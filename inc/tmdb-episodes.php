<?php
/**
 * TMDB episode list for a series record: titles, summaries, air dates,
 * runtimes. Stored privately and used two ways (owner, 24 Sep 2026):
 *
 * 1. As a source. The writer reads `_daw_tmdb_eps` and writes each episode
 *    line in the site's own words (madde 64); the TMDB summary is raw
 *    material, never copied.
 * 2. As a check. When TMDB has a summary or a real title for an episode, the
 *    audit no longer accepts "no source" for that column: the line stays red
 *    until the episode is written. "Episodes exist for every show" was the
 *    owner's point after season pages were closed with N/A summaries.
 *
 * Which season: a season page reads its own season; a series record with
 * season pages reads season 1; any other series reads every season (up to
 * eight), in order.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_TMDB_EPS    = '_daw_tmdb_eps';
const DAW_TMDB_EPS_AT = '_daw_tmdb_eps_at';

/** Is a TMDB episode name a real title, or a placeholder such as "Episode 3"? */
function daw_tmdb_eps_real_title( $name ) {
	$name = trim( (string) $name );
	// Placeholders are not titles: "Episode 1", "第1集", "1", "제1회", and
	// the Korean broadcasters' "<show> 1회" / "1화" (Good Doctor had twenty of
	// those, which the audit then demanded as titles).
	return '' !== $name && ! preg_match( '/^(episode|ep\.?|episodio|épisode|folge|第)\s*\d+|^\d+$|^(에피소드|제)\s*\d+|\d+\s*(회|화|話|集|회차)\s*$|\b(episode|ep\.?)\s*\d+\s*$/iu', $name );
}

/** Fetch and store. true on success, null when TMDB could not be asked, false when not a series. */
function daw_tmdb_eps_sync( $post_id ) {
	$post_id = (int) $post_id;
	$ref     = function_exists( 'daw_tmdb_ref' ) ? daw_tmdb_ref( $post_id ) : array();
	if ( ! $ref || 'tv' !== $ref['kind'] || 'ok' !== get_post_meta( $post_id, '_daw_tmdb_img_check', true ) ) {
		update_post_meta( $post_id, DAW_TMDB_EPS_AT, time() );
		return false;
	}
	$paths = array();
	$base  = function_exists( 'daw_season_api_base' ) ? daw_season_api_base( $post_id, $ref ) : '';
	if ( '' !== $base ) {
		$paths[] = $base;
	} else {
		$show = daw_tmdb_api_get( 'tv/' . (int) $ref['id'], array( 'language' => 'en-US' ) );
		if ( null === $show ) {
			return null;
		}
		foreach ( (array) ( is_array( $show ) ? ( $show['seasons'] ?? array() ) : array() ) as $s ) {
			$n = (int) ( $s['season_number'] ?? 0 );
			if ( $n > 0 && (int) ( $s['episode_count'] ?? 0 ) > 0 ) {
				$paths[] = 'tv/' . (int) $ref['id'] . '/season/' . $n;
			}
		}
		$paths = array_slice( $paths, 0, 8 );
	}
	$out = array();
	foreach ( $paths as $p ) {
		$b = daw_tmdb_api_get( $p, array( 'language' => 'en-US' ) );
		if ( null === $b ) {
			return null;
		}
		$eps = (array) ( is_array( $b ) ? ( $b['episodes'] ?? array() ) : array() );
		// Korean shows often have no English summary on TMDB but do have a
		// Korean one. Read the Korean page once per season when any English
		// summary or title is missing: it is source material too, rewritten
		// in the site's own English like everything else (madde 64).
		$ko = array();
		foreach ( $eps as $e ) {
			if ( '' === trim( (string) ( $e['overview'] ?? '' ) ) || ! daw_tmdb_eps_real_title( $e['name'] ?? '' ) ) {
				$kb = daw_tmdb_api_get( $p, array( 'language' => 'ko-KR' ) );
				foreach ( (array) ( is_array( $kb ) ? ( $kb['episodes'] ?? array() ) : array() ) as $k ) {
					$ko[ (int) ( $k['episode_number'] ?? 0 ) ] = $k;
				}
				break;
			}
		}
		foreach ( $eps as $e ) {
			$k     = $ko[ (int) ( $e['episode_number'] ?? 0 ) ] ?? array();
			$out[] = array(
				's'        => (int) ( $e['season_number'] ?? 0 ),
				'e'        => (int) ( $e['episode_number'] ?? 0 ),
				'name'     => mb_substr( (string) ( $e['name'] ?? '' ), 0, 200 ),
				'overview' => mb_substr( (string) ( $e['overview'] ?? '' ), 0, 2000 ),
				'air'      => (string) ( $e['air_date'] ?? '' ),
				'runtime'  => (int) ( $e['runtime'] ?? 0 ),
				'name_ko'  => mb_substr( (string) ( $k['name'] ?? '' ), 0, 200 ),
				'ov_ko'    => mb_substr( (string) ( $k['overview'] ?? '' ), 0, 2000 ),
			);
		}
	}
	update_post_meta( $post_id, DAW_TMDB_EPS, $out );
	update_post_meta( $post_id, DAW_TMDB_EPS_AT, time() );
	return true;
}

