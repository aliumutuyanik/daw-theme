<?php
/**
 * How many people actually read a record.
 *
 * WHY THIS EXISTS
 * ---------------
 * The home page is supposed to lead with the record people are reading, not the
 * record that happens to sort first. That sentence is only honest if something
 * real is counting, so this file counts, and everything that claims popularity
 * anywhere on the site reads its numbers from here and from nowhere else.
 *
 * WHY IT IS HIDDEN FOR NOW
 * ------------------------
 * A visible counter on a page with four readers a day advertises that it has
 * four readers a day. So the number is collected now, quietly, and stays out of
 * the templates until there is enough of it to be worth showing. Turning it on
 * later is one function call, daw_views_public(), and no data is lost in the
 * meantime.
 *
 * WHY A BEACON AND NOT A PHP COUNTER
 * ----------------------------------
 * LiteSpeed serves most pages straight from its cache, so PHP never runs on the
 * requests that matter. A counter incremented during render would therefore
 * count roughly one view per cache lifetime and miss every real reader. The
 * page instead asks the browser to report itself, which has a second and larger
 * benefit: almost no crawler runs JavaScript, so the obvious source of fake
 * numbers drops out without a blocklist.
 *
 * WHAT IS STORED ABOUT A READER
 * -----------------------------
 * Nothing. The row is (record, day, how many), with no identifier of any kind.
 * The repeat guard hashes the address together with the browser string, keeps
 * the hash for thirty minutes in a transient so one reader refreshing a page
 * ten times is one view, and then the hash expires. The address itself is never
 * written anywhere.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** Bumped when the table shape changes. */
define( 'DAW_VIEWS_SCHEMA', 1 );

/** How long a reader is the same reader. */
define( 'DAW_VIEWS_REPEAT', 30 * MINUTE_IN_SECONDS );

/** How many days of rows are kept. Ten more than the window the site reads. */
define( 'DAW_VIEWS_KEEP', 40 );

/** Set true the day the counts go on the page. Nothing else has to change. */
function daw_views_public() {
	return (bool) apply_filters( 'daw_views_public', false );
}

function daw_views_table() {
	global $wpdb;

	return $wpdb->prefix . 'daw_views';
}

/* ================================================================ table === */

add_action( 'admin_init', 'daw_views_install', 5 );
function daw_views_install() {
	if ( (int) get_option( 'daw_views_schema' ) === DAW_VIEWS_SCHEMA ) {
		return;
	}

	global $wpdb;

	$table   = daw_views_table();
	$collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta(
		"CREATE TABLE {$table} (
			post_id BIGINT(20) UNSIGNED NOT NULL,
			day DATE NOT NULL,
			hits INT(10) UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (post_id,day),
			KEY day (day)
		) {$collate};"
	);

	update_option( 'daw_views_schema', DAW_VIEWS_SCHEMA, false );
}

/* ============================================================== the hit === */

add_action( 'rest_api_init', 'daw_views_routes' );
function daw_views_routes() {
	register_rest_route(
		'daw/v1',
		'/v',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => 'daw_views_hit',
			'args'                => array(
				'post_id' => array(
					'required'          => true,
					'validate_callback' => static function ( $v ) {
						return is_numeric( $v ) && (int) $v > 0;
					},
				),
			),
		)
	);
}

/**
 * One reader, one record, one day.
 *
 * Everything that could be an argument later is refused here instead: an
 * unpublished record, a record that is not ours, a logged-in editor walking the
 * site, and anything whose browser string says it is a robot. What survives all
 * four is a person reading a page.
 */
function daw_views_hit( WP_REST_Request $req ) {
	$post_id = (int) $req->get_param( 'post_id' );
	$post    = get_post( $post_id );

	if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return new WP_REST_Response( null, 204 );
	}

	if ( is_user_logged_in() ) {
		return new WP_REST_Response( null, 204 );
	}

	$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	if ( '' === $agent || daw_views_is_robot( $agent ) ) {
		return new WP_REST_Response( null, 204 );
	}

	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'daw_v_' . md5( $ip . '|' . $agent . '|' . $post_id );

	if ( get_transient( $key ) ) {
		return new WP_REST_Response( null, 204 );
	}

	set_transient( $key, 1, DAW_VIEWS_REPEAT );

	daw_views_add( $post_id, 1 );

	return new WP_REST_Response( null, 204 );
}

