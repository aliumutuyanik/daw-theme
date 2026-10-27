<?php
/**
 * Discover Asian World: the chat's own door into the site's data.
 *
 * The daily work from the chat (the inbox, audit notes, field values, the
 * control-centre report) used to go through Royal MCP's option and meta
 * tools. These routes do the same through any connector that can call the
 * WordPress REST API (Easy MCP AI's wp_rest_read / wp_rest_write), so the
 * work does not depend on one connector plugin being active.
 *
 * Administrators only. What can be touched is narrow on purpose:
 * - options: the names the site already lists as readable (the
 *   royal_mcp_readable_options list) and the site's own daw_* names, never
 *   anything that looks like a key, token or password;
 * - post meta: the site's own keys (daw_* and _daw_*) only.
 * Values are stored exactly as sent (slashes kept), which also ends the old
 * problem of backslashes and "\n" being eaten on the way in.
 *
 *   GET  /daw/v1/opt?name=daw_kc_report
 *   GET  /daw/v1/kc-brief                (control centre, only what is not fine)
 *   GET  /daw/v1/idx[?why=orphan&n=40]   (why pages stay out of Google's index)
 *   POST /daw/v1/verify {id, note}       (stamp a record when its audit has no red/empty line)
 *   GET  /daw/v1/files                   (a fingerprint of every installed file)
 *   GET  /daw/v1/optfind?w=easy          (connector settings, secrets blanked)
 *   GET  /daw/v1/page?path=/x/&ua=mobile&part=0   (the page as a reader gets it,
 *        gzip+base64 in parts under 70 KB, for measuring it in a real browser)
 *   GET  /daw/v1/meta?id=123&key=_daw_audit
 *   POST /daw/v1/meta  {"id": 123, "set": {"daw_x": "..."}, "del": ["_daw_y"]}
 *   GET  /daw/v1/brief?id=123[&keys=a,b]  (what the audit wants, compact)
 *   POST /daw/v1/reaudit {"id": 123}      (audit now, returns the brief)
 *   POST /daw/v1/inbox {"box": {...}}   (same commands as mcp-inbox.php)
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

function daw_rb_secret_name( $name ) {
	return (bool) preg_match( '/token|secret|pass|_key$|apikey|api_key|salt|hash|nonce|cred|private|client_id/i', (string) $name );
}

function daw_rb_opt_readable( $name ) {
	$name = (string) $name;
	if ( '' === $name || daw_rb_secret_name( $name ) ) {
		return false;
	}
	if ( 0 === strpos( $name, 'daw_' ) ) {
		return true;
	}
	if ( daw_rb_opt_writable( $name ) ) { // the connector's own switches, read before written
		return true;
	}
	$list = (array) apply_filters( 'royal_mcp_readable_options', array() );
	return in_array( $name, $list, true );
}

/** Written only for the connector's own tool switches, after reading them first. */
function daw_rb_opt_writable( $name ) {
	return ! daw_rb_secret_name( $name ) && (bool) preg_match( '/^(easy_mcp_ai|easymcpai|emcp)[a-z0-9_\-]*$/i', (string) $name );
}

function daw_rb_meta_ok( $key ) {
	// The site's own keys, plus the three SEO keys the SEO engine reads
	// (it keeps Rank Math's key names, seo-engine.php).
	return (bool) preg_match( '/^(_?daw_[a-z0-9_]+|rank_math_(title|description|focus_keyword))$/i', (string) $key );
}

function daw_rb_admin() {
	return current_user_can( 'manage_options' );
}

add_action( 'rest_api_init', 'daw_rb_routes' );

/*
 * GET /daw/v1/fresh (1.75.72): the records whose facts move by the week.
 * Running or upcoming shows, and shows that ended or started in the last
 * 60 days, with what the page says about them, so the chat can update
 * aired dates, status, episodes and ratings without opening every record.
 */
