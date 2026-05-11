<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route that activates (switches to) a single installed theme.
 *
 *     POST /wp-json/deckwp/v1/theme-switch
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *     Content-Type: application/json
 *
 *     { "slug": "twentytwentyfour" }
 *
 * Response 200 (success):
 *
 *     { "slug": "twentytwentyfour", "active": true,
 *       "stylesheet": "twentytwentyfour", "error": null }
 *
 * Response 200 (failure — theme not installed, switch_theme didn't take,
 * activation hook errored):
 *
 *     { "slug": "...", "active": false, "stylesheet": null,
 *       "error": "Theme not installed on this WordPress install." }
 *
 * Triggered by the dashboard's Library install-progress modal's
 * "Activate now" button on succeeded theme rows, when the operator
 * either:
 *   - unchecked the install-time "Activate after install" checkbox
 *     and changed their mind, OR
 *   - the connector that ran the install was pre-v0.17 and silently
 *     dropped the inline switch step.
 *
 * Plus eventually a per-row toggle in the dashboard's site detail
 * page (Task 1.2 of the FRONTEND_ROADMAP).
 *
 * ## Why a separate route from /plugin-toggle
 *
 * Plugin activation is additive (multiple plugins can be active at
 * once). Theme activation is *destructive* (only ONE theme is active;
 * switching replaces the previous theme on the live frontend). The
 * semantics + payload differ enough that conflating them in one
 * route would force every caller to carry a `kind` discriminator —
 * and the dashboard's operator-facing copy ("Activate this plugin
 * now" vs "Switch the live theme to this one") needs the verb-level
 * distinction anyway.
 *
 * ## No deactivate
 *
 * WordPress always has exactly one active theme. There's no
 * `deactivate_theme()` primitive — switching away from a theme
 * happens by activating a different one. This route only flips a
 * theme TO active; the dashboard surfaces the destructive
 * confirmation copy ("This will replace your active theme") before
 * calling.
 *
 * ## Authorization & idempotency
 *
 * HMAC-verified by {@see \DeckWP\Connect\REST\Auth\HmacVerifier}.
 * Idempotent — switching to the theme that's already active is a
 * no-op success (returns `active: true` without re-running the
 * activation hooks).
 *
 * ## Activation hooks
 *
 * `switch_theme()` fires the `switch_theme` + `after_switch_theme`
 * action hooks. Themes routinely register callbacks on those (set
 * up customizer defaults, register nav menus). A misbehaving
 * callback that throws a fatal will surface to the WP error handler;
 * we re-read `get_stylesheet()` after the call to detect "switch
 * didn't take" cases and surface that in the response.
 */
class ThemeSwitchRoute
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
            ],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request->get_param('slug');

        if ($slug === '') {
            return new WP_REST_Response(
                ['slug' => '', 'active' => false, 'stylesheet' => null, 'error' => 'Missing slug.'],
                422
            );
        }

        if (! function_exists('wp_get_themes')) {
            // theme.php pulls wp_get_themes + switch_theme + friends.
            require_once ABSPATH . 'wp-includes/theme.php';
        }

        $stylesheet = $this->findThemeStylesheet($slug);
        if ($stylesheet === null) {
            return new WP_REST_Response([
                'slug'       => $slug,
                'active'     => false,
                'stylesheet' => null,
                'error'      => 'Theme not installed on this WordPress install.',
            ], 200);
        }

        $current = function_exists('get_stylesheet') ? (string) get_stylesheet() : '';

        // Idempotent — theme already active.
        if ($current === $stylesheet) {
            return new WP_REST_Response([
                'slug'       => $slug,
                'active'     => true,
                'stylesheet' => $stylesheet,
                'error'      => null,
            ], 200);
        }

        switch_theme($stylesheet);

        // Re-read to catch the rare case where a `switch_theme`
        // action handler redirected to a different theme (multisite
        // network themes do this, as do some preset-locking plugins).
        $newCurrent = function_exists('get_stylesheet') ? (string) get_stylesheet() : '';
        $activated  = ($newCurrent === $stylesheet);

        return new WP_REST_Response([
            'slug'       => $slug,
            'active'     => $activated,
            'stylesheet' => $activated ? $stylesheet : ($newCurrent ?: null),
            'error'      => $activated
                ? null
                : sprintf(
                    'switch_theme(%s) did not take effect — active stylesheet is now %s.',
                    $stylesheet,
                    $newCurrent ?: 'unknown'
                ),
        ], 200);
    }

    /**
     * Locate a theme on disk by wp.org slug. Mirrors the same lookup
     * helper used by {@see \DeckWP\Connect\Install\Installer::findThemeStylesheet()}.
     */
    private function findThemeStylesheet(string $slug): ?string
    {
        if (! function_exists('wp_get_themes')) {
            return null;
        }

        $themes = wp_get_themes();
        foreach ($themes as $stylesheet => $theme) {
            if ((string) $stylesheet === $slug) {
                return (string) $stylesheet;
            }
            $textDomain = method_exists($theme, 'get') ? (string) $theme->get('TextDomain') : '';
            if ($textDomain !== '' && $textDomain === $slug) {
                return (string) $stylesheet;
            }
        }

        return null;
    }
}
