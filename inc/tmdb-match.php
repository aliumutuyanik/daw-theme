<?php
/**
 * Find the TMDB record behind each of ours.
 *
 * WHY THIS EXISTS
 * ---------------
 * Only 187 of 926 records carry a TMDB id, so "refresh every poster from TMDB"
 * is not a download job, it is a matching job. The download half already works
 * and has worked for weeks. What was missing is the answer to which TMDB entry
 * a record actually is.
 *
 * WHY IT REFUSES TO GUESS
 * -----------------------
 * A wrong match is worse than no match. A record with no poster looks unfinished
 * and is obviously unfinished. A record with the wrong drama's poster looks
 * finished and is wrong, and nobody finds out until a reader does. So this
 * accepts a match by itself only when the title matches exactly once it has been
 * normalised and the year agrees, and everything else goes to a review list with
 * its candidates and their posters, for a person to settle in one click.
 *
 * The site's rule is that nothing is invented. An automatic best guess on a
 * name like "Alice in Borderland" or an actor whose romanised name a dozen
 * people share would be inventing, so it is not on offer here.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_MATCH_SLICE  = 12;
const DAW_MATCH_BUDGET = 12;

/** The record types worth matching, and which TMDB index each one lives in. */
function daw_tmdb_kind_of( $post_id ) {
	switch ( daw_get_type( $post_id ) ) {
		case 'drama':
			return 'tv';

		case 'film':
			return 'movie';

		case 'person':
		case 'group':
			return 'person';
	}

	return '';
}

/** Our country slugs as TMDB writes them. */
function daw_tmdb_country_codes() {
	return array(
		'south-korea' => 'KR',
		'japan'       => 'JP',
		'china'       => 'CN',
		'taiwan'      => 'TW',
		'thailand'    => 'TH',
		'hong-kong'   => 'HK',
		'philippines' => 'PH',
		'singapore'   => 'SG',
	);
}

function daw_tmdb_country_code( $post_id ) {
	$terms = wp_get_object_terms( $post_id, 'dw_country', array( 'fields' => 'slugs' ) );
	$map   = daw_tmdb_country_codes();

	foreach ( (array) $terms as $slug ) {
		if ( isset( $map[ $slug ] ) ) {
			return $map[ $slug ];
		}
	}

	return '';
}

/**
 * Two titles are the same title when the things that are not the title are
 * taken off: case, accents, punctuation, a leading article and the season or
 * year somebody appended to make a slug unique.
 */
function daw_tmdb_norm( $text ) {
	$text = (string) $text;
	$text = remove_accents( $text );
	$text = strtolower( $text );
	$text = preg_replace( '/\(\d{4}\)/', ' ', $text );
	$text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
	$text = trim( preg_replace( '/^(the|a|an)\s+/', '', trim( $text ) ) );

	return preg_replace( '/\s+/', ' ', $text );
}

/* ================================================================ queue === */

function daw_tmdb_match_build_queue() {
	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'dw_type',
					'field'    => 'slug',
					'terms'    => array( 'drama', 'film', 'person', 'group' ),
				),
			),
		)
	);

	$todo = array();

	foreach ( array_map( 'intval', (array) $ids ) as $id ) {
		if ( '' !== trim( (string) daw_field_raw( $id, 'daw_tmdb_id' ) ) ) {
			continue;
		}

		$todo[] = $id;
	}

	update_option( 'daw_match_queue', $todo, false );
	update_option( 'daw_match_stats', array( 'done' => 0, 'hit' => 0, 'review' => 0, 'none' => 0, 'total' => count( $todo ) ), false );

	return $todo;
}

/* ================================================================ search == */

/**
 * Ask TMDB, score what comes back, and decide whether it is certain enough.
 *
 * Returns array( 'id' => int ) for a confident match, array( 'candidates' =>
 * array ) for something a person has to settle, or an empty array for nothing
 * found at all.
 */
