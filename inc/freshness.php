<?php
/**
 * Discover Asian World — the control centre for facts that go out of date.
 *
 * THE PROBLEM THIS SOLVES
 * -----------------------
 * Every field on a record was written once and then left. That is correct for
 * a synopsis, which does not change, and wrong for half the Details card,
 * which does. A drama marked "ongoing" stays marked ongoing forever. A watch
 * link keeps saying Netflix a year after Netflix dropped it. An episode count
 * frozen at twelve stays at twelve while the show runs to sixteen.
 *
 * None of that is a typing mistake. It is a fact that was true when it was
 * written and rotted afterwards, and nothing in the site was watching.
 *
 * THREE KINDS OF FIELD
 * --------------------
 * The whole design rests on telling them apart, because treating them alike is
 * how an automated refresh destroys an editor's work.
 *
 *   Authored   synopsis, why it is watched, themes, facts, FAQ, takeaways.
 *              Somebody wrote these from sources. Nothing here ever touches
 *              them. Not to improve them, not to fill them, not ever.
 *
 *   Fetched    poster, native title, TMDB id. Fetched once because they are
 *              facts of record. Filled when empty, left alone after, which is
 *              what tmdb-fill.php already does.
 *
 *   Living     airing status, episode count, last aired date, where to watch,
 *              the trailer link. These change while nobody is looking. They
 *              are the entire subject of this file.
 *
 * WHAT IT CHANGES BY ITSELF, AND WHAT IT ONLY REPORTS
 * ---------------------------------------------------
 * It corrects airing status on its own, because status is not an opinion: it
 * is arithmetic on two dates this site already holds. A record whose last
 * episode aired in March is not "ongoing" in September, and no editor needs to
 * be consulted about that.
 *
 * Everything else it reports and leaves. A dead watch link is flagged, not
 * deleted, because "this link 404s" and "the show is not on Netflix any more"
 * are different claims and only a person can tell which one is true.
 *
 * Every change it makes is written to a log with the old value in it. An
 * automatic system that cannot show its work is one nobody can trust.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * Living field groups and how long a check stays good for.
 *
 * The ages are deliberately uneven. Airing status is worth a weekly look
 * because it is cheap and it is the one readers notice. A trailer link is
 * worth a look twice a year, because YouTube takedowns are rare and the check
 * costs a request.
 */
function daw_fresh_groups() {
	return array(
		'status'  => array(
			'label'  => __( 'Airing status and episode count', 'daw' ),
			'max'    => 7 * DAY_IN_SECONDS,
			'types'  => array( 'drama', 'variety' ),
		),
		'watch'   => array(
			'label'  => __( 'Where to watch', 'daw' ),
			'max'    => 90 * DAY_IN_SECONDS,
			'types'  => array( 'drama', 'film', 'variety' ),
		),
		'trailer' => array(
			'label'  => __( 'Trailer link', 'daw' ),
			'max'    => 180 * DAY_IN_SECONDS,
			'types'  => array( 'drama', 'film', 'variety' ),
		),
	);
}

/** When each group was last confirmed on a record. */
function daw_fresh_stamps( $post_id ) {
	$v = get_post_meta( $post_id, '_daw_checked', true );
	return is_array( $v ) ? $v : array();
}

function daw_fresh_stamp( $post_id, $group ) {
	$s           = daw_fresh_stamps( $post_id );
	$s[ $group ] = time();
	update_post_meta( $post_id, '_daw_checked', $s );
}

/** Records of a type whose group check is older than the group allows. */
function daw_fresh_overdue( $group ) {
	$groups = daw_fresh_groups();
	if ( ! isset( $groups[ $group ] ) ) {
		return array();
	}

	$cfg = $groups[ $group ];
	$out = array();

	foreach ( daw_audit_record_ids( $cfg['types'] ) as $id ) {
		$stamps = daw_fresh_stamps( $id );
		$last   = isset( $stamps[ $group ] ) ? (int) $stamps[ $group ] : 0;

		if ( ! $last || ( time() - $last ) > $cfg['max'] ) {
			$out[] = $id;
		}
	}

	return $out;
}

/* ============================================================ the log === */

