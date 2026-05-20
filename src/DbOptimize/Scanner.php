<?php

namespace DeckWP\Connect\DbOptimize;

defined('ABSPATH') || exit;

/**
 * Database inventory + cleanup-target snapshot.
 *
 * Powers the dashboard's `/sites/{site}/db-optimize` view: how big is
 * the install's DB, which tables carry overhead, and how many rows
 * are sitting in each well-known "cleanable" bucket (post revisions,
 * spam comments, auto-drafts, trash, expired transients, orphaned
 * postmeta, pingbacks/trackbacks). The dashboard renders the stat
 * cards + Quick Cleanups + Tables list from this single snapshot.
 *
 * ## Why a snapshot (not on-demand queries per UI click)
 *
 * Mirrors the SiteHealth pattern: the connector runs the read-side
 * work once, the dashboard stores the envelope in a `db_scans` row,
 * and every render of the Db Optimize tab reads from that row. The
 * operator clicking "Run optimization" or "Clean selected" then
 * dispatches an explicit cleanup/optimize action that re-snapshots
 * the DB at the end so the UI reflects the new state.
 *
 * Doing the surveys on every page-load would burn ~7 COUNT queries
 * + SHOW TABLE STATUS on each render — fine for a dashboard with
 * 10 sites, not fine at 1000.
 *
 * ## Multisite posture (current scope: per-blog)
 *
 * On a multisite install, the snapshot is scoped to the CURRENT
 * blog's tables. The dashboard pairs against a single connector
 * URL per site row, and that connector handles whichever blog
 * answered the REST request — wp_options / wp_posts / wp_comments
 * for blog 1, wp_2_options / wp_2_posts / wp_2_comments for blog 2,
 * etc. Cross-blog sweeps are out of scope for v1; an operator who
 * pairs blog 1 sees blog 1's bytes, not the entire network's.
 *
 * `$wpdb` is already scoped correctly when WP serves a REST request
 * on a sub-blog (multisite routing happens before `rest_api_init`),
 * so no explicit switch_to_blog needed here.
 *
 * ## Auto-draft cutoff
 *
 * The "auto-drafts" cleanup target excludes drafts created in the
 * last 7 days — WP's own DB cleanup follows the same convention
 * (`wp-cron` deletes auto-drafts > 7 days old by default). Without
 * the cutoff, we'd be reporting drafts that the operator might
 * still be editing as "cleanable", which would erode trust.
 */
class Scanner
{
    /**
     * Take a read-only snapshot of the install's DB state.
     *
     * @return array{
     *   sent_at:int,
     *   db_name:string,
     *   db_version:string,
     *   total_size_bytes:int,
     *   total_size_human:string,
     *   total_tables:int,
     *   total_overhead_bytes:int,
     *   total_overhead_human:string,
     *   tables:array<int, array<string, mixed>>,
     *   cleanup_targets:array<int, array<string, mixed>>
     * }
     */
    public function run(): array
    {
        global $wpdb;

        $tables   = $this->collectTables();
        $cleanups = $this->collectCleanupTargets();

        $totalBytes    = 0;
        $totalOverhead = 0;
        foreach ($tables as $row) {
            $totalBytes    += (int) ($row['total_size_bytes'] ?? 0);
            $totalOverhead += (int) ($row['overhead_bytes']  ?? 0);
        }

        // DB engine version — useful for the dashboard to flag installs
        // running EOL MySQL/MariaDB without an extra round-trip. WP's
        // `$wpdb->db_version()` returns the SERVER version (not the
        // client-library version), which is what we want here.
        $dbVersion = method_exists($wpdb, 'db_version') ? (string) $wpdb->db_version() : '';

        return [
            'sent_at'              => time(),
            'db_name'              => defined('DB_NAME') ? (string) DB_NAME : '',
            'db_version'           => $dbVersion,
            'total_size_bytes'     => $totalBytes,
            'total_size_human'     => $this->formatBytes($totalBytes),
            'total_tables'         => count($tables),
            'total_overhead_bytes' => $totalOverhead,
            'total_overhead_human' => $this->formatBytes($totalOverhead),
            'tables'               => $tables,
            'cleanup_targets'      => $cleanups,
        ];
    }

