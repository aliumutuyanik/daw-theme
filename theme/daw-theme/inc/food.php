<?php
/**
 * Food on screen: the two rails.
 *
 * On a drama or film, a rail of dish cards (daw_food_title_section). On a food
 * record, a rail of poster cards (daw_food_seen_section). Both read what
 * daw-core indexed; neither queries for it.
 *
 * Both print nothing at all, heading included, when there is nothing to show.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** The domain without www, for a source that has a link but no name. */
function daw_food_host( $url ) {
	$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );

	return preg_replace( '/^www\./i', '', $host );
}

/** MyDramaList is never linked (rule 3.4); its name may still be printed. */
function daw_food_linkable( $url ) {
	return '' !== (string) $url && false === stripos( daw_food_host( $url ), 'mydramalist' );
}

/**
 * Split the index rows of a drama or film into its sections, one per kind of
 * guide (food, culture, myths, places, other), in a fixed order. Empty kinds are
 * left out.
 *
 * @param array $items Rows from daw_fos_title_foods().
 * @return array [ key => [ id, label, items ] ]
 */
function daw_food_title_groups( array $items ) {
	$groups = array();
	$labels = function_exists( 'daw_fos_group_labels' ) ? daw_fos_group_labels() : array( 'food' => __( 'Food on screen', 'daw' ) );

	foreach ( $labels as $key => $label ) {
		$rows = array_values(
			array_filter(
				$items,
				static function ( $e ) use ( $key ) {
					return ( $e['group'] ?? 'food' ) === $key;
				}
			)
		);
		if ( $rows ) {
			$groups[ $key ] = array(
				'id'    => sanitize_title( $label ),
				'label' => $label,
				'items' => $rows,
			);
		}
	}

	return $groups;
}

/**
 * One section on a drama or film page: a heading and a rail of guide cards in
 * episode order, episode-less last. The picture is the guide's own featured
 * image, held at 4 / 3.
 *
 * @param array $group One entry of daw_food_title_groups().
 */
