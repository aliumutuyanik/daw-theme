<?php
/**
 * Discover Asian World — Pinterest, one pin per finished record.
 *
 * WHAT THIS DOES
 * --------------
 * Every record that has passed its full audit (the lock, "kilitli, bitti") is
 * pinned to Pinterest once, on the board its type belongs to, with a picture
 * drawn by the site. The site decides what goes next and when; Make only asks
 * and carries the answer to Pinterest:
 *
 *   GET  /wp-json/daw/v1/pin/next   the next record, or nothing yet
 *   POST /wp-json/daw/v1/pin/done   {id, pin_id}: write it down
 *
 * Both answer only to the key in the X-DAW-PIN-KEY header. The same pattern
 * already runs BLGLFlix's account.
 *
 * NEVER TWICE
 * -----------
 * A record that has a pin log (`_daw_pin`) is never handed out again, and a
 * record ticked "Pinterest'e gönderme" is never handed out at all. The log
 * keeps the pin id, the board, the date and a hash of the picture, so a later
 * second pin for the same page can be made to use a different picture.
 *
 * Make asks every 45 minutes and may ask again before it has written back. A
 * record handed out is held for 30 minutes and handed out again only to the
 * same question, so a slow run never becomes two pins.
 *
 * THE PICTURE
 * -----------
 * The pin is the record's own TMDB poster (or a person's TMDB profile
 * photo) as it is, at full size: the same picture the page shows, already in
 * Pinterest's 2:3 shape. The owner chose this on 28 October 2026. The
 * Pinterest screen can switch to a drawn card instead (the TMDB picture on
 * top, kind, year, network, title and address below).
 *
 * A SECOND PIN, LATER
 * -------------------
 * A page whose first pin was the TMDB picture gets a second pin at least 14
 * days later, with the drawn card: same page, a different picture, which is
 * what Pinterest asks of a second pin. Every third pin of the day is one of
 * these once pages are due; nothing gets a third. A record with no confirmed TMDB picture
 * uses a featured image with an allowed licence record, and failing that the
 * drawn seal: maroon, gold frame, logo, the name in its own script.
 *
 * PACE
 * ----
 * A new account that posts in heaps is a spam account to Pinterest; that is
 * how the GBC account was lost. So: 3 a day for the first two weeks, 5 a day
 * for the next two, 8 a day after that, spread over 08:00-22:00 New York time,
 * most visited records first, never the same board twice in a row when another
 * board has something waiting.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_PIN_LOG  = '_daw_pin';      // list of {pin, board, img, at}
const DAW_PIN_SKIP = '_daw_pin_skip'; // "do not send to Pinterest"
const DAW_PIN_W    = 1000;
const DAW_PIN_H    = 1500;
const DAW_PIN_REV  = '2'; // bump when the card layout changes

/* ================================================================ boards === */

/**
 * The countries the site covers: dw_country slug => key prefix, the adjective,
 * the place name and the searched short forms. Their order is the menu's.
 */
function daw_pin_countries() {
	return array(
		'south-korea' => array( 'k', 'Korean', 'Korea', 'K-Drama', 'K-Pop' ),
		'japan'       => array( 'j', 'Japanese', 'Japan', 'J-Drama', 'J-Pop' ),
		'taiwan'      => array( 'tw', 'Taiwanese', 'Taiwan', 'Taiwanese Drama', 'Taiwanese Pop' ),
		'china'       => array( 'c', 'Chinese', 'China', 'C-Drama', 'C-Pop' ),
		'thailand'    => array( 'th', 'Thai', 'Thailand', 'Thai Drama', 'T-Pop' ),
		'singapore'   => array( 'sg', 'Singaporean', 'Singapore', 'Singaporean Drama', 'Singaporean Pop' ),
		'philippines' => array( 'ph', 'Filipino', 'Philippines', 'Filipino Drama', 'P-Pop' ),
		'hong-kong'   => array( 'hk', 'Hong Kong', 'Hong Kong', 'Hong Kong Drama', 'Cantopop' ),
	);
}

/**
 * The topics, as the site's menu lists them under each country (Korean
 * Drama, Korean Movie, Korean Actors, Korean Actress, Korean Pop, Foods &
 * Drinks, Trends & News, Travel, Test, Culture, Myths) plus variety shows.
 * One topic, one board: dramas and movies, actors and actresses, culture and
 * travel are searched apart and never share a board.
 */
function daw_pin_topics() {
	return array( 'drama', 'movie', 'actor', 'actress', 'pop', 'food', 'news', 'travel', 'test', 'culture', 'myth', 'variety' );
}

/** A topic's board name and description for a country. */
function daw_pin_topic_board( $topic, $c ) {
	list( , $adj, $place, $dr, $pop ) = $c;
	$names = array(
		'drama'   => array( ( false !== strpos( $dr, '-' ) ? $dr . 's: ' : '' ) . $adj . ' Dramas to Watch', $adj . ' drama recommendations with cast, story, episodes and where to watch.' ),
		'movie'   => array( $adj . ' Movies to Watch', $adj . ' movie recommendations with cast, story and where to watch.' ),
		'actor'   => array( $adj . ' Actors', $adj . ' actors: profiles, dramas, movies and new roles.' ),
		'actress' => array( $adj . ' Actresses', $adj . ' actresses: profiles, dramas, movies and new roles.' ),
		'pop'     => array( $pop, $pop . ' groups and singers: members, songs and profiles.' ),
		'food'    => array( $adj . ' Food and Drinks', $adj . ' food and drinks: dishes, recipes, snacks and what to order, as seen in its dramas.' ),
		'news'    => array( $adj . ' Entertainment News', $adj . ' drama, movie and star news: casting, premieres, ratings and trends.' ),
		'travel'  => array( $place . ' Travel', $place . ' travel: drama filming locations, places to visit and tips.' ),
		'test'    => array( $adj . ' Drama Quizzes', $adj . ' drama and culture quizzes and tests.' ),
		'culture' => array( $adj . ' Culture', $adj . ' culture explained: customs, holidays, language and everyday life seen in its dramas.' ),
		'myth'    => array( $adj . ' Myths and Legends', $adj . ' myths, legends and folklore creatures, and the dramas that use them.' ),
		'variety' => array( $adj . ' Variety Shows', $adj . ' variety and reality shows: cast, format and where to watch.' ),
	);
	return $names[ $topic ];
}

/**
 * Every board, by key: country prefix + topic ("j_movie"), Korean genre and
 * platform boards, and the Korean boards made before this scheme, which keep
 * their keys because pin logs name them.
 *
 * 'id' is the Pinterest board, '' while it does not exist. A board is made
 * by the drip (see daw_pin_board_wanted) the first time a record is ready
 * for it, so no board sits empty and a new category gets its board as soon
 * as its first record is finished. Ids made that way are kept in the
 * daw_pin_board_ids option. 'also' marks a board a pin is saved to, never
 * its first board. 'kind' is the phrase a pin's title uses on that board.
 */
function daw_pin_boards() {
	$boards = array();
	foreach ( daw_pin_countries() as $c ) {
		foreach ( daw_pin_topics() as $t ) {
			list( $name, $desc ) = daw_pin_topic_board( $t, $c );
			$boards[ $c[0] . '_' . $t ] = array( 'name' => $name, 'desc' => $desc, 'id' => '' );
		}
	}

	// Korean boards made on 28 Oct 2026 (names: Ubersuggest, US; "korean
	// food" 246k a month, "korean actors" 15k, "korean actresses" 8k).
	// "Korean Actors" is the account's old board, kept with its pins.
	$made = array(
		'kdrama'   => array( 'k_drama', 'K-Drama Recommendations', '988118043183707540' ),
		'kmovie'   => array( 'k_movie', 'Korean Movies to Watch', '988118043183707541' ),
		'actor'    => array( 'k_actor', 'Korean Actors', '988118043182946887' ),
		'actress'  => array( 'k_actress', 'Korean Actresses', '988118043183707542' ),
		'kfood'    => array( 'k_food', 'Korean Food and Drinks', '988118043183707544' ),
		'kculture' => array( 'k_culture', 'Korean Culture', '988118043183707545' ),
		'news'     => array( 'k_news', 'Korean Trends and News', '988118043183707546' ),
	);
	foreach ( $made as $key => $m ) {
		$boards[ $key ] = array( 'name' => $m[1], 'desc' => $boards[ $m[0] ]['desc'], 'id' => $m[2] );
		unset( $boards[ $m[0] ] );
	}
	// The country drama boards made on 28 Oct 2026.
	foreach ( array(
		'c_drama'  => '988118043183707543',
		'j_drama'  => '988118043183707638',
		'th_drama' => '988118043183707639',
		'tw_drama' => '988118043183707640',
		'hk_drama' => '988118043183707641',
		'ph_drama' => '988118043183707642',
		'sg_drama' => '988118043183707643',
	) as $key => $id ) {
		$boards[ $key ]['id'] = $id;
	}
	$boards['th_drama']['name'] = 'Thai Dramas (Lakorn) to Watch';
	$boards['hk_drama']['name'] = 'Hong Kong TVB Dramas to Watch';
	$boards['ph_drama']['name'] = 'Filipino Dramas (Teleserye) to Watch';

	// Korean genre and platform boards: a K-drama or Korean movie goes first
	// to its category board and is saved to these as well. One genre each.
	$also = array(
		'romance'   => array( 'Romantic K-Dramas', '988118043183707557', 'Romantic K-Drama' ),
		'thriller'  => array( 'Thriller K-Dramas', '988118043183707558', 'Thriller K-Drama' ),
		'mystery'   => array( 'Mystery K-Dramas', '', 'Mystery K-Drama' ),
		'hdrama'    => array( 'Horror K-Dramas', '', 'Horror K-Drama' ),
		'sageuk'    => array( 'Historical K-Dramas', '988118043183707559', 'Historical K-Drama' ),
		'fantasy'   => array( 'Fantasy K-Dramas', '988118043183707560', 'Fantasy K-Drama' ),
		'medical'   => array( 'Medical K-Dramas', '988118043183707579', 'Medical K-Drama' ),
		'school'    => array( 'School K-Dramas', '988118043183707576', 'School K-Drama' ),
		'netflix'   => array( 'Netflix K-Dramas to Watch', '988118043183707561', '' ),
		'viki'      => array( 'K-Dramas on Viki', '988118043183707574', '' ),
		'prime'     => array( 'K-Dramas on Prime Video', '988118043183707578', '' ),
		'khorror'   => array( 'Korean Horror Movies', '988118043183707562', 'Korean Horror Movie' ),
		'kthriller' => array( 'Korean Thriller Movies', '', 'Korean Thriller Movie' ),
		'rommovie'  => array( 'Romantic Korean Movies', '988118043183707577', 'Romantic Korean Movie' ),
		'action'    => array( 'Korean Action Movies', '988118043183707575', 'Korean Action Movie' ),
		'nfmovie'   => array( 'Korean Movies on Netflix', '988118043183707580', '' ),
	);
	foreach ( $also as $key => $a ) {
		$boards[ $key ] = array(
			'name' => $a[0],
			'desc' => '' !== $a[2] ? $a[0] . ': recommendations with cast, story and where to watch.' : $a[0] . ' with cast, story and where to watch.',
			'id'   => $a[1],
			'also' => true,
		);
	}

	// Boards the drip has made since.
	$ids = daw_pin_opt( 'daw_pin_board_ids', array() );
	foreach ( is_array( $ids ) ? $ids : array() as $key => $id ) {
		if ( isset( $boards[ $key ] ) && '' === $boards[ $key ]['id'] ) {
			$boards[ $key ]['id'] = (string) $id;
		}
	}
	return apply_filters( 'daw_pin_boards', $boards );
}

