<?php

namespace DeckWP\Connect\Smoke;

defined('ABSPATH') || exit;

/**
 * Post-update health check for the auto-rollback safety net.
 *
 * Run by {@see \DeckWP\Connect\Install\Installer} immediately after
 * a successful upgrade, with the goal of catching cases where:
 *
 *   1. The upgrade replaced files but left the plugin folder in a
 *      structurally broken state (missing main file, syntactically
 *      invalid PHP).
 *   2. The plugin was active before the upgrade and silently
 *      failed to re-activate after — usually a fatal at activation
 *      that WP swallows by deactivating.
 *   3. Optionally: the front page now returns a 5xx, indicating the
 *      site as a whole is broken even if the plugin folder looks OK.
 *
 * The checker is fast and offline-by-default. Plugin folder + main
 * file existence + PHP token validity all run against local disk.
 * The HTTP-front-page check is opt-in (`$checkHome = true` in
 * {@see verify()}) because a lot of customer sites have basic auth,
 * staging-mode redirects, or maintenance walls that would produce
 * false-positive failures.
 *
 * ## Wire shape
 *
 *     verify('formidable-pro', 'formidable-pro/formidable-pro.php', wasActive=true)
 *       → ['ok' => true]
 *       → ['ok' => false, 'reason' => 'plugin_folder_missing'|'php_syntax_error'|'plugin_inactive_after_upgrade'|'home_5xx', 'detail' => 'human-readable']
 *
 * ## What we deliberately don't check
 *
 *   - Database schema migrations / option upgrades. WP plugins use
 *     `register_activation_hook` and one-shot `version_compare` flows
 *     for that; we don't drive those, and a half-applied DB migration
 *     is not something a folder-level rollback can fix anyway.
 *   - Frontend rendering correctness (CSS broken, JS console errors).
 *     Out of scope — the smoke check is for "is the site fundamentally
 *     up", not "does it look right".
 */
class PostUpdateChecker
{
    /** Outbound timeout for the optional home-page check. */
    private const HOME_CHECK_TIMEOUT_SECONDS = 8;

    /**
     * Run the smoke check. Returns an envelope the Installer folds
     * into the per-item response (and uses to decide whether to
     * auto-rollback).
     *
     * @return array<string, mixed>
     */
    public function verify(string $slug, ?string $pluginFile, bool $wasActive, bool $checkHome = false): array
    {
        // Folder existence: most basic invariant.
        $pluginsDir = $this->pluginsDir();
        if ($pluginsDir === null) {
            return $this->fail('plugins_dir_unresolved', 'Could not resolve wp-content/plugins/.');
        }
        $folder = $pluginsDir . '/' . $slug;
        if (! is_dir($folder)) {
            return $this->fail('plugin_folder_missing', sprintf('Plugin folder "%s" is gone after upgrade.', $slug));
        }

        // Main file existence + PHP token validity. We don't try to
        // execute the file — that could trigger fatals on its own —
        // we just walk the token stream and let `token_get_all` raise
        // a ParseError on syntactically broken PHP.
        if ($pluginFile !== null && $pluginFile !== '') {
            $absMainFile = $pluginsDir . '/' . $pluginFile;
            if (! file_exists($absMainFile)) {
                return $this->fail('plugin_main_file_missing', sprintf('Plugin main file "%s" is gone after upgrade.', $pluginFile));
            }
            $contents = @file_get_contents($absMainFile);
            if ($contents === false) {
                return $this->fail('plugin_main_file_unreadable', sprintf('Plugin main file "%s" exists but cannot be read.', $pluginFile));
            }
            try {
                // TOKEN_PARSE flag triggers a ParseError on broken PHP
                // exactly like the regular tokenizer would, but without
                // running require_once-style side effects.
                @token_get_all($contents, defined('TOKEN_PARSE') ? TOKEN_PARSE : 0);
            } catch (\ParseError $e) {
                return $this->fail('php_syntax_error', sprintf(
                    'Plugin main file failed PHP token parse after upgrade: %s',
                    $e->getMessage()
                ));
            }
        }

        // Active-state check: if the plugin was active going in, it
        // should still be active after a successful upgrade. WP
        // silently deactivates on activation-time fatal — that's the
        // exact failure mode this catches.
        if ($wasActive && $pluginFile !== null && $pluginFile !== '') {
            if (! function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if (function_exists('is_plugin_active') && ! is_plugin_active($pluginFile)) {
                return $this->fail('plugin_inactive_after_upgrade', sprintf(
                    'Plugin "%s" was active before the upgrade but is not active after — WP likely auto-deactivated on a fatal during plugin load.',
                    $pluginFile
                ));
            }
        }

        // DEV-ONLY kill switch — used by the manual smoke harness
        // to force a rollback path without producing a real fault.
        // The presence of `.deckwp-force-smoke-fail` inside uploads/
        // (any contents) flips the checker to fail. Operators
        // shipping this plugin to production should never have that
        // file on disk; it's not surfaced anywhere in the UI.
        if ($this->forceFailKillSwitch()) {
            return $this->fail('dev_force_fail', 'Forced failure via .deckwp-force-smoke-fail kill switch.');
        }

        if ($checkHome) {
            $homeCheck = $this->checkHomePage();
            if (! $homeCheck['ok']) {
                return $this->fail($homeCheck['reason'], $homeCheck['detail']);
            }
        }

        return ['ok' => true];
    }

    /** Path to wp-content/plugins/, or null if WP didn't define WP_PLUGIN_DIR. */
    private function pluginsDir(): ?string
    {
        if (defined('WP_PLUGIN_DIR')) {
            return rtrim(WP_PLUGIN_DIR, '/\\');
        }
        if (defined('WP_CONTENT_DIR')) {
            return rtrim(WP_CONTENT_DIR, '/\\') . '/plugins';
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkHomePage(): array
    {
        if (! function_exists('home_url') || ! function_exists('wp_remote_head')) {
            return ['ok' => true];  // can't check; treat as no-signal.
        }
        $url = (string) home_url('/');
        $response = wp_remote_head($url, [
            'timeout' => self::HOME_CHECK_TIMEOUT_SECONDS,
            // Don't follow redirects — a redirect chain can hide a
            // broken final destination. But also don't treat 3xx as
            // a problem; the chain is intentional on most sites.
            'redirection' => 0,
            'sslverify' => false,
        ]);
        if (is_wp_error($response)) {
            return [
                'ok'     => false,
                'reason' => 'home_unreachable',
                'detail' => sprintf('Home page request failed: %s', (string) $response->get_error_message()),
            ];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 500) {
            return [
                'ok'     => false,
                'reason' => 'home_5xx',
                'detail' => sprintf('Home page returned HTTP %d after upgrade.', $status),
            ];
        }
        return ['ok' => true];
    }

    /** True iff the dev kill-switch file is present in uploads. */
    private function forceFailKillSwitch(): bool
    {
        if (! function_exists('wp_get_upload_dir')) {
            return false;
        }
        $uploads = wp_get_upload_dir();
        $base = (string) ($uploads['basedir'] ?? '');
        if ($base === '') {
            return false;
        }
        return file_exists(rtrim($base, '/\\') . '/.deckwp-force-smoke-fail');
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(string $reason, string $detail): array
    {
        return [
            'ok'     => false,
            'reason' => $reason,
            'detail' => $detail,
        ];
    }
}
