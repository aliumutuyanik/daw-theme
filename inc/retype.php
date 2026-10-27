<?php
/**
 * Discover Asian World — a one-time correction of misfiled record types.
 *
 * WHY IT IS IN THE PLUGIN AND NOT DONE BY HAND
 * --------------------------------------------
 * The dw_ taxonomies are deliberately not exposed over REST, which is what
 * keeps the record model out of reach of anything but this site's own code. The
 * cost of that is that a batch of corrections cannot be made from outside, and
 * fourteen records retyped by hand in wp-admin is fourteen chances to click the
 * wrong box. So the corrections travel with the plugin, run once, and leave a
 * note in the log of what they changed.
 *
 * IT SETS, IT DOES NOT ADD
 * ------------------------
 * wp_set_object_terms with append off, so a record that ended up carrying two
 * types comes out of this carrying one. A record whose type is already right is
 * left alone and not counted.
 *
 * SAFE TO RUN AGAIN
 * -----------------
 * The stamp is the map itself, so editing the list below runs the new entries
 * and nothing else. Deleting the option re-runs everything, which is harmless:
 * setting a type to what it already is changes nothing.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * post id => the type it should have been filed under.
 *
 * Every line here was checked against the record itself:
 *  - Jimin is a person who sings and is a member of BTS, not a band.
 *  - Seven Cha Eun-woo pieces, a ranked actor list and a ranked drama list were
 *    filed under Culture, which put them beside an explainer on how a Korean
 *    table works. They are features.
 *  - Two food guides were also under Culture.
 *  - The population guide was under Travel as well as Culture; it is Culture.
 */
function daw_retype_map() {
	return array(
		9049  => 'person',  // Jimin
		12990 => 'feature', // Cha Eun-woo: brand ambassadorships
		12974 => 'feature', // Cha Eun-woo: ideal partner
		12958 => 'feature', // Cha Eun-woo: family
		12826 => 'feature', // Cha Eun-woo: hairstyles
		12804 => 'feature', // Cha Eun-woo: MBTI
		12782 => 'feature', // Cha Eun-woo and Moonbin
		12766 => 'feature', // Cha Eun-woo: military enlistment
		10223 => 'feature', // Top 10 most handsome Korean actors
		9591  => 'feature', // The ten K-dramas of 2023
		11286 => 'food',    // Your Ultimate Guide to Bubble Tea
		11212 => 'food',    // Korean food culture
		12045 => 'culture', // South Korea's population
	);
}

add_action( 'init', 'daw_run_retype', 25 );
function daw_run_retype() {
	$map   = daw_retype_map();
	$stamp = md5( wp_json_encode( $map ) );

	if ( get_option( 'daw_retype_stamp' ) === $stamp ) {
		return;
	}

	$done = array();

	foreach ( $map as $post_id => $type ) {
		$post = get_post( (int) $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			continue;
		}

		$now = wp_get_object_terms( (int) $post_id, 'dw_type', array( 'fields' => 'slugs' ) );

		if ( is_wp_error( $now ) ) {
			continue;
		}

		if ( array( $type ) === array_values( (array) $now ) ) {
			continue;
		}

		$set = wp_set_object_terms( (int) $post_id, $type, 'dw_type', false );

		if ( ! is_wp_error( $set ) ) {
			$done[] = $post_id . ' ' . implode( '+', (array) $now ) . ' → ' . $type;
		}
	}

	update_option( 'daw_retype_stamp', $stamp, false );

	if ( $done && function_exists( 'daw_browse_bump_gen' ) ) {
		daw_browse_bump_gen();
	}

	if ( $done ) {
		update_option( 'daw_retype_log', $done, false );
	}
}

/* ============================================================== country === */

/**
 * Records that were never filed under a country, and the country they belong to.
 *
 * WHY THIS IS SEPARATE FROM THE TYPE MAP
 * --------------------------------------
 * A type is exclusive: a record is a quiz or it is a guide, never both, so
 * setting it replaces whatever was there. A country is not always: a guide can
 * be about two, and a record that already carries one must not lose it because
 * a second was added here. So this appends and the type map replaces.
 *
 * WHY IT MATTERS AT ALL
 * ---------------------
 * /korean-test/ is the quizzes list locked to South Korea, and it printed "0
 * quizzes" over an empty grid because the one quiz in the archive had never
 * been given a country. The page was right and the record was incomplete,
 * which is the more common way round.
 */
function daw_country_fix_map() {
	return array(
		10232 => 'south-korea', // Top 10 Korean Actors quiz.
	);
}

add_action( 'init', 'daw_run_country_fix', 25 );
function daw_run_country_fix() {
	$map   = daw_country_fix_map();
	$stamp = md5( wp_json_encode( $map ) );

	if ( get_option( 'daw_country_fix_stamp' ) === $stamp ) {
		return;
	}

	foreach ( $map as $post_id => $country ) {
		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			continue;
		}

		$now = wp_get_object_terms( (int) $post_id, 'dw_country', array( 'fields' => 'slugs' ) );

		if ( is_wp_error( $now ) || in_array( $country, (array) $now, true ) ) {
			continue;
		}

		wp_set_object_terms( (int) $post_id, $country, 'dw_country', true );
	}

	update_option( 'daw_country_fix_stamp', $stamp, false );

	if ( function_exists( 'daw_browse_bump_gen' ) ) {
		daw_browse_bump_gen();
	}
}
