<?php
/**
 * Discover Asian World — the mark.
 *
 * Drawn as inline SVG rather than an uploaded file, for three reasons: it is
 * about 700 bytes so it costs less than the request an <img> would make, it
 * recolours from the same CSS tokens as the rest of the interface, and it stays
 * sharp at every size without a second asset.
 *
 * THE SEAL, AND WHY BOTH EXIST
 * ---------------------------
 * The brand kit argued for a flat vector, because bevel and gloss turn to mush
 * below roughly 40px and a wordmark inside the disc becomes an unreadable dot.
 * Umut's call was the rendered seal, and it is his mark — so the rendered seal
 * is what the site wears. It is masked to its own circle so the square's light
 * ground never shows as a pale tile on the cream header, and zoomed a touch so
 * the mask cuts nothing off the wax edge.
 *
 * The vector below stays for the two jobs the render cannot do: it is the
 * fallback when the file is missing, and it is what renders at favicon sizes,
 * where the render has nothing legible left to say.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * The seal mark on its own.
 *
 * @param int    $size    Rendered size in px.
 * @param string $variant 'default' or 'reversed' for dark backgrounds.
 */
function daw_seal( $size = 36, $variant = 'default' ) {
	$disc = '#7E2128';
	$gold = '#C9971F';
	$face = '#FAF7F2';

	if ( 'reversed' === $variant ) {
		$disc = '#8E252D';
	}

	ob_start();
	?>
	<svg class="daw-seal" width="<?php echo (int) $size; ?>" height="<?php echo (int) $size; ?>"
		viewBox="0 0 40 40" role="img" aria-hidden="true" focusable="false">
		<!-- Beaded rim. First thing to drop out at favicon sizes, by design. -->
		<circle cx="20" cy="20" r="18.4" fill="none" stroke="<?php echo esc_attr( $gold ); ?>"
			stroke-width="1.4" stroke-dasharray="1.6 2.1" stroke-linecap="round" opacity=".85"/>
		<circle cx="20" cy="20" r="16" fill="<?php echo esc_attr( $disc ); ?>"/>
		<circle cx="20" cy="20" r="12.8" fill="none" stroke="<?php echo esc_attr( $gold ); ?>" stroke-width="1.1"/>
		<!-- The A. Drawn as paths so no font has to load before the logo is right. -->
		<path d="M20 10.4 L26.4 27.2 H23.1 L21.75 23.3 H18.25 L16.9 27.2 H13.6 Z"
			fill="<?php echo esc_attr( $face ); ?>"/>
		<path d="M19 20.9 H21 L20 17.6 Z" fill="<?php echo esc_attr( $disc ); ?>"/>
	</svg>
	<?php
	return ob_get_clean();
}

/* ------------------------------------------------------------- favicon --- */

/*
  ONE ICON, IN ONE PLACE

  The tab icon was coming out differently depending on what opened the site,
  and there were three separate reasons for it.

  The first is that there were two pictures. The theme drew a simplified seal
  as an inline SVG, and everything that cannot take an SVG fell back to the
  uploaded logo: the admin screen, an iOS home screen shortcut, a search
  result, an older browser. Both were declared, both were valid, and which one
  a reader saw was down to their browser. That is fixed by having one picture:
  the real logo, the same file the header shows, declared at every size.

  The second is that WordPress prints its own site icon links as well, in four
  sizes, which is a second opinion offered at the same priority. Its output is
  removed above and everything is declared here instead, in one place and one
  order.

  The third is /favicon.ico. The host answers that path with its own error page
  before WordPress ever sees the request, so anything that asks for the icon
  the oldest way gets html back. The plugin writes a real file at that path;
  the handler at the bottom of this block is the fallback for an environment
  where it cannot.

  And a favicon is cached harder than anything else a browser holds, so every
  url here carries the release on it. A reader who has the old one will be
  offered the new one on the next release rather than in a month.
*/

remove_action( 'wp_head', 'wp_site_icon', 99 );

