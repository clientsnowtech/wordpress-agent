<?php
/**
 * Plugin Name: WP Claude Agent
 * Description: Connects this WordPress site to Claude Code via a session token + REST API. Lets Claude read/write files, run DB queries, eval PHP, manage plugins/options, and upload media. FULL POWER — use only on sites you own.
 * Version: 1.3.0
 * Author: ClientsNow
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CLAUDE_BRIDGE_NS', 'claude-bridge/v1' );
define( 'CLAUDE_BRIDGE_OPT_HASH', 'claude_bridge_token_hash' );
define( 'CLAUDE_BRIDGE_OPT_EXP', 'claude_bridge_token_expires' );
define( 'CLAUDE_BRIDGE_OPT_CLIENT', 'claude_bridge_client_ip' );   // locks token to one client
define( 'CLAUDE_BRIDGE_OPT_TTL', 'claude_bridge_ttl_seconds' );    // chosen session length
define( 'CLAUDE_BRIDGE_OPT_ENABLED', 'claude_bridge_enabled' );    // master kill switch
define( 'CLAUDE_BRIDGE_OPT_REQUIRE_HTTPS', 'claude_bridge_require_https' );
define( 'CLAUDE_BRIDGE_OPT_ALLOWLIST', 'claude_bridge_ip_allowlist' );  // IP allowlist (admin/option)
define( 'CLAUDE_BRIDGE_OPT_LOG', 'claude_bridge_audit_log' );      // recent operations
define( 'CLAUDE_BRIDGE_OPT_BACKUPS', 'claude_bridge_backups' );    // file-change backup index
define( 'CLAUDE_BRIDGE_BACKUP_MAX', 100 );                        // backups kept
define( 'CLAUDE_BRIDGE_DEFAULT_TTL', 8 * HOUR_IN_SECONDS );        // session length
define( 'CLAUDE_BRIDGE_LOG_MAX', 60 );                             // audit entries kept
define( 'CLAUDE_BRIDGE_MAX_FAILS', 8 );                            // failed token tries...
define( 'CLAUDE_BRIDGE_LOCKOUT', 15 * MINUTE_IN_SECONDS );         // ...before IP lockout
define( 'CLAUDE_BRIDGE_VERSION', '1.3.0' );                        // keep in sync with header

/* -------------------------------------------------------------------------
 * Self-hosted auto-update (polls a channel.json manifest)
 * ---------------------------------------------------------------------- */
require_once __DIR__ . '/includes/updater.php';
add_action(
	'init',
	function () {
		new Claude_Bridge_Updater( __FILE__, CLAUDE_BRIDGE_VERSION );
	}
);

/* -------------------------------------------------------------------------
 * Token helpers
 * ---------------------------------------------------------------------- */

function claude_bridge_generate_token( $ttl = null ) {
	$token = bin2hex( random_bytes( 24 ) ); // 48 hex chars
	if ( $ttl === null ) {
		$ttl = (int) get_option( CLAUDE_BRIDGE_OPT_TTL, CLAUDE_BRIDGE_DEFAULT_TTL );
	}
	$ttl = (int) apply_filters( 'claude_bridge_ttl', max( 300, $ttl ) );
	// New token always invalidates the previous one (one active session only).
	update_option( CLAUDE_BRIDGE_OPT_HASH, hash( 'sha256', $token ), false );
	update_option( CLAUDE_BRIDGE_OPT_EXP, time() + $ttl, false );
	update_option( CLAUDE_BRIDGE_OPT_TTL, $ttl, false );
	delete_option( CLAUDE_BRIDGE_OPT_CLIENT ); // unbind; next caller claims the lock
	claude_bridge_clear_fails();
	claude_bridge_log( 'token.generate', array( 'ttl' => $ttl ), 'admin' );
	return $token;
}

function claude_bridge_revoke_token() {
	delete_option( CLAUDE_BRIDGE_OPT_HASH );
	delete_option( CLAUDE_BRIDGE_OPT_EXP );
	delete_option( CLAUDE_BRIDGE_OPT_CLIENT );
	claude_bridge_log( 'token.revoke', array(), 'admin' );
}

function claude_bridge_client_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
}

/* -------------------------------------------------------------------------
 * Brute-force lockout (per IP, transient-backed)
 * ---------------------------------------------------------------------- */

function claude_bridge_fail_key( $ip ) {
	return 'claude_bridge_fails_' . md5( $ip );
}
function claude_bridge_record_fail( $ip ) {
	$key  = claude_bridge_fail_key( $ip );
	$n    = (int) get_transient( $key );
	$n++;
	set_transient( $key, $n, CLAUDE_BRIDGE_LOCKOUT );
	return $n;
}
function claude_bridge_is_locked_out( $ip ) {
	return (int) get_transient( claude_bridge_fail_key( $ip ) ) >= CLAUDE_BRIDGE_MAX_FAILS;
}
function claude_bridge_clear_fails( $ip = null ) {
	if ( $ip ) {
		delete_transient( claude_bridge_fail_key( $ip ) );
	}
}

