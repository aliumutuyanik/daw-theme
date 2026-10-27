<?php
/**
 * The browse body: the sort bar, the filters, the grid and the pager.
 *
 * ONE BODY, TWO HEADS
 * -------------------
 * /dramas/ and /korean-drama/ ask the same question with one difference: the
 * second one has already answered "which country". They were two templates for
 * a week and immediately drifted — the country page had no filters, so a reader
 * who wanted Korean romance had to go up to /dramas/, filter, and lose the
 * country. Now both pages draw this, and the country page simply arrives with
 * one filter already locked.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * Draw the whole browse body.
 *
 * @param array $args {
 *     @type array  $cfg     types, one, many, facets.
 *     @type string $base    The page's own url, which every filter link builds on.
 *     @type array  $filters daw_browse_request().
 *     @type array  $ids     Every matching record id, already ordered.
 *     @type string $lock    A country slug this page cannot leave, or ''.
 *     @type string $style   poster or guide.
 * }
 */
function daw_browse_body( array $args ) {
	$cfg     = $args['cfg'];
	$base    = $args['base'];
	$filters = $args['filters'];
	$ids     = $args['ids'];
	$lock    = $args['lock'] ?? '';
	$style   = $args['style'] ?? 'poster';

	// Everything this page has already answered for the reader: the country a
	// section is locked to, plus any filter the page stands for by name.
	$fixed = $args['fixed'] ?? array();

	if ( ! $fixed && $lock ) {
		$fixed = array( 'country' => array( (string) $lock ) );
	}

	$total = count( $ids );
	$per   = defined( 'DAW_BROWSE_PER_PAGE' ) ? DAW_BROWSE_PER_PAGE : 24;
	$pages = max( 1, (int) ceil( $total / $per ) );
	$pg    = min( max( 1, (int) $filters['pg'] ), $pages );
	$slice = array_slice( $ids, ( $pg - 1 ) * $per, $per );

	// A page never offers a filter it has already answered for itself.
	$hidden_facets = array_map( 'daw_browse_facet_of_key', array_keys( $fixed ) );
	$facet_list    = $hidden_facets ? array_values( array_diff( $cfg['facets'], $hidden_facets ) ) : $cfg['facets'];
	$facets        = $facet_list ? daw_browse_facets( $cfg['types'], $filters, $facet_list ) : array();

	$chips = daw_browse_chips( $base, $filters, $fixed );

	// How many choices the reader has made. Read here rather than beside the
	// rail, because the button that shows it is printed before the rail is.
	$active = function_exists( 'daw_browse_count_active' ) ? daw_browse_count_active( $filters, $fixed ) : 0;

	// A rail whose every filter has fewer than two options prints nothing but
	// its "Show results" button: /quizzes/ with one quiz looked like a broken
	// filter. With nothing to offer and nothing chosen, there is no rail.
	if ( $facets && ! $active ) {
		$printable = array_filter(
			$facets,
			static function ( $rows ) {
				return count( (array) $rows ) >= 2;
			}
		);

		if ( ! $printable ) {
			$facets = array();
		}
	}
	?>

	<div class="daw-browsetop">
		<p class="daw-browsecount">
			<?php
			printf(
				/* translators: 1: number of records, 2: singular or plural label */
				esc_html__( '%1$s %2$s', 'daw' ),
				esc_html( number_format_i18n( $total ) ),
				esc_html( 1 === $total ? $cfg['one'] : $cfg['many'] )
			);
			?>
		</p>

		<?php
		// THE TRIGGER LIVES IN THIS ROW ON A PHONE
		// ----------------------------------------
		// It had a line of its own under the sort options, which spends a whole
		// row of a small screen on one control. Here it sits at the left of the
		// row the reader is already looking at, and the sort options scroll
		// past it if they need to. On a desktop the rail is on the page and
		// this button is not drawn at all.
		if ( $facets ) :
			?>
			<button type="button" class="daw-filterbtn" data-daw-filters-open hidden aria-controls="daw-filters" aria-expanded="false">
				<?php esc_html_e( 'Filters', 'daw' ); ?>
				<?php if ( $active ) : ?>
					<span class="daw-filterbtn-n"><?php echo esc_html( number_format_i18n( $active ) ); ?></span>
				<?php endif; ?>
			</button>
		<?php endif; ?>

		<?php if ( $total > 1 ) : ?>
			<div class="daw-browsesort">
				<span class="daw-browsesort-l"><?php esc_html_e( 'Sort', 'daw' ); ?></span>
				<?php
				// A record with an air date sorts by it. A person or a guide has
				// no air date, so "newest" there means most recently added, and
				// the label says that rather than implying a date nobody holds.
				$dated = ! in_array( $cfg['types'][0], array( 'person', 'group' ), true ) && 'guide' !== $style;

				// WHAT THIS SITE CAN HONESTLY SORT BY
				// -----------------------------------
				// Not "most viewed": nothing here counts views, and a list
				// ordered by a number the site does not hold is a list ordered
				// by nothing. What it does hold, for a person, is how much of
				// their work is filed, and a profile with forty credits is
				// both the more complete one and usually the one the reader
				// meant. So people get Most credits and titles keep their dates.
				$people = in_array( $cfg['types'][0], array( 'person', 'group' ), true );

				if ( $dated ) {
					$sorts = array(
						'new' => __( 'Newest first', 'daw' ),
						'old' => __( 'Oldest first', 'daw' ),
						'az'  => __( 'A to Z', 'daw' ),
					);
				} elseif ( $people ) {
					$sorts = array(
						'new'     => __( 'Recently added', 'daw' ),
						'credits' => __( 'Most credits', 'daw' ),
						'az'      => __( 'A to Z', 'daw' ),
					);
				} else {
					$sorts = array(
						'new' => __( 'Recently added', 'daw' ),
						'az'  => __( 'A to Z', 'daw' ),
					);
				}

				foreach ( $sorts as $key => $label ) :
					$is = $filters['sort'] === $key;
					?>
					<a class="daw-browsesort-o<?php echo $is ? ' is-on' : ''; ?>"
						href="<?php echo esc_url( daw_browse_url( $base, $filters, array( 'sort' => $key ) ) ); ?>"
						<?php echo $is ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="daw-browsecols<?php echo $facets ? '' : ' is-nofacets'; ?>">

		<?php if ( $facets ) : ?>
			<?php
			// THE RAIL, AND WHY IT LOOKS LIKE THIS
			// ------------------------------------
			// Three things decide whether a filter gets used, and all three
			// come from the same body of research every shop already follows.
			// An option has to look selectable, so each row carries a real box
			// rather than being a link that happens to change colour. Several
			// values in one filter have to be possible, because a reader who
			// wants romance usually also wants comedy, and being made to choose
			// is where people give up. And the number beside an option has to
			// say what clicking it gives, which is counted with that filter
			// released rather than with it applied.
			//
			// On a phone the rail is a sheet rather than a column: it opens
			// over the page from a button that carries the count, and it closes
			// on a button that says how many records are waiting. Both of those
			// are drawn below and cost nothing when the script never runs,
			// because without it the rail is simply a block of the page.
			$titles = array(
				'country'  => __( 'Where it is from', 'daw' ),
				'genre'    => __( 'Genre', 'daw' ),
				'network'  => __( 'Where to watch', 'daw' ),
				'decade'   => __( 'When it aired', 'daw' ),
				'status'   => __( 'Still airing', 'daw' ),
				'episodes' => __( 'How many episodes', 'daw' ),
				'runtime'  => __( 'How long it runs', 'daw' ),
				'role'     => __( 'What they do', 'daw' ),
				'kind'     => __( 'Solo or group', 'daw' ),
				'gender'   => __( 'Women or men', 'daw' ),
				'dish'     => __( 'What kind of dish', 'daw' ),
				'story'    => __( 'What kind of story', 'daw' ),
				'born'     => __( 'When they were born', 'daw' ),
				'height'   => __( 'Height', 'daw' ),
			);

			?>

			<aside class="daw-facets" id="daw-filters" aria-label="<?php esc_attr_e( 'Filters', 'daw' ); ?>">
				<form class="daw-facets-in" method="get" action="<?php echo esc_url( $base . '#results' ); ?>">
					<?php
					// SORT TRAVELS WITH THE FORM
					// --------------------------
					// It is not a filter, it is not in the rail, and without
					// this hidden copy every filter change would quietly throw
					// the reader's chosen order away.
					if ( ! empty( $filters['sort'] ) && 'new' !== $filters['sort'] ) {
						printf( '<input type="hidden" name="sort" value="%s">', esc_attr( $filters['sort'] ) );
					}

					// What the page has already answered is part of the page,
					// not part of what the reader chose, so it is carried
					// rather than offered.
					foreach ( $fixed as $fixed_key => $fixed_values ) {
						foreach ( (array) $fixed_values as $fixed_value ) {
							printf(
								'<input type="hidden" name="%1$s[]" value="%2$s">',
								esc_attr( $fixed_key ),
								esc_attr( $fixed_value )
							);
						}
					}
					?>

					<div class="daw-facets-head">
						<h2 class="daw-facets-t"><?php esc_html_e( 'Filters', 'daw' ); ?></h2>

						<?php
						// CLEAR ALL EMPTIES THE BOXES, IT DOES NOT LEAVE
						// ----------------------------------------------
						// Without a script it is a link to the cleared page,
						// which is the version that always works. With one it
						// unticks everything and stays where the reader is,
						// because they are still deciding. It appears the
						// moment anything is ticked rather than only when
						// something has been applied: a reader who has ticked
						// three boxes and changed their mind needs it before
						// they have pressed anything.
						?>
						<a class="daw-facets-clear" data-daw-filters-clear
							href="<?php echo esc_url( daw_browse_url( $base, $filters, daw_browse_cleared( $filters, $fixed ) ) ); ?>"
							<?php echo $active ? '' : 'hidden'; ?>><?php esc_html_e( 'Clear all', 'daw' ); ?></a>

						<?php
						// AND THE CROSS IS CANCEL
						// -----------------------
						// Everything ticked since the sheet opened is put back
						// the way it was and the sheet closes. A reader who
						// opened the filters, chose China and thought better of
						// it expects the cross to undo that, not to keep it
						// and hide it.
						?>
						<button type="button" class="daw-facets-x" data-daw-filters-close hidden>
							<span class="daw-vh"><?php esc_html_e( 'Close without changing anything', 'daw' ); ?></span>
							<span aria-hidden="true">&times;</span>
						</button>
					</div>

					<div class="daw-facets-body">
					<?php
					foreach ( $facet_list as $facet ) :
						$rows = $facets[ $facet ] ?? array();

						// A filter with one option divides nothing, so it is
						// not printed. A page with three records does not need
						// a sidebar explaining that all three are Korean.
						if ( ! $rows || count( $rows ) < 2 ) {
							continue;
						}

						$key      = 'episodes' === $facet ? 'eps' : $facet;
						$chosen   = (array) ( $filters[ $key ] ?? array() );
						$long     = count( $rows ) > 8;
						// A filter the reader has already used stays open. So
						// does the first one, which is the one they look at.
						$is_open  = ( $chosen || 0 === array_search( $facet, $facet_list, true ) );
						?>
						<details class="daw-facet"<?php echo $is_open ? ' open' : ''; ?>>
							<summary class="daw-facet-h">
								<span><?php echo esc_html( $titles[ $facet ] ); ?></span>
								<?php if ( $chosen ) : ?>
									<span class="daw-facet-b"><?php echo esc_html( number_format_i18n( count( $chosen ) ) ); ?></span>
								<?php endif; ?>
							</summary>

							<?php if ( $long ) : ?>
								<label class="daw-facet-find" hidden>
									<span class="daw-vh"><?php echo esc_html( sprintf( /* translators: %s: filter name */ __( 'Search %s', 'daw' ), $titles[ $facet ] ) ); ?></span>
									<input type="search" data-daw-facet-find placeholder="<?php esc_attr_e( 'Type to narrow…', 'daw' ); ?>" autocomplete="off">
								</label>
							<?php endif; ?>

							<ul class="daw-facet-l">
								<?php
								$i = 0;

								foreach ( $rows as $row ) :
									++$i;
									$on = daw_browse_has( $filters, $key, $row['slug'] );
									?>
									<li<?php echo ( $i > 8 && $long && ! $on ) ? ' class="is-extra" hidden' : ''; ?>>
										<label class="daw-facet-o<?php echo $on ? ' is-on' : ''; ?>">
											<input type="checkbox" class="daw-facet-i" name="<?php echo esc_attr( $key ); ?>[]"
												value="<?php echo esc_attr( $row['slug'] ); ?>"<?php echo $on ? ' checked' : ''; ?>>
											<span class="daw-facet-box" aria-hidden="true"></span>
											<span class="daw-facet-n"><?php echo esc_html( $row['name'] ); ?></span>
											<span class="daw-facet-c"><?php echo esc_html( number_format_i18n( $row['n'] ) ); ?></span>
										</label>
									</li>
								<?php endforeach; ?>
							</ul>

							<?php if ( $long ) : ?>
								<button type="button" class="daw-facet-more" data-daw-facet-more hidden
									data-less="<?php esc_attr_e( 'Show fewer', 'daw' ); ?>"
									data-more="<?php echo esc_attr( sprintf( /* translators: %s: how many more options */ __( 'Show %s more', 'daw' ), number_format_i18n( count( $rows ) - 8 ) ) ); ?>">
									<?php
									printf(
										/* translators: %s: how many more options there are */
										esc_html__( 'Show %s more', 'daw' ),
										esc_html( number_format_i18n( count( $rows ) - 8 ) )
									);
									?>
								</button>
							<?php endif; ?>
						</details>
					<?php endforeach; ?>
					</div>

					<?php
					// THE BUTTON THAT APPLIES THEM
					// ----------------------------
					// Every option used to be a link, so one tap was one page
					// load. On a phone, inside a sheet, that meant the sheet
					// shut and the page jumped to the top on every single
					// choice, which made picking three things impossible. Now
					// the options are checkboxes and this submits all of them
					// at once. On a desktop the script submits on each change
					// instead, because the rail is beside the grid there and
					// the reader can see the result arrive.
					//
					// Without any script it is simply a form with a button,
					// which is the version that always works.
					?>
					<div class="daw-facets-foot">
						<button type="submit" class="daw-btn daw-btn-p daw-facets-done">
							<?php esc_html_e( 'Show results', 'daw' ); ?>
						</button>
					</div>
				</form>
			</aside>
		<?php endif; ?>

		<div class="daw-browsemain">

			<?php
			// WHAT IS ON, WHERE IT CANNOT BE MISSED
			// -------------------------------------
			// On a phone the rail is behind a button, so without this row the
			// reader cannot see what they have chosen, and a page showing four
			// records instead of four hundred looks broken rather than
			// filtered. So the chips sit above the grid at every width, each
			// one removing only itself.
			if ( $facets ) :
				?>
				<div class="daw-filterbar">
					<?php if ( $chips ) : ?>
						<div class="daw-chips">
							<?php foreach ( $chips as $chip ) : ?>
								<a class="daw-chip" href="<?php echo esc_url( $chip['url'] ); ?>">
									<?php echo esc_html( $chip['label'] ); ?>
									<span class="daw-chip-x" aria-hidden="true">×</span>
								</a>
							<?php endforeach; ?>
							<a class="daw-chip daw-chip-clear" href="<?php echo esc_url( daw_browse_url( $base, $filters, daw_browse_cleared( $filters, $fixed ) ) ); ?>"><?php esc_html_e( 'Clear all', 'daw' ); ?></a>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $slice ) : ?>
				<div id="results" class="<?php echo 'guide' === $style ? 'daw-hubguides daw-browseguides' : 'daw-grid daw-browsegrid'; ?>">
					<?php
					$n        = 0;
					$ads_here = 0;

					foreach ( $slice as $rid ) {
						++$n;

						if ( 'guide' === $style ) {
							daw_hub_guide_card( $rid, true );
						} elseif ( 'person' === $cfg['types'][0] || 'group' === $cfg['types'][0] ) {
							daw_person_card( $rid );
						} else {
							daw_hub_title_card( $rid, 'film' === $cfg['types'][0] ? 'genre' : 'network' );
						}

						// THE PAGE IS THREE BLOCKS OF SIX
						// -------------------------------
						// Eighteen cards divide evenly by six, three and two,
						// which is the column count on desktop, tablet and
						// phone. So a break after the sixth and the twelfth
						// lands on a completed row at every width, and the ad
						// takes the full width rather than a card's place: no
						// card ever moves when it loads, and no row is left
						// short above it.
						if ( 0 === $n % 6 && $n < count( $slice ) ) {
							echo '</div>';
							daw_ad_slot( 'daw-ad-leaderboard' );
							++$ads_here;
							printf( '<div class="%s">', 'guide' === $style ? 'daw-hubguides daw-browseguides' : 'daw-grid daw-browsegrid' );
						}
					}
					?>
				</div>

				<?php
				// A THIN PAGE STILL CARRIES ONE
				// -----------------------------
				// The advert above only appears between rows, so a browse page
				// with six records or fewer was carrying none at all. Myths has
				// three, travel has four and the quizzes page has two: the
				// three pages least able to afford being the only ones on the
				// site that earn nothing. One unit after the last row, and only
				// where the row breaks never fired.
				if ( ! $ads_here ) {
					daw_ad_slot( 'daw-ad-leaderboard' );
				}
				?>
			<?php else : ?>
				<p class="daw-empty">
					<?php
					printf(
						/* translators: %s: plural record label, e.g. dramas */
						esc_html__( 'No %s match this combination yet. Removing one filter usually brings the list back.', 'daw' ),
						esc_html( $cfg['many'] )
					);
					?>
				</p>
			<?php endif; ?>

			<?php daw_browse_pager( $base, $filters, $pg, $pages ); ?>
		</div>
	</div>

	<?php
	if ( $facets ) {
		daw_browse_filter_script();
	}
	?>
	<?php
}

