<?php

namespace DeckWP\Connect\Transport;

defined('ABSPATH') || exit;

use DeckWP\Connect\REST\Auth\HmacVerifier;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Init-hook fallback transport — lets the DeckWP dashboard reach this
 * site when `/wp-json` is blocked by the host or a security plugin.
 *
 * Some managed hosts and "harden WordPress" plugins disable or firewall
 * the REST API entirely. When that happens every `deckwp/v1/*` route
 * becomes unreachable and the paired site goes dark even though the
 * connector is installed and healthy. This subsystem provides a second
 * door: a request that arrives on a normal front-end URL, is caught on
 * the `init` hook, authenticated with the SAME HMAC scheme as the REST
 * routes, and dispatched to the SAME route handlers.
 *
 * It is purely INBOUND (dashboard → site). It never initiates a network
 * request and does no work on ordinary visitor page loads — the very
 * first thing {@see self::maybeHandle()} does is an O(1) check for the
 * transport flag and return if it is absent. This preserves the
 * connector's "no phone-home on page loads" privacy guarantee.
 *
 * ## Wire contract (for the dashboard-side sender)
 *
 *     POST {site_url}/?deckwp_connect_transport=1
 *     X-DeckWP-Timestamp: <unix epoch>
 *     X-DeckWP-Nonce:     <16-32 bytes hex>
 *     X-DeckWP-Signature: <hex hmac-sha256>
 *     Content-Type: application/json
 *
 *     { "route": "<deckwp/v1 route slug>", "payload": { ...route params... } }
 *
 * The HMAC canonical is identical to the REST scheme
 * ({@see HmacVerifier}): `{timestamp}.{nonce}.POST.{path}.{sha256(body)}`
 * where `{path}` is the request path WITHOUT the query string (e.g. `/`
 * for a root install, `/blog/` for a subdirectory install) and `{body}`
 * is the raw JSON envelope above.
 *
 * Critically, the target route lives INSIDE the signed body, never in the
 * (unsigned) query string. The `deckwp_connect_transport` query flag only
 * decides *whether* we intercept; it carries no authority. Because the
 * route + params are inside the signed body, an intercepted transport
 * request cannot be re-pointed at a different, more destructive endpoint —
 * the same "method/path lock" property the REST surface relies on.
 *
 * Response: `Content-Type: application/json`, the route handler's body and
 * HTTP status, then the request terminates (the fallback owns the request).
 *
 * ## How it reuses the REST stack (single source of truth)
 *
 * After authenticating, we boot the REST server with
 * {@see rest_get_server()} (which fires `rest_api_init` and registers
 * every `deckwp/v1` route), look the target route up in the live route
 * registry, and invoke its registered `callback` directly with a
 * {@see WP_REST_Request} carrying the payload as a JSON body. The route's
 * argument schema is applied via {@see WP_REST_Request::has_valid_params()}
 * before the handler runs, exactly as `WP_REST_Server::dispatch()` would.
 *
 * We deliberately do NOT re-run the route's HMAC `permission_callback`:
 * the identical request bytes were already verified by
 * {@see HmacVerifier::verifyFromGlobals()} above. Invoking the registered
 * handler keeps ONE copy of each route's business logic (the route class
 * remains the single source of truth) — nothing is duplicated here.
 *
 * ## Registration
 *
 * Hooked on `init` at priority 0 — BEFORE
 * {@see \DeckWP\Connect\Maintenance\MaintenanceGuard} (priority 1) — so
 * the dashboard can still manage a site whose maintenance mode is on.
 */
class InitHookFallback
{
    /**
     * Query var that flags a request as a fallback-transport request.
     * Only controls whether we intercept; carries no authority (the
     * signed body holds the route + params).
     */
    public const TRANSPORT_FLAG = 'deckwp_connect_transport';

    /**
     * `init` priority. 0 keeps us ahead of the MaintenanceGuard
     * (priority 1) so the transport bypasses maintenance interception.
     */
    private const INIT_PRIORITY = 0;

    /**
     * `deckwp/v1` route slugs reachable through the fallback. Explicit
     * allowlist (defense in depth): only the HMAC-protected POST command
     * routes. Excludes `sso-login` (GET, browser-token auth) and
     * `bootstrap-pairing` (used before a secret exists — and
     * verifyFromGlobals() requires a secret, so it could never authenticate
     * over this transport anyway).
     *
     * @var string[]
     */
    private const ALLOWED_ROUTES = [
        'scan',
        'install-batch',
        'restore-backup',
        'delete-backup',
        'inventory',
        'maintenance',
        'backup-create',
        'set-managed-slugs',
        'whitelabel',
        'plugin-toggle',
        'theme-switch',
        'theme-delete',
        'site-health',
        'db-scan',
        'db-cleanup',
        'db-optimize-tables',
    ];

    /** @var HmacVerifier */
    private $verifier;

    public function __construct(HmacVerifier $verifier = null)
    {
        $this->verifier = $verifier ?? new HmacVerifier();
    }

    /**
     * Wire up the hook. Called once from {@see \DeckWP\Connect\Bootstrap}.
     */
    public function register(): void
    {
        add_action('init', [$this, 'maybeHandle'], self::INIT_PRIORITY);
    }

