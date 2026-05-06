<?php

namespace DeckWP\Connect\Backup;

defined('ABSPATH') || exit;

/**
 * Local plugin-folder snapshot manager (Sprint 4 v1).
 *
 * Two operations: zip a plugin folder up so we can roll back later
 * ({@see snapshot()}), and extract a previously-snapshotted zip back
 * over the live folder ({@see restore()}). v1 is local-only — the zip
 * lives under `wp-content/uploads/deckwp-backups/`. The dashboard
 * orchestrator owns the metadata row (status, expiry, checksum) and
 * tells the connector which file to restore via the canonical
 * `local_path`.
 *
 * Sprint 5 will layer a B2/S3 upload + DB-dump path on top of this
 * class — both will live as separate methods, the v1 plugin-folder
 * path remains unchanged.
 *
 * ## Wire shape
 *
 *     snapshot('formidable-pro')
 *       → ['ok' => true,
 *          'local_path' => 'wp-content/uploads/deckwp-backups/formidable-pro-2026-05-04T21-30-00-Ax9dfe.zip',
 *          'absolute_path' => '/var/www/.../uploads/deckwp-backups/...zip',
 *          'checksum' => 'sha256-hex',
 *          'size_bytes' => 1234567]
 *       → on failure: ['ok' => false, 'error' => 'why', 'error_code' => 'plugin_not_found' | ...]
 *
 *     restore('/abs/path/to.zip', 'formidable-pro', 'expected-sha256')
 *       → ['ok' => true]
 *       → on failure: ['ok' => false, 'error' => 'why', 'error_code' => '...']
 *
 * ## Why the dashboard sends the `local_path` back on restore
 *
 * The connector is intentionally stateless about backups — it knows
 * how to write a zip and how to read one, but doesn't keep a registry
 * of "which zips exist for which plugins". That registry is the
 * dashboard's `backups` table. Restore therefore takes the path as
 * an explicit argument; the connector just validates it's inside our
 * managed directory (path-traversal defense) before opening it.
 *
 * ## Safety
 *
 * - Slug allowlist regex (`/^[a-z0-9][a-z0-9._-]*$/i`) blocks
 *   `../something`, `slug/with/slash`, and other traversal payloads
 *   before they ever touch the filesystem.
 * - Plugin path resolution uses `realpath()` and checks the result
 *   stays under the plugins root — a symlink farm trying to escape
 *   to `/etc/passwd` fails the realpath sanity check.
 * - Restore extracts to a temp dir first, validates the zip's top-
 *   level matches the slug, THEN swaps the live directory in a
 *   move-old-aside / move-new-into-place sequence. If anything in
 *   the swap fails halfway, the old folder is moved back.
 *
 * ## Bounds
 *
 * - MAX_PLUGIN_DIR_BYTES = 500 MB. Plugins almost never reach 100 MB;
 *   500 MB is comfortably above the worst real-world case (Avada
 *   all-in-one ~70 MB, Beaver Builder ~30 MB). Above that, snapshot
 *   refuses with an explicit error rather than silently producing
 *   a multi-GB zip that fills disk.
 */
class BackupManager
{
    /** Refusal threshold for snapshotting an unusually large plugin. */
    public const MAX_PLUGIN_DIR_BYTES = 500 * 1024 * 1024;

    /** Subdirectory name (under wp-content/uploads/) where zips live. */
    public const BACKUPS_DIR_NAME = 'deckwp-backups';

