<?php
/**
 * Discover Asian World — Hız & Sağlık (speed + health audit screen).
 *
 * WHAT THIS IS, AND WHAT IT IS NOT
 * ---------------------------------
 * inc/speed.php (already in this plugin) PREVENTS waste — it dequeues assets
 * a page does not use. This file MEASURES what is left, on demand, from an
 * admin screen: how long a real page takes warm and cold, how heavy it is,
 * which assets are heaviest, how much of daw.css a sample of real pages
 * never touches, and what WordPress's own Site Health checks say. It never
 * runs on a visitor's request — only when the owner clicks "Measure now", or
 * once a day on cron so the screen has something to show without a click.
 *
 * WHY ONE SAMPLE PER TEMPLATE, NOT EVERY PAGE
 * ---------------------------------------------
 * 926+ records share a handful of templates (single-title, single-person,
 * single-group, single-news, front-page, search, browse). The template is
 * what decides the weight, not the individual record, so one real URL per
 * template says as much as a thousand would and costs a thousand times less.
 *
 * WHY wp_remote_get ON OUR OWN DOMAIN
 * -------------------------------------
 * This is the only way to see what a cold, logged-out, cache-eligible
 * request actually gets: PHP functions can read the template's PHP, but they
 * cannot see what LiteSpeed's page cache, image CDN host or minifier did to
 * the response. We measure the finished HTTP response, the same thing a
 * visitor's or Google's crawler's browser gets.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_SPEED_LAST   = 'daw_speed_last'; // Array of the last run's results. Not autoloaded.
const DAW_SPEED_KEY_PSI = 'daw_key_pagespeed'; // Optional: PageSpeed Insights API key.

/**
 * One representative, published, live URL per template.
 *
 * Picked freshly each run rather than hard-coded, so a sample never points
 * at a record that got unpublished or deleted since the last measurement.
 */
function daw_speed_samples() {
	$samples = array();

	$by_type = array(
		'front'  => null, // handled separately below
		'drama'  => array( 'tax' => 'dw_type', 'term' => 10874 ),
		'film'   => array( 'tax' => 'dw_type', 'term' => 10875 ),
		'person' => array( 'tax' => 'dw_type', 'term' => 10876 ),
		'group'  => array( 'tax' => 'dw_type', 'term' => 10877 ),
		'news'   => array( 'tax' => 'dw_type', 'term' => 10878 ),
	);

	foreach ( $by_type as $label => $q ) {
		if ( null === $q ) {
			continue;
		}
		$ids = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'             => 'publish',
				'posts_per_page'          => 1,
				'orderby'                 => 'modified',
				'order'                   => 'DESC',
				'fields'                  => 'ids',
				'no_found_rows'           => true,
				'update_post_meta_cache'  => false,
				'tax_query'               => array(
					array( 'taxonomy' => $q['tax'], 'field' => 'term_id', 'terms' => $q['term'] ),
				),
			)
		);
		if ( $ids ) {
			$samples[ $label ] = get_permalink( $ids[0] );
		}
	}

	$samples['front']  = home_url( '/' );
	$samples['search']  = home_url( '/?s=love' );
	if ( function_exists( 'daw_browse_sample_url' ) ) {
		$u = daw_browse_sample_url();
		if ( $u ) {
			$samples['browse'] = $u;
		}
	}

	// THE LISTS, NOT ONLY THE RECORDS
	// -------------------------------
	// Readers move through the site by the browse pages: Dramas, Films,
	// People, the country sections and their filters. Those were never in
	// this sample, so "the site is slow when I tap Dramas" had no number to
	// answer it. The global drama list, the Korean drama section and a kept
	// one-genre state are measured with the records.
	if ( function_exists( 'daw_browse_page_url_for_type' ) ) {
		$dramas = daw_browse_page_url_for_type( 'drama' );
		if ( $dramas ) {
			$samples['list']        = $dramas;
			$samples['list_filter'] = add_query_arg( 'genre', 'romance', $dramas );
		}
	}
	if ( function_exists( 'daw_browse_country_page' ) ) {
		$section = daw_browse_country_page( 'south-korea', 'drama' );
		if ( $section ) {
			$samples['section'] = $section;
		}
	}

	/**
	 * Filters the set of URLs the speed audit measures.
	 *
	 * @param array $samples label => url
	 */
	return apply_filters( 'daw_speed_samples', $samples );
}

