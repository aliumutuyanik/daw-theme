<?php
/**
 * Discover Asian World — Yayılım: where each record has been shared.
 *
 * WHAT THIS DOES
 * --------------
 * One admin screen, "Yayılım", with a tab per network: Pinterest (the queue
 * in pinterest.php, shown here as it stands) and Tumblr (this file). Every
 * published record is posted to the asianworldnews Tumblr blog once. The
 * site decides what goes next and when; Make only asks and carries the
 * answer to Tumblr, the same way the Pinterest drip works:
 *
 *   GET  /wp-json/daw/v1/tumblr/next    the next record, or nothing yet
 *   POST /wp-json/daw/v1/tumblr/done    {id, tumblr_id}: write it down
 *   GET  /wp-json/daw/v1/tumblr/status  the counts
 *
 * All three answer only to the key in the X-DAW-TB-KEY header.
 *
 * NEVER TWICE
 * -----------
 * A record with a Tumblr log (`_daw_tumblr`) is never handed out again, and
 * a record ticked "Tumblr'a gönderme" is never handed out at all. A record
 * handed out and not written back within 30 minutes is taken as posted with
 * an unknown id, so a lost answer never becomes a second post. An answer
 * "atlandi" (Tumblr refused it) closes the record without counting it
 * against the day.
 *
 * PACE
 * ----
 * A new Tumblr blog that posts links in heaps reads as spam. So: 6 a day
 * for the first two weeks, 8 for the next two, 10 after that, spread over
 * 08:00-23:00 New York time, with a gap between posts. Records follow the
 * Pinterest order: news from the last three days and shows on air first,
 * then the most visited.
 *
 * WHAT IS SENT
 * ------------
 * The picture and the words are the Pinterest pin's (daw_pin_image,
 * daw_pin_text): the record's confirmed TMDB poster or photo, or the card
 * the site draws. Nothing here changes a page a reader sees; the reader is
 * never sent to Tumblr from the site (rule 3.1).
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_TB_LOG  = '_daw_tumblr';      // list of {post, blog, at}
const DAW_TB_SKIP = '_daw_tumblr_skip'; // "do not send to Tumblr"
const DAW_TB_AGAIN = '_daw_tumblr_again'; // "share on Tumblr again": time asked
const DAW_TB_BLOG = 'asianworldnews';

/* ============================================================= settings === */

function daw_tb_settings() {
	$s = function_exists( 'daw_pin_opt' ) ? daw_pin_opt( 'daw_tb', array() ) : get_option( 'daw_tb', array() );
	return wp_parse_args(
		is_array( $s ) ? $s : array(),
		array(
			'on'    => 1,
			'scope' => 'all',    // locked | all
			'from'  => 8,        // New York hours
			'to'    => 23,
			'ramp'  => '6,8,10', // a day: weeks 1-2, weeks 3-4, after
			'start' => '',       // Y-m-d of the first post, set by the first post
		)
	);
}

/** The shared key Make sends. Made once, readable over the management API. */
function daw_tb_key() {
	$k = (string) get_option( 'daw_tb_key', '' );
	if ( '' === $k ) {
		$k = 'dawtb_' . wp_generate_password( 24, false, false );
		update_option( 'daw_tb_key', $k, false );
	}
	return $k;
}

add_filter( 'royal_mcp_readable_options', 'daw_tb_readable' );
function daw_tb_readable( $keys ) {
	$keys   = (array) $keys;
	$keys[] = 'daw_tb_key';
	$keys[] = 'daw_tb_state';
	return array_values( array_unique( $keys ) );
}

