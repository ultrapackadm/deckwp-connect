<?php

namespace DeckWP\Connect\Install;

defined('ABSPATH') || exit;

use DeckWP\Connect\Backup\BackupManager;
use DeckWP\Connect\Smoke\PostUpdateChecker;

/**
 * Installs / upgrades plugins on the local WordPress install.
 *
 * v0.4.0 ships plugin upgrades from wp.org only — premium catalog and
 * theme support land in subsequent releases. The Installer is the
 * thin operational layer; the dashboard's UpdateOrchestrator is the
 * business-logic side that decides WHICH plugin gets upgraded, when,
 * and what the user-facing Update row looks like.
 *
 * ## Wire contract
 *
 * Input — array of items the dashboard wants installed/upgraded:
 *
 *     [
 *       ['slug' => 'akismet',          'type' => 'plugin'],
 *       ['slug' => 'wordpress-seo',    'type' => 'plugin', 'backup_required' => true],
 *       ['slug' => 'avada',            'type' => 'plugin', 'download_url' => 'https://...'],
 *     ]
 *
 * Output — one result row per input item, in input order:
 *
 *     [
 *       ['slug' => '...', 'status' => 'installed'|'unchanged'|'failed',
 *        'version_before' => '5.2.0', 'version_after' => '5.4.0',
 *        'error' => null,
 *        // Present iff backup_required was true on the input item
 *        // and snapshot succeeded:
 *        'backup' => ['local_path' => '...', 'checksum' => '...', 'size_bytes' => 1234567]],
 *     ]
 *
 * ## Pre-update backup
 *
 * When the dashboard sends `backup_required: true` on an item, the
 * Installer asks {@see BackupManager::snapshot()} for a zip of the
 * live plugin folder BEFORE running the upgrade. If the snapshot
 * fails (disk full, plugin folder unreadable, ZipArchive missing),
 * the upgrade is skipped — better to fail loudly than to take a
 * destructive action without a rollback target. If the snapshot
 * succeeds, its metadata rides back in the response so the dashboard
 * can flip the corresponding Backup row from `Created` to `Available`.
 *
 * Auto-rollback on failed upgrade lives in Sprint 4 T4 (still TODO).
 * v1 of T3 produces the snapshot + records it; the upgrade itself
 * either succeeds or returns a Failed status, and the operator
 * triggers Restore manually for now.
 *
 * ## How the upgrade actually happens
 *
 * We use core's {@see \Plugin_Upgrader::upgrade()} after refreshing
 * the `update_plugins` site transient via {@see wp_update_plugins()}.
 * That call hits api.wordpress.org/plugins/update-check/1.1/ with the
 * full plugin list, gets back fresh `Plugin_Upgrade::$update`
 * metadata (download URL + new version), and `upgrade()` then
 * downloads the ZIP and replaces the on-disk files.
 *
 * Pinning to a specific version isn't supported — wp.org's update API
 * always serves the latest stable. If a customer reports "I want to
 * downgrade to 5.2.x because 5.4 broke something", that's a future
 * feature: download a specific ZIP and call install_package directly.
 *
 * ## Filesystem requirements
 *
 * `Plugin_Upgrader` needs write access to `wp-content/plugins/`. On
 * most hosting setups WP picks `direct` filesystem method when the
 * web user owns the files (typical on shared hosting + Forge + Herd).
 * If `FS_METHOD` resolves to `ftp` or `ssh2` and credentials aren't
 * stored, the upgrade silently fails with `unable_to_connect_to_filesystem`.
 * We surface that verbatim in the error field so the operator can
 * fix wp-config.
 *
 * ## Safety / idempotency
 *
 * - `upgrade()` returns `false` when there's nothing to upgrade
 *   (already on latest). We map that to status=`unchanged` so the
 *   dashboard doesn't show false-success on a no-op.
 * - Each item runs independently. One failing item doesn't abort
 *   later items — the dashboard sees per-item statuses and can
 *   surface a partial-success summary.
 * - No backup or rollback at this layer — the UpdateOrchestrator
 *   on the dashboard side handles that lifecycle (Sprint 4).
 */
class Installer
{
    /** @var BackupManager */
    private $backupManager;

    /** @var PostUpdateChecker */
    private $smokeChecker;

