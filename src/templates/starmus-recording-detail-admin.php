<?php
/**
 * Starmus Recording Detail - Admin/Editor View
 * Shows comprehensive metadata and technical details for admins and editors
 *
 * @package Starmus\templates
 * @version 0.5.6
 * @var int $post_id The recording post ID
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$audio_url      = get_post_meta( $post_id, 'audio_file_url', true );
$recording_type = get_the_terms( $post_id, 'recording_type' );
$language       = get_the_terms( $post_id, 'language' );
$transcript     = get_post_meta( $post_id, 'first_pass_transcription', true );
$metadata       = get_post_meta( $post_id, 'recording_metadata', true );

$transcript_data = $transcript ? json_decode( $transcript, true ) : null;
$meta_data       = $metadata ? json_decode( $metadata, true ) : null;
$author          = get_userdata( get_post_field( 'post_author', $post_id ) );
?>

<div class="starmus-admin-detail">
	<div class="starmus-admin-detail__grid">
		
		<!-- Main Content -->
		<div class="starmus-admin-detail__main">
			<header class="starmus-detail__header">
				<h1 class="starmus-detail__title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h1>
				<div class="starmus-detail__meta">
					<span class="starmus-meta__author">
						<?php esc_html_e( 'Submitted by:', STARMUS_TEXT_DOMAIN ); ?>
						<strong><?php echo esc_html( $author->display_name ?? 'Unknown' ); ?></strong>
					</span>
					<span class="starmus-meta__date">
						<time datetime="<?php echo esc_attr( get_the_date( 'c', $post_id ) ); ?>">
							<?php echo esc_html( get_the_date( 'F j, Y \a\t g:i A', $post_id ) ); ?>
						</time>
					</span>
				</div>
			</header>

			<?php if ( $audio_url ) : ?>
				<div class="starmus-detail__audio">
					<h2><?php esc_html_e( 'Audio Recording', STARMUS_TEXT_DOMAIN ); ?></h2>
					<audio controls preload="metadata" class="starmus-audio-player--large">
						<source src="<?php echo esc_url( $audio_url ); ?>" type="audio/webm">
						<source src="<?php echo esc_url( $audio_url ); ?>" type="audio/mp4">
						<source src="<?php echo esc_url( $audio_url ); ?>" type="audio/mpeg">
						<?php esc_html_e( 'Your browser does not support the audio element.', STARMUS_TEXT_DOMAIN ); ?>
					</audio>
				</div>
			<?php endif; ?>

			<?php if ( $transcript_data && ! empty( $transcript_data['transcript'] ) ) : ?>
				<div class="starmus-detail__transcript">
					<h2><?php esc_html_e( 'Speech Recognition Results', STARMUS_TEXT_DOMAIN ); ?></h2>
					<div class="starmus-transcript__stats">
						<span class="starmus-stat">
							<?php esc_html_e( 'Segments:', STARMUS_TEXT_DOMAIN ); ?>
							<strong><?php echo count( $transcript_data['transcript'] ); ?></strong>
						</span>
						<?php if ( isset( $transcript_data['detectedLanguage'] ) ) : ?>
							<span class="starmus-stat">
								<?php esc_html_e( 'Detected:', STARMUS_TEXT_DOMAIN ); ?>
								<strong><?php echo esc_html( $transcript_data['detectedLanguage'] ); ?></strong>
							</span>
						<?php endif; ?>
					</div>
					<div class="starmus-transcript__content">
						<?php foreach ( $transcript_data['transcript'] as $segment ) : ?>
							<div class="starmus-transcript__segment" data-timestamp="<?php echo esc_attr( $segment['timestamp'] ?? 0 ); ?>">
								<span class="starmus-transcript__text"><?php echo esc_html( $segment['text'] ?? '' ); ?></span>
								<span class="starmus-transcript__meta">
									<?php if ( isset( $segment['confidence'] ) ) : ?>
										<span class="starmus-confidence" data-confidence="<?php echo esc_attr( $segment['confidence'] ); ?>">
											<?php echo esc_html( round( $segment['confidence'] * 100 ) ); ?>%
										</span>
									<?php endif; ?>
									<?php if ( isset( $segment['timestamp'] ) ) : ?>
										<span class="starmus-timestamp"><?php echo esc_html( gmdate( 'i:s', $segment['timestamp'] / 1000 ) ); ?></span>
									<?php endif; ?>
								</span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<!-- Sidebar with Technical Data -->
		<div class="starmus-admin-detail__sidebar">
			
			<!-- Recording Info -->
			<div class="starmus-info-card">
				<h3><?php esc_html_e( 'Recording Information', STARMUS_TEXT_DOMAIN ); ?></h3>
				<dl class="starmus-info-list">
					<?php if ( $recording_type && ! is_wp_error( $recording_type ) ) : ?>
						<dt><?php esc_html_e( 'Type', STARMUS_TEXT_DOMAIN ); ?></dt>
						<dd><?php echo esc_html( $recording_type[0]->name ); ?></dd>
					<?php endif; ?>
					<?php if ( $language && ! is_wp_error( $language ) ) : ?>
						<dt><?php esc_html_e( 'Language', STARMUS_TEXT_DOMAIN ); ?></dt>
						<dd><?php echo esc_html( $language[0]->name ); ?></dd>
					<?php endif; ?>
					<?php if ( $meta_data && isset( $meta_data['technical'] ) ) : ?>
						<dt><?php esc_html_e( 'Duration', STARMUS_TEXT_DOMAIN ); ?></dt>
						<dd><?php echo esc_html( gmdate( 'i:s', $meta_data['technical']['duration'] / 1000 ) ); ?></dd>
						<dt><?php esc_html_e( 'Sample Rate', STARMUS_TEXT_DOMAIN ); ?></dt>
						<dd><?php echo esc_html( $meta_data['technical']['sampleRate'] ); ?> Hz</dd>
						<dt><?php esc_html_e( 'File Size', STARMUS_TEXT_DOMAIN ); ?></dt>
						<dd><?php echo esc_html( size_format( $meta_data['technical']['fileSize'] ) ); ?></dd>
						<dt><?php esc_html_e( 'Codec', STARMUS_TEXT_DOMAIN ); ?></dt>
						<dd><?php echo esc_html( $meta_data['technical']['codec'] ); ?></dd>
					<?php endif; ?>
				</dl>
			</div>

			<!-- Quality Metrics -->
			<?php if ( $meta_data && isset( $meta_data['technical']['audioProcessing'] ) ) : ?>
				<div class="starmus-info-card">
					<h3><?php esc_html_e( 'Audio Processing', STARMUS_TEXT_DOMAIN ); ?></h3>
					<dl class="starmus-info-list">
						<dt><?php esc_html_e( 'Noise Suppression', STARMUS_TEXT_DOMAIN ); ?></dt>
						<dd><?php echo $meta_data['technical']['audioProcessing']['noiseSuppression'] ? '✓' : '✗'; ?></dd>
						<dt><?php esc_html_e( 'Echo Cancellation', STARMUS_TEXT_DOMAIN ); ?></dt>
						<dd><?php echo $meta_data['technical']['audioProcessing']['echoCancellation'] ? '✓' : '✗'; ?></dd>
						<dt><?php esc_html_e( 'Auto Gain Control', STARMUS_TEXT_DOMAIN ); ?></dt>
						<dd><?php echo $meta_data['technical']['audioProcessing']['autoGainControl'] ? '✓' : '✗'; ?></dd>
						<dt><?php esc_html_e( 'Calibrated', STARMUS_TEXT_DOMAIN ); ?></dt>
						<dd><?php echo $meta_data['technical']['audioProcessing']['isCalibrated'] ? '✓' : '✗'; ?></dd>
					</dl>
				</div>
			<?php endif; ?>

			<!-- Device Information -->
			<?php if ( $meta_data && isset( $meta_data['device'] ) ) : ?>
				<div class="starmus-info-card">
					<h3><?php esc_html_e( 'Device Information', STARMUS_TEXT_DOMAIN ); ?></h3>
					<dl class="starmus-info-list">
						<dt><?php esc_html_e( 'Platform', STARMUS_TEXT_DOMAIN ); ?></dt>
						<dd><?php echo esc_html( $meta_data['device']['platform'] ); ?></dd>
						<dt><?php esc_html_e( 'Screen', STARMUS_TEXT_DOMAIN ); ?></dt>
						<dd><?php echo esc_html( $meta_data['device']['screenResolution'] ); ?></dd>
						<?php if ( isset( $meta_data['device']['connection'] ) && is_array( $meta_data['device']['connection'] ) ) : ?>
							<dt><?php esc_html_e( 'Connection', STARMUS_TEXT_DOMAIN ); ?></dt>
							<dd><?php echo esc_html( $meta_data['device']['connection']['effectiveType'] ?? 'Unknown' ); ?></dd>
						<?php endif; ?>
					</dl>
				</div>
			<?php endif; ?>

			<!-- Unique Identifiers -->
			<?php if ( $meta_data && isset( $meta_data['identifiers'] ) ) : ?>
				<div class="starmus-info-card">
					<h3><?php esc_html_e( 'Corpus Identifiers', STARMUS_TEXT_DOMAIN ); ?></h3>
					<dl class="starmus-info-list">
						<dt><?php esc_html_e( 'Session UUID', STARMUS_TEXT_DOMAIN ); ?></dt>
						<dd><code><?php echo esc_html( $meta_data['identifiers']['sessionUUID'] ); ?></code></dd>
						<dt><?php esc_html_e( 'Submission UUID', STARMUS_TEXT_DOMAIN ); ?></dt>
						<dd><code><?php echo esc_html( $meta_data['identifiers']['submissionUUID'] ); ?></code></dd>
					</dl>
				</div>
			<?php endif; ?>

		</div>
	</div>

	<div class="starmus-detail__actions">
		<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=audio-recording' ) ); ?>" class="starmus-btn starmus-btn--outline">
			<?php esc_html_e( '← Back to All Recordings', STARMUS_TEXT_DOMAIN ); ?>
		</a>
		<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" class="starmus-btn starmus-btn--primary">
			<?php esc_html_e( 'Edit Post', STARMUS_TEXT_DOMAIN ); ?>
		</a>
	</div>
</div>