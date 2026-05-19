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
 *   - `custom_login` (v0.23.0) — replace the wp-login.php logo
 *     image + accent color via inline CSS on the
 *     `login_enqueue_scripts` action. Also retargets the logo's
 *     anchor URL + title attribute so the hover/click no longer
 *     advertises WordPress. Color falls back gracefully when no
 *     valid hex is provided; logo URL falls back to the default
 *     WP logo when empty (only the URL retarget applies, keeping
 *     this fully opt-in granular).
 *
 *   - `adminbar_logo` (v0.23.0) — unhook the entire `wp-logo`
 *     adminbar node (including its About / Documentation /
 *     Support sub-menu) so customer-facing adminbars don't leak
 *     WP identity. If `adminbar_logo_url` is set, drop in a
 *     replacement node `deckwp-whitelabel-logo` with the custom
 *     image as its icon (rendered via CSS background-image).
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

        // `custom_login` — three hooks coordinate the rebrand on the
        // wp-login.php screen:
        //   - login_enqueue_scripts: prints the inline CSS overriding
        //     the WP logo image + accent color
        //   - login_headerurl: changes where the logo link points
        //   - login_headertext: changes the link's title attr (hover
        //     text), avoiding "Powered by WordPress" leak
        add_action('login_enqueue_scripts', [$this, 'maybePrintCustomLoginCss'], 100);
        add_filter('login_headerurl',       [$this, 'filterLoginHeaderUrl'],  100);
        add_filter('login_headertext',      [$this, 'filterLoginHeaderText'], 100);

        // `adminbar_logo` — runs at priority 11 to fire AFTER WP's
        // own `wp_admin_bar_wp_menu` (priority 10) which adds the
        // wp-logo node. We unhook the entire `wp-logo` tree (it
        // carries About / Documentation / Support sub-menu items
        // that leak WP identity) and, if a custom URL is set, drop
        // in a replacement node styled via CSS in the action below.
        add_action('admin_bar_menu',          [$this, 'maybeReplaceAdminBarLogo'], 11);
        add_action('wp_before_admin_bar_render', [$this, 'maybePrintAdminBarLogoCss'], 100);
        add_action('admin_head',                 [$this, 'maybePrintAdminBarLogoCss'], 100);
        add_action('wp_head',                    [$this, 'maybePrintAdminBarLogoCss'], 100);
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
     * `custom_login` (v0.23.0) — emit inline CSS on wp-login.php to
     * replace the WP logo image + accent color. Runs on
     * `login_enqueue_scripts` so the styles inject INSIDE the login
     * page's `<head>`, after WP's own login styles, so our overrides
     * win without `!important` spam.
     *
     * Two independent override knobs:
     *
     *   - `custom_login_logo_url` — replaces `.login h1 a` background
     *     image. We hard-code dimensions (84x84) matching WP's
     *     default logo block so the layout doesn't shift. Custom
     *     logos at other aspect ratios may overflow; the box is
     *     `background-size: contain` so non-square images letterbox.
     *
     *   - `custom_login_color` — applies a tint to the primary
     *     button + the focus accent on the login form input
     *     borders. Validated as a CSS hex color (3 / 4 / 6 / 8 hex
     *     digits) at render time — non-hex values are skipped
     *     entirely rather than emitted as garbage that could break
     *     the page.
     *
     * Self-gates on the toggle being ON. When ON but both URL +
     * color empty, the method early-returns (no CSS to emit) — the
     * toggle alone, without any value, is a no-op. The
     * `login_headerurl` / `login_headertext` filters below ARE
     * applied even with empty URL + color, because their fallback
     * (the configured author URL / plugin name) doesn't require
     * any custom-login-specific config beyond the master toggle.
     */
    public function maybePrintCustomLoginCss(): void
    {
        if (! $this->isToggleOn('custom_login')) {
            return;
        }

        $logoUrl = $this->getToggleString('custom_login_logo_url');
        $color   = $this->sanitizeHexColor($this->getToggleString('custom_login_color'));

        if ($logoUrl === '' && $color === '') {
            // Toggle on but no overrides configured. The
            // login_headerurl / login_headertext filters still run
            // (cheap; their fallback covers the empty case) but we
            // skip the CSS block to keep wp-login.php's source
            // clean for QA.
            return;
        }

        $css = '';

        if ($logoUrl !== '') {
            $css .= sprintf(
                '.login h1 a{background-image:url(\'%s\');background-size:contain;'
                . 'background-position:center center;background-repeat:no-repeat;'
                . 'width:84px;height:84px;}',
                esc_url($logoUrl)
            );
        }

        if ($color !== '') {
            // Accent the primary button + focus rings on the login
            // form. Border + box-shadow combo so the focus state
            // remains visible against light + dark themes.
            $css .= sprintf(
                '.wp-core-ui .button-primary{background:%1$s;border-color:%1$s;'
                . '-webkit-box-shadow:0 1px 0 %1$s;box-shadow:0 1px 0 %1$s;}'
                . '.wp-core-ui .button-primary:hover,'
                . '.wp-core-ui .button-primary:focus{background:%1$s;border-color:%1$s;'
                . 'filter:brightness(0.92);}'
                . '#loginform input[type="text"]:focus,'
                . '#loginform input[type="password"]:focus{border-color:%1$s;'
                . '-webkit-box-shadow:0 0 0 1px %1$s;box-shadow:0 0 0 1px %1$s;}'
                . '.login #backtoblog a:hover,'
                . '.login #nav a:hover{color:%1$s;}',
                $color
            );
        }

        echo '<style id="deckwp-custom-login">' . $css . '</style>';
    }

    /**
     * `custom_login` — retarget the login logo's anchor away from
     * wordpress.org. Falls back to the configured `author_uri`
     * from the per-plugin overrides (if the operator set one for
     * the connector), then to `home_url('/')` (the customer's own
     * site root) — never `https://wordpress.org/` which is WP's
     * default and the whole point of the toggle.
     *
     * Pass-through when toggle is off — we don't touch core's
     * default unless the operator opted in.
     *
     * @param  string $url WP's default value (https://wordpress.org/)
     * @return string
     */
    public function filterLoginHeaderUrl($url)
    {
        if (! $this->isToggleOn('custom_login')) {
            return $url;
        }
        $authorUri = $this->resolveConnectorAuthorUri();
        if ($authorUri !== '') {
            return $authorUri;
        }
        return function_exists('home_url') ? home_url('/') : $url;
    }

    /**
     * `custom_login` — replace the login logo's `title` attribute
     * (also used as the link's accessible text) so hover/screen
     * readers don't announce "Powered by WordPress". Falls back
     * to the configured connector plugin Name override (if set)
     * then to the WP site title — never the WP default.
     *
     * @param  string $text WP's default value (typically the site title or "Powered by WordPress")
     * @return string
     */
    public function filterLoginHeaderText($text)
    {
        if (! $this->isToggleOn('custom_login')) {
            return $text;
        }
        $name = $this->resolveConnectorPluginName();
        if ($name !== '') {
            return $name;
        }
        return function_exists('get_bloginfo') ? (string) get_bloginfo('name') : $text;
    }

    /**
     * `adminbar_logo` — unhook the entire `wp-logo` node (including
     * its sub-menu items: About WordPress, WordPress.org,
     * Documentation, Support, Feedback) so the customer's adminbar
     * doesn't leak WP identity. When `adminbar_logo_url` is set,
     * insert a replacement node `deckwp-whitelabel-logo` in the
     * same `top-secondary` group; its CSS styling happens in
     * {@see self::maybePrintAdminBarLogoCss()}.
     *
     * Runs on `admin_bar_menu` priority 11 — WP adds the wp-logo
     * node at priority 10 via `wp_admin_bar_wp_menu()`, so we
     * have to run after it. Lower priority numbers wouldn't see
     * the node to remove.
     *
     * @param  \WP_Admin_Bar $bar
     */
    public function maybeReplaceAdminBarLogo($bar): void
    {
        if (! $this->isToggleOn('adminbar_logo')) {
            return;
        }
        if (! is_object($bar) || ! method_exists($bar, 'remove_node')) {
            return;
        }

        // Strip the WordPress logo + everything WP hangs off it.
        // The remove_node call cascades to children because WP
        // stores them keyed by parent.
        $bar->remove_node('wp-logo');

        $logoUrl = $this->getToggleString('adminbar_logo_url');
        if ($logoUrl === '') {
            // Toggle on but no custom logo URL — leave the slot
            // empty. The adminbar shows nothing where WordPress
            // used to be; the operator may prefer this over a
            // generic placeholder.
            return;
        }

        $homeUrl = function_exists('home_url') ? home_url('/') : '#';

        $bar->add_node([
            'id'    => 'deckwp-whitelabel-logo',
            'title' => '<span class="ab-icon" aria-hidden="true"></span>'
                . '<span class="screen-reader-text">'
                . esc_html($this->resolveConnectorPluginName() ?: 'Site logo')
                . '</span>',
            'href'  => $homeUrl,
            'meta'  => [
                'title' => $this->resolveConnectorPluginName() ?: '',
            ],
        ]);
    }

    /**
     * `adminbar_logo` — print the CSS that paints the custom logo
     * node's `<span class="ab-icon">` with the operator's image.
     * Emitted across three head contexts (`wp_before_admin_bar_render`,
     * `admin_head`, `wp_head`) because the adminbar renders both in
     * wp-admin AND on the front-end when the user is logged in;
     * each context's head has different action timing.
     *
     * The triple registration is idempotent — the `style#` id below
     * is unique and a duplicate in the same DOM is a no-op
     * (browsers tolerate it). We accept the slight bloat in trade
     * for guaranteed coverage of every context the adminbar
     * actually appears in.
     */
    public function maybePrintAdminBarLogoCss(): void
    {
        if (! $this->isToggleOn('adminbar_logo')) {
            return;
        }
        $logoUrl = $this->getToggleString('adminbar_logo_url');
        if ($logoUrl === '') {
            return;
        }

        // 20x20 matches the WP logo's icon container exactly so the
        // adminbar row height doesn't shift. Custom logos at other
        // aspect ratios are scaled with `background-size: contain`
        // so they letterbox cleanly inside the icon slot.
        $css = sprintf(
            '#wpadminbar #wp-admin-bar-deckwp-whitelabel-logo .ab-icon{'
            . 'background:url(\'%s\') center center / contain no-repeat;'
            . 'width:20px;height:20px;display:inline-block;}'
            . '#wpadminbar #wp-admin-bar-deckwp-whitelabel-logo .ab-icon::before{content:none;}',
            esc_url($logoUrl)
        );

        echo '<style id="deckwp-adminbar-logo">' . $css . '</style>';
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
     * Resolve the connector's own `author_uri` from the per-plugin
     * overrides if the operator configured one. Used by the
     * `custom_login` toggle to retarget the login logo link away
     * from wordpress.org.
     *
     * Returns the empty string when no override is configured, so
     * the caller can fall through to its next fallback (typically
     * `home_url('/')`).
     */
    private function resolveConnectorAuthorUri(): string
    {
        $overrides = $this->getPluginOverrides();
        $ownPath = defined('DECKWP_CONNECT_BASENAME') ? DECKWP_CONNECT_BASENAME : '';
        if ($ownPath === '' || ! isset($overrides[$ownPath]) || ! is_array($overrides[$ownPath])) {
            return '';
        }
        $uri = $overrides[$ownPath]['author_uri'] ?? '';
        return is_string($uri) ? $uri : '';
    }

    /**
     * Resolve the connector's own rebranded plugin `name` from the
     * per-plugin overrides if the operator set one. Used by the
     * `custom_login` toggle for the login logo's hover/accessible
     * text, and by `adminbar_logo` for the replacement node's
     * screen-reader text.
     *
     * Returns the empty string when no override is configured so
     * callers can fall through to their own defaults.
     */
    private function resolveConnectorPluginName(): string
    {
        $overrides = $this->getPluginOverrides();
        $ownPath = defined('DECKWP_CONNECT_BASENAME') ? DECKWP_CONNECT_BASENAME : '';
        if ($ownPath === '' || ! isset($overrides[$ownPath]) || ! is_array($overrides[$ownPath])) {
            return '';
        }
        $name = $overrides[$ownPath]['name'] ?? '';
        return is_string($name) ? $name : '';
    }

    /**
     * Validate a string as a CSS hex color (`#RGB`, `#RGBA`,
     * `#RRGGBB`, `#RRGGBBAA`). Returns the input unchanged when
     * valid, or the empty string when not — caller treats empty as
     * "skip the color override entirely" rather than emitting
     * potentially invalid CSS that could break the rendered page.
     *
     * Why this lives in Branding instead of WhitelabelRoute: the
     * storage layer accepts arbitrary strings (configured shape =
     * "any string"). Strict validation at render time means stale
     * configs from old dashboards never produce broken CSS, no
     * matter what got written into the option.
     */
    private function sanitizeHexColor(string $color): string
    {
        if ($color === '') {
            return '';
        }
        // Allow 3 / 4 / 6 / 8 hex digits with or without leading `#`.
        // Normalize to always have the `#` prefix on return.
        $color = ltrim($color, '#');
        if (! preg_match('/^[0-9a-fA-F]{3,8}$/', $color)) {
            return '';
        }
        $len = strlen($color);
        if ($len !== 3 && $len !== 4 && $len !== 6 && $len !== 8) {
            return '';
        }
        return '#' . $color;
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