function daw_tmdb_search( $post_id ) {
	$key  = daw_tmdb_key();
	$kind = daw_tmdb_kind_of( $post_id );

	if ( ! $key || ! $kind ) {
		return array();
	}

	$title = get_the_title( $post_id );
	$year  = (int) daw_field_raw( $post_id, 'daw_aired_start' );
	$year  = $year ? (int) gmdate( 'Y', $year ) : 0;
	$code  = daw_tmdb_country_code( $post_id );

	// THE STRONGEST FIELD WE HOLD
	// ---------------------------
	// Ten films are called Hide and Seek. One is called 숨바꼭질. The romanised
	// title is the ambiguous half of a record and the native title is the half
	// that identifies it, so the native title is searched first and, when it
	// matches, it settles the question on its own.
	$native = trim( (string) daw_field_raw( $post_id, 'person' === $kind ? 'daw_native_name' : 'daw_native_title' ) );

	$args = array( 'query' => $title, 'include_adult' => 'false' );

	// The year narrows a search hard, so it is asked for first and the search is
	// repeated without it if that came back empty. A drama filed under the year
	// it finished rather than the year it started would otherwise find nothing.
	$rows = daw_tmdb_search_call( $kind, $args + daw_tmdb_year_arg( $kind, $year ), $key );

	if ( ! $rows && $year ) {
		$rows = daw_tmdb_search_call( $kind, $args, $key );
	}

	if ( $native ) {
		$rows = array_merge( $rows, daw_tmdb_search_call( $kind, array( 'query' => $native, 'include_adult' => 'false' ), $key ) );
	}

	if ( ! $rows ) {
		return array();
	}

	// The two searches overlap, and the same entry arriving twice must not look
	// like two candidates agreeing with each other.
	$seen = array();
	$uniq = array();

	foreach ( $rows as $row ) {
		$rid = (int) ( $row['id'] ?? 0 );

		if ( $rid && ! isset( $seen[ $rid ] ) ) {
			$seen[ $rid ] = true;
			$uniq[]       = $row;
		}
	}

	$rows = $uniq;

	$want       = daw_tmdb_norm( $title );
	$want_naked = preg_replace( '/\s+/u', '', (string) $native );
	$scored     = array();

	foreach ( array_slice( $rows, 0, 8 ) as $row ) {
		$name  = (string) ( $row['name'] ?? $row['title'] ?? '' );
		$orig  = (string) ( $row['original_name'] ?? $row['original_title'] ?? '' );
		$date  = (string) ( $row['first_air_date'] ?? $row['release_date'] ?? '' );
		$ryear = $date ? (int) substr( $date, 0, 4 ) : 0;

		$exact = ( daw_tmdb_norm( $name ) === $want ) || ( $orig && daw_tmdb_norm( $orig ) === $want );

		// A native title match is a different and much stronger kind of exact.
		// Spaces come out of both sides because CJK titles are written with and
		// without them by different sources.
		$native_hit = false;

		if ( '' !== $want_naked ) {
			foreach ( array( $name, $orig ) as $candidate_name ) {
				if ( $candidate_name && preg_replace( '/\s+/u', '', $candidate_name ) === $want_naked ) {
					$native_hit = true;
					break;
				}
			}
		}

		$exact = $exact || $native_hit;

		$countries = (array) ( $row['origin_country'] ?? array() );

		if ( 'person' === $kind ) {
			foreach ( (array) ( $row['known_for'] ?? array() ) as $known ) {
				$countries = array_merge( $countries, (array) ( $known['origin_country'] ?? array() ) );
				if ( empty( $known['origin_country'] ) ) {
					$countries = array_merge( $countries, daw_tmdb_lang_countries( $known['original_language'] ?? '' ) );
				}
			}
		}

		// A film search result carries no origin_country, only the language it
		// was made in. That is enough to tell the Korean Hide and Seek (2013)
		// from the American one, which is all the country check is for.
		if ( ! $countries ) {
			$countries = daw_tmdb_lang_countries( $row['original_language'] ?? '' );
		}

		$score  = 0;
		$score += $exact ? 100 : 0;
		$score += $native_hit ? 80 : 0;
		$score += ( $ryear && $year && abs( $ryear - $year ) <= 1 ) ? 40 : 0;
		$score += ( $code && in_array( $code, $countries, true ) ) ? 25 : 0;
		$score += min( 10, (int) ( (float) ( $row['popularity'] ?? 0 ) ) );

		$scored[] = array(
			'id'      => (int) ( $row['id'] ?? 0 ),
			'name'    => $name,
			'orig'    => $orig,
			'year'    => $ryear,
			'img'     => (string) ( $row['poster_path'] ?? $row['profile_path'] ?? '' ),
			'country' => implode( ',', array_unique( $countries ) ),
			'exact'   => $exact,
			'native'  => $native_hit,
			'score'   => $score,
		);
	}

	usort(
		$scored,
		static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		}
	);

	$top    = $scored[0];
	$second = $scored[1] ?? null;

	// Certain enough means three things at once: the title is the same title,
	// nothing else claims the same title as loudly, and either the year agrees
	// or we never had a year to disagree with.
	$year_ok   = ! $year || ( $top['year'] && abs( $top['year'] - $year ) <= 1 );
	$alone     = ! $second || ! $second['exact'] || ( $top['score'] - $second['score'] ) >= 40;
	$confident = $top['exact'] && $year_ok && $alone && $top['id'];

	// A person is held to a higher bar. Romanised names repeat constantly and a
	// wrong portrait on a profile is the worst single error this site can make,
	// so the country has to agree as well.
	if ( 'person' === $kind && $confident && $code && false === strpos( $top['country'], $code ) ) {
		$confident = false;
	}

	// THE COUNTRY SETTLES A TITLE THAT REPEATS
	// ----------------------------------------
	// Ten films are called Hide and Seek and our records often carry no release
	// date, so "is it alone" can never be satisfied by the title. But this is an
	// archive of Korean, Japanese, Chinese, Thai, Taiwanese, Hong Kong, Filipino
	// and Singaporean work, and among those ten only one is Korean. When exactly
	// one candidate carries both the title and the country, there is nothing
	// left to be uncertain about.
	if ( ! $confident && $code ) {
		$both = array_values(
			array_filter(
				$scored,
				static function ( $c ) use ( $code ) {
					return $c['exact'] && $c['country'] && false !== strpos( $c['country'], $code );
				}
			)
		);

		if ( 1 === count( $both ) && $both[0]['id'] ) {
			$year_fits = ! $year || ! $both[0]['year'] || abs( $both[0]['year'] - $year ) <= 1;

			if ( $year_fits ) {
				$top       = $both[0];
				$confident = true;
			}
		}
	}

	// A native title or name that matches exactly, and matches only one entry,
	// outranks all of the above. It is the one piece of evidence on a record
	// that cannot mean two different works, so a year filed a season late or a
	// country TMDB has recorded differently does not get to veto it.
	if ( ! $confident && $top['native'] && $top['id'] && ( ! $second || empty( $second['native'] ) ) ) {
		$confident = true;
	}

	// The last resort before giving up on a person: TMDB keeps the Korean,
	// Japanese and Chinese spellings of a name in also_known_as, which the
	// search result does not carry. One extra call settles most of what is left.
	if ( ! $confident && 'person' === $kind && $native && $top['id'] ) {
		$aka = daw_tmdb_person_aka( $top['id'], $key );

		if ( in_array( $want_naked, $aka, true ) ) {
			$confident = true;
			$top['native'] = true;
		}
	}

	if ( $confident ) {
		return array( 'id' => $top['id'], 'row' => $top );
	}

	return array( 'candidates' => array_slice( $scored, 0, 5 ) );
}

