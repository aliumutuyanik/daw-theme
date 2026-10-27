<?php
/**
 * Discover Asian World: one place for every picture decision.
 *
 * WHY THIS EXISTS
 * ---------------
 * Picture settings had spread over Tools › DAW: TMDB posters, Settings ›
 * Reading and Tools › DAW: Image rights. The owner asked for one managed area
 * with two clear halves (23 Sep 2026):
 *
 *   DAW Images › TMDB                 every title, person, variety and anime
 *                                     picture: the API key, where pictures
 *                                     come from, the private backup, cleaning
 *                                     the media library.
 *   DAW Images › Commons & licensed   the rights register for news and guides
 *                                     (and any licensed photo), unchanged.
 *   DAW Images › News cards           the branded news card generator.
 *
 * THE PLAN IT SERVES
 * ------------------
 * Normal running: pictures come from TMDB's server, nothing is stored here in
 * the media library. As insurance, a private backup copy of each picture is
 * kept in a protected folder that is not in the media library and cannot be
 * opened from the web. If TMDB ever stops answering, one switch shows the
 * backup instead; another shows no pictures at all. Later, a paid TMDB
 * licence or studio press material can replace either without rebuilding
 * anything: a licensed local photo always wins when "TMDB first" is off.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_VAULT_META    = '_daw_vault';
const DAW_VAULT_AT      = '_daw_vault_at';
const DAW_VAULT_CRON    = 'daw_vault_cron';
const DAW_IMAGES_PARENT = 'daw-images';

/* ================================================================= menus === */

// The old screens are folded into this one.
remove_action( 'admin_menu', 'daw_tmdb_admin_menu' );
remove_action( 'admin_init', 'daw_tmdb_key_setting' );
remove_action( 'daw_lic_page_after', 'daw_tmdb_hotlink_panel', 5 );

add_action( 'admin_menu', 'daw_images_menu', 9 );
function daw_images_menu() {
	add_menu_page( 'DAW', 'DAW', 'manage_options', 'daw', 'daw_home_page', 'dashicons-format-image', 3 );
	add_submenu_page( 'daw', 'Kontrol Merkezi', 'Kontrol Merkezi', 'manage_options', 'daw', 'daw_home_page' );
	add_submenu_page( 'daw', 'Görseller', 'Görseller', 'manage_options', DAW_IMAGES_PARENT, 'daw_images_tmdb_page' );
}

