<?php
/**
 * Structured data.
 *
 * ONE GRAPH, WRITTEN BY US
 * ------------------------
 * Rank Math can emit schema for a post, but it only knows what a generic post
 * is: a headline, a date, an author. It does not know that this post is a
 * twenty-episode South Korean drama with a named cast playing named characters,
 * a broadcast window, a network and a trailer. Everything that could win a rich
 * result lives in fields Rank Math has never heard of, so the graph is ours and
 * Rank Math's is switched off on these pages. Two graphs describing the same
 * page differently is worse than one graph describing it well.
 *
 * WHAT IS CLAIMED HERE IS TRUE
 * ----------------------------
 * aggregateRating is emitted only when the record has at least
 * DAW_RATING_SCHEMA_FLOOR real votes, and it describes the votes this site
 * collected — not an average borrowed from somewhere else. A rating in schema
 * is a claim to Google that people rated this thing here. Fabricating it is how
 * a site gets its rich results removed permanently, and it would be a lie
 * besides.
 *
 * Every field is omitted when empty rather than filled with a placeholder. A
 * graph that says numberOfEpisodes: 0 is worse than one that stays quiet.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', 'daw_print_schema', 20 );
function daw_print_schema() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	$post_id = get_the_ID();
	$type    = daw_get_type( $post_id );
	if ( ! $type ) {
		return;
	}

	$graph = array_filter(
		array(
			daw_schema_publisher(),
			daw_schema_website(),
			daw_schema_breadcrumb( $post_id, $type ),
			daw_schema_entity( $post_id, $type ),
			daw_schema_faq( $post_id ),
			function_exists( 'daw_fos_schema_node' ) ? daw_fos_schema_node( $post_id ) : null,
		)
	);

	if ( ! $graph ) {
		return;
	}

	printf(
		"<script type=\"application/ld+json\" id=\"daw-schema\">%s</script>\n",
		wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array_values( $graph ),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		)
	);
}

/**
 * While Rank Math is active its JSON-LD is taken off records and browse
 * pages. Once it is off, seo-engine.php describes the remaining pages (front
 * page, plain pages, categories) with the same Organization and WebSite nodes.
 */
add_filter( 'rank_math/json_ld', 'daw_suppress_rank_math_schema', 99, 2 );
function daw_suppress_rank_math_schema( $data, $jsonld ) {
	if ( is_singular( 'post' ) && daw_get_type( get_the_ID() ) ) {
		return array();
	}

	// A LIST IS NOT AN ARTICLE SOMEBODY WROTE
	// ---------------------------------------
	// Every browse page was publishing two descriptions of itself: our own
	// CollectionPage with the items on it, and underneath it Rank Math's
	// Article with an author and a publication date. A page that claims to be
	// both is a page a search engine has to guess about, and the guess is not
	// ours to make. Where we describe the list ourselves, the article markup
	// comes off.
	if ( is_page() && function_exists( 'daw_browse_schema_cfg' ) && daw_browse_schema_cfg( get_the_ID() ) ) {
		return array();
	}

	return $data;
}

/**
 * A record's title as plain text, for JSON-LD.
 *
 * get_the_title() returns HTML: an apostrophe comes back as &#8217;, and
 * wp_json_encode() then writes those six characters into the data, so search
 * engines read the name "Bomi&#8217;s Room". The page prints the right glyph;
 * the structured data has to say the same name.
 */
function daw_schema_title( $post_id ) {
	return html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' );
}

/* ================================================================ pieces == */

function daw_schema_publisher() {
	$logo = function_exists( 'daw_logo_url' ) ? daw_logo_url() : '';

	return array_filter(
		array(
			'@type'  => 'Organization',
			'@id'    => home_url( '/#organization' ),
			'name'   => 'Discover Asian World',
			'url'    => home_url( '/' ),
			'logo'   => $logo ? array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			) : null,
			'sameAs' => array_values( array_filter( (array) apply_filters( 'daw_schema_same_as', array() ) ) ),
		)
	);
}

function daw_schema_website() {
	return array(
		'@type'     => 'WebSite',
		'@id'       => home_url( '/#website' ),
		'url'       => home_url( '/' ),
		'name'      => 'Discover Asian World',
		'publisher' => array( '@id' => home_url( '/#organization' ) ),
		// The search box only earns a place here because the site has one and
		// it works; declaring an action that 404s is a penalty waiting to land.
		'potentialAction' => array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => home_url( '/?s={search_term_string}' ),
			),
			'query-input' => 'required name=search_term_string',
		),
	);
}

/**
 * The trail to a record, as the reader sees it and as the schema states it.
 *
 * ONE TRAIL, TWO READERS
 * ----------------------
 * The templates printed Home > Country > Dramas > Title with the middle steps
 * as plain text, and the schema said Home > Country > Title with the country
 * step carrying no url. Google rejects a ListItem without "item" on every
 * step but the last, so every record with a country failed the breadcrumb
 * report, and the trail it did describe was not the trail on the page. Both
 * now read this one list, and a step that has no page to link to is left out
 * of both rather than printed as a dead word.
 *
 * Steps: Home, the country hub, the section that holds this kind of record
 * (the country's own section when there is one, the global browse page when
 * there is not), then the record. A news record ends on the first record it
 * is about, because a headline is a sentence and a trail is not; the schema
 * keeps the headline's own url for that last step.
 *
 * @return array<int, array{name: string, url: string}>
 */
function daw_record_trail( $post_id ) {
	static $memo = array();

	$post_id = (int) $post_id;

	if ( isset( $memo[ $post_id ] ) ) {
		return $memo[ $post_id ];
	}

	$type  = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
	$trail = array(
		array( 'name' => __( 'Home', 'daw' ), 'url' => home_url( '/' ) ),
	);

	$country = daw_schema_first_term( $post_id, 'dw_country' );
	$section = '';

	if ( $country && function_exists( 'daw_country_hubs' ) ) {
		foreach ( daw_country_hubs() as $hub_slug => $hub ) {
			if ( ( $hub['country'] ?? '' ) !== $country->slug ) {
				continue;
			}

			$hub_page = get_page_by_path( $hub_slug );

			if ( $hub_page && 'publish' === $hub_page->post_status ) {
				$trail[] = array( 'name' => (string) $hub['name'], 'url' => (string) get_permalink( $hub_page ) );
			}

			$section = function_exists( 'daw_browse_country_page' ) ? daw_browse_country_page( $country->slug, $type ) : '';
			break;
		}
	}

	if ( ! $section && $type && function_exists( 'daw_browse_page_url_for_type' ) ) {
		$section = daw_browse_page_url_for_type( $type );
	}

	if ( $section ) {
		$section_id = url_to_postid( $section );
		$label      = $section_id ? daw_schema_title( $section_id ) : '';

		if ( '' !== $label ) {
			$trail[] = array( 'name' => $label, 'url' => $section );
		}
	}

	$last = daw_schema_title( $post_id );

	// A news story: the fourth step is the record the story is set to be
	// about (daw_news_about), under its own name and url; the last step is
	// the headline. With none set, the trail ends on the headline (1.75.56).
	// It used to take the first record found in the text, which put "Hyun Bin"
	// in the trail of a Kim Eun Sook story.
	if ( 'news' === $type ) {
		$about = daw_news_about_record( $post_id );
		if ( $about ) {
			$trail[] = array( 'name' => daw_schema_title( $about ), 'url' => (string) get_permalink( $about ) );
		}
	}

	$trail[] = array( 'name' => $last, 'url' => (string) get_permalink( $post_id ) );

	$memo[ $post_id ] = $trail;

	return $trail;
}