add_action( 'wp_head', 'daw_favicon', 2 );
function daw_favicon() {
	// THE ICON IS THE LOGO
	// --------------------
	// An earlier version of this file drew a simplified seal as an inline SVG,
	// on the argument that the real wax seal turns to mud at sixteen pixels.
	// The argument was sound and the result was wrong: browsers that take an
	// SVG showed the drawing and everything else showed the uploaded logo, so
	// the same site had two marks depending on what opened it. One icon beats a
	// better icon. This declares the real one, at every size, in one place.
	foreach ( array( 32, 192, 512 ) as $size ) {
		$url = daw_icon_src( $size );

		if ( $url ) {
			printf(
				'<link rel="icon" type="image/png" sizes="%1$dx%1$d" href="%2$s">' . "\n",
				(int) $size,
				esc_url( $url )
			);
		}
	}

	$v    = defined( 'DAW_VERSION' ) ? DAW_VERSION : '1';
	$root = function_exists( 'daw_icon_root_has' ) ? 'daw_icon_root_has' : '__return_false';

	// The .ico carries 16, 32 and 48 in one file, for Windows and old software.
	if ( $root( 'favicon.ico' ) ) {
		printf( '<link rel="icon" href="%s" sizes="any">' . "\n", esc_url( home_url( '/favicon.ico?v=' . $v ) ) );
	}

	// iOS paints a transparent icon on black, so it gets the opaque copy.
	$touch = $root( 'apple-touch-icon.png' ) ? home_url( '/apple-touch-icon.png?v=' . $v ) : daw_icon_src( 180 );

	if ( $touch ) {
		printf( '<link rel="apple-touch-icon" sizes="180x180" href="%s">' . "\n", esc_url( $touch ) );
	}

	// Android home screen and Chrome's install sheet read the manifest.
	if ( $root( 'site.webmanifest' ) ) {
		printf( '<link rel="manifest" href="%s">' . "\n", esc_url( home_url( '/site.webmanifest?v=' . $v ) ) );
		echo '<meta name="theme-color" content="#7E2128">' . "\n";
	}
}

/**
 * One size of the site icon, with the release stamped on it.
 *
 * The stamp is the only way a reader who already has the old picture in their
 * browser is ever offered the new one, because a favicon is cached past the
 * point where cache headers are consulted.
 */
function daw_icon_src( $size ) {
	return daw_site_icon_url( $size );
}

function daw_site_icon_url( $size ) {
	$url = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( $size ) : '';

	if ( ! $url ) {
		return '';
	}

	return add_query_arg( 'v', defined( 'DAW_VERSION' ) ? DAW_VERSION : '1', $url );
}

/**
 * Answer /favicon.ico ourselves rather than letting the host 404 it.
 *
 * This only fires when the request reaches WordPress, which on this host it
 * does not: the root path is answered by the server's own error page first.
 * The plugin writes a real file there, and this stays as the second line for
 * any environment where that write is not possible.
 */
add_action( 'do_faviconico', 'daw_serve_faviconico' );
function daw_serve_faviconico() {
	$url = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 32 ) : '';

	if ( ! $url ) {
		return;
	}

	wp_safe_redirect( $url, 302 );
	exit;
}

/**
 * Mark plus wordmark, as used in the header and footer.
 */
function daw_logo( $size = 36, $variant = 'default', $link = true ) {
	$mark = daw_logo_image( $size );
	if ( '' === $mark ) {
		$mark = daw_seal( $size, $variant );
	}

	$inner = $mark
		. '<span class="daw-wordmark"><small>' . esc_html__( 'Discover', 'daw' ) . '</small>'
		. '<strong>' . esc_html__( 'Asian World', 'daw' ) . '</strong></span>';

	if ( ! $link ) {
		return '<span class="daw-logo">' . $inner . '</span>';
	}

	// No aria-label. The link already says "Discover Asian World" in visible
	// text, and a label that differed from it ("..., home") failed WCAG 2.5.3
	// (label in name) in Lighthouse on every page: a voice-control user says
	// what they see, and the name has to contain it.
	return sprintf(
		'<a class="daw-logo" href="%s" rel="home">%s</a>',
		esc_url( home_url( '/' ) ),
		$inner
	);
}


/**
 * The rendered seal, resolved from the media library rather than hard-coded.
 *
 * A url written into a theme file is a url that breaks the day staging becomes
 * production, because the two libraries do not share upload paths. So the file
 * is found once by its name, its attachment id is remembered in an option, and
 * every later request is a single lookup. Move the site, and the same lookup
 * finds the same file at its new address.
 *
 * Width and height are always printed: the header must not move while the
 * image loads.
 */