/** The record's dw_country slug, '' when it has none. */
function daw_pin_country( $post_id ) {
	$terms = get_the_terms( $post_id, 'dw_country' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	return $terms[0]->slug;
}

/** The record's topic, as the menu names it. */
function daw_pin_topic( $post_id ) {
	$type = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
	$map  = array(
		'drama'   => 'drama',
		'film'    => 'movie',
		'group'   => 'pop',
		'food'    => 'food',
		'news'    => 'news',
		'travel'  => 'travel',
		'quiz'    => 'test',
		'culture' => 'culture',
		'feature' => 'culture',
		'myth'    => 'myth',
		'variety' => 'variety',
	);
	if ( 'person' === $type ) {
		return 'female' === strtolower( (string) get_post_meta( $post_id, 'daw_gender', true ) ) ? 'actress' : 'actor';
	}
	return $map[ $type ] ?? '';
}

/** The board key for a record, or '' if it is not pinned at all. */
function daw_pin_board_key( $post_id ) {
	$topic = daw_pin_topic( $post_id );
	$c     = daw_pin_country( $post_id );
	$c     = '' === $c ? 'south-korea' : $c;
	$all   = daw_pin_countries();
	if ( '' === $topic || ! isset( $all[ $c ] ) ) {
		return '';
	}
	$key = $all[ $c ][0] . '_' . $topic;
	$old = array_flip(
		array(
			'kdrama'   => 'k_drama',
			'kmovie'   => 'k_movie',
			'actor'    => 'k_actor',
			'actress'  => 'k_actress',
			'kfood'    => 'k_food',
			'kculture' => 'k_culture',
			'news'     => 'k_news',
		)
	);
	return $old[ $key ] ?? $key;
}

function daw_pin_genre_slugs( $post_id ) {
	$terms = get_the_terms( $post_id, 'dw_genre' );
	return ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'slug' ) : array();
}

/** US streaming services for the record (TMDB / JustWatch), as names. */
function daw_pin_streams( $post_id ) {
	return function_exists( 'daw_wp_names' ) ? daw_wp_names( $post_id, 'US', 'stream' ) : array();
}

function daw_pin_on_netflix( $post_id ) {
	foreach ( daw_pin_streams( $post_id ) as $n ) {
		if ( false !== stripos( $n, 'netflix' ) ) {
			return true;
		}
	}
	return false;
}