    /**
     * One row per table on the install's DB. Source: `SHOW TABLE STATUS`,
     * a single query that returns engine + row count + data/index size
     * + free-space-after-deletes for every table the connecting user
     * can see.
     *
     * The `data_free` field is what shows up as "overhead" in the UI —
     * it's the unreclaimed space MyISAM/InnoDB leaves behind after
     * DELETEs that `OPTIMIZE TABLE` can reclaim. On InnoDB tables
     * stored in the shared system tablespace, `data_free` is meaningful
     * but reclaiming requires server-level operations the connector
     * deliberately doesn't perform. For tables in their own files
     * (`innodb_file_per_table=ON`, the modern default), `OPTIMIZE TABLE`
     * does the right thing.
     *
     * @return array<int, array{name:string,engine:string,rows:int,data_size_bytes:int,index_size_bytes:int,total_size_bytes:int,overhead_bytes:int,has_overhead:bool}>
     */
    private function collectTables(): array
    {
        global $wpdb;

        // SHOW TABLE STATUS returns all tables in the current DB. Filter
        // to tables that match the WP prefix so a shared DB hosting
        // multiple WP installs (or non-WP apps) doesn't bleed unrelated
        // tables into the dashboard. base_prefix catches both single-
        // site (`wp_`) and multisite blog tables (`wp_2_`, `wp_3_`).
        $prefix = (string) $wpdb->base_prefix;

        $rows = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Name'] ?? '');
            if ($name === '' || strpos($name, $prefix) !== 0) {
                continue;
            }

            $dataLen  = (int) ($row['Data_length']  ?? 0);
            $indexLen = (int) ($row['Index_length'] ?? 0);
            $dataFree = (int) ($row['Data_free']    ?? 0);
            $total    = $dataLen + $indexLen;

            // Threshold for "worth reclaiming" — anything under 64 KB
            // is noise (filesystem block padding, etc.) and the operator
            // shouldn't see it badged amber. The dashboard's stat card
            // uses its own >1 MB threshold for the aggregate; per-row
            // we're stricter so individual table buttons don't light
            // up for ~zero gain.
            $hasOverhead = $dataFree >= 64 * 1024;

            $out[] = [
                'name'              => $name,
                'engine'            => (string) ($row['Engine'] ?? ''),
                'rows'              => (int) ($row['Rows'] ?? 0),
                'data_size_bytes'   => $dataLen,
                'index_size_bytes'  => $indexLen,
                'total_size_bytes'  => $total,
                'overhead_bytes'    => $dataFree,
                'has_overhead'      => $hasOverhead,
            ];
        }

        // Largest first — matches the dashboard's mockup ordering and
        // keeps the eye on the high-impact tables in the rendered list.
        usort($out, static function ($a, $b) {
            return ($b['total_size_bytes'] ?? 0) <=> ($a['total_size_bytes'] ?? 0);
        });

