<?php
/**
 * What is left to do to the cast lists.
 *
 * WHY A REPORT AND NOT A BUTTON
 * -----------------------------
 * The cast on a record arrives in two states. Either someone has filed the
 * people under Main, Supporting and Guest with their character names, or the
 * record still carries the flat list the old tag-driven widget left behind: no
 * billing, no characters, just names in the order the tags happened to be in.
 *
 * The template now prints the tiers first and the leftovers last, so no record
 * opens with a supporting actor under the word "Cast" any more. But the
 * leftovers are still leftovers, and nothing here can promote them: billing
 * order is a fact about a production, and this plugin has no source for it. A
 * button that guessed would put the wrong face first on hundreds of pages.
 *
 * So this screen says, honestly, which records still need a person to look at
 * them and in what order to work through them: the ones with no tiers at all
 * first, because those are the pages where the cast section is entirely
 * unbilled, then the half-done ones.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** One record's cast state. */
function daw_cast_state( $post_id ) {
	$untiered = (array) get_post_meta( $post_id, 'daw_cast_person', true );
	$untiered = array_filter( array_map( 'intval', $untiered ) );

	$tiered = array();
	foreach ( array( 'main', 'supporting', 'guest' ) as $tier ) {
		foreach ( (array) daw_get_cast( $post_id, $tier ) as $row ) {
			$tiered[ (int) $row['id'] ] = true;
		}
	}

	// Someone already billed is not outstanding work, whatever the flat list
	// still says, because the template drops them from it.
	$left = array_diff( $untiered, array_keys( $tiered ) );

	return array(
		'tiered'   => count( $tiered ),
		'untiered' => count( $left ),
	);
}

add_action( 'admin_menu', 'daw_cast_report_menu' );
function daw_cast_report_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: Cast tiering', 'daw' ),
		__( 'DAW: Cast tiering', 'daw' ),
		'manage_options',
		'daw-cast',
		'daw_cast_report_page'
	);
}

function daw_cast_report_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => array( 'drama', 'film' ),
				),
			),
		)
	);

	$none    = array();
	$partial = array();
	$done    = 0;
	$empty   = 0;

	foreach ( $ids as $id ) {
		$state = daw_cast_state( $id );

		if ( ! $state['tiered'] && ! $state['untiered'] ) {
			$empty++;
			continue;
		}

		if ( ! $state['tiered'] ) {
			$none[ $id ] = $state;
			continue;
		}

		if ( $state['untiered'] ) {
			$partial[ $id ] = $state;
			continue;
		}

		$done++;
	}

	echo '<div class="wrap"><h1>DAW: Cast tiering</h1>';
	printf(
		'<p><strong>%1$d</strong> title record(s). <strong>%2$d</strong> are fully billed, <strong>%3$d</strong> have names but no billing at all, <strong>%4$d</strong> are part way, and <strong>%5$d</strong> have no cast on them yet.</p>',
		count( $ids ),
		$done,
		count( $none ),
		count( $partial ),
		$empty
	);
	echo '<p>Billing order is a fact about a production and this plugin has no source for it, so nothing here promotes anyone automatically. Work down the first list: those records show their whole cast under "Also in the cast", which is the honest label but not the one a reader wants.</p>';

	foreach ( array(
		array( __( 'No billing at all', 'daw' ), $none ),
		array( __( 'Part way', 'daw' ), $partial ),
	) as $group ) {
		list( $heading, $rows ) = $group;

		if ( ! $rows ) {
			continue;
		}

		printf( '<h2>%s <span style="font-weight:400">(%d)</span></h2>', esc_html( $heading ), count( $rows ) );
		echo '<table class="widefat striped"><thead><tr><th>Record</th><th>Billed</th><th>Unbilled</th></tr></thead><tbody>';

		foreach ( array_slice( $rows, 0, 200, true ) as $id => $state ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%d</td><td>%d</td></tr>',
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html( get_the_title( $id ) ),
				(int) $state['tiered'],
				(int) $state['untiered']
			);
		}

		echo '</tbody></table>';

		if ( count( $rows ) > 200 ) {
			printf( '<p>%s</p>', esc_html( sprintf( 'Showing the first 200 of %d.', count( $rows ) ) ) );
		}
	}

	echo '</div>';
}