/** Does the record stream in the US on a service whose name contains $needle? */
function daw_pin_streams_on( $post_id, $needle ) {
	foreach ( daw_pin_streams( $post_id ) as $n ) {
		if ( false !== stripos( $n, $needle ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Korean genre and platform boards the record is also saved to, best first.
 * The pin is saved to at most three of them, days apart (daw_pin_save_due).
 *
 * @return string[] board keys, made or not
 */
function daw_pin_also( $post_id ) {
	$type = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
	$c    = daw_pin_country( $post_id );
	if ( ( '' !== $c && 'south-korea' !== $c ) || ! in_array( $type, array( 'drama', 'film' ), true ) ) {
		return array();
	}
	$g   = daw_pin_genre_slugs( $post_id );
	$has = static function ( $slugs ) use ( $g ) {
		return (bool) array_intersect( $slugs, $g );
	};
	if ( 'drama' === $type ) {
		$want = array(
			'netflix'  => daw_pin_on_netflix( $post_id ),
			'sageuk'   => $has( array( 'historical', 'period-drama' ) ),
			'fantasy'  => $has( array( 'fantasy', 'supernatural', 'time-travel' ) ),
			'hdrama'   => $has( array( 'horror' ) ),
			'thriller' => $has( array( 'thriller', 'crime', 'psychological' ) ),
			'mystery'  => $has( array( 'mystery' ) ),
			'medical'  => $has( array( 'medical' ) ),
			'school'   => $has( array( 'school', 'youth', 'coming-of-age' ) ),
			'romance'  => $has( array( 'romance', 'romantic-comedy' ) ),
			'viki'     => daw_pin_streams_on( $post_id, 'viki' ),
			'prime'    => daw_pin_streams_on( $post_id, 'prime' ),
		);
	} else {
		$want = array(
			'nfmovie'   => daw_pin_on_netflix( $post_id ),
			'khorror'   => $has( array( 'horror', 'zombie' ) ),
			'kthriller' => $has( array( 'thriller', 'crime', 'mystery' ) ),
			'action'    => $has( array( 'action', 'martial-arts' ) ),
			'rommovie'  => $has( array( 'romance', 'romantic-comedy', 'melodrama' ) ),
		);
	}
	return array_keys( array_filter( $want ) );
}

function daw_pin_board_id( $key ) {
	$b = daw_pin_boards();
	return isset( $b[ $key ] ) ? (string) $b[ $key ]['id'] : '';
}

/* ============================================================== settings === */

/**
 * An option of this file, read past the object cache.
 *
 * Two requests at once (Make asking again before the first answer came back,
 * 28 Oct 2026) left LiteSpeed's object cache holding "this option does not
 * exist" for options both had just written, so the day's count read as zero
 * and the daily cap stopped counting. These options are small and read a few
 * times a request; reading them fresh costs nothing that matters.
 */
function daw_pin_opt( $name, $default = array() ) {
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( $name, 'options' );
	return get_option( $name, $default );
}

function daw_pin_settings() {
	$s = daw_pin_opt( 'daw_pin', array() );
	// The first ramp, 3/5/8, was for a new account; this one has pinned the
	// site for years, so the stored first ramp moves up once (28 Oct 2026).
	// A ramp set by hand on the Pinterest screen is left as it is.
	if ( is_array( $s ) && '3,5,8' === ( $s['ramp'] ?? '' ) && empty( $s['rv'] ) ) {
		$s['ramp'] = '6,10,15';
		$s['rv']   = 2;
		update_option( 'daw_pin', $s, false );
	}
	// More boards to save to since 1.75.95: 4 saves a day becomes 6.
	if ( is_array( $s ) && $s && (int) ( $s['rv'] ?? 0 ) < 3 ) {
		if ( 4 === (int) ( $s['saves'] ?? 4 ) ) {
			$s['saves'] = 6;
		}
		$s['rv'] = 3;
		update_option( 'daw_pin', $s, false );
	}
	return wp_parse_args(
		is_array( $s ) ? $s : array(),
		array(
			'on'    => 1,
			'scope' => 'locked', // locked | all
			'from'  => 8,        // New York hours
			'to'    => 22,
			'ramp'  => '6,10,15', // a day: weeks 1-2, weeks 3-4, after
			'start' => '',       // Y-m-d of the first pin, set by the first pin
			'look'  => 'direct', // direct: the TMDB picture as it is | card: drawn card
			'saves' => 6,        // saves of existing pins to more boards, a day
		)
	);
}

/** The shared key Make sends. Made once, readable over the management API. */
function daw_pin_key() {
	$k = (string) get_option( 'daw_pin_key', '' );
	if ( '' === $k ) {
		$k = 'dawpin_' . wp_generate_password( 24, false, false );
		update_option( 'daw_pin_key', $k, false );
	}
	return $k;
}

add_filter( 'royal_mcp_readable_options', 'daw_pin_readable' );
function daw_pin_readable( $keys ) {
	$keys   = (array) $keys;
	$keys[] = 'daw_pin_key';
	$keys[] = 'daw_pin_state';
	$keys[] = 'daw_pin_dupes';
	return array_values( array_unique( $keys ) );
}

/** Pins allowed today, by how long the account has been pinning. */
function daw_pin_cap_today() {
	$s    = daw_pin_settings();
	$ramp = array_map( 'intval', explode( ',', (string) $s['ramp'] ) ) + array( 6, 10, 15 );
	if ( '' === $s['start'] ) {
		return max( 1, $ramp[0] );
	}
	$days = (int) floor( ( time() - strtotime( $s['start'] . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
	if ( $days < 14 ) {
		return max( 1, $ramp[0] );
	}
	if ( $days < 28 ) {
		return max( 1, $ramp[1] );
	}
	return max( 1, $ramp[2] );
}

/* ================================================================ queue === */

function daw_pin_tz() {
	return new DateTimeZone( 'America/New_York' );
}

/** Pins made today (New York day), newest last: list of [post_id, at]. */
function daw_pin_today() {
	$day = ( new DateTime( 'now', daw_pin_tz() ) )->format( 'Y-m-d' );
	$t   = daw_pin_opt( 'daw_pin_day', array() );
	if ( ! is_array( $t ) || ( $t['day'] ?? '' ) !== $day ) {
		$t = array( 'day' => $day, 'pins' => array() );
	}
	return $t;
}

/**
 * Records that may be pinned, most visited first.
 *
 * @return int[]
 */
function daw_pin_candidates() {
	$s    = daw_pin_settings();
	$meta = array(
		array( 'key' => DAW_PIN_LOG, 'compare' => 'NOT EXISTS' ),
		array( 'key' => DAW_PIN_SKIP, 'compare' => 'NOT EXISTS' ),
	);
	if ( 'all' !== $s['scope'] ) {
		// The same match the posts screen uses for "Kilitli (bitti)".
		$meta[] = array( 'key' => '_daw_audit', 'value' => 's:4:"lock";b:1;', 'compare' => 'LIKE' );
	}
	$ids = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	if ( ! $ids ) {
		return array();
	}
	update_meta_cache( 'post', $ids );
	usort(
		$ids,
		static function ( $a, $b ) {
			$d = (int) get_post_meta( $b, 'daw_views_30', true ) - (int) get_post_meta( $a, 'daw_views_30', true );
			if ( 0 === $d ) {
				$d = (int) get_post_meta( $b, 'daw_views_all', true ) - (int) get_post_meta( $a, 'daw_views_all', true );
			}
			return 0 !== $d ? $d : $b - $a;
		}
	);
	return array_map( 'intval', $ids );
}

/** Can this one go now? Checks that are too slow to put in the query. */
function daw_pin_ready( $post_id ) {
	if ( '' === daw_pin_board_id( daw_pin_board_key( $post_id ) ) ) {
		return false;
	}
	if ( 'all' !== daw_pin_settings()['scope'] && function_exists( 'daw_ae_is_locked' ) && ! daw_ae_is_locked( $post_id ) ) {
		return false; // locked once, edited since: wait for the audit
	}
	return true;
}

/**
 * Why nothing goes now, or '' when something may.
 */
function daw_pin_wait_reason() {
	$s = daw_pin_settings();
	if ( ! $s['on'] ) {
		return 'off';
	}
	$hour = (int) ( new DateTime( 'now', daw_pin_tz() ) )->format( 'G' );
	if ( $hour < (int) $s['from'] || $hour >= (int) $s['to'] ) {
		return 'outside hours';
	}
	$t   = daw_pin_today();
	$cap = daw_pin_cap_today();
	if ( count( $t['pins'] ) >= $cap ) {
		return 'daily cap reached';
	}
	// Spread the day's pins over the window rather than spending them at once.
	$gap  = (int) floor( ( (int) $s['to'] - (int) $s['from'] ) * HOUR_IN_SECONDS / $cap );
	$last = $t['pins'] ? (int) end( $t['pins'] )[1] : 0;
	if ( $last && time() - $last < $gap ) {
		return 'too soon';
	}
	return '';
}

/**
 * Pick the next record.
 *
 * @return int post id or 0
 */
function daw_pin_pick() {
	$t         = daw_pin_today();
	$last_post = $t['pins'] ? (int) end( $t['pins'] )[0] : (int) daw_pin_opt( 'daw_pin_last', 0 );
	$last_key  = $last_post ? daw_pin_board_key( $last_post ) : '';

	// Every third pin of the day goes to a page that already has its first
	// pin, with the drawn card this time; so do all pins once every page has
	// its first one.
	$second = daw_pin_second_candidates();
	if ( $second && 2 === count( $t['pins'] ) % 3 ) {
		return array( $second[0], 'card' );
	}

	$fallback = 0;
	foreach ( daw_pin_candidates() as $id ) {
		if ( ! daw_pin_ready( $id ) ) {
			continue;
		}
		if ( daw_pin_board_key( $id ) !== $last_key ) {
			return array( $id, '' );
		}
		if ( ! $fallback ) {
			$fallback = $id;
		}
	}
	if ( $fallback ) {
		return array( $fallback, '' );
	}
	return $second ? array( $second[0], 'card' ) : array( 0, '' );
}

/**
 * Pages ready for their second pin: one pin so far, made with the TMDB
 * picture itself, at least 14 days ago, and a drawn card that is not that
 * picture. Most visited first.
 *
 * @return int[]
 */
function daw_pin_second_candidates() {
	$ids = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array( 'key' => DAW_PIN_LOG, 'compare' => 'EXISTS' ),
				array( 'key' => DAW_PIN_SKIP, 'compare' => 'NOT EXISTS' ),
			),
		)
	);
	if ( ! $ids ) {
		return array();
	}
	update_meta_cache( 'post', $ids );
	$out = array();
	foreach ( $ids as $id ) {
		$log = get_post_meta( $id, DAW_PIN_LOG, true );
		$ok  = array_values( array_filter( is_array( $log ) ? $log : array(), static function ( $e ) {
			return 'atlandi' !== ( $e['pin'] ?? '' );
		} ) );
		if ( 1 !== count( $ok ) || (int) $ok[0]['at'] > time() - 14 * DAY_IN_SECONDS ) {
			continue;
		}
		$photo = daw_pin_photo( $id );
		// The first pin was a poster: ours (the TMDB picture) or one of the
		// account's pins from before this system, adopted below.
		$was_poster = 0 === strpos( (string) $ok[0]['img'], 'old:' ) || ( $photo && 'tmdb' === $photo['kind'] && md5( 'tmdb:' . substr( $photo['id'], 5 ) ) === $ok[0]['img'] );
		if ( ! $was_poster ) {
			continue;
		}
		if ( '' === daw_pin_board_id( daw_pin_board_key( $id ) ) ) {
			continue;
		}
		$out[] = (int) $id;
	}
	usort(
		$out,
		static function ( $a, $b ) {
			return (int) get_post_meta( $b, 'daw_views_30', true ) - (int) get_post_meta( $a, 'daw_views_30', true );
		}
	);
	return $out;
}

/**
 * When the waiting records will go, at the current pace: the order they are
 * picked in and the New York day each one falls on. An estimate: an edit that
 * breaks a lock, or new traffic, moves a record up or down.
 *
 * @return array list of [post_id, Y-m-d]
 */
function daw_pin_plan( $limit = 60 ) {
	$s     = daw_pin_settings();
	$ramp  = array_map( 'intval', explode( ',', (string) $s['ramp'] ) ) + array( 6, 10, 15 );
	$start = '' !== $s['start'] ? strtotime( $s['start'] . ' 00:00:00 UTC' ) : time();
	$day   = new DateTime( 'now', daw_pin_tz() );
	$left  = daw_pin_cap_today() - count( daw_pin_today()['pins'] );
	if ( (int) $day->format( 'G' ) >= (int) $s['to'] ) {
		$left = 0;
	}

	// Picked as daw_pin_pick() picks: never the same board twice in a row
	// while another board has something waiting.
	$queue = array();
	foreach ( daw_pin_candidates() as $id ) {
		if ( daw_pin_ready( $id ) ) {
			$queue[] = array( $id, daw_pin_board_key( $id ) );
		}
	}
	$order = array();
	$last  = '';
	while ( $queue && count( $order ) < $limit ) {
		$at = 0;
		foreach ( $queue as $i => $q ) {
			if ( $q[1] !== $last ) {
				$at = $i;
				break;
			}
		}
		$order[] = $queue[ $at ][0];
		$last    = $queue[ $at ][1];
		array_splice( $queue, $at, 1 );
	}

	$plan = array();
	foreach ( $order as $id ) {
		while ( $left <= 0 ) {
			$day->modify( '+1 day' );
			$days = (int) floor( ( $day->getTimestamp() - $start ) / DAY_IN_SECONDS );
			$left = max( 1, $days < 14 ? $ramp[0] : ( $days < 28 ? $ramp[1] : $ramp[2] ) );
		}
		$plan[] = array( $id, $day->format( 'Y-m-d' ) );
		$left--;
	}
	return $plan;
}

/* ================================================================== text === */

/** Plain text: no tags, no entities, no long dashes, single spaces. */
function daw_pin_plain( $text ) {
	$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
	$text = preg_replace( '/\s*[\x{2014}\x{2013}]\s*/u', ', ', $text );
	return trim( preg_replace( '/\s+/u', ' ', $text ) );
}

/** Cut at a word boundary to at most $max characters. */
function daw_pin_cut( $text, $max ) {
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut = mb_substr( $text, 0, $max - 1 );
	$sp  = mb_strrpos( $cut, ' ' );
	if ( false !== $sp && $sp > $max * 0.6 ) {
		$cut = mb_substr( $cut, 0, $sp );
	}
	return rtrim( $cut, " ,.;:" ) . '…';
}

function daw_pin_year( $post_id ) {
	$v = get_post_meta( $post_id, 'daw_aired_start', true );
	if ( is_numeric( $v ) && (int) $v > 100000 ) {
		return gmdate( 'Y', (int) $v ); // stored as a UTC-midnight timestamp
	}
	return preg_match( '/^(19|20)\d\d/', (string) $v, $m ) ? $m[0] : '';
}

function daw_pin_terms( $post_id, $tax, $n ) {
	$terms = get_the_terms( $post_id, $tax );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return array();
	}
	return array_slice( wp_list_pluck( $terms, 'name' ), 0, $n );
}

/** What the record is, in the words people search with: "K-Drama", "Korean Movie". */
function daw_pin_kind( $post_id ) {
	$type  = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
	$c     = daw_pin_country( $post_id );
	$where = array(
		''            => 'Korean',
		'south-korea' => 'Korean',
		'china'       => 'Chinese',
		'hong-kong'   => 'Hong Kong',
		'japan'       => 'Japanese',
		'taiwan'      => 'Taiwanese',
		'thailand'    => 'Thai',
		'philippines' => 'Filipino',
		'singapore'   => 'Singaporean',
	);
	$adj = $where[ $c ] ?? 'Asian';

	switch ( $type ) {
		case 'drama':
			$short = array( 'Korean' => 'K-Drama', 'Chinese' => 'C-Drama', 'Japanese' => 'J-Drama', 'Thai' => 'Thai Drama', 'Taiwanese' => 'Taiwanese Drama' );
			return $short[ $adj ] ?? $adj . ' Drama';
		case 'film':
			return $adj . ' Movie';
		case 'variety':
			return $adj . ' Variety Show';
		case 'person':
			return $adj . ( 'female' === strtolower( (string) get_post_meta( $post_id, 'daw_gender', true ) ) ? ' Actress' : ' Actor' );
		case 'group':
			return 'K-Pop Group';
		case 'news':
			return 'K-Drama News';
		case 'food':
			return $adj . ' Food';
		case 'travel':
			return ( daw_pin_countries()[ '' === $c ? 'south-korea' : $c ][2] ?? 'Asia' ) . ' Travel';
		case 'myth':
			return $adj . ' Myth';
		case 'culture':
			return $adj . ' Culture';
	}
	return '';
}

/** The name in its own script, short enough to set large. */
function daw_pin_native( $post_id ) {
	foreach ( array( 'daw_native_title', 'daw_native_name' ) as $k ) {
		$v = trim( (string) get_post_meta( $post_id, $k, true ) );
		$v = trim( preg_replace( '/\s*[\(（].*$/u', '', $v ) );
		if ( '' !== $v && mb_strlen( $v ) <= 8 && '' !== daw_card_native_family( $v ) ) {
			return $v;
		}
	}
	return '';
}

/** Everything one pin says. */
function daw_pin_text( $post_id ) {
	$type  = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
	$name  = daw_pin_plain( get_the_title( $post_id ) );
	$kind  = daw_pin_kind( $post_id );
	$year  = daw_pin_year( $post_id );
	$genre = daw_pin_terms( $post_id, 'dw_genre', 3 );
	$net   = daw_pin_terms( $post_id, 'dw_network', 1 );
	$title_like = in_array( $type, array( 'drama', 'film', 'variety' ), true );

	// Title: the searched pattern first, shorter forms when it runs long.
	$tries = array( $name );
	if ( $title_like ) {
		$bk    = daw_pin_boards()[ daw_pin_board_key( $post_id ) ]['kind'] ?? $kind;
		$base  = $name . ( '' !== $year ? ' (' . $year . ')' : '' ) . ' ' . $bk;
		$tries = array( $base . ': Cast, Story and Where to Watch', $base . ': Cast and Story', $base, $name );
	} elseif ( 'person' === $type ) {
		$tries = array( $name . ': ' . $kind . ' Profile, Dramas and Movies', $name . ': ' . $kind . ' Profile', $name );
	}
	$title = '';
	foreach ( $tries as $t ) {
		if ( mb_strlen( $t ) <= 100 ) {
			$title = $t;
			break;
		}
	}
	if ( '' === $title ) {
		$title = daw_pin_cut( $name, 100 );
	}

	// Description: the page's own written summary, then facts the record holds.
	$lead = daw_pin_plain( get_post_meta( $post_id, 'rank_math_description', true ) );
	if ( '' === $lead ) {
		$lead = daw_pin_plain( get_post_field( 'post_excerpt', $post_id ) );
	}
	$facts = array();
	if ( $genre ) {
		$facts[] = 'Genre: ' . implode( ', ', $genre ) . '.';
	}
	if ( $title_like && $net ) {
		$facts[] = 'Network: ' . $net[0] . '.';
	}
	$streams = $title_like ? array_slice( daw_pin_streams( $post_id ), 0, 3 ) : array();
	if ( $streams ) {
		$facts[] = 'Stream it in the US on ' . implode( ', ', $streams ) . '.';
	}
	$eps = (int) get_post_meta( $post_id, 'daw_episodes', true );
	if ( 'drama' === $type && $eps > 0 ) {
		$facts[] = $eps . ' episodes.';
	}

	$tags = array(
		'drama'   => array( 'kdrama', 'koreandrama', 'kdramarecommendations' ),
		'film'    => array( 'koreanmovie', 'koreanfilm', 'kmovie' ),
		'variety' => array( 'koreanvariety', 'kvariety', 'korea' ),
		'person'  => array( 'kdrama', 'koreanactor', 'kdramaactors' ),
		'group'   => array( 'kpop', 'korea' ),
		'news'    => array( 'kdramanews', 'kdrama', 'koreanentertainment' ),
		'food'    => array( 'koreanfood', 'koreanrecipes', 'kfood' ),
		'travel'  => array( 'koreatravel', 'southkorea', 'seoul' ),
		'myth'    => array( 'koreanmythology', 'koreanculture', 'korea' ),
	);
	$hash = $tags[ $type ] ?? array( 'koreanculture', 'korea', 'kdrama' );
	if ( $title_like && daw_pin_on_netflix( $post_id ) ) {
		$hash[] = 'drama' === $type ? 'netflixkdrama' : 'netflix';
	}
	if ( 'person' === $type && 'actress' === daw_pin_board_key( $post_id ) ) {
		$hash[1] = 'koreanactress';
	}
	// Other countries: the country's own searched tags, by topic.
	$c = daw_pin_country( $post_id );
	if ( '' !== $c && 'south-korea' !== $c ) {
		$country_tags = array(
			'china'       => array( 'cdrama', 'chinesedrama', 'cdramarecommendations' ),
			'japan'       => array( 'jdrama', 'japanesedrama', 'japan' ),
			'thailand'    => array( 'thaidrama', 'lakorn', 'thailand' ),
			'taiwan'      => array( 'taiwanesedrama', 'twdrama', 'taiwan' ),
			'hong-kong'   => array( 'tvbdrama', 'hongkongdrama', 'hongkong' ),
			'philippines' => array( 'pinoydrama', 'teleserye', 'philippines' ),
			'singapore'   => array( 'singaporedrama', 'mediacorp', 'singapore' ),
		);
		$place = str_replace( '-', '', $c );
		$kh    = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $kind ) );
		$hash  = 'drama' === $type && isset( $country_tags[ $c ] )
			? $country_tags[ $c ]
			: array_values( array_unique( array_filter( array( $kh, $place ) ) ) );
	}
	if ( 'person' === $type ) {
		$h = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $name ) );
		if ( '' !== $h ) {
			$hash[] = $h;
		}
	}
	foreach ( $genre as $g ) {
		if ( count( $hash ) >= 5 ) {
			break;
		}
		$h = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $g ) );
		if ( '' !== $h && ! in_array( $h, $hash, true ) ) {
			$hash[] = $h;
		}
	}
	$hash = '#' . implode( ' #', $hash );

	$tail = trim( implode( ' ', $facts ) . ' Read the full guide on Discover Asian World.' );
	$desc = daw_pin_cut( $lead, 800 - mb_strlen( $tail ) - mb_strlen( $hash ) - 4 );
	$desc = trim( $desc . "\n\n" . $tail . "\n\n" . $hash );

	// The small line over the headline on the card.
	$kicker = array( $kind );
	if ( '' !== $year && $title_like ) {
		$kicker[] = $year;
	}
	if ( $net && $title_like ) {
		$kicker[] = $net[0];
	}
	if ( 'news' === $type ) {
		$k = trim( (string) get_post_meta( $post_id, 'daw_news_kicker', true ) );
		if ( '' !== $k ) {
			$kicker = array( $k );
		}
	}
	$sub = '';
	if ( $title_like ) {
		$sub = 'Cast, story and where to watch';
	} elseif ( 'person' === $type ) {
		$sub = 'Profile, dramas and movies';
	}

	return array(
		'title'  => $title,
		'desc'   => $desc,
		'name'   => $name,
		'kind'   => $kind,
		'year'   => $title_like ? $year : '',
		'kicker' => implode( '  ·  ', array_filter( $kicker ) ), // network names keep their case: tvN, not TVN
		'sub'    => $sub,
		'native' => daw_pin_native( $post_id ),
		'link'   => add_query_arg( array( 'utm_source' => 'pinterest', 'utm_medium' => 'social', 'utm_campaign' => 'pin' ), get_permalink( $post_id ) ),
	);
}

