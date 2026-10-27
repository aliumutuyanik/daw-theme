<?php
/**
 * Discover Asian World: why Google has not taken a page in.
 *
 * WHY (7 Oct 2026)
 * ----------------
 * 1,003 of 1,248 inspected pages were outside Google's index, and the control
 * centre could only say how many, in Google's own three words. Those words do
 * not say what to do. This file joins Google's URL Inspection rows with what
 * the site knows about each page and sorts every page left out into one
 * reason a person (or the chat) can act on:
 *
 *   uncrawled Google has never fetched it (unknown, or discovered and queued)
 *   tech      Google could not fetch it, robots blocked it, or it says noindex
 *   canonical Google chose a different address as the original
 *   stale     Google's last visit is older than the page's last change: it
 *             judged an old version (the rebuild of 4 Oct 2026 changed most)
 *   orphan    no other record links to it (no cast row, no filmography)
 *   young     published less than 14 days ago; Google simply has not come yet
 *   thin      the record's own audit still has many empty fields
 *   wait      none of the above: a fair page Google has not reached yet
 *
 * A page lands in the first reason that fits, in that order, so every page is
 * counted once. The result is kept in daw_idx_doc (twice a day with the
 * snapshot, and on {"idx_run": 1} from the chat) and read by the control
 * centre card "Dizin doktoru" and GET /daw/v1/idx.
 *
 * Nothing here changes a page. It tells which pages to work on and why.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_IDX_OPTION = 'daw_idx_doc';

/** Reason keys in the order a page is tested, with the words the owner reads. */
function daw_idx_reasons() {
	return array(
		'uncrawled' => array( 'Google hiç gelmedi', 'Google adresi biliyor ya da hiç duymadı, ama sayfayı hiç açmadı. Sorun sayfada değil, taramanın sırası gelmedi: iç bağlantı ve site haritası hızlandırır.', 'warn' ),
		'tech'      => array( 'Teknik engel', 'Google sayfayı alamadı, robots engelledi ya da noindex gördü. Önce bunlar.', 'bad' ),
		'canonical' => array( 'Kanonik farklı', 'Google başka bir adresi asıl sayfa seçmiş. Kopya içerik ya da yanlış kanonik.', 'bad' ),
		'stale'     => array( 'Eski hâlini görmüş', 'Google en son sayfa değişmeden önce gelmiş; eski, zayıf sürümü değerlendirdi. Yeniden taranınca düzelir.', 'warn' ),
		'orphan'    => array( 'Yetim sayfa', 'Sitede bu sayfaya kadro ya da filmografi bağlantısı yok. Google onu önemsiz sayıyor.', 'warn' ),
		'young'     => array( 'Yeni sayfa', 'Yayına gireli 14 günden az; Google henüz gelmedi.', 'info' ),
		'thin'      => array( 'İçerik eksik', 'Kaydın denetiminde 30\'dan çok boş alan var; sayfa ince görünüyor.', 'warn' ),
		'wait'      => array( 'Sırada', 'Belirgin bir sorun yok; Google\'ın gelmesi bekleniyor. İç bağlantı hızlandırır.', 'info' ),
	);
}

/** Inbound links from other records: titles that list a person, people a title lists. */
function daw_idx_inbound() {
	global $wpdb;
	$in = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", defined( 'DAW_CAST_IDX' ) ? DAW_CAST_IDX : '_daw_cast_idx' ), ARRAY_N ) as $r ) { // phpcs:ignore WordPress.DB
		if ( ! preg_match_all( '/,(\d+):/', (string) $r[1], $m ) ) {
			continue;
		}
		$tid = (int) $r[0];
		foreach ( $m[1] as $pid ) {
			$pid        = (int) $pid;
			$in[ $pid ] = ( $in[ $pid ] ?? 0 ) + 1;           // the title links to the person
			$in[ $tid ] = ( $in[ $tid ] ?? 0 ) + 1;           // the person's filmography links back
		}
	}
	return $in;
}

