<?php
/**
 * Fill the empty fields on a matched record from TMDB.
 *
 * WHY THIS IS A SEPARATE STEP
 * ---------------------------
 * Matching answers which TMDB entry a record is. This answers what that entry
 * knows that we do not. They are separate because the first one has to be right
 * before the second one is allowed to happen: filling a record from the wrong
 * TMDB entry writes wrong data into fields that look authoritative afterwards.
 *
 * WHAT IT WILL AND WILL NOT TOUCH
 * -------------------------------
 * It fills fields that are empty. It never overwrites a value somebody typed,
 * not even one that disagrees with TMDB, because an editor's decision outranks
 * a database's. Where the two disagree the difference is written down and shown
 * on the tools page, so a person can look at the short list of disagreements
 * instead of reading nine hundred records.
 *
 * It does not copy the synopsis. That text belongs to whoever wrote it, this
 * site writes its own, and a field that reads well but was lifted is the exact
 * thing the editorial policy exists to prevent.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_FILL_SLICE  = 10;
const DAW_FILL_BUDGET = 12;

/* ================================================================ queue === */

function daw_tmdb_fill_build_queue() {
	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => 'daw_tmdb_id',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	$todo = array();

	foreach ( array_map( 'intval', (array) $ids ) as $id ) {
		if ( '' !== trim( (string) daw_field_raw( $id, 'daw_tmdb_id' ) ) && daw_tmdb_kind_of( $id ) ) {
			$todo[] = $id;
		}
	}

	update_option( 'daw_fill_queue', $todo, false );
	update_option( 'daw_fill_stats', array( 'done' => 0, 'filled' => 0, 'fields' => 0, 'diff' => 0, 'total' => count( $todo ) ), false );

	return $todo;
}

/* ================================================================= call === */

function daw_tmdb_detail( $kind, $tmdb_id, $key ) {
	$res = wp_remote_get(
		sprintf( 'https://api.themoviedb.org/3/%s/%d?api_key=%s', $kind, (int) $tmdb_id, rawurlencode( $key ) ),
		array( 'timeout' => 15, 'user-agent' => 'DiscoverAsianWorld/1.0 (+https://discoverasianworld.com)' )
	);

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $res ), true );

	return is_array( $body ) ? $body : array();
}

/**
 * What TMDB holds for this record, in our field names and our formats.
 *
 * Dates become timestamps because that is what the date fields store. Gender
 * becomes our two words. Status becomes one of the three the select offers.
 * Anything TMDB left blank is left out of the array entirely rather than
 * written as an empty string, so a missing value never overwrites a real one.
 */
function daw_tmdb_fields_from( $kind, $body ) {
	$out = array();

	$date = static function ( $value ) {
		$value = trim( (string) $value );

		return ( $value && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) ? (int) strtotime( $value . ' 00:00:00 UTC' ) : 0;
	};

	if ( 'person' === $kind ) {
		$born = $date( $body['birthday'] ?? '' );
		$died = $date( $body['deathday'] ?? '' );

		if ( $born ) {
			$out['daw_born'] = $born;
		}

		if ( $died ) {
			$out['daw_died'] = $died;
		}

		if ( ! empty( $body['place_of_birth'] ) ) {
			$out['daw_birth_place'] = sanitize_text_field( (string) $body['place_of_birth'] );
		}

		$gender = (int) ( $body['gender'] ?? 0 );

		if ( 1 === $gender ) {
			$out['daw_gender'] = 'female';
		} elseif ( 2 === $gender ) {
			$out['daw_gender'] = 'male';
		}

		// The first non-latin spelling TMDB holds is the native name.
		foreach ( (array) ( $body['also_known_as'] ?? array() ) as $aka ) {
			if ( preg_match( '/[\x{3040}-\x{30ff}\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{ac00}-\x{d7af}]/u', (string) $aka ) ) {
				$out['daw_native_name'] = sanitize_text_field( (string) $aka );
				break;
			}
		}

		return $out;
	}

	$start = $date( $body['first_air_date'] ?? $body['release_date'] ?? '' );
	$end   = $date( $body['last_air_date'] ?? '' );

	if ( $start ) {
		$out['daw_aired_start'] = $start;
	}

	if ( $end ) {
		$out['daw_aired_end'] = $end;
	}

	$native = trim( (string) ( $body['original_name'] ?? $body['original_title'] ?? '' ) );
	$plain  = trim( (string) ( $body['name'] ?? $body['title'] ?? '' ) );

	// Only when it is actually a different writing system. A Korean drama whose
	// original_name TMDB recorded in English is not a native title.
	if ( $native && $native !== $plain && preg_match( '/[^\x00-\x7F]/', $native ) ) {
		$out['daw_native_title'] = sanitize_text_field( $native );
	}

	$eps = (int) ( $body['number_of_episodes'] ?? 0 );

	if ( $eps > 0 ) {
		$out['daw_episodes'] = $eps;
	}

	$runtime = (int) ( $body['runtime'] ?? 0 );

	if ( ! $runtime && ! empty( $body['episode_run_time'][0] ) ) {
		$runtime = (int) $body['episode_run_time'][0];
	}

	if ( $runtime > 0 ) {
		$out['daw_duration_min'] = $runtime;
	}

	$status = strtolower( (string) ( $body['status'] ?? '' ) );

	if ( in_array( $status, array( 'ended', 'canceled', 'cancelled', 'released' ), true ) ) {
		$out['daw_status'] = 'completed';
	} elseif ( in_array( $status, array( 'returning series', 'in production' ), true ) ) {
		$out['daw_status'] = 'ongoing';
	} elseif ( in_array( $status, array( 'planned', 'post production', 'rumored' ), true ) ) {
		$out['daw_status'] = 'upcoming';
	}

	return $out;
}

