<?php
/**
 * Discover Asian World: brand pictures made only from the site's own seal.
 *
 * WHY
 * ---
 * The owner asked for a clean picture made entirely from the logo: for the
 * theme itself (the thumbnail WordPress shows under Appearance › Themes) and,
 * later, for social media work. Nothing in it belongs to anyone else, so it
 * can be used anywhere.
 *
 * HOW
 * ---
 * Drawn on the server with GD from the real logo file (the 300 px seal): a warm
 * paper ground, a thin double gold frame, a soft shadow and the seal in the
 * middle at its own size, never enlarged. Three sizes are written to a public
 * folder, uploads/daw-brand/, not to the media library:
 *
 *   social  1200 x 630   link previews (Facebook, X, WhatsApp)
 *   square  1080 x 1080  Instagram and profile pictures
 *   theme   1200 x 900   the theme's screenshot.png
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

function daw_brand_sizes() {
	return array(
		'social' => array( 1200, 630 ),
		'square' => array( 1080, 1080 ),
		'theme'  => array( 1200, 900 ),
	);
}

function daw_brand_dir() {
	$up  = wp_upload_dir( null, false );
	$dir = trailingslashit( $up['basedir'] ) . 'daw-brand';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	return $dir;
}

function daw_brand_url( $name ) {
	$up   = wp_upload_dir( null, false );
	$file = daw_brand_dir() . '/' . $name . '.png';
	return file_exists( $file ) ? trailingslashit( $up['baseurl'] ) . 'daw-brand/' . $name . '.png?v=' . filemtime( $file ) : '';
}

/** Draw one brand picture. Returns a GD image or null. */
function daw_brand_draw( $w, $h ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'daw_card_logo_path' ) ) {
		return null;
	}
	$logo_path = daw_card_logo_path();
	$logo      = $logo_path ? @imagecreatefrompng( $logo_path ) : false; // phpcs:ignore
	if ( ! $logo ) {
		return null;
	}
	imagealphablending( $logo, true );
	$lw = imagesx( $logo );
	$lh = imagesy( $logo );

	$im = imagecreatetruecolor( $w, $h );
	imagealphablending( $im, true );
	imagesavealpha( $im, false );

	// Warm paper, a touch darker towards the edges.
	for ( $y = 0; $y < $h; $y++ ) {
		$t = abs( $y - $h / 2 ) / ( $h / 2 );
		$r = (int) round( 246 - 14 * $t );
		$g = (int) round( 239 - 16 * $t );
		$b = (int) round( 229 - 18 * $t );
		imageline( $im, 0, $y, $w, $y, imagecolorallocate( $im, $r, $g, $b ) );
	}

	// A thin double gold frame, inset.
	$cx = $w / 2;
	$cy = $h / 2;
	foreach ( array( array( 26, 96 ), array( 33, 110 ) ) as $f ) {
		$gold = imagecolorallocatealpha( $im, 176, 132, 72, $f[1] );
		imagerectangle( $im, $f[0], $f[0], $w - 1 - $f[0], $h - 1 - $f[0], $gold );
	}

	// Soft shadow, then the seal at its own size.
	$size = min( $lw, (int) round( min( $w, $h ) * 0.56 ) );
	// Many faint rings build a soft shadow without a hard edge.
	for ( $k = 16; $k > 0; $k-- ) {
		imagefilledellipse( $im, (int) $cx, (int) ( $cy + $size * 0.09 ), (int) ( $size * 0.84 + $k * 3 ), (int) ( $size * 0.84 + $k * 2 ), imagecolorallocatealpha( $im, 70, 30, 25, 125 ) );
	}
	imagecopyresampled( $im, $logo, (int) round( $cx - $size / 2 ), (int) round( $cy - $size / 2 ), 0, 0, $size, $size, $lw, $lh );

	imagedestroy( $logo );
	return $im;
}

