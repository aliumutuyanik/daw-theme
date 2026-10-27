<?php
/**
 * DAW › Kapsam: the whole archive's gaps in one place.
 *
 * Three lists, read from every record's `_daw_cov` report:
 *   1. Bağlanmamış: both records exist, the credit is not in the cast. Fixable
 *      in the title's cast box in a minute; biggest win first.
 *   2. Kaydı olmayan kişiler: people credited on our titles with no record,
 *      ranked by how many of our titles they are in and how high they are
 *      billed.
 *   3. Kaydı olmayan yapımlar: titles our people are in with no record,
 *      ranked by how many of our people are in them; upcoming ones marked.
 *
 * Opening a record still goes through the kanibalizasyon and TMDB gates (K19,
 * K23); this page only says where the gaps are.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'daw_covp_menu', 30 );
function daw_covp_menu() {
	add_submenu_page( 'daw', 'Kapsam', 'Kapsam', 'manage_options', 'daw-kapsam', 'daw_covp_page' );
}

/** Every stored report, keyed by post id. */
function daw_covp_reports() {
	global $wpdb;
	// phpcs:ignore WordPress.DB
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, m.meta_value, p.post_title FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status = 'publish'", DAW_COV ) );
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$v = maybe_unserialize( $r->meta_value );
		if ( is_array( $v ) ) {
			$v                       = function_exists( 'daw_cov_resolve_report' ) ? daw_cov_resolve_report( (int) $r->post_id, $v ) : $v;
			$v['title']              = $r->post_title;
			$out[ (int) $r->post_id ] = $v;
		}
	}
	return $out;
}

function daw_covp_aggregate( $reports ) {
	$people   = array();
	$titles   = array();
	$unlinked = array();
	foreach ( $reports as $pid => $r ) {
		if ( 'title' === ( $r['kind'] ?? '' ) ) {
			foreach ( (array) ( $r['people'] ?? array() ) as $p ) {
				if ( 'missing' === $p['state'] ) {
					$k = (int) $p['id'];
					if ( ! isset( $people[ $k ] ) ) {
						$people[ $k ] = array( 'name' => $p['name'], 'n' => 0, 'best' => 999, 'in' => array() );
					}
					$people[ $k ]['n']++;
					$people[ $k ]['best'] = min( $people[ $k ]['best'], (int) $p['order'] );
					$people[ $k ]['in'][] = $r['title'] . ( '' !== $p['role'] ? ' (' . $p['role'] . ')' : '' );
				} elseif ( 'unlinked' === $p['state'] ) {
					$unlinked[] = array( 'title' => $pid, 'person' => (int) $p['post'], 'role' => $p['role'], 'order' => (int) $p['order'], 'eps' => (int) $p['eps'] );
				}
			}
		} elseif ( 'person' === ( $r['kind'] ?? '' ) ) {
			foreach ( (array) ( $r['works'] ?? array() ) as $w ) {
				if ( 'missing' === $w['state'] ) {
					$k = $w['key'];
					if ( ! isset( $titles[ $k ] ) ) {
						$titles[ $k ] = array( 'title' => $w['title'], 'kind' => $w['kind'], 'year' => $w['year'], 'soon' => ! empty( $w['soon'] ), 'n' => 0, 'who' => array(), 'eps' => 0, 'pids' => array() );
					}
					$titles[ $k ]['n']++;
					$titles[ $k ]['eps']    = max( (int) $titles[ $k ]['eps'], (int) ( $w['eps'] ?? 0 ) );
					$titles[ $k ]['pids'][] = (int) $pid;
					$titles[ $k ]['who'][] = $r['title'] . ( '' !== $w['as'] ? ' (' . $w['as'] . ')' : ( '' !== $w['role'] ? ' (' . $w['role'] . ')' : '' ) );
				} elseif ( 'unlinked' === $w['state'] && '' === $w['as'] ) {
					$unlinked[] = array( 'title' => (int) $w['post'], 'person' => $pid, 'role' => $w['role'], 'order' => 999, 'eps' => (int) $w['eps'] );
				}
			}
		}
	}
	// The same pair can be found from both sides.
	$seen = array();
	$u    = array();
	foreach ( $unlinked as $x ) {
		$k = $x['title'] . ':' . $x['person'];
		if ( isset( $seen[ $k ] ) ) {
			continue;
		}
		$seen[ $k ] = true;
		$u[]        = $x;
	}
	uasort( $people, static function ( $a, $b ) { return ( $b['n'] <=> $a['n'] ) ?: ( $a['best'] <=> $b['best'] ); } );
	uasort( $titles, static function ( $a, $b ) { return ( $b['n'] <=> $a['n'] ) ?: strcmp( (string) $b['year'], (string) $a['year'] ); } );
	usort( $u, static function ( $a, $b ) { return $a['order'] <=> $b['order']; } );
	return array( $people, $titles, $u );
}

