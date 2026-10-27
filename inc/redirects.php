<?php
/**
 * Permanent redirects for merged and retired urls.
 *
 * WHY THIS LIVES IN THE PLUGIN
 * ----------------------------
 * When two pages turn out to be the same story, the fix is to write one good
 * page and send the other one to it for ever. That redirect is part of the
 * site's own history, not a setting in a plugin someone might deactivate, so it
 * is kept here, in the same artefact as the templates and the field schema, and
 * it travels with the site.
 *
 * WHY IT RUNS ON 404 AND NOT ON EVERY REQUEST
 * -------------------------------------------
 * The retired post is trashed, so WordPress has already decided the url is a
 * 404 by the time this runs. Hooking there means the lookup costs nothing on
 * the millions of requests that resolve normally, and it means a url that later
 * gets a real page again stops redirecting on its own, because the 404 never
 * happens. A map checked before the main query would have to be consulted every
 * time and would quietly shadow live pages.
 *
 * WHAT IS STORED
 * --------------
 * daw_redirects is a flat array of source path => target path, both rooted and
 * trailing-slashed. Query strings and fragments on the incoming request are
 * dropped: a merged page's ?utm_source belongs to the old page, not the new one.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_REDIRECTS_OPTION = 'daw_redirects';

/** @return array source path => target path */
function daw_get_redirects() {
	$map = get_option( DAW_REDIRECTS_OPTION, array() );

	return is_array( $map ) ? $map : array();
}

/**
 * Record one permanent move. Both paths are normalised so that a trailing
 * slash, or its absence, cannot produce a redirect that never fires.
 *
 * @param string $from Path or full url that should stop existing.
 * @param string $to   Path or full url it becomes.
 * @return bool
 */
function daw_add_redirect( $from, $to ) {
	$from = daw_normalise_path( $from );
	$to   = daw_normalise_path( $to );

	if ( '' === $from || '' === $to || $from === $to ) {
		return false;
	}

	$map = daw_get_redirects();

	// A chain is a lost redirect. If the new target was itself moved once,
	// point straight at wherever it ended up.
	$seen = array();
	while ( isset( $map[ $to ] ) && ! isset( $seen[ $to ] ) ) {
		$seen[ $to ] = true;
		$to          = $map[ $to ];
	}

	// Anything that used to point at the retired url now points past it.
	foreach ( $map as $src => $dst ) {
		if ( $dst === $from ) {
			$map[ $src ] = $to;
		}
	}

	$map[ $from ] = $to;

	return update_option( DAW_REDIRECTS_OPTION, $map, false );
}

/** '/foo' or 'https://site/foo?x=1' => '/foo/' */
function daw_normalise_path( $url ) {
	$url  = trim( (string) $url );
	$path = wp_parse_url( $url, PHP_URL_PATH );

	if ( null === $path ) {
		$path = $url;
	}

	$path = '/' . ltrim( (string) $path, '/' );
	$path = strtok( $path, '?' );
	$path = strtok( $path, '#' );

	if ( '/' !== $path ) {
		$path = untrailingslashit( $path ) . '/';
	}

	return $path;
}

