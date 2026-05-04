<?php

namespace DeckWP\Connect;

defined('ABSPATH') || exit;

use DeckWP\Connect\Heartbeat\Scheduler as HeartbeatScheduler;
use DeckWP\Connect\REST\Server as RestServer;
use DeckWP\Connect\Scan\Scheduler as ScanScheduler;
use DeckWP\Connect\Settings\Page as SettingsPage;

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
 *   - REST\Server           — exposes deckwp/v1/* routes for the
 *                             dashboard. Currently: POST /scan
 *                             (the "Scan now" trigger). HMAC-protected.
 *
 * ## Subsystems planned (per CLAUDE.md, will be wired in upcoming sprints)
 *
 *   - Transport\InitHookFallback — bypass when /wp-json is blocked by host
 *   - DropIn\Installer           — installs wp-content/fatal-error-handler.php
 *   - Whitelabel\Branding        — rewrites plugin row metadata
 *   - Maintenance\Page           — 503 holding page
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
    }
}
