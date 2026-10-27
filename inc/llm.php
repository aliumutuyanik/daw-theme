<?php
/**
 * Outside models (Gemini, OpenRouter, NVIDIA) for reading and drafting.
 *
 * WHY
 * ---
 * An agent that opens Wikipedia, MyDramaList and the Korean press page by page
 * spends 200-300k tokens on one person. The owner (7 Oct 2026) asked for that
 * reading to happen on this server, through free or cheap outside models, so
 * Claude only receives a short result to check and write.
 *
 * RULES
 * -----
 * - Fixed tasks only, each with its own prompt; no free prompt reaches a model.
 * - Keys come from wp-config.php (DAW_GEMINI_KEY, DAW_OPENROUTER_KEY,
 *   DAW_NVIDIA_KEY) or non-autoloaded options. They are never returned, logged
 *   or put into an error message.
 * - Providers are tried in order; 402/429/5xx/timeout moves to the next one.
 *   When all fail the answer is { ok:false, fallback:true } and Claude does the
 *   work itself, so nothing is ever left half done.
 * - research_*: every fact is checked here before Claude sees it. The page at
 *   its url is opened and the quote must be on it, and the value must be in the
 *   quote. Pilots (7 Oct) showed models give confident wrong values and
 *   invented urls; what fails the check goes to "unverified", never to "facts".
 * - Outside models never write to the site.
 *
 * Route: POST /daw/v1/llm { task, id, input, max_tokens }   (admin only)
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_LLM_LOG   = 'daw_llm_log';
const DAW_LLM_USAGE = 'daw_llm_usage';
const DAW_LLM_TASKS = array( 'ping', 'config', 'research_person', 'research_title', 'prompt', 'verify', 'done', 'inbox' );
const DAW_LLM_INBOX = 'daw_llm_inbox';
const DAW_LLM_RESV  = 'daw_llm_reserved'; // id => [source, time]: people handed to one chat window
const DAW_LLM_BATCH = 100;
const DAW_LLM_BANNED = array( 'delve', 'tapestry', 'testament', 'captivating', 'must-watch', 'rollercoaster', 'unforgettable journey', 'in conclusion' );

/* ============================================================ providers === */

/**
 * The chain. Gemini first (Google Search grounding), OpenRouter second (web
 * search through its ":online" models), NVIDIA last with a short limit: its
 * one usable model took 90-170 seconds in the pilot. NVIDIA cannot search, so
 * it gets no research: without search the first live test (7 Oct, 8671) gave
 * only invented urls, every one of them a 404.
 * Model names change (Google retired gemini-2.5-flash for new keys in Oct
 * 2026), so they can be set with the "config" task instead of a release.
 */
function daw_llm_providers() {
	$models = (array) get_option( 'daw_llm_models', array() );
	return array(
		'gemini'     => array(
			'name'    => 'Gemini',
			'const'   => 'DAW_GEMINI_KEY',
			'model'   => (string) ( $models['gemini'] ?? 'gemini-3.8-flash' ),
			'timeout' => 60,
			'search'  => true,
		),
		'openrouter' => array(
			'name'    => 'OpenRouter',
			'const'   => 'DAW_OPENROUTER_KEY',
			'model'   => (string) ( $models['openrouter'] ?? ( daw_llm_free_only() ? 'meta-llama/llama-3.3-70b-instruct:free' : 'google/gemini-2.5-flash' ) ),
			'timeout' => 45,
			'search'  => ! daw_llm_free_only(),
		),
		'nvidia'     => array(
			'name'    => 'NVIDIA NIM',
			'const'   => 'DAW_NVIDIA_KEY',
			'model'   => (string) ( $models['nvidia'] ?? 'moonshotai/kimi-k3' ),
			'timeout' => 50,
			'search'  => false,
		),
	);
}

/**
 * Free only (owner, 7 Oct 2026): no call may cost money. Gemini runs on a
 * project without billing; OpenRouter only on ":free" models and without its
 * paid web search, so it gets no research; NVIDIA's free tier as it is.
 * define( 'DAW_LLM_FREE_ONLY', false ) in wp-config.php lifts it.
 */
function daw_llm_free_only() {
	return ! defined( 'DAW_LLM_FREE_ONLY' ) || DAW_LLM_FREE_ONLY;
}

function daw_llm_key( $p ) {
	$all = daw_llm_providers();
	if ( ! isset( $all[ $p ] ) ) {
		return '';
	}
	$c = $all[ $p ]['const'];
	if ( defined( $c ) && constant( $c ) ) {
		return trim( (string) constant( $c ) );
	}
	return trim( (string) get_option( 'daw_llm_key_' . $p, '' ) );
}

/** Keeps any key out of a string that may be logged or returned. */
function daw_llm_scrub( $s ) {
	$s = (string) $s;
	foreach ( array_keys( daw_llm_providers() ) as $p ) {
		$k = daw_llm_key( $p );
		if ( '' !== $k ) {
			$s = str_replace( $k, '***', $s );
		}
	}
	return mb_substr( $s, 0, 300 );
}

/**
 * One call to one provider.
 * Returns array( ok, text, tokens, code, reason, urls, ms ).
 */