add_action( 'template_redirect', 'daw_serve_redirects', 1 );
function daw_serve_redirects() {
	if ( ! is_404() || is_admin() ) {
		return;
	}

	$map = daw_get_redirects();
	if ( ! $map ) {
		return;
	}

	$path = daw_normalise_path( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	if ( ! isset( $map[ $path ] ) ) {
		return;
	}

	wp_safe_redirect( home_url( $map[ $path ] ), 301 );
	exit;
}

/**
 * Merge one record into another: record the move, then take the duplicate out
 * of circulation.
 *
 * Trashed rather than deleted, because a merge is a judgement call and the
 * original text is the only evidence of what was merged. Trashing also removes
 * it from every listing, feed and sitemap without a single query having to know
 * this feature exists.
 *
 * @param int $from_id The duplicate.
 * @param int $to_id   The page that survives.
 * @return bool
 */
function daw_merge_records( $from_id, $to_id ) {
	$from = get_post( (int) $from_id );
	$to   = get_post( (int) $to_id );

	if ( ! $from || ! $to || $from->ID === $to->ID ) {
		return false;
	}

	$ok = daw_add_redirect( get_permalink( $from ), get_permalink( $to ) );

	if ( ! $ok ) {
		return false;
	}

	// Keep a note on the survivor of what it absorbed, so the next person to
	// look at this page can tell that the merge was deliberate.
	add_post_meta( $to->ID, '_daw_merged_from', (int) $from->ID );

	wp_trash_post( $from->ID );

	return true;
}

/* ============================================================== retired === */

/**
 * Pages taken down on purpose, and where their readers go instead.
 *
 * WHY A LIST IN CODE AND NOT A CLICK IN THE ADMIN
 * -----------------------------------------------
 * A retirement is a decision with a reason, and the reason belongs next to it.
 * A row in an options table says a url moved; this says why it moved, survives
 * a database restore, and applies itself on a fresh install of the site.
 *
 * Keyed by the slug of the page that goes, pointing at the slug that stays.
 */
function daw_retired_pages() {
	return array(
		// TEN CHILDHOOD PHOTOGRAPHS OF IDENTIFIABLE CHILDREN
		// --------------------------------------------------
		// The "baby versus now" quiz was built on ten childhood photographs of
		// named actors, with no source recorded for any of them and no way to
		// confirm that the child in the picture is the person the caption
		// claims. The site's own copyright page promises one portrait per
		// person and nothing else, and its own rule is that an unconfirmed
		// claim about someone's private life is not published. The quiz was
		// both of those things at once, so it goes, and its readers land on the
		// quizzes that are left.
		'korean-actor-baby-vs-now-part-1' => 'quizzes',
	);
}

add_action( 'init', 'daw_retire_pages', 28 );
function daw_retire_pages() {
	$run = '2026-09-18-a';

	if ( get_option( 'daw_retired_run' ) === $run ) {
		return;
	}

	update_option( 'daw_retired_run', $run, false );

	foreach ( daw_retired_pages() as $from_slug => $to_slug ) {
		$from = get_page_by_path( $from_slug, OBJECT, array( 'post', 'page' ) );
		$to   = get_page_by_path( $to_slug, OBJECT, array( 'post', 'page' ) );

		if ( ! $from || ! $to || 'trash' === $from->post_status ) {
			continue;
		}

		daw_merge_records( $from->ID, $to->ID );
	}
}

/* ======================================== owner decisions on Rank Math 301s === */

/**
 * Turn off the Rank Math redirects the owner decided against.
 *
 * Two pages had been 301'd into their neighbours on 17 September: the Chuseok
 * dates page (9599) into the Chuseok guide (11248), and the soju bomb page
 * (7250) into the soju page (7249). The audit then rewrote both so each
 * answers a different search, and on 23 September the owner decided both
 * should stand as pages. While the redirects were active the new pages could
 * not be seen at all.
 *
 * They are set to inactive, not deleted: Rank Math's own screen can switch
 * them back on in one click. Runs once, and only touches redirects whose
 * source is one of these two pages' own paths.
 */
add_action( 'admin_init', 'daw_rm_redirects_off_v1', 30 );
function daw_rm_redirects_off_v1() {
	if ( get_option( 'daw_rm_redirects_off_v1' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'rank_math_redirections';
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB
		update_option( 'daw_rm_redirects_off_v1', array( 'when' => time(), 'note' => 'no Rank Math table' ), false );
		return;
	}

	$paths = array();
	foreach ( array( 9599, 7250 ) as $pid ) {
		$link = get_permalink( $pid );
		if ( $link ) {
			$paths[] = trim( (string) wp_parse_url( $link, PHP_URL_PATH ), '/' );
		}
	}

	$done = array();
	$rows = $wpdb->get_results( "SELECT id, sources FROM {$table} WHERE status = 'active'" ); // phpcs:ignore WordPress.DB
	foreach ( (array) $rows as $r ) {
		$sources = maybe_unserialize( $r->sources );
		foreach ( (array) $sources as $src ) {
			$pattern = trim( (string) ( $src['pattern'] ?? '' ), '/' );
			if ( '' !== $pattern && in_array( $pattern, $paths, true ) ) {
				$wpdb->update( $table, array( 'status' => 'inactive' ), array( 'id' => (int) $r->id ) ); // phpcs:ignore WordPress.DB
				$done[] = (int) $r->id;
				break;
			}
		}
	}

	$cache = $wpdb->prefix . 'rank_math_redirections_cache';
	if ( $done && $cache === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cache ) ) ) { // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$cache} WHERE redirection_id IN (" . implode( ',', array_map( 'intval', $done ) ) . ')' ); // phpcs:ignore WordPress.DB
	}

	update_option( 'daw_rm_redirects_off_v1', array( 'when' => time(), 'ids' => $done, 'paths' => $paths ), false );
}

