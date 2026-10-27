<?php
/**
 * The rulebook: every rule the audit applies, in one file shipped with the
 * plugin (rulebook.json), so nobody has to explain the rules again.
 *
 * WHO READS IT
 * ------------
 * - The owner: DAW › Kurallar lists every numbered item, how it is checked
 *   (by the plugin, by the plugin plus a researcher, or by research only),
 *   the site rules and the condition for every field.
 * - The audit: each failing line carries the rule it failed, so the record's
 *   own `_daw_audit` meta says not only what is wrong but what is required.
 * - An AI agent over MCP: `wp_get_option daw_rulebook` returns the whole book
 *   and `wp_get_option daw_agent_brief` the working order. With those and a
 *   record's `_daw_audit`, an agent needs no other instructions.
 *
 * @package daw-core
 */

defined( 'ABSPATH' ) || exit;

/** The whole book, read once per request. */
function daw_rulebook() {
	static $book = null;
	if ( null === $book ) {
		$raw  = @file_get_contents( DAW_DIR . 'inc/rulebook.json' ); // phpcs:ignore
		$book = $raw ? json_decode( $raw, true ) : array();
		$book = is_array( $book ) ? $book : array();
	}
	return $book;
}

/** The rule text for a checklist number ("34", "K-d") or a field id. */
function daw_rule_text( $no, $field_id = '' ) {
	$book = daw_rulebook();
	if ( '' !== $field_id && isset( $book['fields'][ $field_id ]['rule'] ) ) {
		return (string) $book['fields'][ $field_id ]['rule'];
	}
	foreach ( (array) ( $book['items'] ?? array() ) as $it ) {
		if ( (string) $it['no'] === (string) $no ) {
			return (string) $it['rule'];
		}
	}
	return '';
}

/**
 * Keep the book in options so MCP can read it. Rewritten only when the
 * plugin version changes.
 */
