<?php
/**
 * The airing calendar.
 *
 * One page, two ways in. The list answers what most readers came for (on air
 * now, coming up, just finished) in one request. The month view reaches every
 * other date. Both are drawn from the same index of premieres and finales.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();

$req   = daw_calendar_request();
$data  = daw_calendar_data( $req );
$base  = (string) get_permalink( get_the_ID() );
$view  = $req['view'];
$month = $req['month'];
$is_m  = 'month' === $view;

/** Link to this page with some of the request changed. */
$link = static function ( $over = array() ) use ( $base, $req ) {
	$args = array_merge(
		array(
			'view' => 'list' === $req['view'] ? null : $req['view'],
			'ym'   => 'month' === $req['view'] ? $req['month'] : null,
			'type' => $req['type'],
		),
		$over
	);

	return daw_calendar_url( $base, $args );
};

/** One event row: poster, kind, title, line of facts, and an add-to-calendar link for coming premieres. */
$event_row = static function ( $e ) use ( $req, $base ) {
	$id      = (int) $e['id'];
	$eps     = (int) daw_field_raw( $id, 'daw_episodes' );
	$net     = daw_first_term_name( $id, 'dw_network' );
	$country = daw_first_term_name( $id, 'dw_country' );
	$facts   = array_filter(
		array(
			$country,
			$net,
			/* translators: %s: number of episodes */
			$eps ? sprintf( _n( '%s ep', '%s eps', $eps, 'daw' ), number_format_i18n( $eps ) ) : '',
		)
	);
	$prem    = 'prem' === $e['kind'];
	$is_ep   = 'ep' === $e['kind'];
	$coming  = $prem && gmdate( 'Y-m-d', $e['when'] ) > $req['today'];
	if ( $is_ep ) {
		/* translators: %s: episode number, or a range such as 3-4 */
		$kind_label = '' !== (string) ( $e['no'] ?? '' ) ? sprintf( __( 'Episode %s', 'daw' ), $e['no'] ) : __( 'New episode', 'daw' );
	} elseif ( $prem ) {
		$kind_label = 'film' === $e['type'] ? __( 'Release', 'daw' ) : __( 'Premiere', 'daw' );
	} else {
		$kind_label = __( 'Finale', 'daw' );
	}
	?>
	<li class="daw-cal2-ev">
		<a class="daw-cal2-pic" href="<?php echo esc_url( get_permalink( $id ) ); ?>" tabindex="-1" aria-hidden="true"><span class="daw-poster"><?php echo daw_poster( $id, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></a>
		<span class="daw-cal2-body">
			<span class="daw-cal2-kind <?php echo $is_ep ? 'ep' : ( $prem ? 'prem' : 'fin' ); ?>"><?php echo esc_html( $kind_label ); ?></span>
			<a class="n" href="<?php echo esc_url( get_permalink( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a>
			<span class="m"><?php echo esc_html( implode( ' · ', $facts ) ); ?></span>
		</span>
		<?php if ( $coming ) : ?>
			<a class="daw-cal2-add" href="<?php echo esc_url( add_query_arg( 'daw_ics', $id, $base ) ); ?>" rel="nofollow"><?php esc_html_e( '+ Add to calendar', 'daw' ); ?></a>
		<?php endif; ?>
	</li>
	<?php
};

/** Events as days: a dated heading with "In 69 days", then that day's rows. */
$days = static function ( $events, $anchor = false ) use ( $event_row ) {
	foreach ( daw_calendar_by_day( $events ) as $day => $list ) {
		$ts = strtotime( $day . ' 00:00:00 UTC' );
		?>
		<div class="daw-cal2-day"<?php echo $anchor ? ' id="d-' . esc_attr( $day ) . '"' : ''; ?>>
			<div class="daw-cal2-dayh"><?php echo esc_html( daw_calendar_fmt( $ts, 'D j F Y' ) ); ?><span><?php echo esc_html( daw_calendar_relative( $ts ) ); ?></span></div>
			<ul class="daw-cal2-ul">
				<?php
				foreach ( $list as $e ) {
					$event_row( $e );
				}
				?>
			</ul>
		</div>
		<?php
	}
};

$n_now  = count( $data['onair'] );
$n_next = count( $data['next'] ) + count( $data['tbc'] );
$n_done = count( $data['done'] );
?>

<div class="daw-wrap">
	<nav class="daw-crumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'daw' ); ?>">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'daw' ); ?></a>
		<span aria-hidden="true">›</span><span aria-current="page"><?php esc_html_e( 'Airing calendar', 'daw' ); ?></span>
	</nav>
