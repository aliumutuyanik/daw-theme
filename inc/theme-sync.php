<?php
/**
 * The theme travels inside the plugin.
 *
 * WHY
 * ---
 * A plugin and a theme are two separate uploads, and two separate uploads are
 * never the same age. That is not a theoretical problem: daw_active_years()
 * changed what it returned, the plugin shipped with the change and the theme
 * did not, and every profile on the site printed the word "Array" where the
 * years should have been. Nobody did anything wrong. The two halves were simply
 * installed an hour apart.
 *
 * So the theme is now carried inside the plugin, under theme/daw-theme, and
 * written into the themes directory whenever the two versions disagree. One
 * upload, one version, and a reader function and the template that reads it can
 * never again be a release apart.
 *
 * The theme is still published as its own zip for a first install and for
 * anyone who would rather do it by hand. This only keeps it level afterwards.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_THEME_SLUG = 'daw-theme';

add_action( 'admin_init', 'daw_sync_bundled_theme' );
add_action( 'init', 'daw_sync_bundled_theme', 20 );

/**
 * Write the bundled theme out if the installed one is a different version.
 *
 * Runs on admin_init rather than on activation alone, because the interesting
 * case is an upgrade: the plugin files are replaced without activation ever
 * firing, and the theme beside them has to follow in the same breath.
 *
 * WHY IT ALSO RUNS ON THE FRONT END
 * ---------------------------------
 * It used to run only in the admin, and the one admin request that follows an
 * upload is update.php, which is exactly the request this is not allowed to run
 * on. WordPress does not redirect after "Replace current with uploaded", it
 * prints a link, so anybody who uploads a release and then goes straight to the
 * site gets the new plugin with last release's templates and a site that looks
 * unchanged. That is a bad half hour to spend, and it happened twice before the
 * cause was clear. Now the first request of any kind after an upload writes the
 * theme, so opening the site is enough.
 */
function daw_sync_bundled_theme() {
	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	// Not while a plugin is being uploaded or replaced. Copying a theme over
	// itself during the one request that is also swapping this plugin's own
	// files is how an upload ends in "the link you followed has expired".
	if ( function_exists( 'daw_is_delicate_request' ) && daw_is_delicate_request() ) {
		return;
	}

	// A failure that has already been reported is not retried on every page
	// load: the copy is expensive, it is going to fail again for the same
	// reason, and the notice with the button is already on screen. The
	// transient expires in an hour, so a permissions fix is picked up without
	// anybody having to remember this rule.
	if ( get_transient( 'daw_theme_sync_error' ) ) {
		return;
	}

	$source = DAW_DIR . 'theme/' . DAW_THEME_SLUG;

	if ( ! is_dir( $source ) ) {
		return;
	}

	$bundled = daw_bundled_theme_version( $source );

	if ( '' === $bundled ) {
		return;
	}

	// Cheap guard first: the version we last wrote out. This runs on every
	// admin page load, so the common case has to cost one option read and
	// nothing else.
	//
	// The key is the theme version AND the plugin version (1.75.64): a plugin
	// release that changed theme files but not the theme's Version header
	// (1.75.62 and 1.75.63 did) was never written out, because both the
	// option and the installed theme already carried the old number. Every
	// plugin release now writes the bundled theme once.
	$key = $bundled . '|' . ( defined( 'DAW_VERSION' ) ? DAW_VERSION : '' );

	if ( get_option( 'daw_theme_bundled_version' ) === $key ) {
		return;
	}

	// Two requests arriving together would both start copying the same files
	// over each other. One lock, thirty seconds, and the second one simply
	// leaves it to the first.
	if ( get_transient( 'daw_theme_sync_lock' ) ) {
		return;
	}

	set_transient( 'daw_theme_sync_lock', 1, 30 );

	$written = daw_write_bundled_theme( $source );

	delete_transient( 'daw_theme_sync_lock' );

	if ( is_wp_error( $written ) ) {
		set_transient( 'daw_theme_sync_error', $written->get_error_message(), HOUR_IN_SECONDS );
		return;
	}

	delete_transient( 'daw_theme_sync_error' );
	update_option( 'daw_theme_bundled_version', $key, false );

	daw_purge_page_cache();

	// The stylesheet is cache-busted by filemtime, and the theme's own header
	// is read from a cached file list. Both have just changed underneath it.
	wp_clean_themes_cache();

	set_transient( 'daw_theme_synced', $bundled, HOUR_IN_SECONDS );
}