    /**
     * Intercept + handle a fallback-transport request, or return
     * immediately on any normal request.
     */
    public function maybeHandle(): void
    {
        // Cheap gate FIRST. On a normal visitor page load the flag is
        // absent, so we return in O(1) — no DB read, no network, no work.
        // This is what preserves the "no phone-home on page loads"
        // guarantee: the subsystem is inert unless the dashboard
        // explicitly addresses it.
        if (! isset($_GET[self::TRANSPORT_FLAG])) {
            return;
        }

        // The fallback always carries a command body. A non-POST request
        // to the flagged URL is never valid; ignore it (fall through to a
        // normal page render rather than erroring).
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
        if ($method !== 'POST') {
            return;
        }

        // Authenticate the RAW request (headers from $_SERVER, body from
        // php://input, path from REQUEST_URI). Reuses the exact HMAC +
        // timestamp-window + nonce-shape checks the REST permission
        // callback runs — single source of truth for verification.
        if (! $this->verifier->verifyFromGlobals()) {
            $this->respond(401, ['error' => 'Invalid or expired signature.']);
        }

        // Decode the signed envelope. The route slug lives here (inside the
        // signed body), NOT in the query string, so the signature binds to
        // this specific command.
        $raw     = (string) (file_get_contents('php://input') ?: '');
        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! isset($decoded['route']) || ! is_string($decoded['route'])) {
            $this->respond(400, ['error' => 'Malformed transport envelope.']);
        }

        $route   = $decoded['route'];
        $payload = (isset($decoded['payload']) && is_array($decoded['payload'])) ? $decoded['payload'] : [];

        if (! in_array($route, self::ALLOWED_ROUTES, true)) {
            $this->respond(404, ['error' => 'Unknown or disallowed route.']);
        }

        $this->dispatch($route, $payload);
    }

    /**
     * Dispatch an already-authenticated command to its registered REST
     * route handler and emit the response.
     *
     * @param array<string, mixed> $payload
     */
    private function dispatch(string $route, array $payload): void
    {
        // Boot the REST server: this fires `rest_api_init`, registering
        // every deckwp/v1 route (via REST\Server) so we can reuse the live
        // handler. Only happens on an authenticated fallback request, so
        // the cost is acceptable.
        $server = rest_get_server();
        $path   = '/deckwp/v1/' . $route;

        $routes = $server->get_routes();
        if (! isset($routes[$path]) || ! is_array($routes[$path])) {
            $this->respond(404, ['error' => 'Route not registered.']);
        }

        // Find the endpoint that accepts POST and carries a callback.
        $endpoint = null;
        foreach ($routes[$path] as $candidate) {
            if (! is_array($candidate) || empty($candidate['callback'])) {
                continue;
            }
            $methods = isset($candidate['methods']) ? $candidate['methods'] : [];
            if ($this->endpointAcceptsPost($methods)) {
                $endpoint = $candidate;
                break;
            }
        }

        if ($endpoint === null) {
            $this->respond(405, ['error' => 'Route does not accept POST.']);
        }

        // Build a REST request carrying the payload as a JSON body, so the
        // handler's get_param()/get_json_params() read it exactly as on a
        // real /wp-json call. Attach the endpoint attributes so the route's
        // declared argument schema is available for validation.
        $request = new WP_REST_Request('POST', $path);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($payload));
        $request->set_attributes($endpoint);

        // Mirror WP_REST_Server::dispatch(): validate declared args before
        // the handler runs. (We skip ONLY the permission_callback — already
        // satisfied by verifyFromGlobals() on the identical bytes.)
        $valid = $request->has_valid_params();
        if (is_wp_error($valid)) {
            $this->respond(400, [
                'error'   => 'Invalid parameters.',
                'details' => $valid->get_error_messages(),
            ]);
        }
        $request->sanitize_params();

        $response = call_user_func($endpoint['callback'], $request);

        $this->emitResponse($response);
    }

    /**
     * True if a route endpoint's `methods` entry permits POST. WP may
     * store methods as an assoc map (`['POST' => true]`), an indexed list,
     * or a CSV string depending on how the route was registered.
     *
     * @param mixed $methods
     */
    private function endpointAcceptsPost($methods): bool
    {
        if (is_array($methods)) {
            if (isset($methods['POST'])) {
                return (bool) $methods['POST'];
            }
            foreach ($methods as $key => $value) {
                if (is_string($key) && strtoupper($key) === 'POST') {
                    return true;
                }
                if (is_string($value) && stripos($value, 'POST') !== false) {
                    return true;
                }
            }

            return false;
        }

        return is_string($methods) && stripos($methods, 'POST') !== false;
    }

    /**
     * Normalize a handler return value (WP_REST_Response, WP_Error, or
     * plain data) into an emitted JSON response.
     *
     * @param mixed $response
     */
    private function emitResponse($response): void
    {
        if (is_wp_error($response)) {
            $data   = $response->get_error_data();
            $status = (is_array($data) && isset($data['status'])) ? (int) $data['status'] : 500;
            $this->respond($status, [
                'error' => $response->get_error_message(),
                'code'  => $response->get_error_code(),
            ]);
        }

        if ($response instanceof WP_REST_Response) {
            $this->respond($response->get_status(), $response->get_data());
        }

        // Plain array/scalar returned directly by a handler.
        $this->respond(200, $response);
    }

    /**
     * Emit a JSON response and terminate the request. The fallback owns
     * the whole request once it takes over, so we exit before WordPress
     * renders a normal page.
     *
     * @param int   $status
     * @param mixed $data
     */
    private function respond(int $status, $data): void
    {
        if (! headers_sent()) {
            status_header($status);
            nocache_headers();
            header('Content-Type: application/json; charset=' . get_option('blog_charset', 'UTF-8'));
            header('X-Robots-Tag: noindex, nofollow', true);
        }

        echo wp_json_encode($data);
        exit;
    }
}
