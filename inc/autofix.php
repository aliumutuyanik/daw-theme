<?php
/**
 * Discover Asian World: the repairs the site makes by itself.
 *
 * The owner's rule (4 Oct 2026): when a check finds a fault the site can fix
 * on its own, it fixes it, and the control centre only lists what truly needs
 * a person. Everything done here is logged (daw_fix_log) and every text that
 * is changed keeps its old version as a WordPress revision.
 *
 * WHAT IS REPAIRED
 * ----------------
 * 1. Internal links. A link that goes through a redirect is pointed straight
 *    at the final address. A link to an address that answers 404 is pointed
 *    at the page it clearly meant (same slug, old slug, a near-identical
 *    slug); with no safe match, the link is removed and its words stay. Every
 *    address is asked first: a link the site actually answers with 200 is
 *    never touched, whatever the audit thought.
 * 2. Missing internal links (rule 3.5). The first plain mention of a record
 *    the site has a page for, outside headings, links and code, becomes a
 *    link; at most five new links per text per pass.
 * 3. Search Console sitemap. sitemap_index.xml is submitted to Google when it
 *    is not listed there or has changed since the last submission (once a day
 *    at most), and Google's own report on it (errors, warnings, how many
 *    addresses it read) is kept for the control centre.
 * 4. Bing and Yandex. Every address in the sitemap is announced once through
 *    IndexNow, then again weekly when the owner runs every check.
 * 5. Background jobs left behind by removed plugins (Rank Math, Site Kit)
 *    are taken off the schedule.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_FIX_LOG = 'daw_fix_log';

/** Whether the repairs run (on unless the owner turned them off). */
function daw_fix_on() {
	return '0' !== (string) get_option( 'daw_fix_on', '1' );
}

/** Keep a line about what was done; the last 150 are kept. */
function daw_fix_log( $kind, $text, $post_id = 0 ) {
	$log   = (array) get_option( DAW_FIX_LOG, array() );
	$log[] = array(
		'at'   => time(),
		'kind' => (string) $kind,
		'text' => (string) $text,
		'post' => (int) $post_id,
	);
	update_option( DAW_FIX_LOG, array_slice( $log, -150 ), false );
}

add_filter( 'royal_mcp_readable_options', 'daw_fix_readable' );
function daw_fix_readable( $keys ) {
	$keys = (array) $keys;
	foreach ( array( DAW_FIX_LOG, 'daw_gsc_sm_status', 'daw_kc_last_run', 'daw_fix_probe_last' ) as $k ) {
		$keys[] = $k;
	}
	return array_values( array_unique( $keys ) );
}

/* ============================================================== schedule == */

