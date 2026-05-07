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
 *   ✅ Slice 3: multisite — three-tier search across
 *      active_sitewide_plugins → current blog → switch_to_blog loop.
 *      Log storage moved to update_site_option (network-wide). Log
 *      entry gained `scope` and `blog_id`. Closed the Manage-GPL gap
 *      on the comparison table.
 *
 *   ✅ Slice 4 (this slice):
 *        a) Memory-exhaustion detection — flag `oom: true` on log
 *           entries whose `message` contains "Allowed memory size"
 *           or "Out of memory". The dashboard can then surface those
 *           differently (memory tuning hint vs. plugin bug).
 *        b) Branded 503 splash — when we successfully identified +
 *           deactivated a culprit, render a self-contained HTML page
 *           explaining what happened, with a "Refresh page" button
 *           and a Retry-After: 5 header. No theme dependency; no
 *           plugin-asset URLs. Mirrors the MaintenanceGuard's inline
 *           CSS pattern so it renders even when the rest of WP is
 *           half-broken. When we couldn't identify a culprit the
 *           drop-in delegates to parent::handle() so the operator
 *           still gets the WP recovery flow with the trace.
 *
 *   🚧 Slice 5: /backup-create endpoint + UltraHub integration so
 *      operators can request a manual backup ("snapshot before
 *      shipping the deactivate") from the dashboard.
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
    define('DECKWP_DROPIN_VERSION', '0.12.0-slice4');
}

if (! class_exists('WP_Fatal_Error_Handler')) {
    // Pre-WP-5.2 — no fatal handler API to extend. Fall back to a
    // no-op object with a `handle()` method so wp_register_fatal_error_handler()
    // accepts the return value but otherwise does nothing different
    // from native behavior.
    require_once ABSPATH . WPINC . '/class-wp-fatal-error-handler.php';
}

