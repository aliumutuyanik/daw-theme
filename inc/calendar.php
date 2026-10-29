<?php
/**
 * The airing calendar: every dated premiere and finale, reachable by month.
 *
 * WHAT A CALENDAR CAN HONESTLY SAY HERE
 * -------------------------------------
 * The obvious design is a week grid: Monday's dramas, Tuesday's dramas, the way
 * a Korean broadcast schedule is actually published. This archive does not
 * store a broadcast weekday, so that grid would be built out of a field that
 * does not exist, and a guess printed in a table looks exactly like a fact.
 *
 * What the records do carry is a status and two dates, the premiere and the
 * finale. Those are the events. Nothing else is drawn on a day.
 *
 * TWO WAYS IN
 * -----------
 * The page opens as a list: what is on air now, what is coming (every dated
 * premiere, soonest first) and this week's TV guide. The month view reaches
 * every other date: any month from the first dated record to the last, one
 * click from the previous and the next, or straight to a month and year.
 * The list is the default because it is the answer most readers came for and
 * it is one request; the month pages are noindex, as the tabs were.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_CALENDAR_SLUG = 'airing-calendar';

/** The record types that have an air date worth putting on a calendar. */
function daw_calendar_types() {
	return array( 'drama', 'film', 'variety', 'anime' );
}

/** Type filter labels, slug => label. */
function daw_calendar_filters() {
	return array(
		'all'     => __( 'All', 'daw' ),
		'drama'   => __( 'Dramas', 'daw' ),
		'film'    => __( 'Movies', 'daw' ),
		'variety' => __( 'Variety', 'daw' ),
		'anime'   => __( 'Anime', 'daw' ),
	);
}

/**
 * What the address asks for: the view, the month and the type filter, each
 * checked and defaulted. Nothing is trusted.
 */
function daw_calendar_request() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$view = ( isset( $_GET['view'] ) && 'month' === sanitize_key( wp_unslash( $_GET['view'] ) ) ) ? 'month' : 'list';
	$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'all';
	$type = isset( daw_calendar_filters()[ $type ] ) ? $type : 'all';
	$m    = isset( $_GET['ym'] ) ? sanitize_text_field( wp_unslash( $_GET['ym'] ) ) : '';

	// The month and year selects send two fields; a link sends one.
	if ( isset( $_GET['mo'], $_GET['yr'] ) ) {
		$m = sprintf( '%04d-%02d', (int) $_GET['yr'], (int) $_GET['mo'] );
	}
	// phpcs:enable

	$ok = (bool) preg_match( '/^(\d{4})-(\d{2})$/', $m, $p ) && (int) $p[1] >= 1990 && (int) $p[1] <= 2100 && (int) $p[2] >= 1 && (int) $p[2] <= 12;

	return array(
		'view'  => $view,
		'type'  => $type,
		'month' => $ok ? $m : wp_date( 'Y-m' ),
		'today' => wp_date( 'Y-m-d' ),
	);
}

/** A stored date (UTC midnight) in the page's language. */
function daw_calendar_fmt( $ts, $format ) {
	return wp_date( $format, (int) $ts, new DateTimeZone( 'UTC' ) );
}

/** Whole days from today to a stored date; negative when it is behind us. */
function daw_calendar_days_from_today( $ts ) {
	$a = strtotime( gmdate( 'Y-m-d', (int) $ts ) . ' 00:00:00 UTC' );
	$b = strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 UTC' );

	return (int) round( ( $a - $b ) / DAY_IN_SECONDS );
}

/** "In 69 days", "Tomorrow", "14 days ago". */
function daw_calendar_relative( $ts ) {
	$d = daw_calendar_days_from_today( $ts );

	if ( 0 === $d ) {
		return __( 'Today', 'daw' );
	}
	if ( 1 === $d ) {
		return __( 'Tomorrow', 'daw' );
	}
	if ( -1 === $d ) {
		return __( 'Yesterday', 'daw' );
	}
	if ( $d > 0 ) {
		/* translators: %s: number of days */
		return sprintf( __( 'In %s days', 'daw' ), number_format_i18n( $d ) );
	}

	/* translators: %s: number of days */
	return sprintf( __( '%s days ago', 'daw' ), number_format_i18n( -$d ) );
}

