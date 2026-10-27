<?php
/**
 * The hub pages, and what each one is a hub of.
 *
 * WHY A TABLE AND NOT A TAXONOMY ARCHIVE
 * --------------------------------------
 * dw_country is registered public => false, on purpose: a public taxonomy would
 * mint a second set of urls for pages that already exist and have existed for
 * two years. /south-korea-korean-dramas-movies-culture/ is the Korea page. It
 * keeps that url forever. All that changes is what draws it.
 *
 * So the country a hub belongs to is written down here, keyed by the page slug,
 * rather than discovered from a rewrite rule. One table, one place to edit, and
 * no rewrite rule anywhere in this plugin.
 *
 * WHAT A HUB IS NOT
 * -----------------
 * It is not a second copy of the type lists. /korean-drama/ is the list of
 * Korean dramas and it stays that; the Korea hub shows a handful and links to
 * it. Two pages that answer the same query with the same rows are two pages
 * competing for one result, and the one that loses is ours either way.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every country hub: the page that already exists, and what it is about.
 *
 * 'country'   dw_country term slug. A hub with no term yet still renders; it
 *             simply has nothing to count, which is the honest state of a
 *             country nobody has filed a record under.
 * 'adjective' The word the copy uses: "Korean cinema", "Japanese cinema".
 * 'sections'  Page slugs to link out to, by record type. Missing pages are
 *             dropped at render, never printed as dead links.
 */
function daw_country_hubs() {
	return array(
		'south-korea-korean-dramas-movies-culture' => array(
			'country'   => 'south-korea',
			'name'      => __( 'South Korea', 'daw' ),
			// The name a sentence uses. "People from South Korea" is a form
			// nobody says out loud, and "People from Korean" is not English at
			// all, so the short name is written down rather than derived.
			'short'     => __( 'Korea', 'daw' ),
			'adjective' => __( 'Korean', 'daw' ),
			'sections'  => array(
				'drama'   => 'korean-drama',
				'film'    => 'korean-movie',
				'person'  => 'korean-actors',
				'group'   => 'korean-pop',
				'food'    => 'korean-foods-drinks',
				'culture' => 'korean-culture',
				'myth'    => 'korean-myths',
				'travel'  => 'korean-travel',
				'news'    => 'korean-trends-news',
			),
		),
		'japan-japanese-dramas-movies-culture'     => array(
			'country'   => 'japan',
			'name'      => __( 'Japan', 'daw' ),
			'short'     => __( 'Japan', 'daw' ),
			'adjective' => __( 'Japanese', 'daw' ),
			'sections'  => array(
				'drama'   => 'japanese-drama',
				'film'    => 'japanese-movies',
				'person'  => 'japanese-actors',
				'food'    => 'japanese-foods-drinks',
				'culture' => 'japanese-culture',
			),
		),
		'chinese-dramas-movies-culture'            => array(
			'country'   => 'china',
			'name'      => __( 'China', 'daw' ),
			'short'     => __( 'China', 'daw' ),
			'adjective' => __( 'Chinese', 'daw' ),
			'sections'  => array(
				'drama'   => 'chinese-drama',
				'film'    => 'chinese-movies',
				'person'  => 'chinese-actors',
				'food'    => 'chinese-foods-drinks',
				'culture' => 'chinese-culture',
			),
		),
		'taiwan-taiwanese-dramas-movies-culture'   => array(
			'country'   => 'taiwan',
			'name'      => __( 'Taiwan', 'daw' ),
			'short'     => __( 'Taiwan', 'daw' ),
			'adjective' => __( 'Taiwanese', 'daw' ),
			'sections'  => array(
				'drama'   => 'taiwanese-drama',
				'film'    => 'taiwanese-movies',
				'person'  => 'taiwanese-actors',
				'food'    => 'taiwanese-foods-drinks',
				'culture' => 'taiwanese-culture',
			),
		),
		'thailand-thai-dramas-movies-culture'      => array(
			'country'   => 'thailand',
			'name'      => __( 'Thailand', 'daw' ),
			'short'     => __( 'Thailand', 'daw' ),
			'adjective' => __( 'Thai', 'daw' ),
			'sections'  => array(
				'drama'   => 'thai-drama',
				'film'    => 'thai-movie',
				'person'  => 'thai-actors',
				'food'    => 'thai-foods-drinks',
				'culture' => 'thai-culture',
			),
		),
		'hong-kong-dramas-movies-culture'          => array(
			'country'   => 'hong-kong',
			'name'      => __( 'Hong Kong', 'daw' ),
			'short'     => __( 'Hong Kong', 'daw' ),
			'adjective' => __( 'Hong Kong', 'daw' ),
			'sections'  => array(
				'drama'   => 'hong-kong-drama',
				'film'    => 'hong-kong-movies',
				'person'  => 'hong-kong-actors',
				'food'    => 'hong-kong-foods-drinks',
				'culture' => 'hong-kong-culture',
			),
		),
		'philippines-filipino-dramas-movies-culture' => array(
			'country'   => 'philippines',
			'name'      => __( 'Philippines', 'daw' ),
			'short'     => __( 'the Philippines', 'daw' ),
			'adjective' => __( 'Filipino', 'daw' ),
			'sections'  => array(
				'drama'   => 'filipino-drama',
				'film'    => 'filipino-movies',
				'person'  => 'filipino-actors',
				'food'    => 'filipino-foods-drinks',
				'culture' => 'filipino-culture',
			),
		),
		'singapore-singaporean-dramas-movies-culture' => array(
			'country'   => 'singapore',
			'name'      => __( 'Singapore', 'daw' ),
			'short'     => __( 'Singapore', 'daw' ),
			'adjective' => __( 'Singaporean', 'daw' ),
			'sections'  => array(
				'drama'   => 'singaporean-drama',
				'film'    => 'singaporean-movies',
				'person'  => 'singaporean-actors',
				'food'    => 'singaporean-foods-drinks',
				'culture' => 'singaporean-culture',
			),
		),
	);
}

/** The hub definition for a page, or null when the page is not a hub. */
function daw_hub_for_page( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();

	if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
		return null;
	}

	$hubs = daw_country_hubs();
	$slug = (string) get_post_field( 'post_name', $post_id );

	return isset( $hubs[ $slug ] ) ? $hubs[ $slug ] + array( 'slug' => $slug ) : null;
}

/**
 * Route a hub page to its template.
 *
 * ONE PAGE AT A TIME
 * ------------------
 * A hub only takes the new template once its slug is in the option below.
 * Eight pages that have been live for two years do not all change on the same
 * plugin upgrade because a developer was confident; they change when someone
 * has looked at the new one and said yes.
 */
add_filter( 'page_template', 'daw_route_hub_template' );
function daw_route_hub_template( $template ) {
	$hub = daw_hub_for_page();

	if ( ! $hub || ! daw_hub_is_live( $hub['slug'] ) ) {
		return $template;
	}

	$found = locate_template( array( 'page-country.php' ) );

	return $found ? $found : $template;
}

/**
 * How much a country needs before its hub is worth switching.
 *
 * WHY A FLOOR
 * -----------
 * The template draws a country with two records perfectly well, and a page
 * with two records on it is still a page with two records on it. The existing
 * Japan page is twenty three thousand characters somebody wrote by hand, and
 * it has been indexed for two years. Replacing that with three cards is not a
 * redesign, it is a deletion with a nicer layout.
 *
 * So a hub is offered as ready only once there is enough behind it to be worth
 * the trade. The numbers are the ones a reader would call a section rather
 * than a sample: enough dramas to fill the strip, enough films to fill the
 * row, enough guides to make the subject headings mean something.
 */
