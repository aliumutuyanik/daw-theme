<?php
/**
 * A person's credits, derived rather than typed.
 *
 * ONE ENTRY, TWO PAGES
 * --------------------
 * Nobody maintains two lists that agree. If a filmography were its own field,
 * adding Lee Jong Suk to Pinocchio's cast would leave his profile wrong until
 * somebody remembered to go and edit it too, and on 926 records that is the
 * same as saying his profile stays wrong. So the cast block on a title is the
 * only place a credit is ever entered, and the filmography is that relationship
 * read backwards.
 *
 * WHY AN INDEX AND NOT A META QUERY
 * ---------------------------------
 * The cast lives in serialised arrays, which MySQL cannot look inside. Rather
 * than load every title and unserialise it, each title writes a flat index on
 * save: ",5301:main,5362:main,23702:supporting,". One indexed LIKE then answers
 * "everything this person was in", and the tier comes back with it, so the
 * split by billing costs nothing extra.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_CAST_IDX = '_daw_cast_idx';

/* ================================================================ index === */

add_action( 'save_post_post', 'daw_reindex_cast', 20, 1 );
function daw_reindex_cast( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( ! in_array( daw_get_type( $post_id ), array( 'drama', 'film', 'variety', 'anime' ), true ) ) {
		return;
	}

	$pairs = array();
	$seen  = array();

	// "untiered" is not an afterthought: the extraction that recovered the cast
	// from the old Elementor pages wrote everyone into that one bucket, because
	// the old widget was a flat tag query with no notion of billing. Indexing
	// only the three tiered buckets meant every record except the ones tiered by
	// hand was invisible to the filmography, which is the bug that made a
	// profile look empty when the data was sitting right there.
	foreach ( array( 'main', 'supporting', 'guest', 'untiered' ) as $tier ) {
		foreach ( daw_get_cast( $post_id, $tier ) as $row ) {
			$pid = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( ! $pid || isset( $seen[ $pid ] ) ) {
				continue;
			}
			$seen[ $pid ] = true;
			$pairs[]      = $pid . ':' . $tier;
		}
	}

	if ( $pairs ) {
		update_post_meta( $post_id, DAW_CAST_IDX, ',' . implode( ',', $pairs ) . ',' );
	} else {
		delete_post_meta( $post_id, DAW_CAST_IDX );
	}

	// ONE FLUSH PER BATCH, NOT ONE PER RECORD
	// ---------------------------------------
	// daw_flush_person_cache() is a DELETE across the whole options table. Run
	// once after a save it costs nothing; run once per record inside a fifty
	// record backfill it is fifty full table scans, which is what pushed the
	// earlier two hundred record run past the gateway timeout. The backfill
	// sets this flag and flushes once when it is done.
	if ( empty( $GLOBALS['daw_ci_batch'] ) ) {
		daw_flush_person_cache();
	}
}

/*
 * A CAST WRITTEN OUTSIDE THE EDITOR STILL REINDEXES (1.75.3)
 * ----------------------------------------------------------
 * The index was rebuilt only on save_post. Cast rows written straight to meta
 * (the chat's connector, imports) left it as it was: Alchemy of Souls had its
 * three leads tiered in meta while the index still filed all seven as
 * "untiered", so every profile showed the drama under Other credits and the
 * audit reported the actors as missing from its cast. Any change to a cast
 * key now reindexes that record once, at the end of the request.
 */
add_action( 'added_post_meta', 'daw_cast_meta_changed', 10, 3 );
add_action( 'updated_post_meta', 'daw_cast_meta_changed', 10, 3 );
add_action( 'deleted_post_meta', 'daw_cast_meta_changed', 10, 3 );
function daw_cast_meta_changed( $mid, $post_id, $key ) {
	if ( ! is_string( $key ) || 0 !== strpos( $key, 'daw_cast_' ) ) {
		return;
	}
	static $queued = array();
	$post_id = (int) $post_id;
	if ( ! $post_id || isset( $queued[ $post_id ] ) ) {
		return;
	}
	$queued[ $post_id ] = true;
	add_action(
		'shutdown',
		static function () use ( $post_id ) {
			daw_reindex_cast( $post_id );
		}
	);
}