/**
 * The "olası eş" cell of the missing-productions list. A one-episode TMDB
 * credit under a known anthology umbrella (KBS Drama Special and the like) is
 * usually a single work the site already holds under another TMDB id, so the
 * same actor's films and one-episode dramas that no TMDB credit is matched to
 * are offered. Nothing is written: once the source confirms a pair, the id goes
 * into daw_tmdb_alt on that record.
 */
function daw_covp_alt_cell( $key, $t ) {
	if ( ! function_exists( 'daw_cov_alt_candidates' ) || (int) ( $t['eps'] ?? 0 ) !== 1 || ! daw_cov_is_anthology( (string) $t['title'] ) ) {
		return '';
	}
	$out = array();
	foreach ( array_unique( (array) ( $t['pids'] ?? array() ) ) as $pid ) {
		foreach ( daw_cov_alt_candidates( (int) $pid, (int) $t['year'] ) as $c ) {
			$out[ $c ] = sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $c ) ), esc_html( get_the_title( $c ) ) );
		}
	}
	return $out ? implode( ' · ', array_slice( $out, 0, 4 ) ) . '<br><span style="color:#50575e">daw_tmdb_alt: ' . esc_html( $key ) . '</span>' : '';
}

function daw_covp_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$reports = daw_covp_reports();
	list( $people, $titles, $unlinked ) = daw_covp_aggregate( $reports );
	global $wpdb;
	// phpcs:ignore WordPress.DB
	$eligible = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'ok'", DAW_TMDB_CHECK ) );
	$tab      = sanitize_key( $_GET['tab'] ?? 'unlinked' ); // phpcs:ignore WordPress.Security.NonceVerification
	$base     = admin_url( 'admin.php?page=daw-kapsam' );

	echo '<div class="wrap"><h1>DAW › Kapsam: kadro ve filmografi eksikleri</h1>';
	printf(
		'<p style="max-width:70em">TMDB\'nin kadro ve filmografi listesi sitedeki kayıtlarla karşılaştırılır. Taranan: <strong>%d / %d</strong> kayıt (TMDB eşleşmesi doğrulanmış olanlar). Her kayıt haftada bir yeniden taranır; kişinin yeni bir işi çıkınca burada "yeni" olarak görünür. Yeni kayıt açmadan önce kanibalizasyon ve TMDB kapıları (K19, K23) yine geçerlidir.</p>',
		count( $reports ),
		$eligible
	);
	$nonce = wp_create_nonce( 'daw_covp_run' );
	echo '<p><button type="button" class="button button-primary" id="daw-covp-run">Şimdi tara</button> <span id="daw-covp-msg" style="margin-left:8px;color:#50575e"></span></p>';
	?>
	<script>
	(function () {
		var b = document.getElementById('daw-covp-run'), m = document.getElementById('daw-covp-msg'), done = 0;
		b.addEventListener('click', function () {
			b.disabled = true;
			(function step() {
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'daw_covp_run', nonce: <?php echo wp_json_encode( $nonce ); ?> }) })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						if (!d || !d.success) { m.textContent = 'Hata, 5 sn sonra yeniden denenecek.'; setTimeout(step, 5000); return; }
						done += d.data.done;
						m.textContent = done + ' kayıt tarandı.';
						if (d.data.done > 0) { setTimeout(step, 400); } else { m.textContent = 'Bitti: ' + done + ' kayıt. Sayfa yenileniyor.'; setTimeout(function () { location.reload(); }, 800); }
					})
					.catch(function () { setTimeout(step, 5000); });
			})();
		});
	})();
	</script>
	<?php
	$tabs = array(
		'unlinked' => sprintf( 'Bağlanmamış (%d)', count( $unlinked ) ),
		'people'   => sprintf( 'Kaydı olmayan kişiler (%d)', count( $people ) ),
		'titles'   => sprintf( 'Kaydı olmayan yapımlar (%d)', count( $titles ) ),
	);
	echo '<h2 class="nav-tab-wrapper">';
	foreach ( $tabs as $k => $l ) {
		printf( '<a class="nav-tab%s" href="%s">%s</a>', $tab === $k ? ' nav-tab-active' : '', esc_url( add_query_arg( 'tab', $k, $base ) ), esc_html( $l ) );
	}
	echo '</h2>';

	echo '<table class="widefat striped" style="max-width:1300px"><thead><tr>';
	if ( 'people' === $tab ) {
		echo '<th>Kişi</th><th>TMDB</th><th style="text-align:center">Kaç yapımımızda</th><th style="text-align:center">En iyi sıra</th><th>Nerede</th></tr></thead><tbody>';
		foreach ( array_slice( $people, 0, 300, true ) as $id => $p ) {
			printf(
				'<tr><td><strong>%s</strong></td><td><a href="%s" target="_blank" rel="noopener">%d</a></td><td style="text-align:center">%d</td><td style="text-align:center">%d</td><td style="font-size:12px">%s</td></tr>',
				esc_html( $p['name'] ),
				esc_url( 'https://www.themoviedb.org/person/' . $id ),
				(int) $id,
				(int) $p['n'],
				(int) $p['best'] + 1,
				esc_html( implode( ' · ', array_slice( $p['in'], 0, 6 ) ) )
			);
		}
	} elseif ( 'titles' === $tab ) {
		echo '<th>Yapım</th><th>Tür</th><th>Yıl</th><th>TMDB</th><th style="text-align:center">Kaç kişimiz</th><th>Kimler</th><th>Olası eş</th></tr></thead><tbody>';
		$kinds = array( 'drama' => 'Dizi', 'film' => 'Film', 'variety' => 'Varyete', 'anime' => 'Anime' );
		foreach ( array_slice( $titles, 0, 300, true ) as $k => $t ) {
			list( $media, $tid ) = explode( ':', $k );
			printf(
				'<tr><td><strong>%s</strong>%s</td><td>%s</td><td>%s</td><td><a href="%s" target="_blank" rel="noopener">%s</a></td><td style="text-align:center">%d</td><td style="font-size:12px">%s</td><td style="font-size:12px">%s</td></tr>',
				esc_html( $t['title'] ),
				$t['soon'] ? ' <span style="background:#f6ecc9;color:#6a4d0c;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:600">yeni / yakında</span>' : '',
				esc_html( $kinds[ $t['kind'] ] ?? $t['kind'] ),
				esc_html( $t['year'] ),
				esc_url( 'https://www.themoviedb.org/' . $media . '/' . (int) $tid ),
				esc_html( $k ),
				(int) $t['n'],
				esc_html( implode( ' · ', array_slice( $t['who'], 0, 6 ) ) ),
				daw_covp_alt_cell( $k, $t )
			);
		}
	} else {
		echo '<th>Yapım</th><th>Kişi</th><th>Rol</th><th style="text-align:center">TMDB sırası</th><th></th></tr></thead><tbody>';
		foreach ( array_slice( $unlinked, 0, 300 ) as $u ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td style="text-align:center">%s</td><td><a class="button button-small" href="%s">Kadroyu düzenle</a></td></tr>',
				esc_html( get_the_title( $u['title'] ) ),
				esc_html( get_the_title( $u['person'] ) ),
				esc_html( $u['role'] ),
				999 === $u['order'] ? '' : (int) $u['order'] + 1,
				esc_url( (string) get_edit_post_link( $u['title'] ) )
			);
		}
	}
	echo '</tbody></table>';
	echo '<p style="color:#50575e">En çok 300 satır gösterilir; sıralama önem sırasıdır (kaç kaydımızı etkilediği, sonra TMDB kadro sırası).</p></div>';
}

