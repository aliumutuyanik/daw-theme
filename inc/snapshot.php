<?php
/**
 * Discover Asian World — one option that says where the archive stands.
 *
 * WHY AN OPTION AND NOT A PAGE
 * ----------------------------
 * The audit and control-centre screens answer the question inside wp-admin,
 * which is the wrong place for it. The person who needs to know what to work
 * on next is not sitting in wp-admin; they are deciding whether to open it at
 * all. A screen you have to log in to read is a screen that gets read on the
 * days you were going to work anyway.
 *
 * So the same numbers are written to a single option. Anything that can read
 * one option — a dashboard on another domain, a phone, a scheduled report —
 * can then show the state of the archive without touching a page render or
 * running a query of its own.
 *
 * WHAT IT COSTS AND WHY THAT IS THE DESIGN
 * ----------------------------------------
 * Building it walks every record and scores it, which is far too expensive to
 * do on request. It runs on cron, twice a day, and everything that reads it
 * reads a value that is at most twelve hours old. That is the right trade for
 * a work queue: nobody needs to know within the minute which actor to start
 * on, and a dashboard that costs a full table scan per viewer is a dashboard
 * that takes the site down the day two people open it.
 *
 * WHAT IS DELIBERATELY NOT IN IT
 * ------------------------------
 * No record bodies, no field contents, no email addresses, no keys. It holds
 * counts, ids, titles and short reason strings. An option that can be read
 * from outside should carry the smallest thing that answers the question.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_SNAPSHOT_OPTION = 'daw_audit_snapshot';

/**
 * Let the MCP connector read this one option.
 *
 * Royal MCP keeps a readable allowlist precisely so that a plugin opts in per
 * key rather than the connector being able to read anything. One key, and one
 * that holds no secrets by construction.
 */
add_filter( 'royal_mcp_readable_options', 'daw_snapshot_readable' );
function daw_snapshot_readable( $keys ) {
	$keys   = (array) $keys;
	$keys[] = DAW_SNAPSHOT_OPTION;

	return array_values( array_unique( $keys ) );
}

/**
 * Build the snapshot.
 *
 * @return array
 */
