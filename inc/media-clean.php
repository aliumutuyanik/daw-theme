<?php
/**
 * Discover Asian World — removing the files nothing points at.
 *
 * THE NUMBER THIS EXISTS FOR
 * --------------------------
 * Ten thousand eight hundred files in the library. Two hundred and seventy two
 * of them were fetched from TMDB and carry a source. Eight and a half thousand
 * of them are not a record's poster, are not inside any body, any field, any
 * option or any term, and were last touched by whoever uploaded them. They cost
 * disk, they cost every backup, and not one of them is on a page.
 *
 * HOW IT DECIDES
 * --------------
 * It does not decide. daw_media_unused() reads every post body, every meta
 * value, every option and every term meta, pulls the upload paths out of them,
 * and subtracts that set. A file is a candidate only if it appears in none of
 * them and is nobody's featured image. That is a fact about the database rather
 * than a guess about a filename.
 *
 * WHAT IT WILL NOT TOUCH
 * ----------------------
 * The site icon and the logo are protected by id, because both are referenced
 * as a number rather than as a path and no scan of the content would find them.
 *
 * THE LIST COMES OUT FIRST
 * ------------------------
 * Before a single row is deleted, the whole list is written to a csv in the
 * uploads folder: id, file, title, date. If this turns out to have been a
 * mistake, that file is the only way anybody would ever know what was there.
 * The deletion cannot start until that file exists.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_CLEAN_SLICE  = 40;
const DAW_CLEAN_BUDGET = 12; // seconds.

/** Attachments that are referenced by id rather than by path, and so never scanned. */
function daw_media_protected() {
	$ids = array(
		(int) get_option( 'site_icon' ),
		(int) get_theme_mod( 'custom_logo' ),
		(int) get_option( 'daw_logo_attachment' ),
		(int) get_option( 'daw_icon_attachment' ),
	);

	return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
}

/** Where the record of what was removed is written. */
function daw_media_clean_csv_path() {
	$up = wp_upload_dir();

	return trailingslashit( $up['basedir'] ) . 'daw-removed-images.csv';
}

function daw_media_clean_csv_url() {
	$up = wp_upload_dir();

	return trailingslashit( $up['baseurl'] ) . 'daw-removed-images.csv';
}

/**
 * Write the list, and return how many rows it holds.
 *
 * Nothing is deleted here. This is the receipt, and it is written before the
 * transaction rather than after it.
 */
function daw_media_clean_write_csv( $ids ) {
	$path = daw_media_clean_csv_path();
	$fh   = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	if ( ! $fh ) {
		return 0;
	}

	fputcsv( $fh, array( 'id', 'file', 'title', 'uploaded', 'url' ) );

	$n = 0;

	foreach ( (array) $ids as $id ) {
		$id   = (int) $id;
		$post = get_post( $id );

		if ( ! $post ) {
			continue;
		}

		fputcsv(
			$fh,
			array(
				$id,
				(string) get_post_meta( $id, '_wp_attached_file', true ),
				$post->post_title,
				$post->post_date,
				(string) wp_get_attachment_url( $id ),
			)
		);

		++$n;
	}

	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	return $n;
}

/* ================================================================= run === */

/** Arm the run: rebuild the list, write the csv, queue the ids. */
function daw_media_clean_start() {
	$unused = daw_media_unused( true );
	$ids    = array_values( array_diff( (array) ( $unused['ids'] ?? array() ), daw_media_protected() ) );

	if ( ! $ids ) {
		return 0;
	}

	$written = daw_media_clean_write_csv( $ids );

	if ( ! $written ) {
		update_option( 'daw_clean_note', 'The list could not be written to the uploads folder, so nothing was deleted.', false );
		return 0;
	}

	update_option( 'daw_clean_queue', $ids, false );
	update_option( 'daw_clean_total', count( $ids ), false );
	update_option( 'daw_clean_done', 0, false );
	update_option( 'daw_clean_note', '', false );

	return count( $ids );
}

/** Stop, leaving whatever is left alone. */
function daw_media_clean_stop() {
	delete_option( 'daw_clean_queue' );
}

function daw_media_clean_slice() {
	$queue = get_option( 'daw_clean_queue' );

	if ( ! is_array( $queue ) || ! $queue ) {
		return 0;
	}

	if ( get_transient( 'daw_clean_lock' ) ) {
		return 0;
	}

	set_transient( 'daw_clean_lock', 1, 120 );

	$protected = array_flip( daw_media_protected() );
	$start     = microtime( true );
	$done      = (int) get_option( 'daw_clean_done' );
	$n         = 0;

	while ( $queue && $n < DAW_CLEAN_SLICE && ( microtime( true ) - $start ) < DAW_CLEAN_BUDGET ) {
		$id = (int) array_shift( $queue );
		++$n;

		if ( isset( $protected[ $id ] ) ) {
			continue;
		}

		// Read once more at the moment of deletion. A file that became a
		// record's poster between the scan and now is no longer a candidate,
		// and that gap is exactly where an accident would live.
		if ( 'attachment' !== get_post_type( $id ) ) {
			continue;
		}

		global $wpdb;

		$still = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d", $id )
		);

		if ( $still ) {
			continue;
		}

		wp_delete_attachment( $id, true );
		++$done;
	}

	update_option( 'daw_clean_queue', array_values( $queue ), false );
	update_option( 'daw_clean_done', $done, false );

	if ( ! $queue ) {
		delete_option( 'daw_clean_queue' );
		update_option( 'daw_clean_finished', time(), false );
	}

	delete_transient( 'daw_clean_lock' );

	return $n;
}

