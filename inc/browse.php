<?php
/**
 * The browse pages: every drama, every film, every person, with filters.
 *
 * WHAT THIS IS FOR
 * ----------------
 * The country hubs answer "what is here from Korea". The section pages answer
 * "every Korean drama". Neither answers "romance dramas", "everything on
 * Netflix" or "films from the 1990s", and those are questions readers arrive
 * with. This is the page that answers them.
 *
 * NO REWRITE RULES, STILL
 * -----------------------
 * The rest of this plugin owns no URL and this file keeps that promise. A
 * browse page is an ordinary page that already exists; the filters are query
 * strings on it. Nothing here can collide with a post slug, nothing needs a
 * flush, and a filter renamed tomorrow cannot 404 a URL somebody shared today.
 *
 * WHAT GOOGLE IS ALLOWED TO INDEX
 * -------------------------------
 * The design asked for "every filter combination has its own indexable URL".
 * That is how a site earns hundreds of near-empty pages and a sitewide quality
 * problem: romance + Netflix + 2021 + 16 episodes matches two records, and two
 * records is not a page worth ranking.
 *
 * So the indexable set is written down rather than generated:
 *
 *   /dramas/, /films/, /people/        the unfiltered page, indexed
 *   country + type                      already a page of its own
 *                                       (/korean-drama/), so the country facet
 *                                       links there instead of filtering here
 *   anything else                       works for the reader, noindex for the
 *                                       crawler, canonical back to the base
 *
 * That keeps the filters useful to a person and keeps the crawler on the pages
 * that have something to say.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * Eighteen a page.
 *
 * The grid is six across on a desktop, three on a tablet, two on a phone, and
 * eighteen is the only small number that divides by all three. Twenty left a
 * row of two hanging under a row of six on the desktop and a single card alone
 * at the bottom on a phone, which reads as a page that ran out rather than a
 * page that ended.
 */
const DAW_BROWSE_PER_PAGE = 18;

/**
 * The browse pages, keyed by page slug.
 *
 * 'types'   dw_type slugs this page lists.
 * 'facets'  which filters to offer, in order. Every facet is dropped at render
 *           if the current result set has nothing to put in it, so a page never
 *           shows a filter that can only return zero.
 */
function daw_browse_pages() {
	return array(
		'dramas'   => array(
			'types'  => array( 'drama' ),
			'title'  => __( 'Asian dramas', 'daw' ),
			'one'    => __( 'drama', 'daw' ),
			'many'   => __( 'dramas', 'daw' ),
			'style'  => 'poster',
			'facets' => array( 'country', 'genre', 'status', 'decade', 'network', 'episodes' ),
		),
		'films'    => array(
			'types'  => array( 'film' ),
			'title'  => __( 'Asian films', 'daw' ),
			'one'    => __( 'film', 'daw' ),
			'many'   => __( 'films', 'daw' ),
			'style'  => 'poster',
			'facets' => array( 'country', 'genre', 'decade', 'runtime' ),
		),
		'people'   => array(
			'types'  => array( 'person' ),
			'title'  => __( 'Actors, idols and creators', 'daw' ),
			'one'    => __( 'person', 'daw' ),
			'many'   => __( 'people', 'daw' ),
			'style'  => 'poster',
			'facets' => array( 'country', 'role', 'gender', 'born', 'height' ),
		),
		'k-pop'    => array(
			// A SOLOIST IS A PERSON, NOT A GROUP
			// ---------------------------------
			// Jimin is a singer who is also a member of BTS. Filing him under
			// the group type made the card read "Music Group" under his face,
			// which is simply wrong. So this page carries both types and keeps
			// the person side down to the people who actually make music, and
			// the Solo or group filter separates them again for the reader.
			'types'  => array( 'group', 'person' ),
			// PRIMARY ROLE, AND KOREA
			// -----------------------
			// Matching any role that mentions singing put Andy Lau and Tony
			// Leung on the K-pop page, because both of them have sung. What
			// belongs here is the people whose work is the music: the first
			// thing their own record says they do. And the page is called
			// K-pop, so it is Korea, not every singer in Asia.
			'roles'     => array( 'idol', 'singer' ),
			// Counted wherever it appears rather than only first: an idol who
			// also acts is still an idol, and IU belongs on both pages.
			'roles_any' => array( 'idol' ),
			'flag'      => 'daw_music_act',
			'lock'      => 'south-korea',
			'title'  => __( 'K-pop groups and soloists', 'daw' ),
			'one'    => __( 'act', 'daw' ),
			'many'   => __( 'acts', 'daw' ),
			'style'  => 'poster',
			'facets' => array( 'kind', 'genre', 'gender' ),
		),
		// MUSIC IS THE GENERAL PAGE. K-POP IS ONE VIEW OF IT
		// --------------------------------------------------
		// /k-pop/ is an address readers already have and search engines
		// already hold, so it does not move, get renamed or get redirected.
		// It stays exactly what it is: this list, locked to Korea.
		//
		// This page is the same list without the lock, which is what lets a
		// Japanese, Chinese or Thai act be filed correctly instead of being
		// squeezed under a Korean heading or left off the site. The country
		// filter is what separates them, so there is one page to maintain
		// rather than one per scene.
		'music'    => array(
			'types'     => array( 'group', 'person' ),
			'roles'     => array( 'idol', 'singer' ),
			'roles_any' => array( 'idol' ),
			'flag'      => 'daw_music_act',
			'title'  => __( 'Music: groups, idols and soloists', 'daw' ),
			'one'    => __( 'act', 'daw' ),
			'many'   => __( 'acts', 'daw' ),
			'style'  => 'poster',
			'facets' => array( 'country', 'kind', 'genre', 'gender' ),
		),
		'food'     => array(
			'types'  => array( 'food' ),
			'title'  => __( 'Food and drink guides', 'daw' ),
			'one'    => __( 'guide', 'daw' ),
			'many'   => __( 'guides', 'daw' ),
			'style'  => 'guide',
			'facets' => array( 'dish', 'country' ),
		),
		'culture'  => array(
			'types'  => array( 'culture' ),
			'title'  => __( 'Culture guides', 'daw' ),
			'one'    => __( 'guide', 'daw' ),
			'many'   => __( 'guides', 'daw' ),
			'style'  => 'guide',
			'facets' => array( 'country' ),
		),
		'myths'    => array(
			'types'  => array( 'myth' ),
			'title'  => __( 'Myths and legends', 'daw' ),
			'one'    => __( 'guide', 'daw' ),
			'many'   => __( 'guides', 'daw' ),
			'style'  => 'guide',
			'facets' => array( 'country' ),
		),
		'travel'   => array(
			'types'  => array( 'travel' ),
			'title'  => __( 'Travel guides', 'daw' ),
			'one'    => __( 'guide', 'daw' ),
			'many'   => __( 'guides', 'daw' ),
			'style'  => 'guide',
			'facets' => array( 'country' ),
		),
		'news'     => array(
			'types'  => array( 'news' ),
			'title'  => __( 'News', 'daw' ),
			'one'    => __( 'story', 'daw' ),
			'many'   => __( 'stories', 'daw' ),
			'style'  => 'guide',
			'facets' => array( 'story', 'country' ),
		),
		'features' => array(
			'types'  => array( 'feature' ),
			'title'  => __( 'Features and lists', 'daw' ),
			'one'    => __( 'feature', 'daw' ),
			'many'   => __( 'features', 'daw' ),
			'style'  => 'guide',
			'facets' => array( 'country' ),
		),
		'quizzes'  => array(
			'types'  => array( 'quiz' ),
			'title'  => __( 'Quizzes', 'daw' ),
			'one'    => __( 'quiz', 'daw' ),
			'many'   => __( 'quizzes', 'daw' ),
			'style'  => 'guide',
			'facets' => array( 'country' ),
		),

		// TWO OLD URLS THAT ARE FILTER STATES WITH A HISTORY
		// --------------------------------------------------
		// Both of these were hand-built lists that went stale the week they
		// were typed. They are the same records this page layer already holds,
		// narrowed, so they keep their address and stop being maintained by
		// hand. The heading says what they are; the rail offers what is left.
		'korean-actress' => array(
			'types'  => array( 'person' ),
			'roles'  => array( 'actor' ),
			'lock'   => 'south-korea',
			'preset' => array( 'gender' => array( 'female' ) ),
			'title'  => __( 'Korean actresses', 'daw' ),
			'one'    => __( 'actress', 'daw' ),
			'many'   => __( 'actresses', 'daw' ),
			'style'  => 'poster',
			'facets' => array( 'born', 'height' ),
		),
		'korean-test'    => array(
			'types'  => array( 'quiz' ),
			'lock'   => 'south-korea',
			'title'  => __( 'Korean quizzes', 'daw' ),
			'one'    => __( 'quiz', 'daw' ),
			'many'   => __( 'quizzes', 'daw' ),
			'style'  => 'guide',
			'facets' => array(),
		),
	);
}

/**
 * The heading, which says what the reader actually asked for.
 *
 * A page with a genre and a decade chosen whose heading still reads "Asian
 * dramas" is a page that has not noticed its own state, and the reader has to
 * look at the sidebar to find out where they are.
 */
