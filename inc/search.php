<?php
/**
 * Discover Asian World — search that knows what it is searching.
 *
 * WHAT WAS THERE
 * --------------
 * WordPress's own search, which matches words in post content and ranks by
 * date. On an archive whose content lives in fields rather than in prose, that
 * means a search for "kim soo" returns three news stories that mention him
 * before it returns the man himself, and each result is printed with an excerpt
 * and a "Read More" under a heading that repeats the title it is already under.
 *
 * WHAT THIS DOES INSTEAD
 * ----------------------
 * A record is a thing, not a document, so the title is what a reader typed and
 * everything else is context. The ranking says so: an exact title first, then a
 * title that starts with what was typed, then one that contains it, then
 * anything else that mentions it. Inside each of those, a person outranks a
 * story about that person, because somebody who types a name wants the person.
 *
 * Names arrive romanised half a dozen ways, so the native name and the "also
 * known as" list are searched too: "김수현" and "Kim Soohyun" both find the
 * record that files itself as Kim Soo Hyun.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** How many results one search page holds. */
const DAW_SEARCH_PER_PAGE = 20;

/**
 * The record types, in the order the tabs show them, with their labels.
 *
 * Guides are one tab rather than four: a reader searching for tteokbokki does
 * not care whether the archive files it under food or culture, and four tabs
 * holding two results each is four tabs of nothing.
 */
function daw_search_tabs() {
	return array(
		'people'  => array( 'label' => __( 'People', 'daw' ), 'types' => array( 'person' ) ),
		'dramas'  => array( 'label' => __( 'Dramas', 'daw' ), 'types' => array( 'drama', 'variety' ) ),
		'films'   => array( 'label' => __( 'Films', 'daw' ), 'types' => array( 'film' ) ),
		'k-pop'   => array( 'label' => __( 'K-Pop', 'daw' ), 'types' => array( 'group' ) ),
		'guides'  => array( 'label' => __( 'Guides', 'daw' ), 'types' => array( 'food', 'culture', 'myth', 'travel', 'feature', 'quiz' ) ),
		'news'    => array( 'label' => __( 'News', 'daw' ), 'types' => array( 'news' ) ),
	);
}

/**
 * Every record that matches, best first.
 *
 * Returns ids. The caller decides what to draw with them, and the whole list
 * rather than one page of it, because the tab counts above the results are
 * counted from the same search that produced them.
 */
function daw_search_ids( $query ) {
	$query = trim( wp_strip_all_tags( (string) $query ) );

	if ( '' === $query || mb_strlen( $query ) < 2 ) {
		return array();
	}

	$key = 'daw_search_' . md5( 'v2|' . ( function_exists( 'daw_browse_gen' ) ? daw_browse_gen() : 1 ) . '|' . mb_strtolower( $query ) );
	$hit = get_transient( $key );

	if ( is_array( $hit ) ) {
		return $hit;
	}

	global $wpdb;

	$like = '%' . $wpdb->esc_like( $query ) . '%';

	// TITLES AND THE NAMES A TITLE ALSO GOES BY
	// -----------------------------------------
	// One statement rather than three queries and a merge in PHP: the meta join
	// is a left join, so a record with no native name is not lost by it, and the
	// distinct keeps a record that matches twice from arriving twice.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT p.ID
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} m
			          ON m.post_id = p.ID
			         AND m.meta_key IN ( 'daw_native_name', 'daw_native_title', 'daw_also_known_as', 'daw_real_name' )
			  WHERE p.post_type = 'post'
			    AND p.post_status = 'publish'
			    AND ( p.post_title LIKE %s OR m.meta_value LIKE %s )
			  LIMIT 300",
			$like,
			$like
		)
	);

	$ids = array_map( 'intval', (array) $ids );

	// Then whatever else mentions it, so a story about somebody is still found
	// by their name even though the name is not its title.
	$loose = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => 120,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			's'                      => $query,
		)
	);

	$ids = array_values( array_unique( array_merge( $ids, array_map( 'intval', (array) $loose ) ) ) );

	// The same name written another way: "kim soohyun", "kim soo-hyun" and
	// "soo hyun kim" are all Kim Soo Hyun, and none of them is a substring of
	// the title. Compared with spaces and punctuation gone, and word by word.
	$ids = array_values( array_unique( array_merge( $ids, daw_search_loose_names( $query ) ) ) );

	if ( ! $ids ) {
		set_transient( $key, array(), HOUR_IN_SECONDS );

		return array();
	}

	$ids = daw_search_rank( $ids, $query );

	set_transient( $key, $ids, 6 * HOUR_IN_SECONDS );

	return $ids;
}