    /**
     * Snapshot the plugin folder at `wp-content/plugins/<slug>/` into
     * a zip under our managed backups directory.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $slug): array
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === null) {
            return $this->fail('invalid_slug', 'Plugin slug failed validation (allowlist regex).');
        }

        $pluginsDir = $this->pluginsDir();
        if ($pluginsDir === null) {
            return $this->fail('plugins_dir_unresolved', 'Could not resolve the wp-content/plugins/ directory.');
        }

        // Two valid plugin layouts on disk:
        //
        //   1. Folder plugin: wp-content/plugins/<slug>/<slug>.php (+ siblings)
        //      The common case. Most plugins from wp.org and every
        //      premium plugin from the UltraPack catalog.
        //
        //   2. Single-file plugin: wp-content/plugins/<slug>.php
        //      The Hello Dolly pattern — a leftover from WP's earliest
        //      days. The slug WP uses (`hello`) doesn't have a folder;
        //      the file is named `hello.php` directly under plugins/.
        //
        // We snapshot both. Folder plugins go through zipDirectory()
        // as before; single-file plugins go through a special path
        // that wraps the lone file in a `<slug>/` virtual folder
        // inside the zip, so restore() can still expect a uniform
        // layout (the folder shape on extract).
        $sourceDir = $pluginsDir . '/' . $slug;
        $singleFile = $pluginsDir . '/' . $slug . '.php';

        $isFolder = is_dir($sourceDir);
        $isSingleFile = ! $isFolder && is_file($singleFile);

        if (! $isFolder && ! $isSingleFile) {
            return $this->fail(
                'plugin_not_found',
                sprintf(
                    'Plugin "%s" does not exist under wp-content/plugins/ — looked for both a folder (%s/) and a single-file plugin (%s.php).',
                    $slug,
                    $slug,
                    $slug
                )
            );
        }

        // realpath() containment guard against symlink / .. escapes.
        // Same defense for both layouts; we just feed a different
        // resolved path depending on what we found.
        $resolvedSource = realpath($isFolder ? $sourceDir : $singleFile);
        $resolvedPluginsDir = realpath($pluginsDir);
        if ($resolvedSource === false
            || $resolvedPluginsDir === false
            || strncmp($resolvedSource, $resolvedPluginsDir, strlen($resolvedPluginsDir)) !== 0
        ) {
            return $this->fail('path_escape', 'Resolved plugin path is outside wp-content/plugins/.');
        }

        $sizeBytes = $isFolder
            ? $this->dirSizeBytes($resolvedSource)
            : (int) @filesize($resolvedSource);
        if ($sizeBytes > self::MAX_PLUGIN_DIR_BYTES) {
            return $this->fail(
                'plugin_too_large',
                sprintf(
                    'Plugin is %d bytes — over the %d-byte snapshot ceiling. Snapshot refused to avoid filling disk.',
                    $sizeBytes,
                    self::MAX_PLUGIN_DIR_BYTES
                )
            );
        }

        $backupDir = $this->ensureBackupsDir();
        if ($backupDir === null) {
            return $this->fail('backup_dir_uncreatable', 'Could not create or find the deckwp-backups/ directory inside uploads/.');
        }

        $targetZip = $backupDir . '/' . $this->buildZipName($slug);

        $zipResult = $isFolder
            ? $this->zipDirectory($resolvedSource, $slug, $targetZip)
            : $this->zipSingleFile($resolvedSource, $slug, $targetZip);
        if (! $zipResult['ok']) {
            // Belt and suspenders — drop the partial zip if any.
            if (file_exists($targetZip)) {
                @unlink($targetZip);
            }
            return $this->fail($zipResult['error_code'], $zipResult['error']);
        }

        $checksum = @hash_file('sha256', $targetZip);
        if ($checksum === false) {
            @unlink($targetZip);
            return $this->fail('checksum_failed', 'Could not compute SHA-256 of the produced zip — file unreadable.');
        }

        $size = @filesize($targetZip);
        if ($size === false || $size === 0) {
            @unlink($targetZip);
            return $this->fail('zip_empty', 'Zip ended up empty or unreadable after creation.');
        }

        return [
            'ok'             => true,
            'local_path'     => $this->relativeUploadsPath($targetZip),
            'absolute_path'  => $targetZip,
            'checksum'       => $checksum,
            'size_bytes'     => $size,
        ];
    }

    /**
     * Restore a previously-snapshotted zip back over the live plugin
     * folder. `$expectedChecksum` is optional — when provided we
     * refuse to extract a zip whose SHA-256 doesn't match (defends
     * against on-disk corruption between snapshot and restore).
     *
     * @return array<string, mixed>
     */
    public function restore(string $absoluteZipPath, string $slug, ?string $expectedChecksum = null): array
    {
        $slug = $this->sanitizeSlug($slug);
        if ($slug === null) {
            return $this->fail('invalid_slug', 'Plugin slug failed validation.');
        }

        $backupDir = $this->ensureBackupsDir();
        if ($backupDir === null) {
            return $this->fail('backup_dir_unresolved', 'Could not resolve the deckwp-backups/ directory.');
        }

        $resolvedZip = realpath($absoluteZipPath);
        $resolvedBackupDir = realpath($backupDir);
        if ($resolvedZip === false || $resolvedBackupDir === false) {
            return $this->fail('zip_not_found', 'Backup zip not found at the given path.');
        }
        if (strncmp($resolvedZip, $resolvedBackupDir, strlen($resolvedBackupDir)) !== 0) {
            return $this->fail('path_escape', 'Backup zip path is outside the managed backups directory.');
        }

        if ($expectedChecksum !== null && $expectedChecksum !== '') {
            $actual = @hash_file('sha256', $resolvedZip);
            if ($actual === false) {
                return $this->fail('checksum_failed', 'Could not read backup zip to verify checksum.');
            }
            if (! hash_equals($expectedChecksum, $actual)) {
                return $this->fail('checksum_mismatch', 'Backup zip SHA-256 does not match the expected value — file may be corrupt.');
            }
        }

        $pluginsDir = $this->pluginsDir();
        if ($pluginsDir === null) {
            return $this->fail('plugins_dir_unresolved', 'Could not resolve the wp-content/plugins/ directory.');
        }

        // Extract into a uniquely-named sibling so a half-extracted
        // payload never collides with the live plugin folder, then
        // swap atomically.
        $tempExtractDir = $pluginsDir . '/.deckwp-restore-' . $slug . '-' . bin2hex(random_bytes(4));
        $extractResult = $this->extractZip($resolvedZip, $tempExtractDir, $slug);
        if (! $extractResult['ok']) {
            $this->recursiveDelete($tempExtractDir);
            return $this->fail($extractResult['error_code'], $extractResult['error']);
        }

        // Sanity: the extracted folder structure should expose
        // `<tempExtractDir>/<slug>/...`. If a future snapshot uses a
        // different layout, fail loudly here rather than silently
        // restoring nothing.
        $extractedSlugDir = $tempExtractDir . '/' . $slug;
        if (! is_dir($extractedSlugDir)) {
            $this->recursiveDelete($tempExtractDir);
            return $this->fail('zip_layout_unexpected', sprintf('Backup zip did not contain a top-level "%s/" folder — refusing to restore.', $slug));
        }

        // Detect single-file plugin layout: zip contains exactly
        // one file at `<slug>/<slug>.php` and no other entries. The
        // restore target on disk is `wp-content/plugins/<slug>.php`,
        // not a folder. Both cases share the move-aside / move-new
        // / rollback choreography but differ in which path becomes
        // the live target.
        $singleFile = $this->detectSingleFilePlugin($extractedSlugDir, $slug);

        if ($singleFile !== null) {
            return $this->restoreSingleFile(
                $tempExtractDir,
                $singleFile,
                $pluginsDir,
                $slug
            );
        }

        $liveTarget = $pluginsDir . '/' . $slug;
        $aside = $pluginsDir . '/.deckwp-old-' . $slug . '-' . bin2hex(random_bytes(4));

        // Move-old-aside (only if there's a live folder; first-time
        // restore after a totally-failed install may not have one).
        $hadLive = is_dir($liveTarget);
        if ($hadLive) {
            if (! @rename($liveTarget, $aside)) {
                $this->recursiveDelete($tempExtractDir);
                return $this->fail('rename_failed', 'Could not move the live plugin folder aside before restore.');
            }
        }

        // Move-new-into-place.
        if (! @rename($extractedSlugDir, $liveTarget)) {
            // Roll back the move-aside.
            if ($hadLive) {
                @rename($aside, $liveTarget);
            }
            $this->recursiveDelete($tempExtractDir);
            return $this->fail('rename_failed', 'Could not move the extracted folder into place; rolled the live folder back.');
        }

        // Cleanup: drop the temp-extract scratchpad and the moved-aside
        // old folder. Failures here are non-fatal — restore succeeded;
        // these leave clutter but don't break anything.
        $this->recursiveDelete($tempExtractDir);
        if ($hadLive) {
            $this->recursiveDelete($aside);
        }

        return ['ok' => true];
    }

