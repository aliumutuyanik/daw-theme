<?php
/**
 * Discover Asian World — comments, and why there are none.
 *
 * WHAT WAS IN THERE
 * -----------------
 * Five hundred and thirty two comments. Every one of them was read. Not one was
 * a reader. The pending queue was Mexican pharmacy links, WordPress web design
 * links, casino links dressed as praise and a run of adult site spam. The
 * approved queue was the site pinging itself: a hundred and fifty notices that
 * one of our own pages had linked to another of our own pages.
 *
 * WHY THEY ARE TURNED OFF RATHER THAN MODERATED
 * ---------------------------------------------
 * This is a reference archive. A drama record is a set of fields and a cast
 * list; nobody has ever needed to leave a note under one, and the templates
 * have never printed a comment. So the form was open, invisible, and collecting
 * links for three years. Moderating that is a weekly chore with no upside.
 * Closing it removes the chore, the rows, the spam surface and the requests.
 *
 * Self-pings go with them. A site that notifies itself every time it links to
 * itself is a site generating its own database rows for no reader.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================== closed === */

// Nothing is open, whatever any individual post still says in its own row.
add_filter( 'comments_open', '__return_false', 100 );
add_filter( 'pings_open', '__return_false', 100 );

// And nothing is printed, so a template that asks for the list gets an empty
// one rather than a form.
add_filter( 'comments_array', '__return_empty_array', 100 );

// The XML-RPC endpoint is the other door into the same room.
add_filter( 'xmlrpc_methods', 'daw_close_pingback_methods' );
function daw_close_pingback_methods( $methods ) {
	unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );

	return $methods;
}

add_filter( 'wp_headers', 'daw_drop_pingback_header' );
function daw_drop_pingback_header( $headers ) {
	unset( $headers['X-Pingback'] );

	return $headers;
}

remove_action( 'wp_head', 'rsd_link' );

// A page that links to another of our own pages does not need to tell us.
add_action( 'pre_ping', 'daw_drop_self_pings' );
function daw_drop_self_pings( &$links ) {
	$home = home_url();

	foreach ( $links as $i => $link ) {
		if ( 0 === strpos( (string) $link, $home ) ) {
			unset( $links[ $i ] );
		}
	}
}

/* ============================================================== admin ==== */

/**
 * Take the comments screens out of the admin.
 *
 * A menu item that opens onto a permanently empty list is a menu item that
 * makes somebody check it.
 */
add_action( 'admin_menu', 'daw_hide_comments_menu', 999 );
function daw_hide_comments_menu() {
	remove_menu_page( 'edit-comments.php' );
	remove_submenu_page( 'options-general.php', 'options-discussion.php' );
}

add_action( 'init', 'daw_hide_comments_bar' );
function daw_hide_comments_bar() {
	remove_action( 'admin_bar_menu', 'wp_admin_bar_comments_menu', 60 );
}

add_action( 'admin_init', 'daw_drop_comment_support', 20 );
function daw_drop_comment_support() {
	foreach ( array( 'post', 'page', 'attachment' ) as $type ) {
		if ( post_type_supports( $type, 'comments' ) ) {
			remove_post_type_support( $type, 'comments' );
			remove_post_type_support( $type, 'trackbacks' );
		}
	}
}

/* =============================================================== once ==== */

/**
 * Close every existing row and delete what is already in there.
 *
 * The filters above are enough to make the site behave, but they leave five
 * hundred rows in the database and a number in the admin bar. This clears both,
 * once, in slices, and writes down what it removed.
 */
const DAW_COMMENTS_RUN = '2026-09-18-a';

add_action( 'admin_init', 'daw_comments_cleanup', 33 );
function daw_comments_cleanup() {
	if ( function_exists( 'daw_is_delicate_request' ) && daw_is_delicate_request() ) {
		return;
	}

	if ( get_option( 'daw_comments_run' ) === DAW_COMMENTS_RUN ) {
		return;
	}

	if ( get_transient( 'daw_comments_lock' ) ) {
		return;
	}

	set_transient( 'daw_comments_lock', 1, 120 );

	global $wpdb;

	$log   = (array) get_option( 'daw_comments_log', array( 'deleted' => 0, 'closed' => 0 ) );
	$start = microtime( true );

	// Delete in slices: five hundred wp_delete_comment calls in one request is
	// a timeout, and each one fires actions other code may be listening to.
	$ids = (array) $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} LIMIT 200" );

	foreach ( $ids as $id ) {
		if ( ( microtime( true ) - $start ) > 12 ) {
			break;
		}

		wp_delete_comment( (int) $id, true );
		++$log['deleted'];
	}

	if ( ! $ids ) {
		// Nothing left to remove, so close the doors on every row and stop.
		$log['closed'] = (int) $wpdb->query(
			"UPDATE {$wpdb->posts} SET comment_status = 'closed', ping_status = 'closed'
			 WHERE comment_status <> 'closed' OR ping_status <> 'closed'"
		);

		update_option( 'default_comment_status', 'closed' );
		update_option( 'default_ping_status', 'closed' );
		update_option( 'default_pingback_flag', 0 );

		update_option( 'daw_comments_run', DAW_COMMENTS_RUN, false );
	}

	update_option( 'daw_comments_log', $log, false );

	delete_transient( 'daw_comments_lock' );
}
