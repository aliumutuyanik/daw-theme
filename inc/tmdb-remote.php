<?php
/**
 * Discover Asian World: title posters and person photos shown straight from
 * TMDB, with nothing stored on this server.
 *
 * THE SECOND STEP OF THE 23 SEPTEMBER DECISION
 * --------------------------------------------
 * tmdb-hotlink.php already shows the TMDB copies the importer made from TMDB's
 * own server. The file still sat in this media library, though, and anyone
 * who knew the uploads url could fetch it from here. The owner asked for the
 * next step: nothing risky hosted here at all.
 *
 * So a record keeps only TMDB's file path (for example "/abc123.jpg") in the
 * private meta `_daw_tmdb_img`, fetched through the documented API from the
 * record's `daw_tmdb_id`. The page builds the image.tmdb.org url from it at a
 * moderate size. No file is downloaded, and the importer stops downloading
 * while this is on.
 *
 * WHAT STAYS LOCAL
 * ----------------
 * A picture whose rights are recorded in the register (Commons, KOGL, our own)
 * is ours to host and keeps being shown from here. Only unlicensed pictures go
 * remote. With "strict" on, a record whose local picture has no recorded
 * rights and no TMDB path shows the drawn monogram instead.
 *
 * WHY THE KEYS START WITH AN UNDERSCORE
 * -------------------------------------
 * The verification fingerprint (verify.php) hashes the public daw_ meta. A
 * nightly path refresh must not turn every checked record stale, so the path
 * and its sync time are private meta.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_TMDB_IMG      = '_daw_tmdb_img';
const DAW_TMDB_IMG_AT   = '_daw_tmdb_img_at';
const DAW_TMDB_IMG_REF  = '_daw_tmdb_img_ref';
const DAW_TMDB_PREV     = '_daw_prev_thumb';
const DAW_TMDB_IMG_CRON = 'daw_tmdb_img_cron';
const DAW_TMDB_IMG_ONE  = 'daw_tmdb_img_one';
const DAW_TMDB_CHECK    = '_daw_tmdb_img_check';
const DAW_TMDB_WHY      = '_daw_tmdb_img_why';

/**
 * Where title and person pictures come from, one switch:
 *   'off'   this module does nothing (the site behaves as before 1.29)
 *   'tmdb'  normal: shown from TMDB's server
 *   'vault' emergency: shown from the private backup on this server
 *   'none'  kill switch: no pictures, the drawn monogram everywhere
 */
function daw_img_source() {
	$s = (string) get_option( 'daw_img_source', '' );
	if ( '' === $s ) {
		$s = get_option( 'daw_img_remote', 0 ) ? 'tmdb' : 'off';
	}
	return in_array( $s, array( 'off', 'tmdb', 'vault', 'none' ), true ) ? $s : 'off';
}

function daw_remote_on() {
	return in_array( daw_img_source(), array( 'tmdb', 'vault' ), true );
}

/**
 * TMDB first, even over a licensed local photo (owner's plan, 23 Sep 2026:
 * every title and person picture comes from TMDB, none is kept here). On by
 * default; the switch exists so a licensed photo can win again if wanted.
 */
function daw_remote_tmdb_first() {
	return (bool) get_option( 'daw_img_tmdb_first', 1 );
}

function daw_remote_strict() {
	// On by default (owner, 24 Sep 2026: titles and people are shown only from
	// the API; a picture we host without rights is never shown).
	return (bool) get_option( 'daw_img_strict', 1 );
}

/** The record types whose main picture this module decides. */
function daw_remote_types() {
	return array( 'drama', 'film', 'variety', 'anime', 'person', 'group' );
}

/* ==================================================================== api === */

/**
 * One GET to the TMDB API, with whichever credential the owner saved.
 *
 * TMDB hands out two: the short v3 "API key" (sent as ?api_key=) and the long
 * "API Read Access Token" (a JWT, sent as a Bearer header). Both open the same
 * read endpoints, so the field takes either and the shape decides how it is
 * sent. The token never reaches a page: this runs on the server only.
 *
 * @return array|false|null  Decoded body; false when TMDB says the record does
 *                           not exist; null on any other failure (try later).
 */
function daw_tmdb_api_get( $path, $args = array() ) {
	$key = function_exists( 'daw_tmdb_key' ) ? daw_tmdb_key() : '';
	if ( '' === $key ) {
		return null;
	}

	$headers = array( 'Accept' => 'application/json' );
	if ( 0 === strpos( $key, 'eyJ' ) ) {
		$headers['Authorization'] = 'Bearer ' . $key;
	} else {
		$args['api_key'] = $key;
	}

	$url = 'https://api.themoviedb.org/3/' . ltrim( $path, '/' );
	if ( $args ) {
		$url = add_query_arg( array_map( 'rawurlencode', $args ), $url );
	}

	$res = wp_remote_get(
		$url,
		array(
			'timeout'    => 12,
			'headers'    => $headers,
			'user-agent' => 'DiscoverAsianWorld/1.0 (+https://discoverasianworld.com)',
		)
	);

	if ( is_wp_error( $res ) ) {
		return null;
	}

	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 404 === $code ) {
		return false;
	}
	if ( 200 !== $code ) {
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	return is_array( $body ) ? $body : null;
}

