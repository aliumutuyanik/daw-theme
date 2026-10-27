<?php
/**
 * News to record linking, in both directions, derived rather than typed.
 *
 * WHY THIS IS NOT A FIELD SOMEONE FILLS IN
 * ----------------------------------------
 * A story about Seo Kang Joon that names Undercover High School has to reach
 * both of those records, and both of those records have to show the story. Ask
 * an editor to remember that on every save and it will be right on the day it
 * was written and wrong for ever afterwards. So the link is read out of the
 * story itself: the names are already in the prose, which is the one place
 * they cannot be forgotten.
 *
 * HOW A MATCH IS DECIDED
 * ----------------------
 * Two passes, both deliberately narrow, because a wrong link is worse than a
 * missing one.
 *
 *   1. Anything the writer set in italics is looked up against the drama, film
 *      and variety records. House style already italicises a title, so <em>Grid
 *      </em> resolves to the drama and the sentence "the grid went down" does
 *      not.
 *   2. Record names of two or more words are matched anywhere in the text, on
 *      word boundaries. "Seo Kang Joon" is not a phrase that turns up by
 *      accident. A one-word record name is never matched this way, which is
 *      why IU and Rain have to be added by hand when they matter.
 *
 * Hand-entered mentions are never dropped. The derived set is added to them.
 *
 * WHAT USES THE RESULT
 * --------------------
 * The flat index _daw_mentions_idx is what a record reads to find the stories
 * that name it, so writing it is what closes the loop. The same list drives the
 * cards at the foot of the story, and the first mention of each record in the
 * body is turned into a link on output, so the reader gets there in one click
 * without anyone typing an href.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_MENTION_IDX  = '_daw_mentions_idx';
const DAW_TITLE_IDX_TTL = DAY_IN_SECONDS;

/** Record kinds a story is allowed to point at. */
function daw_linkable_types() {
	return array( 'drama', 'film', 'variety', 'person', 'group' );
}

/**
 * name (lowercased, punctuation folded) => post id, for every linkable record.
 *
 * Built once a day and thrown away whenever a record is saved or renamed,
 * because nine hundred titles is a query worth doing once and not on every
 * save of every story.
 *
 * @return array
 */
function daw_title_index() {
	$cached = get_transient( 'daw_title_idx_' . DAW_VERSION );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$rows = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => daw_linkable_types(),
				),
			),
		)
	);

	$index = array();

	foreach ( $rows as $id ) {
		$names = array( array( get_the_title( $id ), false ) );

		// Alternate names are kept, but marked: they only count when the text
		// itself marks them as a title (italics). See daw_scan_for_records().
		foreach ( (array) daw_field_raw( $id, 'daw_also_known_as' ) as $aka ) {
			$names[] = array( $aka, true );
		}

		foreach ( $names as $pair ) {
			list( $name, $alias ) = $pair;
			$key = daw_fold_title( $name );
			if ( '' === $key || isset( $index[ $key ] ) ) {
				continue;
			}
			$index[ $key ] = array(
				'id'    => (int) $id,
				'type'  => daw_get_type( $id ),
				'name'  => html_entity_decode( wp_strip_all_tags( (string) $name ), ENT_QUOTES, 'UTF-8' ),
				'words' => count( preg_split( '/\s+/', trim( (string) $name ) ) ),
				'alias' => (bool) $alias,
			);
		}
	}

	set_transient( 'daw_title_idx_' . DAW_VERSION, $index, DAW_TITLE_IDX_TTL );

	return $index;
}

/**
 * One spelling for comparison: lowercased, curly quotes straightened, every
 * run of punctuation and space collapsed to a single space.
 */
function daw_fold_title( $name ) {
	$name = wp_strip_all_tags( html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) );
	$name = str_replace( array( '’', '‘', '“', '”' ), array( "'", "'", '"', '"' ), $name );
	$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
	$name = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $name );

	return trim( (string) $name );
}

add_action( 'save_post_post', 'daw_flush_title_index', 5 );
add_action( 'deleted_post', 'daw_flush_title_index' );
function daw_flush_title_index() {
	delete_transient( 'daw_title_idx_' . DAW_VERSION );
}

/**
 * Every record this story points at: what the editor chose, plus what the
 * prose already says.
 *
 * @param int         $post_id
 * @param string|null $content Pass the content being saved; defaults to stored.
 * @return int[]
 */
