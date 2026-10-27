<?php
/**
 * Two-source fill: a fact goes into a record by itself only when TMDB and
 * Wikidata say the same thing.
 *
 * WHY TWO SOURCES
 * ---------------
 * TMDB is right about most things and wrong about some (it credits every
 * actor of a Korean drama with every episode, for one). A value that two
 * independent databases agree on is safe to write without anyone reading it;
 * a value only one of them holds is a suggestion; a value they disagree on,
 * or a value already in the record that both contradict, is a job for a
 * person. That is the whole rule, and it costs no AI tokens.
 *
 * WHAT IT WRITES
 * --------------
 * Only empty fields, never over an editor's value. Every written field is
 * recorded in `_daw_src` ("tmdb+wikidata", or "wikidata" for the English
 * Wikipedia address, which Wikidata holds by construction). Suggestions go
 * to `_daw_vf_suggest`, disagreements and contradictions to `_daw_vf_diff`;
 * the audit shows both under madde 62 so a researcher checks only those.
 *
 * Only stable facts are handled: dates, episode count, native name, IMDb and
 * Wikipedia addresses, director, screenwriter, production company; for people
 * birth and death date, gender, birthplace, native name. Text is never
 * written here: synopses, biographies and answers are the site's own work.
 *
 * Also: a person who has a record and a verified TMDB match, credited on a
 * verified title but missing from its cast, is added to the untiered cast
 * ("Also in the cast"). Billing is never guessed; an editor tiers them.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

const DAW_VF_AT      = '_daw_vf_at';
const DAW_VF_SRC     = '_daw_src';
const DAW_VF_SUGGEST = '_daw_vf_suggest';
const DAW_VF_DIFF    = '_daw_vf_diff';

/** Normalise names for comparison: case, spaces, hyphens, accents. */
function daw_vf_norm( $s ) {
	return preg_replace( '/[^\p{L}\p{N}]/u', '', mb_strtolower( remove_accents( trim( (string) $s ) ) ) );
}

/** A Wikidata time value ("+2014-11-12T00:00:00Z", precision 11) as Y-m-d, or ''. */
function daw_vf_wd_date( $entity, $prop ) {
	foreach ( $entity['claims'][ $prop ] ?? array() as $c ) {
		if ( 'deprecated' === ( $c['rank'] ?? '' ) ) {
			continue;
		}
		$v = $c['mainsnak']['datavalue']['value'] ?? null;
		if ( is_array( $v ) && isset( $v['time'] ) && (int) ( $v['precision'] ?? 0 ) >= 11 && preg_match( '/^\+?(\d{4}-\d{2}-\d{2})/', $v['time'], $m ) ) {
			return $m[1];
		}
	}
	return '';
}

/** A Wikidata quantity (episodes, height) as a number, or 0. */
function daw_vf_wd_amount( $entity, $prop ) {
	foreach ( $entity['claims'][ $prop ] ?? array() as $c ) {
		$v = $c['mainsnak']['datavalue']['value'] ?? null;
		if ( is_array( $v ) && isset( $v['amount'] ) ) {
			return (float) ltrim( (string) $v['amount'], '+' );
		}
	}
	return 0;
}

/** Plain string claims (IMDb id, native name text). */
function daw_vf_wd_strings( $entity, $prop ) {
	$out = array();
	foreach ( $entity['claims'][ $prop ] ?? array() as $c ) {
		$v = $c['mainsnak']['datavalue']['value'] ?? null;
		if ( is_string( $v ) ) {
			$out[] = $v;
		} elseif ( is_array( $v ) && isset( $v['text'] ) ) {
			$out[] = (string) $v['text'];
		}
	}
	return $out;
}

/** English (or Korean) labels of the items a property points at: one extra call per record. */
function daw_vf_wd_labels( array $qids ) {
	$qids = array_values( array_unique( array_filter( $qids ) ) );
	if ( ! $qids || ! function_exists( 'daw_wd_get' ) ) {
		return array();
	}
	$b   = daw_wd_get( array( 'action' => 'wbgetentities', 'ids' => implode( '|', array_slice( $qids, 0, 45 ) ), 'props' => 'labels', 'languages' => 'en' ) );
	$out = array();
	foreach ( (array) ( $b['entities'] ?? array() ) as $q => $e ) {
		$out[ $q ] = (string) ( $e['labels']['en']['value'] ?? '' );
	}
	return $out;
}

