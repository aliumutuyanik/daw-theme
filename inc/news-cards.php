<?php
/**
 * Discover Asian World — news cards drawn by the site itself.
 *
 * WHY THE SITE DRAWS ITS OWN NEWS PICTURES
 * ----------------------------------------
 * Every news image on the site was a composite put together from pictures
 * taken elsewhere, and none of them could be licensed after the fact. A news
 * story needs a picture for the listing, the social preview and Google
 * Discover, and the only picture nobody else holds rights in is one we make.
 *
 * So each story gets a card: the logo, the wordmark, the kicker, the headline,
 * the date, and the subject's name in its own script as a large watermark.
 * Nothing on it belongs to anyone but the site. It is drawn when the story is
 * saved and redrawn when the headline changes, so nobody has to remember to.
 *
 * The one other picture allowed on news is official press material from the
 * rights holder, recorded in the image rights register. A story that has one
 * keeps it; the card never overwrites a recorded image.
 *
 * FONTS
 * -----
 * The same faces the site sets in: Newsreader for the headline, Bricolage
 * Grotesque for the wordmark and small type, and Noto Serif for the
 * watermark. All three are under the SIL Open Font License. They are fetched
 * once from Google Fonts as TrueType and kept in uploads; the watermark font is
 * fetched per text, cut down to the glyphs it needs, so a Korean font is a few
 * kilobytes instead of several megabytes.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_CARD_W   = 1200;
const DAW_CARD_H   = 675;
const DAW_CARD_REV = '1'; // bump when the layout changes, so every card redraws

/** Can this server draw cards at all? */
function daw_card_capable() {
	return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagettftext' ) && function_exists( 'imagejpeg' );
}

/* ================================================================= fonts === */

function daw_card_font_dir() {
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'daw-fonts';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	return $dir;
}

/**
 * A TrueType file for one family and weight, optionally cut to some text.
 *
 * @return string path, or '' if it could not be fetched
 */
function daw_card_font( $family, $weight, $text = '' ) {
	$key  = md5( $family . '|' . $weight . '|' . $text );
	$path = daw_card_font_dir() . '/' . $key . '.ttf';

	if ( file_exists( $path ) && filesize( $path ) > 1000 ) {
		return $path;
	}

	$url = 'https://fonts.googleapis.com/css2?family=' . rawurlencode( $family ) . ':wght@' . (int) $weight;
	if ( '' !== $text ) {
		$url .= '&text=' . rawurlencode( $text );
	}

	// A user agent Google does not recognise as a modern browser is served
	// TrueType rather than WOFF2, which is the format GD can read.
	$css = wp_remote_get( $url, array( 'timeout' => 15, 'user-agent' => 'daw-card-generator/1.0' ) );
	if ( is_wp_error( $css ) || 200 !== (int) wp_remote_retrieve_response_code( $css ) ) {
		return '';
	}
	if ( ! preg_match( '#url\((https://fonts\.gstatic\.com/[^)]+)\)#', wp_remote_retrieve_body( $css ), $m ) ) {
		return '';
	}

	$ttf = wp_remote_get( $m[1], array( 'timeout' => 20 ) );
	if ( is_wp_error( $ttf ) || 200 !== (int) wp_remote_retrieve_response_code( $ttf ) ) {
		return '';
	}

	$body = wp_remote_retrieve_body( $ttf );
	// TrueType starts 00 01 00 00 or "true"; OpenType-CFF "OTTO" also works in FreeType.
	if ( strlen( $body ) < 1000 || ! in_array( substr( $body, 0, 4 ), array( "\x00\x01\x00\x00", 'true', 'OTTO' ), true ) ) {
		return '';
	}

	file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	return $path;
}

/** The watermark face for a piece of native text, by script. */
function daw_card_native_family( $text ) {
	if ( preg_match( '/[\x{3040}-\x{30FF}]/u', $text ) ) {
		return 'Noto Serif JP';
	}
	if ( preg_match( '/[\x{AC00}-\x{D7AF}\x{1100}-\x{11FF}]/u', $text ) ) {
		return 'Noto Serif KR';
	}
	if ( preg_match( '/[\x{0E00}-\x{0E7F}]/u', $text ) ) {
		return 'Noto Serif Thai';
	}
	if ( preg_match( '/\p{Han}/u', $text ) ) {
		return 'Noto Serif TC';
	}
	return '';
}

