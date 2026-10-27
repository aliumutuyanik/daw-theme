<?php
/**
 * One-shot cleanup: the four invented narrative panels.
 *
 * WHAT IS WRONG
 * -------------
 * Four of the deep-dive panels were filled, on records nobody had checked, by
 * an earlier automated pass that wrote text nothing supports:
 *
 *   daw_best_scenes  ·  daw_filming_location
 *   daw_visual_design ·  daw_real_life_loves
 *
 * The invented text is identifiable. Every one of those writes carries the
 * same editor residue — a span with the class string `relative -mx-px` — which
 * no hand-written entry has, because nobody types that. The fingerprint is
 * therefore an exact marker for "written by that pass", and a record somebody
 * has since filled in by hand does not match it.
 *
 * WHY THIS IS WORSE THAN THE ALHAMBRA BODY
 * ----------------------------------------
 * The Alhambra body was invisible: the templates never printed post_content.
 * These four are printed, with headings, on the page. Among them:
 *
 * - Filming locations that name real places — Bukchon Hanok Village, Namsan
 *   Tower, Cheonggyecheon — for productions whose locations are documented
 *   nowhere at all. A reader can travel to those places expecting to stand
 *   where a scene was shot.
 * - "Real-life loves on set": unsourced claims about whether named, living
 *   actors were romantically involved with each other. That is the single
 *   worst thing a reference site can publish, and it was rendering on every
 *   untouched title record.
 *
 * So this migration deletes rather than rewrites. A missing panel is a page
 * that does not claim to know; a filled one is a page that claims wrongly.
 * Nothing is lost: the old values go to the option daw_panels_backup_v2 first.
 *
 * WHAT IT WILL NOT TOUCH
 * ----------------------
 * Any panel whose text lacks the fingerprint. Every panel written or rewritten
 * by hand — the sourced filming-location notes, the sourced scene lists — is
 * outside the WHERE clause and stays exactly as it is.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * The editor residue that marks the invented writes. Nobody types this.
 *
 * A second marker travels with it: a zero-width space, U+200B, left at the end
 * of each bullet. It survives in places where the class attribute does not,
 * because wp_kses strips the class on output while the invisible character
 * goes through untouched. That is why a page can look clean to a scan for the
 * class string and still be printing invented text: check both.
 */
const DAW_PANEL_FINGERPRINT = 'relative -mx-px';

/** The invisible companion marker. Deliberately not used in the WHERE clause:
 *  a zero-width space can legitimately appear in pasted Korean text, so it is
 *  a hint for a human checking a record, not a safe basis for deletion. */
const DAW_PANEL_FINGERPRINT_ZWSP = "\xE2\x80\x8B";

/**
 * The keys involved.
 *
 * WHY SIX AND NOT FOUR
 * --------------------
 * The first version of this file listed only the four panels whose invented
 * text was obviously dangerous. Checking a record by hand afterwards found the
 * same fingerprint in two more: daw_themes_messages and daw_interesting_facts.
 * The pass that wrote the four wrote these as well, so leaving them out meant
 * the migration cleaned most of a record and left the rest.
 *
 * They are less alarming than invented filming locations, but they are not
 * harmless: Undercover High School carried an "interesting fact" asserting
 * things about named living actors that nothing supports, and a themes panel
 * of generic sentences that would fit any drama ever made.
 */
function daw_invented_panel_keys() {
	return array(
		'daw_best_scenes',
		'daw_filming_location',
		'daw_visual_design',
		'daw_real_life_loves',
		'daw_themes_messages',
		'daw_interesting_facts',
		// daw_awards joined the list last, after two records that looked clean
		// turned out to be carrying a fingerprinted awards panel. It is not
		// printed by the template any more (the awards TABLE replaced it), so
		// nobody would have seen it on the page and nobody would have found it
		// without reading the meta. Invisible wrong data is still wrong data,
		// and it still goes into exports, feeds and anything that reads meta
		// directly.
		'daw_awards',
	);
}

add_action( 'admin_init', 'daw_migrate_invented_panels' );

/**
 * Run once, then never again.
 *
 * Hooked to admin_init rather than to activation, because the plugin is
 * deployed by updating in place and an activation hook would not fire.
 */
function daw_migrate_invented_panels() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}
	// VERSION IN THE FLAG, ON PURPOSE
	// -------------------------------
	// The first cut of this migration covered four keys. This one covers six.
	// A site that had already run the four-key version would find the old flag
	// set and skip the two new keys forever, so the flag is versioned and the
	// wider pass runs once more. The extra work is a single indexed query on
	// a site that is already clean.
	if ( get_option( 'daw_panels_migrated_v2' ) ) {
		return;
	}

	global $wpdb;

	$keys         = daw_invented_panel_keys();
	$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

	$sql = "SELECT meta_id, post_id, meta_key, meta_value
	          FROM {$wpdb->postmeta}
	         WHERE meta_key IN ( {$placeholders} )
	           AND meta_value LIKE %s";

	$args   = $keys;
	$args[] = '%' . $wpdb->esc_like( DAW_PANEL_FINGERPRINT ) . '%';

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB

	if ( ! $rows ) {
		update_option( 'daw_panels_migrated_v2', array( 'when' => time(), 'count' => 0 ), false );
		return;
	}

	// Back up before deleting anything. Keyed by meta_id so a post with more
	// than one affected panel keeps all of them, distinctly.
	$backup = array();
	foreach ( $rows as $row ) {
		$backup[ (int) $row->meta_id ] = array(
			'post_id' => (int) $row->post_id,
			'key'     => $row->meta_key,
			'value'   => $row->meta_value,
		);
	}
	update_option( 'daw_panels_backup_v2', $backup, false );

	$done  = 0;
	$posts = array();
	foreach ( $rows as $row ) {
		// delete_post_meta with the exact value, so that a second panel on the
		// same post under the same key is never taken out by a broad delete.
		if ( delete_post_meta( (int) $row->post_id, $row->meta_key, $row->meta_value ) ) {
			$done++;
			$posts[ (int) $row->post_id ] = true;
		}
	}

	// The per-record caches hold the rendered panels. Drop them, or the pages
	// keep serving the text that has just been deleted from under them.
	foreach ( array_keys( $posts ) as $post_id ) {
		clean_post_cache( $post_id );
	}

	update_option(
		'daw_panels_migrated_v2',
		array(
			'when'    => time(),
			'count'   => $done,
			'posts'   => count( $posts ),
			'version' => defined( 'DAW_VERSION' ) ? DAW_VERSION : '',
		),
		false
	);
}