/** The record a story is set to be about: daw_news_about, when it is set and published. 0 otherwise. */
function daw_news_about_record( $post_id ) {
	$id = (int) get_post_meta( (int) $post_id, 'daw_news_about', true );
	return ( $id && $id !== (int) $post_id && 'publish' === get_post_status( $id ) ) ? $id : 0;
}

/** The records a story names, in the order they first appear in its headline and text. */
function daw_news_mentions_in_order( $post_id ) {
	$ids = function_exists( 'daw_news_mentions_cached' ) ? array_map( 'intval', (array) daw_news_mentions_cached( $post_id ) ) : array();
	$post = get_post( $post_id );
	if ( count( $ids ) < 2 || ! $post ) {
		return $ids;
	}
	$text = strtolower( remove_accents( wp_specialchars_decode( $post->post_title . ' ' . wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), ENT_QUOTES ) ) );
	$text = str_replace( '-', ' ', $text );
	$pos  = array();
	foreach ( $ids as $n => $mid ) {
		$name = strtolower( remove_accents( wp_specialchars_decode( get_the_title( $mid ), ENT_QUOTES ) ) );
		$at   = '' === $name ? false : strpos( $text, str_replace( '-', ' ', $name ) );
		$pos[ $mid ] = array( false === $at ? PHP_INT_MAX : $at, $n );
	}
	usort( $ids, static fn( $a, $b ) => $pos[ $a ] <=> $pos[ $b ] );
	return $ids;
}

function daw_schema_breadcrumb( $post_id, $type ) {
	$list = array();

	foreach ( daw_record_trail( $post_id ) as $i => $item ) {
		$list[] = array(
			'@type'    => 'ListItem',
			'position' => $i + 1,
			'name'     => $item['name'],
			'item'     => $item['url'],
		);
	}

	return array(
		'@type'           => 'BreadcrumbList',
		'@id'             => get_permalink( $post_id ) . '#breadcrumb',
		'itemListElement' => $list,
	);
}

/**
 * The record itself. A drama is a TVSeries, a film is a Movie, a person is a
 * Person, a news record is a NewsArticle — the type of the thing, not the type
 * of the WordPress post it happens to live in.
 *
 * ADDING A KIND IS ONE LINE
 * -------------------------
 * The site will grow past drama and film. A variety show, a web drama, an
 * anime series, an anime film are all the same two shapes underneath: a thing
 * that runs in episodes, and a thing that runs once. Nothing about them needs
 * a new template or a new builder, only a row in this table, because the page
 * is drawn from the fields a record happens to hold and a record with no
 * episode count simply has no episode section.
 *
 * A manga is the exception and it is worth saying why: it has no cast, no
 * runtime and no air date, and it has volumes, chapters, a serialising
 * magazine and an artist. Those are different questions, so it gets its own
 * record shape and its own builder when it arrives, rather than being forced
 * through a screen-work template that would print empty rows.
 */
function daw_schema_screen_kinds() {
	return array(
		// Episodic. Anything that runs as a series.
		'drama'   => 'series',
		'variety' => 'series',
		'anime'   => 'series',
		// Single works.
		'film'    => 'movie',
	);
}

function daw_schema_entity( $post_id, $type ) {
	$screen = daw_schema_screen_kinds();

	// An anime film is a Movie, not a series: TMDB's record kind decides.
	if ( 'anime' === $type && function_exists( 'daw_tmdb_ref' ) ) {
		$ref = daw_tmdb_ref( $post_id );
		if ( $ref && 'movie' === $ref['kind'] ) {
			return daw_schema_movie( $post_id );
		}
	}

	if ( isset( $screen[ $type ] ) ) {
		return 'series' === $screen[ $type ]
			? daw_schema_series( $post_id )
			: daw_schema_movie( $post_id );
	}

	switch ( $type ) {
		case 'person':
			return daw_schema_person_record( $post_id );
		case 'group':
			return daw_schema_music_group( $post_id );
		case 'news':
			return daw_schema_news( $post_id );
	}

	if ( function_exists( 'daw_guide_types' ) && in_array( $type, daw_guide_types(), true ) ) {
		return daw_schema_guide( $post_id );
	}

	return daw_schema_article( $post_id );
}

function daw_schema_common( $post_id ) {
	$url     = get_permalink( $post_id );
	$aka     = array_values( array_filter( (array) daw_field_raw( $post_id, 'daw_also_known_as' ) ) );
	$native  = (string) daw_field_raw( $post_id, 'daw_native_title' );
	$synopsis = wp_strip_all_tags( (string) daw_field_raw( $post_id, 'daw_synopsis' ) );

	if ( $native ) {
		array_unshift( $aka, $native );
	}

	return array_filter(
		array(
			'@id'           => $url . '#record',
			'url'           => $url,
			'name'          => daw_schema_title( $post_id ),
			'alternateName' => $aka ? array_values( array_unique( $aka ) ) : null,
			'description'   => $synopsis ? wp_trim_words( $synopsis, 55, '…' ) : null,
			'image'         => daw_schema_image( $post_id ),
			'genre'         => daw_schema_term_names( $post_id, 'dw_genre' ),
			'inLanguage'    => daw_schema_language( $post_id ),
			'contentRating' => (string) daw_field_raw( $post_id, 'daw_content_rating' ),
			'dateModified'  => get_post_modified_time( 'c', true, $post_id ),
			'isPartOf'      => array( '@id' => home_url( '/#website' ) ),
			// No 'publisher': this site did not publish the drama or the film,
			// and naming itself as publisher of someone else's work is a false
			// statement in the graph.
			'sameAs'        => daw_schema_title_same_as( $post_id ),
		)
	);
}

/**
 * The same work elsewhere: TMDB from its id, IMDb and Wikipedia when the
 * record holds them. Reference databases only, the way Person does it.
 */
function daw_schema_title_same_as( $post_id ) {
	$out = array();
	$ref = function_exists( 'daw_tmdb_ref' ) ? daw_tmdb_ref( $post_id ) : array();
	if ( $ref && in_array( $ref['kind'], array( 'tv', 'movie' ), true ) && ( ! function_exists( 'daw_tmdb_img_path' ) || 'ok' === get_post_meta( $post_id, '_daw_tmdb_img_check', true ) ) ) {
		$out[] = 'https://www.themoviedb.org/' . $ref['kind'] . '/' . (int) $ref['id'] . ( empty( $ref['season'] ) ? '' : '/season/' . (int) $ref['season'] );
	}
	foreach ( array( 'daw_link_imdb', 'daw_link_wikipedia' ) as $k ) {
		$u = trim( (string) daw_field_raw( $post_id, $k ) );
		if ( '' !== $u && preg_match( '#^https://#', $u ) ) {
			$out[] = $u;
		}
	}
	return $out ? array_values( array_unique( $out ) ) : null;
}