function daw_logo_image( $size = 36 ) {
	$id = (int) get_option( 'daw_logo_attachment_id', 0 );

	if ( ! $id ) {
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				's'              => 'asian-world-logo',
				'fields'         => 'ids',
			)
		);
		$id = $found ? (int) $found[0] : 0;
		update_option( 'daw_logo_attachment_id', $id, false );
	}

	if ( ! $id ) {
		return '';
	}

	$src = wp_get_attachment_image_url( $id, 'thumbnail' );
	if ( ! $src ) {
		return '';
	}

	return sprintf(
		'<span class="daw-logo-mark" style="--logo:%dpx"><img src="%s" width="%d" height="%d" alt="" decoding="async"></span>',
		(int) $size,
		esc_url( $src ),
		(int) $size,
		(int) $size
	);
}

/**
 * The same mark at full resolution, for schema and social cards — places that
 * want a url, not markup.
 */
function daw_logo_url() {
	$id = (int) get_option( 'daw_logo_attachment_id', 0 );
	if ( ! $id ) {
		daw_logo_image();
		$id = (int) get_option( 'daw_logo_attachment_id', 0 );
	}

	return $id ? (string) wp_get_attachment_image_url( $id, 'full' ) : '';
}

/**
 * A url only if something is actually there.
 *
 * The first version of this header shipped eight links to pages that do not
 * exist yet — a menu that 404s is worse than a menu with fewer items, and on a
 * site whose whole point is that urls resolve, unforgivable. Now a destination
 * has to prove it exists before it gets a link, and the moment a page is
 * published its link appears on its own.
 *
 * @return string  The url, or '' when there is nothing at that slug.
 */
function daw_page_url( $slug ) {
	static $cache = array();

	if ( isset( $cache[ $slug ] ) ) {
		return $cache[ $slug ];
	}

	$page = get_page_by_path( $slug );
	$cache[ $slug ] = ( $page && 'publish' === $page->post_status ) ? get_permalink( $page ) : '';

	return $cache[ $slug ];
}

/**
 * Drop entries whose url came back empty.
 */
function daw_live_links( array $items ) {
	return array_values( array_filter( $items, function ( $item ) {
		return ! empty( $item['url'] );
	} ) );
}

/**
 * The primary navigation, as a data array.
 *
 * Kept here rather than in a WordPress menu because these eight destinations
 * are the shape of the database, not an editorial choice someone should be able
 * to rearrange by accident. Labels still pass through translation so a second
 * language costs nothing later.
 */
