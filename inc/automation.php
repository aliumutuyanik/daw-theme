<?php
/**
 * Automation and the work queue.
 *
 * DAW › Otomatik işler: every job that costs no AI tokens, on one page with
 * one button. The button walks the jobs in turn, a few records at a time,
 * with a pause between steps, so a shared host never sees more than one
 * small request at once. Without the page open the same jobs run hourly
 * from cron in small slices.
 *
 * DAW › İş sırası: what is left for people and AI agents, record by record,
 * sorted by readers first (30-day views) and then by how much is missing.
 * Each record is given the cheapest tier that can finish it:
 *   - auto:   nothing for an agent; waiting for an automatic job
 *   - haiku:  lookups and mechanical fixes (search patterns, links, dates,
 *             dashes, cliché words, SEO lengths)
 *   - sonnet: research and writing (text fields, episode credits, conflicts)
 * The list is stored in the `daw_work_queue` option so an agent reads one
 * option over MCP and takes the next batch, instead of opening 1,100 records.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================ work queue === */

/** Madde numbers that wait on an automatic job, not on an agent. */
function daw_wq_auto_items() {
	return array( '55', '56', '59', '61', 'K-g', 'K-a' );
}

/** Madde numbers the cheapest model can finish: lookups and mechanical fixes. */
function daw_wq_haiku_items() {
	return array( '42', '46', '47', '48', '49', '50', '51', '52', '58', 'K-b', 'K-c', 'K-h' );
}

function daw_wq_build() {
	global $wpdb;
	// phpcs:ignore WordPress.DB
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, m.meta_value, p.post_title FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status = 'publish'", DAW_AE_META ) );
	$auto  = daw_wq_auto_items();
	$haiku = daw_wq_haiku_items();
	$items = array();
	$count = array( 'sonnet' => 0, 'haiku' => 0, 'auto' => 0, 'locked' => 0, 'done' => 0 );
	foreach ( (array) $rows as $r ) {
		$m = maybe_unserialize( $r->meta_value );
		if ( ! is_array( $m ) ) {
			continue;
		}
		if ( ! empty( $m['lock'] ) ) {
			$count['locked']++;
			continue;
		}
		$need = array();
		$tier = 'done';
		foreach ( (array) ( $m['fails'] ?? array() ) as $f ) {
			$no = (string) ( $f[4] ?? '' );
			if ( in_array( $no, $auto, true ) ) {
				if ( 'done' === $tier ) {
					$tier = 'auto';
				}
				continue;
			}
			$need[ $no ] = true;
			if ( in_array( $no, $haiku, true ) ) {
				if ( 'sonnet' !== $tier ) {
					$tier = 'haiku';
				}
			} else {
				$tier = 'sonnet';
			}
		}
		$count[ $tier ]++;
		if ( in_array( $tier, array( 'done', 'auto' ), true ) ) {
			continue;
		}
		$items[] = array(
			'id'    => (int) $r->post_id,
			'title' => $r->post_title,
			'type'  => (string) ( $m['type'] ?? '' ),
			'tier'  => $tier,
			'views' => function_exists( 'daw_views_30' ) ? daw_views_30( (int) $r->post_id ) : 0,
			'n'     => count( $need ),
			'madde' => implode( ',', array_keys( $need ) ),
		);
	}
	usort(
		$items,
		static function ( $a, $b ) {
			return ( $b['views'] <=> $a['views'] ) ?: ( $b['n'] <=> $a['n'] );
		}
	);
	$q = array(
		'built'  => time(),
		'count'  => $count,
		'how'    => 'Sıradaki işi al: tier=haiku olanlar ucuz modelle (anahtar kelime, bağlantı, tarih, tire/klişe düzeltme), tier=sonnet olanlar araştırma ve yazı. Her kayıtta önce _daw_audit.fails okunur, yalnız o maddelere dokunulur (daw_agent_brief).',
		'items'  => array_slice( $items, 0, 400 ),
	);
	update_option( 'daw_work_queue', $q, false );
	return $q;
}

add_action( 'daw_ae_cron', 'daw_wq_build', 20 );

add_filter( 'royal_mcp_readable_options', 'daw_wq_readable' );
function daw_wq_readable( $keys ) {
	$keys   = (array) $keys;
	$keys[] = 'daw_work_queue';
	$keys[] = 'daw_auto_state';
	return array_values( array_unique( $keys ) );
}

/* ============================================================ automation === */

/** The zero-token jobs, in the order the button runs them. */
function daw_auto_jobs() {
	return array(
		'verify'   => array( 'Kaynak doğrulaması (TMDB + Wikidata)', 'daw_vf_cron_run', 4 ),
		'coverage' => array( 'Kapsam taraması', 'daw_cov_cron_run', 5 ),
		'watch'    => array( 'Ülkeye göre izleme yerleri', 'daw_wp_cron_run', 6 ),
		'audit'    => array( 'Denetim', 'daw_auto_audit_step', 6 ),
	);
}