/** Old addresses (bookmarks, the dashboard) land on the new screens. */
add_action( 'admin_init', 'daw_images_old_urls' );
function daw_images_old_urls() {
	global $pagenow;
	if ( 'tools.php' !== $pagenow || empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$map  = array(
		'daw-tmdb'         => DAW_IMAGES_PARENT,
		'daw-image-rights' => 'daw-image-rights',
		'daw-cards'        => 'daw-cards',
	);
	$page = sanitize_key( $_GET['page'] ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! isset( $map[ $page ] ) ) {
		return;
	}
	$args         = array_map( 'sanitize_text_field', wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$args['page'] = $map[ $page ];
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	exit;
}

function daw_images_url( $page = DAW_IMAGES_PARENT, $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
}

/* ===================================================== bearer token shim === */

/**
 * Every older caller (tmdb-fill, tmdb-match) sends the credential as
 * ?api_key=. When the owner saved the long Read Access Token instead, that
 * parameter is taken off and the token goes in the Authorization header,
 * which is how TMDB expects it. One place, so no caller has to know.
 */
add_filter( 'pre_http_request', 'daw_tmdb_bearer_shim', 10, 3 );
function daw_tmdb_bearer_shim( $pre, $args, $url ) {
	if ( false !== $pre || 0 !== strpos( $url, 'https://api.themoviedb.org/' ) ) {
		return $pre;
	}
	$q = array();
	wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	if ( empty( $q['api_key'] ) || 0 !== strpos( $q['api_key'], 'eyJ' ) ) {
		return $pre;
	}
	$token = $q['api_key'];
	$url   = remove_query_arg( 'api_key', $url );
	$args['headers']                  = isset( $args['headers'] ) ? (array) $args['headers'] : array();
	$args['headers']['Authorization'] = 'Bearer ' . $token;
	return wp_remote_request( $url, $args );
}

/* ================================================================= vault === */

/**
 * The private backup folder: inside uploads so the host always allows
 * writing there, under a name nobody can guess, with a rule that refuses
 * every web request. It is not the media library: nothing here is an
 * attachment, nothing is listed anywhere.
 */
function daw_vault_dir() {
	static $dir = null;
	if ( null !== $dir ) {
		return $dir;
	}
	$up  = wp_upload_dir( null, false );
	$dir = trailingslashit( $up['basedir'] ) . 'daw-vault-' . substr( wp_hash( 'daw-vault' ), 0, 12 );
	foreach ( array( '', '/tmdb', '/legacy' ) as $sub ) {
		if ( ! is_dir( $dir . $sub ) ) {
			wp_mkdir_p( $dir . $sub );
		}
		if ( ! file_exists( $dir . $sub . '/index.php' ) ) {
			@file_put_contents( $dir . $sub . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore
		}
	}
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		@file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
	}
	return $dir;
}

/** The backup file (relative to the vault) for a record, or '' if none. */
function daw_vault_file_for( $post_id ) {
	static $memo = array();
	$post_id = (int) $post_id;
	if ( isset( $memo[ $post_id ] ) ) {
		return $memo[ $post_id ];
	}
	$rel = (string) get_post_meta( $post_id, DAW_VAULT_META, true );
	if ( '' !== $rel && ( ! preg_match( '#^tmdb/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)$#i', $rel ) || ! file_exists( daw_vault_dir() . '/' . $rel ) ) ) {
		$rel = '';
	}
	$memo[ $post_id ] = $rel;
	return $rel;
}

/** Where the page asks for a backup picture (only answered in emergency mode). */
function daw_vault_url( $post_id ) {
	$rel = daw_vault_file_for( $post_id );
	if ( '' === $rel ) {
		return '';
	}
	return add_query_arg(
		array(
			'daw_vault' => (int) $post_id,
			'v'         => substr( md5( $rel ), 0, 8 ),
		),
		home_url( '/' )
	);
}

/** Copy TMDB's picture for one record into the vault. */
function daw_vault_backup_one( $post_id ) {
	$post_id = (int) $post_id;
	$path    = function_exists( 'daw_tmdb_img_path' ) ? daw_tmdb_img_path( $post_id ) : '';
	if ( '' === $path ) {
		return false;
	}
	$rel  = 'tmdb/' . ltrim( $path, '/' );
	$file = daw_vault_dir() . '/' . $rel;

	if ( ! file_exists( $file ) ) {
		$person = in_array( daw_get_type( $post_id ), array( 'person', 'group' ), true );
		$res    = wp_remote_get(
			'https://image.tmdb.org/t/p/' . ( $person ? 'h632' : 'w500' ) . $path,
			array(
				'timeout'    => 20,
				'user-agent' => 'DiscoverAsianWorld/1.0 (+https://discoverasianworld.com)',
			)
		);
		$type = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_header( $res, 'content-type' );
		$body = is_wp_error( $res ) ? '' : wp_remote_retrieve_body( $res );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res )
			|| 0 !== strpos( $type, 'image/' ) || strlen( $body ) < 1000 || strlen( $body ) > 3 * MB_IN_BYTES
			|| false === @file_put_contents( $file, $body ) ) { // phpcs:ignore
			// Remembered, so one bad record cannot block the queue for everyone.
			update_post_meta( $post_id, '_daw_vault_fail', time() );
			return false;
		}
	}

	update_post_meta( $post_id, DAW_VAULT_META, $rel );
	update_post_meta( $post_id, DAW_VAULT_AT, time() );
	delete_post_meta( $post_id, '_daw_vault_fail' );
	return true;
}

/** Confirmed records that have a TMDB picture but no backup yet (plain SQL, see the sync queue). */
function daw_vault_queue( $limit = 50 ) {
	global $wpdb;
	$sql = $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = %s AND i.meta_value <> ''
		 JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s AND c.meta_value = 'ok'
		 LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = %s
		 LEFT JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_daw_vault_fail'
		 WHERE p.post_type = 'post' AND p.post_status = 'publish'
		   AND v.meta_id IS NULL
		   AND ( f.meta_value IS NULL OR CAST( f.meta_value AS UNSIGNED ) < %d )
		 GROUP BY p.ID ORDER BY p.ID ASC LIMIT %d",
		DAW_TMDB_IMG,
		DAW_TMDB_CHECK,
		DAW_VAULT_META,
		time() - DAY_IN_SECONDS,
		max( 1, (int) $limit )
	);
	return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB
}

