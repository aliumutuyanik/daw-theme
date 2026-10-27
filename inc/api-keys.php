<?php
/**
 * API keys for the sources the server reads itself, and the readers.
 *
 * WHY
 * ---
 * Every search an agent runs through its own tools costs tokens and a quota
 * that runs out in the middle of a batch. The owner took keys for the services
 * that offer an API (24 Sep 2026) so the server asks them instead, once, and
 * keeps what it may keep next to the record:
 *
 *   YouTube Data API v3  the trailer's channel, upload date, length, status
 *                        -> _daw_src_yt (refreshed with the store, 30 days)
 *   Kakao (Daum search)  web, video and blog results for the record's native
 *                        title: leads for the second source (Korean press)
 *                        -> _daw_src_kakao
 *   KOBIS (KOFIC)        Korean films: release date check against the
 *                        official box office system. Its terms (articles
 *                        6.2 and 8.3.2) allow live use only, so nothing KOBIS
 *                        returns is stored: the audit asks at the moment it
 *                        runs and keeps only "matches" or "differs"
 *
 * The keys live in options that do not autoload, are never printed back in
 * full, and are never sent anywhere but their own service.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_EXT_AT = '_daw_src_ext_at';

/** The services, their option names and what they are for. */
function daw_api_services() {
	return array(
		'youtube' => array( 'YouTube Data API v3', 'daw_key_youtube', 'Fragman kanalı, yükleme tarihi, süre, durum' ),
		'kakao'   => array( 'Kakao (Daum arama) REST API', 'daw_key_kakao', 'Korece web, video ve blog sonuçları: ikinci kaynak adayları' ),
		'kobis'   => array( 'KOBIS Open API (KOFIC)', 'daw_key_kobis', 'Kore filmleri: vizyon tarihi kontrolü, yalnız anlık (saklanmaz)' ),
	);
}

function daw_api_key( $service ) {
	$const = 'DAW_KEY_' . strtoupper( $service );
	if ( defined( $const ) && constant( $const ) ) {
		return (string) constant( $const );
	}
	$map = daw_api_services();
	return isset( $map[ $service ] ) ? trim( (string) get_option( $map[ $service ][1], '' ) ) : '';
}

/** A GET that returns decoded JSON, or null. */
function daw_api_get_json( $url, array $headers = array() ) {
	$res = wp_remote_get(
		$url,
		array(
			'timeout'    => 20,
			'user-agent' => 'DiscoverAsianWorld/1.0 (https://discoverasianworld.com)',
			'headers'    => array_merge( array( 'Accept' => 'application/json' ), $headers ),
		)
	);
	// A failure used to vanish here: the record was marked failed, retried an
	// hour later, and the page kept showing a day-old Wikidata error. It now
	// says which service answered what. Never the URL: it carries the key.
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( is_wp_error( $res ) ) {
		if ( function_exists( 'daw_src_error' ) ) {
			daw_src_error( $host . ': ' . $res->get_error_message() );
		}
		return null;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$b    = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code ) {
		daw_api_quota_note( $host, $code, is_array( $b ) ? $b : array() );
		if ( function_exists( 'daw_src_error' ) ) {
			$why = is_array( $b ) ? (string) ( $b['error']['message'] ?? $b['message'] ?? $b['errorType'] ?? '' ) : '';
			daw_src_error( $host . ': HTTP ' . $code . ( '' !== $why ? ' ' . wp_strip_all_tags( $why ) : '' ) );
		}
		return null;
	}
	return is_array( $b ) ? $b : null;
}

/* =============================================================== readers === */

/**
 * Daily quotas. YouTube gives 10,000 units a day and resets at midnight
 * Pacific time; Kakao resets at midnight in Seoul. When a service says the
 * day's quota is spent, nothing more is sent to it until the reset, and the
 * records it could not read wait in daw_ext_pending for the retry job.
 */
function daw_api_quota_note( $host, $code, array $body ) {
	$reason = (string) ( $body['error']['errors'][0]['reason'] ?? $body['errorType'] ?? '' );
	if ( false !== strpos( $host, 'googleapis.com' ) && 403 === $code && preg_match( '/quota|dailyLimit|rateLimit/i', $reason ) ) {
		update_option( 'daw_quota_youtube', daw_api_next_reset( 8 ), false );
	} elseif ( false !== strpos( $host, 'kakao.com' ) && ( 429 === $code || preg_match( '/limit|quota/i', $reason ) ) ) {
		update_option( 'daw_quota_kakao', daw_api_next_reset( 15 ), false );
	}
}

