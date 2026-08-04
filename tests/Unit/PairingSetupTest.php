<?php

namespace DeckWP\Connect\Tests\Unit;

use DeckWP\Connect\Heartbeat\Scheduler as HeartbeatScheduler;
use DeckWP\Connect\Pairing\Setup;
use DeckWP\Connect\Scan\Scheduler as ScanScheduler;
use DeckWP\Connect\Storage\Settings;
use DeckWP\Connect\Tests\Support\FakeApiClient;
use PHPUnit\Framework\TestCase;

/**
 * What has to be true the instant a pairing completes.
 *
 * An end-to-end pass paired a fresh site and found the dashboard
 * showing zero plugins until the operator hunted down the Refresh
 * button. Two causes, and the second is the interesting one:
 *
 *   1. Nothing pushed an inventory at the end of the handshake, so the
 *      dashboard had nothing but what pairing itself carried.
 *   2. Neither cron event was ever scheduled — on any install, ever.
 *      `DECKWP_CONNECT_ENABLE_HEARTBEAT` and `DECKWP_CONNECT_ENABLE_SCAN`
 *      gated the schedulers as `defined(X) && X`, and the plugin
 *      defines neither. So "wait for the next tick" was not a slower
 *      path to the same place — there was no next tick.
 *
 * These tests pin both halves.
 */
class PairingSetupTest extends TestCase
{
    /** @var string|false */
    private $previousErrorLog;

    protected function setUp(): void
    {
        wpStubReset();

        // The heartbeat logs its outcome either way. Route it to a file
        // so the suite's output stays readable.
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . '/deckwp-connect-test.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        wpStubReset();
    }

    public function test_schedules_both_cron_events(): void
    {
        // The regression: a paired site whose wp_cron event list was
        // empty, so no heartbeat and no scan ever ran unattended.
        $this->givenPairedSite();

        $this->makeSetup()->run();

        $this->assertNotFalse(wp_next_scheduled(HeartbeatScheduler::HOOK));
        $this->assertNotFalse(wp_next_scheduled(ScanScheduler::HOOK));
    }

    public function test_pushes_the_first_inventory_without_waiting_for_cron(): void
    {
        $this->givenPairedSite();
        $http = $this->httpReturning(202);

        $result = $this->makeSetup($http)->run();

        $this->assertTrue($result['heartbeat_sent']);
        $this->assertNull($result['heartbeat_error']);
        $this->assertCount(1, $http->calls);
        $this->assertSame(
            'https://deckwp.com/api/v1/sites/site_01JABC/events',
            $http->calls[0]['url']
        );

        $body = json_decode($http->calls[0]['body'], true);
        $this->assertSame('heartbeat', $body['event']);
        // The whole point of the eager push: the dashboard learns the
        // site's contents now, not on the next tick.
        $this->assertArrayHasKey('plugins', $body);
        $this->assertArrayHasKey('themes', $body);
    }

    public function test_a_failed_first_push_does_not_undo_the_pairing(): void
    {
        // Pairing already succeeded server-side by the time we get
        // here. Rolling it back because the network hiccuped would
        // throw away a working connection to report a transient fault.
        $this->givenPairedSite();

        $result = $this->makeSetup($this->httpReturning(500))->run();

        $this->assertTrue($result['scheduled']);
        $this->assertFalse($result['heartbeat_sent']);
        $this->assertTrue((new Settings())->isPaired());
        // And the cron is still queued, which is what actually
        // recovers the inventory a few minutes later.
        $this->assertNotFalse(wp_next_scheduled(HeartbeatScheduler::HOOK));
    }

    public function test_reports_why_the_first_push_failed(): void
    {
        // The operator is about to switch to a dashboard with no plugin
        // list on it. Silence there reads as a broken connection.
        $this->givenPairedSite();

        $result = $this->makeSetup($this->httpReturning(500))->run();

        $this->assertNotNull($result['heartbeat_error']);
        $this->assertNotSame('', $result['heartbeat_error']);
    }

    public function test_does_nothing_for_a_site_that_is_not_paired(): void
    {
        $http = $this->httpReturning(202);

        $result = $this->makeSetup($http)->run();

        $this->assertFalse($result['scheduled']);
        $this->assertFalse($result['heartbeat_sent']);
        $this->assertSame([], $http->calls);
        $this->assertFalse(wp_next_scheduled(HeartbeatScheduler::HOOK));
        $this->assertFalse(wp_next_scheduled(ScanScheduler::HOOK));
    }

    public function test_running_it_twice_does_not_double_book_the_cron(): void
    {
        // Re-pairing an already-paired install is a normal thing to do
        // (rotate the secret, move dashboards). It must not leave two
        // heartbeats racing each other.
        $this->givenPairedSite();
        $setup = $this->makeSetup();

        $setup->run();
        $first = $GLOBALS['__wp_cron'];

        $setup->run();

        $this->assertSame($first, $GLOBALS['__wp_cron']);
    }

    /**
     * A constant can be defined but never undefined, so this one runs
     * in its own PHP process — otherwise it would poison every test
     * that happens to be ordered after it.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_honours_a_site_that_opted_out_of_the_heartbeat(): void
    {
        // The constant survives as an escape hatch for the rare host
        // that wants the connector passive. Setup must not route
        // around a decision the scheduler respects.
        define('DECKWP_CONNECT_ENABLE_HEARTBEAT', false);

        $this->givenPairedSite();

        $this->makeSetup()->run();

        $this->assertFalse(wp_next_scheduled(HeartbeatScheduler::HOOK));
        // Scan is a separate switch and stays on.
        $this->assertNotFalse(wp_next_scheduled(ScanScheduler::HOOK));
    }

    /**
     * A Setup wired to real schedulers — only the socket is faked, so
     * the scheduling and payload logic under test is the real thing.
     */
    private function makeSetup(?FakeApiClient $http = null): Setup
    {
        $settings = new Settings();

        return new Setup(
            $settings,
            new HeartbeatScheduler($settings, null, $http ?? $this->httpReturning(202)),
            new ScanScheduler($settings)
        );
    }

    private function httpReturning(int $status): FakeApiClient
    {
        return new FakeApiClient([['status' => $status]]);
    }

    /**
     * Seed the option store the way a completed pair handshake leaves it.
     */
    private function givenPairedSite(): void
    {
        wpStubSetOption(Settings::OPTION_KEY, [
            'site_id'           => 'site_01JABC',
            'token'             => 'local-bootstrap-token',
            'hmac_secret'       => base64_encode('super-secret-bytes'),
            'platform_url'      => 'https://deckwp.com',
            'connected_at'      => '1754300000',
            'team_slug'         => 'acme',
            'callback_url'      => 'https://deckwp.com/api/v1/sites/site_01JABC/events',
            'heartbeat_seconds' => 300,
            'scan_seconds'      => 86400,
        ]);
    }
}
