<?php

namespace DeckWP\Connect;

defined('ABSPATH') || exit;

use DeckWP\Connect\DropIn\Installer as DropInInstaller;
use DeckWP\Connect\Heartbeat\Scheduler as HeartbeatScheduler;
use DeckWP\Connect\Maintenance\MaintenanceGuard;
use DeckWP\Connect\REST\Server as RestServer;
use DeckWP\Connect\Scan\Scheduler as ScanScheduler;
use DeckWP\Connect\Settings\Page as SettingsPage;
use DeckWP\Connect\Updater\UpdateSuppressor;

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
 *                                  set-managed-slugs.
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
 *
 * ## Subsystems planned (per CLAUDE.md, will be wired in upcoming sprints)
 *
 *   - Transport\InitHookFallback — bypass when /wp-json is blocked by host
 *   - Whitelabel\Branding        — rewrites plugin row metadata
 *   - Updater\SelfUpdater        — pulls connector self-updates
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
        (new UpdateSuppressor())->register();

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
