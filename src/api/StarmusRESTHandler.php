<?php
declare(strict_types=1);

/**
 * REST handler bridging HTTP endpoints to submission services.
 *
 * @package Starisian\Sparxstar\Starmus\api
 */

namespace Starisian\Sparxstar\Starmus\api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\includes\StarmusSubmissionHandler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use function register_rest_route;
use function is_wp_error;
use function current_user_can;
use function __;

/**
 * Exposes WordPress REST API routes for audio submissions.
 */
final class StarmusRESTHandler {

	private StarmusSubmissionHandler $submission_handler;
	private StarmusAudioRecorderDAL $dal;
	private StarmusSettings $settings;

	/**
	 * Constructor.
	 */
	public function __construct(
		StarmusAudioRecorderDAL $dal,
		StarmusSettings $settings,
		?StarmusSubmissionHandler $submission_handler = null
	) {

		$this->dal                = $dal;
		$this->settings           = $settings;
		$this->submission_handler = $submission_handler ?? new StarmusSubmissionHandler( $dal, $settings );

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			StarmusSubmissionHandler::STARMUS_REST_NAMESPACE,
			'/upload-fallback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_fallback_upload' ),
				'permission_callback' => static fn() => current_user_can( 'upload_files' ),
			)
		);

		register_rest_route(
			StarmusSubmissionHandler::STARMUS_REST_NAMESPACE,
			'/upload-chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chunk_upload' ),
				'permission_callback' => static fn() => current_user_can( 'upload_files' ),
			)
		);

		register_rest_route(
			StarmusSubmissionHandler::STARMUS_REST_NAMESPACE,
			'/status/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => static fn() => current_user_can( 'upload_files' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => 'is_numeric',
					),
				),
			)
		);
	}

	/**
	 * Handle fallback form-based upload.
	 */
	public function handle_fallback_upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			// Log incoming payload
			$files = $request->get_file_params();
			error_log( '[Starmus Upload Debug] FILE PARAMS: ' . print_r( $files, true ) );
			error_log( '[Starmus Upload Debug] PARAMS: ' . print_r( $request->get_params(), true ) );

			// Defensive file-key handling
			$file = $files['audio_file'] ?? ( $files['file'] ?? null );
			if ( ! $file || ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
				return new WP_Error(
					'server_error',
					__( 'Missing or invalid file upload data.', 'starmus-audio-recorder' ),
					array( 'status' => 400 )
				);
			}

			// Pass request downstream
			$result = $this->submission_handler->handle_fallback_upload_rest( $request );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			do_action(
				'starmus_submission_complete',
				$result['data']['attachment_id'] ?? 0,
				$result['data']['post_id'] ?? 0
			);

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $result['data'],
				),
				200
			);
		} catch ( \Throwable $e ) {
			StarmusLogger::log( 'RestHandler:fallback', $e, 'error' );
			return new WP_Error(
				'server_error',
				__( 'Upload failed. Please try again later.', 'starmus-audio-recorder' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Handle chunked uploads.
	 */
	public function handle_chunk_upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$result = $this->submission_handler->handle_upload_chunk_rest( $request );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! empty( $result['data']['attachment_id'] ) && ! empty( $result['data']['post_id'] ) ) {
				do_action( 'starmus_submission_complete', $result['data']['attachment_id'], $result['data']['post_id'] );
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $result,
				),
				200
			);
		} catch ( \Throwable $e ) {
			StarmusLogger::log( 'RestHandler:chunk', $e, 'error' );
			return new WP_Error( 'server_error', 'Chunk upload failed', array( 'status' => 500 ) );
		}
	}

	/**
	 * Handle status check for a submission.
	 */
	public function handle_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request['id'];

		try {
			$post_info = $this->dal->get_post_info( $post_id );
			if ( ! $post_info ) {
				return new WP_Error( 'not_found', 'Submission not found', array( 'status' => 404 ) );
			}

			if ( $post_info['type'] !== $this->submission_handler->get_cpt_slug() ) {
				return new WP_Error( 'invalid_type', 'Not an audio recording', array( 'status' => 403 ) );
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => array(
						'id'     => $post_id,
						'status' => $post_info['status'],
						'type'   => $post_info['type'],
					),
				),
				200
			);
		} catch ( \Throwable $e ) {
			StarmusLogger::log( 'RestHandler:status', $e, 'error' );
			return new WP_Error( 'server_error', 'Could not fetch status', array( 'status' => 500 ) );
		}
	}
}
