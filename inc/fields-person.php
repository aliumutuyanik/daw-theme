<?php
/**
 * The rest of a person.
 *
 * WHY A SECOND FILE
 * -----------------
 * A profile in the approved design carries about forty-four fields across nine
 * labelled cards. Bolting all of them onto the original three-box person schema
 * would have produced one editing screen nobody could find anything in, so the
 * boxes here mirror the cards on the page: what an editor sees while typing has
 * the same shape as what a reader sees afterwards.
 *
 * THE RULE THAT GOVERNS THE PRIVATE-LIFE FIELDS
 * --------------------------------------------
 * These are real, living people. A rumour typed into a field becomes a
 * sentence a search engine repeats, so the private-life fields are built to
 * make the careful answer the easy one: marital status is a closed list,
 * "confirmed partner" means confirmed by the person or their agency, and
 * anything else goes in "reported pairings", which the template renders as a
 * dated, attributed rumour or not at all.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'rwmb_meta_boxes', 'daw_register_person_meta_boxes', 11 );
function daw_register_person_meta_boxes( $boxes ) {
	$boxes[] = daw_box_person_overview();
	$boxes[] = daw_box_person_status();
	$boxes[] = daw_box_person_education();
	$boxes[] = daw_box_person_career();
	$boxes[] = daw_box_person_military();
	$boxes[] = daw_box_person_family();
	$boxes[] = daw_box_person_personality();
	$boxes[] = daw_box_person_music();
	$boxes[] = daw_box_person_shop();
	$boxes[] = daw_box_person_sourcing();
	$boxes[] = daw_box_person_awards();
	$boxes[] = daw_box_person_links();
	$boxes[] = daw_box_person_faq();
	$boxes[] = daw_box_person_asked();

	return $boxes;
}

function daw_person_box( $id, $title, array $fields ) {
	return array(
		'id'         => $id,
		'title'      => $title,
		'post_types' => array( 'post' ),
		'daw_types'  => array( 'person' ),
		'fields'     => $fields,
	);
}

/* ------------------------------------------------------------- overview -- */

function daw_box_person_overview() {
	return daw_person_box(
		'daw_person_overview',
		__( 'Person: overview', 'daw' ),
		array(
			array(
				'id'   => 'daw_person_summary',
				'name' => __( 'One-line summary', 'daw' ),
				'type' => 'textarea',
				'rows' => 2,
				'desc' => __( 'The sentence under the name. What this person does, and the one thing they are known for. It also becomes the meta description when none is written.', 'daw' ),
			),
			array(
				'id'      => 'daw_person_takeaways',
				'name'    => __( 'Key takeaways', 'daw' ),
				'type'    => 'textarea',
				'rows'    => 2,
				'clone'   => true,
				'desc'    => __( 'Three is the right number. Each one a fact a reader could repeat to someone else.', 'daw' ),
			),
			array(
				'id'    => 'daw_person_section_title',
				'name'  => __( 'Overview section: heading', 'daw' ),
				'type'  => 'text',
				'clone' => true,
				'columns' => 4,
			),
			array(
				'id'      => 'daw_person_section_body',
				'name'    => __( 'Overview section: text', 'daw' ),
				'type'    => 'wysiwyg',
				'raw'     => false,
				'clone'   => true,
				'columns' => 8,
				'options' => array( 'textarea_rows' => 6, 'media_buttons' => false ),
				'desc'    => __( 'Row order must match the headings. Row two of each is the same section.', 'daw' ),
			),
		)
	);
}

/* -------------------------------------------------------------- status --- */

