<?php
/**
 * Discover Asian World — SEO Envanter (SEO command / inventory screen).
 *
 * WHAT IT READS, AND FROM WHERE
 * -------------------------------
 * Nothing here calls an external API and nothing here is guessed:
 *   - SEO score, title/description, robots            -> Rank Math's own
 *     postmeta (rank_math_seo_score, rank_math_title,
 *     rank_math_description, rank_math_robots). These are documented, stable
 *     Rank Math keys; this plugin only reads them, never writes them.
 *   - Search Console clicks/impressions/position        -> Rank Math's own
 *     table, {$wpdb->prefix}rank_math_analytics_gsc, the same table its
 *     Analytics screen reads. If Rank Math's Analytics was never connected
 *     to Search Console, or the table does not exist, every GSC column shows
 *     "veri yok" rather than guessing or erroring — checked once via
 *     daw_seo_gsc_table_exists() so a missing table degrades the whole
 *     screen gracefully instead of failing per row.
 *   - Search volume                                      -> our own
 *     postmeta, _daw_kw_vol, a JSON blob {"kw":"...","vol":N,"src":"...",
 *     "at":"YYYY-MM-DD"}. NOTHING on the server fetches this: Ubersuggest
 *     and Google Ads Keyword Planner have no server-callable API with a key
 *     this site holds. It is filled from the chat, through the Ubersuggest
 *     MCP connection, the same division of labour GBC's kelime-evreni.php
 *     documented for the same reason. A record with no volume saved yet
 *     says "hacim bekliyor", not zero.
 *
 * WHAT IT DOES NOT CLAIM
 * ------------------------
 * "Indexed in Google" is not shown here. That specific fact needs the
 * Search Console URL Inspection API, which needs a Google service account
 * JSON credential this site has not been given yet. Rather than fake it
 * from the robots meta (noindex is not the same question as "did Google
 * actually index this"), the column is simply absent until that credential
 * exists. See the admin notice at the bottom of the screen.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_KW_VOL_META = '_daw_kw_vol';

/** Whether Rank Math's GSC analytics table exists on this install at all. */
function daw_seo_gsc_table_exists() {
	// Our own table (seo-gsc.php) once it holds data; Rank Math's until then.
	if ( function_exists( 'daw_gsc_has_data' ) && daw_gsc_has_data() ) {
		return true;
	}
	global $wpdb;
	static $exists = null;
	if ( null !== $exists ) {
		return $exists;
	}
	$table  = $wpdb->prefix . 'rank_math_analytics_gsc';
	$found  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	$exists = ( $found === $table );
	return $exists;
}

/** 28-day clicks/impressions/avg position for one post, matched by URL path. */
function daw_seo_gsc_for_post( $post_id ) {
	global $wpdb;
	if ( ! daw_seo_gsc_table_exists() ) {
		return null;
	}

	$path = (string) wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH );
	if ( '' === $path ) {
		return null;
	}

	if ( function_exists( 'daw_gsc_has_data' ) && daw_gsc_has_data() ) {
		return daw_gsc_for_path( $path, 28 );
	}

	$table = $wpdb->prefix . 'rank_math_analytics_gsc';
	$since = gmdate( 'Y-m-d', time() - 28 * DAY_IN_SECONDS );

	// Rank Math stores `page` as a full path (sometimes with a leading
	// domain, depending on how Search Console returned it), so both a plain
	// path match and a %path% match are tried; the first non-empty row wins.
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position
			 FROM {$table} WHERE page = %s AND created >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own derived prefix, not user input.
			$path,
			$since
		),
		ARRAY_A
	);

	if ( ! $row || null === $row['clicks'] ) {
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position
				 FROM {$table} WHERE page LIKE %s AND created >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'%' . $wpdb->esc_like( $path ),
				$since
			),
			ARRAY_A
		);
	}

	if ( ! $row || null === $row['clicks'] ) {
		return null;
	}

	return array(
		'clicks'      => (int) $row['clicks'],
		'impressions' => (int) $row['impressions'],
		'position'    => null !== $row['position'] ? round( (float) $row['position'], 1 ) : null,
	);
}

/** Read the saved keyword-volume blob for a post, or null if none saved yet. */
function daw_kw_vol_get( $post_id ) {
	$raw = get_post_meta( $post_id, DAW_KW_VOL_META, true );
	if ( ! $raw ) {
		return null;
	}
	$data = json_decode( (string) $raw, true );
	return is_array( $data ) && ! empty( $data['kw'] ) ? $data : null;
}

/**
 * Writes the saved keyword-volume blob for a post.
 *
 * Called from chat (through the Meta Box / post-meta MCP tool), after a real
 * Ubersuggest or Google Ads Keyword Planner lookup — never by this plugin's
 * own code, which has no key to call either service with.
 */
function daw_kw_vol_set( $post_id, $keyword, $volume, $source ) {
	$data = array(
		'kw'  => sanitize_text_field( $keyword ),
		'vol' => (int) $volume,
		'src' => sanitize_text_field( $source ),
		'at'  => gmdate( 'Y-m-d' ),
	);
	return update_post_meta( $post_id, DAW_KW_VOL_META, wp_json_encode( $data ) );
}

/* =============================================================== screen === */

