<?php
/**
 * Discover Asian World: news that keeps itself current.
 *
 * Some stories are finished the day they are written (a death, an award night).
 * Others are about what comes next: an actor's next drama, a discharge date, a
 * show "on air now". Those go out of date by themselves, so they are kept as
 * living stories on one url:
 *
 *   daw_news_live     '1' living, '0' finished, '' worked out from the headline
 *   daw_news_next     what is confirmed to come, one row per line:
 *                     "when | what | record id" (when: 2026-11-20, 2026-11 or
 *                     words like "late 2026"; record id optional)
 *   daw_news_checked  the day a person last checked the whole story
 *   daw_news_about    the person the story is about (worked out if empty)
 *
 * A living story is listed for a check when it was never checked in this
 * shape, when 30 days have passed since the last check, when a date it gave
 * has passed, or when a drama or film it names has changed state since the
 * check (upcoming to on air, on air to finished). The person it is about shows
 * the confirmed rows in an "upcoming" box on their own page, linked back here.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_NEWS_LIVE_DAYS = 30;

add_filter( 'royal_mcp_readable_options', 'daw_nl_readable' );
function daw_nl_readable( $keys ) {
	$keys   = (array) $keys;
	$keys[] = 'daw_news_stale';
	return array_values( array_unique( $keys ) );
}

/** Is this a story about what comes next? */
function daw_news_is_live( $pid ) {
	$flag = (string) get_post_meta( $pid, 'daw_news_live', true );
	if ( '1' === $flag || '0' === $flag ) {
		return '1' === $flag;
	}
	// The headline only: old slugs still say "upcoming" about albums long out.
	$text = strtolower( remove_accents( wp_specialchars_decode( get_the_title( $pid ), ENT_QUOTES ) ) );
	return (bool) preg_match( '/\b(next|upcoming|new drama|now|filming|due|discharge|slate|not signed|comeback|future)\b/', str_replace( '-', ' ', $text ) );
}

/** A date or month written by a person, as a timestamp (0 when it is words). */
function daw_nl_when_ts( $when ) {
	$w = trim( (string) $when );
	if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $w ) ) {
		return (int) strtotime( $w . ' 23:59:59 UTC' );
	}
	if ( preg_match( '/^(\d{4})-(\d{2})$/', $w, $m ) ) {
		return (int) strtotime( 'last day of ' . $w . '-01 23:59:59 UTC' );
	}
	return 0;
}

/** A human date for a row: 20 November 2026, November 2026, or the words. */
function daw_nl_when_label( $when ) {
	$w = trim( (string) $when );
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $w ) ) {
		return gmdate( 'j F Y', (int) strtotime( $w . ' UTC' ) );
	}
	if ( preg_match( '/^\d{4}-\d{2}$/', $w ) ) {
		return gmdate( 'F Y', (int) strtotime( $w . '-01 UTC' ) );
	}
	return $w;
}

/** The confirmed rows: [ [when, what, id], ... ]. */
/**
 * A row marked done is finished business (1.75.52). Umut, 8 Oct 2026: the
 * Kim Soo Hyun hearing date passed, the card kept asking, and the only way
 * out was to invent an outcome or retype the date. A fourth column "done"
 * ("when | what | record id | done") takes the row out of every reader of
 * the rows: the stale card, the person's "upcoming" box and the story's
 * "What's next" box. The text stays in the field, so it can be undone.
 */
function daw_news_row_done( $v ) {
	return in_array( strtolower( trim( (string) $v ) ), array( 'done', 'bitti', '1', 'true' ), true );
}

function daw_news_next_rows( $pid ) {
	$raw  = get_post_meta( $pid, 'daw_news_next', true );
	$rows = array();
	if ( is_array( $raw ) ) {
		foreach ( $raw as $r ) {
			$r = array_values( (array) $r );
			if ( daw_news_row_done( $r[3] ?? '' ) ) {
				continue;
			}
			$rows[] = array( (string) ( $r[0] ?? '' ), (string) ( $r[1] ?? '' ), (int) ( $r[2] ?? 0 ) );
		}
	} else {
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$p      = array_map( 'trim', explode( '|', $line ) );
			if ( daw_news_row_done( $p[3] ?? '' ) ) {
				continue;
			}
			$rows[] = array( (string) ( $p[0] ?? '' ), (string) ( $p[1] ?? '' ), (int) ( $p[2] ?? 0 ) );
		}
	}
	return array_values(
		array_filter(
			$rows,
			static fn( $r ) => '' !== trim( $r[1] )
		)
	);
}