add_action( 'wp_ajax_daw_covp_run', 'daw_covp_run' );
function daw_covp_run() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'daw_covp_run', 'nonce', false ) ) {
		wp_send_json_error( 'no', 403 );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 90 ); // phpcs:ignore
	}
	wp_send_json_success( array( 'done' => (int) daw_cov_cron_run( 8 ) ) );
}

/* ======================================================= control centre === */

/**
 * The Kapsam page's numbers, small enough for the control centre.
 *
 * Reading every `_daw_cov` report is the heavy part, so it is done with the
 * archive snapshot (twice a day) and kept as one option; the control centre
 * only reads that option. The per-record table answers "this person has ten
 * films on TMDB, how many are on the site": total, linked, record but not
 * linked (fixable now), no record.
 */
function daw_covp_summary_build() {
	$reports = daw_covp_reports();
	list( $people, $titles, $unlinked ) = daw_covp_aggregate( $reports );
	global $wpdb;
	// phpcs:ignore WordPress.DB
	$eligible = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'ok'", DAW_TMDB_CHECK ) );

	$credits = 0;
	$linked  = 0;
	$rows    = array();
	foreach ( $reports as $pid => $r ) {
		$c   = (array) ( $r['count'] ?? array() );
		$l   = (int) ( $c['linked'] ?? 0 );
		$u   = (int) ( $c['unlinked'] ?? 0 );
		$m   = (int) ( $c['missing'] ?? 0 );
		$credits += (int) ( $r['total'] ?? 0 );
		$linked  += $l;
		if ( $u || $m ) {
			$rows[] = array( 'id' => (int) $pid, 'kind' => (string) ( $r['kind'] ?? '' ), 'total' => (int) ( $r['total'] ?? 0 ), 'linked' => $l, 'unlinked' => $u, 'missing' => $m );
		}
	}
	usort( $rows, static fn( $a, $b ) => ( $b['unlinked'] <=> $a['unlinked'] ) ?: ( $b['missing'] <=> $a['missing'] ) );

	$sum = array(
		'at'       => time(),
		'scanned'  => count( $reports ),
		'eligible' => $eligible,
		'credits'  => $credits,
		'linked'   => $linked,
		'unlinked' => count( $unlinked ),
		'people'   => count( $people ),
		'titles'   => count( $titles ),
		'soon'     => count( array_filter( $titles, static fn( $t ) => ! empty( $t['soon'] ) ) ),
		'top_p'    => array_map( static fn( $p ) => array( $p['name'], (int) $p['n'] ), array_slice( array_values( $people ), 0, 5 ) ),
		'top_t'    => array_map( static fn( $t ) => array( $t['title'], (int) $t['n'], ! empty( $t['soon'] ) ), array_slice( array_values( $titles ), 0, 5 ) ),
		'rows'     => array_slice( $rows, 0, 12 ),
	);
	update_option( 'daw_cov_sum', $sum, false );
	daw_covp_rec_build( $reports );
	return $sum;
}