/* ================================================================ picture === */

/**
 * The picture on top of the pin.
 *
 * First the record's own TMDB poster (titles) or profile photo (people), the
 * same picture the page shows from TMDB, used only when the TMDB match was
 * confirmed (owner, 28 Oct 2026: "görseller sitemdeki TMDB API görselleri
 * olacak, linkler sitemde"). Then a featured image with an allowed licence
 * record. Nothing else: no picture means the drawn seal.
 *
 * @return array {id, url, path, att, kind} or empty
 */
function daw_pin_photo( $post_id ) {
	$tmdb = function_exists( 'daw_tmdb_img_path' ) ? daw_tmdb_img_path( $post_id ) : '';
	if ( '' !== $tmdb ) {
		return array( 'id' => 'tmdb:' . $tmdb, 'url' => 'https://image.tmdb.org/t/p/w780' . $tmdb, 'path' => '', 'att' => 0, 'kind' => 'tmdb' );
	}
	$thumb = (int) get_post_thumbnail_id( $post_id );
	if ( ! $thumb || ! function_exists( 'daw_lic_status' ) || 'ok' !== daw_lic_status( $thumb, $post_id ) ) {
		return array();
	}
	// A news card is our own drawing of the headline; the pin draws its own.
	if ( $thumb === (int) get_post_meta( $post_id, '_daw_card_att', true ) ) {
		return array();
	}
	$path = get_attached_file( $thumb );
	return ( $path && file_exists( $path ) ) ? array( 'id' => 'att:' . $thumb, 'url' => '', 'path' => $path, 'att' => $thumb, 'kind' => 'att' ) : array();
}

