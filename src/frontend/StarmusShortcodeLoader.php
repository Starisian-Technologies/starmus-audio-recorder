<?php
namespace Starmus\frontend;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starmus\includes\StarmusSettings;
use Starmus\helpers\StarmusLogger;
use Starmus\helpers\StarmusTemplateLoaderHelper;
/**
 * Handles shortcodes for displaying user recordings and recording details.
 * Delegates rendering to StarmusTemplateLoaderHelper for cleaner code.
 *
 * NOTE - Recorder form and Edit Audio Form are located in their own classes
 *
 * @since 0.7.5
 * @version 0.7.5
 */
class StarmusShortcodeLoader {

	private ?StarmusSettings $settings = null;

	public function __construct( ?StarmusSettings $settings ) {
		$this->settings = $settings;
		// --- 2. THE HOOK REGISTRATION IS NOW PUBLIC ---
		// This should be called from your main plugin file to start the class.
		$this->register_hooks();
	}

	public function register_hooks(): void {

		add_shortcode( 'starmus_my_recordings', array( $this, 'render_my_recordings_shortcode' ) );
		add_shortcode( 'starmus_recording_detail', array( $this, 'render_submission_detail_shortcode' ) );
		// This hooks our new function to 'the_content' filter.
		add_filter( 'the_content', array( $this, 'render_submission_detail_via_filter' ), 100 );
	}

	/**
	 * Render the "My Recordings" shortcode.
	 */
	public function render_my_recordings_shortcode( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to view your recordings.', 'starmus-audio-recorder' ) . '</p>';
		}

		try {
			// Your excellent query logic is preserved.
			$attributes     = shortcode_atts( array( 'posts_per_page' => 10 ), $atts );
			$posts_per_page = max( 1, absint( $attributes['posts_per_page'] ) );
			$paged          = get_query_var( 'paged' ) ? (int) get_query_var( 'paged' ) : 1;
			$cpt_slug       = $this->settings ? $this->settings->get( 'cpt_slug', 'audio-recording' ) : 'audio-recording';

			$query = new \WP_Query(
				array(
					'post_type'      => $cpt_slug,
					'author'         => get_current_user_id(),
					'posts_per_page' => $posts_per_page,
					'paged'          => $paged,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ), // This important detail is kept.
				)
			);

			// --- 3. DELEGATE RENDERING TO THE HELPER ---
			return StarmusTemplateLoaderHelper::render_template(
				'parts/starmus-my-recordings-list.php', // Use the template part
				array(
					'query'         => $query,
					'edit_page_url' => $this->get_edit_page_url_admin(),
				)
			);
		} catch ( \Throwable $e ) {
			StarmusLogger::log( 'UI:render_my_recordings', $e );
			return '<p>' . esc_html__( 'Unable to load recordings.', 'starmus-audio-recorder' ) . '</p>';
		}
	}

	/**
	 * Renders the [starmus_recording_detail] shortcode.
	 * Intelligently loads the correct template (admin or user) based on permissions.
	 */
	public function render_submission_detail_shortcode( $atts ) {
		if ( ! is_singular( 'audio-recording' ) ) {
			return '<p><em>[starmus_recording_detail] can only be used on a single audio recording page.</em></p>';
		}

		$post_id          = get_the_ID();
		$template_to_load = '';

		// Your detailed permission logic is preserved.
		if ( current_user_can( 'edit_others_posts', $post_id ) ) {
			$template_to_load = 'starmus-recording-detail-admin.php';

		} elseif ( is_user_logged_in() && get_current_user_id() == get_post_field( 'post_author', $post_id ) ) {
			$template_to_load = 'starmus-recording-detail-user.php';
		}

		if ( ! empty( $template_to_load ) ) {
			// --- 4. DELEGATE RENDERING TO THE HELPER ---
			return StarmusTemplateLoaderHelper::render_template( $template_to_load );
		}

		// Your detailed permission messages are preserved.
		if ( is_user_logged_in() ) {
			return '<p>You do not have permission to view this recording detail.</p>';
		}
		return '<p><em>You must be logged in to view this recording detail.</em></p>';
	}

	/**
	 * Replaces the content of single 'audio-recording' posts with our custom template.
	 *
	 * This method is hooked to 'the_content' filter and is the definitive way
	 * to display the detail view without needing to manually add a shortcode.
	 *
	 * @param string $content The original post content (which we will ignore).
	 * @return string The HTML from our custom detail templates.
	 */
	public function render_submission_detail_via_filter( string $content ): string {
		// First, check if we are on a single 'audio-recording' post in the main query.
		if ( ! is_singular( 'audio-recording' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content; // If not, return the original content unmodified.
		}

		// --- THE LOGIC TO CHOOSE THE TEMPLATE ---
		$post_id          = get_the_ID();
		$template_to_load = '';

		if ( current_user_can( 'edit_others_posts', $post_id ) ) {
			// User is an admin/editor.
			$template_to_load = 'parts/starmus-recording-detail-admin.php';
		} elseif ( is_user_logged_in() && get_current_user_id() == get_post_field( 'post_author', $post_id ) ) {
			// User is the author.
			$template_to_load = 'parts/starmus-recording-detail-user.php';
		}

		if ( ! empty( $template_to_load ) ) {
			// We found the right template, so render it using our helper.
			// We completely replace the original content.
			return StarmusTemplateLoaderHelper::render_template( $template_to_load );
		}

		// If the user doesn't have permission, show a permission error.
		return '<p>You do not have permission to view this recording detail.</p>';
	}
	/**
	 * Admin edit screen URL.
	 */
	private function get_edit_page_url_admin(): string {
		$cpt = $this->settings ? $this->settings->get( 'cpt_slug', 'audio-recording' ) : 'audio-recording';
		return admin_url( 'edit.php?post_type=' . $cpt );
	}
}