function daw_vault_run_batch() {
	$ok   = 0;
	$fail = 0;
	foreach ( daw_vault_queue( 50 ) as $id ) {
		if ( daw_vault_backup_one( $id ) ) {
			$ok++;
		} else {
			$fail++;
		}
	}
	return array( $ok, $fail );
}

function daw_vault_auto() {
	return (bool) get_option( 'daw_vault_auto', 1 );
}

add_action( 'init', 'daw_vault_schedule' );
function daw_vault_schedule() {
	if ( ! wp_next_scheduled( DAW_VAULT_CRON ) ) {
		wp_schedule_event( time() + 900, 'hourly', DAW_VAULT_CRON );
	}
}

add_action( DAW_VAULT_CRON, 'daw_vault_cron_run' );
function daw_vault_cron_run() {
	if ( daw_vault_auto() && 'off' !== daw_img_source() ) {
		daw_vault_run_batch();
		// Records confirmed later (after their details were filled in) get
		// their old file tidied away too, a few at a time, once the one-button
		// job has finished its own run.
		if ( 'tmdb' === daw_img_source() && function_exists( 'daw_img_job_get' ) ) {
			$j = daw_img_job_get();
			if ( 'done' === ( $j['state'] ?? '' ) ) {
				$left = array_values( array_diff( daw_clean_candidates(), array_map( 'intval', array_keys( (array) ( $j['skipped'] ?? array() ) ) ) ) );
				foreach ( array_slice( $left, 0, 20 ) as $att ) {
					$why = daw_clean_one( $att );
					if ( '' === $why ) {
						$j['n']['removed'] = (int) ( $j['n']['removed'] ?? 0 ) + 1;
					} else {
						$j['skipped'][ $att ] = $why;
					}
				}
				daw_img_job_put( $j );
			}
		}
	}
}

/** When TMDB gives a record a new picture, the old backup no longer matches. */
add_action( 'added_post_meta', 'daw_vault_path_changed', 10, 4 );
add_action( 'updated_post_meta', 'daw_vault_path_changed', 10, 4 );
add_action( 'deleted_post_meta', 'daw_vault_path_changed', 10, 4 );
function daw_vault_path_changed( $meta_id, $post_id, $key, $value ) {
	if ( DAW_TMDB_IMG !== $key ) {
		return;
	}
	$rel = (string) get_post_meta( $post_id, DAW_VAULT_META, true );
	if ( '' === $rel ) {
		return;
	}
	$now = 'deleted_post_meta' === current_action() ? '' : 'tmdb/' . ltrim( (string) $value, '/' );
	if ( $now !== $rel ) {
		delete_post_meta( $post_id, DAW_VAULT_META );
	}
}

/**
 * Emergency mode only: hand out a backup picture. In every other mode this
 * answers 404, so the backup is never public while TMDB works.
 */