/**
 * The same decision, done so it actually matches (owner, 24 Sep 2026).
 *
 * v1 compared the full path with Rank Math's pattern and found nothing: the
 * pages were still redirected a day later. Rank Math stores patterns in more
 * than one shape (with or without the domain, slashes or the "exact"
 * comparison on the bare slug), so v2 reduces both sides to the last path
 * segment. The boba page (11286), redirected into the bubble tea recipe
 * (11301), is added: the two now answer different searches ("what is boba"
 * and the recipe) and the owner decided it stands as a page too.
 */
add_action( 'admin_init', 'daw_rm_redirects_off_v2', 31 );
function daw_rm_redirects_off_v2() {
	if ( get_option( 'daw_rm_redirects_off_v2' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'rank_math_redirections';
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB
		update_option( 'daw_rm_redirects_off_v2', array( 'when' => time(), 'note' => 'no Rank Math table' ), false );
		return;
	}
	$last = static function ( $s ) {
		$s = (string) wp_parse_url( (string) $s, PHP_URL_PATH ) ?: (string) $s;
		$s = trim( $s, "/ \t" );
		$p = explode( '/', $s );
		return strtolower( rawurldecode( (string) end( $p ) ) );
	};
	$slugs = array();
	foreach ( array( 9599, 7250, 11286 ) as $pid ) {
		$post = get_post( $pid );
		if ( $post ) {
			$slugs[] = strtolower( (string) $post->post_name );
			$slugs[] = $last( get_permalink( $pid ) );
		}
	}
	$slugs = array_values( array_unique( array_filter( $slugs ) ) );
	$done  = array();
	$rows  = $wpdb->get_results( "SELECT id, sources FROM {$table} WHERE status = 'active'" ); // phpcs:ignore WordPress.DB
	foreach ( (array) $rows as $r ) {
		foreach ( (array) maybe_unserialize( $r->sources ) as $src ) {
			if ( in_array( $last( $src['pattern'] ?? '' ), $slugs, true ) ) {
				$wpdb->update( $table, array( 'status' => 'inactive' ), array( 'id' => (int) $r->id ) ); // phpcs:ignore WordPress.DB
				$done[] = (int) $r->id;
				break;
			}
		}
	}
	$cache = $wpdb->prefix . 'rank_math_redirections_cache';
	if ( $cache === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cache ) ) ) { // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$cache}" ); // phpcs:ignore WordPress.DB
	}
	// The plugin's own map (daw_redirects) must not send these paths away either.
	$map = daw_get_redirects();
	$cut = 0;
	foreach ( array_keys( $map ) as $from ) {
		if ( in_array( $last( $from ), $slugs, true ) ) {
			unset( $map[ $from ] );
			++$cut;
		}
	}
	if ( $cut ) {
		update_option( DAW_REDIRECTS_OPTION, $map, false );
	}
	update_option( 'daw_rm_redirects_off_v2', array( 'when' => time(), 'ids' => $done, 'slugs' => $slugs, 'map_removed' => $cut ), false );
}
