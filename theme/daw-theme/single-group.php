<?php
/**
 * The group record: a band, its members, its releases and its screen work.
 *
 * WHY IT IS NOT THE PERSON TEMPLATE WITH A DIFFERENT WORD ON IT
 * ------------------------------------------------------------
 * A band has no height, no birthday and no filmography, and it does have
 * members, a discography and a fandom. Those are not extra fields on a person,
 * they are the whole shape of the thing. So this page asks the questions a band
 * answers and leaves out the ones it cannot.
 *
 * THE MEMBER LIST IS COLLECTED RATHER THAN TYPED
 * ----------------------------------------------
 * It is read from the members' own records, so adding a member is one field on
 * one profile and this page follows.
 *
 * Their screen credits are not collected here, and deliberately so. "What has
 * this group been in" is not a question a group answers: the group was in
 * nothing, one of its members was, and a row of posters under the band's name
 * says otherwise. Those credits live on the profile that holds them.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$id = get_the_ID();

	/* --------------------------------------------------------- identity -- */
	$native   = daw_field( 'daw_native_name', $id );
	$nicks    = daw_field( 'daw_group_nicknames', $id );
	$former   = daw_field( 'daw_group_former_name', $id );
	$kind     = (string) daw_field( 'daw_group_kind', $id );
	$formed   = daw_field( 'daw_group_formed', $id );
	$based    = daw_field( 'daw_group_based', $id );
	$country  = function_exists( 'daw_terms' ) ? daw_terms( 'dw_country', $id ) : '';

	/* ----------------------------------------------------- career, music -- */
	$debut    = daw_field( 'daw_group_debut', $id );
	$debut_r  = daw_field( 'daw_group_debut_release', $id );
	$agency   = daw_field( 'daw_agency', $id );
	$label    = daw_field( 'daw_group_label', $id );
	$status   = (string) daw_field( 'daw_group_status', $id );
	$genres   = function_exists( 'daw_terms' ) ? daw_terms( 'dw_genre', $id ) : '';

	/* ---------------------------------------------------------- fandom --- */
	$fandom   = daw_field( 'daw_group_fandom', $id );
	$colour   = daw_field( 'daw_group_fandom_colour', $id );
	$stick    = (string) daw_field( 'daw_group_lightstick', $id );

	$summary  = daw_field( 'daw_group_summary', $id );
	$embed    = daw_field( 'daw_group_embed', $id );

	$line_up  = (int) daw_field( 'daw_group_member_count', $id );
	$faq      = function_exists( 'daw_get_group_faq' ) ? daw_get_group_faq( $id ) : array();
	$body     = trim( (string) get_the_content() );
	$body     = ( '' !== $body && false === strpos( $body, '[elementor' ) ) ? $body : '';
	$credits  = function_exists( 'daw_group_screen_credits' ) ? daw_group_screen_credits( $id, 8 ) : array();
	$news     = function_exists( 'daw_get_related_news' ) ? daw_get_related_news( $id, 4 ) : array();
	$similar  = function_exists( 'daw_group_similar' ) ? daw_group_similar( $id, 6 ) : array();

	$types    = function_exists( 'daw_release_types' ) ? daw_release_types() : array();
	$members  = function_exists( 'daw_group_members' ) ? daw_group_members( $id ) : array();
	$releases = function_exists( 'daw_group_releases' ) ? daw_group_releases( $id ) : array();

	$kinds = array(
		'girl'  => __( 'Girl group', 'daw' ),
		'boy'   => __( 'Boy group', 'daw' ),
		'co-ed' => __( 'Co-ed group', 'daw' ),
		'duo'   => __( 'Duo', 'daw' ),
		'band'  => __( 'Band', 'daw' ),
	);

	$statuses = array(
		'active'    => __( 'Active', 'daw' ),
		'hiatus'    => __( 'On hiatus', 'daw' ),
		'disbanded' => __( 'Disbanded', 'daw' ),
	);

	// Members who are still in, and members who are not, counted separately
	// because the record says one thing and the page must not round it up.
	$current = array();
	$past    = array();

	foreach ( $members as $mid ) {
		if ( 'former' === (string) daw_field( 'daw_group_badge', $mid ) ) {
			$past[] = $mid;
		} else {
			$current[] = $mid;
		}
	}
	?>

<div class="daw-wrap">
	<?php daw_crumb_nav( $id ); ?>
</div>

