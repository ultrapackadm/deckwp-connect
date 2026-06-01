<?php

namespace DeckWP\Connect\REST;

defined('ABSPATH') || exit;

use DeckWP\Connect\REST\Auth\HmacVerifier;
use DeckWP\Connect\REST\Routes\BackupCreateRoute;
use DeckWP\Connect\REST\Routes\BootstrapPairingRoute;
use DeckWP\Connect\REST\Routes\DbCleanupRoute;
use DeckWP\Connect\REST\Routes\DbOptimizeTablesRoute;
use DeckWP\Connect\REST\Routes\DbScanRoute;
use DeckWP\Connect\REST\Routes\DeleteBackupRoute;
use DeckWP\Connect\REST\Routes\InstallBatchRoute;
use DeckWP\Connect\REST\Routes\InventoryRoute;
use DeckWP\Connect\REST\Routes\MaintenanceRoute;
use DeckWP\Connect\REST\Routes\PluginToggleRoute;
use DeckWP\Connect\REST\Routes\BackupOffsiteUploadRoute;
use DeckWP\Connect\REST\Routes\RestoreBackupRoute;
use DeckWP\Connect\REST\Routes\SiteHealthRoute;
use DeckWP\Connect\REST\Routes\ThemeDeleteRoute;
use DeckWP\Connect\REST\Routes\ThemeSwitchRoute;
use DeckWP\Connect\REST\Routes\ScanRoute;
use DeckWP\Connect\REST\Routes\SetManagedSlugsRoute;
use DeckWP\Connect\REST\Routes\SsoLoginRoute;
use DeckWP\Connect\REST\Routes\WhitelabelRoute;

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
 *   - GET  /wp-json/deckwp/v1/sso-login — consume a one-time SSO
 *     login token from the URL query and log the operator in as
 *     an administrator. Browser navigation, not HMAC-headers.
 *     {@see SsoLoginRoute}.
 *   - POST /wp-json/deckwp/v1/maintenance — toggle the
 *     dashboard-driven maintenance mode on/off.
 *   - GET  /wp-json/deckwp/v1/maintenance — read current state.
 *     {@see MaintenanceRoute}.
 *   - POST /wp-json/deckwp/v1/backup-create — create a plugin folder
 *     snapshot on demand (off-cycle from install-batch). Powers the
 *     dashboard's manual "Create backup" button. Fires a
 *     `deckwp_connect_backup_created` action hook on success — reserved
 *     for the planned UltraHub off-site upload integration.
 *     {@see BackupCreateRoute}.
 *   - POST /wp-json/deckwp/v1/set-managed-slugs — set the list of
 *     plugins / themes the dashboard is managing. Drives the
 *     UpdateSuppressor's filtering of "Update available" notices in
 *     the WP admin so an operator can't bypass the dashboard's
 *     pre-update backup + smoke flow. {@see SetManagedSlugsRoute}.
 *   - POST /wp-json/deckwp/v1/whitelabel — push the operator's
 *     branding overrides for plugin metadata (Name, Description,
 *     Author, AuthorURI, PluginURI) plus a hide-from-list flag.
 *     The Whitelabel\Branding subsystem reads the resulting option
 *     and rewrites WP's admin Plugins page in real time.
 *     {@see WhitelabelRoute}.
 *   - POST /wp-json/deckwp/v1/plugin-toggle — activate or deactivate
 *     a single plugin. Powers the dashboard's Library "Activate
 *     after install" checkbox + the post-install "Activate now"
 *     button. Idempotent. {@see PluginToggleRoute}.
 *   - POST /wp-json/deckwp/v1/theme-switch — activate (switch_theme
 *     to) a single installed theme. Theme equivalent of
 *     `plugin-toggle` minus the deactivate verb (WP always has
 *     exactly one active theme). Idempotent. {@see ThemeSwitchRoute}.
 *   - POST /wp-json/deckwp/v1/theme-delete — remove an installed
 *     theme from disk via WP's `delete_theme()`. Refuses to delete
 *     the active theme or the parent of an active child theme;
 *     idempotent (deleting an already-gone theme returns
 *     `deleted: false` + clear error). {@see ThemeDeleteRoute}.
 *   - POST /wp-json/deckwp/v1/site-health — run every registered
 *     `WP_Site_Health` check (core + plugin-registered) and return
 *     a flat envelope the dashboard's HealthRun model can store.
 *     {@see SiteHealthRoute}.
 *   - POST /wp-json/deckwp/v1/db-scan — snapshot the install's DB
 *     inventory (table list + sizes + overhead) + the seven well-
 *     known cleanup-target counts (revisions, spam, drafts, trash,
 *     transients, orphan postmeta, pingbacks). Read-only.
 *     {@see DbScanRoute}.
 *   - POST /wp-json/deckwp/v1/db-cleanup — execute the selected
 *     cleanup categories from the dashboard's checkbox list.
 *     {@see DbCleanupRoute}.
 *   - POST /wp-json/deckwp/v1/db-optimize-tables — run
 *     OPTIMIZE TABLE on the requested table list. Defends against
 *     SQL injection via a two-layer allowlist (SHOW TABLES + regex).
 *     {@see DbOptimizeTablesRoute}.
 *   - POST /wp-json/deckwp/v1/bootstrap-pairing — used by the
 *     dashboard's Automatic Pairing flow to push a pairing token
 *     INTO the connector. NOT HMAC-protected (no secret yet by
 *     definition); falls back to WP cookie + nonce + manage_options
 *     auth. Delegates to {@see PairingHandler::pair()} for the
 *     synchronous handshake. {@see BootstrapPairingRoute}.
 *
 * ## Planned
 *
 *   - POST /wp-json/deckwp/v1/plugin-delete — uninstall a plugin
 *     (file removal + uninstall hook).
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

    /** @var BackupOffsiteUploadRoute */
    private $backupOffsiteUploadRoute;

    /** @var DeleteBackupRoute */
    private $deleteBackupRoute;

    /** @var InventoryRoute */
    private $inventoryRoute;

    /** @var SsoLoginRoute */
    private $ssoLoginRoute;

    /** @var MaintenanceRoute */
    private $maintenanceRoute;

    /** @var BackupCreateRoute */
    private $backupCreateRoute;

    /** @var SetManagedSlugsRoute */
    private $setManagedSlugsRoute;

    /** @var WhitelabelRoute */
    private $whitelabelRoute;

    /** @var PluginToggleRoute */
    private $pluginToggleRoute;

    /** @var ThemeSwitchRoute */
    private $themeSwitchRoute;

    /** @var ThemeDeleteRoute */
    private $themeDeleteRoute;

    /** @var SiteHealthRoute */
    private $siteHealthRoute;

    /** @var DbScanRoute */
    private $dbScanRoute;

    /** @var DbCleanupRoute */
    private $dbCleanupRoute;

    /** @var DbOptimizeTablesRoute */
    private $dbOptimizeTablesRoute;

    /** @var BootstrapPairingRoute */
    private $bootstrapPairingRoute;

    public function __construct(
        HmacVerifier $verifier = null,
        ScanRoute $scanRoute = null,
        InstallBatchRoute $installBatchRoute = null,
        RestoreBackupRoute $restoreBackupRoute = null,
        BackupOffsiteUploadRoute $backupOffsiteUploadRoute = null,
        DeleteBackupRoute $deleteBackupRoute = null,
        InventoryRoute $inventoryRoute = null,
        SsoLoginRoute $ssoLoginRoute = null,
        MaintenanceRoute $maintenanceRoute = null,
        BackupCreateRoute $backupCreateRoute = null,
        SetManagedSlugsRoute $setManagedSlugsRoute = null,
        WhitelabelRoute $whitelabelRoute = null,
        PluginToggleRoute $pluginToggleRoute = null,
        ThemeSwitchRoute $themeSwitchRoute = null,
        ThemeDeleteRoute $themeDeleteRoute = null,
        SiteHealthRoute $siteHealthRoute = null,
        DbScanRoute $dbScanRoute = null,
        DbCleanupRoute $dbCleanupRoute = null,
        DbOptimizeTablesRoute $dbOptimizeTablesRoute = null,
        BootstrapPairingRoute $bootstrapPairingRoute = null
    ) {
        $this->verifier              = $verifier ?? new HmacVerifier();
        $this->scanRoute             = $scanRoute ?? new ScanRoute();
        $this->installBatchRoute     = $installBatchRoute ?? new InstallBatchRoute();
        $this->restoreBackupRoute    = $restoreBackupRoute ?? new RestoreBackupRoute();
        $this->backupOffsiteUploadRoute = $backupOffsiteUploadRoute ?? new BackupOffsiteUploadRoute();
        $this->deleteBackupRoute     = $deleteBackupRoute ?? new DeleteBackupRoute();
        $this->inventoryRoute        = $inventoryRoute ?? new InventoryRoute();
        $this->ssoLoginRoute         = $ssoLoginRoute ?? new SsoLoginRoute();
        $this->maintenanceRoute      = $maintenanceRoute ?? new MaintenanceRoute();
        $this->backupCreateRoute     = $backupCreateRoute ?? new BackupCreateRoute();
        $this->setManagedSlugsRoute  = $setManagedSlugsRoute ?? new SetManagedSlugsRoute();
        $this->whitelabelRoute       = $whitelabelRoute ?? new WhitelabelRoute();
        $this->pluginToggleRoute     = $pluginToggleRoute ?? new PluginToggleRoute();
        $this->themeSwitchRoute      = $themeSwitchRoute ?? new ThemeSwitchRoute();
        $this->themeDeleteRoute      = $themeDeleteRoute ?? new ThemeDeleteRoute();
        $this->siteHealthRoute       = $siteHealthRoute ?? new SiteHealthRoute();
        $this->dbScanRoute           = $dbScanRoute ?? new DbScanRoute();
        $this->dbCleanupRoute        = $dbCleanupRoute ?? new DbCleanupRoute();
        $this->dbOptimizeTablesRoute = $dbOptimizeTablesRoute ?? new DbOptimizeTablesRoute();
        $this->bootstrapPairingRoute = $bootstrapPairingRoute ?? new BootstrapPairingRoute();
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
            '/backup-offsite-upload',
            $this->backupOffsiteUploadRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/inventory',
            $this->inventoryRoute->args($permissionCallback)
        );

        // SSO login is the one route NOT HMAC-header-protected —
        // see SsoLoginRoute class docblock. The token in the query
        // param IS the credential. We pass the verifier callback
        // anyway for symmetry; the route ignores it (uses
        // __return_true) and validates the token inside the handler.
        register_rest_route(
            'deckwp/v1',
            '/sso-login',
            $this->ssoLoginRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/maintenance',
            $this->maintenanceRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/backup-create',
            $this->backupCreateRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/set-managed-slugs',
            $this->setManagedSlugsRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/whitelabel',
            $this->whitelabelRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/plugin-toggle',
            $this->pluginToggleRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/theme-switch',
            $this->themeSwitchRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/theme-delete',
            $this->themeDeleteRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/site-health',
            $this->siteHealthRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/db-scan',
            $this->dbScanRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/db-cleanup',
            $this->dbCleanupRoute->args($permissionCallback)
        );

        register_rest_route(
            'deckwp/v1',
            '/db-optimize-tables',
            $this->dbOptimizeTablesRoute->args($permissionCallback)
        );

        // NOTE: bootstrap-pairing does NOT use $permissionCallback
        // (the HMAC verifier) — by definition we don't have a secret
        // yet at bootstrap time. The route's own args() supplies a
        // `current_user_can('manage_options')` callback instead.
        // Passing the HMAC verifier here is purely structural; the
        // route ignores it.
        register_rest_route(
            'deckwp/v1',
            '/bootstrap-pairing',
            $this->bootstrapPairingRoute->args($permissionCallback)
        );
    }
}
