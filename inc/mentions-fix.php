<?php
/**
 * Rebuild the "mentioned in this story" index for every news record.
 *
 * WHAT WAS BROKEN
 * ---------------
 * A news story records which dramas, films and people it is about, and every
 * one of those records shows the story under "In the news". That lookup does
 * not read the list itself, because the list is a serialised array and finding
 * an id inside one across three thousand records is a table scan. It reads a
 * flat index written when the story is saved.
 *
 * The index is written on save_post. Anything set another way, by an import, by
 * a bulk edit, or by a tool talking to the database, never triggered a save, so
 * the story kept its list and the index stayed empty. The result is a record
 * that is named in a story and a story that never appears on it. BTS was the
 * one that showed it: the military service story names BTS, the BTS page said
 * there was no news.
 *
 * This walks every news record once and writes the index from the list that is
 * already there. It touches one hidden key, it invents nothing, and it runs
 * once.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_MENTIONS_FIX_RUN = '2026-09-19-index';

add_action( 'admin_init', 'daw_mentions_fix', 40 );
function daw_mentions_fix() {
	if ( get_option( 'daw_mentions_fix' ) === DAW_MENTIONS_FIX_RUN ) {
		return;
	}

	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array(
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => 'news',
				),
			),
		)
	);

	$written = 0;

	foreach ( $ids as $id ) {
		$list = get_post_meta( $id, 'daw_mentions', true );
		$list = array_values( array_filter( array_map( 'intval', (array) $list ) ) );

		if ( ! $list ) {
			delete_post_meta( $id, '_daw_mentions_idx' );
			continue;
		}

		$want = ',' . implode( ',', $list ) . ',';

		if ( (string) get_post_meta( $id, '_daw_mentions_idx', true ) !== $want ) {
			update_post_meta( $id, '_daw_mentions_idx', $want );
			$written++;
		}
	}

	update_option( 'daw_mentions_fix', DAW_MENTIONS_FIX_RUN, false );
	update_option( 'daw_mentions_fix_n', $written, false );

	if ( $written ) {
		set_transient( 'daw_mentions_fix_notice', $written, 5 * MINUTE_IN_SECONDS );
	}
}

add_action( 'admin_notices', 'daw_mentions_fix_notice' );
function daw_mentions_fix_notice() {
	$n = get_transient( 'daw_mentions_fix_notice' );

	if ( ! $n ) {
		return;
	}

	delete_transient( 'daw_mentions_fix_notice' );

	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: how many stories were reindexed */
				_n(
					'DAW: %s news story was naming a record that could not see it. Its index has been rebuilt and the story now appears under In the news.',
					'DAW: %s news stories were naming records that could not see them. Their indexes have been rebuilt and the stories now appear under In the news.',
					(int) $n,
					'daw'
				),
				number_format_i18n( (int) $n )
			)
		)
	);
}
