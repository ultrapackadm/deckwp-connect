<?php

namespace DeckWP\Connect\License;

defined('ABSPATH') || exit;

/**
 * Detects whether an installed plugin/theme carries an ACTIVE official
 * license on this site.
 *
 * Ported from the UltraPack Auto Updater LicenseDetector (authored by
 * Marcus) and adapted for the DeckWP connector: it REPORTS a state to
 * the dashboard rather than blocking an updater locally, and it excludes
 * wp.org as an update source (in the connector this runs over EVERY
 * installed item, not just catalog-managed ones, so a plain wp.org
 * update must never read as "licensed").
 *
 * The state rides on the inventory / heartbeat payload; the dashboard
 * decides what to do with it (skip auto sweeps, require an override on
 * manual updates). The connector also consults this at install time as
 * a final safeguard before overwriting a folder.
 *
 * ## Signals (any hit ⇒ licensed_active)
 *
 *   A. Own updater active — an update sits in the update_plugins /
 *      update_themes transient whose `package` is neither wp.org nor
 *      DeckWP/UltraPack. Only a third-party (author) updater injects
 *      that, which means a live license.
 *   B. Custom `Update URI:` header (plugins, WP 6.5+) pointing off
 *      wp.org — declares a custom update server even before an update
 *      is pending.
 *   C. Known license framework says active — EDD (`<slug>_license_status
 *      = valid`), Freemius (`fs_accounts` site with a license), plus an
 *      extensible filter `deckwp_detect_active_license`.
 *
 * ## Fail-open
 *
 * Only returns `licensed_active` when a signal is confident; everything
 * else is `unknown`. A false negative just means "not protected" (same
 * as today); the operator can still force Protect from the dashboard.
 *
 * ## Circularity
 *
 * Signal A reads the update transient. At the point of APPLICATION
 * (Installer, right before overwrite) the connector has itself just
 * refreshed that transient with the managed-updates bypass, so reading
 * it there would be circular. Callers on that path pass
 * `$useTransient = false` (framework signals only) — mirrors the UAU
 * `$use_transient_signal` guard.
 */
class LicenseDetector
{
    /** Guard against re-entrancy while reading the update transient. */
    private static $resolvingTransient = false;

    /**
     * License state for one item.
     *
     * @param string $slug         Plugin dir slug or theme stylesheet slug.
     * @param string $type         'plugin' | 'theme'.
     * @param bool   $useTransient Include signal A (update transient).
     * @return array{state: string, provider: ?string}
     *         state ∈ {'licensed_active','unknown'}.
     */
    public function detect(string $slug, string $type, bool $useTransient = true): array
    {
        $type = ($type === 'theme') ? 'theme' : 'plugin';

        if ($slug === '') {
            return ['state' => 'unknown', 'provider' => null];
        }

        if ($useTransient && $this->officialUpdateInTransient($slug, $type)) {
            return ['state' => 'licensed_active', 'provider' => 'external_updater'];
        }

        if ($type === 'plugin' && $this->hasCustomUpdateUri($slug)) {
            return ['state' => 'licensed_active', 'provider' => 'update_uri'];
        }

        $framework = $this->activeLicenseFramework($slug, $type);
        if ($framework !== null) {
            return ['state' => 'licensed_active', 'provider' => $framework];
        }

        return ['state' => 'unknown', 'provider' => null];
    }

    /**
     * Convenience boolean. Defaults to NOT using the transient signal —
     * the common caller is the install-time safeguard, where signal A is
     * circular.
     */
    public function isLicensedActive(string $slug, string $type, bool $useTransient = false): bool
    {
        return $this->detect($slug, $type, $useTransient)['state'] === 'licensed_active';
    }

    /**
     * Signal A: an update present whose package comes from a third-party
     * (author) updater — i.e. neither wp.org nor DeckWP/UltraPack.
     */
    private function officialUpdateInTransient(string $slug, string $type): bool
    {
        if (self::$resolvingTransient) {
            return false;
        }
        self::$resolvingTransient = true;
        $transient = get_site_transient($type === 'theme' ? 'update_themes' : 'update_plugins');
        self::$resolvingTransient = false;

        if (! is_object($transient) || empty($transient->response) || ! is_array($transient->response)) {
            return false;
        }

        if ($type === 'theme') {
            if (empty($transient->response[$slug])) {
                return false;
            }
            $resp = $transient->response[$slug];
            $package = is_array($resp) && isset($resp['package']) ? (string) $resp['package'] : '';
        } else {
            $file = $this->pluginFileForSlug($slug);
            if ($file === null || empty($transient->response[$file])) {
                return false;
            }
            $resp = $transient->response[$file];
            $package = is_object($resp) && isset($resp->package) ? (string) $resp->package : '';
        }

        return $this->isExternalPackage($package);
    }