/**
 * Ask TMDB for the record's poster (title) or profile photo (person) path and
 * store it. Returns the path, '' when TMDB has none, null when TMDB could not
 * be reached (nothing is changed then).
 */
function daw_tmdb_img_sync( $post_id ) {
	$post_id = (int) $post_id;
	$ref     = function_exists( 'daw_tmdb_ref' ) ? daw_tmdb_ref( $post_id ) : array();

	if ( ! $ref ) {
		delete_post_meta( $post_id, DAW_TMDB_IMG );
		delete_post_meta( $post_id, DAW_TMDB_IMG_REF );
		update_post_meta( $post_id, DAW_TMDB_IMG_AT, time() );
		return '';
	}

	$season = ! empty( $ref['season'] ) ? (int) $ref['season'] : 0;
	$body   = daw_tmdb_api_get( $ref['kind'] . '/' . $ref['id'] . ( $season ? '/season/' . $season : '' ), array( 'language' => 'en-US' ) );
	if ( $season && is_array( $body ) ) {
		// A season page (madde 65): the season's own poster and first air date,
		// the series' names. A season without a poster of its own shows the
		// series poster.
		$show = daw_tmdb_api_get( 'tv/' . $ref['id'], array( 'language' => 'en-US' ) );
		if ( null === $show ) {
			$body = null;
		} else {
			$show = is_array( $show ) ? $show : array();
			$body = array(
				'poster_path'    => (string) ( $body['poster_path'] ?? '' ) ?: (string) ( $show['poster_path'] ?? '' ),
				'first_air_date' => (string) ( $body['air_date'] ?? '' ),
				'original_name'  => (string) ( $show['original_name'] ?? '' ),
				'name'           => (string) ( $show['name'] ?? '' ),
				'_season'        => $season,
			);
		}
	}
	if ( null === $body ) {
		// Remembered, so a record TMDB cannot answer for does not hold up the
		// queue; it is tried again a day later.
		update_post_meta( $post_id, '_daw_tmdb_img_fail', time() );
		return null;
	}
	delete_post_meta( $post_id, '_daw_tmdb_img_fail' );

	$path = '';
	if ( is_array( $body ) ) {
		$path = 'person' === $ref['kind']
			? (string) ( $body['profile_path'] ?? '' )
			: (string) ( $body['poster_path'] ?? '' );
	}

	// Only a bare TMDB file name is ever stored, so nothing but an image path
	// on image.tmdb.org can come out of this meta.
	if ( '' !== $path && ! preg_match( '#^/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)$#i', $path ) ) {
		$path = '';
	}

	if ( '' === $path ) {
		delete_post_meta( $post_id, DAW_TMDB_IMG );
	} else {
		update_post_meta( $post_id, DAW_TMDB_IMG, $path );
	}

	// Is this TMDB record really ours? Until it is shown to be, its picture
	// is not used (owner's rule: nothing we are not sure of goes on a page).
	list( $verdict, $why ) = is_array( $body ) ? daw_tmdb_match_check( $post_id, $ref, $body ) : array( 'gone', 'TMDB bu numarada kayıt bulamadı' );
	update_post_meta( $post_id, DAW_TMDB_CHECK, $verdict );
	update_post_meta( $post_id, DAW_TMDB_WHY, $why );
	update_post_meta( $post_id, DAW_TMDB_IMG_REF, $ref['kind'] . '/' . $ref['id'] . ( $season ? '/season/' . $season : '' ) );
	update_post_meta( $post_id, DAW_TMDB_IMG_AT, time() );

	return $path;
}

/** The stored path whatever the match check said, or ''. */
function daw_tmdb_img_path_raw( $post_id ) {
	$p = (string) get_post_meta( (int) $post_id, DAW_TMDB_IMG, true );
	return preg_match( '#^/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)$#i', $p ) ? $p : '';
}

/**
 * The path the site may use: only when the TMDB record was confirmed to be
 * this record. Everything that shows, backs up or cleans goes through here.
 */
function daw_tmdb_img_path( $post_id ) {
	if ( 'ok' !== (string) get_post_meta( (int) $post_id, DAW_TMDB_CHECK, true ) ) {
		return '';
	}
	return daw_tmdb_img_path_raw( $post_id );
}

/** Letters and digits only, lower case: "Pinocchio (2014)" and "피노키오" compare fairly. */
function daw_tmdb_match_norm( $s ) {
	$s = preg_replace( '/[^\p{L}\p{N}]+/u', '', (string) $s );
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
}

/**
 * Compare what TMDB says with what the record says.
 *
 * Titles: the year of the first broadcast or release (one year of slack for
 * a December premiere) and the native title. People: the birth date and the
 * native name. A clear disagreement is 'mismatch'; nothing to compare yet is
 * 'unverified' and is tried again after the record is filled in.
 *
 * @return array  [ 'ok'|'mismatch'|'unverified', reason in Turkish ]
 */
