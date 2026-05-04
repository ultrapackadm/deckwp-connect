<?php

namespace DeckWP\Connect\Pairing;

defined('ABSPATH') || exit;

use DeckWP\Connect\HMAC\Signer;
use DeckWP\Connect\HTTP\ApiClient;
use DeckWP\Connect\Storage\Settings;

/**
 * Performs the initial pairing handshake against the DeckWP dashboard.
 *
 * ## Flow
 *
 * 1. User pastes a pairing token (issued by the dashboard's AddSiteForm)
 *    plus an optional platform URL into the connector's settings page.
 * 2. {@see Page::handleConnectSubmit()} calls {@see self::pair()}.
 * 3. We POST `{platform}/api/v1/connect/pair` with the token in the
 *    `X-DeckWP-Pairing-Token` header and a JSON body of WP metadata.
 * 4. On 200 the dashboard returns the durable `hmac_secret` (base64),
 *    `site_id`, `team_slug`, `callback_url`, and the heartbeat/scan
 *    intervals. We persist them via {@see Settings::update()} and
 *    return a success result for the UI to render.
 * 5. On any non-2xx the response envelope's `error` is surfaced to the
 *    operator unchanged.
 *
 * ## Result shape
 *
 *     [
 *         'ok'      => bool,
 *         'message' => string,   // human-readable, safe to render
 *         'site_id' => string,   // populated only on success
 *     ]
 *
 * The dashboard's `/api/v1/connect/pair` endpoint handles all the
 * cryptographic / domain logic — token validation, secret rotation,
 * `SiteWasPaired` event, etc. The connector just has to faithfully
 * relay the state into local options.
 */
class Handler
{
    private const PAIR_PATH = '/api/v1/connect/pair';

    /**
     * Default platform URL if the operator didn't override it. Production
     * customers always pair against deckwp.com; the override exists for
     * staging / self-hosted dashboards and local dev (deckwp-app.test).
     */
    private const DEFAULT_PLATFORM = 'https://deckwp.com';

    /** @var ApiClient */
    private $http;

    /** @var Settings */
    private $settings;

    /** @var Signer */
    private $signer;

    public function __construct(
        ApiClient $http = null,
        Settings $settings = null,
        Signer $signer = null
    ) {
        $this->http     = $http ?? new ApiClient();
        $this->settings = $settings ?? new Settings();
        $this->signer   = $signer ?? new Signer();
    }

    /**
     * Run the handshake. Empty-string token → user error, propagated
     * to the UI without hitting the network.
     *
     * @return array{ok: bool, message: string, site_id: string}
     */
    public function pair(string $token, string $platformUrl = ''): array
    {
        $token = trim($token);
        if ($token === '') {
            return $this->failure('Paste the pairing token from your DeckWP dashboard before clicking Connect.');
        }

        $platformUrl = $this->normalizePlatformUrl($platformUrl);
        $url = $platformUrl . self::PAIR_PATH;

        $response = $this->http->postJson(
            $url,
            $this->collectMetadata(),
            ['X-DeckWP-Pairing-Token' => $token]
        );

        if (! $response['ok']) {
            return $this->failure((string) ($response['error'] ?? 'Connection failed.'));
        }

        $body = is_array($response['body']) ? $response['body'] : [];
        $required = ['site_id', 'hmac_secret', 'team_slug'];
        foreach ($required as $key) {
            if (empty($body[$key])) {
                return $this->failure(sprintf(
                    'Dashboard responded 200 but the response is missing "%s". Try again — if it persists, contact support.',
                    $key
                ));
            }
        }

        $intervals = isset($body['intervals']) && is_array($body['intervals']) ? $body['intervals'] : [];

        $this->settings->update([
            'site_id'           => (string) $body['site_id'],
            'hmac_secret'       => (string) $body['hmac_secret'],
            'team_slug'         => (string) $body['team_slug'],
            'platform_url'      => $platformUrl,
            'callback_url'      => (string) ($body['callback_url'] ?? ''),
            'heartbeat_seconds' => (int) ($intervals['heartbeat_seconds'] ?? 300),
            'scan_seconds'      => (int) ($intervals['scan_seconds'] ?? 86400),
            'connected_at'      => (string) time(),
        ]);

        return [
            'ok'      => true,
            'message' => sprintf('Connected to DeckWP. Site UUID %s.', (string) $body['site_id']),
            'site_id' => (string) $body['site_id'],
        ];
    }

