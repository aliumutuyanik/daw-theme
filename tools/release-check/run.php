<?php
/**
 * Release check for daw-core (1.75.65, owner 11 Oct 2026: "güncelleme geldi,
 * bir şey bozuldu mu" must not be the owner's question).
 *
 * Run before every package, from the plugin folder:
 *
 *     php tools/release-check/run.php
 *
 * Exit code 0 = the package may be built. Anything else = it may not.
 *
 * Part 1, the package itself (each one has broken the site or a release once):
 *   - every PHP file parses (php -l);
 *   - no two global PHP functions share a name (a redeclare took the whole
 *     site down and could only be undone over FTP);
 *   - inc/rulebook.json parses and its item numbers are unique;
 *   - the plugin header Version, DAW_VERSION and the bundled theme's Version
 *     are the same number (1.75.62 and 1.75.63 shipped theme changes under
 *     the old theme number, and the site never received them).
 *
 * Part 2, behaviour on real records (every case is one that went wrong once;
 * a case is added whenever a new one turns up):
 *   - Search Console classification on the live Seo Kang Joon page (the
 *     benchmark person) with its 29 real queries and its stored decisions;
 *   - name spellings: Korean romanisation, hyphenated names, K-pop;
 *   - Tam / Temel / Eksik levels from a stored audit;
 *   - TMDB alternate ids (tv:83893 / movie:1455382).
 *
 * Nothing here is loaded by WordPress: the folder is only read from the
 * command line, and every file refuses to run outside it.
 *
 * @package daw-core
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

$root = dirname( __DIR__, 2 );
$fail = 0;
$pass = 0;

function rc_ok( $cond, $label, $detail = '' ) {
	global $fail, $pass;
	if ( $cond ) {
		++$pass;
		return;
	}
	++$fail;
	echo "  FAIL  {$label}" . ( '' !== $detail ? "\n        {$detail}" : '' ) . "\n";
}

/* ============================================================ part 1 ==== */

echo "1. Paket\n";

$php = array();
$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( 'php' === $f->getExtension() ) {
		$php[] = $f->getPathname();
	}
}
sort( $php );

$lint_bad = array();
foreach ( $php as $f ) {
	$out = array();
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $f ) . ' 2>&1', $out, $code );
	if ( 0 !== $code ) {
		$lint_bad[] = substr( $f, strlen( $root ) + 1 ) . ': ' . implode( ' ', $out );
	}
}
rc_ok( ! $lint_bad, count( $php ) . ' PHP dosyası sözdizimi', implode( "\n        ", $lint_bad ) );

// Global PHP functions only: a line that starts with "function" at column 0
// (or "	function" inside if ( ! function_exists() ) guards is allowed twice).
$seen = array();
$dup  = array();
foreach ( $php as $f ) {
	if ( false !== strpos( $f, '/tools/' ) ) {
		continue;
	}
	foreach ( file( $f ) as $n => $line ) {
		if ( preg_match( '/^function\s+([A-Za-z0-9_]+)\s*\(/', $line, $m ) ) {
			$name = strtolower( $m[1] );
			if ( isset( $seen[ $name ] ) ) {
				$dup[] = $m[1] . ' (' . $seen[ $name ] . ' ve ' . substr( $f, strlen( $root ) + 1 ) . ':' . ( $n + 1 ) . ')';
			} else {
				$seen[ $name ] = substr( $f, strlen( $root ) + 1 ) . ':' . ( $n + 1 );
			}
		}
	}
}
rc_ok( ! $dup, count( $seen ) . ' global fonksiyon, aynı isim yok', implode( "\n        ", $dup ) );

$book = json_decode( (string) file_get_contents( $root . '/inc/rulebook.json' ), true );
rc_ok( is_array( $book ), 'rulebook.json geçerli JSON', json_last_error_msg() );
if ( is_array( $book ) ) {
	$nos  = array_map( 'strval', array_column( (array) ( $book['items'] ?? array() ), 'no' ) );
	$dups = array_diff_assoc( $nos, array_unique( $nos ) );
	rc_ok( ! $dups, count( $nos ) . ' kural maddesi, numaralar tekil', 'Tekrar eden: ' . implode( ', ', array_unique( $dups ) ) );
}

$main    = (string) file_get_contents( $root . '/daw-core.php' );
$v_head  = preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/m', $main, $m ) ? $m[1] : '';
$v_const = preg_match( "/define\(\s*'DAW_VERSION',\s*'([0-9.]+)'/", $main, $m ) ? $m[1] : '';
$style   = glob( $root . '/theme/*/style.css' );
$v_theme = $style && preg_match( '/^[ \t\/*#@]*Version:\s*([0-9.]+)/mi', (string) file_get_contents( $style[0] ), $m ) ? $m[1] : '';
rc_ok( '' !== $v_head && $v_head === $v_const, "Eklenti başlığı ({$v_head}) = DAW_VERSION ({$v_const})" );
rc_ok( $v_theme === $v_const, "Tema sürümü ({$v_theme}) = eklenti sürümü ({$v_const})", 'Tema numarası artmazsa site tema değişikliğini almaz.' );