function daw_hub_floor() {
	return array(
		'drama' => 6,
		'film'  => 4,
		'guide' => 3,
	);
}

/** What this country has against that floor. */
function daw_hub_readiness( $country ) {
	$counts = daw_hub_counts( $country );
	$floor  = daw_hub_floor();

	$guides = 0;
	foreach ( ( function_exists( 'daw_guide_types' ) ? daw_guide_types() : array() ) as $gt ) {
		$guides += (int) ( $counts[ $gt ] ?? 0 );
	}

	$have = array(
		'drama' => (int) ( $counts['drama'] ?? 0 ),
		'film'  => (int) ( $counts['film'] ?? 0 ),
		'guide' => $guides,
	);

	$short = array();
	foreach ( $floor as $k => $need ) {
		if ( $have[ $k ] < $need ) {
			$short[ $k ] = $need - $have[ $k ];
		}
	}

	return array( 'have' => $have, 'floor' => $floor, 'short' => $short, 'ready' => ! $short );
}

/** Which hubs are switched over. Everything else keeps the page it has. */
function daw_hub_is_live( $slug ) {
	$live = (array) get_option( 'daw_live_hubs', array() );

	return in_array( $slug, $live, true );
}

/* ================================================================ counts === */

/**
 * How many records of each type this country holds.
 *
 * Counted rather than typed into the page, which is the whole point: the number
 * under "Dramas" was last correct on the day somebody edited it by hand, and
 * has been wrong ever since.
 */
function daw_hub_counts( $country ) {
	$key    = 'daw_hubcount_' . $country;
	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$out = array();

	foreach ( array_keys( daw_record_types() ) as $type ) {
		$out[ $type ] = daw_hub_count( $country, $type );
	}

	set_transient( $key, $out, 6 * HOUR_IN_SECONDS );

	return $out;
}

function daw_hub_count( $country, $type ) {
	$q = new WP_Query(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => daw_hub_tax_query( $country, $type ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		)
	);

	return (int) $q->found_posts;
}

/** country + type, as a tax_query. $type may be an array of type slugs. */
function daw_hub_tax_query( $country, $type ) {
	$tax = array(
		'relation' => 'AND',
		array(
			'taxonomy' => 'dw_country',
			'field'    => 'slug',
			'terms'    => $country,
		),
	);

	if ( $type ) {
		$tax[] = array(
			'taxonomy' => 'dw_type',
			'field'    => 'slug',
			'terms'    => (array) $type,
		);
	}

	return $tax;
}

/* ================================================================= lists === */

/**
 * Records of one type from one country.
 *
 * ORDER IS A CLAIM
 * ----------------
 * The design asked for "ranked by reads and searches over the last 30 days".
 * This site has no reads data and no search data, so that caption would be
 * describing something that never happened. What the records actually carry is
 * an air date, so the strip is ordered by it and the heading says "recent".
 *
 * A title with no air date is not in this strip, which is correct: an undated
 * record is not a recent one. It is still on the full list the strip links to.
 */
function daw_hub_records( $country, $type, $limit = 6 ) {
	$base = array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'posts_per_page'         => (int) $limit,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'ignore_sticky_posts'    => true,
		'update_post_term_cache' => false,
		'tax_query'              => daw_hub_tax_query( $country, $type ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	);

	$ids = get_posts(
		$base + array(
			'meta_key' => 'daw_aired_start', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'  => 'meta_value_num',
			'order'    => 'DESC',
		)
	);

	// AN UNDATED RECORD IS STILL A RECORD
	// -----------------------------------
	// Ordering by a meta key silently drops every record that does not have it,
	// and this site has 162 Korean films of which not one carries an air date.
	// The strip came out empty and the whole Cinema section disappeared, which
	// looked like a template with a bug rather than a field nobody has filled.
	// So the dated ones lead, newest first, and the rest follow by the date
	// they were last worked on until the strip is full.
	if ( count( $ids ) < $limit ) {
		$rest = get_posts(
			$base + array(
				'post__not_in' => $ids,
				'orderby'      => 'modified',
				'order'        => 'DESC',
			)
		);

		$ids = array_slice( array_merge( $ids, $rest ), 0, (int) $limit );
	}

	return $ids;
}

/** Guides and news carry no air date, so those are ordered by publication. */
function daw_hub_articles( $country, $type, $limit = 3 ) {
	return get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => (int) $limit,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'tax_query'              => daw_hub_tax_query( $country, $type ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		)
	);
}

/**
 * The people of a country, busiest first.
 *
 * WHY NOT ASK EACH PROFILE
 * ------------------------
 * daw_credit_count() reads a filmography, and a filmography is a query. Asking
 * it for a hundred and sixty eight people on one page load is a hundred and
 * sixty eight queries on a cold cache, which is how a page stops rendering at
 * all. The cast index is one meta row per title, so one read of all of them
 * and a tally in PHP answers "who is in the most things" for the whole site at
 * once, in a single query over a few hundred rows.
 */
function daw_hub_people( $country, $limit = 4 ) {
	global $wpdb;

	$key    = 'daw_hubppl_' . $country . '_' . (int) $limit;
	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	// NEWEST FIRST, NOT BUSIEST FIRST
	// ------------------------------
	// Ranking by credit count gives the same five faces on the same page for
	// the next two years, which makes a database look like a shrine. Newest
	// first means the panel changes every time a profile is added, and it is
	// the profiles just added that most need somebody to find them.
	$people = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => (int) $limit,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => daw_hub_tax_query( $country, array( 'person', 'group' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		)
	);

	if ( ! $people ) {
		return array();
	}

	// The credit count still rides along, because "Actor · 31 credits" is what
	// tells a reader the profile is worth opening. It comes from one read of
	// the cast index rather than one filmography query per person: asking each
	// profile would be four queries here and a hundred on a bigger panel.
	$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_CAST_IDX )
	);

	$credits = array();

	foreach ( $rows as $idx ) {
		if ( ! preg_match_all( '/,(\d+):[a-z]+/', (string) $idx, $m ) ) {
			continue;
		}
		foreach ( $m[1] as $pid ) {
			$pid             = (int) $pid;
			$credits[ $pid ] = ( $credits[ $pid ] ?? 0 ) + 1;
		}
	}

	$out = array();

	foreach ( $people as $pid ) {
		$out[] = array( 'id' => (int) $pid, 'credits' => (int) ( $credits[ (int) $pid ] ?? 0 ) );
	}

	set_transient( $key, $out, 6 * HOUR_IN_SECONDS );

	return $out;
}

/**
 * The terms this country's records actually use, with their counts.
 *
 * A global term list would offer a reader filters that return nothing: forty
 * networks, of which four ever broadcast anything in this country. So the tally
 * is over this country's records only, and a term nobody used is not printed.
 */
function daw_hub_terms( $country, $taxonomy, $limit = 12 ) {
	$key    = 'daw_hubterm_' . $country . '_' . $taxonomy;
	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		return array_slice( $cached, 0, $limit );
	}

	$ids = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => daw_hub_tax_query( $country, array( 'drama', 'film', 'variety' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		)
	);

	$tally = array();

	foreach ( $ids as $id ) {
		$terms = get_the_terms( $id, $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			continue;
		}
		foreach ( $terms as $t ) {
			$tally[ $t->term_id ]         = $tally[ $t->term_id ] ?? array( 'name' => $t->name, 'slug' => $t->slug, 'n' => 0 );
			$tally[ $t->term_id ]['n']   += 1;
		}
	}

	uasort(
		$tally,
		static function ( $a, $b ) {
			return $b['n'] <=> $a['n'];
		}
	);

	$tally = array_values( $tally );

	set_transient( $key, $tally, 6 * HOUR_IN_SECONDS );

	return array_slice( $tally, 0, $limit );
}

