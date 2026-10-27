<?php
/**
 * Discover Asian World: the full PageSpeed report, kept where it can be read.
 *
 * speed-audit.php keeps a score. A score says the page is slow; it does not
 * say why. This keeps, for the three pages that matter most on a phone (the
 * home page, a drama and the drama list), the six lab numbers, what the
 * Largest Contentful Paint element was, when the page looked finished, and
 * every Lighthouse check that did not pass with its worst offenders. The
 * owner reads the result in DAW › Hız & Sağlık; the chat reads the same option
 * through Royal MCP, so a fix can be aimed at the actual cause.
 *
 * One page per run, each in its own background event a minute and a half
 * apart: a mobile report takes 15 to 40 seconds, and the host stops any
 * request at 60.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_PSI_DETAIL = 'daw_psi_detail';
// The same report for a computer screen (strategy=desktop), kept apart so
// every reader of the phone report stays as it was.
const DAW_PSI_DETAIL_D = 'daw_psi_detail_d';

/**
 * The pages measured: one of every kind a reader lands on (owner, 4 Oct
 * 2026: "does it not look at the other pages?"). The newest record of each
 * type, the drama list, the Korean drama section and the home page. Picked
 * fresh every run, so a sample is never a page that has since gone.
 */
function daw_psi_target_labels() {
	return array(
		'drama'   => 'Dizi',
		'film'    => 'Film',
		'variety' => 'Şov programı',
		'person'  => 'Oyuncu',
		'group'   => 'Müzik grubu',
		'news'    => 'Haber',
		'food'    => 'Yemek rehberi',
		'culture' => 'Kültür rehberi',
		'list'    => 'Dizi listesi',
		'section' => 'Ülke bölümü',
		'home'    => 'Ana sayfa',
	);
}

