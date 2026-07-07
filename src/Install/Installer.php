<?php

namespace DeckWP\Connect\Install;

defined('ABSPATH') || exit;

use DeckWP\Connect\Backup\BackupManager;
use DeckWP\Connect\License\LicenseDetector;
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

    /** @var LicenseDetector */
    private $licenseDetector;

    public function __construct(
        BackupManager $backupManager = null,
        PostUpdateChecker $smokeChecker = null,
        LicenseDetector $licenseDetector = null
    ) {
        $this->backupManager   = $backupManager ?? new BackupManager();
        $this->smokeChecker    = $smokeChecker ?? new PostUpdateChecker();
        $this->licenseDetector = $licenseDetector ?? new LicenseDetector();
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
        // `active`: opt-in activation post-install (Library "Activate
        // after install" checkbox). Only honored on the fresh-install
        // path — for upgrades, we never change activation state
        // because Plugin_Upgrader::upgrade() preserves whatever was
        // active before, and second-guessing that is a footgun.
        $activateAfterInstall = ! empty($item['active']);
        // `smoke_check_home` is opt-in: the dashboard sends true only
        // for sites whose home page is known to return 2xx without
        // basic auth or maintenance walls. False positives there are
        // worse than missing the signal.
        $smokeCheckHome = ! empty($item['smoke_check_home']);

        if ($slug === '') {
            return $this->failure('', 'Missing slug.');
        }

        if ($type !== 'plugin' && $type !== 'theme') {
            // Core support remains an explicit non-goal — we never
            // touch wp_version from this code path. Plugins and
            // themes are the supported items.
            return $this->failure($slug, sprintf('Unsupported type "%s" — handles plugins and themes only.', $type));
        }

        if ($type === 'theme') {
            // Theme path is separate from plugin — different upgrader,
            // different activation semantics (switch_theme replaces
            // the live theme rather than adding to the active set).
            // backup_required honored since v0.32.0; smoke check
            // shipped in v0.33.0 (folder + style.css + index.php +
            // functions.php token parse + active-state survived,
            // mirroring the plugin smoke shape with theme-specific
            // verification points). Auto-rollback via
            // BackupManager::restoreTheme() is wired through
            // handleSmokeFailure('theme', ...) when a snapshot exists.
            // License protection safeguard (theme upgrade path). Only an
            // EXISTING theme has a license to protect — a fresh install
            // has nothing to overwrite. Mirrors the plugin path below.
            if ($this->themeExists($slug)) {
                $licenseBlock = $this->licenseGuard($slug, 'theme', $item);
                if ($licenseBlock !== null) {
                    return $licenseBlock;
                }
            }

            return $this->installOneTheme($slug, $downloadUrl, $activateAfterInstall, $backupRequired, $smokeCheckHome);
        }

        $pluginFile = $this->findPluginFile($slug);

        // Fresh-install path. The plugin isn't on disk yet, so there's
        // nothing to upgrade — we either grab the package URL the
        // dashboard handed us (premium catalog flow) or look the slug
        // up on wp.org and download from there. Snapshot + smoke check
        // don't apply (no `before` state to capture, and the smoke
        // checker compares activation state before/after, which is
        // meaningless on a brand-new install).
        if ($pluginFile === null) {
            return $this->runFreshInstall($slug, $downloadUrl, $activateAfterInstall);
        }

        // License protection safeguard (upgrade path): refuse to overwrite
        // a plugin that carries an active official license with the catalog
        // build, unless the dashboard explicitly authorized the override.
        // Final line of defense behind the dashboard's UpdateOrchestrator
        // gate — closes the race where a license appears after dispatch.
        $licenseBlock = $this->licenseGuard($slug, 'plugin', $item);
        if ($licenseBlock !== null) {
            return $licenseBlock;
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
                'plugin',
                $slug,
                $beforeVersion,
                $smokeResult,
                $backupResult
            );
        }

        // Dependency detection (Day 7 / Plugin Dependencies sprint).
        // Read `Requires Plugins:` header from the just-upgraded
        // plugin and filter down to the slugs that aren't installed
        // + active locally. The dashboard uses missing_dependencies
        // to auto-install wp.org dependencies after a premium upgrade
        // whose activation would otherwise be blocked by WP core.
        //
        // Empty arrays are still emitted so the dashboard can branch
        // on key presence rather than fall back to "old connector"
        // detection logic.
        $requiresPlugins = $this->readPluginRequiresPlugins($pluginFile);
        $missingDeps = $this->filterMissingDependencies($requiresPlugins);

        return $this->withBackup([
            'slug' => $slug,
            'status' => 'installed',
            'version_before' => $beforeVersion,
            'version_after' => $afterVersion,
            'error' => null,
            'requires_plugins' => $requiresPlugins,
            'missing_dependencies' => $missingDeps,
        ], $backupResult);
    }

    /**
     * Smoke check failed after a successful upgrade. If we have a
     * snapshot we just took, restore it and return `rolled_back`.
     * If we don't, return `failed` with the smoke reason — the
     * operator has to handle it manually because there's no path
     * back to a known-good state.
     *
     * Kind-aware since the rollback target differs:
     *   - 'plugin' → BackupManager::restore() (plugin folder)
     *   - 'theme'  → BackupManager::restoreTheme() (theme folder)
     * The rest of the choreography (snapshot path resolution,
     * error envelope shape) is identical between kinds.
     *
     * @param  string                     $kind         'plugin' | 'theme'
     * @param  array<string, mixed>       $smokeResult
     * @param  array<string, mixed>|null  $backupResult
     * @return array<string, mixed>
     */
    private function handleSmokeFailure(string $kind, string $slug, string $beforeVersion, array $smokeResult, $backupResult): array
    {
        $reason = (string) ($smokeResult['reason'] ?? 'unknown');
        $detail = (string) ($smokeResult['detail'] ?? 'no detail');

        if ($backupResult === null) {
            // No snapshot to restore from. The plugin/theme is left
            // in whatever state the upgrade produced; surface the
            // smoke reason verbatim so the dashboard can flag
            // this as a "manual intervention required" failure.
            return $this->failure(
                $slug,
                sprintf('Post-upgrade smoke check failed (%s) and no pre-update snapshot is available to roll back from: %s', $reason, $detail)
            );
        }

        // Resolve the local_path the snapshot returned (relative to
        // uploads basedir) back to absolute, then ask BackupManager
        // to restore via the kind-appropriate method.
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

        $restore = $kind === 'theme'
            ? $this->backupManager->restoreTheme(
                $absoluteZip,
                $slug,
                (string) ($backupResult['checksum'] ?? '')
            )
            : $this->backupManager->restore(
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
     * Read the `Requires Plugins:` header from a plugin file.
     *
     * WP 6.5+ ships native support for this header — comma-separated
     * list of slugs (e.g. `analytify, woocommerce`) that the plugin
     * needs in order to activate. WP core blocks activation when any
     * required plugin is missing or inactive ("This plugin cannot be
     * activated because required plugins are missing or inactive.").
     *
     * We use `get_file_data()` directly rather than `get_plugin_data()`
     * so this works on WP < 6.5 too — `get_plugin_data()` returns the
     * `RequiresPlugins` key only on 6.5+, but the underlying header
     * file scan works on any version.
     *
     * @return array<int, string> Sanitized slug list, lowercased + trimmed,
     *         duplicates removed. Empty array when the header is missing
     *         or empty.
     */
    private function readPluginRequiresPlugins(string $pluginFile): array
    {
        $path = WP_PLUGIN_DIR . '/' . $pluginFile;
        if (! is_readable($path) || ! function_exists('get_file_data')) {
            return [];
        }

        $data = get_file_data($path, ['RequiresPlugins' => 'Requires Plugins']);
        $raw = isset($data['RequiresPlugins']) ? (string) $data['RequiresPlugins'] : '';
        if ($raw === '') {
            return [];
        }

        // Header format: comma-separated slugs, e.g. "analytify, woocommerce"
        $slugs = array_filter(array_map(
            static function ($s) {
                $s = strtolower(trim((string) $s));
                // Defensive: strip anything that isn't a wp.org-style slug
                // shape. WP itself enforces the same — the header is
                // intended for wp.org slugs only.
                return preg_match('/^[a-z0-9\-]+$/', $s) === 1 ? $s : '';
            },
            explode(',', $raw)
        ));

        return array_values(array_unique($slugs));
    }

    /**
     * Filter a list of required-plugin slugs down to the ones that
     * are NOT currently installed AND active on this site.
     *
     * The dashboard uses the output to decide what to auto-install
     * as dependencies after a premium-plugin install whose
     * activation was blocked by missing requires.
     *
     * Match logic:
     *   - A required slug `X` is "satisfied" when ANY active plugin
     *     in `active_plugins` is at path `X/X.php` OR the plugin
     *     folder is `X/` and contains at least one active *.php
     *     entrypoint. Matching against the folder name is what WP
     *     core itself uses on the same check.
     *
     * @param  array<int, string>  $requiredSlugs  Output of {@see readPluginRequiresPlugins()}
     * @return array<int, string>  Slugs that need installation/activation.
     */
    private function filterMissingDependencies(array $requiredSlugs): array
    {
        if ($requiredSlugs === []) {
            return [];
        }

        if (! function_exists('get_plugins') || ! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (! function_exists('get_plugins') || ! function_exists('is_plugin_active')) {
            // Both should always exist on a real WP install — but if
            // somehow the admin helpers can't be loaded, conservatively
            // declare all required plugins as missing so the dashboard
            // attempts to install them. Worse case: a redundant
            // install attempt; the connector will report "already
            // installed" idempotently.
            return $requiredSlugs;
        }

        $allPlugins = get_plugins(); // [plugin-file => header data]
        $missing = [];

        foreach ($requiredSlugs as $slug) {
            $found = false;
            foreach (array_keys($allPlugins) as $pluginFile) {
                // Plugin folder name is the segment before the first `/`.
                // For single-file plugins (e.g. `hello.php`) the slug is
                // the basename without extension.
                $folder = strpos($pluginFile, '/') !== false
                    ? substr($pluginFile, 0, strpos($pluginFile, '/'))
                    : basename($pluginFile, '.php');

                if (strtolower($folder) === $slug && is_plugin_active($pluginFile)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $missing[] = $slug;
            }
        }

        return $missing;
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
     * Fresh install — the plugin isn't on disk yet, so {@see runUpgrade}
     * has nothing to upgrade. Resolves a download URL (either the one
     * the dashboard sent for premium catalog plugins, or one looked
     * up via {@see plugins_api()} for free wp.org plugins) and asks
     * {@see \Plugin_Upgrader::install()} to download + extract +
     * place under `wp-content/plugins/`.
     *
     * Differs from `runUpgrade` in three ways:
     *   1. Calls `install()` instead of `upgrade()` — install also
     *      handles the case where the destination doesn't exist yet.
     *   2. Doesn't take a snapshot. Nothing to back up; if the
     *      install fails we leave the WP install in its prior empty
     *      state, which is exactly the rollback target.
     *   3. Doesn't run the smoke check. The smoke check compares
     *      pre/post activation state to detect upgrades that broke
     *      the plugin's main file — that comparison is degenerate
     *      for a plugin we're seeing for the first time.
     *
     * The new plugin is left INACTIVE by default. Auto-activation
     * is not the right default — it would activate a plugin the
     * operator has never seen the settings/landing page for, which
     * is how WordPress users get surprised by "this plugin took over
     * my admin" defaults. The dashboard's Library picker offers an
     * opt-in "Activate after install" checkbox; when set, the
     * `active` flag is forwarded here and we activate immediately
     * after the install lands.
     *
     * @return array<string, mixed>
     */
    private function runFreshInstall(string $slug, string $downloadUrl, bool $activateAfterInstall = false): array
    {
        // Resolve the package URL. Premium catalog flow already
        // handed us one; free flow needs a wp.org lookup.
        if ($downloadUrl === '') {
            if (! function_exists('plugins_api')) {
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            }
            if (! function_exists('plugins_api')) {
                return $this->failure($slug, 'Could not load wp-admin/includes/plugin-install.php to resolve the wp.org download URL.');
            }

            $info = plugins_api(
                'plugin_information',
                [
                    'slug' => $slug,
                    // Skip every heavy field — we only need download_link.
                    // wp.org's plugin_information returns ~150KB by default
                    // when sections + reviews are included; trimming here
                    // keeps the install request snappy on slow hosts.
                    'fields' => [
                        'sections' => false,
                        'screenshots' => false,
                        'reviews' => false,
                        'banners' => false,
                        'icons' => false,
                        'contributors' => false,
                    ],
                ]
            );

            if (is_wp_error($info)) {
                return $this->failure(
                    $slug,
                    sprintf('Could not look up "%s" on wp.org: %s', $slug, $info->get_error_message())
                );
            }

            $downloadUrl = isset($info->download_link) ? (string) $info->download_link : '';
            if ($downloadUrl === '') {
                return $this->failure($slug, sprintf('wp.org returned no download_link for slug "%s" — is the plugin still on the directory?', $slug));
            }
        }

        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $result   = $upgrader->install($downloadUrl);

        if (is_wp_error($result)) {
            return $this->failure($slug, $this->formatWpError($result));
        }

        // `install()` returns true on success, null/false on failure
        // without a WP_Error (rare — usually means the unzip phase
        // bailed silently because of FS permissions).
        if ($result !== true) {
            return $this->failure($slug, 'Plugin_Upgrader::install() returned a non-truthy result without a WP_Error — usually a filesystem-permissions issue. Check wp-content/plugins/ is writable by the web user.');
        }

        // Verify the plugin actually landed on disk before reporting
        // success. install() can return true while the unzip target
        // ended up under a different folder name (slug doesn't always
        // match the plugin's main directory — e.g. wp.org's
        // "wp-mail-smtp" zip extracts as "wp-mail-smtp-pro" for the
        // pro release). If we can't find it, surface that loudly so
        // the dashboard reports the install as failed instead of
        // silently dropping it.
        $newPluginFile = $this->findPluginFile($slug);
        if ($newPluginFile === null) {
            return $this->failure(
                $slug,
                sprintf('Install completed but no plugin folder named "%s" was found under wp-content/plugins/. The package may extract to a different folder name.', $slug)
            );
        }

        $afterVersion = $this->readPluginVersion($newPluginFile);

        // Optional activation. Failure here doesn't unwind the install
        // (the file is on disk; a failed activation hook is a fixable
        // condition, not a reason to delete the plugin). We surface
        // the activation error inline so the operator sees what
        // happened, and report `status: installed` with `active:false`
        // so the dashboard knows the bytes landed even if activation
        // didn't take.
        $activated = false;
        $activationError = null;
        if ($activateAfterInstall) {
            if (! function_exists('activate_plugin')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $activationResult = activate_plugin($newPluginFile, '', false, false);
            if (is_wp_error($activationResult)) {
                $activationError = $this->formatWpError($activationResult);
            } else {
                $activated = is_plugin_active($newPluginFile);
                if (! $activated) {
                    $activationError = 'Activation hook ran without error but the plugin remained inactive.';
                }
            }
        }

        // Dependency detection (Plugin Dependencies sprint). Read
        // the `Requires Plugins:` header from the freshly-installed
        // plugin's main file. When the operator asked for activation
        // and activation_error indicates the plugin's deps were
        // missing (common case: WP core's "required plugins are
        // missing or inactive" message), the dashboard can auto-
        // install those wp.org deps and re-attempt activation.
        //
        // Empty arrays still emit so the dashboard can branch on
        // key presence vs fall back to old-connector behavior.
        $requiresPlugins = $this->readPluginRequiresPlugins($newPluginFile);
        $missingDeps = $this->filterMissingDependencies($requiresPlugins);

        $row = [
            'slug'            => $slug,
            'status'          => 'installed',
            'version_before'  => '',
            'version_after'   => $afterVersion,
            'error'           => null,
            'requires_plugins' => $requiresPlugins,
            'missing_dependencies' => $missingDeps,
        ];
        // Only carry the activation fields back when the operator
        // asked for it — keeps the response shape lean for the
        // common "install only" case.
        if ($activateAfterInstall) {
            $row['active'] = $activated;
            $row['activation_error'] = $activationError;
        }

        return $row;
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
     * License protection safeguard. Returns a failure result when the
     * item carries an active official license AND the dashboard did not
     * authorize an override; otherwise null (proceed).
     *
     * Only fires when the dashboard handed us a specific build to write
     * (`download_url` — the premium/catalog overwrite). A wp.org upgrade
     * resolves its own package and can't strip a license, so it's exempt.
     * Uses framework signals only (`$useTransient = false`): the update
     * transient is circular here because the connector just refreshed it
     * with the managed-updates bypass.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function licenseGuard(string $slug, string $type, array $item)
    {
        if (empty($item['download_url'])) {
            return null;
        }
        if (! empty($item['license_override'])) {
            return null;
        }
        if (! $this->licenseDetector->isLicensedActive($slug, $type, false)) {
            return null;
        }

        return $this->failure(
            $slug,
            sprintf(
                'Refusing to overwrite %s "%s": an active official license was detected on this site '
                . '(original_license_protected). Confirm the override in the dashboard to replace it.',
                $type,
                $slug
            )
        );
    }

    /**
     * True when a theme with this stylesheet slug is already installed —
     * i.e. an upgrade (something to protect), not a fresh install.
     */
    private function themeExists(string $slug): bool
    {
        if (! function_exists('wp_get_theme')) {
            return false;
        }

        return wp_get_theme($slug)->exists();
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

    /* =============================================================
     | Theme path — parallel implementation to the plugin path above.
     |
     | Differs in three ways from plugins:
     |   1. Different upgrader (`Theme_Upgrader`) and different
     |      package lookup (`themes_api('theme_information')`).
     |   2. Activation = `switch_theme($stylesheet)`. There's only
     |      ONE active theme at a time — switching deactivates the
     |      previous theme. The operator's "Activate after install"
     |      checkbox carries the same opt-in semantics it has for
     |      plugins, but with a more destructive default: a theme
     |      switch immediately replaces the live frontend's look.
     |   3. No backup / smoke check yet. Theme upgrades + activations
     |      get added to Sprint 4's snapshot lifecycle in a later
     |      release; for v0.16 we install and (optionally) switch,
     |      no rollback target.
     |
     | Same envelope shape as the plugin path so the dashboard's
     | InstallFromLibraryJob can reuse its existing result parser
     | (status / error / active / activation_error).
     ============================================================= */

    /**
     * @param  string  $slug         wp.org theme slug (e.g. "twentytwentyfour")
     * @param  string  $downloadUrl  optional explicit package URL
     * @param  bool    $activateAfterInstall  switch_theme to $slug on success
     * @return array<string, mixed>
     */
    private function installOneTheme(string $slug, string $downloadUrl, bool $activateAfterInstall, bool $backupRequired = false, bool $smokeCheckHome = false): array
    {
        $this->loadThemeUpgraderClasses();

        $stylesheet = $this->findThemeStylesheet($slug);

        // Fresh install path — theme not yet on disk.
        if ($stylesheet === null) {
            return $this->runFreshInstallTheme($slug, $downloadUrl, $activateAfterInstall);
        }

        // Upgrade path — theme exists, run Theme_Upgrader::upgrade().
        $beforeVersion = $this->readThemeVersion($stylesheet);

        // Capture the active-theme state BEFORE the upgrade so the
        // smoke check can compare. Unlike plugins (WP auto-deactivates
        // on activation-time fatal), themes don't auto-switch on fatal
        // — but the signal still catches the rarer case where the
        // upgrade payload internally renamed the slug or replaced
        // files with a different theme's content.
        $wasActive = $this->isThemeActive($slug);

        // Mirror the plugin upgrade snapshot block — if the dashboard
        // asked for a pre-update backup, take it BEFORE the upgrade
        // and refuse the upgrade if the snapshot fails. An upgrade
        // without a rollback target is precisely what Sprint 4 + the
        // KILLER #1 work exists to prevent.
        $backupResult = null;
        if ($backupRequired) {
            $snapshot = $this->backupManager->snapshotTheme($slug);
            if (! ($snapshot['ok'] ?? false)) {
                return $this->failure(
                    $slug,
                    sprintf(
                        'Pre-update theme backup failed (%s): %s',
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

        $upgrader = new \Theme_Upgrader(new \Automatic_Upgrader_Skin());
        $result = $upgrader->upgrade($stylesheet);

        if (is_wp_error($result)) {
            return $this->withBackup(
                $this->failure($slug, $this->formatWpError($result)),
                $backupResult
            );
        }

        // false from Theme_Upgrader::upgrade() = nothing to upgrade
        // (already on latest). Map to `unchanged` so the dashboard
        // doesn't show false-success on a no-op.
        if ($result === false) {
            return $this->withBackup(
                $this->withThemeActivation(
                    $slug,
                    $stylesheet,
                    'unchanged',
                    $beforeVersion,
                    $beforeVersion,
                    null,
                    $activateAfterInstall
                ),
                $backupResult
            );
        }

        $afterVersion = $this->readThemeVersion($stylesheet);

        // Post-update smoke check — folder + style.css + index.php +
        // functions.php PHP validity + active-state survived. If
        // something's broken AND we have a snapshot, auto-rollback
        // right here so the site is never left in a broken state.
        // Without a snapshot, we surface the smoke failure and let
        // the operator decide.
        $smokeResult = $this->smokeChecker->verifyTheme($slug, $wasActive, $smokeCheckHome);
        if (! ($smokeResult['ok'] ?? false)) {
            return $this->handleSmokeFailure(
                'theme',
                $slug,
                $beforeVersion,
                $smokeResult,
                $backupResult
            );
        }

        return $this->withBackup(
            $this->withThemeActivation(
                $slug,
                $stylesheet,
                'installed',
                $beforeVersion,
                $afterVersion,
                null,
                $activateAfterInstall
            ),
            $backupResult
        );
    }

    /**
     * Wrapper around WP's `get_stylesheet()` — returns true iff the
     * given theme slug matches the currently-active stylesheet.
     * Captured BEFORE an upgrade so the smoke check can detect a
     * theme that silently went inactive after.
     */
    private function isThemeActive(string $slug): bool
    {
        if (! function_exists('get_stylesheet')) {
            return false;
        }
        return (string) get_stylesheet() === $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function runFreshInstallTheme(string $slug, string $downloadUrl, bool $activateAfterInstall): array
    {
        if ($downloadUrl === '') {
            if (! function_exists('themes_api')) {
                require_once ABSPATH . 'wp-admin/includes/theme.php';
            }
            if (! function_exists('themes_api')) {
                return $this->failure($slug, 'Could not load wp-admin/includes/theme.php to resolve the wp.org download URL.');
            }

            $info = themes_api(
                'theme_information',
                [
                    'slug' => $slug,
                    // Strip everything but the package URL — theme
                    // information returns a fat payload by default
                    // (screenshots, sections, ratings, etc.) and we
                    // only need download_link.
                    'fields' => [
                        'sections' => false,
                        'screenshot_url' => false,
                        'ratings' => false,
                        'reviews_url' => false,
                        'parent' => false,
                        'template' => false,
                    ],
                ]
            );

            if (is_wp_error($info)) {
                return $this->failure(
                    $slug,
                    sprintf('Could not look up theme "%s" on wp.org: %s', $slug, $info->get_error_message())
                );
            }

            $downloadUrl = isset($info->download_link) ? (string) $info->download_link : '';
            if ($downloadUrl === '') {
                return $this->failure($slug, sprintf('wp.org returned no download_link for theme slug "%s" — is the theme still on the directory?', $slug));
            }
        }

        $upgrader = new \Theme_Upgrader(new \Automatic_Upgrader_Skin());
        $result   = $upgrader->install($downloadUrl);

        if (is_wp_error($result)) {
            return $this->failure($slug, $this->formatWpError($result));
        }

        if ($result !== true) {
            return $this->failure($slug, 'Theme_Upgrader::install() returned a non-truthy result without a WP_Error — usually a filesystem-permissions issue. Check wp-content/themes/ is writable by the web user.');
        }

        $stylesheet = $this->findThemeStylesheet($slug);
        if ($stylesheet === null) {
            return $this->failure(
                $slug,
                sprintf('Install completed but no theme folder named "%s" was found under wp-content/themes/. The package may extract to a different folder name.', $slug)
            );
        }

        return $this->withThemeActivation(
            $slug,
            $stylesheet,
            'installed',
            '',
            $this->readThemeVersion($stylesheet),
            null,
            $activateAfterInstall
        );
    }

    /**
     * Apply (or skip) the `switch_theme()` step and return the
     * shape-consistent result envelope.
     *
     * Theme activation is destructive — it replaces the active
     * theme. That's not a thing we can roll back without remembering
     * the previous active theme; for v0.16 we just do the switch
     * and report the outcome. If the operator wants the old theme
     * back, they activate it manually (or via a future
     * /theme-switch dashboard action).
     *
     * @return array<string, mixed>
     */
    private function withThemeActivation(
        string $slug,
        string $stylesheet,
        string $status,
        string $beforeVersion,
        string $afterVersion,
        ?string $error,
        bool $activateAfterInstall
    ): array {
        $row = [
            'slug'           => $slug,
            'status'         => $status,
            'version_before' => $beforeVersion,
            'version_after'  => $afterVersion,
            'error'          => $error,
        ];

        if (! $activateAfterInstall) {
            return $row;
        }

        // switch_theme() returns void — re-read the active stylesheet
        // afterward to detect cases where a `switch_theme` action
        // hook redirected to a different theme (rare, but happens
        // with multi-site network themes).
        if (! function_exists('switch_theme')) {
            require_once ABSPATH . 'wp-includes/theme.php';
        }

        switch_theme($stylesheet);

        $current = function_exists('get_stylesheet') ? get_stylesheet() : '';
        $activated = ($current === $stylesheet);

        $row['active'] = $activated;
        $row['activation_error'] = $activated
            ? null
            : sprintf('switch_theme(%s) did not take effect — active stylesheet is now %s.', $stylesheet, $current ?: 'unknown');

        return $row;
    }

    /**
     * Locate a theme on disk by wp.org slug. Themes are keyed by
     * stylesheet (directory name); the slug usually matches but not
     * always (e.g. forked themes, child themes with custom directory
     * names).
     *
     * Walks `wp_get_themes()` and matches on stylesheet AND on the
     * WP Theme object's "TextDomain" header as a fallback.
     */
    private function findThemeStylesheet(string $slug): ?string
    {
        if (! function_exists('wp_get_themes')) {
            return null;
        }

        $themes = wp_get_themes();
        foreach ($themes as $stylesheet => $theme) {
            if ((string) $stylesheet === $slug) {
                return (string) $stylesheet;
            }
            $textDomain = method_exists($theme, 'get') ? (string) $theme->get('TextDomain') : '';
            if ($textDomain !== '' && $textDomain === $slug) {
                return (string) $stylesheet;
            }
        }

        return null;
    }

    private function readThemeVersion(string $stylesheet): string
    {
        if (! function_exists('wp_get_theme')) {
            return '';
        }
        $theme = wp_get_theme($stylesheet);
        if (! $theme || ! $theme->exists()) {
            return '';
        }

        return (string) $theme->get('Version');
    }

    /**
     * Theme_Upgrader + Automatic_Upgrader_Skin only autoload during
     * admin pageloads. REST and cron contexts need them pulled in
     * manually. Same shape as {@see self::loadCoreUpgraderClasses()}
     * for plugins — different file (`class-theme-upgrader.php`) is
     * the only addition.
     */
    private function loadThemeUpgraderClasses(): void
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        if (! class_exists('Theme_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
        }
        if (! class_exists('Automatic_Upgrader_Skin')) {
            require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
        }
    }
}