/* -------------------------------------------------------------------------
 * Audit log (ring buffer in an option)
 * ---------------------------------------------------------------------- */

function claude_bridge_log( $action, $detail = array(), $ip = null ) {
	$log   = get_option( CLAUDE_BRIDGE_OPT_LOG, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$log[] = array(
		'time'   => time(),
		'ip'     => $ip !== null ? $ip : claude_bridge_client_ip(),
		'action' => $action,
		'detail' => $detail,
	);
	if ( count( $log ) > CLAUDE_BRIDGE_LOG_MAX ) {
		$log = array_slice( $log, -CLAUDE_BRIDGE_LOG_MAX );
	}
	update_option( CLAUDE_BRIDGE_OPT_LOG, $log, false );
}

/* -------------------------------------------------------------------------
 * File-change backups — every write/delete snapshots the prior state so it
 * can be reverted. Backups live in uploads/claude-bridge-backups (web-denied).
 * ---------------------------------------------------------------------- */

function claude_bridge_backup_dir() {
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'claude-bridge-backups';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		@file_put_contents( $dir . '/index.php', "<?php // silence is golden\n" );
	}
	return $dir;
}

// Snapshot $path before it is changed. action: write|delete|pre-revert. Returns entry id.
function claude_bridge_record_backup( $path, $action ) {
	$path        = wp_normalize_path( $path );
	$existed     = is_file( $path );
	$backup_name = null;
	$size        = 0;

	if ( $existed ) {
		$data        = file_get_contents( $path );
		$size        = strlen( $data );
		$backup_name = md5( $path ) . '-' . time() . '-' . wp_generate_password( 6, false ) . '.bak';
		file_put_contents( trailingslashit( claude_bridge_backup_dir() ) . $backup_name, $data );
	}

	$idx = get_option( CLAUDE_BRIDGE_OPT_BACKUPS, array() );
	if ( ! is_array( $idx ) ) {
		$idx = array();
	}
	$idx[] = array(
		'id'       => uniqid( 'cb', true ),
		'time'     => time(),
		'path'     => $path,
		'action'   => $action,
		'existed'  => $existed,   // false => revert means "remove the new file"
		'backup'   => $backup_name,
		'size'     => $size,
		'reverted' => false,
	);

	// Prune oldest, delete their .bak files.
	if ( count( $idx ) > CLAUDE_BRIDGE_BACKUP_MAX ) {
		$drop = array_slice( $idx, 0, count( $idx ) - CLAUDE_BRIDGE_BACKUP_MAX );
		foreach ( $drop as $d ) {
			if ( ! empty( $d['backup'] ) ) {
				@unlink( trailingslashit( claude_bridge_backup_dir() ) . $d['backup'] );
			}
		}
		$idx = array_slice( $idx, -CLAUDE_BRIDGE_BACKUP_MAX );
	}

	update_option( CLAUDE_BRIDGE_OPT_BACKUPS, $idx, false );
	return $idx[ count( $idx ) - 1 ]['id'];
}

function claude_bridge_get_bearer( WP_REST_Request $request ) {
	// Apache often strips Authorization; support a fallback header too.
	$auth = $request->get_header( 'authorization' );
	if ( $auth && stripos( $auth, 'bearer ' ) === 0 ) {
		return trim( substr( $auth, 7 ) );
	}
	$alt = $request->get_header( 'x_claude_token' );
	if ( $alt ) {
		return trim( $alt );
	}
	return '';
}