add_action( 'init', 'daw_vault_serve', 1 );
function daw_vault_serve() {
	if ( ! isset( $_GET['daw_vault'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$id  = (int) $_GET['daw_vault']; // phpcs:ignore WordPress.Security.NonceVerification
	$rel = 'vault' === daw_img_source() && 'publish' === get_post_status( $id ) ? daw_vault_file_for( $id ) : '';
	if ( '' === $rel ) {
		// Not cached, so switching emergency mode on takes effect at once.
		nocache_headers();
		status_header( 404 );
		exit;
	}
	$file = daw_vault_dir() . '/' . $rel;
	$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
	$mime = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
	);
	header( 'Content-Type: ' . ( $mime[ $ext ] ?? 'application/octet-stream' ) );
	header( 'Content-Length: ' . filesize( $file ) );
	header( 'Cache-Control: public, max-age=86400' );
	header( 'X-Robots-Tag: noindex' );
	readfile( $file ); // phpcs:ignore
	exit;
}

/* ========================================================= library clean === */

/**
 * Media library files that the TMDB plan makes unnecessary: copies of TMDB
 * files, pictures already detached from their record, and the unlicensed
 * featured image of any title or person that now has a TMDB picture. A
 * licensed file (Commons, KOGL, own) is never on this list.
 */
function daw_clean_candidates() {
	global $wpdb;
	$ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_daw_source' AND meta_value = 'TMDB'" ) ); // phpcs:ignore WordPress.DB
	$ids = array_merge( $ids, daw_remote_detached_attachments() );

	// A licensed photo (Commons) on a title or person that now shows TMDB's
	// picture is not shown anywhere either (owner, 24 Sep 2026: titles and
	// people come from the API; Commons stays for news and guides).
	$replaced = array();
	$records  = function_exists( 'daw_audit_record_ids' ) ? daw_audit_record_ids( daw_remote_types() ) : array();
	foreach ( $records as $rid ) {
		$att = (int) get_post_thumbnail_id( $rid );
		if ( ! $att ) {
			continue;
		}
		$has = '' !== daw_tmdb_img_path( $rid );
		if ( $has || daw_remote_strict() ) {
			$ids[] = $att;
		}
		if ( $has && daw_remote_tmdb_first() ) {
			$replaced[ $att ] = true;
		}
	}

	$out = array();
	foreach ( array_unique( array_filter( $ids ) ) as $att ) {
		if ( 'attachment' !== get_post_type( $att ) ) {
			continue;
		}
		// Anything with an entry in the rights register is left alone (even an
		// incomplete one: a licensed file waiting for its details), unless TMDB
		// has taken its place on its record. The blocker still stops it if a
		// news story or guide uses it.
		if ( function_exists( 'daw_lic_get' ) && daw_lic_get( $att ) && empty( $replaced[ $att ] ) ) {
			continue;
		}
		$out[] = (int) $att;
	}
	sort( $out );
	return $out;
}

/**
 * Why a file may not be removed yet, or '' when it can go. Checked one file
 * at a time, at the moment of removal.
 */
function daw_clean_blocker( $att ) {
	global $wpdb;
	$att = (int) $att;

	// Site icon, logo and the other files the site itself depends on.
	$protected = function_exists( 'daw_media_protected' ) ? daw_media_protected() : array();
	$protected[] = (int) get_option( 'daw_logo_attachment_id' );
	if ( in_array( $att, array_map( 'intval', $protected ), true ) ) {
		return 'sitenin kendisi kullanıyor (logo ya da simge)';
	}

	// Featured image (now or before a detach) of something that would lose its
	// picture: every user must be a title or person with a TMDB picture.
	foreach ( daw_clean_users( $att ) as $pid ) {
		if ( ! in_array( daw_get_type( $pid ), daw_remote_types(), true ) ) {
			return sprintf( '%d numaralı yazının görseli (dizi, film ya da kişi değil)', $pid );
		}
		// With strict on, a record without a confirmed TMDB picture shows the
		// monogram, so its old file is not used and can go to the backup too.
		if ( '' === daw_tmdb_img_path( $pid ) && ! daw_remote_strict() ) {
			return sprintf( '%d numaralı kaydın görseli ve o kaydın TMDB görseli yok', $pid );
		}
	}

	// Used inside any text: the class WordPress writes, a gallery shortcode,
	// or the file name in any size (a "-scaled" upload is matched by its
	// original stem, which covers every resized copy too).
	// Matched by folder and name ("2024/03/Doona"), not by the bare name: a file
	// called after its drama ("Doona") would otherwise match every text that
	// mentions the drama (found on the live run, 24 Sep 2026).
	$file = (string) get_post_meta( $att, '_wp_attached_file', true );
	$stem = preg_replace( '/(-scaled)?\.[a-z0-9]+$/i', '', $file );
	$like = false !== strpos( $stem, '/' ) ? '%' . $wpdb->esc_like( $stem ) . '%' : '%wp-image-' . $att . '"%';
	$in_ids = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_status NOT IN ('auto-draft','inherit','trash') AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s ) LIMIT 50",
			'%wp-image-' . $att . '"%',
			'%wp-image-' . $att . ' %',
			'%ids="' . $att . ',%',
			'%ids="' . $att . '"%',
			'%,' . $att . ',%',
			'%,' . $att . '"%',
			$like
		)
	);
	foreach ( $in_ids as $pid ) {
		if ( daw_clean_body_shown( (int) $pid ) ) {
			return sprintf( '%d numaralı yazının metninde kullanılıyor', (int) $pid );
		}
	}

	// Referenced by any other field: a gallery, a social image, Elementor
	// data ("id":123), or the file's own url.
	$meta = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			"SELECT CONCAT(m.post_id, ' / ', m.meta_key) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.post_id <> %d AND p.post_type NOT IN ('revision') AND p.post_status NOT IN ('auto-draft','trash') AND m.meta_key NOT IN ('_thumbnail_id', %s, '_wp_attachment_metadata', '_wp_attached_file') AND ( m.meta_value = %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s ) LIMIT 1",
			$att,
			DAW_TMDB_PREV,
			(string) $att,
			'%i:' . $att . ';%',
			'%"' . $att . '"%',
			'%"id":' . $att . ',%',
			'%"id":' . $att . '}%',
			$like
		)
	);
	if ( '' !== $meta ) {
		return 'başka bir alanda kullanılıyor (yazı / alan: ' . $meta . ')';
	}

	// Options and term meta (a hub banner, a theme setting).
	$opt = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			"SELECT ( SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND ( option_value = %s OR option_value LIKE %s OR option_value LIKE %s ) ) + ( SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_value = %s OR meta_value LIKE %s )",
			'%transient%',
			(string) $att,
			'%i:' . $att . ';%',
			$like,
			(string) $att,
			$like
		)
	);
	if ( $opt ) {
		return 'bir ayarda ya da hub sayfasında kullanılıyor';
	}
	return '';
}

