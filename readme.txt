=== DeckWP Connect ===
Contributors: deckwp
Tags: management, updates, backups, security, multisite
Requires at least: 5.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.39.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pair your WordPress site with the DeckWP dashboard for one-click updates, automatic backup & rollback, security scans, SSO, and remote management.

== Description ==

DeckWP Connect securely pairs this WordPress site with your DeckWP account at https://deckwp.com. Once paired, you manage the site remotely from the DeckWP dashboard — no more logging into wp-admin on every site you own.

Every action is **initiated by the dashboard over an HMAC-signed request** and verified before anything runs. The plugin does nothing on visitor page loads, sends no tracking or analytics, and never phones home on its own.

**What you can do from DeckWP once this site is paired:**

* **Updates** — install, update, and activate plugins and themes one at a time or in bulk, driven from the dashboard.
* **Backup & rollback** — a backup is taken before every managed update, and the update is automatically rolled back if a post-update smoke check finds the site broken.
* **Restore** — restore any backup the dashboard captured, on demand.
* **Security scan** — an on-demand local scan flags high-signal compromise indicators (PHP files inside the uploads directory, obfuscated `eval()` backdoor signatures in plugins/themes, a world-writable `wp-config.php`) and reports them to the dashboard.
* **One-click SSO login** — open wp-admin from the dashboard with no password re-entry, via a short-lived single-use token.
* **Inventory** — see every installed plugin and theme, and which ones have updates available.
* **Site Health** — run WordPress' built-in Site Health checks and review the results in the dashboard.
* **Database optimization** — review table sizes and overhead, clean up post revisions, spam, expired transients and other cruft, and run OPTIMIZE TABLE.
* **Maintenance mode** — put the site behind a branded 503 holding page while you work.
* **Fatal-error protection** — if a plugin update or change triggers a fatal error, the bundled error handler deactivates the offending plugin so your site stays online, then reports the incident to the dashboard.
* **White-label** — rebrand the connector (name, description, author, links, plus login and admin-bar logos) or hide it from the Plugins list entirely, all from the dashboard.

The connector also keeps **itself** up to date: when a new version is published it offers the update through the normal WordPress Plugins screen, so you click Update like any other plugin.

**Privacy & security at a glance**

* No tracking, no analytics, no phone-home on page loads — the connector acts only on an authenticated request from your dashboard.
* Every dashboard → site request is HMAC-SHA256 signed and verified, with a 60-second anti-replay window.
* The per-site secret is stored out of WordPress' always-loaded option cache and is rotated whenever you re-pair the site.

**Open source.** DeckWP Connect is GPLv2 and developed in the open at https://github.com/ultrapackadm/deckwp-connect — read every line before you install it. For plans and pricing, see https://deckwp.com.

== Installation ==

= Recommended: install and pair from the DeckWP dashboard =

1. Create an account at https://deckwp.com.
2. Click **+ Add Site** and enter this site's URL plus a wp-admin administrator login.
3. DeckWP installs the connector and completes the pairing handshake for you, usually in under a minute.

= Manual =

1. Download `deckwp-connect.zip` from your DeckWP dashboard (or from the GitHub Releases page).
2. In wp-admin, go to **Plugins → Add New → Upload Plugin**, choose the ZIP, click **Install Now**, then **Activate**.
3. In the DeckWP dashboard, choose **+ Add Site** and generate a pairing token.
4. In wp-admin, open **Settings → DeckWP Connect**, paste the pairing token (and, if you run a staging or self-hosted dashboard, its URL), and click **Connect**.

Pairing tokens are single-use and expire 15 minutes after they are issued. On success the dashboard and the site exchange a per-site secret that signs every future request.

== Frequently Asked Questions ==

= Does the plugin slow down my site or track visitors? =

No. DeckWP Connect has no visitor-facing code path — it does nothing on normal front-end page loads, sends no analytics, and never "phones home" on page views. It acts only when your DeckWP dashboard makes an authenticated request, when you use its Settings page, or on an optional background heartbeat (which stays off unless you explicitly enable it).

= How is the connection secured? =

Every request from the dashboard to this site is signed with HMAC-SHA256 using a per-site secret established during pairing, and verified before any action runs. Signatures cover the HTTP method, path, and body, are compared in constant time, and are rejected outside a 60-second anti-replay window — so a captured request can't be replayed or re-pointed at a different endpoint. The secret is stored in an autoload-disabled option (kept out of WordPress' always-loaded cache) and is rotated whenever the site is re-paired, so a previous owner can't keep signing requests. The one-click SSO login uses a separate short-lived, single-use signed token.

= How do I disconnect this site? =

Either one revokes access immediately:

* In wp-admin: **Settings → DeckWP Connect → Disconnect**. This clears the stored connection and notifies the dashboard.
* In DeckWP: open the site and delete it.

If the dashboard revokes the site, the connector detects it on its next call and clears the local connection on its own.

= How do I uninstall it? =

Deactivate and delete the plugin from **Plugins** like any other plugin — deactivating also clears its scheduled tasks. If you want to remove every trace, you can additionally delete the `deckwp_connect_settings` option and the `wp-content/uploads/deckwp-backups/` directory.

= What happens if I cancel my DeckWP account? =

The connector simply stops being contacted. Your WordPress site is unaffected and keeps working normally; deactivate and delete the plugin whenever you like.

= Can I rebrand the plugin for my clients? =

Yes. White-labeling is configured from the DeckWP dashboard — rename the connector and change its description, author, and links, customize the login and admin-bar logos, or hide it from the Plugins list. Changes apply across wp-admin in real time.

== Changelog ==

The complete, versioned history lives in `CHANGELOG.md` in the repository, and every GitHub Release carries its own notes:
https://github.com/ultrapackadm/deckwp-connect/releases

= 0.34.0 =
* Lowered the WordPress minimum to 5.2. The connector's WordPress API surface supports it; `WP_Site_Health` is the only 5.2-era dependency, and the Site Health module now degrades gracefully when it is unavailable. See `CHANGELOG.md` for full details and earlier releases.

== Support ==

Email support@deckwp.com or visit https://deckwp.com/contact. Please report security issues privately to security@deckwp.com rather than filing a public issue.
