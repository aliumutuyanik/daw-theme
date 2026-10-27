<?php
/**
 * Streaming platform identity — logos, names, colours.
 *
 * WHY THIS EXISTS
 * ---------------
 * "Where to watch" is the row a reader actually clicks, and a row with a real
 * Netflix or Viki mark reads as a service; a row with a grey letter reads as a
 * placeholder. Asking an editor to upload a logo for every platform on every
 * one of 926 records is a job nobody finishes, so the site fetches the mark
 * itself, once per domain, and keeps it.
 *
 * THREE RULES IT FOLLOWS
 * ----------------------
 * 1. Fetch once, host locally. Hot-linking someone's favicon, or routing it
 *    through a third-party favicon service, hands every reader's ip to a
 *    server we do not control and breaks the day that server does. We download
 *    the icon into the media library and serve it from our own domain.
 * 2. Never shift. The tile is a fixed box whether it holds a logo, a monogram
 *    or nothing at all, so a record that gains its logo an hour after
 *    publication does not move a single pixel of the page.
 * 3. Never block a page view. The fetch happens on cron, after the response
 *    has already been sent. The first visitor sees the monogram; everyone
 *    after sees the logo.
 *
 * FAIR USE
 * --------
 * A platform's mark is used here to identify that platform as the source of a
 * legal stream, at icon size, next to a link to it. That is nominative use —
 * the same basis on which every "watch on" row on the web works.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_PLATFORM_OPTION = 'daw_platform_icons';
const DAW_PLATFORM_HOOK   = 'daw_fetch_platform_icon';
const DAW_PLATFORM_RETRY  = 7 * DAY_IN_SECONDS;

/**
 * The domain is the identity. Two records may write "Netflix", "netflix" and
 * "Netflix Korea" as the platform name, but they all link to netflix.com — so
 * the host, not the typed label, is what we key logos on.
 */
function daw_platform_domain( $url ) {
	$host = wp_parse_url( (string) $url, PHP_URL_HOST );
	if ( ! $host ) {
		return '';
	}
	$host = strtolower( $host );
	$host = preg_replace( '/^(www|m|watch|play)\./', '', $host );

	return $host;
}

/**
 * The platforms Asian titles actually stream on, with the colour their own
 * brand uses. The colour is only ever a fallback tile behind a letter, so a
 * near-enough value is fine; what matters is that Netflix is not the same
 * grey as Viu.
 *
 * A domain missing from this table still works — it gets a neutral tile and,
 * once cron has run, its real logo. The table exists so the *first* paint of
 * a known platform already looks deliberate.
 */
function daw_platform_brands() {
	static $brands = null;
	if ( null !== $brands ) {
		return $brands;
	}

	$brands = array(
		'netflix.com'      => array( 'Netflix', '#E50914' ),
		'viki.com'         => array( 'Rakuten Viki', '#1FBDD4' ),
		'rakuten.tv'       => array( 'Rakuten TV', '#BF0000' ),
		'viu.com'          => array( 'Viu', '#FFCC00' ),
		'disneyplus.com'   => array( 'Disney+', '#0C204C' ),
		'hotstar.com'      => array( 'Disney+ Hotstar', '#0C204C' ),
		'primevideo.com'   => array( 'Prime Video', '#00A8E1' ),
		'amazon.com'       => array( 'Prime Video', '#00A8E1' ),
		'iq.com'           => array( 'iQIYI', '#00BE06' ),
		'iqiyi.com'        => array( 'iQIYI', '#00BE06' ),
		'wetv.vip'         => array( 'WeTV', '#FF7300' ),
		'youku.tv'         => array( 'Youku', '#1D8CE0' ),
		'bilibili.tv'      => array( 'Bilibili', '#00A1D6' ),
		'hulu.com'         => array( 'Hulu', '#1CE783' ),
		'tv.apple.com'     => array( 'Apple TV+', '#1C1D21' ),
		'crunchyroll.com'  => array( 'Crunchyroll', '#F47521' ),
		'coupangplay.com'  => array( 'Coupang Play', '#0074E4' ),
		'wavve.com'        => array( 'Wavve', '#1351F9' ),
		'tving.com'        => array( 'TVING', '#FF153C' ),
		'watcha.com'       => array( 'Watcha', '#FF0558' ),
		'kocowa.com'       => array( 'KOCOWA', '#2B37AC' ),
		'youtube.com'      => array( 'YouTube', '#FF0000' ),
		'max.com'          => array( 'HBO Max', '#0046FF' ),
		'paramountplus.com' => array( 'Paramount+', '#0064FF' ),
		'peacocktv.com'    => array( 'Peacock', '#000000' ),
		'tubitv.com'       => array( 'Tubi', '#FBC500' ),
		'plex.tv'          => array( 'Plex', '#E5A00D' ),
		'gagaoolala.com'   => array( 'GagaOOLala', '#E5004F' ),
		'trueid.net'       => array( 'TrueID', '#D2232A' ),
		'vidio.com'        => array( 'Vidio', '#00B4DB' ),
		'catchplay.com'    => array( 'CATCHPLAY+', '#00C4B3' ),
		'mubi.com'         => array( 'MUBI', '#001489' ),
		'criterionchannel.com' => array( 'The Criterion Channel', '#00205B' ),
	);

	/**
	 * A site can teach the map a platform we have not met.
	 *
	 * @param array $brands domain => array( label, hex ).
	 */
	$brands = apply_filters( 'daw_platform_brands', $brands );

	return $brands;
}