/** Build the report. Reads the inspection table once and the posts it names. */
function daw_idx_build() {
	if ( ! function_exists( 'daw_gsc_inspect_table' ) || (int) get_option( 'daw_gsc_db', 0 ) < ( defined( 'DAW_GSC_DB_VERSION' ) ? DAW_GSC_DB_VERSION : 1 ) ) {
		return array();
	}
	global $wpdb;

	$rows = (array) $wpdb->get_results( 'SELECT path, verdict, coverage, fetch_state, robots_state, indexing, google_canonical, user_canonical, last_crawl FROM ' . daw_gsc_inspect_table(), ARRAY_A ); // phpcs:ignore WordPress.DB

	// path => post, through the slug (permalinks are /%postname%/).
	$by_slug = array();
	foreach ( (array) $wpdb->get_results( "SELECT ID, post_name, post_date_gmt, post_modified_gmt FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page')", ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB
		$by_slug[ (string) $p['post_name'] ] = $p;
	}
	$fails = array();
	foreach ( (array) $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_daw_audit'", ARRAY_N ) as $a ) { // phpcs:ignore WordPress.DB
		$v = maybe_unserialize( $a[1] );
		if ( is_array( $v ) && isset( $v['sum'] ) ) {
			$fails[ (int) $a[0] ] = (int) ( $v['sum']['eksik'] ?? 0 ) + (int) ( $v['sum']['no'] ?? 0 );
		}
	}
	$in      = daw_idx_inbound();
	$reasons = daw_idx_reasons();
	$count   = array_fill_keys( array_keys( $reasons ), 0 );
	$list    = array_fill_keys( array_keys( $reasons ), array() );
	$cov     = array();
	$types   = array();
	$queue   = array( 'person' => array(), 'title' => array() );
	$total   = 0;
	$pass    = 0;
	$now     = time();

	foreach ( $rows as $r ) {
		++$total;
		if ( 'PASS' === $r['verdict'] ) {
			++$pass;
			continue;
		}
		$path = (string) $r['path'];
		$slug = basename( untrailingslashit( $path ) );
		$post = $by_slug[ $slug ] ?? null;
		$pid  = $post ? (int) $post['ID'] : 0;
		if ( $pid && function_exists( 'daw_thin_is_held' ) && daw_thin_is_held( $pid ) ) {
			--$total; // held at the gate on purpose, not a page Google is refusing.
			continue;
		}
		$type = $pid && function_exists( 'daw_get_type' ) ? (string) daw_get_type( $pid ) : '';
		$type = '' !== $type ? $type : 'other';
		$crawl = $r['last_crawl'] ? strtotime( $r['last_crawl'] . ' UTC' ) : 0;
		$mod   = $post ? strtotime( $post['post_modified_gmt'] . ' UTC' ) : 0;
		$born  = $post ? strtotime( $post['post_date_gmt'] . ' UTC' ) : 0;
		$gc    = untrailingslashit( (string) $r['google_canonical'] );
		$uc    = untrailingslashit( (string) $r['user_canonical'] );

		// Google answers *_UNSPECIFIED for a page it has never fetched; that is
		// not a block (1.75.25 counted 757 such pages as technical faults).
		$fetch  = false !== strpos( (string) $r['fetch_state'], 'UNSPECIFIED' ) ? '' : (string) $r['fetch_state'];
		$robots = false !== strpos( (string) $r['robots_state'], 'UNSPECIFIED' ) ? '' : (string) $r['robots_state'];

		if ( ! $crawl ) {
			$why = 'uncrawled';
		} elseif ( ( '' !== $fetch && 'SUCCESSFUL' !== $fetch ) || 'DISALLOWED' === $robots || false !== stripos( (string) $r['indexing'], 'BLOCKED' ) || false !== stripos( (string) $r['coverage'], 'noindex' ) ) {
			$why = 'tech';
		} elseif ( '' !== $gc && '' !== $uc && $gc !== $uc ) {
			$why = 'canonical';
		} elseif ( $crawl && $mod && $crawl < $mod - HOUR_IN_SECONDS ) {
			$why = 'stale';
		} elseif ( $pid && empty( $in[ $pid ] ) && in_array( $type, array( 'person', 'drama', 'film', 'variety', 'anime' ), true ) ) {
			$why = 'orphan';
		} elseif ( $born && $now - $born < 14 * DAY_IN_SECONDS ) {
			$why = 'young';
		} elseif ( $pid && ( $fails[ $pid ] ?? 0 ) > 30 ) {
			$why = 'thin';
		} else {
			$why = 'wait';
		}

		++$count[ $why ];
		if ( $pid && ( 'uncrawled' === $why || 'stale' === $why ) && in_array( $type, array( 'person', 'drama', 'film', 'variety', 'anime' ), true ) ) {
			$queue[ 'person' === $type ? 'person' : 'title' ][] = array( $pid, 'stale' === $why ? 1 : 0, (int) ( $in[ $pid ] ?? 0 ) );
		}
		$c           = (string) $r['coverage'];
		$cov[ $c ]   = ( $cov[ $c ] ?? 0 ) + 1;
		$types[ $type ][ $why ] = ( $types[ $type ][ $why ] ?? 0 ) + 1;
		if ( count( $list[ $why ] ) < 40 ) {
			$list[ $why ][] = array(
				'id'    => $pid,
				'path'  => $path,
				'type'  => $type,
				'cov'   => $c,
				'in'    => (int) ( $in[ $pid ] ?? 0 ),
				'empty' => (int) ( $fails[ $pid ] ?? 0 ),
				'crawl' => $crawl ? gmdate( 'Y-m-d', $crawl ) : '',
				'mod'   => $mod ? gmdate( 'Y-m-d', $mod ) : '',
				'gcan'  => 'canonical' === $why ? (string) $r['google_canonical'] : '',
			);
		}
	}

	// Orphans and stale pages the inbound count favours first: most links, least empty.
	foreach ( $list as $k => $rows_k ) {
		usort( $rows_k, static fn( $a, $b ) => ( $b['in'] <=> $a['in'] ) ?: ( $a['empty'] <=> $b['empty'] ) );
		$list[ $k ] = $rows_k;
	}
	arsort( $cov );

	// The home-page rail reads this: stale pages first (Google knows them and
	// only needs to come back), then the most linked-to.
	foreach ( $queue as $k => $q ) {
		usort( $q, static fn( $a, $b ) => ( $b[1] <=> $a[1] ) ?: ( $b[2] <=> $a[2] ) );
		$queue[ $k ] = array_map( 'intval', array_column( array_slice( $q, 0, 600 ), 0 ) );
	}

	$doc = array(
		'at'       => $now,
		'total'    => $total,
		'indexed'  => $pass,
		'count'    => $count,
		'coverage' => $cov,
		'types'    => $types,
		'rows'     => $list,
		'queue'    => $queue,
	);
	update_option( DAW_IDX_OPTION, $doc, false );
	return $doc;
}

add_action( 'daw_snapshot_cron', 'daw_idx_build', 60 );
add_action( 'daw_kc_run_all_late', 'daw_idx_build', 60 );

/**
 * Pages Google has not fetched (or not re-fetched since they changed), a
 * different handful each day, for the home-page rail. The home page is the
 * page Google opens most; a link from it is the cheapest crawl invitation.
 *
 * @return array{person:int[],title:int[]}
 */
function daw_idx_rotation( $n = 6 ) {
	$q   = (array) ( daw_idx_doc()['queue'] ?? array() );
	$day = (int) floor( time() / DAY_IN_SECONDS );
	$out = array( 'person' => array(), 'title' => array() );
	foreach ( $out as $k => $unused ) {
		// Never a page held at the thin gate: the home page's links go to pages
		// that can enter the index (1.75.48).
		$ids = array_values( array_filter( (array) ( $q[ $k ] ?? array() ), static fn( $id ) => 'publish' === get_post_status( $id ) && ! ( function_exists( 'daw_thin_is_held' ) && daw_thin_is_held( $id ) ) ) );
		$fresh = daw_idx_fresh_opened( $k );
		if ( ! $ids && ! $fresh ) {
			continue;
		}
		$out[ $k ] = daw_idx_rotation_pick( $ids, $n, $day, $fresh );
	}
	return $out;
}

/**
 * Records of one rail kind that left the thin gate in the last 7 days, newest
 * first. Read from the posts, not from the queue: a held page's inspection
 * row is dropped (index-gate.php), so a page that just opened is often not in
 * the queue yet, and it is exactly the one that needs the link.
 */
function daw_idx_fresh_opened( $kind ) {
	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_daw_opened', // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_daw_opened',
					'value'   => time() - 7 * DAY_IN_SECONDS,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			),
		)
	);
	$want = 'person' === $kind ? array( 'person' ) : array( 'drama', 'film', 'variety' );
	return array_values(
		array_filter(
			array_map( 'intval', (array) $ids ),
			static fn( $id ) => function_exists( 'daw_get_type' ) && in_array( daw_get_type( $id ), $want, true ) && ! ( function_exists( 'daw_thin_is_held' ) && daw_thin_is_held( $id ) )
		)
	);
}