/**
 * Compare one record with TMDB and Wikidata and act on the result.
 * Returns true when it ran, null when a source could not be reached.
 */
function daw_vf_run( $post_id ) {
	$post_id = (int) $post_id;
	$ref     = function_exists( 'daw_tmdb_ref' ) ? daw_tmdb_ref( $post_id ) : array();
	if ( ! $ref || 'ok' !== get_post_meta( $post_id, DAW_TMDB_CHECK, true ) ) {
		update_post_meta( $post_id, DAW_VF_AT, time() );
		return false;
	}
	$kind = $ref['kind'];
	$tm   = daw_tmdb_api_get( $kind . '/' . $ref['id'], array( 'language' => 'en-US', 'append_to_response' => 'external_ids' . ( 'person' === $kind ? '' : ( 'tv' === $kind ? ',credits,aggregate_credits' : ',credits' ) ) ) );
	if ( ! is_array( $tm ) || empty( $tm['id'] ) ) {
		update_post_meta( $post_id, DAW_VF_AT, time() - 6 * DAY_IN_SECONDS );
		return null;
	}
	$qid = (string) ( $tm['external_ids']['wikidata_id'] ?? '' );
	$wd  = array();
	if ( preg_match( '/^Q\d+$/', $qid ) && function_exists( 'daw_wd_get' ) ) {
		$b  = daw_wd_get( array( 'action' => 'wbgetentities', 'ids' => $qid, 'props' => 'claims|labels|sitelinks', 'languages' => 'en|ko|ja|zh', 'sitefilter' => 'enwiki' ) );
		$wd = (array) ( $b['entities'][ $qid ] ?? array() );
	}

	// pairs: field => [ tmdb value, wikidata value, how to compare ]
	$pairs = 'person' === $kind ? daw_vf_person_pairs( $tm, $wd ) : daw_vf_title_pairs( $kind, $tm, $wd, $post_id );

	// Seasons (madde 65). A season page and a series record with season pages
	// (season 1) take dates, episodes and makers from TMDB's season and from
	// the season's own Wikidata item, never from the series totals.
	$base = ( 'tv' === $kind && function_exists( 'daw_season_api_base' ) ) ? daw_season_api_base( $post_id, $ref ) : '';
	if ( '' !== $base ) {
		$sb = daw_tmdb_api_get( $base, array( 'language' => 'en-US', 'append_to_response' => 'external_ids,aggregate_credits' ) );
		if ( ! is_array( $sb ) ) {
			update_post_meta( $post_id, DAW_VF_AT, time() - 6 * DAY_IN_SECONDS );
			return null;
		}
		$sq  = (string) ( $sb['external_ids']['wikidata_id'] ?? '' );
		$swd = array();
		if ( preg_match( '/^Q\d+$/', $sq ) && function_exists( 'daw_wd_get' ) ) {
			$b   = daw_wd_get( array( 'action' => 'wbgetentities', 'ids' => $sq, 'props' => 'claims|labels|sitelinks', 'languages' => 'en|ko|ja|zh', 'sitefilter' => 'enwiki' ) );
			$swd = (array) ( $b['entities'][ $sq ] ?? array() );
		}
		$sp = daw_vf_season_pairs( $sb, $swd );
		if ( ! empty( $ref['season'] ) ) {
			$pairs = $sp;   // the series' own names, IMDb and companies are not this page's.
			$wd    = $swd;  // nor is its Wikipedia article, unless the season has one.
		} else {
			unset( $pairs['daw_aired_end'] );
			$pairs = array_merge( $pairs, $sp );
		}
	}

	$src     = get_post_meta( $post_id, DAW_VF_SRC, true );
	$src     = is_array( $src ) ? $src : array();
	$suggest = array();
	$diff    = array();
	$wrote   = 0;
	foreach ( $pairs as $field => $p ) {
		list( $t, $w, $write ) = $p;
		$has_t = '' !== (string) ( is_array( $t ) ? implode( '', $t ) : $t );
		$has_w = '' !== (string) ( is_array( $w ) ? implode( '', $w ) : $w );
		$agree = $has_t && $has_w && daw_vf_same( $t, $w );
		$cur   = get_post_meta( $post_id, $field, true );
		$empty = '' === trim( (string) ( is_array( $cur ) ? implode( '', $cur ) : $cur ) );

		if ( $agree ) {
			if ( $empty ) {
				update_post_meta( $post_id, $field, $write );
				$src[ $field ] = 'tmdb+wikidata';
				$wrote++;
			} elseif ( ! daw_vf_same( $cur, $write ) ) {
				$diff[ $field ] = array( 'record' => daw_vf_show( $cur ), 'tmdb' => daw_vf_show( $t ), 'wikidata' => daw_vf_show( $w ), 'kind' => 'record_contradicted' );
			} else {
				$src[ $field ] = $src[ $field ] ?? 'tmdb+wikidata (doğrulandı)';
			}
		} elseif ( $has_t && $has_w ) {
			$diff[ $field ] = array( 'record' => daw_vf_show( $cur ), 'tmdb' => daw_vf_show( $t ), 'wikidata' => daw_vf_show( $w ), 'kind' => 'sources_disagree' );
		} elseif ( ( $has_t || $has_w ) && $empty ) {
			$suggest[ $field ] = array( 'value' => daw_vf_show( $has_t ? $t : $w ), 'src' => $has_t ? 'tmdb' : 'wikidata' );
		}
	}

	// English Wikipedia address: Wikidata's own sitelink, one source by design.
	$enwiki = (string) ( $wd['sitelinks']['enwiki']['title'] ?? '' );
	if ( '' !== $enwiki && '' === trim( (string) get_post_meta( $post_id, 'daw_link_wikipedia', true ) ) ) {
		update_post_meta( $post_id, 'daw_link_wikipedia', 'https://en.wikipedia.org/wiki/' . rawurlencode( str_replace( ' ', '_', $enwiki ) ) );
		$src['daw_link_wikipedia'] = 'wikidata';
		$wrote++;
	}

	update_post_meta( $post_id, DAW_VF_SRC, $src );
	update_post_meta( $post_id, DAW_VF_SUGGEST, $suggest );
	update_post_meta( $post_id, DAW_VF_DIFF, $diff );
	update_post_meta( $post_id, DAW_VF_AT, time() );
	update_post_meta( $post_id, '_daw_vf_wd', $qid );

	if ( 'person' !== $kind ) {
		daw_vf_autocast( $post_id );
	}
	return true;
}

