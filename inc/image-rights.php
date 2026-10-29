<?php
/**
 * Discover Asian World — the image rights register.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every featured image the article audits looked at had no source, no author
 * and no licence recorded, and the owner confirmed the news images were his
 * own composites of pictures taken from elsewhere. An image nobody can trace
 * is an image the site cannot defend, however it got there.
 *
 * So each image now carries its own record: where it came from, who made it,
 * under what licence, and when it was taken. The credit under the picture is
 * built from that record, with links, which is what the licences actually
 * require. A picture with no record is reported as a problem, not assumed to
 * be fine.
 *
 * WHAT IS ALLOWED
 * ---------------
 * Licences that permit hosting the file on a site that carries advertising:
 * public domain / CC0, CC BY, CC BY-SA, KOGL Type 1, and the site's own work.
 * Official press or publicity material from the rights holder is allowed on
 * news only, unaltered, with the terms page recorded.
 *
 * Never allowed: anything NC (the site carries ads, so the use is commercial),
 * anything ND (resizing a crop can already count as a derivative), and
 * anything without a record.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** The record's fields, on the attachment. */
function daw_lic_keys() {
	return array(
		'type'      => 'daw_lic_type',      // cc0 | pd | cc-by | cc-by-sa | kogl1 | press | own
		'version'   => 'daw_lic_version',   // "4.0", "3.0" … for CC
		'author'    => 'daw_lic_author',
		'source'    => 'daw_lic_source',    // file page (e.g. commons.wikimedia.org/wiki/File:…)
		'terms'     => 'daw_lic_terms',     // press: the page stating the terms of use
		'retrieved' => 'daw_lic_retrieved', // Y-m-d
		'title'     => 'daw_lic_title',     // the work's own title, if it has one
	);
}

/** Licence types and how they are named and linked. */
function daw_lic_types() {
	return array(
		'cc0'      => array( 'label' => 'CC0', 'url' => 'https://creativecommons.org/publicdomain/zero/1.0/' ),
		'pd'       => array( 'label' => 'Public domain', 'url' => '' ),
		'cc-by'    => array( 'label' => 'CC BY %s', 'url' => 'https://creativecommons.org/licenses/by/%s/' ),
		'cc-by-sa' => array( 'label' => 'CC BY-SA %s', 'url' => 'https://creativecommons.org/licenses/by-sa/%s/' ),
		'kogl1'    => array( 'label' => 'KOGL Type 1', 'url' => 'https://www.kogl.or.kr/info/licenseType1.do' ),
		'press'    => array( 'label' => 'Press material', 'url' => '' ),
		'own'      => array( 'label' => '', 'url' => '' ),
	);
}

/** The record for one attachment, or an empty array. */
function daw_lic_get( $att_id ) {
	$att_id = (int) $att_id;
	if ( ! $att_id ) {
		return array();
	}

	$out = array();
	foreach ( daw_lic_keys() as $k => $meta ) {
		$out[ $k ] = trim( (string) get_post_meta( $att_id, $meta, true ) );
	}

	// The write API turns "3.0" into the number 3, and agents worked around it
	// with "3.0 " (trailing space) or stored plain "3". CC versions are 1.0,
	// 2.0, 2.5, 3.0 and 4.0, so a bare integer is always the ".0" one.
	$out['version'] = trim( $out['version'], " \t\n\r\0\x0B\"'" );
	if ( preg_match( '/^[1-4]$/', $out['version'] ) ) {
		$out['version'] .= '.0';
	}
	$out['type'] = strtolower( trim( $out['type'] ) );

	return '' === $out['type'] ? array() : $out;
}

/**
 * Is this image allowed where it is used?
 *
 * @return string ok | unregistered | disallowed | incomplete
 */
