<?php

namespace DeckWP\Connect\Updater;

defined('ABSPATH') || exit;

use DeckWP\Connect\HMAC\Signer;
use DeckWP\Connect\Storage\Settings;

/**
 * Pulls the connector's own latest release metadata from the
 * dashboard and offers it to WordPress' built-in update flow.
 *
 * ## Why
 *
 * Without this, shipping v0.13.0 means every operator has to
 * manually download the zip and re-install on every site they own.
 * Bloqueia distribuição massiva.
 *
 * With this:
 *
 *   1. WP cron refreshes `update_plugins` site transient
 *      (default every 12h on most installs; immediately when an
 *      admin visits the Plugins page).
 *   2. Our `pre_set_site_transient_update_plugins` filter polls
 *      `GET /api/v1/sites/{site_id}/connector/latest` on the
 *      dashboard, HMAC-signed.
 *   3. If the response carries a version newer than what's
 *      installed, we inject an "update available" entry into the
 *      transient under our plugin path.
 *   4. The operator clicks Update on the WP admin Plugins page,
 *      WP downloads the zip from the URL we returned, replaces
 *      the plugin folder atomically (`Plugin_Upgrader::upgrade`
 *      with `clear_destination=true`).
 *
 * ## Cache
 *
 * Result of the dashboard poll is cached in a 1-hour transient
 * (`deckwp_connect_self_update_check`). The dashboard caches its
 * GitHub poll for 1h too, so even hundreds of sites poll-storming
 * at once won't pile on the GitHub rate limit.
 *
 * On a transport / 4xx / 5xx failure, we cache `false` for 5
 * minutes — short enough to recover quickly when the dashboard
 * comes back, long enough to avoid hammering a broken endpoint.
 *
 * ## Bypass for the dashboard's own /install-batch flow
 *
 * The Updater\UpdateSuppressor strips managed slugs from the
 * update_plugins transient. The connector itself is technically
 * self-managed, but the dashboard never lists it in its
 * `deckwp_managed_slugs` option (the operator can't manage the
 * connector via /install-batch — that would be a self-upgrade
 * loop). So no special bypass is needed here.
 *
 * ## What this class does NOT do
 *
 *   - Auto-upgrade on its own (no scheduled call to
 *     Plugin_Upgrader). The operator stays in the loop — clicks
 *     Update on the WP admin like any other plugin.
 *   - Verify the downloaded zip's authenticity. Trust is rooted
 *     in HTTPS to the dashboard (our HMAC-signed endpoint) and
 *     HTTPS from the dashboard to GitHub (Releases zip URL).
 *     A stronger posture (sigstore-style attestations) is a v1.1
 *     hardening pass.
 */
class SelfUpdater
{
    /** Plugin path relative to wp-content/plugins/. */
    public const PLUGIN_FILE = 'deckwp-connect/deckwp-connect.php';

    /** Transient holding the cached "latest version" envelope. */
    public const TRANSIENT_OK   = 'deckwp_connect_self_update_check';

    /** Transient holding the "last poll failed" flag (negative cache). */
    public const TRANSIENT_FAIL = 'deckwp_connect_self_update_failed';

    public const TTL_OK_SECONDS   = HOUR_IN_SECONDS;
    public const TTL_FAIL_SECONDS = 300;

    /** @var Signer */
    private $signer;

    /** @var Settings */
    private $settings;

