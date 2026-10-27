<?php
/**
 * Discover Asian World — Google News sitemap.
 *
 * WHY A SEPARATE FILE, NOT RANK MATH
 * -----------------------------------
 * Rank Math's own News Sitemap module exists and already had a publication
 * name and post type saved in its settings, but the module itself is PRO-only
 * and this site runs the free version. Turning it on fails with
 * "This module is available in the PRO version." Rather than buy a licence
 * for one XML file, this writes the same thing by hand — the format is a
 * public, fixed Google spec, not something that needs a paid plugin.
 *
 * WHY NOT A REWRITE RULE
 * -----------------------
 * daw-core's own header promises every taxonomy "registers with public =>
 * false and rewrite => false… nothing here touches permalinks, adds rewrite
 * rules". A news sitemap needs a URL, but it does not need to be inside
 * WordPress's routing to get one: this writes a real, static .xml file to
 * the uploads folder (the same trick GBC used for CSS it wanted in a real
 * file instead of a shortcode) and serves it as a plain static asset. No
 * rewrite rule, no query var, nothing added to permalink resolution.
 *
 * WHAT GOES IN IT, AND WHY ONLY THAT
 * -----------------------------------
 * Google's spec: only articles published in the last 2 days, at most 1,000
 * URLs. This is not "our news archive" — it is a change-ping for a crawler
 * that already knows about everything older. Putting dramas, people or
 * groups in it would violate Google News content policy (the feed has to be
 * actual news), so the query is pinned to the `news` dw_type term and
 * nothing else, by term ID, not by guessing a slug.
 *
 * WHEN IT REGENERATES
 * --------------------
 * On publish/update of any news post (so a new story appears within
 * seconds), and twice a day on its own (so a story that ages past 48 hours
 * drops out even if nothing new was published). Both call the same function;
 * there is one way this file is written, not two slightly different ones.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_NEWS_SITEMAP_TERM_ID = 10878; // dw_type: News. Fixed ID, not a slug lookup, so a renamed term can't silently empty the feed.
const DAW_NEWS_SITEMAP_FILE    = 'daw-news-sitemap.xml';
const DAW_NEWS_SITEMAP_WINDOW  = 2 * DAY_IN_SECONDS; // Google News: only the last 2 days belong in this feed.
const DAW_NEWS_SITEMAP_MAX     = 1000; // Google's own cap per news sitemap file.

/**
 * The publication name Google News shows readers.
 *
 * Rank Math already had one saved (news_sitemap_publication_name) from
 * before the module turned out to be PRO-only; reusing it means the name
 * only has to be right in one place, and changing it in Rank Math's own
 * sitemap settings screen (which still renders even with the module off)
 * is enough to change it here too.
 */
function daw_news_sitemap_publication_name() {
	$rank_math = get_option( 'rank-math-options-sitemap' );
	$name      = is_array( $rank_math ) && ! empty( $rank_math['news_sitemap_publication_name'] )
		? (string) $rank_math['news_sitemap_publication_name']
		: '';

	// Rank Math's options stay in the database after it is switched off, but
	// if they are ever cleaned up the engine's own copy still has the name.
	if ( '' === $name && function_exists( 'daw_eng_set' ) ) {
		$name = (string) daw_eng_set( 'news_name', '' );
	}

	/**
	 * Filters the Google News publication name.
	 *
	 * @param string $name
	 */
	return apply_filters( 'daw_news_sitemap_publication_name', '' !== $name ? $name : get_bloginfo( 'name' ) );
}

/** Absolute filesystem path the sitemap is written to. */
function daw_news_sitemap_path() {
	$uploads = wp_upload_dir();
	return trailingslashit( $uploads['basedir'] ) . DAW_NEWS_SITEMAP_FILE;
}

/** Public URL of the written file. */
function daw_news_sitemap_url() {
	$uploads = wp_upload_dir();
	return trailingslashit( $uploads['baseurl'] ) . DAW_NEWS_SITEMAP_FILE;
}

/**
 * Builds the XML and writes it to uploads. Returns true on success.
 *
 * Nothing here can break a page request: it only ever runs on save_post or
 * on the twice-daily cron, never while a visitor's page is rendering.
 */
