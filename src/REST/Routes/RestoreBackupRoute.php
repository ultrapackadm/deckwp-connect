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
 *       "checksum": "sha256-hex" }    // optional
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
            ],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $slug      = (string) $request->get_param('slug');
        $localPath = (string) $request->get_param('local_path');
        $checksum  = (string) $request->get_param('checksum');

        if ($slug === '' || $localPath === '') {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Missing slug or local_path.', 'error_code' => 'invalid_input'],
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

        $result = $this->backupManager->restore(
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
