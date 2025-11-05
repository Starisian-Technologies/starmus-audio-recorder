use function get_attached_file;
use function wp_get_attachment_url;
namespace Starmus\services;

use function esc_url_raw;
use function download_url;
use function is_wp_error;
use function as3cf_upload_attachment;
use function wp_get_upload_dir;
use function update_attached_file;
<?php
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * FileService (DAL-integrated)
 * ----------------------------
 * - Guarantees local access for offloaded files.
 * - Routes all persistence and attachment updates through DAL.
 * - Supports external offloaders like WP Offload Media (AS3CF).
 *
 * @package Starisian\Sparxstar\Starmus\services
 * @version 0.8.5-dal
 */

namespace Starisian\Sparxstar\Starmus\services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;

final class FileService {

	private StarmusAudioRecorderDAL $dal;

	public function __construct( ?StarmusAudioRecorderDAL $dal = null ) {
		$this->dal = $dal ?: new StarmusAudioRecorderDAL();
	}

	/**
	 * Guarantees a local copy of an attachment's file is available for processing.
	 *
	 * If offloaded, downloads it to a temp path and returns the local copy.
	 * The caller is responsible for cleanup.
	 */
	public function get_local_copy( int $attachment_id ): ?string {
		$local_path = @get_attached_file( $attachment_id );
		if ( $local_path && file_exists( $local_path ) ) {
<<<<<<< HEAD
			return $local_path;
=======
			return sanitize_text_field( $local_path ); // The file is already here, no need to download.
>>>>>>> 571b925d (11042025MB3)
		}

		$remote_url = wp_get_attachment_url( $attachment_id );
		if ( ! $remote_url ) {
			return null;
		}
	$remote_url = \esc_url_raw( $remote_url );

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

<<<<<<< HEAD
		$temp = download_url( $remote_url );
		if ( is_wp_error( $temp ) ) {
			return null;
		}
		return $temp;
=======
	$temp_file_path = \download_url( $remote_url );

	if ( \is_wp_error( $temp_file_path ) ) {
			error_log( "Starmus FileUtility: Failed to download {$remote_url}. Error: " . $temp_file_path->get_error_message() );
			return null;
		}

		return sanitize_text_field( $temp_file_path );
>>>>>>> 571b925d (11042025MB3)
	}

	/**
	 * Uploads or re-attaches a local file via offloader or DAL fallback.
	 */
	public function upload_and_replace_attachment( int $attachment_id, string $local_file_path ): bool {
<<<<<<< HEAD
		if ( ! file_exists( $local_file_path ) ) {
			return false;
		}

		// Delegate to offloader if present
		if ( function_exists( 'as3cf_upload_attachment' ) ) {
			$result = as3cf_upload_attachment( $attachment_id, null, $local_file_path );
			if ( is_wp_error( $result ) ) {
				return false;
=======
		// Many offloader plugins (like WP Offload Media) provide a function to handle this.
		// We check for the most common one. You may need to add checks for others.
		if ( function_exists( 'as3cf_upload_attachment' ) ) {
			// This function from WP Offload Media handles the upload to S3/R2
			// and updates all the necessary WordPress metadata.
			$result = \as3cf_upload_attachment( $attachment_id, null, $local_file_path );
			return ! \is_wp_error( $result );
		} else {
			// Fallback for environments without a known offloader.
			// This assumes the server has write access to the uploads directory.
			$upload_dir = \wp_get_upload_dir();
			$new_path   = $upload_dir['path'] . '/' . basename( $local_file_path );

			if ( rename( $local_file_path, $new_path ) ) {
				\update_attached_file( $attachment_id, $new_path );
				return true;
>>>>>>> 571b925d (11042025MB3)
			}
			return true;
		}

		// DAL-managed fallback
		$upload_dir = wp_get_upload_dir();
		$new_path   = trailingslashit( $upload_dir['path'] ) . basename( $local_file_path );

		if ( @rename( $local_file_path, $new_path ) ) {
			$this->dal->update_attachment_metadata( $attachment_id, $new_path );
			return true;
		}
		return false;
	}
}