add_action( 'admin_init', 'daw_rulebook_sync' );
add_action( 'daw_ae_cron', 'daw_rulebook_sync' );
function daw_rulebook_sync() {
	if ( get_option( 'daw_rulebook_v' ) === DAW_VERSION ) {
		return;
	}
	$book = daw_rulebook();
	if ( ! $book ) {
		return;
	}
	update_option( 'daw_rulebook', $book, false );
	update_option(
		'daw_agent_brief',
		array(
			'read_first' => 'wp_get_option daw_rulebook (bütün kurallar), sonra kaydın _daw_audit meta alanı (fails listesi: [bölüm, madde adı, durum, sorun, madde no, kural]).',
			'batch'      => 'Toplu işte wp_get_option daw_work_queue okunur. Partiye başlarken her kayıt DAW › Denetim detayında bir kez açılır (admin.php?page=daw-denetim&record=ID): sistem o kayıt için kaynak doğrulaması, kapsam ve izleme yerini o anda yürütür, denetimi yeniler; saatlik görev beklenmez. Sonra istenen modele (tier: haiku ya da sonnet) uyan sıradaki N kayıt alınır. Her kayıtta yalnız fails listesindeki maddeler ve onların alanları okunur (wp_get_post_meta key ile tek tek), kaydın bütün meta dökümü açılmaz. Haiku kaydında sonnet maddesi çıkarsa dokunmadan bırakılır.',
			'tiers'      => array(
				'haiku'  => 'Anahtar kelime (46-49), IMDb/Wikipedia bağlantısı (58), fragman tarihi (42), uzun tire, klişe ve yer tutucu düzeltmesi (50-52, K-h), SEO başlık/açıklama uzunluğu (K-b, K-c).',
				'sonnet' => 'Kaynak araştırması ve metin: sinopsis, öne çıkanlar, derin bölümler, SSS, bölüm özetleri, kadro bölüm bilgisi (K18), kaynak çelişkileri (62).',
			),
			'writing'    => 'Metin uydurulmaz: her cümle kaynaklı (K24), kaynağı olmayan cümle çıkar. Yazmadan önce Ubersuggest kalıpları okunur ve _daw_kw’ye yazılır (K25): araştırmada çıkan bütün kalıplar hacimleriyle kaydedilir, hiçbiri silinmez; hedeflenenler düz, ilgili ama hedeflenmeyenler "n":1, konu dışı olanlar (başka kişi/yapım, yanlış niyet) "x":1 işaretiyle durur ve metne girmez; sinopsis en çok aranan konulara göre kurulur, kalıp ayda 5.000+ ise 350+ kelime ve 5-6 paragraf (21). Sinopsise sığmayan, ayda 1.000+ aranan ayrı bir konu (ending explained, based on webtoon, filming locations) kendi H2’siyle açılır; başlık kalıbı taşır, metin 120+ kelime (63). Aranan kalıp yoksa ek bölüm açılmaz. Özgünlük (64): site %99 özgün; iki kayıtta aynı cümle olmaz, kaynak cümlesi kopyalanmaz, olgu alınıp cümle yeniden kurulur ve uygun long-tail kalıplar içine yerleştirilir. Bölüm özetleri de her bölüme özgü, somut (karakter, oyun, olay adı) ve tekrar etmeyen cümlelerdir.',
			'watch'      => 'İzleme satırları platformun kendisine gider (56): elle yazılmış resmi bağlantı, Wikidata platform kimliğinden yapım sayfası, doğrulanmış platform araması, en son TMDB. Kimliği Wikidata’da olmayan büyük platformda (Kocowa, iQIYI, Viu) yapımın sayfası bulunursa daw_watch_platform/daw_watch_url’e yazılır.',
			'seasons'    => 'Her sezon kendi sayfasıdır (65, MyDramaList modeli); seri kaydı 1. sezonu temsil eder. Sezon sayfası TMDB’de seriye ve sezon numarasına bağlanır; afiş, tarih, bölüm ve kadro o sezondan okunur, dizinin toplamından değil. Yeni sezon için önce K19 araması; sezonun kaydı yoksa ve seri sayfası zaten 1. sezonu anlatıyorsa yeni sezon sayfası “X Season N” adıyla açılır.',
			'sources'    => 'Kaynak deposu önce okunur: _daw_src_tmdb (TMDB özeti: Asya dillerindeki adlar ve özetler, tarihler, kanal, şirketler, yaş sınırları, kadro ve karakterler, ekip, kimlikler), _daw_src_wd (Wikidata öğesi, etiketli iddialar) ve _daw_src_wiki_en / _daw_src_wiki_ko (ja, zh, zh_yue, th, tl, vi, id, ms: hangisi varsa) (Wikipedia wikitext: bilgi kutusu, bölüm ve reyting tabloları, adresli referanslar; url ve permalink). Ayrıca _daw_src_yt (fragmanın kanalı, yükleme tarihi, süresi) ve _daw_src_kakao (Korece web/video/blog sonuçları: ikinci kaynak adayları, sayfa açılmadan kaynak sayılmaz). Web araması yalnız bunların cevaplamadığı için yapılır. Wikidata ve Wikipedia yine birer kaynaktır; olgu ikinci kaynakla doğrulanır (K4, madde 62).',
			'trailer'    => 'Fragman yalnız resmi kanaldan (42): yayıncı, platform, yapımcı ya da dağıtıcı; bölgesel resmi kanallar geçerli. Fan, derleme ya da yeniden yükleme kanalı hiçbir koşulda kullanılmaz; resmi yükleme yoksa alan boş kalır ve _daw_yok’a N/A yazılır.',
			'workflow'   => (array) ( $book['workflow'] ?? array() ),
			'states'     => array(
				'ok'    => 'kaynaklı ve kurala uygun',
				'yok'   => 'N/A: araştırıldı, kaynak yok (yeşil sayılır, _daw_yok). Madde 66: sebep bakılan yerleri listeler, en az 5 yer, en az 1 resmi, tarihli: "<sebep>. Bakılan (N): adres; adres (resmi); ... | gg.aa.yyyy". Okunamayan sayfa listeye girmez; eksik liste EKSİK sayılır.',
				'na'    => 'bu kayıtta uygulanmaz',
				'eksik' => 'henüz bakılmadı',
				'no'    => 'kurala aykırı, düzeltilecek',
				'wait'  => 'kaynakla doğrulanacak ya da tarama bekliyor',
			),
			'benchmarks' => array( 'drama' => 'Pinocchio, post 23163', 'person' => 'Seo Kang Joon, post 2480' ),
		),
		false
	);
	update_option( 'daw_rulebook_v', DAW_VERSION, false );
}

add_filter( 'royal_mcp_readable_options', 'daw_rulebook_readable' );
function daw_rulebook_readable( $keys ) {
	$keys   = (array) $keys;
	$keys[] = 'daw_rulebook';
	$keys[] = 'daw_agent_brief';
	return array_values( array_unique( $keys ) );
}

/* ================================================================= page === */

add_action( 'admin_menu', 'daw_rulebook_menu', 31 );
function daw_rulebook_menu() {
	add_submenu_page( 'daw', 'Kurallar', 'Kurallar', 'manage_options', 'daw-kurallar', 'daw_rulebook_page' );
}

