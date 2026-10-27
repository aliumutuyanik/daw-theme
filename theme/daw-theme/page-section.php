<?php
/**
 * A section page: one record type, one country, all of it.
 *
 * WHAT THIS PAGE IS FOR
 * ---------------------
 * The country hub shows six dramas and says "All 23 Thai dramas". This is the
 * page that link was promising. Without it the hub is a menu whose items do
 * not open, and the reader who wanted the list has nowhere to go but the
 * search box.
 *
 * WHY IT IS DRAWN AND NOT WRITTEN
 * -------------------------------
 * /korean-drama/ is the old answer to this problem: a quarter of a megabyte of
 * cards placed by hand, correct on the day it was typed and wrong by the end of
 * that week. Eleven more pages like it is eleven more lists to forget to
 * update. So this counts at render time, from the same query and the same cards
 * the hub uses, which is also why the two pages look like one site.
 *
 * WHICH PAGES GET IT
 * ------------------
 * Only the ones with nothing in the body. daw_section_is_live() explains the
 * reasoning; the short version is that a page created empty was created to be
 * filled by this file, and a page with words in it belongs to whoever wrote
 * them. Nothing here can delete anybody's work.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();

$section = function_exists( 'daw_section_for_page' ) ? daw_section_for_page() : null;

if ( ! $section ) {
	get_footer();
	return;
}

$country   = $section['country'];
$type      = $section['type'];
$name      = $section['name'];
$short     = $section['short'];
$adjective = $section['adjective'];

$hub_url  = function_exists( 'daw_page_url' ) ? daw_page_url( $section['hub'] ) : '';
$counts   = daw_hub_counts( $country );
$total    = (int) ( $counts[ $type ] ?? 0 );
$rows     = function_exists( 'daw_section_records' ) ? daw_section_records( $country, $type ) : array();

// THE SAME BODY /dramas/ DRAWS
// ---------------------------
// This page is /dramas/ with the country already answered. It gets the same
// sort bar, the same filters and the same pager, minus the country filter it is
// locked to, so a reader who wants Korean romance never has to leave.
$browse_cfg = function_exists( 'daw_browse_cfg_for_type' ) ? daw_browse_cfg_for_type( $type ) : null;

if ( $browse_cfg ) {
	$browse_filters            = daw_browse_request();
	$browse_filters['country'] = array( $country );
	$browse_ids                = daw_browse_ids( $browse_cfg['types'], $browse_filters );
	$rows                      = $browse_ids;
}
$sections = function_exists( 'daw_hub_sections' ) ? daw_hub_sections() : array();
$style    = $sections[ $type ]['style'] ?? 'poster';

// The label for this type, in the plural, as a reader would say it. Taken from
// the same list the hub navigation uses so a type renamed there is renamed
// here, rather than drifting into two different words for one thing.
$labels = array(
	'drama'   => __( 'dramas', 'daw' ),
	'film'    => __( 'films', 'daw' ),
	'person'  => __( 'actors', 'daw' ),
	'group'   => __( 'pop groups', 'daw' ),
	'anime'   => __( 'animation', 'daw' ),
	'manga'   => __( 'comics', 'daw' ),
	'food'    => __( 'food and drink guides', 'daw' ),
	'culture' => __( 'culture guides', 'daw' ),
	'myth'    => __( 'myths and folklore', 'daw' ),
	'travel'  => __( 'travel guides', 'daw' ),
	'news'    => __( 'news', 'daw' ),
);
$label = $labels[ $type ] ?? __( 'records', 'daw' );

// THE TILE COUNTS ONE THING SOMETIMES
// -----------------------------------
// A country with a single culture guide was being told it had "1 culture
// guides", which is the kind of sentence that makes a reader trust the numbers
// less than they should. The singular is a second list rather than a rule,
// because English plurals are not a rule.
$labels_one = array(
	'drama'   => __( 'drama', 'daw' ),
	'film'    => __( 'film', 'daw' ),
	'person'  => __( 'actor', 'daw' ),
	'group'   => __( 'pop group', 'daw' ),
	'anime'   => __( 'animation', 'daw' ),
	'manga'   => __( 'comic', 'daw' ),
	'food'    => __( 'food and drink guide', 'daw' ),
	'culture' => __( 'culture guide', 'daw' ),
	'myth'    => __( 'myth', 'daw' ),
	'travel'  => __( 'travel guide', 'daw' ),
	'news'    => __( 'news story', 'daw' ),
);

// "Thai dramas" reads correctly. "Thailand dramas" does not, and "People from
// Thai" is not English either, so the two shapes are kept apart rather than
// one being bent to cover both.
$heading = 'person' === $type
	/* translators: %s: short country name, e.g. Thailand */
	? sprintf( __( 'Actors from %s', 'daw' ), $short )
	/* translators: 1: nationality adjective, 2: plural type label */
	: sprintf( __( '%1$s %2$s', 'daw' ), $adjective, $label );

// The heading follows the filters the same way the browse page's does, so a
// reader who has narrowed this page to romance is told so at the top rather
// than having to read the sidebar back to themselves.
if ( $browse_cfg && function_exists( 'daw_browse_heading' ) && daw_browse_is_filtered( $browse_filters ) ) {
	$narrowed = daw_browse_heading(
		array_merge( $browse_cfg, array( 'many' => $label, 'title' => $heading ) ),
		array_merge( $browse_filters, array( 'country' => array() ) ),
		'person' === $type ? '' : $adjective
	);

	// The person heading already ends in "from Korea", so it is not given a
	// second one. That printed "Actors from Korea from Korea" for one build.
	if ( $narrowed ) {
		$heading = $narrowed;
	}
}
?>

