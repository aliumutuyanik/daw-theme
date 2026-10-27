<?php
/**
 * Discover Asian World: one button that does the whole picture move.
 *
 * WHY
 * ---
 * "Do 50, click again" was confusing (owner, 23 Sep 2026). He asked for one
 * button that goes through the whole site by itself, in a loop, and says at
 * the end that everything is done: every TMDB picture found, every backup
 * taken, every unneeded file gone from the media library and kept hidden in
 * the private backup.
 *
 * HOW
 * ---
 * The work still goes in small bites (20 records per step, rule 8.1 says at
 * most 50), because one long request would time out on shared hosting. While
 * the screen is open, the page asks for the next bite as soon as the last one
 * finishes, so it runs without clicking. If the screen is closed, WordPress's
 * scheduler carries on every 30 seconds until the job is finished.
 *
 *   step 1  sync    ask TMDB for every record's picture
 *   step 2  backup  copy each picture into the private backup
 *   step 3  clean   back up and remove unneeded media library files
 *   done            a summary with every number and every file left alone
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_IMG_JOB      = 'daw_img_job';
const DAW_IMG_JOB_TICK = 'daw_img_job_tick';
const DAW_IMG_JOB_BITE = 20;

function daw_img_job_get() {
	$j = get_option( DAW_IMG_JOB, array() );
	return is_array( $j ) ? $j : array();
}

function daw_img_job_put( $j ) {
	update_option( DAW_IMG_JOB, $j, false );
}

/** Start (or restart) the whole run. Returns '' or a reason it could not start. */
function daw_img_job_start() {
	if ( '' === daw_tmdb_key() ) {
		return 'Önce TMDB anahtarını kaydedin.';
	}
	if ( ! is_array( daw_tmdb_api_get( 'configuration' ) ) ) {
		return 'TMDB anahtarı kabul etmedi ya da TMDB\'ye ulaşılamadı. Anahtarı kontrol edin.';
	}

	update_option( 'daw_img_source', 'tmdb', false );

	// A fresh run retries everything that failed before.
	global $wpdb;
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_daw_tmdb_img_fail' ) ); // phpcs:ignore WordPress.DB
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_daw_vault_fail' ) ); // phpcs:ignore WordPress.DB

	daw_img_job_put(
		array(
			'state'   => 'running',
			'step'    => 'sync',
			'started' => time(),
			'updated' => time(),
			'n'       => array(
				'synced'      => 0,
				'with'        => 0,
				'sync_fail'   => 0,
				'backed'      => 0,
				'backup_fail' => 0,
				'removed'     => 0,
			),
			'skipped' => array(),
			'idle'    => 0,
			'msg'     => '',
		)
	);
	delete_transient( 'daw_img_job_lock' );
	wp_clear_scheduled_hook( DAW_IMG_JOB_TICK );
	wp_schedule_single_event( time() + 5, DAW_IMG_JOB_TICK );
	return '';
}

function daw_img_job_stop() {
	$j = daw_img_job_get();
	if ( 'running' === ( $j['state'] ?? '' ) ) {
		$j['state'] = 'paused';
		$j['msg']   = 'Durduruldu. Kaldığı yerden devam etmek için Başlat\'a basın.';
		daw_img_job_put( $j );
	}
	wp_clear_scheduled_hook( DAW_IMG_JOB_TICK );
}

/** One bite of work. Safe to call from the page and the scheduler at once. */
add_action( DAW_IMG_JOB_TICK, 'daw_img_job_tick' );
function daw_img_job_tick() {
	$j = daw_img_job_get();
	if ( 'running' !== ( $j['state'] ?? '' ) ) {
		return $j;
	}
	if ( get_transient( 'daw_img_job_lock' ) ) {
		return $j;
	}
	set_transient( 'daw_img_job_lock', 1, 45 );

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore
	}

	// Any error stops the job with its message on screen instead of leaving it
	// silently stuck (what happened on the first live run).
	try {
		$j = daw_img_job_step( $j );
	} catch ( \Throwable $e ) {
		$j['state'] = 'paused';
		$j['msg']   = 'Hata: ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
	}

	$j['updated'] = time();
	daw_img_job_put( $j );
	delete_transient( 'daw_img_job_lock' );

	if ( 'running' === $j['state'] && ! wp_next_scheduled( DAW_IMG_JOB_TICK ) ) {
		wp_schedule_single_event( time() + 30, DAW_IMG_JOB_TICK );
	}
	if ( 'done' === $j['state'] && ! wp_next_scheduled( 'daw_snapshot_soon' ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'daw_snapshot_soon' );
	}
	return $j;
}

