# Changelog — DeckWP Connect

All notable changes to this project will be documented here. Format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [SemVer](https://semver.org/).

## [0.3.0] — 2026-05-04

### Added
- `Scan\Scanner` — local-filesystem security scanner. Three checks
  in this release, each fast, deterministic, and free of external
  API calls so the scan can run in-request on a 60s budget:
    1. PHP files inside `wp-content/uploads/` (high-signal artifact
       of webshell uploads).
    2. Obfuscation patterns (`eval(base64_decode(...))`,
       `eval(gzinflate(...))`, `eval(str_rot13(...))`) anywhere in
       `plugins/` or `themes/`. The three workhorse signatures of
       injected backdoors — high precision, low false-positive
       rate against legitimate plugin code.
    3. World-writable `wp-config.php`. Common on shared hosts
       where someone chmod 777'd the file to "fix permissions";
       lets unprivileged users on the same server read DB
       credentials.
  Soft-cap of 50 findings per run; payloads truncate gracefully
  with a `truncated: true` flag. Skips files >5 MB and
  `vendor/`/`node_modules/`/`.git/`/`tests/` subdirectories.
- `Scan\Scheduler` — WP-Cron-driven scan sender. Hooks
  `deckwp_connect_scan` to a `cron_schedules`-registered interval
  (`deckwp_connect_scan_interval`, value pulled from
  `scan_seconds` settings key, default 86400). Gated by
  `DECKWP_CONNECT_ENABLE_SCAN` (off by default) so the connector
  doesn't fire results into the void during phased rollouts.
  Mirrors the heartbeat scheduler's 401 self-cleanup so a
  dashboard-revoked connector cleans local state on the next
  scan tick.
- `REST\Server` — registers the connector's `deckwp/v1/*` REST
  surface. First route: `POST /wp-json/deckwp/v1/scan`,
  HMAC-protected via the existing `REST\Auth\HmacVerifier` as a
  `permission_callback`. Triggered by the dashboard's "Scan now"
  button — runs the scan synchronously and returns the result
  envelope inline (cron-driven scans take the outbound event-push
  path instead).
- `REST\Routes\ScanRoute` — handler for the `/scan` route.
  Delegates to `Scan\Scheduler::runScan()` so manual and
  scheduled scans share the same Scanner code path; only the
  delivery channel differs.

### Compatibility
- Requires WordPress 5.6+, PHP 7.4+
- No breaking changes from v0.2.1
- Interoperates with deckwp-app's matching v0.3.0 release that adds
  the `scan_completed` event handler and the dashboard-side "Scan
  now" button

## [0.2.1] — 2026-05-04

### Fixed
- The dashboard → connector half of the disconnect lifecycle was
  missing. After v0.2.0 closed the connector → dashboard side
  (operator clicks Disconnect on WP, dashboard flips Revoked), the
  reverse direction left the connector stranded: clicking Disconnect
  on the dashboard's `/sites/{uuid}` flipped Revoked + deleted the
  credential, but the WP admin still showed "This site is paired
  with DeckWP" and the connector kept firing heartbeats with
  credentials that could never authenticate again. Now
  `Heartbeat\Scheduler::sendNow()` self-cleans on a 401 response:
  the dashboard's `VerifyConnectorHmac` middleware returns 401 when
  there's no credential row for the site (which is exactly the
  state after `DisconnectProcessor` runs), so the connector treats
  any 401 as proof of revocation. It calls
  `Settings::clearConnection()`, stashes a 1-day transient with the
  platform URL captured before the clear, and the next admin page
  render shows a warning banner ("DeckWP revoked this connection")
  with a "Re-pair this site" link back to `/sites/create`.

  Lag is bounded by the heartbeat interval — at the default 300s
  cron tick, the connector's UI catches up within 5 minutes of a
  dashboard-side revoke. Operators in a hurry can hit "Send
  heartbeat now" to trigger the cleanup immediately. Edge case:
  a transient 401 from clock skew >60s would also trigger this and
  bump the operator to unpaired; the timestamp window is 60s and
  `time()` is re-derived per request, so this only happens on a
  meaningfully misconfigured server clock — re-pair is 30s.

## [0.2.0] — 2026-05-03

### Added
- `DeckWP\Connect\HMAC\Signer` — outbound request signer. Mirror of the
  deckwp-app `HmacSigner` wire format
  (`{ts}.{nonce}.{METHOD}.{path}.{sha256(body)}`, hex hmac-sha256).
  Caller is responsible for `base64_decode`-ing the stored secret to
  raw bytes before signing — matches the Laravel signer's contract.
