<?php
/**
 * The discography, read from Spotify rather than typed.
 *
 * WHY THIS EXISTS
 * ---------------
 * A discography is the one part of a group record that goes out of date on its
 * own. A drama record is finished when the show has aired; a band releases
 * something new the week after somebody types the last row, and from then on
 * the page is quietly wrong. Sixteen rows typed by hand also means sixteen
 * chances to get a date, a track count or a title wrong, and nobody checks a
 * table they typed themselves.
 *
 * So the releases are read from the artist's own catalogue: the titles, the
 * dates, the track counts, the album type and the link to each record. What is
 * on this page is then what the label actually put out, and a new release
 * appears without anybody being told about it.
 *
 * WHAT IS NOT COPIED
 * ------------------
 * No audio, no tracklists, no descriptions and no artwork files. The cover is
 * referenced at Spotify's own address rather than downloaded, which is both the
 * way their terms expect it to be used and the reason this cannot repeat the
 * mess of unsourced images the media library is already carrying. An editor who
 * has a properly licensed sleeve can still upload one, and that upload wins.
 *
 * WHAT AN EDITOR STILL OWNS
 * -------------------------
 * The type a release is filed under, the title track, the OST link back to a
 * drama, and any release Spotify does not carry. A sync fills what is empty and
 * leaves alone what somebody has already answered, in the same way the TMDB
 * field filler does, so a correction made here survives the next run.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/* ================================================================ keys === */

/** The client id, from wp-config if it is there and the option otherwise. */
function daw_spotify_id() {
	if ( defined( 'DAW_SPOTIFY_ID' ) && DAW_SPOTIFY_ID ) {
		return (string) DAW_SPOTIFY_ID;
	}

	return trim( (string) get_option( 'daw_spotify_id' ) );
}

/** The client secret, same rule. */
function daw_spotify_secret() {
	if ( defined( 'DAW_SPOTIFY_SECRET' ) && DAW_SPOTIFY_SECRET ) {
		return (string) DAW_SPOTIFY_SECRET;
	}

	return trim( (string) get_option( 'daw_spotify_secret' ) );
}

/** Whether the integration can run at all. */
function daw_spotify_ready() {
	return '' !== daw_spotify_id() && '' !== daw_spotify_secret();
}

