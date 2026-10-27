<?php
/**
 * The source store: every free source read once, kept on the record.
 *
 * WHY
 * ---
 * Every agent that finishes a record used to open the same pages again: the
 * Wikipedia article in English, the one in Korean, the Wikidata item, the TMDB
 * page it could not read. Each time the whole page went through the model to
 * answer three questions (owner, 24 Sep 2026: "pull everything once, every
 * Asian language, keep it on the record, spend as few tokens as possible").
 *
 * WHAT IS KEPT (private meta, never on the page, not in any fingerprint)
 * --------------------------------------------------------------------
 *   _daw_src_tmdb      TMDB, compact: every title and overview TMDB has in an
 *                      Asian language and English, dates, runtime, networks,
 *                      companies, keywords, ratings by country, cast with
 *                      characters, the crew that matters, external ids; for a
 *                      person the biographies, other names and every credit
 *   _daw_src_wd        the Wikidata item: labels, aliases, sitelinks, every
 *                      claim with the label of what it points at
 *   _daw_src_wiki_XX   one Wikipedia article per language that has one, in
 *                      English and every Asian language the site covers
 *                      (ko, ja, zh, zh_yue, th, tl, vi, id, ms): title,
 *                      permanent url, revision, wikitext (infobox, episode and
 *                      ratings tables, references with their urls)
 *   _daw_src_yt, _daw_src_kakao   see api-keys.php (when keys are saved)
 *   _daw_src_at        when it was read; _daw_src_fail when it last failed
 *
 * WHICH ITEM
 * ----------
 * Wikidata: the item TMDB names for the record (external_ids), the one source
 * verification stored, the links importer's, or the item behind the record's
 * English Wikipedia link. Guides take the Wikipedia articles in their own
 * source list. No item, no guess.
 *
 * WHAT IT IS NOT
 * --------------
 * Not a crawl. Only APIs made for this use. Sites whose robots rules say no
 * (IMDb, JustWatch, Namuwiki and the rest) are not read. KOBIS is asked live
 * and never stored (its terms). The store is raw material: the site's text is
 * still written in its own words (madde 64), and a fact still needs its second
 * source (K4, madde 62).
 *
 * THE FIRST VERSION (1.43.0) STORED NOTHING
 * ----------------------------------------
 * It asked Wikidata with maxlag=5. maxlag is for bots that edit: when the query
 * service lags (it was 407 seconds behind that day) every read is refused, and
 * the first record in the queue failed forever while the step stopped on it.
 * Reads no longer send maxlag, a failed record waits an hour instead of
 * blocking the queue, and the last error is shown on the screen.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_SRC_AT    = '_daw_src_at';
const DAW_SRC_FAIL  = '_daw_src_fail';
const DAW_SRC_WD    = '_daw_src_wd';
const DAW_SRC_TMDB  = '_daw_src_tmdb';
const DAW_SRC_WIKI  = '_daw_src_wiki_';
const DAW_SRC_MAX   = 60000;  // characters of wikitext kept per article
const DAW_SRC_SLICE = 10;     // records per step at most
const DAW_SRC_AGENT = 'DiscoverAsianWorld/1.0 (https://discoverasianworld.com; source store)';

/** Wikipedia editions kept: meta suffix => [ wikidata site id, host ]. */
function daw_src_wikis() {
	return array(
		'en'     => array( 'enwiki', 'en.wikipedia.org' ),
		'ko'     => array( 'kowiki', 'ko.wikipedia.org' ),
		'ja'     => array( 'jawiki', 'ja.wikipedia.org' ),
		'zh'     => array( 'zhwiki', 'zh.wikipedia.org' ),
		'zh_yue' => array( 'zh_yuewiki', 'zh-yue.wikipedia.org' ),
		'th'     => array( 'thwiki', 'th.wikipedia.org' ),
		'tl'     => array( 'tlwiki', 'tl.wikipedia.org' ),
		'vi'     => array( 'viwiki', 'vi.wikipedia.org' ),
		'id'     => array( 'idwiki', 'id.wikipedia.org' ),
		'ms'     => array( 'mswiki', 'ms.wikipedia.org' ),
	);
}

/** Languages whose TMDB translations are kept (ISO 639-1). */
function daw_src_tmdb_langs() {
	return array( 'en', 'ko', 'ja', 'zh', 'cn', 'th', 'tl', 'vi', 'id', 'ms' );
}

/** Record types the store covers. */
function daw_src_types() {
	$t = array( 'drama', 'film', 'variety', 'anime', 'person', 'group' );
	if ( function_exists( 'daw_guide_types' ) ) {
		$t = array_merge( $t, (array) daw_guide_types() );
	}
	return array_values( array_unique( $t ) );
}

/** Remember why the last call failed, for the screen. */
function daw_src_error( $what ) {
	update_option( 'daw_src_last_err', array( 'at' => time(), 'what' => mb_substr( (string) $what, 0, 300 ) ), false );
}

