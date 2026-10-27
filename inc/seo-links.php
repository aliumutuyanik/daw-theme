<?php
/**
 * Discover Asian World: the link audit.
 *
 * Two questions, asked of every published text on the site:
 *
 * 1. Does any link inside it point at an address on this site that does not
 *    exist, or that only works because a redirect catches it? A redirected
 *    link costs the reader a hop and the page some of the weight it passes on.
 * 2. Does it name a drama, film, person, group, food, myth or place the site
 *    has a page for, without linking to it (rule 3.5)?
 *
 * It runs in the background, forty texts an hour, and once on every save of a
 * published text, and keeps a result only where something was found.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_LK_META  = '_daw_links';
const DAW_LK_BATCH = 40;

/** Record types whose names are worth finding in text. */
function daw_lk_entity_types() {
	return array( 'drama', 'film', 'variety', 'anime', 'person', 'group', 'food', 'culture', 'myth', 'travel' );
}

/**
 * The site's names and addresses, built once a day.
 *
 * names: the name as written => post id. A guide called "Jin Ramen: Ottogi's
 *        mild and spicy noodles" is known by the part before the colon.
 * paths: every published address => post id, for checking links.
 */
function daw_lk_dictionary() {
	$cached = get_transient( 'daw_lk_dict' );

	if ( is_array( $cached ) && isset( $cached['names'], $cached['paths'] ) ) {
		return $cached;
	}

	global $wpdb;

	$rows = $wpdb->get_results( "SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page')" ); // phpcs:ignore WordPress.DB
	$ids  = wp_list_pluck( (array) $rows, 'ID' );

	if ( $ids ) {
		_prime_post_caches( array_map( 'intval', $ids ), true, false );
	}

	$names = array();
	$paths = array();
	$types = array_flip( daw_lk_entity_types() );

	foreach ( (array) $rows as $row ) {
		$id   = (int) $row->ID;
		$link = get_permalink( $id );

		if ( $link ) {
			$paths[ daw_lk_path( $link ) ] = $id;
		}

		if ( 'post' !== $row->post_type || ! function_exists( 'daw_get_type' ) ) {
			continue;
		}

		$type = daw_get_type( $id );
		if ( ! isset( $types[ $type ] ) ) {
			continue;
		}

		$name = trim( html_entity_decode( wp_strip_all_tags( (string) $row->post_title ), ENT_QUOTES, 'UTF-8' ) );
		$name = trim( (string) preg_replace( '/\s*[:(].*$/u', '', $name ) );

		if ( ! daw_lk_name_usable( $name ) ) {
			continue;
		}

		// Two records with one name ("Mother", the film and the drama) cannot
		// be told apart in running text, so neither is suggested.
		$names[ $name ] = isset( $names[ $name ] ) ? 0 : $id;
	}

	foreach ( get_categories( array( 'hide_empty' => false ) ) as $cat ) {
		$paths[ daw_lk_path( get_category_link( $cat ) ) ] = -1;
	}

	$dict = array(
		'names' => array_filter( $names ),
		'paths' => $paths,
	);

	set_transient( 'daw_lk_dict', $dict, DAY_IN_SECONDS );

	return $dict;
}

/**
 * A name is worth looking for when it cannot easily be an ordinary word:
 * two words or more, or one long capitalised word.
 */
function daw_lk_name_usable( $name ) {
	if ( mb_strlen( $name ) < 4 || ! preg_match( '/^\p{Lu}/u', $name ) ) {
		return false;
	}

	if ( false === strpos( $name, ' ' ) ) {
		return mb_strlen( $name ) >= 6;
	}

	return true;
}

function daw_lk_path( $url ) {
	$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );

	return strtolower( '/' . trim( rawurldecode( $path ), '/' ) . ( '' === trim( $path, '/' ) ? '' : '/' ) );
}

/**
 * Check one text.
 *
 * @return array{broken: array, redirected: array, unlinked: array}
 */
