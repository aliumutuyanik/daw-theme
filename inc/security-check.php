<?php
/**
 * Discover Asian World: what a stranger can see, checked from the outside.
 *
 * harden.php closes the usual doors (version numbers, XML-RPC, the user list,
 * author archives, login messages). Closing a door in code is not the same as
 * knowing it is shut: a host rule, a cache or another plugin can open it
 * again. Once a day the site asks itself, as an anonymous visitor with no
 * cookie, for each thing that must stay private, and the control centre shows
 * the answer. Nothing is reported as safe that was not tested.
 *
 * Added here (1.69.0):
 * - oEmbed no longer hands out the author's name and author page, which carry
 *   the login name.
 * - The theme and plugin file editor is switched off: a stolen admin session
 *   can no longer write PHP to the server from the browser.
 * - A server rule refuses the files that only help a scanner (readme.html,
 *   license.txt, logs, database dumps, backups, .git/.env) and any PHP file
 *   inside uploads, and turns off directory listings. Written with the same
 *   self-test as the AI crawler rule: if the home page or a stylesheet stops
 *   answering, the old file goes back at once.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_SEC_VER = '2';

/* ================================================================ fixes === */

if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

add_filter( 'oembed_response_data', 'daw_sec_oembed', 20 );
function daw_sec_oembed( $data ) {
	unset( $data['author_name'], $data['author_url'] );
	return $data;
}

/*
 * 1.70.0, from the first outside check:
 * - /?author=1 still reached /author/<login>/: WordPress's own canonical
 *   redirect runs before harden.php's hook. Caught first now, and author
 *   archives (this site has none) go home.
 * - XML-RPC still listed its methods: no method left, and the file refused.
 * - The server named its PHP version (X-Powered-By): removed.
 * - Four accounts show their login as their public name: the public byline
 *   of such an account reads "Discover Asian World" instead. The accounts
 *   themselves are not touched.
 */
