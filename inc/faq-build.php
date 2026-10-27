<?php
/**
 * Frequently asked questions, built from what the record already says.
 *
 * WHY A GENERATOR IS SAFE HERE AND NOWHERE ELSE
 * --------------------------------------------
 * Most of this site refuses to generate prose, because generated prose makes
 * claims nobody checked. These questions make no new claim at all. Every answer
 * is a sentence built around a value already stored on the record and already
 * printed elsewhere on the page: the episode count, the dates, the platforms
 * the title is listed on, the billed cast, the credits. If a field is empty the
 * question is not asked, so the generator can only ever say things the record
 * can back up.
 *
 * WHY IT IS WORTH DOING AT ALL
 * ----------------------------
 * Two reasons, and neither is padding. A reader who lands from a search for
 * "how many episodes" gets the answer without reading a synopsis. And the
 * template pairs the questions with the related news in the rail beside them,
 * so a record with no questions loses that layout and prints its one news story
 * across the page instead.
 *
 * WHAT IT WILL NOT DO
 * -------------------
 * It never touches a record that already has questions on it. Those were
 * written by a person and they are better than anything derived from a field.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** "A", "A and B", "A, B and C" */
function daw_faq_join( array $items ) {
	$items = array_values( array_filter( array_map( 'trim', $items ) ) );
	$last  = array_pop( $items );

	if ( ! $items ) {
		return (string) $last;
	}

	return implode( ', ', $items ) . __( ' and ', 'daw' ) . $last;
}

/**
 * The questions this record can answer out of its own fields.
 *
 * @param int $post_id Record.
 * @return array list of [ 'q' => string, 'a' => string ]
 */
