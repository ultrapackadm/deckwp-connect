<?php

namespace DeckWP\Connect\Scan;

defined('ABSPATH') || exit;

/**
 * Local file-system scanner that surfaces obvious compromise signals
 * on a WordPress install.
 *
 * v0.3.0 ships three checks — each fast, deterministic, and free of
 * external API calls so the scan can run in-request on a 60s budget:
 *
 *   1. PHP files inside `wp-content/uploads/`. Uploads should hold
 *      media; a `.php` there is a high-signal artifact of webshell
 *      uploads and similar exploit kits.
 *
 *   2. Obfuscation patterns (`eval` wrapped around `base64_decode`,
 *      `gzinflate` or `str_rot13`) anywhere in `plugins/` or
 *      `themes/`. These three are the workhorse signatures of injected
 *      PHP backdoors — high precision, low false-positive rate against
 *      legitimate plugin code.
 *
 *   3. wp-config.php with the world-writable bit set. Operationally
 *      common on shared hosts where a panicked operator chmod 777'd
 *      the file to "fix permissions"; lets unprivileged users on the
 *      same server read DB credentials.
 *
 * ## Precision
 *
 * A scanner that cries wolf on a clean install is worse than no
 * scanner, because the operator learns to dismiss it. An end-to-end
 * pass on a brand-new WordPress produced three findings, all of them
 * wrong, and all three from the same mistake — treating a textual
 * match as a behavioural one:
 *
 *   - `uploads/deckwp-backups/index.php`, reported critical. It is the
 *     directory-listing stub DeckWP writes itself, and WordPress core,
 *     WooCommerce and most hosts write the identical two lines all over
 *     wp-content. A PHP file with no executable statement in it cannot
 *     be a webshell, so {@see self::isInertPhpStub()} settles the
 *     question by parsing rather than by maintaining a list of
 *     filenames we forgive.
 *
 *   - This very file, reported critical, because the paragraph above
 *     used to spell one of the signatures out and the signature matched
 *     its own documentation. Code inside a comment does not run, so
 *     {@see self::matchIsInsideComment()} drops those matches. Any
 *     security plugin that ships a signature list — Wordfence, Sucuri,
 *     and us — was being flagged for describing malware.
 *
 *   - `wp-config.php`, reported world-writable at mode 0666 on a
 *     Windows host, where `fileperms()` reports 0666 for every writable
 *     file because NTFS has no POSIX mode bits to report. The check now
 *     runs only where the answer means something.
 *
 * Each of the three is fixed by narrowing what counts as evidence, not
 * by exempting DeckWP's own files. The connector's directory is scanned
 * like any other plugin's, because "the attacker put it in the security
 * plugin's folder" is a real thing that happens.
 *
 * Scope omissions (deliberate, planned for later releases):
 *   - WP core file integrity (needs the wp.org checksums API call —
 *     network-dependent, doesn't fit the offline-first philosophy
 *     of the MVP).
 *   - Plugin/theme integrity (same — would need wp.org checksums
 *     per slug+version).
 *   - Database scan (suspicious admin users, malicious options).
 *   - Outdated TLS / weak passwords (out of scope for filesystem
 *     scanner).
 *
 * Bounds:
 *   - MAX_FINDINGS hard-caps the response payload at 50; if the
 *     scan would exceed that, the result envelope sets
 *     `truncated=true` and the operator sees the first 50.
 *   - MAX_CONTENT_SCAN_BYTES skips reading any single PHP file
 *     larger than 5 MB. Real plugins almost never have files that
 *     big, and the ones that do (compiled assets, vendor dumps)
 *     don't need to be regex-scanned.
 *   - SKIP_DIRS lists subdirectory names walked over wholesale —
 *     `vendor/`, `node_modules/`, etc., where false positives in
 *     PHP regex-style strings are common and signal-to-noise is
 *     low.
 *
 * Output shape (consumed by the dashboard's ScanIngestProcessor —
 * keep these field names stable across versions):
 *
 *     [
 *         'scanned_at'     => int,    // Unix timestamp.
 *         'duration_ms'    => int,    // Wall time the scan took.
 *         'findings_count' => int,    // Total findings the scanner saw,
 *                                     // not just the slice we kept.
 *         'findings'       => array,  // Up to MAX_FINDINGS items.
 *         'stats'          => [
 *             'files_scanned' => int,
 *             'directories'   => int,
 *         ],
 *         'truncated' => bool,        // findings_count > MAX_FINDINGS
 *     ]
 *
 * Each finding:
 *
 *     [
 *         'type'        => string,    // Stable machine identifier.
 *         'severity'    => 'info'|'warning'|'critical',
 *         'path'        => string,    // Relative to ABSPATH.
 *         'description' => string,    // Human-readable, dashboard renders verbatim.
 *         'line'        => int|null,  // For pattern hits.
 *         'evidence'    => string|null, // Short snippet for triage.
 *     ]
 */
