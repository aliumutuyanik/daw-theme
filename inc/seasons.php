<?php
/**
 * Madde 65: a season of a series is its own page, tied to TMDB's season.
 *
 * WHY
 * ---
 * The site has about 25 "X Season N" records (Stranger Season 2, Kingdom
 * Season 2, Sweet Home Season 3). TMDB keeps a series as one record with its
 * seasons inside, so none of these pages matched anything on TMDB: no poster
 * from TMDB (the old uploaded file was served instead, against the image
 * rule), no dates, no cast list to compare with.
 *
 * The owner chose the MyDramaList shape (24 Sep 2026): every season keeps its
 * own page and address, and each page is tied to the series on TMDB plus the
 * season number. The poster, dates, episodes and cast then come from TMDB's
 * season. The series record stands for season 1 and all pages of one series
 * link to each other under the title.
 *
 * HOW A SEASON IS TIED
 * --------------------
 * From the title: "Stranger Season 2" is split into "Stranger" and 2. The
 * site's record named exactly "Stranger" must exist, be a series and have a
 * TMDB match that was checked ("ok"). TMDB is then asked for that series'
 * season 2; only when TMDB has it is the link written. A season TMDB does not
 * know (some Chinese dramas list each season as a separate series) is left
 * alone and shows as a line to look at by hand.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_SEASON_NO     = '_daw_tmdb_season';
const DAW_SEASON_PARENT = '_daw_season_parent';
const DAW_SEASON_STATE  = '_daw_season_state';
const DAW_SEASON_AT     = '_daw_season_at';

/** The TMDB season number this record stands for, 0 when it is not a season record. */
function daw_season_no( $post_id ) {
	$n = (int) get_post_meta( (int) $post_id, DAW_SEASON_NO, true );
	return $n > 0 ? $n : 0;
}

/** "Stranger Season 2" => [ 'Stranger', 2, '' ]; "Alchemy of Souls Season 2: Light and Shadow" keeps the subtitle. */
function daw_season_title_split( $title ) {
	$t = trim( html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, 'UTF-8' ) );
	if ( preg_match( '/^(.+?)\s+Season\s+(\d{1,2})\b\s*(?:[:\-]\s*(.+))?$/iu', $t, $m ) && (int) $m[2] > 1 ) {
		return array( trim( $m[1] ), (int) $m[2], trim( $m[3] ?? '' ) );
	}
	return array();
}

/** The series record on the site named exactly $base, or 0. */
function daw_season_find_parent( $base ) {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_title = %s ORDER BY ID ASC", $base ) ); // phpcs:ignore WordPress.DB
	$best = 0;
	foreach ( array_map( 'intval', (array) $ids ) as $id ) {
		if ( daw_season_no( $id ) ) {
			continue;
		}
		$ref = function_exists( 'daw_tmdb_ref' ) ? daw_tmdb_ref( $id ) : array();
		if ( ! $ref || 'tv' !== $ref['kind'] ) {
			continue;
		}
		if ( 'ok' === get_post_meta( $id, '_daw_tmdb_img_check', true ) ) {
			return $id;
		}
		$best = $best ? $best : $id;
	}
	return $best;
}

/**
 * Tie one record to its series' season on TMDB, when its title says it is a
 * season. Returns the state it ended in: linked, own, no_parent,
 * parent_unverified, no_tmdb, not_season, or '' when TMDB could not be asked.
 */
