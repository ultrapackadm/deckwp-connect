<?php

namespace DeckWP\Connect\Tests\Unit;

use DeckWP\Connect\Inventory\PluginInventory;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Telling "nothing to update" apart from "we couldn't ask".
 *
 * An end-to-end pass clicked Refresh on a fresh pairing and got
 * "All up to date" while the wp.org query was failing underneath.
 * The two situations produce identical output from WordPress:
 * `wp_update_plugins()` returns void either way, and an update
 * transient with no `response` means both "you're current" and "we
 * never got an answer".
 *
 * So the connector watches the request itself and reports what it
 * saw. These tests pin each outcome, because a wrong answer here is
 * worse than no answer: the operator stops checking.
 */
class PluginInventoryUpdateCheckTest extends TestCase
{
    private const CHECKED_AT = 1754300000;

    protected function setUp(): void
    {
        wpStubReset();
    }

    protected function tearDown(): void
    {
        wpStubReset();
    }

    public function test_reports_ok_when_the_poll_succeeds(): void
    {
        wpStubSetUpdateCheckResponse($this->httpStatus(200));
        $this->givenCachedUpdates([]);

        $check = $this->collectAndReadCheck();

        $this->assertTrue($check['ok']);
        $this->assertTrue($check['checked']);
        $this->assertNull($check['error']);
        $this->assertSame(self::CHECKED_AT, $check['last_checked']);
    }

    public function test_reports_the_failure_when_wp_org_is_unreachable(): void
    {
        // The exact shape the end-to-end pass hit: connection failure,
        // empty transient response, panel says "All up to date".
        wpStubSetUpdateCheckResponse(new WP_Error('http_request_failed', 'cURL error 6: Could not resolve host'));
        $this->givenCachedUpdates([]);

        $check = $this->collectAndReadCheck();

        $this->assertFalse($check['ok']);
        $this->assertTrue($check['checked']);
        $this->assertStringContainsString('Could not resolve host', (string) $check['error']);
    }

    public function test_reports_the_failure_when_wp_org_answers_with_an_error_status(): void
    {
        wpStubSetUpdateCheckResponse($this->httpStatus(503));
        $this->givenCachedUpdates([]);

        $check = $this->collectAndReadCheck();

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('503', (string) $check['error']);
    }

    public function test_a_successful_retry_clears_an_earlier_failure(): void
    {
        // Core retries the update check over plain HTTP when the HTTPS
        // attempt errors. Both attempts fire the hook we listen on; only
        // the last one describes the outcome of the poll as a whole.
        wpStubSetUpdateCheckResponses([
            new WP_Error('http_request_failed', 'SSL certificate problem'),
            $this->httpStatus(200),
        ]);
        $this->givenCachedUpdates([]);

        $check = $this->collectAndReadCheck();

        $this->assertTrue($check['ok']);
        $this->assertNull($check['error']);
    }

    public function test_ignores_requests_that_are_not_the_update_check(): void
    {
        // Sites make plenty of other HTTP calls, and some of them fail
        // for reasons that say nothing about wp.org. Only the
        // update-check endpoint counts. The failure goes last so a
        // connector that counted it would leave ok=false.
        wpStubSetUpdateCheckResponses([
            $this->httpStatus(200),
            wpStubHttpEvent(
                new WP_Error('http_request_failed', 'some webhook fell over'),
                'https://hooks.example.com/notify'
            ),
        ]);
        $this->givenCachedUpdates([]);

        $check = $this->collectAndReadCheck();

        $this->assertTrue($check['ok']);
        $this->assertNull($check['error']);
    }

    public function test_a_later_request_cannot_rewrite_the_result(): void
    {
        // The watcher is unhooked when the poll returns. Without that,
        // any update-check later in the same pageload (core runs one on
        // admin_init) would silently overwrite a real failure.
        wpStubSetUpdateCheckResponse(new WP_Error('http_request_failed', 'Connection timed out'));
        $this->givenCachedUpdates([]);

        $inventory = new PluginInventory();
        $inventory->collect();

        do_action('http_api_debug', $this->httpStatus(200), 'response', 'Curl', [], 'https://api.wordpress.org/plugins/update-check/1.1/');

        $this->assertFalse($inventory->lastUpdateCheck()['ok']);
    }

    public function test_treats_a_skipped_poll_as_fine(): void
    {
        // Cached data younger than core's threshold: no request goes out
        // at all. That's not a failure, and reporting it as one would
        // light up every dashboard on every heartbeat.
        wpStubSetUpdateCheckResponse(null);
        $this->givenCachedUpdates([]);

        $check = $this->collectAndReadCheck();

        $this->assertTrue($check['ok']);
        $this->assertFalse($check['checked']);
        $this->assertNull($check['error']);
    }

    public function test_reports_a_missing_transient_as_an_unanswered_question(): void
    {
        // No HTTP failure observed, but nothing cached either — we still
        // have no basis for claiming the site is up to date.
        wpStubSetUpdateCheckResponse(null);

        $check = $this->collectAndReadCheck();

        $this->assertFalse($check['ok']);
        $this->assertNotNull($check['error']);
    }

    public function test_still_reports_available_updates_when_the_check_worked(): void
    {
        // The guard must not cost us the thing it guards: a working
        // check with a real update still produces an update row.
        wpStubSetUpdateCheckResponse($this->httpStatus(200));
        wpStubSetPlugins([
            'contact-form-7/wp-contact-form-7.php' => ['Name' => 'Contact Form 7', 'Version' => '6.0'],
        ]);
        $this->givenCachedUpdates([
            'contact-form-7/wp-contact-form-7.php' => (object) ['new_version' => '6.1.6'],
        ]);

        $inventory = new PluginInventory();
        $rows = $inventory->collect();

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['update_available']);
        $this->assertSame('6.1.6', $rows[0]['new_version']);
        $this->assertTrue($inventory->lastUpdateCheck()['ok']);
    }

    /** @return array{ok: bool, checked: bool, error: string|null, last_checked: int|null} */
    private function collectAndReadCheck(): array
    {
        $inventory = new PluginInventory();
        $inventory->collect();

        return $inventory->lastUpdateCheck();
    }

    /** @param array<string, object> $response */
    private function givenCachedUpdates(array $response): void
    {
        wpStubSetSiteTransient('update_plugins', (object) [
            'response'     => $response,
            'last_checked' => self::CHECKED_AT,
        ]);
    }

    /** @return array<string, mixed> */
    private function httpStatus(int $code): array
    {
        return ['response' => ['code' => $code, 'message' => '']];
    }
}
