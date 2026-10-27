<?php
/**
 * Discover Asian World — posters and portraits served from TMDB, not hosted here.
 *
 * WHY THE EARLIER DECISION WAS REVERSED
 * -------------------------------------
 * tmdb.php downloads each poster into the media library, and its header says
 * why: hot-linking sends every reader's IP to someone else's server, and every
 * poster disappears the day that server says no. Both are true.
 *
 * The owner weighed them against a third thing and chose differently on 23
 * September 2026: a poster copied onto this server is a copy this site made
 * and distributes. Shown from TMDB's own server, the file is never copied
 * here, which in the courts that follow the "server test" (the site's host is
 * in the US) is a materially weaker claim against the site. It is not a
 * licence and not a guarantee — some courts reject that test — but together
 * with a small single poster used to identify the work, a rights-holder credit
 * and a working takedown process, it makes a removal request the realistic
 * worst case.
 *
 * WHAT THIS DOES
 * --------------
 * Any attachment the TMDB importer made (it records _daw_source = TMDB and the
 * original URL) is shown from image.tmdb.org at the size the page asks for.
 * The local file is left where it is, so the switch can be turned off and the
 * site goes back to its own copies instantly. When the owner is satisfied,
 * the local copies can be removed.
 *
 * The privacy cost is real and is stated in the privacy policy: a reader's
 * browser requests the picture from TMDB's CDN.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

function daw_tmdb_hotlink_on() {
	// With TMDB as the picture source, any copy still in the media library is
	// shown from TMDB too.
	return (bool) get_option( 'daw_tmdb_hotlink', 0 ) || ( function_exists( 'daw_img_source' ) && 'tmdb' === daw_img_source() );
}

/** The TMDB file name for an attachment the importer made, or ''. */
function daw_tmdb_file( $att_id ) {
	static $memo = array();
	$att_id = (int) $att_id;
	if ( isset( $memo[ $att_id ] ) ) {
		return $memo[ $att_id ];
	}
	$file = '';
	if ( 'TMDB' === (string) get_post_meta( $att_id, '_daw_source', true ) ) {
		$src = (string) get_post_meta( $att_id, '_daw_source_url', true );
		if ( preg_match( '#image\.tmdb\.org/t/p/[^/]+/([A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp))#i', $src, $m ) ) {
			$file = $m[1];
		}
	}
	$memo[ $att_id ] = $file;
	return $file;
}

/** TMDB's own sizes: pick the smallest that covers the width asked for. */
function daw_tmdb_size_for( $w ) {
	foreach ( array( 92, 154, 185, 342, 500, 780 ) as $s ) {
		if ( $w <= $s ) {
			return 'w' . $s;
		}
	}
	return 'w780';
}

/** Width the theme is asking for, from a size name or [w,h]. */
function daw_tmdb_requested_width( $size ) {
	if ( is_array( $size ) ) {
		return (int) ( $size[0] ?? 500 );
	}
	$sizes = wp_get_registered_image_subsizes();
	if ( isset( $sizes[ $size ]['width'] ) && $sizes[ $size ]['width'] > 0 ) {
		return (int) $sizes[ $size ]['width'];
	}
	return 'full' === $size ? 780 : 500;
}

add_filter( 'wp_get_attachment_image_src', 'daw_tmdb_image_src', 20, 4 );
function daw_tmdb_image_src( $image, $att_id, $size, $icon ) {
	if ( ! daw_tmdb_hotlink_on() || is_admin() ) {
		return $image;
	}
	$file = daw_tmdb_file( $att_id );
	if ( '' === $file || ! $image ) {
		return $image;
	}
	$want = daw_tmdb_requested_width( $size );
	$tsz  = daw_tmdb_size_for( $want );
	$w    = (int) substr( $tsz, 1 );
	// Keep the stored aspect ratio so the box is drawn before the file arrives.
	$meta = wp_get_attachment_metadata( $att_id );
	$h    = ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) ? (int) round( $w * $meta['height'] / $meta['width'] ) : (int) round( $w * 1.5 );
	return array( 'https://image.tmdb.org/t/p/' . $tsz . '/' . $file, $w, $h, false );
}

add_filter( 'wp_get_attachment_url', 'daw_tmdb_hotlink_url', 20, 2 );
function daw_tmdb_hotlink_url( $url, $att_id ) {
	if ( ! daw_tmdb_hotlink_on() || is_admin() ) {
		return $url;
	}
	$file = daw_tmdb_file( $att_id );
	return '' === $file ? $url : 'https://image.tmdb.org/t/p/w780/' . $file;
}

