<?php

namespace DeckWP\Connect\Updater;

defined('ABSPATH') || exit;

/**
 * Hides "Update available" notices in the WP admin for plugins and
 * themes the dashboard has flagged as DeckWP-managed.
 *
 * ## Why
 *
 * DeckWP's pre-update backup + smoke + auto-rollback flow only fires
 * when the update goes through the connector's `/install-batch` route.
 * If the operator clicks Update directly on the WP admin's plugins
 * screen, WP runs `Plugin_Upgrader` synchronously without our
 * orchestration — no snapshot, no smoke, no rollback target. That's
 * the exact failure mode this feature exists to prevent.
 *
 * Suppressing the update offer at the source (filtering the
 * `site_transient_update_plugins` / `_themes` transients) makes the
 * "Update available" link, the row-actions Update link, the bulk
 * action checkboxes, *and* the auto-update toggle all disappear for
 * managed slugs. The operator goes through the dashboard for these.
 *
 * ## Wire shape
 *
 * Managed entries live in the `deckwp_managed_slugs` site option,
 * shape:
 *
 *     [
 *       'plugins' => [
 *         'formidable-pro/formidable-pro.php',
 *         'wp-rocket',
 *       ],
 *       'themes' => [ 'avada' ],
 *     ]
 *
 * Plugin entries can be EITHER the WP plugin_path (`slug/main.php`)
 * OR just the folder slug (`slug`) — the suppressor matches both
 * shapes so the dashboard isn't forced to know the main file name.
 * Themes are always folder slugs (themes don't have a "main file"
 * concept WP exposes here).
 *
 * Storage uses `get_site_option` / `update_site_option` so a
 * multisite network has a single shared list — same posture as the
 * fatal-handler log (Slice 3).
 *
 * ## Bypass for the dashboard's own update calls
 *
 * The dashboard's `/install-batch` flow runs `wp_update_plugins()`
 * to refresh the transient before calling `Plugin_Upgrader::upgrade`.
 * That refresh fires this filter — and would strip the very entry
 * we're trying to upgrade. The bypass: define
 * `DECKWP_CONNECT_ALLOW_MANAGED_UPDATES` to `true` in the calling
 * code path before the upgrader runs. The filter checks the
 * constant and returns the transient untouched.
 *
 * `Install\Installer` already gates around this — see its docblock.
 */
class UpdateSuppressor
{
    /** Site option holding the managed slugs lists. */
    public const OPTION_KEY = 'deckwp_managed_slugs';

    /**
     * Register the filters. Idempotent: safe to call from `plugins_loaded`.
     */
    public function register(): void
    {
        // Priority 9999 so most plugin-side filters run before us. We
        // remove entries; running later means everyone else's data is
        // already in the transient when we look at it.
        add_filter('site_transient_update_plugins', [$this, 'filterPluginUpdates'], 9999);
        add_filter('site_transient_update_themes',  [$this, 'filterThemeUpdates'],  9999);
    }

    /**
     * Strip managed plugin entries from the update_plugins transient.
     *
     * The transient is a stdClass with these relevant fields:
     *   - response:    keyed by plugin_path; entries WP shows "Update available" for
     *   - no_update:   keyed by plugin_path; entries up to date
     *   - checked:     keyed by plugin_path; everything WP last polled
     *
     * We remove from `response` so the offer disappears from the UI.
     * We deliberately leave `no_update` and `checked` alone — they're
     * referenced by other update flows (heartbeat, auto-update
     * scheduling) and stripping them would have surprising knock-ons.
     *
     * @param  mixed $transient stdClass | false
     * @return mixed
     */
    public function filterPluginUpdates($transient)
    {
        if ($this->isBypassActive() || ! is_object($transient) || empty($transient->response)) {
            return $transient;
        }

        $managed = $this->getManagedPlugins();
        if (empty($managed)) {
            return $transient;
        }

        foreach (array_keys($transient->response) as $pluginPath) {
            if ($this->matchesManagedPlugin((string) $pluginPath, $managed)) {
                unset($transient->response[$pluginPath]);
            }
        }

        return $transient;
    }

    /**
     * Strip managed theme entries from the update_themes transient.
     *
     * Themes use folder-slug keys (`'avada'` not `'avada/style.css'`).
     * Match is exact-string against the managed themes list.
     *
     * @param  mixed $transient
     * @return mixed
     */
    public function filterThemeUpdates($transient)
    {
        if ($this->isBypassActive() || ! is_object($transient) || empty($transient->response)) {
            return $transient;
        }

        $managed = $this->getManagedThemes();
        if (empty($managed)) {
            return $transient;
        }

        foreach (array_keys($transient->response) as $themeSlug) {
            if (in_array((string) $themeSlug, $managed, true)) {
                unset($transient->response[$themeSlug]);
            }
        }

        return $transient;
    }

    /**
     * Read the plugins half of the option. Returns a sanitised
     * array of strings; missing / malformed option returns [].
     *
     * @return string[]
     */
    public function getManagedPlugins(): array
    {
        $opt = $this->getOption();
        if (! isset($opt['plugins']) || ! is_array($opt['plugins'])) {
            return [];
        }
        return $this->sanitizeStringList($opt['plugins']);
    }

    /**
     * Read the themes half of the option.
     *
     * @return string[]
     */
    public function getManagedThemes(): array
    {
        $opt = $this->getOption();
        if (! isset($opt['themes']) || ! is_array($opt['themes'])) {
            return [];
        }
        return $this->sanitizeStringList($opt['themes']);
    }

    /**
     * Match a plugin path (`'slug/main.php'`) against the managed
     * list. Managed entries can be EITHER the full plugin_path OR
     * just the folder slug — both shapes match.
     *
     * Single-file plugins (Hello-Dolly pattern: `'hello.php'`,
     * no slash) only match exact filename — there's no folder
     * to derive a slug from.
     *
     * @param string[] $managed
     */
    private function matchesManagedPlugin(string $pluginPath, array $managed): bool
    {
        if (in_array($pluginPath, $managed, true)) {
            return true;
        }
        if (strpos($pluginPath, '/') !== false) {
            $slug = strstr($pluginPath, '/', true);
            if ($slug !== false && in_array($slug, $managed, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Bypass for the dashboard's own update flow. See class docblock.
     */
    private function isBypassActive(): bool
    {
        return defined('DECKWP_CONNECT_ALLOW_MANAGED_UPDATES')
            && DECKWP_CONNECT_ALLOW_MANAGED_UPDATES === true;
    }

    /**
     * @return array{plugins?: array, themes?: array}
     */
    private function getOption(): array
    {
        return (array) get_site_option(self::OPTION_KEY, []);
    }

    /**
     * @param  mixed[] $list
     * @return string[]
     */
    private function sanitizeStringList(array $list): array
    {
        $out = [];
        foreach ($list as $entry) {
            if (! is_string($entry) || $entry === '') {
                continue;
            }
            $out[] = $entry;
        }
        return $out;
    }
}
