<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use DeckWP\Connect\Pairing\Handler as PairingHandler;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route the DeckWP dashboard hits to complete an
 * Automatic Pairing flow.
 *
 *     POST /wp-json/deckwp/v1/bootstrap-pairing
 *     X-WP-Nonce: <nonce from /wp-admin/plugin-install.php?tab=upload>
 *     Cookie: wordpress_logged_in_*
 *     Content-Type: application/json
 *
 *     { "pairing_token": "...", "platform_url": "https://deckwp.com" }
 *
 * Response 200 (success):
 *
 *     { "ok": true, "site_id": "<uuid>", "message": "Connected ..." }
 *
 * Response 200 (failure):
 *
 *     { "ok": false, "error": "..." }
 *
 * ## Why this route is NOT HMAC-protected
 *
 * The dashboard's Automatic Pairing flow runs BEFORE the connector
 * has an HMAC secret — by definition, the WHOLE POINT of the call
 * is to obtain one. We fall back to standard WordPress capability
 * auth instead: the caller must be authenticated as a user with
 * `manage_options` (admin), validated via WP's cookie + nonce
 * mechanism. The dashboard accomplishes that by logging in to the
 * site via the operator's WP admin credentials before hitting this
 * endpoint — same cookie + nonce shape it captured during the
 * upload + activation steps.
 *
 * ## What this route does
 *
 * Receives the pairing token + platform URL from the dashboard,
 * then calls {@see PairingHandler::pair()} synchronously — same
 * code path the manual settings-page form uses when the operator
 * pastes a token. The handshake fires inline:
 *
 *   1. POST to <platform_url>/api/v1/sites/.../pair with the token
 *      in the X-DeckWP-Pairing-Token header + connector metadata
 *      in the body.
 *   2. Dashboard verifies the token, returns the HMAC secret +
 *      site UUID + team slug + heartbeat/scan intervals.
 *   3. Connector persists the secret to wp_options.
 *
 * On return, the connector is fully paired — subsequent dashboard
 * calls can use HMAC-signed routes (`/install-batch`, `/scan`,
 * `/inventory`, etc.). The dashboard's confirmAutomatic flow can
 * close the form and redirect to /sites/{id} immediately.
 *
 * ## Idempotency / re-pair
 *
 * If the connector already has settings populated (previous
 * successful pair), the handshake call STILL fires — the dashboard
 * either accepts the new token (re-pair) or rejects it (token
 * expired / belongs to a different team). Either way, the settings
 * get overwritten by the handler's `Settings::update()` call. This
 * matches the Manual flow's behavior.
 */
class BootstrapPairingRoute
{
    /** @var PairingHandler */
    private $handler;

    public function __construct(PairingHandler $handler = null)
    {
        $this->handler = $handler ?? new PairingHandler();
    }

    /**
     * Route registration array. Consumed by
     * {@see \DeckWP\Connect\REST\Server::registerRoutes()}.
     *
     * NOTE the `permission_callback`: we do NOT use the HMAC
     * verifier here. Instead we rely on WP's standard cookie + nonce
     * auth + the `manage_options` capability. Anyone who can install
     * a plugin can also bootstrap the pairing — same threat model.
     *
     * @param  callable  $permissionCallback  Unused — we override.
     * @return array<string, mixed>
     */
    public function args(callable $permissionCallback): array
    {
        return [
            'methods'             => 'POST',
            'permission_callback' => function () {
                // current_user_can() needs the user to be logged in
                // first. WP REST handles cookie auth + nonce validation
                // before this callback fires, so a bad/missing nonce
                // already 401s us out before we get here.
                return function_exists('current_user_can')
                    && current_user_can('manage_options');
            },
            'callback' => [$this, 'handle'],
            'args'     => [
                'pairing_token' => [
                    'required' => true,
                    'type'     => 'string',
                ],
                'platform_url'  => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $token       = (string) $request->get_param('pairing_token');
        $platformUrl = (string) $request->get_param('platform_url');

        if ($token === '' || $platformUrl === '') {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Both pairing_token and platform_url are required.',
            ], 422);
        }

        // Delegate to the same handler the Manual pairing path uses
        // (Settings page form submit). The handler does the full
        // handshake + persists settings on success.
        $result = $this->handler->pair($token, $platformUrl);

        // Pair() returns its own envelope shape; pass it through
        // verbatim so the dashboard side can decode known fields.
        $status = $result['ok'] ?? false ? 200 : 200; // always 200, ok bool inside
        return new WP_REST_Response($result, $status);
    }
}
