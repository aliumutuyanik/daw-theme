<?php
/**
 * Discover Asian World — fill the empty fields from the pages' own old bodies.
 *
 * THE PROBLEM THIS SOLVES
 * -----------------------
 * The People page says South Korea has 245 profiles. The "What they do" filter
 * says two of them are actors. Both numbers are correct: the country comes from
 * a taxonomy that was filled during the import, and the occupation comes from a
 * field that never was. Everything a reader might filter by is sitting in the
 * page builder payload that each record still carries, written as "Occupation:
 * Actor" inside an accordion, where no query can reach it.
 *
 * The extractor in extract.php already knows how to read those panels. What it
 * did not have was a way to run over the whole archive without somebody sitting
 * in wp-admin clicking through it, and a guarantee that it would not overwrite
 * work done since. This file is both.
 *
 * IT ONLY FILLS WHAT IS EMPTY
 * ---------------------------
 * A field that already holds something was either curated by hand or written
 * from a better source than a four year old page body, so it wins. The record's
 * current values are read before the extractor runs and written back after,
 * which leaves exactly the empty ones filled. That is a wrapper rather than a
 * change inside the extractor, so the extractor keeps behaving the way the
 * Tools screen expects when somebody runs it there deliberately.
 *
 * IT RUNS IN SLICES
 * -----------------
 * Nine hundred records in one request is a timeout. The queue is built once and
 * worked through a few records at a time, on cron and on any admin page load,
 * with a lock so two slices never run at once. A crash loses one slice, not the
 * run, because the queue is stored rather than recomputed.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_BACKFILL_SLICE  = 25;
const DAW_BACKFILL_BUDGET = 12; // seconds

/**
 * Every field the extractor is allowed to write, and therefore every field this
 * has to protect if it already holds something.
 */
function daw_backfill_fields() {
	return array(
		// Person.
		'daw_native_name', 'daw_nationality', 'daw_gender', 'daw_born', 'daw_birth_place',
		'daw_zodiac', 'daw_height_cm', 'daw_spouse', 'daw_children', 'daw_agency',
		'daw_occupation', 'daw_member_of',
		'daw_info_blob', 'daw_biography', 'daw_hobbies', 'daw_interests', 'daw_facts',
		'daw_secrets', 'daw_funny_things', 'daw_fears', 'daw_person_news',
		// Title.
		'daw_native_title', 'daw_also_known_as', 'daw_screenwriter', 'daw_director',
		'daw_episodes', 'daw_aired_start', 'daw_aired_end', 'daw_airing_days',
		'daw_duration_min', 'daw_content_rating', 'daw_details_blob', 'daw_best_scenes',
		'daw_filming_location', 'daw_visual_design', 'daw_themes_messages',
		'daw_interesting_facts', 'daw_awards', 'daw_real_life_loves',
		'daw_synopsis', 'daw_trailer_youtube', 'daw_cast_person',
	);
}

/** One record, filled but never overwritten. */
function daw_backfill_one( $post_id ) {
	$post_id = (int) $post_id;

	if ( ! function_exists( 'daw_extract_one' ) ) {
		return array( 'fields' => 0 );
	}

	$keep = array();

	foreach ( daw_backfill_fields() as $key ) {
		$value = get_post_meta( $post_id, $key, true );

		if ( '' !== $value && array() !== $value && null !== $value ) {
			$keep[ $key ] = $value;
		}
	}

	$keep_terms = array();

	foreach ( array( 'dw_genre', 'dw_network' ) as $taxonomy ) {
		$slugs = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );

		if ( ! is_wp_error( $slugs ) && $slugs ) {
			$keep_terms[ $taxonomy ] = $slugs;
		}
	}

	$report = daw_extract_one( $post_id, true );

	// Put back everything that was already there. What the extractor wrote into
	// a field that was empty stays; what it wrote over does not.
	foreach ( $keep as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	foreach ( $keep_terms as $taxonomy => $slugs ) {
		wp_set_object_terms( $post_id, $slugs, $taxonomy, false );
	}

	// The job, which no imported panel carries, read from the record's own
	// opening sentence. Only ever written into an empty field, and marked so it
	// can be found again and corrected in bulk.
	if ( function_exists( 'daw_get_type' ) && 'person' === daw_get_type( $post_id )
		&& '' === trim( (string) get_post_meta( $post_id, 'daw_occupation', true ) ) ) {
		$job = daw_derive_occupation( $post_id );

		if ( '' !== $job ) {
			update_post_meta( $post_id, 'daw_occupation', $job );
			update_post_meta( $post_id, 'daw_occupation_src', 'derived' );
			$report['fields'] = (int) ( $report['fields'] ?? 0 ) + 1;
		}
	}

	// Gender, read the same way and for the same reason: a hundred and six of
	// the two hundred and forty five Korean profiles had none, which made
	// "every Korean actress" a list of about half of them.
	if ( function_exists( 'daw_get_type' ) && 'person' === daw_get_type( $post_id )
		&& '' === trim( (string) get_post_meta( $post_id, 'daw_gender', true ) ) ) {
		$gender = daw_derive_gender( $post_id );

		if ( '' !== $gender ) {
			update_post_meta( $post_id, 'daw_gender', $gender );
			update_post_meta( $post_id, 'daw_gender_src', 'derived' );
			$report['fields'] = (int) ( $report['fields'] ?? 0 ) + 1;
		}
	}

	return $report;
}

