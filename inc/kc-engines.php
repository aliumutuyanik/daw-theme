<?php
/**
 * Discover Asian World: the audit engines, visible and startable.
 *
 * Each engine runs by itself on a schedule. This puts all of them on the
 * control centre in one place: what it checks, when it last ran, what it
 * found, and a button to run it now. The findings are listed in full
 * (which page, where on the page, the address, what went wrong), so
 * nothing has to be looked up elsewhere.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** id => [name, what it checks, callable, last-run reader, result reader]. */
function daw_kce_engines() {
	return array(
		'img' => array(
			'Kırık görsel',
			'Her sayfadaki afiş, portre ve yazı içi görseller tek tek açılıp denenir',
			static fn() => function_exists( 'daw_ick_run' ) ? daw_ick_run( 150 ) : null,
			static fn() => (int) ( get_option( 'daw_imgchk', array() )['at'] ?? 0 ),
			static function () {
				$st = get_option( 'daw_imgchk', array() );
				$n  = function_exists( 'daw_ick_rows' ) ? count( daw_ick_rows() ) : 0;
				$lap = (int) ( $st['lap_done'] ?? 0 );
				return array( $n ? 'bad' : 'ok', sprintf( '%d kırık · tur: %d/%d sayfa%s', $n, (int) ( $st['pos'] ?? 0 ), (int) ( $st['total'] ?? 0 ), $lap ? ' · son tam tur ' . daw_kc_ago( $lap ) : '' ) );
			},
		),
		'rd'  => array(
			'Yönlendirmeler',
			'Her yönlendirme: hedef canlı mı, döngü, zincir, ana sayfa, alakasız hedef',
			static fn() => function_exists( 'daw_rda_run' ) ? daw_rda_run() : null,
			static fn() => (int) ( get_option( 'daw_rda', array() )['at'] ?? 0 ),
			static function () {
				$r   = get_option( 'daw_rda', array() );
				$bad = 0;
				foreach ( (array) ( $r['count'] ?? array() ) as $k => $n ) {
					$bad += in_array( $k, array( 'external', 'dormant' ), true ) ? 0 : (int) $n;
				}
				return array( $bad ? 'warn' : 'ok', sprintf( '%d kural · %d sorunlu · son çalışmada %d düzeltildi', (int) ( $r['total'] ?? 0 ), $bad, (int) ( $r['fixed'] ?? 0 ) ) );
			},
		),
		'nf'  => array(
			'404 onarımı',
			'Okurun düştüğü 404 adresleri doğru sayfaya bağlanır, tarayıcı adresleri ayıklanır',
			static function () {
				if ( function_exists( 'daw_nf_reclassify' ) ) {
					delete_option( 'daw_nf_kind_v' );
					daw_nf_reclassify();
				}
				return function_exists( 'daw_nf_autofix_run' ) ? daw_nf_autofix_run() : null;
			},
			static function () {
				$log  = (array) get_option( 'daw_nf_autofix_log', array() );
				$last = $log ? $log[ array_key_last( $log ) ] : array();
				return (int) ( $last['at'] ?? 0 );
			},
			static function () {
				$r = (array) get_option( 'daw_kc_report', array() );
				$n = (int) ( $r['areas']['seo']['open404'] ?? 0 );
				return array( $n ? 'warn' : 'ok', sprintf( '%d açık 404', $n ) );
			},
		),
		'sec' => array(
			'Güvenlik ve gizlilik',
			'Site dışarıdan, girişsiz bir ziyaretçi gibi yoklanır',
			static fn() => function_exists( 'daw_sec_run' ) ? daw_sec_run() : null,
			static fn() => (int) ( get_option( 'daw_sec_check', array() )['at'] ?? 0 ),
			static function () {
				$s = function_exists( 'daw_sec_data' ) ? daw_sec_data() : array( 'bad' => 0, 'rows' => array() );
				return array( $s['bad'] ? 'bad' : 'ok', sprintf( '%d kontrol · %d sorun', count( $s['rows'] ), (int) $s['bad'] ) );
			},
		),
		'news' => array(
			'Eskiyen haberler',
			'Canlı haberler (sıradaki proje, terhis, yayında) 30 günde bir, tarih geçince ya da bahsedilen dizinin durumu değişince listeye düşer',
			static fn() => function_exists( 'daw_news_stale_run' ) ? daw_news_stale_run() : null,
			static fn() => (int) ( get_option( 'daw_news_stale', array() )['at'] ?? 0 ),
			static function () {
				$r = get_option( 'daw_news_stale', array() );
				$n = count( (array) ( $r['rows'] ?? array() ) );
				return array( $n ? 'warn' : 'ok', sprintf( '%d haber · %d canlı · %d kontrol bekliyor', (int) ( $r['total'] ?? 0 ), (int) ( $r['live'] ?? 0 ), $n ) );
			},
		),
		'opp' => array(
			'Arama fırsatları',
			'Search Console aramaları kalıp listesiyle karşılaştırılır; ilk sayfada tıklanmayanlar ayrıca',
			static fn() => function_exists( 'daw_opp_compute' ) ? daw_opp_compute() : null,
			static fn() => (int) ( get_option( 'daw_opps', array() )['at'] ?? 0 ),
			static function () {
				$o = get_option( 'daw_opps', array() );
				$n = function_exists( 'daw_opp_rows' ) ? count( daw_opp_rows() ) : 0;
				$c = count( (array) ( $o['ctr'] ?? array() ) );
				return array( ( $n || $c ) ? 'info' : 'ok', sprintf( '%d fırsat · %d tıklanmayan ilk sayfa', $n, $c ) );
			},
		),
		'ga4' => array(
			'Analytics (trafik kalitesi)',
			'Google Analytics okunur: gün gün kanal, etkileşim ve "Direct" trafiğin nereden geldiği',
			static fn() => function_exists( 'daw_ga4_run' ) ? daw_ga4_run() : null,
			static fn() => (int) ( get_option( 'daw_ga4_report', array() )['at'] ?? 0 ),
			static function () {
				$r = get_option( 'daw_ga4_report', array() );
				if ( ! empty( $r['error'] ) ) {
					return array( 'bad', (string) $r['error'] );
				}
				$q   = daw_kce_quality();
				$day = $q ? end( $q ) : null;
				return array( 'info', $day ? sprintf( 'Dün: %d oturum, %d etkileşimli, Direct %d', $day['all'], $day['eng'], $day['direct'] ) : 'veri bekleniyor' );
			},
		),
		'lic' => array(
			'Görsel lisansları',
			'Lisans kaydı olmayan görseller Wikimedia Commons\'ta dosyanın kendisiyle aranır',
			static fn() => function_exists( 'daw_cm_run' ) ? daw_cm_run( 20 ) : null,
			static fn() => 0,
			static function () {
				$r = (array) get_option( 'daw_kc_report', array() );
				$n = (int) ( $r['areas']['pictures']['unreg'] ?? 0 );
				return array( $n ? 'warn' : 'ok', sprintf( '%d lisanssız görsel', $n ) );
			},
		),
	);
}

