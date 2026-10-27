<?php
/**
 * Discover Asian World: search opportunities (Fırsatlar).
 *
 * WHAT THE OWNER ASKED FOR (5 Oct 2026)
 * -------------------------------------
 * Search Console already shows which searches bring each page up. Some of
 * them are not in the page's keyword list (_daw_kw, from Ubersuggest) and
 * nobody would have guessed them. Those are the cheapest growth there is:
 * Google already shows the page for them; answering them on the page moves
 * it up. This finds them every day, flags the new ones, and lets each be
 * added to the page's keyword list (where the audit then checks that the
 * page actually answers it) or dismissed.
 *
 * WHAT COUNTS AS AN OPPORTUNITY
 * -----------------------------
 * A search from the latest 28-day snapshot (perf.php, wp_daw_gsc_qh) for a
 * page that is one of our records, with at least 3 impressions, that is not
 * in that page's keyword list and has not been dismissed. Scored by
 * impressions, doubled when the page sits in positions 4 to 20, where a
 * small improvement turns impressions into clicks. "New" means the search
 * was not there in the snapshot about a month earlier.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_OPP_SLUG = 'daw-firsat';

/** Lower-case, single-spaced, as keywords are compared everywhere. */
function daw_opp_norm( $s ) {
	return trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( (string) $s ) ) );
}

/**
 * The comparison key: letters and digits only. "seo kang-joon", "Seo Kang
 * Joon" and "seo kangjoon" are one search for Google and one page for us.
 */
function daw_opp_key( $s ) {
	return (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( (string) $s ) );
}

/**
 * The same search in other words: the words of a search, sorted, without the
 * filler Google ignores and without the kind words the page already is.
 * "cast of ashfall" and "ashfall cast" are one search; so are "hotel king
 * korean drama" and "hotel king". Plurals stay ("X dramas", "X movies" ask for a list). A search that only differs from a listed
 * one (or from the page's name) this way is already covered, not new.
 */
