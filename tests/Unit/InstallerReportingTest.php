<?php

namespace DeckWP\Connect\Tests\Unit;

use DeckWP\Connect\Install\Installer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * What the connector tells the dashboard about an install — the two
 * places it used to say less than it knew.
 *
 * 1. A theme result echoed back the slug it was ASKED for and dropped
 *    the stylesheet directory it had just resolved, so the dashboard
 *    couldn't recognize its own install in the next inventory.
 * 2. A non-truthy `install()` with no WP_Error was reported as
 *    "usually a filesystem-permissions issue" whatever the actual
 *    cause, sending operators to chmod for problems chmod can't fix.
 */
class InstallerReportingTest extends TestCase
{
    protected function setUp(): void
    {
        wpStubReset();
    }

    private function invoke(string $method, array $args)
    {
        $m = new ReflectionMethod(Installer::class, $method);
        $m->setAccessible(true);

        return $m->invoke(new Installer(), ...$args);
    }

    /* ---- installed_slug ------------------------------------------- */

    public function test_theme_result_reports_the_stylesheet_directory(): void
    {
        // Citadela's package extracts to `citadela-theme`; the catalog
        // (and therefore the dashboard's request) calls it `citadela`.
        $row = $this->invoke('withThemeActivation', [
            'citadela',
            'citadela-theme',
            'installed',
            '6.2.6',
            '6.2.7',
            null,
            false,
        ]);

        $this->assertSame('citadela', $row['slug'], 'echoes back what was requested');
        $this->assertSame('citadela-theme', $row['installed_slug'], 'and says where it actually landed');
    }

    public function test_installed_slug_is_present_even_when_the_names_agree(): void
    {
        // The dashboard shouldn't have to treat "absent" and "same"
        // differently — absent means an old connector, nothing else.
        $row = $this->invoke('withThemeActivation', [
            'twentytwentyfive',
            'twentytwentyfive',
            'unchanged',
            '1.0',
            '1.0',
            null,
            false,
        ]);

        $this->assertSame('twentytwentyfive', $row['installed_slug']);
    }

    /* ---- silent upgrader failures ---------------------------------- */

    public function test_reports_a_wp_error_the_upgrader_swallowed(): void
    {
        $upgrader = new \stdClass();
        $upgrader->skin = new class {
            public $result;

            public function __construct()
            {
                $this->result = new \WP_Error('download_failed', 'Download failed. Not Found');
            }

            public function get_upgrade_messages()
            {
                return ['Downloading installation package…'];
            }
        };

        $message = $this->invoke('describeSilentUpgraderFailure', [
            $upgrader, 'Plugin_Upgrader::install()', 'wp-content/plugins/',
        ]);

        $this->assertStringContainsString('Download failed. Not Found', $message);
        $this->assertStringNotContainsString('permissions', $message);
    }

    public function test_reports_the_last_thing_wordpress_said(): void
    {
        // The real ACF PRO failure shape: the package downloaded fine
        // and then WordPress refused the archive. Nothing to do with
        // file permissions.
        $upgrader = new \stdClass();
        $upgrader->skin = new class {
            public $result = null;

            public function get_upgrade_messages()
            {
                return [
                    'Downloading installation package from <span>https://…</span>…',
                    'Unpacking the package…',
                    'Incompatible Archive.',
                ];
            }
        };

        $message = $this->invoke('describeSilentUpgraderFailure', [
            $upgrader, 'Plugin_Upgrader::install()', 'wp-content/plugins/',
        ]);

        $this->assertStringContainsString('Incompatible Archive.', $message);
        $this->assertStringNotContainsString('permissions', $message);
        // Markup stripped — this goes into an operator-facing field.
        $this->assertStringNotContainsString('<span>', $message);
    }

    public function test_falls_back_to_the_permissions_hint_only_when_nothing_is_known(): void
    {
        $upgrader = new \stdClass();
        $upgrader->skin = new class {
            public $result = null;

            public function get_upgrade_messages()
            {
                return [];
            }
        };

        $message = $this->invoke('describeSilentUpgraderFailure', [
            $upgrader, 'Theme_Upgrader::install()', 'wp-content/themes/',
        ]);

        $this->assertStringContainsString('wp-content/themes/', $message);
        $this->assertStringContainsString('writable', $message);
        // And it no longer asserts that permissions ARE the cause.
        $this->assertStringContainsString('valid ZIP', $message);
    }

    public function test_survives_a_skin_that_offers_nothing(): void
    {
        $upgrader = new \stdClass();

        $message = $this->invoke('describeSilentUpgraderFailure', [
            $upgrader, 'Plugin_Upgrader::install()', 'wp-content/plugins/',
        ]);

        $this->assertStringContainsString('Plugin_Upgrader::install()', $message);
    }
}
