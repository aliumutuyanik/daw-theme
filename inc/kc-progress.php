<?php
/**
 * Progress of "Tüm kontrolleri şimdi çalıştır" (1.75.67, owner 13 Oct 2026:
 * "kontrol çalıştırınca %100 veriyor, ne kadar süreceğini göremiyoruz, gerçek
 * dolan bir bar olsun").
 *
 * The button only schedules background events; until 1.75.66 the page then
 * said "Son tam kontrol: 4 saniye önce" straight away, although nothing had
 * run yet. Now every step the button schedules is written down when it is
 * scheduled and ticked off when its event has really run, so the bar fills
 * as the work is done, and the time left comes from what is still waiting:
 *   - the seven checks (Search Console, index, 404 repairs, snapshot,
 *     freshness, speed) and the second wave of repairs;
 *   - every PageSpeed measurement (phone and desktop, one per page type);
 *   - the link audit, which reads every published text in batches.
 * "Son tam kontrol" is the time the last step finished, not the click.
 *
 * WordPress runs background events on page requests; the progress request
 * this page sends every few seconds is one, so the work keeps moving while
 * the page is open.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

const DAW_KCP_OPT = 'daw_kc_progress';

/** The single steps the button schedules, with the words the bar shows. */
function daw_kcp_labels() {
	return array(
		'daw_lk_tick'          => 'Bağlantı denetimi başlıyor',
		'daw_gsc_sync'         => 'Search Console verisi',
		'daw_gsc_inspect'      => 'Google dizin denetimi',
		'daw_nf_autofix'       => '404 onarımı',
		'daw_snapshot_cron'    => 'Site anlık görüntüsü',
		'daw_fresh_daily'      => 'Güncellik kontrolü',
		'daw_speed_daily'      => 'Hız özeti',
		'daw_kc_run_all_late'  => 'Onarımlar ve site haritası',
	);
}

/** Called by the button right after it has scheduled everything. */
function daw_kcp_start( $t, $psi_count ) {
	$steps = array();
	$i     = 0;
	foreach ( daw_kcp_labels() as $hook => $label ) {
		$steps[ $hook ] = array(
			'label' => $label,
			'at'    => 'daw_kc_run_all_late' === $hook ? $t + 200 : $t + $i * 20,
			'done'  => 0,
		);
		++$i;
	}

	update_option(
		DAW_KCP_OPT,
		array(
			'started'   => $t,
			'finished'  => 0,
			'steps'     => $steps,
			'psi_total' => (int) $psi_count,
			'psi_done'  => 0,
			'psi_from'  => $t + 30,
			'lk_total'  => daw_kcp_lk_total(),
		),
		false
	);
}

/** Published texts the link audit reads in one pass. */
function daw_kcp_lk_total() {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page')" ); // phpcs:ignore WordPress.DB
}

/** The run in progress, or null. A run never counts as live for more than three hours. */
function daw_kcp_live() {
	$p = get_option( DAW_KCP_OPT );
	if ( ! is_array( $p ) || empty( $p['started'] ) || ! empty( $p['finished'] ) ) {
		return null;
	}
	if ( time() - (int) $p['started'] > 3 * HOUR_IN_SECONDS ) {
		$p['finished'] = time();
		$p['timeout']  = 1;
		update_option( DAW_KCP_OPT, $p, false );
		return null;
	}
	return $p;
}

/* Tick a step off once its event has really run (late priority: after the work). */
foreach ( array_keys( daw_kcp_labels() ) as $daw_kcp_hook ) {
	add_action(
		$daw_kcp_hook,
		static function () use ( $daw_kcp_hook ) {
			$p = daw_kcp_live();
			if ( ! $p || ! isset( $p['steps'][ $daw_kcp_hook ] ) || ! empty( $p['steps'][ $daw_kcp_hook ]['done'] ) ) {
				return;
			}
			$p['steps'][ $daw_kcp_hook ]['done'] = time();
			update_option( DAW_KCP_OPT, $p, false );
			daw_kcp_finish_if_done();
		},
		9999
	);
}
unset( $daw_kcp_hook );