function daw_primary_nav() {
	$countries = daw_live_links( array(
		array( 'label' => __( 'South Korea', 'daw' ), 'url' => daw_page_url( 'south-korea-korean-dramas-movies-culture' ) ),
		array( 'label' => __( 'Japan', 'daw' ), 'url' => daw_page_url( 'japan-japanese-dramas-movies-culture' ) ),
		array( 'label' => __( 'China', 'daw' ), 'url' => daw_page_url( 'chinese-dramas-movies-culture' ) ),
		array( 'label' => __( 'Thailand', 'daw' ), 'url' => daw_page_url( 'thailand-thai-dramas-movies-culture' ) ),
		array( 'label' => __( 'Taiwan', 'daw' ), 'url' => daw_page_url( 'taiwan-taiwanese-dramas-movies-culture' ) ),
		array( 'label' => __( 'Philippines', 'daw' ), 'url' => daw_page_url( 'philippines-filipino-dramas-movies-culture' ) ),
		array( 'label' => __( 'Hong Kong', 'daw' ), 'url' => daw_page_url( 'hong-kong-dramas-movies-culture' ) ),
		array( 'label' => __( 'Singapore', 'daw' ), 'url' => daw_page_url( 'singapore-singaporean-dramas-movies-culture' ) ),
	) );

	$items = array(
		// The Countries parent points at the biggest hub rather than a
		// /countries/ index that does not exist yet.
		array(
			'label'    => __( 'Countries', 'daw' ),
			'url'      => $countries ? $countries[0]['url'] : '',
			'children' => $countries,
		),
		// The browse pages first, the Korean lists as the fallback. These three
		// words promise every drama on the site, not Korea's, and they kept
		// that promise only because there was nowhere else to send them. The
		// moment /dramas/ exists the promise is met; until then nothing moves.
		array( 'label' => __( 'Dramas', 'daw' ), 'url' => daw_page_url( 'dramas' ) ? daw_page_url( 'dramas' ) : daw_page_url( 'korean-drama' ) ),
		array( 'label' => __( 'Films', 'daw' ), 'url' => daw_page_url( 'films' ) ? daw_page_url( 'films' ) : daw_page_url( 'korean-movie' ) ),
		array( 'label' => __( 'People', 'daw' ), 'url' => daw_page_url( 'people' ) ? daw_page_url( 'people' ) : daw_page_url( 'korean-actors' ) ),
		// MUSIC IN THE MENU, K-POP STILL AT ITS OWN ADDRESS
		// --------------------------------------------------
		// The menu is the promise the site makes about what it covers, and
		// "K-Pop" promised one country. The page it points at now covers the
		// rest of Asia as well, with a country filter to narrow it. Nothing
		// was redirected to do this: /k-pop/ is still there, still Korean,
		// and still linked from the Korean hub and from every idol profile.
		array( 'label' => __( 'Music', 'daw' ), 'url' => daw_page_url( 'music' ) ? daw_page_url( 'music' ) : ( daw_page_url( 'k-pop' ) ? daw_page_url( 'k-pop' ) : daw_page_url( 'korean-pop' ) ) ),
		array( 'label' => __( 'News', 'daw' ), 'url' => daw_page_url( 'news' ) ),
	);

	// Eight top-level items plus a wordmark, a search field and two icons do
	// not fit a 1180px measure — the last one printed through the search box.
	// The three that lose least by moving are the three that are browsed rather
	// than looked up, so they gather under More.
	$more = daw_live_links(
		array(
			array( 'label' => __( 'Culture', 'daw' ), 'url' => daw_page_url( 'culture' ) ? daw_page_url( 'culture' ) : daw_page_url( 'korean-culture' ) ),
			array( 'label' => __( 'Myths & legends', 'daw' ), 'url' => daw_page_url( 'myths' ) ? daw_page_url( 'myths' ) : daw_page_url( 'korean-myths' ) ),
			array( 'label' => __( 'Food & Drink', 'daw' ), 'url' => daw_page_url( 'food' ) ? daw_page_url( 'food' ) : daw_page_url( 'korean-foods-drinks' ) ),
			array( 'label' => __( 'Travel', 'daw' ), 'url' => daw_page_url( 'travel' ) ? daw_page_url( 'travel' ) : daw_page_url( 'korean-travel' ) ),
			array( 'label' => __( 'Features & lists', 'daw' ), 'url' => daw_page_url( 'features' ) ),
			array( 'label' => __( 'Quizzes', 'daw' ), 'url' => daw_page_url( 'quizzes' ) ? daw_page_url( 'quizzes' ) : daw_page_url( 'quiz' ) ),
		)
	);

	if ( $more ) {
		$items[] = array(
			'label'    => __( 'More', 'daw' ),
			'url'      => $more[0]['url'],
			'children' => $more,
		);
	}

	return daw_live_links( $items );
}

/**
 * The small links in the utility bar. Same rule: nothing there, nothing shown.
 */
function daw_utility_nav() {
	return daw_live_links( array(
		array( 'label' => __( 'Recently added', 'daw' ), 'url' => daw_page_url( 'recently-added' ) ),
		array( 'label' => __( 'Airing calendar', 'daw' ), 'url' => daw_page_url( 'airing-calendar' ) ),
		array( 'label' => __( 'About', 'daw' ), 'url' => daw_page_url( 'about' ) ),
	) );
}

/**
 * Icons for the mobile bottom bar. Line art at 22px, one stroke weight, so the
 * five of them read as a set rather than five downloads from five places.
 */