function daw_news_sitemap_write() {
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAW_NEWS_SITEMAP_WINDOW );

	$query = new WP_Query(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'date_query'             => array(
				array( 'column' => 'post_date_gmt', 'after' => $cutoff, 'inclusive' => true ),
			),
			'tax_query'               => array(
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'term_id',
					'terms'    => DAW_NEWS_SITEMAP_TERM_ID,
				),
			),
			'orderby'                 => 'date',
			'order'                   => 'DESC',
			'posts_per_page'          => DAW_NEWS_SITEMAP_MAX,
			'no_found_rows'           => true,
			'update_post_meta_cache'  => false,
			'update_post_term_cache'  => false,
			'ignore_sticky_posts'     => true,
		)
	);

	$pub_name = esc_html( daw_news_sitemap_publication_name() );
	$lang     = 'en';

	$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ';
	$xml .= 'xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

	foreach ( $query->posts as $post ) {
		$loc   = esc_url( get_permalink( $post ) );
		$title = esc_html( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
		$date  = mysql2date( DATE_W3C, get_post_time( 'Y-m-d H:i:s', true, $post ), false );

		if ( '' === $loc || '' === $title ) {
			continue; // Never print a <url> block the spec would reject outright.
		}

		$xml .= "\t<url>\n";
		$xml .= "\t\t<loc>{$loc}</loc>\n";
		$xml .= "\t\t<news:news>\n";
		$xml .= "\t\t\t<news:publication>\n";
		$xml .= "\t\t\t\t<news:name>{$pub_name}</news:name>\n";
		$xml .= "\t\t\t\t<news:language>{$lang}</news:language>\n";
		$xml .= "\t\t\t</news:publication>\n";
		$xml .= "\t\t\t<news:publication_date>{$date}</news:publication_date>\n";
		$xml .= "\t\t\t<news:title>{$title}</news:title>\n";
		$xml .= "\t\t</news:news>\n";
		$xml .= "\t</url>\n";
	}

	$xml .= '</urlset>' . "\n";

	wp_reset_postdata();

	$written = @file_put_contents( daw_news_sitemap_path(), $xml ); // phpcs:ignore -- deliberate, see guard below.

	return false !== $written;
}

/**
 * Regenerate on publish/update of a news post.
 *
 * Fires on every save, not only the publish transition, because an edit to
 * an already-published story (a correction, a title fix) should reflect in
 * the feed immediately too — Google re-crawls the sitemap, not the page, to
 * find out something changed.
 */
add_action( 'save_post_post', 'daw_news_sitemap_maybe_regenerate', 20, 3 );
function daw_news_sitemap_maybe_regenerate( $post_id, $post, $update ) {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		return;
	}
	if ( ! has_term( DAW_NEWS_SITEMAP_TERM_ID, 'dw_type', $post ) ) {
		return;
	}
	daw_news_sitemap_write();
}

/**
 * A story taken down leaves the feed at once, not at the next cron.
 *
 * save_post above only rebuilds when the record is published, so a news item
 * moved back to draft, made private or trashed stayed in the sitemap for up to
 * twelve hours, pointing Google News at a url that now 404s.
 */
add_action( 'transition_post_status', 'daw_news_sitemap_on_unpublish', 20, 3 );
function daw_news_sitemap_on_unpublish( $new_status, $old_status, $post ) {
	if ( 'publish' !== $old_status || 'publish' === $new_status || 'post' !== $post->post_type ) {
		return;
	}
	if ( ! has_term( DAW_NEWS_SITEMAP_TERM_ID, 'dw_type', $post ) ) {
		return;
	}
	daw_news_sitemap_write();
}

/**
 * The feed named in robots.txt.
 *
 * The file is written to /wp-content/uploads/, and under the sitemap protocol a
 * sitemap may only list urls at or below its own folder unless robots.txt (or
 * Search Console) vouches for it. robots.txt listed only Rank Math's index, so
 * the news feed was undiscoverable and, strictly, not allowed to list the
 * stories at all. Late priority so it lands after Rank Math's own edits.
 */
add_filter( 'robots_txt', 'daw_news_sitemap_robots', 999, 2 );
function daw_news_sitemap_robots( $output, $public ) {
	if ( ! $public || ! file_exists( daw_news_sitemap_path() ) ) {
		return $output;
	}

	$line = 'Sitemap: ' . daw_news_sitemap_url();

	if ( false !== strpos( (string) $output, $line ) ) {
		return $output;
	}

	return rtrim( (string) $output ) . "\n" . $line . "\n";
}

/**
 * Hourly, so a story leaves the 48-hour window within the hour even without a
 * new publish (8 Oct 2026, SEO spec item 4; it was twice a day, which let a
 * story sit in the file for up to 60 hours). An event left on the old
 * schedule is moved once.
 */
add_action( 'init', 'daw_news_sitemap_schedule' );
function daw_news_sitemap_schedule() {
	if ( wp_next_scheduled( 'daw_news_sitemap_refresh' ) && 'hourly' !== wp_get_schedule( 'daw_news_sitemap_refresh' ) ) {
		wp_clear_scheduled_hook( 'daw_news_sitemap_refresh' );
	}
	if ( ! wp_next_scheduled( 'daw_news_sitemap_refresh' ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'daw_news_sitemap_refresh' );
	}
}
add_action( 'daw_news_sitemap_refresh', 'daw_news_sitemap_write' );

/* The info box with a "Rebuild now" link that sat on the SEO screen is gone
   (5 Oct 2026): it looked like a sitemap switch, and the file already rebuilds
   on every news publish and twice a day. sitemap_index.xml lists it. */
