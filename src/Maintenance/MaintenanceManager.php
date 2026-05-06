<?php

namespace DeckWP\Connect\Maintenance;

defined('ABSPATH') || exit;

/**
 * On/off toggle + state persistence for the dashboard-driven
 * "Maintenance mode" feature.
 *
 * State lives in a JSON file at
 * `wp-content/uploads/deckwp-maintenance.lock`. We deliberately
 * avoid the wp-options table to:
 *
 *   1. Survive a corrupted DB (the maintenance page must be
 *      reachable even if SQL is broken — that's part of why
 *      operators *enable* maintenance in the first place).
 *   2. Not interact with WP's built-in `.maintenance` flag
 *      that core sets during plugin upgrades. Our toggle is a
 *      separate, longer-lived concept.
 *
 * Lock file shape (JSON):
 *
 *     {
 *       "enabled":     true,
 *       "ends_at":     1717684800,   // Unix timestamp; auto-disable
 *       "message":     "We're updating the site, back in 30 min",
 *       "started_at":  1717683000,
 *       "started_by":  "operator email or label (informational only)"
 *     }
 *
 * `ends_at` is the hard auto-expiry — once `time() >= ends_at`,
 * the guard treats the lock as inactive even if the file hasn't
 * been removed yet. This stops a forgotten "Enable maintenance"
 * click from blackholing a site forever.
 *
 * The dashboard side stores a mirror in `sites.maintenance_until`
 * for UI rendering, but the lock file is the ground truth on the
 * customer server.
 */
class MaintenanceManager
{
    public const LOCK_FILENAME = 'deckwp-maintenance.lock';

    /** Default message rendered on the branded maintenance page. */
    public const DEFAULT_MESSAGE = "We're performing scheduled maintenance. We'll be back shortly.";

    /**
     * Enable maintenance with a given duration + optional message.
     *
     * @param  int     $minutes      How long the lock should last from now.
     * @param  string  $message      Operator-facing text (HTML escaped at render).
     * @param  string  $startedBy    Audit label (operator email, "system", etc.).
     * @return array<string, mixed>
     */
    public function enable(int $minutes, string $message = '', string $startedBy = ''): array
    {
        if ($minutes < 1) {
            return $this->fail('invalid_duration', 'Duration must be at least 1 minute.');
        }
        if ($minutes > 60 * 24) {
            // 24h ceiling — anything longer is a sign of operator
            // forgetting to flip it back. Set a hard cap; operators
            // can re-enable for another 24h cycle.
            return $this->fail('duration_too_long', 'Maintenance window cannot exceed 24 hours per enable; re-enable for additional time.');
        }

        $lockPath = $this->lockPath();
        if ($lockPath === null) {
            return $this->fail('uploads_unresolvable', 'Could not resolve uploads directory to write the lock file.');
        }

        $now = time();
        $payload = [
            'enabled'    => true,
            'ends_at'    => $now + ($minutes * 60),
            'message'    => $message !== '' ? $message : self::DEFAULT_MESSAGE,
            'started_at' => $now,
            'started_by' => $startedBy !== '' ? $startedBy : 'dashboard',
        ];

        $written = @file_put_contents($lockPath, (string) wp_json_encode($payload));
        if ($written === false) {
            return $this->fail('write_failed', 'Could not write lock file. Check uploads/ permissions.');
        }

        return [
            'ok'    => true,
            'state' => $payload,
        ];
    }

    /**
     * Disable maintenance — remove the lock file.
     *
     * Idempotent: returns ok=true even when no lock was active
     * (operator clicking Disable twice in a row is harmless).
     *
     * @return array<string, mixed>
     */
    public function disable(): array
    {
        $lockPath = $this->lockPath();
        if ($lockPath === null) {
            return $this->fail('uploads_unresolvable', 'Could not resolve uploads directory.');
        }
        if (! file_exists($lockPath)) {
            return ['ok' => true, 'already_off' => true];
        }
        if (! @unlink($lockPath)) {
            return $this->fail('unlink_failed', 'Could not remove lock file.');
        }
        return ['ok' => true];
    }

    /**
     * Read the current lock state. Returns a normalized envelope:
     *
     *     ['active' => false]                              when off
     *     ['active' => true, 'ends_at' => ..., 'message' => ..., ...] when on
     *
     * Lock is treated as inactive when:
     *   - File doesn't exist.
     *   - File contents fail JSON parse (corrupt).
     *   - `enabled` flag missing or false.
     *   - `ends_at` is in the past (auto-expiry).
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $lockPath = $this->lockPath();
        if ($lockPath === null || ! file_exists($lockPath)) {
            return ['active' => false];
        }

        $raw = @file_get_contents($lockPath);
        if ($raw === false || $raw === '') {
            return ['active' => false];
        }
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return ['active' => false];
        }

        $enabled = ! empty($data['enabled']);
        $endsAt = isset($data['ends_at']) ? (int) $data['ends_at'] : 0;
        if (! $enabled || $endsAt <= time()) {
            return [
                'active'  => false,
                'ends_at' => $endsAt,
            ];
        }

        return [
            'active'     => true,
            'ends_at'    => $endsAt,
            'message'    => isset($data['message']) ? (string) $data['message'] : self::DEFAULT_MESSAGE,
            'started_at' => isset($data['started_at']) ? (int) $data['started_at'] : 0,
            'started_by' => isset($data['started_by']) ? (string) $data['started_by'] : '',
        ];
    }

    /**
     * Absolute path to the lock file, or null if the WP uploads
     * dir can't be resolved (no `wp_get_upload_dir`, error in
     * the response).
     */
    private function lockPath(): ?string
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
        return $base . '/' . self::LOCK_FILENAME;
    }

    /**
     * @return array{ok: false, error: string, error_code: string}
     */
    private function fail(string $code, string $message): array
    {
        return [
            'ok'         => false,
            'error_code' => $code,
            'error'      => $message,
        ];
    }
}
