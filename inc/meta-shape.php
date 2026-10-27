<?php
/**
 * Meta shape guard.
 *
 * WHY THIS EXISTS
 * ---------------
 * On 25 Sep 2026 two finished records (BTS, 12.12: The Day) served a PHP
 * "critical error" to every visitor. Nothing in the templates had changed.
 * The cause was the data: a writer (an MCP call) had stored single-value
 * fields as one-element arrays, e.g. daw_native_title = ["서울의 봄"] and
 * daw_social_x = ["https://x.com/BTS_twt"]. The templates pass those values
 * to string functions, and PHP 8 stops the page with a TypeError.
 *
 * Three layers, so it cannot happen again:
 *  1. A write guard: a single-value daw_ field that is handed an array is
 *     stored as a string (one element: that element; several: joined).
 *  2. A repair: daw_meta_shape_repair() fixes rows already stored that way
 *     and collapses identical duplicate rows of single-value fields. It runs
 *     in the audit prep and, once, over every record in 50-record batches.
 *  3. An audit row: whatever the repair could not settle (two different
 *     values in duplicate rows) shows red, so a person decides.
 *
 * Fields Meta Box itself stores as arrays or as several rows (clone,
 * multiple) are left alone; only fields registered as single values are
 * touched.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** Registered daw_ fields that hold one plain value. Cached per request. */
function daw_scalar_fields() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map = array();
	foreach ( (array) apply_filters( 'rwmb_meta_boxes', array() ) as $box ) {
		foreach ( (array) ( $box['fields'] ?? array() ) as $f ) {
			$id = (string) ( $f['id'] ?? '' );
			if ( 0 !== strpos( $id, 'daw_' ) ) {
				continue;
			}
			$type = (string) ( $f['type'] ?? '' );
			// Only the plain single-value types. Anything Meta Box stores as
			// an array on purpose (clone, multiple, lists, images) is skipped.
			if ( ! empty( $f['clone'] ) || ! empty( $f['multiple'] ) || ! in_array( $type, array( 'text', 'textarea', 'wysiwyg', 'url', 'email', 'select', 'radio', 'number', 'date', 'oembed' ), true ) ) {
				continue;
			}
			$map[ $id ] = $type;
		}
	}
	return $map;
}

/**
 * An array handed to a single-value field, as the string it should be.
 * Only when the array holds one distinct value: two album links or two
 * native titles are a decision for a person, so the answer is null and
 * nothing is guessed (a URL joined with a comma is no URL at all).
 */
function daw_scalar_flatten( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	$parts = array();
	array_walk_recursive(
		$value,
		static function ( $v ) use ( &$parts ) {
			if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
				$parts[] = trim( (string) $v );
			}
		}
	);
	$parts = array_values( array_unique( $parts ) );
	if ( count( $parts ) > 1 ) {
		return null;
	}
	return $parts ? $parts[0] : '';
}

/**
 * A number field holds a number. The theme formats it with
 * number_format_i18n(), and PHP 8 stops the page on "41-80" or "about 60".
 * Arrays are judged after flattening, so ["63"] passes.
 */
function daw_meta_number_ok( $type, $value ) {
	if ( 'number' !== $type ) {
		return true;
	}
	$v = is_array( $value ) ? daw_scalar_flatten( $value ) : $value;
	if ( null === $v ) {
		return true; // several values: the array rule answers that
	}
	$v = trim( (string) $v );
	return '' === $v || is_numeric( $v );
}

/**
 * The research stores (_daw_yok, _daw_ok, _daw_kw) are arrays. A writer that
 * JSON-encodes the object first stores one long string, and the audit then
 * sees no N/A at all (Grid, 25 Sep 2026: 24 rows "not looked at"). Such a
 * string is decoded before it is stored. Returns null when nothing to do.
 */
