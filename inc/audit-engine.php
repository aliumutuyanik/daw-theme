<?php
/**
 * Discover Asian World: the audit engine behind DAW › Denetim.
 *
 * WHAT IT DOES
 * ------------
 * For any record it answers, field by field, "is this filled from a source,
 * researched and empty, or not looked at yet", and then checks the rules the
 * site lives by: script of native names, dates against status, word counts,
 * em dashes, AI tells, placeholders, SEO lengths, keyword placement, the
 * schema the page actually sends, the picture and its rights.
 *
 * WHY THE FIELD LIST IS NOT WRITTEN HERE
 * --------------------------------------
 * It is read from the meta boxes the plugin registers (each box declares its
 * daw_types). A field added tomorrow is audited tomorrow without anyone
 * remembering to add it here, which is the owner's condition: nothing skipped.
 *
 * THE THREE STATES OF AN EMPTY FIELD
 * ----------------------------------
 *   dolu   filled
 *   yok    researched, no source (listed in the private meta _daw_yok, or the
 *          record carries a current verification stamp, which attests it)
 *   eksik  nobody has looked yet: keeps the record open
 * A field that cannot apply to this record (a death date for the living, a
 * spouse for someone unmarried) is "—".
 *
 * Results are cached per record in the private meta _daw_audit and rebuilt on
 * save, hourly in small batches, and on demand.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_AE_VERSION = 7; // 7: full keyword research rule (1.75.71)
const DAW_AE_META    = '_daw_audit';
const DAW_AE_YOK     = '_daw_yok';
const DAW_AE_KW      = '_daw_kw';
// Madde 48: a missing pattern is red only at this volume or in the top N.
const DAW_KW_MUST_VOL = 1000;
const DAW_KW_MUST_TOP = 15;
const DAW_KW_FULL_SINCE = '2026-10-15'; // full-list research rule (1.75.71)
const DAW_AE_LOCK    = '_daw_lock';

/* ============================================================ registry === */

/** Every meta box the plugin registers, with its daw_types. Cached. */
function daw_ae_boxes() {
	static $boxes = null;
	if ( null !== $boxes ) {
		return $boxes;
	}
	$boxes = array();
	foreach ( (array) apply_filters( 'rwmb_meta_boxes', array() ) as $box ) {
		if ( ! empty( $box['daw_types'] ) && ! empty( $box['fields'] ) ) {
			$boxes[] = $box;
		}
	}
	return $boxes;
}

/** The type groups the panel shows. */
function daw_ae_groups() {
	return array(
		'title'  => array( 'label' => 'Dizi ve film', 'types' => array( 'drama', 'film' ) ),
		'show'   => array( 'label' => 'Varyete ve anime', 'types' => array( 'variety', 'anime' ) ),
		'person' => array( 'label' => 'Kişi', 'types' => array( 'person' ) ),
		'group'  => array( 'label' => 'Grup ve şarkıcı', 'types' => array( 'group' ) ),
		'guide'  => array( 'label' => 'Rehber', 'types' => function_exists( 'daw_guide_types' ) ? daw_guide_types() : array() ),
		'news'   => array( 'label' => 'Haber', 'types' => array( 'news' ) ),
	);
}

function daw_ae_group_of( $type ) {
	foreach ( daw_ae_groups() as $k => $g ) {
		if ( in_array( $type, $g['types'], true ) ) {
			return $k;
		}
	}
	return '';
}

/** The boxes (and their fields) that apply to one type. */
function daw_ae_boxes_for( $type ) {
	$out = array();
	foreach ( daw_ae_boxes() as $box ) {
		if ( in_array( $type, (array) $box['daw_types'], true ) ) {
			$out[] = $box;
		}
	}
	return $out;
}

/* ============================================================== values === */

/** All stored values of a key, flattened to non-empty scalars. */
function daw_ae_values( $post_id, $key ) {
	$flat = array();
	$walk = static function ( $v ) use ( &$walk, &$flat ) {
		if ( is_array( $v ) ) {
			foreach ( $v as $x ) {
				$walk( $x );
			}
			return;
		}
		if ( is_object( $v ) ) {
			return;
		}
		$v = trim( (string) $v );
		if ( '' !== $v && '0' !== $v ) {
			$flat[] = $v;
		}
	};
	foreach ( (array) get_post_meta( $post_id, $key, false ) as $raw ) {
		$walk( maybe_unserialize( $raw ) );
	}
	return $flat;
}

/** Row count of a clone field (parallel arrays compare on this). */
function daw_ae_rows( $post_id, $key ) {
	$v = get_post_meta( $post_id, $key, true );
	return is_array( $v ) ? count( $v ) : ( '' === trim( (string) $v ) ? 0 : 1 );
}

function daw_ae_preview( $vals ) {
	if ( ! $vals ) {
		return '';
	}
	if ( count( $vals ) > 1 ) {
		return sprintf( '%d değer', count( $vals ) );
	}
	$s = wp_strip_all_tags( $vals[0] );
	return mb_strlen( $s ) > 90 ? mb_substr( $s, 0, 88 ) . '…' : $s;
}

/** Note prefix of a field closed because the record itself rules it out. */
const DAW_AE_IMP = 'Mantıken uygulanmaz: ';

/**
 * Fields a person cannot have, decided from their own record (owner, 7 Oct
 * 2026). These used to be researched like any empty field, ten places and a
 * date each, to prove that a woman has no military service. They close here
 * with the reason, and stay visible as their own count on the record and in
 * the control centre so nothing is hidden: if the deciding field changes
 * (a group is added, the music answer turns to yes), the row opens again.
 */
function daw_ae_impossible( $post_id, $id, $type ) {
	if ( 'person' !== $type ) {
		return '';
	}
	if ( 0 === strpos( $id, 'daw_mil_' ) || 'daw_military_panel' === $id ) {
		$gender = mb_strtolower( implode( ' ', daw_ae_values( $post_id, 'daw_gender' ) ) );
		if ( preg_match( '/\b(female|woman|kad[ıi]n)\b/u', $gender ) ) {
			return 'kadın, askerlik yükümlülüğü yok';
		}
	}
	if ( in_array( $id, array( 'daw_group_position', 'daw_group_badge', 'daw_member_role' ), true ) && ! daw_ae_values( $post_id, 'daw_member_of' ) ) {
		return 'bir gruba üye değil ("Member of" boş)';
	}
	if ( in_array( $id, array( 'daw_spotify_artist', 'daw_link_apple' ), true ) ) {
		$act = mb_strtolower( implode( ' ', daw_ae_values( $post_id, 'daw_music_act' ) ) );
		$job = mb_strtolower( wp_strip_all_tags( implode( ' ', daw_ae_values( $post_id, 'daw_occupation' ) ) ) );
		if ( 'no' === $act ) {
			return 'şarkıcı değil (Müzik sayfası: hayır)';
		}
		if ( '' === $act && ! daw_ae_values( $post_id, 'daw_member_of' ) && ! preg_match( '/sing|idol|rapper|vocal|musician|songwriter|composer|band|\bdj\b|şarkı|müzisyen/u', $job ) ) {
			return 'şarkıcı değil (meslek: ' . ( '' !== $job ? mb_substr( $job, 0, 40 ) : 'yazılmamış' ) . ')';
		}
	}
	if ( 'daw_died' === $id ) {
		return 'yaşıyor, ölüm tarihi yok';
	}
	return '';
}

/** Fields that cannot apply to this record, with the reason. */
function daw_ae_not_applicable( $post_id, $id, $type ) {
	$imp = daw_ae_impossible( $post_id, $id, $type );
	if ( '' !== $imp ) {
		return DAW_AE_IMP . $imp;
	}
	$always = array(
		'daw_gallery'   => 'Galeri politikası: izinli malzeme yoksa boş kalır',
		'daw_blgl_url'  => 'Sadece BL/GL yapımlar',
		'daw_paw_name'  => 'Eski tek satırlık alan, yerini ödül tablosu aldı',
		'daw_spotify_uri' => 'Tema bu alanı çizmez; OST albümü daw_ost_album alanında (madde 39)',
		// Only for a production TMDB lists twice (madde 72). Empty is the
		// normal state; the coverage check says when one is needed.
		'daw_tmdb_alt'  => 'Yalnız TMDB\'de iki kimlikle geçen yapımlar; gerekirse kapsam satırı söyler (madde 72)',
	);
	if ( isset( $always[ $id ] ) ) {
		return $always[ $id ];
	}
	if ( 0 === strpos( $id, 'daw_shop_' ) ) {
		return 'Ortaklık programı onaylanınca doldurulur';
	}
	if ( in_array( $id, array( 'daw_news_live', 'daw_news_about' ), true ) ) {
		return 'Otomatik: boşsa haberin başlığından bulunur';
	}
	if ( in_array( $id, array( 'daw_news_checked', 'daw_news_next' ), true ) && function_exists( 'daw_news_is_live' ) && ! daw_news_is_live( $post_id ) ) {
		return 'Bitmiş haber: ileriye dönük bilgi taşımıyor';
	}
	if ( 'daw_news_next' === $id && function_exists( 'daw_news_checked' ) && daw_news_checked( $post_id ) ) {
		return 'Kontrol edildi: kesinleşmiş yeni plan yok';
	}
	if ( 'daw_awards' === $id && daw_ae_rows( $post_id, 'daw_aw_year' ) > 0 ) {
		return 'Ödül tablosu dolu; aynı bilgiyi düz yazı olarak tekrar yazmak K14 ihlali';
	}
	if ( 'daw_best_scenes' === $id && daw_ae_rows( $post_id, 'daw_scene_title' ) > 0 ) {
		return 'Sahne satırları dolu; tema bu paneli çizmez (K14)';
	}
	if ( 'daw_food_kind' === $id && 'food' !== $type ) {
		return 'Yalnız yemek ve içecek rehberinde (Food sayfasının filtresi)';
	}
	if ( 'daw_studio' === $id && 'anime' !== $type ) {
		return 'Yalnız animede';
	}
	if ( 'film' === $type && in_array( $id, array( 'daw_seasons', 'daw_episodes', 'daw_aired_end', 'daw_airing_days', 'daw_cast_supporting_eps', 'daw_cast_guest_eps', 'daw_scene_ep' ), true ) ) {
		return 'Filmde bölüm, sezon ve bitiş tarihi yok';
	}
	if ( 'daw_seasons' === $id && function_exists( 'daw_season_no' ) && daw_season_no( $post_id ) ) {
		return 'Sezon sayfası tek sezonu anlatır; sezon sayısı seri kaydında (madde 65)';
	}
	if ( 'daw_title_section_title' === $id || 'daw_title_section_body' === $id ) {
		return 'İsteğe bağlı: yalnız ayda 1.000+ aranan ayrı bir soru için açılır (madde 63)';
	}
	if ( 'daw_producer' === $id && 'variety' !== $type ) {
		return 'Yalnız varyetede';
	}
	if ( 'daw_original_author' === $id && '' === trim( (string) get_post_meta( $post_id, 'daw_adaptation', true ) ) ) {
		return 'Uyarlama değil';
	}
	if ( 'daw_ep_no' === $id || 'daw_ep_title' === $id || 'daw_ep_date' === $id || 'daw_ep_rating' === $id || 'daw_ep_synopsis' === $id ) {
		if ( 'film' === $type ) {
			return 'Filmde bölüm yok';
		}
		if ( 'daw_ep_rating' === $id && in_array( $type, array( 'anime' ), true ) ) {
			return 'Animede reyting tablosu tutulmuyor';
		}
	}
	if ( 'daw_cast_main_eps' === $id ) {
		return 'Başrol tam sezon oynar: bölüm sütunu boş kalır (K18)';
	}
	if ( 'daw_cast_person' === $id ) {
		foreach ( array( 'main', 'supporting', 'guest' ) as $tier ) {
			if ( daw_ae_values( $post_id, "daw_cast_{$tier}_person" ) ) {
				return 'Eski düz liste; kadro kademelere ayrılmış';
			}
		}
	}
	if ( in_array( $type, array( 'person', 'group' ), true ) && in_array( $id, array( 'daw_faq_q', 'daw_faq_a' ), true ) ) {
		return 'Kişi ve grupta kendi SSS alanı kullanılır';
	}
	if ( 'person' === $type ) {
		$marital = strtolower( implode( ' ', daw_ae_values( $post_id, 'daw_marital_status' ) ) );
		if ( in_array( $id, array( 'daw_spouse', 'daw_partner' ), true ) && false !== strpos( $marital, 'not married' ) ) {
			return 'Evli değil';
		}
	}
	if ( 'film' === $type && in_array( $id, array( 'daw_airing_days', 'daw_ep_no', 'daw_ep_date', 'daw_ep_title', 'daw_ep_synopsis', 'daw_ep_rating' ), true ) ) {
		return 'Filmde bölüm yok';
	}
	return '';
}

/* =============================================================== rules === */

function daw_ae_ai_words() {
	// Site rule 5.2 plus the K26 list of the audit skill (madde 50 and 52).
	return array(
		'nowadays', 'undoubtedly', 'delve', 'tapestry', 'testament to', 'boasts', 'nestled', 'vibrant',
		'in conclusion', 'on the other hand', 'in this context', 'rich history', "it's worth noting",
		'it is worth noting', "it's important to note", 'it is important to note', 'in the realm of',
		'must-watch', 'embark on', 'navigate the complexities', "in today's fast-paced world",
		"whether you're", 'look no further', 'dive into', 'unlock the', 'elevate your', 'seamless',
		'game-changer', 'stands as a', 'serves as a reminder', 'more than just a', 'leaves a lasting impression',
		'captivating', 'a masterclass in', 'resonates with audiences', 'cements his status', 'cements her status',
		'as we will see', 'in summary', 'to sum up', 'this version is seo', 'seo-optimized', '[eklenecek]', 'todo:',
		// Owner, 23 Oct 2026: the site needs its own voice, not stock AI
		// phrasing. Second list of set phrases that read as generated text.
		'rollercoaster', 'roller coaster of emotions', 'emotional roller coaster', 'breathtaking', 'must-see',
		'edge of their seats', 'edge of your seat', 'edge-of-your-seat', 'a feast for the eyes', 'tugs at the heartstrings',
		'heart-wrenching', 'something for everyone', 'keeps viewers hooked', 'leaves viewers', 'will leave you',
		'a true gem', 'showcases', 'showcasing', 'pivotal role', 'plays a pivotal', 'at its core', 'in the world of',
		'unforgettable', 'a testament', 'masterful', 'intricately', 'nuanced portrayal', 'solidified', 'solidifying',
		'cemented', 'takes viewers on', 'viewers are treated', 'fans of the genre will', 'a journey of', 'beloved by fans',
	);
}

/** Sentence shapes that read as generated text (owner, 23 Oct 2026). */
function daw_ae_ai_shapes() {
	return array(
		'/\\bnot only\\b[^.!?]{1,90}\\bbut also\\b/i'              => 'not only ... but also',
		'/\\bwhether you are\\b|\\bwhether you\'re\\b/i'               => 'whether you are ...',
		'/\\b(isn\'t|is not|wasn\'t|was not) (just|only|merely)\\b[^.!?]{1,70}[,;]\\s*(it\'s|it is|it was)\\b/i' => 'is not just X, it is Y',
		'/\\bmore than (just )?a (drama|series|film|movie|show|story)\\b/i' => 'more than just a drama',
	);
}

/** Rule problems in one text value: [] when clean. */
function daw_ae_text_issues( $s ) {
	$out = array();
	// A web address is somebody else's slug, not our writing: an article URL
	// with "seamless" in it is a source, not a cliché (24 Sep 2026).
	if ( preg_match( '#^https?://\S+$#i', trim( (string) $s ) ) ) {
		return $out;
	}
	if ( false !== strpos( $s, '—' ) ) {
		$out[] = 'uzun tire (kural 5.1)';
	}
	if ( preg_match( '/\s–\s/u', $s ) ) {
		$out[] = 'tire olarak kullanılmış en dash';
	}
	// AI words, clichés and sentence shapes moved to inc/voice.php (madde 75-77).
	if ( in_array( trim( $s ), array( 'N/A', 'n/a', 'TBA', 'Unknown', '-', 'Not recorded', 'To be confirmed', '[Information not available]', '[REDACTED]', 'N/A (Film)', 'N/A (Theatrical Release)' ), true ) ) {
		$out[] = 'yer tutucu değer';
	}
	if ( false !== strpos( $s, '&amp;amp;' ) || false !== strpos( $s, '&amp;#' ) ) {
		$out[] = 'HTML iki kez kaçışlanmış (kural 5.4)';
	}
	return $out;
}

/** Does the string use the script its country writes in? */
function daw_ae_native_script_ok( $s, $country ) {
	if ( '' === $s ) {
		return true;
	}
	// Official titles mix scripts: 재벌X형사 (Flex X Cop), SKY 캐슬, D.P. 디피.
	// What is wrong is a native title with NONE of the country's own script,
	// so a Latin letter is only a fault when the native script is missing.
	$c = strtolower( $country );
	// Vietnamese, Filipino, Indonesian and Malay are written in Latin letters,
	// so a Latin native title is the right one there (Forever Young 2, 1.75.87).
	if ( preg_match( '/vietnam|philippines|indonesia|malaysia/', $c ) ) {
		return true;
	}
	if ( preg_match( '/[A-Za-z]/', $s ) && ! preg_match( '/[\p{Hangul}\p{Hiragana}\p{Katakana}\p{Han}\p{Thai}]/u', $s ) ) {
		return false;
	}
	if ( false !== strpos( $c, 'korea' ) ) {
		return (bool) preg_match( '/\p{Hangul}/u', $s );
	}
	if ( false !== strpos( $c, 'japan' ) ) {
		return (bool) preg_match( '/[\p{Hiragana}\p{Katakana}\p{Han}]/u', $s );
	}
	if ( preg_match( '/china|taiwan|hong kong|singapore/', $c ) ) {
		return (bool) preg_match( '/\p{Han}/u', $s );
	}
	if ( false !== strpos( $c, 'thailand' ) ) {
		return (bool) preg_match( '/\p{Thai}/u', $s );
	}
	return true;
}

function daw_ae_country( $post_id ) {
	$t = get_the_terms( $post_id, 'dw_country' );
	return ( $t && ! is_wp_error( $t ) ) ? $t[0]->name : '';
}

/* ============================================================== engine === */

/**
 * Audit one record.
 *
 * @return array [
 *   'type', 'group', 'at',
 *   'sections' => [ key => [ 'label', 'items' => [ [label, value, state, note] ] ] ],
 *   'sum' => [ ok, no, wait, yok, na, eksik ],
 *   'col' => [ daw_ae_columns() key => ok|no|wait|na ]
 * ]
 */
