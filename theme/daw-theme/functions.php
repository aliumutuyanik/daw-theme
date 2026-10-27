<?php
/**
 * Discover Asian World — child theme bootstrap
 *
 * A child of Astra on purpose. Astra keeps drawing the header, footer and
 * navigation while we replace record templates one at a time, so the site is
 * never half-built in public. When every template is ours, Astra goes.
 *
 * This file adds no rewrite rules and changes no permalinks. Template routing
 * happens in the DAW Core plugin, on the single_template filter, after
 * WordPress has already resolved the url the same way it does today.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/*
 * THE VERSION IS NEVER TYPED TWICE
 * -------------------------------
 * This was hard-coded, and it was left at 0.16.1 while style.css climbed to
 * 0.16.8. The stylesheet kept going out as daw.css?ver=0.16.1, so browsers and
 * the page cache served the old file and seven releases of CSS changes were
 * invisible on the live site while the theme reported the new version in
 * wp-admin. A number a human has to remember to change in two places is a bug
 * waiting for a deadline. It is read from the theme header now.
 */
define( 'DAW_THEME_VERSION', wp_get_theme()->get( 'Version' ) );

require_once get_stylesheet_directory() . '/inc/brand.php';
require_once get_stylesheet_directory() . '/inc/record-scripts.php';
require_once get_stylesheet_directory() . '/inc/browse-ui.php';
require_once get_stylesheet_directory() . '/inc/quiz.php';
require_once get_stylesheet_directory() . '/inc/food.php';

add_action( 'wp_enqueue_scripts', 'daw_theme_assets', 20 );
function daw_theme_assets() {
	// The header, footer and mobile bar are now ours on every page, so the
	// stylesheet loads everywhere rather than only on records.
	// And the stylesheet's own mtime beats even that: a CSS fix made without a
	// version bump still reaches the reader, which is what you want while a
	// site is being rebuilt a dozen times a day.
	//
	// THE MINIFIED COPY, WHEN IT IS NOT OLDER THAN THE SOURCE
	// ------------------------------------------------------
	// daw.css is half comments: 229 KB on the wire before compression, 115 KB
	// without them (57 KB gzipped against 20 KB). It is the one stylesheet that
	// blocks the first paint on every page, so the browser gets the copy built
	// by bin/build-css.mjs. A hand edit to daw.css without a rebuild leaves the
	// minified file older than the source, and then the source is served
	// instead: a stale build can cost bytes, never a CSS fix.
	$dir      = get_stylesheet_directory();
	$css_file = 'daw.css';

	// The copy says which source it was built from (a "daw-src:<md5>" comment
	// on its first line), so the check survives the theme being written out
	// by the plugin, which gives both files new and arbitrary mtimes.
	if ( function_exists( 'daw_min_css_fresh' ) ? daw_min_css_fresh( $dir ) : ( file_exists( $dir . '/assets/daw.min.css' ) && file_exists( $dir . '/assets/daw.css' ) && filemtime( $dir . '/assets/daw.min.css' ) >= filemtime( $dir . '/assets/daw.css' ) ) ) {
		$css_file = 'daw.min.css';
	}

	$css_path = $dir . '/assets/' . $css_file;
	$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : DAW_THEME_VERSION;

	wp_enqueue_style(
		'daw',
		get_stylesheet_directory_uri() . '/assets/' . $css_file,
		array(),
		$css_ver
	);

	// Two families, two jobs: Bricolage for interface and headings, Newsreader
	// for the long panels people actually sit and read. Their @font-face rules
	// are a stylesheet of their own, served from this theme and loaded off the
	// critical path by daw_fonts_async() below; assets/fonts.css says why.
	$fonts_path = $dir . '/assets/fonts.css';

	wp_enqueue_style(
		'daw-fonts',
		get_stylesheet_directory_uri() . '/assets/fonts.css',
		array(),
		file_exists( $fonts_path ) ? (string) filemtime( $fonts_path ) : DAW_THEME_VERSION
	);
}

/**
 * Whether assets/daw.min.css was built from the daw.css beside it.
 *
 * The md5 of the source is kept with the source's size and mtime, so it is
 * worked out once per edit, not once per request.
 */
function daw_min_css_fresh( $dir ) {
	$src = $dir . '/assets/daw.css';
	$min = $dir . '/assets/daw.min.css';

	if ( ! file_exists( $src ) || ! file_exists( $min ) ) {
		return false;
	}

	$stamp = filemtime( $src ) . ':' . filesize( $src ) . ':' . filemtime( $min );
	$memo  = get_option( 'daw_min_css_check' );

	if ( is_array( $memo ) && isset( $memo['stamp'], $memo['ok'] ) && $memo['stamp'] === $stamp ) {
		return (bool) $memo['ok'];
	}

	$head = (string) file_get_contents( $min, false, null, 0, 64 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$ok   = false !== strpos( $head, 'daw-src:' . md5_file( $src ) );

	update_option( 'daw_min_css_check', array( 'stamp' => $stamp, 'ok' => $ok ), true );

	return $ok;
}

/**
 * "Script runs here", said before the first paint. Things the footer script
 * upgrades (the filter rail that becomes a sheet on a phone) can then take
 * their final shape in the first layout instead of moving when the script
 * arrives at the end of the page: the rail jumping away was a 0.233 layout
 * shift on a section page (PageSpeed, 6 Oct 2026).
 */
add_action( 'wp_head', 'daw_js_flag', 0 );
function daw_js_flag() {
	echo '<script>document.documentElement.classList.add("daw-js")</script>' . "\n";
}

add_action( 'wp_head', 'daw_preconnect_fonts', 1 );
function daw_preconnect_fonts() {
	// No font hints. The faces are on this host, and a preload was measured
	// and taken out again: it put 73 KB of font on the critical path beside
	// the stylesheet, and the first paint came later, not sooner.

	// THE AD HOSTS, BEFORE THE AD SCRIPT ASKS FOR THEM
	// ------------------------------------------------
	// Lighthouse flags more than 4 preconnects as excessive: each opens a
	// full TCP+TLS handshake and Chrome only runs a few at once.
	// pagead2.googlesyndication.com, the one host adsbygoogle.js itself comes
	// from, keeps the full preconnect and the two hosts only hit once an
	// advert is served get a cheaper dns-prefetch.
	if ( function_exists( 'daw_ads_live' ) && daw_ads_live() ) {
		echo '<link rel="preconnect" href="https://pagead2.googlesyndication.com" crossorigin>' . "\n";
		echo '<link rel="dns-prefetch" href="https://googleads.g.doubleclick.net">' . "\n";
		echo '<link rel="dns-prefetch" href="https://tpc.googlesyndication.com">' . "\n";
	}
}

/**
 * The font stylesheet, off the critical path.
 *
 * MEASURED: a blocking stylesheet blocks the first paint even when every
 * glyph it describes is set to swap, and a face declared in one is requested
 * as soon as it is parsed. Loading it as a print stylesheet and promoting it
 * once it arrives takes both out of that queue. Text paints immediately in
 * the fallback stack. The noscript copy keeps it working with no javascript.
 */
add_filter( 'style_loader_tag', 'daw_fonts_async', 10, 2 );
function daw_fonts_async( $tag, $handle ) {
	if ( 'daw-fonts' !== $handle || is_admin() ) {
		return $tag;
	}

	// THE FACES ONLY WHERE THEY ARE ALREADY IN THE BROWSER (1.75.16)
	// The sheet used to be promoted from media=print once it arrived. Its
	// @font-face rules then appeared after the first paint, the file landed
	// inside optional's ~100 ms window, and the headings were set again in
	// Bricolage: the hero block moved (layout shift 0.105, drama page,
	// 6 Oct 2026) and the whole page was laid out twice. Now:
	// - a first visit never applies the sheet. The page is painted and laid
	//   out once, in the metric-matched fallback stack. After it has loaded
	//   and gone quiet, the sheet and the two latin files are fetched into the
	//   browser cache and a flag is set;
	// - every later page switches the sheet on in the head, before the body
	//   exists, so the faces come from the cache for the first paint.
	$async = str_replace( "media='all'", "media='print'", $tag );
	if ( $async === $tag ) {
		$async = str_replace( '>', ' media="print">', $tag );
	}

	$base  = trailingslashit( get_stylesheet_directory_uri() ) . 'assets/';
	$files = array( $base . 'fonts/bricolage-grotesque-latin.woff2', $base . 'fonts/newsreader-latin-o18.woff2' );
	preg_match( "#href=['\"]([^'\"]+)['\"]#", $tag, $m );
	if ( ! empty( $m[1] ) ) {
		$files[] = html_entity_decode( $m[1] );
	}

	$boot = '<script>try{if(localStorage.getItem("dawf")==="1"){var l=document.getElementById("daw-fonts-css");if(l){l.media="all";}}}catch(e){}'
		. '(function(){var f=' . wp_json_encode( array_values( $files ), JSON_UNESCAPED_SLASHES ) . ';'
		. 'function go(){try{if(localStorage.getItem("dawf")==="1"||!window.fetch){return;}Promise.all(f.map(function(u){return fetch(u,{credentials:"same-origin"}).then(function(r){if(!r.ok){throw 0;}return r.blob();});})).then(function(){localStorage.setItem("dawf","1");}).catch(function(){});}catch(e){}}'
		. 'window.addEventListener("load",function(){(window.requestIdleCallback||function(c){setTimeout(c,1500);})(go,{timeout:4000});});})();</script>';

	return $async . '<noscript>' . $tag . '</noscript>' . "\n" . $boot . "\n";
}

/**
 * THE STYLESHEET, INSIDE THE PAGE (1.58.0)
 * ----------------------------------------
 * daw.min.css was the one request every page waited for before it could
 * paint: PageSpeed put it at 170 to 400 ms of render blocking on a phone
 * (4 Oct 2026). Most readers arrive from a search, read one page and leave,
 * so a separate file buys them a cache they never use and costs them a round
 * trip they always pay. The minified sheet (21 KB on the wire, no url()
 * inside it, so nothing resolves differently) is now written into the head
 * where the <link> stood. The page itself is what LiteSpeed caches, so the
 * reader's cost is the same bytes, one request fewer.
 *
 * Only the built copy is inlined; while daw.css is newer than its build the
 * plain file is linked as before. Off with the option daw_css_inline = 0.
 */
add_filter( 'style_loader_tag', 'daw_css_inline', 10, 2 );
function daw_css_inline( $tag, $handle ) {
	if ( 'daw' !== $handle || is_admin() || is_customize_preview() || '0' === (string) get_option( 'daw_css_inline', '1' ) ) {
		return $tag;
	}
	if ( false === strpos( $tag, 'daw.min.css' ) ) {
		return $tag;
	}

	$file = get_stylesheet_directory() . '/assets/daw.min.css';
	$css  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions

	if ( '' === $css || false !== stripos( $css, '</style' ) ) {
		return $tag;
	}

	return '<style id="daw-css">' . $css . '</style>' . "\n";
}

/**
 * What a theme has to tell WordPress about itself.
 *
 * This used to be inherited, because this was a child theme and the parent
 * declared it. The parent is gone: seventy kilobytes of inline stylesheet on
 * every page, for a header, a footer and a layout this theme had already
 * replaced, plus its own stylesheet and its own script on top. What it still
 * carried were these declarations, so they move here and nothing else does.
 */
add_action( 'after_setup_theme', 'daw_theme_setup' );
function daw_theme_setup() {
	// Featured images are the posters and the portraits: the single most used
	// field on the site. Without this the box disappears from the editor.
	add_theme_support( 'post-thumbnails' );

	// The document title, which the SEO plugin then filters. A theme that does
	// not declare this prints no title at all.
	add_theme_support( 'title-tag' );

	add_theme_support(
		'html5',
		array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' )
	);

	// An embed that keeps its aspect ratio is an embed that does not move the
	// page when it loads.
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'automatic-feed-links' );

	// The reading measure is set in the stylesheet; this stops the editor
	// offering widths the templates do not honour.
	add_theme_support( 'align-wide' );

	// THE GUIDE-CARD WIDTH
	// --------------------
	// A guide card is 232px wide on a phone, 406 real pixels at 1.75x. The
	// sizes WordPress makes jump from 300 to 768, so the phone took the 768
	// file: 65 KB where 20 KB would do (PageSpeed, home page, 4 Oct 2026).
	// A 480px copy sits in between. Width only, so it keeps the photo's
	// shape and joins the same srcset.
	add_image_size( 'daw_guide', 480, 0, false );

	load_theme_textdomain( 'daw', get_stylesheet_directory() . '/languages' );
}