function daw_browse_heading( $cfg, $filters, $adjective = '' ) {
	$heading = $cfg['title'];

	// ONE VALUE NAMES THE PAGE, SEVERAL DO NOT
	// ----------------------------------------
	// "Romance dramas" is a page. "Romance, comedy and thriller dramas from
	// Korea and Japan on Netflix and tvN in the 2010s" is a sentence nobody
	// wants as a headline, and it is a combination the reader built rather than
	// a thing the site is about. So a filter contributes to the heading only
	// when it holds exactly one value, and a page narrowed further keeps the
	// name it started with. Those pages are noindex anyway.
	$one = static function ( $filters, $key ) {
		$values = (array) ( $filters[ $key ] ?? array() );

		return 1 === count( $values ) ? reset( $values ) : '';
	};

	$genre = $one( $filters, 'genre' );

	if ( $genre ) {
		$term = get_term_by( 'slug', $genre, 'dw_genre' );

		if ( $term ) {
			/* translators: 1: genre, 2: plural record label */
			$heading = sprintf( __( '%1$s %2$s', 'daw' ), $term->name, $cfg['many'] );

			if ( $adjective ) {
				/* translators: 1: nationality adjective, 2: heading so far */
				$heading = sprintf( __( '%1$s %2$s', 'daw' ), $adjective, strtolower( $heading ) );
			}
		}
	} elseif ( $adjective ) {
		/* translators: 1: nationality adjective, 2: plural record label */
		$heading = sprintf( __( '%1$s %2$s', 'daw' ), $adjective, $cfg['many'] );
	}

	$country = $one( $filters, 'country' );

	if ( $country && ! $adjective ) {
		$term = get_term_by( 'slug', $country, 'dw_country' );

		if ( $term ) {
			/* translators: 1: heading so far, 2: country name */
			$heading = sprintf( __( '%1$s from %2$s', 'daw' ), $heading, $term->name );
		}
	}

	$network = $one( $filters, 'network' );

	if ( $network ) {
		$term = get_term_by( 'slug', $network, 'dw_network' );

		if ( $term ) {
			/* translators: 1: heading so far, 2: network or platform name */
			$heading = sprintf( __( '%1$s on %2$s', 'daw' ), $heading, $term->name );
		}
	}

	$decade = $one( $filters, 'decade' );

	if ( $decade ) {
		/* translators: 1: heading so far, 2: decade, e.g. 2010 */
		$heading = sprintf( __( '%1$s from the %2$ss', 'daw' ), $heading, (int) $decade );
	}

	$status = $one( $filters, 'status' );

	if ( 'ongoing' === $status ) {
		/* translators: %s: heading so far */
		$heading = sprintf( __( '%s on air now', 'daw' ), $heading );
	} elseif ( 'upcoming' === $status ) {
		/* translators: %s: heading so far */
		$heading = sprintf( __( 'Upcoming %s', 'daw' ), strtolower( $heading ) );
	}

	return $heading;
}

/**
 * The browse config for one record type, whatever page is asking.
 *
 * The country sections use this so /korean-drama/ offers exactly the filters
 * /dramas/ offers, minus the one it is already locked to. A type nobody has
 * written a row for still gets a working page, with no filters and the right
 * card shape, rather than a fatal.
 */
function daw_browse_cfg_for_type( $type, $label_one = '', $label_many = '' ) {
	foreach ( daw_browse_pages() as $cfg ) {
		if ( ( $cfg['types'][0] ?? '' ) === $type ) {
			return $cfg;
		}
	}

	$sections = function_exists( 'daw_hub_sections' ) ? daw_hub_sections() : array();

	return array(
		'types'  => array( $type ),
		'title'  => $label_many ? $label_many : $type,
		'one'    => $label_one ? $label_one : $type,
		'many'   => $label_many ? $label_many : $type,
		'style'  => $sections[ $type ]['style'] ?? 'poster',
		'facets' => array(),
	);
}

/** The browse config for a page, or null when the page is not one. */
function daw_browse_for_page( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();

	if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
		return null;
	}

	$pages = daw_browse_pages();
	$slug  = get_post_field( 'post_name', $post_id );

	return $pages[ $slug ] ?? null;
}

add_filter( 'page_template', 'daw_route_browse_template' );

/**
 * Draw a browse page with the browse template.
 *
 * Same rule as the sections: a page with real text in its body keeps its text.
 * That is the escape hatch if one of these ever has to go back to being an
 * ordinary page, and it is why nothing here is destructive.
 */
function daw_route_browse_template( $template ) {
	if ( ! daw_browse_for_page() ) {
		return $template;
	}

	if ( function_exists( 'daw_section_is_live' ) && ! daw_section_is_live() ) {
		return $template;
	}

	$found = locate_template( 'page-browse.php' );

	return $found ? $found : $template;
}

/* ============================================================== request === */

/**
 * The filters, read from the query string and sanitised to things that exist.
 *
 * Anything unrecognised is dropped rather than passed through, so a hand-typed
 * or link-rotted parameter produces the unfiltered page instead of an error or
 * an empty one.
 */
function daw_browse_request() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$raw = array();

	foreach ( daw_browse_filter_keys() as $key ) {
		$raw[ $key ] = isset( $_GET[ $key ] ) ? daw_browse_split( wp_unslash( $_GET[ $key ] ) ) : array();
	}

	$raw['sort'] = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : '';
	$raw['pg']   = isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1;
	// phpcs:enable

	// Each filter is checked against the things that actually exist, so a url
	// somebody typed or an old link to a term that has since been merged comes
	// back as no filter rather than as an empty page.
	foreach ( array( 'country' => 'dw_country', 'genre' => 'dw_genre', 'network' => 'dw_network' ) as $key => $taxonomy ) {
		$raw[ $key ] = array_values(
			array_filter(
				array_map( 'sanitize_title', $raw[ $key ] ),
				static function ( $slug ) use ( $taxonomy ) {
					return (bool) get_term_by( 'slug', $slug, $taxonomy );
				}
			)
		);
	}

	$allowed = array(
		'status' => array( 'completed', 'ongoing', 'upcoming' ),
		'role'   => array_keys( daw_person_role_labels() ),
		'kind'   => array_keys( daw_browse_kind_labels() ),
		'gender' => array_keys( daw_browse_gender_labels() ),
		'dish'   => array_keys( daw_food_kind_labels() ),
		'story'  => array_keys( daw_news_story_labels() ),
		'eps'    => array_keys( daw_browse_bands( 'episodes' ) ),
		'runtime' => array_keys( daw_browse_bands( 'runtime' ) ),
		'height' => array_keys( daw_browse_bands( 'height' ) ),
	);

	foreach ( $allowed as $key => $list ) {
		$raw[ $key ] = array_values( array_intersect( array_map( 'sanitize_key', $raw[ $key ] ), $list ) );
	}

	foreach ( array( 'decade', 'born' ) as $key ) {
		$raw[ $key ] = array_values(
			array_filter(
				array_map( 'absint', $raw[ $key ] ),
				static function ( $year ) {
					return $year >= 1900 && $year <= 2100;
				}
			)
		);
	}

	if ( ! in_array( $raw['sort'], array( 'new', 'old', 'az', 'credits' ), true ) ) {
		$raw['sort'] = 'new';
	}

	// Not a reader filter: the page's own restriction, set from its config by
	// the template so that every function down the chain reads it from the same
	// array and the cache key changes with it.
	$raw['require_roles']     = array();
	$raw['require_roles_any'] = array();
	$raw['require_flag']      = '';

	// Not a filter either: a caller that only needs the set, not the order.
	$raw['unsorted'] = false;

	return $raw;
}

/**
 * Every filter key, in the order the sidebar prints them.
 *
 * One list, used by the request reader, the url builder, the query and the
 * indexing rule, so a filter added here is a filter the whole chain knows about
 * rather than one that has to be remembered in five places.
 */
function daw_browse_filter_keys() {
	return array( 'country', 'genre', 'network', 'decade', 'status', 'role', 'kind', 'gender', 'dish', 'story', 'eps', 'runtime', 'height', 'born' );
}

/**
 * One query value into a list.
 *
 * MULTIPLE VALUES IN ONE FILTER
 * -----------------------------
 * A reader who wants romance does not usually want only romance: they want
 * romance or comedy, on Netflix or tvN. Filters that take one value each made
 * them choose, and choosing meant going back and forth. So a filter now holds a
 * list, the values inside it are an or, and the filters between them are an
 * and, which is the arrangement everybody already knows from every shop.
 */
function daw_browse_split( $value ) {
	if ( is_array( $value ) ) {
		$parts = $value;
	} else {
		$parts = explode( ',', (string) $value );
	}

	$parts = array_map( 'trim', $parts );
	$parts = array_filter( $parts, 'strlen' );

	// A list long enough to be an attack is not a list a reader built.
	return array_slice( array_values( array_unique( $parts ) ), 0, 12 );
}

