<?php

namespace DeckWP\Connect\REST;

defined('ABSPATH') || exit;

use DeckWP\Connect\REST\Auth\HmacVerifier;
use DeckWP\Connect\REST\Routes\DeleteBackupRoute;
use DeckWP\Connect\REST\Routes\InstallBatchRoute;
use DeckWP\Connect\REST\Routes\InventoryRoute;
use DeckWP\Connect\REST\Routes\RestoreBackupRoute;
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
 * ## Routes (v0.4.0)
 *
 *   - POST /wp-json/deckwp/v1/scan — run a security scan on demand.
 *     Triggered by the dashboard's "Scan now" button.
 *     {@see ScanRoute}.
 *   - POST /wp-json/deckwp/v1/install-batch — install/upgrade a
 *     batch of plugins. wp.org-only in v0.4.0; UltraPack premium
 *     catalog support lands when the dashboard's catalog client
 *     ships. {@see InstallBatchRoute}.
 *   - POST /wp-json/deckwp/v1/restore-backup — restore a previously
 *     captured plugin folder snapshot. Used by the dashboard's
 *     manual Restore button and (Sprint 4 T4) by the auto-rollback
 *     path. {@see RestoreBackupRoute}.
 *   - POST /wp-json/deckwp/v1/delete-backup — delete an expired
 *     snapshot zip from disk. Idempotent. Used by the dashboard's
 *     retention sweeper (Sprint 4 T6). {@see DeleteBackupRoute}.
 *   - POST /wp-json/deckwp/v1/inventory — return a fresh inventory
 *     snapshot on demand (same payload as the cron heartbeat, but
 *     pull-shaped). Powers the dashboard's "Refresh now" button.
 *     {@see InventoryRoute}.
 *
 * ## Planned (Sprint 4+)
 *
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

    /** @var InstallBatchRoute */
    private $installBatchRoute;

    /** @var RestoreBackupRoute */
    private $restoreBackupRoute;

    /** @var DeleteBackupRoute */
    private $deleteBackupRoute;

    /** @var InventoryRoute */
    private $inventoryRoute;

    public function __construct(
        HmacVerifier $verifier = null,
        ScanRoute $scanRoute = null,
        InstallBatchRoute $installBatchRoute = null,
        RestoreBackupRoute $restoreBackupRoute = null,
        DeleteBackupRoute $deleteBackupRoute = null,
        InventoryRoute $inventoryRoute = null
    ) {
        $this->verifier           = $verifier ?? new HmacVerifier();
        $this->scanRoute          = $scanRoute ?? new ScanRoute();
        $this->installBatchRoute  = $installBatchRoute ?? new InstallBatchRoute();
        $this->restoreBackupRoute = $restoreBackupRoute ?? new RestoreBackupRoute();
        $this->deleteBackupRoute  = $deleteBackupRoute ?? new DeleteBackupRoute();
        $this->inventoryRoute     = $inventoryRoute ?? new InventoryRoute();
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

        register_rest_route(
            'deckwp/v1',
            '/install-batch',
            $this->installBatchRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/restore-backup',
            $this->restoreBackupRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/delete-backup',
            $this->deleteBackupRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/inventory',
            $this->inventoryRoute->args($permissionCallback)
        );
    }
}