/** The next time the clock reaches $hour:05 UTC. */
function daw_api_next_reset( $hour ) {
	$t = gmmktime( (int) $hour, 5, 0, (int) gmdate( 'n' ), (int) gmdate( 'j' ), (int) gmdate( 'Y' ) );
	return $t > time() ? $t : $t + DAY_IN_SECONDS;
}

/** Is this service's daily quota spent right now? */
function daw_api_quota_out( $service ) {
	return (int) get_option( 'daw_quota_' . $service, 0 ) > time();
}

/** Records whose YouTube / Kakao read is still owed, oldest first. */
function daw_ext_pending( $add = 0, $remove = 0 ) {
	$list = array_map( 'intval', (array) get_option( 'daw_ext_pending', array() ) );
	if ( $add && ! in_array( (int) $add, $list, true ) ) {
		$list[] = (int) $add;
	}
	if ( $remove ) {
		$list = array_values( array_diff( $list, array( (int) $remove ) ) );
	}
	if ( $add || $remove ) {
		update_option( 'daw_ext_pending', $list, false );
	}
	return $list;
}

/**
 * The retry job: every hour it looks at the owed list, and when the quota is
 * open again it reads them in small steps until the list is empty. Nothing is
 * sent while a quota is spent, so an empty hour costs nothing.
 */
add_action( 'daw_ext_retry', 'daw_ext_retry_run' );
function daw_ext_retry_run() {
	$list = daw_ext_pending();
	if ( ! $list || ( daw_api_quota_out( 'youtube' ) && daw_api_quota_out( 'kakao' ) ) ) {
		return;
	}
	$start = microtime( true );
	foreach ( $list as $id ) {
		if ( microtime( true ) - $start > 20 || daw_api_quota_out( 'youtube' ) ) {
			break;
		}
		daw_src_ext_sync( $id );
	}
	update_option( 'daw_ext_retry_at', time(), false );
}

add_action( 'init', 'daw_ext_retry_schedule' );
function daw_ext_retry_schedule() {
	if ( ! wp_next_scheduled( 'daw_ext_retry' ) ) {
		wp_schedule_event( time() + 600, 'hourly', 'daw_ext_retry' );
	}
}

/** YouTube video id from a url or an id. */
function daw_yt_id( $url ) {
	$url = trim( (string) $url );
	if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $url ) ) {
		return $url;
	}
	if ( preg_match( '#(?:v=|youtu\.be/|/embed/|/shorts/)([A-Za-z0-9_-]{11})#', $url, $m ) ) {
		return $m[1];
	}
	return '';
}

/** One video's facts, or null. */
function daw_yt_video( $id ) {
	$key = daw_api_key( 'youtube' );
	if ( '' === $key || '' === $id || daw_api_quota_out( 'youtube' ) ) {
		return null;
	}
	$b = daw_api_get_json( add_query_arg( array_map( 'rawurlencode', array( 'part' => 'snippet,contentDetails,status', 'id' => $id, 'key' => $key ) ), 'https://www.googleapis.com/youtube/v3/videos' ) );
	if ( null === $b ) {
		return null;
	}
	$v = (array) ( $b['items'][0] ?? array() );
	if ( ! $v ) {
		return array( 'id' => $id, 'found' => false );
	}
	return array(
		'id'           => $id,
		'found'        => true,
		'url'          => 'https://www.youtube.com/watch?v=' . $id,
		'title'        => (string) ( $v['snippet']['title'] ?? '' ),
		'channel_id'   => (string) ( $v['snippet']['channelId'] ?? '' ),
		'channel'      => (string) ( $v['snippet']['channelTitle'] ?? '' ),
		'published'    => (string) ( $v['snippet']['publishedAt'] ?? '' ),
		'duration'     => (string) ( $v['contentDetails']['duration'] ?? '' ),
		'privacy'      => (string) ( $v['status']['privacyStatus'] ?? '' ),
		'embeddable'   => ! empty( $v['status']['embeddable'] ),
		'upload'       => (string) ( $v['status']['uploadStatus'] ?? '' ),
	);
}

