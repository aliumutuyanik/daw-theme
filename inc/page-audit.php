<?php
/**
 * Discover Asian World — Sayfa Denetimi (per-record on-page detail audit).
 *
 * WHY THIS EXISTS
 * ----------------
 * SEO Envanter (inc/seo-command.php) lists every record in one row each —
 * score, title/description length, Search Console numbers. That is a summary,
 * not a diagnosis: the owner asked specifically for H1/H2 correctness and
 * missing alt text PER PAGE, measured on the real rendered page, not guessed
 * from a field. This file fetches each record's own live, logged-out HTML
 * (the same thing a visitor or Googlebot gets, the same technique
 * inc/speed-audit.php already uses) and parses it directly — no dependency on
 * which Meta Box field happened to hold the text.
 *
 * WHAT IT CHECKS, AND HOW
 * -------------------------
 * Everything here is read from the rendered HTML, not assumed:
 *   - H1: exactly one, and its text
 *   - Heading order: H1 -> H2 -> H3 without skipping a level
 *   - H2 count and text
 *   - Every <img>: has a non-empty alt attribute or not, with the offending
 *     image sources listed (not just a count)
 *   - <title> tag and meta description, read from the actual <head>, so a
 *     broken SEO plugin or caching issue shows up here even if the saved
 *     postmeta looks fine
 *   - Canonical tag and robots meta noindex
 *   - Internal vs external link counts
 *   - Every third-party <script src>, by host — same technique as the Hız &
 *     Sağlık screen's "yavaşlatıcılar" report, applied per record
 *   - JSON-LD blocks: present, and each one valid JSON
 *   - HTTP status and raw page weight
 *
 * SCORE
 * -----
 * 0-100 across six groups (İçerik yapısı 25, Meta 20, URL/Index 15,
 * Bağlantılar 10, Üçüncü taraf betikler 10, Şema 10, Kelime hacmi 10 when a
 * _daw_kw_vol value exists, otherwise that group is left out of the maximum
 * rather than counted as a zero). This mirrors the shape of the scored,
 * grouped single-page audit screen the owner already had built for GBC
 * (gbc-core 1.50.0, inc/sayfa-duzen.php) — same idea, rebuilt for what DAW
 * can actually measure today (no Search Console cannibalization/silo data
 * here yet; those need a bigger follow-up, not guessed numbers).
 *
 * SITE-WIDE SCAN, RULE 8.1
 * --------------------------
 * A one-button batch job (same shape as inc/images-job.php: small bites,
 * option-stored state, cron tick + admin-ajax poll) walks every published
 * record 25 at a time and stores a compact summary in `_daw_page_audit`
 * postmeta. The full per-field breakdown is NOT stored for every record —
 * that would bloat the database for numbers nobody is looking at — it is
 * computed on demand, live, the moment the owner opens one record's detail
 * screen from SEO Envanter's "Detay" link.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_PA_META  = '_daw_page_audit';
const DAW_PA_JOB    = 'daw_pa_job';
const DAW_PA_TICK   = 'daw_pa_job_tick';
const DAW_PA_BITE   = 25; // Rule 8.1: batches, well under the 50-record ceiling.

/* ============================================================= analyze === */

