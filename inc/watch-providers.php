<?php
/**
 * Where to watch, by country, from TMDB's watch-provider data.
 *
 * WHY NOT JUSTWATCH DIRECTLY
 * --------------------------
 * JustWatch's terms forbid scraping its pages. TMDB licenses the same data
 * from JustWatch and hands it out through /watch/providers, on one condition:
 * the page says the data comes from JustWatch. So this file asks TMDB, keeps
 * only provider names and logo paths, and the title page prints the credit.
 *
 * WHAT IS STORED
 * --------------
 * `_daw_watch_tmdb` holds [ 'at' => time, 'r' => [ 'US' => [ 'stream' =>
 * [[id, name, logo]], 'free' => …, 'buy' => … ] ], 'link' => [ 'US' => url ] ]
 * for every country TMDB answers for. Logos are shown from TMDB's image
 * server (nothing is downloaded or kept here).
 *
 * WHERE A ROW LINKS TO (madde 56)
 * -------------------------------
 * A reader who presses "Kocowa" wants Kocowa, not another listing site. So a
 * row goes to the platform itself, in this order:
 *
 * 1. the official link typed into the record (daw_watch_platform / _url);
 * 2. the title's own page on the platform, from the platform ID Wikidata
 *    keeps for it (Netflix P1874, Prime Video P14440, Disney+ P7596/P7595,
 *    Apple TV P9751/P9586, Hulu P6467/P6466, Max P8298, Viki P10344, TVING
 *    P11960, Crunchyroll P11330, Peacock P11815, Paramount+ P13147, Tubi
 *    P7761/P7760), stored in `_daw_watch_ids` and refreshed with the list;
 * 3. the platform's own search for the title, for platforms whose search
 *    address was opened and checked (Kocowa, Viki, iQIYI, WeTV,
 *    OnDemandKorea, Prime Video, Netflix, Crunchyroll, Tubi, YouTube,
 *    Google Play);
 * 4. the platform's home page when known; otherwise no link (never TMDB).
 *
 * LICENCES END
 * ------------
 * Every refresh replaces the whole list, so a platform whose licence ended
 * drops off the page on the next run: weekly, daily while on air (madde 56).
 *
 * WHEN IT RUNS
 * ------------
 * Only for records whose TMDB match has been verified (image check "ok"), so
 * a wrong id never puts a wrong platform on a page. Weekly for everything,
 * daily for titles that are on air or upcoming, 40 records per hourly tick.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

const DAW_WP_META = '_daw_watch_tmdb';
const DAW_WP_AT   = '_daw_watch_tmdb_at';
const DAW_WP_CAP  = 6; // rows shown before "Show all".


/** Regions kept, with the name printed in the picker. */
function daw_wp_regions() {
	return array(
		'US' => 'United States',
		'GB' => 'United Kingdom',
		'CA' => 'Canada',
		'AU' => 'Australia',
		'NZ' => 'New Zealand',
		'IE' => 'Ireland',
		'IN' => 'India',
		'SG' => 'Singapore',
		'MY' => 'Malaysia',
		'PH' => 'Philippines',
		'ID' => 'Indonesia',
		'TH' => 'Thailand',
		'VN' => 'Vietnam',
		'TW' => 'Taiwan',
		'HK' => 'Hong Kong',
		'JP' => 'Japan',
		'KR' => 'South Korea',
		'TR' => 'Türkiye',
		'DE' => 'Germany',
		'FR' => 'France',
		'ES' => 'Spain',
		'IT' => 'Italy',
		'NL' => 'Netherlands',
		'BR' => 'Brazil',
		'MX' => 'Mexico',
	);
}

/** A country's English name: the fixed list first, then PHP intl, then the code. */
function daw_wp_region_name( $cc ) {
	$names = daw_wp_regions();
	if ( isset( $names[ $cc ] ) ) {
		return $names[ $cc ];
	}
	if ( class_exists( 'Locale' ) ) {
		$n = \Locale::getDisplayRegion( '-' . $cc, 'en' );
		if ( is_string( $n ) && '' !== $n && $n !== $cc ) {
			return $n;
		}
	}
	return $cc;
}

