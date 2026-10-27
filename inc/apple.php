<?php
/**
 * The catalogue, read from Apple when Spotify will not answer.
 *
 * WHY THERE ARE TWO SOURCES
 * -------------------------
 * Spotify's web api answered the catalogue request with 403 and this message:
 * "Active premium subscription required for the owner of the app." The keys are
 * valid and the handshake works; the account that owns the app has to be paying
 * for Spotify before the catalogue endpoints will answer at all. That is their
 * policy, not a fault in this code, and no amount of rewriting the request gets
 * past it.
 *
 * Apple's search service answers the same questions without a key and without a
 * subscription: the release titles, the dates, the track counts, a link to each
 * record and its sleeve. So it is the fallback, and it runs only when Spotify
 * has come back empty.
 *
 * WHAT IS AND IS NOT TAKEN
 * ------------------------
 * The sleeve is referenced at Apple's own address rather than copied here, and
 * every release that carries one also carries the link to its page on Apple
 * Music, which is the arrangement their artwork is published for. No audio, no
 * tracklists, no descriptions.
 *
 * Apple has no artist photograph in this service, so the square group portrait
 * is the one thing only Spotify can supply.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** One call to Apple's search service, decoded, with a note kept for the tools page. */
function daw_apple_get( $path, $args ) {
	$url = add_query_arg( array_map( 'rawurlencode', $args ), 'https://itunes.apple.com/' . ltrim( $path, '/' ) );

	$res = wp_remote_get( $url, array( 'timeout' => 15 ) );

	if ( is_wp_error( $res ) ) {
		daw_spotify_note( $url, 0, $res->get_error_message() );
		return array();
	}

	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = wp_remote_retrieve_body( $res );

	if ( 200 !== $code ) {
		daw_spotify_note( $url, $code, substr( (string) $body, 0, 300 ) );
		return array();
	}

	daw_spotify_note( $url, 200, '' );

	$out = json_decode( $body, true );

	return isset( $out['results'] ) && is_array( $out['results'] ) ? $out['results'] : array();
}

/** Names compared without spacing, case or punctuation. */
function daw_apple_norm( $name ) {
	return strtolower( preg_replace( '~[^a-z0-9]~i', '', (string) $name ) );
}

/**
 * The artist id for a group, found once and then remembered.
 *
 * Matched on the name rather than taken from the first result, because a search
 * for BTS also returns tribute acts, karaoke labels and a Thai transit system.
 * If nothing matches the name exactly, nothing is returned: a wrong artist id
 * would fill the page with somebody else's records, which is worse than an
 * empty frame.
 */
function daw_apple_artist_id( $post_id ) {
	$post_id = (int) $post_id;
	$held    = trim( (string) get_post_meta( $post_id, 'daw_apple_artist', true ) );

	if ( $held ) {
		return $held;
	}

	$name = get_the_title( $post_id );
	$want = daw_apple_norm( $name );

	$rows = daw_apple_get(
		'search',
		array(
			'term'    => $name,
			'entity'  => 'musicArtist',
			'limit'   => '20',
			'country' => 'US',
		)
	);

	foreach ( $rows as $row ) {
		if ( daw_apple_norm( $row['artistName'] ?? '' ) === $want && ! empty( $row['artistId'] ) ) {
			update_post_meta( $post_id, 'daw_apple_artist', (string) $row['artistId'] );
			return (string) $row['artistId'];
		}
	}

	return '';
}

/**
 * Every album Apple lists for an artist, newest first, in the same shape the
 * Spotify reader returns so the writer does not care which one answered.
 */
function daw_apple_albums( $artist_id ) {
	$artist_id = preg_replace( '~[^0-9]~', '', (string) $artist_id );

	if ( '' === $artist_id ) {
		return array();
	}

	$rows = daw_apple_get(
		'lookup',
		array(
			'id'      => $artist_id,
			'entity'  => 'album',
			'limit'   => '200',
			'country' => 'US',
		)
	);

	$out = array();

	// The first row of a lookup is the artist, and it is also how we learn the
	// exact spelling to compare every collection's credit against.
	$want_artist = '';

	foreach ( $rows as $row ) {
		if ( 'artist' === ( $row['wrapperType'] ?? '' ) ) {
			$want_artist = (string) ( $row['artistName'] ?? '' );
		}
	}

	foreach ( $rows as $row ) {
		if ( 'collection' !== ( $row['wrapperType'] ?? '' ) ) {
			continue;
		}

		$name = trim( (string) ( $row['collectionName'] ?? '' ) );

		if ( '' === $name ) {
			continue;
		}

		// Apple's catalogue is the worst offender for this: every alternative
		// cover, every member version and every digital single of a title
		// track is its own row. They fold into the release they are an edition
		// of, and anything credited to another act is left where it belongs.
		if ( daw_release_is_noise( $name, (string) ( $row['artistName'] ?? '' ), $want_artist ) ) {
			continue;
		}

		$key    = daw_release_key( $name );
		$tracks = (int) ( $row['trackCount'] ?? 0 );

		if ( '' === $key ) {
			continue;
		}

		if ( isset( $out[ $key ] ) && (int) $out[ $key ]['tracks'] >= $tracks ) {
			continue;
		}

		// Apple serves the sleeve at whatever size is asked for in the name of
		// the file, and 100px is a thumbnail on a phone, let alone a card.
		$art = (string) ( $row['artworkUrl100'] ?? '' );
		$art = $art ? str_replace( '100x100bb', '600x600bb', $art ) : '';

		$date = (string) ( $row['releaseDate'] ?? '' );

		$out[ $key ] = array(
			'name'   => $name,
			'date'   => $date ? substr( $date, 0, 10 ) : '',
			'tracks' => $tracks,
			'kind'   => daw_apple_kind( $row ),
			'url'    => '',
			'apple'  => (string) ( $row['collectionViewUrl'] ?? '' ),
			'cover'  => $art,
		);
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
 * Which of our release types an Apple collection looks like.
 *
 * Apple says less about this than Spotify does, so the guess is coarser and, as
 * everywhere else, it is only ever written into a row that has no type yet.
 */
function daw_apple_kind( $row ) {
	$name   = strtolower( (string) ( $row['collectionName'] ?? '' ) );
	$tracks = (int) ( $row['trackCount'] ?? 0 );

	if ( false !== strpos( $name, ' - single' ) || 1 === $tracks ) {
		return 'digital';
	}

	if ( false !== strpos( $name, ' - ep' ) || ( $tracks > 1 && $tracks <= 7 ) ) {
		return 'mini';
	}

	return 'studio';
}
