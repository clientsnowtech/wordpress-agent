<?php
/**
 * Self-hosted plugin updater for Claude Bridge.
 *
 * Claude Bridge is not on the wp.org repo, so WordPress won't offer updates
 * for it on its own. This polls a JSON manifest ("channel.json") hosted at a
 * public URL and, when the manifest's version is newer than the installed one,
 * shows the normal "Update now" button in Plugins / Dashboard → Updates.
 *
 * Set the manifest URL in wp-config.php (recommended):
 *     define( 'CLAUDE_BRIDGE_UPDATE_MANIFEST', 'https://your-host/claude-bridge/channel.json' );
 * or filter it: add_filter( 'claude_bridge_update_manifest', fn() => '...' );
 *
 * The manifest format is documented in update-channel/channel.json in the repo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Claude_Bridge_Updater {

	private $file;       // plugin main file path
	private $basename;   // e.g. claude-bridge/claude-bridge.php
	private $slug;       // e.g. claude-bridge
	private $version;    // installed version
	private $manifest_url;
	private $cache_key;

	public function __construct( $file, $version ) {
		$this->file         = $file;
		$this->basename     = plugin_basename( $file );
		$this->slug         = dirname( $this->basename );
		if ( '.' === $this->slug ) {
			$this->slug = basename( $this->basename, '.php' );
		}
		$this->version      = $version;
		$this->manifest_url = $this->resolve_manifest_url();
		$this->cache_key    = 'claude_bridge_update_' . md5( (string) $this->manifest_url );

		if ( ! $this->manifest_url ) {
			return; // no channel configured — stay silent
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		// Drop cached manifest right after this plugin updates, so we re-check.
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 2 );
	}

	private function resolve_manifest_url() {
		$url = defined( 'CLAUDE_BRIDGE_UPDATE_MANIFEST' ) ? CLAUDE_BRIDGE_UPDATE_MANIFEST : '';
		return apply_filters( 'claude_bridge_update_manifest', $url );
	}

	/** Fetch + cache the manifest (12h). Returns object or null. */
	private function get_manifest() {
		$cached = get_transient( $this->cache_key );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		$res = wp_remote_get(
			$this->manifest_url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			set_transient( $this->cache_key, '', HOUR_IN_SECONDS ); // brief negative cache
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $res ) );
		if ( ! $data || empty( $data->version ) || empty( $data->download_url ) ) {
			set_transient( $this->cache_key, '', HOUR_IN_SECONDS );
			return null;
		}

		set_transient( $this->cache_key, $data, 12 * HOUR_IN_SECONDS );
		return $data;
	}

	/** Inject an available update into the core update transient. */
	public function inject_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}
		$m = $this->get_manifest();
		if ( ! $m ) {
			return $transient;
		}

		if ( version_compare( $m->version, $this->version, '>' ) ) {
			$obj = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $m->version,
				'package'     => $m->download_url,
				'url'         => isset( $m->homepage ) ? $m->homepage : '',
				'tested'      => isset( $m->tested ) ? $m->tested : '',
				'requires'    => isset( $m->requires ) ? $m->requires : '',
				'requires_php' => isset( $m->requires_php ) ? $m->requires_php : '',
			);
			$transient->response[ $this->basename ] = $obj;
		} else {
			// Mark as up to date so WP doesn't keep nagging on this slug.
			$transient->no_update[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $this->version,
				'package'     => '',
				'url'         => '',
			);
		}
		return $transient;
	}

	/** Feed the "View details" popup. */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}
		$m = $this->get_manifest();
		if ( ! $m ) {
			return $result;
		}

		$info = (object) array(
			'name'          => isset( $m->name ) ? $m->name : 'Claude Bridge',
			'slug'          => $this->slug,
			'version'       => $m->version,
			'author'        => isset( $m->author ) ? $m->author : '',
			'homepage'      => isset( $m->homepage ) ? $m->homepage : '',
			'requires'      => isset( $m->requires ) ? $m->requires : '',
			'tested'        => isset( $m->tested ) ? $m->tested : '',
			'requires_php'  => isset( $m->requires_php ) ? $m->requires_php : '',
			'last_updated'  => isset( $m->last_updated ) ? $m->last_updated : '',
			'download_link' => $m->download_url,
			'sections'      => isset( $m->sections ) ? (array) $m->sections : array(),
		);
		return $info;
	}

	public function flush_cache( $upgrader, $options ) {
		if ( isset( $options['action'], $options['type'] ) && 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			delete_transient( $this->cache_key );
		}
	}
}
