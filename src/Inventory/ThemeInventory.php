<?php

namespace DeckWP\Connect\Inventory;

defined('ABSPATH') || exit;

/**
 * Collects the local WordPress theme inventory for the heartbeat payload.
 *
 * Each row mirrors the shape the dashboard's HeartbeatProcessor::syncThemes()
 * expects to upsert against:
 *
 *     [
 *         'slug'             => 'avada-child',        // stylesheet dir name
 *         'name'             => 'Avada Child',
 *         'version'          => '1.0',
 *         'active'           => true,
 *         'parent'           => 'Avada',              // null for non-child / standalone
 *         'update_available' => false,
 *         'new_version'      => null,                  // populated when update available
 *     ]
 *
 * `slug` is the stylesheet directory name (the folder inside
 * wp-content/themes/) — that's what `get_stylesheet()` returns for the
 * currently-active theme, and the canonical key the dashboard joins on.
 *
 * `active` reflects WP's `get_stylesheet()` — the active theme on this
 * site. For child themes, only the child row gets active=true; the
 * parent (which WP still loads) reports active=false. This matches WP's
 * own semantics: `wp_get_theme()` exposes both, but only one slug is
 * "the active stylesheet".
 *
 * `parent` is populated for child themes from WP's `Template:` header.
 * Standalone / parent themes leave this null.
 *
 * Update detection reads WP's `update_themes` site transient — the same
 * source the WP admin "Updates" screen uses. Mirror of {@see PluginInventory}'s
 * `update_plugins` path, including the on-demand `wp_update_themes()` call
 * to refresh the transient when wp-cron is misbehaving.
 */
class ThemeInventory
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function collect(): array
    {
        if (! function_exists('wp_get_themes')) {
            // wp_get_themes lives in wp-includes/theme.php which is
            // loaded by core on every request — but be defensive
            // anyway for unusual bootstrap orders (e.g. WP-CLI
            // commands that skip parts of the standard load).
            return [];
        }

        $all = (array) wp_get_themes(['errors' => null, 'allowed' => null]);
        $activeSlug = function_exists('get_stylesheet') ? (string) get_stylesheet() : '';
        $updates = $this->updatePayload();

        $rows = [];
        foreach ($all as $slug => $theme) {
            // wp_get_themes() returns WP_Theme objects keyed by their
            // stylesheet directory name. The key IS the slug.
            $slug = (string) $slug;
            if ($slug === '') {
                continue;
            }

            // WP_Theme exposes header fields via get(). Coerce to string
            // — broken theme headers can return false here, which would
            // poison the payload as the literal "false" string downstream.
            $name = method_exists($theme, 'get') ? (string) $theme->get('Name') : '';
            $version = method_exists($theme, 'get') ? (string) $theme->get('Version') : '';

            // `Template:` is the parent theme's stylesheet name for
            // child themes. Standalone themes carry the same value as
            // their own slug (`Template: <self>`) by WP convention —
            // we strip that case so the dashboard's catalog only
            // stores real parent relationships.
            $template = method_exists($theme, 'get') ? (string) $theme->get('Template') : '';
            $parent = ($template !== '' && $template !== $slug) ? $template : null;

            $rows[] = [
                'slug'             => $slug,
                'name'             => $name,
                'version'          => $version,
                'active'           => $slug === $activeSlug,
                'parent'           => $parent,
                'update_available' => isset($updates[$slug]),
                'new_version'      => isset($updates[$slug]['new_version'])
                    ? (string) $updates[$slug]['new_version']
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * Pull the theme update payload out of the WP site transient.
     * Returns a map keyed by theme slug (the stylesheet dir name).
     *
     * Same pattern as {@see PluginInventory::updatePayload}:
     *
     *   1. Force-refresh the transient via wp_update_themes() so the
     *      dashboard doesn't see stale "0 outdated" when wp-cron is
     *      misbehaving (DISABLE_WP_CRON=true without an external cron).
     *   2. Set the UpdateSuppressor bypass constant FIRST so the
     *      site_transient_update_themes filter passes through any
     *      managed slugs (the dashboard NEEDS the full picture for
     *      inventory reporting, even when admin updates are hidden).
     *
     * @return array<string, array<string, mixed>>
     */
    private function updatePayload(): array
    {
        // 1. Bypass UpdateSuppressor for this read — set BEFORE
        // wp_update_themes() because the internal site_transient
        // read in wp_update_themes also goes through the filter.
        if (! defined('DECKWP_CONNECT_ALLOW_MANAGED_UPDATES')) {
            define('DECKWP_CONNECT_ALLOW_MANAGED_UPDATES', true);
        }

        // 2. Refresh the transient on-demand.
        if (function_exists('wp_update_themes')) {
            wp_update_themes();
        }

        // 3. Read. Note: update_themes uses a stdClass shape that's
        // similar but not identical to update_plugins —
        // `$transient->response` is an array of arrays (not objects),
        // keyed by theme slug.
        $transient = get_site_transient('update_themes');

        if (! is_object($transient) || ! isset($transient->response) || ! is_array($transient->response)) {
            return [];
        }

        $out = [];
        foreach ($transient->response as $slug => $data) {
            // update_themes' entries are arrays already in modern WP,
            // but coerce defensively in case a host's customization
            // turned them into objects.
            $out[(string) $slug] = is_object($data) ? (array) $data : (array) $data;
        }

        return $out;
    }
}
