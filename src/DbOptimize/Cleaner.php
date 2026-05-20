<?php

namespace DeckWP\Connect\DbOptimize;

defined('ABSPATH') || exit;

/**
 * Executes the actual DELETEs for the cleanup categories surfaced
 * by {@see Scanner}. Returns per-category counts of rows removed
 * so the dashboard can render a "cleaned X revisions, Y spam
 * comments" summary toast.
 *
 * ## Safety posture
 *
 *   - Categories are checked against an allowlist before any DELETE
 *     runs. Unknown category IDs are skipped (not errored) so a
 *     future Scanner addition doesn't break older connectors via
 *     dashboard payload mismatch.
 *   - Each category uses a single bounded DELETE (no row-by-row
 *     loop) — MySQL handles the row removal in one statement.
 *   - Auto-drafts use the same `> 7 days old` guard as Scanner's
 *     count, so the operator who sees "5 auto-drafts" and clicks
 *     Clean actually gets those 5 rows deleted (not 5 + whatever
 *     they started writing in the past 5 minutes).
 *   - All static SQL — no input flows into table names or column
 *     names. The only user input is the category ID itself, which
 *     branches into a fixed switch, never into the SQL string.
 *
 * ## What this DOES NOT do
 *
 *   - No OPTIMIZE TABLE — that's {@see Optimizer}'s job, run as
 *     a separate REST action so the operator can decide whether
 *     the DELETE'd space gets reclaimed immediately or on the
 *     site's regular optimize cadence.
 *   - No transactions wrapping multi-category sweeps. Each
 *     category is independent; partial failure in category C
 *     doesn't roll back A and B. The dashboard's response lists
 *     per-category status so the operator sees exactly what landed.
 */
class Cleaner
{
    /**
     * Allowlist of category IDs the cleaner knows how to handle.
     * Match must be exact — anything else is silently skipped.
     */
    private const CATEGORIES = [
        'revisions',
        'spam',
        'drafts',
        'trash',
        'transients',
        'orphan_postmeta',
        'pingbacks',
    ];