/* ====================================================== template helpers == */

/**
 * The poster adapter.
 *
 * Every poster and portrait on the site goes through this one function, so the
 * day we switch image sources — TMDB paths, a CDN, locally cached copies —
 * exactly one file changes and 926 records follow. Today it resolves to the
 * featured image, because that is what the records carry.
 *
 * @param int    $post_id
 * @param string $size  WordPress image size to request.
 */
function daw_poster( $post_id, $size = 'medium_large', $sizes_override = '' ) {
	// ONE COUNTER, ONE LEAD PICTURE
	// -----------------------------
	// Every poster used to ask for fetchpriority="high" and decoding="sync" up
	// to the eighth one on the page. Eight pictures at the top priority is the
	// same as none: on a phone they shared the first second of bandwidth with
	// the hero, and the hero, the page's Largest Contentful Paint, arrived
	// last of them. Lighthouse on the home page, 390px, 2 Oct 2026: LCP 5.3 s
	// with 27% of it "load delay" behind its own siblings.
	//
	// Now the first picture a request prints is the lead (it is the hero on
	// the home page, a title, a person and a group, which is where the LCP
	// is), and it alone is high priority. The next seven stay eager so the
	// first strip is never half painted, but they are asked for at low
	// priority: on a phone that strip is two screens down, and eager at the
	// browser's default still took the bandwidth the hero needed.
	// Nothing decodes synchronously: a sync decode holds the frame that
	// would have painted the text around it.
	static $printed = 0;

	$printed++;
	$lead  = 1 === $printed;
	$eager = $printed <= 8;

	// WHAT THE SLOT ACTUALLY IS
	// -------------------------
	// The grid description below is right for a card and wrong for the two
	// other shapes this prints into. A hero poster is 120 to 260 CSS pixels
	// wide, so being told "45vw" made a phone fetch the 500px file for a
	// 120px box. A thumbnail sits in a 40 to 84px face or row image.
	if ( '' !== $sizes_override ) {
		// The caller knows its slot (a guide card is landscape and 232px on
		// a phone, not a grid cell).
		$sizes = $sizes_override;
	} elseif ( 'thumbnail' === $size ) {
		$sizes = '96px';
	} elseif ( $lead && in_array( $size, array( 'large', 'medium_large' ), true ) ) {
		$sizes = '(max-width: 700px) 130px, 260px';
	} else {
		// WHY sizes IS WRITTEN OUT BY HAND
		// --------------------------------
		// WordPress derives it from the size that was requested, so asking
		// for the 214px file told the browser the slot was 214px wide and it
		// duly picked the 214px candidate for a card that renders at about
		// 176 CSS pixels -- 352 real ones on any modern screen. Every poster
		// on the site was being upscaled from a thumbnail. The slot is a
		// grid cell, so it is described as one.
		//
		// A PHONE CARD IS ABOUT 115 CSS PIXELS, NOT 45vw
		// ----------------------------------------------
		// On a phone every card shape is narrow: the rails are 112px, a cast
		// grid cell about 114px, three-across browse cells about 120px, the
		// hub rail 132px. "45vw" told a 412px phone the slot was 185px, so it
		// fetched the 342px poster and the 421px portrait for a 115px box:
		// PageSpeed counted 107 to 346 KB wasted per page (4 Oct 2026).
		// 105px is the honest slot rounded down a little, on purpose, so a
		// 1.75x phone takes TMDB's 185px file (1.6 pixels per CSS pixel, which
		// reads sharp on a poster) and a 3x phone takes the 342px file
		// instead of the 500px one. Wider phones and small tablets get a
		// third of the width, as their three-across grids are.
		//
		// A DESKTOP CELL IS AT MOST ABOUT 176 CSS PIXELS (1.75.45)
		// The page is 1148px inside its gutters: the hub strips are six
		// cells of 176px, a cast or browse grid seven of about 148px.
		// "220px" made a desktop at 1x skip TMDB's 185px file for the 342px
		// one, and PageSpeed counted 48 to 56 KB wasted per poster, 232 KB
		// on the home page and 477 to 506 KB on a list or section page
		// (desktop run, 7 Oct 2026). 180px picks the 185px file at 1x and
		// still the 342px file on a 2x screen.
		$sizes = '(max-width: 460px) 105px, (max-width: 699px) 31vw, (max-width: 1099px) 24vw, 180px';
	}

	$attrs = array(
		'loading'       => $eager ? 'eager' : 'lazy',
		'fetchpriority' => $lead ? 'high' : ( $eager ? 'low' : 'auto' ),
		'decoding'      => 'async',
		'sizes'         => $sizes,
	);

	// TITLES AND PEOPLE MAY BE SHOWN FROM TMDB
	// ----------------------------------------
	// daw-core decides where a record's picture comes from (a licensed local
	// file, TMDB's own server, or nothing safe to show). null means "carry on
	// as before", '' means "draw the monogram".
	if ( function_exists( 'daw_remote_image_html' ) ) {
		$remote = daw_remote_image_html( $post_id, $size, $attrs );
		if ( null !== $remote ) {
			return '' === $remote ? daw_monogram( $post_id ) : $remote;
		}
	}

	if ( ! has_post_thumbnail( $post_id ) ) {
		return daw_monogram( $post_id );
	}

	// THE FIRST FEW POSTERS ARE NOT LAZY
	// ----------------------------------
	// Every poster sits in a .daw-poster box that has a solid background, so a
	// picture that has not painted yet does not look like a picture loading. It
	// looks like a deliberate grey panel, and on a strip of six cards where five
	// have arrived, the sixth reads as broken. That is exactly what was reported:
	// one card "half showing" on the home page. Below the fold lazy loading is
	// still right; the counter resets per request.
	return get_the_post_thumbnail(
		$post_id,
		$size,
		$attrs + array(
			'alt' => sprintf(
				/* translators: %s: record title */
				esc_attr__( 'Poster for %s', 'daw' ),
				get_the_title( $post_id )
			),
		)
	);
}

