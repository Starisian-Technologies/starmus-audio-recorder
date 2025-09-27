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

/**
 * Renders the user interface for the audio recorder and recordings list.
 * Pure presentation: shortcodes, enqueue, template rendering, cached term helpers.
 * (REST + upload logic live in StarmusRestHandler / StarmusSubmissionHandler.)
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
         * Register all WordPress hooks required for the front-end recorder UI.
         *
         * @return void
         */
        private function register_hooks(): void
        {
                add_shortcode('starmus_my_recordings', array($this, 'render_my_recordings_shortcode'));
                add_shortcode('starmus_audio_recorder_form', array($this, 'render_recorder_shortcode'));

		add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

		// Keep your transient cache hygiene for taxonomies
		add_action('create_language', array($this, 'clear_taxonomy_transients'));
		add_action('edit_language', array($this, 'clear_taxonomy_transients'));
		add_action('delete_language', array($this, 'clear_taxonomy_transients'));

		add_action('create_recording-type', array($this, 'clear_taxonomy_transients'));
		add_action('edit_recording-type', array($this, 'clear_taxonomy_transients'));
		add_action('delete_recording-type', array($this, 'clear_taxonomy_transients'));
	}

        /**
         * Render the "My Recordings" shortcode.
         *
         * @param array $atts Shortcode attributes supplied by WordPress.
         *
         * @return string Rendered HTML for the recordings list.
         */
        public function render_my_recordings_shortcode($atts = array()): string
	{
		if (!is_user_logged_in()) {
			return '<p>' . esc_html__('You must be logged in to view your recordings.', 'starmus-audio-recorder') . '</p>';
		}

		try {
			$attributes = shortcode_atts(array('posts_per_page' => 10), $atts);
			$posts_per_page = max(1, absint($attributes['posts_per_page']));
			$paged = get_query_var('paged') ? (int) get_query_var('paged') : 1;
			$cpt_slug = $this->settings ? $this->settings->get('cpt_slug', 'audio-recording') : 'audio-recording';

			$query = new \WP_Query(
				array(
					'post_type' => $cpt_slug,
					'author' => get_current_user_id(),
					'posts_per_page' => $posts_per_page,
					'paged' => $paged,
					'post_status' => array('publish', 'draft', 'pending', 'private'),
				)
			);

			return $this->render_template(
				'starmus-my-recordings-list.php',
				array(
					'query' => $query,
					// Preserve your original pattern where list screens are reachable:
					'edit_page_url' => $this->get_edit_page_url_admin(),
				)
			);
		} catch (\Throwable $e) {
			StarmusLogger::log('UI:render_my_recordings', $e);
			return '<p>' . esc_html__('Unable to load recordings.', 'starmus-audio-recorder') . '</p>';
		}
	}

        /**
         * Render the recorder form shortcode output.
         *
         * @param array $atts Shortcode attributes supplied by WordPress.
         *
         * @return string Rendered HTML for the recorder UI.
         */
        public function render_recorder_shortcode($atts = array()): string
	{
		if (!is_user_logged_in()) {
			return '<p>' . esc_html__('You must be logged in to record audio.', 'starmus-audio-recorder') . '</p>';
		}

		// Keep your integration point
		do_action('starmus_before_recorder_render');

		try {
			$template_args = array(
				'form_id' => 'starmus_recorder_form',
				'consent_message' => $this->settings ? $this->settings->get('consent_message', 'I consent to the terms and conditions.') : 'I consent to the terms and conditions.',
				'data_policy_url' => $this->settings ? $this->settings->get('data_policy_url', '') : '',
				'recording_types' => $this->get_cached_terms('recording-type', 'starmus_recording_types_list'),
				'languages' => $this->get_cached_terms('language', 'starmus_languages_list'),
			);

			// Preserve your admin flag for front-end JS
			$is_admin = current_user_can('administrator') || current_user_can('manage_options') || current_user_can('super_admin');
			$admin_flag = '<script>window.isStarmusAdmin = ' . ($is_admin ? 'true' : 'false') . ';</script>';

			return $admin_flag . $this->render_template('starmus-audio-recorder-ui.php', $template_args);

		} catch (\Throwable $e) {
			StarmusLogger::log('UI:render_recorder_shortcode', $e);
			return '<p>' . esc_html__('The audio recorder is temporarily unavailable.', 'starmus-audio-recorder') . '</p>';
		}
	}

        /**
         * Register and localize front-end assets for the recorder UI.
         *
         * @return void
         */
        public function enqueue_scripts(): void
	{
		try {
			if (is_admin()) {
				return;
			}

			global $post;
			if (!is_a($post, 'WP_Post') || empty($post->post_content)) {
				return;
			}

			$recorder_shortcode = 'starmus_audio_recorder_form';
			$list_shortcode = 'starmus_my_recordings';
			$has_recorder = has_shortcode($post->post_content, $recorder_shortcode);
			$has_list = has_shortcode($post->post_content, $list_shortcode);

			// Styles if either shortcode exists
			if ($has_recorder || $has_list) {
				wp_enqueue_style(
					'starmus-unified-styles',
					trailingslashit(STARMUS_URL) . 'assets/css/starmus-styles.min.css',
					array(),
					defined('STARMUS_VERSION') ? STARMUS_VERSION : '1.0.0'
				);
			}

			// Scripts only when recorder is present
			$script_handle = null;
			if ($has_recorder) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					wp_enqueue_script('starmus-hooks', trailingslashit(STARMUS_URL) . 'src/js/starmus-audio-recorder-hooks.js', array(), STARMUS_VERSION, true);
					wp_enqueue_script('starmus-recorder-module', trailingslashit(STARMUS_URL) . 'src/js/starmus-audio-recorder-module.js', array('starmus-hooks'), STARMUS_VERSION, true);
					wp_enqueue_script('starmus-submissions-handler', trailingslashit(STARMUS_URL) . 'src/js/starmus-audio-recorder-submissions-handler.js', array('starmus-hooks'), STARMUS_VERSION, true);
					wp_enqueue_script('starmus-ui-controller', trailingslashit(STARMUS_URL) . 'src/js/starmus-audio-recorder-ui-controller.js', array('starmus-hooks', 'starmus-recorder-module', 'starmus-submissions-handler'), STARMUS_VERSION, true);
					wp_enqueue_script('tus-js', trailingslashit(STARMUS_URL) . 'vendor/js/tus.min.js', array(), '4.3.1', true);

					$script_handle = 'starmus-ui-controller';
				} else {
					wp_enqueue_script('starmus-app', trailingslashit(STARMUS_URL) . 'assets/js/starmus-app.min.js', array(), STARMUS_VERSION, true);
					wp_enqueue_script('tus-js', trailingslashit(STARMUS_URL) . 'vendor/js/tus.min.js', array(), '4.3.1', true);

					$script_handle = 'starmus-app';
				}

				// Localize REST endpoint + nonce exactly as you had
				wp_localize_script(
					$script_handle,
					'starmusFormData',
					array(
                                                'rest_url' => esc_url_raw(rest_url(StarmusSubmissionHandler::STAR_REST_NAMESPACE . '/upload-chunk')),
						'rest_nonce' => wp_create_nonce('wp_rest'),
					)
				);

				// Pass allowed languages + validation flag from settings
				$allowed_languages_raw = $this->settings ? $this->settings->get('allowed_languages', '') : '';
				$allowed_languages = array_filter(array_map('trim', explode(',', $allowed_languages_raw)));
				$bypass_language_validation = (bool) ($this->settings ? $this->settings->get('bypass_language_validation', true) : true);

				wp_localize_script(
					$script_handle,
					'starmusSettings',
					array(
						'allowedLanguages' => $allowed_languages,
						'bypassLanguageValidation' => $bypass_language_validation,
					)
				);

				// Optional TUS endpoint bootstrap
				$tus_endpoint = $this->settings ? $this->settings->get('tus_endpoint', '') : '';
				if (!empty($tus_endpoint)) {
					wp_add_inline_script(
						$script_handle,
						'window.starmusTus = { endpoint: "' . esc_url_raw($tus_endpoint) . '" };',
						'before'
					);
				}
			}

			// Keep your global offline sync script; align dependency to whichever handle we actually registered
			$offline_dep = (defined('WP_DEBUG') && WP_DEBUG) ? 'starmus-submissions-handler' : ($script_handle ?: 'jquery');
			wp_enqueue_script(
				'starmus-offline-sync',
				trailingslashit(STARMUS_URL) . 'src/js/starmus-offline-sync.js',
				array($offline_dep),
				'1.0.0',
				true
			);

		} catch (\Throwable $e) {
			StarmusLogger::log('UI:enqueue_scripts', $e);
		}
	}

        /**
         * Render a template file with provided arguments.
         * Searches child theme, parent theme, then plugin (src/templates).
         *
         * @param string $template_file Template filename to locate.
         * @param array  $args          Context passed into the template scope.
         *
         * @return string Rendered HTML output from the template.
         */
        private function render_template(string $template_file, array $args = array()): string
	{
		try {
			$template_name = basename($template_file);

			$locations = array(
				trailingslashit(get_stylesheet_directory()) . 'starmus/' . $template_name,
				trailingslashit(get_template_directory()) . 'starmus/' . $template_name,
				trailingslashit(STARMUS_PATH) . 'src/templates/' . $template_name,
			);

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
         * Get cached terms for a taxonomy, with transient caching.
         *
         * @param string $taxonomy  Taxonomy slug.
         * @param string $cache_key Transient cache key for storing the terms.
         *
         * @return array List of taxonomy terms.
         */
        private function get_cached_terms(string $taxonomy, string $cache_key): array
	{
		$terms = get_transient($cache_key);
		if (false === $terms) {
			$terms = get_terms(
				array(
					'taxonomy' => $taxonomy,
					'hide_empty' => false,
				)
			);
			if (!is_wp_error($terms)) {
				set_transient($cache_key, $terms, 12 * HOUR_IN_SECONDS);
			} else {
				StarmusLogger::log('UI:get_cached_terms', new \Exception($terms->get_error_message()));
				$terms = array();
			}
		}
		return is_array($terms) ? $terms : array();
	}

        /**
         * Clear term caches for Language and Recording Type.
         *
         * @return void
         */
        public function clear_taxonomy_transients(): void
	{
		delete_transient('starmus_languages_list');
		delete_transient('starmus_recording_types_list');
	}

        /**
         * Admin edit screen for CPT list (kept for back-compat with your template usage).
         *
         * @return string URL to the admin list table for recordings.
         */
        private function get_edit_page_url_admin(): string
	{
		$cpt = $this->settings ? $this->settings->get('cpt_slug', 'audio-recording') : 'audio-recording';
		return admin_url('edit.php?post_type=' . $cpt);
	}

        /**
         * Front-end edit page URL (optional, preserved if your theme templates use it).
         *
         * @return string URL to the front-end edit page or an empty string.
         */
        private function get_edit_page_url(): string
	{
		$edit_page_id = $this->settings ? $this->settings->get('edit_page_id') : 0;
		if (!empty($edit_page_id)) {
			$permalink = get_permalink((int) $edit_page_id);
			return $permalink ? esc_url($permalink) : '';
		}
		return '';
	}
}
