<?php
/**
 * Discover Asian World — where every image came from.
 *
 * WHY THIS EXISTS
 * ---------------
 * The site's own copyright page makes a specific promise: one poster per title,
 * one portrait per person, each one recording the file's source and the rights
 * holder, and nothing else. The media library holds ten thousand files, and
 * nobody can currently say which of them that promise covers.
 *
 * A quiz built on ten childhood photographs of named actors, with no source
 * recorded for any of them, is what turned this from a tidy-up into a job. The
 * question is not whether those particular files go. It is how many more there
 * are, and the honest answer is that nobody knows until something counts them.
 *
 * WHAT IT COUNTS
 * --------------
 * Images fetched from TMDB carry _daw_source, written when they were fetched.
 * Everything else was uploaded by hand at some point over three years, by
 * whoever, from wherever. That single fact splits the library in two, and the
 * second half is the half to look at.
 *
 * IT DELETES NOTHING
 * ------------------
 * This screen reports. Deleting ten thousand files on the strength of a missing
 * meta row would take out the logo, the hub artwork and every guide's own
 * photography along with the problem. The list comes first, a person reads it,
 * and the removal is a separate decision made against a name rather than a
 * count.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'daw_media_audit_menu' );
function daw_media_audit_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW image sources', 'daw' ),
		__( 'DAW image sources', 'daw' ),
		'manage_options',
		'daw-media-audit',
		'daw_media_audit_page'
	);
}

/** The numbers, computed in four statements rather than ten thousand. */
function daw_media_audit_counts() {
	global $wpdb;

	$out = array();

	$out['total'] = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
	);

	$out['sourced'] = (int) $wpdb->get_var(
		"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_daw_source'
		 WHERE p.post_type = 'attachment' AND m.meta_value <> ''"
	);

	$out['thumbs'] = (int) $wpdb->get_var(
		"SELECT COUNT(DISTINCT m.meta_value) FROM {$wpdb->postmeta} m
		 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_status <> 'trash'
		 WHERE m.meta_key = '_thumbnail_id'"
	);

	// Attached to nothing and used as nobody's poster: the files that would
	// disappear tomorrow without a single page changing.
	$out['loose'] = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		 WHERE p.post_type = 'attachment'
		   AND p.ID NOT IN ( SELECT CAST(meta_value AS UNSIGNED) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' )"
	);

	$out['unsourced'] = $out['total'] - $out['sourced'];

	return $out;
}

/** Unsourced uploads grouped by the month they arrived, newest first. */
function daw_media_audit_months( $limit = 40 ) {
	global $wpdb;

	return (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DATE_FORMAT(p.post_date, '%%Y-%%m') AS ym, COUNT(*) AS n
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_daw_source'
			 WHERE p.post_type = 'attachment' AND m.meta_id IS NULL
			 GROUP BY ym
			 ORDER BY ym DESC
			 LIMIT %d",
			(int) $limit
		)
	);
}

/**
 * Unsourced files whose own name says what they are.
 *
 * A file called "Childhood photo" is a claim about a private person, made in a
 * filename, by somebody who did not write down where the picture came from.
 * These are the ones to read first.
 */
function daw_media_audit_patterns() {
	return array(
		'childhood' => __( 'childhood and baby photographs', 'daw' ),
		'baby'      => __( 'childhood and baby photographs', 'daw' ),
		'gallery'   => __( 'gallery images', 'daw' ),
		'wallpaper' => __( 'wallpapers', 'daw' ),
		'screenshot'=> __( 'screen captures', 'daw' ),
		'screencap' => __( 'screen captures', 'daw' ),
		'instagram' => __( 'social media reposts', 'daw' ),
		'weibo'     => __( 'social media reposts', 'daw' ),
		'pinterest' => __( 'social media reposts', 'daw' ),
	);
}

/** Files matching one flagged word, with the record that shows them. */
function daw_media_audit_flagged( $word, $limit = 200 ) {
	global $wpdb;

	$like = '%' . $wpdb->esc_like( $word ) . '%';

	return (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_date
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_daw_source'
			 WHERE p.post_type = 'attachment'
			   AND m.meta_id IS NULL
			   AND ( p.post_title LIKE %s OR p.guid LIKE %s )
			 ORDER BY p.post_date DESC
			 LIMIT %d",
			$like,
			$like,
			(int) $limit
		)
	);
}


