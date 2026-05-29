# DeckWP Connect

> The WordPress connector that pairs a site with the
> [DeckWP](https://deckwp.com) dashboard over an HMAC-authenticated REST API.

[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)
[![Requires WordPress](https://img.shields.io/badge/WordPress-5.2+-blue)](https://wordpress.org)
[![Requires PHP](https://img.shields.io/badge/PHP-7.4+-blue)](https://www.php.net)

DeckWP Connect is the open-source agent half of DeckWP. It runs on the
customer's WordPress site and exposes a small, HMAC-signed REST surface
(`deckwp/v1/*`) that the dashboard at deckwp.com calls to manage the site.

It deliberately does **not** host or distribute plugins/themes, process
payments, track users, or phone home on page loads. It acts only in
response to a signed request from the dashboard — plus an optional
WP-Cron heartbeat and the standard WordPress plugin-update check.

**Project status:** actively developed, currently in the `0.x` series.
Distributed as tagged GitHub Releases (see [Releases & distribution](#releases--distribution)).

## What it does

Once a site is paired with a DeckWP account, the dashboard can:

- **Install / update / activate** plugins and themes — single or bulk
  (`install-batch`, `plugin-toggle`, `theme-switch`, `theme-delete`)
- **Back up before every managed update** and **roll back automatically**
  when a post-update smoke check fails; restore or delete backups on demand
- **Run a local security scan** — PHP files in `uploads/`, obfuscated
  `eval()` backdoor signatures in `plugins/`/`themes/`, world-writable
  `wp-config.php` — and report findings (no external API calls)
- **SSO login** to wp-admin via a short-lived, single-use signed token
- **Collect plugin/theme inventory** plus pending updates (on-demand pull
  and an optional heartbeat push)
- **Run WordPress Site Health** checks and return a flat result envelope
- **Optimize the database** — table/overhead snapshot, targeted cleanup,
  and `OPTIMIZE TABLE` (with a two-layer allowlist against SQL injection)
- **Toggle maintenance mode** with a self-contained, branded 503 page
- **Survive plugin-caused fatals** — a bundled drop-in deactivates the
  offending plugin so the site stays up, and ships the incident log home
- **White-label** the connector — rewrite plugin metadata (Name,
  Description, Author, Author URI, Plugin URI), customize the login and
  admin-bar logos, or hide it from the Plugins list
- **Self-update** — offer the connector's own new versions through the
  native WordPress Plugins screen

## Architecture

The entrypoint (`deckwp-connect.php`) defines constants, registers
activation/deactivation hooks, ensures a pairing token + HMAC secret
exist, and boots the orchestrator on `plugins_loaded`:

```
deckwp-connect.php
        │  plugins_loaded
        ▼
DeckWP\Connect\Bootstrap::boot()
   ├─ Settings\Page              admin UI for the pairing handshake
   ├─ Heartbeat\Scheduler        WP-Cron inventory/fatal-log push
   │                             (gated by DECKWP_CONNECT_ENABLE_HEARTBEAT)
   ├─ Scan\Scheduler             WP-Cron scan sender
   │                             (gated by DECKWP_CONNECT_ENABLE_SCAN)
   ├─ REST\Server                deckwp/v1/* routes (HMAC-protected)
   ├─ Updater\UpdateSuppressor   hides "Update available" for dashboard-
   │                             managed slugs so operators can't bypass
   │                             the pre-update backup + smoke flow
   ├─ Whitelabel\Branding        rewrites/hides plugin metadata in wp-admin
   ├─ Updater\SelfUpdater        pulls the connector's own updates
   ├─ Maintenance\MaintenanceGuard  init-hook 503 interceptor
   └─ DropIn\Installer           installs wp-content/fatal-error-handler.php
```

> `Transport\InitHookFallback` (a non-REST transport for hosts that block
> `/wp-json`) is planned in `CLAUDE.md` but not yet wired.

### REST surface (`deckwp/v1/*`)

Registered in `REST\Server`; every route's `permission_callback` is
`HmacVerifier::verify()` **except the two noted below**:

| Route | Method | Purpose |
| --- | --- | --- |
| `/scan` | POST | Run the local security scan |
| `/install-batch` | POST | Install/upgrade (and optionally activate) plugins/themes |
| `/backup-create` | POST | Snapshot a plugin/theme folder |
| `/restore-backup` | POST | Restore a snapshot |
| `/delete-backup` | POST | Delete an expired snapshot |
| `/inventory` | POST | On-demand plugin/theme inventory |
| `/plugin-toggle` | POST | Activate/deactivate a plugin |
| `/theme-switch` | POST | Activate (switch to) a theme |
| `/theme-delete` | POST | Delete a theme from disk |
| `/maintenance` | GET/POST | Read/toggle maintenance mode |
| `/site-health` | POST | Run WP Site Health and return results |
| `/db-scan`, `/db-cleanup`, `/db-optimize-tables` | POST | Database optimize |
| `/set-managed-slugs` | POST | Tell the connector which slugs the dashboard manages |
| `/whitelabel` | POST | Push branding overrides |
| `/sso-login` | GET | **Not HMAC-header-protected** — the signed one-time token in the URL is the credential |
| `/bootstrap-pairing` | POST | **Not HMAC-protected** — runs before a shared secret exists; falls back to WP cookie auth + nonce + `manage_options` |

## How pairing works

1. The dashboard issues a single-use **pairing token** (48 hex chars,
   valid 15 minutes).
2. The operator pastes it into **Settings → DeckWP Connect** and clicks
   **Connect** (or the dashboard's Automatic Pairing flow pushes it in via
   `/bootstrap-pairing` while authenticated as a wp-admin user).
3. The connector POSTs `{platform}/api/v1/connect/pair` with the token in
   the `X-DeckWP-Pairing-Token` header and basic site metadata.
4. The dashboard returns the durable `hmac_secret` (base64), `site_id`,
   `team_slug`, `callback_url`, and heartbeat/scan intervals, which the
   connector persists in the `deckwp_connect_settings` option.

From then on the dashboard signs each request with the shared secret, and
the connector verifies it. **Disconnect** (Settings page) or deleting the
site in the dashboard revokes access; a dashboard-side revoke is detected
on the next signed call and clears local state automatically.

### HMAC wire format

```
canonical = "{timestamp}.{nonce}.{METHOD}.{path}.{sha256(body)}"
signature = hash_hmac('sha256', canonical, raw_secret_bytes)   // hex
```

Sent as `X-DeckWP-Timestamp`, `X-DeckWP-Nonce`, `X-DeckWP-Signature`.
The secret is stored base64-encoded and decoded to raw bytes before
hashing on both sides.

## Security non-negotiables

These are enforced in code and must not regress (see `CLAUDE.md`):

1. **Every dashboard → site request is HMAC-verified.** The only
   exceptions are `/sso-login` (the signed token in the URL is the
   credential) and `/bootstrap-pairing` (cookie auth + nonce +
   `manage_options`, because no shared secret exists yet).
2. **60-second anti-replay window**, with the method and path bound into
   the signature so a captured request can't be re-pointed at another
   endpoint.
3. **The HMAC secret is rotated on re-pair**, so a previous owner can't
   keep signing requests.
4. **`hash_equals()` for all signature/token comparisons** — never `===`
   on cryptographic material.
5. **`random_bytes()` for token + secret generation** — never `mt_rand`,
   `uniqid`, or `wp_generate_password` for secrets.
6. **`autoload=false` on the settings option**, so the `hmac_secret`
   never enters WordPress' always-loaded option cache.
7. **The fatal-error drop-in never deactivates the connector itself** —
   that would sever the only remote recovery path.

## Compatibility

- WordPress **5.2+**
- PHP **7.4+** (intentionally not 8.0+ — many shared hosts still run 7.4)
- WordPress Multisite supported (settings live in `wp_sitemeta`)
- Tested up to WordPress 6.7

## Releases & distribution

This plugin is **not** published to the wordpress.org directory. All three
channels resolve to the same artifact — the `deckwp-connect.zip` asset
attached to a tagged GitHub Release:

1. **Auto-install** via the DeckWP dashboard (one-click with wp-admin
   credentials)
2. **Manual ZIP download** from [GitHub Releases](https://github.com/ultrapackadm/deckwp-connect/releases)
3. **Self-update** via the DeckWP API, which returns the release's
   `download_url`

Releases are automated by `.github/workflows/release.yml`, which fires on
any `v*` tag push: it lints every PHP file, verifies the tag matches both
the `Version:` header and `DECKWP_CONNECT_VERSION` in `deckwp-connect.php`,
builds the ZIP via `git archive` (honoring `.gitattributes` `export-ignore`
rules), extracts the matching `CHANGELOG.md` section for the release body,
and publishes the release with the ZIP attached. See `CLAUDE.md` for the
full release ritual.

## Local development

Requirements: PHP 7.4+, Composer, and a WordPress 5.2+ install for testing.

```bash
git clone git@github.com:ultrapackadm/deckwp-connect.git
cd deckwp-connect
composer install
# Symlink into your local WP install:
ln -s "$(pwd)" /path/to/wordpress/wp-content/plugins/deckwp-connect
wp plugin activate deckwp-connect
```

Run the test suite and coding-standards check:

```bash
./vendor/bin/phpunit
./vendor/bin/phpcs
```

The connector has **zero production runtime dependencies** — `composer.json`
is dev-only (PHPUnit + WPCS), and a fallback PSR-4 autoloader in the
entrypoint covers class loading when `vendor/` is absent.

## Reporting security issues

Email **security@deckwp.com**. Please do not file public issues for
vulnerabilities. We aim to respond within 24 hours.

## License

GPL-2.0-or-later. See [LICENSE](./LICENSE).

The DeckWP dashboard (proprietary) is a separate codebase and is not
covered by this license.