/** True when the reader has narrowed the page at all. */
function daw_browse_is_filtered( $filters ) {
	foreach ( daw_browse_filter_keys() as $key ) {
		if ( ! empty( $filters[ $key ] ) ) {
			return true;
		}
	}

	return ! empty( $filters['pg'] ) && $filters['pg'] > 1;
}

/**
 * How many separate choices the reader has made, counting each value.
 *
 * The country a section page is locked to is not one of them. /thai-movie/
 * arrives with its country already answered, and counting it made the button
 * read "Filters 2" when the reader had chosen exactly one thing.
 */
function daw_browse_count_active( $filters, $fixed = '' ) {
	$fixed = daw_browse_fixed_arg( $fixed );
	$n     = 0;

	foreach ( daw_browse_filter_keys() as $key ) {
		$values = array_map( 'strval', (array) ( $filters[ $key ] ?? array() ) );

		if ( isset( $fixed[ $key ] ) ) {
			$values = array_diff( $values, $fixed[ $key ] );
		}

		$n += count( $values );
	}

	return $n;
}

/**
 * The filter values a page fixes for itself, as key => values.
 *
 * WHY A PAGE FIXES ANYTHING
 * -------------------------
 * /k-pop/ is Korea by definition, and /korean-actress/ is one url that has
 * ranked for three years for a query the filter layer answers as
 * ?country=south-korea&gender=female. Throwing that url away to prove a point
 * about filter design would cost the traffic and gain nothing, so the page
 * keeps its address and the filters it stands for are applied for the reader
 * rather than asked of them.
 *
 * A fixed filter is not a choice the reader made, so it is not offered in the
 * rail, not printed as a chip, and not cleared by "Clear all".
 */
function daw_browse_fixed( $cfg ) {
	$fixed = array();

	if ( ! empty( $cfg['lock'] ) ) {
		$fixed['country'] = array( (string) $cfg['lock'] );
	}

	foreach ( (array) ( $cfg['preset'] ?? array() ) as $key => $values ) {
		$fixed[ $key ] = array_values( array_map( 'strval', (array) $values ) );
	}

	return $fixed;
}

/** A country slug or a ready-made fixed map, always returned as the map. */
function daw_browse_fixed_arg( $fixed ) {
	if ( is_array( $fixed ) ) {
		return $fixed;
	}

	return $fixed ? array( 'country' => array( (string) $fixed ) ) : array();
}

/** The facet that offers a filter key, so a fixed key can be dropped from the rail. */
function daw_browse_facet_of_key( $key ) {
	$map = array( 'eps' => 'episodes' );

	return $map[ $key ] ?? $key;
}

/** Whether one value of one filter is currently on. */
function daw_browse_has( $filters, $key, $value ) {
	$now = (array) ( $filters[ $key ] ?? array() );

	return in_array( (string) $value, array_map( 'strval', $now ), true );
}

/**
 * The url with one value added to a filter, or taken out of it if it is on.
 *
 * This is what every option in the sidebar links to, which is why a click never
 * clears the rest of the page: it edits one value and leaves everything else
 * exactly where the reader put it.
 */
function daw_browse_toggle_url( $base, $filters, $key, $value ) {
	$now   = array_map( 'strval', (array) ( $filters[ $key ] ?? array() ) );
	$value = (string) $value;

	if ( in_array( $value, $now, true ) ) {
		$next = array_values( array_diff( $now, array( $value ) ) );
	} else {
		$next   = $now;
		$next[] = $value;
	}

	return daw_browse_url( $base, $filters, array( $key => $next ) );
}

/** One browse url with some filters changed. An empty array clears a filter. */
function daw_browse_url( $base, $filters, $changes = array() ) {
	$next = array_merge( $filters, $changes );

	// A changed filter always returns to page one: page four of a different
	// question is not a place anybody meant to be.
	if ( ! isset( $changes['pg'] ) ) {
		$next['pg'] = 1;
	}

	$query = array();

	foreach ( daw_browse_filter_keys() as $key ) {
		$values = array_values( array_filter( (array) ( $next[ $key ] ?? array() ), 'strlen' ) );

		if ( $values ) {
			$query[ $key ] = implode( ',', $values );
		}
	}

	if ( ! empty( $next['sort'] ) && 'new' !== $next['sort'] ) {
		$query['sort'] = $next['sort'];
	}

	if ( ! empty( $next['pg'] ) && $next['pg'] > 1 ) {
		$query['pg'] = (int) $next['pg'];
	}

	return $query ? add_query_arg( $query, $base ) : $base;
}

/* ================================================================ query === */

/** The cache generation, bumped whenever a record is saved. */
function daw_browse_gen() {
	return (int) get_option( 'daw_browse_gen', 1 );
}

add_action( 'save_post_post', 'daw_browse_bump_gen' );
add_action( 'deleted_post', 'daw_browse_bump_gen' );

function daw_browse_bump_gen() {
	update_option( 'daw_browse_gen', daw_browse_gen() + 1, false );
}

/**
 * Every record id that matches, in the order asked for.
 *
 * The whole id list rather than one page of it, because the facet counts beside
 * the grid are counted from the same list: one query answers "what is on this
 * page" and "how many would each filter leave", and the two can never disagree.
 */
/**
 * Prime the caches for a set of records, once per request.
 *
 * WHY THIS IS NOT INSIDE THE QUERY
 * --------------------------------
 * MEASURED, and the first attempt at this was wrong. Priming after the database
 * query helped a cold page and did nothing for a warm one, because a warm page
 * never reaches the query: the id list comes back from a transient in a
 * millisecond, and then the six facet tallies walk those four hundred and sixty
 * four records asking each one what genre it is, with nothing primed. The
 * transient was making the page slower than having no transient at all.
 *
 * So priming belongs with the ids rather than with the query, and it remembers
 * what it has already primed, because a browse page asks for overlapping sets
 * seven times over and the second ask should be free.
 */
function daw_browse_prime( $ids ) {
	static $done = array();

	$todo = array();

	foreach ( (array) $ids as $id ) {
		$id = (int) $id;

		if ( $id && ! isset( $done[ $id ] ) ) {
			$todo[]      = $id;
			$done[ $id ] = true;
		}
	}

	if ( ! $todo ) {
		return;
	}

	update_meta_cache( 'post', $todo );
	update_object_term_cache( $todo, 'post' );
}

/**
 * @param array $types   Record types.
 * @param array $filters Browse filters.
 * @param int   $prime   How many of the returned ids the caller will actually
 *                       read fields from. 0 means all of them, which is what a
 *                       browse page needs and is the default, so no existing
 *                       caller changes behaviour.
 *
 * WHY THE LIMIT EXISTS, MEASURED
 * ------------------------------
 * The home page draws five strips. Each one asks for a whole id list, prints
 * between four and six cards from it, and uses the length for a label. Priming
 * is sized to the list, so the home page was warming the meta and term caches
 * for roughly nine hundred records in order to render about twenty. Uncached,
 * it cost 1.3 to 1.8 seconds against 0.4 for a title page drawn from the same
 * data.
 *
 * A browse page is the opposite case and must keep priming everything: its
 * facet tallies walk every id, and the comment on daw_browse_prime() records
 * what happens when they walk them cold. So the caller says what it will
 * touch, and the two pages stop paying each other's bills.
 */