/* ============================================================== unused === */

/**
 * The files nothing on the site points at.
 *
 * WHY THE OBVIOUS QUERY IS NOT ENOUGH
 * -----------------------------------
 * "Not a featured image" is not the same as "unused". An image can sit inside a
 * guide's body, inside a hub's intro, or inside a field, and none of those are
 * a _thumbnail_id row. Deleting on the strength of that one query would take
 * out pictures that are on the page right now.
 *
 * So every post body and every meta value is read once, the upload paths are
 * pulled out of them, and that set is subtracted. One pass over the content
 * rather than ten thousand LIKE queries, and the answer is a list of files that
 * genuinely appear nowhere.
 */
function daw_media_unused( $rebuild = false ) {
	$cached = get_option( 'daw_media_unused' );

	if ( ! $rebuild && is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;

	$used = array();

	$note = static function ( $text ) use ( &$used ) {
		if ( ! is_string( $text ) || false === strpos( $text, 'uploads/' ) ) {
			return;
		}

		if ( preg_match_all( '#uploads/([0-9]{4}/[0-9]{2}/[^"\'\\s\\\\)\\]}>,]+)#i', $text, $m ) ) {
			foreach ( $m[1] as $path ) {
				// Strip the size suffix WordPress adds, so poster-300x450.jpg
				// counts as a use of poster.jpg.
				$path = preg_replace( '/-\\d+x\\d+(\\.[a-z0-9]+)$/i', '$1', $path );
				$used[ strtolower( $path ) ] = true;
			}
		}
	};

	foreach ( (array) $wpdb->get_col( "SELECT post_content FROM {$wpdb->posts} WHERE post_content LIKE '%uploads/%'" ) as $body ) {
		$note( $body );
	}

	foreach ( (array) $wpdb->get_col( "SELECT post_excerpt FROM {$wpdb->posts} WHERE post_excerpt LIKE '%uploads/%'" ) as $body ) {
		$note( $body );
	}

	foreach ( (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE '%uploads/%'" ) as $value ) {
		$note( $value );
	}

	foreach ( (array) $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_value LIKE '%uploads/%'" ) as $value ) {
		$note( $value );
	}

	foreach ( (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_value LIKE '%uploads/%'" ) as $value ) {
		$note( $value );
	}

	// Every attachment, with the file it stands for.
	$rows = (array) $wpdb->get_results(
		"SELECT p.ID, p.post_title, m.meta_value AS file
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
		 WHERE p.post_type = 'attachment'"
	);

	$thumbs = array_flip(
		array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'" ) )
	);

	$unused = array();

	foreach ( $rows as $row ) {
		if ( isset( $thumbs[ (int) $row->ID ] ) ) {
			continue;
		}

		if ( isset( $used[ strtolower( (string) $row->file ) ] ) ) {
			continue;
		}

		$unused[] = (int) $row->ID;
	}

	$out = array(
		'ids'     => $unused,
		'n'       => count( $unused ),
		'total'   => count( $rows ),
		'checked' => time(),
	);

	update_option( 'daw_media_unused', $out, false );

	return $out;
}

function daw_media_audit_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$c = daw_media_audit_counts();

	echo '<div class="wrap"><h1>' . esc_html__( 'Where the images came from', 'daw' ) . '</h1>';

	echo '<p>' . esc_html__( 'An image with a source recorded can be defended. An image without one cannot, whatever it shows. This screen counts the two, and it changes nothing.', 'daw' ) . '</p>';

	echo '<table class="widefat striped" style="max-width:640px"><tbody>';

	$rows = array(
		__( 'Files in the library', 'daw' )                  => $c['total'],
		__( 'Fetched from TMDB, source recorded', 'daw' )    => $c['sourced'],
		__( 'Uploaded by hand, no source recorded', 'daw' )  => $c['unsourced'],
		__( 'Used as a record poster or portrait', 'daw' )   => $c['thumbs'],
		__( 'Not used as any record image', 'daw' )          => $c['loose'],
	);

	foreach ( $rows as $label => $n ) {
		printf(
			'<tr><td>%s</td><td style="text-align:right"><strong>%s</strong></td></tr>',
			esc_html( $label ),
			esc_html( number_format_i18n( (int) $n ) )
		);
	}

	echo '</tbody></table>';

	/* ------------------------------------------------------- flagged ----- */

	echo '<h2>' . esc_html__( 'Files whose own name is the problem', 'daw' ) . '</h2>';

	$seen  = array();
	$found = false;

	foreach ( daw_media_audit_patterns() as $word => $label ) {
		$hits = daw_media_audit_flagged( $word );

		if ( ! $hits ) {
			continue;
		}

		$found = true;

		printf(
			'<h3>%s <span style="font-weight:400">(%s)</span></h3>',
			esc_html( ucfirst( $label ) ),
			esc_html( sprintf( /* translators: %s: a word found in a file name */ __( 'matched on "%s"', 'daw' ), $word ) )
		);

		echo '<ul style="margin-left:1.4em;list-style:disc">';

		foreach ( $hits as $hit ) {
			if ( isset( $seen[ $hit->ID ] ) ) {
				continue;
			}

			$seen[ $hit->ID ] = true;

			printf(
				'<li><a href="%s">%s</a> <span style="color:#666">%s</span></li>',
				esc_url( get_edit_post_link( $hit->ID ) ),
				esc_html( $hit->post_title ),
				esc_html( mysql2date( 'j M Y', $hit->post_date ) )
			);
		}

		echo '</ul>';
	}

	if ( ! $found ) {
		echo '<p>' . esc_html__( 'Nothing matched. That is a good sign and not a clean bill of health: a file can be a problem under a perfectly ordinary name.', 'daw' ) . '</p>';
	}

	/* -------------------------------------------------------- months ----- */

	echo '<h2>' . esc_html__( 'Unsourced uploads, by the month they arrived', 'daw' ) . '</h2>';
	echo '<p>' . esc_html__( 'A month with hundreds of files in it is usually one import or one project, which makes it one decision rather than hundreds.', 'daw' ) . '</p>';
	echo '<table class="widefat striped" style="max-width:420px"><tbody>';

	foreach ( daw_media_audit_months() as $row ) {
		printf(
			'<tr><td>%s</td><td style="text-align:right">%s</td></tr>',
			esc_html( $row->ym ),
			esc_html( number_format_i18n( (int) $row->n ) )
		);
	}

	echo '</tbody></table>';

	/* --------------------------------------------------------- unused ---- */

	echo '<h2>' . esc_html__( 'Files nothing points at', 'daw' ) . '</h2>';

	$rebuild = isset( $_GET['daw_scan'] ) && check_admin_referer( 'daw_media_scan' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$unused  = get_option( 'daw_media_unused' );

	if ( $rebuild || ! is_array( $unused ) ) {
		$unused = daw_media_unused( true );
	}

	printf(
		'<p>%s</p>',
		esc_html(
			sprintf(
				/* translators: 1: files that appear nowhere, 2: files in total */
				__( '%1$s of %2$s files appear in no record body, no field, no option and no record image. This reads every body and every field rather than trusting the featured image column, so a picture that is inside a guide is not on this list.', 'daw' ),
				number_format_i18n( (int) ( $unused['n'] ?? 0 ) ),
				number_format_i18n( (int) ( $unused['total'] ?? 0 ) )
			)
		)
	);

	if ( ! empty( $unused['checked'] ) ) {
		printf(
			'<p><em>%s</em> <a class="button" href="%s">%s</a></p>',
			esc_html(
				sprintf(
					/* translators: %s: how long ago the scan ran */
					__( 'Counted %s ago.', 'daw' ),
					human_time_diff( (int) $unused['checked'] )
				)
			),
			esc_url( wp_nonce_url( admin_url( 'tools.php?page=daw-media-audit&daw_scan=1' ), 'daw_media_scan' ) ),
			esc_html__( 'Count again', 'daw' )
		);
	}

	echo '<p>' . esc_html__( 'Nothing is deleted from this screen. The list is the thing to read first, because a file that appears nowhere today was on a page that was rewritten yesterday, and its record still remembers it.', 'daw' ) . '</p>';

	echo '</div>';
}
