<?php

/**
 * Front-end presentation layer for the Starmus recorder experience.
 *
 * @package   Starmus
 */

namespace Starisian\Sparxstar\Starmus\frontend;

use Throwable;
use Exception;

if (! \defined('ABSPATH')) {
	exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper;

/**
 * Renders the user interface for the audio recorder and recordings list.
 * Pure presentation: shortcodes + template rendering.
 * Assets are handled separately in StarmusAssets.
 */
class StarmusAudioRecorderUI
{
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

		StarmusLogger::info(
			'Recorder component available, registering recorder hooks',
			['component' => self::class]
		);
		add_action('starmus_after_audio_upload', [$this, 'save_all_metadata'], 10, 3);
		add_filter('starmus_audio_upload_success_response', [$this, 'add_conditional_redirect'], 10, 3);

		// Cron scheduling moved to activation to avoid performance issues
		// Clear cache when a Language is added, edited, or deleted.
		add_action('delete_starmus_tax_language', $this->clear_taxonomy_transients(...));
		// Clear cache when a Recording Type is added, edited, or deleted.
		add_action('delete_starmus_story_type', $this->clear_taxonomy_transients(...));
	}

	/**
	 * Render the recorder form shortcode.
	 */
	public function render_recorder_shortcode(): string
	{

		try {
			$template_args = [
				'form_id'         => 'starmus_recorder_form',
				'consent_message' => $this->settings instanceof StarmusSettings ? $this->settings->get('consent_message', 'I consent to the terms and conditions.') : 'I consent to the terms and conditions.',
				'data_policy_url' => $this->settings instanceof StarmusSettings ? $this->settings->get('data_policy_url', '') : '',
				'recording_types' => $this->get_cached_terms('starmus_story_type', 'starmus_recording_types_list'),
				'languages'       => $this->get_cached_terms('starmus_tax_language', 'starmus_languages_list'),
			];

			return StarmusTemplateLoaderHelper::secure_render_template('starmus-audio-recorder-ui.php', $template_args);
		} catch (Throwable $throwable) {
			StarmusLogger::log($throwable);
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
					'script_id'      => 0, // NEW: Script context
				],
				$atts,
				'starmus_audio_re_recorder'
			);

			// Get post_id from shortcode attribute or URL parameter
			$post_id = absint($atts['post_id']);
			if ($post_id <= 0 && isset($_GET['recording_id'])) {
				$post_id = absint($_GET['recording_id']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}

			// Validate post exists and is an audio-recording
			if ($post_id <= 0) {
				return '<p>' . esc_html__('No recording specified.', 'starmus-audio-recorder') . '</p>';
			}

			$cpt_slug = $this->settings instanceof StarmusSettings
				? $this->settings->get('cpt_slug', 'audio-recording')
				: 'audio-recording';

			if (get_post_type($post_id) !== $cpt_slug) {
				return '<p>' . esc_html__('Invalid recording ID.', 'starmus-audio-recorder') . '</p>';
			}

			// Get existing post data to pre-fill the form
			$post           = get_post($post_id);
			$existing_title = $post ? $post->post_title : '';

			// Get existing taxonomies
			$language_terms = wp_get_object_terms($post_id, 'starmus_tax_language');
			$language_id    = (! is_wp_error($language_terms) && ! empty($language_terms)) ? $language_terms[0]->term_id : 0;

			$type_terms = wp_get_object_terms($post_id, 'starmus_story_type');
			$type_id    = (! is_wp_error($type_terms) && ! empty($type_terms)) ? $type_terms[0]->term_id : 0;

			// NEW: Script Context Override
			$script_id = absint($atts['script_id']);
			$dialect_id = 0; // Default

			if ($script_id > 0 && get_post_type($script_id) === 'starmus-script') {
				$script_post = get_post($script_id);
				if ($script_post) {
					// 1. Populate Title from Script
					$existing_title = $script_post->post_title;

					// 2. Populate Language from Script
					$script_langs = wp_get_object_terms($script_id, 'starmus_tax_language');
					if (! is_wp_error($script_langs) && ! empty($script_langs)) {
						$language_id = $script_langs[0]->term_id;
					}

					// 3. Populate Dialect from Script (Assuming starmus_tax_dialect)
					$script_dialects = wp_get_object_terms($script_id, 'starmus_tax_dialect');
					if (! is_wp_error($script_dialects) && ! empty($script_dialects)) {
						$dialect_id = $script_dialects[0]->term_id;
					}

					// 4. Populate Recording Type -> oral_submission
					$type_term = get_term_by('slug', 'oral_submission', 'starmus_story_type');
					if ($type_term && ! is_wp_error($type_term)) {
						$type_id = $type_term->term_id;
					}
				}
			}

			$template_args = [
				'form_id'           => 'rerecord',
				'post_id'           => $post_id,
				'artifact_id'       => $post_id, // Link to original recording
				'existing_title'    => $existing_title,
				'existing_language' => $language_id,
				'existing_type'     => $type_id,
				'existing_dialect'  => $dialect_id, // NEW passed var
				'consent_message'   => $this->settings instanceof StarmusSettings
					? $this->settings->get('consent_message', 'I consent to the terms and conditions.')
					: 'I consent to the terms and conditions.',
				'data_policy_url' => $this->settings instanceof StarmusSettings
					? $this->settings->get('data_policy_url', '')
					: '',
				'allowed_file_types' => $this->settings instanceof StarmusSettings
					? $this->settings->get('allowed_file_types', 'webm')
					: 'webm',
				'recording_types' => $this->get_cached_terms('starmus_story_type', 'starmus_recording_types_list'),
				'languages'       => $this->get_cached_terms('starmus_tax_language', 'starmus_languages_list'),
			];

			return StarmusTemplateLoaderHelper::secure_render_template(
				'starmus-audio-re-recorder-ui.php',
				$template_args
			);
		} catch (Throwable $throwable) {
			StarmusLogger::log($throwable);
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
				StarmusLogger::log(new Exception($terms->get_error_message()));
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
}