    public function __construct(Signer $signer = null, Settings $settings = null)
    {
        $this->signer   = $signer   ?? new Signer();
        $this->settings = $settings ?? new Settings();
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'injectUpdateOffer'], 10, 1);
    }

    /**
     * Filter callback. Adds an entry under our plugin path to the
     * `response` array when a newer version is available.
     *
     * @param  mixed $transient stdClass | false (when WP forces a refresh)
     * @return mixed
     */
    public function injectUpdateOffer($transient)
    {
        if (! is_object($transient)) {
            return $transient;
        }

        $info = $this->fetchLatestEnvelope();
        if (! is_array($info) || empty($info['version']) || empty($info['download_url'])) {
            return $transient;
        }

        $localVersion = $this->getLocalPluginVersion();
        if ($localVersion === '' || version_compare($info['version'], $localVersion, '<=')) {
            // Local is at-or-above what the dashboard is offering.
            return $transient;
        }

        if (! isset($transient->response) || ! is_array($transient->response)) {
            $transient->response = [];
        }

        $transient->response[self::PLUGIN_FILE] = (object) [
            'slug'         => 'deckwp-connect',
            'plugin'       => self::PLUGIN_FILE,
            'new_version'  => (string) $info['version'],
            'package'      => (string) $info['download_url'],
            'url'          => (string) ($info['changelog_url'] ?? 'https://deckwp.com'),
            'tested'       => (string) ($info['tested_wp'] ?? ''),
            'requires_php' => (string) ($info['requires_php'] ?? '7.4'),
            'icons'        => [],
            'banners'      => [],
        ];

        return $transient;
    }

    /**
     * Read-through cache for the dashboard poll. Returns null on
     * failure (caller decides what to do — typically: pass through
     * unchanged so other plugins' updates still appear).
     *
     * @return array<string, mixed>|null
     */
    private function fetchLatestEnvelope(): ?array
    {
        $cached = get_site_transient(self::TRANSIENT_OK);
        if (is_array($cached)) {
            return $cached;
        }

        if (get_site_transient(self::TRANSIENT_FAIL)) {
            // Negative cache active — last attempt failed, stay
            // backed off for the remainder of the TTL.
            return null;
        }

        $envelope = $this->pollDashboard();
        if ($envelope === null) {
            set_site_transient(self::TRANSIENT_FAIL, 1, self::TTL_FAIL_SECONDS);
            return null;
        }

        set_site_transient(self::TRANSIENT_OK, $envelope, self::TTL_OK_SECONDS);
        return $envelope;
    }

    /**
     * Make the HMAC-signed HTTPS GET to the dashboard's
     * `connector/latest` endpoint. Returns null on any failure
     * (transport, 4xx, 5xx, malformed JSON).
     *
     * @return array<string, mixed>|null
     */
    private function pollDashboard(): ?array
    {
        $stored = $this->settings->get();
        $platformUrl = isset($stored['platform_url']) ? rtrim((string) $stored['platform_url'], '/') : '';
        $siteId = isset($stored['site_id']) ? (string) $stored['site_id'] : '';
        $secretBase64 = isset($stored['hmac_secret']) ? (string) $stored['hmac_secret'] : '';

        if ($platformUrl === '' || $siteId === '' || $secretBase64 === '') {
            return null; // not paired
        }

        $secretRaw = base64_decode($secretBase64, true);
        if ($secretRaw === false || $secretRaw === '') {
            return null;
        }

        $path = '/api/v1/sites/' . $siteId . '/connector/latest';
        $headers = $this->signer->sign('GET', $path, '', $secretRaw);

        $response = wp_remote_get($platformUrl . $path, [
            'timeout' => 8,
            'headers' => array_merge(
                ['Accept' => 'application/json'],
                $headers
            ),
            'sslverify' => $this->shouldVerifySsl(),
        ]);

        if (is_wp_error($response)) {
            error_log('[DeckWP Connect] SelfUpdater poll transport error: ' . $response->get_error_message());
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            error_log('[DeckWP Connect] SelfUpdater poll non-2xx: ' . $status);
            return null;
        }

        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || empty($decoded['version'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * Read the connector plugin's currently-installed version from
     * its main file header. Falls back to '' if WP's file API
     * isn't loaded (very early failure modes).
     */
    private function getLocalPluginVersion(): string
    {
        if (! function_exists('get_plugin_data')) {
            $adminFile = ABSPATH . 'wp-admin/includes/plugin.php';
            if (! is_readable($adminFile)) {
                return '';
            }
            require_once $adminFile;
        }

        if (! defined('WP_PLUGIN_DIR')) {
            return '';
        }

        $main = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
        if (! is_readable($main)) {
            return '';
        }

        $data = get_plugin_data($main, false, false);
        return is_array($data) && isset($data['Version']) ? (string) $data['Version'] : '';
    }

    /**
     * Mirror the heartbeat scheduler's SSL verification flag.
     * `DECKWP_CONNECT_SKIP_SSL_VERIFY` lets local dev hit Herd's
     * self-signed cert; production keeps verification on.
     */
    private function shouldVerifySsl(): bool
    {
        return ! (defined('DECKWP_CONNECT_SKIP_SSL_VERIFY') && DECKWP_CONNECT_SKIP_SSL_VERIFY === true);
    }
}