/* ============================================================ part 2 ==== */

echo "2. Gerçek kayıtlarda davranış\n";

require __DIR__ . '/stubs.php';

// Search Console rows for the fixture page: the 29 queries Google showed the
// Seo Kang Joon page for in the 28 days to 7 Oct 2026, plus three that the
// classifier must handle (an operator, the site's own name, another site).
$GLOBALS['rc_queries'] = array(
	'5urprise', 'is seo kang joon married', 'kang joon', 'kang seo joon', 'running man kang hoon',
	'seo kang', 'seo kang joo', 'seo kang joon', 'seo kang joon age', 'seo kang joon ahn eun jin',
	'seo kang joon debut', 'seo kang joon dramas', 'seo kang joon eyes', 'seo kang joon ipoh international school',
	'seo kang joon jisoo', 'seo kang joon malaysia', 'seo kang joon new drama', 'seo kang joon sister',
	'seo kang joon wife', 'seo kang jooon', 'seo kang jun', 'seo kang-joon', 'seo kang-joon appears in',
	'seo kang-joon green coach', 'seo kang-joon songs', 'seo kang-joon tv shows', 'seo kang-joon wife',
	'seo kangjoon', 'seojun', 'site:discoverasianworld.com', 'discover asian world seo kang joon',
	'seo kang joon reddit',
);
function daw_perf_paths( $id ) {
	return array( '/seo-kang-joon/' );
}
function daw_perf_queries( $paths, $limit = 15 ) {
	$o = array();
	foreach ( $GLOBALS['rc_queries'] as $q ) {
		$o[ $q ] = array( 'c' => 0, 'i' => 1, 'p' => 5.0, 'prev' => false );
	}
	return array( $o, '' );
}
function daw_audit_seo_state( $id ) {
	return array(
		'title'       => 'TV Shows With Seo Kang Joon: Every Drama and Movie',
		'description' => 'Seo Kang Joon movie and drama list with the episodes he appears in. Plus his 2025 MBC Drama Awards grand prize, 5urprise years, military service and dating.',
	);
}

$GLOBALS['daw_rc_title'][2480] = 'Seo Kang Joon';
$GLOBALS['daw_rc_meta'][2480]  = array(
	'_daw_ok'       => array(
		'gsc:running man kang hoon'                   => 'Başka kişi',
		'gsc:seo kang-joon green coach'               => 'Başka yapım',
		'gsc:seo kang joon jisoo'                     => 'Doğrulanmamış iddia (madde 31)',
		'gsc:seo kang joon ipoh international school' => 'Kaynağı bulunamayan bilgi',
		'gsc:seojun'                                  => 'Bu kişiye özgü değil',
	),
	'_daw_src_tmdb' => array(
		'name' => 'Seo Kang-joon',
		'aka'  => array( '서강준', 'Seo Kang-jun', 'Kang-joon Seo', 'Kang-jun Seo', '이승환', 'Lee Seung-hwan', '徐康俊' ),
	),
	'daw_real_name' => 'Lee Seung Hwan',
);

require $root . '/inc/audit-engine.php';
require $root . '/inc/work-center.php';
require $root . '/inc/coverage.php';

$html = (string) gzdecode( (string) file_get_contents( __DIR__ . '/fixtures/seo-kang-joon.html.gz' ) );
rc_ok( strlen( $html ) > 100000, 'Seo Kang Joon sayfa örneği okundu' );

