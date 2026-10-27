<?php
/**
 * Food on screen: which dish or drink appears in which drama or film.
 *
 * ONE ENTRY, TWO PAGES
 * --------------------
 * The match is typed once, on the food record, in _daw_food_seen_in. The drama
 * or film page never stores it: it reads _daw_food_idx, the same relationship
 * read backwards, exactly the way a filmography is read out of _daw_cast_idx.
 * Nobody keeps two lists that agree.
 *
 * WHAT A ROW IS
 * -------------
 * title_id (the drama or film record on this site), kind (dish, brand, drink)
 * and source_url (the page that proves the match) are required. season,
 * episode, scene, source_name and order are optional. A row with no source is
 * not saved: an unsourced "this dish was in that drama" is a rumour, and the
 * site does not print rumours (rule 1.1).
 *
 * WHAT THE INDEX COSTS
 * --------------------
 * A save touches only the titles that row set names, the old ones and the new
 * ones, so it is as many writes as titles affected and never a scan. The full
 * rebuild exists for a restore or an import and runs in batches of 50, because
 * 200 at a time returns a 504 on this server (rule 8.1).
 *
 * NOT TIED TO A CATEGORY
 * ----------------------
 * K-Food, J-Food and T-Food are categories today and other cuisines will come.
 * A food record is one whose dw_type is "food" or that sits in a category
 * listed by the daw_fos_food_categories filter; nothing else is hard coded.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_FOOD_META = '_daw_food_seen_in';
const DAW_FOOD_IDX  = '_daw_food_idx';
const DAW_FOOD_LAST = '_daw_food_last';

/* ================================================================ rules === */

function daw_fos_kinds() {
	// dish, brand, drink: food records. appears: any other guide (a myth, a
	// tradition, a place) that shows up in the title. Its label is empty on
	// purpose: the heading of the section already says what it is.
	return array( 'dish', 'brand', 'drink', 'appears' );
}

function daw_fos_kind_label( $kind ) {
	$map = array(
		'dish'  => __( 'Dish', 'daw' ),
		'brand' => __( 'Brand', 'daw' ),
		'drink' => __( 'Drink', 'daw' ),
	);

	return $map[ (string) $kind ] ?? '';
}

/** The record types that are a drama or a film, in the broad sense. */
function daw_fos_screen_types() {
	return (array) apply_filters( 'daw_fos_screen_types', array( 'drama', 'film', 'variety', 'anime' ) );
}

/** Is this record a dish or a drink? */
function daw_fos_is_food( $post_id ) {
	$post_id = (int) $post_id;
	if ( ! $post_id || 'post' !== get_post_type( $post_id ) ) {
		return false;
	}

	if ( in_array( daw_get_type( $post_id ), (array) apply_filters( 'daw_fos_food_types', array( 'food' ) ), true ) ) {
		return true;
	}

	$cats = array_map( 'intval', (array) apply_filters( 'daw_fos_food_categories', array( 988, 4517, 6896 ) ) );

	return $cats && has_category( $cats, $post_id );
}

/**
 * Any guide that can be linked to a drama or film: food, culture, myth, travel,
 * feature. The same rows, the same index; only the heading on the title page
 * changes with the kind of guide.
 */
function daw_fos_is_source( $post_id ) {
	$post_id = (int) $post_id;
	if ( ! $post_id || 'post' !== get_post_type( $post_id ) ) {
		return false;
	}

	return daw_fos_is_food( $post_id )
		|| in_array( daw_get_type( $post_id ), (array) apply_filters( 'daw_fos_source_types', array( 'food', 'culture', 'myth', 'travel', 'feature' ) ), true );
}

/** Which section of a title page a guide belongs in. */
function daw_fos_group_of( $post_id ) {
	if ( daw_fos_is_food( $post_id ) ) {
		return 'food';
	}
	$type = daw_get_type( (int) $post_id );

	return isset( daw_fos_group_labels()[ $type ] ) ? $type : 'feature';
}

/** Section headings on a drama or film page, in the order they print. */
function daw_fos_group_labels() {
	return (array) apply_filters(
		'daw_fos_group_labels',
		array(
			'food'    => __( 'Food on screen', 'daw' ),
			'culture' => __( 'Culture on screen', 'daw' ),
			'myth'    => __( 'Myths and legends on screen', 'daw' ),
			'travel'  => __( 'Places on screen', 'daw' ),
			'feature' => __( 'In our guides', 'daw' ),
		)
	);
}

/** A drama or film record exists with this ID, whatever its status. */
function daw_fos_title_exists( $title_id ) {
	$title_id = (int) $title_id;

	return $title_id > 0
		&& 'post' === get_post_type( $title_id )
		&& in_array( daw_get_type( $title_id ), daw_fos_screen_types(), true );
}

/** A drama or film record that is published, so a card may link to it. */
function daw_fos_title_live( $title_id ) {
	return 'publish' === get_post_status( (int) $title_id ) && daw_fos_title_exists( $title_id );
}

/* =============================================================== cleaning === */

/**
 * The only door the rows go through, from the admin box, REST and MCP alike.
 *
 * @param mixed      $rows    Anything; a non-array becomes an empty list.
 * @param array|null $dropped Receives counts of what was refused and why.
 * @return array
 */
function daw_fos_clean( $rows, &$dropped = null ) {
	$dropped = array(
		'no_source' => 0,
		'bad_title' => 0,
		'bad_kind'  => 0,
		'duplicate' => 0,
	);
	$out     = array();
	$seen    = array();

	if ( ! is_array( $rows ) ) {
		return $out;
	}

	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}

		$url = esc_url_raw( trim( (string) ( $r['source_url'] ?? '' ) ), array( 'http', 'https' ) );
		if ( '' === $url ) {
			++$dropped['no_source'];
			continue;
		}

		$tid = (int) ( $r['title_id'] ?? 0 );
		if ( ! daw_fos_title_exists( $tid ) ) {
			++$dropped['bad_title'];
			continue;
		}

		$kind = (string) ( $r['kind'] ?? '' );
		if ( ! in_array( $kind, daw_fos_kinds(), true ) ) {
			++$dropped['bad_kind'];
			continue;
		}

		$season  = max( 0, (int) ( $r['season'] ?? 0 ) );
		$episode = max( 0, (int) ( $r['episode'] ?? 0 ) );
		$scene   = sanitize_text_field( (string) ( $r['scene'] ?? '' ) );
		// No em dash in anything that is published (rule 5.1).
		$scene = trim( str_replace( "\xE2\x80\x94", ', ', $scene ) );
		$scene = function_exists( 'mb_substr' ) ? mb_substr( $scene, 0, 200 ) : substr( $scene, 0, 200 );
		$name  = sanitize_text_field( (string) ( $r['source_name'] ?? '' ) );
		$name  = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 120 ) : substr( $name, 0, 120 );
		$order = max( 0, (int) ( $r['order'] ?? 0 ) );

		$key = $tid . '|' . $season . '|' . $episode . '|' . strtolower( $scene );
		if ( isset( $seen[ $key ] ) ) {
			++$dropped['duplicate'];
			continue;
		}
		$seen[ $key ] = true;

		$row = array(
			'title_id'   => $tid,
			'kind'       => $kind,
			'source_url' => $url,
		);
		if ( $season ) {
			$row['season'] = $season;
		}
		if ( $episode ) {
			$row['episode'] = $episode;
		}
		if ( '' !== $scene ) {
			$row['scene'] = $scene;
		}
		if ( '' !== $name ) {
			$row['source_name'] = $name;
		}
		if ( $order ) {
			$row['order'] = $order;
		}

		$out[] = $row;
	}

	return $out;
}