function daw_food_title_section( array $group ) {
	$items = $group['items'];

	$cards = array();

	foreach ( $items as $e ) {
		$fid = (int) $e['food_id'];
		if ( ! $fid ) {
			continue;
		}

		// Kind first, then the episode, then the scene. What is not known is
		// not printed, and nothing stands in for it.
		$line = array_filter(
			array(
				function_exists( 'daw_fos_kind_label' ) ? daw_fos_kind_label( $e['kind'] ) : '',
				function_exists( 'daw_fos_ep_label' ) ? daw_fos_ep_label( $e['season'] ?? 0, $e['episode'] ?? 0 ) : '',
				trim( (string) ( $e['scene'] ?? '' ) ),
			),
			static function ( $part ) {
				return '' !== $part;
			}
		);

		$cards[] = array(
			'id'   => $fid,
			'line' => implode( ' · ', $line ),
		);
	}

	if ( ! $cards ) {
		return;
	}
	?>
	<section class="daw-section daw-food" id="<?php echo esc_attr( $group['id'] ); ?>">
		<h2><?php echo esc_html( $group['label'] ); ?></h2>
		<div class="daw-rail-row daw-foodrail">
			<?php foreach ( $cards as $c ) : ?>
				<a class="daw-foodcard" href="<?php echo esc_url( get_permalink( $c['id'] ) ); ?>">
					<span class="daw-foodcard-img">
						<?php
						if ( has_post_thumbnail( $c['id'] ) ) {
							echo get_the_post_thumbnail( // phpcs:ignore WordPress.Security.EscapeOutput
								$c['id'],
								'medium',
								array(
									'loading'  => 'lazy',
									'decoding' => 'async',
									'sizes'    => '(max-width: 760px) 168px, 210px',
									'alt'      => '',
								)
							);
						} elseif ( function_exists( 'daw_monogram' ) ) {
							echo daw_monogram( $c['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
					</span>
					<span class="daw-foodcard-name"><?php echo esc_html( wp_strip_all_tags( get_the_title( $c['id'] ) ) ); ?></span>
					<?php if ( '' !== $c['line'] ) : ?>
						<span class="daw-foodcard-line"><?php echo esc_html( $c['line'] ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>
	</section>
	<?php
}

/**
 * On a food record: "Seen in K-Dramas and movies". The same poster card as a
 * filmography, with the scene note and its source underneath. Prints nothing
 * when no listed title is published.
 *
 * @param array $list Rows from daw_fos_seen_list().
 */
function daw_food_seen_section( array $list ) {
	if ( ! $list ) {
		return;
	}
	?>
	<section class="daw-gsection daw-seenin" id="seen-in">
		<h2><?php esc_html_e( 'Seen in K-Dramas and movies', 'daw' ); ?></h2>
		<div class="daw-rail-row daw-seenrail">
			<?php
			foreach ( $list as $item ) :
				$tid    = (int) $item['title_id'];
				$kind   = function_exists( 'daw_get_type' ) ? daw_get_type( $tid ) : '';
				$scenes = $item['scenes'];
				$year   = (int) $item['year'];
				$rating = function_exists( 'daw_title_rating_value' ) ? daw_title_rating_value( $tid ) : 0;
				$one    = 1 === count( $scenes );

				// "Drama · Ep 4" or "Film". With more than one scene the
				// episodes belong to the scene lines underneath instead.
				$line = array_filter(
					array(
						daw_kind_label( $kind ),
						$one && function_exists( 'daw_fos_ep_label' ) ? daw_fos_ep_label( $scenes[0]['season'] ?? 0, $scenes[0]['episode'] ?? 0 ) : '',
					),
					static function ( $part ) {
						return '' !== $part;
					}
				);
				?>
				<div class="daw-seencard">
					<a class="daw-person" data-kind="<?php echo esc_attr( $kind ? $kind : 'drama' ); ?>" href="<?php echo esc_url( get_permalink( $tid ) ); ?>">
						<div class="daw-poster">
							<?php echo daw_poster( $tid, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( daw_kind_label( $kind ) ) : ?>
								<span class="daw-kindbadge"><?php echo esc_html( daw_kind_label( $kind ) ); ?></span>
							<?php endif; ?>
							<?php if ( $rating ) : ?>
								<span class="daw-scorebadge">★ <?php echo esc_html( number_format_i18n( $rating, 1 ) ); ?></span>
							<?php endif; ?>
							<?php if ( $year ) : ?>
								<span class="daw-yearbadge"><?php echo esc_html( $year ); ?></span>
							<?php endif; ?>
						</div>
						<div class="daw-person-name"><?php echo esc_html( wp_strip_all_tags( get_the_title( $tid ) ) ); ?></div>
						<?php if ( $line ) : ?>
							<div class="daw-person-role"><?php echo esc_html( implode( ' · ', $line ) ); ?></div>
						<?php endif; ?>
					</a>
					<?php
					$notes = array();
					foreach ( $scenes as $s ) {
						$scene = trim( (string) ( $s['scene'] ?? '' ) );
						$ep    = $one || ! function_exists( 'daw_fos_ep_label' ) ? '' : daw_fos_ep_label( $s['season'] ?? 0, $s['episode'] ?? 0 );
						$url   = (string) ( $s['source_url'] ?? '' );
						$name  = trim( (string) ( $s['source_name'] ?? '' ) );
						$link  = daw_food_linkable( $url );
						if ( '' === $name && $link ) {
							$name = daw_food_host( $url );
						}
						if ( '' === $scene && '' === $ep && '' === $name ) {
							continue;
						}
						$notes[] = compact( 'scene', 'ep', 'url', 'name', 'link' );
					}
					?>
					<?php if ( $notes ) : ?>
						<div class="daw-seen-notes">
							<?php foreach ( $notes as $n ) : ?>
								<p class="daw-seen-note">
									<?php
									$text = trim( $n['ep'] . ( '' !== $n['ep'] && '' !== $n['scene'] ? ' · ' : '' ) . $n['scene'] );
									echo esc_html( $text );
									if ( '' !== $n['name'] ) {
										echo '' !== $text ? ' ' : '';
										if ( $n['link'] ) {
											printf(
												'<a class="daw-seen-src" href="%s" target="_blank" rel="noopener">%s</a>',
												esc_url( $n['url'] ),
												esc_html( $n['name'] )
											);
										} else {
											echo '<span class="daw-seen-src">' . esc_html( $n['name'] ) . '</span>';
										}
									}
									?>
								</p>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</section>
	<?php
}