    /**
     * Notify the dashboard that the operator clicked Disconnect, so it
     * can flip the site to `revoked` instead of letting the row sit at
     * "Paired" with stale `last_seen_at`.
     *
     * Sent as a normal HMAC-signed event to the same callback URL the
     * heartbeat uses — the dashboard's `EventsController` discriminates
     * by the `event` field, so we don't need a new endpoint. If the
     * dashboard hasn't shipped the `disconnect` handler yet, the event
     * comes back 200 + `ignored` (forward-compat path) and the
     * connector still treats the disconnect as locally complete; the
     * dashboard's row will go stale on its own.
     *
     * Failure semantics: this is best-effort. The caller
     * ({@see \DeckWP\Connect\Settings\Page::handleDisconnectSubmit()})
     * runs `Settings::clearConnection()` regardless of the result —
     * the user wants out, and a transport error shouldn't block them.
     * The result envelope just feeds the admin notice so the operator
     * knows whether the dashboard was actually informed.
     *
     * @return array{ok: bool, message: string, status: int}
     */
    public function disconnect(): array
    {
        if (! $this->settings->isPaired()) {
            return $this->disconnectResult(false, 'Site is not paired with a dashboard.', 0);
        }

        $callbackUrl = (string) $this->settings->get('callback_url', '');
        if ($callbackUrl === '') {
            return $this->disconnectResult(false, 'No callback_url stored — re-pair the site to get one.', 0);
        }

        $secretBase64 = (string) $this->settings->get('hmac_secret', '');
        $secretRaw = base64_decode($secretBase64, true);
        if ($secretRaw === false || $secretRaw === '') {
            return $this->disconnectResult(false, 'hmac_secret is missing or not valid base64.', 0);
        }

        $payload = [
            'event'   => 'disconnect',
            'sent_at' => time(),
        ];
        $body = wp_json_encode($payload);
        if ($body === false) {
            return $this->disconnectResult(false, 'Failed to encode disconnect payload as JSON.', 0);
        }

        $path = parse_url($callbackUrl, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return $this->disconnectResult(false, 'callback_url has no path component.', 0);
        }

        $headers = $this->signer->sign('POST', $path, $body, $secretRaw);
        $response = $this->http->postBody($callbackUrl, $body, $headers);

        if (! $response['ok']) {
            return $this->disconnectResult(
                false,
                (string) ($response['error'] ?? 'Unknown error contacting the dashboard.'),
                (int) $response['status']
            );
        }

        // EventsController returns `{"status":"accepted"|"ignored", ...}`.
        // `ignored` means the dashboard reached us, validated HMAC, and
        // returned 200 — but doesn't have a handler for `event=disconnect`
        // yet (it will land in a later release). From the operator's
        // perspective, the local disconnect still succeeds, but the
        // dashboard row will sit at `paired` with stale `last_seen_at`
        // until then. Surface this honestly instead of claiming the
        // dashboard "was notified" when functionally it wasn't.
        $remoteStatus = '';
        if (is_array($response['body']) && isset($response['body']['status'])) {
            $remoteStatus = (string) $response['body']['status'];
        }

        if ($remoteStatus === 'ignored') {
            return $this->disconnectResult(
                false,
                'Dashboard accepted the request but does not yet process disconnect events; the site will continue to show as paired until its last_seen_at goes stale.',
                (int) $response['status']
            );
        }

        return $this->disconnectResult(
            true,
            'Dashboard notified of disconnect.',
            (int) $response['status']
        );
    }

    /**
     * Build the JSON body for the pair POST. Mirrors the shape that
     * `App\Http\Requests\Api\V1\PairRequest` validates against.
     *
     * @return array<string, mixed>
     */
    private function collectMetadata(): array
    {
        global $wp_version;

        $siteUrl = (string) get_site_url();
        $restRoot = (string) get_rest_url();

        return [
            'site_url'                => $siteUrl,
            'rest_root'               => $restRoot,
            'wp_version'              => (string) ($wp_version ?? 'unknown'),
            'php_version'             => PHP_VERSION,
            'is_multisite'            => function_exists('is_multisite') && is_multisite(),
            'plugin_version'          => defined('DECKWP_CONNECT_VERSION') ? DECKWP_CONNECT_VERSION : 'dev',
            'connector_capabilities'  => $this->capabilities(),
        ];
    }

    /**
     * Capabilities the connector advertises in v0.1.0. Each flag gates a
     * dashboard-side feature; missing flags signal "this connector can't
     * do that, route around it".
     *
     * @return array<int, string>
     */
    private function capabilities(): array
    {
        // v0.1.0 ships the pairing handshake only — no bulk update,
        // no fatal handler, no scan. Future versions append flags here
        // as subsystems land. Keep alphabetized.
        return [];
    }

    /**
     * Lock down the URL the operator typed: trim trailing slash, fall
     * back to production default, refuse anything that's not http(s).
     */
    private function normalizePlatformUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return self::DEFAULT_PLATFORM;
        }

        $raw = rtrim($raw, '/');
        if (! preg_match('~^https?://~i', $raw)) {
            return self::DEFAULT_PLATFORM;
        }

        return $raw;
    }

    /**
     * @return array{ok: bool, message: string, site_id: string}
     */
    private function failure(string $message): array
    {
        return [
            'ok'      => false,
            'message' => $message,
            'site_id' => '',
        ];
    }

    /**
     * @return array{ok: bool, message: string, status: int}
     */
    private function disconnectResult(bool $ok, string $message, int $status): array
    {
        return [
            'ok'      => $ok,
            'message' => $message,
            'status'  => $status,
        ];
    }
}