$expect = array(
	// Met on the page, in any spelling of the name.
	'ok'   => array(
		'5urprise', 'is seo kang joon married', 'kang joon', 'kang seo joon', 'seo kang', 'seo kang joon',
		'seo kang joon age', 'seo kang joon debut', 'seo kang joon dramas', 'seo kang joon eyes',
		'seo kang joon malaysia', 'seo kang joon new drama', 'seo kang joon sister', 'seo kang joon wife',
		'seo kang jun', 'seo kang-joon', 'seo kang-joon appears in', 'seo kang-joon songs',
		'seo kang-joon tv shows', 'seo kang-joon wife', 'discover asian world seo kang joon',
		// "Kang-joon" on the page is also "kangjoon" to a searcher.
		'seo kangjoon',
	),
	// Misspelt name: Google already matches it, it is never written on the page.
	'var'  => array( 'seo kang joo', 'seo kang jooon' ),
	// Decided, with a reason, or recognised by rule.
	'skip' => array(
		'running man kang hoon', 'seo kang-joon green coach', 'seo kang joon jisoo',
		'seo kang joon ipoh international school', 'seojun', 'site:discoverasianworld.com', 'seo kang joon reddit',
	),
	// Not on this snapshot of the page (written in on 9 Oct, after it was taken).
	'miss' => array( 'seo kang joon ahn eun jin' ),
);
$rows = daw_ae_gsc_rows( 2480, $html );
foreach ( $expect as $st => $qs ) {
	foreach ( $qs as $q ) {
		$got = $rows[ $q ]['st'] ?? '(yok)';
		rc_ok( $got === $st, "GSC \"{$q}\" → {$st}", "Çıkan: {$got}" );
	}
}

// Spellings that are one name.
$same = array(
	array( 'Yang Hee Seung', 'Yang Hui-seung' ),
	array( 'Ahn Eun-bin', 'An Eun-bin' ),
	array( 'Kim Soo Jin', 'Kim Su-jin' ),
);
foreach ( $same as $p ) {
	rc_ok( daw_ae_kname_fold( $p[0] ) === daw_ae_kname_fold( $p[1] ), "Ad yazımı: {$p[0]} = {$p[1]}" );
}
rc_ok( daw_ae_kname_fold( 'Kim Soo-jin' ) !== daw_ae_kname_fold( 'Oh Hyun-jong' ), 'Ad yazımı: farklı kişiler ayrı kalır' );
rc_ok( daw_ae_kw_in_fold( 'suzy kpop', 'Suzy joined the K-pop group Miss A' ), 'Arama: "kpop" = "K-pop"' );
rc_ok( daw_ae_kw_in_fold( 'kang hana taxi driver', 'She played Kang Ha-na in Taxi Driver' ), 'Arama: "kang hana" = "Kang Ha-na"' );
rc_ok( daw_ae_kw_in_fold( 'eye color', 'his eye colour' ), 'Arama: color = colour' );
rc_ok( ! daw_ae_kw_in_fold( 'seo kang joon net worth', 'Seo Kang Joon is an actor' ), 'Arama: sayfada olmayan kelime eksik sayılır' );

// Tam / Temel / Eksik.
$m_tam   = array( 'lock' => true, 'sum' => array( 'no' => 0, 'eksik' => 0, 'wait' => 0 ), 'fails' => array() );
$m_temel = array( 'lock' => true, 'sum' => array( 'no' => 0, 'eksik' => 0, 'wait' => 1 ), 'fails' => array( array( 'coverage', 'Kaydı olmayan dizi / film / anime', 'wait', '', '59', '', '1 yapım' ) ) );
$m_red   = array( 'sum' => array( 'no' => 2, 'eksik' => 0 ), 'fails' => array( array( 'rules', 'x', 'no', '', '21', '', '' ) ) );
$m_eks   = array( 'sum' => array( 'no' => 0, 'eksik' => 3 ), 'fails' => array( array( 'x', 'y', 'eksik', '', '', '', '' ) ) );
rc_ok( 'tam' === daw_wc_level( daw_wc_state( $m_tam ), $m_tam ), 'Düzey: açık satır yok → Tam bitti' );
rc_ok( 'temel' === daw_wc_level( daw_wc_state( $m_temel ), $m_temel ), 'Düzey: yalnız bekleyen satır → Temel bitti (yeni film gelince düşer)' );
rc_ok( 'eksik' === daw_wc_level( daw_wc_state( $m_red ), $m_red ), 'Düzey: kırmızı satır → Temelde eksik' );
rc_ok( 'eksik' === daw_wc_level( daw_wc_state( $m_eks ), $m_eks ), 'Düzey: bakılmamış alan → Temelde eksik' );

// TMDB alternate ids.
rc_ok( 'tv:83893' === daw_cov_alt_key( 'tv:83893' ), 'TMDB eş kimlik: tv:83893' );
rc_ok( 'movie:1455382' === daw_cov_alt_key( 'https://www.themoviedb.org/movie/1455382' ), 'TMDB eş kimlik: adres biçimi' );
rc_ok( '' === daw_cov_alt_key( '83893' ), 'TMDB eş kimlik: türsüz sayı reddedilir' );

/* =========================================================== result ==== */

echo "\n" . ( $fail ? "SONUÇ: {$fail} hata, {$pass} geçti. Paket ÇIKMAZ.\n" : "SONUÇ: {$pass} kontrolün hepsi geçti. Paket çıkabilir.\n" );
exit( $fail ? 1 : 0 );