/** The stored rows of a food record, cleaned. */
function daw_fos_rows( $food_id ) {
	$raw = get_post_meta( (int) $food_id, DAW_FOOD_META, true );

	return is_array( $raw ) ? daw_fos_clean( $raw ) : array();
}

/* ============================================================ REST meta === */

add_action( 'init', 'daw_fos_register_meta', 20 );
function daw_fos_register_meta() {
	register_post_meta(
		'post',
		DAW_FOOD_META,
		array(
			'type'              => 'array',
			'single'            => true,
			'description'       => 'Dramas and films this dish or drink appears in.',
			'show_in_rest'      => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'title_id', 'kind', 'source_url' ),
						'properties'           => array(
							'title_id'    => array( 'type' => 'integer', 'minimum' => 1 ),
							'season'      => array( 'type' => 'integer', 'minimum' => 0 ),
							'episode'     => array( 'type' => 'integer', 'minimum' => 0 ),
							'scene'       => array( 'type' => 'string', 'maxLength' => 200 ),
							'kind'        => array( 'type' => 'string', 'enum' => daw_fos_kinds() ),
							// An empty source fails here, so REST answers 400
							// instead of quietly dropping the row.
							'source_url'  => array( 'type' => 'string', 'format' => 'uri', 'minLength' => 1 ),
							'source_name' => array( 'type' => 'string', 'maxLength' => 120 ),
							'order'       => array( 'type' => 'integer', 'minimum' => 0 ),
						),
					),
				),
			),
			'sanitize_callback' => static function ( $value ) {
				return daw_fos_clean( $value );
			},
			'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
				return current_user_can( 'edit_post', (int) $post_id );
			},
		)
	);
}

/* =============================================================== caching === */

/** Cache keys carry the shape and the code version (K20). */
function daw_fos_ckey( $kind, $post_id ) {
	return 'daw_food_v1_' . str_replace( '.', '', DAW_VERSION ) . '_' . $kind . (int) $post_id;
}

function daw_fos_forget( $post_id ) {
	delete_transient( daw_fos_ckey( 'f', $post_id ) );
	delete_transient( daw_fos_ckey( 't', $post_id ) );
}

/** One DELETE for the whole family; used once per batch, never per record. */
function daw_fos_flush() {
	global $wpdb;

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_daw\_food\_%' OR option_name LIKE '\_transient\_timeout\_daw\_food\_%'"
	);
}

/* ================================================================ index === */

/**
 * Put one food record's rows for one title into that title's index, replacing
 * whatever this food had there before.
 */
function daw_fos_idx_apply( $title_id, $food_id, array $rows ) {
	$title_id = (int) $title_id;
	$food_id  = (int) $food_id;
	$idx      = get_post_meta( $title_id, DAW_FOOD_IDX, true );
	$idx      = is_array( $idx ) ? $idx : array();

	$keep = array();
	foreach ( $idx as $e ) {
		if ( is_array( $e ) && (int) ( $e['food_id'] ?? 0 ) !== $food_id ) {
			$keep[] = $e;
		}
	}

	foreach ( $rows as $r ) {
		$keep[] = array(
			'food_id' => $food_id,
			'season'  => (int) ( $r['season'] ?? 0 ),
			'episode' => (int) ( $r['episode'] ?? 0 ),
			'scene'   => (string) ( $r['scene'] ?? '' ),
			'kind'    => (string) $r['kind'],
		);
	}

	if ( $keep ) {
		if ( $keep !== $idx ) {
			update_post_meta( $title_id, DAW_FOOD_IDX, $keep );
		}
	} else {
		delete_post_meta( $title_id, DAW_FOOD_IDX );
	}
}

/**
 * Bring every index in line with this food record as it is now. A record that
 * is not published, or not a food record, contributes nothing.
 */
function daw_fos_reindex_food( $food_id ) {
	$food_id = (int) $food_id;
	if ( ! $food_id || wp_is_post_revision( $food_id ) ) {
		return;
	}

	$GLOBALS['daw_fos_ran'][ $food_id ] = true;

	$rows = array();
	if ( 'publish' === get_post_status( $food_id ) && daw_fos_is_source( $food_id ) ) {
		$rows = daw_fos_rows( $food_id );

		// Rows typed the older way (title + note, no source) are indexed too, so
		// a title page can list the guide today without waiting for anyone to
		// retype them. They are read, never copied or changed here, and a title
		// the new rows already carry is not counted twice.
		$food_kind = daw_fos_is_food( $food_id ) ? 'dish' : 'appears';
		foreach ( daw_fos_legacy_pending( $food_id ) as $lr ) {
			$rows[] = array(
				'title_id' => $lr[0],
				'kind'     => $food_kind,
				'scene'    => function_exists( 'mb_substr' ) ? mb_substr( $lr[1], 0, 200 ) : substr( $lr[1], 0, 200 ),
			);
		}
	}

	$by_title = array();
	foreach ( $rows as $r ) {
		$by_title[ (int) $r['title_id'] ][] = $r;
	}

	$old = array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $food_id, DAW_FOOD_LAST, true ) ) ) );
	$all = array_unique( array_merge( array_keys( $by_title ), $old ) );

	foreach ( $all as $tid ) {
		daw_fos_idx_apply( $tid, $food_id, $by_title[ $tid ] ?? array() );
	}

	if ( $by_title ) {
		update_post_meta( $food_id, DAW_FOOD_LAST, implode( ',', array_keys( $by_title ) ) );
	} else {
		delete_post_meta( $food_id, DAW_FOOD_LAST );
	}

	if ( empty( $GLOBALS['daw_fos_batch'] ) ) {
		daw_fos_forget( $food_id );
		foreach ( $all as $tid ) {
			daw_fos_forget( $tid );
		}
	}
}

/** Saved from the REST API, MCP or the admin box: the meta hooks see all three. */
add_action( 'added_post_meta', 'daw_fos_on_meta', 10, 3 );
add_action( 'updated_post_meta', 'daw_fos_on_meta', 10, 3 );
add_action( 'deleted_post_meta', 'daw_fos_on_meta', 10, 3 );
function daw_fos_on_meta( $meta_id, $post_id, $meta_key ) {
	if ( DAW_FOOD_META === $meta_key || DAW_FOS_OLD_TITLE === $meta_key || DAW_FOS_OLD_NOTE === $meta_key ) {
		daw_fos_reindex_food( $post_id );
	}
}

/** Catches a category or type change, which does not touch the meta. */
add_action( 'save_post_post', 'daw_fos_on_save', 40, 1 );
function daw_fos_on_save( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! empty( $GLOBALS['daw_fos_ran'][ $post_id ] ) ) {
		return;
	}
	if ( metadata_exists( 'post', $post_id, DAW_FOOD_META ) || metadata_exists( 'post', $post_id, DAW_FOOD_LAST ) || metadata_exists( 'post', $post_id, DAW_FOS_OLD_TITLE ) ) {
		daw_fos_reindex_food( $post_id );
	}
}

/**
 * Publishing, unpublishing, trashing and restoring.
 * A food record leaving publish drops out of every title's index; a title
 * leaving publish only needs its cards forgotten, the rows are kept so the card
 * comes back if the title does.
 */
