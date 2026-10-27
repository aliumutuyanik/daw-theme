<?php
/**
 * Posters from TMDB, without an API key.
 *
 * WHY NO API
 * ----------
 * An API key is a credential to store, rotate and leak. Every TMDB title page
 * already publishes its own poster in an og:image tag, because that is how the
 * page renders on social media — so the poster is public, stable, and reachable
 * with one ordinary request. That is all this needs.
 *
 * WHAT IT TAKES AND WHAT IT LEAVES ALONE
 * --------------------------------------
 * One poster per record, downloaded once into the media library and served from
 * this domain, used to identify the work it belongs to. No galleries, no
 * backdrops, no stills — those are a licensing question, and until someone
 * answers it the gallery field stays empty on purpose.
 *
 * Hot-linking image.tmdb.org would be smaller work and worse judgement: it puts
 * every reader's ip on someone else's server and every poster on the site
 * disappears the day that server says no.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_TMDB_HOOK = 'daw_fetch_tmdb_poster';

/* ================================================================== api === */

/**
 * The API key, which nobody but the site owner ever types.
 *
 * It lives in an option so that it can be entered in wp-admin and never has to
 * pass through anybody's hands on the way there. A constant in wp-config.php
 * wins over it, for anyone who would rather keep it out of the database.
 */
function daw_tmdb_key() {
	if ( defined( 'DAW_TMDB_KEY' ) && DAW_TMDB_KEY ) {
		return (string) DAW_TMDB_KEY;
	}

	return trim( (string) get_option( 'daw_tmdb_key' ) );
}

/**
 * Which TMDB record a post points at, as a kind and a number.
 *
 * The field holds whatever the editor pasted, so this accepts a full url and a
 * bare id equally. TMDB keeps films, series and people in three separate id
 * spaces and the same number means a different thing in each, so the kind is
 * never guessed from the number.
 */
function daw_tmdb_ref( $post_id ) {
	$raw = trim( (string) daw_field_raw( $post_id, 'daw_tmdb_id' ) );

	if ( '' === $raw ) {
		return array();
	}

	if ( preg_match( '#themoviedb\.org/(movie|tv|person)/(\d+)#i', $raw, $m ) ) {
		return array(
			'kind' => strtolower( $m[1] ),
			'id'   => (int) $m[2],
		);
	}

	if ( ctype_digit( $raw ) ) {
		$type = daw_get_type( $post_id );
		$kind = 'tv';

		if ( 'film' === $type ) {
			$kind = 'movie';
		} elseif ( in_array( $type, array( 'person', 'group' ), true ) ) {
			$kind = 'person';
		}

		$ref = array(
			'kind' => $kind,
			'id'   => (int) $raw,
		);
		// A season page (madde 65) points at its series plus the season number.
		$season = defined( 'DAW_SEASON_NO' ) ? (int) get_post_meta( $post_id, DAW_SEASON_NO, true ) : 0;
		if ( 'tv' === $kind && $season > 0 ) {
			$ref['season'] = $season;
		}
		return $ref;
	}

	return array();
}

/**
 * Ask the API where the poster is.
 *
 * WHY THIS REPLACED READING THE PAGE
 * ----------------------------------
 * The old route opened the public TMDB page and read the poster path out of its
 * og:image tag. It worked, and it was the wrong way to do it: scraping a page
 * is not a use TMDB permits, and a route that depends on somebody else's markup
 * breaks the day they change a template. The documented API asks for the same
 * file, is allowed to be asked, and answers with a stable field name.
 */
function daw_tmdb_api_poster( $post_id ) {
	$key = daw_tmdb_key();
	$ref = daw_tmdb_ref( $post_id );

	if ( ! $key || ! $ref ) {
		return '';
	}

	// The shared request helper also accepts the Read Access Token.
	if ( function_exists( 'daw_tmdb_api_get' ) ) {
		$body = daw_tmdb_api_get( $ref['kind'] . '/' . $ref['id'] . ( empty( $ref['season'] ) ? '' : '/season/' . (int) $ref['season'] ), array( 'language' => 'en-US' ) );
		$path = is_array( $body )
			? (string) ( 'person' === $ref['kind'] ? ( $body['profile_path'] ?? '' ) : ( $body['poster_path'] ?? '' ) )
			: '';
		return '' === $path ? '' : 'https://image.tmdb.org/t/p/w780' . $path;
	}

	$res = wp_remote_get(
		sprintf(
			'https://api.themoviedb.org/3/%s/%d?api_key=%s',
			$ref['kind'],
			$ref['id'],
			rawurlencode( $key )
		),
		array(
			'timeout'    => 15,
			'user-agent' => 'DiscoverAsianWorld/1.0 (+https://discoverasianworld.com)',
		)
	);

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return '';
	}

	$body = json_decode( wp_remote_retrieve_body( $res ), true );

	if ( ! is_array( $body ) ) {
		return '';
	}

	$path = 'person' === $ref['kind']
		? (string) ( $body['profile_path'] ?? '' )
		: (string) ( $body['poster_path'] ?? '' );

	if ( '' === $path ) {
		return '';
	}

	return 'https://image.tmdb.org/t/p/w780' . $path;
}

