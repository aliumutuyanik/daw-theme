<?php
/**
 * Discover Asian World: IndexNow, the instant "this page changed" ping.
 *
 * Bing, Yandex, Seznam, Naver and the other IndexNow engines take a list of
 * changed urls and fetch them within minutes instead of waiting for their next
 * crawl. Google does not take part; for Google the sitemap's lastmod does the
 * same job. Rank Math's Instant Indexing module did this; its key is reused so
 * the engines keep trusting the same file.
 *
 * Pings are collected for a minute and sent as one list, so saving a record
 * five times, or a batch job touching fifty, is one request and not fifty.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** The key, Rank Math's if it had one, a new one otherwise. */
function daw_inx_key() {
	$key = (string) daw_eng_set( 'indexnow_key', '' );

	if ( ! preg_match( '/^[a-zA-Z0-9-]{8,128}$/', $key ) ) {
		$key = str_replace( '-', '', wp_generate_uuid4() );
		daw_eng_settings_save( array( 'indexnow_key' => $key ) );
	}

	return $key;
}

/** /{key}.txt answers with the key, which is how the engines verify the site. */
add_action( 'parse_request', 'daw_inx_serve_key', 1 );
function daw_inx_serve_key() {
	if ( is_admin() || ! daw_eng_live() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	if ( '.txt' !== substr( $path, -4 ) ) {
		return;
	}

	$key = (string) daw_eng_set( 'indexnow_key', '' );

	if ( '' !== $key && '/' . $key . '.txt' === $path ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex', true );
		echo esc_html( $key );
		exit;
	}
}

/** Queue a url when a post or page goes live, changes while live, or is taken down. */
add_action( 'transition_post_status', 'daw_inx_on_status', 20, 3 );
function daw_inx_on_status( $new, $old, $post ) {
	if ( ! daw_eng_live() || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}
	if ( 'publish' !== $new && 'publish' !== $old ) {
		return;
	}
	if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || '' !== $post->post_password ) {
		return;
	}

	$robots = get_post_meta( $post->ID, 'rank_math_robots', true );
	if ( 'publish' === $new && is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
		return;
	}

	$url = get_permalink( $post );
	if ( $url ) {
		daw_inx_queue( $url );
	}
}

function daw_inx_queue( $url ) {
	// The background jobs re-save records in batches. One ping per address
	// per twelve hours is plenty, and stays well clear of the rate limit.
	$recent = (array) get_option( 'daw_inx_recent', array() );
	$now    = time();
	$recent = array_filter(
		$recent,
		static function ( $t ) use ( $now ) {
			return $now - (int) $t < 12 * HOUR_IN_SECONDS;
		}
	);

	if ( isset( $recent[ $url ] ) ) {
		return;
	}

	$recent[ $url ] = $now;
	update_option( 'daw_inx_recent', $recent, false );

	$queue   = (array) get_option( 'daw_inx_queue', array() );
	$queue[] = (string) $url;

	update_option( 'daw_inx_queue', array_values( array_unique( $queue ) ), false );

	if ( ! wp_next_scheduled( 'daw_inx_send' ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'daw_inx_send' );
	}
}

add_action( 'daw_inx_send', 'daw_inx_send_run' );
function daw_inx_send_run() {
	$queue = array_values( array_filter( (array) get_option( 'daw_inx_queue', array() ) ) );

	if ( ! $queue ) {
		return;
	}

	delete_option( 'daw_inx_queue' );

	return daw_inx_submit( $queue );
}

/**
 * Send a list of urls. Up to 10,000 per request is allowed; this site never
 * comes close.
 *
 * @return int|WP_Error HTTP status.
 */
function daw_inx_submit( array $urls ) {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$key  = daw_inx_key();

	$response = wp_remote_post(
		'https://api.indexnow.org/indexnow',
		array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode(
				array(
					'host'        => $host,
					'key'         => $key,
					'keyLocation' => home_url( '/' . $key . '.txt' ),
					'urlList'     => array_slice( array_values( array_unique( $urls ) ), 0, 10000 ),
				)
			),
		)
	);

	$code = is_wp_error( $response ) ? $response->get_error_message() : (int) wp_remote_retrieve_response_code( $response );

	$log   = (array) get_option( 'daw_inx_log', array() );
	$log[] = array(
		'at'   => time(),
		'n'    => count( $urls ),
		'code' => $code,
		'urls' => array_slice( $urls, 0, 5 ),
	);
	update_option( 'daw_inx_log', array_slice( $log, -50 ), false );

	return is_wp_error( $response ) ? $response : (int) $code;
}
