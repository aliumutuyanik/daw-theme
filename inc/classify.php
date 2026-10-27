<?php
/**
 * Discover Asian World — one-time record classifier
 *
 * Reads each record's existing categories and assigns the right dw_type term,
 * plus dw_country where the category says so. Tools → DAW: Classify records.
 *
 * WHAT THIS TOUCHES
 * -----------------
 * wp_set_object_terms() on dw_type and dw_country. Nothing else. It does not
 * write post_content, does not change post_type, does not touch slugs, and
 * does not add or remove a single category. No url can change as a result.
 *
 * It is idempotent and resumable: records that already carry a dw_type are
 * skipped, so you can run it, add content, and run it again safely.
 *
 * PRECEDENCE MATTERS
 * ------------------
 * The audit found records double-tagged — 167 in "Movie" and 162 in "K-Movie"
 * are largely the same records, and actor records often also carry a Movie
 * category. So the map below is ordered, first match wins, and person sits
 * above film deliberately: a profile that mentions a film is still a person.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_CLASSIFY_BATCH = 150;

/**
 * Ordered category-slug → dw_type map. First match wins.
 */
function daw_classify_map() {
	return array(
		'person'  => array( 'k-actors', 'k-actress' ),
		'group'   => array( 'k-pop' ),
		'drama'   => array( 'k-drama', 'c-drama', 'j-drama' ),
		'film'    => array( 'k-movie', 'c-movie', 'j-movie', 't-movie', 'movie' ),
		'news'    => array( 'trends' ),
		'food'    => array( 'k-food', 'j-food', 't-food' ),
		'culture' => array( 'k-culture', 't-culture' ),
		'myth'    => array( 'k-myths' ),
		'travel'  => array( 'k-travel' ),
		// Quizzes are not a Korean thing — they can be about anything in Asia,
		// so the type is generic and the map accepts the per-country category
		// names that already exist plus the ones likely to be added later.
		'quiz'    => array( 'k-test', 'j-test', 'c-test', 't-test', 'test', 'quiz', 'quizzes' ),
	);
}

/**
 * Category slug → country name. Korea covers 897 of 926 records.
 */
function daw_country_map() {
	return array(
		'south-korea' => 'South Korea',
		'china'       => 'China',
		'japan'       => 'Japan',
	);
}

/**
 * Decide a record's type from its category slugs. '' when nothing matches.
 */
function daw_classify_one( $cat_slugs ) {
	foreach ( daw_classify_map() as $type => $slugs ) {
		if ( array_intersect( $cat_slugs, $slugs ) ) {
			return $type;
		}
	}
	return '';
}

/* ------------------------------------------------------------- admin page - */

add_action( 'admin_menu', 'daw_classify_menu' );
function daw_classify_menu() {
	add_management_page(
		__( 'DAW: Classify records', 'daw' ),
		__( 'DAW: Classify records', 'daw' ),
		'manage_options',
		'daw-classify',
		'daw_classify_page'
	);
}

function daw_classify_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'daw' ) );
	}

	$running = isset( $_GET['run'] ) && check_admin_referer( 'daw_classify' );

	echo '<div class="wrap"><h1>' . esc_html__( 'Classify records', 'daw' ) . '</h1>';

	if ( $running ) {
		daw_classify_run_batch();
		echo '</div>';
		return;
	}

	daw_classify_preview();

	$url = wp_nonce_url( admin_url( 'tools.php?page=daw-classify&run=1' ), 'daw_classify' );
	printf(
		'<p style="margin-top:24px"><a href="%s" class="button button-primary button-hero">%s</a></p>',
		esc_url( $url ),
		esc_html__( 'Run the classifier', 'daw' )
	);
	echo '<p class="description">' . esc_html__( 'Runs in batches and continues on its own. Only dw_type and dw_country are written, so no url, slug, category or content is touched. Safe to re-run.', 'daw' ) . '</p>';
	echo '</div>';
}

/**
 * Dry run. Counts what WOULD be assigned, writes nothing.
 */
