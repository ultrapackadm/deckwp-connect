<?php
/**
 * Plugin Name:       DeckWP Connect
 * Plugin URI:        https://deckwp.com
 * Description:       Connects this WordPress site to your DeckWP dashboard for one-click bulk updates, scan + auto-fix, automatic backup & rollback, SSO login, and remote management.
 * Version:           0.37.0
 * Author:            DeckWP
 * Author URI:        https://deckwp.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       deckwp-connect
 * Domain Path:       /languages
 * Requires at least: 5.2
 * Tested up to:      6.7
 * Requires PHP:      7.4
 */

defined('ABSPATH') || exit;

// Duplicate-load guard. If another copy of this plugin has already been
// loaded in the same request (e.g. operator uploaded a fresh ZIP without
// deleting the existing folder — `deckwp-connect/` + `deckwp-connect-main/`
// from a GitHub source archive — and both are active in `active_plugins`),
// the first copy wins and the second one bails out silently. The constants
// + functions declared below are already in scope, the Bootstrap class is
// already autoloaded, and re-declaring any of it would either throw notices
// or — for the top-level function declarations — fatal with "Cannot
// redeclare function".
//
// Bailing here is preferable to `function_exists()`-guarding every
// declaration because the second copy's __FILE__ + plugin_basename are
// different from the first, which would otherwise pollute the
// DECKWP_CONNECT_FILE / DECKWP_CONNECT_DIR / DECKWP_CONNECT_BASENAME
// constants (define() throws notices on collision but DOES NOT update
// the value — so the second copy's path never wins, leading to subtle
// bugs in the autoloader looking at the WRONG src/ dir). Early return
// keeps the first copy's identity intact across the request.
if (defined('DECKWP_CONNECT_VERSION')) {
    return;
}

define('DECKWP_CONNECT_VERSION',  '0.37.0');
define('DECKWP_CONNECT_FILE',     __FILE__);
define('DECKWP_CONNECT_DIR',      plugin_dir_path(__FILE__));
define('DECKWP_CONNECT_URL',      plugin_dir_url(__FILE__));
define('DECKWP_CONNECT_BASENAME', plugin_basename(__FILE__));

// Autoloader (Composer if available, fallback to PSR-4 manual)
if (file_exists(DECKWP_CONNECT_DIR . 'vendor/autoload.php')) {
    require_once DECKWP_CONNECT_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register(function ($class) {
        $prefix = 'DeckWP\\Connect\\';
        $base_dir = DECKWP_CONNECT_DIR . 'src/';
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = $base_dir . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    });
}

// ─── Activation / Deactivation ────────────────────────────────────────────
//
// All top-level function declarations are guarded with `function_exists()`
// so the file can be loaded twice without a "Cannot redeclare function"
// fatal. This happens in practice when an operator manually uploads a
// new release ZIP (e.g. `deckwp-connect-main.zip` from GitHub's source
// archive) without first deleting the existing `deckwp-connect/` folder
// — WordPress sees them as two separate plugins, loads both files, and
// every top-level function declaration in the second load explodes.
// Guarding keeps the second load a silent no-op (the constants above
// also re-define but PHP `define()` is non-fatal on collision).
register_activation_hook(__FILE__, 'deckwp_connect_on_activate');
register_deactivation_hook(__FILE__, 'deckwp_connect_on_deactivate');

if (! function_exists('deckwp_connect_on_activate')) {
    function deckwp_connect_on_activate(): void
    {
        $defaults = [
            'site_id'      => '',
            'token'        => '',
            'hmac_secret'  => '',
            'platform_url' => '',
            'connected_at' => '',
        ];

        if (function_exists('is_multisite') && is_multisite()) {
            if (!get_site_option('deckwp_connect_settings')) {
                add_site_option('deckwp_connect_settings', $defaults);
            }
        } else {
            if (!get_option('deckwp_connect_settings')) {
                // autoload=false to keep hmac_secret out of the always-loaded options cache
                add_option('deckwp_connect_settings', $defaults, '', false);
            }
        }

        deckwp_connect_ensure_pairing_token();
    }
}

if (! function_exists('deckwp_connect_on_deactivate')) {
    function deckwp_connect_on_deactivate(): void
    {
        wp_clear_scheduled_hook('deckwp_connect_heartbeat');
        // TODO (Sprint 1 G6): uninstall fatal-handler drop-in if it's ours
    }
}

if (! function_exists('deckwp_connect_ensure_pairing_token')) {
    /**
     * Generate token + hmac_secret if missing. Used at activation and on every
     * boot as a safety net (Sprint 1 G2 will move into TokenManager class).
     */
    function deckwp_connect_ensure_pairing_token(): void
    {
        $opt = function_exists('is_multisite') && is_multisite()
            ? (array) get_site_option('deckwp_connect_settings', [])
            : (array) get_option('deckwp_connect_settings', []);

        $needs_token  = empty($opt['token']);
        $needs_secret = empty($opt['hmac_secret']);

        if (!$needs_token && !$needs_secret) {
            return;
        }

        if ($needs_token) {
            $opt['token'] = bin2hex(random_bytes(24)); // 48 hex chars
        }
        if ($needs_secret) {
            $opt['hmac_secret'] = base64_encode(random_bytes(32));
        }

        if (function_exists('is_multisite') && is_multisite()) {
            update_site_option('deckwp_connect_settings', $opt);
        } else {
            update_option('deckwp_connect_settings', $opt);
        }
    }
}

// ─── Boot ─────────────────────────────────────────────────────────────────
//
// All subsystem wiring lives in {@see DeckWP\Connect\Bootstrap} — this
// hook just kicks it off after WP has finished loading plugins (so other
// plugins' filters are available, e.g. for the Whitelabel branding hooks
// once that subsystem ships).
add_action('plugins_loaded', static function () {
    deckwp_connect_ensure_pairing_token();

    \DeckWP\Connect\Bootstrap::boot();
});

// Settings link on the plugins list row
add_filter('plugin_action_links_' . DECKWP_CONNECT_BASENAME, static function ($links) {
    $url = admin_url('options-general.php?page=deckwp-connect');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'deckwp-connect') . '</a>');
    return $links;
});