add_action( 'admin_post_daw_kc_engine', 'daw_kce_run' );
function daw_kce_run() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Yetki yok.' );
	}
	check_admin_referer( 'daw_kc_engine' );
	$id  = sanitize_key( wp_unslash( $_POST['engine'] ?? '' ) );
	$all = daw_kce_engines();
	$ran = array();
	foreach ( 'all' === $id ? array_keys( $all ) : array( $id ) as $k ) {
		if ( isset( $all[ $k ] ) ) {
			call_user_func( $all[ $k ][2] );
			update_option( 'daw_kce_at_' . $k, time(), false );
			$ran[] = $k;
		}
	}
	// One picture again, from the broken list.
	$pid = (int) ( $_POST['pid'] ?? 0 );
	if ( $pid && function_exists( 'daw_ick_urls' ) ) {
		$st  = get_option( 'daw_imgchk', array() );
		$bad = (array) ( $st['bad'] ?? array() );
		unset( $bad[ $pid ] );
		if ( function_exists( 'daw_tmdb_img_sync' ) ) {
			daw_tmdb_img_sync( $pid );
		}
		foreach ( daw_ick_urls( $pid ) as $u ) {
			$c = daw_ick_probe( $u[0] );
			if ( 200 !== $c ) {
				$bad[ $pid ] = array( $u[0], $u[1], $c, time() );
				break;
			}
		}
		$st['bad'] = $bad;
		update_option( 'daw_imgchk', $st, false );
		$ran[] = 'img-one';
	}
	if ( function_exists( 'daw_kc_store_report' ) ) {
		daw_kc_store_report();
	}
	wp_safe_redirect( add_query_arg( array( 'page' => DAW_MENU, 'daw_kce' => implode( ',', $ran ) ), admin_url( 'admin.php' ) ) . '#kc-engines' );
	exit;
}

