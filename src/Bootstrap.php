<?php

namespace DeckWP\Connect;

defined('ABSPATH') || exit;

use DeckWP\Connect\DropIn\Installer as DropInInstaller;
use DeckWP\Connect\Heartbeat\Scheduler as HeartbeatScheduler;
use DeckWP\Connect\Maintenance\MaintenanceGuard;
use DeckWP\Connect\REST\Server as RestServer;
use DeckWP\Connect\Scan\Scheduler as ScanScheduler;
use DeckWP\Connect\Settings\Page as SettingsPage;
use DeckWP\Connect\Transport\InitHookFallback;
use DeckWP\Connect\Updater\SelfUpdater;
use DeckWP\Connect\Updater\UpdateSuppressor;
use DeckWP\Connect\Whitelabel\Branding as WhitelabelBranding;

/**
 * Boots and registers every connector subsystem on `plugins_loaded`.
 *
 * Kept thin on purpose — the heavy lifting lives in each subsystem class.
 * This file should change only when a NEW subsystem is added or removed,
 * never when an existing subsystem's internals shift.
 *
 * ## Subsystems registered (current state)
 *
 *   - Settings\Page         — admin UI for the pairing handshake
 *   - Heartbeat\Scheduler   — WP-Cron-driven heartbeat sender (gated
 *                             by the DECKWP_CONNECT_ENABLE_HEARTBEAT
 *                             constant; off by default)
 *   - Scan\Scheduler        — WP-Cron-driven security scan sender
 *                             (gated by DECKWP_CONNECT_ENABLE_SCAN;
 *                             off by default)
 *   - REST\Server                — exposes deckwp/v1/* routes for the
 *                                  dashboard. Currently: scan,
 *                                  install-batch, restore-backup,
 *                                  delete-backup, inventory, sso-login,
 *                                  maintenance, backup-create,
 *                                  set-managed-slugs, whitelabel.
 *                                  HMAC-protected (except sso-login,
 *                                  which uses a self-signed query token).
 *   - Maintenance\MaintenanceGuard — `init` hook that intercepts
 *                                    non-admin / non-REST requests
 *                                    with a 503 branded page when
 *                                    the dashboard has toggled
 *                                    maintenance mode on.
 *   - DropIn\Installer           — idempotently installs (or refreshes)
 *                                  `wp-content/fatal-error-handler.php`
 *                                  from the bundled source. Foreign
 *                                  drop-ins are detected and skipped.
 *                                  Slice 1 of the multisite-aware
 *                                  fatal-handling rollout.
 *   - Updater\UpdateSuppressor   — hides "Update available" for plugins
 *                                  / themes the dashboard has flagged
 *                                  as managed (deckwp_managed_slugs
 *                                  site option). Closes the flank where
 *                                  an operator clicking Update on the
 *                                  WP admin would bypass the connector's
 *                                  pre-update backup + smoke flow.
 *   - Whitelabel\Branding        — rewrites plugin metadata in the WP
 *                                  admin (Name, Description, Author,
 *                                  AuthorURI, PluginURI) + hides
 *                                  selected entries based on the
 *                                  deckwp_whitelabel_config site option
 *                                  pushed by the dashboard via
 *                                  /wp-json/deckwp/v1/whitelabel.
 *   - Updater\SelfUpdater        — polls the dashboard's
 *                                  `GET /api/v1/sites/{site}/connector/latest`
 *                                  on the WP update_plugins transient
 *                                  refresh and offers the connector's
 *                                  own update through WP's built-in
 *                                  upgrade flow. Operator clicks Update
 *                                  on the WP Plugins page; no manual
 *                                  redeploy.
 *   - Transport\InitHookFallback — `init`-hook fallback transport that
 *                                  accepts HMAC-signed commands on a
 *                                  normal front-end URL when /wp-json is
 *                                  blocked by the host or a security
 *                                  plugin. Reuses the REST handlers +
 *                                  HMAC verifier; inbound only, inert on
 *                                  normal page loads. Runs at init
 *                                  priority 0 (before MaintenanceGuard).
 *
 * ## Singleton
 *
 * One Bootstrap per request. The instance is intentionally NOT exposed as
 * a global service locator — subsystems should construct their own
 * collaborators (or have them passed in) rather than reaching back here.
 * The container is just a boot orchestrator.
 */
class Bootstrap
{
    /** @var Bootstrap|null */
    private static $instance = null;

    /** @var bool */
    private $booted = false;

    private function __construct()
    {
        // Private — go through {@see boot()}.
    }

    /**
     * Idempotent entrypoint. Safe to call multiple times: subsystems
     * register their hooks on first boot, subsequent calls no-op.
     */
    public static function boot(): void
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        self::$instance->run();
    }

    private function run(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        (new SettingsPage())->register();
        (new HeartbeatScheduler())->register();
        (new ScanScheduler())->register();
        (new RestServer())->register();

        // REST fallback transport — accepts HMAC-signed commands on a
        // normal front-end URL (init hook, priority 0) for sites where a
        // host or security plugin blocks /wp-json. Registering before the
        // MaintenanceGuard (priority 1) lets the dashboard keep managing a
        // site whose maintenance mode is on.
        (new InitHookFallback())->register();

        (new UpdateSuppressor())->register();
        (new WhitelabelBranding())->register();
        (new SelfUpdater())->register();

        // Maintenance mode guard — runs at `init` priority 1 so we
        // short-circuit the request before themes/plugins start their
        // frontend rendering. Bypasses REST + admin + CLI; see the
        // class docblock for the full exemption list.
        $maintenanceGuard = new MaintenanceGuard();
        add_action('init', [$maintenanceGuard, 'maybeIntercept'], 1);

        // Fatal handler drop-in — idempotently ensures
        // wp-content/fatal-error-handler.php is our managed source.
        // Foreign drop-ins (host-installed, third-party plugins) are
        // detected via marker grep and left alone. Failures are
        // logged to error_log only; we deliberately don't break boot
        // if the install can't run, since the plugin still does
        // useful work (heartbeat, REST, etc.) without it.
        $this->ensureFatalDropIn();
    }

    private function ensureFatalDropIn(): void
    {
        $result = (new DropInInstaller())->install();
        if (empty($result['ok'])) {
            error_log(
                '[DeckWP Connect] Fatal handler drop-in install skipped: '
                . ($result['error_code'] ?? 'unknown')
                . ' — '
                . ($result['error'] ?? '')
            );
        }
    }
}
