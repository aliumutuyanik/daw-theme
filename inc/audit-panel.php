<?php
/**
 * Discover Asian World: DAW › Denetim, the screen over the audit engine.
 *
 * List: every record, one row, the six columns of the engine (fields, rules,
 * SEO, schema, picture, freshness), filters by type group, state and failing
 * column, 50 rows a page (rule 8.1). Detail: one record, every field and every
 * rule, each with its state and the reason.
 *
 * Numbers come from the stored short audits (one query for the lot), never
 * from re-auditing a thousand records on page load. "Tümünü denetle" rebuilds
 * them in bites while the page is open, like the pictures job.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'daw_ap_menu', 11 );
function daw_ap_menu() {
	add_submenu_page( 'daw', 'Denetim', 'Denetim', 'manage_options', 'daw-denetim', 'daw_ap_page' );
}

function daw_ap_state_of( $m ) {
	$s = $m['sum'] ?? array();
	if ( ( $s['no'] ?? 0 ) + ( $s['eksik'] ?? 0 ) > 0 ) {
		return 'no';
	}
	return ( $s['wait'] ?? 0 ) > 0 ? 'wait' : 'ok';
}

function daw_ap_icon( $st ) {
	$map = array(
		'ok'    => array( '✓', '#1f6f5c', 'tamam' ),
		'no'    => array( '✗', '#a8431a', 'düzeltilecek' ),
		'eksik' => array( '✗', '#a8431a', 'eksik' ),
		'wait'  => array( '⏱', '#7a5a12', 'bakılacak' ),
		'yok'   => array( 'N/A', '#1f6f5c', 'N/A · kontrol edildi, bulunamadı' ),
		'na'    => array( '—', '#8a8178', 'uygulanmaz' ),
	);
	$m = $map[ $st ] ?? array( '·', '#8a8178', '' );
	return sprintf( '<span title="%s" style="color:%s;font-weight:700;font-size:%s">%s</span>', esc_attr( $m[2] ), esc_attr( $m[1] ), 'N/A' === $m[0] ? '11px' : '15px', esc_html( $m[0] ) );
}

/** All stored short audits, keyed by post id, with titles. */
function daw_ap_rows() {
	global $wpdb;
	$raw = $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, m.meta_value, p.post_title FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status = 'publish' AND p.post_type = 'post'", DAW_AE_META ) ); // phpcs:ignore WordPress.DB
	$out = array();
	foreach ( $raw as $r ) {
		$m = maybe_unserialize( $r->meta_value );
		if ( is_array( $m ) ) {
			$m['title']              = $r->post_title;
			$out[ (int) $r->post_id ] = $m;
		}
	}
	return $out;
}