/** One pass over every title, 80 a tick, to repair indexes written before 1.75.3. */
add_action( 'daw_fix_tick', 'daw_cast_reindex_backfill', 6 );
function daw_cast_reindex_backfill() {
	if ( '1' === (string) get_option( 'daw_cast_reidx_done' ) ) {
		return;
	}
	global $wpdb;
	$after = (int) get_option( 'daw_cast_reidx_pos', 0 );
	$ids   = array_map(
		'intval',
		(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( 'daw_cast_main_person', 'daw_cast_supporting_person', 'daw_cast_guest_person', 'daw_cast_person' ) AND post_id > %d ORDER BY post_id ASC LIMIT 80",
				$after
			)
		)
	);
	if ( ! $ids ) {
		update_option( 'daw_cast_reidx_done', '1', false );
		daw_flush_person_cache();
		return;
	}
	$GLOBALS['daw_ci_batch'] = true;
	foreach ( $ids as $id ) {
		if ( 'publish' === get_post_status( $id ) ) {
			daw_reindex_cast( $id );
		}
	}
	$GLOBALS['daw_ci_batch'] = false;
	update_option( 'daw_cast_reidx_pos', max( $ids ), false );
	daw_flush_person_cache();
}

function daw_flush_person_cache() {
	global $wpdb;

	// A persistent object cache keeps transients out of the options table,
	// so the DELETE below never reached them: Seorabeol Knife stayed in Cha
	// Eun Woo's filmography after he left its cast (29 Oct 2026). The keys
	// carry a generation number, and a flush moves it on.
	update_option( 'daw_flm_gen', (int) get_option( 'daw_flm_gen', 0 ) + 1, false );

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_daw_flm_%' OR option_name LIKE '_transient_timeout_daw_flm_%'"
	);
}

/* ========================================================== filmography === */

/** The buckets, always all of them. Callers index them without checking. */
function daw_empty_filmography() {
	return array(
		'lead'       => array(),
		'supporting' => array(),
		'guest'      => array(),
		'other'      => array(),
		'crew'       => array(),
	);
}

/**
 * @return array  [ 'lead' => [row,…], 'supporting' => [...], 'guest' => [...] ]
 *                Each row: id, title, url, year, tier, role, network, kind, rating.
 */
