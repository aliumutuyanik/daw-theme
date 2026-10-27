<?php
/**
 * Discover Asian World — the hardening that costs nothing.
 *
 * WHAT THIS IS AND IS NOT
 * -----------------------
 * This is not a security plugin. A security plugin is a firewall, a malware
 * scanner and a login lockout, and all three of those belong at the host, where
 * they can act before a request reaches PHP. Running them inside WordPress
 * means every visitor pays for the check, which is the opposite of what this
 * site is being rebuilt for.
 *
 * What is here is the set of things that are free: closing doors nobody uses,
 * declining to announce which version of everything is running, and sending the
 * four response headers that tell a browser to be strict. Each one is a line,
 * none of them run a query, and together they remove the easy half of what an
 * automated scanner looks for.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * -----------------------------
 * No content security policy: advertising is served by a third party that loads
 * further third parties, and a policy written without that inventory would
 * break the adverts rather than secure the page. No login rate limiting, which
 * without a shared store is per-process and therefore theatre. No blocking of
 * the REST API, which is how this site is edited.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================ headers ==== */

/**
 * The four headers worth sending on every page.
 *
 * Nothing exotic, and nothing that can break a page that was already correct.
 */
add_action( 'send_headers', 'daw_security_headers' );
function daw_security_headers() {
	if ( is_admin() || headers_sent() ) {
		return;
	}

	// A file served as a stylesheet is a stylesheet. This is the header that
	// stops a browser being talked into treating an upload as a script.
	header( 'X-Content-Type-Options: nosniff' );

	// Another site may frame nothing of ours. Adverts are unaffected: this is
	// about our page being put inside someone else's, not about what we embed.
	header( 'X-Frame-Options: SAMEORIGIN' );

	// The full url of the page a reader came from is their business. The origin
	// is enough for anybody we link to, and it keeps query strings out of other
	// people's logs.
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );

	// Nothing on this site needs a camera, a microphone or a location, so
	// nothing embedded in it gets to ask.
	header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()' );
}

/* ========================================================== version ====== */

/**
 * Stop announcing the exact version of everything.
 *
 * "WordPress 7.1.1" in a meta tag is the first line of an automated scan: it
 * turns a guess into a lookup. Hiding it is not a fix for an unpatched site,
 * and this site is patched; it removes the free half of the attacker's work.
 */
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

// The same number, offered again through the feed and the oEmbed discovery
// links, neither of which this site uses.
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'wp_shortlink_wp_head' );

/* ============================================================ xmlrpc ===== */

/**
 * XML-RPC, off.
 *
 * It exists for desktop blogging clients and for Jetpack. Neither is here, and
 * it is the endpoint every credential stuffing script tries first because it
 * accepts hundreds of password guesses in one request. This site is edited
 * through the REST API, which is untouched.
 */
add_filter( 'xmlrpc_enabled', '__return_false' );

/* ======================================================= enumeration ===== */

/**
 * Author enumeration, off.
 *
 * /?author=1 redirects to the author archive, and the url it redirects to
 * contains the login name. That is half of a login handed over by a url anybody
 * can type. This site has no author archives to reach, so the request simply
 * goes home.
 */
add_action( 'template_redirect', 'daw_block_author_enumeration' );
function daw_block_author_enumeration() {
	if ( is_admin() || ! isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	wp_safe_redirect( home_url( '/' ), 301 );
	exit;
}

/**
 * The same list, through the REST API, to anybody who is not signed in.
 *
 * The endpoint stays for an editor and for the connector this site is managed
 * with, because both are authenticated and both need it. It is removed for a
 * request that has not identified itself at all.
 */
add_filter( 'rest_endpoints', 'daw_close_rest_users' );
function daw_close_rest_users( $endpoints ) {
	if ( current_user_can( 'list_users' ) ) {
		return $endpoints;
	}

	unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );

	return $endpoints;
}

/* ============================================================== login ==== */

/**
 * One message for every failed sign-in.
 *
 * WordPress says "unknown username" for one and "the password you entered for
 * the username X is incorrect" for the other, which confirms an account exists
 * before anybody has guessed a password. One message says nothing.
 */
add_filter( 'login_errors', 'daw_quiet_login_errors' );
function daw_quiet_login_errors() {
	return __( 'That sign-in was not recognised.', 'daw' );
}
