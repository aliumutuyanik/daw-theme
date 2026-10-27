<?php
/**
 * Discover Asian World: the watchdog.
 *
 * On 4 Oct 2026 an update stopped halfway and left daw-core uninstalled: the
 * site showed no records and the owner found out by looking at it. A plugin
 * cannot report its own absence, so daw-core writes a tiny must-use plugin
 * (wp-content/mu-plugins/daw-watchdog.php). WordPress always loads that file,
 * whatever happens to daw-core, and when daw-core or the theme is missing it
 * puts a red notice on every admin screen and e-mails the site address at
 * most once every six hours. It does nothing at all while all is well.
 *
 * A server that is down entirely cannot report itself either; for that a
 * free outside monitor (UptimeRobot, Better Stack) pinging the home page is
 * the answer, and the control centre says so.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

const DAW_WATCHDOG_VER = '1';

add_action( 'admin_init', 'daw_watchdog_install' );
function daw_watchdog_install() {
	if ( ! defined( 'WPMU_PLUGIN_DIR' ) || get_option( 'daw_watchdog_ver' ) === DAW_WATCHDOG_VER && file_exists( WPMU_PLUGIN_DIR . '/daw-watchdog.php' ) ) {
		return;
	}
	if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
		return;
	}

	$code = <<<'PHP'
<?php
/**
 * Plugin Name: DAW Watchdog
 * Description: Written by DAW Core. Warns the owner (admin notice and e-mail) when DAW Core or the DAW theme stops loading. Does nothing while all is well.
 * Version: 1
 */
defined( 'ABSPATH' ) || exit;

function daw_wd_problem() {
	$p = array();
	if ( ! defined( 'DAW_VERSION' ) ) {
		$p[] = 'DAW Core eklentisi yüklü ya da etkin değil (kayıtlar, SEO, site haritası çalışmıyor).';
	}
	if ( 'daw-theme' !== get_stylesheet() ) {
		$p[] = 'Etkin tema DAW teması değil.';
	}
	return $p;
}

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$p = daw_wd_problem();
	if ( $p ) {
		echo '<div class="notice notice-error"><p><strong>Discover Asian World bekçisi:</strong> ' . esc_html( implode( ' ', $p ) ) . ' Eklentiler sayfasından DAW Core zip dosyasını yükleyip etkinleştirin.</p></div>';
	}
} );

add_action( 'init', function () {
	$p = daw_wd_problem();
	if ( ! $p ) {
		if ( get_option( 'daw_wd_alerted' ) ) {
			delete_option( 'daw_wd_alerted' );
		}
		return;
	}
	if ( time() - (int) get_option( 'daw_wd_alerted', 0 ) < 6 * HOUR_IN_SECONDS ) {
		return;
	}
	update_option( 'daw_wd_alerted', time(), false );
	wp_mail(
		get_option( 'admin_email' ),
		'[Discover Asian World] Site sorunu: DAW Core çalışmıyor',
		"Bekçi bir sorun gördü:\n\n- " . implode( "\n- ", $p ) . "\n\nNe yapılmalı: WordPress yönetim paneli › Eklentiler › Yeni Ekle › Eklenti Yükle, son DAW Core zip dosyasını yükleyip Etkinleştir. Veriler veritabanında duruyor; eklenti geri gelince site olduğu gibi döner.\n\n" . home_url( '/' )
	);
}, 99 );
PHP;

	// phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false !== @file_put_contents( WPMU_PLUGIN_DIR . '/daw-watchdog.php', $code ) ) {
		update_option( 'daw_watchdog_ver', DAW_WATCHDOG_VER, true );
	}
}

/** Is the watchdog in place (for the control centre). */
function daw_watchdog_ok() {
	return defined( 'WPMU_PLUGIN_DIR' ) && file_exists( WPMU_PLUGIN_DIR . '/daw-watchdog.php' );
}