/* ================================================================== data === */

function daw_card_logo_path() {
	$id = (int) get_option( 'daw_logo_attachment_id', 0 );
	if ( ! $id ) {
		$found = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'DESC', 's' => 'asian-world-logo', 'fields' => 'ids' ) );
		$id    = $found ? (int) $found[0] : 0;
	}
	$path = $id ? get_attached_file( $id ) : '';
	return ( $path && file_exists( $path ) ) ? $path : '';
}

/** What goes on one story's card. */
function daw_card_data( $post_id ) {
	$kicker = trim( (string) get_post_meta( $post_id, 'daw_news_kicker', true ) );

	$country = '';
	$terms   = get_the_terms( $post_id, 'dw_country' );
	if ( $terms && ! is_wp_error( $terms ) ) {
		$country = $terms[0]->name;
	}

	// The watermark is the subject's own name in its own script: the first
	// record the story is about that has one. Long titles are left out; the
	// watermark is a texture, not a second headline.
	$native = '';
	foreach ( (array) get_post_meta( $post_id, 'daw_mentions' ) as $mid ) {
		foreach ( array( 'daw_native_name', 'daw_native_title' ) as $k ) {
			$v = trim( (string) get_post_meta( (int) $mid, $k, true ) );
			$v = trim( preg_replace( '/\s*[\(（].*$/u', '', $v ) );
			if ( '' !== $v && mb_strlen( $v ) <= 5 && '' !== daw_card_native_family( $v ) ) {
				$native = $v;
				break 2;
			}
		}
	}

	return array(
		'kicker'  => function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $kicker ) : strtoupper( $kicker ),
		'title'   => html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' ),
		'date'    => wp_date( 'j F Y', (int) get_post_time( 'U', true, $post_id ), new DateTimeZone( 'Asia/Seoul' ) ),
		'country' => $country,
		'native'  => $native,
	);
}

/* ================================================================ render === */

/** Width in pixels of text at a pixel size. */
function daw_card_tw( $px, $font, $text ) {
	$b = imagettfbbox( $px * 0.75, 0, $font, $text );
	return $b ? abs( $b[2] - $b[0] ) : 0;
}

/** Break a headline into lines no wider than $max. */
function daw_card_wrap( $px, $font, $text, $max ) {
	$words = preg_split( '/\s+/u', trim( $text ) );
	$lines = array();
	$cur   = '';
	foreach ( $words as $w ) {
		$try = '' === $cur ? $w : $cur . ' ' . $w;
		if ( daw_card_tw( $px, $font, $try ) <= $max || '' === $cur ) {
			$cur = $try;
		} else {
			$lines[] = $cur;
			$cur     = $w;
		}
	}
	if ( '' !== $cur ) {
		$lines[] = $cur;
	}
	return $lines;
}

/** Text with letter spacing, one glyph at a time. Returns the end x. */
function daw_card_tracked( $im, $px, $x, $y, $col, $font, $text, $track ) {
	foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
		imagettftext( $im, $px * 0.75, 0, (int) round( $x ), (int) $y, $col, $font, $ch );
		$x += daw_card_tw( $px, $font, $ch ) + $track;
	}
	return $x;
}