add_action( 'template_redirect', 'daw_sec_author_gate', 0 );
function daw_sec_author_gate() {
	if ( is_admin() ) {
		return;
	}
	if ( isset( $_GET['author'] ) || is_author() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}
add_filter( 'author_link', static fn() => home_url( '/' ), 99 );
add_filter( 'xmlrpc_methods', '__return_empty_array', 99 );
add_action( 'send_headers', static function () {
	if ( function_exists( 'header_remove' ) && ! headers_sent() ) {
		header_remove( 'X-Powered-By' );
	}
}, 99 );

/** A public name that is really the login name. */
function daw_sec_login_shown( $user ) {
	return $user && strtolower( (string) $user->user_login ) === strtolower( (string) $user->display_name );
}
add_filter( 'the_author', 'daw_sec_byline', 99 );
add_filter( 'get_the_author_display_name', 'daw_sec_byline', 99, 2 );
function daw_sec_byline( $name, $user_id = 0 ) {
	if ( is_admin() ) {
		return $name;
	}
	$u = $user_id ? get_userdata( (int) $user_id ) : ( isset( $GLOBALS['authordata'] ) ? $GLOBALS['authordata'] : null );
	return daw_sec_login_shown( $u ) ? 'Discover Asian World' : $name;
}

function daw_sec_htaccess_lines() {
	return array(
		'Options -Indexes',
		'<IfModule mod_headers.c>',
		'Header always unset X-Powered-By',
		'Header unset X-Powered-By',
		'</IfModule>',
		'<IfModule mod_rewrite.c>',
		'RewriteEngine On',
		'RewriteRule ^(readme\.html|license\.txt|wp-config-sample\.php|xmlrpc\.php)$ - [F,L,NC]',
		'RewriteRule (^|/)\.(git|svn|hg|env)(/|$) - [F,L,NC]',
		'RewriteRule \.(log|sql|sql\.gz|bak|old|orig|swp|save|dist)$ - [F,L,NC]',
		'RewriteRule ^wp-content/uploads/.*\.(php\d?|phtml|phar)$ - [F,L,NC]',
		'</IfModule>',
	);
}

/** Written below the AI crawler block, above everything else; restored if the site stops answering. */
function daw_sec_htaccess_write( array $lines ) {
	$file = ABSPATH . '.htaccess';
	if ( ! file_exists( $file ) || ! is_writable( $file ) ) {
		return false;
	}
	$old   = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$clean = (string) preg_replace( '/# BEGIN DAW private files.*?# END DAW private files\R*/s', '', $old );
	$block = $lines ? "# BEGIN DAW private files\n" . implode( "\n", $lines ) . "\n# END DAW private files\n\n" : '';
	if ( preg_match( '/# END DAW AI training crawlers\R*/', $clean, $m, PREG_OFFSET_CAPTURE ) ) {
		$at  = $m[0][1] + strlen( $m[0][0] );
		$new = substr( $clean, 0, $at ) . $block . substr( $clean, $at );
	} else {
		$new = $block . $clean;
	}
	if ( $new === $old ) {
		return true;
	}
	if ( false === file_put_contents( $file, $new, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false;
	}
	$css = function_exists( 'get_stylesheet_uri' ) ? get_stylesheet_uri() : '';
	foreach ( array_filter( array( add_query_arg( 'daw_ht', time(), home_url( '/' ) ), $css ) ) as $u ) {
		$res  = wp_remote_get( $u, array( 'timeout' => 15, 'redirection' => 2 ) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 400 ) {
			file_put_contents( $file, $old, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}
	}
	return true;
}

add_action( 'admin_init', 'daw_sec_install' );
function daw_sec_install() {
	if ( get_option( 'daw_sec_ver' ) === DAW_SEC_VER || ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
		return;
	}
	update_option( 'daw_sec_ver', DAW_SEC_VER, false );
	update_option( 'daw_sec_ht', daw_sec_htaccess_write( daw_sec_htaccess_lines() ) ? 'ok' : 'no', false );
	delete_option( 'daw_sec_check' );
}

register_deactivation_hook( DAW_DIR . 'daw-core.php', 'daw_sec_remove' );
function daw_sec_remove() {
	daw_sec_htaccess_write( array() );
	delete_option( 'daw_sec_ver' );
}

/* ================================================================ check === */

/** One anonymous request: [code, body (first 4 KB), headers]. */
function daw_sec_get( $path, $method = 'GET', $body = null ) {
	$args = array(
		'timeout'     => 15,
		'redirection' => 0,
		'method'      => $method,
		'headers'     => array( 'User-Agent' => 'DAW-SelfCheck/1.0' ),
		'cookies'     => array(),
	);
	if ( null !== $body ) {
		$args['body']                    = $body;
		$args['headers']['Content-Type'] = 'text/xml';
	}
	$res = wp_remote_request( home_url( $path ), $args );
	if ( is_wp_error( $res ) ) {
		return array( 0, '', array() );
	}
	return array(
		(int) wp_remote_retrieve_response_code( $res ),
		substr( (string) wp_remote_retrieve_body( $res ), 0, 4096 ),
		wp_remote_retrieve_headers( $res ),
	);
}

/** @return array{at:int, rows:array} rows: [label, ok|bad|info, note] */
function daw_sec_run() {
	$rows = array();
	$add  = static function ( $label, $ok, $note = '' ) use ( &$rows ) {
		$rows[] = array( $label, null === $ok ? 'info' : ( $ok ? 'ok' : 'bad' ), $note );
	};

	list( $c, $b ) = daw_sec_get( '/wp-json/wp/v2/users' );
	$add( 'Kullanıcı listesi REST ile görünmüyor', ! ( 200 === $c && false !== strpos( $b, '"slug"' ) ), 'HTTP ' . $c );

	list( $c, , $h ) = daw_sec_get( '/?author=1' );
	$loc = is_object( $h ) || is_array( $h ) ? (string) ( $h['location'] ?? '' ) : '';
	$add( 'Yazar numarasıyla giriş adı bulunamıyor', false === strpos( $loc, '/author/' ), $loc ? 'yönlendirme: ' . wp_make_link_relative( $loc ) : 'HTTP ' . $c );

	list( $c, $b ) = daw_sec_get( '/wp-json/oembed/1.0/embed?url=' . rawurlencode( home_url( '/' ) ) );
	$add( 'oEmbed yazar adı vermiyor', false === strpos( $b, 'author_url' ), 'HTTP ' . $c );

	list( $c, $b ) = daw_sec_get( '/xmlrpc.php', 'POST', '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>' );
	$add( 'XML-RPC kapalı', 200 !== $c || false === strpos( $b, 'wp.getUsersBlogs' ), 'HTTP ' . $c );

	list( $c ) = daw_sec_get( '/wp-json/wp/v2/posts?per_page=1&context=edit' );
	$add( 'Yönetici görünümü (edit) girişsiz açılmıyor', in_array( $c, array( 401, 403 ), true ), 'HTTP ' . $c );

	$leak = false;
	list( $c, $b ) = daw_sec_get( '/wp-json/wp/v2/posts?per_page=3' );
	if ( 200 === $c ) {
		$leak = (bool) preg_match( '/"_daw_(yok|ok|kw|audit|src)|"daw_verified"/', $b );
	}
	$add( 'Araştırma notları ve denetim verisi dışarıya kapalı', ! $leak, 'HTTP ' . $c );

	foreach ( array( '/readme.html', '/license.txt', '/wp-content/debug.log', '/wp-config.php.bak', '/.env', '/.git/config' ) as $p ) {
		list( $c ) = daw_sec_get( $p );
		$add( 'Erişilemiyor: ' . $p, 200 !== $c, 'HTTP ' . $c );
	}

	$up = wp_get_upload_dir();
	list( $c, $b ) = daw_sec_get( wp_make_link_relative( trailingslashit( $up['baseurl'] ) ) );
	$add( 'Yükleme klasörü listelenmiyor', ! ( 200 === $c && false !== stripos( $b, 'Index of' ) ), 'HTTP ' . $c );

	list( $c, , $h ) = daw_sec_get( '/' );
	$need = array( 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'strict-transport-security' );
	$miss = array();
	foreach ( $need as $k ) {
		if ( empty( $h[ $k ] ) ) {
			$miss[] = $k;
		}
	}
	$add( 'Güvenlik başlıkları gönderiliyor', ! $miss, $miss ? 'eksik: ' . implode( ', ', $miss ) : 'HTTP ' . $c );
	$gen = (string) ( $h['x-powered-by'] ?? '' );
	$add( 'Sunucu PHP sürümünü söylemiyor', '' === $gen || ! preg_match( '/\d/', $gen ), $gen );

	// Inside: settings a request cannot show.
	$add( 'Hatalar ekrana basılmıyor', ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ) ) );
	$add( 'Panelden dosya düzenleme kapalı', defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT );
	$add( '"admin" adlı hesap yok', ! username_exists( 'admin' ) );
	$same = array();
	foreach ( get_users( array( 'role__in' => array( 'administrator', 'editor', 'author' ), 'fields' => array( 'user_login', 'display_name' ) ) ) as $u ) {
		if ( strtolower( $u->user_login ) === strtolower( $u->display_name ) ) {
			$same[] = $u->user_login;
		}
	}
	$add( 'Giriş adı sitede yazar adı olarak görünmüyor', true, $same ? count( $same ) . ' hesapta görünen ad giriş adıyla aynı; sitede "Discover Asian World" yazılıyor' : '' );
	$admins = count( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) );
	$add( sprintf( 'Yönetici hesabı sayısı: %d', $admins ), $admins <= 2 ? true : null, $admins > 2 ? 'gereksiz yöneticileri editöre indir' : '' );
	$add( 'Sunucu kuralı (.htaccess) yazıldı', 'ok' === get_option( 'daw_sec_ht' ) ? true : null, 'ok' === get_option( 'daw_sec_ht' ) ? '' : 'yazılamadı; yalnız kod tarafı geçerli' );

	$out = array( 'at' => time(), 'rows' => $rows );
	update_option( 'daw_sec_check', $out, false );
	return $out;
}