    /**
     * Detect single-file plugin layout in an extracted snapshot.
     * Returns the absolute path of the single PHP file when the
     * extracted dir contains exactly one entry that's a *.php
     * file; null otherwise (folder plugin, multi-file).
     */
    private function detectSingleFilePlugin(string $extractedSlugDir, string $slug): ?string
    {
        $entries = @scandir($extractedSlugDir);
        if ($entries === false) {
            return null;
        }
        $real = array_values(array_filter($entries, fn ($e) => $e !== '.' && $e !== '..'));
        if (count($real) !== 1) {
            return null;
        }
        $candidate = $extractedSlugDir . '/' . $real[0];
        if (! is_file($candidate)) {
            return null;
        }
        if (substr(strtolower($real[0]), -4) !== '.php') {
            return null;
        }
        return $candidate;
    }

    /**
     * Restore a single-file plugin (Hello Dolly pattern). The
     * live target is `wp-content/plugins/<filename>.php`, not a
     * folder — same move-aside / move-new / rollback dance as
     * the folder path, just on a file.
     *
     * @return array<string, mixed>
     */
    private function restoreSingleFile(string $tempExtractDir, string $extractedFile, string $pluginsDir, string $slug): array
    {
        $filename = basename($extractedFile);
        $liveTarget = $pluginsDir . '/' . $filename;
        $aside = $pluginsDir . '/.deckwp-old-' . $slug . '-' . bin2hex(random_bytes(4)) . '.php';

        $hadLive = file_exists($liveTarget);
        if ($hadLive) {
            if (! @rename($liveTarget, $aside)) {
                $this->recursiveDelete($tempExtractDir);
                return $this->fail('rename_failed', 'Could not move the live single-file plugin aside before restore.');
            }
        }

        if (! @rename($extractedFile, $liveTarget)) {
            if ($hadLive) {
                @rename($aside, $liveTarget);
            }
            $this->recursiveDelete($tempExtractDir);
            return $this->fail('rename_failed', 'Could not move the extracted single-file plugin into place; rolled back.');
        }

        $this->recursiveDelete($tempExtractDir);
        if ($hadLive) {
            @unlink($aside);
        }

        return ['ok' => true];
    }