/** Decades, counted off the air date. Empty decades never appear. */
function daw_hub_decades( $country, $limit = 8 ) {
	$key    = 'daw_hubdec_' . $country;
	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		return array_slice( $cached, 0, $limit );
	}

	$ids = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'tax_query'              => daw_hub_tax_query( $country, array( 'drama', 'film', 'variety' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		)
	);

	$tally = array();

	foreach ( $ids as $id ) {
		$start = (int) daw_field_raw( $id, 'daw_aired_start' );
		if ( ! $start ) {
			continue;
		}
		$decade           = ( (int) gmdate( 'Y', $start ) ) - ( (int) gmdate( 'Y', $start ) % 10 );
		$tally[ $decade ] = ( $tally[ $decade ] ?? 0 ) + 1;
	}

	krsort( $tally );

	$out = array();
	foreach ( $tally as $decade => $n ) {
		$out[] = array( 'name' => $decade . 's', 'slug' => (string) $decade, 'n' => $n );
	}

	set_transient( $key, $out, 6 * HOUR_IN_SECONDS );

	return array_slice( $out, 0, $limit );
}

/**
 * Throw the counts away when a record changes.
 *
 * Six hours is fine for a reader and wrong for an editor: somebody who has just
 * filed a drama under Japan wants the Japan page to say so, not to be told to
 * wait until this afternoon.
 */
add_action( 'save_post_post', 'daw_flush_hub_cache' );
add_action( 'set_object_terms', 'daw_flush_hub_cache' );
function daw_flush_hub_cache() {
	global $wpdb;

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_daw\_hub%'
		    OR option_name LIKE '\_transient\_timeout\_daw\_hub%'"
	);
}

/* ================================================================= admin === */

/**
 * Which hubs use the new template.
 *
 * A SWITCH, NOT A DEPLOY
 * ----------------------
 * Eight pages that have been live for two years should not all change because
 * a plugin updated. Each one is turned on by hand, after somebody has opened it
 * and looked. The old body is never deleted: turning a hub off puts the page
 * back exactly as it was, because the content is still sitting in post_content
 * where it always was, and the new template simply stops being chosen.
 */
add_action( 'admin_menu', 'daw_hub_admin_menu' );
function daw_hub_admin_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: Hubs', 'daw' ),
		__( 'DAW: Hubs', 'daw' ),
		'manage_options',
		'daw-hubs',
		'daw_hub_admin_page'
	);
}

function daw_hub_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$hubs = daw_country_hubs();

	if ( isset( $_POST['daw_hubs_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['daw_hubs_nonce'] ), 'daw_hubs' ) ) {
		$posted = isset( $_POST['live'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['live'] ) ) : array();
		$live   = array_values( array_intersect( $posted, array_keys( $hubs ) ) );

		update_option( 'daw_live_hubs', $live, false );
		daw_flush_hub_cache();

		// Switching a hub changes what every cached copy of that page says, so
		// the caches go with it rather than being left for somebody to remember.
		if ( function_exists( 'daw_purge_page_cache' ) ) {
			daw_purge_page_cache();
		}

		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'Saved, and the page cache has been purged.', 'daw' )
			. '</p></div>';
	}

	$live = (array) get_option( 'daw_live_hubs', array() );

	echo '<div class="wrap"><h1>DAW: Hubs</h1>';
	echo '<p>' . esc_html__( 'A hub ticked here is drawn from the database. Unticked, the page keeps the body it has always had; nothing is deleted either way, so this is safe to turn on and off while you look.', 'daw' ) . '</p>';
	echo '<p>' . esc_html__( 'Ready means the country has enough records to fill the page: six dramas, four films and three guides. Below that the old page is the better page, because it has content and the new one would have gaps. Nothing stops you switching early; the column is there so it is a decision rather than an accident.', 'daw' ) . '</p>';

	echo '<form method="post"><table class="widefat striped"><thead><tr>'
		. '<th>' . esc_html__( 'Page', 'daw' ) . '</th>'
		. '<th>' . esc_html__( 'Country term', 'daw' ) . '</th>'
		. '<th>' . esc_html__( 'Records', 'daw' ) . '</th>'
		. '<th>' . esc_html__( 'Ready?', 'daw' ) . '</th>'
		. '<th>' . esc_html__( 'Old body', 'daw' ) . '</th>'
		. '<th>' . esc_html__( 'New template', 'daw' ) . '</th>'
		. '</tr></thead><tbody>';

	foreach ( $hubs as $slug => $hub ) {
		$page = get_page_by_path( $slug );
		$term = get_term_by( 'slug', $hub['country'], 'dw_country' );
		$n    = $term ? (int) $term->count : 0;

		$ready = daw_hub_readiness( $hub['country'] );

		if ( $ready['ready'] ) {
			$verdict = '<span style="color:#1a7f37">' . esc_html__( 'ready', 'daw' ) . '</span>';
		} else {
			$bits = array();
			foreach ( $ready['short'] as $what => $n ) {
				/* translators: 1: how many more are needed, 2: what kind */
				$bits[] = sprintf( esc_html__( '%1$d more %2$s', 'daw' ), $n, $what );
			}
			$verdict = '<span style="color:#b32d2e">' . esc_html( implode( ', ', $bits ) ) . '</span>';
		}

		printf(
			'<tr><td><strong>%1$s</strong><br><small>%2$s</small></td><td>%3$s</td><td>%4$s</td><td>%9$s</td><td>%5$s</td>'
			. '<td><label><input type="checkbox" name="live[]" value="%6$s"%7$s> %8$s</label></td></tr>',
			esc_html( $hub['name'] ),
			$page ? sprintf( '<a href="%s">/%s/</a>', esc_url( (string) get_permalink( $page ) ), esc_html( $slug ) ) : esc_html__( 'page missing', 'daw' ),
			$term
				? esc_html( $hub['country'] )
				: '<span style="color:#b32d2e">' . esc_html__( 'no term yet', 'daw' ) . '</span>',
			$n
				? esc_html( number_format_i18n( $n ) )
				: '<span style="color:#b32d2e">0</span>',
			$page
				? esc_html( size_format( strlen( (string) $page->post_content ) ) )
				: '—',
			esc_attr( $slug ),
			in_array( $slug, $live, true ) ? ' checked' : '',
			esc_html__( 'use it', 'daw' ),
			wp_kses_post( $verdict )
		);
	}

	echo '</tbody></table>';
	wp_nonce_field( 'daw_hubs', 'daw_hubs_nonce' );
	submit_button( __( 'Save', 'daw' ) );
	echo '</form></div>';
}

/* ============================================================ hub fields === */

/**
 * The two things a person writes on a hub.
 *
 * WHY NOT THE PAGE BODY
 * ---------------------
 * Because the page body is the thing being replaced. The Korea page carries
 * twenty two thousand characters of hand-placed cards, and the first version of
 * this template printed them under the heading "Country guide", so the reader
 * got the new page and then the whole old one underneath it. The old body stays
 * in the database untouched — that is what makes this reversible — but it is
 * not what the hub prints.
 *
 * So the standfirst is the page excerpt, which WordPress already has, and the
 * longer piece is a field of its own. Two boxes, on hub pages only.
 */