/** Read the Version header out of the bundled style.css without loading the theme. */
function daw_bundled_theme_version( $source ) {
	$style = $source . '/style.css';

	if ( ! is_readable( $style ) ) {
		return '';
	}

	$head = (string) file_get_contents( $style, false, null, 0, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	return preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', $head, $m ) ? trim( $m[1] ) : '';
}

/**
 * Copy the bundled theme over the installed one.
 *
 * Files are overwritten and new ones added. Nothing is deleted, because a
 * deletion here would be a deletion inside a directory the site owner may also
 * have put something in, and a stale extra file is a far smaller problem than
 * an upload somebody loses.
 */
function daw_write_bundled_theme( $source ) {
	global $wp_filesystem;

	require_once ABSPATH . 'wp-admin/includes/file.php';

	if ( ! WP_Filesystem() ) {
		return new WP_Error( 'daw_fs', __( 'WordPress could not get write access to the themes directory.', 'daw' ) );
	}

	$target = trailingslashit( get_theme_root() ) . DAW_THEME_SLUG;

	if ( ! $wp_filesystem->is_dir( $target ) && ! $wp_filesystem->mkdir( $target, FS_CHMOD_DIR ) ) {
		return new WP_Error( 'daw_fs', __( 'The themes directory is not writable.', 'daw' ) );
	}

	$result = copy_dir( $source, $target );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// The theme serves assets/daw.min.css only while it is not older than
	// assets/daw.css. copy_dir() writes files in directory order, so the two
	// can land a second apart either way; stamping the minified copy last
	// keeps a fresh release on the small file instead of the 229 KB source.
	if ( $wp_filesystem->exists( $target . '/assets/daw.min.css' ) ) {
		$wp_filesystem->touch( $target . '/assets/daw.min.css' );
	}

	return true;
}

/* ------------------------------------------------- forcing it by hand --- */

/**
 * A button that writes the theme out now, and says why if it cannot.
 *
 * WHY THIS EXISTS
 * ---------------
 * The automatic write failed once on a host whose themes directory PHP could
 * not write to, and it failed the way these things always fail: quietly. The
 * plugin said 0.40.0, the theme stayed at 0.37.0, every template on the site
 * was three releases old, and the only clue was a notice that had already been
 * dismissed. So there is now a notice that cannot be dismissed while the two
 * numbers disagree, and a button beside it that tries again and reports what
 * happened rather than leaving it to be guessed at.
 */
add_action( 'admin_post_daw_force_theme_sync', 'daw_force_theme_sync' );
function daw_force_theme_sync() {
	if ( ! current_user_can( 'switch_themes' ) || ! check_admin_referer( 'daw_force_theme_sync' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'daw' ) );
	}

	$source = DAW_DIR . 'theme/' . DAW_THEME_SLUG;
	$result = is_dir( $source ) ? daw_write_bundled_theme( $source ) : new WP_Error( 'daw_fs', __( 'The plugin is not carrying a theme.', 'daw' ) );

	if ( is_wp_error( $result ) ) {
		set_transient( 'daw_theme_sync_error', $result->get_error_message(), HOUR_IN_SECONDS );
	} else {
		delete_transient( 'daw_theme_sync_error' );
		update_option( 'daw_theme_bundled_version', daw_bundled_theme_version( $source ) . '|' . ( defined( 'DAW_VERSION' ) ? DAW_VERSION : '' ), false );
		daw_purge_page_cache();
		wp_clean_themes_cache();
		set_transient( 'daw_theme_synced', daw_bundled_theme_version( $source ), HOUR_IN_SECONDS );
	}

	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}

/** The installed theme's version, or '' when it is not there. */
function daw_installed_theme_version() {
	$theme = wp_get_theme( DAW_THEME_SLUG );

	return $theme->exists() ? (string) $theme->get( 'Version' ) : '';
}

/* ------------------------------------------------------------- notices --- */