/** The countries a TMDB original_language points at, as our country codes. */
function daw_tmdb_lang_countries( $lang ) {
	$map = array(
		'ko' => array( 'KR' ),
		'ja' => array( 'JP' ),
		'th' => array( 'TH' ),
		'tl' => array( 'PH' ),
		'zh' => array( 'CN', 'TW', 'HK' ),
		'cn' => array( 'CN', 'HK' ),
	);
	return $map[ strtolower( (string) $lang ) ] ?? array();
}

/** The other spellings TMDB holds for a person's name, spaces removed. */
function daw_tmdb_person_aka( $tmdb_id, $key ) {
	$res = wp_remote_get(
		sprintf( 'https://api.themoviedb.org/3/person/%d?api_key=%s', (int) $tmdb_id, rawurlencode( $key ) ),
		array( 'timeout' => 15, 'user-agent' => 'DiscoverAsianWorld/1.0 (+https://discoverasianworld.com)' )
	);

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	$out  = array();

	foreach ( (array) ( $body['also_known_as'] ?? array() ) as $name ) {
		$out[] = preg_replace( '/\s+/u', '', (string) $name );
	}

	return $out;
}

function daw_tmdb_year_arg( $kind, $year ) {
	if ( ! $year ) {
		return array();
	}

	if ( 'tv' === $kind ) {
		return array( 'first_air_date_year' => $year );
	}

	if ( 'movie' === $kind ) {
		return array( 'primary_release_year' => $year );
	}

	return array();
}

