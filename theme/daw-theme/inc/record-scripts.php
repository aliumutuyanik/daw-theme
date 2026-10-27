<?php
/**
 * The behaviour every record page shares.
 *
 * Three small things: the trailer facade, the soundtrack player, and the rating
 * widget. They used to live at the bottom of the title template, which meant a
 * profile page could not have a rating without copying forty lines of
 * javascript into a second file. One function, called from both templates.
 *
 * It stays inline rather than becoming a request of its own: the whole thing is
 * under 3KB and it is needed on first paint of every record.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

function daw_record_scripts() {
	?>
<script>
/* The trailer facade swaps for the real player only on a click. Until then
   nothing has been requested from YouTube and no cookie exists. */
document.addEventListener( 'click', function ( e ) {
	var btn = e.target.closest( '.daw-embed-play' );
	if ( ! btn ) { return; }
	var box = btn.closest( '.daw-embed' );
	var id  = box && box.dataset.yt;
	if ( ! id ) { return; }

	var f = document.createElement( 'iframe' );
	f.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0';
	f.title = <?php echo wp_json_encode( __( 'Trailer', 'daw' ) ); ?>;
	f.allow = 'accelerometer; autoplay; encrypted-media; picture-in-picture';
	f.allowFullscreen = true;
	box.innerHTML = '';
	box.appendChild( f );
} );

/* Spotify follows the same rule as the trailer: nothing is requested and no
   cookie is set until the reader asks, except where they have already said
   yes to embedded media, in which case the player is simply there. */
function dawLoadSpotify( box ) {
	var url = box && box.dataset.spotify;
	if ( ! url ) { return; }

	/* artist was missing from this list. A title record stores an album url and
	   a person record stores an artist url, and the person template shipped
	   before anyone put an artist url in the field, so the omission only
	   surfaced when one arrived: the card rendered, the button did nothing. */
	var m = url.match( /spotify\.com\/(?:intl-[a-z]{2}\/)?(album|playlist|track|artist)\/([A-Za-z0-9]+)/ );
	/* NO NEW TAB. EVER.
	   This used to open Spotify in a new tab when it could not read the url,
	   which sent the reader off the page we spent the whole record building.
	   A url we cannot parse is our data problem, not the reader's exit. The
	   card stays as it is and the tracklist underneath still answers the
	   question they came with. */
	if ( ! m ) { return; }

	var f = document.createElement( 'iframe' );
	f.src = 'https://open.spotify.com/embed/' + m[1] + '/' + m[2];
	f.width = '100%';
	/* The card reserved a height before anything loaded, and the player has to
	   be exactly that tall or the page moves. The template decides which:
	   an album on a title page gets the compact 152px strip, an artist on a
	   person page gets the full 352px player, because that one lists several
	   tracks and a reader who can pick a track stays and listens instead of
	   leaving to find one. */
	f.height = String( parseInt( box.dataset.playerHeight, 10 ) || 152 );
	f.style.border = '0';
	f.style.borderRadius = '12px';
	f.loading = 'lazy';
	f.allow = 'clipboard-write; encrypted-media; fullscreen; picture-in-picture';
	f.title = <?php echo wp_json_encode( __( 'Soundtrack player', 'daw' ) ); ?>;
	box.replaceWith( f );
}

document.addEventListener( 'click', function ( e ) {
	var btn = e.target.closest( '[data-spotify-load]' );
	if ( ! btn ) { return; }
	dawLoadSpotify( btn.closest( '[data-spotify]' ) );
} );

/* The height is reserved by the card, so swapping it for the real player moves
   nothing on the page. */
document.querySelectorAll( '[data-spotify-auto]' ).forEach( dawLoadSpotify );

/* Ratings. One vote per browser, remembered in localStorage: a courtesy lock,
   not a security boundary. Storage throws in private windows and with site data
   blocked, so every read and write is wrapped. */