/**
 * The tidy name for a platform, preferring the brand table over whatever was
 * typed into the field — so "netflix kore" renders as Netflix.
 */
function daw_platform_label( $url, $fallback = '' ) {
	$brands = daw_platform_brands();
	$domain = daw_platform_domain( $url );

	if ( isset( $brands[ $domain ] ) ) {
		return $brands[ $domain ][0];
	}

	$fallback = trim( (string) $fallback );
	if ( '' !== $fallback ) {
		return $fallback;
	}

	return $domain;
}

function daw_platform_color( $url ) {
	$brands = daw_platform_brands();
	$domain = daw_platform_domain( $url );

	return isset( $brands[ $domain ] ) ? $brands[ $domain ][1] : '#6B655B';
}

/* ================================================================ storage == */

function daw_platform_store() {
	$store = get_option( DAW_PLATFORM_OPTION, array() );

	return is_array( $store ) ? $store : array();
}

/**
 * The local url of a platform's icon, or '' if we do not have one yet. Asking
 * for an icon we do not have is what queues the fetch — one line at the call
 * site, no admin screen anyone has to remember to visit.
 */
function daw_platform_icon_url( $url ) {
	$domain = daw_platform_domain( $url );
	if ( ! $domain ) {
		return '';
	}

	$store = daw_platform_store();

	if ( ! empty( $store[ $domain ]['url'] ) ) {
		return $store[ $domain ]['url'];
	}

	// A domain that failed recently is left alone: a dead favicon url does not
	// come back to life because 900 records asked for it.
	$last = isset( $store[ $domain ]['checked'] ) ? (int) $store[ $domain ]['checked'] : 0;
	if ( $last && ( time() - $last ) < DAW_PLATFORM_RETRY ) {
		return '';
	}

	daw_queue_platform_icon( $domain );

	return '';
}

function daw_queue_platform_icon( $domain ) {
	if ( ! $domain || get_transient( 'daw_pf_' . md5( $domain ) ) ) {
		return;
	}
	set_transient( 'daw_pf_' . md5( $domain ), 1, HOUR_IN_SECONDS );

	if ( ! wp_next_scheduled( DAW_PLATFORM_HOOK, array( $domain ) ) ) {
		wp_schedule_single_event( time() + 30, DAW_PLATFORM_HOOK, array( $domain ) );
	}
}

/* ================================================================== fetch == */

add_action( DAW_PLATFORM_HOOK, 'daw_fetch_platform_icon', 10, 1 );

/**
 * Find the best icon a platform publishes about itself and keep a copy.
 *
 * Order of preference is deliberate: apple-touch-icon first because it is the
 * one icon every service keeps sharp and square, then any declared icon with a
 * usable size, then the /favicon.ico nobody maintains but everybody has.
 */
function daw_fetch_platform_icon( $domain ) {
	$domain = daw_platform_domain( 'https://' . ltrim( (string) $domain, '/' ) );
	if ( ! $domain ) {
		return;
	}

	$store                        = daw_platform_store();
	$store[ $domain ]['checked']  = time();
	$store[ $domain ]['attempts'] = isset( $store[ $domain ]['attempts'] ) ? (int) $store[ $domain ]['attempts'] + 1 : 1;
	update_option( DAW_PLATFORM_OPTION, $store, false );

	$candidates = daw_platform_icon_candidates( $domain );

	foreach ( $candidates as $candidate ) {
		$id = daw_sideload_platform_icon( $candidate, $domain );
		if ( $id ) {
			$store                       = daw_platform_store();
			$store[ $domain ]['id']      = $id;
			$store[ $domain ]['url']     = wp_get_attachment_url( $id );
			$store[ $domain ]['checked'] = time();
			$store[ $domain ]['source']  = $candidate;
			update_option( DAW_PLATFORM_OPTION, $store, false );

			return $id;
		}
	}

	return 0;
}

