<?php
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * @package Starmus\services
 * @module  StarmusAudioProcessingHandler
 * @version 0.7.4
 * @since   0.7.4
 * @file    Central handler for the post-upload audio processing pipeline.
 */

namespace Starmus\services;

use Starmus\frontend\StarmusAudioRecorderUI;
use Starmus\services\WaveformService;
use Starmus\services\PostProcessingService;
use Starmus\services\FileService;
use Starmus\services\AudioProcessingService;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StarmusAudioProcessingHandler {

	private static $instance                                  = null;
	private ?WaveformService $waveform_service                = null;
	private ?PostProcessingService $post_processing_service   = null;
	private ?FileService $file_service                        = null;
	private ?AudioProcessingService $audio_processing_service = null;
	private ?array $config                                    = array();
	private ?array $pipeline                                  = array();

	public static function init(): StarmusAudioProcessingHandler {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->waveform_service         = new WaveformService();
		$this->post_processing_service  = new PostProcessingService();
		$this->file_service             = new FileService();
		$this->audio_processing_service = new AudioProcessingService();
		$this->setup_config();
		$this->setup_pipeline();
	}

	private function setup_config(): void {
		$default_config = array(
			'log_level'            => 'info',
			'generate_waveform'    => true,
			'transcode_and_master' => true,
		);
		$this->config   = apply_filters( 'starmus_audio_processing_config', $default_config );
	}

	private function setup_pipeline(): void {
		$default_pipeline = array(
			'10_validate_upload'      => array( $this, 'step_validate_upload' ),
			'20_sideload_and_attach'  => array( $this, 'step_sideload_and_attach' ),
			'30_generate_waveform'    => array( $this, 'step_generate_waveform' ),
			'40_transcode_and_master' => array( $this, 'step_transcode_and_master' ),
			'50_save_metadata'        => array( $this, 'step_save_metadata' ),
			'60_finalize_post'        => array( $this, 'step_finalize_post' ),
		);
		$this->pipeline   = apply_filters( 'starmus_audio_processing_pipeline', $default_pipeline );
		ksort( $this->pipeline );
	}

	public function process_audio( string $temp_file_path, array $form_data, string $uuid ): mixed {
		$this->log( 'info', 'Starting audio processing pipeline.', array( 'uuid' => $uuid ) );
		$post = $this->find_post_by_uuid( $uuid );
		if ( ! $post || get_current_user_id() !== (int) $post->post_author ) {
			return new WP_Error( 'not_found', 'Draft submission not found or permission denied.' );
		}
		$context = array(
			'temp_file_path' => $temp_file_path,
			'form_data'      => $form_data,
			'post_id'        => $post->ID,
			'attachment_id'  => null,
			'results'        => array(),
		);
		try {
			foreach ( $this->pipeline as $step_name => $callback ) {
				if ( ! is_callable( $callback ) ) {
					continue;
				}
				$this->log( 'debug', "Executing pipeline step: [{$step_name}]" );
				$context = call_user_func( $callback, $context, $this->config );
				if ( is_wp_error( $context ) ) {
					throw new \Exception( $context->get_error_message(), $context->get_error_code() );
				}
			}
		} catch ( \Exception $e ) {
			$this->log( 'error', 'Pipeline failed.', array( 'error' => $e->getMessage() ) );
			wp_delete_post( $context['post_id'], true );
			if ( $context['attachment_id'] ) {
				wp_delete_attachment( $context['attachment_id'], true );
			}
			if ( file_exists( $temp_file_path ) ) {
				unlink( $temp_file_path );
			}
			return new WP_Error( $e->getCode(), 'Processing failed: ' . $e->getMessage() );
		}
		$this->log( 'info', 'Audio processing pipeline completed successfully.', array( 'post_id' => $context['post_id'] ) );
		do_action( 'starmus_audio_processing_complete', $context );
		return $context;
	}

	public function step_validate_upload( $context ): mixed {
		$finfo     = finfo_open( FILEINFO_MIME_TYPE );
		$real_mime = $finfo ? (string) finfo_file( $finfo, $context['temp_file_path'] ) : '';
		finfo_close( $finfo );
		if ( strpos( $real_mime, 'audio/' ) !== 0 && $real_mime !== 'video/webm' ) {
			return new WP_Error( 'invalid_mime', 'File is not a valid audio type.' );
		}
		$context['results']['validation'] = array( 'mime_type' => $real_mime );
		return $context;
	}

	public function step_sideload_and_attach( $context ): mixed {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file_name     = $context['form_data']['fileName'] ?? basename( $context['temp_file_path'] );
		$file_data     = array(
			'name'     => wp_unique_filename( wp_get_upload_dir()['path'], $file_name ),
			'tmp_name' => $context['temp_file_path'],
		);
		$attachment_id = media_handle_sideload( $file_data, $context['post_id'] );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		$context['attachment_id']         = (int) $attachment_id;
		$context['results']['attachment'] = array( 'id' => $attachment_id );
		return $context;
	}

	public function step_generate_waveform( $context, $config ): mixed {
		if ( $config['generate_waveform'] && $this->waveform_service->is_tool_available() ) {
			$success                        = $this->waveform_service->generate_waveform_data( $context['attachment_id'] );
			$context['results']['waveform'] = array( 'generated' => $success );
		}
		return $context;
	}

	public function step_transcode_and_master( $context, $config ): mixed {
		if ( $config['transcode_and_master'] && $this->post_processing_service->is_tool_available() ) {
			$success                         = $this->post_processing_service->process_and_archive_audio( $context['attachment_id'] );
			$context['results']['transcode'] = array( 'processed' => $success );
		}
		return $context;
	}

	public function step_save_metadata( $context ): mixed {
		$ui_class = new StarmusAudioRecorderUI( null );
		$ui_class->save_all_metadata( $context['post_id'], $context['attachment_id'], $context['form_data'] );
		$context['results']['metadata_saved'] = true;
		return $context;
	}

	public function step_finalize_post( $context ): mixed {
		wp_update_post(
			array(
				'ID'          => $context['post_id'],
				'post_status' => 'publish',
			)
		);
		update_post_meta( $context['post_id'], '_audio_attachment_id', $context['attachment_id'] );
		set_post_thumbnail( $context['post_id'], $context['attachment_id'] );
		$context['results']['post_finalized'] = true;
		return $context;
	}

	private function find_post_by_uuid( string $uuid ): ?\WP_Post {
		$args  = array(
			'post_type'      => 'audio-recording',
			'post_status'    => 'draft',
			'meta_query'     => array(
				array(
					'key'   => '_submission_uuid',
					'value' => $uuid,
				),
			),
			'posts_per_page' => 1,
			'fields'         => 'all',
		);
		$query = new \WP_Query( $args );
		return $query->have_posts() ? $query->posts[0] : null;
	}

	private function log( $level, $message, $data = array() ): void {
		if ( $this->config['log_level'] === 'debug' || $level !== 'debug' ) {
			error_log( '[Starmus_Audio_Processing_Handler][' . strtoupper( $level ) . '] ' . $message . ' ' . json_encode( $data ) );
		}
	}
}

Starmus_Audio_Processing_Handler::init();
