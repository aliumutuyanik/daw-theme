<?php
/**
 * Discover Asian World — pull content out of Elementor into real fields
 *
 * The audit found the site's actual content living inside _elementor_data
 * accordions while post_content held either nothing or a duplicated block of
 * "Memories of the Alhambra" text. This reads the accordions and writes each
 * panel into the field it belongs in.
 *
 * WHAT IT TOUCHES
 * ---------------
 * Post meta and the dw_genre / dw_network taxonomies. It does NOT write
 * post_content, post_title, post_name, post_type or categories. _elementor_data
 * is read, never modified — the Elementor version of every page stays exactly
 * as it is, so nothing that renders today stops rendering. No url can change.
 *
 * Idempotent: a record already carrying the marker is skipped, so it is safe
 * to run repeatedly and safe to interrupt.
 *
 * MATCHING IS BY PANEL TITLE, NOT POSITION
 * ----------------------------------------
 * Two records sampled in the audit had their panels in the same order, but
 * ordering is an editor's habit and habits drift over 926 records. Titles are
 * the stable key, so an editor who reordered panels in 2024 costs us nothing
 * and an unrecognised title is reported rather than guessed at.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_EXTRACT_BATCH = 40;

/**
 * Accordion panel title → field id, per record type.
 * Titles are normalised (lowercase, collapsed whitespace) before lookup.
 */
function daw_panel_map( $type ) {
	$title = array(
		'details'                    => 'daw_details_blob',
		'best scenes'                => 'daw_best_scenes',
		'filming locations'          => 'daw_filming_location',
		'visual design and costumes' => 'daw_visual_design',
		'themes and messages'        => 'daw_themes_messages',
		'interesting facts'          => 'daw_interesting_facts',
		'awards'                     => 'daw_awards',
		'real-life loves on set'     => 'daw_real_life_loves',
		'real life loves on set'     => 'daw_real_life_loves',
	);

	$person = array(
		'info'         => 'daw_info_blob',
		'biography'    => 'daw_biography',
		'hobbies'      => 'daw_hobbies',
		'interests'    => 'daw_interests',
		'facts'        => 'daw_facts',
		'secrets'      => 'daw_secrets',
		'funny things' => 'daw_funny_things',
		'fears'        => 'daw_fears',
		'news'         => 'daw_person_news',
	);

	if ( in_array( $type, array( 'drama', 'film' ), true ) ) {
		return $title;
	}
	if ( 'person' === $type ) {
		return $person;
	}

	return array();
}

/**
 * Label inside the Details / Info panel → where it goes.
 * 'tax:' prefixed values are taxonomies, everything else is a meta field.
 */
function daw_label_map( $type ) {
	if ( 'person' === $type ) {
		return array(
			'native name'  => 'daw_native_name',
			'nationality'  => 'daw_nationality',
			'gender'       => 'daw_gender',
			'born'         => 'daw_born',
			'birth place'  => 'daw_birth_place',
			'birthplace'   => 'daw_birth_place',
			'zodiac sign'  => 'daw_zodiac',
			'height'       => 'daw_height_cm',
			'spouse'       => 'daw_spouse',
			'children'     => 'daw_children',
			'agency'       => 'daw_agency',
			'occupation'   => 'daw_occupation',
			'profession'   => 'daw_occupation',
			'group'        => 'daw_member_of',
		);
	}

	return array(
		'native title'     => 'daw_native_title',
		'also known as'    => 'daw_also_known_as',
		'genres'           => 'tax:dw_genre',
		'screenwriters'    => 'daw_screenwriter',
		'screenwriter'     => 'daw_screenwriter',
		'directors'        => 'daw_director',
		'director'         => 'daw_director',
		'episodes'         => 'daw_episodes',
		'aired'            => 'daw_aired_range',
		'airing days'      => 'daw_airing_days',
		'original network' => 'tax:dw_network',
		'network'          => 'tax:dw_network',
		'duration'         => 'daw_duration_min',
		'content rating'   => 'daw_content_rating',
	);
}

