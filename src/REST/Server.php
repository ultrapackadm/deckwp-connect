<?php

namespace DeckWP\Connect\REST;

defined('ABSPATH') || exit;

use DeckWP\Connect\REST\Auth\HmacVerifier;
use DeckWP\Connect\REST\Routes\ScanRoute;

/**
 * Registers the connector's inbound REST API surface under the
 * `deckwp/v1` namespace.
 *
 * Every route here is HMAC-protected by {@see HmacVerifier::verify()}
 * acting as the WP `permission_callback`. The verifier reads the
 * X-DeckWP-Timestamp/Nonce/Signature headers, recomputes the
 * canonical against our stored hmac_secret, and constant-time
 * compares — no further auth check is needed inside route handlers.
 *
 * ## Routes (v0.3.0)
 *
 *   - POST /wp-json/deckwp/v1/scan — run a scan on demand. Triggered
 *     by the dashboard's "Scan now" button. {@see ScanRoute}.
 *
 * ## Planned (Sprint 4+)
 *
 *   - POST /wp-json/deckwp/v1/install-batch — install plugins/themes
 *     from a dashboard-provided ZIP set.
 *   - POST /wp-json/deckwp/v1/update-batch — apply updates with
 *     pre-scan + post-scan safety net.
 *   - POST /wp-json/deckwp/v1/plugin-action — activate/deactivate/
 *     delete a single plugin.
 *   - POST /wp-json/deckwp/v1/maintenance — toggle the branded
 *     maintenance page.
 *   - POST /wp-json/deckwp/v1/sso-login — issue a one-shot login URL.
 *   - GET  /wp-json/deckwp/v1/inventory — full plugin/theme/core
 *     inventory snapshot (pull complement to the heartbeat push).
 *
 * Each new route adds a {@see Routes} class + a `register_rest_route`
 * call inside {@see self::registerRoutes()}.
 */
class Server
{
    /** @var HmacVerifier */
    private $verifier;

    /** @var ScanRoute */
    private $scanRoute;

    public function __construct(
        HmacVerifier $verifier = null,
        ScanRoute $scanRoute = null
    ) {
        $this->verifier  = $verifier ?? new HmacVerifier();
        $this->scanRoute = $scanRoute ?? new ScanRoute();
    }

    /**
     * Wire up hooks. Called once from {@see \DeckWP\Connect\Bootstrap}.
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register every route under `deckwp/v1`. WP calls this on
     * `rest_api_init` for every REST request — keep it cheap.
     */
    public function registerRoutes(): void
    {
        $permissionCallback = [$this->verifier, 'verify'];

        register_rest_route(
            'deckwp/v1',
            '/scan',
            $this->scanRoute->args($permissionCallback)
        );
    }
}
