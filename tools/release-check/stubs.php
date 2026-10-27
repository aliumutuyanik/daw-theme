<?php
/**
 * The few WordPress functions the tested code calls, faked, so the release
 * check runs with plain `php` and no WordPress. Meta values come from the
 * fixture set the test file registers in $GLOBALS['daw_rc_meta'].
 *
 * @package daw-core
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAW_VERSION', 'release-check' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

$GLOBALS['daw_rc_meta']  = array();
$GLOBALS['daw_rc_title'] = array();

function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function apply_filters( $tag, $v ) { return $v; }
function do_action( ...$a ) {}
function __( $s ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function maybe_unserialize( $v ) { return $v; }
function remove_accents( $s ) { return $s; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://discoverasianworld.com/' . ltrim( $p, '/' ); }
function get_bloginfo( $k = '' ) { return 'Discover Asian World'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function get_transient( $k ) { return false; }
function set_transient( ...$a ) { return true; }
function delete_transient( $k ) { return true; }
function get_option( $k, $d = false ) { return $d; }
function wp_next_scheduled( ...$a ) { return false; }
function wp_schedule_single_event( ...$a ) { return true; }
function get_the_title( $id ) { return $GLOBALS['daw_rc_title'][ (int) $id ] ?? ''; }
function get_post_meta( $id, $k = '', $single = false ) {
	$v = $GLOBALS['daw_rc_meta'][ (int) $id ][ $k ] ?? '';
	return $v;
}
function wpautop( $s ) {
	$parts = preg_split( '/\n\s*\n/', trim( (string) $s ) );
	return implode( "\n", array_map( static function ( $p ) { return '<p>' . trim( $p ) . '</p>'; }, $parts ) );
}