function daw_opp_tkey( $s ) {
	static $stop = array( 'of', 'the', 'a', 'an', 'in', 'to', 'for', 'and', 'on', 'is', 'k', 'kdrama', 'korean', 'korea', 'drama', 'series', 'serie', 'movie', 'film', 'show', 'tv', 'kpop' );
	$t = mb_strtolower( html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	$t = preg_replace( '/\bk[\s-]?dramas?\b/u', ' kdrama ', $t );
	$w = array_filter( preg_split( '/[^\p{L}\p{N}]+/u', $t ), 'strlen' );
	// Plural and singular are one search, as the audit's matcher reads them:
	// "seo kang joon dramas" came back as new after "drama" was listed.
	$w = array_map(
		static function ( $x ) {
			if ( mb_strlen( $x ) <= 3 || 's' !== substr( $x, -1 ) || 'ss' === substr( $x, -2 ) ) {
				return $x;
			}
			if ( 'sses' === substr( $x, -4 ) ) {
				return substr( $x, 0, -2 );
			}
			if ( 'ies' === substr( $x, -3 ) && mb_strlen( $x ) > 4 ) {
				return substr( $x, 0, -3 ) . 'y';
			}
			return substr( $x, 0, -1 );
		},
		$w
	);
	$w = array_unique( array_diff( $w, $stop ) );
	sort( $w );
	return $w ? '~' . implode( ' ', $w ) : '';
}

/**
 * A record's keyword list as a set of keys. The page's own name (title,
 * native title, other names) counts as already placed: it is the H1.
 */
function daw_opp_kw_set( $pid ) {
	$set = array();
	foreach ( (array) get_post_meta( $pid, '_daw_kw', true ) as $k ) {
		$k = is_array( $k ) ? ( $k['kw'] ?? '' ) : $k;
		$set[ daw_opp_key( $k ) ]  = true;
		$set[ daw_opp_tkey( $k ) ] = true;
	}
	foreach ( (array) get_post_meta( $pid, '_daw_kw_ignore', true ) as $k ) {
		$set[ daw_opp_key( $k ) ] = true;
	}
	$names = array_merge(
		array( html_entity_decode( get_the_title( $pid ), ENT_QUOTES, 'UTF-8' ), (string) get_post_meta( $pid, 'daw_native_title', true ), (string) get_post_meta( $pid, 'daw_native_name', true ) ),
		array_map( 'strval', (array) get_post_meta( $pid, 'daw_also_known_as', true ) )
	);
	foreach ( $names as $n ) {
		if ( '' !== trim( $n ) ) {
			$set[ daw_opp_key( $n ) ]  = true;
			$set[ daw_opp_tkey( $n ) ] = true;
		}
	}
	unset( $set[''] );
	return $set;
}

/**
 * Which other record already lists this search as a keyword, or 0: adding it
 * here as well would make two of our pages chase one search.
 */
function daw_opp_owner( $qk, $pid, $tk = '' ) {
	static $map = null;
	if ( null === $map ) {
		global $wpdb;
		$map = array();
		foreach ( (array) $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_daw_kw'", ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB
			foreach ( (array) maybe_unserialize( $m['meta_value'] ) as $k ) {
				$kw = is_array( $k ) ? ( $k['kw'] ?? '' ) : (string) $k;
				foreach ( array( daw_opp_key( $kw ), daw_opp_tkey( $kw ) ) as $key ) {
					if ( '' !== $key && ! isset( $map[ $key ] ) ) {
						$map[ $key ] = (int) $m['post_id'];
					}
				}
			}
		}
	}
	$o = (int) ( $map[ $qk ] ?? 0 );
	if ( ! $o && '' !== $tk ) {
		$o = (int) ( $map[ $tk ] ?? 0 );
	}
	return $o && $o !== (int) $pid ? $o : 0;
}

/** Recompute the list. Runs after each Search Console query sync. */
add_action( 'daw_gsc_queries_synced', 'daw_opp_compute', 20 );
add_action( 'daw_kc_run_all_late', 'daw_opp_compute', 60 );
function daw_opp_compute() {
	if ( ! function_exists( 'daw_perf_qh_table' ) || (int) get_option( 'daw_perf_db', 0 ) < 1 ) {
		return;
	}
	global $wpdb;
	$t   = daw_perf_qh_table();
	$now = (string) $wpdb->get_var( "SELECT MAX(wk) FROM {$t}" ); // phpcs:ignore WordPress.DB
	if ( '' === $now ) {
		return;
	}
	$then = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(wk) FROM {$t} WHERE wk <= %s", gmdate( 'Y-m-d', strtotime( $now . ' -25 days' ) ) ) ); // phpcs:ignore WordPress.DB

	// Every search counts, a single impression included: on a young site the
	// searches with 1 or 2 impressions are most of what Google shows, and a
	// list filtered at 3 came back empty (5 Oct 2026: 97 rows, 96 known).
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT page, query, clicks, impressions, position FROM {$t} WHERE wk = %s AND impressions >= 1 ORDER BY impressions DESC LIMIT 5000", $now ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$old  = array();
	if ( '' !== $then ) {
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT page, query, position FROM {$t} WHERE wk = %s", $then ), ARRAY_A ) as $o ) { // phpcs:ignore WordPress.DB
			$old[ $o['page'] . '|' . $o['query'] ] = (float) $o['position'];
		}
	}

	$ids  = array();
	$sets = array();
	$out  = array();
	$byq  = array();
	$stat = array( 'rows' => count( $rows ), 'no_page' => 0, 'known' => 0 );
	$ctr  = array();
	foreach ( $rows as $r ) {
		$path = (string) $r['page'];
		if ( ! isset( $ids[ $path ] ) ) {
			$ids[ $path ] = (int) url_to_postid( home_url( $path ) );
		}
		$pid = $ids[ $path ];
		if ( ! $pid ) {
			++$stat['no_page'];
			continue;
		}
		if ( ! isset( $sets[ $pid ] ) ) {
			$sets[ $pid ] = daw_opp_kw_set( $pid );
		}
		$q = daw_opp_norm( $r['query'] );
		// On the first page of Google and nobody clicks: the title and the
		// description are the problem, not the content.
		if ( (float) $r['position'] <= 5 && 0 === (int) $r['clicks'] && (int) $r['impressions'] >= 20 ) {
			$ctr[] = array( 'pid' => $pid, 'q' => $q, 'imp' => (int) $r['impressions'], 'pos' => round( (float) $r['position'], 1 ) );
		}
		// "site:discoverasianworld.com" and other operator searches are the
		// owner checking the site, not a reader asking a question.
		if ( preg_match( '/\b(?:site|inurl|intitle|cache|related):/', $q ) ) {
			++$stat['known'];
			continue;
		}
		if ( '' === $q || isset( $sets[ $pid ][ daw_opp_key( $q ) ] ) || isset( $sets[ $pid ][ daw_opp_tkey( $q ) ] ) ) {
			++$stat['known'];
			continue;
		}
		$pos   = (float) $r['position'];
		$imp   = (int) $r['impressions'];
		$key   = $path . '|' . $r['query'];
		// One search, one row: when Google shows two of our pages for the
		// same search, the better placed page keeps it and the other is
		// named, so the search is never added to two pages (K19).
		$qk = daw_opp_key( $q );
		if ( isset( $byq[ $qk ] ) ) {
			$i = $byq[ $qk ];
			if ( $pos < $out[ $i ]['pos'] ) {
				$out[ $i ]['also'][] = (int) $out[ $i ]['pid'];
				$out[ $i ]['pid']    = $pid;
				$out[ $i ]['pos']    = round( $pos, 1 );
			} elseif ( (int) $out[ $i ]['pid'] !== $pid ) {
				$out[ $i ]['also'][] = $pid;
			}
			$out[ $i ]['imp']  += $imp;
			$out[ $i ]['clk']  += (int) $r['clicks'];
			$out[ $i ]['score'] = $out[ $i ]['imp'] * ( $out[ $i ]['pos'] >= 4 && $out[ $i ]['pos'] <= 20 ? 2 : 1 );
			continue;
		}
		$byq[ $qk ] = count( $out );
		$out[] = array(
			'also'  => array(),
			'owner' => daw_opp_owner( $qk, $pid, daw_opp_tkey( $q ) ),
			'pid'   => $pid,
			'q'     => $q,
			'imp'   => $imp,
			'clk'   => (int) $r['clicks'],
			'pos'   => round( $pos, 1 ),
			'prev'  => isset( $old[ $key ] ) ? round( $old[ $key ], 1 ) : null,
			'new'   => '' !== $then && ! isset( $old[ $key ] ),
			'score' => $imp * ( $pos >= 4 && $pos <= 20 ? 2 : 1 ),
		);
	}
	foreach ( $out as &$o ) {
		$o['also'] = array_values( array_unique( array_diff( $o['also'], array( (int) $o['pid'] ) ) ) );
	}
	unset( $o );
	usort( $out, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
	update_option( 'daw_opps', array( 'at' => time(), 'wk' => $now, 'then' => $then, 'stat' => $stat, 'ctr' => array_slice( $ctr, 0, 50 ), 'rows' => array_slice( $out, 0, 300 ) ), false );
}

/** The stored list, minus anything settled since it was computed. */
function daw_opp_rows() {
	$o = get_option( 'daw_opps', array() );
	if ( ! is_array( $o ) || empty( $o['rows'] ) ) {
		return array();
	}
	$sets = array();
	$out  = array();
	foreach ( $o['rows'] as $r ) {
		$pid = (int) $r['pid'];
		if ( ! isset( $sets[ $pid ] ) ) {
			$sets[ $pid ] = daw_opp_kw_set( $pid );
		}
		if ( ! isset( $sets[ $pid ][ daw_opp_key( $r['q'] ) ] ) && ! isset( $sets[ $pid ][ daw_opp_tkey( $r['q'] ) ] ) ) {
			$out[] = $r;
		}
	}
	return $out;
}

/* ============================================================== actions == */

add_action( 'admin_post_daw_opp', 'daw_opp_action' );
function daw_opp_action() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['n'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['n'] ) ), 'daw_opp' ) ) {
		wp_die( 'Yetkisiz', 403 );
	}
	$pid = isset( $_POST['pid'] ) ? (int) $_POST['pid'] : 0;
	$q   = isset( $_POST['q'] ) ? daw_opp_norm( sanitize_text_field( wp_unslash( $_POST['q'] ) ) ) : '';
	$imp = isset( $_POST['imp'] ) ? (int) $_POST['imp'] : 0;
	$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	if ( $pid && '' !== $q ) {
		daw_opp_apply( $pid, $q, $do, $imp );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=' . DAW_OPP_SLUG ) );
	exit;
}

