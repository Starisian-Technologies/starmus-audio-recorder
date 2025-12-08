<?php

/**
 * Starmus Audio Editor UI - Refactored for Security & Performance
 *
 * @package Starisian\Sparxstar\Starmus\frontend
 *
 * @version 0.9.2
 *
 * @since 0.3.1
 */

namespace Starisian\Sparxstar\Starmus\frontend;

use Exception;

use function file_exists;
use function is_numeric;
use function json_decode;

use const JSON_ERROR_NONE;

use function json_last_error;
use function json_last_error_msg;
use function realpath;

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper;

use function str_replace;

use Throwable;

use function usort;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (! \defined('ABSPATH')) {
    exit;
}

class StarmusAudioEditorUI
{
    /**
     * REST namespace for editor endpoints (must match other handlers)
     *
     * @var string
     */
    public const STARMUS_REST_NAMESPACE = 'star_uec/v1';

    /**
     * REST namespace consumed by the editor for annotation endpoints.
     * Use StarmusSubmissionHandler::STARMUS_REST_NAMESPACE directly where needed.
     */

    /**
     * Upper bound for stored annotations to avoid overloading requests.
     *
     * @var int
     */
    public const STARMUS_MAX_ANNOTATIONS = 1000;

    /**
     * Time-based throttle applied when saving annotations.
     *
     * @var int
     */
    public const STARMUS_RATE_LIMIT_SECONDS = 2;

    /**
     * Cached rendering context shared between hooks during a request.
     *
     * @var array<string, mixed>|null
     */
    private ?array $cached_context = null;

    /**
     * Bootstrap the editor by registering its WordPress hooks.
     */
    public function __construct()
    {
        $this->register_hooks();
    }

    /**
     * Register shortcode, assets, and REST route hooks.
     */
    public function register_hooks(): void
    {
        // Register REST endpoint for annotation saving.
        add_action('rest_api_init', $this->register_rest_endpoint(...));

        // Add body class for Starmus-controlled pages
        add_filter('body_class', function ($classes) {
            global $post;
            if ($post && has_shortcode($post->post_content, 'starmus_audio_editor')) {
                $classes[] = 'starmus-page';
                $classes[] = 'starmus-editor-page';
            }

            return $classes;
        });
    }

    /**
     * Render the audio editor shortcode.
     *
     * @param array $atts Shortcode attributes.
     *
     * @return string Rendered HTML output.
     */
    public function render_audio_editor_shortcode(array $atts = []): string
    {
        try {
            if (! is_user_logged_in()) {
                return '<p>' . esc_html__('You must be logged in to edit audio.', 'starmus-audio-recorder') . '</p>';
            }

            do_action('starmus_before_editor_render');

            // Pass the atts to get_editor_context
            $context = $this->get_editor_context($atts);

            if (is_wp_error($context)) {
                return '<div class="notice notice-error"><p>' . esc_html($context->get_error_message()) . '</p></div>';
            }

            // Now template receives post_id
            return StarmusTemplateLoaderHelper::secure_render_template(
                'starmus-audio-editor-ui.php',
                ['context' => $context]
            );
        } catch (\Throwable $throwable) {
            $this->log_error($throwable);
            return '<div class="notice notice-error"><p>' .
                esc_html__('Audio editor unavailable.', 'starmus-audio-recorder') .
                '</p></div>';
        }
    }

