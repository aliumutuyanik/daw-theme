<?php
/**
 * Discover Asian World: Google Analytics 4, read into the control centre.
 *
 * Search Console says how the site is found; Analytics says what readers do
 * once they are here. The same service account reads both (owner added it as
 * a Viewer on property 420020709, 4 Oct 2026). Once a day the last 28 days
 * are compared with the 28 before: readers, sessions, page views, time on
 * the page, where readers come from, which pages they read, their country
 * and device. Nothing is fetched while a screen opens.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_GA4_REPORT = 'daw_ga4_report';

function daw_ga4_property() {
	return preg_replace( '/\D/', '', (string) get_option( 'daw_ga4_property', '420020709' ) );
}

add_filter( 'royal_mcp_readable_options', 'daw_ga4_readable' );
function daw_ga4_readable( $keys ) {
	$keys   = (array) $keys;
	$keys[] = DAW_GA4_REPORT;
	return array_values( array_unique( $keys ) );
}

add_action( 'init', 'daw_ga4_schedule' );
function daw_ga4_schedule() {
	if ( ! wp_next_scheduled( 'daw_ga4_daily' ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', 'daw_ga4_daily' );
	}
}
add_action( 'daw_ga4_daily', 'daw_ga4_run' );
add_action( 'daw_kc_run_all_late', 'daw_ga4_run', 20 );

/** One runReport call. */
function daw_ga4_report( array $body ) {
	if ( ! function_exists( 'daw_gsc_api' ) || '' === daw_ga4_property() ) {
		return new WP_Error( 'daw_ga4', 'Analytics bağlantısı yok.' );
	}
	$res = daw_gsc_api( 'POST', 'https://analyticsdata.googleapis.com/v1beta/properties/' . daw_ga4_property() . ':runReport', $body );
	if ( is_wp_error( $res ) ) {
		$msg = str_replace( ' (Servis hesabının e-postası Search Console mülküne kullanıcı olarak eklendi mi?)', '', $res->get_error_message() );
		if ( false !== stripos( $msg, 'has not been used' ) || false !== stripos( $msg, 'disabled' ) ) {
			$msg = 'Google Cloud projesinde "Google Analytics Data API" açık değil. (' . $msg . ')';
		} elseif ( false !== stripos( $msg, 'permission' ) ) {
			$msg = 'Servis hesabı Analytics mülküne Görüntüleyen olarak eklenmemiş. (' . $msg . ')';
		}
		return new WP_Error( 'daw_ga4', $msg );
	}
	return $res;
}

/** Rows as [ dims..., metrics... ] arrays of plain values. */
function daw_ga4_rows( $res ) {
	$out = array();
	foreach ( (array) ( $res['rows'] ?? array() ) as $r ) {
		$row = array();
		foreach ( (array) ( $r['dimensionValues'] ?? array() ) as $v ) {
			$row[] = (string) ( $v['value'] ?? '' );
		}
		foreach ( (array) ( $r['metricValues'] ?? array() ) as $v ) {
			$row[] = (float) ( $v['value'] ?? 0 );
		}
		$out[] = $row;
	}
	return $out;
}

