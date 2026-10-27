<?php
/**
 * Discover Asian World — Meta Box field schema
 *
 * Field names were not invented. They were extracted from the live site's
 * Elementor accordions in the September 2026 audit:
 *
 *   Title  → Details · Best Scenes · Filming Locations · Visual Design and
 *            Costumes · Themes and Messages · Interesting Facts · Awards ·
 *            Real-Life Loves on Set   (+ Trailer, Music/OST, Gallery, Cast)
 *   Person → Info · Biography · Hobbies · Interests · Facts · Secrets ·
 *            Funny Things · Fears · News
 *            Info decomposes into: Native Name, Nationality, Gender, Born,
 *            Birth Place, Zodiac Sign, Height, Spouse, Children
 *
 * FREE-TIER COMPROMISE — READ THIS BEFORE EDITING THE CAST FIELDS
 * --------------------------------------------------------------
 * Meta Box's free core gives us cloneable (repeatable) fields but NOT groups,
 * so we cannot store "person + character + billing" as one repeating row.
 * Instead each cast tier is TWO parallel cloneable fields that stay index
 * aligned: daw_cast_main_person[i] pairs with daw_cast_main_role[i].
 *
 * That compromise is confined to storage. Everything that reads cast goes
 * through daw_get_cast(), which zips the two arrays back into proper rows.
 * If we ever buy MB Group or ACF PRO, only this file and that one helper
 * change — no template, no schema, no query touches the raw meta.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'rwmb_meta_boxes', 'daw_register_meta_boxes' );
function daw_register_meta_boxes( $boxes ) {

	$boxes[] = daw_box_title_details();
	$boxes[] = daw_box_title_deepdive();
	$boxes[] = daw_box_title_media();
	$boxes[] = daw_box_title_cast();
	$boxes[] = daw_box_title_watch();

	$boxes[] = daw_box_title_scenes();
	$boxes[] = daw_box_title_related();
	$boxes[] = daw_box_title_episodes();
	$boxes[] = daw_box_title_ost();
	$boxes[] = daw_box_title_awards();
	$boxes[] = daw_box_title_faq();

	$boxes[] = daw_box_news_story();
	$boxes[] = daw_box_news_mentions();

	$boxes[] = daw_box_person_info();
	$boxes[] = daw_box_person_deepdive();
	$boxes[] = daw_box_person_social();

	return $boxes;
}

/* ============================================================== TITLE ===== */

/**
 * The Details accordion panel, decomposed into discrete fields.
 * Previously one blob of <strong>Label:</strong> value<br> pairs.
 */
