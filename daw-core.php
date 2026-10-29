<?php
/**
 * Plugin Name:       DAW Core — record types and fields
 * Plugin URI:        https://discoverasianworld.com
 * Description:       The data layer for Discover Asian World: the dw_type record taxonomy and the Meta Box field schema for titles and people. Registers no public archives and no rewrite rules, so activating it cannot change a single URL.
 * Version:     1.77.16
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Discover Asian World
 * Text Domain:       daw
 * Domain Path:       /languages
 * GitHub Plugin URI: aliumutuyanik/daw-core
 * Primary Branch:    main
 *
 * WHY A PLUGIN AND NOT THE THEME
 * ------------------------------
 * Taxonomies and fields are data. Templates are presentation. If the data layer
 * lived in the theme, the day we switch themes every field on 926 records would
 * vanish from the admin. As a plugin it survives theme changes, and it can be
 * activated today while Astra keeps rendering the site exactly as it does now.
 *
 * SAFETY
 * ------
 * Every taxonomy registers with public => false and rewrite => false. Nothing
 * here touches permalinks, adds rewrite rules, or issues a redirect. Permalinks
 * stay /%postname%/ and all 960 indexed urls resolve exactly as they do today.
 *
 * Install on staging first.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

define( 'DAW_VERSION', '1.77.16' );
define( 'DAW_DIR', plugin_dir_path( __FILE__ ) );

/**
 * No editing plugin or theme files from inside the admin.
 *
 * The file editor turns one stolen editor password into arbitrary code on the
 * server. Everything here is deployed as a zip and lives in version control, so
 * nobody has a reason to open it, and the constant has to be defined before
 * WordPress builds the Tools menu.
 */
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

/**
 * The theme rides along with the plugin, and it does so outside the Meta Box
 * dependency below: keeping the template level with the reader functions is a
 * file operation, not a field operation, and it has to happen even on a site
 * where the field plugin is missing.
 */
require_once DAW_DIR . 'inc/profiler.php';
require_once DAW_DIR . 'inc/quiet.php';
require_once DAW_DIR . 'inc/theme-sync.php';
require_once DAW_DIR . 'inc/icon.php';
require_once DAW_DIR . 'inc/redirects.php';
// The SEO engine that replaces Rank Math (1.51.0). Loaded outside the Meta Box
// dependency on purpose: titles, the sitemap, robots.txt and redirects must
// keep working even on a request where the field plugin is missing. While
// Rank Math is active the engine prints nothing; seo-engine.php says why.
require_once DAW_DIR . 'inc/seo-engine.php';
require_once DAW_DIR . 'inc/seo-sitemap.php';
require_once DAW_DIR . 'inc/seo-redirects.php';
require_once DAW_DIR . 'inc/seo-indexnow.php';
require_once DAW_DIR . 'inc/seo-gsc.php';
require_once DAW_DIR . 'inc/seo-links.php';
require_once DAW_DIR . 'inc/autofix.php';
require_once DAW_DIR . 'inc/kc-progress.php';
require_once DAW_DIR . 'inc/ga4.php';
require_once DAW_DIR . 'inc/perf.php';
require_once DAW_DIR . 'inc/opportunities.php';
require_once DAW_DIR . 'inc/ai-bots.php';
require_once DAW_DIR . 'inc/mcp-health.php';
require_once DAW_DIR . 'inc/rest-bridge.php';
require_once DAW_DIR . 'inc/cache-warm.php';
require_once DAW_DIR . 'inc/index-boost.php';
require_once DAW_DIR . 'inc/index-doctor.php';
require_once DAW_DIR . 'inc/meta-json.php';
require_once DAW_DIR . 'inc/mcp-inbox.php';
require_once DAW_DIR . 'inc/commons-match.php';
require_once DAW_DIR . 'inc/security-check.php';
require_once DAW_DIR . 'inc/redirect-audit.php';
require_once DAW_DIR . 'inc/image-check.php';
require_once DAW_DIR . 'inc/kc-engines.php';
require_once DAW_DIR . 'inc/news-live.php';
require_once DAW_DIR . 'inc/watchdog.php';
require_once DAW_DIR . 'inc/seo-admin.php';
require_once DAW_DIR . 'inc/admin-ui.php';
require_once DAW_DIR . 'inc/comments.php';
require_once DAW_DIR . 'inc/harden.php';
require_once DAW_DIR . 'inc/hsts.php';
require_once DAW_DIR . 'inc/views.php';
require_once DAW_DIR . 'inc/social.php';
require_once DAW_DIR . 'inc/deploy.php';

