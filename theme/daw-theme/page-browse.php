<?php
/**
 * The browse page: every record of one kind, with the filters that narrow it.
 *
 * The country hubs answer "what is here from Korea" and the country sections
 * answer "every Korean drama". This one answers the question a reader arrives
 * with when they have no country in mind: romance, Netflix, the 1990s, still
 * airing.
 *
 * The page is a head; the body it draws is shared with the country sections, so
 * the two can never offer different filters for the same records.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();

$cfg = function_exists( 'daw_browse_for_page' ) ? daw_browse_for_page() : null;

if ( ! $cfg ) {
	get_footer();
	return;
}

$page_id = (int) get_the_ID();
$base    = (string) get_permalink( $page_id );
$filters = daw_browse_request();

// The page's own restriction, not the reader's: /k-pop/ carries people as well
// as groups, and the people it carries are the ones who make music.
$filters['require_roles']     = isset( $cfg['roles'] ) ? (array) $cfg['roles'] : array();
	$filters['require_roles_any'] = isset( $cfg['roles_any'] ) ? (array) $cfg['roles_any'] : array();
	$filters['require_flag']      = isset( $cfg['flag'] ) ? (string) $cfg['flag'] : '';

// A page can be about one country by definition. K-pop is: a Thai singer is
// not K-pop, however good. The lock is applied here rather than offered as a
// filter, and the body hides the country facet when it sees one.
$lock = isset( $cfg['lock'] ) ? (string) $cfg['lock'] : '';

// A page can also stand for a filter by name. /korean-actress/ is an address
// three years of links point at, and what it means is exactly one state of the
// people list. It keeps the address and answers the filter itself.
$fixed = function_exists( 'daw_browse_fixed' ) ? daw_browse_fixed( $cfg ) : array();

foreach ( $fixed as $fixed_key => $fixed_values ) {
	$filters[ $fixed_key ] = $fixed_values;
}

$ids = daw_browse_ids( $cfg['types'], $filters );

// The heading names what the reader chose. A filter the page answered for
// itself is already in the title, and printing it again gave /k-pop/ the
// heading "K-pop groups and soloists from South Korea".
$heading_filters = $filters;

foreach ( array_keys( $fixed ) as $fixed_key ) {
	$heading_filters[ $fixed_key ] = array();
}

$heading = daw_browse_heading( $cfg, $heading_filters );
?>

<div class="daw-wrap">
	<nav class="daw-crumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'daw' ); ?>">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'daw' ); ?></a>
		<span aria-hidden="true">›</span><span aria-current="page"><?php echo esc_html( $cfg['title'] ); ?></span>
	</nav>
</div>

<?php
// The writing sits under the list, for the reason page-section.php explains:
// the reader came for the grid, and a paragraph above it is a paragraph in the
// way. Both templates do this the same way, because they are meant to be one
// page with the country already answered.
$intro = trim( (string) get_post_meta( $page_id, 'daw_section_intro', true ) );

if ( '' === $intro && has_excerpt( $page_id ) ) {
	$intro = trim( wp_strip_all_tags( get_the_excerpt( $page_id ) ) );
}

$outro = trim( (string) get_post_meta( $page_id, 'daw_section_outro', true ) );

if ( function_exists( 'daw_browse_fill_counts' ) ) {
	$intro = daw_browse_fill_counts( $intro, $cfg, $filters, $ids );
	$outro = daw_browse_fill_counts( $outro, $cfg, $filters, $ids );
}
?>

<header class="daw-phero daw-browsehero daw-phero-tight">
	<div class="daw-wrap">
		<div class="daw-hubhero">
			<h1><?php echo esc_html( $heading ); ?></h1>
		</div>
	</div>
</header>

<div class="daw-wrap">
	<?php
	daw_browse_body(
		array(
			'cfg'     => $cfg,
			'base'    => $base,
			'filters' => $filters,
			'ids'     => $ids,
			'lock'    => $lock,
			'fixed'   => $fixed,
			'style'   => $cfg['style'] ?? 'poster',
		)
	);
	?>

	<?php
	// Only on the page as it stands, not on a filtered view of it: the text
	// describes the whole collection, and repeating it under every filter would
	// be the same paragraph on four hundred urls.
	$chosen = function_exists( 'daw_browse_count_active' )
		? ( daw_browse_count_active( $filters, $fixed ) > 0 || (int) $filters['pg'] > 1 )
		: daw_browse_is_filtered( $filters );

	if ( ! $chosen && ( '' !== $intro || '' !== $outro ) ) :
		?>
		<section class="daw-seotext">
			<?php if ( '' !== $intro ) : ?>
				<p class="daw-seotext-lead"><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>

			<?php
			if ( '' !== $outro ) {
				echo function_exists( 'daw_seotext_body' ) ? daw_seotext_body( $outro ) : wp_kses_post( wpautop( $outro ) );
			}
			?>
		</section>
	<?php endif; ?>
</div>

<?php
get_footer();