class Scanner
{
    /** Soft cap on findings reported, to keep payload size bounded. */
    public const MAX_FINDINGS = 50;

    /** Skip the body-scan for any single PHP file bigger than this. */
    private const MAX_CONTENT_SCAN_BYTES = 5 * 1024 * 1024;

    /**
     * Ceiling for {@see self::isInertPhpStub()}. A directory-listing
     * stub is two lines; nothing above this needs the benefit of the
     * doubt.
     */
    private const MAX_STUB_BYTES = 4096;

    /** Directory names skipped wholesale during plugins/themes walks. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git', '.svn', 'tests', 'test'];

    /**
     * Compiled regexes for the three obfuscation signatures we flag.
     * Keyed by the `type` field they emit so the dashboard can group
     * findings without parsing the description.
     *
     * @var array<string, string>
     */
    private const MALWARE_PATTERNS = [
        'eval_base64'    => '/eval\s*\(\s*base64_decode\s*\(/i',
        'eval_gzinflate' => '/eval\s*\(\s*gzinflate\s*\(/i',
        'eval_str_rot13' => '/eval\s*\(\s*str_rot13\s*\(/i',
    ];

    /**
     * Run the full scan and return a result envelope. Caller is
     * responsible for shipping it to the dashboard via the signed
     * `scan_completed` event.
     *
     * @return array<string, mixed>
     */
    public function scan(): array
    {
        $start = microtime(true);

        // Hard ceiling so a runaway iterator can't take down the
        // request worker. Most scans complete in <30s on shared
        // hosting; 60s gives ~2× headroom.
        @set_time_limit(60);

        $findings = [];
        $stats = [
            'files_scanned' => 0,
            'directories' => 0,
        ];

        $this->scanUploadsForPhp($findings, $stats);
        $this->scanForMalwarePatterns($findings, $stats);
        $this->checkWpConfigPermissions($findings);

        $totalFound = count($findings);
        $truncated = $totalFound > self::MAX_FINDINGS;
        $findings = array_slice($findings, 0, self::MAX_FINDINGS);

        return [
            'scanned_at' => time(),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'findings_count' => $totalFound,
            'findings' => $findings,
            'stats' => $stats,
            'truncated' => $truncated,
        ];
    }

