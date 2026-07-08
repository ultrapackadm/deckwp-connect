<?php

namespace DeckWP\Connect\Tests\Unit;

use DeckWP\Connect\Install\Installer;
use DeckWP\Connect\License\LicenseDetector;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The install-time license safeguard — the connector's final line of
 * defense that refuses to overwrite a licensed item with a catalog build.
 *
 * We drive the private `licenseGuard()` decision directly (via reflection)
 * with a stubbed detector, so the WP upgraders never run: the guard either
 * returns a failure result (block) or null (proceed).
 */
class InstallerLicenseGuardTest extends TestCase
{
    private function installerWithLicense(bool $licensed): Installer
    {
        $detector = new class($licensed) extends LicenseDetector {
            private bool $licensed;

            public function __construct(bool $licensed)
            {
                $this->licensed = $licensed;
            }

            public function isLicensedActive(string $slug, string $type, bool $useTransient = false): bool
            {
                return $this->licensed;
            }
        };

        return new Installer(null, null, $detector);
    }

    /** @return array<string, mixed>|null */
    private function guard(Installer $installer, array $item)
    {
        $m = new ReflectionMethod(Installer::class, 'licenseGuard');
        $m->setAccessible(true);

        return $m->invoke($installer, (string) ($item['slug'] ?? 'acme'), 'plugin', $item);
    }

    public function test_blocks_a_licensed_catalog_overwrite(): void
    {
        $result = $this->guard(
            $this->installerWithLicense(true),
            ['slug' => 'acme', 'download_url' => 'https://cdn.deckwp.com/premium/acme.zip']
        );

        $this->assertIsArray($result);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('original_license_protected', $result['error']);
    }

    public function test_allows_when_operator_authorized_the_override(): void
    {
        $result = $this->guard(
            $this->installerWithLicense(true),
            [
                'slug' => 'acme',
                'download_url' => 'https://cdn.deckwp.com/premium/acme.zip',
                'license_override' => true,
            ]
        );

        $this->assertNull($result);
    }

    public function test_exempts_wporg_upgrades_with_no_download_url(): void
    {
        // No download_url = the connector resolves via wp.org; a wp.org
        // build can't strip a license, so the guard never fires even when
        // the item is licensed.
        $result = $this->guard(
            $this->installerWithLicense(true),
            ['slug' => 'acme']
        );

        $this->assertNull($result);
    }

    public function test_allows_an_unlicensed_catalog_overwrite(): void
    {
        $result = $this->guard(
            $this->installerWithLicense(false),
            ['slug' => 'acme', 'download_url' => 'https://cdn.deckwp.com/premium/acme.zip']
        );

        $this->assertNull($result);
    }
}
