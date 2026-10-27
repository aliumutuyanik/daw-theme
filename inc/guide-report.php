<?php
/**
 * Where every guide record stands.
 *
 * WHY THIS EXISTS
 * ---------------
 * Fifty-odd articles have to be rewritten one at a time, over days, by whoever
 * is free. Without a list the work is invisible: nobody can tell what is done,
 * what is half done, or which record was skipped because it was awkward. The
 * hub pages are no help either, because they only show what someone remembered
 * to add to them, which turned out to be two thirds of the set.
 *
 * So this reads the taxonomy directly and reports the state of each record
 * against the things a finished guide has: a standfirst, a fact table, sources,
 * questions, the titles it appears in, a picture, and prose that is not still
 * sitting in a page builder.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** Everything filed under a guide type, oldest first. */
function daw_guide_records() {
	return get_posts(
		array(
			'post_type'        => 'post',
			'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => false,
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => daw_guide_types(),
				),
			),
		)
	);
}

/** One record's state, as the columns of the report. */
function daw_guide_state( $post_id ) {
	$post  = get_post( $post_id );
	$body  = $post ? $post->post_content : '';
	$plain = wp_strip_all_tags( strip_shortcodes( $body ) );

	$rows = static function ( $key ) use ( $post_id ) {
		return count( array_filter( array_map( 'trim', array_map( 'strval', (array) daw_field_raw( $post_id, $key ) ) ) ) );
	};

	// WHICH COUNTRY A RECORD BELONGS TO
	// ---------------------------------
	// The breadcrumb, the schema and any future country filter all read
	// dw_country, and a record with none of it falls out of all three
	// silently. So the report names it, and names it as missing when it is,
	// because "which of these is Japanese" is not a question anybody should
	// have to answer by opening fifty five records one at a time.
	$countries = wp_get_post_terms( $post_id, 'dw_country', array( 'fields' => 'names' ) );

	return array(
		'type'       => daw_get_type( $post_id ),
		'country'    => is_wp_error( $countries ) ? array() : $countries,
		'words'      => str_word_count( $plain ),
		'stand'      => '' !== trim( (string) daw_field_raw( $post_id, 'daw_guide_standfirst' ) ),
		'native'     => '' !== trim( (string) daw_field_raw( $post_id, 'daw_guide_native' ) ),
		'glance'     => $rows( 'daw_glance_label' ),
		'sources'    => $rows( 'daw_guide_source_name' ),
		'faq'        => $rows( 'daw_faq_q' ),
		'onscreen'   => $rows( 'daw_onscreen_title' ),
		'shop'       => $rows( 'daw_shop_title' ),
		'image'      => has_post_thumbnail( $post_id ),
		'elementor'  => '' !== (string) get_post_meta( $post_id, '_elementor_edit_mode', true ),
		// The old articles all carry their title again as an H1 inside the body,
		// which is a second H1 on the page and a sign the record has not been
		// touched yet.
		'innerh1'    => (bool) preg_match( '#<h1\b#i', $body ),
		'modified'   => (int) get_post_modified_time( 'U', true, $post_id ),
		'status'     => get_post_status( $post_id ),
	);
}

/** Done means it has the four things a guide cannot be published without. */
function daw_guide_is_done( array $s ) {
	return $s['stand'] && $s['sources'] >= 1 && $s['glance'] >= 3 && ! $s['elementor'] && ! $s['innerh1'];
}

add_action( 'admin_menu', 'daw_guide_report_menu' );
function daw_guide_report_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: Guides', 'daw' ),
		__( 'DAW: Guides', 'daw' ),
		'manage_options',
		'daw-guides',
		'daw_guide_report_page'
	);
}

function daw_guide_report_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$ids   = daw_guide_records();
	$done  = array();
	$todo  = array();
	$count = array();

	foreach ( $ids as $id ) {
		$state = daw_guide_state( $id );

		$count[ $state['type'] ] = ( $count[ $state['type'] ] ?? 0 ) + 1;

		if ( daw_guide_is_done( $state ) ) {
			$done[ $id ] = $state;
		} else {
			$todo[ $id ] = $state;
		}
	}

	echo '<div class="wrap"><h1>DAW: Guides</h1>';

	$by_type = array();
	foreach ( $count as $type => $n ) {
		$by_type[] = esc_html( $type . ': ' . $n );
	}

	printf(
		'<p><strong>%1$d</strong> guide record(s) (%2$s). <strong>%3$d</strong> rewritten, <strong>%4$d</strong> still to do.</p>',
		count( $ids ),
		implode( ', ', $by_type ),
		count( $done ),
		count( $todo )
	);
	echo '<p>A record counts as rewritten when it has a standfirst, at least three rows in the fact table, at least one source, no page-builder data and no second H1 inside the body.</p>';

	foreach ( array(
		array( __( 'Still to do', 'daw' ), $todo ),
		array( __( 'Rewritten', 'daw' ), $done ),
	) as $group ) {
		list( $heading, $set ) = $group;

		if ( ! $set ) {
			continue;
		}

		printf( '<h2>%s <span style="font-weight:400">(%d)</span></h2>', esc_html( $heading ), count( $set ) );
		echo '<table class="widefat striped"><thead><tr>'
			. '<th>Record</th><th>Type</th><th>Country</th><th>Words</th><th>Stand</th><th>Glance</th><th>Sources</th>'
			. '<th>FAQ</th><th>On screen</th><th>Image</th><th>Builder</th><th>Inner H1</th><th>Updated</th>'
			. '</tr></thead><tbody>';

		$tick = static function ( $on ) {
			return $on ? '<span style="color:#1a7f37">yes</span>' : '<span style="color:#b32d2e">no</span>';
		};

		foreach ( $set as $id => $s ) {
			printf(
				'<tr><td><a href="%1$s">%2$s</a><br><small>%3$s</small></td><td>%4$s</td><td>%15$s</td><td>%5$d</td><td>%6$s</td>'
				. '<td>%7$d</td><td>%8$d</td><td>%9$d</td><td>%10$d</td><td>%11$s</td><td>%12$s</td><td>%13$s</td><td>%14$s</td></tr>',
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html( get_the_title( $id ) ),
				esc_html( (string) get_post_field( 'post_name', $id ) ),
				esc_html( $s['type'] ),
				(int) $s['words'],
				wp_kses_post( $tick( $s['stand'] ) ),
				(int) $s['glance'],
				(int) $s['sources'],
				(int) $s['faq'],
				(int) $s['onscreen'],
				wp_kses_post( $tick( $s['image'] ) ),
				$s['elementor'] ? '<span style="color:#b32d2e">yes</span>' : '<span style="color:#1a7f37">no</span>',
				$s['innerh1'] ? '<span style="color:#b32d2e">yes</span>' : '<span style="color:#1a7f37">no</span>',
				esc_html( gmdate( 'j M Y', $s['modified'] ) ),
				$s['country']
					? esc_html( implode( ', ', $s['country'] ) )
					: '<span style="color:#b32d2e">none</span>'
			);
		}

		echo '</tbody></table>';
	}

	echo '</div>';
}
