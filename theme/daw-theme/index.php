<?php
/**
 * The fallback list.
 *
 * WHY A THEME THAT DRAWS EVERYTHING STILL NEEDS THIS
 * --------------------------------------------------
 * WordPress requires index.php of every theme, and it is the file that answers
 * whatever the more specific templates did not: a date archive somebody linked
 * to in 2023, a taxonomy nobody browses, an author page. None of those are part
 * of this site, and none of them may fatal either.
 *
 * So it is short on purpose. It prints what the query found in the same cards
 * the rest of the site uses, and it says plainly when the query found nothing.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();

$title = __( 'Everything in the archive', 'daw' );

if ( is_archive() ) {
	$title = wp_strip_all_tags( get_the_archive_title() );
} elseif ( is_home() && ! is_front_page() ) {
	$title = single_post_title( '', false );
}
?>

<div class="daw-wrap">
	<nav class="daw-crumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'daw' ); ?>">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'daw' ); ?></a>
		<span aria-hidden="true">›</span><span aria-current="page"><?php echo esc_html( $title ); ?></span>
	</nav>
</div>

<header class="daw-phero daw-phero-tight">
	<div class="daw-wrap">
		<div class="daw-hubhero">
			<h1><?php echo esc_html( $title ); ?></h1>
		</div>
	</div>
</header>

<div class="daw-wrap">
	<?php if ( have_posts() ) : ?>
		<div class="daw-hubguides daw-browseguides">
			<?php
			while ( have_posts() ) :
				the_post();

				if ( function_exists( 'daw_hub_guide_card' ) ) {
					daw_hub_guide_card( get_the_ID(), true );
				} else {
					printf(
						'<a class="daw-card" href="%s"><span class="daw-card-t">%s</span></a>',
						esc_url( (string) get_permalink() ),
						esc_html( get_the_title() )
					);
				}
			endwhile;
			?>
		</div>

		<?php
		the_posts_pagination(
			array(
				'mid_size'  => 1,
				'prev_text' => __( 'Previous', 'daw' ),
				'next_text' => __( 'Next', 'daw' ),
			)
		);
		?>
	<?php else : ?>
		<div class="daw-section daw-section-tight">
			<p class="daw-sectionnote"><?php esc_html_e( 'Nothing here. The search box finds records by title, by native name and by the name a person is also known as.', 'daw' ); ?></p>
		</div>
	<?php endif; ?>
</div>

<?php
get_footer();
