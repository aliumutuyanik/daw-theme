<?php
/**
 * Discover Asian World: DAW › SEO, the screens over the SEO engine.
 *
 * Durum        where the switch from Rank Math stands, and the side-by-side
 *              comparison of every kind of page before and after it
 * Ayarlar      separator, site name, verification codes, share picture
 * robots.txt   the file, editable
 * Yönlendirme  every redirect, the Rank Math import and its report
 * 404          missing addresses people and search engines asked for
 * Kurallar     titles and descriptions against rule 6.2 and rule 5.1
 * Bağlantılar  broken, redirected and missing internal links
 * IndexNow     the key and the last pings
 * Search Console  the service account and the daily download
 *
 * Plus the SEO box on the post and page editor and on category screens, which
 * replaces Rank Math's once Rank Math is off. The box writes the same meta
 * keys Rank Math did, so turning Rank Math back on would find every value.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_SA_SLUG = 'daw-seo';

add_action( 'admin_menu', 'daw_sa_menu', 30 );
function daw_sa_menu() {
	add_submenu_page( 'daw', 'SEO', 'SEO', 'manage_options', DAW_SA_SLUG, 'daw_sa_page' );
}

function daw_sa_url( $tab = 'durum', $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => DAW_SA_SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
}

function daw_sa_tabs() {
	return array(
		'durum'       => 'Durum ve geçiş',
		'ayarlar'     => 'Ayarlar',
		'robots'      => 'robots.txt',
		'yonlendirme' => 'Yönlendirmeler',
		'404'         => '404 kaydı',
		'kurallar'    => 'Kurallar',
		'dizin'       => 'Google dizini',
		'baglanti'    => 'Bağlantılar',
		'indexnow'    => 'IndexNow',
		'gsc'         => 'Search Console',
	);
}

/* ============================================================= actions === */