/** The photo as a GD image, or false. */
function daw_pin_photo_im( $photo ) {
	if ( ! $photo ) {
		return false;
	}
	if ( '' !== $photo['path'] ) {
		$bytes = (string) file_get_contents( $photo['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	} else {
		$r = wp_remote_get( $photo['url'], array( 'timeout' => 20 ) );
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			return false;
		}
		$bytes = (string) wp_remote_retrieve_body( $r );
	}
	return '' !== $bytes ? @imagecreatefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

/**
 * Copy $src into a box, cropped to fill it. Centred across; down the picture
 * the crop sits at $ay (0 top, 0.5 middle): faces on posters sit high, the
 * title block they cut away sits low and is set in the panel anyway.
 */
function daw_pin_cover( $im, $src, $x, $y, $w, $h, $ay = 0.5 ) {
	$sw = imagesx( $src );
	$sh = imagesy( $src );
	$r  = max( $w / $sw, $h / $sh );
	$cw = (int) round( $w / $r );
	$ch = (int) round( $h / $r );
	imagecopyresampled( $im, $src, $x, $y, (int) ( ( $sw - $cw ) / 2 ), (int) ( ( $sh - $ch ) * $ay ), $w, $h, $cw, $ch );
}

/**
 * Draw one pin.
 *
 * @return resource|\GdImage|WP_Error
 */
function daw_pin_render( $post_id ) {
	if ( ! function_exists( 'daw_card_capable' ) || ! daw_card_capable() ) {
		return new WP_Error( 'daw_pin_gd', 'GD with FreeType is not available on this server.' );
	}
	$d      = daw_pin_text( $post_id );
	$f_head = daw_card_font( 'Newsreader', 600 );
	$f_ui   = daw_card_font( 'Bricolage Grotesque', 500 );
	$f_bold = daw_card_font( 'Bricolage Grotesque', 800 );
	if ( ! $f_head || ! $f_ui || ! $f_bold ) {
		return new WP_Error( 'daw_pin_font', 'The card fonts could not be fetched from Google Fonts.' );
	}

	$W     = DAW_PIN_W;
	$H     = DAW_PIN_H;
	$src   = daw_pin_photo_im( daw_pin_photo( $post_id ) );
	$top   = $src ? 1060 : 860; // the picture's share of the pin
	$lines_max = $src ? 2 : 3;
	$im    = imagecreatetruecolor( $W, $H );
	imagealphablending( $im, true );

	$paper  = daw_card_rgb( $im, 'FAF7F2' );
	$ink    = daw_card_rgb( $im, '1C1D21' );
	$muted  = daw_card_rgb( $im, '6B655B' );
	$gold   = daw_card_rgb( $im, 'C9971F' );
	$dgold  = daw_card_rgb( $im, 'A9741A' );
	$maroon = daw_card_rgb( $im, '7E2128' );
	$soft   = daw_card_rgb( $im, 'E8D2C8' );

	imagefilledrectangle( $im, 0, 0, $W, $H, $paper );

	$logo = daw_card_logo_path();
	$lsrc = $logo ? imagecreatefromstring( (string) file_get_contents( $logo ) ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( $lsrc ) {
		imagealphablending( $lsrc, true );
		imagesavealpha( $lsrc, true );
	}

	if ( $src ) {
		daw_pin_cover( $im, $src, 0, 0, $W, $top, 0.2 );
		imagedestroy( $src );
	} else {
		// The seal: maroon, a gold frame, the logo, the name in its own script.
		imagefilledrectangle( $im, 0, 0, $W, $top, $maroon );
		imagesetthickness( $im, 3 );
		imagerectangle( $im, 34, 34, $W - 35, $top - 35, $gold );
		imagesetthickness( $im, 1 );

		if ( $lsrc ) {
			$ls = 210;
			imagecopyresampled( $im, $lsrc, (int) ( ( $W - $ls ) / 2 ), 92, 0, 0, $ls, $ls, imagesx( $lsrc ), imagesy( $lsrc ) );
		}
		$word = 'DISCOVER ASIAN WORLD';
		$tw   = daw_card_tw( 22, $f_ui, $word ) + 4 * ( mb_strlen( $word ) - 1 );
		daw_card_tracked( $im, 22, ( $W - $tw ) / 2, 352, $soft, $f_ui, $word, 4 );

		$big = '' !== $d['native'] ? $d['native'] : mb_strtoupper( $d['kind'] );
		$fb  = '' !== $d['native'] ? daw_card_font( daw_card_native_family( $d['native'] ), 700, $d['native'] ) : $f_bold;
		if ( $fb ) {
			$px = 230;
			do {
				$b  = imagettfbbox( $px * 0.75, 0, $fb, $big );
				$bw = abs( $b[2] - $b[0] );
				$bh = abs( $b[1] - $b[7] );
				if ( $bw <= $W - 180 && $bh <= 330 ) {
					break;
				}
				$px -= 8;
			} while ( $px > 60 );
			imagettftext( $im, $px * 0.75, 0, (int) ( ( $W - $bw ) / 2 - $b[0] ), (int) ( 425 + ( 360 + $bh ) / 2 - $b[1] ), $gold, $fb, $big );
		}
	}

	// The gold line between picture and panel.
	imagefilledrectangle( $im, 0, $top, $W, $top + 9, $gold );

	// Kicker.
	$x  = 70;
	$ky = $top + ( $src ? 74 : 92 );
	if ( '' !== $d['kicker'] ) {
		$px = 26;
		while ( daw_card_tw( $px, $f_bold, $d['kicker'] ) + 2 * mb_strlen( $d['kicker'] ) > $W - 2 * $x && $px > 18 ) {
			$px -= 2;
		}
		daw_card_tracked( $im, $px, $x, $ky, $dgold, $f_bold, $d['kicker'], 2 );
	}

	// Headline: as large as it can be in the lines the panel has.
	$px    = $src ? 70 : 78;
	$lines = daw_card_wrap( $px, $f_head, $d['name'], $W - 2 * $x );
	while ( count( $lines ) > $lines_max && $px > 40 ) {
		$px   -= 4;
		$lines = daw_card_wrap( $px, $f_head, $d['name'], $W - 2 * $x );
	}
	$lh = (int) round( $px * 1.16 );
	$y  = $ky + ( $src ? 18 : 30 ) + $px;
	foreach ( array_slice( $lines, 0, $lines_max + 1 ) as $ln ) {
		imagettftext( $im, $px * 0.75, 0, $x, $y, $ink, $f_head, $ln );
		$y += $lh;
	}

	if ( '' !== $d['sub'] && $y + 6 < $H - 130 ) {
		imagettftext( $im, 32 * 0.75, 0, $x, $y + 6, $muted, $f_ui, $d['sub'] );
	}

	// Foot: the logo and the address.
	$fy = $H - 62;
	imagefilledrectangle( $im, $x, $fy - 52, $W - $x, $fy - 51, daw_card_rgb( $im, 'E2D9CC' ) );
	if ( $lsrc ) {
		imagecopyresampled( $im, $lsrc, $x, $fy - 36, 0, 0, 52, 52, imagesx( $lsrc ), imagesy( $lsrc ) );
		imagedestroy( $lsrc );
	}
	imagettftext( $im, 28 * 0.75, 0, $x + 66, $fy, $maroon, $f_bold, 'discoverasianworld.com' );

	return $im;
}

/**
 * The pin picture as a file in uploads, drawn once per version of the text.
 *
 * @return array|WP_Error {url, path, hash, photo}
 */
function daw_pin_image( $post_id, $look = '' ) {
	$photo = daw_pin_photo( $post_id );
	$look  = '' !== $look ? $look : daw_pin_settings()['look'];

	// The TMDB picture itself: posters and profile photos are already 2:3,
	// which is Pinterest's own shape. Asked for at full size so the pin is
	// sharp; nothing is drawn or stored here.
	if ( $photo && 'tmdb' === $photo['kind'] && 'card' !== $look ) {
		$path = substr( $photo['id'], 5 );
		return array(
			'url'   => 'https://image.tmdb.org/t/p/original' . $path,
			'path'  => '',
			'hash'  => md5( 'tmdb:' . $path ),
			'photo' => $photo,
		);
	}

	$d     = daw_pin_text( $post_id );
	$hash  = md5( wp_json_encode( array( $d['name'], $d['kicker'], $d['sub'], $d['native'], $photo['id'] ?? '', DAW_PIN_REV ) ) );
	$up   = wp_upload_dir();
	$dir  = trailingslashit( $up['basedir'] ) . 'daw-pins';
	$name = 'pin-' . (int) $post_id . '-' . substr( $hash, 0, 10 ) . '.jpg';
	$path = $dir . '/' . $name;

	if ( ! file_exists( $path ) ) {
		$im = daw_pin_render( $post_id );
		if ( is_wp_error( $im ) ) {
			return $im;
		}
		wp_mkdir_p( $dir );
		// Older drawings of the same record go; only the current one is kept.
		foreach ( (array) glob( $dir . '/pin-' . (int) $post_id . '-*.jpg' ) as $old ) {
			wp_delete_file( $old );
		}
		imagejpeg( $im, $path, 88 );
		imagedestroy( $im );
	}

	return array(
		'url'   => trailingslashit( $up['baseurl'] ) . 'daw-pins/' . $name,
		'path'  => $path,
		'hash'  => $hash,
		'photo' => $photo,
	);
}

/** The whole pin, as Make sends it to Pinterest. */
function daw_pin_item( $post_id, $look = '' ) {
	$img = daw_pin_image( $post_id, $look );
	if ( is_wp_error( $img ) ) {
		return $img;
	}
	$d   = daw_pin_text( $post_id );
	$key = daw_pin_board_key( $post_id );

	$type = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
	if ( $img['photo'] && 'tmdb' === $img['photo']['kind'] && '' === $img['path'] ) {
		$alt = 'person' === $type
			? $d['name'] . ', ' . $d['kind'] . ', photo'
			: 'Poster of ' . $d['name'] . ( '' !== $d['year'] ? ' (' . $d['year'] . ')' : '' ) . ', ' . $d['kind'];
	} elseif ( $img['photo'] && 'tmdb' === $img['photo']['kind'] ) {
		$alt = ( 'person' === $type ? $d['name'] . ', ' . $d['kind'] : 'Poster of ' . $d['name'] . ( '' !== $d['year'] ? ' (' . $d['year'] . ')' : '' ) ) . ', with the title card from Discover Asian World';
	} elseif ( $img['photo'] ) {
		$alt = trim( (string) get_post_meta( $img['photo']['att'], '_wp_attachment_image_alt', true ) );
		$alt = '' !== $alt ? $alt . '. ' . $d['name'] . ', ' . $d['kind'] : $d['name'] . ', ' . $d['kind'];
	} else {
		$alt = 'Title card for ' . $d['name'] . ( '' !== $d['year'] ? ' (' . $d['year'] . ')' : '' ) . ( '' !== $d['kind'] ? ', ' . $d['kind'] : '' ) . ', from Discover Asian World';
	}

	return array(
		'id'          => (int) $post_id,
		'board'       => $key,
		'board_id'    => daw_pin_board_id( $key ),
		'title'       => $d['title'],
		'description' => $d['desc'],
		'link'        => $d['link'],
		'image_url'   => $img['url'],
		'alt_text'    => daw_pin_cut( daw_pin_plain( $alt ), 500 ),
		'img'         => $img['hash'],
	);
}

/* ================================================================== REST === */

add_action( 'rest_api_init', 'daw_pin_routes' );
function daw_pin_routes() {
	$auth = static function ( WP_REST_Request $r ) {
		return hash_equals( daw_pin_key(), (string) $r->get_header( 'x-daw-pin-key' ) );
	};
	register_rest_route( 'daw/v1', '/pin/next', array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => 'daw_pin_rest_next' ) );
	register_rest_route( 'daw/v1', '/pin/done', array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => 'daw_pin_rest_done' ) );
	register_rest_route( 'daw/v1', '/pin/status', array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => 'daw_pin_rest_status' ) );
	register_rest_route( 'daw/v1', '/pin/saved', array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => 'daw_pin_rest_saved' ) );
	register_rest_route( 'daw/v1', '/pin/board', array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => 'daw_pin_rest_board' ) );
	register_rest_route( 'daw/v1', '/pin/adopt', array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => 'daw_pin_rest_adopt' ) );
}

