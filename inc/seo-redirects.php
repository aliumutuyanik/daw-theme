<?php
/**
 * Discover Asian World: redirections and the 404 log, without Rank Math.
 *
 * TWO TABLES
 * ----------
 * {prefix}daw_redirects holds every move the site has made: the ones imported
 * from Rank Math, the ones written here by hand, and the ones made on their
 * own when a published page's address changes. {prefix}daw_404 holds the
 * addresses people asked for that did not exist.
 *
 * WHEN A REDIRECT FIRES
 * ---------------------
 * A rule written here fires on a 404, the same as the plugin's own
 * daw_redirects map (redirects.php): it can then never hide a live page,
 * which is how the Chuseok, soju bomb and boba pages disappeared for a week
 * in September. Rules imported from Rank Math are the exception ("force"):
 * Rank Math ran them on every request, the feed rules depend on that, and
 * they keep doing it. Any of them can be switched off in DAW › SEO.
 *
 * WHO GETS LOGGED
 * ---------------
 * Rank Math logged every 404, and most of its 91 rows were bots probing for
 * files that never existed. Here a request is sorted first: a person, a search
 * engine crawler (Google, Bing, Yandex, Apple, DuckDuckGo, whose 404s matter
 * for Search Console) or any other bot. People and search engines are logged
 * per address; other bots are left out. Probes for .php files, wp-admin,
 * .env and the like are never stored.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_RD_DB_VERSION = 2;

function daw_rd_table() {
	global $wpdb;

	return $wpdb->prefix . 'daw_redirects';
}

function daw_nf_table() {
	global $wpdb;

	return $wpdb->prefix . 'daw_404';
}

/* ============================================================== tables === */

add_action( 'plugins_loaded', 'daw_rd_install', 5 );
function daw_rd_install() {
	if ( (int) get_option( 'daw_rd_db', 0 ) >= DAW_RD_DB_VERSION ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();

	dbDelta(
		'CREATE TABLE ' . daw_rd_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(512) NOT NULL DEFAULT '',
			match_type varchar(10) NOT NULL DEFAULT 'exact',
			ignore_case tinyint(1) NOT NULL DEFAULT 1,
			target varchar(1024) NOT NULL DEFAULT '',
			code smallint(5) unsigned NOT NULL DEFAULT 301,
			status varchar(10) NOT NULL DEFAULT 'active',
			force_live tinyint(1) NOT NULL DEFAULT 0,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit datetime NULL DEFAULT NULL,
			origin varchar(20) NOT NULL DEFAULT 'manual',
			note varchar(255) NOT NULL DEFAULT '',
			created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY source (source(191)),
			KEY status (status)
		) {$charset};"
	);

	dbDelta(
		'CREATE TABLE ' . daw_nf_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			path varchar(512) NOT NULL DEFAULT '',
			path_hash char(32) NOT NULL DEFAULT '',
			kind varchar(10) NOT NULL DEFAULT 'page',
			hits_human int(10) unsigned NOT NULL DEFAULT 0,
			hits_search int(10) unsigned NOT NULL DEFAULT 0,
			first_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_ref varchar(512) NOT NULL DEFAULT '',
			last_ua varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY path_hash (path_hash),
			KEY path (path(191)),
			KEY last_seen (last_seen)
		) {$charset};"
	);

	update_option( 'daw_rd_db', DAW_RD_DB_VERSION, true );
}

function daw_rd_tables_ready() {
	return (int) get_option( 'daw_rd_db', 0 ) >= DAW_RD_DB_VERSION;
}

/* ============================================================ matching === */

/** The request path, rooted, as typed (no query string). */
function daw_rd_request_path() {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

	return '/' . ltrim( rawurldecode( $path ), '/' );
}

/** A source in the form it is stored and compared: rooted, trailing slash, lower case. */
function daw_rd_norm( $url ) {
	if ( function_exists( 'daw_normalise_path' ) ) {
		$path = daw_normalise_path( $url );
	} else {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$path = '' === trim( $path, '/' ) ? '/' : '/' . trim( $path, '/' ) . '/';
	}

	return strtolower( rawurldecode( $path ) );
}

/**
 * Active rules that are not plain exact matches, kept in one autoloaded
 * option until a rule changes. The forced ones are checked on every request,
 * so this has to cost nothing: an autoloaded option is already in memory, a
 * transient with an expiry would be a query per page view.
 */