function daw_meta_json_store( $meta_key, $meta_value ) {
	if ( ! in_array( (string) $meta_key, array( '_daw_yok', '_daw_ok', '_daw_kw' ), true ) || ! is_string( $meta_value ) ) {
		return null;
	}
	$t = ltrim( $meta_value );
	if ( '' === $t || ( '{' !== $t[0] && '[' !== $t[0] ) ) {
		return null;
	}
	$d = json_decode( $t, true );
	return is_array( $d ) ? $d : null;
}

/**
 * Date fields hold a Unix timestamp (UTC midnight); the theme, schema, age
 * and browse filters read them with (int). A writer that stores "1989-07-26"
 * gave (int) 1989, the page read 1 January 1970 and the portrait match by
 * birth date failed (Jeon Yeo Been, Hong Ki Joon, 27 Oct 2026). Such a string
 * is converted before it is stored. Returns null when nothing to do.
 */
function daw_meta_date_store( $meta_key, $meta_value ) {
	if ( ! in_array( (string) $meta_key, array( 'daw_born', 'daw_died', 'daw_aired_start', 'daw_aired_end' ), true ) || ! is_string( $meta_value ) ) {
		return null;
	}
	if ( ! preg_match( '/^\s*(\d{4})-(\d{2})-(\d{2})(?:[T ][\d:.]+Z?)?\s*$/', $meta_value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return null;
	}
	return (string) gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );
}

/* ---------------------------------------------------------- write guard --- */

add_filter( 'update_post_metadata', 'daw_meta_shape_guard_update', 5, 5 );
add_filter( 'add_post_metadata', 'daw_meta_shape_guard_add', 5, 5 );

function daw_meta_shape_guard_update( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
	$date = daw_meta_date_store( $meta_key, $meta_value );
	if ( null === $check && null !== $date ) {
		return update_metadata( 'post', $object_id, $meta_key, $date, $prev_value );
	}
	$decoded = daw_meta_json_store( $meta_key, $meta_value );
	if ( null === $check && null !== $decoded ) {
		return update_metadata( 'post', $object_id, $meta_key, $decoded, $prev_value );
	}
	if ( null !== $check || 0 !== strpos( (string) $meta_key, 'daw_' ) ) {
		return $check;
	}
	$map = daw_scalar_fields();
	if ( empty( $map[ $meta_key ] ) ) {
		return $check;
	}
	if ( ! daw_meta_number_ok( $map[ $meta_key ], $meta_value ) ) {
		return false; // "41-80" in a number field took Alice in Borderland down (25 Sep 2026)
	}
	if ( ! is_array( $meta_value ) ) {
		return $check;
	}
	$flat = daw_scalar_flatten( $meta_value );
	if ( null === $flat ) {
		return false; // several values for a one-value field: refused, the writer sees the failure
	}
	return update_metadata( 'post', $object_id, $meta_key, $flat, $prev_value );
}

function daw_meta_shape_guard_add( $check, $object_id, $meta_key, $meta_value, $unique ) {
	$date = daw_meta_date_store( $meta_key, $meta_value );
	if ( null === $check && null !== $date ) {
		return add_metadata( 'post', $object_id, $meta_key, $date, $unique );
	}
	$decoded = daw_meta_json_store( $meta_key, $meta_value );
	if ( null === $check && null !== $decoded ) {
		return add_metadata( 'post', $object_id, $meta_key, $decoded, $unique );
	}
	if ( null !== $check || 0 !== strpos( (string) $meta_key, 'daw_' ) ) {
		return $check;
	}
	$map = daw_scalar_fields();
	if ( empty( $map[ $meta_key ] ) ) {
		return $check;
	}
	if ( ! daw_meta_number_ok( $map[ $meta_key ], $meta_value ) ) {
		return false;
	}
	if ( ! is_array( $meta_value ) ) {
		return $check;
	}
	$flat = daw_scalar_flatten( $meta_value );
	if ( null === $flat ) {
		return false;
	}
	return add_metadata( 'post', $object_id, $meta_key, $flat, $unique );
}