function daw_tmdb_match_check( $post_id, $ref, $body ) {
	$post_id = (int) $post_id;

	if ( 'person' === $ref['kind'] ) {
		$ours_b  = (int) get_post_meta( $post_id, 'daw_born', true );
		$their_b = (string) ( $body['birthday'] ?? '' );
		$ours_n  = daw_tmdb_match_norm( get_post_meta( $post_id, 'daw_native_name', true ) );
		$names   = array_merge( array( (string) ( $body['name'] ?? '' ) ), (array) ( $body['also_known_as'] ?? array() ) );
		$name_ok = '' !== $ours_n && in_array( $ours_n, array_map( 'daw_tmdb_match_norm', $names ), true );

		// Kişide TMDB'nin kendisi yanlış olabilir (Yoyo Mung: kayıtta 1975,
		// TMDB'de yanlışlıkla 1973). En az iki bağımsız kaynaktan (TMDB dışında)
		// kişi ve kayıttaki değer elle çapraz doğrulanınca _daw_ok['tmdb_match']
		// yazılır; drama/film'deki aynı kaçış kapısının kişi karşılığı (madde 69).
		// Kayıt TMDB'nin değerine sessizce çekilmez, yalnız görsel engeli kalkar.
		$okm                 = get_post_meta( $post_id, '_daw_ok', true );
		$manually_confirmed  = is_array( $okm ) && ! empty( $okm['tmdb_match'] );

		if ( $ours_b > 0 && '' !== $their_b ) {
			$ours_d = gmdate( 'Y-m-d', $ours_b );
			$gap    = abs( $ours_b - (int) strtotime( $their_b . ' 00:00:00 UTC' ) );
			// One day of slack: a date saved as local midnight reads as the day
			// before in UTC.
			if ( $gap <= DAY_IN_SECONDS ) {
				return array( 'ok', 'Doğum tarihi tutuyor' );
			}
			if ( $manually_confirmed ) {
				return array( 'ok', sprintf( 'Doğum tarihi tutmuyor (kayıtta %s, TMDB\'de %s) ama kişi elle çapraz doğrulandı, _daw_ok ile açıklandı', $ours_d, $their_b ) );
			}
			return array( 'mismatch', sprintf( 'Doğum tarihi tutmuyor: kayıtta %s, TMDB\'de %s', $ours_d, $their_b ) );
		}
		if ( $name_ok ) {
			return array( 'ok', 'Yerel ad tutuyor' );
		}
		if ( '' !== $ours_n ) {
			if ( $manually_confirmed ) {
				return array( 'ok', 'Yerel ad TMDB\'deki adlarla tutmuyor ama kişi elle çapraz doğrulandı, _daw_ok ile açıklandı' );
			}
			return array( 'mismatch', 'Yerel ad TMDB\'deki adlarla tutmuyor' );
		}
		// No birthday on TMDB and no native script to compare (an Australian
		// actor has no Hangul name): a person checked the filmography against
		// two other sources and wrote it down.
		if ( $manually_confirmed ) {
			return array( 'ok', 'TMDB\'de doğum tarihi yok ama kişi elle çapraz doğrulandı, _daw_ok ile açıklandı' );
		}
		return array( 'unverified', 'Kayıtta doğum tarihi ve yerel ad yok; kayıt doldurulunca kendiliğinden kontrol edilecek' );
	}

	$start    = (int) get_post_meta( $post_id, 'daw_aired_start', true );
	$ours_y   = $start > 0 ? (int) gmdate( 'Y', $start ) : 0;
	$their_d  = (string) ( $body['first_air_date'] ?? $body['release_date'] ?? '' );
	$their_y  = '' !== $their_d ? (int) substr( $their_d, 0, 4 ) : 0;
	$ours_n   = daw_tmdb_match_norm( get_post_meta( $post_id, 'daw_native_title', true ) );
	$their_ns = array_filter(
		array_map(
			'daw_tmdb_match_norm',
			array( $body['original_name'] ?? '', $body['original_title'] ?? '', $body['name'] ?? '', $body['title'] ?? '' )
		)
	);
	$name_ok = false;
	if ( '' !== $ours_n ) {
		foreach ( $their_ns as $t ) {
			if ( $t === $ours_n || ( mb_strlen( $ours_n ) >= 2 && ( false !== strpos( $t, $ours_n ) || false !== strpos( $ours_n, $t ) ) ) ) {
				$name_ok = true;
				break;
			}
		}
	}

	// A film released in different years in different countries (Fetish: US
	// 2008, Korea 2010) fails the year test while the native title matches.
	// A person who checked cast and title writes _daw_ok['tmdb_match'] with
	// the reason; the native title must still agree, so the note cannot
	// approve a different production.
	$okm = get_post_meta( $post_id, '_daw_ok', true );
	if ( $name_ok && is_array( $okm ) && ! empty( $okm['tmdb_match'] ) ) {
		return array( 'ok', 'Yerel ad tutuyor, yıl farkı _daw_ok ile açıklandı' );
	}

	if ( $ours_y && $their_y ) {
		if ( abs( $ours_y - $their_y ) <= 1 ) {
			return array( 'ok', 'Yıl tutuyor' );
		}
		// Remakes share a title, so a wrong year is a mismatch even when the
		// name matches.
		return array( 'mismatch', sprintf( 'Yıl tutmuyor: kayıtta %d, TMDB\'de %d', $ours_y, $their_y ) );
	}
	if ( $name_ok ) {
		return array( 'ok', 'Yerel ad tutuyor' );
	}
	// A season page tied through its checked series record (madde 65): the
	// series match was verified, TMDB has this season, and the title names it.
	if ( ! empty( $body['_season'] ) && defined( 'DAW_SEASON_PARENT' ) ) {
		$parent = (int) get_post_meta( $post_id, DAW_SEASON_PARENT, true );
		if ( $parent && 'ok' === get_post_meta( $parent, DAW_TMDB_CHECK, true ) ) {
			return array( 'ok', sprintf( 'Seri kaydı doğrulandı, TMDB\'de %d. sezon var', (int) $body['_season'] ) );
		}
	}
	// TMDB holds no title in the record's own script and no year (How Am I,
	// SBS 1998: TMDB has only "How Am I?"), so nothing can disagree. A person
	// who matched the entry by hand wrote _daw_ok['tmdb_match'].
	if ( '' !== $ours_n && ! $their_y && is_array( $okm ) && ! empty( $okm['tmdb_match'] ) && preg_match( '/[^\x00-\x7F]/u', $ours_n ) ) {
		$native_on_tmdb = false;
		foreach ( $their_ns as $t ) {
			if ( preg_match( '/[^\x00-\x7F]/u', $t ) ) {
				$native_on_tmdb = true;
			}
		}
		if ( ! $native_on_tmdb ) {
			return array( 'ok', 'TMDB\'de yerel ad ve yıl yok; eşleşme elle doğrulandı, _daw_ok ile açıklandı' );
		}
	}
	if ( '' !== $ours_n && $their_ns ) {
		return array( 'mismatch', 'Yerel ad TMDB\'deki adla tutmuyor' );
	}
	return array( 'unverified', 'Kayıtta yayın yılı ve yerel ad yok; kayıt doldurulunca kendiliğinden kontrol edilecek' );
}

