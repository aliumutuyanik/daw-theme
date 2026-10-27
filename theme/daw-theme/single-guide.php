<?php
/**
 * The guide record: culture, food and drink, myths, travel.
 *
 * Reached through the single_template filter in DAW Core when dw_type is one of
 * the guide types.
 *
 * WHAT THIS PAGE IS FOR
 * ---------------------
 * Somebody watched a drama, saw a red dish in a paper cup, and typed the name
 * into a search box at eleven at night. They are not looking for a recipe blog
 * and they are not looking for an encyclopaedia. They want to know what it is,
 * why it is there, and where else they have seen it. So the page answers in
 * that order: a standfirst that states the thing, an article that explains it,
 * a fact table for the details nobody reads a paragraph for, and a row of the
 * titles it appears in, which is the door back into the rest of the site.
 *
 * THE ARTICLE IS THE POST CONTENT
 * -------------------------------
 * Unlike a drama or a person, a guide has no fixed shape, so the prose stays in
 * the editor and this template arranges what surrounds it. The contents list is
 * read out of the headings that are really there, and the fact table and the
 * in-article advert are placed by the same rule on every record instead of by
 * hand on each one.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$id         = get_the_ID();
	$type       = function_exists( 'daw_get_type' ) ? daw_get_type( $id ) : '';
	$kicker     = function_exists( 'daw_guide_kicker' ) ? daw_guide_kicker( $id ) : '';
	$standfirst = daw_field( 'daw_guide_standfirst', $id );
	$native     = daw_field( 'daw_guide_native', $id );
	$credit     = daw_field( 'daw_guide_hero_credit', $id );
	$glance     = function_exists( 'daw_get_glance' ) ? daw_get_glance( $id ) : array();
	$onscreen   = function_exists( 'daw_get_onscreen' ) ? daw_get_onscreen( $id ) : array();
	// The structured list (food on screen) replaces the older hand typed row of
	// titles when it has something to show, so one page never answers "where
	// have I seen this" twice. Rows typed the old way stay and still print when
	// the new list is empty.
	$seen_in    = function_exists( 'daw_fos_seen_list' ) ? daw_fos_seen_list( $id ) : array();
	if ( $seen_in && function_exists( 'daw_fos_covered' ) ) {
		$covered  = daw_fos_covered( $id );
		$onscreen = array_values(
			array_filter(
				$onscreen,
				static function ( $row ) use ( $covered ) {
					return ! in_array( (int) $row['id'], $covered, true );
				}
			)
		);
	}
	$sources    = function_exists( 'daw_get_guide_sources' ) ? daw_get_guide_sources( $id ) : array();
	$faq        = function_exists( 'daw_get_faq' ) ? daw_get_faq( $id ) : array();
	$shop       = function_exists( 'daw_get_shop' ) ? daw_get_shop( $id ) : array();
	$shop       = array_values(
		array_filter(
			$shop,
			static function ( $row ) {
				return '' !== trim( (string) $row['title'] ) && '' !== trim( (string) $row['url'] );
			}
		)
	);
	$related    = function_exists( 'daw_get_related_guides' ) ? daw_get_related_guides( $id, 4 ) : array();
	$minutes    = function_exists( 'daw_guide_read_time' ) ? daw_guide_read_time( $id ) : 0;
	$updated    = (int) get_post_modified_time( 'U', true, $id );
	$country    = function_exists( 'daw_terms' ) ? daw_terms( 'dw_country', $id ) : '';

	/* ------------------------------------------------------------- body -- */

	// The content is filtered once, then read for its headings and written back
	// with ids on them, so the contents list and the article cannot disagree.
	$body = apply_filters( 'the_content', get_the_content( null, false, $id ) );
	$toc  = array( 'html' => $body, 'rows' => array() );

	if ( function_exists( 'daw_guide_contents' ) ) {
		$toc = daw_guide_contents( $body );
	}

	$body = $toc['html'];

	if ( $glance && function_exists( 'daw_guide_inject' ) ) {
		ob_start();
		?>
		<div class="daw-glance">
			<div class="daw-glance-h"><?php esc_html_e( 'At a glance', 'daw' ); ?></div>
			<dl>
				<?php foreach ( $glance as $row ) : ?>
					<div>
						<dt><?php echo esc_html( $row['label'] ); ?></dt>
						<dd><?php echo esc_html( $row['value'] ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		</div>
		<?php
		$body = daw_guide_inject( $body, (string) ob_get_clean(), 2 );
	}

	// THE AD IS SPLICED IN AFTER THE SAFETY FILTER, NOT BEFORE IT
	// ------------------------------------------------------------
	// $body is echoed later through wp_kses_post(), which is right: the post
	// content itself is user-editable and needs that pass. But wp_kses_post()
	// does not know <script> as an allowed tag, so when the ad's markup
	// (including its inline <script>) was spliced in here, first, the filter
	// stripped the tag and left its JS source sitting in the page as plain,
	// readable text — the ad box rendered, the "script" beside it did not
	// execute, it just sat there as a paragraph of code. The fix leaves a
	// plain-text marker in $body for kses to pass through untouched, and the
	// real ad markup is swapped back in afterwards, once kses is done.
	$daw_ad_incontent_html  = '';
	$daw_ad_incontent_token = '';
	if ( function_exists( 'daw_ad_slot' ) && function_exists( 'daw_guide_inject' ) ) {
		ob_start();
		daw_ad_slot( 'daw-ad-incontent' );
		$daw_ad_incontent_html = (string) ob_get_clean();

		if ( '' !== trim( $daw_ad_incontent_html ) ) {
			$daw_ad_incontent_token = 'DAW_AD_INCONTENT_TOKEN_' . wp_generate_password( 12, false, false );
			$body                   = daw_guide_inject( $body, $daw_ad_incontent_token, 4 );
		}
	}
	?>

<div class="daw daw-guide-page">

	<?php
	// THE TRAIL SITS WHERE IT SITS ON EVERY OTHER PAGE
	// -----------------------------------------------
	// It used to be inside the hero box, beside the photograph, on the hero's
	// own tint. Every other template in the site puts it above the hero on the
	// page's ground, so a reader moving between a guide and a category page saw
	// the trail jump. One place, one look.
	?>
	<div class="daw-wrap">
		<?php daw_crumb_nav( $id ); ?>
	</div>

	<header class="daw-ghero">
		<div class="daw-wrap daw-ghero-in">
			<div class="daw-ghero-txt">

			<?php if ( $kicker ) : ?>
				<p class="daw-gkicker"><?php echo esc_html( $kicker ); ?></p>
			<?php endif; ?>

			<h1 class="daw-gtitle"><?php the_title(); ?></h1>

			<?php if ( trim( (string) $native ) !== '' ) : ?>
				<p class="daw-gnative"><?php echo esc_html( $native ); ?></p>
			<?php endif; ?>

			<?php if ( trim( (string) $standfirst ) !== '' ) : ?>
				<p class="daw-gstand"><?php echo esc_html( wp_strip_all_tags( $standfirst ) ); ?></p>
			<?php endif; ?>

			<div class="daw-gmeta">
				<?php
				// THE BYLINE IS THE DESK
				// ----------------------
				// These are compiled from institutional sources, not reported by
				// a named writer, and a byline that says otherwise is a claim
				// about a person. The correction link at the foot is how a
				// reader reaches whoever is responsible.
				?>
				<span class="daw-gbyline"><?php esc_html_e( 'DAW editorial team', 'daw' ); ?></span>
				<span aria-hidden="true">·</span>
				<time datetime="<?php echo esc_attr( gmdate( 'c', $updated ) ); ?>">
					<?php
					printf(
						/* translators: %s: date the guide was last updated */
						esc_html__( 'Updated %s', 'daw' ),
						esc_html( date_i18n( 'j F Y', $updated ) )
					);
					?>
				</time>
				<?php if ( $minutes ) : ?>
					<span aria-hidden="true">·</span>
					<span><?php printf( esc_html( _n( '%d min read', '%d min read', $minutes, 'daw' ) ), (int) $minutes ); ?></span>
				<?php endif; ?>
			</div>
			</div>

			<?php
			// THE PICTURE SITS BESIDE THE HEADLINE, NOT UNDER IT
			// --------------------------------------------------
			// A full-width band across the top looked generous and cost the
			// page its opening: three hundred pixels of photograph between the
			// headline and the first sentence, so a reader on a laptop had to
			// scroll before the article began. Beside the headline, the picture
			// and the first paragraph land on the same screen, which is where a
			// newspaper has always put them. On a phone there is no beside, so
			// it goes directly under the headline and before the standfirst.
			//
			// Either way the box is drawn from its aspect ratio before the file
			// arrives, so nothing moves when it loads.
			?>
			<figure class="daw-gfig">
				<?php if ( has_post_thumbnail( $id ) ) : ?>
					<?php echo get_the_post_thumbnail( $id, 'large', array( 'loading' => 'eager', 'decoding' => 'async', 'fetchpriority' => 'high', 'sizes' => '(max-width: 980px) calc(100vw - 32px), 360px' ) ); ?>
				<?php endif; ?>
				<?php
				// The rights record builds the credit with its links; the old
				// free-text field is only a fallback for images not yet recorded.
				$lic_credit = function_exists( 'daw_lic_credit_html' ) ? daw_lic_credit_html( get_post_thumbnail_id( $id ) ) : '';
				if ( '' !== $lic_credit ) :
					?>
					<figcaption><?php echo wp_kses( $lic_credit, array( 'a' => array( 'href' => true, 'rel' => true, 'target' => true ) ) ); ?></figcaption>
				<?php elseif ( trim( (string) $credit ) !== '' ) : ?>
					<figcaption><?php echo esc_html( $credit ); ?></figcaption>
				<?php endif; ?>
			</figure>
		</div>
	</header>

	<div class="daw-wrap">

		<div class="daw-gcols daw-section">

			<aside class="daw-gtoc">
				<?php if ( $toc['rows'] ) : ?>
					<div class="daw-rcard">
						<div class="daw-rcard-h"><?php esc_html_e( 'Contents', 'daw' ); ?></div>
						<div class="daw-rcard-b">
							<ul>
								<?php foreach ( $toc['rows'] as $row ) : ?>
									<li><a href="#<?php echo esc_attr( $row['id'] ); ?>"><?php echo esc_html( $row['text'] ); ?></a></li>
								<?php endforeach; ?>
								<?php if ( $faq ) : ?>
									<li><a href="#faq"><?php esc_html_e( 'FAQ', 'daw' ); ?></a></li>
								<?php endif; ?>
							</ul>
						</div>
					</div>
				<?php endif; ?>
			</aside>

			<?php
			ob_start();

			// The marker left above survives wp_kses_post() because it is plain
			// text, no angle brackets for the filter to touch. Once the filter
			// has run, the real ad markup (script tag included) goes back in.
			$daw_gbody_html = wp_kses_post( $body );
			if ( '' !== $daw_ad_incontent_token ) {
				$daw_gbody_html = str_replace( $daw_ad_incontent_token, $daw_ad_incontent_html, $daw_gbody_html );
			}
			?>
			<div class="daw-maincol">
				<div class="daw-prose daw-gbody"><?php echo $daw_gbody_html; ?></div>

				<?php
				// A quiz is an article with something to play inside it. The
				// block sits after the writing that sets it up and before the
				// sections that follow from it.
				if ( function_exists( 'daw_quiz_block' ) ) {
					daw_quiz_block( $id );
				}
				?>

				<?php if ( $onscreen ) : ?>
					<section id="on-screen" class="daw-gsection">
						<h2><?php esc_html_e( 'Where you have seen it on screen', 'daw' ); ?></h2>
						<div class="daw-rail-row">
							<?php
							foreach ( $onscreen as $row ) :
								$oid  = (int) $row['id'];
								$oyr  = (int) daw_field( 'daw_aired_start', $oid );
								$onet = function_exists( 'daw_terms' ) ? daw_terms( 'dw_network', $oid ) : '';
								$line = array_filter(
									array(
										$oyr ? gmdate( 'Y', $oyr ) : '',
										$onet ? explode( ', ', $onet )[0] : '',
										trim( (string) $row['note'] ),
									)
								);
								?>
								<a class="daw-person" href="<?php echo esc_url( get_permalink( $oid ) ); ?>">
									<div class="daw-poster"><?php echo daw_poster( $oid, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
									<div class="daw-person-name"><?php echo esc_html( get_the_title( $oid ) ); ?></div>
									<?php if ( $line ) : ?>
										<div class="daw-person-role"><?php echo esc_html( implode( ' · ', $line ) ); ?></div>
									<?php endif; ?>
								</a>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

				<?php
				// SEEN IN: the end of the article text, before the questions.
				if ( $seen_in && function_exists( 'daw_food_seen_section' ) ) {
					daw_food_seen_section( $seen_in );
				}
				?>

				<?php if ( $faq ) : ?>
					<section id="faq" class="daw-gsection">
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
				<?php endif; ?>

				<?php
				// WHO CHECKED THIS, AND AGAINST WHAT
				// ----------------------------------
				// A page that states when a dish reached a street in Seoul is
				// making a historical claim, and a historical claim with nothing
				// behind it is somebody's guess repeated confidently. The desk
				// box and the source list are the two halves of the answer: who
				// is responsible, and what they read.
				?>
				<footer class="daw-gdesk">
					<div class="daw-gdesk-row">
						<span class="daw-gdesk-mark" aria-hidden="true">✶</span>
						<div>
							<strong><?php esc_html_e( 'Discover Asian World editorial team', 'daw' ); ?></strong>
							<p><?php esc_html_e( 'Guides are checked against local sources and primary records, and every update is published with its date. Corrections are welcome.', 'daw' ); ?></p>
						</div>
					</div>

					<?php if ( $sources ) : ?>
						<p class="daw-gsources">
							<strong><?php esc_html_e( 'Sources:', 'daw' ); ?></strong>
							<?php
							$out = array();
							foreach ( $sources as $src ) {
								$out[] = trim( (string) $src['url'] ) !== ''
									? sprintf(
										'<a href="%s" target="_blank" rel="noopener nofollow">%s</a>',
										esc_url( $src['url'] ),
										esc_html( $src['name'] )
									)
									: esc_html( $src['name'] );
							}
							echo wp_kses_post( implode( ' · ', $out ) );
							?>
						</p>
					<?php endif; ?>

					<?php
					// The images on a guide are decoration and the reader can see
					// that. Labelling them illustrative was a line of type doing no
					// work, and it was the same note that had already been taken off
					// the hero. What stays is the correction link, which is the only
					// part of this footer a reader can act on.
					$fix = function_exists( 'daw_page_url' ) ? daw_page_url( 'request-a-correction' ) : '';
					if ( $fix ) :
						?>
						<p class="daw-empty" style="font-size:.76rem;margin:8px 0 0">
							<a href="<?php echo esc_url( $fix ); ?>"><?php esc_html_e( 'Request a correction', 'daw' ); ?></a>
						</p>
					<?php endif; ?>
				</footer>
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
				<div class="daw-rail-in">
					<?php if ( $related ) : ?>
						<div class="daw-rcard">
							<div class="daw-rcard-h"><?php esc_html_e( 'Related guides', 'daw' ); ?></div>
							<div class="daw-rcard-b">
								<ul>
									<?php foreach ( $related as $rel ) : ?>
										<li><a href="<?php echo esc_url( get_permalink( $rel ) ); ?>"><?php echo esc_html( get_the_title( $rel ) ); ?></a></li>
									<?php endforeach; ?>
								</ul>
							</div>
						</div>
					<?php endif; ?>

					<?php daw_ad_rail( $main_html ); ?>
				</div>
			</aside>
		</div>

		<?php if ( $shop ) : ?>
			<section class="daw-section daw-shop" id="at-home">
				<div class="daw-shop-head">
					<h2>
						<?php
						echo 'food' === $type
							? esc_html__( 'Make it at home', 'daw' )
							: esc_html__( 'Related things to buy', 'daw' );
						?>
					</h2>
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
					<?php esc_html_e( 'We may earn a commission from purchases made through these links. It costs you nothing extra. Prices and availability are set on the retailer\'s page, not here.', 'daw' ); ?>
				</p>
			</section>
		<?php endif; ?>

	</div>
</div>

	<?php
endwhile;

get_footer();