( function () {
	var box = document.querySelector( '.daw-rate' );
	if ( ! box ) { return; }

	var postId = box.dataset.post;
	var key    = 'daw_rated_' + postId;

	function already() {
		try { return window.localStorage.getItem( key ); } catch ( e ) { return null; }
	}
	function lock( s ) {
		try { window.localStorage.setItem( key, String( s ) ); } catch ( e ) {}
	}
	var line = box.querySelector( '[data-count]' );
	function say( mode, s, n ) {
		if ( ! line ) { return; }
		var t = line.dataset;
		if ( 'thanks' === mode ) {
			line.textContent = n <= 1 ? t.tFirstThanks : ( 1 === n ? t.tThanksOne : t.tThanks.replace( '%s', n ) );
		} else {
			line.textContent = n <= 1 ? t.tMineOne.replace( '%s', s ) : t.tMine.replace( '%1$s', s ).replace( '%2$s', n );
		}
	}
	function paint( s ) {
		box.querySelectorAll( '[data-score-btn]' ).forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', b.dataset.scoreBtn === String( s ) ? 'true' : 'false' );
			b.disabled = true;
		} );
	}

	var prior = already();
	if ( prior ) {
		paint( prior );
		say( 'mine', prior, parseInt( line ? line.dataset.n : '0', 10 ) || 1 );
	}

	box.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-score-btn]' );
		if ( ! btn || already() ) { return; }

		var score = btn.dataset.scoreBtn;
		lock( score );
		paint( score );
		say( 'thanks', score, ( parseInt( line ? line.dataset.n : '0', 10 ) || 0 ) + 1 );

		fetch( <?php echo wp_json_encode( esc_url_raw( rest_url( 'daw/v1/rate' ) ) ); ?>, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { post_id: postId, score: score } )
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( d ) {
			if ( ! d || typeof d.avg === 'undefined' ) { return; }
			/* The score line starts hidden on an unrated title, because a dash
			   the size of a score reads as a score. The first vote is what
			   brings it out. */
			var head = box.querySelector( '.daw-rate-head' );
			if ( head && d.count ) { head.hidden = false; }
			box.querySelector( '[data-score]' ).textContent = d.count ? d.avg.toFixed( 1 ) : '';
			if ( line && d.count ) {
				line.dataset.n = d.count;
				say( 'thanks', score, d.count );
			}
			/* The phone-only score cell in the dates row. */
			var cell = document.querySelector( '[data-score-cell]' );
			if ( cell && d.count ) {
				cell.hidden = false;
				cell.querySelector( '[data-score-cell-v]' ).textContent = d.avg.toFixed( 1 );
			}
		} )
		.catch( function () {} );
	} );
}() );

/* The filmography filter hides cards rather than re-querying: the whole list is
   already on the page, so a filter that went back to the server would be slower
   and would lose the reader's place. Groups with nothing left to show hide
   themselves, so no heading is ever left standing over an empty row. */
( function () {
	var bar = document.querySelector( '[data-film-filter]' );
	if ( ! bar ) { return; }

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-film-filter]' );
		if ( ! btn ) { return; }

		var want = btn.dataset.filmFilter;

		document.querySelectorAll( '[data-film-filter]' ).forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', String( b === btn ) );
		} );

		document.querySelectorAll( '#filmography [data-kind]' ).forEach( function ( el ) {
			el.hidden = ( want !== 'all' && el.dataset.kind !== want );
		} );

		document.querySelectorAll( '#filmography .daw-filmgroup' ).forEach( function ( group ) {
			group.hidden = ! group.querySelector( '[data-kind]:not([hidden])' );
		} );
	} );
} )();

/* Saving is a per-reader convenience kept in this browser. It is deliberately
   not an account: nobody should have to sign up to bookmark an actor. */
( function () {
	var KEY = 'daw_saved';

	function read() {
		try { return JSON.parse( window.localStorage.getItem( KEY ) || '[]' ); } catch ( e ) { return []; }
	}

	function write( list ) {
		try { window.localStorage.setItem( KEY, JSON.stringify( list ) ); } catch ( e ) {}
	}

	document.querySelectorAll( '[data-save]' ).forEach( function ( btn ) {
		var id = btn.dataset.save;
		if ( read().indexOf( id ) !== -1 ) { btn.setAttribute( 'aria-pressed', 'true' ); }

		btn.addEventListener( 'click', function () {
			var list = read();
			var at   = list.indexOf( id );
			if ( at === -1 ) { list.push( id ); } else { list.splice( at, 1 ); }
			write( list );
			btn.setAttribute( 'aria-pressed', String( at === -1 ) );
		} );
	} );
} )();
</script>
	<?php
}