add_action( 'add_meta_boxes_page', 'daw_hub_meta_box' );
function daw_hub_meta_box( $post ) {
	if ( ! daw_hub_for_page( $post->ID ) ) {
		return;
	}

	add_meta_box(
		'daw-hub-intro',
		__( 'DAW: Country guide', 'daw' ),
		'daw_hub_meta_box_render',
		'page',
		'normal',
		'high'
	);
}

function daw_hub_meta_box_render( $post ) {
	wp_nonce_field( 'daw_hub_intro', 'daw_hub_intro_nonce' );

	$intro = (string) get_post_meta( $post->ID, 'daw_hub_intro', true );
	?>
	<p class="description">
		<?php esc_html_e( 'The paragraph that says where to start. It prints across the full width of the hub, set in columns. The page body above is not printed on a hub; it is kept only so the page can be switched back.', 'daw' ); ?>
	</p>
	<p class="description">
		<strong><?php esc_html_e( 'Never type a count into this box.', 'daw' ); ?></strong>
		<?php esc_html_e( 'Use a token and it is filled in from the same query as the numbers at the top of the page, so the two can never disagree:', 'daw' ); ?>
		<code>{dramas}</code> <code>{films}</code> <code>{people}</code> <code>{guides}</code>
		<code>{country}</code> <code>{short}</code> <code>{adjective}</code>
		<?php esc_html_e( 'and one for every record type, such as', 'daw' ); ?> <code>{foods}</code>.
	</p>
	<textarea name="daw_hub_intro" rows="8" style="width:100%" placeholder="<?php esc_attr_e( 'Most people enter Korean drama through romantic comedy…', 'daw' ); ?>"><?php echo esc_textarea( $intro ); ?></textarea>

	<p class="description" style="margin-top:12px">
		<?php esc_html_e( 'The two lines under the title come from the page excerpt. If the excerpt box is hidden, turn it on under Screen Options.', 'daw' ); ?>
	</p>
	<?php
}

