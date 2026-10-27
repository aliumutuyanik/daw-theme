<?php
/**
 * DAW › İşler: one screen for the whole job.
 *
 * WHY
 * ---
 * "İş sırası", "Arşiv denetimi", "Denetim", "Otomatik işler", "Eksikler" and
 * "Güncellik" each answered part of the same question, in different words,
 * and the owner could not tell from any one of them what was actually done
 * (owner, 24 Sep 2026: one place, run and stop, what is finished with its
 * link, what is missing in a table, 20/50/100/250 a page).
 *
 * So this screen says four things and nothing else:
 *   1. how far along the site is: finished / to fix / not looked at / waiting;
 *   2. the automatic jobs, with Start and Stop;
 *   3. every record in one table, filterable, with what it still needs;
 *   4. where the rules are.
 *
 * FINISHED MEANS ONE THING
 * ------------------------
 * A record is "Bitti" when it is locked, or when it has no red line, no
 * unlooked field and a valid verification stamp. Waiting lines (coverage,
 * freshness) do not hold a finished record back: they are jobs the site
 * runs by itself. Everything here is read from the stored audits, one query,
 * never by re-auditing on page load.
 *
 * The old screens stay registered, so their links keep working, but they are
 * out of the menu and linked once at the bottom of this page.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** State of one stored audit: bitti, kirmizi, eksik, damga, bekliyor. */
function daw_wc_state( array $m ) {
	if ( ! empty( $m['lock'] ) ) {
		return 'bitti';
	}
	$s = (array) ( $m['sum'] ?? array() );
	if ( (int) ( $s['no'] ?? 0 ) > 0 ) {
		return 'kirmizi';
	}
	if ( (int) ( $s['eksik'] ?? 0 ) > 0 ) {
		return 'eksik';
	}
	foreach ( (array) ( $m['fails'] ?? array() ) as $f ) {
		if ( 'Doğrulama damgası' === ( $f[1] ?? '' ) ) {
			return 'damga';
		}
	}
	return 'bitti';
}

/**
 * A record that would be Bitti but whose TMDB cast (titles) or filmography
 * (people) is not all on the site is not finished (1.75.52, Umut, 8 Oct
 * 2026: "eksik kadro ya da eksik film varsa tam bittiye girmesinler").
 * $cov is the coverage row [type, total, linked, linkable, no record]; a
 * record never compared with TMDB has no row and is not held back here.
 */
function daw_wc_state_cast( $state, $cov ) {
	if ( 'bitti' === $state && is_array( $cov ) && (int) ( $cov[1] ?? 0 ) > (int) ( $cov[2] ?? 0 ) ) {
		return 'kadro';
	}
	return $state;
}

/**
 * The three levels the owner reads (1.75.52): Tam bitti, Temel bitti, Temelde
 * eksik var. Stamp, red line and unlooked field are internal audit words; the
 * screen says only whether what we can fill is filled.
 */
function daw_wc_levels() {
	return array(
		'tam'   => array( 'Tam bitti', '#e3f1ec', '#185a4a', 'Denetimde açık satır yok: her alan dolu ya da kaynakla YOK, kadro/filmografi sitede bağlı, Search Console aramaları karşılanmış, damga geçerli. Yeni bir iş, kadro değişikliği ya da içerik düzenlemesi açık satır doğurursa kayıt kendiliğinden Temel bitti\'ye iner.' ),
		'temel' => array( 'Temel bitti', '#e8eef7', '#27466f', 'Kırmızı ve bakılmamış alan yok, ama bekleyen satır var: kaydı olmayan yapım/kişi, yeni/yakında iş, karşılanmamış arama, damga ya da kilit yenilemesi. Bekleyen kapanınca kendiliğinden Tam bitti olur.' ),
		'eksik' => array( 'Temelde eksik var', '#fbe3e3', '#8a1f1f', 'Doldurabildiğimiz bir ya da daha çok alan eksik ya da kurala aykırı.' ),
	);
}

/**
 * Level of one row (1.75.58, owner 9 Oct 2026: "her şey bittiyse tam bitti;
 * sonra yeni bir film gelirse tam bittiden çıkmalı"). Read from the stored
 * audit itself, the same lines the record's audit page shows:
 *   eksik: a red line or an unlooked field;
 *   tam:   no open line at all (no red, no unlooked, no waiting);
 *   temel: everything else (only waiting lines are open).
 * The old rule compared raw TMDB counts (total vs linked) and so kept a
 * record whose every credit was settled, variety-only or matched through
 * daw_tmdb_alt in Temel forever. A waiting line opened later by the weekly
 * coverage scan, a new work, an edit or a new search takes the record out of
 * Tam by itself, because the audit is re-run when those change.
 */