/**
 * Posts that show this file, or showed it before a detach. Revisions and
 * auto-drafts are left out: WordPress copies _thumbnail_id into every
 * revision, and a revision is never shown (the live run counted hundreds).
 */
function daw_clean_users( $att ) {
	global $wpdb;
	return array_map(
		'intval',
		(array) $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key IN ('_thumbnail_id', %s) AND m.meta_value = %s
				   AND p.post_type NOT IN ('revision','attachment') AND p.post_status NOT IN ('auto-draft','inherit','trash')",
				DAW_TMDB_PREV,
				(string) (int) $att
			)
		)
	);
}

/**
 * Is this post's body ever printed? Title and person pages are drawn from
 * their fields; their post_content is an old page-builder leftover that no
 * template shows (group pages do print theirs).
 */
function daw_clean_body_shown( $post_id ) {
	$type = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
	return ! in_array( $type, array( 'drama', 'film', 'variety', 'anime', 'person' ), true );
}

/**
 * Keep a copy in the vault, unhook it from its record, then remove it from
 * the media library. Returns '' on success or the reason it was kept.
 */
function daw_clean_one( $att ) {
	$att     = (int) $att;
	$blocker = daw_clean_blocker( $att );
	if ( '' !== $blocker ) {
		return $blocker;
	}
	// Every record that loses this file must already have its TMDB picture in
	// the backup, so the emergency switch still has something to show.
	$users = daw_clean_users( $att );
	foreach ( $users as $pid ) {
		if ( '' !== daw_tmdb_img_path( $pid ) && '' === daw_vault_file_for( $pid ) && ! daw_vault_backup_one( $pid ) ) {
			return sprintf( '%d numaralı kaydın TMDB görselinin yedeği henüz alınamadı', $pid );
		}
	}

	// The file and, for a "-scaled" upload, the original it was made from.
	$src   = get_attached_file( $att );
	$files = array( $src );
	if ( function_exists( 'wp_get_original_image_path' ) ) {
		$files[] = wp_get_original_image_path( $att );
	}
	foreach ( array_unique( array_filter( $files ) ) as $f ) {
		if ( ! file_exists( $f ) ) {
			continue;
		}
		$dest = daw_vault_dir() . '/legacy/' . $att . '-' . sanitize_file_name( wp_basename( $f ) );
		if ( ! file_exists( $dest ) && ! @copy( $f, $dest ) ) { // phpcs:ignore
			return 'yedek klasörüne kopyalanamadı';
		}
	}

	global $wpdb;
	foreach ( $users as $pid ) {
		if ( (int) get_post_thumbnail_id( $pid ) === $att ) {
			delete_post_meta( $pid, '_thumbnail_id' );
		}
	}
	// The detached-from record no longer points at a file that is gone.
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => DAW_TMDB_PREV, 'meta_value' => (string) $att ) ); // phpcs:ignore WordPress.DB
	wp_delete_attachment( $att, true );

	$log   = (array) get_option( 'daw_clean_log', array() );
	$log[] = array( $att, wp_basename( (string) $src ), time() );
	update_option( 'daw_clean_log', array_slice( $log, -2000 ), false );
	return '';
}