/** One bite of the current step. */
function daw_img_job_step( $j ) {
	switch ( $j['step'] ) {
		case 'sync':
			$ids = daw_tmdb_img_queue( DAW_IMG_JOB_BITE );
			if ( ! $ids ) {
				$j['step'] = 'backup';
				$j['idle'] = 0;
				break;
			}
			$fails = 0;
			foreach ( $ids as $id ) {
				$r = daw_tmdb_img_sync( $id );
				if ( null === $r ) {
					$fails++;
					$j['n']['sync_fail']++;
				} else {
					$j['n']['synced']++;
					if ( '' !== $r ) {
						$j['n']['with']++;
					}
				}
			}
			// Three bites in a row where TMDB answered nothing: stop and say so,
			// rather than marking the whole archive as failed.
			$j['idle'] = count( $ids ) === $fails ? $j['idle'] + 1 : 0;
			if ( $j['idle'] >= 3 ) {
				$j['state'] = 'paused';
				$j['msg']   = 'TMDB art arda cevap vermedi. Bağlantıyı test edip Başlat\'a yeniden basın.';
			}
			break;

		case 'backup':
			$ids = daw_vault_queue( DAW_IMG_JOB_BITE );
			if ( ! $ids ) {
				$j['step'] = 'clean';
				break;
			}
			foreach ( $ids as $id ) {
				if ( daw_vault_backup_one( $id ) ) {
					$j['n']['backed']++;
				} else {
					$j['n']['backup_fail']++;
				}
			}
			break;

		case 'clean':
			if ( 'tmdb' !== daw_img_source() ) {
				$j['state'] = 'paused';
				$j['msg']   = 'Görsel kaynağı TMDB değil; medya kütüphanesi temizliği bekletildi.';
				break;
			}
			$left = array_values( array_diff( daw_clean_candidates(), array_map( 'intval', array_keys( $j['skipped'] ) ) ) );
			if ( ! $left ) {
				$j['step']     = 'done';
				$j['state']    = 'done';
				$j['finished'] = time();
				break;
			}
			foreach ( array_slice( $left, 0, DAW_IMG_JOB_BITE ) as $att ) {
				$why = daw_clean_one( $att );
				if ( '' === $why ) {
					$j['n']['removed']++;
				} else {
					$j['skipped'][ $att ] = $why;
				}
			}
			$j['clean_left'] = max( 0, count( $left ) - DAW_IMG_JOB_BITE );
			break;
	}
	return $j;
}

/** Counts straight from the database (rule 8.2: COUNT, not loops). */
function daw_img_job_counts() {
	global $wpdb;
	$count = static function ( $key ) use ( $wpdb ) {
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status = 'publish' AND p.post_type = 'post'",
				$key
			)
		);
	};
	$ok = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT c.post_id) FROM {$wpdb->postmeta} c JOIN {$wpdb->postmeta} i ON i.post_id = c.post_id AND i.meta_key = %s JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE c.meta_key = %s AND c.meta_value = 'ok' AND p.post_status = 'publish' AND p.post_type = 'post'",
			DAW_TMDB_IMG,
			DAW_TMDB_CHECK
		)
	);
	return array(
		'ids'     => $count( 'daw_tmdb_id' ),
		'checked' => $count( DAW_TMDB_IMG_AT ),
		'with'    => $ok,
		'backed'  => $count( DAW_VAULT_META ),
	);
}