function claude_bridge_check_auth( WP_REST_Request $request ) {
	$ip = claude_bridge_client_ip();

	// Master kill switch.
	if ( ! get_option( CLAUDE_BRIDGE_OPT_ENABLED, 1 ) ) {
		return new WP_Error( 'disabled', 'WP Claude Agent is disabled.', array( 'status' => 403 ) );
	}

	// IP allowlist: when configured, only listed IPs may use the bridge — a valid
	// token from any other IP is still rejected. Empty list = no IP restriction.
	$allow = claude_bridge_ip_allowlist();
	if ( ! empty( $allow ) && ! in_array( $ip, $allow, true ) ) {
		claude_bridge_log( 'ip.blocked', array(), $ip );
		return new WP_Error( 'ip_blocked', 'Your IP is not allowlisted for WP Claude Agent.', array( 'status' => 403 ) );
	}

	// Brute-force lockout.
	if ( claude_bridge_is_locked_out( $ip ) ) {
		return new WP_Error( 'locked_out', 'Too many failed attempts. Try again later.', array( 'status' => 429 ) );
	}

	// Require HTTPS for non-local requests when enabled.
	if ( get_option( CLAUDE_BRIDGE_OPT_REQUIRE_HTTPS, 0 ) && ! is_ssl() && ! claude_bridge_is_local_ip( $ip ) ) {
		return new WP_Error( 'insecure', 'HTTPS required.', array( 'status' => 403 ) );
	}

	// Static token mode: a permanent token defined in wp-config.php
	// ( define( 'CLAUDE_BRIDGE_STATIC_TOKEN', '...' ); ). Set-and-forget — no
	// expiry and no single-client lock, so the MCP config never changes. Access
	// is still gated by the IP allowlist above and the brute-force lockout.
	// When this constant is set it fully governs auth; the session-key flow is skipped.
	if ( defined( 'CLAUDE_BRIDGE_STATIC_TOKEN' ) && CLAUDE_BRIDGE_STATIC_TOKEN ) {
		$token = claude_bridge_get_bearer( $request );
		if ( $token && hash_equals( (string) CLAUDE_BRIDGE_STATIC_TOKEN, $token ) ) {
			claude_bridge_clear_fails( $ip );
			return true;
		}
		$n = claude_bridge_record_fail( $ip );
		claude_bridge_log( 'auth.fail', array( 'attempt' => $n, 'mode' => 'static' ), $ip );
		return new WP_Error( 'bad_token', 'Invalid token.', array( 'status' => 403 ) );
	}

	$hash = get_option( CLAUDE_BRIDGE_OPT_HASH );
	$exp  = (int) get_option( CLAUDE_BRIDGE_OPT_EXP );

	if ( empty( $hash ) ) {
		return new WP_Error( 'no_token', 'No session token active. Generate one in WP admin → Tools → WP Claude Agent.', array( 'status' => 401 ) );
	}
	if ( time() > $exp ) {
		claude_bridge_revoke_token();
		return new WP_Error( 'expired', 'Session token expired. Generate a new one.', array( 'status' => 401 ) );
	}
	$token = claude_bridge_get_bearer( $request );
	if ( ! $token || ! hash_equals( $hash, hash( 'sha256', $token ) ) ) {
		$n = claude_bridge_record_fail( $ip );
		claude_bridge_log( 'auth.fail', array( 'attempt' => $n ), $ip );
		return new WP_Error( 'bad_token', 'Invalid token.', array( 'status' => 403 ) );
	}

	// Single-client lock: first caller claims the token; others rejected.
	$owner = get_option( CLAUDE_BRIDGE_OPT_CLIENT );
	if ( empty( $owner ) ) {
		update_option( CLAUDE_BRIDGE_OPT_CLIENT, $ip, false );
		claude_bridge_log( 'client.claim', array(), $ip );
	} elseif ( ! hash_equals( (string) $owner, $ip ) ) {
		claude_bridge_log( 'client.rejected', array( 'owner' => $owner ), $ip );
		return new WP_Error( 'locked', 'Token already in use by another client. Only one remote access allowed; generate a new token.', array( 'status' => 423 ) );
	}

	claude_bridge_clear_fails( $ip );
	return true;
}

/**
 * Allowed client IPs. Merges the wp-config constant CLAUDE_BRIDGE_IP_ALLOWLIST
 * (comma/space/newline separated) with the admin option, plus a filter hook.
 * Empty result = no IP restriction (token-only auth).
 */
function claude_bridge_ip_allowlist() {
	$raw = array();
	if ( defined( 'CLAUDE_BRIDGE_IP_ALLOWLIST' ) && CLAUDE_BRIDGE_IP_ALLOWLIST ) {
		$raw[] = (string) CLAUDE_BRIDGE_IP_ALLOWLIST;
	}
	$opt = get_option( CLAUDE_BRIDGE_OPT_ALLOWLIST, '' );
	if ( $opt ) {
		$raw[] = (string) $opt;
	}
	$list = array();
	foreach ( $raw as $chunk ) {
		foreach ( preg_split( '/[\s,]+/', $chunk, -1, PREG_SPLIT_NO_EMPTY ) as $ip ) {
			$list[] = trim( $ip );
		}
	}
	$list = array_values( array_unique( array_filter( $list ) ) );
	return apply_filters( 'claude_bridge_ip_allowlist', $list );
}