/** When a person last checked the whole story (0 = never). */
function daw_news_checked( $pid ) {
	$v = get_post_meta( $pid, 'daw_news_checked', true );
	if ( is_numeric( $v ) ) {
		return (int) $v;
	}
	$v = trim( (string) $v );
	return '' === $v ? 0 : (int) strtotime( $v . ' UTC' );
}

/** The person the story is about: set by hand, or the one named in the headline. */
function daw_news_about( $pid ) {
	$set = (int) get_post_meta( $pid, 'daw_news_about', true );
	if ( $set ) {
		return $set;
	}
	$title = strtolower( remove_accents( wp_specialchars_decode( get_the_title( $pid ), ENT_QUOTES ) ) );
	$ids   = function_exists( 'daw_news_mentions_cached' ) ? daw_news_mentions_cached( $pid ) : array();
	foreach ( (array) $ids as $mid ) {
		if ( ! in_array( daw_get_type( $mid ), array( 'person', 'group' ), true ) ) {
			continue;
		}
		$name = strtolower( remove_accents( get_the_title( $mid ) ) );
		if ( '' !== $name && false !== strpos( str_replace( '-', ' ', $title ), str_replace( '-', ' ', $name ) ) ) {
			return (int) $mid;
		}
	}
	return 0;
}

/** Titles (not people) a story names, plus the records in its rows. */
function daw_nl_watched_titles( $pid ) {
	$ids = function_exists( 'daw_news_mentions_cached' ) ? (array) daw_news_mentions_cached( $pid ) : array();
	foreach ( daw_news_next_rows( $pid ) as $r ) {
		if ( $r[2] ) {
			$ids[] = $r[2];
		}
	}
	$out = array();
	foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
		if ( $id && 'publish' === get_post_status( $id ) && ! in_array( daw_get_type( $id ), array( 'person', 'group', 'news' ), true ) ) {
			$out[] = $id;
		}
	}
	return $out;
}

function daw_nl_status( $id ) {
	return function_exists( 'daw_airing_status' ) ? (string) daw_airing_status( $id )['slug'] : '';
}

/** The moment a check is recorded, the state of every named title is kept. */
add_action( 'added_post_meta', 'daw_nl_on_checked', 10, 3 );
add_action( 'updated_post_meta', 'daw_nl_on_checked', 10, 3 );
function daw_nl_on_checked( $mid, $pid, $key ) {
	if ( 'daw_news_checked' !== $key ) {
		return;
	}
	$seen = array();
	foreach ( daw_nl_watched_titles( (int) $pid ) as $id ) {
		$seen[ $id ] = daw_nl_status( $id );
	}
	update_post_meta( (int) $pid, '_daw_news_seen', $seen );
	delete_transient( 'daw_nl_up_v1' );
}

/** Why one story needs a look, or array() when it does not. */
function daw_news_reasons( $pid ) {
	$now     = time();
	$live    = daw_news_is_live( $pid );
	$checked = daw_news_checked( $pid );
	$mod     = (int) get_post_modified_time( 'U', true, $pid );
	// A living story never checked in the new shape is measured from its last edit.
	$since   = $live ? ( $checked ? $checked : $mod ) : max( $mod, $checked );
	$why     = array();

	foreach ( array_filter( array_map( 'intval', (array) get_post_meta( $pid, 'daw_news_tl_date', true ) ) ) as $d ) {
		if ( $d > $since && $d <= $now ) {
			$why[] = sprintf( 'Zaman çizelgesindeki %s tarihi geçti', gmdate( 'j M Y', $d ) );
			break;
		}
	}

	if ( $live ) {
		if ( ! $checked ) {
			$why[] = 'Canlı haber: yeni yapıya göre (Sırada ne var / Şimdiye kadar) henüz kontrol edilmedi';
		} else {
			$age = (int) floor( ( $now - $checked ) / DAY_IN_SECONDS );
			if ( $age >= DAW_NEWS_LIVE_DAYS ) {
				$why[] = sprintf( 'Canlı haber %d gündür kontrol edilmedi', $age );
			}
			foreach ( daw_news_next_rows( $pid ) as $r ) {
				$ts = daw_nl_when_ts( $r[0] );
				if ( $ts && $ts <= $now && $ts > $checked ) {
					$why[] = sprintf( '"%s" için verilen tarih (%s) geçti', $r[1], daw_nl_when_label( $r[0] ) );
				}
			}
			$seen = (array) get_post_meta( $pid, '_daw_news_seen', true );
			foreach ( daw_nl_watched_titles( $pid ) as $id ) {
				if ( ! array_key_exists( $id, $seen ) ) {
					continue;
				}
				$cur = daw_nl_status( $id );
				if ( (string) $seen[ $id ] !== $cur ) {
					$why[] = sprintf( '%s artık "%s" (kontrol edildiğinde "%s" idi)', get_the_title( $id ), daw_nl_status_tr( $cur ), daw_nl_status_tr( (string) $seen[ $id ] ) );
				}
			}
		}
	} elseif ( ! $why ) {
		$future = array_filter( array_map( 'intval', (array) get_post_meta( $pid, 'daw_news_tl_date', true ) ), static fn( $d ) => $d > $now );
		$age    = (int) floor( ( $now - $since ) / DAY_IN_SECONDS );
		if ( $future && $age > 60 ) {
			$why[] = sprintf( 'İleriye bakıyor ama %d gündür güncellenmedi', $age );
		} elseif ( $age > 180 ) {
			$why[] = sprintf( '%d gündür güncellenmedi', $age );
		}
	}
	return $why;
}

