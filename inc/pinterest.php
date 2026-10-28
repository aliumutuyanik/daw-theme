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
 * WHY THE PICTURE IS DRAWN
 * ------------------------
 * Posters and stills belong to the studios. The image rights register already
 * says the site does not host what it cannot defend, and a Pinterest account
 * that collects copyright complaints is closed. So the pin is a card drawn by
 * the site, as the news cards are: the title, the kind, the year and network,
 * the name in its own script, the logo. A record whose featured image has an
 * allowed licence record gets that photo on top of the card instead.
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
const DAW_PIN_REV  = '1'; // bump when the card layout changes

/* ================================================================ boards === */

/**
 * The boards, by key. Ids are the boards made on 28 October 2026 on
 * pinterest.com/discoverasianworld; "Korean Actors" is the account's old board,
 * kept with its pins and given a description.
 */
function daw_pin_boards() {
	$boards = array(
		'kdrama'   => array( 'name' => 'K-Drama Recommendations', 'id' => '988118043183707540' ),
		'kmovie'   => array( 'name' => 'Korean Movies to Watch', 'id' => '988118043183707541' ),
		'actor'    => array( 'name' => 'Korean Actors', 'id' => '988118043182946887' ),
		'actress'  => array( 'name' => 'Korean Actresses', 'id' => '988118043183707542' ),
		'asian'    => array( 'name' => 'C-Drama, J-Drama and Thai Drama', 'id' => '988118043183707543' ),
		'kfood'    => array( 'name' => 'Korean Food and Recipes', 'id' => '988118043183707544' ),
		'kculture' => array( 'name' => 'Korean Culture and Travel', 'id' => '988118043183707545' ),
		'news'     => array( 'name' => 'K-Drama News', 'id' => '988118043183707546' ),
	);
	return apply_filters( 'daw_pin_boards', $boards );
}

