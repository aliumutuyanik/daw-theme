<?php
/**
 * Analytics gate: GA4 loads only for a visitor who does something.
 *
 * WHAT IT DOES
 * ------------
 * Site Kit's own GA4 tag is switched off and the same measurement ID is loaded
 * by a small script of ours, once the visitor makes a real movement: pointer,
 * scroll, touch or key. Data-centre bots that open a page and leave within half
 * a second never move, so they never reach Analytics.
 *
 * WHAT IT KEEPS
 * -------------
 * - The consent defaults. Site Kit's consent mode (denied by default in the
 *   EEA, UK and Switzerland) is written first into the same dataLayer, so a
 *   visitor who has not agreed is still counted as not agreed. The consent
 *   message's later "update" call is processed in order, before the config.
 * - The measurement ID, read from Site Kit's own setting.
 * - The custom dimension googlesitekit_post_type.
 * - Search Console and SEO are untouched; this file prints nothing for them.
 *
 * - Google's consent message (1.50.1, fixed 1.50.2: a CMP that stays silent no longer blocks measurement). Where GDPR applies (EEA, UK,
 *   Switzerland) the script waits for the reader's answer in the AdSense
 *   Privacy & messaging banner (IAB TCF v2, read through __tcfapi) and loads
 *   nothing until purpose 1 is allowed. Outside those regions the banner
 *   reports gdprApplies=false and measurement starts at the first movement.
 *
 * WHAT IT COSTS (known, accepted)
 * -------------------------------
 * A real visitor who lands and neither moves, scrolls, touches nor presses a
 * key before leaving is not counted. The page view is also recorded at the
 * first movement, not at load, so session start times shift by a moment.
 * Compare Analytics organic sessions with Search Console clicks after the first
 * week: a steady ratio means the gate removed bots, not people.
 *
 * SWITCH OFF: define( 'DAW_GA_GATE_OFF', true ) in wp-config.php, or return
 * false from the filter daw_ga_gate_enabled. Site Kit's tag then comes back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Is the gate on at all? One place, so the tag block and the print agree. */
function daw_ga_gate_enabled() {
	if ( defined( 'DAW_GA_GATE_OFF' ) && DAW_GA_GATE_OFF ) {
		return false;
	}
	return (bool) apply_filters( 'daw_ga_gate_enabled', '' !== daw_ga_gate_measurement_id() );
}

/** Measurement ID from Site Kit's setting; the fixed value only if that is unreadable. */
function daw_ga_gate_measurement_id() {
	$settings = get_option( 'googlesitekit_analytics-4_settings' );
	$id       = ( is_array( $settings ) && ! empty( $settings['measurementID'] ) ) ? (string) $settings['measurementID'] : 'G-17J03CGX3L';
	return preg_match( '/^G-[A-Z0-9]+$/', $id ) ? $id : '';
}

/**
 * Seconds a visit must stay in view before it is measured (1.75.56). Was 4;
 * 10 is GA4's own "engaged" line, so what is counted is a reader who stayed.
 * Change with the filter daw_ga_gate_seconds.
 */
function daw_ga_gate_seconds() {
	return max( 4, min( 30, (int) apply_filters( 'daw_ga_gate_seconds', 10 ) ) );
}

/** Only the live domain, with or without www. */
function daw_ga_gate_live_host() {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	return in_array( $host, array( 'discoverasianworld.com', 'www.discoverasianworld.com' ), true );
}

/** Site Kit's own GA4 tag off: the filter, and the script handle itself as a second lock. */
add_filter(
	'googlesitekit_analytics-4_tag_blocked',
	static function ( $blocked ) {
		return daw_ga_gate_enabled() ? true : $blocked;
	}
);

add_action( 'wp_enqueue_scripts', 'daw_ga_gate_dequeue_kit', 9999 );
function daw_ga_gate_dequeue_kit() {
	if ( is_admin() || ! daw_ga_gate_enabled() ) {
		return;
	}
	// Two locks on purpose: if one of them stops working after a Site Kit
	// update, the tag would load twice and every visit would count twice.
	wp_dequeue_script( 'google_gtagjs' );
	wp_deregister_script( 'google_gtagjs' );
}

