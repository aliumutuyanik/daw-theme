<?php
/**
 * Discover Asian World: which AI crawlers may read the site.
 *
 * THE OWNER'S RULE (5 Oct 2026)
 * -----------------------------
 * "Only what brings traffic." A crawler that reads the archive to train a
 * model gives nothing back: the answer is shown without the site. A crawler
 * that fetches a page because someone asked ChatGPT, Claude or Perplexity a
 * question puts a link to the page in the answer. So:
 *
 * - TRAINING crawlers are refused, twice: by robots.txt (they all say they
 *   honour it) and by a 403 at the web server (.htaccess, before WordPress
 *   and before the page cache), for the ones that do not. robots.txt itself
 *   stays readable so they can see the rule.
 * - SEARCH / CITATION crawlers and Bingbot stay welcome.
 * - Googlebot is never touched: Google's AI Overviews use the ordinary
 *   Googlebot crawl, and refusing it means leaving Google Search.
 *   Google-Extended (training for Gemini) is a robots.txt token only.
 *
 * WHAT IS MEASURED
 * ----------------
 * - Visits by the allowed AI crawlers that reach WordPress, per month. Pages
 *   served from the LiteSpeed cache never reach PHP, so this is a floor.
 * - Readers who arrived from an AI answer (chatgpt.com, perplexity.ai,
 *   gemini, copilot, claude.ai…), from Analytics, last 28 days (ga4.php).
 * The control centre shows both, so "this crawler came N times and brought
 * M readers" is a fact on screen, not a guess.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_AIB_VER = '1';

/** Crawlers that collect for training. user-agent token => who. */
function daw_aib_training() {
	return array(
		'GPTBot'             => 'OpenAI (eğitim)',
		'ClaudeBot'          => 'Anthropic (eğitim)',
		'Google-Extended'    => 'Google Gemini (eğitim izni)',
		'Applebot-Extended'  => 'Apple (eğitim izni)',
		'Meta-ExternalAgent' => 'Meta Llama (eğitim)',
		'meta-externalagent' => '',
		'Bytespider'         => 'ByteDance / TikTok (eğitim)',
		'CCBot'              => 'Common Crawl (eğitim arşivi)',
		'Amazonbot'          => 'Amazon (eğitim)',
		'Diffbot'            => 'Diffbot (veri toplama)',
		'Omgilibot'          => 'Webz.io (veri toplama)',
		'Webzio-Extended'    => '',
		'ICC-Crawler'        => 'ICC (eğitim)',
		'cohere-ai'          => 'Cohere (eğitim)',
		'cohere-training-data-crawler' => '',
		'AI2Bot'             => 'Allen AI (eğitim)',
		'PanguBot'           => 'Huawei (eğitim)',
		'img2dataset'        => 'Görsel veri seti toplayıcı',
	);
}

/** Crawlers that fetch to answer a person, with a link back. */
function daw_aib_citation() {
	return array(
		'OAI-SearchBot'    => 'ChatGPT arama',
		'ChatGPT-User'     => 'ChatGPT (kullanıcı sorusu)',
		'Claude-SearchBot' => 'Claude arama',
		'Claude-User'      => 'Claude (kullanıcı sorusu)',
		'PerplexityBot'    => 'Perplexity arama',
		'Perplexity-User'  => 'Perplexity (kullanıcı sorusu)',
		'MistralAI-User'   => 'Mistral Le Chat',
		'DuckAssistBot'    => 'DuckDuckGo yapay zekâ cevabı',
		'Meta-WebIndexer'  => 'Meta AI arama',
		'Gemini-Deep-Research' => 'Gemini derin araştırma',
		'bingbot'          => 'Bing ve Copilot',
	);
}

/* ============================================================ robots.txt == */

add_filter( 'robots_txt', 'daw_aib_robots', 30, 2 );
function daw_aib_robots( $output, $public ) {
	if ( ! $public || false !== stripos( (string) $output, 'User-agent: GPTBot' ) ) {
		return $output;
	}
	$block = "\n\n# AI training crawlers: refused (they give no traffic back). Search and citation crawlers stay allowed.\n";
	foreach ( array_keys( daw_aib_training() ) as $ua ) {
		$block .= 'User-agent: ' . $ua . "\n";
	}
	$block .= "Disallow: /\n";
	return rtrim( (string) $output ) . $block;
}

/* ================================================== server-level refusal == */

