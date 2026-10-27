<?php
/**
 * Discover Asian World — taxonomies
 *
 * DESIGN RULE THAT GOVERNS THIS FILE
 * ----------------------------------
 * All 926 records stay post_type = "post". We never convert them to a CPT.
 * Permalinks are /%postname%/ (flat, root level) and the root namespace is
 * already shared by 926 posts + 34 pages + 7,878 root-level tags. Adding a CPT
 * there would mean hand-writing rewrite rules that must beat WordPress's own
 * page/tag resolution — and any mistake is a 404 on a URL that carries ranking.
 *
 * So: record type is a TAXONOMY, not a post type. The theme picks its template
 * from the dw_type term. Zero rewrite rules, zero 301s, no URL ever changes.
 *
 * Every taxonomy here is registered with public => false + show_ui => true:
 * they are editorial metadata and internal query keys, NOT public archives.
 * Public browsing happens through our own archive templates on existing page
 * URLs (/korean-drama/, /korean-actors/ …), which already have ranking.
 * This also keeps them out of the root URL namespace entirely.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * The record types. Keys are term slugs, values are labels.
 * Counts are from the September 2026 audit of the live site.
 */
function daw_record_types() {
	return array(
		'drama'   => __( 'Drama', 'daw' ),     // ~421
		'film'    => __( 'Film', 'daw' ),      // ~167
		// Variety, talk and reality shows. A separate type because a profile's
		// filmography is read by billing, and "guest on a talk show" is not a
		// supporting role in a drama; mixing them would make both lists wrong.
		'variety' => __( 'Variety show', 'daw' ),
		'person'  => __( 'Person', 'daw' ),    // ~239
		'group'   => __( 'Music Group', 'daw' ), // ~5 (K-Pop)
		'news'    => __( 'News', 'daw' ),      // ~51
		'food'    => __( 'Food & Drink', 'daw' ), // ~21
		'culture' => __( 'Culture', 'daw' ),   // ~9
		'myth'    => __( 'Myth', 'daw' ),      // ~3
		'travel'  => __( 'Travel', 'daw' ),    // ~5
		'quiz'    => __( 'Quiz', 'daw' ),      // ~2 — "which character are you" style
		// LISTS AND PROFILE FEATURES
		// --------------------------
		// "The ten K-dramas of 2023 worth watching" and "Cha Eun-woo's MBTI"
		// were both filed under Culture, which put a ranked drama list and a
		// celebrity questionnaire next to an explainer on how a Korean table
		// works. They are neither culture nor news: a feature is not dated to
		// the day and it does not explain a country, it looks at one subject
		// the site already covers and goes round it once.
		'feature' => __( 'Feature', 'daw' ),
		// NOT YET USED, AND THAT IS THE POINT
		// -----------------------------------
		// A hub section cannot appear for a kind of record nobody can file, so
		// the terms exist before the first record does. Both are empty today;
		// an empty term costs a row in a table and means the day somebody wants
		// to add a manhwa they can, without a developer.
		//
		// Animation is its own type rather than a genre because it is a
		// different production ecosystem, with studios where a drama has a
		// network. Comics are their own type because they have no cast, no
		// runtime and no air date, and do have volumes, chapters and an artist.
		'anime'   => __( 'Animation', 'daw' ),
		'manga'   => __( 'Comic', 'daw' ),
	);
}

add_action( 'init', 'daw_register_taxonomies', 5 );
function daw_register_taxonomies() {

	$shared = array(
		'public'             => false,
		'publicly_queryable' => false,
		'show_ui'            => true,
		'show_in_menu'       => true,
		'show_in_rest'       => true,
		'show_admin_column'  => true,
		'hierarchical'       => true,
		'rewrite'            => false, // critical: stay out of the root namespace
		'query_var'          => false,
	);

	// ---------------------------------------------------------------- dw_type
	// The spine. One term per record. Decides the template and the field group.
	register_taxonomy(
		'dw_type',
		array( 'post' ),
		array_merge(
			$shared,
			array(
				'labels'       => daw_tax_labels( __( 'Record Type', 'daw' ), __( 'Record Types', 'daw' ) ),
				'meta_box_cb'  => 'post_categories_meta_box', // radio-like single choice enforced below
				'description'  => __( 'Exactly one per record. Drives template and fields.', 'daw' ),
			)
		)
	);

	// ------------------------------------------------------------- dw_country
	register_taxonomy(
		'dw_country',
		array( 'post' ),
		array_merge( $shared, array(
			'labels' => daw_tax_labels( __( 'Country', 'daw' ), __( 'Countries', 'daw' ) ),
		) )
	);

	// --------------------------------------------------------------- dw_genre
	register_taxonomy(
		'dw_genre',
		array( 'post' ),
		array_merge( $shared, array(
			'labels'       => daw_tax_labels( __( 'Genre', 'daw' ), __( 'Genres', 'daw' ) ),
			'hierarchical' => false,
		) )
	);

	// ------------------------------------------------------------- dw_network
	// SBS, tvN, JTBC, Netflix … used by the Title schema and by browse filters.
	register_taxonomy(
		'dw_network',
		array( 'post' ),
		array_merge( $shared, array(
			'labels'       => daw_tax_labels( __( 'Network', 'daw' ), __( 'Networks', 'daw' ) ),
			'hierarchical' => false,
		) )
	);
}

