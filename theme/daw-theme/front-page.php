<?php
/**
 * The home page.
 *
 * WHAT A HOME PAGE IS FOR HERE
 * ----------------------------
 * Almost nobody arrives here first. They arrive on a record from a search
 * engine, and they come here afterwards to find out what else is in the place.
 * So this page answers one question, "what is here", and answers it with the
 * archive's own shape: the countries, the newest of each kind, and the writing
 * around them. It is a way in, not a magazine cover.
 *
 * EVERY NUMBER ON IT IS COUNTED
 * -----------------------------
 * The country cards, the section counts and the lists are all read at render
 * time from the same queries the browse pages use. Nothing is typed in, so
 * nothing on this page can be out of date while the rest of the site is right.
 *
 * AND NOTHING ON IT IS INVENTED
 * -----------------------------
 * There is no "most popular" and no "trending" unless something real is
 * counting. The search box keeps a tally of what people type, and when that
 * tally has enough in it the most searched section appears with the sentence
 * that says where the number came from. Until then the same slot says
 * "recently added", which is true today.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();

$counts_of = static function ( $country ) {
	return function_exists( 'daw_hub_counts' ) ? daw_hub_counts( $country ) : array();
};

$hubs = function_exists( 'daw_country_hubs' ) ? daw_country_hubs() : array();
?>

<?php
// THE HOME PAGE NAMES ITSELF
// --------------------------
// Its only h1 used to be the featured record's title, so the front door of the
// site told a search engine it was a page about "Pinocchio", and on a week with
// nothing featured it had no h1 at all. The h1 now says what the site is. It is
// visually hidden because the header and the hero already say it on screen.
?>
<h1 class="daw-vh"><?php esc_html_e( 'Discover Asian World: Asian dramas, films, actors and K-pop in one database', 'daw' ); ?></h1>

<?php
/* ------------------------------------------------------------- the hero -- */

// The one title the site is pointing at this week. It is an editorial choice,
// so it is stored as one rather than guessed: an empty setting simply means the
// strip below starts the page instead, which is a fine home page too.
$featured = function_exists( 'daw_featured_record' ) ? daw_featured_record() : 0;

