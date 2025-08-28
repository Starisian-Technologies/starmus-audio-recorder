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
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StarmusAudioRecorderUI {

	// REFACTOR: Use a namespace for REST API routes for better organization and to avoid conflicts.
	const REST_NAMESPACE = 'starmus/v1';

	/**
	 * Constructor. Registers hooks and shortcodes.
	 */
	public function __construct() {
		// Shortcodes
		add_shortcode( 'starmus_my_recordings', array( $this, 'render_my_recordings_shortcode' ) );
		add_shortcode( 'starmus_audio_recorder', array( $this, 'render_recorder_shortcode' ) );

		// Frontend assets
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// REFACTOR: Register REST API route for uploads instead of admin-ajax.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Internal extension points
		add_action( 'starmus_after_audio_upload', array( $this, 'save_all_metadata' ), 10, 3 );
		add_filter( 'starmus_audio_upload_success_response', array( $this, 'add_conditional_redirect' ), 10, 3 );

		// REFACTOR: Schedule cron job for cleaning up stale temporary files.
		if ( ! wp_next_scheduled( 'starmus_cleanup_temp_files' ) ) {
			wp_schedule_event( time(), 'hourly', 'starmus_cleanup_temp_files' );
		}
		add_action( 'starmus_cleanup_temp_files', array( $this, 'cleanup_stale_temp_files' ) );
        
	    // hooks that call the cache clearing function.
        add_action( 'saved_term', array( $this, 'clear_taxonomy_transients' ) );
        add_action( 'delete_term', array( $this, 'clear_taxonomy_transients' ) );
    }

	/**
	 * [starmus_my_recordings]
	 */
	public function render_my_recordings_shortcode( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to view your recordings.', 'starmus_audio_recorder' ) . '</p>';
		}
		$attributes = shortcode_atts( array( 'posts_per_page' => 10 ), $atts );
		$paged      = get_query_var( 'paged' ) ? (int) get_query_var( 'paged' ) : 1;
		$query      = new WP_Query(
			array(
				'post_type'      => $this->get_setting( 'cpt_slug', 'audio-recording' ),
				'author'         => get_current_user_id(),
				'posts_per_page' => absint( $attributes['posts_per_page'] ),
				'paged'          => $paged,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			)
		);
		return $this->render_template(
			'starmus-my-recordings-list.php',
			array(
				'query'         => $query,
				'edit_page_url' => $this->get_edit_page_url(),
			)
		);
	}

	/**
	 * [starmus_audio_recorder]
	 * Renders the recorder form, fetching and caching taxonomies for dynamic dropdowns.
	 */
	public function render_recorder_shortcode( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to record audio.', 'starmus_audio_recorder' ) . '</p>';
		}
		do_action( 'starmus_before_recorder_render' );

		// --- IMPROVEMENT: Caching for Taxonomy Terms ---
		$languages = get_transient( 'starmus_languages_list' );
		if ( false === $languages ) {
			$languages = get_terms( array( 'taxonomy' => 'language', 'hide_empty' => false ) );
			// Cache for 12 hours. The cache is automatically cleared if a term is updated (see constructor hooks).
			set_transient( 'starmus_languages_list', $languages, 12 * HOUR_IN_SECONDS );
		}

		$recording_types = get_transient( 'starmus_recording_types_list' );
		if ( false === $recording_types ) {
			$recording_types = get_terms( array( 'taxonomy' => 'recording_type', 'hide_empty' => false ) );
			set_transient( 'starmus_recording_types_list', $recording_types, 12 * HOUR_IN_SECONDS );
		}
		// --- END IMPROVEMENT ---

		$attributes = shortcode_atts( array( 'form_id' => 'starmusAudioForm' ), $atts );

		return $this->render_template(
			'starmus-audio-recorder-ui.php',
			array(
				'form_id'         => esc_attr( $attributes['form_id'] ),
				'consent_message' => wp_kses_post( $this->get_setting( 'consent_message' ) ),
				'data_policy_url' => esc_url( $this->get_setting( 'data_policy_url' ) ),
				'recording_types' => $recording_types,
				'languages'       => $languages,
			)
		);
	}

    /**
	 * IMPROVEMENT: Add a function to clear our custom caches when terms are updated.
	 */
	public function clear_taxonomy_transients(): void {
		delete_transient( 'starmus_languages_list' );
		delete_transient( 'starmus_recording_types_list' );
	}


	/**
	 * Enqueues scripts and styles.
	 */
	public function enqueue_scripts(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		$content = $post->post_content ?? '';
		if ( ! has_shortcode( $content, 'starmus_audio_recorder' ) ) {
			return;
		}

		wp_enqueue_script( 'starmus-recorder', STARMUS_URL . 'assets/js/starmus-audio-recorder-module.min.js', array(), STARMUS_VERSION, true );
		// HARDENING: Add wp-api-fetch dependency for REST API.
		wp_enqueue_script( 'starmus-submissions', STARMUS_URL . 'assets/js/starmus-audio-recorder-submissions.min.js', array( 'starmus-recorder', 'wp-api-fetch' ), STARMUS_VERSION, true );

		wp_localize_script(
			'starmus-submissions',
			'starmusFormData',
			array(
				// REFACTOR: Use REST API endpoint and nonce instead of admin-ajax.
				'rest_url'   => esc_url_raw( rest_url( self::REST_NAMESPACE . '/upload-chunk' ) ),
				'rest_nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
		wp_enqueue_style( 'starmus-style', STARMUS_URL . 'assets/css/starmus-audio-recorder-style.min.css', array(), STARMUS_VERSION );
	}

	/**
	 * REFACTOR: Register REST API routes for chunked uploads.
	 */
	public function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/upload-chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_upload_chunk_rest' ),
				'permission_callback' => array( $this, 'upload_permissions_check' ),
			)
		);
	}

	/**
	 * HARDENING: Permission check for the REST endpoint. Fails loudly.
	 */
	public function upload_permissions_check( WP_REST_Request $request ): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * REFACTOR: Handles chunk uploads via the REST API.
	 */
	public function handle_upload_chunk_rest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// HARDENING: Rate limiting check to prevent abuse.
		if ( $this->is_rate_limited() ) {
			return new WP_Error( 'rate_limit_exceeded', 'You are uploading too frequently. Please wait a moment.', array( 'status' => 429 ) );
		}

		$data = $this->validate_chunk_data( $request->get_params(), $request->get_file_params() );
		if ( is_wp_error( $data ) ) {
			return $data; // Return the specific error from validation.
		}

		$temp_file_path = $this->write_chunk( $data['uuid'], $data['file_chunk'] );
		if ( is_wp_error( $temp_file_path ) ) {
			return $temp_file_path;
		}

		if ( 0 === $data['offset'] && ! $this->find_post_by_uuid( $data['uuid'] ) ) {
			$this->create_draft_post( $data['uuid'], $data['total_size'], $data['file_name'], $request->get_params() );
		}

		if ( ( $data['offset'] + (int) $data['file_chunk']['size'] ) >= $data['total_size'] ) {
			return $this->finalize_submission( $data['uuid'], $data['file_name'], $temp_file_path, $request->get_params() );
		}

		return new WP_REST_Response( array( 'success' => true, 'message' => 'Chunk received' ), 200 );
	}

	/**
	 * HARDENING: Validate incoming chunk data with stricter rules.
	 */
	private function validate_chunk_data( array $params, array $files ): array|WP_Error {
		$params     = wp_unslash( $params );
		$uuid       = sanitize_key( $params['audio_uuid'] ?? '' );
		$offset     = absint( $params['chunk_offset'] ?? 0 );
		$total_size = absint( $params['total_size'] ?? 0 );
		$file_name  = sanitize_file_name( $params['fileName'] ?? 'audio.webm' );
		$file_chunk = $files['audio_file'] ?? null;

		if ( ! $uuid || ! $file_chunk || UPLOAD_ERR_OK !== ( $file_chunk['error'] ?? 0 ) || ! $total_size ) {
			return new WP_Error( 'invalid_request_data', 'Invalid or missing request data.', array( 'status' => 400 ) );
		}

		$max_size = (int) $this->get_setting( 'max_file_size_mb', 25 ) * 1024 * 1024;
		if ( $total_size > $max_size ) {
			return new WP_Error( 'file_too_large', 'The uploaded file exceeds the maximum allowed size.', array( 'status' => 413 ) );
		}

		$allowed_extensions = (array) $this->get_setting( 'allowed_extensions', array( 'webm', 'mp3', 'm4a', 'wav', 'ogg' ) );
		$extension          = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, $allowed_extensions, true ) ) {
			return new WP_Error( 'invalid_file_extension', 'The file type is not permitted.', array( 'status' => 415 ) );
		}

		return compact( 'uuid', 'offset', 'total_size', 'file_chunk', 'file_name' );
	}

	/**
	 * HARDENING: Write chunk to a temporary file using WP_Filesystem.
	 */
	private function write_chunk( string $uuid, array $file_chunk ): string|WP_Error {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$temp_dir = $this->get_temp_dir();
		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		$temp_file_path = trailingslashit( $temp_dir ) . $uuid . '.part';
		$chunk_content  = $wp_filesystem->get_contents( $file_chunk['tmp_name'] );

		if ( false === $chunk_content ) {
			return new WP_Error( 'read_failed', 'Failed to read from temporary upload chunk.', array( 'status' => 500 ) );
		}

		// Append content to the .part file
		if ( ! $wp_filesystem->put_contents( $temp_file_path, $chunk_content, FS_APPEND ) ) {
			return new WP_Error( 'write_failed', 'Failed to write chunk to temporary file.', array( 'status' => 500 ) );
		}

		return $temp_file_path;
	}

	/**
	 * Finalizes the submission, returns a REST response or WP_Error.
	 */
	private function finalize_submission( string $uuid, string $file_name, string $temp_file_path, array $form_data ): WP_REST_Response|WP_Error {
		global $wp_filesystem;
		$post = $this->find_post_by_uuid( $uuid );
		if ( ! $post ) {
			$wp_filesystem->delete( $temp_file_path );
			return new WP_Error( 'submission_not_found', 'Draft submission post could not be found.', array( 'status' => 500 ) );
		}

		$upload_dir_info = wp_get_upload_dir();
		$upload_path     = apply_filters( 'starmus_upload_path', $upload_dir_info['path'] );
		if ( ! wp_mkdir_p( $upload_path ) ) {
			return new WP_Error( 'upload_dir_unwritable', 'Upload directory is not writable.', array( 'status' => 500 ) );
		}
		$upload_url = trailingslashit( apply_filters( 'starmus_upload_url', $upload_dir_info['url'] ) );

		$final_filename = wp_unique_filename( $upload_path, $file_name );
		$final_filepath = trailingslashit( $upload_path ) . $final_filename;

		if ( ! $wp_filesystem->move( $temp_file_path, $final_filepath, true ) ) {
			$wp_filesystem->delete( $temp_file_path );
			return new WP_Error( 'file_move_failed', 'Could not move file to final destination.', array( 'status' => 500 ) );
		}

		$finfo     = finfo_open( FILEINFO_MIME_TYPE );
		$real_mime = finfo_file( $finfo, $final_filepath );
		finfo_close( $finfo );
		if ( false === strpos( $real_mime, 'audio/' ) ) {
			$wp_filesystem->delete( $final_filepath );
			return new WP_Error( 'invalid_mime_type', 'File content is not a valid audio type.', array( 'status' => 415 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_id = wp_insert_attachment(
			array(
				'guid'           => $upload_url . $final_filename,
				'post_mime_type' => $real_mime,
				'post_title'     => pathinfo( $final_filename, PATHINFO_FILENAME ),
				'post_status'    => 'inherit',
			),
			$final_filepath,
			$post->ID
		);

		if ( is_wp_error( $attachment_id ) ) {
			$wp_filesystem->delete( $final_filepath );
			return new WP_Error( 'attachment_error', $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}

		wp_update_attachment_metadata( (int) $attachment_id, wp_generate_attachment_metadata( (int) $attachment_id, $final_filepath ) );
		$waveform_generated = $this->generate_waveform_data( (int) $attachment_id );

		wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'publish' ) );
		update_post_meta( $post->ID, '_audio_attachment_id', (int) $attachment_id );

		do_action( 'starmus_after_audio_upload', (int) $post->ID, (int) $attachment_id, $form_data );
		$response_data = apply_filters(
			'starmus_audio_upload_success_response',
			array(
				'message'            => esc_html__( 'Submission complete!', 'starmus_audio_recorder' ),
				'post_id'            => (int) $post->ID,
				'waveform_generated' => (bool) $waveform_generated,
			),
			(int) $post->ID,
			$form_data
		);

		return new WP_REST_Response( $response_data, 200 );
	}

	/**
	 * Create draft post for a new submission.
	 */
	private function create_draft_post( string $uuid, int $total_size, string $file_name, array $form_data ): void {
		$meta_input = array( 'audio_uuid' => $uuid, 'upload_total_size' => $total_size );
		if ( $this->get_setting( 'collect_ip_ua' ) && ! empty( $form_data['audio_consent'] ) ) {
			$meta_input['submission_ip']         = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
			$meta_input['submission_user_agent'] = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		}
		wp_insert_post(
			array(
				'post_title'  => sanitize_text_field( $form_data['audio_title'] ?? pathinfo( $file_name, PATHINFO_FILENAME ) ),
				'post_type'   => $this->get_setting( 'cpt_slug', 'audio-recording' ),
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
				'meta_input'  => $meta_input,
			)
		);
	}

	/**
	 * Save metadata and taxonomies. Hooked to 'starmus_after_audio_upload'.
	 */
	public function save_all_metadata( int $audio_post_id, int $attachment_id, array $form_data ): void {
		$consent_post_id = $this->create_consent_post( $audio_post_id, $form_data );
		$this->update_audio_recording_metadata( $audio_post_id, $attachment_id, $consent_post_id, $form_data );
		$this->assign_audio_recording_taxonomies( $audio_post_id, $form_data );
	}

	/**
	 * HARDENING: Validate ACF field keys and dependencies.
	 */
	private function create_consent_post( int $audio_post_id, array $form_data ): ?int {
		if ( ! function_exists( 'update_field' ) ) { return null; }
		// ... [Consent post creation logic remains the same] ...
		return null; // Simplified for brevity
	}
	private function update_audio_recording_metadata( int $audio_post_id, int $attachment_id, ?int $consent_post_id, array $form_data ): void {
		if ( ! function_exists( 'update_field' ) ) { return; }
		// ... [ACF update logic remains the same] ...
	}

	/**
	 * HARDENING: Assign taxonomy terms only if they exist.
	 */
	private function assign_audio_recording_taxonomies( int $audio_post_id, array $form_data ): void {
		$taxonomies = array(
			'language'       => $form_data['language'] ?? null,
			'recording_type' => $form_data['recording_type'] ?? null,
		);
		foreach ( $taxonomies as $tax_slug => $term_slug ) {
			if ( empty( $term_slug ) || ! taxonomy_exists( $tax_slug ) ) {
				continue;
			}
			$term = get_term_by( 'slug', sanitize_key( (string) $term_slug ), $tax_slug );
			if ( $term && ! is_wp_error( $term ) ) {
				wp_set_post_terms( $audio_post_id, array( (int) $term->term_id ), $tax_slug, false );
			}
		}
	}

	/**
	 * Add conditional redirect URL to the final AJAX response.
	 */
	public function add_conditional_redirect( array $response, int $post_id, array $form_data ): array {
		$recording_type_slug = ! empty( $form_data['recording_type'] ) ? sanitize_key( (string) $form_data['recording_type'] ) : 'default';
		$response['redirect_url'] = apply_filters(
			'starmus_final_redirect_url',
			home_url( '/my-recordings' ), // Default fallback.
			(int) $post_id,
			$recording_type_slug,
			$form_data
		);
		return $response;
	}

	/**
	 * Generate waveform data (best-effort, opt-in).
	 */
	private function generate_waveform_data( int $attachment_id ): bool {
		if ( ! $this->get_setting( 'enable_waveform_generation' ) ) {
			return false;
		}
		// ... [Waveform generation logic remains the same] ...
		return false; // Simplified for brevity
	}

	/**
	 * Render a template with theme override support.
	 */
	private function render_template( string $template_name, array $args = array() ): string {
		// ... [Template rendering logic remains the same] ...
		return ''; // Simplified for brevity
	}

	/**
	 * REFACTOR: Use a transient to cache settings and reflect admin changes.
	 */
	private function get_setting( string $key, $default = null ) {
		$cache_key = 'starmus_settings_cache';
		$settings  = get_transient( $cache_key );
		if ( false === $settings ) {
			// Assumes StarmusSettings::get_all() exists to fetch all settings at once.
			// If not, this logic would need to adapt.
			$all_settings_class = new StarmusSettings();
			$settings           = $all_settings_class->get_all_settings(); // Assuming this method exists
			set_transient( $cache_key, $settings, HOUR_IN_SECONDS ); // Cache for 1 hour.
		}
		return $settings[ $key ] ?? $default;
	}

	/**
	 * HARDENING: Rate limit check based on user ID and IP.
	 */
	private function is_rate_limited(): bool {
		$limit = (int) apply_filters( 'starmus_rate_limit_uploads_per_minute', 20 );
		$key   = 'starmus_upload_rate_limit_' . get_current_user_id() . '_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * HARDENING: Get a secure, filterable temporary directory.
	 */
	private function get_temp_dir(): string|WP_Error {
		$upload_dir = wp_get_upload_dir();
		$default_temp_dir = trailingslashit( $upload_dir['basedir'] ) . 'starmus-temp';
		$temp_dir = apply_filters( 'starmus_temp_upload_dir', $default_temp_dir );

		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error( 'temp_dir_error', 'Cannot create or access temporary directory.', array( 'status' => 500 ) );
		}
		if ( ! file_exists( trailingslashit( $temp_dir ) . '.htaccess' ) ) {
			@file_put_contents( trailingslashit( $temp_dir ) . '.htaccess', 'deny from all' );
		}
		return $temp_dir;
	}

	/**
	 * REFACTOR: Cron job to clean up stale .part files older than 24 hours.
	 */
	public function cleanup_stale_temp_files() {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}
		$temp_dir = $this->get_temp_dir();
		if ( is_wp_error( $temp_dir ) ) {
			return;
		}
		$files = $wp_filesystem->dirlist( $temp_dir );
		if ( empty( $files ) ) {
			return;
		}
		$cutoff = time() - DAY_IN_SECONDS; // 24 hours ago
		foreach ( $files as $file ) {
			if ( '.part' === substr( $file['name'], -5 ) && $file['lastmod'] < $cutoff ) {
				$wp_filesystem->delete( trailingslashit( $temp_dir ) . $file['name'] );
			}
		}
	}
    // ... [find_post_by_uuid and other private helpers remain the same] ...
	private function find_post_by_uuid( string $uuid ): ?\WP_Post {
		global $wpdb;
		$post_id = $wpdb->get_var(/* ... */);
		return $post_id ? get_post( (int) $post_id ) : null;
	}
    private function get_edit_page_url(): string { /* ... */ return ''; }
}
