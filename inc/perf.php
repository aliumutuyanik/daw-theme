<?php
/**
 * Discover Asian World: how each record is doing, on its own audit page.
 *
 * WHAT THE OWNER ASKED FOR (5 Oct 2026)
 * -------------------------------------
 * "Is this page climbing, is it getting traffic, which searches bring it,
 * are the keywords we chose actually ranking?" Search Console and Analytics
 * already feed the control centre, but only as site totals. This keeps the
 * per-page history and draws it on the record's audit page (Denetim).
 *
 * WHAT IS KEPT
 * ------------
 * - Search Console, per page per day: already stored by seo-gsc.php
 *   (wp_daw_gsc, sixteen months). Nothing new.
 * - Search Console, per page per search, as weekly snapshots of the last 28
 *   days (wp_daw_gsc_qh). seo-gsc.php keeps only today's 28 days; the
 *   snapshots are what let a position be compared with a month ago. The
 *   first run backfills three earlier 28-day windows, so a trend is there
 *   from day one. Kept 26 weeks.
 * - Analytics, per page per ISO week, the last 13 weeks (wp_daw_ga4_pw),
 *   replaced daily in one report call. Analytics only counts readers that
 *   passed the bot gate (analytics-gate.php, 1.62.3); weeks before that
 *   carry bot traffic and are marked so on the chart.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_PERF_DB = 1;
const DAW_PERF_BOT_GATE_DAY = '2026-10-04'; // analytics-gate.php went live (1.62.3).

function daw_perf_qh_table() {
	global $wpdb;
	return $wpdb->prefix . 'daw_gsc_qh';
}

function daw_perf_ga_table() {
	global $wpdb;
	return $wpdb->prefix . 'daw_ga4_pw';
}

add_action( 'plugins_loaded', 'daw_perf_install', 7 );
function daw_perf_install() {
	if ( (int) get_option( 'daw_perf_db', 0 ) >= DAW_PERF_DB ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();

	dbDelta(
		'CREATE TABLE ' . daw_perf_qh_table() . " (
			wk date NOT NULL,
			page varchar(191) NOT NULL DEFAULT '',
			query varchar(191) NOT NULL DEFAULT '',
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			impressions int(10) unsigned NOT NULL DEFAULT 0,
			position float NOT NULL DEFAULT 0,
			PRIMARY KEY  (wk,page,query),
			KEY page (page)
		) {$charset};"
	);

	dbDelta(
		'CREATE TABLE ' . daw_perf_ga_table() . " (
			wk char(6) NOT NULL DEFAULT '',
			page varchar(191) NOT NULL DEFAULT '',
			views int(10) unsigned NOT NULL DEFAULT 0,
			users int(10) unsigned NOT NULL DEFAULT 0,
			eng float NOT NULL DEFAULT 0,
			PRIMARY KEY  (wk,page),
			KEY page (page)
		) {$charset};"
	);

	update_option( 'daw_perf_db', DAW_PERF_DB, true );
}

/* ======================================================== search history == */

/** Monday of the week a day falls in, Y-m-d. */
function daw_perf_monday( $ymd ) {
	$t = strtotime( $ymd . ' 00:00:00 UTC' );
	return gmdate( 'Y-m-d', $t - ( ( (int) gmdate( 'N', $t ) - 1 ) * DAY_IN_SECONDS ) );
}