/** The lines the screen shows, in plain Turkish. */
function daw_img_job_lines( $j ) {
	$c     = daw_img_job_counts();
	$state = $j['state'] ?? '';
	$step  = $j['step'] ?? '';
	$n     = $j['n'] ?? array();
	$skip  = count( $j['skipped'] ?? array() );
	$out   = array();

	if ( '' === $state ) {
		return array( 'Henüz çalıştırılmadı.' );
	}

	$mark = static function ( $s ) use ( $step, $state ) {
		$order = array( 'sync' => 1, 'backup' => 2, 'clean' => 3, 'done' => 4 );
		if ( 'done' === $state || $order[ $step ] > $order[ $s ] ) {
			return '✔';
		}
		return $step === $s ? '…' : '·';
	};

	$out[] = sprintf( '%s 1/3 TMDB kontrol edildi: %d / %d kayıt (%d kayıtta görsel bulundu ve kaydın kendisi olduğu doğrulandı)', $mark( 'sync' ), $c['checked'], $c['ids'], $c['with'] );
	$out[] = sprintf( '%s 2/3 Gizli yedek alındı: %d / %d görsel', $mark( 'backup' ), $c['backed'], $c['with'] );
	$out[] = sprintf(
		'%s 3/3 Medya kütüphanesinden kaldırıldı (yedekte duruyor): %d dosya%s',
		$mark( 'clean' ),
		(int) ( $n['removed'] ?? 0 ),
		'clean' === $step && isset( $j['clean_left'] ) ? sprintf( ', kalan yaklaşık %d', (int) $j['clean_left'] ) : ''
	);

	if ( 'done' === $state ) {
		$out[] = '';
		$out[] = 'TAMAMLANDI.';
		$out[] = sprintf( 'Bütün dizi, film ve kişi görselleri TMDB\'den gösteriliyor. %d görselin yedeği gizli klasörde.', $c['backed'] );
		$out[] = sprintf( 'Medya kütüphanesinden %d dosya kaldırıldı; hepsinin kopyası gizli yedekte.', (int) ( $n['removed'] ?? 0 ) );
		if ( $skip ) {
			$out[] = sprintf( '%d dosya başka bir yerde kullanıldığı için bırakıldı (liste aşağıda).', $skip );
		}
		$open = 0;
		foreach ( daw_img_report() as $k => $g ) {
			if ( $g['problem'] ) {
				$open += count( $g['rows'] );
			}
		}
		if ( $open ) {
			$out[] = sprintf( '%d kayıtta görsel konamadı ya da emin olunamadı. Hepsi nedenleriyle aşağıdaki "Yapılamayanlar" listesinde. Kayıtlar içerik denetiminde doldurulunca sistem bunları kendiliğinden yeniden kontrol eder.', $open );
		}
		$out[] = 'Bundan sonra yeni kayıtlar saatlik kontrolle kendiliğinden eklenir ve yedeklenir.';
	}

	$fails = (int) ( $n['sync_fail'] ?? 0 ) + (int) ( $n['backup_fail'] ?? 0 );
	if ( $fails ) {
		$out[] = sprintf( '%d işlemde TMDB cevap vermedi; bunlar bir gün sonra kendiliğinden yeniden denenecek.', $fails );
	}
	if ( ! empty( $j['msg'] ) && 'running' !== $state ) {
		$out[] = $j['msg'];
	}
	return $out;
}

/* ================================================================== ajax === */

add_action( 'wp_ajax_daw_img_job', 'daw_img_job_ajax' );
function daw_img_job_ajax() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'daw_img_job', 'nonce', false ) ) {
		wp_send_json_error( 'no', 403 );
	}
	$j = daw_img_job_tick();
	wp_send_json_success(
		array(
			'state' => $j['state'] ?? '',
			'lines' => daw_img_job_lines( $j ),
		)
	);
}

/* ================================================================ screen === */