add_action( 'save_post_page', 'daw_hub_meta_box_save' );
function daw_hub_meta_box_save( $post_id ) {
	if ( ! isset( $_POST['daw_hub_intro_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( $_POST['daw_hub_intro_nonce'] ), 'daw_hub_intro' )
		|| ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$intro = isset( $_POST['daw_hub_intro'] ) ? wp_kses_post( wp_unslash( $_POST['daw_hub_intro'] ) ) : '';

	if ( '' === trim( $intro ) ) {
		delete_post_meta( $post_id, 'daw_hub_intro' );
	} else {
		update_post_meta( $post_id, 'daw_hub_intro', $intro );
	}
}

/**
 * The same two boxes for a section page.
 *
 * A section page is a page like any other and its editor shows a body field
 * that this template does not print, which is a trap: somebody types a
 * paragraph into it, saves, and the page silently stops being drawn from the
 * database because an empty body is the switch. So the box says that out loud,
 * and gives the intro somewhere to go that is not the body.
 */
add_action( 'add_meta_boxes_page', 'daw_section_meta_box' );
function daw_section_meta_box( $post ) {
	if ( ! daw_section_for_page( $post->ID ) ) {
		return;
	}

	add_meta_box(
		'daw-section-intro',
		__( 'DAW: Section page', 'daw' ),
		'daw_section_meta_box_render',
		'page',
		'normal',
		'high'
	);
}

function daw_section_meta_box_render( $post ) {
	wp_nonce_field( 'daw_section_intro', 'daw_section_intro_nonce' );

	$intro = (string) get_post_meta( $post->ID, 'daw_section_intro', true );
	$live  = daw_section_is_live( $post->ID );
	?>
	<p class="description">
		<?php if ( $live ) : ?>
			<strong><?php esc_html_e( 'This page is drawn from the database.', 'daw' ); ?></strong>
			<?php esc_html_e( 'Every record of this type for this country is listed automatically, newest first. Leave the body above empty to keep it that way: put anything in the body and the page reverts to printing that instead.', 'daw' ); ?>
		<?php else : ?>
			<strong><?php esc_html_e( 'This page is printing its own body.', 'daw' ); ?></strong>
			<?php esc_html_e( 'It has words in it, so the hand-written version wins and nothing here is deleted. Empty the body to hand the page over to the database.', 'daw' ); ?>
		<?php endif; ?>
	</p>
	<p class="description">
		<strong><?php esc_html_e( 'Never type a count into this box.', 'daw' ); ?></strong>
		<?php esc_html_e( 'Use a token and it is filled in from the same query as the number at the top of the page:', 'daw' ); ?>
		<code>{dramas}</code> <code>{films}</code> <code>{people}</code> <code>{guides}</code>
		<code>{country}</code> <code>{short}</code> <code>{adjective}</code>
	</p>
	<textarea name="daw_section_intro" rows="5" style="width:100%" placeholder="<?php esc_attr_e( 'Every Thai drama in the archive, newest first…', 'daw' ); ?>"><?php echo esc_textarea( $intro ); ?></textarea>

	<?php
	// THE LONGER PIECE, AND WHY IT IS AT THE BOTTOM OF THE PAGE
	// --------------------------------------------------------
	// The old /korean-drama/ ended in a block of repeated phrases, which is the
	// thing this box exists to replace. It prints under the grid, where a reader
	// who wants the background finds it and a reader who wants the list is not
	// made to scroll past it. Written as writing, it earns the page its search
	// terms; written as a list of search terms, it costs the page its ranking.
	$outro = (string) get_post_meta( $post->ID, 'daw_section_outro', true );
	?>
	<p class="description" style="margin-top:18px">
		<strong><?php esc_html_e( 'The longer piece, printed under the list.', 'daw' ); ?></strong>
		<?php esc_html_e( 'A few paragraphs on what this collection is and how to read it. The same tokens work here. Basic HTML is allowed, and headings are welcome.', 'daw' ); ?>
	</p>
	<textarea name="daw_section_outro" rows="10" style="width:100%" placeholder="<?php esc_attr_e( 'How a Korean broadcast season is put together, why sixteen episodes…', 'daw' ); ?>"><?php echo esc_textarea( $outro ); ?></textarea>
	<?php
}

add_action( 'save_post_page', 'daw_section_meta_box_save' );
function daw_section_meta_box_save( $post_id ) {
	if ( ! isset( $_POST['daw_section_intro_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( $_POST['daw_section_intro_nonce'] ), 'daw_section_intro' )
		|| ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$intro = isset( $_POST['daw_section_intro'] ) ? wp_kses_post( wp_unslash( $_POST['daw_section_intro'] ) ) : '';

	if ( '' === trim( $intro ) ) {
		delete_post_meta( $post_id, 'daw_section_intro' );
	} else {
		update_post_meta( $post_id, 'daw_section_intro', $intro );
	}

	$outro = isset( $_POST['daw_section_outro'] ) ? wp_kses_post( wp_unslash( $_POST['daw_section_outro'] ) ) : '';

	if ( '' === trim( $outro ) ) {
		delete_post_meta( $post_id, 'daw_section_outro' );
	} else {
		update_post_meta( $post_id, 'daw_section_outro', $outro );
	}
}

/**
 * Certificates, counted.
 *
 * The fourth box in the archive card. It is the one fact on a record that
 * answers a question the other three cannot: whether the thing is watchable
 * with a child in the room. Records with the field empty are simply not
 * counted, so a country nobody has rated yet gets no box rather than a box
 * that says "unrated: 421".
 */
function daw_hub_ratings( $country, $limit = 8 ) {
	$key    = 'daw_hubrate_' . $country;
	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		return array_slice( $cached, 0, $limit );
	}

	$ids = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'tax_query'              => daw_hub_tax_query( $country, array( 'drama', 'film', 'variety' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		)
	);

	$tally = array();

	foreach ( $ids as $id ) {
		$cert = trim( (string) daw_field_raw( $id, 'daw_content_rating' ) );

		if ( '' === $cert ) {
			continue;
		}

		$tally[ $cert ] = ( $tally[ $cert ] ?? 0 ) + 1;
	}

	arsort( $tally );

	$out = array();
	foreach ( $tally as $cert => $n ) {
		$out[] = array( 'name' => $cert, 'n' => $n );
	}

	set_transient( $key, $out, 6 * HOUR_IN_SECONDS );

	return array_slice( $out, 0, $limit );
}

/* =============================================================== sections === */

/**
 * What a hub prints, in order, for every kind of record there is.
 *
 * WHY A TABLE AND NOT NINE IF STATEMENTS
 * --------------------------------------
 * This site is going to grow sideways. Today it holds dramas, films, groups
 * and four kinds of guide; tomorrow it holds manhwa, and after that anime, and
 * after that something nobody has thought of. If each of those means opening
 * the hub template and adding another block, then the eighth one is written at
 * speed on a Friday and does not look like the other seven.
 *
 * So a section is a row. Adding manhwa to every country hub on the site is one
 * line here plus the term itself, and it arrives with the same heading shape,
 * the same "see all" link and the same empty-state behaviour as everything
 * else.
 *
 * 'style'  poster (2:3 artwork, the shape a title or a cover is shot in) or
 *          guide (16:10, the shape a photograph of a dish or a street is).
 * 'n'      how many to show before the link takes over.
 * 'title'  %s is filled with the word in 'subject'.
 * 'subject' adjective ("Korean cinema") or short ("Travel in Korea"). The
 *          wrong one gives you "Travel in Korean", which is not English.
 *
 * A row whose type holds no records for this country prints nothing at all.
 * That is what keeps a country with three records looking deliberate: the page
 * is short because the archive is small, not because the template broke.
 */
function daw_hub_sections() {
	return array(
		'film'    => array(
			'style'   => 'poster',
			'n'       => 4,
			'title'   => __( '%s cinema', 'daw' ),
			'subject' => 'adjective',
			'note'    => '',
			'all'     => __( 'All %1$s %2$s films', 'daw' ),
			'all_one' => __( 'The one %2$s film', 'daw' ),
		),
		'group'   => array(
			'style'   => 'poster',
			'n'       => 4,
			'title'   => __( 'K-pop', 'daw' ),
			'subject' => 'adjective',
			'note'    => __( 'Groups and soloists, with the records they appear on.', 'daw' ),
			'all'     => __( 'All %1$s groups', 'daw' ),
			'all_one' => __( 'The one group', 'daw' ),
		),
		'variety' => array(
			'style'   => 'poster',
			'n'       => 4,
			'title'   => __( '%s variety shows', 'daw' ),
			'subject' => 'adjective',
			'note'    => __( 'Talk, reality and game shows, and who turns up on them.', 'daw' ),
			'all'     => __( 'All %1$s shows', 'daw' ),
			'all_one' => __( 'The one show', 'daw' ),
		),
		'anime'   => array(
			'style'   => 'poster',
			'n'       => 4,
			'title'   => __( '%s animation', 'daw' ),
			'subject' => 'adjective',
			'note'    => __( 'Series and features, with the studios behind them.', 'daw' ),
			'all'     => __( 'All %1$s titles', 'daw' ),
			'all_one' => __( 'The one title', 'daw' ),
		),
		'manga'   => array(
			'style'   => 'poster',
			'n'       => 4,
			'title'   => __( '%s comics', 'daw' ),
			'subject' => 'adjective',
			'note'    => __( 'Manhwa, manga and the adaptations they became.', 'daw' ),
			'all'     => __( 'All %1$s titles', 'daw' ),
			'all_one' => __( 'The one title', 'daw' ),
		),
		'food'    => array(
			'style'   => 'guide',
			'n'       => 3,
			'title'   => __( '%s food and drink', 'daw' ),
			'subject' => 'adjective',
			'note'    => __( 'What it is, how it is eaten, and where it came from.', 'daw' ),
			'all'     => __( 'All %1$s guides', 'daw' ),
			'all_one' => __( 'The one guide', 'daw' ),
		),
		'culture' => array(
			'style'   => 'guide',
			'n'       => 3,
			'title'   => __( '%s culture', 'daw' ),
			'subject' => 'adjective',
			'note'    => __( 'The customs, the clothes and the words behind them.', 'daw' ),
			'all'     => __( 'All %1$s guides', 'daw' ),
			'all_one' => __( 'The one guide', 'daw' ),
		),
		'myth'    => array(
			'style'   => 'guide',
			'n'       => 3,
			'title'   => __( '%s myths and legends', 'daw' ),
			'subject' => 'adjective',
			'note'    => __( 'The old stories, and where they turn up on screen today.', 'daw' ),
			'all'     => __( 'All %1$s guides', 'daw' ),
			'all_one' => __( 'The one guide', 'daw' ),
		),
		'travel'  => array(
			'style'   => 'guide',
			'n'       => 3,
			'title'   => __( 'Travel in %s', 'daw' ),
			'subject' => 'short',
			'note'    => __( 'Places worth the trip, with what to expect when you get there.', 'daw' ),
			'all'     => __( 'All %1$s guides', 'daw' ),
			'all_one' => __( 'The one guide', 'daw' ),
		),
		'quiz'    => array(
			'style'   => 'guide',
			'n'       => 3,
			'title'   => __( 'Test yourself', 'daw' ),
			'subject' => 'adjective',
			'note'    => __( 'How much of this do you actually know?', 'daw' ),
			'all'     => __( 'All %1$s quizzes', 'daw' ),
			'all_one' => __( 'The one quiz', 'daw' ),
		),
	);
}

/**
 * The records for one hub section.
 *
 * Titles have an air date and are ordered by it; a guide does not and is
 * ordered by when it was last worked on. One function so the template never
 * has to know which is which.
 */
/**
 * Which records a country section leads with.
 *
 * TWO DIFFERENT QUESTIONS, TWO DIFFERENT ORDERS
 * ---------------------------------------------
 * For dramas and films the question is what came out lately, so they are
 * ordered by release date and the section reads as a calendar. For people,
 * groups, food, culture, myths, travel and the quizzes there is no release
 * date to sort by and "most recently edited" tells a reader nothing at all,
 * so those sections lead with what has actually been read in the last thirty
 * days. A wider pool is fetched first and the reading decides the order inside
 * it, which keeps a section from being frozen around whichever six records
 * happened to be newest.
 *
 * While the counter is still empty every score is nought, the sort is stable,
 * and every section comes out exactly as it did before.
 */
function daw_hub_section_records( $country, $type, $style, $limit ) {
	$limit    = (int) $limit;
	$by_views = in_array( $type, array( 'person', 'group', 'food', 'culture', 'myth', 'travel', 'quiz' ), true );

	if ( $by_views && function_exists( 'daw_views_sort' ) ) {
		$pool = max( 40, $limit * 8 );
		$ids  = 'poster' === $style
			? daw_hub_records( $country, $type, $pool )
			: daw_hub_articles( $country, $type, $pool );

		return array_slice( daw_views_sort( $ids ), 0, $limit );
	}

	return 'poster' === $style
		? daw_hub_records( $country, $type, $limit )
		: daw_hub_articles( $country, $type, $limit );
}

/**
 * Fill the counts into the editor's paragraph.
 *
 * WHY THIS EXISTS
 * ---------------
 * The first draft of the Korea guide opened "421 dramas, 162 films, 245 people
 * and 36 guides". Every one of those numbers was typed, and the counters at the
 * top of the same page were counted, so the day somebody filed the 422nd drama
 * the page disagreed with itself in its second sentence. That is worse than no
 * number at all: a reader who spots it stops believing the rest.
 *
 * So the paragraph writes {dramas} and this fills it in from the same query the
 * strip above uses. An unknown token is left alone rather than replaced with an
 * empty string, because a visible {typo} is a bug somebody fixes and a silent
 * gap is a bug nobody sees.
 */
function daw_hub_fill_counts( $text, $country, $hub ) {
	if ( false === strpos( $text, '{' ) ) {
		return $text;
	}

	$counts = daw_hub_counts( $country );

	$guides = 0;
	foreach ( ( function_exists( 'daw_guide_types' ) ? daw_guide_types() : array() ) as $gt ) {
		$guides += (int) ( $counts[ $gt ] ?? 0 );
	}

	$map = array(
		'{country}'   => $hub['name'],
		'{short}'     => $hub['short'] ?? $hub['name'],
		'{adjective}' => $hub['adjective'],
		'{guides}'    => number_format_i18n( $guides ),
	);

	// Every record type answers to its own name, so a type added to the site
	// becomes a token on every hub without anyone editing this function.
	foreach ( $counts as $type => $n ) {
		$map[ '{' . $type . 's}' ] = number_format_i18n( $n );
		$map[ '{' . $type . '}' ]  = number_format_i18n( $n );
	}

	$map['{people}'] = number_format_i18n( (int) ( $counts['person'] ?? 0 ) );

	return strtr( $text, $map );
}

/* ============================================================== coverage === */

/**
 * Which records have no country, and which have no type.
 *
 * WHY IT IS A PAGE AND NOT A ONE-OFF SCRIPT
 * -----------------------------------------
 * A record with no country is invisible on every hub, and nothing anywhere
 * says so: the page just quietly holds one fewer card. Sixteen guides were in
 * that state and nobody knew, including the person who wrote them. A gap that
 * reports itself gets closed; a gap that has to be looked for does not.
 *
 * It lists rather than guesses. Reading a title and deciding a dish is
 * Japanese is a judgement, and a plugin that made that judgement silently over
 * nine hundred records would be wrong about some of them with nobody watching.
 */
add_action( 'admin_menu', 'daw_country_report_menu' );
function daw_country_report_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: Countries', 'daw' ),
		__( 'DAW: Countries', 'daw' ),
		'manage_options',
		'daw-countries',
		'daw_country_report_page'
	);
}

function daw_country_report_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$ids = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
		)
	);

	$missing = array();
	$tally   = array();

	foreach ( $ids as $id ) {
		$c = wp_get_post_terms( $id, 'dw_country', array( 'fields' => 'names' ) );
		$t = daw_get_type( $id );

		if ( is_wp_error( $c ) || ! $c ) {
			$missing[] = array( 'id' => $id, 'type' => $t );
			continue;
		}

		foreach ( $c as $name ) {
			$tally[ $name ] = ( $tally[ $name ] ?? 0 ) + 1;
		}

		// Two countries on one record is not a co-production, it is a mistake
		// somebody made twice. The hub counts it under both and the breadcrumb
		// picks whichever came back first.
		if ( count( $c ) > 1 ) {
			$missing[] = array( 'id' => $id, 'type' => $t, 'note' => implode( ' + ', $c ) );
		}
	}

	arsort( $tally );

	echo '<div class="wrap"><h1>DAW: Countries</h1>';

	printf(
		'<p><strong>%1$d</strong> record(s). <strong>%2$d</strong> need attention.</p>',
		count( $ids ),
		count( $missing )
	);

	echo '<table class="widefat striped" style="max-width:520px"><thead><tr><th>' . esc_html__( 'Country', 'daw' ) . '</th><th>' . esc_html__( 'Records', 'daw' ) . '</th></tr></thead><tbody>';
	foreach ( $tally as $name => $n ) {
		printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $name ), esc_html( number_format_i18n( $n ) ) );
	}
	echo '</tbody></table>';

	if ( ! $missing ) {
		echo '<p style="color:#1a7f37"><strong>' . esc_html__( 'Every record has exactly one country.', 'daw' ) . '</strong></p></div>';
		return;
	}

	printf( '<h2>%s <span style="font-weight:400">(%d)</span></h2>', esc_html__( 'Needs a country', 'daw' ), count( $missing ) );
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Record', 'daw' ) . '</th><th>' . esc_html__( 'Type', 'daw' ) . '</th><th>' . esc_html__( 'Problem', 'daw' ) . '</th></tr></thead><tbody>';

	foreach ( $missing as $row ) {
		printf(
			'<tr><td><a href="%1$s">%2$s</a></td><td>%3$s</td><td>%4$s</td></tr>',
			esc_url( (string) get_edit_post_link( $row['id'] ) ),
			esc_html( get_the_title( $row['id'] ) ),
			esc_html( $row['type'] ? $row['type'] : '—' ),
			empty( $row['note'] )
				? '<span style="color:#b32d2e">' . esc_html__( 'no country', 'daw' ) . '</span>'
				: '<span style="color:#b32d2e">' . esc_html( $row['note'] ) . '</span>'
		);
	}

	echo '</tbody></table></div>';
}

