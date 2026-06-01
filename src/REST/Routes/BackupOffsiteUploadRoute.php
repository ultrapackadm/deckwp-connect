<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\Backup\BackupManager;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route: upload an EXISTING local backup zip off-site
 * (Backblaze B2) to a pre-signed PUT URL.
 *
 *     POST /wp-json/deckwp/v1/backup-offsite-upload
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *     Content-Type: application/json
 *
 *     { "local_path": "deckwp-backups/akismet-2026-06-01.zip",
 *       "url":        "https://...signed PUT...",
 *       "headers":    { "Content-Type": "application/zip" },   // optional
 *       "key":        "backups/<team>/<backup>.zip" }          // optional, echoed back
 *
 * Response 200:
 *
 *     { "ok": true, "offsite": { "ok": true, "key": "backups/..." } }
 *
 * Response 4xx/5xx: `{ "ok": false, "error": "...", "error_code": "..." }`
 *
 * ## Why a dedicated route (vs. uploading inside install-batch)
 *
 * The manual "Backup now" path uploads off-site inline in
 * /backup-create (v0.36.0) because that request exists only to make a
 * backup. Pre-update backups, though, are taken *inside* install-batch
 * — the critical upgrade path. Adding a (potentially large) B2 upload
 * there would extend the upgrade request and risk a timeout that leaves
 * the update half-done. So the dashboard takes the pre-update snapshot
 * locally during install-batch as before, then calls THIS route
 * asynchronously (a queued job) to ship the already-written zip to B2 —
 * decoupling egress latency from the upgrade entirely. Works for any
 * existing local backup, not just pre-update ones.
 *
 * Shipped in connector v0.38.0.
 */
class BackupOffsiteUploadRoute
{
    /** @var BackupManager */
    private $backupManager;

    public function __construct(BackupManager $backupManager = null)
    {
        $this->backupManager = $backupManager ?? new BackupManager();
    }

    /**
     * @param  callable  $permissionCallback HMAC verifier passed in by the Server.
     * @return array<string, mixed>
     */
    public function args(callable $permissionCallback): array
    {
        return [
            'methods'             => 'POST',
            'permission_callback' => $permissionCallback,
            'callback'            => [$this, 'handle'],
            'args'                => [
                'local_path' => [
                    'required' => true,
                    'type'     => 'string',
                ],
                'url' => [
                    'required' => true,
                    'type'     => 'string',
                ],
                'headers' => [
                    'required' => false,
                    'type'     => 'object',
                ],
                'key' => [
                    'required' => false,
                    'type'     => 'string',
                    'default'  => '',
                ],
            ],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $localPath = (string) $request->get_param('local_path');
        $url       = (string) $request->get_param('url');
        $key       = (string) $request->get_param('key');
        $headers   = $request->get_param('headers');
        $headers   = is_array($headers) ? $headers : [];

        if ($localPath === '' || $url === '') {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Missing local_path or url.', 'error_code' => 'invalid_input'],
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

        $result = $this->backupManager->uploadOffsite($absolutePath, $url, $headers);

        if (! ($result['ok'] ?? false)) {
            // 422 for a clean "can't upload" (bad path, missing zip,
            // escape, store rejected); 502 for transport failures
            // reaching the object store.
            $status = in_array(
                (string) ($result['error_code'] ?? ''),
                ['offsite_no_url', 'offsite_zip_unreadable', 'offsite_path_escape', 'offsite_rejected', 'offsite_no_curl'],
                true
            ) ? 422 : 502;

            return new WP_REST_Response($result, $status);
        }

        return new WP_REST_Response(
            [
                'ok'      => true,
                'offsite' => ['ok' => true, 'key' => $key],
            ],
            200
        );
    }

    /**
     * Convert "deckwp-backups/foo.zip" (relative to uploads basedir)
     * into an absolute filesystem path. Returns null if the WP uploads
     * dir isn't resolvable. Mirrors {@see RestoreBackupRoute}; the
     * BackupManager applies its own managed-directory containment guard.
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

        if (preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $relative)) {
            return $relative;
        }

        return $base . '/' . ltrim($relative, '/\\');
    }
}
