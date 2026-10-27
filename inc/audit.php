<?php
/**
 * Discover Asian World — one screen that says what is wrong with the archive.
 *
 * WHY THIS EXISTS
 * ---------------
 * Thirty records were checked by hand. It took a working day, it found three
 * invented panels on a record that was already marked finished, and it left
 * six hundred and thirty two records unexamined. Checking those the same way
 * is not a plan, and fetching every page over HTTP to look at it is worse: it
 * is nine hundred requests against a shared host that is already loaded.
 *
 * Everything the hand check looked for is in the database. So this asks the
 * database instead. One page, six questions, each with the records named.
 *
 * WHAT IT WILL NOT DO
 * -------------------
 * It will not fix anything. Every count here links to the records behind it
 * and stops. A screen that offered to repair four hundred records in one click
 * would be a screen that could ruin four hundred records in one click, and the
 * repairs this archive needs are editorial: they need somebody to read a
 * source. Deleting invented text is the one exception, and that already has
 * its own migration with its own backup.
 *
 * WHAT IT CANNOT SEE
 * ------------------
 * Cast gaps. A missing person record is a name that was never stored, so no
 * query can find it — the absence has no row. The closest this can get is
 * naming the records whose cast is thin or whose tiers are empty, which is
 * where a human should look. The named gaps stay in research/kunye-denetim.md
 * because they came from reading, not from the database.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** Panels the invented pass wrote. Kept in sync with migrate-panels.php. */
function daw_audit_panel_keys() {
	return function_exists( 'daw_invented_panel_keys' )
		? daw_invented_panel_keys()
		: array( 'daw_best_scenes', 'daw_filming_location', 'daw_visual_design', 'daw_real_life_loves', 'daw_themes_messages', 'daw_interesting_facts', 'daw_awards' );
}

/**
 * Records still carrying text from the invented pass.
 *
 * Two markers, and the second one matters more than it looks. The class string
 * is stripped by wp_kses on output, so a page can look clean to anything that
 * reads the rendered HTML while the row underneath still holds it. The
 * zero-width space survives output. Checking only one of them is how a record
 * that was already marked finished kept three invented panels.
 *
 * @return array post_id => list of meta keys
 */
function daw_audit_invented() {
	global $wpdb;

	$keys = daw_audit_panel_keys();
	$ph   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

	$sql = "SELECT post_id, meta_key, meta_value
	          FROM {$wpdb->postmeta}
	         WHERE meta_key IN ( {$ph} )
	           AND ( meta_value LIKE %s OR meta_value LIKE %s )";

	$args   = $keys;
	$args[] = '%' . $wpdb->esc_like( 'relative -mx-px' ) . '%';
	$args[] = '%' . $wpdb->esc_like( "\xE2\x80\x8B" ) . '%';

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB

	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[ (int) $r->post_id ][] = $r->meta_key;
	}

	return $out;
}

/**
 * The same search, split by which marker each record carries.
 *
 * It matters operationally. The migration deletes on the class string alone,
 * because that one is unambiguous — nobody types it. The zero-width space is a
 * weaker signal: it survives output, which is why it is worth searching for,
 * but it can also arrive legitimately in text pasted from a Korean source. So
 * a record carrying only the zero-width marker is not deleted automatically;
 * it is listed for somebody to look at.
 *
 * Reporting one number for both would leave an editor unable to tell which
 * pile a record is in, and therefore unable to tell whether running the
 * migration again would help.
 *
 * @return array{fingerprint:int[], zwsp_only:int[]}
 */
function daw_audit_invented_split() {
	global $wpdb;

	$keys = daw_audit_panel_keys();
	$ph   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

	$sql  = "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
	          WHERE meta_key IN ( {$ph} ) AND meta_value LIKE %s";
	$args = $keys;
	$args[] = '%' . $wpdb->esc_like( 'relative -mx-px' ) . '%';

	$fp = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB

	$all = array_map( 'intval', array_keys( daw_audit_invented() ) );

	return array(
		'fingerprint' => $fp,
		'zwsp_only'   => array_values( array_diff( $all, $fp ) ),
	);
}

/**
 * Records whose post_content is not the placeholder comment.
 *
 * The templates never print post_content on a record, so anything in there is
 * invisible and therefore unnoticed: the Alhambra body sat on hundreds of
 * records for months. Invisible is not harmless — it goes into exports, feeds,
 * search indexes and anything that reads the REST API.
 *
 * @return int[]
 */