function daw_ap_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! empty( $_GET['record'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		daw_ap_detail( (int) $_GET['record'] ); // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	// The list is DAW › İşler now; the column view stays behind ?view=sutun.
	if ( function_exists( 'daw_wc_page' ) && 'sutun' !== ( $_GET['view'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		daw_wc_page();
		return;
	}

	$rows   = daw_ap_rows();
	$groups = daw_ae_groups();
	$f_g    = sanitize_key( $_GET['g'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	$f_s    = sanitize_key( $_GET['s'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	$f_c    = sanitize_key( $_GET['c'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$base   = admin_url( 'admin.php?page=daw-denetim' );
	$total  = count( function_exists( 'daw_audit_record_ids' ) ? daw_audit_record_ids( daw_ae_all_types() ) : array() );

	echo '<div class="wrap"><h1>DAW › Denetim</h1>';
	printf(
		'<p style="max-width:70em">Her kayıt kendi türünün bütün alanlarından ve sitenin kurallarından geçer. ✓ kaynaklı ve kurala uygun · N/A kontrol edildi, bulunamadı (yeşil sayılır) · ✗ düzeltilecek ya da hiç bakılmadı · ⏱ yeniden bakılacak · — bu kayıtta uygulanmaz. Sütun başlığının üstüne gelince ne denetlendiği yazar. Denetlenen: <strong>%d / %d</strong> kayıt.</p>',
		count( $rows ),
		$total
	);

	// Rebuild everything, in bites, while the page is open.
	$nonce = wp_create_nonce( 'daw_ap_run' );
	echo '<p><button type="button" class="button button-primary" id="daw-ap-run">Tümünü şimdi denetle</button> <span id="daw-ap-msg" style="margin-left:8px;color:#50575e"></span></p>';
	?>
	<script>
	(function () {
		var b = document.getElementById('daw-ap-run'), m = document.getElementById('daw-ap-msg'), done = 0;
		b.addEventListener('click', function () {
			b.disabled = true;
			(function step() {
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'daw_ap_run', nonce: <?php echo wp_json_encode( $nonce ); ?> }) })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						if (!d || !d.success) { m.textContent = 'Hata, 5 sn sonra yeniden denenecek.'; setTimeout(step, 5000); return; }
						done += d.data.done;
						m.textContent = done + ' kayıt denetlendi, kalan yaklaşık ' + d.data.left + '.';
						if (d.data.done > 0) { setTimeout(step, 300); } else { m.textContent = 'Bitti: ' + done + ' kayıt. Sayfa yenileniyor.'; setTimeout(function () { location.reload(); }, 800); }
					})
					.catch(function () { setTimeout(step, 5000); });
			})();
		});
	})();
	</script>
	<?php

	// Cards.
	echo '<div style="display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;max-width:1300px;margin:14px 0">';
	foreach ( $groups as $gk => $g ) {
		$c = array( 'ok' => 0, 'no' => 0, 'wait' => 0 );
		$n = 0;
		foreach ( $rows as $m ) {
			if ( ( $m['group'] ?? '' ) === $gk ) {
				$n++;
				$c[ daw_ap_state_of( $m ) ]++;
			}
		}
		printf(
			'<a href="%s" style="text-decoration:none;color:inherit;background:#fff;border:1px solid %s;border-radius:10px;padding:12px 14px;display:block"><div style="font-weight:600">%s</div><div style="font-size:26px;font-weight:600;margin:4px 0">%d</div><div style="font-size:12px"><span style="color:#1f6f5c">%d tam</span> · <span style="color:#a8431a">%d eksik</span> · <span style="color:#7a5a12">%d bakılacak</span></div></a>',
			esc_url( add_query_arg( 'g', $gk, $base ) ),
			$f_g === $gk ? '#7e2128' : '#dcdcde',
			esc_html( $g['label'] ),
			$n,
			$c['ok'],
			$c['no'],
			$c['wait']
		);
	}
	echo '</div>';

	// Filters.
	$cols = daw_ae_columns();
	echo '<form method="get" style="margin:6px 0 12px"><input type="hidden" name="page" value="daw-denetim">';
	echo '<select name="g"><option value="">Bütün türler</option>';
	foreach ( $groups as $gk => $g ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $gk ), selected( $f_g, $gk, false ), esc_html( $g['label'] ) );
	}
	echo '</select> <select name="s"><option value="">Her durum</option>';
	foreach ( array( 'no' => 'Düzeltilecek / eksik', 'wait' => 'Bakılacak', 'ok' => 'Tam', 'lock' => 'Kilitli' ) as $k => $l ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $f_s, $k, false ), esc_html( $l ) );
	}
	echo '</select> <select name="c"><option value="">Her sütun</option>';
	foreach ( $cols as $k => $l ) {
		printf( '<option value="%s"%s>%s sorunlu</option>', esc_attr( $k ), selected( $f_c, $k, false ), esc_html( $l ) );
	}
	echo '</select> ';
	submit_button( 'Süz', 'secondary', '', false );
	echo '</form>';

	$list = array_filter(
		$rows,
		static function ( $m ) use ( $f_g, $f_s, $f_c ) {
			if ( $f_g && ( $m['group'] ?? '' ) !== $f_g ) {
				return false;
			}
			if ( 'lock' === $f_s ) {
				if ( empty( $m['lock'] ) ) {
					return false;
				}
			} elseif ( $f_s && daw_ap_state_of( $m ) !== $f_s ) {
				return false;
			}
			if ( $f_c && in_array( $m['col'][ $f_c ] ?? 'na', array( 'ok', 'na' ), true ) ) {
				return false;
			}
			return true;
		}
	);
	uasort(
		$list,
		static function ( $a, $b ) {
			$w = array( 'no' => 0, 'wait' => 1, 'ok' => 2 );
			return ( $w[ daw_ap_state_of( $a ) ] <=> $w[ daw_ap_state_of( $b ) ] ) ?: strcasecmp( $a['title'], $b['title'] );
		}
	);
	$count = count( $list );
	$list  = array_slice( $list, ( $paged - 1 ) * 50, 50, true );

	echo '<table class="widefat striped" style="max-width:1500px"><thead><tr><th>Kayıt</th><th>Tür</th><th style="text-align:center">Sayım</th>';
	foreach ( $cols as $k => $l ) {
		printf( '<th style="text-align:center;font-size:11px;padding:8px 2px" title="%s">%s</th>', esc_attr( daw_ap_col_help( $k ) ), esc_html( $l ) );
	}
	echo '<th>Durum</th><th>Son denetim</th></tr></thead><tbody>';
	foreach ( $list as $pid => $m ) {
		$s = $m['sum'];
		printf(
			'<tr><td><a href="%s"><strong>%s</strong></a>%s</td><td>%s</td><td style="text-align:center;font-size:12px;color:#50575e;white-space:nowrap">%d ✓ · %d N/A · %d ✗</td>',
			esc_url( add_query_arg( 'record', $pid, $base ) ),
			esc_html( $m['title'] ),
			! empty( $m['lock'] ) ? ' <span title="Kilitli: içerik tam denetimden geçti, yeniden yazılmaz" style="font-size:12px;color:#1f6f5c;font-weight:600">🔒 kilitli</span>' : '',
			esc_html( $m['type'] ),
			(int) ( $s['ok'] ?? 0 ),
			(int) ( $s['yok'] ?? 0 ),
			(int) ( $s['eksik'] ?? 0 ) + (int) ( $s['no'] ?? 0 )
		);
		$old = (int) ( $m['v'] ?? 0 ) < DAW_AE_VERSION;
		foreach ( $cols as $k => $l ) {
			echo '<td style="text-align:center;padding:8px 2px">' . daw_ap_icon( $old ? 'wait' : ( $m['col'][ $k ] ?? 'na' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$st  = daw_ap_state_of( $m );
		$lab = array( 'no' => array( '#fbe6db', '#8a3413', ( (int) ( $s['no'] ?? 0 ) + (int) ( $s['eksik'] ?? 0 ) ) . ' iş' ), 'wait' => array( '#f6ecc9', '#6a4d0c', (int) ( $s['wait'] ?? 0 ) . ' bakılacak' ), 'ok' => array( '#e3f1ec', '#185a4a', 'tam' ) );
		printf(
			'<td><span style="background:%s;color:%s;border-radius:999px;padding:2px 9px;font-size:12px;font-weight:600">%s</span></td><td style="font-size:12px;color:#50575e">%s önce</td></tr>',
			esc_attr( $lab[ $st ][0] ),
			esc_attr( $lab[ $st ][1] ),
			esc_html( $lab[ $st ][2] ),
			esc_html( human_time_diff( (int) $m['at'] ) )
		);
	}
	if ( ! $list ) {
		echo '<tr><td colspan="19">Bu süzgeçte kayıt yok. Henüz denetlenmemişse "Tümünü şimdi denetle"ye basın.</td></tr>';
	}
	echo '</tbody></table>';

	$pages = (int) ceil( $count / 50 );
	if ( $pages > 1 ) {
		echo '<p>';
		for ( $i = 1; $i <= $pages; $i++ ) {
			printf( '<a class="button%s" style="margin-right:4px" href="%s">%d</a>', $i === $paged ? ' button-primary' : '', esc_url( add_query_arg( array( 'g' => $f_g, 's' => $f_s, 'c' => $f_c, 'paged' => $i ), $base ) ), $i );
		}
		echo '</p>';
	}
	echo '</div>';
}

/** One row per audited line. */
function daw_ap_item_rows( $items, $pid = 0 ) {
	foreach ( $items as $it ) {
		$note = esc_html( 'yok' === $it[2] && '' === (string) $it[3] ? 'Kontrol edildi, kaynak bulunamadı.' : (string) $it[3] ) . daw_ap_rule_line( $it ) . daw_ap_accept_form( $it, $pid );
		if ( $pid && daw_ap_is_volatile( $it ) && in_array( $it[2], array( 'ok', 'yok' ), true ) ) {
			$note .= daw_ap_recheck_cell( $pid, $it );
		}
		printf(
			'<tr><td class="daw-ap-l">%s%s</td><td class="daw-ap-v">%s</td><td class="daw-ap-s">%s</td><td class="daw-ap-n">%s</td></tr>',
			'' !== (string) ( $it[5] ?? '' ) ? '<small>' . esc_html( (string) $it[5] ) . '</small> ' : '',
			esc_html( $it[0] ),
			esc_html( (string) $it[1] ),
			daw_ap_icon( $it[2] ), // phpcs:ignore WordPress.Security.EscapeOutput
			$note // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		);
	}
}

/* ================================================ facts that can change == */

/**
 * VERIFIED ONCE IS NOT VERIFIED FOREVER (1.61.0)
 * ----------------------------------------------
 * A birth date, a native name or a debut year is settled the day it is
 * sourced. Marital status, a partner, group membership, awards, airing
 * status, episodes, where to watch and the trailer are not: they change
 * while nobody looks (owner, 4 Oct 2026: "put what can change on top and
 * remind me every two months; move what is settled for good to the bottom").
 */
function daw_ap_volatile_keys() {
	return array( 'daw_marital_status', 'daw_spouse', 'daw_partner', 'daw_real_life_loves', 'daw_member_of', 'daw_member_role', 'daw_group_badge', 'daw_awards', 'daw_aw_', 'daw_mil_', 'daw_status', 'daw_episodes', 'daw_aired_end', 'daw_airing_days', 'daw_seasons', 'daw_season_', 'daw_watch_', 'daw_trailer_', 'daw_rating', 'daw_link_', 'daw_spotify_uri', 'daw_died' );
}

const DAW_AP_RECHECK_DAYS = 60;

function daw_ap_is_volatile( $it ) {
	$key = (string) ( $it[4] ?? '' );
	foreach ( daw_ap_volatile_keys() as $k ) {
		if ( '' !== $key && 0 === strpos( $key, $k ) ) {
			return true;
		}
	}
	return (bool) preg_match( '/pairing|partner|spouse|marital|relationship|dating|agency|military|award|member|upcoming|status|episodes|watch|trailer/i', (string) $it[0] );
}

/** When a changeable line was last confirmed: its own stamp, else the record's verification. */
function daw_ap_recheck_at( $pid, $it ) {
	$stamps = get_post_meta( $pid, '_daw_recheck', true );
	$key    = (string) ( '' !== (string) ( $it[4] ?? '' ) ? $it[4] : $it[0] );
	if ( is_array( $stamps ) && ! empty( $stamps[ $key ] ) ) {
		return (int) $stamps[ $key ];
	}
	$v = get_post_meta( $pid, 'daw_verified', true );
	if ( is_array( $v ) && ! empty( $v['at'] ) ) {
		return (int) $v['at'];
	}
	return (int) get_post_modified_time( 'U', true, $pid );
}

function daw_ap_recheck_due( $pid, $it ) {
	return time() - daw_ap_recheck_at( $pid, $it ) > DAW_AP_RECHECK_DAYS * DAY_IN_SECONDS;
}

function daw_ap_recheck_cell( $pid, $it ) {
	$at  = daw_ap_recheck_at( $pid, $it );
	$due = daw_ap_recheck_due( $pid, $it );
	$key = (string) ( '' !== (string) ( $it[4] ?? '' ) ? $it[4] : $it[0] );
	$out = sprintf(
		'<div class="daw-ap-re%s">%s</div>',
		$due ? ' is-due' : '',
		$due
			? esc_html( sprintf( 'Yeniden kontrol zamanı geldi (son kontrol %s önce).', human_time_diff( $at ) ) )
			: esc_html( sprintf( 'Son kontrol %s önce · %s sonra yeniden', human_time_diff( $at ), human_time_diff( time(), $at + DAW_AP_RECHECK_DAYS * DAY_IN_SECONDS ) ) )
	);
	if ( $due ) {
		$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="daw-ap-reform">'
			. '<input type="hidden" name="action" value="daw_ap_recheck"><input type="hidden" name="record" value="' . (int) $pid . '">'
			. '<input type="hidden" name="key" value="' . esc_attr( $key ) . '">'
			. wp_nonce_field( 'daw_ap_recheck_' . (int) $pid, 'n', true, false )
			. '<button type="submit" class="button button-small">Kontrol ettim, hâlâ doğru</button></form>';
	}
	return $out;
}

add_action( 'admin_post_daw_ap_recheck', 'daw_ap_recheck' );
function daw_ap_recheck() {
	$pid = isset( $_POST['record'] ) ? (int) $_POST['record'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $pid || '' === $key || ! current_user_can( 'manage_options' ) || ! isset( $_POST['n'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['n'] ) ), 'daw_ap_recheck_' . $pid ) ) {
		wp_die( 'Yetkisiz', 403 );
	}
	$stamps = get_post_meta( $pid, '_daw_recheck', true );
	$stamps = is_array( $stamps ) ? $stamps : array();
	if ( 'all' === $key ) {
		$a = get_post_meta( $pid, DAW_AE_META, true );
		foreach ( (array) ( $a['sections'] ?? array() ) as $sec ) {
			foreach ( (array) ( $sec['items'] ?? array() ) as $it ) {
				if ( daw_ap_is_volatile( $it ) ) {
					$stamps[ (string) ( '' !== (string) ( $it[4] ?? '' ) ? $it[4] : $it[0] ) ] = time();
				}
			}
		}
	} else {
		$stamps[ $key ] = time();
	}
	update_post_meta( $pid, '_daw_recheck', $stamps );
	set_transient( 'daw_ap_one_' . $pid, 'Kontrol tarihi kaydedildi; 60 gün sonra yeniden hatırlatılacak.', 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'admin.php?page=daw-denetim&record=' . $pid . '#daw-ap-volatile' ) );
	exit;
}

/**
 * Once a day: how many records hold a changeable line older than sixty
 * days, for the control centre. Reads the stored audits only.
 */
add_action( 'daw_ae_cron', 'daw_ap_recheck_count', 99 );
function daw_ap_recheck_count() {
	if ( (int) get_option( 'daw_recheck_due_at', 0 ) > time() - DAY_IN_SECONDS ) {
		return;
	}
	$due = array();
	foreach ( daw_ap_rows() as $pid => $a ) {
		foreach ( (array) ( $a['sections'] ?? array() ) as $sec ) {
			foreach ( (array) ( $sec['items'] ?? array() ) as $it ) {
				if ( in_array( $it[2] ?? '', array( 'ok', 'yok' ), true ) && daw_ap_is_volatile( $it ) && daw_ap_recheck_due( $pid, $it ) ) {
					$due[ $pid ] = ( $due[ $pid ] ?? 0 ) + 1;
					continue 2;
				}
			}
		}
	}
	arsort( $due );
	update_option( 'daw_recheck_due', array( 'n' => count( $due ), 'top' => array_slice( array_keys( $due ), 0, 20 ) ), false );
	update_option( 'daw_recheck_due_at', time(), false );
}

/** Under a line that is not green: the rule it has to meet, from the rulebook. */
function daw_ap_rule_line( $it ) {
	if ( ! function_exists( 'daw_rule_text' ) || ! in_array( $it[2], array( 'no', 'eksik', 'wait' ), true ) ) {
		return '';
	}
	$r = daw_rule_text( (string) ( $it[5] ?? '' ), (string) ( $it[4] ?? '' ) );
	return '' === $r ? '' : '<div style="margin-top:4px;color:#27527f">Kural: ' . esc_html( $r ) . '</div>';
}

/** What each column of the list checks (header tooltip). */
function daw_ap_col_help( $k ) {
	$h = array(
		'kunye'    => 'Künye: ad, yerel ad, ülke, kanal, tarihler, tür, özet; kişide doğum, ajans, biyografi.',
		'kadro'    => 'Kadro: başrol, yan rol, konuk; her satırda kişi ve rol adı, sezon bilgisi.',
		'bolum'    => 'Bölümler: sayı, tarih, çakışan tarih, sezonlar.',
		'muzik'    => 'Müzik: OST, diskografi, Spotify.',
		'odul'     => 'Ödüller ve adaylıklar.',
		'sss'      => 'SSS: en az 5 soru, gerçek arama kalıpları.',
		'baglanti' => 'İzleme yerleri, resmi bağlantılar, ilgili kayıtlar.',
		'rules'    => 'Yazı: uzun tire, yapay zeka kalıpları, tekrar cümle, paragraf ritmi, ilk paragraf.',
		'seo'      => 'SEO: anahtar kelime araştırması, title, açıklama, odak kelime yerleşimi, kanibalizasyon.',
		'page'     => 'Canlı sayfa: H1/H2/H3 düzeni, alt metinler, görsel kaynağı (TMDB API), bağlantılar.',
		'schema'   => 'Schema: giden türler, zorunlu alanlar, hata, çift ana tür.',
		'image'    => 'Görsel: TMDB API eşleşmesi, yedek, lisans.',
		'legal'    => 'Hukuk ve güven: TMDB notu, kaldırma adresi, korsan bağlantı, doğrulanmamış iddia.',
		'fresh'    => 'Güncellik: son doğrulama tarihi, devam eden yapımlar.',
	);
	return $h[ $k ] ?? '';
}

function daw_ap_detail( $pid ) {
	// Opening a record runs the automatic jobs that are due for it, then the audit.
	$a    = daw_ae_prep_store( $pid );
	$base = admin_url( 'admin.php?page=daw-denetim' );
	if ( ! is_array( $a ) ) {
		$m = get_post_meta( $pid, DAW_AE_META, true );
		printf( '<div class="wrap"><p><a href="%s">← İşler</a></p><h1>%s</h1><div class="notice notice-error"><p>Denetim bu kayıtta çalışamadı: <code>%s</code></p></div></div>', esc_url( $base ), esc_html( get_the_title( $pid ) ), esc_html( is_array( $m ) ? (string) ( $m['err'] ?? '' ) : '' ) );
		return;
	}
	echo '<div class="wrap">';
	printf( '<p><a href="%s">← İşler</a></p>', esc_url( $base ) );
	printf(
		'<h1>%s <span style="font-size:15px;color:#50575e;font-weight:400">%s · %s</span></h1>',
		esc_html( get_the_title( $pid ) ),
		esc_html( $a['type'] ),
		esc_html( daw_ae_groups()[ $a['group'] ]['label'] ?? '' )
	);
	$s = $a['sum'];
	printf(
		'<p>%d dolu · %d kaynak yok · <strong style="color:#a8431a">%d eksik · %d düzeltilecek</strong> · <strong style="color:#7a5a12">%d bakılacak</strong> · %d uygulanmaz &nbsp; <a class="button" href="%s">Düzenle</a> <a class="button" href="%s" target="_blank" rel="noopener">Sayfayı aç</a></p>',
		(int) $s['ok'],
		(int) $s['yok'],
		(int) $s['eksik'],
		(int) $s['no'],
		(int) $s['wait'],
		(int) $s['na'],
		esc_url( (string) get_edit_post_link( $pid ) ),
		esc_url( get_permalink( $pid ) )
	);

	// Closed by logic, not by research: listed on top so a closed field is
	// never mistaken for a filled one (owner, 7 Oct 2026).
	if ( ! empty( $s['imp'] ) && defined( 'DAW_AE_IMP' ) ) {
		$imp = array();
		foreach ( $a['sections'] as $sec ) {
			foreach ( (array) $sec['items'] as $it ) {
				if ( 'na' === $it[2] && 0 === strpos( (string) $it[3], DAW_AE_IMP ) ) {
					$imp[] = $it[0] . ': ' . substr( (string) $it[3], strlen( DAW_AE_IMP ) );
				}
			}
		}
		printf(
			'<div style="background:#f3f1ec;border:1px solid #dcdcde;border-left:4px solid #8a8178;border-radius:6px;padding:8px 12px;max-width:1300px;margin:8px 0"><strong>Mantıken uygulanmaz: %d alan kapatıldı</strong> <span style="color:#50575e">(kaynak aranmadı; kaydın kendi bilgisi bunu imkânsız kılıyor. Belirleyen alan değişirse yeniden açılır.)</span><br><span style="font-size:12px">%s</span></div>',
			(int) $s['imp'],
			esc_html( implode( ' · ', array_unique( $imp ) ) )
		);
	}
	daw_ap_todo_top( $pid, $a );
	daw_ap_gsc_links( $pid );
	if ( function_exists( 'daw_perf_card' ) ) {
		daw_perf_card( $pid );
	}
	daw_ap_run_button( $pid );
	daw_ap_scores( $pid, $a );
	daw_ap_plain_summary( $pid, $a );
	daw_ap_kw_table( $pid );

	// THREE BANDS (1.61.0): what is wrong, what can change (re-checked every
	// sixty days), and what is settled for good, in that order.
	$bands = array( 'bad' => array(), 'live' => array(), 'fixed' => array(), 'na' => array() );
	foreach ( $a['sections'] as $sec ) {
		foreach ( (array) $sec['items'] as $it ) {
			$it[6] = $sec['label'];
			if ( 'na' === $it[2] ) {
				$bands['na'][] = $it;
			} elseif ( in_array( $it[2], array( 'no', 'eksik', 'wait' ), true ) ) {
				$bands['bad'][] = $it;
			} elseif ( daw_ap_is_volatile( $it ) ) {
				$bands['live'][] = $it;
			} else {
				$bands['fixed'][] = $it;
			}
		}
	}
	usort(
		$bands['live'],
		static function ( $x, $y ) use ( $pid ) {
			return daw_ap_recheck_at( $pid, $x ) <=> daw_ap_recheck_at( $pid, $y );
		}
	);

	$table = static function ( $rows ) use ( $pid ) {
		echo '<table class="widefat striped daw-ap-t"><colgroup><col class="c-l"><col class="c-v"><col class="c-s"><col class="c-n"></colgroup><tbody>';
		daw_ap_item_rows( $rows, $pid );
		echo '</tbody></table>';
	};

	echo '<div class="daw-ap-bands">';
	if ( $bands['bad'] ) {
		printf( '<section class="daw-ap-band is-bad"><h2>Düzeltilecek ve bakılacak <span>%d</span></h2>', count( $bands['bad'] ) );
		$table( $bands['bad'] );
		echo '</section>';
	}
	if ( $bands['live'] ) {
		$due = 0;
		foreach ( $bands['live'] as $it ) {
			$due += ( in_array( $it[2], array( 'ok', 'yok' ), true ) && daw_ap_recheck_due( $pid, $it ) ) ? 1 : 0;
		}
		printf( '<section class="daw-ap-band is-live" id="daw-ap-volatile"><h2>Değişebilen bilgiler <span>%d</span>%s</h2>', count( $bands['live'] ), $due ? ' <em>' . (int) $due . ' satırın kontrol zamanı geldi</em>' : '' );
		echo '<p class="description">Medeni durum, ilişki, grup üyeliği, ödüller, yayın durumu, bölüm sayısı, izleme ve fragman bağlantıları zamanla değişir. Her biri 60 günde bir yeniden kontrol için hatırlatılır.</p>';
		if ( $due ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="daw-ap-reform"><input type="hidden" name="action" value="daw_ap_recheck"><input type="hidden" name="record" value="' . (int) $pid . '"><input type="hidden" name="key" value="all">' . wp_nonce_field( 'daw_ap_recheck_' . (int) $pid, 'n', true, false ) . '<button type="submit" class="button">Hepsini kontrol ettim, hâlâ doğru</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$table( $bands['live'] );
		echo '</section>';
	}
	if ( $bands['fixed'] ) {
		printf( '<section class="daw-ap-band is-fixed"><details open><summary><h2>Kesinleşmiş bilgiler <span>%d</span></h2></summary>', count( $bands['fixed'] ) );
		echo '<p class="description">Doğum tarihi, yerel ad, ilk çıkış, künye gibi bir kez kaynakla doğrulanınca değişmeyen satırlar.</p>';
		$table( $bands['fixed'] );
		echo '</details></section>';
	}
	if ( $bands['na'] ) {
		printf( '<section class="daw-ap-band is-na"><details><summary>%d satır bu kayıtta uygulanmaz</summary>', count( $bands['na'] ) );
		$table( $bands['na'] );
		echo '</details></section>';
	}
	echo '</div></div>';
}

/**
 * The record in plain words, above the full table: is it finished, and if
 * not, exactly what is left and who does it (owner, 24 Sep 2026: the detail
 * page has to say what is checked, what is filled and what is still to do).
 */
function daw_ap_plain_summary( $pid, array $a ) {
	$red  = array();
	$todo = array();
	$sys  = array();
	$look = array();
	$auto = function_exists( 'daw_wq_auto_items' ) ? daw_wq_auto_items() : array();
	// The full audit carries sections; the short list is built from them,
	// the same way the stored audit is.
	if ( ! isset( $a['fails'] ) && function_exists( 'daw_ae_fails' ) ) {
		$a['fails'] = daw_ae_fails( $a );
	}
	foreach ( (array) ( $a['fails'] ?? array() ) as $f ) {
		$no    = (string) ( $f[4] ?? '' );
		$label = ( '' !== $no ? 'madde ' . $no . ': ' : '' ) . $f[1] . ( '' !== (string) ( $f[3] ?? '' ) && 'Henüz bakılmadı' !== $f[3] ? ' (' . $f[3] . ')' : '' );
		if ( 'no' === $f[2] ) {
			$red[] = $label;
		} elseif ( 'eksik' === $f[2] ) {
			$todo[] = $f[1];
		} elseif ( 'wait' === $f[2] ) {
			if ( in_array( $f[1], array( 'Doğrulama damgası', 'Kilit' ), true ) ) {
				continue; // said once below, as the last step.
			}
			if ( in_array( $no, $auto, true ) ) {
				$sys[] = $f[1];
			} else {
				$look[] = $label;
			}
		}
	}
	$state = function_exists( 'daw_wc_state' ) ? daw_wc_state( $a ) : '';
	$st    = function_exists( 'daw_wc_states' ) ? ( daw_wc_states()[ $state ] ?? null ) : null;
	$s     = $a['sum'];

	echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 18px;max-width:1400px;margin:10px 0 18px">';
	if ( $st ) {
		printf( '<p style="margin:0 0 8px;font-size:15px"><span style="display:inline-block;padding:2px 10px;border-radius:99px;background:%s;color:%s;font-weight:700">%s</span> &nbsp;%s</p>', esc_attr( $st[1] ), esc_attr( $st[2] ), esc_html( $st[0] ), esc_html( $st[3] ) );
	}
	printf(
		'<p style="margin:0 0 10px;color:#50575e">Bu kayıtta <strong>%d</strong> şey kontrol edildi: %d dolu ve kaynaklı, %d araştırıldı ama kaynak yok (N/A, yeşil sayılır), %d bu türde uygulanmaz.</p>',
		(int) ( $s['ok'] + $s['yok'] + $s['na'] + $s['no'] + $s['eksik'] + $s['wait'] ),
		(int) $s['ok'],
		(int) $s['yok'],
		(int) $s['na']
	);
	$block = static function ( $title, $items, $colour, $max = 12 ) {
		if ( ! $items ) {
			return;
		}
		$items = array_values( array_unique( $items ) );
		$more  = count( $items ) > $max ? ' … ve ' . ( count( $items ) - $max ) . ' tane daha' : '';
		printf( '<p style="margin:6px 0 2px;font-weight:600;color:%s">%s (%d)</p><ul style="margin:0 0 6px 18px;list-style:disc">', esc_attr( $colour ), esc_html( $title ), count( $items ) );
		foreach ( array_slice( $items, 0, $max ) as $it ) {
			echo '<li>' . esc_html( $it ) . '</li>';
		}
		echo '</ul>' . ( $more ? '<p style="margin:0 0 6px 18px;color:#50575e">' . esc_html( $more ) . '</p>' : '' );
	};
	if ( 'bitti' === $state ) {
		echo '<p style="margin:0;color:#185a4a">Yapılacak bir şey yok. Aşağıdaki tablo her alanı ve kuralı tek tek gösterir.</p>';
	} else {
		echo '<p style="margin:0 0 4px"><strong>Bitmesi için:</strong></p>';
		$block( 'Düzeltilecek (kurala aykırı)', $red, '#8a1f1f' );
		$block( 'Araştırılacak boş alanlar (kaynak bulunursa doldurulur, yoksa N/A)', $todo, '#50575e', 20 );
		$block( 'Kaynakla bakılacak', $look, '#6a4d0c' );
		if ( 'damga' === $state ) {
			echo '<p style="margin:6px 0 2px;font-weight:600;color:#6a4d0c">Son okuma ve doğrulama damgası</p><p style="margin:0 0 6px 18px">Her şey yeşil. Metin kaynaklarla bir kez daha okunup damga yazılınca kayıt Bitti olur.</p>';
		}
	}
	if ( $sys ) {
		printf( '<p style="margin:8px 0 0;color:#50575e">Sistemin kendisi yapar, beklemek yeter: %s.</p>', esc_html( implode( ', ', array_unique( $sys ) ) ) );
	}
	printf( '<p style="margin:8px 0 0;font-size:12px;color:#50575e">İşaretler: ✓ dolu ve kaynaklı · N/A araştırıldı, kaynak yok · ✗ düzeltilecek ya da bakılmadı · ⏱ bakılacak · — uygulanmaz. Her satırın altında kuralı yazar; bütün kurallar <a href="%s">DAW › Kurallar</a>.</p>', esc_url( admin_url( 'admin.php?page=daw-kurallar' ) ) );
	echo '</div>';
}

/** Straight under the counts: every line still to fix or to look at, with what to do about it. */
function daw_ap_todo_top( $pid, $a ) {
	$fix  = array();
	$look = array();
	foreach ( (array) $a['sections'] as $sec ) {
		foreach ( (array) $sec['items'] as $it ) {
			if ( in_array( $it[2], array( 'no', 'eksik' ), true ) ) {
				$fix[] = array( $sec['label'], $it );
			} elseif ( 'wait' === $it[2] ) {
				$look[] = array( $sec['label'], $it );
			}
		}
	}
	if ( ! $fix && ! $look ) {
		echo '<div class="notice notice-success inline" style="max-width:1000px"><p>Düzeltilecek ya da bakılacak satır yok.</p></div>';
		return;
	}
	echo '<div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid #a8431a;border-radius:6px;padding:10px 16px;max-width:1000px;margin:10px 0">';
	foreach ( array( array( 'Düzeltilecek', $fix, '#a8431a' ), array( 'Bakılacak', $look, '#7a5a12' ) ) as $grp ) {
		if ( ! $grp[1] ) {
			continue;
		}
		printf( '<h2 style="margin:6px 0;font-size:15px;color:%s">%s (%d)</h2><ol style="margin:0 0 8px 20px">', esc_attr( $grp[2] ), esc_html( $grp[0] ), count( $grp[1] ) );
		foreach ( $grp[1] as $row ) {
			$it   = $row[1];
			$line = '<strong>' . esc_html( $it[0] ) . '</strong>';
			if ( '' !== trim( (string) $it[1] ) ) {
				$line .= ' <span style="color:#3c434a">· ' . esc_html( (string) $it[1] ) . '</span>';
			}
			if ( '' !== trim( (string) $it[3] ) ) {
				$line .= '<div style="color:#50575e;font-size:12px">' . esc_html( (string) $it[3] ) . '</div>';
			}
			echo '<li style="margin:6px 0">' . $line . daw_ap_accept_form( $it, $pid ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ol>';
	}
	echo '</div>';
}

/** Search Console, one click from the record: this page's performance and the whole site's. */
function daw_ap_gsc_links( $pid ) {
	$res  = 'sc-domain%3A' . rawurlencode( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$base = 'https://search.google.com/search-console/performance/search-analytics?resource_id=' . $res;
	$page = $base . '&page=' . rawurlencode( '*' . get_permalink( $pid ) );
	$insp = 'https://search.google.com/search-console/inspect?resource_id=' . $res . '&id=' . rawurlencode( (string) get_permalink( $pid ) );
	printf(
		'<p style="margin:8px 0">Search Console: <a class="button" href="%s" target="_blank" rel="noopener">Bu sayfanın performansı</a> <a class="button" href="%s" target="_blank" rel="noopener">URL denetimi (dizinde mi?)</a> <a class="button" href="%s" target="_blank" rel="noopener">Tüm site</a></p>',
		esc_url( $page ),
		esc_url( $insp ),
		esc_url( $base )
	);
}

/** On a source-conflict row: the owner checked the record against the sources and keeps it. */
function daw_ap_accept_form( $it, $pid ) {
	if ( ! $pid || 'no' !== ( $it[2] ?? '' ) || 0 !== strpos( (string) $it[0], 'Çelişki: ' ) ) {
		return '';
	}
	$field = substr( (string) $it[0], strlen( 'Çelişki: ' ) );
	return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:6px">'
		. '<input type="hidden" name="action" value="daw_ap_ok"><input type="hidden" name="record" value="' . (int) $pid . '">'
		. '<input type="hidden" name="field" value="' . esc_attr( $field ) . '">'
		. wp_nonce_field( 'daw_ap_ok_' . (int) $pid, 'n', true, false )
		. '<button type="submit" class="button button-small">Kaynaklarla kontrol ettim, kayıt doğru</button></form>';
}

add_action( 'admin_post_daw_ap_ok', 'daw_ap_ok' );
function daw_ap_ok() {
	$pid   = isset( $_POST['record'] ) ? (int) $_POST['record'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$field = isset( $_POST['field'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) wp_unslash( $_POST['field'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $pid || '' === $field || ! current_user_can( 'manage_options' ) || ! isset( $_POST['n'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['n'] ) ), 'daw_ap_ok_' . $pid ) ) {
		wp_die( 'Yetkisiz', 403 );
	}
	$ok = get_post_meta( $pid, '_daw_ok', true );
	$ok = is_array( $ok ) ? $ok : array();
	$ok[ 'vf:' . $field ] = 'Sahip kaynaklarla kontrol etti, kayıttaki değer doğru (' . wp_date( 'j M Y' ) . ')';
	update_post_meta( $pid, '_daw_ok', $ok );
	daw_ae_store_safe( $pid );
	set_transient( 'daw_ap_one_' . $pid, 'Karar kaydedildi: ' . $field . ' satırı yeşile döndü.', 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'admin.php?page=daw-denetim&record=' . $pid ) );
	exit;
}

/** The one-record button: every automatic job, due or not, then the audit, then the lock. */
function daw_ap_run_button( $pid ) {
	$msg = get_transient( 'daw_ap_one_' . $pid );
	if ( is_string( $msg ) && '' !== $msg ) {
		delete_transient( 'daw_ap_one_' . $pid );
		printf( '<div class="notice notice-info inline" style="max-width:1000px"><p>%s</p></div>', esc_html( $msg ) );
	}
	$lock = function_exists( 'daw_ae_is_locked' ) && daw_ae_is_locked( $pid );
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:10px 0">';
	echo '<input type="hidden" name="action" value="daw_ap_one">';
	echo '<input type="hidden" name="record" value="' . (int) $pid . '">';
	wp_nonce_field( 'daw_ap_one_' . (int) $pid, 'n' );
	echo '<button type="submit" class="button button-primary">Bu kaydı şimdi denetle</button> ';
	echo '<span style="color:#50575e;margin-left:8px">Doğrulama, kapsam, kaynak ve izleme işlerini şimdi çalıştırır, sonra denetler. Hepsi yeşil ve damgalıysa kaydı kilitler. ' . ( $lock ? '<strong>Kayıt şu an kilitli.</strong>' : 'Kayıt şu an kilitli değil.' ) . '</span>';
	echo '</form>';
}

add_action( 'admin_post_daw_ap_one', 'daw_ap_one' );
function daw_ap_one() {
	$pid = isset( $_POST['record'] ) ? (int) $_POST['record'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $pid || ! current_user_can( 'manage_options' ) || ! isset( $_POST['n'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['n'] ) ), 'daw_ap_one_' . $pid ) ) {
		wp_die( 'Yetkisiz', 403 );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore
	}
	$a = daw_ae_prep_store( $pid, true );
	if ( is_array( $a ) ) {
		$s    = $a['sum'];
		$open = (int) ( $s['no'] ?? 0 ) + (int) ( $s['eksik'] ?? 0 );
		$msg  = sprintf( 'Denetlendi. %d düzeltilecek ya da eksik, %d bakılacak. ', $open, (int) ( $s['wait'] ?? 0 ) );
		if ( daw_ae_is_locked( $pid ) ) {
			$msg .= 'Kayıt kilitli.';
		} elseif ( $open ) {
			$msg .= 'Kilitlenmedi: önce kırmızı ve eksik satırlar kapanmalı.';
		} elseif ( '' === (string) get_post_meta( $pid, 'daw_verified', true ) ) {
			$msg .= 'Kilitlenmedi: doğrulama damgası yok.';
		} else {
			$msg .= 'Kilitlenmedi.';
		}
	} else {
		$msg = 'Denetim bu kayıtta çalışamadı, ayrıntı aşağıda.';
	}
	set_transient( 'daw_ap_one_' . $pid, $msg, 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'admin.php?page=daw-denetim&record=' . $pid ) );
	exit;
}

add_action( 'wp_ajax_daw_ap_run', 'daw_ap_run' );
function daw_ap_run() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'daw_ap_run', 'nonce', false ) ) {
		wp_send_json_error( 'no', 403 );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 90 ); // phpcs:ignore
	}
	// A run started from the button re-audits everything once, not only the
	// stale: the version bump below marks every stored audit as old.
	if ( ! get_transient( 'daw_ap_run_started' ) ) {
		set_transient( 'daw_ap_run_started', time(), HOUR_IN_SECONDS );
	}
	$since = (int) get_transient( 'daw_ap_run_started' );
	global $wpdb;
	$ids  = function_exists( 'daw_audit_record_ids' ) ? daw_audit_record_ids( daw_ae_all_types() ) : array();
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_AE_META ), OBJECT_K ); // phpcs:ignore WordPress.DB
	$todo = array();
	foreach ( $ids as $id ) {
		$m = isset( $rows[ $id ] ) ? maybe_unserialize( $rows[ $id ]->meta_value ) : null;
		if ( ! is_array( $m ) || (int) ( $m['at'] ?? 0 ) < $since ) {
			$todo[] = (int) $id;
		}
	}
	$done = 0;
	// Eight at a time: each audit also fetches the live page once.
	foreach ( array_slice( $todo, 0, 8 ) as $id ) {
		daw_ae_store_safe( $id );
		$done++;
	}
	if ( ! $done ) {
		delete_transient( 'daw_ap_run_started' );
	}
	wp_send_json_success( array( 'done' => $done, 'left' => max( 0, count( $todo ) - $done ) ) );
}

