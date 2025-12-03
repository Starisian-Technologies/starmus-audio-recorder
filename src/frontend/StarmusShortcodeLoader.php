<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\frontend;

if (! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface;
use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper;
use Throwable;

/**
 * Registers shortcodes and routes rendering lazily to the correct UI classes.
 *
 * @since 0.7.7
 */
final class StarmusShortcodeLoader
{
    private StarmusSettings $settings;

    private StarmusAudioRecorderDAL $dal;

    /**
     * @param StarmusAudioRecorderDALInterface|null $dal The data access layer.
     * @param StarmusSettings|null $settings The settings instance.
     */
    public function __construct(?StarmusAudioRecorderDALInterface $dal = null, ?StarmusSettings $settings = null)
    {
        try {
            if (! $dal instanceof StarmusAudioRecorderDALInterface) {
                throw new \RuntimeException('Invalid DAL: must implement StarmusAudioRecorderDALInterface');
            }

            $this->settings = $settings ?? new StarmusSettings();
            $this->dal      = $dal      ?? new StarmusAudioRecorderDAL();
            add_action('init', $this->register_shortcodes(...));
        } catch (Throwable $throwable) {
            StarmusLogger::error('StarmusShortcodeLoader', $throwable, ['context' => '__construct']);
        }
    }

    /**
     * Register shortcodes — but don't instantiate heavy UI classes yet.
     */
    public function register_shortcodes(): void
    {
        try {
            add_shortcode('starmus_audio_recorder', fn (): string => $this->safe_render(fn (): string => (new StarmusAudioRecorderUI($this->settings))->render_recorder_shortcode(), 'starmus_audio_recorder'));
            add_shortcode('starmus_audio_editor', fn (array $atts = []): string => $this->safe_render(fn (): string => $this->render_editor_with_bootstrap($atts), 'starmus_audio_editor'));
            add_shortcode('starmus_my_recordings', $this->render_my_recordings_shortcode(...));
            add_shortcode('starmus_recording_detail', $this->render_submission_detail_shortcode(...));
            add_shortcode('starmus_audio_re_recorder', fn (array $atts = []): string => $this->safe_render(fn (): string => (new StarmusAudioRecorderUI($this->settings))->render_re_recorder_shortcode($atts), 'starmus_audio_re_recorder'));

            add_filter('the_content', $this->render_submission_detail_via_filter(...), 100);
        } catch (\Throwable $throwable) {
            StarmusLogger::error('StarmusShortcodeLoader', $throwable, ['context' => 'register_shortcodes']);
        }
    }

    /**
     * Safely render UI blocks with logging.
     */
    private function safe_render(callable $renderer, string $context): string
    {
        try {
            return $renderer();
        } catch (\Throwable $throwable) {
            StarmusLogger::log('Shortcode:' . $context, $throwable);
            return '<p>' . esc_html__('Component unavailable.', 'starmus-audio-recorder') . '</p>';
        }
    }

    /**
     * Render the "My Recordings" shortcode.
     */
    public function render_my_recordings_shortcode(array $atts = []): string
    {
        if (! is_user_logged_in()) {
            return '<p>' . esc_html__('You must be logged in to view your recordings.', 'starmus-audio-recorder') . '</p>';
        }

        try {
            $attributes     = shortcode_atts(['posts_per_page' => 10], $atts);
            $posts_per_page = max(1, absint($attributes['posts_per_page']));
            $paged          = get_query_var('paged') ? (int) get_query_var('paged') : 1;
            $cpt_slug       = $this->settings->get('cpt_slug', 'audio-recording');
            $query          = $this->dal->get_user_recordings(get_current_user_id(), $cpt_slug, $posts_per_page, $paged);

            return StarmusTemplateLoaderHelper::render_template(
                'parts/starmus-my-recordings-list.php',
                [
                    'query'         => $query,
                    'edit_page_url' => $this->dal->get_edit_page_url_admin($cpt_slug),
                ]
            );
        } catch (\Throwable $throwable) {
            StarmusLogger::log('UI:render_my_recordings', $throwable);
            return '<p>' . esc_html__('Unable to load recordings.', 'starmus-audio-recorder') . '</p>';
        }
    }

    /**
     * Render the single recording detail shortcode.
     */
    public function render_submission_detail_shortcode(): string
    {
        if (! is_singular('audio-recording')) {
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

        return is_user_logged_in()
            ? '<p>You do not have permission to view this recording detail.</p>'
            : '<p><em>You must be logged in to view this recording detail.</em></p>';
    }

    /**
     * Automatically inject recording detail template into single view.
     */
    public function render_submission_detail_via_filter(string $content): string
    {
        if (! is_singular('audio-recording') || ! in_the_loop() || ! is_main_query()) {
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

        return '<p>You do not have permission to view this recording detail.</p>';
    }

    private function render_editor_with_bootstrap(array $atts): string
    {
        // Create editor instance and get context
        $editor  = new StarmusAudioEditorUI();
        $context = $editor->get_editor_context_public($atts);

        if (is_wp_error($context)) {
            $error_message = $context->get_error_message();
            if (\defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[StarmusShortcodeLoader] Editor context error: ' . $error_message);
            }

            return '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
        }

        if (\defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[StarmusShortcodeLoader] Editor context loaded: post_id=' . $context['post_id']);
        }

        // Get transcript data
        $transcript_json = get_post_meta($context['post_id'], 'star_transcript_json', true);
        $transcript_data = [];
        if ($transcript_json && \is_string($transcript_json)) {
            $decoded = json_decode($transcript_json, true);
            if (\is_array($decoded)) {
                $transcript_data = $decoded;
            }
        }

        // Parse annotations
        $annotations_data = [];
        if (!empty($context['annotations_json']) && \is_string($context['annotations_json'])) {
            $decoded = json_decode($context['annotations_json'], true);
            if (\is_array($decoded)) {
                $annotations_data = $decoded;
            }
        }

        // Provide complete editor bootstrap config to JS
        wp_localize_script(
            'starmus-audio-recorder-script.bundle',
            'STARMUS_EDITOR_DATA',
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

        if (\defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[StarmusShortcodeLoader] JS data localized. Audio URL: ' . $context['audio_url']);
        }

        // Render the UI with context
        return $editor->render_audio_editor_shortcode($atts);
    }
}