/* --------------------------------------------------------------- repair --- */

/**
 * Fix one record. Returns what is still wrong after the repair, as
 * field => reason; an empty array means the record's meta is sound.
 */
function daw_meta_shape_repair( $post_id ) {
	$post_id = (int) $post_id;
	$left    = array();
	foreach ( array( '_daw_yok', '_daw_ok', '_daw_kw' ) as $store ) {
		$raw = get_post_meta( $post_id, $store, true );
		$dec = daw_meta_json_store( $store, $raw );
		if ( null !== $dec ) {
			update_post_meta( $post_id, $store, $dec );
		}
	}
	foreach ( array( 'daw_born', 'daw_died', 'daw_aired_start', 'daw_aired_end' ) as $dk ) {
		$dv = get_post_meta( $post_id, $dk, true );
		if ( null !== daw_meta_date_store( $dk, $dv ) ) {
			update_post_meta( $post_id, $dk, $dv ); // the guard converts it
		}
	}
	$all     = get_post_meta( $post_id );
	foreach ( daw_scalar_fields() as $key => $unused ) {
		if ( empty( $all[ $key ] ) ) {
			continue;
		}
		$rows = array_map( 'maybe_unserialize', (array) $all[ $key ] );
		$one  = daw_scalar_flatten( $rows );
		if ( null === $one ) {
			$left[ $key ] = 'birden çok farklı değer';
			continue;
		}
		if ( ! daw_meta_number_ok( daw_scalar_fields()[ $key ], $one ) ) {
			$left[ $key ] = 'sayı alanında sayı değil: ' . mb_substr( (string) $one, 0, 20 );
			continue;
		}
		$flat = array( (string) $one );
		$needs = count( $rows ) > 1;
		foreach ( $rows as $r ) {
			if ( is_array( $r ) ) {
				$needs = true;
			}
		}
		if ( $needs ) {
			delete_post_meta( $post_id, $key );
			if ( '' !== $flat[0] ) {
				add_post_meta( $post_id, $key, $flat[0], true );
			}
		}
	}
	return $left;
}

/* ------------------------------------------------------ one-time sweep --- */

const DAW_META_SHAPE_SWEEP = 'daw_meta_shape_sweep_2'; // _2: text dates (1.75.86)

add_action( 'init', 'daw_meta_shape_sweep_schedule', 30 );
add_action( 'daw_meta_shape_sweep_run', 'daw_meta_shape_sweep_run' );

function daw_meta_shape_sweep_schedule() {
	$state = get_option( DAW_META_SHAPE_SWEEP );
	if ( is_array( $state ) && ! empty( $state['done'] ) ) {
		return;
	}
	if ( ! wp_next_scheduled( 'daw_meta_shape_sweep_run' ) ) {
		wp_schedule_single_event( time() + 60, 'daw_meta_shape_sweep_run' );
	}
}

/** Fifty records per run (rule 8.1), then the next run is queued. */
function daw_meta_shape_sweep_run() {
	$state = get_option( DAW_META_SHAPE_SWEEP );
	$state = is_array( $state ) ? $state : array( 'last' => 0, 'left' => array() );
	if ( ! empty( $state['done'] ) ) {
		return;
	}
	global $wpdb;
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ('publish','draft','pending','private','future') AND ID > %d ORDER BY ID ASC LIMIT 50",
			(int) $state['last']
		)
	);
	if ( ! $ids ) {
		$state['done'] = time();
		update_option( DAW_META_SHAPE_SWEEP, $state, false );
		return;
	}
	foreach ( $ids as $id ) {
		$left = daw_meta_shape_repair( (int) $id );
		if ( $left ) {
			$state['left'][ (int) $id ] = $left;
		}
		$state['last'] = (int) $id;
	}
	update_option( DAW_META_SHAPE_SWEEP, $state, false );
	wp_schedule_single_event( time() + 30, 'daw_meta_shape_sweep_run' );
}