/** Official links typed into the record, by lower-cased platform name. */
function daw_wp_manual_links( $post_id ) {
	$names = get_post_meta( $post_id, 'daw_watch_platform', true );
	$urls  = get_post_meta( $post_id, 'daw_watch_url', true );
	$names = is_array( $names ) ? array_values( $names ) : array();
	$urls  = is_array( $urls ) ? array_values( $urls ) : array();
	$out   = array();
	foreach ( $names as $i => $n ) {
		$u = esc_url_raw( (string) ( $urls[ $i ] ?? '' ) );
		if ( '' !== trim( (string) $n ) && 0 === strpos( $u, 'https://' ) ) {
			$out[ strtolower( trim( (string) $n ) ) ] = $u;
		}
	}
	return $out;
}

/* ============================================================ deep links === */

/**
 * The platforms a row can link into. key => [ name pattern, Wikidata
 * properties with their address pattern, search address or '' ].
 * Search addresses were opened by hand on 24 September 2026; a platform whose
 * search did not take the title from the address (Apple TV, Viu, Disney+,
 * Hulu) has none, so it falls through to its ID or to TMDB.
 */
function daw_wp_platforms() {
	return array(
		'netflix'     => array( '/^netflix/i', array( 'P1874' => 'https://www.netflix.com/title/%s' ), 'https://www.netflix.com/search?q=%s' ),
		'prime'       => array( '/amazon|prime video/i', array( 'P14440' => 'https://www.primevideo.com/detail/%s', 'P8055' => 'https://www.amazon.com/gp/video/detail/%s' ), 'https://www.primevideo.com/search?phrase=%s' ),
		'disney'      => array( '/disney/i', array( 'P7596' => 'https://www.disneyplus.com/series/wp/%s', 'P7595' => 'https://www.disneyplus.com/movies/wd/%s' ), '' ),
		'apple'       => array( '/^apple tv/i', array( 'P9751' => 'https://tv.apple.com/show/%s', 'P9586' => 'https://tv.apple.com/movie/%s' ), '' ),
		'hulu'        => array( '/^hulu/i', array( 'P6467' => 'https://www.hulu.com/series/%s', 'P6466' => 'https://www.hulu.com/movie/%s' ), '' ),
		'max'         => array( '/^(hbo )?max\b/i', array( 'P8298' => 'https://www.hbomax.com/%s' ), '' ),
		'viki'        => array( '/viki/i', array( 'P10344' => 'https://www.viki.com/%s' ), 'https://www.viki.com/search?q=%s' ),
		'tving'       => array( '/tving/i', array( 'P11960' => 'https://www.tving.com/contents/%s' ), '' ),
		'crunchyroll' => array( '/crunchyroll/i', array( 'P11330' => 'https://www.crunchyroll.com/series/%s' ), 'https://www.crunchyroll.com/search?q=%s' ),
		'peacock'     => array( '/peacock/i', array( 'P11815' => 'https://www.peacocktv.com/stream-%s' ), '' ),
		'paramount'   => array( '/paramount/i', array( 'P13147' => 'https://www.paramountplus.com/shows/video/%s/' ), '' ),
		'tubi'        => array( '/^tubi/i', array( 'P7761' => 'https://tubitv.com/series/%s', 'P7760' => 'https://tubitv.com/movies/%s' ), 'https://tubitv.com/search/%s' ),
		'kocowa'      => array( '/kocowa/i', array(), 'https://www.kocowa.com/en_us/search?q=%s' ),
		'iqiyi'       => array( '/iqiyi/i', array(), 'https://www.iq.com/search?query=%s' ),
		'wetv'        => array( '/^wetv/i', array(), 'https://wetv.vip/en/search/%s' ),
		'odk'         => array( '/ondemandkorea/i', array(), 'https://www.ondemandkorea.com/en/search?q=%s' ),
		'youtube'     => array( '/^youtube/i', array(), 'https://www.youtube.com/results?search_query=%s' ),
		'googleplay'  => array( '/google play/i', array(), 'https://play.google.com/store/search?c=movies&q=%s' ),
	);
}

/** Platform key for a TMDB provider name, or ''. */
function daw_wp_platform_key( $name ) {
	foreach ( daw_wp_platforms() as $k => $p ) {
		if ( preg_match( $p[0], (string) $name ) ) {
			return $k;
		}
	}
	return '';
}