function daw_psi_targets() {
	$urls = array();

	foreach ( array( 'drama', 'film', 'variety', 'person', 'group', 'news', 'food', 'culture' ) as $type ) {
		$ids = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'orderby'                => 'modified',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'tax_query'              => array( array( 'taxonomy' => 'dw_type', 'field' => 'slug', 'terms' => $type ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $ids ) {
			$urls[ $type ] = (string) get_permalink( (int) $ids[0] );
		}
	}

	$list = function_exists( 'daw_browse_page_url_for_type' ) ? daw_browse_page_url_for_type( 'drama' ) : '';
	if ( ! $list ) {
		$page = get_page_by_path( 'dramas' );
		$list = ( $page && 'publish' === $page->post_status ) ? get_permalink( $page ) : '';
	}
	if ( $list ) {
		$urls['list'] = (string) $list;
	}

	if ( function_exists( 'daw_browse_country_page' ) ) {
		$section = daw_browse_country_page( 'south-korea', 'drama' );
		if ( $section ) {
			$urls['section'] = (string) $section;
		}
	}

	$urls['home'] = home_url( '/' );

	return $urls;
}

/** Queue one run per page, ninety seconds apart. */
function daw_psi_queue_all( $delay = 30 ) {
	$t = time() + (int) $delay;

	// The home page last: measured first, straight after a cache purge, it
	// twice came back with every request ending six to seven seconds in and
	// a Speed Index to match, while the same page measured later was fine.
	$targets = daw_psi_targets();
	if ( isset( $targets['home'] ) ) {
		$home = $targets['home'];
		unset( $targets['home'] );
		$targets['home'] = $home;
	}

	foreach ( $targets as $label => $url ) {
		wp_schedule_single_event( $t, 'daw_psi_detail_one', array( $label, $url ) );
		$t += 90;
	}
	// Then each page again as a computer sees it.
	foreach ( $targets as $label => $url ) {
		wp_schedule_single_event( $t, 'daw_psi_detail_one', array( $label, $url, 'desktop' ) );
		$t += 90;
	}
}

/** After every new version, and once a day. */
add_action( 'admin_init', 'daw_psi_on_version' );
function daw_psi_on_version() {
	if ( ! current_user_can( 'manage_options' ) || get_option( 'daw_psi_ver' ) === DAW_VERSION ) {
		return;
	}

	update_option( 'daw_psi_ver', DAW_VERSION, false );

	// A new version changes the head of every page (stylesheet, hints), and
	// LiteSpeed would keep serving the old html until each page expired: the
	// first measurement after 1.53.1 still saw 1.53.0's pages. Purge first.
	do_action( 'litespeed_purge_all' );

	// Readers should never be the ones who rebuild the cache: the three pages
	// are fetched once (phone and desktop) right after the purge, and the
	// measurement starts once they are warm.
	wp_schedule_single_event( time() + 10, 'daw_psi_warm' );

	daw_psi_queue_all( 120 );
}

add_action( 'daw_psi_warm', 'daw_psi_warm_run' );
function daw_psi_warm_run() {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	// The phone copy is the one PageSpeed and most readers ask for.
	$ua = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';
	foreach ( daw_psi_targets() as $url ) {
		wp_remote_get( $url, array( 'timeout' => 8, 'user-agent' => $ua ) );
	}
}

add_action( 'init', 'daw_psi_schedule_daily' );
function daw_psi_schedule_daily() {
	if ( ! wp_next_scheduled( 'daw_psi_daily' ) ) {
		wp_schedule_event( time() + 6 * HOUR_IN_SECONDS, 'daily', 'daw_psi_daily' );
	}
}
add_action( 'daw_psi_daily', 'daw_psi_queue_all' );

/** Audits that only describe and never pass or fail. */
function daw_psi_skip_audit( $audit ) {
	$mode = isset( $audit['scoreDisplayMode'] ) ? (string) $audit['scoreDisplayMode'] : '';

	return in_array( $mode, array( 'notApplicable', 'manual', 'error' ), true );
}

/** Up to six rows of an audit's table, shortened to what identifies the problem. */
function daw_psi_items( $audit ) {
	$items = isset( $audit['details']['items'] ) && is_array( $audit['details']['items'] ) ? $audit['details']['items'] : array();
	// Insights carry a list of tables (details.type "list"): read the rows of
	// each table, so a DOM-size or layout-shift insight is not an empty line.
	if ( isset( $audit['details']['type'] ) && 'list' === $audit['details']['type'] ) {
		$flat = array();
		foreach ( $items as $sub ) {
			foreach ( (array) ( $sub['items'] ?? array() ) as $row ) {
				$flat[] = $row;
			}
		}
		$items = $flat;
	}
	$out   = array();

	foreach ( array_slice( $items, 0, 6 ) as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$row = array();
		foreach ( array( 'url', 'wastedMs', 'wastedBytes', 'totalBytes', 'transferSize', 'blockingTime', 'mainThreadTime', 'entity', 'label', 'phase', 'timing', 'percent', 'groupLabel', 'duration', 'statistic', 'value', 'score', 'startTime', 'reflowTime', 'source' ) as $k ) {
			if ( isset( $item[ $k ] ) && ! is_array( $item[ $k ] ) ) {
				$row[ $k ] = is_float( $item[ $k ] ) ? round( $item[ $k ] ) : $item[ $k ];
			}
		}
		if ( isset( $item['entity'] ) && is_array( $item['entity'] ) && isset( $item['entity']['text'] ) ) {
			$row['entity'] = (string) $item['entity']['text'];
		}
		if ( isset( $item['value'] ) && is_array( $item['value'] ) && isset( $item['value']['value'] ) ) {
			$row['value'] = $item['value']['value'];
		}
		if ( isset( $item['element']['snippet'] ) ) {
			$row['node'] = substr( (string) $item['element']['snippet'], 0, 200 );
		}
		if ( isset( $item['node']['snippet'] ) ) {
			$row['node'] = substr( (string) $item['node']['snippet'], 0, 200 );
		}
		if ( isset( $item['node']['selector'] ) ) {
			$row['selector'] = substr( (string) $item['node']['selector'], 0, 120 );
		}
		if ( isset( $item['items'] ) && is_array( $item['items'] ) ) {
			// Nested tables (the LCP element audit): first nested row only.
			$first = reset( $item['items'] );
			if ( is_array( $first ) && isset( $first['node']['snippet'] ) ) {
				$row['node'] = substr( (string) $first['node']['snippet'], 0, 200 );
			}
		}
		if ( $row ) {
			$out[] = $row;
		}
	}

	return $out;
}

add_action( 'daw_psi_detail_one', 'daw_psi_detail_run', 10, 3 );
function daw_psi_detail_run( $label, $url, $strategy = 'mobile' ) {
	$strategy = 'desktop' === $strategy ? 'desktop' : 'mobile';
	$store    = 'desktop' === $strategy ? DAW_PSI_DETAIL_D : DAW_PSI_DETAIL;
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	$key  = defined( 'DAW_SPEED_KEY_PSI' ) ? trim( (string) get_option( DAW_SPEED_KEY_PSI, '' ) ) : '';
	$args = array(
		'url'      => $url,
		'strategy' => $strategy,
		'category' => 'performance',
	);
	if ( '' !== $key ) {
		$args['key'] = $key;
	}

	$api = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query( $args );
	$res = wp_remote_get( $api, array( 'timeout' => 55 ) );

	// Without a key Google shares one small quota between everybody and often
	// answers 429. The Search Console service account's project can carry the
	// request instead, if the PageSpeed Insights API is enabled there.
	if ( '' === $key && ! is_wp_error( $res ) && 429 === (int) wp_remote_retrieve_response_code( $res ) && function_exists( 'daw_gsc_token' ) ) {
		$token = daw_gsc_token();
		if ( is_string( $token ) ) {
			$res = wp_remote_get( $api, array( 'timeout' => 55, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
		}
	}

	$all = (array) get_option( $store, array() );

	if ( is_wp_error( $res ) ) {
		$all[ $label ] = array( 'url' => $url, 'at' => time(), 'error' => $res->get_error_message() );
		daw_psi_store( $store, $all, $label );
		return;
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );

	if ( empty( $body['lighthouseResult'] ) ) {
		$all[ $label ] = array(
			'url'   => $url,
			'at'    => time(),
			'error' => isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'HTTP ' . wp_remote_retrieve_response_code( $res ),
		);
		daw_psi_store( $store, $all, $label );
		return;
	}

	// WHAT REAL CHROME USERS GOT (CrUX), 28 days, when Google has enough of
	// them: the numbers Google actually ranks on, as opposed to the lab run.
	foreach ( array( 'origin' => 'originLoadingExperience', 'page_' . $label => 'loadingExperience' ) as $ck => $src ) {
		if ( 'mobile' === $strategy && ! empty( $body[ $src ]['metrics'] ) && 'origin' === $ck ) {
			$crux = array( 'at' => time(), 'overall' => (string) ( $body[ $src ]['overall_category'] ?? '' ), 'm' => array() );
			foreach ( (array) $body[ $src ]['metrics'] as $mk => $mv ) {
				$crux['m'][ $mk ] = array( (float) ( $mv['percentile'] ?? 0 ), (string) ( $mv['category'] ?? '' ) );
			}
			update_option( 'daw_crux', $crux, false );
		}
	}

	$lh     = $body['lighthouseResult'];
	$audits = (array) ( $lh['audits'] ?? array() );

	$metrics = array();
	foreach ( array( 'first-contentful-paint', 'largest-contentful-paint', 'speed-index', 'total-blocking-time', 'cumulative-layout-shift', 'interactive', 'server-response-time' ) as $m ) {
		if ( isset( $audits[ $m ]['numericValue'] ) ) {
			$metrics[ $m ] = round( (float) $audits[ $m ]['numericValue'], 'cumulative-layout-shift' === $m ? 3 : 0 );
		}
	}

	// When the page looked finished: the filmstrip's frame times.
	$frames = array();
	foreach ( (array) ( $audits['screenshot-thumbnails']['details']['items'] ?? array() ) as $f ) {
		if ( isset( $f['timing'] ) ) {
			$frames[] = (int) $f['timing'];
		}
	}

	$failing = array();
	foreach ( $audits as $id => $audit ) {
		if ( daw_psi_skip_audit( $audit ) || in_array( $id, array( 'screenshot-thumbnails', 'final-screenshot', 'full-page-screenshot', 'main-thread-tasks', 'network-requests', 'network-rtt', 'network-server-latency', 'script-treemap-data', 'resource-summary' ), true ) ) {
			continue;
		}

		$score = isset( $audit['score'] ) ? $audit['score'] : null;
		$keep  = ( null !== $score && $score < 0.9 ) || in_array( $id, array( 'largest-contentful-paint-element', 'lcp-breakdown-insight', 'lcp-lazy-loaded', 'prioritize-lcp-image', 'third-party-summary', 'third-parties-insight', 'bootup-time', 'mainthread-work-breakdown', 'total-byte-weight', 'font-display', 'font-display-insight', 'render-blocking-resources', 'render-blocking-insight', 'dom-size', 'dom-size-insight', 'layout-shifts', 'cls-culprits-insight', 'forced-reflow-insight', 'long-tasks', 'interaction-to-next-paint-insight' ), true );

		if ( ! $keep ) {
			continue;
		}

		$failing[ $id ] = array_filter(
			array(
				'title'   => (string) ( $audit['title'] ?? $id ),
				'score'   => $score,
				'display' => (string) ( $audit['displayValue'] ?? '' ),
				'items'   => daw_psi_items( $audit ),
			),
			static function ( $v ) {
				return null !== $v && '' !== $v && array() !== $v;
			}
		);
	}

	// The heaviest requests, for "what is the page actually made of".
	$requests = array();
	foreach ( (array) ( $audits['network-requests']['details']['items'] ?? array() ) as $r ) {
		$requests[] = array(
			'url'  => substr( (string) ( $r['url'] ?? '' ), 0, 160 ),
			'kb'   => isset( $r['transferSize'] ) ? round( $r['transferSize'] / 1024, 1 ) : 0,
			'type' => (string) ( $r['resourceType'] ?? '' ),
			'end'  => isset( $r['networkEndTime'] ) ? (int) $r['networkEndTime'] : ( isset( $r['endTime'] ) ? (int) $r['endTime'] : 0 ),
			'prio' => (string) ( $r['priority'] ?? '' ),
		);
	}
	usort(
		$requests,
		static function ( $a, $b ) {
			return $b['kb'] <=> $a['kb'];
		}
	);

	$all[ $label ] = array(
		'url'      => $url,
		'at'       => time(),
		'score'    => isset( $lh['categories']['performance']['score'] ) ? (int) round( $lh['categories']['performance']['score'] * 100 ) : null,
		'lh'       => (string) ( $lh['lighthouseVersion'] ?? '' ),
		'metrics'  => $metrics,
		'frames'   => $frames,
		'requests' => array_slice( $requests, 0, 25 ),
		'count'    => count( (array) ( $audits['network-requests']['details']['items'] ?? array() ) ),
		'audits'   => $failing,
	);

	daw_psi_slow_log( $label, $url, $strategy, $all[ $label ] );
	daw_psi_store( $store, $all, $label );
}

/**
 * Save the measurements. A re-measurement of a page from the slow list
 * (label "slow:...") feeds only that list, never the page-type averages.
 */
function daw_psi_store( $store, array $all, $label ) {
	if ( 0 === strpos( (string) $label, 'slow:' ) ) {
		return;
	}
	update_option( $store, $all, false );
}

/* =================================================== slow pages list ==
 * 1.75.67, owner 13 Oct 2026: "hep farklı sayfalarda düşük skor çıkıyorsa o
 * yavaşlatan sayfaları bir kenara yazıp hızlı hale getirmek için optimize
 * etmek lazım". The daily check measures one page per type (the latest
 * edited), so a slow page shows up once and is gone the next day. Every page
 * that scores under 90 is now written down with what made it slow, measured
 * again every day, and only leaves the list after two good measurements in a
 * row. The list is option daw_psi_slow (readable from the chat).
 */
const DAW_PSI_SLOW = 'daw_psi_slow';

function daw_psi_slow_log( $label, $url, $strategy, array $row ) {
	if ( ! isset( $row['score'] ) || null === $row['score'] ) {
		return;
	}
	$list  = (array) get_option( DAW_PSI_SLOW, array() );
	$key   = md5( $url . '|' . $strategy );
	$score = (int) $row['score'];
	$m     = (array) ( $row['metrics'] ?? array() );

	if ( $score < 90 ) {
		$why = array();
		foreach ( (array) ( $row['audits'] ?? array() ) as $a ) {
			if ( isset( $a['score'] ) && null !== $a['score'] && $a['score'] < 0.5 ) {
				$why[] = trim( $a['title'] . ( ! empty( $a['display'] ) ? ' (' . $a['display'] . ')' : '' ) );
			}
		}
		$e = isset( $list[ $key ] ) && is_array( $list[ $key ] ) ? $list[ $key ] : array(
			'url'      => $url,
			'type'     => preg_replace( '/^slow:/', '', (string) $label ),
			'strategy' => $strategy,
			'first'    => time(),
			'count'    => 0,
		);
		$e['score']  = $score;
		$e['at']     = time();
		$e['count']  = (int) $e['count'] + 1;
		$e['good']   = 0;
		$e['fixed']  = 0;
		$e['lcp']    = (int) ( $m['largest-contentful-paint'] ?? 0 );
		$e['tbt']    = (int) ( $m['total-blocking-time'] ?? 0 );
		$e['si']     = (int) ( $m['speed-index'] ?? 0 );
		$e['cls']    = (float) ( $m['cumulative-layout-shift'] ?? 0 );
		$e['why']    = array_slice( $why, 0, 5 );
		$list[ $key ] = $e;
	} elseif ( isset( $list[ $key ] ) && is_array( $list[ $key ] ) && empty( $list[ $key ]['fixed'] ) ) {
		$list[ $key ]['good']  = (int) ( $list[ $key ]['good'] ?? 0 ) + 1;
		$list[ $key ]['score'] = $score;
		$list[ $key ]['at']    = time();
		if ( $list[ $key ]['good'] >= 2 ) {
			$list[ $key ]['fixed'] = time();
		}
	}

	// Fixed pages stay visible for a week, then drop off; at most 60 rows.
	foreach ( $list as $k => $e ) {
		if ( ! empty( $e['fixed'] ) && (int) $e['fixed'] < time() - WEEK_IN_SECONDS ) {
			unset( $list[ $k ] );
		}
	}
	uasort( $list, static fn( $a, $b ) => ( ! empty( $a['fixed'] ) <=> ! empty( $b['fixed'] ) ) ?: ( (int) $a['score'] <=> (int) $b['score'] ) );
	update_option( DAW_PSI_SLOW, array_slice( $list, 0, 60, true ), false );
}

/** The open slow pages, worst first. */
function daw_psi_slow_open() {
	return array_filter( (array) get_option( DAW_PSI_SLOW, array() ), static fn( $e ) => is_array( $e ) && empty( $e['fixed'] ) );
}

/* Every day, each open slow page is measured again (at most 10, 90 s apart). */
add_action( 'daw_psi_daily', 'daw_psi_slow_recheck', 20 );
function daw_psi_slow_recheck() {
	$t = time() + 23 * 90 + 120; // after the daily round of page types
	foreach ( array_slice( daw_psi_slow_open(), 0, 10, true ) as $e ) {
		wp_schedule_single_event( $t, 'daw_psi_detail_one', array( 'slow:' . $e['type'], $e['url'], $e['strategy'] ) );
		$t += 90;
	}
}

/** The report can be read from the chat through Royal MCP. Nothing in it is private. */
add_filter( 'royal_mcp_readable_options', 'daw_psi_readable' );
function daw_psi_readable( $options ) {
	$options   = (array) $options;
	$options[] = DAW_PSI_DETAIL;
	$options[] = DAW_PSI_DETAIL_D;
	$options[] = 'daw_crux';

	return $options;
}
