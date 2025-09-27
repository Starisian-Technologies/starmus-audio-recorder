<?php
/**
 * Service class for handling file operations, especially for offloaded media.
 *
 * @package Starmus\services
 * @version 1.0.0
 */

namespace Starmus\services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FileService {

	/**
	 * Guarantees a local copy of an attachment's file is available for processing.
	 *
	 * If the file is already local, it returns the path. If offloaded, it downloads
	 * it to a temporary location and returns that path.
	 *
	 * The caller is responsible for cleaning up the temporary file.
	 *
	 * @param int $attachment_id The ID of the WordPress attachment.
	 * @return string|null The path to the local file, or null on failure.
	 */
	public function get_local_copy( int $attachment_id ): ?string {
		// First, check if a local file already exists.
		// The '@' suppresses warnings if the path is a URL, which can happen with some offloaders.
		$local_path = @get_attached_file( $attachment_id );
		if ( $local_path && file_exists( $local_path ) ) {
			return $local_path; // The file is already here, no need to download.
		}

		// The file is likely offloaded. Download it to a temporary location.
		$remote_url = wp_get_attachment_url( $attachment_id );
		if ( ! $remote_url ) {

			return null;
		}

		// WordPress has a built-in function to download a URL to a temp file.
		// We need to make sure the file.php is loaded.
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$temp_file_path = download_url( $remote_url );

		if ( is_wp_error( $temp_file_path ) ) {
			return null;
		}

		return $temp_file_path;
	}

	/**
	 * Uploads a local file to replace an existing attachment, respecting offloaders.
	 *
	 * @param int    $attachment_id The attachment to update.
	 * @param string $local_file_path The path to the new local file to upload.
	 * @return bool True on success.
	 */
	public function upload_and_replace_attachment( int $attachment_id, string $local_file_path ): bool {
		// Many offloader plugins (like WP Offload Media) provide a function to handle this.
		// We check for the most common one. You may need to add checks for others.
		if ( function_exists( 'as3cf_upload_attachment' ) ) {
			// This function from WP Offload Media handles the upload to S3/R2
			// and updates all the necessary WordPress metadata.
			$result = as3cf_upload_attachment( $attachment_id, null, $local_file_path );
			return ! is_wp_error( $result );
		} else {
			// Fallback for environments without a known offloader.
			// This assumes the server has write access to the uploads directory.
			$upload_dir = wp_get_upload_dir();
			$new_path   = $upload_dir['path'] . '/' . basename( $local_file_path );

			if ( rename( $local_file_path, $new_path ) ) {
				update_attached_file( $attachment_id, $new_path );
				return true;
			}
		}
		return false;
	}
}
