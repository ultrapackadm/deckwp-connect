<?php

namespace DeckWP\Connect\Scan;

defined('ABSPATH') || exit;

use DeckWP\Connect\HMAC\Signer;
use DeckWP\Connect\HTTP\ApiClient;
use DeckWP\Connect\Storage\Settings;

/**
 * WP-Cron scheduler + outbound sender for `scan_completed` events.
 *
 * Mirror of {@see \DeckWP\Connect\Heartbeat\Scheduler} but for scans.
 * Cron-driven scans push results to the dashboard via the same
 * `/api/v1/sites/{site}/events` endpoint used for heartbeats — the
 * `event` field on the body discriminates server-side. Manual scans
 * triggered by the dashboard's "Scan now" button take a different
 * path: they hit our inbound `/wp-json/deckwp/v1/scan` REST endpoint
 * and get the result inline in the response, not via an event push
 * (so the dashboard's outbound HTTP request can persist the row in
 * a single shot without polling).
 *
 * ## Cron registration
 *
 * Hook name: `deckwp_connect_scan`. Schedule:
 * `deckwp_connect_scan_interval`, registered via `cron_schedules`
 * filter at the value of `scan_seconds` from the settings option
 * (default 86400 = daily, server-issued during pair).
 *
 * Schedule is installed on `init` whenever the connector is paired
 * AND the constant `DECKWP_CONNECT_ENABLE_SCAN` is `true`. Default
 * disabled — flip the constant once the dashboard's
 * `scan_completed` ingest is live.
 *
 *     define( 'DECKWP_CONNECT_ENABLE_SCAN', true );
 *
 * ## 401 self-cleanup
 *
 * On a 401 from the dashboard the scheduler delegates to the same
 * revoke flow the heartbeat uses (clears local state, stashes the
 * revoke notice transient). Without this the connector would keep
 * pushing scan results into the void after a dashboard-initiated
 * disconnect. See {@see \DeckWP\Connect\Heartbeat\Scheduler::handleRevoke()}
 * for the full rationale.
 *
 * ## Manual trigger via REST
 *
 * The {@see \DeckWP\Connect\REST\Routes\ScanRoute} handler calls
 * {@see self::runScan()} synchronously, returns the result inline,
 * and skips the event-push code path entirely. Same Scanner, same
 * payload shape — only the delivery channel differs.
 */
class Scheduler
{
    /** Cron event hook name. */
    public const HOOK = 'deckwp_connect_scan';

    /** Custom schedule registered via the `cron_schedules` filter. */
    public const SCHEDULE = 'deckwp_connect_scan_interval';

    /** Fallback interval when settings haven't been populated yet. */
    private const DEFAULT_INTERVAL = 86400; // 24h

    /** @var Settings */
    private $settings;

    /** @var Signer */
    private $signer;

    /** @var ApiClient */
    private $http;

    /** @var Scanner */
    private $scanner;

    public function __construct(
        Settings $settings = null,
        Signer $signer = null,
        ApiClient $http = null,
        Scanner $scanner = null
    ) {
        $this->settings = $settings ?? new Settings();
        $this->signer   = $signer ?? new Signer();
        $this->http     = $http ?? new ApiClient();
        $this->scanner  = $scanner ?? new Scanner();
    }