function daw_nl_status_tr( $slug ) {
	$map = array( 'upcoming' => 'yakında', 'onair' => 'yayında', 'completed' => 'bitti', '' => 'durum yok' );
	return $map[ $slug ] ?? $slug;
}

/** Every story that needs a look, saved for the control centre and the chat. */
function daw_news_stale_run() {
	$rows = array();
	$live = 0;
	$ids  = function_exists( 'daw_audit_record_ids' ) ? daw_audit_record_ids( array( 'news' ) ) : array();
	foreach ( $ids as $pid ) {
		$pid = (int) $pid;
		$is  = daw_news_is_live( $pid );
		$live += $is ? 1 : 0;
		$why = daw_news_reasons( $pid );
		if ( $why ) {
			$rows[] = array(
				'id'      => $pid,
				'title'   => wp_specialchars_decode( get_the_title( $pid ), ENT_QUOTES ),
				'live'    => $is,
				'checked' => daw_news_checked( $pid ),
				'reason'  => implode( '; ', $why ),
				'url'     => get_permalink( $pid ),
			);
		}
	}
	$out = array(
		'at'    => time(),
		'total' => count( $ids ),
		'live'  => $live,
		'rows'  => $rows,
	);
	update_option( 'daw_news_stale', $out, false );
	return $out;
}

add_action( 'daw_fix_tick', 'daw_nl_tick', 50 );
function daw_nl_tick() {
	$r = get_option( 'daw_news_stale' );
	if ( ! is_array( $r ) || (int) ( $r['at'] ?? 0 ) < time() - 6 * HOUR_IN_SECONDS ) {
		daw_news_stale_run();
	}
}

/* ------------------------------------------------------- person upcoming -- */

/**
 * What is confirmed to come for one person, and what is on air now:
 * [ 'items' => [ [title, url, label, when, sort, role], ... ], 'news' => WP_Post|null ].
 * Titles come from the person's own credits (a record exists only once a
 * project is confirmed); rows come from the living stories about them.
 */
