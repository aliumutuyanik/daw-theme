<?php
/**
 * The page that is not there.
 *
 * WHY IT IS WORTH A TEMPLATE
 * --------------------------
 * On an archive of people whose names are romanised five different ways, a 404
 * is not usually a broken link. It is somebody who typed Kim Soohyun where we
 * file Kim Soo Hyun, or who followed a link to a record nobody has written yet.
 * Both of those have a next step, so the page says what probably happened and
 * offers the two doors that actually lead somewhere, rather than apologising
 * and leaving them at a dead end.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="daw-wrap">
	<section class="daw-404">
		<p class="daw-404-k"><?php esc_html_e( '404: page not found', 'daw' ); ?></p>
		<p class="daw-404-n" aria-hidden="true">404</p>

		<h1 class="daw-404-t"><?php esc_html_e( 'This page has moved, or it never existed', 'daw' ); ?></h1>

		<p class="daw-404-s"><?php esc_html_e( 'The drama, film or person you are after may be filed under a different romanisation. A search finds it more often than a guess at the spelling does.', 'daw' ); ?></p>

		<form class="daw-404-form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<label class="daw-vh" for="daw-404-s"><?php esc_html_e( 'Search the database', 'daw' ); ?></label>
			<input id="daw-404-s" type="search" name="s" placeholder="<?php esc_attr_e( 'Search titles, people…', 'daw' ); ?>" autocomplete="off">
			<button class="daw-btn daw-btn-p" type="submit"><?php esc_html_e( 'Search', 'daw' ); ?></button>
		</form>

		<?php
		// The destinations, but only the ones that resolve. A 404 page that
		// offers a second 404 is worse than no suggestion at all.
		$doors = function_exists( 'daw_live_links' ) ? daw_live_links(
			array(
				array( 'label' => __( 'Korean dramas', 'daw' ), 'url' => daw_page_url( 'korean-drama' ) ),
				array( 'label' => __( 'Actors and idols', 'daw' ), 'url' => daw_page_url( 'people' ) ),
				array( 'label' => __( 'K-Pop groups', 'daw' ), 'url' => daw_page_url( 'k-pop' ) ),
				array( 'label' => __( 'Myths and legends', 'daw' ), 'url' => daw_page_url( 'myths' ) ),
				array( 'label' => __( 'Food and drink', 'daw' ), 'url' => daw_page_url( 'food' ) ),
				array( 'label' => __( 'Airing calendar', 'daw' ), 'url' => daw_page_url( 'airing-calendar' ) ),
			)
		) : array();

		if ( $doors ) :
			?>
			<p class="daw-404-l"><?php esc_html_e( 'Or start from one of these', 'daw' ); ?></p>
			<nav class="daw-404-doors" aria-label="<?php esc_attr_e( 'Popular destinations', 'daw' ); ?>">
				<?php foreach ( $doors as $door ) : ?>
					<a class="daw-quick-o" href="<?php echo esc_url( $door['url'] ); ?>"><?php echo esc_html( $door['label'] ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>

		<?php
		$contact = daw_page_url( 'contact' );

		if ( $contact ) :
			?>
			<p class="daw-404-tell">
				<?php
				printf(
					/* translators: %s: a link to the contact page */
					esc_html__( 'If the record should exist, %s and it goes on the list.', 'daw' ),
					sprintf( '<a href="%s">%s</a>', esc_url( $contact ), esc_html__( 'tell us what is missing', 'daw' ) )
				);
				?>
			</p>
		<?php endif; ?>
	</section>
</div>

<?php
get_footer();
