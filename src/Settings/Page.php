<?php

namespace DeckWP\Connect\Settings;

defined('ABSPATH') || exit;

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

    /** Querystring flag we set in the redirect after a handled submission. */
    private const FLAG_DONE = 'deckwp_connect_done';

    /** @var SettingsStore */
    private $settings;

    /** @var PairingHandler */
    private $pairing;

    public function __construct(SettingsStore $settings = null, PairingHandler $pairing = null)
    {
        $this->settings = $settings ?? new SettingsStore();
        $this->pairing  = $pairing ?? new PairingHandler();
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
            default:
                // Unknown action — ignore silently rather than expose
                // the dispatcher's switch surface.
                return;
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
        // settings_errors stores via set_transient() under a per-user key —
        // it survives the redirect into render() automatically.
    }

    /**
     * Wipe the connection keys. Idempotent: clicking Disconnect twice
     * is harmless.
     */
    private function handleDisconnectSubmit(): void
    {
        check_admin_referer(self::NONCE_DISCONNECT);

        $this->settings->clearConnection();

        add_settings_error(
            self::SLUG,
            'disconnected',
            __('Disconnected from DeckWP. This site no longer accepts dashboard commands.', 'deckwp-connect'),
            'success'
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

        echo '<form method="post" action="" style="margin-top:1.5em;">';
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
