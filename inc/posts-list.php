<?php
/**
 * The Posts screen, sorted the way the audit thinks about the site.
 *
 * Every record is a WordPress post, so the Posts list mixes dramas, films,
 * people, news and guides in one long list. This file adds:
 *
 * - a row of links above the list, one per record type with its count
 *   (Drama 467 · Film 193 · Person 336 …), and a type dropdown next to the
 *   date filter;
 * - an audit filter: locked, has red lines, has unchecked lines, stamped as
 *   verified, never audited;
 * - two columns: the type, and the audit state (lock, red / unchecked /
 *   waiting counts, when it was last audited, a link to the record's audit).
 *
 * Nothing here writes anything; it only reads `_daw_audit` and `daw_verified`.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

/** Type terms with counts, cached for the request. */
function daw_pl_types() {
	static $t = null;
	if ( null === $t ) {
		$t = get_terms(
			array(
				'taxonomy'   => 'dw_type',
				'hide_empty' => true,
			)
		);
		$t = is_array( $t ) ? $t : array();
	}
	return $t;
}

function daw_pl_is_screen() {
	global $pagenow;
	return is_admin() && 'edit.php' === $pagenow && 'post' === ( $_GET['post_type'] ?? 'post' ); // phpcs:ignore WordPress.Security.NonceVerification
}

/* ================================================================ views === */

add_filter( 'views_edit-post', 'daw_pl_views' );
function daw_pl_views( $views ) {
	$cur = sanitize_key( $_GET['daw_type'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	foreach ( daw_pl_types() as $term ) {
		$url                              = add_query_arg( array( 'post_type' => 'post', 'daw_type' => $term->slug ), admin_url( 'edit.php' ) );
		$views[ 'daw_type_' . $term->slug ] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $url ),
			$cur === $term->slug ? ' class="current" aria-current="page"' : '',
			esc_html( $term->name ),
			(int) $term->count
		);
	}
	return $views;
}

/* ============================================================== filters === */