/**
 * Multisite-aware fatal handler with longest-prefix culprit detection,
 * auto-deactivate, OOM detection, and a branded 503 splash for the
 * identified-and-deactivated path.
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
                $isOom = $this->deckwpDetectOom($error);

                $logEntry = is_multisite()
                    ? $this->deckwpHandleMultisite($error, $isOom)
                    : $this->deckwpHandleSingleSite($error, $isOom);

                // Render the branded splash only when we both identified
                // and successfully deactivated a culprit. Anything else
                // (no plugin_path, deactivate failed) falls through to
                // parent::handle() so the operator gets the full WP
                // recovery flow with the trace and recovery email.
                if (
                    is_array($logEntry)
                    && ! empty($logEntry['plugin_path'])
                    && ! empty($logEntry['deactivated'])
                ) {
                    $this->deckwpRenderBrandedSplash($logEntry);
                    return;
                }
            }
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
     * @param bool  $isOom Whether the error is a memory-exhaustion fatal.
     * @return array The log entry that was appended.
     */
    protected function deckwpHandleSingleSite(array $error, $isOom)
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

        $entry = $this->deckwpBuildLogEntry($error, $culprit, $deactivated, 'single', null, $isOom);
        $this->deckwpAppendLog($entry);
        return $entry;
    }

    /**
     * Multisite path. Three-tier search:
     *
     *   1. Network-active plugins (active_sitewide_plugins) — most
     *      common multisite shape, single registry shared by every blog.
     *   2. Current blog — get_current_blog_id() + get_option.
     *   3. switch_to_blog loop across every other blog.
     *
     * First match wins. If nothing matches, log without plugin_path.
     *
     * @return array The log entry that was appended.
     */
    protected function deckwpHandleMultisite(array $error, $isOom)
    {
        $relative = $this->deckwpRelativePluginPath((string) $error['file']);

        if ($relative === null) {
            $entry = $this->deckwpBuildLogEntry($error, null, false, 'multisite', null, $isOom);
            $this->deckwpAppendLog($entry);
            return $entry;
        }

        // 1. Network-active.
        $sitewide       = (array) get_site_option('active_sitewide_plugins', []);
        $networkCulprit = $this->deckwpLongestPrefixMatch($relative, array_keys($sitewide));
        if ($networkCulprit !== null) {
            $deactivated = $this->deckwpDeactivateNetworkPlugin($networkCulprit);
            $entry       = $this->deckwpBuildLogEntry($error, $networkCulprit, $deactivated, 'network', null, $isOom);
            $this->deckwpAppendLog($entry);
            return $entry;
        }

        // 2. Current blog.
        $currentBlogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        $culprit       = $this->deckwpFindCulpritOnBlog($relative, $currentBlogId);
        if ($culprit !== null) {
            $deactivated = $this->deckwpDeactivatePluginOnBlog($culprit, $currentBlogId);
            $entry       = $this->deckwpBuildLogEntry($error, $culprit, $deactivated, 'blog', $currentBlogId, $isOom);
            $this->deckwpAppendLog($entry);
            return $entry;
        }

        // 3. switch_to_blog loop.
        if (function_exists('get_sites')) {
            $blogIds = get_sites([
                'fields' => 'ids',
                'number' => 0,
            ]);
            foreach ($blogIds as $blogId) {
                $blogId = (int) $blogId;
                if ($blogId === $currentBlogId) {
                    continue;
                }
                $culprit = $this->deckwpFindCulpritOnBlog($relative, $blogId);
                if ($culprit !== null) {
                    $deactivated = $this->deckwpDeactivatePluginOnBlog($culprit, $blogId);
                    $entry       = $this->deckwpBuildLogEntry($error, $culprit, $deactivated, 'blog', $blogId, $isOom);
                    $this->deckwpAppendLog($entry);
                    return $entry;
                }
            }
        }

        // 4. No match.
        $entry = $this->deckwpBuildLogEntry($error, null, false, 'multisite', null, $isOom);
        $this->deckwpAppendLog($entry);
        return $entry;
    }

    /**
     * Detect memory-exhaustion fatals. PHP emits two recognizable
     * messages:
     *
     *   - "Allowed memory size of <N> bytes exhausted (tried to
     *     allocate <M> bytes)" — the standard Zend allocator OOM.
     *   - "Out of memory (allocated <N>) (tried to allocate <M>
     *     bytes)" — emitted when malloc() itself fails.
     *
     * Either form is interesting enough to surface separately in the
     * dashboard (operators tend to want a memory-tuning recommendation
     * rather than a plugin-bug ticket).
     *
     * @return bool
     */
    protected function deckwpDetectOom(array $error)
    {
        $message = isset($error['message']) ? (string) $error['message'] : '';
        return strpos($message, 'Allowed memory size') !== false
            || strpos($message, 'Out of memory') !== false;
    }

    /**
     * Path of the error file relative to WP_PLUGIN_DIR, or null when
     * the file lives outside the plugins tree (theme code, mu-plugins,
     * core). Used by both single-site and multisite paths.
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
     * Build the structured log entry. `blog_id` only present when
     * scope === 'blog'; `oom` only present when the fatal was a
     * memory-exhaustion fatal.
     *
     * @return array
     */
    protected function deckwpBuildLogEntry(array $error, $pluginPath, $deactivated, $scope, $blogId = null, $isOom = false)
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
        if ($isOom) {
            $entry['oom'] = true;
        }
        return $entry;
    }

    /**
     * Append an entry to the network-wide deckwp_fatal_log, trimmed
     * to the cap. update_site_option falls back to update_option on
     * single-site automatically.
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
     * be available in extreme-early-failure modes; manual fallback
     * keeps the handler running.
     */
    protected function deckwpNormalizePath($path)
    {
        return function_exists('wp_normalize_path')
            ? wp_normalize_path($path)
            : str_replace('\\', '/', $path);
    }

    /**
     * Render the branded 503 splash. Called from handle() only when
     * we identified AND successfully deactivated a culprit; other
     * cases delegate to parent::handle() (default WP recovery flow).
     *
     * Self-contained: inline CSS, inline SVG, no asset URL resolution,
     * no theme dependency. Mirrors the MaintenanceGuard pattern so
     * the page renders even with a half-broken WP environment.
     */
    protected function deckwpRenderBrandedSplash(array $logEntry)
    {
        if (! headers_sent()) {
            if (function_exists('status_header')) {
                status_header(503);
            } else {
                header('HTTP/1.1 503 Service Unavailable', true, 503);
            }
            if (function_exists('nocache_headers')) {
                nocache_headers();
            }
            header('Retry-After: 5');
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Robots-Tag: noindex, nofollow');
        }

        $isOom    = ! empty($logEntry['oom']);
        $slug     = isset($logEntry['plugin_path']) ? (string) $logEntry['plugin_path'] : '';
        $blogPart = isset($logEntry['blog_id']) ? ' (blog #' . (int) $logEntry['blog_id'] . ')' : '';

        echo $this->deckwpSplashHtml($slug, $isOom, $blogPart);
    }

    /**
     * Build the splash HTML. Returns a complete HTML document so
     * `echo` from the caller is the entire response body. All
     * dynamic data is htmlspecialchars-escaped.
     */
    protected function deckwpSplashHtml($slug, $isOom, $blogPart)
    {
        $slugEsc     = htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
        $blogPartEsc = htmlspecialchars($blogPart, ENT_QUOTES, 'UTF-8');

        if ($isOom) {
            $heading = 'Memory limit reached';
            $body    = sprintf(
                'A plugin (<code>%s</code>%s) exceeded the available PHP memory and was automatically disabled. The rest of the site keeps working — refresh this page to continue.',
                $slugEsc,
                $blogPartEsc
            );
        } else {
            $heading = 'Plugin error contained';
            $body    = sprintf(
                'A plugin (<code>%s</code>%s) raised an unrecoverable error and was automatically disabled. The rest of the site keeps working — refresh this page to continue.',
                $slugEsc,
                $blogPartEsc
            );
        }

        $headingEsc = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>$headingEsc</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<style>
* { box-sizing: border-box; }
body {
    margin: 0; min-height: 100vh;
    font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    color: #1f2937; background: linear-gradient(135deg, #f9fafb 0%, #eef2ff 100%);
    display: flex; align-items: center; justify-content: center; padding: 2rem;
}
.card {
    width: 100%; max-width: 540px; background: #ffffff; padding: 2.75rem 2.5rem;
    border-radius: 14px; box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
    text-align: center;
}
.icon { color: #4f46e5; margin: 0 auto 1.25rem; display: block; }
.badge {
    display: inline-block; background: #fef3c7; color: #92400e;
    padding: 0.25rem 0.75rem; border-radius: 999px;
    font-size: 0.7rem; font-weight: 700; letter-spacing: 0.04em;
    text-transform: uppercase; margin-bottom: 1rem;
}
h1 { font-size: 1.5rem; margin: 0 0 0.75rem; color: #111827; line-height: 1.3; }
p { color: #4b5563; margin: 0 0 1.75rem; }
code {
    background: #f3f4f6; color: #4f46e5; padding: 0.1rem 0.4rem;
    border-radius: 4px; font-size: 0.875em;
    font-family: ui-monospace, "SFMono-Regular", Menlo, monospace;
}
.btn {
    display: inline-block; background: #4f46e5; color: #ffffff;
    padding: 0.7rem 1.5rem; border: 0; border-radius: 8px;
    font-size: 0.95rem; font-weight: 600; text-decoration: none;
    cursor: pointer; transition: background 0.15s;
}
.btn:hover { background: #4338ca; }
.footer {
    margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #e5e7eb;
    font-size: 0.8125rem; color: #6b7280;
}
.footer strong { color: #4b5563; font-weight: 700; }
</style>
</head>
<body>
<div class="card">
    <svg class="icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 2L2 7l10 5 10-5-10-5z"/>
        <path d="M2 17l10 5 10-5"/>
        <path d="M2 12l10 5 10-5"/>
    </svg>
    <span class="badge">Auto-recovered</span>
    <h1>$headingEsc</h1>
    <p>$body</p>
    <a href="javascript:location.reload();" class="btn">Refresh page</a>
    <div class="footer">
        Protected by <strong>DeckWP</strong>
    </div>
</div>
</body>
</html>
HTML;
    }
}

return new DeckWP_Fatal_Error_Handler();
