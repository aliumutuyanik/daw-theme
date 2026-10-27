<?php
/**
 * Put the actor quiz on a measurement.
 *
 * WHAT WAS WRONG WITH IT
 * ----------------------
 * The quiz asked a reader to reproduce an order that the page itself described
 * as "ours, not a measurement". So the right answer was a private opinion, the
 * reader had no way to reason toward it, and being marked wrong told them
 * nothing. A quiz like that is a coin toss with extra steps.
 *
 * WHAT IT IS NOW
 * --------------
 * The same ten actors, ordered by how often their names are actually searched
 * for. The figure under each name is a twelve month average of global monthly
 * Google searches, read in September 2026. A reader who gets it wrong can see
 * exactly why, because the number that settles it is printed next to the name.
 *
 * The answer is counter-intuitive, which is what makes it worth playing: the
 * order people expect, led by the actor who was famous first, is not the order
 * the searches are in.
 *
 * WHY IT IS A ONE-SHOT AND NOT A TYPED-IN EDIT
 * -------------------------------------------
 * The rows live in one serialised Meta Box field. Ten rows, ten notes and a new
 * order is a long afternoon by hand and a transcription error waiting to
 * happen. Written here it is reviewable, repeatable and dated.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_QUIZ_SEED_RUN = '2026-09-19-search-volume-c';
const DAW_QUIZ_SEED_ID  = 10232;

/**
 * The ranking, its evidence and its reasoning.
 *
 * Figures are rounded. Neighbouring places can sit within a few thousand
 * searches of each other, which the note on the quiz says out loud rather than
 * pretending the third and fourth places are far apart.
 */
function daw_quiz_seed_rows() {
	return array(
		array(
			'name'   => 'Cha Eun Woo',
			'fact'   => '1,739,000 a month',
			'record' => 3481,
			'rank'   => 1,
			'note'   => 'About 1,739,000 searches a month. Idol and actor at once, which means two fandoms searching the same name, and by some distance the most looked-up person on this list.',
		),
		array(
			'name'   => 'Park Bo Gum',
			'fact'   => '1,105,000 a month',
			'record' => 9752,
			'rank'   => 2,
			'note'   => 'About 1,105,000 searches a month, and the steepest curve here: the figure more than doubled around his return to screen before settling back.',
		),
		array(
			'name'   => 'Ji Chang Wook',
			'fact'   => '869,000 a month',
			'record' => 8966,
			'rank'   => 3,
			'note'   => 'About 869,000 searches a month. The most consistent of the ten, barely moving month to month, which is what a long career of steady leads looks like in search data.',
		),
		array(
			'name'   => 'Lee Min Ho',
			'fact'   => '860,000 a month',
			'record' => 3583,
			'rank'   => 4,
			'note'   => 'About 860,000 searches a month. The name most people put first, and it is fourth. Boys Over Flowers made him the face of the Hallyu wave, and the searches have been slowly easing ever since.',
		),
		array(
			'name'   => 'Kim Soo Hyun',
			'fact'   => '806,000 a month',
			'record' => 2802,
			'rank'   => 5,
			'note'   => 'About 806,000 searches a month, with the sharpest single spike on this list when a story about him broke.',
		),
		array(
			'name'   => 'Park Seo Joon',
			'fact'   => '588,000 a month',
			'record' => 5097,
			'rank'   => 6,
			'note'   => 'About 588,000 searches a month. Itaewon Class and a Marvel appearance keep the floor high even in quiet months.',
		),
		array(
			'name'   => 'Gong Yoo',
			'fact'   => '583,000 a month',
			'record' => 3517,
			'rank'   => 7,
			'note'   => 'About 583,000 searches a month. Goblin and Squid Game carry him, and the gap to sixth place is small enough that the two swap around.',
		),
		array(
			'name'   => 'Song Joong Ki',
			'fact'   => '552,000 a month',
			'record' => 1644,
			'rank'   => 8,
			'note'   => 'About 552,000 searches a month. Descendants of the Sun was the peak and the number has been flat for a while.',
		),
		array(
			'name'   => 'Hyun Bin',
			'fact'   => '492,000 a month',
			'record' => 2106,
			'rank'   => 9,
			'note'   => 'About 492,000 searches a month. Crash Landing on You was enormous, and the searches have come a long way down from it.',
		),
		array(
			'name'   => 'Lee Joon Gi',
			'fact'   => '164,000 a month',
			'record' => 4426,
			'rank'   => 10,
			'note'   => 'About 164,000 searches a month. The smallest figure here by a wide margin, which says more about how large the other nine are than about him.',
		),
	);
}

add_action( 'admin_init', 'daw_quiz_seed', 36 );
function daw_quiz_seed() {
	if ( function_exists( 'daw_is_delicate_request' ) && daw_is_delicate_request() ) {
		return;
	}

	if ( get_option( 'daw_quiz_seed_run' ) === DAW_QUIZ_SEED_RUN ) {
		return;
	}

	$post = get_post( DAW_QUIZ_SEED_ID );

	if ( ! $post ) {
		update_option( 'daw_quiz_seed_run', DAW_QUIZ_SEED_RUN, false );
		return;
	}

	$rows = array();

	foreach ( daw_quiz_seed_rows() as $row ) {
		$rows[] = array(
			'daw_q_text'    => $row['name'],
			'daw_q_record'  => (int) $row['record'],
			'daw_q_answer'  => (string) $row['rank'],
			'daw_q_options' => '',
			'daw_q_fact'    => $row['fact'],
			'daw_q_note'    => $row['note'],
		);
	}

	update_post_meta( DAW_QUIZ_SEED_ID, 'daw_q', $rows );
	update_post_meta( DAW_QUIZ_SEED_ID, 'daw_quiz_kind', 'rank' );
	update_post_meta( DAW_QUIZ_SEED_ID, 'daw_quiz_scored', 'yes' );

	update_post_meta(
		DAW_QUIZ_SEED_ID,
		'daw_quiz_lead',
		'Ten Korean actors, ordered by search volume: how many people look each name up on Google every month, worldwide. Put them in order, one for the most searched and ten for the least. The answer is not the order most people expect, and every figure is printed once you check.'
	);

	update_post_meta(
		DAW_QUIZ_SEED_ID,
		'daw_quiz_source',
		'how often each name is searched for on Google, a twelve month average of worldwide monthly search volume, read in September 2026'
	);

	update_post_meta(
		DAW_QUIZ_SEED_ID,
		'daw_guide_standfirst',
		'Ten Korean actors, ordered by how many people look their names up every month. Put them in the right order before you see the numbers.'
	);

	update_option( 'daw_quiz_seed_run', DAW_QUIZ_SEED_RUN, false );
}