function daw_vf_same( $a, $b ) {
	$a = is_array( $a ) ? $a : array( $a );
	$b = is_array( $b ) ? $b : array( $b );
	$na = array_filter( array_map( 'daw_vf_norm', $a ) );
	$nb = array_filter( array_map( 'daw_vf_norm', $b ) );
	sort( $na );
	sort( $nb );
	return $na && $na === $nb;
}

function daw_vf_show( $v ) {
	if ( is_array( $v ) ) {
		return implode( ', ', array_map( 'strval', $v ) );
	}
	if ( is_numeric( $v ) && (int) $v > 100000000 ) {
		return gmdate( 'Y-m-d', (int) $v );
	}
	return (string) $v;
}

/** TMDB and Wikidata side by side for a title. Values in the record's own format. */
function daw_vf_title_pairs( $kind, $tm, $wd, $post_id ) {
	$ts = static function ( $d ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d ) ? (int) strtotime( $d . ' 00:00:00 UTC' ) : '';
	};
	$out = array();

	$t_start = 'tv' === $kind ? ( $tm['first_air_date'] ?? '' ) : ( $tm['release_date'] ?? '' );
	$w_start = $wd ? ( daw_vf_wd_date( $wd, 'P580' ) ?: daw_vf_wd_date( $wd, 'P577' ) ) : '';
	$out['daw_aired_start'] = array( $t_start, $w_start, $ts( $t_start ) );

	if ( 'tv' === $kind && 'Ended' === ( $tm['status'] ?? '' ) ) {
		$t_end = (string) ( $tm['last_air_date'] ?? '' );
		$w_end = $wd ? daw_vf_wd_date( $wd, 'P582' ) : '';
		$out['daw_aired_end'] = array( $t_end, $w_end, $ts( $t_end ) );
	}
	if ( 'tv' === $kind ) {
		$te = (int) ( $tm['number_of_episodes'] ?? 0 );
		$we = $wd ? (int) daw_vf_wd_amount( $wd, 'P1113' ) : 0;
		$out['daw_episodes'] = array( $te ? (string) $te : '', $we ? (string) $we : '', $te );

		// Seasons, specials (season 0) not counted. Wikidata rarely states a
		// season count for a one-season drama, so a single season is also
		// taken as agreed when Wikidata's own episode total equals TMDB's.
		$ts_n = 0;
		foreach ( (array) ( $tm['seasons'] ?? array() ) as $s ) {
			if ( (int) ( $s['season_number'] ?? 0 ) > 0 && (int) ( $s['episode_count'] ?? 0 ) > 0 ) {
				$ts_n++;
			}
		}
		$ws_n = $wd ? (int) daw_vf_wd_amount( $wd, 'P2437' ) : 0;
		if ( ! $ws_n && 1 === $ts_n && $te && $te === $we ) {
			$ws_n = 1;
		}
		$out['daw_seasons'] = array( $ts_n ? (string) $ts_n : '', $ws_n ? (string) $ws_n : '', $ts_n );
	}

	$orig   = (string) ( $tm['original_name'] ?? $tm['original_title'] ?? '' );
	$native = '';
	foreach ( array( 'ko', 'ja', 'zh' ) as $l ) {
		if ( ! empty( $wd['labels'][ $l ]['value'] ) ) {
			$native = (string) $wd['labels'][ $l ]['value'];
			break;
		}
	}
	if ( preg_match( '/[^\x00-\x7F]/u', $orig ) ) {
		$out['daw_native_title'] = array( $orig, $native, $orig );
	}

	$imdb_t = (string) ( $tm['external_ids']['imdb_id'] ?? $tm['imdb_id'] ?? '' );
	$imdb_w = $wd ? implode( '', array_slice( daw_vf_wd_strings( $wd, 'P345' ), 0, 1 ) ) : '';
	$out['daw_link_imdb'] = array( $imdb_t, $imdb_w, $imdb_t ? 'https://www.imdb.com/title/' . $imdb_t . '/' : '' );

	// People and companies: TMDB names against Wikidata labels.
	$crew = (array) ( $tm['credits']['crew'] ?? array() );
	$pick = static function ( $jobs ) use ( $crew ) {
		$n = array();
		foreach ( $crew as $c ) {
			if ( in_array( $c['job'] ?? '', $jobs, true ) ) {
				$n[] = (string) $c['name'];
			}
		}
		return array_values( array_unique( $n ) );
	};
	$t_dir  = $pick( array( 'Director' ) );
	$t_wri  = $pick( array( 'Screenplay', 'Writer' ) );

	// A series credits its directors and writers per episode, so the
	// series-level list is often empty. The aggregate list counts episodes per
	// job; the main names are those on at least a quarter of the episodes,
	// which leaves out a one-episode guest director.
	if ( 'tv' === $kind ) {
		$agg  = (array) ( $tm['aggregate_credits']['crew'] ?? array() );
		$main = static function ( $jobs ) use ( $agg ) {
			$eps = array();
			foreach ( $agg as $c ) {
				foreach ( (array) ( $c['jobs'] ?? array() ) as $j ) {
					if ( in_array( $j['job'] ?? '', $jobs, true ) ) {
						$eps[ (string) $c['name'] ] = max( $eps[ (string) $c['name'] ] ?? 0, (int) ( $j['episode_count'] ?? 0 ) );
					}
				}
			}
			if ( ! $eps ) {
				return array();
			}
			$top = max( $eps );
			return array_keys( array_filter( $eps, static function ( $n ) use ( $top ) {
				return $n * 4 >= $top;
			} ) );
		};
		$t_dir = $main( array( 'Director' ) ) ?: $t_dir;
		$t_wri = $main( array( 'Screenplay', 'Writer', 'Series Composition' ) ) ?: $t_wri;
	}
	$t_prod = array_values( array_filter( wp_list_pluck( (array) ( $tm['production_companies'] ?? array() ), 'name' ) ) );
	$qids   = array();
	foreach ( array( 'P57', 'P58', 'P272' ) as $p ) {
		$qids = array_merge( $qids, $wd ? (array) daw_wd_values( $wd, $p ) : array() );
	}
	$labels = $qids ? daw_vf_wd_labels( $qids ) : array();
	$lab    = static function ( $p ) use ( $wd, $labels ) {
		$o = array();
		foreach ( $wd ? (array) daw_wd_values( $wd, $p ) : array() as $q ) {
			if ( ! empty( $labels[ $q ] ) ) {
				$o[] = $labels[ $q ];
			}
		}
		return $o;
	};
	$out['daw_director']     = array( $t_dir, $lab( 'P57' ), $t_dir );
	$out['daw_screenwriter'] = array( $t_wri, $lab( 'P58' ), $t_wri );
	$out['daw_production']   = array( $t_prod, $lab( 'P272' ), $t_prod );
	return $out;
}