/*
 * WHO GETS THE HOME PAGE'S LINKS (1.75.77)
 * The rail used to walk the queue in id order, six a day: with some 800
 * pages waiting, a page was linked from the home page about once in four
 * months, whether it was just finished or nobody searches for it. Now:
 *   1. up to half the slots go to pages that left the thin gate in the last
 *      7 days, newest first: a page is linked the day it is finished;
 *   2. the rest walk the queue sorted by search demand (top _daw_kw volume,
 *      index-boost.php), a different window each day, so the most searched
 *      pages come round first and most often.
 */
function daw_idx_rotation_pick( array $ids, $n, $day, array $fresh = array() ) {
	update_meta_cache( 'post', $ids );
	$first = array_slice( array_map( 'intval', $fresh ), 0, (int) ceil( $n / 2 ) );

	$rest = array_values( array_diff( $ids, $first ) );
	if ( function_exists( 'daw_ib_top_volume' ) ) {
		$vol = array();
		foreach ( $rest as $id ) {
			$vol[ $id ] = daw_ib_top_volume( $id );
		}
		usort( $rest, static fn( $a, $b ) => ( $vol[ $b ] <=> $vol[ $a ] ) ?: ( $a <=> $b ) );
	}
	$need = $n - count( $first );
	$c    = count( $rest );
	if ( $need > 0 && $c ) {
		$off  = ( $day * $need ) % $c;
		$rest = array_slice( array_merge( array_slice( $rest, $off ), array_slice( $rest, 0, $off ) ), 0, $need );
	} else {
		$rest = array();
	}
	$out = array_merge( $first, $rest );
	// Slots the queue could not fill go to the next fresh pages.
	foreach ( array_slice( array_map( 'intval', $fresh ), count( $first ) ) as $id ) {
		if ( count( $out ) >= $n ) {
			break;
		}
		$out[] = $id;
	}
	return array_map( 'intval', array_values( array_unique( $out ) ) );
}