/* ================================================== percentages, keywords == */

/**
 * The audit as one number: how much of what applies to this record is in
 * order. Filled and sourced-as-absent lines count as in order; red, missing and
 * look-at lines do not; lines that do not apply to the record are left out.
 */
function daw_ap_percent( array $a ) {
	$s    = isset( $a['sum'] ) && is_array( $a['sum'] ) ? $a['sum'] : array();
	$good = (int) ( $s['ok'] ?? 0 ) + (int) ( $s['yok'] ?? 0 );
	$all  = $good + (int) ( $s['eksik'] ?? 0 ) + (int) ( $s['no'] ?? 0 ) + (int) ( $s['wait'] ?? 0 );

	return $all > 0 ? (int) round( $good / $all * 100 ) : 0;
}

/** Colour for a percentage. */
function daw_ap_pct_colour( $pct ) {
	return $pct >= 90 ? '#1a7f37' : ( $pct >= 70 ? '#7a5a12' : '#a8431a' );
}

/**
 * Three numbers at the top of the detail page: the record audit, the page as
 * Google fetches it (H1, headings, alt text, title, description, schema), and
 * whether the page can be indexed at all.
 *
 * "Can be indexed" is read from the page itself: it answers 200, carries no
 * noindex and names itself as canonical. It is not Google's own verdict. That
 * needs Search Console, which this site does not report here, so impressions
 * are shown only when the Search Console table holds rows for the page.
 */
