<?php
/**
 * Default titles and descriptions.
 *
 * WHY GENERATE THEM
 * -----------------
 * 926 records cannot each be given a hand-written title tag before launch, and
 * the ones that go out with the bare post title throw
 * away the two things a searcher scans for: the year, and what they will find
 * on the page. So every record gets a computed title and description built from
 * the fields it already carries.
 *
 * A generated value is a floor, not a ceiling. The moment an editor types a
 * title into Rank Math, that wins and this stops applying to that record. The
 * generator exists to make the worst page on the site decent, not to stop the
 * best pages from being better.
 *
 * WHAT GOES IN A TITLE
 * --------------------
 * Name, year, and the three things people search alongside a drama: cast,
 * episodes, where to watch. "Pinocchio (2014) Cast, Episodes and Where to
 * Watch" answers a query; "Pinocchio" competes with a wooden puppet.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'daw_seo_title', 'daw_default_seo_title', 20 );
function daw_default_seo_title( $title ) {
	if ( ! is_singular( 'post' ) ) {
		return $title;
	}

	$post_id = get_the_ID();
	if ( daw_has_manual_seo( $post_id, 'rank_math_title' ) ) {
		return $title;
	}

	$generated = daw_generate_seo_title( $post_id );

	return $generated ? $generated : $title;
}

add_filter( 'daw_seo_description', 'daw_default_seo_description', 20 );
function daw_default_seo_description( $description ) {
	if ( ! is_singular( 'post' ) ) {
		return $description;
	}

	$post_id = get_the_ID();
	if ( daw_has_manual_seo( $post_id, 'rank_math_description' ) ) {
		return $description;
	}

	$generated = daw_generate_seo_description( $post_id );

	return $generated ? $generated : $description;
}

function daw_has_manual_seo( $post_id, $key ) {
	return '' !== trim( (string) get_post_meta( $post_id, $key, true ) );
}

/* ------------------------------------------------------------------ labels */

/**
 * What a reader calls this kind of record, in the words they search with.
 *
 * "K-Drama" is not decoration. It is the highest-volume modifier in this
 * subject and the one that tells a searcher, in the result list, that they
 * have found the right kind of page. Films behave differently: "Korean movie"
 * outperforms "K-movie", so the two are not built from one pattern.
 */
function daw_seo_kind_label( $post_id, $type ) {
	$country = daw_seo_first_term_name( $post_id, 'dw_country' );

	$drama = array(
		'South Korea' => 'K-Drama',
		'Japan'       => 'J-Drama',
		'China'       => 'C-Drama',
		'Thailand'    => 'Thai Drama',
		'Taiwan'      => 'Taiwanese Drama',
		'Philippines' => 'Filipino Drama',
		'Hong Kong'   => 'Hong Kong Drama',
		'Singapore'   => 'Singapore Drama',
	);
	$adjective = array(
		'South Korea' => 'Korean',
		'Japan'       => 'Japanese',
		'China'       => 'Chinese',
		'Thailand'    => 'Thai',
		'Taiwan'      => 'Taiwanese',
		'Philippines' => 'Filipino',
		'Hong Kong'   => 'Hong Kong',
		'Singapore'   => 'Singaporean',
	);

	if ( 'drama' === $type ) {
		return isset( $drama[ $country ] ) ? $drama[ $country ] : 'Drama';
	}
	if ( 'film' === $type ) {
		return isset( $adjective[ $country ] ) ? $adjective[ $country ] . ' Movie' : 'Movie';
	}
	if ( 'variety' === $type ) {
		return isset( $adjective[ $country ] ) ? $adjective[ $country ] . ' Variety Show' : 'Variety Show';
	}

	return isset( $adjective[ $country ] ) ? $adjective[ $country ] : '';
}

/**
 * The same label as it belongs inside a sentence.
 *
 * A title is a label and takes title case; a description is prose and takes
 * sentence case. Lowercasing the title label wholesale produced "k-drama",
 * which is neither.
 */
function daw_seo_kind_phrase( $post_id, $type ) {
	$label = daw_seo_kind_label( $post_id, $type );

	if ( '' === $label ) {
		return '';
	}

	// Only the noun drops to lower case. "K", "Korean" and "Thai" are proper
	// and stay as they are, which is why this is a word list and not strtolower.
	return str_replace(
		array( 'Drama', 'Movie', 'Variety Show' ),
		array( 'drama', 'movie', 'variety show' ),
		$label
	);
}