/* ============================================================== decision === */

/**
 * Where a record's main picture comes from, decided in one place.
 *
 * @return string  'local'    a picture whose rights are registered: host it.
 *                 'remote'   show TMDB's file from TMDB's server.
 *                 'copy'     a local copy of a TMDB file (tmdb-hotlink.php
 *                            already serves it from TMDB when that switch is on).
 *                 'monogram' strict mode and nothing safe to show.
 *                 'default'  not this module's business.
 */
function daw_remote_mode_for( $post_id ) {
	static $memo = array();
	$post_id = (int) $post_id;

	if ( isset( $memo[ $post_id ] ) ) {
		return $memo[ $post_id ];
	}

	$mode = 'default';
	$type = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';

	if ( in_array( $type, daw_remote_types(), true ) ) {
		$att    = (int) get_post_thumbnail_id( $post_id );
		$path   = daw_tmdb_img_path( $post_id );
		$source = daw_img_source();
		if ( 'off' === $source ) {
			$memo[ $post_id ] = 'default';
			return 'default';
		}
		// In an emergency the backup copy stands in for TMDB's file; a record
		// with no backup yet shows the monogram rather than an empty box.
		$far    = 'vault' === $source
			? ( function_exists( 'daw_vault_file_for' ) && '' !== daw_vault_file_for( $post_id ) ? 'vault' : 'monogram' )
			: 'remote';

		if ( 'none' === $source ) {
			$mode = 'monogram';
		} elseif ( daw_remote_on() && daw_remote_tmdb_first() && '' !== $path ) {
			$mode = $far;
		} elseif ( $att && function_exists( 'daw_lic_status' ) && 'ok' === daw_lic_status( $att, $post_id ) ) {
			$mode = 'local';
		} elseif ( daw_remote_on() && '' !== $path ) {
			$mode = $far;
		} elseif ( $att && function_exists( 'daw_tmdb_file' ) && '' !== daw_tmdb_file( $att ) ) {
			$mode = daw_remote_strict() && ! daw_tmdb_hotlink_on() ? 'monogram' : 'copy';
		} elseif ( daw_remote_strict() && $att ) {
			$mode = 'monogram';
		} elseif ( ! $att && '' === daw_tmdb_img_path_raw( $post_id ) && '' !== (string) get_post_meta( $post_id, '_daw_tmdb_img_at', true ) && '' === (string) get_post_meta( $post_id, '_daw_tmdb_img_fail', true ) ) {
			// TMDB was read and simply has no poster for this record (a stub
			// entry), and nothing is uploaded here: the page already draws the
			// posterless card, so say so instead of "local image without rights".
			$mode = 'monogram';
		}
	}

	$memo[ $post_id ] = $mode;
	return $mode;
}

/** Does the record have a picture to show at all (for layouts that need one). */
function daw_has_record_image( $post_id ) {
	$mode = daw_remote_mode_for( $post_id );
	if ( 'remote' === $mode || 'vault' === $mode ) {
		return true;
	}
	if ( 'monogram' === $mode ) {
		return false;
	}
	return has_post_thumbnail( $post_id );
}

/**
 * TMDB sizes offered to the browser. Posters top out at w500 and photos at
 * h632: large enough for the hero on a phone and a desktop, never the
 * original upload.
 */
function daw_remote_sizes( $is_person ) {
	return $is_person
		? array( 'w185' => 185, 'w342' => 342, 'h632' => 421 ) // w342: a phone's 130px portrait at 1.75x needs 228px; h632 sent 26 KB for it.
		: array( 'w185' => 185, 'w342' => 342, 'w500' => 500 );
}