function daw_get_filmography( $person_id ) {
	global $wpdb;

	$person_id = (int) $person_id;

	// A CACHE KEY CARRIES THE SHAPE IT WAS WRITTEN IN
	// -----------------------------------------------
	// This function's return shape changed when "other credits" was added. The
	// old key kept serving day-old arrays with no 'other' bucket, and every
	// caller that read $film['other'] hit null: a fatal on a live profile page,
	// caused purely by upgrading. The shape is now part of the key, so an
	// upgrade can never read a payload written by a different version, and
	// daw_empty_filmography() below guarantees every bucket exists even if an
	// old payload somehow arrives.
	// ...AND THE CODE THAT FILLED IT
	// ------------------------------
	// Versioning the shape was not enough. When the placeholder filter learned
	// to drop "N/A (Film)", the filter shipped and the page still printed
	// "N/A (Film) · Woo-jin" for a day, because the network had been baked
	// into a payload written by the previous release. The plugin version is in
	// the key now, so every release starts from a clean read and a fix is
	// visible the moment it is installed. The daw_flm_ prefix is kept so
	// daw_flush_person_cache() still clears these.
	$key    = 'daw_flm_v3_' . str_replace( '.', '', DAW_VERSION ) . '_' . (int) get_option( 'daw_flm_gen', 0 ) . '_' . $person_id;
	$cached = get_transient( $key );

	if ( is_array( $cached ) ) {
		return array_merge( daw_empty_filmography(), $cached );
	}

	$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
			DAW_CAST_IDX,
			'%' . $wpdb->esc_like( ',' . $person_id . ':' ) . '%'
		)
	);

	$out = daw_empty_filmography();

	foreach ( $ids as $title_id ) {
		$title_id = (int) $title_id;

		if ( 'publish' !== get_post_status( $title_id ) ) {
			continue;
		}

		$tier = daw_cast_tier_of( $title_id, $person_id );
		if ( ! $tier ) {
			continue;
		}

		$start = (int) daw_field_raw( $title_id, 'daw_aired_start' );

		$row = array(
			'id'      => $title_id,
			'title'   => get_the_title( $title_id ),
			'url'     => get_permalink( $title_id ),
			'year'    => $start ? (int) gmdate( 'Y', $start ) : 0,
			'tier'    => $tier,
			'role'    => daw_cast_role_of( $title_id, $person_id, $tier ),
			'eps'     => daw_cast_eps_of( $title_id, $person_id, $tier ),
			'network' => daw_first_term_name( $title_id, 'dw_network' ),
			'kind'    => daw_get_type( $title_id ),
			'rating'  => daw_title_rating_value( $title_id ),
		);

		// A credit with no billing recorded goes in its own group rather than
		// being quietly filed as supporting. Guessing billing is the one thing
		// a credit list must not do.
		$map    = array( 'main' => 'lead', 'supporting' => 'supporting', 'guest' => 'guest' );
		$bucket = $map[ $tier ] ?? 'other';
		$out[ $bucket ][] = $row;
	}

	// Behind the camera: titles this person directed, wrote or wrote the
	// original of, read from the TMDB crew list stored on each title. The
	// job goes where a character name would.
	if ( function_exists( 'daw_crew_titles_of' ) ) {
		foreach ( daw_crew_titles_of( $person_id ) as $title_id => $jobs ) {
			$start = (int) daw_field_raw( $title_id, 'daw_aired_start' );
			$out['crew'][] = array(
				'id'      => $title_id,
				'title'   => get_the_title( $title_id ),
				'url'     => get_permalink( $title_id ),
				'year'    => $start ? (int) gmdate( 'Y', $start ) : 0,
				'tier'    => 'crew',
				'role'    => implode( ', ', $jobs ),
				'eps'     => '',
				'network' => daw_first_term_name( $title_id, 'dw_network' ),
				'kind'    => daw_get_type( $title_id ),
				'rating'  => daw_title_rating_value( $title_id ),
			);
		}
	}

	// Newest first inside each billing group: a profile is read as "what are
	// they in now", not as a chronology.
	foreach ( $out as $bucket => $rows ) {
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['year'] <=> $a['year'];
			}
		);
		$out[ $bucket ] = $rows;
	}

	set_transient( $key, $out, DAY_IN_SECONDS );

	return $out;
}

function daw_cast_tier_of( $title_id, $person_id ) {
	$idx = (string) get_post_meta( $title_id, DAW_CAST_IDX, true );

	if ( preg_match( '/,' . (int) $person_id . ':([a-z]+),/', $idx, $m ) ) {
		return $m[1];
	}

	return '';
}

function daw_cast_role_of( $title_id, $person_id, $tier ) {
	// The untiered bucket has no companion role field; there is nothing to read.
	if ( 'untiered' === $tier ) {
		return '';
	}

	foreach ( daw_get_cast( $title_id, $tier ) as $row ) {
		if ( (int) $row['id'] === (int) $person_id ) {
			return (string) $row['role'];
		}
	}

	return '';
}

/**
 * How much of a title somebody was in, when it is worth saying.
 *
 * Empty for a series regular, because being in all of it is the default and
 * printing "12 episodes" against twelve episodes tells a reader nothing.
 */
function daw_cast_eps_of( $title_id, $person_id, $tier ) {
	if ( 'untiered' === $tier ) {
		return '';
	}

	foreach ( daw_get_cast( $title_id, $tier ) as $row ) {
		if ( (int) $row['id'] === (int) $person_id ) {
			return isset( $row['eps'] ) ? (string) $row['eps'] : '';
		}
	}

	return '';
}