function daw_tab_icon( $key ) {
	$paths = array(
		'home'   => '<path d="M3 9.5 11 3l8 6.5V19a1 1 0 0 1-1 1h-4v-6H8v6H4a1 1 0 0 1-1-1z"/>',
		'browse' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="12" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="12" width="7" height="7" rx="1.5"/><rect x="12" y="12" width="7" height="7" rx="1.5"/>',
		'search' => '<circle cx="10" cy="10" r="6"/><path d="M14.5 14.5 19 19"/>',
		'saved'  => '<path d="M11 18s-7-4.6-7-9A4 4 0 0 1 11 6.8 4 4 0 0 1 18 9c0 4.4-7 9-7 9z"/>',
		'more'   => '<circle cx="5" cy="11" r="1.6"/><circle cx="11" cy="11" r="1.6"/><circle cx="17" cy="11" r="1.6"/>',
	);

	return '<svg viewBox="0 0 22 22" width="22" height="22" fill="none" stroke="currentColor"'
		. ' stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"'
		. ' focusable="false">' . ( $paths[ $key ] ?? '' ) . '</svg>';
}

/**
 * Footer columns. Legal links point at pages that already exist where they do;
 * the ones still to be written are listed so the gap is visible rather than
 * quietly missing.
 */
function daw_footer_columns() {
	$cols = array(
		__( 'Countries', 'daw' ) => array(
			array( 'label' => __( 'South Korea', 'daw' ), 'url' => daw_page_url( 'south-korea-korean-dramas-movies-culture' ) ),
			array( 'label' => __( 'Japan', 'daw' ), 'url' => daw_page_url( 'japan-japanese-dramas-movies-culture' ) ),
			array( 'label' => __( 'China', 'daw' ), 'url' => daw_page_url( 'chinese-dramas-movies-culture' ) ),
			array( 'label' => __( 'Thailand', 'daw' ), 'url' => daw_page_url( 'thailand-thai-dramas-movies-culture' ) ),
			array( 'label' => __( 'Taiwan', 'daw' ), 'url' => daw_page_url( 'taiwan-taiwanese-dramas-movies-culture' ) ),
			array( 'label' => __( 'Philippines', 'daw' ), 'url' => daw_page_url( 'philippines-filipino-dramas-movies-culture' ) ),
			array( 'label' => __( 'Hong Kong', 'daw' ), 'url' => daw_page_url( 'hong-kong-dramas-movies-culture' ) ),
			array( 'label' => __( 'Singapore', 'daw' ), 'url' => daw_page_url( 'singapore-singaporean-dramas-movies-culture' ) ),
		),
		// THE FOOTER GOES WHERE THE HEADER DOES NOT
		// -----------------------------------------
		// The header's country menu answers "what is here from Korea". The
		// footer answers "everything of one kind, from everywhere", which is
		// the other half of the site and the only place some of these pages are
		// linked from. Two menus that go to the same eight pages are one menu
		// printed twice.
		//
		// There is no "Actresses" row any more. Splitting people by gender put
		// half a cast on one page and half on another, and no reader has ever
		// wanted that.
		__( 'Browse', 'daw' ) => array(
			array( 'label' => __( 'All dramas', 'daw' ), 'url' => daw_page_url( 'dramas' ) ),
			array( 'label' => __( 'All films', 'daw' ), 'url' => daw_page_url( 'films' ) ),
			array( 'label' => __( 'Actors and idols', 'daw' ), 'url' => daw_page_url( 'people' ) ),
			array( 'label' => __( 'Groups and soloists', 'daw' ), 'url' => daw_page_url( 'music' ) ? daw_page_url( 'music' ) : daw_page_url( 'k-pop' ) ),
			array( 'label' => __( 'K-Pop', 'daw' ), 'url' => daw_page_url( 'k-pop' ) ),
			array( 'label' => __( 'Culture', 'daw' ), 'url' => daw_page_url( 'culture' ) ),
			array( 'label' => __( 'Myths & legends', 'daw' ), 'url' => daw_page_url( 'myths' ) ),
			array( 'label' => __( 'Food & drink', 'daw' ), 'url' => daw_page_url( 'food' ) ),
			array( 'label' => __( 'Travel', 'daw' ), 'url' => daw_page_url( 'travel' ) ),
			array( 'label' => __( 'Features & lists', 'daw' ), 'url' => daw_page_url( 'features' ) ),
			array( 'label' => __( 'Airing calendar', 'daw' ), 'url' => daw_page_url( 'airing-calendar' ) ),
		),
		__( 'Editorial', 'daw' ) => array(
			array( 'label' => __( 'About us', 'daw' ), 'url' => daw_page_url( 'about' ) ),
			array( 'label' => __( 'Editorial policy', 'daw' ), 'url' => daw_page_url( 'editorial-policy' ) ),
			array( 'label' => __( 'Masthead & team', 'daw' ), 'url' => daw_page_url( 'masthead' ) ),
			array( 'label' => __( 'Request a correction', 'daw' ), 'url' => daw_page_url( 'contact' ) ),
			array( 'label' => __( 'Contact', 'daw' ), 'url' => daw_page_url( 'contact' ) ),
		),
		__( 'Legal', 'daw' ) => array(
			array( 'label' => __( 'Privacy policy', 'daw' ), 'url' => daw_page_url( 'privacy-policy' ) ),
			array( 'label' => __( 'Cookie policy', 'daw' ), 'url' => daw_page_url( 'cookie-policy' ) ),
			array( 'label' => __( 'Terms of use', 'daw' ), 'url' => daw_page_url( 'terms-of-service' ) ),
			array( 'label' => __( 'DMCA & copyright', 'daw' ), 'url' => daw_page_url( 'legal-notice-visual-content-usage' ) ),
			array( 'label' => __( 'Advertising disclosure', 'daw' ), 'url' => daw_page_url( 'advertising-disclosure' ) ),
		),
	);

	foreach ( $cols as $heading => $links ) {
		$cols[ $heading ] = daw_live_links( $links );
	}

	// A column with nothing left in it should not print a heading either.
	return array_filter( $cols );
}