/** One call to a Wikimedia API. null when it could not be asked. */
function daw_src_api( $host, array $args ) {
	$args = array_merge( array( 'format' => 'json', 'formatversion' => 2 ), $args );
	$res  = wp_remote_get(
		add_query_arg( array_map( 'rawurlencode', $args ), 'https://' . $host . '/w/api.php' ),
		array(
			'timeout'    => 25,
			'user-agent' => DAW_SRC_AGENT,
			'headers'    => array( 'Accept' => 'application/json' ),
		)
	);
	if ( is_wp_error( $res ) ) {
		daw_src_error( $host . ': ' . $res->get_error_message() );
		return null;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$b    = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || ! is_array( $b ) ) {
		daw_src_error( $host . ': HTTP ' . $code );
		return null;
	}
	if ( ! empty( $b['error'] ) ) {
		daw_src_error( $host . ': ' . ( $b['error']['code'] ?? '' ) . ' ' . ( $b['error']['info'] ?? '' ) );
		return null;
	}
	return $b;
}

/* ================================================================ which === */

/** The record's Wikidata item, found once and remembered. '' when there is none. */
function daw_src_qid( $post_id ) {
	$post_id = (int) $post_id;
	foreach ( array( '_daw_src_qid', '_daw_vf_wd', '_daw_wikidata' ) as $k ) {
		$q = trim( (string) get_post_meta( $post_id, $k, true ) );
		if ( preg_match( '/^Q\d+$/', $q ) ) {
			return $q;
		}
	}
	// TMDB names the item for most titles and people.
	$ref = function_exists( 'daw_tmdb_ref' ) ? daw_tmdb_ref( $post_id ) : array();
	if ( $ref && 'ok' === get_post_meta( $post_id, '_daw_tmdb_img_check', true ) && function_exists( 'daw_tmdb_api_get' ) ) {
		$x = daw_tmdb_api_get( $ref['kind'] . '/' . (int) $ref['id'] . '/external_ids' );
		$q = is_array( $x ) ? (string) ( $x['wikidata_id'] ?? '' ) : '';
		if ( preg_match( '/^Q\d+$/', $q ) ) {
			update_post_meta( $post_id, '_daw_src_qid', $q );
			return $q;
		}
	}
	// The item behind the record's own English Wikipedia link.
	$wp = (string) get_post_meta( $post_id, 'daw_link_wikipedia', true );
	if ( preg_match( '#^https?://en\.wikipedia\.org/wiki/(.+)$#', $wp, $m ) ) {
		$b = daw_src_api( 'www.wikidata.org', array( 'action' => 'wbgetentities', 'sites' => 'enwiki', 'titles' => str_replace( '_', ' ', rawurldecode( $m[1] ) ), 'props' => 'info' ) );
		foreach ( (array) ( $b['entities'] ?? array() ) as $id => $e ) {
			if ( preg_match( '/^Q\d+$/', (string) $id ) && ! isset( $e['missing'] ) ) {
				update_post_meta( $post_id, '_daw_src_qid', $id );
				return $id;
			}
		}
	}
	return '';
}

/* ============================================================== wikidata === */

/** A claim's value as something a reader can use. */
function daw_src_value( $snak ) {
	$v = $snak['datavalue']['value'] ?? null;
	switch ( $snak['datavalue']['type'] ?? '' ) {
		case 'wikibase-entityid':
			// The id as Wikidata writes it (Q, P, L…). Building it from
			// numeric-id made "Q0" for values that have none and turned
			// properties into items.
			$id = (string) ( $v['id'] ?? '' );
			if ( '' === $id && ! empty( $v['numeric-id'] ) ) {
				$id = ( 'property' === ( $v['entity-type'] ?? 'item' ) ? 'P' : 'Q' ) . (int) $v['numeric-id'];
			}
			return preg_match( '/^[QPL]\d+(-[FS]\d+)?$/', $id ) ? array( 'q' => $id ) : null;
		case 'time':
			return array( 't' => (string) ( $v['time'] ?? '' ), 'p' => (int) ( $v['precision'] ?? 0 ) );
		case 'quantity':
			return array( 'n' => (string) ( $v['amount'] ?? '' ) );
		case 'monolingualtext':
			return array( 's' => (string) ( $v['text'] ?? '' ), 'l' => (string) ( $v['language'] ?? '' ) );
		case 'string':
		case 'external-id':
			return array( 's' => (string) $v );
	}
	return null;
}

/** Labels for a list of item or property ids, English first. */
function daw_src_labels( array $ids ) {
	$out = array();
	// Only items and properties have labels to fetch this way.
	$ids = array_filter( $ids, static function ( $i ) { return (bool) preg_match( '/^[QP][1-9]\d*$/', (string) $i ); } );
	foreach ( array_chunk( array_values( array_unique( $ids ) ), 50 ) as $chunk ) {
		$b = daw_src_api( 'www.wikidata.org', array( 'action' => 'wbgetentities', 'ids' => implode( '|', $chunk ), 'props' => 'labels', 'languages' => 'en|ko|ja|zh' ) );
		foreach ( (array) ( $b['entities'] ?? array() ) as $id => $x ) {
			$l          = (array) ( $x['labels'] ?? array() );
			$out[ $id ] = (string) ( $l['en']['value'] ?? $l['ko']['value'] ?? $l['ja']['value'] ?? $l['zh']['value'] ?? '' );
		}
	}
	return $out;
}

