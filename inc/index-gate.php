<?php
/**
 * Thin page gate: what Google is offered, and what waits until it is ready.
 *
 * WHY (8 Oct 2026, Umut's SEO spec, item 1)
 * -----------------------------------------
 * 244 of 1,277 pages were in Google's index. 419 were unknown to it, 358
 * discovered and left, 256 crawled and left. Google was declining most of
 * the site, and a site where most pages are a name, a date and one credit
 * reads to it as a site of thin pages. So a record that does not yet have
 * enough of its own to say is kept out of the index (noindex, follow: its
 * links still count) and out of the sitemap, and comes back by itself the
 * day it passes the bar. Nobody has to remember to open it again.
 *
 * THE BAR
 *   person        fewer than 3 credits linked on this site (_daw_cast_idx)
 *                 AND fewer than 250 words of its own text
 *   drama, film   a synopsis under 120 words (until 1.75.68 also: fewer
 *                 than 3 people linked in its cast; dropped, see below),
 *                 or (1.75.76) two of: FAQ under 5 questions, no "why it
 *                 is watched", no takeaways
 *   any of them   stays indexed if Search Console counted at least one
 *                 click on it in the last 90 days
 * Food, culture, myth, guides and news never enter the gate.
 *
 * HOW
 * A record held at the gate carries _daw_thin with the reason in words
 * ("1 künye, 170 kelime"). The robots filter and the sitemap read only that
 * key. The whole archive is measured 50 records a batch (rule 8.1), two
 * batches an hourly tick, so a lap takes about half a day; a record and the
 * people in its cast are measured again the moment it is saved.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

const DAW_THIN       = '_daw_thin';
const DAW_THIN_BATCH = 50;

function daw_thin_types() {
	return array( 'person', 'drama', 'film' );
}

/** How many titles on this site link each person, from every cast index at once. */
function daw_thin_credit_counts() {
	static $counts = null;
	if ( null !== $counts ) {
		return $counts;
	}
	global $wpdb;
	$counts = array();
	$rows   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_key = %s AND p.post_status = 'publish'",
			defined( 'DAW_CAST_IDX' ) ? DAW_CAST_IDX : '_daw_cast_idx'
		)
	);
	foreach ( (array) $rows as $idx ) {
		if ( preg_match_all( '/,(\d+):/', (string) $idx, $m ) ) {
			foreach ( array_unique( $m[1] ) as $pid ) {
				$counts[ (int) $pid ] = ( $counts[ (int) $pid ] ?? 0 ) + 1;
			}
		}
	}
	return $counts;
}

/** Paths Search Console counted a click on in the last 90 days. */
function daw_thin_clicked_paths() {
	static $paths = null;
	if ( null !== $paths ) {
		return $paths;
	}
	$paths = array();
	if ( ! function_exists( 'daw_gsc_table' ) ) {
		return $paths;
	}
	global $wpdb;
	$table = daw_gsc_table();
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB
		return $paths;
	}
	$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			'SELECT page FROM ' . $table . ' WHERE day >= %s GROUP BY page HAVING SUM(clicks) > 0',
			gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS )
		)
	);
	foreach ( (array) $rows as $page ) {
		$path = (string) wp_parse_url( (string) $page, PHP_URL_PATH );
		if ( '' !== $path ) {
			$paths[ trailingslashit( $path ) ] = true;
		}
	}
	return $paths;
}

function daw_thin_word_count( $html ) {
	$text = trim( wp_strip_all_tags( html_entity_decode( (string) $html, ENT_QUOTES, 'UTF-8' ) ) );
	return '' === $text ? 0 : count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) );
}

/** The words a profile has of its own: body, biography, overview sections, summary, takeaways, FAQ answers. */
function daw_thin_person_words( $id ) {
	$parts = array( get_post_field( 'post_content', $id ) );
	$flat  = static function ( $v ) use ( &$flat ) {
		$v = maybe_unserialize( $v );
		return is_array( $v ) ? implode( ' ', array_map( $flat, $v ) ) : ( is_scalar( $v ) ? (string) $v : '' );
	};
	foreach ( array( 'daw_biography', 'daw_person_summary', 'daw_person_section_body', 'daw_person_takeaways', 'daw_pfaq_a', 'daw_relationships' ) as $key ) {
		foreach ( get_post_meta( $id, $key ) as $v ) {
			$parts[] = $flat( $v );
		}
	}
	return daw_thin_word_count( implode( ' ', $parts ) );
}

