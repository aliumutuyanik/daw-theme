<?php
/**
 * Discover Asian World: one look for every DAW screen in the admin.
 *
 * The DAW screens were drawn with WordPress's bare defaults: grey tables,
 * square boxes, tabs from 2012. The owner works in them every day (4 Oct 2026:
 * "the panel looks old-fashioned"). This restyles every screen whose page slug
 * starts with "daw" with the site's own palette (the wax-seal red, the warm
 * paper background, the gold) and puts a status bar on top: SEO engine, Search
 * Console, the last PageSpeed score and the version, with the screens used
 * most one click away.
 *
 * Nothing in any screen's markup changes. It is one stylesheet scoped to a
 * body class, printed inline on these screens only, so the rest of the admin
 * and every editor screen look exactly as before.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** Is this one of ours: admin.php or tools.php with ?page=daw… */
function daw_ui_is_daw_screen() {
	if ( ! is_admin() || ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return false;
	}

	$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	return 'daw' === $page || 0 === strpos( $page, 'daw-' ) || 0 === strpos( $page, 'daw_' );
}

add_filter( 'admin_body_class', 'daw_ui_body_class' );
function daw_ui_body_class( $classes ) {
	return daw_ui_is_daw_screen() ? $classes . ' daw-admin' : $classes;
}

add_action( 'admin_head', 'daw_ui_styles', 50 );
function daw_ui_styles() {
	if ( ! daw_ui_is_daw_screen() ) {
		return;
	}

	echo '<style id="daw-admin-ui">' . daw_ui_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static stylesheet.
}

/**
 * ONE MAP FOR EVERY DAW SCREEN (1.57.0)
 * -------------------------------------
 * The owner's rule: the side menu, the bar on top and the tabs inside must
 * be the same structure, so nothing is in two places and nothing is lost
 * (4 Oct 2026). Five areas; each area's first screen is the one the side
 * menu opens, and the rest sit as tabs under the bar.
 */
function daw_ui_groups() {
	return array(
		'merkez' => array(
			'label' => 'Kontrol Merkezi',
			'pages' => array( 'daw' => 'Kontrol Merkezi' ),
		),
		'seo'    => array(
			'label' => 'SEO',
			'pages' => array(
				'daw-seo'         => 'SEO ve Google',
				'daw-seo-command' => 'SEO envanteri',
				'daw-firsat'      => 'Arama fırsatları',
			),
		),
		'icerik' => array(
			'label' => 'İçerik',
			'pages' => array(
				'daw-denetim'  => 'Kayıt denetimi',
				'daw-archive'  => 'Arşiv denetimi',
				'daw-kapsam'   => 'Kapsam',
				'daw-fresh'    => 'Güncellik',
				'daw-backlog'  => 'Eksikler',
				'daw-kurallar' => 'Kurallar',
			),
		),
		'gorsel' => array(
			'label' => 'Görseller',
			'pages' => array(
				'daw-images'       => 'Görseller',
				'daw-image-rights' => 'Commons & lisanslı',
				'daw-cards'        => 'Haber kartları',
			),
		),
		'sistem' => array(
			'label' => 'Hız & Sistem',
			'pages' => array(
				'daw-speed-audit' => 'Hız & Sağlık',
				'daw-otomatik'    => 'Otomatik işler',
				'daw-is-sirasi'   => 'İş sırası',
				'daw-api'         => 'API anahtarları',
			),
		),
	);
}

/** Where a DAW screen opens. API keys live under Tools; the rest under DAW. */
function daw_ui_url( $slug ) {
	return 'daw-api' === $slug ? admin_url( 'tools.php?page=daw-api' ) : admin_url( 'admin.php?page=' . $slug );
}

/** The area a screen belongs to, or ''. */
function daw_ui_group_of( $slug ) {
	foreach ( daw_ui_groups() as $key => $g ) {
		if ( isset( $g['pages'][ $slug ] ) ) {
			return $key;
		}
	}
	return '';
}