function claude_bridge_is_local_ip( $ip ) {
	return in_array( $ip, array( '127.0.0.1', '::1', '' ), true ) || preg_match( '/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip );
}

/* -------------------------------------------------------------------------
 * Path resolution — relative paths are anchored to ABSPATH
 * ---------------------------------------------------------------------- */

function claude_bridge_resolve_path( $path ) {
	$path = (string) $path;
	// Treat as absolute if it looks absolute (unix / or windows C:\)
	if ( preg_match( '#^([a-zA-Z]:[\\\\/]|/)#', $path ) ) {
		return wp_normalize_path( $path );
	}
	return wp_normalize_path( rtrim( ABSPATH, '/\\' ) . '/' . ltrim( $path, '/\\' ) );
}

/* -------------------------------------------------------------------------
 * REST routes
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', function () {
	$auth = 'claude_bridge_check_auth';

	$route = function ( $path, $cb, $methods = 'POST' ) use ( $auth ) {
		register_rest_route( CLAUDE_BRIDGE_NS, $path, array(
			'methods'             => $methods,
			'callback'            => function ( $request ) use ( $cb, $path ) {
				// Log the op (without full payloads) then run it.
				$p = $request->get_param( 'path' );
				$detail = array();
				if ( $p ) {
					$detail['path'] = $p;
				}
				if ( $path === '/db/query' ) {
					$detail['sql'] = substr( (string) $request->get_param( 'sql' ), 0, 200 );
				}
				claude_bridge_log( $path, $detail );
				return call_user_func( $cb, $request );
			},
			'permission_callback' => $auth,
		) );
	};

	$route( '/ping', 'claude_bridge_ep_ping', 'GET' );
	$route( '/fs/read', 'claude_bridge_ep_fs_read' );
	$route( '/fs/write', 'claude_bridge_ep_fs_write' );
	$route( '/fs/list', 'claude_bridge_ep_fs_list' );
	$route( '/fs/delete', 'claude_bridge_ep_fs_delete' );
	$route( '/fs/revert', 'claude_bridge_ep_fs_revert' );
	$route( '/fs/history', 'claude_bridge_ep_fs_history' );
	$route( '/db/query', 'claude_bridge_ep_db_query' );
	$route( '/php/eval', 'claude_bridge_ep_php_eval' );
	$route( '/plugins/list', 'claude_bridge_ep_plugins_list' );
	$route( '/plugins/install', 'claude_bridge_ep_plugins_install' );
	$route( '/plugins/activate', 'claude_bridge_ep_plugins_activate' );
	$route( '/options/get', 'claude_bridge_ep_options_get' );
	$route( '/options/set', 'claude_bridge_ep_options_set' );
	$route( '/media/upload', 'claude_bridge_ep_media_upload' );
} );

/* ----------------------------- endpoints ------------------------------- */

function claude_bridge_ep_ping() {
	global $wp_version;
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$theme = wp_get_theme();
	return array(
		'ok'          => true,
		'wp_version'  => $wp_version,
		'php_version' => PHP_VERSION,
		'abspath'     => wp_normalize_path( ABSPATH ),
		'site_url'    => site_url(),
		'home_url'    => home_url(),
		'theme'       => array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ), 'stylesheet' => get_stylesheet() ),
		'active_plugins' => get_option( 'active_plugins', array() ),
	);
}

function claude_bridge_ep_fs_read( WP_REST_Request $r ) {
	$path = claude_bridge_resolve_path( $r->get_param( 'path' ) );
	if ( ! is_file( $path ) ) {
		return new WP_Error( 'not_found', "File not found: $path", array( 'status' => 404 ) );
	}
	$content = file_get_contents( $path );
	return array(
		'path'           => $path,
		'size'           => strlen( $content ),
		'content_base64' => base64_encode( $content ),
	);
}

function claude_bridge_ep_fs_write( WP_REST_Request $r ) {
	$path = claude_bridge_resolve_path( $r->get_param( 'path' ) );
	$b64  = $r->get_param( 'content_base64' );
	$raw  = $r->get_param( 'content' );
	$data = ( $b64 !== null ) ? base64_decode( $b64 ) : (string) $raw;

	$backup_id = claude_bridge_record_backup( $path, 'write' ); // snapshot before overwrite

	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	$bytes = file_put_contents( $path, $data );
	if ( $bytes === false ) {
		return new WP_Error( 'write_failed', "Could not write: $path", array( 'status' => 500 ) );
	}
	return array( 'path' => $path, 'bytes' => $bytes, 'backup_id' => $backup_id );
}

function claude_bridge_ep_fs_list( WP_REST_Request $r ) {
	$path = claude_bridge_resolve_path( $r->get_param( 'path' ) ?: '.' );
	if ( ! is_dir( $path ) ) {
		return new WP_Error( 'not_dir', "Not a directory: $path", array( 'status' => 404 ) );
	}
	$items = array();
	foreach ( scandir( $path ) as $name ) {
		if ( $name === '.' || $name === '..' ) {
			continue;
		}
		$full = wp_normalize_path( $path . '/' . $name );
		$items[] = array(
			'name' => $name,
			'type' => is_dir( $full ) ? 'dir' : 'file',
			'size' => is_file( $full ) ? filesize( $full ) : null,
		);
	}
	return array( 'path' => $path, 'items' => $items );
}