/* ====================================================== per record === */

/**
 * id => [type, TMDB total, linked, record-but-not-linked, no record], one
 * small option for the whole archive, so the İşler list can print "30 / 50"
 * on every row without reading 1,200 large reports. Rebuilt with the summary
 * and kept current one record at a time as the weekly comparison runs.
 */
function daw_covp_rec_row( array $r ) {
	$c = (array) ( $r['count'] ?? array() );
	return array( (int) ( $r['total'] ?? 0 ), (int) ( $c['linked'] ?? 0 ), (int) ( $c['unlinked'] ?? 0 ), (int) ( $c['missing'] ?? 0 ) );
}

function daw_covp_types_of( array $ids ) {
	if ( ! $ids ) {
		return array();
	}
	global $wpdb;
	$in = implode( ',', array_map( 'intval', $ids ) );
	// phpcs:ignore WordPress.DB
	$rows = $wpdb->get_results(
		"SELECT r.object_id, t.slug FROM {$wpdb->term_relationships} r
		   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = 'dw_type'
		   JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		  WHERE r.object_id IN ($in)",
		ARRAY_N
	);
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[ (int) $r[0] ] = (string) $r[1];
	}
	return $out;
}

function daw_covp_rec_build( $reports = null ) {
	$reports = null === $reports ? daw_covp_reports() : $reports;
	$types   = daw_covp_types_of( array_keys( $reports ) );
	$rec     = array();
	foreach ( $reports as $pid => $r ) {
		$rec[ (int) $pid ] = array_merge( array( $types[ (int) $pid ] ?? '' ), daw_covp_rec_row( $r ) );
	}
	update_option( 'daw_cov_rec', $rec, false );
	return $rec;
}

function daw_covp_rec() {
	$r = get_option( 'daw_cov_rec' );
	if ( ! is_array( $r ) ) {
		$r = daw_covp_rec_build();
	}
	return $r;
}

