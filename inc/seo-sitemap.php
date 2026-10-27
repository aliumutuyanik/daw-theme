<?php
/**
 * Discover Asian World: the XML sitemap, at the addresses Rank Math used.
 *
 * SAME ADDRESSES, ON PURPOSE
 * --------------------------
 * Search Console was given https://discoverasianworld.com/sitemap_index.xml,
 * and robots.txt names it. The index lists post-sitemap1.xml to
 * post-sitemapN.xml and page-sitemap.xml, exactly the names Rank Math wrote,
 * so nothing has to be resubmitted and no crawler meets a 404. /sitemap.xml
 * and WordPress's own /wp-sitemap.xml answer with a 301 to the index.
 *
 * NO REWRITE RULE
 * ---------------
 * daw-core promises not to add rewrite rules. The sitemap does not need one:
 * the request path is compared with a short list at parse_request, before the
 * main query runs, and the answer is printed straight away. A path that is not
 * a sitemap costs one string comparison.
 *
 * WHAT IS IN IT
 * -------------
 * Published posts and pages, without a password, that are not marked noindex
 * in their own robots setting, two hundred to a file, ordered by id so a page
 * never moves from one file to another as the archive grows. Each url carries
 * its last-modified date and its pictures: the record's TMDB poster or
 * portrait where it has one, the featured image otherwise, and the pictures in
 * the written text.
 *
 * Each file is built once and kept until something is published, changed or
 * removed, so a crawler asking for it costs a single option read.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** Rank Math is gone, or an admin asked to see ours with ?daw_preview=1. */
function daw_sm_should_serve() {
	if ( function_exists( 'daw_eng_live' ) && daw_eng_live() ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return isset( $_GET['daw_preview'] ) && current_user_can( 'manage_options' );
}

add_action( 'parse_request', 'daw_sm_route', 0 );
function daw_sm_route() {
	if ( is_admin() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

	if ( '' !== $home && '/' !== $home && 0 === strpos( $path, rtrim( $home, '/' ) ) ) {
		$path = substr( $path, strlen( rtrim( $home, '/' ) ) );
	}

	$path = '/' . ltrim( $path, '/' );

	// "/sitemap_index.xml/" and the like: answer at the address without the slash.
	if ( '/' !== $path && '/' === substr( $path, -1 ) && preg_match( '#\.(xml|xsl)/$#', $path ) && daw_sm_should_serve() ) {
		wp_safe_redirect( home_url( rtrim( $path, '/' ) ), 301 );
		exit;
	}

	// Quick exit: nothing below ends in anything but .xml or .xsl.
	if ( '.xml' !== substr( $path, -4 ) && '.xsl' !== substr( $path, -4 ) ) {
		return;
	}

	if ( ! daw_sm_should_serve() ) {
		return;
	}

	if ( '/sitemap.xml' === $path || '/wp-sitemap.xml' === $path || preg_match( '#^/wp-sitemap-[a-z0-9_-]+\.xml$#', $path ) ) {
		wp_safe_redirect( home_url( '/sitemap_index.xml' ), 301 );
		exit;
	}

	if ( '/main-sitemap.xsl' === $path ) {
		daw_sm_send( daw_sm_xsl(), 'text/xsl' );
	}

	if ( '/sitemap_index.xml' === $path ) {
		daw_sm_send( daw_sm_cached( 'index', 'daw_sm_build_index' ) );
	}

	if ( preg_match( '#^/([a-z0-9_]+)-sitemap(\d*)\.xml$#', $path, $m ) ) {
		$type = $m[1];
		$page = '' === $m[2] ? 1 : (int) $m[2];

		if ( in_array( $type, daw_sm_types(), true ) && $page >= 1 && $page <= daw_sm_pages( $type ) ) {
			daw_sm_send(
				daw_sm_cached(
					$type . '-' . $page,
					static function () use ( $type, $page ) {
						return daw_sm_build_urlset( $type, $page );
					}
				)
			);
		}

		// A file number past the end is a real 404, not an empty sitemap.
	}
}

/** Print an XML answer and stop. */
function daw_sm_send( $body, $type = 'application/xml' ) {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'daw sitemap' );

	status_header( 200 );
	header( 'Content-Type: ' . $type . '; charset=UTF-8' );
	header( 'X-Robots-Tag: noindex, follow', true );
	header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );

	if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped below.
	}

	exit;
}

