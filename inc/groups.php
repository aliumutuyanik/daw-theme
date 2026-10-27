<?php
/**
 * Discover Asian World — bands, and the people in them.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * BTS is a group. Jimin is a singer who is also in BTS. Before this file the
 * archive had one word for both of them, so Jimin's card read "Music Group"
 * under his face and the K-pop page counted him as a band. The fix is not a
 * label change: a soloist is a person record, a band is a group record, and the
 * thing that connects them is a field on the person that names the band.
 *
 * ONE FIELD, NOT TWO
 * ------------------
 * The membership is stored once, on the person, in daw_member_of. The group's
 * member list is read back from it. Storing it on both sides would mean two
 * places to forget, and a band whose page lists a member the member's own page
 * does not.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * The group a person belongs to, or null.
 *
 * The field takes whatever an editor is most likely to have to hand: a post id,
 * a slug, or the band's name as it is written on its own record. Anything that
 * does not resolve to a published group returns null rather than a guess.
 */
function daw_person_group( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	$raw     = trim( (string) get_post_meta( $post_id, 'daw_member_of', true ) );

	if ( '' === $raw ) {
		return null;
	}

	$group = null;

	if ( ctype_digit( $raw ) ) {
		$group = get_post( (int) $raw );
	}

	if ( ! $group ) {
		$group = get_page_by_path( sanitize_title( $raw ), OBJECT, 'post' );
	}

	if ( ! $group ) {
		$found = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'title'            => $raw,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		$group = $found ? get_post( $found[0] ) : null;
	}

	if ( ! $group || 'publish' !== $group->post_status ) {
		return null;
	}

	return has_term( 'group', 'dw_type', $group ) ? $group : null;
}

/**
 * Everyone whose record names this group, in the order their records were added.
 *
 * Reading the members back out of the person records means the band page can
 * never disagree with the member pages, and adding a member is one field on one
 * record rather than an edit in two places.
 */
function daw_group_members( $group_id = 0 ) {
	$group_id = $group_id ? (int) $group_id : (int) get_the_ID();

	if ( ! $group_id ) {
		return array();
	}

	$key = 'daw_group_members_' . $group_id . '_' . ( function_exists( 'daw_browse_gen' ) ? daw_browse_gen() : 1 );
	$hit = get_transient( $key );

	if ( is_array( $hit ) ) {
		return $hit;
	}

	$group   = get_post( $group_id );
	$needles = array( (string) $group_id );

	if ( $group ) {
		$needles[] = $group->post_name;
		$needles[] = $group->post_title;
	}

	$ids = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'             => array(
				array(
					'key'     => 'daw_member_of',
					'value'   => $needles,
					'compare' => 'IN',
				),
			),
		)
	);

	$ids = array_values(
		array_filter(
			$ids,
			static function ( $id ) {
				return has_term( 'person', 'dw_type', $id );
			}
		)
	);

	set_transient( $key, $ids, 6 * HOUR_IN_SECONDS );

	return $ids;
}

/* ============================================================== fields === */