function daw_tmdb_search_call( $kind, $args, $key ) {
	$url = add_query_arg(
		array_map( 'rawurlencode', array_merge( $args, array( 'api_key' => $key ) ) ),
		'https://api.themoviedb.org/3/search/' . $kind
	);

	$res = wp_remote_get(
		$url,
		array(
			'timeout'    => 15,
			'user-agent' => 'DiscoverAsianWorld/1.0 (+https://discoverasianworld.com)',
		)
	);

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $res ), true );

	return is_array( $body ) && ! empty( $body['results'] ) ? $body['results'] : array();
}

/* ================================================================== run === */

add_action( 'admin_init', 'daw_tmdb_match_tick', 33 );
add_action( 'daw_match_cron', 'daw_tmdb_match_tick' );

function daw_tmdb_match_tick() {
	if ( function_exists( 'daw_is_delicate_request' ) && daw_is_delicate_request() ) {
		return;
	}

	if ( ! daw_tmdb_key() || ! get_option( 'daw_match_on' ) ) {
		return;
	}

	daw_tmdb_match_slice();

	$queue = get_option( 'daw_match_queue' );

	if ( is_array( $queue ) && $queue && ! wp_next_scheduled( 'daw_match_cron' ) ) {
		wp_schedule_single_event( time() + 60, 'daw_match_cron' );
	}
}

function daw_tmdb_match_slice() {
	$queue = get_option( 'daw_match_queue' );

	if ( ! is_array( $queue ) || ! $queue ) {
		return 0;
	}

	if ( get_transient( 'daw_match_lock' ) ) {
		return 0;
	}

	set_transient( 'daw_match_lock', 1, 120 );

	$stats = get_option( 'daw_match_stats', array() );
	$start = microtime( true );
	$n     = 0;

	while ( $queue && $n < DAW_MATCH_SLICE && ( microtime( true ) - $start ) < DAW_MATCH_BUDGET ) {
		$id  = (int) array_shift( $queue );
		$hit = daw_tmdb_search( $id );

		if ( ! empty( $hit['id'] ) ) {
			update_post_meta( $id, 'daw_tmdb_id', (int) $hit['id'] );
			update_post_meta( $id, 'daw_tmdb_match', 'auto' );
			// Kept so that an automatic decision can be looked at afterwards.
			// A matcher nobody can check is a matcher nobody should trust.
			update_post_meta( $id, '_daw_tmdb_auto', $hit['row'] ?? array() );
			delete_post_meta( $id, '_daw_tmdb_cand' );
			$stats['hit'] = (int) ( $stats['hit'] ?? 0 ) + 1;
		} elseif ( ! empty( $hit['candidates'] ) ) {
			update_post_meta( $id, '_daw_tmdb_cand', $hit['candidates'] );
			$stats['review'] = (int) ( $stats['review'] ?? 0 ) + 1;
		} else {
			update_post_meta( $id, '_daw_tmdb_cand', array() );
			$stats['none'] = (int) ( $stats['none'] ?? 0 ) + 1;
		}

		$stats['done'] = (int) ( $stats['done'] ?? 0 ) + 1;
		++$n;
	}

	update_option( 'daw_match_queue', array_values( $queue ), false );
	update_option( 'daw_match_stats', $stats, false );

	delete_transient( 'daw_match_lock' );

	return $n;
}

/* ================================================================ review == */

add_action( 'admin_menu', 'daw_tmdb_match_menu' );
function daw_tmdb_match_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: TMDB matching', 'daw' ),
		__( 'DAW: TMDB matching', 'daw' ),
		'manage_options',
		'daw-tmdb-match',
		'daw_tmdb_match_page'
	);
}

function daw_tmdb_review_ids( $limit = 20 ) {
	return get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_daw_tmdb_cand',
					'compare' => 'EXISTS',
				),
			),
		)
	);
}

