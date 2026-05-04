<?php

namespace DeckWP\Connect\Install;

defined('ABSPATH') || exit;

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
 *       ['slug' => 'akismet',       'type' => 'plugin'],
 *       ['slug' => 'wordpress-seo', 'type' => 'plugin'],
 *     ]
 *
 * Output — one result row per input item, in input order:
 *
 *     [
 *       ['slug' => '...', 'status' => 'installed'|'unchanged'|'failed',
 *        'version_before' => '5.2.0', 'version_after' => '5.4.0',
 *        'error' => null],
 *     ]
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
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function install(array $items): array
    {
        $this->loadCoreUpgraderClasses();

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

        if ($slug === '') {
            return $this->failure('', 'Missing slug.');
        }

        if ($type !== 'plugin') {
            // Theme + core support are explicit non-goals for v0.4.0.
            // The dashboard side filters these out before calling us;
            // this branch is just defense in depth.
            return $this->failure($slug, sprintf('Unsupported type "%s" — v0.4.0 handles plugins only.', $type));
        }

        $pluginFile = $this->findPluginFile($slug);
        if ($pluginFile === null) {
            return $this->failure($slug, 'Plugin not installed on this WordPress install.');
        }

        $beforeVersion = $this->readPluginVersion($pluginFile);

        $upgradeResult = $this->runUpgrade($pluginFile);

        // is_wp_error: a real failure inside the upgrader (ZIP download
        // failed, FS_METHOD is ftp without creds, etc).
        if (is_wp_error($upgradeResult)) {
            return $this->failure(
                $slug,
                $this->formatWpError($upgradeResult)
            );
        }

        // false: nothing to upgrade. Most commonly, the plugin is
        // already at the latest version. Surface as `unchanged` so
        // the dashboard's Update row reflects reality.
        if ($upgradeResult === false) {
            return [
                'slug' => $slug,
                'status' => 'unchanged',
                'version_before' => $beforeVersion,
                'version_after' => $beforeVersion,
                'error' => null,
            ];
        }

        $afterVersion = $this->readPluginVersion($pluginFile);

        return [
            'slug' => $slug,
            'status' => 'installed',
            'version_before' => $beforeVersion,
            'version_after' => $afterVersion,
            'error' => null,
        ];
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