/* ================================================================== run === */

add_action( 'admin_init', 'daw_tmdb_fill_tick', 34 );
add_action( 'daw_fill_cron', 'daw_tmdb_fill_tick' );

function daw_tmdb_fill_tick() {
	if ( function_exists( 'daw_is_delicate_request' ) && daw_is_delicate_request() ) {
		return;
	}

	if ( ! daw_tmdb_key() || ! get_option( 'daw_fill_on' ) ) {
		return;
	}

	daw_tmdb_fill_slice();

	$queue = get_option( 'daw_fill_queue' );

	if ( is_array( $queue ) && $queue && ! wp_next_scheduled( 'daw_fill_cron' ) ) {
		wp_schedule_single_event( time() + 60, 'daw_fill_cron' );
	}
}

function daw_tmdb_fill_slice() {
	$queue = get_option( 'daw_fill_queue' );

	if ( ! is_array( $queue ) || ! $queue ) {
		return 0;
	}

	if ( get_transient( 'daw_fill_lock' ) ) {
		return 0;
	}

	set_transient( 'daw_fill_lock', 1, 120 );

	$key   = daw_tmdb_key();
	$stats = get_option( 'daw_fill_stats', array() );
	$start = microtime( true );
	$n     = 0;

	while ( $queue && $n < DAW_FILL_SLICE && ( microtime( true ) - $start ) < DAW_FILL_BUDGET ) {
		$id   = (int) array_shift( $queue );
		$kind = daw_tmdb_kind_of( $id );
		$tid  = (int) daw_field_raw( $id, 'daw_tmdb_id' );

		++$n;
		$stats['done'] = (int) ( $stats['done'] ?? 0 ) + 1;

		if ( ! $kind || ! $tid ) {
			continue;
		}

		// Season pages and series records with season pages are filled from
		// TMDB's season by the verification job (madde 65), not from the
		// series totals here.
		if ( function_exists( 'daw_season_api_base' ) && '' !== daw_season_api_base( $id ) ) {
			continue;
		}

		$body = daw_tmdb_detail( $kind, $tid, $key );

		if ( ! $body ) {
			continue;
		}

		$fields = daw_tmdb_fields_from( $kind, $body );
		$wrote  = 0;
		$diff   = array();

		foreach ( $fields as $field => $value ) {
			$have = get_post_meta( $id, $field, true );

			if ( '' === $have || null === $have || '0' === (string) $have ) {
				update_post_meta( $id, $field, $value );
				++$wrote;
				continue;
			}

			// A disagreement is not corrected here. It is written down.
			if ( in_array( $field, array( 'daw_aired_start', 'daw_aired_end', 'daw_born' ), true ) ) {
				if ( abs( (int) $have - (int) $value ) > 400 * DAY_IN_SECONDS ) {
					$diff[ $field ] = array( 'ours' => (int) $have, 'tmdb' => (int) $value );
				}
			} elseif ( (string) $have !== (string) $value ) {
				$diff[ $field ] = array( 'ours' => (string) $have, 'tmdb' => (string) $value );
			}
		}

		if ( $wrote ) {
			update_post_meta( $id, '_daw_fill_tmdb', array_keys( $fields ) );
			$stats['filled'] = (int) ( $stats['filled'] ?? 0 ) + 1;
			$stats['fields'] = (int) ( $stats['fields'] ?? 0 ) + $wrote;
		}

		if ( $diff ) {
			update_post_meta( $id, '_daw_tmdb_diff', $diff );
			$stats['diff'] = (int) ( $stats['diff'] ?? 0 ) + 1;
		} else {
			delete_post_meta( $id, '_daw_tmdb_diff' );
		}
	}

	update_option( 'daw_fill_queue', array_values( $queue ), false );
	update_option( 'daw_fill_stats', $stats, false );

	delete_transient( 'daw_fill_lock' );

	return $n;
}