/** The one-button panel printed at the top of DAW Images › TMDB. */
function daw_img_job_panel() {
	$j       = daw_img_job_get();
	$state   = $j['state'] ?? '';
	$running = 'running' === $state;
	$nonce   = wp_create_nonce( 'daw_img_job' );

	echo '<div style="max-width:62em;padding:16px 18px;margin:14px 0 22px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #7E2128">';
	echo '<h2 style="margin-top:0">Tek tuş: bütün siteyi TMDB\'ye geçir</h2>';
	echo '<p>Başlat\'a bir kez basın. Sistem bütün dizi, film, varyete, anime ve kişi kayıtlarını sırayla dolaşır: (1) her kaydın TMDB görselini bulur, (2) her görselin kopyasını gizli yedek klasörüne alır, (3) medya kütüphanesindeki gereksiz dosyaları önce yedekler sonra kütüphaneden kaldırır. Başka yerde kullanılan dosyalara ve lisanslı Commons fotoğraflarına dokunmaz. Bitince aşağıda "TAMAMLANDI" yazar. Bu sayfa açıksa hızlı çalışır; kapatırsanız arka planda devam eder.</p>';

	echo '<p>';
	printf(
		'<form method="post" action="%s" style="display:inline" onsubmit="return confirm(\'%s\')">',
		esc_url( admin_url( 'admin-post.php' ) ),
		esc_js( 'Bütün site taranacak, görseller yedeklenecek ve gereksiz dosyalar medya kütüphanesinden kaldırılacak (kopyaları gizli yedekte kalır). Başlatılsın mı?' )
	);
	wp_nonce_field( 'daw_hub' );
	echo '<input type="hidden" name="action" value="daw_hub"><input type="hidden" name="op" value="job_start">';
	submit_button( $running ? 'Çalışıyor…' : ( 'done' === $state ? 'Yeniden çalıştır' : 'Başlat' ), 'primary hero', 'go', false, $running ? array( 'disabled' => 'disabled' ) : array() );
	echo '</form> ';
	if ( $running ) {
		printf( '<form method="post" action="%s" style="display:inline">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'daw_hub' );
		echo '<input type="hidden" name="action" value="daw_hub"><input type="hidden" name="op" value="job_stop">';
		submit_button( 'Durdur', 'secondary', 'go', false );
		echo '</form>';
	}
	echo '</p>';

	echo '<pre id="daw-img-job" style="white-space:pre-wrap;font-size:13px;line-height:1.6;background:#f6f7f7;padding:12px;margin:0">';
	echo esc_html( implode( "\n", daw_img_job_lines( $j ) ) );
	echo '</pre>';

	if ( ! empty( $j['skipped'] ) ) {
		echo '<details style="margin-top:10px"><summary>Bırakılan dosyalar (' . count( $j['skipped'] ) . ')</summary><ul>';
		foreach ( array_slice( $j['skipped'], 0, 300, true ) as $att => $why ) {
			printf( '<li><a href="%s">%d</a>: %s</li>', esc_url( (string) get_edit_post_link( (int) $att ) ), (int) $att, esc_html( $why ) );
		}
		echo '</ul></details>';
	}
	echo daw_img_report_html(); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div>';

	if ( $running ) {
		?>
		<script>
		(function () {
			var box = document.getElementById('daw-img-job');
			var body = new URLSearchParams({ action: 'daw_img_job', nonce: <?php echo wp_json_encode( $nonce ); ?> });
			function step() {
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						if (!d || !d.success) { setTimeout(step, 5000); return; }
						box.textContent = d.data.lines.join('\n');
						if (d.data.state === 'running') { setTimeout(step, 1000); } else { location.reload(); }
					})
					.catch(function () { setTimeout(step, 5000); });
			}
			step();
		})();
		</script>
		<?php
	}
}

/* ================================================================ report === */

/**
 * Every title and person sorted into what happened to its picture, with the
 * reason for everything that could not be done. Built in one pass with the
 * meta cache primed, so it stays quick on the whole archive.
 *
 * @return array  key => [ 'label', 'problem' (bool), 'rows' => [ [id, why] ] ]
 */