function daw_platform_icon_candidates( $domain ) {
	$home = 'https://' . $domain . '/';
	$out  = array();

	$res = wp_remote_get(
		$home,
		array(
			'timeout'     => 12,
			'redirection' => 4,
			'user-agent'  => 'Mozilla/5.0 (compatible; DiscoverAsianWorld/1.0; +https://discoverasianworld.com)',
		)
	);

	if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
		$html = wp_remote_retrieve_body( $res );
		$head = substr( $html, 0, 120000 );

		if ( preg_match_all( '#<link[^>]+>#i', $head, $links ) ) {
			$found = array();

			foreach ( $links[0] as $tag ) {
				if ( ! preg_match( '#rel=["\']([^"\']*icon[^"\']*)["\']#i', $tag, $rel ) ) {
					continue;
				}
				if ( ! preg_match( '#href=["\']([^"\']+)["\']#i', $tag, $href ) ) {
					continue;
				}

				$rel_v = strtolower( $rel[1] );
				$size  = 0;
				if ( preg_match( '#sizes=["\'](\d+)x(\d+)["\']#i', $tag, $sz ) ) {
					$size = (int) $sz[1];
				}

				// apple-touch-icon is the square, opaque, well-maintained one.
				$score = ( false !== strpos( $rel_v, 'apple' ) ) ? 1000 : 0;
				$score += $size;
				if ( preg_match( '#\.svg(\?|$)#i', $href[1] ) ) {
					$score += 300; // sharp at every size, tiny on the wire.
				}

				$found[] = array( $score, daw_platform_abs_url( $href[1], $home ) );
			}

			usort(
				$found,
				static function ( $a, $b ) {
					return $b[0] <=> $a[0];
				}
			);

			foreach ( array_slice( $found, 0, 4 ) as $row ) {
				if ( $row[1] ) {
					$out[] = $row[1];
				}
			}
		}
	}

	$out[] = $home . 'apple-touch-icon.png';
	$out[] = $home . 'favicon.ico';

	return array_values( array_unique( $out ) );
}

function daw_platform_abs_url( $href, $base ) {
	$href = trim( html_entity_decode( $href, ENT_QUOTES ) );
	if ( '' === $href || 0 === strpos( $href, 'data:' ) ) {
		return '';
	}
	if ( 0 === strpos( $href, '//' ) ) {
		return 'https:' . $href;
	}
	if ( preg_match( '#^https?://#i', $href ) ) {
		return $href;
	}

	return rtrim( $base, '/' ) . '/' . ltrim( $href, '/' );
}

/**
 * Download one candidate and put it in the media library, or return 0 and let
 * the caller try the next. Nothing here trusts the remote server: the file is
 * checked for being an image before it is ever stored.
 */