function daw_faq_derive( $post_id ) {
	$post_id = (int) $post_id;
	$type    = daw_get_type( $post_id );

	if ( ! in_array( $type, array( 'drama', 'film' ), true ) ) {
		return array();
	}

	$name  = get_the_title( $post_id );
	$rows  = array();
	$eps   = (int) daw_field_raw( $post_id, 'daw_episodes' );
	$mins  = (int) daw_field_raw( $post_id, 'daw_duration_min' );
	$start = (int) daw_field_raw( $post_id, 'daw_aired_start' );
	$end   = (int) daw_field_raw( $post_id, 'daw_aired_end' );

	$network = '';
	$terms   = get_the_terms( $post_id, 'dw_network' );
	if ( $terms && ! is_wp_error( $terms ) ) {
		$network = $terms[0]->name;
	}

	/* ---------------------------------------------------------- episodes -- */
	if ( 'drama' === $type && $eps ) {
		$answer = sprintf(
			/* translators: 1: title, 2: episode count */
			__( '%1$s has %2$d episodes.', 'daw' ),
			$name,
			$eps
		);

		if ( $mins ) {
			$answer = sprintf(
				/* translators: 1: title, 2: episode count, 3: minutes */
				__( '%1$s has %2$d episodes, each about %3$d minutes long.', 'daw' ),
				$name,
				$eps,
				$mins
			);
		}

		$rows[] = array(
			/* translators: %s: title */
			'q' => sprintf( __( 'How many episodes does %s have?', 'daw' ), $name ),
			'a' => $answer,
		);
	}

	/* ------------------------------------------------------------- dates -- */
	if ( $start ) {
		$from = date_i18n( 'j F Y', $start );

		if ( 'film' === $type ) {
			$rows[] = array(
				/* translators: %s: title */
				'q' => sprintf( __( 'When was %s released?', 'daw' ), $name ),
				/* translators: 1: title, 2: date */
				'a' => sprintf( __( '%1$s was released on %2$s.', 'daw' ), $name, $from ),
			);
		} else {
			$where = $network
				/* translators: %s: broadcaster */
				? sprintf( __( ' on %s', 'daw' ), $network )
				: '';

			$answer = $end
				? sprintf(
					/* translators: 1: title, 2: broadcaster clause, 3: first date, 4: last date */
					__( '%1$s aired%2$s from %3$s to %4$s.', 'daw' ),
					$name,
					$where,
					$from,
					date_i18n( 'j F Y', $end )
				)
				: sprintf(
					/* translators: 1: title, 2: broadcaster clause, 3: first date */
					__( '%1$s began airing%2$s on %3$s.', 'daw' ),
					$name,
					$where,
					$from
				);

			$rows[] = array(
				/* translators: %s: title */
				'q' => sprintf( __( 'When did %s air?', 'daw' ), $name ),
				'a' => $answer,
			);
		}
	}

	/* ------------------------------------------------------------- cast --- */
	$main = function_exists( 'daw_get_cast' ) ? daw_get_cast( $post_id, 'main' ) : array();
	if ( $main ) {
		$played = array();
		$plain  = array();

		foreach ( array_slice( $main, 0, 4 ) as $row ) {
			$who = get_the_title( (int) $row['id'] );

			if ( '' === trim( (string) $who ) ) {
				continue;
			}

			$plain[] = $who;

			$played[] = trim( (string) $row['role'] )
				/* translators: 1: actor, 2: character */
				? sprintf( __( '%1$s as %2$s', 'daw' ), $who, $row['role'] )
				: $who;
		}

		if ( $plain ) {
			$rows[] = array(
				/* translators: %s: title */
				'q' => sprintf( __( 'Who stars in %s?', 'daw' ), $name ),
				/* translators: 1: title, 2: list of actors */
				'a' => sprintf( __( 'The main cast is %2$s.', 'daw' ), $name, daw_faq_join( $played ) ),
			);
		}
	}

	/* ---------------------------------------------------------- credits --- */
	$director = array_filter( (array) daw_field_raw( $post_id, 'daw_director' ) );
	$writer   = array_filter( (array) daw_field_raw( $post_id, 'daw_screenwriter' ) );

	if ( $director || $writer ) {
		if ( $director && $writer ) {
			$answer = sprintf(
				/* translators: 1: title, 2: director(s), 3: writer(s) */
				__( '%1$s was directed by %2$s and written by %3$s.', 'daw' ),
				$name,
				daw_faq_join( $director ),
				daw_faq_join( $writer )
			);
			/* translators: %s: title */
			$question = sprintf( __( 'Who wrote and directed %s?', 'daw' ), $name );
		} elseif ( $director ) {
			$answer = sprintf(
				/* translators: 1: title, 2: director(s) */
				__( '%1$s was directed by %2$s.', 'daw' ),
				$name,
				daw_faq_join( $director )
			);
			/* translators: %s: title */
			$question = sprintf( __( 'Who directed %s?', 'daw' ), $name );
		} else {
			$answer = sprintf(
				/* translators: 1: title, 2: writer(s) */
				__( '%1$s was written by %2$s.', 'daw' ),
				$name,
				daw_faq_join( $writer )
			);
			/* translators: %s: title */
			$question = sprintf( __( 'Who wrote %s?', 'daw' ), $name );
		}

		$rows[] = array(
			'q' => $question,
			'a' => $answer,
		);
	}

	/* ------------------------------------------------------ where to watch */
	$watch = function_exists( 'daw_get_watch_links' ) ? daw_get_watch_links( $post_id ) : array();
	if ( $watch ) {
		$names = array();
		foreach ( $watch as $w ) {
			$label = function_exists( 'daw_platform_label' )
				? daw_platform_label( $w['url'], $w['platform'] )
				: $w['platform'];

			if ( '' !== trim( (string) $label ) ) {
				$names[] = $label;
			}
		}

		if ( $names ) {
			$rows[] = array(
				/* translators: %s: title */
				'q' => sprintf( __( 'Where can I watch %s?', 'daw' ), $name ),
				'a' => sprintf(
					/* translators: 1: title, 2: list of platforms */
					__( '%1$s is listed on %2$s. Availability varies by country.', 'daw' ),
					$name,
					daw_faq_join( $names )
				),
			);
		}
	} elseif ( function_exists( 'daw_wp_names' ) && daw_wp_names( $post_id ) ) {
		// No official row typed in: the United States list from TMDB, dated
		// and credited, because it is a snapshot of one country.
		$rows[] = array(
			/* translators: %s: title */
			'q' => sprintf( __( 'Where can I watch %s?', 'daw' ), $name ),
			'a' => sprintf(
				/* translators: 1: title, 2: list of platforms, 3: month and year */
				__( 'In the United States, %1$s streams on %2$s, according to JustWatch data checked in %3$s. Availability differs by country and changes often; the where-to-watch box on this page lists other countries.', 'daw' ),
				$name,
				daw_faq_join( daw_wp_names( $post_id ) ),
				gmdate( 'F Y', (int) ( daw_wp_get( $post_id )['at'] ?? time() ) )
			),
		);
	}

	/* ------------------------------------------------------------ rating -- */
	$rating = trim( (string) daw_field_raw( $post_id, 'daw_content_rating' ) );
	if ( '' !== $rating ) {
		$rows[] = array(
			/* translators: %s: title */
			'q' => sprintf( __( 'What age rating does %s carry?', 'daw' ), $name ),
			/* translators: 1: title, 2: rating */
			'a' => sprintf( __( '%1$s carries a %2$s rating.', 'daw' ), $name, $rating ),
		);
	}

	return $rows;
}

