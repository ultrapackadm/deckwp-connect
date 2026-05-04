<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\Install\Installer;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route that installs/upgrades a batch of items.
 *
 *     POST /wp-json/deckwp/v1/install-batch
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *     Content-Type: application/json
 *
 *     { "items": [
 *         { "slug": "akismet", "type": "plugin" },
 *         { "slug": "wordpress-seo", "type": "plugin" }
 *     ] }
 *
 * Response 200:
 *
 *     { "items": [
 *         { "slug": "akismet", "status": "installed",
 *           "version_before": "5.2.0", "version_after": "5.4.0",
 *           "error": null },
 *         ...
 *     ] }
 *
 * Triggered by the dashboard's "Update" button (per-plugin) and
 * eventually the bulk "Update all" action. HMAC verification runs
 * via {@see \DeckWP\Connect\REST\Auth\HmacVerifier} as the
 * `permission_callback`, so by the time this handler runs the
 * request is already trusted.
 *
 * Synchronous: holds the request open for the duration of every
 * upgrade in the batch. Plugin upgrades from wp.org are typically
 * fast (1-5s each on a healthy host), but big plugins or slow
 * networks can stretch a batch into the 30s+ range. The dashboard
 * sets a generous outbound timeout; if the dashboard times out
 * mid-batch the connector still finishes installing locally — the
 * dashboard just doesn't see the per-item statuses for the
 * trailing items and would need a follow-up `inventory` call (Sprint 4)
 * to reconcile.
 *
 * No backup before, no rollback on failure — that lifecycle is the
 * dashboard's job for now (will eventually move into the connector
 * with a `pre_update_backup_required` flag in the request).
 */
class InstallBatchRoute
{
    /** @var Installer */
    private $installer;

    public function __construct(Installer $installer = null)
    {
        $this->installer = $installer ?? new Installer();
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
                'items' => [
                    'required' => true,
                    'type' => 'array',
                ],
            ],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $items = $request->get_param('items');
        if (! is_array($items)) {
            return new WP_REST_Response(
                ['message' => 'Missing or invalid "items" array.'],
                422
            );
        }

        // Cap the batch size so a malicious or buggy dashboard can't
        // spin us into a 5-minute install loop. Dashboard's
        // RemoteInstallTrigger has its own timeout, but defense in
        // depth at the receiving end is cheap.
        if (count($items) > 25) {
            return new WP_REST_Response(
                ['message' => 'Batch size exceeds the 25-item ceiling.'],
                422
            );
        }

        $results = $this->installer->install($items);

        return new WP_REST_Response(['items' => $results], 200);
    }
}