/** The nationality-and-job phrase a person is searched by: "Korean actor". */
function daw_seo_person_kind( $post_id ) {
	$country = daw_seo_first_term_name( $post_id, 'dw_country' );
	$map     = array(
		'South Korea' => 'Korean',
		'Japan'       => 'Japanese',
		'China'       => 'Chinese',
		'Thailand'    => 'Thai',
		'Taiwan'      => 'Taiwanese',
		'Philippines' => 'Filipino',
	);
	$adj = isset( $map[ $country ] ) ? $map[ $country ] : '';

	$job    = wp_strip_all_tags( html_entity_decode( (string) daw_field_raw( $post_id, 'daw_occupation' ), ENT_QUOTES ) );
	$job    = strtolower( trim( $job ) );
	$gender = (string) daw_field_raw( $post_id, 'daw_gender' );

	if ( '' !== $job ) {
		// "Actor and model" is accurate on the page and clumsy in a title, so
		// the first job wins. Splitting on the conjunction rather than on the
		// first space keeps "voice actor" intact.
		$parts = preg_split( '/\s*(?:\band\b|&|,|\/|\xc2\xb7)\s*/u', $job );
		$job   = trim( (string) $parts[0] );
	} else {
		// Every record of this type is here for screen work, so "actor" is a
		// description rather than a guess; the gendered form is only used where
		// the record actually holds a gender.
		$job = ( 'female' === $gender ) ? 'actress' : 'actor';
	}

	return trim( $adj . ' ' . $job );
}

/**
 * "a" or "an", by the sound the next word starts with.
 *
 * The description read "is a 8-episode", "is a 11-episode" and "is a MBC
 * drama" on real records. English picks the article by sound, so a number is
 * read the way it is spoken (eight, eleven, eighteen, eighty) and a short
 * capitalised network is read letter by letter (an M-B-C, a K-B-S).
 */
function daw_seo_article( $phrase ) {
	$word = (string) strtok( trim( (string) $phrase ), ' -' );

	if ( '' === $word ) {
		return __( 'a', 'daw' );
	}

	if ( preg_match( '/^\d+/', $word, $m ) ) {
		$n  = $m[0];
		$an = '8' === $n[0] || in_array( (int) $n, array( 11, 18 ), true )
			|| ( strlen( $n ) % 3 === 2 && in_array( (int) substr( $n, 0, 2 ), array( 11, 18 ), true ) );
	} elseif ( preg_match( '/^[A-Z]{2,5}$/', $word ) ) {
		$an = false !== strpos( 'AEFHILMNORSX', $word[0] );
	} else {
		$an = (bool) preg_match( '/^[aeiou]/i', $word );
	}

	return $an ? __( 'an', 'daw' ) : __( 'a', 'daw' );
}

/* ------------------------------------------------------------------ titles */

/**
 * @return string  Title only. The site name is not appended here; whether one
 *                 belongs in the tag is a Rank Math setting, and adding it in
 *                 two places is how a title ends up saying it twice.
 */
