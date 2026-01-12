<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\frontend;

use Starisian\Sparxstar\Starmus\core\StarmusAssetLoader;

if ( ! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\data\StarmusAudioDAL;
use Starisian\Sparxstar\Starmus\data\StarmusProsodyDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper;
use Throwable;

use Starisian\Sparxstar\Starmus\core\StarmusConsentHandler;

/**
 * Registers shortcodes and routes rendering lazily to the correct UI classes.
 *
 * @since 0.7.7
 */
final class StarmusShortcodeLoader
{
    /**
     * Settings service instance.
     */
    private ?StarmusSettings $settings = null;

    /**
     * Data Access Layer instance.
     */
    private ?StarmusAudioDAL $dal = null;

    /**
     * Consent Handler instance.
     */
    private ?StarmusConsentHandler $consent_handler = null;

    /**
     * Consent UI instance.
     */
    private ?StarmusConsentUI $consent_ui = null;

    /**
     * Prosody player instance.
     */
    private ?StarmusProsodyPlayer $prosody = null;

    /**
     * @param StarmusAudioDAL|null $dal The data access layer.
     * @param StarmusSettings|null $settings The settings instance.
     * @param StarmusProsodyDAL|null $prosody_dal The prosody DAL instance.
     */
    public function __construct(?StarmusAudioDAL $dal = null, ?StarmusSettings $settings = null, ?StarmusProsodyDAL $prosody_dal = null)
    {
        try {
            $this->settings = $settings ?? new StarmusSettings();
            $this->dal      = $dal ?? new StarmusAudioDAL();
            $this->consent_handler = new StarmusConsentHandler();
            $this->consent_ui = new StarmusConsentUI($this->consent_handler, $this->settings);
            $this->consent_ui->register_hooks();

            // Ensure prosody engine is set up
            $this->set_prosody_engine($prosody_dal);
            $this->register_hooks();
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }

    private function register_hooks(): void
    {
        // Currently no additional hooks to register
        add_action('init', $this->register_shortcodes(...));
    }

    /**
     * Register shortcodes — but don't instantiate heavy UI classes yet.
     */
    public function register_shortcodes(): void
    {
        try {
            add_shortcode('starmus_audio_recorder', fn(): string => $this->safe_render(fn(): string => (new StarmusAudioRecorderUI($this->settings))->render_recorder_shortcode()));
            add_shortcode('starmus_audio_editor', fn(array $atts = []): string => $this->safe_render(fn(): string => $this->render_editor_with_bootstrap($atts)));
            add_shortcode('starmus_my_recordings', $this->render_my_recordings_shortcode(...));
            add_shortcode('starmus_recording_detail', $this->render_submission_detail_shortcode(...));
            add_shortcode('starmus_audio_re_recorder', fn(array $atts = []): string => $this->safe_render(fn(): string => (new StarmusAudioRecorderUI($this->settings))->render_re_recorder_shortcode($atts)));
            add_shortcode('starmus_contributor_consent', fn(): string => $this->safe_render(fn(): string => $this->consent_ui->render_shortcode()));

            add_filter('the_content', $this->render_submission_detail_via_filter(...), 100);
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }

    private function set_prosody_engine(?StarmusProsodyDAL $prosody_dal = null): void
    {
        if ($this->prosody instanceof StarmusProsodyPlayer) {
            return;
        }

        try {
            $this->prosody = new StarmusProsodyPlayer($prosody_dal);
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }

    /**
     * Safely render UI blocks with logging.
     */
    private function safe_render(callable $renderer): string
    {
        try {
            return $renderer();
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return '<p>' . esc_html__('Component unavailable.', 'starmus-audio-recorder') . '</p>';
        }
    }

    /**
     * Render the "My Recordings" shortcode.
     * Modified to handle Detail View inline since CPT is not publicly queryable.
     */
    public function render_my_recordings_shortcode(array $atts = []): string
    {
        if ( ! is_user_logged_in()) {
            return '<p>' . esc_html__('You must be logged in to view your recordings.', 'starmus-audio-recorder') . '</p>';
        }

        try {
            // === Detail View Handler ===
            // Since audio-recording is hidden from frontend queries, we handle display here.
            // Use $_GET directly as filter_input has reliability issues in some WP environments
            $view         = isset($_GET['view']) ? sanitize_key($_GET['view']) : '';
            $recording_id = isset($_GET['recording_id']) ? absint($_GET['recording_id']) : 0;

            if ($view === 'detail' && $recording_id > 0) {
                // Security: Ensure user owns this recording or can edit others
                $post = get_post($recording_id);
                if ($post && (get_current_user_id() === (int) $post->post_author || current_user_can('edit_others_posts'))) {
                    // Masquerade global post for template parts that use get_the_ID()
                    global $post;
                    $post = get_post($recording_id);
                    setup_postdata($post);

                    $template = current_user_can('edit_others_posts')
                        ? 'starmus-recording-detail-admin.php'
                        : 'starmus-recording-detail-user.php';

                    // Contextual Links
                    $recorder_page_id = $this->settings->get('recorder_page_id');
                    $recorder_url     = $recorder_page_id ? get_permalink((int) $recorder_page_id) : '';

                    // Pass variables explicitly to robust templates
                    $output = StarmusTemplateLoaderHelper::render_template($template, [
                        'post_id'           => $recording_id,
                        'recorder_page_url' => $recorder_url,
                        // Add edit_page_url if needed, currently not strictly required by prompt unless "re-recorder" implies it
                    ]);
                    wp_reset_postdata();
                    return $output;
                }
            }

            // === List View Handler ===
            $attributes     = shortcode_atts(['posts_per_page' => 10], $atts);
            $posts_per_page = max(1, absint($attributes['posts_per_page']));
            $paged          = get_query_var('paged') ? (int) get_query_var('paged') : 1;
            $cpt_slug       = $this->settings->get('cpt_slug', 'audio-recording');
            $query          = $this->dal->get_user_recordings(get_current_user_id(), $cpt_slug, $posts_per_page, $paged);

            // Resolve Base URL for links
            $page_ids = $this->settings->get('my_recordings_page_id');
            $page_id  = \is_array($page_ids) ? (int) reset($page_ids) : (int) $page_ids;
            $base_url = $page_id > 0 ? get_permalink($page_id) : get_permalink();

            return StarmusTemplateLoaderHelper::render_template(
                'parts/starmus-my-recordings-list.php',
                [
                    'query'         => $query,
                    'edit_page_url' => $this->dal->get_edit_page_url_admin($cpt_slug),
                    'base_url'      => $base_url,
                ]
            );
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return '<p>' . esc_html__('Unable to load recordings.', 'starmus-audio-recorder') . '</p>';
        }
    }

    /**
     * Render the single recording detail shortcode.
     */
    public function render_submission_detail_shortcode(): string
    {
        try {
            if ( ! is_singular('audio-recording')) {
                return '<p><em>[starmus_recording_detail] can only be used on a single audio recording page.</em></p>';
            }

            $post_id          = get_the_ID();
            $template_to_load = '';
            if (current_user_can('edit_others_posts', $post_id)) {
                $template_to_load = 'starmus-recording-detail-admin.php';
            } elseif (is_user_logged_in() && get_current_user_id() === (int) get_post_field('post_author', $post_id)) {
                $template_to_load = 'starmus-recording-detail-user.php';
            }

            if ($template_to_load !== '' && $template_to_load !== '0') {
                return StarmusTemplateLoaderHelper::render_template($template_to_load);
            }
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }

        return is_user_logged_in()
            ? '<p>You do not have permission to view this recording detail.</p>'
            : '<p><em>You must be logged in to view this recording detail.</em></p>';
    }

    /**
     * Automatically inject recording detail template into single view.
     */
    public function render_submission_detail_via_filter(string $content): string
    {
        try {
            if ( ! is_singular('audio-recording') || ! in_the_loop() || ! is_main_query()) {
                return $content;
            }

            $post_id          = get_the_ID();
            $template_to_load = '';

            if (current_user_can('edit_others_posts', $post_id)) {
                $template_to_load = 'parts/starmus-recording-detail-admin.php';
            } elseif (is_user_logged_in() && get_current_user_id() === (int) get_post_field('post_author', $post_id)) {
                $template_to_load = 'parts/starmus-recording-detail-user.php';
            }

            if ($template_to_load !== '' && $template_to_load !== '0') {
                return StarmusTemplateLoaderHelper::render_template($template_to_load);
            }
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }

        return '<p>You do not have permission to view this recording detail.</p>';
    }

    private function render_editor_with_bootstrap(array $atts): string
    {

        try {

            // Create editor instance and get context
            $editor  = new StarmusAudioEditorUI();
            $context = $editor->get_editor_context_public($atts);

            if (is_wp_error($context)) {
                $error_message = $context->get_error_message();
                return '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
            }

            // Get transcript data
            // FIX: Updated key to match StarmusSubmissionHandler (starmus_transcription_json)
            // Fallback to star_transcript_json for legacy
            $transcript_json = get_post_meta($context['post_id'], 'starmus_transcription_json', true);
            if (empty($transcript_json)) {
                $transcript_json = get_post_meta($context['post_id'], 'star_transcript_json', true);
            }

            $transcript_data = [];
            if ($transcript_json && \is_string($transcript_json)) {
                $decoded = json_decode($transcript_json, true);
                if (\is_array($decoded)) {
                    $transcript_data = $decoded;
                }
            }

            // Parse annotations
            $annotations_data = [];
            if ( ! empty($context['annotations_json']) && \is_string($context['annotations_json'])) {
                $decoded = json_decode($context['annotations_json'], true);
                if (\is_array($decoded)) {
                    $annotations_data = $decoded;
                }
            }

            // Set editor data for asset loader to localize
            StarmusAssetLoader::set_editor_data(
                [
                    'postId'          => $context['post_id'],
                    'restUrl'         => esc_url_raw(rest_url('star_uec/v1/annotations')),
                    'audioUrl'        => esc_url($context['audio_url']),
                    'waveformDataUrl' => esc_url($context['waveform_url']),
                    'annotations'     => $annotations_data,
                    'transcript'      => $transcript_data,
                    'nonce'           => wp_create_nonce('wp_rest'),
                    'mode'            => 'editor',
                    'canCommit'       => current_user_can('publish_posts'),
                ]
            );
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return '<p>' . esc_html__('Component unavailable.', 'starmus-audio-recorder') . '</p>';
        }

        // Render the UI with context
        return $editor->render_audio_editor_shortcode($atts);
    }

    public function get_prosody_engine(): ?StarmusProsodyPlayer
    {
        return $this->prosody;
    }
}