function daw_schema_series( $post_id ) {
	$node = daw_schema_common( $post_id );

	$node = array( '@type' => 'TVSeries' ) + $node;

	$start = (int) daw_field_raw( $post_id, 'daw_aired_start' );
	$end   = (int) daw_field_raw( $post_id, 'daw_aired_end' );
	$eps   = (int) daw_field_raw( $post_id, 'daw_episodes' );
	$mins  = (int) daw_field_raw( $post_id, 'daw_duration_min' );

	if ( $start ) {
		$node['startDate'] = gmdate( 'Y-m-d', $start );
	}
	if ( $end ) {
		$node['endDate'] = gmdate( 'Y-m-d', $end );
	}
	if ( $eps ) {
		$node['numberOfEpisodes'] = $eps;
	}
	$seasons = (int) daw_field_raw( $post_id, 'daw_seasons' );
	$season  = function_exists( 'daw_season_no' ) ? daw_season_no( $post_id ) : 0;
	if ( $seasons && ! $season ) {
		$node['numberOfSeasons'] = $seasons;
	}
	// A season page (madde 65) is a TVSeason that belongs to its series record.
	if ( $season ) {
		$node['@type']        = 'TVSeason';
		$node['seasonNumber'] = $season;
		$parent               = (int) get_post_meta( $post_id, DAW_SEASON_PARENT, true );
		if ( $parent && 'publish' === get_post_status( $parent ) ) {
			$node['partOfSeries'] = array(
				'@type' => 'TVSeries',
				'name'  => html_entity_decode( daw_schema_title( $parent ), ENT_QUOTES, 'UTF-8' ),
				'url'   => get_permalink( $parent ),
			);
		}
	}
	if ( $mins ) {
		$node['timeRequired'] = 'PT' . $mins . 'M';
	}

	$node = daw_schema_add_makers( $node, $post_id );

	$node += daw_schema_credits( $post_id );
	$node  = daw_schema_add_country( $node, $post_id );
	$node  = daw_schema_add_rating( $node, $post_id );
	$node  = daw_schema_add_trailer( $node, $post_id );
	$node  = daw_schema_add_episodes( $node, $post_id );
	$node  = daw_schema_add_watch( $node, $post_id );
	$node  = daw_schema_add_awards( $node, $post_id );

	return $node;
}

/**
 * Wins only.
 *
 * A nomination is a fact worth printing in the table, but "award" in schema
 * means the thing was actually won, and listing nominations there would be a
 * claim we cannot support. Now that the recipient is a column of its own, each
 * line can name who won rather than describing a category in the abstract.
 */
function daw_schema_add_awards( $node, $post_id ) {
	if ( ! function_exists( 'daw_get_awards' ) ) {
		return $node;
	}

	$awards = array();

	foreach ( daw_get_awards( $post_id ) as $row ) {
		$result = isset( $row['result'] ) ? (string) $row['result'] : '';
		if ( false === stripos( $result, 'won' ) && false === stripos( $result, 'winner' ) ) {
			continue;
		}

		$parts = array_filter(
			array(
				isset( $row['year'] ) ? (string) $row['year'] : '',
				isset( $row['body'] ) ? (string) $row['body'] : '',
				isset( $row['category'] ) ? (string) $row['category'] : '',
			)
		);

		if ( ! $parts ) {
			continue;
		}

		$line = implode( ', ', $parts );

		if ( ! empty( $row['who'] ) ) {
			/* translators: 1: award line, 2: recipient name */
			$line = sprintf( __( '%1$s (%2$s)', 'daw' ), $line, $row['who'] );
		}

		$awards[] = $line;
	}

	if ( $awards ) {
		$node['award'] = $awards;
	}

	return $node;
}

/**
 * productionCompany is the company that made it (daw_production; for anime
 * also the studio), not the channel that aired it: "SBS" there was wrong for
 * Pinocchio, whose production company is iHQ.
 */
function daw_schema_add_makers( $node, $post_id ) {
	$makers = array_values( array_filter( array_map( 'trim', array_merge( (array) daw_field_raw( $post_id, 'daw_production' ), 'anime' === daw_get_type( $post_id ) ? (array) daw_field_raw( $post_id, 'daw_studio' ) : array() ) ) ) );
	if ( $makers ) {
		$node['productionCompany'] = array_map(
			static function ( $name ) {
				return array( '@type' => 'Organization', 'name' => wp_strip_all_tags( $name ) );
			},
			$makers
		);
	}
	return $node;
}

function daw_schema_movie( $post_id ) {
	$node = array( '@type' => 'Movie' ) + daw_schema_common( $post_id );

	$start = (int) daw_field_raw( $post_id, 'daw_aired_start' );
	$mins  = (int) daw_field_raw( $post_id, 'daw_duration_min' );

	if ( $start ) {
		$node['datePublished'] = gmdate( 'Y-m-d', $start );
	}
	if ( $mins ) {
		$node['duration'] = 'PT' . $mins . 'M';
	}

	$node  = daw_schema_add_makers( $node, $post_id );
	$node += daw_schema_credits( $post_id );
	$node  = daw_schema_add_country( $node, $post_id );
	$node  = daw_schema_add_rating( $node, $post_id );
	$node  = daw_schema_add_trailer( $node, $post_id );
	$node  = daw_schema_add_watch( $node, $post_id );
	$node  = daw_schema_add_awards( $node, $post_id );

	return $node;
}

/**
 * Cast, with the character each actor played.
 *
 * PerformanceRole is the reason this is worth doing properly: without it the
 * graph says "these ten people were in this", which every database says. With
 * it, the graph can answer "who played Choi In Ha", which is the question
 * people actually type.
 */
function daw_schema_credits( $post_id ) {
	$out   = array();
	$actor = array();
	$seen  = array();

	// THE UNTIERED BUCKET COUNTS
	// --------------------------
	// Most records still carry their cast in the flat bucket the Elementor
	// extraction wrote them into, because the old widget had no notion of
	// billing. Reading only the three tiered buckets meant the page listed
	// thirteen actors and the schema on the same page listed none: the search
	// engine was told these titles have no cast at all. The filmography index
	// learned this lesson already and reads all four.
	//
	// An untiered credit has no character name, so it goes in as a plain
	// Person. That is the honest shape: we know they were in it, we do not
	// know who they played.
	foreach ( array( 'main', 'supporting', 'guest', 'untiered' ) as $tier ) {
		foreach ( daw_get_cast( $post_id, $tier ) as $row ) {
			$name = daw_schema_title( $row['id'] );
			if ( ! $name ) {
				continue;
			}

			// A person tiered by hand and also left in the flat bucket would
			// otherwise be published twice, once with their role and once
			// without, which reads as two different people.
			if ( isset( $seen[ (int) $row['id'] ] ) ) {
				continue;
			}
			$seen[ (int) $row['id'] ] = true;

			$person = array(
				'@type' => 'Person',
				'name'  => $name,
				'url'   => get_permalink( $row['id'] ),
			);

			$actor[] = $row['role']
				? array(
					'@type'         => 'PerformanceRole',
					'characterName' => $row['role'],
					'actor'         => $person,
				)
				: $person;
		}
	}

	if ( $actor ) {
		$out['actor'] = $actor;
	}

	// Crew: a person with a record on the site is sent with its url, so the
	// graph ties the title to the director's own page.
	$crew_people = static function ( $names, $job ) use ( $post_id ) {
		$out = array();
		foreach ( array_values( array_filter( array_map( 'trim', (array) $names ) ) ) as $n ) {
			$p   = daw_schema_bare_person( $n );
			$url = daw_schema_crew_url( $post_id, $job, $n );
			if ( $url ) {
				$p['url'] = $url;
			}
			$out[] = $p;
		}
		return $out;
	};

	$directors = $crew_people( daw_field_raw( $post_id, 'daw_director' ), 'Director' );
	if ( $directors ) {
		$out['director'] = $directors;
	}

	$writers = $crew_people( daw_field_raw( $post_id, 'daw_screenwriter' ), 'Screenwriter' );
	if ( $writers ) {
		$out['author'] = $writers;
	}

	// Variety: the PD is the name people search.
	$producers = $crew_people( daw_field_raw( $post_id, 'daw_producer' ), 'Producer' );
	if ( $producers && 'variety' === daw_get_type( $post_id ) ) {
		$out['producer'] = $producers;
	}

	// An adaptation says what it is based on and who wrote that.
	$source = trim( (string) daw_field_raw( $post_id, 'daw_adaptation' ) );
	if ( '' !== $source ) {
		$parts = array_map( 'trim', explode( ',', $source, 2 ) );
		$based = array(
			'@type' => daw_schema_source_type( $parts[0] ),
			'name'  => $parts[1] ?? $parts[0],
		);
		$authors = $crew_people( daw_field_raw( $post_id, 'daw_original_author' ), 'Original author' );
		if ( $authors ) {
			$based['author'] = $authors;
		}
		$out['isBasedOn'] = $based;
	}

	return $out;
}

