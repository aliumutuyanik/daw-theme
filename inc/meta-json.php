<?php
/**
 * Discover Asian World: structured meta written through the MCP connector.
 *
 * Royal MCP's meta tool only carries strings, so a list such as daw_faq_q
 * arrives as the JSON text '["…","…"]' and would be stored as one string:
 * the FAQ, the keyword table and every paired list would break. A daw meta
 * value that is a valid JSON array or object is stored as the real array.
 * Plain text, HTML and placeholders like "[Information not available]" are
 * not valid JSON and pass through untouched.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'update_post_metadata', 'daw_meta_json_decode', 5, 5 );
add_filter( 'add_post_metadata', 'daw_meta_json_decode_add', 5, 5 );

function daw_meta_json_value( $meta_key, $value ) {
	if ( ! is_string( $value ) || ! preg_match( '/^_?daw_/', (string) $meta_key ) ) {
		return null;
	}
	$t = trim( $value );
	if ( '' === $t || ( '[' !== $t[0] && '{' !== $t[0] ) ) {
		return null;
	}
	$decoded = json_decode( $t, true );
	return ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) ? $decoded : null;
}

function daw_meta_json_decode( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
	$arr = daw_meta_json_value( $meta_key, $meta_value );
	if ( null === $arr ) {
		return $check;
	}
	return update_metadata( 'post', $object_id, $meta_key, $arr, $prev_value );
}

function daw_meta_json_decode_add( $check, $object_id, $meta_key, $meta_value, $unique ) {
	$arr = daw_meta_json_value( $meta_key, $meta_value );
	if ( null === $arr ) {
		return $check;
	}
	return add_metadata( 'post', $object_id, $meta_key, $arr, $unique );
}