/** Store one 28-day page×query window as the snapshot of the week it ends in. */
function daw_perf_qh_store( array $rows, $end ) {
	global $wpdb;
	$wk = daw_perf_monday( $end );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . daw_perf_qh_table() . ' WHERE wk = %s', $wk ) ); // phpcs:ignore WordPress.DB
	foreach ( array_chunk( $rows, 400 ) as $chunk ) {
		$values = array();
		foreach ( $chunk as $r ) {
			$values[] = $wpdb->prepare(
				'(%s, %s, %s, %d, %d, %f)',
				$wk,
				substr( daw_gsc_path( $r['keys'][0] ), 0, 191 ),
				mb_substr( mb_strtolower( (string) $r['keys'][1] ), 0, 191 ),
				(int) $r['clicks'],
				(int) $r['impressions'],
				(float) $r['position']
			);
		}
		if ( $values ) {
			$wpdb->query( 'INSERT IGNORE INTO ' . daw_perf_qh_table() . ' (wk, page, query, clicks, impressions, position) VALUES ' . implode( ',', $values ) ); // phpcs:ignore WordPress.DB
		}
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . daw_perf_qh_table() . ' WHERE wk < %s', gmdate( 'Y-m-d', time() - 26 * WEEK_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
}

/** seo-gsc.php hands over each fresh 28-day window it fetched. */
/**
 * New searches send a record back to the audit (madde 73, 1.75.60, owner
 * 9 Oct 2026: "yeni arama gelirse bakılacak deyip kontrol ettirsin").
 * After each Search Console sync, every audited record whose set of searches
 * changed is audited again within the hour. A new search the page does not
 * answer opens a waiting line, so the record leaves Tam bitti and shows in
 * Bakılacak until the search is written in or set aside with a reason.
 * The set already looked at is kept per record (_daw_gsc_seen, a hash).
 */
add_action( 'daw_gsc_queries_synced', 'daw_perf_requeue_changed', 30, 2 );
function daw_perf_requeue_changed( $rows, $end ) {
	if ( ! function_exists( 'daw_ae_prep_store' ) || ! function_exists( 'daw_gsc_path' ) ) {
		return;
	}
	$by_page = array();
	foreach ( (array) $rows as $r ) {
		$path = untrailingslashit( (string) daw_gsc_path( $r['keys'][0] ?? '' ) );
		$q    = mb_strtolower( trim( (string) ( $r['keys'][1] ?? '' ) ) );
		if ( '' !== $path && '' !== $q ) {
			$by_page[ $path ][ $q ] = true;
		}
	}
	if ( ! $by_page ) {
		return;
	}
	$ids = function_exists( 'daw_audit_record_ids' ) && function_exists( 'daw_ae_all_types' ) ? (array) daw_audit_record_ids( daw_ae_all_types() ) : array();
	$n   = 0;
	foreach ( $ids as $id ) {
		$id   = (int) $id;
		$path = untrailingslashit( (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH ) );
		if ( '' === $path || empty( $by_page[ $path ] ) ) {
			continue;
		}
		$qs = array_keys( $by_page[ $path ] );
		sort( $qs );
		$hash = md5( implode( "\n", $qs ) );
		$seen = (string) get_post_meta( $id, '_daw_gsc_seen', true );
		if ( $hash === $seen ) {
			continue;
		}
		// The first sync after the update counts as a change too: audits stored
		// before 1.75.58 never looked at Search Console at all.
		update_post_meta( $id, '_daw_gsc_seen', $hash );
		if ( ! wp_next_scheduled( 'daw_ae_one', array( $id ) ) ) {
			// Spread over the hour so a sync touching many records does not
			// fetch every page at once.
			wp_schedule_single_event( time() + 120 + 20 * $n++, 'daw_ae_one', array( $id ) );
		}
	}
}

add_action( 'daw_gsc_queries_synced', 'daw_perf_on_queries', 10, 2 );
function daw_perf_on_queries( $rows, $end ) {
	if ( (int) get_option( 'daw_perf_db', 0 ) < DAW_PERF_DB ) {
		return;
	}
	daw_perf_qh_store( (array) $rows, (string) $end );

	// Three earlier windows, once, so the first look already shows a trend.
	if ( ! get_option( 'daw_perf_backfilled' ) && function_exists( 'daw_gsc_query' ) ) {
		update_option( 'daw_perf_backfilled', time(), false );
		for ( $k = 1; $k <= 3; $k++ ) {
			$e   = gmdate( 'Y-m-d', strtotime( $end . ' -' . ( 28 * $k ) . ' days' ) );
			$s   = gmdate( 'Y-m-d', strtotime( $e . ' -27 days' ) );
			$old = daw_gsc_query( array( 'startDate' => $s, 'endDate' => $e, 'dimensions' => array( 'page', 'query' ), 'type' => 'web' ) );
			if ( ! is_wp_error( $old ) ) {
				daw_perf_qh_store( (array) $old, $e );
			}
		}
	}
}

/* ======================================================== analytics weeks == */

add_action( 'daw_ga4_daily', 'daw_perf_ga_run', 30 );
add_action( 'daw_kc_run_all_late', 'daw_perf_ga_run', 30 );
function daw_perf_ga_run() {
	if ( (int) get_option( 'daw_perf_db', 0 ) < DAW_PERF_DB || ! function_exists( 'daw_ga4_report' ) ) {
		return;
	}
	$res = daw_ga4_report(
		array(
			'dateRanges' => array( array( 'startDate' => '91daysAgo', 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'isoYearIsoWeek' ), array( 'name' => 'pagePath' ) ),
			'metrics'    => array( array( 'name' => 'screenPageViews' ), array( 'name' => 'activeUsers' ), array( 'name' => 'userEngagementDuration' ) ),
			'limit'      => 25000,
		)
	);
	if ( is_wp_error( $res ) ) {
		update_option( 'daw_perf_ga_err', $res->get_error_message(), false );
		return;
	}
	global $wpdb;
	$rows = daw_ga4_rows( $res );
	$wpdb->query( 'TRUNCATE TABLE ' . daw_perf_ga_table() ); // phpcs:ignore WordPress.DB
	foreach ( array_chunk( $rows, 400 ) as $chunk ) {
		$values = array();
		foreach ( $chunk as $r ) {
			$path = (string) wp_parse_url( (string) $r[1], PHP_URL_PATH );
			if ( '' === $path ) {
				continue;
			}
			$values[] = $wpdb->prepare( '(%s, %s, %d, %d, %f)', substr( (string) $r[0], 0, 6 ), substr( $path, 0, 191 ), (int) $r[2], (int) $r[3], (float) $r[4] );
		}
		if ( $values ) {
			// The same path can come twice (with and without a trailing slash
			// collapsed above); the sums are what count.
			$wpdb->query( 'INSERT INTO ' . daw_perf_ga_table() . ' (wk, page, views, users, eng) VALUES ' . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE views = views + VALUES(views), users = users + VALUES(users), eng = eng + VALUES(eng)' ); // phpcs:ignore WordPress.DB
		}
	}
	update_option( 'daw_perf_ga_at', time(), false );
	delete_option( 'daw_perf_ga_err' );
}

/* =============================================================== reading == */

/** Our path forms, as Search Console and Analytics store them. */
function daw_perf_paths( $pid ) {
	$p = (string) wp_parse_url( (string) get_permalink( $pid ), PHP_URL_PATH );
	return array_values( array_unique( array( $p, untrailingslashit( $p ), trailingslashit( $p ) ) ) );
}

/** Search Console totals for a day range [from, to] for one page. */
function daw_perf_gsc_range( $paths, $from, $to ) {
	global $wpdb;
	if ( ! function_exists( 'daw_gsc_table' ) ) {
		return null;
	}
	$in  = implode( ',', array_fill( 0, count( $paths ), '%s' ) );
	$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			'SELECT SUM(clicks) c, SUM(impressions) i, IF(SUM(impressions) > 0, SUM(position * impressions) / SUM(impressions), NULL) p FROM ' . daw_gsc_table() . " WHERE page IN ($in) AND day BETWEEN %s AND %s", // phpcs:ignore WordPress.DB
			array_merge( $paths, array( $from, $to ) )
		),
		ARRAY_A
	);
	return array( (int) ( $row['c'] ?? 0 ), (int) ( $row['i'] ?? 0 ), null !== ( $row['p'] ?? null ) ? round( (float) $row['p'], 1 ) : null );
}