/** One GET, timed, with a cache-bust query arg when $cold is true. */
function daw_speed_fetch( $url, $cold = false ) {
	$fetch_url = $cold ? add_query_arg( 'daw_cb', wp_generate_password( 8, false ), $url ) : $url;

	$t0  = microtime( true );
	$res = wp_remote_get(
		$fetch_url,
		array(
			'timeout'    => 25,
			'user-agent' => 'DAW-Speed-Audit/1.0 (+https://discoverasianworld.com)',
			'sslverify'  => true,
		)
	);
	$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );

	if ( is_wp_error( $res ) ) {
		return array( 'ok' => false, 'error' => $res->get_error_message(), 'ms' => $ms );
	}

	$body = (string) wp_remote_retrieve_body( $res );
	$code = (int) wp_remote_retrieve_response_code( $res );

	return array(
		'ok'          => 200 === $code,
		'code'        => $code,
		'ms'          => $ms,
		'bytes_html'  => strlen( $body ),
		'cache_hdr'   => wp_remote_retrieve_header( $res, 'x-litespeed-cache' ),
		'body'        => $body,
	);
}

/** Every <link rel=stylesheet>/<script src>/<img src> URL in an HTML body. */
function daw_speed_assets( $html ) {
	$out = array( 'css' => array(), 'js' => array(), 'img' => array() );

	if ( preg_match_all( '/<link[^>]+rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\']/i', $html, $m ) ) {
		$out['css'] = $m[1];
	}
	if ( preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/i', $html, $m ) ) {
		$out['js'] = $m[1];
	}
	if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m ) ) {
		$out['img'] = $m[1];
	}

	return $out;
}

/** HEAD a handful of assets for their byte size. Capped so one run stays fast. */
function daw_speed_weigh( array $urls, $cap = 8 ) {
	$weighed = array();
	$urls    = array_slice( array_unique( $urls ), 0, $cap );

	foreach ( $urls as $url ) {
		if ( 0 !== strpos( $url, 'http' ) ) {
			continue; // data: URI or relative path we didn't resolve; skip rather than guess.
		}
		$res = wp_remote_head( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $res ) ) {
			continue;
		}
		$len = (int) wp_remote_retrieve_header( $res, 'content-length' );
		if ( $len > 0 ) {
			$weighed[ $url ] = $len;
		}
	}

	arsort( $weighed );
	return $weighed;
}

/** What fraction of daw.css's own class selectors show up in the sampled pages at all. */
function daw_speed_dead_css( array $bodies ) {
	$css_path = get_stylesheet_directory() . '/assets/daw.css';
	if ( ! file_exists( $css_path ) ) {
		return null;
	}
	$css = file_get_contents( $css_path );
	if ( false === $css ) {
		return null;
	}

	preg_match_all( '/\.([a-zA-Z_][a-zA-Z0-9_-]*)/', $css, $m );
	$classes = array_unique( $m[1] );
	$classes = array_diff( $classes, array( 'daw-ad-unit' ) ); // editor-only class, never in a visitor's HTML by design.

	$haystack = implode( ' ', $bodies );
	$unused   = array();
	foreach ( $classes as $class ) {
		if ( false === strpos( $haystack, $class ) ) {
			$unused[] = $class;
		}
	}

	return array(
		'total'  => count( $classes ),
		'unused' => count( $unused ),
		'sample' => array_slice( $unused, 0, 40 ),
	);
}

