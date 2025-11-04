<?php
/**
 * Front-end presentation layer for the Starmus recorder experience.
 *
 * @package   Starmus
 */

namespace Starisian\Sparxstar\Starmus\frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\includes\StarmusSubmissionHandler;
use Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper;
use Exception;
use Throwable;

/**
 * Renders the user interface for the audio recorder and recordings list.
 * Pure presentation: shortcodes + template rendering.
 * Assets are handled separately in StarmusAssets.
 */
class StarmusAudioRecorderUI {



	/**
	 * REST namespace exposed to localized front-end scripts.
	 */
	public const STARMUS_REST_NAMESPACE = StarmusSubmissionHandler::STARMUS_REST_NAMESPACE;

	/**
	 * Optional settings container used to hydrate UI data.
	 */
	private ?StarmusSettings $settings = null;

	/**
	 * Prime the UI layer with optional settings for template hydration.
	 *
	 * @param StarmusSettings|null $settings Configuration object, if available.
	 */
	public function __construct( ?StarmusSettings $settings ) {
		$this->settings = $settings;
		$this->register_hooks();
	}

	/**
	 * Register shortcodes and taxonomy cache hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// Cache hygiene for taxonomies.
		add_action( 'create_language', array( $this, 'clear_taxonomy_transients' ) );
		add_action( 'edit_language', array( $this, 'clear_taxonomy_transients' ) );
		add_action( 'delete_language', array( $this, 'clear_taxonomy_transients' ) );

		add_action( 'create_recording-type', array( $this, 'clear_taxonomy_transients' ) );
		add_action( 'edit_recording-type', array( $this, 'clear_taxonomy_transients' ) );
		add_action( 'delete_recording-type', array( $this, 'clear_taxonomy_transients' ) );
	}

	/**
	 * Render the recorder form shortcode.
	 */
	public function render_recorder_shortcode(): string {

		try {
			$template_args = array(
				'form_id'         => 'starmus_recorder_form',
				'consent_message' => $this->settings ? $this->settings->get( 'consent_message', 'I consent to the terms and conditions.' ) : 'I consent to the terms and conditions.',
				'data_policy_url' => $this->settings ? $this->settings->get( 'data_policy_url', '' ) : '',
				'recording_types' => $this->get_cached_terms( 'recording-type', 'starmus_recording_types_list' ),
				'languages'       => $this->get_cached_terms( 'language', 'starmus_languages_list' ),
			);

			return StarmusTemplateLoaderHelper::secure_render_template( 'starmus-audio-recorder-ui.php', $template_args );

		} catch ( \Throwable $e ) {
			error_log( $e );
			StarmusLogger::log( 'UI:render_recorder_shortcode', $e );
			return '<p>' . esc_html__( 'The audio recorder is temporarily unavailable.', 'starmus-audio-recorder' ) . '</p>';
		}
	}

  /**
 * Render the re-recorder (single-button variant).
 * Usage: [starmus_audio_re_recorder title="..." language="..." recording_type="..."]
 */
public function render_re_recorder_shortcode( array $atts = [] ): string {
	try {
		$atts = shortcode_atts(
			array(
				'title'          => '',
				'language'       => '',
				'recording_type' => '',
			),
			$atts,
			'starmus_audio_re_recorder'
		);

		$template_args = array(
			'title'           => sanitize_text_field( $atts['title'] ),
			'language'        => sanitize_text_field( $atts['language'] ),
			'recording_type'  => sanitize_text_field( $atts['recording_type'] ),
			'container_id'    => 'starmus-re-recorder-' . wp_generate_uuid4(),
			'consent_message' => $this->settings
				? $this->settings->get( 'consent_message', 'I consent to the terms and conditions.' )
				: 'I consent to the terms and conditions.',
			'data_policy_url' => $this->settings
				? $this->settings->get( 'data_policy_url', '' )
				: '',
		);

		return \Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper::secure_render_template(
			'starmus-audio-re-recorder.php',
			$template_args
		);

	} catch ( \Throwable $e ) {
		\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::log( 'UI:render_re_recorder_shortcode', $e );
		return '<p>' . esc_html__( 'The re-recorder is temporarily unavailable.', 'starmus-audio-recorder' ) . '</p>';
	}
}


	/**
	 * Get cached terms with transient support.
	 */
	private function get_cached_terms( string $taxonomy, string $cache_key ): array {
		$terms = get_transient( $cache_key );
		if ( false === $terms ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				set_transient( $cache_key, $terms, 12 * HOUR_IN_SECONDS );
			} else {
				StarmusLogger::log( 'UI:get_cached_terms', new \Exception( $terms->get_error_message() ) );
				$terms = array();
			}
		}
		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Clear cached terms.
	 */
	public function clear_taxonomy_transients(): void {
		delete_transient( 'starmus_languages_list' );
		delete_transient( 'starmus_recording_types_list' );
	}
}