function daw_season_link( $post_id ) {
	$post_id = (int) $post_id;
	$split   = daw_season_title_split( get_the_title( $post_id ) );
	$set     = static function ( $state ) use ( $post_id ) {
		update_post_meta( $post_id, DAW_SEASON_STATE, $state );
		update_post_meta( $post_id, DAW_SEASON_AT, time() );
		return $state;
	};

	if ( daw_season_no( $post_id ) ) {
		// Already tied (by this job or by hand): only the parent may be missing.
		if ( ! (int) get_post_meta( $post_id, DAW_SEASON_PARENT, true ) && $split ) {
			$parent = daw_season_find_parent( $split[0] );
			if ( $parent ) {
				update_post_meta( $post_id, DAW_SEASON_PARENT, $parent );
			}
		}
		return $set( 'linked' );
	}
	// Only a series can have seasons: a film, a person, a news story or a
	// guide whose headline says "Season 2" is not tied to anything.
	$type = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
	if ( ! $split || '' === $type || in_array( $type, array( 'film', 'person', 'group', 'news', 'guide' ), true ) ) {
		return $set( 'not_season' );
	}

	$parent = daw_season_find_parent( $split[0] );
	$own    = function_exists( 'daw_tmdb_ref' ) ? daw_tmdb_ref( $post_id ) : array();
	$own_ok = $own && 'ok' === get_post_meta( $post_id, '_daw_tmdb_img_check', true );

	if ( $parent ) {
		if ( 'ok' !== get_post_meta( $parent, '_daw_tmdb_img_check', true ) ) {
			return $set( 'parent_unverified' );
		}
		$pref = daw_tmdb_ref( $parent );
	} else {
		// No series record on the site: ask TMDB for a series of exactly
		// that name. Only a single exact hit is used.
		$pref = daw_season_search_series( $post_id, $split[0] );
		if ( null === $pref ) {
			return '';
		}
		if ( ! $pref ) {
			return $set( $own_ok ? 'own' : daw_season_adopt_own( $post_id, $split, 'no_parent' ) );
		}
	}

	// The series' own season N wins over a separate TMDB entry that only
	// carries the season's name: those are often thin duplicates (found:
	// "지옥 시즌2", no poster, wrong country, matched to Hellbound Season 2).
	$body = daw_tmdb_api_get( 'tv/' . (int) $pref['id'] . '/season/' . (int) $split[1], array( 'language' => 'en-US' ) );
	if ( null === $body ) {
		return '';
	}
	if ( false === $body || empty( $body['id'] ) ) {
		return $set( $own_ok && (int) $own['id'] !== (int) $pref['id'] ? 'own' : daw_season_adopt_own( $post_id, $split, 'no_tmdb' ) );
	}
	if ( $own && (int) $own['id'] !== (int) $pref['id'] ) {
		update_post_meta( $post_id, '_daw_tmdb_prev_id', (string) get_post_meta( $post_id, 'daw_tmdb_id', true ) );
	}

	update_post_meta( $post_id, 'daw_tmdb_id', (string) (int) $pref['id'] );
	update_post_meta( $post_id, 'daw_tmdb_match', 'season' );
	update_post_meta( $post_id, DAW_SEASON_NO, (int) $split[1] );
	if ( $parent ) {
		update_post_meta( $post_id, DAW_SEASON_PARENT, $parent );
		wp_cache_delete( 'daw_season_family_' . $parent, 'daw' );
	}
	delete_post_meta( $post_id, '_daw_tmdb_cand' );
	delete_post_meta( $post_id, '_daw_tmdb_auto' );
	if ( function_exists( 'daw_tmdb_img_sync' ) ) {
		daw_tmdb_img_sync( $post_id );
	}
	return $set( 'linked' );
}

/**
 * A TMDB series named exactly $base, for a season page whose series has no
 * record on the site. [ kind, id ] on one exact hit, [] on none or several,
 * null when TMDB could not be asked.
 */
function daw_season_search_series( $post_id, $base ) {
	$b = daw_tmdb_api_get( 'search/tv', array( 'query' => $base, 'language' => 'en-US' ) );
	if ( null === $b || false === $b ) {
		return null === $b ? null : array();
	}
	$norm = static function ( $s ) {
		return preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) );
	};
	$want = $norm( $base );
	$hits = array();
	foreach ( (array) ( $b['results'] ?? array() ) as $r ) {
		if ( $want === $norm( $r['name'] ?? '' ) || $want === $norm( $r['original_name'] ?? '' ) ) {
			$hits[] = (int) $r['id'];
		}
	}
	$hits = array_values( array_unique( $hits ) );
	return 1 === count( $hits ) ? array( 'kind' => 'tv', 'id' => $hits[0] ) : array();
}