    public function __construct(BackupManager $backupManager = null, PostUpdateChecker $smokeChecker = null)
    {
        $this->backupManager = $backupManager ?? new BackupManager();
        $this->smokeChecker  = $smokeChecker ?? new PostUpdateChecker();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function install(array $items): array
    {
        $this->loadCoreUpgraderClasses();

        // Bypass Updater\UpdateSuppressor for the lifetime of this
        // request. The suppressor strips managed entries from the
        // site_transient_update_plugins / _themes transients so an
        // operator clicking Update on the WP admin can't bypass our
        // backup + smoke + auto-rollback flow. But this code path
        // IS our own update flow — we need those entries present for
        // wp_update_plugins() and Plugin_Upgrader::upgrade() below to
        // see something to act on. Constants are request-scoped, and
        // the /install-batch HTTP request is never an admin browse,
        // so the side-effect is contained.
        if (! defined('DECKWP_CONNECT_ALLOW_MANAGED_UPDATES')) {
            define('DECKWP_CONNECT_ALLOW_MANAGED_UPDATES', true);
        }

        // Refresh the update_plugins transient so Plugin_Upgrader::upgrade()
        // has fresh download URLs + versions to work with. Without this it
        // would either no-op (transient stale, says nothing to update) or
        // fall through to the cached state from the last admin-side check.
        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }

        $results = [];
        foreach ($items as $item) {
            $results[] = $this->installOne(is_array($item) ? $item : []);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function installOne(array $item): array
    {
        $slug = isset($item['slug']) ? (string) $item['slug'] : '';
        $type = isset($item['type']) ? (string) $item['type'] : 'plugin';
        $downloadUrl = isset($item['download_url']) ? (string) $item['download_url'] : '';
        $backupRequired = ! empty($item['backup_required']);
        // `smoke_check_home` is opt-in: the dashboard sends true only
        // for sites whose home page is known to return 2xx without
        // basic auth or maintenance walls. False positives there are
        // worse than missing the signal.
        $smokeCheckHome = ! empty($item['smoke_check_home']);

        if ($slug === '') {
            return $this->failure('', 'Missing slug.');
        }

        if ($type !== 'plugin') {
            // Theme + core support are explicit non-goals for v0.4.0.
            // The dashboard side filters these out before calling us;
            // this branch is just defense in depth.
            return $this->failure($slug, sprintf('Unsupported type "%s" — handles plugins only.', $type));
        }

        $pluginFile = $this->findPluginFile($slug);
        if ($pluginFile === null) {
            return $this->failure($slug, 'Plugin not installed on this WordPress install.');
        }

        $beforeVersion = $this->readPluginVersion($pluginFile);

        // Snapshot the active state BEFORE the upgrade so the smoke
        // check can compare. Plugin_Upgrader sometimes re-activates
        // the plugin automatically and sometimes doesn't (depends on
        // whether activation throws); a side-by-side compare is the
        // only reliable signal.
        $wasActive = $this->isPluginActive($pluginFile);

        // Pre-update snapshot when the dashboard asked for one. We
        // refuse to proceed with the upgrade if the snapshot fails —
        // an upgrade without a rollback target is precisely what
        // Sprint 4 exists to prevent.
        $backupResult = null;
        if ($backupRequired) {
            $snapshot = $this->backupManager->snapshot($slug);
            if (! ($snapshot['ok'] ?? false)) {
                return $this->failure(
                    $slug,
                    sprintf(
                        'Pre-update backup failed (%s): %s',
                        (string) ($snapshot['error_code'] ?? 'unknown'),
                        (string) ($snapshot['error'] ?? 'no detail')
                    )
                );
            }
            $backupResult = [
                'local_path' => (string) $snapshot['local_path'],
                'checksum'   => (string) $snapshot['checksum'],
                'size_bytes' => (int) $snapshot['size_bytes'],
            ];
        }

        // Two upgrade paths:
        //
        // 1. download_url given → premium plugin from the UltraPack
        //    catalog. The dashboard already resolved the URL with the
        //    team's catalog token; we install directly from that URL
        //    via WP_Upgrader::run() with our package, bypassing the
        //    update_plugins transient.
        //
        // 2. download_url empty → free wp.org plugin. WP's standard
        //    Plugin_Upgrader::upgrade() handles this — it reads the
        //    update_plugins transient (which we refresh in install()
        //    before calling installOne()) and downloads from
        //    api.wordpress.org.
        $upgradeResult = $downloadUrl !== ''
            ? $this->runUpgradeFromUrl($pluginFile, $downloadUrl)
            : $this->runUpgrade($pluginFile);

        // is_wp_error: a real failure inside the upgrader (ZIP download
        // failed, FS_METHOD is ftp without creds, etc).
        if (is_wp_error($upgradeResult)) {
            return $this->withBackup(
                $this->failure($slug, $this->formatWpError($upgradeResult)),
                $backupResult
            );
        }

        // false: nothing to upgrade. Most commonly, the plugin is
        // already at the latest version. Surface as `unchanged` so
        // the dashboard's Update row reflects reality.
        if ($upgradeResult === false) {
            return $this->withBackup([
                'slug' => $slug,
                'status' => 'unchanged',
                'version_before' => $beforeVersion,
                'version_after' => $beforeVersion,
                'error' => null,
            ], $backupResult);
        }

        $afterVersion = $this->readPluginVersion($pluginFile);

        // Post-update smoke check — folder + main file PHP validity
        // + activation state survived. If something's broken AND
        // we have a snapshot, auto-rollback right here so the site
        // is never left in a fatal state. Without a snapshot, we
        // surface the smoke failure and let the operator decide.
        $smokeResult = $this->smokeChecker->verify($slug, $pluginFile, $wasActive, $smokeCheckHome);
        if (! ($smokeResult['ok'] ?? false)) {
            return $this->handleSmokeFailure(
                $slug,
                $beforeVersion,
                $smokeResult,
                $backupResult
            );
        }

        return $this->withBackup([
            'slug' => $slug,
            'status' => 'installed',
            'version_before' => $beforeVersion,
            'version_after' => $afterVersion,
            'error' => null,
        ], $backupResult);
    }

    /**
     * Smoke check failed after a successful upgrade. If we have a
     * snapshot we just took, restore it and return `rolled_back`.
     * If we don't, return `failed` with the smoke reason — the
     * operator has to handle it manually because there's no path
     * back to a known-good state.
     *
     * @param  array<string, mixed>       $smokeResult
     * @param  array<string, mixed>|null  $backupResult
     * @return array<string, mixed>
     */
    private function handleSmokeFailure(string $slug, string $beforeVersion, array $smokeResult, $backupResult): array
    {
        $reason = (string) ($smokeResult['reason'] ?? 'unknown');
        $detail = (string) ($smokeResult['detail'] ?? 'no detail');

        if ($backupResult === null) {
            // No snapshot to restore from. The plugin is left in
            // whatever state the upgrade produced; surface the
            // smoke reason verbatim so the dashboard can flag
            // this as a "manual intervention required" failure.
            return $this->failure(
                $slug,
                sprintf('Post-upgrade smoke check failed (%s) and no pre-update snapshot is available to roll back from: %s', $reason, $detail)
            );
        }

        // Resolve the local_path the snapshot returned (relative to
        // uploads basedir) back to absolute, then ask BackupManager
        // to restore.
        $absoluteZip = $this->absoluteFromUploadsRelative((string) $backupResult['local_path']);
        if ($absoluteZip === null) {
            return $this->withBackup(
                $this->failure($slug, sprintf(
                    'Post-upgrade smoke check failed (%s: %s) and could not resolve the snapshot path for rollback.',
                    $reason,
                    $detail
                )),
                $backupResult
            );
        }

        $restore = $this->backupManager->restore(
            $absoluteZip,
            $slug,
            (string) ($backupResult['checksum'] ?? '')
        );

        if (! ($restore['ok'] ?? false)) {
            // Snapshot existed but restore itself failed. This is the
            // worst-case path — site is now in a broken state and
            // we can't recover automatically. Surface both errors.
            return $this->withBackup(
                $this->failure($slug, sprintf(
                    'Post-upgrade smoke check failed (%s: %s) AND auto-rollback failed (%s: %s). Site needs manual recovery.',
                    $reason,
                    $detail,
                    (string) ($restore['error_code'] ?? 'unknown'),
                    (string) ($restore['error'] ?? 'no detail')
                )),
                $backupResult
            );
        }

        // Restore succeeded. The plugin folder is back to its
        // pre-upgrade state. Report `rolled_back` so the dashboard
        // can settle the Update row to UpdateStatus::RolledBack and
        // mark the Backup as Restored.
        return $this->withBackup([
            'slug' => $slug,
            'status' => 'rolled_back',
            'version_before' => $beforeVersion,
            'version_after' => $beforeVersion,
            'error' => sprintf('Post-upgrade smoke check failed (%s): %s', $reason, $detail),
            'rollback_reason' => $reason,
        ], $backupResult);
    }

    /** Convert a `deckwp-backups/foo.zip` relative path to absolute. */
    private function absoluteFromUploadsRelative(string $relative): ?string
    {
        if (! function_exists('wp_get_upload_dir')) {
            return null;
        }
        $uploads = wp_get_upload_dir();
        $base = rtrim((string) ($uploads['basedir'] ?? ''), '/\\');
        if ($base === '') {
            return null;
        }
        return $base . '/' . ltrim($relative, '/\\');
    }

    /**
     * Wrapper around WP's `is_plugin_active()` that loads the admin
     * helper if it isn't already loaded (REST/cron contexts).
     */
    private function isPluginActive(string $pluginFile): bool
    {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return function_exists('is_plugin_active') && (bool) is_plugin_active($pluginFile);
    }

    /**
     * Fold the backup metadata into the per-item response if a
     * snapshot was taken. Keeps the failure/unchanged/installed
     * branches above tidy and ensures the `backup` key is present
     * iff the snapshot succeeded.
     *
     * @param  array<string, mixed>       $row
     * @param  array<string, mixed>|null  $backup
     * @return array<string, mixed>
     */
    private function withBackup(array $row, $backup): array
    {
        if ($backup !== null) {
            $row['backup'] = $backup;
        }
        return $row;
    }

    /**
     * Map a slug ("akismet") to the plugin file ("akismet/akismet.php")
     * by walking `get_plugins()` and matching on the directory name.
     *
     * Single-file plugins (slug == file == "hello.php") are also
     * supported as a fallback since their directory is empty in the
     * key WP uses.
     */
    private function findPluginFile(string $slug): ?string
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        foreach ($plugins as $file => $_data) {
            $dir = strpos($file, '/') !== false ? explode('/', $file, 2)[0] : $file;
            if ($dir === $slug) {
                return $file;
            }
            // Single-file plugin (no directory): "hello.php" type.
            if ($dir === ($slug . '.php')) {
                return $file;
            }
        }

        return null;
    }

    private function readPluginVersion(string $pluginFile): string
    {
        $path = WP_PLUGIN_DIR . '/' . $pluginFile;
        if (! is_readable($path) || ! function_exists('get_plugin_data')) {
            return '';
        }
        $data = get_plugin_data($path, false, false);

        return isset($data['Version']) ? (string) $data['Version'] : '';
    }

    /**
     * Wraps the actual {@see \Plugin_Upgrader::upgrade()} call so
     * other methods stay focused on shape conversion. Lives in its
     * own method partly for testability — a future test fake can
     * stub this out.
     *
     * @return mixed True on success, false on no-op, WP_Error on failure.
     */
    private function runUpgrade(string $pluginFile)
    {
        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());

        return $upgrader->upgrade($pluginFile);
    }

