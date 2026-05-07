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
 *      protection.
 *
 *   ✅ Slice 2: single-site detection + auto-deactivate via
 *      longest-prefix-match against active_plugins, log to
 *      deckwp_fatal_log (capped 50).
 *
 *   ✅ Slice 3 (this slice): multisite — three-tier search across
 *      active_sitewide_plugins → current blog → switch_to_blog loop.
 *      Log storage moved to get_site_option/update_site_option so a
 *      single network-wide log feeds the dashboard regardless of
 *      which blog tripped the fatal. Log entry now carries `scope`
 *      ('single'|'network'|'blog'|'multisite' for the no-match case)
 *      and `blog_id` (for scope=blog). Closes the Manage-GPL gap on
 *      the comparison table ("Multisite fatal handler · ✓ full"
 *      vs Manage GPL "× skipped").
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
    define('DECKWP_DROPIN_VERSION', '0.12.0-slice3');
}

if (! class_exists('WP_Fatal_Error_Handler')) {
    // Pre-WP-5.2 — no fatal handler API to extend. Fall back to a
    // no-op object with a `handle()` method so wp_register_fatal_error_handler()
    // accepts the return value but otherwise does nothing different
    // from native behavior.
    require_once ABSPATH . WPINC . '/class-wp-fatal-error-handler.php';
}

/**
 * Multisite-aware fatal handler with longest-prefix culprit detection
 * and auto-deactivate.
 *
 * Single-site (Slice 2) remains the fast path — one option read, one
 * option write. Multisite (Slice 3) walks active_sitewide_plugins
 * first (network-active is the most common multisite shape), then
 * the current blog, then every other blog via switch_to_blog. Memory
 * exhaustion is treated identically to other E_ERRORs for now;
 * Slice 4 adds the dedicated branch + branded 503.
 */
