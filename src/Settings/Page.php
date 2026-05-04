<?php

namespace DeckWP\Connect\Settings;

defined('ABSPATH') || exit;

use DeckWP\Connect\Heartbeat\Scheduler as HeartbeatScheduler;
use DeckWP\Connect\Pairing\Handler as PairingHandler;
use DeckWP\Connect\Storage\Settings as SettingsStore;

/**
 * Admin page that drives the pairing handshake.
 *
 * Lives under Settings → DeckWP Connect (matches the slug the main
 * plugin file's row-action link points to). Two states:
 *
 *   - Unpaired → renders a token + platform-URL form. Submitting it
 *     calls {@see PairingHandler::pair()}, which on success fills the
 *     option store and bounces back here in the paired state.
 *   - Paired   → renders status (site UUID, team slug, last connected,
 *     intervals) + a Disconnect button that clears connection keys.
 *
 * ## Form processing pattern
 *
 * Both forms POST back to the same admin URL with a hidden
 * `deckwp_connect_action` discriminator (`pair` | `disconnect`). The
 * dispatcher runs on `admin_init`, applies a nonce + capability check,
 * stashes the result via {@see add_settings_error()}, then redirects
 * to keep refresh-resends from re-firing the action (Post-Redirect-Get).
 *
 * ## Why not the Settings API
 *
 * The Settings API is great for declarative key/value config but
 * awkward for an action that calls an external service and persists
 * the response. The form here looks like a settings form but its
 * effect is a side-channel network call — a plain admin-post handler
 * matches the semantics better and stays simple.
 */
class Page
{
    /** Slug used in `?page=` URL + as the dispatcher discriminator prefix. */
    public const SLUG = 'deckwp-connect';

    /** Capability required to view + submit the page. */
    private const CAPABILITY = 'manage_options';

    /** Nonce action for the pair form. */
    private const NONCE_PAIR = 'deckwp_connect_pair';

    /** Nonce action for the disconnect form. */
    private const NONCE_DISCONNECT = 'deckwp_connect_disconnect';

    /** Nonce action for the manual "send heartbeat now" trigger. */
    private const NONCE_HEARTBEAT = 'deckwp_connect_heartbeat_now';

    /** Querystring flag we set in the redirect after a handled submission. */
    private const FLAG_DONE = 'deckwp_connect_done';

    /**
     * Transient prefix for the per-user admin-notice flash. We use our
     * own key (instead of core's `'settings_errors'`) so other plugins
     * hooked into `admin_notices` can't accidentally consume it by
     * calling `settings_errors()` without a slug argument before our
     * `render()` runs — `get_settings_errors()` deletes the transient
     * after merging, so a single such call would silently wipe the
     * banner.
     */
    private const NOTICE_TRANSIENT_PREFIX = 'deckwp_connect_admin_notice_';

    /** @var SettingsStore */
    private $settings;

    /** @var PairingHandler */
    private $pairing;

    /** @var HeartbeatScheduler */
    private $heartbeat;

    public function __construct(
        SettingsStore $settings = null,
        PairingHandler $pairing = null,
        HeartbeatScheduler $heartbeat = null
    ) {
        $this->settings  = $settings ?? new SettingsStore();
        $this->pairing   = $pairing ?? new PairingHandler();
        $this->heartbeat = $heartbeat ?? new HeartbeatScheduler();
    }

