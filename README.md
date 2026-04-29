# DeckWP Connect

> WordPress connector plugin that pairs your site with the
> [DeckWP](https://deckwp.com) dashboard.

[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)
[![Requires WordPress](https://img.shields.io/badge/WordPress-5.6+-blue)](https://wordpress.org)
[![Requires PHP](https://img.shields.io/badge/PHP-7.4+-blue)](https://www.php.net)

## What it does

Once paired with a DeckWP account, you can manage this WordPress site from
the dashboard at deckwp.com without logging into wp-admin:

- **One-click updates** for plugins and themes (single, bulk, scheduled)
- **Backup before update + rollback** if anything breaks
- **Plugin scan + auto-fix** (VirusTotal + UltraHub) before install
- **SSO login** to wp-admin (no password re-entry)
- **Maintenance mode** with branded 503 page
- **Health snapshot**: WP version, PHP, SSL, debug mode, pending updates
- **Activity log** per site (30-day retention)
- **Auto-deactivate** plugins that crash with a fatal error
- **Whitelabel** the connector under your agency's brand (every plan)

## Status

🚧 **Pre-release.** First public version targeted for May 2026.

## Installation

### Recommended: install from your DeckWP dashboard

1. Sign up at [deckwp.com](https://deckwp.com) (free for 3 sites)
2. Click **+ Add Site**, paste this site's URL and a wp-admin user
3. DeckWP installs and pairs the connector automatically

### Manual

1. Download the latest release ZIP from the
   [Releases page](https://github.com/ultrapackadm/deckwp-connect/releases)
2. WP admin → **Plugins → Add New → Upload Plugin** → pick the ZIP
3. Activate
4. **Settings → DeckWP Connect** → copy the connection token
5. In your DeckWP dashboard: **+ Add Site → Manual** → paste URL + token

## Privacy & security

- Your **wp-admin password is never stored** — pairing uses a one-time token
- **Every request is HMAC-SHA256 signed** with a per-site secret;
  unsigned/tampered requests are rejected
- **60-second anti-replay window** on every signed request
- **No tracking, no phone-home** — code only runs when DeckWP makes a request
- **Open source** (GPLv2) — read every line before installing
- **Unpair anytime** by clicking "Regenerate token" or deleting the site
  from your DeckWP dashboard

## Local development

Requirements: PHP 7.4+, Composer, WordPress 5.6+ for testing.

```bash
git clone git@github.com:ultrapackadm/deckwp-connect.git
cd deckwp-connect
composer install
# Symlink into your local WP install:
ln -s "$(pwd)" /path/to/wordpress/wp-content/plugins/deckwp-connect
# Activate via WP admin or wp-cli
wp plugin activate deckwp-connect
```

Run tests:

```bash
./vendor/bin/phpunit
```

## Reporting security issues

Email **security@deckwp.com**. Do not file public issues for vulnerabilities.
We respond within 24h.

## License

GPL-2.0-or-later. See [LICENSE](./LICENSE).

The DeckWP dashboard (proprietary) is a separate codebase and not covered
by this license.

