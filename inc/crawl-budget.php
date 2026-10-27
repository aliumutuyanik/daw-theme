<?php
/**
 * Crawl budget: Googlebot's visits go to records, not to lists and plumbing.
 *
 * WHY (16 Oct 2026, owner: "gereksiz kotayı harcamamalı, Google'ın botlarına
 * en değerli sayfaları göstermeliyiz")
 * ------------------------------------------------------------------------
 * 800 of 1,269 pages had never been fetched by Google ("Discovered" or
 * "unknown"), while every page on the site linked eight to twenty filtered
 * lists (/dramas/?genre=romance, ?network=tvn, ?status=upcoming, ...) and
 * every <head> pointed at four REST and oEmbed addresses. browse.php already
 * makes each filtered list noindex with its canonical on the bare list, and
 * on 8 Oct they were deliberately left open in robots.txt so Google could
 * see that noindex. It has seen it: the lists have been noindex for eight
 * days. Left open, each combination is still fetched again and again, and
 * that is the budget the 800 records are waiting for. Google's own guidance
 * for faceted navigation that should not be indexed is a robots.txt rule.
 *
 * WHAT IS CLOSED
 *   any query string        filters, sort, ?s= site search, oEmbed and
 *                           ?replytocom-style variants. ?pg=N stays open:
 *                           page five of a bare list is indexable and is
 *                           the only listing that links its deepest records.
 *   /search/                the pretty form of the site search.
 *   /<anything>/feed/       per-post comment feeds (comments are closed).
 *                           The site feed /feed/ stays open for discovery.
 * Stylesheets, scripts and images under /wp-content/ and /wp-includes/
 * carry ?ver= and must stay fetchable or Google renders an unstyled page,
 * so both folders are allowed by a longer (and therefore winning) rule.
 *
 * WHAT IS NO LONGER ADVERTISED
 *   <link rel="https://api.w.org/">, the per-page JSON alternate, the two
 *   oEmbed discovery links, the REST Link: header and the comments feed.
 *   None of them is a page; each was an extra address on every page.
 *
 * Records, people, hubs, the bare lists and their ?pg= pages are untouched.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

/** The lines added to the "User-agent: *" group. */
function daw_cb_robots_lines() {
	return array(
		'# Crawl budget (daw-core 1.75.75): lists with filters, site search and comment feeds are not crawled.',
		'Disallow: /*?',
		'Allow: /*?pg=',
		'Allow: /wp-content/',
		'Allow: /wp-includes/',
		'Disallow: /search/',
		'Disallow: /*/feed/',
	);
}

/*
 * Runs after the DAW SEO body (20) and before the AI-crawler block (30), and
 * writes into the "User-agent: *" group itself: lines appended at the end of
 * the file would belong to whichever group came last (Yandex or the AI list)
 * and Googlebot would never read them.
 */
add_filter( 'robots_txt', 'daw_cb_robots_txt', 25, 2 );
function daw_cb_robots_txt( $output, $public ) {
	if ( ! $public || false !== strpos( (string) $output, 'Disallow: /*?' ) ) {
		return $output;
	}
	$lines = explode( "\n", str_replace( "\r", '', (string) $output ) );
	foreach ( $lines as $i => $line ) {
		if ( 0 === strcasecmp( trim( $line ), 'User-agent: *' ) ) {
			// After the group's other User-agent lines, if any, and before its rules.
			$at = $i + 1;
			while ( isset( $lines[ $at ] ) && 0 === stripos( trim( $lines[ $at ] ), 'user-agent:' ) ) {
				++$at;
			}
			array_splice( $lines, $at, 0, daw_cb_robots_lines() );
			return implode( "\n", $lines );
		}
	}
	// No "User-agent: *" group at all: open one.
	return "User-agent: *\n" . implode( "\n", daw_cb_robots_lines() ) . "\n\n" . ltrim( (string) $output );
}

/* --------------------------------------------- addresses that are not pages */

add_action( 'init', 'daw_cb_head_links' );
function daw_cb_head_links() {
	remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 10 );
	remove_action( 'template_redirect', 'rest_output_link_header', 11 );
	remove_action( 'wp_head', 'feed_links_extra', 3 );
}

add_filter( 'feed_links_show_comments_feed', '__return_false' );
