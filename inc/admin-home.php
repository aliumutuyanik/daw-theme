<?php
/**
 * Discover Asian World: one short admin menu and a home screen.
 *
 * WHY
 * ---
 * Twenty-four "DAW: …" entries had piled up under Tools, most of them one-off
 * repair jobs whose work is finished (owner, 23 Sep 2026: keep what we use,
 * lose the rest, and let every screen say where things stand, what the API is
 * doing and what to do next).
 *
 * WHAT CHANGED
 * ------------
 * - One "DAW" menu with the screens in daily use.
 * - The occasional tools are linked from the home screen, not the menu.
 * - The finished one-off jobs are out of the menu. Their code stays for now
 *   (other parts still call some of their functions) and each one can still
 *   be opened from the home screen's last list if it is ever needed again.
 * - Every TMDB request the site makes is counted, so the home screen can show
 *   today's and this month's use.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_MENU = 'daw';

/**
 * Screens kept in the menu: slug => [ Turkish label, callback ]. The ones the
 * images module registers itself are added there.
 */
function daw_menu_kept() {
	return array(
		'daw-archive'    => array( 'Arşiv denetimi', 'daw_audit_wide_page' ),
		'daw-backlog'    => array( 'Eksikler', 'daw_backlog_page' ),
		'daw-fresh'      => array( 'Güncellik', 'daw_fresh_page' ),
		'daw-speed-audit' => array( 'Hız & Sağlık', 'daw_speed_audit_page' ),
		'daw-seo-command' => array( 'SEO Envanter', 'daw_seo_command_page' ),
	);
}

/** Occasional tools: out of the menu, linked from the home screen. */
function daw_menu_tools() {
	return array(
		'daw-next'        => array( 'Sıradaki işler ve özet', 'Özetin elle yenilenmesi, sıradaki kayıtlar' ),
		'daw-tmdb-match'  => array( 'TMDB eşleştirme', 'TMDB numarası olmayan kayıtlar için aday bulma (arka planda kendisi çalışır)' ),
		'daw-audit'       => array( 'Kayıt denetimi (ayrıntılı)', 'Her kaydın eksik maddeleri' ),
		'daw-tmdb-fill'   => array( 'TMDB alanları', 'Eşleşen kayıtların boş alanlarını TMDB\'den doldurma' ),
		'daw-hubs'        => array( 'Hub sayfaları', 'Ülke ve tür sayfalarının neyi topladığı' ),
		'daw-countries'   => array( 'Ülkeler', 'Ülke başına kayıt sayıları' ),
		'daw-links'       => array( 'Resmi bağlantılar', 'Instagram, X, YouTube bağlantıları (Wikidata)' ),
		'daw-spotify'     => array( 'Spotify diskografi', 'Grup ve şarkıcı albümleri' ),
		'daw-platforms'   => array( 'Platform logoları', 'Netflix, Viki gibi izleme platformları' ),
		'daw-faq'         => array( 'Dizi SSS üretici', 'Kayıttaki bilgiden soru-cevap' ),
		'daw-news-links'  => array( 'Haber bağlantıları', 'Haberlerin kayıtlara bağlanması' ),
		'daw-cast-index'  => array( 'Kadro dizini', 'Filmografi dizinini yeniden kurma' ),
		'daw-cast'        => array( 'Kadro kademeleri', 'Başrol, yardımcı, konuk ayrımı kalan kayıtlar' ),
	);
}

/** Finished one-off jobs: out of the menu, listed last on the home screen. */
function daw_menu_retired() {
	return array(
		'daw-classify'     => array( 'Kayıt sınıflandırma', 'Tür ve ülke terimleri bir kez atandı' ),
		'daw-extract'      => array( 'Elementor içerik çıkarma', 'Eski sayfa kurucu içeriği alanlara taşındı' ),
		'daw-guide-clean'  => array( 'Rehber gövde temizliği', 'Eski rehber gövdeleri temizlendi' ),
		'daw-guides'       => array( 'Rehber durum listesi', 'Yerini Arşiv denetimindeki makale tablosu aldı' ),
		'daw-media-audit'  => array( 'Görsel kaynakları (eski)', 'Yerini DAW › Görseller aldı' ),
		'daw-deploy'       => array( 'Deploy', 'Sürümler zip ile kuruluyor' ),
	);
}

/**
 * The occasional tools and the retired one-off jobs, by their real render
 * callback (and capability, where it isn't manage_options) — see the note in
 * daw_menu_build() for why this map has to exist.
 */