/**
 * One season's dates, episode count and makers: TMDB's season against the
 * season's own Wikidata item (madde 65).
 */
function daw_vf_season_pairs( $sb, $wd ) {
	$ts = static function ( $d ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d ) ? (int) strtotime( $d . ' 00:00:00 UTC' ) : '';
	};
	$out   = array();
	$eps   = array_values( array_filter( (array) ( $sb['episodes'] ?? array() ), static function ( $e ) {
		return (int) ( $e['episode_number'] ?? 0 ) > 0;
	} ) );
	$start = (string) ( $sb['air_date'] ?? '' );
	$out['daw_aired_start'] = array( $start, $wd ? ( daw_vf_wd_date( $wd, 'P580' ) ?: daw_vf_wd_date( $wd, 'P577' ) ) : '', $ts( $start ) );

	// The last date only once every episode has one and it is in the past.
	$dates = array_map( static function ( $e ) {
		return (string) ( $e['air_date'] ?? '' );
	}, $eps );
	if ( $eps && ! in_array( '', $dates, true ) ) {
		$last = max( $dates );
		if ( $ts( $last ) && $ts( $last ) < time() ) {
			$out['daw_aired_end'] = array( $last, $wd ? daw_vf_wd_date( $wd, 'P582' ) : '', $ts( $last ) );
		}
	}
	$te = count( $eps );
	$we = $wd ? (int) daw_vf_wd_amount( $wd, 'P1113' ) : 0;
	$out['daw_episodes'] = array( $te ? (string) $te : '', $we ? (string) $we : '', $te );

	$agg  = (array) ( $sb['aggregate_credits']['crew'] ?? array() );
	$main = static function ( $jobs ) use ( $agg ) {
		$n = array();
		foreach ( $agg as $c ) {
			foreach ( (array) ( $c['jobs'] ?? array() ) as $j ) {
				if ( in_array( $j['job'] ?? '', $jobs, true ) ) {
					$n[ (string) $c['name'] ] = max( $n[ (string) $c['name'] ] ?? 0, (int) ( $j['episode_count'] ?? 0 ) );
				}
			}
		}
		if ( ! $n ) {
			return array();
		}
		$top = max( $n );
		return array_keys( array_filter( $n, static function ( $x ) use ( $top ) {
			return $x * 4 >= $top;
		} ) );
	};
	$qids = array();
	foreach ( array( 'P57', 'P58' ) as $p ) {
		$qids = array_merge( $qids, $wd ? (array) daw_wd_values( $wd, $p ) : array() );
	}
	$labels = $qids ? daw_vf_wd_labels( $qids ) : array();
	$lab    = static function ( $p ) use ( $wd, $labels ) {
		$o = array();
		foreach ( $wd ? (array) daw_wd_values( $wd, $p ) : array() as $q ) {
			if ( ! empty( $labels[ $q ] ) ) {
				$o[] = $labels[ $q ];
			}
		}
		return $o;
	};
	$t_dir = $main( array( 'Director' ) );
	$t_wri = $main( array( 'Screenplay', 'Writer', 'Series Composition' ) );
	$out['daw_director']     = array( $t_dir, $lab( 'P57' ), $t_dir );
	$out['daw_screenwriter'] = array( $t_wri, $lab( 'P58' ), $t_wri );
	return $out;
}

