<?php
/**
 * Madde 64: every sentence on the site is the site's own.
 *
 * WHY
 * ---
 * The owner's rule (24 Sep 2026): the site is to be 99 percent original. No
 * two pages share a sentence, and nothing is lifted from a source: a source
 * gives facts, the sentence is written here, in a shape that also answers
 * what people search for.
 *
 * What a machine can check is the first half: the same sentence on two
 * records. An index of every longer sentence on the site (eight words or
 * more, normalised) is rebuilt once a day from one database query, and the
 * audit looks up each sentence of the record it is checking. A sentence that
 * also sits on another record is a red line naming that record.
 *
 * Copying from outside the site cannot be measured here; that half is a
 * writing rule (the agent brief and the rulebook say so), checked by reading.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_ORIG_OPT = 'daw_orig_index';

/** The long text fields whose sentences are indexed. */
function daw_orig_fields() {
	return array(
		'daw_synopsis', 'daw_why_watched', 'daw_title_section_body', 'daw_takeaways', 'daw_faq_a', 'daw_ep_synopsis',
		'daw_best_scenes', 'daw_filming_location', 'daw_visual_design', 'daw_themes_messages', 'daw_interesting_facts', 'daw_remakes',
		'daw_biography', 'daw_person_section_body', 'daw_person_takeaways', 'daw_pfaq_a', 'daw_person_summary', 'daw_relationships',
	);
}

/** Sentences of eight words or more, normalised, as short hashes. */
function daw_orig_hashes( $text ) {
	$text = html_entity_decode( wp_strip_all_tags( str_replace( array( '</p>', '<br', '</li>' ), array( "</p>\n", "\n<br", "</li>\n" ), (string) $text ) ), ENT_QUOTES, 'UTF-8' );
	$out  = array();
	foreach ( preg_split( '/(?<=[.!?])\s+|\n+/u', $text ) as $s ) {
		$n = trim( preg_replace( '/\s+/u', ' ', preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower( $s ) ) ) );
		if ( '' === $n || count( explode( ' ', $n ) ) < 8 ) {
			continue;
		}
		$out[ substr( md5( $n ), 0, 12 ) ] = $s;
	}
	return $out;
}

/** Every value of the indexed fields for one record, flattened. */
function daw_orig_record_text( $post_id ) {
	$parts = array();
	foreach ( daw_orig_fields() as $f ) {
		foreach ( get_post_meta( $post_id, $f ) as $v ) {
			$v       = maybe_unserialize( $v );
			$parts[] = is_array( $v ) ? implode( "\n", array_map( 'strval', $v ) ) : (string) $v;
		}
	}
	return implode( "\n", $parts );
}

/** Rebuild the site-wide index: hash => [post ids]. One query. */
function daw_orig_build() {
	global $wpdb;
	$keys = "'" . implode( "','", array_map( 'esc_sql', daw_orig_fields() ) ) . "'";
	$rows = $wpdb->get_results( "SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_status = 'publish' AND p.post_type = 'post' AND m.meta_key IN ($keys) AND m.meta_value <> ''" ); // phpcs:ignore WordPress.DB
	$idx  = array();
	foreach ( (array) $rows as $r ) {
		$v    = maybe_unserialize( $r->meta_value );
		$text = is_array( $v ) ? implode( "\n", array_map( 'strval', $v ) ) : (string) $v;
		foreach ( array_keys( daw_orig_hashes( $text ) ) as $h ) {
			$idx[ $h ][ (int) $r->post_id ] = 1;
		}
	}
	// Every sentence is kept (hash => ids), so a sentence copied today from a
	// record indexed yesterday is caught on the copying record's next audit.
	$all = array();
	foreach ( $idx as $h => $ids ) {
		$all[ $h ] = implode( ',', array_keys( $ids ) );
	}
	update_option( DAW_ORIG_OPT, array( 'at' => time(), 'h' => $all ), false );
	return $all;
}

add_action( 'daw_ae_cron', 'daw_orig_cron', 5 );
function daw_orig_cron() {
	$i = get_option( DAW_ORIG_OPT );
	if ( ! is_array( $i ) || (int) ( $i['at'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
		daw_orig_build();
	}
}

/**
 * Audit rows for one record: sentences it shares with other records.
 * Checks against the stored index and, for the record's own current text,
 * against the other records' stored text for the hashes that repeat.
 */
function daw_orig_rules( $post_id ) {
	$i = get_option( DAW_ORIG_OPT );
	if ( ! is_array( $i ) ) {
		$i = array( 'h' => daw_orig_build() );
	}
	$all   = (array) ( $i['h'] ?? array() );
	$mine  = daw_orig_hashes( daw_orig_record_text( $post_id ) );
	$clash = array();
	foreach ( $mine as $h => $sentence ) {
		foreach ( explode( ',', (string) ( $all[ $h ] ?? '' ) ) as $other ) {
			if ( (int) $other && (int) $other !== (int) $post_id ) {
				$clash[] = array( $sentence, (int) $other );
				break;
			}
		}
	}
	if ( ! $clash ) {
		return array( array( 'Özgünlük (madde 64)', count( $mine ) . ' cümle', 'ok', '' ) );
	}
	$first = $clash[0];
	return array(
		array(
			'Özgünlük (madde 64)',
			count( $clash ) . ' cümle başka kayıtta da var',
			'no',
			sprintf( 'Örnek: "%s" aynı zamanda %s kaydında. Her kayıt kendi cümleleriyle yazılır.', mb_substr( trim( $first[0] ), 0, 90 ), get_the_title( $first[1] ) ),
		),
	);
}