/**
 * Which third-party hosts the sampled pages actually load a <script src> from,
 * and whether Analytics is wired twice (once through Tag Manager, once as its
 * own direct gtag.js) — a real, measured duplicate load, not a guess.
 *
 * Found live on discoverasianworld.com, 1 Oct 2026 (Site Kit has Analytics AND
 * Tag Manager both connected): googletagmanager.com x2, a direct gtag.js load,
 * Microsoft Clarity, Yandex Metrica and Google's consent-message script. The
 * ad network host (googlesyndication.com) is expected and excluded from the
 * warning — that is the ad system this plugin manages, not an unplanned extra.
 */
function daw_speed_third_party( array $bodies ) {
	$own_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$known    = array(
		'googletagmanager.com'     => 'Google Tag Manager',
		'google-analytics.com'     => 'Google Analytics',
		'/gtag/js'                 => 'Google Analytics (gtag.js, doğrudan)',
		'googlesyndication.com'    => 'Google AdSense (reklam yuvaları — beklenen)',
		'doubleclick.net'          => 'Google Ads / DoubleClick',
		'clarity.ms'               => 'Microsoft Clarity',
		'mc.yandex.'               => 'Yandex Metrica',
		'connect.facebook.net'     => 'Meta Pixel',
		'hotjar.com'               => 'Hotjar',
		'fundingchoicesmessages.google.com' => 'Google Funding Choices (rıza mesajı)',
		'accounts.google.com'      => 'Google Sign-In',
	);

	$found = array(); // host => count
	foreach ( $bodies as $html ) {
		if ( preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $src ) {
				$h = (string) wp_parse_url( $src, PHP_URL_HOST );
				if ( '' === $h || $h === $own_host ) {
					continue;
				}
				$found[ $h ] = ( $found[ $h ] ?? 0 ) + 1;
			}
		}
	}

	$rows = array();
	$has_gtm = false;
	$has_ga_direct = false;
	foreach ( $found as $h => $n ) {
		$label = $h;
		foreach ( $known as $frag => $name ) {
			if ( false !== strpos( $h, $frag ) ) {
				$label = $name;
				break;
			}
		}
		if ( false !== strpos( $h, 'googletagmanager.com' ) ) {
			$has_gtm = true;
		}
		if ( false !== strpos( $h, 'google-analytics.com' ) ) {
			$has_ga_direct = true;
		}
		$rows[] = array( 'host' => $h, 'label' => $label, 'count' => $n );
	}
	usort( $rows, static function ( $a, $b ) { return $b['count'] <=> $a['count']; } );

	return array(
		'rows'                 => $rows,
		'redundant_analytics'  => $has_gtm && $has_ga_direct,
	);
}

/** WordPress's own Site Health "direct" tests — the ones that don't need an AJAX round trip. */
function daw_speed_site_health() {
	// THE DAILY RUN DIED HERE
	// -----------------------
	// On cron there is no admin screen, so the helpers the core tests call
	// (get_core_updates(), get_plugin_updates(), the update and file APIs)
	// were never loaded and the whole measurement ended in a fatal error
	// before anything was saved: the screen kept showing a run from before
	// 1.47.26. They are loaded here, and a test that still throws is skipped
	// rather than taking the run down with it.
	foreach ( array( 'update.php', 'plugin.php', 'theme.php', 'file.php', 'misc.php', 'class-wp-site-health.php' ) as $inc ) {
		if ( is_readable( ABSPATH . 'wp-admin/includes/' . $inc ) ) {
			require_once ABSPATH . 'wp-admin/includes/' . $inc;
		}
	}
	if ( ! class_exists( 'WP_Site_Health' ) ) {
		return array();
	}

	$health = WP_Site_Health::get_instance();
	$tests  = WP_Site_Health::get_tests();
	$out    = array();

	foreach ( (array) ( $tests['direct'] ?? array() ) as $name => $test ) {
		if ( ! isset( $test['test'] ) ) {
			continue;
		}
		// Two tests only mean something with a person at the screen: from a
		// background run the REST check has no signed-in user (it reported
		// "unexpected result") and the event running this very measurement
		// counts as "late". The admin's own Site Health screen still runs both.
		if ( wp_doing_cron() && in_array( (string) ( is_string( $test['test'] ) ? $test['test'] : $name ), array( 'rest_availability', 'scheduled_events' ), true ) ) {
			continue;
		}
		$fn = is_string( $test['test'] ) ? array( $health, 'get_test_' . str_replace( '-', '_', $test['test'] ) ) : $test['test'];
		if ( ! is_callable( $fn ) ) {
			continue;
		}
		try {
			$result = call_user_func( $fn );
		} catch ( \Throwable $e ) {
			continue;
		}
		if ( ! is_array( $result ) ) {
			continue;
		}
		$out[] = array(
			'label'  => (string) ( $result['label'] ?? $test['label'] ?? '' ),
			'status' => (string) ( $result['status'] ?? '' ),
		);
	}

	return $out;
}