function daw_snapshot_build() {
	$people = function_exists( 'daw_audit_people_progress' ) ? daw_audit_people_progress() : array();

	// The queue is the part a person acts on, so it is the part worth keeping
	// detail for. Twenty five names and eight records each is a fortnight of
	// work listed; beyond that the list stops being a plan and starts being an
	// inventory.
	$queue = array();
	foreach ( array_slice( $people, 0, 25, true ) as $p ) {
		$weak = array();
		foreach ( array_slice( $p['weak'], 0, 8 ) as $w ) {
			$weak[] = array(
				'id'      => (int) $w['id'],
				'title'   => (string) $w['title'],
				'score'   => (int) $w['score'],
				'missing' => array_slice( (array) $w['missing'], 0, 4 ),
				'url'     => get_permalink( $w['id'] ),
			);
		}

		$queue[] = array(
			'id'    => (int) $p['id'],
			'name'  => (string) $p['name'],
			'done'  => (int) $p['done'],
			'total' => (int) $p['total'],
			'url'   => get_permalink( $p['id'] ),
			'weak'  => $weak,
		);
	}

	$recent = array();
	foreach ( array_slice( (array) get_option( 'daw_fresh_log', array() ), 0, 12 ) as $e ) {
		$recent[] = array(
			'when'  => (int) $e['when'],
			'title' => get_the_title( (int) $e['post'] ),
			'field' => (string) $e['field'],
			'from'  => (string) $e['from'],
			'to'    => (string) $e['to'],
		);
	}

	$broken = (array) get_option( 'daw_fresh_broken', array() );
	$links  = 0;
	foreach ( $broken as $row ) {
		$links += count( (array) ( $row['items'] ?? array() ) );
	}

	$split = daw_audit_invented_split();

	// The archive-wide figure. It runs daw_audit_completion() inside itself,
	// so the 'records' key below is taken from it rather than computed twice:
	// scoring six hundred records is the expensive half of this whole build.
	$archive = daw_audit_archive_total();

	// The cast queue is what a person clicks through, so it carries titles and
	// links. Forty rows is about a week of evenings, which is the horizon this
	// is any use over.
	$cast_queue = array();
	foreach ( daw_audit_cast_queue( 40 ) as $row ) {
		$cast_queue[] = array(
			'id'       => (int) $row['id'],
			'title'    => (string) $row['title'],
			'type'     => (string) $row['type'],
			'tiered'   => (int) $row['tiered'],
			'untiered' => (int) $row['untiered'],
			'state'    => (string) $row['bucket'],
			'score'    => (int) $row['score'],
			'url'      => get_permalink( $row['id'] ),
		);
	}

	// Cast, by person: the queue for somebody who has just finished writing an
	// actor's filmography and now wants to fill in who else was in it.
	$cast_people = array();
	foreach ( daw_audit_cast_people_queue( 25 ) as $p ) {
		$weak = array();
		foreach ( array_slice( $p['weak'], 0, 10 ) as $w ) {
			$weak[] = array(
				'id'       => (int) $w['id'],
				'title'    => (string) $w['title'],
				'tiered'   => (int) $w['tiered'],
				'untiered' => (int) $w['untiered'],
				'state'    => (string) $w['state'],
				'score'    => (int) $w['score'],
				'url'      => get_permalink( $w['id'] ),
			);
		}

		$cast_people[] = array(
			'id'      => (int) $p['id'],
			'name'    => (string) $p['name'],
			'titles'  => (int) $p['titles'],
			'done'    => (int) $p['done'],
			'content' => (int) $p['content'],
			'url'     => get_permalink( $p['id'] ),
			'weak'    => $weak,
		);
	}

	// Lists a person can click through and check by eye. The numbers on the
	// dashboard are only worth trusting if every one of them opens onto the
	// records behind it: "29 titles complete" means nothing until you can see
	// which twenty-nine and open them.
	$lists = array( 'titles_complete' => array(), 'people_complete' => array() );

	foreach ( daw_audit_record_ids( array( 'drama', 'film', 'variety' ) ) as $id ) {
		$sc = daw_audit_record_score( $id );
		if ( 100 === (int) $sc['score'] ) {
			$lists['titles_complete'][] = array(
				'id'    => (int) $id,
				'title' => get_the_title( $id ),
				'url'   => get_permalink( $id ),
				'v'     => function_exists( 'daw_verify_state' ) ? daw_verify_state( $id ) : 'none',
			);
		}
	}
	foreach ( daw_audit_record_ids( array( 'person', 'group' ) ) as $id ) {
		$sc = daw_audit_person_score( $id );
		if ( 100 === (int) $sc['score'] ) {
			$lists['people_complete'][] = array(
				'id'    => (int) $id,
				'title' => get_the_title( $id ),
				'url'   => get_permalink( $id ),
				'v'     => function_exists( 'daw_verify_state' ) ? daw_verify_state( $id ) : 'none',
			);
		}
	}

	// Articles: all of them, worst first, with both verdicts kept apart. Sources and
	// a focus keyword are not the same job and should not arrive as one number.
	$articles = array();
	foreach ( daw_audit_articles() as $a ) {
		$articles[] = array(
			'id'          => (int) $a['id'],
			'title'       => (string) $a['title'],
			'type'        => (string) $a['type'],
			'words'       => (int) $a['words'],
			'score'       => (int) $a['score'],
			'missing'     => array_slice( (array) $a['missing'], 0, 4 ),
			'seo_score'   => (int) $a['seo_score'],
			'seo_missing' => array_slice( (array) $a['seo_missing'], 0, 3 ),
			'url'         => get_permalink( $a['id'] ),
			'v'           => function_exists( 'daw_verify_state' ) ? daw_verify_state( $a['id'] ) : 'none',
		);
	}

	// The log of what has been audited: the newest thirty, with links, so each
	// one can be opened and checked by eye. Stale and open ones are listed by
	// name because they are the ones that need somebody's attention.
	$vt       = function_exists( 'daw_verify_tally' ) ? daw_verify_tally() : array();
	$verified = array( 'recent' => array(), 'stale' => array(), 'open' => array() );

	foreach ( array_slice( (array) ( $vt['recent'] ?? array() ), 0, 30 ) as $r ) {
		$verified['recent'][] = array(
			'id'    => (int) $r['id'],
			'title' => (string) $r['title'],
			'type'  => (string) $r['type'],
			'at'    => (int) $r['at'],
			'yok'   => (int) $r['yok'],
			'url'   => get_permalink( $r['id'] ),
		);
	}
	foreach ( array( 'stale', 'open' ) as $k ) {
		foreach ( array_slice( (array) ( $vt[ $k ] ?? array() ), 0, 30 ) as $id ) {
			$verified[ $k ][] = array(
				'id'    => (int) $id,
				'title' => get_the_title( $id ),
				'url'   => get_permalink( $id ),
			);
		}
	}

	return array(
		'generated' => time(),
		'verified'  => $verified,
		'site'      => home_url(),
		'version'   => defined( 'DAW_VERSION' ) ? DAW_VERSION : '',
		'totals'    => array(
			'titles'   => (int) $archive['groups']['titles']['total'],
			'people'   => (int) $archive['groups']['people']['total'],
			'articles' => (int) $archive['groups']['articles']['total'],
			'all'      => (int) $archive['total'],
		),
		'archive'   => $archive,
		'cast'      => $archive['cast'],
		'cast_queue' => $cast_queue,
		'cast_people' => $cast_people,
		'articles'  => $articles,
		'lists'     => $lists,
		'backlog'   => function_exists( 'daw_backlog_people' ) ? array(
			'people_total' => count( daw_backlog_people() ),
			'people'       => array_map(
				static fn( $p ) => array( 'name' => $p['name'], 'count' => $p['count'], 'titles' => array_map( static fn( $t ) => array( 'title' => $t['title'], 'url' => get_permalink( $t['id'] ) ), array_slice( $p['titles'], 0, 6 ) ) ),
				array_slice( daw_backlog_people(), 0, 40 )
			),
			'works'        => array_map(
				static fn( $w ) => array( 'name' => $w['name'], 'url' => get_permalink( $w['id'] ), 'items' => array_map( static fn( $i ) => str_replace( '|', ' · ', $i ), $w['items'] ) ),
				daw_backlog_works()
			),
		) : array(),
		'images'    => function_exists( 'daw_lic_census' ) ? daw_lic_census()['counts'] : array(),
		'record_images' => function_exists( 'daw_remote_census' ) ? daw_remote_census() : array(),
		'image_report'  => function_exists( 'daw_img_report' ) ? array_map( static function ( $g ) { return count( $g['rows'] ); }, daw_img_report() ) : array(),
		'news_recheck' => function_exists( 'daw_news_recheck' ) ? array_slice( daw_news_recheck(), 0, 40 ) : array(),
		'records'   => $archive['groups']['titles'],
		'health'    => array(
			'invented'     => count( $split['fingerprint'] ) + count( $split['zwsp_only'] ),
			'invented_fp'  => count( $split['fingerprint'] ),
			'invented_zw'  => count( $split['zwsp_only'] ),
			'bodies'       => count( daw_audit_foreign_bodies() ),
			'placeholders' => count( daw_audit_placeholders() ),
			'stale_status' => count( daw_audit_stale_status() ),
			'broken_links' => $links,
		),
		'freshness' => array(
			'status'  => count( daw_fresh_overdue( 'status' ) ),
			'watch'   => count( daw_fresh_overdue( 'watch' ) ),
			'trailer' => count( daw_fresh_overdue( 'trailer' ) ),
		),
		'queue'     => $queue,
		'recent'    => $recent,
	);
}

