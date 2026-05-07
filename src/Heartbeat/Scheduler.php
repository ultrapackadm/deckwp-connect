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
 *       ],
 *       "fatal_log": [
 *         { "ts": 1717684800, "type": 1, "file": "...", "line": 42,
 *           "message": "Call to undefined function ...",
 *           "plugin_path": "buggy/buggy.php", "deactivated": true,
 *           "scope": "single" },
 *         …
 *       ]
 *     }
 *
 * The `fatal_log` array carries the rolling deckwp_fatal_log option
 * the drop-in writes (Slice 4 of KILLER #1, capped at 50 entries).
 * The dashboard de-duplicates entries by `ts` against its own
 * `last_fatal_seen_ts` watermark — we ship the full log on every
 * heartbeat and let the dashboard decide what's new.
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
     * Logs every outcome (ok or fail) to `error_log`. The ok line is
     * the only durable trace when the operator triggers a heartbeat
     * via the Settings button — flash notices ride a 30s transient
     * that's easy to miss, but the log line stays. Enable WP_DEBUG_LOG
     * in `wp-config.php` to route this to `wp-content/debug.log`.
     *
     * @return array{ok: bool, status: int, body: array|null, raw: string, error: string|null}
     */
    public function sendNow(): array
    {
        if (! $this->settings->isPaired()) {
            $result = $this->failure('Connector is not paired with a dashboard.');
            $this->logFailure($result);

            return $result;
        }

        $callbackUrl = (string) $this->settings->get('callback_url', '');
        if ($callbackUrl === '') {
            $result = $this->failure('No callback_url stored — re-pair the site to get one.');
            $this->logFailure($result);

            return $result;
        }

        $secretBase64 = (string) $this->settings->get('hmac_secret', '');
        $secretRaw = base64_decode($secretBase64, true);
        if ($secretRaw === false || $secretRaw === '') {
            $result = $this->failure('hmac_secret is missing or not valid base64.');
            $this->logFailure($result);

            return $result;
        }

        $payload = $this->buildPayload();
        $body = wp_json_encode($payload);
        if ($body === false) {
            $result = $this->failure('Failed to encode heartbeat payload as JSON.');
            $this->logFailure($result);

            return $result;
        }

        $path = parse_url($callbackUrl, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            $result = $this->failure('callback_url has no path component.');
            $this->logFailure($result);

            return $result;
        }

        $signature = $this->signer->sign('POST', $path, $body, $secretRaw);

        $result = $this->http->postBody($callbackUrl, $body, $signature);

        // Self-cleanup on 401. The dashboard's `VerifyConnectorHmac`
        // middleware returns 401 when there's no credential row to
        // compare against — which is exactly what happens after a
        // dashboard-initiated disconnect (the DisconnectProcessor
        // deletes the credential). Without this branch the connector
        // would keep firing heartbeats forever, getting 401s, and the
        // WP admin would still display "Paired" with credentials that
        // can never authenticate again. Treat any 401 as proof the
        // dashboard revoked us, clear local state, and stash a notice
        // for the next admin page render. This closes the dashboard →
        // connector half of the disconnect lifecycle (the connector →
        // dashboard half landed in v0.2.0 via the `disconnect` event).
        //
        // Edge case: a transient 401 from clock skew >60s would also
        // trigger this. The HmacSigner's timestamp window is 60s and
        // we re-derive `time()` per request, so skew that big is a
        // misconfigured server clock — the operator gets bumped to
        // unpaired and re-pairs in 30s, low cost for a rare event.
        if ((int) ($result['status'] ?? 0) === 401) {
            $this->handleRevoke();
            $result['error'] = 'Dashboard revoked this connection — local state has been cleared. Re-pair from the dashboard if you want to reconnect.';
        }

        if ($result['ok']) {
            $this->logSuccess($result, count((array) ($payload['plugins'] ?? [])));
        } else {
            $this->logFailure($result);
        }

        return $result;
    }

    /**
     * Wipe local connection state and stash a transient notice so the
     * next admin page render can tell the operator what happened.
     *
     * Called when {@see self::sendNow()} sees a 401 from the dashboard,
     * which is the dashboard's signal that this site's credential was
     * deleted (operator clicked Disconnect on the dashboard side, or
     * staff revoked it manually). Captures `platform_url` BEFORE the
     * clear so the notice can render a "Re-pair this site" link back
     * to the right dashboard.
     */
    private function handleRevoke(): void
    {
        $platformUrl = (string) $this->settings->get('platform_url', '');

        $this->settings->clearConnection();

        $ttl = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        set_transient('deckwp_connect_revoke_notice', [
            'platform_url' => $platformUrl,
            'revoked_at'   => time(),
        ], $ttl);

        if (function_exists('error_log')) {
            error_log('[deckwp-connect] connection revoked by dashboard (heartbeat returned 401) — local state cleared');
        }
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
            'fatal_log'    => $this->collectFatalLog(),
        ];
    }

    /**
     * Pull the fatal handler's rolling log for inclusion in the
     * heartbeat. Slice 4 of KILLER #1 stores entries in the
     * `deckwp_fatal_log` site option (capped 50 by the drop-in).
     *
     * The dashboard de-duplicates entries by their `ts` field
     * against its `last_fatal_seen_ts` column, so it's safe for us
     * to ship the entire log on every heartbeat. We deliberately
     * do NOT clear the log on the connector side after sending —
     * the dashboard's watermark IS the dedupe; clearing locally
     * would lose entries on transport failures.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectFatalLog(): array
    {
        // get_site_option falls back to get_option on single-site,
        // matching what the drop-in writes via update_site_option.
        $log = get_site_option('deckwp_fatal_log', []);
        if (! is_array($log)) {
            return [];
        }
        return array_values($log);
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

    /**
     * @param array{ok: bool, status: int, body: array|null, raw: string, error: string|null} $result
     */
    private function logSuccess(array $result, int $pluginCount): void
    {
        if (! function_exists('error_log')) {
            return;
        }

        error_log(sprintf(
            '[deckwp-connect] heartbeat ok (status=%d, plugins=%d)',
            (int) $result['status'],
            $pluginCount
        ));
    }
}