function daw_lic_status( $att_id, $post_id = 0 ) {
	$r = daw_lic_get( $att_id );

	if ( ! $r ) {
		return 'unregistered';
	}
	if ( ! isset( daw_lic_types()[ $r['type'] ] ) ) {
		return 'disallowed';
	}
	if ( 'press' === $r['type'] ) {
		$type = $post_id && function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
		// Owner, 29 Oct 2026: a title with no TMDB picture may carry the
		// distributor's or broadcaster's own promotional still or poster,
		// credited and with its terms written down. People stay excluded.
		if ( ! in_array( $type, array( 'news', 'drama', 'film', 'variety', 'anime' ), true ) ) {
			return 'disallowed';
		}
		if ( '' === $r['source'] || '' === $r['terms'] ) {
			return 'incomplete';
		}
		return 'ok';
	}
	if ( 'own' === $r['type'] ) {
		// A drawing or AI image the site made is fine for a monster or a news
		// card, never as the picture of a real person or group.
		$type = $post_id && function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
		return in_array( $type, array( 'person', 'group' ), true ) ? 'disallowed' : 'ok';
	}
	// Everything licensed needs the file page it came from; the attribution
	// licences also need an author.
	if ( '' === $r['source'] ) {
		return 'incomplete';
	}
	if ( in_array( $r['type'], array( 'cc-by', 'cc-by-sa', 'kogl1' ), true ) && '' === $r['author'] ) {
		return 'incomplete';
	}
	if ( in_array( $r['type'], array( 'cc-by', 'cc-by-sa' ), true ) && '' === $r['version'] ) {
		return 'incomplete';
	}

	return 'ok';
}

/**
 * The credit line, with the links the licence asks for.
 *
 * Title, author, source and licence — the four things CC attribution needs.
 * Returns '' when there is no record, so the template can fall back to the
 * old free-text credit field.
 */
function daw_lic_credit_html( $att_id ) {
	$r = daw_lic_get( $att_id );
	if ( ! $r ) {
		return '';
	}

	$types = daw_lic_types();
	$t     = $types[ $r['type'] ] ?? null;
	if ( ! $t ) {
		return '';
	}

	if ( 'own' === $r['type'] ) {
		return esc_html__( 'Image: Discover Asian World', 'daw' );
	}

	$who = '' !== $r['author'] ? esc_html( $r['author'] ) : '';
	if ( $who && '' !== $r['source'] ) {
		$who = sprintf( '<a href="%s" rel="nofollow noopener" target="_blank">%s</a>', esc_url( $r['source'] ), $who );
	}

	if ( 'press' === $r['type'] ) {
		return trim( sprintf(
			/* translators: %s: rights holder */
			__( 'Photo: %s (press material)', 'daw' ),
			$who ? $who : esc_html__( 'rights holder', 'daw' )
		) );
	}

	// A ported licence ("2.0 KR") links to its own deed, /by-sa/2.0/kr/.
	$ver  = $r['version'];
	$port = '';
	if ( preg_match( '/^(\d\.\d)\s+([A-Za-z]{2})$/', $ver, $pm ) ) {
		$ver  = $pm[1];
		$port = strtolower( $pm[2] );
	}
	$label = sprintf( $t['label'], $r['version'] );
	$url   = $t['url'] ? sprintf( $t['url'], $ver ) . ( '' !== $port ? $port . '/' : '' ) : '';
	$lic   = $url ? sprintf( '<a href="%s" rel="license nofollow noopener" target="_blank">%s</a>', esc_url( $url ), esc_html( trim( $label ) ) ) : esc_html( trim( $label ) );

	$via = '';
	if ( '' !== $r['source'] && false !== stripos( $r['source'], 'wikimedia.org' ) ) {
		$via = ' ' . esc_html__( 'via Wikimedia Commons', 'daw' );
	}

	if ( ! $who && '' !== $r['source'] ) {
		$who = sprintf( '<a href="%s" rel="nofollow noopener" target="_blank">%s</a>', esc_url( $r['source'] ), esc_html__( 'source', 'daw' ) );
	}

	return sprintf( '%s %s%s, %s', esc_html__( 'Photo:', 'daw' ), $who, $via, $lic );
}

/* ================================================================ census === */

/**
 * Every featured image in use on a published article or news story, with its
 * status. Titles and people are counted separately: their pictures come from
 * TMDB under the site's own rule, which is a different question from the one
 * this register answers, and mixing the two would hide both.
 */