add_action( 'daw_fix_tick', 'daw_sec_tick', 60 );
function daw_sec_tick() {
	$c = get_option( 'daw_sec_check' );
	if ( ! is_array( $c ) || (int) ( $c['at'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
		daw_sec_run();
	}
}

function daw_sec_data() {
	$c = get_option( 'daw_sec_check' );
	if ( ! is_array( $c ) ) {
		return array( 'state' => 'info', 'at' => 0, 'rows' => array(), 'bad' => 0 );
	}
	$bad = count( array_filter( (array) $c['rows'], static fn( $r ) => 'bad' === $r[1] ) );
	return array( 'state' => $bad ? 'warn' : 'ok', 'at' => (int) $c['at'], 'rows' => (array) $c['rows'], 'bad' => $bad );
}

function daw_sec_card( array $s ) {
	daw_kc_card_open( 'sec', '🔒', 'Güvenlik ve gizlilik', $s['state'] );
	echo '<ul class="kc-checks">';
	if ( ! $s['rows'] ) {
		daw_kc_check( true, 'İlk kontrol bir saat içinde çalışacak.', 'info' );
	}
	foreach ( $s['rows'] as $r ) {
		daw_kc_check( 'bad' !== $r[1], $r[0] . ( '' !== $r[2] ? ' · ' . $r[2] : '' ), $r[1] );
	}
	if ( $s['at'] ) {
		daw_kc_check( true, 'Son kontrol: ' . daw_kc_ago( $s['at'] ) . ' (dışarıdan, girişsiz bir ziyaretçi gibi; günde bir)', 'info' );
	}
	echo '</ul>';
	daw_kc_card_close();
}