function daw_menu_hidden_callbacks() {
	return array(
		'daw-next'        => array( 'daw_audit_next_page', 'manage_options' ),
		'daw-tmdb-match'  => array( 'daw_tmdb_match_page', 'manage_options' ),
		'daw-audit'       => array( 'daw_audit_page', 'manage_options' ),
		'daw-tmdb-fill'   => array( 'daw_tmdb_fill_page', 'manage_options' ),
		'daw-hubs'        => array( 'daw_hub_admin_page', 'manage_options' ),
		'daw-countries'   => array( 'daw_country_report_page', 'manage_options' ),
		'daw-links'       => array( 'daw_wd_admin_page', 'manage_options' ),
		'daw-spotify'     => array( 'daw_spotify_admin_page', 'manage_options' ),
		'daw-platforms'   => array( 'daw_platform_admin_page', 'manage_options' ),
		'daw-faq'         => array( 'daw_faq_admin_page', 'manage_options' ),
		'daw-news-links'  => array( 'daw_news_links_page', 'manage_options' ),
		'daw-cast-index'  => array( 'daw_cast_index_page', 'manage_options' ),
		'daw-cast'        => array( 'daw_cast_report_page', 'manage_options' ),
		'daw-classify'    => array( 'daw_classify_page', 'manage_options' ),
		'daw-extract'     => array( 'daw_extract_page', 'manage_options' ),
		'daw-guide-clean' => array( 'daw_guide_clean_page', 'manage_options' ),
		'daw-guides'      => array( 'daw_guide_report_page', 'manage_options' ),
		'daw-media-audit' => array( 'daw_media_audit_page', 'manage_options' ),
		'daw-deploy'      => array( 'daw_pkg_page', 'update_plugins' ),
	);
}

add_action( 'admin_menu', 'daw_menu_build', 999 );
function daw_menu_build() {
	// Out of Tools, whatever they are.
	$all = array_merge( array_keys( daw_menu_kept() ), array_keys( daw_menu_tools() ), array_keys( daw_menu_retired() ) );
	foreach ( $all as $slug ) {
		remove_submenu_page( 'tools.php', $slug );
	}

	// The kept ones, into the DAW menu. The page itself stays registered where
	// it was, so every old tools.php?page=… link keeps working too.
	foreach ( daw_menu_kept() as $slug => $row ) {
		if ( function_exists( $row[1] ) ) {
			add_submenu_page( DAW_MENU, $row[0], $row[0], 'manage_options', $slug, $row[1] );
		}
	}

	// The occasional tools and the retired jobs: out of Tools' nav on purpose
	// (that is what daw_menu_tools()/daw_menu_retired() are for), but WordPress
	// does not have a "registered, just hidden" state — remove_submenu_page()
	// above also drops the page from every $submenu array, and admin.php reads
	// that same array to decide whether ANYONE, capability included, may open
	// the page at all. Past this point they were simply gone: their home-screen
	// links and old bookmarks all hit "Sorry, you are not allowed to access
	// this page." for the site owner too. A null parent registers the page
	// without adding it to any nav, which keeps it directly reachable.
	// Found 28 Sep 2026 — daw-archive and daw-audit both came back "not
	// allowed" for the owner despite confirmed manage_options access.
	foreach ( daw_menu_hidden_callbacks() as $slug => $row ) {
		list( $callback, $cap ) = $row;
		if ( function_exists( $callback ) ) {
			add_submenu_page( null, $slug, $slug, $cap, $slug, $callback );
		}
	}
}

/** Where a tool opens: under the DAW menu if it lives there, else under Tools. */
function daw_menu_url( $slug ) {
	$in_daw = in_array( $slug, array_merge( array( DAW_MENU, 'daw-images', 'daw-image-rights', 'daw-cards' ), array_keys( daw_menu_kept() ) ), true );
	return $in_daw ? admin_url( 'admin.php?page=' . $slug ) : admin_url( 'tools.php?page=' . $slug );
}

/* ================================================================= icon === */

/**
 * The DAW menu wears the site's own seal instead of a stock icon. It is read
 * from the logo attachment at 20 px, so moving the site or changing the logo
 * needs nothing here.
 */
add_action( 'admin_head', 'daw_menu_icon_css' );
function daw_menu_icon_css() {
	$id  = (int) get_option( 'daw_logo_attachment_id', 0 );
	$src = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
	if ( ! $src ) {
		return;
	}
	printf(
		'<style>#toplevel_page_daw .wp-menu-image::before{content:"";background:url(%s) center/22px 22px no-repeat;opacity:1}#toplevel_page_daw .wp-menu-image{opacity:1}</style>',
		esc_url( $src )
	);
}

/* ======================================================= remote reading === */

/**
 * Let the MCP connector read the picture job's state and the TMDB counters,
 * so a run can be followed and checked from outside the admin screen. None
 * of these holds a key or a secret.
 */
add_filter( 'royal_mcp_readable_options', 'daw_home_readable' );
function daw_home_readable( $keys ) {
	$keys = (array) $keys;
	foreach ( array( 'daw_img_job', 'daw_tmdb_usage', 'daw_img_source', 'daw_match_on', 'daw_fill_on', 'daw_brand_images' ) as $k ) {
		$keys[] = $k;
	}
	return array_values( array_unique( $keys ) );
}

/* ============================================================= API usage === */

/**
 * Count every request to TMDB, whoever made it: the picture check, the
 * matcher, the field filler, the backup. The browser's own picture requests go
 * straight to TMDB and are not seen here.
 */
