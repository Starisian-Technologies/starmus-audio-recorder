<?php
/**
 * Starmus Recordings List Template
 *
 * Lists audio recordings with accessible cards and custom pagination.
 *
 * @var WP_Query $query The custom query object containing recordings.
 * @var string   $edit_page_url (Optional) URL to the edit page.
 */

if ( $query->have_posts() ) { ?>

	<section aria-labelledby="starmus-recordings-heading">
		<h2 id="starmus-recordings-heading" class="starmus-visually-hidden">
			<?php esc_html_e( 'Your audio recordings', 'starmus-audio-recorder' ); ?>
		</h2>

		<div class="starmus-recordings-grid">
			<?php
			while ( $query->have_posts() ) {
				$query->the_post();
				$current_post_id = get_the_ID();
				$post_title      = get_the_title();

				// === PRODUCTION DATA RESOLUTION ===
				// 1. Prioritize Mastered MP3 (Best for web playback)
				$audio_att_id = (int) get_post_meta( $current_post_id, 'mastered_mp3', true );
				
				// 2. Fallback to Original Source (Newer system)
				if ( ! $audio_att_id ) {
					$audio_att_id = (int) get_post_meta( $current_post_id, 'audio_files_originals', true );
				}

				// 3. Fallback to Legacy Attachment ID
				if ( ! $audio_att_id ) {
					$audio_att_id = (int) get_post_meta( $current_post_id, '_audio_attachment_id', true );
				}

				// 4. Resolve URL & MIME Type
				$audio_url = $audio_att_id ? wp_get_attachment_url( $audio_att_id ) : '';
				
				$mime_type = 'audio/mpeg'; // Default
				if ( $audio_url ) {
					$ext = strtolower( pathinfo( $audio_url, PATHINFO_EXTENSION ) );
					if ( 'wav' === $ext ) $mime_type = 'audio/wav';
					if ( 'webm' === $ext ) $mime_type = 'audio/webm';
					if ( 'm4a' === $ext ) $mime_type = 'audio/mp4';
				}

				// 5. Additional Metadata
				$recording_type = get_the_terms( $current_post_id, 'recording_type' );
				$language       = get_the_terms( $current_post_id, 'language' );
				$duration       = get_post_meta( $current_post_id, 'audio_duration', true );
				?>

				<article class="starmus-card sparxstar-glass-card" aria-labelledby="card-title-<?php echo intval( $current_post_id ); ?>">
					<div class="starmus-card__header">
						<h3 id="card-title-<?php echo intval( $current_post_id ); ?>" class="starmus-card__title">
							<a href="<?php the_permalink(); ?>" class="starmus-card__link">
								<?php echo esc_html( $post_title ); ?>
							</a>
						</h3>
						
						<div class="starmus-card__meta">
							<span class="starmus-card__date">
								<span class="screen-reader-text"><?php esc_html_e( 'Recorded on:', 'starmus-audio-recorder' ); ?></span>
								<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
									<?php echo esc_html( get_the_date( 'M j, Y' ) ); ?>
								</time>
							</span>

							<?php if ( ! empty( $recording_type ) && ! is_wp_error( $recording_type ) ) { ?>
								<span class="starmus-card__type">
									<span class="screen-reader-text"><?php esc_html_e( 'Type:', 'starmus-audio-recorder' ); ?></span>
									<?php echo esc_html( $recording_type[0]->name ); ?>
								</span>
							<?php } ?>

							<?php if ( ! empty( $language ) && ! is_wp_error( $language ) ) { ?>
								<span class="starmus-card__language">
									<span class="screen-reader-text"><?php esc_html_e( 'Language:', 'starmus-audio-recorder' ); ?></span>
									<?php echo esc_html( $language[0]->name ); ?>
								</span>
							<?php } ?>
						</div>
					</div>

					<?php if ( $audio_url ) { ?>
						<div class="starmus-card__audio">
							<audio controls preload="none" class="starmus-audio-player" style="width: 100%;">
								<source src="<?php echo esc_url( $audio_url ); ?>" type="<?php echo esc_attr( $mime_type ); ?>">
								<?php esc_html_e( 'Your browser does not support the audio element.', 'starmus-audio-recorder' ); ?>
							</audio>
							
							<?php if ( $duration ) { ?>
								<div class="starmus-card__duration" aria-hidden="true">
									<?php echo esc_html( gmdate( 'i:s', (int) $duration ) ); ?>
								</div>
								<span class="screen-reader-text">
									<?php printf( esc_html__( 'Duration: %s', 'starmus-audio-recorder' ), gmdate( 'i:s', (int) $duration ) ); ?>
								</span>
							<?php } ?>
						</div>
					<?php } ?>

					<div class="starmus-card__actions">
						<a href="<?php echo esc_url( get_permalink( $current_post_id ) ); ?>" class="starmus-btn starmus-btn--outline" aria-label="<?php echo esc_attr( sprintf( __( 'View details for %s', 'starmus-audio-recorder' ), $post_title ) ); ?>">
							<?php esc_html_e( 'View Details', 'starmus-audio-recorder' ); ?>
						</a>

						<?php if ( ! empty( $edit_page_url ) && current_user_can( 'edit_post', $current_post_id ) ) { ?>
							<?php
							// Use 'recording_id' for consistency with Editor/Recorder templates
							$edit_link = add_query_arg( 'recording_id', $current_post_id, $edit_page_url );
							$secure_edit_link = wp_nonce_url( $edit_link, 'starmus_edit_audio_' . $current_post_id, 'nonce' );
							?>
							<a href="<?php echo esc_url( $secure_edit_link ); ?>" class="starmus-btn starmus-btn--primary" aria-label="<?php echo esc_attr( sprintf( __( 'Edit audio for %s', 'starmus-audio-recorder' ), $post_title ) ); ?>">
								<?php esc_html_e( 'Edit Audio', 'starmus-audio-recorder' ); ?>
							</a>
						<?php } ?>
					</div>
				</article>

			<?php } ?>
		</div><!-- .starmus-recordings-grid -->
	</section>

	<?php
	// === ROBUST CUSTOM QUERY PAGINATION ===
	
	// Handle 'paged' (archives) vs 'page' (static front page)
	$current_page = max( 1, get_query_var( 'paged' ), get_query_var( 'page' ) );

	$pagination_links = paginate_links( [
		'base'      => str_replace( 999999999, '%#%', esc_url( get_pagenum_link( 999999999 ) ) ),
		'format'    => '?paged=%#%',
		'current'   => $current_page,
		'total'     => $query->max_num_pages, // Uses specific custom query total
		'prev_text' => '<span aria-hidden="true">&laquo;</span> <span class="screen-reader-text">' . __( 'Previous page', 'starmus-audio-recorder' ) . '</span>',
		'next_text' => '<span class="screen-reader-text">' . __( 'Next page', 'starmus-audio-recorder' ) . '</span> <span aria-hidden="true">&raquo;</span>',
		'type'      => 'array', // Return array for accessible list rendering
	] );

	if ( $pagination_links ) {
		?>
		<nav class="starmus-pagination" aria-label="<?php esc_attr_e( 'Recording list pagination', 'starmus-audio-recorder' ); ?>">
			<ul class="starmus-pagination__list">
				<?php foreach ( $pagination_links as $link ) { ?>
					<li class="starmus-pagination__item">
						<?php echo wp_kses_post( $link ); ?>
					</li>
				<?php } ?>
			</ul>
		</nav>
		<?php
	}
	?>

<?php } else { ?>

	<div class="starmus-no-recordings">
		<p><?php esc_html_e( 'You have not submitted any recordings yet.', 'starmus-audio-recorder' ); ?></p>
	</div>

<?php } ?>

<?php
wp_reset_postdata();
?>