<header class="daw-phero daw-phero-person daw-phero-group" id="overview">
	<div class="daw-wrap">
		<div class="daw-phero-in">
			<div class="daw-phero-portrait">
				<?php
				// A GROUP IS NOT A POSTER, AND IT IS NOT AN ALBUM SLEEVE EITHER
				// -------------------------------------------------------------
				// These heroes were showing whichever photograph somebody had
				// uploaded, with nothing behind it saying we were allowed to.
				// BTS was showing the cover of a Japanese compilation album as
				// its portrait: a record sleeve standing in for a picture of
				// seven people, out of a media library nobody can source.
				//
				// The picture on the act's own Spotify page is put there by the
				// label for this, is referenced at their address rather than
				// copied here, and comes square, which is the shape an artist
				// image is. So the frame is square on this page and only on
				// this page: a drama poster is still two by three and nothing
				// else on the site moves.
				//
				// The Spotify picture wins here, which is the opposite of the
				// rule the release covers use, and deliberately so. A sleeve
				// somebody uploaded is usually a scan of the real sleeve and is
				// at worst the same picture twice. A group photograph somebody
				// uploaded is the thing that got us into this: it is the one we
				// cannot source. To put a licensed photograph back in front of
				// this, clear the Spotify artist link on the record and the
				// uploaded image returns.
				$g_pic = trim( (string) get_post_meta( $id, 'daw_group_image_url', true ) );
				// A freely licensed photograph (CC0, public domain, CC BY,
				// CC BY-SA, KOGL Type 1) with a complete rights record goes in
				// front of the Spotify picture, with its credit line under it:
				// those licences allow reuse on a commercial page as long as the
				// credit is shown, and it is also the picture the page shares.
				$g_att  = (int) get_post_thumbnail_id( $id );
				$g_lic  = ( $g_att && function_exists( 'daw_lic_get' ) ) ? daw_lic_get( $g_att ) : array();
				$g_open = $g_lic && in_array( $g_lic['type'], array( 'cc0', 'pd', 'cc-by', 'cc-by-sa', 'kogl1' ), true ) && 'ok' === daw_lic_status( $g_att, $id );
				?>
				<span class="daw-poster daw-gposter">
					<?php
					if ( $g_open ) {
						echo wp_get_attachment_image( $g_att, 'large', false, array( 'loading' => 'eager', 'fetchpriority' => 'high', 'decoding' => 'async', 'alt' => get_the_title( $id ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
					} elseif ( $g_pic ) {
						// 320px copy first, 640 for dense screens (spotify.php).
						$g_small = function_exists( 'daw_spotify_sized' ) ? daw_spotify_sized( $g_pic, 320 ) : $g_pic;
						printf(
							'<img src="%s" srcset="%s 320w, %s 640w" sizes="(max-width: 700px) 130px, 220px" alt="%s" width="640" height="640" loading="eager" fetchpriority="high" referrerpolicy="no-referrer">',
							esc_url( $g_small ),
							esc_url( $g_small ),
							esc_url( $g_pic ),
							/* translators: %s: the name of the group */
							esc_attr( sprintf( __( '%s, from their Spotify artist page', 'daw' ), get_the_title( $id ) ) )
						);
					} else {
						echo daw_poster( $id, 'large' ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
					?>
				</span>
				<?php
				// The credit sits below the photograph now, the same place
				// the person and title heroes put theirs (.daw-hero-credit):
				// a licence credit overlapping a small square photo was hard
				// to read, and the site should not have two different ways
				// of showing the same kind of line.
				$g_credit = $g_open && function_exists( 'daw_lic_credit_html' ) ? daw_lic_credit_html( $g_att ) : '';
				if ( '' !== $g_credit ) :
					?>
					<p class="daw-hero-credit"><?php echo wp_kses( $g_credit, array( 'a' => array( 'href' => true, 'rel' => true, 'target' => true ) ) ); ?></p>
				<?php endif; ?>
			</div>

			<div class="daw-phero-id">
				<p class="daw-eyebrow">
					<?php
					echo esc_html(
						implode(
							' · ',
							array_filter(
								array(
									__( 'K-pop group', 'daw' ),
									$country,
								)
							)
						)
					);
					?>
				</p>

				<h1><?php the_title(); ?></h1>

				<?php
				$under = array_filter(
					array(
						$native,
						/* translators: %s: the fandom's name */
						$fandom ? sprintf( __( 'Fandom: %s', 'daw' ), $fandom ) : '',
					)
				);

				if ( $under ) :
					?>
					<p class="daw-native"><?php echo esc_html( implode( ' · ', $under ) ); ?></p>
				<?php endif; ?>

				<?php
				// The four words that place the group at a glance. Each one is
				// printed only if the record holds it, so the row never shows
				// an empty pill.
				$chips = array_filter(
					array(
						$kind ? ( $kinds[ $kind ] ?? '' ) : '',
						$genres,
						$agency,
						$status ? ( $statuses[ $status ] ?? '' ) : '',
					)
				);

				if ( $chips ) :
					?>
					<ul class="daw-gchips">
						<?php foreach ( $chips as $chip ) : ?>
							<li><?php echo esc_html( $chip ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php
				$stats = array();

				if ( $debut ) {
					$stats[] = array( 'v' => $debut, 'l' => __( 'Debut', 'daw' ) );
				}
				// The line-up as it is, falling back to the profiles we hold
				// when nobody has typed the real figure yet.
				$shown = $line_up ? $line_up : count( $current );

				if ( $shown ) {
					$stats[] = array(
						'v' => number_format_i18n( $shown ),
						'l' => _n( 'Member', 'Members', $shown, 'daw' ),
					);
				}
				if ( $fandom ) {
					$stats[] = array( 'v' => $fandom, 'l' => __( 'Fandom', 'daw' ) );
				}
				if ( $agency ) {
					$stats[] = array( 'v' => $agency, 'l' => __( 'Agency', 'daw' ) );
				}

				$spotify = daw_field( 'daw_spotify_artist', $id );

				if ( $stats ) :
					?>
					<div class="daw-pstats">
						<?php foreach ( array_slice( $stats, 0, 5 ) as $s ) : ?>
							<div class="daw-pstat">
								<div class="daw-pstat-v"><?php echo esc_html( $s['v'] ); ?></div>
								<div class="daw-pstat-l"><?php echo esc_html( $s['l'] ); ?></div>
							</div>
						<?php endforeach; ?>

						<?php
						// TWO PLACES, ONE SHOWING AT A TIME
						// ---------------------------------
						// On a wide screen the statistics strip ends halfway
						// across the page and the buttons were sitting in a
						// band of their own underneath it, with the gap between
						// them doing nothing. Joined onto the end of the strip
						// they pick up the same divider as every other cell and
						// the band disappears.
						//
						// On a phone that strip is two narrow columns and the
						// buttons belong under the photograph instead, where
						// they already are. So the markup exists twice and
						// exactly one copy is ever displayed: the other is
						// display:none, which also takes it out of the reading
						// order for a screen reader.
						if ( $spotify || $releases ) :
							?>
							<p class="daw-gdo daw-gdo-wide">
								<?php if ( $spotify ) : ?>
									<a class="daw-btn daw-btn-p" href="<?php echo esc_url( $spotify ); ?>" rel="noopener nofollow" target="_blank"><?php esc_html_e( 'Listen on Spotify', 'daw' ); ?></a>
								<?php endif; ?>
								<?php if ( $releases ) : ?>
									<a class="daw-btn" href="#discography"><?php esc_html_e( 'Discography', 'daw' ); ?></a>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

			</div>

			<?php
			// THE BUTTONS SIT IN THE HERO'S OWN CELL
			// --------------------------------------
			// Inside the reading column they were the last thing in a stack,
			// which on a phone put "Listen on Spotify" below the name, the
			// native name, four chips and five statistics: about a screen and
			// a half down from the thing it belongs to. The hero grid already
			// keeps a cell for exactly this, under the photograph on a phone
			// and at the foot of the reading column on a desktop, and it was
			// standing empty on every group record.
			if ( $spotify || $releases ) :
				?>
				<p class="daw-gdo daw-gdo-narrow">
					<?php if ( $spotify ) : ?>
						<a class="daw-btn daw-btn-p" href="<?php echo esc_url( $spotify ); ?>" rel="noopener nofollow" target="_blank"><?php esc_html_e( 'Listen on Spotify', 'daw' ); ?></a>
					<?php endif; ?>
					<?php if ( $releases ) : ?>
						<a class="daw-btn" href="#discography"><?php esc_html_e( 'Discography', 'daw' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
	</div>
</header>

<div class="daw-wrap daw-group-page">

	<?php
	// THE SAME JUMP BAR EVERY OTHER RECORD HAS
	// ----------------------------------------
	// A profile and a title record both open with a row of links to their own
	// sections, and a group record did not, which is the whole of the reason
	// this page felt like a different site. The bar is built from what the
	// page actually prints, so it never offers a section that is not there.
	$nav = array( 'overview' => __( 'Overview', 'daw' ) );

	if ( $current || $past ) {
		$nav['members'] = __( 'Members', 'daw' );
	}

	if ( $releases ) {
		$nav['newest'] = __( 'Newest release', 'daw' );
	}

	$nav['group-data'] = __( 'Group data', 'daw' );

	if ( $releases ) {
		$nav['discography'] = __( 'Discography', 'daw' );
	}
	if ( $similar ) {
		$nav['similar'] = __( 'Similar', 'daw' );
	}
	if ( $credits ) {
		$nav['screen-credits'] = __( 'On screen', 'daw' );
	}
	if ( $news ) {
		$nav['group-news'] = __( 'News', 'daw' );
	}
	if ( $faq ) {
		$nav['faq'] = __( 'FAQ', 'daw' );
	}

	if ( count( $nav ) > 2 ) :
		?>
		<nav class="daw-stickynav" aria-label="<?php esc_attr_e( 'On this page', 'daw' ); ?>">
			<ul>
				<?php
				// NOT $label. THAT IS THE RECORD LABEL
				// ------------------------------------
				// This loop ran before the Quick facts card was built and left
				// $label holding the last thing in the jump bar, so every group
				// on the site reported its record label as "Discography".
				?>
				<?php foreach ( $nav as $anchor => $nav_label ) : ?>
					<li><a href="#<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $nav_label ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
	<?php endif; ?>

	<?php
	// THE OPENING AND THE LINE-UP ARE ONE ROW, NOT TWO
	// ------------------------------------------------
	// Both of these were full width and neither one filled it. The paragraph
	// ran to 1180px, which is nobody's reading measure, and the member grid put
	// two faces on the left of a row with space for six, so the page read as
	// broken rather than as short. The number of faces is also the one thing
	// here that keeps changing: two today, seven when the profiles are written,
	// and a grid that looks right at seven looks abandoned at two.
	//
	// A column and a rail fixes both at once. The paragraph gets a measure, the
	// line-up becomes a list that reads the same at two names or at twelve, and
	// the space on the right stops being empty.
	$has_members = (bool) ( $current || $past );

	if ( $summary || $has_members ) :
		?>
		<div class="daw-cols daw-section daw-gtop">
			<div class="daw-maincol">
				<?php if ( $summary || $body ) : ?>
					<section class="daw-gintro" id="about">
						<?php if ( $summary ) : ?>
							<p class="daw-gsum"><?php echo esc_html( wp_strip_all_tags( $summary ) ); ?></p>
						<?php endif; ?>

						<?php
						// THE WRITING BELONGS BESIDE THE LINE-UP, NOT UNDER THE FOOTNOTE
						// --------------------------------------------------------------
						// The piece somebody writes about a group was printed at the very
						// bottom of the page, under the discography and the credits, which
						// is where a reader has already stopped. It is the part of the
						// record that answers what people actually type into a search box,
						// so it goes where they are still reading: the top of the column,
						// with the line-up beside it and its own headings.
						if ( $body ) :
							?>
							<div class="daw-prose daw-gprose"><?php echo wp_kses_post( apply_filters( 'the_content', $body ) ); ?></div>
						<?php endif; ?>
					</section>
				<?php endif; ?>
			</div>

			<aside class="daw-rail">
				<?php if ( $has_members ) : ?>
					<section class="daw-pcard daw-gmembers" id="members">
						<div class="daw-pcard-h">
							<?php
							// "Members of BTS" is the second most typed thing about this
							// group after its own name, and the card was calling itself
							// The line-up, which is a word nobody searches for. The
							// heading says the thing; the count goes underneath it.
							printf(
								/* translators: %s: the name of the group */
								esc_html__( '%s members', 'daw' ),
								esc_html( get_the_title( $id ) )
							);
							?>
						</div>

						<?php
						// A PORTRAIT, NOT A THUMBNAIL
						// ---------------------------
						// A 46px circle is the right size for "who else was in
						// this drama" and the wrong size for the line-up, which
						// is the thing half the readers came for. These are the
						// people; they get the shape a person gets everywhere
						// else on the site, two by three, and the line under the
						// name says what they do in the group rather than what
						// they do for a living.
						?>
						<div class="daw-gmembers-list">
							<?php foreach ( array_merge( $current, $past ) as $mid ) : ?>
								<?php
								$m_role = trim( (string) daw_field( 'daw_group_position', $mid ) );

								if ( '' === $m_role ) {
									$m_role = trim( (string) daw_field( 'daw_occupation', $mid ) );
								}

								$m_out = 'former' === (string) daw_field( 'daw_group_badge', $mid );
								?>
								<a class="daw-gmember" href="<?php echo esc_url( (string) get_permalink( $mid ) ); ?>">
									<span class="daw-gmember-face daw-poster">
										<?php echo daw_poster( $mid, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									</span>
									<span class="daw-gmember-txt">
										<strong><?php echo esc_html( get_the_title( $mid ) ); ?></strong>
										<?php if ( $m_role ) : ?>
											<small><?php echo esc_html( $m_role ); ?></small>
										<?php endif; ?>
										<?php if ( $m_out ) : ?>
											<small class="daw-gmember-out"><?php esc_html_e( 'Former member', 'daw' ); ?></small>
										<?php endif; ?>
									</span>
								</a>
							<?php endforeach; ?>
						</div>

						<?php
						// Said once, in the place the gap actually shows, rather
						// than as a note under a heading somewhere above it.
						if ( $line_up && count( $current ) < $line_up ) :
							?>
							<p class="daw-gmembers-note">
								<?php
								printf(
									/* translators: 1: how many profiles exist, 2: how many people are in the line-up */
									esc_html__( '%1$s of %2$s profiles written so far. Each one carries acting credits, OST work and personal data.', 'daw' ),
									esc_html( number_format_i18n( count( $current ) ) ),
									esc_html( number_format_i18n( $line_up ) )
								);
								?>
							</p>
						<?php endif; ?>
					</section>
				<?php endif; ?>
			</aside>
		</div>
	<?php endif; ?>

	<?php
	// THE NEWEST RELEASE, WHICH IS THE THING THAT KEEPS CHANGING
	// ----------------------------------------------------------
	// Everything else on this page is a fact that stays put. The answer to
	// "what did they just put out" is the one that is wrong a week after
	// somebody types it, and it is also the reason a good share of the traffic
	// arrives. So it is not typed at all: it is the first row of a discography
	// that sorts itself newest first and is refilled from the catalogue every
	// night, which means a release that came out this morning is here without
	// anybody being told.
	//
	// The player is the album's own Spotify embed, so the listening is done and
	// counted there. Nothing is hosted, streamed or cached on this site.
	$newest = $releases ? $releases[0] : null;
	$player = $newest && function_exists( 'daw_spotify_embed_url' ) ? daw_spotify_embed_url( $newest['spotify'] ) : '';

	if ( ! $player && $embed ) {
		$player = $embed;
	}

	if ( $newest ) :
		?>
		<section class="daw-gnew daw-section" id="newest">
			<div class="daw-gnew-art daw-poster">
				<?php
				if ( $newest['cover'] ) {
					echo wp_get_attachment_image( $newest['cover'], 'medium', false, array( 'loading' => 'lazy', 'alt' => esc_attr( $newest['title'] ) ) );
				} elseif ( $newest['cover_url'] ) {
					printf(
						'<img src="%s" alt="%s" loading="lazy" width="300" height="300" referrerpolicy="no-referrer">',
						esc_url( function_exists( 'daw_spotify_sized' ) ? daw_spotify_sized( $newest['cover_url'], 300 ) : $newest['cover_url'] ),
						esc_attr( $newest['title'] )
					);
				}
				?>
			</div>

			<div class="daw-gnew-b">
				<p class="daw-eyebrow">
					<?php
					echo esc_html(
						implode(
							' · ',
							array_filter(
								array(
									__( 'Newest release', 'daw' ),
									$types[ $newest['type'] ] ?? '',
								)
							)
						)
					);
					?>
				</p>

				<h2><?php echo esc_html( $newest['title'] ); ?></h2>

				<?php
				$n_line = array_filter(
					array(
						$newest['ts'] ? date_i18n( 'j F Y', $newest['ts'] ) : ( $newest['year'] ? (string) $newest['year'] : '' ),
						$newest['tracks'] ? sprintf( _n( '%s track', '%s tracks', $newest['tracks'], 'daw' ), number_format_i18n( $newest['tracks'] ) ) : '',
						$newest['track'] ? sprintf( __( 'Title track: %s', 'daw' ), $newest['track'] ) : '',
					)
				);

				if ( $n_line ) :
					?>
					<p class="daw-gnew-line"><?php echo esc_html( implode( ' · ', $n_line ) ); ?></p>
				<?php endif; ?>

				<?php if ( $newest['spotify'] || $newest['apple'] ) : ?>
					<?php
					// SPOTIFY IS THE ONE ANYBODY CAN ACTUALLY PRESS
					// ---------------------------------------------
					// Spotify has a free tier, so "play this" is a real offer
					// to any reader. Apple Music plays thirty seconds and then
					// asks for a subscription, which makes it a worse first
					// button even when it is the only link we hold. So Spotify
					// leads wherever we have it, and Apple only takes the front
					// when there is nothing else, labelled so nobody presses it
					// expecting a song.
					?>
					<p class="daw-gnew-do">
						<?php if ( $newest['spotify'] ) : ?>
							<a class="daw-btn daw-btn-p" href="<?php echo esc_url( $newest['spotify'] ); ?>" rel="noopener nofollow" target="_blank"><?php esc_html_e( 'Play on Spotify', 'daw' ); ?></a>
						<?php endif; ?>
						<?php if ( $newest['apple'] ) : ?>
							<a class="daw-btn <?php echo $newest['spotify'] ? '' : 'daw-btn-p'; ?>" href="<?php echo esc_url( $newest['apple'] ); ?>" rel="noopener nofollow" target="_blank"><?php esc_html_e( 'Open in Apple Music', 'daw' ); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<?php if ( $player ) : ?>
					<div class="daw-gnew-player">
<?php // Rule 4.1: nothing from Spotify until the reader asks (1.63.1). ?>
						<?php $g_sp_ready = function_exists( 'wp_has_consent' ) ? wp_has_consent( 'marketing' ) : false; ?>
						<div class="daw-player" style="--player-h:152px" data-player-height="152" data-spotify="<?php echo esc_attr( str_replace( '/embed/', '/', (string) $player ) ); ?>" <?php echo $g_sp_ready ? 'data-spotify-auto' : ''; ?>>
							<button type="button" class="daw-player-cta" data-spotify-load>
								<span class="daw-player-play" aria-hidden="true"></span>
								<span class="daw-player-txt"><strong><?php esc_html_e( 'Play here', 'daw' ); ?></strong><small><?php esc_html_e( 'Plays here on the page. Nothing opens in a new tab.', 'daw' ); ?></small></span>
							</button>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</section>
	<?php endif; ?>

	<?php daw_ad_slot( 'daw-ad-leaderboard' ); ?>

	<?php
	/* ------------------------------------------------------ group data --- */

	// The fact tables. Each card is a question a reader might have, and a card
	// whose every row is empty is not printed at all: a table of dashes tells
	// nobody anything except that we did not look.
	$cards = array(
		__( 'Identity and formation', 'daw' ) => array(
			__( 'Group name', 'daw' )   => get_the_title( $id ),
			__( 'Native name', 'daw' )  => $native,
			__( 'Nicknames', 'daw' )    => $nicks,
			__( 'Former name', 'daw' )  => $former,
			__( 'Group type', 'daw' )   => $kind ? ( $kinds[ $kind ] ?? '' ) : '',
			__( 'Based in', 'daw' )     => $based,
			__( 'Formed', 'daw' )       => $formed,
		),
		__( 'Career and music', 'daw' ) => array(
			__( 'Debut date', 'daw' )    => $debut,
			__( 'Debut release', 'daw' ) => $debut_r,
			__( 'Agency', 'daw' )        => $agency,
			__( 'Record label', 'daw' )  => $label,
			__( 'Genres', 'daw' )        => $genres,
			__( 'Status', 'daw' )        => $status ? ( $statuses[ $status ] ?? '' ) : '',
		),
		__( 'Fandom and identity', 'daw' ) => array(
			__( 'Fandom name', 'daw' )         => $fandom,
			__( 'Fandom colour', 'daw' )       => $colour,
			__( 'Official lightstick', 'daw' ) => 'yes' === $stick ? __( 'Yes', 'daw' ) : ( 'no' === $stick ? __( 'No', 'daw' ) : '' ),
			// The line-up, not the profiles. This card said "Members: 2" for a
			// seven member group because it was counting our own records, the
			// same mistake the hero was making.
			__( 'Members', 'daw' )             => $line_up ? number_format_i18n( $line_up ) : ( $current ? number_format_i18n( count( $current ) ) : '' ),
			__( 'Former members', 'daw' )      => $past ? number_format_i18n( count( $past ) ) : '',
			__( 'Profiles here', 'daw' )       => $line_up && count( $current ) < $line_up ? number_format_i18n( count( $current ) ) : '',
		),
	);

	$cards = array_filter(
		$cards,
		static function ( $rows ) {
			return (bool) array_filter( array_map( 'trim', array_map( 'strval', $rows ) ) );
		}
	);

	if ( $cards ) :
		echo '<span id="group-data"></span>';
		daw_hub_head(
			__( 'Group data', 'daw' ),
			__( 'Debut, agency, line-up and fandom.', 'daw' )
		);
		?>
		<div class="daw-gcards daw-section daw-section-tight">
			<?php foreach ( $cards as $heading => $rows ) : ?>
				<div class="daw-pcard">
					<div class="daw-pcard-h"><?php echo esc_html( $heading ); ?></div>
					<dl class="daw-pcard-b">
						<?php
						foreach ( $rows as $label_text => $value ) {
							daw_rrow( $label_text, $value );
						}
						?>
					</dl>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php
	/* ----------------------------------------------------- discography --- */
	if ( $releases ) :
		$tally = array();

		foreach ( $releases as $rel ) {
			if ( $rel['type'] ) {
				$tally[ $rel['type'] ] = ( $tally[ $rel['type'] ] ?? 0 ) + 1;
			}
		}

		daw_hub_head(
			__( 'Discography', 'daw' ),
			__( 'Every release, newest first, with its own cover, tracklist and where to listen.', 'daw' )
		);
		?>
		<div id="discography" class="daw-section daw-section-tight daw-cols">
			<?php ob_start(); ?>
			<div class="daw-maincol">
			<?php if ( $tally ) : ?>
				<div class="daw-gfilters" role="group" aria-label="<?php esc_attr_e( 'Release types', 'daw' ); ?>">
					<button type="button" class="daw-quick-o is-on" data-daw-rel="all">
						<?php
						printf(
							/* translators: %s: how many releases */
							esc_html__( 'All %s', 'daw' ),
							esc_html( number_format_i18n( count( $releases ) ) )
						);
						?>
					</button>
					<?php foreach ( $types as $slug => $name ) : ?>
						<?php if ( ! empty( $tally[ $slug ] ) ) : ?>
							<button type="button" class="daw-quick-o" data-daw-rel="<?php echo esc_attr( $slug ); ?>">
								<?php echo esc_html( $name ); ?> <?php echo esc_html( number_format_i18n( $tally[ $slug ] ) ); ?>
							</button>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php
			// THE COVERS FIRST, THE TABLE UNDER THEM
			// --------------------------------------
			// A discography is looked at before it is read. The cards are how
			// a reader recognises a record they own; the table underneath is
			// how they check a date or a track count. Same rows, same filter,
			// two ways of reading them, and neither one repeats a fact the
			// other had to invent.
			?>
			<div class="daw-grels">
				<?php foreach ( $releases as $rel ) : ?>
					<div class="daw-grel" data-rel-type="<?php echo esc_attr( $rel['type'] ); ?>">
						<div class="daw-grel-art">
							<?php
							// An uploaded sleeve wins. Failing that the cover is
							// shown at Spotify's own address rather than copied
							// into the media library, which is how their terms
							// expect artwork to be used and keeps this out of
							// the pile of pictures nobody can source.
							if ( $rel['cover'] ) {
								echo wp_get_attachment_image( $rel['cover'], 'medium', false, array( 'loading' => 'lazy', 'alt' => esc_attr( $rel['title'] ) ) );
							} elseif ( $rel['cover_url'] ) {
								printf(
									'<img src="%s" alt="%s" loading="lazy" width="300" height="300" referrerpolicy="no-referrer">',
									esc_url( function_exists( 'daw_spotify_sized' ) ? daw_spotify_sized( $rel['cover_url'], 300 ) : $rel['cover_url'] ),
									esc_attr( $rel['title'] )
								);
							}
							?>
							<?php if ( ! empty( $types[ $rel['type'] ] ) ) : ?>
								<span class="daw-kindbadge"><?php echo esc_html( $types[ $rel['type'] ] ); ?></span>
							<?php endif; ?>
						</div>

						<div class="daw-grel-b">
							<strong><?php echo esc_html( $rel['title'] ); ?></strong>

							<?php
							$line = array_filter(
								array(
									$rel['year'] ? (string) $rel['year'] : '',
									$rel['tracks'] ? sprintf( _n( '%s track', '%s tracks', $rel['tracks'], 'daw' ), number_format_i18n( $rel['tracks'] ) ) : '',
									$rel['label'],
								)
							);

							if ( $line ) :
								?>
								<small><?php echo esc_html( implode( ' · ', $line ) ); ?></small>
							<?php endif; ?>

							<?php if ( $rel['track'] ) : ?>
								<small>
									<?php
									printf(
										/* translators: %s: the name of the title track */
										esc_html__( 'Title track: %s', 'daw' ),
										esc_html( $rel['track'] )
									);
									?>
								</small>
							<?php endif; ?>

							<?php if ( $rel['linked'] ) : ?>
								<small>
									<?php
									printf(
										/* translators: %s: the drama or film an OST belongs to */
										esc_html__( 'From %s', 'daw' ),
										esc_html( $rel['linked'] )
									);
									?>
								</small>
							<?php endif; ?>

							<?php if ( $rel['spotify'] || $rel['apple'] ) : ?>
								<span class="daw-grel-do">
									<?php if ( $rel['spotify'] ) : ?>
										<a href="<?php echo esc_url( $rel['spotify'] ); ?>" rel="noopener nofollow" target="_blank">Spotify</a>
									<?php endif; ?>
									<?php if ( $rel['apple'] ) : ?>
										<a href="<?php echo esc_url( $rel['apple'] ); ?>" rel="noopener nofollow" target="_blank">Apple</a>
									<?php endif; ?>
								</span>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<table class="daw-greleases">
				<caption class="daw-vh"><?php esc_html_e( 'Releases', 'daw' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Year', 'daw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Release', 'daw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'daw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Title track', 'daw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tracks', 'daw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Listen', 'daw' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $releases as $rel ) : ?>
						<tr data-rel-type="<?php echo esc_attr( $rel['type'] ); ?>">
							<td><?php echo esc_html( $rel['year'] ? $rel['year'] : '' ); ?></td>
							<th scope="row">
								<?php echo esc_html( $rel['title'] ); ?>
								<?php if ( $rel['native'] ) : ?>
									<span class="daw-grel-n"><?php echo esc_html( $rel['native'] ); ?></span>
								<?php endif; ?>
								<?php if ( $rel['linked'] ) : ?>
									<span class="daw-grel-n">
										<?php
										printf(
											/* translators: %s: the drama or film an OST belongs to */
											esc_html__( 'From %s', 'daw' ),
											esc_html( $rel['linked'] )
										);
										?>
									</span>
								<?php endif; ?>
							</th>
							<td><?php echo esc_html( $types[ $rel['type'] ] ?? '' ); ?></td>
							<td><?php echo esc_html( $rel['track'] ? $rel['track'] : '' ); ?></td>
							<td><?php echo esc_html( $rel['tracks'] ? number_format_i18n( $rel['tracks'] ) : '' ); ?></td>
							<td class="daw-grel-go">
								<?php if ( $rel['spotify'] ) : ?>
									<a href="<?php echo esc_url( $rel['spotify'] ); ?>" rel="noopener nofollow" target="_blank">Spotify</a>
								<?php endif; ?>
								<?php if ( $rel['apple'] ) : ?>
									<a href="<?php echo esc_url( $rel['apple'] ); ?>" rel="noopener nofollow" target="_blank">Apple</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $embed ) : ?>
				<?php
				// ONE PLAYER, NOT ONE PER RELEASE
				// -------------------------------
				// Twenty embedded players is twenty third-party iframes, each
				// one loading its own script on a page nobody asked to be an
				// application. One, at the foot of the list, and every release
				// above it carries a plain link instead.
				?>
				<div class="daw-gembed">
<?php $g_sp_ready = function_exists( 'wp_has_consent' ) ? wp_has_consent( 'marketing' ) : false; ?>
						<div class="daw-player" style="--player-h:152px" data-player-height="152" data-spotify="<?php echo esc_attr( str_replace( '/embed/', '/', (string) $embed ) ); ?>" <?php echo $g_sp_ready ? 'data-spotify-auto' : ''; ?>>
							<button type="button" class="daw-player-cta" data-spotify-load>
								<span class="daw-player-play" aria-hidden="true"></span>
								<span class="daw-player-txt"><strong><?php esc_html_e( 'Play here', 'daw' ); ?></strong><small><?php esc_html_e( 'Plays here on the page. Nothing opens in a new tab.', 'daw' ); ?></small></span>
							</button>
						</div>
				</div>
			<?php endif; ?>
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
				// The four or five facts somebody scrolling a long discography
				// still wants in front of them. Every one of them is already on
				// the page above; none of them is typed twice in the record.
				$quick = array_filter(
					array(
						__( 'Debut', 'daw' )   => $debut,
						__( 'Members', 'daw' ) => $shown ? number_format_i18n( $shown ) : '',
						__( 'Fandom', 'daw' )  => $fandom,
						__( 'Agency', 'daw' )  => $agency,
						__( 'Label', 'daw' )   => $label,
						__( 'Status', 'daw' )  => $status ? ( $statuses[ $status ] ?? '' ) : '',
					)
				);

				if ( $quick ) :
					?>
					<section class="daw-pcard">
						<div class="daw-pcard-h"><?php esc_html_e( 'Quick facts', 'daw' ); ?></div>
						<dl class="daw-pcard-b">
							<?php
							foreach ( $quick as $quick_label => $quick_value ) {
								daw_rrow( $quick_label, $quick_value );
							}
							?>
						</dl>
					</section>
				<?php endif; ?>

				<?php daw_ad_rail( $main_html ); ?>
			</aside>
		</div>
	<?php endif; ?>

	<?php
	/* ------------------------------------------------ on screen ---------
	 *
	 * This came off the page once, and it comes back on one condition: every
	 * card names the member who was actually in it and links to them. A group
	 * did not act. One of its members did, the record knew which one the whole
	 * time, and printing the posters without the name was the thing that made
	 * it read as a claim about the band.
	 */
	if ( $credits ) :
		echo '<span id="screen-credits"></span>';
		daw_hub_head(
			__( 'Members on screen', 'daw' ),
			__( 'Acting work by the people in this group, collected from their own profiles. Each one is credited to the member who did it.', 'daw' )
		);

		?>
		<div class="daw-grid daw-hubgrid daw-section daw-section-tight">
			<?php foreach ( $credits as $credit ) : ?>
				<?php $who = (int) ( $credit['member'] ?? 0 ); ?>
				<div class="daw-oscard">
					<a class="daw-person" href="<?php echo esc_url( (string) get_permalink( $credit['id'] ) ); ?>">
						<div class="daw-poster">
							<?php echo daw_poster( $credit['id'], 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php
							$c_year = (int) get_post_meta( $credit['id'], 'daw_aired_start', true );
							$c_year = $c_year ? gmdate( 'Y', $c_year ) : '';

							if ( $c_year ) :
								?>
								<span class="daw-yearbadge"><?php echo esc_html( $c_year ); ?></span>
							<?php endif; ?>
						</div>
						<div class="daw-person-name"><?php echo esc_html( get_the_title( $credit['id'] ) ); ?></div>
					</a>

					<?php
					// The name, and nothing around it. It reads as the second
					// line of the card rather than as a caption about it, which
					// is what "V (Kim Taehyung) · Guest" did: a label in a
					// different voice, explaining a thing the reader had
					// already understood from the name being there.
					if ( $who ) :
						?>
						<a class="daw-gcredit-who" href="<?php echo esc_url( (string) get_permalink( $who ) ); ?>"><?php echo esc_html( get_the_title( $who ) ); ?></a>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php
	/* -------------------------------------------------------- similar ---- */
	if ( $similar ) :
		echo '<span id="similar"></span>';
		daw_hub_head(
			__( 'Similar groups', 'daw' ),
			__( 'Other groups in the archive from the same scene. Not a claim about how anybody sounds, which is not something a database can know.', 'daw' )
		);
		?>
		<div class="daw-grid daw-hubgrid daw-section daw-section-tight">
			<?php
			foreach ( $similar as $sid ) {
				daw_group_member_card( (int) $sid );
			}
			?>
		</div>
	<?php endif; ?>

	<?php
	// The same tail every other record carries: the questions in the reading
	// column, the stories that name this group in the rail beside them. A band
	// gets asked different questions than an actor does, which is why the rows
	// are written on the record rather than derived from one.
	if ( $faq || $news ) :
		?>
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
					<section id="group-news">
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

	<p class="daw-sectionnote daw-gfoot">
		<?php
		printf(
			/* translators: %s: date this record was last updated */
			esc_html__( 'Group and member images belong to their rights holders and are used to identify the work. The discography is compiled from official releases. Last updated %s.', 'daw' ),
			esc_html( get_the_modified_date( 'j F Y', $id ) )
		);
		?>
	</p>
</div>

<?php if ( $releases ) : ?>
	<script>
	/* The release filter. Rows are hidden, never removed, so the table keeps
	   its shape and a reader with no script sees every release. */
	( function () {
		var bar = document.querySelector( '.daw-gfilters' );
		if ( ! bar ) { return; }

		bar.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-daw-rel]' );
			if ( ! btn ) { return; }

			var want = btn.dataset.dawRel;

			Array.prototype.forEach.call( bar.querySelectorAll( '[data-daw-rel]' ), function ( b ) {
				b.classList.toggle( 'is-on', b === btn );
			} );

			// The chips filter both views of the same list, because a reader
			// who narrows to Mini album and still sees every cover above the
			// table reads the chip as broken rather than as applying to half
			// the section.
			Array.prototype.forEach.call(
				document.querySelectorAll( '.daw-greleases tbody tr, .daw-grels .daw-grel' ),
				function ( row ) {
					row.hidden = ( 'all' !== want && row.dataset.relType !== want );
				}
			);
		} );
	}() );
	</script>
<?php endif; ?>

	<?php
endwhile;

// The click-to-play players above need the record scripts (1.63.1).
if ( function_exists( 'daw_record_scripts' ) ) {
	daw_record_scripts();
}

get_footer();