function daw_news_mentions( $post_id, $content = null ) {
	$post_id = (int) $post_id;
	// One row per id (the meta box) or one row holding the whole list (REST,
	// MCP). Reading rows only turned a stored list into a bare 1 (1.75.86).
	$manual = array();
	foreach ( (array) get_post_meta( $post_id, 'daw_mentions', false ) as $row ) {
		$row    = is_string( $row ) && '[' === substr( ltrim( $row ), 0, 1 ) ? json_decode( $row, true ) : $row;
		$manual = array_merge( $manual, is_array( $row ) ? $row : array( $row ) );
	}
	$manual = array_filter( array_map( 'intval', $manual ) );

	if ( null === $content ) {
		$post    = get_post( $post_id );
		$content = $post ? $post->post_content : '';
	}

	// The searchable surface of the story: the body, the sentence under the
	// headline and the dated rows, because a name can appear in any of them.
	$haystack = (string) $content . ' '
		. (string) daw_field_raw( $post_id, 'daw_news_standfirst' ) . ' '
		. (string) daw_field_raw( $post_id, 'daw_news_confirmed' ) . ' '
		. implode( ' ', (array) daw_field_raw( $post_id, 'daw_news_tl_event' ) );

	$found = daw_scan_for_records( $haystack );

	$all = array_values( array_unique( array_merge( $manual, $found ) ) );

	// A story never points at itself.
	return array_values( array_diff( $all, array( $post_id ) ) );
}

/**
 * The two passes described at the top of this file.
 *
 * @param string $html
 * @return int[]
 */
function daw_scan_for_records( $html ) {
	$index = daw_title_index();
	if ( ! $index ) {
		return array();
	}

	$hits = array();

	// Pass one: italics resolve against title records.
	if ( preg_match_all( '/<(?:em|i|cite)\b[^>]*>(.*?)<\/(?:em|i|cite)>/is', $html, $m ) ) {
		foreach ( $m[1] as $inner ) {
			$key = daw_fold_title( $inner );
			if ( isset( $index[ $key ] ) ) {
				$hits[] = $index[ $key ]['id'];
			}
		}
	}

	// Pass two: record names in running text.
	//
	// WHY THIS IS STRICTER THAN IT WAS
	// --------------------------------
	// It used to fold the whole text to lower case and look for every name in
	// it, which attached a record to any sentence that happened to contain its
	// words. Audits found the damage story by story: "that night" linked One
	// Ordinary Day through its alternate title, "twenty years" linked the drama
	// Twenty Years, "one day" linked A Day, and "Park Sung-hoon" linked the
	// actor Sung Hoon. A wrong link is wrong data on two pages at once.
	//
	// So a name now has to appear the way a name appears: with its own
	// capitalisation, not glued to a hyphen or letter, and not as the tail of
	// a longer proper name. Longer names are tried first and the span they
	// match is used up, so a short name cannot match inside a long one.
	// Alternate titles only count in italics (pass one), because they are the
	// ones most often made of ordinary words.
	$plain = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
	$plain = str_replace( array( '’', '‘' ), "'", $plain );

	$rows = array_filter( $index, static fn( $r ) => empty( $r['alias'] ) && $r['words'] >= 2 );
	uasort( $rows, static fn( $a, $b ) => mb_strlen( $b['name'] ) <=> mb_strlen( $a['name'] ) );

	foreach ( $rows as $row ) {
		$words = preg_split( '/[\s\p{P}]+/u', trim( str_replace( '’', "'", $row['name'] ) ), -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $words ) < 2 ) {
			continue;
		}

		$pattern = '/(?<![\p{L}\p{N}\-])' . implode( '[\s\p{P}]{1,3}', array_map( static fn( $w ) => preg_quote( $w, '/' ), $words ) ) . '(?![\p{L}\p{N}]|-\p{L})/u';

		if ( ! preg_match_all( $pattern, $plain, $mm, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}

		foreach ( $mm[0] as $hit ) {
			list( $found, $offset ) = $hit;
			$before = substr( $plain, max( 0, $offset - 40 ), min( 40, $offset ) );

			// The tail of a longer proper name ("Park Sung Hoon" is not "Sung Hoon").
			if ( 'person' === $row['type'] && preg_match( '/\p{Lu}[\p{L}\-]*\s+$/u', $before ) ) {
				continue;
			}

			// A two-word name at the start of a sentence is as likely to be an
			// ordinary phrase with a capital letter as a title.
			if ( 2 === count( $words ) && 'person' !== $row['type'] && ( '' === trim( $before ) || preg_match( '/[.!?]\s*$/u', $before ) ) ) {
				continue;
			}

			$hits[] = $row['id'];

			// Use the span up, so nothing shorter matches inside it.
			$plain = substr_replace( $plain, str_repeat( ' ', strlen( $found ) ), $offset, strlen( $found ) );
			break;
		}
	}

	return array_values( array_unique( array_map( 'intval', $hits ) ) );
}