/** Read and keep the Wikidata item. The sitelinks, or null on failure. */
function daw_src_wd_sync( $post_id, $qid ) {
	$langs = implode( '|', array( 'en', 'ko', 'ja', 'zh', 'zh-hant', 'zh-hk', 'yue', 'th', 'tl', 'vi', 'id', 'ms' ) );
	$b     = daw_src_api( 'www.wikidata.org', array( 'action' => 'wbgetentities', 'ids' => $qid, 'props' => 'labels|aliases|descriptions|claims|sitelinks/urls', 'languages' => $langs ) );
	if ( null === $b ) {
		return null;
	}
	$e = (array) ( $b['entities'][ $qid ] ?? array() );
	if ( ! $e || isset( $e['missing'] ) ) {
		delete_post_meta( $post_id, DAW_SRC_WD );
		return array();
	}

	$pick = static function ( $list, $many = false ) {
		$out = array();
		foreach ( (array) $list as $l => $x ) {
			$out[ $l ] = $many
				? array_values( array_map( static function ( $y ) { return (string) ( $y['value'] ?? '' ); }, (array) $x ) )
				: (string) ( $x['value'] ?? '' );
		}
		return $out;
	};

	$claims = array();
	$refs   = array();
	foreach ( (array) ( $e['claims'] ?? array() ) as $prop => $list ) {
		foreach ( (array) $list as $c ) {
			if ( 'deprecated' === ( $c['rank'] ?? '' ) ) {
				continue;
			}
			$val = daw_src_value( $c['mainsnak'] ?? array() );
			if ( null === $val ) {
				continue;
			}
			// Qualifiers that change the meaning: when, which role, which season, order.
			foreach ( array( 'P580', 'P582', 'P585', 'P453', 'P4633', 'P1545', 'P518' ) as $qp ) {
				foreach ( (array) ( $c['qualifiers'][ $qp ] ?? array() ) as $qs ) {
					$qv = daw_src_value( $qs );
					if ( null !== $qv ) {
						$val[ 'q_' . $qp ][] = $qv;
						if ( isset( $qv['q'] ) ) {
							$refs[] = $qv['q'];
						}
					}
				}
			}
			if ( isset( $val['q'] ) ) {
				$refs[] = $val['q'];
			}
			$claims[ $prop ][] = $val;
		}
	}
	$names  = $refs ? daw_src_labels( $refs ) : array();
	$pnames = $claims ? daw_src_labels( array_keys( $claims ) ) : array();
	$named  = static function ( $v ) use ( &$named, $names ) {
		if ( isset( $v['q'] ) ) {
			$v['label'] = $names[ $v['q'] ] ?? '';
		}
		foreach ( $v as $k => $sub ) {
			if ( 0 === strpos( (string) $k, 'q_' ) && is_array( $sub ) ) {
				$v[ $k ] = array_map( $named, $sub );
			}
		}
		return $v;
	};
	$out_claims = array();
	foreach ( $claims as $prop => $vals ) {
		$label                                               = $pnames[ $prop ] ?? '';
		$out_claims[ $prop . ( '' !== $label ? ' ' . $label : '' ) ] = array_map( $named, $vals );
	}
	$sitelinks = array();
	foreach ( (array) ( $e['sitelinks'] ?? array() ) as $site => $x ) {
		$sitelinks[ $site ] = array( 'title' => (string) ( $x['title'] ?? '' ), 'url' => (string) ( $x['url'] ?? '' ) );
	}

	// update_post_meta unslashes what it is given; text can hold backslashes.
	update_post_meta(
		$post_id,
		DAW_SRC_WD,
		wp_slash(
			array(
				'qid'          => $qid,
				'url'          => 'https://www.wikidata.org/wiki/' . $qid,
				'at'           => gmdate( 'Y-m-d' ),
				'labels'       => $pick( $e['labels'] ?? array() ),
				'descriptions' => $pick( $e['descriptions'] ?? array() ),
				'aliases'      => $pick( $e['aliases'] ?? array(), true ),
				'sitelinks'    => $sitelinks,
				'claims'       => $out_claims,
			)
		)
	);
	return $sitelinks;
}

/* ============================================================= wikipedia === */

/** Read and keep one article. true, false (none) or null (failed). */
function daw_src_wiki_save( $post_id, $suffix, $host, $title ) {
	$p = daw_src_api( $host, array( 'action' => 'query', 'prop' => 'revisions', 'rvprop' => 'ids|timestamp|content', 'rvslots' => 'main', 'titles' => $title, 'redirects' => 1 ) );
	if ( null === $p ) {
		return null;
	}
	$page = (array) ( $p['query']['pages'][0] ?? array() );
	$rev  = (array) ( $page['revisions'][0] ?? array() );
	$text = (string) ( $rev['slots']['main']['content'] ?? '' );
	if ( '' === $text ) {
		delete_post_meta( $post_id, DAW_SRC_WIKI . $suffix );
		return false;
	}
	$cut  = mb_strlen( $text ) > DAW_SRC_MAX;
	$name = (string) ( $page['title'] ?? $title );
	update_post_meta(
		$post_id,
		DAW_SRC_WIKI . $suffix,
		wp_slash(
			array(
				'title'     => $name,
				'url'       => 'https://' . $host . '/wiki/' . rawurlencode( str_replace( ' ', '_', $name ) ),
				'permalink' => 'https://' . $host . '/w/index.php?oldid=' . (int) ( $rev['revid'] ?? 0 ),
				'revid'     => (int) ( $rev['revid'] ?? 0 ),
				'edited'    => (string) ( $rev['timestamp'] ?? '' ),
				'at'        => gmdate( 'Y-m-d' ),
				'cut'       => $cut,
				'wikitext'  => $cut ? mb_substr( $text, 0, DAW_SRC_MAX ) : $text,
			)
		)
	);
	return true;
}