// A srcset would point at our local sizes; the TMDB picture carries its own.
add_filter( 'wp_calculate_image_srcset', 'daw_tmdb_no_srcset', 20, 5 );
function daw_tmdb_no_srcset( $sources, $size_array, $image_src, $image_meta, $att_id ) {
	if ( daw_tmdb_hotlink_on() && '' !== daw_tmdb_file( $att_id ) ) {
		return false;
	}
	return $sources;
}

/** Warm the connection to TMDB's CDN on pages that will use it. */
add_filter( 'wp_resource_hints', 'daw_tmdb_hints', 10, 2 );
function daw_tmdb_hints( $urls, $rel ) {
	if ( 'preconnect' === $rel && daw_tmdb_hotlink_on() ) {
		$urls[] = array( 'href' => 'https://image.tmdb.org', 'crossorigin' => 'anonymous' );
	}
	return $urls;
}

/**
 * The credit under a TMDB picture. Posters belong to whoever made the work;
 * where the record names a production company it is named, otherwise the
 * credit says the rights holder generically. TMDB is named as where the image
 * is served from, which is also what its API terms ask for.
 */
function daw_tmdb_credit( $post_id ) {
	$att = (int) get_post_thumbnail_id( $post_id );
	if ( ! $att || '' === daw_tmdb_file( $att ) ) {
		return '';
	}
	$who = '';
	foreach ( (array) get_post_meta( $post_id, 'daw_production', true ) as $p ) {
		$p = trim( wp_strip_all_tags( (string) $p ) );
		if ( '' !== $p ) {
			$who = $p;
			break;
		}
	}
	$is_person = function_exists( 'daw_get_type' ) && in_array( daw_get_type( $post_id ), array( 'person', 'group' ), true );
	$what      = $is_person ? __( 'Photo', 'daw' ) : __( 'Poster', 'daw' );
	return sprintf(
		/* translators: 1: Poster/Photo, 2: rights holder */
		__( '%1$s © %2$s. Image via TMDB.', 'daw' ),
		$what,
		'' !== $who ? $who : __( 'the rights holder', 'daw' )
	);
}

/* ================================================================ settings === */

add_action( 'daw_lic_page_after', 'daw_tmdb_hotlink_panel', 5 );
function daw_tmdb_hotlink_panel() {
	global $wpdb;
	$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_daw_source' AND meta_value = 'TMDB'" ); // phpcs:ignore WordPress.DB
	echo '<h2>Posters and portraits from TMDB</h2>';
	printf( '<p style="max-width:56em"><strong>%d</strong> images came from TMDB. They are not licensed: TMDB does not own them, and a credit does not change that. With the switch below on, they are shown from TMDB\'s own server instead of from copies on this one, with a rights-holder credit. The local copies stay until you delete them, so switching off restores them at once.</p>', (int) $n );
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'daw_tmdb_hotlink' );
	echo '<input type="hidden" name="action" value="daw_tmdb_hotlink">';
	printf( '<p><label><input type="checkbox" name="on" value="1" %s> Serve TMDB images from TMDB (not from this server)</label></p>', checked( daw_tmdb_hotlink_on(), true, false ) );
	submit_button( 'Save', 'secondary', 'submit', false );
	echo '</form>';
}

add_action( 'admin_post_daw_tmdb_hotlink', 'daw_tmdb_hotlink_save' );
function daw_tmdb_hotlink_save() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'daw_tmdb_hotlink' ) ) {
		wp_die( 'No.' );
	}
	update_option( 'daw_tmdb_hotlink', empty( $_POST['on'] ) ? 0 : 1, false );
	wp_safe_redirect( admin_url( 'admin.php?page=daw-images' ) );
	exit;
}

/**
 * The one credit line for a record's main picture, whatever its origin:
 * the rights register first (Commons, KOGL, our own), TMDB second.
 */
function daw_image_credit_for_post( $post_id ) {
	if ( function_exists( 'daw_remote_mode_for' ) ) {
		$mode = daw_remote_mode_for( $post_id );
		// Pictures shown from TMDB are credited once, in the footer, as the
		// owner asked; the line under each picture is not repeated.
		if ( in_array( $mode, array( 'remote', 'vault', 'monogram' ), true ) ) {
			return '';
		}
	}
	$att = (int) get_post_thumbnail_id( $post_id );
	if ( ! $att ) {
		return '';
	}
	if ( function_exists( 'daw_lic_credit_html' ) ) {
		$c = daw_lic_credit_html( $att );
		if ( '' !== $c ) {
			return $c;
		}
	}
	return esc_html( daw_tmdb_credit( $post_id ) );
}