/**
 * Every dated event and every title on air, once, for all views.
 *
 * Dates are read in PHP rather than queried in SQL for the same reason the
 * browse page does it: the air date is a timestamp in meta, a range query on it
 * plus a status query is the slowest join on the site, and the whole list is
 * about a thousand rows. The result is cached for six hours and keyed by the
 * browse generation, the plugin version (K20) and the day.
 *
 * Returns array(
 *   'events' => [ [ id, when, kind prem|fin, type ], ... ] oldest first,
 *   'onair'  => [ [ id, when, type ], ... ],
 *   'tbc'    => [ [ id, type ], ... ]  marked upcoming with no date,
 *   'years'  => [ first, last ],
 * )
 */
function daw_calendar_index() {
	// BUILT OFF THE REQUEST (1.77.15). Building the index reads every title's
	// dates and episode list: about fourteen seconds on this host. Keyed on
	// the browse generation, it was rebuilt by the first visitor after every
	// save, and with records being saved all afternoon the home page and the
	// calendar timed out with 504s (29 Oct 2026). Now a visitor always gets
	// the last index at once; a changed generation or a new day only
	// schedules a rebuild in the background, one at a time.
	$gen  = function_exists( 'daw_browse_gen' ) ? daw_browse_gen() : 1;
	$want = md5( wp_json_encode( array( $gen, DAW_VERSION, gmdate( 'Y-m-d' ) ) ) );
	$last = get_option( 'daw_cal_last' );

	if ( is_array( $last ) && isset( $last['events'] ) ) {
		if ( ( $last['sig'] ?? '' ) !== $want && ! get_transient( 'daw_cal_lock' ) && ! wp_next_scheduled( 'daw_cal_rebuild' ) ) {
			wp_schedule_single_event( time(), 'daw_cal_rebuild' );
		}
		return $last;
	}

	return daw_calendar_build( $want );
}

add_action( 'daw_cal_rebuild', 'daw_calendar_rebuild' );
function daw_calendar_rebuild() {
	if ( get_transient( 'daw_cal_lock' ) ) {
		return;
	}
	set_transient( 'daw_cal_lock', 1, 5 * MINUTE_IN_SECONDS );
	$gen = function_exists( 'daw_browse_gen' ) ? daw_browse_gen() : 1;
	daw_calendar_build( md5( wp_json_encode( array( $gen, DAW_VERSION, gmdate( 'Y-m-d' ) ) ) ) );
	delete_transient( 'daw_cal_lock' );
}