/** Fetch one record's own live, logged-out, cache-bust HTML. */
function daw_pa_http( $post_id ) {
	$url = get_permalink( $post_id );
	if ( ! $url ) {
		return array( 'ok' => false, 'err' => 'permalink yok' );
	}
	$fetch_url = add_query_arg( 'daw_cb', wp_generate_password( 6, false ), $url );

	$res = wp_remote_get(
		$fetch_url,
		array(
			'timeout'    => 20,
			'user-agent' => 'DAW-Page-Audit/1.0 (+https://discoverasianworld.com)',
			'sslverify'  => true,
		)
	);
	if ( is_wp_error( $res ) ) {
		return array( 'ok' => false, 'err' => $res->get_error_message(), 'url' => $url );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = (string) wp_remote_retrieve_body( $res );
	return array(
		'ok'   => 200 === $code && '' !== $body,
		'code' => $code,
		'body' => $body,
		'url'  => $url,
		'bytes' => strlen( $body ),
	);
}

/** Parse one HTML body into every fact this audit cares about. Pure function — no fetching here. */
function daw_pa_parse( $html, $url ) {
	$own_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	// Headings, in document order, so a level jump (H1 -> H3) is detectable.
	preg_match_all( '~<h([1-4])\b[^>]*>(.*?)</h\1>~is', $html, $hm, PREG_SET_ORDER );
	$levels = array();
	$h1 = array();
	$h2 = array();
	foreach ( $hm as $row ) {
		$lvl  = (int) $row[1];
		$text = trim( wp_strip_all_tags( $row[2] ) );
		$levels[] = $lvl;
		if ( 1 === $lvl && '' !== $text ) {
			$h1[] = $text;
		}
		if ( 2 === $lvl && '' !== $text ) {
			$h2[] = $text;
		}
	}
	$skip = 0;
	for ( $i = 1, $n = count( $levels ); $i < $n; $i++ ) {
		if ( $levels[ $i ] - $levels[ $i - 1 ] > 1 ) {
			$skip++;
		}
	}

	// Images: every <img>, and which ones have no non-empty alt.
	preg_match_all( '~<img\b[^>]*>~i', $html, $im );
	$img_total = count( $im[0] );
	$missing_alt = array();
	foreach ( $im[0] as $tag ) {
		// An explicit alt="" marks a decorative image (a platform logo beside its
		// own name, the header mark beside the site name): correct markup, not a
		// missing alt. Only a tag with no alt attribute at all is counted.
		$has_alt = (bool) preg_match( '~\balt\s*=\s*["\'][^"\']*["\']~i', $tag );
		if ( ! $has_alt ) {
			$src = '';
			if ( preg_match( '~\bsrc\s*=\s*["\']([^"\']+)["\']~i', $tag, $sm ) ) {
				$src = $sm[1];
			}
			$missing_alt[] = $src;
		}
	}

	// <head> basics.
	$title_tag = '';
	if ( preg_match( '~<title>(.*?)</title>~is', $html, $tm ) ) {
		$title_tag = trim( wp_strip_all_tags( $tm[1] ) );
	}
	$meta_desc = '';
	if ( preg_match( '~<meta\s+(?:[^>]*?\s)?name=["\']description["\'][^>]*?\scontent=["\'](.*?)["\'][^>]*>~is', $html, $dm )
		|| preg_match( '~<meta\s+(?:[^>]*?\s)?content=["\'](.*?)["\'][^>]*?\sname=["\']description["\'][^>]*>~is', $html, $dm ) ) {
		$meta_desc = html_entity_decode( trim( $dm[1] ), ENT_QUOTES, 'UTF-8' );
	}
	$canonical = '';
	if ( preg_match( '~<link\s+(?:[^>]*?\s)?rel=["\']canonical["\'][^>]*?\shref=["\']([^"\']+)["\']~i', $html, $cm ) ) {
		$canonical = $cm[1];
	}
	$noindex = false;
	if ( preg_match( '~<meta\s+(?:[^>]*?\s)?name=["\']robots["\'][^>]*?\scontent=["\']([^"\']*)["\']~i', $html, $rm ) ) {
		$noindex = false !== stripos( $rm[1], 'noindex' );
	}

	// Links: internal vs external, best-effort (fragment/mailto/tel excluded).
	preg_match_all( '~<a\s[^>]*href=["\']([^"\']+)["\']~i', $html, $am );
	$internal = 0;
	$external = 0;
	foreach ( $am[1] as $href ) {
		if ( '' === $href || '#' === $href[0] || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' ) || 0 === stripos( $href, 'javascript:' ) ) {
			continue;
		}
		$h = (string) wp_parse_url( $href, PHP_URL_HOST );
		if ( '' === $h || $h === $own_host ) {
			$internal++;
		} else {
			$external++;
		}
	}

	// Third-party scripts, same technique as inc/speed-audit.php's site-wide report, per record here.
	preg_match_all( '~<script[^>]+src=["\']([^"\']+)["\']~i', $html, $sm );
	$scripts = array();
	foreach ( $sm[1] as $src ) {
		$h = (string) wp_parse_url( $src, PHP_URL_HOST );
		if ( '' !== $h && $h !== $own_host ) {
			$scripts[] = $h;
		}
	}
	$scripts = array_values( array_unique( $scripts ) );

	// JSON-LD blocks: present, and valid.
	preg_match_all( '~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $jm );
	$schema_total = count( $jm[1] );
	$schema_bad   = 0;
	$schema_types = array();
	foreach ( $jm[1] as $block ) {
		$data = json_decode( trim( $block ), true );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			$schema_bad++;
			continue;
		}
		$nodes = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : array( $data );
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && ! empty( $node['@type'] ) ) {
				$schema_types[] = is_array( $node['@type'] ) ? implode( '/', $node['@type'] ) : (string) $node['@type'];
			}
		}
	}

	// Rough word count: strip all tags, collapse whitespace. Approximate by design (see screen note).
	$text_only = wp_strip_all_tags( preg_replace( '~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $html ) );
	$words     = str_word_count( $text_only );

	return array(
		'url'          => $url,
		'h1'           => $h1,
		'h1_count'     => count( $h1 ),
		'h2'           => $h2,
		'h2_count'     => count( $h2 ),
		'heading_skip' => $skip,
		'img_total'    => $img_total,
		'missing_alt'  => $missing_alt,
		'title_tag'    => $title_tag,
		'meta_desc'    => $meta_desc,
		'canonical'    => $canonical,
		'noindex'      => $noindex,
		'links_internal' => $internal,
		'links_external' => $external,
		'scripts'      => $scripts,
		'schema_total' => $schema_total,
		'schema_bad'   => $schema_bad,
		'schema_types' => array_values( array_unique( $schema_types ) ),
		'words'        => $words,
	);
}

/** Fetch + parse one record. Returns null only on a hard fetch failure (HTTP/network). */
function daw_pa_analyze( $post_id ) {
	$http = daw_pa_http( $post_id );
	if ( empty( $http['ok'] ) ) {
		return array(
			'ok'   => false,
			'http' => (int) ( $http['code'] ?? 0 ),
			'err'  => (string) ( $http['err'] ?? '' ),
			'url'  => $http['url'] ?? get_permalink( $post_id ),
		);
	}
	$parsed = daw_pa_parse( $http['body'], $http['url'] );
	$parsed['ok']    = true;
	$parsed['http']  = $http['code'];
	$parsed['bytes'] = $http['bytes'];
	return $parsed;
}

/* ================================================================ score === */

/** 0-100, grouped. Returns [ 'score', 'max', 'groups' => [ name => [items, score, max] ] ]. */
function daw_pa_score( $post_id, $parsed ) {
	$groups = array();

	if ( empty( $parsed['ok'] ) ) {
		return array(
			'score'  => 0,
			'max'    => 0,
			'failed' => true,
			'groups' => array(),
		);
	}

	$mb_len = static function ( $s ) { return mb_strlen( (string) $s ); };

	// İçerik yapısı — 25.
	$missing = count( $parsed['missing_alt'] );
	$alt_pts = $parsed['img_total'] > 0 ? max( 0, 5 - 5 * ( $missing / max( 1, $parsed['img_total'] ) ) ) : 5;
	$groups['icerik'] = array(
		'ad'  => 'İçerik yapısı',
		'items' => array(
			array( 'ad' => 'Tek H1', 'ok' => 1 === $parsed['h1_count'], 'puan' => 1 === $parsed['h1_count'] ? 10 : 0, 'max' => 10, 'not' => $parsed['h1_count'] . ' H1' ),
			array( 'ad' => 'Başlık sırası atlamıyor (H1→H2→H3)', 'ok' => 0 === $parsed['heading_skip'], 'puan' => 0 === $parsed['heading_skip'] ? 5 : 0, 'max' => 5, 'not' => $parsed['heading_skip'] ? $parsed['heading_skip'] . ' atlama' : '' ),
			array( 'ad' => 'En az 2 H2', 'ok' => $parsed['h2_count'] >= 2, 'puan' => $parsed['h2_count'] >= 2 ? 5 : 0, 'max' => 5, 'not' => $parsed['h2_count'] . ' H2' ),
			array( 'ad' => 'Görsellerde alt metni', 'ok' => 0 === $missing, 'puan' => (int) round( $alt_pts ), 'max' => 5, 'not' => $missing ? $missing . ' / ' . $parsed['img_total'] . ' görselde alt metni yok' : ( $parsed['img_total'] . ' görselin hepsi dolu' ) ),
		),
	);

	// Meta — 20.
	$tl = $mb_len( $parsed['title_tag'] );
	$dl = $mb_len( $parsed['meta_desc'] );
	$groups['meta'] = array(
		'ad' => 'Meta',
		'items' => array(
			array( 'ad' => 'Title etiketi 20–60 karakter', 'ok' => $tl >= 20 && $tl <= 60, 'puan' => ( $tl >= 20 && $tl <= 60 ) ? 10 : 0, 'max' => 10, 'not' => $tl . ' karakter' ),
			array( 'ad' => 'Meta açıklama var ve 70–160 karakter', 'ok' => $dl >= 70 && $dl <= 160, 'puan' => ( $dl >= 70 && $dl <= 160 ) ? 10 : 0, 'max' => 10, 'not' => '' !== $parsed['meta_desc'] ? $dl . ' karakter' : 'açıklama yok' ),
		),
	);

	// URL / Index — 15.
	$groups['url'] = array(
		'ad' => 'URL ve indeksleme',
		'items' => array(
			array( 'ad' => 'HTTP 200', 'ok' => 200 === (int) $parsed['http'], 'puan' => 200 === (int) $parsed['http'] ? 6 : 0, 'max' => 6, 'not' => 'HTTP ' . $parsed['http'] ),
			array( 'ad' => 'noindex yok', 'ok' => ! $parsed['noindex'], 'puan' => ! $parsed['noindex'] ? 5 : 0, 'max' => 5, 'not' => $parsed['noindex'] ? 'noindex basılıyor' : '' ),
			array( 'ad' => 'Canonical kendi adresini gösteriyor', 'ok' => '' !== $parsed['canonical'] && untrailingslashit( $parsed['canonical'] ) === untrailingslashit( $parsed['url'] ), 'puan' => ( '' !== $parsed['canonical'] && untrailingslashit( $parsed['canonical'] ) === untrailingslashit( $parsed['url'] ) ) ? 4 : 0, 'max' => 4, 'not' => '' !== $parsed['canonical'] ? $parsed['canonical'] : 'canonical yok' ),
		),
	);

	// Bağlantılar — 10.
	$groups['baglanti'] = array(
		'ad' => 'Bağlantılar',
		'items' => array(
			array( 'ad' => 'En az 3 iç bağlantı', 'ok' => $parsed['links_internal'] >= 3, 'puan' => $parsed['links_internal'] >= 3 ? 10 : (int) round( 10 * min( 1, $parsed['links_internal'] / 3 ) ), 'max' => 10, 'not' => $parsed['links_internal'] . ' iç, ' . $parsed['links_external'] . ' dış bağlantı' ),
		),
	);

	// Üçüncü taraf betikler — 10. Ads/consent hosts are expected, not penalized by name here;
	// this group only penalizes an excessive, unexplained count.
	$sc = count( $parsed['scripts'] );
	$groups['betik'] = array(
		'ad' => 'Üçüncü taraf betikler',
		'items' => array(
			array( 'ad' => 'Makul sayıda dış betik (≤6)', 'ok' => $sc <= 6, 'puan' => $sc <= 6 ? 10 : max( 0, 10 - 2 * ( $sc - 6 ) ), 'max' => 10, 'not' => $sc . ' farklı dış kaynak: ' . implode( ', ', $parsed['scripts'] ) ),
		),
	);

	// Şema — 10.
	$groups['sema'] = array(
		'ad' => 'Şema',
		'items' => array(
			array( 'ad' => 'En az bir JSON-LD bloğu var', 'ok' => $parsed['schema_total'] > 0, 'puan' => $parsed['schema_total'] > 0 ? 5 : 0, 'max' => 5, 'not' => $parsed['schema_total'] . ' blok' ),
			array( 'ad' => 'Hepsi geçerli JSON', 'ok' => 0 === $parsed['schema_bad'], 'puan' => 0 === $parsed['schema_bad'] ? 5 : 0, 'max' => 5, 'not' => $parsed['schema_bad'] ? $parsed['schema_bad'] . ' bozuk blok' : implode( ', ', $parsed['schema_types'] ) ),
		),
	);

	// Kelime hacmi — 10, only when data exists (function_exists guard: seo-command.php defines it).
	$kw = function_exists( 'daw_kw_vol_get' ) ? daw_kw_vol_get( $post_id ) : null;
	if ( $kw ) {
		$kw_in_title = '' !== $parsed['title_tag'] && false !== mb_stripos( $parsed['title_tag'], $kw['kw'] );
		$groups['kelime'] = array(
			'ad' => 'Kelime hacmi',
			'items' => array(
				array( 'ad' => 'Hedef kalıp başlıkta geçiyor', 'ok' => $kw_in_title, 'puan' => $kw_in_title ? 10 : 0, 'max' => 10, 'not' => $kw['vol'] . '/ay — "' . $kw['kw'] . '"' ),
			),
		);
	}

	$score = 0;
	$max   = 0;
	foreach ( $groups as $k => $g ) {
		foreach ( $g['items'] as $it ) {
			$score += $it['puan'];
			$max   += $it['max'];
		}
	}

	return array(
		'score'  => $max > 0 ? (int) round( $score / $max * 100 ) : 0,
		'raw'    => $score,
		'max'    => $max,
		'failed' => false,
		'groups' => $groups,
	);
}

/* ==================================================== summary (stored) === */

/** The compact, per-record summary written to postmeta by the batch job. */
function daw_pa_summary_get( $post_id ) {
	$raw = get_post_meta( $post_id, DAW_PA_META, true );
	if ( ! $raw ) {
		return null;
	}
	$data = json_decode( (string) $raw, true );
	return is_array( $data ) ? $data : null;
}

function daw_pa_summary_write( $post_id, $parsed, $scored ) {
	$summary = array(
		'score'        => $scored['score'],
		'http'         => (int) ( $parsed['http'] ?? 0 ),
		'h1'           => (int) ( $parsed['h1_count'] ?? 0 ),
		'h2'           => (int) ( $parsed['h2_count'] ?? 0 ),
		'missing_alt'  => isset( $parsed['missing_alt'] ) ? count( $parsed['missing_alt'] ) : 0,
		'img_total'    => (int) ( $parsed['img_total'] ?? 0 ),
		'scripts'      => isset( $parsed['scripts'] ) ? count( $parsed['scripts'] ) : 0,
		'schema_bad'   => (int) ( $parsed['schema_bad'] ?? 0 ),
		'failed'       => ! empty( $scored['failed'] ),
		'at'           => gmdate( 'Y-m-d H:i' ),
	);
	update_post_meta( $post_id, DAW_PA_META, wp_json_encode( $summary ) );
	return $summary;
}

/* =================================================================== job === */

function daw_pa_job_get() {
	$j = get_option( DAW_PA_JOB, array() );
	return is_array( $j ) ? $j : array();
}
function daw_pa_job_put( $j ) {
	update_option( DAW_PA_JOB, $j, false );
}

/** Next bite of un-scanned, published records (oldest scan first: never-scanned, then stalest). */
function daw_pa_queue( $limit ) {
	global $wpdb;
	// Never-scanned records first.
	$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			 WHERE p.post_type = 'post' AND p.post_status = 'publish' AND m.meta_id IS NULL
			 ORDER BY p.post_date DESC LIMIT %d",
			DAW_PA_META,
			$limit
		)
	);
	return array_map( 'intval', $ids );
}