add_action( 'rest_api_init', static function () {
	register_rest_route(
		'daw/v1',
		'/fresh',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'daw_rb_admin',
			'callback'            => static function () {
				global $wpdb;
				$rows = $wpdb->get_results( "SELECT p.ID id, p.post_title t, MAX(CASE WHEN m.meta_key='daw_status' THEN m.meta_value END) st, MAX(CASE WHEN m.meta_key='daw_aired_start' THEN m.meta_value END) a, MAX(CASE WHEN m.meta_key='daw_aired_end' THEN m.meta_value END) e, MAX(CASE WHEN m.meta_key='daw_episodes' THEN m.meta_value END) ep, MAX(CASE WHEN m.meta_key='daw_views_30' THEN m.meta_value END) v FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('daw_status','daw_aired_start','daw_aired_end','daw_episodes','daw_views_30') WHERE p.post_status='publish' AND p.post_type='post' GROUP BY p.ID", ARRAY_A ); // phpcs:ignore WordPress.DB
				$now = time();
				$out = array();
				foreach ( (array) $rows as $r ) {
					$st = strtolower( (string) $r['st'] );
					$a  = (int) $r['a'];
					$e  = (int) $r['e'];
					$hot = in_array( $st, array( 'ongoing', 'upcoming' ), true ) || ( $a && abs( $now - $a ) < 60 * DAY_IN_SECONDS ) || ( $e && abs( $now - $e ) < 60 * DAY_IN_SECONDS ) || ( $a > $now );
					if ( ! $hot ) {
						continue;
					}
					$out[] = array(
						'id'     => (int) $r['id'],
						'title'  => (string) $r['t'],
						'status' => $st,
						'start'  => $a ? gmdate( 'Y-m-d', $a ) : '',
						'end'    => $e ? gmdate( 'Y-m-d', $e ) : '',
						'eps'    => (int) $r['ep'],
						'listed' => function_exists( 'daw_ae_rows' ) ? (int) daw_ae_rows( (int) $r['id'], 'daw_ep_no' ) : 0,
						'views'  => (int) $r['v'],
					);
				}
				usort( $out, static fn( $x, $y ) => $y['views'] <=> $x['views'] );
				return daw_rb_nocache( array( 'n' => count( $out ), 'rows' => $out ) );
			},
		)
	);
	// AI PHRASING SCAN (1.75.83, Umut 23 Oct 2026): read-only. Runs the audit's
	// AI-tone checks (rules 50/52 word list, sentence shapes, long dash) over
	// every text field of every record and says which records and phrases
	// hit, with the record's current level. Nothing is written.
	register_rest_route(
		'daw/v1',
		'/aiscan',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'daw_rb_admin',
			'callback'            => static function ( WP_REST_Request $r ) {
				global $wpdb;
				if ( ! function_exists( 'daw_wc_rows' ) || ! function_exists( 'daw_ae_text_issues' ) ) {
					return daw_rb_nocache( array( 'error' => 'audit not loaded' ) );
				}
				if ( $r->get_param( 'build_tpl' ) && function_exists( 'daw_voice_tpl_build' ) ) {
					$fr = daw_voice_tpl_build();
					return daw_rb_nocache( array( 'frames' => count( $fr ), 'top' => array_slice( $fr, 0, 60, true ) ) );
				}
				$rows = daw_wc_rows();
				$ids  = array_keys( $rows );
				$off  = max( 0, (int) ( $r->get_param( 'offset' ) ?? 0 ) );
				$lim  = max( 1, min( 900, (int) ( $r->get_param( 'limit' ) ?? 900 ) ) );
				$ids  = array_slice( $ids, $off, $lim );
				if ( ! $ids ) {
					return daw_rb_nocache( array( 'total' => count( $rows ), 'rows' => array() ) );
				}
				$in   = implode( ',', array_map( 'intval', $ids ) );
				$meta = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND ( meta_key LIKE 'daw\\_%' OR meta_key IN ('rank_math_title','rank_math_description') )", ARRAY_A ); // phpcs:ignore WordPress.DB
				$body = $wpdb->get_results( "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID IN ($in)", OBJECT_K ); // phpcs:ignore WordPress.DB
				$txt  = array();
				foreach ( (array) $meta as $m ) {
					$v = maybe_unserialize( $m['meta_value'] );
					$flat = array();
					if ( is_array( $v ) ) {
						array_walk_recursive( $v, static function ( $x ) use ( &$flat ) { if ( is_string( $x ) ) { $flat[] = $x; } } );
					} elseif ( is_string( $v ) ) {
						$flat = array( $v );
					}
					foreach ( $flat as $f ) {
						if ( strlen( $f ) > 25 && preg_match( '/[a-z]{3,}\s+[a-z]{3,}/i', $f ) ) {
							$txt[ (int) $m['post_id'] ][] = array( $m['meta_key'], $f );
						}
					}
				}
				foreach ( $body as $bid => $b ) {
					$txt[ (int) $bid ][] = array( 'post_content', (string) $b->post_content );
					$txt[ (int) $bid ][] = array( 'post_excerpt', (string) $b->post_excerpt );
				}
				$out = array();
				$by_phrase = array();
				$by_level  = array();
				foreach ( $ids as $id ) {
					$hits = array();
					foreach ( $txt[ $id ] ?? array() as $t ) {
						foreach ( ( function_exists( 'daw_voice_issues' ) ? daw_voice_issues( $t[1], $t[0], (string) ( $rows[ $id ]['title'] ?? '' ) ) : array() ) as $iss ) {
							$issue  = $iss[1] . ' (madde ' . $iss[0] . ')';
							$hits[] = $t[0] . ': ' . $issue;
							$by_phrase[ $issue ] = ( $by_phrase[ $issue ] ?? 0 ) + 1;
						}
					}
					if ( $hits ) {
						$lv = (string) ( $rows[ $id ]['level'] ?? '' );
						$by_level[ $lv ] = ( $by_level[ $lv ] ?? 0 ) + 1;
						$out[] = array( 'id' => $id, 'title' => (string) $rows[ $id ]['title'], 'level' => $lv, 'hits' => array_values( array_unique( $hits ) ) );
					}
				}
				arsort( $by_phrase );
				$levels = array();
				foreach ( $ids as $id ) {
					$lv = (string) ( $rows[ $id ]['level'] ?? '' );
					$levels[ $lv ] = ( $levels[ $lv ] ?? 0 ) + 1;
				}
				return daw_rb_nocache( array( 'total' => count( $rows ), 'scanned' => count( $ids ), 'levels' => $levels, 'with_hits' => count( $out ), 'hits_by_level' => $by_level, 'by_phrase' => $by_phrase, 'rows' => $out ) );
			},
		)
	);
	// WHICH RECORDS STILL NEED THE FULL UBERSUGGEST LIST (1.75.74)
	// The daily queue is ordered by the main keyword's search volume, so
	// every record without the full research comes back with the biggest
	// volume its old list holds (0 when it has no list) and its views.
	register_rest_route(
		'daw/v1',
		'/kwq',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'daw_rb_admin',
			'callback'            => static function ( WP_REST_Request $r ) {
				global $wpdb;
				$ids = function_exists( 'daw_audit_record_ids' ) && function_exists( 'daw_ae_all_types' ) ? array_map( 'intval', (array) daw_audit_record_ids( daw_ae_all_types() ) ) : array();
				if ( ! $ids ) {
					return daw_rb_nocache( array( 'total' => 0 ) );
				}
				$in   = implode( ',', $ids );
				$meta = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN ('_daw_kw','_daw_kw_at','_daw_yok','daw_views_30')", ARRAY_A ); // phpcs:ignore WordPress.DB
				$m = array();
				foreach ( (array) $meta as $row ) {
					$m[ (int) $row['post_id'] ][ $row['meta_key'] ] = $row['meta_value'];
				}
				$titles = $wpdb->get_results( "SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE ID IN ($in)", OBJECT_K ); // phpcs:ignore WordPress.DB
				$since = defined( 'DAW_KW_FULL_SINCE' ) ? DAW_KW_FULL_SINCE : '2026-10-15';
				$todo  = array();
				$thin  = array();
				$full  = 0;
				foreach ( $ids as $id ) {
					$x    = $m[ $id ] ?? array();
					$kat  = (string) ( $x['_daw_kw_at'] ?? '' );
					$ok   = '' !== $kat && $kat >= $since;
					if ( ! $ok ) {
						$yok = maybe_unserialize( $x['_daw_yok'] ?? '' );
						$yok = is_string( $yok ) ? json_decode( $yok, true ) : $yok;
						$nt  = is_array( $yok ) ? (string) ( $yok['_daw_kw'] ?? '' ) : '';
						if ( preg_match( '/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/', $nt, $km ) ) {
							$ok = sprintf( '%04d-%02d-%02d', $km[3], $km[2], $km[1] ) >= $since;
						}
					}
					if ( $ok ) {
						$full++;
						// 1.75.86: a researched list can still be thin (seeded with the
						// legal name only, a stage name never tried). Fewer than ten
						// target patterns is listed for a second seed.
						$kw0 = maybe_unserialize( $x['_daw_kw'] ?? '' );
						$kw0 = is_string( $kw0 ) ? json_decode( $kw0, true ) : $kw0;
						$tg  = 0;
						$tk  = '';
						$tv  = 0;
						foreach ( is_array( $kw0 ) ? $kw0 : array() as $k ) {
							if ( is_array( $k ) && empty( $k['x'] ) && empty( $k['n'] ) && (int) ( $k['vol'] ?? 0 ) > 0 ) {
								$tg++;
								if ( (int) $k['vol'] > $tv ) {
									$tv = (int) $k['vol'];
									$tk = (string) ( $k['kw'] ?? '' );
								}
							}
						}
						if ( $tg < 10 ) {
							$thin[] = array(
								'id'    => $id,
								'title' => isset( $titles[ $id ] ) ? (string) $titles[ $id ]->post_title : '',
								'n'     => $tg,
								'kw'    => $tk,
								'vol'   => $tv,
							);
						}
						continue;
					}
					$kw = maybe_unserialize( $x['_daw_kw'] ?? '' );
					$kw = is_string( $kw ) ? json_decode( $kw, true ) : $kw;
					$top = 0;
					$kws = '';
					foreach ( is_array( $kw ) ? $kw : array() as $k ) {
						$v = is_array( $k ) ? (int) ( $k['vol'] ?? 0 ) : 0;
						if ( $v > $top ) {
							$top = $v;
							$kws = (string) ( $k['kw'] ?? '' );
						}
					}
					$todo[] = array(
						'id'    => $id,
						'title' => isset( $titles[ $id ] ) ? (string) $titles[ $id ]->post_title : '',
						'type'  => isset( $titles[ $id ] ) ? (string) $titles[ $id ]->post_type : '',
						'vol'   => $top,
						'kw'    => $kws,
						'views' => (int) ( $x['daw_views_30'] ?? 0 ),
					);
				}
				usort( $todo, static fn( $a, $b ) => array( $b['vol'], $b['views'] ) <=> array( $a['vol'], $a['views'] ) );
				$n = max( 0, (int) ( $r->get_param( 'n' ) ?? 200 ) );
				return daw_rb_nocache( array( 'total' => count( $ids ), 'full' => $full, 'todo' => count( $todo ), 'no_list' => count( array_filter( $todo, static fn( $t ) => 0 === $t['vol'] ) ), 'rows' => array_slice( $todo, 0, $n ), 'thin' => count( $thin ), 'thin_rows' => $r->get_param( 'thin' ) ? $thin : array() ) );
			},
		)
	);
} );
function daw_rb_routes() {
	register_rest_route(
		'daw/v1',
		'/opt',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => 'daw_rb_admin',
				'callback'            => static function ( WP_REST_Request $r ) {
					$name = (string) $r->get_param( 'name' );
					if ( ! daw_rb_opt_readable( $name ) ) {
						return new WP_Error( 'daw_opt', 'Bu ayar okunamaz: ' . $name, array( 'status' => 403 ) );
					}
					return daw_rb_nocache( array( 'name' => $name, 'value' => get_option( $name, null ) ) );
				},
			),
		)
	);

	// Madde 73: a record's Search Console queries with their place on the page
	// and their state (ok, var, skip, miss), the rows the audit panel shows.
	register_rest_route(
		'daw/v1',
		'/gsc',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'daw_rb_admin',
			'callback'            => static function ( WP_REST_Request $r ) {
				$id = (int) $r->get_param( 'id' );
				if ( ! get_post( $id ) || ! function_exists( 'daw_ae_gsc_rows' ) ) {
					return new WP_Error( 'daw_gsc', 'Kayıt yok ya da denetim yüklenmedi: ' . $id, array( 'status' => 400 ) );
				}
				return daw_rb_nocache( array( 'id' => $id, 'rows' => daw_ae_gsc_rows( $id ) ) );
			},
		)
	);

	register_rest_route(
		'daw/v1',
		'/kc-brief',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'daw_rb_admin',
			'callback'            => static function () {
				return daw_rb_nocache( function_exists( 'daw_kc_brief' ) ? daw_kc_brief() : array() );
			},
		)
	);

	// Releases are built in more than one place. Two zips can carry the same
	// version number and different code, so before a new one is built the live
	// files are compared with its base, file by file, rather than trusted by
	// the number on the plugin screen.
	register_rest_route(
		'daw/v1',
		'/files',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'daw_rb_admin',
			'callback'            => static function () {
				$out = array(
					'version' => defined( 'DAW_VERSION' ) ? DAW_VERSION : '',
					'theme'   => (string) wp_get_theme()->get( 'Version' ),
					'files'   => array(),
				);
				$roots = array(
					'plugin' => dirname( __DIR__ ),
					'theme'  => get_stylesheet_directory(),
				);
				foreach ( $roots as $tag => $root ) {
					$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
					foreach ( $it as $f ) {
						if ( preg_match( '/\.(php|js|css)$/', $f->getFilename() ) ) {
							$rel = $tag . '/' . ltrim( str_replace( '\\', '/', substr( $f->getPathname(), strlen( $root ) ) ), '/' );
							$out['files'][ $rel ] = substr( (string) md5_file( $f->getPathname() ), 0, 10 );
						}
					}
				}
				ksort( $out['files'] );
				return daw_rb_nocache( $out );
			},
		)
	);

	register_rest_route(
		'daw/v1',
		'/idx',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'daw_rb_admin',
			'callback'            => static function ( WP_REST_Request $r ) {
				$doc = function_exists( 'daw_idx_doc' ) ? daw_idx_doc() : array();
				$why = sanitize_key( (string) $r->get_param( 'why' ) );
				$n   = max( 1, min( 40, (int) ( $r->get_param( 'n' ) ?: 15 ) ) );
				if ( $doc ) {
					$rows        = (array) $doc['rows'];
					$doc['rows'] = '' !== $why ? array( $why => array_slice( (array) ( $rows[ $why ] ?? array() ), 0, $n ) ) : array_map( static fn( $x ) => array_slice( (array) $x, 0, 3 ), $rows );
				}
				return daw_rb_nocache( $doc );
			},
		)
	);

	register_rest_route(
		'daw/v1',
		'/optfind',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'daw_rb_admin',
			'callback'            => static function ( WP_REST_Request $r ) {
				return daw_rb_nocache( array( 'rows' => function_exists( 'daw_mcph_opt_find' ) ? daw_mcph_opt_find( (string) $r->get_param( 'w' ) ) : array() ) );
			},
		)
	);

	register_rest_route(
		'daw/v1',
		'/meta',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => 'daw_rb_admin',
				'callback'            => static function ( WP_REST_Request $r ) {
					$id  = (int) $r->get_param( 'id' );
					$key = (string) $r->get_param( 'key' );
					if ( ! get_post( $id ) || ! daw_rb_meta_ok( $key ) ) {
						return new WP_Error( 'daw_meta', 'Kayıt ya da anahtar uygun değil', array( 'status' => 400 ) );
					}
					$val = get_post_meta( $id, $key, true );
					// SLICES OF A BIG LIST (1.75.70)
					// A long series' TMDB episode list (_daw_tmdb_eps on Giant,
					// 684 rows, over 1 MB with the Korean text) could not be read
					// at all: the answer was cut at the bridge's size cap. offset
					// and limit return a window of a list value, cols keeps only
					// the named fields of each row; total says how many rows exist.
					$off  = $r->get_param( 'offset' );
					$lim  = $r->get_param( 'limit' );
					$cols = array_filter( array_map( 'trim', explode( ',', (string) $r->get_param( 'cols' ) ) ), 'strlen' );
					if ( is_array( $val ) && ( null !== $off || null !== $lim || $cols ) ) {
						$list  = array_values( $val );
						$total = count( $list );
						$off   = max( 0, (int) $off );
						$lim   = null === $lim ? 50 : max( 1, min( 500, (int) $lim ) );
						$part  = array_slice( $list, $off, $lim );
						if ( $cols ) {
							$part = array_map(
								static function ( $row ) use ( $cols ) {
									return is_array( $row ) ? array_intersect_key( $row, array_flip( $cols ) ) : $row;
								},
								$part
							);
						}
						return daw_rb_nocache( array( 'id' => $id, 'key' => $key, 'total' => $total, 'offset' => $off, 'limit' => $lim, 'value' => $part ) );
					}
					return daw_rb_nocache( array( 'id' => $id, 'key' => $key, 'value' => $val ) );
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => 'daw_rb_admin',
				'callback'            => static function ( WP_REST_Request $r ) {
					$id = (int) $r->get_param( 'id' );
					if ( ! get_post( $id ) ) {
						return new WP_Error( 'daw_meta', 'Kayıt yok: ' . $id, array( 'status' => 404 ) );
					}
					$set  = (array) $r->get_param( 'set' );
					$del  = (array) $r->get_param( 'del' );
					$out  = array( 'id' => $id, 'set' => array(), 'del' => array(), 'refused' => array() );
					foreach ( $set as $k => $v ) {
						if ( ! daw_rb_meta_ok( $k ) ) {
							$out['refused'][] = $k;
							continue;
						}
						// _daw_yok and _daw_ok are objects of notes, one per field.
						// Agents kept sending only their new keys and wiped the
						// rest (Six Siblings, Country Princess). They are merged:
						// sent keys replace, a null value removes a key, and
						// "replace_obj": true restores the old whole-object write
						// (1.75.72).
						if ( in_array( $k, array( '_daw_yok', '_daw_ok' ), true ) && ! $r->get_param( 'replace_obj' ) ) {
							$in  = is_string( $v ) ? json_decode( $v, true ) : $v;
							$cur = get_post_meta( $id, $k, true );
							$cur = is_string( $cur ) ? json_decode( $cur, true ) : $cur;
							if ( is_array( $in ) ) {
								$cur = is_array( $cur ) ? $cur : array();
								foreach ( $in as $ik => $iv ) {
									if ( null === $iv ) {
										unset( $cur[ $ik ] );
									} else {
										$cur[ $ik ] = $iv;
									}
								}
								$v = $cur;
								$out['merged'][] = $k;
							}
						}
						// Madde 80: invisible characters never reach the page.
						if ( function_exists( 'daw_strip_watermarks' ) ) {
							if ( is_string( $v ) ) {
								$v = daw_strip_watermarks( $v );
							} elseif ( is_array( $v ) ) {
								array_walk_recursive( $v, static function ( &$x ) { if ( is_string( $x ) ) { $x = daw_strip_watermarks( $x ); } } );
							}
						}
						update_post_meta( $id, $k, wp_slash( $v ) );
						$out['set'][] = $k;
						// The day the keyword list was written: the İşler list
						// shows it so research is not repeated (1.75.70).
						if ( '_daw_kw' === $k ) {
							update_post_meta( $id, '_daw_kw_at', wp_date( 'Y-m-d' ) );
						}
					}
					foreach ( $del as $k ) {
						if ( ! daw_rb_meta_ok( $k ) ) {
							$out['refused'][] = $k;
							continue;
						}
						delete_post_meta( $id, (string) $k );
						$out['del'][] = $k;
					}
					// ONE ROW OF A LIST, NOT THE WHOLE LIST (1.75.45)
					// A repeater such as a group's discography (daw_rel, 118
					// rows, 66 KB on BTS) had to be sent back whole to fix one
					// duplicate. rows: { key, merge: { index: { field: value } },
					// drop: [ index, ... ] }, indexes as GET returned them;
					// merge runs first, then drop, then the list is renumbered.
					$rows = (array) $r->get_param( 'rows' );
					if ( ! empty( $rows['key'] ) ) {
						$rk   = (string) $rows['key'];
						$list = get_post_meta( $id, $rk, true );
						if ( ! daw_rb_meta_ok( $rk ) || ! is_array( $list ) ) {
							$out['refused'][] = $rk;
						} else {
							foreach ( (array) ( $rows['merge'] ?? array() ) as $i => $fields ) {
								if ( isset( $list[ $i ] ) && is_array( $list[ $i ] ) && is_array( $fields ) ) {
									$list[ $i ] = array_merge( $list[ $i ], $fields );
								}
							}
							foreach ( (array) ( $rows['drop'] ?? array() ) as $i ) {
								unset( $list[ $i ] );
							}
							update_post_meta( $id, $rk, wp_slash( array_values( $list ) ) );
							$out['rows'] = array( 'key' => $rk, 'count' => count( $list ) );
						}
					}
					// ADD ROWS AT THE END OF A LIST (1.75.73)
					// A long show (Running Man, 820 episodes) needed the whole daw_ep_*
					// list sent back to add one week. append: { key: [ value, ... ] }
					// pushes the values onto the stored list; a missing key starts one.
					foreach ( (array) $r->get_param( 'append' ) as $ak => $avals ) {
						$ak  = (string) $ak;
						$cur = get_post_meta( $id, $ak, true );
						if ( ! daw_rb_meta_ok( $ak ) || ! is_array( $avals ) || ( '' !== $cur && ! is_array( $cur ) ) ) {
							$out['refused'][] = $ak;
							continue;
						}
						$cur = is_array( $cur ) ? array_values( $cur ) : array();
						foreach ( $avals as $av ) {
							$cur[] = $av;
						}
						update_post_meta( $id, $ak, wp_slash( $cur ) );
						$out['append'][ $ak ] = count( $cur );
					}
					return daw_rb_nocache( $out );
				},
			),
		)
	);

	register_rest_route(
		'daw/v1',
		'/page',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'daw_rb_admin',
			'callback'            => static function ( WP_REST_Request $r ) {
				$path = '/' . ltrim( (string) wp_parse_url( (string) $r->get_param( 'path' ), PHP_URL_PATH ), '/' );
				$ua   = 'desktop' === $r->get_param( 'ua' )
					? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36'
					: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';
				$key  = 'daw_rb_page_' . md5( $path . '|' . $ua );
				$gz   = get_transient( $key );
				$hdr  = '';
				if ( false === $gz ) {
					$res = wp_remote_get( home_url( $path ), array( 'timeout' => 25, 'user-agent' => $ua, 'redirection' => 0 ) );
					if ( is_wp_error( $res ) ) {
						return new WP_Error( 'daw_page', $res->get_error_message(), array( 'status' => 502 ) );
					}
					$hdr = (string) wp_remote_retrieve_header( $res, 'x-litespeed-cache' );
					$gz  = base64_encode( (string) gzencode( (string) wp_remote_retrieve_body( $res ), 9 ) );
					set_transient( $key, $gz, 10 * MINUTE_IN_SECONDS );
				}
				$size  = 70000;
				$parts = max( 1, (int) ceil( strlen( $gz ) / $size ) );
				$part  = max( 0, min( $parts - 1, (int) $r->get_param( 'part' ) ) );
				return daw_rb_nocache( array( 'path' => $path, 'parts' => $parts, 'part' => $part, 'cache' => $hdr, 'b64' => substr( $gz, $part * $size, $size ) ) );
			},
		)
	);

	register_rest_route(
		'daw/v1',
		'/brief',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'daw_rb_admin',
			'callback'            => static function ( WP_REST_Request $r ) {
				$id = (int) $r->get_param( 'id' );
				if ( ! get_post( $id ) ) {
					return new WP_Error( 'daw_brief', 'Kayıt yok: ' . $id, array( 'status' => 404 ) );
				}
				return daw_rb_nocache( daw_rb_brief( $id, (string) $r->get_param( 'keys' ) ) );
			},
		)
	);

	// Re-audit one record now, in this request: no excerpt re-save, no
	// waiting for the 90-second cron event. Returns the fresh brief.
	register_rest_route(
		'daw/v1',
		'/reaudit',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'daw_rb_admin',
			'callback'            => static function ( WP_REST_Request $r ) {
				$id = (int) $r->get_param( 'id' );
				if ( ! get_post( $id ) || ! function_exists( 'daw_ae_prep_store' ) ) {
					return new WP_Error( 'daw_reaudit', 'Kayıt yok ya da denetim yüklenmedi: ' . $id, array( 'status' => 400 ) );
				}
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
				// sync=img,eps (1.75.70): meta written through /meta never fires
				// the save hook, so a corrected TMDB id left the portrait check
				// and the episode list stale (Ahn So-hee kept her old uploaded
				// picture as og:image). Refresh them here before the audit.
				$sync = array_map( 'trim', explode( ',', (string) $r->get_param( 'sync' ) ) );
				$synced = array();
				if ( in_array( 'img', $sync, true ) && function_exists( 'daw_tmdb_img_sync' ) ) {
					$synced['img'] = daw_tmdb_img_sync( $id );
				}
				if ( in_array( 'eps', $sync, true ) && function_exists( 'daw_tmdb_eps_sync' ) ) {
					$synced['eps'] = daw_tmdb_eps_sync( $id );
				}
				clean_post_cache( $id );
				// Meta written through /meta does not fire save_post, so the
				// cached page can be older than the record: drop it first.
				do_action( 'litespeed_purge_post', $id );
				daw_ae_prep_store( $id, true );
				$brief = daw_rb_brief( $id );
				if ( $synced ) {
					$brief['synced'] = $synced;
				}
				return daw_rb_nocache( $brief );
			},
		)
	);

	// Stamp a record as verified from outside (1.75.56). The fingerprint is
	// worked out here: the record is audited again first, and refused when
	// the audit still shows a red or empty line. Body: {id, note}.
	register_rest_route(
		'daw/v1',
		'/verify',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'daw_rb_admin',
			'callback'            => static function ( WP_REST_Request $r ) {
				$id = (int) $r->get_param( 'id' );
				if ( ! get_post( $id ) || 'publish' !== get_post_status( $id ) || ! function_exists( 'daw_ae_prep_store' ) || ! function_exists( 'daw_verify_hash' ) ) {
					return new WP_Error( 'daw_verify', 'Kayıt yayında değil, yok ya da denetim yüklenmedi: ' . $id, array( 'status' => 400 ) );
				}
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
				clean_post_cache( $id );
				do_action( 'litespeed_purge_post', $id );
				daw_ae_prep_store( $id, true );
				$audit = get_post_meta( $id, '_daw_audit', true );
				$sum   = is_array( $audit ) ? (array) ( $audit['sum'] ?? array() ) : array();
				$no    = (int) ( $sum['no'] ?? 0 );
				$eksik = (int) ( $sum['eksik'] ?? 0 );
				if ( ! is_array( $audit ) || $no + $eksik > 0 ) {
					$why = array();
					foreach ( (array) ( $audit['fails'] ?? array() ) as $f ) {
						if ( in_array( $f[2] ?? '', array( 'no', 'eksik' ), true ) ) {
							$why[] = array( 'panel' => $f[0] ?? '', 'label' => $f[1] ?? '', 'state' => $f[2] ?? '', 'problem' => $f[3] ?? '' );
						}
					}
					return new WP_REST_Response( array( 'ok' => false, 'id' => $id, 'no' => $no, 'eksik' => $eksik, 'reasons' => $why ), 409 );
				}
				$stamp = array(
					'at'    => time(),
					'dolu'  => (int) ( $sum['ok'] ?? 0 ),
					'yok'   => (int) ( $sum['yok'] ?? 0 ),
					'eksik' => 0,
					'list'  => (string) ( $audit['group'] ?? '' ),
					'note'  => sanitize_text_field( (string) $r->get_param( 'note' ) ),
					'hash'  => daw_verify_hash( $id ),
				);
				update_post_meta( $id, 'daw_verified', $stamp );
				// The lock needs the stamp to exist, so audit once more.
				daw_ae_prep_store( $id, true );
				return daw_rb_nocache( array( 'ok' => true, 'id' => $id, 'stamp' => $stamp, 'state' => function_exists( 'daw_verify_state' ) ? daw_verify_state( $id ) : '' ) );
			},
		)
	);

	register_rest_route(
		'daw/v1',
		'/inbox',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'daw_rb_admin',
			'callback'            => static function ( WP_REST_Request $r ) {
				$box = $r->get_param( 'box' );
				$pid = (int) get_option( 'page_on_front' );
				if ( ! is_array( $box ) || ! $box || ! $pid || ! function_exists( 'daw_inbox_run' ) ) {
					return new WP_Error( 'daw_inbox', 'Boş ya da geçersiz komut', array( 'status' => 400 ) );
				}
				update_post_meta( $pid, 'daw_inbox', wp_slash( $box ) ); // runs at once (daw_inbox_on_write)
				return daw_rb_nocache( array( 'last' => get_option( 'daw_inbox_last' ) ) );
			},
		)
	);
}