/* ========================================================== occupation === */

/**
 * What a person does, worked out from the record's own prose.
 *
 * WHY THIS IS NEEDED AT ALL
 * -------------------------
 * The imported profiles carry an Info panel with nationality, birthday, height
 * and a dozen other fields, and not one of them says what the person does. So
 * the People page could filter by height and not by job, which is the wrong way
 * round: nobody has ever looked for a 178cm person, and everybody looks for
 * actors.
 *
 * The job is in the record, it is just in the biography rather than in a field:
 * "Cha Eun Woo, born Lee Dong-min on March 30 1997, is a prominent South Korean
 * actor and singer." That sentence is the site's own writing about its own
 * subject, so reading it is not a guess.
 *
 * WHERE IT STOPS
 * --------------
 * Only the opening of the biography is read, because that is where a profile
 * states the job and everywhere else is where it mentions other people's. A
 * record whose prose names nothing recognisable gets one fallback: if the
 * archive itself credits them in a cast, they acted in something, which is a
 * fact this site holds rather than one it inferred. Anything else is left
 * empty, because an empty field is honest and a guessed one is not.
 */
function daw_occupation_patterns() {
	// Order matters only for readability; the winner is whichever matches
	// earliest in the sentence, so "voice actor" beats the "actor" inside it.
	return array(
		'voice'    => '/\b(?:voice actor|voice actress|seiyuu)\b/i',
		'actor'    => '/\b(?:actor|actress)\b/i',
		'idol'     => '/\b(?:idol)\b/i',
		'singer'   => '/\b(?:singer|vocalist|rapper|singer-songwriter|songwriter)\b/i',
		'model'    => '/\b(?:model|modelling|modeling)\b/i',
		'host'     => '/\b(?:host|presenter|emcee|television personality|tv personality)\b/i',
		'comedian' => '/\b(?:comedian|comedienne)\b/i',
		'dancer'   => '/\b(?:dancer|choreographer)\b/i',
		'director' => '/\b(?:director|filmmaker)\b/i',
		'writer'   => '/\b(?:screenwriter|novelist|author|writer)\b/i',
		'producer' => '/\b(?:producer)\b/i',
	);
}

/** The word each role is written with inside a sentence. */
function daw_occupation_words() {
	return array(
		'voice'    => __( 'voice actor', 'daw' ),
		'actor'    => __( 'actor', 'daw' ),
		'idol'     => __( 'idol', 'daw' ),
		'singer'   => __( 'singer', 'daw' ),
		'model'    => __( 'model', 'daw' ),
		'host'     => __( 'host', 'daw' ),
		'comedian' => __( 'comedian', 'daw' ),
		'dancer'   => __( 'dancer', 'daw' ),
		'director' => __( 'director', 'daw' ),
		'writer'   => __( 'writer', 'daw' ),
		'producer' => __( 'producer', 'daw' ),
	);
}

/**
 * The opening of whatever prose the record holds about this person.
 *
 * Four hundred characters, which is the first sentence and usually a bit of the
 * second. Past that a profile is talking about roles, co-stars and awards, and
 * every one of those sentences contains words that look like job titles.
 */
