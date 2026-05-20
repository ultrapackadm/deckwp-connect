<?php

namespace DeckWP\Connect\DbOptimize;

defined('ABSPATH') || exit;

/**
 * Runs `OPTIMIZE TABLE` against a caller-supplied list of tables,
 * reclaiming the `data_free` overhead that {@see Scanner} surfaces
 * as amber-badged "overhead" rows in the dashboard's Tables list.
 *
 * ## Why a separate REST action (not a flag on /db-cleanup)
 *
 * OPTIMIZE TABLE is a fundamentally different operation from DELETE:
 *
 *   - DELETE removes rows from a table that's still doing read/write
 *     work in the meantime. Cheap on any modern InnoDB.
 *   - OPTIMIZE TABLE acquires a metadata lock + rebuilds the table.
 *     On a multi-GB wp_postmeta this can take 30-60s and the table
 *     is read-only during the rebuild. The dashboard wants to
 *     surface that delay explicitly (spinner + "this can take a
 *     while" copy) rather than burying it inside a generic Clean
 *     button.
 *
 * ## SQL injection posture
 *
 * MySQL doesn't accept parameterized table names in `OPTIMIZE TABLE`
 * — the table identifier has to land in the SQL string literally.
 * That makes naive concatenation a classic injection vector. The
 * defense here is a two-layer allowlist:
 *
 *   1. Each requested table name is checked against the result of
 *      `SHOW TABLES` (the install's actual table list). Anything
 *      not present is rejected. SHOW TABLES is the canonical source
 *      — a hostile dashboard payload claiming `wp_posts; DROP TABLE
 *      wp_users` can't match an existing table.
 *   2. The name is also pattern-matched against `^[A-Za-z0-9_]+$`
 *      as defense-in-depth. Any character outside that set
 *      (backticks, spaces, semicolons, quotes) → reject. WP table
 *      names are always plain identifiers; the linter is here to
 *      catch any future MySQL relaxation we haven't thought of.
 *
 * Both gates must pass. The actual `OPTIMIZE TABLE` statement still
 * wraps the name in backticks (`\`{$name}\``) for completeness.
 *
 * ## Multisite scope
 *
 * Same per-blog scope as Scanner — the connector running on blog 1
 * optimizes blog 1's tables, blog 2's connector optimizes blog 2's,
 * etc. Cross-blog optimize sweeps are out of scope for v1.
 */
class Optimizer
{
    /**
     * Optimize a list of tables. Returns per-table results so the
     * dashboard can report success/failure cell-by-cell.
     *
     * @param  array<int, string>  $tables  Table names from the
     *         dashboard payload (operator-selected subset).
     * @return array{
     *   sent_at:int,
     *   results:array<int, array{name:string,optimized:bool,error:string|null,reclaimed_bytes:int|null}>
     * }
     */
    public function optimize(array $tables): array
    {
        global $wpdb;

        $allowedTables = $this->loadAllowedTables();
        $results       = [];

        foreach ($tables as $rawName) {
            $name = is_string($rawName) ? $rawName : '';

            // Layer 1: allowlist.
            if (! in_array($name, $allowedTables, true)) {
                $results[] = [
                    'name'             => $name,
                    'optimized'        => false,
                    'error'            => 'table not found on this install',
                    'reclaimed_bytes'  => null,
                ];
                continue;
            }

            // Layer 2: identifier regex (defense-in-depth).
            if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                $results[] = [
                    'name'             => $name,
                    'optimized'        => false,
                    'error'            => 'invalid identifier characters',
                    'reclaimed_bytes'  => null,
                ];
                continue;
            }

            // Capture data_free before/after so the dashboard can
            // show how much actually got reclaimed.
            $before = $this->dataFreeFor($name);

            // MySQL returns the OPTIMIZE TABLE result as a result-set
            // with status messages — `get_results` reads those out
            // for the response. Status "OK" / "Table is already up
            // to date" → success.
            $rows = $wpdb->get_results(
                "OPTIMIZE TABLE `{$name}`",
                ARRAY_A
            );

            if (! is_array($rows)) {
                $results[] = [
                    'name'             => $name,
                    'optimized'        => false,
                    'error'            => 'OPTIMIZE TABLE returned no rows',
                    'reclaimed_bytes'  => null,
                ];
                continue;
            }

            // Look for any non-OK row in the result set. MySQL emits
            // multiple rows for InnoDB tables ("Table does not
            // support optimize, doing recreate + analyze instead" is
            // status=note + a follow-up status=status with msg=OK).
            $error = null;
            foreach ($rows as $row) {
                $status  = (string) ($row['Msg_type'] ?? '');
                $message = (string) ($row['Msg_text'] ?? '');

                // Errors from MySQL come as Msg_type='Error'. We
                // surface them verbatim so the operator can copy
                // them into a support ticket if needed.
                if (strtolower($status) === 'error') {
                    $error = $message;
                    break;
                }
            }

            if ($error !== null) {
                $results[] = [
                    'name'             => $name,
                    'optimized'        => false,
                    'error'            => $error,
                    'reclaimed_bytes'  => null,
                ];
                continue;
            }

            $after     = $this->dataFreeFor($name);
            $reclaimed = $before !== null && $after !== null
                ? max(0, $before - $after)
                : null;

            $results[] = [
                'name'             => $name,
                'optimized'        => true,
                'error'            => null,
                'reclaimed_bytes'  => $reclaimed,
            ];
        }

        return [
            'sent_at' => time(),
            'results' => $results,
        ];
    }

    /**
     * Snapshot of the install's table list, filtered to the WP
     * prefix (same logic as Scanner). Used as the allowlist for
     * OPTIMIZE TABLE name validation.
     *
     * @return array<int, string>
     */
    private function loadAllowedTables(): array
    {
        global $wpdb;

        $prefix = (string) $wpdb->base_prefix;
        $rows   = $wpdb->get_col('SHOW TABLES');
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $name = (string) $row;
            if ($name !== '' && strpos($name, $prefix) === 0) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Read the current `data_free` (overhead bytes) for a table.
     * Returns null when SHOW TABLE STATUS doesn't return a row for
     * the table (race condition: table dropped between layers).
     */
    private function dataFreeFor(string $name): ?int
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $name),
            ARRAY_A
        );
        if (! is_array($row) || ! isset($row['Data_free'])) {
            return null;
        }
        return (int) $row['Data_free'];
    }
}