    /**
     * Delete a previously-snapshotted zip from disk. Used by the
     * dashboard's retention cron once a backup is past its
     * `expires_at` cutoff — the zip stops being useful (the
     * dashboard already moved on) but eats disk on the customer
     * server until something cleans it up.
     *
     * Idempotent: returns ok=true if the file is already gone
     * (the dashboard might re-issue a delete after a transient
     * failure, and the second call must not surface as a fault).
     *
     * Path is validated to live inside the managed
     * deckwp-backups/ directory before unlinking — same
     * defense-in-depth posture as restore().
     *
     * @return array<string, mixed>
     */
    public function delete(string $absoluteZipPath): array
    {
        $backupDir = $this->ensureBackupsDir();
        if ($backupDir === null) {
            return $this->fail('backup_dir_unresolved', 'Could not resolve the deckwp-backups/ directory.');
        }

        $resolvedBackupDir = realpath($backupDir);
        if ($resolvedBackupDir === false) {
            return $this->fail('backup_dir_unresolved', 'Could not realpath the deckwp-backups/ directory.');
        }

        $resolvedZip = realpath($absoluteZipPath);
        if ($resolvedZip === false) {
            // File is already gone — treat as success so the
            // dashboard can mark the row Expired without retrying
            // forever on a no-op.
            return ['ok' => true, 'already_gone' => true];
        }

        if (strncmp($resolvedZip, $resolvedBackupDir, strlen($resolvedBackupDir)) !== 0) {
            return $this->fail('path_escape', 'Backup zip path is outside the managed backups directory.');
        }

        if (! @unlink($resolvedZip)) {
            return $this->fail('unlink_failed', 'Could not unlink the zip — file may be locked or permissions are wrong.');
        }

        return ['ok' => true];
    }

    /* --------------------------------------------------------------
     | Internals
     |-------------------------------------------------------------- */

    /**
     * @return array{ok: bool, error?: string, error_code?: string}
     */
    private function zipDirectory(string $sourceDir, string $rootName, string $targetZip): array
    {
        if (! class_exists('\\ZipArchive')) {
            return ['ok' => false, 'error_code' => 'zip_unavailable', 'error' => 'PHP ZipArchive extension not loaded.'];
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($targetZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            return ['ok' => false, 'error_code' => 'zip_open_failed', 'error' => 'Could not open zip for writing (code ' . (int) $opened . ').'];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $absolute = $file->getPathname();
                // Strip the source-dir prefix to get a path relative to
                // the plugin folder, then prefix with `<slug>/` so the
                // zip's top-level mirrors what restore() expects.
                $rel = ltrim(substr($absolute, strlen($sourceDir)), '/\\');
                $rel = str_replace('\\', '/', $rel);
                $entryName = $rootName . '/' . $rel;
                if (! $zip->addFile($absolute, $entryName)) {
                    $zip->close();
                    return ['ok' => false, 'error_code' => 'zip_add_failed', 'error' => 'Could not add file to zip: ' . $entryName];
                }
            }
        } catch (\Throwable $e) {
            $zip->close();
            return ['ok' => false, 'error_code' => 'zip_iteration_failed', 'error' => 'Iteration failed: ' . $e->getMessage()];
        }

        if (! $zip->close()) {
            return ['ok' => false, 'error_code' => 'zip_close_failed', 'error' => 'ZipArchive::close() returned false; zip likely incomplete.'];
        }

        return ['ok' => true];
    }