/** Kakao Daum search: web, vclip, blog or cafe. Rows or null. */
function daw_kakao_search( $kind, $query, $size = 10 ) {
	$key = daw_api_key( 'kakao' );
	if ( '' === $key || '' === trim( (string) $query ) || daw_api_quota_out( 'kakao' ) ) {
		return null;
	}
	$b = daw_api_get_json(
		add_query_arg( array_map( 'rawurlencode', array( 'query' => $query, 'size' => (int) $size, 'sort' => 'accuracy' ) ), 'https://dapi.kakao.com/v2/search/' . rawurlencode( $kind ) ),
		array( 'Authorization' => 'KakaoAK ' . $key )
	);
	if ( null === $b ) {
		return null;
	}
	$out = array();
	foreach ( (array) ( $b['documents'] ?? array() ) as $d ) {
		$out[] = array_filter(
			array(
				'title'  => wp_strip_all_tags( html_entity_decode( (string) ( $d['title'] ?? '' ), ENT_QUOTES ) ),
				'url'    => (string) ( $d['url'] ?? '' ),
				'site'   => (string) ( $d['blogname'] ?? $d['cafename'] ?? $d['author'] ?? '' ),
				'date'   => substr( (string) ( $d['datetime'] ?? '' ), 0, 10 ),
				'snippet'=> mb_substr( wp_strip_all_tags( html_entity_decode( (string) ( $d['contents'] ?? '' ), ENT_QUOTES ) ), 0, 240 ),
			),
			'strlen'
		);
	}
	return $out;
}

/** One KOBIS call; its documented address is plain http, https is tried first. */
function daw_kobis_get( $path, array $args ) {
	foreach ( array( 'https', 'http' ) as $scheme ) {
		$b = daw_api_get_json( add_query_arg( array_map( 'rawurlencode', $args ), $scheme . '://www.kobis.or.kr/kobisopenapi/webservice/rest/' . $path ) );
		if ( null !== $b ) {
			return $b;
		}
	}
	return null;
}

/** KOBIS: the film's code by Korean title and year, or ''. Live, not stored. */
function daw_kobis_find( $title_ko, $year ) {
	$key = daw_api_key( 'kobis' );
	if ( '' === $key || '' === trim( (string) $title_ko ) ) {
		return '';
	}
	$args = array( 'key' => $key, 'movieNm' => $title_ko, 'itemPerPage' => 10 );
	if ( $year ) {
		$args['openStartDt'] = (int) $year - 1;
		$args['openEndDt']   = (int) $year + 1;
	}
	$b    = daw_kobis_get( 'movie/searchMovieList.json', $args );
	$list = (array) ( $b['movieListResult']['movieList'] ?? array() );
	$want = preg_replace( '/\s+/u', '', (string) $title_ko );
	$hits = array();
	foreach ( $list as $m ) {
		if ( preg_replace( '/\s+/u', '', (string) ( $m['movieNm'] ?? '' ) ) === $want ) {
			$hits[] = $m;
		}
	}
	return 1 === count( $hits ) ? array( (string) $hits[0]['movieCd'], (string) ( $hits[0]['openDt'] ?? '' ) ) : '';
}

/* ================================================================= store === */

/** YouTube and Kakao facts for one record. true, or null when a service did not answer. */
function daw_src_ext_sync( $post_id ) {
	$post_id = (int) $post_id;
	$ok      = true;

	$yt = daw_yt_id( (string) get_post_meta( $post_id, 'daw_trailer_youtube', true ) );
	if ( '' !== $yt && '' !== daw_api_key( 'youtube' ) ) {
		$v = daw_yt_video( $yt );
		if ( null === $v ) {
			$ok = false;
		} else {
			$v['at'] = gmdate( 'Y-m-d' );
			update_post_meta( $post_id, '_daw_src_yt', wp_slash( $v ) );
		}
	} elseif ( '' === $yt ) {
		delete_post_meta( $post_id, '_daw_src_yt' );
	}

	if ( '' !== daw_api_key( 'kakao' ) ) {
		$type   = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
		$person = in_array( $type, array( 'person', 'group' ), true );
		$native = trim( (string) get_post_meta( $post_id, $person ? 'daw_native_name' : 'daw_native_title', true ) );
		$q      = '' !== $native ? $native : get_the_title( $post_id );
		if ( ! $person && '' !== $native ) {
			$q .= 'film' === $type ? ' 영화' : ( 'variety' === $type ? ' 예능' : ' 드라마' );
		}
		$web  = daw_kakao_search( 'web', $q, 15 );
		$clip = daw_kakao_search( 'vclip', $q, 10 );
		$blog = daw_kakao_search( 'blog', $q, 5 );
		if ( null === $web ) {
			$ok = false;
		} else {
			update_post_meta(
				$post_id,
				'_daw_src_kakao',
				wp_slash(
					array(
						'q'     => $q,
						'at'    => gmdate( 'Y-m-d' ),
						'note'  => 'Search leads, not sources: open each page before citing it.',
						'web'   => $web,
						'vclip' => (array) $clip,
						'blog'  => (array) $blog,
					)
				)
			);
		}
	}

	if ( $ok ) {
		update_post_meta( $post_id, DAW_EXT_AT, time() );
		daw_ext_pending( 0, $post_id );
	} else {
		daw_ext_pending( $post_id );
	}
	return $ok ? true : null;
}