function daw_card_rgb( $im, $hex ) {
	$hex = ltrim( $hex, '#' );
	return imagecolorallocate( $im, hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
}

/**
 * Draw one card.
 *
 * @param string $style 'paper' (A) or 'seal' (B)
 * @return resource|\GdImage|WP_Error
 */
function daw_card_render( $post_id, $style = 'paper' ) {
	if ( ! daw_card_capable() ) {
		return new WP_Error( 'daw_card_gd', 'GD with FreeType is not available on this server.' );
	}

	$d = daw_card_data( $post_id );

	$f_head = daw_card_font( 'Newsreader', 600 );
	$f_ui   = daw_card_font( 'Bricolage Grotesque', 500 );
	$f_bold = daw_card_font( 'Bricolage Grotesque', 800 );
	if ( ! $f_head || ! $f_ui || ! $f_bold ) {
		return new WP_Error( 'daw_card_font', 'The card fonts could not be fetched from Google Fonts.' );
	}

	$f_nat = '';
	if ( '' !== $d['native'] ) {
		$f_nat = daw_card_font( daw_card_native_family( $d['native'] ), 700, $d['native'] );
	}

	$seal = 'seal' === $style;
	$W    = DAW_CARD_W;
	$H    = DAW_CARD_H;
	$im   = imagecreatetruecolor( $W, $H );
	imagealphablending( $im, true );

	$bg    = daw_card_rgb( $im, $seal ? '7E2128' : 'FAF7F2' );
	$ink   = daw_card_rgb( $im, $seal ? 'FAF7F2' : '1C1D21' );
	$muted = daw_card_rgb( $im, $seal ? 'E8D2C8' : '6B655B' );
	$gold  = daw_card_rgb( $im, $seal ? 'C9971F' : 'A9741A' );
	$rule  = daw_card_rgb( $im, 'C9971F' );
	$mark  = daw_card_rgb( $im, $seal ? '8C2A32' : 'F0E8DC' );
	$accent = daw_card_rgb( $im, '7E2128' );

	imagefilledrectangle( $im, 0, 0, $W, $H, $bg );

	// Watermark first, so everything else sits on top of it.
	// It has a box of its own to the right of the headline column and is
	// shrunk to fit it, so a three-syllable name never runs under the text.
	if ( $f_nat ) {
		$box_w = 400;
		$box_h = 250;
		$px    = 250;
		do {
			$b  = imagettfbbox( $px * 0.75, 0, $f_nat, $d['native'] );
			$tw = abs( $b[2] - $b[0] );
			$th = abs( $b[1] - $b[7] );
			if ( $tw <= $box_w && $th <= $box_h ) {
				break;
			}
			$px -= 10;
		} while ( $px > 80 );
		$x = $W - 44 - $tw - $b[0];
		$y = $H - 100 - $b[1]; // above the foot line on both styles
		imagettftext( $im, $px * 0.75, 0, (int) $x, (int) $y, $mark, $f_nat, $d['native'] );
	}

	if ( $seal ) {
		imagesetthickness( $im, 2 );
		imagerectangle( $im, 22, 22, $W - 23, $H - 23, $rule );
	} else {
		imagefilledrectangle( $im, 0, 0, $W, 9, $accent );
		$band = daw_card_rgb( $im, 'F3EEE5' );
		imagefilledrectangle( $im, 0, $H - 78, $W, $H, $band );
		imagefilledrectangle( $im, 0, $H - 79, $W, $H - 78, daw_card_rgb( $im, 'E9E2D8' ) );
	}

	// The logo: the same file the header prints.
	$lx = 58;
	$ly = 44;
	$ls = 72; // the file has a transparent margin; the header scales it up the same way
	$logo = daw_card_logo_path();
	if ( $logo ) {
		$src = imagecreatefromstring( (string) file_get_contents( $logo ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $src ) {
			imagecopyresampled( $im, $src, $lx, $ly, 0, 0, $ls, $ls, imagesx( $src ), imagesy( $src ) );
			imagedestroy( $src );
		}
	}

	// The wordmark, as the header sets it: DISCOVER small and tracked, ASIAN
	// WORLD in the heavy weight.
	$wx = $lx + $ls + 8;
	daw_card_tracked( $im, 13, $wx, $ly + 30, $muted, $f_ui, 'DISCOVER', 3 );
	imagettftext( $im, 25 * 0.75, 0, $wx, $ly + 57, $ink, $f_bold, 'ASIAN WORLD' );

	// Kicker.
	$ky = 186;
	if ( '' !== $d['kicker'] ) {
		$end = daw_card_tracked( $im, 20, 64, $ky, $gold, $f_bold, $d['kicker'], 1.5 );
		if ( ! $seal ) {
			imagefilledrectangle( $im, 64, $ky + 12, (int) min( $end, 64 + 120 ), $ky + 14, $rule );
		}
	}

	// Headline: as large as it can be while fitting four lines.
	$max   = $f_nat ? 660 : 1000; // 64 + 660 stays clear of the watermark box
	$px    = 56;
	$lines = daw_card_wrap( $px, $f_head, $d['title'], $max );
	while ( count( $lines ) > 4 && $px > 36 ) {
		$px   -= 4;
		$lines = daw_card_wrap( $px, $f_head, $d['title'], $max );
	}
	$lh = (int) round( $px * 1.24 );
	$y  = $ky + 36 + $px;
	foreach ( array_slice( $lines, 0, 5 ) as $ln ) {
		imagettftext( $im, $px * 0.75, 0, 64, $y, $ink, $f_head, $ln );
		$y += $lh;
	}

	// Foot: date and country, and the address.
	$fy   = $seal ? $H - 64 : $H - 30;
	$foot = $d['date'] . ( '' !== $d['country'] ? '   ·   ' . $d['country'] : '' );
	imagettftext( $im, 21 * 0.75, 0, 64, $fy, $muted, $f_ui, $foot );
	$site = 'discoverasianworld.com';
	imagettftext( $im, 21 * 0.75, 0, $W - 64 - daw_card_tw( 21, $f_ui, $site ), $fy, $seal ? $gold : $accent, $f_ui, $site );

	return $im;
}

/* ================================================================= apply === */

function daw_card_style_for( $post_id ) {
	$own = (string) get_post_meta( $post_id, 'daw_card_style', true );
	if ( in_array( $own, array( 'paper', 'seal' ), true ) ) {
		return $own;
	}
	$def = (string) get_option( 'daw_card_style', 'paper' );
	return in_array( $def, array( 'paper', 'seal' ), true ) ? $def : 'paper';
}

/**
 * Draw the card and make it the story's featured image.
 *
 * @return int|WP_Error attachment id
 */
function daw_card_apply( $post_id, $force = false ) {
	$style = daw_card_style_for( $post_id );
	$hash  = md5( wp_json_encode( array( daw_card_data( $post_id ), $style, DAW_CARD_REV ) ) );
	$att   = (int) get_post_meta( $post_id, '_daw_card_att', true );

	if ( ! $force && $att && get_post( $att ) && get_post_meta( $post_id, '_daw_card_hash', true ) === $hash && (int) get_post_thumbnail_id( $post_id ) === $att ) {
		return $att;
	}

	$im = daw_card_render( $post_id, $style );
	if ( is_wp_error( $im ) ) {
		return $im;
	}

	$up   = wp_upload_dir();
	$dir  = trailingslashit( $up['basedir'] ) . 'daw-cards';
	wp_mkdir_p( $dir );
	$name = 'news-card-' . (int) $post_id . '-' . substr( $hash, 0, 8 ) . '.jpg';
	$path = $dir . '/' . $name;

	imagejpeg( $im, $path, 86 );
	imagedestroy( $im );

	$rel = 'daw-cards/' . $name;

	if ( $att && get_post( $att ) ) {
		// Same attachment, new file: the old file was drawn by this function
		// and is removed with it, so regenerating never piles up copies.
		$old = get_attached_file( $att );
		if ( $old && $old !== $path && false !== strpos( $old, '/daw-cards/news-card-' . (int) $post_id . '-' ) && file_exists( $old ) ) {
			wp_delete_file( $old );
		}
		update_attached_file( $att, $path );
	} else {
		$att = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => sprintf( 'News card: %s', get_the_title( $post_id ) ),
				'post_status'    => 'inherit',
			),
			$path,
			$post_id
		);
		if ( is_wp_error( $att ) || ! $att ) {
			return is_wp_error( $att ) ? $att : new WP_Error( 'daw_card_att', 'The card could not be added to the media library.' );
		}
	}

	// One file, no intermediate sizes: the card is already the size it is
	// shown at, and every extra size is another file to keep in step.
	wp_update_attachment_metadata( $att, array( 'width' => DAW_CARD_W, 'height' => DAW_CARD_H, 'file' => $rel, 'sizes' => array() ) );
	update_post_meta( $att, '_wp_attachment_image_alt', get_the_title( $post_id ) );
	update_post_meta( $att, 'daw_lic_type', 'own' );
	update_post_meta( $att, '_daw_card_style', $style );
	update_post_meta( $att, 'daw_lic_retrieved', gmdate( 'Y-m-d' ) );

	set_post_thumbnail( $post_id, $att );
	update_post_meta( $post_id, '_daw_card_att', $att );
	update_post_meta( $post_id, '_daw_card_hash', $hash );

	return (int) $att;
}

