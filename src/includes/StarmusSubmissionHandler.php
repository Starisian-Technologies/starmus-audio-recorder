<?php
declare(strict_types=1);

/**
 * Core submission service responsible for processing uploads using the DAL.
 *
 * @package   Starisian\Sparxstar\Starmus\includes
 * @version   6.9.3-GOLDEN-MASTER
 */

namespace Starisian\Sparxstar\Starmus\includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use function array_map;
use function fclose;
use function file_exists;
use function filemtime;
use function filesize;
use function fopen;
use function get_current_user_id;
use function get_post_meta;
use function get_post_type;
use function glob;
use function home_url;
use function is_dir;
use function is_wp_error;
use function json_decode;
use function mime_content_type;
use function pathinfo;
use function rmdir;
use function sanitize_key;
use function sanitize_text_field;
use function strtolower;
use function str_contains;
use function str_starts_with;
use function stream_copy_to_stream;
use function sys_get_temp_dir;
use function time;
use function trailingslashit;
use function unlink;
use function wp_check_filetype;
use function wp_get_attachment_url;
use function wp_get_mime_types;
use function wp_json_encode;
use function wp_next_scheduled;
use function wp_normalize_path;
use function wp_schedule_single_event;
use function wp_set_post_terms;
use function wp_unique_filename;
use function wp_unslash;
use function wp_upload_dir;

use Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusSanitizer;
use Starisian\Sparxstar\Starmus\services\StarmusPostProcessingService;
use Throwable;
use WP_Error;
use WP_REST_Request;

final class StarmusSubmissionHandler {

    public const STARMUS_REST_NAMESPACE = 'star-starmus-audio-recorder/v1';

    private array $fallback_file_keys = [ 'audio_file', 'file', 'upload' ];

    // Explicit MIME types for stricter validation
    private array $default_allowed_mimes = [
        'audio/webm',
        'audio/ogg',
        'audio/mpeg',
        'audio/wav',
        'audio/x-wav',
        'audio/mp4',
    ];

    public function __construct(
        private readonly StarmusAudioRecorderDALInterface $dal,
        private readonly StarmusSettings $settings
    ) {
        try {
            add_action( 'starmus_cleanup_temp_files', [ $this, 'cleanup_stale_temp_files' ] );
            StarmusLogger::info( 'SubmissionHandler', 'Constructed successfully' );
        } catch ( Throwable $throwable ) {
            StarmusLogger::error( 'SubmissionHandler', 'Construction failed', [ 'error' => $throwable->getMessage() ] );
            throw $throwable;
        }
    }

    // --- UPLOAD HANDLERS ---

    public function process_completed_file( string $file_path, array $form_data ): array|WP_Error {
        return $this->_finalize_from_local_disk( $file_path, $form_data );
    }