/* ------------------------------------------------------------- utilities - */

function daw_norm( $s ) {
	$s = wp_strip_all_tags( (string) $s );
	$s = html_entity_decode( $s, ENT_QUOTES, 'UTF-8' );
	$s = preg_replace( '/\s+/u', ' ', $s );
	return strtolower( trim( $s, " \t\n\r\0\x0B:" ) );
}

/**
 * The accordion HTML arrives carrying ChatGPT's data-start / data-end debris
 * and a scattering of emoji used as bullets. Strip both — they add nothing and
 * they make the stored value harder to search and re-use later.
 */
/**
 * Make a value survive the database.
 *
 * This site's tables are not utf8mb4, so a four-byte character — every emoji —
 * is invalid input. MySQL does not reject it politely: it truncates from that
 * character onward. The Awards panel began with a trophy emoji, so 4KB of award
 * listings silently became an empty string, and the extractor had no way to
 * know, because the write itself reported success.
 *
 * wp_encode_emoji() turns them into HTML entities, which render identically and
 * store as plain ASCII. The real fix is converting the tables to utf8mb4; until
 * that happens this keeps content from disappearing.
 */
function daw_db_safe( $value ) {
	return function_exists( 'wp_encode_emoji' ) ? wp_encode_emoji( (string) $value ) : (string) $value;
}

function daw_clean_html( $html ) {
	$html = preg_replace( '/\sdata-(start|end|is-only-node)="[^"]*"/', '', (string) $html );
	$html = preg_replace( '/\sdata-(start|end|is-only-node)(?=[\s>])/', '', $html );
	return trim( $html );
}

/**
 * Walk the Elementor tree and collect what we need in one pass.
 */
function daw_walk_elementor( array $nodes, array &$found ) {
	foreach ( $nodes as $node ) {
		$widget   = $node['widgetType'] ?? '';
		$settings = $node['settings'] ?? array();

		if ( 'nested-accordion' === $widget ) {
			$found['accordion'][] = $node;
		} elseif ( 'image-box' === $widget && empty( $found['synopsis'] ) ) {
			// The real synopsis lives here, not in the excerpt. Four hand-written
			// paragraphs naming every lead and the character they play — the
			// excerpt is a two-line trailer for it. Missing this on the first
			// pass meant 926 records would have shipped with the short version.
			$found['synopsis'] = $settings['description_text'] ?? '';
		} elseif ( 'video' === $widget && empty( $found['video'] ) ) {
			$url = $settings['youtube_url'] ?? ( $settings['link'] ?? '' );
			if ( $url ) {
				$found['video'] = is_array( $url ) ? ( $url['url'] ?? '' ) : $url;
			}
		}

		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			daw_walk_elementor( $node['elements'], $found );
		}
	}
}

/**
 * Collect the text-editor HTML of each direct child slot of an accordion,
 * in order, so it can be paired with the item_title of the same index.
 */
function daw_accordion_panels( array $accordion ) {
	$titles = array();
	foreach ( ( $accordion['settings']['items'] ?? array() ) as $item ) {
		$titles[] = $item['item_title'] ?? '';
	}

	$bodies = array();
	foreach ( ( $accordion['elements'] ?? array() ) as $slot ) {
		$html = '';
		$collect = function ( array $nodes ) use ( &$collect, &$html ) {
			foreach ( $nodes as $n ) {
				if ( ( $n['widgetType'] ?? '' ) === 'text-editor' ) {
					$html .= $n['settings']['editor'] ?? '';
				}
				if ( ! empty( $n['elements'] ) ) {
					$collect( $n['elements'] );
				}
			}
		};
		$collect( array( $slot ) );
		$bodies[] = $html;
	}

	$out = array();
	foreach ( $titles as $i => $t ) {
		$out[] = array( 'title' => $t, 'html' => $bodies[ $i ] ?? '' );
	}

	return $out;
}