/**
 * The stand-in for a record that has no picture.
 *
 * WHY IT IS DRAWN AND NOT UPLOADED
 * --------------------------------
 * Two of the people in the archive have no photograph anywhere we are allowed
 * to take one from, and there will be more. The three bad answers are: leave a
 * coloured hole, upload one stock face and repeat it, or generate a face. The
 * last one is the worst, because a made-up face on a real person's record is a
 * false claim about a real person, and it is the kind of thing nobody notices
 * until it is everywhere.
 *
 * So the gap says the record's name instead. It is drawn as inline SVG: it
 * costs no request, it takes the palette from the same tokens as everything
 * else, it is sharp at 40px and at 400px, and it fills whatever box it is
 * handed, which is how the grid keeps its shape whether the picture arrived
 * or not.
 */
function daw_monogram( $post_id ) {
	$title = wp_strip_all_tags( get_the_title( $post_id ) );
	$mark  = daw_initials( $title );

	if ( '' === $mark ) {
		return '';
	}

	return sprintf(
		'<svg class="daw-mono" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid slice" role="img" aria-label="%1$s">'
			. '<rect width="100" height="100"/>'
			. '<text x="50" y="50" text-anchor="middle" dominant-baseline="central">%2$s</text>'
			. '</svg>',
		esc_attr(
			sprintf(
				/* translators: %s: record title */
				__( 'No picture yet for %s', 'daw' ),
				$title
			)
		),
		esc_html( $mark )
	);
}

/**
 * Up to two initials from a record title, for the monogram above.
 *
 * Multibyte throughout, because the titles include native names, and a title
 * that is a number or a single word still has to produce something rather than
 * nothing.
 */
function daw_initials( $title ) {
	$title = trim( preg_replace( '/\s+/u', ' ', (string) $title ) );

	if ( '' === $title ) {
		return '';
	}

	$out = '';

	foreach ( preg_split( '/[\s\-]+/u', $title ) as $word ) {
		$word = preg_replace( '/^[^\p{L}\p{N}]+/u', '', $word );

		if ( '' === $word ) {
			continue;
		}

		$out .= mb_substr( $word, 0, 1 );

		if ( mb_strlen( $out ) >= 2 ) {
			break;
		}
	}

	if ( '' === $out ) {
		$out = mb_substr( $title, 0, 1 );
	}

	return mb_strtoupper( $out );
}

/**
 * Read a Meta Box value without requiring Meta Box to be loaded. Falls back to
 * raw post meta so a template never fatals because a plugin was toggled off.
 */
function daw_field( $key, $post_id = null, $single = true ) {
	$post_id = $post_id ? $post_id : get_the_ID();

	if ( function_exists( 'rwmb_meta' ) ) {
		return rwmb_meta( $key, array(), $post_id );
	}

	return get_post_meta( $post_id, $key, $single );
}

/**
 * Print one definition row, but only when there is something to say. Empty
 * rows are worse than missing ones: they read as a gap in the research.
 */
function daw_row( $label, $value ) {
	if ( is_array( $value ) ) {
		$value = implode( ', ', array_filter( $value ) );
	}
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return;
	}

	printf(
		'<div class="daw-field"><dt>%s</dt><dd>%s</dd></div>',
		esc_html( $label ),
		esc_html( $value )
	);
}

/**
 * The breadcrumb, from the same trail the schema states.
 *
 * Every step but the last is a link, because a step a reader cannot follow is
 * not a trail, and an unlinked "Dramas" was also an internal link the page
 * never gave (rule 3.5). daw_record_trail() in daw-core decides the steps; if
 * the plugin is missing the trail is simply Home and the record.
 */
function daw_crumb_nav( $post_id = null ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	$trail   = function_exists( 'daw_record_trail' )
		? daw_record_trail( $post_id )
		: array(
			array( 'name' => __( 'Home', 'daw' ), 'url' => home_url( '/' ) ),
			array( 'name' => get_the_title( $post_id ), 'url' => get_permalink( $post_id ) ),
		);
	$last    = count( $trail ) - 1;

	echo '<nav class="daw-crumb" aria-label="' . esc_attr__( 'Breadcrumb', 'daw' ) . '">';

	foreach ( $trail as $i => $step ) {
		if ( $i > 0 ) {
			echo '<span aria-hidden="true">›</span>';
		}

		if ( $i === $last ) {
			echo '<span aria-current="page">' . esc_html( $step['name'] ) . '</span>';
		} else {
			echo '<a href="' . esc_url( $step['url'] ) . '">' . esc_html( $step['name'] ) . '</a>';
		}
	}

	echo '</nav>';
}

/**
 * A term list as plain text, for the field rows.
 */
function daw_terms( $tax, $post_id = null ) {
	$terms = get_the_terms( $post_id ? $post_id : get_the_ID(), $tax );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}

	// Placeholder terms ("N/A", "Unknown", a dash) left by the old import are
	// "we do not know" written into a field; they are printed as nothing
	// (rule 2.2). A guide's on-screen card showed "2022 · N/A · …".
	$names = array();
	foreach ( $terms as $t ) {
		if ( ! function_exists( 'daw_is_placeholder_term' ) || ! daw_is_placeholder_term( $t->name ) ) {
			$names[] = $t->name;
		}
	}
	return implode( ', ', $names );
}

/**
 * Pull the video id out of any of the shapes YouTube urls come in.
 */
function daw_youtube_id( $url ) {
	if ( ! $url ) {
		return '';
	}
	if ( preg_match( '#(?:youtu\.be/|v=|embed/|shorts/)([A-Za-z0-9_-]{6,})#', $url, $m ) ) {
		return $m[1];
	}

	return '';
}

/**
 * A row in a rail card. Same rule as daw_row: nothing to say, nothing printed.
 */
/** A details row whose value is already-escaped HTML (linked names). */
function daw_rrow_html( $label, $html ) {
	if ( '' === trim( wp_strip_all_tags( (string) $html ) ) ) {
		return;
	}
	printf( '<div class="daw-rrow"><dt>%s</dt><dd>%s</dd></div>', esc_html( $label ), wp_kses( (string) $html, array( 'a' => array( 'href' => true ) ) ) );
}

function daw_rrow( $label, $value ) {
	if ( is_array( $value ) ) {
		$value = implode( ', ', array_filter( $value ) );
	}
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return;
	}

	printf(
		'<div class="daw-rrow"><dt>%s</dt><dd>%s</dd></div>',
		esc_html( $label ),
		esc_html( $value )
	);
}

/* ============================================================ ADVERTISING ===
 *
 * The publisher account and the five units below were created for this site
 * and nothing else. Each template slot maps to exactly one unit, so a report
 * in AdSense reads as a sentence about the page: "the billboard under the
 * long-form panels earned this much".
 *
 *   daw-ad-leaderboard  Under the hero, both templates.      horizontal
 *   daw-ad-billboard    After the long-form panels.          horizontal
 *   daw-ad-rail         Sticky, beside the article.          vertical
 *   daw-ad-rect         In the rail, lower down.             square
 *   daw-ad-incontent    Under the overview, every width.      horizontal
 */
const DAW_AD_CLIENT = 'ca-pub-7154604851132509';

// How much article the lower rail advert needs beside it before it is printed
// at all. See daw_ad_rail() for why this number is a height in disguise.
const DAW_AD_RAIL_MIN_WORDS = 450;