/**
 * The small amount of script the rail needs, printed once per page.
 *
 * NOTHING HERE IS LOAD-BEARING
 * ----------------------------
 * Without it the rail is a column of links that still filter, still count and
 * still work on a phone, because the phone layout puts the rail above the grid
 * rather than hiding it. What the script adds is the sheet, the box that
 * narrows a long list, and the button that reveals the rest of one. Each of
 * those is an improvement on something that already works, which is why the
 * markup carries the plain version and the script upgrades it.
 */
function daw_browse_filter_script() {
	static $done = false;

	if ( $done ) {
		return;
	}

	$done = true;
	?>
	<script>
	( function () {
		var rail = document.getElementById( 'daw-filters' );
		if ( ! rail ) { return; }

		var form  = rail.querySelector( 'form' );
		var open  = document.querySelector( '[data-daw-filters-open]' );
		var shuts = rail.querySelectorAll( '[data-daw-filters-close]' );
		var root  = document.documentElement;

		function isSheet() {
			return window.matchMedia( '(max-width: 960px)' ).matches;
		}

		/* THE PAGE DOES NOT MOVE WHILE THE SHEET IS OPEN
		   ---------------------------------------------
		   Locking the page with overflow:hidden alone lets the browser forget
		   where the reader was, so closing the sheet dropped them back at the
		   top. The scroll position is held on the body instead, and put back
		   exactly when the sheet closes. */
		var at = 0;

		/* WHAT WAS TICKED WHEN THE SHEET OPENED
		   ------------------------------------
		   The cross is cancel, so the state at the moment of opening has to be
		   kept somewhere to put back. A reader who opens the filters, chooses
		   China and changes their mind expects the cross to undo that rather
		   than to keep it and hide it from them. */
		var was = null;

		function boxes() {
			return form ? form.querySelectorAll( '.daw-facet-i' ) : [];
		}

		function snapshot() {
			was = [];
			Array.prototype.forEach.call( boxes(), function ( b ) { was.push( b.checked ); } );
		}

		function restore() {
			if ( ! was ) { return; }
			Array.prototype.forEach.call( boxes(), function ( b, i ) {
				b.checked = !! was[ i ];
				var o = b.closest( '.daw-facet-o' );
				if ( o ) { o.classList.toggle( 'is-on', b.checked ); }
			} );
			paint();
		}

		/* How many boxes are ticked right now, which decides whether Clear all
		   is offered and what the apply button says. Read from the form rather
		   than from the url, because in the sheet the two disagree on purpose
		   until the reader presses the button. */
		function ticked() {
			var n = 0;
			Array.prototype.forEach.call( boxes(), function ( b ) { if ( b.checked ) { n++; } } );
			return n;
		}

		var clear = rail.querySelector( '[data-daw-filters-clear]' );
		var done  = rail.querySelector( '.daw-facets-done' );
		var doneT = done ? done.textContent.trim() : '';

		function paint() {
			var n = ticked();
			if ( clear ) { clear.hidden = n === 0; }
			if ( done ) {
				done.textContent = n ? doneT + ' (' + n + ')' : doneT;
			}
		}

		function show() {
			snapshot();
			paint();
			at = window.scrollY || window.pageYOffset || 0;
			document.body.style.position = 'fixed';
			document.body.style.top = ( -at ) + 'px';
			document.body.style.left = '0';
			document.body.style.right = '0';
			document.body.style.width = '100%';
			root.classList.add( 'daw-filters-on' );
			if ( open ) { open.setAttribute( 'aria-expanded', 'true' ); }
		}

		function hide( cancel ) {
			if ( cancel ) { restore(); }
			root.classList.remove( 'daw-filters-on' );
			document.body.style.position = '';
			document.body.style.top = '';
			document.body.style.left = '';
			document.body.style.right = '';
			document.body.style.width = '';
			window.scrollTo( 0, at );
			if ( open ) {
				open.setAttribute( 'aria-expanded', 'false' );
				open.focus( { preventScroll: true } );
			}
		}

		if ( open ) {
			open.hidden = false;
			open.addEventListener( 'click', show );
			root.classList.add( 'daw-filters-sheet' );
		}

		Array.prototype.forEach.call( shuts, function ( el ) {
			el.hidden = false;
			el.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				hide( true );
			} );
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && root.classList.contains( 'daw-filters-on' ) ) { hide( true ); }
		} );

		rail.addEventListener( 'click', function ( e ) {
			// The dimmed page behind the sheet closes it, the way every sheet
			// on a phone does, and closing is cancelling.
			if ( e.target === rail && isSheet() ) { hide( true ); }
		} );

		/* Clear all empties the boxes rather than leaving the page. On a
		   desktop the rail applies on every change, so clearing applies too;
		   in the sheet the reader is still building a question and the button
		   at the bottom is what ends it. */
		if ( clear ) {
			clear.addEventListener( 'click', function ( e ) {
				if ( ! form ) { return; }

				e.preventDefault();

				Array.prototype.forEach.call( boxes(), function ( b ) {
					b.checked = false;
					var o = b.closest( '.daw-facet-o' );
					if ( o ) { o.classList.remove( 'is-on' ); }
				} );

				paint();

				if ( ! isSheet() ) { form.submit(); }
			} );
		}

		/* COMING BACK IS NOT REOPENING
		   ---------------------------
		   A page restored from the back/forward cache comes back with whatever
		   class was on the root when the reader left it, so a sheet that was
		   open when they pressed a link was open again when they came back.
		   The sheet is never the thing somebody navigated back to. */
		window.addEventListener( 'pageshow', function () {
			if ( root.classList.contains( 'daw-filters-on' ) ) {
				root.classList.remove( 'daw-filters-on' );
				document.body.style.position = '';
				document.body.style.top = '';
				document.body.style.left = '';
				document.body.style.right = '';
				document.body.style.width = '';
				if ( open ) { open.setAttribute( 'aria-expanded', 'false' ); }
			}
			paint();
		} );

		/* On a desktop the rail sits beside the grid, so a change can apply
		   straight away and the reader watches the result arrive. On a phone
		   they are inside a sheet building a question, and a reload after every
		   tap would shut it in their face, so there the button applies the lot. */
		if ( form ) {
			form.addEventListener( 'change', function ( e ) {
				if ( ! e.target.classList.contains( 'daw-facet-i' ) ) { return; }

				var box = e.target.closest( '.daw-facet-o' );
				if ( box ) { box.classList.toggle( 'is-on', e.target.checked ); }

				paint();

				if ( ! isSheet() ) { form.submit(); }
			} );
		}

		// Show more, show fewer.
		Array.prototype.forEach.call( rail.querySelectorAll( '[data-daw-facet-more]' ), function ( btn ) {
			btn.hidden = false;
			btn.addEventListener( 'click', function () {
				var box  = btn.closest( '.daw-facet' );
				var more = box.classList.toggle( 'is-open' );
				Array.prototype.forEach.call( box.querySelectorAll( 'li.is-extra' ), function ( li ) {
					li.hidden = ! more;
				} );
				btn.textContent = more ? btn.dataset.less : btn.dataset.more;
			} );
		} );

		// Type to narrow a long list. Rows are hidden rather than reordered, so
		// nothing moves under the reader's finger.
		Array.prototype.forEach.call( rail.querySelectorAll( '[data-daw-facet-find]' ), function ( input ) {
			input.closest( '.daw-facet-find' ).hidden = false;
			input.addEventListener( 'input', function () {
				var box   = input.closest( '.daw-facet' );
				var q     = input.value.trim().toLowerCase();
				var extra = box.classList.contains( 'is-open' );

				Array.prototype.forEach.call( box.querySelectorAll( 'li' ), function ( li ) {
					var name = li.querySelector( '.daw-facet-n' );
					if ( ! name ) { return; }

					if ( ! q ) {
						li.hidden = li.classList.contains( 'is-extra' ) && ! extra;
						return;
					}

					li.hidden = name.textContent.toLowerCase().indexOf( q ) === -1;
				} );
			} );
		} );
	}() );
	</script>
	<?php
}