function daw_rulebook_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$book  = daw_rulebook();
	$badge = array(
		'auto'       => array( '#e3f1ec', '#185a4a', 'Sistem ölçer' ),
		'auto+agent' => array( '#e2ebf5', '#27527f', 'Sistem + kaynak kontrolü' ),
		'agent'      => array( '#f6ecc9', '#6a4d0c', 'Araştırmayla' ),
	);
	echo '<div class="wrap"><h1>DAW › Kurallar</h1>';
	echo '<p style="max-width:72em">Denetimin uyguladığı her kural burada, sabit numarasıyla. Denetim dökümündeki her satır bu numarayı taşır; bir satır kırmızıysa yanında hangi kurala aykırı olduğu yazar. Yapay zekâ ajanı aynı kitabı sitenin kendisinden okur (<code>daw_rulebook</code> ve <code>daw_agent_brief</code> ayarları), ayrıca anlatmaya gerek yoktur.</p>';

	echo '<h2>Çalışma sırası</h2><ol style="max-width:72em">';
	foreach ( (array) ( $book['workflow'] ?? array() ) as $w ) {
		echo '<li style="margin-bottom:6px">' . esc_html( $w ) . '</li>';
	}
	echo '</ol>';

	echo '<h2>Numaralı maddeler</h2>';
	echo '<table class="widefat striped" style="max-width:1300px"><thead><tr><th style="width:60px">No</th><th style="width:22%">Madde</th><th>Dolu / geçer sayılma şartı</th><th style="width:170px">Nasıl kontrol edilir</th></tr></thead><tbody>';
	$group = '';
	foreach ( (array) ( $book['items'] ?? array() ) as $it ) {
		if ( ( $it['group'] ?? '' ) !== $group ) {
			$group = (string) ( $it['group'] ?? '' );
			printf( '<tr><td colspan="4" style="background:#f3ede4;font-weight:600">%s</td></tr>', esc_html( $group ) );
		}
		$b = $badge[ $it['check'] ?? 'auto+agent' ] ?? $badge['auto+agent'];
		printf(
			'<tr><td style="font-weight:600;color:#7e2128">%s</td><td>%s</td><td>%s</td><td><span style="background:%s;color:%s;border-radius:999px;padding:2px 9px;font-size:12px;font-weight:600">%s</span></td></tr>',
			esc_html( $it['no'] ),
			esc_html( $it['title'] ),
			esc_html( $it['rule'] ),
			esc_attr( $b[0] ),
			esc_attr( $b[1] ),
			esc_html( $b[2] )
		);
	}
	echo '</tbody></table>';

	$names = array( 'seo' => 'SEO', 'schema' => 'Schema', 'image' => 'Görseller ve alt metin', 'text_legal' => 'Yazı, hukuk, güncellik' );
	foreach ( (array) ( $book['checks'] ?? array() ) as $k => $rows ) {
		printf( '<h2>%s</h2>', esc_html( $names[ $k ] ?? $k ) );
		echo '<table class="widefat striped" style="max-width:1300px"><tbody>';
		foreach ( $rows as $r ) {
			printf( '<tr><td style="width:60px;font-weight:600;color:#7e2128">%s</td><td style="width:22%%">%s</td><td>%s</td></tr>', esc_html( $r['no'] ), esc_html( $r['title'] ), esc_html( $r['rule'] ) );
		}
		echo '</tbody></table>';
	}

	echo '<h2>Site kuralları</h2><table class="widefat striped" style="max-width:1300px"><tbody>';
	foreach ( (array) ( $book['site'] ?? array() ) as $r ) {
		printf( '<tr><td style="width:60px;font-weight:600;color:#7e2128">%s</td><td>%s</td></tr>', esc_html( $r['no'] ), esc_html( $r['rule'] ) );
	}
	echo '</tbody></table>';

	echo '<h2>Alan başına şart</h2><table class="widefat striped" style="max-width:1300px"><thead><tr><th style="width:60px">No</th><th style="width:26%">Alan</th><th>Şart</th></tr></thead><tbody>';
	foreach ( (array) ( $book['fields'] ?? array() ) as $id => $f ) {
		printf( '<tr><td style="font-weight:600;color:#7e2128">%s</td><td><code>%s</code></td><td>%s</td></tr>', esc_html( $f['no'] ), esc_html( $id ), esc_html( $f['rule'] ) );
	}
	echo '</tbody></table></div>';
}
