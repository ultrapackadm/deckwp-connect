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

// --- In-memory WP state --------------------------------------------------
$GLOBALS['__wp_options'] = [];
$GLOBALS['__wp_site_transients'] = [];
$GLOBALS['__wp_plugins'] = [];
$GLOBALS['__wp_filters'] = [];
$GLOBALS['__wp_file_data'] = [];
$GLOBALS['__wp_themes'] = [];

/** Reset all stub state — call in each test's setUp(). */
function wpStubReset(): void
{
    $GLOBALS['__wp_options'] = [];
    $GLOBALS['__wp_site_transients'] = [];
    $GLOBALS['__wp_plugins'] = [];
    $GLOBALS['__wp_filters'] = [];
    $GLOBALS['__wp_file_data'] = [];
    $GLOBALS['__wp_themes'] = [];
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