function daw_img_report() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}
	$g = array(
		'remote'     => array( 'label' => 'TMDB\'den gösteriliyor (doğrulandı)', 'problem' => false, 'rows' => array() ),
		'local'      => array( 'label' => 'Kendi lisanslı görselimiz (Commons, KOGL, kendi kartımız)', 'problem' => false, 'rows' => array() ),
		'no_id'      => array( 'label' => 'TMDB numarası yok', 'problem' => true, 'rows' => array() ),
		'waiting'    => array( 'label' => 'Henüz kontrol edilmedi (sırada)', 'problem' => true, 'rows' => array() ),
		'no_pic'     => array( 'label' => 'TMDB\'de bu kaydın görseli yok', 'problem' => true, 'rows' => array() ),
		'not_found'  => array( 'label' => 'TMDB\'de bulunamadı (arandı; 30 günde bir yeniden aranır, baş harfler gösteriliyor)', 'problem' => true, 'rows' => array() ),
		'mismatch'   => array( 'label' => 'Emin olunamadı: TMDB kaydı bizim kayıtla tutmuyor', 'problem' => true, 'rows' => array() ),
		'unverified' => array( 'label' => 'Doğrulanamadı: kayıtta karşılaştıracak bilgi yok (doldurulunca kendiliğinden kontrol edilir)', 'problem' => true, 'rows' => array() ),
		'gone'       => array( 'label' => 'TMDB bu numarada kayıt bulamadı (numara yanlış)', 'problem' => true, 'rows' => array() ),
		'fail'       => array( 'label' => 'TMDB\'ye ulaşılamadı (bir gün sonra yeniden denenecek)', 'problem' => true, 'rows' => array() ),
		'no_backup'  => array( 'label' => 'Görsel var ama gizli yedeği alınamadı', 'problem' => true, 'rows' => array() ),
	);

	$ids = function_exists( 'daw_audit_record_ids' ) ? daw_audit_record_ids( daw_remote_types() ) : array();
	if ( $ids ) {
		update_meta_cache( 'post', $ids );
	}
	foreach ( $ids as $id ) {
		$tid   = trim( (string) get_post_meta( $id, 'daw_tmdb_id', true ) );
		$at    = (string) get_post_meta( $id, DAW_TMDB_IMG_AT, true );
		$check = (string) get_post_meta( $id, DAW_TMDB_CHECK, true );
		$why   = (string) get_post_meta( $id, DAW_TMDB_WHY, true );
		$mode  = daw_remote_mode_for( $id );

		if ( 'local' === $mode ) {
			$g['local']['rows'][] = array( $id, '' );
			continue;
		}
		if ( '' === $tid ) {
			// Searched and nothing on TMDB carries this title in this country
			// (or a person settled it as "none"): initials are the right
			// answer (rule 1.1), not a job for anyone. Candidates that do
			// carry the title and the country still need a person's eye.
			$cand = get_post_meta( $id, '_daw_tmdb_cand', true );
			if ( 'none' === (string) get_post_meta( $id, 'daw_tmdb_match', true ) || ( (int) get_post_meta( $id, '_daw_tmdb_cand_at', true ) && ! daw_img_cand_worth( $id, $cand ) ) ) {
				$g['not_found']['rows'][] = array( $id, 'TMDB\'de bulunamadı' );
				continue;
			}
			$g['no_id']['rows'][] = array( $id, is_array( $cand ) && $cand ? 'TMDB adayı var, Araçlar › DAW: TMDB matching ekranında seçilmeli' : 'Kayda TMDB numarası girilmeli' );
			continue;
		}
		if ( '' !== (string) get_post_meta( $id, '_daw_tmdb_img_fail', true ) ) {
			$g['fail']['rows'][] = array( $id, '' );
			continue;
		}
		if ( '' === $at ) {
			$g['waiting']['rows'][] = array( $id, '' );
			continue;
		}
		if ( in_array( $check, array( 'mismatch', 'unverified', 'gone' ), true ) ) {
			$g[ $check ]['rows'][] = array( $id, $why );
			continue;
		}
		if ( '' === daw_tmdb_img_path_raw( $id ) ) {
			$g['no_pic']['rows'][] = array( $id, 'Baş harfler gösteriliyor' );
			continue;
		}
		if ( 'ok' === $check && '' === daw_vault_file_for( $id ) ) {
			$g['no_backup']['rows'][] = array( $id, '' !== (string) get_post_meta( $id, '_daw_vault_fail', true ) ? 'İndirme başarısız, yeniden denenecek' : 'Sırada' );
		}
		if ( 'remote' === $mode ) {
			$g['remote']['rows'][] = array( $id, '' );
		}
	}
	$memo = $g;
	return $g;
}

