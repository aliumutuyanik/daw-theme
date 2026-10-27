<?php
/**
 * Discover Asian World: every redirect, checked once a day.
 *
 * A redirect that was right when it was written can go wrong later: the
 * target is trashed or renamed, a second redirect is stacked on the first,
 * or the old address becomes a real page again. Any of these sends readers
 * (and Google) somewhere useless. Once a day each active rule is checked:
 *
 * - dead       the target is not a live page of this site (bad)
 * - loop       following the rules comes back to where it started (bad)
 * - shadow     the old address is a live page again and a forced rule hides it (bad)
 * - chain      the target is itself redirected: collapsed to the final page here (fixed)
 * - home       sent to the home page: Google treats that as a soft 404 (warn)
 * - unrelated  old and new address share no meaningful word (warn: a person decides)
 * - dormant    the old address is a live page, so the rule never fires (info)
 *
 * Only a chain is changed without asking, because the final page is the
 * page the reader already ends up on. Everything else is listed for the
 * chat, which settles it through the inbox (mcp-inbox.php: redirects,
 * rd_off).
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'royal_mcp_readable_options', 'daw_rda_readable' );
function daw_rda_readable( $keys ) {
	$keys   = (array) $keys;
	$keys[] = 'daw_rda';
	return array_values( array_unique( $keys ) );
}

add_action( 'daw_fix_tick', 'daw_rda_tick', 40 );
function daw_rda_tick() {
	$r = get_option( 'daw_rda' );
	if ( ! is_array( $r ) || (int) ( $r['at'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
		daw_rda_run();
	}
}

/** Words that say what a page is about; the rest is glue. */
function daw_rda_words( $text ) {
	static $stop = array( 'the', 'and', 'for', 'with', 'from', 'that', 'this', 'what', 'how', 'why', 'are', 'its', 'you', 'your', 'about', 'into', 'korean', 'korea', 'drama', 'dramas', 'kdrama', 'movie', 'film', 'series', 'season', 'episode', 'episodes', 'cast', 'review', 'explained', 'guide', 'news', 'page', 'category', 'tag', 'html', 'php', 'amp' );
	$out = array();
	foreach ( preg_split( '/[^a-z0-9]+/', strtolower( remove_accents( rawurldecode( (string) $text ) ) ) ) as $w ) {
		if ( strlen( $w ) >= 3 && ! in_array( $w, $stop, true ) && ! preg_match( '/^\d{1,3}$/', $w ) ) {
			$out[ $w ] = true;
		}
	}
	return $out;
}

/** Internal path of a target, or '' when it points off-site. */
function daw_rda_internal( $target ) {
	$t = (string) $target;
	if ( preg_match( '#^https?://#i', $t ) ) {
		$host = (string) wp_parse_url( $t, PHP_URL_HOST );
		$mine = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( preg_replace( '/^www\./', '', strtolower( $host ) ) !== preg_replace( '/^www\./', '', strtolower( $mine ) ) ) {
			return '';
		}
		$t = (string) wp_parse_url( $t, PHP_URL_PATH );
	}
	return daw_rd_norm( '/' . ltrim( $t, '/' ) );
}

