<?php
/**
 * A country hub.
 *
 * WHAT CHANGED AND WHAT DID NOT
 * -----------------------------
 * The url did not. /south-korea-korean-dramas-movies-culture/ is the Korea page
 * and stays the Korea page; WordPress resolves it exactly as it did yesterday
 * and only this file draws it now. What went is the body: a quarter of a
 * megabyte of hand-placed cards that were correct on the day somebody typed
 * them and have been drifting ever since. Every number and every card below is
 * counted at render time, so a record filed under Korea this afternoon is on
 * this page this afternoon.
 *
 * WHAT IT IS NOT
 * --------------
 * It is not the drama list. The strips here are the first handful and a link to
 * the list that already exists at its own url. A hub that reprinted all 423
 * dramas would be /korean-drama/ under a second address, and two pages
 * answering one query is a fight we lose to ourselves.
 *
 * EVERY BLOCK CHECKS FIRST
 * ------------------------
 * A country with three records shows three records. It does not show an empty
 * "Cinema" heading over nothing, and it does not print a filter that would
 * return no rows. That matters here more than anywhere: seven of the eight
 * country hubs have almost nothing filed under them yet, and the page has to
 * look deliberate rather than broken while that is fixed.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();

$hub = function_exists( 'daw_hub_for_page' ) ? daw_hub_for_page() : null;

if ( ! $hub ) {
	get_footer();
	return;
}

$country   = $hub['country'];
$name      = $hub['name'];
$adjective = $hub['adjective'];
$short     = $hub['short'] ?? $hub['name'];

$counts = daw_hub_counts( $country );

/** The page for a record type, or '' when it does not exist yet. */
$hub_page = static function ( $type ) use ( $hub ) {
	return isset( $hub['sections'][ $type ] ) ? daw_page_url( $hub['sections'][ $type ] ) : '';
};

$guide_types  = function_exists( 'daw_guide_types' ) ? daw_guide_types() : array( 'food', 'culture', 'myth', 'travel' );
$guide_total  = 0;
foreach ( $guide_types as $gt ) {
	$guide_total += (int) ( $counts[ $gt ] ?? 0 );
}

/* ------------------------------------------------------------- the strip -- */
// Five numbers at most: past that it stops being a summary and becomes a table
// nobody reads. A type with no records is not printed as a zero, because a zero
// under a label is an advertisement for a gap.
$stats = array();

// One of anything is singular. "1 Dramas" under a number is a small thing that
// reads as carelessness, and a hub that counts carefully everywhere else cannot
// afford it.
foreach ( array(
	'drama'  => array( __( 'Dramas', 'daw' ), __( 'Drama', 'daw' ) ),
	'film'   => array( __( 'Films', 'daw' ), __( 'Film', 'daw' ) ),
	'person' => array( __( 'People', 'daw' ), __( 'Person', 'daw' ) ),
	'group'  => array( __( 'K-pop groups', 'daw' ), __( 'K-pop group', 'daw' ) ),
) as $type => $label ) {
	if ( ! empty( $counts[ $type ] ) ) {
		$stats[] = array(
			'v'   => number_format_i18n( $counts[ $type ] ),
			'l'   => 1 === (int) $counts[ $type ] ? $label[1] : $label[0],
			'url' => $hub_page( $type ),
		);
	}
}

if ( $guide_total ) {
	$stats[] = array(
		'v'   => number_format_i18n( $guide_total ),
		'l'   => 1 === (int) $guide_total ? __( 'Guide', 'daw' ) : __( 'Guides', 'daw' ),
		'url' => '',
	);
}

/* --------------------------------------------------------------- the nav -- */
// Built from the same table as the sections, so a kind of record added to the
// site appears in the bar and on the page together and can never be in one
// without the other. People and news sit outside it: they are the rail, not a
// section, but they still have pages of their own worth linking to.
$nav = array();

$nav_labels = array(
	'drama'   => __( 'Dramas', 'daw' ),
	'film'    => __( 'Films', 'daw' ),
	'person'  => __( 'People', 'daw' ),
	'group'   => __( 'K-pop', 'daw' ),
	'variety' => __( 'Variety', 'daw' ),
	'anime'   => __( 'Animation', 'daw' ),
	'manga'   => __( 'Comics', 'daw' ),
	'food'    => __( 'Food & drink', 'daw' ),
	'culture' => __( 'Culture', 'daw' ),
	'myth'    => __( 'Myths', 'daw' ),
	'travel'  => __( 'Travel', 'daw' ),
	'quiz'    => __( 'Quizzes', 'daw' ),
	'news'    => __( 'News', 'daw' ),
);

