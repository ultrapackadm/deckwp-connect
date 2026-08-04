<?php

namespace DeckWP\Connect\Tests\Unit;

use DeckWP\Connect\Inventory\PluginInventory;
use PHPUnit\Framework\TestCase;

/**
 * Reporting the slug wordpress.org actually uses.
 *
 * WordPress identifies a plugin by its file path, and the dashboard was
 * treating the directory part of that path as if it were the
 * wordpress.org slug. Usually they match. When they don't, every URL
 * built from the assumption is wrong:
 *
 *   hello.php  →  path slug "hello", real slug "hello-dolly"
 *
 * Hello Dolly ships with WordPress, so the dashboard was requesting an
 * artifact that cannot exist on every site it managed, and an
 * end-to-end pass saw the resulting scan fail with no explanation.
 *
 * WordPress knows the right answer — the update transient records which
 * directory slug it matched each plugin to — so these tests pin that we
 * report it, including for plugins that are already up to date (whose
 * entries live in `no_update`, not `response`).
 */
class PluginInventoryWpOrgSlugTest extends TestCase
{
    protected function setUp(): void
    {
        wpStubReset();
        // Every test here is about slugs, not about the poll, so keep
        // the poll boringly successful.
        wpStubSetUpdateCheckResponse(['response' => ['code' => 200, 'message' => '']]);
    }

    protected function tearDown(): void
    {
        wpStubReset();
    }

    public function test_reports_the_directory_slug_for_a_single_file_plugin(): void
    {
        // The exact case that 404'd in production.
        wpStubSetPlugins([
            'hello.php' => ['Name' => 'Hello Dolly', 'Version' => '1.7.2'],
        ]);
        $this->givenTransient([], [
            'hello.php' => (object) ['slug' => 'hello-dolly', 'new_version' => '1.7.2'],
        ]);

        $row = $this->firstRow();

        $this->assertSame('hello', $row['slug'], 'the WordPress-local identifier is unchanged');
        $this->assertSame('hello-dolly', $row['wporg_slug']);
    }

    public function test_reports_the_directory_slug_for_a_vendored_sub_bundle(): void
    {
        // Plugins that vendor another plugin get an identifier several
        // directories deep; the directory part is not a slug at all.
        $file = 'aawp/aawp/vendor/woocommerce/action-scheduler/action-scheduler.php';
        wpStubSetPlugins([$file => ['Name' => 'Action Scheduler', 'Version' => '3.9.0']]);
        $this->givenTransient([
            $file => (object) ['slug' => 'action-scheduler', 'new_version' => '4.0.0'],
        ], []);

        $row = $this->firstRow();

        $this->assertSame('action-scheduler', $row['wporg_slug']);
        $this->assertTrue($row['update_available']);
    }

    public function test_finds_the_slug_of_an_up_to_date_plugin(): void
    {
        // The regression: reading only `response` meant a plugin with
        // nothing to update looked identical to one wordpress.org has
        // never heard of. Most plugins on a healthy site are here.
        wpStubSetPlugins([
            'akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.7'],
        ]);
        $this->givenTransient([], [
            'akismet/akismet.php' => (object) ['slug' => 'akismet', 'new_version' => '5.7'],
        ]);

        $row = $this->firstRow();

        $this->assertSame('akismet', $row['wporg_slug']);
        $this->assertFalse($row['update_available']);
    }

    public function test_reports_null_for_a_plugin_wordpress_org_does_not_know(): void
    {
        // Premium and bespoke plugins appear in neither bucket. Null is
        // the useful answer: it tells the dashboard there is no
        // wordpress.org artifact to fetch, so it can say so instead of
        // requesting one and reporting the 404 as a mystery.
        wpStubSetPlugins([
            'acme-private/acme-private.php' => ['Name' => 'Acme Private', 'Version' => '2.0'],
        ]);
        $this->givenTransient([], [
            'akismet/akismet.php' => (object) ['slug' => 'akismet', 'new_version' => '5.7'],
        ]);

        $row = $this->firstRow();

        $this->assertSame('acme-private', $row['slug']);
        $this->assertNull($row['wporg_slug']);
    }

    public function test_ignores_transient_entries_that_carry_no_slug(): void
    {
        // Third-party updaters inject entries with just a version and a
        // package URL. Inventing a slug from those would put us right
        // back to guessing.
        wpStubSetPlugins([
            'acme-pro/acme-pro.php' => ['Name' => 'Acme Pro', 'Version' => '1.0'],
        ]);
        $this->givenTransient([
            'acme-pro/acme-pro.php' => (object) [
                'new_version' => '1.1',
                'package' => 'https://acme.example/acme-pro-1.1.zip',
            ],
        ], []);

        $row = $this->firstRow();

        $this->assertNull($row['wporg_slug']);
        $this->assertTrue($row['update_available'], 'the update itself is still reported');
    }

    public function test_survives_a_transient_with_no_no_update_bucket(): void
    {
        // Older WordPress and some caching layers omit `no_update`
        // entirely. Missing data must not fatal the heartbeat.
        wpStubSetPlugins([
            'akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.7'],
        ]);
        wpStubSetSiteTransient('update_plugins', (object) [
            'response' => [],
            'last_checked' => 1754300000,
        ]);

        $row = $this->firstRow();

        $this->assertNull($row['wporg_slug']);
    }

    /**
     * @param  array<string, object>  $response
     * @param  array<string, object>  $noUpdate
     */
    private function givenTransient(array $response, array $noUpdate): void
    {
        wpStubSetSiteTransient('update_plugins', (object) [
            'response' => $response,
            'no_update' => $noUpdate,
            'last_checked' => 1754300000,
        ]);
    }

    /** @return array<string, mixed> */
    private function firstRow(): array
    {
        $rows = (new PluginInventory())->collect();
        $this->assertNotEmpty($rows, 'expected at least one inventory row');

        return $rows[0];
    }
}