function daw_audit_foreign_bodies() {
	global $wpdb;

	$ids = daw_audit_record_ids();
	if ( ! $ids ) {
		return array();
	}

	$in   = implode( ',', array_map( 'absint', $ids ) );
	$rows = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$in}) AND CHAR_LENGTH( TRIM( post_content ) ) > 200" ); // phpcs:ignore WordPress.DB

	return array_map( 'intval', (array) $rows );
}

/**
 * Meta values that are a stand-in for an answer rather than an answer.
 *
 * "Not Available" in a field is worse than an empty field, because the template
 * prints it and the reader believes it is the site talking.
 *
 * @return array post_id => list of meta keys
 */
function daw_audit_placeholders() {
	global $wpdb;

	$needles = array( 'Not Available', 'Not applicable', 'N/A (', 'TBD', 'TBA', '[To be', 'Information not', 'Coming soon', 'Lorem ipsum' );

	$where = array();
	$args  = array();
	foreach ( $needles as $n ) {
		$where[] = 'meta_value LIKE %s';
		$args[]  = '%' . $wpdb->esc_like( $n ) . '%';
	}

	$sql = "SELECT post_id, meta_key
	          FROM {$wpdb->postmeta}
	         WHERE meta_key LIKE 'daw\\_%'
	           AND CHAR_LENGTH( meta_value ) < 120
	           AND ( " . implode( ' OR ', $where ) . ' )';

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB

	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[ (int) $r->post_id ][] = $r->meta_key;
	}

	return $out;
}

/**
 * The fields a finished record of each type is expected to carry.
 *
 * Deliberately short. These are the ones whose absence makes a page read as
 * unfinished to a visitor, not everything the schema allows: a drama with no
 * synopsis is broken, a drama with no filming locations may simply be a drama
 * whose locations nobody published.
 */
function daw_audit_required() {
	return array(
		'drama'  => array( 'daw_synopsis', 'daw_native_title', 'daw_episodes', 'daw_aired_start', 'daw_faq_q', 'daw_takeaways' ),
		'film'   => array( 'daw_synopsis', 'daw_native_title', 'daw_aired_start', 'daw_faq_q', 'daw_takeaways' ),
		'person' => array( 'daw_biography', 'daw_native_name', 'daw_born' ),
	);
}

/** Every record id we audit, by type. */
function daw_audit_record_ids( $types = array( 'drama', 'film', 'variety', 'person' ) ) {
	static $cache = array();
	$key = implode( ',', (array) $types );

	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$cache[ $key ] = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => (array) $types,
				),
			),
		)
	);

	return $cache[ $key ];
}

/**
 * Records whose airing status has gone stale.
 *
 * A record marked ongoing whose last episode aired a month ago is a page that
 * is telling readers something untrue, and it will keep telling them until
 * somebody notices. This is the one check here that finds a fact that rots on
 * its own rather than a fact that was wrong when it was typed.
 *
 * @return int[]
 */
function daw_audit_stale_status() {
	$out = array();

	foreach ( daw_audit_record_ids( array( 'drama', 'variety' ) ) as $id ) {
		$status = (string) get_post_meta( $id, 'daw_status', true );
		if ( 'ongoing' !== $status && 'airing' !== $status ) {
			continue;
		}

		$end = (int) get_post_meta( $id, 'daw_aired_end', true );
		if ( $end && $end < ( time() - MONTH_IN_SECONDS ) ) {
			$out[] = $id;
		}
	}

	return $out;
}

add_action( 'admin_menu', 'daw_audit_menu' );
function daw_audit_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: Archive audit', 'daw' ),
		__( 'DAW: Archive audit', 'daw' ),
		'manage_options',
		'daw-audit',
		'daw_audit_page'
	);
}

/** A list of record links, capped, with a count. */
function daw_audit_list( $ids, $note = '' ) {
	$ids   = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	$total = count( $ids );

	if ( ! $total ) {
		echo '<p style="color:#2a7">Nothing found.</p>';
		return;
	}

	echo '<p><strong>' . esc_html( number_format_i18n( $total ) ) . '</strong> record' . ( 1 === $total ? '' : 's' );
	if ( $note ) {
		echo ' — ' . esc_html( $note );
	}
	echo '</p><ol style="max-height:22em;overflow:auto;margin:0 0 1.5em 2em">';

	foreach ( array_slice( $ids, 0, 400 ) as $id ) {
		printf(
			'<li><a href="%s">%s</a> <span style="color:#777">#%d</span></li>',
			esc_url( get_edit_post_link( $id ) ),
			esc_html( get_the_title( $id ) ?: '(no title)' ),
			(int) $id
		);
	}

	echo '</ol>';

	if ( $total > 400 ) {
		echo '<p style="color:#777">Showing the first 400.</p>';
	}
}