/* ================================================================ screen === */

function daw_images_tmdb_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$key    = daw_tmdb_key();
	$kind   = '' === $key ? 'anahtar girilmedi' : ( 0 === strpos( $key, 'eyJ' ) ? 'Read Access Token kayıtlı' : 'API anahtarı kayıtlı' );
	$source = daw_img_source();
	$c      = daw_remote_census();
	$form   = function ( $op, $inner, $confirm = '' ) {
		printf(
			'<form method="post" action="%s" style="display:inline-block;margin:0 6px 6px 0"%s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\')"' : ''
		);
		wp_nonce_field( 'daw_hub' );
		echo '<input type="hidden" name="action" value="daw_hub"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</form>';
	};

	echo '<div class="wrap"><h1>DAW › Görseller</h1>';
	echo '<p style="max-width:62em">Bütün dizi, film, varyete, anime ve kişi görselleri. Normalde TMDB\'nin sunucusundan gösterilir, medya kütüphanesinde hiçbir şey durmaz. Acil durum için gizli bir yedek tutulur. Haber ve rehber görselleri (Commons, KOGL, sitenin kendi kartları) <a href="' . esc_url( daw_images_url( 'daw-image-rights' ) ) . '">Commons &amp; lisanslı</a> ekranındadır.</p>';

	// At a glance.
	$rep = function_exists( 'daw_img_report' ) ? daw_img_report() : array();
	$bad = 0;
	foreach ( $rep as $g ) {
		$bad += $g['problem'] ? count( $g['rows'] ) : 0;
	}
	printf(
		'<div style="max-width:62em;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;font-size:14px;line-height:1.7"><strong>Şu an:</strong> TMDB\'den gösterilen <strong>%d</strong> · kendi lisanslı görselimiz <strong>%d</strong> · gizli yedekte <strong>%d</strong> · yapılamayan <strong>%d</strong> (liste aşağıdaki raporda)<br>%s</div>',
		count( $rep['remote']['rows'] ?? array() ),
		count( $rep['local']['rows'] ?? array() ),
		(int) ( $c['backed_up'] ?? 0 ),
		$bad,
		esc_html( function_exists( 'daw_tmdb_usage_line' ) ? daw_tmdb_usage_line() : '' )
	);

	// 1. Connection: open when there is no key, folded away once there is.
	printf( '<details id="daw-conn" style="margin:14px 0"%s><summary><strong>TMDB bağlantısı:</strong> %s</summary>', '' === $key ? ' open' : '', esc_html( $kind ) );
	$form( 'key', '<input type="password" name="key" value="" placeholder="' . esc_attr( '' === $key ? 'Paste the API key or API Read Access Token' : 'Saved. Paste a new one to replace it' ) . '" class="regular-text" autocomplete="off" spellcheck="false"> ' . get_submit_button( 'Save', 'primary', 'go', false ) );
	$form( 'test', get_submit_button( 'Test connection', 'secondary', 'go', false ) );
	echo '<p class="description">Anahtar sadece bu sunucuda kullanılır, hiçbir sayfada görünmez.</p></details>';

	// 2. The one button.
	if ( function_exists( 'daw_img_job_panel' ) ) {
		daw_img_job_panel();
	}

	echo '<details style="margin-top:18px"><summary><strong>Gelişmiş</strong>: görsel kaynağı (acil durum anahtarı), ayrıntılı sayılar, elle çalıştırma</summary>';

	// Source.
	echo '<h2>Görseller nereden gelsin</h2><p class="description">Başlat düğmesi bunu kendisi TMDB yapar. Acil durumda buradan "Backup" seçilir.</p>';
	$opts = array(
		'tmdb'  => 'TMDB (normal): shown from TMDB\'s server, nothing stored in the media library',
		'vault' => 'Backup (emergency): shown from the private backup on this server',
		'none'  => 'No pictures: the drawn monogram everywhere',
		'off'   => 'Off: pictures already in the media library are shown; nothing new is ever downloaded',
	);
	$inner = '';
	foreach ( $opts as $k => $label ) {
		$inner .= sprintf( '<p><label><input type="radio" name="source" value="%s" %s> %s</label></p>', esc_attr( $k ), checked( $source, $k, false ), esc_html( $label ) );
	}
	$inner .= sprintf( '<p><label><input type="checkbox" name="first" value="1" %s> TMDB first: use the TMDB picture even where a licensed photo (Commons) is on the record</label></p>', checked( daw_remote_tmdb_first(), true, false ) );
	$inner .= sprintf( '<p><label><input type="checkbox" name="strict" value="1" %s> Strict: never show a local picture whose rights are not recorded</label></p>', checked( daw_remote_strict(), true, false ) );
	$inner .= sprintf( '<p><label><input type="checkbox" name="auto" value="1" %s> Keep the private backup up to date automatically (50 per hour)</label></p>', checked( daw_vault_auto(), true, false ) );
	$inner .= get_submit_button( 'Save', 'primary', 'go', false );
	$form( 'source', $inner );

	// Status.
	echo '<h2>Ayrıntılı durum</h2><table class="widefat striped" style="max-width:44em"><tbody>';
	$rows = array(
		'total'        => 'Titles and people',
		'no_tmdb_id'   => 'No TMDB id on the record (fix the record)',
		'synced'       => 'TMDB checked',
		'with_path'    => 'TMDB has a picture',
		'backed_up'    => 'Picture in the private backup',
		'remote'       => 'Shown from TMDB now',
		'vault'        => 'Shown from the backup now',
		'local'        => 'Shown from here, rights recorded',
		'copy'         => 'Still using a media library copy of a TMDB file',
		'unregistered' => 'Still using a media library picture with no recorded rights',
		'monogram'     => 'Monogram',
		'none'         => 'No picture',
	);
	foreach ( $rows as $k => $label ) {
		printf( '<tr><td style="width:5em"><strong>%d</strong></td><td>%s</td></tr>', (int) ( $c[ $k ] ?? 0 ), esc_html( $label ) );
	}
	echo '</tbody></table>';

	// Manual controls, for when something needs a nudge.
	echo '<h2>Elle çalıştırma (normalde gerekmez)</h2><p>';
	$form( 'sync', get_submit_button( 'Check 50 TMDB pictures', 'secondary', 'go', false ) );
	$form( 'backup', get_submit_button( 'Back up 50 pictures', 'secondary', 'go', false ) );
	echo '</p>';

	// 5. Media library.
	$cand = daw_clean_candidates();
	echo '<h2>Empty the media library of these pictures</h2>';
	printf(
		'<p style="max-width:62em"><strong>%d</strong> file(s) in the media library are copies of TMDB pictures or unlicensed pictures that TMDB now replaces. The button keeps a copy of each in the private backup, unhooks it from its record and removes it from the media library. A file still used anywhere else (inside a text, a gallery, a record with no TMDB picture) is skipped and the reason is shown. Licensed Commons files are never touched.</p>',
		count( $cand )
	);
	if ( 'tmdb' !== $source ) {
		echo '<p><em>Available when the source above is TMDB.</em></p>';
	} else {
		$form( 'clean', get_submit_button( 'Back up and remove 50 from the media library', 'delete', 'go', false, $cand ? array() : array( 'disabled' => 'disabled' ) ), 'Back up and remove up to 50 files from the media library?' );
	}
	printf( '<p><a href="%s">See these files in Media › Library</a></p>', esc_url( admin_url( 'upload.php?mode=list&daw_img=clean' ) ) );

	$skipped = (array) get_option( 'daw_clean_skipped', array() );
	if ( $skipped ) {
		echo '<details><summary>Skipped last time (' . count( $skipped ) . ')</summary><ul>';
		foreach ( array_slice( $skipped, 0, 100, true ) as $att => $why ) {
			printf( '<li><a href="%s">%d</a>: %s</li>', esc_url( (string) get_edit_post_link( (int) $att ) ), (int) $att, esc_html( $why ) );
		}
		echo '</ul></details>';
	}

	echo '<h2>Gizli yedek klasörü</h2>';
	printf(
		'<p style="max-width:62em"><code>%s</code><br>Web\'den açılamaz ve medya kütüphanesinde görünmez. Site bu klasörü sadece acil durum modunda okur.</p>',
		esc_html( str_replace( ABSPATH, '', daw_vault_dir() ) )
	);
	echo '</details></div>';
}

