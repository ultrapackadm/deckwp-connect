<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\DbOptimize\Cleaner;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route that runs the selected cleanup categories
 * (revisions, spam, drafts, trash, transients, orphan postmeta,
 * pingbacks).
 *
 *     POST /wp-json/deckwp/v1/db-cleanup
 *     X-DeckWP-Timestamp/Nonce/Signature: ...
 *     Content-Type: application/json
 *
 *     { "categories": ["revisions", "spam", "transients"] }
 *
 *     200 {
 *       "sent_at": 1717684800,
 *       "results": [
 *         {"id": "revisions",  "deleted": 218, "error": null},
 *         {"id": "spam",       "deleted": 47,  "error": null},
 *         {"id": "transients", "deleted": 164, "error": null}
 *       ]
 *     }
 *
 *     422 — categories missing/not an array (operator request was malformed).
 *
 * Triggered by the dashboard's "Clean selected" button. The post-
 * cleanup snapshot is captured separately (Db Optimize tab calls
 * /db-scan after a successful cleanup completes) so the UI shows
 * the new totals without conflating "this cleanup" with "this scan".
 *
 * ## Why ALL categories run (even when one errors)
 *
 * Same fail-isolation posture as SiteHealth: per-category try/catch
 * inside the Cleaner. Operator's "clean these 7 things" intent
 * isn't blocked by one broken category. The dashboard surfaces
 * the per-row error rather than an all-or-nothing rollback.
 *
 * @see \DeckWP\Connect\DbOptimize\Cleaner for the actual DELETE logic.
 */
class DbCleanupRoute
{
    /** @var Cleaner */
    private $cleaner;

    public function __construct(Cleaner $cleaner = null)
    {
        $this->cleaner = $cleaner ?? new Cleaner();
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
                'categories' => [
                    'required' => true,
                    'type'     => 'array',
                ],
            ],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }

        $categories = $request->get_param('categories');
        if (! is_array($categories) || empty($categories)) {
            return new WP_REST_Response([
                'error' => 'categories must be a non-empty array',
            ], 422);
        }

        $envelope = $this->cleaner->clean($categories);
        return new WP_REST_Response($envelope, 200);
    }
}
