<?php

namespace DeckWP\Connect\Tests\Unit;

use DeckWP\Connect\Install\Installer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Upgrading a plugin that is currently ACTIVE.
 *
 * WordPress core hooks `deactivate_plugin_before_upgrade()` onto
 * `upgrader_pre_install`, so `Plugin_Upgrader::upgrade()` switches the
 * plugin off before replacing its files. Core's only path back on is
 * `Plugin_Upgrader_Skin::after()`, which prints an activation iframe
 * into an admin pageload — and we run under `Automatic_Upgrader_Skin`
 * inside a REST request, where no such pageload exists.
 *
 * Left alone, every upgrade of an active plugin finished on the new
 * version and switched off; the smoke check read core's own
 * deactivation as a fatal and rolled a working upgrade back. Contact
 * Form 7 6.0 → 6.1.6 is the report that surfaced it.
 *
 * Neither `wp plugin update` nor the wp-admin AJAX updater reproduces
 * it (`bulk_upgrade()` never registers the deactivation filter), which
 * is why this had no coverage until now: every way a human tests it by
 * hand takes a different code path than the one customers hit.
 */
class InstallerActivePluginTest extends TestCase
{
    private const FILE = 'contact-form-7/wp-contact-form-7.php';

    protected function setUp(): void
    {
        wpStubReset();
    }

    /**
     * @return array{restored: bool, error: string|null}
     */
    private function restore(bool $wasActive, bool $wasNetworkActive = false): array
    {
        $m = new ReflectionMethod(Installer::class, 'restoreActiveState');
        $m->setAccessible(true);

        return $m->invoke(new Installer(), self::FILE, $wasActive, $wasNetworkActive);
    }

    public function test_puts_back_a_plugin_core_deactivated_mid_upgrade(): void
    {
        // The state right after Plugin_Upgrader::upgrade() returns:
        // new files on disk, plugin switched off by core.
        wpStubSetActivePlugins([]);

        $result = $this->restore(true);

        $this->assertTrue($result['restored']);
        $this->assertNull($result['error']);
        $this->assertTrue(is_plugin_active(self::FILE), 'plugin is active again');
    }

    public function test_reactivates_silently(): void
    {
        // Core deactivated silently, so no `deactivate_plugin` hook
        // fired. Firing `activate_plugin` on the way back would run
        // register_activation_hook callbacks that belong to a FIRST
        // INSTALL — seeding default options, scheduling cron, showing
        // a welcome redirect — none of which should happen on an
        // upgrade. WP-CLI and cron auto-updates don't fire them either.
        wpStubSetActivePlugins([]);

        $this->restore(true);

        $calls = wpStubActivationCalls();
        $this->assertCount(1, $calls);
        $this->assertTrue($calls[0]['silent'], 'we are restoring a state, not performing an activation');
    }

    public function test_preserves_network_scope(): void
    {
        // A network-active plugin restored site-only would vanish from
        // every site in the network but the current one.
        wpStubSetActivePlugins([]);

        $this->restore(true, true);

        $calls = wpStubActivationCalls();
        $this->assertTrue($calls[0]['network_wide']);
        $this->assertTrue(is_plugin_active_for_network(self::FILE));
    }

    public function test_leaves_an_inactive_plugin_inactive(): void
    {
        // The customer had this switched off before the upgrade.
        // Turning it on as a side effect of updating it would be us
        // changing their site's behaviour without being asked.
        wpStubSetActivePlugins([]);

        $result = $this->restore(false);

        $this->assertFalse($result['restored']);
        $this->assertNull($result['error']);
        $this->assertSame([], wpStubActivationCalls(), 'activate_plugin was never called');
        $this->assertFalse(is_plugin_active(self::FILE));
    }

    public function test_does_nothing_when_the_plugin_never_left_the_active_set(): void
    {
        // The premium `download_url` path goes through WP_Upgrader::run()
        // and never deactivates, so it arrives here already active.
        wpStubSetActivePlugins([self::FILE]);

        $result = $this->restore(true);

        $this->assertFalse($result['restored'], 'restored is true only when THIS call put it back');
        $this->assertNull($result['error']);
        $this->assertSame([], wpStubActivationCalls());
        $this->assertTrue(is_plugin_active(self::FILE));
    }

    public function test_surfaces_a_refused_activation(): void
    {
        wpStubSetActivePlugins([]);
        wpStubSetActivationResult(
            self::FILE,
            new \WP_Error('plugin_wp_php_incompatible', 'The plugin requires PHP 8.1.')
        );

        $result = $this->restore(true);

        $this->assertFalse($result['restored']);
        $this->assertStringContainsString('requires PHP 8.1', (string) $result['error']);
    }

    public function test_catches_an_activation_that_reports_no_error_and_does_nothing(): void
    {
        // `activate_plugin()` returning null is not proof of anything —
        // it returns null when it succeeds AND when it short-circuits.
        // Without the read-back, a plugin left switched off would be
        // reported to the dashboard as successfully reactivated, and
        // the customer's site quietly loses a plugin.
        wpStubSetActivePlugins([]);
        wpStubSetActivationResult(self::FILE, 'noop');

        $result = $this->restore(true);

        $this->assertFalse($result['restored']);
        $this->assertStringContainsString('still inactive', (string) $result['error']);
        $this->assertStringContainsString(self::FILE, (string) $result['error']);
    }
}
