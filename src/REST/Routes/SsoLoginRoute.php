<?php

namespace DeckWP\Connect\REST\Routes;

defined('ABSPATH') || exit;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound REST route that consumes a one-time SSO login token
 * and logs the operator in as a WP administrator.
 *
 *     GET /wp-json/deckwp/v1/sso-login?token=<ts>.<jti>.<sig>
 *
 * GET — not POST, not HMAC-header-protected — because the
 * browser navigates directly to this URL via window.open(). The
 * security posture is built into the token itself:
 *
 *   - `ts` (Unix timestamp) and `exp = ts + 60s` bound the
 *     window for a stolen URL.
 *   - `jti` is a 32-hex-char identifier (128 bits of entropy);
 *     once consumed, stashed in a transient with a 5-minute TTL
 *     to defeat replay even within the 60s window.
 *   - `sig` is HMAC-SHA256(`<ts>.<jti>`, hmac_secret) with the
 *     same shared secret the regular HMAC headers use. Without
 *     the secret, a third party can't forge a token.
 *
 * On valid token:
 *   1. Pick a WP user — first one with role `administrator`.
 *      Configurable via `deckwp_sso_login_user_id` filter for
 *      operators who want a dedicated audit user.
 *   2. wp_set_auth_cookie($user_id, true) — sets the standard WP
 *      session cookie, lifetime same as a checkbox-remember login.
 *   3. 302 redirect to /wp-admin/.
 *   4. Mark jti consumed in a transient (defense in depth on
 *      this side; the dashboard's sso_sessions.jti UNIQUE is
 *      the other layer).
 *
 * On invalid token: return 401 with a short error body. No
 * redirect — keep the browser at the connector URL so the
 * operator notices something went wrong.
 *
 * ## Why not HMAC headers
 *
 * The HMAC header protocol used by every other route here
 * requires the dashboard to sign the request. SSO is the
 * inverse: the BROWSER navigates to the URL, and browsers
 * don't carry custom request headers when following navigation.
 * The token IS the signed credential, in the URL.
 */
class SsoLoginRoute
{
    /** Maximum age of a token (seconds since `ts`) we'll accept. */
    public const TOKEN_TTL_SECONDS = 60;

    /**
     * Transient TTL for consumed jti markers. Has to cover
     * the token TTL plus any reasonable clock skew between
     * the dashboard and the connector. 5 minutes is generous.
     */
    private const CONSUMED_TRANSIENT_TTL = 300;

    /**
     * Permission callback returns `true` unconditionally — the
     * token's HMAC is the auth, and we validate it inside the
     * handler so we can return our own 401 envelope on failure
     * (rather than the default "403 forbidden" WP REST emits
     * when permission_callback returns false).
     *
     * @param  callable  $permissionCallback  Unused — see above.
     * @return array<string, mixed>
     */
    public function args(callable $permissionCallback): array
    {
        return [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle'],
            'args' => [
                'token' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ];
    }

    public function handle(WP_REST_Request $request)
    {
        $token = (string) $request->get_param('token');

        // Parse: <ts>.<jti>.<sig>
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return $this->error(401, 'malformed_token', 'SSO token format is invalid.');
        }
        [$ts, $jti, $sig] = $parts;

        if (! ctype_digit($ts) || strlen($jti) < 16 || ! ctype_xdigit($sig)) {
            return $this->error(401, 'malformed_token', 'SSO token contents fail basic shape checks.');
        }

        $tsInt = (int) $ts;
        if ($tsInt <= 0 || abs(time() - $tsInt) > self::TOKEN_TTL_SECONDS) {
            return $this->error(401, 'token_expired', sprintf(
                'SSO token is outside the %d-second validity window.',
                self::TOKEN_TTL_SECONDS
            ));
        }

        // Replay guard — has this jti been consumed already?
        $consumedKey = 'deckwp_sso_consumed_' . $jti;
        if (function_exists('get_transient') && get_transient($consumedKey) !== false) {
            return $this->error(401, 'token_replayed', 'SSO token has already been used.');
        }

        $secretRaw = $this->loadHmacSecret();
        if ($secretRaw === null) {
            return $this->error(500, 'no_secret', 'Connector has no hmac_secret on file. Re-pair this site.');
        }

        $expectedSig = hash_hmac('sha256', $ts . '.' . $jti, $secretRaw);
        if (! hash_equals($expectedSig, $sig)) {
            return $this->error(401, 'bad_signature', 'SSO token signature does not match.');
        }

        // Mark consumed first so a concurrent retry can't squeeze
        // through. The "set transient before do the action" order
        // is deliberate.
        if (function_exists('set_transient')) {
            set_transient($consumedKey, '1', self::CONSUMED_TRANSIENT_TTL);
        }

        $userId = $this->resolveLoginUser();
        if ($userId === 0) {
            return $this->error(500, 'no_admin_user', 'Could not find an administrator user to log in as.');
        }

        // Set the standard WP session cookie. `true` for "remember"
        // so the cookie lifetime is the WP-default ~14 days, same
        // as a normal checkbox-remember login.
        wp_set_auth_cookie($userId, true);
        wp_set_current_user($userId);

        $redirectTarget = function_exists('admin_url') ? admin_url('/') : '/wp-admin/';

        // 302 redirect. The browser drops us at /wp-admin/ already
        // logged in.
        return new WP_REST_Response(null, 302, [
            'Location' => $redirectTarget,
        ]);
    }

    /**
     * Pull the raw HMAC secret bytes from plugin settings.
     * `hmac_secret` is stored base64-encoded; the dashboard signs
     * tokens with the decoded raw bytes, so we have to decode here
     * before comparing.
     */
    private function loadHmacSecret(): ?string
    {
        $opt = function_exists('is_multisite') && is_multisite()
            ? (array) get_site_option('deckwp_connect_settings', [])
            : (array) get_option('deckwp_connect_settings', []);

        $b64 = (string) ($opt['hmac_secret'] ?? '');
        if ($b64 === '') {
            return null;
        }
        $raw = base64_decode($b64, true);
        if ($raw === false || $raw === '') {
            return null;
        }
        return $raw;
    }

    /**
     * Pick the WP user to log the operator in as. Default: the
     * first administrator user. Overrideable via filter for
     * operators who want a dedicated audit user (e.g. "deckwp-bot").
     *
     * Returns 0 if no admin user can be resolved.
     */
    private function resolveLoginUser(): int
    {
        // Filter hook for operator override:
        //
        //   add_filter('deckwp_sso_login_user_id', function ($user_id) {
        //       return get_user_by('login', 'deckwp-audit')->ID;
        //   });
        $filtered = (int) apply_filters('deckwp_sso_login_user_id', 0);
        if ($filtered > 0 && get_user_by('id', $filtered)) {
            return $filtered;
        }

        // Fallback: first user with role administrator. Sorted by
        // ID so the choice is deterministic across calls.
        $users = get_users([
            'role'    => 'administrator',
            'orderby' => 'ID',
            'order'   => 'ASC',
            'number'  => 1,
            'fields'  => ['ID'],
        ]);

        if (empty($users)) {
            return 0;
        }
        return (int) $users[0]->ID;
    }

    /**
     * @return WP_REST_Response
     */
    private function error(int $status, string $code, string $message)
    {
        return new WP_REST_Response([
            'ok'         => false,
            'error_code' => $code,
            'error'      => $message,
        ], $status);
    }
}