/** Why a record is held at the gate, in words, or '' when it may be indexed. */
function daw_thin_reason( $id ) {
	$id   = (int) $id;
	$type = function_exists( 'daw_get_type' ) ? daw_get_type( $id ) : '';
	if ( ! in_array( $type, daw_thin_types(), true ) || 'publish' !== get_post_status( $id ) ) {
		return '';
	}

	$path = trailingslashit( (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH ) );
	if ( isset( daw_thin_clicked_paths()[ $path ] ) ) {
		return '';
	}

	if ( 'person' === $type ) {
		$credits = (int) ( daw_thin_credit_counts()[ $id ] ?? 0 );
		$words   = daw_thin_person_words( $id );
		return ( $credits < 3 && $words < 250 ) ? sprintf( '%d künye, %d kelime', $credits, $words ) : '';
	}

	$words = daw_thin_word_count( get_post_meta( $id, 'daw_synopsis', true ) );
	$idx   = (string) get_post_meta( $id, defined( 'DAW_CAST_IDX' ) ? DAW_CAST_IDX : '_daw_cast_idx', true );
	$cast  = preg_match_all( '/,(\d+):/', $idx, $m ) ? count( array_unique( $m[1] ) ) : 0;
	// 1.75.69 (owner 13 Oct 2026: "noindex'leri kaldırabilirsin, içeriği
	// doldurduğun sürece zaten uzun olacak"): a title is judged on its own
	// text only. How many of its actors have a page here is a coverage job
	// (rule 59), not a reason to keep a finished page away from Google; the
	// cast count held 231 titles with full synopses back.
	unset( $cast );
	if ( $words < 120 ) {
		return sprintf( '%d kelime özet', $words );
	}

	// TWO OF THE THREE READER SECTIONS MISSING (1.75.76, owner 16 Oct 2026:
	// "Google'a sadece en değerli sayfaları göster"; asked and answered
	// "Sıkılaştır"). 800 pages were waiting for Googlebot while 424 titles
	// with a synopsis and little else were offered beside the finished ones.
	// A title now also waits when two of these are missing: an FAQ of at
	// least 5 questions, "why it is watched", takeaways. It comes back on its
	// own the moment the second one is filled, and a page Search Console
	// counted a click on stays open whatever it lacks (checked above).
	$gaps = daw_thin_title_gaps( $id );
	return count( $gaps ) >= 2 ? 'eksik: ' . implode( ', ', $gaps ) : '';
}

/** Which of the three reader sections a title lacks, in the owner's words. */
function daw_thin_title_gaps( $id ) {
	$gaps = array();
	$faq  = get_post_meta( (int) $id, 'daw_faq_q', true );
	$faq  = is_array( $faq ) ? $faq : get_post_meta( (int) $id, 'daw_faq_q' );
	$faq  = array_filter( array_map( static fn( $q ) => is_scalar( $q ) ? trim( (string) $q ) : '', (array) $faq ), 'strlen' );
	if ( count( $faq ) < 5 ) {
		$gaps[] = sprintf( 'SSS %d/5', count( $faq ) );
	}
	foreach ( array( 'daw_why_watched' => 'why watched', 'daw_takeaways' => 'takeaways' ) as $key => $label ) {
		$v = get_post_meta( (int) $id, $key, true );
		$v = is_array( $v ) ? implode( ' ', array_filter( array_map( static fn( $x ) => is_scalar( $x ) ? trim( (string) $x ) : '', $v ) ) ) : trim( (string) $v );
		if ( '' === $v ) {
			$gaps[] = $label;
		}
	}
	return $gaps;
}

/** Measure one record and write the result. true when its state changed. */
function daw_thin_apply( $id ) {
	$id     = (int) $id;
	$reason = daw_thin_reason( $id );
	$was    = (string) get_post_meta( $id, DAW_THIN, true );
	if ( '' === $reason ) {
		if ( '' !== $was ) {
			delete_post_meta( $id, DAW_THIN );
			daw_thin_purge( $id );
			daw_thin_announce( $id );
			return true;
		}
		return false;
	}
	if ( $reason !== $was ) {
		update_post_meta( $id, DAW_THIN, $reason );
	}
	daw_thin_forget_inspection( $id );
	if ( '' === $was ) {
		daw_thin_purge( $id );
		return true;
	}
	return false;
}