add_action( 'init', 'daw_snapshot_schedule' );
function daw_snapshot_schedule() {
	if ( ! wp_next_scheduled( 'daw_snapshot_cron' ) ) {
		wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'twicedaily', 'daw_snapshot_cron' );
	}
}

add_action( 'daw_snapshot_cron', 'daw_snapshot_refresh' );
function daw_snapshot_refresh() {
	update_option( DAW_SNAPSHOT_OPTION, daw_snapshot_build(), false );
}

/**
 * Build it once on the first admin request after install, so that a freshly
 * updated site has an answer before its first cron tick rather than an empty
 * dashboard and no explanation.
 */
add_action( 'admin_init', 'daw_snapshot_first_run', 40 );
function daw_snapshot_first_run() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}

	$snap = get_option( DAW_SNAPSHOT_OPTION );

	// Rebuild when there is nothing, and also when the plugin has been updated
	// since the stored snapshot was built.
	//
	// The second half was learnt the hard way. 1.24.0 widened the snapshot from
	// title records to the whole archive, the site was updated, and the option
	// still held the old shape for up to twelve hours — so the dashboard went on
	// reporting 662 records and no cast counts on a site that could now answer
	// both. A snapshot stamped with a version the plugin is no longer running is
	// not stale data, it is the wrong question's answer, and it should not
	// survive the first admin page load after an update.
	if ( is_array( $snap ) && ! empty( $snap['generated'] ) ) {
		$built_with = isset( $snap['version'] ) ? (string) $snap['version'] : '';
		$running    = defined( 'DAW_VERSION' ) ? (string) DAW_VERSION : '';

		if ( '' === $running || $built_with === $running ) {
			return;
		}
	}

	daw_snapshot_refresh();
}

/**
 * The rebuild button.
 *
 * It had a handler and no button for two versions: admin_post_daw_snapshot_now
 * was registered, nothing ever printed the form, and the only way to refresh
 * the snapshot was to wait for cron. Printing it from one function means every
 * screen that shows snapshot numbers offers the same way to refresh them.
 */
function daw_snapshot_button( $note = true ) {
	$snap = get_option( DAW_SNAPSHOT_OPTION );
	$when = is_array( $snap ) && ! empty( $snap['generated'] ) ? (int) $snap['generated'] : 0;

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:1em 0">';
	wp_nonce_field( 'daw_snapshot_now' );
	echo '<input type="hidden" name="action" value="daw_snapshot_now">';
	submit_button( __( 'Rebuild the snapshot now', 'daw' ), 'secondary', 'submit', false );

	if ( $note ) {
		if ( $when ) {
			printf(
				' <span style="color:#777">Last built %s ago%s. It rebuilds on its own twice a day.</span>',
				esc_html( human_time_diff( $when ) ),
				! empty( $snap['version'] ) ? esc_html( ', by version ' . $snap['version'] ) : ''
			);
		} else {
			echo ' <span style="color:#777">Never built.</span>';
		}
	}

	echo '</form>';
}

/** Rebuild on demand from the audit screen. */
add_action( 'admin_post_daw_snapshot_now', 'daw_snapshot_now' );
function daw_snapshot_now() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'daw_snapshot_now' ) ) {
		wp_die( 'No.' );
	}

	daw_snapshot_refresh();
	$back = wp_get_referer();
	wp_safe_redirect( $back ? add_query_arg( 'rebuilt', 1, $back ) : admin_url( 'admin.php?page=daw&rebuilt=1' ) );
	exit;
}
