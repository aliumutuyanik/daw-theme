<?php
/**
 * One-shot cleanup: the Memories of the Alhambra body that is sitting inside
 * 550 other records.
 *
 * WHAT IS WRONG
 * -------------
 * 550 title records carry a byte-for-byte identical `post_content` of 1,521
 * bytes. It is the promo blurb for Memories of the Alhambra: an <h1> with that
 * drama's name, an <img> of its poster, and three paragraphs naming Hyun Bin
 * and Park Shin Hye. It sits on the Cheese in the Trap record, the Good Doctor
 * record, the Watcher record, and 547 others.
 *
 * The title templates do not print post_content, so none of this is visible on
 * the page. It is still wrong, and not harmlessly so: WordPress search reads
 * post_content, the feeds read it, the excerpt falls back to it when the
 * excerpt is empty, and some schema paths read it. A site that answers a search
 * for "cheese in the trap" with Hyun Bin's name has a data problem, and EEAT is
 * exactly what it costs.
 *
 * WHY A MIGRATION AND NOT 550 EDITS
 * ---------------------------------
 * Because 550 is 550. One pass over the table, one WHERE clause, one audit
 * trail, and a flag so it can never run twice.
 *
 * WHAT IT WILL NOT TOUCH
 * ----------------------
 * - The genuine Memories of the Alhambra record. Its own promo text belongs to
 *   it. Matched by title, not by ID, so it survives a re-slug.
 * - Excerpts. They were checked and they are correct: the Cheese in the Trap
 *   record has a Cheese in the Trap excerpt. Only the body is foreign.
 * - Any body that does not carry the exact fingerprint. A record someone has
 *   since written a real body for is not in the WHERE clause at all.
 *
 * The old body of every row it changes is written to the option
 * daw_alhambra_backup before anything is updated, so the whole operation is
 * reversible from the database without a file restore.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** The sentence that identifies the foreign body. Distinctive, and short. */
const DAW_ALHAMBRA_FINGERPRINT = 'Step into the enchanting world';

/** What a title record's body is supposed to say. */
const DAW_TITLE_BODY = '<!-- Title record. Content lives in the DAW fields and is rendered by single-title.php. -->';

add_action( 'admin_init', 'daw_migrate_alhambra_bodies' );

/**
 * Run once, then never again.
 *
 * Hooked to admin_init rather than to activation, because this plugin is
 * deployed by updating in place and an activation hook would not fire.
 */
function daw_migrate_alhambra_bodies() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}
	if ( get_option( 'daw_alhambra_migrated' ) ) {
		return;
	}

	global $wpdb;

	// The genuine record keeps its own text. Matched on title so that renaming
	// its slug, or the ID changing in a future import, cannot orphan it.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_content
			   FROM {$wpdb->posts}
			  WHERE post_type = 'post'
			    AND post_status IN ( 'publish', 'draft', 'pending', 'private' )
			    AND post_content LIKE %s
			    AND post_title NOT LIKE %s",
			'%' . $wpdb->esc_like( DAW_ALHAMBRA_FINGERPRINT ) . '%',
			'%' . $wpdb->esc_like( 'Memories of the Alhambra' ) . '%'
		)
	);

	if ( ! $rows ) {
		update_option( 'daw_alhambra_migrated', array( 'when' => time(), 'count' => 0 ), false );
		return;
	}

	// Back up before touching anything. One option row, id => old body.
	$backup = array();
	foreach ( $rows as $row ) {
		$backup[ (int) $row->ID ] = $row->post_content;
	}
	update_option( 'daw_alhambra_backup', $backup, false );

	$done = 0;
	foreach ( $rows as $row ) {
		// wp_update_post rather than a direct UPDATE: the save hooks are what
		// rebuild the reverse index and drop the per-record transients, and a
		// body change that skips them leaves the site serving the old copy.
		$ok = wp_update_post(
			array(
				'ID'           => (int) $row->ID,
				'post_content' => DAW_TITLE_BODY,
			),
			true
		);
		if ( ! is_wp_error( $ok ) ) {
			$done++;
		}
	}

	update_option(
		'daw_alhambra_migrated',
		array(
			'when'    => time(),
			'count'   => $done,
			'version' => defined( 'DAW_VERSION' ) ? DAW_VERSION : '',
		),
		false
	);
}