/**
 * Write the derived questions, if this record has none of its own.
 *
 * @return array{written:int,reason:string}
 */
function daw_faq_build( $post_id ) {
	$post_id = (int) $post_id;

	$existing = (array) daw_field_raw( $post_id, 'daw_faq_q' );
	$existing = array_filter( array_map( 'trim', array_map( 'strval', $existing ) ) );

	if ( $existing ) {
		return array(
			'written' => 0,
			'reason'  => __( 'Already has questions written by hand.', 'daw' ),
		);
	}

	$rows = daw_faq_derive( $post_id );

	// Two questions is not a frequently asked questions section, it is a stub.
	if ( count( $rows ) < 3 ) {
		update_post_meta( $post_id, '_daw_faq_built', time() );

		return array(
			'written' => 0,
			'reason'  => sprintf(
				/* translators: %d: number of questions the record could answer. */
				__( 'Only %d question(s) could be answered from the fields on this record.', 'daw' ),
				count( $rows )
			),
		);
	}

	update_post_meta( $post_id, 'daw_faq_q', wp_list_pluck( $rows, 'q' ) );
	update_post_meta( $post_id, 'daw_faq_a', wp_list_pluck( $rows, 'a' ) );
	update_post_meta( $post_id, '_daw_faq_built', time() );

	return array(
		'written' => count( $rows ),
		'reason'  => '',
	);
}

/* ================================================================== admin = */

/** Title records with no questions on them, never tried before. */
function daw_faq_queue( $again = false ) {
	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => array( 'drama', 'film' ),
				),
			),
		)
	);

	$queue = array();

	foreach ( $ids as $id ) {
		$has = array_filter( array_map( 'trim', array_map( 'strval', (array) daw_field_raw( $id, 'daw_faq_q' ) ) ) );

		if ( $has ) {
			continue;
		}

		if ( ! $again && get_post_meta( $id, '_daw_faq_built', true ) ) {
			continue;
		}

		$queue[] = $id;
	}

	return $queue;
}

add_action( 'admin_menu', 'daw_faq_admin_menu' );
function daw_faq_admin_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: Title FAQs', 'daw' ),
		__( 'DAW: Title FAQs', 'daw' ),
		'manage_options',
		'daw-faq',
		'daw_faq_admin_page'
	);
}

function daw_faq_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="wrap"><h1>DAW: Title FAQs</h1>';
	echo '<p>Questions built from the fields already on the record: episode count, dates, billed cast, credits, platforms, age rating. Nothing is invented, an empty field simply asks no question, and a record that already has questions is left alone. Records that can answer fewer than three are skipped.</p>';

	$again = isset( $_POST['daw_faq_again'] );

	if ( isset( $_POST['daw_faq_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['daw_faq_nonce'] ), 'daw_faq' ) ) {
		$batch  = max( 1, min( 100, (int) ( $_POST['daw_faq_batch'] ?? 25 ) ) );
		$report = array();
		$filled = 0;

		foreach ( array_slice( daw_faq_queue( $again ), 0, $batch ) as $pid ) {
			$res            = daw_faq_build( $pid );
			$report[ $pid ] = $res;
			$filled        += $res['written'] ? 1 : 0;
		}

		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			$filled ? 'success' : 'warning',
			esc_html(
				sprintf(
					/* translators: 1: records written, 2: records looked at */
					__( 'Wrote questions on %1$d of the %2$d record(s) looked at.', 'daw' ),
					$filled,
					count( $report )
				)
			)
		);

		echo '<table class="widefat striped"><thead><tr><th>Record</th><th>Questions</th><th>Note</th></tr></thead><tbody>';
		foreach ( $report as $pid => $res ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td></tr>',
				esc_url( (string) get_permalink( $pid ) ),
				esc_html( get_the_title( $pid ) ),
				$res['written'] ? (int) $res['written'] : '&mdash;',
				esc_html( $res['reason'] )
			);
		}
		echo '</tbody></table>';
	}

	$pending = daw_faq_queue( $again );

	echo '<form method="post">';
	wp_nonce_field( 'daw_faq', 'daw_faq_nonce' );
	echo '<p><label>Do this many now: <input type="number" name="daw_faq_batch" value="25" min="1" max="100" style="width:5em"></label></p>';
	printf(
		'<p><label><input type="checkbox" name="daw_faq_again" value="1" %s> %s</label></p>',
		checked( $again, true, false ),
		esc_html__( 'Try records that were skipped before as well', 'daw' )
	);
	submit_button( __( 'Build questions', 'daw' ) );
	echo '</form>';

	printf( '<p><strong>%d</strong> record(s) with no questions waiting.</p>', count( $pending ) );

	echo '</div>';
}