        return $out;
    }

    /**
     * One row per cleanup category. The shape matches what the
     * dashboard's Quick Cleanups card expects: each entry has an `id`
     * (used by /db-cleanup to identify the category), a label, the
     * current row count, and an estimated reclaimable bytes figure.
     *
     * "Estimated" because we can't compute exact savings without
     * running the DELETE — we use an average-row-size heuristic per
     * category that's accurate enough for the UI's "Save X MB" chip.
     *
     * @return array<int, array{id:string,label:string,count:int,savings_bytes:int}>
     */
    private function collectCleanupTargets(): array
    {
        global $wpdb;

        $out = [];

        // Post revisions. Stored as full rows in wp_posts with
        // `post_type='revision'`. Each revision carries the full
        // post_content of the version it represents — large posts
        // accumulate quickly on heavily-edited installs.
        $revisions = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
        );
        $out[] = [
            'id'            => 'revisions',
            'label'         => 'Post revisions',
            'count'         => $revisions,
            // Average revision ~50 KB (post_content + meta + the row's
            // wp_postmeta entries). Underestimates large posts,
            // overestimates short ones; OK as a UI affordance.
            'savings_bytes' => $revisions * 50 * 1024,
        ];

        // Spam comments. The "spam" status is set by Akismet + other
        // anti-spam plugins. These can pile up on busy comment threads
        // and bloat wp_comments + wp_commentmeta.
        $spam = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s",
                'spam'
            )
        );
        $out[] = [
            'id'            => 'spam',
            'label'         => 'Spam comments',
            'count'         => $spam,
            'savings_bytes' => $spam * 2 * 1024,
        ];

        // Auto-drafts older than 7 days. WP creates an "auto-draft"
        // post every time someone clicks "Add new" but doesn't save —
        // these accumulate indefinitely on multi-author sites. WP's
        // own daily wp-cron sweep deletes them after 7 days; we
        // surface the count so the operator can clean them faster if
        // they want.
        $drafts = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_status = 'auto-draft'
               AND post_date < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $out[] = [
            'id'            => 'drafts',
            'label'         => 'Auto-drafts (>7d)',
            'count'         => $drafts,
            'savings_bytes' => $drafts * 10 * 1024,
        ];

        // Trashed posts AND trashed comments. Combined into a single
        // category — the operator's mental model is "empty the trash",
        // not "empty the post trash and then empty the comment trash".
        $trashPosts = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"
        );
        $trashComments = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s",
                'trash'
            )
        );
        $out[] = [
            'id'            => 'trash',
            'label'         => 'Trashed posts + comments',
            'count'         => $trashPosts + $trashComments,
            'savings_bytes' => ($trashPosts * 30 * 1024) + ($trashComments * 2 * 1024),
        ];

        // Expired transients. WP stores transients as pairs of rows
        // in wp_options: `_transient_<name>` (the value) and
        // `_transient_timeout_<name>` (the expiry timestamp). A
        // transient is expired when its timeout value < now. We count
        // each PAIR (timeout row) since deleting requires also
        // deleting the matching value row.
        $now = time();
        $expiredTransients = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                   AND option_value < %d",
                $wpdb->esc_like('_transient_timeout_') . '%',
                $now
            )
        );
        $out[] = [
            'id'            => 'transients',
            'label'         => 'Expired transients',
            'count'         => $expiredTransients,
            // Multiply by 2 because each timeout row implies a value
            // row we also delete. Average ~1 KB per row.
            'savings_bytes' => $expiredTransients * 2 * 1024,
        ];

        // Orphaned postmeta — wp_postmeta rows whose post_id no longer
        // exists in wp_posts (left over from deleted posts where the
        // delete didn't cascade the meta). Common on installs that
        // ran early WP versions or used buggy plugins.
        $orphanMeta = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.ID IS NULL"
        );
        $out[] = [
            'id'            => 'orphan_postmeta',
            'label'         => 'Orphaned postmeta rows',
            'count'         => $orphanMeta,
            'savings_bytes' => $orphanMeta * 512,
        ];

        // Pingbacks + trackbacks. Distinct from spam — these are
        // legitimately classified `comment_type` entries that most
        // operators don't display anyway. WP discourages pingbacks
        // since ~2017 (XML-RPC attack surface).
        $pingbacks = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->comments}
             WHERE comment_type IN ('pingback', 'trackback')"
        );
        $out[] = [
            'id'            => 'pingbacks',
            'label'         => 'Pingbacks + trackbacks',
            'count'         => $pingbacks,
            'savings_bytes' => $pingbacks * 1024,
        ];

        return $out;
    }

    /**
     * Render a byte count as "X MB" / "X KB" / "X GB" for the
     * dashboard's stat cards. WP's `size_format()` does the same
     * but is admin-side; reimplementing inline keeps the connector
     * REST handler free of wp-admin/* require_once chains.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = (int) floor(log($bytes, 1024));
        $i     = max(0, min($i, count($units) - 1));
        $value = $bytes / pow(1024, $i);
        // 1 decimal for KB+; integer for raw B.
        return $i === 0
            ? sprintf('%d %s', $value, $units[$i])
            : sprintf('%.1f %s', $value, $units[$i]);
    }
}
