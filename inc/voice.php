<?php
/**
 * Site voice and originality checks, madde 75-81 (Umut, 27 Oct 2026).
 *
 * The owner asked for one more control layer inside "Temel bitti": every text
 * on a record is tested and a failing line keeps the record out of Temel
 * bitti until the text is rewritten.
 *
 *   75 Klişe kelimeler       stock review clichés (rollercoaster, must-see)
 *   76 AI kelimeleri         vocabulary language models overuse (delve, showcase)
 *   77 AI kalıpları          sentence shapes (not only ... but also, "Moreover,")
 *   78 Şablon cümleler       the same sentence frame on many records
 *   79 Ansiklopedik dil      Wikipedia-lead phrasing ("(Korean: ...)", "[3]")
 *   80 AI izi / filigran     invisible characters, chat leftovers, markdown
 *   81 Site sesi             the DAW voice: rhythm, openings, editor's view
 *
 * Words inside double quotes are a critic's or a person's own words and are
 * not held against us for 75-77.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

/** Madde 75: review clichés. */
function daw_voice_cliches() {
	return array(
		'rollercoaster', 'roller coaster of emotions', 'emotional roller coaster', 'breathtaking', 'must-see', 'must-watch',
		'edge of their seats', 'edge of your seat', 'edge-of-your-seat', 'a feast for the eyes', 'tugs at the heartstrings',
		'heart-wrenching', 'heartwrenching', 'something for everyone', 'keeps viewers hooked', 'leaves viewers', 'will leave you',
		'a true gem', 'unforgettable', 'masterful', 'masterpiece of', 'a masterclass in', 'binge-worthy', 'bingeworthy',
		'leaves a lasting impression', 'look no further', 'takes viewers on', 'viewers are treated', 'fans of the genre will',
		'a journey of', 'beloved by fans', 'stole the hearts', 'steals the show', 'took the world by storm', 'won the hearts',
		'a must for', 'cult classic status', 'needs no introduction', 'a breath of fresh air', 'pulls at the heartstrings',
		'will keep you guessing', 'jaw-dropping', 'spine-chilling', 'heartwarming tale', 'a perfect blend of',
	);
}

/** Madde 76: words models reach for. */
function daw_voice_ai_vocab() {
	return array(
		'delve', 'tapestry', 'testament to', 'a testament', 'showcases', 'showcasing', 'showcased', 'pivotal role', 'plays a pivotal',
		'nuanced portrayal', 'seamless', 'seamlessly', 'vibrant', 'boasts', 'nestled', 'cemented', 'cements his', 'cements her',
		'solidified', 'solidifying', 'captivating', 'captivated audiences', 'resonates with', 'resonated with', 'multifaceted',
		'underscores', 'underscoring', 'intricate', 'intricately', 'in the realm of', 'embark on', 'embarks on', 'navigate the complexities',
		'navigating the complexities', 'foster', 'fostering', 'elevate', 'elevates', 'unlock the', 'game-changer', 'stands as a',
		'serves as a reminder', 'serves as a testament', 'in today\'s fast-paced world', 'it\'s worth noting', 'it is worth noting',
		'it\'s important to note', 'it is important to note', 'rich history', 'at its core', 'in the world of', 'a blend of',
		'undoubtedly', 'nowadays', 'dive into', 'dives into', 'deep dive', 'meticulous', 'meticulously', 'compelling narrative',
		'profound', 'enigmatic', 'labyrinthine', 'ever-evolving', 'landscape of', 'a beacon of', 'harrowing journey',
	);
}