function daw_ae_audit( $post_id ) {
	$post_id = (int) $post_id;
	$type    = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
	$group   = daw_ae_group_of( $type );
	$yok     = (array) get_post_meta( $post_id, DAW_AE_YOK, true );
	$country = daw_ae_country( $post_id );

	$sections = array();
	$rulehits = array();
	$texts    = array();

	// 1. Every registered field for this type.
	$boxes = daw_ae_boxes_for( $type );
	if ( ! $boxes ) {
		$sections['setup'] = array(
			'label' => 'Alan seti',
			'items' => array( array( 'Bu türün alanları', '', 'no', 'Bu tür (' . $type . ') için eklentide alan kutusu yok: alan seti açılmalı' ) ),
		);
	}
	foreach ( $boxes as $box ) {
		$items = array();
		foreach ( $box['fields'] as $f ) {
			if ( empty( $f['id'] ) || in_array( $f['type'] ?? '', array( 'heading', 'divider', 'custom_html', 'button' ), true ) ) {
				continue;
			}
			$id    = $f['id'];
			$label = wp_strip_all_tags( (string) ( $f['name'] ?? $id ) );
			$vals  = daw_ae_values( $post_id, $id );
			$na    = daw_ae_not_applicable( $post_id, $id, $type );

			$in_body = '';
			if ( 'guide' === $group && ! $vals && function_exists( 'daw_audit_body_has' ) ) {
				// Newer articles carry these things in their body, not in slots.
				$map = array( 'daw_glance_label' => 'table', 'daw_glance_value' => 'table', 'daw_faq_q' => 'questions', 'daw_faq_a' => 'questions', 'daw_guide_source_name' => 'sources', 'daw_guide_source_url' => 'sources' );
				if ( isset( $map[ $id ] ) && daw_audit_body_has( (string) get_post_field( 'post_content', $post_id ), $map[ $id ] ) ) {
					$in_body = 'Metnin içinde var';
				} elseif ( 'daw_guide_standfirst' === $id && '' !== trim( (string) get_post_field( 'post_excerpt', $post_id ) ) ) {
					$in_body = 'Özet (excerpt) olarak var';
				}
			}

			if ( '' !== $in_body ) {
				$state = 'ok';
				$note  = $in_body;
			} elseif ( $vals ) {
				$state = 'ok';
				$note  = '';
				foreach ( $vals as $v ) {
					$texts[] = array( $label, $v, $id );
				}
			} elseif ( '' !== $na ) {
				$state = 'na';
				$note  = $na;
			} elseif ( 0 === strpos( $id, 'daw_watch_' ) && function_exists( 'daw_wp_get' ) && daw_wp_get( $post_id ) ) {
				$state = 'yok';
				$note  = 'Ülkeye göre liste TMDB/JustWatch\'ten geliyor; elle satır isteğe bağlı';
			} elseif ( '' !== ( $tm_note = daw_ae_tmdb_has( $post_id, $id ) ) ) {
				// TMDB holds this episode data: "no source" is not an answer.
				$state = 'no';
				$note  = $tm_note;
			} elseif ( isset( $yok[ $id ] ) && '' !== ( $weak = daw_ae_yok_check( $id, $yok[ $id ], $yok ) ) ) {
				$state = 'eksik';
				$note  = $weak;
			} elseif ( isset( $yok[ $id ] ) && 'daw_trailer_youtube' === $id && ( $tna = daw_trailer_na_date( $yok[ $id ] ) ) && $tna < time() - DAW_TRAILER_RECHECK * DAY_IN_SECONDS ) {
				// Not found last time; searched again after DAW_TRAILER_RECHECK
				// days. Waiting, not failing: the record stays finished.
				$state = 'wait';
				$note  = 'Fragman yeniden aranacak (son bakış ' . gmdate( 'd.m.Y', $tna ) . ')';
			} elseif ( isset( $yok[ $id ] ) ) {
				$state = 'yok';
				$note  = $yok[ $id ];
			} else {
				// The stamp used to turn every empty field into N/A. That let a
				// field nobody had looked at pass as "researched, no source"
				// (owner, 24 Sep 2026). A field is N/A only when someone wrote
				// why, in _daw_yok.
				$state = 'eksik';
				$note  = 'Henüz bakılmadı';
			}
			$items[] = array( $label, daw_ae_preview( $vals ), $state, $note, $id );
		}
		if ( $items ) {
			$sections[ $box['id'] ] = array(
				'label' => wp_strip_all_tags( (string) ( $box['title'] ?? $box['id'] ) ),
				'items' => $items,
			);
		}
	}

	// 1b. A food record's "seen in" list (Food on screen).
	if ( function_exists( 'daw_fos_is_source' ) && daw_fos_is_source( $post_id ) ) {
		$food_items = daw_fos_audit_items( $post_id );
		if ( $food_items ) {
			$sections['food_seen'] = array( 'label' => 'Ekranda geçtiği yapımlar (Food on screen)', 'items' => $food_items );
		}
	}

	// 2. Rules across the record.
	$rules = array();
	foreach ( $texts as $t ) {
		foreach ( daw_ae_text_issues( $t[1] ) as $issue ) {
			$rules[] = array( $t[0], mb_substr( wp_strip_all_tags( $t[1] ), 0, 70 ), 'no', $issue );
		}
	}
	$title_text = get_the_title( $post_id );
	foreach ( daw_ae_text_issues( $title_text . ' ' . get_post_field( 'post_excerpt', $post_id ) ) as $issue ) {
		$rules[] = array( 'Başlık / özet', $title_text, 'no', $issue );
	}
	// Madde 75-81: site voice and originality (inc/voice.php, 27 Oct 2026).
	if ( function_exists( 'daw_voice_rules' ) ) {
		foreach ( daw_voice_rules( $post_id, $texts, $group ) as $row ) {
			$rules[] = $row;
		}
	}

	if ( in_array( $group, array( 'title', 'show' ), true ) ) {
		$native = implode( ' ', daw_ae_values( $post_id, 'daw_native_title' ) );
		$rules[] = array( 'Yerel ad yazısı', $native, daw_ae_native_script_ok( $native, $country ) ? 'ok' : 'no', daw_ae_native_script_ok( $native, $country ) ? '' : 'Ülkesinin alfabesiyle yazılmalı, Latin harf olmamalı' );
		$rules[] = array( 'Ülke', $country, '' !== $country ? 'ok' : 'no', '' !== $country ? '' : 'Ülke terimi yok (K-Drama sanılır)' );

		$start  = (int) get_post_meta( $post_id, 'daw_aired_start', true );
		$end    = (int) get_post_meta( $post_id, 'daw_aired_end', true );
		$status = strtolower( (string) get_post_meta( $post_id, 'daw_status', true ) );
		$now    = time();
		$want   = '';
		if ( $start && 'film' === $type ) {
			// A film is released once: before the date it is upcoming, after it completed.
			$want = $start > $now ? 'upcoming' : 'completed';
		} elseif ( $start ) {
			$want = $start > $now ? 'upcoming' : ( $end && $end < $now ? 'completed' : 'ongoing' );
		}
		$ok_status = '' === $want || '' === $status || $want === $status;
		$rules[]   = array( 'Durum ve tarihler', $status . ( $start ? ' · ' . gmdate( 'Y-m-d', $start ) : '' ) . ( $end ? ' → ' . gmdate( 'Y-m-d', $end ) : '' ), $ok_status ? 'ok' : 'no', $ok_status ? '' : 'Tarihlere göre "' . $want . '" olmalı' );
		if ( 'completed' === $status && ! $end && 'film' !== $type ) {
			$rules[] = array( 'Bitiş tarihi', '', 'no', 'Tamamlanmış yapımda son yayın boş kalamaz (K13)' );
		}

		// Length follows demand: a title people search for a lot (a pattern at
		// 5,000 a month or more) gets a fuller synopsis, 5 to 6 paragraphs and
		// at least 350 words; the rest 3 to 4 paragraphs and at least 200.
		// Either way every sentence is sourced and nothing repeats another
		// section (K14); length is never reached with filler.
		$syn_html = implode( ' ', daw_ae_values( $post_id, 'daw_synopsis' ) );
		$words    = str_word_count( wp_strip_all_tags( $syn_html ) );
		$paras    = max( 1, preg_match_all( '#<p[\s>]#i', $syn_html ) );
		$top_vol  = 0;
		$kw_all   = get_post_meta( $post_id, DAW_AE_KW, true );
		foreach ( is_array( $kw_all ) ? $kw_all : array() as $k ) {
			if ( is_array( $k ) && ( ! empty( $k['n'] ) || ! empty( $k['x'] ) ) ) {
				continue; // research-only pattern, not a target
			}
			$top_vol = max( $top_vol, is_array( $k ) ? (int) ( $k['vol'] ?? 0 ) : 0 );
		}
		$big     = $top_vol >= 5000;
		$min_w   = $big ? 350 : 200;
		$min_p   = $big ? 5 : 3;
		$syn_ok  = $words >= $min_w && $paras >= $min_p;
		// Extra H2 sections (madde 63): the heading carries a searched pattern
		// of 1,000+ a month, the text is at least 120 words.
		if ( function_exists( 'daw_get_title_sections' ) ) {
			foreach ( daw_get_title_sections( $post_id ) as $xs ) {
				$hit = false;
				foreach ( is_array( $kw_all ) ? $kw_all : array() as $k ) {
					if ( is_array( $k ) && (int) ( $k['vol'] ?? 0 ) >= 1000 && function_exists( 'daw_ae_kw_in' ) && daw_ae_kw_in( (string) $k['kw'], $xs['title'] ) ) {
						$hit = true;
						break;
					}
				}
				$xw      = str_word_count( wp_strip_all_tags( $xs['body'] ) );
				$xok     = $hit && $xw >= 120;
				$rules[] = array( 'Ek bölüm: ' . $xs['title'], $xw . ' kelime', $xok ? 'ok' : 'no', $xok ? '' : ( $hit ? 'En az 120 kelime' : 'Başlık ayda 1.000+ aranan bir kalıbı taşımalı (_daw_kw)' ) );
			}
		}
		$rules[] = array( 'Özet uzunluğu', $words . ' kelime · ' . $paras . ' paragraf', $syn_ok ? 'ok' : 'no', $syn_ok ? '' : ( $big ? 'Çok aranan yapım: en az 350 kelime, 5-6 paragraf' : 'En az 200 kelime, 3-4 paragraf' ) );
		$tk      = count( daw_ae_values( $post_id, 'daw_takeaways' ) );
		$rules[] = array( 'Öne çıkanlar', $tk . ' madde', ( $tk >= 4 && $tk <= 6 ) ? 'ok' : 'no', ( $tk >= 4 && $tk <= 6 ) ? '' : '4 ile 6 arası olmalı' );
		$fq      = daw_ae_rows( $post_id, 'daw_faq_q' );
		$rules[] = array( 'SSS', $fq . ' soru', $fq >= 5 ? 'ok' : 'wait', $fq >= 5 ? '' : 'En az 5 soru önerilir' );

		// A row repeating someone already credited in a higher tier is not
		// drawn on the page (daw_get_cast), so it owes no role or episodes of
		// its own; its empty cells are not counted against the tier.
		$cast_above = array();
		foreach ( array( 'main', 'supporting', 'guest' ) as $tier ) {
			$p = daw_ae_rows( $post_id, "daw_cast_{$tier}_person" );
			$r = daw_ae_rows( $post_id, "daw_cast_{$tier}_role" );
			$dup_role  = 0;
			$dup_rcell = 0;
			$dup_eps   = 0;
			$tp = (array) get_post_meta( $post_id, "daw_cast_{$tier}_person", true );
			if ( $cast_above ) {
				$tr_raw = get_post_meta( $post_id, "daw_cast_{$tier}_role", true );
				// An empty string cast to an array has a cell daw_ae_rows never
				// counted, which once read a role count of -1 (14206).
				$tr = is_array( $tr_raw ) ? $tr_raw : ( '' === trim( (string) $tr_raw ) ? array() : array( $tr_raw ) );
				$te = (array) get_post_meta( $post_id, "daw_cast_{$tier}_eps", true );
				foreach ( $tp as $i => $pid ) {
					if ( (int) $pid && isset( $cast_above[ (int) $pid ] ) ) {
						if ( '' === trim( (string) ( $tr[ $i ] ?? '' ) ) ) {
							$dup_role++;
							// An empty cell that exists is counted by
							// daw_ae_rows on the role side too.
							$dup_rcell += array_key_exists( $i, $tr ) ? 1 : 0;
						}
						$dup_eps  += '' === trim( (string) ( $te[ $i ] ?? '' ) ) ? 1 : 0;
					}
				}
			}
			foreach ( $tp as $pid ) {
				if ( (int) $pid ) {
					$cast_above[ (int) $pid ] = true;
				}
			}
			$p_eps = $p - $dup_eps;
			$p     = $p - $dup_role;
			$r     = $r - $dup_rcell;
			if ( $p !== $r ) {
				// Fewer roles than people, and the role field carries a sourced
				// madde 66 N/A: the names are credited, the parts were never
				// published (Let's Go! 18942). It counts like the episode line
				// below; a wrong count the other way stays red.
				$rk = "daw_cast_{$tier}_role";
				if ( $r < $p && isset( $yok[ $rk ] ) ) {
					$why     = daw_ae_yok_check( $rk, $yok[ $rk ], $yok );
					$rules[] = '' !== $why
						? array( 'Kadro (' . $tier . ')', $p . ' kişi / ' . $r . ' rol', 'eksik', $why )
						: array( 'Kadro (' . $tier . ')', $p . ' kişi / ' . $r . ' rol', 'yok', is_string( $yok[ $rk ] ) ? $yok[ $rk ] : 'Araştırıldı, kaynak yok' );
				} else {
					$rules[] = array( 'Kadro (' . $tier . ')', $p . ' kişi / ' . $r . ' rol', 'no', 'Kişi ve rol sayısı eşit olmalı' );
				}
			}
			if ( 'main' !== $tier && $p_eps > 0 && 'film' !== $type ) {
				$eps = count( daw_ae_values( $post_id, "daw_cast_{$tier}_eps" ) );
				if ( $eps < $p_eps ) {
					$yk      = "daw_cast_{$tier}_eps";
					$rules[] = isset( $yok[ $yk ] ) && '' !== daw_ae_yok_check( $yk, $yok[ $yk ], $yok )
						? array( 'Kadro bölüm bilgisi (' . $tier . ')', $eps . ' / ' . $p, 'eksik', daw_ae_yok_check( $yk, $yok[ $yk ], $yok ) )
						: ( isset( $yok[ $yk ] )
						? array( 'Kadro bölüm bilgisi (' . $tier . ')', $eps . ' / ' . $p, 'yok', is_string( $yok[ $yk ] ) ? $yok[ $yk ] : 'Araştırıldı, kaynak yok' )
						: array( 'Kadro bölüm bilgisi (' . $tier . ')', $eps . ' / ' . $p, 'no', 'Başrol dışındaki herkeste bölüm bilgisi zorunlu (K18)' ) );
				}
			}
		}
		$eps_declared = (int) get_post_meta( $post_id, 'daw_episodes', true );
		$eps_listed   = daw_ae_rows( $post_id, 'daw_ep_no' );
		$okx          = (array) get_post_meta( $post_id, '_daw_ok', true );
		if ( $eps_declared && 'film' !== $type ) {
			// "7 / 9": a partial list. On a finished show every episode is
			// listed (madde 35); on a running one, the rest arrive as they air.
			if ( $eps_listed === $eps_declared ) {
				$rules[] = array( 'Bölüm listesi', $eps_listed . ' / ' . $eps_declared, 'ok', '' );
			} elseif ( isset( $okx['ep_partial'] ) && '' === daw_ae_tmdb_has( $post_id, 'daw_ep_no' ) ) {
				$rules[] = array( 'Bölüm listesi', $eps_listed . ' / ' . $eps_declared, 'ok', 'Doğrulanmış istisna: ' . ( is_string( $okx['ep_partial'] ) ? $okx['ep_partial'] : '' ) );
			} else {
				$running = in_array( $status, array( 'ongoing', 'upcoming' ), true );
				$rules[] = array( 'Bölüm listesi', $eps_listed . ' / ' . $eps_declared, $running ? 'wait' : 'no', $running ? 'Yayında: yeni bölümler geldikçe eklenecek' : 'Bitmiş yapımda bütün bölümler listelenir (' . ( $eps_declared - $eps_listed ) . ' eksik)' );
			}
		}
		// Every listed episode carries every column: a column filled on 7 of 9
		// rows counts as unfinished, not as filled.
		if ( $eps_listed ) {
			// Dates are owed for every listed episode; synopsis, title and
			// rating only for the ones that have aired. An episode airing next
			// month cannot have a summary or a rating yet.
			$ep_dates = get_post_meta( $post_id, 'daw_ep_date', true );
			$ep_dates = is_array( $ep_dates ) ? array_values( $ep_dates ) : array();
			$aired    = array();
			foreach ( $ep_dates as $i => $d ) {
				$ts = '' !== trim( (string) $d ) ? strtotime( (string) $d . ' 23:59:59' ) : false;
				if ( false === $ts || $ts <= time() ) {
					$aired[] = $i;
				}
			}
			foreach ( array( 'daw_ep_date' => array( 'Bölüm tarihleri', true ), 'daw_ep_synopsis' => array( 'Bölüm özetleri', true ), 'daw_ep_title' => array( 'Bölüm adları', false ), 'daw_ep_rating' => array( 'Bölüm reytingleri', false ) ) as $col => $info ) {
				$vals = get_post_meta( $post_id, $col, true );
				$vals = is_array( $vals ) ? array_values( $vals ) : array();
				if ( 'daw_ep_date' === $col || ! $ep_dates ) {
					$owed   = $eps_listed;
					$filled = count( array_filter( array_map( 'trim', array_map( 'strval', $vals ) ), 'strlen' ) );
				} else {
					$owed   = count( $aired );
					$filled = 0;
					foreach ( $aired as $i ) {
						$filled += '' !== trim( (string) ( $vals[ $i ] ?? '' ) ) ? 1 : 0;
					}
				}
				if ( $filled >= $owed ) {
					continue;
				}
				// A show that has not started has no confirmed episode dates
				// yet: the row waits instead of failing (1.75.70).
				if ( 'daw_ep_date' === $col && 'upcoming' === $status && ! $filled ) {
					$rules[] = array( $info[0] . ' dolu', $filled . ' / ' . $owed, 'wait', 'Yayın başlamadı: tarihler resmi takvim açıklanınca yazılır' );
					continue;
				}
				$tm_note = daw_ae_tmdb_has( $post_id, $col, $filled );
				if ( '' !== $tm_note ) {
					$rules[] = array( $info[0] . ' dolu', $filled . ' / ' . $owed, 'no', $tm_note );
					continue;
				}
				if ( isset( $yok[ $col ] ) && '' !== ( $weak = daw_ae_yok_check( $col, $yok[ $col ], $yok ) ) ) {
					$rules[] = array( $info[0] . ' dolu', $filled . ' / ' . $owed, 'eksik', $weak );
					continue;
				}
				$state   = isset( $yok[ $col ] ) ? 'yok' : ( $filled ? ( $info[1] ? 'no' : 'wait' ) : ( $info[1] ? 'no' : 'eksik' ) );
				$rules[] = array( $info[0] . ' dolu', $filled . ' / ' . $owed, $state, isset( $yok[ $col ] ) ? ( is_string( $yok[ $col ] ) ? $yok[ $col ] : '' ) : ( $info[1] ? 'Her bölümde zorunlu' : 'Kaynak varsa her bölümde; yoksa _daw_yok ile N/A' ) );
			}
		}
		// Parallel columns elsewhere: OST track/artist, awards five columns.
		foreach ( array( array( 'daw_ost_track', 'daw_ost_artist', 'OST parça / sanatçı' ), array( 'daw_aw_year', 'daw_aw_result', 'Ödül yılı / sonucu' ), array( 'daw_aw_body', 'daw_aw_who', 'Ödül töreni / alan' ) ) as $pc ) {
			$a = count( array_filter( array_map( 'strval', (array) get_post_meta( $post_id, $pc[0], true ) ), 'strlen' ) );
			$b = count( array_filter( array_map( 'strval', (array) get_post_meta( $post_id, $pc[1], true ) ), 'strlen' ) );
			if ( $a !== $b ) {
				$rules[] = array( $pc[2], $a . ' / ' . $b, 'no', 'Satırların hepsi iki sütunda da dolu olmalı' );
			}
		}
		$dates = daw_ae_values( $post_id, 'daw_ep_date' );
		if ( count( $dates ) !== count( array_unique( $dates ) ) ) {
			// A double broadcast is real on some shows; once a source settles it
			// the record carries `_daw_ok['ep_dates_dup']` and the line turns green.
			$okd     = (array) get_post_meta( $post_id, '_daw_ok', true );
			$rules[] = isset( $okd['ep_dates_dup'] )
				? array( 'Bölüm tarihleri', '', 'ok', 'Aynı gün iki bölüm doğrulandı: ' . ( is_string( $okd['ep_dates_dup'] ) ? $okd['ep_dates_dup'] : '' ) )
				: array( 'Bölüm tarihleri', '', 'wait', 'Aynı tarihli bölümler var: kaynakla doğrulanmalı' );
		}
	}

	if ( 'person' === $group ) {
		$native = implode( ' ', daw_ae_values( $post_id, 'daw_native_name' ) );
		$ok     = daw_ae_native_script_ok( $native, $country ? $country : 'South Korea' );
		$rules[] = array( 'Yerel ad yazısı', $native, $ok ? 'ok' : 'no', $ok ? '' : 'Sadece kendi alfabesi; Latin harf ya da gerçek ad olmamalı' );
		$fq      = daw_ae_rows( $post_id, 'daw_pfaq_q' );
		$rules[] = array( 'SSS', $fq . ' soru', $fq >= 5 ? 'ok' : 'wait', $fq >= 5 ? '' : 'En az 5 soru önerilir' );
		if ( function_exists( 'daw_remote_mode_for' ) && 'remote' === daw_remote_mode_for( $post_id ) ) {
			$src = strtolower( implode( ' ', daw_ae_values( $post_id, 'daw_src_portrait' ) ) );
			if ( '' !== $src && false === strpos( $src, 'tmdb' ) ) {
				$rules[] = array( 'Portre kaynağı satırı', $src, 'no', 'Fotoğraf TMDB\'den geliyor; kaynak satırı güncellenmeli' );
			}
		}
	}

	foreach ( array( array( 'daw_faq_q', 'daw_faq_a' ), array( 'daw_pfaq_q', 'daw_pfaq_a' ), array( 'daw_gfaq_q', 'daw_gfaq_a' ), array( 'daw_glance_label', 'daw_glance_value' ), array( 'daw_guide_source_name', 'daw_guide_source_url' ) ) as $pair ) {
		$a = daw_ae_rows( $post_id, $pair[0] );
		$b = daw_ae_rows( $post_id, $pair[1] );
		if ( $a !== $b ) {
			$rules[] = array( $pair[0] . ' / ' . $pair[1], $a . ' / ' . $b, 'no', 'Paralel satırlar eşit değil' );
		}
	}
	// K14: the same sentence twice on one page.
	$seen = array();
	foreach ( $texts as $t ) {
		foreach ( preg_split( '/(?<=[.!?])\s+/u', wp_strip_all_tags( $t[1] ) ) as $sent ) {
			$n = strtolower( trim( preg_replace( '/\s+/u', ' ', $sent ) ) );
			if ( str_word_count( $n ) < 8 ) {
				continue;
			}
			if ( isset( $seen[ $n ] ) && $seen[ $n ] !== $t[0] ) {
				$rules[] = array( 'Tekrar (K14)', mb_substr( $sent, 0, 70 ), 'no', 'Aynı cümle hem "' . $seen[ $n ] . '" hem "' . $t[0] . '" alanında' );
			}
			$seen[ $n ] = $t[0];
		}
	}

	// Madde 51: paragraphs all the same length are a machine's rhythm.
	foreach ( array( 'daw_synopsis', 'daw_why_watched', 'daw_biography', 'daw_person_section_body' ) as $long ) {
		$txt = implode( "\n\n", daw_ae_values( $post_id, $long ) );
		$par = array_values( array_filter( array_map( 'trim', preg_split( '/\n\s*\n|<\/p>/i', $txt ) ) ) );
		if ( count( $par ) >= 4 ) {
			$wc   = array_map( static function ( $x ) { return max( 1, str_word_count( wp_strip_all_tags( $x ) ) ); }, $par );
			$mean = array_sum( $wc ) / count( $wc );
			$var  = 0;
			foreach ( $wc as $w ) {
				$var += ( $w - $mean ) * ( $w - $mean );
			}
			$cv = sqrt( $var / count( $wc ) ) / $mean;
			if ( $cv < 0.15 ) {
				$rules[] = array( 'Paragraf ritmi (madde 51)', $long, 'wait', 'Bütün paragraflar neredeyse aynı uzunlukta: yapay zekâ izi gibi okunur' );
			}
		}
	}

	// Madde 64: no sentence is shared with another record.
	if ( function_exists( 'daw_season_rules' ) && in_array( $group, array( 'title', 'show' ), true ) ) {
		foreach ( daw_season_rules( $post_id ) as $row ) {
			$rules[] = $row;
		}
	}
	if ( function_exists( 'daw_kobis_rules' ) && 'title' === $group ) {
		foreach ( daw_kobis_rules( $post_id ) as $row ) {
			$rules[] = $row;
		}
	}
	if ( function_exists( 'daw_trailer_rules' ) && in_array( $group, array( 'title', 'show' ), true ) ) {
		foreach ( daw_trailer_rules( $post_id ) as $row ) {
			$rules[] = $row;
		}
	}
	if ( function_exists( 'daw_orig_rules' ) && in_array( $group, array( 'title', 'show', 'person', 'group' ), true ) ) {
		foreach ( daw_orig_rules( $post_id ) as $row ) {
			$rules[] = $row;
		}
	}
	// A single-value field stored as an array took two pages down (BTS,
	// 12.12, 25 Sep 2026). The repair fixes what it can; what is left is two
	// different values in one field, and a person picks the right one.
	if ( function_exists( 'daw_meta_shape_repair' ) ) {
		$left = daw_meta_shape_repair( $post_id );
		if ( $left ) {
			$bits = array();
			foreach ( $left as $k => $why ) {
				$bits[] = $k . ' (' . $why . ')';
			}
			$rules[] = array( 'Meta biçimi', implode( '; ', $bits ), 'no', 'Tek değerli alanda birden çok farklı satır var: doğru değer seçilir, fazlası silinir' );
		}
	}

	// K17 / K15: page-builder leftovers or another record's text in the body.
	$body = (string) get_post_field( 'post_content', $post_id );
	if ( in_array( $group, array( 'title', 'show', 'person' ), true ) && '' !== trim( $body ) ) {
		if ( preg_match( '/elementor|e-n-accordion|uc_post_list|wp-block-uagb/i', $body ) ) {
			$rules[] = array( 'Eski gövde (K17)', mb_strlen( $body ) . ' bayt', 'no', 'Sayfada görünmeyen eski Elementor gövdesi duruyor; aramaya ve özete sızar' );
		}
		if ( false !== stripos( $body, 'Memories of the Alhambra' ) && false === stripos( $title_text, 'Alhambra' ) ) {
			$rules[] = array( 'Yabancı veri (K15)', '', 'no', 'Gövdede başka bir dizinin metni var' );
		}
	}

	// K21: our type against TMDB's kind.
	if ( function_exists( 'daw_tmdb_ref' ) && in_array( $group, array( 'title', 'show' ), true ) ) {
		$ref = daw_tmdb_ref( $post_id );
		if ( $ref && ( ( 'movie' === $ref['kind'] && 'film' !== $type ) || ( 'tv' === $ref['kind'] && 'film' === $type ) ) ) {
			// A one-off TV special that TMDB files as a movie is real; once a
			// source settles it the record carries `_daw_ok['tmdb_kind']`.
			$okk     = (array) get_post_meta( $post_id, '_daw_ok', true );
			$rules[] = isset( $okk['tmdb_kind'] )
				? array( 'Tür ve TMDB (K21)', $type . ' / ' . $ref['kind'], 'ok', 'Tür farkı doğrulandı: ' . ( is_string( $okk['tmdb_kind'] ) ? $okk['tmdb_kind'] : '' ) )
				: array( 'Tür ve TMDB (K21)', $type . ' / ' . $ref['kind'], 'no', 'Kaydın türü TMDB kaydının türüyle uyuşmuyor; tek bölümlük TV özel yapımıysa kaynakla _daw_ok[\'tmdb_kind\'] yazılır' );
		}
	}

	// K19: another record chasing the same search.
	foreach ( daw_ae_duplicates( $post_id ) as $dup ) {
		$rules[] = array( 'Kanibalizasyon (K19)', $dup[1], 'no', $dup[0] );
	}

	$sections['rules'] = array( 'label' => 'Yazı kuralları ve yapay zekâ izi', 'items' => $rules );

	// 3. SEO.
	$seo = array();
	$kws = (array) get_post_meta( $post_id, DAW_AE_KW, true );
	$kws = array_filter( $kws );
	// Entries flagged "n" are research only: patterns that were searched and
	// are listed in the panel with their volume and where they appear, but are
	// not targets, so they are neither scored nor counted as missing.
	$kws = array_values(
		array_filter(
			$kws,
			static function ( $k ) {
				return ! ( is_array( $k ) && ( ! empty( $k['n'] ) || ! empty( $k['x'] ) ) );
			}
		)
	);
	$s   = array( 'title' => '' );
	if ( function_exists( 'daw_audit_seo_state' ) ) {
		$s     = daw_audit_seo_state( $post_id );
		$seo[] = array( 'SEO başlığı', $s['title'] . ' (' . mb_strlen( $s['title'] ) . ')', ( '' !== $s['title'] && mb_strlen( $s['title'] ) <= 60 ) ? 'ok' : 'no', 'auto' === $s['title_source'] ? 'otomatik üretiliyor' : '' );
		$dl    = mb_strlen( $s['description'] );
		$seo[] = array( 'Açıklama', $dl . ' karakter', ( 'manual' === $s['desc_source'] && $dl >= 150 && $dl <= 160 ) ? 'ok' : ( '' === $s['description'] ? 'no' : 'wait' ), 'manual' === $s['desc_source'] ? '150 ile 160 arası' : 'elle yazılmamış (' . $s['desc_source'] . ')' );
		$seo[] = array( 'Odak kelime', $s['focus'], '' !== $s['focus'] ? 'ok' : 'no', '' );
		if ( false !== stripos( $s['title'], 'discover asian world' ) ) {
			$seo[] = array( 'Site adı başlıkta', '', 'no', 'Tema ekliyor (kural 6.3)' );
		}
		$haystack = array(
			'title' => strtolower( $s['title'] ),
			'desc'  => strtolower( $s['description'] ),
			// The whole readable page counts (1.75.71): a long-tail answered in
			// the biography, a section or the takeaways is answered. Reading
			// only the synopsis and FAQ pushed agents to stuff the FAQ.
			'body'  => strtolower( wp_strip_all_tags( implode( ' ', array_merge( daw_ae_values( $post_id, 'daw_synopsis' ), daw_ae_values( $post_id, 'daw_person_summary' ), daw_ae_values( $post_id, 'daw_group_summary' ), daw_ae_values( $post_id, 'daw_biography' ), daw_ae_values( $post_id, 'daw_person_section_title' ), daw_ae_values( $post_id, 'daw_person_section_body' ), daw_ae_values( $post_id, 'daw_title_section_title' ), daw_ae_values( $post_id, 'daw_title_section_body' ), daw_ae_values( $post_id, 'daw_takeaways' ), daw_ae_values( $post_id, 'daw_person_takeaways' ), daw_ae_values( $post_id, 'daw_why_watched' ), daw_ae_values( $post_id, 'daw_themes_messages' ), daw_ae_values( $post_id, 'daw_interesting_facts' ), daw_ae_values( $post_id, 'daw_hobbies' ), daw_ae_values( $post_id, 'daw_career_event' ), array( (string) get_post_field( 'post_content', $post_id ) ) ) ) ) ),
			'faq'   => strtolower( implode( ' ', array_merge( daw_ae_values( $post_id, 'daw_faq_q' ), daw_ae_values( $post_id, 'daw_faq_a' ), daw_ae_values( $post_id, 'daw_pfaq_q' ), daw_ae_values( $post_id, 'daw_pfaq_a' ), daw_ae_values( $post_id, 'daw_gfaq_q' ), daw_ae_values( $post_id, 'daw_gfaq_a' ) ) ) ),
		);
		// FULL RESEARCH (1.75.71, Umut 15 Oct 2026): a record is "Temel bitti"
		// only after Ubersuggest's whole pattern list was saved with volumes
		// (targets, research "n", off-topic "x"). Lists saved before that rule
		// held only the patterns placed, so they are researched again.
		$kat   = (string) get_post_meta( $post_id, '_daw_kw_at', true );
		$kyok0 = get_post_meta( $post_id, '_daw_yok', true );
		$knote0 = is_array( $kyok0 ) ? (string) ( $kyok0['_daw_kw'] ?? '' ) : '';
		$kfull = ( '' !== $kat && $kat >= DAW_KW_FULL_SINCE );
		if ( ! $kfull && preg_match( '/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/', $knote0, $km ) ) {
			$kfull = sprintf( '%04d-%02d-%02d', $km[3], $km[2], $km[1] ) >= DAW_KW_FULL_SINCE;
		}
		$seo[] = $kfull
			? array( 'Tam kelime araştırması', '' !== $kat ? $kat : 'kalıp yok', 'ok', 'Ubersuggest kalıplarının hepsi hacimleriyle kayıtlı' )
			: array( 'Tam kelime araştırması', '' !== $kat ? $kat : 'yapılmadı', 'eksik', 'Ubersuggest\'in bütün kalıpları hacimleriyle kaydedilmeli (hedef / "n" araştırma / "x" konu dışı); ilgili her kalıp sayfada kaynaklı cümleyle cevaplanır (K25)' );
		if ( ! $kws ) {
			// Research that found nothing with volume is a result, not a gap
			// (madde 49: no field for a zero-volume pattern). It is written in
			// _daw_yok['_daw_kw'] with the seeds tried and the date.
			$kyok = get_post_meta( $post_id, '_daw_yok', true );
			$kok   = get_post_meta( $post_id, '_daw_ok', true );
			$knote = is_array( $kyok ) ? trim( (string) ( $kyok['_daw_kw'] ?? '' ) ) : '';
			if ( '' === $knote && is_array( $kok ) ) {
				$knote = trim( (string) ( $kok['kw_zero'] ?? '' ) );
			}
			$kdated = (bool) preg_match( '/\b\d{1,2}\.\d{1,2}\.\d{4}\b|\b\d{4}-\d{2}-\d{2}\b/', $knote );
			$seo[] = $knote && $kdated
				? array( 'Anahtar kelime araştırması', '0 kalıp', 'ok', 'Araştırıldı, hacimli kalıp yok: ' . mb_substr( $knote, 0, 120 ) )
				: array( 'Anahtar kelime araştırması', '', 'eksik', 'Kayıtlı kalıp yok: araştırmada _daw_kw yazılmalı (hacimli kalıp çıkmadıysa _daw_yok[\'_daw_kw\'] tohumlar ve tarihle)' );
		}
		// Madde 48 (1.75.86, Umut 27 Oct 2026): the full Ubersuggest list is
		// kept, but only the big patterns must be on the page. A pattern at
		// DAW_KW_MUST_VOL a month or more, or among the DAW_KW_MUST_TOP
		// biggest, is red when missing; the long tail is an opportunity
		// (wait), so a richer list no longer pushes a record out of Temel.
		$kvols = array();
		foreach ( $kws as $kw ) {
			$kvols[] = is_array( $kw ) ? (int) ( $kw['vol'] ?? 0 ) : 0;
		}
		rsort( $kvols );
		$kcut = $kvols ? $kvols[ min( count( $kvols ), DAW_KW_MUST_TOP ) - 1 ] : 0;
		foreach ( $kws as $kw ) {
			$k      = strtolower( trim( (string) ( is_array( $kw ) ? ( $kw['kw'] ?? '' ) : $kw ) ) );
			$vol    = is_array( $kw ) ? (int) ( $kw['vol'] ?? 0 ) : 0;
			if ( '' === $k ) {
				continue;
			}
			$must = $vol >= DAW_KW_MUST_VOL || ( $vol > 0 && $vol >= $kcut );
			$where = array();
			foreach ( array( 'title' => 'başlık', 'desc' => 'açıklama', 'body' => 'metin', 'faq' => 'SSS' ) as $hk => $hl ) {
				if ( daw_ae_kw_in( $k, $haystack[ $hk ], in_array( $hk, array( 'title', 'desc' ), true ) ) ) {
					$where[] = $hl;
				}
			}
			$seo[] = array( '"' . $k . '"', ( $vol ? $vol . '/ay · ' : '' ) . ( $where ? implode( ', ', $where ) : 'sayfada yok' ), $where ? 'ok' : ( $must ? 'no' : 'wait' ), $where ? '' : ( $must ? 'Kalıp hiçbir yere yerleşmemiş' : 'Fırsat: küçük kalıp, kaynaklı bir cümleyle karşılanabiliyorsa yerleştir' ) );
		}
	}
	$sections['seo'] = array( 'label' => 'SEO ve anahtar kelimeler', 'items' => $seo );

	// 3b. The page itself, as a reader and Google get it.
	$html = daw_ae_page_html( $post_id );
	list( $page_items, $legal_items, $live_schema, $headings ) = daw_ae_page_checks( $post_id, $html, $group, $type );
	// Madde 73: every search Google already brings to this page is answered on
	// it. Measured on the live page, the same way the panel's column is.
	if ( '' !== $html ) {
		list( $gn, $gmiss ) = daw_ae_gsc_missing( $post_id, $html );
		if ( $gn ) {
			$sections['seo']['items'][] = $gmiss
				? array( 'GSC sorgusu karşılanmamış', count( $gmiss ) . ' / ' . $gn . ' sorgu', 'wait', 'Kontrol et: ' . implode( ', ', array_slice( $gmiss, 0, 5 ) ) . ( count( $gmiss ) > 5 ? ' +' . ( count( $gmiss ) - 5 ) : '' ) . '. Her arama ya uygun yere işlenir ya da _daw_ok[\'gsc:<arama>\'] gerekçesiyle dışarıda bırakılır (yazım hatası kendiliğinden ayrılır)' )
				: array( 'GSC sorgusu karşılanmamış', '0 / ' . $gn . ' sorgu', 'ok', 'Search Console\'un getirdiği her arama sayfada karşılanıyor' );
		}
	}
	// K11: the biggest search patterns belong in a heading.
	if ( $kws && $headings ) {
		$sorted = $kws;
		usort( $sorted, static function ( $a, $b ) { return (int) ( is_array( $b ) ? ( $b['vol'] ?? 0 ) : 0 ) <=> (int) ( is_array( $a ) ? ( $a['vol'] ?? 0 ) : 0 ); } );
		$hs = strtolower( implode( ' | ', $headings ) . ' | ' . ( $s['title'] ?? '' ) );
		foreach ( array_slice( $sorted, 0, 3 ) as $kw ) {
			$k = strtolower( trim( (string) ( is_array( $kw ) ? ( $kw['kw'] ?? '' ) : $kw ) ) );
			if ( '' === $k ) {
				continue;
			}
			// A pattern like "pinocchio cast" is met by a heading that holds "cast".
			$tail = trim( str_replace( strtolower( $title_text ), '', $k ) );
			// Folded like the body checks, so "K-pop" in a heading meets "kpop".
			$met  = daw_ae_kw_in_fold( $k, $hs ) || ( '' !== $tail && daw_ae_kw_in_fold( $tail, $hs ) );
			$page_items[] = array( 'Başlıkta yüksek hacimli kalıp (K11)', $k, $met ? 'ok' : 'no', $met ? '' : 'En çok aranan kalıplardan biri hiçbir başlıkta yok' );
		}
	}
	$sections['page']  = array( 'label' => 'Sayfa: başlıklar, görseller, bağlantılar', 'items' => $page_items );
	$sections['legal'] = array( 'label' => 'Hukuk ve güven', 'items' => $legal_items );

	// 4. Schema the page sends.
	$sch = array();
	try {
		$node = function_exists( 'daw_schema_entity' ) ? daw_schema_entity( $post_id, $type ) : null;
	} catch ( \Throwable $e ) {
		$node = null;
		$sch[] = array( 'Schema', '', 'no', 'Üretilirken hata: ' . $e->getMessage() );
	}
	if ( is_array( $node ) ) {
		$t    = is_array( $node['@type'] ?? '' ) ? implode( '/', $node['@type'] ) : (string) ( $node['@type'] ?? '' );
		$need = array(
			'TVSeries'    => array( 'name', 'image', 'startDate', 'numberOfEpisodes', 'actor', 'genre', 'sameAs' ),
			'Movie'       => array( 'name', 'image', 'director', 'actor', 'genre', 'sameAs' ),
			'Person'      => array( 'name', 'image', 'birthDate', 'sameAs' ),
			'MusicGroup'  => array( 'name', 'image', 'foundingDate' ),
			'NewsArticle' => array( 'headline', 'image', 'datePublished', 'author' ),
			'Article'     => array( 'headline', 'image', 'datePublished' ),
		);
		$sch[] = array( 'Tür', $t, '' !== $t ? 'ok' : 'no', '' );
		if ( 'anime' === $type && 'TVSeries' !== $t ) {
			$sch[] = array( 'Anime türü', $t, 'no', 'Anime TVSeries olarak gönderilmeli' );
		}
		foreach ( $need[ $t ] ?? array() as $prop ) {
			$has   = ! empty( $node[ $prop ] );
			$sch[] = array( $prop, $has ? 'var' : '', $has ? 'ok' : 'wait', $has ? '' : 'Google bu alanı ister' );
		}
		if ( ! empty( $node['aggregateRating'] ) ) {
			$n     = (int) ( $node['aggregateRating']['ratingCount'] ?? 0 );
			$sch[] = array( 'aggregateRating', $n . ' oy', $n >= 5 ? 'ok' : 'wait', $n >= 5 ? '' : 'Çok az oy: Google\'a göndermek için eşik önerilir' );
		}
		if ( ! empty( $node['publisher'] ) && in_array( $t, array( 'Person', 'TVSeries', 'Movie', 'MusicGroup' ), true ) ) {
			$sch[] = array( 'publisher', '', 'no', 'Bu düğümde publisher yazılmaz: yapımı biz yayınlamadık (kural 6.5)' );
		}
	} elseif ( ! $sch ) {
		$sch[] = array( 'Schema', '', 'no', 'Bu kayıt için schema üretilmiyor' );
	}
	$sch = array_merge( $sch, $live_schema );
	$sections['schema'] = array( 'label' => 'Schema', 'items' => $sch );

	// 5. Picture.
	$img = array();
	if ( in_array( $group, array( 'title', 'show', 'person', 'group' ), true ) && function_exists( 'daw_remote_mode_for' ) ) {
		$mode  = daw_remote_mode_for( $post_id );
		$names = array( 'remote' => 'TMDB\'den, doğrulanmış', 'local' => 'Lisanslı yerel görsel', 'monogram' => 'Baş harf (TMDB görseli doğrulanamadı)', 'copy' => 'TMDB dosyasının yerel kopyası', 'vault' => 'Acil durum yedeği', 'default' => 'Yerel görsel, hakkı kayıtsız' );
		$no_poster = 'monogram' === $mode && ! get_post_thumbnail_id( $post_id ) && function_exists( 'daw_tmdb_img_path_raw' ) && '' === daw_tmdb_img_path_raw( $post_id );
		$img[] = array( 'Görsel', $names[ $mode ] ?? $mode, 'remote' === $mode || 'local' === $mode ? 'ok' : ( 'monogram' === $mode ? 'wait' : 'no' ), 'monogram' === $mode ? ( $no_poster ? 'TMDB kaydında afiş yok; sayfa baş harf kartını gösteriyor' : (string) get_post_meta( $post_id, '_daw_tmdb_img_why', true ) ) : '' );
	} else {
		$att   = (int) get_post_thumbnail_id( $post_id );
		$st    = $att && function_exists( 'daw_lic_status' ) ? daw_lic_status( $att, $post_id ) : 'none';
		$img[] = array( 'Öne çıkan görsel', $att ? '#' . $att : '', 'ok' === $st ? 'ok' : 'no', 'ok' === $st ? '' : ( 'none' === $st ? 'Görsel yok' : 'Hak kaydı: ' . $st ) );
	}
	$sections['image'] = array( 'label' => 'Görsel', 'items' => $img );

	// 6. Freshness.
	$fresh = array();
	$vs    = function_exists( 'daw_verify_state' ) ? daw_verify_state( $post_id ) : 'none';
	$vn    = array( 'verified' => 'Doğrulandı', 'stale' => 'Damgadan sonra değişti', 'open' => 'Damga eksik bildiriyor', 'none' => 'Damga yok' );
	$fresh[] = array( 'Doğrulama damgası', $vn[ $vs ] ?? $vs, 'verified' === $vs ? 'ok' : 'wait', '' );
	// The lock (kilit): once a record has passed a full audit, its content is
	// fingerprinted. While the fingerprint holds, the text is not rewritten
	// and only what can change on its own is looked at again (status, where
	// to watch, new works, the live page). An edit to the content breaks the
	// lock and the record gets a full audit again.
	$lock = get_post_meta( $post_id, DAW_AE_LOCK, true );
	if ( is_array( $lock ) && ! empty( $lock['hash'] ) ) {
		$fresh[] = hash_equals( (string) $lock['hash'], daw_ae_lock_hash( $post_id ) )
			? array( 'Kilit', 'kilitli · ' . gmdate( 'Y-m-d', (int) $lock['at'] ), 'ok', 'İçerik tam denetimden geçti ve değişmedi: metin yeniden yazılmaz, yalnız değişebilen maddelere bakılır' )
			: array( 'Kilit', 'bozuldu', 'wait', 'İçerik kilitten sonra değişti (' . gmdate( 'Y-m-d', (int) $lock['at'] ) . '): değişen alanlar yeniden denetlenir' );
	} else {
		$fresh[] = array( 'Kilit', 'yok', 'wait', 'Tam denetimden geçip her şey yeşil olunca kendiliğinden kilitlenir' );
	}
	$sections['fresh'] = array( 'label' => 'Güncellik ve kilit', 'items' => $fresh );

	// Where to watch, by country (TMDB / JustWatch).
	if ( in_array( $group, array( 'title', 'show' ), true ) && function_exists( 'daw_wp_get' ) ) {
		$wd  = daw_wp_get( $post_id );
		$wat = (int) get_post_meta( $post_id, DAW_WP_AT, true );
		if ( $wd ) {
			$line = array( 'Ülkeye göre izleme yerleri', count( $wd['r'] ) . ' ülke · ABD: ' . implode( ', ', daw_wp_names( $post_id ) ), 'ok', 'TMDB API, JustWatch verisi · ' . human_time_diff( (int) $wd['at'] ) . ' önce' );
		} elseif ( 'ok' !== get_post_meta( $post_id, DAW_TMDB_CHECK, true ) ) {
			$line = array( 'Ülkeye göre izleme yerleri', '', 'wait', 'TMDB eşleşmesi doğrulanınca alınır' );
		} elseif ( $wat ) {
			$line = array( 'Ülkeye göre izleme yerleri', '', 'yok', 'TMDB/JustWatch bu yapım için hiçbir ülkede platform listelemiyor' );
		} else {
			$line = array( 'Ülkeye göre izleme yerleri', '', 'wait', 'Henüz sorulmadı; saatlik görev alacak' );
		}
		$sections['watch_tmdb'] = array(
			'label' => 'Nerede izlenir (TMDB / JustWatch)',
			'items' => array( $line ),
		);
	}

	// Coverage against TMDB (madde 59): cast for titles, filmography for people.
	if ( defined( 'DAW_COV' ) && in_array( $group, array( 'title', 'show', 'person' ), true ) ) {
		$cov   = get_post_meta( $post_id, DAW_COV, true );
		// A person's credits are placed against today's records, not the ones the
		// weekly scan saw: a daw_tmdb_alt written since counts at once, and
		// `_daw_ok['coverage:<tv|movie>:<id>']` closes a credit with its note.
		if ( is_array( $cov ) && function_exists( 'daw_cov_resolve_report' ) ) {
			$cov = daw_cov_resolve_report( $post_id, $cov );
		}
		$items = array();
		if ( ! is_array( $cov ) ) {
			$items[] = array( 'TMDB karşılaştırması', '', 'wait', 'ok' === get_post_meta( $post_id, DAW_TMDB_CHECK, true ) ? 'Henüz taranmadı; Kapsam sayfasındaki tarama ya da saatlik görev alacak' : 'TMDB eşleşmesi doğrulanınca taranır' );
		} elseif ( 'title' === ( $cov['kind'] ?? '' ) ) {
			$c     = $cov['count'];
			$top   = array_filter( (array) $cov['people'], static function ( $p ) { return (int) $p['order'] < 15; } );
			// `_daw_ok['cov:<tmdb person id>']` settles a TMDB credit that does
			// not belong on this record (another season's cast, a wrong credit).
			$cok   = get_post_meta( $post_id, '_daw_ok', true );
			$cok   = is_array( $cok ) ? $cok : array();
			$unl   = array_filter( (array) $cov['people'], static function ( $p ) use ( $cok ) { return 'unlinked' === $p['state'] && ! isset( $cok[ 'cov:' . (int) ( $p['id'] ?? 0 ) ] ); } );
			$miss  = array_filter( $top, static function ( $p ) { return 'missing' === $p['state']; } );
			$names = static function ( $rows ) { return implode( ', ', array_slice( wp_list_pluck( $rows, 'name' ), 0, 8 ) ); };
			$items[] = array( 'Kadro kapsamı', $c['linked'] . ' bağlı / ' . $cov['total'] . ' TMDB kredisi', 'ok', $c['missing'] . ' kişinin kaydı yok, ' . $c['unlinked'] . ' kişi bağlanmamış' );
			$items[] = $unl
				? array( 'Kaydı var, kadroda yok', count( $unl ) . ' kişi', 'no', 'Kadroya ekle: ' . $names( $unl ) )
				: array( 'Kaydı var, kadroda yok', '0', 'ok', '' );
			$items[] = $miss
				? array( 'İlk 15 içinde kaydı olmayan', count( $miss ) . ' kişi', 'wait', 'Kişi kaydı açılacak: ' . $names( $miss ) )
				: array( 'İlk 15 içinde kaydı olmayan', '0', 'ok', '' );
			$crew = (array) ( $cov['crew'] ?? array() );
			if ( $crew ) {
				$sug = array();
				foreach ( array( 'Director' => 'daw_director', 'Screenwriter' => 'daw_screenwriter', 'Original author' => 'daw_original_author', 'Producer' => 'daw_producer' ) as $job => $field ) {
					$have = implode( ' ', daw_ae_values( $post_id, $field ) );
					foreach ( $crew as $cm ) {
						if ( ( $cm['job'] ?? '' ) === $job && false === strpos( daw_ae_kname_fold( $have ), daw_ae_kname_fold( (string) $cm['name'] ) ) ) {
							$sug[] = $job . ': ' . $cm['name'];
						}
					}
				}
				$items[] = $sug
					? array( 'Ekip (TMDB)', count( $sug ) . ' ad alanda yok', 'wait', 'Kaynakla doğrulayıp ekle: ' . implode( '; ', array_slice( $sug, 0, 6 ) ) )
					: array( 'Ekip (TMDB)', count( $crew ) . ' kişi', 'ok', 'Yönetmen, senarist, orijinal eser alanlarıyla tutarlı' );
				$nocrew = array();
				foreach ( $crew as $cm ) {
					if ( empty( $cm['post'] ) && 'Producer' !== ( $cm['job'] ?? '' ) ) {
						$nocrew[] = $cm['name'] . ' (' . $cm['job'] . ')';
					}
				}
				$nocrew  = array_values( array_unique( $nocrew ) );
				$items[] = $nocrew
					? array( 'Ekipte kaydı olmayan', count( $nocrew ) . ' kişi', 'wait', 'Kişi kaydı açılabilir: ' . implode( '; ', array_slice( $nocrew, 0, 6 ) ) )
					: array( 'Ekipte kaydı olmayan', '0', 'ok', '' );
			}
		} else {
			$c     = $cov['count'];
			$works = (array) $cov['works'];
			// A TMDB credit that was looked into and settled stays settled: a
			// note in `_daw_ok` under `cov:<tv|movie>:<id>`, or any `coverage:*`
			// note that names the credit as tv/<id> or movie/<id>, takes it out
			// of the open lists (a premiere year shown as an appearance, a
			// webtoon trailer, a credit TMDB mixed up with another actor).
			$cvok  = get_post_meta( $post_id, '_daw_ok', true );
			$cvok  = is_array( $cvok ) ? $cvok : array();
			$settled = 0;
			$works = array_filter(
				$works,
				static function ( $w ) use ( $cvok, &$settled ) {
					$key = (string) ( $w['key'] ?? '' );
					if ( '' === $key ) {
						return true;
					}
					$path = str_replace( ':', '/', $key ) . ' ';
					foreach ( $cvok as $k => $v ) {
						$k = (string) $k;
						if ( 'cov:' . $key === $k || ( 0 === strpos( $k, 'coverage:' ) && is_string( $v ) && false !== strpos( $v . ' ', $path ) ) ) {
							++$settled;
							return false;
						}
					}
					return true;
				}
			);
			$unl   = array_filter( $works, static function ( $w ) { return 'unlinked' === $w['state'] && '' === $w['as']; } );
			$miss  = array_filter( $works, static function ( $w ) { return 'missing' === $w['state'] && in_array( $w['kind'], array( 'drama', 'film', 'anime' ), true ) && '' === $w['as']; } );
			$vmiss = array_filter( $works, static function ( $w ) { return 'missing' === $w['state'] && 'variety' === $w['kind']; } );
			$soon  = array_filter( $works, static function ( $w ) { return ! empty( $w['soon'] ); } );
			$t     = static function ( $rows ) { return implode( ', ', array_slice( array_map( static function ( $w ) { return $w['title'] . ( $w['year'] ? ' (' . $w['year'] . ')' : '' ); }, $rows ), 0, 8 ) ); };
			$kl    = array();
			foreach ( (array) ( $cov['kinds'] ?? array() ) as $k => $st ) {
				$kl[] = $k . ' ' . (int) ( $st['linked'] ?? 0 ) . '/' . array_sum( $st );
			}
			// Credits closed with `_daw_ok['coverage:<tv|movie>:<id>']`: the
			// note is shown, so the exception is never silent.
			$kex = '';
			$kn  = array();
			foreach ( (array) $cov['works'] as $kw ) {
				if ( 'settled' === ( $kw['state'] ?? '' ) ) {
					$kn[] = $kw['title'] . ' (' . $kw['key'] . ')' . ( '' !== (string) ( $kw['note'] ?? '' ) ? ': ' . $kw['note'] : '' );
				}
			}
			if ( $kn ) {
				$kex = ' · Kapsam istisnası: ' . implode( '; ', array_slice( $kn, 0, 5 ) );
			}
			$items[] = array( 'Filmografi kapsamı', $c['linked'] . ' bağlı / ' . $cov['total'] . ' TMDB işi', 'ok', implode( ' · ', $kl ) . ( $settled ? ' · ' . $settled . ' kredi sahip kararıyla kapatıldı' : '' ) . $kex );
			$items[] = $unl
				? array( 'Yapım kaydı var, kadrosunda yok', count( $unl ) . ' yapım', 'no', 'O yapımların kadrosuna ekle: ' . $t( $unl ) )
				: array( 'Yapım kaydı var, kadrosunda yok', '0', 'ok', '' );
			$items[] = $miss
				? array( 'Kaydı olmayan dizi / film / anime', count( $miss ) . ' yapım', 'wait', $t( $miss ) )
				: array( 'Kaydı olmayan dizi / film / anime', '0', 'ok', '' );
			if ( $vmiss ) {
				$items[] = array( 'Kaydı olmayan varyete', count( $vmiss ) . ' program', 'ok', 'Bilgi: ' . $t( $vmiss ) );
			}
			if ( $soon ) {
				// A coming work is something to watch, not a gap: a missing
				// record already shows on the line above. As a "wait" it kept a
				// finished person page off 100% until the work aired (owner,
				// 29 Oct 2026: "hepsi bittiyse tam bittiye geçmiyor mu").
				$items[] = array( 'Yeni / yakında', count( $soon ) . ' iş', 'ok', 'Bilgi, takip: ' . $t( $soon ) );
			}
		}
		$sections['coverage'] = array( 'label' => 'Kapsam (TMDB ile karşılaştırma)', 'items' => $items );
	}

	// Two-source check (madde 62): TMDB against Wikidata for stable facts.
	if ( defined( 'DAW_VF_AT' ) && in_array( $group, array( 'title', 'show', 'person' ), true ) ) {
		$items = array();
		if ( ! get_post_meta( $post_id, DAW_VF_AT, true ) || 'ok' !== get_post_meta( $post_id, DAW_TMDB_CHECK, true ) ) {
			$items[] = array( 'TMDB + Wikidata karşılaştırması', '', 'wait', 'ok' === get_post_meta( $post_id, DAW_TMDB_CHECK, true ) ? 'Henüz yapılmadı; saatlik görev alacak' : 'TMDB eşleşmesi doğrulanınca yapılır' );
		} else {
			// A record the check skipped (no TMDB match) has the stamp but none
			// of the three lists; an empty meta cast to array is [''], not [].
			$arr = static function ( $k ) use ( $post_id ) {
				$v = get_post_meta( $post_id, $k, true );
				return is_array( $v ) ? array_filter( $v, 'is_array' ) + array_filter( $v, 'is_string' ) : array();
			};
			$src = array_filter( $arr( DAW_VF_SRC ), 'strlen' );
			$items[] = array( 'İki kaynakla doğrulanan alanlar', count( $src ) . ' alan', 'ok', implode( ', ', array_keys( $src ) ) );
			$okv = get_post_meta( $post_id, '_daw_ok', true );
			$okv = is_array( $okv ) ? $okv : array();
			foreach ( array_filter( $arr( DAW_VF_DIFF ), 'is_array' ) as $f => $d ) {
				// A disagreement settled with a third source (K5) is recorded as
				// _daw_ok['vf:<field>'] and stops asking.
				if ( isset( $okv[ 'vf:' . $f ] ) ) {
					$items[] = array( 'Karar verildi: ' . $f, is_string( $okv[ 'vf:' . $f ] ) ? $okv[ 'vf:' . $f ] : '', 'ok', '' );
					continue;
				}
				// Corrected since the last comparison: the record now says what
				// both sources say, so the old row no longer stands.
				if ( 'record_contradicted' === ( $d['kind'] ?? '' ) && function_exists( 'daw_vf_show' ) && daw_vf_show( get_post_meta( $post_id, $f, true ) ) === (string) ( $d['tmdb'] ?? '' ) ) {
					$items[] = array( 'Düzeltildi: ' . $f, (string) $d['tmdb'], 'ok', '' );
					continue;
				}
				$items[] = 'record_contradicted' === ( $d['kind'] ?? '' )
					? array( 'Çelişki: ' . $f, 'kayıt: ' . $d['record'], 'no', 'TMDB ve Wikidata ikisi de "' . $d['tmdb'] . '" diyor; kaynakla kontrol edip düzelt' )
					: array( 'Kaynaklar ayrışıyor: ' . $f, 'TMDB: ' . $d['tmdb'] . ' · Wikidata: ' . $d['wikidata'], 'wait', 'Üçüncü kaynakla karar ver (K5)' );
			}
			foreach ( array_filter( $arr( DAW_VF_SUGGEST ), 'is_array' ) as $f => $sg ) {
				// Settled since the last comparison: the field was filled from
				// a checked source, or the decision is written in _daw_ok.
				$now = get_post_meta( $post_id, $f, true );
				if ( isset( $okv[ 'vf:' . $f ] ) || ( is_array( $now ) ? array_filter( array_map( 'strval', $now ), 'strlen' ) : '' !== trim( (string) $now ) ) ) {
					continue;
				}
				$items[] = array( 'Tek kaynak önerisi: ' . $f, $sg['value'], 'wait', strtoupper( (string) $sg['src'] ) . ' diyor, ikinci kaynak yok: doğrulayıp yaz ya da N/A' );
			}
			$old = array_filter( array_filter( $arr( '_daw_fill_tmdb' ), 'is_string' ), 'strlen' );
			$uv  = array_filter( array_diff( $old, array_keys( $src ) ), static function ( $f ) use ( $okv ) {
				return ! isset( $okv[ 'vf:' . $f ] );
			} );
			if ( $uv ) {
				$items[] = array( 'Yalnız TMDB\'den gelmiş eski değerler', implode( ', ', $uv ), 'wait', 'Wikidata doğrulamadı: bir kaynakla teyit et' );
			}
		}
		$sections['verify'] = array( 'label' => 'Kaynak doğrulaması (TMDB + Wikidata)', 'items' => $items );
	}

	// Numbers: the fixed checklist numbers (research/kunye-kontrol-listesi.md,
	// 1-53 plus closing checks K-a…K-p, new checks appended from 54), so the
	// owner can say "madde 34" and mean the same thing on every record.
	// Sections are listed in the order of the list's columns.
	$colidx = array_flip( array_keys( daw_ae_columns() ) );
	$keys   = array_keys( $sections );
	$pos    = array_flip( $keys );
	usort(
		$keys,
		static function ( $x, $y ) use ( $colidx, $pos ) {
			return ( ( $colidx[ daw_ae_bucket( $x ) ] ?? 99 ) <=> ( $colidx[ daw_ae_bucket( $y ) ] ?? 99 ) ) ?: ( $pos[ $x ] <=> $pos[ $y ] );
		}
	);
	$sorted = array();
	foreach ( $keys as $k ) {
		foreach ( $sections[ $k ]['items'] as $i => $it ) {
			$it    = array_pad( $it, 5, '' );
			$it[5] = daw_ae_madde( $k, $it );
			$sections[ $k ]['items'][ $i ] = $it;
		}
		$sorted[ $k ] = $sections[ $k ];
	}
	$sections = $sorted;

	// Sums and the columns of the list.
	$sum = array( 'ok' => 0, 'no' => 0, 'wait' => 0, 'yok' => 0, 'na' => 0, 'eksik' => 0, 'imp' => 0 );
	$col = array();
	foreach ( $sections as $k => $sec ) {
		$worst = 'na';
		foreach ( $sec['items'] as $it ) {
			$sum[ $it[2] ] = ( $sum[ $it[2] ] ?? 0 ) + 1;
			if ( 'na' === $it[2] && 0 === strpos( (string) $it[3], DAW_AE_IMP ) ) {
				++$sum['imp'];
			}
			if ( 'na' === $it[2] ) {
				continue;
			}
			if ( 'na' === $worst ) {
				$worst = 'ok'; // ok and "checked, not found" are both green.
			}
			if ( in_array( $it[2], array( 'no', 'eksik' ), true ) ) {
				$worst = 'no';
			} elseif ( 'wait' === $it[2] && 'ok' === $worst ) {
				$worst = 'wait';
			}
		}
		$bucket = daw_ae_bucket( $k );
		$rank   = array( 'na' => 0, 'ok' => 1, 'wait' => 2, 'no' => 3 );
		if ( ! isset( $col[ $bucket ] ) || $rank[ $worst ] > $rank[ $col[ $bucket ] ] ) {
			$col[ $bucket ] = $worst;
		}
	}

	return array(
		'v'        => DAW_AE_VERSION,
		'type'     => $type,
		'group'    => $group,
		'at'       => time(),
		'sections' => $sections,
		'sum'      => $sum,
		'col'      => $col,
	);
}