function daw_lic_census() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}

	$types = function_exists( 'daw_audit_article_types' ) ? daw_audit_article_types() : array( 'news' );
	$ids   = function_exists( 'daw_audit_record_ids' ) ? daw_audit_record_ids( $types ) : array();

	$out = array(
		'counts' => array( 'ok' => 0, 'unregistered' => 0, 'incomplete' => 0, 'disallowed' => 0, 'none' => 0 ),
		'rows'   => array(),
	);

	foreach ( $ids as $pid ) {
		$att = (int) get_post_thumbnail_id( $pid );
		$st  = $att ? daw_lic_status( $att, $pid ) : 'none';
		$out['counts'][ $st ]++;

		if ( 'ok' !== $st ) {
			$out['rows'][] = array(
				'post'   => (int) $pid,
				'att'    => $att,
				'status' => $st,
				'type'   => function_exists( 'daw_get_type' ) ? daw_get_type( $pid ) : '',
			);
		}
	}

	$memo = $out;

	return $memo;
}

/**
 * Attachments no longer used as anybody's featured image and carrying no
 * record: the old uncredited pictures the audits replaced. Listed so the
 * owner can delete them from the media library. Nothing here deletes them;
 * a file that has left the library cannot be brought back.
 */
function daw_lic_orphans( $limit = 300 ) {
	global $wpdb;

	$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB
		"SELECT p.ID FROM {$wpdb->posts} p
		  WHERE p.post_type = 'attachment'
		    AND p.post_mime_type LIKE 'image/%'
		    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} t WHERE t.meta_key = '_thumbnail_id' AND t.meta_value = p.ID )
		    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} l WHERE l.post_id = p.ID AND l.meta_key = 'daw_lic_type' )
		    AND p.post_parent IN (
		        SELECT tr.object_id FROM {$wpdb->term_relationships} tr
		          JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		          JOIN {$wpdb->terms} te ON te.term_id = tt.term_id
		         WHERE tt.taxonomy = 'dw_type' AND te.slug IN ('news','culture','food','myth','travel','feature','quiz')
		    )
		  ORDER BY p.ID DESC
		  LIMIT " . (int) $limit
	);

	return array_map( 'intval', (array) $rows );
}

/* ================================================================ screen === */

add_action( 'admin_menu', 'daw_lic_menu' );
function daw_lic_menu() {
	add_submenu_page( 'daw', __( 'Commons & lisanslı', 'daw' ), __( 'Commons & lisanslı', 'daw' ), 'manage_options', 'daw-image-rights', 'daw_lic_page' );
}

function daw_lic_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$c = daw_lic_census();

	echo '<div class="wrap"><h1>Image rights</h1>';
	echo '<p style="max-width:56em">Every featured image on an article or news story, checked against its rights record. Allowed: public domain / CC0, CC BY, CC BY-SA, KOGL Type 1, the site\'s own cards, and official press material on news only. Never allowed: NC, ND, or anything without a record.</p>';

	echo '<table class="widefat" style="max-width:40em"><tbody>';
	foreach ( array(
		'ok'           => 'Recorded and allowed',
		'incomplete'   => 'Recorded, but missing author, source link or licence version',
		'unregistered' => 'No record — treat as unlicensed and replace',
		'disallowed'   => 'Licence not allowed here',
		'none'         => 'No featured image',
	) as $k => $label ) {
		printf( '<tr><td style="width:5em"><strong>%d</strong></td><td>%s</td></tr>', (int) $c['counts'][ $k ], esc_html( $label ) );
	}
	echo '</tbody></table>';

	if ( $c['rows'] ) {
		echo '<h2>To fix</h2><table class="widefat striped"><thead><tr><th>Post</th><th>Type</th><th>Image</th><th>Status</th></tr></thead><tbody>';
		foreach ( $c['rows'] as $r ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( get_edit_post_link( $r['post'] ) ),
				esc_html( get_the_title( $r['post'] ) ),
				esc_html( $r['type'] ),
				$r['att'] ? '<a href="' . esc_url( get_edit_post_link( $r['att'] ) ) . '">#' . (int) $r['att'] . '</a>' : '—',
				esc_html( $r['status'] )
			);
		}
		echo '</tbody></table>';
	}

	$orph = daw_lic_orphans();
	echo '<h2>Old images no longer in use, with no record</h2>';
	echo '<p style="max-width:56em">These are the pictures the audits took off articles and news. They are still in the media library. Deleting a file from the library cannot be undone, so this screen does not do it: open each one and delete it from the Media Library yourself once you are satisfied.</p>';
	if ( $orph ) {
		echo '<ol>';
		foreach ( $orph as $a ) {
			printf( '<li><a href="%s">#%d %s</a></li>', esc_url( get_edit_post_link( $a ) ), (int) $a, esc_html( get_the_title( $a ) ) );
		}
		echo '</ol>';
	} else {
		echo '<p style="color:#2a7">None.</p>';
	}

	do_action( 'daw_lic_page_after' );

	echo '</div>';
}

