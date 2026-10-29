<?php
/**
 * "Eksik kadroyu bağla": link the cast pairs TMDB confirms and the site has.
 *
 * WHY (8 Oct 2026, Umut's SEO spec, item 7)
 * -----------------------------------------
 * The weekly TMDB comparison found 468 pairs where the person and the title
 * both have a record on this site and TMDB lists the person in that title's
 * cast, but the two records are not linked. Every one of those is a missing
 * internal link and a missing credit, and the thin page gate (index-gate.php)
 * counts credits: linking them is the fastest way out of the gate.
 *
 * WHAT IT WRITES
 * The person is appended to the title's untiered cast (daw_cast_person), the
 * bucket the filmography prints as "Other credits". TMDB gives no billing
 * tier, and a character name has no field in that bucket, so nothing is
 * guessed: no tier, no role (rules 1.1 and 1.2). An editor promotes the row
 * into Main, Supporting or Guest later, the same as every other untiered row.
 * A person already somewhere in the title's cast is never added twice.
 *
 * HOW
 * 50 pairs a batch (rule 8.1). The cast index is rebuilt for each title with
 * the batch flag set and the filmography cache is flushed once at the end
 * (rule 8.3). The stored TMDB reports are marked "linked" on both sides, so
 * the count on the control centre falls as the batches run.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

const DAW_CASTLINK_BATCH = 50;

/** Every person id already in any of the title's cast buckets. */
function daw_castlink_present( $title_id ) {
	$ids = array();
	foreach ( array( 'daw_cast_person', 'daw_cast_main_person', 'daw_cast_supporting_person', 'daw_cast_guest_person' ) as $k ) {
		foreach ( (array) get_post_meta( (int) $title_id, $k, true ) as $pid ) {
			if ( (int) $pid ) {
				$ids[ (int) $pid ] = true;
			}
		}
	}
	return $ids;
}

/** Mark one pair linked in a stored TMDB report, and keep its counts true. */
function daw_castlink_mark( $report_id, $other_id, $side ) {
	$r = get_post_meta( (int) $report_id, DAW_COV, true );
	if ( ! is_array( $r ) ) {
		return;
	}
	$list    = 'title' === $side ? 'people' : 'works';
	$changed = false;
	foreach ( (array) ( $r[ $list ] ?? array() ) as $i => $row ) {
		if ( (int) ( $row['post'] ?? 0 ) === (int) $other_id && 'unlinked' === ( $row['state'] ?? '' ) && ( 'people' === $list || '' === ( $row['as'] ?? '' ) ) ) {
			$r[ $list ][ $i ]['state'] = 'linked';
			$r['count']['unlinked']    = max( 0, (int) ( $r['count']['unlinked'] ?? 0 ) - 1 );
			$r['count']['linked']      = (int) ( $r['count']['linked'] ?? 0 ) + 1;
			$changed                   = true;
		}
	}
	if ( $changed ) {
		update_post_meta( (int) $report_id, DAW_COV, $r );
	}
}

