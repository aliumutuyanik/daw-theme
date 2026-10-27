<?php
/**
 * Discover Asian World — what the archive is missing, kept where it was found.
 *
 * WHY THIS EXISTS
 * ---------------
 * A person with no record on the site is a name nobody stored, so no query
 * can find it later: the absence has no row. The only moment the name exists
 * is when somebody reading a source sees it in a cast list and notices there
 * is nobody to link it to. So that is when it is written down, on the record
 * where it was found:
 *
 *   daw_missing_cast  on a title   — people in its cast with no person record
 *   daw_missing_works on a person  — their works with no title record
 *
 * Each is a list of plain lines, "Name | character | tier | episodes" and
 * "Title | year | type | TMDB id | note", written by whoever audits the
 * record. Nothing here is shown to readers.
 *
 * This file adds them up. The people list is ranked by how many titles each
 * missing person appears in, because the person who is missing from twelve
 * cast lists is the one whose record would fix twelve pages at once.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** Split a stored line into its fields. */
function daw_backlog_fields( $line ) {
	$line = trim( wp_strip_all_tags( (string) $line ) );
	return array_map( 'trim', explode( '|', $line ) );
}

/** A name folded for matching: "Kim Tae-woo" and "Kim Tae Woo" are one line. */
function daw_backlog_key( $name ) {
	$name = preg_replace( '/\s*\(.*?\)\s*/u', ' ', (string) $name );
	$name = mb_strtolower( preg_replace( '/[\s\-‐‑·.]+/u', ' ', $name ) );
	return trim( $name );
}

/**
 * Every missing person, ranked.
 *
 * @return array[] name, key, count, titles[{id,title,role}]
 */
function daw_backlog_people() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'daw_missing_cast'" ); // phpcs:ignore WordPress.DB

	$people = array();
	foreach ( (array) $rows as $r ) {
		if ( 'publish' !== get_post_status( (int) $r->post_id ) ) {
			continue;
		}
		foreach ( (array) maybe_unserialize( $r->meta_value ) as $line ) {
			$f   = daw_backlog_fields( $line );
			$key = daw_backlog_key( $f[0] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $people[ $key ] ) ) {
				$people[ $key ] = array( 'name' => $f[0], 'key' => $key, 'count' => 0, 'titles' => array() );
			}
			$people[ $key ]['count']++;
			$people[ $key ]['titles'][] = array(
				'id'    => (int) $r->post_id,
				'title' => get_the_title( (int) $r->post_id ),
				'role'  => implode( ' · ', array_slice( $f, 1 ) ),
			);
		}
	}

	// Directors, screenwriters and original authors TMDB lists for our titles
	// (coverage.php keeps them in _daw_crew) are people worth a record too: a
	// director's page gathers every work of theirs on the site.
	$jobs_tr = array( 'Director' => 'Yönetmen', 'Screenwriter' => 'Senarist', 'Original author' => 'Orijinal eser', 'Producer' => 'Yapımcı' );
	$crew    = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_daw_crew'" ); // phpcs:ignore WordPress.DB
	foreach ( (array) $crew as $r ) {
		if ( 'publish' !== get_post_status( (int) $r->post_id ) ) {
			continue;
		}
		foreach ( (array) maybe_unserialize( $r->meta_value ) as $cm ) {
			if ( ! is_array( $cm ) || ! empty( $cm['post'] ) ) {
				continue;
			}
			$key = daw_backlog_key( (string) ( $cm['name'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $people[ $key ] ) ) {
				$people[ $key ] = array( 'name' => (string) $cm['name'], 'key' => $key, 'count' => 0, 'titles' => array() );
			}
			$people[ $key ]['count']++;
			$people[ $key ]['crew']     = true;
			$people[ $key ]['titles'][] = array(
				'id'    => (int) $r->post_id,
				'title' => get_the_title( (int) $r->post_id ),
				'role'  => $jobs_tr[ $cm['job'] ?? '' ] ?? (string) ( $cm['job'] ?? '' ),
			);
		}
	}

	uasort( $people, static fn( $a, $b ) => $b['count'] <=> $a['count'] ?: strcmp( $a['name'], $b['name'] ) );
	$memo = array_values( $people );
	return $memo;
}

/** Every person with works missing from the site. */
function daw_backlog_works() {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'daw_missing_works'" ); // phpcs:ignore WordPress.DB
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$items = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) maybe_unserialize( $r->meta_value ) ) ) ) );
		if ( ! $items ) {
			continue;
		}
		$out[] = array(
			'id'    => (int) $r->post_id,
			'name'  => get_the_title( (int) $r->post_id ),
			'items' => $items,
		);
	}
	usort( $out, static fn( $a, $b ) => count( $b['items'] ) <=> count( $a['items'] ) );
	return $out;
}

add_action( 'admin_menu', 'daw_backlog_menu' );
function daw_backlog_menu() {
	add_submenu_page( 'tools.php', __( 'DAW: Backlog', 'daw' ), __( 'DAW: Backlog', 'daw' ), 'manage_options', 'daw-backlog', 'daw_backlog_page' );
}

function daw_backlog_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$people = daw_backlog_people();
	$works  = daw_backlog_works();

	echo '<div class="wrap"><h1>Backlog: what the archive is missing</h1>';
	echo '<p style="max-width:56em">Written down by the audits at the moment each gap was found. People are ranked by how many cast lists they are missing from: a record for the person at the top fixes the most pages at once.</p>';

	printf( '<h2>People with no record (%d)</h2>', count( $people ) );
	echo '<table class="widefat striped"><thead><tr><th style="width:4em">Titles</th><th>Name</th><th>Where they appear</th></tr></thead><tbody>';
	foreach ( array_slice( $people, 0, 300 ) as $p ) {
		$t = array();
		foreach ( $p['titles'] as $row ) {
			$t[] = sprintf( '<a href="%s">%s</a>%s', esc_url( get_edit_post_link( $row['id'] ) ), esc_html( $row['title'] ), $row['role'] ? ' <span style="color:#888">' . esc_html( $row['role'] ) . '</span>' : '' );
		}
		printf( '<tr><td><strong>%d</strong></td><td>%s</td><td style="font-size:12px">%s</td></tr>', (int) $p['count'], esc_html( $p['name'] ), implode( ' · ', $t ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</tbody></table>';

	printf( '<h2>Works with no record (%d people)</h2>', count( $works ) );
	foreach ( $works as $w ) {
		printf( '<h3 style="margin-bottom:.3em"><a href="%s">%s</a></h3><ul style="margin-top:0">', esc_url( get_edit_post_link( $w['id'] ) ), esc_html( $w['name'] ) );
		foreach ( $w['items'] as $it ) {
			echo '<li>' . esc_html( str_replace( '|', ' · ', $it ) ) . '</li>';
		}
		echo '</ul>';
	}
	echo '</div>';
}
