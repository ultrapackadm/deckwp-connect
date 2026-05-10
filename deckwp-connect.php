<?php
/**
 * Plugin Name:       DeckWP Connect
 * Plugin URI:        https://deckwp.com
 * Description:       Connects this WordPress site to your DeckWP dashboard for one-click bulk updates, scan + auto-fix, automatic backup & rollback, SSO login, and remote management.
 * Version:           0.16.0
 * Author:            DeckWP
 * Author URI:        https://deckwp.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       deckwp-connect
 * Domain Path:       /languages
 * Requires at least: 5.6
 * Tested up to:      6.7
 * Requires PHP:      7.4
 */

defined('ABSPATH') || exit;

define('DECKWP_CONNECT_VERSION',  '0.16.0');
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
register_activation_hook(__FILE__, 'deckwp_connect_on_activate');
register_deactivation_hook(__FILE__, 'deckwp_connect_on_deactivate');

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

function deckwp_connect_on_deactivate(): void
{
    wp_clear_scheduled_hook('deckwp_connect_heartbeat');
    // TODO (Sprint 1 G6): uninstall fatal-handler drop-in if it's ours
}

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