/** Guides: the Wikipedia articles in their own source list, up to four. */
function daw_src_guide_wikis( $post_id ) {
	$out  = array();
	$urls = (array) get_post_meta( $post_id, 'daw_guide_source_url', true );
	$host_to_suffix = array();
	foreach ( daw_src_wikis() as $suffix => $w ) {
		$host_to_suffix[ $w[1] ] = $suffix;
	}
	foreach ( $urls as $u ) {
		if ( preg_match( '#^https?://((?:[a-z-]+)\.(?:m\.)?wikipedia\.org)/wiki/([^?\#]+)#i', html_entity_decode( (string) $u ), $m ) ) {
			$host = str_replace( '.m.', '.', strtolower( $m[1] ) );
			if ( isset( $host_to_suffix[ $host ] ) ) {
				$out[] = array( $host_to_suffix[ $host ], $host, str_replace( '_', ' ', rawurldecode( $m[2] ) ) );
			}
		}
		if ( count( $out ) >= 4 ) {
			break;
		}
	}
	return $out;
}

/* ================================================================== tmdb === */

/** Names out of a TMDB list of objects. */
function daw_src_names( $list, $key = 'name' ) {
	return array_values( array_filter( array_map( static function ( $x ) use ( $key ) { return (string) ( $x[ $key ] ?? '' ); }, (array) $list ), 'strlen' ) );
}