/** PageSpeed Insights, only when a key is saved — otherwise the screen just omits the card. */
function daw_speed_psi( $url ) {
	$key = trim( (string) get_option( DAW_SPEED_KEY_PSI, '' ) );
	if ( '' === $key ) {
		return null;
	}

	$out = array();
	foreach ( array( 'mobile', 'desktop' ) as $strategy ) {
		$api = add_query_arg(
			array(
				'url'      => rawurlencode( $url ),
				'strategy' => $strategy,
				'category' => 'performance',
				'key'      => $key,
			),
			'https://www.googleapis.com/pagespeedonline/v5/runPagespeed'
		);
		$res = wp_remote_get( $api, array( 'timeout' => 40 ) );
		if ( is_wp_error( $res ) ) {
			$out[ $strategy ] = array( 'error' => $res->get_error_message() );
			continue;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$score = $body['lighthouseResult']['categories']['performance']['score'] ?? null;
		$out[ $strategy ] = array(
			'score' => null !== $score ? (int) round( $score * 100 ) : null,
			'error' => isset( $body['error']['message'] ) ? (string) $body['error']['message'] : '',
		);
	}

	return $out;
}

/** The full measurement run. Cached in an option; never runs mid-request for a visitor. */
function daw_speed_run() {
	// A run fetches every sample twice and asks PageSpeed for two reports,
	// which is longer than the request that starts it may live: started
	// from the MCP or a slow cron hit, it was cut off before it saved and
	// the screen kept the previous run. It finishes whatever the caller does.
	ignore_user_abort( true );
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	$prev     = (array) get_option( DAW_SPEED_LAST, array() );
	$prev_psi = $prev['psi'] ?? null;

	$samples = daw_speed_samples();

	// The lists first: they are what a reader taps through, and if the host
	// cuts the run short it is the records at the end that go unmeasured.
	$first   = array_intersect_key( $samples, array_flip( array( 'list', 'list_filter', 'section' ) ) );
	$samples = $first + $samples;

	$rows    = array();
	$bodies  = array();
	$all_assets = array( 'css' => array(), 'js' => array(), 'img' => array() );

	foreach ( $samples as $label => $url ) {
		// One fetch first, unmeasured: the sampled records are the ones
		// saved last, so their cached copy was just purged and the "warm"
		// figure was really a rebuild. A reader arrives after the warmer.
		daw_speed_fetch( $url, false );
		$warm = daw_speed_fetch( $url, false );
		$cold = daw_speed_fetch( $url, true );

		if ( ! empty( $warm['body'] ) ) {
			$bodies[]  = $warm['body'];
			$assets    = daw_speed_assets( $warm['body'] );
			foreach ( $all_assets as $k => $v ) {
				$all_assets[ $k ] = array_merge( $v, $assets[ $k ] ?? array() );
			}
		}

		$rows[ $label ] = array(
			'url'           => $url,
			'warm_ms'       => $warm['ms'] ?? null,
			'cold_ms'       => $cold['ms'] ?? null,
			'warm_ok'       => $warm['ok'] ?? false,
			'html_kb'       => isset( $warm['bytes_html'] ) ? round( $warm['bytes_html'] / 1024, 1 ) : null,
			'cache_header'  => $warm['cache_hdr'] ?? '',
			'css_count'     => isset( $warm['body'] ) ? count( daw_speed_assets( $warm['body'] )['css'] ) : null,
			'js_count'      => isset( $warm['body'] ) ? count( daw_speed_assets( $warm['body'] )['js'] ) : null,
			'img_count'     => isset( $warm['body'] ) ? count( daw_speed_assets( $warm['body'] )['img'] ) : null,
		);

		unset( $warm['body'] ); // Don't hold every sampled page's full HTML in the stored option.

		// SAVED AS IT GOES
		// ----------------
		// The host's proxy ends a request at 60 seconds (nginx 504) and
		// LiteSpeed stops PHP with it, whatever ignore_user_abort says. A
		// whole run is ten pages fetched twice plus two PageSpeed reports,
		// which is longer than that, so a run that saved only at the end
		// saved nothing at all and the screen kept showing an older run.
		// Each page is written the moment it is measured; the PageSpeed
		// score carried from the last run until a new one arrives.
		update_option(
			DAW_SPEED_LAST,
			array(
				'when'      => time(),
				'partial'   => true,
				'templates' => $rows,
				'psi'       => $prev_psi,
			) + $prev,
			false
		);
	}

	$heaviest = daw_speed_weigh( array_merge( $all_assets['css'], $all_assets['js'] ), 15 );

	$result = array(
		'when'        => time(),
		'partial'     => false,
		'templates'   => $rows,
		'heaviest'    => $heaviest,
		'dead_css'    => daw_speed_dead_css( $bodies ),
		'third_party' => daw_speed_third_party( $bodies ),
		'health'      => daw_speed_site_health(),
		'psi'         => $prev_psi,
	);

	update_option( DAW_SPEED_LAST, $result, false );

	// PageSpeed last, in its own save: two remote reports can take most of a
	// minute on their own, and everything measured above is already kept.
	$psi = daw_speed_psi( home_url( '/' ) );
	if ( $psi ) {
		$result['psi'] = $psi;
		update_option( DAW_SPEED_LAST, $result, false );
	}

	return $result;
}

add_action( 'daw_speed_daily', 'daw_speed_run' );
add_action( 'init', 'daw_speed_schedule' );
function daw_speed_schedule() {
	if ( ! wp_next_scheduled( 'daw_speed_daily' ) ) {
		wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, 'daily', 'daw_speed_daily' );
	}
}

