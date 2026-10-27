<?php
/**
 * Discover Asian World: the SEO engine that replaces Rank Math.
 *
 * WHY THIS EXISTS
 * ---------------
 * Rank Math did five jobs for this site: the head tags (title, description,
 * robots, canonical, Open Graph, Twitter), the sitemap, the redirects, the 404
 * log and the robots.txt. Everything that made those tags good was already
 * ours: the generated titles and descriptions (seo.php), the record schema
 * (schema.php), the browse and calendar rules (browse.php, calendar.php) and
 * the TMDB share picture (tmdb-remote.php). Rank Math was a frame around them
 * that also loaded a few hundred kilobytes of code we never used. The owner
 * decided on 4 October 2026 to take the frame away and keep the content.
 *
 * NOTHING MOVES
 * -------------
 * The engine reads the same post meta Rank Math wrote (rank_math_title,
 * rank_math_description, rank_math_robots, rank_math_canonical_url,
 * rank_math_facebook_*), the same term meta, and Rank Math's own saved
 * settings for the title templates, the separator and the robots defaults.
 * No row is copied, renamed or deleted. Every hand-written title on the site
 * is read from exactly where it already is, and the sitemap answers at the
 * same addresses Search Console already knows.
 *
 * THREE STATES
 * ------------
 * 1. Rank Math active (today): the engine prints nothing. Our own filters are
 *    bridged into Rank Math's, so every rule in seo.php, browse.php and
 *    calendar.php keeps working exactly as before.
 * 2. Rank Math active and a signed shadow request: the engine computes the
 *    head it would print and hides it in one HTML comment, so DAW › SEO ›
 *    Geçiş kontrolü can put the two side by side, page by page, before
 *    anything is switched off.
 * 3. Rank Math inactive: the engine prints the head, serves the sitemap,
 *    robots.txt and IndexNow key, and keeps the category base stripped.
 *
 * Switching back is deactivating nothing and activating Rank Math again: the
 * engine sees it and goes quiet on the next request.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/* ================================================================ state === */

/** Rank Math is loaded on this request. */
function daw_eng_rm_active() {
	return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false );
}

/** The engine owns the head, the sitemap and robots.txt on this request. */
function daw_eng_live() {
	return ! daw_eng_rm_active() && 'off' !== get_option( 'daw_seo_engine', 'auto' );
}

/** The shared secret that marks a request as a shadow comparison. */
function daw_eng_shadow_token() {
	$token = (string) get_option( 'daw_seo_shadow_token', '' );

	if ( strlen( $token ) < 32 ) {
		$token = wp_generate_password( 40, false, false );
		update_option( 'daw_seo_shadow_token', $token, false );
	}

	return $token;
}