/**
 * Split a "<strong>Label:</strong> value<br>" blob into label => value pairs.
 */
function daw_parse_blob( $html ) {
	$html  = preg_replace( '#<br\s*/?>#i', "\n", (string) $html );
	$html  = preg_replace( '#</p>#i', "\n", $html );
	$lines = preg_split( '/\n+/', $html );

	$pairs = array();
	foreach ( $lines as $line ) {
		if ( ! preg_match( '#<strong[^>]*>(.*?)</strong>(.*)#is', $line, $m ) ) {
			continue;
		}
		$label = daw_norm( $m[1] );
		$value = trim( wp_strip_all_tags( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ) ) );
		$value = trim( $value, " :\u{00A0}" );
		if ( '' !== $label && '' !== $value ) {
			$pairs[ $label ] = $value;
		}
	}

	return $pairs;
}

/**
 * "November 12, 2014 – January 15, 2015" → two timestamps.
 * Handles the en dash, the hyphen, and a single date with no end.
 */
function daw_parse_date_range( $value ) {
	$parts = preg_split( '/\s*[–—-]\s*/u', $value, 2 );
	$start = strtotime( trim( $parts[0] ?? '' ) );
	$end   = isset( $parts[1] ) ? strtotime( trim( $parts[1] ) ) : false;

	return array( $start ?: null, $end ?: null );
}

/* ----------------------------------------------------------- the extractor */

/**
 * @return array  ['panels' => n, 'unknown' => [titles], 'fields' => n]
 */
function daw_extract_one( $post_id, $write = true ) {
	$report = array( 'panels' => 0, 'unknown' => array(), 'fields' => 0 );

	$type = daw_get_type( $post_id );
	$map  = daw_panel_map( $type );
	if ( ! $map ) {
		return $report;
	}

	// The payload, wherever it now lives: records taken off the builder keep
	// it under a name Elementor no longer reads. See inc/elementor-exit.php.
	$raw = function_exists( 'daw_elementor_payload' )
		? daw_elementor_payload( $post_id )
		: get_post_meta( $post_id, '_elementor_data', true );
	if ( ! $raw ) {
		return $report;
	}
	$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
	if ( ! is_array( $data ) ) {
		return $report;
	}

	$found = array( 'accordion' => array(), 'video' => '', 'synopsis' => '' );
	daw_walk_elementor( $data, $found );

	if ( $write && trim( (string) $found['synopsis'] ) !== '' ) {
		$report['fields'] += daw_write_synopsis( $post_id, $found['synopsis'] );
	}

	if ( $write && $found['video'] ) {
		update_post_meta( $post_id, 'daw_trailer_youtube', esc_url_raw( $found['video'] ) );
		$report['fields']++;
	}

	foreach ( $found['accordion'] as $accordion ) {
		foreach ( daw_accordion_panels( $accordion ) as $panel ) {
			$key = daw_norm( $panel['title'] );
			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $map[ $key ] ) ) {
				$report['unknown'][ $key ] = ( $report['unknown'][ $key ] ?? 0 ) + 1;
				continue;
			}

			$report['panels']++;
			$field = $map[ $key ];
			$html  = daw_clean_html( $panel['html'] );

			$report['sizes'][ $key ] = array(
				'raw'   => strlen( (string) $panel['html'] ),
				'clean' => strlen( (string) $html ),
			);

			if ( 'daw_details_blob' === $field || 'daw_info_blob' === $field ) {
				$report['fields'] += daw_write_blob( $post_id, $type, $html, $write );
				continue;
			}

			if ( $write && '' !== $html ) {
				$safe = daw_db_safe( wp_kses_post( $html ) );
				$report['sizes'][ $key ]['stored'] = strlen( (string) $safe );

				if ( '' !== trim( $safe ) ) {
					update_post_meta( $post_id, $field, $safe );
					$report['fields']++;
				}
			}
		}
	}

	if ( $write ) {
		daw_extract_synopsis( $post_id );

		if ( in_array( $type, array( 'drama', 'film' ), true ) ) {
			$report['cast'] = daw_extract_cast( $post_id );
		}

		// A record of what each panel weighed going in and coming out. When a
		// panel silently produces nothing — as Awards did on the first run —
		// this says whether the content was missing, or arrived and was lost
		// on the way, without having to guess from the outside.
		update_post_meta( $post_id, '_daw_extract_report', wp_json_encode( $report['sizes'] ?? array() ) );
		update_post_meta( $post_id, '_daw_extracted', DAW_VERSION );
	}

	return $report;
}