/** "Webtoon", "Novel", "Manga" to a schema.org type. */
function daw_schema_source_type( $word ) {
	$w = strtolower( (string) $word );
	if ( false !== strpos( $w, 'novel' ) || false !== strpos( $w, 'book' ) ) {
		return 'Book';
	}
	if ( false !== strpos( $w, 'webtoon' ) || false !== strpos( $w, 'manga' ) || false !== strpos( $w, 'manhwa' ) || false !== strpos( $w, 'comic' ) ) {
		return 'ComicSeries';
	}
	if ( false !== strpos( $w, 'film' ) || false !== strpos( $w, 'movie' ) ) {
		return 'Movie';
	}
	if ( false !== strpos( $w, 'drama' ) || false !== strpos( $w, 'series' ) ) {
		return 'TVSeries';
	}
	return 'CreativeWork';
}

/** The record url of a crew member, if the site has one. */
function daw_schema_crew_url( $post_id, $job, $name ) {
	if ( ! function_exists( 'daw_cov_person_post' ) ) {
		return '';
	}
	$norm = static function ( $s ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( (string) $s ) ) );
	};
	foreach ( (array) get_post_meta( $post_id, DAW_CREW, true ) as $c ) {
		if ( is_array( $c ) && ( $c['job'] ?? '' ) === $job && $norm( $c['name'] ?? '' ) === $norm( $name ) ) {
			$pid = daw_cov_person_post( (int) ( $c['id'] ?? 0 ) );
			return $pid ? (string) get_permalink( $pid ) : '';
		}
	}
	return '';
}

function daw_schema_bare_person( $name ) {
	return array( '@type' => 'Person', 'name' => (string) $name );
}

function daw_schema_add_country( $node, $post_id ) {
	$country = daw_schema_first_term( $post_id, 'dw_country' );
	if ( $country ) {
		$node['countryOfOrigin'] = array( '@type' => 'Country', 'name' => $country->name );
	}

	return $node;
}

function daw_schema_add_rating( $node, $post_id ) {
	if ( ! function_exists( 'daw_rating_is_publishable' ) || ! daw_rating_is_publishable( $post_id ) ) {
		return $node;
	}

	$rating = daw_rating( $post_id );

	$node['aggregateRating'] = array(
		'@type'       => 'AggregateRating',
		'ratingValue' => (string) $rating['avg'],
		'ratingCount' => (int) $rating['count'],
		'bestRating'  => '10',
		'worstRating' => '1',
	);

	return $node;
}

function daw_schema_add_trailer( $node, $post_id ) {
	$url = (string) daw_field_raw( $post_id, 'daw_trailer_youtube' );
	if ( ! $url || ! function_exists( 'daw_youtube_id' ) ) {
		return $node;
	}

	$vid = daw_youtube_id( $url );
	if ( ! $vid ) {
		return $node;
	}

	// GOOGLE REQUIRES uploadDate ON A VideoObject
	// -------------------------------------------
	// The node used to be printed whether or not the date was on file, and
	// array_filter quietly dropped the empty value, so every trailer without
	// a recorded date was an error in the Video report. No real date, no node:
	// the date is not something this site may guess (rule 1.1). A bare year
	// is not enough either; the property wants a day.
	$uploaded = daw_schema_date( (string) daw_field_raw( $post_id, 'daw_trailer_date' ) );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $uploaded ) ) {
		return $node;
	}

	$title = daw_schema_title( $post_id );
	$about = wp_trim_words( wp_strip_all_tags( (string) daw_field_raw( $post_id, 'daw_synopsis' ) ), 30, '…' );
	/* translators: %s: title */
	$name  = sprintf( __( 'Official trailer for %s', 'daw' ), $title );

	$node['trailer'] = array(
		'@type'        => 'VideoObject',
		'name'         => $name,
		'description'  => '' !== $about ? $about : $name,
		// hqdefault exists for every YouTube video; maxresdefault is only
		// generated for HD uploads and 404s for many older trailers.
		'thumbnailUrl' => 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg',
		'embedUrl'     => 'https://www.youtube-nocookie.com/embed/' . $vid,
		'uploadDate'   => $uploaded,
	);

	return $node;
}

/**
 * Episodes, as real TVEpisode nodes rather than a list of strings — an episode
 * with a number and a name is something a search engine can surface on its own.
 */
function daw_schema_add_episodes( $node, $post_id ) {
	if ( ! function_exists( 'daw_get_episodes' ) ) {
		return $node;
	}

	$rows = daw_get_episodes( $post_id );
	if ( ! $rows ) {
		return $node;
	}

	$episodes = array();
	foreach ( $rows as $row ) {
		$no = isset( $row['no'] ) ? (int) $row['no'] : 0;
		if ( ! $no ) {
			continue;
		}

		$episodes[] = array_filter(
			array(
				'@type'         => 'TVEpisode',
				'episodeNumber' => $no,
				'name'          => isset( $row['title'] ) ? (string) $row['title'] : '',
				'datePublished' => ! empty( $row['date'] ) ? daw_schema_date( $row['date'] ) : '',
				'description'   => ! empty( $row['synopsis'] ) ? wp_strip_all_tags( (string) $row['synopsis'] ) : '',
			)
		);
	}

	// The episode table has no season column. Only a record known to be one
	// season can wrap its episodes in "season 1"; any other record lists them
	// on the series itself and claims no season number it cannot show.
	if ( $episodes && 1 !== (int) daw_field_raw( $post_id, 'daw_seasons' ) ) {
		$node['episode'] = $episodes;
	} elseif ( $episodes ) {
		$node['containsSeason'] = array(
			array_filter(
				array(
					'@type'            => 'TVSeason',
					'seasonNumber'     => 1,
					'numberOfEpisodes' => (int) daw_field_raw( $post_id, 'daw_episodes' ),
					'episode'          => $episodes,
				)
			),
		);
	}

	return $node;
}

/**
 * Where it can legally be watched. These are the links already shown on the
 * page, so the graph is describing what a reader can see, not making a separate
 * claim — which is the only honest way to emit a WatchAction.
 */
function daw_schema_add_watch( $node, $post_id ) {
	if ( ! function_exists( 'daw_get_watch_links' ) ) {
		return $node;
	}

	$actions = array();
	foreach ( daw_get_watch_links( $post_id ) as $row ) {
		if ( empty( $row['url'] ) ) {
			continue;
		}

		$actions[] = array(
			'@type'  => 'WatchAction',
			'target' => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => $row['url'],
			),
			'name'   => $row['platform'],
		);
	}

	if ( $actions ) {
		$node['potentialAction'] = $actions;
	}

	return $node;
}