/** Madde 77: sentence shapes. Pattern => name. */
function daw_voice_shapes() {
	return array(
		'/\bnot only\b[^.!?]{1,90}\bbut also\b/i'                                        => 'not only ... but also',
		'/\bwhether you(\'re| are)\b/i'                                                   => 'whether you are ...',
		'/\b(isn\'t|is not|wasn\'t|was not) (just|only|merely)\b[^.!?]{1,70}[,;]\s*(it\'s|it is|it was)\b/i' => 'is not just X, it is Y',
		'/\bmore than (just )?an? (drama|series|film|movie|show|story|romance|thriller)\b/i' => 'more than just a drama',
		'/(^|[.!?]\s+)(Moreover|Furthermore|Additionally|In addition|In conclusion|In summary|To sum up|In short|Overall|Ultimately|All in all|Simply put|Needless to say),/'
			=> 'bağlaç açılışı (Moreover, Overall, In short ...)',
		'/(^|[.!?]\s+)(It\'s no wonder|No wonder|It is no surprise|Here\'s the thing|Here is the thing|Let\'s be honest|The best part\?|The result\?|The verdict\?)/i'
			=> 'sohbet kalıbı (It\'s no wonder, The result? ...)',
		'/^\s*(Ever wondered|Have you ever|Looking for|Are you|Want to)\b[^?]{0,120}\?/i'  => 'soru ile açılış',
		'/\bFrom [^,.]{3,40} to [^,.]{3,40}, (this|the|it|he|she|they)\b/'                => 'From X to Y, ... kalıbı',
		'/\b(a|an) (story|tale|journey) (of|about) (love|loss|hope|friendship|redemption|self-discovery), (love|loss|hope|friendship|redemption|self-discovery),? and (love|loss|hope|friendship|redemption|self-discovery)\b/i'
			=> 'üçlü soyut liste (love, loss and hope)',
	);
}

/** Madde 78: frames that are templates by themselves. */
function daw_voice_fixed_templates() {
	return array(
		'everything you need to know', 'here is everything', 'here\'s everything', 'in this guide', 'in this article',
		'read on to', 'keep reading', 'let\'s take a look', 'without further ado', 'if you enjoyed', 'if you liked this',
		'is a must for fans of', 'is perfect for fans of', 'the perfect choice for', 'is sure to',
	);
}

/** Madde 79: Wikipedia-lead phrasing. Pattern => name. */
function daw_voice_encyclopedic() {
	return array(
		'/\[\s*\d{1,3}\s*\]|\[citation needed\]|\[note \d+\]/i'                                  => 'kaynakça işareti [3]',
		'/\((Korean|Japanese|Chinese|Thai|Hangul|Hanja|Kanji|RR|MR|lit\.|romanized)\s*:/i'      => 'Wikipedia giriş parantezi (Korean: ...)',
		'/\bRR:\s|\bMR:\s|\blit\.\s/'                                                            => 'romanizasyon notu (RR:, lit.)',
		'/\bis an? (\d{4} )?(South Korean|Korean|Japanese|Chinese|Taiwanese|Thai|Filipino) (television series|television drama|TV series|streaming television series|web series|drama series|film|romantic comedy television series)\b/i'
			=> 'Wikipedia giriş cümlesi (is a 2024 South Korean television series)',
		'/\bknown professionally as\b|\bbetter known by (his|her|their) stage name\b/i'         => 'Wikipedia kişi girişi (known professionally as)',
		'/\bbest known for (his|her|their) roles? in\b/i'                                       => 'Wikipedia kişi girişi (best known for her roles in)',
		'/\bThe series stars\b|\bThe film stars\b|\bIt stars\b/'                                 => 'Wikipedia kadro cümlesi (The series stars ...)',
		'/\bIt (aired|premiered) on [^.]{2,40} from [^.]{3,30} to [^.]{3,30}\b/i'                => 'Wikipedia yayın cümlesi (It aired on X from ... to ...)',
		'/\baccording to (Nielsen|Wikipedia|AsianWiki|MyDramaList|IMDb|TMDB|Soompi|Naver)\b/i'   => 'kaynak adıyla bilgi (according to X)',
		'/\b(was|were) reported by\b|\breportedly\b/i'                                          => 'haber dili (reportedly, was reported by)',
	);
}