function daw_rd_pattern_rules() {
	$rules = get_option( 'daw_rd_patterns' );

	if ( is_array( $rules ) ) {
		return $rules;
	}

	global $wpdb;

	$rules = daw_rd_tables_ready() ? (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		'SELECT id, source, match_type, ignore_case, target, code, force_live FROM ' . daw_rd_table() . " WHERE status = 'active' AND ( match_type <> 'exact' OR force_live = 1 ) ORDER BY id ASC",
		ARRAY_A
	) : array();

	update_option( 'daw_rd_patterns', $rules, true );

	return $rules;
}

function daw_rd_flush_cache() {
	delete_option( 'daw_rd_patterns' );
}

/** Does one rule match this path? */
function daw_rd_rule_matches( array $rule, $path ) {
	$type    = (string) $rule['match_type'];
	$source  = (string) $rule['source'];
	$subject = ltrim( $path, '/' );

	if ( 'exact' === $type ) {
		return daw_rd_norm( $path ) === daw_rd_norm( $source );
	}

	if ( 'regex' === $type ) {
		$flags = ! empty( $rule['ignore_case'] ) ? 'i' : '';
		$re    = '@' . str_replace( '@', '\@', $source ) . '@' . $flags;

		return 1 === @preg_match( $re, $subject ) || 1 === @preg_match( $re, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	$needle = trim( $source, '/' );
	$hay    = trim( $subject, '/' );

	if ( ! empty( $rule['ignore_case'] ) ) {
		$needle = strtolower( $needle );
		$hay    = strtolower( $hay );
	}

	if ( '' === $needle ) {
		return false;
	}

	switch ( $type ) {
		case 'start':
			return 0 === strpos( $hay, $needle );
		case 'end':
			return substr( $hay, -strlen( $needle ) ) === $needle;
		case 'contains':
			return false !== strpos( $hay, $needle );
	}

	return false;
}

/** The target url for a matched rule, with regex back-references filled in. */
function daw_rd_target( array $rule, $path ) {
	$target = (string) $rule['target'];

	if ( 'regex' === $rule['match_type'] && false !== strpos( $target, '$' ) ) {
		$flags = ! empty( $rule['ignore_case'] ) ? 'i' : '';
		$re    = '@' . str_replace( '@', '\@', (string) $rule['source'] ) . '@' . $flags;
		$new   = @preg_replace( $re, $target, ltrim( $path, '/' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( is_string( $new ) ) {
			$target = $new;
		}
	}

	if ( '' !== $target && ! preg_match( '#^https?://#i', $target ) ) {
		$target = home_url( '/' . ltrim( $target, '/' ) );
	}

	return $target;
}

/** Send the visitor on, count the hit, stop. */
function daw_rd_execute( array $rule, $path ) {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( 'UPDATE ' . daw_rd_table() . ' SET hits = hits + 1, last_hit = %s WHERE id = %d', current_time( 'mysql', true ), (int) $rule['id'] ) ); // phpcs:ignore WordPress.DB

	$code = (int) $rule['code'];

	if ( in_array( $code, array( 410, 451 ), true ) ) {
		// Gone, on purpose. The 404 template draws the page, even when the
		// address still resolves to something; only the status says it will
		// not come back.
		global $wp_query;
		if ( $wp_query && ! is_404() ) {
			$wp_query->set_404();
		}
		status_header( $code );
		nocache_headers();
		return;
	}

	$target = daw_rd_target( $rule, $path );

	if ( '' === $target ) {
		return;
	}

	// Our own host goes through wp_safe_redirect; another host only if the rule says so.
	add_filter(
		'allowed_redirect_hosts',
		static function ( $hosts ) use ( $target ) {
			$hosts[] = (string) wp_parse_url( $target, PHP_URL_HOST );
			return $hosts;
		}
	);

	wp_safe_redirect( $target, in_array( $code, array( 301, 302, 307, 308 ), true ) ? $code : 301, 'DAW' );
	exit;
}

/** Forced rules, on every request, before WordPress decides anything else. */
add_action( 'template_redirect', 'daw_rd_serve_forced', 0 );
function daw_rd_serve_forced() {
	if ( is_admin() || ! daw_rd_tables_ready() ) {
		return;
	}

	$path = daw_rd_request_path();

	foreach ( daw_rd_pattern_rules() as $rule ) {
		if ( ! empty( $rule['force_live'] ) && daw_rd_rule_matches( $rule, $path ) ) {
			daw_rd_execute( $rule, $path );
			return;
		}
	}
}

/** Everything else, only when the address does not exist. */
add_action( 'template_redirect', 'daw_rd_serve_on_404', 2 );
function daw_rd_serve_on_404() {
	if ( ! is_404() || is_admin() || ! daw_rd_tables_ready() ) {
		return;
	}

	global $wpdb;

	$path = daw_rd_request_path();
	$norm = daw_rd_norm( $path );

	$rule = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			'SELECT id, source, match_type, ignore_case, target, code, force_live FROM ' . daw_rd_table() . " WHERE status = 'active' AND match_type = 'exact' AND source = %s ORDER BY id DESC LIMIT 1",
			$norm
		),
		ARRAY_A
	);

	if ( $rule ) {
		daw_rd_execute( $rule, $path );
		return;
	}

	foreach ( daw_rd_pattern_rules() as $rule ) {
		if ( 'exact' !== $rule['match_type'] && daw_rd_rule_matches( $rule, $path ) ) {
			daw_rd_execute( $rule, $path );
			return;
		}
	}
}

/* ============================================================ writing === */

/**
 * Add one redirect. Exact sources are normalised; a chain is collapsed so a
 * visitor never takes two hops.
 *
 * @return int|false New row id.
 */
function daw_rd_add( $source, $target, $args = array() ) {
	if ( ! daw_rd_tables_ready() ) {
		return false;
	}

	global $wpdb;

	$args = wp_parse_args(
		$args,
		array(
			'match_type'  => 'exact',
			'ignore_case' => 1,
			'code'        => 301,
			'status'      => 'active',
			'force_live'  => 0,
			'hits'        => 0,
			'origin'      => 'manual',
			'note'        => '',
		)
	);

	$type = in_array( $args['match_type'], array( 'exact', 'start', 'end', 'contains', 'regex' ), true ) ? $args['match_type'] : 'exact';

	if ( 'exact' === $type ) {
		$source = daw_rd_norm( $source );
	}

	$target = trim( (string) $target );
	if ( '' !== $target && 0 === strpos( $target, home_url() ) ) {
		$target = (string) wp_parse_url( $target, PHP_URL_PATH );
	}

	if ( '' === $source || ( '' === $target && ! in_array( (int) $args['code'], array( 410, 451 ), true ) ) ) {
		return false;
	}
	if ( 'exact' === $type && '' !== $target && daw_rd_norm( $target ) === $source ) {
		return false; // A page pointing at itself.
	}
	// Never the home page for a lost page: Google reads that as a soft 404.
	// A page with no true successor answers 404 or 410 instead. Only an old
	// index file (/index.html) may lead home.
	if ( '' !== $target && '/' === daw_rd_norm( $target ) && ! preg_match( '#^/index\.(html?|php)/?$#', $source ) ) {
		return false;
	}

	// Anything that pointed at the new source now points at its target.
	if ( 'exact' === $type && '' !== $target ) {
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . daw_rd_table() . ' SET target = %s WHERE target = %s OR target = %s', $target, $source, untrailingslashit( $source ) ) ); // phpcs:ignore WordPress.DB
	}

	$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		daw_rd_table(),
		array(
			'source'      => $source,
			'match_type'  => $type,
			'ignore_case' => (int) $args['ignore_case'],
			'target'      => $target,
			'code'        => (int) $args['code'],
			'status'      => 'inactive' === $args['status'] ? 'inactive' : 'active',
			'force_live'  => (int) $args['force_live'],
			'hits'        => (int) $args['hits'],
			'origin'      => substr( (string) $args['origin'], 0, 20 ),
			'note'        => substr( (string) $args['note'], 0, 255 ),
			'created'     => current_time( 'mysql', true ),
		)
	);

	daw_rd_flush_cache();

	// A redirect for an address that was in the 404 log takes it out of the log.
	if ( $ok && 'exact' === $type ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . daw_nf_table() . ' WHERE path = %s OR path = %s', $source, untrailingslashit( $source ) ) ); // phpcs:ignore WordPress.DB
	}

	return $ok ? (int) $wpdb->insert_id : false;
}

