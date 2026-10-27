<?php
/**
 * Discover Asian World — stop loading what the page does not use.
 *
 * WHAT WAS MEASURED
 * -----------------
 * A browse page, which is a grid of posters and a filter form, was pulling
 * seventeen javascript files, seven stylesheets, jQuery and WordPress's emoji
 * script. Our own templates need one stylesheet and about two kilobytes of
 * inline script. Everything else came from plugins that had no work to do on
 * that page and enqueued themselves anyway.
 *
 * WHY THIS RATHER THAN TURNING PLUGINS OFF
 * ----------------------------------------
 * Because a plugin that is off on a page that needs it is a broken page, and
 * some pages still hold page-builder content. This file is the smaller, safer
 * half of the job: on a page the builder is not drawing, the builder's assets
 * are dequeued. A page that still has builder content keeps everything it had.
 *
 * Nothing here deactivates anything. Every rule is reversible by deleting the
 * line that makes it, and each one states what it is dropping and why.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the page being drawn is one of ours.
 *
 * Ours means a template in the DAW theme is producing the markup, which is true
 * of every record, every browse and section page, the hubs, the calendar, the
 * documents, search, the home page and 404. On those, the page builder is not
 * involved in a single pixel.
 */
function daw_is_daw_page() {
	if ( is_admin() ) {
		return false;
	}

	if ( is_search() || is_404() ) {
		return true;
	}

	if ( is_singular( 'post' ) ) {
		return (bool) ( function_exists( 'daw_get_type' ) && daw_get_type( get_the_ID() ) );
	}

	if ( is_front_page() ) {
		return true;
	}

	if ( ! is_page() ) {
		return false;
	}

	$id = (int) get_queried_object_id();

	// A page whose body still holds builder content is drawn by the builder
	// even when one of our templates is in front of it, so it keeps its assets.
	if ( daw_page_uses_builder( $id ) ) {
		return false;
	}

	foreach ( array( 'daw_browse_for_page', 'daw_hub_for_page', 'daw_section_for_page', 'daw_doc_for_page' ) as $fn ) {
		if ( function_exists( $fn ) && $fn( $id ) ) {
			return true;
		}
	}

	// The calendar has no for_page() of its own: it is the one page matched by
	// slug, so it is asked for by slug here too rather than being given a
	// function it does not otherwise need.
	if ( defined( 'DAW_CALENDAR_SLUG' ) && DAW_CALENDAR_SLUG === get_post_field( 'post_name', $id ) ) {
		return true;
	}

	return false;
}

/**
 * Whether a page's own body is page-builder output.
 *
 * Both halves matter: the builder flag says it was edited there, and the body
 * says whether anything came of it. A page marked as a builder page with an
 * empty body is a page somebody opened in the builder once, and it does not
 * need three hundred kilobytes of javascript to print nothing.
 */
function daw_page_uses_builder( $post_id ) {
	$post_id = (int) $post_id;

	if ( ! $post_id ) {
		return false;
	}

	$mode = (string) get_post_meta( $post_id, '_elementor_edit_mode', true );

	if ( 'builder' !== $mode ) {
		return false;
	}

	$data = get_post_meta( $post_id, '_elementor_data', true );

	if ( ! $data || '[]' === $data ) {
		return false;
	}

	// The body a reader would see. A page whose content is a marker comment is
	// drawn from the database by one of our templates, whatever the builder
	// thinks it still owns.
	$body = trim( (string) get_post_field( 'post_content', $post_id ) );
	$body = trim( preg_replace( '/<!--.*?-->/s', '', $body ) );

	return '' !== $body;
}

/* ============================================================== emoji === */

/**
 * WordPress's emoji script, which nothing on this site uses.
 *
 * It is a separate request and a script that scans every page for characters
 * that could be replaced with images, on a site whose writing is plain text and
 * whose one emoji-shaped thing is a drawn SVG. It has been safe to remove since
 * about 2016 and it is still enqueued by default.
 */
add_action( 'init', 'daw_drop_emoji' );
function daw_drop_emoji() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

	add_filter( 'tiny_mce_plugins', 'daw_drop_emoji_tinymce' );
	add_filter( 'wp_resource_hints', 'daw_drop_emoji_hint', 10, 2 );
}