/**
 * The filters currently applied, as removable chips.
 *
 * The chip row is the only place a reader can see everything that is narrowing
 * the page at once, which matters on a phone where the rail is closed.
 */
function daw_browse_chips( $base, $filters, $fixed = '' ) {
	$fixed = function_exists( 'daw_browse_fixed_arg' ) ? daw_browse_fixed_arg( $fixed ) : array();
	$chips = array();

	// ONE CHIP PER VALUE
	// ------------------
	// A filter now holds a list, so "Romance, Comedy" is two chips and each one
	// removes only itself. A single chip that cleared the whole filter would
	// undo a choice the reader did not make.
	$label_of = static function ( $key, $value ) {
		switch ( $key ) {
			case 'genre':
			case 'country':
			case 'network':
				$taxonomy = array(
					'genre'   => 'dw_genre',
					'country' => 'dw_country',
					'network' => 'dw_network',
				)[ $key ];
				$term     = get_term_by( 'slug', $value, $taxonomy );

				return $term ? $term->name : '';

			case 'decade':
				/* translators: %s: decade, e.g. 2010 */
				return sprintf( __( '%ss', 'daw' ), (int) $value );

			case 'born':
				/* translators: %s: decade, e.g. 1990 */
				return sprintf( __( 'Born in the %ss', 'daw' ), (int) $value );

			case 'dish':
				return function_exists( 'daw_food_kind_labels' ) ? ( daw_food_kind_labels()[ $value ] ?? '' ) : '';

			case 'gender':
				return function_exists( 'daw_browse_gender_labels' ) ? ( daw_browse_gender_labels()[ $value ] ?? '' ) : '';

			case 'kind':
				return function_exists( 'daw_browse_kind_labels' ) ? ( daw_browse_kind_labels()[ $value ] ?? '' ) : '';

			case 'role':
				return function_exists( 'daw_person_role_labels' ) ? ( daw_person_role_labels()[ $value ] ?? '' ) : '';

			case 'status':
				$words = array(
					'ongoing'   => __( 'On air now', 'daw' ),
					'upcoming'  => __( 'Upcoming', 'daw' ),
					'completed' => __( 'Finished', 'daw' ),
				);

				return $words[ $value ] ?? '';

			case 'eps':
				$bands = daw_browse_bands( 'episodes' );

				return isset( $bands[ $value ] ) ? $bands[ $value ][0] : '';

			case 'runtime':
				$bands = daw_browse_bands( 'runtime' );

				return isset( $bands[ $value ] ) ? $bands[ $value ][0] : '';

			case 'height':
				$bands = daw_browse_bands( 'height' );

				return isset( $bands[ $value ] ) ? $bands[ $value ][0] : '';
		}

		return '';
	};

	foreach ( daw_browse_filter_keys() as $key ) {
		$skip = isset( $fixed[ $key ] ) ? $fixed[ $key ] : array();

		foreach ( array_diff( array_map( 'strval', (array) ( $filters[ $key ] ?? array() ) ), $skip ) as $value ) {
			$label = $label_of( $key, $value );

			if ( '' === $label ) {
				continue;
			}

			$chips[] = array(
				'label' => $label,
				'url'   => daw_browse_toggle_url( $base, $filters, $key, $value ),
			);
		}
	}

	return $chips;
}

