<?php
/**
 * Official links, filled from Wikidata.
 *
 * WHY WIKIDATA AND NOT A SEARCH
 * -----------------------------
 * An Instagram account on a person's page is a claim about who somebody is. Get
 * it wrong and the site is pointing 245 profiles at strangers, which is a
 * correction at best and a legal letter at worst. So nothing here guesses: the
 * accounts come from Wikidata, where each one is a cited statement on an item,
 * and the item has to prove it is the right person before a single field is
 * written.
 *
 * THE PROOF
 * ---------
 * Two gates, both compulsory.
 *
 *  1. The name. One of the item's labels or aliases, in any language it
 *     publishes, has to equal the record's title, native name, real name or a
 *     known alternative, compared with the same folding the rest of the plugin
 *     uses.
 *  2. The date. The record's date of birth has to equal the item's, to the day.
 *     Korean entertainment is full of exact homonyms — there is more than one
 *     working actor called Kim Min-jae — and a birthday is the one field that
 *     separates them. A record with no date of birth is not filled at all. It
 *     goes on the review list instead, because a plausible guess is the exact
 *     failure this whole file exists to avoid.
 *
 * A record that passes both keeps the item's id in _daw_wikidata, so the next
 * person to wonder where a link came from can check it in one click.
 *
 * WHAT IT WILL NOT DO
 * -------------------
 * It never overwrites a field that already has something in it. An editor who
 * typed a link in by hand knows something this file does not, and a batch job
 * that silently replaces human work is a batch job nobody runs twice.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_WD_API   = 'https://www.wikidata.org/w/api.php';
const DAW_WD_AGENT = 'DiscoverAsianWorld/1.0 (https://discoverasianworld.com; links importer)';

/** Item ids that mean "this is a human". */
function daw_wd_kind_map() {
	return array(
		// Q5 is human. Nothing else will do for a person record.
		'person' => array( 'Q5' ),
		// Musical group, boy band, girl group, rock band, musical ensemble.
		'group'  => array( 'Q215380', 'Q281643', 'Q56816954', 'Q5741069', 'Q2088357' ),
	);
}

/**
 * The Wikidata properties worth carrying, and what each one becomes.
 *
 * MyDramaList is deliberately absent, for the same reason it is absent from the
 * template: it is the nearest thing this site has to a competitor and a link
 * from every profile would hand it the traffic the research earned.
 */
function daw_wd_property_map() {
	return array(
		'P2003' => array(
			'field'  => 'daw_social_instagram',
			'format' => 'https://www.instagram.com/%s/',
		),
		'P2002' => array(
			'field'  => 'daw_social_x',
			'format' => 'https://x.com/%s',
		),
		'P2013' => array(
			'field'  => 'daw_social_facebook',
			'format' => 'https://www.facebook.com/%s/',
		),
		'P2397' => array(
			'field'  => 'daw_social_youtube',
			'format' => 'https://www.youtube.com/channel/%s',
		),
		'P345'  => array(
			'field'  => 'daw_link_imdb',
			'format' => 'https://www.imdb.com/name/%s/',
			'expect' => '/^nm\d{5,}$/',
		),
	);
}

/** One request to the Wikidata API. Returns a decoded array, or an empty one. */
function daw_wd_get( array $args ) {
	$args['format'] = 'json';

	$res = wp_remote_get(
		add_query_arg( array_map( 'rawurlencode', $args ), DAW_WD_API ),
		array(
			'timeout'    => 20,
			'user-agent' => DAW_WD_AGENT,
			'headers'    => array( 'Accept' => 'application/json' ),
		)
	);

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $res ), true );

	return is_array( $body ) ? $body : array();
}

/** The same folding the news linker uses, so two files never disagree on a name. */
function daw_wd_fold( $text ) {
	if ( function_exists( 'daw_fold_title' ) ) {
		return daw_fold_title( $text );
	}

	$text = strtolower( wp_strip_all_tags( (string) $text ) );
	$text = strtr( $text, array( '’' => "'", '‘' => "'", '“' => '"', '”' => '"' ) );
	$text = preg_replace( '/[^a-z0-9\p{L}]+/u', ' ', $text );

	return trim( preg_replace( '/\s+/', ' ', $text ) );
}

