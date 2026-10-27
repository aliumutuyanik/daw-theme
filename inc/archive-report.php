<?php
/**
 * "Why is it not finished": one pass over every record, read back over REST.
 *
 * WHY (8 Oct 2026, Umut)
 * ----------------------
 * The Whole archive screen says 271 of 1,198 records are finished and shows
 * the reasons only inside wp-admin, computed live, and the cast is kept out of
 * the total on purpose (audit-wide.php). Nobody could ask "what is missing,
 * by group, and which of those records are also held out of Google" without
 * opening the screen. This stores one row per record and answers that.
 *
 * WHAT IT DOES
 * Reads the same scores the screen reads (daw_audit_record_score for titles,
 * daw_audit_person_score for people and groups), plus the gate reason
 * (_daw_thin) and the TMDB cast gap the coverage job already stored
 * (daw_cov_rec). It writes nothing to any record: only two options of its
 * own, the rows and the summary.
 *
 * HOW
 * 50 records a call (rule 8.1). GET /daw/v1/archive?run=1 takes the next 50;
 * when the lap ends the summary is rebuilt. GET /daw/v1/archive reads it back.
 * GET /daw/v1/archive?group=titles&missing=<label> lists the ids behind one
 * reason. A cron tick runs one batch an hour so the figures stay current
 * without anybody asking.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

const DAW_ARC_BATCH = 50;

/** The record types this report covers, keyed by the group they report under. */
function daw_arc_groups() {
	return array(
		'titles' => array( 'drama', 'film', 'variety' ),
		'people' => array( 'person', 'group' ),
	);
}

/** Every record id in report order. */
function daw_arc_ids() {
	$all = array();
	foreach ( daw_arc_groups() as $types ) {
		foreach ( (array) daw_audit_record_ids( $types ) as $id ) {
			$all[] = (int) $id;
		}
	}
	return $all;
}

/** "FAQ 7/10" and "Synopsis 80 words" group under one label each. */
function daw_arc_label( $m ) {
	$m = (string) $m;
	if ( 0 === strpos( $m, 'FAQ' ) ) {
		return 'FAQ';
	}
	$k = trim( (string) preg_replace( '/\s+\d.*$/', '', $m ) );
	return '' === $k ? $m : $k;
}

/** One record's row: type, score, what is missing, gate reason, TMDB cast gap. */
function daw_arc_row( $id, array $cov = array() ) {
	$id    = (int) $id;
	$type  = function_exists( 'daw_get_type' ) ? (string) daw_get_type( $id ) : '';
	$state = in_array( $type, array( 'person', 'group' ), true ) ? daw_audit_person_score( $id ) : daw_audit_record_score( $id );

	return array(
		$type,
		(int) $state['score'],
		array_values( array_map( 'daw_arc_label', (array) $state['missing'] ) ),
		(string) get_post_meta( $id, '_daw_thin', true ),
		isset( $cov[ $id ][4] ) ? (int) $cov[ $id ][4] : -1,
	);
}

/**
 * Score the next batch of records and store their rows.
 *
 * @return array{pos:int,total:int,done:bool}
 */
function daw_arc_step( $n = DAW_ARC_BATCH ) {
	if ( ! function_exists( 'daw_audit_record_ids' ) || ! function_exists( 'daw_audit_record_score' ) || ! function_exists( 'daw_audit_person_score' ) ) {
		return array( 'pos' => 0, 'total' => 0, 'done' => false );
	}

	$n     = max( 1, min( DAW_ARC_BATCH, (int) $n ) );
	$ids   = daw_arc_ids();
	$total = count( $ids );
	$pos   = (int) get_option( 'daw_arc_pos', 0 );
	if ( $pos >= $total ) {
		$pos = 0;
	}

	$rows = (array) get_option( 'daw_arc_rows', array() );
	$cov  = (array) get_option( 'daw_cov_rec', array() );

	foreach ( array_slice( $ids, $pos, $n ) as $id ) {
		$rows[ $id ] = daw_arc_row( $id, $cov );
	}

	$pos  += $n;
	$done  = $pos >= $total;
	if ( $done ) {
		$keep = array_flip( $ids );
		$rows = array_intersect_key( $rows, $keep );
		update_option( 'daw_arc_sum', daw_arc_summary( $rows ), false );
		$pos = 0;
	}

	update_option( 'daw_arc_rows', $rows, false );
	update_option( 'daw_arc_pos', $pos, false );

	return array( 'pos' => $pos, 'total' => $total, 'done' => $done );
}

/** Totals per group: finished, held, and the reasons the rest are not. */
function daw_arc_summary( array $rows ) {
	$sum = array(
		'at'     => time(),
		'groups' => array(),
	);

	foreach ( daw_arc_groups() as $g => $types ) {
		$t = array(
			'total'          => 0,
			'complete'       => 0,
			'held'           => 0,
			'complete_held'  => 0,
			'partial_held'   => 0,
			'cast_gap'       => 0,
			'missing'        => array(),
			'held_by_reason' => array(),
		);

		foreach ( $rows as $r ) {
			if ( ! in_array( $r[0], $types, true ) ) {
				continue;
			}
			$t['total']++;
			$complete = 100 === $r[1];
			$held     = '' !== $r[3];

			if ( $complete ) {
				$t['complete']++;
			} else {
				foreach ( $r[2] as $lab ) {
					$t['missing'][ $lab ] = ( $t['missing'][ $lab ] ?? 0 ) + 1;
				}
			}
			if ( $held ) {
				$t['held']++;
				$t[ $complete ? 'complete_held' : 'partial_held' ]++;
				$why = (string) preg_replace( '/\d+/', 'N', $r[3] );
				$t['held_by_reason'][ $why ] = ( $t['held_by_reason'][ $why ] ?? 0 ) + 1;
			}
			if ( $r[4] > 0 ) {
				$t['cast_gap']++;
			}
		}

		arsort( $t['missing'] );
		arsort( $t['held_by_reason'] );
		$t['partial'] = $t['total'] - $t['complete'];
		$sum['groups'][ $g ] = $t;
	}

	return $sum;
}