function daw_classify_preview() {
	$ids = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );

	$tally       = array_fill_keys( array_keys( daw_classify_map() ), 0 );
	$unmatched   = array();
	$already     = 0;

	foreach ( $ids as $id ) {
		// "unclassified" is a parking space, not an answer. When the map grows
		// — as it did when quizzes got their own type — those records come back
		// into scope instead of staying stuck on the first verdict.
		$existing = daw_get_type( $id );
		if ( $existing && 'unclassified' !== $existing ) {
			$already++;
			continue;
		}

		$slugs = wp_get_post_terms( $id, 'category', array( 'fields' => 'slugs' ) );
		$type  = daw_classify_one( is_wp_error( $slugs ) ? array() : $slugs );

		if ( $type ) {
			$tally[ $type ]++;
		} elseif ( count( $unmatched ) < 25 ) {
			$unmatched[ $id ] = get_the_title( $id );
		}
	}

	$total_unmatched = count( $ids ) - $already - array_sum( $tally );

	echo '<h2>' . esc_html__( 'Preview', 'daw' ) . '</h2>';
	echo '<table class="widefat striped" style="max-width:520px"><thead><tr><th>' . esc_html__( 'Record type', 'daw' ) . '</th><th style="text-align:right">' . esc_html__( 'Records', 'daw' ) . '</th></tr></thead><tbody>';

	foreach ( $tally as $type => $n ) {
		$label = daw_record_types()[ $type ] ?? $type;
		printf( '<tr><td>%s</td><td style="text-align:right"><strong>%d</strong></td></tr>', esc_html( $label ), (int) $n );
	}

	printf(
		'<tr><td><em>%s</em></td><td style="text-align:right"><strong>%d</strong></td></tr>',
		esc_html__( 'No match, needs a decision', 'daw' ),
		(int) $total_unmatched
	);
	printf(
		'<tr><td><em>%s</em></td><td style="text-align:right">%d</td></tr>',
		esc_html__( 'Already classified, will be skipped', 'daw' ),
		(int) $already
	);
	printf(
		'<tr><td><strong>%s</strong></td><td style="text-align:right"><strong>%d</strong></td></tr>',
		esc_html__( 'Total records', 'daw' ),
		count( $ids )
	);
	echo '</tbody></table>';

	if ( $unmatched ) {
		echo '<h3>' . esc_html__( 'Unmatched records (first 25)', 'daw' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'These carry no category the map recognises. They stay untyped until you give them a category or set the type by hand. The classifier never guesses.', 'daw' ) . '</p><ul style="list-style:disc;margin-left:20px">';
		foreach ( $unmatched as $id => $title ) {
			printf( '<li><a href="%s">%s</a></li>', esc_url( get_edit_post_link( $id ) ), esc_html( $title ) );
		}
		echo '</ul>';
	}
}

/**
 * Process one batch, then bounce back for the next until nothing is left.
 */
function daw_classify_run_batch() {
	$ids = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
		'posts_per_page' => DAW_CLASSIFY_BATCH,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'tax_query'      => array(
			'relation' => 'OR',
			array(
				'taxonomy' => 'dw_type',
				'operator' => 'NOT EXISTS',
			),
			array(
				'taxonomy' => 'dw_type',
				'field'    => 'slug',
				'terms'    => 'unclassified',
			),
		),
		// Without this the parked records would come back every batch and the
		// run would never end. The marker carries the plugin version, so a new
		// release with a wider map retries them exactly once more.
		'meta_query'     => array(
			'relation' => 'OR',
			array(
				'key'     => '_daw_classify_pass',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_daw_classify_pass',
				'value'   => DAW_VERSION,
				'compare' => '!=',
			),
		),
	) );

	if ( ! $ids ) {
		echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'Done. Every record that could be classified has been.', 'daw' ) . '</strong></p></div>';
		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'tools.php?page=daw-classify' ) ),
			esc_html__( 'Back to the preview', 'daw' )
		);
		return;
	}

	$countries = daw_country_map();
	$done      = 0;

	foreach ( $ids as $id ) {
		update_post_meta( $id, '_daw_classify_pass', DAW_VERSION );

		$slugs = wp_get_post_terms( $id, 'category', array( 'fields' => 'slugs' ) );
		$slugs = is_wp_error( $slugs ) ? array() : $slugs;

		$type = daw_classify_one( $slugs );
		if ( ! $type ) {
			// Park it so the batch query moves on instead of looping forever.
			wp_set_object_terms( $id, array( 'unclassified' ), 'dw_type', false );
			continue;
		}

		wp_set_object_terms( $id, array( $type ), 'dw_type', false );
		$done++;

		foreach ( $countries as $cat_slug => $country ) {
			if ( in_array( $cat_slug, $slugs, true ) ) {
				wp_set_object_terms( $id, array( $country ), 'dw_country', false );
				break;
			}
		}
	}

	$next = wp_nonce_url( admin_url( 'tools.php?page=daw-classify&run=1' ), 'daw_classify' );

	printf(
		'<div class="notice notice-info"><p>%s</p></div>',
		sprintf(
			/* translators: %d: number of records classified in this batch */
			esc_html__( 'Classified %d records in this batch. Continuing…', 'daw' ),
			(int) $done
		)
	);
	printf( '<meta http-equiv="refresh" content="1;url=%s">', esc_url( $next ) );
	printf( '<p><a href="%s">%s</a></p>', esc_url( $next ), esc_html__( 'Continue now', 'daw' ) );
}

/**
 * The parking term for records the map cannot place. Kept out of
 * daw_record_types() so it never appears as a real content type.
 */
add_action( 'init', 'daw_seed_unclassified_term', 21 );
function daw_seed_unclassified_term() {
	if ( ! term_exists( 'unclassified', 'dw_type' ) ) {
		wp_insert_term( 'Unclassified', 'dw_type', array( 'slug' => 'unclassified' ) );
	}
}