add_action( 'http_api_debug', 'daw_tmdb_usage_count', 10, 5 );
function daw_tmdb_usage_count( $response, $context, $class, $args, $url ) {
	if ( 'response' !== $context ) {
		return;
	}
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( 'api.themoviedb.org' !== $host && 'image.tmdb.org' !== $host ) {
		return;
	}
	$u     = get_option( 'daw_tmdb_usage', array() );
	$u     = is_array( $u ) ? $u : array();
	$day   = gmdate( 'Y-m-d' );
	$month = gmdate( 'Y-m' );
	$kind  = 'api.themoviedb.org' === $host ? 'api' : 'img';

	if ( ( $u['day'] ?? '' ) !== $day ) {
		$u['day']   = $day;
		$u['today'] = array( 'api' => 0, 'img' => 0 );
	}
	if ( ( $u['month'] ?? '' ) !== $month ) {
		$u['month']   = $month;
		$u['month_n'] = array( 'api' => 0, 'img' => 0 );
	}
	$u['today'][ $kind ]++;
	$u['month_n'][ $kind ]++;

	$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	if ( 200 === $code ) {
		$u['last_ok'] = time();
	} elseif ( 404 !== $code ) {
		$u['last_err']      = time();
		$u['last_err_code'] = $code;
	}
	update_option( 'daw_tmdb_usage', $u, false );
}

/** One line about the API for any screen. */
function daw_tmdb_usage_line() {
	$u   = get_option( 'daw_tmdb_usage', array() );
	$u   = is_array( $u ) ? $u : array();
	$key = function_exists( 'daw_tmdb_key' ) ? daw_tmdb_key() : '';
	if ( '' === $key ) {
		return 'TMDB API: anahtar girilmedi.';
	}
	$today = ( $u['day'] ?? '' ) === gmdate( 'Y-m-d' ) ? $u['today'] : array( 'api' => 0, 'img' => 0 );
	$month = ( $u['month'] ?? '' ) === gmdate( 'Y-m' ) ? $u['month_n'] : array( 'api' => 0, 'img' => 0 );
	$state = 'bağlı';
	if ( ! empty( $u['last_err'] ) && ( empty( $u['last_ok'] ) || $u['last_err'] > $u['last_ok'] ) ) {
		$state = sprintf( 'son istek hata verdi (kod %d, %s önce)', (int) ( $u['last_err_code'] ?? 0 ), human_time_diff( (int) $u['last_err'] ) );
	}
	return sprintf(
		'TMDB API: %s. Bugün %d veri isteği, %d yedek indirme. Bu ay %d veri isteği, %d yedek indirme.',
		$state,
		(int) $today['api'],
		(int) $today['img'],
		(int) $month['api'],
		(int) $month['img']
	);
}

/* ================================================================== home === */

function daw_home_card( $title, $lines, $links ) {
	echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:14px 16px;min-width:0">';
	printf( '<h2 style="margin:0 0 8px;font-size:15px">%s</h2>', esc_html( $title ) );
	foreach ( $lines as $l ) {
		printf( '<p style="margin:0 0 6px">%s</p>', wp_kses( $l, array( 'strong' => array(), 'span' => array( 'style' => true ) ) ) );
	}
	if ( $links ) {
		echo '<p style="margin:10px 0 0">';
		foreach ( $links as $label => $url ) {
			printf( '<a class="button" href="%s" style="margin:0 6px 6px 0">%s</a>', esc_url( $url ), esc_html( $label ) );
		}
		echo '</p>';
	}
	echo '</div>';
}

function daw_home_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// The control centre (control-center.php) draws the overview; this page
	// keeps the snapshot button, the brand images and the tool lists below it.
	if ( function_exists( 'daw_kc_render' ) ) {
		daw_kc_render();
	}

	echo '<div class="wrap kc-tools">';
	echo '<details><summary>Özeti şimdi yenile ve marka görselleri</summary>';
	if ( function_exists( 'daw_snapshot_button' ) ) {
		daw_snapshot_button();
	}
	if ( function_exists( 'daw_brand_panel' ) ) {
		daw_brand_panel();
	}
	echo '</details>';

	// Occasional tools.
	echo '<details><summary><strong>Diğer araçlar</strong> (ara sıra gerekenler)</summary><table class="widefat striped" style="margin-top:8px"><tbody>';
	foreach ( daw_menu_tools() as $slug => $row ) {
		printf( '<tr><td style="width:16em"><a href="%s">%s</a></td><td>%s</td></tr>', esc_url( daw_menu_url( $slug ) ), esc_html( $row[0] ), esc_html( $row[1] ) );
	}
	echo '</tbody></table></details>';

	echo '<details><summary><strong>İşi biten eski araçlar</strong> (menüden kaldırıldı)</summary><table class="widefat striped" style="margin-top:8px"><tbody>';
	foreach ( daw_menu_retired() as $slug => $row ) {
		printf( '<tr><td style="width:16em"><a href="%s">%s</a></td><td>%s</td></tr>', esc_url( daw_menu_url( $slug ) ), esc_html( $row[0] ), esc_html( $row[1] ) );
	}
	echo '</tbody></table></details>';
	echo '</div>';
}