/** The report under the one-button panel: counts, and every list folded open-able. */
/** Does any stored candidate carry our title (or native title) and our country? */
function daw_img_cand_worth( $id, $cand ) {
	if ( ! is_array( $cand ) || ! $cand ) {
		return false;
	}
	$code = function_exists( 'daw_tmdb_country_code' ) ? daw_tmdb_country_code( $id ) : '';
	foreach ( $cand as $c ) {
		if ( ! empty( $c['native'] ) ) {
			return true;
		}
		if ( ! empty( $c['exact'] ) && ( '' === $code || false !== strpos( (string) ( $c['country'] ?? '' ), $code ) ) ) {
			return true;
		}
	}
	return false;
}

function daw_img_report_html() {
	$g   = daw_img_report();
	$out = '<h2 style="margin-top:22px">Rapor</h2><table class="widefat striped" style="max-width:62em"><tbody>';
	foreach ( $g as $k => $grp ) {
		if ( ! $grp['rows'] && $grp['problem'] ) {
			continue;
		}
		$out .= sprintf(
			'<tr><td style="width:5em"><strong>%d</strong></td><td>%s%s</td></tr>',
			count( $grp['rows'] ),
			esc_html( $grp['label'] ),
			$grp['problem'] ? ' <span style="color:#b32d2e">(yapılamayan)</span>' : ''
		);
	}
	$out .= '</tbody></table>';

	foreach ( $g as $k => $grp ) {
		if ( ! $grp['rows'] ) {
			continue;
		}
		$out .= sprintf( '<details style="margin:8px 0"%s><summary><strong>%s</strong> (%d) listesini gör</summary><ol style="columns:2;max-width:62em">', $grp['problem'] ? '' : '', esc_html( $grp['label'] ), count( $grp['rows'] ) );
		foreach ( $grp['rows'] as $r ) {
			$out .= sprintf(
				'<li><a href="%s">%s</a> <a href="%s" target="_blank" rel="noopener" style="text-decoration:none">↗</a>%s</li>',
				esc_url( (string) get_edit_post_link( $r[0] ) ),
				esc_html( get_the_title( $r[0] ) ),
				esc_url( get_permalink( $r[0] ) ),
				'' !== $r[1] ? ' <span style="color:#646970">' . esc_html( $r[1] ) . '</span>' : ''
			);
		}
		$out .= '</ol></details>';
	}
	return $out;
}

/**
 * One-time fix for sites that ran 1.31.1: strict mode on, and the files the
 * first run skipped by mistake (a drama's name inside an article counted as
 * "used") get another look.
 */
add_action( 'admin_init', 'daw_img_job_fix_v2' );
function daw_img_job_fix_v2() {
	if ( get_option( 'daw_img_fix_v3' ) ) {
		return;
	}
	update_option( 'daw_img_strict', 1, false );
	$j = daw_img_job_get();
	if ( $j ) {
		$j['skipped'] = array();
		if ( 'done' === ( $j['state'] ?? '' ) ) {
			$j['state'] = 'running';
			$j['step']  = 'clean';
		}
		daw_img_job_put( $j );
		if ( 'running' === $j['state'] && ! wp_next_scheduled( DAW_IMG_JOB_TICK ) ) {
			wp_schedule_single_event( time() + 10, DAW_IMG_JOB_TICK );
		}
	}
	update_option( 'daw_img_fix_v3', 1, false );
}
