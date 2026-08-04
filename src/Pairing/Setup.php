<?php

namespace DeckWP\Connect\Pairing;

defined('ABSPATH') || exit;

use DeckWP\Connect\Heartbeat\Scheduler as HeartbeatScheduler;
use DeckWP\Connect\Scan\Scheduler as ScanScheduler;
use DeckWP\Connect\Storage\Settings;

/**
 * The one way a connection begins on this site — the mirror of
 * {@see Teardown}.
 *
 * {@see Handler::pair()} stores the credentials the dashboard just
 * issued and returns. That used to be the whole handshake, and it left
 * two things undone.
 *
 * ## 1. Nothing was scheduled
 *
 * Both cron events are installed on `init` by their schedulers'
 * `maybeSchedule()`, which is the right place for the steady state but
 * a poor one for the moment of pairing: the operator has just clicked
 * Connect and is looking at a dashboard that stays empty until some
 * later request happens to run `init` while paired. Scheduling here
 * makes the connection live at the instant it is made, and mirrors
 * teardown, which clears the same two events.
 *
 * ## 2. Nothing was sent
 *
 * The dashboard learns a site's plugins and themes from the heartbeat.
 * With the first tick a minute out (and wp-cron only firing on a real
 * visitor), a freshly paired site showed zero plugins on the dashboard
 * until the operator found the Refresh button — the first thing they
 * see after connecting is a site that looks broken. So we push one
 * heartbeat immediately, synchronously, while we still hold the
 * request.
 *
 * A failed first push is deliberately not an error. Pairing already
 * succeeded — the dashboard has the site, the site has the secret —
 * and the cron will carry the inventory over on its next tick. Failing
 * the handshake over it would discard a working pairing to report a
 * transient network problem.
 */
class Setup
{
    /** @var Settings */
    private $settings;

    /** @var HeartbeatScheduler */
    private $heartbeat;

    /** @var ScanScheduler */
    private $scan;

    public function __construct(
        ?Settings $settings = null,
        ?HeartbeatScheduler $heartbeat = null,
        ?ScanScheduler $scan = null
    ) {
        $this->settings  = $settings ?? new Settings();
        $this->heartbeat = $heartbeat ?? new HeartbeatScheduler();
        $this->scan      = $scan ?? new ScanScheduler();
    }

    /**
     * Bring a just-paired site fully online.
     *
     * @return array{scheduled: bool, heartbeat_sent: bool, heartbeat_error: string|null}
     */
    public function run(): array
    {
        if (! $this->settings->isPaired()) {
            // Called out of order, or the settings write failed. Either
            // way both steps below would be no-ops that log noise.
            return [
                'scheduled'       => false,
                'heartbeat_sent'  => false,
                'heartbeat_error' => 'Connector is not paired.',
            ];
        }

        // Both schedulers gate on their own opt-out constant and on
        // isPaired(), so this respects a site that has turned either
        // subsystem off.
        $this->heartbeat->maybeSchedule();
        $this->scan->maybeSchedule();

        $result = $this->heartbeat->sendNow();
        $sent   = (bool) $result['ok'];

        return [
            'scheduled'       => true,
            'heartbeat_sent'  => $sent,
            // sendNow() already logs both outcomes; we return the
            // reason so the settings page can mention it next to the
            // success notice rather than leaving a silent gap.
            'heartbeat_error' => $sent ? null : $this->reasonFrom($result),
        ];
    }

    /**
     * @param  array{ok: bool, status: int, body: array|null, raw: string, error: string|null}  $result
     */
    private function reasonFrom(array $result): string
    {
        $error = isset($result['error']) ? (string) $result['error'] : '';
        if ($error !== '') {
            return $error;
        }

        $status = isset($result['status']) ? (int) $result['status'] : 0;

        return $status > 0
            ? sprintf('Dashboard answered HTTP %d.', $status)
            : 'Unknown error.';
    }
}