/** This request was sent by the comparison tool and carries a valid token. */
function daw_eng_is_shadow() {
	static $is = null;

	if ( null !== $is ) {
		return $is;
	}

	$given = '';
	if ( isset( $_SERVER['HTTP_X_DAW_SEO_SHADOW'] ) ) {
		$given = (string) wp_unslash( $_SERVER['HTTP_X_DAW_SEO_SHADOW'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	} elseif ( isset( $_GET['daw_seo_shadow'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$given = (string) wp_unslash( $_GET['daw_seo_shadow'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
	}

	if ( '' === $given ) {
		$is = false;
		return $is;
	}

	$stored = (string) get_option( 'daw_seo_shadow_token', '' );
	$is     = '' !== $stored && hash_equals( $stored, $given );

	return $is;
}

/* ============================================================= settings === */

/**
 * A Rank Math switch. It saves them as the strings "on" and "off", and "off"
 * is a non-empty string, so a plain truthiness test reads every switch as on.
 */
function daw_eng_on( $value ) {
	return true === $value || 'on' === $value || '1' === (string) $value || 1 === $value;
}

/** One of Rank Math's saved option groups, read once per request. */
function daw_eng_rm_opt( $group ) {
	static $cache = array();

	if ( ! isset( $cache[ $group ] ) ) {
		$value            = get_option( 'rank-math-options-' . $group );
		$cache[ $group ] = is_array( $value ) ? $value : array();
	}

	return $cache[ $group ];
}

/**
 * robots.txt as the engine serves it.
 *
 * Rank Math's saved file blocked /wp-content/uploads/ and allowed it again
 * four lines later, blocked the theme folder (so any crawler without its own
 * group could not load the stylesheet or the fonts it renders the page with),
 * and allowed a folder called "some-plugin" that has never existed. The named
 * groups for Google, Bing, Apple and DuckDuckGo said "Allow: /", which only
 * took those crawlers out of the rules above them. This is the same intent
 * with the contradictions taken out. It can be edited in DAW › SEO.
 */
function daw_eng_default_robots_txt() {
	return implode(
		"\n",
		array(
			'User-agent: *',
			'Disallow: /wp-admin/',
			'Allow: /wp-admin/admin-ajax.php',
			'Disallow: /wp-login.php',
			'Disallow: /xmlrpc.php',
			'Disallow: /readme.html',
			'Disallow: /license.txt',
			'Disallow: /cgi-bin/',
			'Disallow: /trackback/',
			'Disallow: /comments/',
			'',
			'User-agent: Yandex',
			'Disallow: /wp-admin/',
			'Allow: /wp-admin/admin-ajax.php',
			'Disallow: /wp-login.php',
			'Disallow: /xmlrpc.php',
			'Crawl-delay: 1',
		)
	);
}

/**
 * Settings, built from Rank Math's saved options the first time and kept as
 * our own from then on (option daw_seo). Until the first save, the values are
 * read live from Rank Math's options, which stay in the database after the
 * plugin is deactivated.
 */
function daw_eng_settings() {
	static $settings = null;

	if ( null !== $settings ) {
		return $settings;
	}

	$saved = get_option( 'daw_seo' );

	// Once saved, our copy is complete; Rank Math's options are not read again.
	$settings = is_array( $saved ) && $saved ? array_merge( daw_eng_settings_from_rm_defaults(), $saved ) : daw_eng_settings_from_rm();

	return $settings;
}

/** Keys a saved copy might lack after an upgrade, without reading Rank Math. */
function daw_eng_settings_from_rm_defaults() {
	return array(
		'sep'                   => '-',
		'sitename'              => (string) get_bloginfo( 'name' ),
		'og_image_id'           => 0,
		'og_image'              => '',
		'facebook'              => '',
		'twitter'               => '',
		'twitter_card'          => 'summary_large_image',
		'robots_txt'            => daw_eng_default_robots_txt(),
		'sitemap_per_page'      => 200,
		'sitemap_images'        => true,
		'sitemap_types'         => array( 'post', 'page' ),
		'sitemap_exclude'       => '',
		'indexnow_key'          => '',
		'news_name'             => '',
		'strip_category_base'   => true,
		'attachment_redirect'   => true,
		'disable_date_archives' => true,
		'new_window_external'   => true,
		'nofollow_external'     => false,
		'img_alt'               => true,
		'auto_404'              => true,
	);
}

/** What Rank Math had, in the engine's shape. */
function daw_eng_settings_from_rm() {
	$t  = daw_eng_rm_opt( 'titles' );
	$g  = daw_eng_rm_opt( 'general' );
	$sm = daw_eng_rm_opt( 'sitemap' );
	$ii = daw_eng_rm_opt( 'instant-indexing' );

	$handle = trim( (string) ( $t['twitter_author_names'] ?? '' ) );

	return array(
		'sep'                   => '' !== (string) ( $t['title_separator'] ?? '' ) ? (string) $t['title_separator'] : '-',
		'sitename'              => '' !== trim( (string) ( $t['website_name'] ?? '' ) ) ? (string) $t['website_name'] : (string) get_bloginfo( 'name' ),
		'og_image_id'           => (int) ( $t['open_graph_image_id'] ?? 0 ),
		'og_image'              => (string) ( $t['open_graph_image'] ?? '' ),
		'facebook'              => (string) ( $t['social_url_facebook'] ?? '' ),
		'twitter'               => '' !== $handle ? '@' . ltrim( $handle, '@' ) : '',
		'twitter_card'          => (string) ( $t['twitter_card_type'] ?? 'summary_large_image' ),
		'google_verify'         => (string) ( $g['google_verify'] ?? '' ),
		'bing_verify'           => (string) ( $g['bing_verify'] ?? '' ),
		'yandex_verify'         => (string) ( $g['yandex_verify'] ?? '' ),
		'pinterest_verify'      => (string) ( $g['pinterest_verify'] ?? '' ),
		'baidu_verify'          => (string) ( $g['baidu_verify'] ?? '' ),
		'strip_category_base'   => daw_eng_on( $g['strip_category_base'] ?? 'off' ),
		'attachment_redirect'   => daw_eng_on( $g['attachment_redirect_urls'] ?? 'off' ),
		'attachment_default'    => (string) ( $g['attachment_redirect_default'] ?? '' ),
		'disable_date_archives' => daw_eng_on( $t['disable_date_archives'] ?? 'off' ),
		'new_window_external'   => daw_eng_on( $g['new_window_external_links'] ?? 'off' ),
		'nofollow_external'     => daw_eng_on( $g['nofollow_external_links'] ?? 'off' ),
		'img_alt'               => daw_eng_on( $g['add_img_alt'] ?? 'off' ),
		'auto_404'              => true,
		'robots_txt'            => daw_eng_default_robots_txt(),
		'sitemap_per_page'      => max( 50, min( 1000, (int) ( $sm['items_per_page'] ?? 200 ) ) ),
		'sitemap_images'        => ! isset( $sm['include_images'] ) || daw_eng_on( $sm['include_images'] ),
		'sitemap_types'         => daw_eng_sitemap_types_from_rm( $sm ),
		'sitemap_exclude'       => (string) ( $sm['exclude_posts'] ?? '' ),
		'indexnow_key'          => (string) ( $ii['indexnow_api_key'] ?? '' ),
		'news_name'             => (string) ( $sm['news_sitemap_publication_name'] ?? '' ),
	);
}

/** Post types Rank Math put in the sitemap, limited to ones that still exist. */
function daw_eng_sitemap_types_from_rm( $sm ) {
	$types = array();

	foreach ( array( 'post', 'page' ) as $type ) {
		if ( ! isset( $sm[ 'pt_' . $type . '_sitemap' ] ) || daw_eng_on( $sm[ 'pt_' . $type . '_sitemap' ] ) ) {
			$types[] = $type;
		}
	}

	return $types;
}

function daw_eng_set( $key, $default = null ) {
	$s = daw_eng_settings();

	return array_key_exists( $key, $s ) ? $s[ $key ] : $default;
}

/** Rank Math's title and robots templates. They stay in its option. */
function daw_eng_t( $key, $default = '' ) {
	$t = daw_eng_rm_opt( 'titles' );

	return array_key_exists( $key, $t ) ? $t[ $key ] : $default;
}

/** Forget the per-request copies after a save on the settings screen. */
function daw_eng_settings_save( array $values ) {
	$saved = get_option( 'daw_seo' );
	$saved = is_array( $saved ) ? $saved : daw_eng_settings_from_rm();

	// Autoloaded: it is read on every page once the engine is live.
	update_option( 'daw_seo', array_merge( $saved, $values ), true );
}

/**
 * The options read on every request exist from the first admin visit, so a
 * missing one never costs a database query per page view.
 */
add_action( 'admin_init', 'daw_eng_seed_options', 1 );
function daw_eng_seed_options() {
	if ( false === get_option( 'daw_seo_engine', false ) ) {
		add_option( 'daw_seo_engine', 'auto', '', true );
	}
	if ( false === get_option( 'daw_seo', false ) ) {
		add_option( 'daw_seo', daw_eng_settings_from_rm(), '', true );
	}
}

/* ============================================================ variables === */

/**
 * Rank Math's %variables%, the ones this site's templates and saved titles
 * actually use, resolved the same way.
 */
function daw_eng_vars( $text, $ctx ) {
	$text = (string) $text;

	if ( false === strpos( $text, '%' ) ) {
		return daw_eng_clean_title( $text );
	}

	$post = isset( $ctx['post'] ) ? $ctx['post'] : null;
	$term = isset( $ctx['term'] ) ? $ctx['term'] : null;

	$out = preg_replace_callback(
		'/%([a-z_]+)(?:\(([^)]*)\))?%/i',
		static function ( $m ) use ( $post, $term, $ctx ) {
			$name = strtolower( $m[1] );
			$arg  = isset( $m[2] ) ? $m[2] : '';

			switch ( $name ) {
				case 'sep':
					return daw_eng_set( 'sep', '-' );
				case 'sitename':
					return daw_eng_set( 'sitename' );
				case 'sitedesc':
					return (string) get_bloginfo( 'description' );
				case 'title':
				case 'seo_title':
					if ( $post ) {
						return daw_eng_plain( get_the_title( $post ) );
					}
					return $term ? daw_eng_plain( $term->name ) : '';
				case 'parent_title':
					return ( $post && $post->post_parent ) ? daw_eng_plain( get_the_title( $post->post_parent ) ) : '';
				case 'term':
				case 'term_title':
					return $term ? daw_eng_plain( $term->name ) : '';
				case 'term_description':
					return $term ? daw_eng_trim_text( (string) $term->description, 160 ) : '';
				case 'excerpt':
				case 'seo_description':
					return $post ? daw_eng_excerpt( $post ) : '';
				case 'excerpt_only':
					return ( $post && '' !== trim( $post->post_excerpt ) ) ? daw_eng_trim_text( $post->post_excerpt, 160 ) : '';
				case 'category':
				case 'primary_category':
					if ( $post ) {
						$cats = get_the_category( $post->ID );
						return $cats ? daw_eng_plain( $cats[0]->name ) : '';
					}
					return '';
				case 'categories':
					if ( $post ) {
						return implode( ', ', wp_list_pluck( (array) get_the_category( $post->ID ), 'name' ) );
					}
					return '';
				case 'focuskw':
					return $post ? (string) strtok( (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true ), ',' ) : '';
				case 'date':
				case 'publish_date':
					return $post ? get_the_date( '', $post ) : '';
				case 'modified':
					return $post ? get_the_modified_date( '', $post ) : '';
				case 'currentyear':
					return wp_date( 'Y' );
				case 'currentdate':
					return wp_date( get_option( 'date_format' ) );
				case 'currentmonth':
					return wp_date( 'F' );
				case 'name':
				case 'post_author':
					if ( isset( $ctx['user'] ) && $ctx['user'] ) {
						return (string) $ctx['user']->display_name;
					}
					return $post ? (string) get_the_author_meta( 'display_name', $post->post_author ) : '';
				case 'search_query':
					return (string) get_search_query( false );
				case 'page':
					return daw_eng_page_var();
				case 'pt_single':
				case 'pt_plural':
					$pto = $post ? get_post_type_object( $post->post_type ) : null;
					if ( ! $pto ) {
						return '';
					}
					return 'pt_single' === $name ? (string) $pto->labels->singular_name : (string) $pto->labels->name;
				case 'customfield':
					return $post ? wp_strip_all_tags( (string) get_post_meta( $post->ID, $arg, true ) ) : '';
				case 'count':
					return '';
			}

			// Unknown variable: leave nothing behind rather than print "%foo%".
			return '';
		},
		$text
	);

	return daw_eng_clean_title( (string) $out );
}

/** "Page 2 of 5" after the separator, only past the first page. */
function daw_eng_page_var() {
	global $wp_query;

	$paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
	$max   = isset( $wp_query->max_num_pages ) ? (int) $wp_query->max_num_pages : 0;

	if ( $paged < 2 ) {
		return '';
	}

	if ( $max < $paged ) {
		$max = $paged;
	}

	/* translators: 1: page number, 2: number of pages */
	return daw_eng_set( 'sep', '-' ) . ' ' . sprintf( __( 'Page %1$d of %2$d', 'daw' ), $paged, $max );
}

/** Whitespace collapsed and no separator left dangling at either end. */
function daw_eng_clean_title( $text ) {
	$sep  = preg_quote( (string) daw_eng_set( 'sep', '-' ), '/' );
	$text = preg_replace( '/\s+/u', ' ', (string) $text );
	$text = preg_replace( '/(\s*' . $sep . '\s*){2,}/u', ' ' . daw_eng_set( 'sep', '-' ) . ' ', $text );
	$text = preg_replace( '/^\s*' . $sep . '\s*|\s*' . $sep . '\s*$/u', '', $text );

	return trim( (string) $text );
}

/** Title text as plain characters: no tags, no entities. */
function daw_eng_plain( $text ) {
	return trim( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
}

/** Plain text cut at a word, never in the middle of one. */
function daw_eng_trim_text( $text, $max ) {
	$text = daw_eng_plain( strip_shortcodes( (string) $text ) );
	$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}

	$cut   = mb_substr( $text, 0, $max );
	$space = mb_strrpos( $cut, ' ' );

	return rtrim( false !== $space && $space > $max * 0.6 ? mb_substr( $cut, 0, $space ) : $cut, " ,.;:-" );
}

/** The excerpt, or the opening of the text when nobody wrote one. */
function daw_eng_excerpt( $post ) {
	if ( '' !== trim( (string) $post->post_excerpt ) ) {
		return daw_eng_trim_text( $post->post_excerpt, 160 );
	}

	return daw_eng_trim_text( (string) $post->post_content, 160 );
}

/* ============================================================== context === */

/**
 * What kind of page this request is, in the terms the templates are keyed by.
 *
 * @return array{kind: string, post?: WP_Post, term?: WP_Term, user?: WP_User, front?: bool}
 */
function daw_eng_context() {
	static $ctx = null;

	if ( null !== $ctx ) {
		return $ctx;
	}

	$obj = get_queried_object();

	if ( is_front_page() && is_page() && $obj instanceof WP_Post ) {
		$ctx = array( 'kind' => 'singular', 'post' => $obj, 'front' => true );
	} elseif ( is_home() || ( is_front_page() && ! is_page() ) ) {
		$ctx = array( 'kind' => 'home', 'front' => is_front_page() );
	} elseif ( is_singular() && $obj instanceof WP_Post ) {
		$ctx = array( 'kind' => 'singular', 'post' => $obj );
	} elseif ( ( is_category() || is_tag() || is_tax() ) && $obj instanceof WP_Term ) {
		$ctx = array( 'kind' => 'term', 'term' => $obj );
	} elseif ( is_author() && $obj instanceof WP_User ) {
		$ctx = array( 'kind' => 'author', 'user' => $obj );
	} elseif ( is_search() ) {
		$ctx = array( 'kind' => 'search' );
	} elseif ( is_post_type_archive() ) {
		$ctx = array( 'kind' => 'pta', 'pt' => (string) get_query_var( 'post_type' ) );
	} elseif ( is_date() ) {
		$ctx = array( 'kind' => 'date' );
	} elseif ( is_404() ) {
		$ctx = array( 'kind' => '404' );
	} else {
		$ctx = array( 'kind' => 'other' );
	}

	return $ctx;
}

/* ================================================================ title === */

/** The title before our own filters, the way Rank Math built it. */
function daw_eng_title_raw( $ctx ) {
	switch ( $ctx['kind'] ) {
		case 'singular':
			$post = $ctx['post'];
			$meta = trim( (string) get_post_meta( $post->ID, 'rank_math_title', true ) );
			$tpl  = '' !== $meta ? $meta : (string) daw_eng_t( 'pt_' . $post->post_type . '_title', '%title% %sep% %sitename%' );
			return daw_eng_vars( $tpl, $ctx );

		case 'home':
			return daw_eng_vars( (string) daw_eng_t( 'homepage_title', '%sitename% %page% %sep% %sitedesc%' ), $ctx );

		case 'term':
			$term = $ctx['term'];
			$meta = trim( (string) get_term_meta( $term->term_id, 'rank_math_title', true ) );
			$tpl  = '' !== $meta ? $meta : (string) daw_eng_t( 'tax_' . $term->taxonomy . '_title', '%term% %sep% %sitename%' );
			return daw_eng_vars( $tpl, $ctx );

		case 'author':
			return daw_eng_vars( (string) daw_eng_t( 'author_archive_title', '%name% %sep% %sitename% %page%' ), $ctx );

		case 'search':
			return daw_eng_vars( (string) daw_eng_t( 'search_title', '%search_query% %page% %sep% %sitename%' ), $ctx );

		case 'pta':
			$pto = get_post_type_object( $ctx['pt'] );
			$tpl = (string) daw_eng_t( 'pt_' . $ctx['pt'] . '_archive_title', '%pt_plural% %page% %sep% %sitename%' );
			return daw_eng_vars( str_replace( '%pt_plural%', $pto ? $pto->labels->name : '', $tpl ), $ctx );

		case 'date':
			return daw_eng_vars( (string) daw_eng_t( 'date_archive_title', '%date% %page% %sep% %sitename%' ), $ctx );

		case '404':
			return daw_eng_vars( (string) daw_eng_t( '404_title', 'Page Not Found %sep% %sitename%' ), $ctx );
	}

	return daw_eng_vars( '%sitename%', $ctx );
}

/** The finished title tag text, after every rule this site keeps. */
function daw_eng_title() {
	static $title = null;

	if ( null === $title ) {
		$ctx   = daw_eng_context();
		$title = daw_eng_plain( (string) apply_filters( 'daw_seo_title', daw_eng_title_raw( $ctx ) ) );
		$title = preg_replace( '/\s+/u', ' ', $title );
	}

	return $title;
}

/* ========================================================== description === */

function daw_eng_description_raw( $ctx ) {
	switch ( $ctx['kind'] ) {
		case 'singular':
			$post = $ctx['post'];
			$meta = trim( (string) get_post_meta( $post->ID, 'rank_math_description', true ) );
			$tpl  = '' !== $meta ? $meta : (string) daw_eng_t( 'pt_' . $post->post_type . '_description', '%excerpt%' );
			return daw_eng_vars( $tpl, $ctx );

		case 'home':
			return daw_eng_vars( (string) daw_eng_t( 'homepage_description', '' ), $ctx );

		case 'term':
			$term = $ctx['term'];
			$meta = trim( (string) get_term_meta( $term->term_id, 'rank_math_description', true ) );
			$tpl  = '' !== $meta ? $meta : (string) daw_eng_t( 'tax_' . $term->taxonomy . '_description', '%term_description%' );
			return daw_eng_vars( $tpl, $ctx );

		case 'author':
			return daw_eng_vars( (string) daw_eng_t( 'author_archive_description', '' ), $ctx );

		case 'pta':
			return daw_eng_vars( (string) daw_eng_t( 'pt_' . $ctx['pt'] . '_archive_description', '' ), $ctx );
	}

	return '';
}

function daw_eng_description() {
	static $desc = null;

	if ( null === $desc ) {
		$ctx  = daw_eng_context();
		$desc = daw_eng_plain( (string) apply_filters( 'daw_seo_description', daw_eng_description_raw( $ctx ) ) );
		$desc = trim( preg_replace( '/\s+/u', ' ', $desc ) );
	}

	return $desc;
}

/* =============================================================== robots === */

/** Rank Math's robots meta format: "noindex" or "index" etc. as an array. */
function daw_eng_tokens_to_robots( $tokens ) {
	$tokens = array_map( 'strtolower', array_map( 'trim', (array) $tokens ) );
	$robots = array(
		'index'  => in_array( 'noindex', $tokens, true ) ? 'noindex' : 'index',
		'follow' => in_array( 'nofollow', $tokens, true ) ? 'nofollow' : 'follow',
	);

	foreach ( array( 'noarchive', 'nosnippet', 'noimageindex' ) as $extra ) {
		if ( in_array( $extra, $tokens, true ) ) {
			$robots[ $extra ] = $extra;
		}
	}

	return $robots;
}

/** The robots directives for this request, before our filters. */
function daw_eng_robots_raw( $ctx ) {
	$global = (array) daw_eng_t( 'robots_global', array( 'index' ) );
	$tokens = $global;
	$adv    = (array) daw_eng_t( 'advanced_robots_global', array() );

	switch ( $ctx['kind'] ) {
		case 'singular':
			$post = $ctx['post'];
			$meta = get_post_meta( $post->ID, 'rank_math_robots', true );
			if ( is_array( $meta ) && $meta ) {
				$tokens = $meta;
			} elseif ( daw_eng_on( daw_eng_t( 'pt_' . $post->post_type . '_custom_robots', 'off' ) ) ) {
				$tokens = (array) daw_eng_t( 'pt_' . $post->post_type . '_robots', $global );
				$adv    = (array) daw_eng_t( 'pt_' . $post->post_type . '_advanced_robots', $adv );
			}
			if ( post_password_required( $post ) && daw_eng_on( daw_eng_t( 'noindex_password_protected', 'off' ) ) ) {
				$tokens[] = 'noindex';
			}
			if ( 'attachment' === $post->post_type ) {
				$tokens[] = 'noindex';
			}
			break;

		case 'term':
			$term = $ctx['term'];
			$meta = get_term_meta( $term->term_id, 'rank_math_robots', true );
			if ( is_array( $meta ) && $meta ) {
				$tokens = $meta;
			} elseif ( daw_eng_on( daw_eng_t( 'tax_' . $term->taxonomy . '_custom_robots', 'off' ) ) ) {
				$tokens = (array) daw_eng_t( 'tax_' . $term->taxonomy . '_robots', $global );
			}
			if ( 0 === (int) $term->count && daw_eng_on( daw_eng_t( 'noindex_empty_taxonomies', 'off' ) ) ) {
				$tokens[] = 'noindex';
			}
			break;

		case 'author':
			if ( daw_eng_on( daw_eng_t( 'author_custom_robots', 'off' ) ) ) {
				$tokens = (array) daw_eng_t( 'author_robots', $global );
			}
			break;

		case 'date':
			$tokens = (array) daw_eng_t( 'date_archive_robots', array( 'noindex' ) );
			break;

		case 'search':
			if ( daw_eng_on( daw_eng_t( 'noindex_search', 'on' ) ) ) {
				$tokens[] = 'noindex';
			}
			break;

		case '404':
			$tokens[] = 'noindex';
			break;
	}

	if ( 'singular' !== $ctx['kind'] && is_paged() && ( daw_eng_on( daw_eng_t( 'noindex_paginated_pages', 'off' ) ) || daw_eng_on( daw_eng_t( 'noindex_archive_subpages', 'off' ) ) ) ) {
		$tokens[] = 'noindex';
	}

	$robots = daw_eng_tokens_to_robots( $tokens );

	foreach ( $adv as $key => $value ) {
		if ( '' !== (string) $value && false !== $value ) {
			$robots[ $key ] = $key . ':' . $value;
		}
	}

	return $robots;
}

function daw_eng_robots() {
	static $robots = null;

	if ( null === $robots ) {
		$robots = (array) apply_filters( 'daw_seo_robots', daw_eng_robots_raw( daw_eng_context() ) );

		if ( '0' === (string) get_option( 'blog_public' ) ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'nofollow';
		}

		// A page kept out of the index carries no snippet limits, as Rank
		// Math printed it: "noindex, follow" and nothing more.
		if ( isset( $robots['index'] ) && 'noindex' === $robots['index'] ) {
			foreach ( array_keys( $robots ) as $key ) {
				if ( 0 === strpos( (string) $key, 'max-' ) ) {
					unset( $robots[ $key ] );
				}
			}
		}
	}

	return $robots;
}

function daw_eng_is_noindex() {
	$robots = daw_eng_robots();

	return isset( $robots['index'] ) && 'noindex' === $robots['index'];
}

/** "index, follow, max-snippet:-1, ..." the way it is printed and compared. */
function daw_eng_robots_string() {
	return implode( ', ', array_values( array_filter( array_map( 'strval', daw_eng_robots() ) ) ) );
}

/* ============================================================ canonical === */

function daw_eng_paged_url( $url ) {
	$paged = (int) get_query_var( 'paged' );

	if ( $paged < 2 || '' === $url ) {
		return $url;
	}

	global $wp_rewrite;

	return user_trailingslashit( trailingslashit( $url ) . $wp_rewrite->pagination_base . '/' . $paged );
}

function daw_eng_canonical_raw( $ctx ) {
	switch ( $ctx['kind'] ) {
		case 'singular':
			$post = $ctx['post'];
			$meta = trim( (string) get_post_meta( $post->ID, 'rank_math_canonical_url', true ) );
			if ( '' !== $meta ) {
				return $meta;
			}
			if ( ! empty( $ctx['front'] ) ) {
				return home_url( '/' );
			}
			$url = wp_get_canonical_url( $post );
			return $url ? (string) $url : (string) get_permalink( $post );

		case 'home':
			return daw_eng_paged_url( home_url( '/' ) );

		case 'term':
			$term = $ctx['term'];
			$meta = trim( (string) get_term_meta( $term->term_id, 'rank_math_canonical_url', true ) );
			if ( '' !== $meta ) {
				return $meta;
			}
			$link = get_term_link( $term );
			return is_wp_error( $link ) ? '' : daw_eng_paged_url( (string) $link );

		case 'author':
			return daw_eng_paged_url( (string) get_author_posts_url( $ctx['user']->ID ) );

		case 'pta':
			return daw_eng_paged_url( (string) get_post_type_archive_link( $ctx['pt'] ) );
	}

	return '';
}

/** The page's own address after every rule, whether or not it is printed. */
function daw_eng_canonical_full() {
	static $url = null;

	if ( null === $url ) {
		$url = (string) apply_filters( 'daw_seo_canonical', daw_eng_canonical_raw( daw_eng_context() ) );
		$url = remove_query_arg( 'daw_seo_shadow', $url );
	}

	return $url;
}

/**
 * The canonical tag: none on a noindex page, as Rank Math did, unless a
 * module asks for it (a filtered list points at the list it narrows).
 */
function daw_eng_canonical() {
	if ( daw_eng_is_noindex() && ! apply_filters( 'daw_seo_canonical_on_noindex', false ) ) {
		return '';
	}
	return daw_eng_canonical_full();
}

/* =========================================================== share image === */

/** An attachment's picture with its size, or null. */
function daw_eng_attachment_image( $id, $size = 'best' ) {
	$id = (int) $id;

	if ( ! $id ) {
		return null;
	}

	if ( 'best' === $size ) {
		// Rank Math's choice, measured on the live site: the original if it
		// is between 200 and 2000 pixels on each side, otherwise the first
		// of "large" and "medium_large" that is. A 2560px "scaled" upload
		// therefore goes out as its 1024px copy.
		$src = false;
		foreach ( array( 'full', 'large', 'medium_large' ) as $candidate ) {
			$try = wp_get_attachment_image_src( $id, $candidate );
			if ( $try && $try[1] >= 200 && $try[2] >= 200 && $try[1] <= 2000 && $try[2] <= 2000 ) {
				$src = $try;
				break;
			}
		}
		if ( ! $src ) {
			$src = wp_get_attachment_image_src( $id, 'full' );
		}
	} else {
		$src = wp_get_attachment_image_src( $id, $size );
	}

	if ( ! $src ) {
		return null;
	}

	return array(
		'url'  => (string) $src[0],
		'w'    => (int) $src[1],
		'h'    => (int) $src[2],
		'alt'  => daw_eng_plain( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
		'type' => (string) get_post_mime_type( $id ),
	);
}

/** The first picture in a piece of html, by url. */
function daw_eng_first_content_image( $html ) {
	if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $html, $m ) ) {
		return array( 'url' => $m[1], 'w' => 0, 'h' => 0, 'alt' => '', 'type' => '' );
	}

	return null;
}

/** The share picture, in the order Rank Math looked for one. */
function daw_eng_og_image_raw( $ctx ) {
	$image = null;

	if ( 'singular' === $ctx['kind'] ) {
		$post = $ctx['post'];
		$id   = (int) get_post_meta( $post->ID, 'rank_math_facebook_image_id', true );
		$url  = trim( (string) get_post_meta( $post->ID, 'rank_math_facebook_image', true ) );

		$image = daw_eng_attachment_image( $id );

		if ( ! $image && '' !== $url ) {
			$image = array( 'url' => $url, 'w' => 0, 'h' => 0, 'alt' => '', 'type' => '' );
		}
		if ( ! $image && has_post_thumbnail( $post ) ) {
			$image = daw_eng_attachment_image( get_post_thumbnail_id( $post ) );
		}
		if ( ! $image ) {
			$image = daw_eng_first_content_image( $post->post_content );
		}
	} elseif ( 'term' === $ctx['kind'] ) {
		$term  = $ctx['term'];
		$image = daw_eng_attachment_image( (int) get_term_meta( $term->term_id, 'rank_math_facebook_image_id', true ) );
		$url   = trim( (string) get_term_meta( $term->term_id, 'rank_math_facebook_image', true ) );

		if ( ! $image && '' !== $url ) {
			$image = array( 'url' => $url, 'w' => 0, 'h' => 0, 'alt' => '', 'type' => '' );
		}
	}

	if ( ! $image ) {
		$image = daw_eng_attachment_image( (int) daw_eng_set( 'og_image_id', 0 ) );
	}
	if ( ! $image && '' !== (string) daw_eng_set( 'og_image', '' ) ) {
		$image = array( 'url' => (string) daw_eng_set( 'og_image' ), 'w' => 0, 'h' => 0, 'alt' => '', 'type' => '' );
	}

	return $image;
}

function daw_eng_og_image() {
	static $image = false;

	if ( false !== $image ) {
		return $image;
	}

	$image = daw_eng_og_image_raw( daw_eng_context() );
	$url   = $image ? $image['url'] : '';
	$new   = (string) apply_filters( 'daw_seo_og_image', $url );

	// An empty answer keeps the picture found above, which is what Rank Math
	// did with the same filter (measured 4 Oct 2026: group records kept their
	// own or the default picture). Only a different url replaces it.
	if ( '' !== $new && $new !== $url ) {
		$image = array( 'url' => $new, 'w' => 0, 'h' => 0, 'alt' => '', 'type' => '' );
	}

	if ( $image && '' === $image['type'] ) {
		$ext           = strtolower( (string) pathinfo( (string) wp_parse_url( $image['url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$types         = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif' );
		$image['type'] = isset( $types[ $ext ] ) ? $types[ $ext ] : '';
	}

	return $image;
}

/* ============================================================ head data === */

/**
 * Everything the engine prints in the head, as one array. The live output and
 * the shadow comparison read the same array, so what was compared is what
 * gets printed.
 */
function daw_eng_head_data() {
	static $data = null;

	if ( null !== $data ) {
		return $data;
	}

	$ctx   = daw_eng_context();
	$title = daw_eng_title();
	$desc  = daw_eng_description();
	$canon = daw_eng_canonical();
	$post  = isset( $ctx['post'] ) ? $ctx['post'] : null;
	$term  = isset( $ctx['term'] ) ? $ctx['term'] : null;

	$og_title = $title;
	$og_desc  = $desc;
	$tw_title = '';
	$tw_desc  = '';
	$card     = (string) daw_eng_set( 'twitter_card', 'summary_large_image' );

	$meta_src = $post ? array( 'get_post_meta', $post->ID ) : ( $term ? array( 'get_term_meta', $term->term_id ) : null );

	if ( $meta_src ) {
		$fb_t = trim( (string) call_user_func( $meta_src[0], $meta_src[1], 'rank_math_facebook_title', true ) );
		$fb_d = trim( (string) call_user_func( $meta_src[0], $meta_src[1], 'rank_math_facebook_description', true ) );

		if ( '' !== $fb_t ) {
			$og_title = daw_eng_vars( $fb_t, $ctx );
		}
		if ( '' !== $fb_d ) {
			$og_desc = daw_eng_vars( $fb_d, $ctx );
		}

		if ( 'off' === (string) call_user_func( $meta_src[0], $meta_src[1], 'rank_math_twitter_use_facebook', true ) ) {
			$tw_title = daw_eng_vars( trim( (string) call_user_func( $meta_src[0], $meta_src[1], 'rank_math_twitter_title', true ) ), $ctx );
			$tw_desc  = daw_eng_vars( trim( (string) call_user_func( $meta_src[0], $meta_src[1], 'rank_math_twitter_description', true ) ), $ctx );
			$own_card = trim( (string) call_user_func( $meta_src[0], $meta_src[1], 'rank_math_twitter_card_type', true ) );
			if ( '' !== $own_card ) {
				$card = $own_card;
			}
		}
	}

	$image   = daw_eng_og_image();
	$is_home = ! empty( $ctx['front'] ) || 'home' === $ctx['kind'];
	$url     = daw_eng_canonical_full();
	$url     = '' !== $url ? $url : ( 'singular' === $ctx['kind'] ? (string) get_permalink( $post ) : '' );
	if ( 'search' === $ctx['kind'] ) {
		$url = (string) get_search_link();
	}

	$data = array(
		'title'       => $title,
		'description' => $desc,
		'robots'      => daw_eng_robots_string(),
		'canonical'   => $canon,
		'og'          => array_filter(
			array(
				'og:locale'      => get_locale(),
				'og:type'        => $is_home ? 'website' : 'article',
				'og:title'       => $og_title,
				'og:description' => $og_desc,
				'og:url'         => $url,
				'og:site_name'   => (string) daw_eng_set( 'sitename' ),
			),
			'strlen'
		),
		'article'     => array(),
		'image'       => $image,
		'twitter'     => array_filter(
			array(
				'twitter:card'        => $card,
				'twitter:title'       => '' !== $tw_title ? $tw_title : $og_title,
				'twitter:description' => '' !== $tw_desc ? $tw_desc : $og_desc,
				'twitter:site'        => (string) daw_eng_set( 'twitter', '' ),
				'twitter:creator'     => (string) daw_eng_set( 'twitter', '' ),
			),
			'strlen'
		),
	);

	if ( $post && ! $is_home && 'post' === $post->post_type ) {
		$cats = get_the_category( $post->ID );

		$data['article'] = array_filter(
			array(
				'article:publisher'      => (string) daw_eng_set( 'facebook', '' ),
				'article:section'        => $cats ? daw_eng_plain( $cats[0]->name ) : '',
				'og:updated_time'        => get_post_modified_time( 'c', true, $post ),
				'article:published_time' => get_post_time( 'c', true, $post ),
				'article:modified_time'  => get_post_modified_time( 'c', true, $post ),
			),
			'strlen'
		);
	} elseif ( $post && ! $is_home ) {
		$data['article'] = array_filter(
			array(
				'article:publisher'      => (string) daw_eng_set( 'facebook', '' ),
				'og:updated_time'        => get_post_modified_time( 'c', true, $post ),
				'article:published_time' => get_post_time( 'c', true, $post ),
				'article:modified_time'  => get_post_modified_time( 'c', true, $post ),
			),
			'strlen'
		);
	}

	return $data;
}

/* =============================================================== output === */

add_filter( 'pre_get_document_title', 'daw_eng_document_title', 99 );
function daw_eng_document_title( $title ) {
	if ( ! daw_eng_live() || is_admin() || is_feed() ) {
		return $title;
	}

	$ours = daw_eng_title();

	// pre_get_document_title is printed as returned, so it is escaped here.
	return '' !== $ours ? esc_html( $ours ) : $title;
}

add_filter( 'wp_robots', 'daw_eng_wp_robots', 999 );
function daw_eng_wp_robots( $robots ) {
	if ( ! daw_eng_live() || is_admin() ) {
		return $robots;
	}

	$out = array();

	foreach ( daw_eng_robots() as $key => $value ) {
		$value = (string) $value;

		if ( 'index' === $key || 'follow' === $key ) {
			$out[ $value ] = true;
		} elseif ( false !== strpos( $value, ':' ) ) {
			list( $k, $v ) = explode( ':', $value, 2 );
			$out[ $k ]     = $v;
		} elseif ( '' !== $value ) {
			$out[ $value ] = true;
		}
	}

	return $out;
}

add_action( 'wp', 'daw_eng_take_over_head' );
function daw_eng_take_over_head() {
	if ( ! daw_eng_live() || is_admin() ) {
		return;
	}

	// One canonical, ours. WordPress prints its own on singular pages.
	remove_action( 'wp_head', 'rel_canonical' );
}

add_action( 'wp_head', 'daw_eng_print_head', 1 );
function daw_eng_print_head() {
	if ( ! daw_eng_live() || is_feed() ) {
		return;
	}

	$d = daw_eng_head_data();

	echo "\n<!-- DAW SEO -->\n";

	if ( '' !== $d['description'] ) {
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $d['description'] ) );
	}
	if ( '' !== $d['canonical'] ) {
		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $d['canonical'] ) );
	}

	daw_eng_print_prev_next();

	foreach ( $d['og'] as $property => $content ) {
		printf( '<meta property="%s" content="%s">' . "\n", esc_attr( $property ), esc_attr( $content ) );
	}
	foreach ( $d['article'] as $property => $content ) {
		printf( '<meta property="%s" content="%s">' . "\n", esc_attr( $property ), esc_attr( $content ) );
	}

	if ( $d['image'] ) {
		$img = $d['image'];
		printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $img['url'] ) );
		if ( 0 === strpos( $img['url'], 'https://' ) ) {
			printf( '<meta property="og:image:secure_url" content="%s">' . "\n", esc_url( $img['url'] ) );
		}
		if ( $img['w'] && $img['h'] ) {
			printf( '<meta property="og:image:width" content="%d">' . "\n", (int) $img['w'] );
			printf( '<meta property="og:image:height" content="%d">' . "\n", (int) $img['h'] );
		}
		$alt = '' !== $img['alt'] ? $img['alt'] : $d['og']['og:title'] ?? '';
		if ( '' !== $alt ) {
			printf( '<meta property="og:image:alt" content="%s">' . "\n", esc_attr( $alt ) );
		}
		if ( '' !== $img['type'] ) {
			printf( '<meta property="og:image:type" content="%s">' . "\n", esc_attr( $img['type'] ) );
		}
	}

	foreach ( $d['twitter'] as $name => $content ) {
		printf( '<meta name="%s" content="%s">' . "\n", esc_attr( $name ), esc_attr( $content ) );
	}
	if ( $d['image'] ) {
		printf( '<meta name="twitter:image" content="%s">' . "\n", esc_url( $d['image']['url'] ) );
	}

	if ( is_front_page() ) {
		daw_eng_print_verification();
	}

	echo "<!-- /DAW SEO -->\n\n";
}

/** Search Console, Bing, Yandex, Pinterest and Baidu ownership tags. */
function daw_eng_print_verification() {
	$tags = array(
		'google_verify'    => 'google-site-verification',
		'bing_verify'      => 'msvalidate.01',
		'yandex_verify'    => 'yandex-verification',
		'pinterest_verify' => 'p:domain_verify',
		'baidu_verify'     => 'baidu-site-verification',
	);

	foreach ( $tags as $key => $name ) {
		$code = trim( (string) daw_eng_set( $key, '' ) );

		if ( '' !== $code ) {
			printf( '<meta name="%s" content="%s">' . "\n", esc_attr( $name ), esc_attr( $code ) );
		}
	}
}

/** Previous and next on paged WordPress archives. */
function daw_eng_print_prev_next() {
	if ( is_singular() || is_404() || is_search() ) {
		return;
	}

	global $wp_query;

	$paged = max( 1, (int) get_query_var( 'paged' ) );
	$max   = (int) $wp_query->max_num_pages;

	if ( $max < 2 ) {
		return;
	}
	if ( $paged > 1 ) {
		printf( '<link rel="prev" href="%s">' . "\n", esc_url( get_pagenum_link( $paged - 1 ) ) );
	}
	if ( $paged < $max ) {
		printf( '<link rel="next" href="%s">' . "\n", esc_url( get_pagenum_link( $paged + 1 ) ) );
	}
}

/* =============================================================== shadow === */

/**
 * On a signed shadow request, the head the engine would print, in one
 * comment. Base64 because a title can contain "--", which would close an HTML
 * comment early.
 */
add_action( 'wp_head', 'daw_eng_print_shadow', 9999 );
function daw_eng_print_shadow() {
	if ( ! daw_eng_is_shadow() ) {
		return;
	}

	$d = daw_eng_head_data();

	$flat = array(
		'title'       => $d['title'],
		'description' => $d['description'],
		'robots'      => $d['robots'],
		'canonical'   => $d['canonical'],
		'mode'        => daw_eng_live() ? 'live' : 'shadow',
	);

	foreach ( array_merge( $d['og'], $d['twitter'] ) as $k => $v ) {
		$flat[ $k ] = $v;
	}

	if ( $d['image'] ) {
		$flat['og:image']      = $d['image']['url'];
		$flat['twitter:image'] = $d['image']['url'];
	}

	echo '<!--daw-seo-shadow:' . base64_encode( (string) wp_json_encode( $flat ) ) . "-->\n"; // phpcs:ignore WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions
}

/** A shadow request is never stored in, or served from, the page cache. */
add_action( 'init', 'daw_eng_shadow_nocache', 1 );
function daw_eng_shadow_nocache() {
	if ( ! daw_eng_is_shadow() ) {
		return;
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	do_action( 'litespeed_control_set_nocache', 'daw seo shadow' );
}

/* =============================================================== bridge === */

/**
 * While Rank Math is active, our rules reach it through its own filters.
 *
 * seo.php, browse.php, calendar.php and tmdb-remote.php hook daw_seo_*
 * filters now. These four lines hand Rank Math the same answers it was
 * getting from them before, so nothing on the site changes until Rank Math
 * is switched off, and nothing changes after either, because the engine
 * applies the same filters itself.
 */
add_action( 'plugins_loaded', 'daw_eng_bridge', 20 );
function daw_eng_bridge() {
	if ( ! daw_eng_rm_active() ) {
		return;
	}

	add_filter(
		'rank_math/frontend/title',
		static function ( $title ) {
			return apply_filters( 'daw_seo_title', $title );
		},
		20
	);
	add_filter(
		'rank_math/frontend/description',
		static function ( $description ) {
			return apply_filters( 'daw_seo_description', $description );
		},
		20
	);
	add_filter(
		'rank_math/frontend/robots',
		static function ( $robots ) {
			return apply_filters( 'daw_seo_robots', $robots );
		},
		20
	);
	add_filter(
		'rank_math/frontend/canonical',
		static function ( $canonical ) {
			return apply_filters( 'daw_seo_canonical', $canonical );
		},
		20
	);

	$image = static function ( $url ) {
		return apply_filters( 'daw_seo_og_image', $url );
	};
	add_filter( 'rank_math/opengraph/facebook/image', $image, 20 );
	add_filter( 'rank_math/opengraph/facebook/image_secure_url', $image, 20 );
	add_filter( 'rank_math/opengraph/twitter/image', $image, 20 );
}

/* ========================================================== redirections === */

/** Attachment pages and date archives go where Rank Math sent them. */
add_action( 'template_redirect', 'daw_eng_redirect_thin_pages', 3 );
function daw_eng_redirect_thin_pages() {
	if ( ! daw_eng_live() ) {
		return;
	}

	if ( is_attachment() && daw_eng_set( 'attachment_redirect', true ) ) {
		$post   = get_queried_object();
		$parent = ( $post instanceof WP_Post && $post->post_parent ) ? get_post( $post->post_parent ) : null;
		$target = ( $parent && 'publish' === $parent->post_status ) ? get_permalink( $parent ) : '';

		if ( ! $target ) {
			$target = '' !== (string) daw_eng_set( 'attachment_default', '' ) ? (string) daw_eng_set( 'attachment_default' ) : home_url( '/' );
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	if ( is_date() && daw_eng_set( 'disable_date_archives', true ) ) {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}

/* ======================================================== category base === */

/**
 * /k-drama/ and /trends/, not /category/k-drama/.
 *
 * Rank Math stripped the category base, and every category url Google has is
 * the short one. These are the same rules it wrote: one set per category,
 * replacing WordPress's own category rules rather than adding a new root, and
 * the old /category/... form answered with a 301.
 */
add_filter( 'category_rewrite_rules', 'daw_eng_category_rules', 20 );
function daw_eng_category_rules( $rules ) {
	if ( ! daw_eng_live() || ! daw_eng_set( 'strip_category_base', false ) ) {
		return $rules;
	}

	global $wp_rewrite;

	$new  = array();
	$cats = get_categories( array( 'hide_empty' => false ) );

	foreach ( $cats as $cat ) {
		$nicename = $cat->slug;

		if ( $cat->parent && (int) $cat->parent !== (int) $cat->term_id ) {
			$parents = get_category_parents( $cat->parent, false, '/', true );
			if ( ! is_wp_error( $parents ) ) {
				$nicename = $parents . $nicename;
			}
		}

		$nicename = preg_quote( $nicename, '#' );

		$new[ '(' . $nicename . ')/(?:feed/)?(feed|rdf|rss|rss2|atom)/?$' ]                    = 'index.php?category_name=$matches[1]&feed=$matches[2]';
		$new[ '(' . $nicename . ')/' . $wp_rewrite->pagination_base . '/?([0-9]{1,})/?$' ] = 'index.php?category_name=$matches[1]&paged=$matches[2]';
		$new[ '(' . $nicename . ')/?$' ]                                                     = 'index.php?category_name=$matches[1]';
	}

	$base         = trim( (string) get_option( 'category_base' ), '/' );
	$base         = '' !== $base ? $base : 'category';
	$new[ preg_quote( $base, '#' ) . '/(.*)$' ] = 'index.php?daw_cat_redirect=$matches[1]';

	return $new;
}

add_filter( 'query_vars', 'daw_eng_query_vars' );
function daw_eng_query_vars( $vars ) {
	$vars[] = 'daw_cat_redirect';

	return $vars;
}

add_filter( 'request', 'daw_eng_category_redirect' );
function daw_eng_category_redirect( $vars ) {
	if ( empty( $vars['daw_cat_redirect'] ) ) {
		return $vars;
	}

	wp_safe_redirect( home_url( user_trailingslashit( trim( (string) $vars['daw_cat_redirect'], '/' ), 'category' ) ), 301 );
	exit;
}

add_filter( 'term_link', 'daw_eng_category_link', 10, 3 );
function daw_eng_category_link( $link, $term, $taxonomy ) {
	if ( 'category' !== $taxonomy || ! daw_eng_live() || ! daw_eng_set( 'strip_category_base', false ) ) {
		return $link;
	}

	$base = trim( (string) get_option( 'category_base' ), '/' );
	$base = '' !== $base ? $base : 'category';

	return preg_replace( '#^(' . preg_quote( untrailingslashit( home_url() ), '#' ) . ')/(?:\./)?' . preg_quote( $base, '#' ) . '/#', '$1/', (string) $link );
}

/** A new, renamed or deleted category needs its rule written. */
add_action( 'created_category', 'daw_eng_mark_flush' );
add_action( 'edited_category', 'daw_eng_mark_flush' );
add_action( 'delete_category', 'daw_eng_mark_flush' );
function daw_eng_mark_flush() {
	// Forgetting the last seen mode makes the next request flush once.
	update_option( 'daw_seo_mode_seen', '', true );
}

/**
 * The rules follow the state. When Rank Math goes, its own sitemap and
 * category rules have to be replaced by ours in the same breath, and when it
 * comes back the reverse. One flush per change, never per request.
 */
add_action( 'init', 'daw_eng_mode_watch', 99 );
function daw_eng_mode_watch() {
	$mode = daw_eng_live() ? 'live' : 'rm';
	$seen = (string) get_option( 'daw_seo_mode_seen', '' );

	if ( $seen !== $mode ) {
		update_option( 'daw_seo_mode_seen', $mode, true );
		flush_rewrite_rules( false );

		if ( 'live' === $mode && 'rm' === $seen ) {
			update_option( 'daw_seo_live_since', time(), false );
			do_action( 'litespeed_purge_all' );
		}
	}
}

/* ======================================================= core sitemaps === */

/** WordPress's own /wp-sitemap.xml would be a second sitemap. Off, as Rank Math had it. */
add_filter( 'wp_sitemaps_enabled', 'daw_eng_core_sitemaps' );
function daw_eng_core_sitemaps( $enabled ) {
	return daw_eng_live() ? false : $enabled;
}

/* =========================================================== robots.txt === */

add_filter( 'robots_txt', 'daw_eng_robots_txt', 20, 2 );
function daw_eng_robots_txt( $output, $public ) {
	if ( ! daw_eng_live() || ! $public ) {
		return $output;
	}

	$body = trim( str_replace( "\r", '', (string) daw_eng_set( 'robots_txt', daw_eng_default_robots_txt() ) ) );
	$line = 'Sitemap: ' . home_url( '/sitemap_index.xml' );

	if ( false === strpos( $body, $line ) ) {
		$body .= "\n\n" . $line;
	}

	return $body . "\n";
}

/* ======================================================= images and links === */

/**
 * A picture with no alt text gets the page's own name.
 *
 * Rank Math filled empty alts with the file name ("IMG_2041"), which tells a
 * screen reader and an image search nothing. The record's title is at least
 * the right subject. An alt somebody wrote is never touched.
 */
add_filter( 'wp_get_attachment_image_attributes', 'daw_eng_attachment_alt', 20, 2 );
function daw_eng_attachment_alt( $attr, $attachment ) {
	if ( ! daw_eng_live() || ! daw_eng_set( 'img_alt', true ) || ( isset( $attr['alt'] ) && '' !== trim( (string) $attr['alt'] ) ) ) {
		return $attr;
	}

	$title = daw_eng_plain( get_the_title( $attachment ) );

	if ( '' === $title || preg_match( '/^(img|dsc|image|photo|screenshot)[\s_-]*\d/i', $title ) || preg_match( '/\.(jpe?g|png|webp|gif)$/i', $title ) ) {
		$parent = $attachment->post_parent ? $attachment->post_parent : get_the_ID();
		$title  = $parent ? daw_eng_plain( get_the_title( $parent ) ) : '';
	}

	if ( '' !== $title ) {
		$attr['alt'] = $title;
	}

	return $attr;
}

/**
 * In written text: empty alts get the page title, and links that leave the
 * site open in a new tab with rel="noopener" (rule 3.6).
 */
add_filter( 'the_content', 'daw_eng_content_filter', 99 );
function daw_eng_content_filter( $html ) {
	if ( ! daw_eng_live() || is_admin() || is_feed() || ! class_exists( 'WP_HTML_Tag_Processor' ) || '' === (string) $html ) {
		return $html;
	}

	$want_alt = (bool) daw_eng_set( 'img_alt', true );
	$new_tab  = (bool) daw_eng_set( 'new_window_external', true );
	$nofollow = (bool) daw_eng_set( 'nofollow_external', false );

	if ( ! $want_alt && ! $new_tab && ! $nofollow ) {
		return $html;
	}

	$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$title = daw_eng_plain( get_the_title() );
	$p     = new WP_HTML_Tag_Processor( $html );

	while ( $p->next_tag() ) {
		$tag = $p->get_tag();

		if ( 'IMG' === $tag && $want_alt && '' !== $title ) {
			$alt = $p->get_attribute( 'alt' );
			if ( null === $alt || '' === trim( (string) $alt ) ) {
				// role="presentation" marks a deliberately silent picture.
				if ( 'presentation' !== $p->get_attribute( 'role' ) ) {
					$p->set_attribute( 'alt', $title );
				}
			}
			continue;
		}

		if ( 'A' !== $tag ) {
			continue;
		}

		$href = (string) $p->get_attribute( 'href' );
		if ( ! preg_match( '#^https?://#i', $href ) ) {
			continue;
		}

		$link_host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
		if ( '' === $link_host || $link_host === $host || 'www.' . $host === $link_host ) {
			continue;
		}

		$rel = preg_split( '/\s+/', strtolower( trim( (string) $p->get_attribute( 'rel' ) ) ), -1, PREG_SPLIT_NO_EMPTY );

		if ( $new_tab ) {
			if ( null === $p->get_attribute( 'target' ) ) {
				$p->set_attribute( 'target', '_blank' );
			}
			if ( '_blank' === $p->get_attribute( 'target' ) && ! in_array( 'noopener', $rel, true ) ) {
				$rel[] = 'noopener';
			}
		}
		if ( $nofollow && ! in_array( 'nofollow', $rel, true ) && ! in_array( 'sponsored', $rel, true ) ) {
			$rel[] = 'nofollow';
		}
		if ( $rel ) {
			$p->set_attribute( 'rel', implode( ' ', array_unique( $rel ) ) );
		}
	}

	return $p->get_updated_html();
}

/* ======================================================= fallback schema === */

/**
 * The pages schema.php and schema-browse.php do not describe: the front page,
 * the plain pages, categories and any post without a record type. Rank Math
 * described these with its own graph; once it is gone, this one does, using
 * the same Organization and WebSite nodes the records already carry.
 */
add_action( 'wp_head', 'daw_eng_fallback_schema', 22 );
function daw_eng_fallback_schema() {
	if ( ! daw_eng_live() || is_404() || is_search() || daw_eng_is_noindex() ) {
		return;
	}

	$ctx = daw_eng_context();

	if ( 'singular' === $ctx['kind'] ) {
		$post = $ctx['post'];

		if ( 'post' === $post->post_type && function_exists( 'daw_get_type' ) && daw_get_type( $post->ID ) ) {
			return; // schema.php.
		}
		if ( 'page' === $post->post_type && function_exists( 'daw_browse_schema_cfg' ) && daw_browse_schema_cfg( $post->ID ) ) {
			return; // schema-browse.php.
		}
	} elseif ( 'term' !== $ctx['kind'] && 'home' !== $ctx['kind'] ) {
		return;
	}

	$home  = home_url( '/' );
	$url   = daw_eng_canonical();
	$url   = '' !== $url ? $url : $home;
	$graph = array();

	$graph[] = function_exists( 'daw_schema_publisher' ) ? daw_schema_publisher() : array(
		'@type' => 'Organization',
		'@id'   => $home . '#organization',
		'name'  => (string) daw_eng_set( 'sitename' ),
		'url'   => $home,
	);
	$graph[] = function_exists( 'daw_schema_website' ) ? daw_schema_website() : array(
		'@type'     => 'WebSite',
		'@id'       => $home . '#website',
		'url'       => $home,
		'name'      => (string) daw_eng_set( 'sitename' ),
		'publisher' => array( '@id' => $home . '#organization' ),
	);

	$is_front = ! empty( $ctx['front'] ) || 'home' === $ctx['kind'];
	$image    = daw_eng_og_image();

	$page = array_filter(
		array(
			'@type'       => 'term' === $ctx['kind'] ? 'CollectionPage' : 'WebPage',
			'@id'         => $url . '#webpage',
			'url'         => $url,
			'name'        => daw_eng_title(),
			'description' => daw_eng_description(),
			'isPartOf'    => array( '@id' => $home . '#website' ),
			'about'       => $is_front ? array( '@id' => $home . '#organization' ) : null,
			'inLanguage'  => str_replace( '_', '-', get_locale() ),
			'primaryImageOfPage' => $image ? array( '@type' => 'ImageObject', 'url' => $image['url'] ) : null,
		)
	);

	if ( ! $is_front ) {
		$page['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
		$name               = 'term' === $ctx['kind'] ? daw_eng_plain( $ctx['term']->name ) : daw_eng_plain( get_the_title( $ctx['post'] ) );
		$graph[]            = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => array(
				array( '@type' => 'ListItem', 'position' => 1, 'name' => __( 'Home', 'daw' ), 'item' => $home ),
				array( '@type' => 'ListItem', 'position' => 2, 'name' => $name ),
			),
		);
	}

	$graph[] = $page;

	if ( 'singular' === $ctx['kind'] && 'post' === $ctx['post']->post_type ) {
		$post    = $ctx['post'];
		$graph[] = array_filter(
			array(
				'@type'            => 'Article',
				'@id'              => $url . '#article',
				'headline'         => daw_eng_plain( get_the_title( $post ) ),
				'description'      => daw_eng_description(),
				'datePublished'    => get_post_time( 'c', true, $post ),
				'dateModified'     => get_post_modified_time( 'c', true, $post ),
				'author'           => array( '@id' => $home . '#organization' ),
				'publisher'        => array( '@id' => $home . '#organization' ),
				'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
				'image'            => $image ? $image['url'] : null,
				'inLanguage'       => str_replace( '_', '-', get_locale() ),
			)
		);
	}

	printf(
		"<script type=\"application/ld+json\" id=\"daw-schema-page\">%s</script>\n",
		wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array_values( array_filter( $graph ) ),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		)
	);
}

/* ========================================================== status for MCP === */

/**
 * The comparison report and the engine status can be read from the chat
 * through Royal MCP's option reader. Neither holds anything private.
 */
add_filter( 'royal_mcp_readable_options', 'daw_eng_readable_options' );
function daw_eng_readable_options( $options ) {
	$options   = (array) $options;
	$options[] = 'daw_seo_shadow_report';
	$options[] = 'daw_seo_status';
	$options[] = 'daw_rd_import_report';
	$options[] = 'daw_seo_mode_seen';

	return array_values( array_unique( $options ) );
}

/** Search Console state without the key: connected or not, and how far the data goes. */
function daw_eng_gsc_status() {
	if ( ! function_exists( 'daw_gsc_settings' ) ) {
		return array();
	}

	global $wpdb;

	$s     = daw_gsc_settings();
	$creds = daw_gsc_creds();
	$ready = (int) get_option( 'daw_gsc_db', 0 ) >= DAW_GSC_DB_VERSION;

	return array(
		'connected'   => (bool) $creds,
		'account'     => $creds ? $creds['email'] : '',
		'property'    => (string) $s['property'],
		'last_day'    => (string) $s['last_day'],
		'synced_at'   => (int) $s['synced_at'],
		'error'       => (string) $s['error'],
		'days'        => $ready ? (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT day) FROM ' . daw_gsc_table() ) : 0, // phpcs:ignore WordPress.DB
		'queries'     => $ready ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . daw_gsc_q_table() ) : 0, // phpcs:ignore WordPress.DB
		'inspected'   => $ready ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . daw_gsc_inspect_table() ) : 0, // phpcs:ignore WordPress.DB
		'not_indexed' => $ready ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . daw_gsc_inspect_table() . " WHERE verdict <> 'PASS'" ) : 0, // phpcs:ignore WordPress.DB
		'next_sync'   => (int) wp_next_scheduled( 'daw_gsc_sync' ),
	);
}

/** Redirect and 404 counts. */
function daw_eng_rd_status() {
	if ( ! function_exists( 'daw_rd_tables_ready' ) || ! daw_rd_tables_ready() ) {
		return array();
	}

	global $wpdb;

	return array(
		'redirects' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . daw_rd_table() . " WHERE status = 'active'" ), // phpcs:ignore WordPress.DB
		'auto'      => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . daw_rd_table() . " WHERE origin = 'auto'" ), // phpcs:ignore WordPress.DB
		'log_404'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . daw_nf_table() . " WHERE kind = 'page'" ), // phpcs:ignore WordPress.DB
	);
}

