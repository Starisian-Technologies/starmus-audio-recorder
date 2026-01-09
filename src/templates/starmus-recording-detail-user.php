<?php

/**
 * User Detail Template - Simplified View (starmus-recording-detail-user.php)
 *
 * Loaded by StarmusShortcodeLoader::render_submission_detail_shortcode()
 * when the current user is the author of the recording.
 *
 * @package Starisian\Starmus\templates
 */

if ( ! defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\services\StarmusFileService;

// === 1. INITIALIZATION & DATA RESOLUTION ===

try {
    // 1. Get the current Post ID (Works inside Shortcode and Filter contexts)
    $post_id = get_the_ID();

    // Fallback if specific ID passed via args (future proofing)
    if ( ! $post_id && isset($args['post_id'])) {
        $post_id = intval($args['post_id']);
    }

    if ( ! $post_id) {
        // If loaded outside a loop context, return empty or error
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Starmus Detail] No Post ID found in context.');
        }
        return;
    }

    // 2. Instantiate Services (Since Loader doesn't pass them down)
    $settings = new StarmusSettings();

    // Check if FileService exists before instantiating to prevent fatal errors
    $file_service = class_exists(\Starisian\Sparxstar\Starmus\services\StarmusFileService::class)
    ? new StarmusFileService()
    : null;

    // --- 1. Audio Assets (New Schema) ---
    // ACF fields return URLs when return_format is 'url', but we need attachment IDs
    $mastered_mp3_field    = get_field('mastered_mp3', $post_id);
    $archival_wav_field    = get_field('archival_wav', $post_id);
    $original_source_field = get_field('original_source', $post_id);

    // If ACF returns URLs, we need to get attachment IDs from URLs
    if (is_string($mastered_mp3_field) && ($mastered_mp3_field !== '' && $mastered_mp3_field !== '0')) {
        $mastered_mp3_id = attachment_url_to_postid($mastered_mp3_field);
    } else {
        $mastered_mp3_id = (int) $mastered_mp3_field;
    }

    if (is_string($archival_wav_field) && ($archival_wav_field !== '' && $archival_wav_field !== '0')) {
        $archival_wav_id = attachment_url_to_postid($archival_wav_field);
    } else {
        $archival_wav_id = (int) $archival_wav_field;
    }

    if (is_string($original_source_field) && ($original_source_field !== '' && $original_source_field !== '0')) {
        $original_id = attachment_url_to_postid($original_source_field);
    } else {
        $original_id = (int) $original_source_field;
    }

    // Fallback to legacy fields if new schema fields are empty
    if ($original_id === 0) {
        $original_id = (int) get_post_meta($post_id, '_audio_attachment_id', true);
    }
    if ($mastered_mp3_id === 0) {
        $mastered_mp3_id = (int) get_post_meta($post_id, '_audio_mp3_attachment_id', true);
    }
    if ($archival_wav_id === 0) {
        $archival_wav_id = (int) get_post_meta($post_id, '_audio_wav_attachment_id', true);
    }

    // Consistent URL Resolver function (from Admin template)
    $get_url = function (int $att_id) use ($file_service) {
        if ($att_id <= 0) {
            return '';
        }
        try {
            if ($file_service instanceof \Starisian\Sparxstar\Starmus\services\StarmusFileService) {
                return $file_service->star_get_public_url($att_id);
            }
        } catch (\Throwable) {
        }
        return wp_get_attachment_url($att_id) ?: '';
    };

    $mp3_url      = $get_url($mastered_mp3_id);
    $original_url = $get_url($original_id);
    // Playback preference: Mastered MP3 > Original
    $playback_url = $mp3_url ?: $original_url;

    // --- 2. Additional Metadata (Using Admin Logic for Robustness) ---
    $transcript_raw  = get_field('first_pass_transcription', $post_id);
    $transcript_text = '';
    if ( ! empty($transcript_raw)) {
        $decoded         = is_string($transcript_raw) ? json_decode($transcript_raw, true) : $transcript_raw;
        $transcript_text = is_array($decoded) && isset($decoded['transcript']) ? $decoded['transcript'] : $transcript_raw;
    }

    // --- 3. Resolve Duration (Using Admin Logic for Robustness) ---
    $duration_formatted = '';
    $duration_sec       = get_post_meta($post_id, 'audio_duration', true);

    if ($duration_sec) {
        $duration_formatted = gmdate('i:s', intval($duration_sec));
    } elseif ($mastered_mp3_id > 0) {
        $att_meta = wp_get_attachment_metadata($mastered_mp3_id);
        if (isset($att_meta['length_formatted'])) {
            $duration_formatted = $att_meta['length_formatted'];
        } elseif (isset($att_meta['length'])) {
            $duration_formatted = gmdate('i:s', intval($att_meta['length']));
        }
    }

    // --- 4. Fetch Metadata (New Schema) ---
    $accession_number = get_field('accession_number', $post_id);
    $location_data    = get_field('location', $post_id);

    // --- 5. User-Appropriate Environment Data (New Schema) ---
    $env_json_raw = get_field('environment_data', $post_id);
    $env_data     = is_string($env_json_raw) ? json_decode($env_json_raw, true) : [];

    // Parse Browser/OS from User Agent (user-friendly display)
    $ua_string          = $env_data['device']['userAgent'] ?? '';
    $user_agent_display = 'Unknown Browser';

    if ($ua_string) {
        $browser = 'Unknown';
        if (str_contains((string) $ua_string, 'Chrome')) {
            $browser = 'Chrome';
        } elseif (str_contains((string) $ua_string, 'Firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains((string) $ua_string, 'Safari')) {
            $browser = 'Safari';
        }

        $os = 'Unknown';
        if (str_contains((string) $ua_string, 'Android')) {
            $os = 'Android';
        } elseif (str_contains((string) $ua_string, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains((string) $ua_string, 'Mac')) {
            $os = 'MacOS';
        } elseif (str_contains((string) $ua_string, 'Linux')) {
            $os = 'Linux';
        } elseif (str_contains((string) $ua_string, 'CrOS')) {
            $os = 'ChromeOS';
        }

        $user_agent_display = sprintf('%s on %s', $browser, $os);
    }

    // Parse Mic Profile (useful for users to understand quality)
    $mic_data_raw        = get_field('transcriber', $post_id);
    $mic_data            = json_decode($mic_data_raw, true);
    $mic_profile_display = 'Standard';
    if (isset($mic_data['gain'])) {
        $gain = $mic_data['gain'];
        if ($gain >= 1.5) {
            $mic_profile_display = 'High Sensitivity';
        } elseif ($gain <= 0.5) {
            $mic_profile_display = 'Low Sensitivity';
        } else {
            $mic_profile_display = 'Normal Sensitivity';
        }
    }

    // --- 6. Taxonomies ---
    $languages = get_the_terms($post_id, 'language');
    $rec_types = get_the_terms($post_id, 'recording_type');

    // --- 7. Action URLs ---
    $edit_page_slug     = $settings->get('edit_page_id', '');
    $recorder_page_slug = $settings->get('recorder_page_id', '');
    $edit_page_url      = $edit_page_slug ? get_permalink(get_page_by_path($edit_page_slug)) : '';
    $recorder_page_url  = $recorder_page_slug ? get_permalink(get_page_by_path($recorder_page_slug)) : '';
} catch (\Throwable $throwable) {
    // Fail silently in production, log in debug
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Starmus Detail Error] ' . $throwable->getMessage());
    }
    echo '<div class="starmus-alert starmus-alert--error"><p>' . esc_html__('Unable to load recording details.', 'starmus-audio-recorder') . '</p></div>';
    return;
}
?>