/** Build the index and keep it as the last one. */
function daw_calendar_build( $sig ) {
	$ids = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => true,
			'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => daw_calendar_types(),
				),
			),
		)
	);

	// One query for the terms and one per hundred records for the meta,
	// instead of several queries per record.
	if ( $ids ) {
		update_object_term_cache( $ids, 'post' );
		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			update_meta_cache( 'post', $chunk );
		}
	}

	$now    = time();
	$events = array();
	$onair  = array();
	$tbc    = array();

	foreach ( $ids as $id ) {
		$type = function_exists( 'daw_get_type' ) ? (string) daw_get_type( $id ) : '';
		$type = in_array( $type, daw_calendar_types(), true ) ? $type : 'drama';

		// One source of truth: the same function the record page asks (a manual
		// status field nobody maintained once put 1999 dramas on this page).
		$computed = function_exists( 'daw_airing_status' ) ? daw_airing_status( $id ) : array( 'slug' => '' );
		$slug     = (string) $computed['slug'];
		$start    = (int) get_post_meta( $id, 'daw_aired_start', true );
		$end      = (int) get_post_meta( $id, 'daw_aired_end', true );

		if ( $start ) {
			// A whole season released at once (Netflix, Disney+) is one event:
			// "All 8 episodes" on the premiere, nothing after it (owner, 29 Oct
			// 2026: "tümü yayınlanıyorsa tek seferde ver").
			$prem    = array( 'id' => (int) $id, 'when' => $start, 'kind' => 'prem', 'type' => $type );
			$ep_all  = 'film' !== $type ? get_post_meta( $id, 'daw_ep_date', true ) : array();
			if ( is_array( $ep_all ) && count( $ep_all ) > 1 ) {
				$day0 = gmdate( 'Y-m-d', $start );
				$same = 0;
				foreach ( $ep_all as $d ) {
					$same += substr( trim( (string) $d ), 0, 10 ) === $day0 ? 1 : 0;
				}
				if ( $same === count( $ep_all ) ) {
					$prem['all'] = $same;
				}
			}
			$events[] = $prem;
		}

		// A one-day run is one event, not a premiere and a finale on top of each other.
		// A film has a release, not a finale: a stored end date would draw one.
		if ( 'film' !== $type && $end && $end !== $start && $end >= $start ) {
			$events[] = array( 'id' => (int) $id, 'when' => $end, 'kind' => 'fin', 'type' => $type );
		}

		// EPISODES (1.76.9, owner 29 Oct 2026: "bölümler ile dolu olsun, hangi
		// bölüm ne zaman yayınlanıyor gözüksün"). Every dated row of the
		// record's own episode list becomes an event, for titles that started
		// in the last 400 days or have yet to: the month grid fills with
		// "Ep 7" pills and the list view gets a this-week schedule. A row on
		// the premiere day is already that event, so it is skipped; the finale
		// day keeps its episode row, because the calendar shows finales only
		// in past months (owner: "finalleri göstermeye gerek yok").
		if ( 'film' !== $type && ( ! $start || $start > $now - 400 * DAY_IN_SECONDS ) ) {
			$ep_dates = get_post_meta( $id, 'daw_ep_date', true );
			if ( is_array( $ep_dates ) ) {
				$ep_nos = get_post_meta( $id, 'daw_ep_no', true );
				$ep_nos = is_array( $ep_nos ) ? array_values( $ep_nos ) : array();
				$skip   = array( $start ? gmdate( 'Y-m-d', $start ) : '' );
				$seen   = array();
				foreach ( array_values( $ep_dates ) as $i => $d ) {
					$d = substr( trim( (string) $d ), 0, 10 );
					if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) || in_array( $d, $skip, true ) || isset( $seen[ $d ] ) ) {
						continue;
					}
					// Two episodes on one day (a double bill) are one row: "Ep 3-4".
					$no = trim( (string) ( $ep_nos[ $i ] ?? '' ) );
					foreach ( array_values( $ep_dates ) as $j => $d2 ) {
						if ( $j > $i && substr( trim( (string) $d2 ), 0, 10 ) === $d && '' !== trim( (string) ( $ep_nos[ $j ] ?? '' ) ) ) {
							$no = $no . '-' . trim( (string) $ep_nos[ $j ] );
						}
					}
					$no         = preg_replace( '/-(?:[^-]+-)+/', '-', $no );
					$seen[ $d ] = true;
					$events[]   = array( 'id' => (int) $id, 'when' => strtotime( $d . ' 00:00:00 UTC' ), 'kind' => 'ep', 'type' => $type, 'no' => $no );
				}
			}
		}

		if ( 'onair' === $slug ) {
			$onair[] = array( 'id' => (int) $id, 'when' => $start ? $start : $now, 'type' => $type );
		} elseif ( 'upcoming' === $slug && ! $start ) {
			$tbc[] = array( 'id' => (int) $id, 'type' => $type );
		}
	}

	usort(
		$events,
		static function ( $a, $b ) {
			return $a['when'] <=> $b['when'];
		}
	);

	$first = $events ? (int) gmdate( 'Y', $events[0]['when'] ) : (int) wp_date( 'Y' );
	$last  = $events ? (int) gmdate( 'Y', $events[ count( $events ) - 1 ]['when'] ) : (int) wp_date( 'Y' );

	$out = array(
		'events' => $events,
		'onair'  => $onair,
		'tbc'    => $tbc,
		'years'  => array( $first, max( $last, (int) wp_date( 'Y' ) + 1 ) ),
	);

	$out['sig'] = $sig;
	update_option( 'daw_cal_last', $out, false );

	return $out;
}

/**
 * The next episode a record's own episode list dates today or later, for the
 * calendar's Airing now cards (owner 29 Oct 2026: "ne zaman ne yayınlanıyor
 * vermen lazım"). Only a dated row counts; no weekday is projected forward.
 *
 * @return array{no:string, date:string, today:bool}|null
 */