/**
 * Every name this record is known by, folded.
 *
 * A Korean name reaches Wikidata in three or four romanisations at once, so the
 * record's own alternatives are worth as much as its title.
 */
function daw_wd_record_names( $post_id ) {
	$raw = array( get_the_title( $post_id ) );

	foreach ( array( 'daw_native_name', 'daw_real_name', 'daw_also_known_as' ) as $key ) {
		$val = daw_field_raw( $post_id, $key );

		if ( is_array( $val ) ) {
			$raw = array_merge( $raw, $val );
			continue;
		}

		// One field, several names: editors separate them with commas or lines.
		$raw = array_merge( $raw, preg_split( '/[,\n\r]+/', (string) $val ) );
	}

	$out = array();
	foreach ( $raw as $name ) {
		$folded = daw_wd_fold( $name );
		if ( '' !== $folded ) {
			$out[ $folded ] = true;
		}
	}

	return array_keys( $out );
}

/** Candidate item ids for a record, from a name search. */
function daw_wd_candidates( $post_id ) {
	$ids     = array();
	$queries = array( get_the_title( $post_id ) );

	$native = trim( (string) daw_field_raw( $post_id, 'daw_native_name' ) );
	if ( '' !== $native ) {
		$queries[] = $native;
	}

	foreach ( $queries as $q ) {
		$body = daw_wd_get(
			array(
				'action'   => 'wbsearchentities',
				'search'   => $q,
				'language' => 'en',
				'uselang'  => 'en',
				'type'     => 'item',
				'limit'    => 10,
			)
		);

		foreach ( $body['search'] ?? array() as $hit ) {
			if ( ! empty( $hit['id'] ) ) {
				$ids[ $hit['id'] ] = true;
			}
		}
	}

	return array_slice( array_keys( $ids ), 0, 20 );
}

/** Full items for a list of ids, in one request per fifty. */
function daw_wd_entities( array $ids ) {
	$out = array();

	foreach ( array_chunk( $ids, 25 ) as $chunk ) {
		$body = daw_wd_get(
			array(
				'action'     => 'wbgetentities',
				'ids'        => implode( '|', $chunk ),
				'props'      => 'labels|aliases|claims|sitelinks|descriptions',
				'languages'  => 'en|ko|ja|zh|th|mul',
				'sitefilter' => 'enwiki|kowiki',
			)
		);

		foreach ( $body['entities'] ?? array() as $id => $entity ) {
			if ( is_array( $entity ) && empty( $entity['missing'] ) ) {
				$out[ $id ] = $entity;
			}
		}
	}

	return $out;
}

/** The plain values of one property on an item, best rank first. */
function daw_wd_values( array $entity, $prop ) {
	$out = array();

	foreach ( $entity['claims'][ $prop ] ?? array() as $claim ) {
		if ( 'deprecated' === ( $claim['rank'] ?? '' ) ) {
			continue;
		}

		$snak = $claim['mainsnak'] ?? array();
		if ( 'value' !== ( $snak['snaktype'] ?? '' ) ) {
			continue;
		}

		$value = $snak['datavalue']['value'] ?? null;

		if ( is_array( $value ) ) {
			$value = $value['id'] ?? ( $value['time'] ?? null );
		}

		if ( null !== $value && '' !== $value ) {
			$out[] = $value;
		}
	}

	return $out;
}

/** Every label and alias an item publishes, folded. */
function daw_wd_entity_names( array $entity ) {
	$out = array();

	foreach ( $entity['labels'] ?? array() as $row ) {
		$out[ daw_wd_fold( $row['value'] ?? '' ) ] = true;
	}

	foreach ( $entity['aliases'] ?? array() as $rows ) {
		foreach ( (array) $rows as $row ) {
			$out[ daw_wd_fold( $row['value'] ?? '' ) ] = true;
		}
	}

	unset( $out[''] );

	return $out;
}

/**
 * The item's date of birth as Y-m-d, but only when it is stated to the day.
 * A year on its own cannot separate two actors born in the same year, which is
 * exactly the case this gate exists for, so it is treated as no date at all.
 */
