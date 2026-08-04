<?php

namespace DeckWP\Connect\Pairing;

defined('ABSPATH') || exit;

use DeckWP\Connect\Heartbeat\Scheduler as HeartbeatScheduler;
use DeckWP\Connect\Scan\Scheduler as ScanScheduler;
use DeckWP\Connect\Storage\Settings;

/**
 * The one way a connection ends on this site.
 *
 * A pairing can be torn down from four directions:
 *
 *   1. Operator clicks Disconnect in WP admin
 *      ({@see \DeckWP\Connect\Settings\Page::handleDisconnectSubmit()}).
 *   2. Operator clicks Disconnect in the dashboard, which POSTs
 *      {@see \DeckWP\Connect\REST\Routes\UnpairRoute} at us.
 *   3. A heartbeat comes back 401 — the dashboard deleted our
 *      credential and never reached us (site was down, firewalled,
 *      mid-deploy), so the 401 is how we find out.
 *   4. A scan push comes back 401, same reasoning.
 *
 * Before this class, (3) and (4) each carried their own copy of the
 * teardown — one of them said so in a comment ("we duplicate the small
 * amount of revoke logic here for independence") — and (1) had a third,
 * shorter copy that cleared the settings and left the cron events
 * queued. Independence is not the property you want here: a connection
 * that ends four ways should leave the site in one state. Every caller
 * now funnels through {@see self::run()}.
 *
 * ## What teardown means
 *
 *   - Connection keys are emptied ({@see Settings::clearConnection()}).
 *     The locally-generated `token` survives so re-pairing the same
 *     install doesn't churn it.
 *   - Both cron events are unscheduled. `maybeSchedule()` won't
 *     re-queue them while unpaired, but an already-queued event
 *     outlives the clear and fires against an empty secret every five
 *     minutes, writing a failure line to the log each time. Clearing
 *     the settings without clearing the schedule is how you get a
 *     disconnected site that keeps talking.
 *   - A notice is stashed for the next admin page load, so the operator
 *     who finds the settings page back on the connect form learns why.
 *     Suppressed when the operator did it themselves — they don't need
 *     to be told what they just clicked.
 *
 * ## What teardown does NOT do
 *
 * It does not deactivate or delete the plugin, drop the whitelabel
 * branding, remove the fatal-error drop-in, or touch the managed-slugs
 * list. Unpairing is "stop talking to that dashboard", not "uninstall".
 * A site that re-pairs to the same dashboard an hour later should find
 * its local configuration where it left it.
 */
class Teardown
{
    /**
     * Transient the settings page reads (and deletes) to render the
     * "the dashboard revoked this connection" banner exactly once.
     */
    public const NOTICE_KEY = 'deckwp_connect_revoke_notice';

    /** @var Settings */
    private $settings;

    public function __construct(?Settings $settings = null)
    {
        $this->settings = $settings ?? new Settings();
    }

    /**
     * Clear every trace of the current pairing.
     *
     * Idempotent — tearing down an unpaired site is a no-op that
     * still reports honestly via `was_paired`, so callers can tell
     * "we disconnected you" from "there was nothing to disconnect".
     *
     * @param  string  $reason     Short machine-ish tag for the log line
     *                             (e.g. `heartbeat_401`, `dashboard_unpair`).
     * @param  bool    $notify     Stash the admin banner. False when the
     *                             operator initiated the disconnect from
     *                             this very WP admin.
     * @return array{was_paired: bool, platform_url: string, site_id: string}
     */
    public function run(string $reason, bool $notify = true): array
    {
        // Read before the clear — the notice links back to the
        // dashboard that dropped us, and the caller may want to echo
        // the site_id it was paired as.
        $wasPaired   = $this->settings->isPaired();
        $platformUrl = (string) $this->settings->get('platform_url', '');
        $siteId      = (string) $this->settings->get('site_id', '');

        $this->settings->clearConnection();
        $this->clearSchedules();

        if ($notify && $wasPaired) {
            $this->stashNotice($platformUrl);
        }

        if (function_exists('error_log')) {
            error_log(sprintf(
                '[deckwp-connect] connection torn down (%s) — local state cleared%s',
                $reason,
                $wasPaired ? '' : ' (was not paired)'
            ));
        }

        return [
            'was_paired'   => $wasPaired,
            'platform_url' => $platformUrl,
            'site_id'      => $siteId,
        ];
    }

    /**
     * Drop the heartbeat and scan cron events.
     *
     * `wp_clear_scheduled_hook()` removes every queued occurrence of
     * the hook regardless of args, which is what we want — there is
     * one of each and no argument variants.
     */
    private function clearSchedules(): void
    {
        if (! function_exists('wp_clear_scheduled_hook')) {
            return;
        }

        wp_clear_scheduled_hook(HeartbeatScheduler::HOOK);
        wp_clear_scheduled_hook(ScanScheduler::HOOK);
    }

    /**
     * Stash the one-shot admin banner.
     *
     * A day is long enough that an operator who disconnects on Friday
     * still sees the explanation on Monday, and short enough that a
     * banner about a pairing from last month doesn't resurface.
     */
    private function stashNotice(string $platformUrl): void
    {
        if (! function_exists('set_transient')) {
            return;
        }

        $ttl = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

        set_transient(self::NOTICE_KEY, [
            'platform_url' => $platformUrl,
            'revoked_at'   => time(),
        ], $ttl);
    }
}