function daw_audit_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$invented     = daw_audit_invented();
	$bodies       = daw_audit_foreign_bodies();
	$placeholders = daw_audit_placeholders();
	$stale        = daw_audit_stale_status();

	// Missing required fields, counted per type.
	$missing = array();
	foreach ( daw_audit_required() as $type => $fields ) {
		foreach ( daw_audit_record_ids( array( $type ) ) as $id ) {
			foreach ( $fields as $f ) {
				$v = get_post_meta( $id, $f, true );
				if ( '' === $v || array() === $v || null === $v ) {
					$missing[ $type ][ $f ][] = $id;
				}
			}
		}
	}

	echo '<div class="wrap"><h1>Archive audit</h1>';
	echo '<p style="max-width:52em">Every count below is read straight from the database and every record is named. Nothing on this page changes anything.</p>';

	echo '<h2>1. Invented panel text</h2>';
	echo '<p style="max-width:52em">Panels carrying the editor residue of the automated pass, or its zero-width marker. The marker matters: the class string is stripped on output, so these do not show up in a scan of the rendered page.</p>';
	daw_audit_list( array_keys( $invented ), 'run the panel migration, or clear the fields by hand' );

	echo '<h2>2. Text hiding in post_content</h2>';
	echo '<p style="max-width:52em">Record templates never print post_content, so anything longer than a placeholder comment is invisible on the page and still present in exports, feeds and the REST API.</p>';
	daw_audit_list( $bodies );

	echo '<h2>3. Placeholders published as answers</h2>';
	echo '<p style="max-width:52em">Fields saying "Not Available", "TBD" and the like. An empty field prints nothing; these print.</p>';
	daw_audit_list( array_keys( $placeholders ) );

	echo '<h2>4. Airing status that has rotted</h2>';
	echo '<p style="max-width:52em">Marked as still airing, but the last episode aired over a month ago. This is the check that catches facts going stale on their own rather than facts typed wrongly.</p>';
	daw_audit_list( $stale );

	echo '<h2>5. Missing core fields</h2>';
	foreach ( $missing as $type => $fields ) {
		echo '<h3 style="margin-bottom:.2em">' . esc_html( ucfirst( $type ) ) . '</h3>';
		foreach ( $fields as $field => $ids ) {
			printf(
				'<p style="margin:.2em 0"><code>%s</code> — <strong>%s</strong> record%s missing it</p>',
				esc_html( $field ),
				esc_html( number_format_i18n( count( $ids ) ) ),
				1 === count( $ids ) ? '' : 's'
			);
		}
	}
	if ( ! $missing ) {
		echo '<p style="color:#2a7">Nothing found.</p>';
	}

	echo '<h2>6. What this page cannot see</h2>';
	echo '<p style="max-width:52em">Cast gaps. A performer with no record on this site is a name that was never stored, so there is no row to find. The named gaps live in <code>research/kunye-denetim.md</code>, written down as they were found by reading, and they have to be worked through by hand.</p>';

	$last = get_option( 'daw_audit_last' );
	if ( is_array( $last ) && ! empty( $last['when'] ) ) {
		echo '<hr><p style="color:#777">Weekly check last ran ' . esc_html( human_time_diff( (int) $last['when'] ) ) . ' ago. Counts then: '
			. esc_html( wp_json_encode( $last['counts'] ?? array() ) ) . '</p>';
	}

	echo '</div>';
}

/**
 * The weekly pass.
 *
 * It records the counts and nothing else. No email, no notice, no repair: the
 * point is a number to compare against next week, so that a count going up is
 * visible instead of being discovered a year later on one record by accident.
 */
add_action( 'init', 'daw_audit_schedule' );
function daw_audit_schedule() {
	if ( ! wp_next_scheduled( 'daw_audit_weekly' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'daw_audit_weekly' );
	}
}

add_filter( 'cron_schedules', 'daw_audit_cron_schedule' ); // phpcs:ignore WordPress.WP.CronInterval
function daw_audit_cron_schedule( $s ) {
	if ( ! isset( $s['weekly'] ) ) {
		$s['weekly'] = array( 'interval' => WEEK_IN_SECONDS, 'display' => __( 'Once weekly', 'daw' ) );
	}
	return $s;
}

