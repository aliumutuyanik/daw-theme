<?php
/**
 * Discover Asian World: the control centre (DAW › Genel bakış).
 *
 * WHAT IT IS FOR
 * --------------
 * The owner asked for one screen that says, at a glance and in plain words,
 * whether the whole site is in order: speed, Google, the SEO engine, links,
 * content, pictures, the background jobs, the server, the APIs and the ads
 * (4 Oct 2026). Every number here is read from data the site already keeps;
 * nothing on this screen calls an outside service, so it opens instantly.
 *
 * HOW IT READS
 * ------------
 * 1. The health score: one number out of 100 and one sentence.
 * 2. "Yapılacaklar": only what needs a person, most urgent first, each with
 *    the button that goes to the fix. Things the site repairs by itself are
 *    not listed as work.
 * 3. One card per area, each with a green, amber or red state, the number
 *    that matters most, and a link to the screen with the detail.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/* =============================================================== helpers == */

/** A number in Turkish format. */
function daw_kc_n( $v, $dec = 0 ) {
	return number_format_i18n( (float) $v, $dec );
}

/** "3 saat önce", or "henüz yok". */
function daw_kc_ago( $ts ) {
	$ts = (int) $ts;
	return $ts > 0 ? human_time_diff( $ts ) . ' önce' : 'henüz yok';
}

/** A circular score, drawn in SVG. */
function daw_kc_ring( $score, $size = 64, $label = '' ) {
	$score = max( 0, min( 100, (int) $score ) );
	$r     = 15.9155;
	$state = $score >= 90 ? 'ok' : ( $score >= 50 ? 'warn' : 'bad' );

	return sprintf(
		'<span class="kc-ringw"><span class="kc-ring is-%1$s" style="width:%2$dpx;height:%2$dpx"><svg viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="%3$s" class="kc-ring-bg"/><circle cx="18" cy="18" r="%3$s" class="kc-ring-fg" stroke-dasharray="%4$d 100"/></svg><b>%5$d</b></span>%6$s</span>',
		esc_attr( $state ),
		(int) $size,
		esc_attr( (string) $r ),
		$score,
		$score,
		'' !== $label ? '<small>' . esc_html( $label ) . '</small>' : ''
	);
}

/** A thin bar chart from a list of numbers. */
function daw_kc_spark( array $values, $w = 280, $h = 54 ) {
	$values = array_values( array_map( 'floatval', $values ) );
	$count  = count( $values );
	if ( $count < 2 ) {
		return '';
	}
	$max  = max( $values );
	$max  = $max > 0 ? $max : 1;
	$step = $w / $count;
	$bar  = max( 1.5, $step - 2 );
	$out  = '';
	foreach ( $values as $i => $v ) {
		$bh   = max( 1.5, round( $v / $max * ( $h - 4 ), 1 ) );
		$out .= sprintf( '<rect x="%s" y="%s" width="%s" height="%s" rx="1.5"/>', round( $i * $step, 1 ), round( $h - $bh, 1 ), round( $bar, 1 ), $bh );
	}
	return sprintf( '<svg class="kc-spark" viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" aria-hidden="true">%3$s</svg>', (int) $w, (int) $h, $out );
}

/** A split bar: parts as [ value, state ]. */
function daw_kc_split( array $parts ) {
	$total = array_sum( wp_list_pluck( $parts, 0 ) );
	if ( $total <= 0 ) {
		return '<span class="kc-split"><i class="is-none" style="width:100%"></i></span>';
	}
	$out = '';
	foreach ( $parts as $p ) {
		if ( $p[0] > 0 ) {
			$out .= sprintf( '<i class="is-%s" style="width:%s%%"></i>', esc_attr( $p[1] ), round( $p[0] / $total * 100, 2 ) );
		}
	}
	return '<span class="kc-split">' . $out . '</span>';
}

/** Change against the previous period, as a coloured arrow. */
function daw_kc_delta( $now, $before ) {
	$now    = (float) $now;
	$before = (float) $before;
	if ( $before <= 0 ) {
		return $now > 0 ? '<span class="kc-delta is-up">yeni</span>' : '';
	}
	$pct = ( $now - $before ) / $before * 100;
	$cls = $pct >= 0.5 ? 'is-up' : ( $pct <= -0.5 ? 'is-down' : 'is-flat' );
	$arr = $pct >= 0.5 ? '▲' : ( $pct <= -0.5 ? '▼' : '■' );
	return sprintf( '<span class="kc-delta %s">%s %%%s</span>', $cls, $arr, daw_kc_n( abs( $pct ), abs( $pct ) < 10 ? 1 : 0 ) );
}

function daw_kc_seo_url( $tab ) {
	return function_exists( 'daw_sa_url' ) ? daw_sa_url( $tab ) : admin_url( 'admin.php?page=daw-seo&tab=' . $tab );
}

/* ================================================================== data == */

/**
 * Everything the screen shows, read once. Each area is
 * [ state, title, ... ] where state is ok | warn | bad | info.
 */