function daw_browse_ids( $types, $filters, $prime = 0 ) {
	$unsorted  = ! empty( $filters['unsorted'] );
	$signature = array( daw_browse_gen(), $types, $unsorted ? 'set' : $filters['sort'], $filters['require_roles'], $filters['require_roles_any'] ?? array(), $filters['require_flag'] ?? '' );

	foreach ( daw_browse_filter_keys() as $k ) {
		$signature[ $k ] = (array) $filters[ $k ];
	}

	$key = 'daw_browse_' . md5( wp_json_encode( $signature ) );

	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		daw_browse_prime( $prime > 0 ? array_slice( $cached, 0, $prime ) : $cached );

		return $cached;
	}

	$tax = array(
		'relation' => 'AND',
		array(
			'taxonomy' => 'dw_type',
			'field'    => 'slug',
			'terms'    => (array) $types,
		),
	);

	foreach ( array( 'country' => 'dw_country', 'genre' => 'dw_genre', 'network' => 'dw_network' ) as $key_f => $taxonomy ) {
		if ( $filters[ $key_f ] ) {
			$tax[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $filters[ $key_f ],
			);
		}
	}

	// ORDERING HAPPENS HERE, NOT IN SQL
	// --------------------------------
	// Sorting by a meta value means passing meta_key to WP_Query, and that adds
	// an INNER JOIN on postmeta: every record without an air date silently
	// leaves the result. Korea lost 89 dramas that way and the page said 332
	// where the stat above it said 421. The date is read in PHP instead, which
	// this function was already doing for the decade filter anyway.
	$args = array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'ignore_sticky_posts'    => true,
		'update_post_term_cache' => false,
		'orderby'                => 'title',
		'order'                  => 'ASC',
		'tax_query'              => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	);

	$ids = get_posts( $args );

	/* ONE QUERY FOR THE META, NOT FOUR HUNDRED AND SIXTY FOUR
	   -------------------------------------------------------
	   MEASURED: /dramas/ took 1,478ms to render uncached while /quizzes/, the
	   same template over one record, took 465ms. The difference is not the
	   template. Asking for 'fields' => 'ids' tells WordPress not to fetch the
	   posts, and not fetching the posts means it never primes their meta, so
	   the first get_post_meta on each of the 464 records was its own round trip
	   to the database. The loop below reads a date off every one of them.

	   Priming it here is one query for the whole set, and every meta read after
	   this point, in the sort, in the filters and in all six facet counts, is
	   answered from memory. The terms are primed for the same reason: the genre
	   and network facets walk the same list asking each record what it is. */
	daw_browse_prime( $ids );

	// The air dates are read for two reasons only: the decade filter and any
	// sort that is not A to Z. A facet count needs neither, so on those calls
	// this loop does not run at all.
	$needs_dates = ! $unsorted && 'az' !== $filters['sort'];
	$needs_dates = $needs_dates || ! empty( $filters['decade'] );
	$dates       = array();

	if ( $needs_dates ) {
		foreach ( $ids as $id ) {
			$dates[ $id ] = (int) get_post_meta( $id, 'daw_aired_start', true );
		}
	}

	// The filters that live in meta rather than in a taxonomy, plus the page's
	// own restriction. Values inside one filter are an or; the filters are an
	// and, which is the arrangement the sidebar promises.
	$meta_keys = array( 'decade', 'status', 'role', 'kind', 'gender', 'dish', 'story', 'eps', 'runtime', 'height', 'born' );
	$has_meta  = (bool) $filters['require_roles'];

	foreach ( $meta_keys as $k ) {
		if ( ! empty( $filters[ $k ] ) ) {
			$has_meta = true;
			break;
		}
	}

	if ( $has_meta ) {
		$ids = array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $filters, $dates ) {
					if ( $filters['status'] && ! in_array( (string) get_post_meta( $id, 'daw_status', true ), $filters['status'], true ) ) {
						return false;
					}

					if ( $filters['kind'] && ! in_array( daw_browse_kind_of( $id ), $filters['kind'], true ) ) {
						return false;
					}

					if ( $filters['gender'] && ! in_array( (string) get_post_meta( $id, 'daw_gender', true ), $filters['gender'], true ) ) {
						return false;
					}

					if ( $filters['dish'] && ! in_array( (string) get_post_meta( $id, 'daw_food_kind', true ), $filters['dish'], true ) ) {
						return false;
					}

					if ( $filters['story'] && ! in_array( daw_news_story_of( $id ), $filters['story'], true ) ) {
						return false;
					}

					// A page may carry both groups and people. The people it
					// carries are the ones whose first listed job is the one
					// the page is about: "Actress and singer" is an actor.
					//
					// Except where a role is unambiguous on its own. Idol is:
					// nobody is described as an idol in passing, and a page
					// about K-pop that leaves out the idols who also act is
					// leaving out IU, Jimin and most of the reason anyone
					// opened it. So an unambiguous role counts wherever it
					// appears in the list, and everything else has to be the
					// first thing the record says they do.
					if ( $filters['require_roles'] && 'solo' === daw_browse_kind_of( $id ) ) {
						// A PERSON CAN HOLD TWO JOBS, AND A JOB TITLE CANNOT SAY WHICH
						// ------------------------------------------------------------
						// Reading the occupation gets most of this right and two
						// kinds of record wrong, in opposite directions. Xiao Zhan
						// and Dylan Wang are actors first and recording artists
						// second, so the rule above leaves them off a music page
						// they belong on. Andy Lau and Tony Leung have sung, which
						// is not the same as being an act, so a looser rule puts
						// them on it.
						//
						// Neither is a guess anybody should be making from a string.
						// So a page may name a field that an editor fills in, and
						// when it has been filled in it decides. Left alone it says
						// nothing and the job title still answers, which is why
						// nothing had to be filled in for the other nine hundred
						// records before this shipped.
						$say = $filters['require_flag']
							? (string) get_post_meta( $id, $filters['require_flag'], true )
							: '';

						if ( 'no' === $say ) {
							return false;
						}

						if ( 'yes' !== $say ) {
							$any = array_intersect(
								(array) ( $filters['require_roles_any'] ?? array() ),
								daw_person_roles( $id )
							);

							if ( ! $any && ! in_array( daw_person_primary_role( $id ), $filters['require_roles'], true ) ) {
								return false;
							}
						}
					}

					if ( $filters['role'] && ! array_intersect( $filters['role'], daw_person_roles( $id ) ) ) {
						return false;
					}

					if ( $filters['eps'] && ! in_array( daw_browse_band_of( $id, 'episodes' ), $filters['eps'], true ) ) {
						return false;
					}

					if ( $filters['runtime'] && ! in_array( daw_browse_band_of( $id, 'runtime' ), $filters['runtime'], true ) ) {
						return false;
					}

					if ( $filters['height'] && ! in_array( daw_browse_band_of( $id, 'height' ), $filters['height'], true ) ) {
						return false;
					}

					if ( $filters['born'] && ! daw_browse_year_in( (int) get_post_meta( $id, 'daw_born', true ), $filters['born'] ) ) {
						return false;
					}

					if ( $filters['decade'] && ! daw_browse_year_in( (int) $dates[ $id ], $filters['decade'] ) ) {
						return false;
					}

					return true;
				}
			)
		);
	}

	// MOST WORK FIRST
	// ---------------
	// The closest thing to "most popular" that this site can say truthfully.
	// It does not know what anybody watched; it does know how much of a
	// person's work it holds, and a profile with forty credits is both more
	// complete and more likely to be the one somebody meant. Counting is not
	// cheap, which is why it happens inside the cached id list rather than on
	// every card.
	if ( ! $unsorted && 'credits' === $filters['sort'] && function_exists( 'daw_credit_count' ) ) {
		$credits = array();

		foreach ( $ids as $id ) {
			$credits[ $id ] = (int) daw_credit_count( $id );
		}

		usort(
			$ids,
			static function ( $a, $b ) use ( $credits ) {
				// Same number of credits: the one whose name comes first, so
				// the order is stable rather than whatever the database felt
				// like. The query already returned them alphabetically.
				return $credits[ $b ] <=> $credits[ $a ];
			}
		);

		set_transient( $key, $ids, 6 * HOUR_IN_SECONDS );

		return $ids;
	}

	// A to Z is the order the query already returned. Anything else is by air
	// date, with undated records at the end: an undated record is not the
	// newest thing here, and it is not the oldest either.
	if ( ! $unsorted && 'az' !== $filters['sort'] ) {
		$asc = ( 'old' === $filters['sort'] );

		usort(
			$ids,
			static function ( $a, $b ) use ( $dates, $asc ) {
				$da = $dates[ $a ];
				$db = $dates[ $b ];

				// Records that carry no air date at all are people and guides,
				// and "newest" for them means most recently added. The id is
				// exactly that, and it costs no query to read.
				if ( ! $da && ! $db ) {
					return $asc ? $a <=> $b : $b <=> $a;
				}

				if ( ! $da || ! $db ) {
					return ( $da ? 0 : 1 ) <=> ( $db ? 0 : 1 );
				}

				return $asc ? $da <=> $db : $db <=> $da;
			}
		);
	}

	set_transient( $key, $ids, 6 * HOUR_IN_SECONDS );

	return $ids;
}

/**
 * Push BL and GL records to the back of a list, keeping everyone else's
 * relative order (S10.2).
 *
 * WHY HERE AND NOT IN daw_browse_ids()
 * -------------------------------------
 * The rule is a home page rule, not a listing rule: /dramas/ and the Korea hub
 * still show a BL title wherever its air date puts it, and only the "Dramas,
 * newest first" home page strip pushes it back. So this stays a small helper
 * the home page calls on the id list it already has, rather than a change to
 * the shared, cached sort every browse page depends on.
 *
 * @param array $ids Post ids, already ordered.
 * @return array Same ids, BL/GL moved after everything else, order otherwise
 *               unchanged on both sides of the split.
 */
function daw_blgl_to_back( $ids ) {
	$front = array();
	$back  = array();

	foreach ( (array) $ids as $id ) {
		$genres = wp_get_post_terms( $id, 'dw_genre', array( 'fields' => 'slugs' ) );
		$genres = is_wp_error( $genres ) ? array() : $genres;

		if ( array_intersect( array( 'bl', 'gl' ), $genres ) ) {
			$back[] = $id;
		} else {
			$front[] = $id;
		}
	}

	return array_merge( $front, $back );
}

/**
 * Whether a timestamp falls inside any of the decades asked for.
 *
 * A record with no date is not in the 1990s and it is not in the 2010s either,
 * so it drops out rather than being counted in whichever one was checked first.
 */
function daw_browse_year_in( $timestamp, $decades ) {
	if ( ! $timestamp ) {
		return false;
	}

	$year = (int) gmdate( 'Y', $timestamp );

	foreach ( (array) $decades as $decade ) {
		$decade = (int) $decade;

		if ( $year >= $decade && $year <= $decade + 9 ) {
			return true;
		}
	}

	return false;
}