/** Madde 80: invisible characters and machine leftovers. Pattern => name. */
function daw_voice_watermarks() {
	return array(
		'/[\x{200B}-\x{200F}\x{2060}-\x{2064}\x{FEFF}\x{00AD}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u' => 'görünmez karakter (zero-width, yön işareti, yumuşak tire)',
		'/[\x{E0000}-\x{E007F}]/u'                                                                => 'görünmez etiket karakteri (Unicode tag)',
		'/[\x{FE00}-\x{FE0F}]/u'                                                                  => 'görünmez varyasyon seçici',
		'/[\x{202F}\x{2007}\x{2009}\x{200A}\x{3000}]/u'                                          => 'alışılmadık boşluk karakteri',
		'/\b(as an ai|as a language model|i hope this helps|certainly!|great question|knowledge cutoff|as of my last update|i cannot browse)\b/i'
			=> 'sohbet artığı (As an AI, I hope this helps ...)',
		'/oaicite|contentReference|turn\d+search\d+|utm_source=(chatgpt|openai|perplexity|copilot)|【\d+†/i' => 'AI aracı izi (oaicite, utm_source=chatgpt)',
		'/(\*\*[^*\n]{2,60}\*\*|^#{1,4}\s|\n#{1,4}\s|^\s*[-*]\s+\*\*)/m'                         => 'markdown artığı (**kalın**, ## başlık)',
	);
}

/** Text with quoted words removed: a quote is somebody else's language. */
function daw_voice_unquote( $s ) {
	$s = preg_replace( '/"[^"\n]{1,300}"/u', ' ', $s );
	return (string) preg_replace( '/[\x{201C}][^\x{201D}\n]{1,300}[\x{201D}]/u', ' ', (string) $s );
}

/** Mechanical part of madde 80: strip invisible characters. */
function daw_strip_watermarks( $s ) {
	if ( ! is_string( $s ) || '' === $s ) {
		return $s;
	}
	$s = preg_replace( '/[\x{200B}-\x{200F}\x{2060}-\x{2064}\x{FEFF}\x{00AD}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{E0000}-\x{E007F}\x{FE00}-\x{FE0F}]/u', '', $s );
	return (string) preg_replace( '/[\x{202F}\x{2007}\x{2009}\x{200A}\x{3000}]/u', ' ', (string) $s );
}

/**
 * Per-text issues for madde 75, 76, 77, 79, 80 and the fixed part of 78.
 *
 * @return array List of [madde, note].
 */
function daw_voice_issues( $s ) {
	$s = (string) $s;
	if ( '' === trim( $s ) || preg_match( '#^https?://\S+$#i', trim( $s ) ) ) {
		return array();
	}
	$out   = array();
	$plain = html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$own   = daw_voice_unquote( $plain );
	$low   = ' ' . strtolower( $own ) . ' ';
	foreach ( daw_voice_cliches() as $w ) {
		if ( preg_match( '/(?<![a-z])' . preg_quote( $w, '/' ) . '(?![a-z])/', $low ) ) {
			$out[] = array( '75', 'klişe: "' . $w . '"' );
		}
	}
	foreach ( daw_voice_ai_vocab() as $w ) {
		if ( preg_match( '/(?<![a-z])' . preg_quote( $w, '/' ) . '(?![a-z])/', $low ) ) {
			$out[] = array( '76', 'AI kelimesi: "' . $w . '"' );
		}
	}
	foreach ( daw_voice_shapes() as $re => $name ) {
		if ( preg_match( $re, $own ) ) {
			$out[] = array( '77', 'AI kalıbı: ' . $name );
		}
	}
	foreach ( daw_voice_fixed_templates() as $w ) {
		if ( false !== strpos( $low, $w ) ) {
			$out[] = array( '78', 'şablon ifade: "' . $w . '"' );
		}
	}
	foreach ( daw_voice_encyclopedic() as $re => $name ) {
		if ( preg_match( $re, $plain ) ) {
			$out[] = array( '79', 'ansiklopedik dil: ' . $name );
		}
	}
	foreach ( daw_voice_watermarks() as $re => $name ) {
		if ( preg_match( $re, $s ) ) {
			$out[] = array( '80', 'AI izi: ' . $name );
		}
	}
	return $out;
}

