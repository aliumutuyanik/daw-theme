<?php
/**
 * Coverage: what the site has against what TMDB says exists.
 *
 * For a title: every credited actor, and the crew people search for
 * (director, screenwriter, original author, variety producer). Each one is
 * either LINKED (has a record and sits in this title's cast), UNLINKED (has a
 * record but is not in the cast: fixable now), or MISSING (no record yet).
 *
 * For a person: every Asian screen credit (drama, film, variety, anime) and
 * any director or writer credit, in the same three states, plus anything
 * dated in the future, which is how a new project shows up without anybody
 * looking for it.
 *
 * One TMDB request per record, weekly, only for records whose TMDB match has
 * been verified. Nothing here writes to a record's visible fields: it writes
 * the private `_daw_cov` report, `_daw_crew` (who made the title, with TMDB
 * ids) and `_daw_crew_ids` (",id,id," so a person page can find the titles
 * they made). Filling fields and opening records stays with the editor.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

const DAW_COV     = '_daw_cov';
const DAW_COV_AT  = '_daw_cov_at';
const DAW_CREW    = '_daw_crew';
const DAW_CREW_ID = '_daw_crew_ids';

/** TMDB crew jobs kept, mapped to the four roles the site shows. */
function daw_cov_crew_jobs() {
	return array(
		'Director'           => 'Director',
		'Series Director'    => 'Director',
		'Screenplay'         => 'Screenwriter',
		'Writer'             => 'Screenwriter',
		'Teleplay'           => 'Screenwriter',
		'Series Composition' => 'Screenwriter',
		'Novel'              => 'Original author',
		'Original Story'     => 'Original author',
		'Comic Book'         => 'Original author',
		'Author'             => 'Original author',
		'Characters'         => 'Original author',
		'Original Concept'   => 'Original author',
		'Producer'           => 'Producer',
		'Executive Producer' => 'Producer',
	);
}

/** Cache key of the id map; carries the plugin version (K20). */
function daw_cov_map_key() {
	return 'daw_cov_map_v3_' . ( defined( 'DAW_VERSION' ) ? DAW_VERSION : '0' );
}

/** "tv:83893" / "movie:5" from a stored alternate id; '' when it is neither. */
function daw_cov_alt_key( $v ) {
	$v = strtolower( trim( (string) $v ) );
	if ( preg_match( '#^(tv|movie)\s*[:/]\s*(\d+)$#', $v, $m ) || preg_match( '#themoviedb\.org/(tv|movie)/(\d+)#', $v, $m ) ) {
		return $m[1] . ':' . (int) $m[2];
	}
	return '';
}

/**
 * Other TMDB ids a production is also listed under (daw_tmdb_alt), as
 * "tv:83893" / "movie:1455382". Used for matching only: poster, cast, watch
 * places and schema keep coming from daw_tmdb_id.
 */
function daw_cov_alt_list( $post_id ) {
	$raw = get_post_meta( (int) $post_id, 'daw_tmdb_alt', true );
	if ( is_string( $raw ) && '' !== $raw ) {
		$raw = preg_split( '/[\s,;]+/', $raw );
	}
	$out = array();
	foreach ( (array) $raw as $v ) {
		$k = daw_cov_alt_key( is_scalar( $v ) ? $v : '' );
		if ( '' !== $k ) {
			$out[ $k ] = $k;
		}
	}
	return array_values( $out );
}

/**
 * Every record with a TMDB id, as "tv:123" / "movie:5" / "person:9" => post id.
 * A production's daw_tmdb_alt ids are in the map too, after the real ids (a
 * real id always wins). One query each, cached for ten minutes.
 */