/**
 * One record in a few kilobytes instead of the whole meta dump (often 1 MB
 * of oEmbed caches and source snapshots): what the audit still wants, the
 * current value of each of those fields, its N/A or accepted-exception note,
 * and the field ids of the record's type. A batch agent reads this, not
 * every meta key. ?keys=a,b adds the values of other fields it needs.
 */
function daw_rb_brief( $id, $extra = '' ) {
	$type  = function_exists( 'daw_get_type' ) ? (string) daw_get_type( $id ) : '';
	$audit = get_post_meta( $id, '_daw_audit', true );
	$audit = is_array( $audit ) ? $audit : array();
	$yok   = get_post_meta( $id, '_daw_yok', true );
	$yok   = is_array( $yok ) ? $yok : array();
	$okv   = get_post_meta( $id, '_daw_ok', true );
	$okv   = is_array( $okv ) ? $okv : array();

	$fields = array(); // id => label
	$by_lbl = array(); // label => id
	if ( function_exists( 'daw_ae_boxes_for' ) ) {
		foreach ( daw_ae_boxes_for( $type ) as $box ) {
			foreach ( (array) $box['fields'] as $f ) {
				if ( empty( $f['id'] ) || in_array( $f['type'] ?? '', array( 'heading', 'divider', 'custom_html', 'button' ), true ) ) {
					continue;
				}
				$lbl                = wp_strip_all_tags( (string) ( $f['name'] ?? $f['id'] ) );
				$fields[ $f['id'] ] = $lbl;
				// Labels repeat across boxes ("Episode", "What happens" in both the
				// scenes and the episode list), so the box id is part of the key.
				$by_lbl[ ( $box['id'] ?? '' ) . '|' . $lbl ] = $f['id'];
				if ( ! isset( $by_lbl[ '|' . $lbl ] ) ) {
					$by_lbl[ '|' . $lbl ] = $f['id'];
				}
			}
		}
	}
	$short = static function ( $v ) {
		$js = is_string( $v ) ? $v : wp_json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return mb_strlen( (string) $js ) > 700 ? mb_substr( (string) $js, 0, 700 ) . '…(' . mb_strlen( (string) $js ) . ')' : $js;
	};

	$fails = array();
	$want  = array();
	foreach ( (array) ( $audit['fails'] ?? array() ) as $f ) {
		$lbl = (string) ( $f[1] ?? '' );
		$fid = $by_lbl[ ( $f[0] ?? '' ) . '|' . $lbl ] ?? ( $by_lbl[ '|' . $lbl ] ?? '' );
		if ( '' === $fid && preg_match( '/:\s*(daw_[a-z0-9_]+)$/', $lbl, $m ) ) {
			$fid = $m[1];
		}
		$fails[] = array( 'state' => $f[2] ?? '', 'panel' => $f[0] ?? '', 'label' => $lbl, 'field' => $fid, 'problem' => $f[3] ?? '', 'rule' => $f[4] ?? '', 'rule_text' => $f[5] ?? '', 'value' => $f[6] ?? '' );
		if ( '' !== $fid ) {
			$want[ $fid ] = true;
		}
	}
	foreach ( array_filter( array_map( 'trim', explode( ',', $extra ) ) ) as $k ) {
		if ( daw_rb_meta_ok( $k ) ) {
			$want[ $k ] = true;
		}
	}
	$vals = array();
	foreach ( array_keys( $want ) as $k ) {
		$vals[ $k ] = array(
			'value' => $short( get_post_meta( $id, $k, true ) ),
			'yok'   => isset( $yok[ $k ] ) ? $short( $yok[ $k ] ) : null,
			'ok'    => isset( $okv[ 'vf:' . $k ] ) ? $okv[ 'vf:' . $k ] : null,
		);
	}
	$filled = array();
	foreach ( array_keys( $fields ) as $k ) {
		$v = get_post_meta( $id, $k, true );
		if ( is_array( $v ) ? array_filter( array_map( static function ( $x ) { return is_scalar( $x ) ? trim( (string) $x ) : 'x'; }, $v ), 'strlen' ) : '' !== trim( (string) $v ) ) {
			$filled[] = $k;
		}
	}
	return array(
		'id'      => $id,
		'title'   => get_the_title( $id ),
		'type'    => $type,
		'link'    => get_permalink( $id ),
		'excerpt' => (string) get_post_field( 'post_excerpt', $id ),
		'audit'   => array( 'at' => $audit['at'] ?? 0, 'sum' => $audit['sum'] ?? array(), 'lock' => ! empty( $audit['lock'] ) ),
		'fails'   => $fails,
		'values'  => $vals,
		'seo'     => array(
			'title' => (string) get_post_meta( $id, 'rank_math_title', true ),
			'desc'  => (string) get_post_meta( $id, 'rank_math_description', true ),
			'focus' => (string) get_post_meta( $id, 'rank_math_focus_keyword', true ),
			'kw'    => get_post_meta( $id, '_daw_kw', true ),
		),
		'filled'  => $filled,
		'fields'  => $fields,
		'ok_keys' => array_keys( $okv ),
		'yok_keys'=> array_keys( $yok ),
	);
}

function daw_rb_nocache( $data ) {
	$res = new WP_REST_Response( $data );
	$res->header( 'Cache-Control', 'private, no-store' );
	$res->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
	return $res;
}
