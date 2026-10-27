<?php
/**
 * Discover Asian World — structured data for the browse and section pages.
 *
 * WHY A SEPARATE FILE
 * -------------------
 * schema.php answers "what is this record": a drama is a TVSeries, a person is
 * a Person. A browse page is not a record, it is a list of them, and the type
 * that says so is CollectionPage with an ItemList inside it. Keeping the two
 * apart means the record graph never has to ask whether it is on a list page.
 *
 * ONLY THE PAGE THAT IS INDEXED GETS IT
 * -------------------------------------
 * A filtered state is noindex with its canonical pointing at the base page, so
 * describing it to a search engine is describing a page the site has already
 * said not to keep. The list is emitted on the unfiltered first page only, and
 * it names the records that are actually on that page, in the order they are
 * printed, which is what an ItemList is supposed to be.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * Which list, if any, a page is.
 *
 * Both the printer below and the filter that takes Rank Math off these pages
 * have to agree on this, because a page cannot be described twice: once as a
 * list of dramas and once as an article somebody wrote. Asking the same
 * function keeps them from drifting apart.
 *
 * @return array|null array( 'cfg' => array, 'lock' => string ) or null.
 */
function daw_browse_schema_cfg( $page_id ) {
	$page_id = (int) $page_id;

	if ( ! $page_id || ! function_exists( 'daw_browse_request' ) ) {
		return null;
	}

	if ( function_exists( 'daw_browse_for_page' ) ) {
		$cfg = daw_browse_for_page( $page_id );

		if ( $cfg ) {
			return array( 'cfg' => $cfg, 'lock' => '' );
		}
	}

	// A country section page is a list too: "every Korean drama" is exactly the
	// kind of page this markup is for.
	//
	// THE TEST WAS INVERTED, AND IT COST US THE BIGGEST PAGE ON THE SITE
	// ------------------------------------------------------------------
	// daw_section_is_live() is true when the page has no body of its own, which
	// is precisely when the section template takes over and prints the list.
	// The condition asked for the opposite, so every country section page -
	// /korean-drama/, /korean-movie/, /korean-actors/ - published no list
	// markup at all and was left to be described by the generic article markup
	// underneath it. These are the pages with the most search demand in the
	// archive.
	if ( function_exists( 'daw_section_for_page' ) && function_exists( 'daw_browse_cfg_for_type' ) ) {
		$section = daw_section_for_page( $page_id );

		if ( $section && function_exists( 'daw_section_is_live' ) && daw_section_is_live( $page_id ) ) {
			$cfg = daw_browse_cfg_for_type( $section['type'] );

			if ( $cfg ) {
				return array( 'cfg' => $cfg, 'lock' => (string) $section['country'] );
			}
		}
	}

	return null;
}

add_action( 'wp_head', 'daw_print_browse_schema', 21 );
function daw_print_browse_schema() {
	if ( ! is_page() ) {
		return;
	}

	$page_id = (int) get_the_ID();
	$found   = daw_browse_schema_cfg( $page_id );

	if ( ! $found ) {
		return;
	}

	$cfg  = $found['cfg'];
	$lock = $found['lock'];

	$filters = daw_browse_request();

	if ( isset( $cfg['lock'] ) && $cfg['lock'] ) {
		$lock = (string) $cfg['lock'];
	}

	if ( $lock ) {
		$filters['country'] = array( $lock );
	}

	// A page that answers a filter for itself lists that narrower set, so the
	// item list has to be built from the same state the page prints.
	$fixed = function_exists( 'daw_browse_fixed' ) ? daw_browse_fixed( $cfg ) : array();

	foreach ( $fixed as $fixed_key => $fixed_values ) {
		$filters[ $fixed_key ] = $fixed_values;
	}

	// Anything the reader has narrowed is a page the site does not want kept,
	// so it is not described either.
	$narrowed = function_exists( 'daw_browse_count_active' )
		? ( daw_browse_count_active( $filters, $fixed ? $fixed : $lock ) > 0 || $filters['pg'] > 1 )
		: false;

	if ( $narrowed ) {
		return;
	}

	$filters['require_roles']     = isset( $cfg['roles'] ) ? (array) $cfg['roles'] : array();
	$filters['require_roles_any'] = isset( $cfg['roles_any'] ) ? (array) $cfg['roles_any'] : array();
	$filters['require_flag']      = isset( $cfg['flag'] ) ? (string) $cfg['flag'] : '';

	$ids = daw_browse_ids( $cfg['types'], $filters );

	if ( ! $ids ) {
		return;
	}

	$per   = defined( 'DAW_BROWSE_PER_PAGE' ) ? DAW_BROWSE_PER_PAGE : 18;
	$shown = array_slice( $ids, 0, $per );
	$url   = (string) get_permalink( $page_id );

	$elements = array();

	foreach ( $shown as $i => $id ) {
		$elements[] = array(
			'@type'    => 'ListItem',
			'position' => $i + 1,
			'url'      => (string) get_permalink( $id ),
			'name'     => daw_schema_title( $id ),
		);
	}

	$crumbs = array(
		array(
			'@type'    => 'ListItem',
			'position' => 1,
			'name'     => __( 'Home', 'daw' ),
			'item'     => home_url( '/' ),
		),
		array(
			'@type'    => 'ListItem',
			'position' => 2,
			'name'     => daw_schema_title( $page_id ),
			'item'     => $url,
		),
	);

	$description = trim( (string) get_post_meta( $page_id, 'daw_section_intro', true ) );

	if ( '' === $description && has_excerpt( $page_id ) ) {
		$description = trim( wp_strip_all_tags( get_the_excerpt( $page_id ) ) );
	}

	$collection = array_filter(
		array(
			'@type'           => 'CollectionPage',
			'@id'             => $url . '#collection',
			'url'             => $url,
			'name'            => daw_schema_title( $page_id ),
			'description'     => $description,
			'inLanguage'      => 'en',
			'isPartOf'        => array( '@id' => home_url( '/' ) . '#website' ),
			'breadcrumb'      => array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $url . '#breadcrumb',
				'itemListElement' => $crumbs,
			),
			'mainEntity'      => array(
				'@type'            => 'ItemList',
				'@id'              => $url . '#items',
				// The whole archive, not just this page of it: the number is
				// what the heading above the grid says, and a list that claimed
				// eighteen when the page says four hundred would be the one
				// thing here that is not true.
				'numberOfItems'    => count( $ids ),
				'itemListOrder'    => 'https://schema.org/ItemListUnordered',
				'itemListElement'  => $elements,
			),
		)
	);

	printf(
		"<script type=\"application/ld+json\" id=\"daw-browse-schema\">%s</script>\n",
		wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				// Rank Math's whole graph is removed on these pages (schema.php),
				// and with it the WebSite and Organization nodes the collection
				// points at through isPartOf. A reference to an @id the page
				// never defines is a dangling node, so the two travel here too.
				'@graph'   => array_values(
					array_filter(
						array(
							function_exists( 'daw_schema_publisher' ) ? daw_schema_publisher() : null,
							function_exists( 'daw_schema_website' ) ? daw_schema_website() : null,
							$collection,
						)
					)
				),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		)
	);
}