function daw_wd_birth_day( array $entity ) {
	foreach ( $entity['claims']['P569'] ?? array() as $claim ) {
		$value = $claim['mainsnak']['datavalue']['value'] ?? array();

		if ( (int) ( $value['precision'] ?? 0 ) < 11 ) {
			continue;
		}

		if ( preg_match( '/^[+-](\d{4})-(\d{2})-(\d{2})/', (string) ( $value['time'] ?? '' ), $m ) ) {
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}
	}

	return '';
}

/**
 * Pick the one item that is certainly this record, or none.
 *
 * @return array{id:string,entity:array,reason:string}|array{id:string,entity:array,reason:string}[] Result with an empty id when nothing passed.
 */
function daw_wd_match( $post_id, array $entities ) {
	$type  = daw_get_type( $post_id );
	$kinds = daw_wd_kind_map()[ $type ] ?? array();
	$names = daw_wd_record_names( $post_id );
	$born  = (int) daw_field_raw( $post_id, 'daw_born' );
	$day   = $born ? gmdate( 'Y-m-d', $born ) : '';

	if ( ! $kinds ) {
		return array(
			'id'     => '',
			'entity' => array(),
			'reason' => __( 'Only person and group records can be matched.', 'daw' ),
		);
	}

	$passed  = array();
	$named   = array();
	$no_date = ( 'person' === $type && '' === $day );

	foreach ( $entities as $id => $entity ) {
		if ( ! array_intersect( $kinds, daw_wd_values( $entity, 'P31' ) ) ) {
			continue;
		}

		$item_names = daw_wd_entity_names( $entity );
		$hit        = false;

		foreach ( $names as $name ) {
			if ( isset( $item_names[ $name ] ) ) {
				$hit = true;
				break;
			}
		}

		if ( ! $hit ) {
			continue;
		}

		$named[] = $id;

		if ( 'person' === $type && daw_wd_birth_day( $entity ) !== $day ) {
			continue;
		}

		$passed[ $id ] = $entity;
	}

	// A record with no date of birth cannot be proved, however plausible the
	// one item that came back looks. The candidates are handed to the review
	// list instead, where a person can open them and decide.
	if ( $no_date ) {
		return array(
			'id'         => '',
			'entity'     => array(),
			'candidates' => $named,
			'reason'     => __( 'No date of birth on the record, so nothing can be proved. Candidates are listed for a human to check.', 'daw' ),
		);
	}

	if ( 1 === count( $passed ) ) {
		$id = (string) array_key_first( $passed );

		return array(
			'id'         => $id,
			'entity'     => $passed[ $id ],
			'candidates' => $named,
			'reason'     => '',
		);
	}

	if ( count( $passed ) > 1 ) {
		return array(
			'id'         => '',
			'entity'     => array(),
			'candidates' => array_keys( $passed ),
			'reason'     => sprintf(
				/* translators: %d: number of Wikidata items. */
				__( '%d items match the name and the date. Too close to call, so nothing was written.', 'daw' ),
				count( $passed )
			),
		);
	}

	return array(
		'id'         => '',
		'entity'     => array(),
		'candidates' => $named,
		'reason'     => $named
			? sprintf(
				/* translators: %d: number of Wikidata items. */
				__( '%d item(s) carry this name but none has the same date of birth.', 'daw' ),
				count( $named )
			)
			: __( 'No Wikidata item carries this name.', 'daw' ),
	);
}

