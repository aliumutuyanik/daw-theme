<?php
/**
 * Single record: news.
 *
 * WHY THIS PAGE IS SHAPED THE WAY IT IS
 * -------------------------------------
 * A news story about someone else's reporting is legally ours only if we wrote
 * it. So the page keeps two things apart that most aggregators blur:
 *
 *   what an outlet reported  -> named, linked, and credited at the top
 *   what this page states    -> written here, in our own words, and limited to
 *                               what is actually confirmed
 *
 * That is the whole defence, and it is structural rather than a line of small
 * print: there is no field anywhere that holds the original article's text, the
 * source box sends the reader to the outlet rather than standing in for it, and
 * the "What is confirmed" block exists so that the page can say plainly which
 * parts are established and which are not.
 *
 * A developing story keeps one url and grows a timeline row. It never becomes a
 * second page competing with the first, which is both better for the reader and
 * the only way the url promise this site is built on survives a news section.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$id = get_the_ID();

	$kicker     = daw_field( 'daw_news_kicker' );
	$standfirst = daw_field( 'daw_news_standfirst' );
	$confirmed  = daw_field( 'daw_news_confirmed' );
	$sources    = function_exists( 'daw_get_news_sources' ) ? daw_get_news_sources( $id ) : array();
	$timeline   = function_exists( 'daw_get_news_timeline' ) ? daw_get_news_timeline( $id ) : array();
	$timeline   = array_values(
		array_filter(
			$timeline,
			static function ( $row ) {
				return '' !== trim( (string) $row['event'] );
			}
		)
	);

	// WHAT THIS STORY IS ABOUT IS READ OUT OF THE STORY
	// -------------------------------------------------
	// Not only out of the box an editor ticked. daw_news_mentions_cached()
	// returns what was ticked plus every record the prose already names, so a
	// story that says Undercover High School reaches that record whether or not
	// anyone remembered to link it, and that record shows this story back.
	$mentions = function_exists( 'daw_news_mentions_cached' )
		? daw_news_mentions_cached( $id )
		: array_filter( array_map( 'intval', (array) daw_field( 'daw_mentions', $id, false ) ) );
	$country  = daw_terms( 'dw_country' );

	// A reading time is a promise about effort, so it is counted from the words
	// actually on the page rather than typed in and left to rot.
	$words   = str_word_count( wp_strip_all_tags( get_the_content() . ' ' . $confirmed ) );
	$minutes = max( 1, (int) ceil( $words / 220 ) );

	// The last real update row, never the last save (SEO spec item 5).
	$updated = function_exists( 'daw_news_updated_ts' ) ? daw_news_updated_ts( $id ) : (int) get_post_modified_time( 'U', true, $id );
	$posted  = (int) get_post_time( 'U', true, $id );
	?>

<div class="daw-wrap">
	<?php daw_crumb_nav( $id ); ?>
</div>

<?php // ========================================================= hero ==== ?>
<header class="daw-nhero">
	<div class="daw-wrap daw-nhero-in">
		<div class="daw-nhero-txt">
		<?php if ( $kicker ) : ?>
			<p class="daw-nkicker"><?php echo esc_html( $kicker ); ?></p>
		<?php endif; ?>

		<h1 class="daw-ntitle"><?php the_title(); ?></h1>

		<?php if ( trim( (string) $standfirst ) !== '' ) : ?>
			<p class="daw-nstand"><?php echo esc_html( wp_strip_all_tags( $standfirst ) ); ?></p>
		<?php endif; ?>

		<div class="daw-nmeta">
			<?php
			// THE BYLINE IS THE NEWSROOM, NOT A PERSON
			// ----------------------------------------
			// Every story here is a desk write-up from cited reporting, so a
			// named author would claim reporting that nobody here did. The desk
			// takes responsibility, and the correction link at the foot of the
			// page is how a reader reaches it.
			?>
			<span class="daw-nbyline"><?php esc_html_e( 'DAW newsroom', 'daw' ); ?></span>
			<span aria-hidden="true">·</span>
			<time datetime="<?php echo esc_attr( gmdate( 'c', $posted ) ); ?>">
				<?php
				// The label says KST, so the clock has to be Seoul's. The site
				// runs on UTC, and date_i18n() printed UTC under a KST label:
				// every story's time was nine hours early.
				echo esc_html( wp_date( 'j F Y, H:i', $posted, new DateTimeZone( 'Asia/Seoul' ) ) );
				?> KST
			</time>
			<span aria-hidden="true">·</span>
			<span><?php printf( esc_html( _n( '%d min read', '%d min read', $minutes, 'daw' ) ), (int) $minutes ); ?></span>
			<?php if ( $updated > $posted + HOUR_IN_SECONDS ) : ?>
				<span aria-hidden="true">·</span>
				<span class="daw-nupdated">
					<?php
					printf(
						/* translators: %s: date the story was last updated */
						esc_html__( 'Updated %s', 'daw' ),
						esc_html( wp_date( 'j F Y', $updated, new DateTimeZone( 'Asia/Seoul' ) ) )
					);
					?>
				</span>
			<?php endif; ?>
		</div>
		</div>

		<?php
		// THE PICTURE BELONGS BESIDE THE HEADLINE, NOT UNDER IT
		// -----------------------------------------------------
		// A news headline is set to about twenty seven characters a line, which
		// on a wide screen leaves most of the band beside it empty. Dropping the
		// picture into that space costs no vertical room at all and gives the
		// reader the face the story is about before the first sentence, which is
		// what a news picture is for.
		//
		// Below 900px there is no space beside anything, so it moves under the
		// standfirst instead, still above the text. The box is drawn from the
		// aspect ratio before the file arrives, so nothing shifts when it loads,
		// and the credit sits under it because we publish no picture we cannot
		// attribute.
		if ( has_post_thumbnail( $id ) ) :
			$credit     = daw_field( 'daw_src_rights' );
			$lic_credit = function_exists( 'daw_lic_credit_html' ) ? daw_lic_credit_html( get_post_thumbnail_id( $id ) ) : '';
			?>
			<figure class="daw-nfig">
				<?php echo get_the_post_thumbnail( $id, 'medium_large', array( 'loading' => 'eager', 'fetchpriority' => 'high', 'sizes' => '(max-width: 900px) calc(100vw - 32px), 320px' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php if ( '' !== $lic_credit ) : ?>
					<figcaption><?php echo wp_kses( $lic_credit, array( 'a' => array( 'href' => true, 'rel' => true, 'target' => true ) ) ); ?></figcaption>
				<?php elseif ( $credit ) : ?>
					<figcaption><?php echo esc_html( $credit ); ?></figcaption>
				<?php endif; ?>
			</figure>
		<?php endif; ?>
	</div>
</header>

<div class="daw-wrap">
	<div class="daw-ncols daw-section">

		<?php ob_start(); ?>
		<div class="daw-maincol">
			<?php if ( $sources ) : ?>
				<div class="daw-nsource">
					<span class="daw-nsource-mark" aria-hidden="true">✶</span>
					<p>
						<strong><?php esc_html_e( 'Source:', 'daw' ); ?></strong>
						<?php
						$parts = array();
						foreach ( $sources as $src ) {
							$name = esc_html( $src['name'] );
							$url  = trim( (string) $src['url'] );

							$parts[] = $url
								? sprintf(
									'%s · <a href="%s" target="_blank" rel="noopener nofollow">%s</a>',
									$name,
									esc_url( $url ),
									esc_html__( 'Read the original report', 'daw' )
								)
								: $name;
						}
						echo implode( ' &nbsp;|&nbsp; ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php
			// A living story opens with what is confirmed to come (1.75.0).
			if ( function_exists( 'daw_news_next_html' ) ) {
				echo daw_news_next_html( $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped where it is built.
			}
			?>

			<div class="daw-prose daw-nbody">
				<?php the_content(); ?>
			</div>

			<?php if ( trim( (string) $confirmed ) !== '' || $timeline ) : ?>
				<section class="daw-nconfirm" id="what-is-confirmed">
					<h2><?php esc_html_e( 'What is confirmed', 'daw' ); ?></h2>

					<?php
					// Records named in the box and the timeline link to their
					// pages, each once (daw_news_autolink, functions.php).
					$nl_used = array();
					$nl_ids  = function_exists( 'daw_news_autolink' ) ? array_map( 'intval', (array) $mentions ) : array();
					?>
					<?php if ( trim( (string) $confirmed ) !== '' ) : ?>
						<div class="daw-prose"><?php echo $nl_ids ? wp_kses_post( daw_news_autolink( wp_kses_post( $confirmed ), $nl_ids, $nl_used ) ) : wp_kses_post( $confirmed ); ?></div>
					<?php endif; ?>

					<?php if ( $timeline ) : ?>
						<ol class="daw-ntl">
							<?php foreach ( $timeline as $row ) : ?>
								<li>
									<?php if ( (int) $row['date'] ) : ?>
										<span class="daw-ntl-d"><?php echo esc_html( date_i18n( 'j F Y', (int) $row['date'] ) ); ?></span>
									<?php endif; ?>
									<span class="daw-ntl-e"><?php echo $nl_ids ? wp_kses_post( daw_news_autolink( esc_html( $row['event'] ), $nl_ids, $nl_used ) ) : esc_html( $row['event'] ); ?></span>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php daw_ad_slot( 'daw-ad-billboard' ); ?>

			<?php if ( $mentions ) : ?>
				<section class="daw-nment">
					<h2 class="daw-h3"><?php esc_html_e( 'Mentioned in this story', 'daw' ); ?></h2>
					<div class="daw-nment-row">
						<?php
						foreach ( $mentions as $mid ) :
							if ( 'publish' !== get_post_status( $mid ) ) {
								continue;
							}
							$mtype = daw_get_type( $mid );
							$myear = (int) daw_field_raw( $mid, 'daw_aired_start' );
							// A person or a group is described by what they do; a
							// title by what kind it is and when it aired.
							if ( in_array( $mtype, array( 'person', 'group' ), true ) ) {
								$mline = daw_field( 'daw_occupation', $mid );
								if ( '' === trim( (string) $mline ) ) {
									$mline = daw_kind_label( $mtype );
								}
							} else {
								$mline = trim( daw_kind_label( $mtype ) . ( $myear ? ' · ' . gmdate( 'Y', $myear ) : '' ) );
								$mline = trim( $mline, " ·" );
							}
							?>
							<a class="daw-nment-card" href="<?php echo esc_url( get_permalink( $mid ) ); ?>">
								<span class="daw-nment-face"><?php echo daw_poster( $mid, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<span class="daw-nment-txt">
									<strong><?php echo esc_html( get_the_title( $mid ) ); ?></strong>
									<?php if ( $mline ) : ?>
										<small><?php echo esc_html( $mline ); ?></small>
									<?php endif; ?>
								</span>
							</a>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<?php
			// The standing note. It is not a disclaimer bolted on to excuse the
			// page; it describes what the page actually does, which is why it
			// can be short and specific.
			$correction = daw_page_url( 'request-a-correction' );
			?>
			<p class="daw-nnote">
				<?php esc_html_e( 'This report is an original write-up based on the statements cited above. Claims that no named source has confirmed are not reported as fact, and the page shows a new date whenever it is updated.', 'daw' ); ?>
				<?php if ( $correction ) : ?>
					<a href="<?php echo esc_url( $correction ); ?>"><?php esc_html_e( 'Request a correction', 'daw' ); ?></a>
				<?php endif; ?>
			</p>
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
			// THE RAIL HAS TO TRAVEL WITH THE READER
			// --------------------------------------
			// A grid item with align-items:start is only as tall as its own
			// content, so position:sticky on it has nowhere to move and the
			// right half of a long story is empty from the second screen on.
			// The column is stretched to the full row height instead and this
			// inner box is the sticky one, so Latest news and the rail ad stay
			// beside the text the whole way down.
			?>
			<div class="daw-rail-in">
			<?php
			$latest = get_posts(
				array(
					'post_type'      => 'post',
					'posts_per_page' => 5,
					'post__not_in'   => array( $id ),
					'no_found_rows'  => true,
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'taxonomy' => 'dw_type',
							'field'    => 'slug',
							'terms'    => 'news',
						),
					),
				)
			);

			if ( $latest ) :
				?>
				<div class="daw-rcard">
					<div class="daw-rcard-h"><?php esc_html_e( 'Latest news', 'daw' ); ?></div>
					<div class="daw-rcard-b">
						<ul class="daw-nlatest">
							<?php foreach ( $latest as $n ) : ?>
								<li><a href="<?php echo esc_url( get_permalink( $n ) ); ?>"><?php echo esc_html( get_the_title( $n ) ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			<?php endif; ?>

			<?php daw_ad_rail( $main_html ); ?>
			</div>
		</aside>
	</div>
</div>

<?php
// More from the same country, because a reader who came for one Korean casting
// story is in the market for the next one, and this is the cheapest place on
// the site to offer it.
$more = get_posts(
	array(
		'post_type'      => 'post',
		'posts_per_page' => 4,
		'post__not_in'   => array_merge( array( $id ), wp_list_pluck( $latest, 'ID' ) ),
		'no_found_rows'  => true,
		'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
			array(
				'taxonomy' => 'dw_type',
				'field'    => 'slug',
				'terms'    => 'news',
			),
		),
	)
);

$news_hub = daw_page_url( 'news' );

if ( $more ) :
	?>
	<section class="daw-nmore">
		<div class="daw-wrap">
			<div class="daw-nmore-head">
				<h2 class="daw-h3"><?php esc_html_e( 'More news', 'daw' ); ?></h2>
				<?php if ( $news_hub ) : ?>
					<a href="<?php echo esc_url( $news_hub ); ?>"><?php esc_html_e( 'All news', 'daw' ); ?></a>
				<?php endif; ?>
			</div>
			<div class="daw-nmore-row">
				<?php foreach ( $more as $n ) : ?>
					<a class="daw-nmore-card" href="<?php echo esc_url( get_permalink( $n ) ); ?>">
						<span class="daw-nmore-face"><?php echo daw_poster( $n->ID, 'medium', '(max-width: 520px) calc(100vw - 32px), 300px' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<span class="daw-nmore-k"><?php echo esc_html( daw_field( 'daw_news_kicker', $n->ID ) ); ?></span>
						<strong><?php echo esc_html( get_the_title( $n ) ); ?></strong>
						<small><?php echo esc_html( function_exists( 'daw_news_card_date' ) ? daw_news_card_date( $n ) : get_the_date( 'j F Y', $n ) ); ?></small>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
<?php endif; ?>

<?php
endwhile;

get_footer();