add_action( 'admin_init', 'daw_spotify_settings' );
function daw_spotify_settings() {
	register_setting( 'reading', 'daw_spotify_id', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
	register_setting( 'reading', 'daw_spotify_secret', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
}

/* =============================================================== token === */

/**
 * An app token, cached until shortly before it expires.
 *
 * This is the client credentials flow, which reads public catalogue data and
 * has nothing to do with anybody's Spotify account. No listener is identified,
 * no account is touched, and the token is stored where the rest of the plugin's
 * short-lived state lives rather than in the options table.
 */
function daw_spotify_token() {
	if ( ! daw_spotify_ready() ) {
		return '';
	}

	$cached = get_transient( 'daw_spotify_token' );

	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}

	$res = wp_remote_post(
		'https://accounts.spotify.com/api/token',
		array(
			'timeout' => 12,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( daw_spotify_id() . ':' . daw_spotify_secret() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => array( 'grant_type' => 'client_credentials' ),
		)
	);

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		// A failed handshake is remembered briefly so a broken key does not
		// mean one outbound request per group per page load.
		set_transient( 'daw_spotify_token', '', 5 * MINUTE_IN_SECONDS );
		return '';
	}

	$body  = json_decode( wp_remote_retrieve_body( $res ), true );
	$token = isset( $body['access_token'] ) ? (string) $body['access_token'] : '';
	$life  = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;

	if ( '' === $token ) {
		return '';
	}

	set_transient( 'daw_spotify_token', $token, max( 60, $life - 120 ) );

	return $token;
}

/**
 * One GET against the web API, decoded, or an empty array.
 *
 * WHY IT KEEPS A NOTE OF WHAT WENT WRONG
 * --------------------------------------
 * The first run of this came back with nothing and there was no way to tell
 * why: the keys were accepted, so the handshake worked, and then the catalogue
 * call returned an empty array that meant "no albums", "rate limited", "wrong
 * id" and "the host cannot reach this address" all at once. Debugging a
 * request you cannot see is guessing.
 *
 * So every call leaves the address, the status and the first line of the reply
 * where the tools page can print it. It is a transient, so it costs nothing and
 * disappears on its own.
 */
function daw_spotify_get( $path, $args = array() ) {
	$token = daw_spotify_token();

	if ( '' === $token ) {
		daw_spotify_note( $path, 0, 'no token' );
		return array();
	}

	// Built rather than concatenated, so a comma in include_groups is encoded
	// the way the api expects instead of being sent raw and answered with 400.
	$url = 'https://api.spotify.com/v1/' . ltrim( $path, '/' );

	if ( $args ) {
		$url = add_query_arg( array_map( 'rawurlencode', $args ), $url );
	}

	// SPOTIFY ASKED US TO STOP, SO WE STOP
	// ------------------------------------
	// A 429 is not a failure to route around. It is the service saying it will
	// not answer again until a stated time, and an integration that keeps
	// asking through a rate limit earns a longer one. While the hold is on,
	// this does not call at all: it returns nothing at once, which sends the
	// sync to the other source instead of into the same wall.
	$hold = (int) get_transient( 'daw_spotify_hold' );

	if ( $hold > time() ) {
		return array();
	}

	$res = wp_remote_get(
		$url,
		array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		)
	);

	if ( is_wp_error( $res ) ) {
		daw_spotify_note( $url, 0, $res->get_error_message() );
		return array();
	}

	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = wp_remote_retrieve_body( $res );

	if ( 429 === $code ) {
		// Spotify states the wait in a header. A missing or silly value falls
		// back to an hour rather than to zero, because guessing short is how
		// a rate limit becomes a ban.
		$wait = (int) wp_remote_retrieve_header( $res, 'retry-after' );
		$wait = ( $wait > 0 && $wait < DAY_IN_SECONDS ) ? $wait : HOUR_IN_SECONDS;

		set_transient( 'daw_spotify_hold', time() + $wait, $wait + 60 );
		daw_spotify_note( $url, 429, sprintf( 'Rate limited. Waiting %d minutes before asking again. Apple is being used in the meantime.', (int) ceil( $wait / 60 ) ) );

		return array();
	}

	if ( 200 !== $code ) {
		daw_spotify_note( $url, $code, substr( (string) $body, 0, 300 ) );
		return array();
	}

	$out = json_decode( $body, true );

	daw_spotify_note( $url, 200, '' );

	return is_array( $out ) ? $out : array();
}

/** The last thing the api said, for the tools page to print. */
function daw_spotify_note( $url, $code, $why ) {
	set_transient(
		'daw_spotify_last',
		array(
			'url'  => (string) $url,
			'code' => (int) $code,
			'why'  => (string) $why,
			'when' => time(),
		),
		DAY_IN_SECONDS
	);
}

/* ============================================================== artist === */

/**
 * The artist id held on a record, from whatever the editor pasted.
 *
 * A full page url, a uri, or the bare id all arrive in that field at different
 * times, so all three are accepted rather than one being declared correct.
 */
function daw_spotify_artist_id( $post_id ) {
	$raw = trim( (string) get_post_meta( (int) $post_id, 'daw_spotify_artist', true ) );

	if ( '' === $raw ) {
		return '';
	}

	if ( preg_match( '~artist[/:]([A-Za-z0-9]{10,})~', $raw, $m ) ) {
		return $m[1];
	}

	return preg_match( '~^[A-Za-z0-9]{10,}$~', $raw ) ? $raw : '';
}

/* =========================================================== editions === */

/**
 * One release, many products.
 *
 * A catalogue does not list albums, it lists things that can be sold. Arirang
 * comes back as Arirang, Arirang (Jungkook Version), Arirang (Alternative
 * Cover), a digital single of its title track, and a Japanese edition, and
 * every one of those is a separate row with its own sleeve. Printing them all
 * turns a sixteen record discography into sixty tiles that say the same six
 * words, which is the opposite of what a discography is for.
 *
 * So a title is reduced to what it is a release of: brackets and edition
 * suffixes are cut off, and rows that reduce to the same thing are folded
 * together. The one kept is the one with the most tracks, which is the edition
 * a reader means when they name the record.
 */