function daw_sideload_platform_icon( $url, $domain ) {
	if ( ! $url ) {
		return 0;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = download_url( $url, 15 );
	if ( is_wp_error( $tmp ) ) {
		return 0;
	}

	$type = wp_check_filetype( $url );
	$ext  = $type['ext'] ? $type['ext'] : 'png';

	// An .ico is an image to a browser but not to WordPress, and a 1x1 tracking
	// pixel is an image to both. Both get rejected here rather than later.
	$size = @getimagesize( $tmp );
	$is_svg = ( 'svg' === strtolower( $ext ) );

	if ( ! $is_svg ) {
		if ( ! $size || $size[0] < 16 || $size[1] < 16 ) {
			@unlink( $tmp );
			return 0;
		}
		$mime = isset( $size['mime'] ) ? $size['mime'] : '';
		$map  = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		if ( ! isset( $map[ $mime ] ) ) {
			@unlink( $tmp );
			return 0;
		}
		$ext = $map[ $mime ];
	} else {
		// We do not sideload svg: WordPress refuses the mime type by default and
		// loosening that for a third-party file is a bad trade for 2 KB.
		@unlink( $tmp );
		return 0;
	}

	$file = array(
		'name'     => 'platform-' . sanitize_key( str_replace( '.', '-', $domain ) ) . '.' . $ext,
		'tmp_name' => $tmp,
	);

	$id = media_handle_sideload( $file, 0, sprintf( 'Logo — %s', $domain ) );

	if ( is_wp_error( $id ) ) {
		@unlink( $tmp );
		return 0;
	}

	update_post_meta( $id, '_daw_platform_domain', $domain );
	update_post_meta( $id, '_wp_attachment_image_alt', sprintf( '%s logo', daw_platform_label( 'https://' . $domain ) ) );

	return (int) $id;
}

/* ================================================================= render == */

/**
 * The tile. Always 32×32 in the markup, always the same box on screen, whether
 * it ends up holding a fetched logo or the first letter of the platform name.
 * That is the whole reason the size is written into the html and not left to
 * the stylesheet to discover.
 */
function daw_platform_mark( $url, $label = '', $px = 32 ) {
	$icon  = daw_platform_icon_url( $url );
	$label = daw_platform_label( $url, $label );

	if ( $icon ) {
		return sprintf(
			'<img class="daw-watch-mark daw-watch-mark--logo" src="%s" alt="" width="%d" height="%d" loading="lazy" decoding="async">',
			esc_url( $icon ),
			(int) $px,
			(int) $px
		);
	}

	return sprintf(
		'<span class="daw-watch-mark" style="--pf:%s" aria-hidden="true">%s</span>',
		esc_attr( daw_platform_color( $url ) ),
		esc_html( mb_strtoupper( mb_substr( $label, 0, 1 ) ) )
	);
}

/* ================================================================== admin == */

/**
 * One screen, one button: fetch every platform logo the site links to. Useful
 * after an import, and the only way to see at a glance which domains answered.
 */
add_action( 'admin_menu', 'daw_platform_admin_menu' );
function daw_platform_admin_menu() {
	add_submenu_page(
		'tools.php',
		__( 'Platform logos', 'daw' ),
		__( 'Platform logos', 'daw' ),
		'manage_options',
		'daw-platforms',
		'daw_platform_admin_page'
	);
}

function daw_platform_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$store = daw_platform_store();

	if ( isset( $_POST['daw_pf_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['daw_pf_nonce'] ), 'daw_pf' ) ) {
		$domains = daw_platform_known_domains();
		$done    = 0;

		foreach ( $domains as $domain ) {
			if ( ! empty( $store[ $domain ]['url'] ) && empty( $_POST['daw_pf_force'] ) ) {
				continue;
			}
			if ( daw_fetch_platform_icon( $domain ) ) {
				$done++;
			}
		}

		$store = daw_platform_store();
		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			esc_html( sprintf( 'Fetched %d logo(s) across %d linked platform(s).', $done, count( $domains ) ) )
		);
	}

	$domains = daw_platform_known_domains();

	echo '<div class="wrap"><h1>Platform logos</h1>';
	echo '<p>Every domain the records link to under <strong>Where to watch</strong>. Logos are downloaded once and served from this site — nothing is hot-linked.</p>';

	echo '<form method="post">';
	wp_nonce_field( 'daw_pf', 'daw_pf_nonce' );
	echo '<p><label><input type="checkbox" name="daw_pf_force" value="1"> Re-fetch logos we already have</label></p>';
	submit_button( 'Fetch missing logos' );
	echo '</form>';

	echo '<table class="widefat striped"><thead><tr><th>Logo</th><th>Domain</th><th>Name</th><th>Status</th></tr></thead><tbody>';
	foreach ( $domains as $domain ) {
		$row = isset( $store[ $domain ] ) ? $store[ $domain ] : array();
		echo '<tr>';
		echo '<td>' . ( ! empty( $row['url'] ) ? '<img src="' . esc_url( $row['url'] ) . '" width="28" height="28" alt="">' : '—' ) . '</td>';
		echo '<td><code>' . esc_html( $domain ) . '</code></td>';
		echo '<td>' . esc_html( daw_platform_label( 'https://' . $domain ) ) . '</td>';
		if ( ! empty( $row['url'] ) ) {
			echo '<td style="color:#1a7f37">stored</td>';
		} elseif ( ! empty( $row['attempts'] ) ) {
			echo '<td style="color:#a9741a">no icon found (' . (int) $row['attempts'] . ' attempt(s))</td>';
		} else {
			echo '<td style="color:#6b655b">not fetched yet</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * Every domain that appears in a where-to-watch field anywhere on the site,
 * plus the brands we know by name. Read straight from post meta so it reflects
 * what editors actually linked, not what we guessed they would.
 */
function daw_platform_known_domains() {
	global $wpdb;

	$rows = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
			'daw_watch_url'
		)
	);

	$domains = array();

	foreach ( (array) $rows as $row ) {
		$values = maybe_unserialize( $row );
		foreach ( (array) $values as $value ) {
			$domain = daw_platform_domain( $value );
			if ( $domain ) {
				$domains[ $domain ] = true;
			}
		}
	}

	foreach ( array_keys( daw_platform_brands() ) as $domain ) {
		$domains[ $domain ] = true;
	}

	$domains = array_keys( $domains );
	sort( $domains );

	return $domains;
}