/* ======================================================= editing the record === */

/**
 * The record, visible and editable on the image's own edit screen.
 *
 * The owner asked for one place inside the CMS, seen only by him, where the
 * origin of every picture can be looked up. The fields were already on each
 * attachment; this puts them in front of him on the Media > Edit screen, and
 * the register screen below lists all of them and exports them to a file.
 */
add_action( 'add_meta_boxes_attachment', 'daw_lic_box_add' );
function daw_lic_box_add() {
	if ( current_user_can( 'manage_options' ) ) {
		add_meta_box( 'daw_lic_box', __( 'Rights record', 'daw' ), 'daw_lic_box_render', 'attachment', 'side', 'high' );
	}
}

function daw_lic_box_render( $post ) {
	wp_nonce_field( 'daw_lic_box', 'daw_lic_box_nonce' );
	$keys  = daw_lic_keys();
	$vals  = array();
	foreach ( $keys as $k => $meta ) {
		$vals[ $k ] = (string) get_post_meta( $post->ID, $meta, true );
	}
	$focus = (string) get_post_meta( $post->ID, 'daw_focus', true );

	$st = daw_lic_status( $post->ID, (int) $post->post_parent );
	printf( '<p><strong>Status:</strong> %s</p>', esc_html( $st ) );

	echo '<p><label>Licence<br><select name="daw_lic[type]" style="width:100%"><option value="">— none recorded —</option>';
	foreach ( array( 'cc0' => 'CC0', 'pd' => 'Public domain', 'cc-by' => 'CC BY', 'cc-by-sa' => 'CC BY-SA', 'kogl1' => 'KOGL Type 1', 'press' => 'Press material (news and titles, not people)', 'own' => 'Our own work' ) as $v => $l ) {
		printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $vals['type'], $v, false ), esc_html( $l ) );
	}
	echo '</select></label></p>';

	$fields = array(
		'version'   => 'Version (e.g. 4.0, 2.0 KR)',
		'author'    => 'Author, as the source credits it',
		'source'    => 'Source page (e.g. Commons file page)',
		'terms'     => 'Terms page (press material)',
		'retrieved' => 'Retrieved (YYYY-MM-DD)',
		'title'     => 'Work title (optional)',
	);
	foreach ( $fields as $k => $label ) {
		printf(
			'<p><label>%s<br><input type="text" name="daw_lic[%s]" value="%s" style="width:100%%"></label></p>',
			esc_html( $label ),
			esc_attr( $k ),
			esc_attr( trim( $vals[ $k ] ) )
		);
	}
	printf( '<p><label>Crop focus (e.g. 50%% 25%%)<br><input type="text" name="daw_focus" value="%s" style="width:100%%"></label></p>', esc_attr( $focus ) );

	if ( '' !== $vals['source'] ) {
		printf( '<p><a href="%s" target="_blank" rel="noopener">Open the source page</a></p>', esc_url( $vals['source'] ) );
	}
	$credit = daw_lic_credit_html( $post->ID );
	if ( '' !== $credit ) {
		echo '<p style="color:#555"><em>Printed as:</em><br>' . wp_kses( $credit, array( 'a' => array( 'href' => true, 'rel' => true, 'target' => true ) ) ) . '</p>';
	}
}