    /**
     * Zip a single-file plugin (Hello Dolly pattern). The lone
     * `<slug>.php` file goes into the zip under `<slug>/<slug>.php`
     * so the on-disk zip layout is uniform with folder plugins —
     * restore() can rely on the same `<slug>/` top-level entry
     * shape either way and decide single-file vs folder based on
     * the zip's contents (one file ⇔ single-file plugin).
     *
     * @return array{ok: bool, error?: string, error_code?: string}
     */
    private function zipSingleFile(string $sourceFile, string $rootName, string $targetZip): array
    {
        if (! class_exists('\\ZipArchive')) {
            return ['ok' => false, 'error_code' => 'zip_unavailable', 'error' => 'PHP ZipArchive extension not loaded.'];
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($targetZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            return ['ok' => false, 'error_code' => 'zip_open_failed', 'error' => 'Could not open zip for writing (code ' . (int) $opened . ').'];
        }

        $entryName = $rootName . '/' . basename($sourceFile);
        if (! $zip->addFile($sourceFile, $entryName)) {
            $zip->close();
            return ['ok' => false, 'error_code' => 'zip_add_failed', 'error' => 'Could not add file to zip: ' . $entryName];
        }

        if (! $zip->close()) {
            return ['ok' => false, 'error_code' => 'zip_close_failed', 'error' => 'ZipArchive::close() returned false; zip likely incomplete.'];
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string, error_code?: string}
     */
    private function extractZip(string $zipPath, string $targetDir, string $slug): array
    {
        if (! class_exists('\\ZipArchive')) {
            return ['ok' => false, 'error_code' => 'zip_unavailable', 'error' => 'PHP ZipArchive extension not loaded.'];
        }

        if (! wp_mkdir_p($targetDir)) {
            return ['ok' => false, 'error_code' => 'extract_dir_uncreatable', 'error' => 'Could not create extract target directory.'];
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            return ['ok' => false, 'error_code' => 'zip_open_failed', 'error' => 'Could not open backup zip (code ' . (int) $opened . ').'];
        }

        // Defensive sweep over the entry list — refuse anything that
        // tries to write outside the slug subdirectory (zip-slip).
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();
                return ['ok' => false, 'error_code' => 'zip_corrupt', 'error' => 'Could not read zip entry index ' . $i . '.'];
            }
            $name = (string) $stat['name'];
            $normalized = str_replace('\\', '/', $name);
            if (strpos($normalized, '../') !== false || strpos($normalized, '..\\') !== false) {
                $zip->close();
                return ['ok' => false, 'error_code' => 'zip_traversal', 'error' => 'Zip entry contains path traversal: ' . $name];
            }
            // Top-level must start with `<slug>/`. Loose entries
            // outside that folder are a sign of a corrupt or hostile
            // archive.
            if (strpos($normalized, $slug . '/') !== 0 && $normalized !== $slug) {
                $zip->close();
                return ['ok' => false, 'error_code' => 'zip_unexpected_entry', 'error' => 'Zip entry "' . $name . '" is outside the expected slug root.'];
            }
        }

        if (! $zip->extractTo($targetDir)) {
            $zip->close();
            return ['ok' => false, 'error_code' => 'extract_failed', 'error' => 'ZipArchive::extractTo() returned false.'];
        }

        $zip->close();
        return ['ok' => true];
    }