function daw_release_key( $name ) {
	$name = strtolower( (string) $name );

	// Anything in brackets is an edition, a version, a cover or a language.
	$name = preg_replace( '~[\(\[\{][^\)\]\}]*[\)\]\}]~', ' ', $name );

	// Apple hangs the format on the end with a dash.
	$name = preg_replace( '~\s-\s(single|ep|deluxe|remastered).*$~', ' ', $name );

	return preg_replace( '~[^a-z0-9]~', '', $name );
}

/**
 * Whether a row is something other than a release by this act.
 *
 * Instrumentals, karaoke, tributes and covers are products about the music
 * rather than the music. A row credited to somebody else is a collaboration or
 * a compilation the act appears on, and it belongs on their page, not here.
 */
function daw_release_is_noise( $name, $artist = '', $want = '' ) {
	$name = strtolower( (string) $name );

	foreach ( array( 'instrumental', 'karaoke', 'tribute', 'in the style of', 'made famous by', 'a cappella version' ) as $word ) {
		if ( false !== strpos( $name, $word ) ) {
			return true;
		}
	}

	if ( '' !== $artist && '' !== $want ) {
		$a = preg_replace( '~[^a-z0-9]~', '', strtolower( $artist ) );
		$w = preg_replace( '~[^a-z0-9]~', '', strtolower( $want ) );

		// "BTS" matches "BTS"; it does not match "BTS & Halsey", which is a
		// single credited to two acts, and it does not match a covers band.
		if ( $a !== $w ) {
			return true;
		}
	}

	return false;
}

/* ============================================================== albums === */

/**
 * Which of our release types a Spotify album type means.
 *
 * Spotify knows three: album, single and compilation. This archive keeps seven,
 * because a mini album and a studio album are not the same thing to anybody who
 * follows the music. So the guess is deliberately coarse and only ever applied
 * to a row that has no type yet: an editor calling something a mini album or a
 * repackage is never overruled by a word from an api.
 */
function daw_spotify_kind( $album ) {
	$type   = strtolower( (string) ( $album['album_type'] ?? '' ) );
	$tracks = (int) ( $album['total_tracks'] ?? 0 );

	if ( 'single' === $type ) {
		return $tracks > 3 ? 'mini' : 'digital';
	}

	if ( 'compilation' === $type ) {
		return 'repack';
	}

	return $tracks > 0 && $tracks <= 7 ? 'mini' : 'studio';
}

/**
 * Every album, single and compilation on an artist's page, newest first.
 *
 * Spotify lists a record once per market it is licensed in, so the same album
 * comes back several times under different ids. They are folded together on the
 * normalised name, keeping the one with the most tracks, because the deluxe or
 * complete edition is the one whose track count is worth printing.
 */