function daw_llm_call( $p, $prompt, $max_tokens, $search, $json ) {
	$cfg = daw_llm_providers()[ $p ];
	$key = daw_llm_key( $p );
	$t0  = microtime( true );
	if ( '' === $key ) {
		return array( 'ok' => false, 'reason' => 'nokey', 'code' => 0, 'ms' => 0 );
	}
	if ( 'gemini' === $p ) {
		$gen = array( 'maxOutputTokens' => (int) $max_tokens, 'temperature' => 0.2 );
		if ( 0 === strpos( $cfg['model'], 'gemini-2.5-flash' ) ) {
			// Flash counts thinking against maxOutputTokens; a short limit
			// would come back empty.
			$gen['thinkingConfig'] = array( 'thinkingBudget' => 0 );
		}
		$body = array( 'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $prompt ) ) ) ) );
		if ( $search && $cfg['search'] ) {
			// Search grounding and JSON mode cannot be on together: the answer
			// comes as text and the JSON is cut out of it.
			$body['tools'] = array( array( 'google_search' => new stdClass() ) );
		} elseif ( $json ) {
			$gen['responseMimeType'] = 'application/json';
		}
		$body['generationConfig'] = $gen;
		$res = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $cfg['model'] ) . ':generateContent',
			array(
				'timeout' => $cfg['timeout'],
				'headers' => array( 'Content-Type' => 'application/json', 'x-goog-api-key' => $key ),
				'body'    => wp_json_encode( $body ),
			)
		);
	} else {
		$url  = 'openrouter' === $p ? 'https://openrouter.ai/api/v1/chat/completions' : 'https://integrate.api.nvidia.com/v1/chat/completions';
		$body = array(
			'model'       => $cfg['model'] . ( $search && $cfg['search'] && 'openrouter' === $p && false === strpos( $cfg['model'], ':' ) ? ':online' : '' ),
			'messages'    => array( array( 'role' => 'user', 'content' => $prompt ) ),
			'max_tokens'  => (int) $max_tokens,
			'temperature' => 0.2,
		);
		if ( $json && 'openrouter' === $p ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}
		$head = array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key );
		if ( 'openrouter' === $p ) {
			$head['HTTP-Referer'] = home_url( '/' );
			$head['X-Title']      = 'DiscoverAsianWorld';
		}
		$res = wp_remote_post( $url, array( 'timeout' => $cfg['timeout'], 'headers' => $head, 'body' => wp_json_encode( $body ) ) );
	}
	$ms = (int) round( 1000 * ( microtime( true ) - $t0 ) );
	if ( is_wp_error( $res ) ) {
		$msg = $res->get_error_message();
		return array( 'ok' => false, 'reason' => false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'timeout' ) ? 'timeout' : 'error', 'code' => 0, 'ms' => $ms, 'note' => daw_llm_scrub( $msg ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$d    = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code ) {
		$reason = in_array( $code, array( 402, 429 ), true ) ? 'quota' : ( $code >= 500 ? 'error' : ( 408 === $code ? 'timeout' : 'error' ) );
		$note   = is_array( $d ) ? (string) ( $d['error']['message'] ?? ( is_string( $d['error'] ?? null ) ? $d['error'] : '' ) ) : '';
		return array( 'ok' => false, 'reason' => $reason, 'code' => $code, 'ms' => $ms, 'note' => daw_llm_scrub( $note ) );
	}
	$text  = '';
	$tok   = 0;
	$urls  = array();
	$cites = array();
	if ( 'gemini' === $p ) {
		foreach ( (array) ( $d['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			$text .= (string) ( $part['text'] ?? '' );
		}
		$tok = (int) ( $d['usageMetadata']['totalTokenCount'] ?? 0 );
		foreach ( (array) ( $d['candidates'][0]['groundingMetadata']['groundingChunks'] ?? array() ) as $ch ) {
			if ( ! empty( $ch['web']['uri'] ) ) {
				$urls[] = (string) $ch['web']['uri'];
			}
		}
	} else {
		$text = (string) ( $d['choices'][0]['message']['content'] ?? '' );
		$tok  = (int) ( $d['usage']['total_tokens'] ?? 0 );
		// OpenRouter's web search hands back what the search engine read on
		// each page. That text comes from the engine, not from the model.
		foreach ( (array) ( $d['choices'][0]['message']['annotations'] ?? array() ) as $a ) {
			$c = $a['url_citation'] ?? null;
			if ( is_array( $c ) && ! empty( $c['url'] ) ) {
				$urls[] = (string) $c['url'];
				if ( ! empty( $c['content'] ) ) {
					$cites[ daw_llm_url_key( (string) $c['url'] ) ] = ( $cites[ daw_llm_url_key( (string) $c['url'] ) ] ?? '' ) . ' ' . (string) $c['content'];
				}
			}
		}
	}
	if ( '' === trim( $text ) ) {
		return array( 'ok' => false, 'reason' => 'error', 'code' => $code, 'ms' => $ms, 'tokens' => $tok, 'note' => 'boş cevap' );
	}
	return array( 'ok' => true, 'text' => $text, 'tokens' => $tok, 'code' => $code, 'ms' => $ms, 'urls' => $urls, 'cites' => $cites );
}

/** The first JSON object in a model's text, or null. */
function daw_llm_json( $text ) {
	$t = trim( (string) $text );
	$t = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $t );
	$d = json_decode( $t, true );
	if ( is_array( $d ) ) {
		return $d;
	}
	$a = strpos( $t, '{' );
	$b = strrpos( $t, '}' );
	if ( false !== $a && false !== $b && $b > $a ) {
		$d = json_decode( substr( $t, $a, $b - $a + 1 ), true );
	}
	return is_array( $d ) ? $d : null;
}

/* ================================================================ usage === */

function daw_llm_usage() {
	$u = get_option( DAW_LLM_USAGE, array() );
	if ( ! is_array( $u ) || ( $u['day'] ?? '' ) !== gmdate( 'Y-m-d' ) ) {
		$u = array( 'day' => gmdate( 'Y-m-d' ), 'p' => array() );
	}
	// A new key is a new project: yesterday's "credit gone" does not apply.
	foreach ( (array) $u['p'] as $p => $c ) {
		$h = substr( md5( daw_llm_key( $p ) ), 0, 8 );
		if ( ( $c['kh'] ?? '' ) !== $h ) {
			$u['p'][ $p ]['out']     = '';
			$u['p'][ $p ]['out_web'] = '';
			$u['p'][ $p ]['kh']      = $h;
		}
	}
	return $u;
}

function daw_llm_count( $p, array $r, $web = false ) {
	$u = daw_llm_usage();
	$c = $u['p'][ $p ] ?? array( 'req' => 0, 'tok' => 0, 'ok' => 0, 'fail' => 0, 'out' => '', 'out_web' => '' );
	$c['kh'] = substr( md5( daw_llm_key( $p ) ), 0, 8 );
	++$c['req'];
	$c['tok'] += (int) ( $r['tokens'] ?? 0 );
	if ( ! empty( $r['ok'] ) ) {
		++$c['ok'];
	} else {
		++$c['fail'];
		// 402 is credit gone: skip this provider (or its paid web search
		// alone) for the rest of the day.
		if ( 402 === (int) ( $r['code'] ?? 0 ) ) {
			$c[ $web ? 'out_web' : 'out' ] = 'kredi bitti';
		}
	}
	$u['p'][ $p ] = $c;
	update_option( DAW_LLM_USAGE, $u, false );
}

function daw_llm_log_add( array $row ) {
	$log   = (array) get_option( DAW_LLM_LOG, array() );
	$log[] = $row;
	if ( count( $log ) > 500 ) {
		$log = array_slice( $log, -500 );
	}
	update_option( DAW_LLM_LOG, $log, false );
}

/* =========================================================== the runner === */

/**
 * Ask the chain until one provider returns valid JSON. Invalid JSON is asked
 * once more on the same provider, then the next provider is tried.
 */
function daw_llm_run( $task, $id, $prompt, $max_tokens, $search ) {
	$tried = array();
	$u     = daw_llm_usage();
	foreach ( daw_llm_providers() as $p => $cfg ) {
		if ( '' === daw_llm_key( $p ) ) {
			continue;
		}
		if ( 'openrouter' === $p && daw_llm_free_only() && ':free' !== substr( $cfg['model'], -5 ) ) {
			continue; // A paid model, and the owner allows free ones only.
		}
		if ( $search && ! $cfg['search'] ) {
			continue; // A model that cannot search only invents sources.
		}
		$out = (string) ( $u['p'][ $p ][ $search ? 'out_web' : 'out' ] ?? '' ) . (string) ( $u['p'][ $p ]['out'] ?? '' );
		if ( '' !== $out ) {
			$tried[] = $p . ':kredi bitti';
			continue;
		}
		for ( $try = 0; $try < 2; $try++ ) {
			$r = daw_llm_call( $p, $prompt, $max_tokens, $search, true );
			if ( empty( $r['ok'] ) && 503 === (int) ( $r['code'] ?? 0 ) ) {
				sleep( 3 ); // "high demand" passes in seconds more often than not
				$r = daw_llm_call( $p, $prompt, $max_tokens, $search, true );
			}
			daw_llm_count( $p, $r, $search );
			if ( empty( $r['ok'] ) ) {
				$tried[] = $p . ':' . $r['reason'] . ( $r['code'] ? ' ' . $r['code'] : '' ) . ( ! empty( $r['note'] ) ? ' (' . mb_substr( (string) $r['note'], 0, 160 ) . ')' : '' );
				break; // A failed call goes to the next provider, not again.
			}
			$j = daw_llm_json( $r['text'] );
			if ( null !== $j ) {
				return array( 'ok' => true, 'provider' => $p, 'model' => $cfg['model'], 'json' => $j, 'tokens' => (int) $r['tokens'], 'ms' => (int) $r['ms'], 'urls' => (array) ( $r['urls'] ?? array() ), 'cites' => (array) ( $r['cites'] ?? array() ), 'tried' => $tried );
			}
			$tried[] = $p . ':bad_json';
		}
	}
	$reason = 'error';
	$joined = implode( ' ', $tried );
	if ( ! $tried ) {
		$reason = 'nokey';
	} elseif ( false !== strpos( $joined, 'quota' ) || false !== strpos( $joined, 'kredi' ) ) {
		$reason = 'quota';
	} elseif ( false !== strpos( $joined, 'timeout' ) ) {
		$reason = 'timeout';
	}
	return array( 'ok' => false, 'reason' => $reason, 'tried' => $tried );
}

/* ======================================================== verification === */

/** A url without its query, fragment and trailing slash, for matching. */
function daw_llm_url_key( $url ) {
	$u = strtolower( rawurldecode( (string) $url ) );
	$u = preg_replace( '/[?#].*$/', '', $u );
	return rtrim( preg_replace( '#^https?://(www\.)?#', '', $u ), '/' );
}

/** Lowercase, entities decoded, quotes and dashes unified, spaces collapsed. */
function daw_llm_norm( $s ) {
	$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$s = str_replace( array( '’', '‘', '“', '”', '–', '—', "\xC2\xA0" ), array( "'", "'", '"', '"', '-', '-', ' ' ), $s );
	$s = mb_strtolower( $s, 'UTF-8' );
	$s = preg_replace( '/\[\d{1,3}\]/', ' ', $s ); // footnote marks
	return trim( preg_replace( '/\s+/u', ' ', $s ) );
}

/**
 * The text of a page, normalized, and where it really was (redirects such as
 * Gemini's grounding links followed). Fetched once per request.
 */
function daw_llm_page( $url ) {
	static $seen = array();
	if ( isset( $seen[ $url ] ) ) {
		return $seen[ $url ];
	}
	static $t0 = null;
	$t0  = $t0 ?? microtime( true );
	$out = array( 'ok' => false, 'text' => '', 'url' => $url, 'code' => 0, 'later' => false );
	if ( count( $seen ) >= 80 || microtime( true ) - $t0 > 40 ) {
		$out['later'] = true; // out of time in this request; the queue tries again
		return $out;
	}
	if ( ! wp_http_validate_url( $url ) ) {
		$seen[ $url ] = $out;
		return $out;
	}
	$res = wp_safe_remote_get(
		$url,
		array(
			'timeout'             => 10,
			'redirection'         => 5,
			'limit_response_size' => 3 * MB_IN_BYTES,
			'user-agent'          => 'Mozilla/5.0 (compatible; DiscoverAsianWorld/1.0; +https://discoverasianworld.com)',
		)
	);
	if ( ! is_wp_error( $res ) ) {
		$out['code'] = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 === $out['code'] ) {
			$html = (string) wp_remote_retrieve_body( $res );
			$html = preg_replace( '#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $html );
			$out['text'] = daw_llm_norm( wp_strip_all_tags( $html ) );
			$out['ok']   = '' !== $out['text'];
			$obj         = $res['http_response'] ?? null;
			if ( is_object( $obj ) && method_exists( $obj, 'get_response_object' ) ) {
				$real = (string) ( $obj->get_response_object()->url ?? '' );
				if ( '' !== $real ) {
					$out['url'] = $real;
				}
			}
		}
	}
	$seen[ $url ] = $out;
	return $out;
}

/**
 * Is the value carried by the quote? Numbers must all be there (a date given
 * as 1994-10-10 matches "October 10, 1994" or "1994년 10월 10일"); words of
 * three letters or more must all be there.
 */
function daw_llm_value_in_quote( $value, $quote ) {
	$v = daw_llm_norm( $value );
	$q = daw_llm_norm( $quote );
	if ( '' === $v ) {
		return false;
	}
	if ( false !== mb_strpos( $q, $v ) ) {
		return true;
	}
	preg_match_all( '/\d+/', $v, $vn );
	preg_match_all( '/\d+/', $q, $qn );
	$qnums = array_map( 'intval', $qn[0] );
	foreach ( $vn[0] as $n ) {
		if ( ! in_array( (int) $n, $qnums, true ) ) {
			return false;
		}
	}
	$words = array_filter( preg_split( '/[^\p{L}\p{N}]+/u', preg_replace( '/\d+/', ' ', $v ) ), static fn( $w ) => mb_strlen( $w ) >= 3 );
	foreach ( $words as $w ) {
		if ( false === mb_strpos( $q, $w ) ) {
			return false;
		}
	}
	return (bool) ( $vn[0] || $words );
}

/** English text rules: no em dash, no banned words. */
function daw_llm_quality( $s ) {
	if ( false !== strpos( (string) $s, '—' ) ) {
		return 'em dash';
	}
	$l = mb_strtolower( (string) $s );
	foreach ( DAW_LLM_BANNED as $w ) {
		if ( false !== strpos( $l, $w ) ) {
			return 'yasak kelime: ' . $w;
		}
	}
	return '';
}

/* =============================================================== tasks === */

/** The fields to research: given, or the record's empty, applicable ones. */
function daw_llm_fields( $post_id, $type, array $want ) {
	$out = array();
	foreach ( function_exists( 'daw_ae_boxes_for' ) ? daw_ae_boxes_for( $type ) : array() as $box ) {
		foreach ( (array) $box['fields'] as $f ) {
			$id = (string) ( $f['id'] ?? '' );
			// Short facts only. Prose panels (wysiwyg, textarea) are written,
			// not looked up; selects and switches are editorial choices.
			if ( '' === $id || ! in_array( $f['type'] ?? 'text', array( 'text', 'number', 'date', 'url', 'email' ), true ) ) {
				continue;
			}
			// People: only facts a source states outright. Table rows (music
			// credits, awards), editorial lists (traits, skills) and site
			// links (ask_to) are not looked up.
			if ( 'person' === $type && ! preg_match( '/^daw_(birth_|height|weight|blood|mbti|real_name|religion|agency|link_agency|social_|edu_|mil_|fam_|spouse|children|partner|instruments|member_of|debut)/', $id ) ) {
				continue;
			}
			if ( $want ) {
				if ( in_array( $id, $want, true ) ) {
					$out[ $id ] = wp_strip_all_tags( (string) ( $f['name'] ?? $id ) );
				}
				continue;
			}
			if ( daw_ae_values( $post_id, $id ) || '' !== daw_ae_not_applicable( $post_id, $id, $type ) ) {
				continue;
			}
			$out[ $id ] = wp_strip_all_tags( (string) ( $f['name'] ?? $id ) );
		}
	}
	foreach ( $want as $id ) { // Asked for but not in a box: keep, with its id as label.
		if ( ! isset( $out[ $id ] ) && preg_match( '/^daw_[a-z0-9_]+$/', $id ) ) {
			$out[ $id ] = $id;
		}
	}
	return array_slice( $out, 0, 30, true );
}

function daw_llm_research_prompt( $kind, $name, $native, array $fields ) {
	$list = '';
	foreach ( $fields as $id => $label ) {
		$list .= '- ' . $id . ': ' . $label . "\n";
	}
	$who = 'person' === $kind ? 'the East Asian actor, singer or entertainer' : 'the East Asian drama, film, variety show or anime';
	return "Research {$who} \"{$name}\"" . ( '' !== $native ? " (native name: {$native})" : '' ) . ".\n"
		. "Find the value of each field below on a real web page (official agency or broadcaster pages, Wikipedia, namu.wiki, MyDramaList, major news sites).\n\n"
		. $list . "\n"
		. "Answer with ONLY one JSON object, no other text:\n"
		. '{"facts":[{"field":"<field id>","value":"<value in English>","quote":"<one sentence copied character for character from the page>","url":"<the page address>"}],"checked_urls":["<every page you read>"],"not_found":["<field ids you could not find>"]}' . "\n\n"
		. "Rules:\n"
		. "- The quote must be copied exactly from the page at url and must contain the value. It will be checked by opening the page; a quote that is not on the page is thrown away.\n"
		. "- Never guess or invent. If you are not sure, put the field in not_found.\n"
		. "- Dates as YYYY-MM-DD, heights as a number of centimetres.\n"
		. "- Values in English, no em dash.\n";
}

function daw_llm_task_research( $kind, $post_id, array $input, $max_tokens ) {
	$type = function_exists( 'daw_get_type' ) ? (string) daw_get_type( $post_id ) : '';
	if ( 'person' === $kind && 'person' !== $type ) {
		return array( 'ok' => false, 'fallback' => false, 'reason' => 'bad_input', 'note' => 'kayıt kişi değil' );
	}
	$name   = trim( (string) ( $input['name'] ?? get_the_title( $post_id ) ) );
	$native = trim( (string) ( $input['native'] ?? implode( ' ', daw_ae_values( $post_id, 'person' === $kind ? 'daw_native_name' : 'daw_native_title' ) ) ) );
	$fields = daw_llm_fields( $post_id, $type ? $type : $kind, array_values( array_filter( array_map( 'strval', (array) ( $input['fields'] ?? array() ) ) ) ) );
	if ( '' === $name || ! $fields ) {
		return array( 'ok' => false, 'fallback' => false, 'reason' => 'bad_input', 'note' => '' === $name ? 'ad yok' : 'aranacak alan yok' );
	}
	$run = daw_llm_run( 'research_' . $kind, $post_id, daw_llm_research_prompt( $kind, $name, $native, $fields ), $max_tokens, true );
	if ( empty( $run['ok'] ) ) {
		return array( 'ok' => false, 'fallback' => true, 'reason' => $run['reason'], 'tried' => $run['tried'] );
	}
	$j          = $run['json'];
	list( $facts, $unverified ) = daw_llm_verify( (array) ( $j['facts'] ?? array() ), $fields, (array) $run['cites'] );
	$checked = array();
	foreach ( array_unique( array_merge( (array) ( $j['checked_urls'] ?? array() ), $run['urls'] ) ) as $u ) {
		$pg = daw_llm_page( (string) $u );
		if ( $pg['ok'] ) {
			$checked[] = $pg['url'];
		}
	}
	$found     = wp_list_pluck( $facts, 'field' );
	$not_found = array_values( array_diff( array_keys( $fields ), $found ) );
	return array(
		'ok'       => true,
		'provider' => $run['provider'],
		'model'    => $run['model'],
		'tokens'   => $run['tokens'],
		'ms'       => $run['ms'],
		'tried'    => $run['tried'],
		'result'   => array(
			'facts'        => $facts,
			'unverified'   => $unverified,
			'not_found'    => $not_found,
			'checked_urls' => array_values( array_unique( $checked ) ),
		),
	);
}

/**
 * The check every fact passes, whoever found it (a model here, or the owner
 * in his own ChatGPT/Gemini): the quote must be on the page, or in the search
 * engine's copy of it, and the value must be in the quote.
 */
function daw_llm_verify( array $items, array $fields, array $cites = array() ) {
	$facts      = array();
	$unverified = array();
	foreach ( $items as $f ) {
		$field = (string) ( $f['field'] ?? '' );
		$value = trim( (string) ( $f['value'] ?? '' ) );
		$quote = trim( (string) ( $f['quote'] ?? '' ) );
		$url   = trim( (string) ( $f['url'] ?? '' ) );
		$orig  = trim( (string) ( $f['value_orig'] ?? '' ) );
		$row   = array( 'field' => $field, 'value' => $value, 'quote' => $quote, 'url' => $url );
		if ( '' !== $orig && $orig !== $value ) {
			$row['value_orig'] = $orig; // value as the quote writes it; Claude checks the English form
		}
		$why   = '';
		if ( ! isset( $fields[ $field ] ) ) {
			$why = 'istenmeyen alan';
		} elseif ( '' === $value || mb_strlen( daw_llm_norm( $quote ) ) < 8 ) {
			$why = 'değer ya da alıntı yok';
		} elseif ( '' !== ( $qw = daw_llm_quality( $value ) ) ) {
			$why = $qw;
		} elseif ( ! daw_llm_value_in_quote( $value, $quote ) && ( '' === $orig || ! daw_llm_value_in_quote( $orig, $quote ) ) ) {
			$why = 'değer alıntıda yok';
		} else {
			$nq  = daw_llm_norm( $quote );
			$pg  = daw_llm_page( $url );
			$cit = daw_llm_norm( $cites[ daw_llm_url_key( $url ) ] ?? '' );
			if ( $pg['ok'] && false !== mb_strpos( $pg['text'], $nq ) ) {
				$row['url']     = $pg['url'];
				$row['checked'] = 'sayfa';
			} elseif ( '' !== $cit && false !== mb_strpos( $cit, $nq ) ) {
				// namu.wiki and MyDramaList refuse this server (403). The
				// search engine's copy of the page is the second witness.
				$row['checked'] = 'arama motoru kopyası';
			} elseif ( ! empty( $pg['later'] ) ) {
				$why = 'sırada';
			} elseif ( ! $pg['ok'] ) {
				$why = 'sayfa açılmadı' . ( $pg['code'] ? ' (' . $pg['code'] . ')' : '' ) . ( '' !== $cit ? ', arama kopyasında da yok' : '' );
			} else {
				$why = 'alıntı sayfada yok';
			}
		}
		if ( '' === $why ) {
			$facts[] = $row;
		} else {
			$row['why']   = $why;
			$unverified[] = $row;
		}
	}
	return array( $facts, $unverified );
}

/** Every provider once: works or not, why, how long. Never the key. */
function daw_llm_task_ping( array $input = array() ) {
	$out  = array();
	$only = (string) ( $input['only'] ?? '' );
	$web  = ! empty( $input['search'] );
	foreach ( daw_llm_providers() as $p => $cfg ) {
		if ( '' !== $only && $only !== $p ) {
			continue; // one provider at a time keeps the request short
		}
		$row = array( 'name' => $cfg['name'], 'model' => $cfg['model'], 'key' => '' !== daw_llm_key( $p ) );
		if ( $row['key'] ) {
			$r = $web
				? daw_llm_call( $p, 'Search the web: what is the capital of South Korea? Reply with only this JSON: {"ok":true,"answer":"<city>"}', 200, true, true )
				: daw_llm_call( $p, 'Reply with exactly this JSON and nothing else: {"ok":true}', 60, false, true );
			daw_llm_count( $p, $r );
			$row['ok']     = ! empty( $r['ok'] );
			$row['code']   = (int) ( $r['code'] ?? 0 );
			$row['ms']     = (int) ( $r['ms'] ?? 0 );
			$row['reason'] = $row['ok'] ? '' : (string) ( $r['reason'] ?? '' );
			$row['note']   = $row['ok'] ? '' : (string) ( $r['note'] ?? '' );
			if ( 'openrouter' === $p ) {
				$k = wp_remote_get( 'https://openrouter.ai/api/v1/key', array( 'timeout' => 10, 'headers' => array( 'Authorization' => 'Bearer ' . daw_llm_key( $p ) ) ) );
				$d = is_wp_error( $k ) ? null : json_decode( (string) wp_remote_retrieve_body( $k ), true );
				if ( is_array( $d['data'] ?? null ) ) {
					$row['credit'] = array(
						'usage'     => $d['data']['usage'] ?? null,
						'limit'     => $d['data']['limit'] ?? null,
						'remaining' => $d['data']['limit_remaining'] ?? null,
						'free_tier' => $d['data']['is_free_tier'] ?? null,
					);
				}
				// Free models come and go; list what is free today.
				$m = wp_remote_get( 'https://openrouter.ai/api/v1/models', array( 'timeout' => 15 ) );
				$d = is_wp_error( $m ) ? null : json_decode( (string) wp_remote_retrieve_body( $m ), true );
				$free = array();
				foreach ( (array) ( $d['data'] ?? array() ) as $mo ) {
					$id = (string) ( $mo['id'] ?? '' );
					if ( ':free' === substr( $id, -5 ) ) {
						$free[ $id ] = (int) ( $mo['context_length'] ?? 0 );
					}
				}
				arsort( $free );
				$row['free_models'] = array_slice( array_keys( $free ), 0, 25 );
			}
		}
		$out[ $p ] = $row;
	}
	return array( 'ok' => true, 'result' => array( 'providers' => $out, 'today' => daw_llm_usage()['p'] ) );
}

/** Model names per provider; an empty name goes back to the default. */
function daw_llm_task_config( array $models ) {
	$cur = (array) get_option( 'daw_llm_models', array() );
	foreach ( array_keys( daw_llm_providers() ) as $p ) {
		if ( ! array_key_exists( $p, $models ) ) {
			continue;
		}
		$m = trim( (string) $models[ $p ] );
		if ( '' === $m ) {
			unset( $cur[ $p ] );
		} elseif ( preg_match( '#^[a-z0-9._:/\-]{3,80}$#i', $m ) ) {
			$cur[ $p ] = $m;
		}
	}
	update_option( 'daw_llm_models', $cur, false );
	return array( 'ok' => true, 'result' => array( 'models' => wp_list_pluck( daw_llm_providers(), 'model' ) ) );
}


/* ======================================================= by hand (owner) === */

/*
 * The owner has ChatGPT and Gemini subscriptions. A subscription has no API,
 * and driving the web app with a program breaks its terms. So the site writes
 * the question, the owner pastes it into ChatGPT or Gemini with web search on,
 * and pastes the answer back. The answer goes through daw_llm_verify like any
 * model's, lands in daw_llm_inbox, and Claude checks it against the rules
 * before anything is written. Tools > DAW: Dış AI.
 */

/** People with the most empty short fields, not yet in the inbox. */
function daw_llm_manual_next( $n = 5 ) {
	$n    = max( 1, min( DAW_LLM_BATCH, (int) $n ) );
	$done = array_merge(
		array_map( 'intval', array_keys( (array) get_option( DAW_LLM_INBOX, array() ) ) ),
		array_map( 'intval', array_keys( daw_llm_reserved() ) )
	);
	$ids  = function_exists( 'daw_audit_record_ids' ) ? array_map( 'intval', (array) daw_audit_record_ids( array( 'person' ) ) ) : array();
	$pick = array();
	foreach ( $ids as $id ) {
		if ( in_array( $id, $done, true ) ) {
			continue;
		}
		$c = count( daw_llm_fields( $id, 'person', array() ) );
		if ( $c >= 3 ) {
			$pick[ $id ] = $c;
		}
		if ( count( $pick ) >= 300 ) {
			break;
		}
	}
	arsort( $pick );
	return array_slice( array_keys( $pick ), 0, $n );
}

/** Live reservations (6 hours), people already answered dropped. */
function daw_llm_reserved() {
	$r     = (array) get_option( DAW_LLM_RESV, array() );
	$inbox = (array) get_option( DAW_LLM_INBOX, array() );
	$keep  = array();
	foreach ( $r as $id => $row ) {
		if ( isset( $inbox[ $id ] ) || time() - (int) ( $row[1] ?? 0 ) > 6 * HOUR_IN_SECONDS ) {
			continue;
		}
		$keep[ (int) $id ] = $row;
	}
	return $keep;
}

/**
 * The list for one chat window. ChatGPT and Gemini each get their own people,
 * so two windows running side by side never research the same person. The
 * list stays the same on reload until it is answered or "new list" is asked.
 */
function daw_llm_manual_ids( $src, $n, $fresh = false ) {
	$src  = sanitize_key( (string) $src );
	$r    = daw_llm_reserved();
	$mine = array_keys( array_filter( $r, static fn( $row ) => ( $row[0] ?? '' ) === $src ) );
	if ( $fresh ) {
		$r    = array_filter( $r, static fn( $row ) => ( $row[0] ?? '' ) !== $src );
		$mine = array();
		update_option( DAW_LLM_RESV, $r, false );
	}
	if ( $mine ) {
		return array_map( 'intval', $mine );
	}
	$ids = daw_llm_manual_next( $n );
	foreach ( $ids as $id ) {
		$r[ $id ] = array( $src, time() );
	}
	update_option( DAW_LLM_RESV, $r, false );
	return $ids;
}

function daw_llm_manual_prompt( array $ids ) {
	$blocks = '';
	$people = array();
	foreach ( array_slice( $ids, 0, DAW_LLM_BATCH ) as $id ) {
		if ( ! get_post( $id ) ) {
			continue;
		}
		$fields = daw_llm_fields( $id, 'person', array() );
		if ( ! $fields ) {
			continue;
		}
		$native  = implode( ' ', daw_ae_values( $id, 'daw_native_name' ) );
		$blocks .= "\nID " . $id . ': ' . get_the_title( $id ) . ( '' !== $native ? ' (' . $native . ')' : '' ) . "\n";
		foreach ( $fields as $fid => $label ) {
			$blocks .= '- ' . $fid . ': ' . $label . "\n";
		}
		$people[] = $id;
	}
	$text = "Use web search. For each East Asian entertainer below, find the listed facts on real web pages.\n"
		. "Prefer official sources: the agency or broadcaster page, the person's verified social account, then Wikipedia, namu.wiki, MyDramaList and major Korean, Chinese, Japanese or Thai news sites. Do not use fan blogs, kprofiles-type sites or AI-written pages.\n"
		. $blocks
		. "\nWork through every person and every field; search each person separately. Most of these fields are on the person's Wikipedia page in English, Korean, Chinese or Japanese, or in interviews.\n"
		. "\nAnswer with ONLY one JSON object and nothing else:\n"
		. '{"people":[{"id":<ID>,"facts":[{"field":"<field id>","value":"<value in English>","value_orig":"<the same value exactly as written in the quote>","quote":"<one full sentence copied character for character from the page text, in its original language>","url":"<the page address>"}]}]}' . "\n\n"
		. "Rules:\n- Every quote is checked by opening the page; a quote that is not on the page is thrown away. Copy a sentence from the article body, not from an infobox, table or search snippet, and do not shorten or translate it.\n- The value must appear in the quote (numbers exactly).\n- Never guess. Leave out anything you are not sure of.\n- Dates as YYYY-MM-DD, heights in centimetres.\n"
		. "- Sources, best first: the agency or broadcaster profile, the person's own verified account or an interview with them, Wikipedia, major news sites. Profile aggregators (kprofiles and the like), fan wikis, IMDb-style data pages, Prime Video, TVmaze and AI-written pages do not count.\n"
		. "- Spouse only if married and announced (name, year). Partner only if both confirmed it (name, date). Children only if announced. Religion only if the person said it themselves.\n"
		. "- Agency: the company that manages the person now, not one they own or used to be with.\n"
		. "- Debut: the first work and its year, e.g. 2015 (film, Title).\n"
		. "- High school is secondary school; a junior college or A-level college is not a high school.\n"
		. "- Make sure the page is about this exact person (check the birth date or native name); people with the same name are a common mistake.\n";
	return array( 'ids' => $people, 'prompt' => $text );
}

/** Check a pasted answer; keep the result for Claude. */
function daw_llm_manual_verify( $input, $source = 'manual' ) {
	if ( is_string( $input ) ) {
		$input = daw_llm_json( $input );
	}
	$people = (array) ( $input['people'] ?? ( isset( $input['id'] ) ? array( $input ) : array() ) );
	$inbox  = (array) get_option( DAW_LLM_INBOX, array() );
	$out    = array();
	$queue  = (array) get_option( 'daw_llm_queue', array() );
	foreach ( array_slice( $people, 0, 120 ) as $pp ) {
		$id = (int) ( $pp['id'] ?? 0 );
		if ( ! $id || ! get_post( $id ) ) {
			continue;
		}
		$type   = function_exists( 'daw_get_type' ) ? (string) daw_get_type( $id ) : 'person';
		$want   = array_values( array_filter( array_map( static fn( $f ) => (string) ( $f['field'] ?? '' ), (array) ( $pp['facts'] ?? array() ) ) ) );
		$fields = daw_llm_fields( $id, $type ? $type : 'person', $want );
		list( $facts, $unver ) = daw_llm_verify( (array) ( $pp['facts'] ?? array() ), $fields );
		$later = array_values( array_filter( $unver, static fn( $f ) => 'sırada' === ( $f['why'] ?? '' ) ) );
		if ( $later ) {
			// Out of time: keep only what was not reached and finish it on cron.
			$unver = array_values( array_filter( $unver, static fn( $f ) => 'sırada' !== ( $f['why'] ?? '' ) ) );
			$queue[ $id ] = array(
				'source' => $source,
				'facts'  => array_map( static fn( $f ) => array_diff_key( $f, array( 'why' => 1 ) ), $later ),
				'done'   => array( 'facts' => array_merge( (array) ( $queue[ $id ]['done']['facts'] ?? array() ), $facts ), 'unverified' => array_merge( (array) ( $queue[ $id ]['done']['unverified'] ?? array() ), $unver ) ),
			);
			$out[ $id ] = array( 'name' => get_the_title( $id ), 'facts' => $facts, 'unverified' => $unver, 'queued' => count( $later ) );
			continue;
		}
		if ( isset( $queue[ $id ]['done'] ) ) {
			$facts = array_merge( (array) $queue[ $id ]['done']['facts'], $facts );
			$unver = array_merge( (array) $queue[ $id ]['done']['unverified'], $unver );
		}
		unset( $queue[ $id ] );
		$out[ $id ]   = array( 'name' => get_the_title( $id ), 'facts' => $facts, 'unverified' => $unver );
		$inbox[ $id ] = array( 'at' => time(), 'source' => $source, 'facts' => $facts, 'unverified' => $unver, 'written' => false );
		daw_llm_log_add( array( 'at' => time(), 'task' => 'verify', 'id' => $id, 'provider' => $source, 'model' => '', 'tokens' => 0, 'ok' => true, 'reason' => '', 'facts' => count( $facts ), 'unver' => count( $unver ) ) );
	}
	if ( count( $inbox ) > 300 ) {
		$inbox = array_slice( $inbox, -300, null, true );
	}
	update_option( DAW_LLM_INBOX, $inbox, false );
	update_option( 'daw_llm_queue', $queue, false );
	if ( $queue && ! wp_next_scheduled( 'daw_llm_queue_run' ) ) {
		wp_schedule_single_event( time() + 60, 'daw_llm_queue_run' );
	}
	return $out;
}

/** Cron: finish the checks one request could not reach. */
add_action( 'daw_llm_queue_run', 'daw_llm_queue_run' );
function daw_llm_queue_run() {
	$queue = (array) get_option( 'daw_llm_queue', array() );
	if ( ! $queue ) {
		return;
	}
	$by = array();
	foreach ( array_slice( $queue, 0, 15, true ) as $id => $q ) {
		$by[ (string) ( $q['source'] ?? 'manual' ) ][] = array( 'id' => (int) $id, 'facts' => (array) ( $q['facts'] ?? array() ) );
	}
	foreach ( $by as $src => $people ) {
		daw_llm_manual_verify( array( 'people' => $people ), $src );
	}
}

/** Claude marks people it has written (or rejected) so they leave the waiting count. */
function daw_llm_manual_done( array $ids ) {
	$inbox = (array) get_option( DAW_LLM_INBOX, array() );
	$n     = 0;
	foreach ( $ids as $id ) {
		if ( isset( $inbox[ (int) $id ] ) ) {
			$inbox[ (int) $id ]['written'] = true;
			++$n;
		}
	}
	update_option( DAW_LLM_INBOX, $inbox, false );
	return $n;
}

/**
 * Inbox rows Claude has not written yet, a page at a time. The whole option
 * grows past what a REST reader can take in one response.
 */
function daw_llm_inbox_pending( $n = 25, $page = 1 ) {
	$n     = max( 1, min( 50, $n ) );
	$page  = max( 1, $page );
	$rows  = array();
	foreach ( (array) get_option( DAW_LLM_INBOX, array() ) as $id => $r ) {
		if ( empty( $r['written'] ) ) {
			$rows[ (int) $id ] = array(
				'name'       => get_the_title( (int) $id ),
				'source'     => $r['source'] ?? '',
				'at'         => $r['at'] ?? 0,
				'facts'      => $r['facts'] ?? array(),
				'unverified' => $r['unverified'] ?? array(),
			);
		}
	}
	return array(
		'total'  => count( $rows ),
		'page'   => $page,
		'queued' => count( (array) get_option( 'daw_llm_queue', array() ) ),
		'items'  => array_slice( $rows, ( $page - 1 ) * $n, $n, true ),
	);
}

add_action( 'admin_menu', 'daw_llm_menu' );
function daw_llm_menu() {
	add_submenu_page( 'tools.php', 'DAW: Dış AI', 'DAW: Dış AI', 'manage_options', 'daw-dis-ai', 'daw_llm_admin_page' );
}

function daw_llm_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$tabs = array( 'chatgpt' => 'ChatGPT', 'gemini_app' => 'Gemini' );
	$src  = sanitize_key( (string) ( $_GET['src'] ?? 'chatgpt' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$src  = isset( $tabs[ $src ] ) ? $src : 'chatgpt';
	$n    = isset( $_POST['daw_llm_n'] ) ? (int) $_POST['daw_llm_n'] : 25; // phpcs:ignore WordPress.Security.NonceVerification
	$result = null;
	if ( isset( $_POST['daw_llm_answer'] ) && check_admin_referer( 'daw_llm_manual' ) ) {
		$result = daw_llm_manual_verify( wp_unslash( (string) $_POST['daw_llm_answer'] ), $src );
	}
	$pr = daw_llm_manual_prompt( daw_llm_manual_ids( $src, $n, isset( $_POST['daw_llm_new'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	echo '<div class="wrap"><h1>Dış AI: elle araştırma</h1><h2 class="nav-tab-wrapper">';
	foreach ( $tabs as $k => $label ) {
		printf( '<a class="nav-tab%s" href="%s">%s</a>', $k === $src ? ' nav-tab-active' : '', esc_url( add_query_arg( array( 'page' => 'daw-dis-ai', 'src' => $k ), admin_url( 'tools.php' ) ) ), esc_html( $label ) );
	}
	echo '</h2>';
	printf( '<p>Bu sekmedeki kişiler yalnız %s için ayrıldı; öbür sekme başka kişileri verir, ikisini aynı anda çalıştırabilirsin. 1) Metni kopyala, %s\'de <strong>web araması açıkken</strong> yapıştır. 2) Cevabın tamamını alttaki kutuya yapıştırıp "Doğrula"ya bas. Site her alıntıyı kaynak sayfada arar; geçemeyen atılır, süreye sığmayanlar birkaç dakika içinde arka planda bitirilir. Geçenleri Claude kurallara göre kontrol edip yazar; buradan siteye hiçbir şey yazılmaz.</p>', esc_html( $tabs[ $src ] ), esc_html( $tabs[ $src ] ) );
	echo '<form method="post"><label>Kaç kişi: <input type="number" name="daw_llm_n" min="1" max="' . (int) DAW_LLM_BATCH . '" value="' . esc_attr( (string) $n ) . '"></label> <button class="button" name="daw_llm_new" value="1">Yeni liste</button></form>';
	echo '<p><strong>Kişiler:</strong> ' . esc_html( implode( ', ', array_map( static fn( $i ) => get_the_title( $i ) . ' (' . $i . ')', $pr['ids'] ) ) ) . '</p>';
	echo '<textarea readonly rows="14" style="width:100%;font-family:monospace" onclick="this.select()">' . esc_textarea( $pr['prompt'] ) . '</textarea>';
	echo '<h2>Cevabı yapıştır</h2><form method="post">';
	wp_nonce_field( 'daw_llm_manual' );
	echo '<textarea name="daw_llm_answer" rows="10" style="width:100%;font-family:monospace"></textarea><p><button class="button button-primary">Doğrula</button></p></form>';
	if ( null !== $result ) {
		echo '<h2>Sonuç</h2>';
		if ( ! $result ) {
			echo '<p>Cevapta okunabilir kayıt yok (JSON bozuk ya da ID yanlış).</p>';
		}
		foreach ( $result as $id => $r ) {
			printf( '<h3>%s (%d): %d doğrulandı, %d atıldı%s</h3><ul>', esc_html( $r['name'] ), (int) $id, count( $r['facts'] ), count( $r['unverified'] ), ! empty( $r['queued'] ) ? ', ' . (int) $r['queued'] . ' sırada' : '' );
			foreach ( $r['facts'] as $f ) {
				printf( '<li>✓ %s: <strong>%s</strong> <a href="%s" target="_blank" rel="noopener">kaynak</a> (%s)</li>', esc_html( $f['field'] ), esc_html( $f['value'] ), esc_url( $f['url'] ), esc_html( $f['checked'] ?? '' ) );
			}
			foreach ( $r['unverified'] as $f ) {
				printf( '<li style="color:#996">✗ %s: %s (%s)</li>', esc_html( $f['field'] ), esc_html( $f['value'] ), esc_html( $f['why'] ) );
			}
			echo '</ul>';
		}
	}
	$inbox = (array) get_option( DAW_LLM_INBOX, array() );
	$wait  = count( array_filter( $inbox, static fn( $r ) => empty( $r['written'] ) && ! empty( $r['facts'] ) ) );
	$queued = count( (array) get_option( 'daw_llm_queue', array() ) );
	printf( '<p><em>Claude\'un kontrolünü bekleyen: %d kişi. Arka planda doğrulanan: %d kişi.</em></p></div>', (int) $wait, (int) $queued );
}

/* ================================================================ route === */

add_action( 'rest_api_init', 'daw_llm_routes' );
function daw_llm_routes() {
	register_rest_route(
		'daw/v1',
		'/llm',
		array(
			'methods'             => 'POST',
			'callback'            => 'daw_llm_rest',
			'permission_callback' => function_exists( 'daw_rb_admin' ) ? 'daw_rb_admin' : static fn() => current_user_can( 'manage_options' ),
		)
	);
}

function daw_llm_rest( WP_REST_Request $req ) {
	$task  = (string) $req->get_param( 'task' );
	$id    = (int) $req->get_param( 'id' );
	$input = $req->get_param( 'input' );
	$input = is_array( $input ) ? $input : array();
	$max   = max( 200, min( 8000, (int) ( $req->get_param( 'max_tokens' ) ?: 3000 ) ) );
	if ( ! in_array( $task, DAW_LLM_TASKS, true ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'fallback' => false, 'reason' => 'bad_task', 'tasks' => DAW_LLM_TASKS ), 400 );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 240 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	if ( 'ping' === $task ) {
		return new WP_REST_Response( daw_llm_task_ping( $input ) );
	}
	if ( 'prompt' === $task ) {
		$ids = array_map( 'intval', (array) ( $input['ids'] ?? array() ) );
		return new WP_REST_Response( array( 'ok' => true, 'result' => daw_llm_manual_prompt( $ids ? $ids : daw_llm_manual_next( (int) ( $input['n'] ?? 5 ) ) ) ) );
	}
	if ( 'verify' === $task ) {
		return new WP_REST_Response( array( 'ok' => true, 'result' => daw_llm_manual_verify( $input, sanitize_key( (string) ( $input['source'] ?? 'manual' ) ) ) ) );
	}
	if ( 'done' === $task ) {
		return new WP_REST_Response( array( 'ok' => true, 'marked' => daw_llm_manual_done( array_map( 'intval', (array) ( $input['ids'] ?? array() ) ) ) ) );
	}
	if ( 'inbox' === $task ) {
		return new WP_REST_Response( array( 'ok' => true ) + daw_llm_inbox_pending( (int) ( $input['n'] ?? 25 ), (int) ( $input['page'] ?? 1 ) ) );
	}
	if ( 'config' === $task ) {
		return new WP_REST_Response( daw_llm_task_config( (array) ( $input['models'] ?? array() ) ) );
	}
	if ( ! $id || ! get_post( $id ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'fallback' => false, 'reason' => 'bad_input', 'note' => 'id yok' ), 400 );
	}
	$ck = 'daw_llm_' . md5( $task . '|' . $id . '|' . wp_json_encode( $input ) . '|' . $max );
	$c  = get_transient( $ck );
	if ( is_array( $c ) && ! $req->get_param( 'fresh' ) ) {
		$c['cached'] = true;
		return new WP_REST_Response( $c );
	}
	$kind = 'research_person' === $task ? 'person' : 'title';
	$out  = daw_llm_task_research( $kind, $id, $input, $max );
	$out['cached'] = false;
	daw_llm_log_add(
		array(
			'at'       => time(),
			'task'     => $task,
			'id'       => $id,
			'provider' => (string) ( $out['provider'] ?? '' ),
			'model'    => (string) ( $out['model'] ?? '' ),
			'tokens'   => (int) ( $out['tokens'] ?? 0 ),
			'ok'       => ! empty( $out['ok'] ),
			'reason'   => (string) ( $out['reason'] ?? '' ),
			'facts'    => count( (array) ( $out['result']['facts'] ?? array() ) ),
			'unver'    => count( (array) ( $out['result']['unverified'] ?? array() ) ),
		)
	);
	if ( ! empty( $out['ok'] ) ) {
		set_transient( $ck, $out, 7 * DAY_IN_SECONDS );
	}
	return new WP_REST_Response( $out );
}

/* ====================================================== control centre === */

/** Card: keys present, today's use per provider, success rate, last runs. */
function daw_llm_card( $kc ) {
	$pv   = daw_llm_providers();
	$u    = daw_llm_usage();
	$log  = (array) get_option( DAW_LLM_LOG, array() );
	$keys = array_filter( array_keys( $pv ), static fn( $p ) => '' !== daw_llm_key( $p ) );
	daw_kc_card_open( 'llm', '✦', 'Dış AI modelleri', $keys ? 'info' : 'warn', true );
	echo '<ul class="kc-checks">';
	daw_kc_check(
		(bool) $keys,
		$keys
			? sprintf( 'Anahtarı olan: %s. Sıra: Gemini, OpenRouter, NVIDIA; biri kredi/limit/hata verirse sıradakine geçer, hepsi biterse iş Claude\'a döner.', implode( ', ', array_map( static fn( $p ) => $pv[ $p ]['name'], $keys ) ) )
			: 'Hiç anahtar yok: wp-config.php\'ye DAW_GEMINI_KEY, DAW_OPENROUTER_KEY, DAW_NVIDIA_KEY eklenince çalışır.',
		$keys ? 'info' : 'warn'
	);
	$ok   = count( array_filter( $log, static fn( $r ) => ! empty( $r['ok'] ) ) );
	$fct  = array_sum( wp_list_pluck( $log, 'facts' ) );
	$unv  = array_sum( wp_list_pluck( $log, 'unver' ) );
	if ( $log ) {
		daw_kc_check( true, sprintf( 'Son %s araştırmanın %s tanesi dış modelde bitti. Doğrulanan bilgi %s, kaynakta tutmadığı için atılan %s.', daw_kc_n( count( $log ) ), daw_kc_n( $ok ), daw_kc_n( $fct ), daw_kc_n( $unv ) ), 'info' );
	}
	echo '</ul>';
	echo '<div class="kc-scroll"><table class="kc-mini"><thead><tr><th>Sağlayıcı</th><th>Model</th><th>Bugün istek</th><th>Başarılı</th><th>Token</th><th>Durum</th></tr></thead><tbody>';
	foreach ( $pv as $p => $cfg ) {
		$c = $u['p'][ $p ] ?? array();
		printf(
			'<tr><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td></tr>',
			esc_html( $cfg['name'] ),
			esc_html( $cfg['model'] ),
			(int) ( $c['req'] ?? 0 ),
			(int) ( $c['ok'] ?? 0 ),
			esc_html( daw_kc_n( (int) ( $c['tok'] ?? 0 ) ) ),
			esc_html( '' === daw_llm_key( $p ) ? 'anahtar yok' : ( ! empty( $c['out'] ) ? $c['out'] . ', bugün atlanıyor' : ( ! empty( $c['out_web'] ) ? 'web araması kredisi bitti, araştırmada atlanıyor' : ( $cfg['search'] ? 'hazır' : 'hazır (araştırma yok: web araması yapamıyor)' ) ) ) )
		);
	}
	echo '</tbody></table></div>';
	if ( $log ) {
		echo '<div class="kc-scroll"><table class="kc-mini"><thead><tr><th>Zaman</th><th>İş</th><th>Kayıt</th><th>Sağlayıcı</th><th>Sonuç</th></tr></thead><tbody>';
		foreach ( array_reverse( array_slice( $log, -10 ) ) as $r ) {
			printf(
				'<tr class="is-%s"><td>%s</td><td>%s</td><td><a href="%s">%s</a></td><td>%s</td><td>%s</td></tr>',
				$r['ok'] ? 'info' : 'warn',
				esc_html( wp_date( 'd.m H:i', (int) $r['at'] ) ),
				esc_html( $r['task'] ),
				esc_url( (string) get_edit_post_link( (int) $r['id'] ) ),
				esc_html( get_the_title( (int) $r['id'] ) ),
				esc_html( $r['provider'] ? $r['provider'] : '-' ),
				esc_html( $r['ok'] ? sprintf( '%d doğrulandı, %d atıldı', $r['facts'], $r['unver'] ) : 'Claude\'a döndü: ' . $r['reason'] )
			);
		}
		echo '</tbody></table></div>';
	}
	daw_kc_card_close();
}