/**
 * Browser strings that are not readers.
 *
 * This is a second line rather than the first one. The first line is that the
 * beacon only runs in a browser that runs scripts, which almost no crawler
 * does. This catches the handful that do.
 */
function daw_views_is_robot( $agent ) {
	$marks = array(
		'bot', 'crawl', 'spider', 'slurp', 'scrape', 'curl', 'wget', 'python',
		'headless', 'phantom', 'preview', 'monitor', 'pingdom', 'lighthouse',
		'gtmetrix', 'pagespeed', 'facebookexternalhit', 'embedly', 'quora link',
		'whatsapp', 'telegram', 'chatgpt', 'gptbot', 'claude', 'perplexity',
	);

	$agent = strtolower( $agent );

	foreach ( $marks as $mark ) {
		if ( false !== strpos( $agent, $mark ) ) {
			return true;
		}
	}

	return false;
}

/** Add to today's row for one record. */
function daw_views_add( $post_id, $hits = 1 ) {
	global $wpdb;

	$table = daw_views_table();
	$day   = gmdate( 'Y-m-d' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (post_id, day, hits) VALUES (%d, %s, %d)
			 ON DUPLICATE KEY UPDATE hits = hits + %d",
			$post_id,
			$day,
			$hits,
			$hits
		)
	);
}

/* ============================================================== reading === */

/**
 * Views in the last thirty days.
 *
 * Read from post meta, not from the table, because the home page sorts hundreds
 * of records by this number on every uncached render and a join per record
 * would undo the work of the last week. The meta is written once a day by the
 * rollup below, which is exactly as fresh as a thirty day window needs to be.
 */
function daw_views_30( $post_id ) {
	return (int) get_post_meta( (int) $post_id, 'daw_views_30', true );
}

/** Views since counting began. */
function daw_views_all( $post_id ) {
	return (int) get_post_meta( (int) $post_id, 'daw_views_all', true );
}

/**
 * Sort ids by how much they were read, most first.
 *
 * Records nobody has read yet keep the order they arrived in, so a list that
 * has no numbers in it at all comes back exactly as it went in. That is what
 * makes it safe to sort every strip on the home page through here today, while
 * the counter is still empty, and have nothing change until it is not.
 */
function daw_views_sort( $ids ) {
	$ids = array_values( array_map( 'intval', (array) $ids ) );

	if ( count( $ids ) < 2 ) {
		return $ids;
	}

	if ( function_exists( 'daw_browse_prime' ) ) {
		daw_browse_prime( $ids );
	}

	$rank = array();

	foreach ( $ids as $i => $id ) {
		$rank[ $id ] = array( daw_views_30( $id ), -$i );
	}

	usort(
		$ids,
		static function ( $a, $b ) use ( $rank ) {
			return $rank[ $b ] <=> $rank[ $a ];
		}
	);

	return $ids;
}

/* ============================================================== rollup === */

add_action( 'daw_views_cron', 'daw_views_rollup' );
add_action( 'admin_init', 'daw_views_maybe_rollup', 32 );

/**
 * WP cron only fires when somebody visits, and on a quiet staging site that can
 * be days. So the admin checks too, once a day at most, and only on a request
 * that is not doing anything delicate.
 */
function daw_views_maybe_rollup() {
	if ( function_exists( 'daw_is_delicate_request' ) && daw_is_delicate_request() ) {
		return;
	}

	if ( ! wp_next_scheduled( 'daw_views_cron' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'daw_views_cron' );
	}

	$last = (int) get_option( 'daw_views_rolled' );

	if ( $last && ( time() - $last ) < DAY_IN_SECONDS ) {
		return;
	}

	daw_views_rollup();
}