function daw_tmdb_match_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$key = daw_tmdb_key();

	echo '<div class="wrap"><h1>DAW: TMDB matching</h1>';

	if ( ! $key ) {
		echo '<div class="notice notice-error"><p>No TMDB API key. Add one in Settings, Reading before starting.</p></div></div>';
		return;
	}

	if ( isset( $_POST['daw_match_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['daw_match_nonce'] ), 'daw_match' ) ) {
		if ( isset( $_POST['daw_match_start'] ) ) {
			daw_tmdb_match_build_queue();
			update_option( 'daw_match_on', 1, false );
			echo '<div class="notice notice-success"><p>Queue built. Matching runs in the background, a dozen records at a time. Leave this page open and refresh it.</p></div>';
		}

		if ( isset( $_POST['daw_match_recheck'] ) ) {
			// The rules got better after these were set aside. Putting them back
			// through is cheaper than asking a person to settle questions the
			// matcher can now answer by itself.
			$again = daw_tmdb_review_ids( -1 );

			foreach ( $again as $rid ) {
				delete_post_meta( (int) $rid, '_daw_tmdb_cand' );
			}

			update_option( 'daw_match_queue', array_map( 'intval', $again ), false );
			update_option(
				'daw_match_stats',
				array( 'done' => 0, 'hit' => 0, 'review' => 0, 'none' => 0, 'total' => count( $again ) ),
				false
			);
			update_option( 'daw_match_on', 1, false );

			printf( '<div class="notice notice-success"><p>%d record(s) put back through with the current rules.</p></div>', count( $again ) );
		}

		if ( isset( $_POST['daw_match_stop'] ) ) {
			update_option( 'daw_match_on', 0, false );
			echo '<div class="notice notice-warning"><p>Stopped. Nothing already matched is undone.</p></div>';
		}

		if ( isset( $_POST['pick'] ) && is_array( $_POST['pick'] ) ) {
			$saved = 0;

			foreach ( wp_unslash( $_POST['pick'] ) as $pid => $choice ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$pid    = (int) $pid;
				$choice = sanitize_text_field( $choice );

				if ( ! $pid || '' === $choice ) {
					continue;
				}

				if ( 'none' === $choice ) {
					delete_post_meta( $pid, '_daw_tmdb_cand' );
					update_post_meta( $pid, 'daw_tmdb_match', 'none' );
					++$saved;
					continue;
				}

				update_post_meta( $pid, 'daw_tmdb_id', (int) $choice );
				update_post_meta( $pid, 'daw_tmdb_match', 'chosen' );
				delete_post_meta( $pid, '_daw_tmdb_cand' );
				++$saved;
			}

			printf( '<div class="notice notice-success"><p>%d record(s) settled.</p></div>', (int) $saved );
		}
	}

	$stats = get_option( 'daw_match_stats', array() );
	$queue = get_option( 'daw_match_queue', array() );
	$on    = (int) get_option( 'daw_match_on' );

	echo '<form method="post">';
	wp_nonce_field( 'daw_match', 'daw_match_nonce' );

	printf(
		'<p>%s</p>',
		esc_html(
			sprintf(
				'Checked %d of %d. Matched on their own: %d. Waiting for you: %d. Nothing found: %d. Still queued: %d.',
				(int) ( $stats['done'] ?? 0 ),
				(int) ( $stats['total'] ?? 0 ),
				(int) ( $stats['hit'] ?? 0 ),
				(int) ( $stats['review'] ?? 0 ),
				(int) ( $stats['none'] ?? 0 ),
				is_array( $queue ) ? count( $queue ) : 0
			)
		)
	);

	submit_button( $on ? __( 'Restart from the beginning', 'daw' ) : __( 'Start matching', 'daw' ), 'primary', 'daw_match_start', false );
	echo ' ';
	submit_button( __( 'Stop', 'daw' ), 'secondary', 'daw_match_stop', false );
	echo ' ';
	submit_button( __( 'Re-check everything waiting for a decision', 'daw' ), 'secondary', 'daw_match_recheck', false );
	echo '</form>';

	daw_tmdb_auto_table();

	$review = daw_tmdb_review_ids( 20 );

	if ( ! $review ) {
		echo '<p>Nothing waiting for a decision.</p></div>';
		return;
	}

	echo '<hr><h2>Records that need a person</h2>';
	echo '<form method="post">';
	wp_nonce_field( 'daw_match', 'daw_match_nonce' );
	echo '<table class="widefat striped"><tbody>';

	foreach ( $review as $pid ) {
		$cands = get_post_meta( $pid, '_daw_tmdb_cand', true );
		$cands = is_array( $cands ) ? $cands : array();
		$year  = (int) daw_field_raw( $pid, 'daw_aired_start' );

		printf(
			'<tr><td style="width:240px"><strong><a href="%s">%s</a></strong><br><span class="description">%s%s</span></td><td>',
			esc_url( (string) get_edit_post_link( $pid ) ),
			esc_html( get_the_title( $pid ) ),
			esc_html( daw_get_type( $pid ) ),
			esc_html( $year ? ' · ' . gmdate( 'Y', $year ) : '' )
		);

		if ( ! $cands ) {
			echo '<em>TMDB returned nothing for this name.</em> ';
		}

		foreach ( $cands as $c ) {
			printf(
				'<label style="display:inline-block;width:150px;vertical-align:top;margin:0 10px 10px 0;text-align:center">
					<input type="radio" name="pick[%1$d]" value="%2$d"><br>
					%3$s
					<span style="display:block;font-size:12px;line-height:1.3">%4$s<br><span class="description">%5$s %6$s</span></span>
				</label>',
				(int) $pid,
				(int) $c['id'],
				$c['img']
					? '<img src="https://image.tmdb.org/t/p/w154' . esc_attr( $c['img'] ) . '" style="width:120px;height:auto;border-radius:6px">'
					: '<span style="display:inline-block;width:120px;height:180px;background:#eee;border-radius:6px"></span>',
				esc_html( $c['name'] ),
				esc_html( $c['year'] ? (string) $c['year'] : '' ),
				esc_html( $c['country'] )
			);
		}

		printf(
			'<label style="display:inline-block;vertical-align:top"><input type="radio" name="pick[%d]" value="none"> %s</label>',
			(int) $pid,
			esc_html__( 'None of these', 'daw' )
		);

		echo '</td></tr>';
	}

	echo '</tbody></table>';
	submit_button( __( 'Save these choices', 'daw' ) );
	echo '</form></div>';
}


/**
 * What the matcher decided on its own, with the picture it decided on.
 *
 * An automatic match that nobody ever looks at is a guess with better manners.
 * This is the page that makes it checkable: our title on the left, the TMDB
 * entry it was bound to on the right, poster included. Anything that looks
 * wrong is one click from being unbound.
 */
function daw_tmdb_auto_table() {
	if ( isset( $_POST['daw_undo_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['daw_undo_nonce'] ), 'daw_undo' ) ) {
		$undone = 0;

		foreach ( (array) ( $_POST['undo'] ?? array() ) as $pid ) {
			$pid = (int) $pid;

			if ( ! $pid ) {
				continue;
			}

			delete_post_meta( $pid, 'daw_tmdb_id' );
			delete_post_meta( $pid, '_daw_tmdb_auto' );
			update_post_meta( $pid, 'daw_tmdb_match', 'rejected' );
			++$undone;
		}

		printf( '<div class="notice notice-success"><p>%d automatic match(es) undone.</p></div>', (int) $undone );
	}

	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_daw_tmdb_auto',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	if ( ! $ids ) {
		return;
	}

	echo '<hr><h2>Matched on their own, most recent first</h2>';
	echo '<p class="description">Check a few. If one is wrong, tick it and undo, and it goes back to being unmatched.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'daw_undo', 'daw_undo_nonce' );
	echo '<table class="widefat striped"><thead><tr><th style="width:30px"></th><th>Our record</th><th>Bound to</th></tr></thead><tbody>';

	foreach ( $ids as $pid ) {
		$row  = get_post_meta( $pid, '_daw_tmdb_auto', true );
		$row  = is_array( $row ) ? $row : array();
		$year = (int) daw_field_raw( $pid, 'daw_aired_start' );

		printf(
			'<tr><td><input type="checkbox" name="undo[]" value="%1$d"></td>
			 <td><a href="%2$s">%3$s</a><br><span class="description">%4$s%5$s</span></td>
			 <td>%6$s <a href="https://www.themoviedb.org/%7$s/%8$d" target="_blank" rel="noopener">%9$s</a> <span class="description">%10$s %11$s</span></td></tr>',
			(int) $pid,
			esc_url( (string) get_edit_post_link( $pid ) ),
			esc_html( get_the_title( $pid ) ),
			esc_html( (string) daw_get_type( $pid ) ),
			esc_html( $year ? ' · ' . gmdate( 'Y', $year ) : '' ),
			! empty( $row['img'] )
				? '<img src="https://image.tmdb.org/t/p/w92' . esc_attr( $row['img'] ) . '" style="width:46px;height:auto;vertical-align:middle;border-radius:4px;margin-right:8px">'
				: '',
			esc_attr( (string) daw_tmdb_kind_of( $pid ) ),
			(int) ( $row['id'] ?? 0 ),
			esc_html( (string) ( $row['name'] ?? '' ) ),
			esc_html( ! empty( $row['year'] ) ? (string) $row['year'] : '' ),
			esc_html( (string) ( $row['country'] ?? '' ) )
		);
	}

	echo '</tbody></table>';
	submit_button( __( 'Undo the ticked matches', 'daw' ), 'secondary' );
	echo '</form>';
}