add_action( 'admin_init', 'daw_sa_handle_post' );
function daw_sa_handle_post() {
	if ( empty( $_POST['daw_sa_action'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return;
	}

	check_admin_referer( 'daw_sa' );

	$action = sanitize_key( wp_unslash( $_POST['daw_sa_action'] ) );
	$tab    = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'durum';
	$msg    = '';
	$p      = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	switch ( $action ) {
		case 'settings':
			$values = array(
				'sep'                   => sanitize_text_field( $p['sep'] ?? '-' ),
				'sitename'              => sanitize_text_field( $p['sitename'] ?? '' ),
				'og_image_id'           => absint( $p['og_image_id'] ?? 0 ),
				'og_image'              => esc_url_raw( $p['og_image'] ?? '' ),
				'facebook'              => esc_url_raw( $p['facebook'] ?? '' ),
				'twitter'               => sanitize_text_field( $p['twitter'] ?? '' ),
				'google_verify'         => sanitize_text_field( $p['google_verify'] ?? '' ),
				'bing_verify'           => sanitize_text_field( $p['bing_verify'] ?? '' ),
				'yandex_verify'         => sanitize_text_field( $p['yandex_verify'] ?? '' ),
				'pinterest_verify'      => sanitize_text_field( $p['pinterest_verify'] ?? '' ),
				'baidu_verify'          => sanitize_text_field( $p['baidu_verify'] ?? '' ),
				'strip_category_base'   => ! empty( $p['strip_category_base'] ),
				'attachment_redirect'   => ! empty( $p['attachment_redirect'] ),
				'disable_date_archives' => ! empty( $p['disable_date_archives'] ),
				'new_window_external'   => ! empty( $p['new_window_external'] ),
				'nofollow_external'     => ! empty( $p['nofollow_external'] ),
				'img_alt'               => ! empty( $p['img_alt'] ),
				'sitemap_per_page'      => max( 50, min( 1000, absint( $p['sitemap_per_page'] ?? 200 ) ) ),
				'sitemap_images'        => ! empty( $p['sitemap_images'] ),
				'sitemap_exclude'       => sanitize_text_field( $p['sitemap_exclude'] ?? '' ),
				'auto_404'              => ! empty( $p['auto_404'] ),
			);
			if ( '' !== $values['twitter'] ) {
				$values['twitter'] = '@' . ltrim( $values['twitter'], '@' );
			}
			daw_eng_settings_save( $values );
			update_option( 'daw_seo_engine', 'off' === ( $p['engine'] ?? 'auto' ) ? 'off' : 'auto', true );
			daw_eng_mark_flush();
			daw_sm_bump();
			$msg = 'Ayarlar kaydedildi.';
			break;

		case 'robots':
			$body = isset( $p['robots_txt'] ) ? (string) $p['robots_txt'] : '';
			if ( ! empty( $p['reset'] ) ) {
				$body = daw_eng_default_robots_txt();
			}
			daw_eng_settings_save( array( 'robots_txt' => sanitize_textarea_field( $body ) ) );
			$msg = 'robots.txt kaydedildi.';
			break;

		case 'rd_add':
			$id  = daw_rd_add(
				(string) ( $p['source'] ?? '' ),
				(string) ( $p['target'] ?? '' ),
				array(
					'match_type' => sanitize_key( $p['match_type'] ?? 'exact' ),
					'code'       => absint( $p['code'] ?? 301 ),
					'force_live' => ! empty( $p['force_live'] ) ? 1 : 0,
					'note'       => sanitize_text_field( $p['note'] ?? '' ),
					'origin'     => ! empty( $p['from404'] ) ? '404' : 'manual',
				)
			);
			$msg = $id ? 'Yönlendirme eklendi.' : 'Eklenemedi: kaynak ya da hedef boş, ya da sayfa kendine yönleniyor.';
			break;

		case 'rd_toggle':
		case 'rd_delete':
			global $wpdb;
			$id = absint( $p['id'] ?? 0 );
			if ( 'rd_delete' === $action ) {
				$wpdb->delete( daw_rd_table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
				$msg = 'Yönlendirme silindi.';
			} else {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . daw_rd_table() . " SET status = IF(status = 'active', 'inactive', 'active') WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
				$msg = 'Durum değiştirildi.';
			}
			daw_rd_flush_cache();
			break;

		case 'rd_import':
			$r   = daw_rd_import_rank_math();
			$msg = sprintf( 'Rank Math aktarımı: %d kayıt bulundu, %d eklendi, %d zaten vardı.', (int) $r['found'], (int) $r['imported'], (int) $r['skipped'] );
			break;

		case 'nf_delete':
			global $wpdb;
			$wpdb->delete( daw_nf_table(), array( 'id' => absint( $p['id'] ?? 0 ) ) ); // phpcs:ignore WordPress.DB
			$msg = '404 satırı silindi.';
			break;

		case 'nf_autofix':
			$done = daw_nf_autofix_run();
			$msg  = sprintf( '%d adres otomatik yönlendirildi.', count( $done ) );
			break;

		case 'gsc_inspect':
			$n   = daw_gsc_inspect_run( 25 );
			$s   = daw_gsc_settings();
			$msg = sprintf( '%d sayfa Google\'a soruldu.', (int) $n ) . ( '' !== $s['error'] ? ' Son hata: ' . $s['error'] : '' );
			break;

		case 'nf_clear':
			global $wpdb;
			$wpdb->query( 'TRUNCATE TABLE ' . daw_nf_table() ); // phpcs:ignore WordPress.DB
			$msg = '404 kaydı temizlendi.';
			break;

		case 'inx_submit':
			$urls = array_filter( array_map( 'esc_url_raw', array_map( 'trim', explode( "\n", (string) ( $p['urls'] ?? '' ) ) ) ) );
			if ( $urls ) {
				$res = daw_inx_submit( $urls );
				$msg = is_wp_error( $res ) ? 'IndexNow hatası: ' . $res->get_error_message() : 'IndexNow yanıtı: HTTP ' . (int) $res . ' (200 ve 202 kabul demek).';
			}
			break;

		case 'gsc_save':
			$prop   = sanitize_text_field( $p['property'] ?? '' );
			$values = array(
				'property'        => $prop,
				// A property typed by hand stays; an untouched one is chosen
				// automatically (daw_gsc_pick_property).
				'property_pinned' => $prop !== daw_gsc_settings()['property'],
			);
			$json   = trim( (string) ( $p['json'] ?? '' ) );
			if ( '' !== $json ) {
				$decoded = json_decode( $json, true );
				if ( is_array( $decoded ) && ! empty( $decoded['client_email'] ) && ! empty( $decoded['private_key'] ) ) {
					$values['json'] = $json;
					delete_transient( 'daw_gsc_token_w2' );
				} else {
					$msg = 'JSON okunamadı: dosyanın tamamını yapıştırın (client_email ve private_key alanları olmalı). ';
				}
			}
			daw_gsc_save( $values );
			$msg .= 'Search Console ayarı kaydedildi.';
			break;

		case 'gsc_test':
			$sites = daw_gsc_sites();
			$msg   = is_wp_error( $sites ) ? 'Bağlantı hatası: ' . $sites->get_error_message() : 'Bağlantı çalışıyor. Erişilen mülkler: ' . ( $sites ? implode( ', ', $sites ) : 'yok (servis hesabını Search Console\'a kullanıcı olarak ekleyin)' );
			break;

		case 'gsc_sync':
			daw_gsc_sync_run();
			$s   = daw_gsc_settings();
			$msg = '' !== $s['error'] ? 'Hata: ' . $s['error'] : 'Çekildi. Son gün: ' . $s['last_day'] . ' (geri kalan günler arka planda iki dakikada bir devam eder).';
			break;

		case 'gsc_forget':
			daw_gsc_save( array( 'json' => '' ) );
			delete_transient( 'daw_gsc_token_w2' );
			$msg = 'Anahtar silindi.';
			break;
	}

	set_transient( 'daw_sa_msg_' . get_current_user_id(), $msg, 60 );
	wp_safe_redirect( daw_sa_url( $tab ) );
	exit;
}

/* ================================================================ page === */

function daw_sa_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'durum'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tabs = daw_sa_tabs();
	$tab  = isset( $tabs[ $tab ] ) ? $tab : 'durum';

	echo '<div class="wrap"><h1>SEO</h1>';

	$msg = get_transient( 'daw_sa_msg_' . get_current_user_id() );
	if ( $msg ) {
		delete_transient( 'daw_sa_msg_' . get_current_user_id() );
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px">';
	foreach ( $tabs as $slug => $label ) {
		printf( '<a class="nav-tab%s" href="%s">%s</a>', $slug === $tab ? ' nav-tab-active' : '', esc_url( daw_sa_url( $slug ) ), esc_html( $label ) );
	}
	echo '</nav>';

	echo '<style>.daw-sa-ok{color:#1a7f37}.daw-sa-bad{color:#b32d2e}.daw-sa-warn{color:#996800}.daw-sa table td{vertical-align:top}.daw-sa code{word-break:break-all}.daw-sa .box{background:#fff;border:1px solid #dcdcde;padding:12px 16px;margin:0 0 16px;max-width:1100px}.daw-sa details summary{cursor:pointer}</style>';
	echo '<div class="daw-sa">';
	call_user_func( 'daw_sa_tab_' . ( '404' === $tab ? 'nf' : $tab ) );
	echo '</div></div>';
}

function daw_sa_form_open( $action, $tab ) {
	echo '<form method="post" style="display:inline">';
	wp_nonce_field( 'daw_sa' );
	printf( '<input type="hidden" name="daw_sa_action" value="%s"><input type="hidden" name="tab" value="%s">', esc_attr( $action ), esc_attr( $tab ) );
}

/** A step-by-step job in the browser: run one chunk, then load the next. */
function daw_sa_continue( $url ) {
	// wp_nonce_url() returns "&amp;" for printing in html. Handed to
	// location.href as it is, the nonce arrives as "amp;_wpnonce" and the
	// next step fails its referer check, so the job stopped after one step.
	$url = html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' );
	printf( '<p><em>Devam ediyor…</em></p><script>setTimeout(function(){location.href=%s;},400);</script>', wp_json_encode( esc_url_raw( $url ) ) );
}

/* ============================================================== durum === */

function daw_sa_tab_durum() {
	$rm   = daw_eng_rm_active();
	$live = daw_eng_live();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['run'] ) && 'compare' === $_GET['run'] ) {
		check_admin_referer( 'daw_sa_run' );
		daw_sa_compare_step( isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	echo '<div class="box"><h2 style="margin-top:0">Şu an</h2><table class="widefat striped" style="max-width:760px"><tbody>';
	if ( $rm ) {
		printf( '<tr><td>Rank Math</td><td><strong>açık</strong> (%s): DAW SEO motoru bekliyor</td></tr>', esc_html( defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : '' ) );
	}
	printf( '<tr><td>DAW SEO motoru</td><td>%s</td></tr>', $live ? '<strong class="daw-sa-ok">canlı: başlık, meta, sitemap, robots.txt bu sitede</strong>' : ( 'off' === get_option( 'daw_seo_engine', 'auto' ) ? '<strong class="daw-sa-bad">kapalı (Ayarlar)</strong>' : 'beklemede: Rank Math açık olduğu sürece hiçbir şey basmaz' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	printf( '<tr><td>Sitemap</td><td><a href="%1$s" target="_blank" rel="noopener">%1$s</a> %2$s</td></tr>', esc_url( home_url( '/sitemap_index.xml' ) ), $live ? '' : '· <a href="' . esc_url( add_query_arg( 'daw_preview', 1, home_url( '/sitemap_index.xml' ) ) ) . '" target="_blank" rel="noopener">bizimkinin önizlemesi</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput
	$counts = array();
	foreach ( daw_sm_types() as $type ) {
		$counts[] = $type . ': ' . number_format_i18n( count( daw_sm_rows( $type ) ) ) . ' adres, ' . daw_sm_pages( $type ) . ' dosya';
	}
	printf( '<tr><td>Sitemap içeriği (bizim)</td><td>%s</td></tr>', esc_html( implode( ' · ', $counts ) ) );
	global $wpdb;
	$rd = daw_rd_tables_ready() ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . daw_rd_table() ) : 0; // phpcs:ignore WordPress.DB
	printf( '<tr><td>Yönlendirmeler</td><td>%d kayıt</td></tr>', (int) $rd );
	echo '</tbody></table></div>';

	$run = wp_nonce_url( daw_sa_url( 'durum', array( 'run' => 'compare', 'step' => 0 ) ), 'daw_sa_run' );

	echo '<div class="box"><h2 style="margin-top:0">Canlı kontrol</h2>';
	printf( '<p><a class="button button-primary" href="%s">Canlı kontrolü başlat</a> Ana sayfa, her kayıt türünden örnekler, liste sayfaları, kategoriler, arama ve 404: her sayfada tek title, tek description, tek canonical ve şema doğru mu, sitemap adresleri tutuyor mu.</p>', esc_url( $run ) );
	echo '</div>';

	daw_sa_compare_report();
}

/** The pages compared: one or two of every kind the site serves. */
function daw_sa_compare_urls() {
	$urls = array( home_url( '/' ) );

	$types = function_exists( 'daw_record_types' ) ? array_keys( daw_record_types() ) : array();
	foreach ( $types as $type ) {
		$ids = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 2,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'tax_query'      => array( array( 'taxonomy' => 'dw_type', 'field' => 'slug', 'terms' => $type ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $id ) {
			$urls[] = get_permalink( $id );
		}
	}

	foreach ( array( 'about', 'contact', 'privacy-policy', 'dramas', 'films', 'people', 'korean-drama', 'food', 'news', 'airing-calendar', 'quizzes' ) as $slug ) {
		$page = get_page_by_path( $slug );
		if ( $page && 'publish' === $page->post_status ) {
			$urls[] = get_permalink( $page );
		}
	}

	$urls[] = home_url( '/dramas/?pg=2' );
	$urls[] = home_url( '/airing-calendar/?view=month' );

	$cats = get_categories( array( 'orderby' => 'count', 'order' => 'DESC', 'number' => 2 ) );
	foreach ( $cats as $cat ) {
		$link   = get_category_link( $cat );
		$urls[] = $link;
		if ( $cat->count > (int) get_option( 'posts_per_page', 10 ) ) {
			$urls[] = user_trailingslashit( trailingslashit( $link ) . 'page/2' );
		}
	}

	$urls[] = home_url( '/?s=kim' );
	$urls[] = home_url( '/daw-seo-test-' . wp_rand( 1000, 9999 ) . '/' );

	return array_values( array_unique( array_filter( $urls ) ) );
}

/** Pull the tags out of a page's head, counting any that appear twice. */
function daw_sa_parse_head( $html ) {
	$out    = array( 'fields' => array(), 'dupes' => array(), 'shadow' => null );
	$head   = (string) substr( (string) $html, 0, (int) ( stripos( (string) $html, '</head>' ) ?: 400000 ) );

	if ( preg_match( '/<!--daw-seo-shadow:([A-Za-z0-9+\/=]+)-->/', $head, $m ) ) {
		$out['shadow'] = json_decode( (string) base64_decode( $m[1] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	$prev = libxml_use_internal_errors( true );
	$dom  = new DOMDocument();
	$dom->loadHTML( '<?xml encoding="utf-8"?>' . $head . '</head><body></body></html>' );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	$xp = new DOMXPath( $dom );

	$add = static function ( $key, $value ) use ( &$out ) {
		if ( isset( $out['fields'][ $key ] ) ) {
			$out['dupes'][ $key ] = ( $out['dupes'][ $key ] ?? 1 ) + 1;
			return;
		}
		$out['fields'][ $key ] = trim( (string) $value );
	};

	foreach ( $xp->query( '//title' ) as $n ) {
		$add( 'title', $n->textContent );
	}
	foreach ( $xp->query( '//meta[@name="description"]' ) as $n ) {
		$add( 'description', $n->getAttribute( 'content' ) );
	}
	foreach ( $xp->query( '//meta[@name="robots"]' ) as $n ) {
		$add( 'robots', $n->getAttribute( 'content' ) );
	}
	foreach ( $xp->query( '//link[@rel="canonical"]' ) as $n ) {
		$add( 'canonical', $n->getAttribute( 'href' ) );
	}
	foreach ( $xp->query( '//meta[starts-with(@property,"og:")]' ) as $n ) {
		$p = $n->getAttribute( 'property' );
		if ( in_array( $p, array( 'og:title', 'og:description', 'og:image', 'og:type', 'og:url' ), true ) ) {
			$add( $p, $n->getAttribute( 'content' ) );
		}
	}
	foreach ( $xp->query( '//meta[starts-with(@name,"twitter:")]' ) as $n ) {
		$p = $n->getAttribute( 'name' );
		if ( in_array( $p, array( 'twitter:card', 'twitter:title', 'twitter:description', 'twitter:image' ), true ) ) {
			$add( $p, $n->getAttribute( 'content' ) );
		}
	}

	return $out;
}

/** Two values are the same for a search engine. */
function daw_sa_norm( $key, $value ) {
	$v = html_entity_decode( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$v = trim( preg_replace( '/\s+/u', ' ', $v ) );

	if ( 'robots' === $key ) {
		$t = array_filter( array_map( 'trim', explode( ',', strtolower( $v ) ) ) );
		sort( $t );
		return implode( ',', $t );
	}

	return $v;
}

function daw_sa_compare_keys() {
	return array( 'title', 'description', 'robots', 'canonical', 'og:type', 'og:title', 'og:description', 'og:url', 'og:image', 'twitter:card', 'twitter:title', 'twitter:description', 'twitter:image' );
}

/** One step: five pages. The last step also compares the sitemaps. */
function daw_sa_compare_step( $step ) {
	$report = get_option( 'daw_seo_shadow_report' );

	if ( 0 === $step || ! is_array( $report ) ) {
		$report = array(
			'at'      => time(),
			'mode'    => daw_eng_live() ? 'live' : 'shadow',
			'queue'   => daw_sa_compare_urls(),
			'rows'    => array(),
			'sitemap' => null,
			'done'    => false,
		);
	}

	$token = daw_eng_shadow_token();
	// Three per step: each is a full page render on this server, and five
	// slow ones in a row can run into the PHP time limit.
	$batch = array_splice( $report['queue'], 0, 3 );

	foreach ( $batch as $url ) {
		$fetch = add_query_arg( 'daw_seo_shadow', $token, $url );
		$res   = wp_remote_get(
			$fetch,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array( 'X-DAW-SEO-Shadow' => $token ),
				'user-agent'  => 'DAW-SEO-Compare/1.0',
			)
		);

		$row = array( 'url' => $url, 'code' => 0, 'diffs' => array(), 'dupes' => array(), 'note' => '' );

		if ( is_wp_error( $res ) ) {
			$row['note']      = $res->get_error_message();
			$report['rows'][] = $row;
			continue;
		}

		$row['code'] = (int) wp_remote_retrieve_response_code( $res );

		if ( $row['code'] >= 300 && $row['code'] < 400 ) {
			$row['note']      = 'Yönlendirme: ' . wp_remote_retrieve_header( $res, 'location' );
			$report['rows'][] = $row;
			continue;
		}

		$parsed       = daw_sa_parse_head( (string) wp_remote_retrieve_body( $res ) );
		$row['dupes'] = $parsed['dupes'];
		$ours         = $parsed['shadow'];

		if ( 'live' === $report['mode'] ) {
			// Rank Math is gone: what is on the page is ours. Check it is
			// there once and agrees with what the engine says it printed.
			$ours = is_array( $ours ) ? $ours : array();
			foreach ( array( 'title', 'description', 'canonical', 'robots' ) as $key ) {
				$on_page = daw_sa_norm( $key, $parsed['fields'][ $key ] ?? '' );
				$noindex = false !== strpos( daw_sa_norm( 'robots', $parsed['fields']['robots'] ?? '' ), 'noindex' );
				if ( '' === $on_page && 'description' !== $key && 404 !== $row['code'] && ! ( 'canonical' === $key && $noindex ) ) {
					$row['diffs'][ $key ] = array( '(yok)', '' );
				}
			}
		} elseif ( ! is_array( $ours ) ) {
			$row['note'] = 'Motorun çıktısı sayfada yok (sayfa önbellekten gelmiş olabilir).';
		} else {
			foreach ( daw_sa_compare_keys() as $key ) {
				$a = daw_sa_norm( $key, $parsed['fields'][ $key ] ?? '' );
				$b = daw_sa_norm( $key, $ours[ $key ] ?? '' );
				if ( 'canonical' === $key || 'og:url' === $key ) {
					$a = remove_query_arg( 'daw_seo_shadow', $a );
					$b = remove_query_arg( 'daw_seo_shadow', $b );
				}
				if ( $a !== $b ) {
					$row['diffs'][ $key ] = array( $a, $b );
				}
			}
		}

		$report['rows'][] = $row;
	}

	if ( ! $report['queue'] ) {
		$report['sitemap'] = 'shadow' === $report['mode'] ? daw_sa_compare_sitemaps() : null;
		$report['done']    = true;
		$report['ok']      = count(
			array_filter(
				$report['rows'],
				static function ( $r ) {
					return ! $r['diffs'] && ! $r['dupes'] && '' === $r['note'];
				}
			)
		);
	}

	update_option( 'daw_seo_shadow_report', $report, false );

	if ( ! $report['done'] ) {
		printf( '<p>%d / %d sayfa…</p>', count( $report['rows'] ), count( $report['rows'] ) + count( $report['queue'] ) );
		daw_sa_continue( wp_nonce_url( daw_sa_url( 'durum', array( 'run' => 'compare', 'step' => $step + 1 ) ), 'daw_sa_run' ) );
		return;
	}

	echo '<p>Bitti.</p>';
	daw_sa_continue( daw_sa_url( 'durum' ) );
}

/** Rank Math's sitemap urls against ours. */
function daw_sa_compare_sitemaps() {
	$theirs = array();
	$files  = array();
	$res    = wp_remote_get( home_url( '/sitemap_index.xml' ), array( 'timeout' => 30 ) );

	if ( is_wp_error( $res ) ) {
		return array( 'error' => $res->get_error_message() );
	}

	if ( preg_match_all( '#<loc>([^<]+)</loc>#', (string) wp_remote_retrieve_body( $res ), $m ) ) {
		foreach ( $m[1] as $child ) {
			$child   = html_entity_decode( $child, ENT_QUOTES | ENT_XML1, 'UTF-8' );
			$files[] = basename( (string) wp_parse_url( $child, PHP_URL_PATH ) );
			if ( false !== strpos( $child, 'daw-news-sitemap' ) ) {
				continue;
			}
			$r = wp_remote_get( $child, array( 'timeout' => 30 ) );
			if ( ! is_wp_error( $r ) && preg_match_all( '#<url>\s*<loc>([^<]+)</loc>#', (string) wp_remote_retrieve_body( $r ), $mm ) ) {
				foreach ( $mm[1] as $loc ) {
					$theirs[ html_entity_decode( trim( $loc ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) ] = true;
				}
			}
		}
	}

	$ours      = array();
	$our_files = array();
	foreach ( daw_sm_types() as $type ) {
		for ( $i = 1; $i <= daw_sm_pages( $type ); $i++ ) {
			$our_files[] = daw_sm_file_name( $type, $i );
			foreach ( daw_sm_file_entries( $type, $i ) as $e ) {
				$ours[ $e['loc'] ] = true;
			}
		}
	}

	return array(
		'their_files' => $files,
		'our_files'   => $our_files,
		'their_count' => count( $theirs ),
		'our_count'   => count( $ours ),
		'only_theirs' => array_slice( array_keys( array_diff_key( $theirs, $ours ) ), 0, 40 ),
		'only_ours'   => array_slice( array_keys( array_diff_key( $ours, $theirs ) ), 0, 40 ),
	);
}

function daw_sa_compare_report() {
	$report = get_option( 'daw_seo_shadow_report' );

	if ( ! is_array( $report ) || empty( $report['done'] ) ) {
		return;
	}

	$total = count( $report['rows'] );

	echo '<div class="box"><h2 style="margin-top:0">Son karşılaştırma</h2>';
	printf(
		'<p>%s · %s · <strong class="%s">%d / %d sayfa sorunsuz</strong></p>',
		esc_html( wp_date( 'j M Y H:i', (int) $report['at'] ) ),
		'live' === $report['mode'] ? 'Canlı kontrol' : 'Rank Math ile motor yan yana',
		(int) $report['ok'] === $total ? 'daw-sa-ok' : 'daw-sa-warn',
		(int) $report['ok'],
		(int) $total
	);

	echo '<table class="widefat striped"><thead><tr><th style="width:34%">Sayfa</th><th>HTTP</th><th>Sonuç</th></tr></thead><tbody>';
	foreach ( $report['rows'] as $row ) {
		$cell = '';
		if ( '' !== $row['note'] ) {
			$cell .= '<span class="daw-sa-warn">' . esc_html( $row['note'] ) . '</span><br>';
		}
		foreach ( (array) $row['dupes'] as $k => $n ) {
			$cell .= '<span class="daw-sa-bad">' . esc_html( $k ) . ' sayfada ' . (int) $n . ' kez</span><br>';
		}
		foreach ( (array) $row['diffs'] as $k => $pair ) {
			$cell .= sprintf(
				'<details><summary class="daw-sa-bad">%s farklı</summary><div style="font-size:12px"><strong>%s:</strong> <code>%s</code><br><strong>Motor:</strong> <code>%s</code></div></details>',
				esc_html( $k ),
				'live' === $report['mode'] ? 'Sayfada' : 'Rank Math',
				esc_html( '' === $pair[0] ? '(boş)' : $pair[0] ),
				esc_html( '' === $pair[1] ? '(boş)' : $pair[1] )
			);
		}
		if ( '' === $cell ) {
			$cell = '<span class="daw-sa-ok">aynı</span>';
		}
		printf( '<tr><td><a href="%1$s" target="_blank" rel="noopener"><code>%2$s</code></a></td><td>%3$d</td><td>%4$s</td></tr>', esc_url( $row['url'] ), esc_html( str_replace( home_url(), '', $row['url'] ) ), (int) $row['code'], $cell ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</tbody></table>';

	$sm = $report['sitemap'];
	if ( is_array( $sm ) ) {
		echo '<h3>Sitemap</h3>';
		if ( isset( $sm['error'] ) ) {
			echo '<p class="daw-sa-warn">' . esc_html( $sm['error'] ) . '</p>';
		} else {
			$their      = 'live' === $report['mode'] ? 'Yayında' : 'Rank Math';
			$same_files = ! array_diff( array_filter( $sm['their_files'], static function ( $f ) {
				return false === strpos( $f, 'news' );
			} ), $sm['our_files'] );
			printf( '<p>Dosyalar: ' . esc_html( $their ) . ' <code>%s</code> · Motor <code>%s</code> %s</p>', esc_html( implode( ', ', $sm['their_files'] ) ), esc_html( implode( ', ', $sm['our_files'] ) ), $same_files ? '<span class="daw-sa-ok">adresler aynı</span>' : '<span class="daw-sa-bad">dosya adları farklı</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput
			printf( '<p>Adres sayısı: ' . esc_html( $their ) . ' %d · Motor %d</p>', (int) $sm['their_count'], (int) $sm['our_count'] );
			if ( $sm['only_theirs'] ) {
				echo '<details><summary class="daw-sa-warn">Yalnız ' . esc_html( $their ) . ' ('  . count( $sm['only_theirs'] ) . ')</summary><ul>';
				foreach ( $sm['only_theirs'] as $u ) {
					echo '<li><code>' . esc_html( $u ) . '</code></li>';
				}
				echo '</ul></details>';
			}
			if ( $sm['only_ours'] ) {
				echo '<details><summary class="daw-sa-warn">Yalnız motorda (' . count( $sm['only_ours'] ) . ')</summary><ul>';
				foreach ( $sm['only_ours'] as $u ) {
					echo '<li><code>' . esc_html( $u ) . '</code></li>';
				}
				echo '</ul></details>';
			}
		}
	}

	echo '</div>';
}

/* ============================================================= ayarlar === */

function daw_sa_tab_ayarlar() {
	$s = daw_eng_settings();

	daw_sa_form_open( 'settings', 'ayarlar' );
	echo '<table class="form-table" role="presentation"><tbody>';

	$text = static function ( $name, $label, $help = '' ) use ( $s ) {
		printf(
			'<tr><th><label for="%1$s">%2$s</label></th><td><input class="regular-text" id="%1$s" name="%1$s" value="%3$s">%4$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) ( $s[ $name ] ?? '' ) ),
			'' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	};
	$check = static function ( $name, $label ) use ( $s ) {
		printf( '<label style="display:block;margin:2px 0"><input type="checkbox" name="%s" value="1"%s> %s</label>', esc_attr( $name ), checked( ! empty( $s[ $name ] ), true, false ), esc_html( $label ) );
	};

	$text( 'sitename', 'Site adı', 'Başlık şablonlarındaki %sitename% ve og:site_name.' );
	$text( 'sep', 'Ayırıcı', 'Şablonlardaki %sep%.' );
	$text( 'og_image_id', 'Varsayılan paylaşım görseli (ortam ID)', 'Kendi görseli olmayan sayfalarda og:image.' );
	$text( 'og_image', 'Varsayılan paylaşım görseli (adres)', 'ID yoksa bu adres kullanılır.' );
	$text( 'facebook', 'Facebook sayfası', 'article:publisher.' );
	$text( 'twitter', 'X kullanıcı adı', 'twitter:site, @ ile.' );
	$text( 'google_verify', 'Google doğrulama kodu' );
	$text( 'bing_verify', 'Bing doğrulama kodu' );
	$text( 'yandex_verify', 'Yandex doğrulama kodu' );
	$text( 'pinterest_verify', 'Pinterest doğrulama kodu' );
	$text( 'baidu_verify', 'Baidu doğrulama kodu' );
	$text( 'sitemap_per_page', 'Sitemap dosya başına adres', 'Değiştirmek sitemap dosya adlarını değiştirir; 200\'de kalması önerilir.' );
	$text( 'sitemap_exclude', 'Sitemap dışı ID\'ler', 'Virgülle.' );

	echo '<tr><th>Davranış</th><td>';
	$check( 'strip_category_base', 'Kategori adresinde /category/ yok (/k-drama/). Kapatmak kategori adreslerini değiştirir.' );
	$check( 'attachment_redirect', 'Ortam (ek) sayfaları bağlı yazıya 301' );
	$check( 'disable_date_archives', 'Tarih arşivleri ana sayfaya 301' );
	$check( 'new_window_external', 'Yazıdaki dış bağlantılar yeni sekmede, rel="noopener" (kural 3.6)' );
	$check( 'nofollow_external', 'Yazıdaki dış bağlantılara rel="nofollow"' );
	$check( 'img_alt', 'Alt metni boş görsellere sayfa başlığı' );
	$check( 'sitemap_images', 'Sitemap\'e görselleri ekle' );
	$check( 'auto_404', '404\'leri kesin eşleşme varsa saatte bir kendiliğinden yönlendir' );
	echo '</td></tr>';

	printf(
		'<tr><th>Motor</th><td><select name="engine"><option value="auto"%s>Açık</option><option value="off"%s>Kapalı (acil durum)</option></select><p class="description">"Kapalı" seçilirse sayfalarda hiçbir SEO etiketi olmaz. Yalnız sorun ayıklarken.</p></td></tr>',
		selected( get_option( 'daw_seo_engine', 'auto' ), 'auto', false ),
		selected( get_option( 'daw_seo_engine', 'auto' ), 'off', false )
	);

	echo '</tbody></table>';
	submit_button( 'Kaydet' );
	echo '</form>';

	echo '<p class="description" style="max-width:820px">Başlık ve açıklama şablonları (yazı, sayfa, kategori, arama) kayıtlı şablonlardan uygulanır. Kayıtların başlık ve açıklamaları seo.php üreticisinden ya da yazının kendi SEO kutusundan gelir.</p>';
}

/* ============================================================== robots === */

function daw_sa_tab_robots() {
	$body = (string) daw_eng_set( 'robots_txt', daw_eng_default_robots_txt() );

	if ( daw_eng_rm_active() ) {
		echo '<div class="notice notice-warning inline"><p>Rank Math açıkken /robots.txt dosyasını Rank Math veriyor. Buradaki metin Rank Math kapandığında geçerli olur.</p></div>';
	}

	daw_sa_form_open( 'robots', 'robots' );
	printf( '<p><textarea name="robots_txt" rows="22" class="large-text code">%s</textarea></p>', esc_textarea( $body ) );
	echo '<p class="description">Sonuna "Sitemap: …/sitemap_index.xml" satırı kendiliğinden eklenir; Google News sitemap satırını news-sitemap.php ekler.</p>';
	submit_button( 'Kaydet', 'primary', 'submit', false );
	echo ' <button class="button" name="reset" value="1">Önerilen metne dön</button>';
	echo '</form>';
	printf( '<p><a href="%1$s" target="_blank" rel="noopener">%1$s</a></p>', esc_url( home_url( '/robots.txt' ) ) );
}

/* ========================================================= yönlendirme === */

function daw_sa_tab_yonlendirme() {
	global $wpdb;

	if ( ! daw_rd_tables_ready() ) {
		echo '<p>Tablo henüz kurulmadı; sayfayı yenileyin.</p>';
		return;
	}

	echo '<div class="box"><h2 style="margin-top:0">Yeni yönlendirme</h2>';
	daw_sa_form_open( 'rd_add', 'yonlendirme' );
	echo '<p><input name="source" class="regular-text" placeholder="/eski-adres/" required> → <input name="target" class="regular-text" placeholder="/yeni-adres/ ya da tam adres"> ';
	echo '<select name="match_type"><option value="exact">tam</option><option value="start">ile başlar</option><option value="end">ile biter</option><option value="contains">içerir</option><option value="regex">regex</option></select> ';
	echo '<select name="code"><option>301</option><option>302</option><option>307</option><option>410</option><option>451</option></select> ';
	echo '<label><input type="checkbox" name="force_live" value="1"> sayfa varken de</label> ';
	echo '<input name="note" placeholder="not" style="width:180px"> ';
	submit_button( 'Ekle', 'primary', 'submit', false );
	echo '</p></form>';
	echo '<p class="description">Yönlendirme yalnız adres 404 verdiğinde çalışır, böylece yaşayan bir sayfayı hiçbir zaman gizlemez. "Sayfa varken de" işaretliyse her istekte çalışır. Yayındaki bir yazının adresi değiştiğinde eski adres buraya kendiliğinden eklenir.</p>';
	echo '</div>';

	$report = get_option( 'daw_rd_import_report' );
	// The one-time import is history; its leftovers only matter while a
	// decision on a forced redirect is still open.
	if ( ! daw_eng_rm_active() && ( ! is_array( $report ) || empty( $report['forced'] ) ) ) {
		return;
	}
	echo '<div class="box"><h2 style="margin-top:0">Eski yönlendirme aktarımı</h2>';
	if ( is_array( $report ) ) {
		printf( '<p>%s · %d kayıt bulundu, %d eklendi, %d atlandı.</p>', esc_html( wp_date( 'j M Y H:i', (int) $report['at'] ) ), (int) $report['found'], (int) $report['imported'], (int) $report['skipped'] );
		if ( ! empty( $report['forced'] ) ) {
			echo '<p class="daw-sa-warn"><strong>Kaynağı yaşayan bir sayfa olan yönlendirmeler</strong> (Rank Math bunları sayfa varken de yönlendiriyordu; aynı davranış korundu, karar sizin):</p><ul>';
			foreach ( $report['forced'] as $f ) {
				printf( '<li><code>%s</code> → <code>%s</code> · <a href="%s">sayfa #%d</a></li>', esc_html( $f['source'] ), esc_html( $f['target'] ), esc_url( (string) get_edit_post_link( (int) $f['post'] ) ), (int) $f['post'] );
			}
			echo '</ul>';
		}
	} else {
		echo '<p>Henüz yapılmadı.</p>';
	}
	daw_sa_form_open( 'rd_import', 'yonlendirme' );
	submit_button( 'Rank Math\'ten yeniden aktar (yalnız eksikleri ekler)', 'secondary', 'submit', false );
	echo '</form></div>';

	$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . daw_rd_table() . ' ORDER BY id DESC LIMIT 500', ARRAY_A ); // phpcs:ignore WordPress.DB

	echo '<table class="widefat striped"><thead><tr><th>Kaynak</th><th>Tür</th><th>Hedef</th><th>Kod</th><th>Durum</th><th>Tık</th><th>Son</th><th>Köken</th><th></th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr>';
		printf( '<td><code>%s</code>%s</td>', esc_html( $r['source'] ), $r['force_live'] ? ' <span class="daw-sa-warn" title="Sayfa varken de çalışır">⚑</span>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<td>%s</td><td><code>%s</code></td><td>%d</td>', esc_html( $r['match_type'] ), esc_html( $r['target'] ), (int) $r['code'] );
		printf( '<td class="%s">%s</td>', 'active' === $r['status'] ? 'daw-sa-ok' : 'daw-sa-warn', 'active' === $r['status'] ? 'açık' : 'kapalı' );
		printf( '<td>%d</td><td>%s</td><td>%s%s</td><td>', (int) $r['hits'], esc_html( $r['last_hit'] ? wp_date( 'j M', strtotime( $r['last_hit'] . ' UTC' ) ) : '' ), esc_html( $r['origin'] ), '' !== $r['note'] ? '<br><small>' . esc_html( $r['note'] ) . '</small>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		daw_sa_form_open( 'rd_toggle', 'yonlendirme' );
		printf( '<input type="hidden" name="id" value="%d"><button class="button-link">%s</button></form> · ', (int) $r['id'], 'active' === $r['status'] ? 'kapat' : 'aç' );
		daw_sa_form_open( 'rd_delete', 'yonlendirme' );
		printf( '<input type="hidden" name="id" value="%d"><button class="button-link daw-sa-bad" onclick="return confirm(\'Silinsin mi?\')">sil</button></form>', (int) $r['id'] );
		echo '</td></tr>';
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="9">Kayıt yok.</td></tr>';
	}
	echo '</tbody></table>';

	$legacy = function_exists( 'daw_get_redirects' ) ? daw_get_redirects() : array();
	if ( $legacy ) {
		echo '<h3>Koddaki birleştirme yönlendirmeleri (redirects.php)</h3><ul>';
		foreach ( $legacy as $from => $to ) {
			printf( '<li><code>%s</code> → <code>%s</code></li>', esc_html( $from ), esc_html( $to ) );
		}
		echo '</ul>';
	}
}

/* ================================================================ 404 === */

function daw_sa_tab_nf() {
	global $wpdb;

	if ( ! daw_rd_tables_ready() ) {
		echo '<p>Tablo henüz kurulmadı; sayfayı yenileyin.</p>';
		return;
	}

	$log = array_reverse( (array) get_option( 'daw_nf_autofix_log', array() ) );
	echo '<div class="box"><strong>Otomatik düzeltme</strong> ' . ( daw_eng_set( 'auto_404', true ) ? '<span class="daw-sa-ok">açık</span>: saatte bir, kesin eşleşmesi olan 404\'ler 301 ile hedefine gönderilir. Search Console\'da görünüp sitede artık olmayan adresler de buraya düşer.' : '<span class="daw-sa-warn">kapalı (Ayarlar)</span>' ) . ' ';
	daw_sa_form_open( 'nf_autofix', '404' );
	echo '<button class="button">Şimdi çalıştır</button></form>';
	if ( $log ) {
		echo '<details style="margin-top:8px"><summary>Son otomatik yönlendirmeler</summary><table class="widefat striped"><tbody>';
		foreach ( array_slice( $log, 0, 10 ) as $entry ) {
			foreach ( $entry['rows'] as $row ) {
				printf( '<tr><td>%s</td><td><code>%s</code> → <code>%s</code></td><td><small>%s</small></td></tr>', esc_html( wp_date( 'j M H:i', (int) $entry['at'] ) ), esc_html( $row[0] ), esc_html( $row[1] ), esc_html( $row[2] ) );
			}
		}
		echo '</tbody></table></details>';
	}
	echo '</div>';

	echo '<p class="description" style="max-width:860px">Yalnız insanların ve arama motoru tarayıcılarının (Google, Bing, Yandex, Apple, DuckDuckGo) düştüğü adresler. Diğer botlar ve .php, wp-admin, .env gibi tarama denemeleri hiç kaydedilmez. Her satırda en olası hedef önerilir; "Yönlendir" ile 301 eklenir ve satır listeden çıkar.</p>';

	$pages = (array) $wpdb->get_results( 'SELECT * FROM ' . daw_nf_table() . " WHERE kind = 'page' ORDER BY (hits_human + hits_search) DESC, last_seen DESC LIMIT 150", ARRAY_A ); // phpcs:ignore WordPress.DB

	echo '<table class="widefat striped"><thead><tr><th>Adres</th><th>İnsan</th><th>Arama motoru</th><th>Son</th><th>Geldiği yer</th><th>Yönlendir</th><th></th></tr></thead><tbody>';
	foreach ( $pages as $i => $r ) {
		$sug = $i < 60 ? daw_nf_suggest( $r['path'] ) : null;
		echo '<tr>';
		printf( '<td><code>%s</code></td><td>%d</td><td>%d</td><td>%s</td><td><small>%s</small></td><td>', esc_html( $r['path'] ), (int) $r['hits_human'], (int) $r['hits_search'], esc_html( wp_date( 'j M H:i', strtotime( $r['last_seen'] . ' UTC' ) ) ), esc_html( $r['last_ref'] ) );
		daw_sa_form_open( 'rd_add', '404' );
		printf(
			'<input type="hidden" name="source" value="%s"><input type="hidden" name="from404" value="1"><input name="target" value="%s" style="width:260px" placeholder="/hedef/"> <button class="button">Yönlendir</button>%s</form>',
			esc_attr( $r['path'] ),
			esc_attr( $sug ? (string) wp_parse_url( $sug['url'], PHP_URL_PATH ) : '' ),
			$sug ? '<br><small class="daw-sa-ok">' . esc_html( $sug['why'] ) . '</small>' : ''
		);
		echo '</td><td>';
		daw_sa_form_open( 'nf_delete', '404' );
		printf( '<input type="hidden" name="id" value="%d"><button class="button-link">yok say</button></form>', (int) $r['id'] );
		echo '</td></tr>';
	}
	if ( ! $pages ) {
		echo '<tr><td colspan="7">Kayıt yok.</td></tr>';
	}
	echo '</tbody></table>';

	$assets = (array) $wpdb->get_results( 'SELECT * FROM ' . daw_nf_table() . " WHERE kind = 'asset' ORDER BY (hits_human + hits_search) DESC LIMIT 60", ARRAY_A ); // phpcs:ignore WordPress.DB
	if ( $assets ) {
		echo '<details style="margin-top:16px"><summary>Eksik dosyalar: görsel, font, stil (' . count( $assets ) . ')</summary><table class="widefat striped"><tbody>';
		foreach ( $assets as $r ) {
			printf( '<tr><td><code>%s</code></td><td>%d</td><td><small>%s</small></td></tr>', esc_html( $r['path'] ), (int) $r['hits_human'] + (int) $r['hits_search'], esc_html( $r['last_ref'] ) );
		}
		echo '</tbody></table></details>';
	}

	echo '<p style="margin-top:16px">';
	daw_sa_form_open( 'nf_clear', '404' );
	echo '<button class="button" onclick="return confirm(\'Tüm 404 kaydı silinsin mi?\')">Kaydı temizle</button></form></p>';
}

/* ============================================================ kurallar === */

/**
 * The title and description a post will actually get, worked out without a
 * page request: its own SEO fields if it has them, the record generator in
 * seo.php if it is a record, the template otherwise.
 *
 * @return array{title: string, description: string, manual_title: bool, manual_desc: bool}
 */
function daw_sa_effective( $post ) {
	$post = get_post( $post );
	$ctx  = array( 'kind' => 'singular', 'post' => $post );

	$manual_t = '' !== trim( (string) get_post_meta( $post->ID, 'rank_math_title', true ) );
	$manual_d = '' !== trim( (string) get_post_meta( $post->ID, 'rank_math_description', true ) );

	$title = daw_eng_title_raw( $ctx );
	$desc  = daw_eng_description_raw( $ctx );

	if ( 'post' === $post->post_type ) {
		if ( ! $manual_t && function_exists( 'daw_generate_seo_title' ) ) {
			$gen = daw_generate_seo_title( $post->ID );
			if ( $gen ) {
				$title = $gen;
			}
		}
		if ( ! $manual_d && function_exists( 'daw_generate_seo_description' ) ) {
			$gen = daw_generate_seo_description( $post->ID );
			if ( $gen ) {
				$desc = $gen;
			}
		}
	}

	return array(
		'title'        => daw_eng_plain( $title ),
		'description'  => daw_eng_plain( $desc ),
		'manual_title' => $manual_t,
		'manual_desc'  => $manual_d,
	);
}

/**
 * What breaks the rules on one post: rule 6.2 (title up to 60 characters,
 * description 150 to 160) and rule 5.1 (no em dash in published text).
 *
 * @return string[]
 */
function daw_sa_rule_issues( $post_id ) {
	$post = get_post( $post_id );

	if ( ! $post ) {
		return array();
	}

	$e      = daw_sa_effective( $post );
	$issues = array();
	$tl     = mb_strlen( $e['title'] );
	$dl     = mb_strlen( $e['description'] );

	if ( 0 === $tl ) {
		$issues[] = 'başlık yok';
	} elseif ( $tl > 60 ) {
		$issues[] = 'başlık ' . $tl . ' > 60';
	}

	if ( 0 === $dl ) {
		$issues[] = 'açıklama yok';
	} elseif ( $dl < 150 || $dl > 160 ) {
		$issues[] = 'açıklama ' . $dl;
	}

	if ( false !== mb_strpos( $e['title'] . $e['description'], "\u{2014}" ) ) {
		$issues[] = 'uzun tire';
	}

	return $issues;
}

function daw_sa_tab_kurallar() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['run'] ) ) {
		check_admin_referer( 'daw_sa_run' );
		daw_sa_rules_step( absint( $_GET['step'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$data = get_option( 'daw_sa_rules' );
	$run  = wp_nonce_url( daw_sa_url( 'kurallar', array( 'run' => 1, 'step' => 0 ) ), 'daw_sa_run' );

	printf( '<p><a class="button button-primary" href="%s">Tüm yazı ve sayfaları tara</a> %s</p>', esc_url( $run ), is_array( $data ) && ! empty( $data['at'] ) ? 'Son tarama: ' . esc_html( wp_date( 'j M Y H:i', (int) $data['at'] ) ) : '' );
	echo '<p class="description">Her sayfanın gerçekte alacağı başlık ve açıklama (yazının kendi SEO alanı, kayıt üreticisi ya da şablon) kurallara göre denetlenir: başlık en çok 60, açıklama 150 ile 160 karakter, uzun tire yok, iki sayfa aynı başlığı taşımaz.</p>';

	if ( ! is_array( $data ) || empty( $data['rows'] ) ) {
		return;
	}

	$filter = isset( $_GET['f'] ) ? sanitize_text_field( wp_unslash( $_GET['f'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$counts = array();
	foreach ( $data['rows'] as $row ) {
		foreach ( $row['i'] as $issue ) {
			$key            = preg_replace( '/\s[\d>\s]+$/', '', $issue );
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
	}

	echo '<p>';
	printf( '<a href="%s">Hepsi (%d)</a>', esc_url( daw_sa_url( 'kurallar' ) ), count( $data['rows'] ) );
	foreach ( $counts as $key => $n ) {
		printf( ' · <a href="%s"%s>%s (%d)</a>', esc_url( daw_sa_url( 'kurallar', array( 'f' => $key ) ) ), $filter === $key ? ' style="font-weight:600"' : '', esc_html( $key ), (int) $n );
	}
	echo '</p>';

	echo '<table class="widefat striped"><thead><tr><th>Sayfa</th><th>Başlık</th><th>Açıklama</th><th>Sorun</th></tr></thead><tbody>';
	$shown = 0;
	foreach ( $data['rows'] as $id => $row ) {
		if ( '' !== $filter && ! preg_grep( '/^' . preg_quote( $filter, '/' ) . '/', $row['i'] ) ) {
			continue;
		}
		if ( ++$shown > 300 ) {
			break;
		}
		printf(
			'<tr><td><a href="%s">#%d</a></td><td>%s <small>(%d)</small></td><td><small>%s</small> <small>(%d)</small></td><td class="daw-sa-bad">%s</td></tr>',
			esc_url( (string) get_edit_post_link( (int) $id ) ),
			(int) $id,
			esc_html( $row['t'] ),
			mb_strlen( $row['t'] ),
			esc_html( $row['d'] ),
			mb_strlen( $row['d'] ),
			esc_html( implode( ' · ', $row['i'] ) )
		);
	}
	echo '</tbody></table>';
}

/** Two hundred posts per step; duplicates are found at the end. */
function daw_sa_rules_step( $step ) {
	global $wpdb;

	$per  = 200;
	$data = 0 === $step ? array( 'rows' => array(), 'titles' => array() ) : (array) get_option( 'daw_sa_rules_tmp', array( 'rows' => array(), 'titles' => array() ) );
	$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page') ORDER BY ID ASC LIMIT %d OFFSET %d", $per, $step * $per ) ); // phpcs:ignore WordPress.DB

	if ( $ids ) {
		_prime_post_caches( array_map( 'intval', $ids ), true, true );
	}

	foreach ( $ids as $id ) {
		$id = (int) $id;
		$e  = daw_sa_effective( $id );

		$data['titles'][ mb_strtolower( $e['title'] ) ][] = $id;

		$issues = daw_sa_rule_issues( $id );
		if ( $issues ) {
			$data['rows'][ $id ] = array( 't' => $e['title'], 'd' => $e['description'], 'i' => $issues );
		}
	}

	if ( count( $ids ) === $per ) {
		update_option( 'daw_sa_rules_tmp', $data, false );
		printf( '<p>%d sayfa tarandı…</p>', ( $step + 1 ) * $per );
		daw_sa_continue( wp_nonce_url( daw_sa_url( 'kurallar', array( 'run' => 1, 'step' => $step + 1 ) ), 'daw_sa_run' ) );
		return;
	}

	foreach ( $data['titles'] as $title => $same ) {
		if ( '' === $title || count( $same ) < 2 ) {
			continue;
		}
		foreach ( $same as $id ) {
			$post = get_post( $id );
			if ( ! isset( $data['rows'][ $id ] ) ) {
				$e                   = daw_sa_effective( $post );
				$data['rows'][ $id ] = array( 't' => $e['title'], 'd' => $e['description'], 'i' => array() );
			}
			$data['rows'][ $id ]['i'][] = 'aynı başlık ' . count( $same );
		}
	}

	delete_option( 'daw_sa_rules_tmp' );
	update_option( 'daw_sa_rules', array( 'at' => time(), 'rows' => $data['rows'] ), false );

	echo '<p>Bitti.</p>';
	daw_sa_continue( daw_sa_url( 'kurallar' ) );
}

/* =============================================================== dizin === */

function daw_sa_tab_dizin() {
	global $wpdb;

	if ( ! daw_gsc_creds() ) {
		printf( '<p>Önce <a href="%s">Search Console</a> sekmesinden servis hesabı bağlanmalı.</p>', esc_url( daw_sa_url( 'gsc' ) ) );
		return;
	}

	echo '<p class="description" style="max-width:860px">Google\'ın her sayfa için kendi kararı (URL Denetimi): dizinde mi, son taramada sayfayı alabildi mi, hangi canonical\'ı seçti. Saatte 40 sayfa, en eski denetlenen önce; sitemap\'in tamamı yaklaşık bir buçuk günde bir dolaşılır.</p>';
	daw_sa_form_open( 'gsc_inspect', 'dizin' );
	submit_button( 'Şimdi 25 sayfa denetle', 'secondary', 'submit', false );
	echo '</form>';

	if ( (int) get_option( 'daw_gsc_db', 0 ) < 2 ) {
		return;
	}

	$sum = (array) $wpdb->get_results( 'SELECT coverage, COUNT(*) n FROM ' . daw_gsc_inspect_table() . ' GROUP BY coverage ORDER BY n DESC', ARRAY_A ); // phpcs:ignore WordPress.DB
	if ( $sum ) {
		echo '<table class="widefat striped" style="max-width:640px;margin:12px 0"><thead><tr><th>Google\'ın durumu</th><th>Sayfa</th></tr></thead><tbody>';
		foreach ( $sum as $row ) {
			printf( '<tr><td>%s</td><td>%d</td></tr>', esc_html( '' !== $row['coverage'] ? $row['coverage'] : '(boş)' ), (int) $row['n'] );
		}
		echo '</tbody></table>';
	}

	$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . daw_gsc_inspect_table() . " ORDER BY verdict = 'PASS', checked DESC LIMIT 400", ARRAY_A ); // phpcs:ignore WordPress.DB
	echo '<table class="widefat striped"><thead><tr><th>Sayfa</th><th>Sorun</th><th>Son tarama</th><th>Denetlendi</th></tr></thead><tbody>';
	$shown = 0;
	foreach ( $rows as $r ) {
		$problems = daw_gsc_inspection_problems( $r );
		if ( ! $problems ) {
			continue;
		}
		++$shown;
		printf(
			'<tr><td><a href="%s" target="_blank" rel="noopener"><code>%s</code></a></td><td class="daw-sa-bad">%s</td><td>%s</td><td>%s</td></tr>',
			esc_url( home_url( $r['path'] ) ),
			esc_html( $r['path'] ),
			esc_html( implode( ' · ', $problems ) ),
			esc_html( $r['last_crawl'] ? wp_date( 'j M Y', strtotime( $r['last_crawl'] . ' UTC' ) ) : 'hiç' ),
			esc_html( wp_date( 'j M H:i', strtotime( $r['checked'] . ' UTC' ) ) )
		);
	}
	if ( ! $shown ) {
		echo '<tr><td colspan="4">Denetlenen sayfalarda sorun yok' . ( $rows ? '' : ' (henüz denetim yok)' ) . '.</td></tr>';
	}
	echo '</tbody></table>';
}

/* ============================================================ bağlantı === */

function daw_sa_tab_baglanti() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['run'] ) ) {
		check_admin_referer( 'daw_sa_run' );
		global $wpdb;
		$step = absint( $_GET['step'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 === $step ) {
			delete_transient( 'daw_lk_dict' );
		}
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page') ORDER BY ID ASC LIMIT 60 OFFSET %d", $step * 60 ) ); // phpcs:ignore WordPress.DB
		foreach ( $ids as $id ) {
			daw_lk_scan( (int) $id );
		}
		if ( count( $ids ) === 60 ) {
			printf( '<p>%d sayfa tarandı…</p>', ( $step + 1 ) * 60 );
			daw_sa_continue( wp_nonce_url( daw_sa_url( 'baglanti', array( 'run' => 1, 'step' => $step + 1 ) ), 'daw_sa_run' ) );
			return;
		}
		update_option( 'daw_lk_state', array( 'cursor' => 0, 'pass_end' => time() ), false );
		echo '<p>Bitti.</p>';
		daw_sa_continue( daw_sa_url( 'baglanti' ) );
		return;
	}

	$state = (array) get_option( 'daw_lk_state', array() );
	printf(
		'<p><a class="button button-primary" href="%s">Şimdi hepsini tara</a> %s</p>',
		esc_url( wp_nonce_url( daw_sa_url( 'baglanti', array( 'run' => 1, 'step' => 0 ) ), 'daw_sa_run' ) ),
		! empty( $state['pass_end'] ) ? 'Son tam tur: ' . esc_html( wp_date( 'j M Y H:i', (int) $state['pass_end'] ) ) : 'Arka planda saatte 40 sayfa taranıyor.'
	);
	echo '<p class="description" style="max-width:860px">Kırık: sitede olmayan bir adrese giden iç bağlantı. Yönlendirmeli: bir yönlendirmeyle çalışan bağlantı, doğrudan hedefe çevrilmeli. Bağlantısız ad: metinde adı geçen ama bağlanmamış dizi, film, kişi, grup, yemek ya da rehber (kural 3.5).</p>';

	global $wpdb;
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id DESC LIMIT 400", DAW_LK_META ) ); // phpcs:ignore WordPress.DB

	echo '<table class="widefat striped"><thead><tr><th>Sayfa</th><th>Kırık</th><th>Yönlendirmeli</th><th>Bağlantısız adlar</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$r = maybe_unserialize( $row->meta_value );
		if ( ! is_array( $r ) ) {
			continue;
		}
		$broken = implode( '<br>', array_map( static function ( $h ) {
			return '<code>' . esc_html( $h ) . '</code>';
		}, (array) $r['broken'] ) );
		$redir  = implode( '<br>', array_map( static function ( $p ) {
			return '<code>' . esc_html( $p[0] ) . '</code> → <code>' . esc_html( $p[1] ) . '</code>';
		}, (array) $r['redirected'] ) );
		$names  = implode( ', ', array_map( static function ( $p ) {
			return '<a href="' . esc_url( (string) get_permalink( (int) $p[0] ) ) . '" target="_blank" rel="noopener">' . esc_html( $p[1] ) . '</a>';
		}, (array) $r['unlinked'] ) );
		printf(
			'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td><small>%s</small></td></tr>',
			esc_url( (string) get_edit_post_link( (int) $row->post_id ) ),
			esc_html( wp_trim_words( get_the_title( (int) $row->post_id ), 8 ) ),
			$broken, // phpcs:ignore WordPress.Security.EscapeOutput
			$redir, // phpcs:ignore WordPress.Security.EscapeOutput
			$names // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="4">Bulunan bir şey yok ya da tarama henüz bitmedi.</td></tr>';
	}
	echo '</tbody></table>';
}

/* ============================================================ indexnow === */

function daw_sa_tab_indexnow() {
	$key = daw_inx_key();

	printf( '<p>Anahtar: <code>%s</code> · Doğrulama dosyası: <a href="%2$s" target="_blank" rel="noopener">%2$s</a></p>', esc_html( $key ), esc_url( home_url( '/' . $key . '.txt' ) ) );
	echo '<p class="description" style="max-width:860px">Bing, Yandex, Seznam ve Naver bir sayfanın değiştiğini dakikalar içinde öğrenir. Yayınlanan, güncellenen ya da kaldırılan her yazı ve sayfa bir dakika içinde tek liste halinde bildirilir. Google katılmıyor; Google için sitemap\'teki tarih aynı işi görür. ' . ( daw_eng_rm_active() ? 'Rank Math açıkken bildirimleri Rank Math yapar.' : '' ) . '</p>';

	echo '<h3>Elle bildir</h3>';
	daw_sa_form_open( 'inx_submit', 'indexnow' );
	echo '<p><textarea name="urls" rows="5" class="large-text code" placeholder="Her satıra bir adres"></textarea></p>';
	submit_button( 'Gönder', 'secondary', 'submit', false );
	echo '</form>';

	$log = array_reverse( (array) get_option( 'daw_inx_log', array() ) );
	if ( $log ) {
		echo '<h3>Son bildirimler</h3><table class="widefat striped"><tbody>';
		foreach ( $log as $l ) {
			printf( '<tr><td>%s</td><td>%d adres</td><td class="%s">%s</td><td><small>%s</small></td></tr>', esc_html( wp_date( 'j M H:i', (int) $l['at'] ) ), (int) $l['n'], in_array( $l['code'], array( 200, 202 ), true ) ? 'daw-sa-ok' : 'daw-sa-bad', esc_html( (string) $l['code'] ), esc_html( implode( ' ', (array) $l['urls'] ) ) );
		}
		echo '</tbody></table>';
	}
}

/* ================================================================= gsc === */

function daw_sa_tab_gsc() {
	$s     = daw_gsc_settings();
	$creds = daw_gsc_creds();

	echo '<div class="box"><h2 style="margin-top:0">Bağlantı</h2>';
	if ( $creds ) {
		printf( '<p class="daw-sa-ok">Servis hesabı kayıtlı: <code>%s</code></p>', esc_html( $creds['email'] ) );
	} else {
		echo '<ol style="max-width:860px">';
		echo '<li>console.cloud.google.com › yeni proje (ya da var olan) › "APIs & Services" › "Library" › <strong>Google Search Console API</strong> › Enable.</li>';
		echo '<li>"IAM & Admin" › "Service Accounts" › Create service account (rol gerekmez) › hesabın "Keys" sekmesi › Add key › JSON. Bir .json dosyası iner.</li>';
		echo '<li>search.google.com/search-console › bu sitenin mülkü › Ayarlar › Kullanıcılar ve izinler › Kullanıcı ekle › servis hesabının e-postası (…@….iam.gserviceaccount.com), izin: Kısıtlı.</li>';
		echo '<li>.json dosyasının tamamını aşağıya yapıştırıp kaydedin, sonra "Bağlantıyı dene".</li>';
		echo '</ol>';
	}

	daw_sa_form_open( 'gsc_save', 'gsc' );
	echo '<p><label>Mülk<br><input name="property" class="regular-text" value="' . esc_attr( $s['property'] ) . '"></label><br><small>Alan adı mülkü için sc-domain:discoverasianworld.com, URL mülkü için https://discoverasianworld.com/</small></p>';
	echo '<p><label>Servis hesabı JSON' . ( $creds ? ' (değiştirmek için yapıştırın; boş bırakılırsa kayıtlı olan kalır)' : '' ) . '<br><textarea name="json" rows="6" class="large-text code" autocomplete="off" spellcheck="false"></textarea></label></p>';
	submit_button( 'Kaydet', 'primary', 'submit', false );
	echo '</form> ';

	if ( $creds ) {
		daw_sa_form_open( 'gsc_test', 'gsc' );
		submit_button( 'Bağlantıyı dene', 'secondary', 'submit', false );
		echo '</form> ';
		daw_sa_form_open( 'gsc_sync', 'gsc' );
		submit_button( 'Şimdi çek', 'secondary', 'submit', false );
		echo '</form> ';
		daw_sa_form_open( 'gsc_forget', 'gsc' );
		echo '<button class="button-link daw-sa-bad" onclick="return confirm(\'Anahtar silinsin mi?\')">Anahtarı sil</button></form>';
	}
	echo '</div>';

	echo '<div class="box"><h2 style="margin-top:0">Veri</h2>';
	global $wpdb;
	$days  = daw_gsc_has_data() ? (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT day) FROM ' . daw_gsc_table() ) : 0; // phpcs:ignore WordPress.DB
	$first = daw_gsc_has_data() ? (string) $wpdb->get_var( 'SELECT MIN(day) FROM ' . daw_gsc_table() ) : ''; // phpcs:ignore WordPress.DB
	printf( '<p>Saklanan gün: %d%s · Son çekilen gün: %s · Son çekim: %s</p>', $days, '' !== $first ? ' (ilk: ' . esc_html( $first ) . ')' : '', esc_html( '' !== $s['last_day'] ? $s['last_day'] : 'yok' ), esc_html( $s['synced_at'] ? wp_date( 'j M H:i', (int) $s['synced_at'] ) : 'yok' ) );
	if ( '' !== $s['error'] ) {
		echo '<p class="daw-sa-bad">Son hata: ' . esc_html( $s['error'] ) . '</p>';
	}
	echo '<p class="description">Her gün kendiliğinden çekilir. İlk çekim 16 ay geriye gider ve 30 günlük parçalarla iki dakikada bir devam eder. Sonuçlar SEO Envanter ekranında ve her yazının SEO kutusunda görünür.</p>';

	if ( daw_gsc_has_data() ) {
		$top = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT page, SUM(clicks) c, SUM(impressions) i FROM ' . daw_gsc_table() . ' WHERE day >= %s GROUP BY page ORDER BY c DESC LIMIT 25', gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		echo '<h3>Son 28 gün, en çok tıklanan</h3><table class="widefat striped"><thead><tr><th>Sayfa</th><th>Tıklama</th><th>Gösterim</th></tr></thead><tbody>';
		foreach ( $top as $t ) {
			printf( '<tr><td><code>%s</code></td><td>%s</td><td>%s</td></tr>', esc_html( $t['page'] ), esc_html( number_format_i18n( (int) $t['c'] ) ), esc_html( number_format_i18n( (int) $t['i'] ) ) );
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}

/* ========================================================== editor box === */

/**
 * The SEO box on the editor, once Rank Math's own box is gone. Same meta keys,
 * so a value typed here is the value Rank Math would have read.
 */
add_action( 'add_meta_boxes', 'daw_sa_add_box', 20 );
function daw_sa_add_box( $post_type ) {
	if ( daw_eng_rm_active() || ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
		return;
	}

	add_meta_box( 'daw-seo-box', 'SEO', 'daw_sa_box', $post_type, 'normal', 'high' );
}

function daw_sa_box( $post ) {
	wp_nonce_field( 'daw_sa_box', 'daw_sa_box_nonce' );

	$e        = daw_sa_effective( $post );
	$title    = (string) get_post_meta( $post->ID, 'rank_math_title', true );
	$desc     = (string) get_post_meta( $post->ID, 'rank_math_description', true );
	$focus    = (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true );
	$canon    = (string) get_post_meta( $post->ID, 'rank_math_canonical_url', true );
	$robots   = (array) get_post_meta( $post->ID, 'rank_math_robots', true );
	$og_image = (string) get_post_meta( $post->ID, 'rank_math_facebook_image', true );

	echo '<div class="daw-sa-box">';
	printf(
		'<div style="background:#fff;border:1px solid #dcdcde;padding:10px 12px;margin-bottom:10px;max-width:640px"><div style="color:#1a0dab;font-size:18px;line-height:1.3" id="daw-sa-prev-t">%s</div><div style="color:#006621;font-size:13px">%s</div><div style="color:#545454;font-size:13px" id="daw-sa-prev-d">%s</div></div>',
		esc_html( $e['title'] ),
		esc_html( (string) get_permalink( $post ) ),
		esc_html( $e['description'] )
	);

	printf(
		'<p><label><strong>SEO başlığı</strong> <span id="daw-sa-tc"></span><br><input type="text" name="daw_sa[title]" id="daw-sa-t" value="%s" class="large-text" placeholder="%s"></label><br><small>Boş bırakılırsa: %s</small></p>',
		esc_attr( $title ),
		esc_attr( $e['manual_title'] ? '' : $e['title'] ),
		$e['manual_title'] ? 'şablon' : 'yukarıdaki önizlemedeki üretilen başlık'
	);
	printf(
		'<p><label><strong>Açıklama</strong> <span id="daw-sa-dc"></span><br><textarea name="daw_sa[description]" id="daw-sa-d" rows="3" class="large-text" placeholder="%s">%s</textarea></label></p>',
		esc_attr( $e['manual_desc'] ? '' : $e['description'] ),
		esc_textarea( $desc )
	);
	printf( '<p><label>Odak kelime<br><input type="text" name="daw_sa[focus]" value="%s" class="regular-text"></label> ', esc_attr( $focus ) );
	printf( '<label>Canonical (boşsa sayfanın kendisi)<br><input type="url" name="daw_sa[canonical]" value="%s" class="regular-text"></label></p>', esc_attr( $canon ) );
	printf( '<p><label>Paylaşım görseli adresi (boşsa öne çıkan görsel ya da TMDB)<br><input type="url" name="daw_sa[og_image]" value="%s" class="large-text"></label></p>', esc_attr( $og_image ) );
	printf(
		'<p><label><input type="checkbox" name="daw_sa[noindex]" value="1"%s> noindex (arama motoruna kapalı, sitemap dışı)</label> &nbsp; <label><input type="checkbox" name="daw_sa[nofollow]" value="1"%s> nofollow</label></p>',
		checked( in_array( 'noindex', $robots, true ), true, false ),
		checked( in_array( 'nofollow', $robots, true ), true, false )
	);

	$issues = daw_sa_rule_issues( $post->ID );
	if ( 'publish' === $post->post_status ) {
		echo '<p>' . ( $issues ? '<span class="daw-sa-bad" style="color:#b32d2e">Kural: ' . esc_html( implode( ' · ', $issues ) ) . '</span>' : '<span style="color:#1a7f37">Başlık ve açıklama kurallara uygun.</span>' ) . '</p>';
	}

	if ( function_exists( 'daw_gsc_for_path' ) ) {
		$path = (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH );
		$gsc  = daw_gsc_for_path( $path, 28 );
		if ( $gsc ) {
			printf( '<p><strong>Search Console, 28 gün:</strong> %s tıklama · %s gösterim · ortalama sıra %s</p>', esc_html( number_format_i18n( $gsc['clicks'] ) ), esc_html( number_format_i18n( $gsc['impressions'] ) ), esc_html( null !== $gsc['position'] ? (string) $gsc['position'] : '—' ) );
			$queries = daw_gsc_queries_for_path( $path, 10 );
			if ( $queries ) {
				echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>Arama</th><th>Tık</th><th>Gösterim</th><th>Sıra</th></tr></thead><tbody>';
				foreach ( $queries as $q ) {
					printf( '<tr><td>%s</td><td>%d</td><td>%d</td><td>%s</td></tr>', esc_html( $q['query'] ), (int) $q['clicks'], (int) $q['impressions'], esc_html( (string) round( (float) $q['position'], 1 ) ) );
				}
				echo '</tbody></table>';
			}
		}
	}

	if ( function_exists( 'daw_gsc_inspection_for_path' ) ) {
		$insp = daw_gsc_inspection_for_path( (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH ) );
		if ( $insp ) {
			$problems = daw_gsc_inspection_problems( $insp );
			printf(
				'<p><strong>Google dizini:</strong> %s <small>(%s)</small></p>',
				$problems ? '<span style="color:#b32d2e">' . esc_html( implode( ' · ', $problems ) ) . '</span>' : '<span style="color:#1a7f37">' . esc_html( $insp['coverage'] ) . '</span>', // phpcs:ignore WordPress.Security.EscapeOutput
				esc_html( wp_date( 'j M', strtotime( $insp['checked'] . ' UTC' ) ) )
			);
		}
	}

	$links = get_post_meta( $post->ID, DAW_LK_META, true );
	if ( is_array( $links ) ) {
		echo '<p><strong>Bağlantı denetimi:</strong> ';
		$bits = array();
		if ( $links['broken'] ) {
			$bits[] = '<span style="color:#b32d2e">' . count( $links['broken'] ) . ' kırık</span> (' . esc_html( implode( ', ', $links['broken'] ) ) . ')';
		}
		if ( $links['redirected'] ) {
			$bits[] = count( $links['redirected'] ) . ' yönlendirmeli';
		}
		if ( $links['unlinked'] ) {
			$bits[] = 'bağlanmamış adlar: ' . esc_html( implode( ', ', wp_list_pluck( array_map( static function ( $p ) {
				return array( 'n' => $p[1] );
			}, $links['unlinked'] ), 'n' ) ) );
		}
		echo implode( ' · ', $bits ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	echo '</div>';
	?>
	<script>
	( function () {
		function count( id, out, lo, hi ) {
			var el = document.getElementById( id ), o = document.getElementById( out );
			if ( ! el || ! o ) { return; }
			function run() {
				var v = el.value || el.getAttribute( 'placeholder' ) || '', n = v.length, bad = n > hi || ( lo && n && n < lo ), dash = v.indexOf( '—' ) !== -1;
				o.textContent = n + ( lo ? ' / ' + lo + '–' + hi : ' / ' + hi ) + ( dash ? ' · uzun tire!' : '' );
				o.style.color = ( bad || dash ) ? '#b32d2e' : '#1a7f37';
				var p = document.getElementById( id === 'daw-sa-t' ? 'daw-sa-prev-t' : 'daw-sa-prev-d' );
				if ( p && el.value ) { p.textContent = el.value; }
			}
			el.addEventListener( 'input', run );
			run();
		}
		count( 'daw-sa-t', 'daw-sa-tc', 0, 60 );
		count( 'daw-sa-d', 'daw-sa-dc', 150, 160 );
	} )();
	</script>
	<?php
}

add_action( 'save_post', 'daw_sa_save_box', 10, 2 );
function daw_sa_save_box( $post_id, $post ) {
	if ( ! isset( $_POST['daw_sa_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['daw_sa_box_nonce'] ) ), 'daw_sa_box' ) ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$in = isset( $_POST['daw_sa'] ) ? (array) wp_unslash( $_POST['daw_sa'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	$fields = array(
		'rank_math_title'          => sanitize_text_field( $in['title'] ?? '' ),
		'rank_math_description'    => sanitize_textarea_field( $in['description'] ?? '' ),
		'rank_math_focus_keyword'  => sanitize_text_field( $in['focus'] ?? '' ),
		'rank_math_canonical_url'  => esc_url_raw( $in['canonical'] ?? '' ),
		'rank_math_facebook_image' => esc_url_raw( $in['og_image'] ?? '' ),
	);

	foreach ( $fields as $key => $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}

	// A typed share picture replaces the one Rank Math stored by id, which
	// the engine would otherwise read first.
	if ( '' !== $fields['rank_math_facebook_image'] ) {
		delete_post_meta( $post_id, 'rank_math_facebook_image_id' );
	}

	// Robots are written only when they say something; an empty field means
	// "the site default", the same as Rank Math with nothing chosen.
	$noindex  = ! empty( $in['noindex'] );
	$nofollow = ! empty( $in['nofollow'] );
	$current  = (array) get_post_meta( $post_id, 'rank_math_robots', true );
	$was_no   = in_array( 'noindex', $current, true ) || in_array( 'nofollow', $current, true );

	if ( $noindex || $nofollow ) {
		update_post_meta( $post_id, 'rank_math_robots', array( $noindex ? 'noindex' : 'index', $nofollow ? 'nofollow' : 'follow' ) );
	} elseif ( $was_no ) {
		delete_post_meta( $post_id, 'rank_math_robots' );
	}
}

/* =========================================================== term box === */

add_action( 'category_edit_form_fields', 'daw_sa_term_fields', 20 );
add_action( 'post_tag_edit_form_fields', 'daw_sa_term_fields', 20 );
function daw_sa_term_fields( $term ) {
	if ( daw_eng_rm_active() ) {
		return;
	}

	wp_nonce_field( 'daw_sa_term', 'daw_sa_term_nonce' );
	$robots = (array) get_term_meta( $term->term_id, 'rank_math_robots', true );

	printf( '<tr class="form-field"><th><label for="daw-sa-tt">SEO başlığı</label></th><td><input id="daw-sa-tt" name="daw_sa_term[title]" value="%s"><p class="description">Boşsa: "%%term%% %%sep%% %%sitename%%" şablonu.</p></td></tr>', esc_attr( (string) get_term_meta( $term->term_id, 'rank_math_title', true ) ) );
	printf( '<tr class="form-field"><th><label for="daw-sa-td">SEO açıklaması</label></th><td><textarea id="daw-sa-td" name="daw_sa_term[description]" rows="3">%s</textarea></td></tr>', esc_textarea( (string) get_term_meta( $term->term_id, 'rank_math_description', true ) ) );
	printf( '<tr class="form-field"><th>Robots</th><td><label><input type="checkbox" name="daw_sa_term[noindex]" value="1"%s style="width:auto"> noindex</label></td></tr>', checked( in_array( 'noindex', $robots, true ), true, false ) );
}

add_action( 'edited_term', 'daw_sa_term_save', 10, 3 );
function daw_sa_term_save( $term_id, $tt_id, $taxonomy ) {
	if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) || ! isset( $_POST['daw_sa_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['daw_sa_term_nonce'] ) ), 'daw_sa_term' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}

	$in = isset( $_POST['daw_sa_term'] ) ? (array) wp_unslash( $_POST['daw_sa_term'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	foreach ( array( 'rank_math_title' => sanitize_text_field( $in['title'] ?? '' ), 'rank_math_description' => sanitize_textarea_field( $in['description'] ?? '' ) ) as $key => $value ) {
		if ( '' === $value ) {
			delete_term_meta( $term_id, $key );
		} else {
			update_term_meta( $term_id, $key, $value );
		}
	}

	if ( ! empty( $in['noindex'] ) ) {
		update_term_meta( $term_id, 'rank_math_robots', array( 'noindex', 'follow' ) );
	} else {
		$cur = (array) get_term_meta( $term_id, 'rank_math_robots', true );
		if ( in_array( 'noindex', $cur, true ) ) {
			delete_term_meta( $term_id, 'rank_math_robots' );
		}
	}
}