function daw_cov_site_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map = get_transient( daw_cov_map_key() );
	if ( is_array( $map ) ) {
		return $map;
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB
	$rows = $wpdb->get_results(
		"SELECT pm.post_id, pm.meta_value, t.slug FROM {$wpdb->postmeta} pm
		 JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'post' AND p.post_status = 'publish'
		 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
		 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'dw_type'
		 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		 WHERE pm.meta_key = 'daw_tmdb_id' AND pm.meta_value <> ''"
	);
	$map = array();
	foreach ( (array) $rows as $r ) {
		$v = trim( (string) $r->meta_value );
		if ( preg_match( '#themoviedb\.org/(movie|tv|person)/(\d+)#i', $v, $m ) ) {
			$key = strtolower( $m[1] ) . ':' . (int) $m[2];
		} elseif ( ctype_digit( $v ) ) {
			$kind = in_array( $r->slug, array( 'person', 'group' ), true ) ? 'person' : ( 'film' === $r->slug ? 'movie' : 'tv' );
			$key  = $kind . ':' . (int) $v;
		} else {
			continue;
		}
		// One TMDB show can have two records (Alchemy of Souls and its season
		// 2 record share 135157). The oldest record is the show; every record
		// sharing the id is kept under "@key" so a person in either cast counts.
		$pid = (int) $r->post_id;
		$map[ $key ] = isset( $map[ $key ] ) ? min( $map[ $key ], $pid ) : $pid;
		$map[ '@' . $key ][] = $pid;
	}
	// Alternate ids: the same production listed under a second TMDB id.
	// phpcs:ignore WordPress.DB
	$alts = $wpdb->get_results(
		"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
		 JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'post' AND p.post_status = 'publish'
		 WHERE pm.meta_key = 'daw_tmdb_alt' AND pm.meta_value <> ''"
	);
	$real = $map;
	foreach ( (array) $alts as $r ) {
		$pid = (int) $r->post_id;
		foreach ( (array) maybe_unserialize( $r->meta_value ) as $v ) {
			$key = daw_cov_alt_key( is_scalar( $v ) ? $v : '' );
			if ( '' === $key || isset( $real[ $key ] ) ) {
				continue;
			}
			$map[ $key ] = isset( $map[ $key ] ) ? min( $map[ $key ], $pid ) : $pid;
			if ( empty( $map[ '@' . $key ] ) || ! in_array( $pid, $map[ '@' . $key ], true ) ) {
				$map[ '@' . $key ][] = $pid;
			}
		}
	}
	set_transient( daw_cov_map_key(), $map, 10 * MINUTE_IN_SECONDS );
	return $map;
}

/**
 * Where one TMDB credit ("tv:83893") sits on the site, for a person record:
 * array( site post id, 'linked' | 'unlinked' | 'missing' ). The map already
 * holds daw_tmdb_alt ids, so a production TMDB lists twice resolves to its one
 * record. Linked means that record's cast (or, for a crew credit, its crew)
 * holds this person.
 */
function daw_cov_work_state( $key, $person_post_id, $as, $map = null ) {
	$map  = null === $map ? daw_cov_site_map() : $map;
	$post = (int) ( $map[ $key ] ?? 0 );
	if ( ! $post ) {
		return array( 0, 'missing' );
	}
	$me = trim( (string) get_post_meta( $person_post_id, 'daw_tmdb_id', true ) );
	foreach ( (array) ( $map[ '@' . $key ] ?? array( $post ) ) as $pp ) {
		$idx  = (string) get_post_meta( (int) $pp, '_daw_cast_idx', true );
		$crew = (string) get_post_meta( (int) $pp, DAW_CREW_ID, true );
		if ( false !== strpos( $idx, ',' . $person_post_id . ':' ) || ( 'cast' !== $as && preg_match( '/(\d+)\s*$/', $me, $m ) && false !== strpos( $crew, ',' . $m[1] . ',' ) ) ) {
			return array( (int) $pp, 'linked' );
		}
	}
	return array( $post, 'unlinked' );
}

/**
 * A stored person report read against today's site: every credit is placed
 * again (so a daw_tmdb_alt written after the scan counts at once, without
 * another TMDB request), credits settled in _daw_ok under
 * "coverage:<tv|movie>:<id>" become 'settled' with their note, and the
 * counts follow. Title reports come back unchanged.
 */
