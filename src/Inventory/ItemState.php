<?php

namespace DeckWP\Connect\Inventory;

defined('ABSPATH') || exit;

/**
 * Reads what WordPress currently believes about one plugin or theme.
 *
 * Every route that changes a site — update, rollback, restore — used to
 * answer the dashboard with what it had *attempted*: `{"ok": true}`, and
 * the panel inferred the rest. That inference is wrong the moment an
 * operation half-succeeds, and it produced the two divergences an
 * end-to-end pass caught: a plugin shown active after a rollback had
 * switched it off, and a plugin shown at 6.1.6 after a restore had put
 * 6.0 back on disk. Only a "Refresh" reconciled them, which means the
 * panel was displaying an assumption for as long as nobody pressed it.
 *
 * So the contract changed: a mutating route reports the post-state it
 * read back from WordPress, and the dashboard settles on that. This
 * class is the single reader behind those reports, so the Installer and
 * the restore route can't drift into two different ideas of what
 * "active" means.
 *
 * Deliberately narrow — one item at a time, no update-check, no wp.org
 * traffic. {@see PluginInventory} remains the full-site collector for
 * the heartbeat; this is the cheap per-item read that runs immediately
 * after a write.
 */
class ItemState
{
    /**
     * Post-operation state of a single plugin, by slug.
     *
     * @return array{installed: bool, version: string, active: bool, plugin_file: string|null}
     */
    public function plugin(string $slug): array
    {
        $pluginFile = $this->findPluginFile($slug);

        if ($pluginFile === null) {
            return [
                'installed' => false,
                'version' => '',
                'active' => false,
                'plugin_file' => null,
            ];
        }

        return [
            'installed' => true,
            'version' => $this->readPluginVersion($pluginFile),
            'active' => $this->isPluginActive($pluginFile),
            'plugin_file' => $pluginFile,
        ];
    }

    /**
     * Post-operation state of a single theme, by stylesheet slug.
     *
     * `active` means "is the live stylesheet", which for themes is
     * exclusive — unlike plugins, exactly one can hold it.
     *
     * @return array{installed: bool, version: string, active: bool}
     */
    public function theme(string $slug): array
    {
        if (! function_exists('wp_get_theme')) {
            return ['installed' => false, 'version' => '', 'active' => false];
        }

        $theme = wp_get_theme($slug);
        if (! $theme->exists()) {
            return ['installed' => false, 'version' => '', 'active' => false];
        }

        return [
            'installed' => true,
            'version' => (string) $theme->get('Version'),
            'active' => $this->isThemeActive($slug),
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
    public function findPluginFile(string $slug): ?string
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (! function_exists('get_plugins')) {
            return null;
        }

        foreach (array_keys((array) get_plugins()) as $file) {
            $file = (string) $file;
            $dir = strpos($file, '/') !== false ? explode('/', $file, 2)[0] : $file;
            if ($dir === $slug || $dir === ($slug . '.php')) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Wrapper around WP's `is_plugin_active()` that loads the admin
     * helper if it isn't already loaded (REST/cron contexts).
     */
    public function isPluginActive(string $pluginFile): bool
    {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return function_exists('is_plugin_active') && (bool) is_plugin_active($pluginFile);
    }

    /**
     * Wrapper around WP's `is_plugin_active_for_network()`. Always
     * false on single-site, where the function exists but the network
     * option never does.
     */
    public function isPluginActiveForNetwork(string $pluginFile): bool
    {
        if (! function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return function_exists('is_plugin_active_for_network')
            && (bool) is_plugin_active_for_network($pluginFile);
    }

    public function readPluginVersion(string $pluginFile): string
    {
        if (! defined('WP_PLUGIN_DIR') || ! function_exists('get_plugin_data')) {
            return '';
        }

        $path = WP_PLUGIN_DIR . '/' . $pluginFile;
        if (! is_readable($path)) {
            return '';
        }

        // false, false: skip markup translation and text-domain loading.
        // We want the raw header, and loading a text domain mid-REST
        // request to read a version number is a side effect nobody asked
        // for.
        $data = get_plugin_data($path, false, false);

        return isset($data['Version']) ? (string) $data['Version'] : '';
    }

    /** True iff the given theme slug is the live stylesheet. */
    public function isThemeActive(string $slug): bool
    {
        return function_exists('get_stylesheet') && (string) get_stylesheet() === $slug;
    }
}