function daw_wc_level( $state, $m = null ) {
	if ( in_array( $state, array( 'kirmizi', 'eksik', 'yok' ), true ) ) {
		return 'eksik';
	}
	if ( is_array( $m ) ) {
		$s = (array) ( $m['sum'] ?? array() );
		if ( (int) ( $s['no'] ?? 0 ) > 0 || (int) ( $s['eksik'] ?? 0 ) > 0 ) {
			return 'eksik';
		}
		foreach ( (array) ( $m['fails'] ?? array() ) as $f ) {
			if ( 'wait' === ( $f[2] ?? '' ) ) {
				return 'temel';
			}
		}
		return 'tam';
	}
	return 'bitti' === $state ? 'tam' : ( 'kadro' === $state ? 'temel' : 'eksik' );
}

/** Labels and colours, in the order the tabs are drawn. */
function daw_wc_states() {
	return array(
		'bitti'   => array( 'Bitti', '#e3f1ec', '#185a4a', 'Kilitli ya da damgalı; kırmızı ve bakılmamış satırı yok.' ),
		'kirmizi' => array( 'Düzeltilecek', '#fbe3e3', '#8a1f1f', 'En az bir satır kurala aykırı.' ),
		'kadro'   => array( 'Kadro/film eksik', '#e8eef7', '#27466f', 'Alanlar tamam; TMDB\'deki kadro ya da filmografinin hepsi sitede kayıtlı ve bağlı değil.' ),
		'eksik'   => array( 'Bakılmamış alan var', '#eceae6', '#50575e', 'Boş alanlar henüz araştırılmadı.' ),
		'damga'   => array( 'Damga bekliyor', '#f6ecc9', '#6a4d0c', 'Her şey yeşil; kaynaklarla son okuma ve damga kaldı.' ),
		'yok'     => array( 'Hiç denetlenmedi', '#f0f0f1', '#50575e', 'Denetim henüz çalışmadı.' ),
	);
}

/** Model tier for what is left, same split as the work queue. */
function daw_wc_tier( array $m ) {
	$auto  = function_exists( 'daw_wq_auto_items' ) ? daw_wq_auto_items() : array();
	$haiku = function_exists( 'daw_wq_haiku_items' ) ? daw_wq_haiku_items() : array();
	$tier  = '';
	foreach ( (array) ( $m['fails'] ?? array() ) as $f ) {
		$no = (string) ( $f[4] ?? '' );
		if ( in_array( $no, $auto, true ) ) {
			continue;
		}
		if ( in_array( $no, $haiku, true ) ) {
			$tier = 'sonnet' === $tier ? 'sonnet' : 'haiku';
		} else {
			$tier = 'sonnet';
		}
	}
	return $tier;
}

