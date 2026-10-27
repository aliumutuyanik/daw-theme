<?php
/**
 * Where the site can be followed, and how to reach the people who run it.
 *
 * WHY THESE ARE SETTINGS
 * ----------------------
 * Accounts get opened, renamed and closed. A handle hard-coded into a template
 * is a broken link waiting to happen, and a broken link in the footer is on
 * every page of the site at once. So each one is a field, each one is printed
 * only when it has something in it, and a footer with no accounts filled in
 * prints no follow row at all rather than a row of dead icons.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * The networks the footer knows how to draw, in the order it draws them.
 *
 * The order is deliberate: the two that actually send this site readers come
 * first, and the rest follow. It is not alphabetical because nobody reads a
 * footer alphabetically.
 */
function daw_social_networks() {
	return array(
		'instagram' => __( 'Instagram', 'daw' ),
		'pinterest' => __( 'Pinterest', 'daw' ),
		'youtube'   => __( 'YouTube', 'daw' ),
		'tiktok'    => __( 'TikTok', 'daw' ),
		'x'         => __( 'X', 'daw' ),
		'facebook'  => __( 'Facebook', 'daw' ),
	);
}

/** Only the ones that have been filled in. */
function daw_social_links() {
	$out = array();

	foreach ( daw_social_networks() as $key => $label ) {
		$url = trim( (string) get_option( 'daw_social_' . $key ) );

		if ( $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$out[ $key ] = array(
				'label' => $label,
				'url'   => $url,
			);
		}
	}

	return $out;
}

/**
 * One icon per network, drawn rather than fetched.
 *
 * An icon font or a sprite sheet would be another request for six small
 * pictures that never change. These are a few hundred bytes each and they
 * inherit the surrounding colour, which is what makes them work in both the
 * footer and anywhere else they get used later.
 */
function daw_social_icon( $key ) {
	$paths = array(
		'instagram' => '<rect x="2" y="2" width="20" height="20" rx="5.5"/><circle cx="12" cy="12" r="4.2"/><circle cx="17.6" cy="6.4" r="1.2" fill="currentColor" stroke="none"/>',
		'pinterest' => '<circle cx="12" cy="12" r="10"/><path d="M11.4 15.6 9.7 22"/><path d="M8.6 14.3c-.5-1.1-.6-2.6-.1-3.9.8-2.3 3-3.6 5.1-3.2 2.2.4 3.5 2.3 3.1 4.6-.4 2.2-2 3.7-3.7 3.4-1-.2-1.6-1-1.4-1.9"/>',
		'youtube'   => '<rect x="2" y="5" width="20" height="14" rx="4.5"/><path d="m10.2 9.3 5 2.7-5 2.7z"/>',
		'tiktok'    => '<path d="M14.4 3v10.9a3.8 3.8 0 1 1-3.1-3.7"/><path d="M14.4 5.4A5 5 0 0 0 19 8.6"/>',
		'x'         => '<path d="m4 4 16 16"/><path d="M20 4 4 20"/>',
		'facebook'  => '<rect x="2" y="2" width="20" height="20" rx="5.5"/><path d="M15.3 8.2h-1.5c-.9 0-1.4.5-1.4 1.4V12h2.8l-.4 2.8h-2.4V22"/><path d="M9.4 12h2.9"/>',
	);

	if ( empty( $paths[ $key ] ) ) {
		return '';
	}

	return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $key ] . '</svg>';
}

/* =============================================================== admin === */

add_action( 'admin_init', 'daw_social_settings' );
function daw_social_settings() {
	foreach ( array_keys( daw_social_networks() ) as $key ) {
		register_setting(
			'reading',
			'daw_social_' . $key,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
	}

	add_settings_field(
		'daw_social',
		__( 'DAW: follow us', 'daw' ),
		'daw_social_field',
		'reading'
	);
}

function daw_social_field() {
	foreach ( daw_social_networks() as $key => $label ) {
		printf(
			'<p><label style="display:inline-block;min-width:7em">%s</label> <input type="url" name="%s" value="%s" class="regular-text" placeholder="https://"></p>',
			esc_html( $label ),
			esc_attr( 'daw_social_' . $key ),
			esc_attr( (string) get_option( 'daw_social_' . $key ) )
		);
	}

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Leave one empty and it does not appear. Leave all of them empty and the footer prints no follow row at all.', 'daw' )
	);
}

/**
 * The same accounts, stated in the Organization node.
 *
 * The footer has printed these links for months while the schema's sameAs
 * filter was never hooked, so the structured data said the site had no
 * profiles anywhere. sameAs is how a search engine ties the site to its own
 * accounts; the list is the one the footer already shows, nothing added.
 */
add_filter( 'daw_schema_same_as', 'daw_social_same_as' );
function daw_social_same_as( $urls ) {
	foreach ( daw_social_links() as $link ) {
		if ( ! empty( $link['url'] ) ) {
			$urls[] = esc_url_raw( $link['url'] );
		}
	}

	return array_values( array_unique( (array) $urls ) );
}