/**
 * Write the flat index a record reads to find the stories about it.
 *
 * Runs late so the meta box has already saved its own fields.
 */
add_action( 'save_post_post', 'daw_reindex_news_mentions', 25, 3 );
function daw_reindex_news_mentions( $post_id, $post = null, $update = null ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( 'news' !== daw_get_type( $post_id ) ) {
		return;
	}

	daw_write_mention_index( $post_id );
}

/**
 * @param int $post_id
 * @return int[] The ids written.
 */
function daw_write_mention_index( $post_id ) {
	$ids = daw_news_mentions( $post_id );

	if ( $ids ) {
		update_post_meta( $post_id, DAW_MENTION_IDX, ',' . implode( ',', $ids ) . ',' );
	} else {
		delete_post_meta( $post_id, DAW_MENTION_IDX );
	}

	delete_transient( 'daw_nment_' . (int) $post_id );

	return $ids;
}

/**
 * The list the story's own template renders, ordered so people come before the
 * things they were in, and cached because it is read on every page view.
 *
 * @param int $post_id
 * @return int[]
 */
function daw_news_mentions_cached( $post_id ) {
	$key    = 'daw_nment_' . (int) $post_id;
	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$ids   = daw_news_mentions( $post_id );
	$order = array( 'person' => 0, 'group' => 1, 'drama' => 2, 'film' => 3, 'variety' => 4 );

	$rows = array();
	foreach ( $ids as $id ) {
		if ( 'publish' !== get_post_status( $id ) ) {
			continue;
		}
		$type   = daw_get_type( $id );
		$rows[] = array(
			'id'   => (int) $id,
			'rank' => isset( $order[ $type ] ) ? $order[ $type ] : 9,
		);
	}

	usort(
		$rows,
		static function ( $a, $b ) {
			return $a['rank'] === $b['rank'] ? $a['id'] - $b['id'] : $a['rank'] - $b['rank'];
		}
	);

	$out = wp_list_pluck( $rows, 'id' );
	set_transient( $key, $out, HOUR_IN_SECONDS );

	return $out;
}

/* ====================================================== links in the body = */

/**
 * Turn the first plain mention of each linked record into a link.
 *
 * Done on output rather than in the stored content so that renaming a record,
 * or unpublishing one, cannot leave a dead href behind in the database. Only
 * the first occurrence is linked: a paragraph with the same name linked four
 * times reads like a directory, not a sentence.
 *
 * Text already inside a link, a heading or an attribute is left alone.
 */
add_filter( 'the_content', 'daw_link_mentions_in_body', 12 );
function daw_link_mentions_in_body( $html ) {
	if ( ! is_singular( 'post' ) || is_admin() || ! in_the_loop() || ! is_main_query() ) {
		return $html;
	}

	$post_id = get_the_ID();
	if ( 'news' !== daw_get_type( $post_id ) ) {
		return $html;
	}

	$ids = daw_news_mentions_cached( $post_id );
	if ( ! $ids ) {
		return $html;
	}

	// Longest names first, so "Seo Kang Joon" is taken before "Seo Kang".
	$targets = array();
	foreach ( $ids as $id ) {
		$name = get_the_title( $id );
		if ( '' === trim( (string) $name ) ) {
			continue;
		}
		$targets[] = array(
			'id'   => (int) $id,
			'name' => (string) $name,
			'len'  => strlen( (string) $name ),
			'url'  => get_permalink( $id ),
		);
	}

	usort(
		$targets,
		static function ( $a, $b ) {
			return $b['len'] - $a['len'];
		}
	);

	// Split on tags so replacements only ever land in text nodes, and skip the
	// inside of anchors, headings and figure captions.
	$parts   = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	$skip    = 0;
	$done    = array();
	$out     = '';

	foreach ( $parts as $part ) {
		if ( '' === $part ) {
			continue;
		}

		if ( '<' === $part[0] ) {
			if ( preg_match( '/^<\s*(a|h1|h2|h3|h4|figcaption)\b/i', $part ) ) {
				$skip++;
			} elseif ( preg_match( '/^<\s*\/\s*(a|h1|h2|h3|h4|figcaption)\b/i', $part ) ) {
				$skip = max( 0, $skip - 1 );
			}
			$out .= $part;
			continue;
		}

		if ( $skip > 0 ) {
			$out .= $part;
			continue;
		}

		foreach ( $targets as $t ) {
			if ( isset( $done[ $t['id'] ] ) ) {
				continue;
			}

			$pattern = '/(?<![\p{L}\p{N}])(' . preg_quote( $t['name'], '/' ) . ')(?![\p{L}\p{N}])/u';
			$first   = preg_replace(
				$pattern,
				'<a href="' . esc_url( $t['url'] ) . '">$1</a>',
				$part,
				1,
				$count
			);

			if ( $count ) {
				$part            = $first;
				$done[ $t['id'] ] = true;
			}
		}

		$out .= $part;
	}

	return $out;
}