/** Thirteen weekly points of impressions, clicks and position for one page. */
function daw_perf_gsc_weeks( $paths, $last ) {
	global $wpdb;
	$in   = implode( ',', array_fill( 0, count( $paths ), '%s' ) );
	$from = gmdate( 'Y-m-d', strtotime( $last . ' -90 days' ) );
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			'SELECT YEARWEEK(day, 3) w, SUM(clicks) c, SUM(impressions) i, IF(SUM(impressions) > 0, SUM(position * impressions) / SUM(impressions), NULL) p FROM ' . daw_gsc_table() . " WHERE page IN ($in) AND day BETWEEN %s AND %s GROUP BY w ORDER BY w", // phpcs:ignore WordPress.DB
			array_merge( $paths, array( $from, $last ) )
		),
		ARRAY_A
	);
	return (array) $rows;
}

/** The current searches for one page, each with its position about a month earlier. */
function daw_perf_queries( $paths, $limit = 15 ) {
	global $wpdb;
	if ( (int) get_option( 'daw_perf_db', 0 ) < DAW_PERF_DB ) {
		return array( array(), '' );
	}
	$in  = implode( ',', array_fill( 0, count( $paths ), '%s' ) );
	$now = (string) $wpdb->get_var( 'SELECT MAX(wk) FROM ' . daw_perf_qh_table() ); // phpcs:ignore WordPress.DB
	if ( '' === $now ) {
		return array( array(), '' );
	}
	$then = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(wk) FROM ' . daw_perf_qh_table() . ' WHERE wk <= %s', gmdate( 'Y-m-d', strtotime( $now . ' -25 days' ) ) ) ); // phpcs:ignore WordPress.DB

	$cur = $wpdb->get_results( // phpcs:ignore WordPress.DB
		$wpdb->prepare( 'SELECT query, SUM(clicks) c, SUM(impressions) i, SUM(position * impressions) / NULLIF(SUM(impressions), 0) p FROM ' . daw_perf_qh_table() . " WHERE wk = %s AND page IN ($in) GROUP BY query ORDER BY c DESC, i DESC LIMIT %d", array_merge( array( $now ), $paths, array( (int) $limit ) ) ), // phpcs:ignore WordPress.DB
		ARRAY_A
	);
	$old = array();
	if ( '' !== $then ) {
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT query, SUM(position * impressions) / NULLIF(SUM(impressions), 0) p FROM ' . daw_perf_qh_table() . " WHERE wk = %s AND page IN ($in) GROUP BY query", array_merge( array( $then ), $paths ) ), ARRAY_A ) as $o ) { // phpcs:ignore WordPress.DB
			$old[ $o['query'] ] = null !== $o['p'] ? (float) $o['p'] : null;
		}
	}
	$out = array();
	foreach ( (array) $cur as $r ) {
		$out[ $r['query'] ] = array(
			'c'    => (int) $r['c'],
			'i'    => (int) $r['i'],
			'p'    => null !== $r['p'] ? round( (float) $r['p'], 1 ) : null,
			'prev' => array_key_exists( $r['query'], $old ) ? $old[ $r['query'] ] : false,
		);
	}
	return array( $out, $then );
}

