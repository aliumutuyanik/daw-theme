<?php
/**
 * The home page leads with what is on air today.
 *
 * WHY (16 Oct 2026, owner: "hangisi o gün airing yapıyorsa onu da ilkine
 * getirin, böyle dinamik yapı olur, bütün airing içerikler gelir"; and
 * "Drama of the week yerine daha ilgi çekici bir şey")
 * ------------------------------------------------------------------------
 * The home page is the page Googlebot opens most, and it changed once a week:
 * one "drama of the week" in the hero, then rails that move slowly. A show
 * with an episode out today is the page people are searching for today, and
 * the freshest reason for a crawler to come back. So:
 *
 *   - the hero leads with the most read title that has an episode dated
 *     today, and says so ("Episode 7 today"), unless an editor pinned one;
 *     with nothing out today it is the weekly choice, as before;
 *   - under the quick row, every title on air is listed as a plain link,
 *     today's episodes first, then the rest, most read first.
 *
 * "On air" is daw_airing_status(), the calendar's own source (calendar.php);
 * "today" is a row of the record's own episode list (daw_ep_date) dated
 * today in site time. No weekday is guessed: the calendar's note on why the
 * archive does not store one still holds. Pages held at the thin gate are
 * left out, so the home page spends no crawl on a noindex page; they join
 * the list the day they are filled.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Titles on air, cached for the day per plugin version (rule 8.4, K20).
 *
 * @return array{today: array<int, array{id:int, ep:string}>, onair: int[]}
 */
function daw_onair_home() {
	$key    = 'daw_onair_v1_' . md5( ( defined( 'DAW_VERSION' ) ? DAW_VERSION : '' ) . '|' . current_time( 'Y-m-d' ) );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$out = array( 'today' => array(), 'onair' => array() );
	if ( ! function_exists( 'daw_calendar_index' ) ) {
		return $out;
	}

	$ids = array();
	foreach ( (array) ( daw_calendar_index()['onair'] ?? array() ) as $row ) {
		$id = (int) ( $row['id'] ?? 0 );
		if ( $id && 'publish' === get_post_status( $id ) && ! ( function_exists( 'daw_thin_is_held' ) && daw_thin_is_held( $id ) ) ) {
			$ids[] = $id;
		}
	}
	// LONG RUNNERS (1.75.79). Running Man, Knowing Bros and the other weekly
	// shows list every episode up to the last one, so daw_derived_finale()
	// reads the latest episode as a finale and the calendar calls the show
	// Completed a day after each broadcast. A record whose status says
	// ongoing and whose own episode list has an episode from the last 14
	// days (or the next 7) is on air, whatever that derivation says.
	foreach ( daw_onair_long_runners() as $id ) {
		if ( ! ( function_exists( 'daw_thin_is_held' ) && daw_thin_is_held( $id ) ) ) {
			$ids[] = $id;
		}
	}
	$ids = array_values( array_unique( $ids ) );
	if ( ! $ids ) {
		set_transient( $key, $out, HOUR_IN_SECONDS );
		return $out;
	}
	update_meta_cache( 'post', $ids );

	$today = current_time( 'Y-m-d' );
	$rank  = array();
	foreach ( $ids as $id ) {
		$views       = function_exists( 'daw_views_30' ) ? daw_views_30( $id ) : 0;
		$vol         = function_exists( 'daw_ib_top_volume' ) ? daw_ib_top_volume( $id ) : 0;
		$rank[ $id ] = array( $views, $vol, -$id );

		$dates = get_post_meta( $id, 'daw_ep_date', true );
		$nos   = get_post_meta( $id, 'daw_ep_no', true );
		if ( ! is_array( $dates ) ) {
			continue;
		}
		foreach ( array_values( $dates ) as $i => $d ) {
			if ( substr( trim( (string) $d ), 0, 10 ) === $today ) {
				$no                   = is_array( $nos ) ? trim( (string) ( array_values( $nos )[ $i ] ?? '' ) ) : '';
				$out['today'][ $id ] = array( 'id' => $id, 'ep' => $no );
				break;
			}
		}
	}

	$by_rank = static fn( $a, $b ) => $rank[ $b ] <=> $rank[ $a ];

	$today_ids = array_keys( $out['today'] );
	usort( $today_ids, $by_rank );
	$out['today'] = array_values( array_map( static fn( $id ) => $out['today'][ $id ], $today_ids ) );

	$rest = array_values( array_diff( $ids, $today_ids ) );
	usort( $rest, $by_rank );
	$out['onair'] = $rest;

	set_transient( $key, $out, 3 * HOUR_IN_SECONDS );
	return $out;
}

