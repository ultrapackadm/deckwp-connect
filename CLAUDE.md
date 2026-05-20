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

All three channels resolve to the same artifact: the
`deckwp-connect.zip` asset attached to the latest GitHub Release.

## Release ritual

Releases are **fully automated** by `.github/workflows/release.yml`.
The dev's responsibility is the three-step prep; CI does the
packaging, validation, and publishing.

### Dev steps (manual)

1. **Bump version** in both places in `deckwp-connect.php`:
   - The plugin header line: `* Version:           X.Y.Z`
   - The `define('DECKWP_CONNECT_VERSION', 'X.Y.Z')` near the top
   The release workflow validates these match the tag — a typo here
   blocks the release before it publishes.
2. **Add a CHANGELOG entry** at the top of `CHANGELOG.md` under
   `## [X.Y.Z] — YYYY-MM-DD`. The workflow extracts this section
   verbatim into the GitHub Release body, so write it for the
   customer (not internal commit-message style).
3. **Commit, tag, push:**
   ```bash
   git add deckwp-connect.php CHANGELOG.md src/...
   git commit -m "feat(...): your message"
   git tag -a vX.Y.Z -m "vX.Y.Z — one-line summary"
   git push origin main --follow-tags
   ```

### CI steps (automatic on tag push)

The `release.yml` workflow fires on every `v*` tag push and:

1. Checks out the repo at the tagged commit
2. Runs `php -l` on every PHP file (entrypoint + `src/`) — a parse
   error blocks the release
3. Verifies the tag version matches `* Version:` AND
   `DECKWP_CONNECT_VERSION` in `deckwp-connect.php` — a mismatch
   blocks the release
4. Builds `deckwp-connect.zip` via
   `git archive --format=zip --prefix=deckwp-connect/`
   — the `.gitattributes` `export-ignore` rules strip
   `CLAUDE.md`, `tests/`, `composer.json`, `.github/` etc. from
   the customer-facing ZIP
5. Sanity-checks that the ZIP contains `deckwp-connect/deckwp-connect.php`
   at the expected path (defends against an `.gitattributes`
   refactor accidentally stripping the entrypoint)
6. Extracts the `## [X.Y.Z]` section from `CHANGELOG.md` via `awk`
   for the release body
7. Publishes the GitHub Release with the ZIP attached as
   `deckwp-connect.zip`

If the release already exists when the workflow fires (e.g. you ran
`gh release create` locally before pushing the tag, or you're
re-running the workflow via `workflow_dispatch`), the workflow
uploads the asset to the existing release with `--clobber` instead
of failing. This keeps the flow robust to mixed manual/CI usage.

### Why this is automated now

Releases v0.19.0–v0.24.1 (May 2026) shipped without the ZIP asset
because the manual `gh release upload` step was forgotten on every
release. The dashboard's `ConnectorReleaseFetcher` returned `null`
for `download_url`, breaking the Auto-pair flow with
"Could not resolve the latest deckwp-connect release from GitHub."

v0.24.1 was patched by manually rebuilding + uploading the ZIP via
PowerShell + `gh release upload --clobber`. The CI workflow exists
so this can't happen again — the release literally cannot be
published without the ZIP, because publishing IS attaching the ZIP.

### Manual fallback (workflow_dispatch)

If a release already exists but is missing the asset (e.g.
historical releases pre-CI), trigger the workflow manually:

1. GitHub UI → Actions → "Release" → "Run workflow"
2. Enter the tag (e.g. `v0.21.0`)
3. CI checks out at that tag, rebuilds the ZIP, and uploads with
   `--clobber` to the existing release

The same path can be used to re-build a ZIP if `.gitattributes` is
ever changed and an old release needs a clean rebuild.

## What this plugin does NOT do

- It does NOT host or distribute plugins/themes (that's UltraPack's job
  via the catalog API)
- It does NOT process payments (that's deckwp-app via Stripe/PayPal)
- It does NOT track users or send analytics
- It does NOT phone home on page loads — only acts when the dashboard
  makes an HMAC-signed request