/** Every search this page showed for in the latest snapshot, for the keyword table. */
function daw_perf_query_map( $pid ) {
	static $memo = array();
	if ( isset( $memo[ $pid ] ) ) {
		return $memo[ $pid ];
	}
	list( $q ) = daw_perf_queries( daw_perf_paths( $pid ), 500 );
	return $memo[ $pid ] = $q;
}

/** Analytics weeks for one page. */
function daw_perf_ga_weeks( $paths ) {
	global $wpdb;
	if ( (int) get_option( 'daw_perf_db', 0 ) < DAW_PERF_DB ) {
		return array();
	}
	$in = implode( ',', array_fill( 0, count( $paths ), '%s' ) );
	return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
		$wpdb->prepare( 'SELECT wk, SUM(views) v, SUM(users) u, SUM(eng) e FROM ' . daw_perf_ga_table() . " WHERE page IN ($in) GROUP BY wk ORDER BY wk", $paths ), // phpcs:ignore WordPress.DB
		ARRAY_A
	);
}

/* ================================================================ drawing == */

/** A small line, inline SVG, no library. Lower is better when $invert. */
function daw_perf_spark( array $vals, $color = '#2271b1', $invert = false ) {
	$vals = array_values( $vals );
	$n    = count( $vals );
	if ( $n < 2 ) {
		return '';
	}
	$nums = array_filter( $vals, 'is_numeric' );
	if ( ! $nums ) {
		return '';
	}
	$min = min( $nums );
	$max = max( $nums );
	$rng = $max - $min ?: 1;
	$w   = 140;
	$h   = 34;
	$pts = array();
	foreach ( $vals as $k => $v ) {
		if ( ! is_numeric( $v ) ) {
			continue;
		}
		$y     = ( $v - $min ) / $rng;
		$y     = $invert ? $y : 1 - $y;
		$pts[] = round( $k * ( $w - 4 ) / ( $n - 1 ) + 2, 1 ) . ',' . round( $y * ( $h - 6 ) + 3, 1 );
	}
	return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true" style="display:block"><polyline fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="' . esc_attr( implode( ' ', $pts ) ) . '"/></svg>';
}