function daw_vf_person_pairs( $tm, $wd ) {
	$ts = static function ( $d ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d ) ? (int) strtotime( $d . ' 00:00:00 UTC' ) : '';
	};
	$out = array();
	$tb  = (string) ( $tm['birthday'] ?? '' );
	$out['daw_born'] = array( $tb, $wd ? daw_vf_wd_date( $wd, 'P569' ) : '', $ts( $tb ) );
	$td  = (string) ( $tm['deathday'] ?? '' );
	if ( '' !== $td || ( $wd && daw_vf_wd_date( $wd, 'P570' ) ) ) {
		$out['daw_died'] = array( $td, $wd ? daw_vf_wd_date( $wd, 'P570' ) : '', $ts( $td ) );
	}
	$g  = (int) ( $tm['gender'] ?? 0 );
	$tg = 1 === $g ? 'female' : ( 2 === $g ? 'male' : '' );
	$wg = '';
	$wv = $wd ? (array) daw_wd_values( $wd, 'P21' ) : array();
	if ( in_array( 'Q6581072', $wv, true ) ) {
		$wg = 'female';
	} elseif ( in_array( 'Q6581097', $wv, true ) ) {
		$wg = 'male';
	}
	$out['daw_gender'] = array( $tg, $wg, $tg );

	$native_t = '';
	foreach ( (array) ( $tm['also_known_as'] ?? array() ) as $aka ) {
		if ( preg_match( '/[\x{3040}-\x{30ff}\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{ac00}-\x{d7af}]/u', (string) $aka ) ) {
			$native_t = (string) $aka;
			break;
		}
	}
	$native_w = '';
	if ( $wd ) {
		$names    = daw_vf_wd_strings( $wd, 'P1559' );
		$native_w = $names ? $names[0] : (string) ( $wd['labels']['ko']['value'] ?? $wd['labels']['ja']['value'] ?? '' );
	}
	$out['daw_native_name'] = array( $native_t, $native_w, $native_t );

	$imdb_t = (string) ( $tm['external_ids']['imdb_id'] ?? $tm['imdb_id'] ?? '' );
	$imdb_w = $wd ? implode( '', array_slice( daw_vf_wd_strings( $wd, 'P345' ), 0, 1 ) ) : '';
	$out['daw_link_imdb'] = array( $imdb_t, $imdb_w, $imdb_t ? 'https://www.imdb.com/name/' . $imdb_t . '/' : '' );
	return $out;
}