    /**
     * Conditionally enqueue front-end assets required by the editor.
     */
    public function enqueue_scripts(): void
    {
        try {
            if (! is_singular()) {
                return;
            }

            global $post;
            if (! $post || ! has_shortcode($post->post_content, 'starmus_audio_editor')) {
                return;
            }

            $context = $this->get_editor_context();
            if (is_wp_error($context)) {
                // Don't enqueue scripts if there's an error loading the context.
                return;
            }

            wp_enqueue_style('starmus-unified-styles', STARMUS_URL . 'assets/css/starmus-audio-recorder-styles.min.css', [], STARMUS_VERSION);
            // Peaks.js is bundled into starmus-audio-recorder-script.bundle.min.js
            // No separate enqueue needed

            // Enqueue transcript controller first (dependency for editor)
            wp_enqueue_script(
                'starmus-transcript-controller',
                STARMUS_URL . 'src/js/starmus-transcript-controller.js',
                [],
                STARMUS_VERSION,
                true
            );

            wp_enqueue_script(
                'starmus-audio-editor',
                STARMUS_URL . 'src/js/starmus-audio-editor.js',
                ['jquery', 'peaks-js', 'starmus-transcript-controller'],
                STARMUS_VERSION,
                true
            );
            $annotations_data = $this->parse_annotations_json($context['annotations_json']);

            // Get transcript data
            $transcript_json = get_post_meta($context['post_id'], 'star_transcript_json', true);
            $transcript_data = [];
            if ($transcript_json && \is_string($transcript_json)) {
                $decoded = json_decode($transcript_json, true);
                if (\is_array($decoded)) {
                    $transcript_data = $decoded;
                }
            }

            wp_localize_script(
                'starmus-audio-editor',
                'STARMUS_EDITOR_DATA',
                [
                    'restUrl'         => esc_url_raw(rest_url(self::STARMUS_REST_NAMESPACE . '/annotations')),
                    'nonce'           => wp_create_nonce('wp_rest'),
                    'postId'          => absint($context['post_id']),
                    'audioUrl'        => esc_url($context['audio_url']),
                    'waveformDataUrl' => esc_url($context['waveform_url']),
                    'annotations'     => $annotations_data,
                    'transcript'      => $transcript_data,
                    'mode'            => 'editor',
                    'canCommit'       => current_user_can('publish_posts'),
                ]
            );
        } catch (Throwable $throwable) {
            $this->log_error($throwable);
        }
    }

