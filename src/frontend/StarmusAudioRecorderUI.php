<?php
/**
 * Manages the front-end audio recording submission process and all related metadata.
 * Handles two-step form UX, chunked uploads, CPT creation, metadata saving, and conditional redirects.
 *
 * @package Starmus\frontend
 */

namespace Starmus\frontend;

use Starmus\includes\StarmusSettings;
use WP_Query;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StarmusAudioRecorderUI {

	private static $upload_dir_cache = null;
	private static $settings_cache   = [];

	/**
	 * Constructor. Registers hooks and shortcodes.
	 */
	public function __construct() {
		// Shortcodes
		add_shortcode( 'starmus_my_recordings', [ $this, 'render_my_recordings_shortcode' ] );
		add_shortcode( 'starmus_audio_recorder', [ $this, 'render_recorder_shortcode' ] );

		// Frontend assets
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		// Chunked upload endpoints
		add_action( 'wp_ajax_starmus_handle_upload_chunk', [ $this, 'handle_upload_chunk' ] );
		add_action( 'wp_ajax_nopriv_starmus_handle_upload_chunk', [ $this, 'handle_upload_chunk' ] );

		// Internal extension points
		add_action( 'starmus_after_audio_upload', [ $this, 'save_all_metadata' ], 10, 3 );
		add_filter( 'starmus_audio_upload_success_response', [ $this, 'add_conditional_redirect' ], 10, 3 );
	}

	/**
	 * [starmus_my_recordings]
	 * Renders a paginated list of current user's audio submissions via template.
	 */
	public function render_my_recordings_shortcode( $atts = [] ): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to view your recordings.', 'starmus_audio_recorder' ) . '</p>';
		}

		$attributes = shortcode_atts( [ 'posts_per_page' => 10 ], $atts );
		$paged      = get_query_var( 'paged' ) ? (int) get_query_var( 'paged' ) : 1;

		$query = new WP_Query( [
			'post_type'      => $this->get_setting( 'cpt_slug', 'audio-recording' ),
			'author'         => get_current_user_id(),
			'posts_per_page' => absint( $attributes['posts_per_page'] ),
			'paged'          => $paged,
			'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
		] );

		return $this->render_template(
			'starmus-my-recordings-list.php',
			[
				'query'         => $query,
				'edit_page_url' => $this->get_edit_page_url(),
			]
		);
	}

	/**
	 * [starmus_audio_recorder]
	 * Renders the recorder form, fetching taxonomies for dynamic dropdowns.
	 */
	public function render_recorder_shortcode( $atts = [] ): string {
		$attributes = shortcode_atts( [ 'form_id' => 'starmusAudioForm' ], $atts );

		$recording_types = get_terms( [ 'taxonomy' => 'recording_type', 'hide_empty' => false ] );
		$languages       = get_terms( [ 'taxonomy' => 'language', 'hide_empty' => false ] );

		return $this->render_template(
			'starmus-audio-recorder-ui.php',
			[
				'form_id'         => esc_attr( $attributes['form_id'] ),
				'consent_message' => $this->get_setting( 'consent_message' ),
				'data_policy_url' => $this->get_setting( 'data_policy_url' ),
				'recording_types' => $recording_types,
				'languages'       => $languages,
			]
		);
	}

	/**
	 * Enqueues scripts and styles when either shortcode is present on the page.
	 */
	public function enqueue_scripts(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post    = get_queried_object();
		$content = isset( $post->post_content ) ? (string) $post->post_content : '';

		$has_recorder = ( function_exists( 'has_shortcode' ) && ( has_shortcode( $content, 'starmus_audio_recorder' ) || has_shortcode( $content, 'starmus_audio_recorder ' ) ) )
			|| str_contains( $content, '[starmus_audio_recorder' );

		$has_list = ( function_exists( 'has_shortcode' ) && ( has_shortcode( $content, 'starmus_my_recordings' ) || has_shortcode( $content, 'starmus_my_recordings ' ) ) )
			|| str_contains( $content, '[starmus_my_recordings' );

		if ( ! $has_recorder && ! $has_list ) {
			return;
		}

		if ( $has_recorder ) {
			wp_enqueue_script(
				'starmus-recorder',
				STARMUS_URL . 'assets/js/starmus-audio-recorder-module.min.js',
				[],
				STARMUS_VERSION,
				true
			);

			wp_enqueue_script(
				'starmus-submissions',
				STARMUS_URL . 'assets/js/starmus-audio-recorder-submissions.min.js',
				[ 'starmus-recorder' ],
				STARMUS_VERSION,
				true
			);

			wp_localize_script(
				'starmus-submissions',
				'starmusFormData',
				[
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'action'   => 'starmus_handle_upload_chunk',
					'nonce'    => wp_create_nonce( 'starmus_chunk_upload' ),
				]
			);
		}

		wp_enqueue_style(
			'starmus-style',
			STARMUS_URL . 'assets/css/starmus-audio-recorder-style.min.css',
			[],
			STARMUS_VERSION
		);
	}

	/**
	 * Handles incoming audio chunks, reassembles them, and finalizes the submission.
	 */
	public function handle_upload_chunk(): void {
		check_ajax_referer( 'starmus_chunk_upload', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Login required' ], 401 );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
		}

		$data = $this->validate_chunk_data();
		if ( ! $data ) {
			return;
		}

		$temp_file_path = $this->write_chunk( $data['uuid'], $data['file_chunk'] );
		if ( ! $temp_file_path ) {
			return;
		}

		if ( 0 === $data['offset'] ) {
			// Double-draft protection: if a post for this UUID already exists, don't create another.
			if ( ! $this->find_post_by_uuid( $data['uuid'] ) ) {
				$this->create_draft_post( $data['uuid'], $data['total_size'], $data['file_name'], $_POST );
			}
		}

		// Final chunk?
		if ( ( $data['offset'] + (int) $data['file_chunk']['size'] ) >= $data['total_size'] ) {
			$this->finalize_submission( $data['uuid'], $data['file_name'], $temp_file_path, $_POST );
			return;
		}

		wp_send_json_success( [ 'message' => 'Chunk received' ] );
	}

	private function validate_chunk_data(): ?array {
		$uuid       = sanitize_key( $_POST['audio_uuid'] ?? '' );
		$offset     = absint( $_POST['chunk_offset'] ?? 0 );
		$total_size = absint( $_POST['total_size'] ?? 0 );
		$file_chunk = $_FILES['audio_file'] ?? null;
		$file_name  = sanitize_file_name( $_POST['fileName'] ?? 'audio.webm' );

		if ( ! $uuid || ! $file_chunk || UPLOAD_ERR_OK !== ( $file_chunk['error'] ?? 0 ) || ! $total_size ) {
			wp_send_json_error( [ 'message' => 'Invalid request data' ], 400 );
			return null;
		}

		return compact( 'uuid', 'offset', 'total_size', 'file_chunk', 'file_name' );
	}

	private function write_chunk( string $uuid, array $file_chunk ): ?string {
		$upload_dir = $this->get_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/starmus-temp';

		if ( ! wp_mkdir_p( $temp_dir ) ) {
			wp_send_json_error( [ 'message' => 'Cannot create temp directory' ], 500 );
			return null;
		}

		$temp_file_path = $temp_dir . '/' . $uuid . '.part';
		$bytes          = @file_put_contents( $temp_file_path, @file_get_contents( $file_chunk['tmp_name'] ), FILE_APPEND | LOCK_EX );

		if ( false === $bytes ) {
			wp_send_json_error( [ 'message' => 'Write failed' ], 500 );
			return null;
		}

		return $temp_file_path;
	}

	private function create_draft_post( string $uuid, int $total_size, string $file_name, array $form_data ): void {
		$meta_input = [
			'audio_uuid'        => $uuid,
			'upload_total_size' => $total_size,
		];

		if ( $this->get_setting( 'collect_ip_ua' ) && ! empty( $form_data['audio_consent'] ) ) {
			$meta_input['submission_ip']         = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
			$meta_input['submission_user_agent'] = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		}

		wp_insert_post( [
			'post_title'  => sanitize_text_field( $form_data['audio_title'] ?? pathinfo( $file_name, PATHINFO_FILENAME ) ),
			'post_type'   => $this->get_setting( 'cpt_slug', 'audio-recording' ),
			'post_status' => 'draft',
			'post_author' => get_current_user_id(),
			'meta_input'  => $meta_input,
		] );
	}

	/**
	 * Finalizes the submission and triggers metadata saves + response filter.
	 */
	private function finalize_submission( string $uuid, string $file_name, string $temp_file_path, array $form_data ): void {
		$post = $this->find_post_by_uuid( $uuid );
		if ( ! $post ) {
			@unlink( $temp_file_path );
			wp_send_json_error( [ 'message' => 'Submission not found' ], 500 );
		}

		$u = $this->get_upload_dir();
		if ( ! wp_mkdir_p( $u['path'] ) ) {
			@unlink( $temp_file_path );
			wp_send_json_error( [ 'message' => 'Upload directory not writable' ], 500 );
		}

		$final_filename = wp_unique_filename( $u['path'], $file_name );
		$final_filepath = trailingslashit( $u['path'] ) . $final_filename;
		$file_url       = trailingslashit( $u['url'] ) . $final_filename;

		// Move final file into uploads dir
		if ( ! @rename( $temp_file_path, $final_filepath ) ) {
			@unlink( $temp_file_path );
			wp_send_json_error( [ 'message' => 'File move failed' ], 500 );
		}

		// Validate audio MIME
		$ft = wp_check_filetype( $final_filepath );
		if ( empty( $ft['type'] ) || strpos( $ft['type'], 'audio/' ) !== 0 ) {
			@unlink( $final_filepath );
			wp_send_json_error( [ 'message' => 'Invalid audio type' ], 400 );
		}

		// Insert attachment
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = wp_insert_attachment( [
			'guid'           => $file_url,
			'post_mime_type' => $ft['type'],
			'post_title'     => pathinfo( $final_filename, PATHINFO_FILENAME ),
			'post_status'    => 'inherit',
		], $final_filepath, $post->ID );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $final_filepath );
			wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ], 500 );
		}

		// Generate (noop for audio) but safe to call
		wp_update_attachment_metadata( (int) $attachment_id, wp_generate_attachment_metadata( (int) $attachment_id, $final_filepath ) );

		// Optional waveform JSON (best-effort)
		$waveform_generated = $this->generate_waveform_data( (int) $attachment_id );

		// Publish post + link attachment
		wp_update_post( [ 'ID' => $post->ID, 'post_status' => 'publish' ] );
		update_post_meta( $post->ID, '_audio_attachment_id', (int) $attachment_id );

		/**
		 * ACTION HOOK: post-ID, attachment-ID, and original form data.
		 */
		do_action( 'starmus_after_audio_upload', (int) $post->ID, (int) $attachment_id, $form_data );

		/**
		 * FILTER HOOK: allow adding redirect URL or extra payload.
		 */
		$response_data = apply_filters(
			'starmus_audio_upload_success_response',
			[
				'message'            => esc_html__( 'Submission complete!', 'starmus_audio_recorder' ),
				'post_id'            => (int) $post->ID,
				'waveform_generated' => (bool) $waveform_generated,
			],
			(int) $post->ID,
			$form_data
		);

		wp_send_json_success( $response_data );
	}

	/**
	 * Save all ACF metadata and assign taxonomies.
	 * Hook: starmus_after_audio_upload
	 */
	public function save_all_metadata( int $audio_post_id, int $attachment_id, array $form_data ): void {
		if ( ! function_exists( 'update_field' ) ) {
			return;
		}

		$consent_post_id = $this->create_consent_post( $audio_post_id, $form_data );
		$this->update_audio_recording_metadata( $audio_post_id, $attachment_id, $consent_post_id, $form_data );
		$this->assign_audio_recording_taxonomies( $audio_post_id, $form_data );
	}

	/**
	 * Create a 'consent-agreement' post, return ID or null.
	 */
	private function create_consent_post( int $audio_post_id, array $form_data ): ?int {
		$user_id   = get_current_user_id();
		$user_info = get_userdata( $user_id );

		$consent_fields = [
			'field_68aa07e16725b' => 'Recorded Submission',                               // Terms Type
			'field_689a64f8ab306' => true,                                                // Agreed to Contributor Terms
			'field_689a6646ab30c' => $user_id,                                            // Contributor ID
			'field_689a6480ab303' => $user_info ? $user_info->display_name : 'Guest',     // Contributor Name
			'field_689a64dfab305' => current_time( 'Y-m-d H:i:s' ),                       // Agreement Datetime
			'field_689a6524ab307' => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),// Contributor IP
			'field_689a6563ab308' => wp_generate_uuid4(),                                 // Submission ID
			'field_689a6572ab309' => sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ), // UA
			'field_689a6588ab30a' => get_permalink( $audio_post_id ),                     // URL
		];

		$consent_post_id = wp_insert_post( [
			'post_type'   => 'consent-agreement',
			'post_status' => 'publish',
			'post_title'  => 'Consent for Audio Recording #' . $audio_post_id,
		] );

		if ( $consent_post_id && ! is_wp_error( $consent_post_id ) ) {
			foreach ( $consent_fields as $field_key => $value ) {
				update_field( $field_key, $value, $consent_post_id );
			}
			return (int) $consent_post_id;
		}
		return null;
	}

	/**
	 * Update 'audio-recording' post with session metadata.
	 */
	private function update_audio_recording_metadata( int $audio_post_id, int $attachment_id, ?int $consent_post_id, array $form_data ): void {
		$session_fields = [
			'field_682cbb1a12a3e' => current_time( 'Y-m-d' ),      // Session Date
			'field_682cbb6d12a3f' => current_time( 'H:i:s' ),      // Session Start Time
			'field_682cbf3112a46' => (int) $attachment_id,         // Audio Files (Originals)
			'field_68afe852920cd' => $consent_post_id,             // Related Consent Agreement
		];

		if ( ! empty( $form_data['gps_latitude'] ) && ! empty( $form_data['gps_longitude'] ) ) {
			$session_fields['field_682cbc5312a42'] = [             // GPS Coordinates
				'lat'     => (float) $form_data['gps_latitude'],
				'lng'     => (float) $form_data['gps_longitude'],
				'address' => 'Recorded Location',
			];
		}

		foreach ( $session_fields as $field_key => $value ) {
			update_field( $field_key, $value, $audio_post_id );
		}
	}

	/**
	 * Assign taxonomy terms to the 'audio-recording' post.
	 */
	private function assign_audio_recording_taxonomies( int $audio_post_id, array $form_data ): void {
		// Language
		if ( ! empty( $form_data['language'] ) ) {
			$lng = sanitize_text_field( (string) $form_data['language'] );
			$term = get_term_by( 'slug', sanitize_key( $lng ), 'language' )
				?: get_term_by( 'name', $lng, 'language' );
			if ( $term && ! is_wp_error( $term ) ) {
				wp_set_post_terms( $audio_post_id, [ (int) $term->term_id ], 'language', false );
			}
		}

		// Recording Type
		if ( ! empty( $form_data['recording_type'] ) ) {
			$rt = sanitize_text_field( (string) $form_data['recording_type'] );
			$term = get_term_by( 'slug', sanitize_key( $rt ), 'recording_type' )
				?: get_term_by( 'name', $rt, 'recording_type' );
			if ( $term && ! is_wp_error( $term ) ) {
				wp_set_post_terms( $audio_post_id, [ (int) $term->term_id ], 'recording_type', false );
			}
		}
	}

	/**
	 * Add conditional redirect URL to the final AJAX response.
	 * Hook: starmus_audio_upload_success_response
	 */
	public function add_conditional_redirect( array $response, int $post_id, array $form_data ): array {
		if ( isset( $form_data['recording_type'] ) ) {
			$recording_type = sanitize_key( (string) $form_data['recording_type'] );
			$base_url       = '';

			if ( 'new-word' === $recording_type ) {
				$base_url = home_url( '/add-details-new-word/' );
			} elseif ( 'oral-history' === $recording_type ) {
				$base_url = home_url( '/add-details-oral-history/' );
			} else {
				$base_url = home_url( '/add-details-general/' );
			}

			if ( ! empty( $base_url ) ) {
				$response['redirect_url'] = add_query_arg( 'recording_id', (int) $post_id, $base_url );
			}
		}
		return $response;
	}

	/**
	 * Generate waveform JSON using the audiowaveform binary (best-effort).
	 */
	private function generate_waveform_data( int $attachment_id ): bool {
		$audio_filepath = get_attached_file( $attachment_id );
		if ( ! $audio_filepath || ! file_exists( $audio_filepath ) ) {
			error_log( 'Starmus Waveform Error: Could not find audio file for attachment ID ' . $attachment_id );
			return false;
		}

		$bin = trim( (string) @shell_exec( 'command -v audiowaveform' ) );
		if ( '' === $bin ) {
			error_log( 'Starmus Waveform Error: audiowaveform not found on PATH' );
			return false;
		}

		$waveform_filepath = preg_replace( '/\.[^.]+$/', '', $audio_filepath ) . '.json';
		$cmd               = sprintf(
			'%s -i %s -o %s -b 8 --pixels-per-second 100 --waveform-color FFFFFF',
			escapeshellcmd( $bin ),
			escapeshellarg( $audio_filepath ),
			escapeshellarg( $waveform_filepath )
		);

		@shell_exec( $cmd );

		if ( ! file_exists( $waveform_filepath ) ) {
			error_log( 'Starmus Waveform Error: Generation failed. Command: ' . $cmd );
			return false;
		}

		update_post_meta( $attachment_id, '_waveform_json_path', $waveform_filepath );
		return true;
	}

	/**
	 * Render a template with theme override support: `wp-content/themes/<theme>/starmus/<template>.php`
	 */
	private function render_template( string $template_name, array $args = [] ): string {
		// Allow theme override
		$theme_template = function_exists( 'locate_template' )
			? locate_template( [ 'starmus/' . $template_name ] )
			: '';

		$template_path = $theme_template ?: ( STARMUS_PATH . 'src/templates/' . $template_name );

		if ( ! file_exists( $template_path ) ) {
			error_log( 'Starmus Template Error: Missing template file - ' . $template_path );
			return '<p>' . esc_html__( 'Error: A required template file is missing.', 'starmus_audio_recorder' ) . '</p>';
		}

		if ( ! empty( $args ) ) {
			extract( $args, EXTR_SKIP );
		}

		ob_start();
		include $template_path;
		return (string) ob_get_clean();
	}

	private function get_edit_page_url(): string {
		$edit_page_id = $this->get_setting( 'edit_page_id' );
		return ( $edit_page_id && ( $url = get_permalink( $edit_page_id ) ) ) ? $url : home_url( '/edit-audio/' );
	}

	private function get_upload_dir(): array {
		if ( null === self::$upload_dir_cache ) {
			self::$upload_dir_cache = wp_get_upload_dir();
		}
		return self::$upload_dir_cache;
	}

	private function get_setting( string $key, $default = null ) {
		if ( ! array_key_exists( $key, self::$settings_cache ) ) {
			self::$settings_cache[ $key ] = StarmusSettings::get( $key, $default );
		}
		return self::$settings_cache[ $key ];
	}

	/**
	 * Find an audio post by its UUID meta.
	 */
	private function find_post_by_uuid( string $uuid ): ?\WP_Post {
		global $wpdb;

		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = 'audio_uuid'
				   AND pm.meta_value = %s
				   AND p.post_status IN ('draft','pending','publish','future','private')
				 ORDER BY p.ID DESC
				 LIMIT 1",
				$uuid
			)
		);

		return $post_id ? get_post( (int) $post_id ) : null;
	}
}