function daw_person_upcoming( $person_id ) {
	$person_id = (int) $person_id;
	$items     = array();
	$have      = array();
	$now       = time();

	$film = function_exists( 'daw_get_filmography' ) ? daw_get_filmography( $person_id ) : array();
	foreach ( (array) $film as $bucket => $rows ) {
		foreach ( (array) $rows as $row ) {
			$id = (int) $row['id'];
			if ( isset( $have[ $id ] ) ) {
				continue;
			}
			$st = daw_nl_status( $id );
			if ( 'upcoming' !== $st && 'onair' !== $st ) {
				continue;
			}
			$start       = (int) daw_field_raw( $id, 'daw_aired_start' );
			$have[ $id ] = true;
			$items[]     = array(
				'title' => html_entity_decode( (string) $row['title'], ENT_QUOTES, 'UTF-8' ),
				'url'   => (string) $row['url'],
				'label' => 'onair' === $st ? __( 'On air now', 'daw' ) : __( 'Upcoming', 'daw' ),
				'where' => (string) $row['network'],
				'line'  => 'onair' === $st ? '' : ( $start && $start > $now
					/* translators: %s: premiere date */
					? sprintf( __( 'Confirmed, premieres %s', 'daw' ), gmdate( 'j F Y', $start ) )
					: __( 'Confirmed, premiere date not announced yet', 'daw' ) ),
				'sort'  => 'onair' === $st ? 0 : ( $start ? $start : PHP_INT_MAX - 1 ),
				'role'  => (string) $row['role'],
			);
		}
	}

	$news = null;
	foreach ( daw_nl_person_news( $person_id ) as $nid ) {
		if ( ! $news ) {
			$news = get_post( $nid );
		}
		foreach ( daw_news_next_rows( $nid ) as $r ) {
			$row = daw_nl_row( $r );
			if ( $row['gone'] || ( $row['id'] && isset( $have[ $row['id'] ] ) ) ) {
				continue;
			}
			$key = $row['id'] ? $row['id'] : 'n:' . daw_search_norm( $row['title'] );
			if ( isset( $have[ $key ] ) ) {
				continue; // the same project named by two stories
			}
			$have[ $key ] = true;
			$items[]      = array(
				'title' => $row['title'],
				'url'   => $row['url'],
				'label' => 'onair' === $row['label'] ? __( 'On air now', 'daw' ) : __( 'Upcoming', 'daw' ),
				'where' => $row['where'],
				'line'  => 'onair' === $row['label'] ? '' : $row['line'],
				'sort'  => $row['sort'],
				'role'  => '',
			);
		}
	}

	usort( $items, static fn( $a, $b ) => $a['sort'] <=> $b['sort'] );
	return array( 'items' => $items, 'news' => $news );
}

/** Living stories about one person, most recently checked first. */
function daw_nl_person_news( $person_id ) {
	$map = get_transient( 'daw_nl_up_v1' );
	if ( ! is_array( $map ) ) {
		$map = array();
		$ids = function_exists( 'daw_audit_record_ids' ) ? daw_audit_record_ids( array( 'news' ) ) : array();
		foreach ( $ids as $nid ) {
			$nid = (int) $nid;
			if ( ! daw_news_is_live( $nid ) ) {
				continue;
			}
			$about = daw_news_about( $nid );
			if ( $about ) {
				$map[ $about ][ $nid ] = max( daw_news_checked( $nid ), (int) get_post_modified_time( 'U', true, $nid ) );
			}
		}
		set_transient( 'daw_nl_up_v1', $map, 6 * HOUR_IN_SECONDS );
	}
	$mine = (array) ( $map[ (int) $person_id ] ?? array() );
	arsort( $mine );
	return array_map( 'intval', array_keys( $mine ) );
}

add_action( 'save_post_post', 'daw_nl_flush', 30 );
function daw_nl_flush() {
	delete_transient( 'daw_nl_up_v1' );
}
add_action( 'updated_post_meta', 'daw_nl_flush_meta', 10, 3 );
add_action( 'added_post_meta', 'daw_nl_flush_meta', 10, 3 );
function daw_nl_flush_meta( $mid, $pid, $key ) {
	if ( in_array( $key, array( 'daw_news_live', 'daw_news_next', 'daw_news_about' ), true ) ) {
		delete_transient( 'daw_nl_up_v1' );
	}
}

