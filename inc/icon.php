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

const DAW_ICON_RUN = '2026-10-28-a'; // -a: 16/32/48 ico, opaque touch icon, manifest (1.75.87)

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
 * The site icon's original file on disk, the one every size is cut from.
 */
function daw_icon_master() {
	$id = (int) get_option( 'site_icon' );
	$f  = $id ? (string) get_attached_file( $id ) : '';
	return ( '' !== $f && file_exists( $f ) ) ? $f : '';
}

/**
 * One square png of the site icon, as bytes.
 *
 * $bg null keeps the transparency (tabs, Android). A colour flattens it onto
 * that colour, because iOS paints a transparent home-screen icon black.
 * $pad is the margin on each side as a share of the size: Android crops a
 * maskable icon to whatever shape the phone uses, keeping only the middle 80%.
 */
function daw_icon_png( $size, $bg = null, $pad = 0 ) {
	$master = daw_icon_master();
	if ( '' === $master || ! function_exists( 'imagecreatefromstring' ) ) {
		return '';
	}
	$src = @imagecreatefromstring( (string) file_get_contents( $master ) ); // phpcs:ignore
	if ( ! $src ) {
		return '';
	}
	$w   = imagesx( $src );
	$h   = imagesy( $src );
	$cut = min( $w, $h );
	$dst = imagecreatetruecolor( $size, $size );
	if ( null === $bg ) {
		imagealphablending( $dst, false );
		imagesavealpha( $dst, true );
		imagefill( $dst, 0, 0, imagecolorallocatealpha( $dst, 0, 0, 0, 127 ) );
		imagealphablending( $dst, true );
	} else {
		imagefill( $dst, 0, 0, imagecolorallocate( $dst, $bg[0], $bg[1], $bg[2] ) );
	}
	$in = (int) round( $size * ( 1 - 2 * $pad ) );
	$at = (int) ( ( $size - $in ) / 2 );
	imagecopyresampled( $dst, $src, $at, $at, (int) ( ( $w - $cut ) / 2 ), (int) ( ( $h - $cut ) / 2 ), $in, $in, $cut, $cut );
	imagesavealpha( $dst, null === $bg );
	ob_start();
	imagepng( $dst, null, 9 );
	$png = (string) ob_get_clean();
	imagedestroy( $src );
	imagedestroy( $dst );
	return $png;
}

/**
 * A real .ico: a container holding one png per size. 16 is the tab, 32 the
 * high-density tab and the taskbar, 48 the Windows desktop shortcut.
 */
function daw_icon_ico( $pngs ) {
	$pngs = array_filter( (array) $pngs );
	if ( ! $pngs ) {
		return '';
	}
	$head = pack( 'vvv', 0, 1, count( $pngs ) );
	$body = '';
	$off  = 6 + 16 * count( $pngs );
	foreach ( $pngs as $size => $png ) {
		$px    = $size >= 256 ? 0 : (int) $size;
		$head .= pack( 'CCCCvvVV', $px, $px, 0, 0, 1, 32, strlen( $png ), $off + strlen( $body ) );
		$body .= $png;
	}
	return $head . $body;
}

/** The files written at the web root, by name. */
function daw_icon_root_files() {
	$cream = array( 0xFA, 0xF7, 0xF2 );
	$out   = array();
	$ico   = daw_icon_ico(
		array(
			16 => daw_icon_png( 16 ),
			32 => daw_icon_png( 32 ),
			48 => daw_icon_png( 48 ),
		)
	);
	if ( '' === $ico ) {
		// No GD: the old single-size file, cut by WordPress.
		$f   = daw_icon_file( 32 );
		$ico = '' !== $f ? daw_icon_ico( array( 32 => (string) file_get_contents( $f ) ) ) : ''; // phpcs:ignore
	}
	$out['favicon.ico']                     = $ico;
	$out['apple-touch-icon.png']            = daw_icon_png( 180, $cream );
	$out['apple-touch-icon-precomposed.png'] = $out['apple-touch-icon.png'];
	$out['icon-192.png']                    = daw_icon_png( 192 );
	$out['icon-512.png']                    = daw_icon_png( 512 );
	$out['icon-maskable-512.png']           = daw_icon_png( 512, $cream, 0.1 );
	$out['site.webmanifest']                = '' === $out['icon-512.png'] ? '' : (string) wp_json_encode(
		array(
			'name'             => 'Discover Asian World',
			'short_name'       => 'Asian World',
			'start_url'        => '/',
			'display'          => 'browser',
			'background_color' => '#FAF7F2',
			'theme_color'      => '#7E2128',
			'icons'            => array(
				array( 'src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png' ),
				array( 'src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png' ),
				array( 'src' => '/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
			),
		),
		JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
	);
	return $out;
}

/** Whether a root file this plugin writes is in place. */
function daw_icon_root_has( $name ) {
	$done = (array) get_option( 'daw_icon_files', array() );
	return in_array( $name, $done, true );
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

	if ( ! (int) get_option( 'site_icon' ) ) {
		update_option( 'daw_icon_note', 'There is no site icon set, so /favicon.ico was left alone. Set one in Appearance, Customise, Site Identity.', false );
		update_option( 'daw_icon_files', array(), false );
		update_option( 'daw_icon_run', $stamp, false );
		return;
	}

	$done = array();
	$fail = array();
	foreach ( daw_icon_root_files() as $name => $bytes ) {
		if ( '' === $bytes ) {
			continue;
		}
		if ( false === file_put_contents( trailingslashit( ABSPATH ) . $name, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$fail[] = $name;
		} else {
			$done[] = $name;
		}
	}

	$note = '';
	if ( $fail ) {
		$note = 'These icon files could not be written to the site root: ' . implode( ', ', $fail ) . '. Every page still declares the icon itself.';
	} elseif ( ! in_array( 'apple-touch-icon.png', $done, true ) ) {
		$note = 'The server has no GD image library, so only a single-size favicon.ico was written.';
	}

	update_option( 'daw_icon_files', $done, false );
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