function daw_spotify_albums( $artist ) {
	$artist = preg_replace( '~[^A-Za-z0-9]~', '', (string) $artist );

	if ( '' === $artist ) {
		return array();
	}

	$out    = array();
	$offset = 0;

	// Four pages is two hundred releases, which is more than any group in this
	// archive has and a hard stop against a runaway loop.
	for ( $page = 0; $page < 4; $page++ ) {
		$body = daw_spotify_get(
			'artists/' . $artist . '/albums',
			array(
				'include_groups' => 'album,single,compilation',
				'limit'          => '50',
				'market'         => 'US',
				'offset'         => (string) $offset,
			)
		);
		$rows = isset( $body['items'] ) && is_array( $body['items'] ) ? $body['items'] : array();

		if ( ! $rows ) {
			break;
		}

		foreach ( $rows as $album ) {
			$name = trim( (string) ( $album['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			if ( daw_release_is_noise( $name ) ) {
				continue;
			}

			$key = daw_release_key( $name );

			if ( '' === $key ) {
				continue;
			}

			if ( isset( $out[ $key ] ) && (int) $out[ $key ]['tracks'] >= (int) ( $album['total_tracks'] ?? 0 ) ) {
				continue;
			}

			$images = isset( $album['images'] ) && is_array( $album['images'] ) ? $album['images'] : array();

			$out[ $key ] = array(
				'name'   => $name,
				'date'   => (string) ( $album['release_date'] ?? '' ),
				'tracks' => (int) ( $album['total_tracks'] ?? 0 ),
				'kind'   => daw_spotify_kind( $album ),
				'url'    => (string) ( $album['external_urls']['spotify'] ?? '' ),
				'cover'  => (string) ( $images[0]['url'] ?? '' ),
			);
		}

		if ( count( $rows ) < 50 ) {
			break;
		}

		$offset += 50;
	}

	$out = array_values( $out );

	usort(
		$out,
		static function ( $a, $b ) {
			return strcmp( $b['date'], $a['date'] );
		}
	);

	return $out;
}

/**
 * The artist's own picture, at Spotify's address.
 *
 * WHY THIS IS NOT A NICE-TO-HAVE
 * ------------------------------
 * The group heroes were carrying whatever photograph somebody found, uploaded
 * into the media library with no licence behind it. BTS was showing the sleeve
 * of a Japanese compilation album as its portrait, which is wrong twice: it is
 * a record cover being used as a photograph of people, and it is a file we
 * cannot say where we got.
 *
 * The picture on an artist's own Spotify page is supplied by the label for
 * exactly this use, is referenced rather than copied, and is square, which is
 * the shape an artist image actually comes in.
 */
function daw_spotify_artist_image( $artist ) {
	$artist = preg_replace( '~[^A-Za-z0-9]~', '', (string) $artist );

	if ( '' === $artist ) {
		return '';
	}

	$body   = daw_spotify_get( 'artists/' . $artist );
	$images = isset( $body['images'] ) && is_array( $body['images'] ) ? $body['images'] : array();

	// Largest first is how Spotify returns them, and the largest is the one
	// worth referencing: the hero is 220px on a desktop and twice that on a
	// dense screen.
	return (string) ( $images[0]['url'] ?? '' );
}

/* ================================================================ sync === */

/**
 * Write the catalogue onto a group record, without overwriting an answer.
 *
 * Matching is on the normalised title, so a row somebody typed keeps its place
 * and gains only the fields it was missing. A release we hold and Spotify does
 * not is left exactly where it is: a Japanese edition or a fan club single is
 * still a release, and an api that has not heard of it is not evidence that it
 * did not happen.
 */
function daw_spotify_sync_group( $post_id ) {
	$post_id = (int) $post_id;
	$artist  = daw_spotify_artist_id( $post_id );

	// A record with no Spotify link is not a dead end any more: Apple is found
	// by name, so the read still happens.
	if ( '' === $artist && ! function_exists( 'daw_apple_albums' ) ) {
		return array( 'ok' => false, 'why' => __( 'This record has no Spotify artist link.', 'daw' ) );
	}

	$picture = $artist ? daw_spotify_artist_image( $artist ) : '';

	if ( '' !== $picture ) {
		update_post_meta( $post_id, 'daw_group_image_url', $picture );
	}

	$albums = $artist ? daw_spotify_albums( $artist ) : array();
	$source = 'Spotify';

	// SPOTIFY FIRST, APPLE WHEN IT WILL NOT ANSWER
	// --------------------------------------------
	// Spotify refuses the catalogue endpoints unless the account that owns the
	// app is paying for Premium, which is a billing condition rather than
	// anything this code can satisfy. Apple answers the same questions without
	// a key and without a subscription, so the page fills either way and the
	// difference is invisible to a reader.
	if ( ! $albums && function_exists( 'daw_apple_albums' ) ) {
		$apple_id = daw_apple_artist_id( $post_id );
		$albums   = $apple_id ? daw_apple_albums( $apple_id ) : array();
		$source   = 'Apple';
	}

	if ( ! $albums ) {
		update_post_meta( $post_id, 'daw_spotify_synced', time() );

		return array(
			'ok'  => false,
			'why' => '' === daw_spotify_token()
				? __( 'Spotify refused the keys. Check the Client ID and Client Secret and save them again.', 'daw' )
				: __( 'Neither Spotify nor Apple returned a catalogue. See the last request below for what the service actually said.', 'daw' ),
		);
	}

	$rows = get_post_meta( $post_id, 'daw_rel', true );
	$rows = is_array( $rows ) ? $rows : array();

	$index = array();

	foreach ( $rows as $i => $row ) {
		$key = daw_release_key( (string) ( $row['daw_rel_title'] ?? '' ) );

		if ( '' !== $key ) {
			$index[ $key ] = $i;
		}
	}

	$added   = 0;
	$filled  = 0;
	$touched = array();

	foreach ( $albums as $album ) {
		$key = daw_release_key( $album['name'] );

		if ( isset( $index[ $key ] ) ) {
			$i    = $index[ $key ];
			$was  = $rows[ $i ];
			$row  = $was;

			// Only ever into an empty field.
			if ( '' === trim( (string) ( $row['daw_rel_date'] ?? '' ) ) && $album['date'] ) {
				$row['daw_rel_date'] = $album['date'];
			}
			if ( ! (int) ( $row['daw_rel_tracks'] ?? 0 ) && $album['tracks'] ) {
				$row['daw_rel_tracks'] = $album['tracks'];
			}
			if ( '' === trim( (string) ( $row['daw_rel_spotify'] ?? '' ) ) && ! empty( $album['url'] ) ) {
				$row['daw_rel_spotify'] = $album['url'];
			}
			if ( '' === trim( (string) ( $row['daw_rel_apple'] ?? '' ) ) && ! empty( $album['apple'] ) ) {
				$row['daw_rel_apple'] = $album['apple'];
			}
			if ( '' === trim( (string) ( $row['daw_rel_cover_url'] ?? '' ) ) && $album['cover'] ) {
				$row['daw_rel_cover_url'] = $album['cover'];
			}
			if ( '' === trim( (string) ( $row['daw_rel_type'] ?? '' ) ) && $album['kind'] ) {
				$row['daw_rel_type'] = $album['kind'];
			}

			if ( $row !== $was ) {
				$rows[ $i ] = $row;
				$filled++;
			}

			$touched[] = $key;
			continue;
		}

		$rows[] = array(
			'daw_rel_title'     => $album['name'],
			'daw_rel_type'      => $album['kind'],
			'daw_rel_date'      => $album['date'],
			'daw_rel_tracks'    => $album['tracks'],
			'daw_rel_spotify'   => (string) ( $album['url'] ?? '' ),
			'daw_rel_apple'     => (string) ( $album['apple'] ?? '' ),
			'daw_rel_cover_url' => $album['cover'],
		);

		$added++;
		$touched[] = $key;
	}

	update_post_meta( $post_id, 'daw_rel', $rows );
	update_post_meta( $post_id, 'daw_spotify_synced', time() );
	update_post_meta( $post_id, 'daw_spotify_ok', 1 );

	if ( function_exists( 'daw_browse_bump_gen' ) ) {
		daw_browse_bump_gen();
	}

	return array(
		'ok'     => true,
		'added'  => $added,
		'filled' => $filled,
		'seen'    => count( $albums ),
		'picture' => '' !== $picture,
		'source'  => $source,
	);
}

/* =============================================================== nightly = */

add_action( 'daw_spotify_cron', 'daw_spotify_run_all' );

add_action( 'init', 'daw_spotify_schedule' );
function daw_spotify_schedule() {
	if ( ! wp_next_scheduled( 'daw_spotify_cron' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'daw_spotify_cron' );
	}
}

/** Every group with a Spotify link, once a night. */
function daw_spotify_run_all() {
	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array(
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => 'group',
				),
			),
		)
	);

	// PACED, NOT ALL AT ONCE
	// ----------------------
	// One group is one request and nobody notices. Forty groups in the same
	// second is how an integration meets a rate limit at three in the morning
	// and finds every record half written in the morning. So a run takes ten,
	// skips anything already read today, and hands the rest to a job a quarter
	// of an hour later. The whole archive still refreshes overnight; it just
	// does it at a speed the services are willing to answer.
	$due = array();

	foreach ( $ids as $id ) {
		$last = (int) get_post_meta( $id, 'daw_spotify_synced', true );
		$ok   = (bool) get_post_meta( $id, 'daw_spotify_ok', true );

		// Read today and it worked: nothing to do. Read today and it did not:
		// try again, because that is exactly the record that needs it.
		if ( $ok && $last > time() - 20 * HOUR_IN_SECONDS ) {
			continue;
		}

		$due[] = (int) $id;
	}

	foreach ( array_slice( $due, 0, 10 ) as $id ) {
		daw_spotify_sync_group( $id );
	}

	if ( count( $due ) > 10 && ! wp_next_scheduled( 'daw_spotify_cron' ) ) {
		wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, 'daw_spotify_cron' );
	}
}

