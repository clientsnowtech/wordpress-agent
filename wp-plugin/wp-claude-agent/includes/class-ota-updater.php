<?php
/**
 * Powerhouse OTA client — over-the-air updates from the Powerhouse release server.
 *
 * CANONICAL COPY: wp-plugin/_ota-client/class-ota-updater.php
 *
 * Every Powerhouse plugin bundles this file as includes/class-ota-updater.php
 * with the class renamed (Claude_Bridge_OTA_Updater -> e.g. PWRC_OTA_Updater).
 * WordPress runs all active plugins in one PHP process, so two plugins cannot
 * ship a class under the same name. Edit this copy, then re-copy it into each
 * plugin; scripts/build-wp-plugins.php warns when a bundled copy has drifted.
 *
 * Flow
 *   1. WordPress runs its plugin update check (twice a day, on the Plugins
 *      screen, or via "Check for updates"). inject_update() fetches the current
 *      release and lists the plugin as updatable, or as up to date — the latter
 *      is what makes WordPress show the auto-update toggle for a plugin that is
 *      not hosted on WordPress.org.
 *   2. On install (manual, bulk or automatic) verify_package() downloads the zip
 *      itself and refuses it unless its SHA-256 matches the published checksum,
 *      it is served securely by the update server, and it really contains this
 *      plugin at the advertised version.
 *   3. fix_source_dir() keeps the new files in the folder the plugin is
 *      installed in, so an update never leaves a second copy behind.
 *
 * Server contract — GET {origin}/api/plugin-update.php?slug=…&version=…
 *   { version, download_url, checksum (sha256 hex), requires, requires_php,
 *     tested, last_updated, homepage, description, changelog, upgrade_notice }
 *
 * Usage, from the plugin's main file:
 *   require_once __DIR__ . '/includes/class-ota-updater.php';
 *   My_OTA_Updater::init([
 *       'file'            => __FILE__,
 *       'slug'            => 'my-plugin',           // registry slug on the server
 *       'name'            => 'My Plugin',
 *       'prefix'          => 'my_plugin',           // option / action names
 *       'origin'          => 'my_origin_callback',  // optional: admin-configured server URL
 *       'origin_constant' => 'MY_PLUGIN_UPDATE_ORIGIN',
 *       'settings_url'    => admin_url('options-general.php?page=my-plugin'),
 *   ]);
 *   // and, inside the settings page:  My_OTA_Updater::render_panel();
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('Claude_Bridge_OTA_Updater')) :

class Claude_Bridge_OTA_Updater {

    const CACHE_TTL = 6 * HOUR_IN_SECONDS;     // good release info
    const RETRY_TTL = 30 * MINUTE_IN_SECONDS;  // back-off after a failed check

    /** @var array */
    private static $cfg = [];

    public static function init(array $cfg) {
        $cfg = array_merge([
            'file'             => '',
            'slug'             => '',
            'name'             => '',
            'prefix'           => '',
            'origin'           => null,
            'origin_constant'  => '',
            'default_origin'   => 'https://powerhouse.clientsnow.in',
            'settings_url'     => '',
            'icon'             => '',
            'description'      => '',
            'legacy_transient' => '',
        ], $cfg);
        if ($cfg['file'] === '' || $cfg['slug'] === '' || $cfg['prefix'] === '') return;

        $cfg['basename'] = plugin_basename($cfg['file']);
        if ($cfg['name'] === '') $cfg['name'] = $cfg['slug'];
        self::$cfg = $cfg;

        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'inject_update']);
        add_filter('plugins_api',                           [__CLASS__, 'plugin_info'], 20, 3);
        add_filter('upgrader_pre_download',                 [__CLASS__, 'verify_package'], 10, 4);
        add_filter('upgrader_source_selection',             [__CLASS__, 'fix_source_dir'], 10, 4);
        add_action('upgrader_process_complete',             [__CLASS__, 'after_upgrade'], 10, 2);
        add_action('admin_post_' . $cfg['prefix'] . '_ota_check', [__CLASS__, 'handle_check_now']);
        add_action('admin_post_' . $cfg['prefix'] . '_ota_auto',  [__CLASS__, 'handle_toggle_auto_update']);
        add_filter('plugin_action_links_' . $cfg['basename'], [__CLASS__, 'action_link']);
        add_action('admin_notices',                         [__CLASS__, 'result_notice']);
    }

    private static function key($suffix) {
        return self::$cfg['prefix'] . '_ota_' . $suffix;
    }

    /* ------------------------------------------------------------------
     * Update server
     * ------------------------------------------------------------------ */

    /**
     * Where update checks go: a wp-config.php constant if defined, else the
     * server URL an admin configured in the plugin's settings, else the
     * Powerhouse default. Only admins can set either, so both are trusted.
     */
    public static function update_origin() {
        $c = self::$cfg;
        if ($c['origin_constant'] !== '' && defined($c['origin_constant']) && constant($c['origin_constant'])) {
            return rtrim((string) constant($c['origin_constant']), '/');
        }
        if (is_callable($c['origin'])) {
            $o = trim((string) call_user_func($c['origin']));
            if ($o !== '' && preg_match('#^https?://#i', $o)) return rtrim($o, '/');
        }
        return rtrim($c['default_origin'], '/');
    }

    private static function host_of($url) {
        $h = parse_url((string) $url, PHP_URL_HOST);
        if (!$h) return '';
        return strtolower(preg_replace('#^www\.#i', '', trim($h, '[]')));
    }

    /** Loopback and dev-only TLDs — the only hosts allowed to serve updates over plain HTTP. */
    private static function is_local_host($host) {
        $host = strtolower(trim((string) $host, '[]'));
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return true;
        return (bool) preg_match('/\.(test|local|localhost)$/', $host);
    }

    /**
     * A package is acceptable only from the update server's host (or a host
     * added through the `{prefix}_ota_allowed_hosts` filter), and only over
     * HTTPS — plain HTTP is tolerated for a local development server alone.
     */
    public static function package_allowed($url) {
        $url = (string) $url;
        if ($url === '') return false;
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = self::host_of($url);
        if ($host === '') return false;

        $hosts = [self::host_of(self::update_origin())];
        $hosts = array_values(array_filter((array) apply_filters(self::key('allowed_hosts'), $hosts)));
        if (!in_array($host, $hosts, true)) return false;

        if ($scheme === 'https') return true;
        return $scheme === 'http' && self::is_local_host($host);
    }

    public static function status() {
        $s = get_site_option(self::key('status'), []);
        return is_array($s) ? $s : [];
    }

    public static function clear_cache() {
        delete_site_transient(self::key('release'));
        delete_site_option(self::key('status'));
        if (self::$cfg['legacy_transient'] !== '') delete_site_transient(self::$cfg['legacy_transient']);
    }

    /**
     * Current release info, or null when it cannot be had. Successes are cached
     * for six hours; failures for 30 minutes, so an unreachable server can't
     * stall admin pages on a timeout every load.
     *
     * @param bool $force Skip both caches.
     * @return array|null
     */
    public static function fetch_remote_info($force = false) {
        if (!$force) {
            $cached = get_site_transient(self::key('release'));
            if (is_array($cached) && array_key_exists('_package_ok', $cached)) return $cached;

            $status = self::status();
            if (empty($status['ok']) && !empty($status['checked_at'])
                && (time() - (int) $status['checked_at']) < self::RETRY_TTL) {
                return null;
            }
        }

        $origin = self::update_origin();
        $url = add_query_arg([
            'slug'    => self::$cfg['slug'],
            'version' => self::installed_version(),
            'wp'      => get_bloginfo('version'),
            'php'     => PHP_VERSION,
        ], $origin . '/api/plugin-update.php');

        $resp = wp_remote_get($url, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);

        if (is_wp_error($resp)) {
            return self::record_failure($origin, 'Could not reach the update server: ' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code !== 200) {
            $detail = is_array($body) && !empty($body['error']) ? ' — ' . sanitize_text_field($body['error']) : '';
            return self::record_failure($origin, 'Update server answered HTTP ' . $code . $detail);
        }
        if (!is_array($body) || empty($body['version']) || !preg_match('/^\d+(\.\d+){1,3}$/', (string) $body['version'])) {
            return self::record_failure($origin, 'Update server returned an unreadable release.');
        }

        $checksum_ok = !empty($body['checksum']) && preg_match('/^[a-f0-9]{64}$/i', (string) $body['checksum']);
        $package_ok  = $checksum_ok && self::package_allowed($body['download_url'] ?? '');

        $problem = null;
        if (!$checksum_ok) {
            $problem = 'The release has no SHA-256 checksum, so it cannot be verified and will not be installed.';
        } elseif (!$package_ok) {
            $problem = 'The release download is not served securely by the update server, so it will not be installed.';
        }

        $body['_package_ok'] = (bool) $package_ok;
        set_site_transient(self::key('release'), $body, self::CACHE_TTL);
        self::save_status([
            'checked_at'     => time(),
            'ok'             => $problem === null,
            'error'          => $problem,
            'remote_version' => (string) $body['version'],
            'origin'         => $origin,
        ]);
        return $body;
    }

    private static function record_failure($origin, $message) {
        self::save_status([
            'checked_at'     => time(),
            'ok'             => false,
            'error'          => $message,
            'remote_version' => self::status()['remote_version'] ?? null,
            'origin'         => $origin,
        ]);
        return null;
    }

    private static function save_status(array $new) {
        $old = self::status();
        update_site_option(self::key('status'), $new);
        $changed = !array_key_exists('ok', $old)
            || (bool) $old['ok'] !== (bool) $new['ok']
            || ($old['error'] ?? null) !== ($new['error'] ?? null);
        if ($changed && !$new['ok'] && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[' . self::$cfg['name'] . ' updates] ' . $new['error']);
        }
    }

    /* ------------------------------------------------------------------
     * WordPress update integration
     * ------------------------------------------------------------------ */

    /**
     * The version actually on disk. The request that just installed an update
     * still has the old code loaded, so a version constant would re-offer the
     * update that was just installed.
     */
    public static function installed_version() {
        $file = WP_PLUGIN_DIR . '/' . self::$cfg['basename'];
        if (is_readable($file)) {
            $data = get_file_data($file, ['Version' => 'Version']);
            if (!empty($data['Version'])) return (string) $data['Version'];
        }
        return '0.0.0';
    }

    private static function wp_slug() {
        $dir = dirname(self::$cfg['basename']);
        return ($dir === '.' || $dir === '') ? self::$cfg['slug'] : $dir;
    }

    private static function update_item(array $remote) {
        $icon = self::$cfg['icon'];
        return (object) [
            'id'             => self::$cfg['basename'],
            'slug'           => self::wp_slug(),
            'plugin'         => self::$cfg['basename'],
            'new_version'    => (string) $remote['version'],
            'url'            => esc_url_raw($remote['homepage'] ?? self::$cfg['default_origin']),
            'package'        => !empty($remote['_package_ok']) ? (string) $remote['download_url'] : '',
            'icons'          => $icon !== '' ? ['1x' => $icon, '2x' => $icon] : [],
            'banners'        => [],
            'banners_rtl'    => [],
            'tested'         => (string) ($remote['tested'] ?? ''),
            'requires'       => (string) ($remote['requires'] ?? ''),
            'requires_php'   => (string) ($remote['requires_php'] ?? ''),
            'upgrade_notice' => wp_strip_all_tags((string) ($remote['upgrade_notice'] ?? '')),
        ];
    }

    /**
     * List the plugin in WordPress's update data. Deliberately does NOT wait for
     * `$transient->checked`: WordPress (verified on 6.6.1) leaves it off the
     * final write of a normal update check, so that common guard means the
     * update is silently never offered. Release info is cached, so this is cheap.
     */
    public static function inject_update($transient) {
        if (!is_object($transient) || wp_installing() || empty(self::$cfg)) return $transient;

        $remote = self::fetch_remote_info();
        if (!$remote) return $transient;

        $b    = self::$cfg['basename'];
        $item = self::update_item($remote);
        if (!isset($transient->response) || !is_array($transient->response))   $transient->response  = [];
        if (!isset($transient->no_update) || !is_array($transient->no_update)) $transient->no_update = [];

        if (version_compare(self::installed_version(), $remote['version'], '<') && $item->package !== '') {
            $transient->response[$b] = $item;
            unset($transient->no_update[$b]);
        } else {
            $transient->no_update[$b] = $item;
            unset($transient->response[$b]);
        }
        return $transient;
    }

    /** The "View details" modal. */
    public static function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || empty(self::$cfg)) return $result;
        if (!in_array($args->slug, [self::wp_slug(), self::$cfg['slug']], true)) return $result;

        $remote = self::fetch_remote_info();
        if (!$remote) {
            $status = self::status();
            return new WP_Error('ota_update_unavailable', $status['error'] ?? 'Release information is not available right now.');
        }

        $changelog = (string) ($remote['sections']['changelog'] ?? $remote['changelog'] ?? '');
        $describe  = (string) ($remote['sections']['description'] ?? $remote['description'] ?? self::$cfg['description']);

        return (object) [
            'name'           => self::$cfg['name'],
            'slug'           => self::wp_slug(),
            'version'        => (string) $remote['version'],
            'author'         => '<a href="https://clientsnow.in">ClientsNow</a>',
            'author_profile' => 'https://clientsnow.in',
            'homepage'       => esc_url_raw($remote['homepage'] ?? self::$cfg['default_origin']),
            'download_link'  => !empty($remote['_package_ok']) ? (string) $remote['download_url'] : '',
            'requires'       => (string) ($remote['requires'] ?? ''),
            'tested'         => (string) ($remote['tested'] ?? ''),
            'requires_php'   => (string) ($remote['requires_php'] ?? ''),
            'last_updated'   => (string) ($remote['last_updated'] ?? ''),
            // Remote HTML renders inside wp-admin — never trust it unfiltered.
            'sections'       => [
                'description' => wp_kses_post($describe),
                'changelog'   => wp_kses_post($changelog),
            ],
            'banners'        => [],
        ];
    }

    /**
     * Download this plugin's update ourselves and verify it before WordPress
     * unpacks anything. Returning a file path installs it; a WP_Error aborts the
     * update and leaves the installed version untouched.
     */
    public static function verify_package($reply, $package, $upgrader, $hook_extra = []) {
        if ($reply !== false || empty(self::$cfg)) return $reply;

        $b      = self::$cfg['basename'];
        $cached = get_site_transient(self::key('release'));
        $is_ours = (is_array($hook_extra) && ($hook_extra['plugin'] ?? '') === $b)
            || (is_array($cached) && !empty($cached['download_url']) && $package === $cached['download_url']);
        if (!$is_ours) return $reply;

        $remote = (is_array($cached) && !empty($cached['download_url'])) ? $cached : self::fetch_remote_info(true);
        if (!$remote) {
            return new WP_Error('ota_update_unverified', 'Could not reach the update server to verify this update. Nothing was changed — try again shortly.');
        }
        if ((string) $package !== (string) ($remote['download_url'] ?? '')) {
            $remote = self::fetch_remote_info(true);
            if (!$remote || (string) $package !== (string) ($remote['download_url'] ?? '')) {
                self::log_install(false, 'Package URL does not match the published release: ' . $package);
                return new WP_Error('ota_package_mismatch', 'This update package is not the release that was published. Nothing was changed.');
            }
        }
        if (!self::package_allowed($package)) {
            self::log_install(false, 'Package host not trusted: ' . $package);
            return new WP_Error('ota_package_untrusted', 'This update is not served securely by the update server, so it was not installed.');
        }
        $expected = strtolower((string) ($remote['checksum'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $expected)) {
            self::log_install(false, 'Release has no SHA-256 checksum');
            return new WP_Error('ota_no_checksum', 'This release has no checksum, so it cannot be verified and was not installed.');
        }

        if (is_object($upgrader) && isset($upgrader->skin) && method_exists($upgrader->skin, 'feedback')) {
            $upgrader->skin->feedback('downloading_package', $package);
        }
        if (!function_exists('download_url')) require_once ABSPATH . 'wp-admin/includes/file.php';

        $tmp = download_url($package, 300);
        if (is_wp_error($tmp)) {
            self::log_install(false, 'Download failed: ' . $tmp->get_error_message());
            return $tmp;
        }

        $actual = strtolower((string) hash_file('sha256', $tmp));
        if (!hash_equals($expected, $actual)) {
            @unlink($tmp);
            self::log_install(false, sprintf('Checksum mismatch for %s: expected %s…, got %s…', $remote['version'], substr($expected, 0, 12), substr($actual, 0, 12)));
            return new WP_Error('ota_checksum_mismatch', 'The downloaded update does not match the published checksum, so it was not installed. Your current version is untouched.');
        }

        $inspect = self::inspect_package($tmp, (string) $remote['version']);
        if (is_wp_error($inspect)) {
            @unlink($tmp);
            self::log_install(false, $inspect->get_error_message());
            return $inspect;
        }

        self::log_install(true, sprintf('Verified %s package (sha256 %s…)', $remote['version'], substr($actual, 0, 12)));
        return $tmp; // WordPress deletes it after unpacking
    }

    /** One top-level folder holding our main file with the advertised Version. */
    private static function inspect_package($file, $version) {
        if (!class_exists('ZipArchive')) return true; // the checksum still applies

        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return new WP_Error('ota_bad_zip', 'The update package is not a readable zip file.');
        }
        $main  = basename(self::$cfg['basename']);
        $roots = [];
        $found = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || strpos($name, '__MACOSX/') === 0) continue;
            $roots[strtok($name, '/')] = true;
            if (preg_match('#^[^/]+/' . preg_quote($main, '#') . '$#', $name)) $found = $name;
        }
        $header = $found !== null ? (string) $zip->getFromName($found, 8192) : '';
        $zip->close();

        if (count($roots) !== 1 || $found === null) {
            return new WP_Error('ota_bad_layout', 'The update package does not contain this plugin.');
        }
        if (!preg_match('/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $header, $m) || trim($m[1]) !== $version) {
            return new WP_Error('ota_version_mismatch', 'The update package is not version ' . $version . ' as advertised, so it was not installed.');
        }
        return true;
    }

    /** Keep the unpacked update in the folder the plugin is installed in. */
    public static function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = []) {
        if (is_wp_error($source) || empty(self::$cfg)) return $source;
        if (!is_array($hook_extra) || ($hook_extra['plugin'] ?? '') !== self::$cfg['basename']) return $source;

        $want = dirname(self::$cfg['basename']);
        if ($want === '.' || $want === '') return $source;
        if (basename(untrailingslashit($source)) === $want) return $source;

        global $wp_filesystem;
        $target = trailingslashit($remote_source) . $want;
        if (!$wp_filesystem || !$wp_filesystem->move(untrailingslashit($source), $target, true)) {
            return new WP_Error('ota_rename_failed', 'Could not prepare the update folder, so the update was not installed.');
        }
        return trailingslashit($target);
    }

    public static function after_upgrade($upgrader, $options) {
        if (empty(self::$cfg) || ($options['type'] ?? '') !== 'plugin' || ($options['action'] ?? '') !== 'update') return;
        $plugins = isset($options['plugins']) ? (array) $options['plugins'] : (isset($options['plugin']) ? [$options['plugin']] : []);
        if (!in_array(self::$cfg['basename'], $plugins, true)) return;

        self::clear_cache();
        self::log_install(true, 'Updated to ' . self::installed_version());
    }

    private static function log_install($ok, $message) {
        update_site_option(self::key('last_install'), ['at' => time(), 'ok' => (bool) $ok, 'message' => (string) $message]);
        if (!$ok && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[' . self::$cfg['name'] . ' updates] ' . $message);
        }
    }

    /* ------------------------------------------------------------------
     * Admin: buttons, notices, panel
     * ------------------------------------------------------------------ */

    public static function auto_updates_supported() {
        return function_exists('wp_is_auto_update_enabled_for_type') && wp_is_auto_update_enabled_for_type('plugin');
    }

    private static function redirect_back($state) {
        $arg = self::key('result');
        $to  = wp_get_referer();
        if (!$to) $to = self::$cfg['settings_url'] !== '' ? self::$cfg['settings_url'] : self_admin_url('plugins.php');
        wp_safe_redirect(add_query_arg($arg, $state, remove_query_arg($arg, $to)));
        exit;
    }

    public static function handle_check_now() {
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('Sorry, you are not allowed to update plugins for this site.'), 403);
        }
        check_admin_referer(self::key('check'));

        $remote = self::fetch_remote_info(true);
        if (!function_exists('wp_update_plugins')) require_once ABSPATH . 'wp-includes/update.php';
        delete_site_transient('update_plugins');
        wp_update_plugins();

        if (!$remote) {
            $state = 'error';
        } elseif (version_compare(self::installed_version(), $remote['version'], '<')) {
            $state = empty($remote['_package_ok']) ? 'blocked' : 'available';
        } else {
            $state = 'current';
        }
        self::redirect_back($state);
    }

    public static function handle_toggle_auto_update() {
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('Sorry, you are not allowed to update plugins for this site.'), 403);
        }
        check_admin_referer(self::key('auto'));
        if (!self::auto_updates_supported()) self::redirect_back('auto_unsupported');

        $b      = self::$cfg['basename'];
        $enable = !empty($_POST[self::key('auto_on')]);
        $auto   = (array) get_site_option('auto_update_plugins', []);
        $auto   = $enable ? array_merge($auto, [$b]) : array_diff($auto, [$b]);
        update_site_option('auto_update_plugins', array_values(array_unique($auto)));
        self::redirect_back($enable ? 'auto_on' : 'auto_off');
    }

    /** "Check for updates" under the plugin's name on the Plugins screen. */
    public static function action_link($links) {
        if (!current_user_can('update_plugins')) return $links;
        $url = wp_nonce_url(admin_url('admin-post.php?action=' . self::$cfg['prefix'] . '_ota_check'), self::key('check'));
        $links[] = '<a href="' . esc_url($url) . '">Check for updates</a>';
        return $links;
    }

    public static function result_notice() {
        $arg = self::key('result');
        if (empty($_GET[$arg]) || !current_user_can('update_plugins')) return;
        $name = self::$cfg['name'];
        $messages = [
            'current'          => ['success', $name . ': checked just now — you are running the latest version.'],
            'available'        => ['info',    $name . ': a new version is available. Install it from the Updates panel or the Plugins screen.'],
            'blocked'          => ['error',   $name . ': a new version exists but did not pass verification, so it will not be installed.'],
            'error'            => ['error',   $name . ': could not check for updates. ' . (self::status()['error'] ?? '')],
            'auto_on'          => ['success', $name . ': automatic updates enabled.'],
            'auto_off'         => ['success', $name . ': automatic updates disabled.'],
            'auto_unsupported' => ['error',   $name . ': automatic plugin updates are turned off for this whole site.'],
        ];
        $state = sanitize_key(wp_unslash($_GET[$arg]));
        if (!isset($messages[$state])) return;
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($messages[$state][0]), esc_html($messages[$state][1]));
    }

    /** Everything the panel shows, computed in one place. */
    public static function view_model() {
        $b      = self::$cfg['basename'];
        $remote = get_site_transient(self::key('release'));
        $status = self::status();
        $latest = is_array($remote) && !empty($remote['version']) ? (string) $remote['version'] : ($status['remote_version'] ?? null);

        $installed = self::installed_version();
        $available = $latest && version_compare($installed, $latest, '<');

        // "Update now" only when WordPress itself has the update queued.
        $wp_list    = get_site_transient('update_plugins');
        $queued     = is_object($wp_list) && isset($wp_list->response[$b]);
        $update_url = ($available && $queued && current_user_can('update_plugins'))
            ? wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($b)), 'upgrade-plugin_' . $b)
            : '';

        $last = get_site_option(self::key('last_install'), []);

        return [
            'name'           => self::$cfg['name'],
            'installed'      => $installed,
            'latest'         => $latest,
            'available'      => (bool) $available,
            'blocked'        => $available && is_array($remote) && empty($remote['_package_ok']),
            'checked_at'     => isset($status['checked_at']) ? (int) $status['checked_at'] : 0,
            'ok'             => !empty($status['ok']),
            'error'          => $status['error'] ?? null,
            'origin'         => self::update_origin(),
            'update_url'     => $update_url,
            'details_url'    => self_admin_url('plugin-install.php?tab=plugin-information&plugin=' . rawurlencode(self::wp_slug()) . '&TB_iframe=true&width=772&height=640'),
            'last_install'   => is_array($last) ? $last : [],
            'auto_supported' => self::auto_updates_supported(),
            'auto_enabled'   => in_array($b, (array) get_site_option('auto_update_plugins', []), true),
            'can_update'     => current_user_can('update_plugins'),
        ];
    }

    /** Self-contained Updates box for the plugin's settings page. */
    public static function render_panel() {
        if (empty(self::$cfg) || !current_user_can('update_plugins')) return;
        if (function_exists('add_thickbox')) add_thickbox();
        $m = self::view_model();

        if ($m['blocked'])        { $badge = ['#fef3c7', '#92400e', 'Blocked']; }
        elseif ($m['available'])  { $badge = ['#dbeafe', '#1e40af', 'Update available']; }
        elseif ($m['latest'])     { $badge = ['#dcfce7', '#166534', 'Up to date']; }
        else                      { $badge = ['#f3f4f6', '#4b5563', 'Not checked yet']; }
        $pill = 'display:inline-block;margin-left:6px;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;';
        ?>
        <div class="card" id="<?php echo esc_attr(self::key('panel')); ?>" style="max-width:none;margin-top:20px;">
            <h2 class="title" style="margin-top:0;">Updates</h2>
            <p class="description" style="margin-top:0;">New versions come from the Powerhouse update server and are checked against a published SHA-256 before they install.</p>
            <table class="form-table" role="presentation" style="margin-top:0;">
                <tr><th scope="row">Installed</th><td><code>v<?php echo esc_html($m['installed']); ?></code></td></tr>
                <tr><th scope="row">Latest release</th><td>
                    <?php if ($m['latest']) : ?><code>v<?php echo esc_html($m['latest']); ?></code><?php endif; ?>
                    <span style="<?php echo esc_attr($pill . 'background:' . $badge[0] . ';color:' . $badge[1] . ';'); ?>"><?php echo esc_html($badge[2]); ?></span>
                </td></tr>
                <tr><th scope="row">Last checked</th><td><?php echo $m['checked_at'] ? esc_html(human_time_diff($m['checked_at'], time()) . ' ago') : 'Never'; ?></td></tr>
                <tr><th scope="row">Update server</th><td><code><?php echo esc_html($m['origin']); ?></code></td></tr>
                <?php if (!empty($m['last_install']['message'])) : ?>
                    <tr><th scope="row">Last install</th><td><?php echo esc_html(($m['last_install']['ok'] ? '' : 'Refused: ') . $m['last_install']['message']); ?>
                        <span class="description">(<?php echo esc_html(human_time_diff((int) $m['last_install']['at'], time()) . ' ago'); ?>)</span></td></tr>
                <?php endif; ?>
            </table>

            <?php if (!empty($m['error'])) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html($m['error']); ?></p></div>
            <?php endif; ?>

            <p style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::$cfg['prefix'] . '_ota_check'), self::key('check'))); ?>">Check for updates</a>
                <?php if ($m['update_url']) : ?>
                    <a class="button button-primary" href="<?php echo esc_url($m['update_url']); ?>">Update now to v<?php echo esc_html($m['latest']); ?></a>
                <?php endif; ?>
                <?php if ($m['latest']) : ?>
                    <a class="thickbox open-plugin-details-modal" href="<?php echo esc_url($m['details_url']); ?>">View details &amp; changelog</a>
                <?php endif; ?>
            </p>

            <?php if ($m['auto_supported']) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="border-top:1px solid #dcdcde;padding-top:12px;">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::$cfg['prefix'] . '_ota_auto'); ?>">
                    <input type="hidden" name="<?php echo esc_attr(self::key('auto_on')); ?>" value="<?php echo $m['auto_enabled'] ? '0' : '1'; ?>">
                    <?php wp_nonce_field(self::key('auto')); ?>
                    <strong>Automatic updates:</strong> <?php echo $m['auto_enabled'] ? 'On' : 'Off'; ?>
                    <button type="submit" class="button button-small" style="margin-left:8px;"><?php echo $m['auto_enabled'] ? 'Disable' : 'Enable'; ?></button>
                    <span class="description" style="display:block;margin-top:6px;">Same switch as the Plugins screen. WordPress installs verified releases in the background.</span>
                </form>
            <?php else : ?>
                <p class="description">Automatic plugin updates are turned off for this whole site. Updates can still be installed with the buttons above.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}

endif;