/* ================================================================= audit === */

/**
 * KOBIS check for a Korean film: asked live, only the verdict is kept
 * (KOBIS terms 6.2, 8.3.2). Rows for the audit.
 */
function daw_kobis_rules( $post_id ) {
	$post_id = (int) $post_id;
	if ( '' === daw_api_key( 'kobis' ) || 'film' !== ( function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '' ) ) {
		return array();
	}
	if ( 'KR' !== ( function_exists( 'daw_tmdb_country_code' ) ? daw_tmdb_country_code( $post_id ) : '' ) ) {
		return array();
	}
	$native = trim( (string) get_post_meta( $post_id, 'daw_native_title', true ) );
	$start  = (int) get_post_meta( $post_id, 'daw_aired_start', true );
	if ( '' === $native || ! $start ) {
		return array();
	}
	$cache = get_post_meta( $post_id, '_daw_kobis_v', true );
	$ours  = gmdate( 'Ymd', $start );
	if ( ! is_array( $cache ) || (int) ( $cache['at'] ?? 0 ) < time() - 30 * DAY_IN_SECONDS || ( $cache['ours'] ?? '' ) !== $ours ) {
		$hit = daw_kobis_find( $native, (int) gmdate( 'Y', $start ) );
		if ( '' === $hit ) {
			$v = 'none';
		} else {
			$v = ( '' !== $hit[1] && $hit[1] === $ours ) ? 'ok' : 'diff';
		}
		$cache = array( 'v' => $v, 'ours' => $ours, 'at' => time() );
		update_post_meta( $post_id, '_daw_kobis_v', $cache );
	}
	$map = array(
		'ok'   => array( 'ok', '' ),
		'diff' => array( 'no', 'KOBIS (KOFIC resmi gişe sistemi) vizyon tarihini farklı veriyor: tarih ikinci kaynakla yeniden doğrulanır (K4, K5)' ),
		'none' => array( 'wait', 'KOBIS\'te bu Korece adla ve yılla tek bir film bulunamadı' ),
	);
	$r = $map[ $cache['v'] ] ?? $map['none'];
	// The API search did not single the film out (more than one hit, or a
	// spacing difference in the title), but a person opened its KOBIS page
	// and wrote what it says in _daw_ok['kobis_check'] (1.75.62).
	if ( 'none' === ( $cache['v'] ?? 'none' ) ) {
		$okn = get_post_meta( $post_id, '_daw_ok', true );
		if ( is_array( $okn ) && ! empty( $okn['kobis_check'] ) && false !== strpos( (string) $okn['kobis_check'], 'kobis.or.kr' ) ) {
			$r = array( 'ok', 'Elle doğrulandı: ' . mb_substr( (string) $okn['kobis_check'], 0, 120 ) );
		}
	}
	return array( array( 'KOBIS kontrolü (madde 62)', $ours, $r[0], $r[1] ) );
}

/**
 * The trailer checked against what YouTube says about it (madde 42). Hide and
 * Seek (2013, Korean) carried the trailer of a 2014 British film of the same
 * name, uploaded in 2016 by its UK distributor: the title matched, the year
 * and the film did not. A confirmed exception is kept in _daw_ok['trailer'].
 */