function daw_first_term_name( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );

	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}

	// "N/A" IS NOT A CHANNEL
	// ---------------------
	// The archive carries a family of placeholder terms left by the old import:
	// N/A, Unknown, None, a bare dash. They are a way of writing "we do not
	// know" into a field, and the site's rule is that we do not know is printed
	// as nothing at all. A filmography card was reading them straight out and
	// putting "My Love, My Bride  N/A  Joon-soo" under a poster, which tells a
	// reader we have no idea what we are talking about.
	//
	// The guard lives here, at the point every card reads its channel, so no
	// page can print one whatever state the taxonomy is left in. The terms
	// themselves still need clearing out, but no reader waits for that.
	foreach ( $terms as $term ) {
		if ( ! daw_is_placeholder_term( $term->name ) ) {
			return $term->name;
		}
	}

	return '';
}

/**
 * Strip the styling an editor left behind in a prose field.
 *
 * WHERE THE JUNK COMES FROM
 * -------------------------
 * Some of the older records were written somewhere that pastes its own
 * highlight markup along with the words, so the stored HTML is full of
 * <span class="relative -mx-px my-[-0.2rem] rounded px-px py-[0.2rem]">.
 * The classes mean nothing on this site, they render as nothing, and every
 * page that carries them ships a few hundred bytes of another tool's styling
 * to every reader.
 *
 * Cleaning it at the point of printing fixes every record at once, including
 * the ones nobody has looked at yet. The stored value is left alone, so
 * nothing is destroyed and a later clean-up pass can still do the tidier job.
 */
function daw_strip_editor_junk( $html ) {
	$html = (string) $html;

	if ( false === strpos( $html, 'class=' ) ) {
		return $html;
	}

	// Utility-class soup: anything with a Tailwind-style bracket or one of the
	// spacing prefixes, on a span that is doing no work.
	$html = preg_replace( '~\s*class="(?:[^"]*(?:-mx-px|my-\[|px-px|py-\[|rounded)[^"]*)"~i', '', $html );

	return preg_replace( '~<span\s*>(.*?)</span>~is', '$1', $html );
}

/** Whether a term name is one of the old import's ways of writing "unknown". */
function daw_is_placeholder_term( $name ) {
	return daw_is_placeholder_value( $name );
}

/**
 * Whether a stored value is a way of writing "we do not know".
 *
 * EVERY FIELD ON THE SITE CAN BE FILLED IN WITH A SHRUG
 * ----------------------------------------------------
 * The imports did not leave unknown fields empty. They wrote the shrug into
 * them: N/A in a channel, "To be confirmed" in an airing day, "[Information
 * not available]" in a content rating. Each one then prints as though it were
 * a fact, so a drama's fact line reads "tvN · 16 episodes · 60 min · To be
 * confirmed", and a reader learns that we do not know what we are talking
 * about.
 *
 * The site's rule is that an empty field is printed as nothing at all. This is
 * the one place that decides what counts as empty, so a field cleaned here is
 * cleaned everywhere that asks.
 */
function daw_is_placeholder_value( $value ) {
	// A SHRUG WITH A QUALIFIER BOLTED ON IS STILL A SHRUG
	// ---------------------------------------------------
	// The old import also produced terms like "N/A (Film)", "N/A (Theatrical
	// Release)" and "N/A (The film was released in theaters...)" — the same
	// non-answer with a bracketed note after it. The bracketed part is dropped
	// here, BEFORE the trim below, because that trim strips a trailing ")" and
	// would otherwise leave "n/a (film" with nothing left to match on. A
	// filmography card was printing "N/A (Film) · Woo-jin" under a poster.
	$value = strtolower( trim( wp_strip_all_tags( (string) $value ) ) );
	$value = (string) preg_replace( '~\s*\([^)]*\)~', ' ', $value );
	$value = trim( $value, " \t\n\r\0\x0B.-–—[](){}:" );
	$value = preg_replace( '~\s+~', ' ', $value );

	$shrugs = array(
		'', 'n/a', 'na', 'n a', 'none', 'null', 'nil', 'unknown', 'not known',
		'not applicable', 'not available', 'no information', 'information not available',
		'not specified', 'not stated', 'not recorded', 'no data', 'data not available',
		'tbd', 'tba', 'to be determined', 'to be confirmed', 'to be announced',
		'pending', 'unconfirmed', 'undisclosed', 'not disclosed', 'other', 'various',
	);

	return in_array( $value, $shrugs, true );
}