/**
 * Sort matches by how well they answer what was typed.
 *
 * Four bands, and inside a band the type decides. A reader who types a person's
 * name wants the person, then the things they were in, then the news about
 * them: that order is the whole reason this function exists rather than a
 * date sort.
 */
function daw_search_rank( $ids, $query ) {
	$needle = daw_search_norm( $query );

	$type_rank = array(
		'person'  => 0,
		'group'   => 1,
		'drama'   => 2,
		'film'    => 3,
		'variety' => 4,
		'culture' => 5,
		'food'    => 5,
		'myth'    => 5,
		'travel'  => 5,
		'feature' => 5,
		'quiz'    => 6,
		'news'    => 7,
	);

	$scored = array();

	foreach ( $ids as $id ) {
		$title = daw_search_norm( get_the_title( $id ) );
		$type  = function_exists( 'daw_get_type' ) ? daw_get_type( $id ) : '';

		if ( $title === $needle ) {
			$band = 0;
		} elseif ( '' !== $needle && 0 === mb_strpos( $title, $needle ) ) {
			$band = 1;
		} elseif ( '' !== $needle && false !== mb_strpos( $title, $needle ) ) {
			$band = 2;
		} else {
			$band = 3;
		}

		$scored[ $id ] = array(
			'band'  => $band,
			'type'  => $type_rank[ $type ] ?? 8,
			'title' => $title,
		);
	}

	uasort(
		$scored,
		static function ( $a, $b ) {
			return array( $a['band'], $a['type'], $a['title'] ) <=> array( $b['band'], $b['type'], $b['title'] );
		}
	);

	return array_keys( $scored );
}

/* ================================================================ near === */

/**
 * A name reduced to what spelling cannot change: lower case, no accents, and
 * nothing but letters and digits. "Kim Soo-hyun" and "kim soohyun" both become
 * "kimsoohyun", which is the whole trick behind the two functions below.
 */
function daw_search_norm( $s ) {
	$s = remove_accents( wp_specialchars_decode( (string) $s, ENT_QUOTES ) );

	return (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( $s ) );
}

/**
 * Every name a record goes by, reduced, built once per archive change.
 *
 * Titles and the native, real and "also known as" names of every record that
 * is a thing (not news, not quizzes). Two queries for the whole archive, kept
 * until a record is saved, so a search never pays for this more than once.
 *
 * @return array{t: array<int,string>, n: array<int,array{0:int,1:string}>}
 */
