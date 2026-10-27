<?php
/**
 * Discover Asian World: readers never rebuild the page cache.
 *
 * A page that is not in LiteSpeed's cache is built by PHP: about 1.2 to 1.9
 * seconds for the lists and the home page, against well under a tenth of
 * that from the cache. Every full purge (a new version, the daily status
 * turn, a settings change) and every saved record empties some of it, and
 * until now the first reader, or Googlebot, paid for the rebuild.
 *
 * So the site fetches its own important pages right after a purge, and
 * once an hour tops them up (a page already cached answers in a few
 * milliseconds, so the top-up costs almost nothing):
 * - every published page (the lists, the calendar, the sections),
 * - the 30 most read records of the last 30 days,
 * - records saved in the last day (each save purges that record).
 * Each address is fetched as a phone and as a computer, because LiteSpeed
 * may keep a separate copy for each. Batches of 20, a minute apart, so the
 * warming itself never loads the server.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_WARM_QUEUE = 'daw_warm_queue';
const DAW_WARM_LAST  = 'daw_warm_last';

/**
 * Which release built a cached page. After an update, a copy LiteSpeed kept
 * from the old release (seen on 6 Oct 2026: a phone copy that outlived the
 * purge) is found by the warmer, purged by its own address and built again.
 */
add_action( 'wp_head', 'daw_warm_build_tag', 0 );
function daw_warm_build_tag() {
	echo '<meta name="daw-build" content="' . esc_attr( DAW_VERSION ) . '">' . "\n";
}

/** The addresses worth keeping warm, most important first. */
function daw_warm_urls() {
	$urls = array( home_url( '/' ) );

	foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 40, 'fields' => 'ids', 'orderby' => 'menu_order', 'order' => 'ASC', 'no_found_rows' => true ) ) as $id ) {
		$urls[] = get_permalink( $id );
	}

	foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 30, 'fields' => 'ids', 'meta_key' => 'daw_views_30', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'no_found_rows' => true ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
		$urls[] = get_permalink( $id );
	}

	foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 20, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true, 'date_query' => array( array( 'column' => 'post_modified_gmt', 'after' => '1 day ago' ) ) ) ) as $id ) {
		$urls[] = get_permalink( $id );
	}

	$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
	return array_slice( $urls, 0, 90 );
}

/** Puts addresses in the queue and starts the first batch. */
function daw_warm_enqueue( $urls, $delay = 20 ) {
	$q = get_option( DAW_WARM_QUEUE, array() );
	$q = is_array( $q ) ? $q : array();
	$q = array_values( array_unique( array_merge( $q, (array) $urls ) ) );
	update_option( DAW_WARM_QUEUE, array_slice( $q, 0, 200 ), false );
	if ( ! wp_next_scheduled( 'daw_warm_batch' ) ) {
		wp_schedule_single_event( time() + max( 5, (int) $delay ), 'daw_warm_batch' );
	}
}

/* =============================================================== triggers == */

// After LiteSpeed has emptied the whole cache.
add_action( 'litespeed_purged_all', 'daw_warm_after_purge_all' );
function daw_warm_after_purge_all() {
	daw_warm_enqueue( daw_warm_urls(), 30 );
}

// After a record is saved (its own page was purged).
add_action( 'save_post_post', 'daw_warm_after_save', 99, 2 );
add_action( 'save_post_page', 'daw_warm_after_save', 99, 2 );
function daw_warm_after_save( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) {
		return;
	}
	daw_warm_enqueue( array( get_permalink( $post_id ), home_url( '/' ) ), 60 );
}

// Hourly top-up.
add_action( 'init', 'daw_warm_schedule' );
function daw_warm_schedule() {
	if ( ! wp_next_scheduled( 'daw_warm_hourly' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'daw_warm_hourly' );
	}
}
add_action( 'daw_warm_hourly', 'daw_warm_hourly_run' );
function daw_warm_hourly_run() {
	daw_warm_enqueue( daw_warm_urls(), 5 );
}

/* ================================================================== batch == */

add_action( 'daw_warm_batch', 'daw_warm_batch_run' );
function daw_warm_batch_run() {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 150 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	$q = get_option( DAW_WARM_QUEUE, array() );
	$q = is_array( $q ) ? $q : array();
	if ( ! $q ) {
		return;
	}
	$batch = array_splice( $q, 0, 20 );
	update_option( DAW_WARM_QUEUE, $q, false );

	$uas = array(
		'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36',
		'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
	);
	$last = get_option( DAW_WARM_LAST, array() );
	$last = is_array( $last ) ? $last : array();
	$hit   = 0;
	$miss  = 0;
	$stale = 0;
	$again = array();
	$ms   = 0;
	foreach ( $batch as $url ) {
		foreach ( $uas as $ua ) {
			$t0  = microtime( true );
			$res = wp_remote_get( $url, array( 'timeout' => 15, 'user-agent' => $ua, 'redirection' => 0 ) );
			$ms += (int) round( ( microtime( true ) - $t0 ) * 1000 );
			$h   = is_wp_error( $res ) ? '' : strtolower( (string) wp_remote_retrieve_header( $res, 'x-litespeed-cache' ) );
			if ( 'hit' === $h && false === strpos( (string) wp_remote_retrieve_body( $res ), 'name="daw-build" content="' . DAW_VERSION . '"' ) ) {
				// An old release's copy: purge this address (LiteSpeed applies
				// it when this request ends) and fetch it again next batch.
				do_action( 'litespeed_purge_url', $url );
				++$stale;
				$tries = get_option( 'daw_warm_retry', array() );
				$tries = is_array( $tries ) && ( $tries['v'] ?? '' ) === DAW_VERSION ? $tries : array( 'v' => DAW_VERSION );
				if ( (int) ( $tries[ $url ] ?? 0 ) < 2 ) { // twice at most per release
					$tries[ $url ] = (int) ( $tries[ $url ] ?? 0 ) + 1;
					$again[]       = $url;
					update_option( 'daw_warm_retry', $tries, false );
				}
			}
			if ( 'hit' === $h ) {
				++$hit;
			} else {
				++$miss;
			}
		}
	}
	if ( $again ) {
		$q = array_values( array_unique( array_merge( $q, $again ) ) );
		update_option( DAW_WARM_QUEUE, $q, false );
	}
	$n = count( $batch ) * count( $uas );
	update_option(
		DAW_WARM_LAST,
		array(
			'at'      => time(),
			'fetched' => $n,
			'hit'     => $hit + (int) ( ( $last['at'] ?? 0 ) > time() - 600 ? ( $last['hit'] ?? 0 ) : 0 ),
			'built'   => $miss + (int) ( ( $last['at'] ?? 0 ) > time() - 600 ? ( $last['built'] ?? 0 ) : 0 ),
			'avg_ms'  => $n ? (int) round( $ms / $n ) : 0,
			'left'    => count( $q ),
			'stale'   => $stale + (int) ( ( $last['at'] ?? 0 ) > time() - 600 ? ( $last['stale'] ?? 0 ) : 0 ),
		),
		false
	);

	if ( $q ) {
		wp_schedule_single_event( time() + 60, 'daw_warm_batch' );
	}
}