/**
 * Store the synopsis found in the image-box widget.
 *
 * Preferred over the excerpt: this is the long hand-written version, complete
 * with who plays whom. An editor's own edit always wins — we never overwrite
 * work someone did in the new fields.
 *
 * @return int  1 when written, 0 when skipped.
 */
function daw_write_synopsis( $post_id, $text ) {
	if ( trim( (string) get_post_meta( $post_id, 'daw_synopsis', true ) ) !== '' ) {
		return 0;
	}

	update_post_meta( $post_id, 'daw_synopsis', daw_db_safe( wp_kses_post( wpautop( trim( $text ) ) ) ) );

	return 1;
}

/**
 * Fall back to the excerpt when no image-box synopsis exists.
 *
 * Excerpts on this site are a mixed bag: some were written with care, and some
 * carry the same duplicated "Memories of the Alhambra" paragraph that poisoned
 * post_content. We take the good ones and refuse the rest rather than copying a
 * known-wrong summary onto hundreds of records.
 */
function daw_extract_synopsis( $post_id ) {
	if ( trim( (string) get_post_meta( $post_id, 'daw_synopsis', true ) ) !== '' ) {
		return; // never overwrite something an editor wrote
	}

	$excerpt = trim( (string) get_post_field( 'post_excerpt', $post_id ) );
	if ( '' === $excerpt ) {
		return;
	}

	if ( stripos( $excerpt, 'Memories of the Alhambra' ) !== false
		&& stripos( get_the_title( $post_id ), 'Alhambra' ) === false ) {
		return;
	}

	update_post_meta( $post_id, 'daw_synopsis', daw_db_safe( wp_kses_post( wpautop( $excerpt ) ) ) );
}

/**
 * Turn the tag-based cast widget into a stored relationship.
 *
 * The Elementor cast list was never a list. It was a live query: "every person
 * record sharing a tag with this title". That is why the site carries 7,878
 * tags — they are the relationship graph, not clutter. We run the same query
 * once and store the result, so the link survives Elementor's removal.
 *
 * What the source does NOT contain, anywhere: billing order and character
 * names. Those cannot be extracted because they were never recorded. Everyone
 * lands in one untiered bucket, and tiering stays an editorial job rather than
 * a guess dressed up as data.
 */
function daw_extract_cast( $post_id ) {
	$tags = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'ids' ) );
	if ( is_wp_error( $tags ) || ! $tags ) {
		return 0;
	}

	$people = get_posts( array(
		'post_type'      => 'post',
		'posts_per_page' => 60,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'post__not_in'   => array( $post_id ),
		'orderby'        => 'title',
		'order'          => 'ASC',
		'tax_query'      => array(
			'relation' => 'AND',
			array(
				'taxonomy' => 'dw_type',
				'field'    => 'slug',
				'terms'    => 'person',
			),
			array(
				'taxonomy' => 'post_tag',
				'field'    => 'term_id',
				'terms'    => $tags,
			),
		),
	) );

	if ( ! $people ) {
		return 0;
	}

	update_post_meta( $post_id, 'daw_cast_person', array_map( 'strval', $people ) );

	return count( $people );
}

/**
 * Write the parsed Details / Info pairs into their individual fields.
 */
