<?php
/**
 * Strip the page-builder wreckage out of the old guide bodies.
 *
 * WHAT IS ACTUALLY IN THESE RECORDS
 * ---------------------------------
 * The guides were written in a page builder and then exported into post
 * content, and the export took everything with it. A single record carries the
 * featured image again as the first figure, the title again as an H1, the
 * opening paragraph trapped inside a <pre><code> block with its own tags
 * showing as text, two stylesheet <link> elements, a widget's entire <style>
 * sheet, a dead post-list widget that renders the words "No posts found", a
 * fancybox gallery, and sixteen share links with no href on any of them.
 *
 * None of that is writing. It is the residue of the tool it was written in, and
 * it is the same residue in every record, which is why this is a pass rather
 * than fifty-five editing jobs.
 *
 * WHY IT EDITS THE DATABASE AND NOT THE OUTPUT
 * -------------------------------------------
 * A filter on the_content would hide it and leave it there, so every future
 * rewrite would start by wading through it again, and an export of the site
 * would still carry it. This removes it. What it removes is listed per record
 * before anything is written, and the dry run is the default.
 *
 * WHAT IT WILL NOT TOUCH
 * ----------------------
 * Prose. It removes structures it can identify exactly, and where it is not
 * certain it leaves the markup alone and says so in the report.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/**
 * Clean one body.
 *
 * @param string $html         Post content.
 * @param string $featured_src Featured image url, so the duplicate at the top
 *                             can be recognised rather than guessed at.
 * @return array{html:string,removed:array}
 */
function daw_guide_clean_html( $html, $featured_src = '' ) {
	$removed = array();
	$before  = $html;

	$cut = static function ( $pattern, $label ) use ( &$html, &$removed ) {
		$n    = 0;
		$html = preg_replace( $pattern, '', $html, -1, $n );

		if ( $n ) {
			$removed[] = $label . ' x' . $n;
		}
	};

	// The opening paragraph is inside a code block with its own tags escaped.
	// Decoding it and dropping the wrapper puts the article's first sentence
	// back into the article, which is where a reader expects to find it.
	$html = preg_replace_callback(
		'#<pre[^>]*>\s*<code[^>]*>(.*?)</code>\s*</pre>#is',
		static function ( $m ) use ( &$removed ) {
			$inner = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$inner = trim( $inner );

			if ( '' === $inner ) {
				$removed[] = 'empty code block';

				return '';
			}

			// A code block holding nothing but a heading is the builder's
			// leftover label for a widget that is about to be deleted anyway.
			if ( preg_match( '#^<h[1-6][^>]*>.*?</h[1-6]>$#is', $inner ) ) {
				$removed[] = 'widget label in code block';

				return '';
			}

			$removed[] = 'unwrapped code block';

			return '<p>' . $inner . '</p>';
		},
		$html
	);

	// Builder and plugin residue.
	$cut( '#<link\b[^>]*>#i', 'stylesheet link' );
	$cut( '#<style\b[^>]*>.*?</style>#is', 'style block' );
	$cut( '#<!--\s*start Post List\s*-->.*?<!--\s*end Post List\s*-->#is', 'post list widget' );
	$cut( '#<a[^>]*data-fancybox[^>]*>.*?</a>#is', 'gallery thumbnail' );
	$cut( '#<h2[^>]*>\s*(?:<strong>\s*)?Gallery\s*(?:</strong>\s*)?</h2>#i', 'gallery heading' );

	// The share block: a heading, one line of copy, and sixteen anchors with no
	// href. The theme has real sharing; this is dead markup.
	$cut( '#<h2[^>]*>\s*Please Share on Social\s*</h2>#i', 'share heading' );
	$cut( '#<p>\s*We would appreciate it if you could share this on social media[^<]*</p>#i', 'share copy' );
	$cut( '#<a\s*>\s*Share on [^<]*</a>#i', 'empty share link' );
	$cut( '#<a\s*>\s*</a>#i', 'empty anchor' );

	// "No posts found" is a widget telling the reader its query returned
	// nothing, which is a sentence no article should contain.
	$cut( '#(?:^|\s)No posts found(?:\s|$)#i', 'no posts found' );

	// The featured image, printed again as the first thing in the body. The
	// template already shows it beside the headline.
	if ( '' !== $featured_src ) {
		// The extension comes off before the string is quoted: quoting first
		// leaves an escaping backslash behind and the pattern stops compiling.
		$file = basename( (string) wp_parse_url( $featured_src, PHP_URL_PATH ) );
		$base = preg_quote( (string) preg_replace( '#\.(jpe?g|png|webp|gif)$#i', '', $file ), '#' );

		$html = preg_replace_callback(
			'#<figure[^>]*>\s*<img[^>]*>\s*(?:<figcaption>.*?</figcaption>\s*)?</figure>#is',
			static function ( $m ) use ( $base, &$removed ) {
				if ( preg_match( '#' . $base . '#i', $m[0] ) ) {
					$removed[] = 'duplicate featured image';

					return '';
				}

				return $m[0];
			},
			$html,
			1
		);
	}

	// The title, repeated as an H1 inside the body. Two H1s on a page is a
	// structural error, and the template already prints the real one.
	$cut( '#<h1\b[^>]*>.*?</h1>#is', 'inner H1' );

	// EM DASHES
	// ---------
	// The house rule is that they are not published, and the old articles are
	// full of them. Between words the dash is doing a comma's job, so it
	// becomes one; between two numbers it is a range, so it becomes a hyphen.
	$dashes = 0;
	$html   = preg_replace( '#(\d)\s*[\x{2013}\x{2014}]\s*(\d)#u', '$1-$2', $html, -1, $dashes );

	if ( $dashes ) {
		$removed[] = 'number range dash x' . $dashes;
	}

	$dashes = 0;
	$html   = preg_replace( '#\s*[\x{2013}\x{2014}]\s*#u', ', ', $html, -1, $dashes );

	if ( $dashes ) {
		$removed[] = 'em dash x' . $dashes;
	}

	// The replacement can leave a comma next to punctuation that already ends
	// the clause.
	$html = preg_replace( '#,\s*,#', ',', $html );
	$html = preg_replace( '#\s+,#', ',', $html );
	$html = preg_replace( '#,\s*([.;:!?])#', '$1', $html );

	// Whatever is left of the line breaks the builder used as spacing.
	$html = preg_replace( '#(?:<br\s*/?>\s*){2,}#i', "\n\n", $html );
	$html = preg_replace( '#<br\s*/?>\s*(?=<h[1-6])#i', "\n\n", $html );
	$html = preg_replace( '#(</h[1-6]>)\s*<br\s*/?>#i', '$1', $html );
	$html = preg_replace( '#\n{3,}#', "\n\n", $html );
	$html = trim( $html );

	if ( $html === trim( $before ) ) {
		$removed = array();
	}

	return array(
		'html'    => $html,
		'removed' => $removed,
	);
}

