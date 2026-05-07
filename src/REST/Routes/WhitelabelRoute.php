<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\Whitelabel\Branding;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route: store the whitelabel branding config the
 * dashboard wants applied on this site's admin UI.
 *
 *     POST /wp-json/deckwp/v1/whitelabel
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *     Content-Type: application/json
 *
 *     { "plugins": {
 *         "akismet/akismet.php": {
 *           "name":        "Spam Shield",
 *           "description": "Stops spam comments.",
 *           "author":      "DeckWP",
 *           "author_uri":  "https://deckwp.com",
 *           "plugin_uri":  "https://deckwp.com/spam-shield",
 *           "hide":        false
 *         },
 *         "wp-rocket/wp-rocket.php": { "hide": true }
 *       },
 *       "themes": { ... }   // reserved for v2
 *     }
 *
 * Response 200:
 *
 *     { "ok": true, "stored": { "plugins": 2, "themes": 0 } }
 *
 * The dashboard sends the full intended state on every call (no
 * incremental edits). When the operator updates a plugin's branding
 * in the dashboard UI, the dashboard re-pushes the entire config —
 * the connector replaces the option wholesale. Empty objects are
 * valid (`{ "plugins": {}, "themes": {} }`) and effectively clear
 * the whitelabel.
 *
 * ## Why PUSH instead of PULL
 *
 * The ROADMAP originally listed a `GET /api/v1/whitelabel?site_id=X`
 * pull endpoint on the dashboard side. PUSH is simpler:
 *   - Real-time: rebrand changes show up on the next admin page load,
 *     not on the next pull cron tick.
 *   - One direction of integration: dashboard pushes when operator
 *     saves config; connector handles the inbound. No connector cron
 *     to maintain, no eventual-consistency window to debug.
 *   - Symmetry with `/set-managed-slugs` and `/maintenance` routes
 *     that already follow the push pattern.
 *
 * If a future use case wants pull (e.g. a fresh site re-installed
 * needs to re-fetch the config without waiting for the next push),
 * adding it is a wire-shape extension, not a refactor.
 *
 * ## Sanitization posture
 *
 * Strings are stored verbatim — this is internal trusted-dashboard
 * input, not user-content. The Branding class itself doesn't escape
 * the strings before piping them through WP's plugin metadata
 * filters; WP's admin templates auto-escape what they render
 * (`esc_html` on `Name`, `esc_url` on `AuthorURI`, etc.).
 *
 * Unknown keys are dropped; non-string values for known string keys
 * are dropped. Empty payloads return 200 + stored counts of 0
 * (clear-all intent).
 */
class WhitelabelRoute
{
    /**
     * @param  callable  $permissionCallback HMAC verifier supplied by the Server.
     * @return array<string, mixed>
     */
    public function args(callable $permissionCallback): array
    {
        return [
            'methods'             => 'POST',
            'permission_callback' => $permissionCallback,
            'callback'            => [$this, 'handle'],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        if (! is_array($body)) {
            $body = [];
        }

        $rawPlugins = isset($body['plugins']) && is_array($body['plugins']) ? $body['plugins'] : [];
        $rawThemes  = isset($body['themes'])  && is_array($body['themes'])  ? $body['themes']  : [];

        $payload = [
            'plugins' => $this->sanitizePluginOverrides($rawPlugins),
            'themes'  => $this->sanitizeThemeOverrides($rawThemes),
        ];

        update_site_option(Branding::OPTION_KEY, $payload);

        return new WP_REST_Response(
            [
                'ok'     => true,
                'stored' => [
                    'plugins' => count($payload['plugins']),
                    'themes'  => count($payload['themes']),
                ],
            ],
            200
        );
    }

    /**
     * Filter to known string keys + boolean `hide`. Unknown keys
     * dropped silently — defensive against future dashboard versions
     * that may add fields the current connector doesn't understand.
     *
     * @param  array<string, mixed> $overrides
     * @return array<string, array<string, mixed>>
     */
    private function sanitizePluginOverrides(array $overrides): array
    {
        $out = [];
        foreach ($overrides as $path => $entry) {
            if (! is_string($path) || $path === '' || ! is_array($entry)) {
                continue;
            }
            $clean = [];
            foreach (['name', 'description', 'author', 'author_uri', 'plugin_uri'] as $key) {
                if (isset($entry[$key]) && is_string($entry[$key])) {
                    $clean[$key] = $entry[$key];
                }
            }
            if (array_key_exists('hide', $entry)) {
                $clean['hide'] = (bool) $entry['hide'];
            }
            // Skip entries that contributed nothing — empty {} entries
            // are pointless storage bloat.
            if (! empty($clean)) {
                $out[$path] = $clean;
            }
        }
        return $out;
    }

    /**
     * Reserved for v2. v1 returns []; the option still stores the
     * `themes` key so future versions don't need a migration step
     * to introduce it.
     *
     * @param  array<string, mixed> $overrides
     * @return array<string, array<string, mixed>>
     */
    private function sanitizeThemeOverrides(array $overrides): array
    {
        return [];
    }
}