/** The weekly comparison wrote a new report: update that one row. */
add_action( 'added_post_meta', 'daw_covp_rec_on_change', 10, 4 );
add_action( 'updated_post_meta', 'daw_covp_rec_on_change', 10, 4 );
function daw_covp_rec_on_change( $mid, $post_id, $key, $value ) {
	if ( DAW_COV !== $key || ! is_array( $value ) ) {
		return;
	}
	// A new comparison can open or close a coverage line (a new film, a cast
	// change): the stored audit is refreshed, so Tam/Temel follows by itself.
	if ( function_exists( 'daw_ae_prep_store' ) && ! wp_next_scheduled( 'daw_ae_one', array( (int) $post_id ) ) ) {
		wp_schedule_single_event( time() + 120, 'daw_ae_one', array( (int) $post_id ) );
	}
	$rec = get_option( 'daw_cov_rec' );
	if ( ! is_array( $rec ) ) {
		return; // built in full on first read.
	}
	$t                       = daw_covp_types_of( array( (int) $post_id ) );
	$rec[ (int) $post_id ] = array_merge( array( $t[ (int) $post_id ] ?? '' ), daw_covp_rec_row( $value ) );
	update_option( 'daw_cov_rec', $rec, false );
}

/** Totals per record type, from the per-record rows. */
function daw_covp_by_type() {
	$out = array();
	foreach ( daw_covp_rec() as $row ) {
		$t = '' !== $row[0] ? $row[0] : 'other';
		if ( ! isset( $out[ $t ] ) ) {
			$out[ $t ] = array( 'n' => 0, 'total' => 0, 'linked' => 0, 'unlinked' => 0, 'missing' => 0, 'full' => 0 );
		}
		$out[ $t ]['n']++;
		$out[ $t ]['total']    += $row[1];
		$out[ $t ]['linked']   += $row[2];
		$out[ $t ]['unlinked'] += $row[3];
		$out[ $t ]['missing']  += $row[4];
		if ( $row[1] && $row[2] >= $row[1] ) {
			$out[ $t ]['full']++;
		}
	}
	$order = array_flip( array( 'drama', 'film', 'variety', 'anime', 'person', 'group' ) );
	uksort( $out, static fn( $a, $b ) => ( $order[ $a ] ?? 9 ) <=> ( $order[ $b ] ?? 9 ) );
	return $out;
}

/** The strip at the top of DAW › İşler: how full each type is against TMDB. */
function daw_covp_strip() {
	$by = daw_covp_by_type();
	if ( ! $by ) {
		return;
	}
	$names = array( 'drama' => 'Dizi', 'film' => 'Film', 'variety' => 'Varyete', 'anime' => 'Anime', 'person' => 'Kişi', 'group' => 'Grup' );
	echo '<div class="wc-box"><strong>TMDB\'ye göre doluluk</strong> <span style="color:#50575e">(kadro ve filmografi: TMDB\'deki her satır sitede bağlı mı)</span>';
	echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:10px 0 6px">';
	foreach ( $by as $t => $b ) {
		$pct = $b['total'] ? (int) floor( 100 * $b['linked'] / $b['total'] ) : 0;
		$col = $pct >= 80 ? '#1f6f5c' : ( $pct >= 50 ? '#7a5a12' : '#a8431a' );
		printf(
			'<div style="min-width:170px;border:1px solid #dcdcde;border-radius:10px;padding:8px 12px"><div style="font-weight:600">%s <span style="color:#50575e;font-weight:400">· %d kayıt</span></div><div style="font-size:22px;font-weight:600;color:%s">%%%d</div><div style="font-size:12px;color:#50575e">%s / %s bağlı<br>%s hemen bağlanabilir · %s kaydı yok<br>%d kayıt tam</div></div>',
			esc_html( $names[ $t ] ?? ucfirst( $t ) ),
			(int) $b['n'],
			esc_attr( $col ),
			$pct,
			esc_html( number_format_i18n( $b['linked'] ) ),
			esc_html( number_format_i18n( $b['total'] ) ),
			esc_html( number_format_i18n( $b['unlinked'] ) ),
			esc_html( number_format_i18n( $b['missing'] ) ),
			(int) $b['full']
		);
	}
	echo '</div><p class="wc-need" style="margin:4px 0 0">Nereden: TMDB API. TMDB eşleşmesi doğrulanmış her kayıt haftada bir karşılaştırılır (dizi/film için kadro, kişi için dizi, film, varyete ve anime işleri). Listedeki "TMDB" sütunu kaydın kendi sayısıdır; tam liste <a href="' . esc_url( admin_url( 'admin.php?page=daw-kapsam' ) ) . '">Kapsam</a> sayfasında.</p></div>';
}