/**
 * A release link turned into the player address for the same record.
 *
 * Spotify publishes an embed under /embed/ for every album and track page it
 * serves, which is the supported way to put a player on a page: the audio is
 * streamed and counted by them, nothing is hosted here, and a listener with an
 * account hears the whole thing rather than a preview.
 */
function daw_spotify_embed_url( $url ) {
	$url = trim( (string) $url );

	if ( '' === $url || ! preg_match( '~open\.spotify\.com/(album|track|playlist|artist)/([A-Za-z0-9]+)~', $url, $m ) ) {
		return '';
	}

	return 'https://open.spotify.com/embed/' . $m[1] . '/' . $m[2];
}

/* ============================================================ automatic === */

/**
 * A record that has never been read gets read, without anybody pressing a
 * button.
 *
 * The nightly job covers everything eventually, which is no help on the day
 * somebody adds a group and opens it to find sixteen empty frames. So the
 * first time a group page is drawn with a Spotify link and nothing synced, a
 * single job is queued for a few seconds' time. The page the reader is looking
 * at does not wait for it: the request that queued it finishes immediately and
 * the covers are there on the next load.
 *
 * It queues once, because the flag is written whether or not the read found
 * anything, so a broken link does not queue a job on every page view.
 */
add_action( 'wp', 'daw_spotify_first_look' );
function daw_spotify_first_look() {
	// Apple needs no keys, so a site with no Spotify app still fills its
	// covers. Only a site with neither source configured does nothing, and
	// there is no such thing: Apple is always there.
	if ( is_admin() || ! is_singular( 'post' ) ) {
		return;
	}

	$id = (int) get_queried_object_id();

	if ( ! $id || ! has_term( 'group', 'dw_type', $id ) ) {
		return;
	}

	// A FAILED READ IS NOT A FINISHED ONE
	// -----------------------------------
	// The flag only said "we tried". A read that came back with nothing wrote
	// it just the same, so a record whose first attempt failed was never
	// attempted again from a page view, and the covers stayed empty with
	// nothing on the page or in the tools saying why. What matters is whether
	// the read worked, so that is what is stored and that is what this checks.
	if ( get_post_meta( $id, 'daw_spotify_ok', true ) ) {
		return;
	}

	// Once an hour at most, so a record Spotify genuinely has nothing for does
	// not queue a job on every single page view.
	if ( (int) get_post_meta( $id, 'daw_spotify_synced', true ) > time() - HOUR_IN_SECONDS ) {
		return;
	}

	if ( ! wp_next_scheduled( 'daw_spotify_one', array( $id ) ) ) {
		wp_schedule_single_event( time() + 5, 'daw_spotify_one', array( $id ) );
	}
}

