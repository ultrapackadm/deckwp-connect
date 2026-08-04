<?php

/**
 * PHPUnit bootstrap for the DeckWP connector.
 *
 * The connector runs inside WordPress, so its classes call WP functions
 * directly. Rather than boot a full WP test install, we stub the handful
 * of WP functions the unit-under-test touches, backed by in-memory arrays
 * a test can seed via wpStubReset()/wpStubSet*(). Keep stubs minimal and
 * grow them only as new units get coverage.
 */

error_reporting(E_ALL);

// --- Autoload: prefer composer, fall back to a PSR-4 map for src/ -------
$composer = __DIR__ . '/../vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
}
spl_autoload_register(function (string $class): void {
    $prefix = 'DeckWP\\Connect\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = __DIR__ . '/../src/' . $rel . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// --- WordPress constants -------------------------------------------------
defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');
defined('WP_PLUGIN_DIR') || define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/deckwp-connect-test-plugins');
// Scanner walks WP_CONTENT_DIR/{uploads,plugins,themes}. Constants can't
// be redefined per-test, so it points at one scratch root and each test
// builds (and tears down) the tree it needs underneath.
defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', sys_get_temp_dir() . '/deckwp-connect-test-content');

// --- In-memory WP state --------------------------------------------------
$GLOBALS['__wp_options'] = [];
$GLOBALS['__wp_site_transients'] = [];
$GLOBALS['__wp_plugins'] = [];
$GLOBALS['__wp_filters'] = [];
$GLOBALS['__wp_actions'] = [];
$GLOBALS['__wp_file_data'] = [];
$GLOBALS['__wp_themes'] = [];
$GLOBALS['__wp_transients'] = [];
$GLOBALS['__wp_cron'] = [];
$GLOBALS['__wp_update_check_responses'] = [];
$GLOBALS['__wp_active_plugins'] = [];
$GLOBALS['__wp_network_active_plugins'] = [];
$GLOBALS['__wp_activation_results'] = [];
$GLOBALS['__wp_activation_calls'] = [];

/** Reset all stub state — call in each test's setUp(). */
function wpStubReset(): void
{
    $GLOBALS['__wp_options'] = [];
    $GLOBALS['__wp_site_transients'] = [];
    $GLOBALS['__wp_plugins'] = [];
    $GLOBALS['__wp_filters'] = [];
    $GLOBALS['__wp_actions'] = [];
    $GLOBALS['__wp_file_data'] = [];
    $GLOBALS['__wp_themes'] = [];
    $GLOBALS['__wp_transients'] = [];
    $GLOBALS['__wp_cron'] = [];
    $GLOBALS['__wp_update_check_responses'] = [];
    $GLOBALS['__wp_active_plugins'] = [];
    $GLOBALS['__wp_network_active_plugins'] = [];
    $GLOBALS['__wp_activation_results'] = [];
    $GLOBALS['__wp_activation_calls'] = [];
}

/** Queue a cron event for the wp_next_scheduled()/wp_clear_scheduled_hook() stubs. */
function wpStubScheduleEvent(string $hook, ?int $timestamp = null): void
{
    $GLOBALS['__wp_cron'][$hook] = $timestamp ?? (time() + 300);
}

/**
 * Seed header data for a plugin file path (as get_file_data() reads it)
 * AND create the physical file so is_readable() passes. Returns the path.
 */
function wpStubSetPluginFileHeaders(string $file, array $headers): string
{
    $path = WP_PLUGIN_DIR . '/' . $file;
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    if (! is_file($path)) {
        file_put_contents($path, "<?php\n/* stub plugin */\n");
    }
    $GLOBALS['__wp_file_data'][$path] = $headers;

    return $path;
}

/** Register a theme slug as "installed" for the wp_get_theme() stub. */
function wpStubSetThemeExists(string $slug, bool $exists = true): void
{
    $GLOBALS['__wp_themes'][$slug] = $exists;
}

function wpStubSetOption(string $name, $value): void
{
    $GLOBALS['__wp_options'][$name] = $value;
}

function wpStubSetSiteTransient(string $name, $value): void
{
    $GLOBALS['__wp_site_transients'][$name] = $value;
}

/** @param array<string, array<string, mixed>> $plugins keyed by plugin file */
function wpStubSetPlugins(array $plugins): void
{
    $GLOBALS['__wp_plugins'] = $plugins;
}

function wpStubAddFilter(string $tag, callable $cb): void
{
    $GLOBALS['__wp_filters'][$tag][] = $cb;
}

/**
 * Decide what the `wp_update_plugins()` stub does when called.
 *
 * `$response` is what core's HTTP layer would hand `http_api_debug`:
 * a WP_Error, or an array like `['response' => ['code' => 200]]`.
 * Pass null for "the poll was skipped because the cache was fresh".
 *
 * @param mixed $response
 */
function wpStubSetUpdateCheckResponse($response): void
{
    wpStubSetUpdateCheckResponses($response === null ? [] : [$response]);
}

/**
 * Same, but for a poll that makes more than one request — core retries
 * the update check over plain HTTP when the HTTPS attempt errors, and
 * both attempts fire `http_api_debug`. Responses fire in order.
 *
 * @param array<int, mixed> $responses
 */
function wpStubSetUpdateCheckResponses(array $responses): void
{
    $GLOBALS['__wp_update_check_responses'] = array_values($responses);
}

/**
 * Wrap a queued response so it fires against a different URL. Sites make
 * plenty of HTTP calls that aren't the update check, and the connector
 * has to ignore them; this is how a test puts one in the middle of a poll.
 *
 * @param mixed $response
 * @return array<string, mixed>
 */
function wpStubHttpEvent($response, string $url): array
{
    return ['__stub_http_event' => true, 'response' => $response, 'url' => $url];
}

/**
 * Seed the active-plugin set.
 *
 * @param array<int, string> $active        Plugin files that are active.
 * @param array<int, string> $networkActive Subset that is network-active.
 */
function wpStubSetActivePlugins(array $active, array $networkActive = []): void
{
    $GLOBALS['__wp_active_plugins'] = array_values($active);
    $GLOBALS['__wp_network_active_plugins'] = array_values($networkActive);
}

/**
 * Decide what `activate_plugin()` does for a given plugin file.
 *
 * Three outcomes matter, because the connector distinguishes them:
 *   - `true`      activation takes (the plugin joins the active set)
 *   - a WP_Error  activation is refused, message surfaces to the dashboard
 *   - `'noop'`    returns null and the plugin STAYS inactive — WordPress's
 *                 nastiest shape, since "no error" reads as success
 *
 * @param mixed $result
 */
function wpStubSetActivationResult(string $pluginFile, $result): void
{
    $GLOBALS['__wp_activation_results'][$pluginFile] = $result;
}

/**
 * Every `activate_plugin()` call, in order, with the arguments it got.
 *
 * The connector reactivates SILENTLY and preserves network scope on
 * purpose (see Installer::restoreActiveState) — both are contract, not
 * detail, so tests need to see the arguments and not just the outcome.
 *
 * @return array<int, array{file: string, network_wide: bool, silent: bool}>
 */
function wpStubActivationCalls(): array
{
    return $GLOBALS['__wp_activation_calls'];
}

// --- WP function stubs (only defined if WP itself isn't loaded) ----------
if (! function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return $GLOBALS['__wp_options'][$name] ?? $default;
    }
}

if (! function_exists('get_site_transient')) {
    function get_site_transient($name)
    {
        return $GLOBALS['__wp_site_transients'][$name] ?? false;
    }
}

if (! function_exists('get_plugins')) {
    function get_plugins()
    {
        return $GLOBALS['__wp_plugins'];
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args)
    {
        foreach ($GLOBALS['__wp_filters'][$tag] ?? [] as $cb) {
            $value = $cb($value, ...$args);
        }

        return $value;
    }
}

if (! function_exists('get_file_data')) {
    function get_file_data($file, $headers, $context = '')
    {
        // Tests that need header data seed __wp_file_data[$file]; default empty.
        $data = $GLOBALS['__wp_file_data'][$file] ?? [];
        $out = [];
        foreach ($headers as $key => $label) {
            $out[$key] = $data[$key] ?? '';
        }

        return $out;
    }
}

// Actions get their own registry (they don't return a filtered value).
// Priority is tracked so remove_action() can match the pair it was
// added with — the connector unhooks its own callbacks.
if (! function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['__wp_actions'][$tag][$priority][] = $callback;

        return true;
    }
}

