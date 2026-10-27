<?php
/**
 * Discover Asian World — which records have actually been audited.
 *
 * WHY A STAMP
 * -----------
 * Everything else in the audit measures the shape of a record: is there a
 * synopsis, are there ten questions, is the title under sixty characters. A
 * record can pass all of that and still carry a wrong date, an invented quote
 * or a paragraph lifted from a source. The only thing that catches those is
 * somebody going through the 53-point list with the sources open.
 *
 * So when that has been done, the record is stamped. The stamp is the answer
 * to "is this ready to go live", and the shape score is only the answer to
 * "is anything obviously missing". The dashboard now leads with the first.
 *
 * WHAT MAKES A STAMP GO STALE
 * ---------------------------
 * An edit. If the post is saved after it was stamped, the audit no longer
 * describes what is on the page, and the record goes back into the queue as
 * "re-check" rather than "done". Meta written through the API does not touch
 * post_modified, so the stamp is always written last, after the save that
 * refreshes the indexes.
 *
 * WHAT IT DOES NOT COVER
 * ----------------------
 * Cast completeness. A performer with no record here cannot be linked, and
 * that work is tracked separately by the cast report. A record can be
 * verified with two billed names and still appear in the cast queue.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_VERIFIED_KEY = 'daw_verified';

/**
 * State of one record: 'verified', 'stale', 'open' (audited, items outstanding)
 * or 'none'.
 *
 * The stamp is an array: at (unix), dolu, yok, eksik (counts from the
 * checklist), list (checklist name), note.
 */
function daw_verify_state( $post_id ) {
	$s = get_post_meta( $post_id, DAW_VERIFIED_KEY, true );

	if ( ! is_array( $s ) || empty( $s['at'] ) ) {
		return 'none';
	}

	if ( ! empty( $s['eksik'] ) ) {
		return 'open';
	}

	// A stamp carries a fingerprint of what was audited, and it goes stale only
	// when that changes. The first version compared post_modified instead, and
	// on the first evening thirty-four audited pages showed as "edited since":
	// deleting a hidden backup field through the API bumps post_modified, and
	// nothing a reader sees had changed. A clock cannot tell those apart; the
	// content can.
	if ( ! empty( $s['hash'] ) ) {
		return hash_equals( (string) $s['hash'], daw_verify_hash( $post_id ) ) ? 'verified' : 'stale';
	}

	$modified = (int) get_post_modified_time( 'U', true, $post_id );
	if ( $modified > (int) $s['at'] + 120 ) {
		return 'stale';
	}

	return 'verified';
}

/**
 * Fingerprint of everything a reader is shown: title, body, excerpt and every
 * public daw_ field. Hidden underscore meta (caches, indexes, backups) and the
 * stamp itself are left out, so housekeeping never breaks a stamp.
 */
function daw_verify_hash( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return '';
	}
	$meta = array();
	foreach ( (array) get_post_meta( $post_id ) as $k => $v ) {
		if ( 0 === strpos( $k, 'daw_' ) && DAW_VERIFIED_KEY !== $k ) {
			$meta[ $k ] = $v;
		}
	}
	ksort( $meta );
	return md5( $post->post_title . "\n" . $post->post_content . "\n" . $post->post_excerpt . "\n" . serialize( $meta ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
}

/**
 * Agents write the stamp through the API and cannot compute the fingerprint,
 * so the site adds it the moment the stamp arrives.
 */
add_action( 'added_post_meta', 'daw_verify_add_hash', 5, 4 );
add_action( 'updated_post_meta', 'daw_verify_add_hash', 5, 4 );
function daw_verify_add_hash( $meta_id, $post_id, $meta_key, $value ) {
	static $busy = false;
	if ( $busy || DAW_VERIFIED_KEY !== $meta_key ) {
		return;
	}
	$v = maybe_unserialize( $value );
	if ( ! is_array( $v ) || ! empty( $v['hash'] ) ) {
		return;
	}
	$v['hash'] = daw_verify_hash( $post_id );
	$busy      = true;
	update_post_meta( $post_id, DAW_VERIFIED_KEY, $v );
	$busy      = false;
}

/**
 * One-time: give every stamp written before fingerprints existed the
 * fingerprint of its record as it stands. Everything that changed those
 * records after they were stamped on 23 September was the audit's own
 * housekeeping (backup deletions, cross-links between audited pages), so the
 * current content is the audited content.
 */
add_action( 'admin_init', 'daw_verify_hash_backfill_v1', 35 );
function daw_verify_hash_backfill_v1() {
	if ( get_option( 'daw_verify_hash_v1' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_VERIFIED_KEY ) ) ); // phpcs:ignore WordPress.DB
	// Site rule 8.1: bulk work in batches of at most 50. Each admin page load
	// does one batch; the flag is only set once nothing is left.
	$n = 0;
	foreach ( $ids as $id ) {
		$s = get_post_meta( $id, DAW_VERIFIED_KEY, true );
		if ( is_array( $s ) && empty( $s['hash'] ) ) {
			$s['hash'] = daw_verify_hash( $id );
			update_post_meta( $id, DAW_VERIFIED_KEY, $s );
			if ( ++$n >= 50 ) {
				return;
			}
		}
	}
	update_option( 'daw_verify_hash_v1', array( 'when' => time() ), false );
	if ( function_exists( 'daw_snapshot_refresh' ) ) {
		daw_snapshot_refresh();
	}
}