add_action( 'init', 'daw_fix_schedule' );
function daw_fix_schedule() {
	if ( ! wp_next_scheduled( 'daw_fix_tick' ) ) {
		wp_schedule_event( time() + 20 * MINUTE_IN_SECONDS, 'hourly', 'daw_fix_tick' );
	}
	if ( ! wp_next_scheduled( 'daw_gsc_sm_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'daw_gsc_sm_daily' );
	}
}

add_action( 'daw_fix_tick', 'daw_fix_tick_run' );
function daw_fix_tick_run() {
	if ( ! daw_fix_on() ) {
		return;
	}
	daw_fix_links_run( 25 );
	daw_fix_cron_cleanup();
	// A refused sitemap submission is tried again within the hour.
	$sm = (array) get_option( 'daw_gsc_sm_status', array() );
	if ( isset( $sm['submit_ok'] ) && ! $sm['submit_ok'] && time() - (int) ( $sm['submit_at'] ?? 0 ) > HOUR_IN_SECONDS ) {
		daw_gsc_sm_run();
	}
	daw_inx_full_push();
	daw_fix_pictures_run( 4 );
}

/* ============================================================== pictures == */

/**
 * The records the picture report sends to a person, worked through by the
 * same strict matcher the archive was matched with (tmdb-match.php: an exact
 * native title, or an exact title with the year or the country agreeing).
 *
 * - No TMDB id: searched, at most every 30 days. A confident hit is saved and
 *   the picture fetched; otherwise the candidates are kept for the review page
 *   and the report decides whether any of them is worth a person's look.
 * - Id that does not agree with the record: searched again. Only a confident
 *   hit on a different entry replaces it (the old id is kept in
 *   _daw_tmdb_prev and logged). Nothing confident: left as it is, still shown
 *   with initials, because a wrong portrait is worse than none.
 */
function daw_fix_pictures_run( $max = 4 ) {
	if ( ! function_exists( 'daw_img_report' ) || ! function_exists( 'daw_tmdb_search' ) || ! function_exists( 'daw_tmdb_key' ) || ! daw_tmdb_key() ) {
		return;
	}
	$rep  = daw_img_report();
	$done = 0;
	$rows = array();
	foreach ( array( 'no_id', 'mismatch' ) as $gk ) {
		foreach ( (array) ( $rep[ $gk ]['rows'] ?? array() ) as $row ) {
			$rows[] = array( $gk, (int) $row[0] );
		}
	}
	foreach ( $rows as $r ) {
		if ( $done >= $max ) {
			break;
		}
		list( $gk, $pid ) = $r;
		$at = (int) get_post_meta( $pid, '_daw_tmdb_cand_at', true );
		if ( $at && time() - $at < 30 * DAY_IN_SECONDS ) {
			continue;
		}
		if ( 'none' === (string) get_post_meta( $pid, 'daw_tmdb_match', true ) ) {
			continue;
		}
		++$done;
		$hit = daw_tmdb_search( $pid );
		update_post_meta( $pid, '_daw_tmdb_cand_at', time() );
		$old = trim( (string) get_post_meta( $pid, 'daw_tmdb_id', true ) );

		if ( ! empty( $hit['id'] ) && (string) (int) $hit['id'] !== $old ) {
			if ( '' !== $old ) {
				update_post_meta( $pid, '_daw_tmdb_prev', $old );
			}
			update_post_meta( $pid, 'daw_tmdb_id', (int) $hit['id'] );
			update_post_meta( $pid, 'daw_tmdb_match', 'auto' );
			update_post_meta( $pid, '_daw_tmdb_auto', $hit['row'] ?? array() );
			delete_post_meta( $pid, '_daw_tmdb_cand' );
			if ( defined( 'DAW_TMDB_IMG_ONE' ) && ! wp_next_scheduled( DAW_TMDB_IMG_ONE, array( $pid ) ) ) {
				wp_schedule_single_event( time() + 60, DAW_TMDB_IMG_ONE, array( $pid ) );
			}
			daw_fix_log( 'img', sprintf( '"%s" TMDB kaydıyla eşleşti (%d%s)', get_the_title( $pid ), (int) $hit['id'], '' !== $old ? ', eski ' . $old : '' ), $pid );
		} elseif ( 'no_id' === $gk ) {
			update_post_meta( $pid, '_daw_tmdb_cand', isset( $hit['candidates'] ) ? (array) $hit['candidates'] : array() );
		}
	}
}

add_action( 'daw_gsc_sm_daily', 'daw_gsc_sm_run' );

/* ================================================================= links == */

/**
 * What the site really answers for one of its own paths: 200, a redirect's
 * final address, or 404. Asked over HTTP, so custom routes (/trends/,
 * filtered lists, the sitemap) are judged by what a reader would get.
 *
 * @return array{code:int, to:string}
 */
function daw_fix_probe( $path ) {
	static $memo = array();

	if ( isset( $memo[ $path ] ) ) {
		return $memo[ $path ];
	}

	$url = home_url( $path );
	// GET, not HEAD: the host's proxy answers some HEAD requests with 405 or
	// nothing at all, and an unknown answer leaves a broken link waiting
	// forever. Only the first 2 KB are read.
	$res = wp_remote_get(
		$url,
		array(
			'timeout'             => 15,
			'redirection'         => 0,
			'limit_response_size' => 2048,
			'user-agent'          => 'Mozilla/5.0 (compatible; DAW-Link-Check/1.1; +' . home_url( '/' ) . ')',
		)
	);

	if ( is_wp_error( $res ) ) {
		$memo[ $path ] = array( 'code' => 0, 'to' => '' );
		return $memo[ $path ];
	}

	$code = (int) wp_remote_retrieve_response_code( $res );
	$to   = (string) wp_remote_retrieve_header( $res, 'location' );

	if ( '' !== $to && 0 === strpos( $to, '/' ) ) {
		$to = home_url( $to );
	}

	$memo[ $path ] = array( 'code' => $code, 'to' => $to );

	// What the probes answered lately, for diagnosis from the chat.
	$st = (array) get_option( 'daw_fix_probe_last', array() );
	$st[ $path ] = $code;
	update_option( 'daw_fix_probe_last', array_slice( $st, -40, null, true ), false );

	return $memo[ $path ];
}

/** Absolute address for a redirect target. */
function daw_fix_abs( $target ) {
	$target = (string) $target;
	return 0 === strpos( $target, '/' ) ? home_url( $target ) : $target;
}

/**
 * Inside which elements a mention must not become a link.
 */
function daw_fix_blocked_tags() {
	return array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style', 'code', 'pre', 'button', 'figcaption', 'title', 'textarea', 'select', 'option', 'label', 'summary' );
}

/**
 * Link the first plain mention of each name, outside the blocked elements.
 *
 * @param string $html
 * @param array  $names [ id, name ] pairs.
 * @param int    $max   New links at most.
 * @return array{0:string,1:array} New html and the names linked.
 */
function daw_fix_autolink( $html, array $names, $max = 5 ) {
	// SAFE NAMES ONLY (1.59.3)
	// A one-word title is too often an ordinary word with a capital: the
	// first pass linked "Screen" in "Screen Actors Guild", "Happiness" in
	// "The Land of Happiness" and "Kingdom" in "United Kingdom". Only names
	// of two words or more are linked, and only where they stand alone: not
	// glued to another capitalised word, and in italics only when the whole
	// italic text is the name.
	$names = array_values(
		array_filter(
			$names,
			static function ( $p ) {
				return false !== strpos( trim( (string) $p[1] ), ' ' );
			}
		)
	);
	if ( ! $names ) {
		return array( $html, array() );
	}

	$parts   = preg_split( '/(<!--.*?-->|<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	$blocked = array_flip( daw_fix_blocked_tags() );
	$italic  = array( 'em' => 1, 'i' => 1, 'cite' => 1 );
	$depth   = 0;
	$idepth  = 0;
	$done    = array();

	foreach ( $parts as $i => $part ) {
		if ( '' === $part ) {
			continue;
		}
		if ( '<' === $part[0] ) {
			if ( preg_match( '#^<(/?)([a-z0-9]+)\b#i', $part, $t ) && '/' !== substr( rtrim( $part, '>' ), -1 ) ) {
				$tag = strtolower( $t[2] );
				if ( isset( $blocked[ $tag ] ) ) {
					$depth = max( 0, $depth + ( '/' === $t[1] ? -1 : 1 ) );
				}
				if ( isset( $italic[ $tag ] ) ) {
					$idepth = max( 0, $idepth + ( '/' === $t[1] ? -1 : 1 ) );
				}
			}
			continue;
		}
		if ( $depth > 0 || count( $done ) >= $max || preg_match( '/\[\/?[a-z_][^\]]*\]/i', $part ) ) {
			continue; // Inside a link, heading or code, or a text carrying a shortcode.
		}
		foreach ( $names as $k => $pair ) {
			list( $id, $name ) = $pair;
			if ( isset( $done[ $id ] ) || count( $done ) >= $max ) {
				continue;
			}
			$link = get_permalink( (int) $id );
			if ( ! $link ) {
				continue;
			}
			if ( $idepth > 0 ) {
				// In italics: only an italic that is exactly this title.
				if ( trim( html_entity_decode( $part, ENT_QUOTES, 'UTF-8' ) ) !== $name ) {
					continue;
				}
				$parts[ $i ] = '<a href="' . esc_url( $link ) . '">' . $part . '</a>';
				$part        = $parts[ $i ];
				$done[ $id ] = $name;
				unset( $names[ $k ] );
				continue;
			}
			$re = '/(?<![\p{L}\p{N}&#;])(' . preg_quote( $name, '/' ) . ')(?![\p{L}\p{N}])/u';
			if ( ! preg_match_all( $re, $part, $mm, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $mm[1] as $hit ) {
				$at     = (int) $hit[1];
				$before = substr( $part, 0, $at );
				$after  = substr( $part, $at + strlen( $hit[0] ) );
				// Glued to another capitalised word ("Screen Actors", "United Kingdom")?
				if ( preg_match( '/\p{Lu}[\p{L}\'’-]*\s+$/u', $before ) || preg_match( '/^\s+\p{Lu}/u', $after ) || preg_match( '/^[\'’]s\s+\p{Lu}/u', $after ) ) {
					continue;
				}
				// Already inside a link placed earlier in this node?
				if ( substr_count( strtolower( $before ), '<a ' ) > substr_count( strtolower( $before ), '</a>' ) ) {
					continue;
				}
				$part        = $before . '<a href="' . esc_url( $link ) . '">' . $hit[0] . '</a>' . $after;
				$parts[ $i ] = $part;
				$done[ $id ] = $name;
				unset( $names[ $k ] );
				break;
			}
		}
	}

	return array( implode( '', $parts ), $done );
}

/**
 * Repair one text from its stored link audit.
 *
 * @return int Number of changes made.
 */
function daw_fix_post_links( $post_id ) {
	$post_id = (int) $post_id;
	$audit   = get_post_meta( $post_id, defined( 'DAW_LK_META' ) ? DAW_LK_META : '_daw_links', true );
	$post    = get_post( $post_id );

	if ( ! is_array( $audit ) || ! $post || 'publish' !== $post->post_status ) {
		return 0;
	}

	// href (as written, decoded) => new address, or '' to remove the link.
	$rescan = false;
	$map  = array();
	$why  = array();

	foreach ( (array) ( $audit['redirected'] ?? array() ) as $row ) {
		if ( is_array( $row ) && ! empty( $row[0] ) && ! empty( $row[1] ) ) {
			$map[ (string) $row[0] ] = daw_fix_abs( $row[1] );
			$why[ (string) $row[0] ] = 'yönlendirme yerine doğrudan hedef';
		}
	}

	foreach ( (array) ( $audit['broken'] ?? array() ) as $href ) {
		$href = (string) $href;
		$path = function_exists( 'daw_lk_path' ) ? daw_lk_path( $href ) : (string) wp_parse_url( $href, PHP_URL_PATH );
		$p    = daw_fix_probe( $path );

		if ( 200 === $p['code'] ) {
			// The site answers it: remember that, so the audit stops calling
			// it broken (seo-links.php reads this list).
			$live          = (array) get_option( 'daw_lk_live_paths', array() );
			$live[ $path ] = time();
			update_option( 'daw_lk_live_paths', array_slice( $live, -500, null, true ), false );
			$rescan        = true;
			continue;
		}
		if ( in_array( $p['code'], array( 301, 302, 307, 308 ), true ) && '' !== $p['to'] ) {
			$map[ $href ] = $p['to'];
			$why[ $href ] = 'yönlenen adres doğrudan hedefe';
			continue;
		}
		if ( ! in_array( $p['code'], array( 404, 410 ), true ) ) {
			continue; // Server hiccup: decide on a later pass.
		}
		$s = function_exists( 'daw_nf_suggest' ) ? daw_nf_suggest( $path ) : null;
		if ( is_array( $s ) && ! empty( $s['auto'] ) && ! empty( $s['url'] ) ) {
			$map[ $href ] = (string) $s['url'];
			$why[ $href ] = 'kırık bağlantı doğru sayfaya (' . $s['why'] . ')';
		} else {
			$map[ $href ] = '';
			$why[ $href ] = 'kırık bağlantı kaldırıldı, metin kaldı';
		}
	}

	$html    = (string) $post->post_content;
	$changes = array();

	if ( $map ) {
		$html = (string) preg_replace_callback(
			'#<a\b([^>]*?)\bhref=(["\'])(.*?)\2([^>]*)>(.*?)</a>#is',
			static function ( $m ) use ( $map, $why, &$changes ) {
				$href = html_entity_decode( $m[3], ENT_QUOTES, 'UTF-8' );
				if ( ! array_key_exists( $href, $map ) ) {
					return $m[0];
				}
				$changes[] = $href . ' → ' . ( '' === $map[ $href ] ? '(kaldırıldı)' : $map[ $href ] ) . ' · ' . $why[ $href ];
				if ( '' === $map[ $href ] ) {
					return $m[5];
				}
				return '<a' . $m[1] . 'href=' . $m[2] . esc_url( $map[ $href ] ) . $m[2] . $m[4] . '>' . $m[5] . '</a>';
			},
			$html
		);
	}

	$unlinked = array();
	foreach ( (array) ( $audit['unlinked'] ?? array() ) as $row ) {
		if ( is_array( $row ) && ! empty( $row[0] ) && ! empty( $row[1] ) && 'publish' === get_post_status( (int) $row[0] ) ) {
			$unlinked[] = array( (int) $row[0], (string) $row[1] );
		}
	}
	if ( $unlinked ) {
		list( $html, $linked ) = daw_fix_autolink( $html, $unlinked, 5 );
		foreach ( $linked as $id => $name ) {
			$changes[] = '"' . $name . '" → ' . get_permalink( (int) $id ) . ' · iç bağlantı eklendi';
		}
	}

	if ( ! $changes || $html === (string) $post->post_content ) {
		if ( ! empty( $rescan ) && function_exists( 'daw_lk_scan' ) ) {
			daw_lk_scan( $post_id );
		}
		return 0;
	}

	// The text as it was stays one click away.
	if ( function_exists( 'wp_save_post_revision' ) ) {
		wp_save_post_revision( $post_id );
	}

	global $wpdb;
	$wpdb->update( $wpdb->posts, array( 'post_content' => $html ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB
	clean_post_cache( $post_id );
	do_action( 'litespeed_purge_post', $post_id );

	foreach ( $changes as $c ) {
		daw_fix_log( 'link', $c, $post_id );
	}

	if ( function_exists( 'daw_lk_scan' ) ) {
		daw_lk_scan( $post_id );
	}

	return count( $changes );
}

/** Repair the texts the link audit flagged, a batch at a time. */
function daw_fix_links_run( $limit = 25 ) {
	global $wpdb;

	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id ASC LIMIT %d", defined( 'DAW_LK_META' ) ? DAW_LK_META : '_daw_links', (int) $limit ) ); // phpcs:ignore WordPress.DB
	$n   = 0;

	foreach ( (array) $ids as $id ) {
		$n += daw_fix_post_links( (int) $id );
	}

	update_option( 'daw_fix_links_at', time(), false );

	return $n;
}

/* ===================================================== Search Console ===== */

/**
 * Google's report on the sitemap, and a fresh submission when it is due.
 */
function daw_gsc_sm_run( $force = false ) {
	if ( ! function_exists( 'daw_gsc_creds' ) || ! daw_gsc_creds() ) {
		return;
	}

	$prop  = (string) daw_gsc_settings()['property'];
	$base  = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $prop ) . '/sitemaps';
	$want  = home_url( '/sitemap_index.xml' );
	$state = (array) get_option( 'daw_gsc_sm_status', array() );
	$gen   = (int) get_option( 'daw_sm_gen', 1 );

	$list = daw_gsc_api( 'GET', $base );
	if ( is_wp_error( $list ) ) {
		$state['error'] = $list->get_error_message();
		$state['at']    = time();
		update_option( 'daw_gsc_sm_status', $state, false );
		return;
	}

	$entries = array();
	$mine    = null;
	foreach ( (array) ( $list['sitemap'] ?? array() ) as $sm ) {
		$submitted = 0;
		$indexed   = 0;
		foreach ( (array) ( $sm['contents'] ?? array() ) as $c ) {
			$submitted += (int) ( $c['submitted'] ?? 0 );
			$indexed   += (int) ( $c['indexed'] ?? 0 );
		}
		$row = array(
			'path'       => (string) ( $sm['path'] ?? '' ),
			'submitted'  => (string) ( $sm['lastSubmitted'] ?? '' ),
			'downloaded' => (string) ( $sm['lastDownloaded'] ?? '' ),
			'pending'    => ! empty( $sm['isPending'] ),
			'errors'     => (int) ( $sm['errors'] ?? 0 ),
			'warnings'   => (int) ( $sm['warnings'] ?? 0 ),
			'urls'       => $submitted,
			'indexed'    => $indexed,
		);
		$entries[] = $row;
		if ( untrailingslashit( $row['path'] ) === untrailingslashit( $want ) ) {
			$mine = $row;
		}
	}

	$due = $force || ! $mine
		|| ( (int) ( $state['gen'] ?? 0 ) !== $gen && time() - (int) ( $state['submit_at'] ?? 0 ) > DAY_IN_SECONDS )
		|| ( isset( $state['submit_ok'] ) && ! $state['submit_ok'] && time() - (int) ( $state['submit_at'] ?? 0 ) > HOUR_IN_SECONDS );

	if ( $due ) {
		$res = daw_gsc_api( 'PUT', $base . '/' . rawurlencode( $want ) );
		$state['submit_at'] = time();
		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			if ( false !== stripos( $msg, 'permission' ) || false !== stripos( $msg, '403' ) || false !== stripos( $msg, 'Servis hesab' ) ) {
				$msg = 'Google gönderime izin vermedi: servis hesabını Search Console\'da "Tam" yetkili kullanıcı yapın. (' . $msg . ')';
			}
			$state['submit_ok']  = false;
			$state['submit_msg'] = $msg;
		} else {
			$state['submit_ok']  = true;
			$state['submit_msg'] = '';
			$state['gen']        = $gen;
			daw_fix_log( 'gsc', 'sitemap_index.xml Search Console\'a gönderildi' );
		}
	}

	$state['entries'] = $entries;
	$state['mine']    = $mine;
	$state['error']   = '';
	$state['at']      = time();
	update_option( 'daw_gsc_sm_status', $state, false );
}

/* =============================================================== IndexNow == */

/**
 * Announce every address in the sitemap once (and again when the owner runs
 * every check, at most weekly). New and changed pages are already announced
 * one by one by seo-indexnow.php.
 */
function daw_inx_full_push( $force = false ) {
	if ( ! function_exists( 'daw_inx_submit' ) || ! function_exists( 'daw_sm_types' ) || ! function_exists( 'daw_eng_live' ) || ! daw_eng_live() ) {
		return 0;
	}

	$last = (int) get_option( 'daw_inx_full_at', 0 );
	if ( $last && ( ! $force || time() - $last < WEEK_IN_SECONDS ) ) {
		return 0;
	}

	$urls = array( home_url( '/' ) );
	foreach ( (array) daw_sm_types() as $type ) {
		foreach ( (array) daw_sm_rows( $type ) as $row ) {
			$id = is_array( $row ) ? (int) ( $row[0] ?? 0 ) : (int) $row; // [ id, modified ] pairs.
			if ( $id ) {
				$link = get_permalink( $id );
				if ( $link ) {
					$urls[] = $link;
				}
			}
		}
	}
	$urls = array_values( array_unique( $urls ) );

	update_option( 'daw_inx_full_at', time(), false );

	if ( count( $urls ) < 2 ) {
		return 0;
	}

	$res = daw_inx_submit( $urls );
	daw_fix_log( 'indexnow', sprintf( '%d adres IndexNow ile Bing ve Yandex\'e bildirildi (yanıt: %s)', count( $urls ), is_wp_error( $res ) ? $res->get_error_message() : (string) $res ) );

	return count( $urls );
}

/* ================================================================== cron == */

/** Events still on the schedule for plugins that are gone. */
function daw_fix_cron_cleanup() {
	$crons = function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : array();
	$gone  = array();

	foreach ( $crons as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( preg_match( '/^(rank_math|rankmath|googlesitekit|site_kit|sitekit)/i', (string) $hook ) && ! has_action( $hook ) ) {
				$gone[ $hook ] = true;
			}
		}
	}

	foreach ( array_keys( $gone ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
		daw_fix_log( 'cron', 'Kaldırılmış eklentinin işi takvimden silindi: ' . $hook );
	}

	return count( $gone );
}

/** Background events overdue by more than fifteen minutes, by hook. */
function daw_fix_late_events() {
	$crons = function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : array();
	$late  = array();
	$limit = time() - 15 * MINUTE_IN_SECONDS;

	foreach ( $crons as $ts => $hooks ) {
		if ( (int) $ts >= $limit ) {
			continue;
		}
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			$late[ $hook ] = isset( $late[ $hook ] ) ? min( $late[ $hook ], (int) $ts ) : (int) $ts;
		}
	}

	asort( $late );
	return $late;
}

/* ======================================================== run every check == */

add_action( 'admin_post_daw_kc_run_all', 'daw_kc_run_all' );
function daw_kc_run_all() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'İzin yok.' );
	}
	check_admin_referer( 'daw_kc_run_all' );

	update_option( 'daw_kc_last_run', time(), false );
	delete_transient( 'daw_kc_sm_urls' );

	// The link audit starts a fresh pass and keeps going every ninety seconds
	// until it has read every text (seo-links.php).
	update_option( 'daw_lk_state', array( 'cursor' => 0, 'pass_end' => 0, 'fast' => 1 ), false );

	$t = time();
	foreach ( array( 'daw_lk_tick', 'daw_gsc_sync', 'daw_gsc_inspect', 'daw_nf_autofix', 'daw_snapshot_cron', 'daw_fresh_daily', 'daw_speed_daily' ) as $i => $hook ) {
		wp_schedule_single_event( $t + $i * 20, $hook );
	}
	wp_schedule_single_event( $t + 200, 'daw_kc_run_all_late' );

	$psi = 0;
	if ( function_exists( 'daw_psi_queue_all' ) ) {
		daw_psi_queue_all();
		$psi = function_exists( 'daw_psi_targets' ) ? 2 * count( daw_psi_targets() ) : 0;
	}

	// The bar under the button follows these steps until each has really run.
	if ( function_exists( 'daw_kcp_start' ) ) {
		daw_kcp_start( $t, $psi );
	}

	spawn_cron();

	wp_safe_redirect( add_query_arg( 'daw_kc', 'started', admin_url( 'admin.php?page=daw' ) ) );
	exit;
}