/**
 * Consent defaults as Site Kit holds them: the regions from its consent-mode
 * setting; when that setting cannot be read, the regions Site Kit printed on the
 * live site. Empty when consent mode is off in Site Kit.
 */
function daw_ga_gate_consent() {
	$cm = get_option( 'googlesitekit_consent_mode' );
	if ( is_array( $cm ) ) {
		if ( empty( $cm['enabled'] ) ) {
			return array();
		}
		$regions = ( ! empty( $cm['regions'] ) && is_array( $cm['regions'] ) ) ? array_values( array_filter( array_map( 'strval', $cm['regions'] ) ) ) : array();
	} else {
		$regions = array();
	}
	if ( ! $regions ) {
		$regions = array( 'AT', 'BE', 'BG', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GB', 'GR', 'HR', 'HU', 'IE', 'IS', 'IT', 'LI', 'LT', 'LU', 'LV', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK' );
	}
	return array(
		'ad_personalization'      => 'denied',
		'ad_storage'              => 'denied',
		'ad_user_data'            => 'denied',
		'analytics_storage'       => 'denied',
		'functionality_storage'   => 'denied',
		'security_storage'        => 'denied',
		'personalization_storage' => 'denied',
		'region'                  => $regions,
		'wait_for_update'         => 500,
	);
}

