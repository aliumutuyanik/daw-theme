<?php
/**
 * Fields for the guide records: culture, food and drink, myths, travel.
 *
 * WHY THE BODY IS STILL THE POST CONTENT
 * --------------------------------------
 * A drama record is a database row with a description attached, so its fields
 * are the page. A guide is the opposite: it is an article, and its shape is
 * whatever the subject needs — four sections here, seven there. Forcing that
 * into a repeater would fight the writer on every record and would have meant
 * migrating fifty-seven existing articles into slots before a single one could
 * render. So the prose stays in the editor where it already is, and the fields
 * around it carry only the things that have to be structured: the standfirst,
 * the fact table, the titles this appears in, and the sources.
 *
 * WHY THERE IS NO RECIPE SCHEMA HERE
 * ----------------------------------
 * These pages explain what a dish is, where it came from and why a reader has
 * just seen it in a drama. Some of them live at urls that promise a recipe, and
 * those keep a plain "how it is made" section in the prose, because a reader
 * who arrives from that search deserves an answer. What they do not get is a
 * recipe card with gram weights and Recipe markup: that is a different kind of
 * site, competing in a different search result, and it is not this one.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** The record types that share the guide template and these fields. */
function daw_guide_types() {
	// A quiz is an article with something to play in the middle of it: the same
	// intro, the same sources line, the same related rail. It gets the guide
	// template and the quiz block is printed inside it.
	return array( 'culture', 'food', 'myth', 'travel', 'feature', 'quiz' );
}

add_filter( 'rwmb_meta_boxes', 'daw_register_guide_meta_boxes', 12 );
function daw_register_guide_meta_boxes( $boxes ) {
	$boxes[] = daw_box_guide_intro();
	$boxes[] = daw_box_guide_glance();
	$boxes[] = daw_box_guide_onscreen();
	$boxes[] = daw_box_guide_sources();
	$boxes[] = daw_box_guide_faq();
	$boxes[] = daw_box_guide_shop();

	return $boxes;
}

function daw_guide_box( $id, $title, array $fields ) {
	return array(
		'id'         => $id,
		'title'      => $title,
		'post_types' => array( 'post' ),
		'daw_types'  => daw_guide_types(),
		'fields'     => $fields,
	);
}

/* ------------------------------------------------------------- intro ----- */

function daw_box_guide_intro() {
	return daw_guide_box(
		'daw_guide_intro',
		__( 'Guide: opening', 'daw' ),
		array(
			array(
				'id'   => 'daw_guide_standfirst',
				'name' => __( 'Standfirst', 'daw' ),
				'type' => 'textarea',
				'rows' => 3,
				'desc' => __( 'Two sentences under the headline that answer the question the reader arrived with. It is the first thing a search result shows and the first thing a reader judges, so it states the fact rather than promising one.', 'daw' ),
			),
			array(
				'id'      => 'daw_food_kind',
				'name'    => __( 'Kind of dish', 'daw' ),
				'type'    => 'select',
				'options' => array_merge(
					array( '' => __( '— not sorted —', 'daw' ) ),
					daw_food_kind_labels()
				),
				'desc'    => __( 'Food and drink only. It is what the Food page filters by, and a guide left unsorted simply does not appear under any of them.', 'daw' ),
			),
			array(
				'id'   => 'daw_guide_native',
				'name' => __( 'Name in its own language', 'daw' ),
				'type' => 'text',
				'desc' => __( 'Hangul, kanji or hanzi where there is one. It is how the thing is written where it comes from, and it is what a reader copies into a search box.', 'daw' ),
			),
			array(
				'id'      => 'daw_guide_hero_credit',
				'name'    => __( 'Lead image credit', 'daw' ),
				'type'    => 'text',
				'desc'    => __( 'Leave this empty unless the picture has a named owner to credit or shows something the reader should be told the name of. The strip under the image only appears when there is something in here, and "illustrative image" is not something: the line at the foot of every guide already says that.', 'daw' ),
			),
		)
	);
}

/* ------------------------------------------------------- at a glance ----- */