function daw_cov_resolve_report( $post_id, $report ) {
	if ( ! is_array( $report ) || 'person' !== ( $report['kind'] ?? '' ) ) {
		return $report;
	}
	$ok  = get_post_meta( (int) $post_id, '_daw_ok', true );
	$ok  = is_array( $ok ) ? $ok : array();
	$map = daw_cov_site_map();
	$count = array( 'linked' => 0, 'unlinked' => 0, 'missing' => 0 );
	$kinds = array();
	$set   = 0;
	foreach ( (array) ( $report['works'] ?? array() ) as $i => $w ) {
		$key = (string) ( $w['key'] ?? '' );
		if ( '' === $key ) {
			continue;
		}
		list( $post, $state ) = daw_cov_work_state( $key, (int) $post_id, (string) ( $w['as'] ?? '' ), $map );
		$report['works'][ $i ]['post']  = $post;
		$report['works'][ $i ]['state'] = $state;
		unset( $report['works'][ $i ]['note'] );
		$ck = 'coverage:' . $key;
		if ( 'linked' !== $state && isset( $ok[ $ck ] ) ) {
			$report['works'][ $i ]['state'] = 'settled';
			$report['works'][ $i ]['note']  = is_scalar( $ok[ $ck ] ) ? trim( (string) $ok[ $ck ] ) : '';
			++$set;
			continue;
		}
		++$count[ $state ];
		$kinds[ $w['kind'] ][ $state ] = ( $kinds[ $w['kind'] ][ $state ] ?? 0 ) + 1;
	}
	$report['count']       = $count;
	$report['kinds']       = $kinds;
	$report['settled_key'] = $set;
	return $report;
}

/** Is a TMDB title one of the umbrellas that hold many unrelated one-episode works? */
function daw_cov_is_anthology( $title ) {
	return (bool) preg_match(
		'/\b(drama\s+special|drama\s+stage|drama\s+city|best\s+theater|short\s+drama|one\s+act\s+drama|monday\s+kiss|tv\s+cinema|tv\s+novel|human\s+theater|special\s+project)\b/i',
		(string) $title
	) || (bool) preg_match( '/(드라마\s*스페셜|드라마\s*스테이지|베스트\s*극장|단막극)/u', (string) $title );
}

/**
 * Records of one person that might be the other half of a one-episode
 * anthology credit: published films and one-episode dramas in the person's
 * cast, first aired in or after the umbrella's first year, that no TMDB
 * credit of this person is matched to. Suggestions only, never written.
 *
 * @return int[] Post ids, closest year first, at most 4.
 */
function daw_cov_alt_candidates( $person_post_id, $from_year ) {
	global $wpdb;
	$person_post_id = (int) $person_post_id;
	$from_year      = (int) $from_year;
	$taken          = array();
	$rep = get_post_meta( $person_post_id, DAW_COV, true );
	foreach ( (array) ( is_array( $rep ) ? ( $rep['works'] ?? array() ) : array() ) as $w ) {
		if ( ! empty( $w['post'] ) && 'missing' !== ( $w['state'] ?? '' ) ) {
			$taken[ (int) $w['post'] ] = true;
		}
	}
	// phpcs:ignore WordPress.DB
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 200", DAW_CAST_IDX, '%' . $wpdb->esc_like( ',' . $person_post_id . ':' ) . '%' ) );
	$out = array();
	foreach ( (array) $ids as $id ) {
		$id = (int) $id;
		if ( isset( $taken[ $id ] ) || 'publish' !== get_post_status( $id ) ) {
			continue;
		}
		$type = daw_get_type( $id );
		if ( ! ( 'film' === $type || ( 'drama' === $type && (int) get_post_meta( $id, 'daw_episodes', true ) <= 1 ) ) ) {
			continue;
		}
		$start = (int) get_post_meta( $id, 'daw_aired_start', true );
		$year  = $start ? (int) gmdate( 'Y', $start ) : 0;
		if ( ! $year || $year < $from_year ) {
			continue;
		}
		$out[ $id ] = $year - $from_year;
	}
	asort( $out );
	return array_slice( array_keys( $out ), 0, 4 );
}

/** The site record for a TMDB person id, or 0. */
function daw_cov_person_post( $tmdb_id ) {
	$map = daw_cov_site_map();
	return (int) ( $map[ 'person:' . (int) $tmdb_id ] ?? 0 );
}

