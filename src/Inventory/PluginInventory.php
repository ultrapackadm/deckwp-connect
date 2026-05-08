<?php

namespace DeckWP\Connect\Inventory;

defined('ABSPATH') || exit;

/**
 * Collects the local WordPress plugin inventory for the heartbeat payload.
 *
 * Each row mirrors the shape the dashboard's PluginInstallation model
 * expects to upsert against:
 *
 *     [
 *         'slug'             => 'akismet',           // dirname or single-file name
 *         'plugin_file'      => 'akismet/akismet.php',
 *         'name'             => 'Akismet Anti-spam: Spam Protection',
 *         'version'          => '5.3.4',
 *         'active'           => true,
 *         'update_available' => false,
 *         'new_version'      => null,                // populated when update available
 *     ]
 *
 * `slug` is the canonical key the dashboard joins on (matches
 * `plugins.slug`). For multi-file plugins WP packages it as
 * `<slug>/<slug>.php` or `<slug>/<main>.php`; we use `dirname` to extract
 * the directory name. Single-file plugins (rare — `hello.php` is the
 * canonical example) get the basename without `.php`.
 *
 * Update detection reads WP's `update_plugins` site transient — the same
 * source the WP admin "Updates" screen uses. Doesn't trigger a fresh
 * check (that's `wp_update_plugins()`); reads whatever is cached.
 */
class PluginInventory
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function collect(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all = (array) get_plugins();
        $active = (array) get_option('active_plugins', []);
        $updates = $this->updatePayload();

        $rows = [];
        foreach ($all as $file => $data) {
            $rows[] = [
                'slug'             => $this->slugFor((string) $file),
                'plugin_file'      => (string) $file,
                'name'             => isset($data['Name']) ? (string) $data['Name'] : '',
                'version'          => isset($data['Version']) ? (string) $data['Version'] : '',
                'active'           => in_array($file, $active, true),
                'update_available' => isset($updates[$file]),
                'new_version'      => isset($updates[$file]['new_version'])
                    ? (string) $updates[$file]['new_version']
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * Convert WP's plugin-file identifier into a stable slug. WP's id is
     * relative to wp-content/plugins/, e.g.:
     *
     *   akismet/akismet.php          → "akismet"
     *   classic-editor/classic-editor.php → "classic-editor"
     *   hello.php                    → "hello"
     */
    private function slugFor(string $file): string
    {
        $dir = dirname($file);
        if ($dir === '.' || $dir === '') {
            return basename($file, '.php');
        }

        return $dir;
    }

    /**
     * Pull the plugin update payload out of the WP site transient.
     * Returns a map keyed by the same plugin-file identifier WP uses for
     * `get_plugins()`.
     *
     * Behavior:
     *
     *   1. If the transient is missing/stale (older than 6 hours OR
     *      response not yet populated), force a wp.org poll via
     *      `wp_update_plugins()`. That's the same call WP cron runs
     *      every 12 hours, but on-demand. Returns nothing — populates
     *      the transient as a side effect.
     *   2. If `Updater\UpdateSuppressor` is active on this install, its
     *      `site_transient_update_plugins` filter strips managed slugs
     *      from the response before we read it. That's correct for the
     *      WP admin Updates page (we don't want operators
     *      double-pressing Update there) but wrong for inventory
     *      reporting — the dashboard NEEDS to know which plugins have
     *      updates so it can surface the per-row "Update" button.
     *      The bypass constant makes the filter pass-through for the
     *      duration of this read.
     *
     * Both fixes are required to surface "12 updates available" in
     * the dashboard for sites with an under-firing wp-cron and/or any
     * managed_slugs configured.
     *
     * @return array<string, array<string, mixed>>
     */
    private function updatePayload(): array
    {
        // 1. Set the UpdateSuppressor bypass FIRST. wp_update_plugins()
        // internally calls get_site_transient(), which fires the
        // `site_transient_update_plugins` filter — without the bypass
        // set up front, that internal read would see the filtered
        // (stripped) response, and any managed slugs would never make
        // it into the freshness comparison.
        if (! defined('DECKWP_CONNECT_ALLOW_MANAGED_UPDATES')) {
            define('DECKWP_CONNECT_ALLOW_MANAGED_UPDATES', true);
        }

        // 2. Ensure the transient is fresh. wp_update_plugins() is a
        // no-op when cached data is < 1h old; otherwise it polls
        // wp.org and re-populates the transient. Making the call
        // here costs ~200ms-1s once per inventory pull, but ensures
        // operators don't see stale "0 outdated" when wp-cron isn't
        // firing reliably (DISABLE_WP_CRON=true without an external
        // cron is the common cause).
        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }

        // 3. Read the transient. UpdateSuppressor's hook still runs
        // on the read but checks the bypass constant we set above
        // and returns the raw response without stripping managed
        // slugs.
        $transient = get_site_transient('update_plugins');

        if (! is_object($transient) || ! isset($transient->response) || ! is_array($transient->response)) {
            return [];
        }

        $out = [];
        foreach ($transient->response as $file => $data) {
            $out[(string) $file] = is_object($data) ? (array) $data : (array) $data;
        }

        return $out;
    }
}
