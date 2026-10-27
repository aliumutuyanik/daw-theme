<?php
/**
 * Site footer, plus the mobile bottom bar.
 *
 * The bottom bar is not decoration. A database with a thousand records needs a
 * way to move that is always under the reader's thumb, and Search sits in it as
 * a first-class destination rather than an icon in a far corner.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;
?>
	</main>

	<footer class="daw-footer">
		<div class="daw-wrap">
			<div class="daw-footer-top">

				<?php
				/* WHAT THIS PARAGRAPH IS FOR
				   --------------------------
				   It used to carry the picture licensing notice, which is a
				   legal sentence sitting in the first thing a reader looks at.
				   The notice still exists, because it has to: it now sits in
				   the small print at the bottom of this same footer, where
				   small print belongs. What stands here instead is the answer
				   to the only question a reader has in this corner of the
				   page, which is what this place is and what is in it. */
				?>
				<div class="daw-footer-brand">
					<?php echo daw_logo( 52, 'default', true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p>
						<?php
						esc_html_e(
							'A database and guide for people who follow Korean, Japanese, Chinese, Thai and Taiwanese drama. Title pages hold the cast, the episodes, where each show aired and what it is about; people and groups have profiles built from the work they appear in. Around them sits the writing: the food, the myths, the festivals and the places these shows keep going back to.',
							'daw'
						);
						?>
					</p>

					<?php $contact = daw_page_url( 'contact' ); ?>
					<?php if ( $contact ) : ?>
						<p class="daw-footer-ask">
							<?php esc_html_e( 'Something missing, or something wrong?', 'daw' ); ?>
							<a href="<?php echo esc_url( $contact ); ?>"><?php esc_html_e( 'Tell us what to add', 'daw' ); ?></a>
						</p>
					<?php endif; ?>

					<?php
					$social = function_exists( 'daw_social_links' ) ? daw_social_links() : array();

					if ( $social ) :
						?>
						<div class="daw-footer-follow">
							<span><?php esc_html_e( 'Follow us', 'daw' ); ?></span>
							<ul>
								<?php foreach ( $social as $key => $net ) : ?>
									<li>
										<a href="<?php echo esc_url( $net['url'] ); ?>" rel="noopener me" target="_blank" aria-label="<?php echo esc_attr( $net['label'] ); ?>" title="<?php echo esc_attr( $net['label'] ); ?>">
											<?php echo daw_social_icon( $key ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>

				<?php foreach ( daw_footer_columns() as $heading => $links ) : ?>
					<nav class="daw-footer-col" aria-label="<?php echo esc_attr( $heading ); ?>">
						<h2><?php echo esc_html( $heading ); ?></h2>
						<ul>
							<?php foreach ( $links as $link ) : ?>
								<li><a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a></li>
							<?php endforeach; ?>
							<?php if ( __( 'Legal', 'daw' ) === $heading ) : ?>
								<li><button type="button" class="daw-linkbtn" data-cmp-open><?php esc_html_e( 'Cookie preferences', 'daw' ); ?></button></li>
							<?php endif; ?>
						</ul>
					</nav>
				<?php endforeach; ?>
			</div>

			<div class="daw-footer-bottom">
				<p>
					<?php
					printf(
						/* translators: %d: current year */
						esc_html__( '© %d Discover Asian World. All rights reserved.', 'daw' ),
						(int) gmdate( 'Y' )
					);
					?>
				</p>
				<p><?php esc_html_e( 'Some links may earn us a commission. Where ads appear, they are served by Google AdSense.', 'daw' ); ?></p>
				<p class="daw-footer-fine">
					<?php
					esc_html_e(
						'Posters and photos of titles and people are shown through the TMDB API. They belong to the studios, broadcasters, distributors, agencies and photographers that made them, and are used only to identify the work or person. This product uses the TMDB API but is not endorsed or certified by TMDB. Streaming availability by country comes from JustWatch through TMDB. Release dates of Korean films are checked against the KOBIS Open API of the Korean Film Council (KOFIC). Rights holders can ask for any image to be removed at info@discoverasianworld.com.',
						'daw'
					);
					?>
				</p>
			</div>
		</div>
	</footer>

	<nav class="daw-tabbar" aria-label="<?php esc_attr_e( 'Sections', 'daw' ); ?>">
		<?php
		// Five destinations, but only ones that resolve. Browse goes to the
		// global drama page rather than the Korean one it used to point at:
		// the bar is the site's own table of contents, and sending every
		// reader to one country from it was a leftover from before the global
		// pages existed. More used to fall back to About — a tap on "More"
		// landing on an editorial page instead of more navigation read as a
		// bug, not a feature, and it was reported as one. It opens its own
		// sheet now (daw_more_sheet(), the mobile twin of the header's More
		// dropdown); About is still one tap away inside that sheet.
		$tabs = array_filter( array(
			array( 'home', __( 'Home', 'daw' ), home_url( '/' ) ),
			array( 'browse', __( 'Browse', 'daw' ), daw_page_url( 'dramas' ) ? daw_page_url( 'dramas' ) : daw_page_url( 'korean-drama' ) ),
			array( 'search', __( 'Search', 'daw' ), home_url( '/?s=' ) ),
			array( 'saved', __( 'Saved', 'daw' ), daw_page_url( 'saved' ) ),
			array( 'more', __( 'More', 'daw' ), daw_page_url( 'about' ) ),
		), function ( $tab ) {
			return ! empty( $tab[2] );
		} );

		foreach ( $tabs as $tab ) :
			list( $key, $label, $url ) = $tab;
			$current = ( 'home' === $key && is_front_page() ) || ( 'search' === $key && is_search() );
			// Browse and More both open a sheet rather than going to one of
			// the many things they could have meant. Both are still links
			// underneath, so a reader whose script never runs lands on the
			// drama list, or on About, exactly as before.
			$sheet_id = ( 'browse' === $key ) ? 'daw-browse-sheet' : ( ( 'more' === $key ) ? 'daw-more-sheet' : '' );
			$sheet    = $sheet_id ? ' data-daw-sheet-open="' . esc_attr( $sheet_id ) . '"' : '';
			?>
			<a href="<?php echo esc_url( $url ); ?>"<?php echo $current ? ' aria-current="page"' : ''; ?><?php echo $sheet; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<?php echo daw_tab_icon( $key ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span><?php echo esc_html( $label ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php
	if ( function_exists( 'daw_browse_sheet' ) ) {
		daw_browse_sheet();
	}
	if ( function_exists( 'daw_more_sheet' ) ) {
		daw_more_sheet();
	}
	?>

<?php
// BACK TO TOP
// -----------
// A record page runs long by design: a filmography, a profile table and a
// news list are each worth the height they take. What they cost is the trip
// back, and on a phone that trip is a dozen swipes. So one control, fixed to
// the corner, that appears only once the reader is far enough down for it to
// be worth anything.
//
// It is a link to #top rather than a scripted scroll, so it works before the
// javascript runs and keeps the browser's own smooth scrolling and its
// reduced-motion rule.
?>
<a class="daw-totop" href="#top" hidden data-totop aria-label="<?php esc_attr_e( 'Back to top', 'daw' ); ?>" title="<?php esc_attr_e( 'Back to top', 'daw' ); ?>">
	<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
		<path d="M12 19V5"/><path d="m5 12 7-7 7 7"/>
	</svg>
</a>

</div><!-- /.daw -->

<script>
/* Mobile menu. No framework, no dependency: a button, a panel and a scrim.
   The panel is positioned off the header rather than sitting in the flow, so
   opening it moves nothing behind it. Escape and a tap outside both close it,
   because a menu that can only be closed by the button that opened it is a
   menu people get stuck in. */
( function () {
	var btn   = document.querySelector( '.daw-burger' );
	var nav   = document.getElementById( 'daw-mobile-nav' );
	var scrim = document.querySelector( '[data-scrim]' );
	if ( ! btn || ! nav ) { return; }

	function setOpen( open ) {
		btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		nav.hidden = ! open;
		if ( scrim ) { scrim.hidden = ! open; }
	}

	btn.addEventListener( 'click', function () {
		setOpen( btn.getAttribute( 'aria-expanded' ) !== 'true' );
	} );

	if ( scrim ) {
		scrim.addEventListener( 'click', function () {
			setOpen( false );
			btn.focus();
		} );
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && btn.getAttribute( 'aria-expanded' ) === 'true' ) {
			setOpen( false );
			btn.focus();
		}
	} );

	/* Rotating to landscape can cross the breakpoint and leave a panel open
	   over a layout that no longer has a burger to close it with. */
	window.addEventListener( 'resize', function () {
		if ( btn.offsetParent === null ) { setOpen( false ); }
	} );
}() );

/* WHAT IS PINNED ABOVE THE PAGE, MEASURED RATHER THAN REMEMBERED
   --------------------------------------------------------------
   An anchored heading has to clear whatever is fixed to the top of the
   window, and on a logged-in screen that includes WordPress's own admin bar.
   Whether that bar is pinned or scrolls away depends on the screen width and
   on the WordPress release, so this reads the position the browser actually
   computed instead of hard-coding a number that is right on one screen and
   wrong on the next. The stylesheet does the rest through --daw-pinned: the
   jump bar sits below the measurement and every [id] is offset by it. */
( function () {
	/* Only a logged-in screen has the bar. For every reader the stylesheet's
	   0px is already right, and writing the variable anyway makes the browser
	   restyle the whole page (every [id], through a :has() rule) twice per
	   load. A film page measured 1.5 s of blocking time, nearly all of it
	   style and layout, with "forced reflow" flagged (6 Oct 2026). So the
	   property is written only when the value actually changes. */
	var bar = document.getElementById( 'wpadminbar' );
	if ( ! bar ) { return; }
	var last = 0;

	function pinned() {
		var h = window.getComputedStyle( bar ).position === 'fixed' ? bar.offsetHeight : 0;
		if ( h !== last ) {
			last = h;
			document.documentElement.style.setProperty( '--daw-pinned', h + 'px' );
		}
	}

	pinned();
	window.addEventListener( 'resize', pinned );
	window.addEventListener( 'load', pinned );
}() );
/* The back to top control. Hidden until the reader is a screen and a half
   down, because a button offering to undo a scroll nobody has made is just
   something in the way. The scroll handler is passive and does nothing but
   flip one attribute, so it costs nothing on a long page. */
( function () {
	var btn = document.querySelector( '[data-totop]' );
	if ( ! btn ) { return; }

	var shown = false;

	function check() {
		var want = window.scrollY > window.innerHeight * 1.5;
		if ( want === shown ) { return; }
		shown = want;
		btn.hidden = ! want;
	}

	/* No check() here, while the page is still being built: reading
	   scrollY forced the browser to lay out the whole page on the spot,
	   100 to 270 ms on a phone (PageSpeed "forced reflow", 6 Oct 2026). The
	   load and pageshow listeners below make the same first check once the
	   layout exists anyway. */
	window.addEventListener( 'scroll', check, { passive: true } );
	window.addEventListener( 'resize', check );
	/* A reader can arrive already a long way down: a shared link with #filmography
	   in it, or a back button restoring where they were. Neither of those is a
	   scroll event, so the button would stay hidden on exactly the page where
	   the reader is furthest from the top. */
	window.addEventListener( 'load', check );
	window.addEventListener( 'hashchange', check );
	window.addEventListener( 'pageshow', check );

	/* #top is the document itself. Moving focus there after the jump means a
	   keyboard reader carries on from the top of the page rather than from
	   the button they just left at the bottom. */
	btn.addEventListener( 'click', function () {
		var main = document.querySelector( 'main' ) || document.body;
		main.setAttribute( 'tabindex', '-1' );
		main.focus( { preventScroll: true } );
	} );
}() );
/* SHEETS
   ------
   Anything with data-daw-sheet-open names a panel that slides up from the
   bottom of a phone. Two of them live on the tab bar today, Browse and More,
   and the filter rail uses the same idea with its own code because it also
   has a form in it.

   The trigger stays a link, so a reader with no script follows it to a real
   page. The script only takes the click away once it knows it can open
   something better. */
( function () {
	var root = document.documentElement;

	function close( sheet ) {
		sheet.hidden = true;
		root.classList.remove( 'daw-sheet-on' );
		document.body.style.position = '';
		document.body.style.top = '';
		document.body.style.width = '';
		if ( sheet.dataset.at ) {
			window.scrollTo( 0, parseInt( sheet.dataset.at, 10 ) || 0 );
		}
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-daw-sheet-open]' ), function ( trigger ) {
		var sheet = document.getElementById( trigger.dataset.dawSheetOpen );
		if ( ! sheet ) { return; }

		trigger.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var at = window.scrollY || window.pageYOffset || 0;
			sheet.dataset.at = at;
			/* The page behind is pinned so it cannot scroll away under the
			   sheet, and put back exactly where it was on the way out. */
			document.body.style.position = 'fixed';
			document.body.style.top = ( -at ) + 'px';
			document.body.style.width = '100%';
			sheet.hidden = false;
			root.classList.add( 'daw-sheet-on' );
			var first = sheet.querySelector( 'a, button' );
			if ( first ) { first.focus( { preventScroll: true } ); }
		} );

		sheet.addEventListener( 'click', function ( e ) {
			if ( e.target === sheet || e.target.closest( '[data-daw-sheet-close]' ) ) {
				e.preventDefault();
				close( sheet );
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! sheet.hidden ) { close( sheet ); }
		} );
	} );
}() );
</script>

<?php wp_footer(); ?>
</body>
</html>