/** Kind of a TMDB credit, in the site's words. */
function daw_cov_kind( $c ) {
	$media  = (string) ( $c['media_type'] ?? '' );
	$genres = array_map( 'intval', (array) ( $c['genre_ids'] ?? array() ) );
	if ( 'movie' === $media ) {
		return in_array( 16, $genres, true ) ? 'anime' : 'film';
	}
	if ( in_array( 16, $genres, true ) ) {
		return 'anime';
	}
	$char = strtolower( (string) ( $c['character'] ?? '' ) );
	if ( array_intersect( $genres, array( 10764, 10767 ) ) || preg_match( '/^(self|himself|herself|host|mc|guest|narrator)\b/', $char ) ) {
		return 'variety';
	}
	return 'drama';
}

/** Is a credit in the site's scope (Asian screen work, not news or ceremonies)? */
function daw_cov_in_scope( $c ) {
	$langs = array( 'ko', 'ja', 'zh', 'cn', 'th', 'tl', 'ms', 'id', 'vi' );
	$ctry  = array( 'KR', 'JP', 'CN', 'TW', 'HK', 'TH', 'PH', 'SG', 'MY', 'ID', 'VN' );
	$ok    = in_array( (string) ( $c['original_language'] ?? '' ), $langs, true ) || array_intersect( (array) ( $c['origin_country'] ?? array() ), $ctry );
	if ( ! $ok ) {
		return false;
	}
	$genres = array_map( 'intval', (array) ( $c['genre_ids'] ?? array() ) );
	if ( array_intersect( $genres, array( 10763, 99 ) ) ) {
		return false; // news, documentary.
	}
	$name = (string) ( $c['name'] ?? $c['title'] ?? '' );
	return ! preg_match( '/\b(awards?|festival|music bank|inkigayo|show! music core|m countdown|the show|show champion|countdown|gayo daejeon|daesang)\b/i', $name );
}

/** People already in a title's cast, as TMDB person ids. */
function daw_cov_cast_tmdb_ids( $post_id ) {
	$ids = array();
	foreach ( array( 'daw_cast_person', 'daw_cast_main_person', 'daw_cast_supporting_person', 'daw_cast_guest_person' ) as $k ) {
		foreach ( (array) get_post_meta( $post_id, $k, true ) as $pid ) {
			$pid = (int) $pid;
			if ( $pid ) {
				$raw = trim( (string) get_post_meta( $pid, 'daw_tmdb_id', true ) );
				if ( preg_match( '/(\d+)\s*$/', $raw, $m ) ) {
					$ids[ (int) $m[1] ] = $pid;
				}
			}
		}
	}
	return $ids;
}

/**
 * Ask TMDB for one record and store the report. true on success, null when
 * TMDB could not be asked, false when the record is not eligible.
 */
function daw_cov_sync( $post_id ) {
	$post_id = (int) $post_id;
	$ref     = function_exists( 'daw_tmdb_ref' ) ? daw_tmdb_ref( $post_id ) : array();
	if ( ! $ref || 'ok' !== get_post_meta( $post_id, DAW_TMDB_CHECK, true ) ) {
		update_post_meta( $post_id, DAW_COV_AT, time() );
		return false;
	}
	$report = 'person' === $ref['kind'] ? daw_cov_build_person( $post_id, $ref ) : daw_cov_build_title( $post_id, $ref );
	if ( null === $report ) {
		update_post_meta( $post_id, DAW_COV_AT, time() - 6 * DAY_IN_SECONDS ); // retry tomorrow.
		return null;
	}
	$report['at'] = time();
	update_post_meta( $post_id, DAW_COV, $report );
	update_post_meta( $post_id, DAW_COV_AT, time() );
	return true;
}

