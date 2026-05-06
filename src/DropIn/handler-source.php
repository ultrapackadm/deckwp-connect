<?php
/**
 * DeckWP Connect — fatal-error-handler drop-in.
 *
 * Marker: DECKWP_FATAL_HANDLER_MARKER
 *
 * Lives at wp-content/fatal-error-handler.php after install. Detected
 * and managed by {@see \DeckWP\Connect\DropIn\Installer} in the
 * connector plugin.
 *
 * WordPress loads this file via `include` from
 * `wp_register_fatal_error_handler()` in wp-settings.php — outside
 * any plugin context. There is NO namespace, NO autoloader, NO use
 * of plugin classes. The file must be self-contained.
 *
 * Identification: the marker constant DECKWP_FATAL_HANDLER_MARKER and
 * the literal comment string above are what the Installer's
 * classifyExisting() greps for. If present, the drop-in is "ours" and
 * safe to overwrite. Absent → "foreign", do not touch (could be a
 * hosting provider's drop-in or another plugin's).
 *
 * Slice 1 of the rollout: install plumbing only. The handle()
 * override falls through to WP's default behavior. Slices 2-4 add:
 *   2. Single-site detection: longest-prefix-match against the error
 *      trace + active_plugins, log to deckwp_fatal_log option (cap 50),
 *      auto-deactivate offending plugin.
 *   3. Multisite: switch_to_blog loop across the network to find the
 *      culprit.
 *   4. Memory exhaustion + branded 503 splash.
 *
 * @package DeckWP\Connect
 */

defined('ABSPATH') || exit;

if (! defined('DECKWP_FATAL_HANDLER_MARKER')) {
    define('DECKWP_FATAL_HANDLER_MARKER', 'deckwp/connect:fatal-handler:1');
}

if (! defined('DECKWP_DROPIN_VERSION')) {
    // Bumped by the Installer when source changes. Used in future
    // slices to drive in-place upgrades (Slice 2+).
    define('DECKWP_DROPIN_VERSION', '0.12.0-slice1');
}

if (! class_exists('WP_Fatal_Error_Handler')) {
    // Pre-WP-5.2 — no fatal handler API to extend. Fall back to a
    // no-op object with a `handle()` method so wp_register_fatal_error_handler()
    // accepts the return value but otherwise does nothing different
    // from native behavior.
    require_once ABSPATH . WPINC . '/class-wp-fatal-error-handler.php';
}

/**
 * Slice 1 skeleton: extends core's handler and currently delegates
 * everything to it. Real detection / auto-deactivation lands in the
 * next slices. Keeping the file shape stable now means the install
 * mechanism is verifiable in isolation.
 */
class DeckWP_Fatal_Error_Handler extends WP_Fatal_Error_Handler
{
    public function handle()
    {
        // TODO Slice 2: identify the offending plugin via
        // longest-prefix-match on $error['file'] + active_plugins,
        // log to deckwp_fatal_log option (cap 50), auto-deactivate.
        //
        // TODO Slice 3: switch_to_blog loop for multisite networks.
        //
        // TODO Slice 4: memory-exhaustion branch + branded 503 splash.
        //
        // For now, fall through to WP's default behavior so we don't
        // regress existing recovery-mode emails while the rest of the
        // slices are being built.
        parent::handle();
    }
}

return new DeckWP_Fatal_Error_Handler();