function daw_schema_faq( $post_id ) {
	// A person page keeps its questions in its own field set, so the graph has
	// to ask the right one rather than silently emitting nothing.
	$kind = daw_get_type( $post_id );

	if ( 'person' === $kind ) {
		$rows = function_exists( 'daw_get_person_faq' ) ? daw_get_person_faq( $post_id ) : array();
	} elseif ( 'group' === $kind ) {
		// A group keeps its questions in its own field set as well. This was
		// fixed for people and not for groups, so BTS published eight answered
		// questions on the page and told Google about none of them.
		$rows = function_exists( 'daw_get_group_faq' ) ? daw_get_group_faq( $post_id ) : array();
	} elseif ( function_exists( 'daw_get_faq' ) ) {
		$rows = daw_get_faq( $post_id );
	} else {
		return null;
	}

	// Guides written in the house shape keep their questions in the body, under
	// "Questions people ask", as bold questions each followed by its answer in
	// the same paragraph. The audit moved seventy-odd guides to that shape and
	// deleted the separate FAQ fields, which silently took every one of those
	// questions out of the structured data. So the body is read as well.
	if ( ! $rows && function_exists( 'daw_guide_types' ) && in_array( $kind, daw_guide_types(), true ) ) {
		$post = get_post( $post_id );
		$html = $post ? (string) $post->post_content : '';
		if ( preg_match_all( '#<p>\s*<strong>([^<]{5,200}\?)\s*</strong>\s*(.+?)</p>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$rows[] = array( 'q' => trim( $hit[1] ), 'a' => trim( wp_strip_all_tags( $hit[2] ) ) );
			}
		}
	}
	if ( ! $rows ) {
		return null;
	}

	$items = array();
	foreach ( $rows as $row ) {
		if ( empty( $row['q'] ) || empty( $row['a'] ) ) {
			continue;
		}

		$items[] = array(
			'@type'          => 'Question',
			'name'           => wp_strip_all_tags( $row['q'] ),
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => wp_strip_all_tags( $row['a'] ),
			),
		);
	}

	if ( ! $items ) {
		return null;
	}

	return array(
		'@type'      => 'FAQPage',
		'@id'        => get_permalink( $post_id ) . '#faq',
		'mainEntity' => $items,
	);
}

function daw_schema_person_record( $post_id ) {
	$url = get_permalink( $post_id );

	$works = daw_schema_person_works( $post_id );

	return array_filter(
		array(
			'@type'         => 'Person',
			'@id'           => $url . '#record',
			'url'           => $url,
			'name'          => daw_schema_title( $post_id ),
			'alternateName' => (string) daw_field_raw( $post_id, 'daw_native_name' ),
			'image'         => daw_schema_image( $post_id ),
			'birthDate'     => daw_schema_date( (string) daw_field_raw( $post_id, 'daw_born' ) ),
			'deathDate'     => daw_schema_date( (string) daw_field_raw( $post_id, 'daw_died' ) ),
			'birthPlace'    => (string) daw_field_raw( $post_id, 'daw_birth_place' ),
			'jobTitle'      => (string) daw_field_raw( $post_id, 'daw_occupation' ),
			'height'        => daw_field_raw( $post_id, 'daw_height_cm' )
				? array( '@type' => 'QuantitativeValue', 'value' => (int) daw_field_raw( $post_id, 'daw_height_cm' ), 'unitCode' => 'CMT' )
				: null,
			'nationality'   => (string) daw_field_raw( $post_id, 'daw_nationality' ),
			// schema.org takes a plain word here, and the field already stores
			// one. It is stated because it is a property of the person that the
			// page states in its own table, not because anything filters by it.
			'gender'        => daw_schema_gender( $post_id ),
			// The band, as the band's own record rather than as its name in
			// quotes, so the two entities are actually joined up.
			'memberOf'      => daw_schema_member_of( $post_id ),
			'affiliation'   => daw_field_raw( $post_id, 'daw_agency' )
				? array( '@type' => 'Organization', 'name' => (string) daw_field_raw( $post_id, 'daw_agency' ) )
				: null,
			'weight'        => daw_field_raw( $post_id, 'daw_weight_kg' )
				? array( '@type' => 'QuantitativeValue', 'value' => (int) daw_field_raw( $post_id, 'daw_weight_kg' ), 'unitCode' => 'KGM' )
				: null,
			'spouse'        => daw_schema_spouse( $post_id ),
			'description'   => daw_schema_person_description( $post_id ),
			'alumniOf'      => daw_field_raw( $post_id, 'daw_edu_university' )
				? array( '@type' => 'CollegeOrUniversity', 'name' => (string) daw_field_raw( $post_id, 'daw_edu_university' ) )
				: null,
			'sameAs'        => daw_schema_person_same_as( $post_id ),
			// THE AWARDS WERE RECORDED AND NEVER STATED
			// -----------------------------------------
			// The profile has carried year, award and result for a while and
			// the page prints them, but the structured data said nothing. An
			// award is one of the few things a search engine will put in a
			// panel beside a person's name, and it was sitting in the database
			// unsaid. Only wins are claimed, which is what the field's own
			// note promised.
			'award'         => daw_schema_person_awards( $post_id ),
			// THE FILMOGRAPHY WAS ON THE PAGE AND NOWHERE IN THE DATA
			// ------------------------------------------------------
			// A person page can print thirty credits and still tell a search
			// engine nothing about what the person is in: the cards are links,
			// not statements. performerIn joins the person to each work by the
			// work's own @id, so the two records are one graph rather than two
			// pages that happen to link. Only records we actually hold are
			// named, so this can never claim a credit the site cannot show.
			//
			// AS A REVERSE actor, NOT performerIn
			// The link used to be performerIn, whose range is Event: every
			// person page carried a type mismatch on all its credits in the
			// Schema.org validator. The true statement is the one the title
			// pages already make ("this drama has this actor"), so the person
			// states it from the other end with JSON-LD's @reverse: same graph,
			// same @ids, no property used outside its range.
			'@reverse'      => $works ? array( 'actor' => $works ) : null,
			// NO publisher HERE.
			// It was copied down from the record types above and it does not
			// belong: publisher is a CreativeWork property, and a Person is not
			// a CreativeWork. Schema.org's own validator flagged exactly this,
			// one warning on one line, on every person page on the site. The
			// page's publisher is already stated once, on the WebSite node that
			// every page carries.
		)
	);
}

/**
 * Every title this person is credited in, as a reference to that title's own
 * node rather than as a name in quotes.
 *
 * Capped, because a long career runs past a hundred credits and a node that
 * size helps nobody: the lead roles are the ones a panel would show, and the
 * full list is a click away on the page itself.
 */
function daw_schema_person_works( $post_id ) {
	if ( ! function_exists( 'daw_get_filmography' ) ) {
		return null;
	}

	$out  = array();
	$seen = array();
	$film = daw_get_filmography( $post_id );

	foreach ( array( 'lead', 'supporting', 'guest', 'other' ) as $tier ) {
		if ( empty( $film[ $tier ] ) ) {
			continue;
		}

		foreach ( $film[ $tier ] as $row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;

			if ( ! $id || isset( $seen[ $id ] ) || count( $out ) >= 50 ) {
				continue;
			}

			$seen[ $id ] = true;
			$out[]       = array(
				'@type' => daw_schema_work_type( $id ),
				'@id'   => get_permalink( $id ) . '#record',
				'name'  => daw_schema_title( $id ),
				'url'   => get_permalink( $id ),
			);
		}
	}

	return $out ? $out : null;
}