function daw_ad_units() {
	return array(
		'daw-ad-leaderboard' => array( '1962621764', 'DAW Leaderboard', '106px desktop / 280px phone' ),
		'daw-ad-billboard'   => array( '9669721392', 'DAW Billboard', '250px desktop / 280px phone' ),
		'daw-ad-rail'        => array( '8356639722', 'DAW Rail', '600px desktop / 280px phone' ),
		'daw-ad-rect'        => array( '5799768308', 'DAW Rect', '250px desktop / 280px phone' ),
		'daw-ad-incontent'   => array( '7929264318', 'DAW In-content', '250px desktop / 280px phone' ),
	);
}

/**
 * Whether this request may print real ad code.
 *
 * ONE REFUSAL ONLY
 * ----------------
 * Staging is not a site in the AdSense account, and serving ads from a host
 * Google was never told about is the kind of thing that gets a publisher
 * account suspended rather than warned. So the host has to be the production
 * domain, spelled out, not guessed from a wildcard.
 *
 * Everyone who loads the production domain sees the same thing, signed in or
 * not. An earlier version also hid the real ad from a signed-in editor and
 * printed a debug label instead, which meant the owner's own browser never
 * showed what a reader actually sees. By the owner's own request (2026-10-03)
 * that split is gone: the same markup renders for everyone, so a placement
 * problem is visible from the panel immediately instead of needing a logged-out
 * window to catch it.
 */
function daw_ads_live() {
	static $live = null;

	if ( null !== $live ) {
		return $live;
	}

	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$live = in_array( $host, array( 'discoverasianworld.com', 'www.discoverasianworld.com' ), true );

	return $live;
}

/**
 * The AdSense loader, once per page, and only on pages that will ask for it.
 */
add_action( 'wp_head', 'daw_ad_loader', 5 );
function daw_ad_loader() {
	if ( ! daw_ads_live() ) {
		return;
	}

	// AFTER THE PAGE, NOT BESIDE IT
	// -----------------------------
	// adsbygoogle.js is async, so it never blocked parsing, but on a phone it
	// still started in the first second and took bandwidth from the hero
	// image and the stylesheet: PageSpeed counted 229 KB of unused script on
	// the home page, most of it this file and what it pulls in. It is now
	// added once the page has finished loading. Every slot already reserves
	// its box (daw_ad_slot), and the push() calls in each slot queue up in
	// window.adsbygoogle until the library arrives, so no unit is lost and
	// nothing on the page moves when they fill.
	$src = sprintf(
		'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=%s',
		rawurlencode( DAW_AD_CLIENT )
	);

	//
	// AND NOT BEFORE THE READER IS THERE (1.47.27)
	// --------------------------------------------
	// Live PageSpeed after 1.47.26, 2 Oct 2026: CLS 0, LCP 2.2 s on a phone,
	// but 301 ms (phone) and 808 ms (desktop) of main-thread blocking and a
	// Speed Index of 5.2 s, almost all of it the ad library parsing and then
	// painting creatives into the boxes in the seconds after load. The
	// library now arrives on the reader's first scroll, tap, click or key,
	// or fifteen seconds after load if they do none of those. A reader who is
	// reading still gets every unit; nothing competes with the page itself
	// while it is being drawn.
	//
	// FIFTEEN, NOT SIX (1.47.30)
	// The six second fallback still fired inside PageSpeed's phone run on
	// some passes: mobile blocking time read 106 ms on one run and 647 ms on
	// the next with the same 68 KB of unused script, the difference being
	// whether the ad library had started by the time the trace closed.
	// Anyone who stays fifteen seconds without touching the page is not
	// reading it.
	//
	// NEVER INSIDE THE TAP ITSELF (1.55.0)
	// Starting the library on touchstart put its download, parse and the
	// first creatives on the main thread in the very gesture the reader was
	// making: the click that touch should have produced came late or not at
	// all, and on a phone the first tap on a link "did nothing" and a second
	// one worked (owner, 4 Oct 2026). The first movement now only books the
	// start 1.2 s later, in an idle moment, so the tap is answered first; a
	// tap that opens another page leaves before the library ever runs.
	printf(
		"<script>(function(){var d=0,q=0,ev=['scroll','pointerdown','keydown','touchstart','mousemove'];function go(){if(d)return;d=1;var s=document.createElement('script');s.async=true;s.crossOrigin='anonymous';s.src=%s;document.head.appendChild(s);}function soon(){if(q)return;q=1;ev.forEach(function(e){removeEventListener(e,soon,true);});setTimeout(function(){if(window.requestIdleCallback){requestIdleCallback(go,{timeout:1500});}else{go();}},1200);}ev.forEach(function(e){addEventListener(e,soon,{capture:true,passive:true});});addEventListener('load',function(){setTimeout(go,15000);},{once:true});})();</script>\n",
		wp_json_encode( $src )
	);

	// NO VIGNETTE ON OUR OWN LINKS (1.59.2)
	// -------------------------------------
	// Auto ads' full-screen "vignette" takes over a tapped link: it adds
	// #google_vignette to the address and waits for an advert. When none is
	// ready the tap seems to do nothing and the reader taps again, which is
	// exactly what the owner kept hitting on an iPhone (4 Oct 2026; owner's
	// choice: switch it off). Google honours data-google-vignette="false" on
	// a link: every link gets it on load, and any link added later gets it
	// the moment it is touched, before the ad script sees the tap.
	// AN EMPTY AD BOX DOES NOT STAY ON THE PAGE (1.75.16)
	// When AdSense has nothing to show it marks the unit
	// data-ad-status="unfilled" and the reserved box stays, dashed and empty:
	// the "gaps" readers saw. A box that is still below the screen when that
	// happens is taken out (nothing the reader is looking at moves, so it is
	// not a layout shift); one in view or already scrolled past stays, because
	// removing it would move the text under the reader.
	echo "<script>(function(){if(!window.MutationObserver)return;function chk(i){if(i.getAttribute('data-ad-status')!=='unfilled')return;var b=i.closest('.daw-ad');if(!b||b.hidden)return;if(b.getBoundingClientRect().top>window.innerHeight){b.hidden=true;b.style.display='none';}}new MutationObserver(function(ms){ms.forEach(function(m){if(m.target&&m.target.classList&&m.target.classList.contains('adsbygoogle')){chk(m.target);}});}).observe(document.documentElement,{subtree:true,attributes:true,attributeFilter:['data-ad-status']});})();</script>\n";

	echo "<script>(function(){function m(a){if(a&&a.tagName==='A'&&!a.hasAttribute('data-google-vignette')){a.setAttribute('data-google-vignette','false');}}function all(){var l=document.getElementsByTagName('a');for(var i=0;i<l.length;i++){m(l[i]);}}if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',all);}else{all();}['pointerdown','touchstart','mousedown','click'].forEach(function(e){addEventListener(e,function(ev){var t=ev.target;m(t&&t.closest?t.closest('a'):null);},{capture:true,passive:true});});})();</script>\n";
}

/**
 * An ad slot that has already reserved its height.
 *
 * The box is printed whether or not an advert fills it, which is the whole
 * point: the height is decided by our CSS before Google's script has loaded,
 * so nothing on the page moves when the advert arrives. The label is a policy
 * requirement, not a design flourish.
 */