function daw_pa_job_start() {
	daw_pa_job_put(
		array(
			'state'   => 'running',
			'started' => time(),
			'n'       => array( 'done' => 0, 'failed' => 0 ),
		)
	);
	wp_clear_scheduled_hook( DAW_PA_TICK );
	wp_schedule_single_event( time() + 5, DAW_PA_TICK );
}

function daw_pa_job_stop() {
	$j = daw_pa_job_get();
	if ( 'running' === ( $j['state'] ?? '' ) ) {
		$j['state'] = 'paused';
		daw_pa_job_put( $j );
	}
	wp_clear_scheduled_hook( DAW_PA_TICK );
}

/** Start over: clears every stored summary so the next run re-measures everything fresh. */
function daw_pa_job_reset_all() {
	global $wpdb;
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => DAW_PA_META ) ); // phpcs:ignore WordPress.DB
}

add_action( DAW_PA_TICK, 'daw_pa_job_tick' );
function daw_pa_job_tick() {
	$j = daw_pa_job_get();
	if ( 'running' !== ( $j['state'] ?? '' ) ) {
		return $j;
	}
	if ( get_transient( 'daw_pa_job_lock' ) ) {
		return $j;
	}
	set_transient( 'daw_pa_job_lock', 1, 60 );

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore
	}

	try {
		$ids = daw_pa_queue( DAW_PA_BITE );
		if ( ! $ids ) {
			$j['state'] = 'done';
			$j['finished'] = time();
		} else {
			foreach ( $ids as $id ) {
				$parsed = daw_pa_analyze( $id );
				$scored = daw_pa_score( $id, $parsed );
				daw_pa_summary_write( $id, $parsed, $scored );
				if ( empty( $parsed['ok'] ) ) {
					$j['n']['failed']++;
				} else {
					$j['n']['done']++;
				}
			}
		}
	} catch ( \Throwable $e ) {
		$j['state'] = 'paused';
		$j['msg']   = 'Hata: ' . $e->getMessage();
	}

	$j['updated'] = time();
	daw_pa_job_put( $j );
	delete_transient( 'daw_pa_job_lock' );

	if ( 'running' === $j['state'] && ! wp_next_scheduled( DAW_PA_TICK ) ) {
		wp_schedule_single_event( time() + 20, DAW_PA_TICK );
	}
	return $j;
}