    private function _finalize_from_local_disk( string $file_path, array $form_data ): array|WP_Error {
        try {
            if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
                return $this->err( 'file_missing', 'No file to process.', 400 );
            }

            $filename   = $form_data['filename'] ?? pathinfo( $file_path, PATHINFO_BASENAME );
            $upload_dir = wp_upload_dir();
            
            // SECURITY: Path Traversal Protection
            $base_path   = wp_normalize_path( trailingslashit( $upload_dir['path'] ) );
            $unique_name = wp_unique_filename( $upload_dir['path'], $filename );
            $destination = $base_path . $unique_name;

            // MIME Detection with Fallback
            $detected_mime = mime_content_type( $file_path );
            if ( false === $detected_mime ) {
                $wp_check = wp_check_filetype( $filename );
                $mime     = $wp_check['type'] ?: 'application/octet-stream';
            } else {
                $mime = (string) $detected_mime;
            }

            $final_mime = empty( $mime ) ? ( $form_data['filetype'] ?? '' ) : $mime;
            $size       = file_exists( $file_path ) ? filesize( $file_path ) : 0;
            
            $valid = $this->validate_file_against_settings( $final_mime, (int) $size );
            if ( is_wp_error( $valid ) ) {
                unlink( $file_path );
                return $valid;
            }

            global $wp_filesystem;
            if ( empty( $wp_filesystem ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }

            if ( ! $wp_filesystem->move( $file_path, $destination, true ) ) {
                unlink( $file_path );
                return $this->err( 'move_failed', 'Failed to move upload.', 500 );
            }

            // Cleanup Chunk Directory if applicable
            $parent_dir = dirname( $file_path );
            if ( str_contains( $parent_dir, 'starmus_tmp' ) ) {
                $this->cleanup_chunks_dir( $parent_dir );
            }

            $attachment_id = $this->dal->create_attachment_from_file( $destination, $filename );
            if ( is_wp_error( $attachment_id ) ) {
                unlink( $destination );
                return $attachment_id;
            }

            // Update vs Create Logic
            $existing_post_id = isset( $form_data['post_id'] ) ? absint( $form_data['post_id'] ) : ( isset( $form_data['recording_id'] ) ? absint( $form_data['recording_id'] ) : 0 );

            if ( $existing_post_id > 0 && get_post_type( $existing_post_id ) === $this->get_cpt_slug() ) {
                $cpt_post_id = $existing_post_id;
                $old_attachment_id = (int) get_post_meta( $cpt_post_id, '_audio_attachment_id', true );
                if ( $old_attachment_id > 0 ) {
                    $this->dal->delete_attachment( $old_attachment_id );
                }
            } else {
                $user_id = isset( $form_data['user_id'] ) ? absint( $form_data['user_id'] ) : get_current_user_id();
                $cpt_post_id = $this->dal->create_audio_post(
                    $form_data['starmus_title'] ?? pathinfo( $filename, PATHINFO_FILENAME ),
                    $this->get_cpt_slug(),
                    $user_id
                );
                if ( is_wp_error( $cpt_post_id ) ) {
                    $this->dal->delete_attachment( (int) $attachment_id );
                    return $cpt_post_id;
                }
            }

            $this->dal->save_post_meta( (int) $cpt_post_id, '_audio_attachment_id', (int) $attachment_id );
            $this->dal->set_attachment_parent( (int) $attachment_id, (int) $cpt_post_id );
            $this->save_all_metadata( (int) $cpt_post_id, (int) $attachment_id, $form_data );

            do_action( 'starmus_after_audio_saved', (int) $cpt_post_id, $form_data );

            return [
                'success'       => true,
                'attachment_id' => (int) $attachment_id,
                'post_id'       => (int) $cpt_post_id,
                'url'           => wp_get_attachment_url( (int) $attachment_id ),
            ];
        } catch ( Throwable $throwable ) {
            StarmusLogger::error( 'SubmissionHandler', 'Finalize Exception', [ 'msg' => $throwable->getMessage() ] );
            return $this->err( 'server_error', 'File finalization failed.', 500 );
        }
    }

    public function handle_fallback_upload_rest( WP_REST_Request $request ): array|WP_Error {
        try {
            if ( $this->is_rate_limited( get_current_user_id() ) ) {
                return $this->err( 'rate_limited', 'Too frequent.', 429 );
            }

            $form_data  = $this->sanitize_submission_data( $request->get_params() ?? [] );
            $files_data = $request->get_file_params() ?? [];

            $file_key = $this->detect_file_key( $files_data );
            if ( ! $file_key ) {
                return $this->err( 'missing_file', 'No audio file provided.', 400 );
            }

            $file = $files_data[ $file_key ];
            if ( ! isset( $file['error'] ) || (int) $file['error'] !== 0 || empty( $file['tmp_name'] ) ) {
                return $this->err( 'upload_error', 'Upload failed on client.', 400 );
            }

            // CRITICAL FIX: Reliable MIME probing for REST fallback (handles iOS/Safari empty type)
            $mime = $file['type'] ?? '';
            
            if ( empty( $mime ) && ! empty( $file['tmp_name'] ) && file_exists( $file['tmp_name'] ) ) {
                $detected = mime_content_type( $file['tmp_name'] );
                if ( $detected ) {
                    $mime = $detected;
                } else {
                    // Fallback to extension check
                    $check = wp_check_filetype( (string) ( $file['name'] ?? '' ) );
                    $mime  = $check['type'] ?? '';
                }
            }

            $validation = $this->validate_file_against_settings( $mime, (int) ( $file['size'] ?? 0 ) );
            if ( is_wp_error( $validation ) ) {
                return $validation;
            }

            $result = $this->process_fallback_upload( $files_data, $form_data, $file_key );
            
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            return [ 'success' => true, 'data' => $result['data'] ];
        } catch ( Throwable $throwable ) {
            StarmusLogger::error( 'SubmissionHandler', 'Fallback REST Exception', [ 'msg' => $throwable->getMessage() ] );
            return $this->err( 'server_error', 'Fallback upload exception.', 500 );
        }
    }

