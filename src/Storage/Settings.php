<?php

namespace DeckWP\Connect\Storage;

defined('ABSPATH') || exit;

/**
 * Multisite-aware wrapper around the `deckwp_connect_settings` option.
 *
 * Single-site installs read/write `wp_options`; multisite installs go to
 * `wp_sitemeta` so the connection is shared across the network. Every
 * caller in the plugin should funnel through this class instead of
 * touching `get_option`/`get_site_option` directly — that way, when the
 * storage backend changes (encryption-at-rest, separate per-site rows,
 * etc.) we patch one place.
 *
 * ## autoload=false
 *
 * The activation hook in `deckwp-connect.php` adds the option with
 * autoload=false on purpose: the `hmac_secret` key would otherwise sit
 * in WP's always-loaded options cache and could leak via plugins that
 * dump `wp_load_alloptions()`. This wrapper preserves that — `update`
 * never touches autoload, and we never call `add_option` again.
 *
 * ## Schema (v0.1.0)
 *
 * Set by the activation hook (defaults):
 *   - site_id       string  Server-issued UUID once paired, '' before.
 *   - token         string  Locally-issued bootstrap token (Mode 2 reserve).
 *   - hmac_secret   string  Base64 secret returned by /api/v1/connect/pair.
 *                            Stored AS RECEIVED — base64 decode happens at
 *                            sign/verify time, not at storage time.
 *   - platform_url  string  Dashboard base URL (e.g. https://deckwp.com).
 *   - connected_at  string  Unix timestamp of last successful pair.
 *
 * Populated by the pair handshake (this file's responsibility starting v0.1.0):
 *   - team_slug         string  For display in the settings page header.
 *   - callback_url      string  Where the connector posts heartbeats/events.
 *   - heartbeat_seconds int     Cron interval, defaults to 300.
 *   - scan_seconds      int     Cron interval for scans, defaults to 86400.
 */
class Settings
{
    public const OPTION_KEY = 'deckwp_connect_settings';

    /**
     * Read the entire settings array. Always returns an array, even
     * when the option doesn't exist (rare — activation creates it).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if (function_exists('is_multisite') && is_multisite()) {
            return (array) get_site_option(self::OPTION_KEY, []);
        }

        return (array) get_option(self::OPTION_KEY, []);
    }

    /**
     * Read a single key with optional default.
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * Merge a patch into the settings option. Keys not present in the
     * patch are preserved untouched.
     *
     * @param array<string, mixed> $patch
     */
    public function update(array $patch): bool
    {
        $merged = array_merge($this->all(), $patch);

        if (function_exists('is_multisite') && is_multisite()) {
            return (bool) update_site_option(self::OPTION_KEY, $merged);
        }

        return (bool) update_option(self::OPTION_KEY, $merged);
    }

    /**
     * Reset the connection-specific keys to empty strings (used by the
     * Disconnect button). The locally-generated `token` is preserved
     * so re-pairing the same install doesn't churn it.
     */
    public function clearConnection(): bool
    {
        return $this->update([
            'site_id'           => '',
            'hmac_secret'       => '',
            'platform_url'      => '',
            'connected_at'      => '',
            'team_slug'         => '',
            'callback_url'      => '',
            'heartbeat_seconds' => 0,
            'scan_seconds'      => 0,
        ]);
    }

    /**
     * Convenience predicate: true when we have everything we need to
     * sign requests against the dashboard.
     */
    public function isPaired(): bool
    {
        $all = $this->all();

        return ! empty($all['site_id'])
            && ! empty($all['hmac_secret'])
            && ! empty($all['platform_url']);
    }
}
