<?php
/**
 * Starmus Audio Recorder UI
 *
 * This file is responsible for rendering and managing the front-end audio
 * recorder interface, including handling all related scripts and REST API
 * endpoints for saving audio data.
 *
 * @package Starmus\frontend
 * @since 0.1.0
 * @version 0.3.0
 */

// phpcs:ignore WordPress.Files.FileName
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

/**
 * Defines the user interface and submission handling for the audio recorder.
 */
class StarmusAudioRecorderUI {


	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'starmus/v1';

	/**
	 * Constructor. Registers hooks and shortcodes.
	 */
	public function __construct() {
		add_shortcode( 'starmus_my_recordings', array( $this, 'render_my_recordings_shortcode' ) );
		add_shortcode( 'starmus_audio_recorder', array( $this, 'render_recorder_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'starmus_after_audio_upload', array( $this, 'save_all_metadata' ), 10, 3 );
		add_filter( 'starmus_audio_upload_success_response', array( $this, 'add_conditional_redirect' ), 10, 3 );
		add_action( 'init', array( $this, 'maybe_schedule_cron' ) );
		add_action( 'starmus_cleanup_temp_files', array( $this, 'cleanup_stale_temp_files' ) );
		add_action( 'saved_term', array( $this, 'clear_taxonomy_transients' ) );
		add_action( 'delete_term', array( $this, 'clear_taxonomy_transients' ) );
	}

	/**
	 * Renders the [starmus_my_recordings] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string The HTML for the user's recordings list.
	 */
	public function render_my_recordings_shortcode( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to view your recordings.', 'starmus_audio_recorder' ) . '</p>';
		}

		$attributes     = shortcode_atts( array( 'posts_per_page' => 10 ), $atts );
		$posts_per_page = max( 1, absint( $attributes['posts_per_page'] ) );
		$paged          = get_query_var( 'paged' ) ? (int) get_query_var( 'paged' ) : 1;

		$query = new WP_Query(
			array(
				'post_type'              => $this->get_setting( 'cpt_slug', 'audio-recording' ),
				'author'                 => get_current_user_id(),
				'posts_per_page'         => $posts_per_page,
				'paged'                  => $paged,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
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
	 * Clears the taxonomy term transients when a term is created, edited, or deleted.
	 */
	public function clear_taxonomy_transients(): void {
		delete_transient( 'starmus_languages_list' );
		delete_transient( 'starmus_recording_types_list' );
	}

	/**
	 * Renders the [starmus_audio_recorder] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string The HTML for the audio recorder form.
	 */
	public function render_recorder_shortcode( $atts = array() ): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to record audio.', 'starmus_audio_recorder' ) . '</p>';
		}

		do_action( 'starmus_before_recorder_render' );

		$languages = get_transient( 'starmus_languages_list' );
		if ( false === $languages ) {
			$languages = get_terms(
				array(
					'taxonomy'   => 'language',
					'hide_empty' => false,
				)
			);
			set_transient( 'starmus_languages_list', $languages, 12 * HOUR_IN_SECONDS );
		}

		$recording_types = get_transient( 'starmus_recording_types_list' );
		if ( false === $recording_types ) {
			$recording_types = get_terms(
				array(
					'taxonomy'   => 'recording_type',
					'hide_empty' => false,
				)
			);
			set_transient( 'starmus_recording_types_list', $recording_types, 12 * HOUR_IN_SECONDS );
		}

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
	 * Enqueues scripts and styles for the recorder UI.
	 */
	public function enqueue_scripts(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! isset( $post->post_content ) || ! has_shortcode( $post->post_content, 'starmus_audio_recorder' ) ) {
			return;
		}

		// Use a single, consistent handle for each script.
		wp_enqueue_script(
			'starmus-recorder-module',
			STARMUS_URL . 'assets/js/starmus-audio-recorder-module.min.js',
			array(),
			STARMUS_VERSION,
			true
		);

		wp_enqueue_script(
			'starmus-recorder-submissions',
			STARMUS_URL . 'assets/js/starmus-audio-recorder-submissions.min.js',
			array( 'starmus-recorder-module', 'wp-api-fetch' ),
			STARMUS_VERSION,
			true
		);

		wp_localize_script(
			'starmus-recorder-submissions',
			'starmusFormData',
			array(
				'rest_url'   => esc_url_raw( rest_url( self::REST_NAMESPACE . '/upload-chunk' ) ),
				'rest_nonce' => wp_create_nonce( 'wp_rest' ),
				'max_mb'     => (int) $this->get_setting( 'max_file_size_mb', 25 ),
			)
		);

		wp_enqueue_style(
			'starmus-recorder-style',
			STARMUS_URL . 'assets/css/starmus-audio-recorder-style.min.css',
			array(),
			STARMUS_VERSION
		);
	}
	/**
	 * Registers REST API routes for chunked uploads.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/upload-chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_upload_chunk_rest' ),
				'permission_callback' => array( $this, 'upload_permissions_check' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Permission check for the REST endpoint.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool True if user can upload files, false otherwise.
	 */
	public function upload_permissions_check( WP_REST_Request $request ): bool {
		$allowed = current_user_can( 'upload_files' );
		/**
		 * Filter: allow site owners to override upload permission.
		 *
		 * @param bool            $allowed
		 * @param WP_REST_Request $request
		 */
		$allowed = (bool) apply_filters_ref_array( 'starmus_can_upload', array( $allowed, $request ) );

		// CSRF: require valid REST nonce.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return false;
		}

		return $allowed;
	}

	/**
	 * Handles chunk uploads via the REST API.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error A response object or an error.
	 */
	public function handle_upload_chunk_rest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( $this->is_rate_limited() ) {
			return new WP_Error(
				'rate_limit_exceeded',
				__( 'You are uploading too frequently. Please wait a moment.', 'starmus_audio_recorder' ),
				array( 'status' => 429 )
			);
		}

		$params = $request->get_params();
		$files  = $request->get_file_params();

		$data = $this->validate_chunk_data( $params, $files );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Create draft post on first chunk (idempotent).
		if ( 0 === $data['offset'] && ! $this->find_post_by_uuid( $data['uuid'] ) ) {
			$this->create_draft_post( $data['uuid'], $data['total_size'], $data['file_name'], $params );
		}

		// Verify the draft belongs to the current user before writing.
		$post = $this->find_post_by_uuid( $data['uuid'] );
		if ( ! $post || (int) get_current_user_id() !== (int) $post->post_author ) {
			return new WP_Error(
				'forbidden_submission',
				__( 'You cannot modify this submission.', 'starmus_audio_recorder' ),
				array( 'status' => 403 )
			);
		}

		$write_result = $this->write_chunk_streamed( $data['uuid'], $data['offset'], $files['audio_file']['tmp_name'] );
		if ( is_wp_error( $write_result ) ) {
			return $write_result;
		}

		// Finalize if we reached or exceeded total size (last chunk).
		if ( ( $data['offset'] + (int) $files['audio_file']['size'] ) >= $data['total_size'] ) {
			return $this->finalize_submission( $data['uuid'], $data['file_name'], $write_result, $params );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Chunk received.', 'starmus_audio_recorder' ),
			),
			200
		);
	}

	/**
	 * Validates incoming chunk data.
	 *
	 * @param array $params The request parameters.
	 * @param array $files  The uploaded files.
	 * @return array|WP_Error The validated data or a WP_Error on failure.
	 */
	private function validate_chunk_data( array $params, array $files ): array|WP_Error {
		$params     = wp_unslash( $params );
		$uuid       = sanitize_key( $params['audio_uuid'] ?? '' );
		$offset     = absint( $params['chunk_offset'] ?? 0 );
		$total_size = absint( $params['total_size'] ?? 0 );
		$file_name  = sanitize_file_name( $params['fileName'] ?? 'audio.webm' );
		$file_chunk = $files['audio_file'] ?? null;

		if ( ! $uuid || ! $file_chunk || UPLOAD_ERR_OK !== ( $file_chunk['error'] ?? 0 ) || ! $total_size ) {
			return new WP_Error(
				'invalid_request_data',
				__( 'Invalid or missing request data.', 'starmus_audio_recorder' ),
				array( 'status' => 400 )
			);
		}

		$max_size = (int) $this->get_setting( 'max_file_size_mb', 25 ) * 1024 * 1024;
		if ( $total_size > $max_size ) {
			return new WP_Error(
				'file_too_large',
				__( 'The uploaded file exceeds the maximum allowed size.', 'starmus_audio_recorder' ),
				array( 'status' => 413 )
			);
		}

		$allowed_exts = array_map( 'strtolower', (array) $this->get_setting( 'allowed_extensions', array( 'webm', 'mp3', 'm4a', 'wav', 'ogg' ) ) );
		$extension    = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, $allowed_exts, true ) ) {
			return new WP_Error(
				'invalid_file_extension',
				__( 'The file type is not permitted.', 'starmus_audio_recorder' ),
				array( 'status' => 415 )
			);
		}

		return compact( 'uuid', 'offset', 'total_size', 'file_chunk', 'file_name' );
	}

	/**
	 * Stream-safe chunk writer with strict offset validation.
	 *
	 * @param string $uuid     Unique upload ID.
	 * @param int    $offset   Chunk byte offset.
	 * @param string $tmp_name PHP tmp file path for this chunk.
	 * @return string|WP_Error Path to the assembled temporary file or error.
	 */
	private function write_chunk_streamed( string $uuid, int $offset, string $tmp_name ): string|WP_Error {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_file_exists
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_filesize
		// Native functions are used here for performance and stream handling, which WP_Filesystem does not expose.
		$temp_dir = $this->get_temp_dir();
		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		$temp_file_path = trailingslashit( $temp_dir ) . $uuid . '.part';

		$current_size = file_exists( $temp_file_path ) ? (int) filesize( $temp_file_path ) : 0;
		if ( $offset !== $current_size ) {
			return new WP_Error(
				'bad_chunk_offset',
				sprintf(
					/* translators: 1: received offset, 2: expected offset */
					__( 'Chunk offset mismatch. Received %1$d, expected %2$d.', 'starmus_audio_recorder' ),
					(int) $offset,
					(int) $current_size
				),
				array( 'status' => 409 )
			);
		}
		// phpcs:enable

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_fopen
		$in = fopen( $tmp_name, 'rb' );
		if ( false === $in ) {
			return new WP_Error(
				'stream_open_failed_in',
				__( 'Failed to open temporary chunk for reading.', 'starmus_audio_recorder' ),
				array( 'status' => 500 )
			);
		}

		$out = fopen( $temp_file_path, 0 === $current_size ? 'wb' : 'ab' );
		if ( false === $out ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
			return new WP_Error(
				'stream_open_failed_out',
				__( 'Failed to open temporary file for writing.', 'starmus_audio_recorder' ),
				array( 'status' => 500 )
			);
		}

		stream_copy_to_stream( $in, $out );
		fflush( $out );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		// phpcs:enable
		return $temp_file_path;
	}

	/**
	 * Finalizes the submission.
	 *
	 * @param string $uuid           The unique upload identifier.
	 * @param string $file_name      The original name of the file.
	 * @param string $temp_file_path The path to the assembled temporary file.
	 * @param array  $form_data      The submitted form data.
	 * @return WP_REST_Response|WP_Error A response object or an error.
	 */
	private function finalize_submission( string $uuid, string $file_name, string $temp_file_path, array $form_data ): WP_REST_Response|WP_Error {
		$post = $this->find_post_by_uuid( $uuid );
		if ( ! $post || (int) get_current_user_id() !== (int) $post->post_author ) {
			if ( file_exists( $temp_file_path ) ) {
				wp_delete_file( $temp_file_path );
			}
			return new WP_Error(
				'submission_not_found',
				__( 'Draft submission post could not be found.', 'starmus_audio_recorder' ),
				array( 'status' => 404 )
			);
		}

		// Verify MIME via fileinfo before sideload.
		$finfo     = finfo_open( FILEINFO_MIME_TYPE );
		$real_mime = $finfo ? (string) finfo_file( $finfo, $temp_file_path ) : '';
		if ( $finfo ) {
			finfo_close( $finfo );
		}
		if ( '' === $real_mime || 0 !== strpos( $real_mime, 'audio/' ) ) {
			wp_delete_file( $temp_file_path );
			return new WP_Error(
				'invalid_mime_type',
				__( 'File content is not a valid audio type.', 'starmus_audio_recorder' ),
				array( 'status' => 415 )
			);
		}

		// Build allowed mimes whitelist based on settings.
		$allowed_exts  = array_map( 'strtolower', (array) $this->get_setting( 'allowed_extensions', array( 'webm', 'mp3', 'm4a', 'wav', 'ogg' ) ) );
		$core_mime_map = wp_get_mime_types();
		$allowed_mimes = array();
		foreach ( $core_mime_map as $exts => $mime ) {
			$ext_arr = array_map( 'trim', explode( '|', $exts ) );
			foreach ( $ext_arr as $ext ) {
				if ( in_array( strtolower( $ext ), $allowed_exts, true ) && 0 === strpos( (string) $mime, 'audio/' ) ) {
					$allowed_mimes[ $ext ] = $mime;
				}
			}
		}

		// Guarded WP includes.
		$__inc_file  = ABSPATH . 'wp-admin/includes/file.php';
		$__inc_media = ABSPATH . 'wp-admin/includes/media.php';
		$__inc_img   = ABSPATH . 'wp-admin/includes/image.php';

		if ( file_exists( $__inc_file ) && ! function_exists( 'wp_handle_sideload' ) ) {
			require_once $__inc_file;
		}
		if ( file_exists( $__inc_media ) && ! function_exists( 'media_handle_sideload' ) ) {
			require_once $__inc_media;
		}
		if ( file_exists( $__inc_img ) && ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once $__inc_img;
		}

		$file_data = array(
			'name'     => wp_unique_filename( wp_get_upload_dir()['path'], $file_name ),
			'tmp_name' => $temp_file_path,
		);

		$upload_result = wp_handle_sideload(
			$file_data,
			array(
				'test_form' => false,
				'mimes'     => $allowed_mimes,
			)
		);

		if ( isset( $upload_result['error'] ) ) {
			if ( file_exists( $temp_file_path ) ) {
				wp_delete_file( $temp_file_path );
			}
			return new WP_Error( 'sideload_failed', $upload_result['error'], array( 'status' => 500 ) );
		}

		$uploaded_path = $upload_result['file'];
		$uploaded_type = $upload_result['type'];

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $uploaded_type,
				'post_title'     => sanitize_text_field( pathinfo( $uploaded_path, PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
			),
			$uploaded_path,
			$post->ID
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $uploaded_path );
			return new WP_Error(
				'attachment_error',
				$attachment_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$attachment_id = (int) $attachment_id;
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded_path ) );

		update_post_meta( $post->ID, '_audio_attachment_id', $attachment_id );
		update_post_meta( $attachment_id, '_starmus_audio_post_id', (int) $post->ID );

		$waveform_generated = $this->generate_waveform_data( $attachment_id );

		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'publish',
			)
		);

		do_action( 'starmus_after_audio_upload', (int) $post->ID, $attachment_id, $form_data );

		$response_data = apply_filters_ref_array(
			'starmus_audio_upload_success_response',
			array(
				array(
					'message'            => esc_html__( 'Submission complete!', 'starmus_audio_recorder' ),
					'post_id'            => (int) $post->ID,
					'attachment_id'      => $attachment_id,
					'waveform_generated' => (bool) $waveform_generated,
				),
				(int) $post->ID,
				$form_data,
			)
		);

		return new WP_REST_Response( $response_data, 200 );
	}

	/**
	 * Creates a draft post for a new submission.
	 *
	 * @param string $uuid       The unique upload identifier.
	 * @param int    $total_size The total size of the file.
	 * @param string $file_name  The name of the file.
	 * @param array  $form_data  The submitted form data.
	 */
	private function create_draft_post( string $uuid, int $total_size, string $file_name, array $form_data ): void {
		$meta_input = array(
			'audio_uuid'        => $uuid,
			'upload_total_size' => $total_size,
		);

		if ( $this->get_setting( 'collect_ip_ua' ) && ! empty( $form_data['audio_consent'] ) ) {
			$meta_input['submission_ip']         = $this->get_client_ip();
			$meta_input['submission_user_agent'] = $this->get_user_agent();
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
	 * Saves all metadata and taxonomies.
	 *
	 * @param int   $audio_post_id The ID of the audio recording post.
	 * @param int   $attachment_id The ID of the audio file attachment.
	 * @param array $form_data     The submitted form data.
	 */
	public function save_all_metadata( int $audio_post_id, int $attachment_id, array $form_data ): void {
		$consent_post_id = $this->create_consent_post( $audio_post_id, $form_data );
		$this->update_audio_recording_metadata( $audio_post_id, $attachment_id, $consent_post_id, $form_data );
		$this->assign_audio_recording_taxonomies( $audio_post_id, $form_data );
	}

	/**
	 * Creates a consent post if consent was given.
	 *
	 * @param int   $audio_post_id The ID of the audio recording post.
	 * @param array $form_data     The submitted form data.
	 * @return int|null            The ID of the new consent post, or null on failure or if no consent.
	 */
	private function create_consent_post( int $audio_post_id, array $form_data ): ?int {
		if ( empty( $form_data['audio_consent'] ) ) {
			return null;
		}

		$consent_post_data = array(
			'post_title'     => sprintf( 'Consent for Recording #%d', $audio_post_id ),
			'post_content'   => __( 'Consent granted for this recording.', 'starmus_audio_recorder' ),
			'post_status'    => 'private',
			'comment_status' => 'closed',
			'post_author'    => get_current_user_id(),
			'post_type'      => 'starmus_consent',
		);

		$consent_post_id = wp_insert_post( $consent_post_data, true );
		if ( is_wp_error( $consent_post_id ) ) {
			return null;
		}

		$consent_post_id = (int) $consent_post_id;
		update_post_meta( $consent_post_id, '_starmus_audio_post_id', $audio_post_id );
		update_post_meta( $consent_post_id, '_starmus_consent_time', current_time( 'mysql' ) );
		update_post_meta( $consent_post_id, '_starmus_consent_ip', $this->get_client_ip() );
		update_post_meta( $consent_post_id, '_starmus_consent_ua', $this->get_user_agent() );

		return $consent_post_id;
	}

	/**
	 * Updates the audio recording post with relevant metadata.
	 *
	 * @param int      $audio_post_id   The ID of the audio recording post.
	 * @param int      $attachment_id   The ID of the audio file attachment.
	 * @param int|null $consent_post_id The ID of the related consent post, if any.
	 * @param array    $form_data       The submitted form data.
	 */
	private function update_audio_recording_metadata( int $audio_post_id, int $attachment_id, ?int $consent_post_id, array $form_data ): void {
		update_post_meta( $audio_post_id, '_audio_attachment_id', $attachment_id );
		if ( $consent_post_id ) {
			update_post_meta( $audio_post_id, '_consent_post_id', $consent_post_id );
		}

		if ( ! empty( $form_data['audio_description'] ) ) {
			wp_update_post(
				array(
					'ID'           => $audio_post_id,
					'post_content' => sanitize_textarea_field( $form_data['audio_description'] ),
				)
			);
		}

		/**
		 * Action: allow external systems (e.g., ACF) to sync additional fields.
		 *
		 * @param int      $audio_post_id
		 * @param int      $attachment_id
		 * @param int|null $consent_post_id
		 * @param array    $form_data
		 */
		do_action( 'starmus_update_recording_metadata', $audio_post_id, $attachment_id, $consent_post_id, $form_data );
	}

	/**
	 * Assigns taxonomy terms to the audio recording post.
	 *
	 * @param int   $audio_post_id The ID of the audio recording post.
	 * @param array $form_data     The submitted form data.
	 */
	private function assign_audio_recording_taxonomies( int $audio_post_id, array $form_data ): void {
		$taxonomies = array(
			'language'       => $form_data['language'] ?? null,
			'recording_type' => $form_data['recording_type'] ?? null,
		);

		foreach ( $taxonomies as $tax_slug => $provided ) {
			if ( empty( $provided ) || ! taxonomy_exists( $tax_slug ) ) {
				continue;
			}

			$term_ids = array();
			$values   = is_array( $provided ) ? $provided : array( $provided );

			foreach ( $values as $val ) {
				if ( is_numeric( $val ) ) {
					$term_id = (int) $val;
					if ( get_term( $term_id, $tax_slug ) ) {
						$term_ids[] = $term_id;
					}
				} else {
					$slug = sanitize_key( (string) $val );
					$term = get_term_by( 'slug', $slug, $tax_slug );
					if ( $term && ! is_wp_error( $term ) ) {
						$term_ids[] = (int) $term->term_id;
					}
				}
			}

			if ( ! empty( $term_ids ) ) {
				wp_set_post_terms( $audio_post_id, $term_ids, $tax_slug, false );
			}
		}
	}

	/**
	 * Adds a conditional redirect URL to the final success response.
	 *
	 * @param array $response  The success response data.
	 * @param int   $post_id   The ID of the created post.
	 * @param array $form_data The submitted form data.
	 * @return array The modified response data.
	 */
	public function add_conditional_redirect( array $response, int $post_id, array $form_data ): array {
		$recording_type_slug      = ! empty( $form_data['recording_type'] ) ? ( is_numeric( $form_data['recording_type'] ) ? (string) $form_data['recording_type'] : sanitize_key( (string) $form_data['recording_type'] ) ) : 'default';
		$response['redirect_url'] = apply_filters_ref_array(
			'starmus_final_redirect_url',
			array( home_url( '/my-recordings' ), $post_id, $recording_type_slug, $form_data )
		);
		return $response;
	}

	/**
	 * Generate waveform data if enabled and the required server tool is present.
	 *
	 * @param int $attachment_id The ID of the audio attachment.
	 * @return bool True if waveform was generated successfully, false otherwise.
	 */
	private function generate_waveform_data( int $attachment_id ): bool {
		if ( ! $this->get_setting( 'enable_waveform_generation' ) ) {
			return false;
		}

		if ( ! apply_filters( 'starmus_allow_shell_exec', false ) ) {
			return false;
		}

		if ( ! function_exists( 'shell_exec' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		$which = trim( (string) shell_exec( 'command -v audiowaveform' ) );
		if ( '' === $which || ! is_executable( $which ) ) {
			return false;
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return false;
		}

		$temp_json_path = $file_path . '.json';
		$cmd            = sprintf(
			'%s -i %s -o %s --pixels-per-second 20 --bits 8 --height 128',
			escapeshellarg( $which ),
			escapeshellarg( $file_path ),
			escapeshellarg( $temp_json_path )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		shell_exec( $cmd );

		if ( ! file_exists( $temp_json_path ) ) {
			return false;
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			$__inc_file = ABSPATH . 'wp-admin/includes/file.php';
			if ( file_exists( $__inc_file ) && ! function_exists( 'WP_Filesystem' ) ) {
				require_once $__inc_file;
			}
			WP_Filesystem();
		}

		$json = $wp_filesystem->get_contents( $temp_json_path );
		wp_delete_file( $temp_json_path );

		if ( false === $json ) {
			return false;
		}

		$waveform_data = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || empty( $waveform_data['data'] ) ) {
			return false;
		}

		update_post_meta( $attachment_id, '_waveform_data', $waveform_data['data'] );
		return true;
	}

	/**
	 * Renders a template with theme override support (no extract()).
	 *
	 * @param string $template_name The name of the template file.
	 * @param array  $args          Arguments to pass to the template.
	 * @return string Rendered HTML.
	 */
	private function render_template( string $template_name, array $args = array() ): string {
		$template_name   = basename( $template_name );
		$theme_template  = trailingslashit( get_stylesheet_directory() ) . 'starmus/' . $template_name;
		$plugin_template = trailingslashit( STARMUS_PATH ) . 'templates/' . $template_name;

		$template_path = '';
		if ( file_exists( $theme_template ) ) {
			$template_path = $theme_template;
		} elseif ( file_exists( $plugin_template ) ) {
			$template_path = $plugin_template;
		}

		if ( $template_path ) {
			ob_start();
			if ( function_exists( 'load_template' ) ) {
				load_template( $template_path, false, $args );
			} else {
				include $template_path;
			}
			return (string) ob_get_clean();
		}
		return '';
	}

	/**
	 * Gets a specific setting value using transients for caching.
	 *
	 * @param string $key     The setting key.
	 * @param mixed  $default The default value if not found.
	 * @return mixed          The setting value.
	 */
	private function get_setting( string $key, $default = null ) {
		$cache_key = 'starmus_settings_cache';
		$settings  = get_transient( $cache_key );

		if ( false === $settings ) {
			// Start with DB option (works in tests), allow override via filter.
			$settings = (array) get_option( 'starmus_settings', array() );
			$settings = (array) apply_filters( 'starmus_settings_array', $settings );

			// If the plugin settings class is available, merge/override.
			if ( class_exists( '\Starmus\includes\StarmusSettings' ) ) {
				$obj = new StarmusSettings();
				if ( method_exists( $obj, 'all' ) ) {
					$loaded = (array) $obj->all();
					// Plugin-defined settings win.
					$settings = array_merge( $settings, $loaded );
				}
			}

			set_transient( $cache_key, $settings, HOUR_IN_SECONDS );
		}

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Checks if the current user/IP is rate-limited (simple per-minute counter).
	 *
	 * @return bool True if rate-limited, false otherwise.
	 */
	private function is_rate_limited(): bool {
		$limit   = (int) apply_filters( 'starmus_rate_limit_uploads_per_minute', 20 );
		$user_id = (int) get_current_user_id();
		$ip      = $this->get_client_ip();
		$key     = 'starmus_rl_' . $user_id . '_' . md5( $ip );

		$current = (int) get_transient( $key );
		if ( $current >= $limit ) {
			return true;
		}
		set_transient( $key, $current + 1, MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Gets a secure, filterable temporary directory for uploads.
	 *
	 * @return string|WP_Error The path to the temp directory or a WP_Error on failure.
	 */
	private function get_temp_dir(): string|WP_Error {
		$upload_dir       = wp_get_upload_dir();
		$default_temp_dir = trailingslashit( $upload_dir['basedir'] ) . 'starmus-temp';
		$temp_dir         = apply_filters( 'starmus_temp_upload_dir', $default_temp_dir );

		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error(
				'temp_dir_error',
				__( 'Cannot create or access temporary directory.', 'starmus_audio_recorder' ),
				array( 'status' => 500 )
			);
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			// Guarded include for WP_Filesystem
			$__inc_file = ABSPATH . 'wp-admin/includes/file.php';
			if ( file_exists( $__inc_file ) && ! function_exists( 'WP_Filesystem' ) ) {
				require_once $__inc_file;
			}
			WP_Filesystem();
		}

		$htaccess_path = trailingslashit( $temp_dir ) . '.htaccess';
		if ( ! $wp_filesystem->exists( $htaccess_path ) ) {
			$wp_filesystem->put_contents( $htaccess_path, "Deny from all\n" );
		}
		$index_path = trailingslashit( $temp_dir ) . 'index.html';
		if ( ! $wp_filesystem->exists( $index_path ) ) {
			$wp_filesystem->put_contents( $index_path, '' );
		}

		return $temp_dir;
	}

	/**
	 * Schedule the cleanup cron if WP-Cron functions are available.
	 */
	public function maybe_schedule_cron(): void {
		if ( function_exists( '\wp_next_scheduled' ) && function_exists( '\wp_schedule_event' ) ) {
			if ( ! \wp_next_scheduled( 'starmus_cleanup_temp_files' ) ) {
				\wp_schedule_event( time(), 'hourly', 'starmus_cleanup_temp_files' );
			}
		}
	}

	/**
	 * Cron job to clean up stale .part files older than 24 hours.
	 */
	public function cleanup_stale_temp_files(): void {
		$temp_dir = $this->get_temp_dir();
		if ( is_wp_error( $temp_dir ) ) {
			return;
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			$__inc_file = ABSPATH . 'wp-admin/includes/file.php';
			if ( file_exists( $__inc_file ) && ! function_exists( 'WP_Filesystem' ) ) {
				require_once $__inc_file;
			}
			WP_Filesystem();
		}

		$files = $wp_filesystem->dirlist( $temp_dir );
		if ( empty( $files ) ) {
			return;
		}

		$cutoff = time() - DAY_IN_SECONDS;

		foreach ( $files as $file ) {
			if ( 'f' === $file['type'] && str_ends_with( $file['name'], '.part' ) && $file['lastmod'] < $cutoff ) {
				$file_path = trailingslashit( $temp_dir ) . $file['name'];
				wp_delete_file( $file_path );
			}
		}
	}

	/**
	 * Finds a post by its UUID meta value, with caching (no direct DB calls).
	 *
	 * @param string $uuid The UUID to search for.
	 * @return \WP_Post|null The post object or null if not found.
	 */
	private function find_post_by_uuid( string $uuid ): ?\WP_Post {
		$uuid        = sanitize_key( $uuid );
		$cache_key   = 'starmus_post_id_for_uuid_' . $uuid;
		$cache_group = 'starmus_audio_recorder';
		$post_id     = wp_cache_get( $cache_key, $cache_group );

		if ( false === $post_id ) {
			$q = new WP_Query(
				array(
					'post_type'              => $this->get_setting( 'cpt_slug', 'audio-recording' ),
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'meta_query'             => array(
						array(
							'key'   => 'audio_uuid',
							'value' => $uuid,
						),
					),
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			$post_id = $q->have_posts() ? (int) $q->posts[0] : 0;
			wp_cache_set( $cache_key, $post_id, $cache_group, 5 * MINUTE_IN_SECONDS );
		}

		return $post_id ? get_post( (int) $post_id ) : null;
	}

	/**
	 * Gets the URL for the page where users can edit a recording.
	 *
	 * @return string The base URL for the edit page, or empty string if not set.
	 */
	private function get_edit_page_url(): string {
		$edit_page_id = $this->get_setting( 'edit_page_id' );
		if ( ! empty( $edit_page_id ) ) {
			$permalink = get_permalink( (int) $edit_page_id );
			return $permalink ? esc_url( $permalink ) : '';
		}
		return '';
	}

	/**
	 * Get a validated client IP string (empty if invalid).
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ip  = filter_var( $raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 );
		return is_string( $ip ) ? $ip : '';
	}

	/**
	 * Get a sanitized User-Agent string.
	 *
	 * @return string
	 */
	private function get_user_agent(): string {
		$raw = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return sanitize_text_field( $raw );
	}
}