    public function process_fallback_upload( array $files_data, array $form_data, string $file_key ): array|WP_Error {
        try {
            if ( ! function_exists( 'media_handle_sideload' ) ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }

            if ( empty( $files_data[ $file_key ] ) ) {
                return $this->err( 'missing_file', 'No audio file provided.', 400 );
            }

            $attachment_id = $this->dal->create_attachment_from_sideload( $files_data[ $file_key ] );
            if ( is_wp_error( $attachment_id ) ) {
                return $attachment_id;
            }

            $existing_id = isset( $form_data['post_id'] ) ? absint( $form_data['post_id'] ) : 0;
            
            if ( $existing_id > 0 && get_post_type( $existing_id ) === $this->get_cpt_slug() ) {
                $cpt_post_id = $existing_id;
            } else {
                $title = $form_data['starmus_title'] ?? pathinfo( (string) $files_data[ $file_key ]['name'], PATHINFO_FILENAME );
                $cpt_post_id = $this->dal->create_audio_post( $title, $this->get_cpt_slug(), get_current_user_id() );
            }

            if ( is_wp_error( $cpt_post_id ) ) {
                $this->dal->delete_attachment( (int) $attachment_id );
                return $cpt_post_id;
            }

            $this->dal->save_post_meta( (int) $cpt_post_id, '_audio_attachment_id', (int) $attachment_id );
            $this->dal->set_attachment_parent( (int) $attachment_id, (int) $cpt_post_id );
            
            $form_data['audio_files_originals'] = (int) $attachment_id;
            $this->save_all_metadata( (int) $cpt_post_id, (int) $attachment_id, $form_data );

            return [
                'success' => true,
                'data'    => [
                    'attachment_id' => (int) $attachment_id,
                    'post_id'       => (int) $cpt_post_id,
                    'url'           => wp_get_attachment_url( (int) $attachment_id ),
                    'redirect_url'  => esc_url( $this->get_redirect_url() ),
                ],
            ];
        } catch ( Throwable $throwable ) {
            StarmusLogger::error( 'SubmissionHandler', 'Process Fallback Exception', [ 'msg' => $throwable->getMessage() ] );
            return $this->err( 'server_error', 'Fallback processing failed.', 500 );
        }
    }

    // --- METADATA SAVING ---