<main class="starmus-user-detail" id="starmus-record-<?php echo esc_attr((string) $post_id); ?>">

	<!-- Header: Title & Badges -->
	<header class="starmus-header-clean">
		<h1 class="starmus-title"><?php echo esc_html(get_the_title($post_id)); ?></h1>

		<div class="starmus-meta-row">
			<span class="starmus-meta-item">
				<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
				<?php echo esc_html(get_the_date('F j, Y', $post_id)); ?>
			</span>

			<?php if ( ! empty($languages) && ! is_wp_error($languages)) { ?>
				<span class="starmus-tag starmus-tag--lang">
        <?php echo esc_html($languages[0]->name); ?>
				</span>
			<?php } ?>

			<?php if ( ! empty($rec_types) && ! is_wp_error($rec_types)) { ?>
				<span class="starmus-tag starmus-tag--type">
        <?php echo esc_html($rec_types[0]->name); ?>
				</span>
			<?php } ?>
		</div>
	</header>

	<div class="starmus-layout-split">

		<!-- Left: Player & Info -->
		<div class="starmus-content-main">

			<!-- Audio Player -->
			<section class="starmus-player-card sparxstar-glass-card">
				<?php if ($playback_url) { ?>
					<audio controls preload="metadata" class="starmus-audio-full">
						<source src="<?php echo esc_url($playback_url); ?>" type="<?php echo str_contains($playback_url, '.mp3') ? 'audio/mpeg' : 'audio/webm'; ?>">
        <?php esc_html_e('Your browser does not support the audio player.', 'starmus-audio-recorder'); ?>
					</audio>
				<?php } else { ?>
					<p class="starmus-empty-msg">
        <?php esc_html_e('Audio is currently processing or unavailable.', 'starmus-audio-recorder'); ?>
					</p>
				<?php } ?>
			</section>

			<!-- Transcription -->
			<?php if ($transcript_text) { ?>
				<section class="starmus-info-card sparxstar-glass-card">
					<h3><?php esc_html_e('Transcription', 'starmus-audio-recorder'); ?></h3>
					<div class="starmus-transcript-box" style="background:#f9f9f9; padding:15px; border:1px solid #eee; border-radius:4px; max-height:200px; overflow-y:auto;">
        <?php echo wp_kses_post(nl2br($transcript_text)); ?>
					</div>
				</section>
			<?php } ?>

			<!-- Recording Details (User-Appropriate Technical Info) -->
			<section class="starmus-info-card sparxstar-glass-card">
				<h3><?php esc_html_e('Recording Details', 'starmus-audio-recorder'); ?></h3>
				<dl class="starmus-dl-list">

					<div class="starmus-dl-item">
						<dt><?php esc_html_e('Browser', 'starmus-audio-recorder'); ?></dt>
						<dd><?php echo esc_html($user_agent_display); ?></dd>
					</div>

					<div class="starmus-dl-item">
						<dt><?php esc_html_e('Microphone', 'starmus-audio-recorder'); ?></dt>
						<dd><?php echo esc_html($mic_profile_display); ?></dd>
					</div>

					<?php if ($duration_formatted) { ?>
						<div class="starmus-dl-item">
							<dt><?php esc_html_e('Duration', 'starmus-audio-recorder'); ?></dt>
							<dd><?php echo esc_html($duration_formatted); ?></dd>
						</div>
					<?php } ?>

					<!-- Add a status field based on file IDs (New: User-friendly status) -->
					<div class="starmus-dl-item">
						<dt><?php esc_html_e('Processing Status', 'starmus-audio-recorder'); ?></dt>
						<dd>
							<?php if ($mastered_mp3_id > 0) { ?>
								<span style="color:var(--starmus-success);font-weight:600;">Complete</span>
							<?php } elseif ($original_id > 0) { ?>
								<span style="color:var(--starmus-warning);font-weight:600;">In Queue</span>
							<?php } else { ?>
								<span style="color:var(--starmus-text-muted);">Awaiting Upload</span>
							<?php } ?>
						</dd>
					</div>

				</dl>
			</section>

			<!-- Public Metadata -->
			<section class="starmus-info-card sparxstar-glass-card">
				<h3><?php esc_html_e('About this Recording', 'starmus-audio-recorder'); ?></h3>
				<dl class="starmus-dl-list">

					<?php if ($location_data) { ?>
						<div class="starmus-dl-item">
							<dt><?php esc_html_e('Location', 'starmus-audio-recorder'); ?></dt>
							<dd><?php echo esc_html($location_data); ?></dd>
						</div>
					<?php } ?>

					<?php if ($accession_number) { ?>
						<div class="starmus-dl-item">
							<dt><?php esc_html_e('Accession ID', 'starmus-audio-recorder'); ?></dt>
							<dd><?php echo esc_html($accession_number); ?></dd>
						</div>
					<?php } ?>
				</dl>
			</section>

		</div>

		<!-- Right: Actions (Permissions Checked in Loader, but re-checked here for safety) -->
		<aside class="starmus-content-sidebar">
			<?php if (current_user_can('edit_post', $post_id)) { ?>
				<section class="starmus-actions-card sparxstar-glass-card">
					<h3><?php esc_html_e('Actions', 'starmus-audio-recorder'); ?></h3>

					<div class="starmus-btn-stack">
						<!-- Re-Record -->
        <?php if ($recorder_page_url) { ?>
							<a href="<?php echo esc_url(add_query_arg('recording_id', $post_id, $recorder_page_url)); ?>" class="starmus-btn starmus-btn--action">
								<span class="dashicons dashicons-microphone" aria-hidden="true"></span>
            <?php esc_html_e('Re-Record Audio', 'starmus-audio-recorder'); ?>
							</a>
						<?php } ?>

						<!-- Edit -->
        <?php if ($edit_page_url) { ?>
							<a href="<?php echo esc_url(add_query_arg('recording_id', $post_id, $edit_page_url)); ?>" class="starmus-btn starmus-btn--outline">
								<span class="dashicons dashicons-edit" aria-hidden="true"></span>
            <?php esc_html_e('Open Editor', 'starmus-audio-recorder'); ?>
							</a>
						<?php } ?>
					</div>
				</section>
			<?php } ?>
		</aside>

	</div>

</main>