/** The schema type for a title, from the record's own kind. */
function daw_schema_work_type( $post_id ) {
	$type = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';

	if ( 'film' === $type ) {
		return 'Movie';
	}

	return 'TVSeries';
}

/**
 * The spouse, but only when the field holds a person rather than an answer.
 *
 * The imported profiles write "Not married", "None" and "Single" into the same
 * box that otherwise holds a name. Handing those to schema.org would create a
 * person called Not Married and marry several hundred actors to her.
 */
function daw_schema_spouse( $post_id ) {
	$value = trim( wp_strip_all_tags( (string) daw_field_raw( $post_id, 'daw_spouse' ) ) );

	if ( '' === $value ) {
		return null;
	}

	$not_a_name = array( 'not married', 'none', 'single', 'n/a', 'na', 'unmarried', 'no', 'unknown', 'not applicable', 'divorced', 'widowed' );

	if ( in_array( strtolower( $value ), $not_a_name, true ) ) {
		return null;
	}

	// A sentence is not a name either.
	if ( str_word_count( $value ) > 5 ) {
		return null;
	}

	return array(
		'@type' => 'Person',
		'name'  => $value,
	);
}

/** The stored value as schema.org writes it, or null when the record does not say. */
function daw_schema_gender( $post_id ) {
	$value = (string) daw_field_raw( $post_id, 'daw_gender' );

	$words = array(
		'male'   => 'Male',
		'female' => 'Female',
	);

	return $words[ $value ] ?? null;
}

/**
 * The group this person belongs to, as a node rather than a string.
 *
 * WHY A NODE
 * ----------
 * "memberOf": "BTS" is a claim about a word. "memberOf": { MusicGroup, name,
 * url } is a claim about a thing that has a page, and it is the second one that
 * lets a search engine put the member and the band in the same picture. The
 * band's record is on this site, so there is no reason to hand over less.
 */
/**
 * The wins, as plain strings, newest first.
 *
 * A nomination is not an award and is not claimed as one. The field stores the
 * result as free text because the imports did, so anything that is not clearly
 * a win is left out rather than guessed at.
 */
function daw_schema_person_awards( $post_id ) {
	// READ THROUGH THE SAME DOOR THE PAGE USES
	// ----------------------------------------
	// This used to read daw_paw_name directly. When awards gained ceremony,
	// category and work columns, every new row left that field empty, so the
	// page printed a full awards table while the structured data claimed the
	// person had won nothing. One reader for both is the only way those two
	// can never disagree again.
	if ( ! function_exists( 'daw_get_person_awards' ) ) {
		return null;
	}

	$out = array();

	foreach ( daw_get_person_awards( $post_id ) as $row ) {
		$result = strtolower( trim( (string) $row['result'] ) );

		// Only wins are claimed. A nomination is not an award, and schema.org
		// has no way to say "nominated" inside this property.
		if ( 'won' !== $result && 'win' !== $result && 'winner' !== $result ) {
			continue;
		}

		$label = trim( wp_strip_all_tags( (string) $row['label'] ) );

		if ( '' === $label ) {
			continue;
		}

		$year  = preg_replace( '~[^0-9]~', '', (string) $row['year'] );
		$out[] = $year ? $label . ' (' . $year . ')' : $label;
	}

	return $out ? array_values( array_unique( $out ) ) : null;
}

function daw_schema_member_of( $post_id ) {
	if ( ! function_exists( 'daw_person_group' ) ) {
		return null;
	}

	$group = daw_person_group( $post_id );

	if ( ! $group ) {
		return null;
	}

	return array(
		'@type' => 'MusicGroup',
		'@id'   => get_permalink( $group ) . '#record',
		'name'  => daw_schema_title( $group ),
		'url'   => get_permalink( $group ),
	);
}

/**
 * A band.
 *
 * It had no schema at all until now: a group record fell through to the generic
 * article node, which described BTS as a piece of writing. MusicGroup is the
 * type, the members are the people whose own records name it, and each one is
 * given by @id so the graph joins up in both directions rather than repeating
 * a name twice.
 */
function daw_schema_music_group( $post_id ) {
	$url     = get_permalink( $post_id );
	$members = function_exists( 'daw_group_members' ) ? daw_group_members( $post_id ) : array();
	$nodes   = array();

	foreach ( $members as $member_id ) {
		$nodes[] = array(
			'@type' => 'Person',
			'@id'   => get_permalink( $member_id ) . '#record',
			'name'  => daw_schema_title( $member_id ),
			'url'   => get_permalink( $member_id ),
		);
	}

	$country = daw_schema_first_term( $post_id, 'dw_country' );

	return array_filter(
		array(
			'@type'         => 'MusicGroup',
			'@id'           => $url . '#record',
			'url'           => $url,
			'name'          => daw_schema_title( $post_id ),
			'alternateName' => (string) daw_field_raw( $post_id, 'daw_native_name' ),
			'image'         => daw_schema_image( $post_id ),
			'description'   => trim( wp_strip_all_tags( (string) daw_field_raw( $post_id, 'daw_group_summary' ) ) ),
			'foundingDate'  => daw_schema_date( (string) daw_field_raw( $post_id, 'daw_group_debut' ) ),
			'foundingLocation' => $country ? array( '@type' => 'Country', 'name' => $country->name ) : null,
			'member'        => $nodes ? $nodes : null,
			'recordLabel'   => daw_field_raw( $post_id, 'daw_agency' )
				? array( '@type' => 'Organization', 'name' => (string) daw_field_raw( $post_id, 'daw_agency' ) )
				: null,
			'sameAs'        => function_exists( 'daw_schema_person_same_as' ) ? daw_schema_person_same_as( $post_id ) : null,
		)
	);
}

/**
 * The one-line summary first, because it was written to be exactly this: a
 * single sentence saying who the person is. The biography is the fallback.
 */
function daw_schema_person_description( $post_id ) {
	$summary = trim( wp_strip_all_tags( (string) daw_field_raw( $post_id, 'daw_person_summary' ) ) );
	if ( '' !== $summary ) {
		return $summary;
	}

	return wp_trim_words( wp_strip_all_tags( (string) daw_field_raw( $post_id, 'daw_biography' ) ), 55, '…' );
}

/**
 * Official accounts only. sameAs is a claim that these are the same entity, so
 * a fan account or an unverified profile has no business in it.
 */
function daw_schema_person_same_as( $post_id ) {
	$urls = array();

	// THE TWO THAT ACTUALLY IDENTIFY A PERSON WERE MISSING
	// ----------------------------------------------------
	// sameAs is how a search engine decides that the Seo Kang Joon on this page
	// is the same Seo Kang Joon it already knows about. An Instagram handle is
	// weak evidence for that; Wikipedia and IMDb are the two strongest, and
	// both were already recorded on the profile and simply never stated. They
	// go first, because order is read as confidence.
	//
	// This is not the same thing as linking a reader out. The Official links
	// card decides what a reader is offered; sameAs is a machine statement
	// about identity that no reader sees.
	foreach ( array( 'daw_link_wikipedia', 'daw_link_imdb', 'daw_social_instagram', 'daw_social_x', 'daw_social_youtube', 'daw_social_weibo', 'daw_social_facebook', 'daw_spotify_artist' ) as $key ) {
		$url = trim( (string) daw_field_raw( $post_id, $key ) );

		if ( '' !== $url && preg_match( '~^https?://~i', $url ) ) {
			$urls[] = $url;
		}
	}

	return $urls ? array_values( array_unique( $urls ) ) : null;
}