/*
 * A HELD PAGE IS NOT A PAGE GOOGLE IS FAILING TO INDEX (1.75.48)
 * The control centre's "N of M pages not in Google" and the index doctor read
 * the URL Inspection table. A held page leaves the sitemap, so it is never
 * inspected again, and its old row would sit there forever as "not indexed",
 * while the home-page rail and the Similar rail kept sending links to it as a
 * page that needed help. Its row is dropped; when it passes the bar it is back
 * in the sitemap, uninspected, which is the first thing the hourly
 * inspection picks.
 */
function daw_thin_forget_inspection( $id ) {
	if ( ! function_exists( 'daw_gsc_inspect_table' ) || ! function_exists( 'daw_gsc_path' ) ) {
		return;
	}
	global $wpdb;
	$wpdb->delete( daw_gsc_inspect_table(), array( 'path_hash' => md5( daw_gsc_path( (string) get_permalink( (int) $id ) ) ) ) ); // phpcs:ignore WordPress.DB
}

/*
 * A PAGE THAT OPENS IS ANNOUNCED (1.75.77, owner 16 Oct 2026: "botlar
 * kendiliğinden gelsin")
 * The moment a record passes the bar it is stamped, so the home page's
 * archive rail puts it first (index-doctor.php) and Googlebot, which visits
 * the home page more than any other, finds a link to it on its next visit;
 * IndexNow tells Bing, Yandex and Naver at once; and the home page is marked
 * for one purge on the next hourly tick, not one purge per record (rule 8.3).
 */
function daw_thin_announce( $id ) {
	update_post_meta( (int) $id, '_daw_opened', time() );
	if ( function_exists( 'daw_inx_queue' ) && function_exists( 'daw_eng_live' ) && daw_eng_live() ) {
		daw_inx_queue( (string) get_permalink( (int) $id ) );
	}
	update_option( 'daw_home_dirty', 1, false );
}

/** Held at the gate right now. */
function daw_thin_is_held( $id ) {
	return '' !== (string) get_post_meta( (int) $id, DAW_THIN, true );
}

/*
 * THE CACHED PAGE HAS TO CHANGE WITH THE STATE (1.75.47)
 * Writing _daw_thin does not fire save_post, so LiteSpeed kept serving the
 * copy it had: on 8 Oct the first lap held 310 records and Keagan Kang was
 * still served without its noindex. Every change of state now drops that one
 * page from the cache.
 */
function daw_thin_purge( $id ) {
	clean_post_cache( (int) $id );
	do_action( 'litespeed_purge_post', (int) $id );
}

/** One batch of the lap: 50 records after the cursor. */
function daw_thin_batch( $limit = DAW_THIN_BATCH ) {
	$all = array_map( 'intval', (array) ( function_exists( 'daw_audit_record_ids' ) ? daw_audit_record_ids( daw_thin_types() ) : array() ) );
	sort( $all );
	$pos  = (int) get_option( 'daw_thin_pos', 0 );
	$todo = array_slice( array_values( array_filter( $all, static fn( $i ) => $i > $pos ) ), 0, (int) $limit );

	if ( ! $todo ) {
		update_option( 'daw_thin_pos', 0, false );
		update_option( 'daw_thin_lap', time(), false );
		return array( 'done' => 0, 'lap' => true, 'held' => daw_thin_count() );
	}

	$changed = 0;
	foreach ( $todo as $id ) {
		$changed += daw_thin_apply( $id ) ? 1 : 0;
	}
	update_option( 'daw_thin_pos', end( $todo ), false );
	if ( $changed && function_exists( 'daw_sm_bump' ) ) {
		daw_sm_bump();
	}
	return array( 'done' => count( $todo ), 'changed' => $changed, 'pos' => end( $todo ), 'of' => count( $all ), 'held' => daw_thin_count() );
}

add_action( 'daw_fix_tick', 'daw_thin_tick', 8 );
function daw_thin_tick() {
	for ( $i = 0; $i < 2; $i++ ) {
		$r = daw_thin_batch();
		if ( ! empty( $r['lap'] ) ) {
			break;
		}
	}
}

/** COUNT(*), never a list, for every place that only needs the number (rule 8.2). */
function daw_thin_count() {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_THIN ) ); // phpcs:ignore WordPress.DB
}

/*
 * A SAVE MEASURES AGAIN AT ONCE
 * A record that gains its third credit or its 120th word should not wait
 * half a day. A title is measured with everybody in its cast, after the
 * cast index has been rebuilt (that runs at shutdown, priority 10).
 */