/** One batch: up to 50 pairs. */
function daw_castlink_batch( $limit = DAW_CASTLINK_BATCH ) {
	if ( ! function_exists( 'daw_covp_aggregate' ) || ! defined( 'DAW_COV' ) ) {
		return array( 'done' => 0, 'left' => 0, 'error' => 'coverage module missing' );
	}
	list( , , $unlinked ) = daw_covp_aggregate( daw_covp_reports() );
	// Pairs this module will not write (a title that is not a drama, film,
	// variety or anime record, a draft) are remembered, so they do not sit at
	// the head of the list and stop every later batch.
	$skip     = (array) get_option( 'daw_castlink_skip', array() );
	$unlinked = array_values( array_filter( $unlinked, static fn( $u ) => ! isset( $skip[ (int) $u['title'] . ':' . (int) $u['person'] ] ) ) );
	$left     = count( $unlinked );

	$GLOBALS['daw_ci_batch'] = true; // stays set to the end of the request: the shutdown reindexes must not flush one by one.
	$GLOBALS['daw_castlink'] = true; // the thin gate is measured once below, not once per title.
	$titles  = array();
	$linked  = 0;
	$skipped = 0;
	$seen    = 0;

	foreach ( $unlinked as $u ) {
		if ( $seen >= (int) $limit ) {
			break;
		}
		++$seen;
		$tid = (int) $u['title'];
		$pid = (int) $u['person'];
		$ok  = $tid && $pid
			&& 'publish' === get_post_status( $tid ) && 'publish' === get_post_status( $pid )
			&& in_array( daw_get_type( $tid ), array( 'drama', 'film', 'variety', 'anime' ), true )
			&& in_array( daw_get_type( $pid ), array( 'person', 'group' ), true );

		// A credit the owner kept out of the cast stays out: someone only "in
		// talks" (Cha Eun Woo on Seorabeol Knife, 29 Oct 2026) is in TMDB's
		// cast list, and this job put him back an hour after he was removed.
		// The title's `_daw_ok` names him by TMDB person id ("cov:<id>", the
		// key the audit already reads) or by record id ("nocast:<id>").
		if ( $ok ) {
			$tok  = get_post_meta( $tid, '_daw_ok', true );
			$tok  = is_array( $tok ) ? $tok : array();
			$ptmd = (string) get_post_meta( $pid, 'daw_tmdb_id', true );
			if ( isset( $tok[ 'nocast:' . $pid ] ) || ( '' !== $ptmd && isset( $tok[ 'cov:' . $ptmd ] ) ) ) {
				$ok = false;
			}
		}

		if ( ! $ok ) {
			$skip[ $tid . ':' . $pid ] = 1;
			++$skipped;
			continue;
		}

		if ( ! isset( daw_castlink_present( $tid )[ $pid ] ) ) {
			$cast   = array_values( array_filter( array_map( 'strval', (array) get_post_meta( $tid, 'daw_cast_person', true ) ) ) );
			$cast[] = (string) $pid;
			update_post_meta( $tid, 'daw_cast_person', $cast );
			++$linked;
		}
		// Already in the cast (under a TMDB id the comparison did not match)
		// counts as linked too: the report was wrong, not the cast.
		$titles[ $tid ] = true;
		daw_castlink_mark( $tid, $pid, 'title' );
		daw_castlink_mark( $pid, $tid, 'person' );
	}
	update_option( 'daw_castlink_skip', $skip, false );

	foreach ( array_keys( $titles ) as $tid ) {
		daw_reindex_cast( $tid );
		// Meta writes do not fire save_post: the cached title page and the
		// cached profiles of its new cast would show the old cast (1.75.47).
		do_action( 'litespeed_purge_post', $tid );
	}
	foreach ( $unlinked as $u ) {
		if ( isset( $titles[ (int) $u['title'] ] ) ) {
			do_action( 'litespeed_purge_post', (int) $u['person'] );
		}
	}

	// The thin page gate measures these records again now, not in half a day.
	if ( $titles && function_exists( 'daw_thin_apply' ) ) {
		$ids = array_keys( $titles );
		foreach ( $unlinked as $u ) {
			if ( isset( $titles[ (int) $u['title'] ] ) ) {
				$ids[] = (int) $u['person'];
			}
		}
		foreach ( array_unique( $ids ) as $id ) {
			daw_thin_apply( $id );
		}
		if ( function_exists( 'daw_sm_bump' ) ) {
			daw_sm_bump();
		}
	}

	daw_flush_person_cache();
	add_action( 'shutdown', 'daw_flush_person_cache', 99 );
	if ( function_exists( 'daw_covp_summary_build' ) ) {
		daw_covp_summary_build();
	}

	return array(
		'done'    => $seen,
		'linked'  => $linked,
		'skipped' => $skipped,
		'left'    => max( 0, $left - $seen ),
	);
}

/* ---------------------------------------------------------- admin and rest */

add_action( 'wp_ajax_daw_castlink_run', 'daw_castlink_ajax' );
function daw_castlink_ajax() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'daw_castlink_run', 'nonce', false ) ) {
		wp_send_json_error( 'no', 403 );
	}
	wp_send_json_success( daw_castlink_batch() );
}

/** The button, printed inside the coverage card when there is something to link. */
function daw_castlink_button( $count ) {
	if ( ! (int) $count ) {
		return;
	}
	$nonce = wp_create_nonce( 'daw_castlink_run' );
	printf(
		'<p><button type="button" class="button button-primary" id="daw-castlink">Eksik kadroyu bağla (%s)</button> <span id="daw-castlink-msg" class="kc-muted" style="margin-left:8px"></span></p>',
		esc_html( number_format_i18n( (int) $count ) )
	);
	?>
	<script>
	(function () {
		var b = document.getElementById('daw-castlink'), m = document.getElementById('daw-castlink-msg'), n = 0;
		if (!b) { return; }
		b.addEventListener('click', function () {
			b.disabled = true;
			(function step() {
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'daw_castlink_run', nonce: <?php echo wp_json_encode( $nonce ); ?> }) })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						if (!d || !d.success) { m.textContent = 'Hata, 5 sn sonra yeniden denenecek.'; setTimeout(step, 5000); return; }
						n += d.data.linked;
						m.textContent = n + ' bağlandı, ' + d.data.left + ' kaldı.';
						if (d.data.done > 0 && d.data.left > 0) { setTimeout(step, 400); } else { m.textContent = 'Bitti: ' + n + ' bağlantı eklendi. Sayfa yenileniyor.'; setTimeout(function () { location.reload(); }, 900); }
					})
					.catch(function () { setTimeout(step, 5000); });
			})();
		});
	})();
	</script>
	<?php
}

/** GET /daw/v1/castlink: how many are left; run=1 links one batch first. */
add_action( 'rest_api_init', 'daw_castlink_rest' );
function daw_castlink_rest() {
	register_rest_route(
		'daw/v1',
		'/castlink',
		array(
			'methods'             => 'GET',
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			'callback'            => static function ( WP_REST_Request $r ) {
				if ( $r->get_param( 'run' ) ) {
					return daw_castlink_batch();
				}
				$s = function_exists( 'daw_covp_summary' ) ? daw_covp_summary() : array();
				return array( 'left' => (int) ( $s['unlinked'] ?? 0 ) );
			},
		)
	);
}
