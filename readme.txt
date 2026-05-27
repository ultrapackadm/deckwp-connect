=== DeckWP Connect ===
Contributors: deckwp
Tags: management, updates, multisite, premium, backup, scan, security
Requires at least: 5.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to DeckWP (deckwp.com) — bulk updates with backup & rollback, scan + auto-fix, SSO login, all from one dashboard.

== Description ==

DeckWP Connect pairs this WordPress site with your DeckWP account at https://deckwp.com. Once paired, manage every site in your fleet from one place — no more 50-tab nightmares.

**What you can do from DeckWP after pairing:**

* Update plugins and themes (one-click, bulk, or scheduled)
* Automatic backup before every update — automatic rollback if anything breaks
* Plugin scan with VirusTotal + UltraHub auto-fix (catches AND repairs corrupted packages)
* Toggle maintenance mode with a branded 503 holding page
* Log in to wp-admin with one click (no password re-entry)
* See every plugin and theme installed, and which need updates
* Health visibility: WordPress version, PHP version, SSL, debug mode
* Per-site activity log (30 days)
* Survive plugin-caused fatals — bad plugin auto-deactivates, site stays up
* Whitelabel the connector under your agency's brand (every plan, no extra cost)

**Plans and limits:** Free plan covers 3 sites with 30 updates/month. Paid plans scale to 50 or 1,000 sites. See pricing at https://deckwp.com.

== Installation ==

= Automatic (recommended) =

1. Sign up at https://deckwp.com (free, 3 sites, no card required)
2. Click **+ Add Site**
3. Paste your site URL + a wp-admin username/password
4. DeckWP installs and pairs the connector in under 30 seconds

= Manual =

1. Download the plugin ZIP from your DeckWP dashboard
2. WP admin → **Plugins → Add New → Upload Plugin**
3. Activate
4. Open **Settings → DeckWP Connect** in wp-admin
5. Copy the connection token shown there
6. In DeckWP: **+ Add Site → Manual** → paste URL + token

== Privacy & Security ==

* Your wp-admin password is **never stored** — pairing uses a one-time token
* Every request from DeckWP to this site is **HMAC-SHA256 signed**, verified before any action runs. Tampered requests are rejected.
* Unpair anytime by clicking **Regenerate token** here, or by deleting the site from your DeckWP dashboard. Either action revokes access immediately.
* No tracking, no analytics, no phone-home on page loads. Code only runs when DeckWP makes a request.

== Frequently Asked Questions ==

= Will this slow down my site? =

No. Plugin is tiny (under 100 KB) and only runs when DeckWP makes a request or when you open its Settings page. Nothing on normal page loads for visitors.

= How do I disconnect? =

Two options:
1. From DeckWP: open the site's page, click **Delete site**, type DELETE to confirm
2. From WordPress: **Settings → DeckWP Connect** → **Regenerate token**. Either action clears the pairing and revokes the stored secret.

= What if my DeckWP account is cancelled? =

The connector stops talking to DeckWP. Your WordPress site is unaffected. You can deactivate and delete the plugin from **Plugins** in wp-admin at any time.

= Can I rebrand the plugin? =

Yes — whitelabel is included on every plan. Rebrand globally or per-site (plugin name, description, author, author URL) or hide it from the Plugins list entirely. All from your DeckWP dashboard.

== Changelog ==

See CHANGELOG.md in the repository for the full history.

= 0.1.0 =
* Initial pre-release: bootstrap, settings option, HMAC verifier (Sprint 0).

== Support ==

Email support@deckwp.com or visit https://deckwp.com/contact.
