<?php
/**
 * Discover Asian World — the whole archive, in one number and three queues.
 *
 * WHY THIS EXISTS
 * ---------------
 * The audit that shipped first answered a narrower question than it looked
 * like it was answering. It scored six hundred and sixty two title records and
 * called that "the archive". It was not the archive. Three hundred and
 * thirty-six people, one group, thirty-five news stories and seventy-four
 * articles were outside it entirely — not failing, not passing, simply not
 * counted. A completion figure that quietly excludes four hundred and
 * forty-seven records is worse than no figure, because somebody will plan
 * around it.
 *
 * So this file adds the three things that were missing and then adds them up.
 *
 * ONE   Cast. A record can pass every content check and still have an empty
 *       cast section, because the cast lives in its own parallel arrays and
 *       nothing was looking at them. This counts them per record and sorts
 *       the gaps so they can be closed one at a time, which is the only way
 *       cast work ever gets done.
 *
 * TWO   People and groups as records in their own right. The existing people
 *       report scores the *titles* a person appears in, which is a different
 *       question from whether that person's own page is finished. Song Hye Kyo
 *       showing 0/34 says nothing about whether her biography exists.
 *
 * THREE Articles — news, culture, food, myth, travel, feature, quiz — with
 *       the SEO rules applied. These are the records the plugin gives the
 *       least help to: daw_generate_seo_title() returns an empty string for
 *       every guide type and daw_generate_seo_description() returns one for
 *       everything that is not a drama, film, variety show or person. So an
 *       article with no hand-written Rank Math meta has no meta this site
 *       chose at all, and nobody would know without looking.
 *
 * WHAT IT STILL CANNOT SEE
 * ------------------------
 * The same thing as before. A performer who has no record here is a name that
 * was never stored, and a query cannot find a row that does not exist. Cast
 * counting tells you a record has four billed names; it cannot tell you the
 * production had eleven. That gap closes by reading a source, one record at a
 * time, and the queues below are only the order to do it in.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================== cast === */

/**
 * How much of the cast work is done, per record and across the archive.
 *
 * The buckets are chosen so that each one names a different job:
 *
 *   empty         Nobody is linked. The cast section prints nothing. This is
 *                 the worst state and the fastest to improve.
 *   unbilled      People are linked but none are in a tier, so the page shows
 *                 a flat list with no billing and no character names.
 *   part_billed   Some are tiered and some are still in the flat list. Half a
 *                 job, and the half that is left is the awkward half.
 *   thin          Fully billed, but under four people. Plausible for a short
 *                 film; usually a record where somebody filed the leads and
 *                 stopped.
 *   billed        Four or more billed, nothing left flat.
 *
 * "thin" is a suspicion, not a finding, and it is reported as one. Four is not
 * a rule about television; it is the point below which a cast list is worth a
 * second look.
 *
 * @return array
 */
function daw_audit_cast_progress() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}

	$buckets = array(
		'empty'       => array(),
		'unbilled'    => array(),
		'part_billed' => array(),
		'thin'        => array(),
		'billed'      => array(),
	);

	$rows  = array();
	$links = 0;

	foreach ( daw_audit_record_ids( array( 'drama', 'film', 'variety' ) ) as $id ) {
		$state = function_exists( 'daw_cast_state' )
			? daw_cast_state( $id )
			: array( 'tiered' => 0, 'untiered' => 0 );

		$tiered   = (int) $state['tiered'];
		$untiered = (int) $state['untiered'];
		$total    = $tiered + $untiered;
		$links   += $total;

		if ( 0 === $total ) {
			$bucket = 'empty';
		} elseif ( 0 === $tiered ) {
			$bucket = 'unbilled';
		} elseif ( $untiered > 0 ) {
			$bucket = 'part_billed';
		} elseif ( $tiered < 4 ) {
			$bucket = 'thin';
		} else {
			$bucket = 'billed';
		}

		$buckets[ $bucket ][] = $id;

		$rows[ $id ] = array(
			'id'       => $id,
			'tiered'   => $tiered,
			'untiered' => $untiered,
			'bucket'   => $bucket,
		);
	}

	$counts = array();
	foreach ( $buckets as $k => $ids ) {
		$counts[ $k ] = count( $ids );
	}

	$memo = array(
		'counts'  => $counts,
		'buckets' => $buckets,
		'rows'    => $rows,
		'links'   => $links,
		'records' => count( $rows ),
	);

	return $memo;
}

/**
 * The cast queue: the records to fix next, worst first.
 *
 * Order is by how bad the state is, then by how much the record is worth
 * fixing. A drama nobody reads with no cast is still a page with no cast, but
 * a finished, well-filled record with an empty cast section is a page that
 * looks broken to somebody who is actually on it — so a higher content score
 * breaks the tie upwards.
 *
 * @param int $limit How many to return.
 * @return array
 */
