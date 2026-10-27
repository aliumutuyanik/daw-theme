<?php
/**
 * Minimal REST exposure for the audit-workflow keys the coordinator needs to
 * close and stamp a record: daw_verified (the "done" stamp), _daw_yok
 * (researched N/A reasons), _daw_ok (resolved-conflict notes) and _daw_kw
 * (keyword research, an array). None of these are Meta Box fields, so MB
 * Rest API's own field exposure never reaches them; this is a deliberately
 * narrow addition rather than the site-wide field exposure discussed and not
 * shipped (content fields are separately covered by rest-meta-content.php).
 *
 * Restricted to the 'edit' REST context, which WordPress core only serves to
 * a request whose current user can edit_post the record in question — an
 * anonymous or unauthenticated request never sees these fields.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'daw_register_min_meta_for_rest', 20 );
function daw_register_min_meta_for_rest() {
	$auth = static function () {
		return current_user_can( 'edit_posts' );
	};
	foreach ( array( 'daw_verified', '_daw_yok', '_daw_ok' ) as $key ) {
		register_post_meta(
			'post',
			$key,
			array(
				'type'          => 'object',
				'single'        => true,
				'show_in_rest'  => array(
					'schema' => array(
						'type'                 => 'object',
						'context'              => array( 'edit' ),
						'additionalProperties' => true,
					),
				),
				'auth_callback' => $auth,
			)
		);
	}

	// The SEO title, description and focus keyword in standard REST (wp/v2
	// "meta"), for anyone who can edit that post (1.75.56). Rank Math is gone;
	// daw-core's own SEO engine keeps reading and writing these same meta keys
	// (seo-engine.php, seo-admin.php), so they are the keys to expose.
	foreach ( array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword' ) as $key ) {
		register_post_meta(
			'post',
			$key,
			array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', (int) $post_id );
				},
			)
		);
	}

	// Other TMDB ids a production is listed under (1.75.57): an array of
	// "tv:83893" / "movie:1455382" strings, for matching only.
	register_post_meta(
		'post',
		'daw_tmdb_alt',
		array(
			'type'          => 'array',
			'single'        => true,
			'show_in_rest'  => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
				return current_user_can( 'edit_post', (int) $post_id );
			},
		)
	);

	// _daw_kw (keyword research) is stored as an array, not an object.
	register_post_meta(
		'post',
		'_daw_kw',
		array(
			'type'          => 'array',
			'single'        => true,
			'show_in_rest'  => array(
				'schema' => array(
					'type'    => 'array',
					'context' => array( 'edit' ),
					'items'   => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
				),
			),
			'auth_callback' => $auth,
		)
	);
}
