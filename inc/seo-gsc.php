<?php
/**
 * Discover Asian World: Search Console data, fetched by the site itself.
 *
 * WHY A SERVICE ACCOUNT
 * ---------------------
 * Rank Math's Analytics module was the only thing filling a Search Console
 * table on this server (rank_math_analytics_gsc, read by seo-command.php). It
 * logs in through Rank Math's own Google app, which goes when Rank Math goes.
 * A Google Cloud service account needs no app review and no login screen: the
 * owner creates one, downloads its JSON key, and adds its e-mail address in
 * Search Console as a user with "Restricted" permission. The site then signs a
 * token for itself with that key (RS256, the same standard Google's own
 * libraries use) and asks for its numbers once a day.
 *
 * WHAT IS KEPT
 * ------------
 * {prefix}daw_gsc     one row per day and page: clicks, impressions, position.
 * {prefix}daw_gsc_q   the searches each page was shown for over the last 28
 *                     days, replaced every day.
 * Rank Math's history is copied into daw_gsc once, so nothing is lost when its
 * table stops growing.
 *
 * The private key is stored in one non-autoloaded option, is never printed
 * back on any screen, and leaves the server only inside a signed request to
 * Google's token endpoint.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_GSC_DB_VERSION = 2;
// Full scope (1.57.0): the site now also submits its sitemap. Reading needs
// nothing more; Google still applies the account's own permission level.
const DAW_GSC_SCOPE      = 'https://www.googleapis.com/auth/webmasters https://www.googleapis.com/auth/analytics.readonly';

function daw_gsc_table() {
	global $wpdb;

	return $wpdb->prefix . 'daw_gsc';
}

function daw_gsc_q_table() {
	global $wpdb;

	return $wpdb->prefix . 'daw_gsc_q';
}

add_action( 'plugins_loaded', 'daw_gsc_install', 6 );
function daw_gsc_install() {
	if ( (int) get_option( 'daw_gsc_db', 0 ) >= DAW_GSC_DB_VERSION ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();

	dbDelta(
		'CREATE TABLE ' . daw_gsc_table() . " (
			day date NOT NULL,
			page varchar(512) NOT NULL DEFAULT '',
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			impressions int(10) unsigned NOT NULL DEFAULT 0,
			position float NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,page(191)),
			KEY page (page(191))
		) {$charset};"
	);

	dbDelta(
		'CREATE TABLE ' . daw_gsc_q_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			page varchar(512) NOT NULL DEFAULT '',
			query varchar(255) NOT NULL DEFAULT '',
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			impressions int(10) unsigned NOT NULL DEFAULT 0,
			position float NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY page (page(191))
		) {$charset};"
	);

	dbDelta(
		'CREATE TABLE ' . daw_gsc_inspect_table() . " (
			path varchar(512) NOT NULL DEFAULT '',
			path_hash char(32) NOT NULL DEFAULT '',
			verdict varchar(20) NOT NULL DEFAULT '',
			coverage varchar(120) NOT NULL DEFAULT '',
			indexing varchar(40) NOT NULL DEFAULT '',
			fetch_state varchar(40) NOT NULL DEFAULT '',
			robots_state varchar(20) NOT NULL DEFAULT '',
			google_canonical varchar(512) NOT NULL DEFAULT '',
			user_canonical varchar(512) NOT NULL DEFAULT '',
			last_crawl datetime NULL DEFAULT NULL,
			checked datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (path_hash),
			KEY verdict (verdict)
		) {$charset};"
	);

	update_option( 'daw_gsc_db', DAW_GSC_DB_VERSION, true );
}

function daw_gsc_inspect_table() {
	global $wpdb;

	return $wpdb->prefix . 'daw_gsc_inspect';
}

/* ============================================================ settings === */

function daw_gsc_settings() {
	$s = get_option( 'daw_gsc', array() );

	return wp_parse_args(
		is_array( $s ) ? $s : array(),
		array(
			'json'      => '',
			'property'  => 'sc-domain:' . preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'last_day'  => '',
			'synced_at' => 0,
			'error'     => '',
			'inspect_note' => '',
			'q_at'      => 0,
			'property_pinned' => false,
		)
	);
}

function daw_gsc_save( array $values ) {
	if ( false === get_option( 'daw_gsc', false ) ) {
		add_option( 'daw_gsc', array(), '', false );
	}

	update_option( 'daw_gsc', array_merge( daw_gsc_settings(), $values ), false );
}