    /**
     * Upgrade from an explicit package URL (premium catalog flow).
     *
     * Uses {@see \WP_Upgrader::run()} directly with our `package`
     * parameter, bypassing the update_plugins transient that
     * {@see \Plugin_Upgrader::upgrade()} normally consults. Same
     * `clear_destination=true` semantics so the existing plugin
     * directory is replaced atomically — no orphan files from a
     * previous version sitting around after a partial install.
     *
     * @return mixed True on success, false on no-op, WP_Error on failure.
     */
    private function runUpgradeFromUrl(string $pluginFile, string $packageUrl)
    {
        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());

        return $upgrader->run([
            'package'           => $packageUrl,
            'destination'       => WP_PLUGIN_DIR,
            'clear_destination' => true,
            'clear_working'     => true,
            // WP 6.5+ reads this key when clear_destination fails and
            // emits an "Undefined array key" notice if it's missing.
            // We don't have rollback semantics here (Sprint 4 owns
            // that), so leave it false: if cleanup fails the upgrade
            // returns a WP_Error and we surface it verbatim.
            'remove_old_failed' => false,
            'is_multi'          => false,
            'hook_extra'        => [
                'plugin' => $pluginFile,
                'type'   => 'plugin',
                'action' => 'update',
            ],
        ]);
    }

    private function loadCoreUpgraderClasses(): void
    {
        // Plugin_Upgrader and friends only autoload when the admin
        // pageload includes them. REST and cron contexts have to pull
        // them in manually. Order matters: file.php → misc.php →
        // plugin.php → class-wp-upgrader.php (which require_once-pulls
        // its dependencies). The Automatic_Upgrader_Skin lives in its
        // own file in WP 5.2+.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        if (! class_exists('Automatic_Upgrader_Skin')) {
            require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $slug, string $error): array
    {
        return [
            'slug' => $slug,
            'status' => 'failed',
            'version_before' => null,
            'version_after' => null,
            'error' => $error,
        ];
    }

    private function formatWpError(\WP_Error $err): string
    {
        $code = (string) $err->get_error_code();
        $msg = (string) $err->get_error_message();

        return $code !== '' ? sprintf('%s: %s', $code, $msg) : $msg;
    }
}