/** TMDB, compact. true, false (not tied) or null (failed). */
function daw_src_tmdb_sync( $post_id ) {
	$ref = function_exists( 'daw_tmdb_ref' ) ? daw_tmdb_ref( $post_id ) : array();
	if ( ! $ref || 'ok' !== get_post_meta( $post_id, '_daw_tmdb_img_check', true ) || ! function_exists( 'daw_tmdb_api_get' ) ) {
		delete_post_meta( $post_id, DAW_SRC_TMDB );
		return false;
	}
	$kind   = $ref['kind'];
	$id     = (int) $ref['id'];
	$append = array(
		'tv'     => 'aggregate_credits,keywords,content_ratings,external_ids,translations,alternative_titles',
		'movie'  => 'credits,keywords,release_dates,external_ids,translations,alternative_titles',
		'person' => 'combined_credits,external_ids,translations',
	);
	$b = daw_tmdb_api_get( $kind . '/' . $id, array( 'language' => 'en-US', 'append_to_response' => $append[ $kind ] ?? '' ) );
	if ( null === $b ) {
		daw_src_error( 'TMDB ' . $kind . '/' . $id . ': no answer' );
		return null;
	}
	if ( ! is_array( $b ) ) {
		return false;
	}
	$langs = daw_src_tmdb_langs();
	$tr    = array();
	foreach ( (array) ( $b['translations']['translations'] ?? array() ) as $t ) {
		$l = (string) ( $t['iso_639_1'] ?? '' );
		if ( ! in_array( $l, $langs, true ) ) {
			continue;
		}
		$d   = (array) ( $t['data'] ?? array() );
		$row = array_filter(
			array(
				'name'      => (string) ( $d['name'] ?? $d['title'] ?? '' ),
				'overview'  => (string) ( $d['overview'] ?? $d['biography'] ?? '' ),
				'tagline'   => (string) ( $d['tagline'] ?? '' ),
			),
			'strlen'
		);
		if ( $row ) {
			$tr[ $l . '-' . (string) ( $t['iso_3166_1'] ?? '' ) ] = $row;
		}
	}
	$crew_jobs = array( 'Director', 'Writer', 'Screenplay', 'Novel', 'Original Story', 'Original Concept', 'Comic Book', 'Author', 'Story', 'Creator', 'Producer', 'Executive Producer', 'Original Music Composer', 'Music', 'Director of Photography', 'Series Director' );
	$out       = array(
		'kind' => $kind,
		'id'   => $id,
		'url'  => 'https://www.themoviedb.org/' . $kind . '/' . $id,
		'at'   => gmdate( 'Y-m-d' ),
		'ids'  => array_filter( (array) ( $b['external_ids'] ?? array() ) ),
		'tr'   => $tr,
		'note' => 'TMDB episode counts per actor are unreliable for Korean dramas (K3): not a source for guest episodes.',
	);
	if ( 'person' === $kind ) {
		$cast = array();
		foreach ( (array) ( $b['combined_credits']['cast'] ?? array() ) as $c ) {
			$cast[] = array_filter(
				array(
					'title' => (string) ( $c['title'] ?? $c['name'] ?? '' ),
					'orig'  => (string) ( $c['original_title'] ?? $c['original_name'] ?? '' ),
					'type'  => (string) ( $c['media_type'] ?? '' ),
					'id'    => (int) ( $c['id'] ?? 0 ),
					'date'  => (string) ( $c['release_date'] ?? $c['first_air_date'] ?? '' ),
					'char'  => (string) ( $c['character'] ?? '' ),
					'eps'   => (int) ( $c['episode_count'] ?? 0 ),
				)
			);
		}
		usort( $cast, static function ( $a, $b2 ) { return strcmp( (string) ( $b2['date'] ?? '' ), (string) ( $a['date'] ?? '' ) ); } );
		$crew = array();
		foreach ( (array) ( $b['combined_credits']['crew'] ?? array() ) as $c ) {
			$crew[] = array_filter( array( 'title' => (string) ( $c['title'] ?? $c['name'] ?? '' ), 'type' => (string) ( $c['media_type'] ?? '' ), 'id' => (int) ( $c['id'] ?? 0 ), 'date' => (string) ( $c['release_date'] ?? $c['first_air_date'] ?? '' ), 'job' => (string) ( $c['job'] ?? '' ) ) );
		}
		$out += array(
			'name'       => (string) ( $b['name'] ?? '' ),
			'aka'        => (array) ( $b['also_known_as'] ?? array() ),
			'birthday'   => (string) ( $b['birthday'] ?? '' ),
			'deathday'   => (string) ( $b['deathday'] ?? '' ),
			'birthplace' => (string) ( $b['place_of_birth'] ?? '' ),
			'gender'     => (int) ( $b['gender'] ?? 0 ),
			'department' => (string) ( $b['known_for_department'] ?? '' ),
			'bio_en'     => (string) ( $b['biography'] ?? '' ),
			'cast'       => array_slice( $cast, 0, 150 ),
			'crew'       => array_slice( $crew, 0, 50 ),
		);
	} else {
		$credits = 'tv' === $kind ? (array) ( $b['aggregate_credits'] ?? array() ) : (array) ( $b['credits'] ?? array() );
		$cast    = array();
		foreach ( array_slice( (array) ( $credits['cast'] ?? array() ), 0, 50 ) as $c ) {
			$roles = 'tv' === $kind
				? array_map( static function ( $r ) { return array( 'char' => (string) ( $r['character'] ?? '' ), 'eps' => (int) ( $r['episode_count'] ?? 0 ) ); }, (array) ( $c['roles'] ?? array() ) )
				: array( array( 'char' => (string) ( $c['character'] ?? '' ) ) );
			$cast[] = array( 'name' => (string) ( $c['name'] ?? '' ), 'id' => (int) ( $c['id'] ?? 0 ), 'order' => (int) ( $c['order'] ?? 0 ), 'roles' => $roles );
		}
		$crew = array();
		foreach ( (array) ( $credits['crew'] ?? array() ) as $c ) {
			$jobs = 'tv' === $kind ? daw_src_names( $c['jobs'] ?? array(), 'job' ) : array( (string) ( $c['job'] ?? '' ) );
			$keep = array_values( array_intersect( $jobs, $crew_jobs ) );
			if ( $keep ) {
				$crew[] = array( 'name' => (string) ( $c['name'] ?? '' ), 'id' => (int) ( $c['id'] ?? 0 ), 'jobs' => $keep );
			}
		}
		$ratings = array();
		if ( 'tv' === $kind ) {
			foreach ( (array) ( $b['content_ratings']['results'] ?? array() ) as $r ) {
				$ratings[ (string) ( $r['iso_3166_1'] ?? '' ) ] = (string) ( $r['rating'] ?? '' );
			}
		} else {
			foreach ( (array) ( $b['release_dates']['results'] ?? array() ) as $r ) {
				foreach ( (array) ( $r['release_dates'] ?? array() ) as $d ) {
					$ratings[ (string) ( $r['iso_3166_1'] ?? '' ) ][] = array_filter( array( 'date' => substr( (string) ( $d['release_date'] ?? '' ), 0, 10 ), 'cert' => (string) ( $d['certification'] ?? '' ), 'type' => (int) ( $d['type'] ?? 0 ) ) );
				}
			}
		}
		$alt = array();
		foreach ( (array) ( $b['alternative_titles']['results'] ?? $b['alternative_titles']['titles'] ?? array() ) as $a ) {
			$alt[] = trim( (string) ( $a['title'] ?? '' ) . ' (' . (string) ( $a['iso_3166_1'] ?? '' ) . ')' );
		}
		$out += array(
			'title'     => (string) ( $b['name'] ?? $b['title'] ?? '' ),
			'original'  => (string) ( $b['original_name'] ?? $b['original_title'] ?? '' ),
			'language'  => (string) ( $b['original_language'] ?? '' ),
			'countries' => (array) ( $b['origin_country'] ?? daw_src_names( $b['production_countries'] ?? array(), 'iso_3166_1' ) ),
			'start'     => (string) ( $b['first_air_date'] ?? $b['release_date'] ?? '' ),
			'end'       => (string) ( $b['last_air_date'] ?? '' ),
			'status'    => (string) ( $b['status'] ?? '' ),
			'episodes'  => (int) ( $b['number_of_episodes'] ?? 0 ),
			'seasons'   => array_map( static function ( $s ) { return array( 'n' => (int) ( $s['season_number'] ?? 0 ), 'name' => (string) ( $s['name'] ?? '' ), 'air' => (string) ( $s['air_date'] ?? '' ), 'eps' => (int) ( $s['episode_count'] ?? 0 ) ); }, (array) ( $b['seasons'] ?? array() ) ),
			'runtime'   => 'tv' === $kind ? (array) ( $b['episode_run_time'] ?? array() ) : (int) ( $b['runtime'] ?? 0 ),
			'genres'    => daw_src_names( $b['genres'] ?? array() ),
			'networks'  => daw_src_names( $b['networks'] ?? array() ),
			'companies' => daw_src_names( $b['production_companies'] ?? array() ),
			'creators'  => daw_src_names( $b['created_by'] ?? array() ),
			'keywords'  => daw_src_names( $b['keywords']['results'] ?? $b['keywords']['keywords'] ?? array() ),
			'ratings'   => $ratings,
			'alt'       => array_slice( $alt, 0, 30 ),
			'overview'  => (string) ( $b['overview'] ?? '' ),
			'cast'      => $cast,
			'crew'      => $crew,
		);
	}
	update_post_meta( $post_id, DAW_SRC_TMDB, wp_slash( $out ) );
	return true;
}