/* ------------------------------------------------- the browse sheet ------ */

/**
 * A browse page with one filter already answered, as a url.
 *
 * The menu is meant to be every way into the archive, and "romance dramas" is
 * one of those ways. Rather than hard-coding the query string in a dozen
 * places, it is built by the same function the filters themselves use, so a
 * change to how a filtered url is written is a change in one file.
 *
 * Returns '' when the page or the term is not there, and daw_live_links() drops
 * the row, so a menu never offers a door that does not open.
 */
function daw_browse_state_url( $page_slug, $filters ) {
	$url = daw_page_url( $page_slug );

	if ( ! $url || ! function_exists( 'daw_browse_url' ) || ! function_exists( 'daw_browse_request' ) ) {
		return '';
	}

	foreach ( array( 'genre' => 'dw_genre', 'network' => 'dw_network', 'country' => 'dw_country' ) as $key => $taxonomy ) {
		if ( isset( $filters[ $key ] ) && ! get_term_by( 'slug', $filters[ $key ], $taxonomy ) ) {
			return '';
		}
	}

	$base = array_fill_keys( daw_browse_filter_keys(), array() );

	foreach ( $filters as $key => $value ) {
		$base[ $key ] = (array) $value;
	}

	$base['sort'] = 'new';
	$base['pg']   = 1;

	return daw_browse_url( $url, $base );
}

/**
 * Everything the site can be browsed by, grouped, for the bar at the bottom of
 * a phone.
 *
 * WHY THE TAB IS NOT A LINK ANY MORE
 * ----------------------------------
 * It pointed at the drama list. So a reader on a phone tapped a button labelled
 * "Browse" and landed on Asian dramas, which is one of nine things this site
 * holds and not the one most of them wanted. A bar with four buttons has room
 * for one that means "show me what is here", and that is a menu, not a
 * destination.
 *
 * The groups are the axes the archive actually has: what kind of thing it is,
 * who is in it, what is written about it, and where it comes from. That is the
 * same shape as the footer, because it is the same truth about the database.
 */