add_action( 'admin_post_daw_hub', 'daw_images_hub_save' );
function daw_images_hub_save() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'daw_hub' ) ) {
		wp_die( 'No.' );
	}
	$op  = sanitize_key( $_POST['op'] ?? '' );
	$msg = '';

	switch ( $op ) {
		case 'job_start':
			$why = function_exists( 'daw_img_job_start' ) ? daw_img_job_start() : 'Job module missing.';
			$msg = '' === $why ? 'Başladı. Bu sayfa açık kaldıkça hızlı ilerler; kapatırsanız arka planda devam eder.' : $why;
			break;
		case 'job_stop':
			daw_img_job_stop();
			$msg = 'Durduruldu.';
			break;
		case 'key':
			$k = trim( sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) ) );
			if ( '' !== $k ) {
				update_option( 'daw_tmdb_key', $k, false );
				$msg = 'Key saved.';
			}
			break;
		case 'test':
			$b   = daw_tmdb_api_get( 'configuration' );
			$msg = is_array( $b ) ? 'TMDB answered. The connection works.' : 'TMDB did not accept the key or could not be reached.';
			break;
		case 'source':
			$s = sanitize_key( $_POST['source'] ?? 'off' );
			update_option( 'daw_img_source', in_array( $s, array( 'off', 'tmdb', 'vault', 'none' ), true ) ? $s : 'off', false );
			update_option( 'daw_img_tmdb_first', empty( $_POST['first'] ) ? 0 : 1, false );
			update_option( 'daw_img_strict', empty( $_POST['strict'] ) ? 0 : 1, false );
			update_option( 'daw_vault_auto', empty( $_POST['auto'] ) ? 0 : 1, false );
			$msg = 'Saved.';
			break;
		case 'sync':
			list( $d, $w, $f ) = daw_tmdb_img_run_batch( 50 );
			$msg = sprintf( 'Checked %d record(s): %d have a TMDB picture. %d could not reach TMDB and will be tried again.', $d, $w, $f );
			break;
		case 'backup':
			list( $ok, $fail ) = daw_vault_run_batch();
			$msg = sprintf( 'Backed up %d picture(s). %d failed and will be tried again.', $ok, $fail );
			break;
		case 'clean':
			if ( 'tmdb' !== daw_img_source() ) {
				$msg = 'Switch the source to TMDB first.';
				break;
			}
			$done = 0;
			$skip = array();
			foreach ( array_slice( daw_clean_candidates(), 0, 50 ) as $att ) {
				$why = daw_clean_one( $att );
				if ( '' === $why ) {
					$done++;
				} else {
					$skip[ $att ] = $why;
				}
			}
			update_option( 'daw_clean_skipped', $skip, false );
			$msg = sprintf( '%d file(s) backed up and removed from the media library. %d skipped.', $done, count( $skip ) );
			break;
	}

	if ( ! wp_next_scheduled( 'daw_snapshot_soon' ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'daw_snapshot_soon' );
	}
	set_transient( 'daw_hub_msg_' . get_current_user_id(), $msg, 120 );
	wp_safe_redirect( daw_images_url() );
	exit;
}

add_action( 'admin_notices', 'daw_images_notice' );
function daw_images_notice() {
	$k   = 'daw_hub_msg_' . get_current_user_id();
	$msg = (string) get_transient( $k );
	if ( '' === $msg ) {
		return;
	}
	delete_transient( $k );
	printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $msg ) );
}

/** Media › Library, list mode: ?daw_img=clean shows the files section 5 would remove. */
add_action( 'pre_get_posts', 'daw_images_media_filter' );
function daw_images_media_filter( $q ) {
	global $pagenow;
	if ( ! is_admin() || 'upload.php' !== $pagenow || ! $q->is_main_query() || 'clean' !== ( $_GET['daw_img'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$ids = daw_clean_candidates();
	$q->set( 'post__in', $ids ? $ids : array( 0 ) );
}