/**
 * An overview that is a TMDB placeholder ("Episode 1 Overview", "TBA", a few
 * words) is not a summary and is no source (Love Story in Harvard, 14 Oct
 * 2026: the audit demanded 58 summaries because of that one line). '' for a
 * placeholder, the trimmed text otherwise.
 */
function daw_tmdb_eps_real_overview( $text ) {
	$t = trim( (string) $text );
	if ( '' === $t || mb_strlen( $t ) < 25 || preg_match( '/^(episode|ep\.?)\s*\d+\s*(overview|summary|synopsis)?\.?$|^(tba|tbd|to be announced|coming soon|n\/a)\.?$|^제?\s*\d+\s*(회|화)\s*(줄거리)?$/iu', $t ) ) {
		return '';
	}
	return $t;
}

/** What TMDB holds for the audit: how many episodes, summaries, real titles, runtimes. */
function daw_tmdb_eps_counts( $post_id ) {
	$eps = get_post_meta( (int) $post_id, DAW_TMDB_EPS, true );
	$c   = array( 'eps' => 0, 'overview' => 0, 'title' => 0, 'runtime' => 0, 'air' => 0 );
	// An overview repeated word for word across several episodes is a season
	// blurb or a location tag ("in Papua New Guinea"), not an episode summary
	// (Law of the Jungle, 25 Sep 2026). Only overviews that belong to one
	// episode count as a source.
	$seen = array();
	foreach ( is_array( $eps ) ? $eps : array() as $e ) {
		foreach ( array( 'overview', 'ov_ko' ) as $k ) {
			$t = daw_tmdb_eps_real_overview( $e[ $k ] ?? '' );
			if ( '' !== $t ) {
				$seen[ $k ][ $t ] = ( $seen[ $k ][ $t ] ?? 0 ) + 1;
			}
		}
	}
	foreach ( is_array( $eps ) ? $eps : array() as $e ) {
		$c['eps']++;
		$own = false;
		foreach ( array( 'overview', 'ov_ko' ) as $k ) {
			$t = daw_tmdb_eps_real_overview( $e[ $k ] ?? '' );
			if ( '' !== $t && 1 === ( $seen[ $k ][ $t ] ?? 0 ) ) {
				$own = true;
			}
		}
		$c['overview'] += $own ? 1 : 0;
		$c['title']    += ( daw_tmdb_eps_real_title( $e['name'] ?? '' ) || daw_tmdb_eps_real_title( $e['name_ko'] ?? '' ) ) ? 1 : 0;
		$c['runtime']  += (int) ( $e['runtime'] ?? 0 ) > 0 ? 1 : 0;
		// Only dates that have passed: a future date on TMDB is a plan, often
		// a guess, and the record waits for it (1.75.70).
		$air           = (string) ( $e['air'] ?? '' );
		$c['air']      += preg_match( '/^\d{4}-\d{2}-\d{2}$/', $air ) && strtotime( $air . ' 23:59:59' ) <= time() ? 1 : 0;
	}
	return $c;
}