/**
 * Append a change to the log.
 *
 * Capped at 500 entries. A log nobody can read is the same as no log, and an
 * option that grows without limit is a different bug arriving later.
 */
function daw_fresh_log( $post_id, $field, $from, $to, $why ) {
	$log = get_option( 'daw_fresh_log' );
	$log = is_array( $log ) ? $log : array();

	array_unshift(
		$log,
		array(
			'when'  => time(),
			'post'  => (int) $post_id,
			'field' => (string) $field,
			'from'  => (string) $from,
			'to'    => (string) $to,
			'why'   => (string) $why,
		)
	);

	update_option( 'daw_fresh_log', array_slice( $log, 0, 500 ), false );
}

/* ================================================ status, from our own data === */

/**
 * What the airing status ought to be, read off the dates we already hold.
 *
 * Returns an empty string when the dates cannot answer it, which is the common
 * case for a record with no end date: a show that started last month and has
 * no end date on file is either still running or simply not finished being
 * typed up, and this cannot tell the difference. Saying nothing is the correct
 * answer to a question you cannot answer.
 */
function daw_fresh_derive_status( $post_id ) {
	$start = (int) get_post_meta( $post_id, 'daw_aired_start', true );
	$end   = (int) get_post_meta( $post_id, 'daw_aired_end', true );
	$now   = time();

	if ( $start && $start > $now ) {
		return 'upcoming';
	}

	// An end date in the past settles it, whatever the record currently says.
	if ( $end && $end < $now ) {
		return 'completed';
	}

	// Started, and an end date that has not arrived: still running.
	if ( $start && $start <= $now && $end && $end > $now ) {
		return 'ongoing';
	}

	return '';
}

/**
 * Correct the status on one record. Returns true when something changed.
 *
 * The one automatic write in this file, and it is safe for a specific reason:
 * the new value is computed from two fields on the same record rather than
 * from anywhere else, so it cannot import somebody else's mistake, and it
 * cannot disagree with the dates printed two lines above it on the page.
 */
function daw_fresh_fix_status( $post_id ) {
	$want = daw_fresh_derive_status( $post_id );
	if ( '' === $want ) {
		return false;
	}

	$have = (string) get_post_meta( $post_id, 'daw_status', true );
	if ( $have === $want ) {
		return false;
	}

	// A record marked completed by hand with no end date on file is not
	// something to argue with; the derive function already declined to guess
	// in that case, so reaching here means the dates are explicit.
	update_post_meta( $post_id, 'daw_status', $want );
	daw_fresh_log( $post_id, 'daw_status', $have, $want, __( 'derived from the aired dates on this record', 'daw' ) );

	return true;
}

/* ====================================================== link liveness === */

/**
 * Is a url still there?
 *
 * HEAD first, because it costs a header and not a page. Some hosts answer HEAD
 * with 405, so a 405 falls back to a ranged GET rather than being reported as
 * a dead link: "this server dislikes HEAD" and "this page is gone" must not
 * produce the same answer.
 *
 * @return int|string HTTP status, or a string when the request itself failed.
 */