/** Sentences of a plain text. */
function daw_voice_sentences( $t ) {
	$t = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $t ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	if ( '' === $t ) {
		return array();
	}
	// Do not split after common abbreviations and initials.
	$t = preg_replace( '/\b(Mr|Mrs|Ms|Dr|St|vs|No|Vol|Ep|Jr|Sr|U\.S|U\.K)\./', '$1<dot>', $t );
	$t = preg_replace( '/\b([A-Z])\./', '$1<dot>', $t );
	$out = array();
	foreach ( preg_split( '/(?<=[.!?])\s+(?=[A-Z0-9"\x{201C}])/u', $t ) as $x ) {
		$x = trim( str_replace( '<dot>', '.', $x ) );
		if ( '' !== $x ) {
			$out[] = $x;
		}
	}
	return $out;
}

/** First word of a sentence, lower case, without punctuation. */
function daw_voice_first_word( $s ) {
	return preg_match( '/^[^\p{L}\p{N}]*([\p{L}\p{N}\'-]+)/u', (string) $s, $m ) ? strtolower( $m[1] ) : '';
}

/** Normalised opening of a sentence for the template index (madde 78). */
function daw_voice_frame( $sentence, $title ) {
	$s = strtolower( html_entity_decode( (string) $sentence, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	$t = strtolower( trim( preg_replace( '/\s*\([^)]*\)\s*/', ' ', (string) $title ) ) );
	if ( '' !== $t ) {
		$s = str_replace( $t, ' T ', $s );
	}
	$s = preg_replace( '/\d+/', ' N ', $s );
	$w = preg_split( '/[^\p{L}N T]+|\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY );
	if ( count( $w ) < 9 ) {
		return '';
	}
	return implode( ' ', array_slice( $w, 0, 6 ) );
}

/** Long text fields the voice metrics read. */
function daw_voice_long_fields() {
	return array( 'daw_synopsis', 'daw_why_watched', 'daw_biography', 'daw_person_section_body', 'daw_title_section_body', 'daw_takeaways', 'daw_person_takeaways', 'daw_themes_messages' );
}

/**
 * Build the template index: for each sentence frame, how many records use it.
 * Stored in an option; rebuilt once a day by the audit cron or on demand.
 */
function daw_voice_tpl_build() {
	global $wpdb;
	$ids = function_exists( 'daw_audit_record_ids' ) && function_exists( 'daw_ae_all_types' ) ? array_map( 'intval', (array) daw_audit_record_ids( daw_ae_all_types() ) ) : array();
	if ( ! $ids ) {
		return array();
	}
	$keys   = array_merge( daw_voice_long_fields(), array( 'daw_person_summary', 'daw_faq_a', 'daw_pfaq_a', 'rank_math_description' ) );
	$in_ids = implode( ',', $ids );
	$in_k   = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";
	$titles = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($in_ids)", OBJECT_K ); // phpcs:ignore WordPress.DB
	$count  = array();
	foreach ( array_chunk( $ids, 150 ) as $chunk ) {
		$in   = implode( ',', $chunk );
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN ($in_k)", ARRAY_A ); // phpcs:ignore WordPress.DB
		$per  = array();
		foreach ( (array) $rows as $r ) {
			$pid = (int) $r['post_id'];
			$v   = maybe_unserialize( $r['meta_value'] );
			$all = array();
			if ( is_array( $v ) ) {
				array_walk_recursive( $v, static function ( $x ) use ( &$all ) { if ( is_string( $x ) ) { $all[] = $x; } } );
			} elseif ( is_string( $v ) ) {
				$all[] = $v;
			}
			foreach ( $all as $txt ) {
				foreach ( daw_voice_sentences( $txt ) as $sen ) {
					$f = daw_voice_frame( $sen, isset( $titles[ $pid ] ) ? $titles[ $pid ]->post_title : '' );
					if ( '' !== $f ) {
						$per[ $pid ][ $f ] = true;
					}
				}
			}
		}
		foreach ( $per as $frames ) {
			foreach ( array_keys( $frames ) as $f ) {
				$count[ $f ] = ( $count[ $f ] ?? 0 ) + 1;
			}
		}
	}
	$keep = array();
	foreach ( $count as $f => $n ) {
		if ( $n >= daw_voice_tpl_min() ) {
			$keep[ $f ] = $n;
		}
	}
	arsort( $keep );
	update_option( 'daw_voice_tpl', array( 'at' => time(), 'n' => count( $ids ), 'frames' => $keep ), false );
	return $keep;
}

/** A frame on this many records or more is a template. */
function daw_voice_tpl_min() {
	return 6;
}

/** The stored index, rebuilt when older than a day (only from cron or REST). */
function daw_voice_tpl_index() {
	$o = get_option( 'daw_voice_tpl' );
	return is_array( $o ) && isset( $o['frames'] ) ? (array) $o['frames'] : array();
}

add_action( 'daw_ae_cron', 'daw_voice_tpl_cron', 5 );
function daw_voice_tpl_cron() {
	$o = get_option( 'daw_voice_tpl' );
	if ( ! is_array( $o ) || (int) ( $o['at'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
		daw_voice_tpl_build();
	}
}

/** Words that show an editor's view (madde 81). */
function daw_voice_editor_markers() {
	return '/\b(worth|start with|skip|if you|you\'ll|you will|you can|you may|fans of|suits|works best|doesn\'t work|does not work|holds up|standout|stand-out|highlight|payoff|the catch|the problem|weak|weakest|strong|strongest|slow|slower|pacing|drags|uneven|best|better than|less than|too long|too short|enough|easy to|hard to|rewatch|binge|first episode|first two episodes|by episode|the ending|the finale)\b/i';
}

/**
 * Audit rows for one record: per-text checks (75-80), the template index
 * (78) and the voice metrics (81).
 *
 * @param int    $post_id Record.
 * @param array  $texts   [label, value] pairs the audit collected.
 * @param string $group   title, show, person, guide ...
 * @return array Audit rule rows [label, value, state, note].
 */
function daw_voice_rules( $post_id, array $texts, $group = '' ) {
	$labels = array(
		'75' => 'Klişe kelime (madde 75)',
		'76' => 'AI kelimesi (madde 76)',
		'77' => 'AI kalıbı (madde 77)',
		'78' => 'Şablon cümle (madde 78)',
		'79' => 'Ansiklopedik dil (madde 79)',
		'80' => 'AI izi / filigran (madde 80)',
		'81' => 'Site sesi (madde 81)',
	);
	$rows = array();
	$seen = array();
	$texts[] = array( 'SEO başlığı', (string) get_post_meta( $post_id, 'rank_math_title', true ) );
	$texts[] = array( 'SEO açıklaması', (string) get_post_meta( $post_id, 'rank_math_description', true ) );
	$texts[] = array( 'Özet (excerpt)', (string) get_post_field( 'post_excerpt', $post_id ) );
	if ( 'guide' === $group ) {
		$texts[] = array( 'Metin', (string) get_post_field( 'post_content', $post_id ) );
	}
	foreach ( $texts as $t ) {
		foreach ( daw_voice_issues( (string) $t[1] ) as $iss ) {
			$k = $iss[0] . '|' . $t[0] . '|' . $iss[1];
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$rows[]     = array( $labels[ $iss[0] ], mb_substr( $t[0] . ': ' . wp_strip_all_tags( (string) $t[1] ), 0, 90 ), 'no', $iss[1] . ' (madde ' . $iss[0] . ')' );
		}
	}

	// 78: a sentence frame shared with many records.
	$idx = daw_voice_tpl_index();
	if ( $idx ) {
		$title = get_the_title( $post_id );
		$hit   = array();
		foreach ( $texts as $t ) {
			foreach ( daw_voice_sentences( (string) $t[1] ) as $sen ) {
				$f = daw_voice_frame( $sen, $title );
				if ( '' !== $f && isset( $idx[ $f ] ) && ! isset( $hit[ $f ] ) ) {
					$hit[ $f ] = true;
					$rows[]    = array( $labels['78'], mb_substr( $t[0] . ': ' . $sen, 0, 90 ), 'no', 'Bu cümle kalıbı ' . (int) $idx[ $f ] . ' kayıtta var: "' . $f . ' ..." Kendi cümlemizle yeniden kurulmalı (madde 78)' );
				}
			}
		}
	}

	// 81: the DAW voice, measured on the long fields.
	foreach ( daw_voice_long_fields() as $key ) {
		$vals = function_exists( 'daw_ae_values' ) ? daw_ae_values( $post_id, $key ) : array();
		$txt  = trim( implode( "\n\n", $vals ) );
		if ( '' === $txt ) {
			continue;
		}
		$sen = daw_voice_sentences( $txt );
		$n   = count( $sen );
		if ( $n >= 8 ) {
			$len  = array_map( static function ( $x ) { return max( 1, str_word_count( $x ) ); }, $sen );
			$mean = array_sum( $len ) / $n;
			$var  = 0;
			foreach ( $len as $l ) {
				$var += ( $l - $mean ) * ( $l - $mean );
			}
			$cv = sqrt( $var / $n ) / $mean;
			if ( $cv < 0.28 ) {
				$rows[] = array( $labels['81'], $key, 'no', 'Monoton ritim: cümle uzunlukları neredeyse aynı (değişkenlik ' . round( $cv, 2 ) . ', en az 0.28). Kısa ve uzun cümleleri karıştır (madde 81)' );
			}
			if ( $mean > 30 ) {
				$rows[] = array( $labels['81'], $key, 'no', 'Ağır cümleler: ortalama ' . round( $mean ) . ' kelime (en çok 30). Böl ve sadeleştir (madde 81)' );
			}
		}
		// Three sentences in a row opening with the same word.
		$run  = 1;
		$prev = '';
		foreach ( $sen as $s ) {
			$w   = daw_voice_first_word( $s );
			$run = ( '' !== $w && $w === $prev ) ? $run + 1 : 1;
			if ( 3 === $run ) {
				$rows[] = array( $labels['81'], $key, 'no', 'Üç cümle üst üste "' . $w . '" ile başlıyor. Açılışları değiştir (madde 81)' );
				break;
			}
			$prev = $w;
		}
		// Three paragraphs opening with the same word.
		$par = array_values( array_filter( array_map( 'trim', preg_split( '/\n\s*\n|<\/p>/i', $txt ) ) ) );
		if ( count( $par ) >= 3 ) {
			$fw = array_count_values( array_filter( array_map( 'daw_voice_first_word', array_map( 'wp_strip_all_tags', $par ) ) ) );
			arsort( $fw );
			$top = key( $fw );
			if ( $top && current( $fw ) >= 3 ) {
				$rows[] = array( $labels['81'], $key, 'no', current( $fw ) . ' paragraf "' . $top . '" ile başlıyor. Her paragraf farklı açılmalı (madde 81)' );
			}
		}
	}
	// The editor's own view must be on the page.
	$view_keys = 'person' === $group ? array( 'daw_person_takeaways', 'daw_biography', 'daw_person_section_body' ) : array( 'daw_why_watched', 'daw_takeaways', 'daw_title_section_body' );
	if ( in_array( $group, array( 'title', 'show', 'person' ), true ) && function_exists( 'daw_ae_values' ) ) {
		$view = '';
		foreach ( $view_keys as $k ) {
			$view .= ' ' . implode( ' ', daw_ae_values( $post_id, $k ) );
		}
		if ( '' !== trim( $view ) && ! preg_match( daw_voice_editor_markers(), daw_voice_unquote( wp_strip_all_tags( $view ) ) ) ) {
			$rows[] = array( $labels['81'], implode( ', ', $view_keys ), 'no', 'Editör görüşü yok: kime uyar, nereden başlanır, neyi iyi/zayıf, temposu nasıl söylenmeli (madde 81)' );
		}
	}
	return $rows;
}