/**
 * Should this story carry a card?
 *
 * Yes when it has no picture, when its picture is our card (so a new headline
 * redraws it), and when its picture has no rights record. No when it has a
 * recorded, allowed picture: official press material is never overwritten.
 */
function daw_card_wanted( $post_id ) {
	if ( ! function_exists( 'daw_get_type' ) || 'news' !== daw_get_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
		return false;
	}
	$thumb = (int) get_post_thumbnail_id( $post_id );
	if ( ! $thumb ) {
		return true;
	}
	if ( $thumb === (int) get_post_meta( $post_id, '_daw_card_att', true ) ) {
		return true;
	}
	if ( ! get_option( 'daw_card_replace_unrecorded', 1 ) ) {
		return false;
	}
	return function_exists( 'daw_lic_status' ) ? 'ok' !== daw_lic_status( $thumb, $post_id ) : true;
}

add_action( 'save_post_post', 'daw_card_on_save', 40, 2 );
function daw_card_on_save( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! daw_card_capable() ) {
		return;
	}
	if ( daw_card_wanted( $post_id ) ) {
		daw_card_apply( $post_id );
	}
}

/* ================================================================ screen === */

add_action( 'admin_menu', 'daw_card_menu' );
function daw_card_menu() {
	add_submenu_page( 'daw', __( 'Haber kartları', 'daw' ), __( 'Haber kartları', 'daw' ), 'manage_options', 'daw-cards', 'daw_card_page' );
}