add_action( 'transition_post_status', 'daw_fos_on_status', 10, 3 );
function daw_fos_on_status( $new, $old, $post ) {
	if ( 'post' !== $post->post_type || ( 'publish' === $new ) === ( 'publish' === $old ) ) {
		return;
	}

	if ( metadata_exists( 'post', $post->ID, DAW_FOOD_META ) || metadata_exists( 'post', $post->ID, DAW_FOOD_LAST ) || metadata_exists( 'post', $post->ID, DAW_FOS_OLD_TITLE ) ) {
		daw_fos_reindex_food( $post->ID );
	}

	// A title changing state: forget its own cards and those of every food
	// that points at it.
	$idx = get_post_meta( $post->ID, DAW_FOOD_IDX, true );
	if ( is_array( $idx ) ) {
		daw_fos_forget( $post->ID );
		foreach ( $idx as $e ) {
			if ( ! empty( $e['food_id'] ) ) {
				daw_fos_forget( $e['food_id'] );
			}
		}
	}
}

add_action( 'before_delete_post', 'daw_fos_on_delete', 10, 1 );
function daw_fos_on_delete( $post_id ) {
	if ( 'post' !== get_post_type( $post_id ) ) {
		return;
	}
	$old = array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $post_id, DAW_FOOD_LAST, true ) ) ) );
	foreach ( $old as $tid ) {
		daw_fos_idx_apply( $tid, $post_id, array() );
		daw_fos_forget( $tid );
	}
}

/* ================================================================ readers === */

/**
 * For a food page: the titles it appears in, grouped, newest first unless an
 * order was typed. Only published titles. Empty array means the section is not
 * printed at all.
 *
 * @return array [ [ title_id, scenes => [ [season, episode, scene, kind, source_url, source_name], … ] ], … ]
 */
function daw_fos_seen_list( $food_id ) {
	$food_id = (int) $food_id;
	$key     = daw_fos_ckey( 'f', $food_id );
	$cached  = get_transient( $key );

	if ( is_array( $cached ) ) {
		$list = $cached;
	} else {
		$groups = array();
		foreach ( daw_fos_rows( $food_id ) as $r ) {
			$groups[ (int) $r['title_id'] ][] = $r;
		}

		$list = array();
		foreach ( $groups as $tid => $scenes ) {
			$order = 0;
			foreach ( $scenes as $s ) {
				if ( ! empty( $s['order'] ) ) {
					$order = $order ? min( $order, (int) $s['order'] ) : (int) $s['order'];
				}
			}
			usort(
				$scenes,
				static function ( $a, $b ) {
					$ae = (int) ( $a['episode'] ?? 0 );
					$be = (int) ( $b['episode'] ?? 0 );
					return ( $ae ? 0 : 1 ) <=> ( $be ? 0 : 1 ) ?: ( (int) ( $a['season'] ?? 0 ) <=> (int) ( $b['season'] ?? 0 ) ) ?: ( $ae <=> $be );
				}
			);
			$list[] = array(
				'title_id' => $tid,
				'order'    => $order,
				'scenes'   => $scenes,
			);
		}

		set_transient( $key, $list, 6 * HOUR_IN_SECONDS );
	}

	// Status is read live: a title that has been unpublished is not shown, and
	// its row is kept.
	$out = array();
	foreach ( $list as $item ) {
		if ( daw_fos_title_live( $item['title_id'] ) ) {
			$start        = (int) daw_field_raw( $item['title_id'], 'daw_aired_start' );
			$item['year'] = $start > 0 ? (int) gmdate( 'Y', $start ) : 0;
			$out[]        = $item;
		}
	}

	usort(
		$out,
		static function ( $a, $b ) {
			$ao = $a['order'] ? 0 : 1;
			$bo = $b['order'] ? 0 : 1;
			return ( $ao <=> $bo ) ?: ( $a['order'] <=> $b['order'] ) ?: ( ( $b['year'] ?: 0 ) <=> ( $a['year'] ?: 0 ) );
		}
	);

	return $out;
}

/**
 * For a drama or film page: the foods linked to it, read from the index.
 * Episode order, episode-less last. Published food records only.
 *
 * @return array [ [ food_id, season, episode, scene, kind ], … ]
 */
function daw_fos_title_foods( $title_id ) {
	$title_id = (int) $title_id;
	$key      = daw_fos_ckey( 't', $title_id );
	$cached   = get_transient( $key );

	if ( ! is_array( $cached ) ) {
		$idx    = get_post_meta( $title_id, DAW_FOOD_IDX, true );
		$cached = array();

		if ( is_array( $idx ) ) {
			foreach ( $idx as $pos => $e ) {
				if ( is_array( $e ) && ! empty( $e['food_id'] ) ) {
					$e['pos']  = $pos;
					$cached[] = $e;
				}
			}
			usort(
				$cached,
				static function ( $a, $b ) {
					$ae = (int) $a['episode'];
					$be = (int) $b['episode'];
					return ( $ae ? 0 : 1 ) <=> ( $be ? 0 : 1 )
						?: ( (int) $a['season'] <=> (int) $b['season'] )
						?: ( $ae <=> $be )
						?: ( $a['pos'] <=> $b['pos'] );
				}
			);
		}

		set_transient( $key, $cached, 6 * HOUR_IN_SECONDS );
	}

	$out = array();
	foreach ( $cached as $e ) {
		$fid = (int) $e['food_id'];
		if ( 'publish' === get_post_status( $fid ) && daw_fos_is_source( $fid ) ) {
			$e['group'] = daw_fos_group_of( $fid );
			$out[]      = $e;
		}
	}

	return $out;
}

/** "S2 Ep 4", "Ep 4", "Season 2": whatever is known, nothing when nothing is. */
function daw_fos_ep_label( $season, $episode ) {
	$season  = (int) $season;
	$episode = (int) $episode;

	if ( $season && $episode ) {
		/* translators: 1: season number, 2: episode number */
		return sprintf( __( 'S%1$d Ep %2$d', 'daw' ), $season, $episode );
	}
	if ( $episode ) {
		/* translators: %d: episode number */
		return sprintf( __( 'Ep %d', 'daw' ), $episode );
	}
	if ( $season ) {
		/* translators: %d: season number */
		return sprintf( __( 'Season %d', 'daw' ), $season );
	}

	return '';
}

/** The ItemList for a food page, or null when there is nothing to list. */
function daw_fos_schema_node( $food_id ) {
	$items = array();
	$pos   = 0;

	foreach ( daw_fos_seen_list( $food_id ) as $item ) {
		$tid = (int) $item['title_id'];
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => ++$pos,
			'url'      => get_permalink( $tid ),
			'name'     => wp_strip_all_tags( get_the_title( $tid ) ),
		);
	}

	if ( ! $items ) {
		return null;
	}

	return array(
		'@type'           => 'ItemList',
		'@id'             => get_permalink( $food_id ) . '#seen-in',
		'name'            => __( 'Seen in K-Dramas and movies', 'daw' ),
		'itemListElement' => $items,
	);
}

/* ============================================================== admin box === */

add_action( 'add_meta_boxes_post', 'daw_fos_add_boxes' );
function daw_fos_add_boxes( $post ) {
	if ( daw_fos_is_source( $post->ID ) ) {
		add_meta_box( 'daw_fos_food', __( 'Seen in (dramas and movies)', 'daw' ), 'daw_fos_box_food', 'post', 'normal', 'default' );
	} elseif ( daw_fos_title_exists( $post->ID ) ) {
		add_meta_box( 'daw_fos_title', __( 'Guides linked to this title (food, culture, myths, places)', 'daw' ), 'daw_fos_box_title', 'post', 'side', 'default' );
	}
}