/**
 * Title pages on each platform, from the Wikidata entity's platform IDs.
 * Returns [ key => url ]; the first property that has a value wins.
 */
function daw_wp_ids_from_entity( array $entity ) {
	$out = array();
	foreach ( daw_wp_platforms() as $k => $p ) {
		foreach ( $p[1] as $prop => $fmt ) {
			foreach ( (array) ( $entity['claims'][ $prop ] ?? array() ) as $c ) {
				if ( 'deprecated' === ( $c['rank'] ?? '' ) ) {
					continue;
				}
				$v = $c['mainsnak']['datavalue']['value'] ?? '';
				// IDs are slugs, numbers or UUIDs; anything else is not trusted into a URL.
				if ( is_string( $v ) && preg_match( '#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,120}$#', $v ) && false === strpos( $v, '..' ) ) {
					$out[ $k ] = sprintf( $fmt, $v );
					break 2;
				}
			}
		}
	}
	return $out;
}

/**
 * Refresh `_daw_watch_ids` from Wikidata. The QID comes from the verify step
 * (`_daw_vf_wd`) or, failing that, from TMDB's external IDs.
 */
function daw_wp_ids_sync( $post_id, array $ref ) {
	if ( ! function_exists( 'daw_wd_get' ) ) {
		return;
	}
	$qid = (string) get_post_meta( $post_id, '_daw_vf_wd', true );
	if ( ! preg_match( '/^Q\d+$/', $qid ) ) {
		$ex  = daw_tmdb_api_get( $ref['kind'] . '/' . $ref['id'] . '/external_ids' );
		$qid = is_array( $ex ) ? (string) ( $ex['wikidata_id'] ?? '' ) : '';
	}
	if ( ! preg_match( '/^Q\d+$/', $qid ) ) {
		delete_post_meta( $post_id, '_daw_watch_ids' );
		return;
	}
	$b = daw_wd_get( array( 'action' => 'wbgetentities', 'ids' => $qid, 'props' => 'claims' ) );
	if ( empty( $b['entities'][ $qid ] ) ) {
		return; // Wikidata did not answer: keep last week's IDs.
	}
	$ids = daw_wp_ids_from_entity( (array) $b['entities'][ $qid ] );
	if ( $ids ) {
		update_post_meta( $post_id, '_daw_watch_ids', $ids );
	} else {
		delete_post_meta( $post_id, '_daw_watch_ids' );
	}
}

/** The words a platform search is given: the title without a "(2017)" tail. */
function daw_wp_search_term( $post_id ) {
	$t = html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' );
	return trim( preg_replace( '/\s*\([^)]*\)\s*$/', '', $t ) );
}

/**
 * Where one row links to: official link, title page, platform search, home page.
 * Returns [ url, how ] with how one of manual / title / search / home, or ['', ''].
 */
function daw_wp_row_url( $name, $manual, $ids, $term, $tmdb_page ) {
	$lc = strtolower( trim( (string) $name ) );
	if ( isset( $manual[ $lc ] ) ) {
		return array( $manual[ $lc ], 'manual' );
	}
	$k = daw_wp_platform_key( $name );
	if ( '' !== $k ) {
		foreach ( $manual as $mn => $mu ) {
			if ( daw_wp_platform_key( $mn ) === $k ) {
				return array( $mu, 'manual' );
			}
		}
		if ( ! empty( $ids[ $k ] ) ) {
			return array( $ids[ $k ], 'title' );
		}
		$plat = daw_wp_platforms();
		if ( '' !== $plat[ $k ][2] && '' !== $term ) {
			return array( sprintf( $plat[ $k ][2], rawurlencode( $term ) ), 'search' );
		}
	}
	// No TMDB fallback: a reader who presses a platform must land on that
	// platform, never on another listing site (owner's rule, 26.09.2026).
	// The platform's own home page is used when it is known; otherwise the
	// row is printed as plain text with no link. $tmdb_page is kept in the
	// signature for callers but no longer used.
	$home = daw_wp_home_url( $name );
	return array( $home, '' === $home ? '' : 'home' );
}

/**
 * Home pages of streaming services TMDB lists that have no title ID or
 * checked search address here. Channels sold inside Prime Video are matched
 * earlier by the Prime pattern; channels inside Apple TV go to the service's
 * own home page, since that is where its subscription lives.
 */