/**
 * The fact table, as free label and value pairs rather than named fields.
 *
 * A dish wants main ingredient, sauce base and heat level; a festival wants
 * when it falls and who celebrates it; a market wants opening hours and the
 * nearest station. Fixed fields would give every record the union of all of
 * them, mostly empty. The editor names the rows, and a row with no value is
 * simply not written.
 */
function daw_box_guide_glance() {
	return daw_guide_box(
		'daw_guide_glance',
		__( 'Guide: at a glance', 'daw' ),
		array(
			array(
				'id'         => 'daw_glance_label',
				'name'       => __( 'Row', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 4,
				'desc'       => __( 'Main ingredient, Heat level, When it falls, Nearest station.', 'daw' ),
			),
			array(
				'id'         => 'daw_glance_value',
				'name'       => __( 'Value', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 8,
				'desc'       => __( 'Row order must match the column on the left.', 'daw' ),
			),
		)
	);
}

/* --------------------------------------------------------- on screen ----- */

/**
 * Where a reader has seen this. It is the whole reason someone searches for
 * tteokbokki at eleven at night, and it is the link back into the database that
 * the rest of the site is built on.
 */
function daw_box_guide_onscreen() {
	return daw_guide_box(
		'daw_guide_onscreen',
		__( 'Guide: where you have seen it on screen', 'daw' ),
		array(
			array(
				'id'          => 'daw_onscreen_title',
				'name'        => __( 'Title', 'daw' ),
				'type'        => 'post',
				'post_type'   => 'post',
				'field_type'  => 'select_advanced',
				'clone'       => true,
				'sort_clone'  => true,
				'columns'     => 6,
				'placeholder' => __( 'Search a drama or film record…', 'daw' ),
				'query_args'  => array(
					'posts_per_page' => 20,
					'tax_query'      => array(
						array(
							'taxonomy' => 'dw_type',
							'field'    => 'slug',
							'terms'    => array( 'drama', 'film', 'variety' ),
						),
					),
				),
			),
			array(
				'id'         => 'daw_onscreen_note',
				'name'       => __( 'What happens in it', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 6,
				'desc'       => __( 'A few words, not a synopsis: "cart scene", "the wedding", "episode 4". Row order must match the column on the left.', 'daw' ),
			),
		)
	);
}

/* ---------------------------------------------------------- sourcing ----- */

function daw_box_guide_sources() {
	return daw_guide_box(
		'daw_guide_sourcing',
		__( 'Guide: sources', 'daw' ),
		array(
			array(
				'id'         => 'daw_guide_source_name',
				'name'       => __( 'Source', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 5,
				'desc'       => __( 'The institution, publication or archive, not a search result. A museum, a ministry, a national archive, a named encyclopaedia entry.', 'daw' ),
			),
			array(
				'id'         => 'daw_guide_source_url',
				'name'       => __( 'Link', 'daw' ),
				'type'       => 'url',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 7,
				'desc'       => __( 'Row order must match the column on the left. A claim about history with no source behind it does not go on the page.', 'daw' ),
			),
		)
	);
}

/* --------------------------------------------------------------- faq ----- */

function daw_box_guide_faq() {
	return daw_guide_box(
		'daw_guide_faq_box',
		__( 'Guide: frequently asked questions', 'daw' ),
		array(
			array(
				'id'         => 'daw_faq_q',
				'name'       => __( 'Question', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
			),
			array(
				'id'         => 'daw_faq_a',
				'name'       => __( 'Answer', 'daw' ),
				'type'       => 'textarea',
				'rows'       => 2,
				'clone'      => true,
				'sort_clone' => true,
			),
		)
	);
}

/* -------------------------------------------------------------- shop ----- */

function daw_box_guide_shop() {
	return daw_guide_box(
		'daw_guide_shop',
		__( 'Guide: make it at home', 'daw' ),
		array(
			array(
				'id'         => 'daw_shop_title',
				'name'       => __( 'Item', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 5,
			),
			array(
				'id'         => 'daw_shop_kind',
				'name'       => __( 'Kind', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 3,
				'desc'       => __( 'Pantry, Cookware, Book.', 'daw' ),
			),
			array(
				'id'         => 'daw_shop_url',
				'name'       => __( 'Link', 'daw' ),
				'type'       => 'url',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 4,
				'desc'       => __( 'Rendered with rel="sponsored nofollow" under a paid-link label. Only list what we would actually use for this subject.', 'daw' ),
			),
		)
	);
}

/* ===================================================== READ HELPERS ====== */

/** The rows of the at-a-glance table, empty values dropped. */
function daw_get_glance( $post_id ) {
	$rows = daw_zip_rows(
		$post_id,
		array(
			'label' => 'daw_glance_label',
			'value' => 'daw_glance_value',
		)
	);

	$out = array();
	foreach ( $rows as $row ) {
		if ( '' === trim( (string) $row['label'] ) || '' === trim( (string) $row['value'] ) ) {
			continue;
		}
		$out[] = $row;
	}

	return $out;
}

/** The titles this guide appears in, as [ id, note ]. */
function daw_get_onscreen( $post_id ) {
	$rows = daw_zip_rows(
		$post_id,
		array(
			'id'   => 'daw_onscreen_title',
			'note' => 'daw_onscreen_note',
		)
	);

	$out = array();
	foreach ( $rows as $row ) {
		$id = (int) $row['id'];
		if ( ! $id ) {
			continue;
		}
		$out[] = array(
			'id'   => $id,
			'note' => (string) $row['note'],
		);
	}

	return $out;
}

/** The sources, as [ name, url ]. A source with no name is not a source. */
function daw_get_guide_sources( $post_id ) {
	$rows = daw_zip_rows(
		$post_id,
		array(
			'name' => 'daw_guide_source_name',
			'url'  => 'daw_guide_source_url',
		)
	);

	$out = array();
	foreach ( $rows as $row ) {
		if ( '' === trim( (string) $row['name'] ) ) {
			continue;
		}
		$out[] = $row;
	}

	return $out;
}

/**
 * Other guides to read next.
 *
 * COUNTRY FIRST, BECAUSE THAT IS HOW A READER ARRIVES
 * --------------------------------------------------
 * Nobody lands on a page about tteokbokki and then wants a Thai festival.
 * They are in one country for the length of a visit, and the site is built so
 * that the whole guide section can be split by country later without moving a
 * single url. So the order is: the same kind of guide from the same country,
 * then any guide from that country, then the same kind from anywhere, then the
 * rest. Each step only runs if the one before it did not fill the list.
 */
function daw_get_related_guides( $post_id, $limit = 4 ) {
	$post_id = (int) $post_id;
	$type    = daw_get_type( $post_id );
	$country = daw_schema_first_term( $post_id, 'dw_country' );
	$country = $country ? $country->slug : '';

	$pick = static function ( $types, $country_slug, $n, $exclude ) {
		if ( $n < 1 ) {
			return array();
		}

		$tax = array(
			array(
				'taxonomy' => 'dw_type',
				'field'    => 'slug',
				'terms'    => $types,
			),
		);

		if ( '' !== $country_slug ) {
			$tax[] = array(
				'taxonomy' => 'dw_country',
				'field'    => 'slug',
				'terms'    => array( $country_slug ),
			);
		}

		if ( count( $tax ) > 1 ) {
			$tax['relation'] = 'AND';
		}

		return get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => $n,
				'post__not_in'     => $exclude,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
				'tax_query'        => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			)
		);
	};

	$all     = daw_guide_types();
	$mine    = $type ? array( $type ) : $all;
	$out     = array();
	$exclude = array( $post_id );

	foreach ( array(
		array( $mine, $country ),
		array( $all, $country ),
		array( $mine, '' ),
		array( $all, '' ),
	) as $step ) {
		if ( count( $out ) >= $limit ) {
			break;
		}

		$found   = $pick( $step[0], $step[1], $limit - count( $out ), $exclude );
		$out     = array_merge( $out, $found );
		$exclude = array_merge( $exclude, wp_list_pluck( $found, 'ID' ) );
	}

	return $out;
}

/**
 * The line above the headline: whose culture this is, and which family of
 * guide it belongs to. Derived, because a record already carries both facts
 * and a typed-in kicker is a third place for them to disagree.
 */
function daw_guide_kicker( $post_id ) {
	$labels = array(
		'culture' => __( 'culture', 'daw' ),
		'food'    => __( 'food', 'daw' ),
		'myth'    => __( 'myths', 'daw' ),
		'travel'  => __( 'travel', 'daw' ),
	);

	$kind    = $labels[ daw_get_type( $post_id ) ] ?? __( 'guide', 'daw' );
	$country = '';
	$terms   = get_the_terms( $post_id, 'dw_country' );

	if ( $terms && ! is_wp_error( $terms ) ) {
		$country = $terms[0]->name;

		// "South Korea food" is not a thing anyone says.
		$country = str_replace( array( 'South Korea', 'Hong Kong SAR' ), array( 'Korean', 'Hong Kong' ), $country );
	}

	return trim( $country . ' ' . $kind );
}

/**
 * Reading time in whole minutes.
 *
 * Two hundred words a minute is the conservative end of the usual range for
 * adult reading on screen, and rounding up means the number is never a promise
 * the page cannot keep.
 */
function daw_guide_read_time( $post_id ) {
	$post = get_post( $post_id );

	if ( ! $post ) {
		return 0;
	}

	$words = str_word_count( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );

	return max( 1, (int) ceil( $words / 200 ) );
}

/**
 * Give every H2 in the body an id and hand back the list for the contents box.
 *
 * WHY THE TEMPLATE DOES NOT ASK THE EDITOR FOR A CONTENTS LIST
 * -----------------------------------------------------------
 * A contents list typed by hand is a second copy of the headings, and the day
 * someone renames a section it becomes a list of links to nowhere. This reads
 * the headings that are actually in the article, so the two cannot drift.
 *
 * @param string $html Rendered post content.
 * @return array{html:string,rows:array} rows are [ id, text ]
 */
function daw_guide_contents( $html ) {
	$rows = array();
	$used = array();

	$html = preg_replace_callback(
		'#<h2\b([^>]*)>(.*?)</h2>#is',
		static function ( $m ) use ( &$rows, &$used ) {
			$text = trim( wp_strip_all_tags( $m[2] ) );

			if ( '' === $text ) {
				return $m[0];
			}

			// An id the editor already set wins: it may be linked from elsewhere.
			if ( preg_match( '/\bid=["\']([^"\']+)["\']/i', $m[1], $has ) ) {
				$id = $has[1];
			} else {
				$id = sanitize_title( $text );
				$n  = 2;
				while ( isset( $used[ $id ] ) ) {
					$id = sanitize_title( $text ) . '-' . $n;
					$n++;
				}
			}

			$used[ $id ] = true;
			$rows[]      = array(
				'id'   => $id,
				'text' => $text,
			);

			$attrs = preg_replace( '/\s*\bid=["\'][^"\']*["\']/i', '', $m[1] );

			return '<h2' . $attrs . ' id="' . esc_attr( $id ) . '">' . $m[2] . '</h2>';
		},
		$html
	);

	return array(
		'html' => $html,
		'rows' => $rows,
	);
}

/**
 * Put a block of markup into the article before the Nth H2.
 *
 * WHY NOT AT THE TOP, AND WHY NOT A SHORTCODE
 * -------------------------------------------
 * A fact table above the first paragraph turns the page into a form, and an
 * advert above the first paragraph is the thing readers install blockers for.
 * Both belong after the reader has been given something. A shortcode would put
 * the decision in the writer's hands, which sounds better than it is: fifty-
 * seven articles would each place it somewhere slightly different, and an
 * article that forgot it would silently lose its table.
 *
 * If the article has fewer than N headings, the block goes at the end, which is
 * still inside the column and still after the prose.
 *
 * @param string $html  Rendered content.
 * @param string $block Markup to insert.
 * @param int    $nth   Insert before this H2, counting from one.
 * @return string
 */
function daw_guide_inject( $html, $block, $nth = 2 ) {
	if ( '' === trim( (string) $block ) ) {
		return $html;
	}

	$count = 0;
	$done  = false;

	$out = preg_replace_callback(
		'#<h2\b#i',
		static function ( $m ) use ( &$count, &$done, $block, $nth ) {
			$count++;

			if ( $count === (int) $nth && ! $done ) {
				$done = true;

				return $block . $m[0];
			}

			return $m[0];
		},
		$html
	);

	return $done ? $out : $html . $block;
}
