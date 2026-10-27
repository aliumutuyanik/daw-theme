<?php
/**
 * The search results page.
 *
 * WHAT IT REPLACES
 * ----------------
 * Astra's default, which printed each result as a blog post: an excerpt, a
 * "Read More", and the title a second time under the first. A search for a
 * person returned three news stories that mention them before the person, and
 * the only way to tell a drama from an actor was to read the URL.
 *
 * WHAT A RESULT IS HERE
 * ---------------------
 * A row that says what the thing is and why it matched. The type is stated in
 * plain words on the right, the matched part of the name is marked in the
 * title, and the line underneath carries the facts that tell two similar
 * records apart: the country and the credit count for a person, the network and
 * the episode count for a drama, the year for a film.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

get_header();

$query = get_search_query();
$ids   = function_exists( 'daw_search_ids' ) ? daw_search_ids( $query ) : array();

// Counted before anything is drawn, because the tabs above the results are the
// same search that produced them rather than a second guess at it.
$groups = function_exists( 'daw_search_groups' ) ? daw_search_groups( $ids ) : array();
$tabs   = function_exists( 'daw_search_tabs' ) ? daw_search_tabs() : array();

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$tab = isset( $_GET['in'] ) ? sanitize_key( wp_unslash( $_GET['in'] ) ) : '';
$pg  = isset( $_GET['pg'] ) ? max( 1, absint( $_GET['pg'] ) ) : 1;
// phpcs:enable

if ( ! isset( $groups[ $tab ] ) ) {
	$tab = '';
}

$shown = $tab ? $groups[ $tab ] : $ids;
$total = count( $shown );
$per   = defined( 'DAW_SEARCH_PER_PAGE' ) ? DAW_SEARCH_PER_PAGE : 20;
$pages = max( 1, (int) ceil( $total / $per ) );
$pg    = min( $pg, $pages );
$page  = array_slice( $shown, ( $pg - 1 ) * $per, $per );

// DID YOU MEAN
// ------------
// Readers rarely know how a name is romanised. When a search finds little, the
// records a typo or two away are offered, and when it finds nothing they are
// shown as the results. Asked for only then, and remembered per phrase.
$near = array();
if ( '' !== trim( $query ) && count( $ids ) < 3 && function_exists( 'daw_search_near' ) ) {
	$near = array_values( array_diff( daw_search_near( $query, 6 ), $ids ) );
}

// One search, one count. Recorded only on the first page of a real query, so a
// reader paging through does not vote for their own phrase five times.
if ( 1 === $pg && '' === $tab && function_exists( 'daw_search_log' ) && '' !== trim( $query ) ) {
	daw_search_log( $query );
}

$base = home_url( '/' );

/** The url for this search with one thing changed. */
$search_url = static function ( $args ) use ( $query, $tab, $base ) {
	$q = array( 's' => $query );

	$in = array_key_exists( 'in', $args ) ? $args['in'] : $tab;

	if ( $in ) {
		$q['in'] = $in;
	}

	if ( ! empty( $args['pg'] ) && $args['pg'] > 1 ) {
		$q['pg'] = (int) $args['pg'];
	}

	return add_query_arg( $q, $base );
};
?>