function daw_write_blob( $post_id, $type, $html, $write ) {
	$labels = daw_label_map( $type );
	$pairs  = daw_parse_blob( $html );
	$n      = 0;

	foreach ( $pairs as $label => $value ) {
		if ( ! isset( $labels[ $label ] ) ) {
			continue;
		}
		$target = $labels[ $label ];
		$n++;

		if ( ! $write ) {
			continue;
		}

		// Taxonomies: comma separated, created if missing.
		if ( str_starts_with( $target, 'tax:' ) ) {
			$tax   = substr( $target, 4 );
			$terms = array_filter( array_map( 'trim', explode( ',', $value ) ) );
			if ( $terms ) {
				wp_set_object_terms( $post_id, $terms, $tax, false );
			}
			continue;
		}

		switch ( $target ) {
			case 'daw_aired_range':
				list( $start, $end ) = daw_parse_date_range( $value );
				if ( $start ) {
					update_post_meta( $post_id, 'daw_aired_start', $start );
				}
				if ( $end ) {
					update_post_meta( $post_id, 'daw_aired_end', $end );
				}
				break;

			case 'daw_born':
				$ts = strtotime( $value );
				if ( $ts ) {
					update_post_meta( $post_id, 'daw_born', $ts );
				}
				break;

			case 'daw_height_cm':
				// "5'5\" (165 cm)" — take the centimetres, ignore the rest.
				if ( preg_match( '/(\d{2,3})\s*cm/i', $value, $m ) ) {
					update_post_meta( $post_id, 'daw_height_cm', (int) $m[1] );
				}
				break;

			case 'daw_duration_min':
				if ( preg_match( '/(\d+)/', $value, $m ) ) {
					update_post_meta( $post_id, 'daw_duration_min', (int) $m[1] );
				}
				break;

			case 'daw_episodes':
				if ( preg_match( '/(\d+)/', $value, $m ) ) {
					update_post_meta( $post_id, 'daw_episodes', (int) $m[1] );
				}
				break;

			case 'daw_gender':
				$g = strtolower( $value );
				update_post_meta( $post_id, 'daw_gender', str_contains( $g, 'female' ) ? 'female' : ( str_contains( $g, 'male' ) ? 'male' : 'other' ) );
				break;

			// Meta Box stores a cloneable field as ONE row holding an array —
			// not as several rows the way plain WordPress meta would. Writing
			// it the WordPress way left the values in the database but
			// invisible to both the editor and the template. One row, one array.
			case 'daw_also_known_as':
			case 'daw_screenwriter':
			case 'daw_director':
				$items = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
				if ( $items ) {
					update_post_meta( $post_id, $target, $items );
				}
				break;

			default:
				update_post_meta( $post_id, $target, daw_db_safe( sanitize_text_field( $value ) ) );
		}
	}

	return $n;
}

/* ------------------------------------------------------------- admin page - */

add_action( 'admin_menu', 'daw_extract_menu' );
function daw_extract_menu() {
	add_management_page(
		__( 'DAW: Extract content', 'daw' ),
		__( 'DAW: Extract content', 'daw' ),
		'manage_options',
		'daw-extract',
		'daw_extract_page'
	);
}

function daw_extract_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'daw' ) );
	}

	echo '<div class="wrap"><h1>' . esc_html__( 'Extract content from Elementor', 'daw' ) . '</h1>';

	if ( isset( $_GET['run'] ) && check_admin_referer( 'daw_extract' ) ) {
		daw_extract_run_batch();
		echo '</div>';
		return;
	}

	daw_extract_preview();

	$url = wp_nonce_url( admin_url( 'tools.php?page=daw-extract&run=1' ), 'daw_extract' );
	printf(
		'<p style="margin-top:24px"><a href="%s" class="button button-primary button-hero">%s</a></p>',
		esc_url( $url ),
		esc_html__( 'Run the extractor', 'daw' )
	);
	echo '<p class="description">' . esc_html__( 'Reads _elementor_data and writes fields. Elementor content is never modified, so every page keeps rendering exactly as it does now. No url, slug, title or category is touched.', 'daw' ) . '</p></div>';
}