    /**
     * Wire up hooks. Called once from {@see \DeckWP\Connect\Bootstrap}.
     */
    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'registerInterval']);
        add_action('init', [$this, 'maybeSchedule']);
        add_action(self::HOOK, [$this, 'sendScheduled']);
    }

    /**
     * Register the custom cron schedule. Idempotent — WP calls this
     * filter on every `cron_schedules` evaluation.
     *
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public function registerInterval(array $schedules): array
    {
        $interval = (int) $this->settings->get('scan_seconds', self::DEFAULT_INTERVAL);
        if ($interval < 3600) {
            // Never let runaway settings push us into a sub-hourly
            // scan loop — these are I/O-heavy and not heartbeats.
            $interval = self::DEFAULT_INTERVAL;
        }

        $schedules[self::SCHEDULE] = [
            'interval' => $interval,
            'display'  => __('DeckWP Connect scan', 'deckwp-connect'),
        ];

        return $schedules;
    }

    /**
     * Schedule the cron event when:
     *   1. Connector is paired (we have a callback_url + secret).
     *   2. The DECKWP_CONNECT_ENABLE_SCAN constant is truthy.
     *   3. No event of this type is already queued.
     *
     * Mirrors the heartbeat scheduler's gating logic.
     */
    public function maybeSchedule(): void
    {
        if (! $this->isScanEnabled()) {
            // Operator turned the flag off — clear any stale entry.
            if (wp_next_scheduled(self::HOOK)) {
                wp_clear_scheduled_hook(self::HOOK);
            }

            return;
        }

        if (! $this->settings->isPaired()) {
            return;
        }

        if (! wp_next_scheduled(self::HOOK)) {
            // Stagger first run by 5 minutes so a fresh pair doesn't
            // hammer the dashboard with both heartbeat AND scan in
            // the same tick.
            wp_schedule_event(time() + 300, self::SCHEDULE, self::HOOK);
        }
    }

    /**
     * Cron handler: run the scan, push results, swallow failures.
     */
    public function sendScheduled(): void
    {
        $result = $this->sendNow('scheduled');
        if (! $result['ok']) {
            $this->logFailure($result);
        }
    }

    /**
     * Run the scan locally. Returns the raw scan result envelope —
     * does NOT push to the dashboard. Used by the REST endpoint
     * which delivers the result inline in its HTTP response.
     *
     * @return array<string, mixed>
     */
    public function runScan(): array
    {
        return $this->scanner->scan();
    }

    /**
     * Run a scan and ship the results to the dashboard via the
     * shared events endpoint. Returns the API call result envelope
     * so callers (cron, dev-trigger) can render success/failure.
     *
     * @return array{ok: bool, status: int, body: array|null, raw: string, error: string|null}
     */
    public function sendNow(string $trigger = 'manual'): array
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

        $scanResult = $this->scanner->scan();

        $payload = [
            'event'   => 'scan_completed',
            'sent_at' => time(),
            'trigger' => $trigger,
            'result'  => $scanResult,
        ];
        $body = wp_json_encode($payload);
        if ($body === false) {
            return $this->failure('Failed to encode scan payload as JSON.');
        }

        $path = parse_url($callbackUrl, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return $this->failure('callback_url has no path component.');
        }

        $signature = $this->signer->sign('POST', $path, $body, $secretRaw);

        $result = $this->http->postBody($callbackUrl, $body, $signature);

        // Same 401 self-cleanup the heartbeat scheduler does. A 401
        // here means the dashboard revoked us — clear local state so
        // the WP admin catches up to the unpaired view, just like
        // the heartbeat path. Calling into the heartbeat scheduler's
        // helper would couple the two unnecessarily; we duplicate
        // the small amount of revoke logic here for independence.
        if ((int) ($result['status'] ?? 0) === 401) {
            $this->handleRevoke();
            $result['error'] = 'Dashboard revoked this connection — local state has been cleared. Re-pair from the dashboard if you want to reconnect.';
        }

        if ($result['ok']) {
            $this->logSuccess($result, (int) ($scanResult['findings_count'] ?? 0));
        } else {
            $this->logFailure($result);
        }

        return $result;
    }

    /**
     * Mirror of {@see \DeckWP\Connect\Heartbeat\Scheduler::handleRevoke()}
     * so a 401 on a scan push has the same operator-facing
     * outcome as a 401 on a heartbeat push.
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
            error_log('[deckwp-connect] connection revoked by dashboard (scan returned 401) — local state cleared');
        }
    }

    private function isScanEnabled(): bool
    {
        return defined('DECKWP_CONNECT_ENABLE_SCAN') && DECKWP_CONNECT_ENABLE_SCAN;
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
            '[deckwp-connect] scan failed (status=%d): %s',
            (int) $result['status'],
            (string) ($result['error'] ?? 'unknown')
        ));
    }

    /**
     * @param array{ok: bool, status: int, body: array|null, raw: string, error: string|null} $result
     */
    private function logSuccess(array $result, int $findingsCount): void
    {
        if (! function_exists('error_log')) {
            return;
        }

        error_log(sprintf(
            '[deckwp-connect] scan ok (status=%d, findings=%d)',
            (int) $result['status'],
            $findingsCount
        ));
    }
}