- `DeckWP\Connect\Inventory\PluginInventory` — collects the local
  WP plugin list (slug, name, version, active state, update-available
  flag from the `update_plugins` site transient). Output shape mirrors
  what the dashboard's `PluginInstallation` table upserts against.
- `DeckWP\Connect\Heartbeat\Scheduler` — WP-Cron scheduler + sender.
  Hooks `deckwp_connect_heartbeat` to a `cron_schedules`-registered
  interval (`deckwp_connect_heartbeat_interval`, value pulled from the
  `heartbeat_seconds` settings key, server-issued during pair, default
  300). Payload: event type, sent_at, wp/php versions, site_url,
  multisite flag, full plugin inventory. Cron scheduling gated by
  `DECKWP_CONNECT_ENABLE_HEARTBEAT` (default off) so the connector
  doesn't fire against an endpoint the dashboard hasn't shipped yet —
  flip to `true` once `/api/v1/sites/{id}/events` is live in
  deckwp-app. Synchronous `sendNow()` method bypasses the schedule
  and the flag for manual-trigger use cases.
- "Send heartbeat now" button on the settings page (paired state).
  Calls `Scheduler::sendNow()` and surfaces the HTTP status + any
  error message via `add_settings_error`. Useful for validating the
  signer + payload mid-development without waiting on cron.
- `DeckWP\Connect\HTTP\ApiClient::postBody()` — POSTs a pre-encoded
  body string. Required for HMAC-signed requests where the signer
  hashes the exact bytes that go on the wire — re-encoding inside
  the client (as `postJson` does) would diverge from that hash and
  break server-side verification. `postJson` now thin-wraps `postBody`
  after encoding.
- `Pairing\Handler::disconnect()` — best-effort outbound `disconnect`
  event so the dashboard can flip the site row from `paired` to
  `revoked` instead of leaving it at "Paired" with stale
  `last_seen_at`. Sent as a normal HMAC-signed event to the same
  callback URL the heartbeat uses; the dashboard's `EventsController`
  already discriminates by the `event` field, so no new endpoint is
  needed. A dashboard that hasn't shipped the handler yet returns
  200 + `{"status":"ignored"}` (forward-compat); the connector
  reads the response body and surfaces this honestly as a warning
  ("dashboard accepted the request but doesn't yet process disconnect
  events") rather than claiming the dashboard was notified.
  `Settings\Page::handleDisconnectSubmit()` now calls this BEFORE
  `clearConnection()` — after the local clear the secret is gone and
  we can't sign anymore — and renders three outcomes in the admin
  notice: `accepted` → success, `ignored` → warning (local only),
  transport/HMAC error → warning (local only).

### Fixed
- `REST\Auth\HmacVerifier` was hashing with the *base64-encoded*
  secret string, but the dashboard's `HmacSigner` hashes with the raw
  decoded bytes. Every inbound signature from the dashboard would
  have failed verification once the dashboard started signing
  requests. The verifier now `base64_decode`s the stored
  `hmac_secret` before passing it to `hash_hmac`. No customer impact:
  no inbound dashboard → connector requests have shipped yet.
- Settings page admin notices were lost across the PRG redirect.
  `add_settings_error()` only writes to the request-scoped
  `$wp_settings_errors` global, and the bridge into the
  `'settings_errors'` transient that core's `options.php` does for you
  doesn't run when a custom admin handler does its own
  `wp_safe_redirect()`. Symptom: clicking "Send heartbeat now" (or
  Connect, or Disconnect) appeared to do nothing — the request was
  fully processed and persisted, but the green/red banner never
  rendered after the 302. Fix: `Settings\Page::dispatchSubmission()`
  now stashes the slug's notices into a per-user, plugin-prefixed
  transient (`deckwp_connect_admin_notice_<user_id>`) before
  redirecting, and `render()` reads them back via
  `flushTransientNotices()` and re-injects them so
  `settings_errors(self::SLUG)` renders the banner like any inline
  notice. We deliberately avoid core's shared `'settings_errors'`
  transient: any plugin hooked into `admin_notices` that calls bare
  `settings_errors()` (without our slug arg) would consume that
  transient before our render runs, silently swallowing our banner —
  `get_settings_errors()` deletes the transient after the first
  merge.
- `Heartbeat\Scheduler::sendNow()` now writes a one-line
  `error_log()` entry on every outcome (ok or fail). The admin
  notice is the primary UX signal but it rides a 30-second transient
  that's easy to lose on a fast browser; the log line is the durable
  trace. Enable `WP_DEBUG_LOG` in `wp-config.php` to route it to
  `wp-content/debug.log`.

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