/**
 * The browse pages' own writing, with its numbers counted instead of typed.
 *
 * daw_hub_fill_counts() does this for the country sections. The browse pages
 * had no equivalent, so /dramas/ said "Four hundred and sixty four dramas"
 * under a header that read 481, and every genre and network figure in the text
 * had drifted the same way. Here the paragraph writes {total} for the whole
 * list and {facet:slug} for one filter option, for example {genre:romance} or
 * {episodes:mid}, and the number is the one the filter beside it shows. An
 * unknown token is left visible, as on the hubs, so a typo is seen and fixed.
 */
function daw_browse_fill_counts( $text, $cfg, $filters, $ids ) {
	if ( false === strpos( (string) $text, '{' ) ) {
		return $text;
	}

	$map = array( '{total}' => number_format_i18n( count( (array) $ids ) ) );

	if ( preg_match_all( '/\{([a-z]+):([a-z0-9-]+)\}/', $text, $m ) ) {
		$facets = daw_browse_facets( $cfg['types'], $filters, array_values( array_unique( $m[1] ) ) );

		foreach ( $m[0] as $i => $token ) {
			$n = null;

			foreach ( (array) ( $facets[ $m[1][ $i ] ] ?? array() ) as $row ) {
				if ( isset( $row['slug'] ) && (string) $row['slug'] === $m[2][ $i ] ) {
					$n = (int) $row['n'];
					break;
				}
			}

			if ( null !== $n ) {
				$map[ $token ] = number_format_i18n( $n );
			}
		}
	}

	return strtr( $text, $map );
}

/* =============================================================== facets === */

/**
 * What each filter would leave, counted from the records currently shown.
 *
 * A facet counts the result set as it stands with that one filter released, so
 * the numbers beside "Romance 186" are what clicking it actually gives you.
 * Counting the fully filtered set instead is the classic facet bug: every
 * option reads 0 except the one already chosen.
 */
function daw_browse_facets( $types, $filters, $facets ) {
	$key = 'daw_browsef_' . md5( wp_json_encode( array( daw_browse_gen(), $types, $filters, $facets ) ) );

	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$out = array();

	foreach ( $facets as $facet ) {
		$without = $filters;
		$key     = array(
			'episodes' => 'eps',
			'runtime'  => 'runtime',
			'height'   => 'height',
		)[ $facet ] ?? $facet;

		if ( isset( $without[ $key ] ) ) {
			$without[ $key ] = array();
		}

		// COUNTING DOES NOT NEED AN ORDER
		// -------------------------------
		// Every facet asks for the same records with one filter released, and
		// then counts them. Sorting four hundred and sixty four records six
		// times over, by a date read off each one, to produce six numbers that
		// do not depend on the order, is the largest single piece of wasted
		// work on a browse page.
		$without['unsorted'] = true;

		$ids = daw_browse_ids( $types, $without );

		if ( 'decade' === $facet ) {
			$out[ $facet ] = daw_browse_count_decades( $ids );
			continue;
		}

		if ( 'status' === $facet ) {
			$out[ $facet ] = daw_browse_count_status( $ids );
			continue;
		}

		if ( 'role' === $facet ) {
			$out[ $facet ] = daw_browse_count_roles( $ids );
			continue;
		}

		if ( 'kind' === $facet ) {
			$out[ $facet ] = daw_browse_count_kinds( $ids );
			continue;
		}

		if ( 'gender' === $facet ) {
			$out[ $facet ] = daw_browse_count_genders( $ids );
			continue;
		}

		if ( 'dish' === $facet ) {
			$out[ $facet ] = daw_browse_count_dishes( $ids );
			continue;
		}

		if ( 'story' === $facet ) {
			$out[ $facet ] = daw_browse_count_stories( $ids );
			continue;
		}

		if ( 'born' === $facet ) {
			$out[ $facet ] = daw_browse_count_born( $ids );
			continue;
		}

		if ( in_array( $facet, array( 'episodes', 'runtime', 'height' ), true ) ) {
			$out[ $facet ] = daw_browse_count_bands( $ids, $facet );
			continue;
		}

		$taxonomy      = array(
			'country' => 'dw_country',
			'genre'   => 'dw_genre',
			'network' => 'dw_network',
		)[ $facet ] ?? '';
		$out[ $facet ] = $taxonomy ? daw_browse_count_terms( $ids, $taxonomy ) : array();
	}

	set_transient( $key, $out, 6 * HOUR_IN_SECONDS );

	return $out;
}

/** Tally one taxonomy over a set of records. */
function daw_browse_count_terms( $ids, $taxonomy ) {
	$tally = array();

	foreach ( $ids as $id ) {
		$terms = get_the_terms( $id, $taxonomy );

		if ( ! $terms || is_wp_error( $terms ) ) {
			continue;
		}

		foreach ( $terms as $t ) {
			if ( ! isset( $tally[ $t->slug ] ) ) {
				$tally[ $t->slug ] = array(
					'slug' => $t->slug,
					'name' => $t->name,
					'n'    => 0,
				);
			}

			++$tally[ $t->slug ]['n'];
		}
	}

	uasort(
		$tally,
		static function ( $a, $b ) {
			return $b['n'] <=> $a['n'] ?: strcmp( $a['name'], $b['name'] );
		}
	);

	return array_values( $tally );
}

/** Tally decades off the air date. */
function daw_browse_count_decades( $ids ) {
	$tally = array();

	foreach ( $ids as $id ) {
		$start = (int) get_post_meta( $id, 'daw_aired_start', true );

		if ( ! $start ) {
			continue;
		}

		$decade = ( (int) gmdate( 'Y', $start ) ) - ( (int) gmdate( 'Y', $start ) % 10 );

		if ( ! isset( $tally[ $decade ] ) ) {
			$tally[ $decade ] = array(
				'slug' => (string) $decade,
				/* translators: %s: decade, e.g. 2020 */
				'name' => sprintf( __( '%ss', 'daw' ), $decade ),
				'n'    => 0,
			);
		}

		++$tally[ $decade ]['n'];
	}

	krsort( $tally );

	return array_values( $tally );
}

/**
 * What a person does, as a short list of roles.
 *
 * WHY THIS IS DERIVED AND NOT A FIELD
 * -----------------------------------
 * The occupation on a record is free text, written the way a source wrote it:
 * "Actor", "Actress", "Actor, model and singer", "Idol (ASTRO)". A filter
 * cannot use that, and asking an editor to also pick from a list would mean the
 * same fact typed twice and disagreeing within a month.
 *
 * So the roles are read out of the sentence. A person can hold several, because
 * people do: an idol who acts is filed under both and appears under both
 * filters, which is the honest answer to "show me idols".
 *
 * GENDER IS NOT A ROLE
 * --------------------
 * "Actress" files under Actor, because a reader looking for who was in a drama
 * does not want half the cast. Gender is a separate field and a separate
 * filter, which is the only shape that lets one person be both an actor and a
 * woman without the archive having to hold them twice.
 *
 * /korean-actress/ still exists as an address, because it has ranked for years
 * and pointing it at nothing would be throwing readers away to make a point.
 * It is a preset state of the people list rather than a second list, so it can
 * never disagree with /korean-actors/ about who is in the archive.
 */
function daw_person_role_labels() {
	return array(
		'actor'    => __( 'Actor', 'daw' ),
		'idol'     => __( 'Idol', 'daw' ),
		'singer'   => __( 'Singer', 'daw' ),
		'model'    => __( 'Model', 'daw' ),
		'host'     => __( 'Host and presenter', 'daw' ),
		'comedian' => __( 'Comedian', 'daw' ),
		'voice'    => __( 'Voice actor', 'daw' ),
		'dancer'   => __( 'Dancer', 'daw' ),
		'director' => __( 'Director', 'daw' ),
		'writer'   => __( 'Writer', 'daw' ),
		'producer' => __( 'Producer', 'daw' ),
	);
}

/** The words that put a person in each bucket, longest match first. */
function daw_person_role_words() {
	return array(
		'voice'    => array( 'voice actor', 'voice actress', 'seiyuu' ),
		'actor'    => array( 'actor', 'actress' ),
		'idol'     => array( 'idol', 'k-pop', 'kpop' ),
		'singer'   => array( 'singer', 'vocalist', 'rapper', 'musician', 'songwriter' ),
		'model'    => array( 'model' ),
		'host'     => array( 'host', 'presenter', 'mc', 'tv personality' ),
		'comedian' => array( 'comedian', 'comedienne' ),
		'dancer'   => array( 'dancer', 'choreographer' ),
		'director' => array( 'director', 'filmmaker' ),
		'writer'   => array( 'writer', 'screenwriter', 'novelist', 'author' ),
		'producer' => array( 'producer' ),
	);
}