<div class="daw-wrap">
	<nav class="daw-crumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'daw' ); ?>">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'daw' ); ?></a>
		<span aria-hidden="true">›</span><span><?php esc_html_e( 'Countries', 'daw' ); ?></span>
		<?php if ( $hub_url ) : ?>
			<span aria-hidden="true">›</span><a href="<?php echo esc_url( $hub_url ); ?>"><?php echo esc_html( $name ); ?></a>
		<?php else : ?>
			<span aria-hidden="true">›</span><span><?php echo esc_html( $name ); ?></span>
		<?php endif; ?>
		<span aria-hidden="true">›</span><span aria-current="page"><?php echo esc_html( ucfirst( $label ) ); ?></span>
	</nav>
</div>

<?php
// THE WRITING SITS UNDER THE GRID, NOT OVER IT
// --------------------------------------------
// A reader who opens this page came for the list. Every line printed above the
// first row is a line between them and it, and on a phone the paragraph, the
// back link and the count together pushed the first card off the screen. So the
// head is the title and nothing else, and the prose moves to the bottom, where
// it is read by the people who want it and by every search engine, which reads
// the whole page either way.
$intro = (string) get_post_meta( get_the_ID(), 'daw_section_intro', true );

if ( '' === trim( $intro ) && has_excerpt() ) {
	$intro = get_the_excerpt();
}

if ( '' !== trim( $intro ) && function_exists( 'daw_hub_fill_counts' ) ) {
	$intro = daw_hub_fill_counts( $intro, $country, $section );
}

$outro = (string) get_post_meta( get_the_ID(), 'daw_section_outro', true );

if ( '' !== trim( $outro ) && function_exists( 'daw_hub_fill_counts' ) ) {
	$outro = daw_hub_fill_counts( $outro, $country, $section );
}
?>

<header class="daw-phero daw-phero-tight">
	<div class="daw-wrap">
		<div class="daw-hubhero">
			<h1><?php echo esc_html( ucfirst( $heading ) ); ?></h1>
		</div>
	</div>
</header>

<div class="daw-wrap">

	<?php
	if ( $browse_cfg ) {
		daw_browse_body(
			array(
				'cfg'     => array_merge(
					$browse_cfg,
					array(
						'one'  => $labels_one[ $type ] ?? $browse_cfg['one'],
						'many' => $label,
					)
				),
				'base'    => (string) get_permalink( get_the_ID() ),
				'filters' => $browse_filters,
				'ids'     => $browse_ids,
				'lock'    => $country,
				'style'   => $style,
			)
		);
	} elseif ( $rows ) {
		printf( '<div class="%s">', 'poster' === $style ? 'daw-grid daw-browsegrid' : 'daw-hubguides daw-browseguides' );

		foreach ( $rows as $rid ) {
			if ( 'poster' === $style ) {
				daw_hub_title_card( $rid, 'film' === $type ? 'genre' : ( 'group' === $type ? 'kind' : 'network' ) );
			} else {
				daw_hub_guide_card( $rid );
			}
		}

		echo '</div>';
		daw_ad_slot( 'daw-ad-leaderboard' );
	} else {
		?>
		<div class="daw-section daw-section-tight">
			<p class="daw-sectionnote">
				<?php
				/* translators: 1: plural type label, 2: country name */
				printf( esc_html__( 'No %1$s from %2$s are in the archive yet. They are added as they are sourced.', 'daw' ), esc_html( $label ), esc_html( $name ) );
				?>
			</p>
		</div>
		<?php
	}
	?>

	<?php
	// The page's own writing, and the links out of it, in one compact block
	// under the list. The links are a row rather than a paragraph each: they
	// are signposts, and a signpost does not need a line of its own.
	// The writing describes the whole collection, so it is not printed under a
	// filtered view of it: "4 of them" under a page showing one is the kind of
	// small wrongness that makes a reader stop trusting the rest.
	// Narrowed means narrowed by the reader. The country is the page, not a
	// choice, so it does not count.
	$narrowed_now = function_exists( 'daw_browse_count_active' )
		&& ( daw_browse_count_active( $browse_filters, $country ) > 0 || (int) $browse_filters['pg'] > 1 );

	if ( ! $narrowed_now && ( '' !== trim( $intro ) || '' !== trim( $outro ) || $hub_url ) ) :
		?>
		<section class="daw-seotext">
			<?php if ( '' !== trim( $intro ) ) : ?>
				<p class="daw-seotext-lead"><?php echo esc_html( wp_strip_all_tags( $intro ) ); ?></p>
			<?php endif; ?>

			<?php
			if ( '' !== trim( $outro ) ) {
				echo function_exists( 'daw_seotext_body' ) ? daw_seotext_body( $outro ) : wp_kses_post( wpautop( $outro ) );
			}
			?>

			<nav class="daw-morelinks" aria-label="<?php esc_attr_e( 'More on this', 'daw' ); ?>">
				<?php if ( $hub_url ) : ?>
					<a href="<?php echo esc_url( $hub_url ); ?>">
						<?php
						/* translators: %s: country name */
						printf( esc_html__( 'Everything on %s', 'daw' ), esc_html( $name ) );
						?>
					</a>
				<?php endif; ?>

				<?php
				$all_url = function_exists( 'daw_browse_page_url_for_type' ) ? daw_browse_page_url_for_type( $type ) : '';

				if ( $all_url ) :
					?>
					<a href="<?php echo esc_url( $all_url ); ?>">
						<?php
						/* translators: %s: plural record label, e.g. food and drink guides */
						printf( esc_html__( 'All %s', 'daw' ), esc_html( $label ) );
						?>
					</a>
				<?php endif; ?>
			</nav>
		</section>
	<?php endif; ?>
</div>

<?php
get_footer();
