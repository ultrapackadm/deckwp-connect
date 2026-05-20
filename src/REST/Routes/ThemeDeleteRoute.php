<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route that deletes a single installed theme.
 *
 *     POST /wp-json/deckwp/v1/theme-delete
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *     Content-Type: application/json
 *
 *     { "slug": "twentytwentyfour" }
 *
 * Response 200 (success):
 *
 *     { "slug": "twentytwentyfour", "deleted": true, "error": null }
 *
 * Response 200 (failure — theme not installed, active, parent of an
 * active child, filesystem error, etc.):
 *
 *     { "slug": "...", "deleted": false,
 *       "error": "Cannot delete the active theme — activate a different theme first." }
 *
 * Triggered by the per-row Delete button on the dashboard's Themes
 * tab. The button is disabled in the UI for the active theme, but
 * we re-check server-side as defense-in-depth (the operator might
 * have JS off, or the dashboard's view of the active theme might
 * be stale from a heartbeat lag).
 *
 * ## Why a separate route from /plugin-toggle or /theme-switch
 *
 * Distinct verbs, distinct safety posture. `/theme-switch` activates;
 * `/theme-delete` removes files from disk. Activation is reversible
 * by re-switching; deletion requires re-installing from the
 * dashboard library to undo. Conflating them in one route would
 * force every caller to carry a `verb` discriminator AND would
 * make the audit trail harder to read (every theme operation
 * looking the same in connector logs).
 *
 * ## Safety checks (in order)
 *
 *   1. Slug is non-empty (422 otherwise — malformed request).
 *   2. Theme exists on disk (`wp_get_themes()` lookup; same matcher
 *      as ThemeSwitchRoute — stylesheet-or-textdomain). Returns
 *      200 + `error: "not installed"` if the slug isn't found,
 *      so the dashboard's per-row delete is idempotent: deleting
 *      something already gone is success-shaped.
 *   3. Theme is NOT the active stylesheet. WP would also reject
 *      this inside `delete_theme()`, but the explicit pre-check
 *      gives a clearer error message and skips the
 *      WP_Filesystem init cost on the doomed path.
 *   4. Theme is NOT the parent of the active child theme. WP's
 *      own `delete_theme()` doesn't always catch this (varies by
 *      version), and deleting a parent under an active child
 *      bricks the customer's frontend with "broken theme"
 *      errors. Explicit reject.
 *   5. WP_Filesystem init succeeds (delete_theme requires it).
 *      In most environments this is direct filesystem access; on
 *      hosts that force FTP/SSH credentials it can fail without
 *      them. We surface that case as an error rather than
 *      hanging or returning ambiguous truthiness.
 *
 * ## Authorization & idempotency
 *
 * HMAC-verified by {@see \DeckWP\Connect\REST\Auth\HmacVerifier}.
 * Idempotent — deleting a theme that's already gone returns
 * `deleted: false` with `error: "not installed"` rather than 404,
 * so the dashboard's optimistic UI update doesn't reverse on a
 * race condition.
 *
 * ## Why not a DELETE HTTP verb
 *
 * Consistency with the rest of the connector's REST surface, which
 * uses POST for every state-changing operation (plugin-toggle,
 * theme-switch, whitelabel, install-batch, etc.). Mixing verbs
 * would force the HMAC verifier + the dashboard's signer to
 * branch on method, with no real REST-ful gain — the body
 * carries the resource identifier either way.
 */
class ThemeDeleteRoute
{
    /**
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
                ['slug' => '', 'deleted' => false, 'error' => 'Missing slug.'],
                422
            );
        }

        if (! function_exists('wp_get_themes')) {
            require_once ABSPATH . 'wp-includes/theme.php';
        }

        $stylesheet = $this->findThemeStylesheet($slug);
        if ($stylesheet === null) {
            // Idempotent: theme is already gone (or was never here).
            // Returning deleted=false with a clear error lets the
            // dashboard distinguish "already done" from "broken"
            // without complicating the response envelope.
            return new WP_REST_Response([
                'slug'    => $slug,
                'deleted' => false,
                'error'   => 'Theme not installed on this WordPress install.',
            ], 200);
        }

        $activeStylesheet = function_exists('get_stylesheet') ? (string) get_stylesheet() : '';
        $activeTemplate   = function_exists('get_template')   ? (string) get_template()   : '';

        if ($stylesheet === $activeStylesheet) {
            return new WP_REST_Response([
                'slug'    => $slug,
                'deleted' => false,
                'error'   => 'Cannot delete the active theme — activate a different theme first.',
            ], 200);
        }

        if ($stylesheet === $activeTemplate && $activeStylesheet !== $activeTemplate) {
            // The active theme is a child (`get_stylesheet` and
            // `get_template` differ), and we'd be deleting its
            // parent. WP would still render the child but every
            // template inherit would 404 — frontend bricks.
            return new WP_REST_Response([
                'slug'    => $slug,
                'deleted' => false,
                'error'   => 'Cannot delete the parent theme of the currently active child theme.',
            ], 200);
        }

        // Pull in delete_theme + WP_Filesystem. Both live in
        // wp-admin/ which isn't loaded on REST requests by default.
        if (! function_exists('delete_theme')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }
        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // delete_theme needs the WP_Filesystem global initialized.
        // On most hosts the 'direct' method works without
        // credentials; some shared hosts force FTP/SSH which we
        // can't satisfy from a REST request, so surface that case.
        global $wp_filesystem;
        if (! WP_Filesystem()) {
            return new WP_REST_Response([
                'slug'    => $slug,
                'deleted' => false,
                'error'   => 'Could not initialize WP filesystem (host may require FTP credentials).',
            ], 200);
        }

        $result = delete_theme($stylesheet);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'slug'    => $slug,
                'deleted' => false,
                'error'   => $result->get_error_message(),
            ], 200);
        }
        if ($result === false) {
            return new WP_REST_Response([
                'slug'    => $slug,
                'deleted' => false,
                'error'   => 'delete_theme returned false (filesystem error during removal).',
            ], 200);
        }

        // delete_theme returned true. Bust the themes cache + re-
        // verify by re-running the lookup. Belt-and-braces against
        // a future delete_theme implementation that returns true
        // before the directory is actually gone.
        if (function_exists('wp_clean_themes_cache')) {
            wp_clean_themes_cache();
        }
        $stillExists = $this->findThemeStylesheet($slug) !== null;

        return new WP_REST_Response([
            'slug'    => $slug,
            'deleted' => ! $stillExists,
            'error'   => $stillExists
                ? 'delete_theme returned true but the theme is still present on disk.'
                : null,
        ], 200);
    }

    /**
     * Locate a theme by stylesheet or text-domain. Same matcher as
     * ThemeSwitchRoute — keeps the dashboard's slug-shaped API
     * consistent across switch + delete operations.
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
