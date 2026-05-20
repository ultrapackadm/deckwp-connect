<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\SiteHealth\Runner;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route that runs WP's Site Health checks on demand.
 *
 *     POST /wp-json/deckwp/v1/site-health
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *
 *     200 {
 *       "sent_at": 1717684800,
 *       "wp_version": "6.9.4",
 *       "php_version": "8.3.10",
 *       "summary": { "good": 14, "recommended": 3, "critical": 1, "error": 0 },
 *       "checks": [
 *         {
 *           "test": "wordpress_version",
 *           "category": "direct",
 *           "label": "Your version of WordPress is up to date",
 *           "status": "good",
 *           "badge_label": "Performance",
 *           "badge_color": "blue",
 *           "description": "You are currently running...",
 *           "actions": ""
 *         },
 *         ...
 *       ]
 *     }
 *
 * Triggered by the dashboard's "Run health check" button on the
 * `/sites/{site}/health-checks` tab. The HMAC signature is
 * verified by {@see \DeckWP\Connect\REST\Auth\HmacVerifier} as the
 * permission callback before this handler runs.
 *
 * The handler runs synchronously and returns the result envelope
 * inline (same posture as ScanRoute). The dashboard's
 * RemoteSiteHealthTrigger stores a HealthRun row pointing at this
 * envelope; no second-channel notification needed.
 *
 * ## Timeout considerations
 *
 * Site Health includes network-dependent tests (loopback, dotorg
 * communication, REST API availability) that each take 5-10s on
 * slow upstreams. Worst-case sequential cost is ~30-45s. We set
 * `set_time_limit(60)` to give a 60s ceiling; the dashboard's
 * outbound HTTP timeout should match.
 *
 * @see \DeckWP\Connect\SiteHealth\Runner for the actual test
 *      enumeration + invocation logic.
 */
class SiteHealthRoute
{
    /** @var Runner */
    private $runner;

    public function __construct(Runner $runner = null)
    {
        $this->runner = $runner ?? new Runner();
    }

    /**
     * Route registration array. Consumed by
     * {@see \DeckWP\Connect\REST\Server::registerRoutes()}.
     *
     * @param  callable  $permissionCallback
     * @return array<string, mixed>
     */
    public function args(callable $permissionCallback): array
    {
        return [
            'methods'             => 'POST',
            'permission_callback' => $permissionCallback,
            'callback'            => [$this, 'handle'],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // 60s ceiling matched to the dashboard-side timeout. Without
        // this, the host's default 30s execution limit (typical on
        // shared hosting) would cut us off mid-sweep on installs
        // with slow dotorg / REST loopback responses.
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }

        $envelope = $this->runner->run();

        return new WP_REST_Response($envelope, 200);
    }
}