/**
 * A board that has a record ready for it and does not exist yet, or array().
 *
 * Asked for by key, name and description; the drip makes it on Pinterest
 * and posts the id to /pin/board. At most three a day, the one with the
 * most records waiting first, so the account grows its boards as the site
 * grows its categories. A board Pinterest refused is not asked for again
 * for a day.
 */
function daw_pin_board_wanted() {
	$t = daw_pin_today();
	if ( (int) ( $t['boards'] ?? 0 ) >= 3 ) {
		return array();
	}
	$need = get_transient( 'daw_pin_need' );
	if ( ! is_array( $need ) ) {
		$boards = daw_pin_boards();
		$need   = array();
		$count  = static function ( $k ) use ( &$need, $boards ) {
			if ( isset( $boards[ $k ] ) && '' === $boards[ $k ]['id'] ) {
				$need[ $k ] = ( $need[ $k ] ?? 0 ) + 1;
			}
		};
		$scope = daw_pin_settings()['scope'];
		foreach ( daw_pin_candidates() as $id ) {
			if ( 'all' === $scope || ! function_exists( 'daw_ae_is_locked' ) || daw_ae_is_locked( $id ) ) {
				$count( daw_pin_board_key( $id ) );
			}
		}
		$pinned = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => DAW_PIN_LOG, 'compare' => 'EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $pinned as $id ) {
			foreach ( daw_pin_also( $id ) as $k ) {
				$count( $k );
			}
		}
		arsort( $need );
		set_transient( 'daw_pin_need', $need, HOUR_IN_SECONDS );
	}
	$fail = daw_pin_opt( 'daw_pin_board_fail', array() );
	$all  = daw_pin_boards();
	foreach ( array_keys( $need ) as $k ) {
		if ( ! isset( $all[ $k ] ) || '' !== $all[ $k ]['id'] || ( is_array( $fail ) && time() - (int) ( $fail[ $k ] ?? 0 ) < DAY_IN_SECONDS ) ) {
			continue;
		}
		return array(
			'key'         => $k,
			'name'        => $all[ $k ]['name'],
			'description' => $all[ $k ]['desc'] . ' From Discover Asian World.',
		);
	}
	return array();
}

/** Make made a board (or could not): keep its id. */
function daw_pin_rest_board( WP_REST_Request $r ) {
	$p   = $r->get_json_params();
	$key = sanitize_key( (string) ( $p['key'] ?? '' ) );
	$id  = preg_replace( '/[^0-9]/', '', (string) ( $p['board_id'] ?? '' ) );
	if ( '' === $key || ! isset( daw_pin_boards()[ $key ] ) ) {
		return new WP_Error( 'daw_pin_bad', 'unknown board key.', array( 'status' => 400 ) );
	}
	$t           = daw_pin_today();
	$t['boards'] = (int) ( $t['boards'] ?? 0 ) + 1;
	update_option( 'daw_pin_day', $t, false );
	if ( '' === $id || ! empty( $p['failed'] ) ) {
		$fail         = daw_pin_opt( 'daw_pin_board_fail', array() );
		$fail         = is_array( $fail ) ? $fail : array();
		$fail[ $key ] = time();
		update_option( 'daw_pin_board_fail', $fail, false );
		return array( 'ok' => false, 'key' => $key );
	}
	$ids         = daw_pin_opt( 'daw_pin_board_ids', array() );
	$ids         = is_array( $ids ) ? $ids : array();
	$ids[ $key ] = $id;
	update_option( 'daw_pin_board_ids', $ids, false );
	return array( 'ok' => true, 'key' => $key, 'board_id' => $id );
}

function daw_pin_rest_next( WP_REST_Request $r ) {
	// A record handed out and not written back yet is handed out again, not a
	// second record: Make may ask twice before Pinterest has answered.
	$held = daw_pin_opt( 'daw_pin_held', array() );
	if ( is_array( $held ) && ! empty( $held['id'] ) && time() - (int) $held['at'] < 30 * MINUTE_IN_SECONDS && daw_pin_log_count( (int) $held['id'] ) === (int) ( $held['n'] ?? 0 ) ) {
		$item = daw_pin_item( (int) $held['id'], (string) ( $held['look'] ?? '' ) );
		return is_wp_error( $item ) ? array( 'item' => null, 'why' => $item->get_error_message() ) : array( 'item' => $item );
	}

	$why = daw_pin_wait_reason();
	if ( 'off' !== $why && 'outside hours' !== $why ) {
		$board = daw_pin_board_wanted();
		if ( $board ) {
			return array( 'item' => null, 'why' => 'board', 'board' => $board );
		}
	}
	if ( '' !== $why && ! $r->get_param( 'force' ) ) {
		// Between new pins, an existing pin may be saved to one more board.
		$t = daw_pin_today();
		if ( in_array( $why, array( 'too soon', 'daily cap reached' ), true ) && (int) ( $t['saves'] ?? 0 ) < (int) daw_pin_settings()['saves'] ) {
			$due = daw_pin_save_due();
			if ( $due ) {
				return array(
					'item' => null,
					'why'  => $why,
					'save' => array(
						'id'       => $due[0],
						'board'    => $due[1],
						'board_id' => daw_pin_board_id( $due[1] ),
						'pin_id'   => $due[2],
					),
				);
			}
		}
		return array( 'item' => null, 'why' => $why );
	}
	list( $id, $look ) = daw_pin_pick();
	if ( ! $id ) {
		return array( 'item' => null, 'why' => 'nothing ready' );
	}
	$item = daw_pin_item( $id, $look );
	if ( is_wp_error( $item ) ) {
		return array( 'item' => null, 'why' => $item->get_error_message() );
	}
	// Never the same picture on the same page twice.
	$log = get_post_meta( $id, DAW_PIN_LOG, true );
	if ( is_array( $log ) && in_array( $item['img'], wp_list_pluck( $log, 'img' ), true ) ) {
		return array( 'item' => null, 'why' => 'picture already pinned for this page' );
	}
	update_option( 'daw_pin_held', array( 'id' => $id, 'at' => time(), 'look' => $look, 'n' => daw_pin_log_count( $id ) ), false );
	return array( 'item' => $item );
}

function daw_pin_rest_done( WP_REST_Request $r ) {
	$p   = $r->get_json_params();
	$id  = (int) ( $p['id'] ?? 0 );
	$pin = sanitize_text_field( (string) ( $p['pin_id'] ?? '' ) );
	if ( ! $id || '' === $pin || 'post' !== get_post_type( $id ) ) {
		return new WP_Error( 'daw_pin_bad', 'id and pin_id are required.', array( 'status' => 400 ) );
	}
	daw_pin_record( $id, $pin );
	return array( 'ok' => true, 'id' => $id, 'pin_id' => $pin );
}

/**
 * Adopt a pin the account made before this system (2024-2025).
 *
 * Those pins are posters that already link to the site, with titles like
 * "Coffee Prince - Asian World", descriptions like "Gong Yoo 52" and no alt
 * text; several pages were pinned twice. Make walks the old boards and posts
 * each pin here. The page it links to gets the pin in its log, so this
 * system never pins the same poster to it again (its second pin, the card,
 * follows 14 days on), and the answer carries the title, description, alt
 * text and link Make writes back into the pin. A second old pin for a page
 * that already has one is answered "duplicate" and listed for the owner to
 * delete by hand.
 */
function daw_pin_rest_adopt( WP_REST_Request $r ) {
	$p    = $r->get_json_params();
	$pin  = preg_replace( '/[^0-9]/', '', (string) ( $p['pin_id'] ?? '' ) );
	$link = esc_url_raw( (string) ( $p['link'] ?? '' ) );
	if ( '' === $pin ) {
		return new WP_Error( 'daw_pin_bad', 'pin_id is required.', array( 'status' => 400 ) );
	}
	$id = '' !== $link ? url_to_postid( strtok( $link, '?#' ) ) : 0;
	if ( ! $id || 'post' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
		return array( 'action' => 'none', 'why' => 'link is not a published record', 'pin_id' => $pin );
	}

	// The picture, by Pinterest's own file name: the same file on the same
	// page twice is a duplicate; a different picture of the same page (the
	// old gallery pins) is a pin of its own and is kept and corrected.
	$img = (string) ( $p['img'] ?? '' );
	$img = 'old:' . ( '' !== $img ? md5( basename( (string) wp_parse_url( $img, PHP_URL_PATH ) ) ) : $pin );

	$log  = get_post_meta( $id, DAW_PIN_LOG, true );
	$log  = is_array( $log ) ? $log : array();
	$mine = in_array( $pin, array_map( 'strval', wp_list_pluck( $log, 'pin' ) ), true );
	if ( $mine ) {
		// Adopted before pictures were compared (1.75.91): give its entry the
		// picture's name now, so a later copy of the same file is caught.
		foreach ( $log as $i => $e ) {
			if ( (string) $e['pin'] === $pin && 0 === strpos( (string) $e['img'], 'old:' ) && $e['img'] !== $img ) {
				$log[ $i ]['img'] = $img;
				update_post_meta( $id, DAW_PIN_LOG, $log );
			}
		}
	}
	$d    = daw_pin_opt( 'daw_pin_dupes', array() );
	$d    = is_array( $d ) ? $d : array();
	if ( ! $mine ) {
		foreach ( $log as $e ) {
			if ( (string) ( $e['img'] ?? '' ) === $img ) {
				$d[ $pin ] = array( 'post' => $id, 'keep' => (string) $e['pin'] );
				update_option( 'daw_pin_dupes', $d, false );
				return array( 'action' => 'duplicate', 'pin_id' => $pin, 'post' => $id, 'keep' => (string) $e['pin'] );
			}
		}
		$at    = strtotime( (string) ( $p['created_at'] ?? '' ) . ' UTC' );
		$log[] = array(
			'pin'   => $pin,
			'board' => 'old',
			'img'   => $img,
			'at'    => $at ? $at : time(),
		);
		update_post_meta( $id, DAW_PIN_LOG, $log );
	}
	if ( isset( $d[ $pin ] ) ) {
		unset( $d[ $pin ] ); // listed by the first pass, before pictures were compared
		update_option( 'daw_pin_dupes', $d, false );
	}

	$d    = daw_pin_text( $id );
	$type = function_exists( 'daw_get_type' ) ? daw_get_type( $id ) : '';
	$alt  = 'person' === $type
		? $d['name'] . ', ' . $d['kind']
		: 'Poster of ' . $d['name'] . ( '' !== $d['year'] ? ' (' . $d['year'] . ')' : '' ) . ( '' !== $d['kind'] ? ', ' . $d['kind'] : '' );
	$fields = array(
		'title'       => $d['title'],
		'description' => $d['desc'],
		'alt_text'    => daw_pin_cut( daw_pin_plain( $alt ), 500 ),
		'link'        => $d['link'],
	);
	return $fields + array(
		'action' => 'update',
		'pin_id' => $pin,
		'post'   => $id,
		// The same fields as one JSON body, so Make sends it to PATCH
		// /v5/pins/{id} as it is, without escaping quotes and line breaks.
		'patch'  => wp_json_encode( $fields ),
	);
}