function daw_calendar_next_ep( $id ) {
	$dates = get_post_meta( $id, 'daw_ep_date', true );
	if ( ! is_array( $dates ) ) {
		return null;
	}
	$nos   = get_post_meta( $id, 'daw_ep_no', true );
	$nos   = is_array( $nos ) ? array_values( $nos ) : array();
	$today = current_time( 'Y-m-d' );
	$best  = null;
	foreach ( array_values( $dates ) as $i => $d ) {
		$d = substr( trim( (string) $d ), 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) || $d < $today ) {
			continue;
		}
		if ( null === $best || $d < $best['date'] ) {
			$best = array( 'no' => trim( (string) ( $nos[ $i ] ?? '' ) ), 'date' => $d, 'today' => $d === $today );
		}
	}
	return $best;
}

/** Rows of an index list narrowed to one type; 'all' keeps everything. */
function daw_calendar_only( $rows, $type ) {
	if ( 'all' === $type ) {
		return array_values( $rows );
	}

	return array_values(
		array_filter(
			$rows,
			static function ( $r ) use ( $type ) {
				return ( $r['type'] ?? '' ) === $type;
			}
		)
	);
}

/** Events grouped by calendar day, 'Y-m-d' => events. Input order is kept. */
function daw_calendar_by_day( $events ) {
	$out = array();

	foreach ( $events as $e ) {
		$out[ gmdate( 'Y-m-d', $e['when'] ) ][] = $e;
	}

	return $out;
}

/**
 * What the calendar page shows, sorted and bounded: the three list sections
 * and, for the month view, that month's events.
 */
function daw_calendar_data( $req ) {
	$idx    = daw_calendar_index();
	$ev     = daw_calendar_only( $idx['events'], $req['type'] );
	$today  = $req['today'];
	$floor  = gmdate( 'Y-m-d', strtotime( $today . ' UTC' ) - 182 * DAY_IN_SECONDS );
	$next   = array();
	$done   = array();
	$month  = array();
	$week   = array();
	$wend   = gmdate( 'Y-m-d', strtotime( $today . ' UTC' ) + 6 * DAY_IN_SECONDS );
	$fin_day = array();
	foreach ( $ev as $e ) {
		if ( 'fin' === $e['kind'] ) {
			$fin_day[ $e['id'] . '|' . gmdate( 'Y-m-d', $e['when'] ) ] = true;
		}
	}

	foreach ( $ev as $e ) {
		$d = gmdate( 'Y-m-d', $e['when'] );

		if ( 'prem' === $e['kind'] && $d > $today ) {
			$next[] = $e;
		} elseif ( 'fin' === $e['kind'] && $d <= $today && $d >= $floor ) {
			$done[] = $e;
		}

		// A finale shows only in a month already over; in this month and the
		// months ahead the last episode is an episode like the others. In a
		// past month the finale stands in for that day's episode row.
		if ( substr( $d, 0, 7 ) === $req['month'] ) {
			$past = $req['month'] < substr( $today, 0, 7 );
			if ( 'fin' === $e['kind'] ? $past : ! ( $past && 'ep' === $e['kind'] && isset( $fin_day[ $e['id'] . '|' . $d ] ) ) ) {
				$month[] = $e;
			}
		}

		// TV guide: every episode and premiere from today to six days on.
		if ( 'fin' !== $e['kind'] && $d >= $today && $d <= $wend ) {
			$week[] = $e;
		}
	}

	$onair = daw_calendar_only( $idx['onair'], $req['type'] );
	usort(
		$onair,
		static function ( $a, $b ) {
			return $b['when'] <=> $a['when'];
		}
	);

	return array(
		'onair' => $onair,
		'next'  => $next,
		'tbc'   => daw_calendar_only( $idx['tbc'], $req['type'] ),
		'done'  => array_reverse( $done ),
		'month' => $month,
		'week'  => $week,
		'years' => $idx['years'],
	);
}

/** The month before or after a 'Y-m'. */
function daw_calendar_shift_month( $ym, $by ) {
	return gmdate( 'Y-m', strtotime( $ym . '-15 UTC ' . ( $by >= 0 ? '+' : '' ) . $by . ' month' ) );
}

/** An address on the calendar page, keeping the view and the type. */
function daw_calendar_url( $base, $args ) {
	$args = array_filter(
		$args,
		static function ( $v ) {
			return null !== $v && '' !== $v && 'all' !== $v;
		}
	);

	return add_query_arg( $args, $base );
}

/**
 * "m" is WordPress's own date-archive query var, so ?m=2026-10 on this page
 * made WordPress look for a dated archive and answer 404. The month now
 * travels as "ym"; old links that still carry "m" are read as "ym" here.
 */