/** Every record of an audited type: id => [title, audit or null, views]. */
function daw_wc_rows() {
	global $wpdb;
	$ids = function_exists( 'daw_audit_record_ids' ) && function_exists( 'daw_ae_all_types' ) ? array_map( 'intval', (array) daw_audit_record_ids( daw_ae_all_types() ) ) : array();
	if ( ! $ids ) {
		return array();
	}
	$in    = implode( ',', $ids );
	$title = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($in)", OBJECT_K ); // phpcs:ignore WordPress.DB
	$audit = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($in)", DAW_AE_META ), OBJECT_K ); // phpcs:ignore WordPress.DB
	$views = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'daw_views_30' AND post_id IN ($in)", OBJECT_K ); // phpcs:ignore WordPress.DB
	$kwm   = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_daw_kw' AND post_id IN ($in)", OBJECT_K ); // phpcs:ignore WordPress.DB
	$kwat  = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_daw_kw_at' AND post_id IN ($in)", OBJECT_K ); // phpcs:ignore WordPress.DB
	$cov   = function_exists( 'daw_covp_rec' ) ? (array) daw_covp_rec() : array();
	$out   = array();
	foreach ( $ids as $id ) {
		$m          = isset( $audit[ $id ] ) ? maybe_unserialize( $audit[ $id ]->meta_value ) : null;
		$m          = is_array( $m ) ? $m : null;
		$out[ $id ] = array(
			'title' => isset( $title[ $id ] ) ? $title[ $id ]->post_title : '#' . $id,
			'm'     => $m,
			'state' => $m ? daw_wc_state_cast( 'damga' === daw_wc_state( $m ) ? 'bitti' : daw_wc_state( $m ), $cov[ $id ] ?? null ) : 'yok',
			'views' => isset( $views[ $id ] ) ? (int) $views[ $id ]->meta_value : 0,
		);
		// "Temel bitti" (1.75.52, Umut 8 Oct 2026): everything we can fill
		// ourselves is filled (no red line, nothing unlooked, stamp valid).
		// What is left out of it is what needs a new record or a link: the
		// cast and filmography coverage. "Tam bitti" adds that.
		$out[ $id ]['level'] = daw_wc_level( $out[ $id ]['state'], $m );
		$out[ $id ]['basic'] = 'eksik' !== $out[ $id ]['level'];
		$out[ $id ]['gap']   = isset( $cov[ $id ] ) ? max( 0, (int) $cov[ $id ][1] - (int) $cov[ $id ][2] ) : 0;
		$out[ $id ]['nmiss'] = $m ? daw_wc_miss_count( $m ) : 0;
		$out[ $id ]['kw']    = daw_wc_kw( $m, isset( $kwm[ $id ] ) ? $kwm[ $id ]->meta_value : '', isset( $kwat[ $id ] ) ? (string) $kwat[ $id ]->meta_value : '' );
	}
	return $out;
}

/**
 * Keyword research at a glance (1.75.70, Umut 14 Oct 2026): was Ubersuggest
 * asked for this record, how many patterns were saved, how much monthly
 * search they carry, and how many sit on the page. Read from the saved
 * patterns (_daw_kw) and the stored audit, so nothing is re-audited for it.
 *
 * state: 'none' never researched, 'zero' researched and nothing with volume,
 * 'part' some patterns not on the page, 'ok' all placed.
 */
function daw_wc_kw( $m, $raw, $at ) {
	$list = maybe_unserialize( $raw );
	if ( is_string( $list ) ) {
		$list = json_decode( $list, true );
	}
	$list = is_array( $list ) ? array_filter( $list ) : array();
	// Every pattern the research returned is kept (1.75.70, Umut): targets are
	// scored, "n" ones are related research, "x" ones are off-topic and never
	// written. The cell shows targets placed and how many were found in all.
	$n    = 0;
	$vol  = 0;
	$all  = 0;
	$vall = 0;
	foreach ( $list as $k ) {
		if ( '' === trim( (string) ( is_array( $k ) ? ( $k['kw'] ?? '' ) : $k ) ) ) {
			continue;
		}
		$v = is_array( $k ) ? (int) ( $k['vol'] ?? 0 ) : 0;
		$all++;
		$vall += $v;
		if ( is_array( $k ) && ( ! empty( $k['n'] ) || ! empty( $k['x'] ) ) ) {
			continue;
		}
		$n++;
		$vol += $v;
	}
	$miss = 0;
	$none = false;
	foreach ( (array) ( $m['fails'] ?? array() ) as $f ) {
		$label = (string) ( $f[1] ?? '' );
		if ( 'Anahtar kelime araştırması' === $label || 'Tam kelime araştırması' === $label ) {
			$none = true;
		} elseif ( '' !== $label && '"' === $label[0] && 'no' === ( $f[2] ?? '' ) ) {
			$miss++;
		}
	}
	if ( ! $n ) {
		$state = ( $none || ! $m ) && ! $all ? 'none' : 'zero';
	} else {
		$state = $miss ? 'part' : 'ok';
	}
	// Lists saved before the full-research rule hold only the placed
	// patterns: they count as not researched until redone (1.75.71).
	$full = defined( 'DAW_KW_FULL_SINCE' ) && '' !== $at && $at >= DAW_KW_FULL_SINCE;
	if ( ! $full && 'zero' !== $state ) {
		$state = $all ? 'old' : 'none';
	}
	return array( 'state' => $state, 'n' => $n, 'vol' => $vol, 'placed' => max( 0, $n - $miss ), 'all' => $all, 'vall' => $vall, 'at' => $at );
}

