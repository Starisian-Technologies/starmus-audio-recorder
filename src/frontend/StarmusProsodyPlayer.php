<?php

namespace Starisian\Sparxstar\Starmus\frontend;

use function class_exists;
use function json_encode;
use function ob_get_clean;
use function ob_start;

use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusProsodyDAL;
use Starisian\Sparxstar\Starmus\data\StarmusProsodyDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;

if (! \defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

/**
 * Class StarmusProsodyPlayer
 *
 * Handles the Shortcode [prosody_reader], Assets, and AJAX Listener.
 *
 * @package Starisian\Sparxstar\Starmus\frontend
 */
class StarmusProsodyPlayer
{
	private ?StarmusProsodyDAL $dal = null;

	public function __construct(?StarmusProsodyDAL $prosody_dal = null)
	{
		$this->dal = $prosody_dal ?: new StarmusProsodyDAL();
		$this->register_hooks();
	}

	/**
	 * Summary of register_hooks
	 */
	private function register_hooks(): void
	{

		// Hooks
		add_action('init', $this->register_shortcodes(...));
		add_action('init', $this->init_dal(...));
		add_action('wp_enqueue_scripts', $this->register_assets(...));

		// AJAX Endpoints (Authenticated & Public if needed, usually Auth only for this)
		add_action('wp_ajax_starmus_save_pace', $this->handle_ajax_save(...));
	}

	public function register_shortcodes(): void
	{
		// Register the shortcode
		add_shortcode('prosody_reader', $this->render_shortcode(...));
	}

	public function init_dal(): void
	{
		if ($this->dal instanceof StarmusProsodyDAL) {
			StarmusLogger::info('StarmusProsodyDAL loaded');
			return;
		}

		if (! class_exists(StarmusProsodyDAL::class)) {
			StarmusLogger::error('StarmusProsodyDAL class not found');
		}

		try {
			$this->dal = new StarmusProsodyDAL();
		} catch (Throwable $throwable) {
			StarmusLogger::log($throwable);
		}
	}

	/**
	 * 1. Register JS/CSS
	 */
	public function register_assets(): void
	{
		// Enqueue the CSS (assuming you saved the CSS from previous chat to a file)
		wp_register_style(
			'starmus-prosody-css',
			STARMUS_URL . 'src/css/starmus-prosody-engine.css',
			[],
			STARMUS_VERSION
		);

		// Enqueue the JS (assuming you saved the JS Class to a file)
		wp_register_script(
			'starmus-prosody-js',
			STARMUS_URL . 'src/js/prosody/starmus-prosody-engine.js',
			[],
			STARMUS_VERSION,
			true // Load in footer
		);
	}

	/**
	 * 2. The Shortcode Output
	 * Usage: [prosody_reader] (uses current post) OR [prosody_reader id="123"]
	 *
	 * @param array $atts Shortcode attributes
	 *
	 * @return string HTML Output
	 */
	public function render_shortcode(array $atts): string
	{
		try {
			$args = shortcode_atts(
				[
					'id' => get_the_ID(),
				],
				$atts
			);

			$post_id = (int) $args['id'];
			$data    = $this->dal->get_script_payload($post_id);

			if ($data === []) {
				return '<div class="prosody-error">Error: Script data not found.</div>';
			}

			// Load Assets
			wp_enqueue_style('starmus-prosody-css');
			wp_enqueue_script('starmus-prosody-js');

			// Pass Data to JS via Inline Script
			// We use wp_add_inline_script for type safety (integers remain integers)
			// and explicit global assignment.
			$json_payload = wp_json_encode($data);

			if (false === $json_payload) {
				// Fallback or log error
				$json_payload = '{}';
			}

			wp_add_inline_script(
				'starmus-prosody-js',
				"console.log('Starmus Prosody Payload Injected'); window.StarmusProsodyData = {$json_payload};",
				'before'
			);

			// Render The HTML Shell
			ob_start();
?>
			<div id="cognitive-regulator">
				<!-- CALIBRATION LAYER -->
				<div id="calibration-layer">
					<div class="tap-zone" id="btn-tap">
						<div class="tap-icon">👆</div>
						<div class="tap-label">TAP RHYTHM</div>
						<div class="tap-sub">Spacebar or Click to set pace</div>
					</div>
					<div class="tap-feedback" id="tap-feedback">...</div>
				</div>

				<!-- THE STAGE -->
				<div id="scaffold-stage" class="hidden">
					<div id="text-flow"></div>
					<div class="spacer"></div>
				</div>

				<!-- CONTROLS -->
				<div class="control-deck hidden" id="main-controls">
					<button id="btn-engage" class="neutral-btn">
						<span class="icon">▶</span> <span class="label">ENGAGE FLOW</span>
					</button>

					<div class="fader-group">
						<span class="fader-label">Anxiety</span>
						<input type="range" id="pace-regulator" min="1000" max="6000" step="50">
						<span class="fader-label">Fatigue</span>
					</div>

					<button id="btn-recal" class="secondary-text-btn" title="Reset Rhythm">[ Re-Tap ]</button>
				</div>
			</div>
<?php
			return ob_get_clean();
		} catch (Throwable $throwable) {
			StarmusLogger::log($throwable);
			return ''; // Always return a string even on error
		}
	}

	/**
	 * 3. AJAX Handler
	 * Only updates the specific 'calibrated_pace_ms' field.
	 */
	public function handle_ajax_save(): void
	{
		try {
			// Ensure it's a POST request
			if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
				wp_send_json_error('Invalid request method');
			}
			// 1. Verify Request
			$post_id = (int) $_POST['post_id'];
			$pace    = (int) $_POST['pace_ms'];
			$nonce   = $_POST['nonce'];

			if (! wp_verify_nonce($nonce, 'starmus_prosody_save_' . $post_id)) {
				wp_send_json_error('Security check failed');
			}

			if (! current_user_can('edit_post', $post_id)) {
				wp_send_json_error('Permission denied');
			}

			// 2. Perform Save via DAL
			$success = $this->dal->save_calibrated_pace($post_id, $pace);
		} catch (\Throwable $throwable) {
			wp_send_json_error('An error occurred: ' . $throwable->getMessage());
			StarmusLogger::log($throwable);
		}

		if ($success) {
			wp_send_json_success(['new_pace' => $pace]);
		} else {
			StarmusLogger::log('Starmus update failed for $post_id=' . $post_id);
			wp_send_json_error('Update failed');
		}
	}
}