function daw_ad_slot( $variant = '' ) {
	$units = daw_ad_units();
	$unit  = isset( $units[ $variant ] ) ? $units[ $variant ] : null;
	$slot  = $unit ? $unit[0] : '';

	printf(
		'<div class="daw-ad %s"><span class="daw-ad-label">%s</span>',
		esc_attr( $variant ),
		esc_html__( 'Advertisement', 'daw' )
	);

	// NO SEPARATE EDITOR VIEW
	// -----------------------
	// This used to print a debug label (unit name, slot id, reserved height)
	// only for a signed-in editor, instead of the real ad, so the owner's own
	// browser never rendered what a reader actually sees. By the owner's own
	// request (2026-10-03) that split is gone: the box below is the same
	// markup for every visitor, on every host. On staging it still reserves
	// its height and shows only the "Advertisement" label above, because
	// daw_ads_live() stays false there; on production it is the real unit,
	// signed in or not.

	// A UNIT THAT FITS ITS BOX, ON EVERY SCREEN
	// -----------------------------------------
	// The units were data-ad-format="auto" with full-width responsive on.
	// On a phone that let AdSense pick a 460px creative for a 280px box,
	// write height:auto !important onto the box to make room, and pull the
	// unit out past the page gutter with a negative margin: the advert came
	// out cut in half and the whole page slid sideways under the reader's
	// thumb (reported on a drama page, 2 Oct 2026).
	//
	// Now every unit asks for a shape its box is built for. Below 981px that
	// is a rectangle (300x250 or 336x280), which the full-bleed phone box
	// holds whole. From 981px up each slot asks for its own shape: a wide
	// unit for the leaderboard and billboard, a tall one for the rail. The
	// choice is made in the browser at push time, because the page cache
	// serves one copy of the html to every screen size.
	$wide = array(
		'daw-ad-leaderboard' => 'horizontal',
		'daw-ad-billboard'   => 'horizontal',
		'daw-ad-rail'        => 'vertical',
	);

	if ( $slot && daw_ads_live() ) {
		printf(
			'<ins class="adsbygoogle" style="display:block;width:100%%" data-ad-client="%s" data-ad-slot="%s" data-ad-format="rectangle" data-full-width-responsive="false"%s></ins>'
			. '<script>(function(i){if(i&&i.getAttribute("data-wide")&&window.matchMedia("(min-width: 981px)").matches){i.setAttribute("data-ad-format",i.getAttribute("data-wide"));}(adsbygoogle=window.adsbygoogle||[]).push({});})(document.currentScript&&document.currentScript.previousElementSibling);</script>',
			esc_attr( DAW_AD_CLIENT ),
			esc_attr( $slot ),
			isset( $wide[ $variant ] ) ? ' data-wide="' . esc_attr( $wide[ $variant ] ) . '"' : ''
		);
	}

	echo '</div>';
}

/**
 * How many words a rendered column actually holds.
 *
 * Counted from the finished HTML rather than from the fields behind it,
 * because a field can be full while the section it feeds is never printed,
 * and a record can be long without any single field being long.
 */
function daw_col_words( $html ) {
	$text = wp_strip_all_tags( (string) $html );
	$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

	if ( '' === $text ) {
		return 0;
	}

	return count( preg_split( '/\s+/u', $text ) );
}

/**
 * The lower rail advert, printed only where the article can carry it.
 *
 * THE PAGE WAS BEING STRETCHED BY ITS OWN ADVERTS
 * -----------------------------------------------
 * The rail is a fixed stack: details, an advert, the rating card, where to
 * watch, then a second advert. On a record with a short article that stack was
 * taller than the column beside it, so the page grew a long empty strip on the
 * left just to make room for an advert on the right. A reader scrolls past
 * nothing to reach it, and the advert is worth nothing there.
 *
 * So the second unit is conditional. Below the threshold it is not printed at
 * all -- not hidden with CSS, not loaded and collapsed, simply absent, which is
 * also the only version that costs the reader nothing. Above it, the article is
 * long enough that the unit sits beside text rather than below it.
 *
 * The number is a height in disguise. The rail's fixed cards come to roughly
 * 900px, and a 250px unit under them needs about that much article again;
 * at the site's measure that is around 450 words. It is deliberately one
 * constant in one place: if the rail gains or loses a card, this is what moves.
 */
function daw_ad_rail( $column_html, $min_words = DAW_AD_RAIL_MIN_WORDS ) {
	if ( daw_col_words( $column_html ) < (int) $min_words ) {
		return;
	}

	daw_ad_slot( 'daw-ad-rail' );
}


/**
 * The platform mark on a "where to watch" row.
 *
 * The logo fetching lives in the plugin, because it writes to the media
 * library and that is data, not presentation. The theme only asks for the
 * mark, and degrades to a coloured monogram if the plugin is not there — a
 * record should never lose its watch links because a plugin was toggled off.
 */
function daw_watch_mark( $url, $platform = '', $px = 32 ) {
	if ( function_exists( 'daw_platform_mark' ) ) {
		return daw_platform_mark( $url, $platform, $px );
	}

	return sprintf(
		'<span class="daw-watch-mark" aria-hidden="true">%s</span>',
		esc_html( mb_strtoupper( mb_substr( (string) $platform, 0, 1 ) ) )
	);
}

function daw_watch_label( $url, $platform = '' ) {
	if ( function_exists( 'daw_platform_label' ) ) {
		return daw_platform_label( $url, $platform );
	}

	return $platform;
}

/**
 * The second line on an official-links row: what this link actually points at.
 *
 * WHY NOT THE HOST
 * ----------------
 * The row used to read "Instagram / www.instagram.com", which is the label
 * spelled twice. Under a name the reader already knows, the useful second line
 * is the identity on the other side: the handle they can search for, the IMDb
 * code that is the same string in every database, the Wikipedia edition they
 * are being sent to. It also lets a reader spot a wrong link without opening
 * it, which is the whole job of a reference card.
 *
 * Everything here is read out of the url itself, so it cannot say anything the
 * link does not already say, and an address this function does not recognise
 * falls back to the bare host rather than guessing.
 *
 * @param string $url   The destination.
 * @param string $label The row label, used only to disambiguate.
 * @return string Plain text, already trimmed. May be empty.
 */
function daw_link_handle( $url, $label = '' ) {
	$url  = trim( (string) $url );
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );

	if ( '' === $host ) {
		return '';
	}

	$host = preg_replace( '/^www\./', '', $host );
	$segs = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
	$one  = isset( $segs[0] ) ? rawurldecode( $segs[0] ) : '';

	// An @ in front of a name is how every one of these platforms writes it,
	// and how a reader would type it into their own app.
	$at = static function ( $name ) {
		$name = ltrim( (string) $name, '@' );

		return '' === $name ? '' : '@' . $name;
	};

	if ( preg_match( '/(^|\.)(instagram\.com|threads\.net|threads\.com|tiktok\.com|x\.com|twitter\.com)$/', $host ) ) {
		return $at( $one );
	}

	if ( preg_match( '/(^|\.)youtube\.com$/', $host ) ) {
		if ( '' !== $one && '@' === $one[0] ) {
			return $at( $one );
		}
		if ( in_array( $one, array( 'c', 'user' ), true ) && isset( $segs[1] ) ) {
			return $at( rawurldecode( $segs[1] ) );
		}
		// A raw channel id is not a handle and is no use to a reader, so the
		// line says what the destination is instead of showing the id.
		return __( 'Channel', 'daw' );
	}

	if ( preg_match( '/(^|\.)facebook\.com$/', $host ) ) {
		return ( '' === $one || 'profile.php' === $one ) ? $host : $at( $one );
	}

	if ( preg_match( '/(^|\.)weibo\.(com|cn)$/', $host ) ) {
		if ( 'u' === $one && isset( $segs[1] ) ) {
			/* translators: %s: numeric Weibo user id. */
			return sprintf( __( 'User %s', 'daw' ), $segs[1] );
		}

		return $at( $one );
	}

	// nm0000123 for a person, tt0000123 for a title. The same code identifies
	// the record in half the databases a reader might check next.
	if ( preg_match( '/(^|\.)imdb\.com$/', $host ) ) {
		return preg_match( '/\b((?:nm|tt|co)\d{5,})\b/i', $path, $m ) ? strtolower( $m[1] ) : $host;
	}

	// Which edition, not which article: the article is the person whose page
	// this is, so the language is the only thing the line can add.
	if ( preg_match( '/(^|\.)wikipedia\.org$/', $host ) ) {
		$lang = strtok( $host, '.' );
		$map  = array(
			'en' => __( 'English', 'daw' ),
			'ko' => __( 'Korean', 'daw' ),
			'ja' => __( 'Japanese', 'daw' ),
			'zh' => __( 'Chinese', 'daw' ),
			'th' => __( 'Thai', 'daw' ),
			'tr' => __( 'Turkish', 'daw' ),
			'es' => __( 'Spanish', 'daw' ),
			'fr' => __( 'French', 'daw' ),
		);

		$name = $map[ $lang ] ?? strtoupper( (string) $lang );

		/* translators: %s: language of a Wikipedia edition, e.g. English. */
		return sprintf( __( '%s article', 'daw' ), $name );
	}

	if ( preg_match( '/(^|\.)spotify\.com$/', $host ) ) {
		return __( 'Artist page', 'daw' );
	}

	return $host;
}

/**
 * Military status reads as a sentence, not as a database value.
 * "serving" in a cell is a slug; "Currently serving" is an answer.
 */
function daw_person_military_label( $status ) {
	$map = array(
		'completed' => __( 'Completed', 'daw' ),
		'serving'   => __( 'Currently serving', 'daw' ),
		'enlisting' => __( 'Due to enlist', 'daw' ),
		'exempt'    => __( 'Exempt', 'daw' ),
		'na'        => __( 'Not applicable', 'daw' ),
	);

	$status = (string) $status;

	return $map[ $status ] ?? '';
}