<header class="daw-shero">
	<div class="daw-wrap">
		<?php
		// Every page has one h1 that says what it is. The search page had none:
		// its first heading was the "Not what you were looking for?" h2 at the
		// foot of the results. The field is the visual heading, so the h1 is
		// for the outline and for assistive technology, not drawn twice.
		?>
		<h1 class="daw-vh">
			<?php
			if ( '' !== trim( $query ) ) {
				/* translators: %s: what the reader searched for */
				printf( esc_html__( 'Search results for "%s"', 'daw' ), esc_html( $query ) );
			} else {
				esc_html_e( 'Search Discover Asian World', 'daw' );
			}
			?>
		</h1>
		<form class="daw-sform" role="search" method="get" action="<?php echo esc_url( $base ); ?>">
			<label class="daw-vh" for="daw-sq"><?php esc_html_e( 'Search titles and people', 'daw' ); ?></label>
			<svg class="daw-sform-i" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false">
				<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
			</svg>
			<input id="daw-sq" type="search" name="s" value="<?php echo esc_attr( $query ); ?>"
				placeholder="<?php esc_attr_e( 'Search titles, people…', 'daw' ); ?>" autocomplete="off">
			<span class="daw-sform-hint"><?php esc_html_e( 'Press Enter to search', 'daw' ); ?></span>
		</form>

		<?php if ( $ids ) : ?>
			<nav class="daw-stabs" aria-label="<?php esc_attr_e( 'Result types', 'daw' ); ?>">
				<a class="daw-stab<?php echo '' === $tab ? ' is-on' : ''; ?>" href="<?php echo esc_url( $search_url( array( 'in' => '' ) ) ); ?>">
					<?php esc_html_e( 'All', 'daw' ); ?>
					<span><?php echo esc_html( number_format_i18n( count( $ids ) ) ); ?></span>
				</a>
				<?php foreach ( $groups as $slug => $group ) : ?>
					<a class="daw-stab<?php echo $slug === $tab ? ' is-on' : ''; ?>" href="<?php echo esc_url( $search_url( array( 'in' => $slug ) ) ); ?>">
						<?php echo esc_html( $tabs[ $slug ]['label'] ); ?>
						<span><?php echo esc_html( number_format_i18n( count( $group ) ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
	</div>
</header>

<div class="daw-wrap">
	<div class="daw-scols">
		<div class="daw-smain">
			<?php if ( '' === trim( $query ) ) : ?>
				<p class="daw-snote"><?php esc_html_e( 'Type a name, a title or a dish and press Enter.', 'daw' ); ?></p>
			<?php elseif ( $ids ) : ?>
				<?php if ( $near ) : ?>
					<p class="daw-snote">
						<?php
						printf(
							/* translators: %s: a record name, linked */
							esc_html__( 'Did you mean %s?', 'daw' ),
							'<a href="' . esc_url( get_permalink( $near[0] ) ) . '"><strong>' . esc_html( get_the_title( $near[0] ) ) . '</strong></a>'
						);
						?>
					</p>
				<?php endif; ?>
				<p class="daw-snote">
					<?php
					printf(
						/* translators: 1: number of results, 2: the search phrase */
						esc_html( _n( '%1$s result for “%2$s”', '%1$s results for “%2$s”', $total, 'daw' ) ),
						esc_html( number_format_i18n( $total ) ),
						esc_html( $query )
					);
					?>
				</p>

				<div class="daw-sresults">
					<?php
					foreach ( $page as $rid ) {
						daw_search_row( $rid, $query );
					}
					?>
				</div>

				<?php if ( $pages > 1 ) : ?>
					<nav class="daw-pager" aria-label="<?php esc_attr_e( 'Pagination', 'daw' ); ?>">
						<?php if ( $pg > 1 ) : ?>
							<a class="daw-pager-o" href="<?php echo esc_url( $search_url( array( 'pg' => $pg - 1 ) ) ); ?>" rel="prev">‹</a>
						<?php endif; ?>
						<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
							<a class="daw-pager-o<?php echo $i === $pg ? ' is-on' : ''; ?>" href="<?php echo esc_url( $search_url( array( 'pg' => $i ) ) ); ?>"><?php echo esc_html( number_format_i18n( $i ) ); ?></a>
						<?php endfor; ?>
						<?php if ( $pg < $pages ) : ?>
							<a class="daw-pager-o" href="<?php echo esc_url( $search_url( array( 'pg' => $pg + 1 ) ) ); ?>" rel="next">›</a>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			<?php elseif ( $near ) : ?>
				<p class="daw-snote">
					<?php
					printf(
						/* translators: 1: the search phrase, 2: the closest record name, linked */
						esc_html__( 'Nothing matched “%1$s” exactly. Did you mean %2$s?', 'daw' ),
						esc_html( $query ),
						'<a href="' . esc_url( get_permalink( $near[0] ) ) . '"><strong>' . esc_html( get_the_title( $near[0] ) ) . '</strong></a>'
					);
					?>
				</p>

				<div class="daw-sresults">
					<?php
					foreach ( $near as $rid ) {
						daw_search_row( $rid, '' );
					}
					?>
				</div>
			<?php else : ?>
				<p class="daw-snote">
					<?php
					printf(
						/* translators: %s: the search phrase */
						esc_html__( 'Nothing matched “%s”.', 'daw' ),
						esc_html( $query )
					);
					?>
				</p>
			<?php endif; ?>

			<?php
			// THE HONEST NOTE
			// ---------------
			// Most searches that fail here fail for one of two reasons, and
			// both of them are ours rather than the reader's: the name is
			// romanised differently from the way we file it, or the record does
			// not exist yet. Saying so is more use than "try another search",
			// and the two doors out are the two things they can actually do.
			if ( '' !== trim( $query ) ) :
				?>
				<aside class="daw-smiss">
					<h2><?php esc_html_e( 'Not what you were looking for?', 'daw' ); ?></h2>
					<p><?php esc_html_e( 'Names are often romanised more than one way (Kim Soo-hyun, Kim Soohyun), and some records are not written yet. Browsing usually finds it faster than guessing the spelling.', 'daw' ); ?></p>
					<p class="daw-smiss-do">
						<?php
						$countries = daw_page_url( 'south-korea-korean-dramas-movies-culture' );
						$contact   = daw_page_url( 'contact' );

						if ( $countries ) {
							printf( '<a class="daw-btn" href="%s">%s</a>', esc_url( $countries ), esc_html__( 'Browse by country', 'daw' ) );
						}

						if ( $contact ) {
							printf( '<a class="daw-btn" href="%s">%s</a>', esc_url( $contact ), esc_html__( 'Tell us what is missing', 'daw' ) );
						}
						?>
					</p>
				</aside>
			<?php endif; ?>
		</div>

		<aside class="daw-srail">
			<?php
			$trending = function_exists( 'daw_search_trending' ) ? daw_search_trending( 6 ) : array();

			// Printed only when there is something true to print. An invented
			// list of popular searches is the easiest thing on this page to
			// fake and the least excusable.
			if ( $trending ) :
				?>
				<div class="daw-rcard">
					<h2 class="daw-rcard-h"><?php esc_html_e( 'What people search for here', 'daw' ); ?></h2>
					<ul class="daw-strend">
						<?php foreach ( $trending as $phrase ) : ?>
							<li>
								<a href="<?php echo esc_url( add_query_arg( 's', rawurlencode( $phrase ), $base ) ); ?>"><?php echo esc_html( $phrase ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php daw_ad_slot( 'daw-ad-rail' ); ?>
		</aside>
	</div>
</div>

<?php
get_footer();
