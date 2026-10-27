<?php
/**
 * Single record: person.
 *
 * Built to the approved profile mockup. The shape of the page follows a real
 * order of questions: who is this and what are they in (hero), the short
 * version (overview), every field we hold (profile data), the long version
 * (deep dive), then the work itself (music, filmography), then the people and
 * stories around them.
 *
 * NOTHING HERE IS TYPED TWICE
 * ---------------------------
 * The filmography, the credit count, the active years and "frequently worked
 * with" are all read backwards out of the cast blocks on title pages. Adding an
 * actor to a drama's cast is the only entry that ever happens; this page
 * updates itself.
 *
 * Every block checks for content first. A profile halfway through research
 * shows what it has and stays silent about the rest. It does not announce how
 * much is missing; an empty field is simply absent.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$id = get_the_ID();

	/* --------------------------------------------------------- identity -- */
	$native  = daw_field( 'daw_native_name' );
	$real    = daw_field( 'daw_real_name' );
	$nation  = daw_field( 'daw_nationality' );
	$gender  = daw_field( 'daw_gender' );
	$religion = daw_field( 'daw_religion' );
	// A handful of records were imported with their ampersand already encoded,
	// so "Actor & model" came out of the database as "Actor &amp; model" and
	// the eyebrow, escaped again on the way out, printed "ACTOR &AMP; MODEL".
	// Decoding on read fixes every record at once, including the ones nobody
	// has noticed yet, and a value that was never encoded passes through it
	// unchanged.
	$job     = wp_specialchars_decode( (string) daw_field( 'daw_occupation' ), ENT_QUOTES );
	$agency  = daw_field( 'daw_agency' );
	// A soloist who is also in a band. The band is a record of its own, so this
	// is a link, not a line of text, and it is empty for everyone who is not in
	// one rather than printing a dash.
	$group   = function_exists( 'daw_person_group' ) ? daw_person_group( $id ) : null;
	$mbti    = daw_field( 'daw_mbti' );
	$country = daw_terms( 'dw_country' );

	/* ---------------------------------------------------- born, physical -- */
	$born    = (int) daw_field( 'daw_born' );
	$died    = (int) daw_field( 'daw_died' );
	$age     = function_exists( 'daw_person_age' ) ? daw_person_age( $id ) : 0;
	$place   = daw_field( 'daw_birth_place' );
	$zodiac  = daw_field( 'daw_zodiac' );
	$czodiac = daw_field( 'daw_chinese_zodiac' );
	$height  = (int) daw_field( 'daw_height_cm' );
	$weight  = (int) daw_field( 'daw_weight_kg' );
	$blood   = daw_field( 'daw_blood_type' );
	$feet    = function_exists( 'daw_height_imperial' ) ? daw_height_imperial( $height ) : '';

	/* ----------------------------------------------------------- status -- */
	$marital  = daw_field( 'daw_marital_status' );
	$children = daw_field( 'daw_children' );
	$partner  = daw_field( 'daw_partner' );
	$pairings = daw_field( 'daw_pairings' );

	// The field stores a value, the page shows a word. Gender was the one row
	// printing its stored value straight through, so the table read "male".
	$gender_labels = array(
		'male'   => __( 'Male', 'daw' ),
		'female' => __( 'Female', 'daw' ),
		'other'  => __( 'Other', 'daw' ),
	);

	$marital_labels = array(
		'single'   => __( 'Not married', 'daw' ),
		'married'  => __( 'Married', 'daw' ),
		'divorced' => __( 'Divorced', 'daw' ),
		'widowed'  => __( 'Widowed', 'daw' ),
	);

	/* --------------------------------------------------------- overview -- */
	$summary   = daw_field( 'daw_person_summary' );
	$takeaways = array_filter( (array) daw_field( 'daw_person_takeaways', $id, false ) );
	$sections  = function_exists( 'daw_get_person_sections' ) ? daw_get_person_sections( $id ) : array();
	$sections  = array_values(
		array_filter(
			$sections,
			static function ( $s ) {
				return '' !== trim( (string) $s['title'] ) && '' !== trim( (string) $s['body'] );
			}
		)
	);

	// FALLBACK: a record whose biography has not yet been split into titled
	// H2 sections would otherwise render nothing at all in the main reading
	// column -- worse than the duplicate-with-About-panel bug this replaced
	// (K14 fix, 27.09.2026, see the note on daw_biography further down).
	// Kim Hyun Joo (post 6567) is the record that surfaced this: daw_biography
	// holds three real paragraphs, daw_person_section_title/body is empty,
	// and the page showed no biography anywhere. When no titled sections
	// exist yet, the raw biography becomes one section so the page is never
	// blank; once an editor splits it into real sections this stops firing
	// on its own, since $sections will no longer be empty.
	if ( ! $sections ) {
		$raw_bio = daw_field( 'daw_biography', $id );
		// Defensive: daw_biography is a single wysiwyg field and should always
		// be a string, but if a past write ever stored it as an array, a bare
		// (string) cast prints the literal word "Array" instead of the
		// content (the same class of bug found live on daw_synopsis, see
		// daw_prose() in functions.php, 2026-10-01).
		if ( is_array( $raw_bio ) ) {
			$raw_bio = implode( "\n\n", array_filter( array_map( 'strval', $raw_bio ) ) );
		}
		$raw_bio = trim( (string) $raw_bio );
		if ( '' !== $raw_bio ) {
			$sections = array(
				array(
					// "Biography" is itself one of the highest-volume search patterns
					// on a person page (K25/madde 46-49 list it beside age, height,
					// wife). The person's own name was used here at first, which threw
					// that pattern away for every record still waiting to be split into
					// titled sections. Changed 28.09.2026 after the site owner asked
					// for a "Biography" entry to always be reachable from the page.
					'title' => __( 'Biography', 'daw' ),
					'body'  => $raw_bio,
				),
			);
		}
	}

	// A record already split into titled H2 sections (K11: each section's title
	// is the search term it satisfies, e.g. "About those eyes") has no single
	// heading that says "Biography" -- the word itself is a top search pattern
	// a reader or a search engine still looks for. Rather than rename an
	// editor's keyword-researched title, an umbrella "Biography" anchor is
	// added ahead of the named sections when there is more than one of them,
	// so both the specific title(s) and the generic term are reachable. A lone
	// section keeps its own title (or, in the fallback above, is already
	// titled "Biography") -- one heading, not two saying the same thing (K14).
	// A single section with its own keyword title ("Shin Do Hyun's TV Shows and
	// Career") also left the page without any "Biography" heading, which K-g
	// requires on every person page (6 Oct 2026), so it gets the umbrella too.
	$bio_wrap = count( $sections ) > 1
		|| ( 1 === count( $sections ) && 0 !== strcasecmp( trim( (string) $sections[0]['title'] ), __( 'Biography', 'daw' ) ) );

	// Optional short intro under the "Biography" umbrella heading, before the
	// named subsections -- e.g. "Seo Kang Joon has spent thirteen years..."
	// A few sentences setting up what the subsections cover, not a rehash of
	// daw_person_summary (the one-line hero blurb) or of any subsection's own
	// content (K14). Empty on most records; nothing prints when it is empty.
	$bio_intro = trim( (string) daw_field( 'daw_bio_intro', $id ) );

	/* ------------------------------------------------------------ career -- */
	$career = function_exists( 'daw_get_career' ) ? daw_get_career( $id ) : array();
	$career = array_values(
		array_filter(
			$career,
			static function ( $r ) {
				return '' !== trim( (string) $r['event'] );
			}
		)
	);
	$debut = daw_field( 'daw_debut' );

	$film    = function_exists( 'daw_get_filmography' ) ? daw_get_filmography( $id ) : array();
	// Never index a bucket that might not be there: this template outlives any
	// one version of the plugin that feeds it.
	$film   += array( 'lead' => array(), 'supporting' => array(), 'guest' => array(), 'other' => array(), 'crew' => array() );
	// Asked for twice in the hero: once to decide whether the portrait column
	// prints the button, and once to decide whether the strip has anything in it.
	$has_film = (bool) ( $film['lead'] || $film['supporting'] || $film['guest'] || $film['other'] || $film['crew'] );
	// What is confirmed to come, and what is on air now (news-live.php, 1.75.0).
	$upnext_html = function_exists( 'daw_person_upcoming_html' ) ? daw_person_upcoming_html( $id ) : '';
	$credits = function_exists( 'daw_credit_count' ) ? daw_credit_count( $id ) : 0;
	$active  = function_exists( 'daw_active_years' ) ? daw_active_years( $id ) : '';
	// Older plugin builds returned only the string, newer ones also know what to
	// call it. Ask for the label if it is there, fall back to the plain word if
	// it is not, and never assume the two halves of the site are the same age.
	$active_label = function_exists( 'daw_active_years_label' ) ? daw_active_years_label( $id ) : __( 'Active', 'daw' );

	/* ---------------------------------------------------------- the rest -- */
	$music    = function_exists( 'daw_get_music_credits' ) ? daw_get_music_credits( $id ) : array();
	$spotify  = daw_field( 'daw_spotify_artist' );
	$shop     = function_exists( 'daw_get_shop' ) ? daw_get_shop( $id ) : array();
	$faq      = function_exists( 'daw_get_person_faq' ) ? daw_get_person_faq( $id ) : array();
	// NOT $mates. THAT NAME IS TAKEN FURTHER DOWN
	// -------------------------------------------
	// The group section rebuilds a variable called $mates from the band's
	// line-up, hundreds of lines below this one and hundreds above the place
	// this list is printed. So the collaborators were computed here, thrown
	// away there, and the section at the foot of the page rendered empty on
	// every profile on the site while its jump link still pointed at it.
	$worked_with = function_exists( 'daw_worked_with' ) ? daw_worked_with( $id, 6 ) : array();
	$news     = function_exists( 'daw_get_related_news' ) ? daw_get_related_news( $id, 4 ) : array();
	// THE AWARDS MOVED OUT OF THE RAIL
	// --------------------------------
	// They used to be a small card in the sidebar: a year and one line of text,
	// with no ceremony, no category, and no way to reach the drama the prize
	// was for. A career's worth of prizes is not sidebar furniture, so the rows
	// are read here once and printed as a full table under the news. The jump
	// bar needs to know about them before the section is reached, which is the
	// other reason they are read this early.
	$pawards  = function_exists( 'daw_get_person_awards' ) ? daw_get_person_awards( $id ) : array();
	// COMPLETENESS IS AN EDITOR'S TOOL, NOT A READER'S
	// ------------------------------------------------
	// "34 of 44" tells the newsroom what is left to research. To a reader it
	// only advertises the gaps, and to a search engine it is a quality signal
	// pointing the wrong way. So it is computed for everyone and printed for
	// nobody except someone who can actually go and fill the missing fields.
	// "Fields filled: 34 of 44" is gone from this page entirely.
	// It was written as an editor's progress bar and it was never that: it does
	// not say WHICH fields are missing, so nobody could act on it, and the only
	// thing it reliably communicated was that the record is incomplete. To a
	// reader that is a reason to leave. A real gap report belongs in wp-admin,
	// over all 926 records at once, not as a number under one person's name.

	/* --------------------------------------------------------- deep dive -- */
	// daw_biography is deliberately NOT in this list. It is the page's main
	// biography, already shown near the top of every person page, and it is
	// filled on almost every record. Including it here duplicated that same
	// text a second time inside this "About [Name]" panel on essentially
	// every person page on the site (found live on the K1 benchmark record,
	// Seo Kang Joon, and reported by the site owner) -- a direct violation of
	// this site's own rule that the same information is never written twice
	// on a page. Removed 27.09.2026.
	$panels = array(
		'daw_relationships'     => __( 'Relationships and dating', 'daw' ),
		'daw_hobbies'           => __( 'Hobbies', 'daw' ),
		'daw_interests'         => __( 'Interests', 'daw' ),
		'daw_facts'             => __( 'Facts', 'daw' ),
		'daw_secrets'           => __( 'Lesser-known details', 'daw' ),
		'daw_funny_things'      => __( 'Funny moments', 'daw' ),
		'daw_fears'             => __( 'Fears and dislikes', 'daw' ),
		'daw_endorsements'      => __( 'Commercials and endorsements', 'daw' ),
		'daw_sports'            => __( 'Sports and fitness', 'daw' ),
		'daw_instruments_panel' => __( 'Musical instruments', 'daw' ),
		'daw_military_panel'    => __( 'Military service', 'daw' ),
	);

	$filled = array();
	foreach ( $panels as $key => $label ) {
		if ( trim( (string) daw_field( $key, $id ) ) !== '' ) {
			$filled[ $key ] = $label;
		}
	}

	/* -------------------------------------------------------------- nav -- */
	$nav = array( 'overview' => __( 'Overview', 'daw' ) );
	$nav['profile-data'] = __( 'Profile data', 'daw' );
	if ( $filled ) {
		$nav['deep-dive'] = __( 'About', 'daw' );
	}
	if ( '' !== $upnext_html ) {
		// Same rule as the section heading: on air is not "upcoming".
		$up_on  = false !== strpos( $upnext_html, 'daw-upnext-badge is-on' );
		$up_new = false !== strpos( $upnext_html, 'daw-upnext-badge"' ) || false !== strpos( $upnext_html, 'daw-upnext-none' );
		$nav['upcoming'] = $up_on ? ( $up_new ? __( 'On air and upcoming', 'daw' ) : __( 'On air now', 'daw' ) ) : __( 'Upcoming', 'daw' );
	}
	if ( $film['lead'] || $film['supporting'] || $film['guest'] || $film['other'] || $film['crew'] ) {
		$nav['filmography'] = __( 'Filmography', 'daw' );
	}
	if ( $music || $spotify ) {
		$nav['music'] = __( 'Music', 'daw' );
	}
	// The jump bar is a map of the page, so it runs in the page's order.
	if ( $news ) {
		$nav['person-news'] = __( 'News', 'daw' );
	}
	if ( $pawards ) {
		$nav['person-awards'] = __( 'Awards', 'daw' );
	}
	if ( $faq ) {
		$nav['faq'] = __( 'FAQ', 'daw' );
	}
	if ( $worked_with ) {
		$nav['worked-with'] = __( 'Worked with', 'daw' );
	}
	?>