function claude_bridge_ep_fs_delete( WP_REST_Request $r ) {
	$path = claude_bridge_resolve_path( $r->get_param( 'path' ) );
	if ( is_dir( $path ) ) {
		return new WP_Error( 'is_dir', 'Refusing to delete a directory via this endpoint.', array( 'status' => 400 ) );
	}
	if ( ! is_file( $path ) ) {
		return new WP_Error( 'not_found', "File not found: $path", array( 'status' => 404 ) );
	}
	$backup_id = claude_bridge_record_backup( $path, 'delete' ); // snapshot before unlink
	$ok        = unlink( $path );
	return array( 'path' => $path, 'deleted' => $ok, 'backup_id' => $backup_id );
}

// Revert a file change. Param: id (exact backup) OR path (latest non-reverted for that path).
function claude_bridge_ep_fs_revert( WP_REST_Request $r ) {
	$id   = $r->get_param( 'id' );
	$path = $r->get_param( 'path' );
	$idx  = get_option( CLAUDE_BRIDGE_OPT_BACKUPS, array() );
	if ( ! is_array( $idx ) || ! $idx ) {
		return new WP_Error( 'no_backups', 'No backups recorded.', array( 'status' => 404 ) );
	}

	$target = null;
	if ( $id ) {
		foreach ( $idx as $e ) {
			if ( $e['id'] === $id ) {
				$target = $e;
				break;
			}
		}
	} else {
		$rp = $path ? wp_normalize_path( claude_bridge_resolve_path( $path ) ) : null;
		for ( $i = count( $idx ) - 1; $i >= 0; $i-- ) {
			if ( empty( $idx[ $i ]['reverted'] ) && ( ! $rp || $idx[ $i ]['path'] === $rp ) ) {
				$target = $idx[ $i ];
				break;
			}
		}
	}
	if ( ! $target ) {
		return new WP_Error( 'not_found', 'No matching backup to revert.', array( 'status' => 404 ) );
	}

	$fpath = $target['path'];
	claude_bridge_record_backup( $fpath, 'pre-revert' ); // make the revert itself reversible

	if ( $target['existed'] && $target['backup'] ) {
		$bfile = trailingslashit( claude_bridge_backup_dir() ) . $target['backup'];
		if ( ! is_file( $bfile ) ) {
			return new WP_Error( 'backup_gone', 'Backup data missing (pruned).', array( 'status' => 410 ) );
		}
		$data = file_get_contents( $bfile );
		$dir  = dirname( $fpath );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$bytes  = file_put_contents( $fpath, $data );
		$result = array( 'reverted' => true, 'path' => $fpath, 'restored_bytes' => $bytes );
	} else {
		// File did not exist before the change → revert removes it.
		if ( is_file( $fpath ) ) {
			@unlink( $fpath );
		}
		$result = array( 'reverted' => true, 'path' => $fpath, 'removed' => true );
	}

	// Mark the target reverted (re-read: record_backup mutated the option).
	$idx = get_option( CLAUDE_BRIDGE_OPT_BACKUPS, array() );
	foreach ( $idx as $i => $e ) {
		if ( $e['id'] === $target['id'] ) {
			$idx[ $i ]['reverted'] = true;
			break;
		}
	}
	update_option( CLAUDE_BRIDGE_OPT_BACKUPS, $idx, false );

	$result['from_action'] = $target['action'];
	return $result;
}

// List backup history, newest first. Optional path filter.
function claude_bridge_ep_fs_history( WP_REST_Request $r ) {
	$path = $r->get_param( 'path' );
	$rp   = $path ? wp_normalize_path( claude_bridge_resolve_path( $path ) ) : null;
	$idx  = get_option( CLAUDE_BRIDGE_OPT_BACKUPS, array() );
	if ( ! is_array( $idx ) ) {
		$idx = array();
	}
	$out = array();
	foreach ( array_reverse( $idx ) as $e ) {
		if ( $rp && $e['path'] !== $rp ) {
			continue;
		}
		$out[] = array(
			'id'       => $e['id'],
			'time'     => $e['time'],
			'path'     => $e['path'],
			'action'   => $e['action'],
			'existed'  => $e['existed'],
			'size'     => $e['size'],
			'reverted' => ! empty( $e['reverted'] ),
		);
	}
	return array( 'history' => $out, 'count' => count( $out ) );
}

function claude_bridge_ep_db_query( WP_REST_Request $r ) {
	global $wpdb;
	$sql = (string) $r->get_param( 'sql' );
	if ( $sql === '' ) {
		return new WP_Error( 'no_sql', 'sql param required.', array( 'status' => 400 ) );
	}
	$wpdb->hide_errors();
	if ( preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|PRAGMA)\b/i', $sql ) ) {
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array( 'type' => 'read', 'rows' => $rows, 'count' => is_array( $rows ) ? count( $rows ) : 0, 'error' => $wpdb->last_error ?: null );
	}
	$affected = $wpdb->query( $sql );
	return array( 'type' => 'write', 'affected' => $affected, 'insert_id' => $wpdb->insert_id, 'error' => $wpdb->last_error ?: null );
}