function daw_fos_box_food( $post ) {
	wp_nonce_field( 'daw_fos_save_' . $post->ID, 'daw_fos_nonce' );

	$rows = daw_fos_rows( $post->ID );
	$tpl  = array(
		'title_id'    => 0,
		'kind'        => 'dish',
		'season'      => '',
		'episode'     => '',
		'scene'       => '',
		'source_url'  => '',
		'source_name' => '',
		'order'       => '',
	);

	$notice = get_transient( 'daw_fos_notice_' . $post->ID . '_' . get_current_user_id() );
	if ( $notice ) {
		delete_transient( 'daw_fos_notice_' . $post->ID . '_' . get_current_user_id() );
		echo '<div class="notice notice-warning inline"><p>' . esc_html( $notice ) . '</p></div>';
	}
	?>
	<p class="description">
		<?php esc_html_e( 'One row per scene. A drama or film with no record on this site cannot be added. A row with no source link is not saved.', 'daw' ); ?>
	</p>
	<div id="daw-fos-rows" data-next="<?php echo esc_attr( count( $rows ) + 1 ); ?>">
		<?php
		foreach ( $rows as $i => $r ) {
			daw_fos_box_row( $i, array_merge( $tpl, $r ) );
		}
		?>
	</div>
	<p><button type="button" class="button" id="daw-fos-add"><?php esc_html_e( 'Add row', 'daw' ); ?></button></p>

	<script type="text/template" id="daw-fos-tpl">
		<?php daw_fos_box_row( '__N__', $tpl ); ?>
	</script>
	<style>
		.daw-fos-row{display:grid;grid-template-columns:24px minmax(160px,2fr) 70px 70px 100px 2fr;gap:6px 8px;align-items:center;padding:10px;border:1px solid #c3c4c7;background:#fff;margin:0 0 8px}
		.daw-fos-row .daw-fos-wide{grid-column:2 / -1;display:grid;grid-template-columns:2fr 1fr 70px auto;gap:8px}
		.daw-fos-row input[type=text],.daw-fos-row input[type=url],.daw-fos-row input[type=number],.daw-fos-row select{width:100%;min-width:0}
		.daw-fos-grip{cursor:grab;text-align:center;color:#787c82;user-select:none}
		.daw-fos-row.is-over{outline:2px dashed #2271b1}
		.daw-fos-sugg{position:absolute;z-index:9;background:#fff;border:1px solid #c3c4c7;box-shadow:0 2px 6px rgba(0,0,0,.15);max-height:220px;overflow:auto;min-width:260px}
		.daw-fos-sugg button{display:block;width:100%;text-align:left;border:0;background:none;padding:6px 10px;cursor:pointer}
		.daw-fos-sugg button:hover,.daw-fos-sugg button:focus{background:#f0f6fc}
		.daw-fos-t{position:relative}
		@media (max-width:782px){.daw-fos-row{grid-template-columns:24px 1fr 1fr}.daw-fos-row .daw-fos-wide{grid-template-columns:1fr}}
	</style>
	<script>
	(function(){
		var wrap=document.getElementById('daw-fos-rows'),tpl=document.getElementById('daw-fos-tpl'),n=parseInt(wrap.dataset.next,10)||1,
			ajax=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,nonce=<?php echo wp_json_encode( wp_create_nonce( 'daw_fos_search' ) ); ?>,dragging=null,timer=null;
		function add(){var d=document.createElement('div');d.innerHTML=tpl.innerHTML.replace(/__N__/g,'n'+(n++)).trim();var r=d.firstChild;wrap.appendChild(r);return r;}
		document.getElementById('daw-fos-add').addEventListener('click',function(){add().querySelector('.daw-fos-q').focus();});
		function renumber(){var rows=wrap.querySelectorAll('.daw-fos-row');for(var i=0;i<rows.length;i++){var o=rows[i].querySelector('.daw-fos-order');if(o){o.value=i+1;}}}
		wrap.addEventListener('click',function(e){
			var row=e.target.closest('.daw-fos-row');if(!row){return;}
			if(e.target.classList.contains('daw-fos-del')){row.remove();}
			if(e.target.classList.contains('daw-fos-up')&&row.previousElementSibling){wrap.insertBefore(row,row.previousElementSibling);renumber();}
			if(e.target.classList.contains('daw-fos-down')&&row.nextElementSibling){wrap.insertBefore(row.nextElementSibling,row);renumber();}
			var b=e.target.closest('.daw-fos-sugg button');
			if(b){row.querySelector('.daw-fos-id').value=b.dataset.id;row.querySelector('.daw-fos-q').value=b.dataset.title;var s=row.querySelector('.daw-fos-sugg');if(s){s.remove();}}
		});
		wrap.addEventListener('input',function(e){
			if(!e.target.classList.contains('daw-fos-q')){return;}
			var row=e.target.closest('.daw-fos-row'),q=e.target.value.trim();
			row.querySelector('.daw-fos-id').value='';
			clearTimeout(timer);
			var old=row.querySelector('.daw-fos-sugg');if(old){old.remove();}
			if(q.length<2){return;}
			timer=setTimeout(function(){
				fetch(ajax+'?action=daw_fos_search&_wpnonce='+nonce+'&q='+encodeURIComponent(q),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
					var o=row.querySelector('.daw-fos-sugg');if(o){o.remove();}
					if(!j||!j.success||!j.data.length){return;}
					var box=document.createElement('div');box.className='daw-fos-sugg';
					j.data.forEach(function(t){var b=document.createElement('button');b.type='button';b.dataset.id=t.id;b.dataset.title=t.title;b.textContent=t.label;box.appendChild(b);});
					row.querySelector('.daw-fos-t').appendChild(box);
				});
			},250);
		});
		wrap.addEventListener('dragstart',function(e){var g=e.target.closest('.daw-fos-row');if(g&&e.target.classList.contains('daw-fos-grip')){dragging=g;e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain','row');}});
		wrap.addEventListener('dragover',function(e){var r=e.target.closest('.daw-fos-row');if(dragging&&r&&r!==dragging){e.preventDefault();r.classList.add('is-over');}});
		wrap.addEventListener('dragleave',function(e){var r=e.target.closest('.daw-fos-row');if(r){r.classList.remove('is-over');}});
		wrap.addEventListener('drop',function(e){var r=e.target.closest('.daw-fos-row');if(dragging&&r&&r!==dragging){e.preventDefault();r.classList.remove('is-over');var after=dragging.compareDocumentPosition(r)&Node.DOCUMENT_POSITION_FOLLOWING;wrap.insertBefore(dragging,after?r.nextSibling:r);renumber();}dragging=null;});
		wrap.addEventListener('dragend',function(){dragging=null;});
	})();
	</script>
	<?php
}

function daw_fos_box_row( $i, array $r ) {
	$title = $r['title_id'] ? get_the_title( (int) $r['title_id'] ) : '';
	$n     = esc_attr( (string) $i );
	?>
	<div class="daw-fos-row">
		<span class="daw-fos-grip" draggable="true" title="<?php esc_attr_e( 'Drag to reorder', 'daw' ); ?>" aria-hidden="true">&#8942;&#8942;</span>
		<div class="daw-fos-t">
			<input type="hidden" class="daw-fos-id" name="daw_fos[<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>][title_id]" value="<?php echo esc_attr( (string) (int) $r['title_id'] ); ?>">
			<input type="text" class="daw-fos-q" autocomplete="off" placeholder="<?php esc_attr_e( 'Search a published drama or film', 'daw' ); ?>" value="<?php echo esc_attr( $title ); ?>" aria-label="<?php esc_attr_e( 'Drama or film', 'daw' ); ?>">
		</div>
		<input type="number" min="0" name="daw_fos[<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>][season]" value="<?php echo esc_attr( (string) $r['season'] ); ?>" placeholder="<?php esc_attr_e( 'Season', 'daw' ); ?>" aria-label="<?php esc_attr_e( 'Season', 'daw' ); ?>">
		<input type="number" min="0" name="daw_fos[<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>][episode]" value="<?php echo esc_attr( (string) $r['episode'] ); ?>" placeholder="<?php esc_attr_e( 'Episode', 'daw' ); ?>" aria-label="<?php esc_attr_e( 'Episode', 'daw' ); ?>">
		<select name="daw_fos[<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>][kind]" aria-label="<?php esc_attr_e( 'Kind', 'daw' ); ?>">
			<?php foreach ( daw_fos_kinds() as $k ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $r['kind'], $k ); ?>><?php echo esc_html( daw_fos_kind_label( $k ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="text" maxlength="200" name="daw_fos[<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>][scene]" value="<?php echo esc_attr( (string) $r['scene'] ); ?>" placeholder="<?php esc_attr_e( 'Scene note, in English, up to 200 characters', 'daw' ); ?>" aria-label="<?php esc_attr_e( 'Scene', 'daw' ); ?>">
		<div class="daw-fos-wide">
			<input type="url" name="daw_fos[<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>][source_url]" value="<?php echo esc_attr( (string) $r['source_url'] ); ?>" placeholder="<?php esc_attr_e( 'Source link (required)', 'daw' ); ?>" aria-label="<?php esc_attr_e( 'Source link', 'daw' ); ?>">
			<input type="text" maxlength="120" name="daw_fos[<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>][source_name]" value="<?php echo esc_attr( (string) $r['source_name'] ); ?>" placeholder="<?php esc_attr_e( 'Source name', 'daw' ); ?>" aria-label="<?php esc_attr_e( 'Source name', 'daw' ); ?>">
			<input type="number" min="0" class="daw-fos-order" name="daw_fos[<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>][order]" value="<?php echo esc_attr( (string) $r['order'] ); ?>" placeholder="<?php esc_attr_e( 'Order', 'daw' ); ?>" aria-label="<?php esc_attr_e( 'Order', 'daw' ); ?>">
			<span>
				<button type="button" class="button-link daw-fos-up" aria-label="<?php esc_attr_e( 'Move up', 'daw' ); ?>">&uarr;</button>
				<button type="button" class="button-link daw-fos-down" aria-label="<?php esc_attr_e( 'Move down', 'daw' ); ?>">&darr;</button>
				<button type="button" class="button-link button-link-delete daw-fos-del"><?php esc_html_e( 'Delete', 'daw' ); ?></button>
			</span>
		</div>
	</div>
	<?php
}

add_action( 'save_post_post', 'daw_fos_save_box', 20, 1 );
function daw_fos_save_box( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( empty( $_POST['daw_fos_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['daw_fos_nonce'] ) ), 'daw_fos_save_' . $post_id ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) || ! daw_fos_is_source( $post_id ) ) {
		return;
	}

	$posted  = isset( $_POST['daw_fos'] ) && is_array( $_POST['daw_fos'] ) ? wp_unslash( $_POST['daw_fos'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$rows    = array();
	$blank   = 0;
	foreach ( $posted as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		// A row left entirely empty is not a mistake worth a warning.
		if ( empty( $r['title_id'] ) && '' === trim( (string) ( $r['source_url'] ?? '' ) ) && '' === trim( (string) ( $r['scene'] ?? '' ) ) ) {
			++$blank;
			continue;
		}
		$rows[] = $r;
	}

	$clean = daw_fos_clean( $rows, $dropped );

	if ( $clean ) {
		update_post_meta( $post_id, DAW_FOOD_META, $clean );
	} else {
		delete_post_meta( $post_id, DAW_FOOD_META );
	}
	// The meta hook ran only if the value changed; an unchanged save still
	// has to leave the index right.
	if ( empty( $GLOBALS['daw_fos_ran'][ $post_id ] ) ) {
		daw_fos_reindex_food( $post_id );
	}

	$msg = array();
	if ( $dropped['no_source'] ) {
		/* translators: %d: number of rows */
		$msg[] = sprintf( _n( '%d row was not saved: it has no source link.', '%d rows were not saved: they have no source link.', $dropped['no_source'], 'daw' ), $dropped['no_source'] );
	}
	if ( $dropped['bad_title'] ) {
		/* translators: %d: number of rows */
		$msg[] = sprintf( _n( '%d row was not saved: pick a drama or film from the suggestions.', '%d rows were not saved: pick a drama or film from the suggestions.', $dropped['bad_title'], 'daw' ), $dropped['bad_title'] );
	}
	if ( $dropped['duplicate'] ) {
		/* translators: %d: number of rows */
		$msg[] = sprintf( _n( '%d duplicate row was dropped.', '%d duplicate rows were dropped.', $dropped['duplicate'], 'daw' ), $dropped['duplicate'] );
	}
	if ( $msg ) {
		set_transient( 'daw_fos_notice_' . $post_id . '_' . get_current_user_id(), implode( ' ', $msg ), 120 );
	}
}

/** Read-only: what the index says about this title. */
function daw_fos_box_title( $post ) {
	$idx   = get_post_meta( $post->ID, DAW_FOOD_IDX, true );
	$shown = 0;

	echo '<p class="description">' . esc_html__( 'Read from the index. To change it, edit the food record.', 'daw' ) . '</p>';

	if ( is_array( $idx ) ) {
		echo '<ul style="margin:0;padding-left:18px;list-style:disc">';
		foreach ( $idx as $e ) {
			$fid = (int) ( $e['food_id'] ?? 0 );
			if ( ! $fid || 'publish' !== get_post_status( $fid ) ) {
				continue;
			}
			$line = array_filter( array( daw_fos_ep_label( $e['season'] ?? 0, $e['episode'] ?? 0 ), (string) ( $e['scene'] ?? '' ) ) );
			printf(
				'<li><a href="%s">%s</a>%s</li>',
				esc_url( get_edit_post_link( $fid ) ),
				esc_html( get_the_title( $fid ) ),
				$line ? ' <small>' . esc_html( implode( ' · ', $line ) ) . '</small>' : ''
			);
			++$shown;
		}
		echo '</ul>';
	}

	if ( ! $shown ) {
		echo '<p>' . esc_html__( 'No guides are linked to this title.', 'daw' ) . '</p>';
	}
}

/** Autocomplete: published dramas and films only. */
add_action( 'wp_ajax_daw_fos_search', 'daw_fos_ajax_search' );
function daw_fos_ajax_search() {
	check_ajax_referer( 'daw_fos_search' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( null, 403 );
	}

	$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	if ( strlen( $q ) < 2 ) {
		wp_send_json_success( array() );
	}

	$ids = get_posts(
		array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			's'                => $q,
			'posts_per_page'   => 12,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => daw_fos_screen_types(),
				),
			),
		)
	);

	$out = array();
	foreach ( $ids as $id ) {
		$start = (int) daw_field_raw( $id, 'daw_aired_start' );
		$title = wp_strip_all_tags( get_the_title( $id ) );
		$out[] = array(
			'id'    => (int) $id,
			'title' => $title,
			'label' => $title . ( $start ? ' (' . gmdate( 'Y', $start ) . ')' : '' ),
		);
	}

	wp_send_json_success( $out );
}

/* ============================================================ rebuild tool === */

add_action( 'admin_menu', 'daw_fos_tools_menu' );
function daw_fos_tools_menu() {
	add_submenu_page( 'tools.php', 'Rebuild food index', 'Rebuild food index', 'manage_options', 'daw-food-index', 'daw_fos_tools_page' );
}

function daw_fos_total() {
	global $wpdb;

	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_FOOD_META )
	);
}

/**
 * One batch: up to 50 food records after $after_id. The first batch (after 0)
 * empties every index first, so the result is the truth and not the old state
 * plus the new one.
 *
 * @return array [ done, last, total, processed ]
 */
function daw_fos_rebuild_batch( $after_id ) {
	global $wpdb;

	$after_id = (int) $after_id;

	if ( 0 === $after_id ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s,%s)", DAW_FOOD_IDX, DAW_FOOD_LAST ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT 50",
			DAW_FOOD_META,
			$after_id
		)
	);

	$GLOBALS['daw_fos_batch'] = true;
	foreach ( $ids as $fid ) {
		daw_fos_reindex_food( (int) $fid );
	}
	$GLOBALS['daw_fos_batch'] = false;

	// One flush for the batch, not one per record (rule 8.3).
	daw_fos_flush();
	if ( function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'post_meta' );
	}

	return array(
		'done'      => count( $ids ) < 50,
		'last'      => $ids ? (int) end( $ids ) : $after_id,
		'total'     => daw_fos_total(),
		'processed' => count( $ids ),
	);
}

add_action( 'wp_ajax_daw_fos_rebuild', 'daw_fos_ajax_rebuild' );
function daw_fos_ajax_rebuild() {
	check_ajax_referer( 'daw_fos_rebuild' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( null, 403 );
	}

	wp_send_json_success( daw_fos_rebuild_batch( isset( $_POST['after'] ) ? (int) $_POST['after'] : 0 ) );
}

function daw_fos_tools_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1>Rebuild food index</h1>
		<p>Rebuilds the food index on every drama and film from the "Seen in" rows of the food records, 50 records at a time. Safe to run again.</p>
		<p><button type="button" class="button button-primary" id="daw-fos-go">Rebuild food index</button></p>
		<p id="daw-fos-prog" aria-live="polite"></p>
	</div>
	<script>
	(function(){
		var btn=document.getElementById('daw-fos-go'),out=document.getElementById('daw-fos-prog'),
			ajax=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,nonce=<?php echo wp_json_encode( wp_create_nonce( 'daw_fos_rebuild' ) ); ?>,done=0;
		function step(after){
			var f=new FormData();f.append('action','daw_fos_rebuild');f.append('_wpnonce',nonce);f.append('after',after);
			fetch(ajax,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
				if(!j||!j.success){out.textContent='Stopped: the server refused a batch. Run it again.';btn.disabled=false;return;}
				done+=j.data.processed;
				out.textContent=done+' of '+j.data.total+' food records indexed.';
				if(j.data.done){out.textContent+=' Finished.';btn.disabled=false;}else{step(j.data.last);}
			}).catch(function(){out.textContent='Stopped: no answer from the server. Run it again.';btn.disabled=false;});
		}
		btn.addEventListener('click',function(){btn.disabled=true;done=0;out.textContent='Starting.';step(0);});
	})();
	</script>
	<?php
}

/**
 * wp daw food-index rebuild
 * Defined always, registered only under WP-CLI; it is only ever called there.
 */
function daw_fos_cli( $args ) {
	if ( ( $args[0] ?? '' ) !== 'rebuild' ) {
		WP_CLI::error( 'Usage: wp daw food-index rebuild' );
	}
	$after = 0;
	$done  = 0;
	do {
		$r     = daw_fos_rebuild_batch( $after );
		$after = $r['last'];
		$done += $r['processed'];
		WP_CLI::log( $done . ' / ' . $r['total'] );
	} while ( ! $r['done'] );
	WP_CLI::success( 'Food index rebuilt.' );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'daw food-index', 'daw_fos_cli' );
}

/* ===========================================================================
 * v1.49: the older "on screen" fields move into _daw_food_seen_in
 * ===========================================================================
 * Food records used to carry daw_onscreen_title (title IDs) and daw_onscreen_note
 * (a note each), with sources kept apart in the daw_guide_source_* lists, tied to
 * no row. One fact, one place (rule 1.4): the rows move here.
 *
 * A row moves only when its source can be identified without guessing: exactly
 * one of the record's own sources has its name or its domain written in the
 * note. Anything else waits on "Source to pick" with the record's own sources
 * offered in a list; the owner or the content side pairs them. A source is never
 * invented to make a row fit (rule 1.1).
 *
 * Deleting the old fields is a separate step, taken only when nothing is
 * waiting and only after typing a confirmation.
 */

const DAW_FOS_OLD_TITLE = 'daw_onscreen_title';
const DAW_FOS_OLD_NOTE  = 'daw_onscreen_note';

/**
 * The migration touches food records only: the categories K-Food, J-Food and
 * T-Food (and any added through daw_fos_food_categories). Every other guide
 * (gumiho, hanbok, a top-ten list) keeps its old "on screen" rows exactly where
 * they are and keeps drawing them; there is no second copy there.
 */
function daw_fos_migratable( $post_id ) {
	$cats = array_map( 'intval', (array) apply_filters( 'daw_fos_food_categories', array( 988, 4517, 6896 ) ) );

	return $cats && 'post' === get_post_type( (int) $post_id ) && has_category( $cats, (int) $post_id );
}

/**
 * Take out of the old fields the rows the new list already carries, so a record
 * never holds the same fact twice. Alignment of the two columns is kept, and
 * both fields go when nothing is left. Food records only. Returns rows removed.
 */
function daw_fos_legacy_prune( $food_id ) {
	$food_id = (int) $food_id;
	if ( ! daw_fos_migratable( $food_id ) ) {
		return 0;
	}

	$titles = (array) get_post_meta( $food_id, DAW_FOS_OLD_TITLE, true );
	$notes  = (array) get_post_meta( $food_id, DAW_FOS_OLD_NOTE, true );
	$have   = array_flip( daw_fos_covered( $food_id ) );
	$keep_t = array();
	$keep_n = array();
	$gone   = 0;

	foreach ( $titles as $i => $tid ) {
		if ( isset( $have[ (int) $tid ] ) ) {
			++$gone;
			continue;
		}
		$keep_t[] = $tid;
		$keep_n[] = $notes[ $i ] ?? '';
	}

	if ( ! $gone ) {
		return 0;
	}

	$left = array_filter( $keep_t, static function ( $t ) {
		return '' !== trim( (string) $t );
	} );
	if ( $left ) {
		update_post_meta( $food_id, DAW_FOS_OLD_TITLE, $keep_t );
		update_post_meta( $food_id, DAW_FOS_OLD_NOTE, $keep_n );
	} else {
		delete_post_meta( $food_id, DAW_FOS_OLD_TITLE );
		delete_post_meta( $food_id, DAW_FOS_OLD_NOTE );
	}

	return $gone;
}

/** The old rows of a record: [ [ title_id, note ], … ]. */
function daw_fos_legacy_rows( $food_id ) {
	if ( ! function_exists( 'daw_zip_rows' ) ) {
		return array();
	}

	$out = array();
	foreach ( daw_zip_rows( (int) $food_id, array( 'title' => DAW_FOS_OLD_TITLE, 'note' => DAW_FOS_OLD_NOTE ) ) as $r ) {
		$tid = (int) $r['title'];
		if ( $tid ) {
			$out[] = array( $tid, trim( (string) $r['note'] ) );
		}
	}

	return $out;
}

/** Title IDs already carried by the new rows, whatever their status. */
function daw_fos_covered( $food_id ) {
	$ids = array();
	foreach ( daw_fos_rows( $food_id ) as $r ) {
		$ids[ (int) $r['title_id'] ] = true;
	}

	return array_keys( $ids );
}

/** Old rows whose title is a real drama or film and not yet in the new rows. */
function daw_fos_legacy_pending( $food_id ) {
	$covered = array_flip( daw_fos_covered( $food_id ) );
	$out     = array();

	foreach ( daw_fos_legacy_rows( $food_id ) as $r ) {
		if ( ! isset( $covered[ $r[0] ] ) && daw_fos_title_exists( $r[0] ) ) {
			$out[] = $r;
		}
	}

	return $out;
}

/** The record's own sources, [ name, url ], url required here. */
function daw_fos_record_sources( $food_id ) {
	$out = array();
	if ( function_exists( 'daw_get_guide_sources' ) ) {
		foreach ( daw_get_guide_sources( $food_id ) as $s ) {
			$url = esc_url_raw( trim( (string) $s['url'] ), array( 'http', 'https' ) );
			if ( '' !== $url ) {
				$out[] = array( 'name' => trim( (string) $s['name'] ), 'url' => $url );
			}
		}
	}

	return $out;
}

/** The single source named in the note, or null. Two candidates is no match. */
function daw_fos_match_source( $note, array $sources ) {
	$note  = strtolower( (string) $note );
	$found = array();

	foreach ( $sources as $s ) {
		$host = strtolower( preg_replace( '/^www\./i', '', (string) wp_parse_url( $s['url'], PHP_URL_HOST ) ) );
		$name = strtolower( $s['name'] );
		if ( ( '' !== $name && strlen( $name ) >= 4 && false !== strpos( $note, $name ) ) || ( '' !== $host && false !== strpos( $note, $host ) ) ) {
			$found[ $s['url'] ] = $s;
		}
	}

	return 1 === count( $found ) ? reset( $found ) : null;
}

/** Append rows to a food record through the one door. Returns rows saved. */
function daw_fos_append_rows( $food_id, array $new ) {
	$before = daw_fos_rows( $food_id );
	$rows   = daw_fos_clean( array_merge( $before, $new ) );
	if ( count( $rows ) > count( $before ) ) {
		update_post_meta( $food_id, DAW_FOOD_META, $rows );
	}

	return count( $rows ) - count( $before );
}

/** Migrate what can be migrated on one record. Returns [ moved, waiting ]. */
function daw_fos_migrate_one( $food_id ) {
	if ( ! daw_fos_migratable( $food_id ) ) {
		return array( 0, 0 );
	}

	$sources = daw_fos_record_sources( $food_id );
	$add     = array();

	foreach ( daw_fos_legacy_pending( $food_id ) as $r ) {
		$m = daw_fos_match_source( $r[1], $sources );
		if ( $m ) {
			$add[] = array(
				'title_id'    => $r[0],
				'kind'        => 'dish',
				'scene'       => $r[1],
				'source_url'  => $m['url'],
				'source_name' => $m['name'],
			);
		}
	}

	$moved = $add ? daw_fos_append_rows( $food_id, $add ) : 0;

	// Whatever the new list now carries leaves the old fields.
	daw_fos_legacy_prune( $food_id );

	return array( $moved, count( daw_fos_legacy_pending( $food_id ) ) );
}

/** Food records that still carry old rows, 50 at a time, after $after_id. */
function daw_fos_migrate_batch( $after_id ) {
	global $wpdb;

	$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT 50",
			DAW_FOS_OLD_TITLE,
			(int) $after_id
		)
	);

	$moved   = 0;
	$waiting = 0;
	$GLOBALS['daw_fos_batch'] = true;
	foreach ( $ids as $fid ) {
		$r        = daw_fos_migrate_one( (int) $fid );
		$moved   += $r[0];
		$waiting += $r[1];
	}
	$GLOBALS['daw_fos_batch'] = false;
	daw_fos_flush(); // once per batch

	return array(
		'done'    => count( $ids ) < 50,
		'last'    => $ids ? (int) end( $ids ) : (int) $after_id,
		'records' => count( $ids ),
		'moved'   => $moved,
		'waiting' => $waiting,
	);
}