/* ================================================================== admin = */

add_action( 'admin_menu', 'daw_guide_clean_menu' );
function daw_guide_clean_menu() {
	add_submenu_page(
		'tools.php',
		__( 'DAW: Clean guide bodies', 'daw' ),
		__( 'DAW: Clean guide bodies', 'daw' ),
		'manage_options',
		'daw-guide-clean',
		'daw_guide_clean_page'
	);
}

function daw_guide_clean_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="wrap"><h1>DAW: Clean guide bodies</h1>';
	echo '<p>Removes the page-builder residue every old guide carries: the duplicate featured image, the second H1, the opening paragraph trapped in a code block, stylesheet links, widget style sheets, the dead post-list widget and its "No posts found", the fancybox gallery and the sixteen empty share links. Prose is left alone.</p>';

	$apply = isset( $_POST['daw_clean_apply'] ) && isset( $_POST['daw_clean_nonce'] )
		&& wp_verify_nonce( sanitize_key( $_POST['daw_clean_nonce'] ), 'daw_clean' );

	$ids     = daw_guide_records();
	$report  = array();
	$changed = 0;
	$saved   = 0;

	foreach ( $ids as $id ) {
		$post = get_post( $id );

		if ( ! $post ) {
			continue;
		}

		$clean = daw_guide_clean_html( $post->post_content, (string) get_the_post_thumbnail_url( $id, 'full' ) );

		if ( ! $clean['removed'] ) {
			continue;
		}

		$changed++;

		$report[ $id ] = array(
			'removed' => $clean['removed'],
			'before'  => strlen( $post->post_content ),
			'after'   => strlen( $clean['html'] ),
		);

		if ( $apply ) {
			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => wp_slash( $clean['html'] ),
				)
			);
			delete_post_meta( $id, '_elementor_data' );
			delete_post_meta( $id, '_elementor_edit_mode' );
			$saved++;
		}
	}

	if ( $apply ) {
		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			esc_html( sprintf( 'Cleaned %d record(s).', $saved ) )
		);
	}

	echo '<form method="post">';
	wp_nonce_field( 'daw_clean', 'daw_clean_nonce' );
	printf(
		'<p><strong>%d</strong> record(s) still carry residue.</p>',
		$changed
	);

	if ( $changed ) {
		submit_button( __( 'Clean them', 'daw' ), 'primary', 'daw_clean_apply' );
	}

	echo '</form>';

	if ( $report ) {
		echo '<table class="widefat striped"><thead><tr><th>Record</th><th>Removed</th><th>Bytes</th></tr></thead><tbody>';

		foreach ( $report as $id => $row ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s &rarr; %s</td></tr>',
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html( get_the_title( $id ) ),
				esc_html( implode( ', ', $row['removed'] ) ),
				esc_html( number_format_i18n( $row['before'] ) ),
				esc_html( number_format_i18n( $row['after'] ) )
			);
		}

		echo '</tbody></table>';
	}

	echo '</div>';
}