function daw_search_names() {
	$key = 'daw_search_names_' . ( function_exists( 'daw_browse_gen' ) ? daw_browse_gen() : 1 );
	$hit = get_transient( $key );

	if ( is_array( $hit ) && isset( $hit['n'] ) ) {
		return $hit;
	}

	global $wpdb;

	$types = array();
	$names = array();
	$seen  = array();

	$add = static function ( $id, $name ) use ( &$names, &$seen ) {
		$n = daw_search_norm( $name );

		if ( mb_strlen( $n ) < 2 || strlen( $n ) > 120 || isset( $seen[ $id . '|' . $n ] ) ) {
			return;
		}

		$seen[ $id . '|' . $n ] = true;
		$names[]                = array( (int) $id, $n );
	};

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
		"SELECT p.ID, p.post_title, t.slug
		   FROM {$wpdb->posts} p
		   JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID
		   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = 'dw_type'
		   JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		  WHERE p.post_type = 'post' AND p.post_status = 'publish' AND t.slug NOT IN ( 'news', 'quiz' )",
		ARRAY_N
	);

	foreach ( (array) $rows as $r ) {
		$types[ (int) $r[0] ] = (string) $r[2];
		$add( $r[0], $r[1] );
	}

	$meta = $wpdb->get_results( // phpcs:ignore WordPress.DB
		"SELECT post_id, meta_value FROM {$wpdb->postmeta}
		  WHERE meta_key IN ( 'daw_native_name', 'daw_native_title', 'daw_also_known_as', 'daw_real_name' ) AND meta_value <> ''",
		ARRAY_N
	);

	foreach ( (array) $meta as $m ) {
		if ( ! isset( $types[ (int) $m[0] ] ) ) {
			continue;
		}

		$v = maybe_unserialize( $m[1] );

		if ( is_array( $v ) ) {
			array_walk_recursive(
			$v,
			static function ( $one ) use ( $add, $m ) {
				if ( is_string( $one ) ) {
					foreach ( preg_split( '/\s*[,;\/\n]\s*/u', $one ) as $part ) {
						$add( $m[0], $part );
					}
				}
			}
			);
		} elseif ( is_string( $v ) ) {
			foreach ( preg_split( '/\s*[,;\/\n]\s*/u', $v ) as $part ) {
				$add( $m[0], $part );
			}
		}
	}

	$out = array( 't' => $types, 'n' => $names );
	set_transient( $key, $out, 12 * HOUR_IN_SECONDS );

	return $out;
}

/**
 * Records whose name holds what was typed once spacing is ignored, or holds
 * every word of it in any order. These are real matches, not guesses.
 */
function daw_search_loose_names( $query ) {
	$q = daw_search_norm( $query );

	if ( mb_strlen( $q ) < 3 ) {
		return array();
	}

	$words = array_values( array_filter( array_map( 'daw_search_norm', preg_split( '/[\s\-_.,]+/u', mb_strtolower( (string) $query ) ) ), static fn( $w ) => mb_strlen( $w ) >= 2 ) );
	$out   = array();

	foreach ( daw_search_names()['n'] as $row ) {
		if ( isset( $out[ $row[0] ] ) ) {
			continue;
		}

		$hit = false !== strpos( $row[1], $q );

		if ( ! $hit && count( $words ) > 1 ) {
			$hit = true;

			foreach ( $words as $w ) {
				if ( false === strpos( $row[1], $w ) ) {
					$hit = false;
					break;
				}
			}
		}

		if ( $hit ) {
			$out[ $row[0] ] = true;

			if ( count( $out ) >= 120 ) {
				break;
			}
		}
	}

	return array_keys( $out );
}

/**
 * "Did you mean": the records whose name is a typo or two away from what was
 * typed, closest first.
 *
 * Only asked for when a search found little or nothing, and remembered per
 * phrase like the search itself, so the comparison over every name in the
 * archive (a few milliseconds) runs at most once per phrase per archive change.
 */
