<?php
/**
 * Awards, read from Wikipedia instead of typed in.
 *
 * WHY AN IMPORT AND NOT A LIVE FEED
 * --------------------------------
 * The obvious idea is to draw the awards table straight from Wikipedia on every
 * page view. It is the wrong idea three times over: the page would stop
 * rendering the day Wikipedia is slow, an anonymous edit there would appear
 * here unreviewed, and the structured data would claim prizes nobody on this
 * site ever checked. So the fetch happens once, on demand, and what it finds is
 * written into this site's own fields. After that the page owns its data: it
 * renders from the database, an editor can correct any row, and a correction is
 * never overwritten by the next fetch.
 *
 * WHAT IT WILL NOT DO
 * -------------------
 * It will not touch a profile that already has awards. Filling an empty table
 * is a favour; replacing a checked one is damage, and no button should be able
 * to do that by accident. Replacing takes a separate, explicit request.
 *
 * WHERE THE TABLE COMES FROM
 * --------------------------
 * The article's own accolades table, through Wikipedia's REST HTML endpoint.
 * Those tables use rowspans heavily -- a ceremony spans its years, a year spans
 * its categories -- so a naive row read gives eleven rows where there are
 * fourteen, with the ceremony missing from most of them. The expansion below is
 * the whole reason this file is longer than a regex would be.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** The five parallel arrays an award row is stored in. */
const DAW_AW_KEYS = array( 'daw_paw_year', 'daw_paw_body', 'daw_paw_category', 'daw_paw_work', 'daw_paw_result' );

/**
 * The Wikipedia article for a person, if we can name one.
 *
 * The stored link is trusted first: someone chose it. Without one the person's
 * own title is tried against the search API, and only an unambiguous, strong
 * match is accepted -- guessing the wrong article would import another actor's
 * career, which is worse than importing nothing.
 */
function daw_awards_wiki_title( $post_id ) {
	$link = trim( (string) get_post_meta( $post_id, 'daw_link_wikipedia', true ) );

	if ( '' !== $link && preg_match( '~/wiki/([^?#]+)~', $link, $m ) ) {
		return rawurldecode( $m[1] );
	}

	$name = get_the_title( $post_id );

	if ( '' === trim( (string) $name ) ) {
		return '';
	}

	$res = wp_remote_get(
		add_query_arg(
			array(
				'action'   => 'query',
				'list'     => 'search',
				'srsearch' => rawurlencode( $name ),
				'srlimit'  => 1,
				'format'   => 'json',
			),
			'https://en.wikipedia.org/w/api.php'
		),
		array(
			'timeout'    => 15,
			'user-agent' => 'discoverasianworld.com awards import',
		)
	);

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return '';
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	$hit  = $body['query']['search'][0]['title'] ?? '';

	if ( '' === $hit ) {
		return '';
	}

	// A search hit is only accepted when it is plainly the same person. The
	// API happily returns "List of South Korean actors" for a name it does not
	// hold, and that article has a table in it.
	$norm = static function ( $s ) {
		return preg_replace( '~[^a-z0-9]~', '', strtolower( (string) $s ) );
	};

	return $norm( $hit ) === $norm( $name ) ? $hit : '';
}

/** The article HTML, or an empty string. */
function daw_awards_wiki_html( $title ) {
	if ( '' === trim( (string) $title ) ) {
		return '';
	}

	$res = wp_remote_get(
		'https://en.wikipedia.org/api/rest_v1/page/html/' . rawurlencode( $title ),
		array(
			'timeout'    => 25,
			'user-agent' => 'discoverasianworld.com awards import',
		)
	);

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return '';
	}

	return (string) wp_remote_retrieve_body( $res );
}

/** A cell's text: no reference markers, no footnote letters, no stray space. */
function daw_awards_cell_text( $node ) {
	$text = $node->textContent;
	$text = preg_replace( '~\[[^\]]{0,12}\]~u', '', $text );       // [1], [a], [note 2]
	$text = str_replace( array( "\r", "\n", "\t" ), ' ', $text );
	$text = preg_replace( '~\s+~u', ' ', $text );

	return trim( (string) $text );
}

/**
 * Every wikitable in the article, with rowspans and colspans expanded so that
 * each row is a complete row.
 */