function daw_trailer_rules( $post_id ) {
	$post_id = (int) $post_id;
	$id      = daw_yt_id( (string) get_post_meta( $post_id, 'daw_trailer_youtube', true ) );
	if ( '' === $id ) {
		return array();
	}
	$label = 'Fragman doğrulaması (madde 42)';
	$ok    = get_post_meta( $post_id, '_daw_ok', true );
	if ( is_array( $ok ) && ! empty( $ok['trailer'] ) ) {
		return array( array( $label, $id, 'ok', is_string( $ok['trailer'] ) ? $ok['trailer'] : '' ) );
	}
	$v = get_post_meta( $post_id, '_daw_src_yt', true );
	if ( ! is_array( $v ) || ( $v['id'] ?? '' ) !== $id ) {
		return array( array( $label, $id, 'wait', 'YouTube bilgisi henüz okunmadı ya da eski video için; kaynak deposu yenileyince bakılır' ) );
	}
	if ( empty( $v['found'] ) ) {
		return array( array( $label, $id, 'no', 'Video YouTube\'da yok (silinmiş ya da gizli): resmi kanaldan başka bir fragman bulunur ya da N/A' ) );
	}
	if ( 'public' !== ( $v['privacy'] ?? '' ) || empty( $v['embeddable'] ) ) {
		return array( array( $label, $id, 'no', 'Video herkese açık ya da gömülebilir değil (' . ( $v['privacy'] ?? '' ) . ')' ) );
	}
	$issues = array();
	$start  = (int) get_post_meta( $post_id, 'daw_aired_start', true );
	$pub    = (int) substr( (string) ( $v['published'] ?? '' ), 0, 4 );
	// A series runs over years: a season 3 trailer from 2025 belongs to a show
	// that began in 2020. The window is first air year to last (or now).
	$end = (int) get_post_meta( $post_id, 'daw_aired_end', true );
	$y0  = $start ? (int) gmdate( 'Y', $start ) : 0;
	$y1  = $end ? (int) gmdate( 'Y', $end ) : ( $y0 && in_array( (string) get_post_meta( $post_id, 'daw_status', true ), array( 'On air', 'Airing', 'Upcoming' ), true ) ? (int) gmdate( 'Y' ) : $y0 );
	if ( $y0 && $pub && ( $pub < $y0 - 2 || $pub > max( $y0, $y1 ) + 2 ) ) {
		$issues[] = sprintf( 'video %d yılında yüklenmiş, yapım %s: aynı adlı başka bir yapımın fragmanı olabilir', $pub, $y1 > $y0 ? $y0 . '-' . $y1 : (string) $y0 );
	}
	if ( $y0 && preg_match_all( '/\b(19[5-9]\d|20[0-4]\d)\b/', (string) ( $v['title'] ?? '' ), $ym ) ) {
		foreach ( array_unique( array_map( 'intval', $ym[1] ) ) as $y ) {
			if ( $y < $y0 - 1 || $y > max( $y0, $y1 ) + 1 ) {
				$issues[] = sprintf( 'video başlığı %d diyor, yapım %s', $y, $y1 > $y0 ? $y0 . '-' . $y1 : (string) $y0 );
			}
		}
	}
	// "Remarriage & Desires" on YouTube is "Remarriage and Desires" here, and an
	// alias stored as "&amp;" is the same ampersand.
	$norm   = static function ( $t ) {
		$t = str_replace( '&', ' and ', html_entity_decode( (string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( $t ) );
	};
	$vt     = $norm( $v['title'] ?? '' );
	$titles = array_filter( array( $norm( get_the_title( $post_id ) ), $norm( get_post_meta( $post_id, 'daw_native_title', true ) ) ) );
	foreach ( (array) get_post_meta( $post_id, 'daw_also_known_as', true ) as $aka ) {
		$titles[] = $norm( $aka );
	}
	$hit = false;
	foreach ( array_filter( $titles ) as $t ) {
		if ( false !== strpos( $vt, $t ) ) {
			$hit = true;
			break;
		}
	}
	if ( ! $hit ) {
		$issues[] = 'video başlığında yapımın adı geçmiyor';
	}
	$date = get_post_meta( $post_id, 'daw_trailer_date', true );
	$date = is_array( $date ) ? (string) reset( $date ) : (string) $date;
	// One day either way is the same upload seen from Seoul or from UTC.
	if ( '' !== $date && '' !== (string) ( $v['published'] ?? '' ) && abs( strtotime( substr( $date, 0, 10 ) ) - strtotime( substr( (string) $v['published'], 0, 10 ) ) ) > DAY_IN_SECONDS ) {
		$issues[] = 'Fragman tarihi alanı (' . substr( $date, 0, 10 ) . ') YouTube yükleme tarihiyle (' . substr( (string) $v['published'], 0, 10 ) . ') aynı değil';
	}
	if ( $issues ) {
		return array( array( $label, $id . ' · ' . (string) ( $v['channel'] ?? '' ), 'no', ucfirst( implode( '; ', $issues ) ) . '. Kanal ve video açıklamasıyla doğrula; doğruysa _daw_ok[\'trailer\'] yaz' ) );
	}
	return array( array( $label, $id . ' · ' . (string) ( $v['channel'] ?? '' ), 'ok', 'Kanal resmi mi: ' . (string) ( $v['channel'] ?? '' ) ) );
}

/* ================================================================= admin === */

add_action( 'admin_menu', 'daw_api_menu', 41 );
function daw_api_menu() {
	add_submenu_page( 'tools.php', 'DAW: API anahtarları', 'DAW: API anahtarları', 'manage_options', 'daw-api', 'daw_api_page' );
}

function daw_api_test( $service ) {
	switch ( $service ) {
		case 'youtube':
			$v = daw_yt_video( 'jNQXAC9IVRw' ); // the first video uploaded to YouTube
			return null === $v ? 'Hata: YouTube cevap vermedi ya da anahtarı kabul etmedi.' : 'Çalışıyor.';
		case 'kakao':
			$r = daw_kakao_search( 'web', '드라마', 1 );
			return null === $r ? 'Hata: Kakao cevap vermedi ya da anahtarı kabul etmedi.' : 'Çalışıyor.';
		case 'kobis':
			$b = daw_kobis_get( 'movie/searchMovieList.json', array( 'key' => daw_api_key( 'kobis' ), 'itemPerPage' => 1 ) );
			return ( null === $b || isset( $b['faultInfo'] ) ) ? 'Hata: KOBIS cevap vermedi ya da anahtarı kabul etmedi.' : 'Çalışıyor.';
	}
	return '';
}

function daw_api_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$services = daw_api_services();
	$msgs     = array();
	if ( isset( $_POST['daw_api_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['daw_api_nonce'] ), 'daw_api' ) ) {
		foreach ( $services as $slug => $s ) {
			$new = isset( $_POST[ 'key_' . $slug ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ 'key_' . $slug ] ) ) ) : '';
			if ( ! empty( $_POST[ 'del_' . $slug ] ) ) {
				delete_option( $s[1] );
				$msgs[] = $s[0] . ': silindi.';
			} elseif ( '' !== $new ) {
				if ( false === get_option( $s[1], false ) ) {
					add_option( $s[1], $new, '', 'no' );
				} else {
					update_option( $s[1], $new, false );
				}
				$msgs[] = $s[0] . ': kaydedildi. ' . daw_api_test( $slug );
			}
		}
		if ( isset( $_POST['daw_api_test'] ) ) {
			foreach ( $services as $slug => $s ) {
				if ( '' !== daw_api_key( $slug ) ) {
					$msgs[] = $s[0] . ': ' . daw_api_test( $slug );
				}
			}
		}
	}
	echo '<div class="wrap"><h1>DAW: API anahtarları</h1>';
	echo '<p>Anahtarlar yalnız kendi servislerine gönderilir, ekranda tam gösterilmez. Boş bırakılan alan mevcut anahtarı korur. TMDB anahtarı Ayarlar › Okuma sayfasındadır.</p>';
	foreach ( $msgs as $m ) {
		printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( $m ) );
	}
	echo '<form method="post" autocomplete="off">';
	wp_nonce_field( 'daw_api', 'daw_api_nonce' );
	echo '<table class="form-table" role="presentation">';
	foreach ( $services as $slug => $s ) {
		$k   = daw_api_key( $slug );
		$now = '' === $k ? 'kayıtlı değil' : 'kayıtlı, sonu …' . substr( $k, -4 );
		printf(
			'<tr><th scope="row"><label for="key_%1$s">%2$s</label></th><td><input type="password" id="key_%1$s" name="key_%1$s" class="regular-text" value="" autocomplete="new-password" placeholder="%3$s"> <label><input type="checkbox" name="del_%1$s" value="1"> sil</label><p class="description">%4$s</p></td></tr>',
			esc_attr( $slug ),
			esc_html( $s[0] ),
			esc_attr( $now ),
			esc_html( $s[2] )
		);
	}
	echo '</table>';
	submit_button( 'Kaydet ve dene', 'primary', 'submit', false );
	echo ' ';
	submit_button( 'Kayıtlıları dene', 'secondary', 'daw_api_test', false );
	echo '</form></div>';
}
