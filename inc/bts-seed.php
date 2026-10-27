<?php
/**
 * The five BTS profiles that did not exist, filled once from sourced facts.
 *
 * WHY A ONE-SHOT AND NOT THIRTY EDITS
 * -----------------------------------
 * Five records needing six fields each is thirty small writes, and thirty small
 * writes made by hand is thirty chances to put Suga's birthday on J-Hope. Doing
 * it in one reviewable block means the data can be read against its source in
 * one sitting, and re-run without doing anything twice.
 *
 * WHERE THESE FACTS COME FROM
 * ---------------------------
 * Each member's own Wikipedia article, read on 19 September 2026: birth name in
 * Latin and Hangul, date of birth, place of birth, and the occupations the
 * article itself lists. Nothing here is a fan wiki's opinion about who the
 * "main" dancer is. Where a role is a matter of who you ask, the field says
 * what the group and the article actually state and stops there.
 *
 * It writes only into fields that are empty, so an editor who has since
 * corrected something keeps their correction.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_BTS_SEED_RUN = '2026-09-19-members-b';

add_action( 'admin_init', 'daw_bts_seed', 45 );
function daw_bts_seed() {
	if ( get_option( 'daw_bts_seed' ) === DAW_BTS_SEED_RUN ) {
		return;
	}

	$group = 6204;

	$people = array(
		24915 => array(
			'daw_native_name'     => '김남준',
			'daw_born'            => 779328000,   // 12 September 1994
			'daw_birth_place'     => 'Seoul, South Korea',
			'daw_occupation'      => 'Rapper, songwriter and record producer',
			'daw_group_position'  => 'Leader and rapper',
			'daw_gender'          => 'male',
			'daw_nationality'     => 'South Korean',
		),
		24916 => array(
			'daw_native_name'     => '김석진',
			'daw_born'            => 723427200,   // 4 December 1992
			'daw_birth_place'     => 'Anyang, South Korea',
			'daw_occupation'      => 'Singer',
			'daw_group_position'  => 'Vocalist',
			'daw_gender'          => 'male',
			'daw_nationality'     => 'South Korean',
		),
		24917 => array(
			'daw_native_name'     => '민윤기',
			'daw_born'            => 731635200,   // 9 March 1993
			'daw_birth_place'     => 'Daegu, South Korea',
			'daw_occupation'      => 'Rapper, songwriter and record producer',
			'daw_group_position'  => 'Rapper and producer',
			'daw_gender'          => 'male',
			'daw_nationality'     => 'South Korean',
		),
		24918 => array(
			'daw_native_name'     => '정호석',
			'daw_born'            => 761529600,   // 18 February 1994
			'daw_birth_place'     => 'Gwangju, South Korea',
			'daw_occupation'      => 'Rapper, singer, songwriter and dancer',
			'daw_group_position'  => 'Rapper and dancer',
			'daw_gender'          => 'male',
			'daw_nationality'     => 'South Korean',
		),
		24919 => array(
			'daw_native_name'     => '전정국',
			'daw_born'            => 873072000,   // 1 September 1997
			'daw_birth_place'     => 'Busan, South Korea',
			'daw_occupation'      => 'Singer and songwriter',
			'daw_group_position'  => 'Vocalist',
			'daw_gender'          => 'male',
			'daw_nationality'     => 'South Korean',
		),
	);

	foreach ( $people as $id => $fields ) {
		if ( 'publish' !== get_post_status( $id ) ) {
			continue;
		}

		foreach ( $fields as $key => $value ) {
			$have = get_post_meta( $id, $key, true );

			if ( '' === $have || null === $have || ( is_numeric( $value ) && ! (int) $have ) ) {
				update_post_meta( $id, $key, $value );
			}
		}

		// The link that puts them in the line-up on the group page.
		if ( '' === (string) get_post_meta( $id, 'daw_member_of', true ) ) {
			update_post_meta( $id, 'daw_member_of', (string) $group );
		}
	}

	// The two that already existed keep their records and gain only the link
	// and the position, if those were missing.
	foreach ( array( 9049 => 'Vocalist and dancer', 20487 => 'Vocalist' ) as $id => $position ) {
		if ( 'publish' !== get_post_status( $id ) ) {
			continue;
		}
		if ( '' === (string) get_post_meta( $id, 'daw_member_of', true ) ) {
			update_post_meta( $id, 'daw_member_of', (string) $group );
		}
		if ( '' === (string) get_post_meta( $id, 'daw_group_position', true ) ) {
			update_post_meta( $id, 'daw_group_position', $position );
		}
	}

	// THE LIST IS CACHED, SO IT HAS TO BE TOLD
	// ----------------------------------------
	// The member list is held for six hours under a key that carries the
	// browse generation. The first version of this called daw_browse_bump(),
	// which is not the name of anything: the guard around it meant the call
	// was skipped in silence, the generation never moved, and five members who
	// were correctly linked in the database did not appear on the page. The
	// function is daw_browse_bump_gen(). Both keys are cleared as well, so a
	// stale entry cannot survive under the old generation either.
	$before = function_exists( 'daw_browse_gen' ) ? daw_browse_gen() : 1;

	if ( function_exists( 'daw_browse_bump_gen' ) ) {
		daw_browse_bump_gen();
	}

	$after = function_exists( 'daw_browse_gen' ) ? daw_browse_gen() : 1;

	foreach ( array_unique( array( $before, $after ) ) as $gen ) {
		delete_transient( 'daw_group_members_' . $group . '_' . $gen );
	}

	update_option( 'daw_bts_seed', DAW_BTS_SEED_RUN, false );
}
