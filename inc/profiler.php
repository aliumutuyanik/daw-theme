<?php
/**
 * Where an admin page spends its time.
 *
 * WHY (owner, 29 Oct 2026: "panelde sayfaların açılması çok yavaş, ne olabilir,
 * hızlandırabiliyor muyuz")
 * -------------------------------------------------------------------------
 * The plugin hangs a hundred and fifty jobs on init, admin_init, admin_menu and
 * the notice hooks. One slow one makes every admin screen slow, and guessing
 * which is how the wrong one gets "optimised". So the next few admin page
 * loads are measured instead: every callback on those hooks is wrapped in a
 * timer that counts milliseconds and database queries, and the slowest are
 * written to the option `daw_prof_last`.
 *
 * It runs only while the option `daw_prof_runs` is above zero (each measured
 * load takes one off), only in the admin, and only for an administrator. With
 * the option at zero nothing is wrapped and the cost is one get_option.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

/** The hooks whose callbacks are timed. */
function daw_prof_hooks() {
	return array( 'init', 'wp_loaded', 'admin_init', 'admin_menu', 'current_screen', 'admin_enqueue_scripts', 'admin_head', 'in_admin_header', 'admin_notices', 'all_admin_notices', 'admin_bar_menu', 'admin_footer', 'shutdown', 'pre_get_posts', 'the_posts', 'manage_post_posts_custom_column', 'admin_print_footer_scripts' );
}

add_action( 'plugins_loaded', 'daw_prof_start', PHP_INT_MAX );
function daw_prof_start() {
	if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() || (int) get_option( 'daw_prof_runs', 0 ) < 1 ) {
		return;
	}
	// The user is not known yet at plugins_loaded; the check runs on init,
	// and a non-administrator's load is simply not stored.
	global $wp_filter, $daw_prof;
	$daw_prof = array(
		't0'  => isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ),
		'cb'  => array(),
		'uri' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
		'boot' => 0,
	);
	// Everything before this point: WordPress core, every plugin's files
	// being read, and this plugin's ~200 includes.
	$daw_prof['boot'] = round( ( microtime( true ) - $daw_prof['t0'] ) * 1000 );
	foreach ( daw_prof_hooks() as $hook ) {
		if ( empty( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof WP_Hook ) ) {
			continue;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $prio => $cbs ) {
			foreach ( $cbs as $key => $cb ) {
				$name = daw_prof_name( $cb['function'] );
				$fn   = $cb['function'];
				$wp_filter[ $hook ]->callbacks[ $prio ][ $key ]['function'] = static function ( ...$args ) use ( $fn, $name, $hook ) {
					global $wpdb, $daw_prof;
					$q0  = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;
					$t   = microtime( true );
					$ret = call_user_func_array( $fn, $args );
					$k   = $hook . ' · ' . $name;
					if ( ! isset( $daw_prof['cb'][ $k ] ) ) {
						$daw_prof['cb'][ $k ] = array( 0.0, 0, 0 );
					}
					$daw_prof['cb'][ $k ][0] += ( microtime( true ) - $t ) * 1000;
					$daw_prof['cb'][ $k ][1] += ( isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0 ) - $q0;
					++$daw_prof['cb'][ $k ][2];
					return $ret;
				};
			}
		}
	}
	add_action( 'shutdown', 'daw_prof_store', PHP_INT_MAX );
}

/** A readable name for a callback. */
function daw_prof_name( $fn ) {
	if ( is_string( $fn ) ) {
		return $fn;
	}
	if ( is_array( $fn ) ) {
		return ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . (string) $fn[1];
	}
	if ( $fn instanceof Closure ) {
		$r = new ReflectionFunction( $fn );
		return 'closure ' . basename( (string) $r->getFileName() ) . ':' . $r->getStartLine();
	}
	return 'callable';
}

function daw_prof_store() {
	global $daw_prof, $wpdb;
	if ( empty( $daw_prof ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$cb = $daw_prof['cb'];
	uasort(
		$cb,
		static function ( $a, $b ) {
			return $b[0] <=> $a[0];
		}
	);
	$top = array();
	foreach ( array_slice( $cb, 0, 30, true ) as $k => $v ) {
		$top[] = sprintf( '%7.1f ms  %4d q  x%d  %s', $v[0], $v[1], $v[2], $k );
	}
	$runs   = (array) get_option( 'daw_prof_last', array() );
	$runs[] = array(
		'at'      => gmdate( 'Y-m-d H:i:s' ),
		'uri'     => $daw_prof['uri'],
		'total'   => round( ( microtime( true ) - $daw_prof['t0'] ) * 1000 ),
		'boot'    => $daw_prof['boot'],
		'queries' => isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0,
		'mem_mb'  => round( memory_get_peak_usage() / 1048576, 1 ),
		'top'     => $top,
	);
	update_option( 'daw_prof_last', array_slice( $runs, -8 ), false );
	update_option( 'daw_prof_runs', max( 0, (int) get_option( 'daw_prof_runs', 0 ) - 1 ), false );
}

/*
 * GET /daw/v1/prof reads the measured loads; POST /daw/v1/prof {"runs":5}
 * measures the next five admin page loads. Administrators only.
 */
add_action( 'rest_api_init', 'daw_prof_routes' );
function daw_prof_routes() {
	register_rest_route(
		'daw/v1',
		'/prof',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'callback'            => static fn() => array(
					'runs_left' => (int) get_option( 'daw_prof_runs', 0 ),
					'last'      => (array) get_option( 'daw_prof_last', array() ),
				),
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'callback'            => static function ( WP_REST_Request $r ) {
					$n = max( 0, min( 20, (int) $r->get_param( 'runs' ) ) );
					update_option( 'daw_prof_runs', $n, false );
					if ( $r->get_param( 'clear' ) ) {
						delete_option( 'daw_prof_last' );
					}
					return array( 'runs_left' => $n );
				},
			),
		)
	);
}