/**
 * A published page whose address changes leaves a redirect behind.
 *
 * WordPress already remembers old slugs for posts, but only for posts, only
 * for the slug part, and invisibly. A row here is visible in DAW › SEO, works
 * for pages too, and survives the old-slug meta being cleaned up.
 */
add_action( 'post_updated', 'daw_rd_on_address_change', 10, 3 );
function daw_rd_on_address_change( $post_id, $after, $before ) {
	if ( 'publish' !== $before->post_status || 'publish' !== $after->post_status ) {
		return;
	}
	if ( ! in_array( $after->post_type, array( 'post', 'page' ), true ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( $before->post_name === $after->post_name && $before->post_parent === $after->post_parent ) {
		return;
	}

	$old = get_permalink( $before );
	$new = get_permalink( $after );

	if ( $old && $new && daw_rd_norm( $old ) !== daw_rd_norm( $new ) ) {
		daw_rd_add( $old, $new, array( 'origin' => 'auto', 'note' => 'Adres değişti: #' . (int) $post_id ) );
	}
}

/* ============================================================ 404 log === */

/**
 * Who is asking: a person, a search engine, or any other machine.
 *
 * @return string human|search|bot
 */
function daw_nf_visitor( $ua ) {
	$ua = (string) $ua;

	if ( '' === trim( $ua ) ) {
		return 'bot';
	}
	if ( preg_match( '/Googlebot|Google-InspectionTool|Storebot-Google|GoogleOther|bingbot|YandexBot|YandexImages|Applebot|DuckDuckBot|Baiduspider|Yeti|SeznamBot|Qwantify/i', $ua ) ) {
		return 'search';
	}
	if ( preg_match( '/bot\b|bot\/|crawl|spider|slurp|fetch|scan|curl|wget|python|httpclient|http-client|okhttp|go-http|java\/|libwww|perl|ruby|php\/|headless|phantom|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|preview|facebookexternalhit|whatsapp|telegram|discord|slack|embedly|ahrefs|semrush|mj12|dotbot|petal|bytespider|gptbot|chatgpt|claude|anthropic|ccbot|perplexity|amazonbot|dataforseo|serpstat|seokicks|blexbot|barkrowler|zoominfo|daw-seo-compare|axios|node-fetch|undici|postman|insomnia/i', $ua ) ) {
		return 'bot';
	}

	return 'human';
}

/** page, asset or probe, from the address alone. */
function daw_nf_kind( $path ) {
	$p = strtolower( (string) $path );
	// The home page itself is never a lost page: a 404 there comes from a
	// query string (?p=, ?page_id=) that pointed nowhere.
	if ( '' === $p || '/' === $p ) {
		return 'probe';
	}
	// Anything under the WordPress folders is a file, never a reader's page,
	// even when a crawler glued junk to it (7 Oct 2026:
	// /wp-content/uploads/…/Brave-Citizen-8.jpg&lr=21206 sat as an open 404).
	if ( preg_match( '#^/wp-(content|includes)/#', $p ) ) {
		return 'asset';
	}
	// Hosting and mail panels other servers have (7 Oct 2026: /webmail/).
	if ( preg_match( '#^/(webmail|roundcube|squirrelmail|rainloop|horde|sogo|zimbra|mail|email|cpanel|whm|plesk|webdisk|cpcalendars|cpcontacts|directadmin|ispconfig|panel|server-status|server-info)(/|$)#', $p ) ) {
		return 'probe';
	}

	if ( preg_match( '#\.(php\d?|phtml|env|asp|aspx|jsp|cgi|pl|sql|bak|old|orig|swp|zip|tar|gz|rar|7z|git|svn|ini|log|yml|yaml|config|conf|htaccess|htpasswd|ds_store)(/|$)#', $p )
		|| preg_match( '#(^|/)\.?(wp-admin|wp-login|wp-includes|xmlrpc|cgi-bin|\.well-known|\.git|\.env|vendor|phpmyadmin|pma|admin|administrator|boaform|actuator|owa|ecp|autodiscover|getcmd|shell|webmcp)(/|$|\.)#', $p ) ) {
		return 'probe';
	}
	// Scanners looking for a forgotten copy of some other site: a one-word
	// path such as /oldsite, /backup, /2019 or /shop, a root .htm/.html page
	// (this site has none), media hashes, and the routes Chinese CMS scanners
	// try (/index/login/register, /api/site/getInfo.do). Not a reader's lost
	// page, so not an open problem (5 Oct 2026: 39 of 40 open 404s).
	if ( preg_match( '#^/(old-?site|wp-?old|old|new|backup|bak|bk|bc|demo|test|www|main|site|sitio|sito|shop|store|vip|home|new-?site|v1|v2|20[0-3][0-9])/?$#', $p )
		|| preg_match( '#^/[^/]+\.(html?|do)$#', $p )
		|| preg_match( '#^/(index|api|home|mobile|m|h5|app)/[a-z/]+(\.(html?|do|php))?$#', $p )
		|| preg_match( '#^/[0-9a-f-]{20,}(\.[a-z0-9]{2,4})?$#', $p ) ) {
		return 'probe';
	}
	// More scanner shapes (6 Oct 2026, all 11 open 404s): API and GraphQL
	// endpoints, Cloudflare's trace, key files, path traversal, upload
	// folders of other CMSs, old Elementor library pages, and random tokens
	// such as /aaa9 or /mlkzu35nstfja3vf61yz. None is a page a reader lost.
	if ( preg_match( '#(^|/)(graphql|cdn-cgi|\.ssh|\.aws|\.docker|proc/self|userfiles|fckeditor|ckeditor|kcfinder|elfinder)(/|$)#', $p )
		|| false !== strpos( $p, '../' )
		|| preg_match( '#^/elementor-\d+/?$#', $p )
		|| preg_match( '#^/[a-z]{3}\d{1,2}/?$#', $p )
		|| ( preg_match( '#^/[a-z0-9]{16,}/?$#', $p ) && preg_match( '#\d#', $p ) && ! preg_match( '#-#', $p ) ) ) {
		return 'probe';
	}
	if ( preg_match( '#\.(woff2?|ttf|otf|eot|css|js|map|jpe?g|png|gif|webp|avif|svg|ico|bmp|mp4|webm|mp3|mpe?g|ogg|flac|wav|avi|mov|mkv|pdf|txt|xml|json|xsl)$#', $p ) ) {
		return 'asset';
	}

	return 'page';
}

/** Rows logged before a pattern was known are sorted again, once per rule set. */
add_action( 'admin_init', 'daw_nf_reclassify' );
add_action( 'daw_fix_tick', 'daw_nf_reclassify', 3 );
function daw_nf_reclassify() {
	if ( '5' === (string) get_option( 'daw_nf_kind_v' ) || ! function_exists( 'daw_rd_tables_ready' ) || ! daw_rd_tables_ready() ) {
		return;
	}
	update_option( 'daw_nf_kind_v', '5', false );
	global $wpdb;
	foreach ( (array) $wpdb->get_results( 'SELECT id, path FROM ' . daw_nf_table() . " WHERE kind = 'page'", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
		$k = daw_nf_kind( $r['path'] );
		if ( 'page' !== $k ) {
			$wpdb->update( daw_nf_table(), array( 'kind' => $k ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB
		}
	}
}

/**
 * Which logged 404s count as an open problem on the control centre.
 *
 * A row counts when there is evidence a reader or Google will meet it again:
 * Google asked for it, another page links to it, or people asked for it more
 * than once. A single visit with no referrer is someone (or something with a
 * browser name) typing a guess; it stays in the 404 log for the record but
 * no longer turns the SEO card yellow. Until 1.75.22 every new scanner shape
 * did exactly that, and the fix was another pattern the next morning.
 *
 * @param string $a Table alias, without the dot.
 */
function daw_nf_open_sql( $a = 'n' ) {
	$a = preg_replace( '/[^a-z]/', '', (string) $a );
	return "( {$a}.hits_search > 0 OR {$a}.last_ref <> '' OR {$a}.hits_human >= 2 )";
}

/**
 * An upload address with junk glued after the extension
 * (/wp-content/uploads/2024/10/x.jpg&lr=21206) goes to the file itself when
 * that file exists. Nothing is written to the database.
 */
add_action( 'template_redirect', 'daw_nf_upload_junk', 1 );
function daw_nf_upload_junk() {
	if ( ! is_404() || is_admin() ) {
		return;
	}
	$path = daw_rd_request_path();
	if ( ! preg_match( '#^(/wp-content/uploads/[^&;,?\s]+?\.(?:jpe?g|png|gif|webp|avif|svg|pdf|mp4|webm))[&;,\s].*$#i', $path, $m ) || false !== strpos( $m[1], '..' ) ) {
		return;
	}
	$up   = wp_get_upload_dir();
	$base = (string) wp_parse_url( (string) $up['baseurl'], PHP_URL_PATH );
	if ( '' === $base || 0 !== strpos( $m[1], $base . '/' ) || ! is_file( $up['basedir'] . substr( $m[1], strlen( $base ) ) ) ) {
		return;
	}
	wp_safe_redirect( home_url( $m[1] ), 301, 'DAW' );
	exit;
}

add_action( 'template_redirect', 'daw_nf_log', 99 );
function daw_nf_log() {
	if ( ! is_404() || is_admin() || ! daw_rd_tables_ready() || wp_doing_ajax() ) {
		return;
	}

	$path = daw_rd_request_path();
	$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 255 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$who  = daw_nf_visitor( $ua );
	$kind = daw_nf_kind( $path );

	// Not stored, not even counted: a scanner can ask for a thousand files a
	// minute, and a database write for each one is the scanner winning.
	if ( 'bot' === $who || 'probe' === $kind ) {
		return;
	}

	global $wpdb;

	$ref = isset( $_SERVER['HTTP_REFERER'] ) ? substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 512 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$now = current_time( 'mysql', true );
	$col = 'search' === $who ? 'hits_search' : 'hits_human';

	$wpdb->query( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			'INSERT INTO ' . daw_nf_table() . " (path, path_hash, kind, {$col}, first_seen, last_seen, last_ref, last_ua) VALUES (%s, %s, %s, 1, %s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE {$col} = {$col} + 1, last_seen = VALUES(last_seen), last_ref = IF(VALUES(last_ref) = '', last_ref, VALUES(last_ref)), last_ua = VALUES(last_ua)",
			substr( $path, 0, 512 ),
			md5( $path ),
			$kind,
			$now,
			$now,
			$ref,
			$ua
		)
	);
}

/** Once a day: rows nobody has asked for in ninety days, with few hits, go. */
add_action( 'init', 'daw_nf_schedule' );
function daw_nf_schedule() {
	if ( ! wp_next_scheduled( 'daw_nf_prune' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'daw_nf_prune' );
	}
}

add_action( 'daw_nf_prune', 'daw_nf_prune_run' );
function daw_nf_prune_run() {
	if ( ! daw_rd_tables_ready() ) {
		return;
	}

	global $wpdb;

	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . daw_nf_table() . ' WHERE last_seen < %s AND hits_human + hits_search < 5', gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
}

/**
 * Where a missing address most likely meant to go.
 *
 * In order: an address with junk on the end ("/kingdom/[", "/namkoong-min/null")
 * whose parent exists; a published post or page with the same last segment;
 * a slug WordPress remembers as an old one; then the closest published slug
 * that starts with the same words.
 *
 * "auto" is true when the match is certain enough to apply without a person
 * looking: a junk ending on a real page, the same slug under another path,
 * a slug WordPress remembers as this page's old one, or a near-identical slug.
 *
 * @return array{url: string, why: string, auto: bool}|null
 */
function daw_nf_suggest( $path ) {
	global $wpdb;

	$path  = '/' . trim( (string) $path, '/' );
	$parts = array_values( array_filter( explode( '/', $path ), 'strlen' ) );

	if ( ! $parts ) {
		return null;
	}

	$last = strtolower( (string) end( $parts ) );

	if ( count( $parts ) > 1 && ( preg_match( '/^(null|undefined|nan|\[|\]|%5b|%5d|\{|\}|object|true|false)$/i', $last ) || '' === trim( $last, '[]{}()<>"\'' ) ) ) {
		$parent = '/' . implode( '/', array_slice( $parts, 0, -1 ) ) . '/';
		$id     = url_to_postid( home_url( $parent ) );
		if ( $id ) {
			return array( 'url' => (string) get_permalink( $id ), 'why' => 'Sonu bozuk adres; üst sayfa var', 'auto' => true );
		}
	}

	$slug = sanitize_title( $last );

	if ( '' === $slug ) {
		return null;
	}

	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' AND post_type IN ('post','page') LIMIT 1", $slug ) ); // phpcs:ignore WordPress.DB
	if ( $id ) {
		return array( 'url' => (string) get_permalink( $id ), 'why' => 'Aynı kısa ad', 'auto' => true );
	}

	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_wp_old_slug' AND m.meta_value = %s AND p.post_status = 'publish' LIMIT 1", $slug ) ); // phpcs:ignore WordPress.DB
	if ( $id ) {
		return array( 'url' => (string) get_permalink( $id ), 'why' => 'Eski kısa adı', 'auto' => true );
	}

	$words = array_slice( explode( '-', $slug ), 0, 2 );
	if ( count( $words ) < 2 || strlen( implode( '-', $words ) ) < 6 ) {
		return null;
	}

	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_name LIKE %s AND post_status = 'publish' AND post_type IN ('post','page') LIMIT 20", $wpdb->esc_like( implode( '-', $words ) ) . '%' ) ); // phpcs:ignore WordPress.DB

	$best  = null;
	$score = 0;
	foreach ( (array) $rows as $row ) {
		similar_text( $slug, (string) $row->post_name, $pct );
		if ( $pct > $score ) {
			$score = $pct;
			$best  = $row;
		}
	}

	if ( $best && $score >= 55 ) {
		return array( 'url' => (string) get_permalink( (int) $best->ID ), 'why' => sprintf( 'Benzer kısa ad (%%%d)', (int) $score ), 'auto' => $score >= 85 );
	}

	return null;
}

/* ============================================================== import === */

/**
 * Bring Rank Math's redirections over, once, without touching its table.
 *
 * Every source of every row becomes a row here, with its comparison type,
 * code, status and hit count. An exact source that is a live page today is
 * marked force_live (Rank Math sent it away anyway) and listed in the report.
 *
 * @return array Report.
 */
function daw_rd_import_rank_math() {
	global $wpdb;

	$table  = $wpdb->prefix . 'rank_math_redirections';
	$report = array(
		'at'       => time(),
		'found'    => 0,
		'imported' => 0,
		'skipped'  => 0,
		'forced'   => array(),
		'rows'     => array(),
	);

	if ( ! daw_rd_tables_ready() ) {
		daw_rd_install();
	}

	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB
		$report['note'] = 'Rank Math tablosu yok';
		update_option( 'daw_rd_import_report', $report, false );
		update_option( 'daw_rd_imported', time(), false );
		return $report;
	}

	$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB

	foreach ( $rows as $row ) {
		++$report['found'];

		$sources = maybe_unserialize( $row['sources'] );
		$code    = (int) $row['header_code'];
		$target  = (string) $row['url_to'];
		$status  = 'active' === $row['status'] ? 'active' : 'inactive';

		foreach ( (array) $sources as $src ) {
			$pattern    = (string) ( $src['pattern'] ?? '' );
			$comparison = (string) ( $src['comparison'] ?? 'exact' );
			$ignore     = ( isset( $src['ignore'] ) && 'case' === $src['ignore'] ) ? 1 : 0;

			if ( '' === trim( $pattern ) ) {
				continue;
			}

			$type = in_array( $comparison, array( 'exact', 'start', 'end', 'contains', 'regex' ), true ) ? $comparison : 'exact';
			$key  = 'exact' === $type ? daw_rd_norm( $pattern ) : $pattern;

			$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . daw_rd_table() . ' WHERE source = %s AND match_type = %s LIMIT 1', $key, $type ) ); // phpcs:ignore WordPress.DB
			if ( $exists ) {
				++$report['skipped'];
				continue;
			}

			// Rank Math checked every rule on every request, not only on a
			// 404: its feed rules, for one, redirect addresses WordPress
			// would otherwise answer. Imported rules keep that behaviour.
			$force = 'active' === $status ? 1 : 0;
			if ( 'exact' === $type && 'active' === $status ) {
				$live_id = url_to_postid( home_url( $key ) );
				if ( $live_id && 'publish' === get_post_status( $live_id ) ) {
					$report['forced'][] = array( 'source' => $key, 'target' => $target, 'post' => $live_id );
				}
			}

			$id = daw_rd_add(
				'exact' === $type ? $key : $pattern,
				$target,
				array(
					'match_type'  => $type,
					'ignore_case' => 'exact' === $type ? 1 : $ignore,
					'code'        => $code ? $code : 301,
					'status'      => $status,
					'force_live'  => $force,
					'hits'        => (int) ( $row['hits'] ?? 0 ),
					'origin'      => 'rankmath',
					'note'        => 'Rank Math #' . (int) $row['id'],
				)
			);

			if ( $id ) {
				++$report['imported'];
				$report['rows'][] = array( 'source' => $key, 'type' => $type, 'target' => $target, 'code' => $code, 'status' => $status );
			} else {
				++$report['skipped'];
			}
		}
	}

	update_option( 'daw_rd_import_report', $report, false );
	update_option( 'daw_rd_imported', time(), false );

	return $report;
}

/**
 * 1.51.0 imported Rank Math's rules as 404-only. Rank Math ran them on every
 * request, so the feed rules (^(.+)/feed/?$ and /feed/) stopped short of what
 * they did before. Once: every active imported rule runs like Rank Math's.
 */
add_action( 'admin_init', 'daw_rd_force_imported_v1', 41 );
function daw_rd_force_imported_v1() {
	if ( get_option( 'daw_rd_force_imported_v1' ) || ! current_user_can( 'manage_options' ) || ! daw_rd_tables_ready() ) {
		return;
	}

	global $wpdb;
	$n = $wpdb->query( 'UPDATE ' . daw_rd_table() . " SET force_live = 1 WHERE origin = 'rankmath' AND status = 'active'" ); // phpcs:ignore WordPress.DB
	daw_rd_flush_cache();
	update_option( 'daw_rd_force_imported_v1', array( 'when' => time(), 'rows' => (int) $n ), false );
}

/**
 * The import runs on its own, once, the first time an admin page loads after
 * this version is installed, while Rank Math's table is still there. Running
 * it again from DAW › SEO only adds rows that are missing.
 */
add_action( 'admin_init', 'daw_rd_auto_import', 40 );
function daw_rd_auto_import() {
	if ( get_option( 'daw_rd_imported' ) || ! current_user_can( 'manage_options' ) || ! daw_rd_tables_ready() ) {
		return;
	}

	daw_rd_import_rank_math();
}

/* ============================================================ auto-fix === */

/**
 * Every hour, the 404s that have a certain answer are answered.
 *
 * A missing address with a certain match (see daw_nf_suggest) gets its 301
 * on its own, is taken out of the log, and is listed under "otomatik" in
 * DAW › SEO › Yönlendirmeler with the reason, so every decision the site made
 * by itself can be read and undone. An address that exists again (a page was
 * published at it) simply leaves the log. Uncertain ones wait for a person.
 */
add_action( 'init', 'daw_nf_autofix_schedule' );
function daw_nf_autofix_schedule() {
	if ( ! wp_next_scheduled( 'daw_nf_autofix' ) ) {
		wp_schedule_event( time() + 20 * MINUTE_IN_SECONDS, 'hourly', 'daw_nf_autofix' );
	}
}

/** Whether a path answers with a page on this site today. */
function daw_seo_path_exists( $path ) {
	$path = '/' . ltrim( (string) strtok( (string) $path, '?#' ), '/' );

	if ( '/' === $path ) {
		return true;
	}
	// Only an exact answer counts: url_to_postid() is generous with junk on
	// the end ("/kingdom/[" can resolve to Kingdom), and that address 404s.
	$id = url_to_postid( home_url( $path ) );
	if ( $id && 'publish' === get_post_status( $id ) && daw_rd_norm( (string) get_permalink( $id ) ) === daw_rd_norm( $path ) ) {
		return true;
	}

	$trim = trim( $path, '/' );
	if ( false === strpos( $trim, '/' ) && term_exists( $trim, 'category' ) ) {
		return true;
	}
	if ( preg_match( '#^([^/]+)/page/\d+$#', $trim, $m ) && term_exists( $m[1], 'category' ) ) {
		return true;
	}

	return false;
}

add_action( 'daw_nf_autofix', 'daw_nf_autofix_run' );
function daw_nf_autofix_run() {
	if ( ! daw_rd_tables_ready() || ! daw_eng_set( 'auto_404', true ) ) {
		return array();
	}

	global $wpdb;

	$rows = (array) $wpdb->get_results( 'SELECT id, path FROM ' . daw_nf_table() . " WHERE kind = 'page' ORDER BY (hits_human + hits_search) DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB
	$done = array();

	foreach ( $rows as $row ) {
		if ( daw_seo_path_exists( $row['path'] ) ) {
			$wpdb->delete( daw_nf_table(), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB
			continue;
		}

		$sug = daw_nf_suggest( $row['path'] );

		if ( ! $sug || empty( $sug['auto'] ) ) {
			continue;
		}

		$target = (string) wp_parse_url( $sug['url'], PHP_URL_PATH );

		if ( daw_rd_add( $row['path'], $target, array( 'origin' => 'auto', 'note' => 'Otomatik: ' . $sug['why'] ) ) ) {
			$done[] = array( $row['path'], $target, $sug['why'] );
		}
	}

	if ( $done ) {
		$log = array_merge( (array) get_option( 'daw_nf_autofix_log', array() ), array( array( 'at' => time(), 'rows' => $done ) ) );
		update_option( 'daw_nf_autofix_log', array_slice( $log, -30 ), false );
	}

	return $done;
}