function daw_drop_emoji_tinymce( $plugins ) {
	return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
}

function daw_drop_emoji_hint( $urls, $relation ) {
	if ( 'dns-prefetch' !== $relation ) {
		return $urls;
	}

	return array_filter(
		$urls,
		static function ( $url ) {
			return false === strpos( (string) ( is_array( $url ) ? ( $url['href'] ?? '' ) : $url ), 's.w.org' );
		}
	);
}

/* ========================================================== the builder = */

/**
 * The page builder's front-end assets, on pages it is not drawing.
 *
 * Elementor enqueues its stylesheets and its javascript on every page of the
 * site, not only the ones built with it, and each add-on brings its own on top.
 * On a page drawn entirely by our own templates that is several hundred
 * kilobytes to render nothing at all.
 *
 * Handles are matched by prefix rather than listed one by one, because an
 * add-on's handles change with its version and a list would quietly stop
 * working after an update while looking as though it still did.
 */
add_action( 'wp_enqueue_scripts', 'daw_drop_builder_assets', 99 );
function daw_drop_builder_assets() {
	if ( ! daw_is_daw_page() ) {
		return;
	}

	$prefixes = array(
		'elementor-',
		'elementor_',
		'e-',
		'uael-',
		'ultimate-addons',
		'unlimited-elements',
		'ue-',
		'uc_',
		'widget-',
		'swiper',
		'font-awesome',
		'eicons',
	);

	foreach ( array( wp_scripts(), wp_styles() ) as $registry ) {
		foreach ( (array) $registry->queue as $handle ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $handle, $prefix ) ) {
					$registry->dequeue( $handle );
					break;
				}
			}
		}
	}
}

/**
 * Elementor's inline critical css and its font preloads, same pages, same
 * reason: they are printed from wp_head rather than enqueued, so dequeuing
 * misses them.
 */
add_action( 'wp_enqueue_scripts', 'daw_drop_builder_inline', 100 );
function daw_drop_builder_inline() {
	if ( ! daw_is_daw_page() ) {
		return;
	}

	remove_action( 'wp_head', array( 'Elementor\\Plugin', 'instance' ), 0 );
	add_filter( 'elementor/frontend/print_google_fonts', '__return_false' );
}

/* ============================================================== jquery == */

/**
 * jQuery, which this theme does not use one line of.
 *
 * It is dropped only where nothing else has asked for it: a plugin that
 * declares jQuery as a dependency still gets it, because taking a library away
 * from code that expects it is how a site breaks silently. So the test is not
 * "do we need it" but "has anything still queued asked for it".
 */
add_action( 'wp_enqueue_scripts', 'daw_drop_jquery', 101 );
function daw_drop_jquery() {
	if ( ! daw_is_daw_page() ) {
		return;
	}

	$scripts = wp_scripts();

	foreach ( (array) $scripts->queue as $handle ) {
		if ( in_array( $handle, array( 'jquery', 'jquery-core', 'jquery-migrate' ), true ) ) {
			continue;
		}

		$item = $scripts->registered[ $handle ] ?? null;

		if ( $item && array_intersect( (array) $item->deps, array( 'jquery', 'jquery-core' ) ) ) {
			return; // Something still needs it. Leave it alone.
		}
	}

	wp_dequeue_script( 'jquery' );
	wp_dequeue_script( 'jquery-core' );
	wp_dequeue_script( 'jquery-migrate' );
}

/* ============================================================== admin === */

/**
 * A count of what is still being loaded, for the admin bar.
 *
 * Not a performance plugin: one number, on the front end, for somebody logged
 * in, so a change here can be checked without opening developer tools and
 * without guessing.
 */