function daw_ga4_run() {
	if ( ! function_exists( 'daw_gsc_creds' ) || ! daw_gsc_creds() ) {
		return;
	}

	$keys   = array( 'activeUsers', 'newUsers', 'sessions', 'screenPageViews', 'averageSessionDuration', 'engagementRate' );
	$report = array( 'at' => time(), 'property' => daw_ga4_property(), 'error' => '' );
	$ranges = array(
		'now'  => array( 'startDate' => '28daysAgo', 'endDate' => 'yesterday' ),
		'prev' => array( 'startDate' => '56daysAgo', 'endDate' => '29daysAgo' ),
	);

	foreach ( $ranges as $name => $range ) {
		$res = daw_ga4_report(
			array(
				'dateRanges' => array( $range ),
				'metrics'    => array_map( static function ( $m ) { return array( 'name' => $m ); }, $keys ),
			)
		);
		if ( is_wp_error( $res ) ) {
			$report['error'] = $res->get_error_message();
			update_option( DAW_GA4_REPORT, $report, false );
			return;
		}
		$rows            = daw_ga4_rows( $res );
		$report[ $name ] = array_combine( $keys, array_pad( (array) ( $rows[0] ?? array() ), count( $keys ), 0 ) );
	}

	$one = array( array( 'startDate' => '28daysAgo', 'endDate' => 'yesterday' ) );

	$daily = daw_ga4_report( array( 'dateRanges' => $one, 'dimensions' => array( array( 'name' => 'date' ) ), 'metrics' => array( array( 'name' => 'activeUsers' ) ), 'orderBys' => array( array( 'dimension' => array( 'dimensionName' => 'date' ) ) ) ) );
	if ( ! is_wp_error( $daily ) ) {
		$report['daily'] = array_map( static function ( $r ) { return array( $r[0], (int) $r[1] ); }, daw_ga4_rows( $daily ) );
	}

	$lists = array(
		'pages'    => array( 'pagePath', array( 'screenPageViews', 'activeUsers', 'userEngagementDuration' ), 10 ),
		'channels' => array( 'sessionDefaultChannelGroup', array( 'sessions' ), 8 ),
		'countries' => array( 'country', array( 'activeUsers' ), 6 ),
		'devices'  => array( 'deviceCategory', array( 'activeUsers' ), 4 ),
	);
	foreach ( $lists as $k => $l ) {
		$res = daw_ga4_report(
			array(
				'dateRanges' => $one,
				'dimensions' => array( array( 'name' => $l[0] ) ),
				'metrics'    => array_map( static function ( $m ) { return array( 'name' => $m ); }, $l[1] ),
				'orderBys'   => array( array( 'metric' => array( 'metricName' => $l[1][0] ), 'desc' => true ) ),
				'limit'      => $l[2],
			)
		);
		if ( ! is_wp_error( $res ) ) {
			$report[ $k ] = daw_ga4_rows( $res );
		}
	}

	// Traffic quality (1.74.0): last 7 days by day and channel, with engaged
	// sessions, and where "Direct" comes from. Bots arrive as Direct with no
	// engagement from data-centre cities; readers engage.
	$q = daw_ga4_report(
		array(
			'dateRanges' => array( array( 'startDate' => '7daysAgo', 'endDate' => 'today' ) ),
			'dimensions' => array( array( 'name' => 'date' ), array( 'name' => 'sessionDefaultChannelGroup' ) ),
			'metrics'    => array( array( 'name' => 'sessions' ), array( 'name' => 'engagedSessions' ), array( 'name' => 'averageSessionDuration' ) ),
			'orderBys'   => array( array( 'dimension' => array( 'dimensionName' => 'date' ) ) ),
			'limit'      => 200,
		)
	);
	if ( ! is_wp_error( $q ) ) {
		$report['quality'] = daw_ga4_rows( $q );
	}
	$g = daw_ga4_report(
		array(
			'dateRanges'      => array( array( 'startDate' => '7daysAgo', 'endDate' => 'today' ) ),
			'dimensions'      => array( array( 'name' => 'country' ), array( 'name' => 'city' ), array( 'name' => 'deviceCategory' ) ),
			'metrics'         => array( array( 'name' => 'sessions' ), array( 'name' => 'engagedSessions' ), array( 'name' => 'averageSessionDuration' ) ),
			'dimensionFilter' => array( 'filter' => array( 'fieldName' => 'sessionDefaultChannelGroup', 'stringFilter' => array( 'value' => 'Direct' ) ) ),
			'orderBys'        => array( array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ) ),
			'limit'           => 15,
		)
	);
	if ( ! is_wp_error( $g ) ) {
		$report['direct_geo'] = daw_ga4_rows( $g );
	}

	// Real readers (1.75.56): engaged sessions are the count of people who
	// stayed 10 s, saw two pages or did something. By channel, last 28 days.
	$e = daw_ga4_report(
		array(
			'dateRanges' => $one,
			'dimensions' => array( array( 'name' => 'sessionDefaultChannelGroup' ) ),
			'metrics'    => array( array( 'name' => 'sessions' ), array( 'name' => 'engagedSessions' ) ),
			'orderBys'   => array( array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ) ),
			'limit'      => 10,
		)
	);
	if ( ! is_wp_error( $e ) ) {
		$report['engaged'] = daw_ga4_rows( $e );
	}

	// What the not-engaged "Direct" visits look like, to tell scripted browsers
	// from ghost hits sent straight to Analytics without opening a page: the
	// hostname the hit claims, the landing page, browser and screen size.
	$diag = array();
	foreach ( array( 'hostName', 'landingPage', 'browser', 'screenResolution' ) as $dim ) {
		$d = daw_ga4_report(
			array(
				'dateRanges'      => array( array( 'startDate' => '7daysAgo', 'endDate' => 'today' ) ),
				'dimensions'      => array( array( 'name' => $dim ) ),
				'metrics'         => array( array( 'name' => 'sessions' ), array( 'name' => 'engagedSessions' ) ),
				'dimensionFilter' => array( 'filter' => array( 'fieldName' => 'sessionDefaultChannelGroup', 'stringFilter' => array( 'value' => 'Direct' ) ) ),
				'orderBys'        => array( array( 'metric' => array( 'metricName' => 'sessions' ), 'desc' => true ) ),
				'limit'           => 6,
			)
		);
		if ( ! is_wp_error( $d ) ) {
			$diag[ $dim ] = daw_ga4_rows( $d );
		}
	}
	if ( $diag ) {
		$report['diag'] = $diag;
	}

	update_option( DAW_GA4_REPORT, $report, false );
}

