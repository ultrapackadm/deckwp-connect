# Changelog — DeckWP Connect

All notable changes to this project will be documented here. Format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [SemVer](https://semver.org/).

## [Unreleased] — 0.1.0

### Added
- `DeckWP\Connect\Bootstrap` — singleton subsystem registry, kicked off
  by the `plugins_loaded` action. Replaces the inline subsystem
  registration that previously lived in the main plugin file.
- `DeckWP\Connect\Settings\Page` — admin page under Settings →
  DeckWP Connect (the slug the existing plugin-row Settings link
  already pointed to). Two states driven by `Storage\Settings::isPaired()`:
  an "unpaired" form that takes a pairing token + dashboard URL, and a
  "paired" status block (Site UUID, team slug, dashboard link, callback
  URL, intervals, last-connected timestamp) plus a Disconnect button.
  Form processing follows the canonical Post-Redirect-Get pattern with
  `check_admin_referer`, `current_user_can('manage_options')`, and
  `add_settings_error` for flash notices that survive the redirect.
- `DeckWP\Connect\Pairing\Handler` — performs the outbound handshake
  against `POST {dashboard}/api/v1/connect/pair`. Collects local
  metadata (`wp_version`, `php_version`, `is_multisite`,
  `plugin_version`, `connector_capabilities`) for the JSON body, sends
  the user-supplied token in the `X-DeckWP-Pairing-Token` header, and
  on a 2xx response persists `site_id`, `hmac_secret`, `team_slug`,
  `callback_url`, and the `heartbeat_seconds` / `scan_seconds` intervals
  via `Storage\Settings::update`. Returns a uniform result envelope
  (`ok`, `message`, `site_id`) for the UI to render. Counterpart of
  `App\Http\Controllers\Api\V1\ConnectController::pair` in deckwp-app.
- `DECKWP_CONNECT_SKIP_SSL_VERIFY` constant — opt-out of TLS verification
  on outbound calls when set to `true` in `wp-config.php`. Required for
  local pairing against Herd-served `*.test` URLs (self-signed cert).
  Defaults to verify-on. NEVER enable in production.
- `DeckWP\Connect\HTTP\ApiClient` — thin wrapper around `wp_remote_post`
  with a uniform result envelope (`ok`, `status`, `body`, `raw`,
  `error`). Wraps Laravel-style `{message: "..."}` and `{error: "..."}`
  response bodies, plus generic fallbacks per HTTP status (401 token
  expired, 422 metadata rejected, 429 rate-limited, 5xx server error).
  No retries — caller decides retry policy. User-Agent identifies the
  connector version, WP version, PHP version for server-side debugging.
- `DeckWP\Connect\Storage\Settings` — multisite-aware wrapper around
  `get_option`/`get_site_option` for the `deckwp_connect_settings`
  option. Centralizes schema knowledge (`isPaired()`, `clearConnection()`,
  `update(array $patch)`) so future storage backend changes (encryption
  at rest, per-site rows on multisite) only touch one place. Preserves
  the `autoload=false` set by the activation hook — `update_option`
  doesn't change autoload state.
- Plugin bootstrap: header, constants, PSR-4 autoloader (Composer fallback
  to manual `spl_autoload_register`)
- Activation/deactivation hooks: creates `deckwp_connect_settings` option
  with `site_id`, `token`, `hmac_secret`, `platform_url`, `connected_at`
- `deckwp_connect_ensure_pairing_token()`: generates a 48-char hex token
  + 32-byte base64 hmac_secret if missing (uses `random_bytes()` for
  cryptographic randomness)
- Multisite support: settings stored in `wp_sitemeta` when network-active
- `DeckWP\Connect\REST\Auth\HmacVerifier`: SHA-256 HMAC verification with
  60-second anti-replay window, constant-time compare via `hash_equals`,
  works for both REST routes and the planned init-hook fallback transport
- Settings link in the plugins list row
- README with install instructions, security notes, dev setup
- composer.json with PSR-4 autoload + WPCS dev dependency

### Changed
- `HmacVerifier` canonical format upgraded from `{ts}\n{nonce}\n{body}` to
  `{ts}.{nonce}.{METHOD}.{path}.{sha256(body)}` to match the deckwp-app
  `HmacSigner` (M1). Locks signatures to method+path so an intercepted
  signed request can't be replayed against a different endpoint, and
  hashes the body so the canonical input stays bounded for large
  payloads (backups, scan reports). Verifier now extracts METHOD from
  `WP_REST_Request::get_method()` (or `$_SERVER['REQUEST_METHOD']` for
  the init-hook fallback) and the request path from `$_SERVER['REQUEST_URI']`
  (query string stripped). Path includes the `/wp-json/` prefix and any
  subdirectory WP install prefix — must match what the signer used.
- Validation now rejects requests missing METHOD or path (with empty
  signature/nonce/timestamp checks already in place).

### Security
- `HmacVerifier` is now resistant to replay-to-different-endpoint attacks
  within the 60s timestamp window. Nonce uniqueness tracking still
  pending (planned for G1 hardening pass).

### Planned (Sprint 1 — G2-G6)
- `Settings\TokenManager` class (regenerate token UI)
- `Settings\Page` (admin settings page with copy-token UI)
- `REST\Server` registering `deckwp/v1/*` routes
- REST routes: `/confirm-pair`, `/bootstrap-token`, `/verify`, `/inventory`,
  `/update-batch`, `/install-batch`, `/plugin-action`, `/theme-action`,
  `/maintenance`, `/sso-login`, `/whitelabel`, `/backup-create`,
  `/set-managed-slugs`
- `Transport\InitHookFallback` — REST-bypass transport when /wp-json blocked
- `DropIn\Installer` + `dropin/deckwp-fatal-handler.php` (multisite-aware)
- `Whitelabel\Branding` — rewrite plugin metadata in admin
- `Maintenance\Page` — branded HTTP 503 holding page
- `Updater\SelfUpdater` — pulls connector updates from
  `https://deckwp.com/api/v1/connector/latest`
- `Updater\UpdateSuppressor` — hides "update available" for managed slugs
