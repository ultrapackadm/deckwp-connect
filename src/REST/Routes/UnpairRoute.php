<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\Pairing\Teardown;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route: the dashboard is ending this pairing.
 *
 *     POST /wp-json/deckwp/v1/unpair
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *
 * Response 200:
 *
 *     { "ok": true, "was_paired": true, "site_id": "01J..." }
 *
 * ## Why this route exists
 *
 * Disconnect used to be one-directional. The connector told the
 * dashboard when it disconnected (`event: disconnect` on the callback
 * URL), but the dashboard clicking Disconnect only revoked its own
 * side: it flipped the site to `revoked` and deleted the credential,
 * and the WordPress install went on holding a `site_id`, an HMAC
 * secret and a callback URL for a dashboard that had already forgotten
 * it. The settings page still read "Connected".
 *
 * The connector did eventually notice — the next heartbeat 401s and
 * {@see \DeckWP\Connect\Heartbeat\Scheduler::handleRevoke()} tears the
 * pairing down. But "eventually" is up to a heartbeat interval away
 * (five minutes by default, longer if WP-Cron only runs on traffic and
 * the site is quiet), and it depends on a scheduled event that a
 * disconnected site has no other reason to keep running. An end-to-end
 * pass caught exactly that window: dashboard said `Revoked`, WordPress
 * said `Connected`, and both were describing the same site.
 *
 * So the dashboard now pushes. The 401 path stays as the fallback for
 * when the push can't land — site down, firewall, connector deactivated
 * — which is the case it was always the right answer for.
 *
 * ## Authentication
 *
 * Standard HMAC, no exception. The secret this request is signed with
 * is one of the things it destroys, which makes the route naturally
 * single-use: replaying a captured unpair against a re-paired site
 * fails the signature check, because re-pairing rotates the secret.
 *
 * ## Idempotence
 *
 * Tearing down an unpaired site is a no-op, but an unpaired site has
 * no secret, so a second call can't authenticate and gets a 401 from
 * the verifier before reaching this handler. `was_paired` therefore
 * reads `true` in practice; it's reported rather than assumed because
 * the alternative is a route that claims to have done something it
 * didn't.
 */
class UnpairRoute
{
    /** @var Teardown */
    private $teardown;

    public function __construct(?Teardown $teardown = null)
    {
        $this->teardown = $teardown ?? new Teardown();
    }

    /**
     * @param  callable  $permissionCallback HMAC verifier, supplied by Server.
     * @return array<string, mixed>
     */
    public function args(callable $permissionCallback): array
    {
        return [
            'methods'             => 'POST',
            'permission_callback' => $permissionCallback,
            'callback'            => [$this, 'handle'],
            'args'                => [],
        ];
    }

    /**
     * Tear down and report what was there.
     *
     * The response is composed from the pre-clear snapshot because by
     * the time we serialise it the settings are already empty.
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->teardown->run('dashboard_unpair');

        return new WP_REST_Response(
            [
                'ok'         => true,
                'was_paired' => $result['was_paired'],
                'site_id'    => $result['site_id'],
            ],
            200
        );
    }
}