function daw_wp_home_url( $name ) {
	$map = array(
		'/amc\s*(\+|plus)/i'        => 'https://www.amcplus.com/',
		'/^philo/i'                  => 'https://www.philo.com/',
		'/^fubo/i'                   => 'https://www.fubo.tv/',
		'/^sling/i'                  => 'https://www.sling.com/',
		'/^shudder/i'                => 'https://www.shudder.com/',
		'/^pluto/i'                  => 'https://pluto.tv/',
		'/^the roku channel/i'       => 'https://therokuchannel.roku.com/',
		'/^plex/i'                   => 'https://watch.plex.tv/',
		'/^mubi/i'                   => 'https://mubi.com/',
		'/^hoopla/i'                 => 'https://www.hoopladigital.com/',
		'/^kanopy/i'                 => 'https://www.kanopy.com/',
		'/^fandango at home|^vudu/i' => 'https://athome.fandango.com/',
		'/^disney/i'                 => 'https://www.disneyplus.com/',
		'/^apple tv/i'               => 'https://tv.apple.com/',
		'/^hulu/i'                   => 'https://www.hulu.com/',
		'/^(hbo )?max\b/i'           => 'https://www.max.com/',
		'/^tving/i'                  => 'https://www.tving.com/',
		'/^peacock/i'                => 'https://www.peacocktv.com/',
		'/^paramount/i'              => 'https://www.paramountplus.com/',
		'/^viu\b/i'                  => 'https://www.viu.com/',
		'/^wavve/i'                  => 'https://www.wavve.com/',
		'/^coupang/i'                => 'https://www.coupangplay.com/',
		'/^watcha/i'                 => 'https://watcha.com/',
	);
	foreach ( $map as $re => $url ) {
		if ( preg_match( $re, (string) $name ) ) {
			return $url;
		}
	}
	return '';
}

/** Group labels, in print order. */
function daw_wp_kinds() {
	return array(
		'stream' => __( 'Stream', 'daw' ),
		'free'   => __( 'Free', 'daw' ),
		'buy'    => __( 'Rent or buy', 'daw' ),
	);
}

/**
 * Ask TMDB for one record. Returns true when something was stored (even an
 * empty answer), null when TMDB could not be asked.
 */
function daw_wp_sync( $post_id ) {
	$post_id = (int) $post_id;
	$ref     = function_exists( 'daw_tmdb_ref' ) ? daw_tmdb_ref( $post_id ) : array();
	if ( ! $ref || ! in_array( $ref['kind'], array( 'tv', 'movie' ), true ) ) {
		delete_post_meta( $post_id, DAW_WP_META );
		update_post_meta( $post_id, DAW_WP_AT, time() );
		return true;
	}
	if ( 'ok' !== get_post_meta( $post_id, DAW_TMDB_CHECK, true ) ) {
		// Not a verified match: never show platforms for a possibly wrong id.
		delete_post_meta( $post_id, DAW_WP_META );
		return null;
	}
	$body = daw_tmdb_api_get( $ref['kind'] . '/' . $ref['id'] . '/watch/providers' );
	if ( null === $body || ! is_array( $body ) ) {
		update_post_meta( $post_id, DAW_WP_AT, time() - 6 * DAY_IN_SECONDS ); // retry tomorrow.
		return null;
	}

	$keep  = array();
	$links = array();
	foreach ( (array) ( $body['results'] ?? array() ) as $cc => $r ) {
		$cc = strtoupper( (string) $cc );
		if ( ! preg_match( '/^[A-Z]{2}$/', $cc ) || ! is_array( $r ) ) {
			continue;
		}
		$lk = (string) ( $r['link'] ?? '' );
		if ( '' !== $lk && preg_match( '#^https://www\.themoviedb\.org/[a-z]+/\d+[^\s"<>]*$#', $lk ) ) {
			$links[ $cc ] = $lk;
		}
		$row = array();
		$map = array( 'flatrate' => 'stream', 'free' => 'free', 'ads' => 'free', 'rent' => 'buy', 'buy' => 'buy' );
		foreach ( $map as $src => $kind ) {
			foreach ( (array) ( $r[ $src ] ?? array() ) as $p ) {
				$pid  = (int) ( $p['provider_id'] ?? 0 );
				$pn   = trim( wp_strip_all_tags( (string) ( $p['provider_name'] ?? '' ) ) );
				$logo = (string) ( $p['logo_path'] ?? '' );
				if ( ! $pid || '' === $pn ) {
					continue;
				}
				if ( '' !== $logo && ! preg_match( '#^/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp|svg)$#i', $logo ) ) {
					$logo = '';
				}
				$row[ $kind ][ $pid ] = array( $pid, mb_substr( $pn, 0, 60 ), $logo );
			}
		}
		// A platform that streams it is not listed again under rent or buy.
		foreach ( array( 'free', 'buy' ) as $k ) {
			if ( isset( $row[ $k ] ) && isset( $row['stream'] ) ) {
				$row[ $k ] = array_diff_key( $row[ $k ], $row['stream'] );
			}
		}
		if ( isset( $row['buy'], $row['free'] ) ) {
			$row['buy'] = array_diff_key( $row['buy'], $row['free'] );
		}
		$row = array_map( 'array_values', array_filter( $row ) );
		if ( $row ) {
			$keep[ $cc ] = $row;
		}
	}

	update_post_meta( $post_id, DAW_WP_META, array( 'at' => time(), 'r' => $keep, 'link' => array_intersect_key( $links, $keep ) ) );
	update_post_meta( $post_id, DAW_WP_AT, time() );
	if ( $keep ) {
		daw_wp_ids_sync( $post_id, $ref );
	}
	// The page is cached; without a purge readers would see last week's list.
	do_action( 'litespeed_purge_post', $post_id );
	return true;
}

