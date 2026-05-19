# Changelog — DeckWP Connect

All notable changes to this project will be documented here. Format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [SemVer](https://semver.org/).

## [0.22.0] — 2026-05-19

Whitelabel toggles: second wave. Two more of the four reserved
toggles from v0.21.0 ship a working WP-side handler. The
remaining two image-based toggles (`custom_login`,
`adminbar_logo`) stay reserved for v0.23.0.

### Added

- `Branding::filterPluginRowMeta` (was a pass-through in v0.21.0)
  now wires the `help_links` toggle. When ON, every plugin row in
  `wp-admin/plugins.php` has all URL-bearing meta items stripped
  (View details, Visit plugin site, By Author). If
  `help_links_url` is set in the config, a single "Support" anchor
  pointing at that URL is appended. Version + plain-text meta
  items are preserved.

  Applies to ALL plugin rows, not just the connector's — matches
  the agency-rebrand UX promise (one Support destination across
  the whole plugins list, not one-per-plugin scattered links).

- `Branding::maybePrintSuppressActivateCss` wires the
  `suppress_activate` toggle via the
  `admin_print_styles-plugins.php` action. When ON and the request
  carries an `activate` / `deactivate` / `deleted` query arg (the
  query args that cause WP to render an inline state-change notice
  on plugins.php), the handler emits a scoped `<style>` block that
  hides the `.wrap > #message` / `.wrap > .notice.updated.notice-success`
  divs core writes for those notices.

  CSS injection (vs. mutating `$_GET` in `admin_init`) was chosen
  because the notice is rendered by an `echo` in core's
  `plugins.php` template — there's no hook to unhook — and
  mutating the query args would side-effect the row-highlighting
  the plugin list table does for activate-multi. CSS is the
  least invasive option.

- `Branding::getToggleString()` helper alongside the existing
  `isToggleOn()` — same per-request cache, returns `''` for
  missing / non-string values. Will be reused by the v0.23.0
  image-URL toggles.

### Wire contract change

None. The `toggles` block on `WhitelabelRoute` already accepts
both keys (v0.21.0); this release wires the WP-side
behavior. Existing dashboards continue to send the full toggle
block and pre-v0.22.0 connectors silently ignore these two
keys' effects (storage was already happening).

### Still reserved (not yet wired)

- `custom_login` — login screen logo override (URL + accent color)
- `adminbar_logo` — admin bar logo node override (URL)

Both ship in v0.23.0 once the image-URL handling pattern is
worked out and visually reviewed on the wptestes connector.

## [0.21.0] — 2026-05-19

Whitelabel foundation extended with agency-level toggles —
boolean switches that apply globally (not per-plugin) for
agency rebrand workflows. v0.21.0 ships the foundation + the
first toggle wired; the remaining four toggles are reserved
in the config shape and land in follow-up releases.

### Added

- `WhitelabelRoute` now accepts a `toggles` block alongside the
  existing `plugins` / `themes` blocks:

  ```json
  {
    "plugins": { ... },
    "themes": { ... },
    "toggles": {
      "hide_updates":         true,
      "suppress_activate":    false,
      "help_links":           false,
      "help_links_url":       "",
      "custom_login":         false,
      "custom_login_logo_url": "",
      "custom_login_color":   "",
      "adminbar_logo":        false,
      "adminbar_logo_url":    ""
    }
  }
  ```

  Sanitization is strict (bools coerced, strings type-checked,
  unknown keys dropped). Response envelope gains a `stored.toggles`
  count alongside the existing `plugins` / `themes` counts.

- `Branding::filterOwnUpdateNotice` — first toggle wired:
  `hide_updates`. When ON, strips the connector's OWN row from
  the `update_plugins` site transient so the rebranded plugin
  doesn't surface an "Update available" notice in customer
  wp-admin. Distinct from `UpdateSuppressor` (which gates the
  dashboard's managed-slugs list); this one targets the
  connector itself only.

  Bypasses when `DECKWP_CONNECT_ALLOW_MANAGED_UPDATES` is
  defined truthy, same posture as the suppressor — the
  dashboard's own `/install-batch` refresh must see the row.

### Wire contract change

Purely additive. Pre-v0.21.0 connectors ignore the new `toggles`
key; pre-v0.21.0 dashboards don't send it. Existing
`plugins` / `themes` override semantics unchanged.

### Reserved (not yet wired)

The config shape carries fields for four more toggles —
`suppress_activate`, `help_links`, `custom_login`,
`adminbar_logo`. Each comes with its own WP-filter-specific
implementation that needs visual review in the customer
wp-admin; landing them one-per-commit so each can be tested
independently.

## [0.20.0] — 2026-05-17

Theme inventory ships in the heartbeat payload. The dashboard now has
the data it needs to render a per-site Themes inventory (matching the
existing Plugins inventory), detect outdated themes, and surface
update / activate / install actions for themes.

### Added

- `Inventory\ThemeInventory` — collects the local theme inventory
  via `wp_get_themes()`, emits one row per theme with slug, name,
  version, active flag (compared against `get_stylesheet()`), parent
  slug for child themes (from the `Template:` header), and update
  metadata read from the `update_themes` site transient.
- `Heartbeat\Scheduler::buildPayload()` now includes a `themes[]`
  key alongside the existing `plugins[]`. Same upsert contract on
  the dashboard side (HeartbeatProcessor::syncThemes).
- Standalone-theme self-reference defense: when WP reports
  `Template: <self>` for a theme that isn't actually a child (older
  WP convention for parent themes), the connector strips that to
  null so the dashboard doesn't store a self-referencing parent.

### Wire contract change

The heartbeat payload shape is purely additive. Pre-v0.20
connectors omit `themes` entirely; pre-v0.20 dashboards ignore the
new key. New dashboards (deckwp-app Themes sprint) distinguish
"themes key absent" (pre-v0.20 connector, no info to apply) from
"themes key present and empty" (impossible on a real WP install,
but valid payload shape) so a pre-v0.20 connector heartbeat never
prunes an existing theme inventory.

### Changed

- Heartbeat success log line now includes `themes=N` alongside the
  existing `plugins=N` count for parity in operator diagnostics.

## [0.19.0] — 2026-05-13

Reports plugin dependencies after every install so the dashboard
can auto-install missing wp.org dependencies. Closes the loop on
the "Pro plugin requires free plugin from wp.org" UX trap (e.g.
Analytify Pro requires Analytify free) — operator clicks Install
once, dashboard installs the dep + retries activation.

### Added

- `Installer::readPluginRequiresPlugins()` — reads the `Requires
  Plugins:` header from a plugin file via `get_file_data()`.
  Works on WP 4.9+ even though the header is officially WP 6.5+
  (the underlying file scan is version-agnostic).
- `Installer::filterMissingDependencies()` — filters a required-
  plugins list down to slugs that aren't installed + active on
  this site. Match logic walks `get_plugins()` keys against the
  required slug as a folder name; supports single-file plugins.
- `install-batch` response now carries two new keys per item:
  - `requires_plugins` — raw list from the header (informational)
  - `missing_dependencies` — slugs that need installation/activation
  Empty arrays still emit so the dashboard can branch on key
  presence vs fall back to old-connector behavior.

### Wire contract change

The `install-batch` response shape is purely additive. Old
dashboards that don't read the new keys continue working
unchanged; new dashboards (deckwp-app Plugin Dependencies sprint)
detect `missing_dependencies` presence and auto-dispatch follow-up
installs for the wp.org slugs listed there.

## [0.18.0] — 2026-05-12

Adds `/bootstrap-pairing` — the inbound REST route the DeckWP
dashboard's new Automatic Pairing flow uses to push a pairing
token into the connector. Pairs with deckwp-app's Day 4 of the
AUTOMATIC_PAIRING sprint.

### Added

- `POST /wp-json/deckwp/v1/bootstrap-pairing` — accepts
  `{pairing_token, platform_url}`, delegates to the existing
  `Pairing\Handler::pair()` for the handshake. Returns the same
  `{ok, message, site_id}` envelope shape the Manual pairing
  path produces.

### Why this route is NOT HMAC-protected

The Automatic Pairing flow runs BEFORE the connector has an
HMAC secret — the whole point of the call is to obtain one.
Auth falls back to standard WP cookie + nonce + the
`manage_options` capability. The dashboard establishes that
session by logging in via the operator's WP admin credentials
in the steps preceding this call (login → verify admin →
install plugin → activate plugin → bootstrap-pairing).

### Pairs with

- DeckWP `b55a6a5` (Day 1), `63055f2` (Day 2), `5adcae5` (Day 3),
  and the upcoming Day 4 commit which wires `RemoteWpClient::pair()`
  end-to-end through this route.

## [0.17.0] — 2026-05-11

Adds the theme equivalent of `/plugin-toggle` — `/theme-switch`.
Completes the theme parity left over from v0.16 (theme install)
so the dashboard's Library install-progress modal can render an
"Activate now" button on succeeded theme rows.

### Added

- `POST /wp-json/deckwp/v1/theme-switch` — accepts `{slug}`.
  Calls `switch_theme($stylesheet)` to activate the theme as
  the site's live one. Idempotent (switching to the active
  theme is a no-op). Re-reads `get_stylesheet()` after the
  call to detect rare "switch didn't take" cases (multisite
  network theme handlers, preset-lock plugins) and surfaces
  the mismatch in the response.