/** A remote url at a width suited to $size ('thumbnail', 'medium', 'large'…). */
function daw_record_image_url( $post_id, $size = 'medium' ) {
	$mode = daw_remote_mode_for( $post_id );
	if ( 'remote' === $mode ) {
		$is_person = in_array( daw_get_type( $post_id ), array( 'person', 'group' ), true );
		$small     = in_array( $size, array( 'thumbnail', 'medium' ), true );
		$tmdb      = $is_person ? ( $small ? 'w185' : 'h632' ) : ( $small ? 'w342' : 'w500' );
		return 'https://image.tmdb.org/t/p/' . $tmdb . daw_tmdb_img_path( $post_id );
	}
	if ( 'vault' === $mode ) {
		return daw_vault_url( $post_id );
	}
	if ( 'monogram' === $mode ) {
		return '';
	}
	return (string) get_the_post_thumbnail_url( $post_id, $size );
}

/**
 * The <img> for a record, or null when the caller should do what it always
 * did, or '' when it should draw the monogram.
 *
 * @param array $attrs  loading, fetchpriority, decoding, sizes, alt.
 */
function daw_remote_image_html( $post_id, $size, $attrs ) {
	$mode = daw_remote_mode_for( $post_id );

	if ( 'monogram' === $mode ) {
		return '';
	}
	if ( 'remote' !== $mode && 'vault' !== $mode ) {
		return null;
	}

	$is_person = in_array( daw_get_type( $post_id ), array( 'person', 'group' ), true );
	$path      = daw_tmdb_img_path( $post_id );
	$set       = array();
	$src       = '';

	if ( 'vault' === $mode ) {
		$src   = daw_vault_url( $post_id );
		$set[] = esc_url( $src ) . ' ' . ( $is_person ? 421 : 500 ) . 'w';
	} else {
		foreach ( daw_remote_sizes( $is_person ) as $tmdb => $w ) {
			$url   = 'https://image.tmdb.org/t/p/' . $tmdb . $path;
			$set[] = esc_url( $url ) . ' ' . (int) $w . 'w';
			$src   = $url;
		}
	}

	// Every TMDB poster and profile photo is uploaded at 2:3, so the box can be
	// reserved before the file arrives (rule 7.2).
	$w = $is_person ? 421 : 500;
	$h = (int) round( $w * 1.5 );

	$alt = isset( $attrs['alt'] ) && '' !== $attrs['alt'] ? $attrs['alt'] : daw_record_image_alt( $post_id );

	return sprintf(
		'<img src="%1$s" srcset="%2$s" sizes="%3$s" width="%4$d" height="%5$d" alt="%6$s" loading="%7$s" fetchpriority="%8$s" decoding="%9$s" class="daw-remote-img" referrerpolicy="strict-origin-when-cross-origin" onerror="this.parentNode&amp;&amp;this.parentNode.classList.add(\'daw-img-failed\');this.remove();">%10$s',
		esc_url( $src ),
		esc_attr( implode( ', ', $set ) ),
		esc_attr( isset( $attrs['sizes'] ) ? $attrs['sizes'] : '(min-width: 700px) 300px, 60vw' ),
		$w,
		$h,
		esc_attr( $alt ),
		esc_attr( isset( $attrs['loading'] ) ? $attrs['loading'] : 'lazy' ),
		esc_attr( isset( $attrs['fetchpriority'] ) ? $attrs['fetchpriority'] : 'auto' ),
		esc_attr( isset( $attrs['decoding'] ) ? $attrs['decoding'] : 'async' ),
		// If TMDB does not answer (outage, blocked key), the drawn monogram
		// takes the box instead of an empty frame.
		function_exists( 'daw_monogram' ) ? '<span class="daw-mono-fallback">' . daw_monogram( $post_id ) . '</span>' : ''
	);
}

/**
 * The alt text: the one piece of image SEO that stays ours when the file is
 * served from TMDB. It names the work the way people search for it:
 * "Pinocchio (2014) K-Drama poster", "Lee Jong Suk (이종석), Korean actor".
 */