/* =============================================================== setting === */

add_action( 'admin_init', 'daw_tmdb_key_setting' );
function daw_tmdb_key_setting() {
	register_setting(
		'reading',
		'daw_tmdb_key',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);

	add_settings_field(
		'daw_tmdb_key',
		__( 'DAW: TMDB API key', 'daw' ),
		'daw_tmdb_key_field',
		'reading'
	);
}

function daw_tmdb_key_field() {
	$set = '' !== daw_tmdb_key();

	printf(
		'<input type="password" name="daw_tmdb_key" value="%s" class="regular-text" autocomplete="off" spellcheck="false"> ',
		esc_attr( (string) get_option( 'daw_tmdb_key' ) )
	);

	printf(
		'<strong>%s</strong>',
		$set ? esc_html__( 'Set', 'daw' ) : esc_html__( 'Not set', 'daw' )
	);

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'With a key, posters are requested from the documented TMDB API. Without one the old route is used, which reads the public page and is not a use TMDB permits. Paste the key once and it is never shown again in full.', 'daw' )
	);
}

/**
 * The TMDB page url for a record, from whatever the editor pasted: a full url,
 * a bare id, or nothing.
 */
function daw_tmdb_url( $post_id ) {
	$raw = trim( (string) daw_field_raw( $post_id, 'daw_tmdb_id' ) );
	if ( '' === $raw ) {
		return '';
	}

	if ( preg_match( '#^https?://#i', $raw ) ) {
		return $raw;
	}

	if ( ctype_digit( $raw ) ) {
		// TMDB keeps films, series and people in three separate id spaces, and
		// the same number means a different thing in each. A person record was
		// being sent to the series url, which is either a 404 or, worse, some
		// unrelated drama's poster on somebody's portrait.
		$type = daw_get_type( $post_id );
		$kind = 'tv';

		if ( 'film' === $type ) {
			$kind = 'movie';
		} elseif ( in_array( $type, array( 'person', 'group' ), true ) ) {
			$kind = 'person';
		}

		$season = defined( 'DAW_SEASON_NO' ) ? (int) get_post_meta( $post_id, DAW_SEASON_NO, true ) : 0;
		return 'https://www.themoviedb.org/' . $kind . '/' . $raw . ( 'tv' === $kind && $season > 0 ? '/season/' . $season : '' );
	}

	return '';
}

/**
 * Read the poster url that a TMDB page publishes about itself.
 *
 * The og:image TMDB serves is a resized derivative; the path inside it is the
 * canonical file, so it is rebuilt at the size we actually want rather than
 * accepting whatever social media happened to need.
 */
function daw_tmdb_poster_url( $page_url ) {
	if ( ! $page_url ) {
		return '';
	}

	$res = wp_remote_get(
		$page_url,
		array(
			'timeout'     => 15,
			'redirection' => 3,
			'user-agent'  => 'Mozilla/5.0 (compatible; DiscoverAsianWorld/1.0; +https://discoverasianworld.com)',
		)
	);

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return '';
	}

	$html = substr( wp_remote_retrieve_body( $res ), 0, 200000 );

	if ( ! preg_match( '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m )
		&& ! preg_match( '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']#i', $html, $m ) ) {
		return '';
	}

	$src = html_entity_decode( $m[1], ENT_QUOTES );

	// …/t/p/<some size>/<file>.jpg  →  …/t/p/original/<file>.jpg
	if ( preg_match( '#/t/p/[^/]+/([A-Za-z0-9._-]+\.(?:jpg|png|webp))#i', $src, $f ) ) {
		return 'https://image.tmdb.org/t/p/w780/' . $f[1];
	}

	return $src;
}

/**
 * Fetch the poster and make it the record's featured image.
 *
 * Refuses to run twice: a record that already has a featured image keeps it,
 * unless the caller explicitly asks to replace it. Nobody wants a scheduled job
 * quietly overwriting a poster someone chose by hand.
 */