/*
 * "THEATRICAL RELEASE" IS NOT A NETWORK (1.75.74)
 * ----------------------------------------------
 * The old import wrote how a film came out into the channel field: 102 films
 * carry the network "Theatrical Release", and a few more "Cinema",
 * "— Theatrical release by Showbox", "Lotte Entertainment (Theatrical
 * Release)". Printed as a fact it read "Network: Theatrical Release" on the
 * Details card, and the meta description Google shows said "Bad Genius is a
 * Theatrical Release Thai movie from 2017". A film with no broadcaster has no
 * network, and an empty field is printed as nothing (rule 2.1).
 */
function daw_is_release_note_network( $name ) {
	$name = strtolower( trim( wp_strip_all_tags( (string) $name ) ) );
	return '' !== $name && (bool) preg_match( '~theatrical|^cinema$|film festival release~', $name );
}

/*
 * ONE DOOR FOR EVERY CHANNEL READ
 * Every page, card, FAQ answer, description and Similar match reads its
 * network through get_the_terms(); the seo.php description and faq-build.php
 * read it there without the placeholder guard daw_first_term_name() has, so
 * the 16 "N/A" films could still reach Google as "is a N/A ... movie". The
 * shrugs and the release notes are dropped here, for readers only: the
 * editors' screens, the backfill and the extractor (wp_get_object_terms)
 * still see the stored terms, so nothing is lost and they can be cleaned.
 */
add_filter( 'get_the_terms', 'daw_network_terms_for_readers', 10, 3 );
function daw_network_terms_for_readers( $terms, $post_id, $taxonomy ) {
	if ( 'dw_network' !== $taxonomy || ! is_array( $terms ) || is_admin() ) {
		return $terms;
	}
	$keep = array_values(
		array_filter(
			$terms,
			static fn( $t ) => is_object( $t ) && ! daw_is_placeholder_value( $t->name ) && ! daw_is_release_note_network( $t->name )
		)
	);
	return $keep ? $keep : false;
}

/**
 * A stored value, or an empty string when it is a shrug.
 *
 * Templates print what this returns, so a placeholder never reaches a reader.
 */
function daw_clean_value( $value ) {
	$value = trim( wp_strip_all_tags( (string) $value ) );

	return daw_is_placeholder_value( $value ) ? '' : $value;
}

/** Our own reader score, or 0. Never a number borrowed from elsewhere. */
function daw_title_rating_value( $title_id ) {
	if ( ! function_exists( 'daw_rating' ) ) {
		return 0;
	}

	$rating = daw_rating( $title_id );

	return $rating['count'] ? (float) $rating['avg'] : 0;
}

/** Flat count across all three billing groups, for the hero stat. */
function daw_credit_count( $person_id ) {
	$film = daw_get_filmography( $person_id );

	return count( $film['lead'] ) + count( $film['supporting'] ) + count( $film['guest'] ) + count( $film['other'] );
}

/**
 * The years this person has been working on screen.
 *
 * WHY THE START DOES NOT COME FROM OUR OWN INDEX
 * ---------------------------------------------
 * It used to. The stat read the earliest and latest year in the filmography
 * and printed the range, which is only honest on a database where every cast
 * block is filled. On a half-filled one it printed "2016 to 2018" for a man who
 * debuted in 2012 and is shooting today, and it printed it as a fact, in a
 * stat card, next to his height. Nobody reading that can tell it is a
 * measurement of our data rather than of his career.
 *
 * So the start is the debut year off the record, which is a sourced fact, and
 * the index is only allowed to move it earlier, never later.
 *
 * WHY THE END IS USUALLY NOT PRINTED AT ALL
 * -----------------------------------------
 * "2016 to 2018" claims he stopped in 2018. We have no such fact. A closing
 * year is claimed in exactly two cases: the person has died, or there is a
 * credit recent enough to say "to present". Otherwise the card says when they
 * started and stops talking, because that is the whole of what we know.
 *
 * WHY THIS STILL RETURNS A STRING
 * -------------------------------
 * It briefly returned an array of value and label, and that shipped as a plugin
 * release while the theme that reads it was still the previous version. The
 * template printed the array, so every profile on the site displayed the word
 * "Array" where the years should be. A plugin and a theme are installed
 * separately and are therefore never the same age, so a reader function may add
 * things but may not change the shape of what it already returned. The label is
 * a second function.
 *
 * @return string  '2012 to present', '2012', '1975 to 2018', or ''.
 */