function daw_cov_build_title( $post_id, $ref ) {
	// A season page, or a series record that has season pages (it stands for
	// season 1), is compared with that season's cast only (madde 65).
	$base = function_exists( 'daw_season_api_base' ) ? daw_season_api_base( $post_id, $ref ) : '';
	$path = '' !== $base ? $base . '/aggregate_credits' : ( 'tv' === $ref['kind'] ? 'tv/' . $ref['id'] . '/aggregate_credits' : 'movie/' . $ref['id'] . '/credits' );
	$b    = daw_tmdb_api_get( $path, array( 'language' => 'en-US' ) );
	if ( ! is_array( $b ) || ! isset( $b['cast'] ) ) {
		return null;
	}
	$in_cast = daw_cov_cast_tmdb_ids( $post_id );
	$people  = array();
	foreach ( (array) $b['cast'] as $c ) {
		$id = (int) ( $c['id'] ?? 0 );
		if ( ! $id || isset( $people[ $id ] ) ) {
			continue;
		}
		$char = isset( $c['roles'][0]['character'] ) ? (string) $c['roles'][0]['character'] : (string) ( $c['character'] ?? '' );
		$post = daw_cov_person_post( $id );
		$people[ $id ] = array(
			'id'    => $id,
			'name'  => mb_substr( (string) ( $c['name'] ?? '' ), 0, 60 ),
			'role'  => mb_substr( $char, 0, 60 ),
			'order' => (int) ( $c['order'] ?? 999 ),
			'eps'   => (int) ( $c['total_episode_count'] ?? 0 ),
			'post'  => $post,
			'state' => isset( $in_cast[ $id ] ) ? 'linked' : ( $post ? 'unlinked' : 'missing' ),
		);
	}
	uasort( $people, static function ( $x, $y ) { return $x['order'] <=> $y['order']; } );

	$jobs = daw_cov_crew_jobs();
	$crew = array();
	foreach ( (array) ( $b['crew'] ?? array() ) as $c ) {
		$id   = (int) ( $c['id'] ?? 0 );
		$list = isset( $c['jobs'] ) ? wp_list_pluck( (array) $c['jobs'], 'job' ) : array( (string) ( $c['job'] ?? '' ) );
		foreach ( $list as $job ) {
			if ( ! $id || ! isset( $jobs[ $job ] ) ) {
				continue;
			}
			$role = $jobs[ $job ];
			if ( 'Producer' === $role && 'variety' !== daw_get_type( $post_id ) ) {
				continue; // a producer is a searched name only on variety shows.
			}
			$crew[ $role . ':' . $id ] = array(
				'id'   => $id,
				'name' => mb_substr( (string) ( $c['name'] ?? '' ), 0, 60 ),
				'job'  => $role,
				'post' => daw_cov_person_post( $id ),
			);
		}
	}
	$crew = array_values( $crew );
	update_post_meta( $post_id, DAW_CREW, $crew );
	update_post_meta( $post_id, DAW_CREW_ID, $crew ? ',' . implode( ',', array_unique( wp_list_pluck( $crew, 'id' ) ) ) . ',' : '' );

	$count = array( 'linked' => 0, 'unlinked' => 0, 'missing' => 0 );
	foreach ( $people as $p ) {
		$count[ $p['state'] ]++;
	}
	return array(
		'kind'   => 'title',
		'total'  => count( $people ),
		'count'  => $count,
		'people' => array_slice( array_values( $people ), 0, 150 ),
		'crew'   => $crew,
	);
}

