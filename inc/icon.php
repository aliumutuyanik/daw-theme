<?php
/**
 * Discover Asian World — a real favicon.ico at the web root.
 *
 * WHAT THE PROBLEM ACTUALLY IS
 * ----------------------------
 * The site declares its icon properly in every page's head. It still comes out
 * wrong in some places, and one of the reasons is a path nobody declares:
 * /favicon.ico. Browsers, feed readers, link previews and a good deal of older
 * software ask for it whether or not the html says anything, and on this host
 * the request never reaches WordPress. The server answers it with its own error
 * page, which is html, which is not an icon.
 *
 * WordPress offers a do_faviconico hook for exactly this, and it is useless
 * here because the hook only fires if the request gets that far.
 *
 * So a real file is written at that path, built from the site icon the rest of
 * the site already uses. One picture, at every address that asks for one.
 *
 * NOTHING IS DRAWN
 * ----------------
 * An earlier version of this file drew a simplified seal, on the argument that
 * the wax seal in the logo is unreadable at sixteen pixels. That argument
 * produced a second logo, which is a worse problem than a small one. The bytes
 * here come from the image in the media library and nowhere else.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_ICON_RUN = '2026-09-18-b';

/**
 * The site icon at one size, as a path on disk.
 *
 * Read from the attachment rather than fetched over http, because a site that
 * cannot reach itself over the network is common and a site that cannot read
 * its own uploads folder is broken in a way this would not fix.
 */
function daw_icon_file( $size ) {
	$id = (int) get_option( 'site_icon' );

	if ( ! $id ) {
		return '';
	}

	$src = wp_get_attachment_image_src( $id, array( (int) $size, (int) $size ) );

	if ( ! $src || empty( $src[0] ) ) {
		return '';
	}

	$up   = wp_upload_dir();
	$file = str_replace( trailingslashit( $up['baseurl'] ), trailingslashit( $up['basedir'] ), (string) $src[0] );

	return file_exists( $file ) ? $file : '';
}

/**
 * A real .ico, which is a container with a png inside it.
 *
 * Every browser that still asks for this path has understood a png inside an
 * ico for fifteen years, and a png is the only way to get a photographic mark
 * into thirty two pixels without it turning into four colours of mud.
 */
function daw_icon_ico( $png ) {
	if ( '' === $png ) {
		return '';
	}

	return pack( 'vvv', 0, 1, 1 )
		. pack( 'CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen( $png ), 22 )
		. $png;
}

/* ================================================================= run === */

add_action( 'admin_init', 'daw_icon_maybe_write', 5 );
function daw_icon_maybe_write() {
	if ( function_exists( 'daw_is_delicate_request' ) && daw_is_delicate_request() ) {
		return;
	}

	$stamp = DAW_ICON_RUN . '|' . (int) get_option( 'site_icon' );

	if ( get_option( 'daw_icon_run' ) === $stamp ) {
		return;
	}

	$file = daw_icon_file( 32 );

	if ( '' === $file ) {
		update_option( 'daw_icon_note', 'There is no site icon set, so /favicon.ico was left alone. Set one in Appearance, Customise, Site Identity.', false );
		update_option( 'daw_icon_run', $stamp, false );
		return;
	}

	$png = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$ico = daw_icon_ico( $png );
	$note = '';

	if ( '' !== $ico ) {
		$root = trailingslashit( ABSPATH ) . 'favicon.ico';

		if ( false === file_put_contents( $root, $ico ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$note = 'favicon.ico could not be written to the site root, so that one path still answers with the host error page. Every page still declares the icon itself.';
		}
	}

	update_option( 'daw_icon_note', $note, false );
	update_option( 'daw_icon_run', $stamp, false );
}

/* ============================================================== notice === */

add_action( 'admin_notices', 'daw_icon_notice' );
function daw_icon_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$note = (string) get_option( 'daw_icon_note' );

	if ( '' === $note ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'DAW icon:', 'daw' ),
		esc_html( $note )
	);
}