    /**
     * Walk wp-content/uploads/ recursively flagging any PHP-ish files.
     *
     * @param array<int, array<string, mixed>> $findings
     * @param array<string, int> $stats
     */
    private function scanUploadsForPhp(array &$findings, array &$stats): void
    {
        if (! defined('WP_CONTENT_DIR')) {
            return;
        }
        $uploadsDir = WP_CONTENT_DIR . '/uploads';
        if (! is_dir($uploadsDir)) {
            return;
        }

        $iterator = $this->safeRecursiveIterator($uploadsDir);
        if ($iterator === null) {
            return;
        }

        foreach ($iterator as $file) {
            if (count($findings) >= self::MAX_FINDINGS) {
                return;
            }
            if (! $file->isFile()) {
                continue;
            }

            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['php', 'phtml', 'phps'], true)
                && ! $this->isInertPhpStub($file->getPathname())) {
                $findings[] = [
                    'type' => 'php_in_uploads',
                    'severity' => 'critical',
                    'path' => $this->relativePath($file->getPathname()),
                    'description' => 'PHP file inside the uploads directory — uploads should hold media only. This is a common artifact of webshell uploads.',
                ];
            }
            $stats['files_scanned']++;
        }
    }

    /**
     * True when a PHP file contains nothing that can execute.
     *
     * The canonical case is the "Silence is golden." index.php that
     * WordPress core, most plugins (DeckWP included) and many hosts
     * drop into every writable directory to suppress directory
     * listings. Flagging those as webshells is how a scan of a clean
     * install produces a critical finding on its first run.
     *
     * Decided by parsing, not by matching filenames or contents against
     * a list: tokenise the file and require that every token be an open
     * tag, a close tag, a comment, whitespace, or inline HTML that is
     * itself only whitespace. One statement — one echo, one variable,
     * one function call — and the file is executable and gets reported.
     *
     * Size-bounded first: a real stub is a couple of lines, and a
     * webshell padded with a megabyte of comments to look inert is not
     * a case we need to hand a free pass to.
     *
     * Fails closed. An unreadable file, or one the tokeniser rejects,
     * is reported rather than excused — a PHP file in uploads that
     * cannot be parsed is more suspicious than one that can.
     */
    private function isInertPhpStub(string $path): bool
    {
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_STUB_BYTES) {
            return false;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        try {
            $tokens = @token_get_all($contents);
        } catch (\Throwable $e) {
            return false;
        }

        foreach ($tokens as $token) {
            // A bare string token is punctuation or an operator — ';',
            // '(', '='. Any of those means there is code here.
            if (! is_array($token)) {
                return false;
            }

            // T_OPEN_TAG_WITH_ECHO ("<?=") is deliberately absent: it
            // is an echo statement in disguise, so a file containing
            // one is not inert.
            $id = $token[0];
            if ($id === T_OPEN_TAG || $id === T_CLOSE_TAG
                || $id === T_WHITESPACE
                || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            // Text after a close tag is served verbatim. Whitespace is
            // the trailing newline every editor adds; anything else is
            // markup being served out of the uploads directory, which
            // is its own problem.
            if ($id === T_INLINE_HTML && trim((string) $token[1]) === '') {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Walk plugins/ and themes/ looking for obfuscation signatures
     * that almost always indicate injected backdoors.
     *
     * @param array<int, array<string, mixed>> $findings
     * @param array<string, int> $stats
     */
    private function scanForMalwarePatterns(array &$findings, array &$stats): void
    {
        if (! defined('WP_CONTENT_DIR')) {
            return;
        }

        foreach (['plugins', 'themes'] as $subDir) {
            $base = WP_CONTENT_DIR . '/' . $subDir;
            if (! is_dir($base)) {
                continue;
            }
            $this->scanDirForPatterns($base, $findings, $stats);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     * @param array<string, int> $stats
     */
    private function scanDirForPatterns(string $base, array &$findings, array &$stats): void
    {
        $iterator = $this->safeRecursiveIterator($base);
        if ($iterator === null) {
            return;
        }

        foreach ($iterator as $file) {
            if (count($findings) >= self::MAX_FINDINGS) {
                return;
            }
            if (! $file->isFile()) {
                continue;
            }

            // Skip files inside ignored subdirectories. Path-based
            // check because RecursiveCallbackFilterIterator gets
            // tricky with deeply-nested cases — a flat substring
            // match against the relative path is good enough.
            $relative = str_replace('\\', '/', $this->relativePath($file->getPathname()));
            $skip = false;
            foreach (self::SKIP_DIRS as $skipName) {
                if (strpos($relative, '/' . $skipName . '/') !== false) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $ext = strtolower($file->getExtension());
            if (! in_array($ext, ['php', 'phtml'], true)) {
                continue;
            }

            $size = $file->getSize();
            if ($size === false || $size > self::MAX_CONTENT_SCAN_BYTES) {
                $stats['files_scanned']++;
                continue;
            }

            $contents = @file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            foreach (self::MALWARE_PATTERNS as $type => $pattern) {
                if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $hit = null;
                foreach ($matches[0] as $match) {
                    if (! $this->matchIsInsideComment($contents, (int) $match[1])) {
                        $hit = $match;
                        break;
                    }
                }
                // Every occurrence was documentation. Keep looking with
                // the next signature rather than reporting the file.
                if ($hit === null) {
                    continue;
                }

                $offset = (int) $hit[1];
                $findings[] = [
                    'type' => $type,
                    'severity' => 'critical',
                    'path' => $this->relativePath($file->getPathname()),
                    'line' => substr_count(substr($contents, 0, $offset), "\n") + 1,
                    'description' => 'Suspicious code pattern (' . $type . ') — eval() over a decoded payload is a hallmark of obfuscated backdoors.',
                    'evidence' => substr((string) $hit[0], 0, 120),
                ];
                // One finding per file is enough for triage. Move on.
                break;
            }
            $stats['files_scanned']++;
        }
    }

    /**
     * True when the byte at `$offset` falls inside a PHP comment.
     *
     * A signature that matches inside a line comment, a block comment
     * or a docblock has matched prose, not behaviour — comments do not
     * execute. Without this, every plugin that documents what it
     * defends against gets reported for describing it, which is how
     * this scanner came to flag its own class docblock as a critical
     * backdoor on a clean install.
     *
     * Only runs on files that already produced a match, so the
     * tokenising cost is paid a handful of times per scan rather than
     * once per file.
     *
     * Fails closed: a file the tokeniser rejects keeps its finding.
     * Broken PHP that happens to contain an eval-over-decode is the
     * last thing to give the benefit of the doubt to.
     */
    private function matchIsInsideComment(string $contents, int $offset): bool
    {
        try {
            $tokens = @token_get_all($contents);
        } catch (\Throwable $e) {
            return false;
        }

        // token_get_all() reports line numbers but not byte offsets,
        // so walk the stream accumulating lengths. The tokens
        // reconstruct the source exactly, which is what makes the
        // running total reliable.
        $position = 0;
        foreach ($tokens as $token) {
            $text = is_array($token) ? (string) $token[1] : (string) $token;
            $length = strlen($text);

            if ($offset < $position + $length) {
                return is_array($token)
                    && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT);
            }

            $position += $length;
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     */
    private function checkWpConfigPermissions(array &$findings): void
    {
        // Windows has no POSIX mode bits. PHP synthesises them from the
        // read-only attribute, so fileperms() answers 0666 for every
        // writable file and 0444 for every read-only one — the
        // world-writable bit is set on literally every wp-config.php on
        // a Windows host, and the finding says nothing about that
        // install's security. A check that cannot fail is not a check;
        // reporting it is how a clean local WordPress produced a
        // permissions warning nobody could act on.
        if ($this->platformLacksPosixPermissions()) {
            return;
        }

        // wp-config.php usually lives at ABSPATH but some installs
        // (security-hardened, multi-app servers) move it one level
        // up. Check the standard spot first, fall back to the
        // parent directory.
        $candidates = [];
        if (defined('ABSPATH')) {
            $candidates[] = ABSPATH . 'wp-config.php';
            $candidates[] = dirname(ABSPATH) . '/wp-config.php';
        }

        foreach ($candidates as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $perms = @fileperms($path);
            if ($perms === false) {
                return;
            }

            // World-writable bit (0002 in the permissions octet).
            if (($perms & 0002) !== 0) {
                $findings[] = [
                    'type' => 'world_writable_config',
                    'severity' => 'warning',
                    'path' => $this->relativePath($path),
                    'description' => sprintf(
                        'wp-config.php is world-writable (mode 0%o). Tighten to 640 or 600 — on shared hosts this lets other users on the same server read database credentials.',
                        $perms & 0777
                    ),
                ];
            }

            // Stop after the first match — we only have one
            // wp-config in any given install.
            return;
        }
    }

    /**
     * True on platforms where `fileperms()` reports something other
     * than real permissions.
     *
     * `PHP_OS_FAMILY` rather than `DIRECTORY_SEPARATOR`: the separator
     * says how paths are written, which is not the same question, and
     * PHP accepts forward slashes on Windows anyway.
     */
    private function platformLacksPosixPermissions(): bool
    {
        return defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Build a recursive iterator that swallows unreadable directories
     * (permission denied, broken symlinks) instead of fataling out
     * mid-scan. Returns null if the root itself is unreadable.
     *
     * @return \RecursiveIteratorIterator<\RecursiveDirectoryIterator>|null
     */
    private function safeRecursiveIterator(string $base)
    {
        try {
            $directory = new \RecursiveDirectoryIterator(
                $base,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            );

            return new \RecursiveIteratorIterator(
                $directory,
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Convert an absolute path to one relative to ABSPATH so the
     * dashboard can render it without leaking server-internal paths
     * like `/var/www/cust42/htdocs/`.
     */
    private function relativePath(string $absolute): string
    {
        if (! defined('ABSPATH')) {
            return $absolute;
        }
        $abspath = rtrim(ABSPATH, '/\\');
        if (strncmp($absolute, $abspath, strlen($abspath)) === 0) {
            return ltrim(substr($absolute, strlen($abspath)), '/\\');
        }

        return $absolute;
    }
}
