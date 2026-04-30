<?php

namespace DeckWP\Connect\REST\Auth;

defined('ABSPATH') || exit;

use WP_REST_Request;

/**
 * Verifies HMAC signatures on inbound requests from the DeckWP dashboard.
 *
 * Counterpart of {@see App\Services\Hmac\HmacSigner} in deckwp-app.
 *
 * ## Wire format
 *
 * Canonical string (input to HMAC):
 *
 *     {timestamp}.{nonce}.{METHOD}.{path}.{sha256(body)}
 *
 * Where:
 *   - timestamp   integer unix epoch, header X-DeckWP-Timestamp
 *   - nonce       16-32 bytes hex, header X-DeckWP-Nonce
 *   - METHOD      HTTP verb in upper case (POST, GET, etc.)
 *   - path        request path WITHOUT query string, INCLUDING the
 *                 /wp-json/ prefix and any subdirectory ("/blog/wp-json/...")
 *   - sha256(body) hex digest of the raw request body, even when empty
 *
 * Signature: hash_hmac('sha256', canonical, hmac_secret) — hex.
 *
 * ## Security properties
 *
 * - **Anti-replay window:** {@see self::TIMESTAMP_WINDOW}-second tolerance
 *   between header timestamp and server clock.
 * - **Method/path lock:** prevents replay-to-different-endpoint attacks.
 *   An intercepted signed `GET /info` cannot be rerouted to
 *   `POST /plugin/delete` even within the window.
 * - **Body hash:** keeps signature input bounded for large payloads
 *   (multipart uploads, install bundles, scan reports).
 * - **Constant-time compare:** {@see hash_equals()} guards against
 *   timing oracles.
 *
 * ## Limitations (Sprint 1)
 *
 * Nonce uniqueness is NOT enforced yet — a request signed in second N can
 * be replayed within the 60s window. Tracking seen nonces in a transient
 * store is planned for the G1 hardening pass.
 */
class HmacVerifier
{
    /**
     * Anti-replay window in seconds.
     *
     * Mirrored verbatim in {@see App\Services\Hmac\HmacSigner::TIMESTAMP_WINDOW}.
     * Keep them in sync.
     */
    public const TIMESTAMP_WINDOW = 60;

    /** WordPress option key for plugin settings. */
    private const SETTINGS_OPTION = 'deckwp_connect_settings';

    /**
     * Permission callback for register_rest_route().
     *
     * Reads everything it needs from the WP_REST_Request plus $_SERVER
     * for the request path (which the request object doesn't expose
     * directly — it normalizes to a route relative to /wp-json/).
     */
    public function verify(WP_REST_Request $request): bool
    {
        $signature = (string) ($request->get_header('x_deckwp_signature') ?: '');
        $nonce     = (string) ($request->get_header('x_deckwp_nonce') ?: '');
        $timestamp = (string) ($request->get_header('x_deckwp_timestamp') ?: '');
        $method    = (string) ($request->get_method() ?: '');
        $path      = $this->extractPath();
        $body      = (string) $request->get_body();

        return $this->validate($signature, $nonce, $timestamp, $method, $path, $body);
    }

    /**
     * Verification path for the init-hook fallback transport — used when
     * /wp-json is blocked by hosting/security plugins. Reads everything
     * directly from $_SERVER and php://input.
     */
    public function verifyFromGlobals(): bool
    {
        $signature = isset($_SERVER['HTTP_X_DECKWP_SIGNATURE']) ? (string) $_SERVER['HTTP_X_DECKWP_SIGNATURE'] : '';
        $nonce     = isset($_SERVER['HTTP_X_DECKWP_NONCE'])     ? (string) $_SERVER['HTTP_X_DECKWP_NONCE']     : '';
        $timestamp = isset($_SERVER['HTTP_X_DECKWP_TIMESTAMP']) ? (string) $_SERVER['HTTP_X_DECKWP_TIMESTAMP'] : '';
        $method    = isset($_SERVER['REQUEST_METHOD'])           ? (string) $_SERVER['REQUEST_METHOD']           : '';
        $path      = $this->extractPath();
        $body      = (string) (file_get_contents('php://input') ?: '');

        return $this->validate($signature, $nonce, $timestamp, $method, $path, $body);
    }

    /**
     * Pull the request path (without query string) from $_SERVER.
     *
     * Returns '' if anything is off so {@see validate()} can reject
     * cleanly via its empty-method-or-path check.
     */
    private function extractPath(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($uri === '') {
            return '';
        }
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    /**
     * Core validation. Returns true only if EVERY check passes.
     *
     * Order matters: cheap rejects come first so attackers can't use
     * timing on the expensive HMAC compare to learn anything about
     * earlier checks.
     */
    private function validate(
        string $signature,
        string $nonce,
        string $timestamp,
        string $method,
        string $path,
        string $body
    ): bool {
        // 1. All required headers must be present.
        if ($signature === '' || $nonce === '' || $timestamp === '') {
            return false;
        }

        // 2. Method and path must be present (request didn't reach us
        //    through some weird transport that stripped them).
        if ($method === '' || $path === '') {
            return false;
        }

        // 3. Timestamp must be a positive integer.
        if (!ctype_digit($timestamp)) {
            return false;
        }
        $ts = (int) $timestamp;

        // 4. Timestamp must be within the anti-replay window.
        if (abs(time() - $ts) > self::TIMESTAMP_WINDOW) {
            return false;
        }

        // 5. Nonce must be 16-32 bytes of hex (32-64 hex chars).
        if (!preg_match('/^[a-f0-9]{16,64}$/i', $nonce)) {
            return false;
        }

        // 6. Settings must contain an hmac_secret.
        $settings = $this->getSettings();
        if (empty($settings['hmac_secret'])) {
            return false;
        }

        // 7. Constant-time HMAC compare.
        $bodyHash  = hash('sha256', $body);
        $canonical = sprintf(
            '%s.%s.%s.%s.%s',
            $timestamp,
            $nonce,
            strtoupper($method),
            $path,
            $bodyHash
        );
        $expected = hash_hmac('sha256', $canonical, (string) $settings['hmac_secret']);

        return hash_equals($expected, $signature);
    }

    /**
     * Multisite: settings stored in wp_sitemeta.
     * Single-site: wp_options.
     */
    private function getSettings(): array
    {
        if (function_exists('is_multisite') && is_multisite()) {
            return (array) get_site_option(self::SETTINGS_OPTION, []);
        }

        return (array) get_option(self::SETTINGS_OPTION, []);
    }
}