/* ============================================================== sections === */

/**
 * Every section page on the site, keyed by slug.
 *
 * WHY THIS IS DERIVED AND NOT TYPED
 * ---------------------------------
 * The country table above already says which page a country's dramas live on.
 * Writing that relationship down a second time, the other way round, is how
 * the two lists drift: somebody adds Thai food to the hub map, forgets the
 * reverse index, and the hub links to a page that does not know what it is.
 * So this reads the one table and turns it inside out. A section added to a
 * country appears here on the same save.
 *
 * Returns slug => array( country, type, hub ) where hub is the country page
 * slug, so a section page can link back to the hub it belongs to.
 */
function daw_section_pages() {
	static $map = null;

	if ( null !== $map ) {
		return $map;
	}

	$map = array();

	foreach ( daw_country_hubs() as $hub_slug => $hub ) {
		foreach ( ( $hub['sections'] ?? array() ) as $type => $page_slug ) {
			$map[ $page_slug ] = array(
				'country'   => $hub['country'],
				'type'      => $type,
				'hub'       => $hub_slug,
				'name'      => $hub['name'],
				'short'     => $hub['short'] ?? $hub['name'],
				'adjective' => $hub['adjective'],
			);
		}
	}

	return $map;
}

/** The section definition for a page, or null when the page is not a section. */
function daw_section_for_page( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();

	if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
		return null;
	}

	$map  = daw_section_pages();
	$slug = (string) get_post_field( 'post_name', $post_id );

	return isset( $map[ $slug ] ) ? $map[ $slug ] + array( 'slug' => $slug ) : null;
}