/** field => url, read off a matched item. */
function daw_wd_links( array $entity ) {
	$links = array();

	foreach ( daw_wd_property_map() as $prop => $spec ) {
		$values = daw_wd_values( $entity, $prop );
		if ( ! $values ) {
			continue;
		}

		$value = ltrim( (string) $values[0], '@/' );

		if ( '' === $value ) {
			continue;
		}

		// An id in the wrong shape is a sign the item is not what we think it
		// is, so it is dropped rather than written.
		if ( isset( $spec['expect'] ) && ! preg_match( $spec['expect'], $value ) ) {
			continue;
		}

		$links[ $spec['field'] ] = sprintf( $spec['format'], rawurlencode( $value ) );
	}

	// The English article first, because the site is written in English and the
	// card names the edition it is sending the reader to.
	foreach ( array( 'enwiki' => 'en', 'kowiki' => 'ko' ) as $site => $lang ) {
		$title = $entity['sitelinks'][ $site ]['title'] ?? '';

		if ( '' !== $title ) {
			$links['daw_link_wikipedia'] = 'https://' . $lang . '.wikipedia.org/wiki/' . rawurlencode( str_replace( ' ', '_', $title ) );
			break;
		}
	}

	return $links;
}

/**
 * Fill one record.
 *
 * @param int  $post_id Record.
 * @param bool $replace Overwrite fields that already have a value.
 * @return array{written:array,skipped:array,reason:string,item:string}
 */
function daw_wd_fill( $post_id, $replace = false ) {
	$post_id = (int) $post_id;
	$out     = array(
		'written'    => array(),
		'skipped'    => array(),
		'candidates' => array(),
		'reason'     => '',
		'item'       => '',
	);

	// A record that has been matched before does not need finding again.
	$known = (string) get_post_meta( $post_id, '_daw_wikidata', true );
	$ids   = $known ? array( $known ) : daw_wd_candidates( $post_id );

	if ( ! $ids ) {
		$out['reason'] = __( 'Nothing came back from the Wikidata search.', 'daw' );
		update_post_meta( $post_id, '_daw_links_wd', time() );

		return $out;
	}

	$entities = daw_wd_entities( $ids );

	if ( $known && isset( $entities[ $known ] ) ) {
		$match = array(
			'id'         => $known,
			'entity'     => $entities[ $known ],
			'candidates' => array( $known ),
			'reason'     => '',
		);
	} else {
		$match = daw_wd_match( $post_id, $entities );
	}

	update_post_meta( $post_id, '_daw_links_wd', time() );

	$out['candidates'] = $match['candidates'] ?? array();

	if ( '' === $match['id'] ) {
		$out['reason'] = $match['reason'];

		return $out;
	}

	$out['item'] = $match['id'];
	update_post_meta( $post_id, '_daw_wikidata', $match['id'] );

	foreach ( daw_wd_links( $match['entity'] ) as $field => $url ) {
		$current = trim( (string) daw_field_raw( $post_id, $field ) );

		if ( '' !== $current && ! $replace ) {
			$out['skipped'][ $field ] = $current;
			continue;
		}

		update_post_meta( $post_id, $field, esc_url_raw( $url ) );
		$out['written'][ $field ] = $url;
	}

	return $out;
}

/** The note in the last column: why nothing was written, and what to look at. */
function daw_wd_note( array $res ) {
	if ( ! $res['reason'] ) {
		return $res['skipped']
			? esc_html( sprintf( '%d field(s) already filled', count( $res['skipped'] ) ) )
			: '';
	}

	$note  = esc_html( $res['reason'] );
	$links = array();

	foreach ( array_slice( $res['candidates'], 0, 5 ) as $qid ) {
		$links[] = sprintf(
			'<a href="https://www.wikidata.org/wiki/%1$s" target="_blank" rel="noopener">%1$s</a>',
			esc_html( $qid )
		);
	}

	return $links ? $note . ' ' . implode( ', ', $links ) : $note;
}

/* ================================================================== admin = */

/**
 * The records the button will work on: people and groups that are still missing
 * at least one link, oldest first so a long run makes visible progress.
 */
function daw_wd_queue( $all = false ) {
	$ids = get_posts(
		array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => false,
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => array( 'person', 'group' ),
				),
			),
		)
	);

	if ( $all ) {
		return $ids;
	}

	$queue = array();

	foreach ( $ids as $id ) {
		// Asked once, answered once. A record that has already been through
		// this does not get asked again until someone ticks the box.
		if ( get_post_meta( $id, '_daw_links_wd', true ) ) {
			continue;
		}

		$queue[] = $id;
	}

	return $queue;
}