### Why a separate route from /plugin-toggle

Plugin activation is additive (multiple plugins can be active);
theme activation is destructive (exactly one active theme,
switching replaces the previous one on the live frontend).
The verb-level distinction matters enough — operator-facing
copy in the dashboard differs between "Activate this plugin
now" and "Switch the live theme to this one" — that conflating
them in `plugin-toggle` would force every caller to carry a
`kind` discriminator AND would obscure the destructiveness
warning the theme path needs.

### Out of scope (deferred)

- Theme deactivation. WordPress has no `deactivate_theme()`
  primitive — switching away from a theme happens by activating
  a different one. The dashboard surfaces a "you must pick
  another theme to switch to" affordance instead.
- Per-row theme toggle on the site detail page. Lands with
  the Task 1.2 (Site detail light) work in
  `_planning/FRONTEND_ROADMAP.md`.

## [0.16.0] — 2026-05-10

Adds theme install + upgrade to `/install-batch`. Until now the
endpoint rejected `type: "theme"` items with `Unsupported type
"theme" — handles plugins only.` The dashboard's Library page
already browses + opens detail modals for themes; this release
closes the loop so "Install on…" actually works on a theme.

### Added

- `Installer::installOneTheme($slug, $downloadUrl, $activateAfterInstall)`
  — parallel implementation to the plugin path. Uses
  `Theme_Upgrader::install()` for fresh installs and
  `Theme_Upgrader::upgrade($stylesheet)` for upgrades. Resolves
  the wp.org download URL via `themes_api('theme_information')`
  when the dashboard didn't send a custom `download_url`.
- `runFreshInstallTheme()` — mirror of `runFreshInstall()` but
  for themes. Skips the snapshot/smoke path (Sprint 4 lifecycle
  work for themes lands later).
