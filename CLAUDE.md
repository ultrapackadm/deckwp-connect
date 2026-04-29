# CLAUDE.md — deckwp-connect

## What this is

WordPress plugin (open-source GPLv2) that pairs a customer's WP install
with the DeckWP dashboard at deckwp.com. Lives on the customer's site;
communicates with the dashboard via HMAC-signed REST/HTTP.

**Plugin slug:** `deckwp-connect/deckwp-connect.php`
**Namespace:** `DeckWP\Connect\`
**REST namespace:** `deckwp/v1/*`
**Settings option:** `deckwp_connect_settings` (single-site: `wp_options`,
multisite: `wp_sitemeta`)
**Custom HTTP headers:** `X-DeckWP-Signature`, `X-DeckWP-Nonce`,
`X-DeckWP-Timestamp`

## Compatibility floor (do not raise without discussion)

- WordPress **5.6+**
- PHP **7.4+** (NOT 8.0+ — many shared hosts still on 7.4; we'd lose
  market share by raising)
- WP Multisite supported (settings in wp_sitemeta, optional per-blog ops)

## Code conventions

- WordPress Coding Standards (WPCS) — run `./vendor/bin/phpcs` before
  commit. Exceptions: PSR-4 namespacing (we use it; WPCS doesn't enforce)
- All public class methods documented with PHPDoc
- All hooks prefixed `deckwp_connect_*`
- All option keys prefixed `deckwp_connect_*`
- All REST routes under `deckwp/v1/`
- All custom headers prefixed `X-DeckWP-*`
- No globals; use static singletons or DI where applicable
- No third-party packages requiring PHP 8.0+ (limits Composer choices)

## Security non-negotiables

1. **Every dashboard → site request must be HMAC-verified.** The only
   exception is `/confirm-pair` (validates the bare token via
   `hash_equals`) and `/bootstrap-token` (uses cookie auth + nonce for
   logged-in admins).
2. **Anti-replay window is 60 seconds.** Tighter is fine; looser
   requires explicit discussion.
3. **HMAC secret is rotated on re-pair** so a previous owner can't keep
   signing requests.
4. **`hash_equals` for all signature/token comparisons** — never `===`
   on cryptographic material.
5. **`random_bytes()` for token + secret generation** — never
   `mt_rand`, `uniqid`, `wp_generate_password` (latter ok for nonces
   but not for secrets).
6. **`autoload=false` for the settings option** so the hmac_secret
   never enters WP's always-loaded options cache.
7. **Drop-in fatal handler must NEVER deactivate the connector itself** —
   would sever the only remote recovery path.

## Architecture overview

```
deckwp-connect.php   ← entrypoint, defines constants, registers
                       activation hooks, boots Bootstrap
                              ↓
                    plugins_loaded action
                              ↓
                    DeckWP\Connect\Bootstrap::boot()
                       ├─ Settings\Page         (admin UI)
                       ├─ REST\Server           (deckwp/v1/* routes)
                       ├─ Transport\InitHookFallback (when REST blocked)
                       ├─ DropIn\Installer      (fatal-error-handler.php)
                       ├─ Whitelabel\Branding   (rewrites plugin metadata)
                       ├─ Maintenance\Page      (503 holding page)
                       └─ Updater\SelfUpdater   (pulls connector updates)
```

## Testing

- PHPUnit 9.x (PHP 7.4 compat — can't go to 10+ until we drop 7.4)
- Mock WP functions via Brain\Monkey or WP_Mock (decide once we add
  the first test)
- HMAC verification has dedicated test suite — every reject path covered

## Deployment to wordpress.org plugin directory

NOT used. We distribute via:
1. **Auto-install** through DeckWP dashboard (one-click via wp-admin
   credentials)
2. **Manual ZIP download** from GitHub Releases
3. **Self-update** via DeckWP API (`/api/v1/connector/latest` returns
   download_url for the connector ZIP)

## What this plugin does NOT do

- It does NOT host or distribute plugins/themes (that's UltraPack's job
  via the catalog API)
- It does NOT process payments (that's deckwp-app via Stripe/PayPal)
- It does NOT track users or send analytics
- It does NOT phone home on page loads — only acts when the dashboard
  makes an HMAC-signed request