function daw_awards_tables( $html ) {
	if ( '' === trim( (string) $html ) ) {
		return array();
	}

	$prev = libxml_use_internal_errors( true );
	$doc  = new DOMDocument();
	$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );

	$xp     = new DOMXPath( $doc );
	$tables = $xp->query( '//table[contains(@class,"wikitable")]' );
	$out    = array();

	foreach ( $tables as $table ) {
		$rows    = $xp->query( './/tr', $table );
		$grid    = array();
		$pending = array();                    // column => array(value, rows left)

		foreach ( $rows as $tr ) {
			$row = array();
			$col = 0;

			// Anything still spanning from an earlier row occupies its column
			// before this row's own cells are placed.
			$fill = static function () use ( &$pending, &$row, &$col ) {
				while ( isset( $pending[ $col ] ) && $pending[ $col ][1] > 0 ) {
					$row[ $col ] = $pending[ $col ][0];
					$pending[ $col ][1]--;
					$col++;
				}
			};

			$fill();

			foreach ( $xp->query( './th|./td', $tr ) as $cell ) {
				$value = daw_awards_cell_text( $cell );
				$span  = max( 1, (int) $cell->getAttribute( 'rowspan' ) );
				$wide  = max( 1, (int) $cell->getAttribute( 'colspan' ) );

				for ( $i = 0; $i < $wide; $i++ ) {
					$row[ $col ] = $value;

					if ( $span > 1 ) {
						$pending[ $col ] = array( $value, $span - 1 );
					}

					$col++;
					$fill();
				}
			}

			ksort( $row );
			$grid[] = array_values( $row );
		}

		if ( count( $grid ) > 1 ) {
			$out[] = $grid;
		}
	}

	return $out;
}

/**
 * The accolades table, turned into award rows.
 *
 * A table qualifies only if its header names a result and a year: that pair is
 * what separates an awards table from a filmography, and both appear on every
 * actor's article in the same words.
 */
function daw_awards_rows_from( $html, $person_name = '' ) {
	$norm = static function ( $s ) {
		return strtolower( trim( (string) $s ) );
	};

	foreach ( daw_awards_tables( $html ) as $grid ) {
		$head = array_map( $norm, $grid[0] );

		$find = static function ( array $wants ) use ( $head ) {
			foreach ( $head as $i => $label ) {
				foreach ( $wants as $want ) {
					if ( false !== strpos( $label, $want ) ) {
						return $i;
					}
				}
			}
			return -1;
		};

		$i_result = $find( array( 'result' ) );
		$i_year   = $find( array( 'year' ) );

		if ( $i_result < 0 || $i_year < 0 ) {
			continue;
		}

		$i_body     = $find( array( 'award ceremony', 'ceremony', 'award' ) );
		$i_category = $find( array( 'category' ) );
		$i_work     = $find( array( 'work', 'nominee', 'recipient', 'nominated work' ) );

		$rows = array();

		foreach ( array_slice( $grid, 1 ) as $line ) {
			$result = $line[ $i_result ] ?? '';
			$year   = preg_replace( '~[^0-9]~', '', $line[ $i_year ] ?? '' );

			// A row with no result is a section heading or a blank separator.
			if ( '' === trim( $result ) || '' === $year ) {
				continue;
			}

			$work     = $i_work >= 0 ? ( $line[ $i_work ] ?? '' ) : '';
			$category = $i_category >= 0 ? ( $line[ $i_category ] ?? '' ) : '';

			// THE NOMINEE COLUMN HOLDS TWO DIFFERENT THINGS
			// ---------------------------------------------
			// Usually a title. Sometimes the person's own name, when the prize
			// was for them rather than for a role. And sometimes both at once:
			// a Best Couple cell reads "Seo Kang-joon (with Gong Seung-yeon)Are
			// You Human?", which is the title with the nominee glued to the
			// front of it. Cutting at the name and throwing away the rest loses
			// the drama; keeping the whole string puts a person's name in a
			// column that links to records. So the name and the bracket that
			// follows it are lifted out, the bracket is kept by moving it onto
			// the category, and whatever remains is the work.
			if ( '' !== $person_name && false !== stripos( $work, $person_name ) ) {
				$with = array();

				$work = preg_replace_callback(
					'~\s*' . preg_quote( $person_name, '~' ) . '\s*(\([^)]*\))?\s*[/,]?\s*~i',
					static function ( $m ) use ( &$with ) {
						if ( ! empty( $m[1] ) ) {
							$with[] = $m[1];
						}
						return ' ';
					},
					$work
				);

				$work = trim( (string) $work );

				if ( $with ) {
					$category = trim( $category . ' ' . implode( ' ', $with ) );
				}
			}

			$rows[] = array(
				'daw_paw_year'     => $year,
				'daw_paw_body'     => $i_body >= 0 ? ( $line[ $i_body ] ?? '' ) : '',
				'daw_paw_category' => $category,
				'daw_paw_work'     => $work,
				'daw_paw_result'   => ucfirst( strtolower( trim( $result ) ) ),
			);
		}

		if ( $rows ) {
			// Newest first, the same order the page prints.
			usort(
				$rows,
				static function ( $a, $b ) {
					return (int) $b['daw_paw_year'] <=> (int) $a['daw_paw_year'];
				}
			);

			return $rows;
		}
	}

	return array();
}

