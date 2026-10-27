<?php
/**
 * Discover Asian World — taking the archive off Elementor.
 *
 * WHAT THE SCAN FOUND
 * -------------------
 * Every url in the sitemap was fetched and read. Eleven pages actually render
 * Elementor widgets. Eight hundred and thirty nine carry _elementor_edit_mode
 * and a payload in _elementor_data and render nothing from either: our own
 * templates draw them, and the only trace Elementor leaves is a body class and
 * the cost of booting the whole builder on every request.
 *
 * So the job is not a migration. It is removing a flag from records that stopped
 * using it the day their template was written.
 *
 * THE PAYLOAD IS MOVED, NOT DELETED
 * ---------------------------------
 * _elementor_data is the only place some of this site's facts have ever lived:
 * the extractor reads occupations, birthdays and synopses straight out of it,
 * and the backfill run that filled nine hundred records read it too. Deleting
 * it would throw away the source and leave the derived copy, which is the wrong
 * way round. It is renamed to _daw_legacy_elementor instead. Elementor stops
 * seeing it, every reader in this plugin still does, and nothing that was
 * imported is lost.
 *
 * IT RUNS IN SLICES
 * -----------------
 * Nine hundred records of meta deletion in one request is a timeout. The queue
 * is built once and worked through on admin page loads and on cron, with a lock,
 * the same way the backfill runs.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_EXIT_SLICE  = 40;
const DAW_EXIT_BUDGET = 12; // seconds.

/**
 * The records that still draw themselves with Elementor, and are therefore left
 * alone until their content has somewhere else to live.
 *
 * This list empties as each one is converted, and the run stamp below changes
 * with it, which walks the archive again and picks up whatever was released.
 */
function daw_exit_keep() {
	// Empty, and meant to stay empty. Every page that drew itself with the
	// builder now draws itself from fields: the legal set from its own body,
	// the two lists from the browse layer, BTS from the group fields, the
	// ranking quiz from question rows, and the childhood photo quiz not at all.
	// A slug goes back in here only if something is put back into the builder,
	// which is the thing this whole exercise exists to stop.
	$slugs = array();

	$ids = array();

	foreach ( $slugs as $slug ) {
		$rows = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		if ( $rows ) {
			$ids[] = (int) $rows[0];
		}
	}

	return $ids;
}

/**
 * The meta rows that only exist to tell a page builder how to draw a page.
 *
 * _elementor_data is not in the list because it is moved rather than removed.
 * The _uag_ rows are left over from an addon pack that is not even installed any
 * more; they are a cached stylesheet for blocks nobody renders.
 */
function daw_exit_meta_keys() {
	return array(
		'_elementor_edit_mode',
		'_elementor_template_type',
		'_elementor_version',
		'_elementor_pro_version',
		'_elementor_page_assets',
		'_elementor_css',
		'_elementor_element_cache',
		'_elementor_controls_usage',
		'_elementor_conditions',
		'_elementor_screenshot_failed',
		'_uag_page_assets',
		'_uag_css_file_name',
		'_uag_custom_page_level_css',
	);
}

/**
 * The Elementor payload a record still holds, wherever it now lives.
 *
 * Readers call this instead of get_post_meta so that a record which has been
 * through the exit is still readable by the extractor.
 */
function daw_elementor_payload( $post_id ) {
	$raw = get_post_meta( (int) $post_id, '_elementor_data', true );

	if ( '' !== $raw && null !== $raw && array() !== $raw ) {
		return $raw;
	}

	return get_post_meta( (int) $post_id, '_daw_legacy_elementor', true );
}

/** One record off the builder. Returns the number of rows it removed. */
function daw_exit_one( $post_id ) {
	$post_id = (int) $post_id;
	$n       = 0;

	$raw = get_post_meta( $post_id, '_elementor_data', true );

	if ( '' !== $raw && null !== $raw && array() !== $raw ) {
		$kept = get_post_meta( $post_id, '_daw_legacy_elementor', true );

		// update_post_meta strips one level of slashes on the way in, so the
		// payload is slashed on the way out. A json payload that loses its
		// escaping is a payload nobody can read back.
		if ( '' === $kept || null === $kept ) {
			update_post_meta( $post_id, '_daw_legacy_elementor', wp_slash( $raw ) );
		}

		delete_post_meta( $post_id, '_elementor_data' );
		++$n;
	}

	foreach ( daw_exit_meta_keys() as $key ) {
		if ( metadata_exists( 'post', $post_id, $key ) ) {
			delete_post_meta( $post_id, $key );
			++$n;
		}
	}

	return $n;
}