function daw_lk_check( $post_id ) {
	$post   = get_post( $post_id );
	$result = array(
		'broken'     => array(),
		'redirected' => array(),
		'unlinked'   => array(),
	);

	if ( ! $post || 'publish' !== $post->post_status ) {
		return $result;
	}

	$html = (string) $post->post_content;

	if ( strlen( wp_strip_all_tags( $html ) ) < 200 ) {
		return $result;
	}

	$dict   = daw_lk_dictionary();
	$host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$linked = array();

	if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $m ) ) {
		foreach ( array_unique( $m[1] ) as $href ) {
			$href = html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );
			$h    = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );

			if ( '' !== $h && $h !== $host && 'www.' . $host !== $h ) {
				continue;
			}
			if ( '' === $h && 0 !== strpos( $href, '/' ) ) {
				continue; // #anchor, mailto:, tel:.
			}

			$path = daw_lk_path( $href );

			if ( '/' === $path || 0 === strpos( $path, '/wp-content/' ) || 0 === strpos( $path, '/wp-json/' ) ) {
				continue;
			}

			if ( isset( $dict['paths'][ $path ] ) ) {
				$linked[ $dict['paths'][ $path ] ] = true;
				continue;
			}

			// The repair asked the site and got a page (a list, a filtered
			// view, a custom route): not broken, whatever url_to_postid says.
			$live = (array) get_option( 'daw_lk_live_paths', array() );
			if ( isset( $live[ $path ] ) && time() - (int) $live[ $path ] < 30 * DAY_IN_SECONDS ) {
				continue;
			}

			$redirect = daw_lk_redirect_for( $path );
			if ( $redirect ) {
				$result['redirected'][] = array( $href, $redirect );
				continue;
			}

			$id = url_to_postid( home_url( $path ) );
			if ( $id && 'publish' === get_post_status( $id ) ) {
				$linked[ $id ] = true;
				continue;
			}

			$result['broken'][] = $href;
		}
	}

	// Names in the text, outside any link.
	$text = preg_replace( '#<a\s[^>]*>.*?</a>#is', ' ', $html );
	$text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( (string) $text ) ), ENT_QUOTES, 'UTF-8' );

	$names = $dict['names'];
	unset( $names[ trim( (string) preg_replace( '/\s*[:(].*$/u', '', daw_eng_plain( $post->post_title ) ) ) ] );

	$found = array();
	foreach ( array_chunk( array_keys( $names ), 150 ) as $chunk ) {
		$re = '/(?<![\p{L}\p{N}])(' . implode( '|', array_map( static function ( $n ) {
			return preg_quote( $n, '/' );
		}, $chunk ) ) . ')(?![\p{L}\p{N}])/u';

		if ( preg_match_all( $re, $text, $mm ) ) {
			foreach ( $mm[1] as $name ) {
				$id = (int) $names[ $name ];
				if ( $id && $id !== (int) $post_id && ! isset( $linked[ $id ] ) ) {
					$found[ $id ] = $name;
				}
			}
		}
	}

	foreach ( array_slice( $found, 0, 25, true ) as $id => $name ) {
		$result['unlinked'][] = array( $id, $name );
	}

	return $result;
}

/** Where a redirect would send this path, if one would. */
function daw_lk_redirect_for( $path ) {
	if ( function_exists( 'daw_get_redirects' ) ) {
		$map = daw_get_redirects();
		if ( isset( $map[ $path ] ) ) {
			return home_url( $map[ $path ] );
		}
	}

	if ( function_exists( 'daw_rd_tables_ready' ) && daw_rd_tables_ready() ) {
		global $wpdb;
		$target = $wpdb->get_var( $wpdb->prepare( 'SELECT target FROM ' . daw_rd_table() . " WHERE status = 'active' AND match_type = 'exact' AND source = %s LIMIT 1", daw_rd_norm( $path ) ) ); // phpcs:ignore WordPress.DB
		if ( $target ) {
			return (string) $target;
		}
	}

	return '';
}

/** Check and file the result; nothing is stored for a clean text. */
function daw_lk_scan( $post_id ) {
	$r = daw_lk_check( $post_id );

	if ( $r['broken'] || $r['redirected'] || $r['unlinked'] ) {
		$r['at'] = time();
		update_post_meta( $post_id, DAW_LK_META, $r );
	} else {
		delete_post_meta( $post_id, DAW_LK_META );
	}

	return $r;
}

/* ================================================================ runs === */

add_action( 'init', 'daw_lk_schedule' );
function daw_lk_schedule() {
	if ( ! wp_next_scheduled( 'daw_lk_tick' ) ) {
		wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'hourly', 'daw_lk_tick' );
	}
}

/** Forty texts per hour, then a week's rest after a full pass. */
add_action( 'daw_lk_tick', 'daw_lk_tick_run' );
function daw_lk_tick_run() {
	$state = (array) get_option( 'daw_lk_state', array( 'cursor' => 0, 'pass_end' => 0 ) );

	if ( ! empty( $state['pass_end'] ) && time() - (int) $state['pass_end'] < WEEK_IN_SECONDS && empty( $state['cursor'] ) ) {
		return;
	}

	global $wpdb;

	$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page') AND ID > %d ORDER BY ID ASC LIMIT %d",
			(int) $state['cursor'],
			DAW_LK_BATCH
		)
	);

	foreach ( $ids as $id ) {
		daw_lk_scan( (int) $id );
	}

	if ( count( $ids ) < DAW_LK_BATCH ) {
		$state = array( 'cursor' => 0, 'pass_end' => time() );
	} else {
		$state['cursor'] = (int) end( $ids );
	}

	update_option( 'daw_lk_state', $state, false );

	// "Run every check" (control centre) asks for a whole pass now: the next
	// batch follows in ninety seconds instead of an hour, until the end.
	if ( ! empty( $state['fast'] ) && ! empty( $state['cursor'] ) ) {
		wp_schedule_single_event( time() + 90, 'daw_lk_tick' );
	}
}

/** A saved published text is checked straight away. */
add_action( 'save_post', 'daw_lk_on_save', 30, 2 );
function daw_lk_on_save( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}

	daw_lk_scan( $post_id );
}

/** A new or renamed record changes the dictionary. */
add_action( 'transition_post_status', 'daw_lk_dict_stale', 10, 3 );
function daw_lk_dict_stale( $new, $old, $post ) {
	// Only a page appearing or disappearing. An edit to a live page keeps its
	// name and address nearly always, and rebuilding the whole dictionary on
	// every save of a fifty-record batch would make each save slow.
	if ( $new !== $old && ( 'publish' === $new || 'publish' === $old ) && in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		delete_transient( 'daw_lk_dict' );
	}
}