function daw_record_image_alt( $post_id ) {
	$title = trim( wp_strip_all_tags( get_the_title( $post_id ) ) );
	$type  = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
	$terms = get_the_terms( $post_id, 'dw_country' );
	$land  = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
	$adj   = array( 'South Korea' => 'Korean', 'Korea' => 'Korean', 'Japan' => 'Japanese', 'China' => 'Chinese', 'Taiwan' => 'Taiwanese', 'Thailand' => 'Thai', 'Philippines' => 'Filipino', 'Hong Kong' => 'Hong Kong' );
	$a     = $adj[ $land ] ?? '';
	if ( in_array( $type, array( 'person', 'group' ), true ) ) {
		$native = trim( (string) get_post_meta( $post_id, 'daw_native_name', true ) );
		// Only the native script: "이종석 (Lee Jong Suk)" becomes "이종석".
		$native = trim( preg_replace( '/\s*\(.*$/u', '', $native ) );
		if ( ! preg_match( '/[^\x00-\x7F]/u', $native ) || 0 === strcasecmp( $native, $title ) ) {
			$native = '';
		}
		// "Korean actress" is the pattern people search for women; the gendered
		// word is used only where the record holds the gender.
		$role = 'group' === $type ? 'group' : ( 'female' === strtolower( (string) get_post_meta( $post_id, 'daw_gender', true ) ) ? 'actress' : 'actor' );
		$job  = strtolower( trim( (string) get_post_meta( $post_id, 'daw_occupation', true ) ) );
		if ( 'group' !== $type && '' !== $job && ! preg_match( '/\bact(or|ress)\b/', $job ) ) {
			// A singer or idol is not "actor": first job from the record instead.
			$first = trim( (string) preg_split( '/\s*(?:\band\b|&|,|\/)\s*/u', $job )[0] );
			if ( '' !== $first && strlen( $first ) <= 20 ) {
				$role = $first;
			}
		}
		$who  = $title . ( '' !== $native ? ' (' . $native . ')' : '' );
		return '' !== $a ? $who . ', ' . $a . ' ' . $role : $who;
	}
	$start = (int) get_post_meta( $post_id, 'daw_aired_start', true );
	// No second year when the title already carries one: "The Beauty Inside (2015)".
	$year = ( $start > 0 && ! preg_match( '/\((19|20)\d\d\)\s*$/', $title ) ) ? ' (' . gmdate( 'Y', $start ) . ')' : '';
	switch ( $type ) {
		case 'drama':
			$kind = 'Korean' === $a ? 'K-Drama' : trim( $a . ' drama' );
			break;
		case 'film':
			$kind = trim( $a . ' movie' );
			break;
		case 'variety':
			$kind = trim( $a . ' variety show' );
			break;
		case 'anime':
			$kind = 'anime';
			break;
		default:
			$kind = '';
	}
	return trim( $title . $year . ' ' . $kind ) . ' poster';
}

/** The credit line under a remote picture. */
function daw_tmdb_credit_line( $post_id ) {
	// No company is named as the owner: the production company on file is not
	// always the rights holder, and a wrong © line is a false statement.
	if ( in_array( daw_get_type( $post_id ), array( 'person', 'group' ), true ) ) {
		return __( 'Photo via TMDB. Rights belong to the photographer or agency.', 'daw' );
	}
	return __( 'Poster via TMDB. Rights belong to the studio, broadcaster or distributor.', 'daw' );
}

/* ================================================================== sync === */

/**
 * Records with a TMDB id whose picture was never checked, is over 30 days old,
 * or was not confirmed and is a day old (so a filled-in record is re-checked).
 *
 * WHY PLAIN SQL
 * -------------
 * The first version was a nested meta_query. On this site's postmeta table it
 * took longer than PHP's time limit, the request died, and the one-button job
 * sat on "1/3" for ever (found on the live site, 24 Sep 2026). One LEFT JOIN
 * per key on (post_id, meta_key) uses the index and answers at once.
 */
function daw_tmdb_img_queue( $limit = 50 ) {
	global $wpdb;
	$now = time();
	$sql = $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = 'daw_tmdb_id' AND t.meta_value <> ''
		 LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
		 LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
		 LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_daw_tmdb_img_fail'
		 WHERE p.post_type = 'post' AND p.post_status = 'publish'
		   AND ( f.meta_value IS NULL OR CAST( f.meta_value AS UNSIGNED ) < %d )
		   AND ( a.meta_value IS NULL
		         OR CAST( a.meta_value AS UNSIGNED ) < %d
		         OR ( COALESCE( c.meta_value, '' ) <> 'ok' AND CAST( a.meta_value AS UNSIGNED ) < %d ) )
		 GROUP BY p.ID ORDER BY p.ID ASC LIMIT %d",
		DAW_TMDB_IMG_AT,
		DAW_TMDB_CHECK,
		$now - DAY_IN_SECONDS,
		$now - 30 * DAY_IN_SECONDS,
		$now - DAY_IN_SECONDS,
		max( 1, (int) $limit )
	);
	return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB
}

/** One batch of at most 50 (rule 8.1). Returns [synced, with path, failed]. */
function daw_tmdb_img_run_batch( $limit = 50 ) {
	$done = 0;
	$with = 0;
	$fail = 0;
	foreach ( daw_tmdb_img_queue( min( 50, (int) $limit ) ) as $id ) {
		$r = daw_tmdb_img_sync( $id );
		if ( null === $r ) {
			$fail++;
			continue;
		}
		$done++;
		if ( '' !== $r ) {
			$with++;
		}
	}
	return array( $done, $with, $fail );
}

add_action( 'init', 'daw_tmdb_img_schedule' );
function daw_tmdb_img_schedule() {
	if ( ! wp_next_scheduled( DAW_TMDB_IMG_CRON ) ) {
		wp_schedule_event( time() + 300, 'hourly', DAW_TMDB_IMG_CRON );
	}
}

add_action( DAW_TMDB_IMG_CRON, 'daw_tmdb_img_cron_run' );
function daw_tmdb_img_cron_run() {
	if ( '' === daw_tmdb_key() ) {
		return;
	}
	daw_tmdb_img_run_batch( 50 );
}

/**
 * A record saved with a new or changed TMDB id gets its path a minute later,
 * off the request that saved it.
 */