foreach ( $nav_labels as $type => $label ) {
	$url = $hub_page( $type );

	if ( $url && ! empty( $counts[ $type ] ) ) {
		$nav[ $url ] = $label;
	}
}

?>

<div class="daw-wrap daw-hub-page">
	<nav class="daw-crumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'daw' ); ?>">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'daw' ); ?></a>
		<span aria-hidden="true">›</span><span><?php esc_html_e( 'Countries', 'daw' ); ?></span>
		<span aria-hidden="true">›</span><span aria-current="page"><?php echo esc_html( $name ); ?></span>
	</nav>
</div>

<?php
// THE HEAD USES THE WHOLE WIDTH, AND ONLY THE HEIGHT IT NEEDS
// ----------------------------------------------------------
// It used to be a stack: title, then a paragraph at reading width, then a row
// of counts, all down the left, with the right half of a 1440px screen empty
// and the first poster pushed under the fold. The paragraph still keeps a
// reading measure, because a line of eighty words across a whole screen is not
// read by anybody. What changes is that the counts move beside it rather than
// under it, which is the one thing here that is better wide than tall.
?>
<header class="daw-phero daw-hubtop" id="overview">
	<div class="daw-wrap">
		<div class="daw-hubtop-in">
			<h1 class="daw-hubtop-h">
				<?php
				// The flag before the name. On a page whose whole job is to say
				// which country this is, it is the fastest way to say it.
				if ( function_exists( 'daw_country_flag' ) ) {
					echo daw_country_flag( $country, 28 ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
				<span><?php echo esc_html( $name ); ?></span>
			</h1>

			<div class="daw-hubtop-say">
				<?php if ( has_excerpt() ) : ?>
					<p class="daw-phero-sum"><?php echo esc_html( get_the_excerpt() ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( $stats ) : ?>
				<div class="daw-pstats daw-hubtop-count">
					<?php foreach ( array_slice( $stats, 0, 5 ) as $s ) : ?>
						<div class="daw-pstat">
							<div class="daw-pstat-v">
								<?php if ( $s['url'] ) : ?>
									<a href="<?php echo esc_url( $s['url'] ); ?>"><?php echo esc_html( $s['v'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $s['v'] ); ?>
								<?php endif; ?>
							</div>
							<div class="daw-pstat-l"><?php echo esc_html( $s['l'] ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</header>

<div class="daw-wrap daw-hub-page">

	<?php
	// The jump bar on a record page moves inside one page. Here every item is a
	// different page, so these are links out, not anchors, and the bar is
	// labelled as navigation rather than as a table of contents.
	if ( $nav ) :
		?>
		<nav class="daw-stickynav" aria-label="<?php esc_attr_e( 'Sections', 'daw' ); ?>">
			<ul>
				<?php foreach ( $nav as $url => $label ) : ?>
					<li><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
	<?php endif; ?>

	<?php
	/* ------------------------------------------------------------ dramas -- */
	$dramas = daw_hub_records( $country, 'drama', 6 );

	if ( $dramas ) :
		daw_hub_head(
			/* translators: %s: nationality adjective, e.g. Korean */
			sprintf( __( 'Recent %s dramas', 'daw' ), $adjective ),
			__( 'The newest titles in the archive, by air date.', 'daw' ),
			$hub_page( 'drama' ),
			1 === (int) $counts['drama']
				/* translators: %s: nationality adjective, e.g. Korean */
				? sprintf( __( 'The one %s drama', 'daw' ), $adjective )
				/* translators: 1: count, 2: nationality adjective */
				: sprintf( __( 'All %1$s %2$s dramas', 'daw' ), number_format_i18n( $counts['drama'] ), $adjective ),
			'daw-section'
		);
		?>
		<div class="daw-section daw-section-tight">
			<div class="daw-grid daw-hubgrid">
				<?php foreach ( $dramas as $tid ) { daw_hub_title_card( $tid ); } ?>
			</div>
		</div>
	<?php endif; ?>

	<?php daw_ad_slot( 'daw-ad-leaderboard' ); ?>

	<?php
	/* ------------------------------------------------ the archive, two up -- */
	// The reading column runs cinema, then the four guide subjects in the order
	// a reader meets them: what is on screen, then what is on the table, then
	// what it means, then the old stories, then where to go. The rail holds the
	// three things that belong beside all of it, and nothing that belongs after.
	$films    = daw_hub_records( $country, 'film', 4 );
	$people   = daw_hub_people( $country, 4 );
	$genres   = daw_hub_terms( $country, 'dw_genre', 10 );
	$networks = daw_hub_terms( $country, 'dw_network', 10 );
	$decades  = daw_hub_decades( $country, 8 );
	$certs    = function_exists( 'daw_hub_ratings' ) ? daw_hub_ratings( $country, 8 ) : array();
	?>
	<div class="daw-hubcols">
		<div class="daw-hubmain">

			<?php
			/* --------------------------------------------- every section -- */
			// EIGHT SECTIONS OR TWO, DRAWN THE SAME WAY
			// -----------------------------------------
			// The order, the wording and the card shape all come from one table
			// in the plugin, so adding manhwa or animation to every country hub
			// on the site is a row there rather than another block here. A type
			// with no records for this country prints nothing: no heading, no
			// empty row, no "0 guides" link. That is what lets the same
			// template draw Korea, which has nine sections, and Taiwan, which
			// has two, without either one looking unfinished.
			foreach ( daw_hub_sections() as $stype => $sec ) {
				if ( empty( $counts[ $stype ] ) ) {
					continue;
				}

				$rows = daw_hub_section_records( $country, $stype, $sec['style'], $sec['n'] );

				if ( ! $rows ) {
					continue;
				}

				$subject = 'short' === $sec['subject'] ? $short : $adjective;

				daw_hub_head(
					sprintf( $sec['title'], $subject ),
					$sec['note'],
					$hub_page( $stype ),
					sprintf(
						1 === (int) $counts[ $stype ] && ! empty( $sec['all_one'] ) ? $sec['all_one'] : $sec['all'],
						number_format_i18n( $counts[ $stype ] ),
						$subject
					)
				);

				printf(
					'<div class="daw-section daw-section-tight"><div class="%s">',
					'poster' === $sec['style'] ? 'daw-grid daw-hubgrid' : 'daw-hubguides'
				);

				foreach ( $rows as $rid ) {
					if ( 'poster' === $sec['style'] ) {
						daw_hub_title_card( $rid, 'film' === $stype ? 'genre' : ( 'group' === $stype ? 'kind' : 'network' ) );
					} else {
						daw_hub_guide_card( $rid );
					}
				}

				echo '</div></div>';
			}
			?>

		</div>

		<aside class="daw-hubrail">
			<?php if ( $people ) : ?>
				<div class="daw-rcard">
					<h2 class="daw-rcard-h">
						<?php
						/* translators: %s: short country name, e.g. Korea */
						printf( esc_html__( 'People from %s', 'daw' ), esc_html( $short ) );
						?>
					</h2>

					<ul class="daw-hubppl">
						<?php
						foreach ( $people as $prow ) :
							$pid = $prow['id'];
							$job = daw_field( 'daw_occupation', $pid );
							$job = $job ? wp_specialchars_decode( (string) $job, ENT_QUOTES ) : daw_type_label( daw_get_type( $pid ) );
							?>
							<li>
								<a href="<?php echo esc_url( get_permalink( $pid ) ); ?>">
									<span class="daw-hubppl-face"><?php echo daw_poster( $pid, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
									<span class="daw-hubppl-txt">
										<span class="daw-person-name"><?php echo esc_html( get_the_title( $pid ) ); ?></span>
										<span class="daw-person-role">
											<?php
											// A profile with nothing indexed yet says what it is and
											// stops. "0 credits" reads as a broken record rather than
											// as a new one.
											if ( $prow['credits'] ) {
												// A new country's people hold one credit each, so the
												// plural has to be counted rather than assumed. "1
												// credits" is the kind of thing a reader reads as
												// carelessness about everything else on the page.
												printf(
													/* translators: 1: occupation, 2: number of screen credits */
													esc_html( _n( '%1$s · %2$s credit', '%1$s · %2$s credits', (int) $prow['credits'], 'daw' ) ),
													esc_html( $job ),
													esc_html( number_format_i18n( $prow['credits'] ) )
												);
											} else {
												echo esc_html( $job );
											}
											?>
										</span>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php if ( $hub_page( 'person' ) ) : ?>
						<a class="daw-hubmore daw-hubmore-b" href="<?php echo esc_url( $hub_page( 'person' ) ); ?>">
							<?php
							if ( 1 === (int) $counts['person'] ) {
								esc_html_e( 'The one person', 'daw' );
							} else {
								/* translators: %s: count */
								printf( esc_html__( 'All %s people', 'daw' ), esc_html( number_format_i18n( $counts['person'] ) ) );
							}
							?>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>


			<?php
			/* ------------------------------------------------------ facets -- */
			// THE FILTERS ARE NOT LINKS YET
			// -----------------------------
			// dw_genre and dw_network are registered public => false, so a term
			// has no page to point at. A pill that goes nowhere is worse than
			// no pill, so these are counts: they tell a reader what the archive
			// is made of, which is true and useful, and each becomes a link the
			// day the list page behind it exists.
			// THE FACETS ARE LINKS NOW
			// ------------------------
			// They were counts, because dw_genre and dw_network register with
			// public => false and a term has no page of its own. That has not
			// changed, and it no longer matters: the country's own drama page
			// reads the same filters from the query string, so "Romance 234"
			// can point at 234 Korean romance dramas with a heading that says
			// so. A number a reader cannot act on is a fact; a number they can
			// open is a way in.
			//
			// Certificate came off the list. Nobody has ever gone looking for a
			// drama by its age rating, and a rail that opens with Genre,
			// Network and Decade is three useful doors instead of two and a
			// filing cabinet.
			$facet_slug = (string) ( $hub['sections']['drama'] ?? '' );
			$facet_base = $facet_slug ? daw_page_url( $facet_slug ) : '';

			if ( $genres || $networks || $decades ) :
				?>
				<div class="daw-rcard">
					<h2 class="daw-rcard-h">
						<?php
						// "WHAT THE SOUTH KOREA ARCHIVE HOLDS" set in spaced capitals
						// wrapped to three lines in a 328px rail and read as a
						// banner rather than as a heading. Two words do the same
						// job and fit on one.
						esc_html_e( 'In the archive', 'daw' );
						?>
					</h2>

					<div class="daw-hubfacets">
						<?php
						foreach ( array(
							array( __( 'Genre', 'daw' ), $genres, 'genre' ),
							array( __( 'Network & platform', 'daw' ), $networks, 'network' ),
							array( __( 'Decade', 'daw' ), $decades, 'decade' ),
						) as $facet ) :
							list( $flabel, $frows, $fkey ) = $facet;

							if ( ! $frows ) {
								continue;
							}
							?>
							<div class="daw-hubfacet">
								<h3><?php echo esc_html( $flabel ); ?></h3>
								<ul>
									<?php
									foreach ( $frows as $frow ) :
										$furl = ( $facet_base && ! empty( $frow['slug'] ) && function_exists( 'daw_browse_state_url' ) )
											? daw_browse_state_url( $facet_slug, array( $fkey => (string) $frow['slug'] ) )
											: '';
										?>
										<li>
											<?php if ( $furl ) : ?>
												<a href="<?php echo esc_url( $furl ); ?>">
													<span class="daw-facet-name"><?php echo esc_html( $frow['name'] ); ?></span>
													<span class="daw-facet-n"><?php echo esc_html( number_format_i18n( $frow['n'] ) ); ?></span>
												</a>
											<?php else : ?>
												<span class="daw-facet-name"><?php echo esc_html( $frow['name'] ); ?></span>
												<span class="daw-facet-n"><?php echo esc_html( number_format_i18n( $frow['n'] ) ); ?></span>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php
			// THE STICKY ADVERT GOES LAST, AND ONLY LAST
			// ------------------------------------------
			// It used to sit between the people card and the archive card. A
			// sticky element stays in the flow but is painted where the scroll
			// puts it, so on the way down it slid straight over the card under
			// it and covered the archive panel. Sticky only works at the end of
			// a column, where there is nothing left for it to cover. The rail
			// is long enough that an advert at the foot of it still follows the
			// reader for most of the page, which was the point of making it
			// stick in the first place.
			daw_ad_slot( 'daw-ad-rail' );
			?>
		</aside>
	</div>

	<?php
	/* --------------------------------------------- the editor's words, wide -- */
	// THE PAGE BODY IS NOT PRINTED HERE
	// ---------------------------------
	// It is the thing being replaced. The first version of this template printed
	// it under "Country guide", so a reader got the new page and then the whole
	// old one underneath. The body stays in the database, which is what makes
	// the switch reversible, and the hub reads its own field instead.
	//
	// This is the one block that runs the full width of the page: it is
	// continuous prose rather than a list of records, and it is where somebody
	// who has scrolled past every strip finds out how the site fits together.
	$intro = trim( (string) get_post_meta( get_the_ID(), 'daw_hub_intro', true ) );

	if ( '' !== $intro ) :
		?>
		<section class="daw-section">
			<div class="daw-hubguide-card">
				<p class="daw-eyebrow">
					<?php
					/* translators: %s: country name */
					printf( esc_html__( '%s: where to start', 'daw' ), esc_html( $name ) );
					?>
				</p>
				<div class="daw-prose daw-hubprose daw-hubprose-wide">
					<?php
					// {dramas}, {films}, {people}, {guides} and the rest are filled
					// from the same counts as the strip at the top, so the prose and
					// the numbers above it can never drift apart.
					echo wp_kses_post( wpautop( daw_hub_fill_counts( $intro, $country, $hub ) ) );
					?>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<?php
	/* -------------------------------------------------------- faq and news -- */
	// Every answer here describes something this site actually does, so it is
	// the same on every hub bar the country's name. Nothing is promised that a
	// reader cannot then go and check.
	$faq = array(
		array(
			/* translators: %s: nationality adjective */
			sprintf( __( 'Where can I watch %s dramas legally?', 'daw' ), $adjective ),
			__( 'Title pages list the official platforms we know of and, where TMDB has JustWatch data, which services carry the show in your country. This site does not stream anything and does not link to unlicensed copies.', 'daw' ),
		),
		array(
			__( 'Where do the scores come from?', 'daw' ),
			__( 'Readers. A score appears on a title only once people have voted on it, and the number of votes is printed beside it, so a 9 from four people never reads as a 9 from four hundred.', 'daw' ),
		),
		array(
			__( 'Where do the posters and the data come from?', 'daw' ),
			__( 'Posters and portraits are shown through the TMDB API and belong to the studios, broadcasters, distributors and agencies behind each work or person. Facts on the page come from the sources named in each record. This product uses the TMDB API but is not endorsed or certified by TMDB.', 'daw' ),
		),
		array(
			__( 'What if something here is wrong or missing?', 'daw' ),
			__( 'Use the "Request a correction" link on the page and an editor reviews it. A field nobody can source is left empty rather than guessed at.', 'daw' ),
		),
	);

	// Three. Five made the rail taller than the questions beside it, and the
	// fourth and fifth were never the reason anybody came to a country page.
	$news = daw_hub_articles( $country, 'news', 3 );
	?>
	<div class="daw-hubcols">
		<div class="daw-hubmain">
			<?php daw_hub_head( __( 'Frequently asked questions', 'daw' ) ); ?>
			<div class="daw-section daw-section-tight">
				<div class="daw-hubfaq">
					<?php foreach ( $faq as $row ) : ?>
						<details>
							<summary><?php echo esc_html( $row[0] ); ?></summary>
							<p><?php echo esc_html( $row[1] ); ?></p>
						</details>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<aside class="daw-hubrail">
			<?php if ( $news ) : ?>
				<div class="daw-rcard">
					<h2 class="daw-rcard-h"><?php esc_html_e( 'Latest news', 'daw' ); ?></h2>

					<ul class="daw-hubnews">
						<?php foreach ( $news as $nid ) : ?>
							<li>
								<a href="<?php echo esc_url( get_permalink( $nid ) ); ?>">
									<span class="daw-hubnews-img"><?php echo daw_poster( $nid, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
									<span class="daw-hubnews-txt">
										<span class="daw-hubnews-t"><?php echo esc_html( get_the_title( $nid ) ); ?></span>
										<time class="daw-hubnews-d" datetime="<?php echo esc_attr( get_the_date( 'c', $nid ) ); ?>">
											<?php echo esc_html( get_the_date( 'j M Y', $nid ) ); ?>
										</time>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php if ( $hub_page( 'news' ) ) : ?>
						<a class="daw-hubmore daw-hubmore-b" href="<?php echo esc_url( $hub_page( 'news' ) ); ?>">
							<?php esc_html_e( 'All news', 'daw' ); ?>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</aside>
	</div>

</div>

<?php
get_footer();