function daw_seo_command_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="wrap">';

	// A "Detay" link routes here with ?detay=<post_id> — the full per-record
	// breakdown (H1/H2, missing alt images, meta, scripts, schema) computed
	// live, rather than a second screen the owner has to find separately.
	$detay = isset( $_GET['detay'] ) ? (int) $_GET['detay'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detail view.
	if ( $detay && function_exists( 'daw_pa_detail_page' ) ) {
		daw_pa_detail_page( $detay );
		echo '</div>';
		return;
	}

	echo '<h1>' . esc_html__( 'SEO Envanter', 'daw' ) . '</h1>';

	if ( function_exists( 'daw_pa_job_panel' ) ) {
		daw_pa_job_panel();
	}

	$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list view.
	$per_page = 50;

	$query = new WP_Query(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page,
			'paged'                  => $paged,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'update_post_term_cache' => false,
		)
	);

	if ( ! daw_seo_gsc_table_exists() ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Search Console verisi yok. DAW › SEO › Search Console sekmesinden servis hesabı anahtarı girildiğinde tıklama ve gösterim sütunları dolar.', 'daw' ) . '</p></div>';
	}

	echo '<table class="widefat striped"><thead><tr>';
	foreach ( array( 'Kayıt', 'Sayfa denetimi', 'SEO kuralları', 'Başlık uzunluğu', 'Açıklama uzunluğu', 'Robots', '28g tıklama', '28g gösterim', 'Ort. sıra', 'Kelime hacmi', '' ) as $h ) {
		echo '<th>' . esc_html( $h ) . '</th>';
	}
	echo '</tr></thead><tbody>';

	foreach ( $query->posts as $post ) {
		$id       = $post->ID;
		// Rank Math's score stops updating once it is gone; the site's own
		// rule check (seo-admin.php) says what is actually wrong instead.
		$issues   = function_exists( 'daw_sa_rule_issues' ) ? daw_sa_rule_issues( $id ) : null;
		$score    = null === $issues ? (string) get_post_meta( $id, 'rank_math_seo_score', true ) : '';
		$title    = (string) get_post_meta( $id, 'rank_math_title', true );
		$desc     = (string) get_post_meta( $id, 'rank_math_description', true );
		$robots   = (array) get_post_meta( $id, 'rank_math_robots', true );
		$gsc      = daw_seo_gsc_for_post( $id );
		$kw       = daw_kw_vol_get( $id );
		$pa       = function_exists( 'daw_pa_summary_get' ) ? daw_pa_summary_get( $id ) : null;

		$title_len = $title ? mb_strlen( $title ) : mb_strlen( get_the_title( $id ) );
		$desc_len  = $desc ? mb_strlen( $desc ) : 0;

		if ( $pa && empty( $pa['failed'] ) ) {
			$pa_cell = sprintf(
				'<strong>%d/100</strong><br><span style="font-size:11px;color:%s">%s</span>',
				(int) $pa['score'],
				( 1 !== (int) $pa['h1'] || $pa['missing_alt'] > 0 ) ? '#c00' : '#646970',
				esc_html( sprintf( '%d H1 · %d H2 · %d/%d alt eksik', (int) $pa['h1'], (int) $pa['h2'], (int) $pa['missing_alt'], (int) $pa['img_total'] ) )
			);
		} elseif ( $pa ) {
			$pa_cell = '<span style="color:#c00">sayfa çekilemedi</span>';
		} else {
			$pa_cell = '<em style="color:#999">' . esc_html__( 'taranmadı', 'daw' ) . '</em>';
		}

		printf(
			'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td style="color:%s">%d</td><td style="color:%s">%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">%s</a></td></tr>',
			esc_url( get_edit_post_link( $id ) ),
			esc_html( wp_trim_words( get_the_title( $id ), 8 ) ),
			$pa_cell, // phpcs:ignore WordPress.Security.EscapeOutput -- built above with esc_html() on every dynamic part.
			null !== $issues ? ( $issues ? '<span style="color:#c00">' . esc_html( implode( ' · ', $issues ) ) . '</span>' : '<span style="color:#1a7f37">tamam</span>' ) : esc_html( $score ? $score . '/100' : '—' ), // phpcs:ignore WordPress.Security.EscapeOutput
			( $title_len > 60 || $title_len < 20 ) ? '#c00' : 'inherit',
			(int) $title_len,
			( $desc_len > 160 || ( $desc_len > 0 && $desc_len < 70 ) ) ? '#c00' : 'inherit',
			(int) $desc_len,
			in_array( 'noindex', $robots, true ) ? '<strong style="color:#c00">noindex</strong>' : 'index',
			null !== $gsc ? number_format_i18n( $gsc['clicks'] ) : '—',
			null !== $gsc ? number_format_i18n( $gsc['impressions'] ) : '—',
			null !== $gsc && $gsc['position'] ? esc_html( $gsc['position'] ) : '—',
			$kw ? esc_html( $kw['vol'] . ' — "' . $kw['kw'] . '"' ) : '<em style="color:#999">' . esc_html__( 'hacim bekliyor', 'daw' ) . '</em>',
			esc_url( add_query_arg( 'detay', $id ) ),
			esc_html__( 'Detay', 'daw' )
		);
	}

	echo '</tbody></table>';

	$total_pages = (int) $query->max_num_pages;
	if ( $total_pages > 1 ) {
		echo '<p>';
		for ( $p = 1; $p <= $total_pages; $p++ ) {
			$url = add_query_arg( 'paged', $p );
			printf( '<a href="%s" style="margin-right:8px;%s">%d</a>', esc_url( $url ), $p === $paged ? 'font-weight:bold' : '', $p );
		}
		echo '</p>';
	}

	echo '<p style="color:#888;font-size:12px;max-width:760px">' . esc_html__( 'Tıklama, gösterim ve sıra DAW › SEO › Search Console sekmesindeki servis hesabıyla her gün çekilir. Bir sayfanın dizinde olup olmadığı için Search Console\'un kendi "Sayfalar" raporuna bakılır.', 'daw' ) . '</p>';

	echo '</div>';
}