if (! function_exists('remove_action')) {
    function remove_action($tag, $callback, $priority = 10)
    {
        foreach ($GLOBALS['__wp_actions'][$tag][$priority] ?? [] as $index => $registered) {
            if ($registered === $callback) {
                unset($GLOBALS['__wp_actions'][$tag][$priority][$index]);

                return true;
            }
        }

        return false;
    }
}

if (! function_exists('do_action')) {
    function do_action($tag, ...$args)
    {
        $byPriority = $GLOBALS['__wp_actions'][$tag] ?? [];
        ksort($byPriority);
        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as $callback) {
                $callback(...$args);
            }
        }
    }
}

// Minimal WP_Error: only the two methods the connector calls on one.
if (! class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var string */
        private $code;

        /** @var string */
        private $message;

        public function __construct($code = '', $message = '')
        {
            $this->code = (string) $code;
            $this->message = (string) $message;
        }

        public function get_error_code()
        {
            return $this->code;
        }

        public function get_error_message()
        {
            return $this->message;
        }
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }
}

if (! function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['__wp_options'][$name] = $value;

        return true;
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}

// Single-site: core's *_site_option pair falls back to the regular
// options table, so one store is enough here. A multisite-specific
// test would need its own.
if (! function_exists('get_site_option')) {
    function get_site_option($name, $default = false)
    {
        return $GLOBALS['__wp_options'][$name] ?? $default;
    }
}

if (! function_exists('update_site_option')) {
    function update_site_option($name, $value)
    {
        $GLOBALS['__wp_options'][$name] = $value;

        return true;
    }
}