/**
 * The checklist number of one audit line: 1-53 from research/kunye-kontrol-
 * listesi.md, the closing checks as K-a…K-p, and the checks added with the
 * panel as 54 (legal and trust), 55 (freshness, K10), 56 (where to watch by
 * country). Numbers never move; a new check gets the next free number.
 */
function daw_ae_madde( $sec, $it ) {
	$id    = (string) ( $it[4] ?? '' );
	$label = (string) ( $it[0] ?? '' );
	$note  = (string) ( $it[3] ?? '' );

	if ( '' !== $id ) {
		$exact = array(
			'daw_native_title' => '4', 'daw_also_known_as' => '5', 'daw_tmdb_id' => '6', 'daw_episodes' => '10', 'daw_seasons' => '10',
			'daw_duration_min' => '11', 'daw_aired_start' => '12', 'daw_aired_end' => '13', 'daw_airing_days' => '14',
			'daw_content_rating' => '15', 'daw_status' => '16', 'daw_director' => '17', 'daw_screenwriter' => '18',
			'daw_production' => '19', 'daw_language' => '20', 'daw_synopsis' => '21', 'daw_takeaways' => '22',
			'daw_why_watched' => '23', 'daw_tags' => '24', 'daw_adaptation' => '25', 'daw_best_scenes' => '26',
			'daw_filming_location' => '27', 'daw_visual_design' => '28', 'daw_themes_messages' => '29',
			'daw_interesting_facts' => '30', 'daw_real_life_loves' => '31', 'daw_ep_no' => '35', 'daw_ep_date' => '35',
			'daw_ep_title' => '36', 'daw_ep_synopsis' => '37', 'daw_ep_rating' => '38', 'daw_trailer_youtube' => '42',
			'daw_spotify_uri' => '43', 'daw_gallery' => '44', 'daw_trailer_date' => '42', 'daw_link_imdb' => '58', 'daw_link_wikipedia' => '58', 'daw_original_author' => '60', 'daw_studio' => '60', 'daw_producer' => '60', 'daw_awards' => '41', 'daw_remakes' => '57', 'daw_blgl_url' => '3', 'daw_faq_q' => 'K-d', 'daw_faq_a' => 'K-d',
		);
		if ( isset( $exact[ $id ] ) ) {
			return $exact[ $id ];
		}
		$pre = array(
			'daw_ost_' => '39', 'daw_scene_' => '40', 'daw_aw_' => '41', 'daw_watch_' => '45',
			'daw_cast_' => ( preg_match( '/_eps$/', $id ) ? '34' : ( preg_match( '/_role$/', $id ) ? '33' : '32' ) ),
		);
		foreach ( $pre as $p => $n ) {
			if ( 0 === strpos( $id, $p ) ) {
				return $n;
			}
		}
		return '';
	}

	$labels = array(
		'Yerel ad yazısı' => '4', 'Ülke' => '8', 'Durum ve tarihler' => '16', 'Bitiş tarihi' => '13',
		'Özet uzunluğu' => '21', 'Ek bölüm' => '63', 'Özgünlük (madde 64)' => '64', 'Sezon bağı (madde 65)' => '65', 'KOBIS kontrolü (madde 62)' => '62', 'Fragman doğrulaması (madde 42)' => '42', 'Öne çıkanlar' => '22', 'SSS' => 'K-d', 'Bölüm listesi' => '35', 'Bölüm tarihleri dolu' => '35', 'Bölüm özetleri dolu' => '37', 'Bölüm adları dolu' => '36', 'Bölüm reytingleri dolu' => '38', 'OST parça / sanatçı' => '39', 'Ödül yılı / sonucu' => '41', 'Ödül töreni / alan' => '41',
		'Bölüm tarihleri' => '35', 'Kanibalizasyon (K19)' => '1', 'Tür ve TMDB (K21)' => '3', 'Tür' => '3',
		'Tekrar (K14)' => 'K-j', 'Paragraf ritmi (madde 51)' => '51', 'Eski gövde (K17)' => 'K-e',
		'Yabancı veri (K15)' => 'K-k', 'Anahtar kelime araştırması' => '46', 'Tam kelime araştırması' => '46', 'Odak kelime' => '48',
		'Başlıkta yüksek hacimli kalıp (K11)' => '48', 'SEO başlığı' => 'K-b', 'Site adı başlıkta' => 'K-b',
		'Açıklama' => 'K-c', 'Görünen yer tutucu' => 'K-h', 'Öne çıkan görsel' => 'K-a', 'Portre kaynağı satırı' => 'K-a',
		'Ülkeye göre izleme yerleri' => '56', 'Doğrulama damgası' => '55', 'Kilit' => '61', 'Özel hayat iddiası' => '31',
		'GSC sorgusu karşılanmamış' => '73',
	);
	if ( preg_match( '/\(madde (7[5-9]|8[01])\)$/', (string) $label, $mm ) ) {
		return $mm[1];
	}
	if ( isset( $labels[ $label ] ) ) {
		return $labels[ $label ];
	}
	if ( 0 === strpos( $label, 'Kadro bölüm bilgisi' ) ) {
		return '34';
	}
	if ( 0 === strpos( $label, 'Ek bölüm' ) ) {
		return '63';
	}
	if ( 0 === strpos( $label, 'Kadro (' ) ) {
		return '33';
	}
	if ( 'rules' === $sec ) {
		if ( false !== strpos( $note, 'yapay zek' ) ) {
			return '50';
		}
		if ( false !== strpos( $note, 'tire' ) ) {
			return '51';
		}
		if ( false !== strpos( $note, 'yer tutucu' ) ) {
			return 'K-h';
		}
		if ( false !== strpos( $note, 'kaçış' ) ) {
			return '52';
		}
		return 'K-i';
	}
	$by_sec = array( 'seo' => '48', 'page' => 'K-g', 'schema' => 'K-l', 'image' => 'K-a', 'legal' => '54', 'fresh' => '55', 'watch_tmdb' => '56', 'coverage' => '59', 'verify' => '62' );
	return $by_sec[ $sec ] ?? '';
}