function claude_bridge_ep_php_eval( WP_REST_Request $r ) {
	$code = (string) $r->get_param( 'code' );
	ob_start();
	$return = null;
	$error  = null;
	try {
		$return = eval( $code );
	} catch ( \Throwable $e ) {
		$error = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
	}
	$output = ob_get_clean();
	return array(
		'output' => $output,
		'return' => $return,
		'error'  => $error,
	);
}

function claude_bridge_ep_plugins_list() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$all    = get_plugins();
	$active = get_option( 'active_plugins', array() );
	$out    = array();
	foreach ( $all as $file => $data ) {
		$out[] = array(
			'file'    => $file,
			'name'    => $data['Name'],
			'version' => $data['Version'],
			'active'  => in_array( $file, $active, true ),
		);
	}
	return array( 'plugins' => $out );
}

function claude_bridge_ep_plugins_install( WP_REST_Request $r ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$slug    = $r->get_param( 'slug' );
	$zip_url = $r->get_param( 'zip_url' );

	$source = '';
	if ( $zip_url ) {
		$source = esc_url_raw( $zip_url );
	} elseif ( $slug ) {
		$api = plugins_api( 'plugin_information', array( 'slug' => sanitize_key( $slug ), 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$source = $api->download_link;
	} else {
		return new WP_Error( 'no_source', 'Provide slug (wp.org) or zip_url.', array( 'status' => 400 ) );
	}

	$skin     = new Automatic_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->install( $source );

	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( $result === false ) {
		return new WP_Error( 'install_failed', 'Install failed.', array( 'status' => 500, 'messages' => $skin->get_upgrade_messages() ) );
	}
	return array( 'installed' => true, 'plugin' => $upgrader->plugin_info(), 'messages' => $skin->get_upgrade_messages() );
}

function claude_bridge_ep_plugins_activate( WP_REST_Request $r ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$plugin = (string) $r->get_param( 'plugin' );
	$res    = activate_plugin( $plugin );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return array( 'activated' => true, 'plugin' => $plugin );
}

function claude_bridge_ep_options_get( WP_REST_Request $r ) {
	$name = (string) $r->get_param( 'name' );
	return array( 'name' => $name, 'value' => get_option( $name, null ) );
}

function claude_bridge_ep_options_set( WP_REST_Request $r ) {
	$name  = (string) $r->get_param( 'name' );
	$value = $r->get_param( 'value' );
	$ok    = update_option( $name, $value );
	return array( 'name' => $name, 'updated' => $ok );
}

function claude_bridge_ep_media_upload( WP_REST_Request $r ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$filename = sanitize_file_name( $r->get_param( 'filename' ) ?: 'upload.bin' );
	$b64      = $r->get_param( 'content_base64' );
	if ( ! $b64 ) {
		return new WP_Error( 'no_content', 'content_base64 required.', array( 'status' => 400 ) );
	}
	$data = base64_decode( $b64 );

	$upload = wp_upload_bits( $filename, null, $data );
	if ( ! empty( $upload['error'] ) ) {
		return new WP_Error( 'upload_error', $upload['error'], array( 'status' => 500 ) );
	}

	$filetype = wp_check_filetype( $upload['file'] );
	$attach_id = wp_insert_attachment( array(
		'post_mime_type' => $filetype['type'],
		'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
		'post_status'    => 'inherit',
	), $upload['file'] );

	$meta = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
	wp_update_attachment_metadata( $attach_id, $meta );

	return array( 'attachment_id' => $attach_id, 'url' => $upload['url'], 'file' => $upload['file'] );
}

/* -------------------------------------------------------------------------
 * Admin UI — Tools → WP Claude Agent
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_management_page( 'WP Claude Agent', 'WP Claude Agent', 'manage_options', 'claude-bridge', 'claude_bridge_admin_page' );
} );

function claude_bridge_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden' );
	}

	$new_token = null;

	if ( isset( $_POST['claude_bridge_action'] ) && check_admin_referer( 'claude_bridge_nonce' ) ) {
		switch ( $_POST['claude_bridge_action'] ) {
			case 'generate':
				$ttl       = isset( $_POST['ttl'] ) ? (int) $_POST['ttl'] : CLAUDE_BRIDGE_DEFAULT_TTL;
				$new_token = claude_bridge_generate_token( $ttl );
				break;
			case 'revoke':
				claude_bridge_revoke_token();
				break;
			case 'toggle':
				update_option( CLAUDE_BRIDGE_OPT_ENABLED, get_option( CLAUDE_BRIDGE_OPT_ENABLED, 1 ) ? 0 : 1, false );
				break;
			case 'https':
				update_option( CLAUDE_BRIDGE_OPT_REQUIRE_HTTPS, isset( $_POST['require_https'] ) ? 1 : 0, false );
				break;
			case 'clearlog':
				update_option( CLAUDE_BRIDGE_OPT_LOG, array(), false );
				break;
		}
	}

	$exp        = (int) get_option( CLAUDE_BRIDGE_OPT_EXP );
	$has_active = get_option( CLAUDE_BRIDGE_OPT_HASH ) && time() < $exp;
	$enabled    = (int) get_option( CLAUDE_BRIDGE_OPT_ENABLED, 1 );
	$req_https  = (int) get_option( CLAUDE_BRIDGE_OPT_REQUIRE_HTTPS, 0 );
	$owner      = get_option( CLAUDE_BRIDGE_OPT_CLIENT );
	$base_url   = rest_url( CLAUDE_BRIDGE_NS );
	$is_https   = ( strpos( $base_url, 'https://' ) === 0 );
	$mcp_path   = wp_normalize_path( WP_PLUGIN_DIR ); // hint only
	$log        = array_reverse( (array) get_option( CLAUDE_BRIDGE_OPT_LOG, array() ) );
	?>
	<style>
		.cb-wrap{max-width:920px}
		.cb-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 22px;margin:16px 0;box-shadow:0 1px 2px rgba(0,0,0,.04)}
		.cb-card h2{margin-top:0;font-size:15px}
		.cb-badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600}
		.cb-on{background:#e6f4ea;color:#137333}.cb-off{background:#f1f1f1;color:#777}.cb-warn{background:#fef7e0;color:#a36a00}.cb-bad{background:#fce8e6;color:#b32d2e}
		.cb-grid{display:grid;grid-template-columns:160px 1fr;gap:8px 16px;align-items:center}
		.cb-grid div:nth-child(odd){color:#646970;font-size:13px}
		.cb-code{font-family:Menlo,Consolas,monospace;background:#1e1e1e;color:#eaeaea;padding:12px 14px;border-radius:6px;white-space:pre-wrap;word-break:break-all;font-size:12.5px;position:relative}
		.cb-copy{position:absolute;top:8px;right:8px;cursor:pointer;background:#333;color:#fff;border:0;border-radius:4px;padding:3px 10px;font-size:11px}
		.cb-copy:hover{background:#555}
		.cb-danger{border-color:#f3c2c0;background:#fdf5f5}
		.cb-log{width:100%;border-collapse:collapse;font-size:12.5px}
		.cb-log th,.cb-log td{text-align:left;padding:5px 8px;border-bottom:1px solid #eee}
		.cb-log code{font-size:12px}
		.cb-cap{columns:2;font-size:13px;color:#444}
	</style>
	<div class="wrap cb-wrap">
		<h1>🔌 WP Claude Agent</h1>

		<div class="cb-card cb-danger">
			<strong style="color:#b32d2e">⚠ Full access:</strong> a session token grants complete file, database and PHP (RCE) control of this site. Share only with your own Claude Code. Generating a new key kills the old one; one client may use a token at a time.
		</div>

		<!-- STATUS -->
		<div class="cb-card">
			<h2>Status</h2>
			<div class="cb-grid">
				<div>Bridge</div><div>
					<span class="cb-badge <?php echo $enabled ? 'cb-on' : 'cb-off'; ?>"><?php echo $enabled ? 'Enabled' : 'Disabled'; ?></span>
					<form method="post" style="display:inline;margin-left:8px">
						<?php wp_nonce_field( 'claude_bridge_nonce' ); ?>
						<input type="hidden" name="claude_bridge_action" value="toggle" />
						<button class="button button-small"><?php echo $enabled ? 'Disable' : 'Enable'; ?></button>
					</form>
				</div>
				<div>Token</div><div><?php echo $has_active
					? '<span class="cb-badge cb-on">Active</span> expires <strong>' . esc_html( date_i18n( 'Y-m-d H:i', $exp ) ) . '</strong>'
					: '<span class="cb-badge cb-off">None</span>'; ?></div>
				<div>Locked to client</div><div><?php echo $owner ? '<code>' . esc_html( $owner ) . '</code>' : '<span class="cb-badge cb-warn">unclaimed</span> <span style="color:#777">first caller claims it</span>'; ?></div>
				<div>Transport</div><div><?php echo $is_https ? '<span class="cb-badge cb-on">HTTPS</span>' : '<span class="cb-badge cb-bad">HTTP</span> <span style="color:#b32d2e">insecure for remote use</span>'; ?></div>
				<div>API base URL</div><div><code><?php echo esc_html( $base_url ); ?></code></div>
			</div>
		</div>

		<?php if ( $new_token ) : ?>
		<!-- NEW TOKEN -->
		<div class="cb-card" style="border-color:#aedcb6">
			<h2>✅ New session key — copy now (shown once)</h2>
			<div class="cb-code" id="cb-token"><button type="button" class="cb-copy" data-copy="cb-token">Copy</button><?php echo esc_html( $new_token ); ?></div>
			<p style="margin-bottom:6px">Paste into Claude Code (one line):</p>
			<div class="cb-code" id="cb-cmd"><button type="button" class="cb-copy" data-copy="cb-cmd">Copy</button><?php
				echo esc_html(
					'claude mcp add wp-bridge '
					. '--env WP_BRIDGE_URL=' . $base_url . ' '
					. '--env WP_BRIDGE_TOKEN=' . $new_token . ' '
					. '-- node "' . $mcp_path . '/../../wordpress-agent/mcp-server/index.js"'
				);
			?></div>
			<p style="color:#777;font-size:12px">Adjust the path to wherever <code>mcp-server/index.js</code> lives on your machine.</p>
		</div>
		<?php endif; ?>

		<!-- GENERATE -->
		<div class="cb-card">
			<h2>Generate session key</h2>
			<form method="post">
				<?php wp_nonce_field( 'claude_bridge_nonce' ); ?>
				<input type="hidden" name="claude_bridge_action" value="generate" />
				<label>Session length:
					<select name="ttl">
						<option value="3600">1 hour</option>
						<option value="14400">4 hours</option>
						<option value="28800" selected>8 hours</option>
						<option value="86400">24 hours</option>
					</select>
				</label>
				<?php submit_button( 'Generate New Session Key', 'primary', 'submit', false ); ?>
			</form>
			<?php if ( $has_active ) : ?>
			<form method="post" style="margin-top:10px">
				<?php wp_nonce_field( 'claude_bridge_nonce' ); ?>
				<input type="hidden" name="claude_bridge_action" value="revoke" />
				<?php submit_button( 'Revoke Active Token', 'delete', 'submit', false ); ?>
			</form>
			<?php endif; ?>
		</div>

		<!-- SECURITY -->
		<div class="cb-card">
			<h2>Security</h2>
			<form method="post">
				<?php wp_nonce_field( 'claude_bridge_nonce' ); ?>
				<input type="hidden" name="claude_bridge_action" value="https" />
				<label><input type="checkbox" name="require_https" <?php checked( $req_https, 1 ); ?> /> Require HTTPS for remote requests (local IPs exempt)</label>
				<?php submit_button( 'Save', 'secondary', 'submit', false ); ?>
			</form>
			<p style="color:#646970;font-size:12.5px;margin-top:12px">
				Brute force: after <?php echo (int) CLAUDE_BRIDGE_MAX_FAILS; ?> bad token attempts an IP is locked out for <?php echo (int) ( CLAUDE_BRIDGE_LOCKOUT / 60 ); ?> min.
				Token is SHA-256 hashed at rest and compared in constant time.
			</p>
		</div>

		<!-- CAPABILITIES -->
		<div class="cb-card">
			<h2>What Claude can do once connected</h2>
			<div class="cb-cap">
				• Read / write / delete any file (themes, BeTheme, Elementor, plugins)<br>
				• Revert any write/delete — every change is auto-backed-up<br>
				• Run raw SQL on the database<br>
				• Execute PHP inside WordPress<br>
				• Install &amp; activate plugins<br>
				• Get / set any option<br>
				• Upload media from your machine
			</div>
		</div>

		<!-- AUDIT LOG -->
		<div class="cb-card">
			<h2>Recent activity <span style="font-weight:400;color:#777">(last <?php echo (int) CLAUDE_BRIDGE_LOG_MAX; ?>)</span></h2>
			<?php if ( empty( $log ) ) : ?>
				<p style="color:#777">No activity yet.</p>
			<?php else : ?>
			<table class="cb-log">
				<thead><tr><th>Time</th><th>IP</th><th>Action</th><th>Detail</th></tr></thead>
				<tbody>
				<?php foreach ( $log as $row ) : ?>
					<tr>
						<td><?php echo esc_html( date_i18n( 'm-d H:i:s', $row['time'] ) ); ?></td>
						<td><code><?php echo esc_html( $row['ip'] ); ?></code></td>
						<td><code><?php echo esc_html( $row['action'] ); ?></code></td>
						<td><?php echo $row['detail'] ? '<code>' . esc_html( wp_json_encode( $row['detail'] ) ) . '</code>' : ''; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" style="margin-top:10px">
				<?php wp_nonce_field( 'claude_bridge_nonce' ); ?>
				<input type="hidden" name="claude_bridge_action" value="clearlog" />
				<button class="button button-small">Clear log</button>
			</form>
			<?php endif; ?>
		</div>
	</div>
	<script>
	document.querySelectorAll('.cb-copy').forEach(function(b){
		b.addEventListener('click',function(){
			var el=document.getElementById(b.dataset.copy);
			var t=el.innerText.replace(/^Copy/,'').trim();
			navigator.clipboard.writeText(t).then(function(){b.textContent='Copied';setTimeout(function(){b.textContent='Copy';},1200);});
		});
	});
	</script>
	<?php
}