function daw_auto_audit_step( $limit = 6 ) {
	$n = 0;
	foreach ( daw_ae_queue( $limit ) as $id ) {
		daw_ae_store_safe( $id );
		$n++;
	}
	return $n;
}

/** How many records each job still has to do. Cheap COUNT queries. */
function daw_auto_left() {
	global $wpdb;
	$left = array();
	$due  = static function ( $at_key, $age ) use ( $wpdb ) {
		// phpcs:ignore WordPress.DB
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s AND c.meta_value = 'ok'
				 LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
				 WHERE p.post_type = 'post' AND p.post_status = 'publish'
				   AND ( a.meta_value IS NULL OR CAST( a.meta_value AS UNSIGNED ) < %d )",
				DAW_TMDB_CHECK,
				$at_key,
				time() - $age
			)
		);
	};
	$left['verify']   = defined( 'DAW_VF_AT' ) ? $due( DAW_VF_AT, 30 * DAY_IN_SECONDS ) : 0;
	$left['coverage'] = defined( 'DAW_COV_AT' ) ? $due( DAW_COV_AT, 7 * DAY_IN_SECONDS ) : 0;
	$left['watch']    = defined( 'DAW_WP_AT' ) ? $due( DAW_WP_AT, 7 * DAY_IN_SECONDS ) : 0;
	$left['audit']    = count( daw_ae_queue( 2000 ) );
	return $left;
}

add_action( 'admin_menu', 'daw_auto_menu', 32 );
function daw_auto_menu() {
	add_submenu_page( 'daw', 'Otomatik işler', 'Otomatik işler', 'manage_options', 'daw-otomatik', 'daw_auto_page' );
	add_submenu_page( 'daw', 'İş sırası', 'İş sırası', 'manage_options', 'daw-is-sirasi', 'daw_wq_page' );
}

function daw_auto_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$left  = daw_auto_left();
	$nonce = wp_create_nonce( 'daw_auto_run' );
	echo '<div class="wrap"><h1>DAW › Otomatik işler</h1>';
	echo '<p style="max-width:72em">Yapay zekâ harcamayan bütün işler: TMDB ile Wikidata\'nın aynı dediği sabit bilgileri boş alanlara yazmak, kadro ve filmografi kapsamı, ülkeye göre izleme yerleri ve denetim. Düğme işleri sırayla, her adımda birkaç kayıtla ve aralarında bekleyerek yürütür; sunucu aynı anda tek küçük istek görür. Sayfa kapalıyken aynı işler saatte bir kendiliğinden küçük dilimlerle sürer. Görseller kendi sayfasındadır (DAW › Görseller).</p>';
	echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>İş</th><th style="text-align:right">Kalan kayıt</th></tr></thead><tbody>';
	foreach ( daw_auto_jobs() as $k => $j ) {
		printf( '<tr><td>%s</td><td style="text-align:right" data-left="%s">%d</td></tr>', esc_html( $j[0] ), esc_attr( $k ), (int) ( $left[ $k ] ?? 0 ) );
	}
	echo '</tbody></table>';
	echo '<p><button type="button" class="button button-primary" id="daw-auto-run">Hepsini şimdi yürüt</button> <span id="daw-auto-msg" style="margin-left:8px;color:#50575e"></span></p></div>';
	?>
	<script>
	(function () {
		var b = document.getElementById('daw-auto-run'), m = document.getElementById('daw-auto-msg');
		b.addEventListener('click', function () {
			b.disabled = true;
			(function step() {
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'daw_auto_run', nonce: <?php echo wp_json_encode( $nonce ); ?> }) })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						if (!d || !d.success) { m.textContent = 'Hata, 10 sn sonra yeniden.'; setTimeout(step, 10000); return; }
						Object.keys(d.data.left).forEach(function (k) { var c = document.querySelector('[data-left="' + k + '"]'); if (c) { c.textContent = d.data.left[k]; } });
						m.textContent = d.data.job ? (d.data.job + ': ' + d.data.done + ' kayıt') : '';
						if (d.data.done > 0) { setTimeout(step, 1500); } else { m.textContent = 'TAMAMLANDI. İş sırası güncellendi.'; b.disabled = false; }
					})
					.catch(function () { setTimeout(step, 10000); });
			})();
		});
	})();
	</script>
	<?php
}