/** A one-line picture of the engine, refreshed on admin visits. */
add_action( 'admin_init', 'daw_eng_status_refresh' );
function daw_eng_status_refresh() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$last = get_option( 'daw_seo_status' );
	if ( is_array( $last ) && isset( $last['at'] ) && time() - (int) $last['at'] < 60 ) {
		return;
	}

	update_option(
		'daw_seo_status',
		array(
			'at'          => time(),
			'version'     => defined( 'DAW_VERSION' ) ? DAW_VERSION : '',
			'rank_math'   => daw_eng_rm_active() ? ( defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : 'active' ) : 'inactive',
			'engine'      => daw_eng_live() ? 'live' : ( 'off' === get_option( 'daw_seo_engine', 'auto' ) ? 'off' : 'standby' ),
			'live_since'  => (int) get_option( 'daw_seo_live_since', 0 ),
			'settings'    => is_array( get_option( 'daw_seo' ) ) ? 'saved' : 'from-rank-math',
			'gsc'         => daw_eng_gsc_status(),
			'redirects'   => daw_eng_rd_status(),
			// Which site-verification tags are printed (names only, no codes).
			'verify'      => array_values( array_filter( array( 'google_verify', 'bing_verify', 'yandex_verify', 'pinterest_verify', 'baidu_verify' ), static function ( $k ) { return '' !== trim( (string) daw_eng_set( $k ) ); } ) ),
		),
		false
	);
}
