<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\DbOptimize\Optimizer;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route that runs `OPTIMIZE TABLE` on the requested
 * table list to reclaim the overhead bytes surfaced by /db-scan.
 *
 *     POST /wp-json/deckwp/v1/db-optimize-tables
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *     Content-Type: application/json
 *
 *     { "tables": ["wp_postmeta", "wpforms_entries"] }
 *
 *     200 {
 *       "sent_at": 1717684800,
 *       "results": [
 *         {"name": "wp_postmeta", "optimized": true, "error": null, "reclaimed_bytes": 2202010},
 *         {"name": "wpforms_entries", "optimized": true, "error": null, "reclaimed_bytes": 184320}
 *       ]
 *     }
 *
 *     422 — tables missing/not an array (malformed request).
 *
 * Triggered by:
 *   - Per-row "Optimize" button in the Tables list (single table).
 *   - "Run optimization" button at the top of the Db Optimize tab
 *     (passes the list of tables flagged `has_overhead: true`).
 *
 * ## Why this is its own route (not bundled into /db-cleanup)
 *
 * OPTIMIZE TABLE is a long-running, lock-acquiring operation that
 * the dashboard wants to surface explicitly to the operator
 * ("optimizing 4 tables — this can take a minute"). DELETEs and
 * OPTIMIZE have different UX shapes; one button cluster cleans
 * row counts, the other defragments storage. Separate verbs make
 * the audit trail readable + lets the dashboard's progress UI
 * differentiate.
 *
 * ## Timeout ceiling
 *
 * `set_time_limit(120)` — 2 minutes of headroom for big-table
 * rebuilds. The dashboard's outbound HTTP timeout should match.
 * Hosts that don't allow set_time_limit() will fall back to their
 * PHP-INI max_execution_time; the operator gets a 504 instead of a
 * partial reclaim. Same posture as the existing install-batch
 * route.
 *
 * @see \DeckWP\Connect\DbOptimize\Optimizer for SQL-injection
 *      defenses + the per-table OPTIMIZE TABLE logic.
 */
class DbOptimizeTablesRoute
{
    /** @var Optimizer */
    private $optimizer;

    public function __construct(Optimizer $optimizer = null)
    {
        $this->optimizer = $optimizer ?? new Optimizer();
    }

    /**
     * @param  callable  $permissionCallback
     * @return array<string, mixed>
     */
    public function args(callable $permissionCallback): array
    {
        return [
            'methods'             => 'POST',
            'permission_callback' => $permissionCallback,
            'callback'            => [$this, 'handle'],
            'args'                => [
                'tables' => [
                    'required' => true,
                    'type'     => 'array',
                ],
            ],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $tables = $request->get_param('tables');
        if (! is_array($tables) || empty($tables)) {
            return new WP_REST_Response([
                'error' => 'tables must be a non-empty array',
            ], 422);
        }

        $envelope = $this->optimizer->optimize($tables);
        return new WP_REST_Response($envelope, 200);
    }
}