add_action( 'admin_menu', 'daw_wd_admin_menu' );
function daw_wd_admin_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: Official links', 'daw' ),
		__( 'DAW: Official links', 'daw' ),
		'manage_options',
		'daw-links',
		'daw_wd_admin_page'
	);
}

function daw_wd_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="wrap"><h1>DAW: Official links</h1>';
	echo '<p>Instagram, X, Facebook, YouTube, IMDb and Wikipedia, read off Wikidata. A record is only filled when an item carries one of its names <em>and</em> the same date of birth, to the day. Everything else is listed below for a human to settle.</p>';

	$replace = isset( $_POST['daw_wd_replace'] );
	$again   = isset( $_POST['daw_wd_again'] );

	if ( isset( $_POST['daw_wd_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['daw_wd_nonce'] ), 'daw_wd' ) ) {
		$batch  = max( 1, min( 25, (int) ( $_POST['daw_wd_batch'] ?? 10 ) ) );
		$report = array();
		$filled = 0;

		foreach ( array_slice( daw_wd_queue( $again ), 0, $batch ) as $pid ) {
			$res             = daw_wd_fill( $pid, $replace );
			$report[ $pid ]  = $res;
			$filled         += count( $res['written'] ) ? 1 : 0;
		}

		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			$filled ? 'success' : 'warning',
			esc_html(
				sprintf(
					/* translators: 1: records filled, 2: records looked at. */
					__( 'Filled %1$d of the %2$d record(s) looked at.', 'daw' ),
					$filled,
					count( $report )
				)
			)
		);

		echo '<h2>What happened</h2><table class="widefat striped"><thead><tr><th>Record</th><th>Wikidata</th><th>Written</th><th>Left alone</th></tr></thead><tbody>';

		foreach ( $report as $pid => $res ) {
			$written = array();
			foreach ( $res['written'] as $field => $url ) {
				$written[] = str_replace( array( 'daw_social_', 'daw_link_' ), '', $field ) . ': ' . $url;
			}

			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( (string) get_edit_post_link( $pid ) ),
				esc_html( get_the_title( $pid ) ),
				$res['item']
					? sprintf( '<a href="https://www.wikidata.org/wiki/%1$s" target="_blank" rel="noopener">%1$s</a>', esc_html( $res['item'] ) )
					: '&mdash;',
				$written ? esc_html( implode( ' · ', $written ) ) : '&mdash;',
				wp_kses_post( daw_wd_note( $res ) )
			);
		}

		echo '</tbody></table>';
	}

	$pending = daw_wd_queue( $again );

	echo '<form method="post">';
	wp_nonce_field( 'daw_wd', 'daw_wd_nonce' );
	echo '<p><label>Do this many now: <input type="number" name="daw_wd_batch" value="10" min="1" max="25" style="width:5em"></label></p>';
	printf(
		'<p><label><input type="checkbox" name="daw_wd_replace" value="1" %s> %s</label></p>',
		checked( $replace, true, false ),
		esc_html__( 'Overwrite links that are already there', 'daw' )
	);
	printf(
		'<p><label><input type="checkbox" name="daw_wd_again" value="1" %s> %s</label></p>',
		checked( $again, true, false ),
		esc_html__( 'Go through every record again, including ones already tried', 'daw' )
	);
	submit_button( __( 'Fill links', 'daw' ) );
	echo '</form>';

	// HOW FAR THIS CAN GET BEFORE IT STARTS
	// -------------------------------------
	// The date of birth is the proof, so a record without one can never be
	// filled automatically however many times the button is pressed. Saying so
	// up front is the difference between a tool that looks broken and a tool
	// that has told you what it needs.
	$dated = 0;
	foreach ( $pending as $pid ) {
		if ( 'person' !== daw_get_type( $pid ) || (int) daw_field_raw( $pid, 'daw_born' ) ) {
			$dated++;
		}
	}

	printf(
		'<p><strong>%d</strong> record(s) waiting. <strong>%d</strong> of them carry a date of birth and can be proved; the other %d will be listed with their candidates for you to settle by hand.</p>',
		count( $pending ),
		$dated,
		count( $pending ) - $dated
	);

	echo '</div>';
}