/** The stored data for a record, or an empty array. */
function daw_wp_get( $post_id ) {
	$d = get_post_meta( (int) $post_id, DAW_WP_META, true );
	return ( is_array( $d ) && ! empty( $d['r'] ) && is_array( $d['r'] ) ) ? $d : array();
}

/** Records due for a refresh, oldest first. */
function daw_wp_queue( $limit = 40 ) {
	global $wpdb;
	$week = time() - 7 * DAY_IN_SECONDS;
	$day  = time() - DAY_IN_SECONDS;
	// phpcs:ignore WordPress.DB
	return array_map(
		'intval',
		(array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = 'daw_tmdb_id' AND t.meta_value <> ''
				 JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s AND c.meta_value = 'ok'
				 LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = 'daw_status'
				 WHERE p.post_type = 'post' AND p.post_status = 'publish'
				   AND ( a.meta_value IS NULL OR CAST( a.meta_value AS UNSIGNED ) < %d
				         OR ( s.meta_value IN ( 'ongoing', 'upcoming' ) AND CAST( a.meta_value AS UNSIGNED ) < %d ) )
				 ORDER BY CAST( COALESCE( a.meta_value, '0' ) AS UNSIGNED ) ASC
				 LIMIT %d",
				DAW_TMDB_CHECK,
				DAW_WP_AT,
				$week,
				$day,
				(int) $limit
			)
		)
	);
}

add_action( 'daw_wp_cron', 'daw_wp_cron_run' );
function daw_wp_cron_run( $limit = 40 ) {
	if ( '' === ( function_exists( 'daw_tmdb_key' ) ? daw_tmdb_key() : '' ) ) {
		return 0;
	}
	$n = 0;
	foreach ( daw_wp_queue( (int) $limit ) as $id ) {
		try {
			daw_wp_sync( $id );
		} catch ( \Throwable $e ) {
			update_post_meta( $id, DAW_WP_AT, time() );
		}
		$n++;
	}
	return $n;
}

add_action( 'init', 'daw_wp_schedule' );
function daw_wp_schedule() {
	if ( ! wp_next_scheduled( 'daw_wp_cron' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'daw_wp_cron' );
	}
}

/** A new or changed TMDB match is asked again straight after its picture check. */
add_action( 'updated_post_meta', 'daw_wp_on_check', 10, 4 );
add_action( 'added_post_meta', 'daw_wp_on_check', 10, 4 );
function daw_wp_on_check( $mid, $post_id, $key, $value ) {
	if ( DAW_TMDB_CHECK === $key ) {
		delete_post_meta( (int) $post_id, DAW_WP_AT );
		if ( 'ok' !== $value ) {
			delete_post_meta( (int) $post_id, DAW_WP_META );
		}
	}
}

