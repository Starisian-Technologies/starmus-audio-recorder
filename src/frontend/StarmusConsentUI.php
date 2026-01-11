<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\frontend;

use Starisian\Sparxstar\Starmus\core\StarmusConsentHandler;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper;
use WP_Error;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class StarmusConsentUI
 *
 * Handles the Frontend UI for the Starmus Consent Form.
 *
 * @package Starisian\Sparxstar\Starmus\frontend
 */
class StarmusConsentUI
{
	/**
	 * StarmusConsentHandler instance.
	 *
	 * @var StarmusConsentHandler
	 */
	private StarmusConsentHandler $handler;

	/**
	 * StarmusSettings instance.
	 *
	 * @var StarmusSettings
	 */
	private StarmusSettings $settings;

	/**
	 * Constructor.
	 *
	 * @param StarmusConsentHandler $handler  Consent handler.
	 * @param StarmusSettings       $settings Plugin settings.
	 */
	public function __construct(StarmusConsentHandler $handler, StarmusSettings $settings)
	{
		$this->handler  = $handler;
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void
	{
		add_action('init', [$this, 'handle_submission']);
	}

	/**
	 * Render the shortcode.
	 *
	 * @return string Rendered HTML.
	 */
	public function render_shortcode(): string
	{
		// Use secure render as requested. Note: This enforces login.
		return StarmusTemplateLoaderHelper::secure_render_template('starmus-consent-form.php');
	}

	/**
	 * Handle form submission.
	 *
	 * @return void
	 */
	public function handle_submission(): void
	{
		if (! isset($_POST['starmus_consent_nonce']) || ! wp_verify_nonce($_POST['starmus_consent_nonce'], 'starmus_consent_action')) {
			return;
		}

		// Include necessary files for media upload.
		if (! function_exists('media_handle_upload')) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$legal_name = isset($_POST['sparxstar_legal_name']) ? sanitize_text_field(wp_unslash($_POST['sparxstar_legal_name'])) : '';
		$email      = isset($_POST['sparxstar_email']) ? sanitize_email(wp_unslash($_POST['sparxstar_email'])) : '';
		$terms_type = isset($_POST['starmus_terms_type']) ? sanitize_text_field(wp_unslash($_POST['starmus_terms_type'])) : '';

		if (empty($legal_name) || empty($email)) {
			// In a real scenario, we might want to redirect back with errors.
			// For now, valid inputs valid input handling is prioritized.
			return;
		}

		// Handle signature upload.
		$attachment_id = 0;
		if (isset($_FILES['starmus_contributor_signature']) && ! empty($_FILES['starmus_contributor_signature']['name'])) {
			$attachment_id = media_handle_upload('starmus_contributor_signature', 0);
			if (is_wp_error($attachment_id)) {
				// Determine how to handle error. For now logging it or just proceeding without signature if acceptable,
				// but usually signature is required.
				// $error_message = $attachment_id->get_error_message();
				$attachment_id = 0;
			}
		}

		// Create Contributor.
		$contributor_id = $this->handler->create_contributor([
			'name'  => $legal_name,
			'email' => $email,
		]);

		if (is_wp_error($contributor_id)) {
			return;
		}

		// Create Consent Recording.
		$consent_data = [
			'terms_type' => $terms_type,
			'signature'  => $attachment_id,
			'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
		];

		$recording_id = $this->handler->create_consent_recording($contributor_id, $consent_data);

		if (is_wp_error($recording_id)) {
			return;
		}

		// Redirect to Recorder.
		$recorder_page_id = $this->settings->get('recorder_page_id');
		$redirect_url     = $recorder_page_id ? get_permalink($recorder_page_id) : home_url();
		$redirect_url     = add_query_arg('starmus_recording_id', $recording_id, $redirect_url);

		wp_safe_redirect($redirect_url);
		exit;
	}
}