add_action( 'admin_init', 'daw_media_clean_tick', 32 );
add_action( 'daw_clean_cron', 'daw_media_clean_tick' );

function daw_media_clean_tick() {
	if ( function_exists( 'daw_is_delicate_request' ) && daw_is_delicate_request() ) {
		return;
	}


	daw_media_clean_slice();

	if ( get_option( 'daw_clean_queue' ) && ! wp_next_scheduled( 'daw_clean_cron' ) ) {
		wp_schedule_single_event( time() + 60, 'daw_clean_cron' );
	}
}

/* ============================================================== screen === */

add_action( 'admin_post_daw_media_clean', 'daw_media_clean_handle' );
function daw_media_clean_handle() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'daw_media_clean' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'daw' ) );
	}

	$what = isset( $_POST['daw_do'] ) ? sanitize_key( wp_unslash( $_POST['daw_do'] ) ) : '';

	if ( 'list' === $what ) {
		$unused = daw_media_unused( true );
		$ids    = array_values( array_diff( (array) ( $unused['ids'] ?? array() ), daw_media_protected() ) );
		daw_media_clean_write_csv( $ids );
	} elseif ( 'go' === $what ) {
		daw_media_clean_start();
	} elseif ( 'stop' === $what ) {
		daw_media_clean_stop();
	}

	wp_safe_redirect( admin_url( 'tools.php?page=daw-media-audit' ) );
	exit;
}

/** The controls, printed at the bottom of the audit screen. */
function daw_media_clean_controls() {
	$unused = get_option( 'daw_media_unused' );
	$queue  = get_option( 'daw_clean_queue' );
	$total  = (int) get_option( 'daw_clean_total' );
	$done   = (int) get_option( 'daw_clean_done' );
	$csv    = file_exists( daw_media_clean_csv_path() );
	$note   = (string) get_option( 'daw_clean_note' );

	echo '<h2>' . esc_html__( 'Removing them', 'daw' ) . '</h2>';

	if ( $note ) {
		printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $note ) );
	}

	if ( is_array( $queue ) && $queue ) {
		printf(
			'<p><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: 1: files removed so far, 2: files in total */
					__( 'Removing: %1$s of %2$s done. It carries on by itself.', 'daw' ),
					number_format_i18n( $done ),
					number_format_i18n( $total )
				)
			)
		);

		printf(
			'<form method="post" action="%s">%s<input type="hidden" name="action" value="daw_media_clean"><input type="hidden" name="daw_do" value="stop"><button class="button">%s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'daw_media_clean', '_wpnonce', true, false ),
			esc_html__( 'Stop', 'daw' )
		);

		return;
	}

	if ( get_option( 'daw_clean_finished' ) ) {
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: how many files were removed */
					__( 'Finished. %s files removed. The list of what went is in the csv below.', 'daw' ),
					number_format_i18n( $done )
				)
			)
		);
	}

	printf(
		'<p>%s</p>',
		esc_html__( 'Write the list first and read it. The list is the only record of what was here, and it is written before anything is removed rather than after.', 'daw' )
	);

	printf(
		'<form method="post" action="%1$s" style="display:inline">%2$s<input type="hidden" name="action" value="daw_media_clean"><input type="hidden" name="daw_do" value="list"><button class="button">%3$s</button></form> ',
		esc_url( admin_url( 'admin-post.php' ) ),
		wp_nonce_field( 'daw_media_clean', '_wpnonce', true, false ),
		esc_html__( 'Write the list', 'daw' )
	);

	if ( $csv ) {
		printf(
			' <a class="button" href="%s" download>%s</a>',
			esc_url( daw_media_clean_csv_url() ),
			esc_html__( 'Download the list', 'daw' )
		);

		printf(
			'<form method="post" action="%1$s" style="margin-top:18px" onsubmit="return confirm(\'%4$s\')">%2$s<input type="hidden" name="action" value="daw_media_clean"><input type="hidden" name="daw_do" value="go"><button class="button button-primary">%3$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'daw_media_clean', '_wpnonce', true, false ),
			esc_html(
				sprintf(
					/* translators: %s: how many files would go */
					__( 'Remove all %s files', 'daw' ),
					number_format_i18n( (int) ( $unused['n'] ?? 0 ) )
				)
			),
			esc_attr__( 'This deletes the files as well as the database rows, and it cannot be undone. The list has been written. Continue?', 'daw' )
		);
	}
}