/** Add a search to a record's keyword list, or dismiss it for that record. */
function daw_opp_apply( $pid, $q, $do, $imp = 0 ) {
	if ( 'add' === $do ) {
		$kw  = get_post_meta( $pid, '_daw_kw', true );
		$kw  = is_array( $kw ) ? $kw : array();
		$key = daw_opp_key( $q );
		foreach ( $kw as $k ) {
			$kk = is_array( $k ) ? ( $k['kw'] ?? '' ) : (string) $k;
			if ( daw_opp_key( $kk ) === $key || daw_opp_tkey( $kk ) === daw_opp_tkey( $q ) ) {
				return false; // already there.
			}
		}
		// Another page already chases this search: one search, one page.
		if ( daw_opp_owner( $key, $pid, daw_opp_tkey( $q ) ) ) {
			return false;
		}
		$kw[] = array( 'kw' => $q, 'vol' => (int) $imp, 'src' => 'gsc' );
		update_post_meta( $pid, '_daw_kw', $kw );
		return true;
	} elseif ( 'ignore' === $do ) {
		$ig   = get_post_meta( $pid, '_daw_kw_ignore', true );
		$ig   = is_array( $ig ) ? $ig : array();
		$ig[] = $q;
		update_post_meta( $pid, '_daw_kw_ignore', array_values( array_unique( $ig ) ) );
	}
}