/* ================================================================ queue === */

/** Every record that still carries a builder flag, minus the ones still using it. */
function daw_exit_build_queue() {
	global $wpdb;

	$ids = $wpdb->get_col(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
		 WHERE meta_key IN ('_elementor_edit_mode','_elementor_data','_elementor_page_assets','_uag_page_assets')"
	);

	$ids  = array_map( 'intval', (array) $ids );
	$keep = daw_exit_keep();
	$ids  = array_values( array_diff( $ids, $keep ) );

	rsort( $ids );

	update_option( 'daw_exit_queue', $ids, false );
	update_option( 'daw_exit_total', count( $ids ), false );
	update_option( 'daw_exit_stats', array( 'done' => 0, 'rows' => 0, 'started' => time() ), false );

	return $ids;
}

/** Work through a slice. Returns how many records it handled. */
function daw_exit_run_slice() {
	$queue = get_option( 'daw_exit_queue' );

	if ( ! is_array( $queue ) || ! $queue ) {
		return 0;
	}

	if ( get_transient( 'daw_exit_lock' ) ) {
		return 0;
	}

	set_transient( 'daw_exit_lock', 1, 120 );

	$stats = get_option( 'daw_exit_stats', array( 'done' => 0, 'rows' => 0 ) );
	$start = microtime( true );
	$n     = 0;

	while ( $queue && $n < DAW_EXIT_SLICE && ( microtime( true ) - $start ) < DAW_EXIT_BUDGET ) {
		$id = array_shift( $queue );

		$stats['rows'] = (int) ( $stats['rows'] ?? 0 ) + daw_exit_one( $id );
		++$stats['done'];
		++$n;
	}

	update_option( 'daw_exit_queue', array_values( $queue ), false );
	update_option( 'daw_exit_stats', $stats, false );

	if ( ! $queue ) {
		update_option( 'daw_exit_finished', time(), false );
	}

	delete_transient( 'daw_exit_lock' );

	return $n;
}

/* ================================================================= runs === */

add_action( 'admin_init', 'daw_exit_tick', 31 );
add_action( 'daw_exit_cron', 'daw_exit_tick' );

function daw_exit_tick() {
	if ( function_exists( 'daw_is_delicate_request' ) && daw_is_delicate_request() ) {
		return;
	}


	daw_exit_run_slice();

	$queue = get_option( 'daw_exit_queue' );

	if ( is_array( $queue ) && $queue && ! wp_next_scheduled( 'daw_exit_cron' ) ) {
		wp_schedule_single_event( time() + 60, 'daw_exit_cron' );
	}
}

/**
 * Start the run once per stamp.
 *
 * Changing the stamp walks the archive again, which is how the second wave runs
 * after the last six pages have been converted and the keep list is empty.
 */
add_action( 'init', 'daw_exit_maybe_start', 27 );
function daw_exit_maybe_start() {
	$run = '2026-09-18-wave3';

	if ( get_option( 'daw_exit_run' ) === $run ) {
		return;
	}

	update_option( 'daw_exit_run', $run, false );
	delete_option( 'daw_exit_finished' );
	daw_exit_build_queue();
}

/* ============================================================== notice === */

add_action( 'admin_notices', 'daw_exit_notice' );
function daw_exit_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$queue = get_option( 'daw_exit_queue' );
	$total = (int) get_option( 'daw_exit_total' );

	if ( ! is_array( $queue ) || ! $total || ! $queue ) {
		return;
	}

	printf(
		'<div class="notice notice-info"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'DAW is taking the archive off Elementor.', 'daw' ),
		esc_html(
			sprintf(
				/* translators: 1: records done, 2: records in total */
				__( '%1$s of %2$s records done. The old payload is kept, renamed, so nothing the extractor reads is lost.', 'daw' ),
				number_format_i18n( $total - count( $queue ) ),
				number_format_i18n( $total )
			)
		)
	);
}
