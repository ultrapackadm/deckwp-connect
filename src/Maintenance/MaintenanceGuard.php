<?php

namespace DeckWP\Connect\Maintenance;

defined('ABSPATH') || exit;

/**
 * The runtime half of the maintenance toggle: hooks `init` early
 * and, when {@see MaintenanceManager::state()} reports an active
 * lock, intercepts non-bypass requests with a 503 + branded
 * maintenance page.
 *
 * ## What does NOT get intercepted
 *
 *   - REST API routes under `/wp-json/*` (or `?rest_route=`).
 *     The dashboard MUST be able to keep talking to the connector
 *     during maintenance — heartbeat, install-batch, restore,
 *     /maintenance toggle itself. Blocking these would brick
 *     the dashboard's remote control of the site exactly when
 *     the operator most needs it.
 *
 *   - WP admin pages (`is_admin()`). Logged-in operators with
 *     `manage_options` need to keep working on the site while
 *     the public face is down — that's typically the whole
 *     point of enabling maintenance.
 *
 *   - WP CLI requests. Cron + ad-hoc CLI shouldn't break.
 *
 *   - `/wp-login.php`. Operators need to be able to log in
 *     during maintenance to use the bypass above.
 *
 * Everything else (frontend page views, REST proxy from
 * non-deckwp paths, etc.) gets the branded page.
 *
 * ## Branded page
 *
 * Inline HTML — no template lookup, no plugin-side template
 * directory needed. Renders the operator's `message` from the
 * lock + a generic "We'll be back" tagline + a Retry-After
 * hint pulled from `ends_at` so well-behaved bots back off.
 *
 * ## Wiring
 *
 *   add_action('init', [new MaintenanceGuard($manager), 'maybeIntercept'], 1);
 *
 * Priority 1 so we run before plugins/themes start their work
 * (we want to short-circuit the request before any expensive
 * frontend rendering kicks in).
 */
class MaintenanceGuard
{
    /** @var MaintenanceManager */
    private $manager;

    public function __construct(MaintenanceManager $manager = null)
    {
        $this->manager = $manager ?? new MaintenanceManager();
    }

    /**
     * Decide whether to intercept the current request and, if
     * so, render the branded page + exit. No-op otherwise.
     */
    public function maybeIntercept(): void
    {
        if ($this->isBypassRequest()) {
            return;
        }

        $state = $this->manager->state();
        if (empty($state['active'])) {
            return;
        }

        $this->render($state);
        // render() exits — never returns.
    }

    /**
     * True when the current request belongs to a route the guard
     * must NOT intercept. Order matters slightly: REST check
     * first because that's the highest-traffic exemption (the
     * dashboard polls the connector several times per minute).
     */
    private function isBypassRequest(): bool
    {
        // REST API — both pretty-permalink (/wp-json/) and
        // plugin-mode (?rest_route=) shapes.
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($uri !== '') {
            if (strpos($uri, '/wp-json/') !== false) {
                return true;
            }
            if (strpos($uri, 'rest_route=') !== false) {
                return true;
            }
            // wp-login.php so operators can authenticate during
            // a long maintenance window.
            if (strpos($uri, '/wp-login.php') !== false) {
                return true;
            }
        }

        // Admin pages — `is_admin()` is the canonical check, but
        // it isn't true at init priority 1 in every context, so
        // we double up with a URI check.
        if (function_exists('is_admin') && is_admin()) {
            return true;
        }
        if ($uri !== '' && strpos($uri, '/wp-admin/') !== false) {
            return true;
        }

        // CLI + cron — these never hit a frontend visitor flow.
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }

        return false;
    }

    /**
     * Render the 503 maintenance page with the branded HTML
     * and exit immediately so WP doesn't continue with theme
     * rendering.
     *
     * @param  array<string, mixed>  $state
     */
    private function render(array $state): void
    {
        $message = isset($state['message']) ? (string) $state['message'] : MaintenanceManager::DEFAULT_MESSAGE;
        $endsAt  = isset($state['ends_at']) ? (int) $state['ends_at'] : 0;

        $secondsLeft = $endsAt > 0 ? max(0, $endsAt - time()) : 0;

        if (! headers_sent()) {
            status_header(503);
            nocache_headers();
            header('Content-Type: text/html; charset=utf-8');
            // Retry-After in seconds (RFC 7231) — well-behaved
            // crawlers will back off until at least this point.
            // Cap at 3600s to play nice with overly-eager bots
            // that won't honor multi-hour values.
            if ($secondsLeft > 0) {
                header('Retry-After: ' . min($secondsLeft, 3600));
            }
        }

        $minutesLeft = (int) ceil($secondsLeft / 60);
        $eta = $minutesLeft > 0
            ? sprintf(_n('%d minute', '%d minutes', $minutesLeft, 'deckwp-connect'), $minutesLeft)
            : '';

        echo $this->buildHtml($message, $eta);
        exit;
    }

    private function buildHtml(string $message, string $eta): string
    {
        // Inline CSS only — no plugin asset URL resolution and
        // no theme dependency. The whole page must render even
        // if the rest of WP is half-broken.
        $body = sprintf(
            '<h1>%s</h1><p>%s</p>%s',
            esc_html__('Maintenance in progress', 'deckwp-connect'),
            esc_html($message),
            $eta !== ''
                ? sprintf(
                    '<p class="eta">%s</p>',
                    sprintf(
                        esc_html__('Estimated time remaining: %s.', 'deckwp-connect'),
                        esc_html($eta)
                    )
                )
                : ''
        );

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Maintenance</title>
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        body {
            background: #0f172a;
            color: #e2e8f0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            max-width: 540px;
            text-align: center;
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(71, 85, 105, 0.4);
            border-radius: 16px;
            padding: 48px 32px;
        }
        h1 {
            font-size: 24px;
            font-weight: 600;
            margin: 0 0 16px;
            color: #f1f5f9;
        }
        p {
            font-size: 16px;
            line-height: 1.5;
            margin: 0 0 12px;
            color: #cbd5e1;
        }
        .eta {
            margin-top: 24px;
            font-size: 14px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="card">{$body}</div>
</body>
</html>
HTML;
    }
}
