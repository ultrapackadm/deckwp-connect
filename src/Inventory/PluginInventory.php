<?php

namespace DeckWP\Connect\Inventory;

use DeckWP\Connect\License\LicenseDetector;

defined('ABSPATH') || exit;

/**
 * Collects the local WordPress plugin inventory for the heartbeat payload.
 *
 * Each row mirrors the shape the dashboard's PluginInstallation model
 * expects to upsert against:
 *
 *     [
 *         'slug'             => 'akismet',           // dirname or single-file name
 *         'wporg_slug'       => 'akismet',           // null when not a directory plugin
 *         'plugin_file'      => 'akismet/akismet.php',
 *         'name'             => 'Akismet Anti-spam: Spam Protection',
 *         'version'          => '5.3.4',
 *         'active'           => true,
 *         'update_available' => false,
 *         'new_version'      => null,                // populated when update available
 *     ]
 *
 * `slug` is the canonical key the dashboard joins on (matches
 * `plugins.slug`). For multi-file plugins WP packages it as
 * `<slug>/<slug>.php` or `<slug>/<main>.php`; we use `dirname` to extract
 * the directory name. Single-file plugins (rare — `hello.php` is the
 * canonical example) get the basename without `.php`.
 *
 * `wporg_slug` is a different thing and the two must not be confused:
 * it is the slug wordpress.org itself uses, which is the only one that
 * resolves to a downloadable artifact. See {@see self::wpOrgSlugFor()}
 * for the cases where they diverge and what that broke.
 *
 * Update detection reads WP's `update_plugins` site transient — the same
 * source the WP admin "Updates" screen uses. Doesn't trigger a fresh
 * check (that's `wp_update_plugins()`); reads whatever is cached.
 */
class PluginInventory
{
    /** @var LicenseDetector */
    private $licenseDetector;

    /**
     * Outcome of the most recent wp.org poll performed by
     * {@see self::updatePayload()}. See {@see self::lastUpdateCheck()}.
     *
     * @var array{ok: bool, checked: bool, error: string|null, last_checked: int|null}
     */
    private $lastUpdateCheck = [
        'ok'           => true,
        'checked'      => false,
        'error'        => null,
        'last_checked' => null,
    ];

    /**
     * Plugin file → wordpress.org directory slug, for every plugin
     * WordPress was able to match against the directory. Filled as a
     * side effect of {@see self::updatePayload()}; see
     * {@see self::wpOrgSlugFor()} for why it exists.
     *
     * @var array<string, string>
     */
    private $wpOrgSlugs = [];

    public function __construct(?LicenseDetector $licenseDetector = null)
    {
        $this->licenseDetector = $licenseDetector ?? new LicenseDetector();
    }