/** The box on a person's page. Empty string when nothing is confirmed. */
function daw_person_upcoming_html( $person_id ) {
	$up = daw_person_upcoming( $person_id );
	if ( ! $up['items'] && ! $up['news'] ) {
		return '';
	}
	$name = get_the_title( $person_id );
	ob_start();
	?>
	<section class="daw-section daw-upnext" id="upcoming">
		<h2><?php
		// The heading says what the list holds: a show already on air is not
		// "upcoming" (reported on Seo Kang Joon's page, 6 Oct 2026).
		$on_air = 0;
		$coming = 0;
		foreach ( $up['items'] as $it ) {
			if ( __( 'On air now', 'daw' ) === $it['label'] ) {
				++$on_air;
			} else {
				++$coming;
			}
		}
		if ( $on_air && $coming ) {
			/* translators: %s: person name */
			$h = __( '%s dramas and movies on air now and coming up', 'daw' );
		} elseif ( $on_air ) {
			/* translators: %s: person name */
			$h = __( '%s dramas and movies on air now', 'daw' );
		} else {
			/* translators: %s: person name */
			$h = __( '%s upcoming dramas and movies', 'daw' );
		}
		printf( esc_html( $h ), esc_html( $name ) );
		?></h2>
		<?php if ( $up['items'] ) : ?>
			<ul class="daw-upnext-list">
				<?php foreach ( $up['items'] as $it ) : ?>
					<li>
						<span class="daw-upnext-badge<?php echo __( 'On air now', 'daw' ) === $it['label'] ? ' is-on' : ''; ?>"><?php echo esc_html( $it['label'] ); ?></span><?php echo ' '; ?>
						<?php if ( $it['url'] ) : ?>
							<a href="<?php echo esc_url( $it['url'] ); ?>"><strong><?php echo esc_html( $it['title'] ); ?></strong></a>
						<?php else : ?>
							<strong><?php echo esc_html( $it['title'] ); ?></strong>
						<?php endif; ?>
						<?php if ( '' !== $it['role'] ) : ?>
							<span class="daw-upnext-role"><?php echo esc_html( $it['role'] ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $it['where'] ) : ?>
							<span class="daw-upnext-role daw-upnext-where"><?php echo esc_html( $it['where'] ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $it['line'] ) : ?>
							<span class="daw-upnext-when"><?php echo esc_html( $it['line'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="daw-upnext-none"><?php
			/* translators: %s: person name */
			printf( esc_html__( 'No new project of %s is confirmed yet.', 'daw' ), esc_html( $name ) );
			?></p>
		<?php endif; ?>
		<?php
		// "Only confirmed" is about projects still to come. A show already on
		// air needs no such note (owner, 6 Oct 2026), so it is printed only
		// when the box holds an upcoming project or says there is none yet.
		$daw_note = $coming || ! $up['items'];
		if ( ! $daw_note && ! $up['news'] ) {
			$daw_note = null;
		}
		?>
		<?php if ( null !== $daw_note ) : ?>
		<p class="daw-upnext-note">
			<?php if ( $daw_note ) : ?>
				<?php esc_html_e( 'Only confirmed projects are listed; projects under consideration are not.', 'daw' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Latest news:', 'daw' ); ?>
			<?php endif; ?>
			<?php if ( $up['news'] ) : ?>
				<?php
				$nid = (int) $up['news']->ID;
				$chk = daw_news_checked( $nid );
				?>
				<a href="<?php echo esc_url( get_permalink( $nid ) ); ?>"><?php echo esc_html( wp_specialchars_decode( get_the_title( $nid ), ENT_QUOTES ) ); ?></a>
				<?php if ( $chk ) : ?>
					<span>(<?php
					/* translators: %s: date */
					printf( esc_html__( 'checked %s', 'daw' ), esc_html( gmdate( 'j F Y', $chk ) ) );
					?>)</span>
				<?php endif; ?>
			<?php endif; ?>
		</p>
		<?php endif; ?>
	</section>
	<?php
	return (string) ob_get_clean();
}

/** The "What's next" box on a living story. */
function daw_news_next_html( $pid ) {
	if ( ! daw_news_is_live( $pid ) ) {
		return '';
	}
	// Read through the same resolver as the person box (1.75.49): a row whose
	// project has aired drops out, one on air says so, a record's date wins.
	$rows = array();
	foreach ( daw_news_next_rows( $pid ) as $r ) {
		$row = daw_nl_row( $r );
		if ( ! $row['gone'] ) {
			$rows[] = $row;
		}
	}
	usort( $rows, static fn( $a, $b ) => $a['sort'] <=> $b['sort'] );
	$chk  = daw_news_checked( $pid );
	if ( ! $rows && ! $chk ) {
		return '';
	}
	ob_start();
	?>
	<section class="daw-nnext" id="whats-next">
		<h2><?php esc_html_e( "What's next", 'daw' ); ?></h2>
		<?php if ( $rows ) : ?>
			<ul class="daw-upnext-list">
				<?php foreach ( $rows as $r ) : ?>
					<li>
						<?php if ( $r['url'] ) : ?>
							<a href="<?php echo esc_url( $r['url'] ); ?>"><strong><?php echo esc_html( $r['title'] ); ?></strong></a>
						<?php else : ?>
							<strong><?php echo esc_html( $r['title'] ); ?></strong>
						<?php endif; ?>
						<?php if ( '' !== $r['where'] ) : ?>
							<span class="daw-upnext-role daw-upnext-where"><?php echo esc_html( $r['where'] ); ?></span>
						<?php endif; ?>
						<span class="daw-upnext-when"><?php echo esc_html( $r['line'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p><?php esc_html_e( 'Nothing new is confirmed yet.', 'daw' ); ?></p>
		<?php endif; ?>
		<p class="daw-upnext-note">
			<?php esc_html_e( 'Only confirmed plans are listed.', 'daw' ); ?>
			<?php if ( $chk ) : ?>
				<?php
				/* translators: %s: date */
				printf( esc_html__( 'Last checked %s.', 'daw' ), esc_html( gmdate( 'j F Y', $chk ) ) );
				?>
			<?php endif; ?>
		</p>
	</section>
	<?php
	return (string) ob_get_clean();
}

/* --------------------------------------------------------------- admin -- */

add_filter( 'rwmb_meta_boxes', 'daw_nl_box', 15 );
function daw_nl_box( $boxes ) {
	$boxes   = (array) $boxes;
	$boxes[] = array(
		'id'         => 'daw_news_live_box',
		'title'      => __( 'News — living story', 'daw' ),
		'post_types' => array( 'post' ),
		'context'    => 'side',
		'daw_types'  => array( 'news' ),
		'fields'     => array(
			array(
				'id'      => 'daw_news_live',
				'name'    => __( 'Living story', 'daw' ),
				'type'    => 'select',
				'options' => array( '' => 'Otomatik (başlıktan)', '1' => 'Evet: güncellenir', '0' => 'Hayır: bitmiş haber' ),
			),
			array(
				'id'   => 'daw_news_checked',
				'name' => __( 'Last checked (YYYY-MM-DD)', 'daw' ),
				'type' => 'text',
				'desc' => 'Bütün haberi kontrol ettiğin gün. 30 gün sonra tekrar listeye düşer.',
			),
			array(
				'id'   => 'daw_news_next',
				'name' => __( "What's next", 'daw' ),
				'type' => 'textarea',
				'rows' => 4,
				'desc' => 'Satır başına: tarih | ne | kayıt no | done. Tarih 2026-11-20, 2026-11 ya da "late 2026". Sadece kesinleşenler. Sonuçlanan satırın sonuna | done yaz: uyarı vermez, kutulardan kalkar.',
			),
			array(
				'id'   => 'daw_news_about',
				'name' => __( 'About (person record id)', 'daw' ),
				'type' => 'number',
				'desc' => 'Boşsa başlıktaki kişi alınır.',
			),
		),
	);
	return $boxes;
}

/* ======================================================= updated date === */

/*
 * THE "UPDATED" DATE IS A NEW TIMELINE ROW, NOTHING ELSE
 * (8 Oct 2026, Umut's SEO spec, item 5)
 * post_modified moves on every save: a typo, a meta field, a cron repair. A
 * story that only had its spelling fixed read as "Updated today", and the
 * front page sorted by publish date, so it led with a story from June 2025.
 * daw_news_updated is written only when the timeline gains a row, and the
 * front page, the story's byline and its NewsArticle dateModified all read
 * the same date.
 */
const DAW_NEWS_UPDATED = 'daw_news_updated';

/** Non-empty rows in a timeline value, whatever shape it was stored in. */
function daw_news_row_count( $value ) {
	$value = maybe_unserialize( $value );
	if ( is_array( $value ) ) {
		return count( array_filter( array_map( static fn( $v ) => is_scalar( $v ) ? trim( (string) $v ) : '', $value ), 'strlen' ) );
	}
	return '' === trim( (string) $value ) ? 0 : 1;
}

add_filter( 'update_post_metadata', 'daw_news_updated_watch', 10, 4 );
add_filter( 'add_post_metadata', 'daw_news_updated_watch', 10, 4 );
function daw_news_updated_watch( $check, $post_id, $key, $value ) {
	if ( 'daw_news_tl_event' !== $key ) {
		return $check;
	}
	$old = 0;
	foreach ( get_post_meta( (int) $post_id, $key ) as $v ) {
		$old += daw_news_row_count( $v );
	}
	if ( daw_news_row_count( $value ) > $old && $old > 0 ) {
		// A first row written with the story is its publication, not an update.
		update_post_meta( (int) $post_id, DAW_NEWS_UPDATED, time() );
	}
	return $check;
}

/** When a real update row was last added, or 0. */
function daw_news_updated_ts( $post_id ) {
	return (int) get_post_meta( (int) $post_id, DAW_NEWS_UPDATED, true );
}

/** The date a story is shown and sorted by: its last real update, else its publication. */
function daw_news_shown_ts( $post_id ) {
	return max( daw_news_updated_ts( $post_id ), (int) get_post_time( 'U', true, (int) $post_id ) );
}

/**
 * The newest stories by that date. The newest by publication plus the
 * recently updated ones are the only candidates, so this is two small queries.
 */
function daw_news_latest_ids( $limit = 4 ) {
	$pool = function_exists( 'daw_browse_ids' ) ? array_slice( (array) daw_browse_ids( array( 'news' ), array_merge( daw_browse_request(), array( 'sort' => 'new' ) ), $limit * 3 ), 0, $limit * 3 ) : array();
	$upd  = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $limit * 2,
			'fields'         => 'ids',
			'meta_key'       => DAW_NEWS_UPDATED, // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);
	$ids = array_values( array_unique( array_map( 'intval', array_merge( $pool, (array) $upd ) ) ) );
	if ( function_exists( 'daw_get_type' ) ) {
		$ids = array_values( array_filter( $ids, static fn( $i ) => 'news' === daw_get_type( $i ) ) );
	}
	usort( $ids, static fn( $a, $b ) => daw_news_shown_ts( $b ) <=> daw_news_shown_ts( $a ) );
	return array_slice( $ids, 0, $limit );
}

/* ================================================= one row, both boxes === */

/*
 * A "WHAT'S NEXT" ROW AND THE RECORD IT NAMES ARE ONE THING (1.75.49)
 * (Umut, 8 Oct 2026: "birbirini beslemeli")
 * ------------------------------------------------------------------
 * Bae Suzy's box printed the row text as typed: "Portraits of Delusion on
 * Disney+ (no date announced)" with "Confirmed, premieres Second half of
 * 2026" under it, which contradicts itself, and "Confirmed, Date not
 * announced". A row is now read, not echoed:
 *   - the title, where it airs ("on Netflix", "in Korean cinemas") and a
 *     note in brackets are told apart;
 *   - a row that names a record (its id, or its exact title once the record
 *     exists) takes that record's own state: its premiere date, On air now
 *     when it starts, gone from "upcoming" once it has aired. The row in the
 *     story and the box on the person page change together, without anyone
 *     editing the story;
 *   - the date line is one of a fixed set of sentences, never the raw text.
 * The person box and the story's "What's next" box both read rows through
 * this one function.
 */

/** Lower-case the first letter of a timing phrase, never of a month or a name. */
function daw_nl_lc( $s ) {
	return preg_match( '/^(First|Second|Early|Mid|Late|Spring|Summer|Autumn|Fall|Winter|Filming|Shooting|Date|Premiere|Release|Coming|Expected|In)\b/', $s ) ? lcfirst( $s ) : $s;
}

/** Where a project airs, split off the end of a row's text. */
function daw_nl_where_re() {
	return '/\s+(?:on|via)\s+(Netflix|Disney\+|Disney Plus|tvN|JTBC|SBS|KBS2|KBS 2TV|KBS1|KBS|MBC|ENA|MBN|TV Chosun|Channel A|Coupang Play|TVING|Tving|Wavve|Apple TV\+|Prime Video|Amazon Prime Video|Viki|iQIYI|Viu|Genie TV|U\+ Mobile TV|Hulu|WeTV|Youku|Tencent Video|Fuji TV|TBS|NHK|TV Asahi|Nippon TV|ABEMA|Mango TV|Hunan TV|CCTV|GMMTV|Channel 3|One31)\s*$|\s+in\s+((?:[A-Z][a-z]+\s+)?(?:cinemas|theaters|theatres))\s*$/u';
}

/** A record of a drama, film or show with exactly this title, or 0. */
function daw_nl_title_record( $title ) {
	$needle = function_exists( 'daw_search_norm' ) ? daw_search_norm( $title ) : '';
	if ( '' === $needle || ! function_exists( 'daw_search_ids' ) ) {
		return 0;
	}
	foreach ( (array) daw_search_ids( $title ) as $id ) {
		if ( in_array( daw_get_type( $id ), array( 'drama', 'film', 'variety', 'anime' ), true ) && daw_search_norm( get_the_title( $id ) ) === $needle ) {
			return (int) $id;
		}
	}
	return 0;
}

/**
 * One row, read: [title, url, id, where, label ('upcoming'|'onair'|''),
 * line, sort, gone]. gone = the project has aired and is no longer next.
 */
function daw_nl_row( array $r ) {
	$when = trim( (string) $r[0] );
	$what = trim( html_entity_decode( wp_strip_all_tags( (string) $r[1] ), ENT_QUOTES, 'UTF-8' ) );
	$note = '';
	if ( preg_match( '/\s*\(([^()]*)\)\s*$/u', $what, $m ) ) {
		$note = trim( $m[1] );
		$what = trim( substr( $what, 0, -strlen( $m[0] ) ) );
	}
	$where = '';
	if ( preg_match( daw_nl_where_re(), $what, $m ) ) {
		$where = trim( '' !== ( $m[1] ?? '' ) ? $m[1] : ( $m[2] ?? '' ) );
		$what  = trim( substr( $what, 0, -strlen( $m[0] ) ) );
	}

	$id = (int) $r[2];
	if ( ! $id ) {
		$id = daw_nl_title_record( $what );
	}
	if ( $id && 'publish' !== get_post_status( $id ) ) {
		$id = 0;
	}

	$out = array(
		'title' => $id ? html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) : $what,
		'url'   => $id ? (string) get_permalink( $id ) : '',
		'id'    => $id,
		'where' => $where,
		'label' => 'upcoming',
		'line'  => '',
		'sort'  => PHP_INT_MAX,
		'gone'  => false,
	);

	// The record knows better than the story once it exists.
	if ( $id ) {
		$st    = daw_nl_status( $id );
		$start = (int) daw_field_raw( $id, 'daw_aired_start' );
		if ( 'onair' === $st ) {
			$out['label'] = 'onair';
			$out['line']  = __( 'On air now', 'daw' );
			$out['sort']  = 0;
			return $out;
		}
		if ( 'upcoming' !== $st && $start && $start <= time() ) {
			$out['gone'] = true;
			return $out;
		}
		if ( $start && $start > time() ) {
			/* translators: %s: premiere date */
			$out['line'] = sprintf( __( 'Confirmed, premieres %s', 'daw' ), gmdate( 'j F Y', $start ) );
			$out['sort'] = $start;
			return $out;
		}
	}

	$ts = daw_nl_when_ts( $when );
	if ( $ts && $ts < time() - DAY_IN_SECONDS ) {
		$out['gone'] = true; // a date that has passed is the story's to fix, not the reader's to see
		return $out;
	}
	$unknown = '/\b(not announced|no date|tba|tbc|tbd|unknown|to be announced|to be confirmed)\b/i';
	if ( '' !== $when && preg_match( $unknown, $when ) ) {
		$when = '';
	}
	if ( '' !== $note && preg_match( $unknown, $note ) ) {
		$note = '';
	}

	if ( $ts ) {
		/* translators: %s: premiere date or month */
		$out['line'] = sprintf( __( 'Confirmed, premieres %s', 'daw' ), daw_nl_when_label( $when ) );
		$out['sort'] = $ts;
	} elseif ( '' !== $when && preg_match( '/\b((?:19|20)\d{2})\b/', $when, $y ) ) {
		$phrase = daw_nl_lc( $when );
		if ( preg_match( '/^(first|second) half\b/i', $phrase ) ) {
			$phrase = 'in the ' . $phrase;
		} elseif ( preg_match( '/^(early|mid|late|spring|summer|autumn|fall|winter)\b/i', $phrase ) ) {
			$phrase = 'in ' . $phrase;
		}
		/* translators: %s: when, e.g. "in the second half of 2026" */
		$out['line'] = sprintf( __( 'Confirmed, premieres %s', 'daw' ), $phrase );
		$out['sort'] = (int) strtotime( $y[1] . '-12-31 UTC' );
	} elseif ( '' !== $when || '' !== $note ) {
		/* translators: %s: what is known about timing, e.g. "filming now" */
		$out['line'] = sprintf( __( 'Confirmed, %s', 'daw' ), daw_nl_lc( '' !== $when ? $when : $note ) );
	} else {
		$out['line'] = __( 'Confirmed, premiere date not announced yet', 'daw' );
	}
	return $out;
}