/* =============================================================== screen === */

function daw_speed_audit_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['daw_speed_run'] ) && check_admin_referer( 'daw_speed_run' ) ) {
		daw_speed_run();
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Ölçüm yenilendi.', 'daw' ) . '</p></div>';
	}

	if ( isset( $_POST['daw_speed_psi_key'] ) && check_admin_referer( 'daw_speed_psi_key' ) ) {
		update_option( DAW_SPEED_KEY_PSI, sanitize_text_field( wp_unslash( $_POST['daw_speed_psi_key'] ) ) );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'PageSpeed Insights anahtarı kaydedildi.', 'daw' ) . '</p></div>';
	}

	$data = get_option( DAW_SPEED_LAST, null );

	echo '<div class="wrap"><h1>' . esc_html__( 'Hız & Sağlık', 'daw' ) . '</h1>';

	echo '<form method="post" style="margin:16px 0">';
	wp_nonce_field( 'daw_speed_run' );
	echo '<button class="button button-primary" name="daw_speed_run" value="1">' . esc_html__( 'Şimdi ölç', 'daw' ) . '</button>';
	if ( $data ) {
		echo ' <span style="color:#666">' . esc_html( sprintf( __( 'son ölçüm: %s', 'daw' ), human_time_diff( $data['when'] ) . ' ' . __( 'önce', 'daw' ) ) ) . '</span>';
	}
	echo '</form>';

	if ( ! $data ) {
		echo '<p>' . esc_html__( 'Henüz ölçüm yok. "Şimdi ölç" ile başlat.', 'daw' ) . '</p></div>';
		return;
	}

	echo '<h2>' . esc_html__( 'Şablon bazında', 'daw' ) . '</h2>';
	echo '<table class="widefat striped"><thead><tr>';
	foreach ( array( 'Şablon', 'URL', 'Sıcak', 'Soğuk', 'HTML', 'CSS', 'JS', 'Görsel', 'LiteSpeed' ) as $h ) {
		echo '<th>' . esc_html( $h ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $data['templates'] as $label => $row ) {
		printf(
			'<tr><td><strong>%s</strong></td><td><a href="%s" target="_blank" rel="noopener">%s</a></td><td>%s ms</td><td>%s ms</td><td>%s KB</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $label ),
			esc_url( $row['url'] ),
			esc_html( wp_parse_url( $row['url'], PHP_URL_PATH ) ?: '/' ),
			esc_html( $row['warm_ms'] ?? '—' ),
			esc_html( $row['cold_ms'] ?? '—' ),
			esc_html( $row['html_kb'] ?? '—' ),
			esc_html( $row['css_count'] ?? '—' ),
			esc_html( $row['js_count'] ?? '—' ),
			esc_html( $row['img_count'] ?? '—' ),
			esc_html( $row['cache_header'] ?: '—' )
		);
	}
	echo '</tbody></table>';

	if ( $data['dead_css'] ) {
		$dc = $data['dead_css'];
		printf(
			'<h2>%s</h2><p>%s</p>',
			esc_html__( 'daw.css — kullanılmayan sınıf tahmini', 'daw' ),
			esc_html(
				sprintf(
					/* translators: 1: unused count, 2: total count, 3: percent */
					__( '%1$d / %2$d sınıf örneklenen sayfalarda hiç geçmiyor (%%%3$s).', 'daw' ),
					$dc['unused'],
					$dc['total'],
					$dc['total'] ? round( $dc['unused'] / $dc['total'] * 100 ) : 0
				)
			)
		);
		if ( $dc['sample'] ) {
			echo '<p style="font-family:monospace;font-size:12px;color:#666">' . esc_html( implode( ', ', $dc['sample'] ) ) . ( $dc['unused'] > count( $dc['sample'] ) ? '…' : '' ) . '</p>';
		}
		echo '<p style="color:#888;font-size:12px">' . esc_html__( 'Not: bu bir tahmindir (örneklenen sayfalarda geçmiyor demek, hiçbir sayfada geçmiyor demek değildir — JS ile eklenen sınıflar ve diğer şablonlardaki kullanım bu listeye yanlışlıkla girebilir). Silmeden önce sınıf adı sitede aranmalı.', 'daw' ) . '</p>';
	}

	if ( ! empty( $data['third_party'] ) && $data['third_party']['rows'] ) {
		$tp = $data['third_party'];
		echo '<h2>' . esc_html__( 'Üçüncü taraf betikler — yavaşlatıcılar', 'daw' ) . '</h2>';
		echo '<p>' . esc_html__( 'Örneklenen sayfaların gerçekten yüklediği, kendi alan adımız dışındaki her betik — tahmin değil, ölçüm.', 'daw' ) . '</p>';
		if ( $tp['redundant_analytics'] ) {
			echo '<div class="notice notice-warning" style="margin:0 0 12px"><p><strong>' . esc_html__( 'Çift ölçüm bulundu:', 'daw' ) . '</strong> ' . esc_html__( 'Google Tag Manager VE doğrudan Google Analytics (gtag.js) aynı anda yükleniyor. Site Kit\'te hem "Analytics" hem "Tag Manager" bağlı olduğu için GA4 muhtemelen iki kere tetikleniyor. DAW › Site Kit ayarlarında ikisinden birini (tercihen doğrudan Analytics\'i, GA4\'ü Tag Manager üstünden tutarak) kapatmak sayfa başına bir betik yükünü kaldırır.', 'daw' ) . '</p></div>';
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Kaynak', 'daw' ) . '</th><th>' . esc_html__( 'Ne olduğu', 'daw' ) . '</th><th>' . esc_html__( 'Kaç sayfada görüldü', 'daw' ) . '</th></tr></thead><tbody>';
		foreach ( $tp['rows'] as $r ) {
			printf(
				'<tr><td style="font-family:monospace;font-size:12px">%s</td><td>%s</td><td>%d</td></tr>',
				esc_html( $r['host'] ),
				esc_html( $r['label'] ),
				(int) $r['count']
			);
		}
		echo '</tbody></table>';
		echo '<p style="color:#888;font-size:12px;max-width:760px">' . esc_html__( 'Google AdSense (reklam yuvaları) ve Funding Choices (rıza mesajı) beklenen ve gerekli; sorunlu olan aynı ölçümün iki kere yüklenmesi ya da sitede kararı verilmemiş bir izleyicinin (ör. Clarity, Yandex Metrica) durmasıdır. Bunlar Tag Manager kapsayıcısının kendi arayüzünde (tagmanager.google.com) eklenmiş olabilir — WordPress tarafından görülemez, kapsayıcıya oradan bakılması gerekir.', 'daw' ) . '</p>';
	}

	if ( $data['heaviest'] ) {
		echo '<h2>' . esc_html__( 'En ağır varlıklar', 'daw' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( $data['heaviest'] as $url => $bytes ) {
			printf(
				'<tr><td>%s</td><td>%s KB</td></tr>',
				esc_html( wp_parse_url( $url, PHP_URL_PATH ) ?: $url ),
				esc_html( round( $bytes / 1024, 1 ) )
			);
		}
		echo '</tbody></table>';
	}

	if ( $data['health'] ) {
		echo '<h2>' . esc_html__( 'WordPress Site Sağlığı', 'daw' ) . '</h2><ul>';
		foreach ( $data['health'] as $t ) {
			$color = 'good' === $t['status'] ? '#1a7f37' : ( 'critical' === $t['status'] ? '#c00' : '#a86a00' );
			printf( '<li style="color:%s">● %s — %s</li>', esc_attr( $color ), esc_html( $t['label'] ), esc_html( $t['status'] ) );
		}
		echo '</ul>';
	}

	echo '<h2>' . esc_html__( 'PageSpeed Insights', 'daw' ) . '</h2>';
	if ( $data['psi'] ) {
		foreach ( $data['psi'] as $strategy => $r ) {
			if ( ! empty( $r['error'] ) ) {
				printf( '<p>%s: <em>%s</em></p>', esc_html( $strategy ), esc_html( $r['error'] ) );
			} else {
				printf( '<p>%s: <strong>%s</strong> / 100</p>', esc_html( $strategy ), esc_html( $r['score'] ?? '—' ) );
			}
		}
	} else {
		echo '<form method="post"><p>' . esc_html__( 'Opsiyonel: bir PageSpeed Insights API anahtarı gir, ana sayfa mobil+masaüstü puanı buraya eklensin.', 'daw' ) . '</p>';
		wp_nonce_field( 'daw_speed_psi_key' );
		echo '<input type="text" name="daw_speed_psi_key" class="regular-text" placeholder="AIza…"> ';
		echo '<button class="button">' . esc_html__( 'Kaydet', 'daw' ) . '</button></form>';
	}

	echo '</div>';
}
