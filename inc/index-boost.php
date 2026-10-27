<?php
/**
 * Discover Asian World: help Google find the pages it has not taken in.
 *
 * WHY (5 Oct 2026)
 * ----------------
 * Of 452 pages Google was asked about, 309 were not in its index: 138 it had
 * never heard of, 116 it had crawled and left out, 55 it knew of and had not
 * fetched. No SEO plugin addresses this. What Google does respond to is
 * internal links from pages it already values: a page linked from indexed
 * pages is discovered sooner and treated as more important.
 *
 * WHAT THIS DOES
 * --------------
 * - The "Similar" rail on every drama, film and variety page (related.php)
 *   gives a not-yet-indexed match a lift in the ranking, so the pages Google
 *   already reads send it towards the ones it does not. Only among real
 *   matches (same type, country, a shared genre): nothing irrelevant is ever
 *   shown to a reader just to help a crawler.
 * - Google's verdict comes from the URL Inspection results seo-gsc.php keeps
 *   (40 pages an hour). A page that enters the index loses the lift on the
 *   next daily refresh of the rail.
 * - A daily count of indexed pages is kept, so the control centre can show
 *   how many entered the index this week.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** Paths Google has looked at and not indexed: path => coverage. */
function daw_ib_pending() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}
	$memo = get_transient( 'daw_ib_pending' );
	if ( is_array( $memo ) ) {
		return $memo;
	}
	$memo = array();
	if ( function_exists( 'daw_gsc_inspect_table' ) && (int) get_option( 'daw_gsc_db', 0 ) >= ( defined( 'DAW_GSC_DB_VERSION' ) ? DAW_GSC_DB_VERSION : 1 ) ) {
		global $wpdb;
		foreach ( (array) $wpdb->get_results( 'SELECT path, coverage FROM ' . daw_gsc_inspect_table() . " WHERE verdict <> 'PASS'", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$memo[ untrailingslashit( (string) $r['path'] ) ] = (string) $r['coverage'];
		}
	}
	set_transient( 'daw_ib_pending', $memo, 6 * HOUR_IN_SECONDS );
	return $memo;
}

function daw_ib_is_pending( $pid ) {
	$path = untrailingslashit( (string) wp_parse_url( (string) get_permalink( $pid ), PHP_URL_PATH ) );
	return isset( daw_ib_pending()[ $path ] );
}

/** related.php asks: a lift worth about one shared genre for a page Google has not taken in. */
add_filter( 'daw_similar_score', 'daw_ib_similar_score', 10, 2 );
function daw_ib_similar_score( $score, $pid ) {
	// A page held at the thin gate is noindex on purpose: no lift (1.75.48).
	if ( function_exists( 'daw_thin_is_held' ) && daw_thin_is_held( $pid ) ) {
		return $score;
	}
	if ( ! daw_ib_is_pending( $pid ) ) {
		return $score;
	}
	// THE MOST WANTED PAGE GOES FIRST (1.75.75)
	// Every page Google has not taken in used to get the same lift, so a
	// record nobody searches for took a Similar slot, and with it Googlebot's
	// visit, as readily as one with 20,000 searches a month. The lift now
	// grows with the record's own top search volume (_daw_kw, Ubersuggest):
	// 12 as before, plus 2 for every power of ten, so 100/mo adds 4, 10,000/mo
	// adds 8. Still only among real matches; the reader sees nothing odd.
	return $score + 12 + (int) round( 2 * log10( 1 + daw_ib_top_volume( $pid ) ) );
}

/** The record's highest target search volume from _daw_kw, 0 when it has none. */
function daw_ib_top_volume( $pid ) {
	$top = 0;
	$kws = get_post_meta( (int) $pid, '_daw_kw', true );
	foreach ( is_array( $kws ) ? $kws : array() as $k ) {
		if ( is_array( $k ) && empty( $k['n'] ) && empty( $k['x'] ) ) {
			$top = max( $top, (int) ( $k['vol'] ?? 0 ) );
		}
	}
	return $top;
}

/* ============================================================== history == */

add_action( 'daw_snapshot_cron', 'daw_ib_snapshot' );
add_action( 'daw_kc_run_all_late', 'daw_ib_snapshot', 50 );
function daw_ib_snapshot() {
	if ( ! function_exists( 'daw_gsc_inspect_table' ) ) {
		return;
	}
	global $wpdb;
	$n    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . daw_gsc_inspect_table() . " WHERE verdict = 'PASS'" ); // phpcs:ignore WordPress.DB
	$hist = get_option( 'daw_ib_hist', array() );
	$hist = is_array( $hist ) ? $hist : array();
	$hist[ gmdate( 'Y-m-d' ) ] = $n;
	ksort( $hist );
	update_option( 'daw_ib_hist', array_slice( $hist, -60, null, true ), false );
	delete_transient( 'daw_ib_pending' );
}

/** Pages that entered the index in the last 7 days (net), or null without a week of history. */
function daw_ib_week_gain() {
	$hist = get_option( 'daw_ib_hist', array() );
	if ( ! is_array( $hist ) || count( $hist ) < 2 ) {
		return null;
	}
	$now  = end( $hist );
	$then = null;
	foreach ( $hist as $day => $n ) {
		if ( $day <= gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ) ) {
			$then = $n;
		}
	}
	if ( null === $then ) {
		$then = reset( $hist );
	}
	return (int) $now - (int) $then;
}