add_action( 'save_post_post', 'daw_tmdb_img_on_save', 30, 2 );
function daw_tmdb_img_on_save( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) {
		return;
	}
	$ref = function_exists( 'daw_tmdb_ref' ) ? daw_tmdb_ref( $post_id ) : array();
	if ( ! $ref ) {
		// The id was cleared: the picture it pointed at must go too.
		if ( '' !== (string) get_post_meta( $post_id, DAW_TMDB_IMG, true ) && ! wp_next_scheduled( DAW_TMDB_IMG_ONE, array( (int) $post_id ) ) ) {
			wp_schedule_single_event( time() + 60, DAW_TMDB_IMG_ONE, array( (int) $post_id ) );
		}
		return;
	}
	$now = $ref['kind'] . '/' . $ref['id'];
	// A confirmed record with the same id needs nothing. An unconfirmed one is
	// checked again after every save, because the save may have added the
	// year, birth date or native name the check was waiting for.
	if ( $now === (string) get_post_meta( $post_id, DAW_TMDB_IMG_REF, true )
		&& '' !== (string) get_post_meta( $post_id, DAW_TMDB_IMG_AT, true )
		&& 'ok' === (string) get_post_meta( $post_id, DAW_TMDB_CHECK, true ) ) {
		return;
	}
	if ( ! wp_next_scheduled( DAW_TMDB_IMG_ONE, array( (int) $post_id ) ) ) {
		wp_schedule_single_event( time() + 60, DAW_TMDB_IMG_ONE, array( (int) $post_id ) );
	}
}

add_action( DAW_TMDB_IMG_ONE, 'daw_tmdb_img_sync' );

/* ======================================================== detach / undo === */

/**
 * Records whose featured image is an unlicensed local file that is no longer
 * needed: a TMDB path is there to replace it, or strict mode hides it anyway.
 */
function daw_remote_detach_candidates( $limit = 50 ) {
	$ids = function_exists( 'daw_audit_record_ids' ) ? daw_audit_record_ids( daw_remote_types() ) : array();
	$out = array();
	foreach ( $ids as $id ) {
		$att = (int) get_post_thumbnail_id( $id );
		if ( ! $att ) {
			continue;
		}
		$has_path = '' !== daw_tmdb_img_path( $id );
		$licensed = function_exists( 'daw_lic_status' ) && 'ok' === daw_lic_status( $att, $id );
		// A licensed photo is only let go when TMDB's picture replaces it.
		if ( $licensed && ! ( $has_path && daw_remote_tmdb_first() ) ) {
			continue;
		}
		if ( ! $has_path && ! daw_remote_strict() ) {
			continue;
		}
		$out[] = (int) $id;
		if ( $limit && count( $out ) >= $limit ) {
			break;
		}
	}
	return $out;
}

/**
 * Unset the featured image and remember which it was. The attachment itself
 * is not touched: it stays in the media library until the owner deletes it.
 */
function daw_remote_detach( $post_id ) {
	$att = (int) get_post_thumbnail_id( $post_id );
	if ( ! $att ) {
		return false;
	}
	update_post_meta( $post_id, DAW_TMDB_PREV, $att );
	delete_post_meta( $post_id, '_thumbnail_id' );
	return true;
}

function daw_remote_undo( $post_id ) {
	$att = (int) get_post_meta( $post_id, DAW_TMDB_PREV, true );
	if ( ! $att || ! get_post( $att ) ) {
		return false;
	}
	set_post_thumbnail( $post_id, $att );
	delete_post_meta( $post_id, DAW_TMDB_PREV );
	return true;
}

/** Attachment ids that were detached, i.e. unlicensed files nothing shows now. */
function daw_remote_detached_attachments() {
	global $wpdb;
	return array_map(
		'intval',
		(array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_TMDB_PREV ) ) // phpcs:ignore WordPress.DB
	);
}

/* ================================================================ census === */

/** Where every title's and person's picture comes from, counted. */
function daw_remote_census() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}
	$c = array( 'local' => 0, 'remote' => 0, 'vault' => 0, 'copy' => 0, 'unregistered' => 0, 'monogram' => 0, 'none' => 0, 'synced' => 0, 'with_path' => 0, 'backed_up' => 0, 'no_tmdb_id' => 0 );
	$ids = function_exists( 'daw_audit_record_ids' ) ? daw_audit_record_ids( daw_remote_types() ) : array();
	foreach ( $ids as $id ) {
		if ( '' === trim( (string) get_post_meta( $id, 'daw_tmdb_id', true ) ) ) {
			$c['no_tmdb_id']++;
		}
		if ( '' !== (string) get_post_meta( $id, DAW_TMDB_IMG_AT, true ) ) {
			$c['synced']++;
		}
		if ( '' !== daw_tmdb_img_path( $id ) ) {
			$c['with_path']++;
		}
		if ( function_exists( 'daw_vault_file_for' ) && '' !== daw_vault_file_for( $id ) ) {
			$c['backed_up']++;
		}
		$mode = daw_remote_mode_for( $id );
		if ( 'default' === $mode ) {
			$mode = has_post_thumbnail( $id ) ? 'unregistered' : 'none';
		}
		$c[ $mode ]++;
	}
	$c['total'] = count( $ids );
	$memo       = $c;
	return $c;
}