/**
 * A news story, described the way schema.org describes one.
 *
 * WHAT GOOGLE ACTUALLY READS
 * --------------------------
 * headline, datePublished, dateModified, image, author and publisher are the
 * six that decide whether a story is eligible at all, so none of them is left
 * to chance: the headline is the real headline trimmed to the 110 characters
 * Google documents, the image is the featured image as an ImageObject with its
 * real dimensions, and the author is this newsroom rather than a person who
 * did no reporting.
 *
 * WHAT MAKES THE ENTITY LEGIBLE
 * -----------------------------
 * about and mentions carry the records the story is linked to, each pointing
 * at that record's own @id on this site, so a search engine reading the story
 * lands on the drama and the actor as entities rather than as strings. The
 * first linked record is the subject; the rest are mentions.
 *
 * WHAT KEEPS IT HONEST
 * --------------------
 * citation carries the outlets that reported it, with their urls. A story with
 * no source does not get one, because an empty citation array is a claim that
 * nothing was cited.
 *
 * Every property is dropped when empty. A graph that says wordCount 0 or
 * articleSection "" is worse than one that stays quiet.
 */
function daw_schema_news( $post_id ) {
	$url  = get_permalink( $post_id );
	$post = get_post( $post_id );

	// about: the record the story is set to be about comes first, then the
	// rest in the order the text names them (1.75.56).
	$mentions = daw_news_mentions_in_order( $post_id );
	$about_id = daw_news_about_record( $post_id );
	if ( $about_id ) {
		$mentions = array_merge( array( $about_id ), array_values( array_diff( $mentions, array( $about_id ) ) ) );
	}

	$refs = array();
	foreach ( $mentions as $mid ) {
		$refs[] = array(
			'@id'  => get_permalink( $mid ) . '#record',
			'name' => daw_schema_title( $mid ),
			'url'  => get_permalink( $mid ),
		);
	}

	$about    = $refs ? array( array_shift( $refs ) ) : array();
	$standfirst = wp_strip_all_tags( (string) daw_field_raw( $post_id, 'daw_news_standfirst' ) );
	$kicker     = wp_strip_all_tags( (string) daw_field_raw( $post_id, 'daw_news_kicker' ) );
	$body       = $post ? wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) : '';
	$words      = $body ? str_word_count( $body ) : 0;

	return array_filter(
		array(
			'@type'            => 'NewsArticle',
			'@id'              => $url . '#record',
			'url'              => $url,
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => $url,
			),
			// Google documents a 110 character ceiling on headline; a longer
			// one is not an error but it is ignored, so it is cut here rather
			// than silently dropped there.
			'headline'         => daw_schema_clip( daw_schema_title( $post_id ), 110 ),
			'alternativeHeadline' => $standfirst ? daw_schema_clip( $standfirst, 200 ) : null,
			'description'      => $standfirst ? daw_schema_clip( $standfirst, 300 ) : null,
			'articleSection'   => $kicker ? $kicker : null,
			'image'            => daw_schema_image( $post_id ),
			'thumbnailUrl'     => get_the_post_thumbnail_url( $post_id, 'medium_large' ) ?: null,
			'datePublished'    => get_post_time( 'c', true, $post_id ),
			// post_modified_gmt, as asked on 9 Oct 2026; the byline and the front page keep the "last real update" date.
			'dateModified'     => get_post_modified_time( 'c', true, $post_id ),
			'inLanguage'       => 'en',
			'isAccessibleForFree' => true,
			'wordCount'        => $words ? $words : null,
			'keywords'         => daw_schema_keywords( $post_id ),
			'articleBody'      => $body ? daw_schema_clip( $body, 5000 ) : null,
			'about'            => $about ? $about : null,
			'mentions'         => $refs ? array_values( $refs ) : null,
			'citation'         => daw_schema_citations( $post_id ),
			'publisher'        => array( '@id' => home_url( '/#organization' ) ),
			'author'           => array( '@id' => home_url( '/#organization' ) ),
			'copyrightHolder'  => array( '@id' => home_url( '/#organization' ) ),
			'copyrightYear'    => (int) get_post_time( 'Y', true, $post_id ),
			'speakable'        => array(
				'@type'       => 'SpeakableSpecification',
				'cssSelector' => array( '.daw-ntitle', '.daw-nstand' ),
			),
		)
	);
}

/** The outlets that reported it, as CreativeWork citations. */
function daw_schema_citations( $post_id ) {
	if ( ! function_exists( 'daw_get_news_sources' ) ) {
		return null;
	}

	$out = array();

	foreach ( (array) daw_get_news_sources( $post_id ) as $row ) {
		$name = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
		$link = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';

		if ( '' === $name && '' === $link ) {
			continue;
		}

		$out[] = array_filter(
			array(
				'@type'     => 'CreativeWork',
				'name'      => $name ? $name : null,
				'url'       => $link ? $link : null,
				'publisher' => $name ? array(
					'@type' => 'Organization',
					'name'  => $name,
				) : null,
			)
		);
	}

	return $out ? $out : null;
}

/** The featured image with its real dimensions, which Google asks for. */
function daw_schema_image( $post_id ) {
	if ( function_exists( 'daw_remote_mode_for' ) ) {
		$mode = daw_remote_mode_for( $post_id );
		if ( 'monogram' === $mode || 'vault' === $mode ) {
			return null;
		}
		if ( 'remote' === $mode ) {
			$person = in_array( daw_get_type( $post_id ), array( 'person', 'group' ), true );
			return array(
				'@type'  => 'ImageObject',
				'url'    => daw_record_image_url( $post_id, 'large' ),
				'width'  => $person ? 421 : 500,
				'height' => $person ? 632 : 750,
			);
		}
	}
	$id = get_post_thumbnail_id( $post_id );
	if ( ! $id ) {
		return null;
	}

	$src = wp_get_attachment_image_src( $id, 'full' );
	if ( ! $src ) {
		return null;
	}

	return array_filter(
		array(
			'@type'   => 'ImageObject',
			'url'     => $src[0],
			'width'   => (int) $src[1] ?: null,
			'height'  => (int) $src[2] ?: null,
			'caption' => wp_get_attachment_caption( $id ) ?: null,
		)
	);
}

/**
 * Keywords, taken from the records the story is about rather than from tags.
 *
 * A tag on this site is a root-level url, which is the same namespace the
 * records live in, so tagging a story "Seo Kang Joon" would fight the actor's
 * own page for that address and win nothing. The entities are already known
 * from the mention index, and they are the honest answer to what this story is
 * about, so they are what goes in keywords. Tags, if a story has any, are added
 * after them.
 */
function daw_schema_keywords( $post_id ) {
	$names = array();

	if ( function_exists( 'daw_news_mentions_cached' ) ) {
		foreach ( daw_news_mentions_cached( $post_id ) as $mid ) {
			$names[] = daw_schema_title( $mid );
		}
	}

	$terms = get_the_terms( $post_id, 'post_tag' );
	if ( $terms && ! is_wp_error( $terms ) ) {
		$names = array_merge( $names, wp_list_pluck( $terms, 'name' ) );
	}

	$names = array_values( array_unique( array_filter( array_map( 'trim', $names ) ) ) );

	return $names ? implode( ', ', $names ) : null;
}

/** Cut on a word boundary without an ellipsis, for schema string fields. */
function daw_schema_clip( $text, $max ) {
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );

	if ( '' === $text || strlen( $text ) <= $max ) {
		return $text;
	}

	$cut = substr( $text, 0, $max );
	$sp  = strrpos( $cut, ' ' );

	return rtrim( false === $sp ? $cut : substr( $cut, 0, $sp ), " ,.;:" );
}