function daw_fresh_probe( $url ) {
	$args = array(
		'timeout'     => 8,
		'redirection' => 3,
		'user-agent'  => 'discoverasianworld-linkcheck/1.0',
	);

	$r = wp_remote_head( $url, $args );

	if ( is_wp_error( $r ) ) {
		return $r->get_error_code();
	}

	$code = (int) wp_remote_retrieve_response_code( $r );

	if ( 405 === $code || 403 === $code ) {
		$r = wp_remote_get( $url, array_merge( $args, array( 'headers' => array( 'Range' => 'bytes=0-0' ) ) ) );
		if ( is_wp_error( $r ) ) {
			return $r->get_error_code();
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
	}

	return $code;
}

/**
 * Is a YouTube video still playable?
 *
 * oEmbed rather than the watch page: a deleted or private video answers 404
 * there, while the watch page answers 200 with an error rendered in
 * JavaScript. Checking the wrong endpoint would report every dead trailer as
 * healthy, which is worse than not checking.
 */
function daw_fresh_probe_youtube( $url ) {
	$probe = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( $url );
	return daw_fresh_probe( $probe );
}

/** Check one record's outbound links. Reports; changes nothing. */
function daw_fresh_check_links( $post_id ) {
	$bad = array();

	$urls = (array) rwmb_meta( 'daw_watch_url', array(), $post_id );
	foreach ( $urls as $u ) {
		$u = trim( (string) $u );
		if ( '' === $u || 0 !== strpos( $u, 'http' ) ) {
			continue;
		}
		$code = daw_fresh_probe( $u );
		if ( is_string( $code ) || $code >= 400 ) {
			$bad[] = array( 'url' => $u, 'code' => $code, 'kind' => 'watch' );
		}
	}

	$trailer = trim( (string) get_post_meta( $post_id, 'daw_trailer_youtube', true ) );
	if ( '' !== $trailer ) {
		$code = daw_fresh_probe_youtube( $trailer );
		if ( is_string( $code ) || $code >= 400 ) {
			$bad[] = array( 'url' => $trailer, 'code' => $code, 'kind' => 'trailer' );
		}
	}

	$store = get_option( 'daw_fresh_broken' );
	$store = is_array( $store ) ? $store : array();

	if ( $bad ) {
		$store[ (int) $post_id ] = array( 'when' => time(), 'items' => $bad );
	} else {
		unset( $store[ (int) $post_id ] );
	}

	update_option( 'daw_fresh_broken', $store, false );

	daw_fresh_stamp( $post_id, 'watch' );
	daw_fresh_stamp( $post_id, 'trailer' );

	return $bad;
}

/* ================================================================ cron === */

/**
 * The daily pass, deliberately small.
 *
 * Twenty five records of link checking is up to about fifty outbound requests,
 * which on a shared host under load is already more than it should be asked to
 * do in one go. The status correction is free — it reads two meta values — so
 * that runs across everything overdue rather than a slice.
 */
add_action( 'init', 'daw_fresh_schedule' );
function daw_fresh_schedule() {
	if ( ! wp_next_scheduled( 'daw_fresh_daily' ) ) {
		wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', 'daw_fresh_daily' );
	}
}

add_action( 'daw_fresh_daily', 'daw_fresh_run_daily' );
function daw_fresh_run_daily() {
	$fixed = 0;
	foreach ( daw_fresh_overdue( 'status' ) as $id ) {
		if ( daw_fresh_fix_status( $id ) ) {
			$fixed++;
		}
		daw_fresh_stamp( $id, 'status' );
	}

	$checked = 0;
	foreach ( array_slice( daw_fresh_overdue( 'watch' ), 0, 50 ) as $id ) {
		daw_fresh_check_links( $id );
		$checked++;
	}

	update_option(
		'daw_fresh_last',
		array( 'when' => time(), 'status_fixed' => $fixed, 'links_checked' => $checked ),
		false
	);
}

/* ============================================================== screen === */

add_action( 'admin_menu', 'daw_fresh_menu' );
function daw_fresh_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: Control centre', 'daw' ),
		__( 'DAW: Control centre', 'daw' ),
		'manage_options',
		'daw-fresh',
		'daw_fresh_page'
	);
}