add_filter( 'request', 'daw_calendar_legacy_month' );
function daw_calendar_legacy_month( $qv ) {
	if ( isset( $qv['m'], $qv['pagename'] ) && DAW_CALENDAR_SLUG === $qv['pagename'] && ! ctype_digit( (string) $qv['m'] ) ) {
		if ( ! isset( $_GET['ym'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$_GET['ym'] = sanitize_text_field( (string) $qv['m'] );
		}
		unset( $qv['m'] );
	}

	return $qv;
}

/** The "add to calendar" file for one title's premiere. */
add_action( 'template_redirect', 'daw_calendar_ics' );
function daw_calendar_ics() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['daw_ics'] ) || ! is_page( DAW_CALENDAR_SLUG ) ) {
		return;
	}

	$id    = (int) $_GET['daw_ics']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$post  = $id ? get_post( $id ) : null;
	$start = $post ? (int) get_post_meta( $id, 'daw_aired_start', true ) : 0;

	if ( ! $post || 'publish' !== $post->post_status || ! $start || ! in_array( (string) daw_get_type( $id ), daw_calendar_types(), true ) ) {
		status_header( 404 );
		nocache_headers();
		exit;
	}

	$esc  = static function ( $s ) {
		return str_replace( array( '\\', ';', ',', "\r", "\n" ), array( '\\\\', '\\;', '\\,', '', '\\n' ), (string) $s );
	};
	$day  = gmdate( 'Ymd', $start );
	$next = gmdate( 'Ymd', $start + DAY_IN_SECONDS );
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$net  = function_exists( 'daw_first_term_name' ) ? daw_first_term_name( $id, 'dw_network' ) : '';

	$lines = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//Discover Asian World//Airing calendar//EN',
		'CALSCALE:GREGORIAN',
		'BEGIN:VEVENT',
		'UID:daw-' . $id . '-premiere@' . $host,
		'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
		'DTSTART;VALUE=DATE:' . $day,
		'DTEND;VALUE=DATE:' . $next,
		'SUMMARY:' . $esc( get_the_title( $id ) . ' premieres' ),
		'DESCRIPTION:' . $esc( ( $net ? $net . '. ' : '' ) . get_permalink( $id ) ),
		'URL:' . get_permalink( $id ),
		'END:VEVENT',
		'END:VCALENDAR',
	);

	nocache_headers();
	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $post->post_name ) . '-premiere.ics"' );
	header( 'X-Robots-Tag: noindex' );
	echo implode( "\r\n", $lines ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}

/** The old tab addresses land on the matching section of the one page. */
add_action( 'template_redirect', 'daw_calendar_old_tabs' );
function daw_calendar_old_tabs() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['tab'] ) || ! is_page( DAW_CALENDAR_SLUG ) ) {
		return;
	}

	$anchor = array( 'on' => 'now', 'next' => 'next' );
	$tab    = sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$to     = (string) get_permalink( get_queried_object_id() ) . '#' . ( $anchor[ $tab ] ?? 'now' );

	wp_safe_redirect( $to, 301 );
	exit;
}

add_filter( 'page_template', 'daw_route_calendar_template' );

/** Draw the calendar page with the calendar template. */
function daw_route_calendar_template( $template ) {
	$id = (int) get_queried_object_id();

	if ( ! $id || 'page' !== get_post_type( $id ) || DAW_CALENDAR_SLUG !== get_post_field( 'post_name', $id ) ) {
		return $template;
	}

	if ( function_exists( 'daw_section_is_live' ) && ! daw_section_is_live() ) {
		return $template;
	}

	$found = locate_template( 'page-calendar.php' );

	return $found ? $found : $template;
}

add_filter( 'daw_seo_robots', 'daw_calendar_robots' );

/**
 * The page with no parameters is the one that is indexed. A month, a type or
 * the month picker is another view of the same records, so it is not another page.
 */
function daw_calendar_robots( $robots ) {
	$id = (int) get_queried_object_id();

	if ( ! $id || DAW_CALENDAR_SLUG !== get_post_field( 'post_name', $id ) ) {
		return $robots;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	foreach ( array( 'view', 'ym', 'mo', 'yr', 'type', 'tab' ) as $k ) {
		if ( isset( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$robots['index'] = 'noindex';
			break;
		}
	}

	return $robots;
}
