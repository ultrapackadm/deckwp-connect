<?php

namespace DeckWP\Connect\REST\Auth;

defined('ABSPATH') || exit;

use WP_REST_Request;

/**
 * Verifies HMAC signature on requests from the DeckWP dashboard to this connector.
 *
 * Compatible with the matching App\Services\Connector\HmacSigner in deckwp-app.
 *
 * Spec:
 *   canonical = "{timestamp}\n{nonce}\n{body}"
 *   signature = hash_hmac('sha256', canonical, hmac_secret)
 *   headers   = X-DeckWP-Signature, X-DeckWP-Nonce, X-DeckWP-Timestamp
 *   anti-replay window = 60s
 */
class HmacVerifier
{
    /** Anti-replay window in seconds. */
    public const TIMESTAMP_WINDOW = 60;

    /** Settings option key. */
    private const SETTINGS_OPTION = 'deckwp_connect_settings';

    /**
     * Permission callback for register_rest_route().
     */
    public function verify(WP_REST_Request $request): bool
    {
        $signature = $request->get_header('x_deckwp_signature') ?: '';
        $nonce     = $request->get_header('x_deckwp_nonce') ?: '';
        $timestamp = $request->get_header('x_deckwp_timestamp') ?: '';
        $body      = (string) $request->get_body();

        return $this->validate($signature, $nonce, $timestamp, $body);
    }

    /**
     * For non-REST flows (init-hook fallback) where we read directly from
     * $_SERVER + php://input.
     */
    public function verifyFromGlobals(): bool
    {
        $signature = isset($_SERVER['HTTP_X_DECKWP_SIGNATURE']) ? (string) $_SERVER['HTTP_X_DECKWP_SIGNATURE'] : '';
        $nonce     = isset($_SERVER['HTTP_X_DECKWP_NONCE']) ? (string) $_SERVER['HTTP_X_DECKWP_NONCE'] : '';
        $timestamp = isset($_SERVER['HTTP_X_DECKWP_TIMESTAMP']) ? (string) $_SERVER['HTTP_X_DECKWP_TIMESTAMP'] : '';
        $body      = (string) (file_get_contents('php://input') ?: '');

        return $this->validate($signature, $nonce, $timestamp, $body);
    }

    /**
     * Core validation. Returns true only if EVERY check passes.
     */
    private function validate(string $signature, string $nonce, string $timestamp, string $body): bool
    {
        // 1. Required headers present
        if ($signature === '' || $nonce === '' || $timestamp === '') {
            return false;
        }

        // 2. Timestamp must be parseable and within window
        if (!ctype_digit($timestamp)) {
            return false;
        }
        $ts = (int) $timestamp;
        if (abs(time() - $ts) > self::TIMESTAMP_WINDOW) {
            return false;
        }

        // 3. Settings must have hmac_secret
        $settings = $this->getSettings();
        if (empty($settings['hmac_secret'])) {
            return false;
        }

        // 4. Nonce sanity (16-32 bytes hex)
        if (!preg_match('/^[a-f0-9]{16,64}$/i', $nonce)) {
            return false;
        }

        // 5. Constant-time HMAC compare
        $canonical = $timestamp . "\n" . $nonce . "\n" . $body;
        $expected = hash_hmac('sha256', $canonical, (string) $settings['hmac_secret']);

        return hash_equals($expected, $signature);
    }

    /**
     * Multisite: settings stored in wp_sitemeta. Single-site: wp_options.
     */
    private function getSettings(): array
    {
        if (function_exists('is_multisite') && is_multisite()) {
            return (array) get_site_option(self::SETTINGS_OPTION, []);
        }
        return (array) get_option(self::SETTINGS_OPTION, []);
    }
}