/**
 * A record type as a word a reader would use. Slugs belong in the database,
 * not in a table cell.
 */
/**
 * Is this record a single work or something that runs in episodes?
 *
 * The title template asks this five times, to choose between "Released" and
 * "Premiere", between "Shop this film" and "Shop this drama", and so on. It
 * used to ask "is the type exactly film", which quietly made every kind that
 * is not a film into a drama: add a variety show and the page calls it a
 * drama in five places.
 *
 * The plugin owns the list, because the same split decides Movie against
 * TVSeries in the schema and those two answers must never disagree. The local
 * fallback is only for a page rendered with the plugin switched off.
 */
function daw_is_single_work( $type ) {
	if ( function_exists( 'daw_schema_screen_kinds' ) ) {
		$map = daw_schema_screen_kinds();
		return isset( $map[ $type ] ) && 'movie' === $map[ $type ];
	}

	return 'film' === $type;
}

/**
 * The word for this kind inside a sentence: "Shop this film", "if you liked
 * this show". Lower case on purpose; daw_kind_label() is the one that heads a
 * badge.
 */
function daw_kind_noun( $kind ) {
	$map = array(
		'drama'   => __( 'drama', 'daw' ),
		'film'    => __( 'film', 'daw' ),
		'variety' => __( 'show', 'daw' ),
	);

	return $map[ (string) $kind ] ?? __( 'title', 'daw' );
}

/**
 * The label for any record type, guides included.
 *
 * daw_kind_label() only knows the screen kinds, because that is all a
 * filmography badge ever shows. A hub prints food, culture, myth and travel
 * too, and those live in the plugin's own list of record types, which is the
 * one place they are named.
 */
function daw_type_label( $type ) {
	if ( function_exists( 'daw_record_types' ) ) {
		$types = daw_record_types();
		if ( isset( $types[ $type ] ) ) {
			return html_entity_decode( (string) $types[ $type ], ENT_QUOTES, 'UTF-8' );
		}
	}

	return daw_kind_label( $type );
}

function daw_kind_label( $kind ) {
	$map = array(
		'drama'   => __( 'Drama', 'daw' ),
		'film'    => __( 'Film', 'daw' ),
		'variety' => __( 'Variety', 'daw' ),
		'group'   => __( 'Music group', 'daw' ),
		'person'  => __( 'Person', 'daw' ),
	);

	// A LABEL IS A CLAIM, SO AN UNKNOWN KIND GETS NO LABEL
	// ----------------------------------------------------
	// This used to fall back to "Drama", which is how a card for BTS came to
	// read "BTS Drama" on a news page. A default that asserts something false
	// is worse than a card with one line instead of two, so an unrecognised
	// kind now says nothing and the caller drops the line.
	return $map[ (string) $kind ] ?? '';
}

/* =============================================================== cards === */
/*
 * THE CARD PARTIALS LIVE HERE, NOT IN A PAGE TEMPLATE
 * ---------------------------------------------------
 * They started at the bottom of page-country.php, which worked for exactly as
 * long as the country hub was the only thing that drew a card. The moment a
 * second template wanted the same grid, calling these from it was a fatal
 * error: WordPress loads one page template per request, so a function defined
 * in page-country.php simply does not exist while page-section.php is running.
 *
 * A partial shared by two templates belongs to the theme, not to either one of
 * them. That is the whole reason this block moved.
 */

/**
 * A section heading, with its "see all" on the right.
 *
 * Five sections wanted the same three lines of markup with different words in
 * them, and the fifth is where the markup drifts: one heading ends up an h3, one
 * link ends up inside the heading, and the page stops looking like one page.
 */
function daw_hub_head( $title, $note = '', $url = '', $link_text = '' ) {
	?>
	<div class="daw-hubhead">
		<div>
			<h2>
				<?php if ( $url ) : ?>
					<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $title ); ?>
				<?php endif; ?>
			</h2>
			<?php if ( $note ) : ?>
				<p class="daw-sectionnote"><?php echo esc_html( $note ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( $url && $link_text ) : ?>
			<a class="daw-hubmore" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $link_text ); ?></a>
		<?php endif; ?>
	</div>
	<?php
}

/** One guide, as a landscape card. */
/**
 * One person as a card, for a grid rather than a rail.
 *
 * The same shape as a title card so a mixed grid lines up, with the line under
 * the name saying what the person does and how much of it is filed here. The
 * credit count is the honest measure of how complete a profile is, and putting
 * it on the card means nobody opens an empty one to find out.
 */