</div>

<header class="daw-phero daw-browsehero">
	<div class="daw-wrap">
		<div class="daw-hubhero">
			<h1><?php esc_html_e( 'Airing calendar', 'daw' ); ?></h1>
			<p class="daw-phero-sum">
				<?php esc_html_e( 'What is running now, what starts next and what has just finished, dated from the records themselves. Every date here is one somebody sourced. Any other month is one click away.', 'daw' ); ?>
			</p>
		</div>
	</div>
</header>

<div class="daw-wrap daw-cal2<?php echo $is_m ? ' is-month' : ''; ?>">

	<div class="daw-cal2-bar">
		<?php if ( ! $is_m ) : ?>
			<ul class="daw-cal2-jump">
				<li><a class="daw-cal2-chip" href="#now"><?php esc_html_e( 'Airing now', 'daw' ); ?> <b><?php echo esc_html( number_format_i18n( $n_now ) ); ?></b></a></li>
				<?php if ( $data['week'] ) : ?>
					<li><a class="daw-cal2-chip" href="#week"><?php esc_html_e( 'This week', 'daw' ); ?> <b><?php echo esc_html( number_format_i18n( count( $data['week'] ) ) ); ?></b></a></li>
				<?php endif; ?>
				<li><a class="daw-cal2-chip" href="#next"><?php esc_html_e( 'Upcoming', 'daw' ); ?> <b><?php echo esc_html( number_format_i18n( $n_next ) ); ?></b></a></li>
				<li><a class="daw-cal2-chip" href="#done"><?php esc_html_e( 'Just finished', 'daw' ); ?> <b><?php echo esc_html( number_format_i18n( $n_done ) ); ?></b></a></li>
			</ul>
		<?php else : ?>
			<form class="daw-cal2-pick" method="get" action="<?php echo esc_url( $base ); ?>">
				<input type="hidden" name="view" value="month">
				<?php if ( 'all' !== $req['type'] ) : ?>
					<input type="hidden" name="type" value="<?php echo esc_attr( $req['type'] ); ?>">
				<?php endif; ?>
				<label class="screen-reader-text" for="daw-cal2-mo"><?php esc_html_e( 'Month', 'daw' ); ?></label>
				<select id="daw-cal2-mo" name="mo">
					<?php for ( $i = 1; $i <= 12; $i++ ) : ?>
						<option value="<?php echo (int) $i; ?>"<?php selected( (int) substr( $month, 5, 2 ), $i ); ?>><?php echo esc_html( daw_calendar_fmt( strtotime( '2026-' . sprintf( '%02d', $i ) . '-15 UTC' ), 'F' ) ); ?></option>
					<?php endfor; ?>
				</select>
				<label class="screen-reader-text" for="daw-cal2-yr"><?php esc_html_e( 'Year', 'daw' ); ?></label>
				<select id="daw-cal2-yr" name="yr">
					<?php for ( $y = $data['years'][1]; $y >= $data['years'][0]; $y-- ) : ?>
						<option value="<?php echo (int) $y; ?>"<?php selected( (int) substr( $month, 0, 4 ), $y ); ?>><?php echo (int) $y; ?></option>
					<?php endfor; ?>
				</select>
				<button type="submit" class="daw-cal2-chip"><?php esc_html_e( 'Go', 'daw' ); ?></button>
			</form>
		<?php endif; ?>

		<div class="daw-cal2-view" role="group" aria-label="<?php esc_attr_e( 'View', 'daw' ); ?>">
			<a class="<?php echo $is_m ? '' : 'is-on'; ?>" href="<?php echo esc_url( $link( array( 'view' => null, 'ym' => null ) ) ); ?>"<?php echo $is_m ? '' : ' aria-current="true"'; ?>><?php esc_html_e( 'List', 'daw' ); ?></a>
			<a class="<?php echo $is_m ? 'is-on' : ''; ?>" href="<?php echo esc_url( $link( array( 'view' => 'month', 'ym' => $month ) ) ); ?>"<?php echo $is_m ? ' aria-current="true"' : ''; ?>><?php esc_html_e( 'Month', 'daw' ); ?></a>
		</div>
	</div>

	<ul class="daw-cal2-filter" aria-label="<?php esc_attr_e( 'Type', 'daw' ); ?>">
		<?php foreach ( daw_calendar_filters() as $k => $label ) : ?>
			<li><a class="daw-cal2-chip<?php echo $k === $req['type'] ? ' is-on' : ''; ?>" href="<?php echo esc_url( $link( array( 'type' => $k ) ) ); ?>"<?php echo $k === $req['type'] ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a></li>
		<?php endforeach; ?>
	</ul>

	<?php if ( ! $is_m ) : ?>

		<section class="daw-cal2-sec" id="now">
			<h2 class="daw-cal2-h"><?php esc_html_e( 'Airing now', 'daw' ); ?> <small><?php echo esc_html( sprintf( /* translators: %s: number of titles */ _n( '%s title', '%s titles', $n_now, 'daw' ), number_format_i18n( $n_now ) ) ); ?></small></h2>
			<?php if ( ! $data['onair'] ) : ?>
				<p class="daw-empty"><?php esc_html_e( 'Nothing in the archive is marked as on air at the moment. Titles appear here as soon as one is.', 'daw' ); ?></p>
			<?php else : ?>
				<ul class="daw-cal2-rail">
					<?php
					// Soonest next episode first, so the rail reads as this
					// week's schedule; titles with no dated episode keep their
					// order at the end.
					$now_rows = $data['onair'];
					$nx_key   = array();
					foreach ( $now_rows as $k => $nr ) {
						$ne = function_exists( 'daw_calendar_next_ep' ) ? daw_calendar_next_ep( (int) $nr['id'] ) : null;
						$nx_key[ $k ] = $ne ? $ne['date'] : '9999-99-99';
					}
					uksort(
						$now_rows,
						static function ( $a, $b ) use ( $nx_key ) {
							return ( $nx_key[ $a ] <=> $nx_key[ $b ] ) ?: ( $a <=> $b );
						}
					);
					foreach ( $now_rows as $row ) :
						$id      = (int) $row['id'];
						$eps     = (int) daw_field_raw( $id, 'daw_episodes' );
						$net     = daw_first_term_name( $id, 'dw_network' );
						$country = daw_first_term_name( $id, 'dw_country' );
						$facts   = array_filter(
							array(
								$country,
								$net,
								/* translators: %s: number of episodes */
								$eps ? sprintf( _n( '%s ep', '%s eps', $eps, 'daw' ), number_format_i18n( $eps ) ) : '',
							)
						);
						?>
						<li class="daw-cal2-card">
							<a class="daw-cal2-pic" href="<?php echo esc_url( get_permalink( $id ) ); ?>" tabindex="-1" aria-hidden="true"><span class="daw-poster"><?php echo daw_poster( $id, 'medium', '170px' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><span class="daw-cal2-live"><?php esc_html_e( 'On air', 'daw' ); ?></span></a>
							<a class="t" href="<?php echo esc_url( get_permalink( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a>
							<span class="m"><?php echo esc_html( implode( ' · ', $facts ) ); ?></span>
							<?php
							// When it airs: the next dated episode from the record's
							// own list, then the sourced airing days (two lines at
							// most, so long notes keep the rail even).
							$air_days = trim( (string) daw_field_raw( $id, 'daw_airing_days' ) );
							$nxt      = function_exists( 'daw_calendar_next_ep' ) ? daw_calendar_next_ep( $id ) : null;
							?>
							<?php if ( $nxt ) : ?>
								<span class="m daw-cal2-next">
									<?php
									if ( $nxt['today'] ) {
										/* translators: %s: episode number */
										echo esc_html( '' !== $nxt['no'] ? sprintf( __( 'Episode %s today', 'daw' ), $nxt['no'] ) : __( 'New episode today', 'daw' ) );
									} else {
										$nts = strtotime( $nxt['date'] . ' 00:00:00 UTC' );
										/* translators: 1: episode number, 2: date */
										echo esc_html( '' !== $nxt['no'] ? sprintf( __( 'Episode %1$s on %2$s', 'daw' ), $nxt['no'], daw_calendar_fmt( $nts, 'D j M' ) ) : sprintf( /* translators: %s: date */ __( 'Next episode %s', 'daw' ), daw_calendar_fmt( $nts, 'D j M' ) ) );
										echo ' <span class="daw-cal2-count">' . esc_html( daw_calendar_relative( $nts ) ) . '</span>';
									}
									?>
								</span>
							<?php elseif ( $row['when'] <= time() && $row['when'] ) : ?>
								<span class="m"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Premiered %s', 'daw' ), daw_calendar_fmt( $row['when'], 'j M' ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $air_days ) : ?>
								<span class="m daw-cal2-days" title="<?php echo esc_attr( $air_days ); ?>"><?php echo esc_html( $air_days ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<?php if ( $data['week'] ) : ?>
			<section class="daw-cal2-sec" id="week">
				<h2 class="daw-cal2-h"><?php esc_html_e( 'This week', 'daw' ); ?> <small><?php esc_html_e( 'every dated episode, premiere and finale for the next seven days', 'daw' ); ?></small></h2>
				<?php $days( $data['week'] ); ?>
			</section>
		<?php endif; ?>

		<section class="daw-cal2-sec" id="next">
			<h2 class="daw-cal2-h"><?php esc_html_e( 'Upcoming', 'daw' ); ?> <small><?php esc_html_e( 'dated premieres and releases, soonest first', 'daw' ); ?></small></h2>
			<?php if ( ! $data['next'] && ! $data['tbc'] ) : ?>
				<p class="daw-empty"><?php esc_html_e( 'No upcoming titles are dated yet. A title appears here once a premiere date is sourced.', 'daw' ); ?></p>
			<?php else : ?>
				<?php $days( $data['next'] ); ?>
				<?php if ( $data['tbc'] ) : ?>
					<div class="daw-cal2-day">
						<div class="daw-cal2-dayh"><?php esc_html_e( 'Date not announced', 'daw' ); ?></div>
						<ul class="daw-cal2-ul">
							<?php
							foreach ( $data['tbc'] as $t ) {
								$event_row( array( 'id' => $t['id'], 'when' => 0, 'kind' => 'prem', 'type' => $t['type'] ) );
							}
							?>
						</ul>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<section class="daw-cal2-sec" id="done">
			<h2 class="daw-cal2-h"><?php esc_html_e( 'Just finished', 'daw' ); ?> <small><?php esc_html_e( 'ended in the last six months', 'daw' ); ?></small></h2>
			<?php if ( ! $data['done'] ) : ?>
				<p class="daw-empty"><?php esc_html_e( 'Nothing has finished in the last six months according to the dates on file.', 'daw' ); ?></p>
			<?php else : ?>
				<?php $days( $data['done'] ); ?>
			<?php endif; ?>
			<p class="daw-cal2-more"><a href="<?php echo esc_url( $link( array( 'view' => 'month', 'ym' => daw_calendar_shift_month( wp_date( 'Y-m' ), -7 ) ) ) ); ?>"><?php esc_html_e( 'Earlier dates: browse by month', 'daw' ); ?> →</a></p>
		</section>

	<?php else : ?>

		<?php
		$prev = daw_calendar_shift_month( $month, -1 );
		$nextm = daw_calendar_shift_month( $month, 1 );
		$ts1  = strtotime( $month . '-01 00:00:00 UTC' );
		$dim  = (int) gmdate( 't', $ts1 );
		$lead = (int) gmdate( 'N', $ts1 ) - 1; // Monday first.
		$by   = daw_calendar_by_day( $data['month'] );
		?>

		<section class="daw-cal2-sec">
			<div class="daw-cal2-mhead">
				<h2><?php echo esc_html( daw_calendar_fmt( $ts1, 'F Y' ) ); ?> <small><?php echo esc_html( sprintf( /* translators: %s: number of dated events */ _n( '%s date', '%s dates', count( $data['month'] ), 'daw' ), number_format_i18n( count( $data['month'] ) ) ) ); ?></small></h2>
				<div class="daw-cal2-mnav">
					<a href="<?php echo esc_url( $link( array( 'view' => 'month', 'ym' => $prev ) ) ); ?>" rel="nofollow">‹ <?php echo esc_html( daw_calendar_fmt( strtotime( $prev . '-15 UTC' ), 'M' ) ); ?></a>
					<a href="<?php echo esc_url( $link( array( 'view' => 'month', 'ym' => wp_date( 'Y-m' ) ) ) ); ?>" rel="nofollow"><?php esc_html_e( 'Today', 'daw' ); ?></a>
					<a href="<?php echo esc_url( $link( array( 'view' => 'month', 'ym' => $nextm ) ) ); ?>" rel="nofollow"><?php echo esc_html( daw_calendar_fmt( strtotime( $nextm . '-15 UTC' ), 'M' ) ); ?> ›</a>
				</div>
			</div>

			<?php if ( ! $data['month'] ) : ?>
				<p class="daw-empty daw-cal2-nodata"><?php esc_html_e( 'No premiere, episode or finale is dated in this month.', 'daw' ); ?></p>
			<?php endif; ?>

			<div class="daw-cal2-month">
				<div class="daw-cal2-grid" role="grid" aria-label="<?php echo esc_attr( daw_calendar_fmt( $ts1, 'F Y' ) ); ?>">
					<?php foreach ( array( 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ) as $dow ) : ?>
						<div class="daw-cal2-dow" role="columnheader"><?php echo esc_html( daw_calendar_fmt( strtotime( '2026-10-' . ( 5 + array_search( $dow, array( 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ), true ) ) . ' UTC' ), 'D' ) ); ?></div>
					<?php endforeach; ?>

					<?php
					$cells = (int) ceil( ( $lead + $dim ) / 7 ) * 7;
					for ( $i = 0; $i < $cells; $i++ ) :
						$dn  = $i - $lead + 1;
						$out = $dn < 1 || $dn > $dim;
						$key = $out ? '' : $month . '-' . sprintf( '%02d', $dn );
						$cls = 'daw-cal2-cell' . ( $out ? ' is-out' : '' ) . ( $key && $key === $req['today'] ? ' is-today' : '' );
						$ts  = $out ? strtotime( $month . '-01 UTC ' . ( $dn - 1 ) . ' day' ) : 0;
						?>
						<div class="<?php echo esc_attr( $cls ); ?>" role="gridcell">
							<span class="d"><?php echo esc_html( $out ? gmdate( 'j', $ts ) : $dn ); ?></span>
							<?php
							if ( $key && isset( $by[ $key ] ) ) :
								// Premieres and finales first, then episodes; a
								// busy Saturday shows four pills and a count, the
								// full day is in the list under the grid.
								$cell_ev = $by[ $key ];
								usort(
									$cell_ev,
									static function ( $a, $b ) {
										return ( 'ep' === $a['kind'] ) <=> ( 'ep' === $b['kind'] );
									}
								);
								$cell_more = max( 0, count( $cell_ev ) - 4 );
								$cell_ev   = array_slice( $cell_ev, 0, 4 );
								foreach ( $cell_ev as $e ) :
									?>
									<?php
									if ( 'ep' === $e['kind'] ) {
										$pk = 'ep';
										/* translators: %s: episode number */
										$pl = '' !== (string) ( $e['no'] ?? '' ) ? sprintf( __( 'Ep %s', 'daw' ), $e['no'] ) : __( 'New ep', 'daw' );
									} else {
										$pk = 'prem' === $e['kind'] ? 'prem' : 'fin';
										$pl = 'prem' === $e['kind'] ? ( 'film' === $e['type'] ? __( 'Release', 'daw' ) : __( 'Premiere', 'daw' ) ) : __( 'Finale', 'daw' );
									}
									?>
									<a class="daw-cal2-pill <?php echo esc_attr( $pk ); ?>" href="<?php echo esc_url( get_permalink( $e['id'] ) ); ?>" title="<?php echo esc_attr( $pl . ': ' . get_the_title( $e['id'] ) ); ?>"><span><?php echo esc_html( get_the_title( $e['id'] ) ); ?></span><?php if ( 'ep' === $pk ) : ?><em><?php echo esc_html( $pl ); ?></em><?php endif; ?></a>
									<?php
								endforeach;
								if ( $cell_more ) :
									?>
									<a class="daw-cal2-more" href="#d-<?php echo esc_attr( $key ); ?>">
										<?php
										/* translators: %s: number of further events that day */
										echo esc_html( sprintf( __( '+%s more', 'daw' ), number_format_i18n( $cell_more ) ) );
										?>
									</a>
									<?php
								endif;
							endif;
							?>
						</div>
					<?php endfor; ?>
				</div>
				<p class="daw-cal2-legend"><span><i class="prem"></i><?php esc_html_e( 'Premiere', 'daw' ); ?></span><span><i class="ep"></i><?php esc_html_e( 'Episode', 'daw' ); ?></span><span><i class="fin"></i><?php esc_html_e( 'Finale', 'daw' ); ?></span></p>
			</div>

			<div class="daw-cal2-monthlist">
				<?php $days( $data['month'], true ); ?>
			</div>
		</section>

	<?php endif; ?>

	<?php daw_ad_slot( 'daw-ad-leaderboard' ); ?>
</div>

<?php
get_footer();