/**
 * Whether a section page is drawn from the database.
 *
 * THE SWITCH IS THE PAGE'S OWN BODY
 * ---------------------------------
 * The hubs needed an explicit toggle because every one of them had a body
 * somebody wrote and none of them could be allowed to vanish on a plugin
 * upgrade. Section pages split cleanly in two instead, and the split is
 * visible in the page itself.
 *
 * /korean-drama/ is two hundred and forty nine thousand characters of
 * hand-written list. /thai-drama/ is a page created empty so this template
 * could fill it. An empty body is not an accident here; it is the only reason
 * such a page exists. So an empty body draws from the database and anything
 * with words in it keeps its words, and there is no third state where a
 * toggle and a body disagree about which one wins.
 *
 * Strict about what empty means: block editor leftovers, a stray non-breaking
 * space and an empty paragraph all still count as nothing to show.
 */
function daw_section_is_live( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();

	if ( ! $post_id ) {
		return false;
	}

	$body = (string) get_post_field( 'post_content', $post_id );
	$body = preg_replace( '/<!--.*?-->/s', '', $body );          // block comments
	$body = str_replace( "\xc2\xa0", ' ', wp_strip_all_tags( $body ) );

	return '' === trim( $body );
}

/**
 * Route an empty section page to the section template.
 *
 * Runs after the hub router and never competes with it: a page is either in
 * the country table or in the section table, never in both.
 */
add_filter( 'page_template', 'daw_route_section_template', 11 );
function daw_route_section_template( $template ) {
	$section = daw_section_for_page();

	if ( ! $section || ! daw_section_is_live() ) {
		return $template;
	}

	$found = locate_template( array( 'page-section.php' ) );

	return $found ? $found : $template;
}

/**
 * Every record of one type for one country, newest first.
 *
 * The hub asks for four films. A section page is the page that promised "all
 * 23", so it asks for all of them and gets them in the same order, drawn by
 * the same cards, which is the only reason the two pages look like one site.
 */
function daw_section_records( $country, $type ) {
	$sections = daw_hub_sections();
	$style    = $sections[ $type ]['style'] ?? 'poster';

	return daw_hub_section_records( $country, $type, $style, 500 );
}

/**
 * The one record the home page leads with.
 *
 * WHY IT IS A SETTING AND NOT A CALCULATION
 * -----------------------------------------
 * "Featured this week" is an editorial sentence. A site that works out its own
 * featured title by picking the newest one is not featuring anything, it is
 * sorting, and saying otherwise in the eyebrow would be the only untrue line on
 * the page. So it is chosen, in Settings → Reading, and when nothing is chosen
 * the home page simply starts at the row below and says nothing it cannot back.
 */
function daw_featured_record() {
	$id   = (int) get_option( 'daw_featured_record' );
	$post = $id ? get_post( $id ) : null;

	if ( $post && 'publish' === $post->post_status && 'post' === $post->post_type ) {
		return $id;
	}

	// Without a pin, a title with an episode out today leads (home-onair.php).
	return (int) apply_filters( 'daw_featured_record_auto', daw_featured_auto() );
}

/**
 * The record the home page leads with when nobody has pinned one.
 *
 * HOW IT IS CHOSEN
 * ----------------
 * Take the twenty most recently released dramas and films, by the date the work
 * came out rather than the date we typed it in, and lead with whichever of those
 * twenty has been read most in the last thirty days. Recency decides who is
 * eligible; readers decide which one wins.
 *
 * WHY TWENTY AND NOT THE NEWEST
 * -----------------------------
 * The newest release is an accident of what aired last week. Twenty is wide
 * enough that a title people are actually watching can beat one that merely
 * came out on Friday, and narrow enough that nothing from three years ago can
 * sit at the top of the page calling itself new.
 *
 * WHILE THE COUNTER IS STILL EMPTY
 * --------------------------------
 * Every candidate has nought views, the sort is stable, and the newest release
 * wins by default. So the slot is right from the first day and changes by
 * itself, with no switch to throw, once there are numbers to change it.
 */
function daw_featured_auto() {
	$week  = gmdate( 'o-\\WW' );
	$saved = get_option( 'daw_featured_week' );

	// WHY THE CHOICE IS HELD FOR THE WEEK
	// -----------------------------------
	// The slot calls itself the drama of the week, so it has to behave like
	// one. A pick that recomputed every hour would put a different title at the
	// top of the page three times in an afternoon, and the words above it would
	// be wrong every time. It is worked out once, on the first request of a new
	// week, and then it stands.
	if ( is_array( $saved ) && isset( $saved['week'], $saved['id'] ) && $saved['week'] === $week ) {
		$post = (int) $saved['id'] ? get_post( (int) $saved['id'] ) : null;

		if ( $post && 'publish' === $post->post_status ) {
			return (int) $saved['id'];
		}
	}

	$pool = daw_featured_pool();
	$id   = $pool ? (int) $pool[0] : 0;

	update_option(
		'daw_featured_week',
		array(
			'week' => $week,
			'id'   => $id,
		),
		false
	);

	return $id;
}

/** How many recent releases compete for the slot. */
function daw_featured_pool_size() {
	return (int) apply_filters( 'daw_featured_pool_size', 20 );
}

/**
 * The twenty newest releases, ordered by how much they were read.
 *
 * Sorting happens here in PHP rather than in the query, because ordering by a
 * meta key makes WordPress join the meta table and quietly drop every record
 * that does not carry the key. A drama with no release date on file should be
 * left out of this list on purpose, not by accident.
 */
function daw_featured_pool() {
	$ids = get_posts(
		array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => array( 'drama', 'film' ),
				),
			),
		)
	);

	$ids = array_map( 'intval', (array) $ids );

	if ( ! $ids ) {
		return array();
	}

	if ( function_exists( 'daw_browse_prime' ) ) {
		daw_browse_prime( $ids );
	}

	$dated = array();

	foreach ( $ids as $id ) {
		$start = (int) get_post_meta( $id, 'daw_aired_start', true );

		// A record with no poster cannot lead a page that is mostly poster.
		if ( $start > 0 && ( function_exists( 'daw_has_record_image' ) ? daw_has_record_image( $id ) : has_post_thumbnail( $id ) ) ) {
			$dated[ $id ] = $start;
		}
	}

	if ( ! $dated ) {
		return array();
	}

	// Nothing that has not come out yet. A release slate is a different page.
	$now = time();

	$dated = array_filter(
		$dated,
		static function ( $start ) use ( $now ) {
			return $start <= $now;
		}
	);

	arsort( $dated );

	$pool = array_slice( array_keys( $dated ), 0, daw_featured_pool_size() );

	return function_exists( 'daw_views_sort' ) ? daw_views_sort( $pool ) : $pool;
}

/**
 * What the hero calls itself, which depends on what it is holding.
 *
 * Dramas and films compete for the same slot, so one fixed line would be wrong
 * half the time. The label follows the record: a drama leads the page as the
 * drama of the week and a film as the film of the week. It is a selection
 * sentence rather than a measurement, which is why it is allowed to be there on
 * the first day, before the counter has anything in it, and why the choice is
 * held for the whole week rather than moving every hour.
 */