    /**
     * Run the requested cleanup categories.
     *
     * @param  array<int, string>  $categories  Category IDs from the
     *         Scanner envelope (operator-selected subset).
     * @return array{
     *   sent_at:int,
     *   results:array<int, array{id:string,deleted:int,error:string|null}>
     * }
     */
    public function clean(array $categories): array
    {
        $results = [];

        foreach ($categories as $rawId) {
            $id = is_string($rawId) ? $rawId : '';
            if ($id === '' || ! in_array($id, self::CATEGORIES, true)) {
                $results[] = [
                    'id'      => $id,
                    'deleted' => 0,
                    'error'   => $id === '' ? 'empty category id' : 'unknown category',
                ];
                continue;
            }

            try {
                $deleted = $this->runCategory($id);
                $results[] = [
                    'id'      => $id,
                    'deleted' => $deleted,
                    'error'   => null,
                ];
            } catch (\Throwable $e) {
                // A broken category shouldn't abort the rest of the
                // sweep — same isolation posture as SiteHealth.
                $results[] = [
                    'id'      => $id,
                    'deleted' => 0,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        return [
            'sent_at' => time(),
            'results' => $results,
        ];
    }

    /**
     * @return int  number of rows actually removed
     */
    private function runCategory(string $id): int
    {
        global $wpdb;

        switch ($id) {
            case 'revisions':
                // Delete the revision rows in wp_posts. WP's own
                // wp_delete_post_revision() does extra work
                // (revisions list filter hooks, etc.) we don't need
                // for a bulk sweep — direct DELETE is fine. Postmeta
                // attached to revisions has a foreign-key constraint
                // logic in some hosting setups; we also sweep it
                // explicitly so the savings actually materialize.
                $sql = "DELETE pm FROM {$wpdb->postmeta} pm
                        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                        WHERE p.post_type = 'revision'";
                $wpdb->query($sql);
                return (int) $wpdb->query(
                    "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'"
                );

            case 'spam':
                // wp_comments rows + their matching wp_commentmeta.
                // The meta JOIN runs first so deleting the comments
                // doesn't orphan the meta rows (which would just
                // become a future "orphan_commentmeta" category).
                $sql = "DELETE cm FROM {$wpdb->commentmeta} cm
                        INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
                        WHERE c.comment_approved = 'spam'";
                $wpdb->query($sql);
                return (int) $wpdb->query(
                    "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
                );

            case 'drafts':
                // Auto-drafts >7 days old. Same cutoff as Scanner's
                // count.
                $sql = "DELETE pm FROM {$wpdb->postmeta} pm
                        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                        WHERE p.post_status = 'auto-draft'
                          AND p.post_date < DATE_SUB(NOW(), INTERVAL 7 DAY)";
                $wpdb->query($sql);
                return (int) $wpdb->query(
                    "DELETE FROM {$wpdb->posts}
                     WHERE post_status = 'auto-draft'
                       AND post_date < DATE_SUB(NOW(), INTERVAL 7 DAY)"
                );

            case 'trash':
                // Posts AND comments in trash. Count is the SUM so
                // the dashboard's "X rows deleted" toast reads the
                // way the operator expects.
                $postsMetaSql = "DELETE pm FROM {$wpdb->postmeta} pm
                                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                                 WHERE p.post_status = 'trash'";
                $wpdb->query($postsMetaSql);
                $postsDeleted = (int) $wpdb->query(
                    "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'"
                );

                $commentMetaSql = "DELETE cm FROM {$wpdb->commentmeta} cm
                                   INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
                                   WHERE c.comment_approved = 'trash'";
                $wpdb->query($commentMetaSql);
                $commentsDeleted = (int) $wpdb->query(
                    "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
                );

                return $postsDeleted + $commentsDeleted;

            case 'transients':
                // Expired transients live in pairs in wp_options:
                // _transient_<name> + _transient_timeout_<name>. We
                // identify expired ones via the timeout rows whose
                // option_value < now(), then JOIN to delete the
                // matching value rows + timeout rows together.
                //
                // Site transients (`_site_transient_*`) are stored
                // on multisite in `sitemeta` not `wp_options`; on
                // single-site they're in wp_options. We sweep both
                // patterns.
                $now = time();
                $deleted = 0;

                // Single-site / sub-blog wp_options transients.
                $deleted += (int) $wpdb->query($wpdb->prepare(
                    "DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b
                     WHERE a.option_name LIKE %s
                       AND a.option_name = CONCAT('_transient_timeout_', SUBSTRING(b.option_name, %d))
                       AND b.option_name = CONCAT('_transient_', SUBSTRING(a.option_name, %d))
                       AND a.option_value < %d",
                    $wpdb->esc_like('_transient_timeout_') . '%',
                    strlen('_transient_timeout_') + 1,
                    strlen('_transient_timeout_') + 1,
                    $now
                ));

                // Single-site _site_transient_* (multisite path uses
                // sitemeta which is out of scope for the current-blog
                // sweep — operator running this on the root blog
                // catches the network-wide ones automatically since
                // they live in `wp_sitemeta`, not the per-blog
                // options table).
                $deleted += (int) $wpdb->query($wpdb->prepare(
                    "DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b
                     WHERE a.option_name LIKE %s
                       AND a.option_name = CONCAT('_site_transient_timeout_', SUBSTRING(b.option_name, %d))
                       AND b.option_name = CONCAT('_site_transient_', SUBSTRING(a.option_name, %d))
                       AND a.option_value < %d",
                    $wpdb->esc_like('_site_transient_timeout_') . '%',
                    strlen('_site_transient_timeout_') + 1,
                    strlen('_site_transient_timeout_') + 1,
                    $now
                ));

                return $deleted;

            case 'orphan_postmeta':
                // Same shape as the count query in Scanner.
                return (int) $wpdb->query(
                    "DELETE pm FROM {$wpdb->postmeta} pm
                     LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE p.ID IS NULL"
                );

            case 'pingbacks':
                // Comments + their meta. Spam path already handled
                // 'spam' classification; this path covers the
                // legitimate-but-undesired pingback/trackback
                // entries.
                $sql = "DELETE cm FROM {$wpdb->commentmeta} cm
                        INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
                        WHERE c.comment_type IN ('pingback', 'trackback')";
                $wpdb->query($sql);
                return (int) $wpdb->query(
                    "DELETE FROM {$wpdb->comments}
                     WHERE comment_type IN ('pingback', 'trackback')"
                );

            default:
                // Already filtered by the CATEGORIES allowlist — this
                // branch is unreachable but satisfies the linter.
                return 0;
        }
    }
}