add_action( 'daw_snapshot_cron', 'daw_covp_summary_build', 62 );
add_action( 'daw_kc_run_all_late', 'daw_covp_summary_build', 62 );

function daw_covp_summary() {
	$s = get_option( 'daw_cov_sum' );
	return is_array( $s ) ? $s : array();
}

/** The control-centre card: TMDB's credit lists against the site. */
function daw_covp_card( $kc ) {
	$s  = daw_covp_summary();
	$st = ! $s ? 'info' : ( $s['unlinked'] ? 'warn' : 'ok' );
	daw_kc_card_open( 'cov', '⇄', 'Kapsam: TMDB ile sitedeki kayıtlar', $st, true );
	echo '<ul class="kc-checks">';
	if ( ! $s ) {
		daw_kc_check( true, 'İlk özet arşiv özetiyle birlikte (günde iki kez) ya da "Tüm kontrolleri çalıştır" ile çıkar.', 'info' );
		echo '</ul>';
		daw_kc_card_close( array( 'Kapsam' => daw_menu_url( 'daw-kapsam' ) ) );
		return;
	}
	$pct = $s['credits'] ? (int) round( 100 * $s['linked'] / $s['credits'] ) : 0;
	daw_kc_check( true, sprintf( 'TMDB ile karşılaştırılan %s / %s kayıt. TMDB\'deki %s kadro ve filmografi satırının %s tanesi (%%%d) sitede bağlı.', daw_kc_n( $s['scanned'] ), daw_kc_n( $s['eligible'] ), daw_kc_n( $s['credits'] ), daw_kc_n( $s['linked'] ), $pct ), 'info' );
	daw_kc_check( ! $s['unlinked'], sprintf( '%s bağlantı eksik: kişi de yapım da sitede var, ama kadroda birbirine bağlı değil. Hemen düzeltilebilir.', daw_kc_n( $s['unlinked'] ) ), $s['unlinked'] ? 'warn' : 'ok' );
	daw_kc_check( true, sprintf( 'Kaydı olmayan %s kişi. En çok geçen: %s.', daw_kc_n( $s['people'] ), implode( ', ', array_map( static fn( $p ) => $p[0] . ' (' . $p[1] . ')', $s['top_p'] ) ) ?: '-' ), 'info' );
	daw_kc_check( true, sprintf( 'Kaydı olmayan %s yapım, %s tanesi yeni ya da yakında. En çok geçen: %s.', daw_kc_n( $s['titles'] ), daw_kc_n( $s['soon'] ), implode( ', ', array_map( static fn( $t ) => $t[0] . ' (' . $t[1] . ')', $s['top_t'] ) ) ?: '-' ), 'info' );
	echo '</ul>';
	if ( function_exists( 'daw_castlink_button' ) ) {
		daw_castlink_button( (int) $s['unlinked'] );
	}
	if ( $s['rows'] ) {
		echo '<div class="kc-scroll"><table class="kc-mini"><thead><tr><th>Kayıt</th><th>TMDB\'de</th><th>Sitede bağlı</th><th>Kaydı var, bağlı değil</th><th>Kaydı yok</th></tr></thead><tbody>';
		foreach ( $s['rows'] as $r ) {
			printf(
				'<tr class="is-%s"><td><a href="%s">%s</a> <span class="kc-muted">%s</span></td><td>%d</td><td>%d</td><td>%d</td><td>%d</td></tr>',
				$r['unlinked'] ? 'warn' : 'info',
				esc_url( (string) get_edit_post_link( $r['id'] ) ),
				esc_html( get_the_title( $r['id'] ) ),
				'person' === $r['kind'] ? 'kişi' : 'yapım',
				(int) $r['total'],
				(int) $r['linked'],
				(int) $r['unlinked'],
				(int) $r['missing']
			);
		}
		echo '</tbody></table></div>';
	}
	printf( '<p class="kc-muted">Her kayıt haftada bir TMDB ile karşılaştırılır. Özet %s.</p>', esc_html( daw_kc_ago( (int) $s['at'] ) ) );
	daw_kc_card_close( array( 'Kapsam (tam liste)' => daw_menu_url( 'daw-kapsam' ) ) );
}

/** For /daw/v1/kc-brief. */
function daw_covp_brief() {
	$s = daw_covp_summary();
	return $s ? array_intersect_key( $s, array_flip( array( 'scanned', 'eligible', 'credits', 'linked', 'unlinked', 'people', 'titles', 'soon' ) ) ) : array();
}