function daw_featured_eyebrow( $id ) {
	$id   = (int) $id;
	$type = ( $id && function_exists( 'daw_get_type' ) ) ? daw_get_type( $id ) : '';

	// "Episode 7 today" or "On air now" says more than "of the week" (1.75.78).
	if ( function_exists( 'daw_onair_eyebrow' ) ) {
		$live = daw_onair_eyebrow( $id, '' );
		if ( '' !== $live ) {
			return $live;
		}
	}

	switch ( $type ) {
		case 'drama':
			return __( 'Drama of the week', 'daw' );

		case 'film':
			return __( 'Film of the week', 'daw' );

		case 'person':
			return __( 'Profile of the week', 'daw' );

		case 'group':
			return __( 'Group of the week', 'daw' );
	}

	return __( 'Featured this week', 'daw' );
}

add_action( 'admin_init', 'daw_featured_setting' );
function daw_featured_setting() {
	register_setting(
		'reading',
		'daw_featured_record',
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		)
	);

	add_settings_field(
		'daw_featured_record',
		__( 'DAW: featured on the home page', 'daw' ),
		'daw_featured_field',
		'reading'
	);
}

function daw_featured_field() {
	$id      = (int) get_option( 'daw_featured_record' );
	$current = $id ? get_post( $id ) : null;

	printf(
		'<input type="number" name="daw_featured_record" value="%s" class="small-text" min="0"> ',
		esc_attr( (string) $id )
	);

	if ( $current ) {
		printf(
			'<strong>%s</strong>',
			esc_html( get_the_title( $current ) )
		);
	}

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'The id of the drama, film or person the home page leads with. Leave it at 0 and the slot fills itself: the twenty most recently released dramas and films compete for it, and the one readers opened most in the last thirty days wins. Put an id in only when you want to override that.', 'daw' )
	);
}

/* ================================================================ flags === */

/**
 * The eight national flags, drawn rather than fetched.
 *
 * WHY THEY ARE DRAWN
 * ------------------
 * Eight small pictures that will never change are eight requests, eight cache
 * entries and eight chances for one of them to be the thing that is still
 * loading when the reader scrolls past. Inline they cost a few hundred bytes
 * each, they are sharp at every pixel density, and they cannot arrive late and
 * push the row around.
 *
 * WHY THEY ARE SIMPLIFIED
 * -----------------------
 * At sixteen pixels tall nobody can see twelve sun rays or the veins of a
 * bauhinia petal. Each one keeps the two or three marks that make it
 * recognisable at that size and drops the rest, which is what a flag at this
 * scale is for: telling the reader which row is which without them reading a
 * word.
 */
function daw_country_flag( $slug, $height = 14 ) {
	$w = (int) round( $height * 1.5 );
	$h = (int) $height;

	$art = daw_country_flag_art( $slug );

	if ( '' === $art ) {
		return '';
	}

	$id = 'daw-flagclip-' . sanitize_html_class( $slug );

	return sprintf(
		'<svg class="daw-flag" width="%1$d" height="%2$d" viewBox="0 0 24 16" role="img" aria-hidden="true" focusable="false">' .
		'<defs><clipPath id="%3$s"><rect width="24" height="16" rx="2.5"/></clipPath></defs>' .
		'<g clip-path="url(#%3$s)">%4$s</g>' .
		'<rect width="24" height="16" rx="2.5" fill="none" stroke="rgba(0,0,0,.14)"/>' .
		'</svg>',
		$w,
		$h,
		esc_attr( $id ),
		$art // phpcs:ignore WordPress.Security.EscapeOutput
	);
}

function daw_country_flag_art( $slug ) {
	switch ( $slug ) {
		case 'japan':
			return '<rect width="24" height="16" fill="#fff"/><circle cx="12" cy="8" r="4.3" fill="#bc002d"/>';

		case 'south-korea':
			return '<rect width="24" height="16" fill="#fff"/>' .
				'<path d="M12 3.6a4.4 4.4 0 0 1 0 8.8 2.2 2.2 0 0 0 0-4.4 2.2 2.2 0 0 1 0-4.4z" fill="#cd2e3a"/>' .
				'<path d="M12 12.4a4.4 4.4 0 0 1 0-8.8 2.2 2.2 0 0 0 0 4.4 2.2 2.2 0 0 1 0 4.4z" fill="#0047a0"/>' .
				'<g fill="#111"><rect x="2.6" y="3.4" width="3.4" height=".7" transform="rotate(33 4.3 3.75)"/>' .
				'<rect x="2.6" y="12" width="3.4" height=".7" transform="rotate(-33 4.3 12.35)"/>' .
				'<rect x="18" y="3.4" width="3.4" height=".7" transform="rotate(-33 19.7 3.75)"/>' .
				'<rect x="18" y="12" width="3.4" height=".7" transform="rotate(33 19.7 12.35)"/></g>';

		case 'china':
			return '<rect width="24" height="16" fill="#de2910"/>' .
				'<path d="m5.2 3 .85 2.6L3.9 4h2.6L4.35 5.6z" fill="#ffde00"/>' .
				'<g fill="#ffde00"><circle cx="9.4" cy="2.5" r=".8"/><circle cx="11.1" cy="4.3" r=".8"/>' .
				'<circle cx="11.1" cy="6.8" r=".8"/><circle cx="9.4" cy="8.6" r=".8"/></g>';

		case 'taiwan':
			return '<rect width="24" height="16" fill="#fe0000"/><rect width="12" height="8" fill="#000095"/>' .
				'<circle cx="6" cy="4" r="3" fill="#fff"/><circle cx="6" cy="4" r="1.7" fill="#000095"/>';

		case 'thailand':
			return '<rect width="24" height="16" fill="#a51931"/><rect y="2.7" width="24" height="10.6" fill="#f4f5f8"/>' .
				'<rect y="5.3" width="24" height="5.4" fill="#2d2a4a"/>';

		case 'hong-kong':
			return '<rect width="24" height="16" fill="#de2910"/>' .
				'<g fill="#fff"><ellipse cx="12" cy="4.4" rx="1.1" ry="2.5"/>' .
				'<ellipse cx="15.4" cy="6.9" rx="1.1" ry="2.5" transform="rotate(72 15.4 6.9)"/>' .
				'<ellipse cx="14.1" cy="10.9" rx="1.1" ry="2.5" transform="rotate(144 14.1 10.9)"/>' .
				'<ellipse cx="9.9" cy="10.9" rx="1.1" ry="2.5" transform="rotate(216 9.9 10.9)"/>' .
				'<ellipse cx="8.6" cy="6.9" rx="1.1" ry="2.5" transform="rotate(288 8.6 6.9)"/></g>';

		case 'philippines':
			return '<rect width="24" height="16" fill="#0038a8"/><rect y="8" width="24" height="8" fill="#ce1126"/>' .
				'<path d="M0 0 13.9 8 0 16z" fill="#fff"/>' .
				'<circle cx="4.3" cy="8" r="1.7" fill="#fcd116"/>' .
				'<g fill="#fcd116"><circle cx="1.5" cy="1.7" r=".7"/><circle cx="1.5" cy="14.3" r=".7"/><circle cx="10.8" cy="8" r=".7"/></g>';

		case 'singapore':
			return '<rect width="24" height="16" fill="#fff"/><rect width="24" height="8" fill="#ed2939"/>' .
				'<circle cx="5.4" cy="4" r="2.8" fill="#fff"/><circle cx="6.9" cy="4" r="2.6" fill="#ed2939"/>' .
				'<g fill="#fff"><circle cx="9.2" cy="2.1" r=".55"/><circle cx="11.1" cy="3.4" r=".55"/>' .
				'<circle cx="10.4" cy="5.6" r=".55"/><circle cx="8" cy="5.6" r=".55"/><circle cx="7.3" cy="3.4" r=".55"/></g>';
	}

	return '';
}