/**
 * Is a search pattern met by a piece of text? Word order, punctuation, a year
 * in brackets and the spelling of "K-drama" do not matter, the way they do not
 * matter to the search engine: "cast of pinocchio korean drama" is met by
 * "Pinocchio (2014) K-Drama: Cast". The kind words (kdrama, korean, drama)
 * are optional, because the whole page is about that drama.
 */
function daw_ae_kw_tokens( $t ) {
	// "&amp;" stored by the MCP pipe is the "&" on the page, not a word "amp".
	$t = strtolower( wp_strip_all_tags( html_entity_decode( (string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	$t = preg_replace( '/\b(?:k[\s-]?dramas?|korean\s+dramas?)\b/u', ' kdrama ', $t );
	$t = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $t );
	// A plural is the same search ("lee jong suk movie" is met by "movies").
	$w = array_filter( explode( ' ', $t ), 'strlen' );
	return array_values( array_map( static function ( $x ) {
		return ( strlen( $x ) > 3 && 's' === substr( $x, -1 ) && 'ss' !== substr( $x, -2 ) ) ? substr( $x, 0, -1 ) : $x;
	}, $w ) );
}
function daw_ae_kw_in( $kw, $text, $strict = false ) {
	$stop = array( 'of', 'the', 'a', 'an', 'is', 'in', 'to', 'for', 'on', 'and', 'k' );
	if ( ! $strict ) {
		// In the running text the kind words are implied by the page.
		$stop = array_merge( $stop, array( 'kdrama', 'korean', 'drama', 'series' ) );
	}
	$need = array_diff( daw_ae_kw_tokens( $kw ), $stop );
	if ( ! $need ) {
		return false;
	}
	$have = array_flip( daw_ae_kw_tokens( $text ) );
	foreach ( $need as $w ) {
		if ( ! isset( $have[ $w ] ) ) {
			return false;
		}
	}
	return true;
}

/*
 * Searches Google actually sent to a page (1.75.58, owner 9 Oct 2026).
 * The Ubersuggest table says where each pattern sits on the page; the
 * Search Console queries get the same column, and a query the page does not
 * answer is a waiting line until it is written in where it fits (madde 73).
 */

/**
 * One word as the search engine treats spellings of a Korean name: "jun" and
 * "joon", "kang" and "gang" are one word. Words that are not Latin letters
 * (Hangul, digits) stay as they are.
 */
function daw_ae_kw_fold_word( $w ) {
	$w = strtolower( (string) $w );
	if ( ! preg_match( '/^[a-z]+$/', $w ) ) {
		return $w;
	}
	// British and American spelling are one search (eye colour = eye color).
	$w = strtr( $w, array( 'colour' => 'color', 'favourite' => 'favorite', 'honour' => 'honor', 'centre' => 'center', 'theatre' => 'theater' ) );
	return daw_ae_roman_fold( str_replace( 'oo', 'u', $w ) );
}

/**
 * Spellings that are one word to a searcher (1.75.62): "K-pop" is "kpop",
 * and a hyphenated given name is also its joined form ("Ha-na" → "hana",
 * "Kang-joon" → "kangjoon"), so "kang hana" is met by "Kang Ha-na".
 */
function daw_ae_kw_norm( $t ) {
	$t = (string) $t;
	$t = preg_replace( '/\bk[\s-]?pop\b/iu', ' kpop ', $t );
	return preg_replace( '/\b(\p{L}+)-(\p{L}+)\b/u', '$1-$2 $1$2', $t );
}

/** daw_ae_kw_in with romanisation folded on both sides (seo kang jun = seo kang joon). */
function daw_ae_kw_in_fold( $kw, $text, $strict = false ) {
	$kw   = daw_ae_kw_norm( $kw );
	$text = daw_ae_kw_norm( $text );
	$stop = array( 'of', 'the', 'a', 'an', 'is', 'in', 'to', 'for', 'on', 'and', 'k' );
	if ( ! $strict ) {
		$stop = array_merge( $stop, array( 'kdrama', 'korean', 'drama', 'series' ) );
	}
	$need = array_map( 'daw_ae_kw_fold_word', array_diff( daw_ae_kw_tokens( $kw ), $stop ) );
	if ( ! $need ) {
		return false;
	}
	$have = array_flip( array_map( 'daw_ae_kw_fold_word', daw_ae_kw_tokens( $text ) ) );
	foreach ( $need as $w ) {
		if ( ! isset( $have[ $w ] ) ) {
			return false;
		}
	}
	return true;
}

/**
 * The places of a page a search can be met in, read once per request:
 * SEO title, description, headings, the FAQ section and the rest of the text
 * a visitor gets. $html is the live page when the caller already has it.
 *
 * @return array label => lower-case text
 */
function daw_ae_place_ctx( $post_id, $html = null ) {
	static $memo = array();
	$post_id = (int) $post_id;
	if ( null === $html && isset( $memo[ $post_id ] ) ) {
		return $memo[ $post_id ];
	}
	$s    = function_exists( 'daw_audit_seo_state' ) ? daw_audit_seo_state( $post_id ) : array( 'title' => '', 'description' => '' );
	$html = null === $html ? (string) daw_ae_page_html( $post_id ) : (string) $html;
	$main = preg_match( '#<main[^>]*>(.*?)</main>#is', $html, $mm ) ? $mm[1] : $html;
	$main = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $main );
	$faq  = '';
	if ( preg_match( '#<section[^>]*\bid=["\']faq["\'][^>]*>(.*?)</section>#is', $main, $fm ) ) {
		$faq  = $fm[1];
		$main = str_replace( $fm[0], ' ', $main );
	}
	$heads = preg_match_all( '#<h[1-4][^>]*>(.*?)</h[1-4]>#is', $main, $hm ) ? implode( ' | ', $hm[1] ) : '';
	$plain = static function ( $t ) {
		return strtolower( html_entity_decode( wp_strip_all_tags( (string) $t ), ENT_QUOTES, 'UTF-8' ) );
	};
	$ctx = array(
		'SEO başlığı' => $plain( $s['title'] ?? '' ),
		'Açıklama'    => $plain( $s['description'] ?? '' ),
		'Başlık'      => $plain( $heads ),
		'SSS'         => $plain( $faq ),
		'Sayfa metni' => $plain( $main ),
	);
	return $memo[ $post_id ] = $ctx;
}

/** Where on the page one search is met: the labels of daw_ae_place_ctx, in order. */
function daw_ae_kw_places( $kw, array $ctx ) {
	$out = array();
	foreach ( $ctx as $label => $text ) {
		if ( '' === $text ) {
			continue;
		}
		$strict = in_array( $label, array( 'SEO başlığı', 'Açıklama' ), true );
		if ( daw_ae_kw_in_fold( $kw, $text, $strict ) ) {
			$out[] = $label;
		}
	}
	return $out;
}

/**
 * A query the owner decided the page should not answer: _daw_ok['gsc:<query>']
 * with the reason. Returns the note, or null.
 */
function daw_ae_gsc_skip( $post_id, $query ) {
	static $ok = array();
	if ( ! isset( $ok[ $post_id ] ) ) {
		$o               = get_post_meta( (int) $post_id, '_daw_ok', true );
		$ok[ $post_id ] = is_array( $o ) ? $o : array();
	}
	$k = 'gsc:' . strtolower( trim( (string) $query ) );
	return isset( $ok[ $post_id ][ $k ] ) && is_scalar( $ok[ $post_id ][ $k ] ) ? (string) $ok[ $post_id ][ $k ] : null;
}

/** Letters and digits only, lower case: "Seo Kang-joon" → "seokangjoon". */
function daw_ae_compact( $s ) {
	return preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) ) );
}

