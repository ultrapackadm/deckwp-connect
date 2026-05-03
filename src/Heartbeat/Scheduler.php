<?php

namespace DeckWP\Connect\Heartbeat;

defined('ABSPATH') || exit;

use DeckWP\Connect\HMAC\Signer;
use DeckWP\Connect\HTTP\ApiClient;
use DeckWP\Connect\Inventory\PluginInventory;
use DeckWP\Connect\Storage\Settings;

/**
 * WP-Cron scheduler + sender for connector heartbeats.
 *
 * ## Wire contract
 *
 *     POST {callback_url}
 *     X-DeckWP-Timestamp: ...
 *     X-DeckWP-Nonce:     ...
 *     X-DeckWP-Signature: ...
 *     Content-Type: application/json
 *
 *     {
 *       "event":         "heartbeat",
 *       "sent_at":       1714502400,
 *       "wp_version":    "6.6.2",
 *       "php_version":   "8.3.10",
 *       "site_url":      "https://deckwp-test-wp.test",
 *       "is_multisite":  false,
 *       "plugins": [
 *         {"slug":"akismet","name":"Akismet…","version":"5.3.4","active":true,
 *          "update_available":false,"new_version":null,"plugin_file":"akismet/akismet.php"},
 *         …
 *       ]
 *     }
 *
 * ## Cron registration
 *
 * Hook name: `deckwp_connect_heartbeat`. Schedule name:
 * `deckwp_connect_heartbeat_interval` — registered via the
 * `cron_schedules` filter at the value of `heartbeat_seconds` in the
 * settings option (server-issued during pair, default 300 seconds).
 *
 * Schedule is created on `init` whenever the connector is paired AND
 * the constant `DECKWP_CONNECT_ENABLE_HEARTBEAT` is `true`. Default is
 * disabled so the connector doesn't fire requests against an endpoint
 * the dashboard hasn't shipped yet — flip it once the dashboard's
 * `/api/v1/sites/{id}/events` route is live.
 *
 *     define( 'DECKWP_CONNECT_ENABLE_HEARTBEAT', true );
 *
 * ## Manual trigger
 *
 * The Settings page's "Send heartbeat now" button calls
 * {@see self::sendNow()} synchronously. Useful for verifying signature
 * + payload mid-development without waiting for the cron.
 */
class Scheduler
{
    /** Cron event hook name. */
    public const HOOK = 'deckwp_connect_heartbeat';

    /** Custom schedule name registered with `cron_schedules`. */
    public const SCHEDULE = 'deckwp_connect_heartbeat_interval';

    /** Fallback interval when settings haven't been populated yet. */
    private const DEFAULT_INTERVAL = 300;

    /** @var Settings */
    private $settings;

    /** @var Signer */
    private $signer;

    /** @var ApiClient */
    private $http;

    /** @var PluginInventory */
    private $inventory;

    public function __construct(
        Settings $settings = null,
        Signer $signer = null,
        ApiClient $http = null,
        PluginInventory $inventory = null
    ) {
        $this->settings  = $settings ?? new Settings();
        $this->signer    = $signer ?? new Signer();
        $this->http      = $http ?? new ApiClient();
        $this->inventory = $inventory ?? new PluginInventory();
    }

    /**
     * Wire up hooks. Called once from {@see Bootstrap}.
     */
    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'registerInterval']);
        add_action('init', [$this, 'maybeSchedule']);
        add_action(self::HOOK, [$this, 'sendHeartbeat']);
    }

    /**
     * Register the custom cron schedule. WP will call this on every
     * `cron_schedules` filter — keep it idempotent.
     *
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public function registerInterval(array $schedules): array
    {
        $interval = (int) $this->settings->get('heartbeat_seconds', self::DEFAULT_INTERVAL);
        if ($interval < 60) {
            $interval = self::DEFAULT_INTERVAL;
        }

        $schedules[self::SCHEDULE] = [
            'interval' => $interval,
            'display'  => __('DeckWP Connect heartbeat', 'deckwp-connect'),
        ];

        return $schedules;
    }

    /**
     * Schedule the cron event when:
     *   1. Connector is paired (we have a callback_url + secret).
     *   2. The DECKWP_CONNECT_ENABLE_HEARTBEAT constant is truthy.
     *   3. No event of this type is already queued.
     */
    public function maybeSchedule(): void
    {
        if (! $this->isHeartbeatEnabled()) {
            // Make sure we don't keep firing if the operator just turned
            // the flag off — clear any leftover cron entry.
            if (wp_next_scheduled(self::HOOK)) {
                wp_clear_scheduled_hook(self::HOOK);
            }

            return;
        }

        if (! $this->settings->isPaired()) {
            return;
        }

        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, self::SCHEDULE, self::HOOK);
        }
    }

    /**
     * Cron handler: build payload, sign, send. Failures are logged and
     * swallowed — WP-Cron retries naturally on the next interval.
     */
    public function sendHeartbeat(): void
    {
        $result = $this->sendNow();
        if (! $result['ok']) {
            $this->logFailure($result);
        }
    }

    /**
     * Synchronous heartbeat sender. Returns the raw API result envelope
     * so the Settings page's "Send heartbeat now" button can render it
     * back to the operator without going through the log.
     *
     * @return array{ok: bool, status: int, body: array|null, raw: string, error: string|null}
     */
    public function sendNow(): array
    {
        if (! $this->settings->isPaired()) {
            return $this->failure('Connector is not paired with a dashboard.');
        }

        $callbackUrl = (string) $this->settings->get('callback_url', '');
        if ($callbackUrl === '') {
            return $this->failure('No callback_url stored — re-pair the site to get one.');
        }

        $secretBase64 = (string) $this->settings->get('hmac_secret', '');
        $secretRaw = base64_decode($secretBase64, true);
        if ($secretRaw === false || $secretRaw === '') {
            return $this->failure('hmac_secret is missing or not valid base64.');
        }

        $payload = $this->buildPayload();
        $body = wp_json_encode($payload);
        if ($body === false) {
            return $this->failure('Failed to encode heartbeat payload as JSON.');
        }

        $path = parse_url($callbackUrl, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return $this->failure('callback_url has no path component.');
        }

        $signature = $this->signer->sign('POST', $path, $body, $secretRaw);

        return $this->http->postBody($callbackUrl, $body, $signature);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        global $wp_version;

        return [
            'event'        => 'heartbeat',
            'sent_at'      => time(),
            'wp_version'   => isset($wp_version) ? (string) $wp_version : 'unknown',
            'php_version'  => PHP_VERSION,
            'site_url'     => (string) get_site_url(),
            'is_multisite' => function_exists('is_multisite') && is_multisite(),
            'plugins'      => $this->inventory->collect(),
        ];
    }

    private function isHeartbeatEnabled(): bool
    {
        return defined('DECKWP_CONNECT_ENABLE_HEARTBEAT') && DECKWP_CONNECT_ENABLE_HEARTBEAT;
    }

    /**
     * @return array{ok: bool, status: int, body: array|null, raw: string, error: string|null}
     */
    private function failure(string $message): array
    {
        return [
            'ok'     => false,
            'status' => 0,
            'body'   => null,
            'raw'    => '',
            'error'  => $message,
        ];
    }

    /**
     * @param array{ok: bool, status: int, body: array|null, raw: string, error: string|null} $result
     */
    private function logFailure(array $result): void
    {
        if (! function_exists('error_log')) {
            return;
        }

        error_log(sprintf(
            '[deckwp-connect] heartbeat failed (status=%d): %s',
            (int) $result['status'],
            (string) ($result['error'] ?? 'unknown')
        ));
    }
}