class DeckWP_Fatal_Error_Handler extends WP_Fatal_Error_Handler
{
    /** Hard cap on the deckwp_fatal_log entries. Older entries roll off. */
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
            $error = $this->detect_error();
            if (is_array($error) && ! empty($error['file'])) {
                if (is_multisite()) {
                    $this->deckwpHandleMultisite($error);
                } else {
                    $this->deckwpHandleSingleSite($error);
                }
            }
            // Slice 4 hook: memory-exhaustion detection + branded splash
            // will branch ahead of parent::handle() once landed.
        } catch (\Throwable $e) {
            // Last-ditch: log to error_log and continue. Never let our
            // bookkeeping crash the recovery page.
            error_log('[DeckWP Connect] Fatal-handler bookkeeping failed: ' . $e->getMessage());
        }

        parent::handle();
    }

    /**
     * Single-site path: search active_plugins, deactivate, log.
     *
     * @param array $error Shape from WP_Fatal_Error_Handler::detect_error().
     */
    protected function deckwpHandleSingleSite(array $error)
    {
        $relative    = $this->deckwpRelativePluginPath((string) $error['file']);
        $culprit     = null;
        $deactivated = false;

        if ($relative !== null) {
            $active  = (array) get_option('active_plugins', []);
            $culprit = $this->deckwpLongestPrefixMatch($relative, $active);
            if ($culprit !== null) {
                $deactivated = $this->deckwpDeactivatePlugin($culprit);
            }
        }

        $this->deckwpAppendLog(
            $this->deckwpBuildLogEntry($error, $culprit, $deactivated, 'single')
        );
    }

    /**
     * Multisite path. Three-tier search:
     *
     *   1. Network-active plugins (active_sitewide_plugins) — single
     *      registry shared by every blog, most common multisite shape.
     *   2. Current blog (cheap: no switch needed).
     *   3. Every other blog via switch_to_blog loop.
     *
     * First match wins. If nothing matches, log without plugin_path.
     *
     * @param array $error Shape from WP_Fatal_Error_Handler::detect_error().
     */
    protected function deckwpHandleMultisite(array $error)
    {
        $relative = $this->deckwpRelativePluginPath((string) $error['file']);

        if ($relative === null) {
            // Error file is outside WP_PLUGIN_DIR — log and bail.
            $this->deckwpAppendLog(
                $this->deckwpBuildLogEntry($error, null, false, 'multisite')
            );
            return;
        }

        // 1. Network-active plugins.
        $sitewide       = (array) get_site_option('active_sitewide_plugins', []);
        $networkCulprit = $this->deckwpLongestPrefixMatch($relative, array_keys($sitewide));
        if ($networkCulprit !== null) {
            $deactivated = $this->deckwpDeactivateNetworkPlugin($networkCulprit);
            $this->deckwpAppendLog(
                $this->deckwpBuildLogEntry($error, $networkCulprit, $deactivated, 'network')
            );
            return;
        }

        // 2. Current blog (most likely culprit when network-active didn't match).
        $currentBlogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        $culprit       = $this->deckwpFindCulpritOnBlog($relative, $currentBlogId);
        if ($culprit !== null) {
            $deactivated = $this->deckwpDeactivatePluginOnBlog($culprit, $currentBlogId);
            $this->deckwpAppendLog(
                $this->deckwpBuildLogEntry($error, $culprit, $deactivated, 'blog', $currentBlogId)
            );
            return;
        }

        // 3. switch_to_blog loop across every other blog.
        if (function_exists('get_sites')) {
            $blogIds = get_sites([
                'fields' => 'ids',
                'number' => 0, // all sites; do NOT cap
            ]);
            foreach ($blogIds as $blogId) {
                $blogId = (int) $blogId;
                if ($blogId === $currentBlogId) {
                    continue;
                }
                $culprit = $this->deckwpFindCulpritOnBlog($relative, $blogId);
                if ($culprit !== null) {
                    $deactivated = $this->deckwpDeactivatePluginOnBlog($culprit, $blogId);
                    $this->deckwpAppendLog(
                        $this->deckwpBuildLogEntry($error, $culprit, $deactivated, 'blog', $blogId)
                    );
                    return;
                }
            }
        }

        // 4. No match anywhere — log with plugin_path null. Operator
        // (or future Slice 4 logic) decides what to do.
        $this->deckwpAppendLog(
            $this->deckwpBuildLogEntry($error, null, false, 'multisite')
        );
    }

    /**
     * Path of the error file relative to WP_PLUGIN_DIR, or null when
     * the file lives outside the plugins tree (theme code, mu-plugins,
     * core). Used by both single-site and multisite paths.
     *
     * Path normalization: error files on Windows arrive with backslashes,
     * active_plugins entries are always forward-slash. wp_normalize_path()
     * bridges them; we manually fall back when WP isn't loaded enough to
     * provide it (extreme-early-failure mode).
     *
     * @return string|null
     */
    protected function deckwpRelativePluginPath($errorFile)
    {
        if (! defined('WP_PLUGIN_DIR') || ! is_string($errorFile) || $errorFile === '') {
            return null;
        }

        $pluginDir = rtrim($this->deckwpNormalizePath(WP_PLUGIN_DIR), '/');
        $errorFile = $this->deckwpNormalizePath($errorFile);

        if (strpos($errorFile, $pluginDir . '/') !== 0) {
            return null;
        }

        return substr($errorFile, strlen($pluginDir) + 1);
        // Shape: 'slug/sub/file.php' OR 'single.php'
    }

    /**
     * Longest-prefix match against a list of active_plugins-shaped
     * entries. Standalone single-file plugins (no slash in path) match
     * exactly; folder plugins ('slug/main.php') match on the 'slug/'
     * prefix and the longest hit wins.
     *
     * @param string   $relative   File path under WP_PLUGIN_DIR.
     * @param string[] $candidates active_plugins-shaped entries (or
     *                             array_keys(active_sitewide_plugins)).
     * @return string|null
     */
    protected function deckwpLongestPrefixMatch($relative, array $candidates)
    {
        $bestMatch = null;
        $bestLen   = 0;

        foreach ($candidates as $pluginPath) {
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
     * Look for the culprit in a specific blog's active_plugins.
     * Pure read — no mutation.
     *
     * @return string|null
     */
    protected function deckwpFindCulpritOnBlog($relative, $blogId)
    {
        if (! function_exists('switch_to_blog') || $blogId <= 0) {
            return null;
        }
        switch_to_blog($blogId);
        $active = (array) get_option('active_plugins', []);
        restore_current_blog();
        return $this->deckwpLongestPrefixMatch($relative, $active);
    }

    /**
     * Remove a plugin from the current site's active_plugins option.
     * Returns true when the option was actually modified.
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
     * Remove a plugin from a specific blog's active_plugins option.
     * Wraps deckwpDeactivatePlugin in a switch_to_blog/restore pair.
     *
     * @return bool
     */
    protected function deckwpDeactivatePluginOnBlog($pluginPath, $blogId)
    {
        if (! function_exists('switch_to_blog') || $blogId <= 0) {
            return false;
        }
        switch_to_blog($blogId);
        $result = $this->deckwpDeactivatePlugin($pluginPath);
        restore_current_blog();
        return $result;
    }

    /**
     * Remove a plugin from the network's active_sitewide_plugins.
     *
     * @return bool
     */
    protected function deckwpDeactivateNetworkPlugin($pluginPath)
    {
        $sitewide = (array) get_site_option('active_sitewide_plugins', []);
        if (! isset($sitewide[$pluginPath])) {
            return false;
        }
        unset($sitewide[$pluginPath]);
        return (bool) update_site_option('active_sitewide_plugins', $sitewide);
    }

    /**
     * Build the structured log entry. Caller passes `scope` so single,
     * network, and per-blog matches can be told apart in the dashboard.
     * `blog_id` only present when scope === 'blog'.
     *
     * @return array
     */
    protected function deckwpBuildLogEntry(array $error, $pluginPath, $deactivated, $scope, $blogId = null)
    {
        $message = isset($error['message']) ? (string) $error['message'] : '';
        if (strlen($message) > self::MESSAGE_TRUNCATE) {
            $message = substr($message, 0, self::MESSAGE_TRUNCATE) . '…';
        }

        $entry = [
            'ts'          => time(),
            'type'        => isset($error['type']) ? (int) $error['type'] : 0,
            'file'        => isset($error['file']) ? (string) $error['file'] : '',
            'line'        => isset($error['line']) ? (int) $error['line'] : 0,
            'message'     => $message,
            'plugin_path' => $pluginPath,
            'deactivated' => $deactivated,
            'scope'       => $scope,
        ];
        if ($blogId !== null) {
            $entry['blog_id'] = (int) $blogId;
        }
        return $entry;
    }

    /**
     * Append an entry to the network-wide deckwp_fatal_log, trimmed
     * to the cap. update_site_option falls back to update_option on
     * single-site automatically, so this works in both topologies
     * with no extra branching.
     */
    protected function deckwpAppendLog(array $entry)
    {
        $log   = (array) get_site_option(self::FATAL_LOG_OPTION, []);
        $log[] = $entry;

        if (count($log) > self::FATAL_LOG_CAP) {
            $log = array_slice($log, -self::FATAL_LOG_CAP);
        }

        update_site_option(self::FATAL_LOG_OPTION, $log);
    }

    /**
     * Cross-platform path normalization. wp_normalize_path() may not
     * be available in extreme-early-failure modes; fall back to a
     * minimal manual normalize so the handler can still run.
     */
    protected function deckwpNormalizePath($path)
    {
        return function_exists('wp_normalize_path')
            ? wp_normalize_path($path)
            : str_replace('\\', '/', $path);
    }
}

return new DeckWP_Fatal_Error_Handler();