/** The Kelime cell. */
function daw_wc_kw_cell( $kw ) {
	$at = '' !== (string) ( $kw['at'] ?? '' ) ? ' · ' . esc_html( (string) $kw['at'] ) : '';
	switch ( $kw['state'] ?? 'none' ) {
		case 'zero':
			return '<td style="font-size:12px;color:#50575e;white-space:nowrap" title="Araştırıldı, hacimli kalıp çıkmadı">0 kalıp' . $at . '</td>';
		case 'part':
		case 'ok':
			return sprintf(
				'<td style="font-size:12px;white-space:nowrap;color:%s" title="%s">%d / %d hedef · %s/ay%s</td>',
				'ok' === $kw['state'] ? '#1f6f5c' : '#a8431a',
				esc_attr( sprintf( 'Araştırmada %d kalıp bulundu (aylık %d arama). %d tanesi hedef, %d hedef sayfada; aylık %d arama hedefleniyor. Kalanlar ilgili araştırma ya da konu dışı.', $kw['all'], $kw['vall'], $kw['n'], $kw['placed'], $kw['vol'] ) ),
				(int) $kw['placed'],
				(int) $kw['n'],
				esc_html( number_format_i18n( (int) $kw['vol'] ) ),
				$at
			);
	}
	if ( 'old' === ( $kw['state'] ?? '' ) ) {
		return sprintf( '<td style="font-size:12px;color:#8a1f1f;white-space:nowrap" title="Eski liste: yalnız yerleştirilen kalıplar kaydedilmiş, tam araştırma yapılacak">Eski liste · %d kalıp</td>', (int) $kw['all'] );
	}
	return '<td style="font-size:12px;color:#8a1f1f;white-space:nowrap" title="Ubersuggest araştırması kayıtlı değil">Yapılmadı</td>';
}

/** What a record still needs, as short labels (red first, then unlooked). */
function daw_wc_needs( array $m, $max = 4 ) {
	$red = array();
	$eks = 0;
	foreach ( (array) ( $m['fails'] ?? array() ) as $f ) {
		if ( 'no' === ( $f[2] ?? '' ) ) {
			$red[] = ( '' !== (string) ( $f[4] ?? '' ) ? $f[4] . ' ' : '' ) . $f[1];
		} elseif ( 'eksik' === ( $f[2] ?? '' ) ) {
			$eks++;
		}
	}
	$out = array_slice( array_unique( $red ), 0, $max );
	if ( count( $red ) > $max ) {
		$out[] = '+' . ( count( $red ) - $max ) . ' kırmızı';
	}
	if ( $eks ) {
		$out[] = $eks . ' alan bakılmadı';
	}
	return $out;
}

/** How many fields still need work: red lines plus unlooked fields. */
function daw_wc_miss_count( array $m ) {
	$n = 0;
	foreach ( (array) ( $m['fails'] ?? array() ) as $f ) {
		if ( in_array( $f[2] ?? '', array( 'no', 'eksik' ), true ) ) {
			$n++;
		}
	}
	return $n;
}