/** A new day means a new rail: let the cached home page go once. */
function daw_idx_rotation_purge() {
	$day = (int) floor( time() / DAY_IN_SECONDS );
	// A page left the thin gate since the last tick (index-gate.php): the
	// home page goes once so its rail links the page now, not tomorrow.
	if ( (int) get_option( 'daw_idx_rot_day', 0 ) === $day && ! get_option( 'daw_home_dirty' ) ) {
		return;
	}
	update_option( 'daw_idx_rot_day', $day, false );
	delete_option( 'daw_home_dirty' );
	$front = (int) get_option( 'page_on_front' );
	if ( $front ) {
		do_action( 'litespeed_purge_post', $front );
	}
	do_action( 'litespeed_purge_url', home_url( '/' ) );
}
add_action( 'daw_fix_tick', 'daw_idx_rotation_purge', 70 );

function daw_idx_doc() {
	$d = get_option( DAW_IDX_OPTION );
	return is_array( $d ) ? $d : array();
}

/** For the control centre: the card. */
function daw_idx_card( $kc ) {
	$doc = daw_idx_doc();
	$cnt = (array) ( $doc['count'] ?? array() );
	$bad = (int) ( $cnt['tech'] ?? 0 ) + (int) ( $cnt['canonical'] ?? 0 );
	$st  = ! $doc ? 'info' : ( $bad ? 'bad' : ( ( (int) ( $cnt['stale'] ?? 0 ) + (int) ( $cnt['orphan'] ?? 0 ) + (int) ( $cnt['thin'] ?? 0 ) ) ? 'warn' : 'ok' ) );
	daw_kc_card_open( 'idx', '⌕', 'Dizin doktoru: neden girmiyor', $st, true );
	if ( ! $doc ) {
		echo '<ul class="kc-checks">';
		daw_kc_check( true, 'İlk inceleme arşiv özetiyle birlikte (günde iki kez) ya da "Tüm kontrolleri çalıştır" ile yapılır.', 'info' );
		echo '</ul>';
		daw_kc_card_close();
		return;
	}
	printf( '<p class="kc-muted">%s sayfadan %s tanesi dizinde. Dışarıda kalanların nedeni, ilk uyan nedene göre bir kez sayıldı. İnceleme %s.</p>', esc_html( daw_kc_n( $doc['total'] ) ), esc_html( daw_kc_n( $doc['indexed'] ) ), esc_html( daw_kc_ago( (int) $doc['at'] ) ) );
	echo '<div class="kc-scroll"><table class="kc-mini"><thead><tr><th>Neden</th><th>Sayfa</th><th>Ne demek, ne yapılır</th><th>Örnekler</th></tr></thead><tbody>';
	foreach ( daw_idx_reasons() as $k => $r ) {
		$n = (int) ( $cnt[ $k ] ?? 0 );
		if ( ! $n ) {
			continue;
		}
		$ex = array();
		foreach ( array_slice( (array) ( $doc['rows'][ $k ] ?? array() ), 0, 3 ) as $row ) {
			$ex[] = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( home_url( $row['path'] ) ), esc_html( trim( $row['path'], '/' ) ) );
		}
		printf( '<tr class="is-%s"><td><b>%s</b></td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_attr( $r[2] ), esc_html( $r[0] ), esc_html( daw_kc_n( $n ) ), esc_html( $r[1] ), implode( ', ', $ex ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}
	echo '</tbody></table></div>';
	$hist = (array) get_option( 'daw_ib_hist', array() );
	if ( count( $hist ) > 1 ) {
		$vals = array_values( $hist );
		printf( '<p class="kc-muted">Dizindeki sayfa, son %d gün: %s.</p>', count( $hist ), esc_html( implode( ' → ', array_map( 'daw_kc_n', $vals ) ) ) );
	}
	daw_kc_card_close( array( 'Google dizini' => daw_kc_seo_url( 'dizin' ) ) );
}

/** For /daw/v1/kc-brief: counts only. */
function daw_idx_brief() {
	$doc = daw_idx_doc();
	return $doc ? array( 'at' => gmdate( 'm-d H:i', (int) $doc['at'] ), 'indexed' => (int) $doc['indexed'], 'total' => (int) $doc['total'], 'why' => array_filter( (array) $doc['count'] ) ) : array();
}
