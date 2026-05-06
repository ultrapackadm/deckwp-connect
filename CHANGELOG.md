# Changelog — DeckWP Connect

All notable changes to this project will be documented here. Format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [SemVer](https://semver.org/).

## [0.8.0] — 2026-05-06

### Added

- `Backup\BackupManager::delete($absoluteZipPath)` — unlinks a
  previously-snapshotted zip from disk. Idempotent: returns
  `ok=true` with `already_gone=true` when the file is already
  missing, so a retrying retention sweep never gets stuck on a
  no-op. Path-escape guard (realpath containment under
  deckwp-backups/) mirrors restore().

- `REST\Routes\DeleteBackupRoute` — `POST /wp-json/deckwp/v1/delete-backup`.
  HMAC-protected. Accepts `{local_path}` (relative to WP uploads
  basedir) and dispatches to BackupManager::delete(). 422 for
  validation-shaped failures (path escape), 500 for unexpected
  filesystem errors. Powers the dashboard's daily retention
  sweeper (Sprint 4 T6) — backups past `expires_at` get their
  zip deleted on the customer server, then the dashboard flips
  the row to Expired.

### Compatibility

- WordPress 5.6+, PHP 7.4+, ZipArchive
- No breaking changes from v0.7.0
- Pre-v0.8.0 dashboards never call /delete-backup; the route is
  ignored when not in use.

### Smoke-tested

End-to-end via `php artisan backups:sweep` against the dev test
site: 4 expired backups deleted from disk, all 4 rows flipped to
Expired, 1 orphan Running Update marked Failed, 1 orphan Created
Backup marked Failed. Disk went from 5 zips to 0 (one true orphan
zip without a DB row got cleaned manually — out of scope for the
sweeper, which only acts on rows it knows about).

## [0.7.0] — 2026-05-05

### Added

- `Smoke\PostUpdateChecker` — fast, offline-by-default health check
  run after every successful upgrade. Three signals:

  1. Plugin folder + main file still exist on disk.
  2. Plugin main file passes a PHP token-parse check (catches
     half-extracted ZIPs, truncated downloads, syntax errors in
     a freshly-installed file).
  3. If the plugin was active before the upgrade, it must still
     be active after — WP silently auto-deactivates on
     activation-time fatals, and that's exactly the failure
     mode this catches.

  Optional fourth signal (opt-in via `smoke_check_home: true` on
  the install-batch item): wp_remote_head() to the home page
  must not return 5xx. Off by default — sites with basic auth or
  maintenance walls would produce false positives.

- **Auto-rollback** in `Install\Installer`. When the smoke check
  fails AND a pre-update snapshot was taken (Sprint 4 T3), the
  installer immediately calls `BackupManager::restore()` and
  reports `status: rolled_back` to the dashboard with a
  `rollback_reason` field. The dashboard's `UpdateOrchestrator`
  picks that up, transitions the Update row to
  `UpdateStatus::RolledBack`, marks the linked Backup as
  Restored, and leaves `installed_version` at the pre-upgrade
  value (since the restore put the old folder back).

  Without a snapshot, smoke failure surfaces as `status: failed`
  with the reason verbatim — operator handles it manually.

### Wire contract additions

Per-item input on `/install-batch`:

```jsonc
{
  "slug": "formidable-pro",
  "type": "plugin",
  "backup_required": true,
  "smoke_check_home": true                 // NEW (optional, default false)
}
```

Per-item output on `/install-batch` adds a `rolled_back` status:

```jsonc
{
  "slug": "formidable-pro",
  "status": "rolled_back",
  "version_before": "6.29",
  "version_after": "6.29",                 // restored
  "error": "Post-upgrade smoke check failed (...): ...",
  "rollback_reason": "plugin_inactive_after_upgrade",
  "backup": { ... }                        // still present, snapshot is now consumed
}
```

### Compatibility

- WordPress 5.6+, PHP 7.4+, ZipArchive extension
- No breaking changes from v0.6.0
- Callers that skip `backup_required` get the v0.5.0 behavior;
  smoke check still runs on successful upgrades and marks them
  `failed` (without auto-rollback) if anything's broken — strictly
  better than v0.5.0's "report installed even when site is down"
  behavior.

### Dev-only kill switch

Touching `wp-content/uploads/.deckwp-force-smoke-fail` (any contents)
forces the smoke check to return failure on the next upgrade. Used
by the manual smoke harness to validate the rollback path without
producing a real fault. Do NOT ship this file in any production
deploy — it's not surfaced anywhere in the UI and is checked
unconditionally at the top of the smoke flow.

## [0.6.0] — 2026-05-04

### Added

- `Backup\BackupManager` — local plugin-folder snapshot manager.
  `snapshot($slug)` zips `wp-content/plugins/<slug>/` into
  `wp-content/uploads/deckwp-backups/<slug>-<ISO timestamp>-<rand>.zip`,
  returns the path + SHA-256 + size for the dashboard to record.
  `restore($abs, $slug, $expectedChecksum)` verifies the checksum,
  extracts to a uniquely-named sibling, then atomically swaps the
  live folder via move-old-aside / move-new-into-place — failure
  rolls the original folder back from the aside.

  Path-traversal defenses at every entry point: slug allowlist
  regex, `realpath()` containment check on the resolved plugin
  path, zip-slip sweep that rejects any entry name with `../` OR
  outside the expected `<slug>/` root.

- `REST\Routes\RestoreBackupRoute` — `POST /wp-json/deckwp/v1/restore-backup`.
  HMAC-protected like every other route. Accepts
  `{slug, local_path, checksum?}` and dispatches to
  `BackupManager::restore()`. Maps validation-shaped failures
  (checksum mismatch, path escape, zip layout unexpected) to 422
  and unexpected filesystem failures to 500 — the dashboard's
  `RemoteRestoreTrigger` consumes the `error_code` for typed UI
  responses.

- `Install\Installer` — the install-batch handler now accepts an
  optional `backup_required: true` flag per item. When set, the
  installer asks `BackupManager::snapshot()` before running the
  upgrade. If the snapshot fails (disk full, plugin folder
  unreadable), the upgrade is skipped — better to fail loudly
  than to mutate live files without a rollback target. If the
  snapshot succeeds, its metadata (`local_path`, `checksum`,
  `size_bytes`) rides back in the per-item response under a new
  `backup` sub-key for the dashboard to reconcile.

### Storage

- `wp-content/uploads/deckwp-backups/` is the managed directory.
  `.htaccess` denies all on Apache; a blank `index.php` blanks
  out directory listings on hosts whose default config still
  honors `DirectoryIndex`. **Nginx note:** nginx ignores
  `.htaccess` — operators on nginx should add an explicit deny
  rule for that path in the server block:

  ```nginx
  location ~ ^/wp-content/uploads/deckwp-backups/ {
      deny all;
      access_log off;
      log_not_found off;
      return 404;
  }
  ```

  The randomized 6-hex-char suffix in zip filenames defeats casual
  enumeration even without the nginx rule, but defense-in-depth is
  cheap.

### Wire contract additions

- Per-item input on `/install-batch`:

  ```jsonc
  {
    "slug": "formidable-pro",
    "type": "plugin",
    "backup_required": true,                   // NEW (optional)
    "download_url": "https://..."              // existing
  }
  ```

- Per-item output on `/install-batch`:

  ```jsonc
  {
    "slug": "formidable-pro",
    "status": "installed",
    "version_before": "6.29",
    "version_after": "6.30.1",
    "error": null,
    "backup": {                                 // NEW (when snapshot ran + succeeded)
      "local_path": "deckwp-backups/formidable-pro-2026-05-04T21-30-00-abc123.zip",
      "checksum": "sha256-hex (64 chars)",
      "size_bytes": 524288
    }
  }
  ```

### Compatibility

- Requires WordPress 5.6+, PHP 7.4+, ZipArchive extension
  (PHP 7.4 ships it by default; refused with `zip_unavailable`
  error_code if missing).
- No breaking changes from v0.5.0. Existing
  `/install-batch` callers that don't set `backup_required` get
  the v0.5.0 behavior unchanged — no `backup` key in the response.
- The `/restore-backup` route is new; pre-v0.6.0 dashboards
  haven't called it.

## [0.5.0] — 2026-05-04

### Added
- `Install\Installer::installOne()` now accepts an optional
  `download_url` field per item. When present, the upgrade routes
  through `WP_Upgrader::run(['package' => $url, ...])` instead of
  `Plugin_Upgrader::upgrade()`, bypassing the `update_plugins`
  transient that the wp.org flow consults. `clear_destination=true`
  preserves the atomic-replace semantics so the existing plugin
  directory is wiped before extraction — no orphan files from the
  prior version.

  This is the connector half of the dashboard's premium-catalog
  update flow: the dashboard resolves the download URL via the
  team's UltraPack catalog token, forwards it as `download_url`
  in the install-batch body, and the connector pulls the ZIP
  directly from the catalog without ever seeing the token.

### Compatibility
- Requires WordPress 5.6+, PHP 7.4+
- No breaking changes from v0.4.0
- Free wp.org plugin updates work unchanged — the new branch only
  fires when `download_url` is non-empty in the request body.

## [0.4.0] — 2026-05-04

### Added
- `Install\Installer` — installs/upgrades a batch of plugins via
  WordPress core's `Plugin_Upgrader::upgrade()`. Refreshes the
  `update_plugins` site transient first via `wp_update_plugins()`
  so the upgrader has fresh download URLs to work with. Per-item
  results map cleanly to the dashboard's Update lifecycle:
  `installed` → Success with version bump, `unchanged` → Success
  with no bump (already on latest), `failed` → Failed with the
  WP_Error message surfaced in the error field. Each item runs
  independently — one failure doesn't abort the batch.
- `REST\Routes\InstallBatchRoute` — second inbound REST route on
  the connector: `POST /wp-json/deckwp/v1/install-batch`.
  HMAC-protected via the existing `REST\Auth\HmacVerifier`.
  Body shape: `{items: [{slug, type}, ...]}`. Caps batch size at
  25 items (defense in depth — the dashboard's outbound trigger
  already enforces its own limits, but a malicious or buggy
  caller shouldn't be able to spin the install loop for minutes).
- Bootstrap registers the new route via `REST\Server::registerRoutes()`
  alongside the existing `/scan` route.

### Compatibility
- Requires WordPress 5.6+, PHP 7.4+
- No breaking changes from v0.3.0
- Wp.org plugins only in this release. Premium UltraPack catalog
  support depends on the dashboard's catalog-client implementation
  and a per-team `ultrapack_catalog_token` lifecycle, which lands
  in a follow-up sprint.
- Filesystem requirement: `Plugin_Upgrader` needs write access to
  `wp-content/plugins/`. On hosts where `FS_METHOD` resolves to
  `ftp` or `ssh2` without stored credentials, the upgrade returns
  `unable_to_connect_to_filesystem` — surfaced verbatim so the
  operator can fix `wp-config.php`.

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