/** Every record with rows still waiting: [ food_id => pending rows ]. */
function daw_fos_all_pending() {
	global $wpdb;

	$out = array();
	$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC LIMIT 500", DAW_FOS_OLD_TITLE )
	);
	foreach ( $ids as $fid ) {
		if ( ! daw_fos_migratable( (int) $fid ) ) {
			continue;
		}
		$p = daw_fos_legacy_pending( (int) $fid );
		if ( $p ) {
			$out[ (int) $fid ] = $p;
		}
	}

	return $out;
}

add_action( 'wp_ajax_daw_fos_migrate', 'daw_fos_ajax_migrate' );
function daw_fos_ajax_migrate() {
	check_ajax_referer( 'daw_fos_migrate' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( null, 403 );
	}

	wp_send_json_success( daw_fos_migrate_batch( isset( $_POST['after'] ) ? (int) $_POST['after'] : 0 ) );
}

add_action( 'admin_menu', 'daw_fos_migrate_menu' );
function daw_fos_migrate_menu() {
	add_submenu_page( 'tools.php', 'Food on screen: move old rows', 'Food: move old rows', 'manage_options', 'daw-food-migrate', 'daw_fos_migrate_page' );
}

/** The owner pairs one waiting row with a source of that record. */
add_action( 'admin_post_daw_fos_pick', 'daw_fos_pick' );
function daw_fos_pick() {
	check_admin_referer( 'daw_fos_pick' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'No.', 403 );
	}

	$food_id = isset( $_POST['food'] ) ? (int) $_POST['food'] : 0;
	$tid     = isset( $_POST['title'] ) ? (int) $_POST['title'] : 0;
	$choice  = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
	$manual  = isset( $_POST['manual'] ) ? esc_url_raw( trim( wp_unslash( $_POST['manual'] ) ), array( 'http', 'https' ) ) : '';
	$msg     = 'nosource';

	$note = '';
	foreach ( daw_fos_legacy_rows( $food_id ) as $r ) {
		if ( $r[0] === $tid ) {
			$note = $r[1];
		}
	}

	$sources = daw_fos_record_sources( $food_id );
	$url     = '';
	$name    = '';
	if ( '' !== $manual ) {
		$url = $manual;
	} elseif ( '' !== $choice && isset( $sources[ (int) $choice ] ) ) {
		$url  = $sources[ (int) $choice ]['url'];
		$name = $sources[ (int) $choice ]['name'];
	}

	if ( $food_id && $tid && '' !== $url && daw_fos_migratable( $food_id ) ) {
		$GLOBALS['daw_fos_ran'] = array();
		$msg = daw_fos_append_rows(
			$food_id,
			array(
				array(
					'title_id'    => $tid,
					'kind'        => 'dish',
					'scene'       => $note,
					'source_url'  => $url,
					'source_name' => $name,
				),
			)
		) ? 'moved' : 'nochange';
		daw_fos_legacy_prune( $food_id );
	}

	wp_safe_redirect( add_query_arg( array( 'page' => 'daw-food-migrate', 'done' => $msg ), admin_url( 'tools.php' ) ) );
	exit;
}

