<?php

namespace DeckWP\Connect\Tests\Unit;

use DeckWP\Connect\License\LicenseDetector;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the connector-side license detector — the piece that
 * tells the dashboard "this installed item has an active official license,
 * don't overwrite it with the catalog build".
 *
 * WP functions are stubbed by tests/bootstrap.php; each test seeds the
 * in-memory options / transients it needs.
 */
class LicenseDetectorTest extends TestCase
{
    private LicenseDetector $detector;

    protected function setUp(): void
    {
        wpStubReset();
        $this->detector = new LicenseDetector();
    }

    /** Helper: build a plugin update transient with one response entry. */
    private function pluginTransient(string $file, string $package): object
    {
        $t = new \stdClass();
        $t->response = [$file => (object) ['package' => $package]];

        return $t;
    }

    public function test_unknown_when_no_signal(): void
    {
        wpStubSetPlugins(['acme/acme.php' => ['Name' => 'Acme']]);

        $result = $this->detector->detect('acme', 'plugin');

        $this->assertSame('unknown', $result['state']);
        $this->assertNull($result['provider']);
    }

    public function test_edd_license_status_valid_is_licensed(): void
    {
        wpStubSetOption('acme_license_status', 'valid');

        $result = $this->detector->detect('acme', 'plugin');

        $this->assertSame('licensed_active', $result['state']);
        $this->assertSame('edd', $result['provider']);
    }

    public function test_edd_slug_with_dashes_maps_to_underscore_option(): void
    {
        wpStubSetOption('my_plugin_license_status', 'valid');

        $result = $this->detector->detect('my-plugin', 'plugin');

        $this->assertSame('licensed_active', $result['state']);
    }

    public function test_external_updater_package_in_transient_is_licensed(): void
    {
        wpStubSetPlugins(['acme/acme.php' => ['Name' => 'Acme']]);
        wpStubSetSiteTransient('update_plugins', $this->pluginTransient(
            'acme/acme.php',
            'https://acme.com/download/acme-pro.zip?license=xyz'
        ));

        $result = $this->detector->detect('acme', 'plugin');

        $this->assertSame('licensed_active', $result['state']);
        $this->assertSame('external_updater', $result['provider']);
    }

    public function test_wporg_package_is_NOT_a_license_signal(): void
    {
        wpStubSetPlugins(['acme/acme.php' => ['Name' => 'Acme']]);
        wpStubSetSiteTransient('update_plugins', $this->pluginTransient(
            'acme/acme.php',
            'https://downloads.wordpress.org/plugin/acme.1.2.4.zip'
        ));

        $result = $this->detector->detect('acme', 'plugin');

        $this->assertSame('unknown', $result['state']);
    }

    public function test_our_own_catalog_package_is_NOT_a_license_signal(): void
    {
        wpStubSetPlugins(['acme/acme.php' => ['Name' => 'Acme']]);
        wpStubSetSiteTransient('update_plugins', $this->pluginTransient(
            'acme/acme.php',
            'https://cdn.deckwp.com/premium/acme.zip'
        ));

        $this->assertSame('unknown', $this->detector->detect('acme', 'plugin')['state']);
    }

    public function test_theme_external_package_is_licensed(): void
    {
        $t = new \stdClass();
        $t->response = ['avada' => ['package' => 'https://theme-fusion.com/avada/update.zip']];
        wpStubSetSiteTransient('update_themes', $t);

        $result = $this->detector->detect('avada', 'theme');

        $this->assertSame('licensed_active', $result['state']);
        $this->assertSame('external_updater', $result['provider']);
    }

    public function test_freemius_site_with_license_is_licensed(): void
    {
        wpStubSetOption('fs_accounts', [
            'sites' => [
                'acme' => ['id' => 1, 'slug' => 'acme', 'license_id' => 42],
            ],
        ]);

        $result = $this->detector->detect('acme', 'plugin');

        $this->assertSame('licensed_active', $result['state']);
        $this->assertSame('freemius', $result['provider']);
    }

    public function test_freemius_site_without_license_id_is_unknown(): void
    {
        wpStubSetOption('fs_accounts', [
            'sites' => [
                'acme' => ['id' => 1, 'slug' => 'acme', 'license_id' => 0],
            ],
        ]);

        $this->assertSame('unknown', $this->detector->detect('acme', 'plugin')['state']);
    }

    public function test_filter_can_force_licensed_with_custom_provider(): void
    {
        wpStubAddFilter('deckwp_detect_active_license', function ($value, $slug, $type) {
            return $slug === 'special' ? 'my-framework' : $value;
        });

        $result = $this->detector->detect('special', 'plugin');

        $this->assertSame('licensed_active', $result['state']);
        $this->assertSame('my-framework', $result['provider']);
    }

    public function test_isLicensedActive_ignores_transient_by_default_but_honors_frameworks(): void
    {
        wpStubSetPlugins(['acme/acme.php' => ['Name' => 'Acme']]);
        // Only a transient signal is present.
        wpStubSetSiteTransient('update_plugins', $this->pluginTransient(
            'acme/acme.php',
            'https://acme.com/download/acme-pro.zip'
        ));

        // Default (safeguard call): transient signal is circular → ignored.
        $this->assertFalse($this->detector->isLicensedActive('acme', 'plugin'));

        // A framework signal still counts on the safeguard path.
        wpStubSetOption('acme_license_status', 'valid');
        $this->assertTrue($this->detector->isLicensedActive('acme', 'plugin'));
    }
}