function daw_fetch_tmdb_poster( $post_id, $replace = false ) {
	$post_id = (int) $post_id;

	// Nothing is downloaded into the media library any more (owner's rule,
	// 1.29): the record only learns TMDB's file name.
	if ( function_exists( 'daw_tmdb_img_sync' ) ) {
		daw_tmdb_img_sync( $post_id );
		return 0;
	}

	if ( ! $replace && has_post_thumbnail( $post_id ) ) {
		return 0;
	}

	// With a key the API is the only route. Falling back to scraping the page
	// when an allowed route exists would be choosing the disallowed one.
	$src = daw_tmdb_key()
		? daw_tmdb_api_poster( $post_id )
		: daw_tmdb_poster_url( daw_tmdb_url( $post_id ) );

	if ( ! $src ) {
		update_post_meta( $post_id, '_daw_tmdb_checked', time() );
		return 0;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = download_url( $src, 25 );
	if ( is_wp_error( $tmp ) ) {
		update_post_meta( $post_id, '_daw_tmdb_checked', time() );
		return 0;
	}

	$size = @getimagesize( $tmp );
	if ( ! $size || $size[0] < 200 ) {
		@unlink( $tmp );
		return 0;
	}

	// A drama has a poster, a person has a portrait. The word ends up in the
	// file name, the attachment title and the alt text, all three of which are
	// read by somebody or something, so it says what the picture actually is.
	$is_person = in_array( daw_get_type( $post_id ), array( 'person', 'group' ), true );
	$word      = $is_person ? 'portrait' : 'poster';

	$title = get_post_field( 'post_name', $post_id );
	$file  = array(
		'name'     => sanitize_file_name( $title . '-' . $word . '.jpg' ),
		'tmp_name' => $tmp,
	);

	$attachment_id = media_handle_sideload(
		$file,
		$post_id,
		sprintf(
			$is_person
				/* translators: %s: person name */
				? __( 'Portrait of %s', 'daw' )
				/* translators: %s: title name */
				: __( 'Poster for %s', 'daw' ),
			get_the_title( $post_id )
		)
	);

	if ( is_wp_error( $attachment_id ) ) {
		@unlink( $tmp );
		return 0;
	}

	// The alt text is what a screen reader announces and what an image search
	// indexes, so it says what the picture is, not what the file is called.
	update_post_meta(
		$attachment_id,
		'_wp_attachment_image_alt',
		sprintf(
			$is_person
				/* translators: %s: person name */
				? __( 'Portrait of %s', 'daw' )
				/* translators: %s: record title */
				: __( 'Poster for %s', 'daw' ),
			get_the_title( $post_id )
		)
	);
	update_post_meta( $attachment_id, '_daw_source', 'TMDB' );
	update_post_meta( $attachment_id, '_daw_source_url', $src );

	set_post_thumbnail( $post_id, $attachment_id );
	update_post_meta( $post_id, '_daw_tmdb_checked', time() );

	return (int) $attachment_id;
}

add_action( DAW_TMDB_HOOK, 'daw_fetch_tmdb_poster', 10, 2 );

/* ================================================================== admin = */

add_action( 'admin_menu', 'daw_tmdb_admin_menu' );
function daw_tmdb_admin_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: TMDB posters', 'daw' ),
		__( 'DAW: TMDB posters', 'daw' ),
		'manage_options',
		'daw-tmdb',
		'daw_tmdb_admin_page'
	);
}