/* ================================================================ data === */

/** Post types in the sitemap: the ones Rank Math had on, if they still exist. */
function daw_sm_types() {
	$types = array();

	foreach ( (array) daw_eng_set( 'sitemap_types', array( 'post', 'page' ) ) as $type ) {
		$pto = get_post_type_object( $type );
		if ( $pto && $pto->public ) {
			$types[] = $type;
		}
	}

	return $types;
}

function daw_sm_per_page() {
	return max( 50, min( 1000, (int) daw_eng_set( 'sitemap_per_page', 200 ) ) );
}

/** Ids nobody wants in the sitemap, from the settings. */
function daw_sm_excluded() {
	return array_filter( array_map( 'intval', preg_split( '/[\s,]+/', (string) daw_eng_set( 'sitemap_exclude', '' ) ) ) );
}

/**
 * Every eligible url of one post type as [id, modified], in id order.
 *
 * @return array<int, array{0:int,1:string}>
 */
function daw_sm_rows( $type ) {
	static $memo = array();

	if ( isset( $memo[ $type ] ) ) {
		return $memo[ $type ];
	}

	global $wpdb;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT p.ID, p.post_modified_gmt FROM {$wpdb->posts} p
			 WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_password = ''
			 AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} r
				WHERE r.post_id = p.ID AND r.meta_key = 'rank_math_robots' AND r.meta_value LIKE %s
			 )
			 ORDER BY p.ID ASC",
			$type,
			'%noindex%'
		),
		ARRAY_N
	);

	$skip = array_flip( daw_sm_excluded() );
	$out  = array();

	foreach ( (array) $rows as $row ) {
		if ( ! isset( $skip[ (int) $row[0] ] ) ) {
			$out[] = array( (int) $row[0], (string) $row[1] );
		}
	}

	/**
	 * Filters the rows of one sitemap type.
	 *
	 * @param array  $out  [id, modified gmt] pairs.
	 * @param string $type Post type.
	 */
	$memo[ $type ] = (array) apply_filters( 'daw_sitemap_rows', $out, $type );

	return $memo[ $type ];
}

function daw_sm_pages( $type ) {
	return (int) ceil( count( daw_sm_rows( $type ) ) / daw_sm_per_page() );
}

/** The file name a crawler asks for, Rank Math's way: a number only when there is more than one. */
function daw_sm_file_name( $type, $page ) {
	return $type . '-sitemap' . ( daw_sm_pages( $type ) > 1 ? (int) $page : '' ) . '.xml';
}

function daw_sm_w3c( $gmt ) {
	$ts = strtotime( $gmt . ' UTC' );

	return $ts ? gmdate( 'c', $ts ) : '';
}

function daw_sm_esc( $text ) {
	return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/**
 * The pictures of one url: record picture or featured image, then the
 * pictures in the written text, on this host or TMDB, at most ten.
 */
function daw_sm_images( WP_Post $post ) {
	$urls = array();

	if ( function_exists( 'daw_get_type' ) && function_exists( 'daw_record_image_url' ) && function_exists( 'daw_remote_types' ) ) {
		$type = daw_get_type( $post->ID );
		if ( $type && in_array( $type, (array) daw_remote_types(), true ) ) {
			$url = (string) daw_record_image_url( $post->ID, 'large' );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
	}

	if ( ! $urls && has_post_thumbnail( $post ) ) {
		$url = (string) get_the_post_thumbnail_url( $post, 'full' );
		if ( '' !== $url ) {
			$urls[] = $url;
		}
	}

	if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $post->post_content, $m ) ) {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		foreach ( $m[1] as $src ) {
			$src = html_entity_decode( $src, ENT_QUOTES, 'UTF-8' );
			if ( 0 === strpos( $src, '//' ) ) {
				$src = 'https:' . $src;
			}
			if ( ! preg_match( '#^https?://#i', $src ) ) {
				continue;
			}
			$h = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
			if ( $h === $host || 'image.tmdb.org' === $h ) {
				$urls[] = $src;
			}
		}
	}

	return array_slice( array_values( array_unique( $urls ) ), 0, 10 );
}