/** Write a separate TMDB series found by name as the record's own id; returns the state. */
function daw_season_adopt_own( $post_id, $split, $fallback ) {
	$hit = daw_season_search_own( $post_id, $split[0], $split[1] );
	if ( ! $hit ) {
		return $fallback;
	}
	$old = (string) get_post_meta( $post_id, 'daw_tmdb_id', true );
	if ( '' !== $old && (string) $hit['id'] !== $old ) {
		update_post_meta( $post_id, '_daw_tmdb_prev_id', $old );
	}
	update_post_meta( $post_id, 'daw_tmdb_id', (string) $hit['id'] );
	update_post_meta( $post_id, 'daw_tmdb_match', 'season-own' );
	delete_post_meta( $post_id, '_daw_tmdb_auto' );
	if ( function_exists( 'daw_tmdb_img_sync' ) ) {
		daw_tmdb_img_sync( $post_id );
	}
	return 'own';
}

/**
 * A season TMDB keeps as a series of its own ("Nonstop 4", "으라차차 와이키키 2"):
 * search by "Base N" and by the record's native title and other names; one
 * exact hit becomes the record's own TMDB series. [ kind, id ] or [].
 */
function daw_season_search_own( $post_id, $base, $n ) {
	$norm  = static function ( $s ) {
		return preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) );
	};
	$names = array( $base . ' ' . $n, $base . ' Season ' . $n );
	foreach ( array( 'daw_native_title', 'daw_also_known_as' ) as $k ) {
		foreach ( (array) get_post_meta( $post_id, $k, true ) as $v ) {
			if ( '' !== trim( (string) $v ) ) {
				$names[] = (string) $v;
			}
		}
	}
	$want = array_filter( array_unique( array_map( $norm, $names ) ) );
	$hits = array();
	foreach ( array_slice( array_unique( $names ), 0, 4 ) as $q ) {
		$b = daw_tmdb_api_get( 'search/tv', array( 'query' => $q ) );
		if ( null === $b ) {
			return null;
		}
		foreach ( (array) ( is_array( $b ) ? ( $b['results'] ?? array() ) : array() ) as $r ) {
			if ( in_array( $norm( $r['name'] ?? '' ), $want, true ) || in_array( $norm( $r['original_name'] ?? '' ), $want, true ) ) {
				$hits[ (int) $r['id'] ] = 1;
			}
		}
	}
	return 1 === count( $hits ) ? array( 'kind' => 'tv', 'id' => (int) array_key_first( $hits ) ) : array();
}

/**
 * The TMDB API path of the season a record stands for: its own season, or
 * season 1 for a series record that has season pages. '' otherwise.
 */
function daw_season_api_base( $post_id, $ref = null ) {
	$ref = null === $ref ? ( function_exists( 'daw_tmdb_ref' ) ? daw_tmdb_ref( $post_id ) : array() ) : $ref;
	if ( ! $ref || 'tv' !== $ref['kind'] ) {
		return '';
	}
	if ( ! empty( $ref['season'] ) ) {
		return 'tv/' . (int) $ref['id'] . '/season/' . (int) $ref['season'];
	}
	return count( daw_season_family( $post_id ) ) > 1 ? 'tv/' . (int) $ref['id'] . '/season/1' : '';
}

/**
 * All pages of one series, season number => post id, season 1 being the
 * series record. Empty when the record has no season pages.
 */
function daw_season_family( $post_id ) {
	$post_id = (int) $post_id;
	$parent  = daw_season_no( $post_id ) ? (int) get_post_meta( $post_id, DAW_SEASON_PARENT, true ) : $post_id;
	if ( ! $parent ) {
		return array();
	}
	$kids = wp_cache_get( 'daw_season_family_' . $parent, 'daw' );
	if ( false === $kids ) {
		$kids = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => DAW_SEASON_PARENT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $parent, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		wp_cache_set( 'daw_season_family_' . $parent, $kids, 'daw', HOUR_IN_SECONDS );
	}
	if ( ! $kids ) {
		return array();
	}
	$out = array( 1 => $parent );
	foreach ( (array) $kids as $k ) {
		$n = daw_season_no( $k );
		if ( $n > 1 && ! isset( $out[ $n ] ) ) {
			$out[ $n ] = (int) $k;
		}
	}
	ksort( $out );
	return count( $out ) > 1 ? $out : array();
}