function daw_box_person_status() {
	return daw_person_box(
		'daw_person_status',
		__( 'Person: personal status', 'daw' ),
		array(
			array(
				'id'      => 'daw_marital_status',
				'name'    => __( 'Marital status', 'daw' ),
				'type'    => 'select',
				'columns' => 4,
				'options' => array(
					''          => __( 'Not stated', 'daw' ),
					'single'    => __( 'Not married', 'daw' ),
					'married'   => __( 'Married', 'daw' ),
					'divorced'  => __( 'Divorced', 'daw' ),
					'widowed'   => __( 'Widowed', 'daw' ),
				),
			),
			array(
				'id'      => 'daw_partner',
				'name'    => __( 'Confirmed partner', 'daw' ),
				'type'    => 'text',
				'columns' => 4,
				'desc'    => __( 'Only if the person or their agency has confirmed it publicly. Otherwise leave empty.', 'daw' ),
			),
			array(
				'id'      => 'daw_pairings',
				'name'    => __( 'Reported pairings', 'daw' ),
				'type'    => 'textarea',
				'rows'    => 2,
				'columns' => 4,
				'desc'    => __( 'Rumours only, each with a date and who reported it. Rendered as a dated, attributed rumour, never as fact.', 'daw' ),
			),
			array(
				'id'      => 'daw_real_name',
				'name'    => __( 'Real name', 'daw' ),
				'type'    => 'text',
				'columns' => 4,
				'desc'    => __( 'Only where it is already public. A birth name the person has not published is not ours to publish.', 'daw' ),
			),
			array(
				'id'      => 'daw_religion',
				'name'    => __( 'Religion', 'daw' ),
				'type'    => 'text',
				'columns' => 4,
			),
			array(
				'id'      => 'daw_weight_kg',
				'name'    => __( 'Weight (kg)', 'daw' ),
				'type'    => 'number',
				'columns' => 2,
			),
			array(
				'id'      => 'daw_blood_type',
				'name'    => __( 'Blood type', 'daw' ),
				'type'    => 'text',
				'columns' => 2,
			),
			array(
				'id'      => 'daw_chinese_zodiac',
				'name'    => __( 'Chinese zodiac', 'daw' ),
				'type'    => 'text',
				'columns' => 4,
			),
		)
	);
}

/* ----------------------------------------------------------- education --- */

function daw_box_person_education() {
	$rows = array(
		'daw_edu_elementary' => __( 'Elementary school', 'daw' ),
		'daw_edu_middle'     => __( 'Middle school', 'daw' ),
		'daw_edu_high'       => __( 'High school', 'daw' ),
		'daw_edu_university' => __( 'University', 'daw' ),
	);

	$fields = array();
	foreach ( $rows as $id => $label ) {
		$fields[] = array(
			'id'      => $id,
			'name'    => $label,
			'type'    => 'text',
			'columns' => 6,
		);
	}

	return daw_person_box( 'daw_person_education', __( 'Person: education', 'daw' ), $fields );
}

/* -------------------------------------------------------------- career --- */

