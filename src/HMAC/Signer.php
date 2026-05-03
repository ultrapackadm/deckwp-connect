<?php

namespace DeckWP\Connect\HMAC;

defined('ABSPATH') || exit;

/**
 * Signs outbound requests from the connector to the DeckWP dashboard.
 *
 * Counterpart of {@see App\Services\Hmac\HmacSigner} in deckwp-app —
 * any drift between the two and signatures stop verifying. The wire
 * format is mirrored verbatim:
 *
 *     canonical = "{timestamp}.{nonce}.{METHOD}.{path}.{sha256(body)}"
 *     signature = hash_hmac('sha256', canonical, raw_secret_bytes)
 *
 * Headers added to the outgoing request:
 *
 *     X-DeckWP-Timestamp   Unix epoch seconds
 *     X-DeckWP-Nonce       16 bytes hex (32 chars)
 *     X-DeckWP-Signature   hex hmac-sha256(canonical, raw secret)
 *
 * ## Secret format gotcha
 *
 * The pairing handshake response delivers `hmac_secret` base64-encoded,
 * and we store it in options exactly as received. **This signer takes
 * the raw decoded bytes** — callers MUST `base64_decode()` the stored
 * string before passing it in. Keeping decoding on the caller side
 * matches the Laravel signer's contract; if both sides drift on
 * encoding we get silent verify failures.
 */
class Signer
{
    /**
     * Anti-replay window seconds — the dashboard's verifier rejects
     * requests whose timestamp drifts more than this from its clock.
     * Mirror of {@see App\Services\Hmac\HmacSigner::TIMESTAMP_WINDOW}.
     */
    public const TIMESTAMP_WINDOW = 60;

    /**
     * Sign a request and return the three headers as an associative array.
     *
     * `$timestamp` and `$nonce` are exposed for tests so they can produce
     * deterministic signatures. Production callers always omit them.
     *
     * @param string      $method   HTTP method, normalized to uppercase.
     * @param string      $path     URL path WITHOUT scheme, host, or query
     *                              (e.g. `/api/v1/sites/abc/events`). Must
     *                              match the path the request is actually
     *                              sent to or verification fails server-side.
     * @param string      $body     Raw request body. Empty string is fine —
     *                              hashed to sha256('').
     * @param string      $secretRaw Decoded HMAC secret bytes (NOT the base64
     *                              string stored in options).
     * @param int|null    $timestamp Override for tests; defaults to time().
     * @param string|null $nonce     Override for tests; defaults to bin2hex(random_bytes(16)).
     * @return array<string, string>
     */
    public function sign(
        string $method,
        string $path,
        string $body,
        string $secretRaw,
        $timestamp = null,
        $nonce = null
    ): array {
        if ($timestamp === null) {
            $timestamp = time();
        }
        if ($nonce === null) {
            $nonce = bin2hex(random_bytes(16));
        }

        $bodyHash = hash('sha256', $body);
        $canonical = sprintf(
            '%d.%s.%s.%s.%s',
            (int) $timestamp,
            $nonce,
            strtoupper($method),
            $path,
            $bodyHash
        );
        $signature = hash_hmac('sha256', $canonical, $secretRaw);

        return [
            'X-DeckWP-Timestamp' => (string) (int) $timestamp,
            'X-DeckWP-Nonce'     => $nonce,
            'X-DeckWP-Signature' => $signature,
        ];
    }
}
