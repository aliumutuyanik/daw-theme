<?php
/**
 * Site header.
 *
 * Overrides Astra's entirely. The theme is still a child so nothing breaks
 * while the remaining templates are written, but from here the chrome is ours:
 * our mark, our eight destinations, our search.
 *
 * Search sits in the header at full width rather than behind an icon. On a
 * database, search is not a utility — it is the main way in.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>

<body <?php body_class( 'daw-body' ); ?>>
<?php wp_body_open(); ?>

<a class="daw-skip" href="#daw-main"><?php esc_html_e( 'Skip to content', 'daw' ); ?></a>

<div class="daw">

	<div class="daw-utility">
		<div class="daw-wrap daw-utility-in">
			<p><?php
			// The number is counted, never typed (rule 1.5): published records, rounded down to the ten.
			$daw_n = (int) floor( (int) ( wp_count_posts( 'post' )->publish ?? 0 ) / 10 ) * 10;
			if ( $daw_n >= 10 ) {
				/* translators: %s: number of records, e.g. 1,100+ */
				printf( esc_html__( 'The Asian entertainment database. %s+ dramas, films, people and guides.', 'daw' ), esc_html( number_format_i18n( $daw_n ) ) );
			} else {
				esc_html_e( 'The Asian entertainment database.', 'daw' );
			}
			?></p>
			<?php $utility = daw_utility_nav(); ?>
			<?php if ( $utility ) : ?>
				<nav aria-label="<?php esc_attr_e( 'Secondary', 'daw' ); ?>">
					<?php foreach ( $utility as $item ) : ?>
						<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>
		</div>
	</div>

	<header class="daw-header">
		<div class="daw-wrap daw-header-in">

			<?php
			// 44, not 34. The mark carries the wordmark inside it, and at 34 that
			// lettering was below the size it can be read at. It still clears the
			// 62px header box, so the bar keeps exactly the height it had.
			echo daw_logo( 44 ); // phpcs:ignore WordPress.Security.EscapeOutput
			?>

			<nav class="daw-nav" aria-label="<?php esc_attr_e( 'Primary', 'daw' ); ?>">
				<ul>
					<?php foreach ( daw_primary_nav() as $item ) : ?>
						<li<?php echo empty( $item['children'] ) ? '' : ' class="has-sub"'; ?>>
							<a href="<?php echo esc_url( $item['url'] ); ?>">
								<?php echo esc_html( $item['label'] ); ?>
								<?php if ( ! empty( $item['children'] ) ) : ?>
									<span class="daw-caret" aria-hidden="true"></span>
								<?php endif; ?>
							</a>
							<?php if ( ! empty( $item['children'] ) ) : ?>
								<ul class="daw-sub">
									<?php foreach ( $item['children'] as $child ) : ?>
										<li><a href="<?php echo esc_url( $child['url'] ); ?>"><?php echo esc_html( $child['label'] ); ?></a></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</nav>

			<div class="daw-header-tools">
				<form class="daw-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
					<label class="daw-sr" for="daw-s"><?php esc_html_e( 'Search titles, people…', 'daw' ); ?></label>
					<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
						<circle cx="7" cy="7" r="5" fill="none" stroke="currentColor" stroke-width="1.6"/>
						<path d="M10.8 10.8 L14 14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
					</svg>
					<input id="daw-s" type="search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>"
						placeholder="<?php esc_attr_e( 'Search titles, people…', 'daw' ); ?>" autocomplete="off">
				</form>

				<?php // Saved is a feature, not a page. It appears once it works. ?>
				<?php if ( daw_page_url( 'saved' ) ) : ?>
					<a class="daw-iconbtn" href="<?php echo esc_url( daw_page_url( 'saved' ) ); ?>"
						aria-label="<?php esc_attr_e( 'Saved', 'daw' ); ?>">
						<svg width="17" height="17" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
							<path d="M10 17s-6.5-4.2-6.5-8.3A3.7 3.7 0 0 1 10 6a3.7 3.7 0 0 1 6.5 2.7C16.5 12.8 10 17 10 17z"
								fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
						</svg>
					</a>
				<?php endif; ?>

				<button class="daw-iconbtn daw-burger" type="button"
					aria-expanded="false" aria-controls="daw-mobile-nav"
					aria-label="<?php esc_attr_e( 'Menu', 'daw' ); ?>">
					<svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
						<path d="M3 6h14M3 10h14M3 14h14" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
					</svg>
				</button>
			</div>
		</div>

		<button class="daw-scrim" type="button" data-scrim hidden tabindex="-1" aria-hidden="true"></button>

		<nav class="daw-mobile-nav" id="daw-mobile-nav" aria-label="<?php esc_attr_e( 'Primary, mobile', 'daw' ); ?>" hidden>
			<div class="daw-wrap">
				<?php
				// THE SUBMENUS WERE MISSING FROM THIS MENU ENTIRELY
				// -------------------------------------------------
				// The desktop bar drops "Countries" and "More" open on hover, and
				// this panel printed only the parents. On a phone that meant every
				// country hub, plus Culture, Food, Travel and Quizzes, had no route
				// to them at all: eight rows that went nowhere the reader wanted.
				// They are <details> rather than script, so the panel costs nothing
				// to open and the browser handles the state.
				?>
				<ul>
					<?php foreach ( daw_primary_nav() as $item ) : ?>
						<?php if ( empty( $item['children'] ) ) : ?>
							<li><a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li>
						<?php else : ?>
							<li class="has-sub">
								<details>
									<summary>
										<span><?php echo esc_html( $item['label'] ); ?></span>
										<span class="daw-mchev" aria-hidden="true"></span>
									</summary>
									<ul class="daw-msub">
										<?php foreach ( $item['children'] as $child ) : ?>
											<li><a href="<?php echo esc_url( $child['url'] ); ?>"><?php echo esc_html( $child['label'] ); ?></a></li>
										<?php endforeach; ?>
									</ul>
								</details>
							</li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			</div>
		</nav>
	</header>

	<main id="daw-main">