/* ================================================================== sync === */

/** Everything for one record. true, or null when a source failed (tried again in an hour). */
function daw_src_sync( $post_id ) {
	$post_id = (int) $post_id;
	$failed  = false;

	if ( null === daw_src_tmdb_sync( $post_id ) ) {
		$failed = true;
	}

	$wikis = array();
	$qid   = daw_src_qid( $post_id );
	if ( '' !== $qid ) {
		$links = daw_src_wd_sync( $post_id, $qid );
		if ( null === $links ) {
			$failed = true;
			$links  = array();
		}
		foreach ( daw_src_wikis() as $suffix => $w ) {
			if ( ! empty( $links[ $w[0] ]['title'] ) ) {
				$wikis[ $suffix ] = array( $suffix, $w[1], $links[ $w[0] ]['title'] );
			} elseif ( ! $failed ) {
				delete_post_meta( $post_id, DAW_SRC_WIKI . $suffix );
			}
		}
	}
	foreach ( daw_src_guide_wikis( $post_id ) as $g ) {
		if ( ! isset( $wikis[ $g[0] ] ) ) {
			$wikis[ $g[0] ] = $g;
		}
	}
	foreach ( $wikis as $w ) {
		if ( null === daw_src_wiki_save( $post_id, $w[0], $w[1], $w[2] ) ) {
			$failed = true;
		}
	}

	// YouTube and Kakao are extras with daily quotas. When one of them says no,
	// the record still counts as read; the audit's own EXT job asks again
	// later. Failing the whole record made it re-read TMDB, Wikidata and every
	// Wikipedia each hour while the quota was spent, and the queue stood still.
	if ( function_exists( 'daw_src_ext_sync' ) ) {
		daw_src_ext_sync( $post_id );
	}

	if ( $failed ) {
		update_post_meta( $post_id, DAW_SRC_FAIL, time() );
		return null;
	}
	delete_post_meta( $post_id, DAW_SRC_FAIL );
	update_post_meta( $post_id, DAW_SRC_AT, time() );
	return true;
}

/* ============================================================ background === */

/** Every covered record: [ ids due, total ]. Due = never read, or read 30+ days ago; a failure waits an hour. */
function daw_src_due( $limit = DAW_SRC_SLICE ) {
	global $wpdb;
	$ids  = function_exists( 'daw_audit_record_ids' ) ? array_map( 'intval', (array) daw_audit_record_ids( daw_src_types() ) ) : array();
	$at   = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '" . esc_sql( DAW_SRC_AT ) . "'", OBJECT_K ); // phpcs:ignore WordPress.DB
	$fail = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '" . esc_sql( DAW_SRC_FAIL ) . "'", OBJECT_K ); // phpcs:ignore WordPress.DB
	$old  = time() - 30 * DAY_IN_SECONDS;
	$wait = time() - HOUR_IN_SECONDS;
	$due  = array();
	$left = 0;
	foreach ( $ids as $id ) {
		if ( isset( $at[ $id ] ) && (int) $at[ $id ]->meta_value >= $old ) {
			continue;
		}
		++$left;
		if ( isset( $fail[ $id ] ) && (int) $fail[ $id ]->meta_value > $wait ) {
			continue;
		}
		if ( count( $due ) < $limit ) {
			$due[] = $id;
		}
	}
	return array( $due, count( $ids ), $left );
}