/* ============================================================ bulk rebuild = */

/**
 * Rebuild the index for every story in one pass, for after an import or after
 * this file changes. Batched, because the scan touches every record name.
 *
 * @param int $limit
 * @return array [ processed, remaining ]
 */
function daw_rebuild_news_links( $limit = 25 ) {
	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => 'news',
				),
			),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_daw_links_built',
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);

	$done = array();

	foreach ( $ids as $id ) {
		$done[ $id ] = daw_write_mention_index( $id );
		update_post_meta( $id, '_daw_links_built', time() );
	}

	return $done;
}

/** Clear the done-marker so the next run walks every story again. */
function daw_reset_news_links() {
	global $wpdb;
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_daw_links_built' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/* =============================================================== the screen = */

/**
 * Tools → News links. Same shape as the cast index screen: a batch button and
 * a count, because the scan reads every record name and a whole-site pass in
 * one request is how you meet a gateway timeout.
 */
add_action( 'admin_menu', 'daw_news_links_menu' );
function daw_news_links_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: News links', 'daw' ),
		__( 'DAW: News links', 'daw' ),
		'manage_options',
		'daw-news-links',
		'daw_news_links_page'
	);
}

function daw_news_links_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="wrap"><h1>DAW: News links</h1>';
	echo '<p>Each story is read for the records it names, in italics for titles and by full name for people, and the result is written to the index a record uses to find the stories about it. Stories saved from now on do this themselves; run it once to cover the ones that already exist.</p>';

	if ( isset( $_POST['daw_nl_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['daw_nl_nonce'] ), 'daw_nl' ) ) {
		if ( isset( $_POST['daw_nl_reset'] ) ) {
			daw_reset_news_links();
			echo '<div class="notice notice-success"><p>Marker cleared. The next run walks every story again.</p></div>';
		} else {
			$done  = daw_rebuild_news_links( 25 );
			$links = 0;
			foreach ( $done as $ids ) {
				$links += count( (array) $ids );
			}
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html( sprintf( 'Linked %d story(ies), %d record link(s) written.', count( $done ), $links ) )
			);
		}
	}

	$pending = daw_news_links_pending_count();

	echo '<form method="post">';
	wp_nonce_field( 'daw_nl', 'daw_nl_nonce' );
	submit_button( 'Link the next 25', 'primary', 'submit', false );
	echo ' ';
	submit_button( 'Start again', 'secondary', 'daw_nl_reset', false );
	echo '</form>';

	printf( '<p><strong>%d</strong> story(ies) still to link.</p>', (int) $pending );
	echo '</div>';
}

function daw_news_links_pending_count() {
	$q = new WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => 'news',
				),
			),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_daw_links_built',
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);

	return (int) $q->found_posts;
}

/* ============================================================ the card date = */

/**
 * The date a news card should show.
 *
 * A rolling story keeps its url and grows, which is the whole point of the
 * timeline, but it means the publication date drifts further from what the page
 * now says. A card that reads "19 February 2025" above a headline about a
 * premiere this month looks stale and is, for the reader, simply wrong.
 *
 * So the card shows when the story was published until it has been meaningfully
 * revised, and after that it shows when it was last brought up to date, marked
 * as such. The published date stays in the byline on the story itself and in
 * datePublished, because rewriting that on every edit is both dishonest and a
 * signal search engines treat as manipulation.
 *
 * @param int|WP_Post $post
 * @return string
 */
function daw_news_card_date( $post ) {
	$id = is_object( $post ) ? $post->ID : (int) $post;

	$published = (int) get_post_time( 'U', true, $id );
	$modified  = (int) get_post_modified_time( 'U', true, $id );

	if ( $modified > $published + ( 30 * DAY_IN_SECONDS ) ) {
		/* translators: %s: the date the story was last updated */
		return sprintf( __( 'Updated %s', 'daw' ), date_i18n( 'j F Y', $modified ) );
	}

	return date_i18n( 'j F Y', $published );
}