function daw_generate_seo_title( $post_id ) {
	$type = daw_get_type( $post_id );
	$name = get_the_title( $post_id );
	$year = daw_seo_year( $post_id );
	$kind = daw_seo_kind_label( $post_id, $type );

	// Each type offers the same title in decreasing length. The first one that
	// fits Google's rendered width is used, so a long name loses the promise
	// rather than being cut off mid-word by the search engine.
	// A title that already ends in its year ("The Beauty Inside (2015)") does not get it twice.
	$stem = ( $year && ! preg_match( '/\((19|20)\d\d\)\s*$/', $name ) ) ? sprintf( '%s (%s)', $name, $year ) : $name;
	$head = $kind ? sprintf( '%s %s', $stem, $kind ) : $stem;
	// "Where to Watch" is promised only when the page has it.
	$can_watch = daw_get_watch_links( $post_id ) || ( function_exists( 'daw_wp_get' ) && daw_wp_get( $post_id ) );

	switch ( $type ) {
		case 'drama':
			$ladder = array(
				$can_watch ? $head . ': ' . __( 'Cast, Episodes, Where to Watch', 'daw' ) : '',
				$head . ': ' . __( 'Cast and Episode Guide', 'daw' ),
				$head . ': ' . __( 'Cast', 'daw' ),
				$head,
				$stem,
				$name,
			);
			break;

		case 'film':
			$ladder = array(
				$can_watch ? $head . ': ' . __( 'Cast, Story, Where to Watch', 'daw' ) : '',
				$head . ': ' . __( 'Cast and Story', 'daw' ),
				$head,
				$stem,
				$name,
			);
			break;

		case 'variety':
			$ladder = array(
				$head . ': ' . __( 'Cast, Episodes and Guests', 'daw' ),
				$head . ': ' . __( 'Cast and Episodes', 'daw' ),
				$head,
				$stem,
				$name,
			);
			break;

		case 'person':
			$ladder = daw_seo_person_title_ladder( $post_id, $name );
			break;

		case 'group':
			$ladder = array(
				$name . ': ' . __( 'Members, Discography and Profile', 'daw' ),
				$name . ': ' . __( 'Members and Discography', 'daw' ),
				$name . ': ' . __( 'Profile', 'daw' ),
				$name,
			);
			break;

		case 'news':
			// A headline is already the query. Anything appended to it competes
			// with the words the story is actually about.
			return $name;

		default:
			return '';
	}

	foreach ( $ladder as $candidate ) {
		if ( '' !== $candidate && mb_strlen( $candidate ) <= 60 ) {
			return $candidate;
		}
	}

	return $name;
}

/**
 * A person's title is chosen by what we actually hold, not by a template.
 *
 * Age, height and dating history are the three modifiers people type after an
 * actor's name, by a wide margin. But a dating promise on a record with no
 * relationship data is a promise the page does not keep, and on someone who has
 * died it is worse than that. So the record decides which of its own angles the
 * title is allowed to advertise.
 */
function daw_seo_person_title_ladder( $post_id, $name ) {
	$died = (int) daw_field_raw( $post_id, 'daw_died' );

	if ( $died ) {
		return array(
			$name . ': ' . __( 'Life, Career and Full Filmography', 'daw' ),
			$name . ': ' . __( 'Life and Filmography', 'daw' ),
			$name,
		);
	}

	$has_love = '' !== trim( (string) daw_field_raw( $post_id, 'daw_relationships' ) )
		|| '' !== trim( (string) daw_field_raw( $post_id, 'daw_partner' ) )
		|| '' !== trim( (string) daw_field_raw( $post_id, 'daw_pairings' ) );

	if ( $has_love ) {
		return array(
			$name . ': ' . __( 'Age, Height, Dramas and Dating History', 'daw' ),
			$name . ': ' . __( 'Age, Height and Dating History', 'daw' ),
			$name . ': ' . __( 'Age, Height and Dramas', 'daw' ),
			$name,
		);
	}

	$kind = daw_seo_person_kind( $post_id );
	$kind = $kind ? ucwords( $kind ) : '';

	return array_values(
		array_filter(
			array(
				$kind ? $name . ': ' . sprintf( /* translators: %s: e.g. "Korean actor" */ __( '%s, Age, Height and Dramas', 'daw' ), $kind ) : '',
				$name . ': ' . __( 'Age, Height and Full Filmography', 'daw' ),
				$name . ': ' . __( 'Profile and Filmography', 'daw' ),
				$name,
			)
		)
	);
}

/* ------------------------------------------------------------ descriptions */

/**
 * Two sentences. The first says what the thing is, in the words someone would
 * use out loud. The second is a promise about the page, and every item in it is
 * something the template will actually render.
 */