// Stands in for core's wp.org poll. Core fires `http_api_debug` for
// the update-check request and then populates the transient; the stub
// fires the same action with whatever the test seeded, which is the
// only part PluginInventory watches.
if (! function_exists('wp_update_plugins')) {
    function wp_update_plugins($extra_stats = [])
    {
        // No queued response = cache was fresh, core makes no request.
        foreach ($GLOBALS['__wp_update_check_responses'] as $entry) {
            $url = 'https://api.wordpress.org/plugins/update-check/1.1/';
            $response = $entry;

            if (is_array($entry) && isset($entry['__stub_http_event'])) {
                $url = (string) $entry['url'];
                $response = $entry['response'];
            }

            do_action(
                'http_api_debug',
                $response,
                'response',
                'WpOrg\Requests\Transport\Curl',
                [],
                $url
            );
        }
    }
}

if (! function_exists('is_multisite')) {
    function is_multisite()
    {
        return false;
    }
}

if (! function_exists('get_site_url')) {
    function get_site_url($blog_id = null, $path = '', $scheme = null)
    {
        return 'https://example-site.test' . $path;
    }
}

if (! function_exists('get_rest_url')) {
    function get_rest_url($blog_id = null, $path = '/', $scheme = 'rest')
    {
        return 'https://example-site.test/wp-json' . $path;
    }
}

// Transients: the connector only ever needs set/get/delete with a TTL
// it doesn't inspect, so the stub ignores expiry. A test that needs
// expiry semantics should assert on the stored value instead.
if (! function_exists('set_transient')) {
    function set_transient($name, $value, $expiration = 0)
    {
        $GLOBALS['__wp_transients'][$name] = $value;

        return true;
    }
}

if (! function_exists('get_transient')) {
    function get_transient($name)
    {
        return $GLOBALS['__wp_transients'][$name] ?? false;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient($name)
    {
        $existed = array_key_exists($name, $GLOBALS['__wp_transients']);
        unset($GLOBALS['__wp_transients'][$name]);

        return $existed;
    }
}

// WP-Cron: one queued timestamp per hook is enough — the connector
// schedules each of its two hooks without args, and never more than once.
if (! function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = [])
    {
        return $GLOBALS['__wp_cron'][$hook] ?? false;
    }
}

if (! function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook, $args = [])
    {
        $existed = array_key_exists($hook, $GLOBALS['__wp_cron']);
        unset($GLOBALS['__wp_cron'][$hook]);

        return $existed ? 1 : 0;
    }
}

if (! function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = [], $wp_error = false)
    {
        // Core refuses to double-book a hook and returns false; the
        // connector relies on that being harmless, so mirror it.
        if (array_key_exists($hook, $GLOBALS['__wp_cron'])) {
            return false;
        }

        $GLOBALS['__wp_cron'][$hook] = (int) $timestamp;

        return true;
    }
}

if (! function_exists('is_plugin_active')) {
    function is_plugin_active($pluginFile)
    {
        return in_array($pluginFile, $GLOBALS['__wp_active_plugins'], true);
    }
}

if (! function_exists('is_plugin_active_for_network')) {
    function is_plugin_active_for_network($pluginFile)
    {
        return in_array($pluginFile, $GLOBALS['__wp_network_active_plugins'], true);
    }
}

if (! function_exists('activate_plugin')) {
    /**
     * Stub of core's `activate_plugin()`.
     *
     * Core returns null on success, null when the plugin was already
     * active (having done nothing), or a WP_Error. It never returns
     * true — so neither does this, and a test that seeds `true` is
     * asking for "the activation takes", not for a return value.
     */
    function activate_plugin($pluginFile, $redirect = '', $networkWide = false, $silent = false)
    {
        $GLOBALS['__wp_activation_calls'][] = [
            'file' => $pluginFile,
            'network_wide' => (bool) $networkWide,
            'silent' => (bool) $silent,
        ];

        // Already active: core short-circuits without touching state.
        if (in_array($pluginFile, $GLOBALS['__wp_active_plugins'], true)) {
            return null;
        }

        $outcome = isset($GLOBALS['__wp_activation_results'][$pluginFile])
            ? $GLOBALS['__wp_activation_results'][$pluginFile]
            : true;

        if (is_wp_error($outcome)) {
            return $outcome;
        }

        // 'noop' = returns clean and leaves the plugin inactive.
        if ($outcome !== 'noop') {
            $GLOBALS['__wp_active_plugins'][] = $pluginFile;
            if ($networkWide) {
                $GLOBALS['__wp_network_active_plugins'][] = $pluginFile;
            }
        }

        return null;
    }
}

if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text, $remove_breaks = false)
    {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
        $text = strip_tags((string) $text);
        if ($remove_breaks) {
            $text = preg_replace('/[\r\n\t ]+/', ' ', $text);
        }

        return trim($text);
    }
}

if (! function_exists('wp_get_theme')) {
    function wp_get_theme($slug = null)
    {
        return new class($slug) {
            private $slug;

            public function __construct($slug)
            {
                $this->slug = (string) $slug;
            }

            public function exists(): bool
            {
                return ! empty($GLOBALS['__wp_themes'][$this->slug]);
            }
        };
    }
}