function daw_cov_build_person( $post_id, $ref ) {
	$b = daw_tmdb_api_get( 'person/' . $ref['id'] . '/combined_credits', array( 'language' => 'en-US' ) );
	if ( ! is_array( $b ) || ! isset( $b['cast'] ) ) {
		return null;
	}
	$map   = daw_cov_site_map();
	$works = array();
	$now   = gmdate( 'Y-m-d' );
	$add   = static function ( $c, $as ) use ( &$works, $map, $post_id, $now ) {
		$media = (string) ( $c['media_type'] ?? '' );
		$id    = (int) ( $c['id'] ?? 0 );
		if ( ! $id || ! in_array( $media, array( 'tv', 'movie' ), true ) || ! daw_cov_in_scope( $c ) ) {
			return;
		}
		$key  = $media . ':' . $id;
		$date = (string) ( $c['first_air_date'] ?? $c['release_date'] ?? '' );
		list( $post, $state ) = daw_cov_work_state( $key, $post_id, $as, $map );
		if ( isset( $works[ $key ] ) && 'cast' !== $as ) {
			$works[ $key ]['as'] .= ', ' . $as;
			return;
		}
		$works[ $key ] = array(
			'key'   => $key,
			'title' => mb_substr( (string) ( $c['name'] ?? $c['title'] ?? '' ), 0, 80 ),
			'kind'  => daw_cov_kind( $c ),
			'year'  => $date ? substr( $date, 0, 4 ) : '',
			'soon'  => '' === $date || $date > $now,
			'role'  => mb_substr( (string) ( $c['character'] ?? '' ), 0, 60 ),
			'as'    => 'cast' === $as ? '' : $as,
			'eps'   => (int) ( $c['episode_count'] ?? 0 ),
			'post'  => $post,
			'state' => $state,
		);
	};
	foreach ( (array) $b['cast'] as $c ) {
		$add( $c, 'cast' );
	}
	$jobs = daw_cov_crew_jobs();
	foreach ( (array) ( $b['crew'] ?? array() ) as $c ) {
		$job = (string) ( $c['job'] ?? '' );
		if ( isset( $jobs[ $job ] ) && 'Producer' !== $jobs[ $job ] ) {
			$add( $c, $jobs[ $job ] );
		}
	}
	uasort(
		$works,
		static function ( $x, $y ) {
			return strcmp( (string) $y['year'], (string) $x['year'] );
		}
	);
	$count = array( 'linked' => 0, 'unlinked' => 0, 'missing' => 0 );
	$kinds = array();
	foreach ( $works as $w ) {
		$count[ $w['state'] ]++;
		$kinds[ $w['kind'] ][ $w['state'] ] = ( $kinds[ $w['kind'] ][ $w['state'] ] ?? 0 ) + 1;
	}
	return array(
		'kind'  => 'person',
		'total' => count( $works ),
		'count' => $count,
		'kinds' => $kinds,
		'works' => array_slice( array_values( $works ), 0, 200 ),
	);
}

/* ============================================================ scheduling === */

/** Records due, oldest first: verified TMDB match, not asked in a week. */
function daw_cov_queue( $limit = 25 ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB
	return array_map(
		'intval',
		(array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s AND c.meta_value = 'ok'
				 LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
				 WHERE p.post_type = 'post' AND p.post_status = 'publish'
				   AND ( a.meta_value IS NULL OR CAST( a.meta_value AS UNSIGNED ) < %d )
				 ORDER BY CAST( COALESCE( a.meta_value, '0' ) AS UNSIGNED ) ASC
				 LIMIT %d",
				DAW_TMDB_CHECK,
				DAW_COV_AT,
				time() - 7 * DAY_IN_SECONDS,
				(int) $limit
			)
		)
	);
}

add_action( 'daw_cov_cron', 'daw_cov_cron_run' );
function daw_cov_cron_run( $limit = 25 ) {
	if ( '' === ( function_exists( 'daw_tmdb_key' ) ? daw_tmdb_key() : '' ) ) {
		return 0;
	}
	$n = 0;
	foreach ( daw_cov_queue( $limit ) as $id ) {
		try {
			daw_cov_sync( $id );
		} catch ( \Throwable $e ) {
			update_post_meta( $id, DAW_COV_AT, time() );
		}
		$n++;
	}
	return $n;
}

add_action( 'init', 'daw_cov_schedule' );
function daw_cov_schedule() {
	if ( ! wp_next_scheduled( 'daw_cov_cron' ) ) {
		wp_schedule_event( time() + 600, 'hourly', 'daw_cov_cron' );
	}
}

/** A changed cast or TMDB match is asked again at the next tick. */
add_action( 'updated_post_meta', 'daw_cov_on_change', 10, 4 );
add_action( 'added_post_meta', 'daw_cov_on_change', 10, 4 );
add_action( 'deleted_post_meta', 'daw_cov_on_alt_deleted', 10, 4 );
function daw_cov_on_alt_deleted( $ids, $post_id, $key ) {
	if ( 'daw_tmdb_alt' === $key ) {
		delete_transient( daw_cov_map_key() );
		daw_cov_reaudit_cast( (int) $post_id );
	}
}