/** One step: as many records as fit in about twenty seconds. */
function daw_src_step() {
	if ( get_transient( 'daw_src_lock' ) ) {
		return 0;
	}
	set_transient( 'daw_src_lock', 1, 90 );
	$start = microtime( true );
	$n     = 0;
	list( $due ) = daw_src_due();
	foreach ( $due as $id ) {
		if ( microtime( true ) - $start > 20 ) {
			break;
		}
		daw_src_sync( $id );
		++$n;
	}
	delete_transient( 'daw_src_lock' );
	return $n;
}

add_action( 'daw_src_cron', 'daw_src_cron_run' );
function daw_src_cron_run() {
	daw_src_step();
	list( $due ) = daw_src_due( 1 );
	if ( $due && ! wp_next_scheduled( 'daw_src_cron' ) ) {
		wp_schedule_single_event( time() + 60, 'daw_src_cron' );
	}
}

add_action( 'init', 'daw_src_schedule' );
function daw_src_schedule() {
	if ( ! wp_next_scheduled( 'daw_src_cron' ) ) {
		wp_schedule_single_event( time() + 300, 'daw_src_cron' );
	}
}

/* ================================================================ admin === */

add_action( 'admin_menu', 'daw_src_menu', 40 );
function daw_src_menu() {
	add_submenu_page( 'tools.php', 'DAW: Kaynak deposu', 'DAW: Kaynak deposu', 'manage_options', 'daw-src', 'daw_src_page' );
}

function daw_src_counts() {
	global $wpdb;
	list( , $total, $left ) = daw_src_due( 0 );
	$rows = $wpdb->get_results( "SELECT meta_key, COUNT(*) n FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_daw\\_src\\_%' GROUP BY meta_key", ARRAY_A ); // phpcs:ignore WordPress.DB
	$by   = array();
	foreach ( (array) $rows as $r ) {
		$by[ $r['meta_key'] ] = (int) $r['n'];
	}
	// An error is shown only while it can still matter: failed records are
	// retried after an hour, so an older line is history, not the current state
	// (the Q0 line from 1.45.0 kept showing a day after 1.45.1 fixed it).
	$err = get_option( 'daw_src_last_err', array() );
	if ( ! is_array( $err ) || (int) ( $err['at'] ?? 0 ) < time() - HOUR_IN_SECONDS ) {
		$err = array();
	}
	return array( 'total' => $total, 'left' => $left, 'done' => $total - $left, 'by' => $by, 'err' => $err );
}

add_action( 'wp_ajax_daw_src_run', 'daw_src_ajax' );
function daw_src_ajax() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'daw_src', 'nonce', false ) ) {
		wp_send_json_error( 'yetki' );
	}
	$locked = (bool) get_transient( 'daw_src_lock' );
	$n      = daw_src_step();
	$c      = daw_src_counts();
	wp_send_json_success( array( 'ran' => $n, 'locked' => $locked, 'done' => $c['done'], 'total' => $c['total'], 'left' => $c['left'], 'err' => $c['err'] ) );
}