function daw_active_years( $person_id ) {
	$years = array();

	foreach ( daw_get_filmography( $person_id ) as $rows ) {
		foreach ( $rows as $row ) {
			if ( $row['year'] ) {
				$years[] = $row['year'];
			}
		}
	}

	// "2010 (model)" and "2016 (actor)" are both normal in this field, so the
	// first four digit year in it is the answer and the rest is prose.
	if ( preg_match( '/\b(?:19|20)\d{2}\b/', (string) daw_field_raw( $person_id, 'daw_debut' ), $m ) ) {
		$years[] = (int) $m[0];
	}

	if ( ! $years ) {
		return array();
	}

	$from    = min( $years );
	$to      = max( $years );
	$current = (int) gmdate( 'Y' );
	$died    = (int) daw_field_raw( $person_id, 'daw_died' );

	if ( $died ) {
		$end = (int) gmdate( 'Y', $died );

		return $from === $end ? (string) $from : sprintf( '%d to %d', $from, $end );
	}

	if ( $to >= $current - 1 ) {
		/* translators: %d: first year of activity */
		return sprintf( __( '%d to present', 'daw' ), $from );
	}

	return (string) $from;
}

/**
 * What to call the value above.
 *
 * "2012 to present" is a span and reads as "Years active". A bare "2012" is not
 * a span, it is a start, and labelling it "Years active" would imply we know
 * when it stopped. The label is decided by the same facts that decide the
 * value, so it is worked out here rather than hardcoded in the template.
 */
function daw_active_years_label( $person_id ) {
	$value = daw_active_years( $person_id );

	if ( '' === $value ) {
		return '';
	}

	// A single year with nobody dead behind it is a start date, not a range.
	if ( ctype_digit( $value ) && ! (int) daw_field_raw( $person_id, 'daw_died' ) ) {
		return __( 'Active since', 'daw' );
	}

	return __( 'Years active', 'daw' );
}

/* ========================================================= worked with === */

/**
 * The people this person keeps turning up beside.
 *
 * Counted from the database's own credits, so it is a fact about our data
 * rather than an editorial opinion, and it gets better on its own every time a
 * cast block is filled in.
 */
