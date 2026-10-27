<?php
/**
 * Discover Asian World — the requests that must not be interrupted.
 *
 * WHAT WENT WRONG
 * ---------------
 * Uploading a new build of this plugin started answering "The link you followed
 * has expired". That message means WordPress rejected the nonce on the replace
 * step, and the most common way to get there is not a stale nonce at all: it is
 * a request that took so long the browser gave up and sent it again.
 *
 * This plugin runs four background jobs on admin_init, each with a twelve
 * second budget: taking the archive off the builder, reading old page bodies
 * into fields, removing unused media, clearing the comment table. They are
 * deliberately greedy, because the whole point is that they finish while
 * somebody works. On an ordinary screen that is free. On the screen that is
 * receiving a six hundred kilobyte zip and then swapping the plugin's own files
 * underneath itself, it is a minute of work stapled to the one request that
 * must not be slow.
 *
 * So the jobs stand aside on those requests. They lose nothing: the queue is
 * stored, the next page load picks it up, and cron picks it up anyway.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether this request is one to stay out of the way of.
 *
 * Installing, updating, uploading and the two endpoints that carry file uploads
 * and background browser calls. Everything else is an ordinary screen.
 */
function daw_is_delicate_request() {
	static $delicate = null;

	if ( null !== $delicate ) {
		return $delicate;
	}

	$delicate = false;

	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) ) {
		$delicate = true;

		return $delicate;
	}

	$script = isset( $_SERVER['PHP_SELF'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['PHP_SELF'] ) ) ) : '';

	$pages = array(
		'update.php',
		'update-core.php',
		'plugin-install.php',
		'theme-install.php',
		'plugin-editor.php',
		'theme-editor.php',
		'async-upload.php',
		'media-upload.php',
		'admin-ajax.php',
		'import.php',
		'export.php',
		'site-health.php',
	);

	if ( in_array( $script, $pages, true ) ) {
		$delicate = true;

		return $delicate;
	}

	// A file is being posted somewhere, which is the shape of every upload no
	// matter which screen it came from.
	if ( ! empty( $_FILES ) ) {
		$delicate = true;
	}

	return $delicate;
}