/* ================================================================= page == */

add_action( 'admin_menu', 'daw_opp_menu', 30 );
function daw_opp_menu() {
	add_submenu_page( 'daw', 'Arama fırsatları', 'Arama fırsatları', 'manage_options', DAW_OPP_SLUG, 'daw_opp_page' );
}

function daw_opp_page() {
	$o    = get_option( 'daw_opps', array() );
	$rows = daw_opp_rows();
	echo '<div class="wrap"><h1>Arama fırsatları</h1>';
	echo '<p style="max-width:900px">Google bu sayfaları aşağıdaki aramalarda gösteriyor, ama aramalar sayfanın kalıp listesinde yok. "Kalıplara ekle" dersen arama o kaydın kalıplarına girer; denetim, sayfanın bu soruyu gerçekten cevaplayıp cevaplamadığını o andan sonra kontrol eder. Alakasızsa "Yoksay". <strong>Yeni</strong>: bir ay önce yoktu. Sıra 4 ile 20 arasındakiler öne alınır: küçük bir iyileştirme gösterimi tıklamaya çevirir.</p>';
	if ( is_array( $o ) && ! empty( $o['at'] ) ) {
		printf( '<p class="description">Son hesap: %s · Search Console penceresi: %s ile biten 28 gün · %d fırsat</p>', esc_html( wp_date( 'j M H:i', (int) $o['at'] ) ), esc_html( (string) $o['wk'] ), count( $rows ) );
	}
	// First page of Google, no clicks: the title and description are the fix.
	if ( is_array( $o ) && ! empty( $o['ctr'] ) ) {
		echo '<h2>İlk sayfada ama tıklanmıyor</h2><p class="description">Sıra 5 ya da daha iyi, en az 20 gösterim, hiç tık yok. İçerik değil, Google\'da görünen başlık ve açıklama düzeltilir.</p>';
		echo '<table class="widefat striped" style="max-width:900px;margin-bottom:24px"><thead><tr><th>Arama</th><th>Sayfa</th><th style="width:80px">Gösterim</th><th style="width:60px">Sıra</th></tr></thead><tbody>';
		foreach ( $o['ctr'] as $c ) {
			$seen = (int) get_post_modified_time( 'U', true, (int) $c['pid'] ) > strtotime( (string) $o['wk'] . ' 23:59:59 UTC' );
			printf( '<tr%s><td>%s</td><td><a href="%s">%s</a></td><td>%s</td><td>%s</td></tr>', $seen ? ' style="opacity:.5" title="Sayfa bu veriden sonra düzenlendi; yeni Search Console verisi bekleniyor"' : '', esc_html( $c['q'] ), esc_url( (string) get_permalink( (int) $c['pid'] ) ), esc_html( get_the_title( (int) $c['pid'] ) ), esc_html( number_format_i18n( $c['imp'] ) ), esc_html( number_format_i18n( $c['pos'], 1 ) ) );
		}
		echo '</tbody></table><h2>Kalıp listesinde olmayan aramalar</h2>';
	}
	if ( ! $rows ) {
		echo '<p>Şu an açık fırsat yok ya da Search Console arama verisi henüz gelmedi.</p></div>';
		return;
	}
	$nonce = wp_create_nonce( 'daw_opp' );
	echo '<table class="widefat striped"><thead><tr><th>Arama</th><th>Sayfa</th><th style="width:80px">Gösterim</th><th style="width:60px">Tık</th><th style="width:110px">Sıra</th><th style="width:220px"></th></tr></thead><tbody>';
	foreach ( array_slice( $rows, 0, 150 ) as $r ) {
		$move = '';
		if ( null !== $r['prev'] ) {
			$d    = $r['prev'] - $r['pos'];
			$move = abs( $d ) >= 0.5 ? sprintf( ' <span style="color:%s">%s %s</span>', $d > 0 ? '#1a7f37' : '#b32d2e', $d > 0 ? '▲' : '▼', number_format_i18n( abs( $d ), 1 ) ) : '';
		}
		$form = static function ( $do, $label, $primary ) use ( $r, $nonce ) {
			return sprintf(
				'<form method="post" action="%s" style="display:inline"><input type="hidden" name="action" value="daw_opp"><input type="hidden" name="n" value="%s"><input type="hidden" name="pid" value="%d"><input type="hidden" name="q" value="%s"><input type="hidden" name="imp" value="%d"><input type="hidden" name="do" value="%s"><button class="button%s button-small">%s</button></form> ',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( $nonce ),
				(int) $r['pid'],
				esc_attr( $r['q'] ),
				(int) $r['imp'],
				esc_attr( $do ),
				$primary ? ' button-primary' : '',
				esc_html( $label )
			);
		};
		$clash = '';
		if ( ! empty( $r['owner'] ) ) {
			$clash .= sprintf( '<br><span style="color:#b32d2e">Bu arama zaten %s sayfasının kalıbı: ekleme, o sayfayı güçlendir</span>', esc_html( get_the_title( (int) $r['owner'] ) ) );
		}
		if ( ! empty( $r['also'] ) ) {
			$clash .= '<br><span style="color:#7a5a12">Google bu arama için şunları da gösteriyor: ' . esc_html( implode( ', ', array_map( static fn( $x ) => get_the_title( (int) $x ), $r['also'] ) ) ) . '</span>';
		}
		printf(
			'<tr><td><strong>%s</strong>%s' . $clash . '</td><td><a href="%s">%s</a> · <a href="%s">denetim</a></td><td>%s</td><td>%s</td><td>%s%s</td><td>%s%s</td></tr>',
			esc_html( $r['q'] ),
			$r['new'] ? ' <span style="color:#7a5a12;border:1px solid #e8d9a8;border-radius:4px;padding:0 4px;font-size:11px">yeni</span>' : '',
			esc_url( (string) get_permalink( (int) $r['pid'] ) ),
			esc_html( get_the_title( (int) $r['pid'] ) ),
			esc_url( admin_url( 'admin.php?page=daw-denetim&record=' . (int) $r['pid'] ) ),
			esc_html( number_format_i18n( $r['imp'] ) ),
			esc_html( number_format_i18n( $r['clk'] ) ),
			esc_html( number_format_i18n( $r['pos'], 1 ) ),
			$move, // phpcs:ignore WordPress.Security.EscapeOutput
			$form( 'add', 'Kalıplara ekle', true ), // phpcs:ignore WordPress.Security.EscapeOutput
			$form( 'ignore', 'Yoksay', false ) // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}
	echo '</tbody></table></div>';
}

/* ======================================================== chat decisions == */

/** mcp-inbox.php hands over {"opps": [[post_id, "query", "add"|"ignore"], …]}. */
add_action( 'daw_inbox_opps', 'daw_opp_inbox', 10, 1 );
function daw_opp_inbox( $list ) {
	$imp = array();
	foreach ( daw_opp_rows() as $r ) {
		$imp[ $r['pid'] . '|' . $r['q'] ] = $r['imp'];
	}
	foreach ( (array) $list as $x ) {
		$pid = (int) ( $x[0] ?? 0 );
		$q   = daw_opp_norm( (string) ( $x[1] ?? '' ) );
		$do  = (string) ( $x[2] ?? '' );
		if ( $pid && '' !== $q && in_array( $do, array( 'add', 'ignore' ), true ) ) {
			daw_opp_apply( $pid, $q, $do, (int) ( $imp[ $pid . '|' . $q ] ?? 0 ) );
		}
	}
}

add_filter( 'royal_mcp_readable_options', 'daw_opp_readable' );
function daw_opp_readable( $keys ) {
	$keys   = (array) $keys;
	$keys[] = 'daw_opps';
	return array_values( array_unique( $keys ) );
}

/**
 * One pass over every record's keyword list: the same search written twice
 * ("seo kang joon wife" from Ubersuggest and again from Search Console, or
 * "X drama" and "X dramas") is kept once, with the larger volume. Runs once
 * per version of this rule.
 */
add_action( 'admin_init', 'daw_opp_kw_dedupe_once' );
function daw_opp_kw_dedupe_once() {
	if ( '1' === get_option( 'daw_kw_dedupe_v1' ) ) {
		return;
	}
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_daw_kw'" ); // phpcs:ignore WordPress.DB
	foreach ( array_map( 'intval', (array) $ids ) as $pid ) {
		$kw = get_post_meta( $pid, '_daw_kw', true );
		if ( ! is_array( $kw ) ) {
			continue;
		}
		$keep = array();
		foreach ( $kw as $k ) {
			$text = is_array( $k ) ? (string) ( $k['kw'] ?? '' ) : (string) $k;
			// Singular and plural are one search for the audit's matcher too.
			$key = preg_replace( '/s\b/u', '', daw_opp_norm( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			$key = daw_opp_key( $key );
			if ( '' === $key ) {
				continue;
			}
			$vol = is_array( $k ) ? (int) ( $k['vol'] ?? 0 ) : 0;
			if ( ! isset( $keep[ $key ] ) || $vol > (int) ( is_array( $keep[ $key ] ) ? ( $keep[ $key ]['vol'] ?? 0 ) : 0 ) ) {
				$keep[ $key ] = $k;
			}
		}
		if ( count( $keep ) !== count( $kw ) ) {
			update_post_meta( $pid, '_daw_kw', array_values( $keep ) );
		}
	}
	update_option( 'daw_kw_dedupe_v1', '1', false );
}