function daw_person_opening( $post_id ) {
	foreach ( array( 'daw_person_summary', 'daw_biography' ) as $key ) {
		$text = trim( wp_strip_all_tags( (string) get_post_meta( $post_id, $key, true ) ) );

		if ( '' !== $text ) {
			return substr( $text, 0, 400 );
		}
	}

	return '';
}

/** The occupation line, or '' when the record does not say. */
function daw_derive_occupation( $post_id ) {
	$text  = daw_person_opening( $post_id );
	$found = array();

	if ( '' !== $text ) {
		foreach ( daw_occupation_patterns() as $role => $pattern ) {
			if ( preg_match( $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) {
				$found[ $role ] = (int) $m[0][1];
			}
		}
	}

	// "Voice actor" contains "actor", so both match and both are true of the
	// same four words. The narrower one is the job.
	if ( isset( $found['voice'], $found['actor'] ) && abs( $found['actor'] - $found['voice'] ) <= 8 ) {
		unset( $found['actor'] );
	}

	asort( $found );
	$roles = array_slice( array_keys( $found ), 0, 3 );

	if ( ! $roles && function_exists( 'daw_credit_count' ) && daw_credit_count( $post_id ) > 0 ) {
		$roles = array( 'actor' );
	}

	if ( ! $roles ) {
		return '';
	}

	$words = daw_occupation_words();
	$names = array();

	foreach ( $roles as $role ) {
		$names[] = $words[ $role ];
	}

	$last  = array_pop( $names );
	$line  = $names ? implode( ', ', $names ) . ' ' . __( 'and', 'daw' ) . ' ' . $last : $last;

	return ucfirst( $line );
}

/* =============================================================== gender === */

/**
 * Whether a profile is about a woman or a man, read from its own prose.
 *
 * WHY IT HAS TO BE DERIVED
 * ------------------------
 * A hundred and six of the two hundred and forty five Korean profiles carry no
 * gender at all, because the imported Info panel never had the field. The
 * archive is not missing the fact, it is only missing it in a place a query can
 * reach: every one of those biographies says "she" or "he" in its first line.
 *
 * WHERE IT STOPS
 * --------------
 * A biography mentions co-stars, directors and spouses, so a single pronoun is
 * not an answer. This needs four pronouns and a clear majority of them, and it
 * reads only the opening of the text, where a profile is still talking about
 * its subject. Anything short of that is left empty, and every value written is
 * marked derived so the whole set can be reviewed or undone in one query.
 */
function daw_derive_gender( $post_id ) {
	$text = '';

	foreach ( array( 'daw_person_summary', 'daw_biography' ) as $key ) {
		$text .= ' ' . wp_strip_all_tags( (string) get_post_meta( $post_id, $key, true ) );
	}

	$text = strtolower( substr( trim( $text ), 0, 1200 ) );

	if ( '' === $text ) {
		return '';
	}

	// "Actress" on its own is a trap: a male actor's biography names his
	// co-stars, and half of them are actresses. It only counts when the
	// sentence is the profile introducing its own subject.
	if ( preg_match( '/\bis (?:a|an)\b[^.]{0,80}\bactress\b/', substr( $text, 0, 400 ) ) ) {
		return 'female';
	}

	preg_match_all( '/\b(she|her|hers|herself)\b/', $text, $f );
	preg_match_all( '/\b(he|him|his|himself)\b/', $text, $m );

	$female = count( $f[0] );
	$male   = count( $m[0] );
	$total  = $female + $male;

	if ( $total < 4 ) {
		return '';
	}

	if ( $female >= $total * 0.75 ) {
		return 'female';
	}

	if ( $male >= $total * 0.75 ) {
		return 'male';
	}

	return '';
}

/* ================================================================ queue === */

/** Build the list of records to work through, newest first. */
function daw_backfill_build_queue() {
	$ids = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			// EVERY RECORD, NOT ONLY THE ONES WITH A PAGE BUILDER BODY
			// -------------------------------------------------------
			// The extractor needs an Elementor payload and does nothing
			// without one, which is free. The occupation is read from the
			// biography, which plenty of records have without ever having been
			// built in Elementor, so narrowing the queue to that payload would
			// have skipped exactly the profiles this is for.
		)
	);

	update_option( 'daw_backfill_queue', array_map( 'intval', $ids ), false );
	update_option( 'daw_backfill_total', count( $ids ), false );
	update_option(
		'daw_backfill_stats',
		array( 'done' => 0, 'fields' => 0, 'started' => time() ),
		false
	);

	return $ids;
}

