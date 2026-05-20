<?php

namespace DeckWP\Connect\SiteHealth;

defined('ABSPATH') || exit;

/**
 * Wraps WordPress core's `WP_Site_Health` to produce a connector-
 * serialized snapshot of every registered Site Health check.
 *
 * ## Why this exists
 *
 * Site Health is a WP 5.2+ feature that surfaces 20+ checks across
 * security, performance, plugin/theme freshness, and infrastructure
 * (HTTPS, REST availability, dotorg communication, etc.). The
 * dashboard's `/sites/{id}/health-checks` tab wants to render those
 * checks remotely without the operator having to log into wp-admin
 * to see them. This service runs `WP_Site_Health` server-side and
 * returns a flat payload the dashboard can store + render.
 *
 * ## What we run
 *
 * `WP_Site_Health::get_tests()` returns the full registered test set
 * — both core and plugin-registered (via the `site_status_tests`
 * filter). The set has two buckets:
 *
 *   - `direct`: synchronous tests, `test` field is a method-suffix
 *     string (e.g. `"wordpress_version"` → `get_test_wordpress_version`).
 *   - `async`: tests WP normally runs via JS-fired REST calls, `test`
 *     field is the REST URL string. The backing method still lives on
 *     `WP_Site_Health` (or on a registering plugin's class).
 *
 * We attempt both buckets inline:
 *
 *   - Direct tests: invoke `get_test_<key>` on the singleton.
 *   - Async tests: derive the method name from the URL's last path
 *     segment (dasherized → underscored), invoke if it exists.
 *     Plugin-registered async tests whose backing method isn't on
 *     the singleton are skipped (we can't replay arbitrary plugin
 *     REST routes from here without spinning a sub-request).
 *
 * Each test result is wrapped in try/catch — a single broken test
 * doesn't abort the snapshot. Failing tests are emitted as
 * `status: 'error'` entries so the dashboard surfaces them
 * explicitly rather than silently dropping coverage.
 *
 * ## HTML stripping
 *
 * Core tests emit HTML in `description` and `actions` (links,
 * `<strong>`, etc.). We `wp_strip_all_tags()` both — the dashboard's
 * health-checks blade renders plain text, and accepting arbitrary
 * HTML over the wire only to escape it client-side is wasted
 * bandwidth + an XSS landmine. Plugins that want rich rendering on
 * the dashboard side can use the `actions` field for a follow-up
 * URL (rendered as a link by the blade); body copy stays plain.
 *
 * ## Timeout posture
 *
 * Per-test cost is dominated by the tests that touch the network
 * (dotorg communication, loopback, REST availability). Worst-case
 * sequential cost is ~30-45s on a healthy install. The connector's
 * route caller (SiteHealthRoute) sets `set_time_limit(60)` to give
 * a 60s ceiling matched to the dashboard's outbound HTTP timeout.
 * Network-dependent tests that hang past their internal timeout
 * still return a failure result without blocking the rest of the
 * sweep — the catch around each call handles the edge.
 */