<div class="daw-wrap daw-person-page">

	<?php daw_crumb_nav( $id ); ?>
</div>

<?php // ======================================================== hero ===== ?>
<header class="daw-phero daw-phero-person" id="overview">
	<div class="daw-wrap">
		<div class="daw-phero-in">

			<div class="daw-phero-portrait">
				<div class="daw-poster">
					<?php
					$portrait = daw_poster( $id, 'medium_large' );
					echo $portrait ? str_replace( 'loading="lazy"', 'loading="eager" fetchpriority="high"', $portrait ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput
					?>
				</div>
				<?php
				// Every picture carries its origin: a licence with links for a
				// recorded image, the rights holder for a TMDB one.
				$hero_credit = function_exists( 'daw_image_credit_for_post' ) ? daw_image_credit_for_post( $id ) : '';
				if ( '' !== $hero_credit ) :
					?>
					<p class="daw-hero-credit"><?php echo wp_kses( $hero_credit, array( 'a' => array( 'href' => true, 'rel' => true, 'target' => true ) ) ); ?></p>
				<?php endif; ?>

				<?php
				// THE BUTTON SITS UNDER THE FACE
				// ------------------------------
				// It spent one version as the last cell of the stat strip,
				// which cost the hero a whole row: the strip is already two
				// columns below 1200px, so the button pushed the summary and
				// everything under it further down on exactly the screens
				// with the least room.
				//
				// Under the portrait it costs nothing. That column ends where
				// the photograph ends and the space beneath it was empty on
				// every record, on a desktop and on a phone alike. So the one
				// control the page is opened for now fills dead space instead
				// of making new space, and it is still the first thing beside
				// the name.
				if ( $has_film ) :
					?>
					<a class="daw-btn daw-btn-p daw-phero-film" href="#filmography"><?php esc_html_e( 'Filmography', 'daw' ); ?></a>
				<?php endif; ?>
			</div>

			<div class="daw-phero-id">
				<p class="daw-eyebrow">
					<?php
					echo esc_html( implode( ' · ', array_filter( array( $job, $country ) ) ) );
					?>
				</p>

				<h1><?php the_title(); ?></h1>

				<?php if ( $native ) : ?>
					<p class="daw-native"><span lang="ko"><?php echo esc_html( $native ); ?></span></p>
				<?php endif; ?>

				<?php if ( $group ) : ?>
					<p class="daw-pgroup">
						<?php
						printf(
							/* translators: %s: the group this person is a member of */
							esc_html__( 'Member of %s', 'daw' ),
							sprintf(
								'<a href="%s">%s</a>',
								esc_url( (string) get_permalink( $group ) ),
								esc_html( get_the_title( $group ) )
							)
						);
						?>
					</p>
				<?php endif; ?>

				<?php
				// THE CHIP ROW IS GONE
				// --------------------
				// It held occupation, country, agency and MBTI. The first two are
				// the eyebrow directly above it, printed a second time three
				// lines later. The agency is searched forty times a month against
				// fifty five thousand for the credit list, and it has a row of
				// its own in the career card below. MBTI is ninety a month and is
				// in the personality card. So the row was two duplicates and two
				// facts nobody came for, sitting in the most valuable space on
				// the page.
				?>

				<?php
				// The five facts a profile is opened for. Each one only appears
				// if we actually hold it, so the strip never shows a blank slot.
				$stats = array();

				if ( $born ) {
					$stats[] = array(
						'v' => date_i18n( 'j M Y', $born ),
						'l' => $died
							? __( 'Born', 'daw' )
							/* translators: %d: age in years */
							: sprintf( __( 'Born · age %d', 'daw' ), $age ),
					);
				}
				if ( $died ) {
					$stats[] = array(
						'v' => date_i18n( 'j M Y', $died ),
						/* translators: %d: age at death */
						'l' => sprintf( __( 'Died · age %d', 'daw' ), $age ),
					);
				}
				// THE BIRTHPLACE IS NOT A HEADLINE FACT
				// -------------------------------------
				// It was the longest value in the strip by a wide margin,
				// "Gunpo, Gyeonggi Province, South Korea" against "183 cm", so
				// it set the width of a row of short facts. And nobody arrives
				// asking it: it does not appear anywhere in this person's search
				// demand, while the credit list is over fifty thousand a month.
				// Written out in full, country included, it belongs in the
				// Profile data table below, where a long value costs nothing and
				// where a reader can tell Gunpo from Bucheon without knowing
				// Korean geography.
				if ( $height ) {
					$stats[] = array(
						'v' => $feet ? sprintf( '%d cm · %s', $height, $feet ) : $height . ' cm',
						'l' => __( 'Height', 'daw' ),
					);
				}
				// The label travels with the value, because the two are decided
				// together: "2012 to present" and "Active since 2016" are
				// different claims and only one of them is safe to make about
				// any given person.
				if ( $active ) {
					$stats[] = array( 'v' => $active, 'l' => $active_label ? $active_label : __( 'Active', 'daw' ) );
				}
				if ( $credits ) {
					$stats[] = array( 'v' => number_format_i18n( $credits ), 'l' => __( 'Screen credits', 'daw' ) );
				}

				if ( $stats ) :
					?>
					<div class="daw-pstats">
						<?php foreach ( array_slice( $stats, 0, 5 ) as $s ) : ?>
							<div class="daw-pstat">
								<div class="daw-pstat-v"><?php echo esc_html( $s['v'] ); ?></div>
								<div class="daw-pstat-l"><?php echo esc_html( $s['l'] ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( trim( (string) $summary ) !== '' ) : ?>
					<p class="daw-phero-sum"><?php echo esc_html( wp_strip_all_tags( $summary ) ); ?></p>
				<?php endif; ?>

			</div>

		</div>
	</div>
</header>

<div class="daw-wrap daw-person-page">

	<?php if ( count( $nav ) > 2 ) : ?>
		<nav class="daw-stickynav" aria-label="<?php esc_attr_e( 'On this page', 'daw' ); ?>">
			<ul>
				<?php foreach ( $nav as $anchor => $label ) : ?>
					<li><a href="#<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $label ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
	<?php endif; ?>

	<?php // =================================================== overview == ?>
	<div class="daw-pcols daw-section">

		<?php
		// The jump bar above already lists the page. This lists what is inside
		// the reading column, which is what a reader is choosing between once
		// they have started reading. With nothing to choose between, no card.
		$toc = array();
		if ( $bio_wrap ) {
			$toc['biography'] = __( 'Biography', 'daw' );
		}
		foreach ( $sections as $sec ) {
			$toc[ sanitize_title( $sec['title'] ) ] = $sec['title'];
		}
		if ( $filled ) {
			$toc['deep-dive'] = __( 'About', 'daw' );
		}
		?>
		<aside class="daw-ptoc">
			<div class="daw-rcard" <?php echo $toc ? '' : 'hidden'; ?>>
				<div class="daw-rcard-h"><?php esc_html_e( 'On this page', 'daw' ); ?></div>
				<div class="daw-rcard-b">
					<ul>
						<?php foreach ( $toc as $anchor => $label ) : ?>
							<li><a href="#<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $label ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		</aside>

		<?php ob_start(); ?>
		<div class="daw-maincol">
			<?php if ( $takeaways ) : ?>
				<div class="daw-takeaways">
					<h2><?php esc_html_e( 'Binge Brief', 'daw' ); ?></h2>
					<ul>
						<?php foreach ( $takeaways as $t ) : ?>
							<li><?php echo esc_html( $t ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( $bio_wrap ) : ?>
				<h2 id="biography" style="margin-top:26px"><?php esc_html_e( 'Biography', 'daw' ); ?></h2>
				<?php if ( '' !== $bio_intro ) : ?>
					<div class="daw-prose" style="margin-top:12px"><?php echo wp_kses_post( daw_prose( $bio_intro ) ); ?></div>
				<?php endif; ?>
			<?php endif; ?>

			<?php foreach ( $sections as $i => $sec ) : ?>
				<section id="<?php echo esc_attr( sanitize_title( $sec['title'] ) ); ?>" style="margin-top:<?php echo ( 0 === $i && ! $bio_wrap ) ? '26' : '30'; ?>px">
					<h2><?php echo esc_html( $sec['title'] ); ?></h2>
					<div class="daw-prose" style="margin-top:12px"><?php echo wp_kses_post( daw_prose( $sec['body'] ) ); ?></div>
				</section>
			<?php endforeach; ?>

			<?php daw_ad_slot( 'daw-ad-incontent' ); ?>
		</div>
		<?php
		// THE COLUMN DECIDES WHETHER THERE IS ROOM FOR A SECOND ADVERT
		// -----------------------------------------------------------
		// The rail is a fixed stack of cards. On a record with little text the
		// rail stood taller than the article beside it, so the page was being
		// stretched by its own adverts. The column is captured here and handed
		// to daw_ad_rail(), which prints the lower unit only when there is
		// enough article to sit beside it. Nothing is escaped again: this is
		// the same string that was about to be printed.
		$main_html = ob_get_clean();
		echo $main_html; // phpcs:ignore WordPress.Security.EscapeOutput
		?>

		<aside class="daw-rail">
			<?php
			// MOST ASKED, NOT "AT A GLANCE"
			// -----------------------------
			// The card that used to sit here listed native name, born, height,
			// agency, debut and credit count. Every one of those was already in
			// the hero or in the Profile data table below, so a reader met the
			// same height three times on one page, and the card answered none of
			// the questions that actually brought them.
			//
			// The search numbers decide the order now. For this person: what he
			// acted in (over 55,000 a month across languages), whether he is
			// married (about 6,000), his eye colour (about 6,500, more than his
			// age and height put together), then the new drama, then age, then
			// height. Native name: nobody. Agency: forty people a month.
			//
			// Three of these are the same question for everyone with a credit
			// list, so the template works them out. The one that is specific to
			// the person is typed into "Person: most asked" by whoever looked the
			// demand up, because which question is the big one is a fact about
			// readers and cannot be derived from the record.
			//
			// Every row is an anchor into the long version further down. That is
			// the whole point of the card: it answers in one line, and the reader
			// who wants more goes down the page rather than back to the results.
			$asked = function_exists( 'daw_get_person_asked' ) ? daw_get_person_asked( $id ) : array();
			$rows  = array();

			foreach ( $asked as $row ) {
				$rows[] = array(
					'q'  => $row['q'],
					'a'  => $row['a'],
					'to' => trim( (string) $row['to'] ),
				);
			}

			if ( $credits ) {
				$lead   = count( $film['lead'] );
				$answer = sprintf(
					/* translators: %s: number of credits */
					_n( '%s title', '%s titles', $credits, 'daw' ),
					number_format_i18n( $credits )
				);
				if ( $lead ) {
					/* translators: 1: credit count phrase, 2: number of lead roles */
					$answer = sprintf( __( '%1$s, %2$s as lead', 'daw' ), $answer, number_format_i18n( $lead ) );
				}
				$rows[] = array(
					'q'  => __( 'Dramas and films', 'daw' ),
					'a'  => $answer,
					'to' => '#filmography',
				);
			}

			// The one question a profile is asked about a living actor more than
			// any other after the credit list. Answered from the record: a
			// confirmed partner if there is one, otherwise the marital status,
			// and nothing at all if neither has been established.
			$status_answer = '';
			if ( '' !== trim( (string) $partner ) ) {
				$status_answer = $partner;
			} elseif ( '' !== trim( (string) $marital ) ) {
				$status_answer = $marital_labels[ $marital ] ?? $marital;
			}
			if ( $status_answer ) {
				$rows[] = array(
					'q'  => __( 'Married or dating', 'daw' ),
					'a'  => $status_answer,
					'to' => '#profile-data',
				);
			}

			// Military service left the quick answers at the top (Umut, 16 Oct
			// 2026): few readers look for it. It stays in Profile data, last.

			// daw_get_related_news() hands back post objects, the same as the
			// list further down. get_the_title() takes either, so it is asked
			// rather than reached into.
			if ( $news ) {
				$headline = get_the_title( $news[0] );

				if ( '' !== trim( (string) $headline ) ) {
					$rows[] = array(
						'q'  => __( 'Latest', 'daw' ),
						'a'  => $headline,
						'to' => '#person-news',
					);
				}
			}

			if ( $born ) {
				$rows[] = array(
					'q'  => __( 'Age and birthday', 'daw' ),
					/* translators: 1: age, 2: date of birth */
					'a'  => $age ? sprintf( __( '%1$s, born %2$s', 'daw' ), number_format_i18n( $age ), date_i18n( 'j F Y', $born ) ) : date_i18n( 'j F Y', $born ),
					'to' => '#profile-data',
				);
			}

			if ( $height ) {
				$rows[] = array(
					'q'  => __( 'Height', 'daw' ),
					'a'  => $feet ? sprintf( '%d cm (%s)', $height, $feet ) : $height . ' cm',
					'to' => '#profile-data',
				);
			}

			// Six is the cap, and it is a cap rather than a target: a person with
			// three answers gets a card with three rows, not three rows and three
			// apologies.
			$rows = array_slice( $rows, 0, 6 );

			if ( $rows ) :
				?>
				<div class="daw-rcard daw-asked">
					<div class="daw-rcard-h"><?php esc_html_e( 'Most asked', 'daw' ); ?></div>
					<div class="daw-rcard-b">
						<dl>
							<?php foreach ( $rows as $row ) : ?>
								<div class="daw-asked-row">
									<dt><?php echo esc_html( $row['q'] ); ?></dt>
									<dd>
										<?php if ( '' !== $row['to'] && 0 === strpos( $row['to'], '#' ) ) : ?>
											<a href="<?php echo esc_attr( $row['to'] ); ?>"><?php echo esc_html( $row['a'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $row['a'] ); ?>
										<?php endif; ?>
									</dd>
								</div>
							<?php endforeach; ?>
						</dl>
					</div>
				</div>
			<?php endif; ?>

			<?php daw_ad_rail( $main_html ); ?>
		</aside>
	</div>

	<?php // =============================================== profile data == ?>
	<?php
	// Each card is a group of fields, and a card with nothing in it is never
	// printed. That is what makes "38 of 44" an honest number rather than a
	// page full of empty rows.
	$cards = array(
		array(
			'label' => __( 'Identity', 'daw' ),
			'rows'  => array(
				__( 'Stage name', 'daw' )  => get_the_title( $id ),
				__( 'Native name', 'daw' ) => $native,
				__( 'Real name', 'daw' )   => $real,
				__( 'Nationality', 'daw' ) => $nation,
				__( 'Gender', 'daw' )      => isset( $gender_labels[ $gender ] ) ? $gender_labels[ $gender ] : $gender,
				__( 'Religion', 'daw' )    => $religion,
			),
		),
		array(
			'label' => __( 'Born and physical', 'daw' ),
			'rows'  => array(
				__( 'Date of birth', 'daw' )  => $born ? date_i18n( 'j F Y', $born ) : '',
				__( 'Date of death', 'daw' )  => $died ? date_i18n( 'j F Y', $died ) : '',
				__( 'Age', 'daw' )            => $age ? (string) $age : '',
				__( 'Birthplace', 'daw' )     => $place,
				__( 'Zodiac', 'daw' )         => $zodiac,
				__( 'Chinese zodiac', 'daw' ) => $czodiac,
				__( 'Height', 'daw' )         => $height ? ( $feet ? sprintf( '%d cm (%s)', $height, $feet ) : $height . ' cm' ) : '',
				__( 'Weight', 'daw' )         => $weight ? $weight . ' kg' : '',
				__( 'Blood type', 'daw' )     => $blood,
			),
		),
		array(
			'label' => __( 'Personal status', 'daw' ),
			'rows'  => array(
				__( 'Marital status', 'daw' )     => $marital ? ( $marital_labels[ $marital ] ?? $marital ) : '',
				__( 'Children', 'daw' )           => $children,
				__( 'Confirmed partner', 'daw' )  => $partner,
				__( 'Reported pairings', 'daw' )  => $pairings,
			),
		),
		array(
			'label' => __( 'Education', 'daw' ),
			'rows'  => array(
				__( 'Elementary', 'daw' )  => daw_field( 'daw_edu_elementary' ),
				__( 'Middle school', 'daw' ) => daw_field( 'daw_edu_middle' ),
				__( 'High school', 'daw' ) => daw_field( 'daw_edu_high' ),
				__( 'University', 'daw' )  => daw_field( 'daw_edu_university' ),
			),
		),
		array(
			'label'  => __( 'Career timeline', 'daw' ),
			'rows'   => array(),
			'career' => true,
		),
		array(
			'label' => __( 'Family', 'daw' ),
			'rows'  => array(
				__( 'Father', 'daw' )   => daw_field( 'daw_fam_father' ),
				__( 'Mother', 'daw' )   => daw_field( 'daw_fam_mother' ),
				__( 'Siblings', 'daw' ) => daw_field( 'daw_fam_siblings' ),
			),
		),
		array(
			'label' => __( 'Personality', 'daw' ),
			'rows'  => array(
				__( 'MBTI', 'daw' )        => $mbti,
				__( 'Traits', 'daw' )      => daw_field( 'daw_traits' ),
				__( 'Skills', 'daw' )      => daw_field( 'daw_skills' ),
				__( 'Instruments', 'daw' ) => daw_field( 'daw_instruments' ),
			),
		),
		// Military service sits last: readers rarely look for it (Umut, 16 Oct
		// 2026). The old "Data and sourcing" card is gone; the photo credit the
		// licence needs already sits under the hero picture.
		array(
			'label' => __( 'Military service', 'daw' ),
			'rows'  => array(
				__( 'Status', 'daw' ) => daw_person_military_label( daw_field( 'daw_mil_status' ) ),
				__( 'Branch', 'daw' ) => daw_field( 'daw_mil_branch' ),
				__( 'Unit', 'daw' )   => daw_field( 'daw_mil_unit' ),
				__( 'Served', 'daw' ) => daw_field( 'daw_mil_served' ),
			),
		),
	);
	?>

	<section class="daw-pdata" id="profile-data">
		<div class="daw-pdata-head">
			<div>
				<h2><?php esc_html_e( 'Profile data', 'daw' ); ?></h2>
				
			</div>
			<?php
			$correction = daw_page_url( 'request-a-correction' );
			if ( $correction ) :
				?>
				<a class="daw-correction" href="<?php echo esc_url( $correction ); ?>"><?php esc_html_e( 'Report a correction', 'daw' ); ?></a>
			<?php endif; ?>
		</div>

		<div class="daw-pgrid">
			<?php
			foreach ( $cards as $card ) :
				$rows = array_filter(
					$card['rows'],
					static function ( $v ) {
						return '' !== trim( (string) $v );
					}
				);

				$is_career = ! empty( $card['career'] );

				if ( ! $rows && ! ( $is_career && ( $career || $agency ) ) ) {
					continue;
				}
				?>
				<div class="daw-pcard">
					<div class="daw-pcard-h"><?php echo esc_html( $card['label'] ); ?></div>
					<dl class="daw-pcard-b">
						<?php
						if ( $is_career ) {
							foreach ( $career as $row ) {
								daw_rrow( $row['year'], $row['event'] );
							}
							daw_rrow( __( 'Agency', 'daw' ), $agency );

							// daw_rrow escapes what it prints, so the row carries
							// the name and the hero above carries the link.
							if ( $group ) {
								daw_rrow( __( 'Member of', 'daw' ), get_the_title( $group ) );
							}
						} else {
							foreach ( $rows as $label => $value ) {
								daw_rrow( $label, $value );
							}
						}
						?>
					</dl>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

	<?php daw_ad_slot( 'daw-ad-leaderboard' ); ?>

	<?php // ================================================== deep dive == ?>
	<?php if ( $filled ) : ?>
		<div class="daw-cols daw-section" id="deep-dive">
			<div class="daw-maincol">
				<?php
				// "Deep dive" was a publishing word with no search behind it, and
				// it was the one heading on the page that never said whose page
				// this is. The name belongs here, once: the panels underneath
				// carry the topics, and this line tells a reader and a crawler
				// which person those topics are about.
				?>
				<h2 class="daw-h2">
					<?php
					printf(
						/* translators: %s: the person's name */
						esc_html__( 'About %s', 'daw' ),
						esc_html( get_the_title( $id ) )
					);
					?>
				</h2>
				

				<?php foreach ( $filled as $key => $label ) : ?>
					<details class="daw-acc" <?php echo ( 'daw_relationships' === $key ) ? 'open' : ''; ?>>
						<summary><h3><?php echo esc_html( $label ); ?></h3></summary>
						<div class="daw-acc-body daw-prose"><?php echo wp_kses_post( daw_prose( daw_field( $key, $id ) ) ); ?></div>

						<?php
						// THE EDITORIAL RULE USED TO BE PRINTED HERE AND IS NOT
						// ANY MORE
						// -----------------------------------------------------
						// Three reasons, in order of weight. It says the same
						// thing as the sentence in this page's footer, so the
						// reader met it twice. It protects nothing: what keeps
						// a paragraph about someone's private life defensible is
						// the attribution and the date inside the paragraph
						// ("Dispatch reported on 30 June 2015... both agencies
						// denied it the following day"), not a promise printed
						// underneath it. And it broke the card it sat in, an
						// unpadded line clamped against the bottom border.
						// The rule still governs what gets written. It is stated
						// once, in the footer, where a policy note belongs.
						?>
					</details>
				<?php endforeach; ?>
			</div>

			<aside class="daw-rail">
				<?php
				// The sources, gathered in one card rather than scattered as chips.
				// A profile that shows where it got its facts is a profile someone
				// can check, and checkable is the whole difference between a
				// database and a rumour mill.
				//
				// ACCOUNTS AND REFERENCES ONLY. NOT PLACES TO LISTEN.
				// ---------------------------------------------------
				// Spotify and Apple Music used to sit in this list. They are the
				// one destination on a profile a reader actually wants to act on,
				// which is exactly why a link to them is a door out: the player is
				// already embedded further up the page, playing here, and a row in
				// this card competes with it and wins. Music stays in the player.
				// This card is who they are (accounts) and where we checked
				// (databases), and nothing else.
				$official = array_filter(
					array(
						'Instagram'   => daw_field( 'daw_social_instagram' ),
						'X'           => daw_field( 'daw_social_x' ),
						'Facebook'    => daw_field( 'daw_social_facebook' ),
						'YouTube'     => daw_field( 'daw_social_youtube' ),
						'Weibo'       => daw_field( 'daw_social_weibo' ),
						'IMDb'        => daw_field( 'daw_link_imdb' ),
						// MyDramaList is not listed here on purpose. It is the
						// closest thing this site has to a competitor: the same
						// records, the same readers, and a link from every profile
						// would hand it the traffic we spent the research to earn.
						// The field is still kept on the record, because it is
						// where a fact was checked, and a source worth checking
						// again is worth writing down. It just is not a door out.
						'Wikipedia'   => daw_field( 'daw_link_wikipedia' ),
						__( 'Agency page', 'daw' ) => daw_field( 'daw_link_agency' ),
					)
				);
				if ( $official ) :
					?>
					<div class="daw-rcard">
						<div class="daw-rcard-h"><?php esc_html_e( 'Official links', 'daw' ); ?></div>
						<div class="daw-rcard-b">
							<?php
							// A ROW PER DESTINATION, NOT A LABEL AND THE WORD "OPEN"
							// ------------------------------------------------------
							// Five rows all ending in the same word gave the reader
							// nothing to aim at: the only thing that differed was the
							// label, and the click target was three characters wide.
							// These rows carry each site's own mark, fetched once into
							// our media library by the plugin and drawn in a fixed box,
							// so the row is recognisable at a glance and the whole row
							// is the link. Same component as "Where to watch" on a
							// title, which is the point: one site, one way of leaving it.
							foreach ( $official as $label => $url ) :
								?>
								<a class="daw-watchrow" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
									<?php echo daw_watch_mark( $url, $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php $handle = daw_link_handle( $url, $label ); ?>
									<span class="daw-watch-name">
										<strong><?php echo esc_html( $label ); ?></strong>
										<?php if ( '' !== $handle ) : ?>
											<small><?php echo esc_html( $handle ); ?></small>
										<?php endif; ?>
									</span>
									<span class="daw-watch-go" aria-hidden="true">↗</span>
								</a>
							<?php endforeach; ?>
							<p class="daw-empty" style="margin:12px 0 0;font-size:.76rem">
								<?php esc_html_e( 'Official accounts and reference databases only. Marks belong to their owners and are shown to identify the destination.', 'daw' ); ?>
							</p>
						</div>
					</div>
				<?php endif; ?>

				<?php
				// NO READER SCORE ON A PERSON
				// ---------------------------
				// Rating a drama out of ten is what readers came to do. Rating a
				// human being is a different act, and this widget was inviting it
				// on every actor's page: an empty "No votes yet. Be the first."
				// under someone's name, with a row of buttons scoring them. It
				// earns nothing either — Google does not show review stars for a
				// Person, so there was no search-result gain to weigh against it.
				// The rating stays where it belongs, on titles.
				?>


				<?php
				// The rectangle that used to sit here is gone. It landed at the
				// foot of a short rail, directly above the billboard below, and
				// the two read as one wall of advertising with a gap in it. One
				// unit at this point in the page, in the reading column, is worth
				// more than two the reader scrolls past together.
				?>
			</aside>
		</div>

		<?php
		// THE ONE HONEST BREAK ON THE PAGE
		// --------------------------------
		// The panels above are the longest stretch of reading on a profile, and
		// this is where it ends: the reader has finished the long-form part and
		// has not started the filmography yet. An ad here interrupts nothing,
		// which is the only reason it is here rather than between two panels.
		// It runs the full column width because the rail has already stopped by
		// this point, and it reserves its box at both widths, so nothing under
		// the reader's thumb moves when it fills.
		daw_ad_slot( 'daw-ad-billboard' );
		?>
	<?php endif; ?>

	<?php
	// ================================================= the group they are in ==
	//
	// A soloist who is also a member is two facts, and the second one is the
	// reason half the readers arrived. This is the short version: the group's
	// featured music item on the left, who this person is within the group on
	// the right. The members are not listed here; the group's own page does
	// that, and one click away is the answer to "who else is in it".
	?>
	<?php if ( $group ) : ?>
		<?php
		$g_id     = (int) $group->ID;
		$g_name   = get_the_title( $g_id );
		$g_url    = (string) get_permalink( $g_id );
		$g_pos    = trim( (string) daw_field( 'daw_group_position', $id ) );
		$g_badge  = (string) daw_field( 'daw_group_badge', $id );
		$g_kind   = trim( (string) daw_field( 'daw_group_kind', $g_id ) );
		$g_kinds  = array(
			'girl'  => __( 'Girl group', 'daw' ),
			'boy'   => __( 'Boy group', 'daw' ),
			'co-ed' => __( 'Co-ed group', 'daw' ),
			'duo'   => __( 'Duo', 'daw' ),
			'band'  => __( 'Band', 'daw' ),
		);
		$g_kind_label = $g_kinds[ $g_kind ] ?? '';
		$g_ag     = trim( wp_specialchars_decode( (string) daw_field( 'daw_agency', $g_id ), ENT_QUOTES ) );
		$g_debut  = trim( (string) daw_field( 'daw_group_debut', $g_id ) );
		$g_fan    = trim( (string) daw_field( 'daw_group_fandom', $g_id ) );
		$g_badges = array(
			'leader' => __( 'Leader', 'daw' ),
			'maknae' => __( 'Maknae', 'daw' ),
			'former' => __( 'Former member', 'daw' ),
		);

		// The picture is the one the group's own page opens with: a freely
		// licensed photograph when there is one, otherwise the artist picture
		// from its Spotify page, otherwise its poster. Same choice, same image.
		$g_credit = '';
		$g_art    = function_exists( 'daw_group_image_html' ) ? daw_group_image_html( $g_id, 'medium', $g_credit ) : '';

		$g_role = array();

		if ( isset( $g_badges[ $g_badge ] ) ) {
			$g_role[] = $g_badges[ $g_badge ];
		}

		if ( '' !== $g_pos ) {
			$g_role[] = $g_pos;
		}

		$g_chips = array_filter(
			array(
				$g_ag,
				$g_debut ? sprintf( /* translators: %s: debut date */ __( 'Debuted %s', 'daw' ), $g_debut ) : '',
				$g_fan ? sprintf( /* translators: %s: fandom name */ __( 'Fandom: %s', 'daw' ), $g_fan ) : '',
			)
		);
		?>
		<section class="daw-section" id="group">
			<h2>
				<?php
				/* translators: %s: the name of the group */
				printf( esc_html__( 'In %s', 'daw' ), esc_html( $g_name ) );
				?>
			</h2>

			<div class="daw-gpanel<?php echo $g_art ? '' : ' is-noimg'; ?>">
				<?php if ( $g_art ) : ?>
					<div class="daw-gpanel-pic">
						<a class="daw-gpanel-img" href="<?php echo esc_url( $g_url ); ?>"><?php echo $g_art; // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
						<?php if ( '' !== $g_credit ) : ?>
							<p class="daw-hero-credit"><?php echo wp_kses( $g_credit, array( 'a' => array( 'href' => true, 'rel' => true, 'target' => true ) ) ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="daw-gpanel-txt">
					<p class="daw-gpanel-eyebrow">
						<?php
						echo esc_html(
							$g_kind_label
								/* translators: 1: group name, 2: kind of group */
								? sprintf( __( 'Member of %1$s · %2$s', 'daw' ), $g_name, $g_kind_label )
								/* translators: %s: group name */
								: sprintf( __( 'Member of %s', 'daw' ), $g_name )
						);
						?>
					</p>

					<?php if ( $g_role ) : ?>
						<p class="daw-gpanel-role"><?php echo esc_html( implode( ' · ', $g_role ) ); ?></p>
					<?php endif; ?>

					<?php if ( $g_chips ) : ?>
						<ul class="daw-gpanel-chips">
							<?php foreach ( $g_chips as $g_chip ) : ?>
								<li><?php echo esc_html( $g_chip ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<p>
						<?php
						echo esc_html(
							'' !== $g_pos
								/* translators: 1: person, 2: group, 3: position */
								? sprintf( __( '%1$s is a member of %2$s, where the part is %3$s. The group has its own page, with the line-up, the releases and the fandom.', 'daw' ), get_the_title( $id ), $g_name, $g_pos )
								/* translators: 1: person, 2: group */
								: sprintf( __( '%1$s is a member of %2$s. The group has its own page, with the line-up, the releases and the fandom.', 'daw' ), get_the_title( $id ), $g_name )
						);
						?>
					</p>

					<p class="daw-morelinks">
						<a href="<?php echo esc_url( $g_url ); ?>">
							<?php
							/* translators: %s: the name of the group */
							printf( esc_html__( 'Everything on %s', 'daw' ), esc_html( $g_name ) );
							?>
						</a>
					</p>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<?php // ====================================================== music == ?>
	<?php if ( $music || $spotify ) : ?>
		<section class="daw-section" id="music">
			<h2><?php esc_html_e( 'Music', 'daw' ); ?></h2>
			<p class="daw-sectionnote"><?php esc_html_e( 'Separated by how they appear on the track: soundtrack vocals, music-video appearances, live stages.', 'daw' ); ?></p>

			<?php if ( $spotify ) : ?>
				<?php $ready = function_exists( 'wp_has_consent' ) ? wp_has_consent( 'marketing' ) : false; ?>
				<div class="daw-player" style="--player-h:352px" data-player-height="352" data-spotify="<?php echo esc_attr( $spotify ); ?>" <?php echo $ready ? 'data-spotify-auto' : ''; ?>>
					<button type="button" class="daw-player-cta" data-spotify-load>
						<span class="daw-player-play" aria-hidden="true"></span>
						<span class="daw-player-txt">
							<strong><?php esc_html_e( 'Play their tracks', 'daw' ); ?></strong>
							<?php
							// "On Spotify" read like a destination. It is not one:
							// the player opens here and the reader never leaves.
							?>
							<small><?php esc_html_e( 'Plays here on the page. Nothing opens in a new tab.', 'daw' ); ?></small>
						</span>
					</button>
					<p class="daw-player-note"><?php esc_html_e( 'Embed only. Audio is served and counted by Spotify, never hosted here.', 'daw' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $music ) : ?>
				<div class="daw-tbl-wrap" style="margin-top:16px">
					<table class="daw-tbl">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Year', 'daw' ); ?></th>
								<th><?php esc_html_e( 'Track or appearance', 'daw' ); ?></th>
								<th><?php esc_html_e( 'Credited as', 'daw' ); ?></th>
								<th><?php esc_html_e( 'Kind', 'daw' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $music as $m ) : ?>
								<tr>
									<td class="is-num"><?php echo esc_html( $m['year'] ); ?></td>
									<td><?php echo esc_html( $m['title'] ); ?></td>
									<td><?php echo esc_html( $m['credit'] ); ?></td>
									<td><?php echo $m['kind'] ? '<span class="daw-kind">' . esc_html( $m['kind'] ) . '</span>' : ''; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php // =================================================== upcoming == ?>
	<?php echo $upnext_html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped where it is built. ?>

	<?php // ================================================ filmography == ?>
	<?php if ( $film['lead'] || $film['supporting'] || $film['guest'] || $film['other'] || $film['crew'] ) : ?>
		<section class="daw-section" id="filmography">
			<?php
			// The heading carries the searched pattern: "<name> shows" and
			// "<name> movies" are the largest searches on a person page
			// after the name itself (K11). The anchor stays #filmography.
			?>
			<h2><?php
			/* translators: %s: person name */
			printf( esc_html__( '%s TV shows and movies', 'daw' ), esc_html( get_the_title( $id ) ) );
			?></h2>
			<p style="margin:6px 0 12px;color:var(--muted)"><?php
			/* translators: %s: person name */
			printf( esc_html__( 'Every %s drama, TV show and movie with a page on this site.', 'daw' ), esc_html( get_the_title( $id ) ) );
			?></p>
			<div class="daw-filmbar">

				<?php
				// Counts come from the same rows the cards are drawn from, so a
				// pill can never promise a number the list does not contain.
				$counts = array( 'drama' => 0, 'film' => 0, 'variety' => 0, 'anime' => 0 );
				foreach ( $film as $rows ) {
					foreach ( $rows as $row ) {
						$k = isset( $counts[ $row['kind'] ] ) ? $row['kind'] : 'drama';
						$counts[ $k ]++;
					}
				}

				$labels = array(
					/* translators: %d: number of dramas */
					'drama'   => __( 'Dramas %d', 'daw' ),
					/* translators: %d: number of films */
					'film'    => __( 'Films %d', 'daw' ),
					/* translators: %d: number of variety shows */
					'variety' => __( 'Variety %d', 'daw' ),
					/* translators: %d: number of anime titles */
					'anime'   => __( 'Anime %d', 'daw' ),
				);

				$pills = array( 'all' => __( 'Everything', 'daw' ) );
				foreach ( $labels as $key => $label ) {
					if ( $counts[ $key ] ) {
						$pills[ $key ] = sprintf( $label, $counts[ $key ] );
					}
				}

				if ( count( $pills ) > 2 ) :
					?>
					<div class="daw-filmfilter" role="group" aria-label="<?php esc_attr_e( 'Filter the filmography', 'daw' ); ?>">
						<?php foreach ( $pills as $key => $label ) : ?>
							<button type="button" data-film-filter="<?php echo esc_attr( $key ); ?>"
								aria-pressed="<?php echo 'all' === $key ? 'true' : 'false'; ?>">
								<?php echo esc_html( $label ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php
			// EVERY CREDIT IS A POSTER. NO CREDIT IS EVER A TABLE ROW.
			// --------------------------------------------------------
			// Guest parts and credits with no billing recorded used to drop into
			// a six column table underneath the posters, and the table printed a
			// cell for every column whether or not there was anything to put in
			// it: a blank Role, a blank Network, an en dash for a missing year,
			// and the words "Not recorded" in the Billing column of every single
			// row. Four real credits were rendered as a form with the answers
			// missing. A reader does not read that as "we are being careful about
			// billing", they read it as a broken page, and they are right.
			//
			// The rule is now one line: a credit looks the same wherever it sits.
			// Same poster, same card, same rail. What differs between the groups
			// is the heading above them, which is where billing belongs, because
			// a heading states a fact about the whole group instead of repeating
			// an absence once per row.
			//
			// And a card prints only what is known. No placeholders, no dashes,
			// no "Not recorded". A missing year means no year badge; an unknown
			// role means the line under the title is just the kind and the
			// network. Nothing is invented to fill a space, and nothing is
			// announced as missing either.
			$groups = array(
				'lead'       => __( 'Main roles', 'daw' ),
				'supporting' => __( 'Supporting roles', 'daw' ),
				'guest'      => __( 'Guest appearances', 'daw' ),
				'other'      => __( 'Other credits', 'daw' ),
				'crew'       => __( 'Behind the camera', 'daw' ),
			);

			foreach ( $groups as $bucket => $label ) :
				if ( ! $film[ $bucket ] ) {
					continue;
				}
				?>
				<div class="daw-filmgroup">
					<h3>
						<?php echo esc_html( $label ); ?>
						<small><?php printf( esc_html( _n( '%s title', '%s titles', count( $film[ $bucket ] ), 'daw' ) ), esc_html( number_format_i18n( count( $film[ $bucket ] ) ) ) ); ?></small>
					</h3>
					<div class="daw-rail-row">
						<?php foreach ( $film[ $bucket ] as $row ) : ?>
							<a class="daw-person" data-kind="<?php echo esc_attr( $row['kind'] ? $row['kind'] : 'drama' ); ?>" href="<?php echo esc_url( $row['url'] ); ?>">
								<div class="daw-poster">
									<?php echo daw_poster( $row['id'], 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<span class="daw-kindbadge"><?php echo esc_html( daw_kind_label( $row['kind'] ) ); ?></span>
									<?php if ( $row['rating'] ) : ?>
										<span class="daw-scorebadge">★ <?php echo esc_html( number_format_i18n( $row['rating'], 1 ) ); ?></span>
									<?php endif; ?>
									<?php if ( $row['year'] ) : ?>
										<span class="daw-yearbadge"><?php echo esc_html( $row['year'] ); ?></span>
									<?php endif; ?>
								</div>
								<div class="daw-person-name"><?php echo esc_html( $row['title'] ); ?></div>
								<?php
								// THREE FACTS, THREE LINES
								// ------------------------
								// Network, character and episode run used to share one
								// muted line joined by dots, so "KBS2 · Namgoong Ho"
								// read as a single blur and a reader could not tell
								// where the channel stopped and the character began.
								// Each now has its own line and its own weight: the
								// network small and quiet, the character in the text
								// colour because it is what the reader came for, and
								// a partial run as a badge so a guest turn is obvious
								// at a glance.
								$f_net  = trim( (string) $row['network'] );
								$f_role = trim( (string) $row['role'] );
								$f_eps  = isset( $row['eps'] ) ? trim( (string) $row['eps'] ) : '';
								?>
								<?php if ( '' !== $f_net ) : ?>
									<div class="daw-person-net"><?php echo esc_html( $f_net ); ?></div>
								<?php endif; ?>
								<?php if ( '' !== $f_role ) : ?>
									<div class="daw-person-role"><?php echo esc_html( $f_role ); ?></div>
								<?php endif; ?>
								<?php if ( '' !== $f_eps ) : ?>
									<div class="daw-person-eps"><?php echo esc_html( $f_eps ); ?></div>
								<?php endif; ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>

	<?php // ================================================ merchandise == ?>
	<?php
	$shop = array_values(
		array_filter(
			$shop,
			static function ( $r ) {
				return '' !== trim( (string) $r['title'] ) && '' !== trim( (string) $r['url'] );
			}
		)
	);
	if ( $shop ) :
		?>
		<section class="daw-section daw-shop" id="merchandise">
			<div class="daw-shop-head">
				<h2><?php esc_html_e( 'Related merchandise', 'daw' ); ?></h2>
				<span class="daw-paidlabel"><?php esc_html_e( 'Affiliate · paid link', 'daw' ); ?></span>
			</div>
			<div class="daw-rail-row">
				<?php foreach ( $shop as $item ) : ?>
					<div class="daw-shopcard">
						<div class="daw-shopcard-b">
							<strong><?php echo esc_html( $item['title'] ); ?></strong>
							<?php if ( $item['kind'] ) : ?>
								<small><?php echo esc_html( $item['kind'] ); ?></small>
							<?php endif; ?>
						</div>
						<a class="daw-btn daw-btn-s" href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener sponsored nofollow">
							<?php esc_html_e( 'View item', 'daw' ); ?>
						</a>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="daw-empty" style="font-size:.78rem;margin-top:12px">
				<?php esc_html_e( 'We may earn a commission from purchases made through these links. It costs you nothing extra and it never affects what we publish about this person. Prices and availability live on the retailer\'s page.', 'daw' ); ?>
			</p>
		</section>
	<?php endif; ?>

	<?php // ======================================= faq beside the news == ?>
	<?php
	// THE TWO COLUMNS START ON THE SAME LINE
	// --------------------------------------
	// The questions and the recent stories are both the tail of the page, and
	// they were stacked: a two column block holding the news on the right with
	// nothing on its left, and then the questions underneath it, starting a
	// section gap lower than the card beside them. Half a screen of empty
	// column, and two headings that should read as one row reading as two.
	//
	// So the questions take the column that was empty. The list of people is
	// no longer squeezed in beside them either; it is a grid of faces and it
	// has the foot of the page to itself.
	?>
	<?php if ( $faq || $news ) : ?>
		<div class="daw-cols daw-section">
			<div class="daw-maincol">
				<?php if ( $faq ) : ?>
					<section id="faq">
						<h2><?php esc_html_e( 'Frequently asked questions', 'daw' ); ?></h2>
						<?php foreach ( $faq as $q ) : ?>
							<?php if ( '' === trim( (string) $q['q'] ) ) { continue; } ?>
							<details class="daw-acc">
								<summary><h3><?php echo esc_html( $q['q'] ); ?></h3></summary>
								<div class="daw-acc-body"><p><?php echo esc_html( $q['a'] ); ?></p></div>
							</details>
						<?php endforeach; ?>
					</section>
				<?php endif; ?>
			</div>

			<aside class="daw-rail">
				<?php if ( $news ) : ?>
					<section id="person-news">
						<h2 class="daw-h3"><?php esc_html_e( 'In the news', 'daw' ); ?></h2>
						<div class="daw-newslist">
							<?php foreach ( $news as $n ) : ?>
								<a class="daw-newsrow" href="<?php echo esc_url( get_permalink( $n ) ); ?>">
									<span class="daw-newsrow-face"><?php echo daw_poster( is_object( $n ) ? $n->ID : $n, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
									<span>
										<strong><?php echo esc_html( get_the_title( $n ) ); ?></strong>
										<small><?php echo esc_html( function_exists( 'daw_news_card_date' ) ? daw_news_card_date( $n ) : get_the_date( 'j F Y', $n ) ); ?></small>
									</span>
								</a>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>
			</aside>
		</div>
	<?php endif; ?>

	<?php // ========================================== awards and nominations == ?>
	<?php
	// WHY THIS IS A TABLE AND NOT A LIST OF PRIZES
	// -------------------------------------------
	// An award means two things to a reader: how good the work was, and which
	// work it was. A list of prize names answers only the first. Given its own
	// column, the work links to its record, so a reader who arrives searching
	// "did he win a Daesang" leaves on the drama page that won it, and the
	// profile stops being a dead end. Rows whose work has no record yet print
	// as plain text and start linking by themselves the day it is created.
	?>
	<?php if ( $pawards ) : ?>
		<section class="daw-section" id="person-awards">
			<h2><?php esc_html_e( 'Awards and nominations', 'daw' ); ?></h2>
			<p class="daw-sectionnote"><?php esc_html_e( 'Every ceremony on record, most recent first. Where we hold a record for the work, it links to it.', 'daw' ); ?></p>
			<div class="daw-tbl-wrap">
				<table class="daw-tbl">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Year', 'daw' ); ?></th>
							<th><?php esc_html_e( 'Ceremony', 'daw' ); ?></th>
							<th><?php esc_html_e( 'Category', 'daw' ); ?></th>
							<th><?php esc_html_e( 'For', 'daw' ); ?></th>
							<th><?php esc_html_e( 'Result', 'daw' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pawards as $aw ) : ?>
							<tr>
								<td class="is-num"><?php echo esc_html( $aw['year'] ); ?></td>
								<td><?php echo esc_html( $aw['body'] ); ?></td>
								<td><?php echo esc_html( $aw['category'] ); ?></td>
								<td>
									<?php if ( '' !== $aw['work'] && $aw['work_url'] ) : ?>
										<a href="<?php echo esc_url( $aw['work_url'] ); ?>"><?php echo esc_html( $aw['work'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $aw['work'] ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $aw['result'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
	<?php endif; ?>


	<?php // ============================== who they keep working with == ?>
	<?php if ( $worked_with ) : ?>
		<section class="daw-section" id="worked-with">
			<h2><?php esc_html_e( 'Frequently worked with', 'daw' ); ?></h2>
			<p class="daw-sectionnote"><?php esc_html_e( 'Counted from shared credits across the database, most shared titles first.', 'daw' ); ?></p>
			<div class="daw-mates">
				<?php foreach ( $worked_with as $mate ) : ?>
					<a class="daw-mate" href="<?php echo esc_url( $mate['url'] ); ?>">
						<span class="daw-mate-face"><?php echo daw_poster( $mate['id'], 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<span class="daw-mate-txt">
							<strong><?php echo esc_html( $mate['name'] ); ?></strong>
							<small>
								<?php
								printf(
									esc_html( _n( '%s shared title', '%s shared titles', $mate['shared'], 'daw' ) ),
									esc_html( number_format_i18n( $mate['shared'] ) )
								);
								?>
							</small>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

	<footer class="daw-attrib">
		<p>
			<?php
			printf(
				/* translators: %s: the date this record was last updated */
				esc_html__( 'The photo is shown through the TMDB API and is used only to identify the person. Personal details come from public sources named on this page. Private life appears only where the person or their agency has said it publicly, with the date. Last updated %s.', 'daw' ),
				esc_html( get_the_modified_date( 'j F Y', $id ) )
			);
			?>
			<?php if ( $correction ) : ?>
				<a href="<?php echo esc_url( $correction ); ?>"><?php esc_html_e( 'Request a correction', 'daw' ); ?></a>
			<?php endif; ?>
		</p>
	</footer>

</div>

<?php daw_record_scripts(); ?>

	<?php
endwhile;

get_footer();