function daw_src_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$c     = daw_src_counts();
	$names = array(
		'_daw_src_tmdb'  => 'TMDB',
		'_daw_src_wd'    => 'Wikidata',
		'_daw_src_yt'    => 'YouTube',
		'_daw_src_kakao' => 'Kakao',
	);
	echo '<div class="wrap"><h1>DAW: Kaynak deposu</h1>';
	echo '<p>TMDB, Wikidata ve Wikipedia (İngilizce ile Korece, Japonca, Çince, Kantonca, Tayca, Tagalogca, Vietnamca, Endonezce, Malayca: hangisinde madde varsa), anahtar girildiyse YouTube ve Kakao, her kayıt için bir kez okunur ve kayda gizli alan olarak yazılır; 30 günde bir yenilenir. Ajanlar önce buradan okur.</p>';
	printf( '<p><strong id="daw-src-done">%d</strong> / %d kayıt okundu, <span id="daw-src-left">%d</span> kaldı.</p>', (int) $c['done'], (int) $c['total'], (int) $c['left'] );
	$parts = array();
	foreach ( $names as $k => $label ) {
		$parts[] = $label . ' ' . (int) ( $c['by'][ $k ] ?? 0 );
	}
	foreach ( $c['by'] as $k => $n ) {
		if ( 0 === strpos( $k, DAW_SRC_WIKI ) ) {
			$parts[] = 'Wikipedia ' . substr( $k, strlen( DAW_SRC_WIKI ) ) . ' ' . (int) $n;
		}
	}
	echo '<p>' . esc_html( implode( ' · ', $parts ) ) . '</p>';
	if ( function_exists( 'daw_ext_pending' ) ) {
		$owed = count( daw_ext_pending() );
		$q    = array();
		foreach ( array( 'youtube' => 'YouTube', 'kakao' => 'Kakao' ) as $sv => $lb ) {
			$until = (int) get_option( 'daw_quota_' . $sv, 0 );
			if ( $until > time() ) {
				$q[] = $lb . ' kotası doldu, ' . wp_date( 'd.m H:i', $until ) . ' açılır';
			}
		}
		printf( '<p>YouTube / Kakao okuması bekleyen: <strong>%d</strong> kayıt%s. Saatlik iş, kota açıkken okur; liste boşalınca durur.</p>', (int) $owed, $q ? ' · ' . esc_html( implode( ' · ', $q ) ) : '' );
	}
	$err = $c['err'];
	echo '<p id="daw-src-err" style="color:#b32d2e">' . ( ! empty( $err['what'] ) ? esc_html( 'Son hata (' . wp_date( 'd.m H:i', (int) $err['at'] ) . '): ' . $err['what'] ) : '' ) . '</p>';
	echo '<p><button type="button" class="button button-primary" id="daw-src-go">Başlat</button> <button type="button" class="button" id="daw-src-stop" disabled>Durdur</button> <span id="daw-src-state"></span></p>';
	echo '<p class="description">Ekran açıkken adım adım kendisi ilerler; kapatınca arka planda dakikada bir adım devam eder.</p>';
	?>
	<script>
	( function () {
		var go = document.getElementById( 'daw-src-go' ), stop = document.getElementById( 'daw-src-stop' ), st = document.getElementById( 'daw-src-state' ), on = false;
		function step() {
			if ( ! on ) { return; }
			var f = new FormData();
			f.append( 'action', 'daw_src_run' );
			f.append( 'nonce', <?php echo wp_json_encode( wp_create_nonce( 'daw_src' ) ); ?> );
			fetch( ajaxurl, { method: 'POST', body: f, credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } ).then( function ( j ) {
				if ( ! j || ! j.success ) { st.textContent = 'Hata'; on = false; return; }
				document.getElementById( 'daw-src-done' ).textContent = j.data.done;
				document.getElementById( 'daw-src-left' ).textContent = j.data.left;
				document.getElementById( 'daw-src-err' ).textContent = ( j.data.err && j.data.err.what ) ? 'Son hata: ' + j.data.err.what : '';
				if ( j.data.left > 0 && ( j.data.ran > 0 ) ) { st.textContent = 'Çalışıyor…'; setTimeout( step, 500 ); }
				else if ( j.data.left > 0 && j.data.locked ) { st.textContent = 'Arka plan görevi çalışıyor, 15 sn sonra devam…'; setTimeout( step, 15000 ); }
				else if ( j.data.left > 0 ) { st.textContent = 'Kalanlar bir saat sonra yeniden denenecek (hata ya da kilit).'; on = false; go.disabled = false; stop.disabled = true; }
				else { st.textContent = 'Bitti.'; on = false; go.disabled = false; stop.disabled = true; }
			} ).catch( function () { st.textContent = 'Bağlantı hatası, 5 sn sonra yeniden'; setTimeout( step, 5000 ); } );
		}
		go.addEventListener( 'click', function () { on = true; go.disabled = true; stop.disabled = false; step(); } );
		stop.addEventListener( 'click', function () { on = false; go.disabled = false; stop.disabled = true; st.textContent = 'Durduruldu.'; } );
	} )();
	</script>
	<?php
	echo '</div>';
}

/* ============================================== 1.68.2: encoded queries === */

/**
 * Until 1.68.2 the API calls here, and the TMDB, Wikidata, Kakao and KOBIS
 * ones, put titles into the address without encoding them. A "&" cut the
 * title short: Remarriage & Desires was looked up as "Remarriage" and the
 * store kept the general Wikipedia article on remarriage as the drama's
 * source (K15). Once, the records whose title or Wikipedia link carries a
 * character that breaks an address lose those stored pages and are read
 * again, and those TMDB could not find are searched again.
 */
add_action( 'daw_fix_tick', 'daw_src_enc_requeue', 2 );
add_action( 'admin_init', 'daw_src_enc_requeue' );
function daw_src_enc_requeue() {
	if ( '1' === (string) get_option( 'daw_src_enc_v' ) ) {
		return;
	}
	update_option( 'daw_src_enc_v', '1', false );
	global $wpdb;
	$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
		"SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'daw_link_wikipedia'
		WHERE p.post_status = 'publish' AND ( p.post_title REGEXP '[&+#?%]' OR m.meta_value REGEXP '(%26|%2B|%23|%3F|[&+])' )"
	);
	$n = 0;
	foreach ( array_map( 'intval', (array) $ids ) as $id ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $id, $wpdb->esc_like( '_daw_src_wiki_' ) . '%' ) ); // phpcs:ignore WordPress.DB
		delete_post_meta( $id, DAW_SRC_AT );
		delete_post_meta( $id, '_daw_src_kakao' );
		if ( '' === trim( (string) get_post_meta( $id, 'daw_tmdb_id', true ) ) ) {
			if ( 'none' === (string) get_post_meta( $id, 'daw_tmdb_match', true ) ) {
				delete_post_meta( $id, 'daw_tmdb_match' );
			}
			delete_post_meta( $id, '_daw_tmdb_cand_at' );
		}
		clean_post_cache( $id );
		++$n;
	}
	if ( $n && function_exists( 'daw_fix_log' ) ) {
		daw_fix_log( 'img', sprintf( 'Adresi bozan karakterli %d kaydın kaynakları yeniden okunacak (kodlama düzeltmesi)', $n ), 0 );
	}
}
