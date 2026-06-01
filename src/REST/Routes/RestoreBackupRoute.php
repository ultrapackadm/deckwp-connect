<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\Backup\BackupManager;
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
 *     { "ok": true }
 *
 * Response 4xx/5xx: { "ok": false, "error": "...", "error_code": "..." }
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

    public function __construct(BackupManager $backupManager = null)
    {
        $this->backupManager = $backupManager ?? new BackupManager();
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

        return new WP_REST_Response(['ok' => true], 200);
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