add_action(
	'daw_psi_detail_one',
	static function () {
		$p = daw_kcp_live();
		if ( ! $p || (int) $p['psi_done'] >= (int) $p['psi_total'] ) {
			return;
		}
		$p['psi_done'] = (int) $p['psi_done'] + 1;
		update_option( DAW_KCP_OPT, $p, false );
		daw_kcp_finish_if_done();
	},
	9999
);

add_action( 'daw_lk_tick', 'daw_kcp_finish_if_done', 10000 );

/** How far the link audit is: [read, total, finished]. */
function daw_kcp_lk( array $p ) {
	global $wpdb;
	$total = max( 1, (int) ( $p['lk_total'] ?? 0 ) );
	$state = (array) get_option( 'daw_lk_state', array() );
	if ( ! empty( $state['pass_end'] ) && (int) $state['pass_end'] >= (int) $p['started'] && empty( $state['cursor'] ) ) {
		return array( $total, $total, true );
	}
	$cursor = (int) ( $state['cursor'] ?? 0 );
	$read   = $cursor > 0 ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page') AND ID <= %d", $cursor ) ) : 0; // phpcs:ignore WordPress.DB
	return array( min( $read, $total ), $total, false );
}

/** Percent, time left, current step and a short line per part. */
function daw_kcp_status( array $p ) {
	$now   = time();
	$done  = 0;
	$all   = 0;
	$left  = array();
	$last  = 0;
	foreach ( $p['steps'] as $s ) {
		++$all;
		if ( ! empty( $s['done'] ) ) {
			++$done;
			$last = max( $last, (int) $s['done'] );
		} else {
			$left[] = $s;
		}
	}

	$psi_t = (int) $p['psi_total'];
	$psi_d = min( $psi_t, (int) $p['psi_done'] );
	list( $lk_r, $lk_t, $lk_end ) = daw_kcp_lk( $p );

	// Weights: each check 1, each speed measurement 1, the link audit 10.
	$units = $all + $psi_t + 10;
	$have  = $done + $psi_d + ( $lk_end ? 10 : 10 * $lk_r / max( 1, $lk_t ) );
	$pct   = (int) floor( 100 * $have / max( 1, $units ) );

	// Time left: the latest of what is still waiting.
	$eta = 0;
	foreach ( $left as $s ) {
		$eta = max( $eta, (int) $s['at'] - $now + 30 );
	}
	if ( $psi_d < $psi_t ) {
		$eta = max( $eta, (int) $p['psi_from'] + $psi_t * 90 - $now, ( $psi_t - $psi_d ) * 60 );
	}
	if ( ! $lk_end ) {
		$eta = max( $eta, (int) ceil( ( $lk_t - $lk_r ) / ( defined( 'DAW_LK_BATCH' ) ? DAW_LK_BATCH : 40 ) ) * 90 );
	}

	$now_label = $left ? (string) $left[0]['label'] : ( $psi_d < $psi_t ? 'Hız ölçümü' : ( $lk_end ? 'Bitiyor' : 'Bağlantı denetimi' ) );

	return array(
		'pct'   => min( 99, max( 1, $pct ) ),
		'eta'   => max( 30, $eta ),
		'now'   => $now_label,
		'parts' => array(
			sprintf( 'Kontroller %d/%d', $done, $all ),
			sprintf( 'Hız ölçümü %d/%d', $psi_d, $psi_t ),
			sprintf( 'Bağlantı denetimi %d/%d metin', $lk_r, $lk_t ),
		),
		'done'  => $done === $all && $psi_d >= $psi_t && $lk_end,
	);
}

function daw_kcp_finish_if_done() {
	$p = daw_kcp_live();
	if ( ! $p ) {
		return;
	}
	$s = daw_kcp_status( $p );
	if ( $s['done'] ) {
		$p['finished'] = time();
		update_option( DAW_KCP_OPT, $p, false );
		update_option( 'daw_kc_last_done', time(), false );
	}
}

/** The control centre asks every few seconds while a run is live. */
add_action( 'wp_ajax_daw_kc_progress', 'daw_kcp_ajax' );
function daw_kcp_ajax() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( null, 403 );
	}
	check_ajax_referer( 'daw_kc_progress' );
	daw_kcp_finish_if_done();
	$p = daw_kcp_live();
	if ( ! $p ) {
		$q = get_option( DAW_KCP_OPT );
		wp_send_json_success(
			array(
				'live' => false,
				'took' => is_array( $q ) && ! empty( $q['finished'] ) ? (int) $q['finished'] - (int) $q['started'] : 0,
			)
		);
	}
	wp_send_json_success( array( 'live' => true ) + daw_kcp_status( $p ) );
}