/**
 * The people in a title's cast are audited again shortly: an alternate TMDB id
 * written or removed on the title closes or opens a line on their pages.
 */
function daw_cov_reaudit_cast( $post_id ) {
	if ( ! function_exists( 'daw_ae_prep_store' ) ) {
		return;
	}
	$idx = (string) get_post_meta( (int) $post_id, '_daw_cast_idx', true );
	if ( ! preg_match_all( '/,(\d+):/', $idx, $m ) ) {
		return;
	}
	$n = 0;
	foreach ( array_unique( array_map( 'intval', $m[1] ) ) as $pid ) {
		if ( $pid && ! wp_next_scheduled( 'daw_ae_one', array( $pid ) ) ) {
			wp_schedule_single_event( time() + 60 + 15 * $n++, 'daw_ae_one', array( $pid ) );
		}
	}
}
function daw_cov_on_change( $mid, $post_id, $key, $value = '' ) {
	if ( DAW_TMDB_CHECK === $key || '_daw_cast_idx' === $key ) {
		delete_post_meta( (int) $post_id, DAW_COV_AT );
		delete_transient( daw_cov_map_key() );
	}
	if ( 'daw_tmdb_alt' === $key ) {
		delete_transient( daw_cov_map_key() );
		daw_cov_reaudit_cast( (int) $post_id );
	}
	// A title's cast list changed: the people in it see a stale
	// "has a record but not in its cast" line until their own report is
	// rebuilt, so they are asked again at the next tick too.
	if ( '_daw_cast_idx' === $key && is_string( $value ) && preg_match_all( '/,(\d+):/', $value, $m ) ) {
		foreach ( array_unique( array_map( 'intval', $m[1] ) ) as $pid ) {
			if ( $pid && (int) $pid !== (int) $post_id ) {
				delete_post_meta( $pid, DAW_COV_AT );
			}
		}
	}
}

/* ================================================================ crew ==== */

/**
 * Names for one crew role, as HTML: each name links to its person record
 * when the site has one (matched by TMDB id, then by name).
 */
function daw_crew_html( $post_id, $job, $names ) {
	$names = array_values( array_filter( array_map( 'trim', (array) $names ) ) );
	if ( ! $names ) {
		return '';
	}
	$crew = (array) get_post_meta( $post_id, DAW_CREW, true );
	$norm = static function ( $s ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( (string) $s ) ) );
	};
	$out = array();
	foreach ( $names as $n ) {
		$url = '';
		foreach ( $crew as $c ) {
			if ( is_array( $c ) && ( $c['job'] ?? '' ) === $job && $norm( $c['name'] ?? '' ) === $norm( $n ) ) {
				$pid = daw_cov_person_post( (int) ( $c['id'] ?? 0 ) );
				if ( $pid ) {
					$url = get_permalink( $pid );
				}
				break;
			}
		}
		$out[] = $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $n ) . '</a>' : esc_html( $n );
	}
	return implode( ', ', $out );
}

/** Titles a person made (director, writer, author), for their filmography. */
function daw_crew_titles_of( $person_id ) {
	$raw = trim( (string) get_post_meta( $person_id, 'daw_tmdb_id', true ) );
	if ( ! preg_match( '/(\d+)\s*$/', $raw, $m ) ) {
		return array();
	}
	$tid = (int) $m[1];
	global $wpdb;
	// phpcs:ignore WordPress.DB
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s", DAW_CREW_ID, '%' . $wpdb->esc_like( ',' . $tid . ',' ) . '%' ) );
	$out = array();
	foreach ( $ids as $id ) {
		$id = (int) $id;
		if ( 'publish' !== get_post_status( $id ) ) {
			continue;
		}
		$jobs = array();
		foreach ( (array) get_post_meta( $id, DAW_CREW, true ) as $c ) {
			if ( is_array( $c ) && (int) ( $c['id'] ?? 0 ) === $tid ) {
				$jobs[] = (string) $c['job'];
			}
		}
		$out[ $id ] = array_values( array_unique( $jobs ) );
	}
	return $out;
}