- `withThemeActivation()` — applies `switch_theme($stylesheet)`
  when the operator opted into "Activate after install". Theme
  activation is destructive (replaces the live frontend's look),
  so the dashboard's checkbox semantics carry forward unchanged —
  it's opt-in for a reason.
- `findThemeStylesheet($slug)` + `readThemeVersion($stylesheet)`
  — analogues to `findPluginFile()` / `readPluginVersion()`. The
  stylesheet lookup walks `wp_get_themes()` matching on the
  stylesheet directory AND the theme's TextDomain header as a
  fallback (some themes have custom dir names that diverge from
  their wp.org slug).
- `loadThemeUpgraderClasses()` — pulls in `Theme_Upgrader` +
  `Automatic_Upgrader_Skin` for REST/cron contexts where they
  don't autoload.

### Changed

- `Installer::installOne()` no longer returns "Unsupported type
  'theme'" — accepts `type: "plugin" | "theme"`, dispatches to
  the right path. `type: "core"` and any other value still
  returns the unsupported-type failure.

### Out of scope (deferred)

- Pre-update snapshot + post-upgrade smoke check for themes —
  Sprint 4's BackupManager.snapshot() currently zips a plugin
  folder; theme snapshots need a parallel BackupManager method
  that knows about parent/child theme dependencies.
- `/theme-switch` REST route — the post-install "Activate now"
  button in the dashboard's progress modal stays hidden for
  themes in this release. Activation happens inline at install
  time via the `active` flag; switching an already-installed
  theme is operator-driven (WP admin) for now.

## [0.15.0] — 2026-05-10

Adds `connector_version` to the heartbeat + inventory payloads.
Lets the dashboard detect sites running outdated connectors and
surface a "Connector update available" banner on /sites/{id}
with one-click guidance — closes the "I shipped /plugin-toggle
in v0.14 but the operator doesn't know they're stuck on v0.13"
discovery gap.

### Added

- `Heartbeat\Scheduler::buildPayload()` now includes
  `connector_version` (from the `DECKWP_CONNECT_VERSION` constant).
- `REST\Routes\InventoryRoute::handle()` mirrors the same field
  so the on-demand /inventory pull (dashboard's "Refresh now"
  button) carries the version too.
- Heartbeat docblock updated to reflect the new field.

No-op for paired sites until the dashboard's next deploy starts
reading the field. Existing payload consumers ignore unknown
fields, so this is a pure addition with no backwards-compat risk.

## [0.14.0] — 2026-05-09

Adds remote activation. The Library install flow can now activate
a plugin in the same dispatch as the install (via `active: true`
on the `/install-batch` item), and a new `/plugin-toggle` route
lets the dashboard activate/deactivate independently — powering the
Library install-progress modal's "Activate now" button on succeeded
rows where the operator left the install-time checkbox unchecked.

### Added

- `POST /wp-json/deckwp/v1/plugin-toggle` — accepts
  `{ slug: string, active: bool }`. Activates via `activate_plugin()`
  or deactivates via `deactivate_plugins()`, surfacing any WP_Error
  from the activation hook verbatim. Idempotent (toggling to the
  current state is a no-op success). Re-reads `is_plugin_active()`
  after the call to detect activation hooks that silently refuse to
  flip the state.

- `Installer::installOne()` reads a new `active` flag on each
  `/install-batch` item. When set, `runFreshInstall()` runs
  `activate_plugin()` on the freshly-installed plugin and reports
  `active` + `activation_error` fields back in the per-item row.
  Activation failure does NOT unwind the install — the bytes are
  on disk, the operator just gets a clear "activation hook errored"
  message and can fix it from WP admin. The flag is upgrade-path
  no-op (preserving Plugin_Upgrader::upgrade()'s existing behavior).

### Changed

- `Server.php` registers the new route and updates the route inventory
  in its class-level docblock; `plugin-action` from the v0.4
  "Planned" section moves to v0.14 (Active), `plugin-delete`
  takes its place on the planned list.

## [0.13.0] — 2026-05-09

Adds the fresh-install path for `/install-batch`. Up to v0.12.2 the
endpoint was upgrade-only — `Installer::installOne()` returned
`Plugin not installed on this WordPress install.` for any slug not
already present on disk. The dashboard's new Library page (DeckWP
v0.5+) needs to install plugins the operator picked from the wp.org
directory but doesn't have on the WP install yet, which the
upgrade-only path can't service.

### Added

- `Installer::runFreshInstall($slug, $downloadUrl)` — when the slug
  isn't already on disk, resolve the wp.org `download_link` via
  `plugins_api('plugin_information')` (or use the `download_url`
  from the request body if the dashboard sent one for premium
  catalog plugins), then call `Plugin_Upgrader::install()`. The
  newly-installed plugin is left INACTIVE — operator activates via
  WP admin or the dashboard's plugin-row toggle.

- Verification step that calls `findPluginFile($slug)` AFTER the
  install. If `Plugin_Upgrader::install()` returns true but the
  zip extracted to a folder name that doesn't match the slug
  (rare, but happens with some plugins whose package extracts
  with a `-pro` suffix), the response surfaces a clear failure
  instead of pretending success.

### Changed

- `installOne()` branches on `findPluginFile($slug)`: if found, the
  existing upgrade path runs (snapshot → upgrade → smoke check →
  optional rollback); if not found, the new fresh-install path
  takes over (no snapshot, no smoke — neither makes sense for a
  brand-new install).

- `Installer.php` class-level docblock updated to mention the new
  "fresh install" item shape (no input changes — just `download_url`
  becomes optional and the WP install no longer needs the slug
  pre-installed).

## [0.12.2] — 2026-05-08

Hotfix. The dashboard's "outdated plugins" badge + per-row Update
buttons stayed permanently empty for sites where wp-cron isn't
firing reliably (DISABLE_WP_CRON=true without an external cron is
the common cause), and got falsely emptied for sites with any
managed slugs configured. Both stem from `PluginInventory`
returning all-null `new_version` fields.

### Fixed

- `Inventory\PluginInventory::updatePayload()` now calls
  `wp_update_plugins()` before reading the `update_plugins`
  site transient. wp_update_plugins is a no-op when the cached
  data is < 1h old; when it's stale (or missing entirely on a
  fresh install before WP cron's first 12h tick), it forces a
  fresh wp.org poll and re-populates the transient. Without this
  call, sites with broken/disabled wp-cron showed every plugin as
  "up to date" forever, because the transient never refreshed.

- `PluginInventory::updatePayload()` also now sets
  `DECKWP_CONNECT_ALLOW_MANAGED_UPDATES` before reading the
  transient, bypassing `Updater\UpdateSuppressor::filterTransient()`
  for the duration of the read. UpdateSuppressor's job is to hide
  the WP admin "Update available" badge for plugins DeckWP
  manages remotely (so operators don't double-press Update from
  WP admin). It was also stripping those entries from the
  inventory snapshot we send to the dashboard — so the dashboard
  saw `new_version: null` for managed plugins and never offered
  the per-row Update button. Same constant the install flow
  already uses for the same reason.

- The fix combo turns the previously-empty "outdated" header
  badge into ":N outdated" the next time the dashboard pulls
  inventory (Atualizar button) or receives a heartbeat.

## [0.12.1] — 2026-05-07

Hotfix release. Two bugs caught by the post-v0.12.0 real-world
SelfUpdater smoke (against the public GH Release that v0.12.0
just shipped). Pest tests against the dashboard endpoint passed
6/6 with `Http::fake()`, but the connector's own polling code
path is not exercised by those tests — the bugs only manifested
when an actual paired site reached out to the dashboard. Without
this hotfix, any v0.12.0 install would silently fail to ever
discover an update offer (the SelfUpdater filter would throw
during `wp_update_plugins()` — caught by the fatal handler
drop-in but never inject the update entry).

### Fixed

- `Updater\SelfUpdater::pollDashboard()` called
  `$this->settings->get()` with no arguments. `Settings::get` is
  the single-key reader (`get(string $key, $default)`); the right
  call for the full settings array is `Settings::all()`. Switched
  over.

  Symptom under v0.12.0: an `ArgumentCountError` thrown the first
  time WP refreshed the `update_plugins` site transient — caught
  by the connector's drop-in fatal handler before reaching the
  user, but the SelfUpdater filter never completed and the update
  offer never got injected.

- `Updater\SelfUpdater::getLocalPluginVersion()` was declared
  `private`. PHP `private` methods aren't subclass-overridable —
  late static binding doesn't apply, so a smoke harness that
  subclasses SelfUpdater (to simulate `version=0.11.0` for testing
  the upgrade flow without a real version downgrade) had its
  override silently ignored. Promoted to `protected` with an
  explicit docblock note.

  No production impact; this only affected our test harness.
  Bumped to ship together with the other fix because they were
  caught in the same smoke session and the diff is one line each.

### Compatibility

- WordPress 5.6+, PHP 7.4+
- No breaking changes from v0.12.0 — this is a pure bugfix.
- Pre-v0.12.0 dashboards never call SelfUpdater's poll endpoint;
  this fix is irrelevant to them.

### Smoke

After fixes, end-to-end real-world chain validated against the
test site (deckwp-test-wp.test):

  1. Connector → dashboard `GET /api/v1/sites/{site}/connector/latest`
     HMAC-signed → HTTP 200 with payload (version, download_url,
     tested_wp, requires_php, changelog_url, published_at).
  2. `fetchLatestEnvelope()` populates the 1h positive cache.
  3. Subclass-mocked `getLocalPluginVersion → '0.11.0'` returns,
     forcing `version_compare('0.12.0', '0.11.0', '<=')` to false.
  4. `injectUpdateOffer` populates
     `$transient->response['deckwp-connect/deckwp-connect.php']`
     with new_version + package URL pointing at the public GH
     Release zip asset.

The download_url itself was independently smoke-tested against
the public GH zip URL (HTTP 200, 115281 bytes, matching SHA256).

## [0.12.0] — 2026-05-07

Headline release of the DeckWP rollout. Ships the multisite-aware
fatal handler (KILLER #1, all 5 slices), the UpdateSuppressor +
/set-managed-slugs flank-protection feature, the Whitelabel branding
that closes FREE-tier viability, the heartbeat extension that ships
the fatal log to the dashboard, and the SelfUpdater that destrava
distribuição massiva.

Backwards-compat: every change is additive on the wire (new optional
fields on the heartbeat payload, new HMAC routes, new filter hooks).
Pre-0.12 dashboards keep working.

### Added

- `DropIn\Installer` + bundled `dropin/handler-source.php` — Slice 1
  of the multisite-aware fatal-handler rollout. Idempotently writes
  `wp-content/fatal-error-handler.php` from a managed source on every
  `plugins_loaded`. Foreign drop-ins (host-installed, third-party
  plugin) are detected via marker grep (`DECKWP_FATAL_HANDLER_MARKER`)
  and **never** overwritten.

  Classification logic (`absent` / `ours` / `foreign`) is text-only —
  we deliberately do NOT `require` the foreign file to inspect its
  constants, since a malformed third-party drop-in could crash the
  connector during boot, which is the exact failure mode this feature
  exists to fix.

  Wired into `Bootstrap::run()` after the existing subsystems.
  Install failures are logged to `error_log()` only — the connector
  keeps booting normally so the rest of the plugin still functions
  on hosts where wp-content/ is unwritable (rare, but possible on
  some shared hosts with chrooted FS).

  Slice 1 was install plumbing only — `handle()` delegated to
  `parent::handle()`. Slice 2 (next bullet) brings the actual
  single-site detection + auto-deactivate. Slices 3 (multisite,
  the Manage GPL gap) and 4 (memory-exhaustion branch + branded
  503 splash) still pending.

- **Single-site fatal detection + auto-deactivate** — Slice 2 of the
  rollout. `DeckWP_Fatal_Error_Handler::handle()` now identifies the
  active plugin whose directory contains the fatal-trigger file
  (longest-prefix match against `get_option('active_plugins')`),
  removes it from the option (live deactivate), and appends a
  structured entry to the `deckwp_fatal_log` option (capped at 50,
  stored with `autoload=false`):

  ```jsonc
  {
    "ts": 1717684800,
    "type": 1,                                          // E_ERROR etc.
    "file": "/var/www/.../wp-content/plugins/buggy/buggy.php",
    "line": 42,
    "message": "Call to undefined function ...",        // truncated at 1024 bytes
    "plugin_path": "buggy/buggy.php",                   // null if outside any active plugin
    "deactivated": true                                 // false if not in active_plugins or update_option refused
  }
  ```

  Both standard `slug/main.php` and standalone single-file plugins
  (Hello-Dolly pattern) are matched. Errors outside `WP_PLUGIN_DIR`
  (theme code, mu-plugins, core) log without `plugin_path` so the
  dashboard sees the trace but the drop-in doesn't auto-deactivate
  something it can't attribute.

  **Multisite networks fall through to `parent::handle()` unchanged**
  — `is_multisite()` early-out. Slice 3 lands the `switch_to_blog`
  loop. Memory exhaustion is currently treated as a regular E_ERROR;
  Slice 4 splits it out with a dedicated branch + branded 503 splash.

  Defense in depth: the entire detection block sits inside a
  `try/catch (\Throwable)`. A bug in our bookkeeping must NEVER
  prevent `parent::handle()` from rendering the user-facing recovery
  page — failures degrade silently to `error_log()`.

  Drop-in version bumped to `0.12.0-slice2`. The Installer's
  byte-equal compare detects the changed source on the next
  `plugins_loaded` and rewrites `wp-content/fatal-error-handler.php`
  in place — operators don't need to reinstall the plugin to pick
  up the new handler.

- **Multisite fatal detection** — Slice 3 of the rollout. Closes the
  Manage GPL gap on the comparison table ("Multisite fatal handler ·
  ✓ full" vs Manage GPL "× skipped"). `handle()` now branches on
  `is_multisite()`; the multisite path runs a three-tier search:

  1. **Network-active plugins** (`active_sitewide_plugins`) — the
     most common multisite shape, single registry shared by every
     blog. Match here deactivates network-wide via `update_site_option`.
  2. **Current blog** — `get_current_blog_id()` is the most likely
     culprit when the network registry didn't match.
  3. **switch_to_blog loop** across every other blog (`get_sites
     (['fields' => 'ids', 'number' => 0])`). First match wins.

  First match on any tier deactivates and logs the entry with
  `scope` set to `network` / `blog` (with `blog_id`) so the dashboard
  can render "deactivated *fee-flux* on blog #7" rather than just
  "deactivated *fee-flux*".

  Single-site path is unchanged in behavior; the scope field reads
  `single`, no `blog_id`. Refactored single-site to share helpers
  (`deckwpRelativePluginPath`, `deckwpLongestPrefixMatch`,
  `deckwpNormalizePath`) with the multisite path so the prefix-match
  algorithm only lives in one place.

  **Log storage moved to `get_site_option` / `update_site_option`.**
  In multisite, this stores the log in `wp_sitemeta` (network-wide)
  so the dashboard pulls a single source of truth regardless of
  which blog tripped the fatal. On single-site, `update_site_option`
  is equivalent to `update_option` — no behavior change for non-MU
  operators, no migration needed for the Slice 2 data shape (the
  new `scope` field is additive; old entries without it default to
  `single` semantically).

  Drop-in version bumped to `0.12.0-slice3`. Same auto-rewrite
  mechanism as Slice 2 — `plugins_loaded` runs the Installer, byte
  diff triggers the upgrade.

- **Memory-exhaustion detection + branded 503 splash** — Slice 4 of
  the rollout. Two complementary additions:

  **OOM detection**: `handle()` now flags log entries whose `message`
  matches `Allowed memory size of <N> bytes exhausted` or `Out of
  memory (allocated <N>)` with `oom: true`. The dashboard can render
  these differently from regular E_ERRORs (memory tuning hint vs.
  plugin bug ticket). Detection is one strpos() per fatal — cheap.

  **Branded splash**: when the handler successfully *both* identified
  a culprit *and* deactivated it, render a self-contained 503 page
  in place of WP's default "experiencing technical difficulties"
  template. The page tells the visitor what happened in plain
  English, surfaces the slug that was disabled (and `(blog #N)` on
  multisite), and offers a "Refresh page" button. Headers:

  ```
  HTTP/1.1 503 Service Unavailable
  Retry-After: 5
  X-Robots-Tag: noindex, nofollow
  Content-Type: text/html; charset=UTF-8
  Cache-Control: no-cache, no-store, must-revalidate (via nocache_headers)
  ```

  Self-contained: inline CSS, inline SVG, no asset URL resolution,
  no theme dependency. Mirrors the `MaintenanceGuard` pattern so
  the page renders even when the rest of WP is half-broken.

  When we *couldn't* identify a culprit (error file outside
  `WP_PLUGIN_DIR` — theme code, mu-plugins, core), or when we
  identified one but `update_option` refused the deactivate (rare;
  DB-side fatal scenarios), the drop-in delegates to
  `parent::handle()` so the operator still gets the WP recovery
  flow with the trace and recovery email. We don't lie with a
  "everything is fine, refresh" splash when we haven't actually
  fixed anything.

  Refactor: `deckwpHandleSingleSite` and `deckwpHandleMultisite`
  now *return* the log entry they appended, so `handle()` can decide
  whether to render the splash. Same code paths as Slice 3, just
  routed through a return value.

  Drop-in version bumped to `0.12.0-slice4`. Auto-rewrite triggered
  on next `plugins_loaded` via byte-equal diff.

- `REST\Routes\BackupCreateRoute` — `POST /wp-json/deckwp/v1/backup-create`.
  HMAC-protected. Operator-initiated, off-cycle plugin folder snapshot
  (vs. the install-batch path that snapshots only when `backup_required:
  true` rides on an upgrade item). Body shape:

  ```jsonc
  { "slug": "formidable-pro" }
  // optional: "type": "plugin" (default; reserved for future themes)
  ```

  Response 200:

  ```jsonc
  { "ok": true,
    "backup": {
      "local_path":    "deckwp-backups/formidable-pro-2026-05-07T13-50-00-Ax9dfe.zip",
      "absolute_path": "/var/www/.../uploads/deckwp-backups/...zip",
      "checksum":      "sha256-hex",
      "size_bytes":    524288
    }
  }
  ```

  Validation-shaped failures map to 422 (`invalid_slug`,
  `plugin_not_found`, `plugin_too_large`, `path_escape`,
  `unsupported_type`); unexpected filesystem failures map to 500.

  Closes Slice 5 of the KILLER #1 fatal-handler rollout.

### Slice 5 — UltraHub off-site upload (after-hook only)

After a successful snapshot, `BackupCreateRoute::handle()` fires:

```php
do_action('deckwp_connect_backup_created', $slug, $backup, 'route');
```

`$backup` is the response payload's `backup` sub-key; `$context` is
`'route'` so a future listener can tell route-driven backups apart
from install-batch ones.

**No subsystem subscribes to this hook in the current connector.**
The reservation is for the planned UltraHub off-site upload
integration (`Integrations\UltraHubBackupSync` or similar). UltraHub
itself doesn't expose its outbound API yet; once that lands on the
UltraPack side, the subscriber subsystem will live in this connector
and push the zip to remote object storage. Wire-protocol contract,
auth scheme, and idempotency strategy will be defined alongside
that subsystem — outside the scope of this connector release.

- `Updater\UpdateSuppressor` + `REST\Routes\SetManagedSlugsRoute` —
  closes the flank where an operator clicking Update on the WP admin
  plugins screen would bypass DeckWP's pre-update backup + smoke +
  auto-rollback flow (`install-batch` route + Sprint 4 wiring).
  Without this, the dashboard's careful orchestration is one click
  away from being defeated; with it, the "Update available" notice
  simply doesn't appear for plugins / themes the dashboard owns.

  ### Wire shape

  `POST /wp-json/deckwp/v1/set-managed-slugs` (HMAC-protected):

  ```jsonc
  { "plugins": ["formidable-pro/formidable-pro.php", "wp-rocket"],
    "themes":  ["avada"] }
  ```

  Response 200: `{ "ok": true, "stored": { "plugins": 2, "themes": 1 } }`.

  Plugin entries can be the WP plugin_path (`slug/main.php`) OR
  just the folder slug (`slug`) — the suppressor matches both
  shapes so the dashboard isn't forced to learn the main file
  name. Theme entries are folder slugs only.

  Empty arrays clear the list (`{"plugins":[],"themes":[]}`).
  Empty *body* (no keys at all) returns 422 `invalid_input` —
  sending nothing is suspicious (typo / wrong route) rather than
  intent to clear.

  ### Suppression mechanics

  Filters `site_transient_update_plugins` / `site_transient_update_themes`
  at priority 9999 (after most third-party filters). Removes managed
  entries from `response` so:

  - The "Update available" pill in the plugins list disappears.
  - The row-actions Update link disappears.
  - Bulk-action checkboxes for those rows can't trigger an update.
  - Auto-update toggles disappear.

  `no_update` and `checked` are deliberately left alone — they're
  read by other update flows (heartbeat, scheduling) and stripping
  them would have surprising knock-ons.

  ### Bypass for the dashboard's own update flow

  `Install\Installer` calls `wp_update_plugins()` to refresh the
  transient before running `Plugin_Upgrader::upgrade`. That refresh
  fires this filter — and would strip the very entry we're about to
  upgrade. Define `DECKWP_CONNECT_ALLOW_MANAGED_UPDATES = true`
  before the upgrader runs and the filter returns the transient
  untouched. (Wiring in `Install\Installer` lands alongside the
  next install-batch hardening pass.)

  ### Storage

  `deckwp_managed_slugs` site option (`update_site_option`).
  Network-wide on multisite, equivalent to wp_options on single-site.
  No per-blog list — the dashboard sends one canonical state per
  site / network on every call.

  ### Smoke test

  Internal-dispatch coverage of 8 cases — all pass:
  - `/set-managed-slugs` happy path (plugins + themes round-trip).
  - Empty body → 422 `invalid_input`.
  - Both keys empty → 200 (clear-all intent).
  - Plugin filter matches plugin_path entries (formidable-pro/...).
  - Plugin filter matches folder-slug entries (wp-rocket).
  - Theme filter matches folder slug (avada).
  - `no_update` left untouched after plugin filter ran.
  - Bypass constant keeps everything in the response.

- `Whitelabel\Branding` + `REST\Routes\WhitelabelRoute` — competitive
  parity feature. Manage GPL ships it in every plan, ManageWP charges
  for it, Zeebrar lists it as "coming soon"; without whitelabel, the
  DeckWP FREE tier isn't vendable. The dashboard collects rebrand
  config (plugin renames, hide-from-list, custom URLs) and pushes it
  via `POST /wp-json/deckwp/v1/whitelabel`; the connector hooks
  `all_plugins` at priority 9999 and rewrites the metadata WP renders
  on the admin Plugins page.

  ### Wire shape

  ```jsonc
  POST /wp-json/deckwp/v1/whitelabel
  {
    "plugins": {
      "akismet/akismet.php": {
        "name":        "Spam Shield",
        "description": "Stops spam comments. Powered by DeckWP.",
        "author":      "DeckWP",
        "author_uri":  "https://deckwp.com",
        "plugin_uri":  "https://deckwp.com/spam-shield",
        "hide":        false
      },
      "wp-rocket/wp-rocket.php": { "hide": true }
    },
    "themes": {}
  }
  ```

  Response: `{ "ok": true, "stored": { "plugins": 2, "themes": 0 } }`.

  ### Sanitization

  Strings stored verbatim (trusted-dashboard input; WP admin
  templates auto-escape via `esc_html`/`esc_url` at render). Unknown
  keys dropped, non-string values for known string keys dropped,
  empty paths dropped, and entries that contributed nothing to the
  override (after sanitization) skipped to avoid storage bloat.

  ### Themes deferred to v2

  The wire shape carries a `themes` key for forward-compat, but v1
  always sanitizes it to `[]`. Theme rebrand has a different filter
  surface (`wp_get_theme`, `themes_api_result`) and lower competitive
  pressure than plugin rebrand — revisit when a customer asks.

  ### Why PUSH not PULL

  The original ROADMAP listed a `GET /api/v1/whitelabel?site_id=X`
  pull endpoint on the dashboard. PUSH is simpler: real-time updates,
  no connector cron to maintain, symmetry with the existing
  `/maintenance` and `/set-managed-slugs` push routes.

  ### Storage

  `deckwp_whitelabel_config` site option (`update_site_option` —
  network-wide on multisite, equivalent to wp_options on single-site).

  ### Smoke (8/8 pass)

  Internal-dispatch coverage:
  - Happy path (3 plugins stored after sanitization) ✓
  - Empty body → 200 + cleared option ✓
  - Unknown / non-string fields dropped from sanitization ✓
  - Empty plugin path dropped ✓
  - Akismet rewrite of Name / Title / Description / Author /
    AuthorName / AuthorURI / PluginURI ✓
  - Version field NOT touched (we only override declared keys) ✓
  - wp-rocket fully removed from the filtered array (hide: true) ✓
  - Plugin without an override passes through unchanged ✓
  - Empty option = no-op (filter returns array unchanged) ✓

- `Install\Installer::install()` — defines
  `DECKWP_CONNECT_ALLOW_MANAGED_UPDATES = true` before refreshing the
  `update_plugins` transient and calling `Plugin_Upgrader::upgrade`.
  Closes the loop on the UpdateSuppressor: without this bypass, the
  `wp_update_plugins()` refresh inside `install()` would fire the
  suppressor's filter, strip the very entries we're about to upgrade,
  and the upgrader would no-op ("nothing to update"). The constant
  is request-scoped; the `/install-batch` HTTP request is one of
  ours (never an admin browse), so the side-effect is contained.

  Smoke (3/3 pass): set `formidable` as managed → confirm
  `apply_filters('site_transient_update_plugins', ...)` strips it →
  call `Installer::install([])` → confirm constant defined → re-apply
  filter → `formidable` now passes through.

- `Updater\SelfUpdater` — connector self-update via WordPress'
  built-in upgrade flow. Closes Next #5 of the ROADMAP.

  Without this, shipping v0.13.0 means every operator manually
  downloads the zip and re-installs on every site. With this, the
  connector polls the dashboard's
  `GET /api/v1/sites/{site}/connector/latest` (HMAC-signed) on
  each `update_plugins` transient refresh; if the dashboard
  reports a newer version, our filter injects the offer into the
  transient and the operator clicks Update on the WP admin
  Plugins page like any other plugin.

  Cache: 1h positive (transient `deckwp_connect_self_update_check`),
  5min negative (transient `deckwp_connect_self_update_failed`).
  Even hundreds of sites poll-storming at once won't multiply the
  GitHub rate limit pressure on the dashboard side — the dashboard's
  ConnectorReleaseFetcher caches its GitHub poll for 1h too.

  Wire shape (response body the dashboard sends):

  ```jsonc
  { "version":      "0.13.0",
    "download_url": "https://github.com/.../deckwp-connect.zip",
    "tested_wp":    "6.6",
    "requires_php": "7.4",
    "changelog_url":"https://github.com/.../releases/tag/v0.13.0",
    "published_at": "2026-05-08T10:00:00Z" }
  ```

  When `download_url` is empty (release with no zip asset), or the
  dashboard returns 503, the filter passes through unchanged —
  better silent no-op than a broken upgrade attempt.

  Wired in Bootstrap. No connector-side smoke yet (would require
  cutting v0.13.0 release on the public repo; vai como part of
  the actual release ritual).

- `Heartbeat\Scheduler::buildPayload()` — heartbeat now ships the
  drop-in's `deckwp_fatal_log` site option as a `fatal_log` array
  in the payload. The dashboard's `HeartbeatProcessor` de-duplicates
  entries by `ts` against its `sites.last_fatal_seen_ts` watermark
  and dispatches `FatalHandlerTrippedNotification` for new ones.

  We deliberately do NOT clear the log on the connector side after
  sending: the dashboard's watermark IS the dedupe; clearing locally
  would lose entries on transport failures. The drop-in's 50-entry
  cap is the size guard.

  Wire shape extension is additive — old dashboards that don't read
  the `fatal_log` key just ignore it. New dashboards that haven't
  received any fatal events yet see an empty array. No connector
  version bump required for the receiver side.

### Compatibility

- WordPress 5.2+ (when WP introduced `wp_register_fatal_error_handler`).
  On pre-5.2 the source file's `class_exists('WP_Fatal_Error_Handler')`
  guard requires the core class explicitly.
- PHP 7.4+, no new dependencies.
- Pre-0.12.0 dashboards continue working — every wire change in
  this release is additive (new optional `fatal_log` field on the
  heartbeat, new HMAC routes that pre-0.12 dashboards simply
  don't call, new filter hooks). No breaking changes from 0.11.0.

### Headline summary (the 5 features this release ships)

  1. **Multisite-aware fatal handler** (KILLER #1) — drop-in at
     `wp-content/fatal-error-handler.php` that auto-deactivates
     a plugin when it crashes. Single-site detection (longest-prefix
     match against `active_plugins`), multisite three-tier search
     (network-active → current blog → switch_to_blog loop), OOM
     detection with `oom: true` flag in the log, and a branded 503
     splash that replaces WP's generic "experiencing technical
     difficulties" page when we identify-and-deactivate a culprit.
  2. **UpdateSuppressor + /set-managed-slugs** — hides "Update
     available" notices in WP admin for plugins under DeckWP
     management, so an operator can't bypass the dashboard's
     pre-update backup + smoke flow by clicking Update on the
     Plugins page directly. `Install\Installer::install` defines
     a bypass constant so the dashboard's own update flow keeps
     working.
  3. **Whitelabel\Branding + /whitelabel** — rewrites plugin
     metadata in the WP admin (Name, Description, Author, etc.)
     based on a config the dashboard pushes. Closes the FREE-tier
     viability flank — Manage GPL ships whitelabel in every plan,
     ManageWP paywalls it.
  4. **Heartbeat carries fatal log** — `Heartbeat\Scheduler::buildPayload`
     ships the drop-in's `deckwp_fatal_log` so the dashboard can
     fire `FatalHandlerTrippedNotification` for each new entry,
     deduped by `ts` watermark.
  5. **`Updater\SelfUpdater`** — connector polls the dashboard's
     `/api/v1/sites/{site}/connector/latest` endpoint on every
     `update_plugins` transient refresh and offers the new
     version through WP's built-in upgrade flow. Operator clicks
     Update on the Plugins page, no per-site redeploy.

## [0.11.0] — 2026-05-06

### Added

- `Maintenance\MaintenanceManager` — enable/disable + state
  for the dashboard-driven maintenance toggle. State lives in a
  JSON lock file at `wp-content/uploads/deckwp-maintenance.lock`
  (deliberately NOT wp-options, so the page is reachable even
  with a corrupt DB). Auto-expires when `ends_at` passes; 24h
  hard ceiling per enable to dodge "operator forgot to flip it
  back" failures.

- `Maintenance\MaintenanceGuard` — `init` priority 1 hook that
  reads the lock file and short-circuits non-bypass requests
  with a 503 + branded HTML page. Bypass list:
  - REST API (`/wp-json/*` and `?rest_route=`) — the dashboard
    MUST keep talking to the connector during maintenance,
    including the `/maintenance` toggle itself.
  - WP admin pages — operators with `manage_options` keep
    working on the site while the public face is down.
  - WP CLI + cron.
  - `/wp-login.php` so operators can authenticate during a
    long maintenance window.

  Page styling is inline CSS, no theme dependency, no plugin
  asset URL resolution — the whole 503 must render even when
  the rest of WP is half-broken. `Retry-After` header set from
  `ends_at` so well-behaved bots back off; `noindex` to keep
  search engines from caching the holding page.

- `REST\Routes\MaintenanceRoute` — `GET` returns current state,
  `POST` flips on/off:
  ```jsonc
  POST /wp-json/deckwp/v1/maintenance
  { "enabled": true, "minutes": 30, "message": "Be back soon", "started_by": "ops@example.com" }
  ```
  Both HMAC-protected like every other route. 422 on duration
  out of range (1..1440 minutes).

### Wiring

`Bootstrap` now adds `MaintenanceGuard::maybeIntercept()` on
the `init` action at priority 1 so we short-circuit before any
plugin/theme starts frontend work.

### Compatibility

- WordPress 5.6+, PHP 7.4+
- No breaking changes from v0.10.0
- Pre-v0.11.0 dashboards never call `/maintenance`; the lock
  file stays absent and the guard always passes through.

### Smoke-tested

End-to-end against the dev test site:
- Frontend pre-toggle → HTTP 200.
- Toggle on via `RemoteMaintenanceTrigger` (5 minutes,
  custom message) → frontend HTTP 503 with the branded page,
  custom message rendered, `Retry-After: 289` header set;
  REST API still HTTP 200.
- Toggle off → frontend HTTP 200 again.

## [0.10.0] — 2026-05-06

### Added

- `REST\Routes\SsoLoginRoute` — `GET /wp-json/deckwp/v1/sso-login`.
  Consumes a one-time SSO login token from a URL query parameter
  and logs the operator in as a WordPress administrator without
  ever showing them the password.

  Powers the dashboard's "Open wp-admin" button. Browser navigates
  to the URL → connector validates the token → 302 redirect to
  `/wp-admin/` with WP session cookies set as if the operator had
  done a normal login (14-day TTL).

### Why GET (not POST + HMAC headers)

This is the one route NOT protected by the X-DeckWP-* HMAC
headers. The browser navigates directly to the URL via
window.open(); browsers don't carry custom request headers when
following navigation. The token IS the signed credential, in
the URL.

Token format: `<unix_ts>.<jti>.<sig>` where `sig` is
HMAC-SHA256(`<ts>.<jti>`, hmac_secret). Validated in three layers:

1. Format + shape (3 parts, `ts` numeric, `jti` ≥16 chars,
   `sig` 64 hex chars) — fails fast on garbage.
2. Timestamp within 60s of `time()` — short window for any
   stolen URL.
3. HMAC matches stored `hmac_secret` — only the dashboard
   that pairs with this site can mint valid tokens.

### Anti-replay

Consumed `jti`s are stashed in a 5-minute transient. Second
use of the same jti returns 401 `token_replayed`. The dashboard
side enforces `jti` UNIQUE on `sso_sessions.jti` independently
— defense in depth across both layers.

### Login user resolution

Default: first user with role `administrator`, ordered by ID.
Configurable via the `deckwp_sso_login_user_id` filter:

```php
add_filter('deckwp_sso_login_user_id', function ($user_id) {
    return get_user_by('login', 'deckwp-audit')->ID;
});
```

### Compatibility

- WordPress 5.6+, PHP 7.4+
- No breaking changes from v0.9.0
- Sites without an existing administrator user → 401
  `no_admin_user`. (Pathological case; every WP install has at
  least one admin.)

### Smoke-tested

End-to-end against the dev test site:
- Mint via `SsoTokenMinter` → curl the resulting URL → 302 to
  /wp-admin/ with `wordpress_logged_in_*` cookies set.
- Replay the same URL → 401 `token_replayed`.
- Malformed token → 401 `malformed_token`.
- Backdated `ts` → 401 `token_expired`.

## [0.9.0] — 2026-05-06

### Added

- `REST\Routes\InventoryRoute` — `POST /wp-json/deckwp/v1/inventory`.
  HMAC-protected. Returns the same payload shape as the cron-driven
  heartbeat, on demand. Powers the dashboard's "Refresh now" button:
  the operator just installed/deleted/activated a plugin in WP admin
  and doesn't want to wait up to 5 minutes for the next heartbeat
  tick to reconcile the inventory.

  Response payload:

  ```jsonc
  {
    "event":        "inventory",
    "sent_at":      1717684800,
    "wp_version":   "6.6.2",
    "php_version":  "8.3.10",
    "site_url":     "https://example.com",
    "is_multisite": false,
    "plugins":      [
      { "slug": "akismet", "name": "Akismet", "version": "5.6", "active": true },
      ...
    ]
  }
  ```

  The `event` field reads "inventory" rather than "heartbeat" for
  log readability. The dashboard's HeartbeatProcessor doesn't
  inspect the field — both push (cron) and pull (this route)
  paths share the merge logic on the dashboard side.

### Compatibility

- WordPress 5.6+, PHP 7.4+, ZipArchive
- No breaking changes from v0.8.1
- Pre-v0.9.0 dashboards never call /inventory; the route is
  ignored when not in use.

## [0.8.1] — 2026-05-06

### Fixed

- `Backup\BackupManager::snapshot()` now handles single-file
  plugins (the Hello Dolly pattern: `wp-content/plugins/hello.php`
  with no `hello/` folder). Previously snapshot returned
  `plugin_not_found`, blocking any update flow that set
  `backup_required: true` on single-file slugs. The dashboard's
  bulk Update-all action exposed this in real-world use — every
  Hello-Dolly-shaped plugin would Fail with the snapshot error
  before the upgrade itself could even attempt.

  Snapshot now zips `hello.php` as `hello/hello.php` inside the
  zip so the restore-side layout is uniform with folder plugins.
  Restore detects the single-file pattern (zip with exactly one
  `<slug>/<slug>.php` entry) and uses a file-level move-aside /
  move-new / rollback dance instead of the folder-level one.
  Both layouts share the path-escape and atomicity guards.

- `Smoke\PostUpdateChecker::verify()` now treats either a folder
  OR a single-file plugin file as "still on disk". Same
  motivation: verify() was false-positive-failing post-upgrade
  for single-file plugins because it only checked is_dir().

### Compatibility

- WordPress 5.6+, PHP 7.4+, ZipArchive
- No breaking changes from v0.8.0
- Pre-v0.8.1 zips that captured folder plugins continue to
  restore correctly — the single-file detection is opt-in based
  on the actual zip contents, not metadata.

### Smoke-tested

End-to-end against Hello Dolly on the dev test site: snapshot
produced a 1.4 KB zip with one entry `hello/hello.php`; live
file overwritten with a marker; restore put the v1.7.2 file
back byte-for-byte (sha matched the original).

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
