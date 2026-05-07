<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\Updater\UpdateSuppressor;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route: set the list of plugins / themes that DeckWP
 * is managing on this site.
 *
 *     POST /wp-json/deckwp/v1/set-managed-slugs
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *     Content-Type: application/json
 *
 *     { "plugins": ["formidable-pro/formidable-pro.php", "wp-rocket"],
 *       "themes":  ["avada"] }
 *
 * Response 200:
 *
 *     { "ok": true,
 *       "stored": { "plugins": 2, "themes": 1 } }
 *
 * Response 4xx: `{ "ok": false, "error": "...", "error_code": "..." }`
 *
 * The dashboard sends the full intended state on every call (no
 * incremental add/remove) — operator removes a site from a plan
 * tier, dashboard resyncs the list with the entries dropped, and
 * suppression for those slugs goes away on the next admin page load.
 *
 * Plugin entries can be the WP plugin_path (`slug/main.php`) OR
 * just the folder slug (`slug`) — the {@see UpdateSuppressor}
 * matches both shapes. Theme entries are folder slugs only.
 *
 * Storage is the `deckwp_managed_slugs` site option (network-wide
 * on multisite, equivalent to wp_options on single-site). One
 * source of truth feeds the suppressor; no per-blog list. If the
 * dashboard ever needs per-blog managed lists, that's a wire-shape
 * extension (`'blogs' => [id => ...]`) — out of scope for the
 * single-site-first MVP.
 *
 * Empty arrays are valid: `{ "plugins": [], "themes": [] }`
 * effectively unmanages everything for this site.
 */
class SetManagedSlugsRoute
{
    /**
     * @param  callable  $permissionCallback HMAC verifier, supplied by Server.
     * @return array<string, mixed>
     */
    public function args(callable $permissionCallback): array
    {
        return [
            'methods'             => 'POST',
            'permission_callback' => $permissionCallback,
            'callback'            => [$this, 'handle'],
            'args'                => [
                'plugins' => [
                    'required' => false,
                    'type'     => 'array',
                    'default'  => [],
                ],
                'themes' => [
                    'required' => false,
                    'type'     => 'array',
                    'default'  => [],
                ],
            ],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $plugins = $request->get_param('plugins');
        $themes  = $request->get_param('themes');

        // Args defaults only apply when WP routes through register_rest_route.
        // Fall back to [] for direct handler calls (e.g. internal dispatch).
        if (! is_array($plugins)) {
            $plugins = [];
        }
        if (! is_array($themes)) {
            $themes = [];
        }

        // At least one of plugins/themes must be present in the body —
        // an entirely empty payload is suspicious (typo / wrong route)
        // rather than an intent to clear. To clear, send explicit
        // empty arrays for both keys.
        if ($plugins === [] && $themes === [] && ! $this->bothKeysProvided($request)) {
            return new WP_REST_Response(
                [
                    'ok'         => false,
                    'error'      => 'Body must include at least one of `plugins` or `themes`. Use empty arrays to clear.',
                    'error_code' => 'invalid_input',
                ],
                422
            );
        }

        $cleanPlugins = $this->sanitizeStringList($plugins);
        $cleanThemes  = $this->sanitizeStringList($themes);

        $payload = [
            'plugins' => $cleanPlugins,
            'themes'  => $cleanThemes,
        ];

        update_site_option(UpdateSuppressor::OPTION_KEY, $payload);

        return new WP_REST_Response(
            [
                'ok'     => true,
                'stored' => [
                    'plugins' => count($cleanPlugins),
                    'themes'  => count($cleanThemes),
                ],
            ],
            200
        );
    }

    /**
     * Check whether the caller deliberately included both keys (even
     * with empty arrays) — i.e. the "clear all" intent — vs. simply
     * forgot to send a body.
     *
     * `WP_REST_Request::get_json_params()` returns the parsed body;
     * we look for explicit key presence rather than value to distinguish.
     */
    private function bothKeysProvided(WP_REST_Request $request): bool
    {
        $body = $request->get_json_params();
        if (! is_array($body)) {
            return false;
        }
        return array_key_exists('plugins', $body) && array_key_exists('themes', $body);
    }

    /**
     * Filter to non-empty strings. Doesn't validate plugin path
     * shape — managed lists are sent by our trusted dashboard;
     * stripping bad entries here is enough.
     *
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
        // Re-key sequentially so the stored array is a JSON list,
        // not an associative array with gaps.
        return array_values(array_unique($out));
    }
}