function daw_box_title_details() {
	return array(
		'id'         => 'daw_title_details',
		'title'      => __( 'Title — details', 'daw' ),
		'post_types' => array( 'post' ),
		'context'    => 'normal',
		'priority'   => 'high',
		'daw_types'  => array( 'drama', 'film', 'variety', 'anime' ),
		'fields'     => array(
			array(
				'id'         => 'daw_takeaways',
				'name'       => __( 'Key takeaways', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'desc'       => __( 'Two or three lines someone could read instead of the whole page and still know what this is. Shown in the box above the synopsis.', 'daw' ),
			),
			array(
				'id'      => 'daw_why_watched',
				'name'    => __( 'Why it still gets watched', 'daw' ),
				'type'    => 'wysiwyg',
				'raw'     => false,
				'options' => array( 'textarea_rows' => 5, 'media_buttons' => false ),
				'desc'    => __( 'The site\'s own view — what keeps people coming back to this title years later. This is the part no database can copy from us.', 'daw' ),
			),
			array(
				'id'      => 'daw_synopsis',
				'name'    => __( 'Synopsis', 'daw' ),
				'type'    => 'wysiwyg',
				'raw'     => false,
				'options' => array( 'textarea_rows' => 6, 'media_buttons' => false ),
				'desc'    => __( 'What the story is about, in the site\'s own words. Seeded from the hand-written excerpt where one exists.', 'daw' ),
			),
			// EXTRA SECTIONS FOR QUESTIONS PEOPLE ACTUALLY SEARCH
			// --------------------------------------------------
			// When a search pattern is big enough to deserve its own heading
			// (madde 63: 1,000 a month or more, and too big for one FAQ answer),
			// it gets an H2 of its own under the synopsis, worded with the
			// pattern itself: "Hello Monster ending explained", "Is Pandora
			// based on a true story". Empty on most records, and that is fine.
			array(
				'id'      => 'daw_title_section_title',
				'name'    => __( 'Extra section: heading', 'daw' ),
				'type'    => 'text',
				'clone'   => true,
				'columns' => 4,
				'desc'    => __( 'Only for a searched question (1,000+ a month). The heading uses the search pattern itself.', 'daw' ),
			),
			array(
				'id'      => 'daw_title_section_body',
				'name'    => __( 'Extra section: text', 'daw' ),
				'type'    => 'wysiwyg',
				'raw'     => false,
				'clone'   => true,
				'columns' => 8,
				'options' => array( 'textarea_rows' => 6, 'media_buttons' => false ),
				'desc'    => __( 'Sourced, at least 120 words, repeats nothing said elsewhere on the page. Row order matches the headings.', 'daw' ),
			),
			array(
				'id'   => 'daw_native_title',
				'name' => __( 'Native title', 'daw' ),
				'type' => 'text',
				'desc' => __( 'e.g. 피노키오 — rendered with lang attribute for correct font and screen readers.', 'daw' ),
			),
			array(
				'id'    => 'daw_also_known_as',
				'name'  => __( 'Also known as', 'daw' ),
				'type'  => 'text',
				'clone' => true,
				'desc'  => __( 'One alternative title per row.', 'daw' ),
			),
			array(
				'id'    => 'daw_screenwriter',
				'name'  => __( 'Screenwriter', 'daw' ),
				'type'  => 'text',
				'clone' => true,
			),
			array(
				'id'    => 'daw_director',
				'name'  => __( 'Director', 'daw' ),
				'type'  => 'text',
				'clone' => true,
			),
			// THE FOUR ROWS THE DESIGN HAD AND THE RECORD DID NOT
			// ---------------------------------------------------
			// The Details card was drawn with tags, source material, production
			// company and language in it, and none of the four existed as a
			// field, so the card quietly rendered four rows short of the design
			// on every one of 590 titles. They are here now. They stay empty
			// until somebody checks them, like every other field.
			array(
				'id'    => 'daw_tags',
				'name'  => __( 'Tags', 'daw' ),
				'type'  => 'text',
				'clone' => true,
				'desc'  => __( 'What the story is actually about, one per row: Time travel. Royal court politics. Love triangle. Genres are a taxonomy; these are not, because they describe this title rather than sorting it.', 'daw' ),
			),
			array(
				'id'   => 'daw_adaptation',
				'name' => __( 'Adapted from', 'daw' ),
				'type' => 'text',
				'desc' => __( 'The source and its title, as one line: Novel, Bu Bu Jing Xin. Webtoon, Cheese in the Trap. Empty for an original screenplay.', 'daw' ),
			),
			array(
				'id'    => 'daw_original_author',
				'name'  => __( 'Original author', 'daw' ),
				'type'  => 'text',
				'clone' => true,
				'desc'  => __( 'Only for an adaptation: who wrote the webtoon, novel or manga, one per row. Feeds schema isBasedOn.', 'daw' ),
			),
			array(
				'id'    => 'daw_studio',
				'name'  => __( 'Animation studio', 'daw' ),
				'type'  => 'text',
				'clone' => true,
				'desc'  => __( 'Anime only: the studio that animated it (MAPPA, Studio Pierrot).', 'daw' ),
			),
			array(
				'id'    => 'daw_producer',
				'name'  => __( 'Producer (PD)', 'daw' ),
				'type'  => 'text',
				'clone' => true,
				'desc'  => __( 'Variety only: the PD people search for (Na Young-seok, Kim Tae-ho).', 'daw' ),
			),
			array(
				'id'    => 'daw_production',
				'name'  => __( 'Production company', 'daw' ),
				'type'  => 'text',
				'clone' => true,
				'desc'  => __( 'The studio that made it, not the channel that aired it. One per row when there were several.', 'daw' ),
			),
			array(
				'id'      => 'daw_language',
				'name'    => __( 'Original language', 'daw' ),
				'type'    => 'text',
				'desc'    => __( 'The language it was shot in. It is not always the country: a Korean production filmed in Mandarin is a Mandarin title.', 'daw' ),
			),
			array(
				'id'      => 'daw_episodes',
				'name'    => __( 'Episodes', 'daw' ),
				'type'    => 'number',
				'min'     => 1,
				'columns' => 3,
			),
			array(
				'id'      => 'daw_seasons',
				'name'    => __( 'Seasons', 'daw' ),
				'type'    => 'number',
				'min'     => 1,
				'columns' => 3,
				'desc'    => __( 'Seasons this record covers, specials not counted. Filled by source verification when TMDB and Wikidata agree.', 'daw' ),
			),
			array(
				'id'      => 'daw_duration_min',
				'name'    => __( 'Duration (minutes)', 'daw' ),
				'type'    => 'number',
				'min'     => 1,
				'columns' => 3,
			),
			array(
				'id'      => 'daw_aired_start',
				'name'    => __( 'First aired', 'daw' ),
				'type'    => 'date',
				'timestamp' => true,
				'columns' => 3,
			),
			array(
				'id'      => 'daw_aired_end',
				'name'    => __( 'Last aired', 'daw' ),
				'type'    => 'date',
				'timestamp' => true,
				'columns' => 3,
				'desc'    => __( 'Leave empty for films and ongoing runs.', 'daw' ),
			),
			array(
				'id'      => 'daw_airing_days',
				'name'    => __( 'Airing days', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
				'desc'    => __( 'e.g. Wednesdays & Thursdays', 'daw' ),
			),
			array(
				'id'      => 'daw_content_rating',
				'name'    => __( 'Content rating', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
				'desc'    => __( 'e.g. 15+', 'daw' ),
			),
			array(
				'id'      => 'daw_status',
				'name'    => __( 'Status', 'daw' ),
				'type'    => 'select',
				'columns' => 6,
				'options' => array(
					'completed' => __( 'Completed', 'daw' ),
					'ongoing'   => __( 'Ongoing', 'daw' ),
					'upcoming'  => __( 'Upcoming', 'daw' ),
				),
				'std'     => 'completed',
			),
			array(
				'id'      => 'daw_tmdb_id',
				'name'    => __( 'TMDB id', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
				'desc'    => __( 'Drives the poster. The image adapter resolves it — no URL is stored.', 'daw' ),
			),
			array(
				'id'      => 'daw_tmdb_alt',
				'name'    => __( 'Other TMDB ids', 'daw' ),
				'type'    => 'text',
				'clone'   => true,
				'columns' => 6,
				'desc'    => __( 'tv:83893 or movie:1455382. TMDB lists this production under this id too. Used only to match it to a person\'s TMDB credit: poster, cast, watch places and schema still come from the TMDB id above.', 'daw' ),
			),
			// THE HAND-OFF TO THE VERTICAL
			// ----------------------------
			// This archive holds every title, BL included, because a Thai
			// drama page without KinnPorsche misrepresents the industry it
			// is describing. What it does not do is answer BL questions:
			// the pairing, the ship history, the genre rankings. Those live
			// on the vertical site, and this field is where a record admits
			// it. One url, filled only when the other page actually exists,
			// so the block never renders a promise the link cannot keep.
			array(
				'id'      => 'daw_blgl_url',
				'name'    => __( 'BL/GL page (BLGLFlix)', 'daw' ),
				'type'    => 'url',
				'columns' => 6,
				'desc'    => __( 'Only for a BL or GL title that already has a live page on the vertical site. Paste the full url; leave empty and no hand-off block renders. This page keeps the record, that page keeps the pairing.', 'daw' ),
			),
		),
	);
}

/**
 * The seven narrative accordion panels. These are the reason fans come.
 * wysiwyg because the existing content is formatted HTML with lists and bold.
 */
function daw_box_title_deepdive() {
	$panels = array(
		'daw_best_scenes'      => __( 'Best scenes', 'daw' ),
		'daw_filming_location' => __( 'Filming locations', 'daw' ),
		'daw_visual_design'    => __( 'Visual design and costumes', 'daw' ),
		'daw_themes_messages'  => __( 'Themes and messages', 'daw' ),
		'daw_interesting_facts'=> __( 'Interesting facts', 'daw' ),
		'daw_remakes'          => __( 'Remakes and other versions', 'daw' ),
		'daw_awards'           => __( 'Awards', 'daw' ),
		'daw_real_life_loves'  => __( 'Real-life loves on set', 'daw' ),
	);

	$fields = array();
	foreach ( $panels as $id => $label ) {
		$fields[] = array(
			'id'      => $id,
			'name'    => $label,
			'type'    => 'wysiwyg',
			'raw'     => false,
			'options' => array( 'textarea_rows' => 8, 'media_buttons' => false ),
		);
	}

	return array(
		'id'         => 'daw_title_deepdive',
		'title'      => __( 'Title — deep dive', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'drama', 'film', 'variety', 'anime' ),
		'fields'     => $fields,
	);
}

function daw_box_title_media() {
	return array(
		'id'         => 'daw_title_media',
		'title'      => __( 'Title — media', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'drama', 'film', 'variety', 'anime' ),
		'fields'     => array(
			array(
				'id'   => 'daw_trailer_youtube',
				'name' => __( 'Trailer (YouTube)', 'daw' ),
				'type' => 'url',
				'desc' => __( 'Rendered as a click-to-load facade — nothing from YouTube loads until the visitor asks. Feeds VideoObject schema and the video sitemap.', 'daw' ),
			),
			array(
				'id'   => 'daw_trailer_date',
				'name' => __( 'Trailer upload date', 'daw' ),
				'type' => 'date',
				'timestamp' => false,
				'desc' => __( 'The date YouTube shows under the trailer. Feeds VideoObject uploadDate; empty means no date is sent rather than a wrong one.', 'daw' ),
			),
			array(
				'id'   => 'daw_link_imdb',
				'name' => __( 'IMDb', 'daw' ),
				'type' => 'url',
				'desc' => __( 'The title page on imdb.com. Schema sameAs only; not shown as a link.', 'daw' ),
			),
			array(
				'id'   => 'daw_link_wikipedia',
				'name' => __( 'Wikipedia', 'daw' ),
				'type' => 'url',
				'desc' => __( 'English Wikipedia article, if there is one. Schema sameAs only.', 'daw' ),
			),
			array(
				'id'    => 'daw_spotify_uri',
				'name'  => __( 'Soundtrack (Spotify)', 'daw' ),
				'type'  => 'url',
				'clone' => true,
				'desc'  => __( 'Album or playlist link. Loaded only after consent.', 'daw' ),
			),
			array(
				'id'    => 'daw_gallery',
				'name'  => __( 'Gallery — licensed material only', 'daw' ),
				'type'  => 'image_advanced',
				'desc'  => __( 'Leave empty unless we hold written permission. Posters come from TMDB and do not belong here.', 'daw' ),
			),
		),
	);
}

/**
 * Cast, split three ways as agreed. See the free-tier note at the top —
 * person and role are parallel cloneable arrays, zipped by daw_get_cast().
 */
function daw_box_title_cast() {
	$fields = array();

	// The untiered bucket. The Elementor cast widget was a live tag query with
	// no billing order and no character names, so that is all the extractor can
	// honestly produce. Editors promote people out of here into the three tiers
	// below as the records get curated; nothing is invented on their behalf.
	$fields[] = array(
		'id'          => 'daw_cast_person',
		'name'        => __( 'Cast — not yet tiered', 'daw' ),
		'type'        => 'post',
		'post_type'   => 'post',
		'field_type'  => 'select_advanced',
		'multiple'    => true,
		'placeholder' => __( 'Search a person record…', 'daw' ),
		'desc'        => __( 'Recovered from the tags that used to drive the cast list. Move people into Main, Supporting or Guest below as you confirm their billing.', 'daw' ),
		'query_args'  => array(
			'posts_per_page' => 20,
			'tax_query'      => array(
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => 'person',
				),
			),
		),
	);

	$tiers = array(
		'main'       => __( 'Main cast', 'daw' ),
		'supporting' => __( 'Supporting cast', 'daw' ),
		'guest'      => __( 'Guest cast', 'daw' ),
	);

	foreach ( $tiers as $tier => $label ) {
		$fields[] = array(
			'id'          => "daw_cast_{$tier}_person",
			'name'        => $label,
			'type'        => 'post',
			'post_type'   => 'post',
			'field_type'  => 'select_advanced',
			'clone'       => true,
			'sort_clone'  => true,
			'columns'     => 6,
			'placeholder' => __( 'Search a person record…', 'daw' ),
			'query_args'  => array(
				'posts_per_page' => 20,
				'tax_query'      => array(
					array(
						'taxonomy' => 'dw_type',
						'field'    => 'slug',
						'terms'    => 'person',
					),
				),
			),
		);
		$fields[] = array(
			'id'      => "daw_cast_{$tier}_role",
			'name'    => sprintf( __( '%s — character names', 'daw' ), $label ),
			'type'    => 'text',
			'clone'   => true,
			'sort_clone' => true,
			'columns' => 4,
			'desc'    => __( 'Row order must match the column on the left.', 'daw' ),
		);
		// HOW MUCH OF THE SHOW SOMEBODY IS ACTUALLY IN
		// --------------------------------------------
		// "Guest" says a performance is not a regular one and stops there. It
		// cannot say that an actor turned up for five episodes of a ten
		// episode run, which is the thing a reader wants to know and the
		// thing a filmography card should carry. Writing it into the
		// character name would hide it inside a string nothing else can read.
		//
		// Left empty, nothing is printed, which is the right answer for a
		// series regular: being in all of it is the default.
		$fields[] = array(
			'id'         => "daw_cast_{$tier}_eps",
			'name'       => sprintf( __( '%s — episodes', 'daw' ), $label ),
			'type'       => 'text',
			'clone'      => true,
			'sort_clone' => true,
			'columns'    => 2,
			'placeholder' => __( 'e.g. 5 episodes, or 3 to 7', 'daw' ),
		);
	}

	return array(
		'id'         => 'daw_title_cast',
		'title'      => __( 'Title — cast', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'drama', 'film', 'variety', 'anime' ),
		'fields'     => $fields,
	);
}

/**
 * Official streaming links only. No unofficial sources, ever — that is both a
 * legal position and an AdSense requirement.
 */
function daw_box_title_watch() {
	return array(
		'id'         => 'daw_title_watch',
		'title'      => __( 'Title — where to watch', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'drama', 'film', 'variety', 'anime' ),
		'fields'     => array(
			array(
				'id'      => 'daw_watch_platform',
				'name'    => __( 'Platform', 'daw' ),
				'type'    => 'text',
				'clone'   => true,
				'sort_clone' => true,
				'columns' => 4,
				'desc'    => __( 'Netflix, Viki, Disney+ …', 'daw' ),
			),
			array(
				'id'      => 'daw_watch_url',
				'name'    => __( 'Official link', 'daw' ),
				'type'    => 'url',
				'clone'   => true,
				'sort_clone' => true,
				'columns' => 5,
				'desc'    => __( 'Row order must match Platform. Official pages only — feeds WatchAction schema.', 'daw' ),
			),
			array(
				'id'      => 'daw_watch_access',
				'name'    => __( 'How you watch', 'daw' ),
				'type'    => 'text',
				'clone'   => true,
				'sort_clone' => true,
				'columns' => 3,
				'desc'    => __( 'Subscription · Free with ads · Rent or buy. Readers ask this before they ask anything else.', 'daw' ),
			),
		),
	);
}

/**
 * Iconic scenes, each pinned to the episode it happens in.
 *
 * The extracted Best Scenes panel is prose: five moments described in a
 * paragraph with no way to tell a reader where to find them. "Traffic Cone Kiss
 * — episode 4" is a different kind of answer, and it is the one fans are
 * actually after. The template prefers these rows when they exist and falls
 * back to the prose panel when they do not, so nothing has to be migrated
 * before the field is useful.
 */
function daw_box_title_scenes() {
	$cols = array(
		'daw_scene_title' => array( __( 'Scene', 'daw' ), 'text', 5 ),
		'daw_scene_ep'    => array( __( 'Episode', 'daw' ), 'text', 2 ),
		'daw_scene_note'  => array( __( 'What happens', 'daw' ), 'textarea', 5 ),
	);

	$fields = array();
	foreach ( $cols as $id => $spec ) {
		$fields[] = array(
			'id'         => $id,
			'name'       => $spec[0],
			'type'       => $spec[1],
			'clone'      => true,
			'sort_clone' => true,
			'columns'    => $spec[2],
			'rows'       => 2,
		);
	}
	$fields[1]['desc'] = __( 'Just the number, or a range like 4–5 when the moment spans two.', 'daw' );

	return array(
		'id'         => 'daw_title_scenes',
		'title'      => __( 'Title — iconic scenes', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'drama', 'film', 'variety', 'anime' ),
		'fields'     => $fields,
	);
}

/**
 * Episode list. Four parallel cloneable columns, zipped by daw_get_episodes().
 * Not elegant — Meta Box's free tier has no repeating group — but the ugliness
 * is confined to storage, and the template never sees it.
 */
/**
 * Titles that belong to the same story.
 *
 * WHY A FIELD AND NOT THE "If you liked this" BLOCK
 * -------------------------------------------------
 * The block at the foot of a record is a genre-and-tone guess: it finds other
 * records that share terms. That is a recommendation, and it is a different
 * claim from "this is the sequel". A reader who lands on season one and has to
 * work out from a grid of thumbnails whether season two exists has been failed
 * by the page, and Google has no way at all to tell that two of these records
 * are one story.
 *
 * So the relation is typed by hand and says what it is: Season 2, Sequel,
 * Original film, Remake, Anime adaptation, Musical, Spin-off. Two parallel
 * cloneable arrays, zipped by daw_get_related_titles(), the same compromise
 * the cast tiers make and for the same reason (no Meta Box groups on free).
 *
 * The relation is entered on BOTH records. It is not derived from one side,
 * because "A is the sequel of B" and "B is the prequel of A" are different
 * sentences and the reader on each page wants the one that fits their page.
 */
function daw_box_title_related() {
	$fields = array();

	$fields[] = array(
		'id'          => 'daw_rel_post',
		'name'        => __( 'Related title', 'daw' ),
		'type'        => 'post',
		'post_type'   => 'post',
		'field_type'  => 'select_advanced',
		'clone'       => true,
		'sort_clone'  => true,
		'columns'     => 6,
		'placeholder' => __( 'Search a title record…', 'daw' ),
		'query_args'  => array(
			'posts_per_page' => 20,
			'tax_query'      => array(
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => array( 'drama', 'film', 'variety', 'anime' ),
				),
			),
		),
	);

	$fields[] = array(
		'id'          => 'daw_rel_kind',
		'name'        => __( 'How it is related', 'daw' ),
		'type'        => 'text',
		'clone'       => true,
		'sort_clone'  => true,
		'columns'     => 6,
		'placeholder' => __( 'Season 2 / Sequel / Original film / Remake / Anime adaptation', 'daw' ),
		'desc'        => __( 'Written from THIS record\'s point of view: on season one you write "Season 2", on season two you write "Season 1".', 'daw' ),
	);

	return array(
		'id'         => 'daw_title_related',
		'title'      => __( 'Related titles — same story', 'daw' ),
		'post_types' => array( 'post' ),
		'context'    => 'normal',
		'priority'   => 'default',
		'fields'     => $fields,
	);
}

function daw_box_title_episodes() {
	$cols = array(
		'daw_ep_no'       => array( __( 'Episode', 'daw' ), 'number', 2 ),
		'daw_ep_title'    => array( __( 'Episode title', 'daw' ), 'text', 3 ),
		'daw_ep_date'     => array( __( 'Air date', 'daw' ), 'date', 2 ),
		'daw_ep_rating'   => array( __( 'Nationwide rating %', 'daw' ), 'text', 2 ),
		// WHAT THE EPISODE IS ABOUT, IN OUR OWN WORDS
		// -------------------------------------------
		// An episode title is a hook and nothing else. "Can We Feel That Spark
		// Again?" tells a reader who missed it nothing about what happened.
		// A line of our own writing does, and it is the kind of thing people
		// search for episode by episode.
		//
		// Written, not copied. A distributor's blurb is the raw material for
		// a sentence in this site's voice, never the sentence itself.
		'daw_ep_synopsis' => array( __( 'What happens', 'daw' ), 'textarea', 3 ),
	);

	$fields = array();
	foreach ( $cols as $id => $spec ) {
		$fields[] = array(
			'id'         => $id,
			'name'       => $spec[0],
			'type'       => $spec[1],
			'clone'      => true,
			'sort_clone' => true,
			'columns'    => $spec[2],
		);
	}
	$fields[0]['desc'] = __( 'All four columns move together — row three of each is the same episode.', 'daw' );

	return array(
		'id'         => 'daw_title_episodes',
		'title'      => __( 'Title — episodes', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'drama', 'variety', 'anime' ),
		'fields'     => $fields,
	);
}

/**
 * Soundtrack. A Spotify album link for the embed plus the track list, because
 * "OST" is a search term in its own right — people look for the song before
 * they look for the drama.
 */
function daw_box_title_ost() {
	return array(
		'id'         => 'daw_title_ost',
		'title'      => __( 'Title — soundtrack', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'drama', 'film', 'variety', 'anime' ),
		'fields'     => array(
			array(
				'id'   => 'daw_ost_album',
				'name' => __( 'Spotify album or playlist', 'daw' ),
				'type' => 'url',
				'desc' => __( 'Loaded only after the visitor accepts cookies, and never before.', 'daw' ),
			),
			array(
				'id'         => 'daw_ost_track',
				'name'       => __( 'Track', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 6,
			),
			array(
				'id'         => 'daw_ost_artist',
				'name'       => __( 'Artist', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 6,
				'desc'       => __( 'Row order must match Track.', 'daw' ),
			),
		),
	);
}

/**
 * Awards as a table rather than a paragraph. The extracted Awards panel keeps
 * its prose; this is the structured version that can be sorted, counted and
 * eventually marked up.
 */
function daw_box_title_awards() {
	// Recipient is its own column, not a name glued onto the end of the
	// category. The first version wrote "Best Actor — Lee Jong Suk" into one
	// cell, which reads fine and sorts, counts and marks up as nothing: an
	// award belongs to a person, and only a separate field can say so.
	$cols = array(
		'daw_aw_year'     => array( __( 'Year', 'daw' ), 2 ),
		'daw_aw_body'     => array( __( 'Ceremony', 'daw' ), 3 ),
		'daw_aw_category' => array( __( 'Category', 'daw' ), 3 ),
		'daw_aw_who'      => array( __( 'Recipient', 'daw' ), 2 ),
		'daw_aw_result'   => array( __( 'Result', 'daw' ), 2 ),
	);

	$fields = array();
	foreach ( $cols as $id => $spec ) {
		$fields[] = array(
			'id'         => $id,
			'name'       => $spec[0],
			'type'       => 'text',
			'clone'      => true,
			'sort_clone' => true,
			'columns'    => $spec[1],
		);
	}

	return array(
		'id'         => 'daw_title_awards',
		'title'      => __( 'Title — awards table', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'drama', 'film', 'variety', 'anime' ),
		'fields'     => $fields,
	);
}

/**
 * Questions people actually ask. Worth its own field because it is the part of
 * the page most likely to be quoted back by a search engine as the answer.
 */
function daw_box_title_faq() {
	return array(
		'id'         => 'daw_title_faq',
		'title'      => __( 'Title — frequently asked questions', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'drama', 'film', 'variety', 'anime', 'person', 'group' ),
		'fields'     => array(
			array(
				'id'         => 'daw_faq_q',
				'name'       => __( 'Question', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'desc'       => __( 'Phrase it the way a reader would type it, not the way an editor would title it.', 'daw' ),
			),
			array(
				'id'         => 'daw_faq_a',
				'name'       => __( 'Answer', 'daw' ),
				'type'       => 'textarea',
				'clone'      => true,
				'sort_clone' => true,
				'rows'       => 3,
				'desc'       => __( 'Row order must match Question. Answer in one or two sentences.', 'daw' ),
			),
		),
	);
}

/**
 * What a news story is about.
 *
 * Tagged once on the news record, and the connection then works in both
 * directions: the story lists what it mentions, and every drama, film and
 * person it names grows a "Related news" block without anyone editing those
 * records. One entry, two places — which is the only way a newsroom of one
 * keeps a thousand records current.
 */
/**
 * A news record, built so that publishing it is safe.
 *
 * THE LEGAL SHAPE IS THE TEMPLATE, NOT A DISCLAIMER
 * -------------------------------------------------
 * A story about someone else's reporting is only ours if we wrote it. So the
 * record separates the two things that get confused: what an outlet reported,
 * which is attributed and linked, and what this page states, which is written
 * here in our own words and limited to what is confirmed.
 *
 * That is also why there is no field for the original article's text. There
 * never will be. The source box names the outlet and links to it, which is what
 * sends the reader there rather than replacing the trip.
 */
function daw_box_news_story() {
	return array(
		'id'         => 'daw_news_story',
		'title'      => __( 'News — the story', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'news' ),
		'fields'     => array(
			array(
				'id'      => 'daw_news_kicker',
				'name'    => __( 'Kicker', 'daw' ),
				'type'    => 'text',
				'columns' => 4,
				'desc'    => __( 'The label above the headline: Breaking, Casting, Awards, Ratings. One or two words.', 'daw' ),
			),
			array(
				'id'      => 'daw_news_standfirst',
				'name'    => __( 'Standfirst', 'daw' ),
				'type'    => 'textarea',
				'rows'    => 2,
				'columns' => 8,
				'desc'    => __( 'One sentence under the headline that says what happened and who said so. This is also what a search result shows, so it stands on its own.', 'daw' ),
			),
			array(
				'id'         => 'daw_news_source_name',
				'name'       => __( 'Reported by', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 4,
				'desc'       => __( 'The outlet that broke it: OSEN, Xports News, Yonhap, the agency itself.', 'daw' ),
			),
			array(
				'id'         => 'daw_news_source_url',
				'name'       => __( 'Link to the original report', 'daw' ),
				'type'       => 'url',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 8,
				'desc'       => __( 'Row order matches the outlets. A story with no link to its source does not get published.', 'daw' ),
			),
			array(
				'id'      => 'daw_news_confirmed',
				'name'    => __( 'What is confirmed', 'daw' ),
				'type'    => 'wysiwyg',
				'raw'     => false,
				'options' => array( 'textarea_rows' => 4, 'media_buttons' => false ),
				'desc'    => __( 'The short list of what is actually established, and what is not. This is the part that keeps the page honest when the rumour turns out to be wrong.', 'daw' ),
			),
			array(
				'id'         => 'daw_news_tl_date',
				'name'       => __( 'Timeline: date', 'daw' ),
				'type'       => 'date',
				'timestamp'  => true,
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 4,
			),
			array(
				'id'         => 'daw_news_tl_event',
				'name'       => __( 'Timeline: what happened', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 8,
				'desc'       => __( 'Newest first. A story that develops keeps its url and grows a row here, rather than becoming a second page competing with the first.', 'daw' ),
			),
		),
	);
}

/** The dated steps of a developing story. */
function daw_get_news_timeline( $post_id ) {
	return daw_zip_rows( $post_id, array(
		'date'  => 'daw_news_tl_date',
		'event' => 'daw_news_tl_event',
	) );
}

/** The outlets that reported it, paired with their links. */
function daw_get_news_sources( $post_id ) {
	$rows = daw_zip_rows( $post_id, array(
		'name' => 'daw_news_source_name',
		'url'  => 'daw_news_source_url',
	) );

	return array_values(
		array_filter(
			$rows,
			static function ( $row ) {
				return '' !== trim( (string) $row['name'] );
			}
		)
	);
}

function daw_box_news_mentions() {
	return array(
		'id'         => 'daw_news_mentions',
		'title'      => __( 'News — what this story is about', 'daw' ),
		'post_types' => array( 'post' ),
		'context'    => 'side',
		'daw_types'  => array( 'news' ),
		'fields'     => array(
			array(
				'id'          => 'daw_mentions',
				'name'        => __( 'Mentioned in this story', 'daw' ),
				'type'        => 'post',
				'post_type'   => 'post',
				'field_type'  => 'select_advanced',
				'multiple'    => true,
				'placeholder' => __( 'Search a drama, film or person…', 'daw' ),
				'desc'        => __( 'Each record listed here shows this story under Related news, automatically.', 'daw' ),
				'query_args'  => array(
					'posts_per_page' => 20,
					'tax_query'      => array(
						array(
							'taxonomy' => 'dw_type',
							'field'    => 'slug',
							'terms'    => array( 'drama', 'film', 'person', 'group' ),
						),
					),
				),
			),
		),
	);
}

/**
 * The news stories that name this record, newest first.
 *
 * Reads a flat index written on save rather than searching serialised meta, so
 * the lookup stays a single indexed LIKE however many stories accumulate.
 */
function daw_get_related_news( $post_id, $limit = 4 ) {
	return get_posts( array(
		'post_type'      => 'post',
		'posts_per_page' => (int) $limit,
		'no_found_rows'  => true,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'tax_query'      => array(
			array(
				'taxonomy' => 'dw_type',
				'field'    => 'slug',
				'terms'    => 'news',
			),
		),
		'meta_query'     => array(
			array(
				'key'     => '_daw_mentions_idx',
				'value'   => ',' . (int) $post_id . ',',
				'compare' => 'LIKE',
			),
		),
	) );
}

add_action( 'save_post_post', 'daw_reindex_mentions', 20, 1 );
function daw_reindex_mentions( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( 'news' !== daw_get_type( $post_id ) ) {
		return;
	}

	$ids = array_filter( array_map( 'intval', (array) get_post_meta( $post_id, 'daw_mentions', true ) ) );

	if ( $ids ) {
		update_post_meta( $post_id, '_daw_mentions_idx', ',' . implode( ',', $ids ) . ',' );
	} else {
		delete_post_meta( $post_id, '_daw_mentions_idx' );
	}
}

/* ============================================================= PERSON ===== */

function daw_box_person_info() {
	return array(
		'id'         => 'daw_person_info',
		'title'      => __( 'Person — info', 'daw' ),
		'post_types' => array( 'post' ),
		'context'    => 'normal',
		'priority'   => 'high',
		'daw_types'  => array( 'person' ),
		'fields'     => array(
			array(
				'id'      => 'daw_native_name',
				'name'    => __( 'Native name', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
			),
			array(
				'id'      => 'daw_nationality',
				'name'    => __( 'Nationality', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
			),
			array(
				'id'      => 'daw_gender',
				'name'    => __( 'Gender', 'daw' ),
				'type'    => 'select',
				'columns' => 4,
				'options' => array(
					'female' => __( 'Female', 'daw' ),
					'male'   => __( 'Male', 'daw' ),
					'other'  => __( 'Other', 'daw' ),
				),
				'desc'    => __( 'A field, not a category. This replaces the K-Actors / K-Actress split.', 'daw' ),
			),
			array(
				'id'      => 'daw_born',
				'name'    => __( 'Born', 'daw' ),
				'type'    => 'date',
				'timestamp' => true,
				'columns' => 4,
			),
			array(
				'id'      => 'daw_died',
				'name'    => __( 'Died', 'daw' ),
				'type'    => 'date',
				'timestamp' => true,
				'columns' => 4,
				'desc'    => __( 'Left empty for the living. Filling it is what stops a profile describing someone in the present tense years after they died.', 'daw' ),
			),
			array(
				'id'      => 'daw_occupation',
				'name'    => __( 'Occupation', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
				'std'     => 'Actor',
			),
			array(
				'id'      => 'daw_birth_place',
				'name'    => __( 'Birth place', 'daw' ),
				'type'    => 'text',
				'columns' => 4,
			),
			array(
				'id'      => 'daw_zodiac',
				'name'    => __( 'Zodiac sign', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
			),
			array(
				'id'      => 'daw_height_cm',
				'name'    => __( 'Height (cm)', 'daw' ),
				'type'    => 'number',
				'columns' => 3,
				'desc'    => __( 'Stored in cm; the template shows both units.', 'daw' ),
			),
			array(
				'id'      => 'daw_spouse',
				'name'    => __( 'Spouse', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
			),
			array(
				'id'      => 'daw_children',
				'name'    => __( 'Children', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
			),
			array(
				'id'      => 'daw_agency',
				'name'    => __( 'Agency', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
			),
			array(
				'id'      => 'daw_member_of',
				'name'    => __( 'Member of', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
				'desc'    => __( 'The group record this person belongs to: its name, its slug, or its id. The group page reads its member list back out of this field.', 'daw' ),
			),
			array(
				'id'      => 'daw_tmdb_id',
				'name'    => __( 'TMDB id', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
				'desc'    => __( 'Drives the portrait, rendered 2:3 like a poster — never a circle.', 'daw' ),
			),
		),
	);
}

function daw_box_person_deepdive() {
	// The order here is the order they appear on the page. Relationships sits
	// first because it is what people came for, and putting it first is also
	// what lets the editorial rule underneath it be seen rather than buried.
	$panels = array(
		'daw_relationships' => __( 'Relationships and dating', 'daw' ),
		'daw_biography'     => __( 'Biography', 'daw' ),
		// The military card below states the dates. This is the part a reader
		// asks about and a table cannot answer: which branch, why that
		// assignment, what it cost the career, what came after. The design had
		// it as a panel of its own and the record had only the four rows.
		'daw_military_panel' => __( 'Military service', 'daw' ),
		'daw_hobbies'       => __( 'Hobbies', 'daw' ),
		'daw_interests'     => __( 'Interests', 'daw' ),
		'daw_facts'         => __( 'Facts', 'daw' ),
		'daw_secrets'       => __( 'Lesser-known details', 'daw' ),
		'daw_funny_things'  => __( 'Funny moments', 'daw' ),
		'daw_fears'         => __( 'Fears and dislikes', 'daw' ),
		'daw_endorsements'  => __( 'Commercials and endorsements', 'daw' ),
		'daw_sports'        => __( 'Sports and fitness', 'daw' ),
		'daw_instruments_panel' => __( 'Musical instruments', 'daw' ),
		'daw_person_news'   => __( 'In the news', 'daw' ),
	);

	$fields = array();
	foreach ( $panels as $id => $label ) {
		$fields[] = array(
			'id'      => $id,
			'name'    => $label,
			'type'    => 'wysiwyg',
			'raw'     => false,
			'options' => array( 'textarea_rows' => 8, 'media_buttons' => false ),
		);
	}

	return array(
		'id'         => 'daw_person_deepdive',
		'title'      => __( 'Person — deep dive', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'person' ),
		'fields'     => $fields,
	);
}

function daw_box_person_social() {
	return array(
		'id'         => 'daw_person_social',
		'title'      => __( 'Person — official accounts', 'daw' ),
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'person' ),
		'fields'     => array(
			array(
				'id'      => 'daw_social_instagram',
				'name'    => __( 'Instagram', 'daw' ),
				'type'    => 'url',
				'columns' => 6,
			),
			array(
				'id'      => 'daw_social_x',
				'name'    => __( 'X', 'daw' ),
				'type'    => 'url',
				'columns' => 6,
			),
			array(
				'id'      => 'daw_social_youtube',
				'name'    => __( 'YouTube', 'daw' ),
				'type'    => 'url',
				'columns' => 6,
			),
			array(
				'id'      => 'daw_social_weibo',
				'name'    => __( 'Weibo', 'daw' ),
				'type'    => 'url',
				'columns' => 6,
			),
			array(
				'id'      => 'daw_social_facebook',
				'name'    => __( 'Facebook', 'daw' ),
				'type'    => 'url',
				'columns' => 6,
				'desc'    => __( 'Official accounts only. Every url here feeds the Person schema sameAs array.', 'daw' ),
			),
		),
	);
}

/* ====================================================== READ HELPERS ====== */

/**
 * One meta read, usable from anywhere in the plugin.
 *
 * It lives here rather than beside the first thing that needed it, because
 * three other files now depend on it and a helper that loads only when an
 * unrelated feature is switched on is a fatal error waiting for the day that
 * feature is switched off.
 */
function daw_field_raw( $post_id, $key ) {
	return function_exists( 'rwmb_meta' ) ? rwmb_meta( $key, array(), $post_id ) : get_post_meta( $post_id, $key, true );
}

/**
 * Airing status, worked out from the dates rather than typed in.
 *
 * A status field that someone has to remember to change is a status field that
 * is wrong within a month. The dates are already there and they cannot drift,
 * so we read them: no end date and a start in the past means it is still
 * running; a start in the future means it has not begun.
 *
 * The stored daw_status field still wins when an editor has set it explicitly —
 * a show can be cancelled mid-run, and no date can tell us that.
 *
 * @return array{slug: string, label: string}
 */
function daw_airing_status( $post_id ) {
	$manual = get_post_meta( $post_id, 'daw_status', true );
	$start  = (int) get_post_meta( $post_id, 'daw_aired_start', true );
	$end    = (int) get_post_meta( $post_id, 'daw_aired_end', true );
	$now    = time();

	if ( 'upcoming' === $manual || ( $start && $start > $now ) ) {
		return array( 'slug' => 'upcoming', 'label' => __( 'Upcoming', 'daw' ) );
	}

	// No finale date typed in yet, but the episode list already holds every
	// episode: the last one's date is the finale. Four Hands, Two Sonatas
	// ended on 4 October 2026 and still read On air the next day, because
	// only the episode rows knew (owner, 5 Oct 2026).
	if ( ! $end ) {
		$end = daw_derived_finale( $post_id );
	}

	// A finale is stored as UTC midnight of its day and airs that evening in
	// Korea, so the show is on air for the whole of that day.
	if ( $end && $end + DAY_IN_SECONDS > $now ) {
		return array( 'slug' => 'onair', 'label' => __( 'On air', 'daw' ) );
	}

	if ( ! $end && $start && $start <= $now && 'completed' !== $manual ) {
		// A MISSING END DATE IS NOT EVIDENCE THAT SOMETHING IS STILL RUNNING
		// ------------------------------------------------------------------
		// This used to call any drama with no recorded end date "On air", and
		// the archive is full of dramas with no recorded end date. A show that
		// finished two years ago was being announced as currently broadcasting,
		// in a badge, on its own page.
		//
		// A Korean drama season runs a couple of months; a daily can run a
		// year. So a start inside the last nine months with no end date is
		// plausibly still going and is called On air. Older than that, the end
		// date is simply missing, and the honest answer is to say nothing:
		// neither On air, which would be a false claim, nor Completed, which
		// would be a guess. The badge disappears and the fact line ends at the
		// last thing we actually know.
		$type = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';

		if ( 'drama' === $type ) {
			return $start > $now - 9 * MONTH_IN_SECONDS
				? array( 'slug' => 'onair', 'label' => __( 'On air', 'daw' ) )
				: array( 'slug' => '', 'label' => '' );
		}
	}

	return array( 'slug' => 'completed', 'label' => __( 'Completed', 'daw' ) );
}

/**
 * The finale read from the episodes, as UTC midnight, or 0 when the list is
 * not complete. Complete means the record's own episode count is known and
 * that many dated episodes are listed, in the record or in TMDB's episode
 * list. A half-typed list never ends a show early.
 */
function daw_derived_finale( $post_id ) {
	$total = (int) get_post_meta( $post_id, 'daw_episodes', true );
	if ( $total < 1 ) {
		return 0;
	}
	$best = 0;
	$own  = array_filter( array_map( 'strval', (array) get_post_meta( $post_id, 'daw_ep_date', true ) ), static fn( $d ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) );
	if ( count( $own ) >= $total ) {
		$best = max( array_map( static fn( $d ) => (int) strtotime( $d . ' 00:00:00 UTC' ), $own ) );
	}
	$tm = get_post_meta( $post_id, '_daw_tmdb_eps', true );
	if ( ! $best && is_array( $tm ) ) {
		$dates = array();
		foreach ( $tm as $e ) {
			$d = (string) ( $e['air'] ?? '' );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
				$dates[] = (int) strtotime( $d . ' 00:00:00 UTC' );
			}
		}
		if ( count( $dates ) >= $total ) {
			$best = max( $dates );
		}
	}
	return $best;
}

/**
 * Once the derived finale has passed, it is written into daw_aired_end, so
 * the calendar's "just finished" list, the fact line and the schema all
 * carry it, and the cached pages that still said On air are refreshed.
 */
add_action( 'daw_fix_tick', 'daw_finale_from_episodes', 7 );
function daw_finale_from_episodes() {
	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 300,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'     => 'daw_aired_start',
					'value'   => time() - 18 * MONTH_IN_SECONDS,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => 'daw_aired_end',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => 'daw_aired_end',
						'value' => '',
					),
				),
			),
		)
	);
	$changed = 0;
	foreach ( $ids as $id ) {
		$fin = daw_derived_finale( (int) $id );
		if ( $fin && $fin + DAY_IN_SECONDS <= time() ) {
			update_post_meta( (int) $id, 'daw_aired_end', $fin );
			if ( 'ongoing' === get_post_meta( (int) $id, 'daw_status', true ) ) {
				update_post_meta( (int) $id, 'daw_status', 'completed' );
			}
			do_action( 'litespeed_purge_post', (int) $id );
			++$changed;
		}
	}
	if ( $changed && function_exists( 'daw_fix_log' ) ) {
		daw_fix_log( 'status', sprintf( '%d dizi son bölümü yayınlandığı için tamamlandı olarak işaretlendi', $changed ) );
	}

	// Status is worked out from dates, so it changes with the calendar day
	// even when nobody edits anything: a premiere turns Upcoming into On air,
	// a finale turns On air into Completed. Dates are stored as UTC midnight,
	// so the badge turns when the UTC day turns; at the first tick of each
	// UTC day the pages whose status turned, and the airing calendar, are
	// dropped from the page cache so no cached copy keeps the old badge.
	$day = gmdate( 'Y-m-d' );
	if ( $changed || get_option( 'daw_status_purge_day' ) !== $day ) {
		update_option( 'daw_status_purge_day', $day, false );
		$turned = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => 'daw_aired_start',
						'value'   => array( time() - 3 * DAY_IN_SECONDS, time() + DAY_IN_SECONDS ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => 'daw_aired_end',
						'value'   => array( time() - 3 * DAY_IN_SECONDS, time() + DAY_IN_SECONDS ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		foreach ( $turned as $id ) {
			do_action( 'litespeed_purge_post', (int) $id );
		}
		if ( function_exists( 'daw_browse_bump_gen' ) ) {
			daw_browse_bump_gen();
		}
		$cal = get_page_by_path( 'airing-calendar' );
		if ( $cal ) {
			do_action( 'litespeed_purge_post', (int) $cal->ID );
		}
		do_action( 'litespeed_purge_url', home_url( '/airing-calendar/' ) );
		do_action( 'litespeed_purge_url', home_url( '/' ) );
	}
}

/**
 * The moment this record's badge turns next, or 0 when it never will again:
 * the premiere for an upcoming show, the day after the finale for one on air
 * (madde 69).
 */
function daw_status_next_turn( $post_id ) {
	$now   = time();
	$start = (int) get_post_meta( $post_id, 'daw_aired_start', true );
	$end   = (int) get_post_meta( $post_id, 'daw_aired_end', true );
	if ( ! $end ) {
		$end = daw_derived_finale( $post_id );
	}
	if ( $start > $now ) {
		return $start;
	}
	if ( $end && $end + DAY_IN_SECONDS > $now ) {
		return $end + DAY_IN_SECONDS;
	}
	return 0;
}

/**
 * The page cache keeps a page no longer than its badge is true. A record
 * page is cached until its next status turn; the airing calendar and the
 * home page until the next UTC midnight, when the day's premieres and
 * finales turn. So On air becomes Completed the moment it should, not when
 * a cache happens to expire (owner, 5 Oct 2026: "hemen gelince saati put
 * diye cekmesi lazim").
 */
add_action( 'template_redirect', 'daw_status_cache_ttl', 5 );
function daw_status_cache_ttl() {
	$ttl = 0;
	$midnight = strtotime( gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) . ' 00:00:00 UTC' ) - time();
	if ( is_singular( 'post' ) ) {
		$id   = (int) get_queried_object_id();
		$turn = daw_status_next_turn( $id );
		$ttl  = $turn ? $turn - time() : 0;
		// A person page carries the On air / Upcoming badges of its titles.
		if ( ! $ttl && function_exists( 'daw_person_upcoming' ) && function_exists( 'daw_get_type' ) && 'person' === daw_get_type( $id ) && daw_person_upcoming( $id ) ) {
			$ttl = $midnight;
		}
	} elseif ( is_front_page() || is_page( 'airing-calendar' ) ) {
		$ttl = $midnight;
	}
	if ( $ttl > 0 ) {
		// At least a minute, so a page about to turn is not rebuilt on every hit.
		do_action( 'litespeed_control_set_ttl', max( 60, (int) $ttl ) );
	}
}

/**
 * Vote distribution across five bands, for the bars in the rating card.
 * Without a row per vote we cannot know the real spread, so this returns an
 * empty array until per-vote storage exists — the card shows the score and the
 * count and leaves the bars out rather than drawing an invented shape.
 */
function daw_rating_distribution( $post_id ) {
	$raw = get_post_meta( $post_id, 'daw_rating_bands', true );

	return is_array( $raw ) ? $raw : array();
}

/**
 * Zip the parallel cast arrays back into rows.
 * This is the only place that knows about the free-tier storage compromise.
 *
 * @param int    $post_id
 * @param string $tier  main|supporting|guest
 * @return array  [ [ 'id' => int, 'role' => string ], … ]
 */
function daw_get_cast( $post_id, $tier = 'main' ) {
	// The untiered bucket is a plain multi-select with no companion role field.
	if ( 'untiered' === $tier ) {
		$people = (array) get_post_meta( $post_id, 'daw_cast_person', true );
		$out    = array();
		foreach ( $people as $pid ) {
			if ( (int) $pid ) {
				$out[] = array( 'id' => (int) $pid, 'role' => '', 'eps' => '' );
			}
		}
		return $out;
	}

	$people = (array) rwmb_meta( "daw_cast_{$tier}_person", array(), $post_id );
	$roles  = (array) rwmb_meta( "daw_cast_{$tier}_role", array(), $post_id );
	$eps    = (array) rwmb_meta( "daw_cast_{$tier}_eps", array(), $post_id );

	$out = array();
	foreach ( $people as $i => $person_id ) {
		$person_id = (int) $person_id;
		if ( ! $person_id ) {
			continue;
		}
		$out[] = array(
			'id'   => $person_id,
			'role' => isset( $roles[ $i ] ) ? (string) $roles[ $i ] : '',
			'eps'  => isset( $eps[ $i ] ) ? trim( (string) $eps[ $i ] ) : '',
		);
	}

	return $out;
}

/**
 * Zip any set of parallel cloneable fields into rows.
 *
 * Every table on a record — episodes, awards, the soundtrack, the FAQ — is
 * stored as parallel columns because the free field plugin has no repeating
 * group. One function unpacks them all, so the pattern lives in exactly one
 * place and every template reads proper rows.
 *
 * @param int   $post_id
 * @param array $keys  ['column name' => 'meta key', …]
 * @return array  list of rows keyed by column name
 */
function daw_zip_rows( $post_id, array $keys ) {
	$cols = array();
	$len  = 0;

	foreach ( $keys as $name => $meta_key ) {
		$vals          = (array) get_post_meta( $post_id, $meta_key, true );
		$cols[ $name ] = $vals;
		$len           = max( $len, count( $vals ) );
	}

	$rows = array();
	for ( $i = 0; $i < $len; $i++ ) {
		$row   = array();
		$empty = true;
		foreach ( $cols as $name => $vals ) {
			$row[ $name ] = isset( $vals[ $i ] ) ? (string) $vals[ $i ] : '';
			if ( '' !== trim( $row[ $name ] ) ) {
				$empty = false;
			}
		}
		if ( ! $empty ) {
			$rows[] = $row;
		}
	}

	return $rows;
}

function daw_get_scenes( $post_id ) {
	return daw_zip_rows( $post_id, array(
		'title' => 'daw_scene_title',
		'ep'    => 'daw_scene_ep',
		'note'  => 'daw_scene_note',
	) );
}

/**
 * The typed relations, zipped back into rows and resolved to live records.
 *
 * A row whose target has been deleted or unpublished is dropped rather than
 * printed as a dead card: the field holds an ID, and an ID can outlive a post.
 */
function daw_get_related_titles( $post_id ) {
	$rows = daw_zip_rows( $post_id, array(
		'post' => 'daw_rel_post',
		'kind' => 'daw_rel_kind',
	) );

	$out = array();
	foreach ( $rows as $row ) {
		$rid = isset( $row['post'] ) ? (int) $row['post'] : 0;
		if ( ! $rid || $rid === (int) $post_id ) {
			continue;
		}
		if ( 'publish' !== get_post_status( $rid ) ) {
			continue;
		}
		$out[] = array(
			'id'    => $rid,
			'kind'  => trim( (string) ( isset( $row['kind'] ) ? $row['kind'] : '' ) ),
			'title' => get_the_title( $rid ),
			'url'   => get_permalink( $rid ),
			'thumb' => function_exists( 'daw_record_image_url' ) ? daw_record_image_url( $rid, 'medium' ) : get_the_post_thumbnail_url( $rid, 'medium' ),
		);
	}

	return $out;
}

/**
 * Episodes, each one told apart into aired, airing today, or still to come.
 *
 * WHY THE STATE IS DERIVED, NOT TYPED
 * ----------------------------------
 * An episode table on a running drama is out of date within a week of anyone
 * touching it. Comparing the stored air date to today costs nothing and is
 * never wrong, so a table filled in once — the whole season, dates and all —
 * keeps telling the truth by itself: episodes turn from upcoming to aired on
 * the night they broadcast, with no one logging in.
 *
 * The same three states are what the airing calendar will read, so a record
 * that is filled in properly here is a record that appears on the calendar
 * without a second round of data entry.
 *
 * @return array [ [ no, title, date, rating, ts, state, in_days ], … ]
 */
function daw_get_episodes( $post_id ) {
	$rows = daw_zip_rows( $post_id, array(
		'no'       => 'daw_ep_no',
		'title'    => 'daw_ep_title',
		'date'     => 'daw_ep_date',
		'rating'   => 'daw_ep_rating',
		'synopsis' => 'daw_ep_synopsis',
	) );

	// Local midnight today, so "airs today" means the reader's today and an
	// episode broadcast this evening is not already filed as history.
	$today = (int) strtotime( current_time( 'Y-m-d' ) . ' 00:00:00' );

	foreach ( $rows as $i => $row ) {
		$ts = $row['date'] ? strtotime( (string) $row['date'] ) : 0;

		$rows[ $i ]['ts']      = $ts ? (int) $ts : 0;
		$rows[ $i ]['in_days'] = 0;

		if ( ! $ts ) {
			// No date is not the same as not yet aired. Say nothing.
			$rows[ $i ]['state'] = '';
			continue;
		}

		$day = (int) strtotime( gmdate( 'Y-m-d', $ts ) . ' 00:00:00' );

		if ( $day < $today ) {
			$rows[ $i ]['state'] = 'aired';
		} elseif ( $day === $today ) {
			$rows[ $i ]['state'] = 'today';
		} else {
			$rows[ $i ]['state']   = 'upcoming';
			$rows[ $i ]['in_days'] = (int) round( ( $day - $today ) / DAY_IN_SECONDS );
		}
	}

	return $rows;
}

/**
 * The next episode due on a record, or null. This is what the airing calendar
 * and the "on air" badge both ask for.
 */
function daw_next_episode( $post_id ) {
	foreach ( daw_get_episodes( $post_id ) as $row ) {
		if ( 'upcoming' === $row['state'] || 'today' === $row['state'] ) {
			return $row;
		}
	}

	return null;
}

/**
 * A human label for an upcoming episode: today, tomorrow, or how many days out.
 */
function daw_episode_when( $row ) {
	if ( empty( $row['state'] ) ) {
		return '';
	}

	if ( 'aired' === $row['state'] ) {
		return __( 'Aired', 'daw' );
	}

	if ( 'today' === $row['state'] ) {
		return __( 'Airs today', 'daw' );
	}

	$days = (int) $row['in_days'];

	if ( 1 === $days ) {
		return __( 'Tomorrow', 'daw' );
	}

	if ( $days <= 14 ) {
		/* translators: %d: number of days */
		return sprintf( _n( 'In %d day', 'In %d days', $days, 'daw' ), $days );
	}

	return __( 'Upcoming', 'daw' );
}

function daw_get_ost( $post_id ) {
	return daw_zip_rows( $post_id, array(
		'track'  => 'daw_ost_track',
		'artist' => 'daw_ost_artist',
	) );
}

function daw_get_awards( $post_id ) {
	return daw_zip_rows( $post_id, array(
		'year'     => 'daw_aw_year',
		'body'     => 'daw_aw_body',
		'category' => 'daw_aw_category',
		'who'      => 'daw_aw_who',
		'result'   => 'daw_aw_result',
	) );
}

function daw_get_faq( $post_id ) {
	return daw_zip_rows( $post_id, array(
		'q' => 'daw_faq_q',
		'a' => 'daw_faq_a',
	) );
}

/**
 * Where-to-watch rows, same zip pattern. Non-official rows are the editor's
 * responsibility; the template links them with rel="nofollow sponsored"
 * only where an affiliate relationship actually exists.
 */
function daw_get_watch_links( $post_id ) {
	$rows = daw_zip_rows( $post_id, array(
		'platform' => 'daw_watch_platform',
		'url'      => 'daw_watch_url',
		'access'   => 'daw_watch_access',
	) );

	$out = array();
	foreach ( $rows as $row ) {
		if ( empty( $row['url'] ) ) {
			continue;
		}
		$out[] = array(
			'platform' => $row['platform'],
			'url'      => esc_url_raw( $row['url'] ),
			'access'   => $row['access'],
		);
	}

	return $out;
}

/**
 * The filmography reverse lookup used to live here, writing three separate
 * index keys and returning bare post objects. It has moved to
 * inc/filmography.php, which writes one index carrying the billing tier with
 * each id, so a profile page gets the tier, the character name and the year
 * from a single query instead of three.
 *
 * Two implementations of the same lookup is how a database starts disagreeing
 * with itself, so this one is gone rather than deprecated. The old
 * _daw_credits_* meta rows it left behind are dead weight and are on the
 * cleanup list.
 */



/** Extra H2 sections of a title record, heading and text zipped. */
function daw_get_title_sections( $post_id ) {
	$t   = get_post_meta( $post_id, 'daw_title_section_title', true );
	$b   = get_post_meta( $post_id, 'daw_title_section_body', true );
	// A single section is often saved as plain strings, not one-item lists;
	// those were silently dropped and the section never showed (1.75.72,
	// KinnPorsche, Infernal Affairs).
	$t   = is_array( $t ) ? array_values( $t ) : ( '' !== trim( (string) $t ) ? array( (string) $t ) : array() );
	$b   = is_array( $b ) ? array_values( $b ) : ( '' !== trim( (string) $b ) ? array( (string) $b ) : array() );
	$out = array();
	foreach ( $t as $i => $h ) {
		$h    = trim( wp_strip_all_tags( (string) $h ) );
		$body = trim( (string) ( $b[ $i ] ?? '' ) );
		if ( '' !== $h && '' !== trim( wp_strip_all_tags( $body ) ) ) {
			$out[] = array( 'title' => $h, 'body' => $body );
		}
	}
	return $out;
}