/** Audit line (madde 65) for a record whose title names a season, or a series with season pages. */
function daw_season_rules( $post_id ) {
	$post_id = (int) $post_id;
	$split   = daw_season_title_split( get_the_title( $post_id ) );
	$n       = daw_season_no( $post_id );
	if ( ! $split && ! $n ) {
		$fam = daw_season_family( $post_id );
		return $fam ? array( array( 'Sezon bağı (madde 65)', count( $fam ) . ' sezon sayfası', 'ok', 'Seri kaydı 1. sezonu temsil eder; sezon sayfaları başlık altında birbirine bağlı' ) ) : array();
	}
	if ( $n ) {
		$check = (string) get_post_meta( $post_id, '_daw_tmdb_img_check', true );
		return array( array( 'Sezon bağı (madde 65)', 'TMDB sezon ' . $n, 'ok' === $check ? 'ok' : 'wait', 'ok' === $check ? '' : 'TMDB sezonu bağlı, eşleşme kontrolü bekliyor: ' . (string) get_post_meta( $post_id, '_daw_tmdb_img_why', true ) ) );
	}
	$state = (string) get_post_meta( $post_id, DAW_SEASON_STATE, true );
	$notes = array(
		'own'               => array( 'ok', 'TMDB bu sezonu ayrı bir dizi olarak tutuyor; kendi TMDB kaydına bağlı' ),
		'no_parent'         => array( 'no', 'Sitede "' . ( $split[0] ?? '' ) . '" adlı seri kaydı yok ve TMDB\'de bu adla tek bir dizi bulunamadı: daw_tmdb_id ve _daw_tmdb_season elle yazılmalı (sezon 1 kaydının açılması sahibin kararı)' ),
		'parent_unverified' => array( 'wait', 'Seri kaydının TMDB eşleşmesi henüz doğrulanmadı; doğrulanınca sezon kendiliğinden bağlanır' ),
		'no_tmdb'           => array( 'no', 'TMDB\'de bu seride ' . ( $split[1] ?? '' ) . '. sezon yok; TMDB sezonu ayrı dizi olarak tutuyor olabilir, elle bakılmalı' ),
	);
	$row = $notes[ $state ] ?? array( 'wait', 'Sezon bağı henüz kurulmadı; denetim açılınca ya da günlük işte denenir' );
	return array( array( 'Sezon bağı (madde 65)', $split ? $split[0] . ', sezon ' . $split[1] : '', $row[0], $row[1] ) );
}

/** Daily: try the season titles not yet tied, ten at a time. */
add_action( 'daw_ae_cron', 'daw_season_cron', 4 );
function daw_season_cron() {
	global $wpdb;
	$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
			 WHERE p.post_type = 'post' AND p.post_status = 'publish' AND p.post_title LIKE %s
			   AND s.meta_value IS NULL
			   AND ( a.meta_value IS NULL OR CAST( a.meta_value AS UNSIGNED ) < %d )
			 LIMIT 10",
			DAW_SEASON_NO,
			DAW_SEASON_AT,
			'% Season %',
			time() - 3 * DAY_IN_SECONDS
		)
	);
	foreach ( array_map( 'intval', (array) $ids ) as $id ) {
		try {
			daw_season_link( $id );
		} catch ( \Throwable $e ) {
			update_post_meta( $id, DAW_SEASON_AT, time() );
		}
	}
}

/** Pages of one series, for the theme: [ [ n, id, year, url, current ], ... ]. */
function daw_season_nav( $post_id ) {
	$out = array();
	foreach ( daw_season_family( $post_id ) as $n => $id ) {
		$start = (int) get_post_meta( $id, 'daw_aired_start', true );
		$out[] = array(
			'n'       => (int) $n,
			'id'      => (int) $id,
			'year'    => $start > 0 ? gmdate( 'Y', $start ) : '',
			'url'     => get_permalink( $id ),
			'current' => (int) $id === (int) $post_id,
		);
	}
	return $out;
}
