<?php
/**
 * DeckWP Connect — fatal-error-handler drop-in.
 *
 * Marker: DECKWP_FATAL_HANDLER_MARKER
 *
 * Lives at wp-content/fatal-error-handler.php after install. Detected
 * and managed by {@see \DeckWP\Connect\DropIn\Installer} in the
 * connector plugin.
 *
 * WordPress loads this file via `include` from
 * `wp_register_fatal_error_handler()` in wp-settings.php — outside
 * any plugin context. There is NO namespace, NO autoloader, NO use
 * of plugin classes. The file must be self-contained.
 *
 * Identification: the marker constant DECKWP_FATAL_HANDLER_MARKER and
 * the literal comment string above are what the Installer's
 * classifyExisting() greps for. If present, the drop-in is "ours" and
 * safe to overwrite. Absent → "foreign", do not touch (could be a
 * hosting provider's drop-in or another plugin's).
 *
 * ## Slice progression
 *
 *   ✅ Slice 1: install plumbing — DropIn\Installer + this file's
 *      skeleton, idempotent install on plugins_loaded, foreign-skip
 *      protection. handle() delegated to parent.
 *
 *   ✅ Slice 2 (this slice): single-site detection + auto-deactivate.
 *      Looks at $error['file'] from detect_error(), longest-prefix
 *      matches against get_option('active_plugins'), removes the
 *      culprit from the option, and appends an entry to the
 *      'deckwp_fatal_log' option (capped at 50). is_multisite() is
 *      explicitly skipped — that's Slice 3's job.
 *
 *   🚧 Slice 3: multisite — switch_to_blog loop across the network
 *      to identify which blog tripped the fatal, plus
 *      active_sitewide_plugins handling.
 *
 *   🚧 Slice 4: memory-exhaustion branch + branded 503 splash to
 *      replace WP's generic "experiencing technical difficulties".
 *
 * @package DeckWP\Connect
 */

defined('ABSPATH') || exit;

if (! defined('DECKWP_FATAL_HANDLER_MARKER')) {
    define('DECKWP_FATAL_HANDLER_MARKER', 'deckwp/connect:fatal-handler:1');
}

if (! defined('DECKWP_DROPIN_VERSION')) {
    // Bumped on every slice so the Installer's byte-equal compare
    // detects "ours but stale" and rewrites the file with the new
    // source. Slice-suffix is informational; the byte diff is what
    // actually triggers the upgrade.
    define('DECKWP_DROPIN_VERSION', '0.12.0-slice2');
}

if (! class_exists('WP_Fatal_Error_Handler')) {
    // Pre-WP-5.2 — no fatal handler API to extend. Fall back to a
    // no-op object with a `handle()` method so wp_register_fatal_error_handler()
    // accepts the return value but otherwise does nothing different
    // from native behavior.
    require_once ABSPATH . WPINC . '/class-wp-fatal-error-handler.php';
}

/**
 * Single-site fatal handler with longest-prefix culprit detection
 * and auto-deactivate (Slice 2).
 *
 * Multisite networks fall through to parent::handle() — Slice 3 adds
 * the switch_to_blog loop. Memory exhaustion is treated identically
 * to other E_ERRORs for now; Slice 4 adds the dedicated branch.
 */
class DeckWP_Fatal_Error_Handler extends WP_Fatal_Error_Handler
{
    /** Hard cap on the deckwp_fatal_log option. Older entries roll off. */
    const FATAL_LOG_CAP = 50;

    /** Option key for the rolling log. Read by the dashboard via REST. */
    const FATAL_LOG_OPTION = 'deckwp_fatal_log';

    /** Truncate stored error message at this many bytes (defends option size). */
    const MESSAGE_TRUNCATE = 1024;

    /**
     * Entry point called by core when the shutdown function detects a
     * fatal. We intentionally try/catch the entire detection branch:
     * a bug in our own code must NOT prevent parent::handle() from
     * showing the user-facing recovery page.
     */
    public function handle()
    {
        try {
            if (! is_multisite()) {
                $error = $this->detect_error();
                if (is_array($error) && ! empty($error['file'])) {
                    $this->deckwpRecordAndDeactivate($error);
                }
            }
            // Slice 3 hook: multisite identification will branch here.
            // Slice 4 hook: memory-exhaustion detection + branded splash
            // will branch here as well, before parent::handle().
        } catch (\Throwable $e) {
            // Last-ditch: log to error_log and continue. Never let our
            // bookkeeping crash the recovery page.
            error_log('[DeckWP Connect] Fatal-handler bookkeeping failed: ' . $e->getMessage());
        }

        parent::handle();
    }