class Runner
{
    /**
     * Execute every registered Site Health test and return a
     * serializable result envelope.
     *
     * @return array{
     *   sent_at:int,
     *   wp_version:string,
     *   php_version:string,
     *   summary:array{good:int,recommended:int,critical:int,error:int},
     *   checks:array<int, array<string, mixed>>
     * }
     */
    public function run(): array
    {
        // Load WP_Site_Health itself.
        if (! class_exists('WP_Site_Health')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        }

        // WP_Site_Health's individual test methods call into utility
        // functions that live in wp-admin/includes/ — `get_core_updates`,
        // `get_plugin_updates`, `get_theme_updates`, `wp_check_php_version`,
        // `get_mu_plugins`, etc. Those files aren't auto-loaded on
        // REST requests (which is what we're in here), so without
        // these requires the test methods throw "Call to undefined
        // function" — surfacing as status=error rows on the dashboard's
        // Health tab. Loading them up-front turns those rows into
        // proper good/recommended/critical classifications.
        //
        // Each require_once is idempotent + cheap (file_exists check
        // happens inside WP's own loader). Listed in the order they
        // appear in WP_Site_Health's call graph so the next failing
        // test surfaced by operator testing slots in naturally.
        foreach ([
            'wp-admin/includes/update.php',         // get_core_updates, get_plugin_updates, get_theme_updates
            'wp-admin/includes/misc.php',           // wp_check_php_version
            'wp-admin/includes/plugin.php',         // get_plugins, get_mu_plugins, is_plugin_active
            'wp-admin/includes/theme.php',          // wp_get_themes deps
            'wp-admin/includes/file.php',           // WP_Filesystem for some tests
            'wp-admin/includes/class-wp-debug-data.php', // background data class used by debug-info test
        ] as $relativePath) {
            $fullPath = ABSPATH . $relativePath;
            if (is_readable($fullPath)) {
                require_once $fullPath;
            }
        }

        $siteHealth = \WP_Site_Health::get_instance();
        $tests = \WP_Site_Health::get_tests();

        $results = [];

        foreach (['direct', 'async'] as $category) {
            $bucket = isset($tests[$category]) && is_array($tests[$category]) ? $tests[$category] : [];
            foreach ($bucket as $key => $test) {
                $row = $this->runOne($siteHealth, (string) $category, (string) $key, (array) $test);
                if ($row !== null) {
                    $results[] = $row;
                }
            }
        }

        $summary = [
            'good'        => 0,
            'recommended' => 0,
            'critical'    => 0,
            'error'       => 0,
        ];
        foreach ($results as $r) {
            $status = (string) $r['status'];
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        global $wp_version;

        return [
            'sent_at'     => time(),
            'wp_version'  => isset($wp_version) ? (string) $wp_version : 'unknown',
            'php_version' => PHP_VERSION,
            'summary'     => $summary,
            'checks'      => $results,
        ];
    }

    /**
     * Run one test with isolation. Returns null when we can't find
     * a backing method (silent skip — typically plugin-registered
     * async tests whose REST callback isn't reachable from here).
     *
     * @param array<string, mixed> $test
     * @return array<string, mixed>|null
     */
    private function runOne(\WP_Site_Health $siteHealth, string $category, string $key, array $test): ?array
    {
        $callable = $test['test'] ?? null;
        $label = (string) ($test['label'] ?? $key);

        try {
            $result = $this->invoke($siteHealth, $callable);
            if (! is_array($result)) {
                return null;
            }
            return $this->normalize($result, $key, $category, $label);
        } catch (\Throwable $e) {
            return [
                'test'        => $key,
                'category'    => $category,
                'label'       => $label,
                'status'      => 'error',
                'badge_label' => 'Error',
                'badge_color' => 'red',
                'description' => 'Test threw an exception: ' . $e->getMessage(),
                'actions'     => '',
            ];
        }
    }

    /**
     * Resolve and invoke the test callable. Two paths:
     *
     *   1. Direct tests — `test` field is a method-suffix string.
     *      We prepend `get_test_` and call on the singleton.
     *   2. Async tests — `test` field is a REST URL string. We
     *      parse out the last path segment, dasher-to-underscore
     *      it, prepend `get_test_`, and call on the singleton if
     *      the method exists. Plugin-registered async tests whose
     *      callback lives elsewhere fall through to null.
     *
     * @param mixed $callable
     * @return array<string, mixed>|null
     */
    private function invoke(\WP_Site_Health $siteHealth, $callable): ?array
    {
        // Closure / [obj, method] / FQN callable — try first since
        // these are explicit.
        if (is_array($callable) && is_callable($callable)) {
            return call_user_func($callable);
        }
        if ($callable instanceof \Closure) {
            return $callable();
        }

        if (! is_string($callable) || $callable === '') {
            return null;
        }

        // URL → async test → derive method from last path segment.
        if (filter_var($callable, FILTER_VALIDATE_URL)) {
            $path = parse_url($callable, PHP_URL_PATH);
            if (! is_string($path) || $path === '') {
                return null;
            }
            $segments = explode('/', trim($path, '/'));
            $slug = (string) end($segments);
            if ($slug === '') {
                return null;
            }
            $method = 'get_test_' . str_replace('-', '_', $slug);
            if (method_exists($siteHealth, $method)) {
                return (array) $siteHealth->{$method}();
            }
            return null;
        }

        // String → direct test → prepend `get_test_`.
        $method = 'get_test_' . $callable;
        if (method_exists($siteHealth, $method)) {
            return (array) $siteHealth->{$method}();
        }
        return null;
    }

    /**
     * Flatten the WP_Site_Health result shape into the wire payload.
     * Body copy goes through wp_strip_all_tags so the dashboard
     * doesn't have to render untrusted HTML.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function normalize(array $result, string $key, string $category, string $fallbackLabel): array
    {
        $status = (string) ($result['status'] ?? 'unknown');
        $badge = is_array($result['badge'] ?? null) ? $result['badge'] : [];

        return [
            'test'        => $key,
            'category'    => $category,
            'label'       => (string) ($result['label'] ?? $fallbackLabel),
            'status'      => $status,
            'badge_label' => (string) ($badge['label'] ?? ''),
            'badge_color' => (string) ($badge['color'] ?? ''),
            'description' => trim(wp_strip_all_tags((string) ($result['description'] ?? ''))),
            'actions'     => trim(wp_strip_all_tags((string) ($result['actions'] ?? ''))),
        ];
    }
}