add_action( 'wp_ajax_daw_auto_run', 'daw_auto_run' );
function daw_auto_run() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'daw_auto_run', 'nonce', false ) ) {
		wp_send_json_error( 'no', 403 );
	}
	if ( get_transient( 'daw_auto_lock' ) ) {
		wp_send_json_success( array( 'done' => 1, 'job' => 'bekliyor', 'left' => array() ) );
	}
	set_transient( 'daw_auto_lock', 1, 60 );
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 60 ); // phpcs:ignore
	}
	$done = 0;
	$job  = '';
	try {
		foreach ( daw_auto_jobs() as $k => $j ) {
			if ( ! function_exists( $j[1] ) ) {
				continue;
			}
			$n = (int) call_user_func( $j[1], $j[2] );
			if ( $n > 0 ) {
				$done = $n;
				$job  = $j[0];
				break;
			}
		}
		if ( ! $done ) {
			daw_wq_build();
		}
	} catch ( \Throwable $e ) {
		$done = 1;
		$job  = 'hata: ' . $e->getMessage();
	}
	delete_transient( 'daw_auto_lock' );
	$left = daw_auto_left();
	update_option( 'daw_auto_state', array( 'at' => time(), 'job' => $job, 'left' => $left ), false );
	wp_send_json_success( array( 'done' => $done, 'job' => $job, 'left' => $left ) );
}

/* ============================================================ queue page === */

function daw_wq_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$q = get_option( 'daw_work_queue' );
	if ( ! is_array( $q ) || isset( $_GET['rebuild'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$q = daw_wq_build();
	}
	$c    = $q['count'];
	$base = admin_url( 'admin.php?page=daw-is-sirasi' );
	$tier = sanitize_key( $_GET['tier'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	echo '<div class="wrap"><h1>DAW › İş sırası</h1>';
	printf( '<p style="max-width:72em">Kalan iş, okunma sırasına göre (son 30 gün), sonra eksik madde sayısına göre. Her kayda onu bitirebilecek en ucuz model atanır. Kilitli kayıtlar listede yoktur. Güncelleme: %s önce · <a href="%s">şimdi yenile</a></p>', esc_html( human_time_diff( (int) $q['built'] ) ), esc_url( add_query_arg( 'rebuild', 1, $base ) ) );
	$cards = array(
		'sonnet' => array( 'Araştırma ve yazı (Sonnet)', '#fbe6db', '#8a3413' ),
		'haiku'  => array( 'Bakma ve küçük düzeltme (Haiku)', '#e2ebf5', '#27527f' ),
		'auto'   => array( 'Otomatik işi bekliyor', '#f6ecc9', '#6a4d0c' ),
		'locked' => array( 'Kilitli, bitti', '#e3f1ec', '#185a4a' ),
	);
	echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin:12px 0">';
	foreach ( $cards as $k => $cd ) {
		printf( '<a href="%s" style="text-decoration:none;background:%s;color:%s;border-radius:10px;padding:10px 14px;min-width:180px"><div style="font-size:24px;font-weight:700">%d</div><div>%s</div></a>', esc_url( add_query_arg( 'tier', $k, $base ) ), esc_attr( $cd[1] ), esc_attr( $cd[2] ), (int) ( $c[ $k ] ?? 0 ), esc_html( $cd[0] ) );
	}
	echo '</div>';
	echo '<p style="max-width:72em;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 14px">Yapay zekâya şunu demen yeter: <strong>"DAW iş sırasından sıradaki 10 Sonnet kaydını yap"</strong> ya da <strong>"… 30 Haiku kaydını yap"</strong>. Ajan <code>daw_work_queue</code>, <code>daw_rulebook</code> ve kaydın <code>_daw_audit</code> alanını okur; kuralları ayrıca anlatmana gerek yok.</p>';
	echo '<table class="widefat striped" style="max-width:1300px"><thead><tr><th>#</th><th>Kayıt</th><th>Tür</th><th>Model</th><th style="text-align:right">30 gün okunma</th><th style="text-align:right">Eksik madde</th><th>Maddeler</th></tr></thead><tbody>';
	$i = 0;
	foreach ( (array) $q['items'] as $it ) {
		if ( $tier && $it['tier'] !== $tier ) {
			continue;
		}
		$i++;
		printf(
			'<tr><td>%d</td><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td style="text-align:right">%d</td><td style="text-align:right">%d</td><td style="font-size:12px">%s</td></tr>',
			$i,
			esc_url( admin_url( 'admin.php?page=daw-denetim&record=' . (int) $it['id'] ) ),
			esc_html( $it['title'] ),
			esc_html( $it['type'] ),
			esc_html( ucfirst( $it['tier'] ) ),
			(int) $it['views'],
			(int) $it['n'],
			esc_html( $it['madde'] )
		);
		if ( $i >= 200 ) {
			break;
		}
	}
	echo '</tbody></table></div>';
}