/**
 * Put people who have a verified record into the untiered cast of a verified
 * title they are credited on. Uses the coverage report; never guesses billing.
 */
function daw_vf_autocast( $post_id ) {
	$cov = get_post_meta( $post_id, DAW_COV, true );
	if ( ! is_array( $cov ) || 'title' !== ( $cov['kind'] ?? '' ) ) {
		return 0;
	}
	$flat  = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $post_id, 'daw_cast_person', true ) ) ) );
	$added = get_post_meta( $post_id, '_daw_autocast', true );
	$added = is_array( $added ) ? $added : array();
	$n     = 0;
	foreach ( (array) $cov['people'] as $p ) {
		$pid = (int) ( $p['post'] ?? 0 );
		if ( 'unlinked' !== ( $p['state'] ?? '' ) || ! $pid || in_array( $pid, $flat, true ) ) {
			continue;
		}
		if ( 'ok' !== get_post_meta( $pid, DAW_TMDB_CHECK, true ) ) {
			continue; // the person's own TMDB match must be verified too.
		}
		$flat[]        = $pid;
		$added[ $pid ] = array( 'at' => time(), 'role' => (string) ( $p['role'] ?? '' ) );
		$n++;
	}
	if ( $n ) {
		update_post_meta( $post_id, 'daw_cast_person', array_values( array_unique( $flat ) ) );
		update_post_meta( $post_id, '_daw_autocast', $added );
		if ( function_exists( 'daw_reindex_cast' ) ) {
			daw_reindex_cast( $post_id );
		}
		delete_post_meta( $post_id, DAW_COV_AT ); // re-scan next tick.
	}
	return $n;
}

/* ============================================================ scheduling === */

function daw_vf_queue( $limit = 20 ) {
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
				DAW_VF_AT,
				time() - 30 * DAY_IN_SECONDS,
				(int) $limit
			)
		)
	);
}

add_action( 'daw_vf_cron', 'daw_vf_cron_run' );
function daw_vf_cron_run( $limit = 20 ) {
	if ( '' === ( function_exists( 'daw_tmdb_key' ) ? daw_tmdb_key() : '' ) ) {
		return 0;
	}
	$n = 0;
	foreach ( daw_vf_queue( $limit ) as $id ) {
		try {
			daw_vf_run( $id );
		} catch ( \Throwable $e ) {
			update_post_meta( $id, DAW_VF_AT, time() );
		}
		$n++;
	}
	return $n;
}

add_action( 'init', 'daw_vf_schedule' );
function daw_vf_schedule() {
	if ( ! wp_next_scheduled( 'daw_vf_cron' ) ) {
		wp_schedule_event( time() + 900, 'hourly', 'daw_vf_cron' );
	}
}