/**
 * And again whenever the record is saved, because the thing an editor has
 * usually just changed is the Spotify link itself.
 */
add_action( 'save_post_post', 'daw_spotify_on_save', 30, 1 );
function daw_spotify_on_save( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( ! has_term( 'group', 'dw_type', $post_id ) ) {
		return;
	}

	if ( ! wp_next_scheduled( 'daw_spotify_one', array( (int) $post_id ) ) ) {
		wp_schedule_single_event( time() + 10, 'daw_spotify_one', array( (int) $post_id ) );
	}
}

add_action( 'daw_spotify_one', 'daw_spotify_sync_group', 10, 1 );

/* ================================================================ tools == */

add_action( 'admin_menu', 'daw_spotify_menu' );
function daw_spotify_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: Spotify discography', 'daw' ),
		__( 'DAW: Spotify discography', 'daw' ),
		'manage_options',
		'daw-spotify',
		'daw_spotify_admin_page'
	);
}

function daw_spotify_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice = '';

	if ( isset( $_POST['daw_spotify_keys'] ) && check_admin_referer( 'daw_spotify' ) ) {
		update_option( 'daw_spotify_id', sanitize_text_field( wp_unslash( $_POST['daw_spotify_id'] ?? '' ) ) );
		update_option( 'daw_spotify_secret', sanitize_text_field( wp_unslash( $_POST['daw_spotify_secret'] ?? '' ) ) );
		delete_transient( 'daw_spotify_token' );
		delete_transient( 'daw_spotify_hold' );
		$notice = __( 'Saved.', 'daw' );
	}

	if ( isset( $_POST['daw_spotify_run'] ) && check_admin_referer( 'daw_spotify' ) ) {
		$one = (int) ( $_POST['daw_spotify_group'] ?? 0 );

		if ( $one ) {
			$r = daw_spotify_sync_group( $one );

			$notice = $r['ok']
				? sprintf(
					/* translators: 1: releases added, 2: releases completed, 3: releases seen, 4: which service answered, 5: whether a picture arrived */
					__( 'Added %1$s, completed %2$s, read %3$s from %4$s. Artist picture: %5$s.', 'daw' ),
					number_format_i18n( $r['added'] ),
					number_format_i18n( $r['filled'] ),
					number_format_i18n( $r['seen'] ),
					$r['source'],
					! empty( $r['picture'] ) ? __( 'yes', 'daw' ) : __( 'none', 'daw' )
				)
				: $r['why'];
		} else {
			daw_spotify_run_all();
			$notice = __( 'Every group with a Spotify link has been read.', 'daw' );
		}
	}

	$groups = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'tax_query'      => array(
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => 'group',
				),
			),
		)
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'DAW: Spotify discography', 'daw' ); ?></h1>

		<?php if ( $notice ) : ?>
			<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<p style="max-width:70ch">
			<?php esc_html_e( 'Reads the catalogue on a group\'s Spotify artist page and writes the titles, dates, track counts, album links and cover art onto the record. It fills empty fields and adds releases we do not hold. It never overwrites an answer somebody has already given, and it never removes a release we hold that Spotify does not carry.', 'daw' ); ?>
		</p>

		<form method="post">
			<?php wp_nonce_field( 'daw_spotify' ); ?>

			<h2><?php esc_html_e( 'Keys', 'daw' ); ?></h2>
			<p style="max-width:70ch">
				<?php esc_html_e( 'Create a free app at developer.spotify.com, then paste its Client ID and Client Secret here. They are read by the server only and are never sent anywhere except to Spotify.', 'daw' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="daw_spotify_id"><?php esc_html_e( 'Client ID', 'daw' ); ?></label></th>
					<td><input name="daw_spotify_id" id="daw_spotify_id" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'daw_spotify_id', '' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="daw_spotify_secret"><?php esc_html_e( 'Client Secret', 'daw' ); ?></label></th>
					<td><input name="daw_spotify_secret" id="daw_spotify_secret" type="password" class="regular-text" autocomplete="new-password" value="<?php echo esc_attr( get_option( 'daw_spotify_secret', '' ) ); ?>"></td>
				</tr>
			</table>

			<p><button class="button button-primary" name="daw_spotify_keys" value="1"><?php esc_html_e( 'Save keys', 'daw' ); ?></button></p>

			<hr>

			<h2><?php esc_html_e( 'Read a catalogue', 'daw' ); ?></h2>

			<?php
			// The question somebody asks first, answered before they press
			// anything: did the keys work. It costs one request and it is the
			// difference between "nothing happened" and knowing why.
			if ( daw_spotify_ready() ) :
				$live = '' !== daw_spotify_token();
				?>
				<p>
					<strong><?php esc_html_e( 'Connection:', 'daw' ); ?></strong>
					<?php
					echo $live
						? esc_html__( 'Spotify accepted the keys.', 'daw' )
						: esc_html__( 'Spotify refused the keys. Check them and save again.', 'daw' );
					?>
				</p>

				<?php
				$hold = (int) get_transient( 'daw_spotify_hold' );

				if ( $hold > time() ) :
					?>
					<p>
						<strong><?php esc_html_e( 'Spotify is rate limited.', 'daw' ); ?></strong>
						<?php
						printf(
							/* translators: %s: how many minutes are left on the wait */
							esc_html__( 'Waiting about %s minutes before asking it again. Apple is answering in the meantime, so the covers still fill.', 'daw' ),
							esc_html( number_format_i18n( (int) ceil( ( $hold - time() ) / 60 ) ) )
						);
						?>
					</p>
				<?php endif; ?>

				<?php
				$last = get_transient( 'daw_spotify_last' );

				if ( is_array( $last ) ) :
					?>
					<p style="max-width:90ch">
						<strong><?php esc_html_e( 'Last request:', 'daw' ); ?></strong>
						<code><?php echo esc_html( $last['url'] ); ?></code><br>
						<strong><?php esc_html_e( 'Answer:', 'daw' ); ?></strong>
						<?php echo esc_html( $last['code'] ? (string) $last['code'] : __( 'no reply', 'daw' ) ); ?>
						<?php if ( $last['why'] ) : ?>
							<br><code><?php echo esc_html( $last['why'] ); ?></code>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( false ) : ?>
				<p></p>
			<?php else : ?>
				<p>
					<select name="daw_spotify_group">
						<option value="0"><?php esc_html_e( 'Every group', 'daw' ); ?></option>
						<?php foreach ( $groups as $g ) : ?>
							<option value="<?php echo esc_attr( $g->ID ); ?>"><?php echo esc_html( get_the_title( $g ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button button-primary" name="daw_spotify_run" value="1"><?php esc_html_e( 'Read it now', 'daw' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'This also runs once a night on its own, so a new release appears without anybody pressing anything.', 'daw' ); ?></p>
			<?php endif; ?>
		</form>
	</div>
	<?php
}

/**
 * A Spotify picture at the size the slot needs (1.60.0).
 *
 * The group page referenced every album sleeve at 640×640 for a box that
 * is at most 300px wide, and the artist photo at 640 for a 130–220px hero:
 * PageSpeed weighed the page at 1.27 MB, three times any other (4 Oct 2026).
 * Spotify serves each picture at fixed sizes whose address differs only in
 * a size code, so the smaller copy is the same file, smaller.
 *
 * @param string $url  A Spotify image address at any size.
 * @param int    $want 300 or 64 for sleeves; 320 or 160 for artist photos.
 */
function daw_spotify_sized( $url, $want ) {
	$url = (string) $url;
	$map = array(
		// album sleeves: 640, 300, 64
		'ab67616d0000b273' => array( 640 => 'ab67616d0000b273', 300 => 'ab67616d00001e02', 64 => 'ab67616d00004851' ),
		'ab67616d00001e02' => array( 640 => 'ab67616d0000b273', 300 => 'ab67616d00001e02', 64 => 'ab67616d00004851' ),
		// artist photos: 640, 320, 160
		'ab6761610000e5eb' => array( 640 => 'ab6761610000e5eb', 320 => 'ab67616100005174', 160 => 'ab6761610000f178' ),
		'ab67616100005174' => array( 640 => 'ab6761610000e5eb', 320 => 'ab67616100005174', 160 => 'ab6761610000f178' ),
	);
	// Apple Music sleeves (apple.php asks for 600x600bb) come at any size named
	// in the file name; a 300px card was being sent a 70 KB 600px sleeve.
	if ( false !== strpos( $url, 'mzstatic.com/' ) ) {
		return (string) preg_replace( '#/\d+x\d+bb\.(jpg|png|webp)$#', '/' . (int) $want . 'x' . (int) $want . 'bb.$1', $url );
	}
	foreach ( $map as $code => $sizes ) {
		if ( false !== strpos( $url, '/image/' . $code ) && isset( $sizes[ $want ] ) ) {
			return str_replace( '/image/' . $code, '/image/' . $sizes[ $want ], $url );
		}
	}
	return $url;
}