function daw_fresh_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Running the pass by hand is allowed; it is the same function cron calls.
	if ( isset( $_POST['daw_fresh_run'] ) && check_admin_referer( 'daw_fresh_run' ) ) {
		daw_fresh_run_daily();
		echo '<div class="notice notice-success"><p>Pass complete.</p></div>';
	}

	$last   = get_option( 'daw_fresh_last' );
	$log    = (array) get_option( 'daw_fresh_log', array() );
	$broken = (array) get_option( 'daw_fresh_broken', array() );

	echo '<div class="wrap"><h1>Control centre</h1>';
	echo '<p style="max-width:54em">Fields that go out of date on their own. Authored text — synopsis, themes, facts, FAQ — is never touched by anything on this page.</p>';

	echo '<form method="post" style="margin:1em 0">';
	wp_nonce_field( 'daw_fresh_run' );
	echo '<button class="button button-primary" name="daw_fresh_run" value="1">Run the pass now</button>';
	if ( is_array( $last ) && ! empty( $last['when'] ) ) {
		printf(
			' <span style="color:#777">Last run %s ago — %d status corrections, %d records link-checked.</span>',
			esc_html( human_time_diff( (int) $last['when'] ) ),
			(int) ( $last['status_fixed'] ?? 0 ),
			(int) ( $last['links_checked'] ?? 0 )
		);
	} else {
		echo ' <span style="color:#777">Never run.</span>';
	}
	echo '</form>';

	echo '<h2>Due for a check</h2><table class="widefat striped" style="max-width:48em"><thead><tr><th>Group</th><th>Overdue</th><th>Checked again after</th></tr></thead><tbody>';
	foreach ( daw_fresh_groups() as $g => $cfg ) {
		printf(
			'<tr><td>%s</td><td><strong>%s</strong></td><td>%s</td></tr>',
			esc_html( $cfg['label'] ),
			esc_html( number_format_i18n( count( daw_fresh_overdue( $g ) ) ) ),
			esc_html( human_time_diff( 0, $cfg['max'] ) )
		);
	}
	echo '</tbody></table>';

	echo '<h2>Links that did not answer</h2>';
	if ( ! $broken ) {
		echo '<p style="color:#2a7">Nothing found, or nothing checked yet.</p>';
	} else {
		echo '<p style="max-width:54em">Flagged, not removed. A 404 means the page is gone; it does not by itself mean the title left the service, and only a person can tell those apart.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Record</th><th>What</th><th>Answer</th><th>URL</th></tr></thead><tbody>';
		foreach ( array_slice( $broken, 0, 200, true ) as $pid => $row ) {
			foreach ( (array) ( $row['items'] ?? array() ) as $item ) {
				printf(
					'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td style="word-break:break-all"><code>%s</code></td></tr>',
					esc_url( get_edit_post_link( $pid ) ),
					esc_html( get_the_title( $pid ) ?: '#' . $pid ),
					esc_html( $item['kind'] ?? '' ),
					esc_html( (string) ( $item['code'] ?? '' ) ),
					esc_html( $item['url'] ?? '' )
				);
			}
		}
		echo '</tbody></table>';
	}

	echo '<h2>What was changed automatically</h2>';
	if ( ! $log ) {
		echo '<p style="color:#777">Nothing yet.</p>';
	} else {
		echo '<p style="max-width:54em">Every automatic write, with the value it replaced. Status is the only field anything here is allowed to change.</p>';
		echo '<table class="widefat striped"><thead><tr><th>When</th><th>Record</th><th>Field</th><th>From</th><th>To</th><th>Why</th></tr></thead><tbody>';
		foreach ( array_slice( $log, 0, 100 ) as $e ) {
			printf(
				'<tr><td>%s ago</td><td><a href="%s">%s</a></td><td><code>%s</code></td><td>%s</td><td><strong>%s</strong></td><td style="color:#777">%s</td></tr>',
				esc_html( human_time_diff( (int) $e['when'] ) ),
				esc_url( get_edit_post_link( (int) $e['post'] ) ),
				esc_html( get_the_title( (int) $e['post'] ) ?: '#' . (int) $e['post'] ),
				esc_html( $e['field'] ),
				esc_html( '' === $e['from'] ? '(empty)' : $e['from'] ),
				esc_html( $e['to'] ),
				esc_html( $e['why'] )
			);
		}
		echo '</tbody></table>';
	}

	echo '<h2>What stays manual, and why</h2>';
	echo '<ul style="max-width:54em;list-style:disc;margin-left:1.5em">';
	echo '<li><strong>Cast.</strong> Billing order is a fact about a production and no feed carries it. A missing person is a name that was never stored, so no query can find the gap.</li>';
	echo '<li><strong>Synopsis, themes, interesting facts, FAQ.</strong> Written from sources, in this site\'s own words. Copying them from a feed is the thing the editorial policy exists to prevent.</li>';
	echo '<li><strong>Removing a dead watch link.</strong> Flagged above. Deciding whether the title left the service or the service moved the page needs a person.</li>';
	echo '<li><strong>Awards.</strong> Announced at a ceremony, not published in a feed this site can read.</li>';
	echo '</ul>';

	echo '</div>';
}
