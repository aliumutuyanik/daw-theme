<?php
/**
 * A way to ship a release without anyone downloading a zip.
 *
 * THE PROBLEM
 * -----------
 * Every change to this plugin meant: build a zip here, hand it over, download
 * it, open Plugins, upload, activate, then go and look at a page to find out
 * whether it worked. Ten times in an afternoon that is most of the afternoon,
 * and it is the reason two halves of the site ended up a release apart and
 * printed the word "Array" across every profile.
 *
 * THE CHANNEL
 * -----------
 * The site already has an authenticated write path: the management API that
 * writes post meta. So a release is written into post meta, in pieces, on one
 * hidden record, together with its version and a SHA-256 of the whole. This
 * screen shows what is waiting, checks the hash, and writes the files out.
 *
 * WHAT THIS IS NOT
 * ----------------
 * It is not a door. Writing that meta needs the same credentials that can
 * already install any plugin from the Plugins screen, so it adds no authority
 * that the account did not have. It does concentrate that authority in one
 * place, which is why the hash is checked before a single file is written, why
 * the version is shown before anything happens, and why automatic installation
 * is off until somebody deliberately turns it on.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_PKG_TYPE = 'daw_pkg';

/* ============================================================== channel === */

/**
 * The record the package is written to.
 *
 * A post type of its own, registered private with no rewrite, so it owns no URL
 * and can never collide with the flat namespace the whole site is built on.
 */
add_action( 'init', 'daw_register_pkg_type' );
function daw_register_pkg_type() {
	register_post_type(
		DAW_PKG_TYPE,
		array(
			'label'               => 'DAW packages',
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => array( 'title', 'custom-fields' ),
		)
	);
}

/**
 * The single record, created once and found by title thereafter.
 *
 * The title is the address: the management API can search for it and does not
 * need anybody to read an id off a screen and type it into a chat.
 */