/**
 * REMOVED 2 Oct 2026 (owner: "sen bunu kendi içerisinde hallet, yanlış bir
 * şey yapıyormuş hissi oluyor" — handle this yourself, it reads as something
 * being wrong). The sync itself was never the problem — daw_sync_bundled_theme()
 * already fixes a real mismatch on its own, silently, before any admin page
 * renders. These two functions only ever printed a red/orange banner ABOUT
 * that — ordinary bookkeeping made to look like an incident. The functions
 * stay defined (daw_theme_version_notice, daw_theme_sync_notice) in case a
 * future owner wants the visibility back, but neither is hooked to
 * `admin_notices` any more, and the manual "force sync" admin-post action
 * below is left in place as a fallback that works without ever announcing
 * itself.
 */
function daw_theme_version_notice() {
	if ( ! current_user_can( 'switch_themes' ) ) {
		return;
	}

	$installed = daw_installed_theme_version();
	if ( '' === $installed ) {
		return;
	}

	// BUG FIXED 2 Oct 2026: this used to compare the installed theme's version
	// against DAW_VERSION — the PLUGIN's own version constant. The plugin and
	// theme are two independent release numbers that only coincided by
	// convention; the plugin bumps on every change, including a plugin-only
	// release that never touches the theme (daw-core 1.47.20, a packaging fix,
	// shipped with the theme unchanged at 1.47.17). That comparison made this
	// warning permanent from that point on, firing on every admin page for a
	// "mismatch" that was never real. The only version that matters here is
	// the bundled copy's own — whether the installed theme matches what THIS
	// plugin package is actually carrying, which is exactly what the silent
	// auto-sync in daw_sync_bundled_theme() already checks correctly.
	$source  = DAW_DIR . 'theme/' . DAW_THEME_SLUG;
	$bundled = is_dir( $source ) ? daw_bundled_theme_version( $source ) : '';

	if ( '' === $bundled || $installed === $bundled ) {
		return;
	}

	$url = wp_nonce_url( admin_url( 'admin-post.php?action=daw_force_theme_sync' ), 'daw_force_theme_sync' );
	?>
	<div class="notice notice-warning">
		<p>
			<strong><?php esc_html_e( 'The DAW plugin and theme are different versions.', 'daw' ); ?></strong>
			<?php
			printf(
				/* translators: 1: bundled theme version, 2: installed theme version */
				esc_html__( 'This plugin is carrying theme %1$s but %2$s is installed. Templates read functions from the plugin, so while these disagree parts of the site can print the wrong thing or nothing at all.', 'daw' ),
				esc_html( $bundled ),
				esc_html( $installed )
			);
			?>
		</p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Write the theme out now', 'daw' ); ?></a>
		</p>
	</div>
	<?php
}

function daw_theme_sync_notice() {
	if ( ! current_user_can( 'switch_themes' ) ) {
		return;
	}

	$error = get_transient( 'daw_theme_sync_error' );

	if ( $error ) {
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'The DAW theme could not be updated automatically.', 'daw' ),
			esc_html( $error . ' ' . __( 'Install daw-theme.zip by hand this once.', 'daw' ) )
		);
		return;
	}

	$done = get_transient( 'daw_theme_synced' );

	if ( $done ) {
		delete_transient( 'daw_theme_synced' );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( /* translators: %s: version number */ __( 'DAW theme updated to %s along with the plugin.', 'daw' ), $done ) )
		);
	}
}

/**
 * Throw away the cached pages after the theme changes underneath them.
 *
 * WHY THIS IS HERE AND NOT IN A CHECKLIST
 * --------------------------------------
 * Three times in a row a template was updated, the plugin reported success,
 * and the page still showed yesterday's markup, because a page cache was
 * serving html generated before the template existed. Each time it looked
 * like the code was broken. Each time the fix was a button in another plugin.
 *
 * A step that has to be remembered after every release is a step that will be
 * forgotten, and the failure it causes is indistinguishable from a bug. So the
 * release does it. Every cache plugin here is asked in the way it prefers, and
 * a site running none of them is unaffected: an action nobody listens to costs
 * nothing.
 */
function daw_purge_page_cache() {
	// LiteSpeed, which is what this site runs.
	do_action( 'litespeed_purge_all' );

	// The others, so moving host does not quietly bring the problem back.
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}

	// WordPress's own object cache last: the page caches above may read from it.
	wp_cache_flush();
}

/* ============================================================= parent ==== */

