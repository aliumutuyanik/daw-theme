<?php
/**
 * Discover Asian World: what LiteSpeed is allowed to do to our pages.
 *
 * WHAT WAS MEASURED
 * -----------------
 * 2 Oct 2026, PageSpeed on the live home page, logged out: CLS 1.0 on both
 * phone and desktop, first paint 6.9 s on a phone. The same theme and plugin,
 * rendered on a local WordPress with no LiteSpeed and no adverts, measured
 * CLS 0.001 (390px) and 0.002 (1440px) in Lighthouse. So the templates do not
 * move. Something between them and the reader does.
 *
 * The live report also said only 16 KB of the page's CSS was unused, where
 * the full stylesheet leaves over 300 KB unused on the same page. That is
 * LiteSpeed's "unique CSS": the page is first painted with a reduced copy of
 * the stylesheet built by a crawler, and the real one is loaded afterwards.
 * Whatever the reduced copy missed (a phone media query, a state the crawler
 * never saw) paints wrong and then jumps when the full file lands. A page
 * shift of the whole viewport is exactly what CLS 1.0 means.
 *
 * WHAT IS TURNED OFF, AND WHERE
 * -----------------------------
 * Page optimisation only (CSS and JS rewriting, unique and critical CSS, HTML
 * minify, LiteSpeed's lazy load), and only on pages our own templates draw.
 * The page cache is untouched: that is the part of LiteSpeed that makes the
 * site fast, and it keeps working on every page.
 *
 * Our pages do not need the optimiser. They carry one minified stylesheet
 * (20 KB compressed), no jQuery, a few hundred bytes of inline script and
 * native lazy loading with reserved boxes. Every rewrite on top of that is a
 * chance to move the page and nothing left to gain.
 *
 * HOW TO UNDO IT
 * --------------
 * update_option( 'daw_litespeed_optm', 'allow' ) gives LiteSpeed the pages
 * back without a release. The default is 'bypass'.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether LiteSpeed's page optimiser should leave this request alone.
 */
function daw_litespeed_bypass() {
	if ( is_admin() || 'allow' === get_option( 'daw_litespeed_optm', 'bypass' ) ) {
		return false;
	}

	return function_exists( 'daw_is_daw_page' ) && daw_is_daw_page();
}

/**
 * The switch, thrown once the query is known.
 *
 * LiteSpeed reads these constants when it rewrites the output buffer, which
 * happens at the very end of the request, so defining them on
 * template_redirect is early enough. The filters are the same request in the
 * form newer releases also listen for; an unknown filter costs nothing.
 */
add_action( 'template_redirect', 'daw_litespeed_switch', 0 );
function daw_litespeed_switch() {
	if ( ! daw_litespeed_bypass() ) {
		return;
	}

	if ( ! defined( 'LITESPEED_NO_OPTM' ) ) {
		define( 'LITESPEED_NO_OPTM', true );
	}
	if ( ! defined( 'LITESPEED_NO_LAZY' ) ) {
		define( 'LITESPEED_NO_LAZY', true );
	}

	add_filter( 'litespeed_can_optm', '__return_false' );
}

/**
 * Let the site's own MCP read the settings this file is about.
 *
 * Without this the only way to know whether unique CSS, async CSS or guest
 * mode is on is to open wp-admin, and the measurement above had to be
 * inferred from a PageSpeed number. These are read-only names: the MCP
 * allowlist adds keys it may read, nothing it may write.
 */
add_filter( 'royal_mcp_readable_options', 'daw_litespeed_readable_options' );
function daw_litespeed_readable_options( $names ) {
	$names = is_array( $names ) ? $names : array();

	foreach ( array(
		'litespeed.conf.optm-css_min',
		'litespeed.conf.optm-css_comb',
		'litespeed.conf.optm-ucss',
		'litespeed.conf.optm-ccss_per_url',
		'litespeed.conf.optm-css_async',
		'litespeed.conf.optm-css_async_inline',
		'litespeed.conf.optm-js_min',
		'litespeed.conf.optm-js_comb',
		'litespeed.conf.optm-js_defer',
		'litespeed.conf.optm-js_delay_inc',
		'litespeed.conf.optm-html_min',
		'litespeed.conf.optm-guest_only',
		'litespeed.conf.guest',
		'litespeed.conf.guest_optm',
		'litespeed.conf.media-lazy',
		'litespeed.conf.media-add_missing_sizes',
		'litespeed.conf.img_optm-webp',
		'daw_litespeed_optm',
		'daw_speed_last',
		// Whether Site Kit is also placing an AdSense tag (and Auto ads) on
		// top of the theme's own loader.
		'googlesitekit_active_modules',
		'googlesitekit_adsense_settings',
		'googlesitekit_sign-in-with-google_settings',
		'googlesitekit_analytics-4_settings',
		// Whether the HSTS block was written to .htaccess (inc/hsts.php).
		'daw_hsts',
		'daw_hsts_written',
	) as $name ) {
		$names[] = $name;
	}

	return array_values( array_unique( $names ) );
}