function daw_audit_cast_queue( $limit = 40 ) {
	$rank = array(
		'empty'       => 0,
		'unbilled'    => 1,
		'part_billed' => 2,
		'thin'        => 3,
	);

	$progress = daw_audit_cast_progress();

	$out = array();
	foreach ( $progress['rows'] as $id => $row ) {
		if ( ! isset( $rank[ $row['bucket'] ] ) ) {
			continue;
		}

		$score = daw_audit_record_score( $id );

		$out[] = array(
			'id'       => $id,
			'title'    => get_the_title( $id ),
			'type'     => function_exists( 'daw_get_type' ) ? daw_get_type( $id ) : '',
			'tiered'   => $row['tiered'],
			'untiered' => $row['untiered'],
			'bucket'   => $row['bucket'],
			'score'    => (int) $score['score'],
			'rank'     => $rank[ $row['bucket'] ],
		);
	}

	usort(
		$out,
		static function ( $a, $b ) {
			if ( $a['rank'] !== $b['rank'] ) {
				return $a['rank'] <=> $b['rank'];
			}
			return $b['score'] <=> $a['score'];
		}
	);

	return array_slice( $out, 0, (int) $limit );
}

/* ========================================================== people === */

/**
 * How finished one person or group record is.
 *
 * Kept deliberately short, and to fields whose absence a reader would notice.
 * A person with no summary is a page with a photograph and a table; a person
 * with no date of birth is a page that cannot answer the question most people
 * arrived with.
 *
 * The filmography is deliberately not part of this. A person with no credits
 * on file may be a person whose credits are all works this archive does not
 * hold yet — a gap in the archive, not a fault in the record — and counting
 * them here would mean running daw_get_filmography() three hundred and
 * thirty-seven times to produce a number nobody scores. The credits question
 * already has its own report.
 *
 * @return array{score:int, missing:string[]}
 */
function daw_audit_person_score( $post_id ) {
	static $memo = array();
	if ( isset( $memo[ (int) $post_id ] ) ) {
		return $memo[ (int) $post_id ];
	}
	$memo[ (int) $post_id ] = daw_audit_person_score_raw( $post_id );

	return $memo[ (int) $post_id ];
}

function daw_audit_person_score_raw( $post_id ) {
	$type = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : 'person';
	$miss = array();

	if ( 'group' === $type ) {
		$checks = array(
			'daw_group_summary' => 'summary',
			'daw_native_name'   => 'native name',
			'daw_group_debut'   => 'debut',
		);
		$faq_key = '';
	} else {
		$checks = array(
			'daw_person_summary'    => 'summary',
			'daw_person_takeaways'  => 'takeaways',
			'daw_native_name'       => 'native name',
			'daw_born'              => 'date of birth',
		);
		$faq_key = 'daw_pfaq_q';
	}

	foreach ( $checks as $key => $label ) {
		$v = get_post_meta( $post_id, $key, true );
		if ( '' === $v || array() === $v || null === $v || '0' === (string) $v ) {
			$miss[] = $label;
		}
	}

	$total = count( $checks ) + 1; // the checks, plus the portrait.

	if ( $faq_key ) {
		$faq = array_filter( array_map( 'trim', array_map( 'strval', (array) rwmb_meta( $faq_key, array(), $post_id ) ) ) );
		if ( count( $faq ) < 5 ) {
			$miss[] = sprintf( 'FAQ %d/5', count( $faq ) );
		}
		$total++;
	}

	if ( ! ( function_exists( 'daw_has_record_image' ) ? daw_has_record_image( $post_id ) : has_post_thumbnail( $post_id ) ) ) {
		$miss[] = 'portrait';
	}

	return array(
		'score'   => max( 0, (int) round( ( ( $total - count( $miss ) ) / $total ) * 100 ) ),
		'missing' => $miss,
	);
}

/* ======================================================== articles === */

/** Every type that is an article rather than a record about a work or a person. */
function daw_audit_article_types() {
	$types = function_exists( 'daw_guide_types' )
		? daw_guide_types()
		: array( 'culture', 'food', 'myth', 'travel', 'feature', 'quiz' );

	$types[] = 'news';

	return array_values( array_unique( $types ) );
}

/**
 * The SEO state of one record, by what a search engine would actually receive.
 *
 * The distinction this makes is the whole point of it. A record can have an
 * empty rank_math_title and still be fine, because daw_generate_seo_title()
 * fills one in on output. A guide cannot: that function returns an empty
 * string for every guide type, so an empty field there really is an empty
 * field. Checking the stored meta alone would have flagged six hundred healthy
 * records and missed the hundred that are actually bare.
 *
 * Lengths are the ones Google truncates around rather than rules: roughly 60
 * characters of title and 160 of description get rendered. A description of
 * forty characters is not an error, but it is a wasted line.
 *
 * @return array{title:string, description:string, focus:string, missing:string[]}
 */