/**
 * Dry run over a sample. Reports how many panels map cleanly and, more
 * usefully, every panel title the map does not recognise — that list is the
 * only thing standing between a partial extraction and a complete one.
 */
function daw_extract_preview() {
	$ids = get_posts( array(
		'post_type'      => 'post',
		'posts_per_page' => 120,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'orderby'        => 'rand',
		'tax_query'      => array(
			array(
				'taxonomy' => 'dw_type',
				'field'    => 'slug',
				'terms'    => array( 'drama', 'film', 'person' ),
			),
		),
	) );

	$panels = 0;
	$fields = 0;
	$unknown = array();
	$empty  = 0;

	foreach ( $ids as $id ) {
		$r = daw_extract_one( $id, false );
		$panels += $r['panels'];
		$fields += $r['fields'];
		if ( ! $r['panels'] ) {
			$empty++;
		}
		foreach ( $r['unknown'] as $k => $n ) {
			$unknown[ $k ] = ( $unknown[ $k ] ?? 0 ) + $n;
		}
	}

	$sampled = count( $ids );

	printf(
		'<h2>%s</h2><p>%s</p>',
		esc_html__( 'Preview', 'daw' ),
		sprintf(
			/* translators: 1: sample size 2: panels 3: fields 4: records with nothing found */
			esc_html__( 'Sampled %1$d random records: %2$d recognised panels, %3$d field values, %4$d records where no accordion was found.', 'daw' ),
			$sampled,
			$panels,
			$fields,
			$empty
		)
	);

	if ( $unknown ) {
		arsort( $unknown );
		echo '<h3>' . esc_html__( 'Panel titles the map does not recognise', 'daw' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'These panels will be left behind. Send this list over and the map gets widened before the full run.', 'daw' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:520px"><tbody>';
		foreach ( array_slice( $unknown, 0, 30, true ) as $title => $n ) {
			printf( '<tr><td><code>%s</code></td><td style="text-align:right">%d</td></tr>', esc_html( $title ), (int) $n );
		}
		echo '</tbody></table>';
	} else {
		echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Every panel title in the sample is recognised.', 'daw' ) . '</p></div>';
	}
}

function daw_extract_run_batch() {
	$ids = get_posts( array(
		'post_type'      => 'post',
		'posts_per_page' => DAW_EXTRACT_BATCH,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'tax_query'      => array(
			array(
				'taxonomy' => 'dw_type',
				'field'    => 'slug',
				'terms'    => array( 'drama', 'film', 'person' ),
			),
		),
		'meta_query'     => array(
			'relation' => 'OR',
			array( 'key' => '_daw_extracted', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_daw_extracted', 'value' => DAW_VERSION, 'compare' => '!=' ),
		),
	) );

	if ( ! $ids ) {
		echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'Done. Every drama, film and person record has been read.', 'daw' ) . '</strong></p></div>';
		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'tools.php?page=daw-extract' ) ),
			esc_html__( 'Back', 'daw' )
		);
		return;
	}

	$fields = 0;
	foreach ( $ids as $id ) {
		$r = daw_extract_one( $id, true );
		$fields += $r['fields'];
	}

	$next = wp_nonce_url( admin_url( 'tools.php?page=daw-extract&run=1' ), 'daw_extract' );

	printf(
		'<div class="notice notice-info"><p>%s</p></div>',
		sprintf(
			/* translators: 1: records 2: field values */
			esc_html__( '%1$d records, %2$d field values written. Continuing…', 'daw' ),
			count( $ids ),
			$fields
		)
	);
	printf( '<meta http-equiv="refresh" content="1;url=%s">', esc_url( $next ) );
	printf( '<p><a href="%s">%s</a></p>', esc_url( $next ), esc_html__( 'Continue now', 'daw' ) );
}
