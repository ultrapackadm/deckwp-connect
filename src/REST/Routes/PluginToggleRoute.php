<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route that activates or deactivates a single plugin.
 *
 *     POST /wp-json/deckwp/v1/plugin-toggle
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *     Content-Type: application/json
 *
 *     { "slug": "litespeed-cache", "active": true }
 *
 * Response 200 (success):
 *
 *     { "slug": "litespeed-cache", "active": true,
 *       "plugin_file": "litespeed-cache/litespeed-cache.php",
 *       "error": null }
 *
 * Response 200 (failure — plugin not found, activation hook errored,
 * required dependencies missing, etc.):
 *
 *     { "slug": "...", "active": <previous_state>,
 *       "error": "Plugin not installed on this WordPress install." }
 *
 * Triggered by the dashboard's:
 *   - Library "Install on…" picker, when the operator leaves the
 *     "Activate after install" checkbox checked. The post-install
 *     activation goes through this route as a separate call rather
 *     than inline in `/install-batch`, so a slow activation hook
 *     doesn't push the install request past its timeout.
 *   - Library install-progress modal, "Activate now" button on
 *     succeeded rows where activation wasn't done at install time.
 *   - (future) /sites/{id} per-row toggle.
 *
 * ## Why this is its own route
 *
 * Activation state is independent of install state. The connector's
 * `/install-batch` already runs `Plugin_Upgrader::install()` which
 * leaves the plugin inactive — keeping that behavior the default
 * means the operator gets to see a plugin's settings page before
 * its activation hooks run (which is how WordPress users avoid
 * "the plugin took over my admin" surprises). Activation belongs
 * in a separate verb the operator can opt into per-plugin.
 *
 * The dashboard's existing `/install-batch` still doesn't change —
 * v0.13's fresh-install path leaves the plugin inactive, this
 * route is the operator's explicit "yes, activate now" follow-up.
 *
 * ## Authorization & idempotency
 *
 * HMAC-verified by {@see \DeckWP\Connect\REST\Auth\HmacVerifier}.
 * Idempotent — toggling to the state the plugin is already in is a
 * no-op (returns ok with `active: <current>`, no error).
 *
 * ## Activation hooks
 *
 * Plugins routinely register `register_activation_hook()` callbacks
 * that run on activation. A misbehaving callback (DB write that
 * fails, file write that fails) gets surfaced as a fatal — we
 * catch it via the WP_Error contract on `activate_plugin()` and
 * surface verbatim in the response so the dashboard can display
 * the cause to the operator without them having to bounce to
 * WP admin. Deactivation hooks rarely fail but are caught the
 * same way.
 */
class PluginToggleRoute
{
    /**
     * Route registration array. Consumed by
     * {@see \DeckWP\Connect\REST\Server::registerRoutes()}.
     *
     * @param  callable  $permissionCallback
     * @return array<string, mixed>
     */
    public function args(callable $permissionCallback): array
    {
        return [
            'methods'             => 'POST',
            'permission_callback' => $permissionCallback,
            'callback'            => [$this, 'handle'],
            'args'                => [
                'slug' => [
                    'required' => true,
                    'type'     => 'string',
                ],
                'active' => [
                    'required' => true,
                    'type'     => 'boolean',
                ],
            ],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $slug   = (string) $request->get_param('slug');
        $active = (bool) $request->get_param('active');

        if ($slug === '') {
            return new WP_REST_Response(
                ['slug' => '', 'active' => false, 'error' => 'Missing slug.'],
                422
            );
        }

        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $pluginFile = $this->findPluginFile($slug);
        if ($pluginFile === null) {
            return new WP_REST_Response([
                'slug'        => $slug,
                'active'      => false,
                'plugin_file' => null,
                'error'       => 'Plugin not installed on this WordPress install.',
            ], 200);
        }

        $isActive = is_plugin_active($pluginFile);

        // Idempotent — already in the requested state.
        if ($isActive === $active) {
            return new WP_REST_Response([
                'slug'        => $slug,
                'active'      => $active,
                'plugin_file' => $pluginFile,
                'error'       => null,
            ], 200);
        }

        if ($active) {
            // activate_plugin() returns null on success, WP_Error on
            // activation-hook failure. The third arg `silent=false`
            // means the standard `activate_plugin` action hook fires —
            // matching what happens when an admin clicks Activate in
            // the WP admin Plugins list.
            $result = activate_plugin($pluginFile, '', false, false);
            if (is_wp_error($result)) {
                return new WP_REST_Response([
                    'slug'        => $slug,
                    'active'      => false, // unchanged; the activation failed
                    'plugin_file' => $pluginFile,
                    'error'       => $this->formatWpError($result),
                ], 200);
            }
        } else {
            // deactivate_plugins() doesn't return a value. We re-read
            // the active state below to confirm.
            deactivate_plugins([$pluginFile], false, false);
        }

        // Re-read the state. If the activation/deactivation failed
        // silently (rare but possible — some plugins refuse to
        // deactivate when WP_DEBUG is off and the deactivation hook
        // throws), the response should reflect actual state, not
        // wishful thinking.
        $finalState = is_plugin_active($pluginFile);

        if ($finalState !== $active) {
            return new WP_REST_Response([
                'slug'        => $slug,
                'active'      => $finalState,
                'plugin_file' => $pluginFile,
                'error'       => sprintf(
                    'Toggle did not persist — plugin is still %s after the call.',
                    $finalState ? 'active' : 'inactive'
                ),
            ], 200);
        }

        return new WP_REST_Response([
            'slug'        => $slug,
            'active'      => $finalState,
            'plugin_file' => $pluginFile,
            'error'       => null,
        ], 200);
    }

    /**
     * Map a slug to its plugin file path. Mirrors the same lookup
     * used by {@see \DeckWP\Connect\Install\Installer::findPluginFile()}
     * — single source of truth would be nicer, but the Installer's
     * method is private and pulling it into a shared helper is
     * out of scope for this route.
     */
    private function findPluginFile(string $slug): ?string
    {
        if (! function_exists('get_plugins')) {
            return null;
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

    /**
     * Flatten a WP_Error into a string the dashboard can display.
     */
    private function formatWpError(\WP_Error $error): string
    {
        $code = $error->get_error_code();
        $msg  = $error->get_error_message();
        if (! is_string($code) || $code === '') {
            return is_string($msg) && $msg !== '' ? $msg : 'Unknown WP_Error.';
        }
        return is_string($msg) && $msg !== ''
            ? sprintf('[%s] %s', $code, $msg)
            : (string) $code;
    }
}