function daw_audit_seo_state( $post_id ) {
	$miss = array();

	$title        = trim( (string) get_post_meta( $post_id, 'rank_math_title', true ) );
	$title_source = '' !== $title ? 'manual' : 'none';

	if ( '' === $title && function_exists( 'daw_generate_seo_title' ) ) {
		$title = trim( (string) daw_generate_seo_title( $post_id ) );
		if ( '' !== $title ) {
			$title_source = 'auto';
		}
	}

	$desc        = trim( (string) get_post_meta( $post_id, 'rank_math_description', true ) );
	$desc_source = '' !== $desc ? 'manual' : 'none';

	if ( '' === $desc && function_exists( 'daw_generate_seo_description' ) ) {
		$desc = trim( (string) daw_generate_seo_description( $post_id ) );
		if ( '' !== $desc ) {
			$desc_source = 'auto';
		}
	}

	if ( '' === $desc ) {
		// Rank Math falls back to the excerpt before it falls back to trimming
		// the body. An excerpt is a real answer, but it is an answer to a
		// different question — it was written to sit under a headline on a
		// listing page, not to win a click in a search result — so it is
		// reported as a gap in its own right rather than as a description.
		$desc = trim( (string) get_post_field( 'post_excerpt', $post_id ) );
		if ( '' !== $desc ) {
			$desc_source = 'excerpt';
		}
	}

	$focus = trim( (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ) );

	// SEPARATE REASONS, SEPARATE LABELS
	// ---------------------------------
	// The first version said "SEO title" for a missing one and "SEO title 71
	// chars" for an overlong one, and the dashboard grouped them by stripping
	// the digits — so forty-three records arrived under one heading that could
	// mean either "write a title" or "shorten a title". Those are different
	// jobs and they now have different names.
	if ( 'none' === $title_source ) {
		$miss[] = 'SEO title missing';
	} elseif ( mb_strlen( $title ) > 60 ) {
		$miss[] = sprintf( 'SEO title too long (%d)', mb_strlen( $title ) );
	} elseif ( mb_strlen( $title ) < 25 ) {
		$miss[] = sprintf( 'SEO title too short (%d)', mb_strlen( $title ) );
	}

	if ( 'none' === $desc_source ) {
		$miss[] = 'meta description missing';
	} elseif ( 'excerpt' === $desc_source ) {
		$miss[] = 'meta description not written (excerpt used)';
	} elseif ( mb_strlen( $desc ) > 160 ) {
		$miss[] = sprintf( 'meta description too long (%d)', mb_strlen( $desc ) );
	} elseif ( mb_strlen( $desc ) < 70 ) {
		$miss[] = sprintf( 'meta description too short (%d)', mb_strlen( $desc ) );
	}

	if ( '' === $focus ) {
		$miss[] = 'focus keyword missing';
	}

	return array(
		'title'        => $title,
		'title_source' => $title_source,
		'description'  => $desc,
		'desc_source'  => $desc_source,
		'focus'        => $focus,
		'missing'      => $miss,
	);
}

/**
 * Does the body carry this thing, wherever it was typed?
 *
 * WHY THIS IS NOT A FIELD CHECK
 * -----------------------------
 * The first version of this function read daw_guide_standfirst,
 * daw_glance_label, daw_guide_source_name and daw_faq_q, and on the live
 * archive it reported that seventy-odd finished articles were thirty per cent
 * done. They were not. Laksa has its comparison table as an HTML <table> in
 * the body, its questions under a "Questions people ask" heading written as
 * bold paragraphs, and its standfirst as the opening paragraph and the
 * excerpt. Every one of those things is on the page. None of them is in the
 * field the check was reading.
 *
 * Two article shapes exist here — the older ones filed into Meta Box slots and
 * the newer ones written straight into the body — and a check that only knows
 * one of them produces a number that is worse than no number, because it sends
 * somebody to rewrite an article that was already finished.
 *
 * So each check asks whether the reader gets the thing, and accepts either
 * answer. The one check that stays strict is sources, because a link to what
 * was read is either there or it is not.
 */
function daw_audit_body_has( $body, $what ) {
	switch ( $what ) {
		case 'table':
			return (bool) preg_match( '#<table\b#i', $body );

		case 'questions':
			// Bold paragraph questions, the way the newer articles write them.
			return preg_match_all( '#<strong>[^<]{5,160}\?\s*</strong>#i', $body ) >= 3;

		case 'sources':
			// An outbound link to somewhere that is not this site. An internal
			// link to a hub page is navigation, not a source.
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( ! preg_match_all( '#href=["\']https?://([^/"\']+)#i', $body, $m ) ) {
				return false;
			}
			foreach ( $m[1] as $h ) {
				if ( $host && false === stripos( $h, (string) $host ) ) {
					return true;
				}
			}
			return false;
	}

	return false;
}