/** Posts allowed today, by how long the blog has been posting. */
function daw_tb_cap_today() {
	$s    = daw_tb_settings();
	$ramp = array_map( 'intval', explode( ',', (string) $s['ramp'] ) ) + array( 6, 8, 10 );
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

/** Posts made today (New York day), newest last: list of [post_id, at]. */
function daw_tb_today() {
	$day = ( new DateTime( 'now', new DateTimeZone( 'America/New_York' ) ) )->format( 'Y-m-d' );
	$t   = function_exists( 'daw_pin_opt' ) ? daw_pin_opt( 'daw_tb_day', array() ) : get_option( 'daw_tb_day', array() );
	if ( ! is_array( $t ) || ( $t['day'] ?? '' ) !== $day ) {
		$t = array( 'day' => $day, 'posts' => array() );
	}
	return $t;
}

/**
 * Records that may be posted, in the Pinterest order.
 *
 * @return int[]
 */
function daw_tb_candidates() {
	// A record the owner asked to share again goes before everything else,
	// oldest request first, even though it has a log (the Pinterest rule).
	$again = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => DAW_TB_AGAIN, // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',
			'meta_query'     => array( array( 'key' => DAW_TB_SKIP, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	$again = array_map( 'intval', $again );
	return array_values( array_unique( array_merge( $again, daw_tb_first_time() ) ) );
}

/**
 * Records never shared on Tumblr, in the Pinterest order.
 *
 * @return int[]
 */
function daw_tb_first_time() {
	$s    = daw_tb_settings();
	$meta = array(
		array( 'key' => DAW_TB_LOG, 'compare' => 'NOT EXISTS' ),
		array( 'key' => DAW_TB_SKIP, 'compare' => 'NOT EXISTS' ),
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
	// Sorting reads every record's dates and visits; the order is kept for
	// ten minutes and only records posted since are taken out.
	$key    = 'daw_tb_order_' . md5( implode( ',', array( $s['scope'], count( $ids ), max( $ids ) ) ) );
	$sorted = get_transient( $key );
	if ( is_array( $sorted ) ) {
		return array_values( array_intersect( $sorted, array_map( 'intval', $ids ) ) );
	}
	update_meta_cache( 'post', $ids );
	if ( 'all' === $s['scope'] && function_exists( 'daw_pin_fit' ) ) {
		$ids = array_filter( $ids, 'daw_pin_fit' );
	}
	if ( function_exists( 'daw_pin_order' ) ) {
		usort( $ids, 'daw_pin_order' );
	}
	$ids = array_map( 'intval', $ids );
	set_transient( $key, $ids, 10 * MINUTE_IN_SECONDS );
	return $ids;
}

/** Why nothing goes now, or '' when something may. */
function daw_tb_wait_reason() {
	$s = daw_tb_settings();
	if ( ! $s['on'] ) {
		return 'off';
	}
	$hour = (int) ( new DateTime( 'now', new DateTimeZone( 'America/New_York' ) ) )->format( 'G' );
	if ( $hour < (int) $s['from'] || $hour >= (int) $s['to'] ) {
		return 'outside hours';
	}
	$t   = daw_tb_today();
	$cap = daw_tb_cap_today();
	if ( count( $t['posts'] ) >= $cap ) {
		return 'daily cap reached';
	}
	// Spread the day's posts over the window rather than spending them at once.
	$gap  = (int) floor( 0.8 * ( (int) $s['to'] - (int) $s['from'] ) * HOUR_IN_SECONDS / $cap );
	$last = $t['posts'] ? (int) end( $t['posts'] )[1] : 0;
	if ( $last && time() - $last < $gap ) {
		return 'too soon';
	}
	return '';
}

function daw_tb_log_count( $id ) {
	// Read past the object cache: a log written by the last /tumblr/done can
	// still read as missing there.
	wp_cache_delete( (int) $id, 'post_meta' );
	$log = get_post_meta( $id, DAW_TB_LOG, true );
	return is_array( $log ) ? count( $log ) : 0;
}

/**
 * Tumblr tags: what the record is, its name, its genres and the searched
 * tags the pin carries, without the "#".
 *
 * @return string[]
 */
function daw_tb_tags( $post_id, $d ) {
	$tags = array( $d['kind'], $d['name'] );
	if ( function_exists( 'daw_pin_terms' ) ) {
		$tags = array_merge( $tags, daw_pin_terms( $post_id, 'dw_genre', 3 ) );
	}
	$parts = explode( "\n\n", (string) $d['desc'] );
	$last  = trim( (string) end( $parts ) );
	if ( 0 === strpos( $last, '#' ) ) {
		$tags = array_merge( $tags, explode( ' ', str_replace( '#', '', $last ) ) );
	}
	$out = array();
	foreach ( $tags as $t ) {
		$t = trim( str_replace( ',', ' ', (string) $t ) );
		if ( '' !== $t && ! in_array( strtolower( $t ), array_map( 'strtolower', $out ), true ) ) {
			$out[] = $t;
		}
	}
	return array_slice( $out, 0, 10 );
}

/** The whole post, as Make sends it to Tumblr. */
function daw_tb_item( $post_id ) {
	if ( ! function_exists( 'daw_pin_text' ) || ! function_exists( 'daw_pin_image' ) ) {
		return new WP_Error( 'daw_tb_nopin', 'The Pinterest module is not loaded.' );
	}
	$img = daw_pin_image( $post_id, 'direct' );
	if ( is_wp_error( $img ) ) {
		return $img;
	}
	$d    = daw_pin_text( $post_id );
	$link = add_query_arg( array( 'utm_source' => 'tumblr', 'utm_medium' => 'social', 'utm_campaign' => 'yayilim' ), get_permalink( $post_id ) );
	$lead = daw_pin_plain( get_post_meta( $post_id, 'rank_math_description', true ) );
	if ( '' === $lead ) {
		$lead = daw_pin_plain( get_post_field( 'post_excerpt', $post_id ) );
	}

	$caption = sprintf( '<h2><a href="%s">%s</a></h2>', esc_url( $link ), esc_html( $d['title'] ) );
	if ( '' !== $lead ) {
		$caption .= sprintf( '<p>%s</p>', esc_html( daw_pin_cut( $lead, 400 ) ) );
	}
	$caption .= sprintf( '<p><a href="%s">%s</a></p>', esc_url( $link ), esc_html( 'Read the full guide on Discover Asian World →' ) );

	return array(
		'id'        => (int) $post_id,
		'blog'      => DAW_TB_BLOG,
		'title'     => $d['title'],
		'link'      => $link,
		'caption'   => $caption,
		'tags'      => daw_tb_tags( $post_id, $d ),
		'image_url' => $img['url'],
	);
}

/** Write a post (or a refusal, "atlandi") into the record and the day. */
function daw_tb_record( $id, $tid ) {
	wp_cache_delete( (int) $id, 'post_meta' );
	$log = get_post_meta( $id, DAW_TB_LOG, true );
	$log = is_array( $log ) ? $log : array();
	// The same answer twice (Make retrying a slow request) is written once.
	if ( 'atlandi' !== $tid && in_array( $tid, array_map( 'strval', wp_list_pluck( $log, 'post' ) ), true ) ) {
		return;
	}
	// An answer that arrives after the record was taken as posted fills in
	// the id; the day already counted it.
	$last = $log ? end( $log ) : array();
	if ( 'unknown' === ( $last['post'] ?? '' ) && 'atlandi' !== $tid ) {
		$log[ count( $log ) - 1 ]['post'] = $tid;
		update_post_meta( $id, DAW_TB_LOG, $log );
		return;
	}
	$log[] = array( 'post' => $tid, 'blog' => DAW_TB_BLOG, 'at' => time() );
	update_post_meta( $id, DAW_TB_LOG, $log );
	delete_option( 'daw_tb_held' );
	if ( 'atlandi' !== $tid ) {
		delete_post_meta( $id, DAW_TB_AGAIN );
	}

	if ( 'atlandi' === $tid ) {
		return; // refused by Tumblr: closed, not counted against the day
	}

	$t            = daw_tb_today();
	$t['posts'][] = array( (int) $id, time() );
	update_option( 'daw_tb_day', $t, false );

	$s = daw_tb_settings();
	if ( '' === $s['start'] ) {
		$s['start'] = gmdate( 'Y-m-d' );
		update_option( 'daw_tb', $s, false );
	}
}

/* ================================================================== REST === */

/**
 * The drips' answers are never cached. LiteSpeed served /tumblr/next and
 * /pin/status from its cache (x-litespeed-cache: hit, 29 Oct 2026): Make
 * saw yesterday's "too soon", and a caller with the wrong key got the cached
 * answer instead of a 401. Marked before WordPress answers, so nothing is
 * stored in the first place.
 */
add_action( 'init', 'daw_tb_nocache', 0 );
function daw_tb_nocache() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$uri = (string) wp_parse_url( $uri, PHP_URL_PATH );
	foreach ( array( '/wp-json/daw/v1/tumblr/', '/wp-json/daw/v1/pin/' ) as $p ) {
		if ( 0 === strpos( $uri, $p ) || false !== strpos( (string) ( $_GET['rest_route'] ?? '' ), substr( $p, 8 ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			do_action( 'litespeed_control_set_nocache', 'daw: drip endpoint' );
			if ( ! headers_sent() ) {
				header( 'Cache-Control: no-store, private' );
				header( 'X-LiteSpeed-Cache-Control: no-cache' );
			}
			return;
		}
	}
}

add_action( 'rest_api_init', 'daw_tb_routes' );
function daw_tb_routes() {
	$auth = static function ( WP_REST_Request $r ) {
		return hash_equals( daw_tb_key(), (string) $r->get_header( 'x-daw-tb-key' ) );
	};
	register_rest_route( 'daw/v1', '/tumblr/next', array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => 'daw_tb_rest_next' ) );
	register_rest_route( 'daw/v1', '/tumblr/done', array( 'methods' => 'POST', 'permission_callback' => $auth, 'callback' => 'daw_tb_rest_done' ) );
	register_rest_route( 'daw/v1', '/tumblr/status', array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => 'daw_tb_rest_status' ) );
}

function daw_tb_rest_next( WP_REST_Request $r ) {
	// A record handed out and not written back is held for 30 minutes, then
	// taken as posted with an unknown id, so it is never posted twice.
	$held = function_exists( 'daw_pin_opt' ) ? daw_pin_opt( 'daw_tb_held', array() ) : get_option( 'daw_tb_held', array() );
	if ( is_array( $held ) && ! empty( $held['id'] ) && daw_tb_log_count( (int) $held['id'] ) === (int) ( $held['n'] ?? 0 ) ) {
		if ( time() - (int) $held['at'] < 30 * MINUTE_IN_SECONDS ) {
			return array( 'item' => null, 'why' => 'waiting for the last answer' );
		}
		$hid   = (int) $held['id'];
		$log   = get_post_meta( $hid, DAW_TB_LOG, true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array( 'post' => 'unknown', 'blog' => DAW_TB_BLOG, 'at' => (int) $held['at'] );
		update_post_meta( $hid, DAW_TB_LOG, $log );
		delete_option( 'daw_tb_held' );
		$t            = daw_tb_today();
		$t['posts'][] = array( $hid, (int) $held['at'] );
		update_option( 'daw_tb_day', $t, false );
	}

	$why = daw_tb_wait_reason();
	if ( '' !== $why && ! $r->get_param( 'force' ) ) {
		return array( 'item' => null, 'why' => $why );
	}
	$ids = daw_tb_candidates();
	if ( ! $ids ) {
		return array( 'item' => null, 'why' => 'nothing ready' );
	}
	$id   = (int) $ids[0];
	$item = daw_tb_item( $id );
	if ( is_wp_error( $item ) ) {
		return array( 'item' => null, 'why' => $item->get_error_message() );
	}
	update_option( 'daw_tb_held', array( 'id' => $id, 'at' => time(), 'n' => daw_tb_log_count( $id ) ), false );
	return array( 'item' => $item );
}

function daw_tb_rest_done( WP_REST_Request $r ) {
	$p   = $r->get_json_params();
	$id  = (int) ( $p['id'] ?? 0 );
	$tid = sanitize_text_field( (string) ( $p['tumblr_id'] ?? '' ) );
	if ( ! $id || '' === $tid || 'post' !== get_post_type( $id ) ) {
		return new WP_Error( 'daw_tb_bad', 'id and tumblr_id are required.', array( 'status' => 400 ) );
	}
	daw_tb_record( $id, $tid );
	return array( 'ok' => true, 'id' => $id, 'tumblr_id' => $tid );
}

/** Counts, kept in an option the management API can read. */
function daw_tb_state() {
	global $wpdb;
	$posted = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_TB_LOG ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$skip   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_TB_SKIP ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$cand   = daw_tb_candidates();
	$t      = daw_tb_today();
	return array(
		'waiting' => count( $cand ),
		'days'    => (int) ceil( count( $cand ) / max( 1, daw_tb_cap_today() ) ),
		'posted'  => $posted,
		'skipped' => $skip,
		'today'   => count( $t['posts'] ),
		'cap'     => daw_tb_cap_today(),
		'wait'    => daw_tb_wait_reason(),
		'start'   => daw_tb_settings()['start'],
		'at'      => time(),
	);
}

function daw_tb_rest_status() {
	$s = daw_tb_state();
	update_option( 'daw_tb_state', $s, false );
	return $s;
}

/* ================================================================ screens === */

/**
 * Posts list: one Yayılım column, a line per network. The Pinterest column
 * pinterest.php adds is taken out and its cell is printed here as it is.
 */
add_filter( 'manage_post_posts_columns', 'daw_tb_column', 30 );
function daw_tb_column( $cols ) {
	unset( $cols['daw_pin'] );
	$cols['daw_yay'] = 'Yayılım';
	return $cols;
}

/** One line on a record's Tumblr state, as HTML. */
function daw_tb_status_html( $post_id ) {
	$log = get_post_meta( $post_id, DAW_TB_LOG, true );
	if ( is_array( $log ) && $log ) {
		$last = end( $log );
		$date = esc_html( wp_date( 'd.m.Y', (int) $last['at'] ) );
		if ( 'atlandi' === $last['post'] ) {
			return '<span style="color:#8a1f1f">Reddedildi</span> <span style="font-size:11px;color:#8c8f94">' . $date . '</span>';
		}
		if ( 'unknown' === $last['post'] ) {
			return 'Paylaşıldı <span style="font-size:11px;color:#8c8f94">' . $date . ' · no. gelmedi</span>';
		}
		return sprintf(
			'<a href="%s" target="_blank" rel="noopener">Paylaşıldı</a> <span style="font-size:11px;color:#8c8f94">%s</span>',
			esc_url( 'https://www.tumblr.com/' . rawurlencode( (string) $last['blog'] ) . '/' . rawurlencode( (string) $last['post'] ) ),
			$date
		);
	}
	if ( get_post_meta( $post_id, DAW_TB_SKIP, true ) ) {
		return '<span style="color:#8c8f94">Gönderilmeyecek</span>';
	}
	return '<span style="color:#8c8f94">Sırada</span>';
}

add_action( 'manage_post_posts_custom_column', 'daw_tb_column_cell', 10, 2 );
function daw_tb_column_cell( $col, $post_id ) {
	if ( 'daw_yay' !== $col ) {
		return;
	}
	if ( function_exists( 'daw_pin_column_cell' ) ) {
		ob_start();
		daw_pin_column_cell( 'daw_pin', $post_id );
		$pin = (string) ob_get_clean();
		echo '<div><span style="color:#646970">P:</span> ' . str_replace( '<br>', ' ', $pin ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '<div><span style="color:#646970">T:</span> ' . daw_tb_status_html( $post_id ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/** Edit screen: where the record has been shared, and the Tumblr opt-out. */
add_action( 'add_meta_boxes_post', 'daw_tb_box' );
function daw_tb_box() {
	add_meta_box( 'daw_tb', 'Yayılım', 'daw_tb_box_html', 'post', 'side', 'low' );
}

/**
 * The Pinterest box moves inside this one (owner, 29 Oct 2026: one box on
 * the side, not two). Its fields, nonce and save hook are pinterest.php's
 * own, printed here unchanged, so ticking them works as it did.
 */
add_action( 'add_meta_boxes_post', 'daw_tb_take_pin_box', 20 );
function daw_tb_take_pin_box() {
	if ( function_exists( 'daw_pin_box_html' ) ) {
		remove_meta_box( 'daw_pin', 'post', 'side' );
	}
}

function daw_tb_box_html( $post ) {
	wp_nonce_field( 'daw_tb_box', 'daw_tb_box_nonce' );
	if ( function_exists( 'daw_pin_box_html' ) ) {
		echo '<p style="margin:0 0 4px"><strong>Pinterest</strong></p>';
		daw_pin_box_html( $post );
		echo '<hr style="margin:10px 0">';
	}
	echo '<p style="margin:0 0 4px"><strong>Tumblr</strong></p>';
	$log = get_post_meta( $post->ID, DAW_TB_LOG, true );
	if ( is_array( $log ) && $log ) {
		echo '<ul style="margin:0 0 8px">';
		foreach ( $log as $e ) {
			$date = esc_html( wp_date( 'd.m.Y', (int) $e['at'] ) );
			if ( 'atlandi' === $e['post'] ) {
				printf( '<li>%s · Tumblr reddetti</li>', $date ); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( 'unknown' === $e['post'] ) {
				printf( '<li>%s · paylaşıldı, no. gelmedi</li>', $date ); // phpcs:ignore WordPress.Security.EscapeOutput
			} else {
				printf( '<li><a href="%s" target="_blank" rel="noopener">%s</a> · %s</li>', esc_url( 'https://www.tumblr.com/' . rawurlencode( (string) $e['blog'] ) . '/' . rawurlencode( (string) $e['post'] ) ), $date, esc_html( (string) $e['blog'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		echo '</ul>';
		printf(
			'<p style="margin:0 0 8px"><label><input type="checkbox" name="daw_tb_again" value="1"%s> Tumblr\'da tekrar paylaş</label><br><span style="color:#646970">Yeni sezon ya da haber olduğunda. Sıranın en önüne geçer.</span></p>',
			checked( (bool) get_post_meta( $post->ID, DAW_TB_AGAIN, true ), true, false )
		);
	} else {
		echo '<p style="margin:0 0 8px">Henüz paylaşılmadı.</p>';
	}
	printf(
		'<label><input type="checkbox" name="daw_tb_skip" value="1"%s> Tumblr\'a gönderme</label>',
		checked( (bool) get_post_meta( $post->ID, DAW_TB_SKIP, true ), true, false )
	);
}

add_action( 'save_post_post', 'daw_tb_box_save', 10, 1 );
function daw_tb_box_save( $post_id ) {
	if ( ! isset( $_POST['daw_tb_box_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['daw_tb_box_nonce'] ), 'daw_tb_box' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! empty( $_POST['daw_tb_skip'] ) ) {
		update_post_meta( $post_id, DAW_TB_SKIP, 1 );
	} else {
		delete_post_meta( $post_id, DAW_TB_SKIP );
	}
	if ( ! empty( $_POST['daw_tb_again'] ) ) {
		if ( ! get_post_meta( $post_id, DAW_TB_AGAIN, true ) ) {
			update_post_meta( $post_id, DAW_TB_AGAIN, time() );
		}
	} else {
		delete_post_meta( $post_id, DAW_TB_AGAIN );
	}
}

/** The Yayılım screen: a tab per network. */
add_action( 'admin_menu', 'daw_tb_menu' );
function daw_tb_menu() {
	add_submenu_page( 'daw', 'Yayılım', 'Yayılım', 'manage_options', 'daw-yayilim', 'daw_tb_page' );
}

/**
 * Pinterest leaves the DAW menu and is reached from the Yayılım screen's
 * Pinterest tab. The page itself stays registered, so its address and its
 * forms keep working.
 */
add_action( 'admin_menu', 'daw_tb_hide_pin_menu', 99 );
function daw_tb_hide_pin_menu() {
	remove_submenu_page( 'daw', 'daw-pinterest' );
}

add_action( 'admin_post_daw_tb_settings', 'daw_tb_settings_save' );
function daw_tb_settings_save() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'daw_tb_settings' ) ) {
		wp_die( 'No.' );
	}
	$s          = daw_tb_settings();
	$s['on']    = empty( $_POST['on'] ) ? 0 : 1;
	$s['scope'] = isset( $_POST['scope'] ) && 'all' === $_POST['scope'] ? 'all' : 'locked';
	$s['from']  = max( 0, min( 23, (int) ( $_POST['from'] ?? 8 ) ) );
	$s['to']    = max( $s['from'] + 1, min( 24, (int) ( $_POST['to'] ?? 23 ) ) );
	$ramp       = array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ramp'] ?? '6,8,10' ) ) ) );
	$s['ramp']  = implode( ',', array_slice( array_pad( array_filter( $ramp ), 3, 10 ), 0, 3 ) );
	update_option( 'daw_tb', $s, false );
	update_option( 'daw_tb_state', daw_tb_state(), false );
	wp_safe_redirect( admin_url( 'admin.php?page=daw-yayilim&tab=tumblr&saved=1' ) );
	exit;
}

function daw_tb_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$tab = isset( $_GET['tab'] ) && 'pinterest' === $_GET['tab'] ? 'pinterest' : 'tumblr'; // phpcs:ignore WordPress.Security.NonceVerification
	$why = array(
		''                  => 'Şimdi gönderi gidebilir',
		'off'               => 'Kapalı',
		'outside hours'     => 'Saat aralığı dışında (New York)',
		'daily cap reached' => 'Bugünkü sınır doldu',
		'too soon'          => 'Bir önceki gönderiden sonra ara bekleniyor',
	);

	echo '<div class="wrap"><h1>Yayılım</h1><nav class="nav-tab-wrapper">';
	foreach ( array( 'tumblr' => 'Tumblr', 'pinterest' => 'Pinterest' ) as $k => $label ) {
		printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( admin_url( 'admin.php?page=daw-yayilim&tab=' . $k ) ), $tab === $k ? ' nav-tab-active' : '', esc_html( $label ) );
	}
	echo '</nav>';

	if ( 'pinterest' === $tab ) {
		// The Pinterest screen itself, as pinterest.php draws it: its forms
		// post to admin-post.php and its own page stays registered (hidden
		// from the menu), so saving and refreshing keep working.
		if ( function_exists( 'daw_pin_page' ) ) {
			daw_pin_page();
		} else {
			echo '<p>Pinterest modülü yüklü değil.</p>';
		}
		echo '</div>';
		return;
	}

	$s = daw_tb_settings();
	// The counts walk every record, as on the Pinterest screen: the last
	// saved ones are shown, rebuilt when an hour old or asked.
	$st = get_option( 'daw_tb_state', array() );
	if ( ! is_array( $st ) || empty( $st['at'] ) || time() - (int) $st['at'] > HOUR_IN_SECONDS || ! empty( $_GET['refresh'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$st = daw_tb_state();
		update_option( 'daw_tb_state', $st, false );
	}
	$st += array( 'waiting' => 0, 'posted' => 0, 'skipped' => 0, 'today' => 0, 'cap' => 0, 'wait' => '', 'days' => 0 );
	printf(
		'<p class="description">Sayılar %s itibarıyla. <a href="%s">Şimdi yenile</a></p>',
		esc_html( wp_date( 'd.m.Y H:i', (int) ( $st['at'] ?? time() ) ) ),
		esc_url( admin_url( 'admin.php?page=daw-yayilim&tab=tumblr&refresh=1' ) )
	);
	printf(
		'<p><strong>%d</strong> kayıt sırada · <strong>%d</strong> paylaşıldı · <strong>%d</strong> gönderilmeyecek · bugün <strong>%d / %d</strong> · %s · bu hızla yaklaşık <strong>%d</strong> gün</p>',
		(int) $st['waiting'],
		(int) $st['posted'],
		(int) $st['skipped'],
		(int) $st['today'],
		(int) $st['cap'],
		esc_html( $why[ $st['wait'] ] ?? $st['wait'] ),
		(int) $st['days']
	);
	printf( '<p>Blog: <a href="%s" target="_blank" rel="noopener">%s.tumblr.com</a></p>', esc_url( 'https://www.tumblr.com/' . DAW_TB_BLOG ), esc_html( DAW_TB_BLOG ) );

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'daw_tb_settings' );
	echo '<input type="hidden" name="action" value="daw_tb_settings"><table class="form-table">';
	printf( '<tr><th>Açık</th><td><label><input type="checkbox" name="on" value="1"%s> Make sorduğunda gönderi ver</label></td></tr>', checked( (bool) $s['on'], true, false ) );
	printf(
		'<tr><th>Kapsam</th><td><select name="scope"><option value="locked"%s>Sadece kilitli (bitti) kayıtlar</option><option value="all"%s>Yayındaki her kayıt</option></select></td></tr>',
		selected( $s['scope'], 'locked', false ),
		selected( $s['scope'], 'all', false )
	);
	printf( '<tr><th>Saat (New York)</th><td><input type="number" name="from" value="%d" min="0" max="23" style="width:70px"> ile <input type="number" name="to" value="%d" min="1" max="24" style="width:70px"> arası</td></tr>', (int) $s['from'], (int) $s['to'] );
	printf( '<tr><th>Günlük adet</th><td><input type="text" name="ramp" value="%s" style="width:90px"> <span class="description">ilk iki hafta, sonraki iki hafta, sonrası%s</span></td></tr>', esc_attr( $s['ramp'] ), '' !== $s['start'] ? esc_html( ' · ilk gönderi ' . $s['start'] ) : '' );
	echo '</table>';
	submit_button( 'Kaydet' );
	echo '</form>';

	echo '<h2>Sıradaki gönderiler</h2><ol>';
	foreach ( array_slice( daw_tb_candidates(), 0, 10 ) as $id ) {
		printf( '<li><a href="%s">%s</a></li>', esc_url( get_edit_post_link( $id ) ), esc_html( function_exists( 'daw_pin_plain' ) ? daw_pin_plain( get_the_title( $id ) ) : get_the_title( $id ) ) );
	}
	echo '</ol>';

	printf( '<h2>Make</h2><p>Anahtar (<code>X-DAW-TB-KEY</code>): <code>%s</code></p>', esc_html( daw_tb_key() ) );
	echo '</div>';
}
