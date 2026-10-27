<?php
/**
 * Discover Asian World — our own ratings
 *
 * Ratings are collected here, on our own records, the way blglflix does it.
 * That matters beyond product preference: Google only permits aggregateRating
 * markup for ratings a site gathers itself. Borrowing someone else's score and
 * marking it up as your own is a manual-action risk, so we never do it.
 *
 * Storage is two integers per record — a running sum and a count — rather than
 * a row per vote. At 926 records and an unknown number of visitors, that keeps
 * the read path to a single meta lookup with no query, and a rating widget that
 * cannot slow a page down.
 *
 * One vote per browser, held in localStorage. That is a courtesy lock, not a
 * security boundary: anyone determined can clear it. Treating it as more than
 * that would mean logins and stored identifiers, which this site does not need
 * and visitors have not consented to.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** Below this many votes we show the score but never mark it up as schema. */
const DAW_RATING_SCHEMA_FLOOR = 25;

/**
 * @return array{avg: float, count: int}
 */
function daw_rating( $post_id ) {
	$count = (int) get_post_meta( $post_id, 'daw_rating_count', true );
	$sum   = (int) get_post_meta( $post_id, 'daw_rating_sum', true );

	return array(
		'avg'   => $count > 0 ? round( $sum / $count, 1 ) : 0.0,
		'count' => $count,
	);
}

/**
 * Whether this record has enough votes to be described as rated in structured
 * data. A score built from three votes is not a rating, it is an anecdote.
 */
function daw_rating_is_publishable( $post_id ) {
	return daw_rating( $post_id )['count'] >= DAW_RATING_SCHEMA_FLOOR;
}

add_action( 'rest_api_init', 'daw_rating_routes' );
function daw_rating_routes() {
	register_rest_route(
		'daw/v1',
		'/rate',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => 'daw_rating_submit',
			'args'                => array(
				'post_id' => array(
					'required'          => true,
					'validate_callback' => function ( $v ) {
						return is_numeric( $v ) && get_post_status( (int) $v ) === 'publish';
					},
				),
				'score'   => array(
					'required'          => true,
					'validate_callback' => function ( $v ) {
						return is_numeric( $v ) && (int) $v >= 1 && (int) $v <= 10;
					},
				),
			),
		)
	);
}

function daw_rating_submit( WP_REST_Request $req ) {
	$post_id = (int) $req->get_param( 'post_id' );
	$score   = (int) $req->get_param( 'score' );

	// A record that is not one of ours is not rateable.
	if ( ! function_exists( 'daw_get_type' ) || ! daw_get_type( $post_id ) ) {
		return new WP_Error( 'daw_not_rateable', __( 'Not a record.', 'daw' ), array( 'status' => 400 ) );
	}

	// Light throttle by IP so a loop cannot inflate a score in one sitting.
	// Deliberately short-lived and not stored beyond it.
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'daw_rate_' . md5( $ip . '|' . $post_id );
	if ( $ip && get_transient( $key ) ) {
		return new WP_REST_Response( daw_rating( $post_id ) + array( 'repeat' => true ), 200 );
	}
	if ( $ip ) {
		set_transient( $key, 1, DAY_IN_SECONDS );
	}

	$count = (int) get_post_meta( $post_id, 'daw_rating_count', true );
	$sum   = (int) get_post_meta( $post_id, 'daw_rating_sum', true );

	update_post_meta( $post_id, 'daw_rating_count', $count + 1 );
	update_post_meta( $post_id, 'daw_rating_sum', $sum + $score );

	// Five counters alongside the total, so the distribution bars in the rating
	// card are real numbers. Cheaper than a row per vote and it answers the only
	// question the bars ask: how many people landed in each band.
	$bands = get_post_meta( $post_id, 'daw_rating_bands', true );
	$bands = is_array( $bands ) ? $bands : array();
	$band  = daw_rating_band( $score );

	$bands[ $band ] = ( isset( $bands[ $band ] ) ? (int) $bands[ $band ] : 0 ) + 1;
	update_post_meta( $post_id, 'daw_rating_bands', $bands );

	return new WP_REST_Response( daw_rating( $post_id ), 200 );
}

/**
 * Which band a score falls into. Bands are pairs, matching the card.
 */
function daw_rating_band( $score ) {
	$score = (int) $score;

	if ( $score >= 9 ) {
		return '9-10';
	}
	if ( $score >= 7 ) {
		return '7-8';
	}
	if ( $score >= 5 ) {
		return '5-6';
	}
	if ( $score >= 3 ) {
		return '3-4';
	}

	return '1-2';
}