/* ================================================================ admin === */

add_action( 'admin_menu', 'daw_tmdb_fill_menu' );
function daw_tmdb_fill_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: TMDB fields', 'daw' ),
		__( 'DAW: TMDB fields', 'daw' ),
		'manage_options',
		'daw-tmdb-fill',
		'daw_tmdb_fill_page'
	);
}

function daw_tmdb_fill_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="wrap"><h1>DAW: TMDB fields</h1>';

	if ( ! daw_tmdb_key() ) {
		echo '<div class="notice notice-error"><p>No TMDB API key.</p></div></div>';
		return;
	}

	echo '<p>Fills empty fields on records that already carry a TMDB id: release dates, episode counts, runtimes, native titles, birth dates and birthplaces. It never overwrites anything that already has a value, and it never copies a synopsis.</p>';

	if ( isset( $_POST['daw_fill_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['daw_fill_nonce'] ), 'daw_fill' ) ) {
		if ( isset( $_POST['daw_fill_start'] ) ) {
			daw_tmdb_fill_build_queue();
			update_option( 'daw_fill_on', 1, false );
			echo '<div class="notice notice-success"><p>Queue built. It runs in the background while you use the admin.</p></div>';
		}

		if ( isset( $_POST['daw_fill_stop'] ) ) {
			update_option( 'daw_fill_on', 0, false );
			echo '<div class="notice notice-warning"><p>Stopped.</p></div>';
		}
	}

	$stats = get_option( 'daw_fill_stats', array() );
	$queue = get_option( 'daw_fill_queue', array() );

	echo '<form method="post">';
	wp_nonce_field( 'daw_fill', 'daw_fill_nonce' );
	printf(
		'<p>%s</p>',
		esc_html(
			sprintf(
				'Checked %d of %d. Records filled: %d. Fields written: %d. Disagreements found: %d. Still queued: %d.',
				(int) ( $stats['done'] ?? 0 ),
				(int) ( $stats['total'] ?? 0 ),
				(int) ( $stats['filled'] ?? 0 ),
				(int) ( $stats['fields'] ?? 0 ),
				(int) ( $stats['diff'] ?? 0 ),
				is_array( $queue ) ? count( $queue ) : 0
			)
		)
	);
	submit_button( __( 'Fill empty fields', 'daw' ), 'primary', 'daw_fill_start', false );
	echo ' ';
	submit_button( __( 'Stop', 'daw' ), 'secondary', 'daw_fill_stop', false );
	echo '</form>';

	$diffs = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 60,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_daw_tmdb_diff',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	if ( ! $diffs ) {
		echo '</div>';
		return;
	}

	echo '<hr><h2>Where our record and TMDB disagree</h2>';
	echo '<p class="description">Nothing here has been changed. These are the records worth a second look, and they are a short list rather than nine hundred.</p>';
	echo '<table class="widefat striped"><thead><tr><th>Record</th><th>Field</th><th>Ours</th><th>TMDB</th></tr></thead><tbody>';

	foreach ( $diffs as $pid ) {
		$diff = get_post_meta( $pid, '_daw_tmdb_diff', true );

		foreach ( (array) $diff as $field => $pair ) {
			$fmt = static function ( $value ) use ( $field ) {
				return in_array( $field, array( 'daw_aired_start', 'daw_aired_end', 'daw_born' ), true )
					? gmdate( 'Y-m-d', (int) $value )
					: (string) $value;
			};

			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td><strong>%s</strong></td></tr>',
				esc_url( (string) get_edit_post_link( $pid ) ),
				esc_html( get_the_title( $pid ) ),
				esc_html( str_replace( 'daw_', '', $field ) ),
				esc_html( $fmt( $pair['ours'] ?? '' ) ),
				esc_html( $fmt( $pair['tmdb'] ?? '' ) )
			);
		}
	}

	echo '</tbody></table></div>';
}