/**
 * How finished one article is, content and SEO scored separately.
 *
 * Separately because they fail for different reasons and get fixed by
 * different work. An article missing its sources needs somebody to go back to
 * what they read; an article missing a focus keyword needs ninety seconds.
 * Folding both into one number hides which of those a record needs.
 *
 * @return array
 */
function daw_audit_article_score( $post_id ) {
	$type  = function_exists( 'daw_get_type' ) ? daw_get_type( $post_id ) : '';
	$post  = get_post( $post_id );
	$body  = $post ? (string) $post->post_content : '';
	$words = str_word_count( wp_strip_all_tags( strip_shortcodes( $body ) ) );

	$excerpt = trim( (string) get_post_field( 'post_excerpt', $post_id ) );

	$rows = static function ( $key ) use ( $post_id ) {
		$v = function_exists( 'daw_field_raw' ) ? daw_field_raw( $post_id, $key ) : get_post_meta( $post_id, $key, true );
		return count( array_filter( array_map( 'trim', array_map( 'strval', (array) $v ) ) ) );
	};

	$val = static function ( $key ) use ( $post_id ) {
		$v = function_exists( 'daw_field_raw' ) ? daw_field_raw( $post_id, $key ) : get_post_meta( $post_id, $key, true );
		return trim( (string) ( is_array( $v ) ? reset( $v ) : $v ) );
	};

	$miss  = array();
	$total = 0;

	$check = static function ( $ok, $label ) use ( &$miss, &$total ) {
		$total++;
		if ( ! $ok ) {
			$miss[] = $label;
		}
	};

	if ( 'news' === $type ) {
		$check( '' !== $val( 'daw_news_standfirst' ) || '' !== $excerpt, 'standfirst' );
		$check( $rows( 'daw_news_source_url' ) >= 1 || daw_audit_body_has( $body, 'sources' ), 'link to the original report' );
		$check( '' !== $val( 'daw_news_kicker' ), 'kicker' );
		// A news story that says nothing about what is and is not established
		// is the kind of page that ages into a rumour with our name on it.
		$check( '' !== $val( 'daw_news_confirmed' ), 'what is confirmed' );
		$check( $words >= 150, sprintf( 'body %d words', $words ) );
	} else {
		$check( '' !== $val( 'daw_guide_standfirst' ) || '' !== $excerpt, 'standfirst' );
		$check(
			$rows( 'daw_guide_source_url' ) >= 1 || $rows( 'daw_guide_source_name' ) >= 1 || daw_audit_body_has( $body, 'sources' ),
			'sources'
		);
		$check( $rows( 'daw_glance_label' ) >= 3 || daw_audit_body_has( $body, 'table' ), 'fact table' );
		$check( $rows( 'daw_faq_q' ) >= 3 || daw_audit_body_has( $body, 'questions' ), 'questions' );
		$check( $words >= 300, sprintf( 'body %d words', $words ) );
	}

	$check( has_post_thumbnail( $post_id ), 'image' );
	// An article still in a page builder cannot be edited anywhere else, and
	// its markup is not the markup the rest of the site prints.
	$check( '' === (string) get_post_meta( $post_id, '_elementor_edit_mode', true ), 'still in Elementor' );
	// The theme prints the title as the H1, so a second one inside the body is
	// two H1s on the page and a sure sign the record was imported, not written.
	$check( ! preg_match( '#<h1\b#i', $body ), 'second H1 in the body' );

	$seo = daw_audit_seo_state( $post_id );

	return array(
		'id'          => (int) $post_id,
		'type'        => $type,
		'title'       => get_the_title( $post_id ),
		'words'       => $words,
		'score'       => max( 0, (int) round( ( ( $total - count( $miss ) ) / max( 1, $total ) ) * 100 ) ),
		'missing'     => $miss,
		'seo_score'   => max( 0, (int) round( ( ( 3 - min( 3, count( $seo['missing'] ) ) ) / 3 ) * 100 ) ),
		'seo_missing' => $seo['missing'],
	);
}

/**
 * Every article, scored, worst first.
 *
 * @return array
 */
function daw_audit_articles() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}

	$out = array();

	foreach ( daw_audit_record_ids( daw_audit_article_types() ) as $id ) {
		$out[] = daw_audit_article_score( $id );
	}

	usort(
		$out,
		static function ( $a, $b ) {
			$sa = $a['score'] + $a['seo_score'];
			$sb = $b['score'] + $b['seo_score'];
			return $sa <=> $sb;
		}
	);

	$memo = $out;

	return $memo;
}

