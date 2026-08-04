<?php

namespace DeckWP\Connect\Tests\Unit;

use DeckWP\Connect\Heartbeat\Scheduler as HeartbeatScheduler;
use DeckWP\Connect\Pairing\Teardown;
use DeckWP\Connect\Scan\Scheduler as ScanScheduler;
use DeckWP\Connect\Storage\Settings;
use PHPUnit\Framework\TestCase;

/**
 * What "disconnected" has to mean locally.
 *
 * An end-to-end pass disconnected a site from the dashboard and found
 * the WordPress install still holding its `site_id`, its HMAC secret
 * and its callback URL, with the settings page still reading
 * "Connected". Two halves to that: the dashboard never told the site
 * (fixed by the `/unpair` route), and the local teardown that did run
 * on other paths left the cron events queued.
 *
 * These tests pin the state a torn-down site is in, independent of
 * which of the four paths tore it down — because the whole point of
 * routing them through one class is that they can't disagree.
 */
class TeardownTest extends TestCase
{
    /** @var string|false */
    private $previousErrorLog;

    protected function setUp(): void
    {
        wpStubReset();

        // Teardown logs a line per run. Route it to a file so the
        // suite's output stays readable.
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . '/deckwp-connect-test.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        wpStubReset();
    }

    public function test_clears_every_credential_the_dashboard_issued(): void
    {
        $this->givenPairedSite();

        (new Teardown())->run('dashboard_unpair');

        $settings = (new Settings())->all();

        // The three the report caught the connector still holding.
        $this->assertSame('', $settings['site_id']);
        $this->assertSame('', $settings['hmac_secret']);
        $this->assertSame('', $settings['callback_url']);

        $this->assertSame('', $settings['platform_url']);
        $this->assertSame('', $settings['team_slug']);
        $this->assertFalse((new Settings())->isPaired());
    }

    public function test_keeps_the_locally_generated_token(): void
    {
        // The bootstrap token is ours, not the dashboard's — churning
        // it on every disconnect would break re-pairing the same
        // install from a token the operator already copied.
        $this->givenPairedSite();

        (new Teardown())->run('dashboard_unpair');

        $this->assertSame('local-bootstrap-token', (new Settings())->get('token'));
    }

    public function test_stops_the_cron_events(): void
    {
        // The bug this catches: clearing the settings without clearing
        // the schedule leaves a heartbeat firing every five minutes
        // against an empty secret, logging a failure each time.
        $this->givenPairedSite();
        wpStubScheduleEvent(HeartbeatScheduler::HOOK);
        wpStubScheduleEvent(ScanScheduler::HOOK);

        (new Teardown())->run('dashboard_unpair');

        $this->assertFalse(wp_next_scheduled(HeartbeatScheduler::HOOK));
        $this->assertFalse(wp_next_scheduled(ScanScheduler::HOOK));
    }

    public function test_leaves_local_configuration_alone(): void
    {
        // Unpairing is "stop talking to that dashboard", not
        // "uninstall". Whitelabel branding and the managed-slugs list
        // are the operator's, and a site that re-pairs an hour later
        // should find them where it left them.
        $this->givenPairedSite();
        wpStubSetOption('deckwp_whitelabel', ['plugin_name' => 'Acme Care']);
        wpStubSetOption('deckwp_managed_slugs', ['plugins' => ['wp-rocket'], 'themes' => []]);

        (new Teardown())->run('dashboard_unpair');

        $this->assertSame(['plugin_name' => 'Acme Care'], get_option('deckwp_whitelabel'));
        $this->assertSame(
            ['plugins' => ['wp-rocket'], 'themes' => []],
            get_option('deckwp_managed_slugs')
        );
    }

    public function test_stashes_the_banner_when_someone_else_disconnected_us(): void
    {
        $this->givenPairedSite();

        (new Teardown())->run('heartbeat_401');

        $notice = get_transient(Teardown::NOTICE_KEY);

        $this->assertIsArray($notice);
        // Captured before the clear — it's what the banner's "Re-pair
        // this site" link is built from, and after the clear there is
        // nothing left to build it from.
        $this->assertSame('https://deckwp.com', $notice['platform_url']);
    }

    public function test_does_not_stash_a_banner_for_a_disconnect_the_operator_just_clicked(): void
    {
        $this->givenPairedSite();

        (new Teardown())->run('admin_disconnect', false);

        $this->assertFalse(get_transient(Teardown::NOTICE_KEY));
    }

    public function test_reports_what_it_found_before_clearing_it(): void
    {
        $this->givenPairedSite();

        $result = (new Teardown())->run('dashboard_unpair');

        $this->assertTrue($result['was_paired']);
        $this->assertSame('site_01JABC', $result['site_id']);
        $this->assertSame('https://deckwp.com', $result['platform_url']);
    }

    public function test_tearing_down_an_unpaired_site_is_a_harmless_no_op(): void
    {
        $result = (new Teardown())->run('dashboard_unpair');

        $this->assertFalse($result['was_paired']);
        // No banner: nothing was connected, so there is nothing to
        // explain to the operator.
        $this->assertFalse(get_transient(Teardown::NOTICE_KEY));
    }

    public function test_running_it_twice_leaves_the_same_state(): void
    {
        $this->givenPairedSite();
        wpStubScheduleEvent(HeartbeatScheduler::HOOK);

        $teardown = new Teardown();
        $teardown->run('dashboard_unpair');
        $first = (new Settings())->all();

        $teardown->run('dashboard_unpair');

        $this->assertSame($first, (new Settings())->all());
        $this->assertFalse(wp_next_scheduled(HeartbeatScheduler::HOOK));
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