function daw_ui_current() {
	return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * The side menu shows the five areas in the same order and with the same
 * names as the bar. The other screens stay registered (WordPress refuses a
 * page that is missing from the menu array, see admin-home.php) and are only
 * hidden from the side menu; they are one click away as tabs.
 */
add_action( 'admin_menu', 'daw_ui_menu_order', 1001 );
function daw_ui_menu_order() {
	global $submenu;

	if ( empty( $submenu['daw'] ) ) {
		return;
	}

	$heads = array();
	foreach ( daw_ui_groups() as $g ) {
		$first           = array_key_first( $g['pages'] );
		$heads[ $first ] = $g['label'];
	}

	$top  = array();
	$rest = array();
	foreach ( $submenu['daw'] as $item ) {
		$slug = (string) ( $item[2] ?? '' );
		if ( isset( $heads[ $slug ] ) ) {
			$item[0]      = $heads[ $slug ];
			$top[ $slug ] = $item;
		} else {
			$rest[] = $item;
		}
	}

	$ordered = array();
	foreach ( array_keys( $heads ) as $slug ) {
		if ( isset( $top[ $slug ] ) ) {
			$ordered[] = $top[ $slug ];
		}
	}

	$submenu['daw'] = array_merge( $ordered, $rest ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
}

/** On a tab screen, the side menu lights its area. */
add_filter( 'submenu_file', 'daw_ui_submenu_file', 10, 2 );
function daw_ui_submenu_file( $submenu_file, $parent_file ) {
	$group = daw_ui_group_of( daw_ui_current() );
	if ( '' === $group ) {
		return $submenu_file;
	}
	$groups = daw_ui_groups();
	return array_key_first( $groups[ $group ]['pages'] );
}

/** Hide the non-area screens in the side menu (every admin screen). */
add_action( 'admin_head', 'daw_ui_menu_css' );
function daw_ui_menu_css() {
	$sel = array();
	foreach ( daw_ui_groups() as $g ) {
		foreach ( array_slice( array_keys( $g['pages'] ), 1 ) as $slug ) {
			$sel[] = '#toplevel_page_daw .wp-submenu li:has(> a[href="admin.php?page=' . $slug . '"])';
		}
	}
	echo '<style>' . implode( ',', $sel ) . '{display:none}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from the static slug list above.
}

/** The status bar above every DAW screen, and the area's tabs under it. */
add_action( 'all_admin_notices', 'daw_ui_bar', 0 );
function daw_ui_bar() {
	if ( ! daw_ui_is_daw_screen() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$logo_id = (int) get_option( 'daw_logo_attachment_id', 0 );
	$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';

	$pills = array();

	$kc = get_option( 'daw_kc_report' );
	if ( is_array( $kc ) && isset( $kc['score'] ) ) {
		$sc      = (int) $kc['score'];
		$pills[] = array( $sc >= 90 ? 'ok' : ( $sc >= 70 ? 'warn' : 'bad' ), 'Sağlık ' . $sc );
	}

	if ( function_exists( 'daw_eng_live' ) ) {
		$pills[] = daw_eng_live()
			? array( 'ok', 'SEO motoru canlı' )
			: ( function_exists( 'daw_eng_rm_active' ) && daw_eng_rm_active() ? array( 'warn', 'SEO: Rank Math açık' ) : array( 'bad', 'SEO motoru kapalı' ) );
	}

	if ( function_exists( 'daw_gsc_creds' ) ) {
		$gsc     = daw_gsc_creds() ? daw_gsc_settings() : null;
		$pills[] = ! $gsc
			? array( 'warn', 'Search Console bağlı değil' )
			: ( '' !== $gsc['error'] ? array( 'bad', 'Search Console hatası' ) : array( 'ok', 'Search Console bağlı' ) );
	}

	$psi = get_option( 'daw_psi_detail' );
	if ( is_array( $psi ) ) {
		$scores = array();
		foreach ( $psi as $row ) {
			if ( is_array( $row ) && isset( $row['score'] ) ) {
				$scores[] = (int) $row['score'];
			}
		}
		if ( $scores ) {
			$score   = min( $scores );
			$pills[] = array( $score >= 90 ? 'ok' : ( $score >= 50 ? 'warn' : 'bad' ), 'Mobil hız ' . $score );
		}
	}
	$psid = get_option( 'daw_psi_detail_d' );
	if ( is_array( $psid ) ) {
		$scores = array();
		foreach ( $psid as $row ) {
			if ( is_array( $row ) && isset( $row['score'] ) ) {
				$scores[] = (int) $row['score'];
			}
		}
		if ( $scores ) {
			$score   = min( $scores );
			$pills[] = array( $score >= 90 ? 'ok' : ( $score >= 50 ? 'warn' : 'bad' ), 'Masaüstü hız ' . $score );
		}
	}

	$current = daw_ui_current();
	$group   = daw_ui_group_of( $current );
	$groups  = daw_ui_groups();

	echo '<div class="daw-ui-bar">';
	echo '<a class="daw-ui-brand" href="' . esc_url( admin_url( 'admin.php?page=daw' ) ) . '">';
	if ( $logo ) {
		printf( '<img src="%s" alt="" width="32" height="32">', esc_url( $logo ) );
	}
	echo '<span><strong>Discover Asian World</strong><small>v' . esc_html( defined( 'DAW_VERSION' ) ? DAW_VERSION : '' ) . '</small></span></a>';

	echo '<nav class="daw-ui-links" aria-label="DAW">';
	foreach ( $groups as $key => $g ) {
		$head = array_key_first( $g['pages'] );
		printf( '<a href="%s"%s>%s</a>', esc_url( daw_ui_url( $head ) ), $key === $group ? ' class="is-on"' : '', esc_html( $g['label'] ) );
	}
	printf( '<a href="%s" target="_blank" rel="noopener">Siteyi aç ↗</a>', esc_url( home_url( '/' ) ) );
	echo '</nav>';

	echo '<div class="daw-ui-pills">';
	foreach ( $pills as $p ) {
		printf( '<span class="daw-ui-pill is-%s">%s</span>', esc_attr( $p[0] ), esc_html( $p[1] ) );
	}
	echo '</div></div>';

	if ( '' !== $group && count( $groups[ $group ]['pages'] ) > 1 ) {
		echo '<nav class="daw-ui-sub" aria-label="' . esc_attr( $groups[ $group ]['label'] ) . '">';
		foreach ( $groups[ $group ]['pages'] as $slug => $label ) {
			printf( '<a href="%s"%s>%s</a>', esc_url( daw_ui_url( $slug ) ), $slug === $current ? ' class="is-on" aria-current="page"' : '', esc_html( $label ) );
		}
		echo '</nav>';
	}
}

/** The stylesheet. Every rule is scoped to body.daw-admin. */
function daw_ui_css() {
	return <<<'CSS'
body.daw-admin{--d-bg:#FAF7F2;--d-surface:#fff;--d-band:#F3EEE5;--d-line:#E9E2D8;--d-line-2:#DDD4C7;--d-accent:#7E2128;--d-accent-fill:#6A1B21;--d-accent-hover:#5A1419;--d-gold:#C9971F;--d-gold-text:#8F6114;--d-ink:#1C1D21;--d-muted:#6B655B;--d-ok:#1F7A4D;--d-ok-bg:#E7F4EC;--d-warn:#8F6114;--d-warn-bg:#FBF1DC;--d-bad:#B3261E;--d-bad-bg:#FBE9E7;--d-r:12px;--d-r-s:8px;--d-shadow:0 1px 2px rgba(28,29,33,.05),0 6px 20px rgba(28,29,33,.05);background:var(--d-bg);color:var(--d-ink)}
body.daw-admin #wpcontent,body.daw-admin #wpbody-content{background:var(--d-bg)}
body.daw-admin #wpbody-content{padding-bottom:48px}
body.daw-admin .wrap{max-width:1280px;margin:18px 24px 0 4px}
body.daw-admin .wrap h1,body.daw-admin .wrap h1.wp-heading-inline{font-size:26px;font-weight:700;letter-spacing:-.01em;color:var(--d-ink);margin:6px 0 14px;padding:0}
body.daw-admin .wrap h2{font-size:17px;font-weight:650;color:var(--d-ink);margin:22px 0 10px}
body.daw-admin .wrap h3{font-size:14.5px;font-weight:650;color:var(--d-ink);margin:18px 0 8px}
body.daw-admin .wrap p,body.daw-admin .wrap li,body.daw-admin .wrap td{font-size:13.5px;line-height:1.55}
body.daw-admin .description,body.daw-admin p.description{color:var(--d-muted)}
body.daw-admin a{color:var(--d-accent)}
body.daw-admin a:hover,body.daw-admin a:focus{color:var(--d-accent-hover)}
body.daw-admin code{background:var(--d-band);border-radius:6px;padding:1px 6px;font-size:12.5px;color:var(--d-ink)}

/* the status bar */
body.daw-admin .daw-ui-bar{display:flex;flex-wrap:wrap;align-items:center;gap:12px 20px;margin:16px 24px 6px 4px;max-width:1280px;padding:12px 16px;background:var(--d-surface);border:1px solid var(--d-line);border-radius:var(--d-r);box-shadow:var(--d-shadow)}
body.daw-admin .daw-ui-brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--d-ink)}
body.daw-admin .daw-ui-brand img{width:32px;height:32px;border-radius:50%}
body.daw-admin .daw-ui-brand span{display:flex;flex-direction:column;line-height:1.15}
body.daw-admin .daw-ui-brand strong{font-size:14px}
body.daw-admin .daw-ui-brand small{font-size:11px;color:var(--d-muted)}
body.daw-admin .daw-ui-links{display:flex;flex-wrap:wrap;gap:4px;flex:1 1 auto}
body.daw-admin .daw-ui-links a{padding:6px 11px;border-radius:999px;text-decoration:none;color:var(--d-ink);font-size:13px;font-weight:550}
body.daw-admin .daw-ui-links a:hover{background:var(--d-band)}
body.daw-admin .daw-ui-links a.is-on{background:var(--d-accent);color:#fff}
body.daw-admin .daw-ui-pills{display:flex;flex-wrap:wrap;gap:6px}
body.daw-admin .daw-ui-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;border:1px solid transparent}
body.daw-admin .daw-ui-pill::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
body.daw-admin .daw-ui-pill.is-ok{background:var(--d-ok-bg);color:var(--d-ok)}
body.daw-admin .daw-ui-pill.is-warn{background:var(--d-warn-bg);color:var(--d-warn)}
body.daw-admin .daw-ui-pill.is-bad{background:var(--d-bad-bg);color:var(--d-bad)}
body.daw-admin .daw-ui-sub{display:flex;flex-wrap:wrap;gap:4px;margin:0 24px 8px 4px;max-width:1280px;padding:4px;background:var(--d-band);border-radius:var(--d-r)}
body.daw-admin .daw-ui-sub a{padding:7px 13px;border-radius:var(--d-r-s);text-decoration:none;color:var(--d-muted);font-size:13px;font-weight:600}
body.daw-admin .daw-ui-sub a:hover{background:rgba(255,255,255,.6);color:var(--d-ink)}
body.daw-admin .daw-ui-sub a.is-on{background:var(--d-surface);color:var(--d-accent);box-shadow:0 1px 3px rgba(28,29,33,.08)}

/* cards and boxes */
body.daw-admin .wrap .box,body.daw-admin .wrap .card,body.daw-admin .wrap .postbox,body.daw-admin .wrap .daw-card{background:var(--d-surface);border:1px solid var(--d-line);border-radius:var(--d-r);box-shadow:var(--d-shadow);padding:16px 20px;max-width:none}
body.daw-admin .wrap .card{margin-top:16px}

/* tables */
body.daw-admin .wrap table.widefat,body.daw-admin .wrap table.wp-list-table{border:1px solid var(--d-line);border-radius:var(--d-r);border-collapse:separate;border-spacing:0;overflow:hidden;background:var(--d-surface);box-shadow:var(--d-shadow)}
body.daw-admin .wrap table.widefat thead th,body.daw-admin .wrap table.widefat thead td,body.daw-admin .wrap table.widefat tfoot th{background:var(--d-band);color:var(--d-muted);font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid var(--d-line);padding:10px 12px;text-align:left}
body.daw-admin .wrap table.widefat td,body.daw-admin .wrap table.widefat tbody th{padding:10px 12px;border-bottom:1px solid var(--d-line);vertical-align:top}
/* A word is never cut letter by letter: some screens set word-break:break-word
   inline, which lets a narrow column shrink to one letter (Denetim, 4 Oct 2026).
   Long unbroken strings (URLs) still wrap, only when they must. */
body.daw-admin .wrap table.widefat td,body.daw-admin .wrap table.widefat th{word-break:normal!important;overflow-wrap:break-word;hyphens:auto}
body.daw-admin .wrap table.widefat tbody tr:last-child td,body.daw-admin .wrap table.widefat tbody tr:last-child th{border-bottom:0}
body.daw-admin .wrap table.striped>tbody>:nth-child(odd){background:#FDFBF8}
body.daw-admin .wrap table.widefat tbody tr:hover{background:#FBF6EE}
body.daw-admin .wrap .form-table th{font-weight:600;color:var(--d-ink)}

/* buttons */
body.daw-admin .wrap .button,body.daw-admin .wrap .button-secondary{border-radius:var(--d-r-s);border:1px solid var(--d-line-2);background:var(--d-surface);color:var(--d-ink);min-height:32px;line-height:30px;padding:0 13px;font-weight:550;box-shadow:none}
body.daw-admin .wrap .button:hover,body.daw-admin .wrap .button-secondary:hover{background:var(--d-band);border-color:var(--d-line-2);color:var(--d-ink)}
body.daw-admin .wrap .button-primary{background:var(--d-accent-fill);border-color:var(--d-accent-fill);color:#fff}
body.daw-admin .wrap .button-primary:hover,body.daw-admin .wrap .button-primary:focus{background:var(--d-accent-hover);border-color:var(--d-accent-hover);color:#fff}
body.daw-admin .wrap .button:focus{box-shadow:0 0 0 2px var(--d-surface),0 0 0 4px var(--d-accent);outline:0}
body.daw-admin .wrap .button-link{color:var(--d-accent)}
body.daw-admin .wrap .button-link.daw-sa-bad{color:var(--d-bad)}

/* tabs as segmented pills */
body.daw-admin .nav-tab-wrapper,body.daw-admin .wrap h2.nav-tab-wrapper{border-bottom:0;display:flex;flex-wrap:wrap;gap:4px;padding:4px;background:var(--d-band);border-radius:var(--d-r);margin:0 0 18px}
body.daw-admin .nav-tab{margin:0;border:0;border-radius:var(--d-r-s);background:transparent;color:var(--d-muted);font-size:13px;font-weight:600;padding:7px 13px;line-height:1.3}
body.daw-admin .nav-tab:hover{background:rgba(255,255,255,.6);color:var(--d-ink)}
body.daw-admin .nav-tab-active,body.daw-admin .nav-tab-active:hover,body.daw-admin .nav-tab-active:focus{background:var(--d-surface);color:var(--d-accent);box-shadow:0 1px 3px rgba(28,29,33,.08)}

/* fields */
body.daw-admin .wrap input[type=text],body.daw-admin .wrap input[type=url],body.daw-admin .wrap input[type=number],body.daw-admin .wrap input[type=search],body.daw-admin .wrap input[type=email],body.daw-admin .wrap input:not([type]),body.daw-admin .wrap select,body.daw-admin .wrap textarea{border:1px solid var(--d-line-2);border-radius:var(--d-r-s);background:var(--d-surface);box-shadow:none;min-height:32px}
body.daw-admin .wrap input:focus,body.daw-admin .wrap select:focus,body.daw-admin .wrap textarea:focus{border-color:var(--d-accent);box-shadow:0 0 0 1px var(--d-accent);outline:0}
body.daw-admin .wrap input[type=checkbox],body.daw-admin .wrap input[type=radio]{accent-color:var(--d-accent)}

/* notices */
body.daw-admin .notice,body.daw-admin div.updated,body.daw-admin div.error{border-radius:var(--d-r-s);border:1px solid var(--d-line);border-left-width:4px;box-shadow:none;background:var(--d-surface);margin:10px 0 14px}
body.daw-admin .notice-success,body.daw-admin div.updated{border-left-color:var(--d-ok)}
body.daw-admin .notice-warning{border-left-color:var(--d-gold)}
body.daw-admin .notice-error,body.daw-admin div.error{border-left-color:var(--d-bad)}
body.daw-admin .notice-info{border-left-color:var(--d-accent)}

/* the record audit (Denetim): three bands, fixed columns so nothing spills */
body.daw-admin .daw-ap-bands{display:grid;gap:16px;max-width:1280px;margin-top:14px}
body.daw-admin .daw-ap-band{background:var(--d-surface);border:1px solid var(--d-line);border-radius:var(--d-r);box-shadow:var(--d-shadow);padding:14px 18px;min-width:0}
body.daw-admin .daw-ap-band h2{display:inline-flex;align-items:center;gap:8px;margin:2px 0 8px!important;font-size:16px!important}
body.daw-admin .daw-ap-band h2 span{font-size:12px;font-weight:700;background:var(--d-band);color:var(--d-muted);border-radius:999px;padding:2px 8px}
body.daw-admin .daw-ap-band h2 em{font-style:normal;font-size:12px;font-weight:700;background:var(--d-warn-bg);color:var(--d-warn);border-radius:999px;padding:2px 8px}
body.daw-admin .daw-ap-band.is-bad{border-left:4px solid var(--d-bad)}
body.daw-admin .daw-ap-band.is-live{border-left:4px solid var(--d-gold)}
body.daw-admin .daw-ap-band.is-fixed{border-left:4px solid var(--d-ok)}
body.daw-admin .daw-ap-band summary{list-style:none}
body.daw-admin .daw-ap-band summary::-webkit-details-marker{display:none}
body.daw-admin .daw-ap-band summary h2::after{content:"▾";font-size:12px;color:var(--d-muted)}
body.daw-admin .wrap table.daw-ap-t{table-layout:fixed;width:100%;border:0;box-shadow:none}
body.daw-admin .daw-ap-t col.c-l{width:24%}
body.daw-admin .daw-ap-t col.c-v{width:32%}
body.daw-admin .daw-ap-t col.c-s{width:46px}
body.daw-admin .wrap .daw-ap-t td{overflow-wrap:anywhere;vertical-align:top}
body.daw-admin .daw-ap-t .daw-ap-l{font-weight:600}
body.daw-admin .daw-ap-t .daw-ap-l small{display:inline-block;font-weight:500;color:var(--d-muted);font-variant-numeric:tabular-nums;margin-right:2px}
body.daw-admin .daw-ap-t .daw-ap-s{text-align:center}
body.daw-admin .daw-ap-t .daw-ap-n{font-size:12.5px;color:var(--d-muted)}
body.daw-admin .daw-ap-re{margin-top:6px;font-size:12px;color:var(--d-ok)}
body.daw-admin .daw-ap-re.is-due{color:var(--d-warn);font-weight:700}
body.daw-admin .daw-ap-reform{margin:6px 0 4px}
@media (max-width:900px){body.daw-admin .wrap table.daw-ap-t,body.daw-admin .daw-ap-t tbody,body.daw-admin .daw-ap-t tr,body.daw-admin .wrap .daw-ap-t td{display:block;width:auto}body.daw-admin .daw-ap-t colgroup{display:none}body.daw-admin .daw-ap-t tr{padding:8px 0;border-bottom:1px solid var(--d-line)}body.daw-admin .wrap table.daw-ap-t td{border:0;padding:2px 4px}body.daw-admin .daw-ap-t .daw-ap-s{text-align:left}}

/* status words used across the screens */
body.daw-admin .daw-sa-ok{color:var(--d-ok)}
body.daw-admin .daw-sa-warn{color:var(--d-warn)}
body.daw-admin .daw-sa-bad{color:var(--d-bad)}
body.daw-admin details>summary{cursor:pointer;font-weight:600}
body.daw-admin details[open]>summary{margin-bottom:6px}
body.daw-admin progress{accent-color:var(--d-accent)}

/* phones and narrow windows: tables scroll inside their card */
@media (max-width:960px){
body.daw-admin .wrap{margin-right:12px}
body.daw-admin .daw-ui-bar,body.daw-admin .daw-ui-sub{margin-right:12px}
body.daw-admin .wrap table.widefat td,body.daw-admin .wrap table.widefat th{padding:8px 9px}
}
CSS;
}