/**
 * Every Latin spelling of the record's own name: title, also-known-as, real
 * name and the names TMDB lists. Compact form => as written.
 */
function daw_ae_name_forms( $post_id ) {
	static $memo = array();
	$post_id = (int) $post_id;
	if ( isset( $memo[ $post_id ] ) ) {
		return $memo[ $post_id ];
	}
	$names = array( get_the_title( $post_id ) );
	foreach ( array( 'daw_also_known_as', 'daw_real_name', 'daw_original_title' ) as $f ) {
		$names = array_merge( $names, daw_ae_values( $post_id, $f ) );
	}
	$src = get_post_meta( $post_id, '_daw_src_tmdb', true );
	if ( is_array( $src ) ) {
		$names[] = (string) ( $src['name'] ?? '' );
		$names   = array_merge( $names, (array) ( $src['aka'] ?? array() ) );
	}
	$out = array();
	foreach ( $names as $n ) {
		$n = (string) $n;
		if ( '' === $n || preg_match( '/[^\x00-\x7F\x{00C0}-\x{024F}]/u', $n ) ) {
			continue; // Hangul, Han and other scripts are not typed as a Latin query.
		}
		$c = daw_ae_compact( $n );
		if ( strlen( $c ) >= 4 ) {
			$out[ $c ] = $n;
		}
	}
	return $memo[ $post_id ] = $out;
}

