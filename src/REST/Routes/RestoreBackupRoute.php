<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\Backup\BackupManager;
use DeckWP\Connect\Inventory\ItemState;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route that restores a previously-taken plugin folder
 * snapshot.
 *
 *     POST /wp-json/deckwp/v1/restore-backup
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *     Content-Type: application/json
 *
 *     { "slug": "formidable-pro",
 *       "local_path": "deckwp-backups/formidable-pro-2026-05-04T...zip",
 *       "checksum": "sha256-hex",      // optional
 *       "download_url": "https://...   // optional (v0.37.0+): signed
 *                                      //   GET url for the off-site (B2)
 *                                      //   copy, used only if the local
 *                                      //   zip is missing on this server.
 *       }
 *
 * Response 200:
 *
 *     { "ok": true, "slug": "contact-form-7", "type": "plugin",
 *       "installed": true, "version": "6.0", "active": true }
 *
 * Response 4xx/5xx: { "ok": false, "error": "...", "error_code": "..." }
 *
 * The response used to be a bare `{"ok": true}`, which left the panel
 * inferring the outcome from the backup row it had asked us to restore.
 * That inference was wrong in the obvious case: restore 6.0 over 6.1.6
 * and the panel kept showing 6.1.6 until someone pressed Refresh. The
 * post-state fields above are read back from WordPress after the swap
 * so the dashboard has no reason to guess.
 *
 * Triggered by:
 *   1. The dashboard's manual "Restore" button (operator-initiated
 *      after a successful update they want to undo).
 *   2. The auto-rollback path (Sprint 4 T4) when post-upgrade
 *      smoke-test fails — fired by the connector itself, not the
 *      dashboard, in that case.
 *
 * The `local_path` is the value the dashboard recorded when the
 * snapshot was created; the connector resolves it relative to the
 * uploads basedir and refuses anything that escapes the managed
 * deckwp-backups/ directory ({@see BackupManager::restore()}).
 *
 * Synchronous: the actual extract takes ~1-3s for a typical plugin.
 * The dashboard sets a generous outbound timeout.
 */
class RestoreBackupRoute
{
    /** @var BackupManager */
    private $backupManager;

    /** @var ItemState */
    private $state;

    public function __construct(?BackupManager $backupManager = null, ?ItemState $state = null)
    {
        $this->backupManager = $backupManager ?? new BackupManager();
        $this->state = $state ?? new ItemState();
    }