/**
 * The last step: delete the old fields, on FOOD records only (K-Food, J-Food,
 * T-Food). Never by meta key across the site: the other guides still use these
 * fields and have nothing to replace them. Only with nothing waiting, only
 * after typing DELETE.
 */
add_action( 'admin_post_daw_fos_purge', 'daw_fos_purge' );
function daw_fos_purge() {
	check_admin_referer( 'daw_fos_purge' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'No.', 403 );
	}

	global $wpdb;

	$typed = isset( $_POST['confirm'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) : '';
	$msg   = 'purge-refused';

	if ( 'DELETE' === $typed && ! daw_fos_all_pending() ) {
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_FOS_OLD_TITLE )
		);
		$n = 0;
		foreach ( $ids as $fid ) {
			if ( daw_fos_migratable( (int) $fid ) ) {
				delete_post_meta( (int) $fid, DAW_FOS_OLD_TITLE );
				delete_post_meta( (int) $fid, DAW_FOS_OLD_NOTE );
				++$n;
			}
		}
		daw_fos_flush();
		$msg = $n ? 'purged' : 'nothing-to-purge';
	}

	wp_safe_redirect( add_query_arg( array( 'page' => 'daw-food-migrate', 'done' => $msg ), admin_url( 'tools.php' ) ) );
	exit;
}

