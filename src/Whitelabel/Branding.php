<?php

namespace DeckWP\Connect\Whitelabel;

defined('ABSPATH') || exit;

/**
 * Rewrites plugin metadata in the WP admin so the customer sees the
 * operator's brand instead of the upstream package's.
 *
 * ## Why
 *
 * Whitelabel branding is in every Manage GPL plan, in ManageWP as a
 * paid add-on, and in Zeebrar's "coming soon" list. For DeckWP it
 * sits in FREE / PRO / AGENCY all three — without it, the FREE tier
 * isn't vendable. The dashboard collects the operator's branding
 * config (plugin renames, hide-from-list, custom URLs) and pushes
 * it to the connector via {@see WhitelabelRoute}.
 *
 * ## What this class does
 *
 * Hooks into `all_plugins` (the array WP populates the admin
 * Plugins page from) at priority 9999 — after every other filter,
 * so the rewrites stick on the rendered output. For each entry in
 * the dashboard-pushed config we:
 *
 *   - Override `Name` / `Title`
 *   - Override `Description`
 *   - Override `Author` / `AuthorName` / `AuthorURI`
 *   - Override `PluginURI` (the "Visit plugin site" link target)
 *   - Drop the entry entirely if `hide: true` — the row simply
 *     doesn't render. The plugin is still loaded by WP; only the
 *     UI presence is suppressed.
 *
 * ## What this class does NOT do
 *
 * - Theme rebrand. Reserved for v2 (the option storage already
 *   carries a `themes` key for forward-compat). Themes have a
 *   different filter surface (`wp_get_theme`, `themes_api_result`)
 *   and the operator demand for theme rebrand is lower than plugin
 *   rebrand on competitive parity grounds.
 * - Hide from the network plugins page when the connector is
 *   network-active without per-blog overrides. Storage is one
 *   network-wide config; per-blog whitelabel is a wire-shape
 *   extension if ever needed (out of scope for MVP).
 * - Suppress the plugin's own "View details" thickbox. The
 *   `plugin_row_meta` filter is wired but the v1 implementation
 *   passes through — operator demand for that level of control
 *   is rare; revisit when a customer asks.
 *
 * ## Storage
 *
 * `deckwp_whitelabel_config` site option (network-wide on multisite,
 * equivalent to wp_options on single-site). Empty / missing option
 * makes every filter a no-op — the class is safe to register before
 * any config has arrived from the dashboard.
 *
 * ## Honesty about limits of metadata rewriting
 *
 * Rewriting `Name` here is cosmetic — WP plugin update checks still
 * use the original slug to talk to wp.org or the UltraPack catalog.
 * A customer who Googles the rebranded name will land on DeckWP's
 * marketing pages (good); a customer who reads the source files in
 * `wp-content/plugins/` will see the original branding (acceptable
 * — the goal is a polished admin UI, not source-level deception).
 */
class Branding
{
    /** Site option holding the whitelabel config pushed from the dashboard. */
    public const OPTION_KEY = 'deckwp_whitelabel_config';

    /**
     * Register the filters. Idempotent: safe to call from `plugins_loaded`.
     */
    public function register(): void
    {
        add_filter('all_plugins',                     [$this, 'filterAllPlugins'],   9999);
        add_filter('plugin_row_meta',                 [$this, 'filterPluginRowMeta'], 9999, 2);
        add_filter('network_admin_plugin_row_meta',   [$this, 'filterPluginRowMeta'], 9999, 2);
    }

    /**
     * Rewrite plugin metadata + drop hidden entries.
     *
     * @param  array<string, array<string, string>> $plugins
     * @return array<string, array<string, string>>
     */
    public function filterAllPlugins($plugins)
    {
        if (! is_array($plugins)) {
            return $plugins;
        }

        $overrides = $this->getPluginOverrides();
        if (empty($overrides)) {
            return $plugins;
        }

        foreach ($plugins as $path => $data) {
            if (! isset($overrides[$path]) || ! is_array($overrides[$path])) {
                continue;
            }
            $o = $overrides[$path];

            if (! empty($o['hide'])) {
                unset($plugins[$path]);
                continue;
            }

            if (! is_array($plugins[$path])) {
                // Defensive — some pathological filter upstream might
                // have replaced the entry with a non-array. Skip.
                continue;
            }

            if (isset($o['name']) && is_string($o['name'])) {
                $plugins[$path]['Name']  = $o['name'];
                $plugins[$path]['Title'] = $o['name'];
            }
            if (isset($o['description']) && is_string($o['description'])) {
                $plugins[$path]['Description'] = $o['description'];
            }
            if (isset($o['author']) && is_string($o['author'])) {
                $plugins[$path]['Author']     = $o['author'];
                $plugins[$path]['AuthorName'] = $o['author'];
            }
            if (isset($o['author_uri']) && is_string($o['author_uri'])) {
                $plugins[$path]['AuthorURI'] = $o['author_uri'];
            }
            if (isset($o['plugin_uri']) && is_string($o['plugin_uri'])) {
                $plugins[$path]['PluginURI'] = $o['plugin_uri'];
            }
        }

        return $plugins;
    }

    /**
     * Pass-through in v1. Wired for symmetry with `filterAllPlugins`
     * so a future override of `View details` / per-row meta links
     * has a hook point already in place — bumping this method is
     * a no-coordination change.
     *
     * @param  string[] $meta
     * @param  string   $pluginPath
     * @return string[]
     */
    public function filterPluginRowMeta($meta, $pluginPath)
    {
        return $meta;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getPluginOverrides(): array
    {
        $config = (array) get_site_option(self::OPTION_KEY, []);
        if (! isset($config['plugins']) || ! is_array($config['plugins'])) {
            return [];
        }
        return $config['plugins'];
    }
}
