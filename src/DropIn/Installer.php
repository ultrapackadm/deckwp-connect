<?php

namespace DeckWP\Connect\DropIn;

defined('ABSPATH') || exit;

/**
 * Installs, upgrades, and removes the DeckWP fatal-error-handler
 * drop-in at `wp-content/fatal-error-handler.php`.
 *
 * ## The single-slot problem
 *
 * WordPress only honors ONE drop-in at that path. Other plugins or
 * hosting providers may have written their own — DeckWP MUST NOT
 * overwrite a foreign drop-in without consent (it'd silently break
 * whatever fatal-handling the operator already has, which is the
 * exact opposite of what this feature is selling).
 *
 * The Installer detects ours via a marker string in the file
 * contents:
 *
 *   define('DECKWP_FATAL_HANDLER_MARKER', '...');
 *
 * Classification:
 *
 *   - **absent**  — no file at the path; safe to write.
 *   - **ours**    — file contains DECKWP_FATAL_HANDLER_MARKER; safe
 *                   to overwrite.
 *   - **foreign** — file exists but no marker; **do not touch**.
 *                   `install()` returns `error_code: foreign_dropin`
 *                   and the caller decides whether to surface a
 *                   warning to the operator.
 *
 * ## Idempotence
 *
 * `install()` is safe to call on every `plugins_loaded`. When the
 * target already matches the source byte-for-byte it returns
 * `action: noop` without touching disk.
 *
 * ## Why grep, not require
 *
 * Classification reads the file as text and looks for the marker
 * string. We deliberately do NOT `require` the foreign file to
 * inspect its constants — a malicious or malformed drop-in could
 * crash the connector during boot, and that's exactly the failure
 * mode this feature exists to fix.
 *
 * @package DeckWP\Connect\DropIn
 */
class Installer
{
    /** Filename WordPress core looks for in wp-content/. */
    public const DROPIN_FILENAME = 'fatal-error-handler.php';

    /** Literal token greppable in our drop-in source. */
    public const MARKER = 'DECKWP_FATAL_HANDLER_MARKER';

    /** @var string|null Override for tests; production uses WP_CONTENT_DIR. */
    private $customDropInPath;

    /** @var string|null Override for tests; production uses bundled handler-source.php. */
    private $customSourcePath;

    /**
     * Optional path overrides to allow unit testing against a temp
     * directory without booting WordPress. Production code calls
     * `new Installer()` with no arguments.
     */
    public function __construct(?string $dropInPath = null, ?string $sourcePath = null)
    {
        $this->customDropInPath = $dropInPath;
        $this->customSourcePath = $sourcePath;
    }

    /**
     * Idempotent install. Resolves the target path, classifies any
     * existing file, and writes ours iff absent or ours-existing.
     * Foreign drop-ins are NEVER overwritten.
     *
     * @return array{ok: bool, action?: string, error_code?: string, error?: string}
     */
    public function install(): array
    {
        $target = $this->dropInPath();
        if ($target === null) {
            return $this->fail('wp_content_unresolvable', 'Could not resolve wp-content path (WP_CONTENT_DIR not defined).');
        }

        $existing = $this->classifyExistingAt($target);
        if ($existing === 'foreign') {
            return $this->fail('foreign_dropin', 'A non-DeckWP fatal-error-handler.php is already in place; refusing to overwrite.');
        }

        $source = $this->sourcePath();
        if (! is_readable($source)) {
            return $this->fail('source_unreadable', 'Drop-in source missing from plugin (' . $source . ').');
        }

        $sourceContents = @file_get_contents($source);
        if ($sourceContents === false) {
            return $this->fail('source_read_failed', 'Could not read drop-in source.');
        }

        // Skip the write when our own drop-in is already present and
        // matches byte-for-byte. Cheap fast-path on every plugins_loaded.
        if ($existing === 'ours') {
            $current = @file_get_contents($target);
            if ($current !== false && $current === $sourceContents) {
                return ['ok' => true, 'action' => 'noop'];
            }
        }

        $bytes = @file_put_contents($target, $sourceContents);
        if ($bytes === false) {
            return $this->fail('write_failed', 'Could not write drop-in. Check wp-content/ permissions.');
        }

        return [
            'ok'     => true,
            'action' => $existing === 'absent' ? 'installed' : 'updated',
        ];
    }

    /**
     * Remove our drop-in. **NEVER touches foreign drop-ins** — those
     * stay even on uninstall, since they belong to someone else.
     *
     * @return array{ok: bool, action?: string, error_code?: string, error?: string}
     */
    public function uninstall(): array
    {
        $target = $this->dropInPath();
        if ($target === null) {
            return $this->fail('wp_content_unresolvable', 'Could not resolve wp-content path.');
        }

        $existing = $this->classifyExistingAt($target);
        if ($existing === 'absent') {
            return ['ok' => true, 'action' => 'already_gone'];
        }
        if ($existing === 'foreign') {
            return ['ok' => true, 'action' => 'foreign_skipped'];
        }

        if (! @unlink($target)) {
            return $this->fail('unlink_failed', 'Could not remove drop-in.');
        }

        return ['ok' => true, 'action' => 'removed'];
    }

    /**
     * Classify the current state at the target path. Public so the
     * Settings page (Slice 4+) can render a status badge without
     * triggering an install attempt.
     *
     * @return 'absent'|'ours'|'foreign'
     */
    public function classifyExisting(): string
    {
        $target = $this->dropInPath();
        if ($target === null) {
            return 'absent';
        }
        return $this->classifyExistingAt($target);
    }

    /**
     * Absolute path to wp-content/fatal-error-handler.php, or null
     * if WP_CONTENT_DIR isn't defined yet (extremely early boot,
     * or a test that hasn't bootstrapped WP).
     */
    public function dropInPath(): ?string
    {
        if ($this->customDropInPath !== null) {
            return $this->customDropInPath;
        }
        if (! defined('WP_CONTENT_DIR')) {
            return null;
        }
        return rtrim(WP_CONTENT_DIR, '/\\') . '/' . self::DROPIN_FILENAME;
    }

    /**
     * Absolute path to the canonical drop-in source bundled with the
     * plugin (the file copied into wp-content/).
     */
    public function sourcePath(): string
    {
        return $this->customSourcePath ?? __DIR__ . '/handler-source.php';
    }

    /**
     * @return 'absent'|'ours'|'foreign'
     */
    private function classifyExistingAt(string $target): string
    {
        if (! file_exists($target)) {
            return 'absent';
        }
        $contents = @file_get_contents($target);
        if ($contents === false) {
            // Unreadable — treat as foreign so we never overwrite
            // something we couldn't even inspect first.
            return 'foreign';
        }
        return strpos($contents, self::MARKER) !== false ? 'ours' : 'foreign';
    }

    /**
     * @return array{ok: false, error_code: string, error: string}
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
