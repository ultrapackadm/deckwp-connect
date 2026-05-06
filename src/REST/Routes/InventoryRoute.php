<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\Inventory\PluginInventory;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route that returns a fresh inventory snapshot
 * on demand — same payload shape as the cron-driven heartbeat,
 * but pulled by the dashboard rather than pushed by the
 * connector.
 *
 *     POST /wp-json/deckwp/v1/inventory
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *     Content-Type: application/json
 *     Body: {} (empty — the slug list is collected server-side)
 *
 * Response 200:
 *
 *     {
 *       "event":        "inventory",
 *       "sent_at":      1717684800,
 *       "wp_version":   "6.6.2",
 *       "php_version":  "8.3.10",
 *       "site_url":     "https://example.com",
 *       "is_multisite": false,
 *       "plugins":      [ { "slug": "...", "name": "...", "version": "...", "active": true }, ... ]
 *     }
 *
 * The dashboard's "Refresh now" button is the typical caller.
 * Useful when the operator just made an out-of-band change in
 * WP admin (installed/deleted/activated a plugin) and doesn't
 * want to wait up to 5 minutes for the next heartbeat tick to
 * reconcile the inventory.
 *
 * The `event` field is "inventory" rather than "heartbeat" so
 * the dashboard can route the response through the same
 * HeartbeatProcessor without confusing it with the
 * fire-and-forget event bus that drives cron heartbeats. (The
 * processor doesn't actually look at the field; the
 * distinction is for log readability.)
 */
class InventoryRoute
{
    /** @var PluginInventory */
    private $inventory;

    public function __construct(PluginInventory $inventory = null)
    {
        $this->inventory = $inventory ?? new PluginInventory();
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
            'args' => [],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        global $wp_version;

        $payload = [
            'event'        => 'inventory',
            'sent_at'      => time(),
            'wp_version'   => isset($wp_version) ? (string) $wp_version : 'unknown',
            'php_version'  => PHP_VERSION,
            'site_url'     => function_exists('get_site_url') ? (string) get_site_url() : '',
            'is_multisite' => function_exists('is_multisite') && is_multisite(),
            'plugins'      => $this->inventory->collect(),
        ];

        return new WP_REST_Response($payload, 200);
    }
}