/** "+12 %", "−3", coloured. */
function daw_perf_delta( $now, $prev, $lower_better = false, $pct = true ) {
	if ( null === $now || null === $prev || false === $prev ) {
		return '<span class="pf-d">yeni</span>';
	}
	$d = $now - $prev;
	if ( abs( $d ) < 0.05 ) {
		return '<span class="pf-d">=</span>';
	}
	$good = $lower_better ? $d < 0 : $d > 0;
	if ( $pct && $prev > 0 ) {
		$txt = sprintf( '%s%%%d', $d > 0 ? '+' : '−', (int) round( abs( $d ) / $prev * 100 ) );
	} else {
		$txt = ( $d > 0 ? '+' : '−' ) . number_format_i18n( abs( $d ), $pct ? 0 : 1 );
	}
	if ( $lower_better ) {
		$txt = ( $d < 0 ? '▲ ' : '▼ ' ) . number_format_i18n( abs( $d ), 1 );
	}
	return '<span class="pf-d ' . ( $good ? 'is-up' : 'is-down' ) . '">' . esc_html( $txt ) . '</span>';
}

/** The card on the audit page. */
function daw_perf_card( $pid ) {
	$paths = daw_perf_paths( $pid );
	$s     = function_exists( 'daw_gsc_settings' ) ? daw_gsc_settings() : array();
	$last  = (string) ( $s['last_day'] ?? '' );

	echo '<style>.daw-pf{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px;max-width:1400px;margin:16px 0}.daw-pf h2{margin:2px 0 10px;font-size:16px}.daw-pf-g{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}.daw-pf-k{border:1px solid #eef0f2;border-radius:8px;padding:10px 12px}.daw-pf-k b{display:block;font-size:22px;line-height:1.2}.daw-pf-k span{color:#50575e;font-size:12px}.pf-d{font-size:12px;font-weight:600;color:#50575e;margin-left:6px}.pf-d.is-up{color:#1a7f37}.pf-d.is-down{color:#b32d2e}.daw-pf table{margin-top:10px}.daw-pf .pf-new{color:#7a5a12;font-size:11px;border:1px solid #e8d9a8;border-radius:4px;padding:0 4px;margin-left:6px}.daw-pf .pf-note{color:#50575e;font-size:12px;margin:8px 0 0}</style>';
	echo '<div class="daw-pf" id="daw-perf"><h2>Performans: Google araması ve okur trafiği</h2>';

	// ---- Search Console totals, 28 days against the 28 before.
	if ( '' !== $last && function_exists( 'daw_gsc_table' ) ) {
		$a_to   = $last;
		$a_from = gmdate( 'Y-m-d', strtotime( $last . ' -27 days' ) );
		$b_to   = gmdate( 'Y-m-d', strtotime( $a_from . ' -1 day' ) );
		$b_from = gmdate( 'Y-m-d', strtotime( $b_to . ' -27 days' ) );
		$now    = daw_perf_gsc_range( $paths, $a_from, $a_to );
		$prev   = daw_perf_gsc_range( $paths, $b_from, $b_to );
		$weeks  = daw_perf_gsc_weeks( $paths, $last );
		$ga     = daw_perf_ga_weeks( $paths );

		$ga_now  = array_slice( $ga, -4 );
		$ga_prev = array_slice( $ga, -8, 4 );
		$sum     = static function ( $rows, $k ) {
			return array_sum( array_map( static function ( $r ) use ( $k ) { return (float) $r[ $k ]; }, $rows ) );
		};
		$v_now   = $sum( $ga_now, 'v' );
		$v_prev  = count( $ga_prev ) ? $sum( $ga_prev, 'v' ) : null;
		$u_now   = $sum( $ga_now, 'u' );
		$e_now   = $u_now > 0 ? $sum( $ga_now, 'e' ) / $u_now : 0;

		echo '<div class="daw-pf-g">';
		printf( '<div class="daw-pf-k"><span>Google gösterim (28 gün)</span><b>%s%s</b>%s</div>', esc_html( number_format_i18n( $now[1] ) ), daw_perf_delta( $now[1], $prev[1] ), daw_perf_spark( array_map( static function ( $r ) { return (int) $r['i']; }, $weeks ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<div class="daw-pf-k"><span>Google tıklama (28 gün)</span><b>%s%s</b>%s</div>', esc_html( number_format_i18n( $now[0] ) ), daw_perf_delta( $now[0], $prev[0] ), daw_perf_spark( array_map( static function ( $r ) { return (int) $r['c']; }, $weeks ), '#1a7f37' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<div class="daw-pf-k"><span>Ortalama sıra (küçük daha iyi)</span><b>%s%s</b>%s</div>', null !== $now[2] ? esc_html( number_format_i18n( $now[2], 1 ) ) : '·', null !== $now[2] && null !== $prev[2] ? daw_perf_delta( $now[2], $prev[2], true ) : '', daw_perf_spark( array_map( static function ( $r ) { return null !== $r['p'] ? (float) $r['p'] : null; }, $weeks ), '#8a2be2', true ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<div class="daw-pf-k"><span>Okur görüntüleme (son 4 hafta)</span><b>%s%s</b>%s</div>', esc_html( number_format_i18n( $v_now ) ), null !== $v_prev ? daw_perf_delta( $v_now, $v_prev ) : '', daw_perf_spark( array_map( static function ( $r ) { return (int) $r['v']; }, $ga ), '#d63638' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<div class="daw-pf-k"><span>Okur başına süre</span><b>%s</b><span>%s okur</span></div>', esc_html( $e_now > 0 ? gmdate( $e_now >= 3600 ? 'G:i:s' : 'i:s', (int) $e_now ) : '·' ), esc_html( number_format_i18n( $u_now ) ) );
		echo '</div>';
		printf( '<p class="pf-note">Google: %s ile %s, önceki 28 günle karşılaştırma; çizgiler 13 haftalık. Okur: Analytics, haftalık; %s öncesi haftalarda bot trafiği de sayılıyordu.</p>', esc_html( wp_date( 'j M', strtotime( $a_from ) ) ), esc_html( wp_date( 'j M Y', strtotime( $a_to ) ) ), esc_html( wp_date( 'j M', strtotime( DAW_PERF_BOT_GATE_DAY ) ) ) );
	} else {
		echo '<p class="pf-note">Search Console verisi henüz yok.</p>';
	}

	// ---- The searches that bring this page.
	// Every query, not a top 15, each with its place on the page and what is
	// to be done with it (madde 73). Same rows and the same classification as
	// the audit line, so the two always agree.
	$grows = function_exists( 'daw_ae_gsc_rows' ) ? daw_ae_gsc_rows( $pid ) : array();
	if ( $grows ) {
		$kw   = get_post_meta( $pid, '_daw_kw', true );
		$ours = array();
		foreach ( (array) $kw as $k ) {
			$ours[] = mb_strtolower( trim( (string) ( is_array( $k ) ? ( $k['kw'] ?? '' ) : $k ) ) );
		}
		$tabs = array(
			'miss' => array( 'Bakılacak', '#a8431a' ),
			'ok'   => array( 'Karşılanıyor', '#1a7f37' ),
			'var'  => array( 'Yazım varyantı', '#27466f' ),
			'skip' => array( 'Bilinçli dışarıda', '#50575e' ),
		);
		$n = array_fill_keys( array_keys( $tabs ), 0 );
		foreach ( $grows as $r ) {
			++$n[ $r['st'] ];
		}
		$first = $n['miss'] ? 'miss' : 'all';
		echo '<div class="daw-gq" data-tab="' . esc_attr( $first ) . '">';
		echo '<style>.daw-gq .gq-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}.daw-gq .gq-tabs button{border:1px solid #dcdcde;background:#f6f7f7;border-radius:999px;padding:3px 10px;cursor:pointer;font-size:12px}.daw-gq .gq-tabs button.is-on{background:#1d2327;color:#fff;border-color:#1d2327}.daw-gq tr[data-st]{display:none}.daw-gq[data-tab=all] tr[data-st],.daw-gq[data-tab=miss] tr[data-st=miss],.daw-gq[data-tab=ok] tr[data-st=ok],.daw-gq[data-tab=var] tr[data-st=var],.daw-gq[data-tab=skip] tr[data-st=skip]{display:table-row}</style>';
		printf(
			'<p style="margin:6px 0">%s</p>',
			$n['miss']
				? sprintf( '<strong style="color:#a8431a">%d / %d arama bakılacak.</strong> Her biri kontrol edilir: Sayfanın o soruyu en doğal cevaplayan yerine (çoğu zaman SSS ya da ilgili bölüm) bir kez, doğru bir cümleyle yazılır. Sayfaya ait olmayan arama (başka kişi, başka yapım, doğrulanmamış iddia, kaynağı olmayan bilgi) _daw_ok[\'gsc:&lt;arama&gt;\'] ile gerekçesiyle dışarıda bırakılır.', (int) $n['miss'], count( $grows ) ) // phpcs:ignore WordPress.Security.EscapeOutput
				: sprintf( '<span style="color:#1a7f37">%d aramanın hepsi karşılanmış ya da kararı verilmiş.</span>', count( $grows ) ) // phpcs:ignore WordPress.Security.EscapeOutput
		); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="gq-tabs">';
		printf( '<button type="button" data-t="all" class="%s">Tümü (%d)</button>', 'all' === $first ? 'is-on' : '', count( $grows ) );
		foreach ( $tabs as $k => $t ) {
			printf( '<button type="button" data-t="%s" class="%s">%s (%d)</button>', esc_attr( $k ), $k === $first ? 'is-on' : '', esc_html( $t[0] ), (int) $n[ $k ] );
		}
		echo '</div>';
		echo '<table class="widefat striped"><thead><tr><th>Bu sayfayı getiren aramalar (28 gün)</th><th>Bu sayfada nerede</th><th style="width:80px">Tıklama</th><th style="width:90px">Gösterim</th><th style="width:70px">Sıra</th><th style="width:120px">Bir ay önceye göre</th></tr></thead><tbody>';
		foreach ( $grows as $q => $r ) {
			$st = $r['st'];
			if ( 'ok' === $st ) {
				$cell = '<span style="color:#1a7f37">' . esc_html( implode( ', ', $r['at'] ) ) . '</span>';
			} elseif ( 'var' === $st ) {
				$cell = '<span style="color:#27466f">Yazım varyantı: ' . esc_html( $r['note'] ) . '</span> <small style="color:#50575e">Google eşliyor, sayfaya yazılmaz</small>';
			} elseif ( 'skip' === $st ) {
				$cell = '<span style="color:#50575e">Bilinçli dışarıda: ' . esc_html( $r['note'] ) . '</span>';
			} else {
				$cell = '<strong style="color:#a8431a">Bakılacak: sayfada yok</strong>';
			}
			if ( false === ( $r['prev'] ?? null ) ) {
				$cell .= ' <span class="pf-new" title="Bir ay önce bu sayfayı bu aramada göstermiyordu">yeni</span>';
			}
			printf(
				'<tr data-st="%s"><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_attr( $st ),
				esc_html( $q ),
				in_array( $q, $ours, true ) ? '' : '<span class="pf-new" title="Aranan kalıplar listesinde yok">fırsat</span>',
				$cell, // phpcs:ignore WordPress.Security.EscapeOutput
				esc_html( number_format_i18n( (int) $r['c'] ) ),
				esc_html( number_format_i18n( (int) $r['i'] ) ),
				null !== $r['p'] ? esc_html( number_format_i18n( $r['p'], 1 ) ) : '·',
				daw_perf_delta( $r['p'], $r['prev'], true ) // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}
		echo '</tbody></table>';
		echo '<script>(function(){var w=document.currentScript.closest(".daw-gq");if(!w){return;}w.querySelectorAll(".gq-tabs button").forEach(function(x){x.addEventListener("click",function(){w.setAttribute("data-tab",x.getAttribute("data-t"));w.querySelectorAll(".gq-tabs button").forEach(function(y){y.classList.toggle("is-on",y===x);});});});})();</script>';
		echo '<p class="pf-note">"Bu sayfada nerede": aramanın kelimeleri SEO başlığında, açıklamada, bir başlıkta, SSS\'te ya da sayfa metninde geçiyor mu (Seo Kang Jun = Seo Kang Joon, colour = color). "Yazım varyantı": arama yalnız bu kaydın adı, başka yazımla ya da harf hatasıyla; Google bunları zaten sayfaya eşliyor, hatalı yazımı sayfaya koymak bir şey kazandırmaz. "fırsat": arama, aranan kalıplar listemizde yok. ▲ sıra iyileşti, ▼ geriledi.</p>';
		echo '</div>';
	} elseif ( function_exists( 'daw_gsc_table' ) ) {
		echo '<p class="pf-note">Son 28 günde Google bu sayfayı hiçbir aramada göstermedi.</p>';
	}

	echo '</div>';
}

/* First look should not wait a day: fetch once, a minute after the update. */
add_action( 'admin_init', 'daw_perf_first_schedule' );
function daw_perf_first_schedule() {
	if ( get_option( 'daw_perf_backfilled' ) || ! function_exists( 'daw_gsc_creds' ) || ! daw_gsc_creds() || wp_next_scheduled( 'daw_perf_first' ) ) {
		return;
	}
	wp_schedule_single_event( time() + 60, 'daw_perf_first' );
}
add_action( 'daw_perf_first', 'daw_perf_first_run' );
function daw_perf_first_run() {
	if ( function_exists( 'daw_gsc_sync_queries' ) ) {
		daw_gsc_sync_queries();
	}
	daw_perf_ga_run();
}