/** Turkish names for GA4's channel groups. */
function daw_ga4_channel_tr( $c ) {
	$map = array(
		'Organic Search' => 'Google ve arama motorları',
		'Direct'         => 'Doğrudan',
		'Organic Social' => 'Sosyal medya',
		'Referral'       => 'Başka siteler',
		'Unassigned'     => 'Belirsiz',
		'Paid Search'    => 'Ücretli arama',
		'Email'          => 'E-posta',
		'Organic Video'  => 'Video (YouTube)',
		'AI Assistant'   => 'Yapay zekâ asistanları',
		'Display'        => 'Görüntülü reklam',
	);
	return $map[ $c ] ?? $c;
}

function daw_ga4_device_tr( $c ) {
	$map = array( 'mobile' => 'Telefon', 'desktop' => 'Bilgisayar', 'tablet' => 'Tablet' );
	return $map[ strtolower( $c ) ] ?? $c;
}

/** The control-centre card. */
function daw_ga4_card() {
	$r = get_option( DAW_GA4_REPORT );
	$st = ! is_array( $r ) ? 'info' : ( '' !== (string) ( $r['error'] ?? '' ) ? 'bad' : 'ok' );

	daw_kc_card_open( 'ga4', '◉', 'Ziyaretçiler (Analytics)', $st );

	if ( ! is_array( $r ) ) {
		echo '<p class="kc-muted">İlk veri birkaç dakika içinde gelir; "Tüm kontrolleri şimdi çalıştır" hemen çeker.</p>';
		daw_kc_card_close();
		return;
	}
	if ( 'bad' === $st ) {
		printf( '<p class="kc-muted">%s</p>', esc_html( (string) $r['error'] ) );
		daw_kc_card_close();
		return;
	}

	$n = (array) ( $r['now'] ?? array() );
	$p = (array) ( $r['prev'] ?? array() );
	$m = static function ( $k ) use ( $n ) {
		return (float) ( $n[ $k ] ?? 0 );
	};
	$q = static function ( $k ) use ( $p ) {
		return (float) ( $p[ $k ] ?? 0 );
	};

	echo '<div class="kc-stats">';
	daw_kc_stat( daw_kc_n( $m( 'activeUsers' ) ), 'okur', daw_kc_delta( $m( 'activeUsers' ), $q( 'activeUsers' ) ) );
	daw_kc_stat( daw_kc_n( $m( 'screenPageViews' ) ), 'sayfa görüntüleme', daw_kc_delta( $m( 'screenPageViews' ), $q( 'screenPageViews' ) ) );
	daw_kc_stat( gmdate( 'i:s', (int) $m( 'averageSessionDuration' ) ), 'ort. oturum', daw_kc_delta( $m( 'averageSessionDuration' ), $q( 'averageSessionDuration' ) ) );
	daw_kc_stat( '%' . daw_kc_n( $m( 'engagementRate' ) * 100 ), 'ilgilenen oturum' );
	echo '</div>';

	if ( ! empty( $r['daily'] ) ) {
		echo '<div class="kc-chart"><span>Günlük okur</span>' . daw_kc_spark( wp_list_pluck( $r['daily'], 1 ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	if ( ! empty( $r['channels'] ) ) {
		$total = array_sum( wp_list_pluck( $r['channels'], 1 ) );
		// A reader who arrives from a search, a link, a post or an AI answer
		// is a person who chose to come; "Direct" is where scripted browsers
		// land (owner's property, Oct 2026: 95% Direct, 8 s visits).
		$real = 0;
		foreach ( $r['channels'] as $c ) {
			if ( ! in_array( $c[0], array( 'Direct', 'Unassigned' ), true ) ) {
				$real += $c[1];
			}
		}
		$eng = 0;
		$all = 0;
		foreach ( (array) ( $r['engaged'] ?? array() ) as $c ) {
			$all += (int) $c[1];
			$eng += (int) $c[2];
		}
		if ( $all ) {
			printf( '<p class="kc-ga-real"><b>%s</b> oturum 10 saniyeden uzun, iki sayfa ya da bir olayla ilgilendi (toplam %s oturumun %%%s\'i). Gerçek okura en yakın sayı budur; Analytics\'te rapor üstüne "Etkileşimli oturum" ölçütünü koyarak aynısını görürsün.</p>', esc_html( daw_kc_n( $eng ) ), esc_html( daw_kc_n( $all ) ), esc_html( daw_kc_n( $eng / $all * 100 ) ) );
		}
		printf( '<p class="kc-ga-real"><b>%s</b> oturum aramadan, bağlantıdan, sosyal medyadan ya da yapay zekâ yanıtından geldi: gerçek okurun en güvenilir sayısı. 1.62.3\'ten önceki "doğrudan" trafiğin büyük kısmı bottu; yeni ölçüm yalnız insan davranışı gösteren ziyareti sayar.</p>', esc_html( daw_kc_n( $real ) ) );
		echo '<h3>Nereden geliyorlar</h3><ul class="kc-reasons">';
		foreach ( $r['channels'] as $c ) {
			printf( '<li><span>%s</span><b>%%%s</b></li>', esc_html( daw_ga4_channel_tr( $c[0] ) ), esc_html( daw_kc_n( $total ? $c[1] / $total * 100 : 0 ) ) );
		}
		echo '</ul>';
	}

	if ( ! empty( $r['pages'] ) ) {
		echo '<h3>En çok okunan sayfalar</h3><ol class="kc-top">';
		foreach ( array_slice( $r['pages'], 0, 6 ) as $pg ) {
			$avg = $pg[2] > 0 ? $pg[3] / max( 1, $pg[2] ) : 0;
			printf( '<li><a href="%1$s" target="_blank" rel="noopener">%2$s</a><span>%3$s görüntüleme · %4$s</span></li>', esc_url( home_url( $pg[0] ) ), esc_html( '/' === $pg[0] ? 'Ana sayfa' : untrailingslashit( $pg[0] ) ), esc_html( daw_kc_n( $pg[1] ) ), esc_html( gmdate( 'i:s', (int) $avg ) ) );
		}
		echo '</ol>';
	}

	$bits = array();
	foreach ( (array) ( $r['devices'] ?? array() ) as $dv ) {
		$bits[] = daw_ga4_device_tr( $dv[0] ) . ' ' . daw_kc_n( $dv[1] );
	}
	$ctry = array();
	foreach ( array_slice( (array) ( $r['countries'] ?? array() ), 0, 5 ) as $c ) {
		$ctry[] = $c[0] . ' ' . daw_kc_n( $c[1] );
	}
	printf( '<p class="kc-muted">Son 28 gün, önceki 28 güne göre. %s%s Güncelleme: %s.</p>', $bits ? esc_html( 'Cihaz: ' . implode( ', ', $bits ) . '. ' ) : '', $ctry ? esc_html( 'Ülke: ' . implode( ', ', $ctry ) . '. ' ) : '', esc_html( daw_kc_ago( (int) $r['at'] ) ) );

	daw_kc_card_close( array( 'Analytics\'te aç' => 'https://analytics.google.com/analytics/web/#/p' . daw_ga4_property() . '/reports/intelligenthome' ) );
}