/**
 * Meta Box is a hard dependency: without it the field schema silently does
 * nothing, which is worse than a clear notice. Fail loudly, fail early.
 */
add_action( 'plugins_loaded', 'daw_boot' );
function daw_boot() {
	if ( ! function_exists( 'rwmb_meta' ) ) {
		add_action( 'admin_notices', 'daw_missing_metabox_notice' );
		return;
	}

	require_once DAW_DIR . 'inc/taxonomies.php';
	require_once DAW_DIR . 'inc/fields.php';
	require_once DAW_DIR . 'inc/fields-person.php';
	require_once DAW_DIR . 'inc/awards-import.php';
	require_once DAW_DIR . 'inc/fields-guide.php';
	require_once DAW_DIR . 'inc/fields-quiz.php';
	require_once DAW_DIR . 'inc/quiz-seed.php';
	require_once DAW_DIR . 'inc/groups.php';
	require_once DAW_DIR . 'inc/meta-shape.php';
	require_once DAW_DIR . 'inc/spotify.php';
	require_once DAW_DIR . 'inc/apple.php';
	require_once DAW_DIR . 'inc/mentions-fix.php';
	require_once DAW_DIR . 'inc/bts-seed.php';
	require_once DAW_DIR . 'inc/rating.php';
	require_once DAW_DIR . 'inc/platforms.php';
	require_once DAW_DIR . 'inc/related.php';
	require_once DAW_DIR . 'inc/crawl-budget.php';
	require_once DAW_DIR . 'inc/home-onair.php';
	require_once DAW_DIR . 'inc/filmography.php';
	require_once DAW_DIR . 'inc/news-links.php';
	require_once DAW_DIR . 'inc/schema.php';
	require_once DAW_DIR . 'inc/tmdb.php';
	require_once DAW_DIR . 'inc/tmdb-match.php';
	require_once DAW_DIR . 'inc/tmdb-fill.php';
	require_once DAW_DIR . 'inc/links-wikidata.php';
	require_once DAW_DIR . 'inc/cast-report.php';
	require_once DAW_DIR . 'inc/audit.php';
	require_once DAW_DIR . 'inc/audit-wide.php';
	// Why each record is not finished, over REST (Umut, 8 Oct 2026).
	require_once DAW_DIR . 'inc/archive-report.php';
	require_once DAW_DIR . 'inc/verify.php';
	require_once DAW_DIR . 'inc/image-rights.php';
	require_once DAW_DIR . 'inc/news-cards.php';
	require_once DAW_DIR . 'inc/pinterest.php';
	require_once DAW_DIR . 'inc/news-sitemap.php';
	require_once DAW_DIR . 'inc/tmdb-hotlink.php';
	require_once DAW_DIR . 'inc/tmdb-remote.php';
	require_once DAW_DIR . 'inc/watch-providers.php';
	require_once DAW_DIR . 'inc/coverage.php';
	require_once DAW_DIR . 'inc/rest-meta-min.php';
	require_once DAW_DIR . 'inc/verify-fill.php';
	require_once DAW_DIR . 'inc/images-hub.php';
	require_once DAW_DIR . 'inc/images-job.php';
	require_once DAW_DIR . 'inc/admin-home.php';
	require_once DAW_DIR . 'inc/control-center.php';
	require_once DAW_DIR . 'inc/brand-images.php';
	require_once DAW_DIR . 'inc/rulebook.php';
	require_once DAW_DIR . 'inc/seasons.php';
	require_once DAW_DIR . 'inc/tmdb-episodes.php';
	require_once DAW_DIR . 'inc/source-store.php';
	require_once DAW_DIR . 'inc/api-keys.php';
	require_once DAW_DIR . 'inc/originality.php';
	require_once DAW_DIR . 'inc/voice.php';
	require_once DAW_DIR . 'inc/audit-engine.php';
	require_once DAW_DIR . 'inc/coverage-panel.php';
	require_once DAW_DIR . 'inc/llm.php';
	require_once DAW_DIR . 'inc/automation.php';
	require_once DAW_DIR . 'inc/posts-list.php';
	require_once DAW_DIR . 'inc/audit-panel.php';
	require_once DAW_DIR . 'inc/work-center.php';
	require_once DAW_DIR . 'inc/backlog.php';
	require_once DAW_DIR . 'inc/freshness.php';
	require_once DAW_DIR . 'inc/snapshot.php';
	require_once DAW_DIR . 'inc/faq-build.php';
	require_once DAW_DIR . 'inc/guide-report.php';
	require_once DAW_DIR . 'inc/guide-clean.php';
	require_once DAW_DIR . 'inc/seo.php';
	require_once DAW_DIR . 'inc/hubs.php';
	require_once DAW_DIR . 'inc/browse.php';
	require_once DAW_DIR . 'inc/search.php';
	require_once DAW_DIR . 'inc/speed.php';
	require_once DAW_DIR . 'inc/analytics-gate.php';
	require_once DAW_DIR . 'inc/litespeed.php';
	require_once DAW_DIR . 'inc/speed-audit.php';
	require_once DAW_DIR . 'inc/speed-psi.php';
	require_once DAW_DIR . 'inc/page-audit.php';
	require_once DAW_DIR . 'inc/seo-command.php';
	require_once DAW_DIR . 'inc/schema-browse.php';
	require_once DAW_DIR . 'inc/retype.php';
	require_once DAW_DIR . 'inc/elementor-exit.php';
	// The extractor used to be an admin-only file, because the only thing that
	// ran it was a button in Tools. The backfill runs it on cron as well, and
	// cron is not an admin request, so it loads for everybody now. It adds a
	// menu page and nothing else until something calls it.
	require_once DAW_DIR . 'inc/extract.php';
	require_once DAW_DIR . 'inc/backfill.php';
	require_once DAW_DIR . 'inc/calendar.php';
	require_once DAW_DIR . 'inc/food-on-screen.php';
	require_once DAW_DIR . 'inc/docs.php';
	// Thin page gate and missing-cast linker (Umut's SEO spec, 8 Oct 2026).
	require_once DAW_DIR . 'inc/index-gate.php';
	require_once DAW_DIR . 'inc/cast-link.php';
	// One-shot data repairs. Each keeps its own done-flag option and returns
	// immediately once it has run, so they cost one get_option() per admin
	// request after the first.
	require_once DAW_DIR . 'inc/migrate-alhambra.php';
	require_once DAW_DIR . 'inc/migrate-panels.php';

	if ( is_admin() ) {
		// Order matters: the prefilter trims the box list for an existing
		// record, the visibility script handles the new-post case where no
		// type is set yet. Together they cover both, and neither loads work
		// the other has already done.
		require_once DAW_DIR . 'inc/admin-box-prefilter.php';
		require_once DAW_DIR . 'inc/admin-field-visibility.php';
		require_once DAW_DIR . 'inc/media-audit.php';
		require_once DAW_DIR . 'inc/classify.php';
	}

	load_plugin_textdomain( 'daw', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

function daw_missing_metabox_notice() {
	echo '<div class="notice notice-error"><p><strong>DAW Core:</strong> Meta Box is not active. The record taxonomy and field schema will not load until it is.</p></div>';
}

/**
 * Taxonomies are registered on init at priority 5, which has already run by the
 * time an activation hook fires — so register them once here too, then flush.
 * The flush is harmless: we add no rewrite rules, it just clears stale ones.
 */
register_activation_hook( __FILE__, 'daw_activate' );
function daw_activate() {
	if ( function_exists( 'daw_register_taxonomies' ) ) {
		daw_register_taxonomies();
	}
	flush_rewrite_rules();
}

/**
 * Tell the Consent API that this plugin behaves.
 *
 * WP Consent API keeps a register of plugins that have said, in code, that they
 * respect a reader's cookie choices. A plugin that has not said so is listed on
 * the Site Health screen as possibly ignoring consent, which for this one is
 * not true: it sets no cookie, loads no third party script and reads consent
 * before printing an embed. The declaration is the only way to say that.
 */
add_action( 'plugins_loaded', 'daw_declare_consent_api' );
function daw_declare_consent_api() {
	add_filter( 'wp_consent_api_registered_' . plugin_basename( __FILE__ ), '__return_true' );
}

register_deactivation_hook( __FILE__, 'daw_deactivate' );
function daw_deactivate() {
	wp_clear_scheduled_hook( 'daw_tmdb_img_cron' );
	wp_clear_scheduled_hook( 'daw_vault_cron' );
	wp_clear_scheduled_hook( 'daw_img_job_tick' );
	wp_clear_scheduled_hook( 'daw_wp_cron' );
	wp_clear_scheduled_hook( 'daw_cov_cron' );
	wp_clear_scheduled_hook( 'daw_vf_cron' );
	flush_rewrite_rules();
}