/**
 * Label array helper — keeps the registrations above readable.
 */
function daw_tax_labels( $singular, $plural ) {
	return array(
		'name'          => $plural,
		'singular_name' => $singular,
		'search_items'  => sprintf( __( 'Search %s', 'daw' ), $plural ),
		'all_items'     => sprintf( __( 'All %s', 'daw' ), $plural ),
		'edit_item'     => sprintf( __( 'Edit %s', 'daw' ), $singular ),
		'update_item'   => sprintf( __( 'Update %s', 'daw' ), $singular ),
		'add_new_item'  => sprintf( __( 'Add new %s', 'daw' ), $singular ),
		'new_item_name' => sprintf( __( 'New %s', 'daw' ), $singular ),
		'menu_name'     => $plural,
	);
}

/**
 * Seed the dw_type terms once, on theme activation / first load.
 * Idempotent: existing terms are left alone.
 */
add_action( 'init', 'daw_seed_type_terms', 20 );
function daw_seed_type_terms() {
	if ( get_option( 'daw_type_terms_seeded' ) === DAW_VERSION ) {
		return;
	}
	foreach ( daw_record_types() as $slug => $label ) {
		if ( ! term_exists( $slug, 'dw_type' ) ) {
			wp_insert_term( $label, 'dw_type', array( 'slug' => $slug ) );
		}
	}
	update_option( 'daw_type_terms_seeded', DAW_VERSION, false );
}

/**
 * Read a record's type slug. Returns '' when unset.
 * This is THE function the whole theme branches on — templates, fields, schema.
 */
function daw_get_type( $post_id = null ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	if ( ! $post_id ) {
		return '';
	}

	// Cached per request: this runs on every template and every schema call.
	static $cache = array();
	if ( isset( $cache[ $post_id ] ) ) {
		return $cache[ $post_id ];
	}

	$terms = get_the_terms( $post_id, 'dw_type' );
	$cache[ $post_id ] = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->slug : '';

	return $cache[ $post_id ];
}

/**
 * Enforce "exactly one dw_type per record".
 * If an editor ticks several, the first is kept. Silent and safe.
 */
add_action( 'set_object_terms', 'daw_enforce_single_type', 10, 6 );
function daw_enforce_single_type( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
	if ( 'dw_type' !== $taxonomy || count( $tt_ids ) <= 1 ) {
		return;
	}
	// $tt_ids carries term_taxonomy_ids. wp_set_object_terms() expects
	// term_ids. Passing one where the other is wanted does not error: the
	// number simply matches no term in dw_type, and the record is left with
	// no type at all, which drops it to the generic Article schema and the
	// wrong template. Convert first, and only keep what converts.
	$keep = array();
	foreach ( $tt_ids as $tt_id ) {
		$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, 'dw_type' );
		if ( $term && ! is_wp_error( $term ) ) {
			$keep[] = (int) $term->term_id;
			break; // one type is the whole point
		}
	}
	if ( ! $keep ) {
		return; // nothing resolvable: leave the record as it is rather than empty it
	}

	remove_action( 'set_object_terms', 'daw_enforce_single_type', 10 );
	wp_set_object_terms( $object_id, $keep, 'dw_type', false );
	add_action( 'set_object_terms', 'daw_enforce_single_type', 10, 6 );
}

/**
 * Template routing. /pinocchio/ still resolves exactly as it does today —
 * WordPress finds the post, and we only swap which PHP file renders it.
 * The URL is never consulted, never rewritten, never redirected.
 */
add_filter( 'single_template', 'daw_route_single_template' );
function daw_route_single_template( $template ) {
	if ( ! is_singular( 'post' ) ) {
		return $template;
	}

	$type = daw_get_type();
	if ( ! $type ) {
		return $template; // untyped record falls back to single.php
	}

	// Drama and film share one template; the differences are field-level.
	// Culture, food, myths and travel share another: they are all articles with
	// a fact table, and splitting them into four near-identical files would
	// mean fixing every layout bug four times.
	$file = $type;

	if ( in_array( $type, array( 'drama', 'film', 'variety' ), true ) ) {
		$file = 'title';
	} elseif ( function_exists( 'daw_guide_types' ) && in_array( $type, daw_guide_types(), true ) ) {
		$file = 'guide';
	}

	$found = locate_template( array( "single-{$file}.php" ) );

	return $found ? $found : $template;
}
