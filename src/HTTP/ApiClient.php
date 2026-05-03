<?php

namespace DeckWP\Connect\HTTP;

defined('ABSPATH') || exit;

/**
 * Thin wrapper around `wp_remote_*` for outbound calls to the DeckWP
 * dashboard.
 *
 * Why not Guzzle: it would force PHP 8.0+, and CLAUDE.md keeps us on
 * 7.4+ for shared-host compatibility. WP's HTTP API is fine for our
 * needs here — small JSON payloads, no streaming, no fancy retries.
 * (When we eventually need bulk uploads or streaming responses, that's
 * a separate transport class.)
 *
 * ## Result envelope
 *
 * Every method returns the same shape so callers branch on a single
 * `$result['ok']` boolean:
 *
 *     [
 *         'ok'      => bool,
 *         'status'  => int,         // HTTP status, 0 on transport error
 *         'body'    => array|null,  // decoded JSON body, null on parse failure
 *         'raw'     => string,      // raw response body for debugging
 *         'error'   => string|null, // human-readable failure reason
 *     ]
 *
 * ## postJson vs postBody
 *
 * - {@see postJson()} — caller passes an array, we encode. Fine for
 *   unsigned requests like the pair handshake.
 * - {@see postBody()} — caller passes a pre-encoded string. Required
 *   when the body is HMAC-signed: signing must run on the exact byte
 *   sequence that goes over the wire, otherwise re-encoding here would
 *   produce a different sha256 and break verification on the server.
 */
class ApiClient
{
    /**
     * Default request timeout in seconds. DeckWP API endpoints are fast
     * (<1s p99 in practice), but pairing can run during a slow first
     * launch on a low-end shared host — give the network 15s.
     */
    private const DEFAULT_TIMEOUT = 15;

    /**
     * POST an array as JSON. Caller-friendly when the body doesn't need
     * to round-trip through a signer.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, body: array|null, raw: string, error: string|null}
     */
    public function postJson(string $url, array $body, array $headers = []): array
    {
        $payload = wp_json_encode($body);
        if ($payload === false) {
            return $this->failure(0, '', 'Failed to encode request body as JSON.');
        }

        return $this->postBody($url, $payload, $headers);
    }

    /**
     * POST a pre-encoded body string. Used by signed requests
     * (heartbeat, events) where the signer hashed the exact bytes we're
     * about to send — re-encoding would diverge from that hash.
     *
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, body: array|null, raw: string, error: string|null}
     */
    public function postBody(string $url, string $body, array $headers = []): array
    {
        $response = wp_remote_post($url, [
            'timeout'     => self::DEFAULT_TIMEOUT,
            'redirection' => 2,
            'sslverify'   => $this->shouldVerifySsl(),
            'headers'     => array_merge([
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => $this->userAgent(),
            ], $headers),
            'body'        => $body,
        ]);

        if (is_wp_error($response)) {
            return $this->failure(0, '', (string) $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        $decodedBody = is_array($decoded) ? $decoded : null;

        if ($status >= 200 && $status < 300) {
            return [
                'ok'     => true,
                'status' => $status,
                'body'   => $decodedBody,
                'raw'    => $raw,
                'error'  => null,
            ];
        }

        return [
            'ok'     => false,
            'status' => $status,
            'body'   => $decodedBody,
            'raw'    => $raw,
            'error'  => $this->errorFromBody($status, $decodedBody, $raw),
        ];
    }

    /**
     * Build a uniform failure envelope for transport-level errors
     * (DNS, TLS, timeout) where there's no HTTP status to lean on.
     *
     * @return array{ok: bool, status: int, body: array|null, raw: string, error: string|null}
     */
    private function failure(int $status, string $raw, string $error): array
    {
        return [
            'ok'     => false,
            'status' => $status,
            'body'   => null,
            'raw'    => $raw,
            'error'  => $error,
        ];
    }

    /**
     * Try to extract a human-friendly error message from the response.
     * Falls through to a generic message when the server didn't send
     * something we can quote.
     */
    private function errorFromBody(int $status, ?array $body, string $raw): string
    {
        if (is_array($body)) {
            // Laravel-style: {"message": "..."}
            if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
                return $body['message'];
            }
            // Generic-style: {"error": "..."}
            if (isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
                return $body['error'];
            }
        }

        if ($status === 401) {
            return 'Authentication failed. The pairing token may have expired, or the HMAC signature did not verify.';
        }
        if ($status === 422) {
            return 'Server rejected the request body. Check the connector logs for the validation error.';
        }
        if ($status === 429) {
            return 'Rate-limited by the dashboard. Wait a minute and try again.';
        }
        if ($status >= 500) {
            return 'Dashboard returned a server error. Try again in a moment.';
        }

        return sprintf('Request failed with status %d.', $status);
    }

    /**
     * Decide whether to verify the dashboard's TLS certificate.
     *
     * Default is verify-on (production safety). The
     * `DECKWP_CONNECT_SKIP_SSL_VERIFY` constant flips it off — needed
     * when pairing against a Herd-served `*.test` domain or any other
     * dev URL with a self-signed cert. Add to `wp-config.php`:
     *
     *     define( 'DECKWP_CONNECT_SKIP_SSL_VERIFY', true );
     *
     * NEVER set this on production — disabling cert verification opens
     * a clean MITM window for anyone on the network path.
     */
    private function shouldVerifySsl(): bool
    {
        if (defined('DECKWP_CONNECT_SKIP_SSL_VERIFY') && DECKWP_CONNECT_SKIP_SSL_VERIFY) {
            return false;
        }

        return true;
    }

    private function userAgent(): string
    {
        $version = defined('DECKWP_CONNECT_VERSION') ? DECKWP_CONNECT_VERSION : 'dev';
        global $wp_version;

        return sprintf(
            'deckwp-connect/%s WordPress/%s PHP/%s',
            $version,
            (string) ($wp_version ?? 'unknown'),
            PHP_VERSION
        );
    }
}