add_action( 'wp_ajax_daw_pa_job', 'daw_pa_job_ajax' );
function daw_pa_job_ajax() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'daw_pa_job', 'nonce', false ) ) {
		wp_send_json_error( 'no', 403 );
	}
	$j = daw_pa_job_tick();
	wp_send_json_success(
		array(
			'state' => $j['state'] ?? '',
			'lines' => daw_pa_job_lines( $j ),
		)
	);
}

add_action( 'admin_post_daw_pa_job', 'daw_pa_job_post' );
function daw_pa_job_post() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'daw_pa_job' ) ) {
		wp_die( 'no' );
	}
	$op = sanitize_key( $_POST['op'] ?? '' );
	if ( 'start' === $op ) {
		daw_pa_job_start();
	} elseif ( 'stop' === $op ) {
		daw_pa_job_stop();
	} elseif ( 'reset' === $op ) {
		daw_pa_job_stop();
		daw_pa_job_reset_all();
		daw_pa_job_put( array() );
	}
	wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=daw-seo-command' ) );
	exit;
}

/** Counts straight from the database — rule 8.2. */
function daw_pa_rollup() {
	global $wpdb;
	$total_posts = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'"
	);
	$scanned = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_PA_META )
	);
	return array( 'total' => $total_posts, 'scanned' => $scanned );
}