/** The roles one person holds, read off the occupation they were given. */
function daw_person_roles( $post_id ) {
	static $cache = array();

	$post_id = (int) $post_id;

	if ( isset( $cache[ $post_id ] ) ) {
		return $cache[ $post_id ];
	}

	$text = strtolower( (string) get_post_meta( $post_id, 'daw_occupation', true ) );
	$text = str_replace( array( '&amp;', '/', '·' ), array( 'and', ' ', ' ' ), $text );
	$out  = array();

	if ( '' !== trim( $text ) ) {
		foreach ( daw_person_role_words() as $role => $words ) {
			foreach ( $words as $word ) {
				// "mc" must not match inside "romcom", so short words are
				// matched on their own boundaries.
				$pattern = strlen( $word ) <= 3
					? '/\b' . preg_quote( $word, '/' ) . '\b/'
					: '/' . preg_quote( $word, '/' ) . '/';

				if ( preg_match( $pattern, $text ) ) {
					$out[] = $role;
					break;
				}
			}
		}
	}

	// A voice actor is an actor too, but "actor" alone should not claim them.
	$out = array_values( array_unique( $out ) );

	$cache[ $post_id ] = $out;

	return $out;
}

/**
 * The banded filters: episode count, running time, height.
 *
 * WHY BANDS AND NOT NUMBERS
 * -------------------------
 * Nobody browses for a 47 minute film. They browse for a short one. A number
 * field makes a hundred filters that each match one record; a band makes three
 * that each mean something, and the boundaries are the ones the subject itself
 * uses: a Korean drama is 16 episodes or it is a daily, a film is under or over
 * two hours, and the height question people actually ask is who is tall.
 *
 * Each band is [ label, min, max ], max null meaning no ceiling.
 */
/**
 * The first job a person's own record lists, as a role slug.
 *
 * daw_person_roles() returns every role in the free-text occupation, which is
 * what the People page needs: a reader looking for models wants everyone who
 * models. A page that is about one kind of work needs the other question, which
 * is what this person mainly is, and the record already answers it by the order
 * it was written in.
 */
function daw_person_primary_role( $post_id ) {
	$raw = strtolower( wp_strip_all_tags( (string) get_post_meta( $post_id, 'daw_occupation', true ) ) );

	if ( '' === $raw ) {
		return '';
	}

	$best  = '';
	$where = PHP_INT_MAX;

	foreach ( daw_person_role_words() as $role => $words ) {
		foreach ( (array) $words as $word ) {
			$at = strpos( $raw, $word );

			if ( false !== $at && $at < $where ) {
				$where = $at;
				$best  = $role;
			}
		}
	}

	return $best;
}

function daw_browse_bands( $which ) {
	$bands = array(
		'episodes' => array(
			'short' => array( __( 'Up to 8 episodes', 'daw' ), 1, 8 ),
			'mid'   => array( __( '9 to 16 episodes', 'daw' ), 9, 16 ),
			'long'  => array( __( '17 to 50 episodes', 'daw' ), 17, 50 ),
			'daily' => array( __( 'Over 50 episodes', 'daw' ), 51, null ),
		),
		'runtime'  => array(
			'short' => array( __( 'Under 90 minutes', 'daw' ), 1, 89 ),
			'mid'   => array( __( '90 to 120 minutes', 'daw' ), 90, 120 ),
			'long'  => array( __( 'Over two hours', 'daw' ), 121, null ),
		),
		'height'   => array(
			'under170' => array( __( 'Under 170 cm', 'daw' ), 100, 169 ),
			'170s'     => array( __( '170 to 179 cm', 'daw' ), 170, 179 ),
			'180plus'  => array( __( '180 cm and over', 'daw' ), 180, 260 ),
		),
	);

	return $bands[ $which ] ?? array();
}

/** The meta field each banded filter reads. */
function daw_browse_band_field( $which ) {
	return array(
		'episodes' => 'daw_episodes',
		'runtime'  => 'daw_duration_min',
		'height'   => 'daw_height_cm',
	)[ $which ] ?? '';
}

/** Which band one record falls in, or '' when the field is empty. */
function daw_browse_band_of( $post_id, $which ) {
	$field = daw_browse_band_field( $which );

	if ( ! $field ) {
		return '';
	}

	$value = (int) get_post_meta( $post_id, $field, true );

	if ( $value <= 0 ) {
		return '';
	}

	foreach ( daw_browse_bands( $which ) as $slug => $band ) {
		if ( $value >= $band[1] && ( null === $band[2] || $value <= $band[2] ) ) {
			return $slug;
		}
	}

	return '';
}

/** Tally one banded filter over a set of records. */
function daw_browse_count_bands( $ids, $which ) {
	$bands = daw_browse_bands( $which );
	$tally = array();

	foreach ( $ids as $id ) {
		$band = daw_browse_band_of( $id, $which );

		if ( ! $band ) {
			continue;
		}

		if ( ! isset( $tally[ $band ] ) ) {
			$tally[ $band ] = array(
				'slug' => $band,
				'name' => $bands[ $band ][0],
				'n'    => 0,
			);
		}

		++$tally[ $band ]['n'];
	}

	// Band order, not count order: "under 170" above "180 and over" is a scale,
	// and a scale that jumps around is harder to read than a long list.
	$order = array_keys( $bands );

	uasort(
		$tally,
		static function ( $a, $b ) use ( $order ) {
			return array_search( $a['slug'], $order, true ) <=> array_search( $b['slug'], $order, true );
		}
	);

	return array_values( $tally );
}

/** Tally the decade people were born in. */
function daw_browse_count_born( $ids ) {
	$tally = array();

	foreach ( $ids as $id ) {
		$born = (int) get_post_meta( $id, 'daw_born', true );

		if ( ! $born ) {
			continue;
		}

		$year   = (int) gmdate( 'Y', $born );
		$decade = $year - ( $year % 10 );

		if ( $decade < 1900 ) {
			continue;
		}

		if ( ! isset( $tally[ $decade ] ) ) {
			$tally[ $decade ] = array(
				'slug' => (string) $decade,
				/* translators: %s: decade, e.g. 1990 */
				'name' => sprintf( __( 'Born in the %ss', 'daw' ), $decade ),
				'n'    => 0,
			);
		}

		++$tally[ $decade ]['n'];
	}

	krsort( $tally );

	return array_values( $tally );
}

/** Tally roles over a set of people. */
function daw_browse_count_roles( $ids ) {
	$labels = daw_person_role_labels();
	$tally  = array();

	foreach ( $ids as $id ) {
		foreach ( daw_person_roles( $id ) as $role ) {
			if ( ! isset( $tally[ $role ] ) ) {
				$tally[ $role ] = array(
					'slug' => $role,
					'name' => $labels[ $role ],
					'n'    => 0,
				);
			}

			++$tally[ $role ]['n'];
		}
	}

	uasort(
		$tally,
		static function ( $a, $b ) {
			return $b['n'] <=> $a['n'];
		}
	);

	return array_values( $tally );
}

/** Tally airing status. */
/**
 * What kind of thing a food guide is about.
 *
 * Thirty six guides in one list is a menu with no sections: a reader who wants
 * something to drink scrolls past nine soups to find out there are four. The
 * value is stored on the record rather than guessed from the title, so a guide
 * that has not been sorted yet simply does not appear under any of these rather
 * than appearing under the wrong one.
 */
function daw_food_kind_labels() {
	return array(
		'drink'  => __( 'Drinks', 'daw' ),
		'main'   => __( 'Main dishes', 'daw' ),
		'soup'   => __( 'Soups and hot pots', 'daw' ),
		'side'   => __( 'Sides and banchan', 'daw' ),
		'street' => __( 'Street food and snacks', 'daw' ),
		'sweet'  => __( 'Sweets and desserts', 'daw' ),
		'pantry' => __( 'Pastes and pantry', 'daw' ),
	);
}

/**
 * The five kinds of news story, and the kickers that fall under each.
 *
 * WHY THE FILTER IS NOT THE KICKER
 * --------------------------------
 * Every story already carries a kicker, written for that story: Premiere,
 * Charts, Not confirmed, Touring. Thirty five stories between them use twenty
 * one different words, which is right above the headline and useless as a
 * filter, because a filter with twenty one options holding one story each is
 * a list of the stories with extra steps.
 *
 * So the kicker stays exactly as written, and the filter groups it. A reader
 * who wants to know what is coming does not care whether the record said
 * Upcoming, Confirmed or Casting. A kicker that matches nothing here simply
 * does not appear in the filter, which is the safe failure: the story is still
 * on the page, still carries its own word, and nothing is mislabelled.
 */
function daw_news_story_labels() {
	return array(
		'upcoming' => __( 'What is coming', 'daw' ),
		'airing'   => __( 'Out now', 'daw' ),
		'honours'  => __( 'Awards and numbers', 'daw' ),
		'offscreen' => __( 'Off screen', 'daw' ),
		'memoriam' => __( 'In memoriam', 'daw' ),
	);
}

