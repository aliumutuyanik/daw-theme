<?php
/**
 * Single record — drama and film.
 *
 * Built to the approved mockup: a main column for the things people read and a
 * sticky rail for the things they check. The rail is why earlier versions felt
 * empty — the right half was never meant to be blank, it was meant to hold the
 * details card, the score and the streaming links.
 *
 * Reached through the single_template filter in DAW Core when dw_type is drama
 * or film. WordPress resolves /pinocchio/ exactly as it does today; only the
 * file that draws it changed.
 *
 * Every block checks for content first. A record halfway through research shows
 * what it has and stays silent about the rest.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$id     = get_the_ID();
	$type   = daw_get_type( $id );
	$native = daw_field( 'daw_native_title' );
	$aka    = (array) daw_field( 'daw_also_known_as', $id, false );
	// "Also known as Moon Lovers: Scarlet Heart Ryeo" under the heading that
	// already says Moon Lovers: Scarlet Heart Ryeo. The extractor copied the
	// title into the alias field on a lot of records, and an alias identical to
	// the title is not an alias. Dropped here rather than hunted down record by
	// record, because the same import will do it again.
	$aka    = array_values(
		array_filter(
			$aka,
			static function ( $name ) {
				$name = trim( (string) $name );

				return '' !== $name && 0 !== strcasecmp( $name, get_the_title() );
			}
		)
	);
	$start  = (int) daw_field( 'daw_aired_start' );
	$end    = (int) daw_field( 'daw_aired_end' );
	$year   = $start ? gmdate( 'Y', $start ) : '';
	$eps    = daw_field( 'daw_episodes' );
	$dur    = daw_field( 'daw_duration_min' );
	$net    = daw_terms( 'dw_network' );
	$days   = daw_field( 'daw_airing_days' );
	$rate   = daw_field( 'daw_content_rating' );
	$country = daw_terms( 'dw_country' );

	$status = function_exists( 'daw_airing_status' )
		? daw_airing_status( $id )
		: array( 'slug' => 'completed', 'label' => __( 'Completed', 'daw' ) );

	$rating = function_exists( 'daw_rating' ) ? daw_rating( $id ) : array( 'avg' => 0, 'count' => 0 );

	$synopsis  = daw_field( 'daw_synopsis' );
	$why       = daw_field( 'daw_why_watched' );
	$takeaways = array_filter( (array) daw_field( 'daw_takeaways', $id, false ) );
	$yt        = daw_youtube_id( daw_field( 'daw_trailer_youtube' ) );
	$watch     = function_exists( 'daw_get_watch_links' ) ? daw_get_watch_links( $id ) : array();
	$wp_html   = function_exists( 'daw_wp_html' ) ? daw_wp_html( $id ) : '';
	$gallery   = array_filter( (array) daw_field( 'daw_gallery', $id, false ) );

	$episodes = function_exists( 'daw_get_episodes' ) ? daw_get_episodes( $id ) : array();
	$ost      = function_exists( 'daw_get_ost' ) ? daw_get_ost( $id ) : array();
	$awards   = function_exists( 'daw_get_awards' ) ? daw_get_awards( $id ) : array();
	$faq      = function_exists( 'daw_get_faq' ) ? daw_get_faq( $id ) : array();
	$album    = daw_field( 'daw_ost_album' );

	$panels = array(
		'daw_best_scenes'       => __( 'Best scenes', 'daw' ),
		'daw_filming_location'  => __( 'Filming locations', 'daw' ),
		'daw_visual_design'     => __( 'Visual design and costumes', 'daw' ),
		'daw_themes_messages'   => __( 'Themes and messages', 'daw' ),
		'daw_interesting_facts' => __( 'Interesting facts', 'daw' ),
		// REMAKES GET THEIR OWN HEADING
		// -----------------------------
		// "Adapted from" in the Details card is one line and has to stay one
		// line, because it sits in a definition list beside Network and
		// Duration. A title that has been remade three times in three
		// countries cannot be described in a rail row, and readers arrive
		// searching for exactly that ("good doctor american remake"), so the
		// long version is a section with a heading of its own.
		'daw_remakes'           => __( 'Remakes and other versions', 'daw' ),
		// NO AWARDS PANEL HERE
		// -------------------
		// The word Awards appeared twice on the same page: once as a prose
		// panel in this run of sections, and again lower down as the real
		// table built from the award rows. Two headings with the same name is
		// a page telling a reader it has two different answers. The table is
		// the better of the two, because it is structured, dated and can say
		// won or nominated, so the prose panel goes and the table stays.
		//
		// Anything already written into the prose field is not lost; it is
		// simply no longer printed here, and belongs in the rows.
		'daw_real_life_loves'   => __( 'Real-life loves on set', 'daw' ),
	);
	$scenes = function_exists( 'daw_get_scenes' ) ? daw_get_scenes( $id ) : array();

	$filled = array();
	foreach ( $panels as $key => $label ) {
		// Structured scenes replace the prose panel rather than sitting beside
		// it — the same five moments twice would read as padding.
		if ( 'daw_best_scenes' === $key && $scenes ) {
			continue;
		}
		if ( trim( (string) daw_field( $key, $id ) ) !== '' ) {
			$filled[ $key ] = $label;
		}
	}

	// FOOD ON SCREEN SITS ABOVE "INTERESTING FACTS"
	// ---------------------------------------------
	// Read from the index daw-core keeps on this record, never queried for.
	// When Interesting facts is empty on this title the section takes the place
	// it would have had: before the next panel that is filled, else after them.
	// Empty index, no section and no heading (rule 2.1).
	$food_items = function_exists( 'daw_fos_title_foods' ) ? daw_fos_title_foods( $id ) : array();
	// One section per kind of guide (food, culture, myths, places, other), in a
	// fixed order; a kind with nothing linked prints nothing.
	$food_groups = ( $food_items && function_exists( 'daw_food_title_groups' ) ) ? daw_food_title_groups( $food_items ) : array();
	$food_items  = $food_groups ? $food_items : array();
	$food_slot   = '';
	if ( $food_items ) {
		$order = array_keys( $panels );
		$from  = array_search( 'daw_interesting_facts', $order, true );
		foreach ( array_keys( $filled ) as $fkey ) {
			if ( array_search( $fkey, $order, true ) >= $from ) {
				$food_slot = $fkey;
				break;
			}
		}
	}

	// CAST READS IN BILLING ORDER, AND EACH NAME APPEARS ONCE
	// -------------------------------------------------------
	// The untiered bucket is what the old tag-driven cast widget left behind:
	// no billing, no character names, and the same people who have since been
	// filed under Main and Supporting. It used to be printed first and without
	// a heading, so a record whose leads were already curated opened its cast
	// with a supporting actor under the word "Cast". The tiers now run in the
	// order a reader expects, the leftovers come last under a label that says
	// what they are, and anyone already billed is dropped from that leftover
	// list rather than shown twice.
	$tiers = array(
		'main'       => __( 'Main cast', 'daw' ),
		'supporting' => __( 'Supporting cast', 'daw' ),
		'guest'      => __( 'Guest appearances', 'daw' ),
		'untiered'   => __( 'Also in the cast', 'daw' ),
	);
	// The same three tiers mean something else on a show and on anime: a
	// variety show has hosts, regulars and guests; anime credits voices.
	if ( 'variety' === $type ) {
		$tiers['main']       = __( 'Hosts and regulars', 'daw' );
		$tiers['supporting'] = __( 'Recurring', 'daw' );
		$tiers['guest']      = __( 'Guests', 'daw' );
	} elseif ( 'anime' === $type ) {
		$tiers['main']       = __( 'Main voice cast', 'daw' );
		$tiers['supporting'] = __( 'Supporting voice cast', 'daw' );
		$tiers['guest']      = __( 'Guest voices', 'daw' );
	}
	$cast = array();
	$seen = array();
	foreach ( $tiers as $tier => $label ) {
		$rows = function_exists( 'daw_get_cast' ) ? daw_get_cast( $id, $tier ) : array();
		$keep = array();

		foreach ( $rows as $row ) {
			if ( isset( $seen[ (int) $row['id'] ] ) ) {
				continue;
			}

			$seen[ (int) $row['id'] ] = true;
			$keep[]                   = $row;
		}

		if ( $keep ) {
			$cast[ $tier ] = array(
				'label' => $label,
				'rows'  => $keep,
				// Saying "billing not confirmed" is the honest caption for a
				// list that came out of tags. It is also the line that tells an
				// editor this record still has work in it.
				'note'  => 'untiered' === $tier ? __( 'Billing not confirmed for these names.', 'daw' ) : '',
			);
		}
	}

	// The jump bar only offers what the record actually has.
	$nav = array( 'overview' => __( 'Overview', 'daw' ) );
	if ( $cast ) {
		$nav['cast'] = __( 'Cast', 'daw' );
	}
	if ( $scenes ) {
		$nav['best-scenes'] = __( 'Best scenes', 'daw' );
	}
	foreach ( $filled as $key => $label ) {
		if ( $food_groups && $key === $food_slot ) {
			foreach ( $food_groups as $fg ) {
				$nav[ $fg['id'] ] = $fg['label'];
			}
		}
		$nav[ sanitize_title( $label ) ] = $label;
	}
	if ( $food_groups && '' === $food_slot ) {
		foreach ( $food_groups as $fg ) {
			$nav[ $fg['id'] ] = $fg['label'];
		}
	}
	if ( $episodes ) {
		$nav['episodes'] = __( 'Episodes', 'daw' );
	}
	if ( $ost || $album ) {
		$nav['soundtrack'] = __( 'Soundtrack', 'daw' );
	}
	if ( $awards ) {
		$nav['awards-table'] = __( 'Awards', 'daw' );
	}
	if ( $yt ) {
		$nav['trailer'] = __( 'Trailer', 'daw' );
	}
	if ( $watch || $wp_html ) {
		$nav['where-to-watch'] = __( 'Where to watch', 'daw' );
	}
	if ( $faq ) {
		$nav['faq'] = __( 'FAQ', 'daw' );
	}
	if ( function_exists( 'daw_get_related_titles' ) && daw_get_related_titles( $id ) ) {
		$nav['related-titles'] = __( 'Same story', 'daw' );
	}
	if ( function_exists( 'daw_get_similar' ) && daw_get_similar( $id, 6 ) ) {
		$nav['similar'] = __( 'Similar', 'daw' );
	}
	?>

<div class="daw-wrap">

		<?php daw_crumb_nav( $id ); ?>

		<?php // ==================================================== hero == ?>
		<header class="daw-hero" id="overview">
			<div class="daw-hero-poster">
				<div class="daw-poster">
					<?php
					$poster = daw_poster( $id, 'medium_large' );
					// Largest thing above the fold: eager, high priority.
					echo $poster ? str_replace( 'loading="lazy"', 'loading="eager" fetchpriority="high"', $poster ) : '';
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
			</div>

			<?php
			// The hero is three parts, not two, and that is what lets the phone
			// layout work: the poster and the identity sit side by side, and
			// everything measured — facts, genres, dates, the button — runs the
			// full width underneath. Squeezing two full dates into the 226px left
			// beside a poster was what broke the old one.
			?>
			<div class="daw-hero-id">
				<p class="daw-eyebrow">
					<?php
					$eyebrow = array_filter( array( $country, daw_kind_label( $type ), $year ) );
					echo esc_html( implode( ' · ', $eyebrow ) );
					?>
				</p>

				<?php
				// A long title (a season subtitle, a full English name) gets a
				// smaller size so it stays within two lines beside the poster.
				$h1_len   = mb_strlen( wp_strip_all_tags( get_the_title() ) );
				$h1_class = $h1_len > 28 ? 'is-long' : ( $h1_len > 14 ? 'is-mid' : '' );
				?>
				<h1<?php echo $h1_class ? ' class="' . esc_attr( $h1_class ) . '"' : ''; ?>><?php the_title(); ?></h1>

				<?php if ( $native || $aka ) : ?>
					<div class="daw-native">
						<?php if ( $native ) : ?>
							<span lang="ko"><?php echo esc_html( $native ); ?></span>
						<?php endif; ?>
						<?php if ( $aka ) : ?>
							<span class="daw-aka">
								<?php
								printf(
									/* translators: %s: comma separated alternative titles */
									esc_html__( 'Also known as %s', 'daw' ),
									esc_html( implode( ', ', $aka ) )
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php
				// SEASONS (madde 65)
				// ------------------
				// Every season of a series keeps its own page, the way
				// MyDramaList does it; the series record stands for season 1.
				// All pages of one series link to each other here, under the
				// title, so a reader on season 2 is one tap from season 1.
				$season_nav = function_exists( 'daw_season_nav' ) ? daw_season_nav( $id ) : array();
				if ( count( $season_nav ) > 1 ) :
					?>
					<nav class="daw-seasons" aria-label="<?php esc_attr_e( 'Seasons', 'daw' ); ?>">
						<span class="daw-seasons-lbl"><?php echo esc_html( sprintf( _n( '%s season', '%s seasons', count( $season_nav ), 'daw' ), number_format_i18n( count( $season_nav ) ) ) ); ?></span>
						<?php foreach ( $season_nav as $sn ) : ?>
							<?php
							// "Season" is kept in the link text for screen readers and
							// search; on a phone it is clipped visually and a short "S"
							// shows instead (S1 · 2017), so two seasons fit beside the
							// poster.
							$sn_label = '<span class="daw-season-word">' . esc_html__( 'Season', 'daw' ) . ' </span><span class="daw-season-s" aria-hidden="true">S</span>' . (int) $sn['n'] . ( '' !== $sn['year'] ? ' · ' . esc_html( $sn['year'] ) : '' );
							?>
							<?php if ( $sn['current'] ) : ?>
								<span class="daw-season is-current" aria-current="page"><?php echo $sn_label; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?></span>
							<?php else : ?>
								<a class="daw-season" href="<?php echo esc_url( $sn['url'] ); ?>"><?php echo $sn_label; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?></a>
							<?php endif; ?>
						<?php endforeach; ?>
					</nav>
				<?php endif; ?>
			</div>

			<div class="daw-hero-meta">
				<p class="daw-facts">
					<?php
					// A SHRUG IS NOT A FACT
					// ---------------------
					// The airing day read "To be confirmed" and the rating read
					// "[Information not available]", both printed in the same
					// line and the same type as the channel and the episode
					// count. The guard is central so every field cleaned there
					// is cleaned here.
					$facts = array_filter( array(
						function_exists( 'daw_clean_value' ) ? daw_clean_value( $net ) : $net,
						$eps ? sprintf( _n( '%s episode', '%s episodes', (int) $eps, 'daw' ), number_format_i18n( (int) $eps ) ) : '',
						$dur ? sprintf( __( '%s min', 'daw' ), number_format_i18n( (int) $dur ) ) : '',
						function_exists( 'daw_clean_value' ) ? daw_clean_value( $days ) : $days,
						function_exists( 'daw_clean_value' ) ? daw_clean_value( $rate ) : $rate,
					) );
					echo esc_html( implode( ' · ', $facts ) );
					?>
					<?php if ( '' !== $status['label'] ) : ?>
						<span class="daw-status daw-status-<?php echo esc_attr( $status['slug'] ); ?>">
							<?php echo esc_html( $status['label'] ); ?>
						</span>
					<?php endif; ?>
				</p>

				<?php
				// Genre chips. The vote that used to share this row now sits
				// in its own cell at the top right of the hero (see below).
				$genres = get_the_terms( $id, 'dw_genre' );
				?>
				<?php if ( $genres && ! is_wp_error( $genres ) ) : ?>
				<div class="daw-hero-row">
						<div class="daw-metarow">
							<?php foreach ( $genres as $g ) : ?>
								<span class="daw-chip"><?php echo esc_html( $g->name ); ?></span>
							<?php endforeach; ?>
						</div>
				</div>
				<?php endif; ?>

				<div class="daw-stats">
					<?php
					// The reader score is not repeated here: it sits in the
					// vote card, which is also where it updates after a vote
					// (K14).
					?>
					<?php if ( $start ) : ?>
						<div class="daw-stat">
							<div class="daw-stat-v is-plain"><?php echo esc_html( date_i18n( 'j M Y', $start ) ); ?></div>
							<div class="daw-stat-l"><?php echo esc_html( daw_is_single_work( $type ) ? __( 'Released', 'daw' ) : __( 'Premiere', 'daw' ) ); ?></div>
						</div>
					<?php endif; ?>
					<?php if ( $end ) : ?>
						<div class="daw-stat">
							<div class="daw-stat-v is-plain"><?php echo esc_html( date_i18n( 'j M Y', $end ) ); ?></div>
							<div class="daw-stat-l"><?php esc_html_e( 'Finale', 'daw' ); ?></div>
						</div>
					<?php endif; ?>

					<?php
					// THE SCORE AS A THIRD DATE CELL, ON A PHONE ONLY
					// ----------------------------------------------
					// Once a title has votes, a phone shows the average beside
					// Premiere and Finale, so the row splits into three equal
					// cells. On a desktop the vote card at the end of this strip
					// already carries the score, so the cell stays hidden there
					// (K14). An unrated title shows two cells; the first vote
					// brings the third out without a reload.
					?>
					<div class="daw-stat daw-stat-score" data-score-cell <?php echo $rating['count'] ? '' : 'hidden'; ?>>
						<div class="daw-stat-v"><b data-score-cell-v><?php echo $rating['count'] ? esc_html( number_format_i18n( $rating['avg'], 1 ) ) : ''; ?></b><span class="daw-stat-of">/10</span></div>
						<div class="daw-stat-l"><?php esc_html_e( 'Reader score', 'daw' ); ?></div>
					</div>

					<?php
					// THE BUTTON BELONGS IN THE STRIP, NOT UNDER IT
					// ---------------------------------------------
					// On its own line the trailer button had a whole band of the
					// hero to itself and nothing beside it, so the eye read the
					// row as unfinished. In the strip it becomes the last fact
					// about the title — score, dates, and the thing you can do
					// about them — and it inherits the divider the dates already
					// draw between themselves. On a phone the strip is a three
					// column grid, so the button drops below it and takes the
					// full width, where a primary action wants to be anyway.
					if ( $yt ) :
						?>
						<div class="daw-stat daw-stat-cta">
							<a class="daw-btn daw-btn-p" href="#trailer"><?php esc_html_e( 'Watch trailer', 'daw' ); ?></a>
						</div>
					<?php endif; ?>

					<?php
					// THE VOTE CLOSES THE STRIP
					// -------------------------
					// A small card pushed to the right end of the dates row: it
					// never competes with the title, however long the title is,
					// and the hero still ends where the poster ends. On a phone
					// it takes the full width under the trailer button.
					?>
					<div class="daw-stat daw-stat-rate">
						<div class="daw-rate daw-rate--inline" data-post="<?php echo (int) $id; ?>">
							<div class="daw-rate-top">
								<span class="daw-rate-lbl"><?php esc_html_e( 'Your rating', 'daw' ); ?></span>
								<span class="daw-rate-head" <?php echo $rating['count'] ? '' : 'hidden'; ?>><b data-score><?php echo $rating['count'] ? esc_html( number_format_i18n( $rating['avg'], 1 ) ) : ''; ?></b>/10</span>
								<?php
							// ONE LINE THAT KNOWS WHERE THE VOTE STANDS
							// -----------------------------------------
							// Nobody has voted: "Be the first to vote". Others have:
							// the count and an invitation. The reader has just voted:
							// thanks, and "you're the first" when that is true. A
							// returning reader who voted before sees their own score.
							// The script swaps the text; these are its words.
							$n_votes = (int) $rating['count'];
							if ( 0 === $n_votes ) {
								$rate_line = __( 'Be the first to vote', 'daw' );
							} else {
								/* translators: %s: number of votes */
								$rate_line = sprintf( _n( '%s vote so far, add yours', '%s votes so far, add yours', $n_votes, 'daw' ), number_format_i18n( $n_votes ) );
							}
							?>
							<span class="daw-rate-count" data-count data-n="<?php echo (int) $n_votes; ?>"
								data-t-first-thanks="<?php esc_attr_e( 'You are the first to vote. Thank you!', 'daw' ); ?>"
								data-t-thanks-one="<?php esc_attr_e( 'Thanks for voting. 1 vote so far', 'daw' ); ?>"
								data-t-thanks="<?php /* translators: %s: number of votes */ echo esc_attr( __( 'Thanks for voting. %s votes so far', 'daw' ) ); ?>"
								data-t-mine="<?php /* translators: 1: the reader's own score, 2: number of votes */ echo esc_attr( __( 'You gave it %1$s. %2$s votes so far', 'daw' ) ); ?>"
								data-t-mine-one="<?php /* translators: %s: the reader's own score */ echo esc_attr( __( 'You gave it %s. The only vote so far', 'daw' ) ); ?>"><?php echo esc_html( $rate_line ); ?></span>
							</div>
							<div class="daw-rate-stars" role="group" aria-label="<?php esc_attr_e( 'Rate this title from 1 to 10', 'daw' ); ?>">
								<?php for ( $n = 1; $n <= 10; $n++ ) : ?>
									<button type="button" data-score-btn="<?php echo (int) $n; ?>" aria-pressed="false"><?php echo (int) $n; ?></button>
								<?php endfor; ?>
							</div>
						</div>
					</div>
				</div>
			</div>
		</header>

		<?php if ( count( $nav ) > 2 ) : ?>
			<nav class="daw-stickynav" aria-label="<?php esc_attr_e( 'On this page', 'daw' ); ?>">
				<ul>
					<?php foreach ( $nav as $anchor => $label ) : ?>
						<li><a href="#<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $label ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
		<?php endif; ?>

		<?php // ============================== main column + reference rail == ?>
		<div class="daw-cols daw-section">

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

				<?php if ( trim( (string) $synopsis ) !== '' ) : ?>
					<section id="synopsis" class="daw-tsec">
						<h2><?php
						// K11: the heading is the searched phrase, "<title> synopsis".
						/* translators: %s: title */
						printf( esc_html__( '%s synopsis', 'daw' ), esc_html( get_the_title() ) );
						?></h2>
						<div class="daw-prose" style="margin-top:12px"><?php echo wp_kses_post( daw_prose( $synopsis ) ); ?></div>
					</section>
				<?php endif; ?>

				<?php
				// Extra H2 sections for a searched question (madde 63), right
				// under the synopsis they extend.
				foreach ( function_exists( 'daw_get_title_sections' ) ? daw_get_title_sections( $id ) : array() as $xsec ) :
					?>
					<section id="<?php echo esc_attr( sanitize_title( $xsec['title'] ) ); ?>" class="daw-tsec">
						<h2><?php echo esc_html( $xsec['title'] ); ?></h2>
						<div class="daw-prose" style="margin-top:12px"><?php echo wp_kses_post( daw_prose( $xsec['body'] ) ); ?></div>
					</section>
				<?php endforeach; ?>

				<?php
				// THE HAND-OFF, AND WHY IT SITS HERE
				// ----------------------------------
				// Directly under the synopsis, with the whole "Why it still
				// gets watched" section between it and the in-content advert
				// further down. That gap is deliberate: an editorial link to
				// another site, placed hard against an ad unit, is the exact
				// arrangement that reads as paid placement to a reviewer even
				// when nothing was paid. It is also why this is a bordered
				// note with a plain text link and no button styling. It looks
				// like a reference, because it is one.
				//
				// It renders only when the url is filled, so a title with no
				// counterpart page shows nothing rather than a dead promise.
				$blgl = trim( (string) daw_field( 'daw_blgl_url' ) );
				if ( $blgl !== '' && filter_var( $blgl, FILTER_VALIDATE_URL ) ) :
					?>
					<aside class="daw-handoff daw-tsec">
						<p class="daw-handoff-l"><?php esc_html_e( 'On our sister site', 'daw' ); ?></p>
						<p class="daw-handoff-b">
							<?php
							printf(
								/* translators: %s: link to the title's page on the BL/GL site */
								esc_html__( 'This title is covered as a BL or GL series on %s, where the pairing, the cast chemistry and the genre rankings are kept. This page stays with the record: the facts, the cast and where it is licensed to stream.', 'daw' ),
								'<a href="' . esc_url( $blgl ) . '">BLGLFlix</a>'
							);
							?>
						</p>
					</aside>
				<?php endif; ?>

				<?php if ( trim( (string) $why ) !== '' ) : ?>
					<section id="why" class="daw-tsec">
						<h2><?php esc_html_e( 'Why it still gets watched', 'daw' ); ?></h2>
						<div class="daw-prose" style="margin-top:12px"><?php echo wp_kses_post( daw_prose( $why ) ); ?></div>
					</section>
				<?php endif; ?>

				<?php
				// Directly under "Why it still gets watched", which is where the
				// overview ends. The reader has finished the argument and has not
				// yet started the details, so an advert here interrupts nothing.
				// It runs at every width: on a phone the rail stacks far below
				// and this is the only unit anyone reaches, and on a desktop it
				// is the one unit sitting where the eye already is.
				daw_ad_slot( 'daw-ad-incontent' );
				?>
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

				<div class="daw-rcard">
					<div class="daw-rcard-h"><?php esc_html_e( 'Details', 'daw' ); ?></div>
					<div class="daw-rcard-b">
						<dl style="margin:0">
							<?php
							daw_rrow( __( 'Native title', 'daw' ), $native );
							daw_rrow( __( 'Country', 'daw' ), $country );
							daw_rrow( __( 'Network', 'daw' ), $net );
							// Crew names link to their records when the site has
							// one; the rest stay plain text. No photos here.
							daw_rrow_html( __( 'Director', 'daw' ), function_exists( 'daw_crew_html' ) ? daw_crew_html( $id, 'Director', daw_field( 'daw_director', $id, false ) ) : esc_html( implode( ', ', (array) daw_field( 'daw_director', $id, false ) ) ) );
							daw_rrow_html( __( 'Screenwriter', 'daw' ), function_exists( 'daw_crew_html' ) ? daw_crew_html( $id, 'Screenwriter', daw_field( 'daw_screenwriter', $id, false ) ) : esc_html( implode( ', ', (array) daw_field( 'daw_screenwriter', $id, false ) ) ) );
							daw_rrow_html( __( 'Original author', 'daw' ), function_exists( 'daw_crew_html' ) ? daw_crew_html( $id, 'Original author', daw_field( 'daw_original_author', $id, false ) ) : '' );
							daw_rrow_html( __( 'Producer', 'daw' ), function_exists( 'daw_crew_html' ) ? daw_crew_html( $id, 'Producer', daw_field( 'daw_producer', $id, false ) ) : '' );
							daw_rrow( __( 'Studio', 'daw' ), daw_field( 'daw_studio', $id, false ) );
							// One season is the default and needs no row; a count above one is news.
							$seasons = (int) daw_field( 'daw_seasons', $id );
							daw_rrow( __( 'Seasons', 'daw' ), $seasons > 1 ? $seasons : '' );
							daw_rrow( __( 'Episodes', 'daw' ), $eps );
							daw_rrow( __( 'Duration', 'daw' ), $dur ? $dur . ' ' . __( 'min', 'daw' ) : '' );
							daw_rrow( __( 'Aired', 'daw' ), $start ? date_i18n( 'j M Y', $start ) . ( $end && date_i18n( 'Ymd', $end ) !== date_i18n( 'Ymd', $start ) ? ' to ' . date_i18n( 'j M Y', $end ) : '' ) : '' );
							daw_rrow( __( 'Airing days', 'daw' ), $days );
							daw_rrow( __( 'Rating', 'daw' ), $rate );
							daw_rrow( __( 'Genres', 'daw' ), daw_terms( 'dw_genre' ) );
							// Genres sort the title against every other title.
							// Tags describe this one: time travel, royal court
							// politics, a love triangle. The design had both, and
							// they answer different questions, so both are here.
							daw_rrow( __( 'Tags', 'daw' ), daw_field( 'daw_tags', $id, false ) );
							daw_rrow( __( 'Adapted from', 'daw' ), daw_field( 'daw_adaptation' ) );
							daw_rrow( __( 'Production', 'daw' ), daw_field( 'daw_production', $id, false ) );
							daw_rrow( __( 'Language', 'daw' ), daw_field( 'daw_language' ) );
							daw_rrow( __( 'Status', 'daw' ), $status['label'] );

							// On a drama still running, the one fact a returning
							// reader wants is when the next one lands.
							if ( function_exists( 'daw_next_episode' ) ) {
								$next = daw_next_episode( $id );
								if ( $next ) {
									daw_rrow(
										__( 'Next episode', 'daw' ),
										sprintf(
											/* translators: 1: episode number, 2: air date, 3: how soon */
											__( 'Ep %1$s · %2$s (%3$s)', 'daw' ),
											$next['no'],
											$next['date'],
											daw_episode_when( $next )
										)
									);
								}
							}
							?>
						</dl>
					</div>
				</div>

				<?php
				// The highest-value slot on the page: beside the details card,
				// in view the moment the record opens on a desktop. The old
				// layout put the only rail unit at the bottom of a rail that
				// runs the height of the article, which is another way of
				// saying almost nobody saw it.
				daw_ad_slot( 'daw-ad-rect' );
				?>

				<?php
				// A typed official link whose platform is already in a country
				// list is not printed twice: that row links to the same page.
				if ( $wp_html && function_exists( 'daw_wp_lists_platform' ) ) {
					$watch = array_values( array_filter( $watch, static function ( $w ) use ( $id ) {
						return ! daw_wp_lists_platform( $id, $w['platform'], $w['url'] );
					} ) );
				}
				?>
				<?php if ( $watch || $wp_html ) : ?>
					<div class="daw-rcard" id="where-to-watch">
						<div class="daw-rcard-h"><?php esc_html_e( 'Where to watch', 'daw' ); ?></div>
						<div class="daw-rcard-b">
							<?php foreach ( $watch as $w ) : ?>
								<a class="daw-watchrow" href="<?php echo esc_url( $w['url'] ); ?>" target="_blank" rel="noopener nofollow">
									<?php echo daw_watch_mark( $w['url'], $w['platform'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span class="daw-watch-name">
										<strong><?php echo esc_html( daw_watch_label( $w['url'], $w['platform'] ) ); ?></strong>
										<?php if ( $w['access'] ) : ?>
											<small><?php echo esc_html( $w['access'] ); ?></small>
										<?php endif; ?>
									</span>
									<span class="daw-watch-go" aria-hidden="true">↗</span>
								</a>
							<?php endforeach; ?>
							<?php if ( $watch && $wp_html ) : ?>
								<div class="daw-wp-h"><?php esc_html_e( 'By country', 'daw' ); ?></div>
							<?php endif; ?>
							<?php echo $wp_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</div>
					</div>
				<?php endif; ?>

				<?php daw_ad_rail( $main_html ); ?>
			</aside>
		</div>

		<?php // ==================================================== cast == ?>
		<?php if ( $cast ) : ?>
			<section class="daw-section" id="cast">
				<h2><?php
				// The heading carries the searched pattern ("Pinocchio cast"), K11.
				printf(
					/* translators: %s: title */
					esc_html( 'anime' === $type ? __( '%s voice cast', 'daw' ) : __( '%s cast', 'daw' ) ),
					esc_html( get_the_title( $id ) )
				);
				?></h2>
				<?php foreach ( $cast as $block ) : ?>
					<?php if ( $block['label'] ) : ?>
						<div class="daw-tier"><?php echo esc_html( $block['label'] ); ?></div>
					<?php endif; ?>
					<?php if ( ! empty( $block['note'] ) ) : ?>
						<p class="daw-empty" style="margin:-4px 0 12px;font-size:.78rem"><?php echo esc_html( $block['note'] ); ?></p>
					<?php endif; ?>
					<div class="daw-rail-row">
						<?php foreach ( $block['rows'] as $row ) : ?>
							<a class="daw-person" href="<?php echo esc_url( get_permalink( $row['id'] ) ); ?>">
								<div class="daw-poster"><?php echo daw_poster( $row['id'], 'medium' ); ?></div>
								<div class="daw-person-name"><?php echo esc_html( get_the_title( $row['id'] ) ); ?></div>
								<?php
								// The character, and how much of the run they
								// are in when that is not all of it.
								$who = array_filter( array(
									$row['role'],
									isset( $row['eps'] ) ? trim( (string) $row['eps'] ) : '',
								) );

								if ( $who ) :
									?>
									<div class="daw-person-role"><?php echo esc_html( implode( ' · ', $who ) ); ?></div>
								<?php endif; ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>

		<?php // ============================================ best scenes == ?>
		<?php if ( $scenes ) : ?>
			<section class="daw-section" id="best-scenes">
				<?php
				// A SPOILER WARNING IS PART OF THE CONTENT, NOT A DISCLAIMER
				// ----------------------------------------------------------
				// This is the one section on the page that names episode numbers
				// next to what happens in them. A reader who arrived to decide
				// whether to start the show can be spoiled by a single glance, and
				// by the time they have read the line it is too late to warn them.
				// So the warning sits beside the heading, above the first scene,
				// where it is read before anything it is warning about.
				?>
				<div class="daw-h2row">
					<h2><?php esc_html_e( 'Best scenes', 'daw' ); ?></h2>
					<span class="daw-spoiler-tag"><?php esc_html_e( 'Spoilers ahead', 'daw' ); ?></span>
				</div>
				<p class="daw-empty" style="margin:0 0 16px">
					<?php esc_html_e( 'The moments people come back for, and where to find them. Episode numbers and outcomes are named, so come back to this one after watching if you would rather not know.', 'daw' ); ?>
				</p>
				<ol class="daw-scenes">
					<?php foreach ( $scenes as $s ) : ?>
						<li>
							<div class="daw-scene-h">
								<strong><?php echo esc_html( $s['title'] ); ?></strong>
								<?php if ( $s['ep'] ) : ?>
									<span class="daw-epbadge">
										<?php
										printf(
											/* translators: %s: episode number or range */
											esc_html__( 'Episode %s', 'daw' ),
											esc_html( $s['ep'] )
										);
										?>
									</span>
								<?php endif; ?>
							</div>
							<?php if ( $s['note'] ) : ?>
								<p><?php echo esc_html( $s['note'] ); ?></p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>
		<?php endif; ?>

		<?php daw_ad_slot( 'daw-ad-leaderboard' ); ?>

		<?php // ============================ deep dive + trailer in rail == ?>
		<?php if ( $filled || $yt || $food_items ) : ?>
			<div class="daw-cols daw-section">
				<div class="daw-maincol">
					<?php
					$first = true;
					foreach ( $filled as $key => $label ) :
						if ( $food_groups && $key === $food_slot ) {
							foreach ( $food_groups as $fg ) {
								daw_food_title_section( $fg );
							}
						}
						?>
						<details class="daw-acc" id="<?php echo esc_attr( sanitize_title( $label ) ); ?>"<?php echo $first ? ' open' : ''; ?>>
							<summary><h2><?php echo esc_html( $label ); ?></h2></summary>
							<div class="daw-acc-body">
								<div class="daw-prose"><?php echo wp_kses_post( daw_prose( daw_field( $key, $id ) ) ); ?></div>
							</div>
						</details>
						<?php
						$first = false;
					endforeach;
					if ( $food_groups && '' === $food_slot ) {
						foreach ( $food_groups as $fg ) {
							daw_food_title_section( $fg );
						}
					}
					?>
				</div>

				<aside class="daw-rail">
					<?php if ( $yt ) : ?>
						<div class="daw-rcard" id="trailer">
							<div class="daw-rcard-h"><?php esc_html_e( 'Trailer', 'daw' ); ?></div>
							<div class="daw-rcard-b">
								<div class="daw-embed" data-yt="<?php echo esc_attr( $yt ); ?>">
									<img src="https://i.ytimg.com/vi/<?php echo esc_attr( $yt ); ?>/hqdefault.jpg"
										alt="<?php echo esc_attr( sprintf( __( 'Trailer thumbnail for %s', 'daw' ), get_the_title() ) ); ?>"
										width="480" height="360" loading="lazy" decoding="async">
									<button type="button" class="daw-embed-play" aria-label="<?php esc_attr_e( 'Play trailer', 'daw' ); ?>">
										<span aria-hidden="true">&#9654;</span>
									</button>
								</div>
								<p class="daw-empty" style="margin:10px 0 0;font-size:.76rem">
									<?php esc_html_e( 'Official trailer on YouTube. Nothing loads from YouTube until you press play.', 'daw' ); ?>
								</p>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( ! $gallery ) : ?>
						<div class="daw-rcard">
							<div class="daw-rcard-h"><?php esc_html_e( 'Gallery', 'daw' ); ?></div>
							<div class="daw-rcard-b">
								<p class="daw-empty" style="margin:0;font-size:.8rem">
									<?php esc_html_e( 'Empty by default. Stills are only published where we hold written permission from the rights holder.', 'daw' ); ?>
								</p>
							</div>
						</div>
					<?php endif; ?>
				</aside>
			</div>
		<?php endif; ?>

		<?php // ================================================ episodes == ?>
		<?php if ( $episodes ) : ?>
			<section class="daw-section" id="episodes">
				<h2><?php
				/* translators: %s: title */
				printf( esc_html__( '%s episodes', 'daw' ), esc_html( get_the_title() ) );
				?></h2>
				<div class="daw-tbl-wrap">
					<table class="daw-tbl">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Ep', 'daw' ); ?></th>
								<th><?php esc_html_e( 'Title', 'daw' ); ?></th>
								<th><?php esc_html_e( 'Air date', 'daw' ); ?></th>
								<th><?php esc_html_e( 'Status', 'daw' ); ?></th>
								<th><?php esc_html_e( 'Rating', 'daw' ); ?></th>
							</tr>
						</thead>
						<?php
						// A LONG-RUNNING SHOW IS NOT A 1,300-ROW TABLE
						// ---------------------------------------------
						// A weekly music show carries more than a thousand
						// episodes, and drawing every row cost the page its
						// mobile speed score. The latest 40 are drawn; the rest
						// wait in an inert <template> until the reader asks.
						$daw_ep_rows = array();
						?>
						<tbody id="daw-eps-body">
							<?php foreach ( $episodes as $ep ) : ?>
								<?php ob_start(); ?>
								<?php $state = isset( $ep['state'] ) ? $ep['state'] : ''; ?>
								<tr class="daw-ep is-<?php echo esc_attr( $state ? $state : 'unknown' ); ?>">
									<td class="is-num"><?php echo esc_html( $ep['no'] ); ?></td>
									<td>
										<?php echo esc_html( $ep['title'] ); ?>
										<?php
										// THE TITLE IS A HOOK, NOT AN ANSWER
										// ----------------------------------
										// "Can We Feel That Spark Again?" tells
										// somebody who missed the episode
										// nothing. The line underneath it does,
										// and it is what people search for when
										// they are catching up one episode at a
										// time. Printed only when it exists, so
										// an unaired episode keeps a clean row.
										$ep_syn = isset( $ep['synopsis'] ) ? trim( (string) $ep['synopsis'] ) : '';

										if ( '' !== $ep_syn ) :
											?>
											<span class="daw-epsyn"><?php echo esc_html( $ep_syn ); ?></span>
										<?php endif; ?>
									</td>
									<td class="is-num"><?php echo esc_html( $ep['date'] ); ?></td>
									<td>
										<?php if ( $state ) : ?>
											<span class="daw-epstate daw-epstate-<?php echo esc_attr( $state ); ?>">
												<?php echo esc_html( daw_episode_when( $ep ) ); ?>
											</span>
										<?php else : ?>
											<span class="daw-epstate daw-epstate-unknown"><?php esc_html_e( 'Date TBC', 'daw' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="is-num">
										<?php
										// An episode with no rating gets an empty
										// cell. A dash here is a placeholder
										// pretending to be data, and the column
										// heading already says what the column is.
										echo $ep['rating'] ? esc_html( rtrim( (string) $ep['rating'], '%' ) . '%' ) : '';
										?>
									</td>
								</tr>
								<?php $daw_ep_rows[] = (string) ob_get_clean(); ?>
							<?php endforeach; ?>
							<?php
							$daw_ep_keep = 40;
							$daw_ep_more = count( $daw_ep_rows ) > $daw_ep_keep + 20 ? array_slice( $daw_ep_rows, 0, count( $daw_ep_rows ) - $daw_ep_keep ) : array();
							if ( $daw_ep_more ) {
								echo '<template id="daw-eps-more">' . implode( '', $daw_ep_more ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput -- rows escaped above.
								echo implode( '', array_slice( $daw_ep_rows, -$daw_ep_keep ) ); // phpcs:ignore WordPress.Security.EscapeOutput
							} else {
								echo implode( '', $daw_ep_rows ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							?>
						</tbody>
					</table>
				</div>
				<?php if ( $daw_ep_more ) : ?>
					<p class="daw-eps-more">
						<button type="button" class="daw-btn" id="daw-eps-btn">
							<?php
							/* translators: %s: number of earlier episodes */
							printf( esc_html__( 'Show the %s earlier episodes', 'daw' ), esc_html( number_format_i18n( count( $daw_ep_more ) ) ) );
							?>
						</button>
					</p>
					<script>
					(function(){var b=document.getElementById('daw-eps-btn'),t=document.getElementById('daw-eps-more'),body=document.getElementById('daw-eps-body');if(!b||!t||!body)return;b.addEventListener('click',function(){body.insertBefore(t.content,body.firstChild);b.parentNode.remove();});})();
					</script>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php // ============================================== soundtrack == ?>
		<?php if ( $ost || $album ) : ?>
			<section class="daw-section" id="soundtrack">
				<h2><?php
				/* translators: %s: title */
				printf( esc_html__( '%s OST', 'daw' ), esc_html( get_the_title() ) );
				?></h2>

				<div class="daw-cols">
					<div class="daw-maincol">
						<?php if ( $album ) : ?>
							<?php
							// If this reader has already agreed to embedded media, the
							// player is simply there — asking a second time for consent
							// they have already given is friction, not privacy. Only a
							// reader who has not agreed sees the card, and one tap on it
							// is both the consent and the play.
							$spotify_ready = function_exists( 'wp_has_consent' ) ? wp_has_consent( 'marketing' ) : false;
							?>
							<div class="daw-player" data-spotify="<?php echo esc_attr( $album ); ?>"
								<?php echo $spotify_ready ? 'data-spotify-auto' : ''; ?>>
								<button type="button" class="daw-player-cta" data-spotify-load>
									<span class="daw-player-play" aria-hidden="true"></span>
									<span class="daw-player-txt">
										<strong><?php esc_html_e( 'Play the soundtrack', 'daw' ); ?></strong>
										<small>
											<?php
											printf(
												/* translators: %d: number of tracks */
												esc_html( _n( '%d track on Spotify · tap to start the player', '%d tracks on Spotify · tap to start the player', max( 1, count( $ost ) ), 'daw' ) ),
												(int) max( 1, count( $ost ) )
											);
											?>
										</small>
									</span>
								</button>
								<p class="daw-player-note">
									<?php esc_html_e( 'Starting the player loads Spotify, which sets its own cookies.', 'daw' ); ?>
								</p>
							</div>
						<?php endif; ?>

						<?php if ( $ost ) : ?>
							<div class="daw-tbl-wrap" style="margin-top:<?php echo $album ? '16px' : '0'; ?>">
								<table class="daw-tbl">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Track', 'daw' ); ?></th>
											<th><?php esc_html_e( 'Artist', 'daw' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $ost as $t ) : ?>
											<tr>
												<td><?php echo esc_html( $t['track'] ); ?></td>
												<td class="is-num"><?php echo esc_html( $t['artist'] ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
					</div>

					<aside class="daw-rail">
						<div class="daw-rcard">
							<div class="daw-rcard-h"><?php esc_html_e( 'Music', 'daw' ); ?></div>
							<div class="daw-rcard-b">
								<dl style="margin:0">
									<?php
									daw_rrow( __( 'Tracks', 'daw' ), $ost ? count( $ost ) : '' );
									daw_rrow( __( 'Network', 'daw' ), $net );
									?>
								</dl>
								<p class="daw-empty" style="margin:10px 0 0;font-size:.76rem">
									<?php esc_html_e( 'Track titles and artists are listed for reference. Audio is streamed by Spotify, never hosted here.', 'daw' ); ?>
								</p>
							</div>
						</div>
					</aside>
				</div>
			</section>
		<?php endif; ?>

		<?php // ============================================ awards table == ?>
		<?php if ( $awards ) : ?>
			<section class="daw-section" id="awards-table">
				<h2><?php esc_html_e( 'Awards', 'daw' ); ?></h2>
				<div class="daw-tbl-wrap">
					<table class="daw-tbl">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Year', 'daw' ); ?></th>
								<th><?php esc_html_e( 'Ceremony', 'daw' ); ?></th>
								<th><?php esc_html_e( 'Category', 'daw' ); ?></th>
								<th><?php esc_html_e( 'Recipient', 'daw' ); ?></th>
								<th><?php esc_html_e( 'Result', 'daw' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $awards as $a ) : ?>
								<?php $won = stripos( $a['result'], 'won' ) !== false || stripos( $a['result'], 'winner' ) !== false; ?>
								<tr>
									<td class="is-num"><?php echo esc_html( $a['year'] ); ?></td>
									<td><?php echo esc_html( $a['body'] ); ?></td>
									<td><?php echo esc_html( $a['category'] ); ?></td>
									<td><?php echo esc_html( isset( $a['who'] ) ? $a['who'] : '' ); ?></td>
									<td class="<?php echo $won ? 'daw-result-won' : 'daw-result-nom'; ?>">
										<?php echo esc_html( $a['result'] ); ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>
		<?php endif; ?>

		<?php // =============================================== gallery ==== ?>
		<?php if ( $gallery ) : ?>
			<section class="daw-section" id="gallery">
				<h2><?php esc_html_e( 'Gallery', 'daw' ); ?></h2>
				<div class="daw-grid">
					<?php foreach ( $gallery as $img_id ) : ?>
						<div class="daw-poster">
							<?php echo wp_get_attachment_image( (int) $img_id, 'medium', false, array( 'loading' => 'lazy', 'decoding' => 'async' ) ); ?>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php // ============================================== affiliate === ?>
		<?php
		// Printed only when there is something to sell. An empty "coming soon"
		// box told every reader about a programme that is not running (rule 2.2).
		$daw_shop = (string) apply_filters( 'daw_title_shop_html', '', $id );
		if ( '' !== trim( $daw_shop ) ) :
			?>
			<section class="daw-section">
				<div class="daw-aff">
					<h3><?php
					/* translators: %s: the kind of title, e.g. film, drama, show */
					printf( esc_html__( 'Shop this %s', 'daw' ), esc_html( daw_kind_noun( $type ) ) );
					?></h3>
					<?php echo wp_kses_post( $daw_shop ); ?>
				</div>
			</section>
		<?php endif; ?>

		<?php // ========================================== faq + news ==== ?>
		<?php
		// TWO HALF-EMPTY SECTIONS MAKE ONE FULL BAND
		// ------------------------------------------
		// The questions are a reading column and never wanted the full page;
		// the related news was usually one card stranded across the whole width
		// under a rule. Side by side they fill each other's empty half: the
		// questions keep the content column, the stories take the rail they
		// already have on a person page, with their pictures, which is also
		// where a reader looks for "what else is happening with this".
		$news = function_exists( 'daw_get_related_news' ) ? daw_get_related_news( $id, 4 ) : array();

		if ( $faq ) :
			?>
			<div class="daw-cols daw-section">
				<div class="daw-maincol">
					<section id="faq">
						<h2><?php esc_html_e( 'Frequently asked questions', 'daw' ); ?></h2>
						<?php foreach ( $faq as $row ) : ?>
							<details class="daw-acc">
								<summary><h3 style="font-size:1rem;margin:0"><?php echo esc_html( $row['q'] ); ?></h3></summary>
								<div class="daw-acc-body">
									<div class="daw-prose"><?php echo wpautop( esc_html( $row['a'] ) ); ?></div>
								</div>
							</details>
						<?php endforeach; ?>
					</section>
				</div>

				<aside class="daw-rail">
					<?php if ( $news ) : ?>
						<section id="related-news">
							<h2 class="daw-h3"><?php esc_html_e( 'Related news', 'daw' ); ?></h2>
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
		<?php elseif ( $news ) : ?>
			<?php
			// NO QUESTIONS ON THIS RECORD, SO THE STORIES STAND ALONE
			// -------------------------------------------------------
			// They do not get the whole page to do it. One card stretched to
			// 1148px under a full-width rule was the thing that looked broken:
			// a single story pretending to be a section. Here it is the same
			// list of rows as in the rail, at the width of the content column,
			// so one story reads as one story and four read as a list.
			?>
			<section class="daw-section daw-readcol" id="related-news">
				<h2><?php esc_html_e( 'Related news', 'daw' ); ?></h2>
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

		<?php // ====================================== related titles == ?>
		<?php
		// SAME STORY IS NOT THE SAME AS SIMILAR
		// -------------------------------------
		// The block below this one is a genre match: a guess that a reader who
		// liked this might like that. This one is a fact. Season two, the film
		// the drama was made from, the anime it was adapted into — a reader who
		// finishes season one is asking a question with a yes-or-no answer, and
		// leaving them to infer it from a rail of thumbnails is the page
		// refusing to answer. The relation is typed by an editor on both
		// records, so each page states it in the direction its own reader needs.
		$related = function_exists( 'daw_get_related_titles' ) ? daw_get_related_titles( $id ) : array();
		if ( $related ) :
			?>
			<section class="daw-section" id="related-titles">
				<h2><?php esc_html_e( 'Part of the same story', 'daw' ); ?></h2>
				<div class="daw-rail-row">
					<?php foreach ( $related as $rel ) : ?>
						<a class="daw-person" href="<?php echo esc_url( $rel['url'] ); ?>">
							<div class="daw-poster"><?php echo daw_poster( $rel['id'], 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
							<?php if ( $rel['kind'] ) : ?>
								<div class="daw-relkind"><?php echo esc_html( $rel['kind'] ); ?></div>
							<?php endif; ?>
							<div class="daw-person-name"><?php echo esc_html( $rel['title'] ); ?></div>
						</a>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php // =========================================== similar titles == ?>
		<?php
		// The last thing on the page is the question the reader now has, which
		// is not "what was this" but "what next". Everything above this line
		// links outward; this links back into the database.
		$similar = function_exists( 'daw_get_similar' ) ? daw_get_similar( $id, 6 ) : array();
		if ( $similar ) :
			?>
			<section class="daw-section" id="similar">
				<h2><?php
				/* translators: %s: the kind of title, e.g. film, drama, show */
				printf( esc_html__( 'If you liked this %s', 'daw' ), esc_html( daw_kind_noun( $type ) ) );
				?></h2>
				<p class="daw-sectionnote">
					<?php
					// Only what the match really used (1.75.74): a rail that had to
					// leave the country does not claim the country.
					if ( empty( $similar[0]['country'] ) ) {
						esc_html_e( 'Matched on genre and era. Closest first.', 'daw' );
					} else {
						esc_html_e( 'Matched on genre, country and era. Closest first.', 'daw' );
					}
					?>
				</p>
				<div class="daw-rail-row">
					<?php
					foreach ( $similar as $sim ) :
						$sid  = $sim['id'];
						$syr  = (int) daw_field( 'daw_aired_start', $sid );
						$sgen = daw_terms( 'dw_genre', $sid );
						?>
						<a class="daw-person" href="<?php echo esc_url( get_permalink( $sid ) ); ?>">
							<div class="daw-poster"><?php echo daw_poster( $sid, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
							<div class="daw-person-name"><?php echo esc_html( get_the_title( $sid ) ); ?></div>
							<div class="daw-person-role">
								<?php
								echo esc_html(
									implode(
										' · ',
										array_filter(
											array(
												$syr ? gmdate( 'Y', $syr ) : '',
												$sgen ? explode( ', ', $sgen )[0] : '',
											)
										)
									)
								);
								?>
							</div>
						</a>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<footer class="daw-attrib">
			<p><?php esc_html_e( 'The poster is shown through the TMDB API and is used only to identify the work. If you hold rights to an image used here and want it removed, write to info@discoverasianworld.com and it will be taken down.', 'daw' ); ?></p>
		</footer>

	</div>

<?php daw_record_scripts(); ?>

	<?php
endwhile;

get_footer();