add_action( 'daw_audit_weekly', 'daw_audit_run_weekly' );
function daw_audit_run_weekly() {
	update_option(
		'daw_audit_last',
		array(
			'when'   => time(),
			'counts' => array(
				'invented'     => count( daw_audit_invented() ),
				'bodies'       => count( daw_audit_foreign_bodies() ),
				'placeholders' => count( daw_audit_placeholders() ),
				'stale'        => count( daw_audit_stale_status() ),
			),
		),
		false
	);
}

/* ==================================================== who to do next === */

/**
 * How finished one title record is, on the measurable criteria only.
 *
 * Measurable is the important word. This cannot tell whether a synopsis is
 * any good, whether the facts are sourced, or whether the cast is complete —
 * those took a person a working day for thirty records. What it can tell is
 * whether the record has the shape of a finished one, and a record that fails
 * these has certainly not been done.
 *
 * So a high score is "probably fine, nobody has checked", and a low score is
 * "definitely not done". The second is what a work queue needs.
 *
 * @return array score 0-100 and the reasons it lost points
 */
function daw_audit_record_score( $post_id ) {
	// Scored more than once per request by design: the completion tally, the
	// people queue and the cast queue all ask about the same records from
	// different directions. Six hundred records times three passes times six
	// meta reads is the difference between a cron tick and a timeout.
	static $memo = array();
	if ( isset( $memo[ (int) $post_id ] ) ) {
		return $memo[ (int) $post_id ];
	}

	$miss = array();

	$faq = (array) rwmb_meta( 'daw_faq_q', array(), $post_id );
	$faq = array_filter( array_map( 'trim', array_map( 'strval', $faq ) ) );
	if ( count( $faq ) < 10 ) {
		$miss[] = sprintf( 'FAQ %d/10', count( $faq ) );
	}

	foreach ( array(
		'daw_synopsis'     => 'synopsis',
		'daw_why_watched'  => 'why watched',
		'daw_takeaways'    => 'takeaways',
		'daw_native_title' => 'native title',
	) as $key => $label ) {
		$v = get_post_meta( $post_id, $key, true );
		if ( '' === $v || array() === $v ) {
			$miss[] = $label;
		}
	}

	// Anything still carrying the invented fingerprint is not finished,
	// whatever else is filled in.
	static $invented = null;
	if ( null === $invented ) {
		$invented = daw_audit_invented();
	}
	if ( isset( $invented[ (int) $post_id ] ) ) {
		$miss[] = 'invented panel text';
	}

	$total = 5 + 1; // four fields, the FAQ, the fingerprint
	$score = (int) round( ( ( $total - count( $miss ) ) / $total ) * 100 );

	$memo[ (int) $post_id ] = array( 'score' => max( 0, $score ), 'missing' => $miss );

	return $memo[ (int) $post_id ];
}

/**
 * Every person record with the state of the titles they appear in.
 *
 * Built off the cast index the plugin already maintains, so it is one pass
 * over the titles rather than a query per person.
 */
function daw_audit_people_progress() {
	$people = array();

	foreach ( daw_audit_record_ids( array( 'person' ) ) as $pid ) {
		$people[ $pid ] = array(
			'id'    => $pid,
			'name'  => get_the_title( $pid ),
			'total' => 0,
			'done'  => 0,
			'weak'  => array(),
		);
	}

	foreach ( daw_audit_record_ids( array( 'drama', 'film', 'variety' ) ) as $tid ) {
		$idx = (string) get_post_meta( $tid, '_daw_cast_idx', true );
		if ( '' === $idx ) {
			continue;
		}

		$state = daw_audit_record_score( $tid );

		foreach ( explode( ',', trim( $idx, ',' ) ) as $chunk ) {
			$pid = (int) strtok( $chunk, ':' );
			if ( ! $pid || ! isset( $people[ $pid ] ) ) {
				continue;
			}

			$people[ $pid ]['total']++;

			if ( $state['score'] >= 100 ) {
				$people[ $pid ]['done']++;
			} else {
				$people[ $pid ]['weak'][] = array(
					'id'      => $tid,
					'title'   => get_the_title( $tid ),
					'score'   => $state['score'],
					'missing' => $state['missing'],
				);
			}
		}
	}

	// A person with no titles on file is not a work queue, it is an empty
	// profile, and it belongs to a different job than this one.
	$people = array_filter( $people, static fn( $p ) => $p['total'] > 0 );

	// The useful order is "most work sitting behind one name": a person whose
	// filmography is nearly done is a short job worth closing, and a person
	// with twenty weak records is where the archive actually improves.
	uasort(
		$people,
		static function ( $a, $b ) {
			$ra = $a['total'] ? $a['done'] / $a['total'] : 0;
			$rb = $b['total'] ? $b['done'] / $b['total'] : 0;

			if ( $ra === $rb ) {
				return $b['total'] <=> $a['total'];
			}

			return $ra <=> $rb;
		}
	);

	return $people;
}