/** Is the record about South Korea (or about nowhere in particular)? */
function daw_pin_country( $post_id ) {
	$terms = get_the_terms( $post_id, 'dw_country' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	return $terms[0]->slug;
}

/** The board key for a record, or '' if it is not pinned at all. */
function daw_pin_board_key( $post_id ) {
	$type   = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
	$c      = daw_pin_country( $post_id );
	$korean = '' === $c || 'south-korea' === $c;

	switch ( $type ) {
		case 'drama':
			return $korean ? 'kdrama' : 'asian';
		case 'film':
			return $korean ? 'kmovie' : 'asian';
		case 'person':
			if ( ! $korean ) {
				return 'asian';
			}
			return 'female' === strtolower( (string) get_post_meta( $post_id, 'daw_gender', true ) ) ? 'actress' : 'actor';
		case 'news':
			return 'news';
		case 'food':
			return 'kfood';
		case '':
			return '';
		default:
			// variety, group, culture, myth, travel, quiz, lists.
			return 'kculture';
	}
}

function daw_pin_board_id( $key ) {
	$b = daw_pin_boards();
	return isset( $b[ $key ] ) ? (string) $b[ $key ]['id'] : '';
}

/* ============================================================== settings === */

function daw_pin_settings() {
	$s = get_option( 'daw_pin', array() );
	return wp_parse_args(
		is_array( $s ) ? $s : array(),
		array(
			'on'    => 1,
			'scope' => 'locked', // locked | all
			'from'  => 8,        // New York hours
			'to'    => 22,
			'ramp'  => '3,5,8',  // a day: weeks 1-2, weeks 3-4, after
			'start' => '',       // Y-m-d of the first pin, set by the first pin
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
	return array_values( array_unique( $keys ) );
}

/** Pins allowed today, by how long the account has been pinning. */
function daw_pin_cap_today() {
	$s    = daw_pin_settings();
	$ramp = array_map( 'intval', explode( ',', (string) $s['ramp'] ) ) + array( 3, 5, 8 );
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
	$t   = get_option( 'daw_pin_day', array() );
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
	$last_post = $t['pins'] ? (int) end( $t['pins'] )[0] : (int) get_option( 'daw_pin_last', 0 );
	$last_key  = $last_post ? daw_pin_board_key( $last_post ) : '';

	$fallback = 0;
	foreach ( daw_pin_candidates() as $id ) {
		if ( ! daw_pin_ready( $id ) ) {
			continue;
		}
		if ( daw_pin_board_key( $id ) !== $last_key ) {
			return $id;
		}
		if ( ! $fallback ) {
			$fallback = $id;
		}
	}
	return $fallback;
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
	$ramp  = array_map( 'intval', explode( ',', (string) $s['ramp'] ) ) + array( 3, 5, 8 );
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
			return 'Korean Food';
		case 'travel':
			return 'Korea Travel';
		case 'myth':
			return 'Korean Myth';
		case 'culture':
			return 'Korean Culture';
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
		$base  = $name . ( '' !== $year ? ' (' . $year . ')' : '' ) . ' ' . $kind;
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
	if ( 'person' === $type && 'actress' === daw_pin_board_key( $post_id ) ) {
		$hash[1] = 'koreanactress';
	}
	if ( 'asian' === daw_pin_board_key( $post_id ) ) {
		$hash = array( 'asiandrama', 'cdrama', 'jdrama' );
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

/** The record's featured image, if its licence lets it go on a pin. */
function daw_pin_photo( $post_id ) {
	$thumb = (int) get_post_thumbnail_id( $post_id );
	if ( ! $thumb || ! function_exists( 'daw_lic_status' ) || 'ok' !== daw_lic_status( $thumb, $post_id ) ) {
		return 0;
	}
	// A news card is our own drawing of the headline; the pin draws its own.
	if ( $thumb === (int) get_post_meta( $post_id, '_daw_card_att', true ) ) {
		return 0;
	}
	$path = get_attached_file( $thumb );
	return ( $path && file_exists( $path ) ) ? $thumb : 0;
}

/** Copy $src into a box, cropped to fill it, centred. */
function daw_pin_cover( $im, $src, $x, $y, $w, $h ) {
	$sw = imagesx( $src );
	$sh = imagesy( $src );
	$r  = max( $w / $sw, $h / $sh );
	$cw = (int) round( $w / $r );
	$ch = (int) round( $h / $r );
	imagecopyresampled( $im, $src, $x, $y, (int) ( ( $sw - $cw ) / 2 ), (int) ( ( $sh - $ch ) / 2 ), $w, $h, $cw, $ch );
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

	$W   = DAW_PIN_W;
	$H   = DAW_PIN_H;
	$top = 860; // the picture's share of the pin
	$im  = imagecreatetruecolor( $W, $H );
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

	$photo = daw_pin_photo( $post_id );
	$src   = $photo ? imagecreatefromstring( (string) file_get_contents( get_attached_file( $photo ) ) ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions

	if ( $src ) {
		daw_pin_cover( $im, $src, 0, 0, $W, $top );
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
	$ky = $top + 92;
	if ( '' !== $d['kicker'] ) {
		$px = 26;
		while ( daw_card_tw( $px, $f_bold, $d['kicker'] ) + 2 * mb_strlen( $d['kicker'] ) > $W - 2 * $x && $px > 18 ) {
			$px -= 2;
		}
		daw_card_tracked( $im, $px, $x, $ky, $dgold, $f_bold, $d['kicker'], 2 );
	}

	// Headline: as large as it can be in four lines.
	$px    = 78;
	$lines = daw_card_wrap( $px, $f_head, $d['name'], $W - 2 * $x );
	while ( count( $lines ) > 3 && $px > 44 ) {
		$px   -= 4;
		$lines = daw_card_wrap( $px, $f_head, $d['name'], $W - 2 * $x );
	}
	$lh = (int) round( $px * 1.16 );
	$y  = $ky + 30 + $px;
	foreach ( array_slice( $lines, 0, 4 ) as $ln ) {
		imagettftext( $im, $px * 0.75, 0, $x, $y, $ink, $f_head, $ln );
		$y += $lh;
	}

	if ( '' !== $d['sub'] ) {
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
function daw_pin_image( $post_id ) {
	$d    = daw_pin_text( $post_id );
	$hash = md5( wp_json_encode( array( $d['name'], $d['kicker'], $d['sub'], $d['native'], daw_pin_photo( $post_id ), DAW_PIN_REV ) ) );
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
		'photo' => daw_pin_photo( $post_id ),
	);
}

/** The whole pin, as Make sends it to Pinterest. */
function daw_pin_item( $post_id ) {
	$img = daw_pin_image( $post_id );
	if ( is_wp_error( $img ) ) {
		return $img;
	}
	$d   = daw_pin_text( $post_id );
	$key = daw_pin_board_key( $post_id );

	if ( $img['photo'] ) {
		$alt = trim( (string) get_post_meta( $img['photo'], '_wp_attachment_image_alt', true ) );
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
}

function daw_pin_rest_next( WP_REST_Request $r ) {
	// A record handed out and not written back yet is handed out again, not a
	// second record: Make may ask twice before Pinterest has answered.
	$held = get_option( 'daw_pin_held', array() );
	if ( is_array( $held ) && ! empty( $held['id'] ) && time() - (int) $held['at'] < 30 * MINUTE_IN_SECONDS && ! get_post_meta( (int) $held['id'], DAW_PIN_LOG, true ) ) {
		$item = daw_pin_item( (int) $held['id'] );
		return is_wp_error( $item ) ? array( 'item' => null, 'why' => $item->get_error_message() ) : array( 'item' => $item );
	}

	$why = daw_pin_wait_reason();
	if ( '' !== $why && ! $r->get_param( 'force' ) ) {
		return array( 'item' => null, 'why' => $why );
	}
	$id = daw_pin_pick();
	if ( ! $id ) {
		return array( 'item' => null, 'why' => 'nothing ready' );
	}
	$item = daw_pin_item( $id );
	if ( is_wp_error( $item ) ) {
		return array( 'item' => null, 'why' => $item->get_error_message() );
	}
	update_option( 'daw_pin_held', array( 'id' => $id, 'at' => time() ), false );
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

/** Write a pin (or a refusal, pin "atlandi") into the record and the day. */
function daw_pin_record( $id, $pin ) {
	$img = daw_pin_image( $id );
	$log = get_post_meta( $id, DAW_PIN_LOG, true );
	$log = is_array( $log ) ? $log : array();
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
	daw_pin_state_save();
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
				printf( '<li><a href="%s" target="_blank" rel="noopener">%s</a> · %s</li>', esc_url( 'https://www.pinterest.com/pin/' . rawurlencode( $e['pin'] ) . '/' ), esc_html( wp_date( 'd.m.Y', (int) $e['at'] ) ), esc_html( daw_pin_boards()[ $e['board'] ]['name'] ?? $e['board'] ) );
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
	$ramp       = array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ramp'] ?? '3,5,8' ) ) ) );
	$s['ramp']  = implode( ',', array_slice( array_pad( array_filter( $ramp ), 3, 8 ), 0, 3 ) );
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
	printf( '<tr><th>Günlük adet</th><td><input type="text" name="ramp" value="%s" style="width:90px"> <span class="description">ilk iki hafta, sonraki iki hafta, sonrası%s</span></td></tr>', esc_attr( $s['ramp'] ), '' !== $s['start'] ? esc_html( ' · ilk pin ' . $s['start'] ) : '' );
	echo '</table>';
	submit_button( 'Kaydet' );
	echo '</form>';

	echo '<h2>Board\'lar</h2><ul>';
	foreach ( daw_pin_boards() as $b ) {
		printf( '<li>%s <code>%s</code></li>', esc_html( $b['name'] ), esc_html( $b['id'] ) );
	}
	echo '</ul>';

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
		$src = wp_nonce_url( admin_url( 'admin-post.php?action=daw_pin_preview&post=' . $id ), 'daw_pin_preview' );
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

	printf( '<h2>Make</h2><p>Anahtar (<code>X-DAW-PIN-KEY</code>): <code>%s</code></p>', esc_html( daw_pin_key() ) );
	echo '</div>';
}