/** The service account, or null when none has been given. */
function daw_gsc_creds() {
	$json = json_decode( (string) daw_gsc_settings()['json'], true );

	if ( ! is_array( $json ) || empty( $json['client_email'] ) || empty( $json['private_key'] ) ) {
		return null;
	}

	return array(
		'email'     => (string) $json['client_email'],
		'key'       => (string) $json['private_key'],
		'token_uri' => ! empty( $json['token_uri'] ) ? (string) $json['token_uri'] : 'https://oauth2.googleapis.com/token',
	);
}

/* =============================================================== auth === */

function daw_gsc_b64url( $data ) {
	return rtrim( strtr( base64_encode( (string) $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
}

/** An access token, kept for fifty minutes. */
function daw_gsc_token() {
	$cached = get_transient( 'daw_gsc_token_w2' );

	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}

	$creds = daw_gsc_creds();

	if ( ! $creds ) {
		return new WP_Error( 'daw_gsc_no_creds', 'Servis hesabı JSON anahtarı girilmemiş.' );
	}
	if ( ! function_exists( 'openssl_sign' ) ) {
		return new WP_Error( 'daw_gsc_no_openssl', 'Sunucuda OpenSSL yok.' );
	}

	$now    = time();
	$header = daw_gsc_b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
	$claims = daw_gsc_b64url(
		wp_json_encode(
			array(
				'iss'   => $creds['email'],
				'scope' => DAW_GSC_SCOPE,
				'aud'   => $creds['token_uri'],
				'iat'   => $now,
				'exp'   => $now + 3600,
			)
		)
	);

	$signature = '';
	$key       = openssl_pkey_get_private( $creds['key'] );

	if ( ! $key || ! openssl_sign( $header . '.' . $claims, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
		return new WP_Error( 'daw_gsc_sign', 'Anahtar okunamadı: JSON dosyasının tamamı mı yapıştırıldı?' );
	}

	$response = wp_remote_post(
		$creds['token_uri'],
		array(
			'timeout' => 20,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $header . '.' . $claims . '.' . daw_gsc_b64url( $signature ),
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( empty( $body['access_token'] ) ) {
		$why = isset( $body['error_description'] ) ? (string) $body['error_description'] : (string) wp_remote_retrieve_response_code( $response );
		return new WP_Error( 'daw_gsc_token', 'Google belirteç vermedi: ' . $why );
	}

	set_transient( 'daw_gsc_token_w2', (string) $body['access_token'], 50 * MINUTE_IN_SECONDS );

	return (string) $body['access_token'];
}

/** One call to the Search Console API. */
function daw_gsc_api( $method, $url, $body = null ) {
	$token = daw_gsc_token();

	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$args = array(
		'method'  => $method,
		'timeout' => 45,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
	);

	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	} elseif ( in_array( $method, array( 'PUT', 'POST' ), true ) ) {
		// Google answers 411 (Length Required) to a PUT with no body at all,
		// which is what the sitemap submission is.
		$args['body']                      = '';
		$args['headers']['Content-Length'] = '0';
	}

	$response = wp_remote_request( $url, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( $code >= 400 ) {
		$msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'HTTP ' . $code;
		if ( 403 === $code ) {
			$msg .= ' (Servis hesabının e-postası Search Console mülküne kullanıcı olarak eklendi mi?)';
		}
		return new WP_Error( 'daw_gsc_http', $msg );
	}

	return is_array( $data ) ? $data : array();
}

/** The properties the service account can see. */
function daw_gsc_sites() {
	$data = daw_gsc_api( 'GET', 'https://www.googleapis.com/webmasters/v3/sites' );

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	return wp_list_pluck( (array) ( $data['siteEntry'] ?? array() ), 'siteUrl' );
}

/** Search Analytics, all pages of rows. */
function daw_gsc_query( array $body ) {
	$prop = (string) daw_gsc_settings()['property'];
	$url  = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $prop ) . '/searchAnalytics/query';
	$rows = array();
	$skip = 0;

	do {
		$body['rowLimit'] = 25000;
		$body['startRow'] = $skip;

		$data = daw_gsc_api( 'POST', $url, $body );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$batch = (array) ( $data['rows'] ?? array() );
		$rows  = array_merge( $rows, $batch );
		$skip += count( $batch );
	} while ( count( $batch ) >= 25000 && $skip < 200000 );

	return $rows;
}

/** A full url from Google, as the path this site files it under. */
function daw_gsc_path( $url ) {
	$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );

	return '' !== $path ? $path : '/';
}

/* =============================================================== sync === */

// admin_init, not init: the credentials option is not autoloaded, and reading
// it on every visitor request would be a query per page view for nothing.
add_action( 'admin_init', 'daw_gsc_schedule' );
function daw_gsc_schedule() {
	if ( daw_gsc_creds() && ! wp_next_scheduled( 'daw_gsc_sync' ) ) {
		wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'daily', 'daw_gsc_sync' );
	}
}

/**
 * Fetch the days not yet stored, thirty at a time. The first run goes back
 * 480 days (Search Console keeps sixteen months) and continues itself every
 * two minutes until it reaches the newest finished day.
 */
add_action( 'daw_gsc_sync', 'daw_gsc_sync_run' );
function daw_gsc_sync_run() {
	if ( ! daw_gsc_creds() ) {
		return;
	}

	daw_gsc_import_rank_math();
	daw_gsc_pick_property();

	$s    = daw_gsc_settings();
	$last = (string) $s['last_day'];
	$end  = gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );

	$start = '' !== $last ? gmdate( 'Y-m-d', strtotime( $last . ' +1 day' ) ) : gmdate( 'Y-m-d', time() - 480 * DAY_IN_SECONDS );

	if ( $start <= $end ) {
		$to = min( $end, gmdate( 'Y-m-d', strtotime( $start . ' +29 days' ) ) );

		$rows = daw_gsc_query(
			array(
				'startDate'  => $start,
				'endDate'    => $to,
				'dimensions' => array( 'date', 'page' ),
				'type'       => 'web',
			)
		);

		if ( is_wp_error( $rows ) ) {
			daw_gsc_save( array( 'error' => $rows->get_error_message() ) );
			return;
		}

		global $wpdb;

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $r ) {
				$values[] = $wpdb->prepare(
					'(%s, %s, %d, %d, %f)',
					(string) $r['keys'][0],
					substr( daw_gsc_path( $r['keys'][1] ), 0, 512 ),
					(int) $r['clicks'],
					(int) $r['impressions'],
					(float) $r['position']
				);
			}
			if ( $values ) {
				$wpdb->query( 'REPLACE INTO ' . daw_gsc_table() . ' (day, page, clicks, impressions, position) VALUES ' . implode( ',', $values ) ); // phpcs:ignore WordPress.DB
			}
		}

		daw_gsc_save(
			array(
				'last_day'  => $to,
				'synced_at' => time(),
				'error'     => '',
			)
		);

		if ( $to < $end ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'daw_gsc_sync' );
			return;
		}
	}

	if ( time() - (int) daw_gsc_settings()['q_at'] > 20 * HOUR_IN_SECONDS ) {
		daw_gsc_sync_queries();
		daw_gsc_feed_missing();
	}
}