function daw_box_person_career() {
	return daw_person_box(
		'daw_person_career',
		__( 'Person: career timeline', 'daw' ),
		array(
			array(
				'id'         => 'daw_career_year',
				'name'       => __( 'Year', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 3,
			),
			array(
				'id'         => 'daw_career_event',
				'name'       => __( 'What happened', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 9,
				'desc'       => __( 'Both columns move together. Row three of each is the same year.', 'daw' ),
			),
			array(
				'id'      => 'daw_debut',
				'name'    => __( 'Debut', 'daw' ),
				'type'    => 'text',
				'columns' => 6,
				'desc'    => __( 'e.g. 2010 (model), 2016 (actor)', 'daw' ),
			),
		)
	);
}

/* ------------------------------------------------------------ military --- */

function daw_box_person_military() {
	return daw_person_box(
		'daw_person_military',
		__( 'Person: military service', 'daw' ),
		array(
			array(
				'id'      => 'daw_mil_status',
				'name'    => __( 'Status', 'daw' ),
				'type'    => 'select',
				'columns' => 3,
				'options' => array(
					''           => __( 'Not stated', 'daw' ),
					'completed'  => __( 'Completed', 'daw' ),
					'serving'    => __( 'Currently serving', 'daw' ),
					'enlisting'  => __( 'Due to enlist', 'daw' ),
					'exempt'     => __( 'Exempt', 'daw' ),
					'na'         => __( 'Not applicable', 'daw' ),
				),
			),
			array(
				'id'      => 'daw_mil_branch',
				'name'    => __( 'Branch', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
			),
			array(
				'id'      => 'daw_mil_unit',
				'name'    => __( 'Unit', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
			),
			array(
				'id'      => 'daw_mil_served',
				'name'    => __( 'Served', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
				'desc'    => __( 'e.g. Nov 2011 to Aug 2013', 'daw' ),
			),
		)
	);
}

/* -------------------------------------------------------------- family --- */

function daw_box_person_family() {
	$rows = array(
		'daw_fam_father'   => array( __( 'Father', 'daw' ), 4 ),
		'daw_fam_mother'   => array( __( 'Mother', 'daw' ), 4 ),
		'daw_fam_siblings' => array( __( 'Siblings', 'daw' ), 4 ),
	);

	$fields = array();
	foreach ( $rows as $id => $spec ) {
		$fields[] = array(
			'id'      => $id,
			'name'    => $spec[0],
			'type'    => 'text',
			'columns' => $spec[1],
		);
	}

	$fields[0]['desc'] = __( 'Family members who are not themselves public figures stay out of this box. "Not disclosed" is a complete answer.', 'daw' );

	return daw_person_box( 'daw_person_family', __( 'Person: family', 'daw' ), $fields );
}

/* --------------------------------------------------------- personality --- */

function daw_box_person_personality() {
	return daw_person_box(
		'daw_person_personality',
		__( 'Person: personality', 'daw' ),
		array(
			array(
				'id'      => 'daw_mbti',
				'name'    => __( 'MBTI', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
				'desc'    => __( 'Only what the person said on record, with the earlier result kept if it changed.', 'daw' ),
			),
			array(
				'id'      => 'daw_traits',
				'name'    => __( 'Traits', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
			),
			array(
				'id'      => 'daw_skills',
				'name'    => __( 'Skills', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
			),
			array(
				'id'      => 'daw_instruments',
				'name'    => __( 'Instruments', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
			),
		)
	);
}

/* --------------------------------------------------------------- music --- */

function daw_box_person_music() {
	return daw_person_box(
		'daw_person_music',
		__( 'Person: music credits', 'daw' ),
		array(
			array(
				'id'      => 'daw_music_act',
				'name'    => __( 'Show on the Music page', 'daw' ),
				'type'    => 'select',
				'columns' => 6,
				'options' => array(
					''    => __( 'Work it out from their job title', 'daw' ),
					'yes' => __( 'Yes, they are a recording act', 'daw' ),
					'no'  => __( 'No, keep them off it', 'daw' ),
				),
				'desc'    => __( 'Left alone, the Music page reads the occupation: an idol counts wherever it appears, and everyone else has to be a singer first. That is right for most records and wrong for two kinds. An actor who genuinely releases music is left off, and an actor who has sung once is put on. Answer here and this answer wins. It changes nothing about the People page, where they stay either way.', 'daw' ),
			),
			array(
				'id'   => 'daw_spotify_artist',
				'name' => __( 'Spotify artist or album', 'daw' ),
				'type' => 'url',
				'desc' => __( 'Played in place through the consent-gated embed. Audio is served and counted by Spotify, never hosted here.', 'daw' ),
			),
			array(
				'id'         => 'daw_mus_year',
				'name'       => __( 'Year', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 2,
			),
			array(
				'id'         => 'daw_mus_title',
				'name'       => __( 'Track or appearance', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 5,
			),
			array(
				'id'         => 'daw_mus_credit',
				'name'       => __( 'Credited as', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 3,
				'desc'       => __( 'Lead vocals, Stage, Music video', 'daw' ),
			),
			array(
				'id'         => 'daw_mus_kind',
				'name'       => __( 'Badge', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 2,
				'desc'       => __( 'OST, LIVE, MV', 'daw' ),
			),
		)
	);
}

/* ----------------------------------------------------------- affiliate --- */

function daw_box_person_shop() {
	return daw_person_box(
		'daw_person_shop',
		__( 'Person: related merchandise', 'daw' ),
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
				'desc'       => __( 'Music, Photobook, Print, Home video', 'daw' ),
			),
			array(
				'id'         => 'daw_shop_url',
				'name'       => __( 'Link', 'daw' ),
				'type'       => 'url',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 4,
				'desc'       => __( 'Rendered with rel="sponsored nofollow" under a paid-link label. Nothing here changes what we publish about the person.', 'daw' ),
			),
		)
	);
}

/* ------------------------------------------------------------ sourcing --- */

function daw_box_person_sourcing() {
	return daw_person_box(
		'daw_person_sourcing',
		__( 'Person: data and sourcing', 'daw' ),
		array(
			array(
				'id'      => 'daw_src_portrait',
				'name'    => __( 'Portrait source', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
				'std'     => 'TMDB',
			),
			array(
				'id'      => 'daw_src_rights',
				'name'    => __( 'Rights holder', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
			),
			array(
				'id'      => 'daw_src_basis',
				'name'    => __( 'Usage basis', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
				'std'     => 'Identification and commentary',
			),
			array(
				'id'      => 'daw_src_meta',
				'name'    => __( 'Metadata source', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
				'std'     => 'TMDB and editorial',
			),
			array(
				'id'      => 'daw_src_editor',
				'name'    => __( 'Editor', 'daw' ),
				'type'    => 'text',
				'columns' => 3,
				'std'     => 'DAW team',
				'desc'    => __( 'Shown on the page. A profile with a named editor behind it is a profile someone is answerable for.', 'daw' ),
			),
		)
	);
}

/* -------------------------------------------------------------- awards --- */

function daw_box_person_awards() {
	return daw_person_box(
		'daw_person_awards',
		__( 'Person: awards', 'daw' ),
		array(
			// The button that fills the rows below from Wikipedia. It sits at
			// the top of the box because the first question an editor has when
			// opening an empty awards table is whether they have to type it.
			array(
				'id'       => 'daw_paw_import',
				'type'     => 'custom_html',
				'callback' => 'daw_awards_button_html',
			),
			array(
				'id'         => 'daw_paw_year',
				'name'       => __( 'Year', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 2,
			),
			array(
				'id'         => 'daw_paw_body',
				'name'       => __( 'Ceremony', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 3,
				'desc'       => __( 'e.g. MBC Drama Awards', 'daw' ),
			),
			array(
				'id'         => 'daw_paw_category',
				'name'       => __( 'Category', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 3,
				'desc'       => __( 'e.g. Grand Prize (Daesang)', 'daw' ),
			),
			// THE COLUMN THAT MAKES THE AWARD A LINK
			// --------------------------------------
			// An award is almost always for a piece of work, and that work is
			// usually already a record here. Kept in its own column it can be
			// matched to that record and printed as a link, so the awards table
			// feeds the filmography instead of sitting beside it as dead text.
			// Typed exactly as the record is titled; an unmatched name still
			// prints, just without a link.
			array(
				'id'         => 'daw_paw_work',
				'name'       => __( 'For which work', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 2,
				'desc'       => __( 'Record title. Linked automatically when it matches.', 'daw' ),
			),
			array(
				'id'         => 'daw_paw_result',
				'name'       => __( 'Result', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 2,
				'desc'       => __( 'Won or Nominated. Only wins are claimed in structured data.', 'daw' ),
			),
			// KEPT BECAUSE A THOUSAND RECORDS STILL USE IT
			// -------------------------------------------
			// Every person profile written before the columns above existed holds
			// the whole award in this one line. Dropping the field would blank
			// those tables, so it stays and is read as the category when the
			// columns are empty. New rows use the columns.
			array(
				'id'         => 'daw_paw_name',
				'name'       => __( 'Award (old single line)', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 12,
				'desc'       => __( 'Older rows only. Leave empty when the columns above are filled.', 'daw' ),
			),
		)
	);
}

/* --------------------------------------------------------------- links --- */

function daw_box_person_links() {
	$rows = array(
		'daw_link_imdb'       => __( 'IMDb', 'daw' ),
		'daw_link_mdl'        => __( 'MyDramaList', 'daw' ),
		'daw_link_wikipedia'  => __( 'Wikipedia', 'daw' ),
		'daw_link_agency'     => __( 'Agency page', 'daw' ),
		'daw_link_apple'      => __( 'Apple Music', 'daw' ),
		// The person's own page on the BL/GL sister site, filled only when
		// that page is live; the profile then hands the pairing work over.
		'daw_blgl_url'        => __( 'BL/GL page (BLGLFlix)', 'daw' ),
	);

	$fields = array();
	foreach ( $rows as $id => $label ) {
		$fields[] = array(
			'id'      => $id,
			'name'    => $label,
			'type'    => 'url',
			'columns' => 6,
		);
	}

	$fields[0]['desc'] = __( 'Official and authoritative pages only. These sit beside the social accounts as the profile\'s sources, so a fan page has no place among them.', 'daw' );

	return daw_person_box( 'daw_person_links', __( 'Person: official links', 'daw' ), $fields );
}

/* ----------------------------------------------------------------- faq --- */

function daw_box_person_faq() {
	return daw_person_box(
		'daw_person_faq',
		__( 'Person: frequently asked questions', 'daw' ),
		array(
			array(
				'id'         => 'daw_pfaq_q',
				'name'       => __( 'Question', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
			),
			array(
				'id'         => 'daw_pfaq_a',
				'name'       => __( 'Answer', 'daw' ),
				'type'       => 'textarea',
				'rows'       => 3,
				'clone'      => true,
				'sort_clone' => true,
				'desc'       => __( 'Row order must match the questions. These are the sentences a search engine quotes back, so each answer stands on its own.', 'daw' ),
			),
		)
	);
}

/* ------------------------------------------------------------ asked --- */

/**
 * THE THREE THINGS THIS PARTICULAR PERSON IS ASKED
 * ------------------------------------------------
 * The card at the top of the page answers the questions a reader arrived with,
 * and most of those answers are already on the record: how many credits, married
 * or not, how tall, when discharged. The template derives those without anyone
 * typing anything.
 *
 * What it cannot derive is which question is the big one for THIS person. The
 * search numbers for Seo Kang Joon put his eye colour above his age and his
 * height combined; for a singer it is the group; for someone who left the
 * industry it is where they went. That is a fact about demand, not about the
 * person, so it has to be entered by whoever looked the demand up.
 *
 * Three rows, no more. A card that answers three questions is read. A card that
 * answers nine is a table, and the reader has one of those further down.
 */
function daw_box_person_asked() {
	return daw_person_box(
		'daw_person_asked',
		__( 'Person: most asked', 'daw' ),
		array(
			array(
				'id'         => 'daw_ask_q',
				'name'       => __( 'Question, as a label', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 4,
				'desc'       => __( 'Two or three words, the way it sits in a list: Eye colour. Group. Military service. Not a sentence.', 'daw' ),
			),
			array(
				'id'         => 'daw_ask_a',
				'name'       => __( 'The answer, one line', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 5,
				'desc'       => __( 'It has to answer the question on its own, in one line, with no reading required. If the honest answer is that nobody knows, say that.', 'daw' ),
			),
			array(
				'id'         => 'daw_ask_to',
				'name'       => __( 'Jumps to', 'daw' ),
				'type'       => 'text',
				'clone'      => true,
				'sort_clone' => true,
				'columns'    => 3,
				'desc'       => __( 'The anchor on this page that carries the long version, written with the hash: #about-those-eyes. Leave empty and the row is text rather than a link.', 'daw' ),
			),
		)
	);
}

/* ======================================================= READ HELPERS ===== */

function daw_get_person_sections( $post_id ) {
	return daw_zip_rows( $post_id, array(
		'title' => 'daw_person_section_title',
		'body'  => 'daw_person_section_body',
	) );
}

function daw_get_career( $post_id ) {
	return daw_zip_rows( $post_id, array(
		'year'  => 'daw_career_year',
		'event' => 'daw_career_event',
	) );
}

function daw_get_music_credits( $post_id ) {
	return daw_zip_rows( $post_id, array(
		'year'   => 'daw_mus_year',
		'title'  => 'daw_mus_title',
		'credit' => 'daw_mus_credit',
		'kind'   => 'daw_mus_kind',
	) );
}

function daw_get_shop( $post_id ) {
	return daw_zip_rows( $post_id, array(
		'title' => 'daw_shop_title',
		'kind'  => 'daw_shop_kind',
		'url'   => 'daw_shop_url',
	) );
}

function daw_get_person_awards( $post_id ) {
	$rows = daw_zip_rows( $post_id, array(
		'year'     => 'daw_paw_year',
		'name'     => 'daw_paw_name',
		'body'     => 'daw_paw_body',
		'category' => 'daw_paw_category',
		'work'     => 'daw_paw_work',
		'result'   => 'daw_paw_result',
	) );

	$out = array();

	foreach ( $rows as $row ) {
		$row['body']     = trim( (string) $row['body'] );
		$row['category'] = trim( (string) $row['category'] );
		$row['work']     = trim( (string) $row['work'] );
		$row['name']     = trim( (string) $row['name'] );

		// An older row carries everything in one line. It is shown as written
		// rather than guessed apart: a comma is not a reliable seam.
		if ( '' === $row['category'] && '' === $row['body'] ) {
			$row['category'] = $row['name'];
		}

		if ( '' === $row['category'] && '' === $row['body'] && '' === $row['work'] ) {
			continue;
		}

		$row['work_url'] = daw_work_url( $row['work'] );

		// One readable line, for the places that cannot print a table: the
		// structured data, and any card that has a single column to spend.
		$label = $row['category'];

		if ( '' !== $row['body'] ) {
			$label = '' === $label ? $row['body'] : $label . ', ' . $row['body'];
		}

		$row['label'] = $label;

		$out[] = $row;
	}

	// Newest first. A career reads better from the most recent prize backwards,
	// and the row a reader came for is almost always the latest one.
	usort(
		$out,
		static function ( $a, $b ) {
			return (int) preg_replace( '~[^0-9]~', '', (string) $b['year'] )
				<=> (int) preg_replace( '~[^0-9]~', '', (string) $a['year'] );
		}
	);

	return $out;
}

/**
 * The record a piece of work refers to, if this site holds one.
 *
 * WHY A LOOKUP AND NOT A STORED ID
 * --------------------------------
 * The award is entered while reading a source that gives a title, not a post
 * id, and half the works named do not have a record yet. Storing an id would
 * mean either leaving the field blank until the record exists, or going back
 * to every award row on the day it is created. A title lookup fills itself in:
 * the moment "Cheese in the Trap" is opened as a record, every award row that
 * names it starts linking, with nothing re-entered.
 *
 * The cost is a query per distinct title, so the answer is cached for a day and
 * the id is looked up once per request. A miss is cached too -- otherwise a
 * person with ten unmatched works runs ten queries on every page view.
 */
function daw_work_url( $title ) {
	$title = trim( (string) $title );

	if ( '' === $title ) {
		return '';
	}

	static $seen = array();

	if ( isset( $seen[ $title ] ) ) {
		return $seen[ $title ];
	}

	$key    = 'daw_work_url_' . md5( $title );
	$cached = get_transient( $key );

	if ( false !== $cached ) {
		$seen[ $title ] = (string) $cached;
		return $seen[ $title ];
	}

	$found = get_posts( array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'title'                  => $title,
		'posts_per_page'         => 1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$url = '';

	// Only a record answers here. A news article about the ceremony has the
	// same words in its title often enough that an untyped match would send
	// the reader to the wrong page.
	if ( $found && has_term( '', 'dw_type', $found[0] ) ) {
		$url = (string) get_permalink( $found[0] );
	}

	set_transient( $key, $url, DAY_IN_SECONDS );
	$seen[ $title ] = $url;

	return $url;
}

function daw_get_person_faq( $post_id ) {
	return daw_zip_rows( $post_id, array(
		'q' => 'daw_pfaq_q',
		'a' => 'daw_pfaq_a',
	) );
}

/** The hand-entered rows for the "Most asked" card. Three at most; the rest is derived. */
function daw_get_person_asked( $post_id ) {
	$rows = daw_zip_rows( $post_id, array(
		'q'  => 'daw_ask_q',
		'a'  => 'daw_ask_a',
		'to' => 'daw_ask_to',
	) );

	$out = array();

	foreach ( $rows as $row ) {
		// A label with no answer is a promise the card cannot keep, so it is
		// not a row at all.
		if ( '' === trim( (string) $row['q'] ) || '' === trim( (string) $row['a'] ) ) {
			continue;
		}
		$out[] = $row;
	}

	return array_slice( $out, 0, 3 );
}

/**
 * Age, worked out rather than stored, and stopped at the date of death.
 * A stored age is wrong on the person's next birthday.
 */
function daw_person_age( $post_id ) {
	$born = (int) daw_field_raw( $post_id, 'daw_born' );
	if ( ! $born ) {
		return 0;
	}

	$died = (int) daw_field_raw( $post_id, 'daw_died' );
	$end  = $died ? $died : time();

	$age = (int) gmdate( 'Y', $end ) - (int) gmdate( 'Y', $born );
	if ( (int) gmdate( 'md', $end ) < (int) gmdate( 'md', $born ) ) {
		$age--;
	}

	return max( 0, $age );
}

/** Centimetres to feet and inches, for the readers who think in those. */
function daw_height_imperial( $cm ) {
	$cm = (int) $cm;
	if ( ! $cm ) {
		return '';
	}

	$inches = round( $cm / 2.54 );

	return sprintf( '%d\'%d"', intdiv( $inches, 12 ), $inches % 12 );
}

/**
 * How complete this profile is.
 *
 * Printed on the page on purpose. A database that shows its own gaps is easier
 * to trust than one that quietly presents a thin record as a full one, and it
 * tells the person filling records in where the work is.
 */
function daw_person_completeness( $post_id ) {
	$fields = array(
		'daw_native_name', 'daw_real_name', 'daw_nationality', 'daw_gender', 'daw_religion',
		'daw_born', 'daw_birth_place', 'daw_zodiac', 'daw_chinese_zodiac', 'daw_height_cm',
		'daw_weight_kg', 'daw_blood_type', 'daw_marital_status', 'daw_children', 'daw_partner',
		'daw_pairings', 'daw_edu_elementary', 'daw_edu_middle', 'daw_edu_high', 'daw_edu_university',
		'daw_agency', 'daw_debut', 'daw_mil_status', 'daw_mil_branch', 'daw_mil_unit', 'daw_mil_served',
		'daw_fam_father', 'daw_fam_mother', 'daw_fam_siblings', 'daw_mbti', 'daw_traits',
		'daw_skills', 'daw_instruments', 'daw_person_summary', 'daw_biography', 'daw_hobbies',
		'daw_interests', 'daw_facts', 'daw_tmdb_id', 'daw_social_instagram',
		'daw_src_portrait', 'daw_src_rights', 'daw_src_basis', 'daw_src_editor',
	);

	$filled = 0;
	foreach ( $fields as $key ) {
		$value = daw_field_raw( $post_id, $key );
		if ( is_array( $value ) ) {
			$value = implode( '', $value );
		}
		if ( '' !== trim( (string) $value ) ) {
			$filled++;
		}
	}

	return array(
		'filled' => $filled,
		'total'  => count( $fields ),
		'pct'    => (int) round( $filled / count( $fields ) * 100 ),
	);
}