/* ============================================================== build === */

function daw_sm_head( $root ) {
	$xsl = esc_url( home_url( '/main-sitemap.xsl' ) );

	if ( 'sitemapindex' === $root ) {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<?xml-stylesheet type="text/xsl" href="' . $xsl . '"?>' . "\n"
			. '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	}

	return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
		. '<?xml-stylesheet type="text/xsl" href="' . $xsl . '"?>' . "\n"
		. '<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"'
		. ' xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd http://www.google.com/schemas/sitemap-image/1.1 http://www.google.com/schemas/sitemap-image/1.1/sitemap-image.xsd"'
		. ' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
}

/** Every child sitemap with the newest change inside it. */
function daw_sm_index_entries() {
	$out = array();
	$per = daw_sm_per_page();

	foreach ( daw_sm_types() as $type ) {
		$rows  = daw_sm_rows( $type );
		$pages = (int) ceil( count( $rows ) / $per );

		for ( $i = 1; $i <= $pages; $i++ ) {
			$chunk  = array_slice( $rows, ( $i - 1 ) * $per, $per );
			$newest = '';
			foreach ( $chunk as $row ) {
				if ( $row[1] > $newest ) {
					$newest = $row[1];
				}
			}
			$out[] = array(
				'loc'     => home_url( '/' . daw_sm_file_name( $type, $i ) ),
				'lastmod' => daw_sm_w3c( $newest ),
			);
		}
	}

	// The Google News feed (news-sitemap.php) is a real file on this host.
	if ( function_exists( 'daw_news_sitemap_path' ) && file_exists( daw_news_sitemap_path() ) ) {
		$out[] = array(
			'loc'     => daw_news_sitemap_url(),
			'lastmod' => gmdate( 'c', (int) filemtime( daw_news_sitemap_path() ) ),
		);
	}

	return $out;
}

function daw_sm_build_index() {
	$xml = daw_sm_head( 'sitemapindex' );

	foreach ( daw_sm_index_entries() as $e ) {
		$xml .= "\t<sitemap>\n\t\t<loc>" . daw_sm_esc( $e['loc'] ) . "</loc>\n";
		if ( '' !== $e['lastmod'] ) {
			$xml .= "\t\t<lastmod>" . daw_sm_esc( $e['lastmod'] ) . "</lastmod>\n";
		}
		$xml .= "\t</sitemap>\n";
	}

	return $xml . '</sitemapindex>' . "\n<!-- DAW sitemap -->\n";
}

/**
 * The url entries of one file, as data, so the comparison tool can read the
 * same list the crawler gets.
 *
 * @return array<int, array{loc: string, lastmod: string, images: string[]}>
 */
function daw_sm_file_entries( $type, $page ) {
	$per  = daw_sm_per_page();
	$rows = array_slice( daw_sm_rows( $type ), ( $page - 1 ) * $per, $per );
	$ids  = array_map(
		static function ( $r ) {
			return $r[0];
		},
		$rows
	);

	if ( ! $ids ) {
		return array();
	}

	_prime_post_caches( $ids, true, true );

	$with_images = (bool) daw_eng_set( 'sitemap_images', true );
	$out         = array();

	foreach ( $rows as $row ) {
		$post = get_post( $row[0] );
		if ( ! $post ) {
			continue;
		}

		$out[] = array(
			'loc'     => (string) get_permalink( $post ),
			'lastmod' => daw_sm_w3c( $row[1] ),
			'images'  => $with_images ? daw_sm_images( $post ) : array(),
		);
	}

	return $out;
}

function daw_sm_build_urlset( $type, $page ) {
	$xml = daw_sm_head( 'urlset' );

	foreach ( daw_sm_file_entries( $type, $page ) as $e ) {
		$xml .= "\t<url>\n\t\t<loc>" . daw_sm_esc( $e['loc'] ) . "</loc>\n";
		if ( '' !== $e['lastmod'] ) {
			$xml .= "\t\t<lastmod>" . daw_sm_esc( $e['lastmod'] ) . "</lastmod>\n";
		}
		foreach ( $e['images'] as $img ) {
			$xml .= "\t\t<image:image>\n\t\t\t<image:loc>" . daw_sm_esc( $img ) . "</image:loc>\n\t\t</image:image>\n";
		}
		$xml .= "\t</url>\n";
	}

	return $xml . '</urlset>' . "\n<!-- DAW sitemap -->\n";
}

/* ============================================================== cache === */

/** A built file, kept until the next change to the archive. */
function daw_sm_cached( $name, $builder ) {
	$key  = 'daw_sm_' . md5( $name . '|' . (int) get_option( 'daw_sm_gen', 1 ) . '|' . daw_sm_per_page() );
	$body = get_transient( $key );

	if ( is_string( $body ) && '' !== $body ) {
		return $body;
	}

	$body = (string) call_user_func( $builder );
	set_transient( $key, $body, DAY_IN_SECONDS );

	return $body;
}

/** Anything published, changed, unpublished or removed makes every file stale. */
add_action( 'transition_post_status', 'daw_sm_bump_on_status', 10, 3 );
function daw_sm_bump_on_status( $new, $old, $post ) {
	if ( ( 'publish' === $new || 'publish' === $old ) && in_array( $post->post_type, daw_sm_types(), true ) ) {
		daw_sm_bump();
	}
}

add_action( 'deleted_post', 'daw_sm_bump' );
add_action( 'updated_post_meta', 'daw_sm_bump_on_robots', 10, 3 );
add_action( 'added_post_meta', 'daw_sm_bump_on_robots', 10, 3 );
function daw_sm_bump_on_robots( $meta_id, $post_id, $key ) {
	if ( 'rank_math_robots' === $key ) {
		daw_sm_bump();
	}
}

function daw_sm_bump() {
	update_option( 'daw_sm_gen', (int) get_option( 'daw_sm_gen', 1 ) + 1, true );
}

/* ================================================================ xsl === */

/** A readable page for people who open the sitemap in a browser. */
function daw_sm_xsl() {
	return <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="2.0"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
	xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
<xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes"/>
<xsl:template match="/">
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="robots" content="noindex, follow"/>
<title>XML Sitemap</title>
<style>
body{font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1d2327;margin:0;padding:24px;background:#fff}
h1{font-size:20px;margin:0 0 4px}
p{color:#50575e;margin:0 0 16px}
table{border-collapse:collapse;width:100%}
th,td{text-align:left;padding:6px 10px;border-bottom:1px solid #e5e5e5;vertical-align:top}
th{background:#f6f7f7;font-weight:600}
a{color:#2271b1;text-decoration:none;word-break:break-all}
td.n{color:#787c82;white-space:nowrap}
</style>
</head>
<body>
<h1>XML Sitemap</h1>
<xsl:choose>
<xsl:when test="count(sitemap:sitemapindex/sitemap:sitemap) &gt; 0">
<p><xsl:value-of select="count(sitemap:sitemapindex/sitemap:sitemap)"/> sitemaps.</p>
<table>
<tr><th>Sitemap</th><th>Last modified</th></tr>
<xsl:for-each select="sitemap:sitemapindex/sitemap:sitemap">
<tr><td><a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc"/></a></td><td class="n"><xsl:value-of select="substring(sitemap:lastmod,0,11)"/></td></tr>
</xsl:for-each>
</table>
</xsl:when>
<xsl:otherwise>
<p><xsl:value-of select="count(sitemap:urlset/sitemap:url)"/> urls.</p>
<table>
<tr><th>URL</th><th>Images</th><th>Last modified</th></tr>
<xsl:for-each select="sitemap:urlset/sitemap:url">
<tr><td><a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc"/></a></td><td class="n"><xsl:value-of select="count(image:image)"/></td><td class="n"><xsl:value-of select="substring(sitemap:lastmod,0,11)"/></td></tr>
</xsl:for-each>
</table>
</xsl:otherwise>
</xsl:choose>
</body>
</html>
</xsl:template>
</xsl:stylesheet>
XSL;
}