function daw_wc_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification
	$f_st  = sanitize_key( $_GET['st'] ?? '' );
	$f_g   = sanitize_key( $_GET['g'] ?? '' );
	$f_q   = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
	$f_kw  = sanitize_key( $_GET['kw'] ?? '' );
	$sort  = sanitize_key( $_GET['sort'] ?? 'views' );
	$per   = (int) ( $_GET['per'] ?? 50 );
	$per   = in_array( $per, array( 20, 50, 100, 250 ), true ) ? $per : 50;
	$paged = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	// phpcs:enable
	$base   = admin_url( 'admin.php?page=daw-denetim' );
	$keep   = array_filter( array( 'st' => $f_st, 'g' => $f_g, 'q' => $f_q, 'kw' => $f_kw, 'sort' => 'views' !== $sort ? $sort : '', 'per' => 50 !== $per ? $per : '' ) );
	$rows   = daw_wc_rows();
	$states = daw_wc_levels();
	$groups = function_exists( 'daw_ae_groups' ) ? daw_ae_groups() : array();

	$count = array_fill_keys( array_keys( $states ), 0 );
	foreach ( $rows as $r ) {
		$count[ $r['level'] ]++;
	}
	$total = count( $rows );
	$done  = $count['tam'];
	$basic = $count['tam'] + $count['temel'];
	$pct   = $total ? round( 100 * $basic / $total, 1 ) : 0;

	echo '<div class="wrap daw-wc"><h1>DAW › İşler</h1>';
	?>
	<style>
	.daw-wc .wc-bar{height:10px;border-radius:99px;background:#eceae6;overflow:hidden;max-width:900px;margin:6px 0 4px}
	.daw-wc .wc-bar i{display:block;height:100%;background:#1f6f5c}
	.daw-wc .wc-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}
	.daw-wc .wc-tab{display:block;text-decoration:none;border-radius:10px;padding:8px 14px;border:2px solid transparent;min-width:130px}
	.daw-wc .wc-tab.is-on{border-color:#7e2128}
	.daw-wc .wc-tab b{display:block;font-size:22px;line-height:1.2}
	.daw-wc .wc-box{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:12px 16px;max-width:1300px;margin:12px 0}
	.daw-wc .wc-pill{display:inline-block;padding:1px 8px;border-radius:99px;font-size:11px;font-weight:600;white-space:nowrap}
	.daw-wc .wc-need{font-size:12px;color:#50575e}
	.daw-wc table td{vertical-align:middle}
	.daw-wc .wc-pager{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:10px 0}
	</style>
	<?php
	printf( '<p style="font-size:15px;margin:4px 0"><strong>%d tam bitti</strong> · <strong>%d temel bitti</strong> · <strong>%d temelde eksik</strong> · toplam %d kayıt</p>', (int) $done, max( 0, (int) $basic - (int) $done ), (int) $count['eksik'], (int) $total );
	printf( '<div class="wc-bar"><i style="width:%s%%"></i></div>', esc_attr( (string) $pct ) );
	if ( function_exists( 'daw_covp_strip' ) ) {
		daw_covp_strip();
	}
	if ( function_exists( 'daw_trailer_recheck_due' ) ) {
		$tr_due = daw_trailer_recheck_due();
		if ( $tr_due ) {
			$links = array();
			foreach ( array_slice( array_keys( $tr_due ), 0, 30 ) as $tid ) {
				$links[] = '<a href="' . esc_url( add_query_arg( 'record', $tid, $base ) ) . '">' . esc_html( get_the_title( $tid ) ) . '</a>';
			}
			printf( '<p class="wc-need"><strong>Fragmanı yeniden aranacak: %d kayıt</strong> (bulunamadı yazılalı %d günden fazla oldu): %s</p>', count( $tr_due ), (int) DAW_TRAILER_RECHECK, implode( ', ', $links ) );
		}
	}

	// 1. State tabs.
	echo '<div class="wc-tabs">';
	printf( '<a class="wc-tab%s" href="%s" style="background:#fff;color:#1d2327;border-color:%s"><b>%d</b>Hepsi</a>', '' === $f_st ? ' is-on' : '', esc_url( add_query_arg( array_diff_key( $keep, array( 'st' => 1 ) ), $base ) ), '' === $f_st ? '#7e2128' : '#dcdcde', (int) $total );
	foreach ( $states as $k => $s ) {
		printf(
			'<a class="wc-tab%s" href="%s" title="%s" style="background:%s;color:%s"><b>%d</b>%s</a>',
			$f_st === $k ? ' is-on' : '',
			esc_url( add_query_arg( array_merge( $keep, array( 'st' => $k ) ), $base ) ),
			esc_attr( $s[3] ),
			esc_attr( $s[1] ),
			esc_attr( $s[2] ),
			(int) $count[ $k ],
			esc_html( $s[0] )
		);
	}
	echo '</div>';

	// 2. Automatic jobs: start and stop.
	$left  = function_exists( 'daw_auto_left' ) ? daw_auto_left() : array();
	$jobs  = function_exists( 'daw_auto_jobs' ) ? daw_auto_jobs() : array();
	$last  = get_option( 'daw_auto_state' );
	$nonce = wp_create_nonce( 'daw_auto_run' );
	echo '<div class="wc-box"><strong>Otomatik işler</strong> <span style="color:#50575e">(yapay zekâ harcamaz; sayfa kapalıyken de saatte bir küçük dilimlerle sürer)</span><div style="display:flex;gap:18px;flex-wrap:wrap;margin:8px 0">';
	foreach ( $jobs as $k => $j ) {
		printf( '<span>%s: <b data-left="%s">%d</b> kalan</span>', esc_html( $j[0] ), esc_attr( $k ), (int) ( $left[ $k ] ?? 0 ) );
	}
	echo '</div><button type="button" class="button button-primary" id="wc-run">Başlat</button> <button type="button" class="button" id="wc-stop" disabled>Durdur</button> <span id="wc-msg" style="margin-left:8px;color:#50575e">';
	if ( is_array( $last ) && ! empty( $last['at'] ) ) {
		echo esc_html( 'Son çalışma ' . human_time_diff( (int) $last['at'] ) . ' önce' . ( ! empty( $last['job'] ) ? ': ' . $last['job'] : '' ) );
	}
	echo '</span></div>';
	?>
	<script>
	(function () {
		var run = document.getElementById('wc-run'), stop = document.getElementById('wc-stop'), m = document.getElementById('wc-msg'), on = false, n = 0;
		function step() {
			if (!on) { m.textContent = 'Durduruldu. ' + n + ' adım yapıldı.'; return; }
			fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'daw_auto_run', nonce: <?php echo wp_json_encode( $nonce ); ?> }) })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.success) { m.textContent = 'Hata, 10 sn sonra yeniden.'; setTimeout(step, 10000); return; }
					n++;
					Object.keys(d.data.left || {}).forEach(function (k) { var c = document.querySelector('[data-left="' + k + '"]'); if (c) { c.textContent = d.data.left[k]; } });
					m.textContent = d.data.job ? (d.data.job + ': ' + d.data.done + ' kayıt') : '';
					if (d.data.done > 0) { setTimeout(step, 1500); } else { on = false; run.disabled = false; stop.disabled = true; m.textContent = 'Tamamlandı. Sayfayı yenileyince tablo güncellenir.'; }
				})
				.catch(function () { setTimeout(step, 10000); });
		}
		run.addEventListener('click', function () { on = true; run.disabled = true; stop.disabled = false; m.textContent = 'Çalışıyor…'; step(); });
		stop.addEventListener('click', function () { on = false; run.disabled = false; stop.disabled = true; });
	})();
	</script>
	<?php

	// 3. Filters.
	echo '<form method="get" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:14px 0 6px"><input type="hidden" name="page" value="daw-denetim">';
	if ( $f_st ) {
		printf( '<input type="hidden" name="st" value="%s">', esc_attr( $f_st ) );
	}
	printf( '<input type="search" name="q" value="%s" placeholder="Kayıt ara">', esc_attr( $f_q ) );
	echo '<select name="g"><option value="">Bütün türler</option>';
	foreach ( $groups as $gk => $g ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $gk ), selected( $f_g, $gk, false ), esc_html( $g['label'] ) );
	}
	echo '</select><select name="kw">';
	foreach ( array( '' => 'Kelime: hepsi', 'none' => 'Ubersuggest yapılmamış', 'old' => 'Eski liste (tam araştırma yok)', 'part' => 'Kalıp sayfada eksik', 'zero' => 'Araştırıldı, hacim yok', 'ok' => 'Kalıplar yerleşmiş' ) as $k => $l ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $f_kw, $k, false ), esc_html( $l ) );
	}
	echo '</select><select name="sort">';
	foreach ( array( 'views' => 'Önce en çok okunan', 'need' => 'Önce en çok eksiği olan', 'title' => 'Ada göre', 'at' => 'Önce en eski denetim', 'cov' => 'Önce TMDB\'ye göre en boş' ) as $k => $l ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $sort, $k, false ), esc_html( $l ) );
	}
	echo '</select><select name="per">';
	foreach ( array( 20, 50, 100, 250 ) as $p ) {
		printf( '<option value="%d"%s>Sayfada %d</option>', $p, selected( $per, $p, false ), $p );
	}
	echo '</select>';
	submit_button( 'Göster', 'secondary', '', false );
	echo '</form>';

	// 4. The table.
	$list = array_filter(
		$rows,
		static function ( $r ) use ( $f_st, $f_g, $f_q, $f_kw ) {
			if ( $f_st && $r['level'] !== $f_st ) {
				return false;
			}
			if ( $f_kw && ( $r['kw']['state'] ?? '' ) !== $f_kw ) {
				return false;
			}
			if ( $f_g && ( ! $r['m'] || ( $r['m']['group'] ?? '' ) !== $f_g ) ) {
				return false;
			}
			return '' === $f_q || false !== mb_stripos( $r['title'], $f_q );
		}
	);
	$cov  = function_exists( 'daw_covp_rec' ) ? daw_covp_rec() : array();
	$cpct = static function ( $id ) use ( $cov ) {
		return isset( $cov[ $id ] ) && $cov[ $id ][1] ? $cov[ $id ][2] / $cov[ $id ][1] : 2;
	};
	$need = static function ( $r ) {
		$s = (array) ( $r['m']['sum'] ?? array() );
		return (int) ( $s['no'] ?? 0 ) * 100 + (int) ( $s['eksik'] ?? 0 );
	};
	uasort(
		$list,
		static function ( $a, $b ) use ( $sort, $need ) {
			switch ( $sort ) {
				case 'cov':
					return 0; // ordered by id below, where the key is known.

				case 'need':
					return $need( $b ) <=> $need( $a ) ?: strcasecmp( $a['title'], $b['title'] );
				case 'title':
					return strcasecmp( $a['title'], $b['title'] );
				case 'at':
					return (int) ( $a['m']['at'] ?? 0 ) <=> (int) ( $b['m']['at'] ?? 0 );
			}
			return $b['views'] <=> $a['views'] ?: strcasecmp( $a['title'], $b['title'] );
		}
	);
	if ( 'cov' === $sort ) {
		uksort( $list, static fn( $a, $b ) => $cpct( $a ) <=> $cpct( $b ) );
	}
	$n     = count( $list );
	$pages = max( 1, (int) ceil( $n / $per ) );
	$paged = min( $paged, $pages );
	$slice = array_slice( $list, ( $paged - 1 ) * $per, $per, true );

	echo '<table class="widefat striped" style="max-width:1300px"><thead><tr><th style="width:34px">#</th><th>Kayıt</th><th>Tür</th><th>Durum</th><th>Ne kaldı</th><th title="TMDB\'deki kadro ya da filmografi satırlarından kaçı sitede bağlı">TMDB</th><th title="Ubersuggest kalıpları: sayfaya yerleşen / kayıtlı, aylık toplam arama">Kelime</th><th>Model</th><th style="text-align:right">30 gün</th><th>Son denetim</th><th></th></tr></thead><tbody>';
	$i = ( $paged - 1 ) * $per;
	foreach ( $slice as $id => $r ) {
		$i++;
		$st   = $states[ $r['level'] ];
		$m    = $r['m'];
		$left = array();
		if ( 'eksik' === $r['level'] ) {
			$left = $m ? daw_wc_needs( $m ) : array( 'Denetim henüz çalışmadı' );
			if ( $r['nmiss'] ) {
				array_unshift( $left, $r['nmiss'] . ' alan eksik' );
			}
		} elseif ( 'temel' === $r['level'] ) {
			$left = array( sprintf( 'Kadro/film: %d kayıt bağlı değil', (int) $r['gap'] ) );
		}
		$tier = $m && 'tam' !== $r['level'] ? daw_wc_tier( $m ) : '';
		printf(
			'<tr><td>%d</td><td><a href="%s" target="_blank" rel="noopener"><strong>%s</strong></a>%s</td><td>%s</td><td><span class="wc-pill" style="background:%s;color:%s">%s</span></td><td class="wc-need">%s</td>%s%s<td>%s</td><td style="text-align:right">%d</td><td style="font-size:12px;color:#50575e">%s</td><td style="white-space:nowrap;font-size:12px"><a href="%s">Denetim</a> · <a href="%s">Düzenle</a></td></tr>',
			(int) $i,
			esc_url( get_permalink( $id ) ),
			esc_html( $r['title'] ),
			! empty( $m['lock'] ) ? ' 🔒' : '',
			esc_html( (string) ( $m['type'] ?? '' ) ),
			esc_attr( $st[1] ),
			esc_attr( $st[2] ),
			esc_html( $st[0] ),
			esc_html( implode( ' · ', $left ) ),
			isset( $cov[ $id ] ) && $cov[ $id ][1]
				? sprintf( '<td style="white-space:nowrap;font-size:12px;color:%s" title="%s">%d / %d</td>', $cov[ $id ][2] >= $cov[ $id ][1] ? '#1f6f5c' : ( $cov[ $id ][3] ? '#a8431a' : '#50575e' ), esc_attr( sprintf( '%d hemen bağlanabilir, %d kaydı yok', $cov[ $id ][3], $cov[ $id ][4] ) ), $cov[ $id ][2], $cov[ $id ][1] )
				: '<td style="font-size:12px;color:#8a8178" title="Henüz TMDB ile karşılaştırılmadı">–</td>',
			daw_wc_kw_cell( $r['kw'] ),
			esc_html( $tier ? ucfirst( $tier ) : '' ),
			(int) $r['views'],
			esc_html( ! empty( $m['at'] ) ? human_time_diff( (int) $m['at'] ) . ' önce' : '' ),
			esc_url( add_query_arg( 'record', $id, $base ) ),
			esc_url( (string) get_edit_post_link( $id ) )
		);
	}
	if ( ! $slice ) {
		echo '<tr><td colspan="11">Bu süzgeçte kayıt yok.</td></tr>';
	}
	echo '</tbody></table>';

	// Pager.
	if ( $pages > 1 ) {
		echo '<div class="wc-pager">';
		printf( '<span>%d kayıt · sayfa %d / %d</span>', (int) $n, (int) $paged, (int) $pages );
		foreach ( array_unique( array( 1, max( 1, $paged - 2 ), max( 1, $paged - 1 ), $paged, min( $pages, $paged + 1 ), min( $pages, $paged + 2 ), $pages ) ) as $p ) {
			printf( ' <a class="button%s" href="%s">%d</a>', $p === $paged ? ' button-primary' : '', esc_url( add_query_arg( array_merge( $keep, array( 'paged' => $p ) ), $base ) ), (int) $p );
		}
		echo '</div>';
	}

	// 5. Rules and the rest, once.
	$more = array(
		'daw-kurallar' => 'Kurallar',
		'daw-kapsam'   => 'Kapsam (eksik kişi ve yapımlar)',
		'daw-fresh'    => 'Güncellik',
		'daw-backlog'  => 'Eksikler (eski liste)',
		'daw-archive'  => 'Arşiv denetimi (eski)',
		'daw-is-sirasi' => 'İş sırası (ajan için)',
	);
	echo '<p class="wc-need" style="margin-top:18px">Kurallar tek yerde: <a href="' . esc_url( admin_url( 'admin.php?page=daw-kurallar' ) ) . '"><strong>DAW › Kurallar</strong></a>. Yapay zekâya "DAW İşler\'den sıradaki 10 kaydı bitir" demek yeter; kuralları oradan okur. Diğer ekranlar: ';
	$links = array();
	foreach ( $more as $slug => $label ) {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
	}
	echo implode( ' · ', $links ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * The menu: İşler first, and the screens it replaces out of the list (they
 * stay registered, so old links open).
 */
add_action( 'admin_menu', 'daw_wc_menu', 1000 );
function daw_wc_menu() {
	global $submenu;
	// These stay registered so old links open — but remove_submenu_page()
	// alone does not do that: it also drops the page from admin.php's own
	// list of pages anyone (capability included) may open, so without the
	// null-parent re-add below the comment above was wishful, not true.
	// Found 28 Sep 2026 alongside the matching bug in admin-home.php's
	// daw_menu_build() — daw-archive ("Arşiv denetimi") came back "Sorry,
	// you are not allowed to access this page." for the site owner.
	$hidden = array(
		'daw-otomatik'  => 'daw_auto_page',
		'daw-is-sirasi' => 'daw_wq_page',
		'daw-archive'   => 'daw_audit_wide_page',
		'daw-backlog'   => 'daw_backlog_page',
		'daw-fresh'     => 'daw_fresh_page',
		'daw-kapsam'    => 'daw_covp_page',
	);
	foreach ( $hidden as $slug => $callback ) {
		remove_submenu_page( 'daw', $slug );
		if ( function_exists( $callback ) ) {
			add_submenu_page( null, $slug, $slug, 'manage_options', $slug, $callback );
		}
	}
	if ( empty( $submenu['daw'] ) ) {
		return;
	}
	foreach ( $submenu['daw'] as $i => $item ) {
		if ( 'daw-denetim' === ( $item[2] ?? '' ) ) {
			$item[0] = 'İşler';
			unset( $submenu['daw'][ $i ] );
			array_splice( $submenu['daw'], 1, 0, array( $item ) );
			break;
		}
	}
	$submenu['daw'] = array_values( $submenu['daw'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
}