/**
 * Addresses Google is still showing in search that no longer exist here go
 * into the 404 log, marked as coming from Search Console, so the hourly
 * auto-fix (seo-redirects.php) can send them to their page before a reader
 * ever lands on the error.
 */
function daw_gsc_feed_missing() {
	if ( ! function_exists( 'daw_seo_path_exists' ) || ! daw_rd_tables_ready() ) {
		return 0;
	}

	global $wpdb;

	$paths = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT page FROM ' . daw_gsc_table() . ' WHERE day >= %s AND impressions > 0 LIMIT 5000', gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	$n     = 0;
	$now   = current_time( 'mysql', true );

	foreach ( $paths as $page ) {
		$path = daw_gsc_path( $page );

		if ( daw_seo_path_exists( $path ) || daw_gsc_has_redirect( $path ) ) {
			continue;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'INSERT INTO ' . daw_nf_table() . " (path, path_hash, kind, hits_search, first_seen, last_seen, last_ref, last_ua) VALUES (%s, %s, 'page', 1, %s, %s, 'Search Console', '')
				 ON DUPLICATE KEY UPDATE last_ref = 'Search Console'",
				substr( $path, 0, 512 ),
				md5( $path ),
				$now,
				$now
			)
		);
		++$n;
	}

	return $n;
}

/** Whether an exact redirect already answers this path. */
function daw_gsc_has_redirect( $path ) {
	global $wpdb;

	return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . daw_rd_table() . " WHERE status = 'active' AND match_type = 'exact' AND source = %s LIMIT 1", daw_rd_norm( $path ) ) ); // phpcs:ignore WordPress.DB
}

/* ========================================================= inspection === */