if ( $featured ) :
	$f_type    = daw_get_type( $featured );
	$f_country = daw_terms( 'dw_country', $featured );
	$f_start   = (int) daw_field_raw( $featured, 'daw_aired_start' );
	$f_net     = daw_first_term_name( $featured, 'dw_network' );
	$f_eps     = (int) daw_field_raw( $featured, 'daw_episodes' );
	$f_genre   = daw_terms( 'dw_genre', $featured );
	$f_score   = function_exists( 'daw_title_rating_value' ) ? daw_title_rating_value( $featured ) : 0;
	$f_sum_raw = daw_field_raw( $featured, 'daw_synopsis' );
	// Defensive: see the note on daw_prose() in functions.php, 2026-10-01 —
	// a (string) cast on an array prints the literal word "Array".
	if ( is_array( $f_sum_raw ) ) {
		$f_sum_raw = implode( ' ', array_filter( array_map( 'strval', $f_sum_raw ) ) );
	}
	$f_sum     = trim( wp_strip_all_tags( (string) $f_sum_raw ) );

	$f_facts = array_filter(
		array(
			$f_start ? gmdate( 'Y', $f_start ) : '',
			$f_net,
			/* translators: %s: number of episodes */
			$f_eps ? sprintf( _n( '%s episode', '%s episodes', $f_eps, 'daw' ), number_format_i18n( $f_eps ) ) : '',
			$f_genre,
		)
	);
	?>
	<header class="daw-hhero">
		<div class="daw-wrap daw-hhero-in">
			<div class="daw-hhero-txt">
				<p class="daw-hhero-eyebrow">
					<?php
					$f_eyebrow = function_exists( 'daw_featured_eyebrow' )
						? daw_featured_eyebrow( $featured )
						: __( 'Featured', 'daw' );

					// On air, the channel says more than the country: "Episode 7
					// today · tvN" (1.75.78). Otherwise the country, as before.
					$f_live = function_exists( 'daw_onair_eyebrow' ) && '' !== daw_onair_eyebrow( $featured, '' );
					echo esc_html(
						implode(
							' · ',
							array_filter( array( $f_eyebrow, ( $f_live && $f_net ) ? $f_net : $f_country ) )
						)
					);
					?>
				</p>

				<?php // An h2 drawn at h1 size: the page's h1 is the site, printed below. ?>
				<h2 class="daw-hhero-t"><?php echo esc_html( get_the_title( $featured ) ); ?></h2>

				<?php if ( $f_sum ) : ?>
					<p class="daw-hhero-s"><?php echo esc_html( wp_trim_words( $f_sum, 34, '…' ) ); ?></p>
				<?php endif; ?>

				<?php if ( $f_facts ) : ?>
					<p class="daw-hhero-m">
						<?php echo esc_html( implode( ' · ', $f_facts ) ); ?>
						<?php if ( $f_score ) : ?>
							<span class="daw-hhero-star">★ <?php echo esc_html( number_format_i18n( $f_score, 1 ) ); ?></span>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<p class="daw-hhero-do">
					<a class="daw-btn daw-btn-p" href="<?php echo esc_url( get_permalink( $featured ) ); ?>">
						<?php echo esc_html( 'person' === $f_type ? __( 'Open the profile', 'daw' ) : __( 'Open the title', 'daw' ) ); ?>
					</a>
					<?php
					$all = function_exists( 'daw_browse_page_url_for_type' ) ? daw_browse_page_url_for_type( $f_type ) : '';

					if ( $all ) :
						?>
						<a class="daw-btn" href="<?php echo esc_url( $all ); ?>"><?php esc_html_e( 'See everything like it', 'daw' ); ?></a>
					<?php endif; ?>
				</p>
			</div>

			<a class="daw-hhero-art" href="<?php echo esc_url( get_permalink( $featured ) ); ?>" aria-hidden="true" tabindex="-1">
				<?php echo daw_poster( $featured, 'large' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</a>
		</div>
	</header>
<?php endif; ?>

<div class="daw-wrap daw-home">

	<?php
	/* ------------------------------------------------------ the quick row -- */

	// Ten ways in, each one a real filtered page. A term the archive does not
	// hold is not offered, so this row is never a list of empty rooms.
	$quick = array_filter(
		array(
			daw_page_url( 'dramas' )                                              => __( 'All dramas', 'daw' ),
			daw_browse_state_url( 'dramas', array( 'status' => 'ongoing' ) )      => __( 'On air now', 'daw' ),
			daw_browse_state_url( 'dramas', array( 'status' => 'upcoming' ) )     => __( 'Upcoming', 'daw' ),
			daw_browse_state_url( 'dramas', array( 'genre' => 'romance' ) )       => __( 'Romance', 'daw' ),
			daw_browse_state_url( 'dramas', array( 'genre' => 'comedy' ) )        => __( 'Comedy', 'daw' ),
			daw_browse_state_url( 'dramas', array( 'genre' => 'thriller' ) )      => __( 'Thriller', 'daw' ),
			daw_browse_state_url( 'dramas', array( 'genre' => 'historical' ) )    => __( 'Historical', 'daw' ),
			daw_browse_state_url( 'dramas', array( 'network' => 'netflix' ) )     => __( 'Netflix', 'daw' ),
			daw_browse_state_url( 'dramas', array( 'network' => 'tvn' ) )         => __( 'tvN', 'daw' ),
			daw_page_url( 'airing-calendar' )                                     => __( 'Airing calendar', 'daw' ),
		),
		static function ( $label, $url ) {
			return '' !== $url && '' !== $label;
		},
		ARRAY_FILTER_USE_BOTH
	);

	if ( $quick ) :
		?>
		<nav class="daw-quick" aria-label="<?php esc_attr_e( 'Quick ways in', 'daw' ); ?>">
			<?php foreach ( $quick as $url => $label ) : ?>
				<a class="daw-quick-o" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<?php
	/* ------------------------------------------------------- on air today -- */

	// Every title on air as a plain link, today's episodes first (1.75.78,
	// daw-core home-onair.php). The list changes every day, which is the
	// home page's best reason for a crawler to come back.
	$onair = function_exists( 'daw_onair_home' ) ? daw_onair_home() : array( 'today' => array(), 'onair' => array() );
	$onair_ids = array_merge( wp_list_pluck( $onair['today'], 'id' ), $onair['onair'] );
	// A poster-less card goes to the end of the rail (1.75.80).
	if ( function_exists( 'daw_pictured_first' ) ) {
		$onair_ids = daw_pictured_first( $onair_ids );
	}
	if ( $onair_ids ) :
		daw_hub_head(
			$onair['today'] ? __( 'On air today', 'daw' ) : __( 'On air now', 'daw' ),
			$onair['today'] ? __( 'New episodes today first, then everything else airing now.', 'daw' ) : __( 'Everything airing now, most read first.', 'daw' ),
			daw_page_url( 'airing-calendar' ),
			__( 'Airing calendar', 'daw' )
		);
		$today_set = array_flip( array_map( 'intval', wp_list_pluck( $onair['today'], 'id' ) ) );
		// A rail of the site's own poster cards, one row on every screen,
		// swiped on a phone and stepped with two buttons on a desktop
		// (1.75.79, owner: "diğerlerine bak, şık yerleştir"). Not a hero
		// slider: every card is in the HTML, so every one is a link a crawler
		// follows, and nothing moves when the page loads.
		?>
		<div class="daw-onair daw-section daw-section-tight">
			<button type="button" class="daw-onair-btn is-prev" aria-label="<?php esc_attr_e( 'Scroll back', 'daw' ); ?>" hidden>&#8249;</button>
			<div class="daw-rail-row daw-onair-rail" id="daw-onair-rail">
				<?php
				foreach ( $onair_ids as $oid ) {
					$orow   = isset( $today_set[ (int) $oid ] ) ? daw_onair_today_row( $oid ) : null;
					/* translators: %s: episode number */
					$obadge = $orow ? ( '' !== $orow['ep'] ? sprintf( __( 'Ep %s today', 'daw' ), $orow['ep'] ) : __( 'New today', 'daw' ) ) : '';
					daw_hub_title_card( $oid, 'network', $obadge );
				}
				?>
			</div>
			<button type="button" class="daw-onair-btn is-next" aria-label="<?php esc_attr_e( 'Scroll forward', 'daw' ); ?>" hidden>&#8250;</button>
		</div>
		<script>
		(function () {
			var r = document.getElementById('daw-onair-rail');
			if (!r) { return; }
			var w = r.parentNode, p = w.querySelector('.is-prev'), n = w.querySelector('.is-next');
			function sync() {
				var over = r.scrollWidth > r.clientWidth + 4;
				p.hidden = !over || r.scrollLeft < 8;
				n.hidden = !over || r.scrollLeft + r.clientWidth > r.scrollWidth - 8;
			}
			function step(d) { r.scrollBy({ left: d * r.clientWidth * 0.8, behavior: 'smooth' }); }
			p.addEventListener('click', function () { step(-1); });
			n.addEventListener('click', function () { step(1); });
			r.addEventListener('scroll', sync, { passive: true });
			window.addEventListener('resize', sync);
			sync();
		})();
		</script>
	<?php endif; ?>

	<?php
	/* --------------------------------------------------- browse by country -- */

	if ( $hubs ) :
		daw_hub_head(
			__( 'Browse by country', 'daw' ),
			__( 'Each one is its own world: its dramas, its cinema, its food, its myths.', 'daw' )
		);
		?>
		<div class="daw-hcountries">
			<?php
			foreach ( $hubs as $slug => $hub ) :
				$url = daw_page_url( $slug );

				if ( ! $url ) {
					continue;
				}

				$counts = $counts_of( $hub['country'] );
				$total  = array_sum( array_map( 'intval', $counts ) );

				// The line under the name is the three kinds this country
				// actually holds most of, so it describes the country rather
				// than repeating the same four words eight times.
				arsort( $counts );
				$kinds = array();

				foreach ( array_slice( array_keys( $counts ), 0, 3 ) as $type ) {
					if ( (int) $counts[ $type ] > 0 && function_exists( 'daw_type_label' ) ) {
						$kinds[] = daw_type_label( $type );
					}
				}
				?>
				<a class="daw-hcountry" href="<?php echo esc_url( $url ); ?>">
					<span class="daw-hcountry-n">
						<?php
						if ( function_exists( 'daw_country_flag' ) ) {
							echo daw_country_flag( $hub['country'] ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
						<span><?php echo esc_html( $hub['name'] ); ?></span>
					</span>
					<span class="daw-hcountry-m">
						<?php if ( $total ) : ?>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: how many records */
									_n( '%s record', '%s records', $total, 'daw' ),
									number_format_i18n( $total )
								)
							);
							?>
						<?php endif; ?>
						<?php if ( $kinds ) : ?>
							<span class="daw-hcountry-k"><?php echo esc_html( ' · ' . implode( ', ', $kinds ) ); ?></span>
						<?php endif; ?>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php daw_ad_slot( 'daw-ad-billboard' ); ?>

	<?php
	/* -------------------------------------------------------------- strips -- */

	// Each strip is the same shape: a heading that says what it is, a line that
	// says where it came from, a link to the whole list, and six records.
	$strips = array(
		array(
			'types' => array( 'drama' ),
			'page'  => 'dramas',
			'title' => __( 'Dramas, newest first', 'daw' ),
			'note'  => __( 'By the date they went to air, not the date we typed them up.', 'daw' ),
			'style' => 'poster',
		),
		array(
			'types' => array( 'film' ),
			'page'  => 'films',
			'title' => __( 'Films worth an evening', 'daw' ),
			'note'  => __( 'Every film in the archive, most recently released first.', 'daw' ),
			'style' => 'poster',
		),
		array(
			'types' => array( 'person' ),
			'page'  => 'people',
			'title' => __( 'Actors, idols and creators', 'daw' ),
			'note'  => __( 'The profiles with the most of their work on file.', 'daw' ),
			'style' => 'poster',
			'sort'  => 'credits',
			// WHY THIS ONE STRIP IS ORDERED BY READERS
			// ----------------------------------------
			// A person becomes worth looking at when something happens to
			// them: a new drama, an award, a casting announcement. The archive
			// cannot know that, but the readers already do, and they say so by
			// arriving. So the profiles with the most work on file are the
			// pool, and the last thirty days of reading decides which six of
			// them lead. While nothing has been read yet every score is nought,
			// the sort is stable, and the strip is exactly what it was before.
			'pool'  => 60,
			'views' => true,
		),
	);

	foreach ( $strips as $strip ) :
		$cfg = function_exists( 'daw_browse_cfg_for_type' ) ? daw_browse_cfg_for_type( $strip['types'][0] ) : null;

		if ( ! $cfg ) {
			continue;
		}

		$filters         = daw_browse_request();
		$filters['sort'] = $strip['sort'] ?? 'new';
		// Only the records this strip will actually read are worth priming.
		// A strip prints six cards; the profiles strip looks at a pool of
		// sixty before choosing its six. The rest of the list exists here to
		// be counted, and counting needs no meta.
		$touch           = ! empty( $strip['views'] ) ? (int) ( $strip['pool'] ?? 60 ) : 6;
		$ids             = daw_browse_ids( $strip['types'], $filters, $touch );

		if ( ! $ids ) {
			continue;
		}

		if ( ! empty( $strip['views'] ) && function_exists( 'daw_views_sort' ) ) {
			$lead = daw_views_sort( array_slice( $ids, 0, (int) ( $strip['pool'] ?? 60 ) ) );
			$ids  = array_merge( $lead, array_slice( $ids, count( $lead ) ) );
		}

		// S10.2: on the home page only, BL and GL titles go behind everything
		// else in the strip. Applied last so it is not undone by the sorts
		// above.
		if ( function_exists( 'daw_blgl_to_back' ) ) {
			$ids = daw_blgl_to_back( $ids );
		}

		$page_url = daw_page_url( $strip['page'] );

		daw_hub_head(
			$strip['title'],
			$strip['note'],
			$page_url,
			$page_url
				? sprintf(
					/* translators: 1: how many, 2: what they are */
					__( 'All %1$s %2$s', 'daw' ),
					number_format_i18n( count( $ids ) ),
					$cfg['many']
				)
				: ''
		);
		?>
		<div class="daw-grid daw-hubgrid daw-section daw-section-tight">
			<?php
			foreach ( array_slice( $ids, 0, 6 ) as $rid ) {
				if ( 'person' === $strip['types'][0] ) {
					daw_person_card( $rid );
				} else {
					daw_hub_title_card( $rid, 'film' === $strip['types'][0] ? 'genre' : 'network' );
				}
			}
			?>
		</div>
	<?php endforeach; ?>

	<?php
	/* ------------------------------------------------------- archive rail -- */

	// Six titles and six people from the part of the archive search engines
	// have not reached yet, a different handful every day (daw-core index doctor).
	// 36 a kind: six as cards, the next thirty as plain links below them
	// (1.75.78), so the home page links sixty waiting pages, not twelve.
	$rail = function_exists( 'daw_idx_rotation' ) ? daw_idx_rotation( 36 ) : array();
	// The six card slots go to records with a picture; the rest, pictured or
	// not, follow as chips (1.75.80). The order of the day's pick is kept.
	if ( function_exists( 'daw_pictured_first' ) ) {
		foreach ( array( 'title', 'person' ) as $pk ) {
			if ( ! empty( $rail[ $pk ] ) ) {
				$rail[ $pk ] = daw_pictured_first( $rail[ $pk ] );
			}
		}
	}
	if ( ! empty( $rail['title'] ) || ! empty( $rail['person'] ) ) :
		daw_hub_head(
			__( 'More from the archive', 'daw' ),
			__( 'A different handful every day.', 'daw' ),
			'',
			''
		);
		foreach ( array( 'title', 'person' ) as $rk ) :
			if ( empty( $rail[ $rk ] ) ) {
				continue;
			}
			?>
			<div class="daw-grid daw-hubgrid daw-section daw-section-tight">
				<?php
				foreach ( array_slice( $rail[ $rk ], 0, 6 ) as $rid ) {
					if ( 'person' === $rk ) {
						daw_person_card( $rid );
					} else {
						daw_hub_title_card( $rid, 'film' === daw_get_type( $rid ) ? 'genre' : 'network' );
					}
				}
				?>
			</div>
			<?php
		endforeach;
		// THIRTY MORE OF EACH, AS NAMED LINKS (1.75.79)
		// Under the cards, the rest of the day's pick as chips in two labelled
		// groups. The words of a link are what tells Google what the page is,
		// so a title carries its year ("Empress Ki 2013") and a person is
		// named in full. Plain anchors in the HTML: every one is crawled.
		$groups = array(
			'title'  => __( 'Dramas and films to discover', 'daw' ),
			'person' => __( 'Actors and idols to discover', 'daw' ),
		);
		foreach ( $groups as $gk => $glabel ) :
			$gids = array_slice( (array) ( $rail[ $gk ] ?? array() ), 6 );
			if ( ! $gids ) {
				continue;
			}
			?>
			<h3 class="daw-discover-h"><?php echo esc_html( $glabel ); ?></h3>
			<div class="daw-chips daw-discover">
				<?php
				foreach ( $gids as $mid ) :
					$myear = '';
					if ( 'title' === $gk ) {
						$mstart = (int) daw_field_raw( $mid, 'daw_aired_start' );
						$myear  = $mstart ? gmdate( 'Y', $mstart ) : '';
					}
					?>
					<a class="daw-chip" href="<?php echo esc_url( get_permalink( $mid ) ); ?>"><?php echo esc_html( get_the_title( $mid ) ); ?><?php if ( '' !== $myear ) : ?> <span class="daw-chip-x"><?php echo esc_html( $myear ); ?></span><?php endif; ?></a>
				<?php endforeach; ?>
			</div>
			<?php
		endforeach;
	endif;
	?>

	<?php
	/* -------------------------------------------------------------- guides -- */

	$guide_types = function_exists( 'daw_guide_types' ) ? daw_guide_types() : array();
	// Forty is the pool the reader sort below looks at; four get printed.
	$guides      = $guide_types ? daw_browse_ids( $guide_types, array_merge( daw_browse_request(), array( 'sort' => 'new' ) ), 40 ) : array();

	if ( $guides ) :
		daw_hub_head(
			__( 'Culture, myths and the table', 'daw' ),
			__( 'What people look up after the credits roll: food, tradition, folklore, places.', 'daw' ),
			daw_page_url( 'culture' ),
			__( 'All guides', 'daw' )
		);
		?>
		<div class="daw-hubguides daw-browseguides daw-section daw-section-tight">
			<?php
			// Same rule as the profiles above: newest is the order until
			// readers have expressed an opinion, then the most read guide of
			// the last thirty days leads.
			$guides_lead = function_exists( 'daw_views_sort' )
				? daw_views_sort( array_slice( $guides, 0, 40 ) )
				: $guides;

			foreach ( array_slice( $guides_lead, 0, 4 ) as $gid ) {
				daw_hub_guide_card( $gid );
			}
			?>
		</div>
	<?php endif; ?>

	<?php
	/* ---------------------------------------------------------------- news -- */

	// Sorted by the last real update, not by publication (SEO spec item 5).
	$news = function_exists( 'daw_news_latest_ids' )
		? daw_news_latest_ids( 4 )
		: daw_browse_ids( array( 'news' ), array_merge( daw_browse_request(), array( 'sort' => 'new' ) ), 4 );

	if ( $news ) :
		?>
		<div class="daw-hnews daw-section">
			<div class="daw-hnews-main">
				<?php
				daw_hub_head(
					__( 'Latest news', 'daw' ),
					__( 'Only what has been announced, with the date and the source on every story.', 'daw' ),
					daw_page_url( 'news' ),
					__( 'All news', 'daw' )
				);
				?>

				<ul class="daw-hnewslist">
					<?php foreach ( array_slice( $news, 0, 4 ) as $nid ) : ?>
						<li>
							<a href="<?php echo esc_url( get_permalink( $nid ) ); ?>">
								<span class="daw-hnews-img"><?php echo daw_poster( $nid, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<span class="daw-hnews-txt">
									<span class="daw-hnews-t"><?php echo esc_html( get_the_title( $nid ) ); ?></span>
									<span class="daw-hnews-m">
										<?php
										echo esc_html(
											implode(
												' · ',
												array_filter(
													array(
														daw_home_news_date( $nid ),
														daw_terms( 'dw_country', $nid ),
													)
												)
											)
										);
										?>
									</span>
								</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<aside class="daw-hnews-rail">
				<?php
				// Each phrase as the record that answers it, never a search
				// page (SEO spec item 3, 8 Oct 2026). No record, no line.
				$trending = function_exists( 'daw_search_trending_records' ) ? daw_search_trending_records( 5 ) : array();

				if ( $trending ) :
					?>
					<div class="daw-rcard">
						<h2 class="daw-rcard-h"><?php esc_html_e( 'What people search for here', 'daw' ); ?></h2>
						<ul class="daw-strend">
							<?php foreach ( $trending as $tid => $ttitle ) : ?>
								<li><a href="<?php echo esc_url( get_permalink( $tid ) ); ?>"><?php echo esc_html( $ttitle ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php daw_ad_slot( 'daw-ad-rect' ); ?>
			</aside>
		</div>
	<?php endif; ?>
</div>

<?php
get_footer();