function daw_search_near( $query, $limit = 6 ) {
	$q   = daw_search_norm( $query );
	$len = strlen( $q );

	if ( mb_strlen( $q ) < 3 ) {
		return array();
	}

	$key = 'daw_search_near_' . md5( ( function_exists( 'daw_browse_gen' ) ? daw_browse_gen() : 1 ) . '|' . $q );
	$hit = get_transient( $key );

	if ( is_array( $hit ) ) {
		return array_slice( $hit, 0, $limit );
	}

	// A typo allowance that grows with the word: one for a short name, three
	// for a long one. More than that and "Did you mean" starts guessing.
	$max   = $len <= 4 ? 1 : ( $len <= 8 ? 2 : 3 );
	$names = daw_search_names();
	$best  = array();

	foreach ( $names['n'] as $row ) {
		$n  = $row[1];
		$ln = strlen( $n );

		if ( $ln + $max < $len ) {
			continue;
		}

		$d = $ln - $len > $max ? PHP_INT_MAX : levenshtein( $q, substr( $n, 0, 255 ) );

		// The start of a longer name, typed with a slip: "song hye gy" for
		// Song Hye Kyo. Costs one more than a whole-name match.
		if ( $ln > $len ) {
			$d = min( $d, levenshtein( $q, substr( $n, 0, $len ) ) + 1 );
		}

		if ( $d <= $max && ( ! isset( $best[ $row[0] ] ) || $d < $best[ $row[0] ] ) ) {
			$best[ $row[0] ] = $d;
		}
	}

	$rank = array( 'person' => 0, 'group' => 1, 'drama' => 2, 'film' => 3, 'variety' => 4, 'anime' => 4 );
	$ids  = array_keys( $best );

	usort(
		$ids,
		static function ( $a, $b ) use ( $best, $rank, $names ) {
			return array( $best[ $a ], $rank[ $names['t'][ $a ] ?? '' ] ?? 5 ) <=> array( $best[ $b ], $rank[ $names['t'][ $b ] ?? '' ] ?? 5 );
		}
	);

	$ids = array_slice( $ids, 0, 12 );
	set_transient( $key, $ids, 6 * HOUR_IN_SECONDS );

	return array_slice( $ids, 0, $limit );
}

/** The ids grouped into the tabs, so each tab can print its own count. */
function daw_search_groups( $ids ) {
	$tabs  = daw_search_tabs();
	$out   = array_fill_keys( array_keys( $tabs ), array() );
	$index = array();

	foreach ( $tabs as $slug => $tab ) {
		foreach ( $tab['types'] as $type ) {
			$index[ $type ] = $slug;
		}
	}

	foreach ( $ids as $id ) {
		$type = function_exists( 'daw_get_type' ) ? daw_get_type( $id ) : '';
		$slug = $index[ $type ] ?? '';

		if ( $slug ) {
			$out[ $slug ][] = $id;
		}
	}

	return array_filter( $out );
}

/* =============================================================== log ===== */

/**
 * What people search for here, counted.
 *
 * WHY KEEP IT AT ALL
 * ------------------
 * "Trending searches" is either read from something true or it is decoration.
 * This site has no analytics it can reach at render time, so it counts its own
 * searches: one row per phrase, a tally, and the date it was last asked for.
 * Nothing about the person asking is recorded, because nothing about them is
 * needed to answer the question "what are people looking for".
 *
 * A search page is never served from the page cache, so the count is complete
 * rather than a sample of the cache misses.
 */
function daw_search_log( $query ) {
	// WHOSE SEARCHES COUNT
	// --------------------
	// Only a reader's. An editor testing the search box is not a reader
	// looking for something, and a crawler poking at query strings is not a
	// person at all. Both used to land in this tally, and a "what people search
	// for" list made of the site's own staff walking around is the kind of
	// invented popularity this file exists to avoid.
	if ( is_user_logged_in() ) {
		return;
	}

	$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	if ( '' === $agent || ( function_exists( 'daw_views_is_robot' ) && daw_views_is_robot( $agent ) ) ) {
		return;
	}

	$query = mb_strtolower( trim( wp_strip_all_tags( (string) $query ) ) );

	// Too short to mean anything, or long enough to be somebody pasting a
	// paragraph in, neither of which is a search worth remembering.
	if ( mb_strlen( $query ) < 3 || mb_strlen( $query ) > 60 ) {
		return;
	}

	$log = get_option( 'daw_search_log' );
	$log = is_array( $log ) ? $log : array();

	if ( ! isset( $log[ $query ] ) ) {
		$log[ $query ] = array( 'n' => 0, 'last' => 0 );
	}

	++$log[ $query ]['n'];
	$log[ $query ]['last'] = time();

	// The list is trimmed rather than allowed to grow for ever: a hundred
	// phrases is more than enough to answer what is trending, and it keeps this
	// option small enough to stay in the cache with everything else.
	if ( count( $log ) > 240 ) {
		uasort(
			$log,
			static function ( $a, $b ) {
				return array( $b['n'], $b['last'] ) <=> array( $a['n'], $a['last'] );
			}
		);

		$log = array_slice( $log, 0, 120, true );
	}

	update_option( 'daw_search_log', $log, false );
}