/**
 * Google's own verdict on each page, from the URL Inspection API.
 *
 * Search Analytics says how a page performs; it cannot say whether Google
 * indexed it, which canonical it chose, or whether its last fetch failed.
 * URL Inspection can, one address per call, two thousand calls a day. Forty
 * an hour, oldest first, goes round the whole sitemap in about a day and a
 * half and stays far inside the limit.
 */
add_action( 'admin_init', 'daw_gsc_inspect_schedule' );
function daw_gsc_inspect_schedule() {
	if ( daw_gsc_creds() && ! wp_next_scheduled( 'daw_gsc_inspect' ) ) {
		wp_schedule_event( time() + 30 * MINUTE_IN_SECONDS, 'hourly', 'daw_gsc_inspect' );
	}
}

add_action( 'daw_gsc_inspect', 'daw_gsc_inspect_run' );
function daw_gsc_inspect_run( $limit = 40 ) {
	if ( ! daw_gsc_creds() || ! function_exists( 'daw_sm_types' ) ) {
		return 0;
	}

	global $wpdb;

	$checked = array();
	foreach ( (array) $wpdb->get_results( 'SELECT path_hash, checked FROM ' . daw_gsc_inspect_table(), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
		$checked[ $r['path_hash'] ] = $r['checked'];
	}

	// URLs Google failed to inspect are parked for a day, so one URL that keeps
	// answering an error cannot sit at the head of the queue and stop every run
	// (12 Oct 2026: "Internal error encountered" on one URL froze the whole check).
	$skip = get_option( 'daw_gsc_inspect_skip', array() );
	$skip = is_array( $skip ) ? $skip : array();
	foreach ( $skip as $h => $row ) {
		if ( ! is_array( $row ) || (int) ( $row['at'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
			unset( $skip[ $h ] );
		}
	}

	$todo = array();
	foreach ( daw_sm_types() as $type ) {
		foreach ( daw_sm_rows( $type ) as $row ) {
			$url  = (string) get_permalink( $row[0] );
			$hash = md5( daw_gsc_path( $url ) );
			if ( isset( $skip[ $hash ] ) ) {
				continue;
			}
			$todo[ $url ] = $checked[ $hash ] ?? '0000-00-00 00:00:00';
		}
	}

	asort( $todo );
	$todo   = array_slice( array_keys( $todo ), 0, (int) $limit );
	$prop   = (string) daw_gsc_settings()['property'];
	$done   = 0;
	$failed = array();
	$streak = 0;
	$last   = '';
	$quota  = false;

	foreach ( $todo as $url ) {
		$data = daw_gsc_api(
			'POST',
			'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
			array(
				'inspectionUrl' => $url,
				'siteUrl'       => $prop,
			)
		);

		if ( is_wp_error( $data ) ) {
			$last = $data->get_error_message();
			// Daily quota (2,000 inspections per property): not a fault, the
			// queue simply waits for tomorrow. Nothing is parked or flagged red.
			if ( preg_match( '/quota|rate limit|RESOURCE_EXHAUSTED|HTTP 429/i', $last ) ) {
				$quota = true;
				break;
			}
			$path                 = daw_gsc_path( $url );
			$skip[ md5( $path ) ] = array(
				'at'   => time(),
				'path' => substr( $path, 0, 200 ),
				'msg'  => substr( $last, 0, 200 ),
			);
			$failed[] = $path;
			// Several failures in a row means Google or the credentials are down,
			// not one URL: stop and say so instead of burning the hourly quota.
			if ( ++$streak >= 3 ) {
				break;
			}
			continue;
		}
		$streak = 0;

		$r    = (array) ( $data['inspectionResult']['indexStatusResult'] ?? array() );
		$path = daw_gsc_path( $url );
		$wpdb->replace( // phpcs:ignore WordPress.DB
			daw_gsc_inspect_table(),
			array(
				'path'             => substr( $path, 0, 512 ),
				'path_hash'        => md5( $path ),
				'verdict'          => substr( (string) ( $r['verdict'] ?? '' ), 0, 20 ),
				'coverage'         => substr( (string) ( $r['coverageState'] ?? '' ), 0, 120 ),
				'indexing'         => substr( (string) ( $r['indexingState'] ?? '' ), 0, 40 ),
				'fetch_state'      => substr( (string) ( $r['pageFetchState'] ?? '' ), 0, 40 ),
				'robots_state'     => substr( (string) ( $r['robotsTxtState'] ?? '' ), 0, 20 ),
				'google_canonical' => substr( (string) ( $r['googleCanonical'] ?? '' ), 0, 512 ),
				'user_canonical'   => substr( (string) ( $r['userCanonical'] ?? '' ), 0, 512 ),
				'last_crawl'       => ! empty( $r['lastCrawlTime'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $r['lastCrawlTime'] ) ) : null,
				'checked'          => current_time( 'mysql', true ),
			)
		);
		++$done;
	}

	update_option( 'daw_gsc_inspect_skip', $skip, false );

	$err  = (string) daw_gsc_settings()['error'];
	$mine = 0 === strpos( $err, 'URL denetimi:' );
	if ( $quota ) {
		$save = array( 'inspect_note' => sprintf( 'Google URL denetimi günlük kotası doldu (%d URL denetlendi); kalanlar yarın sürer.', $done ) );
		if ( $mine ) {
			$save['error'] = '';
		}
		daw_gsc_save( $save );
	} elseif ( 0 === $done && $failed ) {
		// Nothing got through: a real outage, shown as an error.
		daw_gsc_save(
			array(
				'error'        => 'URL denetimi: ' . $last . ' (' . count( $failed ) . ' URL denendi, ilk: ' . $failed[0] . ')',
				'inspect_note' => '',
			)
		);
	} else {
		// At least one URL answered: the connection works. A few URLs Google could
		// not inspect are a note, not an error, and they are retried tomorrow.
		$note = $failed ? sprintf( '%d URL Google tarafında denetlenemedi, yarın yeniden denenecek: %s', count( $failed ), implode( ', ', array_slice( $failed, 0, 5 ) ) ) : '';
		$save = array( 'inspect_note' => $note );
		if ( $mine && $done > 0 ) {
			$save['error'] = '';
		}
		daw_gsc_save( $save );
	}

	return $done;
}

/** Google's last verdict on one path, or null. */
function daw_gsc_inspection_for_path( $path ) {
	global $wpdb;

	if ( (int) get_option( 'daw_gsc_db', 0 ) < DAW_GSC_DB_VERSION ) {
		return null;
	}

	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . daw_gsc_inspect_table() . ' WHERE path_hash = %s', md5( (string) $path ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
}

/**
 * What in Google's verdict needs a person: not indexed, a failed fetch, a
 * robots block, or Google choosing a different canonical than the page names.
 *
 * @return string[]
 */
function daw_gsc_inspection_problems( array $r ) {
	$out = array();

	if ( 'PASS' !== $r['verdict'] && '' !== $r['coverage'] ) {
		$out[] = $r['coverage'];
	}
	if ( '' !== $r['fetch_state'] && 'SUCCESSFUL' !== $r['fetch_state'] && 'PAGE_FETCH_STATE_UNSPECIFIED' !== $r['fetch_state'] ) {
		$out[] = 'Getirme: ' . $r['fetch_state'];
	}
	if ( 'DISALLOWED' === $r['robots_state'] ) {
		$out[] = 'robots.txt engelliyor';
	}
	if ( '' !== $r['google_canonical'] && '' !== $r['user_canonical'] && untrailingslashit( $r['google_canonical'] ) !== untrailingslashit( $r['user_canonical'] ) ) {
		$out[] = 'Google başka canonical seçti: ' . $r['google_canonical'];
	}

	return $out;
}

/**
 * Use the property with the longer history.
 *
 * This site has two: the URL property https://discoverasianworld.com/, with
 * data going back years, and the domain property, which was only added in
 * September 2026 and has nothing before 15 September. Both report the same
 * pages. If the service account can see the URL property it is used;
 * otherwise the domain one. A change restarts the download from the start.
 */
function daw_gsc_pick_property() {
	$s = daw_gsc_settings();

	if ( ! empty( $s['property_pinned'] ) ) {
		return;
	}

	$sites = daw_gsc_sites();
	if ( is_wp_error( $sites ) || ! $sites ) {
		return;
	}

	$url    = home_url( '/' );
	$domain = 'sc-domain:' . preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$pick   = in_array( $url, $sites, true ) ? $url : ( in_array( $domain, $sites, true ) ? $domain : (string) $s['property'] );

	if ( $pick !== $s['property'] ) {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . daw_gsc_table() ); // phpcs:ignore WordPress.DB
		daw_gsc_save(
			array(
				'property' => $pick,
				'last_day' => '',
				'q_at'     => 0,
			)
		);
	}
}

/** The searches behind each page, last 28 finished days. */
function daw_gsc_sync_queries() {
	$end   = gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );
	$start = gmdate( 'Y-m-d', strtotime( $end . ' -27 days' ) );

	$rows = daw_gsc_query(
		array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => array( 'page', 'query' ),
			'type'       => 'web',
		)
	);

	if ( is_wp_error( $rows ) ) {
		daw_gsc_save( array( 'error' => $rows->get_error_message() ) );
		return;
	}

	global $wpdb;

	$wpdb->query( 'TRUNCATE TABLE ' . daw_gsc_q_table() ); // phpcs:ignore WordPress.DB

	foreach ( array_chunk( $rows, 500 ) as $chunk ) {
		$values = array();
		foreach ( $chunk as $r ) {
			$values[] = $wpdb->prepare(
				'(%s, %s, %d, %d, %f)',
				substr( daw_gsc_path( $r['keys'][0] ), 0, 512 ),
				mb_substr( (string) $r['keys'][1], 0, 255 ),
				(int) $r['clicks'],
				(int) $r['impressions'],
				(float) $r['position']
			);
		}
		if ( $values ) {
			$wpdb->query( 'INSERT INTO ' . daw_gsc_q_table() . ' (page, query, clicks, impressions, position) VALUES ' . implode( ',', $values ) ); // phpcs:ignore WordPress.DB
		}
	}

	daw_gsc_save( array( 'q_at' => time() ) );

	// perf.php keeps weekly snapshots of these, for the per-page trend.
	do_action( 'daw_gsc_queries_synced', $rows, $end );
}

/** Rank Math's stored history, copied once. */
function daw_gsc_import_rank_math() {
	if ( get_option( 'daw_gsc_rm_imported' ) ) {
		return;
	}

	global $wpdb;

	$rm = $wpdb->prefix . 'rank_math_analytics_gsc';

	if ( $rm === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rm ) ) ) { // phpcs:ignore WordPress.DB
		$wpdb->query( // phpcs:ignore WordPress.DB
			'INSERT IGNORE INTO ' . daw_gsc_table() . ' (day, page, clicks, impressions, position)
			 SELECT DATE(created), page, SUM(clicks), SUM(impressions),
			        IF(SUM(impressions) > 0, SUM(position * impressions) / SUM(impressions), AVG(position))
			 FROM ' . $rm . ' GROUP BY DATE(created), page'
		);
	}

	update_option( 'daw_gsc_rm_imported', time(), false );
}

