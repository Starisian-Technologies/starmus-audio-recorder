<?php

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * StarmusFileService (DAL-integrated)
 * - Guarantees local access for offloaded files.
 * - Routes all persistence and attachment updates through DAL.
 * - Supports external offloaders like WP Offload Media (AS3CF).
 *
 * @package Starisian\Sparxstar\Starmus\services
 * @version 1.0.0-HARDENED
 */
namespace Starisian\Sparxstar\Starmus\services;

if (! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use WP_Filesystem_Base;

final readonly class StarmusFileService
{
    private StarmusAudioRecorderDAL $dal;

    public function __construct(?StarmusAudioRecorderDAL $dal = null)
    {
        $this->dal = $dal ?: new StarmusAudioRecorderDAL();
    }

    /**
     * Conditionally loads compatibility layers for third-party plugins.
     * This keeps integrations clean and modular.
     */
    public function register_compatibility_hooks(): void
    {
        // --- ADVANCED MEDIA OFFLOADER COMPATIBILITY BRIDGE ---
        // The class name for Advanced Media Offloader's main plugin class is 'Advanced_Media_Offloader\Plugin'.
        // This is the most reliable way to check if it's active.
        if (class_exists('Advanced_Media_Offloader\Plugin')) {
            // Wire the 'ensure_attachment_metadata' method to the 'add_attachment' action.
            // This activates our fix only when AMO is present.
            add_action(
                'add_attachment',
                [$this, 'ensure_attachment_metadata'],
                20, // Priority: Run after attachment is created, before others might use it.
                1   // We only need the first argument ($attachment_id).
            );
        }
    }
     /**
     * Ensures essential metadata exists for a newly created attachment.
     *
     * This method acts as a compatibility bridge. It detects when an attachment is created
     * by the Starmus plugin without metadata and generates it. This is crucial for
     * third-party plugins like Advanced Media Offloader, which rely on this metadata
     * to function correctly and avoid fatal errors.
     *
     * This method is designed to be hooked to the 'add_attachment' action.
     *
     * @param int $attachment_id The post ID of the new attachment.
     * @return void
     */
    public function ensure_attachment_metadata(int $attachment_id): void
    {
        try {
            // Guard Clause 1: Exit immediately if metadata already exists.
            if (wp_get_attachment_metadata($attachment_id)) {
                return;
            }

            // Guard Clause 2: Only target uploads from the specific Starmus fallback API endpoint.
            if (!isset($_SERVER['REQUEST_URI']) || !str_contains($_SERVER['REQUEST_URI'], '/star-starmus-audio-recorder/v1/')) {
                return;
            }

            StarmusLogger::info('StarmusFileService', 'Incomplete attachment detected. Generating missing metadata for offloader compatibility.', ['id' => $attachment_id]);

            $file_path = get_attached_file($attachment_id);

            // Guard Clause 3: Ensure the file physically exists before proceeding.
            if (!$file_path || !file_exists($file_path)) {
                StarmusLogger::error('StarmusFileService', 'Metadata generation skipped: Attached file does not exist.', [
                    'id' => $attachment_id,
                    'path' => is_string($file_path) ? $file_path : 'N/A'
                ]);
                return;
            }

            if (!function_exists('wp_generate_attachment_metadata')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            // Generate and then update the metadata using the DAL for consistency.
            $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
            
            if (!empty($metadata) && !is_wp_error($metadata)) {
                // We use the DAL here to align with your existing architecture.
                $this->dal->update_attachment_metadata($attachment_id, $metadata);
                StarmusLogger::debug('StarmusFileService', 'Successfully generated and saved metadata.', ['id' => $attachment_id]);
            }

        } catch (\Throwable $e) {
            StarmusLogger::error('StarmusFileService', 'An exception occurred during metadata generation.', [
                'id' => $attachment_id,
                'exception' => $e->getMessage(),
                'line' => $e->getLine()
            ]);
        }
    }
    /**
     * Guarantees a local copy of an attachment's file is available for processing.
     * If offloaded, downloads it to a temp path. Caller is responsible for cleanup.
     */
    public function get_local_copy(int $attachment_id): ?string
    {
        if ($attachment_id <= 0) {
            return null;
        }

        // 1. Check for an existing local file first.
        $local_path = get_attached_file($attachment_id);
        
        // PHP 8+ Safety: Ensure $local_path is a string before checking existence.
        if (is_string($local_path) && file_exists($local_path)) {
            StarmusLogger::debug('StarmusFileService', 'Found local file for attachment.', ['id' => $attachment_id]);
            return $local_path;
        }

        // 2. If not local, download from the public URL.
        $remote_url = wp_get_attachment_url($attachment_id);
        if (!$remote_url) {
            StarmusLogger::error('StarmusFileService', 'Attachment URL not found for download.', ['id' => $attachment_id]);
            return null;
        }

        StarmusLogger::info('StarmusFileService', 'File is offloaded. Downloading local copy.', ['url' => $remote_url]);

        if (! \function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $temp_file = download_url(esc_url_raw($remote_url), 120); // 120 second timeout
        
        if (is_wp_error($temp_file)) {
            StarmusLogger::error('StarmusFileService', 'Failed to download offloaded file.', [
                'id' => $attachment_id,
                'error' => $temp_file->get_error_message()
            ]);
            return null;
        }

        return $temp_file;
    }

    /**
     * Uploads or re-attaches a local file, handling offloader integration.
     */
    public function upload_and_replace_attachment(int $attachment_id, string $local_file_path): bool
    {
        if (!file_exists($local_file_path)) {
            StarmusLogger::error('StarmusFileService', 'Local file to be uploaded does not exist.', ['path' => $local_file_path]);
            return false;
        }

        // Delegate to WP Offload Media if present
        if (\function_exists('as3cf_upload_attachment')) {
            StarmusLogger::debug('StarmusFileService', 'Delegating upload to WP Offload Media.', ['id' => $attachment_id]);
            $result = as3cf_upload_attachment($attachment_id, null, $local_file_path);
            if (is_wp_error($result)) {
                StarmusLogger::error('StarmusFileService', 'WP Offload Media failed to upload.', ['error' => $result->get_error_message()]);
                return false;
            }
            return true;
        }

        // Fallback to local filesystem move
        $upload_dir = wp_get_upload_dir();
        $new_path   = trailingslashit($upload_dir['path']) . basename($local_file_path);

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ($wp_filesystem->move($local_file_path, $new_path, true)) {
            $this->dal->update_attachment_metadata($attachment_id, $new_path);
            return true;
        } else {
             StarmusLogger::error('StarmusFileService', 'Filesystem failed to move local file.', ['from' => $local_file_path, 'to' => $new_path]);
        }

        return false;
    }

    /**
     * Returns the correct public URL for an attachment, honoring offloaders.
     */
    public function star_get_public_url(int $attachment_id): ?string
    {
        if ($attachment_id <= 0) {
            return null;
        }

        // Primary method: Let WordPress and its filters (like Offloader) resolve the URL.
        $url = wp_get_attachment_url($attachment_id);
        if (!empty($url)) {
            return esc_url_raw($url);
        }

        // Fallback method: Reconstruct URL from metadata if primary fails.
        $meta = wp_get_attachment_metadata($attachment_id);
        $upload_dir = wp_get_upload_dir();

        if (!empty($meta['file'])) {
            $url = trailingslashit($upload_dir['baseurl']) . ltrim((string) $meta['file'], '/');
            return esc_url_raw($url);
        }

        return null;
    }
}