/** Which kicker belongs to which group. */
function daw_news_story_map() {
	return array(
		'upcoming'      => 'upcoming',
		'confirmed'     => 'upcoming',
		'casting'       => 'upcoming',
		'in production' => 'upcoming',
		'filming'       => 'upcoming',
		'airing'        => 'airing',
		'streaming'     => 'airing',
		'premiere'      => 'airing',
		'release'       => 'airing',
		'released'      => 'airing',
		'television'    => 'airing',
		'awards'        => 'honours',
		'award'         => 'honours',
		'charts'        => 'honours',
		'ratings'       => 'honours',
		'sales'         => 'honours',
		'festival'      => 'honours',
		'box office'    => 'honours',
		'military'      => 'offscreen',
		'enlistment'    => 'offscreen',
		'legal record'  => 'offscreen',
		'politics'      => 'offscreen',
		'status'        => 'offscreen',
		'not confirmed' => 'offscreen',
		'anniversary'   => 'offscreen',
		'touring'       => 'offscreen',
		'obituary'      => 'memoriam',
		'in memoriam'   => 'memoriam',
	);
}

/** The group a record's kicker falls under, or an empty string. */
function daw_news_story_of( $post_id ) {
	$kicker = strtolower( trim( (string) get_post_meta( (int) $post_id, 'daw_news_kicker', true ) ) );

	if ( '' === $kicker ) {
		return '';
	}

	$map = daw_news_story_map();

	return $map[ $kicker ] ?? '';
}

/** Tally the story kind over a set of records. */
function daw_browse_count_stories( $ids ) {
	$tally = array();

	foreach ( $ids as $id ) {
		$value = daw_news_story_of( $id );

		if ( $value ) {
			$tally[ $value ] = ( $tally[ $value ] ?? 0 ) + 1;
		}
	}

	$out = array();

	foreach ( daw_news_story_labels() as $slug => $label ) {
		if ( ! empty( $tally[ $slug ] ) ) {
			$out[] = array(
				'slug' => $slug,
				'name' => $label,
				'n'    => (int) $tally[ $slug ],
			);
		}
	}

	return $out;
}

/** Tally the dish kind over a set of records. */
function daw_browse_count_dishes( $ids ) {
	$tally = array();

	foreach ( $ids as $id ) {
		$value = (string) get_post_meta( $id, 'daw_food_kind', true );

		if ( $value ) {
			$tally[ $value ] = ( $tally[ $value ] ?? 0 ) + 1;
		}
	}

	$out = array();

	foreach ( daw_food_kind_labels() as $slug => $label ) {
		if ( ! empty( $tally[ $slug ] ) ) {
			$out[] = array(
				'slug' => $slug,
				'name' => $label,
				'n'    => (int) $tally[ $slug ],
			);
		}
	}

	return $out;
}

/**
 * Man or woman, as a filter rather than as a job.
 *
 * WHY THIS IS NOT A ROLE
 * ----------------------
 * The site used to file women under "Actress", which made a woman's job a
 * different job from a man's doing the same work. That is fixed: everyone who
 * acts is an Actor. But a reader looking for the women in the archive still has
 * a real question, and it is answered here, in a filter of its own, where it
 * does not rewrite anybody's occupation.
 */
function daw_browse_gender_labels() {
	return array(
		'female' => __( 'Women', 'daw' ),
		'male'   => __( 'Men', 'daw' ),
		'other'  => __( 'Other', 'daw' ),
	);
}

/** Tally the gender field over a set of records. Records without it are not counted. */
function daw_browse_count_genders( $ids ) {
	$tally = array();

	foreach ( $ids as $id ) {
		$value = (string) get_post_meta( $id, 'daw_gender', true );

		if ( $value ) {
			$tally[ $value ] = ( $tally[ $value ] ?? 0 ) + 1;
		}
	}

	$out = array();

	foreach ( daw_browse_gender_labels() as $slug => $label ) {
		if ( ! empty( $tally[ $slug ] ) ) {
			$out[] = array(
				'slug' => $slug,
				'name' => $label,
				'n'    => (int) $tally[ $slug ],
			);
		}
	}

	return $out;
}

/** Group or soloist, as the K-pop page offers it. */
function daw_browse_kind_labels() {
	return array(
		'group' => __( 'Groups', 'daw' ),
		'solo'  => __( 'Soloists', 'daw' ),
	);
}

/** Which of the two a record is, read from its own type. */
function daw_browse_kind_of( $post_id ) {
	$terms = get_the_terms( $post_id, 'dw_type' );

	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}

	foreach ( $terms as $term ) {
		if ( 'group' === $term->slug ) {
			return 'group';
		}

		if ( 'person' === $term->slug ) {
			return 'solo';
		}
	}

	return '';
}

/** Tally groups against soloists over a set of records. */
function daw_browse_count_kinds( $ids ) {
	$tally = array();

	foreach ( $ids as $id ) {
		$kind = daw_browse_kind_of( $id );

		if ( $kind ) {
			$tally[ $kind ] = ( $tally[ $kind ] ?? 0 ) + 1;
		}
	}

	$out    = array();
	$labels = daw_browse_kind_labels();

	foreach ( $labels as $slug => $label ) {
		if ( ! empty( $tally[ $slug ] ) ) {
			$out[] = array(
				'slug' => $slug,
				'name' => $label,
				'n'    => (int) $tally[ $slug ],
			);
		}
	}

	return $out;
}

function daw_browse_count_status( $ids ) {
	$labels = array(
		'ongoing'   => __( 'Airing now', 'daw' ),
		'upcoming'  => __( 'Upcoming', 'daw' ),
		'completed' => __( 'Completed', 'daw' ),
	);

	$tally = array();

	foreach ( $ids as $id ) {
		$status = (string) get_post_meta( $id, 'daw_status', true );

		if ( ! isset( $labels[ $status ] ) ) {
			continue;
		}

		if ( ! isset( $tally[ $status ] ) ) {
			$tally[ $status ] = array(
				'slug' => $status,
				'name' => $labels[ $status ],
				'n'    => 0,
			);
		}

		++$tally[ $status ]['n'];
	}

	$order = array_keys( $labels );

	uasort(
		$tally,
		static function ( $a, $b ) use ( $order ) {
			return array_search( $a['slug'], $order, true ) <=> array_search( $b['slug'], $order, true );
		}
	);

	return array_values( $tally );
}

/**
 * The page that already answers "this country's dramas", if there is one.
 *
 * The country facet links there rather than filtering in place, so one question
 * has one url and the two pages never compete with each other.
 */
function daw_browse_country_page( $country_slug, $type ) {
	if ( ! function_exists( 'daw_country_hubs' ) ) {
		return '';
	}

	foreach ( daw_country_hubs() as $hub ) {
		if ( ( $hub['country'] ?? '' ) !== $country_slug ) {
			continue;
		}

		$slug = $hub['sections'][ $type ] ?? '';

		if ( ! $slug ) {
			return '';
		}

		$page = get_page_by_path( $slug );

		return $page ? (string) get_permalink( $page ) : '';
	}

	return '';
}

/**
 * The global browse page that holds one record type, as a url.
 *
 * The country section pages link back to it: a reader who has just read every
 * Korean food guide is one click from every food guide there is, which is the
 * one link the page was missing.
 */
function daw_browse_page_url_for_type( $type ) {
	foreach ( daw_browse_pages() as $slug => $cfg ) {
		if ( array( $type ) === array_values( (array) $cfg['types'] ) ) {
			$page = get_page_by_path( $slug );

			return $page ? (string) get_permalink( $page ) : '';
		}
	}

	return '';
}

/* ================================================================== seo === */

add_filter( 'daw_seo_robots', 'daw_browse_robots' );
add_filter( 'daw_seo_title', 'daw_browse_state_title' );
add_filter( 'daw_seo_description', 'daw_browse_state_description' );

/**
 * A kept filtered state says what it is in the tab and in the result.
 *
 * Without this, /dramas/?genre=romance and /dramas/ carry the same title, and
 * two pages with one title is one page as far as a search result is concerned.
 * The heading the page already prints is the honest answer, so it is reused
 * rather than written twice.
 */
function daw_browse_state_title( $title ) {
	$page = daw_browse_seo_page();

	if ( ! $page ) {
		return $title;
	}

	$filters = daw_browse_request();

	// Page two of a list is its own indexable page now, so it needs its own
	// title: five pages called "Korean Drama" are one title five times.
	if ( daw_browse_is_only_paged( $filters, $page['fixed'] ) ) {
		$base_title = html_entity_decode( wp_strip_all_tags( get_the_title( get_queried_object_id() ) ), ENT_QUOTES, 'UTF-8' );

		/* translators: 1: page title, 2: page number */
		return sprintf( __( '%1$s, page %2$s', 'daw' ), $base_title, number_format_i18n( (int) $filters['pg'] ) );
	}

	if ( ! empty( $page['fixed'] ) || ! daw_browse_state_is_indexable( $filters ) ) {
		return $title;
	}

	// The heading alone. The site name is not typed into a title (rule 6.3);
	// the records do not carry it either, so the kept states match them.
	return daw_browse_heading( $page['cfg'], $filters );
}