/** The .htaccess lines: 403 for training crawlers, robots.txt exempt. */
function daw_aib_htaccess_lines() {
	$names = array();
	foreach ( array_keys( daw_aib_training() ) as $ua ) {
		if ( false === strpos( $ua, 'Extended' ) ) { // the -Extended tokens never visit.
			$names[] = preg_quote( $ua, '/' );
		}
	}
	$names = array_values( array_unique( array_map( 'strtolower', $names ) ) );
	return array(
		'<IfModule mod_rewrite.c>',
		'RewriteEngine On',
		'RewriteCond %{REQUEST_URI} !^/robots\.txt$',
		'RewriteCond %{HTTP_USER_AGENT} (' . implode( '|', $names ) . ') [NC]',
		'RewriteRule .* - [F,L]',
		'</IfModule>',
	);
}

/**
 * The block goes at the TOP of .htaccess, above LiteSpeed's cache rules:
 * placed below them, a cached page would be handed to the crawler before
 * the refusal is read. The site is fetched right after writing; anything
 * but a normal answer puts the old file back.
 */
function daw_aib_htaccess_write( $lines ) {
	$file = ABSPATH . '.htaccess';
	if ( ! file_exists( $file ) || ! is_writable( $file ) ) {
		return false;
	}
	$old   = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$clean = (string) preg_replace( '/# BEGIN DAW AI training crawlers.*?# END DAW AI training crawlers\R*/s', '', $old );
	$new   = $lines ? "# BEGIN DAW AI training crawlers\n" . implode( "\n", $lines ) . "\n# END DAW AI training crawlers\n\n" . $clean : $clean;
	if ( $new === $old ) {
		return true;
	}
	if ( false === file_put_contents( $file, $new, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false;
	}
	$res  = wp_remote_get( add_query_arg( 'daw_ht', time(), home_url( '/' ) ), array( 'timeout' => 15, 'redirection' => 2 ) );
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	if ( $code < 200 || $code >= 400 ) {
		file_put_contents( $file, $old, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false;
	}
	return true;
}

add_action( 'admin_init', 'daw_aib_htaccess_install' );
function daw_aib_htaccess_install() {
	if ( get_option( 'daw_aib_ver' ) === DAW_AIB_VER || ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
		return;
	}
	update_option( 'daw_aib_ver', DAW_AIB_VER, false ); // once, whatever happens
	update_option( 'daw_aib_ht', daw_aib_htaccess_write( daw_aib_htaccess_lines() ) ? 'ok' : 'no', false );
}

/** Removing the plugin should not leave the rule behind. */
register_deactivation_hook( DAW_DIR . 'daw-core.php', 'daw_aib_htaccess_remove' );
function daw_aib_htaccess_remove() {
	daw_aib_htaccess_write( array() );
	delete_option( 'daw_aib_ver' );
}

/* ============================================================== counting == */

add_action( 'init', 'daw_aib_count', 1 );
function daw_aib_count() {
	if ( is_admin() || empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
		return;
	}
	$ua = (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( false === stripos( $ua, 'bot' ) && false === stripos( $ua, '-user' ) && false === stripos( $ua, 'research' ) ) {
		return;
	}
	$hit = '';
	foreach ( array_merge( array_keys( daw_aib_citation() ), array_keys( daw_aib_training() ) ) as $name ) {
		if ( false !== stripos( $ua, $name ) ) {
			$hit = $name;
			break;
		}
	}
	if ( '' === $hit ) {
		return;
	}
	$m   = gmdate( 'Y-m' );
	$all = get_option( 'daw_aib_hits', array() );
	$all = is_array( $all ) ? $all : array();
	$all[ $m ][ $hit ] = (int) ( $all[ $m ][ $hit ] ?? 0 ) + 1;
	// Six months are plenty.
	ksort( $all );
	$all = array_slice( $all, -6, null, true );
	update_option( 'daw_aib_hits', $all, false );
}

/* =================================================== readers from answers == */

/** Analytics session sources that are AI answers, with a readable name. */
function daw_aib_sources() {
	return array(
		'chatgpt'    => 'ChatGPT',
		'openai'     => 'ChatGPT',
		'perplexity' => 'Perplexity',
		'gemini'     => 'Gemini',
		'bard'       => 'Gemini',
		'copilot'    => 'Copilot',
		'claude'     => 'Claude',
		'anthropic'  => 'Claude',
		'mistral'    => 'Mistral',
		'deepseek'   => 'DeepSeek',
		'meta.ai'    => 'Meta AI',
		'you.com'    => 'You.com',
		'grok'       => 'Grok',
	);
}

add_action( 'daw_ga4_daily', 'daw_aib_ga_run', 40 );
add_action( 'daw_kc_run_all_late', 'daw_aib_ga_run', 40 );
function daw_aib_ga_run() {
	if ( ! function_exists( 'daw_ga4_report' ) ) {
		return;
	}
	$res = daw_ga4_report(
		array(
			'dateRanges' => array( array( 'startDate' => '28daysAgo', 'endDate' => 'yesterday' ) ),
			'dimensions' => array( array( 'name' => 'sessionSource' ) ),
			'metrics'    => array( array( 'name' => 'sessions' ), array( 'name' => 'activeUsers' ) ),
			'limit'      => 500,
		)
	);
	if ( is_wp_error( $res ) ) {
		return;
	}
	$out = array();
	foreach ( daw_ga4_rows( $res ) as $r ) {
		$src = strtolower( (string) $r[0] );
		foreach ( daw_aib_sources() as $needle => $name ) {
			if ( false !== strpos( $src, $needle ) ) {
				$out[ $name ] = array(
					(int) ( $out[ $name ][0] ?? 0 ) + (int) $r[1],
					(int) ( $out[ $name ][1] ?? 0 ) + (int) $r[2],
				);
				break;
			}
		}
	}
	update_option( 'daw_aib_ref', array( 'at' => time(), 'rows' => $out ), false );
}

/* ================================================================ card == */

/** Control-centre data. */
function daw_aib_data() {
	$hits = get_option( 'daw_aib_hits', array() );
	$hits = is_array( $hits ) ? $hits : array();
	$m    = gmdate( 'Y-m' );
	$ref  = get_option( 'daw_aib_ref', array() );
	return array(
		'state'   => 'ok',
		'robots'  => true,
		'ht'      => (string) get_option( 'daw_aib_ht', '' ),
		'month'   => (array) ( $hits[ $m ] ?? array() ),
		'readers' => (array) ( is_array( $ref ) ? ( $ref['rows'] ?? array() ) : array() ),
		'ref_at'  => (int) ( is_array( $ref ) ? ( $ref['at'] ?? 0 ) : 0 ),
	);
}

function daw_aib_card( array $ai ) {
	daw_kc_card_open( 'ai', '✦', 'Yapay zekâ botları', $ai['state'] );
	$cite  = daw_aib_citation();
	$train = daw_aib_training();
	$came  = array_intersect_key( $ai['month'], $cite );
	$tried = array_intersect_key( $ai['month'], $train );
	arsort( $came );
	$readers = 0;
	foreach ( $ai['readers'] as $r ) {
		$readers += (int) $r[1];
	}

	echo '<ul class="kc-checks">';
	daw_kc_check( true, sprintf( 'Eğitim botları kapalı: %d bot robots.txt ile reddediliyor', count( array_filter( $train ) ) ), 'ok' );
	daw_kc_check( 'ok' === $ai['ht'], 'ok' === $ai['ht'] ? 'Sunucu kuralı da var: kurala uymayan eğitim botu 403 alır' : 'Sunucu kuralı yazılamadı (.htaccess); yalnız robots.txt geçerli', 'ok' === $ai['ht'] ? 'ok' : 'info' );
	daw_kc_check( true, 'Açık: ChatGPT, Claude, Perplexity arama botları ve Bing/Copilot (cevapta link veren botlar). Googlebot hiç etkilenmez.', 'ok' );
	daw_kc_check( true, sprintf( 'Yapay zekâ cevaplarından gelen okur (28 gün): %s', daw_kc_n( $readers ) ), 'info' );
	echo '</ul>';

	if ( $ai['readers'] ) {
		echo '<table class="kc-mini"><thead><tr><th>Kaynak</th><th>Okur</th><th>Oturum</th></tr></thead><tbody>';
		foreach ( $ai['readers'] as $name => $r ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $name ), esc_html( daw_kc_n( $r[1] ) ), esc_html( daw_kc_n( $r[0] ) ) );
		}
		echo '</tbody></table>';
	}
	if ( $came || $tried ) {
		echo '<table class="kc-mini"><thead><tr><th>Bu ay gelen bot</th><th>Ziyaret</th></tr></thead><tbody>';
		foreach ( $came as $ua => $n ) {
			printf( '<tr><td>%s <small>(%s)</small></td><td>%s</td></tr>', esc_html( $cite[ $ua ] ), esc_html( $ua ), esc_html( daw_kc_n( $n ) ) );
		}
		foreach ( $tried as $ua => $n ) {
			printf( '<tr><td>%s <small>(%s, reddedildi)</small></td><td>%s</td></tr>', esc_html( $train[ $ua ] ?: $ua ), esc_html( $ua ), esc_html( daw_kc_n( $n ) ) );
		}
		echo '</tbody></table>';
	}
	echo '<p class="kc-muted">Bot sayısı alt sınırdır: önbellekten verilen sayfalar WordPress\'e ulaşmadığı için sayılamaz.</p>';
	daw_kc_card_close();
}