add_action( 'wp_head', 'daw_ga_gate_print', 5 );
function daw_ga_gate_print() {
	// Site Kit's setting: signed-in users are not tracked. Feeds, previews and
	// the customizer never are.
	if ( is_user_logged_in() || is_admin() || is_feed() || is_preview() || is_customize_preview() ) {
		return;
	}
	if ( ! daw_ga_gate_live_host() || ! daw_ga_gate_enabled() ) {
		return;
	}
	$id = daw_ga_gate_measurement_id();

	$config = array();
	if ( is_singular() ) {
		// Site Kit's own custom dimension, kept.
		$config['googlesitekit_post_type'] = get_post_type();
	}
	?>
<script id="daw-ga-gate">
(function(){
	var w=window,id=<?php echo wp_json_encode( $id ); ?>,cfg=<?php echo wp_json_encode( (object) $config ); ?>,cons=<?php echo wp_json_encode( (object) daw_ga_gate_consent() ); ?>,
		ev=['pointerdown','mousemove','scroll','wheel','keydown','touchstart','click'],
		interacted=false,human=false,moves=0,consentOk=null,loaded=false,tcfHooked=false,heard=false,i,
		shown=0,first=0,second=false,need=<?php echo (int) daw_ga_gate_seconds(); ?>*1000,since=document.visibilityState==='visible'?Date.now():0;
	w.dataLayer=w.dataLayer||[];
	w.gtag=w.gtag||function(){w.dataLayer.push(arguments);};
	// Consent default first, unless another consent script has already written it.
	function hasDefault(){
		for(var j=0;j<w.dataLayer.length;j++){
			var e=w.dataLayer[j];
			if(e&&e[0]==='consent'&&e[1]==='default'){return true;}
		}
		return false;
	}
	if(cons&&cons.analytics_storage&&!hasDefault()){w.gtag('consent','default',cons);}
	w.gtag('set','ads_data_redaction',true);
	// ONLY PEOPLE ARE COUNTED (1.62.3)
	// Analytics for the last 28 days showed 7,220 "users", 95% of them
	// "Direct", mostly from Singapore, China and Vietnam, desktop, eight
	// seconds a visit, 10% engaged: browsers driven by scripts, which fire
	// mouse and scroll events like anyone else. A visit is now measured only
	// when it looks like a reader: not an automated or headless browser, a
	// real screen, a real input (a tap, click, key, wheel or scroll, or
	// several mouse movements, each one generated by the device), and four
	// seconds with the page actually in view.
	var ua=navigator.userAgent||'';
	if(navigator.webdriver||/Headless|PhantomJS|Lighthouse|Puppeteer|Playwright|Selenium|Googlebot|bingbot|\bbot\b|bot\/|crawler|spider/i.test(ua)||!(navigator.languages&&navigator.languages.length)||(w.screen&&(screen.width<200||screen.height<200))){return;}
	function seen(){return shown+(since?Date.now()-since:0);}
	document.addEventListener('visibilitychange',function(){
		if(document.visibilityState==='visible'){since=Date.now();}
		else if(since){shown+=Date.now()-since;since=0;}
	});
	function tryLoad(){
		if(loaded||!human||!second||consentOk!==true){return;}
		loaded=true;
		var s=document.createElement('script');
		s.async=true;
		s.src='https://www.googletagmanager.com/gtag/js?id='+id;
		document.head.appendChild(s);
		w.gtag('js',new Date());
		w.gtag('config',id,cfg);
	}
	// Google's consent message (AdSense Privacy & messaging, IAB TCF v2).
	// Where GDPR applies, nothing loads until the reader allows purpose 1
	// (store and access information on a device). Elsewhere it loads at once.
	function onTcf(d,ok){
		if(!ok||!d){return;}
		heard=true;
		// Outside GDPR regions Google's CMP may report without an eventStatus.
		if(d.gdprApplies===false){consentOk=true;tryLoad();return;}
		if(d.eventStatus!=='tcloaded'&&d.eventStatus!=='useractioncomplete'){return;}
		var g=!!(d.purpose&&d.purpose.consents&&d.purpose.consents[1]);
		consentOk=g;
		w.gtag('consent','update',{analytics_storage:g?'granted':'denied'});
		tryLoad();
	}
	function hookTcf(){
		if(tcfHooked){return true;}
		if(typeof w.__tcfapi==='function'){
			tcfHooked=true;
			w.__tcfapi('addEventListener',2,onTcf);
			// A CMP that never answers must not silence measurement: after four
			// seconds without a word, load with the regional defaults (denied
			// in the EEA, UK and Switzerland, so no cookie is set there).
			setTimeout(function(){if(!heard&&consentOk===null){consentOk=true;tryLoad();}},4000);
			return true;
		}
		return false;
	}
	function onInteract(e){
		if(e&&e.isTrusted===false){return;}
		if(e&&e.type==='mousemove'&&++moves<(interacted?8:3)){return;}
		// 1.75.56: a second real input, 1.5 s or more after the first. A script
		// that scrolls once and leaves never gets there.
		if(interacted){
			if(!second&&Date.now()-first>=1500){
				second=true;
				for(i=0;i<ev.length;i++){w.removeEventListener(ev[i],onInteract,{passive:true});}
				check();
			}
			return;
		}
		interacted=true;first=Date.now();
		// Consent and the four seconds in view are waited for side by side.
		setTimeout(afterInteract,1200);
		check();
	}
	// Ten seconds in view (daw_ga_gate_seconds), counted only while the tab is visible; never
	// sooner than 1.2 s after the input, so the reader's tap goes first.
	function check(){
		var left=need-seen();
		if(!second){return;}
		if(left<=0&&document.visibilityState==='visible'){human=true;tryLoad();return;}
		setTimeout(check,Math.max(1200,left));
	}
	function afterInteract(){
		if(hookTcf()){tryLoad();return;}
		// The message arrives with the ad library, which itself waits for this
		// same first movement. If no message has appeared after four seconds,
		// load with the regional defaults: denied in the EEA, UK and
		// Switzerland, so Analytics sets no cookie there.
		var n=0,t=setInterval(function(){
			if(hookTcf()){clearInterval(t);return;}
			if(++n>=20){clearInterval(t);if(consentOk===null){consentOk=true;tryLoad();}}
		},200);
	}
	hookTcf();
	for(i=0;i<ev.length;i++){w.addEventListener(ev[i],onInteract,{passive:true});}
})();
</script>
	<?php
}