function daw_generate_seo_description( $post_id ) {
	$type = daw_get_type( $post_id );

	if ( 'person' === $type ) {
		return daw_seo_person_description( $post_id );
	}

	if ( ! in_array( $type, array( 'drama', 'film', 'variety' ), true ) ) {
		return '';
	}

	$name    = get_the_title( $post_id );
	$native  = trim( (string) daw_field_raw( $post_id, 'daw_native_title' ) );
	$year    = daw_seo_year( $post_id );
	$eps     = (int) daw_field_raw( $post_id, 'daw_episodes' );
	$network = daw_seo_first_term_name( $post_id, 'dw_network' );
	$kind    = daw_seo_kind_phrase( $post_id, $type );
	$leads   = daw_seo_cast_names( $post_id, 2 );

	$what = array_filter(
		array(
			( $eps && 'film' !== $type ) ? sprintf( /* translators: %d: episode count */ __( '%d-episode', 'daw' ), $eps ) : '',
			$network,
			$kind,
			$year ? sprintf( /* translators: %s: year */ __( 'from %s', 'daw' ), $year ) : '',
		)
	);

	$what_text = implode( ' ', $what );

	$lead = sprintf(
		/* translators: 1: title, 2: native title in brackets or empty, 3: "a" or "an", 4: what the work is */
		__( '%1$s%2$s is %3$s %4$s', 'daw' ),
		$name,
		$native ? sprintf( ' (%s)', $native ) : '',
		daw_seo_article( $what_text ),
		$what_text
	);

	if ( $leads ) {
		/* translators: %s: one or two actor names */
		$lead .= sprintf( __( ' starring %s', 'daw' ), implode( __( ' and ', 'daw' ), $leads ) );
	}

	$lead .= '.';

	$has = array();
	if ( daw_get_cast( $post_id, 'main' ) ) {
		$has[] = __( 'full cast', 'daw' );
	}
	if ( 'film' !== $type && daw_get_episodes( $post_id ) ) {
		$has[] = __( 'episode guide', 'daw' );
	}
	if ( daw_get_ost( $post_id ) ) {
		$has[] = __( 'soundtrack', 'daw' );
	}
	if ( daw_get_watch_links( $post_id ) || ( function_exists( 'daw_wp_get' ) && daw_wp_get( $post_id ) ) ) {
		$has[] = __( 'where to watch it legally', 'daw' );
	}

	return daw_seo_fit( $lead, $has, 160 );
}

function daw_seo_person_description( $post_id ) {
	$name   = get_the_title( $post_id );
	$native = trim( (string) daw_field_raw( $post_id, 'daw_native_name' ) );
	$kind   = daw_seo_person_kind( $post_id );
	$born   = (int) daw_field_raw( $post_id, 'daw_born' );
	$died   = (int) daw_field_raw( $post_id, 'daw_died' );
	$height = (int) daw_field_raw( $post_id, 'daw_height_cm' );
	$agency = trim( wp_strip_all_tags( (string) daw_field_raw( $post_id, 'daw_agency' ) ) );
	$film   = function_exists( 'daw_get_filmography' ) ? daw_get_filmography( $post_id ) : array();

	// "IS A ACTOR" (1.75.46)
	// The article was hard-coded, so every "actor", "actress" and "idol"
	// record read "is a actor" in Google (Keagan Kang, 8 Oct 2026). It is
	// chosen by sound now, like the title description. And a record with no
	// occupation and no screen credit gets no job at all: "actor" there was
	// a guess about a singer or a writer.
	$credits = 0;
	foreach ( array( 'lead', 'supporting', 'guest', 'other' ) as $b ) {
		$credits += count( (array) ( $film[ $b ] ?? array() ) );
	}
	if ( '' === trim( (string) daw_field_raw( $post_id, 'daw_occupation' ) ) && ! $credits ) {
		$kind = '';
	}

	$who = $name . ( $native ? sprintf( ' (%s)', $native ) : '' );
	if ( '' !== $kind ) {
		$lead = sprintf(
			/* translators: 1: name with native name, 2: "a" or "an", 3: e.g. "Korean actor" */
			$died ? __( '%1$s was %2$s %3$s', 'daw' ) : __( '%1$s is %2$s %3$s', 'daw' ),
			$who,
			daw_seo_article( $kind ),
			$kind
		);
		if ( $born ) {
			/* translators: %s: date of birth */
			$lead .= sprintf( __( ' born %s', 'daw' ), gmdate( 'j F Y', $born ) );
		}
	} elseif ( $born ) {
		/* translators: 1: name with native name, 2: date of birth */
		$lead = sprintf( __( '%1$s was born on %2$s', 'daw' ), $who, gmdate( 'j F Y', $born ) );
	} else {
		$lead = $who;
	}
	$lead .= '.';

	$facts = array_filter(
		array(
			$height ? sprintf( /* translators: %d: height in centimetres */ __( '%d cm', 'daw' ), $height ) : '',
			$agency,
		)
	);
	if ( $facts ) {
		$lead .= ' ' . implode( ', ', $facts ) . '.';
	}

	// A DESCRIPTION SHOULD USE THE SPACE IT HAS (1.75.46)
	// Google shows about 155 to 160 characters, and most profiles used 60 of
	// them. The works a person is credited with on this site are facts the
	// page shows, so they fill the room: lead roles first, newest first,
	// one title at a time while the sentence still fits.
	$works = array();
	foreach ( array( 'lead', 'supporting', 'other', 'guest' ) as $b ) {
		$rows = array_values( (array) ( $film[ $b ] ?? array() ) );
		usort(
			$rows,
			static function ( $x, $y ) {
				return (int) ( $y['year'] ?? 0 ) <=> (int) ( $x['year'] ?? 0 );
			}
		);
		foreach ( $rows as $row ) {
			$t = wp_strip_all_tags( html_entity_decode( (string) ( $row['title'] ?? '' ), ENT_QUOTES ) );
			if ( '' !== $t && ! in_array( $t, $works, true ) ) {
				$works[] = (int) ( $row['year'] ?? 0 ) ? sprintf( '%s (%d)', $t, (int) $row['year'] ) : $t;
			}
		}
	}
	$known = '';
	foreach ( $works as $i => $w ) {
		$try = array_slice( $works, 0, $i + 1 );
		$txt = count( $try ) > 1
			? implode( ', ', array_slice( $try, 0, -1 ) ) . __( ' and ', 'daw' ) . end( $try )
			: $try[0];
		/* translators: %s: list of titles */
		$line = sprintf( __( ' Credits include %s.', 'daw' ), $txt );
		if ( mb_strlen( $lead . $line ) > 160 || $i >= 3 ) {
			break;
		}
		$known = $line;
	}
	$lead .= $known;

	$has = array();
	if ( $credits ) {
		$has[] = __( 'full filmography', 'daw' );
	}
	if ( function_exists( 'daw_get_person_awards' ) && daw_get_person_awards( $post_id ) ) {
		$has[] = __( 'awards', 'daw' );
	}
	if ( '' !== trim( (string) daw_field_raw( $post_id, 'daw_mil_status' ) ) ) {
		$has[] = __( 'military service', 'daw' );
	}
	if ( '' !== trim( (string) daw_field_raw( $post_id, 'daw_relationships' ) ) ) {
		$has[] = __( 'dating history', 'daw' );
	}

	return daw_seo_fit( $lead, $has, 160 );
}