function daw_kce_button( $engine, $label, $pid = 0 ) {
	return sprintf(
		'<form method="post" action="%s" style="display:inline">%s<input type="hidden" name="action" value="daw_kc_engine"><input type="hidden" name="engine" value="%s">%s<button type="submit" class="button button-small">%s</button></form>',
		esc_url( admin_url( 'admin-post.php' ) ),
		wp_nonce_field( 'daw_kc_engine', '_wpnonce', true, false ),
		esc_attr( $engine ),
		$pid ? '<input type="hidden" name="pid" value="' . (int) $pid . '">' : '',
		esc_html( $label )
	);
}

/** What an HTTP answer means, in words. */
function daw_kce_code_word( $c ) {
	$c = (int) $c;
	if ( 0 === $c ) {
		return 'yanıt yok (bağlantı kurulamadı)';
	}
	if ( 404 === $c || 410 === $c ) {
		return 'dosya yok (' . $c . ')';
	}
	if ( 403 === $c ) {
		return 'erişim reddedildi (403)';
	}
	if ( 415 === $c ) {
		return 'görsel değil, başka bir şey döndü';
	}
	return 'HTTP ' . $c;
}

/** The engines table and the detail lists, inside the control centre grid. */
function daw_kce_render() {
	if ( isset( $_GET['daw_kce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf( '<div class="notice notice-success" style="grid-column:1/-1"><p><strong>Çalıştırıldı:</strong> %s. Sonuçlar aşağıda.</p></div>', esc_html( sanitize_text_field( wp_unslash( $_GET['daw_kce'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/* engines */
	daw_kc_card_open( 'engines', '⚙', 'Denetim motorları', 'info', true );
	echo '<p class="kc-muted">Hepsi kendiliğinden çalışır (saatte ya da günde bir). İstediğin an buradan da çalıştırabilirsin.</p>';
	echo '<table class="kc-mini"><thead><tr><th>Motor</th><th>Ne yapar</th><th>Son çalışma</th><th>Sonuç</th><th></th></tr></thead><tbody>';
	foreach ( daw_kce_engines() as $id => $e ) {
		$at = max( (int) call_user_func( $e[3] ), (int) get_option( 'daw_kce_at_' . $id, 0 ) );
		list( $st, $txt ) = call_user_func( $e[4] );
		printf(
			'<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td class="is-%s">%s</td><td>%s</td></tr>',
			esc_html( $e[0] ),
			esc_html( $e[1] ),
			esc_html( daw_kc_ago( $at ) ),
			esc_attr( $st ),
			esc_html( $txt ),
			daw_kce_button( $id, 'Şimdi çalıştır' ) // phpcs:ignore WordPress.Security.EscapeOutput -- built above.
		);
	}
	echo '</tbody></table>';
	echo '<p style="margin-top:10px">' . daw_kce_button( 'all', '↻ Bütün denetim motorlarını şimdi çalıştır' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	daw_kc_card_close();

	/* stale news, in full */
	$ns = get_option( 'daw_news_stale', array() );
	$nr = (array) ( $ns['rows'] ?? array() );
	daw_kc_card_open( 'news-stale', '✎', 'Eskiyen haberler', $nr ? 'warn' : 'ok', true );
	echo '<p class="kc-muted">Canlı haber: sıradaki proje, yayında olan dizi, terhis gibi kendiliğinden eskiyen haberler. Kontrol edince haberin "Last checked" alanına bugünün tarihini yaz; 30 gün sonra tekrar buraya düşer. Haberin "What\'s next" satırları oyuncunun sayfasındaki "upcoming" kutusuna da gider. Bir satır sonuçlandıysa sonuna <code>| done</code> yaz ("tarih | ne | kayıt no | done"): o satır bir daha uyarı vermez, iki kutudan da kalkar.</p>';
	if ( ! $nr ) {
		echo '<p>Kontrol bekleyen haber yok.</p>';
	} else {
		echo '<table class="kc-mini"><thead><tr><th>Haber</th><th>Tür</th><th>Neden</th><th>Son kontrol</th></tr></thead><tbody>';
		foreach ( $nr as $r ) {
			printf(
				'<tr><td><a href="%s" target="_blank" rel="noopener">%s</a> · <a href="%s">düzenle</a></td><td>%s</td><td class="is-warn">%s</td><td>%s</td></tr>',
				esc_url( (string) $r['url'] ),
				esc_html( (string) $r['title'] ),
				esc_url( (string) get_edit_post_link( (int) $r['id'] ) ),
				! empty( $r['live'] ) ? 'canlı' : 'bitmiş',
				esc_html( (string) $r['reason'] ),
				! empty( $r['checked'] ) ? esc_html( gmdate( 'j M Y', (int) $r['checked'] ) ) : 'hiç'
			);
		}
		echo '</tbody></table>';
	}
	daw_kc_card_close();

	/* broken pictures, in full */
	$rows  = function_exists( 'daw_ick_rows' ) ? daw_ick_rows() : array();
	$where = array( 'record' => 'Afiş / portre (sayfanın ana görseli)', 'featured' => 'Öne çıkan görsel', 'content' => 'Yazının içinde' );
	daw_kc_card_open( 'broken-img', '▣', 'Kırık görseller', $rows ? 'bad' : 'ok', true );
	if ( ! $rows ) {
		echo '<p class="kc-muted">Kırık görsel yok. Her sayfa sırayla denenir; bir görsel açılmazsa burada sayfası, yeri ve adresiyle görünür.</p>';
	} else {
		echo '<table class="kc-mini"><thead><tr><th>Sayfa</th><th>Görselin yeri</th><th>Görsel adresi</th><th>Hata</th><th>Ne zaman</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$b = get_option( 'daw_imgchk', array() )['bad'][ $r[0] ] ?? array();
			printf(
				'<tr><td><a href="%s" target="_blank" rel="noopener">%s</a> · <a href="%s">düzenle</a></td><td>%s</td><td><a href="%s" target="_blank" rel="noopener">%s</a></td><td class="is-bad">%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( get_permalink( $r[0] ) ),
				esc_html( $r[1] ),
				esc_url( (string) get_edit_post_link( $r[0] ) ),
				esc_html( $where[ $r[3] ] ?? $r[3] ),
				esc_url( $r[2] ),
				esc_html( wp_html_excerpt( (string) wp_parse_url( $r[2], PHP_URL_PATH ), 48, '…' ) ),
				esc_html( daw_kce_code_word( $r[4] ) ),
				esc_html( daw_kc_ago( (int) ( $b[3] ?? 0 ) ) ),
				daw_kce_button( 'none', 'Yeniden dene', $r[0] ) // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}
		echo '</tbody></table>';
	}
	daw_kc_card_close();

	/* redirects that need a decision */
	$rda = get_option( 'daw_rda', array() );
	$lab = function_exists( 'daw_rda_labels' ) ? daw_rda_labels() : array();
	$rr  = array_filter( (array) ( $rda['rows'] ?? array() ), static fn( $x ) => isset( $lab[ $x[3] ] ) && 'info' !== $lab[ $x[3] ][0] );
	daw_kc_card_open( 'rd-audit', '↪', 'Yönlendirme denetimi', $rr ? 'warn' : 'ok', true );
	if ( ! $rr ) {
		printf( '<p class="kc-muted">%d yönlendirmenin hepsi doğru yere gidiyor. Ana sayfaya yönlendirme yasak; ölü hedefler, zincirler ve tarayıcı adresleri her gün kendiliğinden düzeltilir.</p>', (int) ( $rda['total'] ?? 0 ) );
	} else {
		echo '<table class="kc-mini"><thead><tr><th>Eski adres</th><th>Gittiği yer</th><th>Sorun</th><th>Ayrıntı</th></tr></thead><tbody>';
		foreach ( array_slice( $rr, 0, 50 ) as $x ) {
			printf(
				'<tr><td>%s</td><td><a href="%s" target="_blank" rel="noopener">%s</a></td><td class="is-%s">%s</td><td>%s</td></tr>',
				esc_html( $x[1] ),
				esc_url( home_url( $x[2] ) ),
				esc_html( $x[2] ),
				esc_attr( $lab[ $x[3] ][0] ),
				esc_html( $lab[ $x[3] ][1] ),
				esc_html( $x[4] )
			);
		}
		echo '</tbody></table>';
	}
	daw_kc_card_close();

	/* search: first page without clicks, then the opportunities */
	$o   = get_option( 'daw_opps', array() );
	$ctr = (array) ( $o['ctr'] ?? array() );
	$opp = function_exists( 'daw_opp_rows' ) ? array_slice( daw_opp_rows(), 0, 15 ) : array();
	daw_kc_card_open( 'search-opps', '◎', 'Arama: ilk sayfa ve fırsatlar', ( $ctr || $opp ) ? 'info' : 'ok', true );
	if ( $ctr ) {
		echo '<p><strong>Google\'ın ilk sayfasında, ama tıklanmıyor</strong> (başlık ve açıklama işi):</p>';
		echo '<table class="kc-mini"><thead><tr><th>Sayfa</th><th>Arama</th><th>Sıra</th><th>Gösterim</th></tr></thead><tbody>';
		foreach ( $ctr as $c ) {
			printf( '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_url( (string) get_edit_post_link( (int) $c['pid'] ) ), esc_html( get_the_title( (int) $c['pid'] ) ), esc_html( $c['q'] ), esc_html( (string) $c['pos'] ), esc_html( daw_kc_n( $c['imp'] ) ) );
		}
		echo '</tbody></table>';
	}
	if ( $opp ) {
		echo '<p style="margin-top:12px"><strong>Sayfada henüz yer almayan aramalar:</strong></p>';
		echo '<table class="kc-mini"><thead><tr><th>Sayfa</th><th>Arama</th><th>Sıra</th><th>Gösterim</th></tr></thead><tbody>';
		foreach ( $opp as $c ) {
			printf( '<tr><td><a href="%s">%s</a></td><td>%s%s</td><td>%s</td><td>%s</td></tr>', esc_url( (string) get_edit_post_link( (int) $c['pid'] ) ), esc_html( get_the_title( (int) $c['pid'] ) ), esc_html( $c['q'] ), ! empty( $c['new'] ) ? ' <em>yeni</em>' : '', esc_html( (string) $c['pos'] ), esc_html( daw_kc_n( $c['imp'] ) ) );
		}
		echo '</tbody></table>';
	}
	if ( ! $ctr && ! $opp ) {
		echo '<p class="kc-muted">Bekleyen arama fırsatı yok.</p>';
	}
	daw_kc_card_close( array( 'Bütün fırsatlar' => admin_url( 'admin.php?page=daw-firsat' ) ) );

	daw_kce_quality_card();
}

/** "Run all checks" on the control centre runs these too. */
add_action( 'daw_kc_run_all_late', 'daw_kce_run_all_late', 40 );
function daw_kce_run_all_late() {
	foreach ( array( 'rd', 'sec', 'opp', 'img' ) as $k ) {
		$e = daw_kce_engines()[ $k ] ?? null;
		if ( $e ) {
			call_user_func( $e[2] );
			update_option( 'daw_kce_at_' . $k, time(), false );
		}
	}
}

/** GA4 quality rows folded per day: date => [all, eng, direct, direct_eng, organic, ai]. */
function daw_kce_quality() {
	$r   = get_option( 'daw_ga4_report', array() );
	$out = array();
	foreach ( (array) ( $r['quality'] ?? array() ) as $row ) {
		list( $date, $ch, $ses, $eng ) = array( (string) $row[0], (string) $row[1], (int) $row[2], (int) $row[3] );
		$d = &$out[ $date ];
		$d = ( $d ?? array( 'date' => $date, 'all' => 0, 'eng' => 0, 'direct' => 0, 'direct_eng' => 0, 'organic' => 0, 'ai' => 0 ) );
		$d['all'] += $ses;
		$d['eng'] += $eng;
		if ( 'Direct' === $ch ) {
			$d['direct']     += $ses;
			$d['direct_eng'] += $eng;
		} elseif ( 'Organic Search' === $ch ) {
			$d['organic'] += $ses;
		} elseif ( 'AI Assistant' === $ch ) {
			$d['ai'] += $ses;
		}
		unset( $d );
	}
	ksort( $out );
	return $out;
}

/** The traffic quality card. */
function daw_kce_quality_card() {
	$q = daw_kce_quality();
	$r = get_option( 'daw_ga4_report', array() );
	daw_kc_card_open( 'ga4-quality', '◔', 'Analytics: gerçek okur mu, bot mu?', $q ? 'info' : 'warn', true );
	echo '<p class="kc-muted">Analytics yalnız iki ayrı gerçek harekette (dokunma, tıklama, kaydırma) ve 10 saniye ekranda kalındıktan sonra sayar (1.75.56); otomatik ve başsız tarayıcılar hiç sayılmaz. "Etkileşimli" oturum: 10 saniyeden uzun, ya da iki sayfa, ya da bir olay. Direct ama etkileşimsiz oturumlar bot şüphelisidir.</p>';
	if ( $q ) {
		echo '<table class="kc-mini"><thead><tr><th>Gün</th><th>Oturum</th><th>Etkileşimli</th><th>Direct</th><th>Direct etkileşimli</th><th>Google</th><th>Yapay zekâ</th></tr></thead><tbody>';
		foreach ( $q as $d ) {
			$ratio = $d['direct'] ? $d['direct_eng'] / $d['direct'] : 1;
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td class="is-%s">%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( substr( $d['date'], 6, 2 ) . '.' . substr( $d['date'], 4, 2 ) ),
				esc_html( daw_kc_n( $d['all'] ) ),
				esc_html( daw_kc_n( $d['eng'] ) ),
				$ratio < 0.2 && $d['direct'] > 20 ? 'warn' : 'ok',
				esc_html( daw_kc_n( $d['direct'] ) ),
				esc_html( daw_kc_n( $d['direct_eng'] ) ),
				esc_html( daw_kc_n( $d['organic'] ) ),
				esc_html( daw_kc_n( $d['ai'] ) )
			);
		}
		echo '</tbody></table>';
	}
	$geo = (array) ( $r['direct_geo'] ?? array() );
	if ( $geo ) {
		echo '<p style="margin-top:12px"><strong>"Direct" nereden geliyor (son 7 gün)</strong></p>';
		echo '<table class="kc-mini"><thead><tr><th>Ülke</th><th>Şehir</th><th>Cihaz</th><th>Oturum</th><th>Etkileşimli</th><th>Ort. süre</th></tr></thead><tbody>';
		foreach ( $geo as $g ) {
			$bot = (int) $g[3] > 5 && (int) $g[4] / max( 1, (int) $g[3] ) < 0.1;
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td class="is-%s">%s</td><td>%s</td><td>%s sn</td></tr>', esc_html( $g[0] ), esc_html( $g[1] ), esc_html( $g[2] ), $bot ? 'warn' : 'ok', esc_html( daw_kc_n( $g[3] ) ), esc_html( daw_kc_n( $g[4] ) ), esc_html( (string) round( (float) $g[5] ) ) );
		}
		echo '</tbody></table>';
	}
	$diag = (array) ( $r['diag'] ?? array() );
	if ( $diag ) {
		$names = array( 'hostName' => 'Bildirilen alan adı', 'landingPage' => 'İlk sayfa', 'browser' => 'Tarayıcı', 'screenResolution' => 'Ekran boyutu' );
		echo '<p style="margin-top:12px"><strong>Direct oturumların yapısı (son 7 gün)</strong> <span class="kc-muted">Alan adı sitemiz değilse ya da ekran "(not set)" ise vuruş siteyi hiç açmadan doğrudan Analytics\'e gönderilmiş demektir; bu durumda filtre ancak Analytics tarafında yapılabilir.</span></p>';
		echo '<table class="kc-mini"><thead><tr><th>Ölçü</th><th>Değer</th><th>Oturum</th><th>Etkileşimli</th></tr></thead><tbody>';
		foreach ( $diag as $dim => $rows ) {
			foreach ( $rows as $g ) {
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $names[ $dim ] ?? $dim ), esc_html( (string) $g[0] ), esc_html( daw_kc_n( $g[1] ) ), esc_html( daw_kc_n( $g[2] ) ) );
			}
		}
		echo '</tbody></table>';
	}
	if ( ! $q && ! $geo ) {
		echo '<p class="kc-muted">Veri bekleniyor: Analytics okuması günde bir çalışır, "Şimdi çalıştır" ile hemen okunur.</p>';
	}
	daw_kc_card_close();
}