/**
 * A theme that stopped being a child theme is still registered as one.
 *
 * WHAT HAPPENED
 * -------------
 * daw-theme was built as a child of Astra so the site kept rendering while the
 * templates were replaced one at a time. That job finished: every page is drawn
 * by a file in this theme, the parent was carrying seventy kilobytes of inline
 * stylesheet on every request for a header and a footer it no longer drew, and
 * it was removed.
 *
 * WordPress does not notice. The parent is recorded in the 'template' option at
 * the moment a theme is activated and is never read from style.css again, so
 * the site is now running a theme whose recorded parent is a folder that does
 * not exist. Nothing breaks today, because every template this theme needs is
 * its own, but WordPress reports the theme as broken, get_template_directory()
 * answers with a missing path, and the first plugin that trusts that answer
 * finds out the hard way.
 *
 * WHEN THIS RUNS
 * --------------
 * Only when all three are true: the active theme declares no parent in its own
 * style.css, the recorded parent is something else, and that recorded parent is
 * not on disk. Any one of those failing means this is a real child theme and
 * this code does nothing at all.
 */
add_action( 'admin_init', 'daw_fix_orphan_parent', 4 );
function daw_fix_orphan_parent() {
	$stylesheet = get_option( 'stylesheet' );
	$template   = get_option( 'template' );

	if ( ! $stylesheet || $stylesheet === $template ) {
		return;
	}

	$theme = wp_get_theme( $stylesheet );

	// It still says it has a parent, so it has one.
	if ( $theme->get( 'Template' ) ) {
		return;
	}

	// The recorded parent is on disk, so leave the registration alone.
	if ( $template && is_dir( trailingslashit( get_theme_root() ) . $template ) ) {
		return;
	}

	update_option( 'template', $stylesheet );

	update_option(
		'daw_parent_fixed',
		sprintf(
			/* translators: 1: the parent that was recorded, 2: the theme now recorded */
			__( 'The theme was still registered as a child of "%1$s", which is no longer installed. It is now registered as "%2$s", which is what it is.', 'daw' ),
			(string) $template,
			(string) $stylesheet
		),
		false
	);
}

add_action( 'admin_notices', 'daw_parent_fixed_notice' );
function daw_parent_fixed_notice() {
	$note = (string) get_option( 'daw_parent_fixed' );

	if ( '' === $note || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	delete_option( 'daw_parent_fixed' );

	printf( '<div class="notice notice-success is-dismissible"><p><strong>DAW:</strong> %s</p></div>', esc_html( $note ) );
}

/* ================================================================ purge === */

add_action( 'admin_init', 'daw_purge_on_update', 6 );
add_action( 'init', 'daw_purge_on_update', 21 );

/**
 * A new release has to be visible the moment it is installed.
 *
 * WHY THIS IS NEEDED AT ALL
 * -------------------------
 * The stylesheet and the scripts carry the version in their url, so a browser
 * fetches the new ones by itself. The page around them does not: LiteSpeed
 * keeps a copy of the rendered html and keeps serving it, so a template change
 * is invisible until something expires or somebody presses Purge All. Uploading
 * a release and then being told the site looks exactly the same is a bad enough
 * experience on its own, and it is worse than that: it makes every change look
 * like it failed, and the next thing anybody does is upload it again.
 *
 * WHAT IT CLEARS
 * --------------
 * Three layers, because there are three. LiteSpeed's page cache, our own hub
 * and browse transients, and the generation counter the filtered lists key
 * themselves on. All of them only when the version actually changed, so this
 * costs one option read on an ordinary admin request.
 */
function daw_purge_on_update() {
	if ( get_option( 'daw_version_seen' ) === DAW_VERSION ) {
		return;
	}

	update_option( 'daw_version_seen', DAW_VERSION, false );

	if ( function_exists( 'daw_flush_hub_cache' ) ) {
		daw_flush_hub_cache();
	}

	if ( function_exists( 'daw_browse_bump_gen' ) ) {
		daw_browse_bump_gen();
	}

	// LiteSpeed listens for this whether or not the plugin is active, and does
	// nothing at all when it is not.
	do_action( 'litespeed_purge_all' );

	set_transient( 'daw_purged_notice', DAW_VERSION, 60 );
}

add_action( 'admin_notices', 'daw_purge_notice' );
function daw_purge_notice() {
	$version = get_transient( 'daw_purged_notice' );

	if ( ! $version || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	delete_transient( 'daw_purged_notice' );

	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: the version just installed */
				__( 'DAW %s is live. The page cache was cleared, so the site is showing the new version now.', 'daw' ),
				$version
			)
		)
	);
}
