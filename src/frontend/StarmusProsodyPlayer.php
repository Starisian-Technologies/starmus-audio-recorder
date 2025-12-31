<?php
namespace Starisian\Sparxstar\Starmus\frontend;

use Starisian\Sparxstar\Starmus\data\StarmusProsodyDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

if ( ! \defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class StarmusProsodyPlayer
 *
 * Handles the Shortcode [prosody_reader], Assets, and AJAX Listener.
 *
 * @package Starisian\Sparxstar\Starmus\frontend
 */
class StarmusProsodyPlayer {

	private StarmusProsodyDAL $dal;

	public function __construct() {		
		$this->register_hooks();
	}

	/**
	 * Summary of register_hooks
	 *
	 * @return void
	 */
	private function register_hooks() {

		// Hooks
		add_action( 'init', array( $this, 'register_shortcodes' ) );
		add_action( 'init', array( $this, 'initDAL' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// AJAX Endpoints (Authenticated & Public if needed, usually Auth only for this)
		add_action( 'wp_ajax_starmus_save_pace', array( $this, 'handle_ajax_save' ) );
	}

	public function register_shortcodes(): void {
		// Register the shortcode
		add_shortcode( 'prosody_reader', array( $this, 'render_shortcode' ) );
	}

	private function initDAL(): void {
		if(class_exists( StarmusProsodyDAL::class ) === false ) {
			throw new \Exception( 'StarmusProsodyDAL class not found' );
		}
		if($this->dal === null) {
			$this->dal = new StarmusProsodyDAL;
		}
	}

	/**
	 * 1. Register JS/CSS
	 *
	 * @return void
	 */
	public function register_assets(): void {
		// Enqueue the CSS (assuming you saved the CSS from previous chat to a file)
		wp_register_style(
			'starmus-prosody-css',
			plugin_dir_url( __FILE__ ) . 'src/css/starmus-prosody-enginge.css',
			array(),
			STARMUS_VERSION
		);

		// Enqueue the JS (assuming you saved the JS Class to a file)
		wp_register_script(
			'starmus-prosody-js',
			plugin_dir_url( __FILE__ ) . 'src/js/prosody/starmus-prosody-engine.js',
			array(),
			STARMUS_VERSION,
			array( 'strategy' => 'defer' ) // WP 6.3+ feature
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
	public function render_shortcode( array $atts ): string {
		$args = shortcode_atts(
			array(
				'id' => get_the_ID(),
			),
			$atts
		);

		$post_id = (int) $args['id'];
		$data    = $this->dal->get_script_payload( $post_id );

		if ( empty( $data ) ) {
			return '<div class="prosody-error">Error: Script data not found.</div>';
		}

		// Load Assets
		wp_enqueue_style( 'starmus-prosody-css' );
		wp_enqueue_script( 'starmus-prosody-js' );

		// Pass Data to JS via Inline Script (Modern approach)
		wp_add_inline_script(
			'starmus-prosody-js',
			'const StarmusData = ' . json_encode( $data ) . ';',
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
	}

	/**
	 * 3. AJAX Handler
	 * Only updates the specific 'calibrated_pace_ms' field.
	 *
	 * @return void
	 */
	public function handle_ajax_save(): void {
		try {
			// Ensure it's a POST request
			if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
				wp_send_json_error( 'Invalid request method' );
			}
			// 1. Verify Request
			$post_id = (int) $_POST['post_id'];
			$pace    = (int) $_POST['pace_ms'];
			$nonce   = $_POST['nonce'];

			if ( ! wp_verify_nonce( $nonce, 'starmus_prosody_save_' . $post_id ) ) {
				wp_send_json_error( 'Security check failed' );
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( 'Permission denied' );
			}

			// 2. Perform Save via DAL
			$success = $this->dal->save_calibrated_pace( $post_id, $pace );

		} catch ( \Throwable $e ) {
			wp_send_json_error( 'An error occurred: ' . $e->getMessage() );
			StarmusLogger::log( $e );
		}

		if ( $success ) {
			wp_send_json_success( array( 'new_pace' => $pace ) );
		} else {
			wp_send_json_error( 'Update failed' );
		}
	}
}
