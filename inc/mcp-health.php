<?php
/**
 * Discover Asian World: keeps the AI connectors' plumbing healthy.
 *
 * The Easy MCP AI diagnostics card flagged two things the site itself can
 * fix, without anyone opening a settings screen:
 *
 * 1. The .htaccess line that hands the Authorization header to PHP. Without
 *    it some servers drop a bearer token before WordPress sees it, and the
 *    connection fails as if the token were wrong. A small block is written
 *    at the top of .htaccess (the same way the AI-crawler block is), the
 *    home page is fetched straight after, and anything but a normal answer
 *    puts the old file back.
 * 2. LiteSpeed must never cache the connector's sign-in and API answers: a
 *    cached answer can make a connection work once and then fail, or keep
 *    working after a token is revoked. The three paths are added to
 *    LiteSpeed's "Do Not Cache URIs" list, and every request to them is
 *    marked no-cache at run time as well, in case the list is edited later.
 *
 * Both run once per version of this file, from an administrator's page view.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_MCPH_VER = '2';

/** Paths that must reach WordPress fresh every time. */
function daw_mcph_paths() {
	return array( '/wp-json/easy-mcp-ai/', '/.well-known/oauth-', '/.well-known/openid-configuration' );
}

/* ======================================================== run-time no-cache == */

add_action( 'init', 'daw_mcph_nocache', 0 );
function daw_mcph_nocache() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	foreach ( daw_mcph_paths() as $p ) {
		if ( 0 === strpos( $uri, $p ) ) {
			do_action( 'litespeed_control_set_nocache', 'daw: AI connector endpoint' );
			if ( ! headers_sent() ) {
				header( 'Cache-Control: no-store, private' );
			}
			return;
		}
	}
}

/* ================================================== LiteSpeed exclude list == */

function daw_mcph_lscache_exclude() {
	if ( ! defined( 'LSCWP_V' ) ) {
		return 'no-litespeed';
	}
	// LiteSpeed keeps its lists as a JSON string ("[]"), not a PHP array.
	// Version 1 of this file wrote an array and kept "[]" as an entry; this
	// reads either form and writes LiteSpeed's own.
	$name = 'litespeed.conf.cache-exc';
	$cur  = get_option( $name, '[]' );
	if ( is_string( $cur ) ) {
		$dec = json_decode( $cur, true );
		$cur = is_array( $dec ) ? $dec : preg_split( '/\R/', $cur );
	}
	$cur  = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $cur ) ), static function ( $v ) {
		return '' !== $v && '[]' !== $v;
	} ) );
	$want = $cur;
	foreach ( daw_mcph_paths() as $p ) {
		if ( ! in_array( $p, $want, true ) ) {
			$want[] = $p;
		}
	}
	if ( class_exists( '\LiteSpeed\Conf' ) && method_exists( '\LiteSpeed\Conf', 'cls' ) ) {
		$conf = \LiteSpeed\Conf::cls();
		if ( method_exists( $conf, 'update_confs' ) ) {
			$conf->update_confs( array( 'cache-exc' => $want ) );
		}
	}
	$saved = get_option( $name, '' );
	if ( ! is_string( $saved ) || json_decode( $saved, true ) !== $want ) {
		update_option( $name, wp_json_encode( $want, JSON_UNESCAPED_SLASHES ) );
		$saved = get_option( $name, '' );
	}
	$check = is_string( $saved ) ? (array) json_decode( $saved, true ) : array();
	return ! array_diff( daw_mcph_paths(), $check ) ? 'ok' : 'no';
}

/* ================================================ Authorization in .htaccess == */

function daw_mcph_auth_lines() {
	return array(
		'<IfModule mod_rewrite.c>',
		'RewriteEngine On',
		'RewriteCond %{HTTP:Authorization} ^(.+)$',
		'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
		'</IfModule>',
		'<IfModule mod_setenvif.c>',
		'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1',
		'</IfModule>',
	);
}

