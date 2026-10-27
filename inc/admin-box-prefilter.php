<?php
/**
 * Discover Asian World — stop loading field groups the record cannot use.
 *
 * THE PROBLEM, MEASURED
 * ---------------------
 * Opening one title record in the editor pulled 180 files, 133 of them
 * separate JavaScript requests, and took 3.2 seconds to become usable. The
 * server was not the problem: it answered in 690ms. The other 2.5 seconds
 * were the browser fetching one script per Meta Box field type, over and over.
 *
 * The cause is in admin-field-visibility.php, and it is not a bug there. That
 * file hides the wrong groups in the browser, because Meta Box's free core has
 * no conditional logic. Hiding is not the same as not loading: every group for
 * every record type — title, person, group, news, guide, quiz — was still
 * registered, still rendered, and still enqueued its assets. A person record
 * was paying for the episode table; a drama was paying for the quiz builder.
 *
 * WHAT THIS DOES
 * --------------
 * On post.php the record already has a type, and that type cannot change
 * without a reload. So we filter the boxes down to the ones that type allows
 * before Meta Box ever sees them, and the browser never hears about the rest.
 *
 * On post-new.php we do NOT filter. A new post has no type yet, the editor
 * ticks one and expects the fields to appear immediately, and that is exactly
 * what the browser-side script is for. New posts are rare; open editors are
 * not. The expensive case is the one we fix.
 *
 * Boxes with no daw_types are kept in both cases: no declared restriction
 * means the box applies everywhere, and guessing otherwise would silently
 * hide somebody's fields.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

// Late priority: every other file has registered its boxes by now, including
// the ones added by fields-person.php, groups.php and the rest.
add_filter( 'rwmb_meta_boxes', 'daw_prefilter_boxes_by_type', 99 );

/**
 * @param array $boxes Registered meta boxes.
 * @return array
 */
function daw_prefilter_boxes_by_type( $boxes ) {
	if ( ! is_admin() ) {
		return $boxes;
	}

	$type = daw_editing_record_type();
	if ( '' === $type ) {
		return $boxes; // new post, or not an editor screen: change nothing
	}

	$keep = array();
	foreach ( $boxes as $box ) {
		if ( empty( $box['daw_types'] ) ) {
			$keep[] = $box; // unrestricted: belongs on every record
			continue;
		}
		if ( in_array( $type, (array) $box['daw_types'], true ) ) {
			$keep[] = $box;
		}
	}

	return $keep;
}

/**
 * The dw_type slug of the record currently open in the editor.
 *
 * Deliberately narrow. It returns a type ONLY for post.php with a real post id
 * that already carries exactly one dw_type term. Anything else — post-new.php,
 * a list table, an AJAX call, a record with no type or with several — returns
 * an empty string, and the caller leaves the boxes alone. A wrong guess here
 * would hide fields from an editor with no explanation, so every uncertain
 * case is answered "I do not know".
 *
 * @return string
 */
function daw_editing_record_type() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$cache = '';

	global $pagenow;
	if ( 'post.php' !== $pagenow ) {
		return $cache;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	if ( ! $post_id ) {
		return $cache;
	}

	$terms = get_the_terms( $post_id, 'dw_type' );
	if ( ! $terms || is_wp_error( $terms ) || count( $terms ) !== 1 ) {
		return $cache;
	}

	$cache = $terms[0]->slug;
	return $cache;
}
