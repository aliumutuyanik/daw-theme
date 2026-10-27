<?php
/**
 * Discover Asian World: HTTPS only, said once to the browser.
 *
 * WHY
 * ---
 * PageSpeed reported "Redirects" (190 ms on desktop, 630 ms on a phone) on
 * every run: the tool asks for http://discoverasianworld.com and the server
 * answers with a redirect to https. Strict-Transport-Security tells a browser
 * that has seen the site once to go straight to https from then on, without
 * the round trip.
 *
 * WHY IN .htaccess AND NOT A PHP HEADER
 * -------------------------------------
 * Most pages are served by LiteSpeed's page cache without PHP running at all,
 * so a header sent from PHP would be missing on exactly the responses that
 * matter. A server directive is on every response, cached or not. It is
 * written with WordPress's own insert_with_markers(), the same function
 * WordPress and LiteSpeed use for their blocks, between "# BEGIN DAW HSTS"
 * and "# END DAW HSTS", so it never touches a line outside that block.
 *
 * WHAT IS DELIBERATELY NOT IN IT
 * ------------------------------
 * No includeSubDomains (the staging subdomain is not ours to commit) and no
 * preload (being on the browsers' preload list takes months to undo). One
 * year, this host only. Both can be added later once this has run cleanly.
 *
 * HOW TO UNDO IT
 * --------------
 * update_option( 'daw_hsts', 'off' ): the next admin page load empties the
 * block. The default is 'on'.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_HSTS_MARKER = 'DAW HSTS';
const DAW_HSTS_STAMP  = 'daw_hsts_written';

/** The lines the block should hold right now. */
function daw_hsts_lines() {
	if ( 'off' === get_option( 'daw_hsts', 'on' ) ) {
		return array();
	}

	// Only on a site that is itself served over https, or the header would
	// promise something the server does not do.
	if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
		return array();
	}

	return array(
		'<IfModule mod_headers.c>',
		'Header always set Strict-Transport-Security "max-age=31536000"',
		'</IfModule>',
	);
}

/**
 * Write the block when it differs from what was last written.
 *
 * admin_init, not every request: .htaccess is read by the server, not by
 * WordPress, so it only has to be right once, and the admin is where the
 * file helpers are loaded and an editor is present if anything goes wrong.
 */
add_action( 'admin_init', 'daw_hsts_sync' );
function daw_hsts_sync() {
	if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$lines = daw_hsts_lines();
	$want  = md5( implode( "\n", $lines ) );

	if ( get_option( DAW_HSTS_STAMP ) === $want ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';

	$file = get_home_path() . '.htaccess';

	// Never create the file, and never write into one the server cannot
	// read back: a missing or locked .htaccess is the host's to manage.
	if ( ! file_exists( $file ) || ! wp_is_writable( $file ) ) {
		return;
	}

	if ( insert_with_markers( $file, DAW_HSTS_MARKER, $lines ) ) {
		update_option( DAW_HSTS_STAMP, $want, false );
	}
}
