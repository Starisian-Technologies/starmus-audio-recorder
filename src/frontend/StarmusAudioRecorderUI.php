<?php
/**
 * Front-end presentation layer for the Starmus recorder experience.
 *
 * @package   Starmus
 */

namespace Starmus\frontend;

if (!defined('ABSPATH')) {
	exit;
}

use Starmus\helpers\StarmusLogger;
use Starmus\includes\StarmusSettings;
use Starmus\frontend\StarmusSubmissionHandler;

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
	public const STAR_REST_NAMESPACE = StarmusSubmissionHandler::STAR_REST_NAMESPACE;

	/**
	 * Optional settings container used to hydrate UI data.
	 */
	private ?StarmusSettings $settings = null;

	/**
	 * Prime the UI layer with optional settings for template hydration.
	 *
	 * @param StarmusSettings|null $settings Configuration object, if available.
	 */
	public function __construct(?StarmusSettings $settings)
	{
		$this->settings = $settings;
		$this->register_hooks();
	}

	/**
	 * Register shortcodes and taxonomy cache hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void
	{
		add_shortcode('starmus_my_recordings', [$this, 'render_my_recordings_shortcode']);
		add_shortcode('starmus_audio_recorder_form', [$this, 'render_recorder_shortcode']);

		// Cache hygiene for taxonomies.
		add_action('create_language', [$this, 'clear_taxonomy_transients']);
		add_action('edit_language', [$this, 'clear_taxonomy_transients']);
		add_action('delete_language', [$this, 'clear_taxonomy_transients']);

		add_action('create_recording-type', [$this, 'clear_taxonomy_transients']);
		add_action('edit_recording-type', [$this, 'clear_taxonomy_transients']);
		add_action('delete_recording-type', [$this, 'clear_taxonomy_transients']);
	}

	/**
	 * Render the "My Recordings" shortcode.
	 */
	public function render_my_recordings_shortcode($atts = []): string
	{
		if (!is_user_logged_in()) {
			return '<p>' . esc_html__('You must be logged in to view your recordings.', 'starmus-audio-recorder') . '</p>';
		}

		try {
			$attributes = shortcode_atts(['posts_per_page' => 10], $atts);
			$posts_per_page = max(1, absint($attributes['posts_per_page']));
			$paged = get_query_var('paged') ? (int) get_query_var('paged') : 1;
			$cpt_slug = $this->settings ? $this->settings->get('cpt_slug', 'audio-recording') : 'audio-recording';

			$query = new \WP_Query(
				[
					'post_type' => $cpt_slug,
					'author' => get_current_user_id(),
					'posts_per_page' => $posts_per_page,
					'paged' => $paged,
					'post_status' => ['publish', 'draft', 'pending', 'private'],
				]
			);

			return $this->render_template(
				'starmus-my-recordings-list.php',
				[
					'query' => $query,
					'edit_page_url' => $this->get_edit_page_url_admin(),
				]
			);
		} catch (\Throwable $e) {
			StarmusLogger::log('UI:render_my_recordings', $e);
			return '<p>' . esc_html__('Unable to load recordings.', 'starmus-audio-recorder') . '</p>';
		}
	}

	/**
	 * Render the recorder form shortcode.
	 */
	public function render_recorder_shortcode($atts = []): string
	{
		if (!is_user_logged_in()) {
			return '<p>' . esc_html__('You must be logged in to record audio.', 'starmus-audio-recorder') . '</p>';
		}

		do_action('starmus_before_recorder_render');

		try {
			$template_args = [
				'form_id' => 'starmus_recorder_form',
				'consent_message' => $this->settings ? $this->settings->get('consent_message', 'I consent to the terms and conditions.') : 'I consent to the terms and conditions.',
				'data_policy_url' => $this->settings ? $this->settings->get('data_policy_url', '') : '',
				'recording_types' => $this->get_cached_terms('recording-type', 'starmus_recording_types_list'),
				'languages' => $this->get_cached_terms('language', 'starmus_languages_list'),
			];

			$is_admin = current_user_can('administrator') || current_user_can('manage_options') || current_user_can('super_admin');
			$admin_flag = '<script>window.isStarmusAdmin = ' . ($is_admin ? 'true' : 'false') . ';</script>';

			return $admin_flag . $this->render_template('starmus-audio-recorder-ui.php', $template_args);

		} catch (\Throwable $e) {
			StarmusLogger::log('UI:render_recorder_shortcode', $e);
			return '<p>' . esc_html__('The audio recorder is temporarily unavailable.', 'starmus-audio-recorder') . '</p>';
		}
	}

	/**
	 * Render a template file.
	 */
	private function render_template(string $template_file, array $args = []): string
	{
		try {
			$template_name = basename($template_file);

			$locations = [
				trailingslashit(get_stylesheet_directory()) . 'starmus/' . $template_name,
				trailingslashit(get_template_directory()) . 'starmus/' . $template_name,
				trailingslashit(STARMUS_PATH) . 'src/templates/' . $template_name,
			];

			$template_path = '';
			foreach ($locations as $location) {
				if (file_exists($location)) {
					$template_path = $location;
					break;
				}
			}

			if (!$template_path) {
				return '';
			}

			if (is_array($args)) {
				extract($args, EXTR_SKIP);
			}

			ob_start();
			include $template_path;
			return (string) ob_get_clean();

		} catch (\Throwable $e) {
			StarmusLogger::log('UI:render_template', $e);
			return '';
		}
	}

	/**
	 * Get cached terms with transient support.
	 */
	private function get_cached_terms(string $taxonomy, string $cache_key): array
	{
		$terms = get_transient($cache_key);
		if (false === $terms) {
			$terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
			if (!is_wp_error($terms)) {
				set_transient($cache_key, $terms, 12 * HOUR_IN_SECONDS);
			} else {
				StarmusLogger::log('UI:get_cached_terms', new \Exception($terms->get_error_message()));
				$terms = [];
			}
		}
		return is_array($terms) ? $terms : [];
	}

	/**
	 * Clear cached terms.
	 */
	public function clear_taxonomy_transients(): void
	{
		delete_transient('starmus_languages_list');
		delete_transient('starmus_recording_types_list');
	}

	/**
	 * Admin edit screen URL.
	 */
	private function get_edit_page_url_admin(): string
	{
		$cpt = $this->settings ? $this->settings->get('cpt_slug', 'audio-recording') : 'audio-recording';
		return admin_url('edit.php?post_type=' . $cpt);
	}
}
