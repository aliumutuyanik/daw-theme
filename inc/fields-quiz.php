<?php
/**
 * Discover Asian World — quizzes as data.
 *
 * WHAT WAS THERE BEFORE
 * ---------------------
 * Two quizzes, each one a complete html document with its own doctype, its own
 * stylesheet and its own script, pasted into an Elementor html widget. They
 * worked. They also carried a second set of colours, a second font stack and a
 * hard-coded list of ten actors with their photo urls typed in by hand, which
 * meant a person who changed agency or got a new portrait was right on their own
 * record and wrong inside the quiz.
 *
 * So the quiz becomes rows: a kind, a list of questions, and for the ranking
 * kind a person record per row. The picture and the link come from the record,
 * which is the same rule the rest of the site follows and the reason nothing
 * here can drift out of date on its own.
 *
 * TWO KINDS, BECAUSE THERE WERE TWO
 * ---------------------------------
 * 'choice' is a question with an image and a set of answers, one of them right.
 * 'rank'   is a list the reader puts in order, scored against the order the
 *          editor set.
 * A third kind is a third template; these two are what the archive holds.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'rwmb_meta_boxes', 'daw_register_quiz_meta_boxes', 14 );
function daw_register_quiz_meta_boxes( $boxes ) {
	$boxes[] = array(
		'id'         => 'daw_quiz_setup',
		'title'      => __( 'Quiz — how it is played', 'daw' ),
		'post_types' => array( 'post' ),
		'context'    => 'normal',
		'priority'   => 'high',
		'daw_types'  => array( 'quiz' ),
		'fields'     => array(
			array(
				'id'      => 'daw_quiz_kind',
				'name'    => __( 'Kind', 'daw' ),
				'type'    => 'select',
				'columns' => 4,
				'options' => array(
					'choice' => __( 'Multiple choice', 'daw' ),
					'rank'   => __( 'Put in order', 'daw' ),
				),
				'std'     => 'choice',
			),
			array(
				'id'      => 'daw_quiz_scored',
				'name'    => __( 'Show a score at the end', 'daw' ),
				'type'    => 'select',
				'columns' => 4,
				'options' => array(
					'yes' => __( 'Yes', 'daw' ),
					'no'  => __( 'No', 'daw' ),
				),
				'std'     => 'yes',
			),
			array(
				'id'      => 'daw_quiz_lead',
				'name'    => __( 'How to play', 'daw' ),
				'type'    => 'textarea',
				'rows'    => 3,
				'desc'    => __( 'One or two sentences above the first question. What the reader is being asked to do.', 'daw' ),
			),
			// WHERE THE RIGHT ORDER COMES FROM
			// --------------------------------
			// A ranking quiz asserts that one order is correct. That is a claim,
			// and on this site a claim carries its source. Filled in, the quiz
			// prints the source under the result. Left empty, it says the order
			// is the editors' own, which is also true and is better than letting
			// a reader assume it was measured.
			array(
				'id'      => 'daw_quiz_source',
				'name'    => __( 'Where the order comes from', 'daw' ),
				'type'    => 'text',
				'desc'    => __( 'Ranking quizzes only. For example: Actor Brand Reputation Index, March 2026. Leave empty and the quiz says the order is an editorial pick.', 'daw' ),
			),
			array(
				'id'      => 'daw_quiz_source_url',
				'name'    => __( 'Source link', 'daw' ),
				'type'    => 'url',
			),
		),
	);

	$boxes[] = array(
		'id'         => 'daw_quiz_body',
		'title'      => __( 'Quiz — questions', 'daw' ),
		'post_types' => array( 'post' ),
		'context'    => 'normal',
		'daw_types'  => array( 'quiz' ),
		'fields'     => array(
			array(
				'id'          => 'daw_q',
				'name'        => __( 'Question', 'daw' ),
				'type'        => 'group',
				'clone'       => true,
				'sort_clone'  => true,
				'collapsible' => true,
				'group_title' => '{#}. {daw_q_text}',
				'fields'      => array(
					array(
						'id'   => 'daw_q_text',
						'name' => __( 'Question or name', 'daw' ),
						'type' => 'text',
						'desc' => __( 'For a ranking quiz this is simply the name, and the record below supplies the rest.', 'daw' ),
					),
					array(
						'id'         => 'daw_q_record',
						'name'       => __( 'The record this is about', 'daw' ),
						'type'       => 'post',
						'post_type'  => 'post',
						'field_type' => 'select_advanced',
						'columns'    => 6,
						'desc'       => __( 'The picture and the link come from here, so a new portrait on the record is a new portrait in the quiz.', 'daw' ),
					),
					array(
						'id'      => 'daw_q_image',
						'name'    => __( 'Picture, only when the record has none', 'daw' ),
						'type'    => 'image_advanced',
						'columns' => 6,
						'max_file_uploads' => 1,
					),
					array(
						'id'      => 'daw_q_options',
						'name'    => __( 'Answers', 'daw' ),
						'type'    => 'textarea',
						'rows'    => 3,
						'columns' => 6,
						'desc'    => __( 'One per line. Multiple choice only.', 'daw' ),
					),
					array(
						'id'      => 'daw_q_answer',
						'name'    => __( 'The right answer', 'daw' ),
						'type'    => 'text',
						'columns' => 6,
						'desc'    => __( 'The answer exactly as it is written above, or the position for a ranking quiz.', 'daw' ),
					),
					// THE FIGURE, ON ITS OWN AND SHORT
					// --------------------------------
					// The note explains. This is the number that settles it,
					// kept apart so the results list can print a clean column
					// of figures rather than a column of sentences.
					array(
						'id'   => 'daw_q_fact',
						'name' => __( 'The figure', 'daw' ),
						'type' => 'text',
						'desc' => __( 'Short, for example 1,739,000 a month. Printed beside the name in the results list.', 'daw' ),
					),
					array(
						'id'   => 'daw_q_note',
						'name' => __( 'What to say once they have answered', 'daw' ),
						'type' => 'textarea',
						'rows' => 2,
					),
				),
			),
		),
	);

	return $boxes;
}

/* ================================================================ read === */

