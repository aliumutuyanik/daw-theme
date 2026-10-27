<?php
/**
 * Discover Asian World: decisions sent from the chat, applied by the site.
 *
 * The MCP connector can write post meta but not the site's own tables. A
 * list of decisions written to the meta key daw_inbox on the front page is
 * applied here (on the next admin page view or the hourly repair run) and
 * the key is removed, with each action logged in the repair log.
 *
 * Supported now:
 *   {"redirects": [["/old-path/", "/new-path/"], ["/junk/", 410]]}
 *   {"refresh": 1}  rewrites daw_kc_report at once
 *   {"imp_run": 10} re-audits people from before the impossible-field rule
 *   {"opps": [[post_id, "search", "add" | "ignore"]]}  (opportunities.php)
 *   {"opps_run": 1}  computes the search opportunities now
 *   {"sec_run": 1}   runs the outside security check now
 *   {"nf_drop": ["/path"]}  removes a 404 row that is not a lost page
 *   {"idx_run": 1}  sorts the pages Google left out by reason now (index-doctor.php)
 *   {"rd_audit": 1}  checks every redirect now (redirect-audit.php)
 *   {"cov": [123]}   compares these records with TMDB again now (coverage.php)
 *   {"img_check": 40}  checks that many pages' pictures now (image-check.php)
 *   {"rd_off": ["/old/"]}  switches a redirect off
 *   {"opt_find": "easy"}  lists a connector plugin's settings (mcp-health.php)
 * A target that is a path makes a 301; 410 tells search engines the address
 * is gone for good. A redirect is only written when its target is a live
 * page of this site, so a typo cannot send readers into a 404.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', 'daw_inbox_run', 40 );
// Written from the chat: applied in the same request, no page view needed.
add_action( 'added_post_meta', 'daw_inbox_on_write', 10, 3 );
add_action( 'updated_post_meta', 'daw_inbox_on_write', 10, 3 );
function daw_inbox_on_write( $meta_id, $object_id, $meta_key ) {
	if ( in_array( $meta_key, array( 'daw_inbox', '_daw_inbox' ), true ) && (int) $object_id === (int) get_option( 'page_on_front' ) ) {
		daw_inbox_run();
	}
}
add_action( 'daw_fix_tick', 'daw_inbox_run', 5 );
function daw_inbox_run() {
	$pid = (int) get_option( 'page_on_front' );
	if ( ! $pid ) {
		return;
	}
	// daw_inbox, without the underscore: WordPress refuses protected (_)
	// keys to the connector on pages.
	$box = get_post_meta( $pid, 'daw_inbox', true );
	if ( ! is_array( $box ) || ! $box ) {
		$box = get_post_meta( $pid, '_daw_inbox', true );
	}
	if ( ! is_array( $box ) || ! $box ) {
		return;
	}
	delete_post_meta( $pid, 'daw_inbox' );
	delete_post_meta( $pid, '_daw_inbox' );

	$done = array();
	foreach ( (array) ( $box['redirects'] ?? array() ) as $r ) {
		$src = '/' . ltrim( (string) ( $r[0] ?? '' ), '/' );
		$to  = $r[1] ?? '';
		if ( '/' === $src || ! function_exists( 'daw_rd_add' ) ) {
			continue;
		}
		if ( 410 === (int) $to ) {
			$ok = daw_rd_add( $src, '', array( 'code' => 410, 'origin' => 'auto', 'note' => 'Sohbetten: kalıcı olarak yok (410)' ) );
		} else {
			$to = '/' . ltrim( (string) wp_parse_url( (string) $to, PHP_URL_PATH ), '/' );
			if ( ! function_exists( 'daw_seo_path_exists' ) || ! daw_seo_path_exists( $to ) ) {
				$done[] = 'Atlandı (hedef yok): ' . $src . ' → ' . $to;
				continue;
			}
			$ok = daw_rd_add( $src, $to, array( 'origin' => 'auto', 'note' => 'Sohbetten: elle eşleştirildi' ) );
		}
		$done[] = ( $ok ? '' : 'Yazılamadı: ' ) . $src . ' → ' . ( 410 === (int) $to ? '410' : $to );
	}

	if ( ! empty( $box['opps'] ) ) {
		do_action( 'daw_inbox_opps', (array) $box['opps'] );
		$done[] = sprintf( '__opps %d', count( (array) $box['opps'] ) );
	}

	// {"opps_run": 1}: compute the search opportunities now, from the stored weeks.
	if ( ! empty( $box['opps_run'] ) && function_exists( 'daw_opp_compute' ) ) {
		daw_opp_compute();
		$done[] = '__opps computed';
	}
	// {"img_check": N}: check N pages' pictures now (up to 200).
	if ( ! empty( $box['img_check'] ) && function_exists( 'daw_ick_run' ) ) {
		daw_ick_run( max( 1, min( 200, (int) $box['img_check'] ) ) );
		$done[] = '__images checked';
	}
	// {"rd_off": ["/old-path/"]}: switch a redirect off (the address 404s again, or is a live page).
	if ( ! empty( $box['rd_off'] ) && function_exists( 'daw_rd_table' ) ) {
		global $wpdb;
		foreach ( (array) $box['rd_off'] as $p ) {
			$n      = (int) $wpdb->update( daw_rd_table(), array( 'status' => 'off' ), array( 'source' => daw_rd_norm( (string) $p ), 'status' => 'active' ) ); // phpcs:ignore WordPress.DB
			$done[] = 'kapatıldı: ' . $p . ' (' . $n . ')';
		}
		if ( function_exists( 'daw_rd_flush_cache' ) ) {
			daw_rd_flush_cache();
		}
	}
	// {"rd_audit": 1}: check every redirect now.
	if ( ! empty( $box['rd_audit'] ) && function_exists( 'daw_rda_run' ) ) {
		daw_rda_run();
		$done[] = '__redirects audited';
	}
	// {"news_set": {"<pid>": {"live":"1","checked":"2026-10-05","next":"when | what | id\n...","about":123}}}:
	// a living story's fields in one go (news-live.php). "checked":"today" means now.
	if ( ! empty( $box['news_set'] ) && is_array( $box['news_set'] ) ) {
		foreach ( $box['news_set'] as $pid => $f ) {
			$pid = (int) $pid;
			if ( ! $pid || 'news' !== daw_get_type( $pid ) || ! is_array( $f ) ) {
				continue;
			}
			if ( isset( $f['live'] ) ) {
				update_post_meta( $pid, 'daw_news_live', in_array( (string) $f['live'], array( '1', '0' ), true ) ? (string) $f['live'] : '' );
			}
			if ( isset( $f['next'] ) ) {
				$next = is_array( $f['next'] ) ? implode( "\n", $f['next'] ) : (string) $f['next'];
				// The MCP pipe drops backslashes, so a "\n" written inside the JSON
				// arrives as a bare "n" glued to the next row ("... |n2026-11 | ...").
				// Rows end in "|" or "| <id>", so that seam is put back as a line break.
				if ( false === strpos( $next, "\n" ) ) {
					$next = preg_replace( '/\|\s*(\d*)\s*n(?=[A-Z0-9])/', "| $1\n", $next );
				}
				update_post_meta( $pid, 'daw_news_next', sanitize_textarea_field( $next ) );
			}
			if ( isset( $f['about'] ) ) {
				update_post_meta( $pid, 'daw_news_about', (int) $f['about'] );
			}
			if ( isset( $f['checked'] ) ) {
				$c = 'today' === $f['checked'] ? gmdate( 'Y-m-d' ) : sanitize_text_field( (string) $f['checked'] );
				// Stored new each time so the state of the named titles is kept again.
				delete_post_meta( $pid, 'daw_news_checked' );
				add_post_meta( $pid, 'daw_news_checked', $c, true );
			}
			$done[] = '__news ' . $pid;
		}
		if ( function_exists( 'daw_news_stale_run' ) ) {
			daw_news_stale_run();
		}
	}
	// {"cov": [pid, ...]}: compare these records with TMDB again now (coverage.php),
	// so a cast fixed on a title clears the person's "not in its cast" line today.
	if ( ! empty( $box['cov'] ) && function_exists( 'daw_cov_sync' ) ) {
		foreach ( array_slice( array_map( 'intval', (array) $box['cov'] ), 0, 10 ) as $pid ) {
			if ( $pid > 0 && get_post( $pid ) ) {
				try {
					daw_cov_sync( $pid );
					$done[] = '__cov ' . $pid;
				} catch ( \Throwable $e ) {
					$done[] = '__cov ' . $pid . ' failed';
				}
				if ( function_exists( 'daw_ae_prep_store' ) && ! wp_next_scheduled( 'daw_ae_one', array( $pid ) ) ) {
					wp_schedule_single_event( time() + 5, 'daw_ae_one', array( $pid ) );
				}
			}
		}
	}
	// {"news_run": 1}: list the stories that need a look, now.
	if ( ! empty( $box['news_run'] ) && function_exists( 'daw_news_stale_run' ) ) {
		daw_news_stale_run();
		$done[] = '__news checked';
	}
	// {"ga4_run": 1}: read Analytics now (traffic quality included).
	if ( ! empty( $box['ga4_run'] ) && function_exists( 'daw_ga4_run' ) ) {
		daw_ga4_run();
		$done[] = '__ga4 read';
	}
	// {"sec_run": 1}: the outside security check, now instead of within the day.
	if ( ! empty( $box['sec_run'] ) && function_exists( 'daw_sec_run' ) ) {
		daw_sec_run();
		$done[] = '__security checked';
	}
	// {"opt_find": "easy"}: how a connector plugin stores its settings (mcp-health.php).
	if ( ! empty( $box['opt_find'] ) && function_exists( 'daw_mcph_opt_find' ) ) {
		foreach ( daw_mcph_opt_find( (string) $box['opt_find'] ) as $line ) {
			$done[] = '__' . $line;
		}
	}
	// {"nf_drop": ["/path"]}: a 404 row that is not a lost page (a bare "/" from a bad query string).
	if ( ! empty( $box['imp_run'] ) && function_exists( 'daw_ae_imp_catchup' ) ) {
		$done[] = '__imp ' . (int) daw_ae_imp_catchup( min( 25, max( 1, (int) $box['imp_run'] ) ) );
	}
	if ( ! empty( $box['idx_run'] ) && function_exists( 'daw_idx_build' ) ) {
		$doc    = daw_idx_build();
		$done[] = '__idx ' . (int) ( $doc['total'] ?? 0 );
	}

	if ( ! empty( $box['nf_drop'] ) && function_exists( 'daw_nf_table' ) ) {
		global $wpdb;
		foreach ( (array) $box['nf_drop'] as $p ) {
			$n      = (int) $wpdb->delete( daw_nf_table(), array( 'path' => (string) $p ) ); // phpcs:ignore WordPress.DB
			$done[] = '__404 dropped ' . $p . ' (' . $n . ')';
		}
	}

	foreach ( $done as $line ) {
		if ( 0 === strpos( $line, '__' ) ) {
			continue;
		}
		if ( function_exists( 'daw_fix_log' ) ) {
			daw_fix_log( 'link', 'Yönlendirme ' . $line );
		}
	}
	update_option( 'daw_inbox_last', array( 'at' => time(), 'done' => $done ), false );

	// {"refresh": 1}, or any change above: the chat reads the new report next.
	if ( function_exists( 'daw_kc_store_report' ) ) {
		daw_kc_store_report();
	}
}

add_filter( 'royal_mcp_readable_options', 'daw_inbox_readable' );
function daw_inbox_readable( $keys ) {
	$keys   = (array) $keys;
	$keys[] = 'daw_inbox_last';
	return array_values( array_unique( $keys ) );
}