    /**
     * A package URL that is a real update source but NOT wp.org and NOT
     * DeckWP/UltraPack. Empty / wp.org / our own = not a license signal.
     */
    private function isExternalPackage(string $package): bool
    {
        if ($package === '') {
            return false;
        }
        $needles = ['downloads.wordpress.org', '//wordpress.org', 's.w.org', 'ultrapack', 'deckwp'];
        foreach ($needles as $needle) {
            if (strpos($package, $needle) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Signal B: the plugin declares a custom `Update URI:` header (WP
     * 6.5+) that points off wp.org. `false` or a `w.org/...` value means
     * wp.org-managed and is NOT a license signal.
     */
    private function hasCustomUpdateUri(string $slug): bool
    {
        $file = $this->pluginFileForSlug($slug);
        if ($file === null) {
            return false;
        }

        $path = WP_PLUGIN_DIR . '/' . $file;
        if (! is_readable($path)) {
            return false;
        }

        $data = get_file_data($path, ['UpdateURI' => 'Update URI'], 'plugin');
        $uri = isset($data['UpdateURI']) ? trim((string) $data['UpdateURI']) : '';

        if ($uri === '' || strtolower($uri) === 'false') {
            return false;
        }
        // wp.org slugs come as `https://wordpress.org/plugins/<slug>/` or
        // `w.org/plugin/<slug>` — both wp.org-managed, not a license.
        if (strpos($uri, 'wordpress.org') !== false || strpos($uri, 'w.org') !== false) {
            return false;
        }

        // Must be an absolute http(s) URI to count as a custom server.
        return (bool) preg_match('#^https?://#i', $uri);
    }

    /**
     * Signal C: known license frameworks. Returns a provider label or
     * null. Extensible via the `deckwp_detect_active_license` filter,
     * which wins when it returns a non-null value.
     */
    private function activeLicenseFramework(string $slug, string $type): ?string
    {
        /**
         * @param string|bool|null $external null = undecided (fall through);
         *                                    truthy = licensed; false = not.
         */
        $external = apply_filters('deckwp_detect_active_license', null, $slug, $type);
        if ($external !== null) {
            if (is_string($external) && $external !== '') {
                return $external;
            }

            return $external ? 'filter' : null;
        }

        if ($this->eddLicenseValid($slug)) {
            return 'edd';
        }

        if ($this->freemiusLicensed($slug)) {
            return 'freemius';
        }

        return null;
    }

    /**
     * EDD Software Licensing convention: a `<prefix>_license_status`
     * option holding 'valid' (string) or ['license' => 'valid'].
     * Covers EDD SL and the many premium plugins that copy its shape.
     */
    private function eddLicenseValid(string $slug): bool
    {
        $base = str_replace('-', '_', $slug);
        $candidates = array_unique([
            $slug . '_license_status',
            $base . '_license_status',
            $base . '_license_key_status',
        ]);

        foreach ($candidates as $opt) {
            $val = get_option($opt);
            if ($val === 'valid') {
                return true;
            }
            if (is_array($val) && isset($val['license']) && $val['license'] === 'valid') {
                return true;
            }
        }

        return false;
    }

    /**
     * Freemius: `fs_accounts` carries a `sites` map (keyed by plugin
     * slug) whose entry references a `license_id` when the install is on
     * a paid, licensed plan. Conservative — no entry / no license id =
     * not a signal.
     */
    private function freemiusLicensed(string $slug): bool
    {
        $fs = get_option('fs_accounts');
        if (! is_array($fs) || empty($fs['sites'])) {
            return false;
        }

        $sites = (array) $fs['sites'];
        if (! isset($sites[$slug])) {
            return false;
        }

        $site = (array) $sites[$slug];
        $licenseId = $site['license_id'] ?? null;

        return ! empty($licenseId) && $licenseId !== '0';
    }

    /**
     * Resolve a plugin's file identifier (e.g. `akismet/akismet.php`)
     * from its slug (dir name). Single-file plugins resolve to
     * `<slug>.php`.
     */
    private function pluginFileForSlug(string $slug): ?string
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach ((array) get_plugins() as $file => $data) {
            $dir = dirname((string) $file);
            if ($dir === '.' || $dir === '') {
                $dir = basename((string) $file, '.php');
            }
            if ($dir === $slug || $file === $slug . '.php' || $file === $slug) {
                return (string) $file;
            }
        }

        return null;
    }
}