add_action( 'restrict_manage_posts', 'daw_pl_filters', 10, 2 );
function daw_pl_filters( $post_type, $which = 'top' ) {
	if ( 'post' !== $post_type || 'top' !== $which ) {
		return;
	}
	$cur = sanitize_key( $_GET['daw_type'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	echo '<select name="daw_type"><option value="">Tüm türler</option>';
	foreach ( daw_pl_types() as $term ) {
		printf( '<option value="%s"%s>%s (%d)</option>', esc_attr( $term->slug ), selected( $cur, $term->slug, false ), esc_html( $term->name ), (int) $term->count );
	}
	echo '</select>';

	$st   = sanitize_key( $_GET['daw_state'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	$opts = array(
		''         => 'Denetim: hepsi',
		'red'      => 'Kırmızı satırı olan',
		'eksik'    => 'Bakılmamış satırı olan',
		'locked'   => 'Kilitli (bitti)',
		'verified' => 'Doğrulama damgalı',
		'none'     => 'Hiç denetlenmemiş',
		'under'    => 'Denetim %100 olmayan',
		'kwmiss'   => 'Geçmeyen aranan kalıbı olan',
	);
	echo '<select name="daw_state">';
	foreach ( $opts as $k => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $st, $k, false ), esc_html( $label ) );
	}
	echo '</select>';
}

add_action( 'pre_get_posts', 'daw_pl_query' );
function daw_pl_query( $q ) {
	if ( ! $q->is_main_query() || ! daw_pl_is_screen() ) {
		return;
	}
	$type = sanitize_key( $_GET['daw_type'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' !== $type ) {
		$tax   = (array) $q->get( 'tax_query' );
		$tax[] = array(
			'taxonomy' => 'dw_type',
			'field'    => 'slug',
			'terms'    => $type,
		);
		$q->set( 'tax_query', $tax );
	}
	$st   = sanitize_key( $_GET['daw_state'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	$meta = (array) $q->get( 'meta_query' );
	// `_daw_audit` is a serialized array; its counters are matched as text.
	switch ( $st ) {
		case 'red':
			$meta[] = array( 'key' => '_daw_audit', 'value' => 's:2:"no";i:0;', 'compare' => 'NOT LIKE' );
			$meta[] = array( 'key' => '_daw_audit', 'compare' => 'EXISTS' );
			break;
		case 'eksik':
			$meta[] = array( 'key' => '_daw_audit', 'value' => 's:5:"eksik";i:0;', 'compare' => 'NOT LIKE' );
			$meta[] = array( 'key' => '_daw_audit', 'compare' => 'EXISTS' );
			break;
		case 'locked':
			$meta[] = array( 'key' => '_daw_audit', 'value' => 's:4:"lock";b:1;', 'compare' => 'LIKE' );
			break;
		case 'verified':
			$meta[] = array( 'key' => 'daw_verified', 'compare' => 'EXISTS' );
			break;
		case 'none':
			$meta[] = array( 'key' => '_daw_audit', 'compare' => 'NOT EXISTS' );
			break;
		case 'under':
			$meta[] = array( 'key' => '_daw_pct', 'value' => 100, 'compare' => '<', 'type' => 'NUMERIC' );
			break;
		case 'kwmiss':
			$meta[] = array( 'key' => '_daw_kwcov', 'value' => 's:4:"miss";a:0:{}', 'compare' => 'NOT LIKE' );
			$meta[] = array( 'key' => '_daw_kwcov', 'value' => 's:3:"pct";', 'compare' => 'LIKE' );
			break;
	}
	if ( $st ) {
		$q->set( 'meta_query', $meta );
	}
	if ( 'daw_pct' === $q->get( 'orderby' ) ) {
		$q->set( 'meta_key', '_daw_pct' );
		$q->set( 'orderby', 'meta_value_num' );
	}
}

add_filter( 'manage_edit-post_sortable_columns', 'daw_pl_sortable' );
function daw_pl_sortable( $cols ) {
	$cols['daw_audit'] = 'daw_pct';
	return $cols;
}

/* ============================================================== columns === */

add_filter( 'manage_post_posts_columns', 'daw_pl_columns' );
function daw_pl_columns( $cols ) {
	$out = array();
	foreach ( $cols as $k => $v ) {
		$out[ $k ] = $v;
		if ( 'title' === $k ) {
			$out['daw_type']  = 'Tür';
			$out['daw_audit'] = 'Denetim';
		}
	}
	return $out;
}

add_action( 'manage_post_posts_custom_column', 'daw_pl_cell', 10, 2 );
function daw_pl_cell( $col, $post_id ) {
	if ( 'daw_type' === $col ) {
		$terms = get_the_terms( $post_id, 'dw_type' );
		if ( is_array( $terms ) && $terms ) {
			$t = $terms[0];
			printf( '<a href="%s">%s</a>', esc_url( add_query_arg( array( 'post_type' => 'post', 'daw_type' => $t->slug ), admin_url( 'edit.php' ) ) ), esc_html( $t->name ) );
		} else {
			echo '<span style="color:#8c8f94">—</span>';
		}
		return;
	}
	if ( 'daw_audit' !== $col ) {
		return;
	}
	$a = get_post_meta( $post_id, '_daw_audit', true );
	if ( ! is_array( $a ) || empty( $a['sum'] ) ) {
		echo '<span style="color:#8c8f94">Denetlenmedi</span>';
		return;
	}
	$s    = $a['sum'];
	// The audit as one number, then how much of the searched volume the page
	// covers and the biggest patterns it still lacks (owner, 29 Oct 2026).
	$pct = function_exists( 'daw_ap_percent' ) ? daw_ap_percent( $a ) : 0;
	$col = function_exists( 'daw_ap_pct_colour' ) ? daw_ap_pct_colour( $pct ) : '#50575e';
	printf( '<div style="font-size:15px;font-weight:700;color:%s;margin-bottom:3px">%%%d</div>', esc_attr( $col ), (int) $pct );
	$kc = get_post_meta( $post_id, '_daw_kwcov', true );
	if ( is_array( $kc ) && isset( $kc['pct'] ) ) {
		$kcol = function_exists( 'daw_ap_pct_colour' ) ? daw_ap_pct_colour( (int) $kc['pct'] ) : '#50575e';
		printf(
			'<div style="font-size:11px;margin-bottom:3px">Kalıplar: <strong style="color:%s">%%%d</strong> hacim · %d / %d geçiyor</div>',
			esc_attr( $kcol ),
			(int) $kc['pct'],
			(int) ( $kc['hit'] ?? 0 ),
			(int) ( $kc['n'] ?? 0 )
		);
		if ( ! empty( $kc['miss'] ) ) {
			$m = array();
			foreach ( (array) $kc['miss'] as $mk ) {
				$m[] = esc_html( (string) $mk[0] ) . ' <span style="color:#8c8f94">' . esc_html( number_format_i18n( (int) $mk[1] ) ) . '</span>';
			}
			echo '<div style="font-size:11px;color:#a8431a;margin-bottom:3px">Geçmiyor: ' . implode( ' · ', $m ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
	}
	$pill = static function ( $n, $bg, $fg, $label ) {
		return $n ? sprintf( '<span title="%s" style="display:inline-block;margin:0 3px 3px 0;padding:1px 7px;border-radius:999px;background:%s;color:%s;font-size:11px;font-weight:600">%d %s</span>', esc_attr( $label ), $bg, $fg, (int) $n, esc_html( $label ) ) : '';
	};
	$out  = '';
	if ( ! empty( $a['lock'] ) ) {
		$out .= '<span style="display:inline-block;margin:0 3px 3px 0;padding:1px 7px;border-radius:999px;background:#e3f1ec;color:#185a4a;font-size:11px;font-weight:700">🔒 kilitli</span>';
	}
	$out .= $pill( (int) ( $s['no'] ?? 0 ), '#fbe3e3', '#8a1f1f', 'kırmızı' );
	$out .= $pill( (int) ( $s['eksik'] ?? 0 ), '#eceae6', '#50575e', 'bakılmadı' );
	$out .= $pill( (int) ( $s['wait'] ?? 0 ), '#f6ecc9', '#6a4d0c', 'bekliyor' );
	if ( '' === $out ) {
		$out = '<span style="color:#185a4a;font-weight:600">Temiz</span>';
	}
	$v    = get_post_meta( $post_id, 'daw_verified', true );
	$when = ! empty( $a['at'] ) ? human_time_diff( (int) $a['at'] ) . ' önce denetlendi' : '';
	if ( is_array( $v ) && ! empty( $v['at'] ) ) {
		$when .= ' · damga ' . gmdate( 'd.m.Y', (int) $v['at'] );
	}
	printf(
		'%s<br><a href="%s" style="font-size:11px">%s</a>',
		$out, // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
		esc_url( admin_url( 'admin.php?page=daw-denetim&record=' . (int) $post_id ) ),
		esc_html( $when ? $when : 'Denetimi aç' )
	);
}

/* ============================================================= backfill === */

/*
 * Records audited before 1.77.1 have no `_daw_pct` or `_daw_kwcov` yet. Each
 * hourly tick fills the percentage for up to 200 of them (read from the stored
 * audit, no page fetch) and the keyword coverage for 5 (one page fetch each),
 * so the whole site is covered in a few days without loading the server.
 */
add_action( 'daw_fix_tick', 'daw_pl_backfill', 80 );
function daw_pl_backfill() {
	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array( 'key' => '_daw_audit', 'compare' => 'EXISTS' ),
				array( 'key' => '_daw_pct', 'compare' => 'NOT EXISTS' ),
			),
		)
	);
	foreach ( (array) $ids as $id ) {
		$a = get_post_meta( $id, '_daw_audit', true );
		if ( is_array( $a ) && function_exists( 'daw_ap_percent' ) ) {
			update_post_meta( $id, '_daw_pct', daw_ap_percent( $a ) );
		}
	}

	if ( ! function_exists( 'daw_ap_kw_cover' ) ) {
		return;
	}
	$ids = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			// Five a tick: each is a full page render through a loopback
			// request, and twenty in a row added to the load that gave the
			// site 504s on 29 Oct 2026.
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array( 'key' => '_daw_kw', 'compare' => 'EXISTS' ),
				array( 'key' => '_daw_kwcov', 'compare' => 'NOT EXISTS' ),
			),
		)
	);
	foreach ( (array) $ids as $id ) {
		try {
			daw_ap_kw_cover( (int) $id );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		// A page that could not be read is marked, so the next tick moves on
		// to other records instead of retrying the same twenty.
		if ( ! get_post_meta( $id, '_daw_kwcov', true ) ) {
			update_post_meta( $id, '_daw_kwcov', array( 'err' => 1, 'at' => time() ) );
		}
	}
}