add_action( 'save_post_post', 'daw_thin_on_save', 30 );
add_action( 'updated_post_meta', 'daw_thin_on_meta', 10, 3 );
add_action( 'added_post_meta', 'daw_thin_on_meta', 10, 3 );
function daw_thin_on_meta( $mid, $post_id, $key ) {
	if ( is_string( $key ) && ( 0 === strpos( $key, 'daw_cast_' ) || in_array( $key, array( 'daw_synopsis', 'daw_faq_q', 'daw_why_watched', 'daw_takeaways', 'daw_biography', 'daw_person_section_body', 'daw_person_summary', 'daw_person_takeaways', 'daw_pfaq_a', 'daw_relationships' ), true ) ) ) {
		daw_thin_on_save( (int) $post_id );
	}
}
function daw_thin_on_save( $post_id ) {
	static $queued = array();
	$post_id = (int) $post_id;
	if ( ! empty( $GLOBALS['daw_castlink'] ) ) {
		return; // the cast linker measures its own batch once.
	}
	if ( ! $post_id || isset( $queued[ $post_id ] ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	$queued[ $post_id ] = true;
	add_action(
		'shutdown',
		static function () use ( $post_id ) {
			$ids = array( $post_id );
			$idx = (string) get_post_meta( $post_id, defined( 'DAW_CAST_IDX' ) ? DAW_CAST_IDX : '_daw_cast_idx', true );
			if ( preg_match_all( '/,(\d+):/', $idx, $m ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $m[1] ) );
			}
			$changed = false;
			foreach ( array_slice( array_unique( $ids ), 0, DAW_THIN_BATCH ) as $id ) {
				$changed = daw_thin_apply( $id ) || $changed;
			}
			if ( $changed && function_exists( 'daw_sm_bump' ) ) {
				daw_sm_bump();
			}
		},
		20
	);
}

/* ------------------------------------------------------- what is printed */

add_filter( 'daw_seo_robots', 'daw_thin_robots', 20 );
function daw_thin_robots( $robots ) {
	$hold = false;
	if ( is_search() ) {
		// Item 3: a search result page is never a page for the index.
		$hold = true;
	} elseif ( is_singular( 'post' ) && '' !== (string) get_post_meta( (int) get_queried_object_id(), DAW_THIN, true ) ) {
		$hold = true;
	}
	if ( $hold ) {
		$robots['index']  = 'noindex';
		$robots['follow'] = 'follow';
	}
	return $robots;
}

add_filter( 'daw_sitemap_rows', 'daw_thin_sitemap_rows', 10, 2 );
function daw_thin_sitemap_rows( $rows, $type ) {
	if ( 'post' !== $type ) {
		return $rows;
	}
	global $wpdb;
	$held = array_flip( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", DAW_THIN ) ) ) ); // phpcs:ignore WordPress.DB
	if ( ! $held ) {
		return $rows;
	}
	return array_values( array_filter( (array) $rows, static fn( $r ) => ! isset( $held[ (int) $r[0] ] ) ) );
}

/* ------------------------------------------------------------------ panel */

add_action( 'admin_menu', 'daw_thin_menu', 30 );
function daw_thin_menu() {
	add_submenu_page( 'daw', 'Kapıda bekleyenler', 'Kapıda bekleyenler', 'manage_options', 'daw-kapi', 'daw_thin_page' );
}

function daw_thin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$paged = max( 1, absint( $_GET['p'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$per   = 100;
	$total = daw_thin_count();
	$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			"SELECT m.post_id, m.meta_value, p.post_title FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_key = %s ORDER BY p.post_title ASC LIMIT %d OFFSET %d",
			DAW_THIN,
			$per,
			( $paged - 1 ) * $per
		)
	);
	$lap   = (int) get_option( 'daw_thin_lap', 0 );
	$nonce = wp_create_nonce( 'daw_thin_run' );

	echo '<div class="wrap"><h1>DAW › Kapıda bekleyenler</h1>';
	printf(
		'<p style="max-width:70em">Google\'a henüz sunulmayan kayıtlar: <strong>%s</strong>. Bunlar <code>noindex, follow</code> basar ve site haritasında yoktur. Ölçüt: kişi 3\'ten az bağlı künye <em>ve</em> 250 kelimeden az kendi metni; dizi/film 120 kelimeden az özet <em>ya da</em> şu üçünden ikisi eksik: en az 5 soruluk SSS, \'why watched\', takeaways. Son 90 günde Search Console\'dan tık almış sayfa her durumda açık kalır. Eşiği geçen kayıt kaydedildiği anda kendiliğinden açılır. Son tam tur: %s.</p>',
		esc_html( number_format_i18n( $total ) ),
		$lap ? esc_html( wp_date( 'j M Y H:i', $lap ) ) : 'henüz yok'
	);
	echo '<p><button type="button" class="button button-primary" id="daw-thin-run">Şimdi yeniden ölç</button> <span id="daw-thin-msg" style="margin-left:8px;color:#50575e"></span></p>';
	?>
	<script>
	(function () {
		var b = document.getElementById('daw-thin-run'), m = document.getElementById('daw-thin-msg'), done = 0, started = false;
		b.addEventListener('click', function () {
			b.disabled = true;
			(function step() {
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'daw_thin_run', nonce: <?php echo wp_json_encode( $nonce ); ?>, restart: started ? '0' : '1' }) })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						started = true;
						if (!d || !d.success) { m.textContent = 'Hata, 5 sn sonra yeniden denenecek.'; setTimeout(step, 5000); return; }
						done += d.data.done;
						m.textContent = done + ' kayıt ölçüldü, kapıda ' + d.data.held + '.';
						if (d.data.done > 0) { setTimeout(step, 300); } else { m.textContent = 'Bitti. Kapıda ' + d.data.held + ' kayıt. Sayfa yenileniyor.'; setTimeout(function () { location.reload(); }, 800); }
					})
					.catch(function () { setTimeout(step, 5000); });
			})();
		});
	})();
	</script>
	<?php
	echo '<table class="widefat striped"><thead><tr><th>Kayıt</th><th>Tür</th><th>Eksik ölçüt</th><th></th></tr></thead><tbody>';
	foreach ( (array) $rows as $r ) {
		printf(
			'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td><a class="button button-small" href="%s">Düzenle</a></td></tr>',
			esc_url( (string) get_permalink( (int) $r->post_id ) ),
			esc_html( $r->post_title ),
			esc_html( function_exists( 'daw_get_type' ) ? daw_get_type( (int) $r->post_id ) : '' ),
			esc_html( $r->meta_value ),
			esc_url( (string) get_edit_post_link( (int) $r->post_id ) )
		);
	}
	echo '</tbody></table>';
	if ( $total > $paged * $per ) {
		printf( '<p><a class="button" href="%s">Sonraki %d</a></p>', esc_url( add_query_arg( 'p', $paged + 1 ) ), (int) $per );
	}
	echo '</div>';
}