/** Ids behind one reason, for one group. */
function daw_arc_list( $group, $label ) {
	$groups = daw_arc_groups();
	if ( ! isset( $groups[ $group ] ) ) {
		return array();
	}
	$out = array();
	foreach ( (array) get_option( 'daw_arc_rows', array() ) as $id => $r ) {
		if ( ! in_array( $r[0], $groups[ $group ], true ) ) {
			continue;
		}
		if ( 'held' === $label ? '' !== $r[3] : in_array( $label, $r[2], true ) ) {
			$out[] = array( 'id' => (int) $id, 'score' => $r[1], 'held' => $r[3] );
		}
	}
	return $out;
}

add_action( 'rest_api_init', 'daw_arc_routes' );
function daw_arc_routes() {
	register_rest_route(
		'daw/v1',
		'/archive',
		array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( WP_REST_Request $r ) {
				$out = array();

				if ( $r->get_param( 'reset' ) ) {
					update_option( 'daw_arc_pos', 0, false );
				}
				if ( $r->get_param( 'run' ) ) {
					$out['step'] = daw_arc_step( DAW_ARC_BATCH );
				}
				if ( $r->get_param( 'group' ) && $r->get_param( 'missing' ) ) {
					$out['list'] = daw_arc_list( (string) $r->get_param( 'group' ), (string) $r->get_param( 'missing' ) );
				}

				$out['pos']     = (int) get_option( 'daw_arc_pos', 0 );
				$out['scored']  = count( (array) get_option( 'daw_arc_rows', array() ) );
				$out['summary'] = get_option( 'daw_arc_sum', null );

				return rest_ensure_response( $out );
			},
		)
	);
}

/*
 * A SAVE SCORES THE RECORD AT ONCE (1.75.51)
 * Umut, 8 Oct 2026: a record that was finished a minute ago still showed as
 * unfinished until the hourly lap reached it. Any write to one of our own
 * fields now queues that record; at shutdown its row and the summary are
 * rebuilt, and its stored audit (the one the work screen filters on) is
 * scheduled a few seconds out instead of waiting for the hourly pass.
 * Capped per request so a bulk import cannot turn into a full re-score.
 */
add_action( 'updated_post_meta', 'daw_arc_on_meta', 10, 3 );
add_action( 'added_post_meta', 'daw_arc_on_meta', 10, 3 );
add_action( 'deleted_post_meta', 'daw_arc_on_meta_deleted', 10, 3 );
function daw_arc_on_meta_deleted( $mids, $post_id, $key ) {
	daw_arc_on_meta( 0, $post_id, $key );
}
function daw_arc_on_meta( $mid, $post_id, $key ) {
	static $queued = array();
	if ( ! is_string( $key ) || 0 !== strpos( $key, 'daw_' ) || 0 === strpos( $key, 'daw_arc' ) ) {
		return;
	}
	if ( ! empty( $GLOBALS['daw_arc_busy'] ) ) {
		return; // a re-audit writing its own fills must not queue itself again.
	}
	$post_id = (int) $post_id;
	if ( ! $post_id || isset( $queued[ $post_id ] ) || count( $queued ) >= DAW_ARC_BATCH || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( 'post' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
		return;
	}
	$queued[ $post_id ] = true;

	if ( 1 === count( $queued ) ) {
		add_action(
			'shutdown',
			static function () use ( &$queued ) {
				$rows = (array) get_option( 'daw_arc_rows', array() );
				if ( ! $rows || ! function_exists( 'daw_audit_record_score' ) ) {
					return;
				}
				$cov = (array) get_option( 'daw_cov_rec', array() );
				foreach ( array_keys( $queued ) as $id ) {
					$rows[ $id ] = daw_arc_row( $id, $cov );
					if ( ! wp_next_scheduled( 'daw_ae_one', array( $id ) ) ) {
						wp_schedule_single_event( time() + 20, 'daw_ae_one', array( $id ) );
					}
				}
				update_option( 'daw_arc_rows', $rows, false );
				update_option( 'daw_arc_sum', daw_arc_summary( $rows ), false );
			},
			30
		);
	}
}

/* The re-audit writes fills of its own; keep those from queueing the record again. */
add_action( 'daw_ae_one', static function () { $GLOBALS['daw_arc_busy'] = true; }, 1 );
add_action( 'daw_ae_one', static function () { $GLOBALS['daw_arc_busy'] = false; }, 99 );

/* One batch an hour keeps the figures current without a visit. */
add_action( 'init', 'daw_arc_schedule' );
function daw_arc_schedule() {
	if ( ! wp_next_scheduled( 'daw_arc_tick' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'daw_arc_tick' );
	}
}

add_action( 'daw_arc_tick', 'daw_arc_tick' );
function daw_arc_tick() {
	daw_arc_step( DAW_ARC_BATCH );
}
