<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\Backup\BackupManager;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route that deletes a previously-snapshotted zip.
 *
 *     POST /wp-json/deckwp/v1/delete-backup
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *     Content-Type: application/json
 *
 *     { "local_path": "deckwp-backups/formidable-pro-2026-05-04T...zip" }
 *
 * Response 200: { "ok": true }
 * Response 4xx/5xx: { "ok": false, "error": "...", "error_code": "..." }
 *
 * Triggered by the dashboard's retention sweeper (Sprint 4 T6),
 * which runs daily, finds Backup rows past `expires_at`, asks
 * the connector to delete the zip, then flips the row to
 * Expired locally.
 *
 * Idempotent: deleting an already-deleted zip returns 200 with
 * `already_gone: true` — the sweeper can retry transient failures
 * without worrying about a half-completed run from yesterday.
 *
 * Path safety: BackupManager::delete() applies the same realpath
 * containment guard used by restore — refuses anything outside
 * wp-content/uploads/deckwp-backups/.
 */
class DeleteBackupRoute
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
                'local_path' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $localPath = (string) $request->get_param('local_path');
        if ($localPath === '') {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Missing local_path.', 'error_code' => 'invalid_input'],
                422
            );
        }

        $absolutePath = $this->resolveLocalPath($localPath);
        if ($absolutePath === null) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Could not resolve uploads basedir for the local_path.', 'error_code' => 'uploads_dir_unresolved'],
                500
            );
        }

        $result = $this->backupManager->delete($absolutePath);

        if (! ($result['ok'] ?? false)) {
            $status = in_array((string) ($result['error_code'] ?? ''), ['path_escape'], true) ? 422 : 500;
            return new WP_REST_Response($result, $status);
        }

        return new WP_REST_Response($result, 200);
    }

    /** Convert "deckwp-backups/foo.zip" relative to uploads basedir to absolute. */
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

        if (preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $relative)) {
            return $relative;
        }

        return $base . '/' . ltrim($relative, '/\\');
    }
}
