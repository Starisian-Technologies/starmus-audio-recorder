<?php

/**
 * Front-end presentation layer for the Starmus recorder experience.
 *
 * @package   Starmus
 */

namespace Starisian\Sparxstar\Starmus\frontend;

if (! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper;
use Starisian\Sparxstar\Starmus\includes\StarmusSubmissionHandler;

/**
 * Renders the user interface for the audio recorder and recordings list.
 * Pure presentation: shortcodes + template rendering.
 * Assets are handled separately in StarmusAssets.
 */
class StarmusAudioRecorderUI
{
    /**
     * REST namespace exposed to localized front-end scripts.
     */
    public const STARMUS_REST_NAMESPACE = StarmusSubmissionHandler::STARMUS_REST_NAMESPACE;

    /**
     * Prime the UI layer with optional settings for template hydration.
     *
     * @param StarmusSettings|null $settings Configuration object, if available.
     */
    public function __construct(private readonly ?StarmusSettings $settings)
    {
        $this->register_hooks();
    }

    /**
     * Register shortcodes and taxonomy cache hooks.
     */
    private function register_hooks(): void
    {

        StarmusLogger::info('StarmusAudioRecorderUI', 'Recorder component available, registering recorder hooks');
        add_action('starmus_after_audio_upload', [$this, 'save_all_metadata'], 10, 3);
        add_filter('starmus_audio_upload_success_response', [$this, 'add_conditional_redirect'], 10, 3);
        add_action('template_redirect', [$this, 'maybe_handle_rerecorder_autostep']);

        // Cron scheduling moved to activation to avoid performance issues
        // Clear cache when a Language is added, edited, or deleted.
        add_action('delete_language', $this->clear_taxonomy_transients(...));
        // Clear cache when a Recording Type is added, edited, or deleted.
        add_action('delete_recording-type', $this->clear_taxonomy_transients(...));
    }

    /**
     * Render the recorder form shortcode.
     */
    public function render_recorder_shortcode(): string
    {

        try {
            $template_args = [
                'form_id'         => 'starmus_recorder_form',
                'consent_message' => $this->settings instanceof \Starisian\Sparxstar\Starmus\core\StarmusSettings ? $this->settings->get('consent_message', 'I consent to the terms and conditions.') : 'I consent to the terms and conditions.',
                'data_policy_url' => $this->settings instanceof \Starisian\Sparxstar\Starmus\core\StarmusSettings ? $this->settings->get('data_policy_url', '') : '',
                'recording_types' => $this->get_cached_terms('recording-type', 'starmus_recording_types_list'),
                'languages'       => $this->get_cached_terms('language', 'starmus_languages_list'),
            ];

            return StarmusTemplateLoaderHelper::secure_render_template('starmus-audio-recorder-ui.php', $template_args);
        } catch (\Throwable $throwable) {
            StarmusLogger::error('StarmusAudioRecorderUI', $throwable, ['context' => 'render_recorder_shortcode']);
            return '<p>' . esc_html__('The audio recorder is temporarily unavailable.', 'starmus-audio-recorder') . '</p>';
        }
    }

    /**
     * Render the re-recorder (single-button variant).
     * Usage: [starmus_audio_re_recorder post_id="..." target_post_id="..."]
     * If post_id is not provided, will check for 'recording_id' in query string.
     */
    public function render_re_recorder_shortcode(array $atts = []): string
    {
        try {
            $atts = shortcode_atts(
                [
                    'post_id'        => 0,
                    'target_post_id' => 0,
                ],
                $atts,
                'starmus_audio_re_recorder'
            );

            // Get post_id from shortcode attribute or URL parameter
            $post_id = absint($atts['post_id']);
            if ($post_id <= 0 && isset($_GET['recording_id'])) {
                $post_id = absint($_GET['recording_id']);
            }

            // Validate post exists and is an audio-recording
            if ($post_id <= 0) {
                return '<p>' . esc_html__('No recording specified.', 'starmus-audio-recorder') . '</p>';
            }

            $cpt_slug = $this->settings instanceof \Starisian\Sparxstar\Starmus\core\StarmusSettings
                ? $this->settings->get('cpt_slug', 'audio-recording')
                : 'audio-recording';

            if (get_post_type($post_id) !== $cpt_slug) {
                return '<p>' . esc_html__('Invalid recording ID.', 'starmus-audio-recorder') . '</p>';
            }

            $template_args = [
                'form_id'         => 'rerecord',
                'post_id'         => $post_id,
                'target_post_id'  => absint($atts['target_post_id']),
                'consent_message' => $this->settings instanceof \Starisian\Sparxstar\Starmus\core\StarmusSettings
                    ? $this->settings->get('consent_message', 'I consent to the terms and conditions.')
                    : 'I consent to the terms and conditions.',
                'data_policy_url' => $this->settings instanceof \Starisian\Sparxstar\Starmus\core\StarmusSettings
                    ? $this->settings->get('data_policy_url', '')
                    : '',
                'allowed_file_types' => $this->settings instanceof \Starisian\Sparxstar\Starmus\core\StarmusSettings
                    ? $this->settings->get('allowed_file_types', 'webm')
                    : 'webm',
            ];

            return \Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper::secure_render_template(
                'starmus-audio-re-recorder-ui.php',
                $template_args
            );
        } catch (\Throwable $throwable) {
            \Starisian\Sparxstar\Starmus\helpers\StarmusLogger::log('UI:render_re_recorder_shortcode', $throwable);
            return '<p>' . esc_html__('The re-recorder is temporarily unavailable.', 'starmus-audio-recorder') . '</p>';
        }
    }

    /**
     * Get cached terms with transient support.
     */
    private function get_cached_terms(string $taxonomy, string $cache_key): array
    {
        $terms = get_transient($cache_key);
        if (false === $terms) {
            $terms = get_terms(
                [
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                ]
            );
            if (! is_wp_error($terms)) {
                set_transient($cache_key, $terms, 12 * HOUR_IN_SECONDS);
            } else {
                StarmusLogger::log('UI:get_cached_terms', new \Exception($terms->get_error_message()));
                $terms = [];
            }
        }

        return \is_array($terms) ? $terms : [];
    }

    /**
     * Clear cached terms.
     */
    public function clear_taxonomy_transients(): void
    {
        delete_transient('starmus_languages_list');
        delete_transient('starmus_recording_types_list');
    }

    public function maybe_handle_rerecorder_autostep(): void
    {
        if (
            !isset($_POST['starmus_rerecord']) ||
            !isset($_POST['post_id'])
        ) {
            return;
        }

        $post_id = absint($_POST['post_id']);

        // Inject POST into $_REQUEST so recorder sees metadata
        $_REQUEST['starmus_existing_recording_id'] = $post_id;

        wp_safe_redirect(
            home_url('/' . get_option('starmus_recorder_page_slug', 'record') . '/?post_id=' . $post_id)
        );
        exit;
    }
}