    /**
     * Did the wp.org update check behind the last {@see self::collect()}
     * actually work?
     *
     * The dashboard needs this to avoid the failure mode an end-to-end
     * pass caught: wp.org was unreachable, the update transient came
     * back with an empty `response`, and the panel rendered that as
     * "All up to date" — the same words it uses when a site genuinely
     * has nothing to update. An unanswered question and an answer of
     * "nothing" are not the same thing, and the operator has no way to
     * tell them apart unless we say so.
     *
     *   - `ok`      — false only when we watched a poll fail. Cached
     *                 data that we never had to refresh is `true`.
     *   - `checked` — whether a poll actually went out this run.
     *                 `wp_update_plugins()` is a no-op on fresh data.
     *   - `error`   — human-readable reason, when there is one.
     *   - `last_checked` — the transient's own timestamp, so the
     *                 dashboard can say how stale the answer is.
     *
     * @return array{ok: bool, checked: bool, error: string|null, last_checked: int|null}
     */
    public function lastUpdateCheck(): array
    {
        return $this->lastUpdateCheck;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function collect(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all = (array) get_plugins();
        $active = (array) get_option('active_plugins', []);
        // updatePayload() refreshes + unlocks the update_plugins transient
        // FIRST, so the license detector's transient signal (read below)
        // sees the raw response including managed slugs.
        $updates = $this->updatePayload();

        $rows = [];
        foreach ($all as $file => $data) {
            $slug = $this->slugFor((string) $file);
            $license = $this->licenseDetector->detect($slug, 'plugin');

            $rows[] = [
                'slug'             => $slug,
                'wporg_slug'       => $this->wpOrgSlugFor((string) $file),
                'plugin_file'      => (string) $file,
                'name'             => isset($data['Name']) ? (string) $data['Name'] : '',
                'version'          => isset($data['Version']) ? (string) $data['Version'] : '',
                'active'           => in_array($file, $active, true),
                'update_available' => isset($updates[$file]),
                'new_version'      => isset($updates[$file]['new_version'])
                    ? (string) $updates[$file]['new_version']
                    : null,
                'license_state'    => $license['state'],
                'license_provider' => $license['provider'],
            ];
        }

        return $rows;
    }

    /**
     * Convert WP's plugin-file identifier into a stable slug. WP's id is
     * relative to wp-content/plugins/, e.g.:
     *
     *   akismet/akismet.php          → "akismet"
     *   classic-editor/classic-editor.php → "classic-editor"
     *   hello.php                    → "hello"
     */
    private function slugFor(string $file): string
    {
        $dir = dirname($file);
        if ($dir === '.' || $dir === '') {
            return basename($file, '.php');
        }

        return $dir;
    }

    /**
     * Pull the plugin update payload out of the WP site transient.
     * Returns a map keyed by the same plugin-file identifier WP uses for
     * `get_plugins()`.
     *
     * Behavior:
     *
     *   1. If the transient is missing/stale (older than 6 hours OR
     *      response not yet populated), force a wp.org poll via
     *      `wp_update_plugins()`. That's the same call WP cron runs
     *      every 12 hours, but on-demand. Returns nothing — populates
     *      the transient as a side effect.
     *   2. If `Updater\UpdateSuppressor` is active on this install, its
     *      `site_transient_update_plugins` filter strips managed slugs
     *      from the response before we read it. That's correct for the
     *      WP admin Updates page (we don't want operators
     *      double-pressing Update there) but wrong for inventory
     *      reporting — the dashboard NEEDS to know which plugins have
     *      updates so it can surface the per-row "Update" button.
     *      The bypass constant makes the filter pass-through for the
     *      duration of this read.
     *
     * Both fixes are required to surface "12 updates available" in
     * the dashboard for sites with an under-firing wp-cron and/or any
     * managed_slugs configured.
     *
     * @return array<string, array<string, mixed>>
     */
    private function updatePayload(): array
    {
        // 1. Set the UpdateSuppressor bypass FIRST. wp_update_plugins()
        // internally calls get_site_transient(), which fires the
        // `site_transient_update_plugins` filter — without the bypass
        // set up front, that internal read would see the filtered
        // (stripped) response, and any managed slugs would never make
        // it into the freshness comparison.
        if (! defined('DECKWP_CONNECT_ALLOW_MANAGED_UPDATES')) {
            define('DECKWP_CONNECT_ALLOW_MANAGED_UPDATES', true);
        }

        // 2. Ensure the transient is fresh. wp_update_plugins() is a
        // no-op when cached data is < 1h old; otherwise it polls
        // wp.org and re-populates the transient. Making the call
        // here costs ~200ms-1s once per inventory pull, but ensures
        // operators don't see stale "0 outdated" when wp-cron isn't
        // firing reliably (DISABLE_WP_CRON=true without an external
        // cron is the common cause).
        //
        // Watch the poll while it happens. Core swallows a failed
        // update-check: `wp_update_plugins()` returns void either way
        // and the transient is simply left without a `response`, which
        // is byte-for-byte what "everything is current" looks like.
        // Listening on `http_api_debug` is the one place the two are
        // distinguishable.
        $this->lastUpdateCheck = [
            'ok'           => true,
            'checked'      => false,
            'error'        => null,
            'last_checked' => null,
        ];

        if (function_exists('wp_update_plugins')) {
            $watcher = $this->watchUpdateCheck();
            wp_update_plugins();
            $this->unwatchUpdateCheck($watcher);
        }

        // 3. Read the transient. UpdateSuppressor's hook still runs
        // on the read but checks the bypass constant we set above
        // and returns the raw response without stripping managed
        // slugs.
        $transient = get_site_transient('update_plugins');

        if (is_object($transient) && isset($transient->last_checked)) {
            $this->lastUpdateCheck['last_checked'] = (int) $transient->last_checked;
        }

        // 4. Harvest the directory slugs BEFORE the `response` guard
        // below, because the two buckets answer different questions.
        // A plugin that is perfectly up to date has no `response`
        // entry at all — it sits in `no_update` — and reading only
        // `response` is why a current plugin looked, to us, exactly
        // like a plugin wordpress.org has never heard of.
        $this->wpOrgSlugs = $this->harvestWpOrgSlugs($transient);

        if (! is_object($transient) || ! isset($transient->response) || ! is_array($transient->response)) {
            // No usable transient at all. If we didn't already catch a
            // failing HTTP call, this is still not an answer we can
            // report as "nothing to update".
            if ($this->lastUpdateCheck['ok']) {
                $this->lastUpdateCheck['ok'] = false;
                $this->lastUpdateCheck['error'] = 'WordPress has no plugin update data cached and the refresh produced none.';
            }

            return [];
        }

        $out = [];
        foreach ($transient->response as $file => $data) {
            $out[(string) $file] = is_object($data) ? (array) $data : (array) $data;
        }

        return $out;
    }

    /**
     * The wordpress.org directory slug for a plugin file, or null when
     * this install has no evidence the plugin comes from the directory.
     *
     * ## Why the local slug is not good enough
     *
     * {@see self::slugFor()} derives a slug from WordPress's own plugin
     * identifier, which is a *path*, not a directory slug. Most of the
     * time they coincide. When they don't, anything built on the
     * assumption breaks:
     *
     *   hello.php
     *       → local "hello", directory slug "hello-dolly"
     *   aawp/aawp/vendor/woocommerce/action-scheduler/action-scheduler.php
     *       → local "aawp/aawp/vendor/woocommerce/action-scheduler",
     *         directory slug "action-scheduler"
     *
     * Hello Dolly ships with every WordPress install, so the dashboard
     * was building a download URL that 404s on literally every site it
     * managed, then reporting the failure as an unexplained one.
     *
     * WordPress already knows the right answer: the update transient
     * carries the directory slug it matched each plugin to. This
     * reports that, and reports null when neither bucket mentions the
     * plugin — which is itself the useful signal that no wordpress.org
     * artifact exists to fetch.
     *
     * @param string $file WP's plugin identifier, e.g. `akismet/akismet.php`.
     *
     * @return string|null
     */
    private function wpOrgSlugFor($file)
    {
        return isset($this->wpOrgSlugs[$file]) ? $this->wpOrgSlugs[$file] : null;
    }

    /**
     * Build the plugin-file → directory-slug map from both buckets of
     * the update transient.
     *
     * `response` holds plugins with an update pending; `no_update`
     * holds the ones wordpress.org confirmed are current. Membership in
     * either is proof the plugin is a directory plugin — which bucket
     * it landed in says nothing about that, only about its version.
     *
     * @param mixed $transient The `update_plugins` site transient.
     *
     * @return array<string, string>
     */
    private function harvestWpOrgSlugs($transient)
    {
        $map = [];

        if (! is_object($transient)) {
            return $map;
        }

        foreach (['response', 'no_update'] as $bucket) {
            if (! isset($transient->$bucket) || ! is_array($transient->$bucket)) {
                continue;
            }

            foreach ($transient->$bucket as $file => $data) {
                $data = is_object($data) ? (array) $data : (array) $data;

                // Entries from third-party updaters routinely omit
                // `slug` (they only need `new_version` + `package`).
                // No slug means no directory claim, so leave the plugin
                // unmapped rather than inventing one.
                if (! isset($data['slug'])) {
                    continue;
                }

                $slug = trim((string) $data['slug']);
                if ($slug === '') {
                    continue;
                }

                $map[(string) $file] = $slug;
            }
        }

        return $map;
    }

    /**
     * Hook `http_api_debug` so we can see the update-check request's
     * outcome, and return the callable so it can be unhooked again.
     *
     * WordPress fires this for every HTTP request it makes, with the
     * raw response (or `WP_Error`) as the first argument. We only care
     * about calls to the plugin update-check endpoint, so anything
     * else the site does mid-poll is ignored.
     *
     * @return callable|null Null when the hook API isn't available.
     */
    private function watchUpdateCheck()
    {
        if (! function_exists('add_action')) {
            return null;
        }

        $watcher = function ($response, $context, $class, $args, $url = '') {
            if ($context !== 'response' || strpos((string) $url, '/plugins/update-check') === false) {
                return;
            }

            $this->lastUpdateCheck['checked'] = true;

            if (function_exists('is_wp_error') && is_wp_error($response)) {
                $this->lastUpdateCheck['ok'] = false;
                $this->lastUpdateCheck['error'] = sprintf(
                    'Could not reach WordPress.org to check for plugin updates: %s',
                    (string) $response->get_error_message()
                );

                return;
            }

            $code = 0;
            if (is_array($response) && isset($response['response']['code'])) {
                $code = (int) $response['response']['code'];
            }

            if ($code !== 200) {
                $this->lastUpdateCheck['ok'] = false;
                $this->lastUpdateCheck['error'] = sprintf(
                    'WordPress.org answered the plugin update check with HTTP %d.',
                    $code
                );

                return;
            }

            // A 200 after an earlier failure means core's HTTPS →
            // HTTP retry got through; the check as a whole worked.
            $this->lastUpdateCheck['ok'] = true;
            $this->lastUpdateCheck['error'] = null;
        };

        add_action('http_api_debug', $watcher, 10, 5);

        return $watcher;
    }

    /**
     * Unhook the watcher. Leaving it attached would make every
     * subsequent update-check on this pageload rewrite our result.
     *
     * @param  callable|null  $watcher
     */
    private function unwatchUpdateCheck($watcher): void
    {
        if ($watcher !== null && function_exists('remove_action')) {
            remove_action('http_api_debug', $watcher, 10);
        }
    }
}