/** Does this profile already have awards an editor might have checked? */
function daw_awards_has_rows( $post_id ) {
	foreach ( array( 'daw_paw_body', 'daw_paw_category', 'daw_paw_name' ) as $key ) {
		foreach ( (array) get_post_meta( $post_id, $key, true ) as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Fill one profile's awards from Wikipedia.
 *
 * Returns a short status string, which is what the admin notice prints. The
 * strings are deliberately specific: "no article" and "no table in the article"
 * are different problems and the second one is worth a human look.
 */
function daw_awards_import( $post_id, $replace = false ) {
	$post_id = (int) $post_id;

	if ( 'publish' !== get_post_status( $post_id ) ) {
		return 'not a published record';
	}

	if ( ! $replace && daw_awards_has_rows( $post_id ) ) {
		return 'already has awards, left alone';
	}

	$title = daw_awards_wiki_title( $post_id );

	if ( '' === $title ) {
		return 'no Wikipedia article found';
	}

	$rows = daw_awards_rows_from( daw_awards_wiki_html( $title ), get_the_title( $post_id ) );

	if ( ! $rows ) {
		return 'no awards table in ' . $title;
	}

	$columns = array();

	foreach ( DAW_AW_KEYS as $key ) {
		$columns[ $key ] = array();
	}

	foreach ( $rows as $row ) {
		foreach ( DAW_AW_KEYS as $key ) {
			$columns[ $key ][] = $row[ $key ];
		}
	}

	foreach ( $columns as $key => $values ) {
		update_post_meta( $post_id, $key, $values );
	}

	// The old single-line field is cleared to the same length, or the reader
	// would pair new rows with leftover text from the old ones.
	update_post_meta( $post_id, 'daw_paw_name', array_fill( 0, count( $rows ), '' ) );

	update_post_meta( $post_id, 'daw_awards_source', 'https://en.wikipedia.org/wiki/' . rawurlencode( $title ) );
	update_post_meta( $post_id, 'daw_awards_fetched', gmdate( 'Y-m-d' ) );

	return sprintf( '%d rows from %s', count( $rows ), $title );
}

/* ------------------------------------------------------------- the button --- */

add_action( 'admin_post_daw_import_awards', 'daw_awards_handle' );
function daw_awards_handle() {
	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
	$replace = ! empty( $_GET['replace'] );

	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( 'Not allowed.', 'daw' ) );
	}

	check_admin_referer( 'daw_import_awards_' . $post_id );

	$status = daw_awards_import( $post_id, $replace );

	wp_safe_redirect(
		add_query_arg( 'daw_awards', rawurlencode( $status ), get_edit_post_link( $post_id, 'raw' ) )
	);
	exit;
}

add_action( 'admin_notices', 'daw_awards_notice' );
function daw_awards_notice() {
	if ( empty( $_GET['daw_awards'] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-info is-dismissible"><p>%s %s</p></div>',
		esc_html__( 'Awards import:', 'daw' ),
		esc_html( sanitize_text_field( wp_unslash( $_GET['daw_awards'] ) ) )
	);
}

/** The link that sits in the awards box. */
function daw_awards_button_html() {
	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

	if ( ! $post_id ) {
		return '';
	}

	$url = wp_nonce_url(
		admin_url( 'admin-post.php?action=daw_import_awards&post=' . $post_id ),
		'daw_import_awards_' . $post_id
	);

	$source  = trim( (string) get_post_meta( $post_id, 'daw_awards_source', true ) );
	$fetched = trim( (string) get_post_meta( $post_id, 'daw_awards_fetched', true ) );

	$note = $source
		? sprintf(
			/* translators: 1: source url, 2: date */
			__( 'Last imported from %1$s on %2$s. Re-importing replaces every row below.', 'daw' ),
			esc_html( $source ),
			esc_html( $fetched )
		)
		: __( 'Reads the accolades table from the English Wikipedia article and fills the rows below. It will not touch a table that already has rows unless you ask it to replace them.', 'daw' );

	return sprintf(
		'<p><a class="button" href="%s">%s</a> <a class="button-link" href="%s" onclick="return confirm(%s)">%s</a></p><p class="description">%s</p>',
		esc_url( $url ),
		esc_html__( 'Fill awards from Wikipedia', 'daw' ),
		esc_url( add_query_arg( 'replace', '1', $url ) ),
		esc_attr( wp_json_encode( __( 'Replace every award row with what Wikipedia has?', 'daw' ) ) ),
		esc_html__( 'replace what is there', 'daw' ),
		esc_html( $note )
	);
}