/** Minutes and seconds in plain Turkish. */
function daw_kcp_dur( $sec ) {
	$sec = max( 0, (int) $sec );
	if ( $sec < 60 ) {
		return $sec . ' sn';
	}
	$m = (int) round( $sec / 60 );
	return $m < 60 ? $m . ' dk' : sprintf( '%d sa %d dk', intdiv( $m, 60 ), $m % 60 );
}

/** The line under the button: a live bar during a run, the last finish time otherwise. */
function daw_kcp_render() {
	$p = daw_kcp_live();
	if ( ! $p ) {
		$q    = get_option( DAW_KCP_OPT );
		$fin  = (int) get_option( 'daw_kc_last_done', 0 );
		$took = is_array( $q ) && ! empty( $q['finished'] ) && empty( $q['timeout'] ) ? (int) $q['finished'] - (int) $q['started'] : 0;
		printf(
			'<span>Son tam kontrol: %s%s. Kontroller kendiliğinden de her gün çalışır.</span>',
			esc_html( $fin ? human_time_diff( $fin ) . ' önce bitti' : 'henüz bitmiş bir tur yok' ),
			$took ? esc_html( ' (' . daw_kcp_dur( $took ) . ' sürdü)' ) : ''
		);
		return;
	}

	$s = daw_kcp_status( $p );
	echo '<div class="kc-prog" id="kc-prog" data-nonce="' . esc_attr( wp_create_nonce( 'daw_kc_progress' ) ) . '" data-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">';
	echo '<div class="kc-prog-top"><b class="kc-prog-pct">' . (int) $s['pct'] . '%</b><span class="kc-prog-eta">yaklaşık ' . esc_html( daw_kcp_dur( $s['eta'] ) ) . ' kaldı</span></div>';
	echo '<div class="kc-prog-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $s['pct'] . '"><i style="width:' . (int) $s['pct'] . '%"></i></div>';
	echo '<div class="kc-prog-now">Şu an: <span class="kc-prog-step">' . esc_html( $s['now'] ) . '</span> · <span class="kc-prog-parts">' . esc_html( implode( ' · ', $s['parts'] ) ) . '</span></div>';
	echo '<small class="kc-prog-note">Bu sayfa açıkken iş hızlı ilerler; kapatırsan arka planda sürer.</small>';
	echo '</div>';
	?>
	<script>
	(function(){
		var box=document.getElementById('kc-prog'); if(!box){return;}
		function dur(s){s=Math.max(0,s|0);if(s<60){return s+' sn';}var m=Math.round(s/60);return m<60?m+' dk':Math.floor(m/60)+' sa '+(m%60)+' dk';}
		function tick(){
			var b=new FormData(); b.append('action','daw_kc_progress'); b.append('_ajax_nonce',box.dataset.nonce);
			fetch(box.dataset.url,{method:'POST',body:b,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
				if(!j||!j.success){return;}
				var d=j.data;
				if(!d.live){
					box.querySelector('.kc-prog-pct').textContent='100%';
					box.querySelector('.kc-prog-bar i').style.width='100%';
					box.querySelector('.kc-prog-eta').textContent='bitti'+(d.took?' ('+dur(d.took)+' sürdü)':'');
					box.querySelector('.kc-prog-now').textContent='Tüm kontroller bitti. Sonuçları görmek için sayfayı yenile.';
					return;
				}
				box.querySelector('.kc-prog-pct').textContent=d.pct+'%';
				box.querySelector('.kc-prog-bar i').style.width=d.pct+'%';
				box.querySelector('.kc-prog-bar').setAttribute('aria-valuenow',d.pct);
				box.querySelector('.kc-prog-eta').textContent='yaklaşık '+dur(d.eta)+' kaldı';
				box.querySelector('.kc-prog-step').textContent=d.now;
				box.querySelector('.kc-prog-parts').textContent=d.parts.join(' · ');
				setTimeout(tick,5000);
			}).catch(function(){setTimeout(tick,10000);});
		}
		setTimeout(tick,3000);
	})();
	</script>
	<?php
}