function daw_person_card( $post_id ) {
	$job = daw_field( 'daw_occupation', $post_id );
	$job = $job ? wp_specialchars_decode( (string) $job, ENT_QUOTES ) : daw_type_label( daw_get_type( $post_id ) );

	$credits = function_exists( 'daw_credit_count' ) ? (int) daw_credit_count( $post_id ) : 0;

	$line = implode(
		' · ',
		array_filter(
			array(
				$job,
				/* translators: %s: number of credits */
				$credits ? sprintf( _n( '%s credit', '%s credits', $credits, 'daw' ), number_format_i18n( $credits ) ) : '',
			)
		)
	);
	?>
	<a class="daw-person daw-hubcard" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
		<span class="daw-poster"><?php echo daw_poster( $post_id, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
		<span class="daw-person-name"><?php echo esc_html( get_the_title( $post_id ) ); ?></span>
		<?php if ( $line ) : ?>
			<span class="daw-person-role"><?php echo esc_html( $line ); ?></span>
		<?php endif; ?>
	</a>
	<?php
}

function daw_hub_guide_card( $post_id, $wide = false ) {
	$read = function_exists( 'daw_guide_read_time' ) ? (int) daw_guide_read_time( $post_id ) : 0;

	// A guide photograph is landscape: 232px wide in a phone's swipe row,
	// the full column in a browse list on a phone, about 340px on a desktop.
	$g_sizes = $wide ? '(max-width: 860px) calc(100vw - 32px), 340px' : '(max-width: 760px) 232px, 340px';
	daw_guide_size_need( (int) get_post_thumbnail_id( $post_id ) );
	?>
	<a class="daw-hubguide" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
		<span class="daw-poster daw-hubguide-img"><?php echo daw_poster( $post_id, 'medium', $g_sizes ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php
		// THE FLAG ON THE PICTURE
		// -----------------------
		// The country is written under the title, but a row of photographs is
		// read before any line of small text is. A flag in the corner answers
		// "where is this" at a glance (owner, 24 Sep 2026). Only the countries
		// the site draws a flag for, at most three, in the order the terms give.
		$g_flags = '';
		if ( function_exists( 'daw_country_flag' ) ) {
			$g_slugs = wp_get_post_terms( $post_id, 'dw_country', array( 'fields' => 'slugs' ) );
			foreach ( is_array( $g_slugs ) ? array_slice( $g_slugs, 0, 3 ) : array() as $g_slug ) {
				$g_flags .= daw_country_flag( $g_slug, 16 );
			}
		}
		if ( '' !== $g_flags ) {
			echo '<span class="daw-hubguide-flags">' . $g_flags . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		?></span>
		<span class="daw-hubguide-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></span>
		<?php
		// WHICH COUNTRY, ON THE CARD
		// --------------------------
		// A browse page of four travel guides cannot offer a country filter
		// worth the name: a filter with one option in it is furniture. What a
		// reader actually wants to know is which country this particular guide
		// is about, and the place to answer that is the card, where they are
		// already looking. The filter appears by itself once there are enough
		// countries for it to mean something.
		$g_country = function_exists( 'daw_terms' ) ? daw_terms( 'dw_country', $post_id ) : '';
		$g_line    = array_filter(
			array(
				$g_country,
				/* translators: %d: minutes */
				$read ? sprintf( __( '%d min read', 'daw' ), (int) $read ) : '',
			)
		);
		?>
		<?php if ( $g_line ) : ?>
			<span class="daw-hubguide-meta"><?php echo esc_html( implode( ' · ', $g_line ) ); ?></span>
		<?php endif; ?>
	</a>
	<?php
}

/**
 * One title, as a poster card.
 *
 * The rating badge only appears on a title somebody has actually rated: an
 * empty star on every card would read as "nobody liked any of these" rather
 * than as "no votes yet".
 */
function daw_hub_title_card( $post_id, $meta = 'network', $badge = '' ) {
	$start = (int) daw_field_raw( $post_id, 'daw_aired_start' );
	$year  = $start ? gmdate( 'Y', $start ) : '';
	$score = function_exists( 'daw_title_rating_value' ) ? daw_title_rating_value( $post_id ) : 0;

	if ( 'kind' === $meta ) {
		$line = daw_type_label( daw_get_type( $post_id ) );
	} elseif ( 'genre' === $meta ) {
		$line = daw_first_term_name( $post_id, 'dw_genre' );
	} else {
		$net  = daw_first_term_name( $post_id, 'dw_network' );
		$eps  = (int) daw_field_raw( $post_id, 'daw_episodes' );
		$line = implode(
			' · ',
			array_filter(
				array(
					$net,
					/* translators: %s: number of episodes */
					$eps ? sprintf( _n( '%s ep', '%s eps', $eps, 'daw' ), number_format_i18n( (int) $eps ) ) : '',
				)
			)
		);
	}
	?>
	<a class="daw-person daw-hubcard" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
		<span class="daw-poster">
			<?php echo daw_poster( $post_id, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php if ( $score ) : ?>
				<span class="daw-hubscore">★ <?php echo esc_html( number_format_i18n( $score, 1 ) ); ?></span>
			<?php endif; ?>
			<?php if ( $year ) : ?>
				<span class="daw-hubyear"><?php echo esc_html( $year ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $badge ) : // "Ep 3 today" on the home page's on-air rail (1.75.79). ?>
				<span class="daw-hubbadge"><?php echo esc_html( $badge ); ?></span>
			<?php endif; ?>
		</span>
		<span class="daw-person-name"><?php echo esc_html( get_the_title( $post_id ) ); ?></span>
		<?php if ( $line ) : ?>
			<span class="daw-person-role"><?php echo esc_html( $line ); ?></span>
		<?php endif; ?>
	</a>
	<?php
}

/**
 * One search result, as a row.
 *
 * The line under the name is the reason this is not a list of titles: two
 * people called Kim Soo something are told apart by the country and the number
 * of credits, and two dramas by the network and the year. So each type says the
 * things that separate it from its neighbours, and nothing else.
 */
function daw_search_row( $post_id, $query = '' ) {
	$type  = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
	$label = function_exists( 'daw_type_label' ) ? daw_type_label( $type ) : '';
	$bits  = array();

	if ( 'person' === $type || 'group' === $type ) {
		$job = daw_field_raw( $post_id, 'daw_occupation' );
		$job = $job ? wp_specialchars_decode( (string) $job, ENT_QUOTES ) : '';

		$credits = function_exists( 'daw_credit_count' ) ? (int) daw_credit_count( $post_id ) : 0;
		$born    = (int) daw_field_raw( $post_id, 'daw_born' );

		$bits = array(
			$job,
			function_exists( 'daw_terms' ) ? daw_terms( 'dw_country', $post_id ) : '',
			/* translators: %s: number of credits */
			$credits ? sprintf( _n( '%s credit', '%s credits', $credits, 'daw' ), number_format_i18n( $credits ) ) : '',
			$born ? sprintf( /* translators: %s: a date */ __( 'Born %s', 'daw' ), date_i18n( 'j M Y', $born ) ) : '',
		);
	} elseif ( in_array( $type, array( 'drama', 'film', 'variety' ), true ) ) {
		$start = (int) daw_field_raw( $post_id, 'daw_aired_start' );
		$eps   = (int) daw_field_raw( $post_id, 'daw_episodes' );

		$bits = array(
			$label,
			$start ? gmdate( 'Y', $start ) : '',
			function_exists( 'daw_first_term_name' ) ? daw_first_term_name( $post_id, 'dw_network' ) : '',
			/* translators: %s: number of episodes */
			$eps ? sprintf( _n( '%s episode', '%s episodes', $eps, 'daw' ), number_format_i18n( (int) $eps ) ) : '',
		);
	} else {
		$read = function_exists( 'daw_guide_read_time' ) ? (int) daw_guide_read_time( $post_id ) : 0;

		$bits = array(
			$label,
			function_exists( 'daw_terms' ) ? daw_terms( 'dw_country', $post_id ) : '',
			/* translators: %d: minutes */
			$read ? sprintf( __( '%d min read', 'daw' ), $read ) : '',
		);
	}

	$line  = implode( ' · ', array_filter( array_map( 'trim', $bits ) ) );
	$score = function_exists( 'daw_title_rating_value' ) ? daw_title_rating_value( $post_id ) : 0;
	?>
	<a class="daw-sresult" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
		<span class="daw-sresult-img"><?php echo daw_poster( $post_id, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>

		<span class="daw-sresult-txt">
			<span class="daw-sresult-t"><?php echo daw_search_mark( get_the_title( $post_id ), $query ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<?php if ( $line ) : ?>
				<span class="daw-sresult-m"><?php echo esc_html( $line ); ?></span>
			<?php endif; ?>
		</span>

		<span class="daw-sresult-side">
			<?php if ( $label ) : ?>
				<span class="daw-sresult-k"><?php echo esc_html( $label ); ?></span>
			<?php endif; ?>
			<?php if ( $score ) : ?>
				<span class="daw-sresult-s">★ <?php echo esc_html( number_format_i18n( $score, 1 ) ); ?></span>
			<?php endif; ?>
		</span>
	</a>
	<?php
}

/**
 * Mark the part of a title the reader actually typed.
 *
 * The whole string is escaped first and the marker is added afterwards, so a
 * title containing a bracket or an ampersand is safe and a search phrase cannot
 * carry markup into the page.
 */
function daw_search_mark( $title, $query ) {
	$title = esc_html( $title );
	$query = trim( (string) $query );

	if ( '' === $query ) {
		return $title;
	}

	$needle = esc_html( $query );
	$at     = mb_stripos( $title, $needle );

	if ( false === $at ) {
		return $title;
	}

	return mb_substr( $title, 0, $at )
		. '<mark>' . mb_substr( $title, $at, mb_strlen( $needle ) ) . '</mark>'
		. mb_substr( $title, $at + mb_strlen( $needle ) );
}

/**
 * One band member, as a card.
 *
 * The line under the name is their position in the group, as the group itself
 * states it, and the badge on the picture is the one thing about a member that
 * is a fact rather than a description: who leads and who is youngest. Where the
 * record does not say, the card says nothing rather than guessing.
 */
function daw_group_member_card( $post_id ) {
	$position = daw_field( 'daw_group_position', $post_id );
	$badge    = (string) daw_field( 'daw_group_badge', $post_id );

	$badges = array(
		'leader' => __( 'Leader', 'daw' ),
		'maknae' => __( 'Maknae', 'daw' ),
		'former' => __( 'Former member', 'daw' ),
	);

	// Without a stated position the card falls back to what the person does at
	// large, which is the same line the People page shows.
	if ( '' === trim( (string) $position ) ) {
		$position = daw_field( 'daw_occupation', $post_id );
		$position = $position ? wp_specialchars_decode( (string) $position, ENT_QUOTES ) : '';
	}
	?>
	<a class="daw-person daw-hubcard daw-gmember<?php echo 'former' === $badge ? ' is-former' : ''; ?>" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
		<span class="daw-poster">
			<?php echo daw_poster( $post_id, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php if ( $badge && isset( $badges[ $badge ] ) ) : ?>
				<span class="daw-gbadge"><?php echo esc_html( $badges[ $badge ] ); ?></span>
			<?php endif; ?>
		</span>
		<span class="daw-person-name"><?php echo esc_html( get_the_title( $post_id ) ); ?></span>
		<?php if ( $position ) : ?>
			<span class="daw-person-role"><?php echo esc_html( $position ); ?></span>
		<?php endif; ?>
	</a>
	<?php
}

/**
 * The picture of a group, as an <img>, or an empty string.
 *
 * The same choice the group's own page makes: a freely licensed photograph with
 * a complete rights record first, then the square artist picture the label put
 * on the act's Spotify page (shown by address, never copied here). Nothing else
 * is used, so a group with neither shows no picture rather than a guess.
 */
function daw_group_image_html( $group_id, $size = 'medium', &$credit = '' ) {
	$group_id = (int) $group_id;
	$credit   = '';
	$att      = (int) get_post_thumbnail_id( $group_id );
	$lic      = ( $att && function_exists( 'daw_lic_get' ) ) ? daw_lic_get( $att ) : array();
	$open     = $lic && in_array( $lic['type'], array( 'cc0', 'pd', 'cc-by', 'cc-by-sa', 'kogl1' ), true ) && function_exists( 'daw_lic_status' ) && 'ok' === daw_lic_status( $att, $group_id );

	if ( $open ) {
		$credit = function_exists( 'daw_lic_credit_html' ) ? (string) daw_lic_credit_html( $att ) : '';

		return wp_get_attachment_image( $att, $size, false, array( 'loading' => 'lazy', 'decoding' => 'async', 'alt' => get_the_title( $group_id ) ) );
	}

	$url = trim( (string) get_post_meta( $group_id, 'daw_group_image_url', true ) );

	if ( '' === $url ) {
		// The group page's own last resort: whatever poster the record has.
		return function_exists( 'daw_poster' ) ? (string) daw_poster( $group_id, $size ) : '';
	}

	return sprintf(
		'<img src="%s" alt="%s" width="300" height="300" loading="lazy" decoding="async" referrerpolicy="no-referrer">',
		esc_url( $url ),
		esc_attr( get_the_title( $group_id ) )
	);
}

/**
 * The facts a reader wants about the group a person belongs to, as label/value
 * rows: what the person does there, when the group began, who stands behind it,
 * and what it put out last. Anything the group record does not state is left
 * out, so a thin record gives a short list and never a row of blanks.
 */
function daw_group_panel_facts( $group_id, $person_id ) {
	$group_id  = (int) $group_id;
	$person_id = (int) $person_id;
	$rows      = array();

	$badges = array(
		'leader' => __( 'Leader', 'daw' ),
		'maknae' => __( 'Maknae', 'daw' ),
		'former' => __( 'Former member', 'daw' ),
	);

	$position = trim( (string) daw_field( 'daw_group_position', $person_id ) );
	$badge    = (string) daw_field( 'daw_group_badge', $person_id );
	$role     = array();

	if ( isset( $badges[ $badge ] ) ) {
		$role[] = $badges[ $badge ];
	}

	if ( '' !== $position ) {
		$role[] = $position;
	}

	if ( $role ) {
		$rows[ __( 'Role', 'daw' ) ] = implode( ' · ', $role );
	}

	$map = array(
		__( 'Debut', 'daw' )         => 'daw_group_debut',
		__( 'Debut release', 'daw' ) => 'daw_group_debut_release',
		__( 'Agency', 'daw' )        => 'daw_agency',
		__( 'Fandom', 'daw' )        => 'daw_group_fandom',
		__( 'Status', 'daw' )        => 'daw_group_status',
	);

	foreach ( $map as $label => $field ) {
		$value = trim( wp_specialchars_decode( (string) daw_field( $field, $group_id ), ENT_QUOTES ) );

		if ( '' !== $value ) {
			$rows[ $label ] = ucfirst( $value );
		}
	}

	if ( function_exists( 'daw_group_releases' ) ) {
		$releases = daw_group_releases( $group_id );

		if ( $releases ) {
			$new   = $releases[0];
			$value = $new['title'] . ( $new['year'] ? ' (' . $new['year'] . ')' : '' );
			$rows[ __( 'Latest release', 'daw' ) ] = $value;
		}
	}

	return $rows;
}

/**
 * Prose from a record field, with another editor's leftover styling removed.
 *
 * Lives here rather than in the plugin because the name daw_clean_html was
 * already taken there, and a second definition of it took the whole site down
 * with a redeclare fatal. Checking that a function name is free is not
 * optional: php -l reads one file at a time and cannot see the collision.
 */
function daw_prose( $html ) {
	// Defensive: every caller passes a single wysiwyg/textarea field, which
	// should always be a string — but if a past write ever stored one as an
	// array (one paragraph per element, say), casting it straight to a
	// string prints the literal word "Array" on the page instead of the
	// content (seen live on the Pinocchio benchmark record, 2026-10-01).
	// Join it back into one block of HTML so the page shows the content
	// instead of PHP's array-to-string notice text.
	if ( is_array( $html ) ) {
		$html = implode( "\n\n", array_filter( array_map( 'strval', $html ) ) );
	}

	$html = function_exists( 'daw_strip_editor_junk' )
		? daw_strip_editor_junk( $html )
		: (string) $html;

	// Prose stored as plain text is printed in paragraphs (1.75.62). A body
	// written without <p> came out as one bare text node inside the div: a
	// reader saw one block, and the audit's first-paragraph check (rule 5.5)
	// skipped it and measured the next <p> on the page, a card footnote.
	// Text that already carries block markup is left as it is.
	if ( '' !== trim( (string) $html ) && ! preg_match( '/<(p|ul|ol|table|h[2-6]|div|blockquote|figure|dl)\b/i', (string) $html ) ) {
		$html = wpautop( (string) $html );
	}
	return $html;
}

/**
 * "Cookie preferences" in the footer (1.50.1).
 *
 * The button carried data-cmp-open but nothing listened to it, so a reader in
 * the EEA, UK or Switzerland had no way to change an earlier answer, which
 * the GDPR requires to be as easy as giving it. A click now asks Google's
 * consent message (AdSense Privacy & messaging) to open again. The ad library
 * that brings the message is started by this same click (daw_ad_loader
 * listens for pointerdown), so the request waits in googlefc.callbackQueue
 * until the message is ready. Outside the regulated regions Google shows no
 * message and the click does nothing visible.
 */
add_action( 'wp_footer', 'daw_cmp_open' );
function daw_cmp_open() {
	if ( ! daw_ads_live() ) {
		return;
	}
	echo "<script>document.addEventListener('click',function(e){var b=e.target&&e.target.closest?e.target.closest('[data-cmp-open]'):null;if(!b){return;}e.preventDefault();var w=window;w.googlefc=w.googlefc||{};w.googlefc.callbackQueue=w.googlefc.callbackQueue||[];w.googlefc.callbackQueue.push({'CONSENT_DATA_READY':function(){w.googlefc.showRevocationMessage();}});});</script>\n";
}

/**
 * Existing guide photographs were uploaded before the 480px size existed.
 * A card that finds its photo without it queues the photo, and one
 * background job a minute later cuts the missing size for everything queued
 * (up to 20 at a time), then clears the page cache once so the new srcset
 * reaches readers. New uploads get the size the normal way.
 */
function daw_guide_size_need( $att_id ) {
	if ( $att_id <= 0 ) {
		return;
	}
	$meta = wp_get_attachment_metadata( $att_id );
	if ( ! is_array( $meta ) || empty( $meta['width'] ) || (int) $meta['width'] <= 480 || isset( $meta['sizes']['daw_guide'] ) ) {
		return;
	}
	$done = (array) get_option( 'daw_guide_size_fail', array() );
	if ( isset( $done[ $att_id ] ) ) {
		return;
	}
	$queue = (array) get_option( 'daw_guide_size_q', array() );
	if ( isset( $queue[ $att_id ] ) ) {
		return;
	}
	$queue[ $att_id ] = 1;
	update_option( 'daw_guide_size_q', $queue, false );
	if ( ! wp_next_scheduled( 'daw_guide_size_run' ) ) {
		wp_schedule_single_event( time() + 60, 'daw_guide_size_run' );
	}
}

add_action( 'daw_guide_size_run', 'daw_guide_size_run' );
function daw_guide_size_run() {
	$queue = (array) get_option( 'daw_guide_size_q', array() );
	if ( ! $queue ) {
		return;
	}
	if ( ! function_exists( 'wp_update_image_subsizes' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	$fail = (array) get_option( 'daw_guide_size_fail', array() );
	$made = 0;
	foreach ( array_slice( array_keys( $queue ), 0, 20 ) as $att_id ) {
		unset( $queue[ $att_id ] );
		$res = function_exists( 'wp_update_image_subsizes' ) ? wp_update_image_subsizes( (int) $att_id ) : new WP_Error( 'no_fn' );
		$meta = wp_get_attachment_metadata( (int) $att_id );
		if ( is_wp_error( $res ) || ! isset( $meta['sizes']['daw_guide'] ) ) {
			// Missing original or an editor error: never retried in a loop.
			$fail[ (int) $att_id ] = time();
		} else {
			++$made;
		}
	}

	update_option( 'daw_guide_size_q', $queue, false );
	update_option( 'daw_guide_size_fail', $fail, false );

	if ( $queue ) {
		wp_schedule_single_event( time() + 60, 'daw_guide_size_run' );
	} elseif ( $made ) {
		do_action( 'litespeed_purge_all' );
	}
}

/**
 * The date on a news card: "Updated 3 Oct 2026" when a timeline row was added
 * after publication, otherwise the day it was published (SEO spec item 5).
 */
function daw_home_news_date( $id ) {
	$posted  = (int) get_post_time( 'U', true, $id );
	$updated = function_exists( 'daw_news_updated_ts' ) ? daw_news_updated_ts( $id ) : 0;
	if ( $updated > $posted + HOUR_IN_SECONDS ) {
		/* translators: %s: date the story was last updated */
		return sprintf( __( 'Updated %s', 'daw' ), wp_date( 'j M Y', $updated ) );
	}
	return wp_date( 'j F Y', $posted );
}