    /**
     * Convert stored annotations JSON to a sanitized array structure.
     *
     * @param string $json Raw JSON string.
     *
     * @return array Parsed annotation data.
     */
    private function parse_annotations_json(string $json): array
    {
        try {
            if ($json === '' || $json === '0') {
                return [];
            }

            $data = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(json_last_error_msg());
            }

            return \is_array($data) ? $data : [];
        } catch (Throwable $throwable) {
            $this->log_error($throwable);
            return [];
        }
    }

    /**
     * Build the editor rendering context for the current request.
     *
     * @param array $atts Shortcode attributes passed from render method.
     *
     * @return array|WP_Error Context array or WP_Error on failure.
     */
    private function get_editor_context(array $atts = []): array|WP_Error
    {
        try {
            if ($this->cached_context !== null) {
                return $this->cached_context;
            }

            // 1. Determine the Post ID (URL takes priority over Shortcode)
            $url_id       = absint($_GET['post_id'] ?? 0);
            $shortcode_id = isset($atts['post_id']) ? absint($atts['post_id']) : 0;
            $post_id      = $url_id ?: $shortcode_id;

            // 2. Security: Verify Nonce ONLY if accessing via URL parameter
            if ($url_id > 0) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotUnslashed
                $get_nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotUnslashed
                $get_wpnonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
                $raw_nonce   = $get_nonce ?: $get_wpnonce;
                $nonce       = \is_string($raw_nonce) ? $raw_nonce : '';

                if (! $nonce || ! wp_verify_nonce($nonce, 'starmus_edit_audio_' . $post_id)) {
                    return new WP_Error('invalid_nonce', __('Security check failed.', 'starmus-audio-recorder'));
                }
            }

            // 3. Fallback: If no ID found anywhere, return empty context (Demo Mode)
            if (! $post_id) {
                $this->cached_context = [
                    'post_id'          => 0,
                    'attachment_id'    => 0,
                    'audio_url'        => '',
                    'waveform_url'     => '',
                    'annotations_json' => '[]',
                ];
                return $this->cached_context;
            }

            // 4. Validate Post Existence and Permissions
            $post = get_post($post_id);
            if (! $post) {
                return new WP_Error('invalid_id', __('Invalid submission ID.', 'starmus-audio-recorder'));
            }

            // Allow if user is post author OR has the starmus_edit_audio capability
            $current_user_id = get_current_user_id();
            $is_author       = ($post->post_author == $current_user_id);
            $has_cap         = current_user_can('starmus_edit_audio');

            // Debug logging removed for production compliance
            // Permission check: user_id, post_author, is_author, has_cap

            if (! $is_author && ! $has_cap) {
                return new WP_Error('permission_denied', __('Permission denied.', 'starmus-audio-recorder'));
            }

            // 5. Retrieve Audio Data
            $attachment_id = absint(get_post_meta($post_id, '_audio_attachment_id', true));
            if (! $attachment_id || get_post_type($attachment_id) !== 'attachment') {
                return new WP_Error('no_audio', __('No audio file attached.', 'starmus-audio-recorder'));
            }

            $audio_url = wp_get_attachment_url($attachment_id);
            if (! $audio_url) {
                return new WP_Error('no_audio_url', __('Audio file URL not available.', 'starmus-audio-recorder'));
            }

            // 6. Build Final Context
            $waveform_url     = $this->get_secure_waveform_url($attachment_id);
            $annotations_json = get_post_meta($post_id, 'starmus_annotations_json', true);
            $annotations_json = \is_string($annotations_json) ? $annotations_json : '[]';

            $this->cached_context = [
                'post_id'          => $post_id,
                'attachment_id'    => $attachment_id,
                'audio_url'        => $audio_url,
                'waveform_url'     => $waveform_url,
                'annotations_json' => $annotations_json,
            ];

            return $this->cached_context;
        } catch (Throwable $throwable) {
            $this->log_error($throwable);
            return new WP_Error('context_error', __('Unable to load editor context.', 'starmus-audio-recorder'));
        }
    }

    /**
     * Generate a signed URL for the waveform attachment if available.
     *
     * @param int $attachment_id Attachment ID storing the waveform data.
     *
     * @return string Public URL for the waveform or empty string when missing.
     */
    private function get_secure_waveform_url(int $attachment_id): string
    {
        $wave_json_path = get_post_meta($attachment_id, '_waveform_json_path', true);
        if (! \is_string($wave_json_path) || ($wave_json_path === '' || $wave_json_path === '0')) {
            return '';
        }

        $uploads          = wp_get_upload_dir();
        $real_wave_path   = realpath($wave_json_path);
        $real_uploads_dir = realpath($uploads['basedir']);
        if (! $real_wave_path || ! $real_uploads_dir || ! str_starts_with($real_wave_path, $real_uploads_dir)) {
            return '';
        }

        if (! file_exists($wave_json_path)) {
            return '';
        }

        return str_replace($uploads['basedir'], $uploads['baseurl'], $wave_json_path);
    }

    /**
     * Register REST endpoints used by the editor for annotation persistence.
     */
    public function register_rest_endpoint(): void
    {
        register_rest_route(
            self::STARMUS_REST_NAMESPACE,
            '/annotations',
            [
                'methods'             => 'POST',
                'callback'            => $this->handle_save_annotations(...),
                'permission_callback' => $this->can_save_annotations(...),
                'args'                => [
                    'postId' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => $this->validate_post_id(...),
                    ],
                    'annotations' => [
                        'required'          => true,
                        'sanitize_callback' => $this->sanitize_annotations(...),
                        'validate_callback' => $this->validate_annotations(...),
                    ],
                ],
            ]
        );
    }

    /**
     * Validate incoming post ID arguments for REST requests.
     *
     * @param mixed $value Raw value supplied to the REST endpoint.
     *
     * @return bool True when the value is a valid post identifier.
     */
    public function validate_post_id($value): bool
    {
        return is_numeric($value) && $value > 0 && get_post(absint($value)) !== null;
    }

    /**
     * Sanitize incoming annotations payloads from REST requests.
     *
     * @param mixed $value Raw annotations payload.
     *
     * @return array Normalized annotations array.
     */
    public function sanitize_annotations($value): array
    {
        try {
            if (! \is_array($value)) {
                return [];
            }

            $sanitized = [];
            foreach ($value as $annotation) {
                if (! \is_array($annotation)) {
                    continue;
                }

                $sanitized[] = [
                    'id'        => sanitize_key($annotation['id'] ?? ''),
                    'startTime' => \floatval($annotation['startTime'] ?? 0),
                    'endTime'   => \floatval($annotation['endTime'] ?? 0),
                    'labelText' => wp_kses_post($annotation['labelText'] ?? ''),
                    'color'     => sanitize_hex_color($annotation['color'] ?? '#000000'),
                ];
            }

            return $sanitized;
        } catch (Throwable $throwable) {
            $this->log_error($throwable);
            return [];
        }
    }

    /**
     * Validate that annotations array obeys structural constraints.
     *
     * @param mixed $value Annotations payload to validate.
     *
     * @return bool True when annotations are acceptable.
     */
    public function validate_annotations($value): bool
    {
        if (! \is_array($value) || \count($value) > self::STARMUS_MAX_ANNOTATIONS) {
            return false;
        }

        foreach ($value as $annotation) {
            if (! \is_array($annotation)) {
                return false;
            }

            if (! isset($annotation['startTime'], $annotation['endTime'])) {
                return false;
            }

            $start = \floatval($annotation['startTime']);
            $end   = \floatval($annotation['endTime']);
            if ($start < 0 || $end < 0 || $start >= $end) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine whether the current request is authorized to save annotations.
     *
     * @param WP_REST_Request $request REST request context.
     *
     * @return bool True when the user can persist annotations.
     */
    public function can_save_annotations(WP_REST_Request $request): bool
    {
        try {
            $nonce = $request->get_header('X-WP-Nonce');
            if (! $nonce || ! wp_verify_nonce($nonce, 'wp_rest')) {
                return false;
            }

            $post_id = absint($request->get_param('postId'));
            if (! $post_id || ! get_post($post_id)) {
                return false;
            }

            return current_user_can('edit_post', $post_id);
        } catch (Throwable $throwable) {
            $this->log_error($throwable);
            return false;
        }
    }

    /**
     * Persist sanitized annotations for a recording.
     *
     * @param WP_REST_Request $request REST request containing annotations.
     *
     * @return WP_REST_Response Success response with saved annotations.
     */
    public function handle_save_annotations(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $post_id     = absint($request->get_param('postId'));
            $annotations = $request->get_param('annotations');
            if ($this->is_rate_limited($post_id)) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'message' => __('Too many requests. Please wait.', 'starmus-audio-recorder'),
                    ],
                    429
                );
            }

            $validation_result = $this->validate_annotation_consistency($annotations);
            if (is_wp_error($validation_result)) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'message' => $validation_result->get_error_message(),
                    ],
                    400
                );
            }

            $json_data = wp_json_encode($annotations);
            if ($json_data === false) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'message' => __('Failed to encode annotations.', 'starmus-audio-recorder'),
                    ],
                    500
                );
            }

            do_action('starmus_before_annotations_save', $post_id, $annotations);
            update_post_meta($post_id, 'starmus_annotations_json', $json_data);
            do_action('starmus_after_annotations_save', $post_id, $annotations);
            return new WP_REST_Response(
                [
                    'success' => true,
                    'message' => __('Annotations saved successfully.', 'starmus-audio-recorder'),
                    'count'   => \count($annotations),
                ],
                200
            );
        } catch (Throwable $throwable) {
            $this->log_error($throwable);
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => __('Internal server error.', 'starmus-audio-recorder'),
                ],
                500
            );
        }
    }

    /**
     * Check if the request should be throttled to avoid rapid writes.
     *
     * @param int $post_id Recording post ID.
     *
     * @return bool True when rate limited.
     */
    private function is_rate_limited(int $post_id): bool
    {
        $user_id = get_current_user_id();
        $key     = \sprintf('starmus_ann_rl_%s_%d', $user_id, $post_id);
        if (get_transient($key)) {
            return true;
        }

        set_transient($key, true, self::STARMUS_RATE_LIMIT_SECONDS);
        return false;
    }

    /**
     * Ensure annotation timestamps are sorted and non-overlapping.
     *
     * @param array $annotations Annotation entries to validate.
     *
     * @return bool|\WP_Error True if valid, WP_Error on failure.
     */
    private function validate_annotation_consistency(array $annotations): true|\WP_Error
    {
        if ($annotations === []) {
            return true;
        }

        usort(
            $annotations,
            fn(array $a, array $b): int => $a['startTime'] <=> $b['startTime']
        );
        for ($i = 0; $i < \count($annotations) - 1; $i++) {
            $current = $annotations[$i];
            $next    = $annotations[$i + 1];
            if ($current['endTime'] > $next['startTime']) {
                return new WP_Error('overlap_detected', __('Overlapping annotations detected.', 'starmus-audio-recorder'));
            }
        }

        return true;
    }

    /**
     * Public wrapper for get_editor_context to allow external access.
     *
     * @param array $atts Shortcode attributes.
     *
     * @return array|WP_Error Context array or WP_Error on failure.
     */
    public function get_editor_context_public(array $atts = []): array|WP_Error
    {
        return $this->get_editor_context($atts);
    }

    /**
     * Log an error with unified context handling.
     *
     * @param Throwable $e Captured exception instance.
     */
    private function log_error(Throwable $e): void
    {
        if (\defined('WP_DEBUG') && WP_DEBUG && \defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log($e->getMessage());
        }
    }
}