function daw_fos_migrate_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$pending = daw_fos_all_pending();
	$gone    = false;
	$done    = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$texts   = array(
		'moved'         => 'Row moved.',
		'nochange'      => 'Nothing changed (the row may already be there).',
		'nosource'      => 'Pick one of the listed sources or paste a link first.',
		'purged'        => 'Old fields deleted on the food records.',
		'nothing-to-purge' => 'No food record still has old fields.',
		'purge-refused' => 'Not deleted: type DELETE and make sure no row is waiting.',
	);
	?>
	<div class="wrap">
		<h1>Food on screen: move old rows</h1>
		<?php if ( isset( $texts[ $done ] ) ) : ?>
			<div class="notice notice-info inline"><p><?php echo esc_html( $texts[ $done ] ); ?></p></div>
		<?php endif; ?>

		<?php if ( $gone ) : ?>
			<p>The old fields have been deleted. Nothing is left to move.</p>
		<?php else : ?>
			<h2>1. Move</h2>
			<p><strong>Food records only</strong> (K-Food, J-Food, T-Food). Other guides keep their old rows and still draw them. Moves each old row to the new field when exactly one of the record's own sources is named in its note (by name or domain). The rest are listed below, waiting for a source. Runs 50 records at a time. Safe to run again.</p>
			<p><button type="button" class="button button-primary" id="daw-fos-mv">Move old rows</button></p>
			<p id="daw-fos-mvp" aria-live="polite"></p>

			<h2>2. Source to pick (<?php echo esc_html( (string) array_sum( array_map( 'count', $pending ) ) ); ?> rows)</h2>
			<?php if ( ! $pending ) : ?>
				<p>Nothing is waiting.</p>
			<?php endif; ?>
			<?php foreach ( $pending as $fid => $rows ) : ?>
				<h3><a href="<?php echo esc_url( get_edit_post_link( $fid ) ); ?>"><?php echo esc_html( get_the_title( $fid ) ); ?></a></h3>
				<?php $sources = daw_fos_record_sources( $fid ); ?>
				<?php foreach ( $rows as $r ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 10px;padding:10px;background:#fff;border:1px solid #c3c4c7">
						<?php wp_nonce_field( 'daw_fos_pick' ); ?>
						<input type="hidden" name="action" value="daw_fos_pick">
						<input type="hidden" name="food" value="<?php echo esc_attr( (string) $fid ); ?>">
						<input type="hidden" name="title" value="<?php echo esc_attr( (string) $r[0] ); ?>">
						<strong><?php echo esc_html( get_the_title( $r[0] ) ); ?></strong>
						<?php echo '' !== $r[1] ? ' · ' . esc_html( $r[1] ) : ''; ?>
						<p style="margin:6px 0">
							<select name="source">
								<option value="">Pick one of this record's sources</option>
								<?php foreach ( $sources as $i => $s ) : ?>
									<option value="<?php echo esc_attr( (string) $i ); ?>"><?php echo esc_html( ( '' !== $s['name'] ? $s['name'] . ' · ' : '' ) . $s['url'] ); ?></option>
								<?php endforeach; ?>
							</select>
							or <input type="url" name="manual" placeholder="Paste the source page that proves it" style="min-width:320px">
							<button class="button">Move this row</button>
						</p>
					</form>
				<?php endforeach; ?>
			<?php endforeach; ?>

			<h2>3. Delete the old fields</h2>
			<p>Only after the list above is empty and you have looked at the pages. This removes the old title and note fields from the <strong>food records only</strong> (K-Food, J-Food, T-Food) and cannot be undone. Other guides are not touched.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'daw_fos_purge' ); ?>
				<input type="hidden" name="action" value="daw_fos_purge">
				<input type="text" name="confirm" placeholder="Type DELETE" autocomplete="off"<?php disabled( (bool) $pending ); ?>>
				<button class="button"<?php disabled( (bool) $pending ); ?>>Delete old fields</button>
			</form>
		<?php endif; ?>
	</div>
	<script>
	(function(){
		var b=document.getElementById('daw-fos-mv');if(!b){return;}
		var o=document.getElementById('daw-fos-mvp'),ajax=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,nonce=<?php echo wp_json_encode( wp_create_nonce( 'daw_fos_migrate' ) ); ?>,rec=0,mv=0,w=0;
		function step(a){var f=new FormData();f.append('action','daw_fos_migrate');f.append('_wpnonce',nonce);f.append('after',a);
			fetch(ajax,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
				if(!j||!j.success){o.textContent='Stopped: the server refused a batch. Run it again.';b.disabled=false;return;}
				rec+=j.data.records;mv+=j.data.moved;w+=j.data.waiting;o.textContent=rec+' records checked, '+mv+' rows moved, '+w+' waiting for a source.';
				if(j.data.done){o.textContent+=' Finished. Reload to see the list.';b.disabled=false;}else{step(j.data.last);}
			}).catch(function(){o.textContent='Stopped: no answer from the server. Run it again.';b.disabled=false;});}
		b.addEventListener('click',function(){b.disabled=true;rec=mv=w=0;o.textContent='Starting.';step(0);});
	})();
	</script>
	<?php
}

