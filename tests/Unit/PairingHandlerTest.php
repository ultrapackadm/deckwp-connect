<?php

namespace DeckWP\Connect\Tests\Unit;

use DeckWP\Connect\Heartbeat\Scheduler as HeartbeatScheduler;
use DeckWP\Connect\Pairing\Handler;
use DeckWP\Connect\Pairing\Setup;
use DeckWP\Connect\Scan\Scheduler as ScanScheduler;
use DeckWP\Connect\Storage\Settings;
use DeckWP\Connect\Tests\Support\FakeApiClient;
use PHPUnit\Framework\TestCase;

/**
 * The handshake, end to end, with only the two sockets faked.
 *
 * {@see PairingSetupTest} pins what {@see Setup} does when it runs.
 * This pins that it runs at all — the defect was not that setup was
 * wrong, it was that the handshake finished at the settings write and
 * nobody noticed for months, because "connected" looked identical
 * either way from inside WP admin.
 */
class PairingHandlerTest extends TestCase
{
    /** @var string|false */
    private $previousErrorLog;

    protected function setUp(): void
    {
        wpStubReset();

        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . '/deckwp-connect-test.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        wpStubReset();
    }

    public function test_a_successful_pair_leaves_the_site_scheduled_and_reported(): void
    {
        $dashboard = $this->dashboardAccepting();
        $events = new FakeApiClient([['status' => 202]]);

        $result = $this->makeHandler($dashboard, $events)->pair('pairing-token-abc');

        $this->assertTrue($result['ok']);
        $this->assertSame('site_01JABC', $result['site_id']);
        $this->assertTrue($result['first_heartbeat']);

        // The two things the site was missing after a "successful" pair.
        $this->assertNotFalse(wp_next_scheduled(HeartbeatScheduler::HOOK));
        $this->assertNotFalse(wp_next_scheduled(ScanScheduler::HOOK));
        $this->assertCount(1, $events->calls);
        $this->assertSame('heartbeat', $events->lastPayload()['event']);
    }

    public function test_the_first_heartbeat_carries_the_credentials_that_were_just_issued(): void
    {
        // Ordering trap: Setup has to run AFTER the settings write, or
        // the heartbeat signs with an empty secret and the dashboard
        // rejects the very first thing the site ever sends it.
        $events = new FakeApiClient([['status' => 202]]);

        $this->makeHandler($this->dashboardAccepting(), $events)->pair('pairing-token-abc');

        $this->assertCount(1, $events->calls);
        $this->assertSame(
            'https://deckwp.com/api/v1/sites/site_01JABC/events',
            $events->calls[0]['url']
        );
        $this->assertArrayHasKey('X-DeckWP-Signature', $events->calls[0]['headers']);
    }

    public function test_a_dead_events_endpoint_does_not_fail_the_pairing(): void
    {
        $events = new FakeApiClient([['status' => 503]]);

        $result = $this->makeHandler($this->dashboardAccepting(), $events)->pair('pairing-token-abc');

        // Pairing succeeded server-side before we ever tried to send.
        // Reporting it as a failure would send the operator back to
        // re-pair a site that is already connected.
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['first_heartbeat']);
        $this->assertTrue((new Settings())->isPaired());
        // And it says so, because the dashboard they're about to open
        // will have no plugin list on it yet.
        $this->assertStringContainsString('could not be delivered', $result['message']);
    }

    public function test_a_rejected_token_schedules_nothing(): void
    {
        $dashboard = new FakeApiClient([[
            'status' => 401,
            'body'   => ['message' => 'Invalid or expired pairing token.'],
        ]]);
        $events = new FakeApiClient();

        $result = $this->makeHandler($dashboard, $events)->pair('stale-token');

        $this->assertFalse($result['ok']);
        $this->assertFalse((new Settings())->isPaired());
        $this->assertFalse(wp_next_scheduled(HeartbeatScheduler::HOOK));
        $this->assertFalse(wp_next_scheduled(ScanScheduler::HOOK));
        $this->assertSame([], $events->calls);
    }

    public function test_an_empty_token_never_reaches_the_network(): void
    {
        $dashboard = $this->dashboardAccepting();

        $result = $this->makeHandler($dashboard, new FakeApiClient())->pair('   ');

        $this->assertFalse($result['ok']);
        $this->assertSame([], $dashboard->calls);
    }

    /**
     * A Handler whose pair POST and whose heartbeat POST hit different
     * fakes — they're different endpoints on the wire, and conflating
     * them would hide an ordering bug between the two.
     */
    private function makeHandler(FakeApiClient $dashboard, FakeApiClient $events): Handler
    {
        $settings = new Settings();

        return new Handler(
            $dashboard,
            $settings,
            null,
            new Setup(
                $settings,
                new HeartbeatScheduler($settings, null, $events),
                new ScanScheduler($settings)
            )
        );
    }

    /**
     * The dashboard's 200 for `POST /api/v1/connect/pair`.
     */
    private function dashboardAccepting(): FakeApiClient
    {
        return new FakeApiClient([[
            'status' => 200,
            'body'   => [
                'site_id'      => 'site_01JABC',
                'hmac_secret'  => base64_encode('super-secret-bytes'),
                'team_slug'    => 'acme',
                'callback_url' => 'https://deckwp.com/api/v1/sites/site_01JABC/events',
                'intervals'    => [
                    'heartbeat_seconds' => 300,
                    'scan_seconds'      => 86400,
                ],
            ],
        ]]);
    }
}