/** Stream a preview without saving anything. */
add_action( 'admin_post_daw_card_preview', 'daw_card_preview' );
function daw_card_preview() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'daw_card_preview' ) ) {
		wp_die( 'No.' );
	}
	$pid   = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
	$style = isset( $_GET['style'] ) && 'seal' === $_GET['style'] ? 'seal' : 'paper';
	$im    = daw_card_render( $pid, $style );
	if ( is_wp_error( $im ) ) {
		wp_die( esc_html( $im->get_error_message() ) );
	}
	nocache_headers();
	header( 'Content-Type: image/png' );
	imagepng( $im );
	imagedestroy( $im );
	exit;
}

add_action( 'admin_post_daw_card_settings', 'daw_card_settings' );
function daw_card_settings() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'daw_card_settings' ) ) {
		wp_die( 'No.' );
	}
	$style = isset( $_POST['style'] ) && 'seal' === $_POST['style'] ? 'seal' : 'paper';
	update_option( 'daw_card_style', $style, false );
	update_option( 'daw_card_replace_unrecorded', empty( $_POST['replace'] ) ? 0 : 1, false );

	$done = 0;
	$fail = '';
	if ( ! empty( $_POST['apply'] ) ) {
		foreach ( daw_audit_record_ids( array( 'news' ) ) as $pid ) {
			if ( ! daw_card_wanted( $pid ) ) {
				continue;
			}
			$r = daw_card_apply( $pid, true );
			if ( is_wp_error( $r ) ) {
				$fail = $r->get_error_message();
				break;
			}
			$done++;
		}
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'daw-cards', 'done' => $done, 'fail' => rawurlencode( $fail ) ), admin_url( 'admin.php' ) ) );
	exit;
}