add_action( 'admin_bar_menu', 'daw_asset_counter', 999 );
function daw_asset_counter( $bar ) {
	if ( is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$bar->add_node(
		array(
			'id'    => 'daw-assets',
			'title' => sprintf(
				/* translators: 1: number of stylesheets, 2: number of scripts */
				__( 'DAW: %1$s css · %2$s js', 'daw' ),
				number_format_i18n( count( (array) wp_styles()->done ) ),
				number_format_i18n( count( (array) wp_scripts()->done ) )
			),
		)
	);
}

/* ========================================================= dead css ===== */

/**
 * Seventy kilobytes of inline stylesheet, on every page, for plugins that are
 * not installed.
 *
 * WHAT WAS MEASURED
 * -----------------
 * /dramas/ is a hundred and ninety kilobytes of html. Eighty eight of those are
 * inline CSS: seventy from the parent theme's dynamic stylesheet, fourteen from
 * WordPress's global styles and four from the block library. The parent theme's
 * share carries compatibility rules for Elementor, WooCommerce, LearnDash,
 * LifterLMS and BuddyPress, written out whether or not any of them exist. On
 * this site none of them do.
 *
 * WHAT IS REMOVED
 * ---------------
 * Only whole rules whose every selector begins with one of those prefixes. A
 * rule that styles anything this site renders is untouched, because a selector
 * that does not start with one of those words is not matched at all. The list
 * is deliberately short: this is not a CSS optimiser, it is a list of plugins
 * that are not here.
 *
 * WHY NOT JUST TURN IT OFF
 * ------------------------
 * The rest of that stylesheet is the header, the typography and the container
 * widths the site actually uses. Removing all of it would be removing the
 * theme.
 */
function daw_dead_css_prefixes() {
	return array(
		'.elementor',
		'.e-con',
		'.woocommerce',
		'.wc-',
		'.learndash',
		'.lifterlms',
		'.llms-',
		'.bbpress',
		'.bbp-',
		'.buddypress',
		'#bbpress-forums',
		'.tutor-',
	);
}

add_filter( 'astra_dynamic_theme_css', 'daw_strip_dead_css', 99 );
function daw_strip_dead_css( $css ) {
	if ( ! is_string( $css ) || '' === $css || is_admin() ) {
		return $css;
	}

	$prefixes = daw_dead_css_prefixes();

	$out = preg_replace_callback(
		'/([^{}]+)\{([^{}]*)\}/',
		static function ( $m ) use ( $prefixes ) {
			$selectors = array_filter( array_map( 'trim', explode( ',', $m[1] ) ) );

			if ( ! $selectors ) {
				return $m[0];
			}

			foreach ( $selectors as $selector ) {
				// An at-rule or a keyframe step is not a selector list, and a
				// selector that starts with anything else is a selector this
				// site might use.
				if ( '' !== $selector && '@' === $selector[0] ) {
					return $m[0];
				}

				$dead = false;

				foreach ( $prefixes as $prefix ) {
					if ( 0 === strpos( $selector, $prefix ) ) {
						$dead = true;
						break;
					}
				}

				if ( ! $dead ) {
					return $m[0];
				}
			}

			return '';
		},
		$css
	);

	return is_string( $out ) ? $out : $css;
}

/**
 * The block editor's stylesheets, on pages drawn without blocks.
 *
 * WordPress prints the block library and the global styles on every page. A
 * record page is a template reading fields, so neither is used, and together
 * they are eighteen kilobytes of inline css. A guide whose body was written in
 * the editor does use blocks, so that page keeps them: has_blocks answers the
 * question rather than a guess about which template is running.
 */
add_action( 'wp_enqueue_scripts', 'daw_drop_block_css', 100 );
function daw_drop_block_css() {
	if ( is_admin() ) {
		return;
	}

	// THE HOME PAGE WAS PAYING FOR IT TOO
	// -----------------------------------
	// This used to stop at "not a singular page", so the home page, search,
	// 404 and every browse page kept the block library: 113 KB of render
	// blocking css on the front door of the site, measured locally with
	// Lighthouse on 2 Oct 2026, for markup none of those templates prints.
	// Those pages are drawn entirely by our templates, so the same answer
	// applies to them as to a record without blocks.
	if ( ! is_singular() && ! is_page() ) {
		if ( ! daw_is_daw_page() ) {
			return;
		}
	} else {
		$post = get_post();

		if ( ! $post || has_blocks( $post ) ) {
			return;
		}
	}

	// A page built by one of our templates prints no block markup at all, and
	// a record whose body is plain html prints none either.
	wp_dequeue_style( 'wp-block-library' );
	wp_dequeue_style( 'wp-block-library-theme' );
	wp_dequeue_style( 'global-styles' );
	wp_dequeue_style( 'classic-theme-styles' );
}

/* ============================================================ analytics == */

/**
 * Site Kit's gtag.js, once the page has loaded.
 *
 * WHAT WAS MEASURED
 * -----------------
 * After 1.47.26 the live page had no layout shift left and a 2.2 s LCP on a
 * phone, and what remained was script: 368 KB of JavaScript PageSpeed found
 * unused, and up to 808 ms of main-thread blocking. gtag.js is about 150 KB
 * of that, and Site Kit prints it async in the head, so it is fetched and
 * parsed while the page is still being drawn.
 *
 * WHY THIS LOSES NO DATA
 * ----------------------
 * Site Kit's own inline lines (the dataLayer, the gtag() queue, the consent
 * defaults and the config call) stay where they are and run at once; they
 * only push into window.dataLayer. The library reads that queue whenever it
 * arrives, so the page view, its consent state and its timing are recorded
 * exactly as before. Only the download moves, to the load event.
 */
add_filter( 'script_loader_tag', 'daw_gtag_after_load', 20, 3 );
function daw_gtag_after_load( $tag, $handle, $src ) {
	if ( 'google_gtagjs' !== $handle || is_admin() || ! $src ) {
		return $tag;
	}

	// $tag is the whole block WordPress prints for this handle: Site Kit's
	// inline lines before and after the file are part of it. Only the one
	// element that downloads the file is swapped; the rest stays as written.
	$loader = sprintf(
		"<script id=\"google_gtagjs-js\">window.addEventListener('load',function(){var s=document.createElement('script');s.async=true;s.src=%s;document.head.appendChild(s);},{once:true});</script>",
		wp_json_encode( $src )
	);

	$out = preg_replace( '#<script\b[^>]*\bid=(["\'])google_gtagjs-js\1[^>]*>\s*</script>#i', $loader, $tag, 1, $count );

	return ( $count && is_string( $out ) ) ? $out : $tag;
}

/* ======================================================= next page ==== */

/**
 * The next page, fetched while the reader is still reaching for it.
 *
 * WordPress 6.8+ prints a speculation-rules block that prefetches a link
 * only once it is pressed ("conservative"), which buys a few dozen
 * milliseconds. "moderate" starts the fetch when the pointer rests on a link
 * (about 200 ms on a desktop) and on press on a touch screen, so the html of
 * the next drama or the next list is usually already on the device when the
 * click lands. Prefetch, not prerender: only the document is fetched, no
 * script on the next page runs early, so no advert or analytics hit can fire
 * for a page the reader never opened. Browsers without speculation rules
 * (Safari, Firefox today) ignore the block.
 *
 * Core still decides when there is no block at all (a logged-in editor,
 * plain permalinks); that answer, null, is passed through untouched.
 */
add_filter( 'wp_speculation_rules_configuration', 'daw_speculation_moderate' );
function daw_speculation_moderate( $config ) {
	if ( ! is_array( $config ) ) {
		return $config;
	}

	return array(
		'mode'      => 'prefetch',
		'eagerness' => 'moderate',
	);
}

/**
 * LiteSpeed's empty placeholder stylesheet.
 *
 * LiteSpeed enqueues litespeed-dummy.css so its CSS optimiser has a handle to
 * hang inline styles on. This site keeps that optimiser off (litespeed.php),
 * so the file is a 600-byte request that blocks the first paint for nothing:
 * PageSpeed on the home page, 4 Oct 2026, put it at 190 ms on a phone.
 */
add_action( 'wp_print_styles', 'daw_drop_litespeed_dummy', 100 );
function daw_drop_litespeed_dummy() {
	if ( is_admin() || 'allow' === get_option( 'daw_litespeed_optm', 'bypass' ) ) {
		return;
	}

	wp_dequeue_style( 'litespeed-cache-dummy' );
}