function daw_rda_run() {
	if ( ! function_exists( 'daw_rd_tables_ready' ) || ! daw_rd_tables_ready() ) {
		return array();
	}
	global $wpdb;
	$t     = daw_rd_table();
	$rules = (array) $wpdb->get_results( "SELECT id, source, match_type, target, code, force_live, hits, origin FROM {$t} WHERE status = 'active' ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB

	// Newest exact rule per source wins, as daw_rd_serve_on_404 does.
	$map = array();
	foreach ( $rules as $r ) {
		if ( 'exact' === $r['match_type'] && ! isset( $map[ $r['source'] ] ) ) {
			$map[ $r['source'] ] = $r;
		}
	}

	$rows  = array();
	$fixed = 0;
	foreach ( $map as $src => $r ) {
		$code = (int) $r['code'];
		if ( 410 === $code || 451 === $code ) {
			continue;
		}
		$to = daw_rda_internal( $r['target'] );
		if ( '' === $to ) {
			$rows[] = array( (int) $r['id'], $src, (string) $r['target'], 'external', 'Site dışına gidiyor' );
			continue;
		}

		// Follow the rules to the end, at most eight steps.
		$seen = array( $src => true );
		$end  = $to;
		$loop = false;
		for ( $i = 0; $i < 8 && isset( $map[ $end ] ) && ! in_array( (int) $map[ $end ]['code'], array( 410, 451 ), true ); $i++ ) {
			if ( isset( $seen[ $end ] ) ) {
				$loop = true;
				break;
			}
			$seen[ $end ] = true;
			$next         = daw_rda_internal( $map[ $end ]['target'] );
			if ( '' === $next ) {
				break;
			}
			$end = $next;
		}
		if ( $loop || isset( $seen[ $end ] ) ) {
			$rows[] = array( (int) $r['id'], $src, $to, 'loop', 'Yönlendirmeler döngüye giriyor' );
			continue;
		}
		$live_end = daw_seo_path_exists( $end ) || '/sitemap_index.xml' === rtrim( $end, '/' );
		if ( $end !== $to && $live_end ) {
			// A chain: point straight at the page the reader lands on.
			$wpdb->update( $t, array( 'target' => $end, 'note' => mb_substr( 'Zincir kısaltıldı (önceki hedef ' . $to . ')', 0, 255 ) ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB
			++$fixed;
			$to = $end;
		}
		if ( ! $live_end ) {
			$sug    = function_exists( 'daw_nf_suggest' ) ? daw_nf_suggest( $src ) : null;
			$rows[] = array( (int) $r['id'], $src, $to, 'dead', 'Hedef canlı bir sayfa değil' . ( $sug ? '; öneri: ' . wp_make_link_relative( (string) $sug['url'] ) : '' ) );
			continue;
		}
		$src_live = daw_seo_path_exists( $src );
		if ( $src_live ) {
			$rows[] = array( (int) $r['id'], $src, $to, ! empty( $r['force_live'] ) ? 'shadow' : 'dormant', ! empty( $r['force_live'] ) ? 'Eski adres canlı bir sayfa ve kural onu gizliyor' : 'Eski adres canlı bir sayfa; kural hiç çalışmıyor' );
			continue;
		}
		// Old sitemap addresses belong on the sitemap, not the home page.
		if ( preg_match( '#^/(sitemap|sitemaps)(\.xml|\.xml\.gz|\.txt)?/?$#', $src ) && '/sitemap_index.xml' !== rtrim( $to, '/' ) && '/sitemap_index.xml' !== rtrim( $src, '/' ) ) {
			$wpdb->update( $t, array( 'target' => '/sitemap_index.xml', 'note' => 'Eski site haritası adresi site haritasına bağlandı' ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB
			++$fixed;
			continue;
		}
		if ( '/' === $to && ! preg_match( '#^/index\.(html?|php)/?$#', $src ) ) {
			$rows[] = array( (int) $r['id'], $src, $to, 'home', 'Ana sayfaya gidiyor (Google için soft 404)' );
			continue;
		}
		$sw = daw_rda_words( $src );
		if ( $sw ) {
			$tw  = daw_rda_words( $to );
			$pid = url_to_postid( home_url( $to ) );
			if ( $pid ) {
				$tw += daw_rda_words( get_the_title( $pid ) );
				$tw += daw_rda_words( (string) get_post_meta( $pid, 'daw_native_title', true ) );
				foreach ( (array) get_post_meta( $pid, 'daw_also_known_as', true ) as $aka ) {
					$tw += daw_rda_words( (string) $aka );
				}
			}
			// Sections renamed when the site was rebuilt, compared on every
			// word (section words like "news" are stop words above).
			$raw = static function ( $t ) {
				$o = array();
				foreach ( preg_split( '/[^a-z0-9]+/', strtolower( remove_accents( rawurldecode( (string) $t ) ) ) ) as $w ) {
					if ( strlen( $w ) >= 3 ) {
						$o[ $w ] = true;
					}
				}
				return $o;
			};
			$sr = $raw( $src );
			foreach ( array( 'blog' => 'news', 'movie' => 'movies', 'guess' => 'test', 'quiz' => 'test' ) as $old => $new ) {
				if ( isset( $sr[ $old ] ) ) {
					$sr[ $new ] = true;
				}
			}
			$tr = $raw( $to ) + $tw;
			if ( ! array_intersect_key( $sw, $tw ) && ! array_intersect_key( $sr, $tr ) && ! preg_match( '#^/index\.(html?|php)/?$#', $src ) ) {
				$rows[] = array( (int) $r['id'], $src, $to, 'unrelated', 'Eski ve yeni adres ortak kelime taşımıyor' );
			}
		}
	}

	// Repairs that need no judgement (1.72.0):
	// - a scanner's or a file's address (probe, asset) sent anywhere: the
	//   rule is switched off and the address answers an honest 404;
	// - a dead, home-page or unrelated target where the site itself has the
	//   page (same slug, old slug, or a 85% slug match): pointed there.
	// What is left is listed for a person.
	$keep = array();
	foreach ( $rows as $row ) {
		list( $rid, $src, $to, $issue ) = $row;
		if ( ! in_array( $issue, array( 'home', 'dead', 'unrelated' ), true ) ) {
			$keep[] = $row;
			continue;
		}
		$kind = function_exists( 'daw_nf_kind' ) ? daw_nf_kind( $src ) : 'page';
		if ( 'page' !== $kind ) {
			$wpdb->update( $t, array( 'status' => 'off', 'note' => 'Denetim: tarayıcı/dosya adresi, 404 verir' ), array( 'id' => (int) $rid ) ); // phpcs:ignore WordPress.DB
			++$fixed;
			continue;
		}
		$sug = function_exists( 'daw_nf_suggest' ) ? daw_nf_suggest( $src ) : null;
		if ( $sug && ! empty( $sug['auto'] ) ) {
			$new = daw_rd_norm( (string) wp_parse_url( (string) $sug['url'], PHP_URL_PATH ) );
			if ( '/' !== $new && $new !== $src && daw_seo_path_exists( $new ) ) {
				$wpdb->update( $t, array( 'target' => $new, 'note' => mb_substr( 'Denetim: ' . $sug['why'], 0, 255 ) ), array( 'id' => (int) $rid ) ); // phpcs:ignore WordPress.DB
				++$fixed;
				continue;
			}
		}
		$keep[] = $row;
	}
	$rows = $keep;

	if ( $fixed && function_exists( 'daw_rd_flush_cache' ) ) {
		daw_rd_flush_cache();
	}
	if ( $fixed && function_exists( 'daw_fix_log' ) ) {
		daw_fix_log( 'link', sprintf( 'Yönlendirme denetimi %d kuralı düzeltti (zincir, ölü/ana sayfa hedefi ya da tarayıcı adresi)', $fixed ), 0 );
	}

	$count = array();
	foreach ( $rows as $row ) {
		$count[ $row[3] ] = ( $count[ $row[3] ] ?? 0 ) + 1;
	}
	$out = array(
		'at'     => time(),
		'total'  => count( $map ),
		'other'  => count( $rules ) - count( $map ),
		'fixed'  => $fixed,
		'count'  => $count,
		'rows'   => array_slice( $rows, 0, 200 ),
	);
	update_option( 'daw_rda', $out, false );
	return $out;
}

/** Labels for the control centre. */
function daw_rda_labels() {
	return array(
		'dead'      => array( 'bad', 'hedefi ölü' ),
		'loop'      => array( 'bad', 'döngü' ),
		'shadow'    => array( 'bad', 'canlı sayfayı gizliyor' ),
		'home'      => array( 'warn', 'ana sayfaya gidiyor' ),
		'unrelated' => array( 'warn', 'alakasız olabilir' ),
		'external'  => array( 'info', 'site dışına' ),
		'dormant'   => array( 'info', 'çalışmıyor (eski adres canlı)' ),
	);
}
