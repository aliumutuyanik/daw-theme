<?php
/**
 * The document pages: about, editorial policy, the legal set.
 *
 * These are the pages a reader goes looking for when they want to know who is
 * behind this and what happens to their data, and the pages an ad network, an
 * app store or a rights holder checks before they trust the site. They are not
 * lists, so they do not get the browse template; they are documents, and they
 * get a template that reads like one.
 *
 * The set is written down here rather than discovered, because the left rail on
 * every one of them is this list, and a document that is not in the list is a
 * document nobody can find from the others.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every document page, in the order the rail prints them.
 *
 * 'group' is what the breadcrumb says: these pages are asked for from two
 * directions, and "Legal › Cookie policy" is a more useful trail than
 * "Editorial › Cookie policy".
 */
function daw_doc_pages() {
	return array(
		'about'                             => array(
			'label' => __( 'About us', 'daw' ),
			'group' => __( 'Editorial', 'daw' ),
		),
		'editorial-policy'                  => array(
			'label' => __( 'Editorial policy', 'daw' ),
			'group' => __( 'Editorial', 'daw' ),
		),
		'masthead'                          => array(
			'label' => __( 'Masthead & team', 'daw' ),
			'group' => __( 'Editorial', 'daw' ),
		),
		'corrections-policy'                => array(
			'label' => __( 'Corrections policy', 'daw' ),
			'group' => __( 'Editorial', 'daw' ),
		),
		'privacy-policy'                    => array(
			'label' => __( 'Privacy policy', 'daw' ),
			'group' => __( 'Legal', 'daw' ),
		),
		'cookie-policy'                     => array(
			'label' => __( 'Cookie policy', 'daw' ),
			'group' => __( 'Legal', 'daw' ),
		),
		'terms-of-service'                  => array(
			'label' => __( 'Terms of use', 'daw' ),
			'group' => __( 'Legal', 'daw' ),
		),
		'legal-notice-visual-content-usage' => array(
			'label' => __( 'DMCA & copyright', 'daw' ),
			'group' => __( 'Legal', 'daw' ),
		),
		'advertising-disclosure'            => array(
			'label' => __( 'Advertising disclosure', 'daw' ),
			'group' => __( 'Legal', 'daw' ),
		),
		'accessibility'                     => array(
			'label' => __( 'Accessibility', 'daw' ),
			'group' => __( 'Legal', 'daw' ),
		),
		'contact'                           => array(
			'label' => __( 'Contact', 'daw' ),
			'group' => __( 'Editorial', 'daw' ),
		),
	);
}

/** The document config for a page, or null. */
function daw_doc_for_page( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();

	if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
		return null;
	}

	$docs = daw_doc_pages();
	$slug = (string) get_post_field( 'post_name', $post_id );

	return isset( $docs[ $slug ] ) ? $docs[ $slug ] + array( 'slug' => $slug ) : null;
}

/** The rail, with the pages that do not exist yet dropped rather than dead. */
function daw_doc_rail() {
	$out = array();

	foreach ( daw_doc_pages() as $slug => $doc ) {
		$page = get_page_by_path( $slug );

		if ( ! $page || 'publish' !== $page->post_status ) {
			continue;
		}

		$out[] = array(
			'slug'  => $slug,
			'label' => $doc['label'],
			'url'   => (string) get_permalink( $page ),
		);
	}

	return $out;
}

add_filter( 'page_template', 'daw_route_doc_template' );

/** Draw a document page with the document template. */
function daw_route_doc_template( $template ) {
	if ( ! daw_doc_for_page() ) {
		return $template;
	}

	$found = locate_template( 'page-doc.php' );

	return $found ? $found : $template;
}

/**
 * When the document was last touched, as a sentence.
 *
 * Read from the page's own modified date rather than typed into the text, so it
 * cannot say January while the page was edited in March. A policy with a date
 * nobody maintains is worse than a policy with no date.
 */
function daw_doc_updated( $post_id ) {
	$modified = get_post_modified_time( 'U', true, $post_id );

	if ( ! $modified ) {
		return '';
	}

	return sprintf(
		/* translators: %s: date */
		__( 'Last updated %s', 'daw' ),
		date_i18n( get_option( 'date_format' ), $modified )
	);
}