/** Copy an image with transparency at a given opacity (0–100). */
function daw_brand_copy_alpha( $dst, $src, $x, $y, $pct ) {
	$w   = imagesx( $src );
	$h   = imagesy( $src );
	$tmp = imagecreatetruecolor( $w, $h );
	imagecopy( $tmp, $dst, 0, 0, $x, $y, $w, $h );
	imagealphablending( $tmp, true );
	imagecopyresampled( $tmp, $src, 0, 0, 0, 0, $w, $h, $w, $h );
	imagecopymerge( $dst, $tmp, $x, $y, 0, 0, $w, $h, $pct );
	imagedestroy( $tmp );
}

/** Write all sizes, and the theme screenshot if the theme folder is writable. */
function daw_brand_build() {
	$done = array();
	foreach ( daw_brand_sizes() as $name => $wh ) {
		$im = daw_brand_draw( $wh[0], $wh[1] );
		if ( ! $im ) {
			return array( 'error' => 'Logo dosyası okunamadı ya da sunucuda GD yok.' );
		}
		$file = daw_brand_dir() . '/' . $name . '.png';
		imagepng( $im, $file, 6 );
		if ( 'theme' === $name ) {
			$shot = trailingslashit( get_stylesheet_directory() ) . 'screenshot.png';
			if ( wp_is_writable( dirname( $shot ) ) ) {
				imagepng( $im, $shot, 6 );
				$done['screenshot'] = 1;
			}
		}
		imagedestroy( $im );
		$done[ $name ] = daw_brand_url( $name );
	}
	update_option( 'daw_brand_images', array( 'built' => time() ) + $done, false );
	return $done;
}

add_action( 'admin_post_daw_brand_build', 'daw_brand_build_action' );
function daw_brand_build_action() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'daw_brand_build' ) ) {
		wp_die( 'No.' );
	}
	$r = daw_brand_build();
	set_transient( 'daw_hub_msg_' . get_current_user_id(), isset( $r['error'] ) ? $r['error'] : 'Marka görselleri oluşturuldu.', 120 );
	wp_safe_redirect( admin_url( 'admin.php?page=daw#brand' ) );
	exit;
}

/** The card on the home screen. */
function daw_brand_panel() {
	echo '<div id="brand" style="max-width:1200px;margin-top:18px;background:#fff;border:1px solid #c3c4c7;padding:14px 16px">';
	echo '<h2 style="margin:0 0 8px;font-size:15px">Marka görselleri (sadece logodan)</h2>';
	echo '<p style="margin:0 0 10px">Tema görseli ve sosyal medya için, tamamen sitenin mührüyle çizilir. Kimsenin hakkı olmayan, her yerde kullanılabilecek görseller.</p>';
	echo '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">';
	foreach ( array( 'social' => 'Sosyal paylaşım 1200×630', 'square' => 'Kare 1080×1080', 'theme' => 'Tema 1200×900' ) as $k => $label ) {
		$u = daw_brand_url( $k );
		echo '<figure style="margin:0">';
		if ( $u ) {
			printf( '<a href="%1$s" target="_blank" rel="noopener"><img src="%1$s" alt="" style="height:140px;width:auto;border:1px solid #dcdcde;display:block"></a>', esc_url( $u ) );
		} else {
			echo '<div style="height:140px;width:200px;border:1px dashed #c3c4c7;display:flex;align-items:center;justify-content:center;color:#646970">Henüz yok</div>';
		}
		printf( '<figcaption style="font-size:12px;color:#646970;margin-top:4px">%s</figcaption></figure>', esc_html( $label ) );
	}
	echo '</div>';
	printf( '<form method="post" action="%s" style="margin-top:10px">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'daw_brand_build' );
	echo '<input type="hidden" name="action" value="daw_brand_build">';
	submit_button( daw_brand_url( 'social' ) ? 'Yeniden oluştur' : 'Oluştur', 'secondary', 'go', false );
	echo '</form></div>';
}
