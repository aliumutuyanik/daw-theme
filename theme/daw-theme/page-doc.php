<?php
/**
 * The document template: about, editorial policy, and the legal set.
 *
 * Three columns on a desktop: the other documents on the left, the text in the
 * middle at a reading measure, the two or three things a reader might actually
 * want to do on the right. On a phone the rail moves to the bottom, because
 * somebody who opened the privacy policy came to read it, not to browse the
 * other policies first.
 *
 * The text itself is the page's own content, written in the editor. Nothing
 * here generates policy language: a template that assembles legal text out of
 * fragments is how a site ends up promising something nobody checked.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();

$doc  = daw_doc_for_page();
$rail = daw_doc_rail();
$id   = (int) get_the_ID();

if ( ! $doc ) {
	get_footer();
	return;
}

$effective = trim( (string) get_post_meta( $id, 'daw_doc_effective', true ) );
$summary   = trim( (string) ( has_excerpt( $id ) ? wp_strip_all_tags( get_the_excerpt( $id ) ) : '' ) );
?>

<div class="daw-wrap">
	<nav class="daw-crumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'daw' ); ?>">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'daw' ); ?></a>
		<span aria-hidden="true">›</span><span><?php echo esc_html( $doc['group'] ); ?></span>
		<span aria-hidden="true">›</span><span aria-current="page"><?php echo esc_html( $doc['label'] ); ?></span>
	</nav>
</div>

<header class="daw-phero daw-dochero">
	<div class="daw-wrap">
		<div class="daw-hubhero">
			<h1><?php the_title(); ?></h1>
			<?php if ( $summary ) : ?>
				<p class="daw-phero-sum"><?php echo esc_html( $summary ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</header>

<div class="daw-wrap">
	<div class="daw-doccols">

		<?php if ( count( $rail ) > 1 ) : ?>
			<nav class="daw-docrail" aria-label="<?php esc_attr_e( 'Policies and editorial pages', 'daw' ); ?>">
				<ul>
					<?php foreach ( $rail as $item ) : ?>
						<li>
							<a class="<?php echo $item['slug'] === $doc['slug'] ? 'is-on' : ''; ?>"
								href="<?php echo esc_url( $item['url'] ); ?>"
								<?php echo $item['slug'] === $doc['slug'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $item['label'] ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			</nav>
		<?php endif; ?>

		<article class="daw-docmain">
			<?php
			$updated = daw_doc_updated( $id );

			if ( $updated || $effective ) :
				?>
				<p class="daw-docdate">
					<?php
					echo esc_html(
						implode(
							' · ',
							array_filter(
								array(
									$updated,
									$effective ? sprintf(
										/* translators: %s: date the document takes effect */
										__( 'Effective %s', 'daw' ),
										$effective
									) : '',
								)
							)
						)
					);
					?>
				</p>
			<?php endif; ?>

			<div class="daw-docbody">
				<?php the_content(); ?>
			</div>
		</article>

		<aside class="daw-docaside">
			<?php
			// The three things a reader of a policy page usually came to do.
			// Each one is a real destination; anything without a page is
			// dropped rather than printed as a dead link.
			$actions = array_filter(
				array(
					array(
						'label' => __( 'Request image removal', 'daw' ),
						'url'   => daw_page_url( 'legal-notice-visual-content-usage' ),
					),
					array(
						'label' => __( 'Request a factual correction', 'daw' ),
						'url'   => daw_page_url( 'corrections-policy' ),
					),
					array(
						'label' => __( 'Data access or deletion request', 'daw' ),
						'url'   => daw_page_url( 'privacy-policy' ),
					),
					array(
						'label' => __( 'Contact the editors', 'daw' ),
						'url'   => daw_page_url( 'contact' ),
					),
				),
				static function ( $a ) {
					return ! empty( $a['url'] );
				}
			);

			if ( $actions ) :
				?>
				<div class="daw-rcard daw-docactions">
					<div class="daw-rcard-h"><?php esc_html_e( 'Quick actions', 'daw' ); ?></div>
					<ul>
						<?php foreach ( $actions as $action ) : ?>
							<li><a href="<?php echo esc_url( $action['url'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( __( 'Legal', 'daw' ) === $doc['group'] ) : ?>
				<p class="daw-docnote">
					<?php esc_html_e( 'This page describes how the site works and what we do with your data. It is not legal advice.', 'daw' ); ?>
				</p>
			<?php endif; ?>
		</aside>
	</div>
</div>

<?php
get_footer();