/** Writes (or with no lines removes) the block at the top, checked by a fetch. */
function daw_mcph_auth_write( $lines ) {
	$file = ABSPATH . '.htaccess';
	if ( ! file_exists( $file ) || ! is_writable( $file ) ) {
		return false;
	}
	$old   = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$clean = (string) preg_replace( '/# BEGIN DAW Authorization header.*?# END DAW Authorization header\R*/s', '', $old );
	$new   = $lines ? "# BEGIN DAW Authorization header\n" . implode( "\n", $lines ) . "\n# END DAW Authorization header\n\n" . $clean : $clean;
	if ( $new === $old ) {
		return true;
	}
	if ( false === file_put_contents( $file, $new, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false;
	}
	$res  = wp_remote_get( add_query_arg( 'daw_ht', time(), home_url( '/' ) ), array( 'timeout' => 15, 'redirection' => 2 ) );
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	if ( $code < 200 || $code >= 400 ) {
		file_put_contents( $file, $old, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false;
	}
	return true;
}

/* ===================================================================== run == */

add_action( 'admin_init', 'daw_mcph_install', 50 );
// Also from the hourly repair run, so no one has to open wp-admin for it.
add_action( 'daw_fix_tick', 'daw_mcph_install', 60 );
function daw_mcph_install() {
	if ( get_option( 'daw_mcph_ver' ) === DAW_MCPH_VER || ( ! wp_doing_cron() && ! current_user_can( 'manage_options' ) ) || wp_doing_ajax() ) {
		return;
	}
	update_option( 'daw_mcph_ver', DAW_MCPH_VER, false ); // once, whatever happens
	update_option(
		'daw_mcp_health',
		array(
			'at'   => time(),
			'auth' => daw_mcph_auth_write( daw_mcph_auth_lines() ) ? 'ok' : 'no',
			'lsc'  => daw_mcph_lscache_exclude(),
		),
		false
	);
}

register_deactivation_hook( DAW_DIR . 'daw-core.php', 'daw_mcph_remove' );
function daw_mcph_remove() {
	daw_mcph_auth_write( array() );
	delete_option( 'daw_mcph_ver' );
}

/* ========================================== settings lookup for the chat == */

/**
 * {"opt_find": "easy"} in the inbox: lists the options whose NAME contains
 * that word (only connector-related words are accepted), with their values
 * shortened and anything that looks like a secret blanked. It lets the chat
 * see how a plugin stores a setting before changing it, instead of guessing.
 */
function daw_mcph_opt_find( $word ) {
	global $wpdb;
	$word = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $word ) );
	if ( ! in_array( $word, array( 'easy', 'easy_mcp', 'easy-mcp', 'emcp', 'royal_mcp', 'royal-mcp' ), true ) ) {
		return array( 'opt_find: izin verilmeyen kelime' );
	}
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT 60", '%' . $wpdb->esc_like( $word ) . '%', '\_transient%' ) ); // phpcs:ignore WordPress.DB
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$name = (string) $r->option_name;
		if ( preg_match( '/token|secret|pass|key|salt|hash|nonce|cred|auth_code|client_secret|private/i', $name ) ) {
			$out[] = $name . ' = [gizli]';
			continue;
		}
		$val = maybe_unserialize( $r->option_value );
		$val = daw_mcph_redact( $val );
		$js  = wp_json_encode( $val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$out[] = $name . ' = ' . ( strlen( (string) $js ) > 1800 ? substr( (string) $js, 0, 1800 ) . '…(' . strlen( (string) $js ) . ')' : $js );
	}
	return $out ? $out : array( 'opt_find: eşleşen yok' );
}

function daw_mcph_redact( $v, $depth = 0 ) {
	if ( $depth > 6 ) {
		return '…';
	}
	if ( is_array( $v ) ) {
		$o = array();
		foreach ( $v as $k => $x ) {
			$o[ $k ] = ( is_string( $k ) && preg_match( '/token|secret|pass|key$|_key|salt|hash|cred|private/i', $k ) ) ? '[gizli]' : daw_mcph_redact( $x, $depth + 1 );
		}
		return $o;
	}
	if ( is_object( $v ) ) {
		return '[nesne]';
	}
	return $v;
}

add_filter( 'royal_mcp_readable_options', 'daw_mcph_readable' );
function daw_mcph_readable( $keys ) {
	$keys   = (array) $keys;
	$keys[] = 'daw_mcp_health';
	$keys[] = 'litespeed.conf.cache-exc';
	return array_values( array_unique( $keys ) );
}