/**
 * A query that is only the record's own name, spelled differently or mistyped
 * ("seo kangjoon", "seo kang joo", "seo kang jooon"). Google already matches
 * these to the page; writing the misspelling on the page would add nothing
 * and reads as keyword stuffing, so they count as met (madde 73).
 * Returns the name it is a form of, or null.
 */
function daw_ae_gsc_variant( $post_id, $query ) {
	$cq = daw_ae_compact( $query );
	if ( strlen( $cq ) < 4 ) {
		return null;
	}
	foreach ( daw_ae_name_forms( $post_id ) as $c => $name ) {
		$room = strlen( $c ) >= 10 ? 2 : 1;
		if ( abs( strlen( $c ) - strlen( $cq ) ) <= $room && levenshtein( $cq, $c ) <= $room ) {
			return $name;
		}
	}
	// Part of the name written as one word ("hyoseop" for Ahn Hyo Seop): a
	// given name of two syllables, at least six letters. Only when the query
	// is that one word: "kang joon", two words, is simply measured on the
	// page (1.75.65, found by the release check).
	if ( false === strpos( trim( (string) $query ), ' ' ) && strlen( $cq ) >= 6 ) {
		foreach ( daw_ae_name_forms( $post_id ) as $c => $name ) {
			if ( strlen( $cq ) < strlen( $c ) && $cq === substr( $c, -strlen( $cq ) ) ) {
				return $name;
			}
		}
	}
	return null;
}

/**
 * Every Search Console query of the latest 28 days, classified:
 *   ok   — met on the page (where: the place labels);
 *   var  — the record's own name, spelled another way or mistyped;
 *   skip — the owner or agent decided the page should not answer it
 *          (_daw_ok['gsc:<query>'] with the reason);
 *   miss — to be written in where it fits.
 * Most clicked and shown first, the order daw_perf_queries returns.
 *
 * @return array query => ['st' => ok|var|skip|miss, 'at' => string[], 'note' => string, 'c','i','p','prev']
 */
function daw_ae_gsc_rows( $post_id, $html = null, $limit = 100 ) {
	if ( ! function_exists( 'daw_perf_queries' ) || ! function_exists( 'daw_perf_paths' ) ) {
		return array();
	}
	list( $qs ) = daw_perf_queries( daw_perf_paths( (int) $post_id ), (int) $limit );
	if ( ! $qs ) {
		return array();
	}
	$ctx  = daw_ae_place_ctx( $post_id, $html );
	$out  = array();
	foreach ( $qs as $q => $r ) {
		$q    = (string) $q;
		$row  = array_merge( (array) $r, array( 'st' => 'miss', 'at' => array(), 'note' => '' ) );
		// A search operator (site:, inurl: ...) or the site's own name is a
		// lookup of the site, not a question the page should answer.
		if ( preg_match( '/^\s*(site|inurl|intitle|intext|cache|related|info):/i', $q ) ) {
			$row['st']   = 'skip';
			$row['note'] = 'Arama operatörü (site: vb.): sayfanın cevaplayacağı bir soru değil (otomatik)';
			$out[ $q ]   = $row;
			continue;
		}
		// The site's own name in a search ("discover asian world seo kang
		// joon") is not asked of the page: it is measured without it.
		$bare = trim( preg_replace( '/\b(discover\s*asian\s*world|discoverasianworld(\.com)?)\b/i', ' ', $q ) );
		if ( '' === $bare ) {
			$row['st']   = 'skip';
			$row['note'] = 'Sitenin kendi adı: sayfanın cevaplayacağı bir soru değil (otomatik)';
			$out[ $q ]   = $row;
			continue;
		}
		$q_orig = $q;
		$q      = $bare;
		$skip = daw_ae_gsc_skip( $post_id, $q_orig );
		$var  = daw_ae_gsc_variant( $post_id, $q );
		// A misspelt name is a variant even when its odd word happens to occur
		// elsewhere on the page ("joo" in another name); an exact spelling of
		// the name is simply met.
		$typo = null !== $var && ! isset( daw_ae_name_forms( $post_id )[ daw_ae_compact( $q ) ] );
		$at   = $ctx && ! $typo ? daw_ae_kw_places( $q, $ctx ) : array();
		if ( $at ) {
			$row['st'] = 'ok';
			$row['at'] = $at;
		} elseif ( null !== $var ) {
			$row['st']   = 'var';
			$row['note'] = $var;
		} elseif ( null !== $skip ) {
			$row['st']   = 'skip';
			$row['note'] = $skip;
		} elseif ( preg_match( '/\b(asianwiki|mydramalist|mdl|reddit|namu\s?wiki|imdb|dramacool|kissasian|viki|kdramalove|soompi|allkpop)\b/i', $q ) ) {
			// Someone looking for another site's page on this subject: the
			// page answers the subject (met above when it does), not the site.
			$row['st']   = 'skip';
			$row['note'] = 'Başka bir sitenin araması: konu sayfada, site adı aranmıyor (otomatik)';
		}
		$out[ $q_orig ] = $row;
	}
	return $out;
}

/**
 * The Search Console queries the page does not answer yet (state "miss").
 *
 * @return array{0:int,1:string[]} [queries looked at, missing queries]
 */
function daw_ae_gsc_missing( $post_id, $html = null, $limit = 100 ) {
	$rows = daw_ae_gsc_rows( $post_id, $html, $limit );
	$miss = array();
	foreach ( $rows as $q => $r ) {
		if ( 'miss' === $r['st'] ) {
			$miss[] = (string) $q;
		}
	}
	return array( count( $rows ), $miss );
}

/**
 * Fingerprint of a record's content: every registered field of its type plus
 * title, excerpt and SEO title and description. Private and volatile metas
 * (watch lists, coverage, image paths, ratings) are not in it, so a weekly
 * TMDB refresh never breaks a lock; an editor's change always does.
 */
function daw_ae_lock_hash( $post_id ) {
	$type = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
	$data = array(
		'title'   => get_the_title( $post_id ),
		'excerpt' => (string) get_post_field( 'post_excerpt', $post_id ),
		'seo_t'   => (string) get_post_meta( $post_id, 'rank_math_title', true ),
		'seo_d'   => (string) get_post_meta( $post_id, 'rank_math_description', true ),
	);
	foreach ( daw_ae_boxes_for( $type ) as $box ) {
		foreach ( $box['fields'] as $f ) {
			if ( ! empty( $f['id'] ) && 0 !== strpos( $f['id'], 'daw_rating' ) ) {
				$data[ $f['id'] ] = daw_ae_values( $post_id, $f['id'] );
			}
		}
	}
	ksort( $data );
	return md5( wp_json_encode( $data ) );
}

/** Is the lock present and intact? */
function daw_ae_is_locked( $post_id ) {
	$lock = get_post_meta( $post_id, DAW_AE_LOCK, true );
	return is_array( $lock ) && ! empty( $lock['hash'] ) && hash_equals( (string) $lock['hash'], daw_ae_lock_hash( $post_id ) );
}

/** Lock a record whose audit is fully green and carries a verification stamp. */
function daw_ae_maybe_lock( $post_id, $a ) {
	$s     = $a['sum'];
	$clean = 0 === (int) ( $s['no'] ?? 0 ) + (int) ( $s['eksik'] ?? 0 );
	$lock  = get_post_meta( $post_id, DAW_AE_LOCK, true );
	$hash  = daw_ae_lock_hash( $post_id );
	if ( is_array( $lock ) && ! empty( $lock['hash'] ) && ! hash_equals( (string) $lock['hash'], $hash ) && ! $clean ) {
		return; // broken and not clean again yet: stays broken until fixed.
	}
	if ( $clean && '' !== (string) get_post_meta( $post_id, 'daw_verified', true ) && ( ! is_array( $lock ) || $lock['hash'] !== $hash ) ) {
		update_post_meta( $post_id, DAW_AE_LOCK, array( 'at' => time(), 'hash' => $hash, 'v' => DAW_AE_VERSION ) );
	}
}