/** The second wave: repairs, after the checks they rely on have run. */
add_action( 'daw_kc_run_all_late', 'daw_kc_run_all_late' );
function daw_kc_run_all_late() {
	if ( daw_fix_on() ) {
		daw_fix_links_run( 50 );
		daw_fix_cron_cleanup();
		daw_inx_full_push( true );
	}
	daw_gsc_sm_run( true );
}

add_action( 'admin_post_daw_fix_toggle', 'daw_fix_toggle' );
function daw_fix_toggle() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'İzin yok.' );
	}
	check_admin_referer( 'daw_fix_toggle' );
	update_option( 'daw_fix_on', daw_fix_on() ? '0' : '1', false );
	wp_safe_redirect( admin_url( 'admin.php?page=daw#kc-fix' ) );
	exit;
}

/**
 * Undo the one-word links of the first pass (1.59.3). Every link the repair
 * added is in the log; the ones for a single-word name are put back to
 * plain text, exactly as they were written.
 */
add_action( 'admin_init', 'daw_fix_undo_single_v1' );
add_action( 'daw_fix_tick', 'daw_fix_undo_single_v1', 1 );
function daw_fix_undo_single_v1() {
	if ( get_option( 'daw_fix_undo_single_v1' ) ) {
		return;
	}
	update_option( 'daw_fix_undo_single_v1', time(), false );

	global $wpdb;
	$by_post = array();
	foreach ( (array) get_option( DAW_FIX_LOG, array() ) as $row ) {
		if ( 'link' !== ( $row['kind'] ?? '' ) || empty( $row['post'] ) ) {
			continue;
		}
		if ( preg_match( '/^"([^"]+)" → (\S+) · iç bağlantı eklendi$/u', (string) $row['text'], $m ) && false === strpos( $m[1], ' ' ) ) {
			$by_post[ (int) $row['post'] ][] = array( $m[1], $m[2] );
		}
	}

	foreach ( $by_post as $pid => $pairs ) {
		$post = get_post( $pid );
		if ( ! $post ) {
			continue;
		}
		$html = (string) $post->post_content;
		$new  = $html;
		foreach ( $pairs as $p ) {
			$new = str_replace( '<a href="' . esc_url( $p[1] ) . '">' . $p[0] . '</a>', $p[0], $new );
		}
		if ( $new !== $html ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $pid ) ); // phpcs:ignore WordPress.DB
			clean_post_cache( $pid );
			do_action( 'litespeed_purge_post', $pid );
			daw_fix_log( 'link', sprintf( 'Tek kelimelik otomatik bağlantılar geri alındı (%s)', implode( ', ', wp_list_pluck( $pairs, 0 ) ) ), $pid );
		}
	}
}

