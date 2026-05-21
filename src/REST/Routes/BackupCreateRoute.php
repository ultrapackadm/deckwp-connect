<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\Backup\BackupManager;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route: create a plugin folder snapshot on demand.
 *
 *     POST /wp-json/deckwp/v1/backup-create
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *     Content-Type: application/json
 *
 *     { "slug": "formidable-pro" }
 *
 * Response 200:
 *
 *     { "ok": true,
 *       "backup": {
 *         "local_path":   "deckwp-backups/formidable-pro-2026-05-07T...zip",
 *         "absolute_path":"/var/www/.../uploads/deckwp-backups/...zip",
 *         "checksum":     "sha256-hex (64 chars)",
 *         "size_bytes":   524288
 *       }
 *     }
 *
 * Response 4xx/5xx: `{ "ok": false, "error": "...", "error_code": "..." }`
 *
 * ## Why a dedicated route (vs. piggy-backing on install-batch)
 *
 * `install-batch` already creates pre-update snapshots when the caller
 * sets `backup_required: true` per item, but those backups are tied to
 * a specific upgrade attempt — they ride back in the per-item response
 * as the "rollback target if the smoke test fails" payload.
 *
 * Operators frequently want a *standalone* snapshot — "I'm about to
 * tweak this plugin's options, give me a rollback target before I
 * touch anything." Tying that to install-batch would require either a
 * fake upgrade item or a special "snapshot-only" item type, both of
 * which leak abstraction. A dedicated route is the right shape.
 *
 * ## After-hook for ecosystem integrations
 *
 * After a successful snapshot we fire:
 *
 *     do_action('deckwp_connect_backup_created', $slug, $backup, $context);
 *
 * `$backup` is the same array shape as the response payload's
 * `backup` sub-key. `$context` is `'route'` here so listeners can
 * tell apart route-driven backups from install-batch-driven ones
 * (the latter never fire this hook in v1; we may extend later).
 *
 * Reserved for the planned UltraHub integration — once the
 * UltraPack-side hub publishes its outbound API, a dedicated
 * subsystem in this plugin will subscribe to the hook and push the
 * zip to remote object storage. **That subsystem is NOT implemented
 * yet.** This route ships only the local snapshot half.
 */
class BackupCreateRoute
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
                'slug' => [
                    'required' => true,
                    'type'     => 'string',
                ],
                // Discriminator: `plugin` or `theme`. Themes ship in
                // connector v0.32.0 — earlier dashboards default to
                // `plugin` so the wire contract stays backwards-
                // compatible with v0.12.0+.
                'type' => [
                    'required' => false,
                    'type'     => 'string',
                    'default'  => 'plugin',
                ],
            ],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request->get_param('slug');
        $type = (string) $request->get_param('type');

        // Mirror the args() default explicitly — `register_rest_route`
        // applies defaults during routing, but a direct handler call
        // (e.g. internal dispatch from a test) skips that layer.
        if ($type === '') {
            $type = 'plugin';
        }

        if ($slug === '') {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Missing slug.', 'error_code' => 'invalid_input'],
                422
            );
        }

        if ($type !== 'plugin' && $type !== 'theme') {
            // Forward-compatibility: refuse unknown types loudly so
            // a future dashboard that learned about a third type
            // doesn't silently swallow the request.
            return new WP_REST_Response(
                [
                    'ok'         => false,
                    'error'      => sprintf('Backup type "%s" is not supported in this connector version.', $type),
                    'error_code' => 'unsupported_type',
                ],
                422
            );
        }

        $result = $type === 'theme'
            ? $this->backupManager->snapshotTheme($slug)
            : $this->backupManager->snapshot($slug);

        if (! ($result['ok'] ?? false)) {
            // 422 for validation-shaped failures (bad slug, item
            // doesn't exist on disk, oversized, path escape attempt);
            // 500 for filesystem failures we don't expect. Same
            // taxonomy across plugin/theme variants — the manager
            // returns parallel error codes (plugin_not_found vs
            // theme_not_found, plugin_too_large vs theme_too_large).
            $status = in_array(
                (string) ($result['error_code'] ?? ''),
                [
                    'invalid_slug',
                    'plugin_not_found', 'theme_not_found',
                    'plugin_too_large', 'theme_too_large',
                    'path_escape',
                ],
                true
            ) ? 422 : 500;

            return new WP_REST_Response($result, $status);
        }

        $backup = [
            'local_path'    => (string) ($result['local_path'] ?? ''),
            'absolute_path' => (string) ($result['absolute_path'] ?? ''),
            'checksum'      => (string) ($result['checksum'] ?? ''),
            'size_bytes'    => (int)    ($result['size_bytes'] ?? 0),
        ];

        /**
         * Fires after a successful on-demand backup. Reserved for the
         * future UltraHub integration that will push the zip off-site;
         * no listeners exist in v1 of this connector.
         *
         * @param string $slug   The plugin slug just snapshotted.
         * @param array  $backup The snapshot metadata (local_path, absolute_path, checksum, size_bytes).
         * @param string $context Always 'route' here; future install-batch wiring may pass other values.
         */
        do_action('deckwp_connect_backup_created', $slug, $backup, 'route');

        return new WP_REST_Response(
            [
                'ok'     => true,
                'backup' => $backup,
            ],
            200
        );
    }
}