    /** Returns null if the slug fails validation. */
    private function sanitizeSlug(string $slug): ?string
    {
        // wp.org plugin slugs are kebab-case; we additionally allow
        // dots and underscores for legacy / commercial plugins that
        // sometimes use them. No slashes, no spaces, no leading dots.
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $slug)) {
            return null;
        }
        return $slug;
    }

    /** Path to wp-content/plugins/, or null if WP didn't define WP_PLUGIN_DIR. */
    private function pluginsDir(): ?string
    {
        if (defined('WP_PLUGIN_DIR')) {
            return rtrim(WP_PLUGIN_DIR, '/\\');
        }
        if (defined('WP_CONTENT_DIR')) {
            return rtrim(WP_CONTENT_DIR, '/\\') . '/plugins';
        }
        return null;
    }

    /**
     * Ensure `wp-content/uploads/deckwp-backups/` exists with our
     * protection drop-ins. Returns null on failure (uploads dir
     * missing, mkdir refused, etc.).
     */
    private function ensureBackupsDir(): ?string
    {
        if (! function_exists('wp_get_upload_dir') || ! function_exists('wp_mkdir_p')) {
            return null;
        }
        $uploads = wp_get_upload_dir();
        if (! empty($uploads['error'])) {
            return null;
        }
        $base = rtrim((string) ($uploads['basedir'] ?? ''), '/\\');
        if ($base === '') {
            return null;
        }

        $dir = $base . '/' . self::BACKUPS_DIR_NAME;
        if (! wp_mkdir_p($dir)) {
            return null;
        }

        // Drop-in protection files. Apache: .htaccess denies all.
        // Nginx: doesn't read .htaccess, but the `index.php` blanks out
        // directory listings and the random suffix in zip names makes
        // enumeration impractical. Operators on nginx should add a
        // location block denying this dir explicitly — documented in
        // the connector README.
        $htaccess = $dir . '/.htaccess';
        if (! file_exists($htaccess)) {
            @file_put_contents(
                $htaccess,
                "# Auto-generated by DeckWP Connect — do not edit.\n"
                . "<IfModule mod_authz_core.c>\n"
                . "    Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    Order deny,allow\n"
                . "    Deny from all\n"
                . "</IfModule>\n"
            );
        }
        $indexPhp = $dir . '/index.php';
        if (! file_exists($indexPhp)) {
            @file_put_contents($indexPhp, "<?php\n// Silence is golden.\n");
        }

        return $dir;
    }

    /**
     * Generate a unique zip filename. Format:
     *   {slug}-{Y-m-dTH-i-s}-{6 hex chars}.zip
     * The random suffix avoids collisions when two snapshots fire
     * within the same wall-clock second (rare but possible during
     * bulk update flows).
     */
    private function buildZipName(string $slug): string
    {
        $ts = gmdate('Y-m-d\TH-i-s');
        $rand = bin2hex(random_bytes(3));
        return $slug . '-' . $ts . '-' . $rand . '.zip';
    }

    /**
     * Sum of file sizes under a directory tree. Skips unreadable
     * entries (returns the partial sum we could measure).
     */
    private function dirSizeBytes(string $dir): int
    {
        $bytes = 0;
        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iter as $file) {
                if ($file->isFile()) {
                    $bytes += (int) $file->getSize();
                }
            }
        } catch (\Throwable $e) {
            // Fall through with whatever we measured so far.
        }
        return $bytes;
    }

    /** Convert an absolute path inside uploads/ to a relative-from-uploads form. */
    private function relativeUploadsPath(string $absolute): string
    {
        if (! function_exists('wp_get_upload_dir')) {
            return $absolute;
        }
        $uploads = wp_get_upload_dir();
        $base = rtrim((string) ($uploads['basedir'] ?? ''), '/\\');
        if ($base === '') {
            return $absolute;
        }
        $normalizedAbs = str_replace('\\', '/', $absolute);
        $normalizedBase = str_replace('\\', '/', $base);
        if (strncmp($normalizedAbs, $normalizedBase, strlen($normalizedBase)) === 0) {
            // Strip the base + leading slash so the relative form
            // looks like "deckwp-backups/{slug}-{ts}.zip".
            return ltrim(substr($normalizedAbs, strlen($normalizedBase)), '/');
        }
        return $absolute;
    }

    /** Best-effort recursive delete — silent on missing entries. */
    private function recursiveDelete(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $entry) {
                if ($entry->isDir()) {
                    @rmdir($entry->getPathname());
                } else {
                    @unlink($entry->getPathname());
                }
            }
        } catch (\Throwable $e) {
            // Nothing more to do; leftovers will be cleaned by the
            // retention cron eventually.
        }
        @rmdir($path);
    }

    /**
     * @return array{ok: false, error: string, error_code: string}
     */
    private function fail(string $code, string $message): array
    {
        return [
            'ok'         => false,
            'error_code' => $code,
            'error'      => $message,
        ];
    }
}