/* =========================================== the one total figure === */

/**
 * How much of the whole archive is finished.
 *
 * Three groups, because they are three different jobs with three different
 * definitions of done, and one total, because that is the question that gets
 * asked. The total is a plain record count: every published record of every
 * type, and how many of them pass their own group's checks. No weighting. A
 * news story is not worth a third of a drama, and pretending to know the
 * exchange rate would only make the number harder to trust.
 *
 * Cast is reported beside the total rather than inside it. A record with a
 * complete synopsis and an empty cast section is genuinely finished on the
 * measure this scores and genuinely unfinished to a reader, and folding those
 * two facts into one percentage would lose both.
 *
 * @return array
 */
function daw_audit_archive_total() {
	$titles = daw_audit_completion();

	$people = array( 'total' => 0, 'complete' => 0, 'missing' => array() );
	foreach ( daw_audit_record_ids( array( 'person', 'group' ) ) as $id ) {
		$people['total']++;
		$state = daw_audit_person_score( $id );

		if ( 100 === $state['score'] ) {
			$people['complete']++;
			continue;
		}

		foreach ( $state['missing'] as $m ) {
			$k                      = 0 === strpos( $m, 'FAQ' ) ? 'FAQ under 5' : $m;
			$people['missing'][ $k ] = ( $people['missing'][ $k ] ?? 0 ) + 1;
		}
	}
	$people['partial'] = $people['total'] - $people['complete'];
	arsort( $people['missing'] );

	$articles = array(
		'total'       => 0,
		'complete'    => 0,
		'seo_clean'   => 0,
		'missing'     => array(),
		'seo_missing' => array(),
	);

	foreach ( daw_audit_articles() as $a ) {
		$articles['total']++;

		if ( 100 === $a['score'] ) {
			$articles['complete']++;
		} else {
			foreach ( $a['missing'] as $m ) {
				$k                         = trim( (string) preg_replace( '/\s+\d.*$/', '', $m ) );
				$k                         = '' === $k ? $m : $k;
				$articles['missing'][ $k ] = ( $articles['missing'][ $k ] ?? 0 ) + 1;
			}
		}

		if ( ! $a['seo_missing'] ) {
			$articles['seo_clean']++;
		} else {
			foreach ( $a['seo_missing'] as $m ) {
				// Only the bracketed number comes off. The reason itself is
				// already the label it should be grouped under.
				$k                             = trim( (string) preg_replace( '/\s*\([^)]*\)\s*$/', '', $m ) );
				$k                             = '' === $k ? $m : $k;
				$articles['seo_missing'][ $k ] = ( $articles['seo_missing'][ $k ] ?? 0 ) + 1;
			}
		}
	}
	$articles['partial'] = $articles['total'] - $articles['complete'];
	arsort( $articles['missing'] );
	arsort( $articles['seo_missing'] );

	$cast = daw_audit_cast_progress();

	$total    = (int) $titles['total'] + (int) $people['total'] + (int) $articles['total'];
	$complete = (int) $titles['complete'] + (int) $people['complete'] + (int) $articles['complete'];

	// The stamp, not the shape, is what "done" means for going live. The shape
	// figure stays because it says where the obvious gaps are; the verified
	// figure is the one that says how much of the archive somebody has checked.
	$verified = function_exists( 'daw_verify_tally' ) ? daw_verify_tally() : array( 'verified' => 0, 'groups' => array() );

	return array(
		'total'    => $total,
		'complete' => $complete,
		'partial'  => $total - $complete,
		'percent'  => $total ? (int) round( $complete / $total * 100 ) : 0,
		'verified' => array(
			'count'   => (int) $verified['verified'],
			'percent' => $total ? round( $verified['verified'] / $total * 100, 1 ) : 0,
			'groups'  => $verified['groups'],
			'stale'   => count( (array) ( $verified['stale'] ?? array() ) ),
			'open'    => count( (array) ( $verified['open'] ?? array() ) ),
		),
		'groups'   => array(
			'titles'   => $titles,
			'people'   => $people,
			'articles' => $articles,
		),
		'cast'     => array(
			'records' => $cast['records'],
			'links'   => $cast['links'],
			'counts'  => $cast['counts'],
		),
	);
}

/* ============================================================ screen === */

add_action( 'admin_menu', 'daw_audit_wide_menu' );
function daw_audit_wide_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: Whole archive', 'daw' ),
		__( 'DAW: Whole archive', 'daw' ),
		'manage_options',
		'daw-archive',
		'daw_audit_wide_page'
	);
}