function daw_schema_article( $post_id ) {
	$url = get_permalink( $post_id );

	return array_filter(
		array(
			'@type'            => 'Article',
			'@id'              => $url . '#record',
			'url'              => $url,
			'mainEntityOfPage' => $url,
			'headline'         => daw_schema_clip( daw_schema_title( $post_id ), 110 ),
			'image'            => get_the_post_thumbnail_url( $post_id, 'full' ),
			'datePublished'    => get_post_time( 'c', true, $post_id ),
			'dateModified'     => get_post_modified_time( 'c', true, $post_id ),
			'publisher'        => array( '@id' => home_url( '/#organization' ) ),
		)
	);
}

/**
 * A guide: culture, food and drink, myths, travel.
 *
 * WHY Article AND NOT Recipe
 * --------------------------
 * Some of these pages explain a dish, and a few of them say how it is made, so
 * Recipe is available and tempting. It would also be a claim about what the
 * page is: a recipe result promises ingredient quantities, times, yields and a
 * photograph of the finished plate, and it is judged against pages that have
 * all four. These pages explain where a dish came from and where a reader has
 * seen it. Marking them as something they are not buys one rich result and
 * loses the trust of everyone who clicks it.
 *
 * What the node does carry is the part that is true and useful: what the guide
 * is about, the works it names, and the institutions it was checked against.
 */
function daw_schema_guide( $post_id ) {
	$url  = get_permalink( $post_id );
	$post = get_post( $post_id );
	$body = $post ? wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) : '';

	$node = array(
		'@type'            => 'Article',
		'@id'              => $url . '#record',
		'url'              => $url,
		'mainEntityOfPage' => $url,
		'headline'         => daw_schema_clip( daw_schema_title( $post_id ), 110 ),
		'description'      => daw_schema_clip( (string) daw_field_raw( $post_id, 'daw_guide_standfirst' ), 300 ),
		'articleSection'   => function_exists( 'daw_guide_kicker' ) ? daw_guide_kicker( $post_id ) : null,
		'image'            => daw_schema_image( $post_id ),
		'datePublished'    => get_post_time( 'c', true, $post_id ),
		'dateModified'     => get_post_modified_time( 'c', true, $post_id ),
		'inLanguage'       => 'en',
		'isAccessibleForFree' => true,
		'wordCount'        => $body ? str_word_count( $body ) : null,
		'publisher'        => array( '@id' => home_url( '/#organization' ) ),
		'author'           => array( '@id' => home_url( '/#organization' ) ),
		'isPartOf'         => array( '@id' => home_url( '/#website' ) ),
	);

	// The name in its own language is the thing the article is about, and the
	// string most likely to match the same entity in another database.
	$native = trim( (string) daw_field_raw( $post_id, 'daw_guide_native' ) );
	if ( '' !== $native ) {
		$node['about'] = array(
			'@type'          => 'Thing',
			'name'           => daw_schema_title( $post_id ),
			'alternateName'  => $native,
		);
	}

	// The titles this appears in, each pointing at its own record on this site
	// rather than at a bare string.
	// When the structured list exists it is printed as an ItemList of its own, so
	// the older mentions are not repeated beside it.
	if ( function_exists( 'daw_get_onscreen' ) ) {
		$mentions = array();
		$covered  = function_exists( 'daw_fos_covered' ) ? daw_fos_covered( $post_id ) : array();

		foreach ( daw_get_onscreen( $post_id ) as $row ) {
			$mid = (int) $row['id'];

			// Titles in the ItemList are not repeated here.
			if ( in_array( $mid, $covered, true ) ) {
				continue;
			}

			if ( ! $mid || 'publish' !== get_post_status( $mid ) ) {
				continue;
			}

			$mentions[] = array(
				'@type' => 'movie' === ( daw_schema_screen_kinds()[ daw_get_type( $mid ) ] ?? 'movie' ) ? 'Movie' : 'TVSeries',
				'@id'   => get_permalink( $mid ) . '#record',
				'name'  => daw_schema_title( $mid ),
				'url'   => get_permalink( $mid ),
			);
		}

		if ( $mentions ) {
			$node['mentions'] = $mentions;
		}
	}

	// Where it was checked. A history claim with no citation is a rumour with
	// a serif font.
	if ( function_exists( 'daw_get_guide_sources' ) ) {
		$cites = array();

		foreach ( daw_get_guide_sources( $post_id ) as $src ) {
			$cite = array(
				'@type' => 'CreativeWork',
				'name'  => $src['name'],
			);

			if ( trim( (string) $src['url'] ) !== '' ) {
				$cite['url'] = $src['url'];
			}

			$cites[] = $cite;
		}

		if ( $cites ) {
			$node['citation'] = $cites;
		}
	}

	return array_filter( $node );
}

/* ================================================================ helpers = */

function daw_schema_term_names( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return array();
	}

	return array_values( wp_list_pluck( $terms, 'name' ) );
}

function daw_schema_first_term( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );

	return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
}

/**
 * Language is inferred from the country rather than typed by hand, because a
 * field nobody fills is a field that ends up wrong.
 */
function daw_schema_language( $post_id ) {
	$map = array(
		'south-korea' => 'ko',
		'korea'       => 'ko',
		'japan'       => 'ja',
		'china'       => 'zh',
		'taiwan'      => 'zh-TW',
		'hong-kong'   => 'zh-HK',
		'thailand'    => 'th',
		'india'       => 'hi',
		'philippines' => 'tl',
		'indonesia'   => 'id',
		'vietnam'     => 'vi',
	);

	$country = daw_schema_first_term( $post_id, 'dw_country' );
	if ( ! $country ) {
		return '';
	}

	return $map[ $country->slug ] ?? '';
}

/** Dates arrive as free text in some fields; only an ISO date is emitted. */
function daw_schema_date( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}

	// TWO WAYS THIS USED TO INVENT A DATE
	// -----------------------------------
	// A group debut typed as a bare year ("2013") is all digits, so it was
	// read as a Unix timestamp and came out as foundingDate 1970-01-01. And a
	// birth before 1970 is a negative timestamp, which is not ctype_digit, so
	// it fell through to strtotime(): -631152000 (1 Jan 1950) came back as
	// today's date. Both put a false date into the structured data (rule 1.1).
	// Each shape is now recognised for what it is, and anything unreadable
	// gives nothing rather than a guess.
	if ( preg_match( '/^(1[89]|20)\d\d$/', $value ) ) {
		return $value; // A year on its own is a valid ISO 8601 date.
	}
	if ( preg_match( '/^(\d{4})-(\d{2})(?:-(\d{2}))?$/', $value, $m ) ) {
		return checkdate( (int) $m[2], isset( $m[3] ) ? (int) $m[3] : 1, (int) $m[1] ) ? $value : '';
	}
	if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return $m[1] . '-' . $m[2] . '-' . $m[3]; // Ymd, as Meta Box stores some dates.
	}
	if ( preg_match( '/^-?\d+$/', $value ) ) {
		return gmdate( 'Y-m-d', (int) $value );
	}

	$date = date_create( $value, new DateTimeZone( 'UTC' ) );

	if ( ! $date ) {
		return '';
	}

	// "June 2013" names a month, not the first of it.
	return preg_match( '/^[\p{L}.]+\s+\d{4}$/u', $value ) ? $date->format( 'Y-m' ) : $date->format( 'Y-m-d' );
}