    public function save_all_metadata( int $audio_post_id, int $attachment_id, array $form_data ): void {
        try {
            $env_json = $form_data['_starmus_env'] ?? '';
            $env_data = [];
            
            if ( $env_json ) {
                $decoded_env = json_decode( wp_unslash( $env_json ), true );
                if ( $decoded_env ) {
                    $env_data = $decoded_env;
                    $this->update_acf_field( 'environment_data', wp_json_encode( $env_data ), $audio_post_id );
                    
                    if ( ! empty( $env_data['identifiers'] ) ) {
                        $this->update_acf_field( 'device_fingerprint', wp_json_encode( $env_data['identifiers'] ), $audio_post_id );
                    }
                    
                    if ( ! empty( $env_data['device']['userAgent'] ) ) {
                        $this->update_acf_field( 'user_agent', sanitize_text_field( $env_data['device']['userAgent'] ), $audio_post_id );
                    }
                }
            }

            $cal_json = $form_data['_starmus_calibration'] ?? '';
            if ( $cal_json ) {
                $decoded_cal = json_decode( wp_unslash( $cal_json ), true );
                if ( $decoded_cal ) {
                    $this->update_acf_field( 'runtime_metadata', wp_json_encode( $decoded_cal ), $audio_post_id );
                    if ( isset( $decoded_cal['gain'] ) ) {
                        $this->update_acf_field( 'mic_profile', 'Gain: ' . sanitize_text_field( (string) $decoded_cal['gain'] ), $audio_post_id );
                    }
                }
            }

            $transcript = $form_data['first_pass_transcription'] ?? '';
            if ( ! $transcript && ! empty( $env_data['transcript'] ) ) {
                $transcript = $env_data['transcript']['final'] ?? '';
            }
            if ( $transcript ) {
                $this->update_acf_field( 'first_pass_transcription', wp_unslash( $transcript ), $audio_post_id );
            }

            if ( ! empty( $form_data['waveform_json'] ) ) {
                $wf_decoded = json_decode( wp_unslash( $form_data['waveform_json'] ) );
                if ( $wf_decoded ) {
                    $this->update_acf_field( 'waveform_json', wp_json_encode( $wf_decoded ), $audio_post_id );
                }
            }

            // NOTE: StarmusSanitizer must use $_SERVER['REMOTE_ADDR'].
            // Ensure vip-config.php maps HTTP_CF_CONNECTING_IP to REMOTE_ADDR if using Cloudflare.
            $this->update_acf_field( 'submission_ip', StarmusSanitizer::get_user_ip(), $audio_post_id );
            
            $passthrough = [ 'contributor_id', 'artifact_id', 'project_collection_id', 'accession_number', 'location', 'recording_metadata' ];
            foreach ( $passthrough as $field ) {
                if ( isset( $form_data[ $field ] ) ) {
                    $this->update_acf_field( $field, sanitize_text_field( (string) $form_data[ $field ] ), $audio_post_id );
                }
            }

            if ( $attachment_id !== 0 ) {
                $this->update_acf_field( 'audio_files_originals', $attachment_id, $audio_post_id );
            }

            if ( ! empty( $form_data['language'] ) ) {
                wp_set_post_terms( $audio_post_id, [ (int) $form_data['language'] ], 'language' );
            }
            if ( ! empty( $form_data['recording_type'] ) ) {
                wp_set_post_terms( $audio_post_id, [ (int) $form_data['recording_type'] ], 'recording-type' );
            }

            do_action( 'starmus_after_save_submission_metadata', $audio_post_id, $form_data, [] );

            $processing_params = [
                'bitrate'      => '192k',
                'samplerate'   => 44100,
                'network_type' => '4g',
                'session_uuid' => $env_data['identifiers']['sessionId'] ?? 'unknown',
            ];
            $this->trigger_post_processing( $audio_post_id, $attachment_id, $processing_params );

        } catch ( Throwable $e ) {
            StarmusLogger::error( 'SubmissionHandler', 'Metadata Save Failed', [ 'error' => $e->getMessage() ] );
        }
    }
    
    private function trigger_post_processing( int $post_id, int $attachment_id, array $params ): void {
        try {
            $processor = new StarmusPostProcessingService();
            if ( ! $processor->process( $post_id, $attachment_id, $params ) ) {
                if ( ! wp_next_scheduled( 'starmus_cron_process_pending_audio', [ $post_id, $attachment_id ] ) ) {
                    wp_schedule_single_event( time() + 60, 'starmus_cron_process_pending_audio', [ $post_id, $attachment_id ] );
                }
            }
        } catch ( Throwable $throwable ) {
            StarmusLogger::error( 'SubmissionHandler', 'Post Processing Trigger Failed', [ 'error' => $throwable->getMessage() ] );
        }
    }

    // --- HELPERS ---

    public function get_cpt_slug(): string { 
        return ( $this->settings && $this->settings->get( 'cpt_slug' ) ) ? sanitize_key( (string) $this->settings->get( 'cpt_slug' ) ) : 'audio-recording'; 
    }
    
    private function update_acf_field( string $key, $value, int $id ): void { 
        $this->dal->save_post_meta( $id, $key, $value ); 
    }
    
    public function sanitize_submission_data( array $data ): array { 
        return StarmusSanitizer::sanitize_submission_data( $data ); 
    }
    
    private function is_rate_limited( int $id ): bool { 
        return $this->dal->is_rate_limited( $id ); 
    }
    
    private function get_temp_dir(): string { 
        return trailingslashit( wp_upload_dir()['basedir'] ?: sys_get_temp_dir() ) . 'starmus_tmp/'; 
    }
    
    private function get_redirect_url(): string { 
        return home_url( '/my-submissions' ); 
    }
    