    /**
     * Wire up the WordPress hooks. Called once from {@see Bootstrap}.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'dispatchSubmission']);
    }

    /**
     * Register the page under Settings → DeckWP Connect.
     */
    public function addMenuPage(): void
    {
        add_options_page(
            __('DeckWP Connect', 'deckwp-connect'),
            __('DeckWP Connect', 'deckwp-connect'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );
    }

    /**
     * Detect a form submission and route it to the right handler. Runs
     * on every `admin_init`; bails fast when no submission is in play.
     */
    public function dispatchSubmission(): void
    {
        if (! isset($_POST['deckwp_connect_action'])) {
            return;
        }
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage DeckWP Connect.', 'deckwp-connect'), 403);
        }

        $action = sanitize_key((string) wp_unslash($_POST['deckwp_connect_action']));
        switch ($action) {
            case 'pair':
                $this->handlePairSubmit();
                break;
            case 'disconnect':
                $this->handleDisconnectSubmit();
                break;
            case 'heartbeat':
                $this->handleHeartbeatSubmit();
                break;
            default:
                // Unknown action — ignore silently rather than expose
                // the dispatcher's switch surface.
                return;
        }

        // Bridge our `add_settings_error()` notices across the PRG
        // redirect. `add_settings_error` only populates the
        // `$wp_settings_errors` global, which is request-scoped — without
        // this transient hand-off the notice would be wiped by the 302
        // and the operator would see a silent reload. We keep notices
        // under a per-user, plugin-prefixed key (NOT core's shared
        // `'settings_errors'` transient): any plugin hooked into
        // `admin_notices` that calls bare `settings_errors()` would
        // consume the shared key and silently swallow our banner before
        // `render()` runs. `render()` reads this back and re-injects via
        // `add_settings_error` so `settings_errors(self::SLUG)` renders
        // it normally.
        $errors = get_settings_errors(self::SLUG);
        if (! empty($errors)) {
            $key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
            set_transient($key, $errors, 30);

            if (function_exists('error_log')) {
                error_log(sprintf(
                    '[deckwp-connect] flash stored (key=%s, count=%d)',
                    $key,
                    count($errors)
                ));
            }
        }

        // PRG redirect — strip the POST body, append the done flag so
        // the page can show the right notice.
        $redirect = add_query_arg(
            [
                'page'          => self::SLUG,
                self::FLAG_DONE => $action,
            ],
            admin_url('options-general.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Run the pairing handshake and stash the result for {@see render()}
     * to surface via {@see settings_errors()}.
     */
    private function handlePairSubmit(): void
    {
        check_admin_referer(self::NONCE_PAIR);

        $token = isset($_POST['pairing_token'])
            ? (string) wp_unslash($_POST['pairing_token'])
            : '';
        $platformUrl = isset($_POST['platform_url'])
            ? (string) wp_unslash($_POST['platform_url'])
            : '';

        $result = $this->pairing->pair($token, $platformUrl);

        add_settings_error(
            self::SLUG,
            $result['ok'] ? 'paired' : 'pair_failed',
            $result['message'],
            $result['ok'] ? 'success' : 'error'
        );
        // Note: this notice ONLY survives the PRG redirect because
        // `dispatchSubmission()` flushes the request-scoped
        // `$wp_settings_errors` global into the `'settings_errors'`
        // transient before redirecting. Without that bridge, every
        // submission would 302 to a silent reload.
    }

    /**
     * Fire a heartbeat synchronously and report the outcome. Bypasses
     * the WP-Cron schedule + the `DECKWP_CONNECT_ENABLE_HEARTBEAT` flag —
     * useful for verifying signing + payload during dev without waiting
     * for the cron to tick.
     */
    private function handleHeartbeatSubmit(): void
    {
        check_admin_referer(self::NONCE_HEARTBEAT);

        $result = $this->heartbeat->sendNow();

        if ($result['ok']) {
            add_settings_error(
                self::SLUG,
                'heartbeat_ok',
                sprintf(
                    /* translators: %d: HTTP status code from the dashboard. */
                    __('Heartbeat delivered (HTTP %d). Dashboard accepted the payload.', 'deckwp-connect'),
                    (int) $result['status']
                ),
                'success'
            );

            return;
        }

        add_settings_error(
            self::SLUG,
            'heartbeat_failed',
            sprintf(
                /* translators: 1: HTTP status code, 2: error message. */
                __('Heartbeat failed (HTTP %1$d): %2$s', 'deckwp-connect'),
                (int) $result['status'],
                (string) ($result['error'] ?? 'unknown')
            ),
            'error'
        );
    }

    /**
     * Wipe the connection keys. Idempotent: clicking Disconnect twice
     * is harmless.
     *
     * Also fires a best-effort `disconnect` event at the dashboard so
     * the site row can flip from `paired` to `revoked` instead of
     * sitting at "Paired" with stale `last_seen_at`. The notification
     * MUST happen before `clearConnection()` — once the secret is
     * gone we can't sign the request anymore. If the network call
     * fails we still proceed with the local clear (the user wants out;
     * a stale dashboard row is recoverable, an unclickable Disconnect
     * button is not).
     */
    private function handleDisconnectSubmit(): void
    {
        check_admin_referer(self::NONCE_DISCONNECT);

        $remote = $this->pairing->disconnect();
        $this->settings->clearConnection();

        if ($remote['ok']) {
            add_settings_error(
                self::SLUG,
                'disconnected',
                __('Disconnected from DeckWP. The dashboard has been notified.', 'deckwp-connect'),
                'success'
            );

            return;
        }

        add_settings_error(
            self::SLUG,
            'disconnected_local_only',
            sprintf(
                /* translators: %s: error message from the dashboard call. */
                __('Disconnected locally, but the dashboard could not be notified (%s). It may still show this site as paired until the next stale check.', 'deckwp-connect'),
                (string) $remote['message']
            ),
            'warning'
        );
    }

    /**
     * Page renderer — chooses the paired vs. unpaired view based on
     * what's in the option store right now.
     */
    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to view this page.', 'deckwp-connect'), 403);
        }

        $this->flushTransientNotices();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('DeckWP Connect', 'deckwp-connect') . '</h1>';

        settings_errors(self::SLUG);

        if ($this->settings->isPaired()) {
            $this->renderStatus();
        } else {
            $this->renderConnectForm();
        }

        echo '</div>';
    }

    /**
     * Pull any stashed flash notices out of the per-user transient and
     * re-inject them into the request-scoped `$wp_settings_errors`
     * global so the upcoming `settings_errors(self::SLUG)` call renders
     * them like any inline notice. See {@see dispatchSubmission()} for
     * why we don't use core's shared `'settings_errors'` transient.
     */
    private function flushTransientNotices(): void
    {
        $key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
        $errors = get_transient($key);
        if (! is_array($errors) || empty($errors)) {
            return;
        }

        delete_transient($key);

        foreach ($errors as $err) {
            if (! is_array($err)) {
                continue;
            }
            add_settings_error(
                (string) ($err['setting'] ?? self::SLUG),
                (string) ($err['code']    ?? 'notice'),
                (string) ($err['message'] ?? ''),
                (string) ($err['type']    ?? 'info')
            );
        }

        if (function_exists('error_log')) {
            error_log(sprintf(
                '[deckwp-connect] flash flushed (key=%s, count=%d)',
                $key,
                count($errors)
            ));
        }
    }

    /**
     * Form for the unpaired state.
     */
    private function renderConnectForm(): void
    {
        $platformUrl = (string) $this->settings->get('platform_url', '');
        if ($platformUrl === '') {
            $platformUrl = 'https://deckwp.com';
        }

        echo '<p>';
        echo esc_html__(
            'Pair this WordPress site with your DeckWP dashboard to enable bulk updates, scans, backups, and remote management. Generate a pairing token in the dashboard at /sites/create, then paste it below.',
            'deckwp-connect'
        );
        echo '</p>';

        echo '<form method="post" action="">';
        wp_nonce_field(self::NONCE_PAIR);
        echo '<input type="hidden" name="deckwp_connect_action" value="pair" />';

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr>';
        echo '<th scope="row"><label for="deckwp_connect_token">' . esc_html__('Pairing token', 'deckwp-connect') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="deckwp_connect_token" name="pairing_token" class="regular-text code" autocomplete="off" spellcheck="false" required />';
        echo '<p class="description">' . esc_html__('48 hex characters issued by the dashboard. Single-use — expires 15 minutes after issue.', 'deckwp-connect') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="deckwp_connect_platform">' . esc_html__('Dashboard URL', 'deckwp-connect') . '</label></th>';
        echo '<td>';
        echo '<input type="url" id="deckwp_connect_platform" name="platform_url" class="regular-text code" value="' . esc_attr($platformUrl) . '" />';
        echo '<p class="description">' . esc_html__('Leave the default unless you are using a staging or self-hosted DeckWP install.', 'deckwp-connect') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</tbody></table>';

        submit_button(__('Connect', 'deckwp-connect'));
        echo '</form>';
    }

    /**
     * Status block + Disconnect form for the paired state.
     */
    private function renderStatus(): void
    {
        $all = $this->settings->all();
        $siteId       = (string) ($all['site_id'] ?? '');
        $teamSlug     = (string) ($all['team_slug'] ?? '');
        $platformUrl  = (string) ($all['platform_url'] ?? '');
        $callbackUrl  = (string) ($all['callback_url'] ?? '');
        $connectedAt  = (int) ($all['connected_at'] ?? 0);
        $heartbeatSec = (int) ($all['heartbeat_seconds'] ?? 0);
        $scanSec      = (int) ($all['scan_seconds'] ?? 0);

        $siteLink = $platformUrl !== '' && $siteId !== ''
            ? rtrim($platformUrl, '/') . '/sites/' . rawurlencode($siteId)
            : '';

        echo '<p>' . esc_html__('This site is paired with DeckWP.', 'deckwp-connect') . '</p>';

        echo '<table class="widefat striped" style="max-width:780px;"><tbody>';
        $this->statusRow(__('Site UUID', 'deckwp-connect'), $siteId, $siteLink);
        $this->statusRow(__('Team', 'deckwp-connect'), $teamSlug);
        $this->statusRow(__('Dashboard', 'deckwp-connect'), $platformUrl, $platformUrl);
        $this->statusRow(__('Callback URL', 'deckwp-connect'), $callbackUrl);
        $this->statusRow(
            __('Connected', 'deckwp-connect'),
            $connectedAt > 0
                ? sprintf(
                    /* translators: %s: human-readable "X minutes ago" timestamp. */
                    __('%s ago', 'deckwp-connect'),
                    human_time_diff($connectedAt, time())
                )
                : '—'
        );
        $this->statusRow(
            __('Heartbeat interval', 'deckwp-connect'),
            $heartbeatSec > 0 ? sprintf('%d seconds', $heartbeatSec) : '—'
        );
        $this->statusRow(
            __('Scan interval', 'deckwp-connect'),
            $scanSec > 0 ? sprintf('%d seconds', $scanSec) : '—'
        );
        echo '</tbody></table>';

        echo '<div style="margin-top:1.5em; display:flex; gap:0.5em; align-items:center;">';

        // Send heartbeat now — synchronous probe for dev validation.
        echo '<form method="post" action="" style="margin:0;">';
        wp_nonce_field(self::NONCE_HEARTBEAT);
        echo '<input type="hidden" name="deckwp_connect_action" value="heartbeat" />';
        submit_button(__('Send heartbeat now', 'deckwp-connect'), 'secondary', 'submit', false);
        echo '</form>';

        // Disconnect.
        echo '<form method="post" action="" style="margin:0;">';
        wp_nonce_field(self::NONCE_DISCONNECT);
        echo '<input type="hidden" name="deckwp_connect_action" value="disconnect" />';
        submit_button(
            __('Disconnect', 'deckwp-connect'),
            'delete',
            'submit',
            false,
            ['onclick' => "return confirm('" . esc_js(__('Disconnect this site from DeckWP? The dashboard will lose remote control until you re-pair.', 'deckwp-connect')) . "');"]
        );
        echo '</form>';

        echo '</div>';
    }

    /**
     * Render one row of the status table — value-only or value-as-link.
     */
    private function statusRow(string $label, string $value, string $linkHref = ''): void
    {
        echo '<tr>';
        echo '<th scope="row" style="width:30%;">' . esc_html($label) . '</th>';
        echo '<td><code>';
        if ($linkHref !== '' && $value !== '') {
            echo '<a href="' . esc_url($linkHref) . '" target="_blank" rel="noopener">' . esc_html($value) . '</a>';
        } else {
            echo esc_html($value !== '' ? $value : '—');
        }
        echo '</code></td>';
        echo '</tr>';
    }
}