/**
 * Every filter emptied, except the one the page is locked to.
 *
 * "Clear all" on a country page clears the filters the reader chose and leaves
 * the country, because the country is the page rather than a choice.
 */
function daw_browse_cleared( $filters, $fixed = '' ) {
	$fixed   = function_exists( 'daw_browse_fixed_arg' ) ? daw_browse_fixed_arg( $fixed ) : array();
	$changes = array();

	foreach ( daw_browse_filter_keys() as $key ) {
		$changes[ $key ] = isset( $fixed[ $key ] ) ? $fixed[ $key ] : array();
	}

	$changes['pg'] = 1;

	return $changes;
}


/** First, last and the current page's neighbours. Nobody clicks page 34. */
function daw_browse_pager( $base, $filters, $pg, $pages ) {
	if ( $pages < 2 ) {
		return;
	}
	?>
	<nav class="daw-pager" aria-label="<?php esc_attr_e( 'Pagination', 'daw' ); ?>">
		<?php if ( $pg > 1 ) : ?>
			<a class="daw-pager-o" rel="prev" href="<?php echo esc_url( daw_browse_url( $base, $filters, array( 'pg' => $pg - 1 ) ) ); ?>">‹<span class="daw-sr"><?php esc_html_e( 'Previous page', 'daw' ); ?></span></a>
		<?php endif; ?>

		<?php
		$window = array_values(
			array_unique(
				array_filter(
					array( 1, $pages, $pg, $pg - 1, $pg + 1 ),
					static function ( $i ) use ( $pages ) {
						return $i >= 1 && $i <= $pages;
					}
				)
			)
		);
		sort( $window );

		$last = 0;

		foreach ( $window as $i ) :
			if ( $last && $i > $last + 1 ) :
				?>
				<span class="daw-pager-gap" aria-hidden="true">…</span>
				<?php
			endif;

			$last = $i;
			?>
			<a class="daw-pager-o<?php echo $i === $pg ? ' is-on' : ''; ?>"
				href="<?php echo esc_url( daw_browse_url( $base, $filters, array( 'pg' => $i ) ) ); ?>"
				<?php echo $i === $pg ? ' aria-current="page"' : ''; ?>><?php echo esc_html( number_format_i18n( $i ) ); ?></a>
		<?php endforeach; ?>

		<?php if ( $pg < $pages ) : ?>
			<a class="daw-pager-o" rel="next" href="<?php echo esc_url( daw_browse_url( $base, $filters, array( 'pg' => $pg + 1 ) ) ); ?>">›<span class="daw-sr"><?php esc_html_e( 'Next page', 'daw' ); ?></span></a>
		<?php endif; ?>
	</nav>
	<?php
}