add_action( 'admin_menu', 'daw_audit_next_menu' );
function daw_audit_next_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: Who to do next', 'daw' ),
		__( 'DAW: Who to do next', 'daw' ),
		'manage_options',
		'daw-next',
		'daw_audit_next_page'
	);
}

function daw_audit_next_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$people = daw_audit_people_progress();

	echo '<div class="wrap"><h1>Who to do next</h1>';

	if ( function_exists( 'daw_snapshot_button' ) ) {
		daw_snapshot_button();
	}
	echo '<p style="max-width:54em">Every person with records on file, ordered by how much of their filmography is unfinished. A record counts as done only when it has ten FAQ entries, a synopsis, a why-it-is-watched, takeaways, a native title, and no invented panel text. That is the shape of a finished record, not proof that it is right: a high score means nobody has found a problem, not that somebody checked.</p>';

	echo '<table class="widefat striped"><thead><tr><th>Person</th><th style="width:6em">Done</th><th style="width:12em">Progress</th><th>Records still short</th></tr></thead><tbody>';

	foreach ( array_slice( $people, 0, 60, true ) as $p ) {
		$pct = $p['total'] ? (int) round( $p['done'] / $p['total'] * 100 ) : 0;

		$weak = array();
		foreach ( array_slice( $p['weak'], 0, 6 ) as $w ) {
			$weak[] = sprintf(
				'<a href="%s">%s</a> <span style="color:#999">%s</span>',
				esc_url( get_edit_post_link( $w['id'] ) ),
				esc_html( $w['title'] ),
				esc_html( implode( ', ', array_slice( $w['missing'], 0, 3 ) ) )
			);
		}
		if ( count( $p['weak'] ) > 6 ) {
			$weak[] = sprintf( '<span style="color:#999">and %d more</span>', count( $p['weak'] ) - 6 );
		}

		printf(
			'<tr><td><a href="%s"><strong>%s</strong></a></td><td>%d / %d</td>'
			. '<td><div style="background:#e5e5e5;height:10px;border-radius:5px;overflow:hidden"><div style="width:%d%%;height:10px;background:%s"></div></div></td>'
			. '<td style="font-size:12px;line-height:1.7">%s</td></tr>',
			esc_url( get_edit_post_link( $p['id'] ) ),
			esc_html( $p['name'] ),
			(int) $p['done'],
			(int) $p['total'],
			$pct,
			$pct >= 100 ? '#2a7' : ( $pct >= 50 ? '#e8a33d' : '#d35' ),
			implode( ' &middot; ', $weak ) // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}

	echo '</tbody></table>';
	echo '<p style="color:#777">Showing the sixty with the most outstanding work.</p></div>';
}


/* ================================================ how much is finished === */

/**
 * The number the archive is actually judged by: how many records are done.
 *
 * Reported alongside a breakdown of what the unfinished ones are missing,
 * because "six hundred records are incomplete" is a number nobody can act on
 * and "six hundred records have no FAQ" is a task.
 *
 * @return array
 */
function daw_audit_completion() {
	$hist    = array();
	$missing = array();
	$done    = 0;
	$total   = 0;

	foreach ( daw_audit_record_ids( array( 'drama', 'film', 'variety' ) ) as $id ) {
		$total++;
		$state = daw_audit_record_score( $id );
		$b     = (int) ( floor( $state['score'] / 10 ) * 10 );

		$hist[ $b ] = ( $hist[ $b ] ?? 0 ) + 1;

		if ( 100 === (int) $state['score'] ) {
			$done++;
			continue;
		}

		foreach ( $state['missing'] as $m ) {
			// The FAQ reason carries its own count ("FAQ 7/10"); group them.
			$k             = 0 === strpos( $m, 'FAQ' ) ? 'FAQ under 10' : $m;
			$missing[ $k ] = ( $missing[ $k ] ?? 0 ) + 1;
		}
	}

	ksort( $hist );
	arsort( $missing );

	return array(
		'total'     => $total,
		'complete'  => $done,
		'partial'   => $total - $done,
		'histogram' => $hist,
		'missing'   => $missing,
	);
}
