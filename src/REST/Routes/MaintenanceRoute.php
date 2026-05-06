<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\Maintenance\MaintenanceManager;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST routes that toggle the dashboard-driven
 * maintenance mode and report its current state.
 *
 *     POST /wp-json/deckwp/v1/maintenance
 *       Body: { "enabled": bool, "minutes": int, "message": string }
 *       - enabled=true  → enable for `minutes` (1..1440)
 *       - enabled=false → disable (idempotent)
 *
 *     GET /wp-json/deckwp/v1/maintenance
 *       → { "active": bool, "ends_at": int, "message": string, ... }
 *
 * Both HMAC-protected via the standard X-DeckWP-* header set.
 *
 * The dashboard's "Enable maintenance" / "Disable maintenance"
 * buttons drive this. State persistence is in
 * `wp-content/uploads/deckwp-maintenance.lock` — see
 * {@see MaintenanceManager} class docblock for why a file
 * (not wp-options).
 */
class MaintenanceRoute
{
    /** @var MaintenanceManager */
    private $manager;

    public function __construct(MaintenanceManager $manager = null)
    {
        $this->manager = $manager ?? new MaintenanceManager();
    }

    /**
     * @param  callable  $permissionCallback
     * @return array<int, array<string, mixed>>
     */
    public function args(callable $permissionCallback): array
    {
        // Two endpoints share the same path, distinguished by
        // method. WP REST handles that via an array of route
        // configs.
        return [
            [
                'methods' => 'GET',
                'permission_callback' => $permissionCallback,
                'callback' => [$this, 'handleGet'],
                'args' => [],
            ],
            [
                'methods' => 'POST',
                'permission_callback' => $permissionCallback,
                'callback' => [$this, 'handlePost'],
                'args' => [
                    'enabled' => [
                        'required' => true,
                        'type' => 'boolean',
                    ],
                    'minutes' => [
                        'required' => false,
                        'type' => 'integer',
                        'default' => 30,
                    ],
                    'message' => [
                        'required' => false,
                        'type' => 'string',
                        'default' => '',
                    ],
                    'started_by' => [
                        'required' => false,
                        'type' => 'string',
                        'default' => '',
                    ],
                ],
            ],
        ];
    }

    public function handleGet(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->manager->state(), 200);
    }

    public function handlePost(WP_REST_Request $request): WP_REST_Response
    {
        $enabled = (bool) $request->get_param('enabled');

        if (! $enabled) {
            $result = $this->manager->disable();
            return new WP_REST_Response($result, ($result['ok'] ?? false) ? 200 : 500);
        }

        $minutes = (int) $request->get_param('minutes');
        $message = (string) $request->get_param('message');
        $startedBy = (string) $request->get_param('started_by');

        $result = $this->manager->enable($minutes, $message, $startedBy);
        if (! ($result['ok'] ?? false)) {
            $code = (string) ($result['error_code'] ?? '');
            $status = in_array($code, ['invalid_duration', 'duration_too_long'], true) ? 422 : 500;
            return new WP_REST_Response($result, $status);
        }
        return new WP_REST_Response($result, 200);
    }
}