/* ============================================================== saves === */

const DAW_PIN_SAVES = '_daw_pin_saves'; // list of {board, pin, at}

/**
 * The next save of an existing pin to another board: [post, board key, pin
 * id], or empty. A record's pin goes to at most two more boards, the first
 * three days after the pin, the second three days after that, most visited
 * records first. Only made boards count.
 */
function daw_pin_save_due() {
	$ids = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array( 'key' => DAW_PIN_LOG, 'compare' => 'EXISTS' ),
				array( 'key' => DAW_PIN_SKIP, 'compare' => 'NOT EXISTS' ),
			),
		)
	);
	if ( ! $ids ) {
		return array();
	}
	update_meta_cache( 'post', $ids );
	usort(
		$ids,
		static function ( $a, $b ) {
			return (int) get_post_meta( $b, 'daw_views_30', true ) - (int) get_post_meta( $a, 'daw_views_30', true );
		}
	);
	foreach ( $ids as $id ) {
		$log = get_post_meta( $id, DAW_PIN_LOG, true );
		$pin = null;
		foreach ( is_array( $log ) ? $log : array() as $e ) {
			if ( 'atlandi' !== ( $e['pin'] ?? '' ) ) {
				$pin = $e;
				break;
			}
		}
		// The account's old pins carry their old titles ("Gong Yoo 52") into
		// every board they are saved to; those pages get the drawn card as a
		// new pin instead, and only pins made here are saved further.
		if ( ! $pin || 'old' === ( $pin['board'] ?? '' ) ) {
			continue;
		}
		$saves = get_post_meta( $id, DAW_PIN_SAVES, true );
		$saves = is_array( $saves ) ? $saves : array();
		if ( count( $saves ) >= 3 ) {
			continue;
		}
		$last = $saves ? (int) end( $saves )['at'] : (int) $pin['at'];
		if ( $last > time() - 3 * DAY_IN_SECONDS ) {
			continue;
		}
		$done = wp_list_pluck( $saves, 'board' );
		foreach ( daw_pin_also( $id ) as $k ) {
			if ( $k !== $pin['board'] && ! in_array( $k, $done, true ) && '' !== daw_pin_board_id( $k ) ) {
				return array( (int) $id, $k, (string) $pin['pin'] );
			}
		}
	}
	return array();
}

function daw_pin_rest_saved( WP_REST_Request $r ) {
	$p     = $r->get_json_params();
	$id    = (int) ( $p['id'] ?? 0 );
	$board = sanitize_key( (string) ( $p['board'] ?? '' ) );
	$pin   = preg_replace( '/[^0-9a-z]/', '', (string) ( $p['pin_id'] ?? '' ) );
	if ( ! $id || '' === $board || 'post' !== get_post_type( $id ) ) {
		return new WP_Error( 'daw_pin_bad', 'id and board are required.', array( 'status' => 400 ) );
	}
	$saves   = get_post_meta( $id, DAW_PIN_SAVES, true );
	$saves   = is_array( $saves ) ? $saves : array();
	$saves[] = array( 'board' => $board, 'pin' => $pin, 'at' => time(), 'ok' => empty( $p['failed'] ) ? 1 : 0 );
	update_post_meta( $id, DAW_PIN_SAVES, $saves );

	$t          = daw_pin_today();
	$t['saves'] = (int) ( $t['saves'] ?? 0 ) + 1;
	update_option( 'daw_pin_day', $t, false );
	return array( 'ok' => true );
}

function daw_pin_log_count( $id ) {
	$log = get_post_meta( $id, DAW_PIN_LOG, true );
	return is_array( $log ) ? count( $log ) : 0;
}

/** Write a pin (or a refusal, pin "atlandi") into the record and the day. */
function daw_pin_record( $id, $pin ) {
	$held = daw_pin_opt( 'daw_pin_held', array() );
	$look = ( is_array( $held ) && (int) ( $held['id'] ?? 0 ) === (int) $id ) ? (string) ( $held['look'] ?? '' ) : '';
	$img  = daw_pin_image( $id, $look );
	$log = get_post_meta( $id, DAW_PIN_LOG, true );
	$log = is_array( $log ) ? $log : array();
	// The same answer twice (Make retrying a slow request) is written once.
	if ( 'atlandi' !== $pin && in_array( $pin, array_map( 'strval', wp_list_pluck( $log, 'pin' ) ), true ) ) {
		return;
	}
	$log[] = array(
		'pin'   => $pin,
		'board' => daw_pin_board_key( $id ),
		'img'   => is_wp_error( $img ) ? '' : $img['hash'],
		'at'    => time(),
	);
	update_post_meta( $id, DAW_PIN_LOG, $log );
	delete_option( 'daw_pin_held' );

	if ( 'atlandi' === $pin ) {
		return; // refused by Pinterest: closed, not counted against the day
	}

	$t           = daw_pin_today();
	$t['pins'][] = array( $id, time() );
	update_option( 'daw_pin_day', $t, false );
	update_option( 'daw_pin_last', $id, false );

	$s = daw_pin_settings();
	if ( '' === $s['start'] ) {
		$s['start'] = gmdate( 'Y-m-d' );
		update_option( 'daw_pin', $s, false );
	}
	// The counts (daw_pin_state) are not rebuilt here: they walk every
	// record, and this answer has to come back before Make gives up waiting.

}

/**
 * How many published records each board would hold, as first board or as a
 * save. A board not made yet is made once this reaches 15.
 */
function daw_pin_board_counts() {
	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	update_meta_cache( 'post', $ids );
	$n = array_fill_keys( array_keys( daw_pin_boards() ), 0 );
	foreach ( $ids as $id ) {
		$k = daw_pin_board_key( $id );
		if ( isset( $n[ $k ] ) ) {
			$n[ $k ]++;
		}
		foreach ( daw_pin_also( $id ) as $a ) {
			if ( isset( $n[ $a ] ) ) {
				$n[ $a ]++;
			}
		}
	}
	return $n;
}

/** Counts, kept in an option the management API can read. */
function daw_pin_state() {
	global $wpdb;
	$pinned = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_PIN_LOG ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$skip   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_PIN_SKIP ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$t      = daw_pin_today();
	return array(
		'waiting' => count( daw_pin_candidates() ),
		'pinned'  => $pinned,
		'skipped' => $skip,
		'today'   => count( $t['pins'] ),
		'cap'     => daw_pin_cap_today(),
		'wait'    => daw_pin_wait_reason(),
		'start'   => daw_pin_settings()['start'],
		'second'  => count( daw_pin_second_candidates() ),
		'boards'  => daw_pin_board_counts(),
		'saves'   => (int) ( $t['saves'] ?? 0 ),
		'plan'    => daw_pin_plan( 10 ),
		'at'      => time(),
	);
}

function daw_pin_state_save() {
	update_option( 'daw_pin_state', daw_pin_state(), false );
}

function daw_pin_rest_status() {
	$s = daw_pin_state();
	update_option( 'daw_pin_state', $s, false );
	return $s;
}

/* ================================================================ screens === */

/** Posts list: a Pinterest column. */
add_filter( 'manage_post_posts_columns', 'daw_pin_column', 20 );
function daw_pin_column( $cols ) {
	$cols['daw_pin'] = 'Pinterest';
	return $cols;
}

add_action( 'manage_post_posts_custom_column', 'daw_pin_column_cell', 10, 2 );
function daw_pin_column_cell( $col, $post_id ) {
	if ( 'daw_pin' !== $col ) {
		return;
	}
	$log = get_post_meta( $post_id, DAW_PIN_LOG, true );
	if ( is_array( $log ) && $log ) {
		$last = end( $log );
		if ( 'atlandi' === $last['pin'] ) {
			echo '<span style="color:#8a1f1f">Reddedildi</span>';
			return;
		}
		printf( '<a href="%s" target="_blank" rel="noopener">Pinlendi</a><br><span style="font-size:11px;color:#8c8f94">%s</span>', esc_url( 'https://www.pinterest.com/pin/' . rawurlencode( $last['pin'] ) . '/' ), esc_html( wp_date( 'd.m.Y', (int) $last['at'] ) ) );
		return;
	}
	if ( get_post_meta( $post_id, DAW_PIN_SKIP, true ) ) {
		echo '<span style="color:#8c8f94">Gönderilmeyecek</span>';
		return;
	}
	echo '<span style="color:#8c8f94">Sırada</span>';
}

/** Edit screen: the log, and the box that keeps a record off Pinterest. */
add_action( 'add_meta_boxes_post', 'daw_pin_box' );
function daw_pin_box() {
	add_meta_box( 'daw_pin', 'Pinterest', 'daw_pin_box_html', 'post', 'side', 'low' );
}