add_action( 'wp_ajax_daw_thin_run', 'daw_thin_ajax' );
function daw_thin_ajax() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'daw_thin_run', 'nonce', false ) ) {
		wp_send_json_error( 'no', 403 );
	}
	if ( ! empty( $_POST['restart'] ) && '1' === $_POST['restart'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		update_option( 'daw_thin_pos', 0, false );
	}
	$r = daw_thin_batch();
	wp_send_json_success( array( 'done' => (int) $r['done'], 'held' => (int) $r['held'] ) );
}

/** GET /daw/v1/thin: the count and one page of the list; run=1 measures one batch first. */
add_action( 'rest_api_init', 'daw_thin_rest' );
function daw_thin_rest() {
	register_rest_route(
		'daw/v1',
		'/thin',
		array(
			'methods'             => 'GET',
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			'callback'            => static function ( WP_REST_Request $r ) {
				$out = array();
				if ( $r->get_param( 'restart' ) ) {
					update_option( 'daw_thin_pos', 0, false );
				}
				if ( $r->get_param( 'run' ) ) {
					$out['run'] = daw_thin_batch();
				}
				global $wpdb;
				$page        = max( 1, (int) $r->get_param( 'page' ) );
				$out['held'] = daw_thin_count();
				$out['pos']  = (int) get_option( 'daw_thin_pos', 0 );
				$out['lap']  = (int) get_option( 'daw_thin_lap', 0 );
				$out['rows'] = $wpdb->get_results( // phpcs:ignore WordPress.DB
					$wpdb->prepare( "SELECT post_id AS id, meta_value AS why FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC LIMIT 100 OFFSET %d", DAW_THIN, ( $page - 1 ) * 100 ),
					ARRAY_A
				);
				return $out;
			},
		)
	);
}