function daw_pkg_post_id() {
	$found = get_posts(
		array(
			'post_type'      => DAW_PKG_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	if ( $found ) {
		return (int) $found[0];
	}

	$id = wp_insert_post(
		array(
			'post_type'   => DAW_PKG_TYPE,
			'post_status' => 'draft',
			'post_title'  => 'DAW package channel',
		)
	);

	return is_wp_error( $id ) ? 0 : (int) $id;
}

/**
 * Make sure the channel exists without anyone having to open a screen.
 *
 * The record has to be there before a release can be written into it, and the
 * one thing this feature must not require is a visit to the page it replaces.
 * One option read on admin pages, and a single insert the first time.
 */
add_action( 'admin_init', 'daw_pkg_ensure_channel', 4 );
function daw_pkg_ensure_channel() {
	if ( ! is_admin() || wp_doing_ajax() || get_option( 'daw_pkg_channel' ) ) {
		return;
	}

	$id = daw_pkg_post_id();

	if ( $id ) {
		update_option( 'daw_pkg_channel', $id, false );
	}
}

/** What is sitting in the channel right now, or an empty array. */
function daw_pkg_waiting() {
	$id = daw_pkg_post_id();

	if ( ! $id ) {
		return array();
	}

	$version = (string) get_post_meta( $id, 'daw_pkg_version', true );
	$sha     = (string) get_post_meta( $id, 'daw_pkg_sha256', true );
	$parts   = (int) get_post_meta( $id, 'daw_pkg_parts', true );

	if ( '' === $version || '' === $sha || $parts < 1 ) {
		return array();
	}

	return array(
		'post_id' => $id,
		'version' => $version,
		'sha256'  => strtolower( $sha ),
		'parts'   => $parts,
	);
}

/* =============================================================== install == */

/**
 * Reassemble, verify, and write the files out.
 *
 * @return string|WP_Error  The installed version on success.
 */
function daw_pkg_install() {
	$pkg = daw_pkg_waiting();

	if ( ! $pkg ) {
		return new WP_Error( 'daw_pkg_none', __( 'There is no package waiting.', 'daw' ) );
	}

	$b64 = '';

	for ( $i = 0; $i < $pkg['parts']; $i++ ) {
		$chunk = get_post_meta( $pkg['post_id'], 'daw_pkg_' . $i, true );

		if ( ! is_string( $chunk ) || '' === $chunk ) {
			/* translators: %d: index of the missing piece */
			return new WP_Error( 'daw_pkg_gap', sprintf( __( 'Piece %d of the package is missing. Nothing was written.', 'daw' ), $i ) );
		}

		$b64 .= $chunk;
	}

	$zip = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

	if ( false === $zip ) {
		return new WP_Error( 'daw_pkg_b64', __( 'The package did not decode. Nothing was written.', 'daw' ) );
	}

	// THE HASH IS CHECKED BEFORE A FILE IS TOUCHED
	// --------------------------------------------
	// Meta is written in pieces over several calls, so a half-written package is
	// a normal thing to find here, not an exceptional one. Without this check
	// the first symptom would be a fatal error on a live site.
	$actual = hash( 'sha256', $zip );

	if ( ! hash_equals( $pkg['sha256'], $actual ) ) {
		return new WP_Error(
			'daw_pkg_hash',
			/* translators: 1: expected hash, 2: actual hash */
			sprintf( __( 'The package does not match its checksum (expected %1$s, got %2$s). Nothing was written.', 'daw' ), substr( $pkg['sha256'], 0, 12 ), substr( $actual, 0, 12 ) )
		);
	}

	global $wp_filesystem;

	require_once ABSPATH . 'wp-admin/includes/file.php';

	if ( ! WP_Filesystem() ) {
		return new WP_Error( 'daw_pkg_fs', __( 'WordPress could not get write access to the plugins directory.', 'daw' ) );
	}

	$uploads = wp_upload_dir();
	$tmp_zip = trailingslashit( $uploads['basedir'] ) . 'daw-pkg-' . $pkg['version'] . '.zip';
	$tmp_dir = trailingslashit( $uploads['basedir'] ) . 'daw-pkg-' . $pkg['version'];

	if ( ! $wp_filesystem->put_contents( $tmp_zip, $zip, FS_CHMOD_FILE ) ) {
		return new WP_Error( 'daw_pkg_write', __( 'The package could not be written to the uploads directory.', 'daw' ) );
	}

	$wp_filesystem->delete( $tmp_dir, true );

	$unzipped = unzip_file( $tmp_zip, $tmp_dir );

	if ( is_wp_error( $unzipped ) ) {
		$wp_filesystem->delete( $tmp_zip );
		return $unzipped;
	}

	// A zip that is not this plugin is not installed. The channel writes over
	// running code; it does not get to guess what it was handed. The root
	// folder may be named either way (daw-core or daw-core-2); it is always
	// written over the folder the running plugin lives in (1.75.58: the site
	// runs from daw-core-2, and the old fixed 'daw-core' target meant every
	// package landed in a folder WordPress never loaded).
	$src = '';
	foreach ( array_unique( array( basename( DAW_DIR ), 'daw-core', 'daw-core-2' ) ) as $root ) {
		if ( $wp_filesystem->exists( $tmp_dir . '/' . $root . '/daw-core.php' ) ) {
			$src = $tmp_dir . '/' . $root;
			break;
		}
	}
	if ( '' === $src ) {
		$wp_filesystem->delete( $tmp_zip );
		$wp_filesystem->delete( $tmp_dir, true );
		return new WP_Error( 'daw_pkg_shape', __( 'That archive is not a daw-core package.', 'daw' ) );
	}

	$copied = copy_dir( $src, untrailingslashit( DAW_DIR ) );

	$wp_filesystem->delete( $tmp_zip );
	$wp_filesystem->delete( $tmp_dir, true );

	if ( is_wp_error( $copied ) ) {
		return $copied;
	}

	// The theme travels inside the plugin and is written out by theme-sync on
	// the next admin request; clearing the marker makes that the very next one.
	delete_option( 'daw_theme_bundled_version' );

	daw_pkg_clear( $pkg );

	update_option( 'daw_pkg_installed', array( 'version' => $pkg['version'], 'at' => time() ), false );

	return $pkg['version'];
}

/** Empty the channel. A package that has been installed is only a large row. */
function daw_pkg_clear( $pkg ) {
	for ( $i = 0; $i < $pkg['parts']; $i++ ) {
		delete_post_meta( $pkg['post_id'], 'daw_pkg_' . $i );
	}

	delete_post_meta( $pkg['post_id'], 'daw_pkg_version' );
	delete_post_meta( $pkg['post_id'], 'daw_pkg_sha256' );
	delete_post_meta( $pkg['post_id'], 'daw_pkg_parts' );
}

/* ========================================================== automatic ==== */

add_action( 'admin_init', 'daw_pkg_maybe_auto_install', 5 );
function daw_pkg_maybe_auto_install() {
	if ( ! is_admin() || wp_doing_ajax() || ! get_option( 'daw_pkg_auto' ) ) {
		return;
	}

	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$pkg = daw_pkg_waiting();

	if ( ! $pkg || $pkg['version'] === DAW_VERSION ) {
		return;
	}

	$done = daw_pkg_install();

	if ( is_wp_error( $done ) ) {
		set_transient( 'daw_pkg_notice', array( 'error', $done->get_error_message() ), HOUR_IN_SECONDS );
		return;
	}

	/* translators: %s: version number */
	set_transient( 'daw_pkg_notice', array( 'success', sprintf( __( 'DAW Core updated itself to %s.', 'daw' ), $done ) ), HOUR_IN_SECONDS );
}

/* =============================================================== screen == */

add_action( 'admin_menu', 'daw_pkg_menu' );
function daw_pkg_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW deploy', 'daw' ),
		__( 'DAW deploy', 'daw' ),
		'update_plugins',
		'daw-deploy',
		'daw_pkg_page'
	);
}