function daw_card_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="wrap"><h1>News cards</h1>';

	if ( isset( $_GET['done'] ) ) {
		printf( '<div class="notice notice-success"><p>%d cards drawn.</p></div>', (int) $_GET['done'] );
	}
	if ( ! empty( $_GET['fail'] ) ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( rawurldecode( (string) $_GET['fail'] ) ) );
	}

	$ok = daw_card_capable();
	echo '<table class="widefat" style="max-width:44em"><tbody>';
	printf( '<tr><td>GD with FreeType</td><td>%s</td></tr>', $ok ? '<span style="color:#2a7">available</span>' : '<span style="color:#d35">missing — cards cannot be drawn</span>' );
	printf( '<tr><td>Logo file</td><td>%s</td></tr>', daw_card_logo_path() ? '<span style="color:#2a7">found</span>' : '<span style="color:#d35">not found</span>' );
	if ( $ok ) {
		foreach ( array( array( 'Newsreader', 600 ), array( 'Bricolage Grotesque', 500 ), array( 'Bricolage Grotesque', 800 ) ) as $f ) {
			printf( '<tr><td>%s %d</td><td>%s</td></tr>', esc_html( $f[0] ), (int) $f[1], daw_card_font( $f[0], $f[1] ) ? '<span style="color:#2a7">ready</span>' : '<span style="color:#d35">could not be fetched</span>' );
		}
	}
	echo '</tbody></table>';

	$latest = get_posts( array( 'post_type' => 'post', 'posts_per_page' => 1, 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => 'dw_type', 'field' => 'slug', 'terms' => 'news' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	$pid    = isset( $_GET['post'] ) ? (int) $_GET['post'] : ( $latest ? (int) $latest[0] : 0 );

	if ( $ok && $pid ) {
		printf( '<h2>Preview: %s</h2>', esc_html( get_the_title( $pid ) ) );
		echo '<p>Drawn now from the story\'s own data, with the real logo and fonts. Nothing is saved until you press the button below. Add <code>&amp;post=ID</code> to the address to preview another story.</p>';
		echo '<div style="display:flex;flex-wrap:wrap;gap:20px">';
		foreach ( array( 'paper' => 'A — Paper', 'seal' => 'B — Seal' ) as $st => $label ) {
			$src = wp_nonce_url( admin_url( 'admin-post.php?action=daw_card_preview&post=' . $pid . '&style=' . $st ), 'daw_card_preview' );
			printf( '<figure style="margin:0"><img src="%s" width="560" style="border:1px solid #ddd;display:block"><figcaption><strong>%s</strong></figcaption></figure>', esc_url( $src ), esc_html( $label ) );
		}
		echo '</div>';
	}

	$cur = (string) get_option( 'daw_card_style', 'paper' );
	echo '<h2>Settings</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'daw_card_settings' );
	echo '<input type="hidden" name="action" value="daw_card_settings">';
	printf( '<p><label><input type="radio" name="style" value="paper" %s> A — Paper</label> &nbsp; <label><input type="radio" name="style" value="seal" %s> B — Seal</label></p>', checked( 'paper', $cur, false ), checked( 'seal', $cur, false ) );
	printf( '<p><label><input type="checkbox" name="replace" value="1" %s> Replace news images that have no rights record (recorded press material is never replaced)</label></p>', checked( (bool) get_option( 'daw_card_replace_unrecorded', 1 ), true, false ) );
	echo '<p><label><input type="checkbox" name="apply" value="1"> Draw cards for every news story now</label></p>';
	submit_button( 'Save' );
	echo '</form>';
	echo '<p style="max-width:56em;color:#666">A single story can use the other style: set its <code>daw_card_style</code> field to <code>paper</code> or <code>seal</code>. The pictures a card replaces stay in the media library, listed under Tools › DAW: Image rights, for you to delete.</p>';
	echo '</div>';
}


/* ============================================================== cropping === */

/**
 * WHY CARDS ARE NEVER CROPPED
 * ---------------------------
 * The theme shows one picture in many boxes: 3:2 on the story, 16:9 under
 * "more news", 1:1 on group pages, 2:3 in the browse grids, all with
 * object-fit: cover. That is right for a photograph and wrong for a card, whose
 * headline is part of the picture: a square crop of a 16:9 card cuts every
 * line in half. So a card is shown whole, on its own background colour, and
 * the box around it simply becomes a tile of the same colour.
 *
 * Photographs keep cover, but aim it: a person's face sits in the upper part
 * of most photos, and the centre crop the browser picks by default takes the
 * top of the head off in a square. An image can carry its own focus point
 * (daw_focus, e.g. "50% 25%"); without one, photos lean upward.
 */
add_filter( 'wp_get_attachment_image_attributes', 'daw_card_img_attrs', 20, 2 );
function daw_card_img_attrs( $attr, $att ) {
	$id    = is_object( $att ) ? (int) $att->ID : (int) $att;
	$style = (string) get_post_meta( $id, '_daw_card_style', true );

	if ( '' !== $style ) {
		$attr['class'] = trim( ( $attr['class'] ?? '' ) . ' daw-is-card daw-is-card--' . sanitize_html_class( $style ) );
		return $attr;
	}

	$focus = trim( (string) get_post_meta( $id, 'daw_focus', true ) );
	if ( '' !== $focus && preg_match( '/^\d{1,3}%\s+\d{1,3}%$/', $focus ) ) {
		$attr['style'] = trim( ( $attr['style'] ?? '' ) . ' object-position:' . $focus . ';' );
	}

	return $attr;
}

/* ======================================================= news freshness === */

/**
 * News stories whose facts may have moved on.
 *
 * Two signals, both from the story's own data rather than a guess:
 *   - a timeline row dated after the story was last updated, whose date has
 *     now passed: something the story expected has happened, or has not;
 *   - no update in 60 days on a story that still has a future-looking row, or
 *     no update in 180 days at all.
 * Every one of the 27 news stories audited in September had gone out of date
 * in exactly these ways: a shoot finished, a case decided, a drama aired.
 */
function daw_news_recheck() {
	// 1.75.0: living stories (news-live.php) have their own, wider rules.
	if ( function_exists( 'daw_news_reasons' ) ) {
		$out = array();
		foreach ( daw_audit_record_ids( array( 'news' ) ) as $pid ) {
			$why = daw_news_reasons( (int) $pid );
			if ( $why ) {
				$out[] = array(
					'id'     => (int) $pid,
					'title'  => wp_specialchars_decode( get_the_title( $pid ), ENT_QUOTES ),
					'reason' => implode( '; ', $why ),
					'url'    => get_permalink( $pid ),
				);
			}
		}
		return $out;
	}
	$out = array();
	$now = time();

	foreach ( daw_audit_record_ids( array( 'news' ) ) as $pid ) {
		$mod   = (int) get_post_modified_time( 'U', true, $pid );
		$dates = array_filter( array_map( 'intval', (array) get_post_meta( $pid, 'daw_news_tl_date', true ) ) );

		$reason = '';
		foreach ( $dates as $d ) {
			if ( $d > $mod && $d <= $now ) {
				$reason = sprintf( 'timeline date %s has passed since the last update', gmdate( 'j M Y', $d ) );
				break;
			}
		}
		if ( '' === $reason ) {
			$future = array_filter( $dates, static fn( $d ) => $d > $now );
			$age    = (int) floor( ( $now - $mod ) / DAY_IN_SECONDS );
			if ( $future && $age > 60 ) {
				$reason = sprintf( 'looks ahead but not updated for %d days', $age );
			} elseif ( $age > 180 ) {
				$reason = sprintf( 'not updated for %d days', $age );
			}
		}

		if ( '' !== $reason ) {
			$out[] = array(
				'id'     => (int) $pid,
				'title'  => get_the_title( $pid ),
				'reason' => $reason,
				'url'    => get_permalink( $pid ),
			);
		}
	}

	return $out;
}
