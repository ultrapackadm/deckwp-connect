<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\DbOptimize\Scanner;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route that snapshots DB inventory + cleanup targets.
 *
 *     POST /wp-json/deckwp/v1/db-scan
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *
 *     200 {
 *       "sent_at": 1717684800,
 *       "db_name": "wordpress",
 *       "db_version": "8.4.0",
 *       "total_size_bytes": 148897792,
 *       "total_size_human": "142.0 MB",
 *       "total_tables": 87,
 *       "total_overhead_bytes": 4404019,
 *       "total_overhead_human": "4.2 MB",
 *       "tables": [
 *         {
 *           "name": "wp_postmeta",
 *           "engine": "InnoDB",
 *           "rows": 24892,
 *           "data_size_bytes": ...,
 *           "index_size_bytes": ...,
 *           "total_size_bytes": ...,
 *           "overhead_bytes": ...,
 *           "has_overhead": true
 *         },
 *         ...
 *       ],
 *       "cleanup_targets": [
 *         {"id": "revisions", "label": "Post revisions", "count": 218, "savings_bytes": ...},
 *         ...
 *       ]
 *     }
 *
 * Triggered by the dashboard's "Refresh" button on the Db Optimize
 * tab + by the weekly auto-scan cron. The dashboard stores the
 * envelope on a `db_scans` row and renders the tab from it; this
 * route does the read-side work only (no DELETEs, no OPTIMIZE TABLE).
 *
 * ## Timeout
 *
 * Same posture as SiteHealth: SHOW TABLE STATUS + the seven COUNT
 * queries run quickly on a healthy install (~100ms typical), but on
 * a 50M-row wp_postmeta the orphaned-meta JOIN can hit a few
 * seconds. We set `set_time_limit(30)` as a ceiling — plenty for
 * read-only queries.
 *
 * @see \DeckWP\Connect\DbOptimize\Scanner for the actual query logic.
 */
class DbScanRoute
{
    /** @var Scanner */
    private $scanner;

    public function __construct(Scanner $scanner = null)
    {
        $this->scanner = $scanner ?? new Scanner();
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
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(30);
        }

        $envelope = $this->scanner->run();
        return new WP_REST_Response($envelope, 200);
    }
}