function daw_pin_box_html( $post ) {
	wp_nonce_field( 'daw_pin_box', 'daw_pin_box_nonce' );
	$log = get_post_meta( $post->ID, DAW_PIN_LOG, true );
	if ( is_array( $log ) && $log ) {
		echo '<ul style="margin:0 0 8px">';
		foreach ( $log as $e ) {
			if ( 'atlandi' === $e['pin'] ) {
				printf( '<li>%s · Pinterest reddetti</li>', esc_html( wp_date( 'd.m.Y', (int) $e['at'] ) ) );
			} else {
				printf( '<li><a href="%s" target="_blank" rel="noopener">%s</a> · %s</li>', esc_url( 'https://www.pinterest.com/pin/' . rawurlencode( $e['pin'] ) . '/' ), esc_html( wp_date( 'd.m.Y', (int) $e['at'] ) ), esc_html( 'old' === $e['board'] ? 'eski pin' : ( daw_pin_boards()[ $e['board'] ]['name'] ?? $e['board'] ) ) );
			}
		}
		echo '</ul><p style="color:#646970;margin:0 0 8px">Bu kayıt bir daha sıraya girmez.</p>';
	} else {
		echo '<p style="margin:0 0 8px">Henüz pinlenmedi.</p>';
	}
	printf(
		'<label><input type="checkbox" name="daw_pin_skip" value="1"%s> Pinterest\'e gönderme</label>',
		checked( (bool) get_post_meta( $post->ID, DAW_PIN_SKIP, true ), true, false )
	);
}

add_action( 'save_post_post', 'daw_pin_box_save', 10, 1 );
function daw_pin_box_save( $post_id ) {
	if ( ! isset( $_POST['daw_pin_box_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['daw_pin_box_nonce'] ), 'daw_pin_box' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! empty( $_POST['daw_pin_skip'] ) ) {
		update_post_meta( $post_id, DAW_PIN_SKIP, 1 );
	} else {
		delete_post_meta( $post_id, DAW_PIN_SKIP );
	}
}

/** The Pinterest screen: state, the key, the next few pins as they will look. */
add_action( 'admin_menu', 'daw_pin_menu' );
function daw_pin_menu() {
	add_submenu_page( 'daw', 'Pinterest', 'Pinterest', 'manage_options', 'daw-pinterest', 'daw_pin_page' );
}

add_action( 'admin_post_daw_pin_settings', 'daw_pin_settings_save' );
function daw_pin_settings_save() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'daw_pin_settings' ) ) {
		wp_die( 'No.' );
	}
	$s          = daw_pin_settings();
	$s['on']    = empty( $_POST['on'] ) ? 0 : 1;
	$s['scope'] = isset( $_POST['scope'] ) && 'all' === $_POST['scope'] ? 'all' : 'locked';
	$s['from']  = max( 0, min( 23, (int) ( $_POST['from'] ?? 8 ) ) );
	$s['to']    = max( $s['from'] + 1, min( 24, (int) ( $_POST['to'] ?? 22 ) ) );
	$ramp       = array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ramp'] ?? '6,10,15' ) ) ) );
	$s['look']  = isset( $_POST['look'] ) && 'card' === $_POST['look'] ? 'card' : 'direct';
	$s['ramp']  = implode( ',', array_slice( array_pad( array_filter( $ramp ), 3, 15 ), 0, 3 ) );
	$s['rv']    = 3;
	update_option( 'daw_pin', $s, false );
	daw_pin_state_save();
	wp_safe_redirect( admin_url( 'admin.php?page=daw-pinterest&saved=1' ) );
	exit;
}

add_action( 'admin_post_daw_pin_preview', 'daw_pin_preview' );
function daw_pin_preview() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'daw_pin_preview' ) ) {
		wp_die( 'No.' );
	}
	$im = daw_pin_render( isset( $_GET['post'] ) ? (int) $_GET['post'] : 0 );
	if ( is_wp_error( $im ) ) {
		wp_die( esc_html( $im->get_error_message() ) );
	}
	nocache_headers();
	header( 'Content-Type: image/jpeg' );
	imagejpeg( $im, null, 85 );
	imagedestroy( $im );
	exit;
}

function daw_pin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s  = daw_pin_settings();
	$st = daw_pin_state();
	update_option( 'daw_pin_state', $st, false );
	$why = array(
		''                  => 'Şimdi pin gidebilir',
		'off'               => 'Kapalı',
		'outside hours'     => 'Saat aralığı dışında (New York)',
		'daily cap reached' => 'Bugünkü sınır doldu',
		'too soon'          => 'Bir önceki pinden sonra ara bekleniyor',
	);

	echo '<div class="wrap"><h1>Pinterest</h1>';
	printf(
		'<p><strong>%d</strong> kayıt sırada · <strong>%d</strong> pinlendi · <strong>%d</strong> gönderilmeyecek · bugün <strong>%d / %d</strong> · %s</p>',
		(int) $st['waiting'],
		(int) $st['pinned'],
		(int) $st['skipped'],
		(int) $st['today'],
		(int) $st['cap'],
		esc_html( $why[ $st['wait'] ] ?? $st['wait'] )
	);

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'daw_pin_settings' );
	echo '<input type="hidden" name="action" value="daw_pin_settings"><table class="form-table">';
	printf( '<tr><th>Açık</th><td><label><input type="checkbox" name="on" value="1"%s> Make sorduğunda pin ver</label></td></tr>', checked( (bool) $s['on'], true, false ) );
	printf(
		'<tr><th>Kapsam</th><td><select name="scope"><option value="locked"%s>Sadece kilitli (bitti) kayıtlar</option><option value="all"%s>Yayındaki her kayıt</option></select></td></tr>',
		selected( $s['scope'], 'locked', false ),
		selected( $s['scope'], 'all', false )
	);
	printf( '<tr><th>Saat (New York)</th><td><input type="number" name="from" value="%d" min="0" max="23" style="width:70px"> ile <input type="number" name="to" value="%d" min="1" max="24" style="width:70px"> arası</td></tr>', (int) $s['from'], (int) $s['to'] );
	printf(
		'<tr><th>Görsel</th><td><select name="look"><option value="direct"%s>TMDB posteri / fotoğrafı, olduğu gibi</option><option value="card"%s>Site kartı (TMDB görseli üstte, başlık altta)</option></select> <span class="description">TMDB görseli olmayan kayıt her iki durumda da çizilen kartla gider.</span></td></tr>',
		selected( $s['look'], 'direct', false ),
		selected( $s['look'], 'card', false )
	);
	printf( '<tr><th>Günlük adet</th><td><input type="text" name="ramp" value="%s" style="width:90px"> <span class="description">ilk iki hafta, sonraki iki hafta, sonrası%s</span></td></tr>', esc_attr( $s['ramp'] ), '' !== $s['start'] ? esc_html( ' · ilk pin ' . $s['start'] ) : '' );
	echo '</table>';
	submit_button( 'Kaydet' );
	echo '</form>';

	echo '<h2>Board\'lar</h2><table class="widefat striped" style="max-width:700px"><thead><tr><th>Board</th><th>Kayıt</th><th>Durum</th></tr></thead><tbody>';
	$counts = isset( $st['boards'] ) ? $st['boards'] : array();
	foreach ( daw_pin_boards() as $k => $b ) {
		$n = (int) ( $counts[ $k ] ?? 0 );
		if ( '' !== $b['id'] ) {
			$state = '<code>' . esc_html( $b['id'] ) . '</code>';
		} else {
			$state = $n >= 15 ? 'Açılabilir (15+ kayıt)' : 'Açılmadı: 15 kayıt bekleniyor';
		}
		printf( '<tr><td>%s%s</td><td>%d</td><td>%s</td></tr>', esc_html( $b['name'] ), ! empty( $b['also'] ) ? ' <span class="description">(ek kayıt)</span>' : '', $n, $state ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</tbody></table>';

	echo '<h2>Sıradaki pinler</h2><div style="display:flex;flex-wrap:wrap;gap:16px">';
	$n = 0;
	foreach ( daw_pin_candidates() as $id ) {
		if ( $n >= 6 ) {
			break;
		}
		if ( ! daw_pin_ready( $id ) ) {
			continue;
		}
		$d   = daw_pin_text( $id );
		$ph  = daw_pin_photo( $id );
		$src = ( $ph && 'tmdb' === $ph['kind'] && 'card' !== $s['look'] )
			? 'https://image.tmdb.org/t/p/w342' . substr( $ph['id'], 5 )
			: wp_nonce_url( admin_url( 'admin-post.php?action=daw_pin_preview&post=' . $id ), 'daw_pin_preview' );
		printf(
			'<div style="width:220px"><img src="%s" width="200" height="300" style="display:block;aspect-ratio:2/3;background:#eee" loading="lazy" alt=""><p style="margin:6px 0 0;font-weight:600">%s</p><p style="margin:2px 0;color:#646970;font-size:12px">%s</p></div>',
			esc_url( $src ),
			esc_html( $d['title'] ),
			esc_html( daw_pin_boards()[ daw_pin_board_key( $id ) ]['name'] ?? '' )
		);
		$n++;
	}
	echo '</div>';

	// The plan: what goes on which day at the current pace.
	$plan = daw_pin_plan( 60 );
	if ( $plan ) {
		$total = (int) $st['waiting'];
		echo '<h2>Takvim</h2>';
		printf( '<p class="description">Şu anki hızla sıradaki %d kaydın tahmini günü (New York). Sırada toplam %d kayıt var.</p>', count( $plan ), $total );
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Gün</th><th>Kayıt</th><th>Board</th></tr></thead><tbody>';
		foreach ( $plan as $row ) {
			printf(
				'<tr><td>%s</td><td><a href="%s">%s</a></td><td>%s</td></tr>',
				esc_html( $row[1] ),
				esc_url( get_edit_post_link( $row[0] ) ),
				esc_html( daw_pin_plain( get_the_title( $row[0] ) ) ),
				esc_html( daw_pin_boards()[ daw_pin_board_key( $row[0] ) ]['name'] ?? '' )
			);
		}
		echo '</tbody></table>';
	}

	$dupes = get_option( 'daw_pin_dupes', array() );
	if ( is_array( $dupes ) && $dupes ) {
		echo '<h2>Silinecek eski çift pinler</h2><p class="description">Aynı sayfaya ikinci kez basılmış eski pinler. Pinterest\'te tek tek silin; aynı sayfanın diğer pini kalır.</p><ul>';
		foreach ( $dupes as $pin => $e ) {
			printf( '<li><a href="%s" target="_blank" rel="noopener">%s</a> · %s</li>', esc_url( 'https://www.pinterest.com/pin/' . rawurlencode( (string) $pin ) . '/' ), esc_html( (string) $pin ), esc_html( daw_pin_plain( get_the_title( (int) $e['post'] ) ) ) );
		}
		echo '</ul>';
	}

	printf( '<h2>Make</h2><p>Anahtar (<code>X-DAW-PIN-KEY</code>): <code>%s</code></p>', esc_html( daw_pin_key() ) );
	echo '</div>';
}
