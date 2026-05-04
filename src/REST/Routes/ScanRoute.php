<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\Scan\Scheduler as ScanScheduler;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route that runs a scan on demand.
 *
 *     POST /wp-json/deckwp/v1/scan
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *
 *     200 {"scanned_at":..., "duration_ms":..., "findings_count":..., "findings":[...], ...}
 *
 * Triggered by the dashboard's "Scan now" button. The HMAC signature
 * is verified by {@see \DeckWP\Connect\REST\Auth\HmacVerifier} as a
 * permission callback before this handler runs, so by the time we're
 * here the request is trusted as coming from the paired dashboard.
 *
 * The handler runs the scan synchronously and returns the result
 * envelope inline. We deliberately don't push a `scan_completed`
 * event here — the dashboard already has the Scan row in memory
 * (it created it before triggering us) and updates it directly from
 * this response, no second-channel notification needed.
 *
 * Cron-driven scans take a different path: they go through
 * {@see ScanScheduler::sendNow()} which pushes results via the
 * heartbeat-style outbound event. Same Scanner, same payload shape,
 * different delivery channel.
 *
 * ## Timeout considerations
 *
 * The scanner caps at 60 seconds via `set_time_limit(60)`. The
 * dashboard's outbound HTTP timeout for the scan trigger should
 * match (60s+) — anything shorter and the dashboard times out
 * while the connector is still working, leaving the operator with
 * a "scan failed" UI even though the scan completed locally.
 */
class ScanRoute
{
    /** @var ScanScheduler */
    private $scheduler;

    public function __construct(ScanScheduler $scheduler = null)
    {
        $this->scheduler = $scheduler ?? new ScanScheduler();
    }

    /**
     * Route registration array. Consumed by {@see \DeckWP\Connect\REST\Server::registerRoutes()}.
     *
     * @param callable $permissionCallback
     * @return array<string, mixed>
     */
    public function args(callable $permissionCallback): array
    {
        return [
            'methods' => 'POST',
            'permission_callback' => $permissionCallback,
            'callback' => [$this, 'handle'],
        ];
    }

    /**
     * Run the scan and return the envelope.
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->scheduler->runScan();

        return new WP_REST_Response($result, 200);
    }
}