    /**
     * Try to attribute the fatal to an active plugin. If a culprit is
     * found, deactivate it and append a structured entry to the log.
     * No-op (just logs without `plugin_path`) when the error file
     * lives outside any active plugin's directory.
     *
     * @param array $error Shape from WP_Fatal_Error_Handler::detect_error().
     */
    protected function deckwpRecordAndDeactivate(array $error)
    {
        $culprit     = $this->deckwpFindCulprit((string) $error['file']);
        $deactivated = false;

        if ($culprit !== null) {
            $deactivated = $this->deckwpDeactivatePlugin($culprit);
        }

        $message = isset($error['message']) ? (string) $error['message'] : '';
        if (strlen($message) > self::MESSAGE_TRUNCATE) {
            $message = substr($message, 0, self::MESSAGE_TRUNCATE) . '…';
        }

        $this->deckwpAppendLog([
            'ts'          => time(),
            'type'        => isset($error['type']) ? (int) $error['type'] : 0,
            'file'        => (string) $error['file'],
            'line'        => isset($error['line']) ? (int) $error['line'] : 0,
            'message'     => $message,
            'plugin_path' => $culprit,
            'deactivated' => $deactivated,
        ]);
    }

    /**
     * Longest-prefix match: given an absolute path that triggered the
     * fatal, find the active plugin whose directory contains it.
     *
     * Two cases for an entry in active_plugins:
     *   - 'slug/main.php' (standard) — match on the 'slug/' prefix
     *   - 'standalone.php' (Hello-Dolly pattern) — exact filename match
     *
     * Returns the matching active_plugins entry verbatim, or null if
     * the error file lives outside WP_PLUGIN_DIR or in an inactive
     * plugin's tree.
     *
     * @return string|null
     */
    protected function deckwpFindCulprit($errorFile)
    {
        if (! defined('WP_PLUGIN_DIR') || ! is_string($errorFile) || $errorFile === '') {
            return null;
        }

        // wp_normalize_path may not be available in extreme-early
        // failure modes; fall back to a manual normalize.
        $normalize = function ($path) {
            return function_exists('wp_normalize_path')
                ? wp_normalize_path($path)
                : str_replace('\\', '/', $path);
        };

        $pluginDir = rtrim($normalize(WP_PLUGIN_DIR), '/');
        $errorFile = $normalize($errorFile);

        if (strpos($errorFile, $pluginDir . '/') !== 0) {
            return null;
        }

        $relative = substr($errorFile, strlen($pluginDir) + 1);
        // $relative shape: 'slug/sub/file.php' OR 'single.php'

        $active    = (array) get_option('active_plugins', []);
        $bestMatch = null;
        $bestLen   = 0;

        foreach ($active as $pluginPath) {
            if (! is_string($pluginPath) || $pluginPath === '') {
                continue;
            }

            if (strpos($pluginPath, '/') === false) {
                // Standalone single-file plugin — must match exactly.
                if ($relative === $pluginPath) {
                    return $pluginPath;
                }
                continue;
            }

            $slugPrefix = strstr($pluginPath, '/', true) . '/';
            $slugLen    = strlen($slugPrefix);
            if (strpos($relative, $slugPrefix) === 0 && $slugLen > $bestLen) {
                $bestMatch = $pluginPath;
                $bestLen   = $slugLen;
            }
        }

        return $bestMatch;
    }

    /**
     * Remove a plugin from the active_plugins option. Returns true
     * when the option was actually modified, false if the plugin was
     * already inactive or update_option refused.
     *
     * @return bool
     */
    protected function deckwpDeactivatePlugin($pluginPath)
    {
        $active = (array) get_option('active_plugins', []);
        $idx    = array_search($pluginPath, $active, true);
        if ($idx === false) {
            return false;
        }

        unset($active[$idx]);
        return (bool) update_option('active_plugins', array_values($active));
    }

    /**
     * Append an entry to deckwp_fatal_log, trimmed to the cap. The
     * option is stored with autoload=false because the dashboard
     * pulls it on demand and we don't want it loaded on every request.
     *
     * @param array $entry Already shaped by the caller.
     */
    protected function deckwpAppendLog(array $entry)
    {
        $log   = (array) get_option(self::FATAL_LOG_OPTION, []);
        $log[] = $entry;

        if (count($log) > self::FATAL_LOG_CAP) {
            $log = array_slice($log, -self::FATAL_LOG_CAP);
        }

        update_option(self::FATAL_LOG_OPTION, $log, false);
    }
}

return new DeckWP_Fatal_Error_Handler();