/** Counts for the admin home. */
function daw_wp_counts() {
	global $wpdb;
	// phpcs:ignore WordPress.DB
	$with = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value NOT LIKE %s", DAW_WP_META, '%s:1:"r";a:0:%' ) );
	// phpcs:ignore WordPress.DB
	$asked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_WP_AT ) );
	return array( 'with' => $with, 'asked' => $asked );
}

/* ======================================================= visitor country === */

/**
 * The visitor's country, from a header the host or CDN already sets, never
 * from a third-party lookup: the IP is not sent anywhere and not stored.
 * Pages are cached, so the page itself cannot know; the script asks this
 * small uncached endpoint once per browser and remembers the answer.
 */
function daw_wp_visitor_cc() {
	foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_GEOIP_COUNTRY' ) as $k ) {
		$v = strtoupper( trim( (string) ( $_SERVER[ $k ] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( preg_match( '/^[A-Z]{2}$/', $v ) && 'XX' !== $v ) {
			return $v;
		}
	}
	return '';
}

add_action( 'rest_api_init', 'daw_wp_rest' );
function daw_wp_rest() {
	register_rest_route(
		'daw/v1',
		'/cc',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => static function () {
				$r = new WP_REST_Response( array( 'cc' => daw_wp_visitor_cc() ) );
				$r->header( 'Cache-Control', 'private, no-store' );
				$r->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
				return $r;
			},
		)
	);
}

/* ================================================================ render === */

/**
 * The by-country block for the title page. Empty string when there is
 * nothing to show.
 */
function daw_wp_html( $post_id ) {
	$d = daw_wp_get( $post_id );
	if ( ! $d ) {
		return '';
	}
	$names  = array();
	$kinds  = daw_wp_kinds();
	$data   = array();
	$manual = daw_wp_manual_links( $post_id );
	$ids    = get_post_meta( $post_id, '_daw_watch_ids', true );
	$ids    = is_array( $ids ) ? $ids : array();
	$term   = daw_wp_search_term( $post_id );
	foreach ( $d['r'] as $cc => $row ) {
		$names[ $cc ] = daw_wp_region_name( $cc );
		$page         = (string) ( $d['link'][ $cc ] ?? '' );
		$data[ $cc ]  = array();
		foreach ( $kinds as $k => $label ) {
			if ( empty( $row[ $k ] ) ) {
				continue;
			}
			foreach ( $row[ $k ] as $p ) {
				$pn                  = (string) $p[1];
				$url                 = daw_wp_row_url( $pn, $manual, $ids, $term, $page )[0];
				$data[ $cc ][ $k ][] = array( $pn, (string) ( $p[2] ?? '' ), $url );
			}
		}
	}
	if ( ! $data ) {
		return '';
	}
	$first = isset( $data['US'] ) ? 'US' : array_key_first( $data );

	ob_start();
	?>
	<div class="daw-wp" data-daw-wp>
		<label class="daw-wp-pick">
			<span><?php esc_html_e( 'Your country', 'daw' ); ?></span>
			<select data-daw-wp-select>
				<?php
				$order = array_keys( $data );
				usort(
					$order,
					static function ( $a, $b ) use ( $names ) {
						return strcmp( $names[ $a ], $names[ $b ] );
					}
				);
				foreach ( $order as $cc ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $cc ), selected( $cc, $first, false ), esc_html( $names[ $cc ] ) );
				}
				?>
			</select>
		</label>
		<?php
		// No height is reserved: the list is drawn for the reader's country
		// before the first paint (saved choice, then browser language), and
		// the one late answer, the host's country header, is only applied
		// while the box is out of view, where a change moves nothing the
		// reader is looking at. Reserving the tallest country's height left a
		// band of empty card under a two-row list.
		?>
		<div class="daw-wp-list" data-daw-wp-list>
			<?php echo daw_wp_list_html( $data[ $first ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php // The JustWatch credit TMDB requires is in the site footer, next to the TMDB credit. ?>
		<script type="application/json" data-daw-wp-data><?php echo wp_json_encode( array( 'd' => $data, 'l' => $kinds ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
	</div>
	<script>
	(function () {
		var box = document.currentScript.previousElementSibling;
		if (!box || !box.hasAttribute('data-daw-wp')) { return; }
		var raw = box.querySelector('[data-daw-wp-data]'), sel = box.querySelector('[data-daw-wp-select]'), list = box.querySelector('[data-daw-wp-list]');
		var j; try { j = JSON.parse(raw.textContent); } catch (e) { return; }
		function esc(s) { var d = document.createElement('span'); d.textContent = s; return d.innerHTML; }
		var CAP = <?php echo (int) DAW_WP_CAP; ?>, more = <?php echo wp_json_encode( __( 'Show all %d', 'daw' ) ); ?>;
		function draw(cc) {
			var row = j.d[cc]; if (!row) { return; }
			var h = '', i = 0;
			Object.keys(j.l).forEach(function (k) {
				if (!row[k]) { return; }
				h += '<div class="daw-wp-kind' + (i >= CAP ? ' is-extra' : '') + '">' + esc(j.l[k]) + '</div>';
				row[k].forEach(function (p) {
					var x = i++ >= CAP ? ' is-extra' : '';
					var mark = p[1] ? '<img class="daw-watch-mark daw-watch-mark--logo" src="https://image.tmdb.org/t/p/w92' + esc(p[1]) + '" width="36" height="36" alt="" loading="lazy" decoding="async">' : '<span class="daw-watch-mark" aria-hidden="true">' + esc((p[0] || '?').charAt(0).toUpperCase()) + '</span>';
					var inner = mark + '<span>' + esc(p[0]) + '</span>';
					h += p[2] ? '<a class="daw-wp-row' + x + '" href="' + esc(p[2]) + '" target="_blank" rel="noopener nofollow">' + inner + '<span class="daw-watch-go" aria-hidden="true">↗</span></a>' : '<div class="daw-wp-row' + x + '">' + inner + '</div>';
				});
			});
			if (i > CAP) { h += '<button type="button" class="daw-wp-more" data-daw-wp-more>' + esc(more.replace('%d', i)) + '</button>'; }
			list.classList.remove('is-open');
			list.innerHTML = h;
		}
		list.addEventListener('click', function (e) { if (e.target.closest('[data-daw-wp-more]')) { list.classList.add('is-open'); } });
		sel.addEventListener('change', function () { draw(sel.value); try { localStorage.setItem('daw_wp_cc', sel.value); } catch (e) {} });
		function pick(cc) { if (cc && j.d[cc] && cc !== sel.value) { sel.value = cc; draw(cc); } }
		var want = null;
		try { want = localStorage.getItem('daw_wp_cc'); } catch (e) {}
		if (want && j.d[want]) { pick(want); return; }
		(navigator.languages || [navigator.language || '']).some(function (l) {
			var m = /-([A-Za-z]{2})$/.exec(l || ''); if (m && j.d[m[1].toUpperCase()]) { want = m[1].toUpperCase(); return true; } return false;
		});
		pick(want);
		// Country from the host's own header (no IP leaves the server), asked
		// once per browser and remembered, so from the second page on it is
		// applied before paint. The first answer arrives late, so it is used
		// only while the box is off screen; in view, a list that changed size
		// would push the page under the reader.
		var seen = null;
		try { seen = localStorage.getItem('daw_geo_cc'); } catch (e) {}
		if (seen !== null) { pick(seen); return; }
		// Whether the box is on screen, kept by an observer: asking for its
		// position (getBoundingClientRect) made the browser lay out the page
		// on the spot. Without IntersectionObserver the answer is "on screen",
		// which only means the late country is not applied.
		var vis = true;
		if ('IntersectionObserver' in window) { new IntersectionObserver(function (es) { vis = es[es.length - 1].isIntersecting; }).observe(box); }
		function away() { return !vis; }
		fetch(<?php echo wp_json_encode( esc_url_raw( rest_url( 'daw/v1/cc' ) ) ); ?>, { credentials: 'omit' })
			.then(function (r) { return r.json(); })
			.then(function (d) { var cc = (d && d.cc) || ''; try { localStorage.setItem('daw_geo_cc', cc); } catch (e) {} if (away()) { pick(cc); } })
			.catch(function () {});
	})();
	</script>
	<?php
	return (string) ob_get_clean();
}

/** One country's list, server side (the same markup the script draws). */
function daw_wp_list_html( $row ) {
	$out = '';
	$i   = 0;
	foreach ( daw_wp_kinds() as $k => $label ) {
		if ( empty( $row[ $k ] ) ) {
			continue;
		}
		$out .= '<div class="daw-wp-kind' . ( $i >= DAW_WP_CAP ? ' is-extra' : '' ) . '">' . esc_html( $label ) . '</div>';
		foreach ( $row[ $k ] as $p ) {
			$x = $i++ >= DAW_WP_CAP ? ' is-extra' : '';
			// The platform's logo from TMDB's image server, a monogram when
			// TMDB has none. Both fill the same fixed square, so nothing moves.
			$mark  = ! empty( $p[1] )
				? '<img class="daw-watch-mark daw-watch-mark--logo" src="' . esc_url( 'https://image.tmdb.org/t/p/w92' . $p[1] ) . '" width="36" height="36" alt="" loading="lazy" decoding="async">'
				: '<span class="daw-watch-mark" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $p[0], 0, 1 ) ) ) . '</span>';
			$inner = $mark . '<span>' . esc_html( $p[0] ) . '</span>';
			$out  .= ! empty( $p[2] )
				? '<a class="daw-wp-row' . $x . '" href="' . esc_url( $p[2] ) . '" target="_blank" rel="noopener nofollow">' . $inner . '<span class="daw-watch-go" aria-hidden="true">↗</span></a>'
				: '<div class="daw-wp-row' . $x . '">' . $inner . '</div>';
		}
	}
	if ( $i > DAW_WP_CAP ) {
		/* translators: %d: number of platforms in the list */
		$out .= '<button type="button" class="daw-wp-more" data-daw-wp-more>' . esc_html( sprintf( __( 'Show all %d', 'daw' ), $i ) ) . '</button>';
	}
	return $out;
}

/**
 * True when a platform (by typed name or official URL host) is already in
 * some country's list, where its row links to the same official page. The
 * title page then leaves the typed row out instead of printing it twice.
 */
function daw_wp_lists_platform( $post_id, $name, $url = '' ) {
	static $seen = array();
	$post_id = (int) $post_id;
	if ( ! isset( $seen[ $post_id ] ) ) {
		$seen[ $post_id ] = array();
		foreach ( (array) ( daw_wp_get( $post_id )['r'] ?? array() ) as $row ) {
			foreach ( $row as $list ) {
				foreach ( $list as $p ) {
					$k = daw_wp_platform_key( $p[1] );
					$seen[ $post_id ][ '' !== $k ? $k : strtolower( $p[1] ) ] = true;
				}
			}
		}
	}
	$k = daw_wp_platform_key( $name );
	if ( '' === $k && '' !== $url ) {
		$k = daw_wp_platform_key( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}
	return isset( $seen[ $post_id ][ '' !== $k ? $k : strtolower( trim( (string) $name ) ) ] );
}

/** US streaming names, for the FAQ answer. */
function daw_wp_names( $post_id, $cc = 'US', $kind = 'stream' ) {
	$d = daw_wp_get( $post_id );
	$o = array();
	foreach ( (array) ( $d['r'][ $cc ][ $kind ] ?? array() ) as $p ) {
		$o[] = (string) $p[1];
	}
	return $o;
}

/**
 * Stored lists from before 1.39.6 have no country links, no logos in the
 * page and only 25 countries. Their refresh stamps are cleared once so the
 * hourly job and DAW › Otomatik işler ask TMDB again for every record; the
 * lists themselves stay on the page until the new answer replaces them.
 */
add_action( 'admin_init', 'daw_wp_upgrade_2' );
function daw_wp_upgrade_2() {
	// Schema 3 (1.40.0): rows link into the platforms, which needs each
	// record's Wikidata platform IDs; clearing the stamps once fetches them.
	if ( 3 <= (int) get_option( 'daw_wp_schema', 0 ) ) {
		return;
	}
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_WP_AT ) ); // phpcs:ignore WordPress.DB
	update_option( 'daw_wp_schema', 3, false );
}