/** The quiz on a record, as rows a template can print, or an empty list. */
function daw_quiz_rows( $post_id ) {
	$rows = get_post_meta( (int) $post_id, 'daw_q', true );

	if ( ! is_array( $rows ) ) {
		return array();
	}

	$out = array();

	foreach ( $rows as $row ) {
		$text   = trim( (string) ( $row['daw_q_text'] ?? '' ) );
		$record = (int) ( $row['daw_q_record'] ?? 0 );

		if ( '' === $text && ! $record ) {
			continue;
		}

		if ( '' === $text && $record ) {
			$text = get_the_title( $record );
		}

		$image = '';

		if ( $record && has_post_thumbnail( $record ) ) {
			$image = (string) get_the_post_thumbnail_url( $record, 'medium' );
		}

		if ( '' === $image ) {
			$ids = (array) ( $row['daw_q_image'] ?? array() );
			$one = $ids ? (int) reset( $ids ) : 0;

			if ( $one ) {
				$image = (string) wp_get_attachment_image_url( $one, 'medium' );
			}
		}

		// A linked record with no upload of its own still has the picture its
		// page draws from TMDB (tmdb-remote.php). Kim Soo Hyun showed an empty
		// circle in the actors quiz because only uploads were asked (owner,
		// 29 Oct 2026).
		if ( '' === $image && $record && function_exists( 'daw_remote_mode_for' ) && 'remote' === daw_remote_mode_for( $record ) ) {
			$path  = function_exists( 'daw_tmdb_img_path' ) ? daw_tmdb_img_path( $record ) : '';
			$image = '' !== $path ? 'https://image.tmdb.org/t/p/w185' . $path : '';
		}

		$options = array_values(
			array_filter(
				array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) ( $row['daw_q_options'] ?? '' ) ) )
			)
		);

		$out[] = array(
			'text'    => $text,
			'image'   => $image,
			'url'     => $record ? (string) get_permalink( $record ) : '',
			'options' => $options,
			'answer'  => trim( (string) ( $row['daw_q_answer'] ?? '' ) ),
			'note'    => trim( (string) ( $row['daw_q_note'] ?? '' ) ),
			'fact'    => trim( (string) ( $row['daw_q_fact'] ?? '' ) ),
		);
	}

	return $out;
}

/** Whether a record is a quiz with something to play. */
function daw_is_quiz( $post_id ) {
	return function_exists( 'daw_get_type' )
		&& 'quiz' === daw_get_type( $post_id )
		&& (bool) daw_quiz_rows( $post_id );
}