function daw_browse_sheet_groups() {
	$groups = array(
		__( 'Watch', 'daw' ) => daw_live_links( array(
			array( 'label' => __( 'All dramas', 'daw' ), 'url' => daw_page_url( 'dramas' ) ),
			array( 'label' => __( 'All films', 'daw' ), 'url' => daw_page_url( 'films' ) ),
			array( 'label' => __( 'On air now', 'daw' ), 'url' => daw_browse_state_url( 'dramas', array( 'status' => 'ongoing' ) ) ),
			array( 'label' => __( 'Upcoming', 'daw' ), 'url' => daw_browse_state_url( 'dramas', array( 'status' => 'upcoming' ) ) ),
			array( 'label' => __( 'Airing calendar', 'daw' ), 'url' => daw_page_url( 'airing-calendar' ) ),
		) ),
		__( 'By genre', 'daw' ) => daw_live_links( array(
			array( 'label' => __( 'Romance', 'daw' ), 'url' => daw_browse_state_url( 'dramas', array( 'genre' => 'romance' ) ) ),
			array( 'label' => __( 'Comedy', 'daw' ), 'url' => daw_browse_state_url( 'dramas', array( 'genre' => 'comedy' ) ) ),
			array( 'label' => __( 'Thriller', 'daw' ), 'url' => daw_browse_state_url( 'dramas', array( 'genre' => 'thriller' ) ) ),
			array( 'label' => __( 'Action', 'daw' ), 'url' => daw_browse_state_url( 'dramas', array( 'genre' => 'action' ) ) ),
			array( 'label' => __( 'Mystery', 'daw' ), 'url' => daw_browse_state_url( 'dramas', array( 'genre' => 'mystery' ) ) ),
			array( 'label' => __( 'Historical', 'daw' ), 'url' => daw_browse_state_url( 'dramas', array( 'genre' => 'historical' ) ) ),
		) ),
		__( 'Where to watch', 'daw' ) => daw_live_links( array(
			array( 'label' => __( 'Netflix', 'daw' ), 'url' => daw_browse_state_url( 'dramas', array( 'network' => 'netflix' ) ) ),
			array( 'label' => __( 'Disney+', 'daw' ), 'url' => daw_browse_state_url( 'dramas', array( 'network' => 'disney' ) ) ),
			array( 'label' => __( 'tvN', 'daw' ), 'url' => daw_browse_state_url( 'dramas', array( 'network' => 'tvn' ) ) ),
			array( 'label' => __( 'SBS', 'daw' ), 'url' => daw_browse_state_url( 'dramas', array( 'network' => 'sbs' ) ) ),
			array( 'label' => __( 'KBS2', 'daw' ), 'url' => daw_browse_state_url( 'dramas', array( 'network' => 'kbs2' ) ) ),
			array( 'label' => __( 'MBC', 'daw' ), 'url' => daw_browse_state_url( 'dramas', array( 'network' => 'mbc' ) ) ),
		) ),
		__( 'People', 'daw' ) => daw_live_links( array(
			array( 'label' => __( 'Actors and idols', 'daw' ), 'url' => daw_page_url( 'people' ) ),
			array( 'label' => __( 'K-pop', 'daw' ), 'url' => daw_page_url( 'k-pop' ) ),
		) ),
		__( 'Read', 'daw' ) => daw_live_links( array(
			array( 'label' => __( 'News', 'daw' ), 'url' => daw_page_url( 'news' ) ),
			array( 'label' => __( 'Features & lists', 'daw' ), 'url' => daw_page_url( 'features' ) ),
			array( 'label' => __( 'Culture', 'daw' ), 'url' => daw_page_url( 'culture' ) ),
			array( 'label' => __( 'Myths & legends', 'daw' ), 'url' => daw_page_url( 'myths' ) ),
			array( 'label' => __( 'Food & drink', 'daw' ), 'url' => daw_page_url( 'food' ) ),
			array( 'label' => __( 'Travel', 'daw' ), 'url' => daw_page_url( 'travel' ) ),
			array( 'label' => __( 'Quizzes', 'daw' ), 'url' => daw_page_url( 'quizzes' ) ),
		) ),
		__( 'Countries', 'daw' ) => daw_live_links( array(
			array( 'label' => __( 'South Korea', 'daw' ), 'url' => daw_page_url( 'south-korea-korean-dramas-movies-culture' ) ),
			array( 'label' => __( 'Japan', 'daw' ), 'url' => daw_page_url( 'japan-japanese-dramas-movies-culture' ) ),
			array( 'label' => __( 'China', 'daw' ), 'url' => daw_page_url( 'chinese-dramas-movies-culture' ) ),
			array( 'label' => __( 'Thailand', 'daw' ), 'url' => daw_page_url( 'thailand-thai-dramas-movies-culture' ) ),
			array( 'label' => __( 'Taiwan', 'daw' ), 'url' => daw_page_url( 'taiwan-taiwanese-dramas-movies-culture' ) ),
			array( 'label' => __( 'Philippines', 'daw' ), 'url' => daw_page_url( 'philippines-filipino-dramas-movies-culture' ) ),
			array( 'label' => __( 'Hong Kong', 'daw' ), 'url' => daw_page_url( 'hong-kong-dramas-movies-culture' ) ),
			array( 'label' => __( 'Singapore', 'daw' ), 'url' => daw_page_url( 'singapore-singaporean-dramas-movies-culture' ) ),
		) ),
	);

	return array_filter( $groups );
}