/** Store the short form of an audit (the list needs no field detail). */
function daw_ae_store( $post_id ) {
	$a = daw_ae_audit( $post_id );
	// A lock does not hide episodes TMDB holds and the record lacks: those
	// were closed as "no source" before the episode list was read (owner,
	// 24 Sep 2026), so the lock opens again until they are written.
	foreach ( daw_ae_fails( $a ) as $f ) {
		if ( ( 'no' === ( $f[2] ?? '' ) && 0 === strpos( (string) ( $f[3] ?? '' ), 'TMDB\'de ' ) ) || 'eksik' === ( $f[2] ?? '' ) ) {
			delete_post_meta( $post_id, DAW_AE_LOCK );
			break;
		}
	}
	daw_ae_maybe_lock( $post_id, $a );
	// Keyword coverage for the Posts list, and the page summary the SEO
	// inventory shows, from the page the audit just read (one fetch).
	if ( function_exists( 'daw_ap_kw_cover' ) ) {
		try {
			daw_ap_kw_cover( $post_id );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
	if ( function_exists( 'daw_pa_analyze' ) && function_exists( 'daw_pa_score' ) && function_exists( 'daw_pa_summary_write' ) ) {
		try {
			$pa = daw_pa_analyze( $post_id );
			if ( ! empty( $pa['ok'] ) ) {
				daw_pa_summary_write( $post_id, $pa, daw_pa_score( $post_id, $pa ) );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
	update_post_meta(
		$post_id,
		DAW_AE_META,
		array(
			'v'     => $a['v'],
			'type'  => $a['type'],
			'group' => $a['group'],
			'at'    => $a['at'],
			'sum'   => $a['sum'],
			'col'   => $a['col'],
			'fails' => daw_ae_fails( $a ),
			'lock'  => daw_ae_is_locked( $post_id ),
		)
	);
	// The percentage as its own number, so the Posts list can sort and
	// filter on it.
	if ( function_exists( 'daw_ap_percent' ) ) {
		update_post_meta( $post_id, '_daw_pct', daw_ap_percent( array( 'sum' => $a['sum'] ) ) );
	}
	return $a;
}

/* ========================================================== scheduling === */

/** Re-audit a record shortly after it is saved (off the saving request). */
add_action( 'save_post_post', 'daw_ae_on_save', 40, 2 );
function daw_ae_on_save( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) {
		return;
	}
	if ( ! wp_next_scheduled( 'daw_ae_one', array( (int) $post_id ) ) ) {
		wp_schedule_single_event( time() + 90, 'daw_ae_one', array( (int) $post_id ) );
	}
}
add_action( 'daw_ae_one', 'daw_ae_prep_store' );

/**
 * Everything the machine can do for one record, then its audit: source
 * verification, coverage and watch list, each only when it is due. Runs after
 * a save and when the record is opened in the audit panel, so an agent working
 * a batch never waits for the hourly jobs to reach its records.
 */
function daw_ae_prep( $post_id, $force = false ) {
	$post_id = (int) $post_id;
	// A season page is tied to its TMDB season first (madde 65); the picture,
	// dates and cast below all read from that link.
	if ( function_exists( 'daw_season_link' ) && ! daw_season_no( $post_id ) && daw_season_title_split( get_the_title( $post_id ) )
		&& (int) get_post_meta( $post_id, DAW_SEASON_AT, true ) < time() - HOUR_IN_SECONDS ) {
		try {
			daw_season_link( $post_id );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
	// The TMDB picture match first: verification, coverage and watch data
	// all wait for it, and a record filled in since its last check (a new
	// TMDB id, a native title) should not wait for the hourly job.
	if ( function_exists( 'daw_tmdb_img_sync' ) && defined( 'DAW_TMDB_CHECK' ) && 'ok' !== get_post_meta( $post_id, DAW_TMDB_CHECK, true )
		&& ( $force || ! defined( 'DAW_TMDB_IMG_AT' ) || (int) get_post_meta( $post_id, DAW_TMDB_IMG_AT, true ) < time() - HOUR_IN_SECONDS ) ) {
		try {
			daw_tmdb_img_sync( $post_id );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
	$jobs    = array(
		array( 'DAW_VF_AT', 'daw_vf_run', 30 ),
		array( 'DAW_SRC_AT', 'daw_src_sync', 30 ),
		array( 'DAW_EXT_AT', 'daw_src_ext_sync', 30 ),
		array( 'DAW_COV_AT', 'daw_cov_sync', 7 ),
		array( 'DAW_WP_AT', 'daw_wp_sync', 7 ),
		array( 'DAW_TMDB_EPS_AT', 'daw_tmdb_eps_sync', 30 ),
	);
	foreach ( $jobs as $j ) {
		if ( ! defined( $j[0] ) || ! function_exists( $j[1] ) ) {
			continue;
		}
		$at = (int) get_post_meta( $post_id, constant( $j[0] ), true );
		if ( ! $force && $at > time() - $j[2] * DAY_IN_SECONDS ) {
			continue;
		}
		try {
			call_user_func( $j[1], $post_id );
		} catch ( \Throwable $e ) {
			update_post_meta( $post_id, constant( $j[0] ), time() - ( $j[2] - 1 ) * DAY_IN_SECONDS );
		}
	}
}

function daw_ae_prep_store( $post_id, $force = false ) {
	daw_ae_prep( $post_id, $force );
	return daw_ae_store_safe( $post_id );
}

/**
 * The audit of one record, never fatal. A record whose audit throws is stored
 * with the error as its one red line, so a batch moves on instead of stopping
 * at the same record on every run, and the panel shows what broke.
 */
function daw_ae_store_safe( $post_id ) {
	try {
		return daw_ae_store( $post_id );
	} catch ( \Throwable $e ) {
		$msg = $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
		update_post_meta(
			$post_id,
			DAW_AE_META,
			array(
				'v'     => DAW_AE_VERSION,
				'type'  => function_exists( 'daw_get_type' ) ? (string) daw_get_type( $post_id ) : '',
				'group' => '',
				'at'    => time(),
				'sum'   => array( 'no' => 1 ),
				'col'   => array( 'rules' => 'no' ),
				'fails' => array( array( 'rules', 'Denetim hatası', 'no', $msg, 'K-a', 'Denetim bu kayıtta çalışamadı; eklenti düzeltilmeden kayıt denetlenemez.' ) ),
				'lock'  => false,
				'err'   => $msg,
			)
		);
		return null;
	}
}

/** Records whose stored audit is missing, older than a week or from an older engine. */
function daw_ae_queue( $limit = 20 ) {
	global $wpdb;
	$ids = function_exists( 'daw_audit_record_ids' ) ? daw_audit_record_ids( daw_ae_all_types() ) : array();
	if ( ! $ids ) {
		return array();
	}
	$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '" . esc_sql( DAW_AE_META ) . "'", OBJECT_K ); // phpcs:ignore WordPress.DB
	$out  = array();
	foreach ( $ids as $id ) {
		$m = isset( $rows[ $id ] ) ? maybe_unserialize( $rows[ $id ]->meta_value ) : null;
		if ( ! is_array( $m ) || (int) ( $m['v'] ?? 0 ) !== DAW_AE_VERSION || (int) ( $m['at'] ?? 0 ) < time() - 7 * DAY_IN_SECONDS ) {
			$out[] = (int) $id;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
	}
	return $out;
}

function daw_ae_all_types() {
	$t = array();
	foreach ( daw_ae_groups() as $g ) {
		$t = array_merge( $t, $g['types'] );
	}
	return array_values( array_unique( $t ) );
}

add_action( 'init', 'daw_ae_schedule' );
function daw_ae_schedule() {
	if ( ! wp_next_scheduled( 'daw_ae_cron' ) ) {
		wp_schedule_event( time() + 600, 'hourly', 'daw_ae_cron' );
	}
}
add_action( 'daw_ae_cron', 'daw_ae_cron_run' );
add_action( 'daw_ae_cron', 'daw_ae_imp_catchup', 20 );

/**
 * People audited before the impossible-field rule (1.75.31) are audited again,
 * fifteen an hour beside the normal queue, so their closed rows show up
 * without waiting for the weekly pass. Also runs from the inbox ({"imp_run":n}).
 */
function daw_ae_imp_catchup( $limit = 15 ) {
	$since = (int) get_option( 'daw_ae_imp_since', 0 );
	if ( ! $since ) {
		$since = time();
		update_option( 'daw_ae_imp_since', $since, false );
	}
	if ( get_option( 'daw_ae_imp_done' ) ) {
		return 0;
	}
	global $wpdb;
	$ids = function_exists( 'daw_audit_record_ids' ) ? array_map( 'intval', (array) daw_audit_record_ids( array( 'person' ) ) ) : array();
	$at  = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '" . esc_sql( DAW_AE_META ) . "'", OBJECT_K ); // phpcs:ignore WordPress.DB
	$n   = 0;
	foreach ( $ids as $id ) {
		$m = isset( $at[ $id ] ) ? maybe_unserialize( $at[ $id ]->meta_value ) : null;
		if ( is_array( $m ) && (int) ( $m['at'] ?? 0 ) >= $since ) {
			continue;
		}
		if ( $n >= $limit ) {
			return $n;
		}
		daw_ae_store_safe( $id );
		++$n;
	}
	update_option( 'daw_ae_imp_done', time(), false );
	return $n;
}

/** How many fields the impossible rule closed, archive-wide, for the control centre. */
function daw_ae_imp_total() {
	global $wpdb;
	$rows = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '" . esc_sql( DAW_AE_META ) . "'" ); // phpcs:ignore WordPress.DB
	$out  = array( 'fields' => 0, 'records' => 0 );
	foreach ( (array) $rows as $v ) {
		$m = maybe_unserialize( $v );
		$n = is_array( $m ) ? (int) ( $m['sum']['imp'] ?? 0 ) : 0;
		if ( $n ) {
			$out['fields']  += $n;
			$out['records'] += 1;
		}
	}
	return $out;
}
function daw_ae_cron_run() {
	// Twenty an hour: each audit also opens the live page once, and a shared
	// host should never feel the audit running.
	foreach ( daw_ae_queue( 20 ) as $id ) {
		daw_ae_store_safe( $id );
	}
}


/* ======================================================= helpers (v4) === */

/** Which list column a section belongs to. */
function daw_ae_bucket( $key ) {
	$fixed = array( 'rules' => 'rules', 'seo' => 'seo', 'page' => 'page', 'schema' => 'schema', 'image' => 'image', 'legal' => 'legal', 'fresh' => 'fresh', 'setup' => 'kunye', 'coverage' => 'kadro', 'verify' => 'kunye' );
	if ( isset( $fixed[ $key ] ) ) {
		return $fixed[ $key ];
	}
	$map = array(
		'cast'      => 'kadro',
		'episode'   => 'bolum',
		'ost'       => 'muzik',
		'music'     => 'muzik',
		'discograph' => 'muzik',
		'award'     => 'odul',
		'faq'       => 'sss',
		'asked'     => 'sss',
		'watch'     => 'baglanti',
		'social'    => 'baglanti',
		'links'     => 'baglanti',
		'related'   => 'baglanti',
	);
	foreach ( $map as $needle => $b ) {
		if ( false !== strpos( $key, $needle ) ) {
			return $b;
		}
	}
	return 'kunye';
}

/** The columns of the list, in order. */
function daw_ae_columns() {
	return array(
		'kunye'    => 'Künye',
		'kadro'    => 'Kadro',
		'bolum'    => 'Bölüm',
		'muzik'    => 'Müzik',
		'odul'     => 'Ödül',
		'sss'      => 'SSS',
		'baglanti' => 'Bağlantı',
		'rules'    => 'Yazı',
		'seo'      => 'SEO',
		'page'     => 'Sayfa',
		'schema'   => 'Schema',
		'image'    => 'Görsel',
		'legal'    => 'Hukuk',
		'fresh'    => 'Güncel',
	);
}

/** Every failing line, short, so an agent can read it through the API. */
function daw_ae_fails( $a ) {
	$out = array();
	foreach ( $a['sections'] as $k => $sec ) {
		foreach ( $sec['items'] as $it ) {
			if ( in_array( $it[2], array( 'no', 'eksik', 'wait' ), true ) ) {
				$rule  = function_exists( 'daw_rule_text' ) ? daw_rule_text( (string) ( $it[5] ?? '' ), (string) ( $it[4] ?? '' ) ) : '';
				$out[] = array( $k, $it[0], $it[2], mb_substr( (string) $it[3], 0, 140 ), (string) ( $it[5] ?? '' ), mb_substr( $rule, 0, 220 ), mb_substr( (string) ( $it[1] ?? '' ), 0, 120 ) );
			}
		}
	}
	return array_slice( $out, 0, 80 );
}

/** Other records that target the same search (K19). */
function daw_ae_duplicates( $post_id ) {
	static $idx = null;
	global $wpdb;
	if ( null === $idx ) {
		$idx  = array( 'focus' => array(), 'title' => array(), 'native' => array() );
		$rows = $wpdb->get_results( "SELECT m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_status = 'publish' AND p.post_type = 'post' AND m.meta_key IN ('rank_math_focus_keyword','rank_math_title','daw_native_title') AND m.meta_value <> ''" ); // phpcs:ignore WordPress.DB
		$key  = array( 'rank_math_focus_keyword' => 'focus', 'rank_math_title' => 'title', 'daw_native_title' => 'native' );
		foreach ( $rows as $r ) {
			$v = strtolower( trim( (string) $r->meta_value ) );
			if ( 'focus' === $key[ $r->meta_key ] ) {
				$v = strtolower( trim( explode( ',', $v )[0] ) );
			}
			if ( '' !== $v && false === strpos( $v, '%' ) ) {
				$idx[ $key[ $r->meta_key ] ][ $v ][] = (int) $r->post_id;
			}
		}
	}
	$out   = array();
	$names = array( 'focus' => 'Aynı odak kelime', 'title' => 'Aynı SEO başlığı', 'native' => 'Aynı yerel ad' );
	foreach ( array( 'focus' => 'rank_math_focus_keyword', 'title' => 'rank_math_title', 'native' => 'daw_native_title' ) as $k => $meta ) {
		$v = strtolower( trim( (string) get_post_meta( $post_id, $meta, true ) ) );
		if ( 'focus' === $k ) {
			$v = strtolower( trim( explode( ',', $v )[0] ) );
		}
		if ( '' === $v || empty( $idx[ $k ][ $v ] ) ) {
			continue;
		}
		$others = array_diff( array_unique( $idx[ $k ][ $v ] ), array( (int) $post_id ) );
		// A film and the drama remade from it carry the same Korean title by
		// design (The Beauty Inside, 2015 film and 2018 JTBC drama): the native
		// title alone is no collision when the two are different kinds of work.
		// Any pair a person has checked is kept in _daw_ok['k19:<id>'].
		$okd  = get_post_meta( $post_id, '_daw_ok', true );
		$okd  = is_array( $okd ) ? $okd : array();
		$mine = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
		$others = array_filter( $others, static function ( $o ) use ( $k, $okd, $mine ) {
			if ( isset( $okd[ 'k19:' . (int) $o ] ) ) {
				return false;
			}
			return ! ( 'native' === $k && '' !== $mine && function_exists( 'daw_get_type' ) && daw_get_type( $o ) !== $mine );
		} );
		if ( $others ) {
			$out[] = array( $names[ $k ] . ': ' . implode( ', ', array_map( static function ( $o ) { return '#' . $o . ' ' . get_the_title( $o ); }, array_slice( $others, 0, 3 ) ) ), $v );
		}
	}
	return $out;
}

/** The published page, fresh (cache busted, K2), or '' when it cannot be read. */
function daw_ae_page_html( $post_id ) {
	// One fetch per record per request: the audit and the keyword coverage
	// stored after it read the same page.
	static $memo = array();
	if ( isset( $memo[ (int) $post_id ] ) ) {
		return $memo[ (int) $post_id ];
	}
	$memo[ (int) $post_id ] = daw_ae_page_html_fetch( $post_id );
	return $memo[ (int) $post_id ];
}

function daw_ae_page_html_fetch( $post_id ) {
	$url = get_permalink( $post_id );
	if ( ! $url || 'publish' !== get_post_status( $post_id ) ) {
		return '';
	}
	$res = wp_remote_get(
		add_query_arg( 'daw_nc', time(), $url ),
		array(
			'timeout'     => 20,
			'redirection' => 2,
			'user-agent'  => 'DAW-Audit/1.0 (+' . home_url( '/' ) . ')',
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		)
	);
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return '';
	}
	return (string) wp_remote_retrieve_body( $res );
}

/**
 * Read the page like a crawler: headings, images, links, the JSON-LD blocks,
 * the notices the site promises. Returns [page items, legal items, schema
 * items, heading texts].
 */
function daw_ae_page_checks( $post_id, $html, $group, $type ) {
	$page  = array();
	$legal = array();
	$sch   = array();
	$heads = array();

	if ( '' === $html || ! class_exists( 'DOMDocument' ) ) {
		$page[] = array( 'Sayfa okunamadı', '', 'wait', 'Sunucu kendi sayfasını açamadı; bir sonraki denetimde yeniden denenir' );
		return array( $page, $legal, $sch, $heads );
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
	libxml_clear_errors();
	$xp = new DOMXPath( $dom );

	// Head.
	$t_nodes = $dom->getElementsByTagName( 'title' );
	$t       = $t_nodes->length ? trim( $t_nodes->item( 0 )->textContent ) : '';
	$page[]  = array( '<title>', $t, '' !== $t ? 'ok' : 'no', '' );
	$md      = $xp->query( '//meta[@name="description"]/@content' );
	$page[]  = array( 'Meta açıklama', $md->length ? mb_strlen( $md->item( 0 )->nodeValue ) . ' karakter' : '', $md->length ? 'ok' : 'no', '' );
	$canon   = $xp->query( '//link[@rel="canonical"]/@href' );
	$c_ok    = $canon->length && untrailingslashit( strtok( $canon->item( 0 )->nodeValue, '?' ) ) === untrailingslashit( get_permalink( $post_id ) );
	// The staging copy is closed to search engines on purpose; these two
	// lines are measured on the live domain only.
	$staging = 0 === strpos( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'stg.' );
	$page[]  = $staging && ! $c_ok
		? array( 'Canonical', $canon->length ? $canon->item( 0 )->nodeValue : '', 'na', 'Hazırlık sitesi: canlı alan adında ölçülür' )
		: array( 'Canonical', $canon->length ? $canon->item( 0 )->nodeValue : '', $c_ok ? 'ok' : 'no', $c_ok ? '' : 'Canonical yok ya da başka adrese gidiyor' );
	$robots  = $xp->query( '//meta[@name="robots"]/@content' );
	$noindex = $robots->length && false !== stripos( $robots->item( 0 )->nodeValue, 'noindex' );
	$page[]  = $staging && $noindex
		? array( 'Dizine alınabilir', 'noindex', 'na', 'Hazırlık sitesi: kasıtlı olarak kapalı; canlıda ölçülür' )
		: array( 'Dizine alınabilir', $noindex ? 'noindex' : 'index', $noindex ? 'no' : 'ok', $noindex ? 'Sayfa arama motorlarına kapalı' : '' );
	$og      = $xp->query( '//meta[@property="og:image"]/@content' );
	// Records never send a file hosted here as their share picture: the site
	// keeps no copies of posters or portraits.
	$og_url  = $og->length ? (string) $og->item( 0 )->nodeValue : '';
	$og_home = $og_url && false !== strpos( $og_url, '/wp-content/uploads/' ) && in_array( $group, array( 'title', 'show', 'person', 'group' ), true );
	// A music group has no TMDB entity, so no TMDB picture can exist for it.
	// A group may share a freely licensed photo instead (CC0, public domain,
	// CC BY, CC BY-SA, KOGL Type 1): these allow commercial reuse, including a
	// page that carries ads and affiliate links, as long as the credit line is
	// shown. Press kits and the site's own drawings are not accepted for real
	// people.
	$og_note = $og_home ? 'og:image sitede barınan bir dosya; TMDB adresi olmalı' : '';
	// A record TMDB has no poster for, with nothing uploaded to it, gets the
	// site's own default share picture (the logo) from Rank Math. That is not a
	// copy of a poster or portrait, so it waits instead of failing. A local
	// featured image on such a record is not shared (daw_share_image_for_record
	// names the default instead), so it no longer blocks this.
	$og_default = $og_home && ( 'group' === $group ? ! get_post_thumbnail_id( $post_id ) : true ) && function_exists( 'daw_remote_mode_for' ) && 'monogram' === daw_remote_mode_for( $post_id ) && function_exists( 'daw_tmdb_img_path_raw' ) && '' === daw_tmdb_img_path_raw( $post_id );
	if ( $og_default ) {
		$og_home = false;
		$og_note = 'TMDB kaydında afiş yok: site varsayılan paylaşım görseli (logo) gidiyor';
	}
	if ( $og_home && 'group' === $group && function_exists( 'daw_lic_get' ) ) {
		$att     = (int) get_post_thumbnail_id( $post_id );
		$lic     = $att ? daw_lic_get( $att ) : array();
		$open    = $lic && in_array( $lic['type'], array( 'cc0', 'pd', 'cc-by', 'cc-by-sa', 'kogl1' ), true ) && 'ok' === daw_lic_status( $att, $post_id );
		$og_home = ! $open;
		$og_note = $open ? 'Açık lisanslı görsel (' . $lic['type'] . '), atıf sayfada' : 'Grup: TMDB\'de grup görseli yok; açık lisanslı (CC0, PD, CC BY, CC BY-SA, KOGL 1) ve hak kaydı tam bir görsel öne çıkan görsel olmalı';
	}
	$page[]  = array( 'Sosyal paylaşım görseli', $og_url ? wp_parse_url( $og_url, PHP_URL_HOST ) : '', $og_home ? 'no' : ( $og_url && ! $og_default ? 'ok' : 'wait' ), $og_note );

	// Headings.
	$h1 = $dom->getElementsByTagName( 'h1' );
	$page[] = array( 'H1 sayısı', (string) $h1->length, 1 === $h1->length ? 'ok' : 'no', 1 === $h1->length ? '' : 'Sayfada tam bir H1 olmalı' );
	$qnorm = static function ( $t ) {
		$t = html_entity_decode( wp_strip_all_tags( (string) $t ), ENT_QUOTES, 'UTF-8' );
		return trim( str_replace( array( "\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}" ), array( "'", "'", '"', '"' ), $t ) );
	};
	if ( 1 === $h1->length && false === stripos( $qnorm( $h1->item( 0 )->textContent ), $qnorm( get_the_title( $post_id ) ) ) ) {
		$page[] = array( 'H1 metni', trim( $h1->item( 0 )->textContent ), 'no', 'H1 kaydın adını taşımıyor' );
	}
	$last = 1;
	$skip = '';
	foreach ( $xp->query( '//h1|//h2|//h3|//h4' ) as $h ) {
		$lvl = (int) substr( $h->nodeName, 1 );
		if ( $lvl >= 2 ) {
			$heads[] = trim( preg_replace( '/\s+/u', ' ', $h->textContent ) );
		}
		if ( $lvl > $last + 1 && '' === $skip ) {
			$skip = 'H' . $last . ' sonrası H' . $lvl . ': "' . mb_substr( trim( $h->textContent ), 0, 40 ) . '"';
		}
		$last = $lvl;
	}
	$page[] = array( 'Başlık sırası', count( $heads ) . ' ara başlık', '' === $skip ? 'ok' : 'no', '' === $skip ? '' : 'Seviye atlanmış: ' . $skip );
	if ( 'person' === $group ) {
		$bio    = (bool) preg_grep( '/biograph/i', $heads );
		$page[] = array( 'Biography başlığı', '', $bio ? 'ok' : 'no', $bio ? '' : 'Kişi sayfasında Biography H2 şart' );
	}
	$mains  = $dom->getElementsByTagName( 'main' )->length;
	$page[] = array( '<main> sayısı', (string) $mains, $mains <= 1 ? 'ok' : 'no', $mains <= 1 ? '' : 'Sayfada birden fazla <main> var' );

	// First paragraph (rule 5.5).
	// A news story: the kicker, the standfirst and the "Source" line are not the
	// first paragraph; the first <p> of the body is (1.75.56). It had counted
	// the "Source" line (12, 8 and 25 words for 55, 46 and about 50).
	$p1 = $xp->query( '//*[contains(concat(" ",normalize-space(@class)," ")," daw-nbody ")]//p' );
	if ( ! $p1->length ) {
		$p1 = $xp->query( '//*[contains(concat(" ",normalize-space(@class)," ")," daw-maincol ")]//p[not(ancestor::*[contains(concat(" ",normalize-space(@class)," ")," daw-nsource ")])]' );
	}
	if ( $p1->length ) {
		$first = '';
		foreach ( $p1 as $pn ) {
			$first = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $pn->textContent ), ENT_QUOTES, 'UTF-8' ) ) );
			if ( '' !== $first ) {
				break;
			}
		}
		$w      = str_word_count( $first );
		$page[] = array( 'İlk paragraf', $w . ' kelime', ( $w >= 30 && $w <= 70 ) ? 'ok' : 'wait', 'Kural 5.5: 40 ile 60 kelime, doğrudan cevap' );
	}

	// Images.
	$imgs  = $dom->getElementsByTagName( 'img' );
	$noalt = 0;
	$hosts = array();
	foreach ( $imgs as $im ) {
		if ( ! $im->hasAttribute( 'alt' ) ) {
			$noalt++;
		}
		$src = $im->getAttribute( 'src' );
		$h   = (string) wp_parse_url( $src, PHP_URL_HOST );
		if ( '' !== $h ) {
			$hosts[ $h ] = ( $hosts[ $h ] ?? 0 ) + 1;
		}
		if ( ! $im->hasAttribute( 'width' ) || ! $im->hasAttribute( 'height' ) ) {
			$nosize = true;
		}
	}
	$hl = array();
	foreach ( $hosts as $h => $n ) {
		$hl[] = ( 'image.tmdb.org' === $h ? 'TMDB API' : $h ) . ' ' . $n;
	}
	$page[] = array( 'Görsel alt metinleri', $imgs->length . ' görsel', 0 === $noalt ? 'ok' : 'no', 0 === $noalt ? '' : $noalt . ' görselde alt metin yok' );
	$page[] = array( 'Görsellerin geldiği yer', implode( ' · ', $hl ), 'ok', '' );
	if ( ! empty( $nosize ) ) {
		$page[] = array( 'Görsel boyutları', '', 'wait', 'Genişlik/yükseklik yazmayan görsel var (kayma, kural 7.2)' );
	}

	// Visible placeholders and editor leftovers.
	$main_txt = '';
	foreach ( $xp->query( '//*[contains(concat(" ",normalize-space(@class)," ")," daw-maincol ")]' ) as $m ) {
		$main_txt .= ' ' . $m->textContent;
	}
	foreach ( array( 'N/A', 'To be confirmed', '[Information not available]', 'Not recorded', 'Lorem ipsum' ) as $ph ) {
		if ( false !== strpos( $main_txt, $ph ) ) {
			$page[] = array( 'Görünen yer tutucu', $ph, 'no', 'Okura boş alan duyuruluyor (kural 2.2)' );
		}
	}
	if ( false !== strpos( $html, '-mx-px' ) ) {
		$page[] = array( 'Editör artığı', '', 'no', 'Başka bir araçtan kalmış markup' );
	}

	// Links.
	$bad_rel = 0;
	$pirate  = array();
	foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
		$href = $a->getAttribute( 'href' );
		if ( '_blank' === $a->getAttribute( 'target' ) && false === strpos( $a->getAttribute( 'rel' ), 'noopener' ) ) {
			$bad_rel++;
		}
		if ( preg_match( '/dramacool|kissasian|myasiantv|dramanice|viewasian|asianc\\.|kisskh|dramago|watchasian/i', $href ) ) {
			$pirate[] = (string) wp_parse_url( $href, PHP_URL_HOST );
		}
	}
	$page[]  = array( 'Yeni sekme bağlantıları', '', 0 === $bad_rel ? 'ok' : 'no', 0 === $bad_rel ? '' : $bad_rel . ' bağlantıda noopener yok (kural 3.6)' );
	$legal[] = array( 'Korsan siteye bağlantı', implode( ', ', array_unique( $pirate ) ), $pirate ? 'no' : 'ok', $pirate ? 'Resmî olmayan izleme sitesine bağlantı' : '' );

	// Notices the site promises.
	$all_txt = $dom->textContent;
	if ( in_array( $group, array( 'title', 'show', 'person', 'group' ), true ) ) {
		$tmdb    = false !== stripos( $all_txt, 'uses the TMDB API but is not endorsed or certified by TMDB' );
		$legal[] = array( 'TMDB bildirimi', '', $tmdb ? 'ok' : 'no', $tmdb ? '' : 'TMDB\'nin istediği cümle sayfada yok' );
	}
	$take    = false !== stripos( $all_txt, 'info@discoverasianworld.com' );
	$legal[] = array( 'Kaldırma talebi adresi', '', $take ? 'ok' : 'no', '' );
	$aff     = false !== stripos( $all_txt, 'may earn commission' );
	// Only a page that carries an affiliate link owes the disclosure.
	$sponsor = $xp->query( '//a[contains(@rel,"sponsored")]' )->length;
	$legal[] = $aff ? array( 'Ortaklık açıklaması', '', 'ok', '' )
		: ( $sponsor ? array( 'Ortaklık açıklaması', $sponsor . ' bağlantı', 'no', 'Ortaklık bağlantısı var, açıklama cümlesi yok' ) : array( 'Ortaklık açıklaması', '', 'na', 'Sayfada ortaklık bağlantısı yok' ) );
	if ( preg_match( '/\\b(dating rumou?rs?|allegedly|rumou?red to be dating|secretly dating)\\b/i', $main_txt, $mm ) ) {
		$legal[] = array( 'Özel hayat iddiası', $mm[0], 'wait', 'Tarih ve kaynakla verilmiş mi bakılmalı (kural 1.3)' );
	}

	// JSON-LD as sent.
	$blocks = 0;
	$types  = array();
	$bad    = 0;
	foreach ( $xp->query( '//script[@type="application/ld+json"]' ) as $sc ) {
		$blocks++;
		$j = json_decode( $sc->textContent, true );
		if ( ! is_array( $j ) ) {
			$bad++;
			continue;
		}
		$nodes = isset( $j['@graph'] ) ? (array) $j['@graph'] : array( $j );
		foreach ( $nodes as $n ) {
			if ( is_array( $n ) && ! empty( $n['@type'] ) ) {
				$types[] = is_array( $n['@type'] ) ? implode( '/', $n['@type'] ) : $n['@type'];
				if ( 'FAQPage' === ( $n['@type'] ?? '' ) ) {
					$sch[] = array( 'FAQPage soru sayısı', (string) count( (array) ( $n['mainEntity'] ?? array() ) ), 'ok', '' );
				}
			}
		}
	}
	$sch[] = array( 'Sayfadaki schema blokları', $blocks . ' blok · ' . implode( ', ', array_unique( $types ) ), ( $blocks >= 1 && 0 === $bad ) ? 'ok' : 'no', $bad ? $bad . ' blok JSON olarak okunamıyor' : ( $blocks ? '' : 'Sayfada hiç schema yok' ) );
	if ( count( array_intersect( $types, array( 'TVSeries', 'Movie', 'Person', 'MusicGroup' ) ) ) > 1 ) {
		$sch[] = array( 'Çift ana tür', implode( ', ', $types ), 'no', 'Sayfa kendini iki farklı şey olarak tanıtıyor' );
	}

	return array( $page, $legal, $sch, $heads );
}

/**
 * When TMDB's episode list (`_daw_tmdb_eps`, tmdb-episodes.php) holds data the
 * record lacks, the note that keeps the line red; '' when nothing is owed.
 * $filled: rows already written for that column (a full column is not asked
 * again).
 */
/* ============================================================ madde 66 === */

const DAW_NA_MIN_SOURCES  = 5; // 10 until 1.75.54 (owner, 8 Oct 2026: five checked places, two official, is enough)
const DAW_NA_MIN_OFFICIAL = 1; // 2 until 1.75.55 (owner, 8 Oct 2026)
const DAW_TRAILER_NA_MIN   = 5;
const DAW_TRAILER_RECHECK  = 90; // days until a "trailer not found" is searched again

/** Date (timestamp) of the last trailer search written in _daw_yok, or 0. */
function daw_trailer_na_date( $reason ) {
	if ( ! is_string( $reason ) ) {
		return 0;
	}
	if ( preg_match( '/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/', $reason, $m ) ) {
		return (int) gmmktime( 0, 0, 0, (int) $m[2], (int) $m[1], (int) $m[3] );
	}
	if ( preg_match( '/\b(\d{4})-(\d{2})-(\d{2})\b/', $reason, $m ) ) {
		return (int) gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );
	}
	return 0;
}

/**
 * Records whose trailer was written down as not found and whose last search
 * is older than DAW_TRAILER_RECHECK days: [ post_id => last search timestamp ].
 * Shown on the work page so the trailer is looked for again.
 */
function daw_trailer_recheck_due() {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_daw_yok' AND meta_value LIKE '%daw_trailer_youtube%'" );
	$due  = array();
	$cut  = time() - DAW_TRAILER_RECHECK * DAY_IN_SECONDS;
	foreach ( (array) $rows as $r ) {
		$y = maybe_unserialize( $r->meta_value );
		$y = is_string( $y ) ? json_decode( $y, true ) : $y;
		if ( ! is_array( $y ) || empty( $y['daw_trailer_youtube'] ) || '' !== (string) get_post_meta( (int) $r->post_id, 'daw_trailer_youtube', true ) ) {
			continue;
		}
		$t = daw_trailer_na_date( $y['daw_trailer_youtube'] );
		if ( $t && $t < $cut ) {
			$due[ (int) $r->post_id ] = $t;
		}
	}
	asort( $due );
	return $due;
}

/**
 * N/A is a finding, and a finding shows its work (owner, 24 Sep 2026): "I went
 * through ten to twenty places, the official pages included, and it is not
 * there". So an N/A reason lists what was read:
 *
 *   <why>. Bakılan (12): sbs.co.kr/... (resmi); netflix.com/title/... (resmi);
 *   ko.wikipedia.org/wiki/...; namu.wiki/w/...; ... | 24.09.2026
 *
 * At least ten distinct places, at least two of them official (broadcaster,
 * platform, studio, agency, publisher, rating board, or marked "(resmi)" /
 * "(official)"), and a date. A reason that says the source was not read
 * ("not fetched", "this session", "okunamadı") is not a finding at all.
 *
 * Structural N/A, where the field has no meaning for this record, starts with
 * "Uygulanmaz:" and is accepted only on the few fields where that can be true
 * (a season whose IMDb and Wikipedia page is the series page; an original
 * screenplay with no source work), with two places named that say so.
 */
function daw_na_official_hosts() {
	return array(
		// Korea: broadcasters, platforms, studios, rating and film boards.
		'kbs.co.kr', 'sbs.co.kr', 'imbc.com', 'mbc.co.kr', 'jtbc.co.kr', 'jtbc.com', 'tvn.cjenm.com', 'cjenm.com', 'tving.com', 'wavve.com', 'coupangplay.com', 'ichannela.com', 'mbn.co.kr', 'tvchosun.com', 'ena.skylife.co.kr', 'skylife.co.kr', 'ebs.co.kr', 'studiodragon.net', 'jtbcstudios.com', 'kmrb.or.kr', 'kofic.or.kr', 'kobis.or.kr', 'kcsc.or.kr',
		// Publishers of the source work.
		'comic.naver.com', 'webtoons.com', 'page.kakao.com', 'webtoon.kakao.com', 'series.naver.com', 'ridibooks.com', 'lezhin.com',
		// Global platforms.
		'netflix.com', 'about.netflix.com', 'primevideo.com', 'amazon.com', 'disneyplus.com', 'viki.com', 'kocowa.com', 'iq.com', 'iqiyi.com', 'wetv.vip', 'youku.com', 'mgtv.com', 'tv.apple.com', 'hulu.com', 'max.com', 'crunchyroll.com', 'viu.com', 'ondemandkorea.com',
		// Japan, China, Taiwan, Hong Kong, Thailand, the Philippines.
		'nhk.or.jp', 'fujitv.co.jp', 'tbs.co.jp', 'ntv.co.jp', 'tv-asahi.co.jp', 'tv-tokyo.co.jp', 'gtv.com.tw', 'settv.com.tw', 'tvb.com', 'ch3thailand.com', 'gmmtv.com', 'abs-cbn.com', 'gmanetwork.com',
	);
}

/** '' when the N/A holds, otherwise the note that turns the row into EKSİK. */
function daw_ae_yok_check( $field, $reason, $all = array() ) {
	if ( ! is_string( $reason ) || '' === trim( $reason ) ) {
		return 'N/A sebebi yazılmamış (madde 66): bakılmamış sayılır.';
	}
	// WordPress stores "&" inside a URL as "&amp;"; its ";" would split the
	// place list and break the count, so the text is decoded first.
	$r = html_entity_decode( trim( $reason ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$all = is_array( $all ) ? array_map( static function ( $v ) { return is_string( $v ) ? html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $v; }, $all ) : $all;
	// "Same list as daw_awards" / "bkz. daw_ost_album": one piece of research
	// often closes several fields (the five award columns, the three OST
	// columns). The referenced field's list is read in, not repeated; a chain
	// (role -> person -> loves) is followed up to three steps.
	$ref_re  = '/(?:same (?:list|research)(?: and reason)? as|see|bkz\.?|ayn[ıi] liste:?)\s+(?:_?daw_yok\.)?(daw_[a-z0-9_]+)/iu';
	// The referenced list may be written with or without "https://".
	$list_re = '/(?:Bak[ıi]lan|Checked)\s*(?:\([^)]*\))?\s*:\s*((?:https?:\/\/)?[a-z0-9-]+\.[a-z]{2,}[^|]*?)(?:\||$)/isu';
	if ( is_array( $all ) && preg_match( $ref_re, $r, $ref ) ) {
		$target = $ref[1];
		$seen_r = array( $field => true );
		for ( $step = 0; $step < 3 && isset( $all[ $target ] ) && is_string( $all[ $target ] ) && ! isset( $seen_r[ $target ] ); $step++ ) {
			$seen_r[ $target ] = true;
			if ( preg_match( $list_re, $all[ $target ], $rm ) ) {
				$date = preg_match( '/\b(\d{1,2}\.\d{1,2}\.\d{4}|\d{4}-\d{2}-\d{2})\b/', $r, $dm ) ? $dm[1] : '';
				// A bare "same list as daw_x" takes the date of the research it points to.
				if ( '' === $date && preg_match( '/\b(\d{1,2}\.\d{1,2}\.\d{4}|\d{4}-\d{2}-\d{2})\b/', $all[ $target ], $dm ) ) {
					$date = $dm[1];
				}
				$head = trim( preg_replace( '/(?:Bak[ıi]lan|Checked)\s*(?:\([^)]*\))?\s*:.*$/isu', '', preg_replace( $ref_re, '', $r ) ) );
				$r    = $head . ' Bakılan: ' . trim( $rm[1] ) . ( '' !== $date ? ' | ' . $date : '' );
				break;
			}
			if ( ! preg_match( $ref_re, $all[ $target ], $ref ) ) {
				break;
			}
			$target = $ref[1];
		}
	}

	$weak = '/\b(not (fetched|returned|read|checked|reached|opened|searched)|snippets?|budget|could not|couldn\'t|unable to|timed? ?out|rate.?limit|to do|todo)\b|\bHTTP 4\d\d\b|\b429\b|bak[ıi]lmad[ıi]|bak[ıi]lamad[ıi]|okunamad[ıi]|eri[şs]ilemedi|al[ıi]namad[ıi]|engellendi|denenmedi|sonra bak[ıi]l/iu';
	$list = '';
	$m    = array();
	if ( preg_match( '/(?:Bak[ıi]lan|Checked)\s*(?:\([^)]*\))?\s*:\s*(.+?)(?:\||$)/isu', $r, $m ) ) {
		$list = $m[1];
	}
	// A reason that lists its places without the "Bakılan:" label ("İki yer:
	// url ve url") still counts them; the label is a convention, the URLs are
	// the evidence.
	if ( '' === $list && preg_match_all( '#https?://[^\s;,)]+(?:\s*\((?:resmi|official)\))?#iu', $r, $um ) ) {
		$list = implode( '; ', $um[0] );
	}
	$head = empty( $m[0] ) ? $r : substr( $r, 0, (int) strpos( $r, $m[0] ) );
	if ( preg_match( $weak, $head ) ) {
		return 'Zayıf N/A (madde 66): sebep kaynağın okunmadığını söylüyor, olmadığını değil. Eski sebep: ' . $r;
	}

	$seen     = array();
	$official = 0;
	$hosts    = daw_na_official_hosts();
	foreach ( preg_split( '/\s*;\s*/u', $list ) as $item ) {
		$item = trim( $item );
		if ( '' === $item ) {
			continue;
		}
		$key = strtolower( preg_replace( '/\s*\((resmi|official)\)\s*$/iu', '', preg_replace( '#^https?://(www\.)?#i', '', $item ) ) );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$is_off       = (bool) preg_match( '/\((resmi|official)\)/iu', $item );
		if ( ! $is_off && preg_match( '#^([a-z0-9.-]+\.[a-z]{2,})#i', $key, $hm ) ) {
			foreach ( $hosts as $h ) {
				if ( $hm[1] === $h || '.' . $h === substr( $hm[1], -strlen( '.' . $h ) ) || 0 === strpos( $key, $h ) ) {
					$is_off = true;
					break;
				}
			}
		}
		$official += $is_off ? 1 : 0;
	}
	$n       = count( $seen );
	$dated   = (bool) preg_match( '/\b\d{1,2}\.\d{1,2}\.\d{4}\b|\b\d{4}-\d{2}-\d{2}\b/', $r );
	$struct  = (bool) preg_match( '/^Uygulanmaz\s*:/iu', $r );
	$struct_ok_fields = array( 'daw_link_imdb', 'daw_link_wikipedia', 'daw_original_author', 'daw_adaptation', 'daw_food_kind', 'daw_guide_native', 'daw_mentions' );

	if ( $struct ) {
		if ( ! in_array( $field, $struct_ok_fields, true ) ) {
			return 'Bu alan "Uygulanmaz" olamaz (madde 66): araştırılır, bulunamazsa bakılan yerlerle N/A yazılır.';
		}
		if ( 'daw_food_kind' === $field || 'daw_guide_native' === $field ) {
			// A sorting choice, not a fact from a source: a guide to a whole
			// cuisine is not one kind of dish. The reason and the date are enough.
			return $dated ? '' : 'Uygulanmaz sebebi tarihle yazılır (madde 66).';
		}
		return $n >= 2 && $dated ? '' : 'Uygulanmaz sebebi en az iki yerle ve tarihle yazılır (madde 66). Şu an: ' . $n . ' yer.';
	}
	// A trailer nobody official has uploaded (owner, 26 Sep 2026): "not found"
	// is written down with where it was looked for, the record can finish, and
	// the field is searched again later (daw_trailer_recheck_due). Five places,
	// one of them official (distributor, broadcaster or platform page, or its
	// YouTube channel marked "(resmi)"), and the date of the search.
	if ( in_array( $field, array( 'daw_trailer_youtube', 'daw_trailer_date' ), true ) ) {
		if ( $n >= DAW_TRAILER_NA_MIN && $official >= 1 && $dated ) {
			return '';
		}
		return sprintf( 'Fragman bulunamadı kaydı eksik: %d yer (en az %d), %d resmi (en az 1)%s. Biçim: "Resmi fragman bulunamadı. Bakılan (N): adres; adres (resmi); ... | gg.aa.yyyy"', $n, DAW_TRAILER_NA_MIN, $official, $dated ? '' : ', tarih yok' );
	}
	if ( $n >= DAW_NA_MIN_SOURCES && $official >= DAW_NA_MIN_OFFICIAL && $dated ) {
		return '';
	}
	$miss = array();
	if ( $n < DAW_NA_MIN_SOURCES ) {
		$miss[] = sprintf( '%d yer listelenmiş, en az %d', $n, DAW_NA_MIN_SOURCES );
	}
	if ( $official < DAW_NA_MIN_OFFICIAL ) {
		$miss[] = sprintf( '%d resmi sayfa, en az %d', $official, DAW_NA_MIN_OFFICIAL );
	}
	if ( ! $dated ) {
		$miss[] = 'tarih yok';
	}
	return 'N/A kanıtsız (madde 66): ' . implode( ', ', $miss ) . '. Biçim: "<sebep>. Bakılan (N): adres; adres (resmi); ... | gg.aa.yyyy". Eski sebep: ' . $r;
}

function daw_ae_tmdb_has( $post_id, $field, $filled = null ) {
	if ( ! function_exists( 'daw_tmdb_eps_counts' ) || ! in_array( $field, array( 'daw_ep_no', 'daw_ep_date', 'daw_ep_synopsis', 'daw_ep_title' ), true ) ) {
		return '';
	}
	$c = daw_tmdb_eps_counts( $post_id );
	if ( ! $c['eps'] ) {
		return '';
	}
	// TMDB's list itself can be wrong: one episode a week where the show ran
	// twice a week (Piano, 2001), or a summary copied from the episode before.
	// A person who checked it against the sources writes _daw_ok['tmdb_eps']
	// with the reason; TMDB then stops being owed and the normal source / N/A
	// rules decide (1.75.70).
	$okb = get_post_meta( $post_id, '_daw_ok', true );
	if ( is_array( $okb ) && ! empty( $okb['tmdb_eps'] ) ) {
		return '';
	}
	$have = null === $filled ? count( array_filter( array_map( 'strval', (array) get_post_meta( $post_id, $field, true ) ), 'strlen' ) ) : (int) $filled;
	$want = array(
		'daw_ep_no'       => $c['eps'],
		// Dates are owed only where TMDB has one: an unaired show lists 22
		// episodes with a date on the first only (1.75.70).
		'daw_ep_date'     => (int) ( $c['air'] ?? $c['eps'] ),
		'daw_ep_synopsis' => $c['overview'],
		'daw_ep_title'    => $c['title'],
	);
	$n = (int) $want[ $field ];
	if ( $n <= 0 || $have >= $n ) {
		return '';
	}
	// Titles: a show the broadcaster never titled (most Korean weekday and
	// weekend dramas) shows up on TMDB with placeholders and the odd stray
	// name. Titles are owed only when TMDB names at least half the episodes;
	// otherwise the page numbers them ("Episode 3") and the N/A stands.
	if ( 'daw_ep_title' === $field && $n * 2 < (int) $c['eps'] ) {
		return '';
	}
	// TMDB's Korean "name" is sometimes a line of dialogue from the episode,
	// not a title the broadcaster gave it (Entertainer). A person who checked
	// that writes _daw_ok['ep_titles'] with the reason, and the N/A stands.
	$okt = get_post_meta( $post_id, '_daw_ok', true );
	if ( 'daw_ep_title' === $field && is_array( $okt ) && ! empty( $okt['ep_titles'] ) ) {
		return '';
	}
	$what = array(
		'daw_ep_no'       => 'bölüm listesi',
		'daw_ep_date'     => 'bölüm tarihi',
		'daw_ep_synopsis' => 'bölüm özeti',
		'daw_ep_title'    => 'bölüm adı',
	);
	return sprintf( 'TMDB\'de %d %s var (_daw_tmdb_eps): kaynak mevcut, N/A kabul edilmez. Özet ve adlar kendi cümlemizle yazılır (madde 37, 64); tarih ikinci kaynakla doğrulanır (K4).', $n, $what[ $field ] );
}

/**
 * Folds the usual Korean romanization variants so one person written two ways
 * matches: Hwang Seung-gi (Wikipedia) and Hwang Seung-ki (TMDB, Amazon) are the
 * same credit. Voiced/unvoiced pairs (g/k, b/p, d/t, j/ch) and r/l collapse.
 */
/**
 * A Korean name in any of its romanisations, for comparing credits
 * (1.75.62): 안 is An or Ahn, 희 is Hee or Hui, 수 is Soo or Su. TMDB and our
 * sources pick differently ("An Eun-bin" / "Ahn Eun-bin", "Yang Hui-seung" /
 * "Yang Hee-seung"), and the crew check asked to add a writer who was
 * already credited. Applied to both sides, so it only widens matches.
 */
function daw_ae_kname_fold( $s ) {
	$s = strtolower( preg_replace( '/[^a-z]/i', '', (string) $s ) );
	$s = strtr( $s, array( 'ahn' => 'an', 'hui' => 'hi', 'hee' => 'hi', 'eui' => 'ui', 'oo' => 'u', 'eo' => 'o', 'ee' => 'i', 'wo' => 'o', 'ae' => 'e' ) );
	return daw_ae_roman_fold( $s );
}

function daw_ae_roman_fold( $s ) {
	$s = strtolower( preg_replace( '/[^a-z]/i', '', (string) $s ) );
	$s = str_replace( 'ch', 'j', $s );
	return strtr( $s, array( 'k' => 'g', 'p' => 'b', 't' => 'd', 'l' => 'r' ) );
}