/**
 * Turn the day rows into the two numbers the templates read, then throw away
 * the rows that have aged out of the window.
 */
function daw_views_rollup() {
	global $wpdb;

	$table = daw_views_table();
	$since = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_id, SUM(hits) AS hits FROM {$table} WHERE day >= %s GROUP BY post_id",
			$since
		)
	);

	$fresh = array();

	foreach ( (array) $rows as $row ) {
		$id            = (int) $row->post_id;
		$fresh[ $id ] = (int) $row->hits;
	}

	// Every record that carried a number yesterday and carries none today has to
	// be cleared, or a record read once last month outranks one read today.
	$stale = get_posts(
		array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => 'daw_views_30', // phpcs:ignore WordPress.DB.SlowDBQuery
			'no_found_rows'  => true,
		)
	);

	foreach ( (array) $stale as $id ) {
		if ( ! isset( $fresh[ (int) $id ] ) ) {
			delete_post_meta( (int) $id, 'daw_views_30' );
		}
	}

	foreach ( $fresh as $id => $hits ) {
		update_post_meta( $id, 'daw_views_30', $hits );
	}

	daw_views_rollup_total();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE day < %s",
			gmdate( 'Y-m-d', time() - DAW_VIEWS_KEEP * DAY_IN_SECONDS )
		)
	);

	update_option( 'daw_views_rolled', time(), false );
}

/**
 * The all time total is carried forward rather than recomputed, because the
 * rows it was built from get deleted after forty days. It adds only the days it
 * has not added before, which is what the watermark option remembers.
 */
function daw_views_rollup_total() {
	global $wpdb;

	$table = daw_views_table();
	$from  = get_option( 'daw_views_total_to' );
	$from  = $from ? (string) $from : gmdate( 'Y-m-d', time() - DAW_VIEWS_KEEP * DAY_IN_SECONDS );
	$to    = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );

	if ( $to < $from ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_id, SUM(hits) AS hits FROM {$table} WHERE day >= %s AND day <= %s GROUP BY post_id",
			$from,
			$to
		)
	);

	foreach ( (array) $rows as $row ) {
		$id  = (int) $row->post_id;
		$now = (int) get_post_meta( $id, 'daw_views_all', true );

		update_post_meta( $id, 'daw_views_all', $now + (int) $row->hits );
	}

	update_option( 'daw_views_total_to', gmdate( 'Y-m-d', time() ), false );
}

add_action( 'daw_core_deactivate', 'daw_views_unschedule' );
function daw_views_unschedule() {
	$next = wp_next_scheduled( 'daw_views_cron' );

	if ( $next ) {
		wp_unschedule_event( $next, 'daw_views_cron' );
	}
}

/* ============================================================== beacon === */

add_action( 'wp_footer', 'daw_views_beacon', 99 );

/**
 * The page reports itself.
 *
 * Five seconds, and only while the tab is actually in front of the reader. A
 * page opened in a background tab and never looked at is not a read, and
 * neither is a bounce off the wrong search result. It fires once, it sends one
 * number, and it never blocks anything: keepalive lets the request outlive the
 * page so leaving immediately still counts, without holding the unload up.
 */
function daw_views_beacon() {
	if ( is_user_logged_in() || ! is_singular() ) {
		return;
	}

	$id = (int) get_queried_object_id();

	if ( ! $id ) {
		return;
	}

	printf(
		'<script>(function(){var s=false,t=null;function go(){if(s)return;s=true;try{fetch(%s,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({post_id:%d}),keepalive:true,credentials:"omit"}).catch(function(){});}catch(e){}}function arm(){if(document.visibilityState!=="visible"){return;}if(t)return;t=setTimeout(go,5000);}document.addEventListener("visibilitychange",function(){if(document.visibilityState==="visible"){arm();}else if(t){clearTimeout(t);t=null;}});arm();}());</script>',
		wp_json_encode( esc_url_raw( rest_url( 'daw/v1/v' ) ) ),
		$id
	);
}
