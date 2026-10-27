<?php
/**
 * Discover Asian World: no broken picture on any page.
 *
 * Every published page is visited in turn (40 an hour, the whole site in
 * about a day and a half) and each picture it shows is asked for: the
 * record's poster or portrait (TMDB, the backup copy or the media library)
 * and every <img> in an article's text. A picture that does not answer 200:
 *
 * - from TMDB: the record's picture is fetched again from TMDB at once (TMDB
 *   sometimes replaces a file); if the new one fails too, it is listed;
 * - from the media library: the file is looked for on disk; missing is listed;
 * - inside article text: listed with the page, for a person to replace.
 *
 * The list goes to the control centre's to-do list and the chat's report,
 * and a picture leaves it the moment it answers again.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'royal_mcp_readable_options', 'daw_ick_readable' );
function daw_ick_readable( $keys ) {
	$keys   = (array) $keys;
	$keys[] = 'daw_imgchk';
	return array_values( array_unique( $keys ) );
}

add_action( 'daw_fix_tick', 'daw_ick_run', 45 );

/** 200 or the failure code for one picture url. */
function daw_ick_probe( $url ) {
	static $memo = array();
	if ( isset( $memo[ $url ] ) ) {
		return $memo[ $url ];
	}
	$up = wp_get_upload_dir();
	if ( 0 === strpos( $url, (string) $up['baseurl'] ) ) {
		$file           = $up['basedir'] . substr( strtok( $url, '?' ), strlen( (string) $up['baseurl'] ) );
		$memo[ $url ] = file_exists( $file ) && filesize( $file ) > 0 ? 200 : 404;
		return $memo[ $url ];
	}
	$res = wp_remote_get(
		$url,
		array(
			'timeout'             => 10,
			'redirection'         => 2,
			'limit_response_size' => 1024,
			'user-agent'          => 'Mozilla/5.0 (compatible; DAW-Image-Check/1.0; +' . home_url( '/' ) . ')',
		)
	);
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	if ( 0 === $code || $code >= 500 ) {
		// No answer or a server hiccup is not a broken picture yet: one more try, slower.
		sleep( 1 );
		$res  = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 2, 'limit_response_size' => 1024 ) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	}
	if ( 200 === $code ) {
		$type = (string) wp_remote_retrieve_header( $res, 'content-type' );
		if ( '' !== $type && 0 !== strpos( $type, 'image/' ) ) {
			$code = 415; // answered, but not with a picture
		}
	}
	$memo[ $url ] = $code;
	return $code;
}

/** Every picture a page shows: [ [url, where], ... ]. */
function daw_ick_urls( $pid ) {
	$out = array();
	if ( function_exists( 'daw_record_image_url' ) && function_exists( 'daw_has_record_image' ) && daw_has_record_image( $pid ) ) {
		$u = (string) daw_record_image_url( $pid, 'medium' );
		if ( '' !== $u ) {
			$out[] = array( $u, 'record' );
		}
	} elseif ( has_post_thumbnail( $pid ) ) {
		$u = (string) get_the_post_thumbnail_url( $pid, 'large' );
		if ( '' !== $u ) {
			$out[] = array( $u, 'featured' );
		}
	}
	$html = (string) get_post_field( 'post_content', $pid );
	if ( '' !== $html && preg_match_all( '#<img[^>]+src=["\']([^"\']+)["\']#i', $html, $m ) ) {
		foreach ( array_unique( $m[1] ) as $src ) {
			if ( 0 === strpos( $src, 'data:' ) ) {
				continue;
			}
			$out[] = array( 0 === strpos( $src, '//' ) ? 'https:' . $src : ( 0 === strpos( $src, '/' ) ? home_url( $src ) : $src ), 'content' );
		}
	}
	return $out;
}

function daw_ick_run( $batch = 40 ) {
	$st  = get_option( 'daw_imgchk', array() );
	$st  = is_array( $st ) ? $st : array();
	$bad = (array) ( $st['bad'] ?? array() );
	$ids = get_posts(
		array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);
	if ( ! $ids ) {
		return $st;
	}
	$pos   = (int) ( $st['pos'] ?? 0 );
	$pos   = $pos >= count( $ids ) ? 0 : $pos;
	$slice = array_slice( $ids, $pos, $batch );
	$pos  += count( $slice );
	if ( $pos >= count( $ids ) ) {
		$pos            = 0;
		$st['lap_done'] = time();
	}

	foreach ( $slice as $pid ) {
		$pid = (int) $pid;
		unset( $bad[ $pid ] );
		foreach ( daw_ick_urls( $pid ) as $u ) {
			list( $url, $where ) = $u;
			$code = daw_ick_probe( $url );
			if ( 200 === $code ) {
				continue;
			}
			if ( 'record' === $where && false !== strpos( $url, 'image.tmdb.org' ) && function_exists( 'daw_tmdb_img_sync' ) ) {
				// TMDB may have replaced the file: ask for the current one once.
				daw_tmdb_img_sync( $pid );
				$again = function_exists( 'daw_record_image_url' ) ? (string) daw_record_image_url( $pid, 'medium' ) : '';
				if ( '' === $again || 200 === daw_ick_probe( $again ) ) {
					if ( function_exists( 'daw_fix_log' ) ) {
						daw_fix_log( 'img', sprintf( '"%s" görseli kırıktı; TMDB\'den yenisi alındı', get_the_title( $pid ) ), $pid );
					}
					continue;
				}
				$url = $again;
			}
			$bad[ $pid ] = array( $url, $where, $code, time() );
			break;
		}
	}

	$st = array(
		'at'       => time(),
		'pos'      => $pos,
		'total'    => count( $ids ),
		'lap_done' => (int) ( $st['lap_done'] ?? 0 ),
		'bad'      => $bad,
	);
	update_option( 'daw_imgchk', $st, false );
	return $st;
}

/** Report rows for the control centre: [pid, title, url, where, code]. */
function daw_ick_rows() {
	$st   = get_option( 'daw_imgchk', array() );
	$rows = array();
	foreach ( (array) ( $st['bad'] ?? array() ) as $pid => $b ) {
		if ( 'publish' !== get_post_status( (int) $pid ) ) {
			continue;
		}
		$rows[] = array( (int) $pid, get_the_title( (int) $pid ), (string) $b[0], (string) $b[1], (int) $b[2] );
	}
	return $rows;
}

/**
 * 1.70.0: the match check now accepts a hand-confirmed entry when TMDB has
 * nothing to compare (no birthday, no native title). Records waiting on
 * that are checked again once.
 */
add_action( 'daw_fix_tick', 'daw_ick_recheck_confirmed', 4 );
function daw_ick_recheck_confirmed() {
	if ( '1' === (string) get_option( 'daw_ick_recheck_v' ) || ! function_exists( 'daw_tmdb_img_sync' ) || ! defined( 'DAW_TMDB_CHECK' ) ) {
		return;
	}
	update_option( 'daw_ick_recheck_v', '1', false );
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ('mismatch','unverified')", DAW_TMDB_CHECK ) ); // phpcs:ignore WordPress.DB
	foreach ( array_map( 'intval', (array) $ids ) as $id ) {
		$ok = get_post_meta( $id, '_daw_ok', true );
		if ( is_array( $ok ) && ! empty( $ok['tmdb_match'] ) ) {
			daw_tmdb_img_sync( $id );
		}
	}
}