function daw_browse_state_description( $description ) {
	$page = daw_browse_seo_page();

	if ( ! $page || ! empty( $page['fixed'] ) ) {
		return $description;
	}

	$cfg     = $page['cfg'];
	$filters = daw_browse_request();

	if ( ! daw_browse_state_is_indexable( $filters ) ) {
		return $description;
	}

	$filters['require_roles']     = isset( $cfg['roles'] ) ? (array) $cfg['roles'] : array();
	$filters['require_roles_any'] = isset( $cfg['roles_any'] ) ? (array) $cfg['roles_any'] : array();
	$filters['require_flag']      = isset( $cfg['flag'] ) ? (string) $cfg['flag'] : '';
	$count                        = count( daw_browse_ids( $cfg['types'], $filters ) );
	$heading                      = daw_browse_heading( $cfg, $filters );
	$types                        = (array) $cfg['types'];

	// WHAT EACH LIST ACTUALLY HOLDS
	// -----------------------------
	// The one sentence used to promise "cast, episodes and where to watch" on
	// every list, people and guides included. Each kind now promises what its
	// own record page prints, and nothing it does not.
	if ( array( 'drama' ) === $types ) {
		$holds = __( 'each with its cast, episode count and where to watch it', 'daw' );
	} elseif ( array( 'film' ) === $types ) {
		$holds = __( 'each with its cast, story and where to watch it', 'daw' );
	} elseif ( array_intersect( $types, array( 'person', 'group' ) ) ) {
		$holds = __( 'each with a full profile and every credit on file', 'daw' );
	} else {
		$holds = __( 'each with its own page in the archive', 'daw' );
	}

	/* translators: 1: how many records, 2: page heading in lower case, 3: what each record page holds */
	$text = sprintf(
		__( '%1$s %2$s in the archive, %3$s. Sort them by newest or by name, and narrow the list further.', 'daw' ),
		number_format_i18n( $count ),
		mb_strtolower( $heading ),
		$holds
	);

	// 160 is the ceiling (rule 6.2). The last sentence is the part to lose.
	if ( mb_strlen( $text ) > 160 ) {
		$text = preg_replace( '/\s+Sort them[^.]*\.$/u', '', $text );
	}

	return $text;
}

/**
 * Which list page this request is, for the robots and canonical rules below.
 *
 * SECTION PAGES WERE OUTSIDE THE RULES
 * ------------------------------------
 * The robots and canonical filters only asked daw_browse_for_page(), which
 * knows the global browse pages (/dramas/, /films/). The country sections
 * (/korean-drama/, /korean-movie/, /korean-actors/) draw the same list with
 * the same fourteen filters and were never asked: every combination of
 * ?genre, ?decade, ?status and ?pg there was left indexable, with Rank Math's
 * canonical as the only defence. That is an unbounded set of near-duplicate
 * urls on the pages with the most search demand on the site.
 *
 * @return array{cfg: array, fixed: array, base: string}|null
 */
function daw_browse_seo_page() {
	if ( ! is_page() ) {
		return null;
	}

	$page_id = (int) get_queried_object_id();
	$cfg     = daw_browse_for_page( $page_id );
	$fixed   = $cfg ? daw_browse_fixed( $cfg ) : array();

	if ( ! $cfg && function_exists( 'daw_browse_schema_cfg' ) ) {
		$found = daw_browse_schema_cfg( $page_id );

		if ( $found ) {
			$cfg   = $found['cfg'];
			$fixed = daw_browse_fixed_arg( $found['lock'] );
		}
	}

	return $cfg
		? array(
			'cfg'   => $cfg,
			'fixed' => $fixed,
			'base'  => (string) get_permalink( $page_id ),
		)
		: null;
}

/**
 * Page two onwards of an unfiltered list.
 *
 * Google's own guidance for paginated lists is that each page is indexable
 * and its own canonical. Pointing page five at page one, as every page here
 * used to, tells Google page five is a copy of page one, and the records that
 * only appear deep in the list lose the one listing that links to them.
 */
function daw_browse_is_only_paged( $filters, $fixed ) {
	return $filters['pg'] > 1 && 0 === daw_browse_count_active( $filters, $fixed );
}

/**
 * A filtered view is for the reader, not for the index.
 *
 * follow, not nofollow: the records linked from it are exactly the pages we do
 * want crawled, and they are reachable from here whether or not this particular
 * combination deserves a result of its own.
 */
function daw_browse_robots( $robots ) {
	$page = daw_browse_seo_page();

	if ( ! $page ) {
		return $robots;
	}

	$filters = daw_browse_request();
	$active  = daw_browse_count_active( $filters, $page['fixed'] );

	// ANY QUERY STRING IS NOINDEX (Umut's SEO spec, 8 Oct 2026, item 2)
	// The one-genre and one-platform states used to stay indexable. With 1,033
	// of 1,277 pages left out of Google's index, every genre, platform and
	// status combination was a near-copy of the list it narrows, spending the
	// crawl on lists instead of records. Now only the bare list and its
	// ?pg=N pages are offered; everything else, a lone ?sort included, is
	// noindex, follow with its canonical on the bare list. Not blocked in
	// robots.txt: Google has to fetch the page to see the noindex.
	$extra = array_diff( array_keys( (array) $_GET ), array( 'pg' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ( 0 === $active && ! $extra ) || ( daw_browse_is_only_paged( $filters, $page['fixed'] ) && ! $extra ) ) {
		return $robots;
	}

	$robots['index']  = 'noindex';
	$robots['follow'] = 'follow';

	return $robots;
}

/**
 * The three filtered states worth having a page of their own.
 *
 * THE QUESTION THIS ANSWERS
 * -------------------------
 * "Romance asian drama" is searched twelve thousand times a month and "asian
 * drama netflix" more than a hundred thousand. Both of those are a filter on
 * this page, and both of them were noindex, which meant the site held the
 * answer and told Google not to keep it. Meanwhile "actors born in the 1990s
 * who are 180cm" is nobody's search and would be four hundred near-identical
 * pages if it were indexed.
 *
 * So the line is drawn at one filter. A genre on its own, or a platform on its
 * own, on the first page, is a real page about a real thing a reader asked for.
 * Two filters together is a combination the reader built, and it stays out of
 * the index with its canonical pointing at the page it was built from.
 */
function daw_browse_state_is_indexable( $filters ) {
	if ( $filters['pg'] > 1 ) {
		return false;
	}

	// Everything that is never on its own worth a url of its own.
	foreach ( array( 'country', 'decade', 'born', 'status', 'role', 'kind', 'gender', 'dish', 'story', 'eps', 'runtime', 'height' ) as $key ) {
		if ( ! empty( $filters[ $key ] ) ) {
			return false;
		}
	}

	$genre   = count( (array) $filters['genre'] );
	$network = count( (array) $filters['network'] );

	// Exactly one value, in exactly one of the two. Two genres together is a
	// combination the reader built, not a subject the site is about.
	return ( 1 === $genre && 0 === $network ) || ( 0 === $genre && 1 === $network );
}

/**
 * The country page that answers a single-country filter, if there is one.
 *
 * /dramas/?country=south-korea and /korean-drama/ are the same list. The second
 * is the one with a real url, a title and a piece of writing under it, so the
 * first points at it and the site never competes with itself.
 */
function daw_browse_country_canonical( $cfg, $filters ) {
	if ( $filters['pg'] > 1 || 1 !== count( (array) $filters['country'] ) ) {
		return '';
	}

	foreach ( daw_browse_filter_keys() as $key ) {
		if ( 'country' !== $key && ! empty( $filters[ $key ] ) ) {
			return '';
		}
	}

	$types = (array) $cfg['types'];

	if ( 1 !== count( $types ) ) {
		return '';
	}

	return daw_browse_country_page( reset( $filters['country'] ), reset( $types ) );
}

add_filter( 'daw_seo_canonical', 'daw_browse_canonical' );

/*
 * A noindex filtered list still names the page it narrows. Without the tag
 * Google has only the noindex and no hint where the list's value belongs.
 */
add_filter( 'daw_seo_canonical_on_noindex', 'daw_browse_canonical_on_noindex' );
function daw_browse_canonical_on_noindex( $print ) {
	return $print || null !== daw_browse_seo_page();
}

/** Every filtered view points home to the page it narrows. */
function daw_browse_canonical( $canonical ) {
	$page = daw_browse_seo_page();

	if ( ! $page ) {
		return $canonical;
	}

	$filters = daw_browse_request();
	$base    = $page['base'];

	// Sort order is never part of a canonical. /dramas/?genre=romance&sort=az
	// is the same list as ?genre=romance in another order, and keeping the
	// sort made every kept state four indexable urls instead of one.
	$filters['sort'] = 'new';

	if ( daw_browse_is_only_paged( $filters, $page['fixed'] ) ) {
		return daw_browse_url( $base, array(), array( 'pg' => (int) $filters['pg'] ) );
	}

	if ( 0 === daw_browse_count_active( $filters, $page['fixed'] ) ) {
		return $base;
	}

	// No filtered state is kept any more (spec item 2, 8 Oct 2026): each one
	// points at the page it narrows, or at the country page that answers it.

	if ( empty( $page['fixed'] ) ) {
		$country_page = daw_browse_country_canonical( $page['cfg'], $filters );

		if ( $country_page ) {
			return $country_page;
		}
	}

	return $base;
}
