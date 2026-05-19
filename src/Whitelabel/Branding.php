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
 * Plus agency-level toggles (v0.21.0+) — boolean switches that
 * apply globally, not per-plugin. Currently shipped:
 *
 *   - `hide_updates` (v0.21.0) — strip the connector's OWN row
 *     from the update_plugins transient so customer wp-admin
 *     doesn't surface an "Update available" notice for the
 *     rebranded plugin. Distinct from {@see UpdateSuppressor}
 *     which gates the dashboard's managed-slugs list.
 *
 *   - `suppress_activate` (v0.22.0) — hide the inline
 *     "Plugin activated." / "Plugin deactivated." notice WP
 *     renders on `plugins.php` after a state-change action. The
 *     notice is rendered inline by core (not via `admin_notices`)
 *     so we can't unhook it — we inject scoped CSS on the
 *     plugins.php screen to hide it. The plugin's actual
 *     activation isn't affected, only the leak-y confirmation
 *     banner.
 *
 *   - `help_links` (v0.22.0) — strip URL-bearing items from the
 *     plugin row meta (View details, Visit plugin site, Author
 *     site) across ALL plugin rows. If `help_links_url` is set,
 *     append a single "Support" anchor pointing at it. Version +
 *     "By Author" text items are preserved (no URL leak).
 *
 * Other toggles (`custom_login`, `adminbar_logo`) are reserved in
 * the config shape but not yet wired — each comes in its own
 * follow-up commit.
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
 * - Suppress the plugin's own "View details" thickbox in a
 *   targeted way (we strip all URL-bearing meta when
 *   `help_links` is on, but we don't selectively hide individual
 *   meta items by their text — too brittle across locales).
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

        // Agency-level whitelabel toggles (v0.21.0+). Each toggle is
        // wired here against the right WP hook. The actual on/off
        // gate happens inside the handler via `isToggleOn()` so the
        // hooks stay registered regardless of config — keeps the
        // hook lifecycle simple and lets the dashboard toggle live.
        add_filter('site_transient_update_plugins',   [$this, 'filterOwnUpdateNotice'], 99999);

        // `suppress_activate` — fires only on the plugins.php screen
        // (the only place WP renders the inline activate/deactivate
        // notice). The handler self-gates on the toggle so the
        // CSS is silent when the operator hasn't opted in.
        add_action('admin_print_styles-plugins.php', [$this, 'maybePrintSuppressActivateCss'], 100);
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
     * `help_links` (v0.22.0) — strip URL-bearing meta items from the
     * plugin row across ALL plugin rows on the plugins admin screen.
     * When `help_links_url` is set, append a single "Support" anchor
     * pointing at the operator-configured URL so the customer has
     * exactly one help destination.
     *
     * Why "all rows" (not just the connector's): the toggle's UX
     * promise on the dashboard is agency-wide rebrand — leaving
     * other plugins' "Visit plugin site" / "View details" links
     * intact would defeat the point (customer would still reach
     * upstream pages for every plugin BUT the connector). Mirrors
     * Manage GPL / ManageWP behavior of stripping these globally.
     *
     * What's stripped: any meta string containing an `<a` tag is
     * dropped. WP's default meta items include:
     *
     *   - "Version X.Y.Z"            (kept — pure text)
     *   - "By <a>Author</a>"         (stripped — has anchor)
     *   - "<a>View details</a>"       (stripped)
     *   - "<a>Visit plugin site</a>"  (stripped)
     *
     * The "By Author" line is collateral damage — its anchor wraps
     * the author name so we can't keep one without the other.
     * Acceptable in v1: the operator's intent with this toggle is
     * to scrub upstream identity, and the author byline is part of
     * that identity.
     *
     * Pass-through when toggle is off — registered hook stays
     * cheap (one option read on first invocation per request,
     * cached after).
     *
     * @param  string[] $meta
     * @param  string   $pluginPath
     * @return string[]
     */
    public function filterPluginRowMeta($meta, $pluginPath)
    {
        if (! is_array($meta) || ! $this->isToggleOn('help_links')) {
            return $meta;
        }

        $stripped = [];
        foreach ($meta as $item) {
            if (is_string($item) && preg_match('/<a\s/i', $item)) {
                continue;
            }
            $stripped[] = $item;
        }

        $url = $this->getToggleString('help_links_url');
        if ($url !== '') {
            // esc_url + esc_html — defensive escaping even though
            // the toggle string came in through WhitelabelRoute's
            // sanitization. The plugins list table renders meta
            // via `implode(' | ', $meta)` without further escaping.
            $stripped[] = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url($url),
                esc_html__('Support', 'deckwp-connect')
            );
        }

        return $stripped;
    }

    /**
     * `suppress_activate` (v0.22.0) — inject scoped CSS on the
     * plugins.php screen to hide the inline "Plugin activated."
     * (and sibling) notice that WP renders directly from
     * `wp-admin/plugins.php` based on `$_GET['activate']` /
     * `$_GET['deactivate']` query strings.
     *
     * Why CSS injection instead of unhooking: the notice is rendered
     * by an `echo` in core's `plugins.php` template, not via the
     * `admin_notices` action — there's no hook to unhook. Mutating
     * `$_GET` in admin_init would technically work but has unknown
     * side effects on other code that reads those query args (plugins
     * list table itself reads `activate-multi` to highlight the
     * activated rows). CSS hiding is the least invasive option.
     *
     * Scope: targets `.wrap > #message` (the specific div WP outputs
     * for this notice) and `.wrap > .notice.updated.notice-success`
     * (the modern variant on recent WP versions). Other admin notices
     * on the same page (errors, plugin-update-banners) are untouched.
     *
     * Only emits the `<style>` tag when the toggle is ON AND there's
     * a state-change query arg present — the CSS is otherwise dead
     * weight on every plugins.php load.
     */
    public function maybePrintSuppressActivateCss(): void
    {
        if (! $this->isToggleOn('suppress_activate')) {
            return;
        }
        // Only inject when a state-change notice would actually
        // render — checking the query args keeps the CSS off the
        // page during normal browsing of the plugins list.
        $relevantArgs = ['activate', 'activate-multi', 'deactivate', 'deactivate-multi', 'deleted'];
        $present = false;
        foreach ($relevantArgs as $arg) {
            if (isset($_GET[$arg])) {
                $present = true;
                break;
            }
        }
        if (! $present) {
            return;
        }

        // Hides:
        //   - <div id="message" class="updated ...">  (classic markup)
        //   - <div class="notice updated notice-success">  (modern)
        // Scoped to `.wrap` to avoid hiding notices that legitimately
        // appear elsewhere on the page (modal headers, etc.).
        echo '<style id="deckwp-suppress-activate">'
            . '.wrap > #message,'
            . '.wrap > .notice.updated.notice-success,'
            . '.wrap > .notice-success.is-dismissible{display:none!important;}'
            . '</style>';
    }

    /**
     * Strip the connector's own row from the `update_plugins` site
     * transient when the operator has flipped `hide_updates` ON in
     * the dashboard. The customer's wp-admin won't render an
     * "Update available" banner for the rebranded plugin (which
     * would otherwise leak DeckWP's identity through the upgrader
     * dialog + tempt the customer into self-upgrading outside the
     * orchestrated flow).
     *
     * Different gate than {@see \DeckWP\Connect\Updater\UpdateSuppressor}
     * which strips DASHBOARD-managed slugs. This one targets the
     * connector's OWN row only, gated on the whitelabel toggle.
     *
     * Bypasses when `DECKWP_CONNECT_ALLOW_MANAGED_UPDATES` is true —
     * same posture as the suppressor so the dashboard's own
     * /install-batch refresh isn't accidentally blanked.
     *
     * @param  mixed $transient
     * @return mixed
     */
    public function filterOwnUpdateNotice($transient)
    {
        if (defined('DECKWP_CONNECT_ALLOW_MANAGED_UPDATES') && DECKWP_CONNECT_ALLOW_MANAGED_UPDATES) {
            return $transient;
        }
        if (! $this->isToggleOn('hide_updates')) {
            return $transient;
        }
        if (! is_object($transient) || ! isset($transient->response) || ! is_array($transient->response)) {
            return $transient;
        }

        // The connector's own plugin path is the only entry we strip
        // here. Other entries are left alone — that's the
        // UpdateSuppressor's job.
        $ownPath = defined('DECKWP_CONNECT_BASENAME') ? DECKWP_CONNECT_BASENAME : '';
        if ($ownPath !== '' && isset($transient->response[$ownPath])) {
            unset($transient->response[$ownPath]);
        }

        return $transient;
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

    /**
     * Read a single boolean toggle from the whitelabel config option.
     * Missing or non-boolean values default to `false` — safer than
     * inheriting whatever truthy thing a future config drift produces.
     *
     * Cached per-request via a static so multiple toggle checks on
     * the same admin page don't hammer the option layer (which
     * triggers `pre_option_*` filters + `wp_load_alloptions`
     * cascades).
     */
    private function isToggleOn(string $key): bool
    {
        return (bool) ($this->loadToggles()[$key] ?? false);
    }

    /**
     * Read a string-valued toggle (e.g. `help_links_url`,
     * `custom_login_logo_url`). Missing or non-string values
     * resolve to `''` — consumers treat empty as "no override".
     *
     * Shares the same per-request cache as {@see self::isToggleOn()}
     * via {@see self::loadToggles()}.
     */
    private function getToggleString(string $key): string
    {
        $val = $this->loadToggles()[$key] ?? '';
        return is_string($val) ? $val : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadToggles(): array
    {
        static $toggles = null;
        if ($toggles === null) {
            $config = (array) get_site_option(self::OPTION_KEY, []);
            $toggles = (isset($config['toggles']) && is_array($config['toggles']))
                ? $config['toggles']
                : [];
        }
        return $toggles;
    }
}