    private function detect_file_key( array $files ): ?string {
        foreach ( $this->fallback_file_keys as $key ) {
            if ( ! empty( $files[ $key ] ) && is_array( $files[ $key ] ) ) {
                return $key;
            }
        }
        foreach ( $files as $key => $val ) {
            if ( is_array( $val ) && ! empty( $val['tmp_name'] ) ) {
                return (string) $key;
            }
        }
        return null;
    }

    public function cleanup_stale_temp_files(): void {
        $dir = $this->get_temp_dir();
        if ( is_dir( $dir ) ) {
            foreach ( glob( $dir . '*.part' ) as $file ) {
                if ( file_exists( $file ) && filemtime( $file ) < time() - DAY_IN_SECONDS ) {
                    unlink( $file );
                }
            }
        }
    }

    private function validate_file_against_settings( string $mime, int $size_bytes ): true|WP_Error {
        $max_mb = $this->settings ? (int) $this->settings->get( 'file_size_limit', 10 ) : 10;
        if ( $size_bytes > ( $max_mb * 1024 * 1024 ) ) {
            return $this->err( 'file_too_large', "Max ${max_mb}MB", 413 );
        }

        $allowed_str = $this->settings ? $this->settings->get( 'allowed_file_types', '' ) : '';
        
        if ( empty( $allowed_str ) ) {
            $allowed_mimes = $this->default_allowed_mimes;
        } else {
            $exts = array_map( 'trim', explode( ',', $allowed_str ) );
            $allowed_mimes = [];
            $wp_mimes = wp_get_mime_types();
            
            foreach ( $exts as $ext ) {
                // FIX: Ensure audio/webm is allowed if 'webm' is listed. 
                // WP defaults 'webm' to 'video/webm', causing 'audio/webm' uploads to fail validation.
                if ( 'webm' === $ext ) {
                    $allowed_mimes[] = 'audio/webm';
                }

                foreach ( $wp_mimes as $ext_pattern => $mime_type ) {
                    if ( str_contains( $ext_pattern, $ext ) ) {
                        $exploded = explode( '|', $mime_type );
                        foreach ( $exploded as $m ) {
                            $allowed_mimes[] = $m;
                        }
                    }
                }
            }
        }

        if ( ! in_array( strtolower( $mime ), $allowed_mimes, true ) ) {
            return $this->err( 'mime_not_allowed', 'Type not allowed: ' . $mime, 415 );
        }
        
        return true;
    }
    
    private function cleanup_chunks_dir( string $path ): void {
        $temp_dir = $this->get_temp_dir();
        $path     = trailingslashit( $path );

        if ( str_starts_with( $path, $temp_dir ) && is_dir( $path ) ) {
            $files = glob( $path . '*' );
            if ( $files ) {
                array_map( 'unlink', $files );
            }
            rmdir( $path );
        }
    }
    
    private function combine_chunks_multipart( string $id, string $base, int $total ): string|WP_Error {
        $final = $this->get_temp_dir() . $id . '.tmp.file';
        $fp = fopen( $final, 'wb' );
        if ( ! $fp ) {
            return $this->err( 'combine_open_failed', 'File create error', 500 );
        }

        for ( $i = 0; $i < $total; $i++ ) {
            $chunk = $base . 'chunk_' . $i;
            if ( ! file_exists( $chunk ) ) {
                usleep( 500000 ); 
                clearstatcache();
            }
            if ( ! file_exists( $chunk ) ) {
                fclose( $fp ); 
                unlink( $final );
                return $this->err( 'missing_chunk', "Chunk $i missing", 400 );
            }
            $chunk_fp = fopen( $chunk, 'rb' );
            if ( ! $chunk_fp ) {
                fclose( $fp ); 
                return $this->err( 'read_chunk_failed', "Chunk $i read error", 500 );
            }
            stream_copy_to_stream( $chunk_fp, $fp );
            fclose( $chunk_fp );
        }
        fclose( $fp );
        return $final;
    }

    private function err( string $code, string $message, int $status = 400, array $context = [] ): WP_Error {
        StarmusLogger::warning( 'SubmissionHandler', "$code: $message", $context );
        return new WP_Error( $code, $message, [ 'status' => $status ] );
    }
}