add_action( 'edit_attachment', 'daw_lic_box_save' );
function daw_lic_box_save( $att_id ) {
	if ( ! isset( $_POST['daw_lic_box_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['daw_lic_box_nonce'] ), 'daw_lic_box' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$in = isset( $_POST['daw_lic'] ) && is_array( $_POST['daw_lic'] ) ? wp_unslash( $_POST['daw_lic'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	foreach ( daw_lic_keys() as $k => $meta ) {
		$v = isset( $in[ $k ] ) ? trim( sanitize_text_field( (string) $in[ $k ] ) ) : '';
		if ( in_array( $k, array( 'source', 'terms' ), true ) ) {
			$v = esc_url_raw( $v );
		}
		if ( '' === $v ) {
			delete_post_meta( $att_id, $meta );
		} else {
			update_post_meta( $att_id, $meta, $v );
		}
	}
	$f = isset( $_POST['daw_focus'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['daw_focus'] ) ) ) : '';
	if ( '' === $f ) {
		delete_post_meta( $att_id, 'daw_focus' );
	} elseif ( preg_match( '/^\d{1,3}%\s+\d{1,3}%$/', $f ) ) {
		update_post_meta( $att_id, 'daw_focus', $f );
	}
}

/* ============================================================ the register === */

/** Every attachment that has a record, with where it is used. */
function daw_lic_register_rows() {
	global $wpdb;
	$ids  = array_map( 'intval', (array) $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'daw_lic_type' ORDER BY post_id DESC" ) ); // phpcs:ignore WordPress.DB
	$rows = array();
	foreach ( $ids as $id ) {
		$r    = daw_lic_get( $id );
		$used = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d", $id ) ) ); // phpcs:ignore WordPress.DB
		$rows[] = array(
			'id'        => $id,
			'file'      => basename( (string) get_attached_file( $id ) ),
			'type'      => $r['type'] ?? '',
			'version'   => $r['version'] ?? '',
			'author'    => $r['author'] ?? '',
			'source'    => $r['source'] ?? '',
			'terms'     => $r['terms'] ?? '',
			'retrieved' => $r['retrieved'] ?? '',
			'status'    => daw_lic_status( $id, $used ? $used[0] : 0 ),
			'used_on'   => $used,
		);
	}
	return $rows;
}

add_action( 'admin_post_daw_lic_export', 'daw_lic_export' );
function daw_lic_export() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'daw_lic_export' ) ) {
		wp_die( 'No.' );
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="image-rights-register-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fputcsv( $out, array( 'attachment_id', 'file', 'licence', 'version', 'author', 'source_page', 'terms_page', 'retrieved', 'status', 'used_on_post_ids', 'used_on_urls' ) );
	foreach ( daw_lic_register_rows() as $r ) {
		fputcsv(
			$out,
			array(
				$r['id'], $r['file'], $r['type'], $r['version'], $r['author'], $r['source'], $r['terms'], $r['retrieved'], $r['status'],
				implode( ' ', $r['used_on'] ),
				implode( ' ', array_map( 'get_permalink', $r['used_on'] ) ),
			)
		);
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

add_action( 'daw_lic_page_after', 'daw_lic_register_table' );
function daw_lic_register_table() {
	$rows = daw_lic_register_rows();
	echo '<h2>Full register (' . count( $rows ) . ')</h2>';
	echo '<p style="max-width:56em">Every image with a rights record: where it came from, under what licence, who made it and when it was taken. Only administrators can see this screen. The same list downloads as a file:</p>';
	printf( '<p><a class="button button-primary" href="%s">Download the register (CSV)</a></p>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=daw_lic_export' ), 'daw_lic_export' ) ) );
	echo '<table class="widefat striped"><thead><tr><th></th><th>Licence</th><th>Author</th><th>Source</th><th>Retrieved</th><th>Used on</th><th>Status</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$thumb = wp_get_attachment_image( $r['id'], array( 60, 60 ) );
		$used  = array();
		foreach ( $r['used_on'] as $p ) {
			$used[] = '<a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a>';
		}
		printf(
			'<tr><td><a href="%s">%s</a></td><td>%s %s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_url( get_edit_post_link( $r['id'] ) ),
			$thumb, // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html( $r['type'] ),
			esc_html( $r['version'] ),
			esc_html( $r['author'] ),
			$r['source'] ? '<a href="' . esc_url( $r['source'] ) . '" target="_blank" rel="noopener">file page</a>' : '—',
			esc_html( $r['retrieved'] ),
			implode( '<br>', $used ), // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html( $r['status'] )
		);
	}
	echo '</tbody></table>';
}