function daw_ap_scores( $pid, array $a ) {
	$audit  = daw_ap_percent( $a );
	$parsed = function_exists( 'daw_pa_analyze' ) ? daw_pa_analyze( $pid ) : array( 'ok' => false );
	$scored = ( ! empty( $parsed['ok'] ) && function_exists( 'daw_pa_score' ) ) ? daw_pa_score( $pid, $parsed ) : null;
	$page   = $scored ? (int) $scored['score'] : null;
	$index  = null;
	$why    = array();

	if ( $scored && isset( $scored['groups']['url'] ) ) {
		$sc = 0;
		$mx = 0;

		foreach ( $scored['groups']['url']['items'] as $it ) {
			$sc += (int) $it['puan'];
			$mx += (int) $it['max'];

			if ( empty( $it['ok'] ) ) {
				$why[] = $it['ad'] . ( ! empty( $it['not'] ) ? ' (' . $it['not'] . ')' : '' );
			}
		}

		$index = $mx > 0 ? (int) round( $sc / $mx * 100 ) : null;
	}

	$gsc = function_exists( 'daw_seo_gsc_for_post' ) ? daw_seo_gsc_for_post( $pid ) : null;

	$box = static function ( $label, $pct, $note ) {
		$pct_s = null === $pct ? '–' : $pct . '%';
		$col   = null === $pct ? '#50575e' : daw_ap_pct_colour( $pct );

		return sprintf(
			'<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:10px 16px;min-width:200px"><div style="font-size:12px;color:#50575e">%s</div><div style="font-size:28px;font-weight:700;color:%s;line-height:1.2">%s</div><div style="font-size:12px;color:#50575e">%s</div></div>',
			esc_html( $label ),
			esc_attr( $col ),
			esc_html( $pct_s ),
			esc_html( $note )
		);
	};

	echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin:12px 0">';
	echo $box( 'Kayıt denetimi', $audit, 'dolu ve kaynaklı satırların payı' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo $box( 'Sayfa (H1, başlıklar, görsel, meta, schema)', $page, $scored ? ( $scored['raw'] . ' / ' . $scored['max'] . ' puan, sayfa canlı çekildi' ) : 'sayfa çekilemedi' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo $box( 'İndekslenebilirlik', $index, $why ? ( 'Sorun: ' . implode( '; ', $why ) ) : 'HTTP 200, noindex yok, canonical kendisi' . ( $gsc ? ' · Search Console: ' . $gsc['impressions'] . ' gösterim' : ' · Google\'ın gerçek indeks durumu değil (Search Console bağlı değil)' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div>';

	if ( $scored ) {
		$bad = array();

		foreach ( $scored['groups'] as $g ) {
			foreach ( $g['items'] as $it ) {
				if ( empty( $it['ok'] ) ) {
					$bad[] = $g['ad'] . ': ' . $it['ad'] . ( ! empty( $it['not'] ) ? ' (' . $it['not'] . ')' : '' );
				}
			}
		}

		if ( $bad ) {
			printf( '<p style="font-size:13px;color:#a8431a"><strong>Sayfada düzeltilecek:</strong> %s</p>', esc_html( implode( ' · ', $bad ) ) );
		}
	}
}

/**
 * Every search pattern saved for the record, with its monthly volume and the
 * places it already appears: SEO title, description, a heading, or the text
 * of the page as a visitor gets it. Target patterns are the ones the record is
 * scored on; patterns flagged as research ("n") are listed too, so the owner
 * can see what is covered and pick the next ones to write for.
 */
function daw_ap_kw_table( $pid ) {
	$raw = get_post_meta( $pid, '_daw_kw', true );
	$raw = is_array( $raw ) ? $raw : array();
	$rows = array();

	foreach ( $raw as $k ) {
		$kw = strtolower( trim( (string) ( is_array( $k ) ? ( $k['kw'] ?? '' ) : $k ) ) );

		if ( '' !== $kw ) {
			$rows[] = array(
				'kw'     => $kw,
				'vol'    => is_array( $k ) ? (int) ( $k['vol'] ?? 0 ) : 0,
				'gsc'    => is_array( $k ) && 'gsc' === ( $k['src'] ?? '' ),
				'target' => ! ( is_array( $k ) && ( ! empty( $k['n'] ) || ! empty( $k['x'] ) ) ),
				'off'    => is_array( $k ) && ! empty( $k['x'] ),
			);
		}
	}

	if ( ! $rows ) {
		return;
	}

	$s    = function_exists( 'daw_audit_seo_state' ) ? daw_audit_seo_state( $pid ) : array( 'title' => '', 'description' => '' );
	$html = function_exists( 'daw_ae_page_html' ) ? (string) daw_ae_page_html( $pid ) : '';
	$heads = '';

	if ( preg_match_all( '#<h[1-4][^>]*>(.*?)</h[1-4]>#is', $html, $m ) ) {
		$heads = strtolower( wp_strip_all_tags( implode( ' | ', $m[1] ) ) );
	}

	$main = $html;

	if ( preg_match( '#<main[^>]*>(.*?)</main>#is', $html, $mm ) ) {
		$main = $mm[1];
	}

	$main = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $main );
	$text = strtolower( html_entity_decode( wp_strip_all_tags( $main ), ENT_QUOTES, 'UTF-8' ) );
	$title = strtolower( (string) $s['title'] );
	$desc  = strtolower( (string) $s['description'] );

	usort(
		$rows,
		static function ( $x, $y ) {
			return $y['vol'] <=> $x['vol'];
		}
	);

	$vol_all = 0;
	$vol_hit = 0;
	$n_hit   = 0;
	$out     = array();

	foreach ( $rows as $r ) {
		$where = array();

		if ( daw_ae_kw_in( $r['kw'], $title, true ) ) {
			$where[] = 'SEO başlığı';
		}

		if ( daw_ae_kw_in( $r['kw'], $desc, true ) ) {
			$where[] = 'açıklama';
		}

		if ( $heads && daw_ae_kw_in( $r['kw'], $heads, true ) ) {
			$where[] = 'başlık';
		}

		if ( $text && daw_ae_kw_in( $r['kw'], $text ) ) {
			$where[] = 'sayfa metni';
		}

		$vol_all += $r['vol'];

		if ( $where ) {
			$vol_hit += $r['vol'];
			++$n_hit;
		}

		$out[] = array( $r, $where );
	}

	$pct = $vol_all > 0 ? (int) round( $vol_hit / $vol_all * 100 ) : 0;

	echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:12px 16px;max-width:1400px;margin:16px 0">';
	printf(
		'<h2 style="margin:4px 0 4px;font-size:16px">Aranan kalıplar (Ubersuggest, ABD)</h2><p style="margin:0 0 8px;color:#50575e;font-size:13px">%d kalıptan <strong>%d</strong> tanesi sayfada geçiyor; aylık arama hacminin <strong>%%%d</strong> kadarı karşılanmış. "Hedef" kalıplar denetimde puanlanır; "araştırma" (ilgili ama hedeflenmeyen) ve "konu dışı" (yazılmayacak) kalıplar yalnız burada, hacimleriyle gösterilir. Eşleşme kelime bazlıdır, tam cümle aranmaz.</p>',
		count( $rows ),
		(int) $n_hit,
		(int) $pct
	);
	echo '<details open><summary style="cursor:pointer;font-size:12px;color:#50575e">Tablo</summary>';
	echo '<table class="widefat striped" style="border:0;margin-top:6px"><thead><tr><th>Kalıp</th><th style="width:90px">Hacim / ay</th><th style="width:80px">Tür</th><th>Sayfada nerede</th><th style="width:90px">Google sırası</th><th style="width:90px">Gösterim (28g)</th></tr></thead><tbody>';
	$gq = function_exists( 'daw_perf_query_map' ) ? daw_perf_query_map( $pid ) : array();

	foreach ( $out as $row ) {
		list( $r, $where ) = $row;
		$g = $gq[ $r['kw'] ] ?? null;
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td style="color:%s">%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $r['kw'] ),
			esc_html( number_format_i18n( $r['vol'] ) ) . ( ! empty( $r['gsc'] ) ? ' <small title="Search Console gösterimi (28 gün), aylık hacim değil">GSC</small>' : '' ),
			$r['target'] ? 'hedef' : ( $r['off'] ? 'konu dışı' : 'araştırma' ),
			$where ? '#1a7f37' : '#a8431a',
			esc_html( $where ? implode( ', ', $where ) : 'geçmiyor' ),
			$g && null !== $g['p'] ? esc_html( number_format_i18n( $g['p'], 1 ) ) . ( function_exists( 'daw_perf_delta' ) ? daw_perf_delta( $g['p'], $g['prev'], true ) : '' ) : '<span style="color:#8c8f94">görünmüyor</span>', // phpcs:ignore WordPress.Security.EscapeOutput
			$g ? esc_html( number_format_i18n( $g['i'] ) ) : ''
		);
	}

	echo '</tbody></table></details></div>';
}

/* ============================================================= admin bar == */

/**
 * The record audit percentage in the admin bar, on the record itself and on
 * its edit screen, linking to the audit detail page. It reads the stored audit
 * only; opening the detail page is what re-runs it.
 */
add_action( 'admin_bar_menu', 'daw_ap_admin_bar', 90 );
function daw_ap_admin_bar( $bar ) {
	if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'daw_ae_store_safe' ) ) {
		return;
	}

	$pid = 0;

	if ( is_admin() ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$pid    = ( $screen && 'post' === $screen->base && 'post' === $screen->post_type ) ? (int) get_the_ID() : 0;

		if ( ! $pid && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$pid = (int) $_GET['post']; // phpcs:ignore WordPress.Security.NonceVerification
		}
	} elseif ( is_singular( 'post' ) ) {
		$pid = (int) get_queried_object_id();
	}

	if ( ! $pid || ! defined( 'DAW_AE_META' ) ) {
		return;
	}

	$a = get_post_meta( $pid, DAW_AE_META, true );

	if ( ! is_array( $a ) || empty( $a['sum'] ) ) {
		return;
	}

	$pct = daw_ap_percent( $a );

	$bar->add_node(
		array(
			'id'    => 'daw-audit-pct',
			'title' => sprintf( '<span style="color:%s;font-weight:700">Denetim %%%d</span>', esc_attr( daw_ap_pct_colour( $pct ) === '#1a7f37' ? '#6fdc8c' : ( $pct >= 70 ? '#ffd166' : '#ff9b7a' ) ), $pct ),
			'href'  => admin_url( 'admin.php?page=daw-denetim&record=' . $pid ),
			'meta'  => array( 'title' => 'Denetim sayfasını aç' ),
		)
	);
}