/** Records marked ongoing with an episode dated in the last 14 days or the next 7. */
function daw_onair_long_runners() {
	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => 'daw_status', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => 'ongoing', // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	$ids = array_map( 'intval', (array) $ids );
	if ( ! $ids ) {
		return array();
	}
	update_meta_cache( 'post', $ids );
	$from = gmdate( 'Y-m-d', current_time( 'timestamp' ) - 14 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	$to   = gmdate( 'Y-m-d', current_time( 'timestamp' ) + 7 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	$out  = array();
	foreach ( $ids as $id ) {
		$dates = get_post_meta( $id, 'daw_ep_date', true );
		foreach ( is_array( $dates ) ? $dates : array() as $d ) {
			$d = substr( trim( (string) $d ), 0, 10 );
			if ( $d >= $from && $d <= $to ) {
				$out[] = $id;
				break;
			}
		}
	}
	return $out;
}

/** The today row for a record, or null. */
function daw_onair_today_row( $id ) {
	foreach ( daw_onair_home()['today'] as $row ) {
		if ( (int) $row['id'] === (int) $id ) {
			return $row;
		}
	}
	return null;
}

/*
 * THE HERO FOLLOWS TODAY'S EPISODES
 * An editor's pin (option daw_featured_record) still wins: that is a
 * decision, this is a default. Otherwise the most read title with an episode
 * out today leads; with none, the weekly choice of daw_featured_auto().
 */
add_filter( 'daw_featured_record_auto', 'daw_onair_featured', 10, 1 );
function daw_onair_featured( $id ) {
	// The first of today's titles that has a picture: the hero is a poster
	// (1.75.80). None with one: the weekly choice, as before.
	foreach ( daw_onair_home()['today'] as $row ) {
		if ( ! function_exists( 'daw_card_has_image' ) || daw_card_has_image( (int) $row['id'] ) ) {
			return (int) $row['id'];
		}
	}
	return $id;
}

/** "Episode 7 today", "New episode today", "On air now", or the weekly label. */
function daw_onair_eyebrow( $id, $fallback ) {
	$row = daw_onair_today_row( $id );
	if ( $row ) {
		/* translators: %s: episode number */
		return '' !== $row['ep'] ? sprintf( __( 'Episode %s today', 'daw' ), $row['ep'] ) : __( 'New episode today', 'daw' );
	}
	if ( in_array( (int) $id, daw_onair_home()['onair'], true ) ) {
		return __( 'On air now', 'daw' );
	}
	return $fallback;
}

/** A new day is a new list: the home page goes once when the date turns. */
add_action( 'daw_fix_tick', 'daw_onair_day_purge', 71 );
function daw_onair_day_purge() {
	$day = current_time( 'Y-m-d' );
	if ( get_option( 'daw_onair_day' ) === $day ) {
		return;
	}
	update_option( 'daw_onair_day', $day, false );
	update_option( 'daw_home_dirty', 1, false );
	if ( function_exists( 'daw_idx_rotation_purge' ) ) {
		daw_idx_rotation_purge();
	}
}

/*
 * NO EMPTY POSTER ON THE HOME PAGE (1.75.80, owner 16 Oct 2026: "ana sayfada
 * görselsiz şeyler çok şık durmuyor")
 * Whether a record's card would show a picture: the same decision daw_poster()
 * makes (daw_remote_mode_for(), tmdb-remote.php), asked without drawing it.
 * The home page uses it to give its card slots to records with a picture and
 * to move the rest into the plain chip links, where a missing poster does not
 * show. A record without one usually has a wrong or unconfirmed TMDB id
 * (Kang Soo Yeon carried another person's id); the coverage job lists those.
 */
function daw_card_has_image( $id ) {
	$id   = (int) $id;
	$mode = function_exists( 'daw_remote_mode_for' ) ? daw_remote_mode_for( $id ) : 'default';
	if ( 'monogram' === $mode ) {
		return false;
	}
	if ( in_array( $mode, array( 'remote', 'vault', 'local', 'copy' ), true ) ) {
		return true;
	}
	return has_post_thumbnail( $id );
}

/** Ids with a picture first, the order kept inside each group. */
function daw_pictured_first( array $ids ) {
	$with = array();
	$without = array();
	foreach ( $ids as $id ) {
		if ( daw_card_has_image( $id ) ) {
			$with[] = (int) $id;
		} else {
			$without[] = (int) $id;
		}
	}
	return array_merge( $with, $without );
}