/**
 * What the mobile tab bar's "More" opens.
 *
 * The desktop header already groups these six under a "More" dropdown
 * (daw_primary_nav()) because eight top-level items do not fit the header's
 * width. The bottom tab bar used to send the same tap straight to the About
 * page instead — a reasonable fallback the day the bar shipped, before this
 * sheet existed, but not what "More" promises on a database this size. Same
 * six destinations, plus the three small utility links the footer already
 * carries, so nothing here is new content invented for this one sheet.
 */
function daw_more_sheet_items() {
	return daw_live_links( array(
		array( 'label' => __( 'Culture', 'daw' ), 'url' => daw_page_url( 'culture' ) ? daw_page_url( 'culture' ) : daw_page_url( 'korean-culture' ) ),
		array( 'label' => __( 'Myths & legends', 'daw' ), 'url' => daw_page_url( 'myths' ) ? daw_page_url( 'myths' ) : daw_page_url( 'korean-myths' ) ),
		array( 'label' => __( 'Food & Drink', 'daw' ), 'url' => daw_page_url( 'food' ) ? daw_page_url( 'food' ) : daw_page_url( 'korean-foods-drinks' ) ),
		array( 'label' => __( 'Travel', 'daw' ), 'url' => daw_page_url( 'travel' ) ? daw_page_url( 'travel' ) : daw_page_url( 'korean-travel' ) ),
		array( 'label' => __( 'Features & lists', 'daw' ), 'url' => daw_page_url( 'features' ) ),
		array( 'label' => __( 'Quizzes', 'daw' ), 'url' => daw_page_url( 'quizzes' ) ? daw_page_url( 'quizzes' ) : daw_page_url( 'quiz' ) ),
	) );
}

/** The sheet itself: six destinations plus the utility links, nothing else. */
function daw_more_sheet() {
	$items = daw_more_sheet_items();
	$utility = daw_utility_nav();

	if ( ! $items && ! $utility ) {
		return;
	}
	?>
	<div class="daw-sheet" id="daw-more-sheet" hidden>
		<div class="daw-sheet-in">
			<div class="daw-sheet-head">
				<h2 class="daw-sheet-t"><?php esc_html_e( 'More', 'daw' ); ?></h2>
				<button type="button" class="daw-sheet-x" data-daw-sheet-close>
					<span class="daw-vh"><?php esc_html_e( 'Close', 'daw' ); ?></span>
					<span aria-hidden="true">&times;</span>
				</button>
			</div>

			<div class="daw-sheet-body">
				<?php if ( $items ) : ?>
					<div class="daw-sheet-group">
						<ul>
							<?php foreach ( $items as $link ) : ?>
								<li><a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
				<?php if ( $utility ) : ?>
					<div class="daw-sheet-group">
						<ul>
							<?php foreach ( $utility as $link ) : ?>
								<li><a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}

/** The sheet itself, printed once in the footer. */
function daw_browse_sheet() {
	$groups = daw_browse_sheet_groups();

	if ( ! $groups ) {
		return;
	}
	?>
	<div class="daw-sheet" id="daw-browse-sheet" hidden>
		<div class="daw-sheet-in">
			<div class="daw-sheet-head">
				<h2 class="daw-sheet-t"><?php esc_html_e( 'All categories', 'daw' ); ?></h2>
				<button type="button" class="daw-sheet-x" data-daw-sheet-close>
					<span class="daw-vh"><?php esc_html_e( 'Close', 'daw' ); ?></span>
					<span aria-hidden="true">&times;</span>
				</button>
			</div>

			<div class="daw-sheet-body">
				<?php foreach ( $groups as $heading => $links ) : ?>
					<div class="daw-sheet-group">
						<h3 class="daw-sheet-gh"><?php echo esc_html( $heading ); ?></h3>
						<ul>
							<?php foreach ( $links as $link ) : ?>
								<li><a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php
}