function daw_pa_job_lines( $j ) {
	$c = daw_pa_rollup();
	if ( empty( $j['state'] ) ) {
		return array( sprintf( '%d / %d kayıt tarandı. Henüz çalıştırılmadı.', $c['scanned'], $c['total'] ) );
	}
	$out = array();
	$out[] = sprintf( '%d / %d kayıt tarandı.', $c['scanned'], $c['total'] );
	if ( 'done' === $j['state'] ) {
		$out[] = 'TAMAMLANDI. Her kayıt için SEO Envanter\'de "Detay" linkiyle tam döküm görülebilir.';
	} elseif ( 'running' === $j['state'] ) {
		$out[] = 'Çalışıyor… (25\'lik partiler halinde, kapatılsa da arka planda sürer)';
	}
	if ( ! empty( $j['msg'] ) ) {
		$out[] = $j['msg'];
	}
	return $out;
}

/** The one-button panel, printed at the top of SEO Envanter. */
function daw_pa_job_panel() {
	$j       = daw_pa_job_get();
	$state   = $j['state'] ?? '';
	$running = 'running' === $state;
	$nonce   = wp_create_nonce( 'daw_pa_job' );

	echo '<div style="max-width:70em;padding:16px 18px;margin:14px 0 22px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #7E2128">';
	echo '<h2 style="margin-top:0">' . esc_html__( 'Tek tuş: bütün siteyi sayfa sayfa tara', 'daw' ) . '</h2>';
	echo '<p>' . esc_html__( 'Her yayınlanmış kaydın kendi canlı sayfasını (çıkış yapmış ziyaretçi gibi) çeker; H1/H2 sırasını, eksik alt metinlerini, başlık ve açıklama uzunluğunu, noindex durumunu, dış betikleri ve şemayı doğrudan sayfadan ölçer. Aşağıdaki tabloda her satırın yanındaki "Detay" o kaydın tam dökümünü açar.', 'daw' ) . '</p>';

	echo '<p>';
	printf( '<form method="post" action="%s" style="display:inline">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'daw_pa_job' );
	echo '<input type="hidden" name="action" value="daw_pa_job"><input type="hidden" name="op" value="start">';
	submit_button( $running ? 'Çalışıyor…' : ( 'done' === $state ? 'Yeniden tara' : 'Taramayı başlat' ), 'primary', 'go', false, $running ? array( 'disabled' => 'disabled' ) : array() );
	echo '</form> ';
	if ( $running ) {
		printf( '<form method="post" action="%s" style="display:inline">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'daw_pa_job' );
		echo '<input type="hidden" name="action" value="daw_pa_job"><input type="hidden" name="op" value="stop">';
		submit_button( 'Durdur', 'secondary', 'go', false );
		echo '</form>';
	}
	echo '</p>';

	echo '<pre id="daw-pa-job" style="white-space:pre-wrap;font-size:13px;line-height:1.6;background:#f6f7f7;padding:12px;margin:0">';
	echo esc_html( implode( "\n", daw_pa_job_lines( $j ) ) );
	echo '</pre>';
	echo '</div>';

	if ( $running ) {
		?>
		<script>
		(function () {
			var box = document.getElementById('daw-pa-job');
			var body = new URLSearchParams({ action: 'daw_pa_job', nonce: <?php echo wp_json_encode( $nonce ); ?> });
			function step() {
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						if (!d || !d.success) { setTimeout(step, 5000); return; }
						box.textContent = d.data.lines.join('\n');
						if (d.data.state === 'running') { setTimeout(step, 1200); } else { location.reload(); }
					})
					.catch(function () { setTimeout(step, 5000); });
			}
			step();
		})();
		</script>
		<?php
	}
}