/**
 * The phrases people have actually typed, most asked first.
 *
 * Only ones asked for more than once and only from the last month, so a single
 * curious visitor and a phrase nobody has wanted since spring both drop out.
 * Returns nothing at all when there is nothing to say, and the template prints
 * nothing rather than filling the space with invented popularity.
 */
function daw_search_trending( $limit = 6 ) {
	$log = get_option( 'daw_search_log' );

	if ( ! is_array( $log ) || ! $log ) {
		return array();
	}

	$cut = time() - 30 * DAY_IN_SECONDS;
	$out = array();

	foreach ( $log as $phrase => $row ) {
		if ( ( $row['n'] ?? 0 ) < 2 || ( $row['last'] ?? 0 ) < $cut ) {
			continue;
		}

		$out[ $phrase ] = (int) $row['n'];
	}

	arsort( $out );

	return array_slice( array_keys( $out ), 0, $limit );
}


/**
 * The searched phrases that have a record of their own, as that record.
 *
 * WHY (8 Oct 2026, Umut's SEO spec, item 3)
 * The front page used to link each phrase to /?s=phrase: five links from the
 * strongest page on the site to search result pages, which are noindex. Now a
 * phrase is shown only when one record answers it outright, by its exact
 * name or the start of it ("seo kang" is Seo Kang-joon), and the link goes to
 * that record. A phrase that no record answers is not shown. No near-miss
 * spelling match is used here: a guess on the front page is still a guess.
 *
 * @return array<int,string> record id => the record's title
 */
function daw_search_trending_records( $limit = 5 ) {
	$phrases = daw_search_trending( 20 );
	if ( ! $phrases ) {
		return array();
	}

	$key = 'daw_strec_' . md5( ( function_exists( 'daw_browse_gen' ) ? daw_browse_gen() : 1 ) . '|' . $limit . '|' . implode( '|', $phrases ) );
	$hit = get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}

	$out = array();
	foreach ( $phrases as $phrase ) {
		$needle = daw_search_norm( $phrase );
		if ( mb_strlen( $needle ) < 3 ) {
			continue;
		}
		foreach ( daw_search_rank( daw_search_ids( $phrase ), $phrase ) as $id ) {
			$type = function_exists( 'daw_get_type' ) ? daw_get_type( $id ) : '';
			if ( in_array( $type, array( 'news', 'quiz', '' ), true ) ) {
				continue;
			}
			$title = daw_search_norm( get_the_title( $id ) );
			if ( $title !== $needle && 0 !== mb_strpos( $title, $needle ) ) {
				break; // Ranked: once the best is not a name match, none after it is.
			}
			if ( ! isset( $out[ $id ] ) ) {
				$out[ (int) $id ] = html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' );
			}
			break;
		}
		if ( count( $out ) >= $limit ) {
			break;
		}
	}

	set_transient( $key, $out, HOUR_IN_SECONDS );
	return $out;
}

/* ================================================================ reset === */

const DAW_SEARCH_LOG_RESET = '2026-09-19-clear';

add_action( 'admin_init', 'daw_search_log_reset', 37 );

/**
 * Empty the tally once, because what is in it is not readers.
 *
 * The list was being filled while the site was being built: every search run
 * from an editor's browser and every probe run while testing the archive went
 * into it, and the front page was offering them back as what people search for.
 * The guard above stops it happening again. This clears what it already
 * collected, once, so the list starts from the day the site had readers.
 */
function daw_search_log_reset() {
	if ( get_option( 'daw_search_log_reset' ) === DAW_SEARCH_LOG_RESET ) {
		return;
	}

	delete_option( 'daw_search_log' );
	update_option( 'daw_search_log_reset', DAW_SEARCH_LOG_RESET, false );
}
