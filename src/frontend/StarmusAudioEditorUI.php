<?php

/**
 * Starmus Audio Editor UI - Refactored for Security & Performance
 *
 * @package Starisian\Sparxstar\Starmus\frontend
 * @version 0.8.5
 * @since 0.3.1
 */

namespace Starisian\Sparxstar\Starmus\frontend;

use Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Throwable;
use Exception;

if (! defined('ABSPATH')) {
	exit;
}

class StarmusAudioEditorUI
{

	/**
	 * REST namespace for editor endpoints (must match other handlers)
	 */
	public const STARMUS_REST_NAMESPACE = 'star_uec/v1';

	/**
	 * REST namespace consumed by the editor for annotation endpoints.
	 * Use StarmusSubmissionHandler::STARMUS_REST_NAMESPACE directly where needed.
	 */

	/**
	 * Upper bound for stored annotations to avoid overloading requests.
	 */
	public const STARMUS_MAX_ANNOTATIONS = 1000;

	/**
	 * Time-based throttle applied when saving annotations.
	 */
	public const STARMUS_RATE_LIMIT_SECONDS = 2;

	/**
	 * Cached rendering context shared between hooks during a request.
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
	 *
	 * @return void
	 */
	public function register_hooks(): void
	{
		// Register REST endpoint for annotation saving.
		add_action('rest_api_init', array($this, 'register_rest_endpoint'));
	}

	/**
	 * Render the audio editor shortcode output for the current user.
	 *
	 * @return string HTML payload for the editor interface.
	 */
	public function render_audio_editor_shortcode(): string
	{
		try {
			if (! is_user_logged_in()) {
				return '<p>' . esc_html__('You must be logged in to edit audio.', 'starmus-audio-recorder') . '</p>';
			}
			do_action('starmus_before_editor_render');
			$context = $this->get_editor_context();
			if (is_wp_error($context)) {
				return '<div class="notice notice-error"><p>' . esc_html($context->get_error_message()) . '</p></div>';
			}
			$template = 'starmus-audio-editor-ui.php';
			return StarmusTemplateLoaderHelper::secure_render_template($template, $context);
		} catch (Throwable $e) {
			$this->log_error('Editor shortcode error', $e);
			return '<div class="notice notice-error"><p>' . esc_html__('Audio editor unavailable.', 'starmus-audio-recorder') . '</p></div>';
		}
	}

	/**
	 * Safely render the editor template with the provided context.
	 *
	 * @param array $context Data exposed to the template during rendering.
	 *
	 * @return string Rendered markup or an error notice.
	 */
	private function render_template_secure(array $context): string
	{
		$default_template = STARMUS_PATH . 'src/templates/starmus-audio-editor-ui.php';
		$template_path    = apply_filters('starmus_editor_template', $default_template, $context);
		if (! $this->is_valid_template_path($template_path)) {
			return '<div class="notice notice-error"><p>' . esc_html__('Invalid template path.', 'starmus-audio-recorder') . '</p></div>';
		}
		if (! file_exists($template_path)) {
			return '<div class="notice notice-error"><p>' . esc_html__('Editor template not found.', 'starmus-audio-recorder') . '</p></div>';
		}
		try {
			ob_start();
			$args = $context;
			include $template_path;
			$output = ob_get_clean();
			return $output !== false ? $output : '';
		} catch (Throwable $e) {
			$this->log_error('Editor template error', $e);
			return '<div class="notice notice-error"><p>' . esc_html__('Template loading failed.', 'starmus-audio-recorder') . '</p></div>';
		}
	}

