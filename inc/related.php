<?php
/**
 * Similar titles.
 *
 * WHY IT EARNS ITS PLACE
 * ----------------------
 * The rest of this page answers "what is this". This section answers "what
 * next", which is the question a reader actually has when they reach the
 * bottom. Without it the only links out of a record are the ones that leave
 * the site entirely — the streaming rows — so the page spends its own traffic.
 *
 * HOW THE MATCH IS MADE
 * ---------------------
 * Same kind of record, same country, and at least one genre in common; then
 * ranked by how many genres are shared, with a small bonus for the same
 * network and for a nearby broadcast year. That ordering matters: a reader who
 * finishes a 2014 SBS newsroom romance is better served by another 2010s SBS
 * romance than by a 2003 historical epic that happens to be tagged "drama".
 *
 * The result is cached per record for a day. Recomputing a genre intersection
 * on every page view of 926 records is exactly the kind of work that makes a
 * site feel slow for no visible gain.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_SIMILAR_TTL = DAY_IN_SECONDS;

/**
 * @param int $post_id
 * @param int $limit  How many to return.
 * @return array  [ [ 'id' => int, 'score' => int ], … ]
 */
function daw_get_similar( $post_id, $limit = 6 ) {
	$post_id = (int) $post_id;
	// v2 (1.75.74): rows carry 'country'. The prefix still starts daw_sim_,
	// so daw_flush_similar_cache() clears both shapes (rule 8.4).
	$key     = 'daw_sim_v2_' . $post_id . '_' . (int) $limit;
	$cached  = get_transient( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$type = daw_get_type( $post_id );
	if ( ! in_array( $type, array( 'drama', 'film', 'variety' ), true ) ) {
		return array();
	}

	$genres  = daw_term_slugs( $post_id, 'dw_genre' );
	$country = daw_term_slugs( $post_id, 'dw_country' );
	$network = daw_term_slugs( $post_id, 'dw_network' );

	if ( ! $genres ) {
		return array();
	}

	$tax_query = array(
		'relation' => 'AND',
		array(
			'taxonomy' => 'dw_type',
			'field'    => 'slug',
			'terms'    => $type,
		),
		array(
			'taxonomy' => 'dw_genre',
			'field'    => 'slug',
			'terms'    => $genres,
		),
	);

	if ( $country ) {
		$tax_query[] = array(
			'taxonomy' => 'dw_country',
			'field'    => 'slug',
			'terms'    => $country,
		);
	}

	$pool = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 60,
			'post__not_in'   => array( $post_id ),
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);

	// NO GENRE IN COMMON AT HOME BEATS A GENRE MATCH ABROAD (1.75.74)
	// The loose match used to drop the country first, so a Thai film whose
	// genres no other Thai film shared got six Korean films under "Matched on
	// genre, country and era" (Bad Genius, 16 Oct 2026). The same country is
	// tried without the genre first, ranked by era; only a country with no
	// other record of this kind falls back to the genre match abroad, and
	// the note under the heading then says so.
	$same_country = (bool) $country;
	$need_genre   = true;
	if ( ! $pool && $country ) {
		$pool = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 60,
				'post__not_in'   => array( $post_id ),
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					$tax_query[0],
					end( $tax_query ),
				),
			)
		);
		$need_genre = false;
	}

	// A record with no country set, or a country nobody else shares, would come
	// back empty. Better a looser match than an empty section.
	if ( ! $pool && $country ) {
		array_pop( $tax_query );
		$same_country = false;
		$need_genre   = true;
		$pool = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 60,
				'post__not_in'   => array( $post_id ),
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
	}

	$year   = (int) gmdate( 'Y', (int) daw_field_raw( $post_id, 'daw_aired_start' ) );
	$scored = array();

	foreach ( $pool as $pid ) {
		$shared = count( array_intersect( $genres, daw_term_slugs( $pid, 'dw_genre' ) ) );
		if ( ! $shared && $need_genre ) {
			continue;
		}

		$score = $shared * 10;

		if ( $network && array_intersect( $network, daw_term_slugs( $pid, 'dw_network' ) ) ) {
			$score += 6;
		}

		if ( $year ) {
			$other = (int) gmdate( 'Y', (int) daw_field_raw( $pid, 'daw_aired_start' ) );
			if ( $other ) {
				$gap    = abs( $year - $other );
				$score += max( 0, 8 - $gap ); // same year is worth eight, a decade apart nothing.
			}
		}

		// index-boost.php lifts a match Google has not yet taken in.
		$score    = (int) apply_filters( 'daw_similar_score', $score, (int) $pid, $post_id );
		$scored[] = array( 'id' => (int) $pid, 'score' => $score, 'country' => $same_country );
	}

	usort(
		$scored,
		static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		}
	);

	$out = array_slice( $scored, 0, (int) $limit );
	set_transient( $key, $out, DAW_SIMILAR_TTL );

	return $out;
}

/**
 * Term slugs for a record, statically cached — this runs forty times per
 * lookup and the same records keep coming back.
 */
function daw_term_slugs( $post_id, $taxonomy ) {
	static $cache = array();
	$key = $taxonomy . ':' . (int) $post_id;

	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$terms = get_the_terms( $post_id, $taxonomy );
	$cache[ $key ] = ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'slug' ) : array();

	return $cache[ $key ];
}

/**
 * The cache has to fall over when the thing it describes changes, or a newly
 * tagged record never appears next to the ones it belongs with.
 */
add_action( 'save_post_post', 'daw_flush_similar_cache' );
add_action( 'set_object_terms', 'daw_flush_similar_cache' );
function daw_flush_similar_cache() {
	global $wpdb;

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_daw_sim_%' OR option_name LIKE '_transient_timeout_daw_sim_%'"
	);
}