/**
 * Join the promise to the lead and keep the result inside what Google renders.
 *
 * The promise is what gets shortened, item by item from the end, because the
 * first sentence identifies the record and the second only lists what is on
 * the page. A truncated list is still true; a truncated identity is not.
 */
function daw_seo_fit( $lead, array $has, $limit = 155 ) {
	while ( $has ) {
		// Sentence case belongs to the sentence, not to the item. Hard-coding
		// the capital on the first item meant that when that item was dropped
		// for a record that does not have it, the sentence began lower case:
		// "183 cm, Man of Creation. awards, military service."
		$items    = array_values( $has );
		$items[0] = function_exists( 'mb_strtoupper' )
			? mb_strtoupper( mb_substr( $items[0], 0, 1 ) ) . mb_substr( $items[0], 1 )
			: ucfirst( $items[0] );
		$tail     = ' ' . implode( ', ', $items ) . '.';
		if ( mb_strlen( $lead . $tail ) <= $limit ) {
			return $lead . $tail;
		}
		array_pop( $has );
	}

	return mb_strlen( $lead ) > $limit ? rtrim( mb_substr( $lead, 0, $limit - 1 ) ) . '…' : $lead;
}

function daw_seo_year( $post_id ) {
	$start = (int) daw_field_raw( $post_id, 'daw_aired_start' );

	return $start ? gmdate( 'Y', $start ) : '';
}

function daw_seo_first_term_name( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );

	return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
}

function daw_seo_cast_names( $post_id, $limit = 2 ) {
	$out = array();

	foreach ( daw_get_cast( $post_id, 'main' ) as $row ) {
		$name = get_the_title( $row['id'] );
		if ( $name ) {
			$out[] = $name;
		}
		if ( count( $out ) >= $limit ) {
			break;
		}
	}

	return $out;
}