/* ============================================================ reading === */

/** Whether our own table holds anything yet. */
function daw_gsc_has_data() {
	static $has = null;

	if ( null === $has ) {
		global $wpdb;
		$has = (int) get_option( 'daw_gsc_db', 0 ) >= DAW_GSC_DB_VERSION
			&& (bool) $wpdb->get_var( 'SELECT 1 FROM ' . daw_gsc_table() . ' LIMIT 1' ); // phpcs:ignore WordPress.DB
	}

	return $has;
}

/**
 * Clicks, impressions and position of one page over the last days.
 *
 * @return array{clicks:int, impressions:int, position:?float}|null
 */
function daw_gsc_for_path( $path, $days = 28 ) {
	if ( ! daw_gsc_has_data() ) {
		return null;
	}

	global $wpdb;

	$since = gmdate( 'Y-m-d', time() - ( (int) $days + 2 ) * DAY_IN_SECONDS );
	$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			'SELECT SUM(clicks) c, SUM(impressions) i, IF(SUM(impressions) > 0, SUM(position * impressions) / SUM(impressions), NULL) p
			 FROM ' . daw_gsc_table() . ' WHERE ( page = %s OR page = %s OR page = %s ) AND day >= %s',
			$path,
			home_url( $path ),
			untrailingslashit( home_url() ) . $path,
			$since
		),
		ARRAY_A
	);

	if ( ! $row || null === $row['c'] ) {
		return null;
	}

	return array(
		'clicks'      => (int) $row['c'],
		'impressions' => (int) $row['i'],
		'position'    => null !== $row['p'] ? round( (float) $row['p'], 1 ) : null,
	);
}

/** The searches one page was shown for, best first. */
function daw_gsc_queries_for_path( $path, $limit = 10 ) {
	global $wpdb;

	if ( (int) get_option( 'daw_gsc_db', 0 ) < DAW_GSC_DB_VERSION ) {
		return array();
	}

	return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			'SELECT query, clicks, impressions, position FROM ' . daw_gsc_q_table() . ' WHERE page = %s ORDER BY clicks DESC, impressions DESC LIMIT %d',
			$path,
			(int) $limit
		),
		ARRAY_A
	);
}