function daw_kc_data() {
	global $wpdb;

	$d = array();

	/* --- speed: the three mobile PageSpeed reports --- */
	$psi   = get_option( 'daw_psi_detail' );
	$psi   = is_array( $psi ) ? $psi : array();
	$psid  = get_option( 'daw_psi_detail_d' );
	$psid  = is_array( $psid ) ? $psid : array();
	$names = function_exists( 'daw_psi_target_labels' ) ? daw_psi_target_labels() : array( 'home' => 'Ana sayfa', 'drama' => 'Dizi', 'list' => 'Dizi listesi' );
	$pages = array();
	foreach ( $names as $k => $label ) {
		if ( isset( $psi[ $k ]['score'] ) ) {
			$pages[ $k ] = array(
				'label' => $label,
				'score' => (int) $psi[ $k ]['score'],
				'lcp'   => isset( $psi[ $k ]['metrics']['largest-contentful-paint'] ) ? (int) $psi[ $k ]['metrics']['largest-contentful-paint'] : 0,
				'cls'   => isset( $psi[ $k ]['metrics']['cumulative-layout-shift'] ) ? (float) $psi[ $k ]['metrics']['cumulative-layout-shift'] : 0,
				'tbt'   => isset( $psi[ $k ]['metrics']['total-blocking-time'] ) ? (int) $psi[ $k ]['metrics']['total-blocking-time'] : 0,
				'kb'    => isset( $psi[ $k ]['requests'] ) ? array_sum( wp_list_pluck( (array) $psi[ $k ]['requests'], 'kb' ) ) : 0,
				'at'    => (int) ( $psi[ $k ]['at'] ?? 0 ),
				'url'   => (string) ( $psi[ $k ]['url'] ?? '' ),
				'd'     => isset( $psid[ $k ]['score'] ) ? (int) $psid[ $k ]['score'] : null,
				'd_lcp' => isset( $psid[ $k ]['metrics']['largest-contentful-paint'] ) ? (int) $psid[ $k ]['metrics']['largest-contentful-paint'] : 0,
				'd_cls' => isset( $psid[ $k ]['metrics']['cumulative-layout-shift'] ) ? (float) $psid[ $k ]['metrics']['cumulative-layout-shift'] : 0,
			);
		}
	}
	$dsc        = array_filter( wp_list_pluck( $pages, 'd' ), 'is_int' );
	$min        = $pages ? min( array_merge( wp_list_pluck( $pages, 'score' ), $dsc ) ) : 0;
	$d['speed'] = array(
		'state' => ! $pages ? 'info' : ( $min >= 90 ? 'ok' : ( $min >= 50 ? 'warn' : 'bad' ) ),
		'pages' => $pages,
		'avg'   => $pages ? (int) round( array_sum( wp_list_pluck( $pages, 'score' ) ) / count( $pages ) ) : 0,
		'avg_d' => $dsc ? (int) round( array_sum( $dsc ) / count( $dsc ) ) : 0,
		'at'    => $pages ? max( wp_list_pluck( $pages, 'at' ) ) : 0,
	);

	/* --- Search Console traffic --- */
	$gsc   = array( 'state' => 'info', 'connected' => false );
	$ready = function_exists( 'daw_gsc_settings' ) && (int) get_option( 'daw_gsc_db', 0 ) >= ( defined( 'DAW_GSC_DB_VERSION' ) ? DAW_GSC_DB_VERSION : 1 );
	if ( function_exists( 'daw_gsc_creds' ) ) {
		$s                = daw_gsc_settings();
		$gsc['connected'] = (bool) daw_gsc_creds();
		$gsc['error']     = (string) $s['error'];
		$gsc['note']      = (string) ( $s['inspect_note'] ?? '' );
		$gsc['property']  = (string) $s['property'];
		$gsc['last_day']  = (string) $s['last_day'];
		$gsc['synced_at'] = (int) $s['synced_at'];

		if ( $ready ) {
			$t    = daw_gsc_table();
			$last = (string) $wpdb->get_var( "SELECT MAX(day) FROM {$t}" ); // phpcs:ignore WordPress.DB
			$days = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT day) FROM {$t}" ); // phpcs:ignore WordPress.DB
			$gsc['days'] = $days;

			if ( '' !== $last ) {
				$from  = gmdate( 'Y-m-d', strtotime( $last . ' -27 days' ) );
				$pfrom = gmdate( 'Y-m-d', strtotime( $last . ' -55 days' ) );
				$pto   = gmdate( 'Y-m-d', strtotime( $last . ' -28 days' ) );

				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(clicks) c, SUM(impressions) i FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY day ORDER BY day", $from, $last ), ARRAY_A ); // phpcs:ignore WordPress.DB
				$by   = array();
				foreach ( (array) $rows as $r ) {
					$by[ $r['day'] ] = $r;
				}
				$clicks = array();
				$impr   = array();
				for ( $i = 27; $i >= 0; $i-- ) {
					$day      = gmdate( 'Y-m-d', strtotime( $last . " -{$i} days" ) );
					$clicks[] = (int) ( $by[ $day ]['c'] ?? 0 );
					$impr[]   = (int) ( $by[ $day ]['i'] ?? 0 );
				}
				$cur  = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(clicks) c, SUM(impressions) i, SUM(position*impressions)/NULLIF(SUM(impressions),0) p FROM {$t} WHERE day BETWEEN %s AND %s", $from, $last ), ARRAY_A ); // phpcs:ignore WordPress.DB
				$prev = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(clicks) c, SUM(impressions) i, SUM(position*impressions)/NULLIF(SUM(impressions),0) p FROM {$t} WHERE day BETWEEN %s AND %s", $pfrom, $pto ), ARRAY_A ); // phpcs:ignore WordPress.DB
				$top  = $wpdb->get_results( $wpdb->prepare( "SELECT page, SUM(clicks) c, SUM(impressions) i FROM {$t} WHERE day BETWEEN %s AND %s GROUP BY page ORDER BY c DESC, i DESC LIMIT 5", $from, $last ), ARRAY_A ); // phpcs:ignore WordPress.DB

				$gsc += array(
					'to'          => $last,
					'from'        => $from,
					'clicks'      => (int) ( $cur['c'] ?? 0 ),
					'impr'        => (int) ( $cur['i'] ?? 0 ),
					'pos'         => (float) ( $cur['p'] ?? 0 ),
					'prev_clicks' => (int) ( $prev['c'] ?? 0 ),
					'prev_impr'   => (int) ( $prev['i'] ?? 0 ),
					'prev_pos'    => (float) ( $prev['p'] ?? 0 ),
					'spark_c'     => $clicks,
					'spark_i'     => $impr,
					'top'         => (array) $top,
				);
			}

			$it = daw_gsc_inspect_table();
			$gsc['inspected']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$it}" ); // phpcs:ignore WordPress.DB
			$gsc['not_indexed'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$it} WHERE verdict <> 'PASS'" ); // phpcs:ignore WordPress.DB
			$gsc['reasons']     = (array) $wpdb->get_results( "SELECT coverage, COUNT(*) n FROM {$it} WHERE verdict <> 'PASS' GROUP BY coverage ORDER BY n DESC LIMIT 4", ARRAY_A ); // phpcs:ignore WordPress.DB
		}

		$end              = gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );
		$gsc['backfill']  = '' === $gsc['last_day'] || $gsc['last_day'] < gmdate( 'Y-m-d', strtotime( $end . ' -3 days' ) );
		$gsc['state']     = ! $gsc['connected'] ? 'bad' : ( '' !== $gsc['error'] ? 'bad' : 'ok' );
	}
	$d['gsc'] = $gsc;

	/* --- Google index --- */
	$published = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page')" ); // phpcs:ignore WordPress.DB
	$ins       = (int) ( $gsc['inspected'] ?? 0 );
	$noi       = (int) ( $gsc['not_indexed'] ?? 0 );
	$d['index'] = array(
		'state'     => ! $gsc['connected'] ? 'info' : ( $ins && $noi / max( 1, $ins ) > 0.25 ? 'warn' : 'ok' ),
		'published' => $published,
		'inspected' => $ins,
		'indexed'   => max( 0, $ins - $noi ),
		'not'       => $noi,
		'reasons'   => $gsc['reasons'] ?? array(),
	);

	/* --- SEO engine, sitemap, redirects, 404 --- */
	$live = function_exists( 'daw_eng_live' ) && daw_eng_live();
	$rd   = array( 'redirects' => 0, 'auto' => 0, 'open404' => 0, 'hits7' => 0 );
	if ( function_exists( 'daw_rd_tables_ready' ) && daw_rd_tables_ready() ) {
		$rt              = daw_rd_table();
		$nt              = daw_nf_table();
		$rd['redirects'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rt} WHERE status = 'active'" ); // phpcs:ignore WordPress.DB
		$rd['auto']      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rt} WHERE origin IN ('auto','404')" ); // phpcs:ignore WordPress.DB
		$rd['hits']      = (int) $wpdb->get_var( "SELECT COALESCE(SUM(hits),0) FROM {$rt}" ); // phpcs:ignore WordPress.DB
		$open            = function_exists( 'daw_nf_open_sql' ) ? ' AND ' . daw_nf_open_sql( 'n' ) : '';
		$rd['open404']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$nt} n WHERE n.kind = 'page'{$open} AND NOT EXISTS (SELECT 1 FROM {$rt} r WHERE r.status = 'active' AND r.source = n.path)" ); // phpcs:ignore WordPress.DB
		$rd['hits7']     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(hits_human + hits_search),0) FROM {$nt} WHERE kind = 'page' AND last_seen >= %s", gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	}
	$sm_urls = 0;
	if ( function_exists( 'daw_sm_types' ) && function_exists( 'daw_sm_rows' ) ) {
		$sm_urls = (int) get_transient( 'daw_kc_sm_urls' );
		if ( ! $sm_urls ) {
			foreach ( (array) daw_sm_types() as $type ) {
				$sm_urls += count( (array) daw_sm_rows( $type ) );
			}
			set_transient( 'daw_kc_sm_urls', $sm_urls, HOUR_IN_SECONDS );
		}
	}
	$d['seo'] = array(
		'state'  => ! $live ? 'bad' : ( $rd['open404'] > 0 ? 'warn' : 'ok' ),
		'live'   => $live,
		'rm'     => function_exists( 'daw_eng_rm_active' ) && daw_eng_rm_active(),
		'since'  => (int) get_option( 'daw_seo_live_since', 0 ),
		'sm'     => $sm_urls,
		'robots' => '1' === (string) get_option( 'blog_public', '1' ),
		'auto404' => ! function_exists( 'daw_eng_set' ) || (bool) daw_eng_set( 'auto_404' ),
	) + $rd;

	/* --- internal links --- */
	$lk = array( 'broken' => 0, 'redirected' => 0, 'unlinked' => 0, 'posts' => 0 );
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", defined( 'DAW_LK_META' ) ? DAW_LK_META : '_daw_links' ) ) as $raw ) { // phpcs:ignore WordPress.DB
		$m = maybe_unserialize( $raw );
		if ( ! is_array( $m ) ) {
			continue;
		}
		$lk['posts']++;
		foreach ( array( 'broken', 'redirected', 'unlinked' ) as $k ) {
			$lk[ $k ] += count( (array) ( $m[ $k ] ?? array() ) );
		}
	}
	$lks         = (array) get_option( 'daw_lk_state', array() );
	$lk['pass']  = (int) ( $lks['pass_end'] ?? 0 );
	$lk['state'] = $lk['broken'] > 0 ? 'bad' : ( $lk['redirected'] > 0 ? 'warn' : 'ok' );
	$d['links']  = $lk;

	/* --- IndexNow --- */
	$log  = (array) get_option( 'daw_inx_log', array() );
	$lastx = $log ? end( $log ) : array();
	$week = 0;
	foreach ( $log as $row ) {
		if ( (int) ( $row['at'] ?? 0 ) > time() - WEEK_IN_SECONDS ) {
			$week += (int) ( $row['n'] ?? 0 );
		}
	}
	$code         = $lastx['code'] ?? '';
	$d['indexnow'] = array(
		'state' => ! $lastx ? 'info' : ( in_array( (int) $code, array( 200, 202 ), true ) ? 'ok' : 'warn' ),
		'last'  => (int) ( $lastx['at'] ?? 0 ),
		'code'  => $code,
		'week'  => $week,
		'queue' => count( array_filter( (array) get_option( 'daw_inx_queue', array() ) ) ),
	);

	/* --- archive, pictures, freshness, backlog (the twice-daily snapshot) --- */
	$snap = get_option( defined( 'DAW_SNAPSHOT_OPTION' ) ? DAW_SNAPSHOT_OPTION : 'daw_audit_snapshot' );
	$snap = is_array( $snap ) ? $snap : array();
	// Stale news is read live (1.75.0): a story checked an hour ago leaves the
	// list now, not at the next twice-daily snapshot.
	// Read from the stored run when it is under ten minutes old (1.75.23):
	// running it on every view rewrote an option and re-read every story.
	if ( function_exists( 'daw_news_stale_run' ) ) {
		$ns = get_option( 'daw_news_stale' );
		$ns = is_array( $ns ) && (int) ( $ns['at'] ?? 0 ) > time() - 10 * MINUTE_IN_SECONDS ? $ns : daw_news_stale_run();
		$snap['news_recheck'] = (array) ( $ns['rows'] ?? array() );
	}
	$tot  = (array) ( $snap['totals'] ?? array() );
	$arc  = (array) ( $snap['archive'] ?? array() );
	$ver  = (array) ( $snap['verified'] ?? array() );
	$pct  = (int) ( $arc['percent'] ?? 0 );
	$d['archive'] = array(
		// Completeness is a list of content work, not a fault on the site.
		'state'    => ! $snap ? 'info' : ( $pct >= 90 ? 'ok' : 'warn' ),
		'all'      => (int) ( $tot['all'] ?? 0 ),
		'titles'   => (int) ( $tot['titles'] ?? 0 ),
		'people'   => (int) ( $tot['people'] ?? 0 ),
		'articles' => (int) ( $tot['articles'] ?? 0 ),
		'complete' => (int) ( $arc['complete'] ?? 0 ),
		'percent'  => $pct,
		'verified' => (int) ( $ver['recent'] ?? ( $arc['verified']['count'] ?? 0 ) ),
		'at'       => (int) ( $snap['generated'] ?? 0 ),
	);

	$img = function_exists( 'daw_img_report' ) ? (array) daw_img_report() : array();
	// Only what a person can act on: no TMDB number, a number that points at
	// the wrong record or nowhere, a missing backup. "TMDB has no picture"
	// (the monogram is the right answer, rule 1.1), "not checked yet" and
	// "TMDB unreachable" sort themselves out.
	$bad   = 0;
	$parts = array();
	foreach ( $img as $gk => $g ) {
		$rows = (array) ( $g['rows'] ?? array() );
		// A backup still waiting its turn is a queue, not a fault: new records
		// opened in the morning turned the card yellow until the next run
		// copied their pictures (7 Oct 2026, 4 people). Only a failed copy counts.
		if ( 'no_backup' === $gk ) {
			$rows = array_filter( $rows, static fn( $r ) => 'Sırada' !== (string) ( $r[1] ?? '' ) );
		}
		$n = count( $rows );
		if ( ! empty( $g['problem'] ) && $n ) {
			$parts[ $gk ] = array( (string) ( $g['label'] ?? $gk ), $n );
			if ( in_array( $gk, array( 'no_id', 'mismatch', 'gone', 'no_backup' ), true ) ) {
				$bad += $n;
			}
		}
	}
	// THE WORK LIST, FOR THE CONTENT ROUNDS
	// ------------------------------------
	// Which records are behind each actionable count, with the reason, so a
	// round of fixes can start from one option read (daw_kc_report) instead of
	// opening the screens. Capped, and only what a person can act on.
	$work = array();
	foreach ( array( 'no_id', 'mismatch', 'gone', 'no_backup', 'unverified' ) as $gk ) {
		foreach ( array_slice( (array) ( $img[ $gk ]['rows'] ?? array() ), 0, 25 ) as $row ) {
			if ( 'no_backup' === $gk && 'Sırada' === (string) ( $row[1] ?? '' ) ) {
				continue;
			}
			$pid    = (int) ( $row[0] ?? 0 );
			$work[] = array( $gk, $pid, get_the_title( $pid ), function_exists( 'daw_get_type' ) ? (string) daw_get_type( $pid ) : '', (string) ( $row[1] ?? '' ), wp_make_link_relative( (string) get_permalink( $pid ) ) );
		}
	}
	foreach ( array_slice( (array) ( $snap['news_recheck'] ?? array() ), 0, 40 ) as $nr ) {
		$work[] = array( 'news_stale', (int) ( $nr['id'] ?? 0 ), (string) ( $nr['title'] ?? '' ), 'news', (string) ( $nr['reason'] ?? '' ), wp_make_link_relative( (string) ( $nr['url'] ?? '' ) ) );
	}
	// Crawled but left out: the content rounds start here (Google read these
	// and judged them not worth keeping yet).
	if ( function_exists( 'daw_ib_pending' ) ) {
		$n = 0;
		foreach ( daw_ib_pending() as $path => $cov ) {
			if ( false === stripos( $cov, 'Crawled' ) ) {
				continue;
			}
			$pid = url_to_postid( home_url( trailingslashit( $path ) ) );
			if ( ! $pid ) {
				continue;
			}
			$work[] = array( 'crawled_not_indexed', $pid, get_the_title( $pid ), function_exists( 'daw_get_type' ) ? (string) daw_get_type( $pid ) : '', $cov, trailingslashit( $path ) );
			if ( ++$n >= 15 ) {
				break;
			}
		}
	}
	// Search opportunities (opportunities.php), best first.
	if ( function_exists( 'daw_opp_rows' ) ) {
		$opps       = daw_opp_rows();
		$d['opps']  = array( 'n' => count( $opps ), 'new' => count( array_filter( $opps, static fn( $o ) => ! empty( $o['new'] ) ) ) );
		// Why the list is what it is, for the chat to read without a screen.
		$oo                 = get_option( 'daw_opps', array() );
		$d['opps']['at']    = (int) ( $oo['at'] ?? 0 );
		$d['opps']['wk']    = (string) ( $oo['wk'] ?? '' );
		$d['opps']['stat']  = (array) ( $oo['stat'] ?? array() );
		$d['opps']['q_at']  = (int) ( ( function_exists( 'daw_gsc_settings' ) ? daw_gsc_settings() : array() )['q_at'] ?? 0 );
		global $wpdb;
		if ( function_exists( 'daw_gsc_q_table' ) ) {
			$d['opps']['q_rows'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . daw_gsc_q_table() ); // phpcs:ignore WordPress.DB
		}
		if ( function_exists( 'daw_perf_qh_table' ) && (int) get_option( 'daw_perf_db', 0 ) >= 1 ) {
			$d['opps']['qh'] = $wpdb->get_results( 'SELECT wk, COUNT(*) n, SUM(impressions) imp FROM ' . daw_perf_qh_table() . ' GROUP BY wk ORDER BY wk DESC LIMIT 6', ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		foreach ( array_slice( $opps, 0, 25 ) as $o ) {
			$work[] = array( 'opp', (int) $o['pid'], get_the_title( (int) $o['pid'] ), (string) $o['q'], sprintf( '%d gösterim, %d tık, sıra %s%s', $o['imp'], $o['clk'], $o['pos'], $o['new'] ? ', yeni' : '' ), wp_make_link_relative( (string) get_permalink( (int) $o['pid'] ) ) );
		}
	}

	// First page of Google, no clicks: the snippet needs work.
	// A page edited after the Search Console window ended has had its
	// snippet looked at already; it waits for new data instead of being asked
	// for again (5 Oct 2026: five pages fixed that morning stayed on the list).
	$opp_wk = (string) ( get_option( 'daw_opps', array() )['wk'] ?? '' );
	foreach ( (array) ( get_option( 'daw_opps', array() )['ctr'] ?? array() ) as $c ) {
		if ( '' !== $opp_wk && (int) get_post_modified_time( 'U', true, (int) $c['pid'] ) > strtotime( $opp_wk . ' 23:59:59 UTC' ) ) {
			continue;
		}
		$work[] = array( 'ctr_low', (int) $c['pid'], get_the_title( (int) $c['pid'] ), (string) $c['q'], sprintf( 'sıra %s, %d gösterim, 0 tık: başlık/açıklama', $c['pos'], $c['imp'] ), wp_make_link_relative( (string) get_permalink( (int) $c['pid'] ) ) );
	}

	// Open 404s (Search Console still shows them, or readers hit them) with
	// the site's own best guess, for the chat to settle.
	if ( function_exists( 'daw_rd_tables_ready' ) && daw_rd_tables_ready() ) {
		global $wpdb;
		$nt = daw_nf_table();
		$rt = daw_rd_table();
		$open = function_exists( 'daw_nf_open_sql' ) ? ' AND ' . daw_nf_open_sql( 'n' ) : '';
		foreach ( (array) $wpdb->get_results( "SELECT n.path, n.hits_human, n.hits_search, n.last_ref FROM {$nt} n WHERE n.kind = 'page'{$open} AND NOT EXISTS (SELECT 1 FROM {$rt} r WHERE r.status = 'active' AND r.source = n.path) ORDER BY (n.hits_human + n.hits_search) DESC LIMIT 40", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$sug    = function_exists( 'daw_nf_suggest' ) ? daw_nf_suggest( $r['path'] ) : null;
			$work[] = array( 'open_404', 0, (string) $r['path'], (string) ( $r['last_ref'] ?? '' ), sprintf( '%d okur, %d arama', (int) $r['hits_human'], (int) $r['hits_search'] ), $sug ? wp_make_link_relative( (string) $sug['url'] ) . ' (' . $sug['why'] . ')' : '' );
		}
	}
	// Redirects that send readers somewhere wrong (redirect-audit.php).
	if ( function_exists( 'daw_rda_labels' ) ) {
		$lab = daw_rda_labels();
		foreach ( (array) ( get_option( 'daw_rda', array() )['rows'] ?? array() ) as $r ) {
			if ( isset( $lab[ $r[3] ] ) && 'info' !== $lab[ $r[3] ][0] ) {
				$work[] = array( 'redirect_' . $r[3], (int) $r[0], (string) $r[1], (string) $r[2], (string) $r[4], '' );
			}
		}
	}
	// Pictures that do not load (image-check.php).
	if ( function_exists( 'daw_ick_rows' ) ) {
		foreach ( daw_ick_rows() as $r ) {
			$work[] = array( 'image_broken', $r[0], $r[1], $r[3], 'HTTP ' . $r[4] . ' · ' . $r[2], wp_make_link_relative( (string) get_permalink( $r[0] ) ) );
		}
	}
	// News and guide pictures with no rights record.
	if ( function_exists( 'daw_lic_census' ) ) {
		foreach ( array_slice( (array) ( daw_lic_census()['rows'] ?? array() ), 0, 20 ) as $r ) {
			$note   = (string) get_post_meta( (int) $r['att'], '_daw_cm_note', true );
			$work[] = array( 'licence_' . $r['status'], (int) $r['post'], get_the_title( (int) $r['post'] ), (string) $r['type'], 'görsel #' . (int) $r['att'] . ( '' !== $note ? ' · ' . $note : '' ), wp_make_link_relative( (string) get_permalink( (int) $r['post'] ) ) );
		}
	}
	$d['work'] = $work;

	$lic           = (array) ( $snap['images'] ?? array() );
	$d['pictures'] = array(
		'state'  => $bad > 0 || (int) ( $lic['unregistered'] ?? 0 ) > 0 ? 'warn' : 'ok',
		'parts_n' => count( $parts ),
		'remote' => count( (array) ( $img['remote']['rows'] ?? array() ) ),
		'local'  => count( (array) ( $img['local']['rows'] ?? array() ) ),
		'bad'    => $bad,
		'parts'  => $parts,
		'lic_ok' => (int) ( $lic['ok'] ?? 0 ),
		'unreg'  => (int) ( $lic['unregistered'] ?? 0 ),
		'inc'    => (int) ( $lic['incomplete'] ?? 0 ),
	);

	$fr           = (array) ( $snap['freshness'] ?? array() );
	$bl           = (array) ( $snap['backlog'] ?? array() );
	$fresh_n      = (int) ( $fr['status'] ?? 0 ) + (int) ( $fr['watch'] ?? 0 ) + (int) ( $fr['trailer'] ?? 0 );
	$d['fresh']   = array(
		// The daily run settles airing status by itself from the aired dates
		// and works through the link checks fifty a day: a queue, not a fault.
		'state'   => 'ok',
		'status'  => (int) ( $fr['status'] ?? 0 ),
		'watch'   => (int) ( $fr['watch'] ?? 0 ),
		'trailer' => (int) ( $fr['trailer'] ?? 0 ),
		'news'    => count( (array) ( $snap['news_recheck'] ?? array() ) ),
		'people'  => (int) ( $bl['people_total'] ?? 0 ),
		'works'   => count( (array) ( $bl['works'] ?? array() ) ),
	);

	/* --- background jobs --- */
	$jobs = array(
		'daw_gsc_sync'      => array( 'Search Console verisi', 'daily' ),
		'daw_gsc_inspect'   => array( 'Google dizin kontrolü', 'hourly' ),
		'daw_nf_autofix'    => array( '404 otomatik düzeltme', 'hourly' ),
		'daw_lk_tick'       => array( 'Bağlantı taraması', 'hourly' ),
		'daw_psi_daily'     => array( 'Mobil hız ölçümü', 'daily' ),
		'daw_speed_daily'   => array( 'Sunucu hız ölçümü', 'daily' ),
		'daw_snapshot_cron' => array( 'Arşiv özeti', 'twicedaily' ),
		'daw_fresh_daily'   => array( 'Güncellik taraması', 'daily' ),
		'daw_wp_cron'       => array( 'Nerede izlenir (JustWatch)', 'hourly' ),
		'daw_news_sitemap_refresh' => array( 'Haber site haritası', 'twicedaily' ),
	);
	$rows  = array();
	$late  = 0;
	$miss  = 0;
	foreach ( $jobs as $hook => $j ) {
		$next = (int) wp_next_scheduled( $hook );
		$st   = 'ok';
		if ( ! $next ) {
			$st = 'warn';
			$miss++;
		} elseif ( $next < time() - 2 * HOUR_IN_SECONDS ) {
			$st = 'warn';
			$late++;
		}
		$rows[ $hook ] = array( 'label' => $j[0], 'next' => $next, 'state' => $st );
	}
	$d['jobs'] = array(
		'state'  => ( $late + $miss ) > 2 ? 'bad' : ( ( $late + $miss ) > 0 ? 'warn' : 'ok' ),
		'rows'   => $rows,
		'late'   => $late,
		'miss'   => $miss,
		'wpcron' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
	);

	/* --- server and WordPress health (the daily speed run keeps it) --- */
	$sp     = (array) get_option( defined( 'DAW_SPEED_LAST' ) ? DAW_SPEED_LAST : 'daw_speed_last', array() );
	$health = array( 'good' => 0, 'recommended' => 0, 'critical' => 0 );
	$hbad   = array();
	foreach ( (array) ( $sp['health'] ?? array() ) as $h ) {
		$k = (string) ( $h['status'] ?? '' );
		if ( isset( $health[ $k ] ) ) {
			$health[ $k ]++;
		}
		if ( 'good' !== $k && '' !== (string) ( $h['label'] ?? '' ) ) {
			$hbad[] = array( $k, wp_strip_all_tags( (string) $h['label'] ) );
		}
	}
	$ttfb = array();
	foreach ( (array) ( $sp['templates'] ?? array() ) as $t ) {
		if ( isset( $t['warm_ms'] ) && is_numeric( $t['warm_ms'] ) ) {
			$ttfb[] = (float) $t['warm_ms'];
		}
	}
	sort( $ttfb );
	// What Google measured for the first byte is the number that matters; the
	// site's own loopback timing (through the host's proxy) is the fallback.
	$psi_ttfb = array();
	foreach ( (array) ( $psi ?? array() ) as $prow ) {
		if ( isset( $prow['metrics']['server-response-time'] ) ) {
			$psi_ttfb[] = (int) $prow['metrics']['server-response-time'];
		}
	}
	sort( $psi_ttfb );
	$issues = array_values(
		array_filter(
			$hbad,
			static function ( $x ) {
				// Measured from a background run these two are false alarms.
				return ! preg_match( '/scheduled event|REST API|Unable to determine the status of the current PHP version/i', $x[1] );
			}
		)
	);
	$health['recommended'] = max( 0, $health['recommended'] - ( count( $hbad ) - count( $issues ) ) );
	$hbad                  = $issues;
	$d['server'] = array(
		'state'    => $health['critical'] > 0 ? 'bad' : ( $health['recommended'] > 2 ? 'warn' : 'ok' ),
		'health'   => $health,
		'issues'   => array_slice( $hbad, 0, 4 ),
		'ttfb'     => $psi_ttfb ? (int) $psi_ttfb[ (int) floor( count( $psi_ttfb ) / 2 ) ] : ( $ttfb ? (int) round( $ttfb[ (int) floor( count( $ttfb ) / 2 ) ] ) : 0 ),
		'ttfb_src' => $psi_ttfb ? 'Google' : 'iç ölçüm',
		'php'      => PHP_VERSION,
		'wp'       => get_bloginfo( 'version' ),
		'version'  => defined( 'DAW_VERSION' ) ? DAW_VERSION : '',
		'at'       => (int) ( $sp['when'] ?? 0 ),
		'cache'    => defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\Core' ),
		'objcache' => wp_using_ext_object_cache(),
	);

	/* --- APIs and connections --- */
	$tmdb = get_option( 'daw_tmdb_usage', array() );
	$tmdb = is_array( $tmdb ) ? $tmdb : array();
	$tkey = function_exists( 'daw_tmdb_key' ) ? '' !== (string) daw_tmdb_key() : false;
	$terr = ! empty( $tmdb['last_err'] ) && ( empty( $tmdb['last_ok'] ) || $tmdb['last_err'] > $tmdb['last_ok'] );
	$d['api'] = array(
		'state'      => ! $tkey || $terr ? 'warn' : 'ok',
		'tmdb_key'   => $tkey,
		'tmdb_err'   => $terr,
		'tmdb_code'  => (int) ( $tmdb['last_err_code'] ?? 0 ),
		'tmdb_today' => ( $tmdb['day'] ?? '' ) === gmdate( 'Y-m-d' ) ? (int) ( $tmdb['today']['api'] ?? 0 ) : 0,
		'tmdb_month' => ( $tmdb['month'] ?? '' ) === gmdate( 'Y-m' ) ? (int) ( $tmdb['month_n']['api'] ?? 0 ) : 0,
		'gsc'        => $gsc['connected'],
		'psi_key'    => defined( 'DAW_SPEED_KEY_PSI' ) && '' !== trim( (string) get_option( DAW_SPEED_KEY_PSI, '' ) ),
		'indexnow'   => function_exists( 'daw_inx_key' ) && '' !== (string) daw_inx_key(),
	);

	/* --- ads and privacy --- */
	$ads = function_exists( 'daw_ads_live' ) && daw_ads_live();
	$d['ads'] = array(
		'state' => $ads ? 'ok' : 'info',
		'live'  => $ads,
		'units' => function_exists( 'daw_ad_units' ) ? count( array_filter( (array) daw_ad_units() ) ) : 0,
		'ga'    => function_exists( 'daw_ga_gate_enabled' ) && daw_ga_gate_enabled(),
		'cmp'   => $ads,
	);

	/* --- AI crawlers --- */
	if ( function_exists( 'daw_aib_data' ) ) {
		$d['ai'] = daw_aib_data();
	}
	if ( function_exists( 'daw_sec_data' ) ) {
		$d['sec'] = daw_sec_data();
	}
	if ( function_exists( 'daw_rda_labels' ) ) {
		$rda       = get_option( 'daw_rda', array() );
		$d['rda']  = array(
			'at'    => (int) ( $rda['at'] ?? 0 ),
			'total' => (int) ( $rda['total'] ?? 0 ),
			'fixed' => (int) ( $rda['fixed'] ?? 0 ),
			'count' => (array) ( $rda['count'] ?? array() ),
		);
	}
	if ( function_exists( 'daw_ick_rows' ) ) {
		$ick      = get_option( 'daw_imgchk', array() );
		$d['ick'] = array(
			'at'       => (int) ( $ick['at'] ?? 0 ),
			'pos'      => (int) ( $ick['pos'] ?? 0 ),
			'total'    => (int) ( $ick['total'] ?? 0 ),
			'lap_done' => (int) ( $ick['lap_done'] ?? 0 ),
			'bad'      => count( daw_ick_rows() ),
		);
	}

	/* --- repairs, Search Console sitemap, late events, updates --- */
	$log  = (array) get_option( 'daw_fix_log', array() );
	$week = 0;
	foreach ( $log as $row ) {
		if ( (int) ( $row['at'] ?? 0 ) > time() - WEEK_IN_SECONDS ) {
			$week++;
		}
	}
	$d['fix'] = array(
		'state' => function_exists( 'daw_fix_on' ) && daw_fix_on() ? 'ok' : 'warn',
		'on'    => function_exists( 'daw_fix_on' ) && daw_fix_on(),
		'week'  => $week,
		'last'  => array_reverse( array_slice( $log, -8 ) ),
		'run'   => (int) get_option( 'daw_kc_last_run', 0 ),
	);

	$sm             = (array) get_option( 'daw_gsc_sm_status', array() );
	$d['gsc']['sm'] = $sm;

	$late = function_exists( 'daw_fix_late_events' ) ? daw_fix_late_events() : array();
	$d['jobs']['late_hooks'] = array_slice( $late, 0, 6, true );
	if ( count( $late ) > 3 && 'ok' === $d['jobs']['state'] ) {
		$d['jobs']['state'] = 'warn';
	}

	$core   = get_site_transient( 'update_core' );
	$plug   = get_site_transient( 'update_plugins' );
	$themes = function_exists( 'wp_get_themes' ) ? wp_get_themes() : array();
	$active = array( get_stylesheet(), get_template() );
	// WordPress wants one default theme kept as a fallback (Site Health asks
	// for it, and the owner installed Twenty Twenty-Five for that). Telling
	// him to delete it brought the health warning back, so the newest default
	// theme is never called unused.
	$fallback = defined( 'WP_DEFAULT_THEME' ) ? WP_DEFAULT_THEME : 'twentytwentyfive';
	$defaults = array_filter( array_keys( $themes ), static fn( $s ) => (bool) preg_match( '/^twenty[a-z-]+$/', (string) $s ) );
	rsort( $defaults );
	$keep     = isset( $themes[ $fallback ] ) ? $fallback : ( $defaults ? $defaults[0] : '' );
	$idle   = array();
	foreach ( $themes as $slug => $th ) {
		if ( ! in_array( $slug, $active, true ) && $slug !== $keep ) {
			$idle[] = $th->get( 'Name' );
		}
	}
	$d['updates'] = array(
		'core'    => ( is_object( $core ) && ! empty( $core->updates[0]->response ) && 'upgrade' === $core->updates[0]->response ) ? (string) $core->updates[0]->current : '',
		'plugins' => is_object( $plug ) && ! empty( $plug->response ) ? count( (array) $plug->response ) : 0,
		'themes'  => $idle,
	);

	return $d;
}

/** What Google's coverage words mean, in plain Turkish. */
function daw_kc_coverage_tr( $c ) {
	$map = array(
		'Crawled - currently not indexed'    => 'Google taradı, henüz dizine almadı',
		'Discovered - currently not indexed' => 'Google adresi biliyor, henüz taramadı',
		'URL is unknown to Google'           => 'Google bu adresi henüz hiç görmedi',
		'Submitted and indexed'              => 'Dizinde',
		'Indexed, not submitted in sitemap'  => 'Dizinde (site haritası dışından)',
		'Page with redirect'                 => 'Yönlendirilen sayfa',
		"Excluded by 'noindex' tag"          => 'noindex ile dışarıda (bilerek)',
		'Not found (404)'                    => 'Sayfa bulunamadı (404)',
		'Soft 404'                           => 'Boş sayfa gibi görünüyor (soft 404)',
		'Blocked by robots.txt'              => 'robots.txt engelliyor',
		'Server error (5xx)'                 => 'Sunucu hatası (5xx)',
		'Duplicate without user-selected canonical'          => 'Kopya sayfa, canonical yok',
		'Duplicate, Google chose different canonical than user' => 'Google başka canonical seçti',
		'Alternate page with proper canonical tag'           => 'Doğru canonical\'lı alternatif sayfa',
	);
	return $map[ $c ] ?? ( '' !== (string) $c ? (string) $c : 'Nedeni yazılmamış' );
}

/**
 * Only what needs a person, most urgent first. Each item:
 * [ severity (bad|warn|info), text, button label, url ].
 */
function daw_kc_todo( array $d ) {
	$t = array();

	if ( ! empty( $d['rda']['count'] ) && function_exists( 'daw_rda_labels' ) ) {
		$parts = array();
		$worst = 'info';
		foreach ( daw_rda_labels() as $k => $l ) {
			if ( ! empty( $d['rda']['count'][ $k ] ) && 'info' !== $l[0] ) {
				$parts[] = $d['rda']['count'][ $k ] . ' ' . $l[1];
				$worst   = 'bad' === $l[0] ? 'bad' : ( 'bad' === $worst ? 'bad' : 'warn' );
			}
		}
		if ( $parts ) {
			$t[] = array( $worst, sprintf( '%d yönlendirmeden sorunlu olanlar: %s.', $d['rda']['total'], implode( ', ', $parts ) ), 'Yönlendirmeler', daw_kc_seo_url( '404' ) );
		}
	}
	if ( ! empty( $d['ick']['bad'] ) ) {
		$t[] = array( 'bad', sprintf( '%d sayfada açılmayan (kırık) görsel var.', $d['ick']['bad'] ), 'Görseller', daw_menu_url( 'daw-images' ) );
	}
	if ( ! empty( $d['sec']['bad'] ) ) {
		$fails = array();
		foreach ( $d['sec']['rows'] as $r ) {
			if ( 'bad' === $r[1] ) {
				$fails[] = $r[0];
			}
		}
		$t[] = array( 'bad', sprintf( 'Güvenlik kontrolünde %d sorun: %s.', count( $fails ), implode( '; ', $fails ) ), 'Güvenlik', admin_url( 'admin.php?page=' . DAW_MENU ) . '#kc-sec' );
	}

	if ( ! $d['seo']['live'] ) {
		$t[] = array( 'bad', 'SEO motoru çalışmıyor: başlıklar, açıklamalar ve site haritası basılmıyor.', 'SEO durumu', daw_kc_seo_url( 'durum' ) );
	}
	if ( ! $d['gsc']['connected'] ) {
		$t[] = array( 'bad', 'Search Console bağlı değil: Google verisi gelmiyor.', 'Bağla', daw_kc_seo_url( 'gsc' ) );
	} elseif ( '' !== (string) ( $d['gsc']['error'] ?? '' ) ) {
		$t[] = array( 'bad', 'Search Console hata veriyor: ' . $d['gsc']['error'], 'Ayrıntı', daw_kc_seo_url( 'gsc' ) );
	}
	if ( '' !== (string) ( $d['gsc']['note'] ?? '' ) ) {
		$t[] = array( 'warn', 'Search Console: ' . $d['gsc']['note'], 'Ayrıntı', daw_kc_seo_url( 'gsc' ) );
	}
	$ga = get_option( 'daw_ga4_report' );
	if ( is_array( $ga ) && '' !== (string) ( $ga['error'] ?? '' ) ) {
		$t[] = array( 'warn', 'Analytics okunamıyor: ' . $ga['error'], 'Analytics', 'https://analytics.google.com/' );
	}
	if ( $d['links']['broken'] > 0 ) {
		$t[] = array( 'bad', sprintf( 'Yazıların içinde %s kırık bağlantı var.', daw_kc_n( $d['links']['broken'] ) ), 'Düzelt', daw_kc_seo_url( 'baglanti' ) );
	}
	if ( $d['server']['health']['critical'] > 0 ) {
		$t[] = array( 'bad', sprintf( 'WordPress sağlık kontrolünde %s kritik madde var.', daw_kc_n( $d['server']['health']['critical'] ) ), 'Site sağlığı', admin_url( 'site-health.php' ) );
	}
	foreach ( $d['speed']['pages'] as $p ) {
		if ( $p['score'] < 90 ) {
			$t[] = array( $p['score'] < 50 ? 'bad' : 'warn', sprintf( '%s mobil hız skoru %d (hedef 90+).', $p['label'], $p['score'] ), 'Hız raporu', daw_menu_url( 'daw-speed-audit' ) );
		}
		if ( null !== $p['d'] && $p['d'] < 90 ) {
			$t[] = array( $p['d'] < 50 ? 'bad' : 'warn', sprintf( '%s masaüstü hız skoru %d (hedef 90+).', $p['label'], $p['d'] ), 'Hız raporu', daw_menu_url( 'daw-speed-audit' ) );
		}
	}
	if ( $d['index']['not'] > 0 ) {
		$t[] = array( 'warn', sprintf( 'Kontrol edilen %s sayfanın %s tanesi Google dizininde değil.', daw_kc_n( $d['index']['inspected'] ), daw_kc_n( $d['index']['not'] ) ), 'Nedenlerine bak', daw_kc_seo_url( 'dizin' ) );
	}
	if ( $d['seo']['open404'] > 0 ) {
		$t[] = array( 'warn', sprintf( '%s adres 404 veriyor ve henüz yönlendirilmedi%s.', daw_kc_n( $d['seo']['open404'] ), $d['seo']['auto404'] ? ' (uygun hedefi olanlar saatte bir kendiliğinden yönlenir)' : '' ), '404 kaydı', daw_kc_seo_url( '404' ) );
	}
	if ( $d['links']['redirected'] > 0 ) {
		$t[] = array( 'warn', sprintf( '%s iç bağlantı yönlendirmeden geçiyor; doğrudan hedefe bağlanmalı.', daw_kc_n( $d['links']['redirected'] ) ), 'Bağlantılar', daw_kc_seo_url( 'baglanti' ) );
	}
	if ( $d['jobs']['late'] + $d['jobs']['miss'] > 0 ) {
		$t[] = array( 'warn', sprintf( '%s otomatik iş gecikmiş ya da planlı değil.', daw_kc_n( $d['jobs']['late'] + $d['jobs']['miss'] ) ), 'Otomatik işler', '#kc-jobs' );
	}
	if ( $d['api']['tmdb_err'] ) {
		$t[] = array( 'warn', sprintf( 'TMDB API son istekte hata verdi (kod %d).', $d['api']['tmdb_code'] ), 'Bağlantı ayarları', daw_menu_url( 'daw-images' ) . '#daw-conn' );
	}
	if ( $d['pictures']['bad'] > 0 ) {
		$t[] = array( 'warn', sprintf( '%s kaydın görseli elle bakılmalı (TMDB numarası yok, yanlış ya da yedek alınamadı).', daw_kc_n( $d['pictures']['bad'] ) ), 'Görseller', daw_menu_url( 'daw-images' ) );
	}
	if ( $d['pictures']['unreg'] > 0 ) {
		$t[] = array( 'warn', sprintf( '%s haber/rehber görselinin lisans kaydı yok.', daw_kc_n( $d['pictures']['unreg'] ) ), 'Lisanslar', daw_menu_url( 'daw-image-rights' ) );
	}
	$re = get_option( 'daw_recheck_due' );
	if ( is_array( $re ) && (int) ( $re['n'] ?? 0 ) > 0 ) {
		$first = (int) ( $re['top'][0] ?? 0 );
		$t[]   = array( 'info', sprintf( '%s kayıtta değişebilen bir bilginin (ilişki, ödül, yayın durumu, izleme…) 60 günlük kontrol zamanı geldi.', daw_kc_n( $re['n'] ) ), 'İlk kayda bak', admin_url( 'admin.php?page=daw-denetim' . ( $first ? '&record=' . $first . '#daw-ap-volatile' : '' ) ) );
	}
	if ( $d['fresh']['news'] > 0 ) {
		$t[] = array( 'info', sprintf( '%s haber kontrol bekliyor (canlı haber ya da tarihi geçmiş bilgi).', daw_kc_n( $d['fresh']['news'] ) ), 'Listeyi aç', admin_url( 'admin.php?page=daw#kc-news-stale' ) );
	}
	if ( $d['fresh']['people'] > 0 ) {
	if ( ! empty( $d['opps']['n'] ) ) {
		$t[] = array( 'info', sprintf( '%s arama fırsatı: Google sayfalarımızı bu aramalarda gösteriyor, kalıp listesinde yoklar%s.', daw_kc_n( $d['opps']['n'] ), $d['opps']['new'] ? sprintf( ' (%s yeni)', daw_kc_n( $d['opps']['new'] ) ) : '' ), 'Fırsatlar', admin_url( 'admin.php?page=daw-firsat' ) );
	}
		$t[] = array( 'info', sprintf( 'Sitede kaydı olmayan %s kişi var (oyuncu ve ekip).', daw_kc_n( $d['fresh']['people'] ) ), 'Eksikler', daw_menu_url( 'daw-backlog' ) );
	}
	if ( $d['server']['health']['recommended'] > 0 ) {
		// Each recommendation said as the thing to do, so the line is the task.
		$how = array();
		foreach ( (array) ( $d['server']['issues'] ?? array() ) as $is ) {
			$l = (string) ( $is[1] ?? '' );
			if ( preg_match( '/^WordPress version/i', $l ) ) {
				$how[] = 'WordPress\'i güncelle (Pano › Güncellemeler)';
			} elseif ( preg_match( '/default theme/i', $l ) ) {
				$how[] = 'yedek olarak Twenty Twenty-Five temasını yükle, etkinleştirme (Görünüm › Temalar › Yeni ekle)';
			} elseif ( preg_match( '/Opcode cache/i', $l ) ) {
				$how[] = 'OPcache\'i aç (hPanel › Gelişmiş › PHP Yapılandırması › PHP uzantıları › opcache)';
			} elseif ( '' !== $l ) {
				$how[] = $l;
			}
		}
		$t[] = array( 'info', sprintf( 'WordPress sağlık önerisi (%s): %s.', daw_kc_n( $d['server']['health']['recommended'] ), $how ? implode( '; ', $how ) : 'ayrıntı Site sağlığı ekranında' ), 'Site sağlığı', admin_url( 'site-health.php' ) );
	}

	$sm = (array) ( $d['gsc']['sm'] ?? array() );
	if ( isset( $sm['submit_ok'] ) && ! $sm['submit_ok'] && '' !== (string) ( $sm['submit_msg'] ?? '' ) ) {
		$t[] = array( 'warn', (string) $sm['submit_msg'] . ' Yetkiyi verdiysen sistem bir saat içinde kendiliğinden yeniden dener; "Tüm kontrolleri şimdi çalıştır" hemen dener.', 'Search Console', 'https://search.google.com/search-console/users' );
	}
	if ( ! empty( $sm['mine'] ) && ( (int) $sm['mine']['errors'] > 0 ) ) {
		$t[] = array( 'bad', sprintf( 'Google site haritasında %d hata bildiriyor.', (int) $sm['mine']['errors'] ), 'Google dizini', daw_kc_seo_url( 'dizin' ) );
	}
	if ( '' !== $d['updates']['core'] ) {
		$t[] = array( 'warn', sprintf( 'WordPress %s güncellemesi hazır.', $d['updates']['core'] ), 'Güncelle', admin_url( 'update-core.php' ) );
	}
	if ( $d['updates']['plugins'] > 0 ) {
		$t[] = array( 'warn', sprintf( '%d eklentinin güncellemesi var.', $d['updates']['plugins'] ), 'Eklentiler', admin_url( 'plugins.php?plugin_status=upgrade' ) );
	}
	if ( $d['updates']['themes'] ) {
		$t[] = array( 'info', sprintf( 'Kullanılmayan tema silinebilir: %s.', implode( ', ', $d['updates']['themes'] ) ), 'Temalar', admin_url( 'themes.php' ) );
	}
	if ( function_exists( 'daw_idx_doc' ) ) {
		$ic = (array) ( daw_idx_doc()['count'] ?? array() );
		if ( (int) ( $ic['tech'] ?? 0 ) + (int) ( $ic['canonical'] ?? 0 ) > 0 ) {
			$t[] = array( 'bad', sprintf( 'Google %d sayfayı teknik engel ya da farklı kanonik yüzünden dizine almıyor.', (int) ( $ic['tech'] ?? 0 ) + (int) ( $ic['canonical'] ?? 0 ) ), 'Dizin doktoru', '#kc-idx' );
		}
	}
	if ( function_exists( 'daw_ae_imp_total' ) ) {
		$it = daw_ae_imp_total();
		if ( $it['fields'] ) {
			$t[] = array( 'info', sprintf( 'Mantıken uygulanmaz diye kapatılan alan: %s (%s kişide). Kadında askerlik, grupsuzda grup pozisyonu, şarkıcı olmayanda Spotify/Apple Music, yaşayanda ölüm tarihi. Kaynak aranmadı; her kaydın tepesinde listeli.', number_format_i18n( $it['fields'] ), number_format_i18n( $it['records'] ) ), 'Denetim', daw_menu_url( 'daw-denetim' ) );
		}
	}
	if ( function_exists( 'daw_covp_summary' ) ) {
		$cv = daw_covp_summary();
		if ( (int) ( $cv['unlinked'] ?? 0 ) > 0 ) {
			$t[] = array( 'warn', sprintf( '%d kadro bağlantısı eksik: kişi de yapım da sitede var, birbirine bağlı değil (TMDB karşılaştırması). Kapsam kartındaki "Eksik kadroyu bağla" düğmesi 50\'şerli bağlar.', (int) $cv['unlinked'] ), 'Bağla', '#kc-cov' );
		}
	}
	if ( function_exists( 'daw_thin_count' ) ) {
		$held = daw_thin_count();
		if ( $held > 0 ) {
			$t[] = array( 'info', sprintf( '%s kayıt kapıda (noindex): künyesi ya da metni eşiğin altında, Google\'a henüz sunulmuyor. Eşiği geçen kendiliğinden açılır.', number_format_i18n( $held ) ), 'Liste', daw_menu_url( 'daw-kapi' ) );
		}
	}
	if ( ! $d['fix']['on'] ) {
		$t[] = array( 'warn', 'Otomatik onarım kapalı: bağlantı ve site haritası hataları kendiliğinden düzelmiyor.', 'Aç', '#kc-fix' );
	}

	$order = array( 'bad' => 0, 'warn' => 1, 'info' => 2 );
	usort(
		$t,
		static function ( $a, $b ) use ( $order ) {
			return $order[ $a[0] ] <=> $order[ $b[0] ];
		}
	);

	return $t;
}

/** One number for the whole site: each area weighs by how much it matters. */
function daw_kc_score( array $d ) {
	$weights = array( 'speed' => 3, 'gsc' => 2, 'index' => 2, 'seo' => 3, 'links' => 2, 'archive' => 1, 'pictures' => 1, 'fresh' => 1, 'jobs' => 2, 'server' => 2, 'api' => 1, 'indexnow' => 1, 'fix' => 1 );
	$value   = array( 'ok' => 100, 'warn' => 65, 'bad' => 20 );
	$sum     = 0;
	$weight  = 0;
	foreach ( $weights as $k => $w ) {
		$st = $d[ $k ]['state'] ?? 'info';
		if ( isset( $value[ $st ] ) ) {
			$sum    += $value[ $st ] * $w;
			$weight += $w;
		}
	}
	return $weight ? (int) round( $sum / $weight ) : 0;
}

/* ================================================================= render == */

function daw_kc_state_word( $st ) {
	$w = array( 'ok' => 'Yolunda', 'warn' => 'Bakılmalı', 'bad' => 'Sorun var', 'info' => 'Bilgi' );
	return $w[ $st ] ?? '';
}

/** A card's head: icon, title, state pill. */
function daw_kc_card_open( $id, $icon, $title, $st, $wide = false ) {
	printf(
		'<section class="kc-card%5$s" id="kc-%1$s"><header class="kc-card-h"><span class="kc-ico" aria-hidden="true">%2$s</span><h2>%3$s</h2><span class="kc-pill is-%4$s">%6$s</span></header>',
		esc_attr( $id ),
		$icon, // phpcs:ignore WordPress.Security.EscapeOutput -- static glyph.
		esc_html( $title ),
		esc_attr( $st ),
		$wide ? ' is-wide' : '',
		esc_html( daw_kc_state_word( $st ) )
	);
}

function daw_kc_card_close( array $links = array() ) {
	if ( $links ) {
		echo '<footer class="kc-card-f">';
		foreach ( $links as $label => $url ) {
			printf( '<a href="%s">%s →</a>', esc_url( $url ), esc_html( $label ) );
		}
		echo '</footer>';
	}
	echo '</section>';
}

/** A labelled figure. */
function daw_kc_stat( $value, $label, $extra = '' ) {
	printf( '<div class="kc-stat"><b>%s</b><span>%s</span>%s</div>', esc_html( $value ), esc_html( $label ), $extra ); // phpcs:ignore WordPress.Security.EscapeOutput -- $extra is built here.
}

/** A row of a check list. */
function daw_kc_check( $ok, $text, $state = null ) {
	$st = null !== $state ? $state : ( $ok ? 'ok' : 'bad' );
	$ic = array( 'ok' => '✓', 'warn' => '!', 'bad' => '✕', 'info' => 'i' );
	printf( '<li class="is-%s"><i>%s</i>%s</li>', esc_attr( $st ), esc_html( $ic[ $st ] ?? '·' ), esc_html( $text ) );
}

/** The same picture, kept so it can be read from the chat (Royal MCP). */
function daw_kc_store_report( $d = null, $todo = null, $score = null ) {
	if ( null === $d ) {
		$d     = daw_kc_data();
		$todo  = daw_kc_todo( $d );
		$score = daw_kc_score( $d );
		set_transient( 'daw_kc_d_' . ( defined( 'DAW_VERSION' ) ? DAW_VERSION : '0' ), $d, 5 * MINUTE_IN_SECONDS );
	}
	$keep = $d;
	unset( $keep['gsc']['spark_c'], $keep['gsc']['spark_i'] );
	update_option( 'daw_kc_report', array( 'at' => time(), 'score' => $score, 'todo' => $todo, 'areas' => $keep ), false );
	daw_kc_track( $d, $score );
}

/** Area names for the change log, in the words the cards use. */
function daw_kc_area_names() {
	return array(
		'speed'    => 'Hız',
		'gsc'      => 'Google arama trafiği',
		'index'    => 'Google dizini',
		'seo'      => 'SEO motoru ve 404',
		'links'    => 'İç bağlantılar',
		'archive'  => 'İçerik arşivi',
		'pictures' => 'Görseller',
		'fresh'    => 'Güncellik',
		'jobs'     => 'Otomatik işler',
		'server'   => 'Sunucu',
		'api'      => 'API bağlantıları',
		'indexnow' => 'IndexNow',
		'fix'      => 'Otomatik onarım',
		'sec'      => 'Güvenlik',
		'ads'      => 'Reklam',
	);
}

/**
 * WHAT DROPPED, AND WHEN
 * ----------------------
 * The owner saw the score fall and cards turn yellow without knowing what
 * had changed or when (7 Oct 2026). Every stored report now compares each
 * area's state with the one before and keeps the turns: the last 60 changes
 * (daw_kc_changes) and an hourly score line for three days (daw_kc_hist).
 * The card "Son değişiklikler" and /daw/v1/kc-brief read these.
 */
function daw_kc_track( array $d, $score ) {
	$now    = time();
	$names  = daw_kc_area_names();
	$states = array();
	foreach ( array_keys( $names ) as $k ) {
		if ( isset( $d[ $k ]['state'] ) ) {
			$states[ $k ] = (string) $d[ $k ]['state'];
		}
	}
	$hist = get_option( 'daw_kc_hist', array() );
	$hist = is_array( $hist ) ? $hist : array();
	$last = $hist ? end( $hist ) : null;

	if ( is_array( $last ) ) {
		$was     = (array) ( $last['s'] ?? array() );
		$changes = get_option( 'daw_kc_changes', array() );
		$changes = is_array( $changes ) ? $changes : array();
		$added   = false;
		foreach ( $states as $k => $st ) {
			if ( isset( $was[ $k ] ) && $was[ $k ] !== $st ) {
				$changes[] = array( 'at' => $now, 'area' => $k, 'from' => $was[ $k ], 'to' => $st, 'why' => daw_kc_area_why( $k, $d ) );
				$added     = true;
			}
		}
		if ( $added ) {
			update_option( 'daw_kc_changes', array_slice( $changes, -60 ), false );
		}
	}

	$row = array( 'at' => $now, 'score' => (int) $score, 's' => $states );
	if ( is_array( $last ) && $now - (int) ( $last['at'] ?? 0 ) < 50 * MINUTE_IN_SECONDS ) {
		$hist[ count( $hist ) - 1 ] = $row; // one row per hour, the latest wins
	} else {
		$hist[] = $row;
	}
	update_option( 'daw_kc_hist', array_slice( $hist, -72 ), false );
}

/** One line on what an area holds right now, for the change log. */
function daw_kc_area_why( $k, array $d ) {
	switch ( $k ) {
		case 'seo':
			return sprintf( '%d açık 404', (int) ( $d['seo']['open404'] ?? 0 ) ) . ( empty( $d['seo']['live'] ) ? ', SEO motoru kapalı' : '' );
		case 'links':
			return sprintf( '%d kırık, %d yönlendirmeli bağlantı', (int) ( $d['links']['broken'] ?? 0 ), (int) ( $d['links']['redirected'] ?? 0 ) );
		case 'speed':
			$min = array();
			foreach ( (array) ( $d['speed']['pages'] ?? array() ) as $pg ) {
				$min[ $pg['label'] ] = min( (int) $pg['score'], is_int( $pg['d'] ?? null ) ? (int) $pg['d'] : 100 );
			}
			asort( $min );
			return $min ? sprintf( 'en düşük: %s %d', (string) key( $min ), (int) reset( $min ) ) : '';
		case 'index':
			return sprintf( '%d / %d sayfa dizinde', (int) ( $d['index']['indexed'] ?? 0 ), (int) ( $d['index']['inspected'] ?? 0 ) );
		case 'jobs':
			return sprintf( '%d geciken, %d kayıp iş', (int) ( $d['jobs']['late'] ?? 0 ), (int) ( $d['jobs']['miss'] ?? 0 ) );
		case 'gsc':
			return (string) ( $d['gsc']['error'] ?? '' );
		case 'api':
			return ! empty( $d['api']['tmdb_err'] ) ? 'TMDB hata kodu ' . (int) ( $d['api']['tmdb_code'] ?? 0 ) : '';
		case 'pictures':
			return sprintf( '%d sorunlu görsel', (int) ( $d['pictures']['bad'] ?? 0 ) );
		case 'server':
			return sprintf( '%d kritik, %d öneri', (int) ( $d['server']['health']['critical'] ?? 0 ), (int) ( $d['server']['health']['recommended'] ?? 0 ) );
		case 'sec':
			return sprintf( '%d sorun', (int) ( $d['sec']['bad'] ?? 0 ) );
	}
	return '';
}

/**
 * The control centre in under two kilobytes, for the scheduled check from
 * the chat (GET /daw/v1/kc-brief): what is not fine, what changed, and the
 * open 404s with the site's own guess. Nothing else.
 */
function daw_kc_brief() {
	$r     = get_option( 'daw_kc_report', array() );
	$r     = is_array( $r ) ? $r : array();
	$areas = (array) ( $r['areas'] ?? array() );
	$names = daw_kc_area_names();
	$not   = array();
	foreach ( $names as $k => $label ) {
		$st = (string) ( $areas[ $k ]['state'] ?? '' );
		if ( in_array( $st, array( 'warn', 'bad' ), true ) ) {
			$not[ $k ] = array( $st, daw_kc_area_why( $k, $areas ) );
		}
	}
	$todo = array();
	foreach ( (array) ( $r['todo'] ?? array() ) as $t ) {
		if ( 'info' !== ( $t[0] ?? '' ) ) {
			$todo[] = array( $t[0], $t[1] );
		}
	}
	$w404 = array();
	$late = array();
	$img  = 0;
	foreach ( (array) ( $areas['work'] ?? array() ) as $w ) {
		if ( 'open_404' === $w[0] && count( $w404 ) < 10 ) {
			$w404[] = array( 'path' => $w[2], 'ref' => $w[3], 'hits' => $w[4], 'guess' => $w[5] );
		} elseif ( 'image_broken' === $w[0] ) {
			$img++;
		}
	}
	foreach ( (array) ( $areas['jobs']['late_hooks'] ?? array() ) as $h => $v ) {
		$late[] = (string) $h;
	}
	$hist = (array) get_option( 'daw_kc_hist', array() );
	$prev = count( $hist ) > 1 ? $hist[ count( $hist ) - 2 ] : null;
	$ch   = array();
	foreach ( (array) get_option( 'daw_kc_changes', array() ) as $c ) {
		if ( (int) $c['at'] > time() - DAY_IN_SECONDS ) {
			$ch[] = array( gmdate( 'm-d H:i', (int) $c['at'] ), $c['area'], $c['from'] . '>' . $c['to'], $c['why'] );
		}
	}
	return array(
		'at'         => (int) ( $r['at'] ?? 0 ),
		'age_min'    => $r ? (int) round( ( time() - (int) $r['at'] ) / 60 ) : null,
		'version'    => defined( 'DAW_VERSION' ) ? DAW_VERSION : '',
		'score'      => (int) ( $r['score'] ?? 0 ),
		'prev_score' => is_array( $prev ) ? (int) $prev['score'] : null,
		'not_ok'     => $not,
		'todo'       => $todo,
		'open_404'   => $w404,
		'img_broken' => $img,
		'late_jobs'  => $late,
		'changes_24h' => array_slice( $ch, -15 ),
		'idx'         => function_exists( 'daw_idx_brief' ) ? daw_idx_brief() : array(),
		'cov'         => function_exists( 'daw_covp_brief' ) ? daw_covp_brief() : array(),
	);
}

// Kept fresh without anyone opening the page: hourly, and after a full run.
add_action( 'daw_fix_tick', 'daw_kc_store_report_cron', 90 );
add_action( 'daw_kc_run_all_late', 'daw_kc_store_report_cron', 99 );
function daw_kc_store_report_cron() {
	daw_kc_store_report();
}

/**
 * The screen reads a copy at most five minutes old (1.75.23): the full read
 * walks every record, every story and every picture, and a slow admin page
 * is the one the owner sees fall over. The hourly report, "run every check"
 * and the chat's refresh all build it fresh.
 */
function daw_kc_data_cached() {
	$key = 'daw_kc_d_' . ( defined( 'DAW_VERSION' ) ? DAW_VERSION : '0' );
	$d   = isset( $_GET['daw_kc'] ) ? false : get_transient( $key ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! is_array( $d ) ) {
		$d = daw_kc_data();
		set_transient( $key, $d, 5 * MINUTE_IN_SECONDS );
	}
	return $d;
}

function daw_kc_render() {
	$d     = daw_kc_data_cached();
	$todo  = daw_kc_todo( $d );
	$score = daw_kc_score( $d );

	$count = array( 'ok' => 0, 'warn' => 0, 'bad' => 0 );
	foreach ( $d as $area ) {
		$st = $area['state'] ?? 'info';
		if ( isset( $count[ $st ] ) ) {
			$count[ $st ]++;
		}
	}
	$urgent = count( array_filter( $todo, static function ( $x ) { return 'info' !== $x[0]; } ) );

	daw_kc_store_report( $d, $todo, $score );

	$user  = wp_get_current_user();
	$hour  = (int) current_time( 'G' );
	$greet = $hour < 6 ? 'İyi geceler' : ( $hour < 12 ? 'Günaydın' : ( $hour < 18 ? 'İyi günler' : 'İyi akşamlar' ) );

	if ( $score >= 90 && 0 === $count['bad'] ) {
		$sentence = 'Site çok iyi durumda. Her şey yolunda gidiyor.';
	} elseif ( 0 === $count['bad'] ) {
		$sentence = 'Site sağlıklı; birkaç konu iyileştirilebilir.';
	} else {
		$sentence = 'Dikkat isteyen konular var; en acil olanlar aşağıda en üstte.';
	}

	echo '<div class="wrap kc">';
	if ( isset( $_GET['daw_kc'] ) && 'started' === $_GET['daw_kc'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success"><p><strong>Tüm kontroller başladı.</strong> Search Console, Google dizini, bağlantılar, 404, hız ölçümü ve arşiv özeti arka planda çalışıyor; düzeltilebilen hatalar kendiliğinden düzeltilecek. İlerleme aşağıdaki çubukta; bitince sonuçlar bu sayfada.</p></div>';
	}
	echo '<h1 class="screen-reader-text">Kontrol Merkezi</h1>';

	/* ---------- hero ---------- */
	echo '<div class="kc-hero">';
	echo '<div class="kc-hero-txt">';
	printf( '<p class="kc-kicker">%s, %s</p>', esc_html( $greet ), esc_html( $user->display_name ? $user->display_name : $user->user_login ) );
	echo '<p class="kc-title">Kontrol Merkezi</p>';
	printf( '<p class="kc-sum">%s</p>', esc_html( $sentence ) );
	echo '<div class="kc-counts">';
	printf( '<span class="is-ok"><b>%d</b> alan yolunda</span>', (int) $count['ok'] );
	printf( '<span class="is-warn"><b>%d</b> alan bakılmalı</span>', (int) $count['warn'] );
	printf( '<span class="is-bad"><b>%d</b> alanda sorun</span>', (int) $count['bad'] );
	echo '</div>';
	echo '<form class="kc-run" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'daw_kc_run_all' );
	echo '<input type="hidden" name="action" value="daw_kc_run_all">';
	echo '<button type="submit" class="kc-run-btn">↻ Tüm kontrolleri şimdi çalıştır</button>';
	if ( function_exists( 'daw_kcp_render' ) ) {
		daw_kcp_render();
	} else {
		printf( '<span>Son tam kontrol: %s. Kontroller kendiliğinden de her gün çalışır.</span>', esc_html( daw_kc_ago( $d['fix']['run'] ) ) );
	}
	echo '</form>';
	echo '</div>';
	echo '<div class="kc-hero-score">' . daw_kc_ring( $score, 132, 'Genel sağlık' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div>';

	/* ---------- quick figures ---------- */
	$g = $d['gsc'];
	echo '<div class="kc-kpis">';
	printf( '<a class="kc-kpi" href="#kc-speed"><span>Hız (ort.) mobil / masaüstü</span><b>%s / %s</b><small>%d sayfa türü, PageSpeed</small></a>', esc_html( $d['speed']['pages'] ? (string) $d['speed']['avg'] : '—' ), esc_html( $d['speed']['avg_d'] ? (string) $d['speed']['avg_d'] : '—' ), count( $d['speed']['pages'] ) );
	printf( '<a class="kc-kpi" href="#kc-gsc"><span>Google tıklama · 28 gün</span><b>%s</b><small>%s</small></a>', esc_html( isset( $g['clicks'] ) ? daw_kc_n( $g['clicks'] ) : '—' ), isset( $g['clicks'] ) ? daw_kc_delta( $g['clicks'], $g['prev_clicks'] ) . ' önceki 28 güne göre' : 'veri bekleniyor' ); // phpcs:ignore WordPress.Security.EscapeOutput
	printf( '<a class="kc-kpi" href="#kc-gsc"><span>Google gösterim · 28 gün</span><b>%s</b><small>%s</small></a>', esc_html( isset( $g['impr'] ) ? daw_kc_n( $g['impr'] ) : '—' ), isset( $g['impr'] ) ? daw_kc_delta( $g['impr'], $g['prev_impr'] ) . ' önceki 28 güne göre' : 'veri bekleniyor' ); // phpcs:ignore WordPress.Security.EscapeOutput
	printf( '<a class="kc-kpi" href="#kc-archive"><span>Yayındaki kayıt</span><b>%s</b><small>%%%d tam</small></a>', esc_html( daw_kc_n( $d['archive']['all'] ) ), (int) $d['archive']['percent'] );
	printf( '<a class="kc-kpi" href="#kc-seo"><span>Site haritası</span><b>%s</b><small>adres Google\'a açık</small></a>', esc_html( daw_kc_n( $d['seo']['sm'] ) ) );
	echo '</div>';

	/* ---------- to do ---------- */
	ob_start();
	echo '<section class="kc-todo">';
	echo '<header class="kc-todo-h"><h2>Yapılacaklar</h2>';
	printf( '<span>%s</span></header>', $urgent ? esc_html( sprintf( '%d konu ilgi bekliyor', $urgent ) ) : 'Acil iş yok' );
	if ( ! $todo ) {
		echo '<p class="kc-empty">Şu an senin yapman gereken bir şey yok. Site kendi işlerini otomatik yürütüyor.</p>';
	} else {
		echo '<ol>';
		foreach ( array_slice( $todo, 0, 10 ) as $item ) {
			printf(
				'<li class="is-%1$s"><i></i><span>%2$s</span><a class="button%4$s" href="%3$s">%5$s</a></li>',
				esc_attr( $item[0] ),
				esc_html( $item[1] ),
				esc_url( $item[3] ),
				'bad' === $item[0] ? ' button-primary' : '',
				esc_html( $item[2] )
			);
		}
		echo '</ol>';
		if ( count( $todo ) > 10 ) {
			printf( '<p class="kc-more">+%d küçük madde daha</p>', count( $todo ) - 10 );
		}
	}
	echo '<p class="kc-auto">Kendiliğinden yürüyenler: 404 yönlendirme, Search Console verisi, Google dizin kontrolü, bağlantı taraması, IndexNow bildirimi, hız ölçümü, arşiv özeti. Bunlar iş listesine girmez.</p>';
	echo '</section>';
	$todo_html = (string) ob_get_clean();

	// Every card is drawn into a buffer first, then laid out by importance
	// (1.75.0): the to-do list and an overview panel side by side at the top,
	// then the cards that need attention, then the engines, then the rest,
	// packed in columns so no space is left empty beside a short card.
	ob_start();

	if ( function_exists( 'daw_kce_render' ) ) {
		daw_kce_render();
	}

	/* ---------- speed ---------- */
	$sp = $d['speed'];
	daw_kc_card_open( 'speed', '⚡', 'Hız (telefon ve bilgisayar)', $sp['state'], true ); // wide: two devices side by side
	if ( ! $sp['pages'] ) {
		echo '<p class="kc-muted">Henüz ölçüm yok. Ölçüm her sürümden sonra ve günde bir kez kendiliğinden yapılır.</p>';
	} else {
		echo '<div class="kc-scroll"><table class="kc-mini kc-speed"><thead>'
			. '<tr><th rowspan="2">Sayfa</th><th colspan="4" class="kc-grp">📱 Telefon</th><th colspan="3" class="kc-grp">🖥️ Masaüstü</th></tr>'
			. '<tr><th>Skor</th><th>Ana içerik</th><th>Kayma</th><th>Boyut</th><th class="kc-sep">Skor</th><th>Ana içerik</th><th>Kayma</th></tr>'
			. '</thead><tbody>';
		foreach ( $sp['pages'] as $p ) {
			$dcell = null === $p['d']
				? '<td class="kc-sep">—</td><td></td><td></td>'
				: sprintf(
					'<td class="kc-sep"><span class="kc-score is-%s">%d</span></td><td class="is-%s">%s sn</td><td class="is-%s">%s</td>',
					$p['d'] >= 90 ? 'ok' : ( $p['d'] >= 50 ? 'warn' : 'bad' ),
					(int) $p['d'],
					$p['d_lcp'] <= 2500 ? 'ok' : ( $p['d_lcp'] <= 4000 ? 'warn' : 'bad' ),
					esc_html( daw_kc_n( $p['d_lcp'] / 1000, 1 ) ),
					$p['d_cls'] <= 0.1 ? 'ok' : 'warn',
					esc_html( daw_kc_n( $p['d_cls'], 2 ) )
				);
			printf(
				'<tr><td><a href="%s" target="_blank" rel="noopener">%s</a></td><td><span class="kc-score is-%s">%d</span></td><td class="is-%s">%s sn</td><td class="is-%s">%s</td><td>%s KB</td>' . $dcell . '</tr>',
				esc_url( $p['url'] ),
				esc_html( $p['label'] ),
				$p['score'] >= 90 ? 'ok' : ( $p['score'] >= 50 ? 'warn' : 'bad' ),
				(int) $p['score'],
				$p['lcp'] <= 2500 ? 'ok' : ( $p['lcp'] <= 4000 ? 'warn' : 'bad' ),
				esc_html( daw_kc_n( $p['lcp'] / 1000, 1 ) ),
				$p['cls'] <= 0.1 ? 'ok' : 'warn',
				esc_html( daw_kc_n( $p['cls'], 2 ) ),
				esc_html( daw_kc_n( $p['kb'] ) )
			);
		}
		echo '</tbody></table></div>';
		if ( function_exists( 'daw_psi_slow_open' ) ) {
			$slow_all = (array) get_option( 'daw_psi_slow', array() );
			if ( $slow_all ) {
				echo '<h4 class="kc-sub">Yavaş sayfalar listesi</h4>';
				echo '<p class="kc-muted">90 altında ölçülen her sayfa buraya yazılır, her gün yeniden ölçülür ve üst üste iki kez 90+ alınca "düzeldi" olur.</p>';
				echo '<div class="kc-scroll"><table class="kc-mini"><thead><tr><th>Sayfa</th><th>Cihaz</th><th>Skor</th><th>Neden</th><th>Kaç kez</th></tr></thead><tbody>';
				foreach ( array_slice( $slow_all, 0, 20 ) as $e ) {
					if ( ! is_array( $e ) ) {
						continue;
					}
					$fixed = ! empty( $e['fixed'] );
					printf(
						'<tr><td><a href="%s" target="_blank" rel="noopener">%s</a></td><td>%s</td><td><span class="kc-score is-%s">%d</span>%s</td><td>%s</td><td>%d</td></tr>',
						esc_url( $e['url'] ),
						esc_html( wp_make_link_relative( (string) $e['url'] ) ),
						'desktop' === ( $e['strategy'] ?? '' ) ? '🖥️' : '📱',
						$fixed ? 'ok' : ( (int) $e['score'] >= 50 ? 'warn' : 'bad' ),
						(int) $e['score'],
						$fixed ? ' düzeldi' : '',
						esc_html( implode( '; ', array_slice( (array) ( $e['why'] ?? array() ), 0, 3 ) ) ),
						(int) ( $e['count'] ?? 1 )
					);
				}
				echo '</tbody></table></div>';
			}
		}
		$crux = get_option( 'daw_crux' );
		if ( is_array( $crux ) && ! empty( $crux['m'] ) ) {
			$cm  = $crux['m'];
			$cat = array( 'FAST' => array( 'ok', 'iyi' ), 'AVERAGE' => array( 'warn', 'orta' ), 'SLOW' => array( 'bad', 'yavaş' ) );
			$one = static function ( $k, $label, $fmt ) use ( $cm, $cat ) {
				if ( empty( $cm[ $k ] ) ) {
					return '';
				}
				$c = $cat[ $cm[ $k ][1] ] ?? array( 'info', '' );
				return sprintf( '<span class="kc-score is-%s">%s %s</span>', esc_attr( $c[0] ), esc_html( $label ), esc_html( $fmt( $cm[ $k ][0] ) ) );
			};
			echo '<p class="kc-crux"><b>Gerçek okurlar (Chrome, 28 gün):</b> '
				. $one( 'LARGEST_CONTENTFUL_PAINT_MS', 'LCP', static function ( $v ) { return daw_kc_n( $v / 1000, 1 ) . ' sn'; } ) . ' '
				. $one( 'INTERACTION_TO_NEXT_PAINT', 'INP', static function ( $v ) { return daw_kc_n( $v ) . ' ms'; } ) . ' '
				. $one( 'CUMULATIVE_LAYOUT_SHIFT_SCORE', 'CLS', static function ( $v ) { return daw_kc_n( $v / 100, 2 ); } ) . ' '
				. $one( 'EXPERIMENTAL_TIME_TO_FIRST_BYTE', 'TTFB', static function ( $v ) { return daw_kc_n( $v ) . ' ms'; } )
				. '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			echo '<p class="kc-crux"><b>Gerçek okurlar (Chrome):</b> Google henüz yeterli gerçek ziyaretçi verisi toplamadı; trafik büyüdükçe burada görünecek.</p>';
		}
		printf( '<p class="kc-muted">Google PageSpeed, telefon, her sayfa türünün en yeni kaydı; son ölçüm %s. Her sürümden sonra ve günde bir kez kendiliğinden ölçülür. Hedef: 90+, ana içerik 2,5 sn altı.</p>', esc_html( daw_kc_ago( $sp['at'] ) ) );
	}
	daw_kc_card_close( array( 'Hız & Sağlık' => daw_menu_url( 'daw-speed-audit' ) ) );

	/* ---------- Search Console ---------- */
	daw_kc_card_open( 'gsc', '◎', 'Google arama trafiği', $g['state'] );
	if ( ! $g['connected'] ) {
		echo '<p class="kc-muted">Search Console bağlı değil.</p>';
	} elseif ( ! isset( $g['clicks'] ) ) {
		echo '<p class="kc-muted">Veri geliyor; ilk günler birkaç dakika içinde dolar.</p>';
	} else {
		echo '<div class="kc-stats">';
		daw_kc_stat( daw_kc_n( $g['clicks'] ), 'tıklama', daw_kc_delta( $g['clicks'], $g['prev_clicks'] ) );
		daw_kc_stat( daw_kc_n( $g['impr'] ), 'gösterim', daw_kc_delta( $g['impr'], $g['prev_impr'] ) );
		daw_kc_stat( $g['impr'] ? '%' . daw_kc_n( $g['clicks'] / $g['impr'] * 100, 1 ) : '—', 'tıklama oranı' );
		daw_kc_stat( $g['pos'] ? daw_kc_n( $g['pos'], 1 ) : '—', 'ort. sıra', $g['prev_pos'] && $g['pos'] ? daw_kc_delta( $g['prev_pos'], $g['pos'] ) : '' );
		echo '</div>';
		echo '<div class="kc-chart"><span>Günlük gösterim</span>' . daw_kc_spark( $g['spark_i'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="kc-chart is-alt"><span>Günlük tıklama</span>' . daw_kc_spark( $g['spark_c'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $g['top'] ) {
			echo '<h3>En çok tıklanan sayfalar</h3><ol class="kc-top">';
			foreach ( $g['top'] as $row ) {
				printf( '<li><a href="%1$s" target="_blank" rel="noopener">%2$s</a><span>%3$s tık · %4$s gös.</span></li>', esc_url( home_url( $row['page'] ) ), esc_html( '/' === $row['page'] ? 'Ana sayfa' : untrailingslashit( $row['page'] ) ), esc_html( daw_kc_n( $row['c'] ) ), esc_html( daw_kc_n( $row['i'] ) ) );
			}
			echo '</ol>';
		}
		printf( '<p class="kc-muted">%s – %s. %s</p>', esc_html( date_i18n( 'j M', strtotime( $g['from'] ) ) ), esc_html( date_i18n( 'j M Y', strtotime( $g['to'] ) ) ), $g['backfill'] ? esc_html( sprintf( 'Geçmiş veri yükleniyor: %d gün alındı, 16 aya kadar tamamlanacak.', (int) $g['days'] ) ) : esc_html( sprintf( '%d günlük geçmiş kayıtlı.', (int) $g['days'] ) ) );
	}
	daw_kc_card_close( array( 'Search Console' => daw_kc_seo_url( 'gsc' ) ) );

	/* ---------- Analytics ---------- */
	if ( function_exists( 'daw_ga4_card' ) ) {
		daw_ga4_card();
	}

	/* ---------- Google index ---------- */
	$ix = $d['index'];
	daw_kc_card_open( 'index', '☰', 'Google dizini', $ix['state'] );
	if ( ! $ix['inspected'] ) {
		echo '<p class="kc-muted">Sayfalar saatte 40 adet Google\'a soruluyor; ilk sonuçlar birazdan burada.</p>';
	} else {
		echo '<div class="kc-stats">';
		daw_kc_stat( daw_kc_n( $ix['indexed'] ), 'dizinde' );
		daw_kc_stat( daw_kc_n( $ix['not'] ), 'dizinde değil' );
		daw_kc_stat( daw_kc_n( $ix['inspected'] ) . ' / ' . daw_kc_n( $ix['published'] ), 'kontrol edildi' );
		echo '</div>';
		echo daw_kc_split( array( array( $ix['indexed'], 'ok' ), array( $ix['not'], 'warn' ), array( max( 0, $ix['published'] - $ix['inspected'] ), 'none' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $ix['reasons'] ) {
			echo '<ul class="kc-reasons">';
			foreach ( $ix['reasons'] as $r ) {
				printf( '<li><span>%s</span><b>%s</b></li>', esc_html( daw_kc_coverage_tr( $r['coverage'] ) ), esc_html( daw_kc_n( $r['n'] ) ) );
			}
			echo '</ul>';
		}
		$gain = function_exists( 'daw_ib_week_gain' ) ? daw_ib_week_gain() : null;
		echo '<ul class="kc-checks">';
		if ( null !== $gain ) {
			daw_kc_check( $gain >= 0, sprintf( 'Son 7 günde dizindeki sayfa sayısı: %s%s', $gain >= 0 ? '+' : '−', daw_kc_n( abs( $gain ) ) ), $gain > 0 ? 'ok' : 'info' );
		}
		daw_kc_check( true, 'Dizin hızlandırıcı açık: dizine girmemiş sayfalar, Google\'ın okuduğu sayfaların "Benzer" bölümünde öne alınıyor', 'ok' );
		echo '</ul>';
		echo '<p class="kc-muted">Yeni sayfaların dizine girmesi birkaç gün ile birkaç hafta sürebilir; "taranmış, dizine eklenmemiş" çoğu zaman içeriğin güçlendirilmesiyle düzelir.</p>';
	}
	$smst = (array) ( $d['gsc']['sm'] ?? array() );
	echo '<ul class="kc-checks">';
	if ( ! empty( $smst['mine'] ) ) {
		$mine = $smst['mine'];
		daw_kc_check( 0 === (int) $mine['errors'], sprintf( 'Site haritası Google\'da: %s adres okundu · %d hata · %d uyarı', daw_kc_n( $mine['urls'] ), (int) $mine['errors'], (int) $mine['warnings'] ), (int) $mine['errors'] > 0 ? 'bad' : ( (int) $mine['warnings'] > 0 ? 'warn' : 'ok' ) );
		daw_kc_check( true, sprintf( 'Son gönderim %s · Google son okuma %s', '' !== $mine['submitted'] ? date_i18n( 'j M H:i', strtotime( $mine['submitted'] ) ) : '—', '' !== $mine['downloaded'] ? date_i18n( 'j M H:i', strtotime( $mine['downloaded'] ) ) : 'henüz yok' ), 'info' );
	} elseif ( ! empty( $smst['at'] ) ) {
		daw_kc_check( false, '' !== (string) ( $smst['error'] ?? '' ) ? 'Site haritası durumu okunamadı: ' . $smst['error'] : 'Site haritası Google\'a gönderiliyor', 'warn' );
	} else {
		daw_kc_check( true, 'Site haritası günde bir Google\'a sorulur ve değiştiyse yeniden gönderilir', 'info' );
	}
	echo '</ul>';
	daw_kc_card_close( array( 'Google dizini' => daw_kc_seo_url( 'dizin' ) ) );

	/* ---------- automatic repairs ---------- */
	$fx = $d['fix'];
	daw_kc_card_open( 'fix', '✚', 'Otomatik onarım', $fx['state'], true );
	echo '<div class="kc-fix-top">';
	printf( '<p>%s</p>', $fx['on'] ? 'Açık. Site düzeltebildiği hataları kendisi düzeltir, her değişikliği buraya yazar ve değişen yazının eski hâlini revizyon olarak saklar.' : 'Kapalı. Hatalar bulunur ama kendiliğinden düzeltilmez.' );
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'daw_fix_toggle' );
	echo '<input type="hidden" name="action" value="daw_fix_toggle">';
	printf( '<button type="submit" class="button%s">%s</button></form>', $fx['on'] ? '' : ' button-primary', $fx['on'] ? 'Kapat' : 'Aç' );
	echo '</div>';
	echo '<ul class="kc-checks kc-cols">';
	daw_kc_check( true, '404 veren adresler doğru sayfaya yönlenir (saatte bir)', 'ok' );
	daw_kc_check( true, 'Google\'ın gördüğü ama sitede olmayan adresler 404 kaydına alınıp yönlenir', 'ok' );
	daw_kc_check( true, 'Yazılardaki kırık ve yönlendirmeli bağlantılar düzeltilir', 'ok' );
	daw_kc_check( true, 'Sitede sayfası olan dizi ve kişi adları (iki kelime ve üstü, başka bir özel ada yapışık değilse) ilk geçtiği yerde bağlantı olur; yazı başına en çok 5', 'ok' );
	daw_kc_check( true, 'Site haritası değişince Search Console\'a yeniden gönderilir', 'ok' );
	daw_kc_check( true, 'Yeni ve değişen sayfalar IndexNow ile Bing ve Yandex\'e bildirilir', 'ok' );
	daw_kc_check( true, 'Kaldırılmış eklentilerden kalan zamanlanmış işler temizlenir', 'ok' );
	echo '</ul>';
	if ( $fx['last'] ) {
		printf( '<h3>Son yapılanlar (7 günde %s)</h3><ol class="kc-log">', esc_html( daw_kc_n( $fx['week'] ) ) );
		foreach ( $fx['last'] as $row ) {
			printf(
				'<li><em>%s</em><span>%s%s</span></li>',
				esc_html( daw_kc_ago( (int) $row['at'] ) ),
				esc_html( (string) $row['text'] ),
				! empty( $row['post'] ) ? ' · <a href="' . esc_url( (string) get_edit_post_link( (int) $row['post'] ) ) . '">' . esc_html( get_the_title( (int) $row['post'] ) ) . '</a>' : ''
			);
		}
		echo '</ol>';
	} else {
		echo '<p class="kc-muted">Henüz bir onarım yapılmadı; ilk tur bir saat içinde ya da "Tüm kontrolleri şimdi çalıştır" ile hemen başlar.</p>';
	}
	daw_kc_card_close();

	/* ---------- SEO engine ---------- */
	$seo = $d['seo'];
	daw_kc_card_open( 'seo', '✦', 'SEO motoru', $seo['state'] );
	echo '<ul class="kc-checks">';
	daw_kc_check( $seo['live'], $seo['live'] ? 'Başlık, açıklama, canonical ve şema basılıyor' : ( $seo['rm'] ? 'Rank Math açık, motor beklemede' : 'Motor kapalı' ) );
	daw_kc_check( $seo['sm'] > 0, sprintf( 'sitemap_index.xml: %s adres', daw_kc_n( $seo['sm'] ) ) );
	daw_kc_check( $seo['robots'], $seo['robots'] ? 'Arama motorlarına açık' : 'Site arama motorlarına kapalı!' );
	daw_kc_check( true, sprintf( '%s etkin yönlendirme (%s kez kullanıldı)', daw_kc_n( $seo['redirects'] ), daw_kc_n( $seo['hits'] ?? 0 ) ) );
	daw_kc_check( $seo['auto404'], $seo['auto404'] ? '404\'ler saatte bir otomatik yönleniyor' : '404 otomatik düzeltme kapalı', $seo['auto404'] ? 'ok' : 'warn' );
	daw_kc_check( 0 === $seo['open404'], sprintf( 'Açık 404: %s · son 7 gün %s ziyaret', daw_kc_n( $seo['open404'] ), daw_kc_n( $seo['hits7'] ) ), 0 === $seo['open404'] ? 'ok' : 'warn' );
	echo '</ul>';
	daw_kc_card_close(
		array(
			'SEO'        => daw_kc_seo_url( 'durum' ),
			'404 kaydı'  => daw_kc_seo_url( '404' ),
		)
	);

	/* ---------- links ---------- */
	$lk = $d['links'];
	daw_kc_card_open( 'links', '⛓', 'İç bağlantılar', $lk['state'] );
	echo '<div class="kc-stats">';
	daw_kc_stat( daw_kc_n( $lk['broken'] ), 'kırık' );
	daw_kc_stat( daw_kc_n( $lk['redirected'] ), 'yönlendirmeli' );
	daw_kc_stat( daw_kc_n( $lk['unlinked'] ), 'bağlanabilir isim' );
	echo '</div>';
	printf( '<p class="kc-muted">Her yazı saatte 40\'ar taranır; kaydedilen yazı anında. Son tam tur: %s. %s</p>', esc_html( daw_kc_ago( $lk['pass'] ) ), $d['fix']['on'] ? 'Otomatik onarım açık: yönlendirmeli bağlantılar doğrudan hedefe, kırıklar doğru sayfaya bağlanır; sitede sayfası olan çok kelimeli adların ilk geçtiği yer bağlantı olur.' : 'Otomatik onarım kapalı.' );
	daw_kc_card_close( array( 'Bağlantılar' => daw_kc_seo_url( 'baglanti' ) ) );

	/* ---------- archive ---------- */
	$a = $d['archive'];
	daw_kc_card_open( 'archive', '▤', 'İçerik arşivi', $a['state'] );
	echo '<div class="kc-stats">';
	daw_kc_stat( daw_kc_n( $a['titles'] ), 'dizi / film' );
	daw_kc_stat( daw_kc_n( $a['people'] ), 'kişi' );
	daw_kc_stat( daw_kc_n( $a['articles'] ), 'makale' );
	echo '</div>';
	printf( '<div class="kc-progress"><span style="width:%d%%"></span></div><p class="kc-progress-l"><b>%%%d</b> kayıt şekil olarak tam (%s / %s) · %s kayıt tek tek doğrulandı</p>', (int) $a['percent'], (int) $a['percent'], esc_html( daw_kc_n( $a['complete'] ) ), esc_html( daw_kc_n( $a['all'] ) ), esc_html( daw_kc_n( $a['verified'] ) ) );
	printf( '<p class="kc-muted">Özet %s hesaplandı; günde iki kez yenilenir.</p>', esc_html( daw_kc_ago( $a['at'] ) ) );
	daw_kc_card_close(
		array(
			'Denetim'        => admin_url( 'admin.php?page=daw-denetim' ),
			'Arşiv denetimi' => daw_menu_url( 'daw-archive' ),
		)
	);

	/* ---------- pictures ---------- */
	$pi = $d['pictures'];
	daw_kc_card_open( 'pictures', '▣', 'Görseller', $pi['state'] );
	echo '<div class="kc-stats">';
	daw_kc_stat( daw_kc_n( $pi['remote'] ), 'TMDB\'den' );
	daw_kc_stat( daw_kc_n( $pi['local'] ), 'kendi lisanslı' );
	daw_kc_stat( daw_kc_n( $pi['bad'] ), 'sorunlu' );
	echo '</div>';
	echo '<ul class="kc-checks">';
	foreach ( $pi['parts'] as $gk => $pp ) {
		daw_kc_check( false, $pp[0] . ': ' . daw_kc_n( $pp[1] ), in_array( $gk, array( 'no_id', 'mismatch', 'gone', 'no_backup' ), true ) ? 'warn' : 'info' );
	}
	daw_kc_check( true, sprintf( 'Haber/rehber görseli lisanslı: %s', daw_kc_n( $pi['lic_ok'] ) ) );
	daw_kc_check( 0 === $pi['unreg'], sprintf( 'Lisans kaydı olmayan: %s', daw_kc_n( $pi['unreg'] ) ), 0 === $pi['unreg'] ? 'ok' : 'warn' );
	daw_kc_check( 0 === $pi['inc'], sprintf( 'Bilgisi eksik: %s', daw_kc_n( $pi['inc'] ) ), 0 === $pi['inc'] ? 'ok' : 'info' );
	echo '</ul>';
	daw_kc_card_close(
		array(
			'Görseller'          => daw_menu_url( 'daw-images' ),
			'Commons & lisanslı' => daw_menu_url( 'daw-image-rights' ),
		)
	);

	/* ---------- freshness and backlog ---------- */
	$f = $d['fresh'];
	daw_kc_card_open( 'fresh', '↻', 'Güncellik ve eksikler', $f['state'] );
	echo '<ul class="kc-checks">';
	daw_kc_check( true, sprintf( 'Yayın durumu: her gün tarihlerden kendiliğinden düzeltilir (sırada %s)', daw_kc_n( $f['status'] ) ), 'ok' );
	daw_kc_check( true, sprintf( 'İzleme bağlantıları günde 50 kontrol edilir (sırada %s)', daw_kc_n( $f['watch'] ) ), 'info' );
	daw_kc_check( true, sprintf( 'Fragman bağlantıları sırada: %s', daw_kc_n( $f['trailer'] ) ), 'info' );
	daw_kc_check( 0 === $f['news'], sprintf( 'Eskimiş olabilecek haber: %s', daw_kc_n( $f['news'] ) ), 0 === $f['news'] ? 'ok' : 'info' );
	daw_kc_check( 0 === $f['people'], sprintf( 'Kaydı olmayan oyuncu: %s · eksik işi olan kişi: %s', daw_kc_n( $f['people'] ), daw_kc_n( $f['works'] ) ), 'info' );
	echo '</ul>';
	daw_kc_card_close(
		array(
			'Güncellik' => daw_menu_url( 'daw-fresh' ),
			'Eksikler'  => daw_menu_url( 'daw-backlog' ),
		)
	);

	/* ---------- background jobs ---------- */
	$j = $d['jobs'];
	daw_kc_card_open( 'jobs', '⚙', 'Otomatik işler', $j['state'] );
	echo '<ul class="kc-jobs">';
	foreach ( $j['rows'] as $row ) {
		$when = ! $row['next'] ? 'planlı değil' : ( $row['next'] <= time() ? 'sırada (' . human_time_diff( $row['next'] ) . ')' : human_time_diff( $row['next'] ) . ' sonra' );
		printf( '<li class="is-%s"><i></i><span>%s</span><em>%s</em></li>', esc_attr( $row['state'] ), esc_html( $row['label'] ), esc_html( $when ) );
	}
	echo '</ul>';
	if ( ! empty( $j['late_hooks'] ) ) {
		echo '<h3>15 dakikadan fazla gecikenler</h3><ul class="kc-jobs">';
		foreach ( $j['late_hooks'] as $hook => $ts ) {
			printf( '<li class="is-warn"><i></i><span>%s</span><em>%s gecikti</em></li>', esc_html( $hook ), esc_html( human_time_diff( (int) $ts ) ) );
		}
		echo '</ul>';
	}
	echo '<p class="kc-muted">İşler siteye gelen ziyaretle tetiklenir; birkaç dakikalık "sırada" normaldir.</p>';
	daw_kc_card_close( array( 'Otomatik işler' => admin_url( 'admin.php?page=daw-otomatik' ) ) );

	/* ---------- server ---------- */
	$sv = $d['server'];
	daw_kc_card_open( 'server', '▥', 'Sunucu ve WordPress', $sv['state'] );
	echo '<div class="kc-stats">';
	daw_kc_stat( $sv['ttfb'] ? daw_kc_n( $sv['ttfb'] ) . ' ms' : '—', 'ilk bayt (' . $sv['ttfb_src'] . ')' );
	daw_kc_stat( daw_kc_n( $sv['health']['good'] ), 'kontrol geçti' );
	daw_kc_stat( daw_kc_n( $sv['health']['recommended'] + $sv['health']['critical'] ), 'öneri / kritik' );
	echo '</div>';
	echo '<ul class="kc-checks">';
	daw_kc_check( $sv['cache'], $sv['cache'] ? 'LiteSpeed sayfa önbelleği açık' : 'Sayfa önbelleği bulunamadı', $sv['cache'] ? 'ok' : 'warn' );
	daw_kc_check( function_exists( 'daw_watchdog_ok' ) && daw_watchdog_ok(), function_exists( 'daw_watchdog_ok' ) && daw_watchdog_ok() ? 'Bekçi açık: DAW Core durursa e-posta ve uyarı gelir' : 'Bekçi kurulamadı (mu-plugins klasörüne yazılamadı)', function_exists( 'daw_watchdog_ok' ) && daw_watchdog_ok() ? 'ok' : 'warn' );
	daw_kc_check( true, 'Sunucu tamamen düşerse: ücretsiz UptimeRobot ile ana sayfayı izlet', 'info' );
	daw_kc_check( $sv['objcache'], $sv['objcache'] ? 'Nesne önbelleği açık' : 'Nesne önbelleği kapalı', $sv['objcache'] ? 'ok' : 'info' );
	foreach ( $sv['issues'] as $is ) {
		daw_kc_check( false, $is[1], 'critical' === $is[0] ? 'bad' : 'info' );
	}
	echo '</ul>';
	printf( '<p class="kc-muted">DAW %s · WordPress %s · PHP %s · ölçüm %s</p>', esc_html( $sv['version'] ), esc_html( $sv['wp'] ), esc_html( $sv['php'] ), esc_html( daw_kc_ago( $sv['at'] ) ) );
	daw_kc_card_close( array( 'Site sağlığı' => admin_url( 'site-health.php' ) ) );

	/* ---------- APIs ---------- */
	$ap = $d['api'];
	$ix2 = $d['indexnow'];
	daw_kc_card_open( 'api', '⇄', 'Bağlantılar ve API', $ap['state'] );
	echo '<ul class="kc-checks">';
	daw_kc_check( $ap['tmdb_key'] && ! $ap['tmdb_err'], $ap['tmdb_key'] ? sprintf( 'TMDB: bugün %s, bu ay %s istek%s', daw_kc_n( $ap['tmdb_today'] ), daw_kc_n( $ap['tmdb_month'] ), $ap['tmdb_err'] ? ' · son istek hata' : '' ) : 'TMDB anahtarı girilmedi', $ap['tmdb_key'] && ! $ap['tmdb_err'] ? 'ok' : 'warn' );
	daw_kc_check( $ap['gsc'], $ap['gsc'] ? 'Search Console bağlı' : 'Search Console bağlı değil' );
	daw_kc_check( $ap['indexnow'], $ix2['last'] ? sprintf( 'IndexNow: son bildirim %s (kod %s) · 7 günde %s adres', daw_kc_ago( $ix2['last'] ), $ix2['code'], daw_kc_n( $ix2['week'] ) ) : 'IndexNow hazır; yeni yayında Bing/Yandex\'e bildirir', 'ok' === $ix2['state'] || 'info' === $ix2['state'] ? 'ok' : 'warn' );
	daw_kc_check( true, $ap['psi_key'] ? 'PageSpeed: kendi anahtarıyla' : 'PageSpeed: ortak kota (Search Console hesabıyla yedekli)', 'ok' );
	echo '</ul>';
	daw_kc_card_close(
		array(
			'API anahtarları' => daw_menu_url( 'daw-api' ),
			'IndexNow'        => daw_kc_seo_url( 'indexnow' ),
		)
	);

	/* ---------- ads and privacy ---------- */
	$ad = $d['ads'];
	daw_kc_card_open( 'ads', '◈', 'Reklam ve gizlilik', $ad['state'] );
	echo '<ul class="kc-checks">';
	daw_kc_check( $ad['live'], $ad['live'] ? sprintf( 'AdSense yayında · %s reklam alanı', daw_kc_n( $ad['units'] ) ) : 'AdSense bu adreste kapalı', $ad['live'] ? 'ok' : 'info' );
	daw_kc_check( true, 'Reklam kodu sayfa çizildikten ve ilk dokunuştan sonra yüklenir (hızı ve dokunmayı etkilemez)', 'ok' );
	daw_kc_check( $ad['cmp'], 'GDPR onay mesajı: Google Gizlilik ve mesajlaşma (AB, İngiltere, İsviçre)', $ad['cmp'] ? 'ok' : 'info' );
	daw_kc_check( $ad['ga'], $ad['ga'] ? 'Analytics onaya bağlı yükleniyor' : 'Analytics kapalı', $ad['ga'] ? 'ok' : 'info' );
	echo '</ul>';
	daw_kc_card_close();

	if ( ! empty( $d['ai'] ) && function_exists( 'daw_aib_card' ) ) {
		daw_aib_card( $d['ai'] );
	}
	if ( ! empty( $d['sec'] ) && function_exists( 'daw_sec_card' ) ) {
		daw_sec_card( $d['sec'] );
	}

	if ( function_exists( 'daw_idx_card' ) ) {
		daw_idx_card( $d );
	}
	if ( function_exists( 'daw_covp_card' ) ) {
		daw_covp_card( $d );
	}
	if ( function_exists( 'daw_llm_card' ) ) {
		daw_llm_card( $d );
	}

	/* ---------- what changed ---------- */
	$changes = array_reverse( array_slice( (array) get_option( 'daw_kc_changes', array() ), -12 ) );
	$hist    = (array) get_option( 'daw_kc_hist', array() );
	$names   = daw_kc_area_names();
	$worse   = array_filter( $changes, static fn( $c ) => (int) $c['at'] > time() - DAY_IN_SECONDS && in_array( $c['to'], array( 'warn', 'bad' ), true ) );
	daw_kc_card_open( 'changes', '⇅', 'Son değişiklikler', $worse ? 'warn' : 'ok', true );
	if ( count( $hist ) > 1 ) {
		echo daw_kc_spark( array_map( static fn( $h ) => (int) $h['score'], $hist ), 560, 44 ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from integers.
		printf( '<p class="kc-muted">Genel sağlık, son %d saat: en düşük %d, en yüksek %d.</p>', count( $hist ), min( array_map( static fn( $h ) => (int) $h['score'], $hist ) ), max( array_map( static fn( $h ) => (int) $h['score'], $hist ) ) );
	}
	echo '<ul class="kc-checks">';
	if ( ! $changes ) {
		daw_kc_check( true, 'Henüz kayıtlı bir değişiklik yok. Her saatlik kontrolde bir alan yeşilden sarıya ya da geri dönerse burada saatiyle görünür.', 'info' );
	}
	foreach ( $changes as $c ) {
		$up = 'ok' === $c['to'] || ( 'warn' === $c['to'] && 'bad' === $c['from'] );
		daw_kc_check(
			$up,
			sprintf( '%s · %s: %s → %s%s', wp_date( 'j M H:i', (int) $c['at'] ), $names[ $c['area'] ] ?? $c['area'], daw_kc_state_word( $c['from'] ), daw_kc_state_word( $c['to'] ), '' !== (string) $c['why'] ? ' (' . $c['why'] . ')' : '' ),
			$up ? 'ok' : $c['to']
		);
	}
	echo '</ul>';
	daw_kc_card_close();

	$grid_html = (string) ob_get_clean();
	echo daw_kc_layout( $todo_html, $grid_html, $d ); // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts.
	echo '</div>'; // wrap
}

/** Cut the drawn cards apart: [ preamble, [ id, icon, title, state, wide, html ], ... ]. */
function daw_kc_split_cards( $html ) {
	$parts = preg_split( '/(?=<section class="kc-card)/', $html );
	$pre   = '';
	$cards = array();
	foreach ( $parts as $i => $part ) {
		if ( 0 !== strpos( $part, '<section class="kc-card' ) ) {
			$pre .= $part;
			continue;
		}
		preg_match( '/id="kc-([^"]+)"/', $part, $mi );
		preg_match( '/<section class="kc-card([^"]*)"/', $part, $mc );
		preg_match( '/kc-pill is-([a-z]+)/', $part, $ms );
		preg_match( '#<span class="kc-ico"[^>]*>(.*?)</span>#s', $part, $mo );
		preg_match( '#<h2>(.*?)</h2>#s', $part, $mt );
		$cards[] = array(
			'id'    => (string) ( $mi[1] ?? 'card' . $i ),
			'icon'  => (string) ( $mo[1] ?? '' ),
			'title' => wp_strip_all_tags( (string) ( $mt[1] ?? '' ) ),
			'state' => (string) ( $ms[1] ?? 'info' ),
			'wide'  => false !== strpos( (string) ( $mc[1] ?? '' ), 'is-wide' ),
			'table' => false !== strpos( $part, '<table' ),
			'html'  => $part,
			'n'     => $i,
		);
	}
	return array( $pre, $cards );
}

/** One short line per area for the overview panel, from the same data. */
function daw_kc_tile_note( $id, $d ) {
	$n  = static fn( $v ) => daw_kc_n( (int) $v );
	$ns = get_option( 'daw_news_stale', array() );
	$ga = get_option( 'daw_ga4_report', array() );
	switch ( $id ) {
		case 'idx':
			$ic = function_exists( 'daw_idx_doc' ) ? (array) ( daw_idx_doc()['count'] ?? array() ) : array();
			arsort( $ic );
			$names = function_exists( 'daw_idx_reasons' ) ? daw_idx_reasons() : array();
			return $ic ? sprintf( 'En çok: %s (%s)', $names[ key( $ic ) ][0] ?? key( $ic ), $n( reset( $ic ) ) ) : '';
		case 'cov':
			$cv = function_exists( 'daw_covp_summary' ) ? daw_covp_summary() : array();
			return $cv ? sprintf( '%s bağlantı eksik, %s kişi kaydı yok', $n( $cv['unlinked'] ), $n( $cv['people'] ) ) : '';
		case 'changes':
			$c = (array) get_option( 'daw_kc_changes', array() );
			return $c ? 'Son: ' . wp_date( 'j M H:i', (int) end( $c )['at'] ) : 'Değişiklik yok';
		case 'engines':
			return function_exists( 'daw_kce_engines' ) ? count( daw_kce_engines() ) . ' motor, istediğinde çalışır' : '';
		case 'news-stale':
			return sprintf( '%s haber kontrol bekliyor', $n( count( (array) ( $ns['rows'] ?? array() ) ) ) );
		case 'broken-img':
			return sprintf( '%s kırık görsel', $n( $d['ick']['bad'] ?? 0 ) );
		case 'rd-audit':
			$bad = 0;
			foreach ( (array) ( $d['rda']['count'] ?? array() ) as $k => $v ) {
				$bad += in_array( $k, array( 'external', 'dormant' ), true ) ? 0 : (int) $v;
			}
			return sprintf( '%s kural · %s sorunlu', $n( $d['rda']['total'] ?? 0 ), $n( $bad ) );
		case 'search-opps':
			return sprintf( '%s fırsat', $n( $d['opps']['n'] ?? 0 ) );
		case 'ga4-quality':
		case 'ga4':
			return isset( $ga['now']['activeUsers'] ) ? sprintf( '%s kullanıcı · 28 gün', $n( $ga['now']['activeUsers'] ) ) : '';
		case 'speed':
			return isset( $d['speed']['avg'] ) ? 'Ortalama skor ' . (int) $d['speed']['avg'] : '';
		case 'gsc':
			return isset( $d['gsc']['clicks'] ) ? sprintf( '%s tık · %s gösterim', $n( $d['gsc']['clicks'] ), $n( $d['gsc']['impr'] ?? 0 ) ) : '';
		case 'index':
			return isset( $d['index']['inspected'] ) ? sprintf( '%s / %s sayfa dizinde', $n( $d['index']['indexed'] ?? 0 ), $n( $d['index']['inspected'] ) ) : '';
		case 'fix':
			return sprintf( 'Bu hafta %s düzeltme', $n( $d['fix']['week'] ?? 0 ) );
		case 'seo':
			return sprintf( '%s yönlendirme · %s açık 404', $n( $d['seo']['redirects'] ?? 0 ), $n( $d['seo']['open404'] ?? 0 ) );
		case 'links':
			return sprintf( '%s kırık · %s bağlantısız', $n( $d['links']['broken'] ?? 0 ), $n( $d['links']['unlinked'] ?? 0 ) );
		case 'archive':
			return sprintf( '%s kayıt · %%%d tam', $n( $d['archive']['all'] ?? 0 ), (int) ( $d['archive']['percent'] ?? 0 ) );
		case 'pictures':
			return sprintf( '%s sorunlu görsel', $n( $d['pictures']['bad'] ?? 0 ) );
		case 'fresh':
			return sprintf( '%s yayın durumu · %s izleme · %s fragman', $n( $d['fresh']['status'] ?? 0 ), $n( $d['fresh']['watch'] ?? 0 ), $n( $d['fresh']['trailer'] ?? 0 ) );
		case 'jobs':
			return sprintf( '%s geciken iş', $n( $d['jobs']['late'] ?? 0 ) );
		case 'server':
			return trim( ( ! empty( $d['server']['php'] ) ? 'PHP ' . $d['server']['php'] : '' ) . ( ! empty( $d['server']['ttfb'] ) ? ' · ilk bayt ' . (int) $d['server']['ttfb'] . ' ms' : '' ), ' ·' );
		case 'api':
			return isset( $d['api']['tmdb_today'] ) ? sprintf( 'TMDB bugün %s istek', $n( $d['api']['tmdb_today'] ) ) : '';
		case 'ads':
			return sprintf( '%s reklam birimi', $n( $d['ads']['units'] ?? 0 ) );
		case 'sec':
			return sprintf( '%s kontrol · %s sorun', $n( count( (array) ( $d['sec']['rows'] ?? array() ) ) ), $n( $d['sec']['bad'] ?? 0 ) );
		case 'ai':
			return sprintf( 'Bu ay %s bot ziyareti', $n( array_sum( array_map( 'intval', (array) ( $d['ai']['month'] ?? array() ) ) ) ) );
	}
	return '';
}

/** The page below the hero, ordered by importance. */
function daw_kc_layout( $todo_html, $grid_html, $d ) {
	list( $pre, $cards ) = daw_kc_split_cards( $grid_html );
	$rank = array( 'bad' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3 );
	usort(
		$cards,
		static function ( $a, $b ) use ( $rank ) {
			$x = ( $rank[ $a['state'] ] ?? 2 ) <=> ( $rank[ $b['state'] ] ?? 2 );
			return 0 !== $x ? $x : $a['n'] <=> $b['n'];
		}
	);

	$count = array( 'bad' => 0, 'warn' => 0, 'info' => 0, 'ok' => 0 );
	foreach ( $cards as $c ) {
		$count[ $c['state'] ] = ( $count[ $c['state'] ] ?? 0 ) + 1;
	}

	/* overview panel */
	$tiles = '';
	foreach ( $cards as $c ) {
		$note   = daw_kc_tile_note( $c['id'], $d );
		$tiles .= sprintf(
			'<a class="kc-tile is-%1$s" href="#kc-%2$s"><span class="kc-tile-i" aria-hidden="true">%3$s</span><span class="kc-tile-t"><b>%4$s</b>%5$s</span><em>%6$s</em></a>',
			esc_attr( $c['state'] ),
			esc_attr( $c['id'] ),
			$c['icon'],
			esc_html( $c['title'] ),
			'' !== $note ? '<small>' . esc_html( $note ) . '</small>' : '',
			esc_html( daw_kc_state_word( $c['state'] ) )
		);
	}
	$over  = '<section class="kc-over"><header class="kc-todo-h"><h2>Genel bakış</h2>';
	$over .= sprintf( '<span><b class="is-bad">%d</b> sorun · <b class="is-warn">%d</b> bakılmalı · <b class="is-ok">%d</b> yolunda</span></header>', (int) $count['bad'], (int) $count['warn'], (int) ( $count['ok'] + $count['info'] ) );
	$over .= '<p class="kc-over-note">Bir kutuya tıkla, ayrıntısına gidip oradan devam et. Önem sırasına göre dizili.</p>';
	$over .= '<div class="kc-tiles">' . $tiles . '</div></section>';

	$out  = $pre;
	$out .= '<div class="kc-top">' . $todo_html . $over . '</div>';

	/* zones */
	$zones = array(
		'att'  => array( 'Dikkat isteyenler', array( 'bad', 'warn' ) ),
		'rest' => array( 'Yolunda olanlar ve bilgiler', array( 'info', 'ok' ) ),
	);
	$engines = '';
	foreach ( $zones as $key => $z ) {
		$full = '';
		$mas  = '';
		foreach ( $cards as $c ) {
			if ( ! in_array( $c['state'], $z[1], true ) ) {
				continue;
			}
			if ( 'engines' === $c['id'] ) {
				$engines = $c['html'];
				continue;
			}
			// A card that carries a table of rows needs the width; a card that
			// only says "nothing to do" joins the columns.
			if ( $c['wide'] && ( $c['table'] || in_array( $c['state'], array( 'bad', 'warn' ), true ) ) ) {
				$full .= $c['html'];
			} else {
				$mas .= str_replace( ' is-wide"', '"', $c['html'] );
			}
		}
		if ( '' === $full && '' === $mas ) {
			continue;
		}
		$out .= sprintf( '<h2 class="kc-zone-h">%s</h2>', esc_html( $z[0] ) );
		$out .= $full;
		if ( '' !== $mas ) {
			$out .= '<div class="kc-mas">' . $mas . '</div>';
		}
		if ( 'att' === $key && '' !== $engines ) {
			$out    .= '<h2 class="kc-zone-h">Denetim motorları</h2>' . $engines;
			$engines = '';
		}
	}
	if ( '' !== $engines ) {
		$out .= '<h2 class="kc-zone-h">Denetim motorları</h2>' . $engines;
	}
	return $out;
}

add_filter( 'royal_mcp_readable_options', 'daw_kc_readable' );
function daw_kc_readable( $keys ) {
	$keys   = (array) $keys;
	$keys[] = 'daw_kc_report';
	return array_values( array_unique( $keys ) );
}

/* ================================================================== style == */

add_action( 'admin_head', 'daw_kc_styles', 60 );
function daw_kc_styles() {
	if ( ! isset( $_GET['page'] ) || 'daw' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	echo '<style id="daw-kc">' . daw_kc_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static stylesheet.
}

function daw_kc_css() {
	return <<<'CSS'
body.daw-admin .kc{max-width:none}
.kc *{box-sizing:border-box}
.kc-hero{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:26px 30px;border-radius:18px;background:radial-gradient(120% 140% at 100% 0%,#9B2C34 0%,#7E2128 45%,#4E1217 100%);color:#fff;box-shadow:0 10px 30px rgba(78,18,23,.25);position:relative;overflow:hidden}
.kc-hero::after{content:"";position:absolute;right:-60px;bottom:-80px;width:280px;height:280px;border-radius:50%;border:38px solid rgba(201,151,31,.12);pointer-events:none}
body.daw-admin .kc .kc-kicker{margin:0;font-size:13.5px;opacity:.8;letter-spacing:.02em}
body.daw-admin .kc .kc-title{margin:2px 0 6px;color:#fff;font-size:32px;font-weight:750;letter-spacing:-.02em;line-height:1.1}
body.daw-admin .kc .kc-sum{margin:0 0 14px;line-height:1.45;font-size:15.5px;opacity:.92;max-width:52ch}
.kc-counts{display:flex;flex-wrap:wrap;gap:8px}
.kc-counts span{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;background:rgba(255,255,255,.12);font-size:12.5px;font-weight:600}
.kc-counts span::before{content:"";width:8px;height:8px;border-radius:50%}
.kc-counts .is-ok::before{background:#7BD3A2}.kc-counts .is-warn::before{background:#F2C14E}.kc-counts .is-bad::before{background:#FF8A80}
.kc-counts b{font-size:14px}
.kc-hero-score{position:relative;z-index:1;flex:none}
.kc-hero .kc-ring{--ring-bg:rgba(255,255,255,.18)}
.kc-hero .kc-ring b{color:#fff;font-size:40px}
.kc-hero .kc-ringw>small{color:rgba(255,255,255,.85);font-size:12.5px}
.kc-hero .kc-ring.is-ok .kc-ring-fg{stroke:#7BD3A2}.kc-hero .kc-ring.is-warn .kc-ring-fg{stroke:#F2C14E}.kc-hero .kc-ring.is-bad .kc-ring-fg{stroke:#FF8A80}

.kc-ring{--ring-bg:var(--d-band);position:relative;display:inline-flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
.kc-ring svg{position:absolute;inset:0;width:100%;height:100%;transform:rotate(-90deg)}
.kc-ring-bg{fill:none;stroke:var(--ring-bg);stroke-width:3.2}
.kc-ring-fg{fill:none;stroke-width:3.2;stroke-linecap:round;transition:stroke-dasharray .6s}
.kc-ring.is-ok .kc-ring-fg{stroke:var(--d-ok)}.kc-ring.is-warn .kc-ring-fg{stroke:var(--d-gold)}.kc-ring.is-bad .kc-ring-fg{stroke:var(--d-bad)}
.kc-ring b{position:relative;font-size:22px;font-weight:750;line-height:1;color:var(--d-ink)}
.kc-ringw{display:inline-flex;flex-direction:column;align-items:center;gap:6px}
.kc-ringw>small{font-size:11.5px;font-weight:600;color:var(--d-muted)}

.kc-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:16px 0}
.kc-kpi{display:flex;flex-direction:column;gap:2px;padding:14px 16px;background:var(--d-surface);border:1px solid var(--d-line);border-radius:14px;box-shadow:var(--d-shadow);text-decoration:none;color:var(--d-ink);transition:transform .15s,box-shadow .15s}
.kc-kpi:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(28,29,33,.08);color:var(--d-ink)}
.kc-kpi span{font-size:11.5px;font-weight:650;color:var(--d-muted);text-transform:uppercase;letter-spacing:.04em}
.kc-kpi b{font-size:26px;font-weight:750;letter-spacing:-.02em;line-height:1.2}
.kc-kpi small{font-size:12px;color:var(--d-muted)}

.kc-delta{display:inline-block;font-size:11.5px;font-weight:700;padding:1px 6px;border-radius:999px;margin-right:4px}
.kc-delta.is-up{background:var(--d-ok-bg);color:var(--d-ok)}.kc-delta.is-down{background:var(--d-bad-bg);color:var(--d-bad)}.kc-delta.is-flat{background:var(--d-band);color:var(--d-muted)}

.kc-todo{background:var(--d-surface);border:1px solid var(--d-line);border-radius:16px;box-shadow:var(--d-shadow);padding:18px 20px;margin-bottom:16px}
.kc-todo-h{display:flex;align-items:baseline;justify-content:space-between;gap:10px}
.kc-todo-h h2{margin:0!important;font-size:18px!important}
.kc-todo-h span{font-size:12.5px;color:var(--d-muted);font-weight:600}
.kc-todo ol{list-style:none;margin:12px 0 0;padding:0}
.kc-todo li{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:10px;margin:0 0 6px;background:#FDFBF8;border:1px solid var(--d-line)}
.kc-todo li i{flex:none;width:10px;height:10px;border-radius:50%}
.kc-todo li.is-bad{background:var(--d-bad-bg);border-color:#F3C9C4}.kc-todo li.is-bad i{background:var(--d-bad)}
.kc-todo li.is-warn{background:var(--d-warn-bg);border-color:#F0DDB1}.kc-todo li.is-warn i{background:var(--d-gold)}
.kc-todo li.is-info i{background:#9AA3AF}
.kc-todo li span{flex:1 1 auto;font-size:13.5px;line-height:1.45}
.kc-todo li .button{flex:none}
.kc-empty{margin:12px 0 0;padding:14px;border-radius:10px;background:var(--d-ok-bg);color:var(--d-ok);font-weight:600}
.kc-more,.kc-auto{margin:10px 0 0!important;font-size:12.5px!important;color:var(--d-muted)}

.kc-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;grid-auto-flow:row dense}
.kc-top{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);gap:16px;align-items:start;margin-bottom:8px}
.kc-top>.kc-todo{margin-bottom:0}
.kc-over{background:var(--d-surface);border:1px solid var(--d-line);border-radius:16px;box-shadow:var(--d-shadow);padding:18px 20px}
.kc-over .kc-todo-h b{font-weight:750}.kc-over .kc-todo-h b.is-bad{color:var(--d-bad)}.kc-over .kc-todo-h b.is-warn{color:var(--d-gold)}.kc-over .kc-todo-h b.is-ok{color:var(--d-ok)}
.kc-over-note{margin:6px 0 12px;font-size:12.5px;color:var(--d-muted)}
.kc-tiles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.kc-tile{display:grid!important;grid-template-columns:28px minmax(0,1fr) auto;align-items:center;gap:10px;padding:9px 11px;border-radius:11px;border:1px solid var(--d-line);background:#FDFBF8;text-decoration:none;color:var(--d-ink);min-width:0;transition:transform .12s,box-shadow .12s,border-color .12s}
.kc-tile:hover,.kc-tile:focus{transform:translateY(-1px);box-shadow:var(--d-shadow);border-color:#D8CCBC;color:var(--d-ink)}
.kc-tile-i{flex:none;width:28px;height:28px;border-radius:8px;display:grid;place-items:center;background:var(--d-band);font-size:14px}
.kc-tile-t{min-width:0;display:flex;flex-direction:column;line-height:1.3}
.kc-tile-t b{font-size:13px;font-weight:650;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.kc-tile-t small{font-size:11.5px;color:var(--d-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.kc-tile em{justify-self:end;white-space:nowrap;font-style:normal;font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:99px;background:#EEF1F4;color:#4B5563}
.kc-tile.is-bad{background:var(--d-bad-bg);border-color:#F3C9C4}.kc-tile.is-bad em{background:var(--d-bad);color:#fff}
.kc-tile.is-warn{background:var(--d-warn-bg);border-color:#F0DDB1}.kc-tile.is-warn em{background:var(--d-gold);color:#fff}
.kc-tile.is-ok em{background:#E3F2E8;color:var(--d-ok)}
.kc-zone-h{margin:26px 0 12px!important;font-size:15px!important;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--d-muted)!important}
.kc>.kc-card,.kc .kc-card.is-wide{margin-bottom:16px}
.kc-mas{columns:4 340px;column-gap:16px}
.kc-mas .kc-cols{columns:1}
.kc-mas>.kc-card{display:flex;break-inside:avoid;page-break-inside:avoid;margin:0 0 16px;width:100%}
.kc-card{scroll-margin-top:48px}
.kc-card:target{outline:3px solid var(--d-gold);outline-offset:2px}
.kc-card{display:flex;flex-direction:column;background:var(--d-surface);border:1px solid var(--d-line);border-radius:16px;box-shadow:var(--d-shadow);padding:16px 18px;min-width:0}
.kc-card.is-wide{grid-column:1/-1}
.kc-card-h{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.kc-card-h h2{margin:0!important;font-size:15.5px!important;flex:1 1 auto}
.kc-ico{width:32px;height:32px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:var(--d-band);color:var(--d-accent);font-size:16px}
.kc-pill{font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:999px;white-space:nowrap}
.kc-pill.is-ok{background:var(--d-ok-bg);color:var(--d-ok)}.kc-pill.is-warn{background:var(--d-warn-bg);color:var(--d-warn)}.kc-pill.is-bad{background:var(--d-bad-bg);color:var(--d-bad)}.kc-pill.is-info{background:var(--d-band);color:var(--d-muted)}
.kc-card-f{margin-top:auto;padding-top:12px;display:flex;flex-wrap:wrap;gap:6px 16px;border-top:1px solid var(--d-line)}
.kc-card-f a{font-size:13px;font-weight:650;text-decoration:none}
.kc-card h3{margin:12px 0 6px!important;font-size:12px!important;text-transform:uppercase;letter-spacing:.04em;color:var(--d-muted)!important}
.kc-muted{color:var(--d-muted);font-size:12.5px!important;margin:10px 0 12px!important}

.kc-rings{display:flex;justify-content:space-around;gap:8px;margin:2px 0 10px}
.kc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(80px,1fr));gap:8px;margin-bottom:8px}
.kc-stat{background:#FDFBF8;border:1px solid var(--d-line);border-radius:10px;padding:9px 10px}
.kc-stat b{display:block;font-size:20px;font-weight:750;letter-spacing:-.01em;line-height:1.15}
.kc-stat span{display:block;font-size:11.5px;color:var(--d-muted);margin:1px 0 3px}

.kc-mini{width:100%;border-collapse:collapse;font-size:12.5px}
.kc-mini th{font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--d-muted);text-align:left;font-weight:700;padding:4px 6px;border-bottom:1px solid var(--d-line)}
.kc-mini td{padding:6px;border-bottom:1px solid var(--d-line)}
.kc-mini tr:last-child td{border-bottom:0}
.kc-score{display:inline-block;min-width:30px;text-align:center;font-weight:750;padding:2px 6px;border-radius:999px}
.kc-score.is-ok{background:var(--d-ok-bg);color:var(--d-ok)}.kc-score.is-warn{background:var(--d-warn-bg);color:var(--d-warn)}.kc-score.is-bad{background:var(--d-bad-bg);color:var(--d-bad)}
.kc-mini a{text-decoration:none;font-weight:600}
.kc-scroll{overflow-x:auto;max-width:100%}
.kc-speed th,.kc-speed td{white-space:nowrap}
.kc-speed th.kc-grp{text-align:center;border-bottom:1px solid var(--d-line);font-size:11px}
.kc-speed .kc-sep{border-left:2px solid var(--d-line);padding-left:10px}
.kc-mini td.is-ok{color:var(--d-ok);font-weight:650}.kc-mini td.is-warn{color:var(--d-warn);font-weight:650}.kc-mini td.is-bad{color:var(--d-bad);font-weight:650}

.kc-chart{margin:8px 0 2px}
.kc-chart span{display:block;font-size:11px;color:var(--d-muted);font-weight:650;margin-bottom:3px}
.kc-spark{display:block;width:100%;height:48px}
.kc-spark rect{fill:var(--d-accent);opacity:.85}
.kc-chart.is-alt .kc-spark rect{fill:var(--d-gold)}
.kc-top{list-style:none;margin:0;padding:0}
.kc-top li{display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px dashed var(--d-line);font-size:12.5px}
.kc-top li:last-child{border-bottom:0}
.kc-top a{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-decoration:none;font-weight:600}
.kc-top span{flex:none;color:var(--d-muted)}

.kc-split{display:flex;height:10px;border-radius:999px;overflow:hidden;background:var(--d-band);margin:6px 0 10px}
.kc-split i{display:block;height:100%}
.kc-split .is-ok{background:var(--d-ok)}.kc-split .is-warn{background:var(--d-gold)}.kc-split .is-bad{background:var(--d-bad)}.kc-split .is-none{background:var(--d-line-2)}
.kc-reasons{list-style:none;margin:0;padding:0}
.kc-reasons li{display:flex;justify-content:space-between;gap:10px;font-size:12.5px;padding:4px 0}
.kc-reasons b{color:var(--d-warn)}

.kc-checks{list-style:none;margin:0;padding:0}
.kc-checks li{display:flex;align-items:flex-start;gap:9px;padding:6px 0;font-size:13px;line-height:1.4;border-bottom:1px solid var(--d-line)}
.kc-checks li:last-child{border-bottom:0}
.kc-checks i{flex:none;width:18px;height:18px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-style:normal;font-weight:800;margin-top:1px}
.kc-checks .is-ok i{background:var(--d-ok-bg);color:var(--d-ok)}.kc-checks .is-warn i{background:var(--d-warn-bg);color:var(--d-warn)}.kc-checks .is-bad i{background:var(--d-bad-bg);color:var(--d-bad)}.kc-checks .is-info i{background:var(--d-band);color:var(--d-muted)}

.kc-progress{height:10px;border-radius:999px;background:var(--d-band);overflow:hidden;margin:8px 0 6px}
.kc-progress span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--d-accent),#B0464E)}
.kc-progress-l{font-size:12.5px!important;margin:0!important}

.kc-jobs{list-style:none;margin:0;padding:0}
.kc-jobs li{display:flex;align-items:center;gap:9px;padding:5px 0;font-size:12.5px;border-bottom:1px solid var(--d-line)}
.kc-jobs li:last-child{border-bottom:0}
.kc-jobs i{flex:none;width:8px;height:8px;border-radius:50%;background:var(--d-ok)}
.kc-jobs li.is-warn i{background:var(--d-gold)}.kc-jobs li.is-bad i{background:var(--d-bad)}
.kc-jobs span{flex:1 1 auto}
.kc-jobs em{font-style:normal;color:var(--d-muted);white-space:nowrap}

.kc-tools{margin-top:22px}
.kc-crux{margin:10px 0 0!important;font-size:12.5px!important;line-height:2}
.kc-crux .kc-score{margin-right:4px}
.kc-ga-real{margin:8px 0!important;padding:9px 11px;border-radius:10px;background:var(--d-ok-bg);color:var(--d-ok);font-size:12.5px!important}
.kc-ga-real b{font-size:16px}
.kc-run{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;margin-top:16px}
.kc-run span{font-size:12.5px;opacity:.8}
.kc-prog{flex:1 1 100%;max-width:560px;margin-top:4px}.kc-prog-top{display:flex;align-items:baseline;gap:10px;font-size:13px}.kc-prog-pct{font-size:20px}.kc-prog-bar{height:10px;border-radius:999px;background:rgba(255,255,255,.25);overflow:hidden;margin:6px 0}.kc-prog-bar i{display:block;height:100%;background:#fff;border-radius:999px;transition:width .6s ease}.kc-prog-now{font-size:12.5px;opacity:.9}.kc-prog-note{display:block;font-size:11.5px;opacity:.7;margin-top:2px}
body.daw-admin .kc .kc-run-btn{appearance:none;border:0;cursor:pointer;background:#fff;color:#6A1B21;font-weight:700;font-size:13.5px;padding:9px 16px;border-radius:999px;box-shadow:0 4px 14px rgba(0,0,0,.18)}
body.daw-admin .kc .kc-run-btn:hover{background:#FDF3DD}
.kc-fix-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:6px}
.kc-fix-top p{margin:0!important}
.kc-cols{columns:2;column-gap:24px}
.kc-cols li{break-inside:avoid}
.kc-log{list-style:none;margin:0;padding:0;max-height:260px;overflow:auto}
.kc-log li{display:flex;gap:10px;padding:6px 0;border-bottom:1px solid var(--d-line);font-size:12.5px;line-height:1.4}
.kc-log em{flex:none;width:7.5em;font-style:normal;color:var(--d-muted)}
.kc-log span{min-width:0;overflow-wrap:anywhere}
.kc-tools details{background:var(--d-surface);border:1px solid var(--d-line);border-radius:14px;padding:12px 16px;margin-bottom:10px}

@media (max-width:1180px){.kc-top{grid-template-columns:minmax(0,1fr)}.kc-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.kc-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width:782px){.kc-card{overflow-x:auto}.kc-tiles{grid-template-columns:minmax(0,1fr)}.kc-mas{columns:1}.kc-cols{columns:1}.kc-fix-top{flex-wrap:wrap}.kc-grid{grid-template-columns:minmax(0,1fr)}.kc-card.is-wide{grid-column:auto}.kc-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.kc-hero{flex-direction:column-reverse;align-items:flex-start;padding:22px}body.daw-admin .kc .kc-title{font-size:25px}.kc-todo li{flex-wrap:wrap}.kc-todo li span{flex-basis:calc(100% - 30px)}}
CSS;
}