/* ============================================================== screen === */

/** The full per-record detail screen, rendered from live data computed on the spot. */
function daw_pa_detail_page( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || 'post' !== $post->post_type ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Kayıt bulunamadı.', 'daw' ) . '</p></div>';
		return;
	}

	echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=daw-seo-command' ) ) . '">&larr; ' . esc_html__( 'SEO Envanter listesine dön', 'daw' ) . '</a></p>';
	echo '<h1>' . esc_html( get_the_title( $post_id ) ) . '</h1>';
	printf(
		'<p><a href="%s" target="_blank" rel="noopener">%s ↗</a> · <a href="%s">%s</a></p>',
		esc_url( get_permalink( $post_id ) ),
		esc_html( get_permalink( $post_id ) ),
		esc_url( (string) get_edit_post_link( $post_id ) ),
		esc_html__( 'kaydı düzenle', 'daw' )
	);

	$parsed = daw_pa_analyze( $post_id );
	if ( empty( $parsed['ok'] ) ) {
		printf(
			'<div class="notice notice-error"><p>%s %s</p></div>',
			esc_html__( 'Sayfa canlı olarak çekilemedi:', 'daw' ),
			esc_html( ( $parsed['http'] ?? 0 ) . ' ' . ( $parsed['err'] ?? '' ) )
		);
		return;
	}
	$scored = daw_pa_score( $post_id, $parsed );
	daw_pa_summary_write( $post_id, $parsed, $scored ); // Opening the detail screen also refreshes the stored summary.

	printf( '<p style="font-size:28px;font-weight:600">%d / 100</p>', (int) $scored['score'] );

	foreach ( $scored['groups'] as $g ) {
		$gp = 0;
		$gm = 0;
		foreach ( $g['items'] as $it ) { $gp += $it['puan']; $gm += $it['max']; }
		printf( '<h2>%s — %d / %d</h2><ul style="margin-left:0">', esc_html( $g['ad'] ), $gp, $gm );
		foreach ( $g['items'] as $it ) {
			printf(
				'<li style="color:%s">%s <strong>%s</strong>%s</li>',
				$it['ok'] ? '#1a7f37' : '#c00',
				$it['ok'] ? '✔' : '✘',
				esc_html( $it['ad'] ),
				'' !== $it['not'] ? ' — ' . esc_html( $it['not'] ) : ''
			);
		}
		echo '</ul>';
	}

	echo '<h2>' . esc_html__( 'Ham veri', 'daw' ) . '</h2>';
	if ( $parsed['h1'] ) {
		echo '<p><strong>H1:</strong> ' . esc_html( implode( ' | ', $parsed['h1'] ) ) . '</p>';
	}
	if ( $parsed['h2'] ) {
		echo '<p><strong>H2 (' . count( $parsed['h2'] ) . '):</strong> ' . esc_html( implode( ' · ', $parsed['h2'] ) ) . '</p>';
	}
	if ( $parsed['missing_alt'] ) {
		echo '<p><strong>' . esc_html__( 'Alt metni eksik görseller', 'daw' ) . ' (' . count( $parsed['missing_alt'] ) . '):</strong></p><ul>';
		foreach ( array_slice( $parsed['missing_alt'], 0, 20 ) as $src ) {
			echo '<li style="font-family:monospace;font-size:12px">' . esc_html( $src ) . '</li>';
		}
		echo '</ul>';
	}
	if ( $parsed['scripts'] ) {
		echo '<p><strong>' . esc_html__( 'Dış betik kaynakları', 'daw' ) . ':</strong> ' . esc_html( implode( ', ', $parsed['scripts'] ) ) . '</p>';
	}
	printf(
		'<p style="color:#666">%s</p>',
		esc_html( sprintf( '%d kelime (yaklaşık) · %s KB · HTTP %d', $parsed['words'], round( $parsed['bytes'] / 1024, 1 ), $parsed['http'] ) )
	);
}