/**
 * Archive-wide tally, by the three groups the completion figure uses.
 *
 * One meta query rather than a read per record: the stamp only exists on the
 * records that have one, which for a long time will be the small minority.
 */
function daw_verify_tally() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}

	global $wpdb;

	$ids = array_map(
		'intval',
		(array) $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_VERIFIED_KEY )
		)
	);

	$groups = array(
		'titles'   => array( 'drama', 'film', 'variety' ),
		'people'   => array( 'person', 'group' ),
		'articles' => function_exists( 'daw_audit_article_types' ) ? daw_audit_article_types() : array(),
	);

	$out = array(
		'verified' => 0,
		'stale'    => array(),
		'open'     => array(),
		'recent'   => array(),
		'groups'   => array(),
	);

	foreach ( $groups as $g => $types ) {
		$out['groups'][ $g ] = 0;
	}

	$type_of = array();
	foreach ( $groups as $g => $types ) {
		foreach ( $types as $t ) {
			$type_of[ $t ] = $g;
		}
	}

	$recent = array();

	foreach ( $ids as $id ) {
		if ( 'publish' !== get_post_status( $id ) ) {
			continue;
		}

		$state = daw_verify_state( $id );
		$type  = function_exists( 'daw_get_type' ) ? daw_get_type( $id ) : '';
		$group = $type_of[ $type ] ?? '';

		if ( 'verified' === $state ) {
			$out['verified']++;
			if ( $group ) {
				$out['groups'][ $group ]++;
			}
			$s        = get_post_meta( $id, DAW_VERIFIED_KEY, true );
			$recent[] = array(
				'id'    => $id,
				'at'    => (int) $s['at'],
				'title' => get_the_title( $id ),
				'type'  => $type,
				'yok'   => (int) ( $s['yok'] ?? 0 ),
				'note'  => (string) ( $s['note'] ?? '' ),
			);
		} elseif ( 'stale' === $state ) {
			$out['stale'][] = $id;
		} elseif ( 'open' === $state ) {
			$out['open'][] = $id;
		}
	}

	usort( $recent, static fn( $a, $b ) => $b['at'] <=> $a['at'] );
	$out['recent'] = $recent;

	$memo = $out;

	return $memo;
}

/**
 * Refresh the dashboard shortly after a record is stamped.
 *
 * The snapshot rebuilds twice a day, which is fine for the shape counts and
 * wrong for this: the point of stamping records one at a time is to watch the
 * number move one at a time. So a stamp books a rebuild a minute later. It is
 * booked once — ten stamps in a minute produce one rebuild, not ten, because
 * each rebuild walks the whole archive.
 */
add_action( 'added_post_meta', 'daw_verify_touch', 10, 3 );
add_action( 'updated_post_meta', 'daw_verify_touch', 10, 3 );
function daw_verify_touch( $meta_id, $post_id, $meta_key ) {
	if ( DAW_VERIFIED_KEY !== $meta_key ) {
		return;
	}

	if ( ! wp_next_scheduled( 'daw_snapshot_soon' ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'daw_snapshot_soon' );
	}
}

add_action( 'daw_snapshot_soon', 'daw_verify_rebuild' );
function daw_verify_rebuild() {
	if ( function_exists( 'daw_snapshot_refresh' ) ) {
		daw_snapshot_refresh();
	}
}