add_filter( 'rwmb_meta_boxes', 'daw_register_group_meta_boxes', 13 );
function daw_register_group_meta_boxes( $boxes ) {
	$boxes[] = array(
		'id'         => 'daw_group_info',
		'title'      => __( 'Group — identity and formation', 'daw' ),
		'post_types' => array( 'post' ),
		'context'    => 'normal',
		'priority'   => 'high',
		'daw_types'  => array( 'group' ),
		'fields'     => array(
			array(
				'id'      => 'daw_native_name',
				'name'    => __( 'Native name', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
			),
			array(
				'id'      => 'daw_agency',
				'name'    => __( 'Agency or label', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
			),
			array(
				'id'      => 'daw_group_debut',
				'name'    => __( 'Debut', 'daw' ),
				'type'    => 'text',
				'columns' => 4,
				'desc'    => __( 'The date the group debuted, as it was announced. Leave empty rather than guessing.', 'daw' ),
			),
			array(
				'id'      => 'daw_group_fandom',
				'name'    => __( 'Fandom name', 'daw' ),
				'type'    => 'text',
				'columns' => 4,
			),
			array(
				'id'      => 'daw_group_status',
				'name'    => __( 'Status', 'daw' ),
				'type'    => 'select',
				'columns' => 4,
				'options' => array(
					''           => __( '— not stated —', 'daw' ),
					'active'     => __( 'Active', 'daw' ),
					'hiatus'     => __( 'On hiatus', 'daw' ),
					'disbanded'  => __( 'Disbanded', 'daw' ),
				),
			),
			array(
				'id'   => 'daw_group_summary',
				'name' => __( 'Summary', 'daw' ),
				'type' => 'textarea',
				'rows' => 4,
				'desc' => __( 'Two or three sentences. What the group is, and what it is known for.', 'daw' ),
			),
			array(
				'id'      => 'daw_group_kind',
				'name'    => __( 'Group type', 'daw' ),
				'type'    => 'select',
				'columns' => 4,
				'options' => array(
					''       => __( '— not stated —', 'daw' ),
					'girl'   => __( 'Girl group', 'daw' ),
					'boy'    => __( 'Boy group', 'daw' ),
					'co-ed'  => __( 'Co-ed group', 'daw' ),
					'duo'    => __( 'Duo', 'daw' ),
					'band'   => __( 'Band', 'daw' ),
				),
			),
			array(
				'id'      => 'daw_group_formed',
				'name'    => __( 'Formed', 'daw' ),
				'type'    => 'text',
				'columns' => 4,
				'desc'    => __( 'The year the group was put together, which is often not the year it debuted.', 'daw' ),
			),
			array(
				'id'      => 'daw_group_based',
				'name'    => __( 'Based in', 'daw' ),
				'type'    => 'text',
				'columns' => 4,
			),
			array(
				'id'      => 'daw_group_member_count',
				'name'    => __( 'Members in the line-up', 'daw' ),
				'type'    => 'number',
				'columns' => 3,
				// A COUNT OF PEOPLE, NOT A COUNT OF OUR PROFILES
				// ----------------------------------------------
				// The hero used to count the member records linked to this
				// group, which is a number about the archive rather than about
				// the band. BTS has seven members and one profile here, and the
				// page said "1 member", which is not a shortcut, it is wrong.
				// So the real figure is typed and the profiles are what they
				// are: a grid that fills in as the records get written.
				'desc'    => __( 'How many people are in the current line-up, whether or not each one has a profile here yet. Leave empty and the page counts the profiles instead.', 'daw' ),
			),
			array(
				'id'      => 'daw_group_former_name',
				'name'    => __( 'Former name', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
			),
			array(
				'id'      => 'daw_group_nicknames',
				'name'    => __( 'Nicknames', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
				'desc'    => __( 'What fans and the press actually call them, comma separated.', 'daw' ),
			),
			array(
				'id'      => 'daw_group_label',
				'name'    => __( 'Record label', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
				'desc'    => __( 'The label the music is released on, where it is not the agency.', 'daw' ),
			),
			array(
				'id'      => 'daw_group_debut_release',
				'name'    => __( 'Debut release', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
			),
			array(
				'id'      => 'daw_group_fandom_colour',
				'name'    => __( 'Fandom colour', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
			),
			array(
				'id'      => 'daw_group_lightstick',
				'name'    => __( 'Official lightstick', 'daw' ),
				'type'    => 'select',
				'columns' => 6,
				'options' => array(
					''    => __( '— not stated —', 'daw' ),
					'yes' => __( 'Yes', 'daw' ),
					'no'  => __( 'No', 'daw' ),
				),
			),
		),
	);

	/* ------------------------------------------------------- discography - */

	// ONE ROW PER RELEASE, NOT ONE FIELD PER ALBUM
	// -------------------------------------------
	// A discography is a list that grows, so it is a repeating row rather than
	// a numbered set of fields somebody has to keep extending. Every column
	// here is something a release actually has on its own cover or its own
	// streaming page; nothing is a rating, a rank or an opinion.
	$boxes[] = array(
		'id'         => 'daw_group_discography',
		'title'      => __( 'Group — discography', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'group' ),
		'fields'     => array(
			array(
				'id'         => 'daw_rel',
				'name'       => __( 'Release', 'daw' ),
				'type'       => 'group',
				'clone'      => true,
				'sort_clone' => true,
				'collapsible' => true,
				'group_title' => '{#}. {daw_rel_title}',
				'fields'     => array(
					array(
						'id'      => 'daw_rel_title',
						'name'    => __( 'Title', 'daw' ),
						'type'    => 'text',
						'columns' => 6,
					),
					array(
						'id'      => 'daw_rel_native',
						'name'    => __( 'Native title', 'daw' ),
						'type'    => 'text',
						'columns' => 6,
					),
					array(
						'id'      => 'daw_rel_type',
						'name'    => __( 'Type', 'daw' ),
						'type'    => 'select',
						'columns' => 3,
						'options' => array(
							'studio'   => __( 'Studio album', 'daw' ),
							'mini'     => __( 'Mini album', 'daw' ),
							'single'   => __( 'Single', 'daw' ),
							'digital'  => __( 'Digital single', 'daw' ),
							'ost'      => __( 'OST', 'daw' ),
							'repack'   => __( 'Repackage', 'daw' ),
							'live'     => __( 'Live album', 'daw' ),
						),
					),
					array(
						'id'      => 'daw_rel_date',
						'name'    => __( 'Release date', 'daw' ),
						'type'    => 'text',
						'columns' => 3,
						'desc'    => __( 'It drives the order, so write it as a date.', 'daw' ),
					),
					array(
						'id'      => 'daw_rel_tracks',
						'name'    => __( 'Tracks', 'daw' ),
						'type'    => 'number',
						'columns' => 3,
					),
					array(
						'id'      => 'daw_rel_label',
						'name'    => __( 'Label', 'daw' ),
						'type'    => 'text',
						'columns' => 3,
					),
					array(
						'id'      => 'daw_rel_track',
						'name'    => __( 'Title track', 'daw' ),
						'type'    => 'text',
						'columns' => 6,
					),
					array(
						'id'      => 'daw_rel_linked',
						'name'    => __( 'Linked title', 'daw' ),
						'type'    => 'text',
						'columns' => 6,
						'desc'    => __( 'For an OST: the drama or film it belongs to, by name or id.', 'daw' ),
					),
					array(
						'id'         => 'daw_rel_cover',
						'name'       => __( 'Cover art', 'daw' ),
						'type'       => 'single_image',
						'columns'    => 3,
						'desc'       => __( 'The sleeve, credited to its rights holder under the same rule as a poster. A release with no cover still gets a card; the space is simply left blank rather than filled with somebody else\'s picture.', 'daw' ),
					),
					array(
						'id'       => 'daw_rel_cover_url',
						'name'     => __( 'Cover art from Spotify', 'daw' ),
						'type'     => 'text',
						'columns'  => 3,
						'readonly' => true,
						'desc'     => __( 'Written by the nightly Spotify read and shown at Spotify\'s own address rather than copied here, which is how their terms expect artwork to be used. An uploaded cover above wins over this one.', 'daw' ),
					),
					array(
						'id'      => 'daw_rel_spotify',
						'name'    => __( 'Spotify url', 'daw' ),
						'type'    => 'url',
						'columns' => 6,
					),
					array(
						'id'      => 'daw_rel_apple',
						'name'    => __( 'Apple Music url', 'daw' ),
						'type'    => 'url',
						'columns' => 6,
					),
				),
			),
		),
	);

	/* ------------------------------------------------------ listen & follow */

	$boxes[] = array(
		'id'         => 'daw_group_links',
		'title'      => __( 'Group — listen and follow', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'group' ),
		'fields'     => array(
			array( 'id' => 'daw_spotify_artist', 'name' => __( 'Spotify artist', 'daw' ), 'type' => 'url', 'columns' => 6 ),
			array( 'id' => 'daw_apple_artist', 'name' => __( 'Apple Music artist', 'daw' ), 'type' => 'url', 'columns' => 6 ),
			array( 'id' => 'daw_social_youtube', 'name' => __( 'YouTube', 'daw' ), 'type' => 'url', 'columns' => 6 ),
			array( 'id' => 'daw_social_instagram', 'name' => __( 'Instagram', 'daw' ), 'type' => 'url', 'columns' => 6 ),
			array( 'id' => 'daw_social_x', 'name' => __( 'X', 'daw' ), 'type' => 'url', 'columns' => 6 ),
			array( 'id' => 'daw_social_weverse', 'name' => __( 'Weverse or fan cafe', 'daw' ), 'type' => 'url', 'columns' => 6 ),
			array(
				'id'      => 'daw_group_embed',
				'name'    => __( 'Player embed url', 'daw' ),
				'type'    => 'url',
				'desc'    => __( 'One release, embedded once at the foot of the discography. A player per release would be twenty players on one page.', 'daw' ),
			),
		),
	);

	return $boxes;
}

/* ============================================================ members === */

add_filter( 'rwmb_meta_boxes', 'daw_register_member_fields', 14 );
function daw_register_member_fields( $boxes ) {
	$boxes[] = array(
		'id'         => 'daw_member_role',
		'title'      => __( 'Person — in the group', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'person' ),
		'fields'     => array(
			array(
				'id'      => 'daw_group_position',
				'name'    => __( 'Position in the group', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
				'desc'    => __( 'As the group itself states it: Main vocal, Rap and dance, Visual.', 'daw' ),
			),
			array(
				'id'      => 'daw_group_badge',
				'name'    => __( 'Badge', 'daw' ),
				'type'    => 'select',
				'columns' => 6,
				'options' => array(
					''       => __( '— none —', 'daw' ),
					'leader' => __( 'Leader', 'daw' ),
					'maknae' => __( 'Maknae', 'daw' ),
					'former' => __( 'Former member', 'daw' ),
				),
			),
		),
	);

	return $boxes;
}

/* ======================================================== discography === */

/**
 * The releases on a group record, newest first.
 *
 * Meta Box hands back a cloneable group as one array of rows. Each row is
 * cleaned here so the template can assume every key exists, and rows with no
 * title at all are dropped: an empty row is what an editor leaves behind when
 * they click add and change their mind.
 */
function daw_group_releases( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	$rows    = get_post_meta( $post_id, 'daw_rel', true );

	if ( ! is_array( $rows ) ) {
		return array();
	}

	$out = array();

	foreach ( $rows as $row ) {
		$title = trim( (string) ( $row['daw_rel_title'] ?? '' ) );

		if ( '' === $title ) {
			continue;
		}

		$date = trim( (string) ( $row['daw_rel_date'] ?? '' ) );
		$ts   = $date ? strtotime( $date ) : 0;

		$out[] = array(
			'title'   => $title,
			'native'  => trim( (string) ( $row['daw_rel_native'] ?? '' ) ),
			'type'    => (string) ( $row['daw_rel_type'] ?? '' ),
			'date'    => $date,
			'ts'      => $ts ? (int) $ts : 0,
			'year'    => $ts ? (int) gmdate( 'Y', $ts ) : 0,
			'tracks'  => (int) ( $row['daw_rel_tracks'] ?? 0 ),
			'label'   => trim( (string) ( $row['daw_rel_label'] ?? '' ) ),
			'track'   => trim( (string) ( $row['daw_rel_track'] ?? '' ) ),
			'linked'  => trim( (string) ( $row['daw_rel_linked'] ?? '' ) ),
			'cover'   => (int) ( $row['daw_rel_cover'] ?? 0 ),
			'cover_url' => trim( (string) ( $row['daw_rel_cover_url'] ?? '' ) ),
			'spotify' => trim( (string) ( $row['daw_rel_spotify'] ?? '' ) ),
			'apple'   => trim( (string) ( $row['daw_rel_apple'] ?? '' ) ),
		);
	}

	// Newest first, and anything undated at the end rather than pretending to
	// be the oldest thing the group ever put out.
	usort(
		$out,
		static function ( $a, $b ) {
			if ( ! $a['ts'] && ! $b['ts'] ) {
				return 0;
			}

			if ( ! $a['ts'] || ! $b['ts'] ) {
				return $a['ts'] ? -1 : 1;
			}

			return $b['ts'] <=> $a['ts'];
		}
	);

	return $out;
}

/** What each release type is called, and how the filter chips are ordered. */
function daw_release_types() {
	return array(
		'studio'  => __( 'Studio album', 'daw' ),
		'mini'    => __( 'Mini album', 'daw' ),
		'single'  => __( 'Single', 'daw' ),
		'digital' => __( 'Digital single', 'daw' ),
		'repack'  => __( 'Repackage', 'daw' ),
		'live'    => __( 'Live album', 'daw' ),
		'ost'     => __( 'OST', 'daw' ),
	);
}


/* ====================================================== screen credits === */

/**
 * Every screen credit held by every member, newest first, with the member.
 *
 * WHY THE MEMBER TRAVELS WITH THE CREDIT
 * --------------------------------------
 * A group has no filmography. What it has is people who do, and a row of
 * posters printed under the band's name says the band was in them: it was not
 * BTS in Hwarang, it was V. This returns who as well as what, and the page is
 * required to print it, which is the only reason the section is allowed back.
 */
function daw_group_screen_credits( $group_id = 0, $limit = 8 ) {
	$group_id = $group_id ? (int) $group_id : (int) get_the_ID();
	$members  = daw_group_members( $group_id );

	if ( ! $members || ! function_exists( 'daw_get_filmography' ) ) {
		return array();
	}

	$seen = array();

	foreach ( $members as $member_id ) {
		$film = daw_get_filmography( $member_id );

		foreach ( array( 'lead', 'supporting', 'guest', 'other' ) as $band ) {
			foreach ( (array) ( $film[ $band ] ?? array() ) as $credit ) {
				$title_id = (int) ( $credit['id'] ?? 0 );

				if ( ! $title_id || isset( $seen[ $title_id ] ) ) {
					continue;
				}

				$seen[ $title_id ] = array(
					'id'     => $title_id,
					'member' => $member_id,
					'band'   => $band,
					'ts'     => (int) get_post_meta( $title_id, 'daw_aired_start', true ),
				);
			}
		}
	}

	uasort(
		$seen,
		static function ( $a, $b ) {
			return $b['ts'] <=> $a['ts'];
		}
	);

	return array_slice( array_values( $seen ), 0, $limit );
}

/* ================================================================== faq === */

/**
 * The questions a group record answers, and the news that names it.
 *
 * A profile has carried both of these for a while and a group has not, which
 * is the whole reason the two pages read as different kinds of page. The
 * questions people type about a band are not the ones they type about an actor
 * ("how many members", "what is the fandom called", "are they still together"),
 * and they are the part of the page a search engine quotes back, so they are
 * written rather than derived.
 */
function daw_get_group_faq( $post_id ) {
	return daw_zip_rows( $post_id, array(
		'q' => 'daw_gfaq_q',
		'a' => 'daw_gfaq_a',
	) );
}

add_filter( 'rwmb_meta_boxes', 'daw_register_group_faq_box', 14 );
function daw_register_group_faq_box( $boxes ) {
	$boxes[] = array(
		'id'         => 'daw_group_faq',
		'title'      => __( 'Group: frequently asked questions', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'group' ),
		'fields'     => array(
			array(
				'id'         => 'daw_gfaq_q',
				'name'       => __( 'Question', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
			),
			array(
				'id'         => 'daw_gfaq_a',
				'name'       => __( 'Answer', 'daw' ),
				'type'       => 'textarea',
				'rows'       => 3,
				'clone'      => true,
				'sort_clone' => true,
				'desc'       => __( 'Row order must match the questions. These are the sentences a search engine quotes back, so each answer stands on its own and names the group rather than saying "they".', 'daw' ),
			),
		),
	);

	return $boxes;
}

/* ============================================================== similar === */

/**
 * Other groups a reader of this one would recognise.
 *
 * Same country first, then the same kind of group, newest debut first. It is
 * deliberately not "similar sounding": the archive holds no opinion about how
 * anybody sounds, and inventing one to fill a row at the foot of the page is
 * exactly the kind of filler this site does not print. What it can say
 * truthfully is "here are the other groups we hold from the same scene".
 */
function daw_group_similar( $post_id, $limit = 6 ) {
	$post_id = (int) $post_id;
	$country = wp_get_post_terms( $post_id, 'dw_country', array( 'fields' => 'slugs' ) );
	$country = is_wp_error( $country ) ? array() : $country;

	$args = array(
		'post_type'      => 'post',
		'posts_per_page' => (int) $limit * 3,
		'post__not_in'   => array( $post_id ),
		'no_found_rows'  => true,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'tax_query'      => array(
			array(
				'taxonomy' => 'dw_type',
				'field'    => 'slug',
				'terms'    => 'group',
			),
		),
	);

	if ( $country ) {
		$args['tax_query'][] = array(
			'taxonomy' => 'dw_country',
			'field'    => 'slug',
			'terms'    => $country,
		);
	}

	$ids = get_posts( array_merge( $args, array( 'fields' => 'ids' ) ) );

	// Not enough from the same country to make a row worth printing: widen it
	// to every group rather than show one card on its own.
	if ( count( $ids ) < 2 && $country ) {
		unset( $args['tax_query'][1] );
		$args['tax_query'] = array_values( $args['tax_query'] );
		$ids = get_posts( array_merge( $args, array( 'fields' => 'ids' ) ) );
	}

	$kind = (string) get_post_meta( $post_id, 'daw_group_kind', true );

	// Same kind of group first, everything else after, order otherwise kept.
	usort(
		$ids,
		static function ( $a, $b ) use ( $kind ) {
			$sa = (string) get_post_meta( $a, 'daw_group_kind', true ) === $kind ? 0 : 1;
			$sb = (string) get_post_meta( $b, 'daw_group_kind', true ) === $kind ? 0 : 1;

			return $sa <=> $sb;
		}
	);

	return array_slice( $ids, 0, (int) $limit );
}
