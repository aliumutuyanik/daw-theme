<?php
/**
 * Discover Asian World — drawing a quiz.
 *
 * The markup is printed on the server from the record's own rows, and the
 * script only reveals what is already on the page. That means the quiz is in
 * the html a crawler reads, it works at the first paint rather than after a
 * bundle loads, and there is no second stylesheet: it uses the site's own
 * tokens like every other block.
 *
 * The right answers are in the page, which is how a printed answer sheet has
 * always worked. A quiz about which actor is which is not an exam, and hiding
 * the answers behind a request to the server would cost every reader a round
 * trip to protect nothing.
 *
 * @package daw
 */

defined( 'ABSPATH' ) || exit;

/** The whole quiz block, or nothing when the record is not one. */
function daw_quiz_block( $post_id ) {
	if ( ! function_exists( 'daw_quiz_rows' ) ) {
		return;
	}

	$rows = daw_quiz_rows( $post_id );

	if ( ! $rows ) {
		return;
	}

	$kind   = (string) get_post_meta( $post_id, 'daw_quiz_kind', true );
	$kind   = 'rank' === $kind ? 'rank' : 'choice';
	$lead   = trim( (string) get_post_meta( $post_id, 'daw_quiz_lead', true ) );
	$scored = 'no' !== (string) get_post_meta( $post_id, 'daw_quiz_scored', true );
	$total  = count( $rows );
	?>
	<section class="daw-quiz" id="quiz" data-daw-quiz data-kind="<?php echo esc_attr( $kind ); ?>" data-total="<?php echo esc_attr( $total ); ?>">
		<div class="daw-quiz-head">
			<h2><?php esc_html_e( 'The quiz', 'daw' ); ?></h2>
			<?php if ( $lead ) : ?>
				<p class="daw-quiz-lead"><?php echo esc_html( $lead ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( 'rank' === $kind ) : ?>
			<ol class="daw-quiz-rank">
				<?php foreach ( $rows as $i => $row ) : ?>
					<li class="daw-quiz-row"
						data-answer="<?php echo esc_attr( $row['answer'] ); ?>"
						data-name="<?php echo esc_attr( $row['text'] ); ?>"
						data-fact="<?php echo esc_attr( $row['fact'] ?? '' ); ?>"
						data-img="<?php echo esc_attr( $row['image'] ); ?>"
						data-url="<?php echo esc_attr( $row['url'] ); ?>">
						<?php if ( $row['image'] ) : ?>
							<img class="daw-quiz-face" src="<?php echo esc_url( $row['image'] ); ?>" alt="<?php echo esc_attr( $row['text'] ); ?>" width="72" height="72" loading="lazy" decoding="async">
						<?php else : ?>
							<span class="daw-quiz-face daw-quiz-face-empty" aria-hidden="true"></span>
						<?php endif; ?>

						<span class="daw-quiz-name">
							<?php if ( $row['url'] ) : ?>
								<a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['text'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $row['text'] ); ?>
							<?php endif; ?>
						</span>

						<label class="daw-vh" for="daw-q-<?php echo (int) $i; ?>">
							<?php
							/* translators: %s: the name being placed */
							printf( esc_html__( 'Where does %s belong?', 'daw' ), esc_html( $row['text'] ) );
							?>
						</label>
						<select class="daw-quiz-pick" id="daw-q-<?php echo (int) $i; ?>">
							<option value=""><?php esc_html_e( 'Place', 'daw' ); ?></option>
							<?php for ( $n = 1; $n <= $total; $n++ ) : ?>
								<option value="<?php echo (int) $n; ?>"><?php echo (int) $n; ?></option>
							<?php endfor; ?>
						</select>

						<p class="daw-quiz-note" hidden><?php echo esc_html( $row['note'] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php else : ?>
			<ol class="daw-quiz-qs">
				<?php foreach ( $rows as $i => $row ) : ?>
					<li class="daw-quiz-q" data-answer="<?php echo esc_attr( $row['answer'] ); ?>">
						<?php if ( $row['image'] ) : ?>
							<img class="daw-quiz-shot" src="<?php echo esc_url( $row['image'] ); ?>" alt="" width="240" height="240" loading="lazy" decoding="async">
						<?php endif; ?>

						<p class="daw-quiz-ask"><?php echo esc_html( $row['text'] ); ?></p>

						<div class="daw-quiz-opts" role="group" aria-label="<?php echo esc_attr( $row['text'] ); ?>">
							<?php foreach ( $row['options'] as $option ) : ?>
								<button type="button" class="daw-quiz-opt" value="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $option ); ?></button>
							<?php endforeach; ?>
						</div>

						<p class="daw-quiz-note" hidden>
							<?php echo esc_html( $row['note'] ); ?>
							<?php if ( $row['url'] ) : ?>
								<a href="<?php echo esc_url( $row['url'] ); ?>"><?php esc_html_e( 'Read the profile', 'daw' ); ?></a>
							<?php endif; ?>
						</p>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>

		<?php
		$q_src = trim( (string) get_post_meta( $post_id, 'daw_quiz_source', true ) );
		$q_url = trim( (string) get_post_meta( $post_id, 'daw_quiz_source_url', true ) );
		?>

		<div class="daw-quiz-foot">
			<button type="button" class="daw-btn daw-btn-p daw-quiz-check"><?php esc_html_e( 'Check my answers', 'daw' ); ?></button>
			<button type="button" class="daw-btn daw-quiz-again" hidden><?php esc_html_e( 'Start again', 'daw' ); ?></button>
			<p class="daw-quiz-left" role="status" aria-live="polite"></p>
		</div>

		<?php if ( $scored ) : ?>
			<div class="daw-quiz-result" hidden tabindex="-1">
				<p class="daw-quiz-score"><span class="daw-quiz-num"></span> <span class="daw-quiz-of"></span></p>
				<p class="daw-quiz-verdict"></p>

				<?php
				/* THE ANSWER, AS A LIST
				   ---------------------
				   Marking each row right or wrong in place tells a reader how
				   they did. It does not tell them the thing they came for,
				   which is the order itself with the numbers behind it. So the
				   whole ranking is printed at the end, one to ten, with the
				   figure that put each name where it is and a mark against the
				   ones the reader placed correctly. It is the part worth
				   screenshotting, and it is the reason the quiz is worth
				   finishing. */
				?>
				<?php
				/* SAID AGAIN, WHERE IT MATTERS MOST
				   ---------------------------------
				   The lead at the top explains the rules to somebody about to
				   play. This line explains the answer to somebody who has just
				   been told they were wrong, which is the moment they most want
				   to know what the order was actually based on. Same fact, and
				   it belongs in both places. */
				?>
				<p class="daw-quiz-basis">
					<?php if ( 'rank' === $kind && $q_src ) : ?>
						<?php esc_html_e( 'Ranked by', 'daw' ); ?>
						<?php if ( $q_url ) : ?>
							<a href="<?php echo esc_url( $q_url ); ?>" rel="noopener" target="_blank"><?php echo esc_html( $q_src ); ?></a>.
						<?php else : ?>
							<?php echo esc_html( $q_src ); ?>.
						<?php endif; ?>
					<?php elseif ( 'rank' === $kind ) : ?>
						<?php esc_html_e( 'This order is an editorial pick, not a measurement.', 'daw' ); ?>
					<?php endif; ?>
				</p>

				<ol class="daw-quiz-board"></ol>
			</div>
		<?php endif; ?>

		<?php
		/* WHERE THE ORDER COMES FROM
		   --------------------------
		   A ranking quiz tells a reader that one order is right. That is a
		   claim about the world, so it says where it got it. With no source on
		   file it says the order is the editors', which keeps the quiz honest
		   and costs it nothing. */
		if ( 'rank' === $kind ) :
			?>
			<p class="daw-quiz-src">
				<?php if ( $q_src ) : ?>
					<?php esc_html_e( 'The order in this quiz follows', 'daw' ); ?>
					<?php if ( $q_url ) : ?>
						<a href="<?php echo esc_url( $q_url ); ?>" rel="noopener" target="_blank"><?php echo esc_html( $q_src ); ?></a>.
					<?php else : ?>
						<?php echo esc_html( $q_src ); ?>.
					<?php endif; ?>
				<?php else : ?>
					<?php esc_html_e( 'The order in this quiz is an editorial pick, not a measurement.', 'daw' ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * The script, printed once, only on a page that holds a quiz.
 *
 * Inline because it is under two kilobytes and a separate file would be one
 * more request for every reader in order to save nothing for anybody.
 */
add_action( 'wp_footer', 'daw_quiz_script', 20 );
function daw_quiz_script() {
	// The queried object, not the loop: by the footer the loop has ended and
	// get_the_ID() answers for whatever ran last, which on a record page is a
	// related item rather than the record.
	if ( ! is_singular( 'post' ) || ! function_exists( 'daw_is_quiz' ) || ! daw_is_quiz( get_queried_object_id() ) ) {
		return;
	}
	?>
	<script id="daw-quiz-js">
	(function () {
		var box = document.querySelector('[data-daw-quiz]');
		if (!box) { return; }

		var kind   = box.dataset.kind;
		var check  = box.querySelector('.daw-quiz-check');
		var again  = box.querySelector('.daw-quiz-again');
		var left   = box.querySelector('.daw-quiz-left');
		var result = box.querySelector('.daw-quiz-result');
		var num    = box.querySelector('.daw-quiz-num');
		var of     = box.querySelector('.daw-quiz-of');
		var verdict= box.querySelector('.daw-quiz-verdict');
		var board  = box.querySelector('.daw-quiz-board');
		var rows   = Array.prototype.slice.call(box.querySelectorAll(kind === 'rank' ? '.daw-quiz-row' : '.daw-quiz-q'));
		var picks  = Array.prototype.slice.call(box.querySelectorAll('.daw-quiz-pick'));

		/* ONE PLACE PER NAME
		   ------------------
		   The ranking quiz let a reader put 1 next to three different actors
		   and then scored all three as wrong, which reads as the quiz being
		   broken rather than the answer being wrong. Picking a number that
		   somebody else already has now swaps the two, the way a seating plan
		   works: nobody is left without a seat and no seat has two people in
		   it. Disabling taken numbers was the other option and it is worse,
		   because it makes changing your mind a chore. */
		picks.forEach(function (sel) {
			sel.dataset.prev = sel.value;

			sel.addEventListener('change', function () {
				var value = sel.value;

				if (value) {
					picks.forEach(function (other) {
						if (other !== sel && other.value === value) {
							other.value = sel.dataset.prev || '';
							other.dataset.prev = other.value;
						}
					});
				}

				sel.dataset.prev = value;
				countLeft();
			});
		});

		if (kind === 'choice') {
			box.addEventListener('click', function (e) {
				var opt = e.target.closest('.daw-quiz-opt');
				if (!opt || box.classList.contains('is-done')) { return; }
				var q = opt.closest('.daw-quiz-q');
				q.querySelectorAll('.daw-quiz-opt').forEach(function (b) {
					b.classList.remove('is-picked');
					b.setAttribute('aria-pressed', 'false');
				});
				opt.classList.add('is-picked');
				opt.setAttribute('aria-pressed', 'true');
				countLeft();
			});
		}

		function answered(row) {
			if (kind === 'rank') {
				var sel = row.querySelector('.daw-quiz-pick');
				return sel ? sel.value : '';
			}
			var picked = row.querySelector('.daw-quiz-opt.is-picked');
			return picked ? picked.value : '';
		}

		function remaining() {
			return rows.filter(function (row) { return String(answered(row)).trim() === ''; }).length;
		}

		/* HOW MANY ARE LEFT, SAID OUT LOUD
		   --------------------------------
		   Check my answers used to score an empty quiz as nought out of ten,
		   which tells a reader they failed when what happened is that they had
		   not started. The button stays available, and the line under it says
		   what is still missing. */
		function countLeft() {
			if (!left) { return; }
			var n = remaining();
			left.textContent = n === 0 ? '' : (n === 1 ? '1 still to answer' : n + ' still to answer');
			check.disabled = n > 0;
		}

		function say(right, total) {
			if (!verdict) { return ''; }
			var pct = total ? Math.round((right / total) * 100) : 0;
			if (pct === 100) { return 'Every one. You have watched the lot.'; }
			if (pct >= 80)   { return 'Close to the whole thing. One or two slipped.'; }
			if (pct >= 50)   { return 'About half. The ones you missed are worth a look.'; }
			if (pct > 0)     { return 'A few. Open the profiles under the ones you got wrong.'; }
			return 'None this time. The notes under each answer say why.';
		}

		function drawBoard() {
			if (!board || kind !== 'rank') { return; }

			var order = rows.slice().sort(function (a, b) {
				return parseInt(a.dataset.answer, 10) - parseInt(b.dataset.answer, 10);
			});

			board.innerHTML = '';

			order.forEach(function (row) {
				var ok   = row.classList.contains('is-right');
				var li   = document.createElement('li');
				li.className = 'daw-quiz-bi' + (ok ? ' is-right' : '');

				var img = row.dataset.img
					? '<img src="' + row.dataset.img + '" alt="" width="44" height="44" loading="lazy" decoding="async">'
					: '<span class="daw-quiz-bi-face"></span>';

				var name = row.dataset.url
					? '<a href="' + row.dataset.url + '">' + row.dataset.name + '</a>'
					: row.dataset.name;

				li.innerHTML =
					'<span class="daw-quiz-bi-n">' + row.dataset.answer + '</span>' +
					img +
					'<span class="daw-quiz-bi-t">' + name +
						(row.dataset.fact ? '<span class="daw-quiz-bi-f">' + row.dataset.fact + '</span>' : '') +
					'</span>' +
					'<span class="daw-quiz-bi-m">' + (ok ? 'You had it' : '') + '</span>';

				board.appendChild(li);
			});
		}

		check.addEventListener('click', function () {
			if (remaining() > 0) { countLeft(); return; }

			var right = 0;

			rows.forEach(function (row) {
				var given = String(answered(row)).trim().toLowerCase();
				var want  = String(row.dataset.answer || '').trim().toLowerCase();
				var ok    = given !== '' && given === want;

				if (ok) { right++; }

				row.classList.add(ok ? 'is-right' : 'is-wrong');

				var note = row.querySelector('.daw-quiz-note');
				if (note && note.textContent.trim() !== '') { note.hidden = false; }

				if (kind === 'choice') {
					row.querySelectorAll('.daw-quiz-opt').forEach(function (b) {
						b.disabled = true;
						if (String(b.value).trim().toLowerCase() === want) { b.classList.add('is-answer'); }
					});
				} else {
					var sel = row.querySelector('.daw-quiz-pick');
					if (sel) { sel.disabled = true; }

					/* The right place, shown next to the wrong one. Being told
					   you are wrong without being told what the answer was is
					   the most annoying thing a quiz can do. */
					var tag = row.querySelector('.daw-quiz-right');
					if (!tag) {
						tag = document.createElement('span');
						tag.className = 'daw-quiz-right';
						row.appendChild(tag);
					}
					tag.textContent = ok
						? ('Correct, number ' + row.dataset.answer)
						: ('You said ' + given + '. The answer is ' + row.dataset.answer + '.');
				}
			});

			box.classList.add('is-done');
			check.hidden = true;
			again.hidden = false;
			if (left) { left.textContent = ''; }

			if (result) {
				if (num) { num.textContent = right + ' / ' + rows.length; }
				if (of)  { of.textContent  = 'right'; }
				if (verdict) { verdict.textContent = say(right, rows.length); }
				if (board) { drawBoard(); }
				result.hidden = false;
				result.focus();
				result.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			}
		});

		again.addEventListener('click', function () {
			rows.forEach(function (row) {
				row.classList.remove('is-right', 'is-wrong');

				var note = row.querySelector('.daw-quiz-note');
				if (note) { note.hidden = true; }

				var tag = row.querySelector('.daw-quiz-right');
				if (tag) { tag.textContent = ''; }

				var sel = row.querySelector('.daw-quiz-pick');
				if (sel) { sel.disabled = false; sel.value = ''; sel.dataset.prev = ''; }

				row.querySelectorAll('.daw-quiz-opt').forEach(function (b) {
					b.disabled = false;
					b.classList.remove('is-picked', 'is-answer');
					b.setAttribute('aria-pressed', 'false');
				});
			});

			box.classList.remove('is-done');
			check.hidden = false;
			again.hidden = true;
			if (result) { result.hidden = true; }
			countLeft();
			box.scrollIntoView({ block: 'start' });
		});

		countLeft();
	}());
	</script>
	<?php
}