/** One group's line: done, outstanding, and what the outstanding ones lack. */
function daw_audit_wide_group( $label, array $g, $note = '' ) {
	$pct = $g['total'] ? (int) round( $g['complete'] / $g['total'] * 100 ) : 0;

	printf(
		'<h3 style="margin-bottom:.2em">%s — <strong>%s</strong> of <strong>%s</strong> done (%d%%)</h3>',
		esc_html( $label ),
		esc_html( number_format_i18n( $g['complete'] ) ),
		esc_html( number_format_i18n( $g['total'] ) ),
		$pct
	);

	if ( $note ) {
		echo '<p style="max-width:52em;color:#555;margin:.2em 0 .6em">' . esc_html( $note ) . '</p>';
	}

	printf(
		'<div style="background:#e5e5e5;height:12px;border-radius:6px;overflow:hidden;max-width:40em"><div style="width:%d%%;height:12px;background:%s"></div></div>',
		$pct,
		$pct >= 90 ? '#2a7' : ( $pct >= 40 ? '#e8a33d' : '#d35' )
	);

	if ( ! empty( $g['missing'] ) ) {
		echo '<p style="margin:.6em 0 1.4em;font-size:13px;color:#555">Most common gaps: ';
		$bits = array();
		foreach ( array_slice( $g['missing'], 0, 6, true ) as $k => $n ) {
			$bits[] = sprintf( '%s (%s)', esc_html( $k ), esc_html( number_format_i18n( $n ) ) );
		}
		echo implode( ' &middot; ', $bits ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo '<p style="margin:.6em 0 1.4em"></p>';
	}
}

function daw_audit_wide_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$t = daw_audit_archive_total();

	echo '<div class="wrap"><h1>The whole archive</h1>';

	// This screen counts live. The dashboard outside wp-admin reads the stored
	// snapshot, so the two can disagree until it is rebuilt — and after a
	// plugin update the difference is the whole point of the update.
	if ( function_exists( 'daw_snapshot_button' ) ) {
		daw_snapshot_button();
	}

	printf(
		'<p style="font-size:30px;margin:.3em 0 0"><strong>%s</strong> of <strong>%s</strong> records finished — <strong>%d%%</strong></p>',
		esc_html( number_format_i18n( $t['complete'] ) ),
		esc_html( number_format_i18n( $t['total'] ) ),
		(int) $t['percent']
	);
	echo '<p style="max-width:54em;color:#555">Finished means the record has the shape of a finished one: the fields its type cannot do without are filled and nothing on it carries the residue of the automated pass. It is not proof that anybody checked the facts. A low score is certain; a high score only means nothing has been found.</p>';

	echo '<h2>The three jobs</h2>';

	daw_audit_wide_group(
		'Titles — dramas, films, variety',
		$t['groups']['titles'],
		'Ten FAQ answers, a synopsis, a why-it-is-watched, takeaways, a native title, and no invented panel text.'
	);

	daw_audit_wide_group(
		'People and groups',
		$t['groups']['people'],
		'A summary, takeaways, a native name, a date of birth, five FAQ answers and a portrait. Their filmographies are a separate report.'
	);

	$a = $t['groups']['articles'];
	daw_audit_wide_group(
		'Articles — news, culture, food, myth, travel, feature, quiz',
		$a,
		'A standfirst, sources, a fact table, questions, enough body text, an image, out of the page builder, and only one H1.'
	);

	printf(
		'<p style="margin:-1em 0 1.4em"><strong>%s</strong> of <strong>%s</strong> articles pass the SEO rules.</p>',
		esc_html( number_format_i18n( $a['seo_clean'] ) ),
		esc_html( number_format_i18n( $a['total'] ) )
	);
	if ( ! empty( $a['seo_missing'] ) ) {
		echo '<p style="font-size:13px;color:#555;margin:-1.2em 0 1.4em">';
		$bits = array();
		foreach ( array_slice( $a['seo_missing'], 0, 6, true ) as $k => $n ) {
			$bits[] = sprintf( '%s (%s)', esc_html( $k ), esc_html( number_format_i18n( $n ) ) );
		}
		echo implode( ' &middot; ', $bits ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	echo '<p style="max-width:54em;color:#555">Worth knowing why the article numbers look the way they do: this plugin writes a search title and description for dramas, films, variety shows and people, and writes nothing at all for guide types. So an article with an empty SEO title field really is bare, where a drama with the same empty field is not.</p>';

	/* ---------------------------------------------------------- cast --- */

	echo '<h2>Cast</h2>';
	$c = $t['cast'];
	printf(
		'<p style="max-width:54em"><strong>%s</strong> people are linked across <strong>%s</strong> title records.</p>',
		esc_html( number_format_i18n( $c['links'] ) ),
		esc_html( number_format_i18n( $c['records'] ) )
	);

	$labels = array(
		'empty'       => 'Nobody linked — the cast section prints nothing',
		'unbilled'    => 'Linked but none billed — a flat list, no tiers, no characters',
		'part_billed' => 'Half billed — some in tiers, some still flat',
		'thin'        => 'Billed but under four people — probably not the whole cast',
		'billed'      => 'Billed, four or more, nothing left flat',
	);

	echo '<table class="widefat striped" style="max-width:56em"><tbody>';
	foreach ( $labels as $k => $label ) {
		printf(
			'<tr><td style="width:6em"><strong>%s</strong></td><td>%s</td></tr>',
			esc_html( number_format_i18n( $c['counts'][ $k ] ?? 0 ) ),
			esc_html( $label )
		);
	}
	echo '</tbody></table>';

	echo '<p style="max-width:54em;color:#555">Under four is a suspicion, not a finding. It is the point below which a cast list is worth opening, not a rule about how many people a production has.</p>';

	echo '<h3>Cast queue — worst first</h3>';
	echo '<p style="max-width:54em;color:#555">Empty first, then unbilled, then half-done. Within each, the records that are otherwise most finished come first, because an empty cast section on a complete page is the one a reader actually notices.</p>';

	echo '<table class="widefat striped"><thead><tr><th>Record</th><th style="width:8em">Billed</th><th style="width:8em">Unbilled</th><th style="width:8em">Record score</th><th>State</th></tr></thead><tbody>';
	foreach ( daw_audit_cast_queue( 60 ) as $row ) {
		printf(
			'<tr><td><a href="%s"><strong>%s</strong></a> <span style="color:#999">%s</span></td><td>%d</td><td>%d</td><td>%d%%</td><td style="color:#666">%s</td></tr>',
			esc_url( get_edit_post_link( $row['id'] ) ),
			esc_html( $row['title'] ),
			esc_html( $row['type'] ),
			(int) $row['tiered'],
			(int) $row['untiered'],
			(int) $row['score'],
			esc_html( $labels[ $row['bucket'] ] ?? $row['bucket'] )
		);
	}
	echo '</tbody></table>';

	/* -------------------------------------------- cast, by person --- */

	echo '<h3>Cast still outstanding, by person</h3>';
	echo '<p style="max-width:54em;color:#555">The same records, asked the other way round. People whose written content is finished come first, because a page with a synopsis, a FAQ and one name under Cast is the most visible unfinished thing on the site — a reader who gets as far as the cast section is a reader who was enjoying it.</p>';

	echo '<table class="widefat striped"><thead><tr><th>Person</th><th style="width:7em">Cast done</th><th style="width:7em">Content done</th><th>Records whose cast is still short</th></tr></thead><tbody>';

	foreach ( daw_audit_cast_people_queue( 40 ) as $p ) {
		$bits = array();
		foreach ( array_slice( $p['weak'], 0, 8 ) as $w ) {
			$bits[] = sprintf(
				'<a href="%s">%s</a> <span style="color:#999">%d billed%s</span>',
				esc_url( get_edit_post_link( $w['id'] ) ),
				esc_html( $w['title'] ),
				(int) $w['tiered'],
				$w['untiered'] ? sprintf( ', %d flat', (int) $w['untiered'] ) : ''
			);
		}
		if ( count( $p['weak'] ) > 8 ) {
			$bits[] = sprintf( '<span style="color:#999">and %d more</span>', count( $p['weak'] ) - 8 );
		}

		printf(
			'<tr><td><a href="%s"><strong>%s</strong></a></td><td>%d / %d</td><td>%d / %d</td><td style="font-size:12px;line-height:1.7">%s</td></tr>',
			esc_url( get_edit_post_link( $p['id'] ) ),
			esc_html( $p['name'] ),
			(int) $p['done'],
			(int) $p['titles'],
			(int) $p['content'],
			(int) $p['titles'],
			implode( ' &middot; ', $bits ) // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}

	echo '</tbody></table>';

	/* ------------------------------------------------------ articles --- */

	echo '<h2>Articles, worst first</h2>';
	echo '<table class="widefat striped"><thead><tr><th>Article</th><th style="width:6em">Type</th><th style="width:6em">Words</th><th style="width:6em">Content</th><th>Missing</th><th style="width:5em">SEO</th><th>SEO gaps</th></tr></thead><tbody>';

	foreach ( daw_audit_articles() as $a2 ) {
		printf(
			'<tr><td><a href="%s"><strong>%s</strong></a></td><td>%s</td><td>%s</td><td>%d%%</td><td style="font-size:12px;color:#666">%s</td><td>%d%%</td><td style="font-size:12px;color:#666">%s</td></tr>',
			esc_url( get_edit_post_link( $a2['id'] ) ),
			esc_html( $a2['title'] ),
			esc_html( $a2['type'] ),
			esc_html( number_format_i18n( $a2['words'] ) ),
			(int) $a2['score'],
			esc_html( implode( ', ', $a2['missing'] ) ),
			(int) $a2['seo_score'],
			esc_html( implode( ', ', $a2['seo_missing'] ) )
		);
	}

	echo '</tbody></table>';

	echo '<h2>What none of this can see</h2>';
	echo '<p style="max-width:54em">A performer with no record here. Counting the cast tells you a record has four billed names; it cannot tell you the production had eleven, because the seven missing ones are names that were never stored and a query cannot find a row that does not exist. The named gaps found by reading are written down in <code>research/kunye-denetim.md</code>, and they close one record at a time.</p>';

	echo '</div>';
}


/* ================================== whose cast is still outstanding === */

/**
 * The cast state of every person's filmography, person by person.
 *
 * WHY THIS EXISTS SEPARATELY
 * --------------------------
 * The archive-wide cast queue is sorted by how bad a record is, which is right
 * for working through the archive and useless for working through a person.
 * Thirty records were finished by hand for one actor; every one of them came
 * out with one or two billed names, because the writing and the casting are
 * different jobs done on different days. Those thirty records are in the
 * "thin" bucket, five hundred and sixty seven worse records sit above them,
 * and the person who just spent a week on that actor cannot find them.
 *
 * So this asks the question the other way round: for each person, how do the
 * titles they appear in stand, and which of them still need a cast.
 *
 * THE ORDER
 * ---------
 * Content-finished people first. A record whose synopsis, FAQ and takeaways
 * are written and whose cast section holds one name is the most visible
 * unfinished thing on the site — a reader who stays long enough to reach the
 * cast is a reader who was enjoying the page. Behind that, more outstanding
 * titles ranks higher.
 *
 * @return array
 */
function daw_audit_cast_people_queue( $limit = 25 ) {
	$progress = daw_audit_cast_progress();
	$rows     = $progress['rows'];

	$people = array();
	foreach ( daw_audit_record_ids( array( 'person' ) ) as $pid ) {
		$people[ $pid ] = array(
			'id'      => $pid,
			'name'    => get_the_title( $pid ),
			'titles'  => 0,
			'done'    => 0, // titles whose cast is properly billed
			'content' => 0, // titles whose content is complete
			'weak'    => array(),
		);
	}

	foreach ( daw_audit_record_ids( array( 'drama', 'film', 'variety' ) ) as $tid ) {
		$idx = (string) get_post_meta( $tid, '_daw_cast_idx', true );
		if ( '' === $idx ) {
			continue;
		}

		$cast    = $rows[ $tid ] ?? array( 'tiered' => 0, 'untiered' => 0, 'bucket' => 'empty' );
		$content = daw_audit_record_score( $tid );

		foreach ( explode( ',', trim( $idx, ',' ) ) as $chunk ) {
			$pid = (int) strtok( $chunk, ':' );
			if ( ! $pid || ! isset( $people[ $pid ] ) ) {
				continue;
			}

			$people[ $pid ]['titles']++;

			if ( 100 === (int) $content['score'] ) {
				$people[ $pid ]['content']++;
			}

			if ( 'billed' === $cast['bucket'] ) {
				$people[ $pid ]['done']++;
			} else {
				$people[ $pid ]['weak'][] = array(
					'id'       => $tid,
					'title'    => get_the_title( $tid ),
					'tiered'   => (int) $cast['tiered'],
					'untiered' => (int) $cast['untiered'],
					'state'    => (string) $cast['bucket'],
					'score'    => (int) $content['score'],
				);
			}
		}
	}

	$people = array_filter( $people, static fn( $p ) => ! empty( $p['weak'] ) );

	uasort(
		$people,
		static function ( $a, $b ) {
			$ca = $a['titles'] ? $a['content'] / $a['titles'] : 0;
			$cb = $b['titles'] ? $b['content'] / $b['titles'] : 0;

			if ( $ca !== $cb ) {
				return $cb <=> $ca; // content finished first
			}

			return count( $b['weak'] ) <=> count( $a['weak'] );
		}
	);

	// The worst-cast title first inside each person, so the row opens on the
	// one worth doing rather than on whichever happened to be indexed first.
	$rank = array( 'empty' => 0, 'unbilled' => 1, 'part_billed' => 2, 'thin' => 3 );
	foreach ( $people as $pid => $p ) {
		usort(
			$p['weak'],
			static function ( $a, $b ) use ( $rank ) {
				$ra = $rank[ $a['state'] ] ?? 9;
				$rb = $rank[ $b['state'] ] ?? 9;
				if ( $ra !== $rb ) {
					return $ra <=> $rb;
				}
				return $a['tiered'] <=> $b['tiered'];
			}
		);
		$people[ $pid ] = $p;
	}

	return array_slice( $people, 0, (int) $limit, true );
}