function daw_pkg_page() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	echo '<div class="wrap"><h1>DAW deploy</h1>';

	if ( isset( $_POST['daw_pkg_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['daw_pkg_nonce'] ), 'daw_pkg' ) ) {

		if ( isset( $_POST['daw_pkg_auto_save'] ) ) {
			update_option( 'daw_pkg_auto', isset( $_POST['daw_pkg_auto'] ) ? 1 : 0, false );
			printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html__( 'Saved.', 'daw' ) );
		}

		if ( isset( $_POST['daw_pkg_go'] ) ) {
			$done = daw_pkg_install();

			if ( is_wp_error( $done ) ) {
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $done->get_error_message() ) );
			} else {
				/* translators: %s: version number */
				printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( sprintf( __( 'Installed %s. Reload any open admin screen to run the new code.', 'daw' ), $done ) ) );
			}
		}
	}

	$pkg = daw_pkg_waiting();

	printf(
		'<p>%s <code>%s</code>. %s <code>%s</code>.</p>',
		esc_html__( 'Running plugin version:', 'daw' ),
		esc_html( DAW_VERSION ),
		esc_html__( 'Installed theme version:', 'daw' ),
		esc_html( (string) wp_get_theme( DAW_THEME_SLUG )->get( 'Version' ) )
	);

	echo '<form method="post">';
	wp_nonce_field( 'daw_pkg', 'daw_pkg_nonce' );

	if ( $pkg ) {
		printf(
			'<div class="notice notice-info inline"><p><strong>%s</strong><br>%s <code>%s</code><br>%s <code>%s</code></p></div>',
			esc_html( sprintf( /* translators: %s: version number */ __( 'Package %s is waiting.', 'daw' ), $pkg['version'] ) ),
			esc_html__( 'Checksum:', 'daw' ),
			esc_html( $pkg['sha256'] ),
			esc_html__( 'Pieces:', 'daw' ),
			esc_html( (string) $pkg['parts'] )
		);
		submit_button( __( 'Install this package', 'daw' ), 'primary', 'daw_pkg_go' );
	} else {
		printf( '<p>%s</p>', esc_html__( 'Nothing is waiting. Any new release appears here on its own.', 'daw' ) );
	}

	printf(
		'<hr><p><label><input type="checkbox" name="daw_pkg_auto" value="1" %s> %s</label></p><p class="description" style="max-width:60em">%s</p>',
		checked( (bool) get_option( 'daw_pkg_auto' ), true, false ),
		esc_html__( 'Install new packages automatically', 'daw' ),
		esc_html__( 'With this on, a package that arrives is verified against its checksum and installed the next time an administrator loads any admin screen. With it off, nothing is written until the button above is pressed.', 'daw' )
	);
	submit_button( __( 'Save', 'daw' ), 'secondary', 'daw_pkg_auto_save' );

	echo '</form></div>';
}

add_action( 'admin_notices', 'daw_pkg_notice' );
function daw_pkg_notice() {
	$notice = get_transient( 'daw_pkg_notice' );

	if ( ! $notice || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	delete_transient( 'daw_pkg_notice' );

	printf(
		'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
		'error' === $notice[0] ? 'error' : 'success',
		esc_html( $notice[1] )
	);
}