function daw_tmdb_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="wrap"><h1>DAW: TMDB posters</h1>';

	// THE KEY IS ALSO HERE
	// --------------------
	// It is registered as a reading setting, which is where WordPress puts
	// things like this, and which is the last place anybody looks for it. The
	// work happens on this page, so the key lives on this page too, and both
	// write the same option.
	if ( isset( $_POST['daw_tmdb_key_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['daw_tmdb_key_nonce'] ), 'daw_tmdb_key' ) ) {
		update_option( 'daw_tmdb_key', sanitize_text_field( wp_unslash( $_POST['daw_tmdb_key'] ?? '' ) ), false );
		echo '<div class="notice notice-success"><p>Key saved.</p></div>';
	}

	echo '<form method="post" style="margin:14px 0;padding:14px;border:1px solid #c3c4c7;background:#fff;max-width:640px">';
	wp_nonce_field( 'daw_tmdb_key', 'daw_tmdb_key_nonce' );
	printf(
		'<p><label><strong>%s</strong><br><input type="password" name="daw_tmdb_key" value="%s" class="regular-text" autocomplete="off" spellcheck="false"></label> <strong>%s</strong></p>',
		esc_html__( 'TMDB API key or API Read Access Token', 'daw' ),
		esc_attr( (string) get_option( 'daw_tmdb_key' ) ),
		daw_tmdb_key() ? esc_html__( 'Set', 'daw' ) : esc_html__( 'Not set', 'daw' )
	);
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'With a key, posters come from the documented TMDB API. Without one the old route is used, which reads the public page and is not a use TMDB permits.', 'daw' )
	);
	submit_button( __( 'Save key', 'daw' ), 'secondary', 'daw_tmdb_key_save', false );
	echo '</form>';

	echo '<p>Records that carry a TMDB link. One poster each, downloaded into this media library and served from this site, nothing is hot-linked.</p>';

	// REPLACING IS A SEPARATE ACT FROM FILLING
	// ----------------------------------------
	// Filling an empty slot can never destroy anything, so it is the default
	// and it is safe to run on everything. Replacing overwrites a poster that
	// somebody may have chosen by hand, so it is a deliberate second mode with
	// its own checkbox, and the old image stays in the media library either way.
	$replace = isset( $_POST['daw_tmdb_replace'] );

	if ( isset( $_POST['daw_tmdb_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['daw_tmdb_nonce'] ), 'daw_tmdb' ) ) {
		$batch = max( 1, min( 25, (int) ( $_POST['daw_tmdb_batch'] ?? 10 ) ) );
		$done  = 0;
		$miss  = 0;

		foreach ( array_slice( daw_tmdb_queue( $replace ), 0, $batch ) as $pid ) {
			if ( daw_fetch_tmdb_poster( $pid, $replace ) ) {
				$done++;
			} else {
				$miss++;
			}
		}

		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			$done ? 'success' : 'warning',
			esc_html(
				$miss
					? sprintf( 'Fetched %d poster(s). %d record(s) gave nothing back and were left as they were.', $done, $miss )
					: sprintf( 'Fetched %d poster(s).', $done )
			)
		);
	}

	$pending = daw_tmdb_queue( $replace );

	echo '<form method="post">';
	wp_nonce_field( 'daw_tmdb', 'daw_tmdb_nonce' );
	echo '<p><label>Fetch this many now: <input type="number" name="daw_tmdb_batch" value="10" min="1" max="25" style="width:5em"></label></p>';
	printf(
		'<p><label><input type="checkbox" name="daw_tmdb_replace" value="1" %s> %s</label></p>',
		checked( $replace, true, false ),
		esc_html__( 'Replace posters that are already there', 'daw' )
	);
	submit_button( $replace ? __( 'Replace posters', 'daw' ) : __( 'Fetch posters', 'daw' ) );
	echo '</form>';

	printf(
		'<p><strong>%d</strong> record(s) in the queue%s.</p>',
		count( $pending ),
		$replace ? esc_html__( ', every record with a TMDB link', 'daw' ) : esc_html__( ', the ones with no poster yet', 'daw' )
	);

	echo '<table class="widefat striped"><thead><tr><th>Record</th><th>TMDB</th></tr></thead><tbody>';
	foreach ( array_slice( $pending, 0, 50 ) as $pid ) {
		printf(
			'<tr><td><a href="%s">%s</a></td><td><a href="%s" target="_blank" rel="noopener">%s</a></td></tr>',
			esc_url( get_edit_post_link( $pid ) ),
			esc_html( get_the_title( $pid ) ),
			esc_url( daw_tmdb_url( $pid ) ),
			esc_html( daw_tmdb_url( $pid ) )
		);
	}
	echo '</tbody></table></div>';
}

/** Records with a TMDB link and no featured image. */
function daw_tmdb_pending() {
	return daw_tmdb_queue( false );
}

/**
 * The records the button will work on.
 *
 * @param bool $all  false: only records with no poster. true: every record that
 *                   carries a TMDB link, poster or not.
 */
function daw_tmdb_queue( $all = false ) {
	$meta = array(
		array(
			'key'     => 'daw_tmdb_id',
			'compare' => 'EXISTS',
		),
	);

	if ( ! $all ) {
		$meta[] = array(
			'key'     => '_thumbnail_id',
			'compare' => 'NOT EXISTS',
		);
	}

	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array_merge( array( 'relation' => 'AND' ), $meta ), // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);

	return array_values(
		array_filter(
			$ids,
			static function ( $id ) {
				return '' !== daw_tmdb_url( $id );
			}
		)
	);
}