/**
 * The writing under a browse or section page, cut at its headings.
 *
 * It used to sit as one 68ch column on the left of a 1180px page, with half the
 * screen empty beside it (owner, 24 Sep 2026). Each heading and the paragraphs
 * under it become one block, so the desktop can lay the blocks side by side as
 * columns and the phone keeps them stacked. Writing without headings stays one
 * block and flows into two columns on a wide screen.
 *
 * @param string $html The stored text, before wpautop.
 * @return string Safe HTML.
 */
function daw_seotext_body( $html ) {
	$html = trim( (string) $html );
	if ( '' === $html ) {
		return '';
	}
	// One reading column (about 70 characters a line) instead of side-by-side
	// blocks: blocks of unequal length left big gaps (owner, 21 Oct 2026). The
	// width beside the column holds a sticky "On this page" list built from the
	// headings, so the right side is useful instead of empty.
	$html = wpautop( $html );
	$toc  = array();
	$used = array();
	$html = preg_replace_callback(
		'/<h([23])([^>]*)>(.*?)<\/h\1>/is',
		static function ( $m ) use ( &$toc, &$used ) {
			$text = trim( wp_strip_all_tags( $m[3] ) );
			$id   = sanitize_title( $text );
			if ( '' === $id ) {
				$id = 'part';
			}
			$base = $id;
			$i    = 2;
			while ( isset( $used[ $id ] ) ) {
				$id = $base . '-' . $i++;
			}
			$used[ $id ] = true;
			if ( false === stripos( $m[2], 'id=' ) ) {
				$m[2] .= ' id="' . esc_attr( $id ) . '"';
			}
			$toc[] = array( (int) $m[1], $id, $text );
			return '<h' . $m[1] . $m[2] . '>' . $m[3] . '</h' . $m[1] . '>';
		},
		$html
	);
	$top = array_values( array_filter( $toc, static function ( $t ) { return 2 === $t[0]; } ) );
	if ( count( $top ) < 3 ) {
		$top = $toc;
	}
	$out = '<div class="daw-seotext-cols' . ( count( $top ) >= 3 ? ' has-toc' : '' ) . '">';
	$out .= '<div class="daw-seotext-col">' . wp_kses_post( $html ) . '</div>';
	if ( count( $top ) >= 3 ) {
		$out .= '<nav class="daw-seotext-toc" aria-label="' . esc_attr__( 'On this page', 'daw' ) . '"><p class="daw-seotext-toc-h">' . esc_html__( 'On this page', 'daw' ) . '</p><ol>';
		foreach ( $top as $t ) {
			$out .= '<li><a href="#' . esc_attr( $t[1] ) . '">' . esc_html( $t[2] ) . '</a></li>';
		}
		$out .= '</ol></nav>';
	}
	return $out . '</div>';
}