/** Work through a slice of the queue. Returns how many records it handled. */
function daw_backfill_run_slice() {
	$queue = get_option( 'daw_backfill_queue' );

	if ( ! is_array( $queue ) || ! $queue ) {
		return 0;
	}

	if ( get_transient( 'daw_backfill_lock' ) ) {
		return 0;
	}

	set_transient( 'daw_backfill_lock', 1, 120 );

	$stats = get_option( 'daw_backfill_stats', array( 'done' => 0, 'fields' => 0 ) );
	$start = microtime( true );
	$n     = 0;

	while ( $queue && $n < DAW_BACKFILL_SLICE && ( microtime( true ) - $start ) < DAW_BACKFILL_BUDGET ) {
		$id     = array_shift( $queue );
		$report = daw_backfill_one( $id );

		$stats['fields'] = (int) ( $stats['fields'] ?? 0 ) + (int) ( $report['fields'] ?? 0 );
		++$stats['done'];
		++$n;
	}

	update_option( 'daw_backfill_queue', array_values( $queue ), false );
	update_option( 'daw_backfill_stats', $stats, false );

	if ( ! $queue ) {
		update_option( 'daw_backfill_finished', time(), false );

		if ( function_exists( 'daw_browse_bump_gen' ) ) {
			daw_browse_bump_gen();
		}
	}

	delete_transient( 'daw_backfill_lock' );

	return $n;
}

/* ================================================================= runs === */

// A slice on every admin page load, and a slice a minute on cron, so the run
// finishes whether or not anybody is sitting at the screen.
add_action( 'admin_init', 'daw_backfill_tick', 30 );
add_action( 'daw_backfill_cron', 'daw_backfill_tick' );

function daw_backfill_tick() {
	if ( function_exists( 'daw_is_delicate_request' ) && daw_is_delicate_request() ) {
		return;
	}


	daw_backfill_run_slice();

	$queue = get_option( 'daw_backfill_queue' );

	if ( is_array( $queue ) && $queue && ! wp_next_scheduled( 'daw_backfill_cron' ) ) {
		wp_schedule_single_event( time() + 60, 'daw_backfill_cron' );
	}
}

/* ================================================================ start === */

/**
 * Start the run once, on the version that introduces it.
 *
 * The stamp is a version rather than a flag, so shipping a better extractor
 * later is a one line change here and the archive is walked again against it.
 */
add_action( 'init', 'daw_backfill_maybe_start', 26 );
function daw_backfill_maybe_start() {
	$run = '2026-09-18-d';

	if ( get_option( 'daw_backfill_run' ) === $run ) {
		return;
	}

	update_option( 'daw_backfill_run', $run, false );
	delete_option( 'daw_backfill_finished' );
	daw_backfill_build_queue();
}

/* ============================================================== notice === */

add_action( 'admin_notices', 'daw_backfill_notice' );
function daw_backfill_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$queue = get_option( 'daw_backfill_queue' );
	$total = (int) get_option( 'daw_backfill_total' );
	$stats = get_option( 'daw_backfill_stats', array() );

	if ( ! is_array( $queue ) || ! $total ) {
		return;
	}

	$left = count( $queue );

	if ( $left ) {
		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'DAW is reading the old page bodies into the record fields.', 'daw' ),
			esc_html(
				sprintf(
					/* translators: 1: records done, 2: records in total, 3: fields written */
					__( '%1$s of %2$s records done, %3$s fields filled so far. It carries on by itself; leaving this screen open moves it along faster.', 'daw' ),
					number_format_i18n( $total - $left ),
					number_format_i18n( $total ),
					number_format_i18n( (int) ( $stats['fields'] ?? 0 ) )
				)
			)
		);

		return;
	}

	if ( get_option( 'daw_backfill_finished' ) ) {
		delete_option( 'daw_backfill_finished' );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: records, 2: fields */
					__( 'DAW finished reading %1$s records and filled %2$s empty fields. Nothing that already held a value was touched.', 'daw' ),
					number_format_i18n( $total ),
					number_format_i18n( (int) ( $stats['fields'] ?? 0 ) )
				)
			)
		);
	}
}