    /**
     * @param  callable  $permissionCallback
     * @return array<string, mixed>
     */
    public function args(callable $permissionCallback): array
    {
        return [
            'methods' => 'POST',
            'permission_callback' => $permissionCallback,
            'callback' => [$this, 'handle'],
            'args' => [
                'slug' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'local_path' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'checksum' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => '',
                ],
                // Discriminator: 'plugin' (default, BC with v0.12.0+)
                // or 'theme' (added v0.32.0). Routes to the matching
                // BackupManager method on the customer disk.
                'type' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'plugin',
                ],
                // Off-site restore fallback (v0.37.0+). A short-lived
                // pre-signed GET url for the backup's off-site (B2) copy.
                // Used ONLY when the local zip at local_path is missing
                // on this server — we download it into the managed
                // backups dir first, then restore as usual. Absent (or
                // sent to an older connector) → local-only restore.
                'download_url' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => '',
                ],
            ],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $slug        = (string) $request->get_param('slug');
        $localPath   = (string) $request->get_param('local_path');
        $checksum    = (string) $request->get_param('checksum');
        $type        = (string) $request->get_param('type');
        $downloadUrl = (string) $request->get_param('download_url');

        // Mirror the args() default for direct handler dispatch
        // (internal tests bypass the routing layer's default).
        if ($type === '') {
            $type = 'plugin';
        }

        if ($slug === '' || $localPath === '') {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Missing slug or local_path.', 'error_code' => 'invalid_input'],
                422
            );
        }

        if ($type !== 'plugin' && $type !== 'theme') {
            return new WP_REST_Response(
                [
                    'ok'         => false,
                    'error'      => sprintf('Restore type "%s" is not supported in this connector version.', $type),
                    'error_code' => 'unsupported_type',
                ],
                422
            );
        }

        // The dashboard sends `local_path` relative to the WP
        // uploads basedir (the same shape the snapshot endpoint
        // returns). Resolve it back to absolute before handing
        // off to BackupManager, which expects an absolute path
        // and validates it stays inside our managed directory.
        $absolutePath = $this->resolveLocalPath($localPath);
        if ($absolutePath === null) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Could not resolve uploads basedir for the local_path.', 'error_code' => 'uploads_dir_unresolved'],
                500
            );
        }

        // Off-site fallback: if the local zip is gone but the dashboard
        // gave us a signed download URL, pull the off-site copy into
        // place first. We only do this when the local file is actually
        // missing — a present local zip is always preferred (faster, no
        // egress). restore()/restoreTheme() then validate + extract it
        // exactly as for a local snapshot (including the checksum guard).
        if ($downloadUrl !== '' && ! is_file($absolutePath)) {
            $download = $this->backupManager->downloadOffsite($absolutePath, $downloadUrl);

            if (! ($download['ok'] ?? false)) {
                // 422 for a clean "couldn't get the object" (bad/expired
                // URL, empty body); 502 for transport-level failures
                // reaching the object store.
                $status = in_array(
                    (string) ($download['error_code'] ?? ''),
                    ['offsite_no_url', 'offsite_path_escape', 'offsite_download_failed', 'offsite_download_empty'],
                    true
                ) ? 422 : 502;

                return new WP_REST_Response($download, $status);
            }
        }

        // Capture the pre-restore activation state. A restore replaces
        // files under a plugin that may be running right now; if the
        // snapshot's main file is named differently from the one on
        // disk, WordPress drops it out of the active set and the site
        // silently loses a plugin the operator only meant to downgrade.
        $wasActive = false;
        $wasNetworkActive = false;
        if ($type === 'plugin') {
            $before = $this->state->plugin($slug);
            $wasActive = $before['active'];
            $wasNetworkActive = $wasActive && $before['plugin_file'] !== null
                && $this->state->isPluginActiveForNetwork($before['plugin_file']);
        }

        $result = $type === 'theme'
            ? $this->backupManager->restoreTheme(
                $absolutePath,
                $slug,
                $checksum !== '' ? $checksum : null
            )
            : $this->backupManager->restore(
                $absolutePath,
                $slug,
                $checksum !== '' ? $checksum : null
            );

        if (! ($result['ok'] ?? false)) {
            // 422 for validation-shaped failures (bad slug, path
            // escape, checksum mismatch); 500 for filesystem
            // failures we don't expect.
            $status = in_array(
                (string) ($result['error_code'] ?? ''),
                ['invalid_slug', 'path_escape', 'zip_not_found', 'checksum_mismatch', 'zip_unexpected_entry', 'zip_traversal', 'zip_layout_unexpected'],
                true
            ) ? 422 : 500;

            return new WP_REST_Response($result, $status);
        }

        // The folder swap happened outside WP_Upgrader, so nothing
        // invalidated the `plugins` object cache. Without this, any
        // get_plugins() call later in this request — including the one
        // behind the post-state read below — answers from the pre-swap
        // scan and we would report the version we just replaced.
        if (function_exists('wp_clean_plugins_cache')) {
            // false: leave the update transient alone. Clearing it here
            // would make the next inventory pull re-poll wp.org for no
            // reason, and the transient is refreshed on its own schedule.
            wp_clean_plugins_cache(false);
        }
        if (function_exists('wp_clean_themes_cache')) {
            wp_clean_themes_cache(false);
        }

        $reactivationError = null;
        if ($type === 'plugin' && $wasActive) {
            $reactivationError = $this->reactivate($slug, $wasNetworkActive);
        }

        $after = $type === 'theme' ? $this->state->theme($slug) : $this->state->plugin($slug);

        $response = [
            'ok' => true,
            'slug' => $slug,
            'type' => $type,
            'installed' => $after['installed'],
            'version' => $after['version'],
            'active' => $after['active'],
        ];

        if ($reactivationError !== null) {
            // The restore itself succeeded — 200 is honest. But the
            // operator needs to know the plugin came back switched off,
            // so the panel gets a field it can surface rather than a
            // silent discrepancy.
            $response['reactivation_error'] = $reactivationError;
        }

        return new WP_REST_Response($response, 200);
    }

    /**
     * Put a plugin back into the active set after a restore.
     *
     * Silent, for the same reason {@see \DeckWP\Connect\Install\Installer}
     * restores activation silently: the site never observed a
     * deactivation, so `register_activation_hook` callbacks — which mean
     * "first install" — have no business running.
     *
     * @return string|null Error message, or null when the plugin is
     *         active again (including when it never stopped being).
     */
    private function reactivate(string $slug, bool $networkWide): ?string
    {
        $pluginFile = $this->state->findPluginFile($slug);
        if ($pluginFile === null) {
            return sprintf('Restore completed but no plugin file for "%s" was found to re-activate.', $slug);
        }
        if ($this->state->isPluginActive($pluginFile)) {
            return null;
        }

        if (! function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (! function_exists('activate_plugin')) {
            return 'Could not load wp-admin/includes/plugin.php to re-activate the plugin.';
        }

        $activation = activate_plugin($pluginFile, '', $networkWide, true);
        if (is_wp_error($activation)) {
            return sprintf('%s: %s', (string) $activation->get_error_code(), (string) $activation->get_error_message());
        }

        return $this->state->isPluginActive($pluginFile)
            ? null
            : sprintf('activate_plugin("%s") reported no error but the plugin is still inactive.', $pluginFile);
    }

    /**
     * Convert "deckwp-backups/foo.zip" (relative to uploads basedir)
     * into an absolute filesystem path. Returns null if the WP
     * uploads dir isn't resolvable.
     */
    private function resolveLocalPath(string $relative): ?string
    {
        if (! function_exists('wp_get_upload_dir')) {
            return null;
        }
        $uploads = wp_get_upload_dir();
        if (! empty($uploads['error'])) {
            return null;
        }
        $base = rtrim((string) ($uploads['basedir'] ?? ''), '/\\');
        if ($base === '') {
            return null;
        }

        // If the dashboard happens to send an already-absolute path,
        // pass it through — BackupManager::restore() applies its own
        // path-escape guard either way.
        if (preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $relative)) {
            return $relative;
        }

        return $base . '/' . ltrim($relative, '/\\');
    }
}
