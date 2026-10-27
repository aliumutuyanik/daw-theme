<?php
/**
 * Discover Asian World — show only the field groups that belong to the record.
 *
 * Meta Box's free core has no conditional logic (that is a paid extension), so
 * we do it ourselves in about forty lines. Each meta box declares a daw_types
 * array; this file hands that map to a small admin script which shows and hides
 * the boxes live as the editor picks a Record Type.
 *
 * Doing it in the browser rather than server-side matters: on a brand new post
 * no type is set yet, and the editor needs the fields to appear the moment they
 * tick one — not after a save-and-reload.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_enqueue_scripts', 'daw_field_visibility_assets' );
function daw_field_visibility_assets( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || 'post' !== $screen->post_type ) {
		return;
	}

	// Build box-id → allowed-types from the registered meta boxes themselves,
	// so adding a group to fields.php needs no change here.
	$map = array();
	foreach ( apply_filters( 'rwmb_meta_boxes', array() ) as $box ) {
		if ( ! empty( $box['daw_types'] ) && ! empty( $box['id'] ) ) {
			$map[ $box['id'] ] = array_values( (array) $box['daw_types'] );
		}
	}

	wp_register_script( 'daw-field-visibility', false, array(), DAW_VERSION, true );
	wp_enqueue_script( 'daw-field-visibility' );
	wp_add_inline_script(
		'daw-field-visibility',
		'window.DAW_FIELD_MAP = ' . wp_json_encode( $map ) . ";\n" . daw_field_visibility_js()
	);
}

/**
 * Kept as a heredoc rather than a .js file: it is small, it depends on the map
 * above, and one fewer HTTP request in wp-admin is one fewer thing to cache-bust.
 */
function daw_field_visibility_js() {
	return <<<'JS'
( function () {
	var map = window.DAW_FIELD_MAP || {};

	function currentType() {
		var checked = document.querySelector( '#dw_typechecklist input:checked, #taxonomy-dw_type input:checked' );
		if ( ! checked ) {
			return '';
		}
		// The checklist carries the term id; read the slug off the label text as
		// a fallback when the id map is unavailable.
		var label = checked.closest( 'label' );
		return ( label ? label.textContent : '' ).trim().toLowerCase().replace( /\s+/g, '-' );
	}

	function apply() {
		var type = currentType();

		Object.keys( map ).forEach( function ( boxId ) {
			var box = document.getElementById( boxId );
			if ( ! box ) {
				return;
			}
			// No type chosen yet: show everything so the editor is never stuck
			// staring at an empty screen wondering what to do.
			var show = ! type || map[ boxId ].indexOf( type ) !== -1;
			box.style.display = show ? '' : 'none';
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		apply();
		var list = document.querySelector( '#dw_typechecklist, #taxonomy-dw_type' );
		if ( list ) {
			list.addEventListener( 'change', apply );
		}
	} );
}() );
JS;
}