/* ---------------------------------------------------------------- audit --- */

/**
 * Audit rows for a food record's "seen in" list: one per row (the title must be
 * published; the source is a link), plus one for old rows still waiting.
 * Items use the audit engine's shape: [ label, preview, state, note ].
 */
function daw_fos_audit_items( $food_id ) {
	$items = array();

	foreach ( daw_fos_rows( $food_id ) as $r ) {
		$tid  = (int) $r['title_id'];
		$live = daw_fos_title_live( $tid );
		$prev = implode( ' · ', array_filter( array( daw_fos_ep_label( $r['season'] ?? 0, $r['episode'] ?? 0 ), (string) ( $r['scene'] ?? '' ) ) ) );
		$items[] = array(
			wp_strip_all_tags( get_the_title( $tid ) ),
			$prev,
			$live ? 'ok' : 'no',
			$live ? '' : 'Yapım yayında değil: satır saklanıyor ama ekrana basılmıyor. Yapımı yayına al ya da satırı sil.',
		);
	}

	$wait = daw_fos_legacy_pending( $food_id );
	if ( $wait ) {
		$items[] = array(
			'Eski alandan taşınmamış satır',
			count( $wait ) . ' satır',
			'wait',
			'Kaynağı seçilecek: Araçlar › Food: move old rows sayfasında satıra kaynak eşlenir.',
		);
	}

	return $items;
}