/* The admin screen for all of this lives in images-hub.php. */

/** Media Library filters: ?daw_img=detached and ?daw_img=tmdb (list mode). */
add_action( 'pre_get_posts', 'daw_remote_media_filter' );
function daw_remote_media_filter( $q ) {
	if ( ! is_admin() || ! $q->is_main_query() || empty( $_GET['daw_img'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	global $pagenow;
	if ( 'upload.php' !== $pagenow ) {
		return;
	}
	$which = sanitize_key( $_GET['daw_img'] ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( 'detached' === $which ) {
		$ids = daw_remote_detached_attachments();
		$q->set( 'post__in', $ids ? $ids : array( 0 ) );
	} elseif ( 'tmdb' === $which ) {
		$q->set(
			'meta_query',
			array(
				array(
					'key'   => '_daw_source',
					'value' => 'TMDB',
				),
			)
		);
	}
}

/* ============================================================ share image === */

/**
 * The social-share picture of a record is TMDB's, never a file on this server.
 *
 * Rank Math builds og:image and twitter:image from the featured image. A record
 * whose old upload was never detached would still send that file to every
 * Facebook, X and messenger preview, which is exactly the hosted copy the site
 * does not keep. On a record page both tags are pointed at the same TMDB url
 * the page itself shows; a record with no TMDB picture sends none rather than
 * a local one.
 */
function daw_share_image_for_record( $url ) {
	if ( ! is_singular( 'post' ) ) {
		return $url;
	}
	$id = (int) get_queried_object_id();
	if ( ! $id || ! function_exists( 'daw_get_type' ) || ! in_array( daw_get_type( $id ), daw_remote_types(), true ) ) {
		return $url;
	}
	$remote = daw_record_image_url( $id, 'large' );
	if ( '' !== $remote && false === strpos( $remote, '/wp-content/uploads/' ) ) {
		return $remote;
	}
	return false !== strpos( (string) $url, '/wp-content/uploads/' ) ? '' : $url;
}
// One filter for og:image and twitter:image. The SEO engine applies it, and
// while Rank Math is active seo-engine.php bridges it into Rank Math's three.
add_filter( 'daw_seo_og_image', 'daw_share_image_for_record', 20 );

/* ========================================================= first picture === */

/**
 * The connection to TMDB's image host, opened before the page asks for it.
 *
 * Every poster and portrait on the site comes from image.tmdb.org, the hero
 * included, and the hero is the Largest Contentful Paint on the home page and
 * on every title and person. On a phone the DNS lookup, TCP and TLS handshake
 * for that host cost several hundred milliseconds that only started once the
 * parser reached the first <img>. A preconnect starts them with the head.
 */
add_action( 'wp_head', 'daw_remote_preconnect', 1 );
function daw_remote_preconnect() {
	if ( is_admin() || is_feed() || ! daw_remote_on() ) {
		return;
	}

	echo '<link rel="preconnect" href="https://image.tmdb.org">' . "\n";
}

/**
 * The hero picture, asked for from the head.
 *
 * The browser only learns about the hero <img> when it parses the body, after
 * the stylesheets. A preload with the same srcset and sizes the <img> carries
 * (daw_poster's lead branch: "(max-width: 700px) 130px, 260px") lets it start
 * the download at once and pick the same file, so nothing is fetched twice.
 * Only for the record whose picture is the first one the page prints: the
 * featured record on the home page, the record itself on a title or person.
 */
add_action( 'wp_head', 'daw_remote_preload_hero', 2 );
function daw_remote_preload_hero() {
	if ( is_admin() || is_feed() || ! daw_remote_on() ) {
		return;
	}

	$id = 0;
	if ( is_front_page() && function_exists( 'daw_featured_record' ) ) {
		$id = (int) daw_featured_record();
	} elseif ( is_singular( 'post' ) ) {
		$id = (int) get_queried_object_id();
		// A group page draws its own 640px picture without a srcset
		// (single-group.php), so a preload with one would fetch twice.
		if ( function_exists( 'daw_get_type' ) && 'group' === daw_get_type( $id ) ) {
			return;
		}
	}

	if ( ! $id || ! function_exists( 'daw_get_type' ) || ! in_array( daw_get_type( $id ), daw_remote_types(), true ) ) {
		return;
	}
	if ( 'remote' !== daw_remote_mode_for( $id ) ) {
		return;
	}

	$path = daw_tmdb_img_path( $id );
	if ( '' === $path ) {
		return;
	}

	$is_person = in_array( daw_get_type( $id ), array( 'person', 'group' ), true );
	$set       = array();
	$src       = '';
	foreach ( daw_remote_sizes( $is_person ) as $tmdb => $w ) {
		$src   = 'https://image.tmdb.org/t/p/' . $tmdb . $path;
		$set[] = $src . ' ' . (int) $w . 'w';
	}

	printf(
		'<link rel="preload" as="image" href="%s" imagesrcset="%s" imagesizes="%s" fetchpriority="high">' . "\n",
		esc_url( $src ),
		esc_attr( implode( ', ', $set ) ),
		esc_attr( '(max-width: 700px) 130px, 260px' )
	);
}