function daw_worked_with( $person_id, $limit = 6 ) {
	$person_id = (int) $person_id;
	// The key carries the version because what counts as a shared credit just
	// changed; a day-old payload from the previous rule would answer with an
	// empty list and look like a bug.
	$key       = 'daw_flm_ww2_' . str_replace( '.', '', DAW_VERSION ) . '_' . (int) get_option( 'daw_flm_gen', 0 ) . '_' . $person_id . '_' . (int) $limit;
	$cached    = get_transient( $key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$counts = array();

	// THE UNTIERED BUCKET COUNTS HERE, AND ONLY HERE
	// ----------------------------------------------
	// Billing is a claim about how prominent someone was, so a credit with no
	// billing recorded cannot be filed under Lead or Supporting and has to wait
	// for somebody to check. Having been in the same production is not a claim
	// of that kind: it either happened or it did not, and the untiered bucket
	// is full of people we know were in the cast.
	//
	// Leaving them out meant this section was empty on every profile, because
	// almost every title the extractor recovered has its cast sitting untiered.
	// Two actors who share one production belong here; a second and a third
	// shared title move them up the list on their own.
	foreach ( daw_get_filmography( $person_id ) as $rows ) {
		foreach ( $rows as $row ) {
			foreach ( array( 'main', 'supporting', 'guest', 'untiered' ) as $tier ) {
				foreach ( daw_get_cast( $row['id'], $tier ) as $mate ) {
					$mate_id = (int) $mate['id'];
					if ( ! $mate_id || $mate_id === $person_id ) {
						continue;
					}
					$counts[ $mate_id ] = isset( $counts[ $mate_id ] ) ? $counts[ $mate_id ] + 1 : 1;
				}
			}
		}
	}

	arsort( $counts );

	$out = array();
	foreach ( array_slice( $counts, 0, (int) $limit, true ) as $id => $shared ) {
		if ( 'publish' !== get_post_status( $id ) ) {
			continue;
		}
		$out[] = array(
			'id'     => (int) $id,
			'name'   => get_the_title( $id ),
			'url'    => get_permalink( $id ),
			'shared' => (int) $shared,
		);
	}

	set_transient( $key, $out, DAY_IN_SECONDS );

	return $out;
}

/* =============================================================== backfill = */

/**
 * The index only exists for records saved since this shipped, so there has to
 * be a way to write it for the 926 that already exist. Batched, resumable, and
 * it touches nothing but one hidden meta key.
 */
add_action( 'admin_menu', 'daw_cast_index_menu' );
function daw_cast_index_menu() {
	add_submenu_page(
		'tools.php',
		__( 'Cast index', 'daw' ),
		__( 'Cast index', 'daw' ),
		'manage_options',
		'daw-cast-index',
		'daw_cast_index_page'
	);
}

function daw_cast_index_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="wrap"><h1>Cast index</h1>';
	echo '<p>Every profile page builds its filmography by reading this index backwards. Records saved from now on index themselves; run this once to cover the ones that already exist.</p>';

	if ( isset( $_POST['daw_ci_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['daw_ci_nonce'] ), 'daw_ci' ) ) {
		$done = 0;

		// FIFTY, NOT TWO HUNDRED
		// ----------------------
		// Two hundred records per run took this host past its gateway timeout
		// and returned a 504 with the batch half done. A backfill that fails
		// halfway is worse than one that takes four more clicks, because you
		// cannot tell from the screen how far it got. Fifty finishes well inside
		// the limit; the counter on this page says how many are left.
		$GLOBALS['daw_ci_batch'] = true;

		foreach ( daw_cast_index_pending( 50 ) as $pid ) {
			daw_reindex_cast( $pid );
			$done++;
		}

		unset( $GLOBALS['daw_ci_batch'] );
		daw_flush_person_cache();

		printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( sprintf( 'Indexed %d record(s).', $done ) ) );
	}

	$pending = daw_cast_index_pending_count();

	echo '<form method="post">';
	wp_nonce_field( 'daw_ci', 'daw_ci_nonce' );
	submit_button( 'Index the next 50' );
	echo '</form>';

	printf( '<p><strong>%d</strong> title(s) with a cast still need indexing.</p>', (int) $pending );
	echo '</div>';
}

/**
 * WHY RAW SQL AND NOT A META QUERY
 * --------------------------------
 * The WP_Query version of this asked for two EXISTS and one NOT EXISTS on
 * postmeta, which the query builder turns into three joins against a table with
 * hundreds of thousands of rows. On this host it did not finish before the
 * gateway gave up, so the Cast index screen simply stopped opening. Two
 * correlated subqueries answer the same question against the meta_key index and
 * return in milliseconds.
 */
function daw_cast_index_pending( $limit ) {
	global $wpdb;

	$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 WHERE p.post_type = 'post'
			   AND p.post_status = 'publish'
			   AND EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} m1
				 WHERE m1.post_id = p.ID
				   AND m1.meta_key IN ( 'daw_cast_main_person', 'daw_cast_person' )
			   )
			   AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} m2
				 WHERE m2.post_id = p.ID
				   AND m2.meta_key = %s
			   )
			 ORDER BY p.ID DESC
			 LIMIT %d",
			DAW_CAST_IDX,
			(int) $limit
		)
	);

	return array_map( 'intval', $ids );
}

/** The same question asked as a number, so the page never fetches a thousand ids just to count them. */
function daw_cast_index_pending_count() {
	global $wpdb;

	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 WHERE p.post_type = 'post'
			   AND p.post_status = 'publish'
			   AND EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} m1
				 WHERE m1.post_id = p.ID
				   AND m1.meta_key IN ( 'daw_cast_main_person', 'daw_cast_person' )
			   )
			   AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} m2
				 WHERE m2.post_id = p.ID
				   AND m2.meta_key = %s
			   )",
			DAW_CAST_IDX
		)
	);
}