	/**
	 * Validate that the template path resolves to an allowed directory.
	 *
	 * @param string $path Candidate template path.
	 *
	 * @return bool True when the path is safe to include.
	 */
	private function is_valid_template_path(string $path): bool
	{
		$real_path = realpath($path);
		if (! $real_path) {
			return false;
		}
		$allowed_dirs = array_filter(
			array(
				realpath(STARMUS_PATH),
				realpath(get_template_directory()),
				realpath(get_stylesheet_directory()),
			)
		);
		foreach ($allowed_dirs as $allowed_dir) {
			if ($allowed_dir && str_starts_with($real_path, $allowed_dir)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Conditionally enqueue front-end assets required by the editor.
	 *
	 * @return void
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
			wp_enqueue_style('starmus-unified-styles', STARMUS_URL . 'assets/css/starmus-styles.min.css', array(), STARMUS_VERSION);
			wp_enqueue_script('peaks-js', STARMUS_URL . 'vendor/js/peaks.min.js', array(), '4.0.0', true);
			wp_enqueue_script(
				'starmus-audio-editor',
				STARMUS_URL . 'src/js/starmus-audio-editor.js',
				array('jquery', 'peaks-js'),
				STARMUS_VERSION,
				true
			);
			$annotations_data = $this->parse_annotations_json($context['annotations_json']);
			wp_localize_script(
				'starmus-audio-editor',
				'STARMUS_EDITOR_DATA',
				array(
					'restUrl'         => esc_url_raw(rest_url(self::STARMUS_REST_NAMESPACE . '/annotations')),
					'nonce'           => wp_create_nonce('wp_rest'),
					'postId'          => absint($context['post_id']),
					'audioUrl'        => esc_url($context['audio_url']),
					'waveformDataUrl' => esc_url($context['waveform_url']),
					'annotations'     => $annotations_data,
				)
			);
		} catch (Throwable $e) {
			$this->log_error('Editor script enqueue error', $e);
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
			if (empty($json)) {
				return array();
			}
			$data = json_decode($json, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new Exception(json_last_error_msg());
			}
			return is_array($data) ? $data : array();
		} catch (Throwable $e) {
			$this->log_error('JSON parsing error', $e);
			return array();
		}
	}

	/**
	 * Build the editor rendering context for the current request.
	 *
	 * @return array|WP_Error Context array or WP_Error on failure.
	 */
	private function get_editor_context(): array|WP_Error
	{
		try {
			if ($this->cached_context !== null) {
				return $this->cached_context;
			}
			$nonce = sanitize_key($_GET['nonce'] ?? '');
			if (empty($nonce) || ! wp_verify_nonce($nonce, 'starmus_edit_audio')) {
				return new WP_Error('invalid_nonce', __('Security check failed.', 'starmus-audio-recorder'));
			}
			$post_id = absint($_GET['post_id'] ?? 0);
			if (! $post_id || ! get_post($post_id)) {
				return new WP_Error('invalid_id', __('Invalid submission ID.', 'starmus-audio-recorder'));
			}
			if (! current_user_can('edit_post', $post_id)) {
				return new WP_Error('permission_denied', __('Permission denied.', 'starmus-audio-recorder'));
			}
			$attachment_id = absint(get_post_meta($post_id, '_audio_attachment_id', true));
			if (! $attachment_id || get_post_type($attachment_id) !== 'attachment') {
				return new WP_Error('no_audio', __('No audio file attached.', 'starmus-audio-recorder'));
			}
			$audio_url = wp_get_attachment_url($attachment_id);
			if (! $audio_url) {
				return new WP_Error('no_audio_url', __('Audio file URL not available.', 'starmus-audio-recorder'));
			}
			$waveform_url         = $this->get_secure_waveform_url($attachment_id);
			$annotations_json     = get_post_meta($post_id, 'starmus_annotations_json', true);
			$annotations_json     = is_string($annotations_json) ? $annotations_json : '[]';
			$this->cached_context = array(
				'post_id'          => $post_id,
				'attachment_id'    => $attachment_id,
				'audio_url'        => $audio_url,
				'waveform_url'     => $waveform_url,
				'annotations_json' => $annotations_json,
			);
			return $this->cached_context;
		} catch (Throwable $e) {
			$this->log_error('Context retrieval error', $e);
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
		if (! is_string($wave_json_path) || empty($wave_json_path)) {
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
	 *
	 * @return void
	 */
	public function register_rest_endpoint(): void
	{
		register_rest_route(
			self::STARMUS_REST_NAMESPACE,
			'/annotations',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'handle_save_annotations'),
				'permission_callback' => array($this, 'can_save_annotations'),
				'args'                => array(
					'postId'      => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => array($this, 'validate_post_id'),
					),
					'annotations' => array(
						'required'          => true,
						'sanitize_callback' => array($this, 'sanitize_annotations'),
						'validate_callback' => array($this, 'validate_annotations'),
					),
				),
			)
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
			if (! is_array($value)) {
				return array();
			}
			$sanitized = array();
			foreach ($value as $annotation) {
				if (! is_array($annotation)) {
					continue;
				}
				$sanitized[] = array(
					'id'        => sanitize_key($annotation['id'] ?? ''),
					'startTime' => floatval($annotation['startTime'] ?? 0),
					'endTime'   => floatval($annotation['endTime'] ?? 0),
					'labelText' => wp_kses_post($annotation['labelText'] ?? ''),
					'color'     => sanitize_hex_color($annotation['color'] ?? '#000000'),
				);
			}
			return $sanitized;
		} catch (Throwable $e) {
			$this->log_error('Annotation sanitization error', $e);
			return array();
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
		if (! is_array($value) || count($value) > self::STARMUS_MAX_ANNOTATIONS) {
			return false;
		}
		foreach ($value as $annotation) {
			if (! is_array($annotation)) {
				return false;
			}
			if (! isset($annotation['startTime'], $annotation['endTime'])) {
				return false;
			}
			$start = floatval($annotation['startTime']);
			$end   = floatval($annotation['endTime']);
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
		} catch (Throwable $e) {
			$this->log_error('Permission check error', $e);
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
					array(
						'success' => false,
						'message' => __('Too many requests. Please wait.', 'starmus-audio-recorder'),
					),
					429
				);
			}
			$validation_result = $this->validate_annotation_consistency($annotations);
			if (is_wp_error($validation_result)) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => $validation_result->get_error_message(),
					),
					400
				);
			}
			$json_data = wp_json_encode($annotations);
			if ($json_data === false) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => __('Failed to encode annotations.', 'starmus-audio-recorder'),
					),
					500
				);
			}
			do_action('starmus_before_annotations_save', $post_id, $annotations);
			update_post_meta($post_id, 'starmus_annotations_json', $json_data);
			do_action('starmus_after_annotations_save', $post_id, $annotations);
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __('Annotations saved successfully.', 'starmus-audio-recorder'),
					'count'   => count($annotations),
				),
				200
			);
		} catch (Throwable $e) {
			$this->log_error('Save annotations error', $e);
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __('Internal server error.', 'starmus-audio-recorder'),
				),
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
		$key     = "starmus_ann_rl_{$user_id}_{$post_id}";
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
	private function validate_annotation_consistency(array $annotations)
	{
		if (empty($annotations)) {
			return true;
		}
		usort(
			$annotations,
			function ($a, $b) {
				return $a['startTime'] <=> $b['startTime'];
			}
		);
		for ($i = 0; $i < count($annotations) - 1; $i++) {
			$current = $annotations[$i];
			$next    = $annotations[$i + 1];
			if ($current['endTime'] > $next['startTime']) {
				return new WP_Error('overlap_detected', __('Overlapping annotations detected.', 'starmus-audio-recorder'));
			}
		}
		return true;
	}

	/**
	 * Log an error with unified context handling.
	 *
	 * @param string    $context Message prefix describing the failure.
	 * @param Throwable $e       Captured exception instance.
	 *
	 * @return void
	 */
	private function log_error(string $context, Throwable $e): void
	{
		if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
			// Debug logging has been removed for production.
			error_log($e);
		}
	}
}
