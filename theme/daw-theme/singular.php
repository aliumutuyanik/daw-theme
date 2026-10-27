<?php
/**
 * One post or page, when nothing more specific draws it.
 *
 * Every record type has its own template and every built page has one too, so
 * arriving here means the page is an ordinary piece of writing: a draft, a page
 * somebody added in the editor, a post filed under no type yet. It gets a
 * reading measure and the site's furniture, and nothing else is invented for it.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	?>

	<div class="daw-wrap">
		<nav class="daw-crumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'daw' ); ?>">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'daw' ); ?></a>
			<span aria-hidden="true">›</span><span aria-current="page"><?php echo esc_html( wp_trim_words( get_the_title(), 8, '' ) ); ?></span>
		</nav>
	</div>

	<header class="daw-phero daw-phero-tight">
		<div class="daw-wrap">
			<div class="daw-hubhero">
				<h1><?php the_title(); ?></h1>
				<?php if ( has_excerpt() ) : ?>
					<p class="daw-phero-sum"><?php echo esc_html( wp_strip_all_tags( get_the_excerpt() ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</header>

	<div class="daw-wrap">
		<article class="daw-readcol">
			<div class="daw-prose"><?php the_content(); ?></div>
		</article>
	</div>

	<?php
endwhile;

get_footer();
