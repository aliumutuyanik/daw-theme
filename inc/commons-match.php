<?php
/**
 * Discover Asian World: find a picture's licence on Wikimedia Commons.
 *
 * Ten food guide photos (October 2026) came from Commons but their licence
 * was never written down, so the rights register reports them as
 * unregistered. Commons can be asked for a file by the SHA-1 of its bytes:
 * an exact match is the same file, not a look-alike. When one is found and
 * its licence is one the site may use (CC0, public domain, CC BY, CC BY-SA),
 * the record is filled from Commons' own metadata: author, licence and
 * version, file page, date. NC and ND licences, or no exact match, leave the
 * picture unregistered for a person to replace.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

add_action( 'daw_fix_tick', 'daw_cm_run', 30 );
function daw_cm_run( $max = 12 ) {
	if ( ! function_exists( 'daw_lic_census' ) || ! function_exists( 'daw_lic_keys' ) ) {
		return;
	}
	$done = 0;
	foreach ( (array) ( daw_lic_census()['rows'] ?? array() ) as $r ) {
		if ( $done >= $max || 'unregistered' !== ( $r['status'] ?? '' ) || empty( $r['att'] ) ) {
			continue;
		}
		$att = (int) $r['att'];
		if ( (int) get_post_meta( $att, '_daw_cm_at', true ) > time() - 7 * DAY_IN_SECONDS ) {
			continue;
		}
		++$done;
		update_post_meta( $att, '_daw_cm_at', time() );
		$res = daw_cm_match( $att );
		if ( is_array( $res ) && function_exists( 'daw_fix_log' ) ) {
			daw_fix_log( 'img', sprintf( 'Görsel lisansı Commons\'tan bulundu: %s (%s)', get_the_title( (int) $r['post'] ), $res['label'] ), (int) $r['post'] );
		}
	}
}

/** The licence types the register knows, from Commons' LicenseShortName. */
function daw_cm_licence( $short ) {
	$s = strtolower( trim( (string) $short ) );
	if ( preg_match( '/(^|[^a-z])(nc|nd)([^a-z]|$)/', $s ) ) {
		return null;
	}
	if ( preg_match( '/^cc[- ]?zero|^cc0/', $s ) ) {
		return array( 'cc0', '' );
	}
	if ( 0 === strpos( $s, 'public domain' ) || 'pd' === $s ) {
		return array( 'pd', '' );
	}
	if ( preg_match( '/^cc by-sa ([0-9.]+)/', $s, $m ) ) {
		return array( 'cc-by-sa', $m[1] );
	}
	if ( preg_match( '/^cc by ([0-9.]+)/', $s, $m ) ) {
		return array( 'cc-by', $m[1] );
	}
	return null;
}

function daw_cm_api( array $q ) {
	$res = wp_remote_get(
		add_query_arg( array_map( 'rawurlencode', array_merge( $q, array( 'format' => 'json', 'formatversion' => 2 ) ) ), 'https://commons.wikimedia.org/w/api.php' ),
		array(
			'timeout' => 15,
			'headers' => array( 'User-Agent' => 'DiscoverAsianWorld/1.0 (' . home_url( '/' ) . '; licence check)' ),
		)
	);
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	return json_decode( (string) wp_remote_retrieve_body( $res ), true );
}

/** @return array{label:string}|null */
function daw_cm_match( $att ) {
	$path = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $att ) : get_attached_file( $att );
	if ( ! $path || ! file_exists( $path ) ) {
		return null;
	}
	$sha = sha1_file( $path );
	$hit = daw_cm_api( array( 'action' => 'query', 'list' => 'allimages', 'aisha1' => $sha, 'ailimit' => 1 ) );
	$title = (string) ( $hit['query']['allimages'][0]['title'] ?? '' );
	if ( '' === $title ) {
		update_post_meta( $att, '_daw_cm_note', 'Commons\'ta aynı dosya bulunamadı (SHA-1)' );
		return null;
	}
	$info = daw_cm_api( array( 'action' => 'query', 'titles' => $title, 'prop' => 'imageinfo', 'iiprop' => 'extmetadata|url' ) );
	$ii   = (array) ( $info['query']['pages'][0]['imageinfo'][0] ?? array() );
	$meta = (array) ( $ii['extmetadata'] ?? array() );
	$lic  = daw_cm_licence( (string) ( $meta['LicenseShortName']['value'] ?? '' ) );
	if ( ! $lic ) {
		update_post_meta( $att, '_daw_cm_note', 'Commons dosyası bulundu ama lisansı kullanılamaz: ' . (string) ( $meta['LicenseShortName']['value'] ?? '?' ) . ' · ' . (string) ( $ii['descriptionurl'] ?? '' ) );
		return null;
	}
	$keys   = daw_lic_keys();
	$author = trim( wp_strip_all_tags( (string) ( $meta['Artist']['value'] ?? '' ) ) );
	update_post_meta( $att, $keys['type'], $lic[0] );
	update_post_meta( $att, $keys['version'], $lic[1] );
	update_post_meta( $att, $keys['author'], mb_substr( $author, 0, 200 ) );
	update_post_meta( $att, $keys['source'], (string) ( $ii['descriptionurl'] ?? '' ) );
	update_post_meta( $att, $keys['retrieved'], gmdate( 'Y-m-d' ) );
	update_post_meta( $att, $keys['title'], mb_substr( trim( wp_strip_all_tags( (string) ( $meta['ObjectName']['value'] ?? '' ) ) ), 0, 200 ) );
	delete_post_meta( $att, '_daw_cm_note' );
	return array( 'label' => (string) ( $meta['LicenseShortName']['value'] ?? '' ) );
}
