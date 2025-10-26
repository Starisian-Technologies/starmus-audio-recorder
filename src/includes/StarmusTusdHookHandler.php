<?php
namespace Starisian\Sparxstar\Starmus\includes;
/**
 * PSR-4 compliant class to handle webhooks from a tusd server.
 *
 * @package    STARMUS
 * @version    1.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\includes\StarmusSubmissionHandler;

/**
 * Starmus_Tusd_Hook_Handler Class
 */
class StarmusTusdHookHandler {

	protected $namespace = 'starmus/v1';
	protected $rest_base = 'hook';

  // Add a property to hold the submission handler
	private ?StarmusSubmissionHandler $submission_handler;

// Use dependency injection in the constructor
	public function __construct( \Starisian\Sparxstar\Starmus\includes\StarmusSubmissionHandler $submission_handler ) {

		$this->submission_handler = $submission_handler;
		$this->register_routes();
		$this->register_hooks();
	}

public function register_hooks(): void {
  add_action( 'rest_api_init', 'starmus_initialize_tusd_hook_handler' );

}

/**
 * Moves the file and creates a media library entry by calling the core handler.
 *
 * @param array $upload_info The 'Upload' object from the tusd hook payload.
 * @return int|WP_Error The new attachment ID on success, or a WP_Error on failure.
 */
private function process_completed_upload( $upload_info ) {
    $temp_path = $upload_info['Storage']['Path'] ?? '';
    $metadata  = $upload_info['MetaData'] ?? [];
    
    // The tus client should send all the form data in the metadata payload
    $sanitized_form_data = $this->submission_handler->sanitize_submission_data( $metadata );

    $result = $this->submission_handler->process_completed_file( $temp_path, $sanitized_form_data );
    
    // Clean up the .info file that tusd leaves behind
    @unlink( $temp_path . '.info' );

    if ( is_wp_error( $result ) ) {
        return $result;
    }
    
    // Here you can trigger your STARMUS pipeline with the new attachment ID
    do_action('starmus_upload_complete', $result['attachment_id']);

    return $result['attachment_id'];
}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_tusd_hook' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	public function handle_tusd_hook( $request ) {
		$data = $request->get_json_params();

		if ( empty( $data ) || ! isset( $data['Type'] ) || ! isset( $data['Event']['Upload'] ) ) {
			return new WP_Error( 'invalid_payload', 'Invalid or empty payload from tusd.', array( 'status' => 400 ) );
		}

		// Optional: For debugging, log the raw payload if WP_DEBUG is enabled.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[TUSD HOOK PAYLOAD] ' . date('c') . " | " . json_encode($data) );
		}
			case 'post-finish':
				$result = $this->process_completed_upload( $data['Event']['Upload'] );
				if ( is_wp_error( $result ) ) {
					// The hook was received, but processing failed. Log it and return an error.
					error_log( '[TUSD HOOK ERROR] ' . $result->get_error_message() );
					return $result; // Forward the WP_Error to the REST response.
				}
				break;
		}

		return new WP_REST_Response( array( 'status' => 'success', 'message' => 'Hook received and processed.' ), 200 );
	}

	/**
	 * Moves the file to the WordPress uploads directory and creates a media library entry.
	 *
	 * @param array $upload_info The 'Upload' object from the tusd hook payload.
	 * @return int|WP_Error The new attachment ID on success, or a WP_Error on failure.
	 */
	private function process_completed_upload( $upload_info ) {
		// 1. Sanitize and retrieve essential data from the hook
		$temp_path = $upload_info['Storage']['Path'] ?? '';
		$metadata  = $upload_info['MetaData'] ?? [];
		$filename  = sanitize_file_name( $metadata['filename'] ?? 'tusd-upload-' . time() );

		if ( ! file_exists( $temp_path ) ) {
			return new WP_Error( 'file_not_found', 'Tusd reported a completed upload, but the file was not found at the specified path: ' . $temp_path, array( 'status' => 500 ) );
		}

		// 2. Prepare the destination in the WordPress uploads directory
		$upload_dir = wp_upload_dir(); // Gets the current year/month upload directory
		$new_filename = wp_unique_filename( $upload_dir['path'], $filename );
		$new_path = $upload_dir['path'] . '/' . $new_filename;

		// 3. Move the file
		if ( ! rename( $temp_path, $new_path ) ) {
			// Also attempt to clean up the .info file tusd creates
			@unlink( $temp_path . '.info' );
			return new WP_Error( 'file_move_failed', 'Could not move the uploaded file to the WordPress uploads directory.', array( 'status' => 500 ) );
		}
		// Also clean up the .info file
		@unlink( $temp_path . '.info' );


		// 4. Create the attachment in the WordPress Media Library
		$filetype = wp_check_filetype( $new_filename, null );

		$attachment_data = array(
			'guid'           => $upload_dir['url'] . '/' . $new_filename,
			'post_mime_type' => $filetype['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $new_filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment_data, $new_path );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// 5. Generate metadata (thumbnails, etc.) for the attachment
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		$attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $new_path );
		wp_update_attachment_metadata( $attachment_id, $attachment_metadata );
		
		error_log( '[TUSD HOOK] Successfully created attachment ID: ' . $attachment_id );
		
		// Here you can trigger your STARMUS pipeline with the new attachment ID
		// do_action('starmus_upload_complete', $attachment_id);

		return $attachment_id;
	}

	public function permissions_check() {
		// Sanitize and validate IP address
		$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? filter_var( $_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP ) : false;
		$allowed_ips = array( '127.0.0.1', '::1' );
		$is_localhost = $remote_ip && in_array( $remote_ip, $allowed_ips, true );

		// Check for shared secret header
		$shared_secret = defined('TUSD_WEBHOOK_SECRET') ? TUSD_WEBHOOK_SECRET : get_option('tusd_webhook_secret', '');
		$provided_secret = '';
		if ( isset( $_SERVER['HTTP_X_TUSD_WEBHOOK_SECRET'] ) ) {
			$provided_secret = sanitize_text_field( $_SERVER['HTTP_X_TUSD_WEBHOOK_SECRET'] );
		}

		if ( $is_localhost && $shared_secret && hash_equals( $shared_secret, $provided_secret ) ) {
			return true;
		}
		return false;
	}
}


// Removed erroneous add_action for missing starmus_initialize_tusd_hook_handler
