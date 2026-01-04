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
 *
 * @version 1.0.0-HARDENED
 */
namespace Starisian\Sparxstar\Starmus\services;

if ( ! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\data\StarmusAudioDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * StarmusFileService (DAL-integrated)
 *
 * Provides comprehensive file management for audio recordings with support for
 * external offload services like Cloudflare R2 via WP Offload Media. Guarantees
 * local file access for processing while maintaining compatibility with various
 * WordPress hosting configurations.
 *
 * Key Features:
 * - Local file access guarantee for offloaded attachments
 * - Automatic metadata generation for third-party plugin compatibility
 * - DAL-routed persistence for consistent data access patterns
 * - Support for WP Offload Media (AS3CF) and Advanced Media Offloader
 * - Temporary file management for processing workflows
 *
 * Compatibility Layers:
 * - Advanced Media Offloader: Automatic metadata generation
 * - WP Offload Media: Native upload delegation
 * - Local filesystem: Direct file operations
 *
 * @package Starisian\Sparxstar\Starmus\services
 *
 * @version 1.0.0-HARDENED
 *
 * @since   1.0.0
 * @see StarmusAudioDAL Data access abstraction
 * @see Advanced_Media_Offloader\Plugin Third-party offloader integration
 */
final readonly class StarmusFileService
{
    /**
     * Data Access Layer for consistent WordPress operations.
     *
     * @since 1.0.0
     */
    private ?StarmusAudioDAL $dal;

    /**
     * Initializes the file service with DAL dependency.
     *
     * @param StarmusAudioDAL|null $dal Optional DAL instance (creates new if null)
     *
     * @since 1.0.0
     */
    public function __construct(?StarmusAudioDAL $dal = null)
    {
        $this->dal = $dal ?: new StarmusAudioDAL();
    }

    /**
     * Registers compatibility hooks for third-party plugin integrations.
     *
     * Conditionally loads compatibility layers to maintain clean, modular
     * integrations with external plugins. Only activates hooks when the
     * target plugins are detected as present.
     *
     * Current Integrations:
     * - Advanced Media Offloader: Metadata generation bridge
     *
     * @since 1.0.0
     *
     * @hook add_attachment Priority 20 for metadata generation
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
     * Ensures essential metadata exists for newly created attachments.
     *
     * Compatibility bridge that detects when attachments are created by Starmus
     * without proper metadata and generates it. Critical for third-party plugins
     * like Advanced Media Offloader that rely on metadata to function correctly.
     *
     * @param int $attachment_id WordPress attachment post ID
     *
     * @since 1.0.0
     *
     * @hook add_attachment Called when new attachments are created
     *
     * Guard Conditions:
     * 1. Skip if metadata already exists (performance optimization)
     * 2. Only target Starmus API endpoints (prevents interference)
     * 3. Validate file existence before processing
     *
     * Process Flow:
     * 1. Check for existing metadata
     * 2. Validate request originates from Starmus endpoint
     * 3. Verify physical file existence
     * 4. Generate WordPress attachment metadata
     * 5. Save via DAL for consistency
     *
     * Error Handling:
     * - Logs detailed information for debugging
     * - Gracefully handles missing files or generation failures
     * - Continues execution on non-critical errors
     *
     * @see wp_generate_attachment_metadata() WordPress metadata generation
     * @see StarmusAudioDAL::update_attachment_metadata() DAL persistence
     */
    public function ensure_attachment_metadata(int $attachment_id): void
    {
        try {
            // Guard Clause 1: Exit immediately if metadata already exists.
            if (wp_get_attachment_metadata($attachment_id)) {
                return;
            }

            // Guard Clause 2: Only target uploads from the specific Starmus fallback API endpoint.
            if ( ! isset($_SERVER['REQUEST_URI']) || ! str_contains((string) $_SERVER['REQUEST_URI'], '/star-starmus-audio-recorder/v1/')) {
                return;
            }

            StarmusLogger::info(
                'Incomplete attachment detected. Generating missing metadata for offloader compatibility.',
                ['component' => self::class, 'attachment_id' => $attachment_id]
            );

            $file_path = get_attached_file($attachment_id);

            // Guard Clause 3: Ensure the file physically exists before proceeding.
            if ( ! $file_path || ! file_exists($file_path)) {
                StarmusLogger::error(
                    'Metadata generation skipped: Attached file does not exist.',
                    [
                        'component'     => self::class,
                        'attachment_id' => $attachment_id,
                    ]
                );
                return;
            }

            if ( ! \function_exists('wp_generate_attachment_metadata')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            // Generate and then update the metadata using the DAL for consistency.
            $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);

            if ( ! empty($metadata) && ! is_wp_error($metadata)) {
                // We use the DAL here to align with your existing architecture.
                $this->dal->update_attachment_metadata($attachment_id, $file_path);
                StarmusLogger::debug(
                    'Successfully generated and saved metadata.',
                    [
                        'component'     => self::class,
                        'attachment_id' => $attachment_id,
                    ]
                );
            }
        } catch (\Throwable $throwable) {
            StarmusLogger::log(
                $throwable,
                [
                    'component'     => self::class,
                    'attachment_id' => $attachment_id,
                ]
            );
        }
    }

    /**
     * Guarantees local file access for attachment processing.
     *
     * Returns a local file path for an attachment, downloading from remote storage
     * if necessary. Essential for audio processing workflows that require direct
     * file system access.
     *
     * @param int $attachment_id WordPress attachment post ID
     *
     * @since 1.0.0
     *
     * Resolution Strategy:
     * 1. **Local Check**: Verify existing local file via get_attached_file()
     * 2. **Remote Download**: Download from public URL if offloaded
     * 3. **Temporary Storage**: Return temp file path (caller must clean up)
     *
     * Use Cases:
     * - Audio processing (FFmpeg operations)
     * - Metadata extraction (getID3 analysis)
     * - Waveform generation (audiowaveform CLI)
     * - File validation and security scanning
     *
     * Offloader Support:
     * - WP Offload Media (AS3CF): Downloads from S3/CloudFlare R2
     * - Advanced Media Offloader: Downloads from configured storage
     * - Custom offloaders: Works with any plugin using wp_get_attachment_url
     *
     * Important Notes:
     * - Returns temporary files for offloaded content
     * - Caller responsible for cleanup of temp files
     * - 120-second download timeout for large files
     * - Automatically handles WordPress file URL filtering
     *
     * @throws \Exception Implicitly via download_url() on network failures
     *
     * @return string|null Local file path if available, null on failure
     *
     * @example
     * ```php
     * $local_path = $service->get_local_copy($attachment_id);
     * if ($local_path && file_exists($local_path)) {
     *     // Process file
     *     process_audio_file($local_path);
     *
     *     // Clean up if temporary
     *     if ($local_path !== get_attached_file($attachment_id)) {
     *         unlink($local_path);
     *     }
     * }
     * ```
     */
    public function get_local_copy(int $attachment_id): ?string
    {
        if ($attachment_id <= 0) {
            return null;
        }

        // 1. Check for an existing local file first.
        $local_path = get_attached_file($attachment_id);

        // PHP 8+ Safety: Ensure $local_path is a string before checking existence.
        if (\is_string($local_path) && file_exists($local_path)) {
            StarmusLogger::debug(
                'Found local file for attachment.',
                [
                    'component'     => self::class,
                    'attachment_id' => $attachment_id,
                    'path'          => $local_path,
                ]
            );
            return $local_path;
        }

        // 2. If not local, download from the public URL.
        $remote_url = wp_get_attachment_url($attachment_id);
        if ( ! $remote_url) {
            StarmusLogger::error(
                'Attachment URL not found for download.',
                [
                    'component'     => self::class,
                    'attachment_id' => $attachment_id,
                ]
            );
            return null;
        }

        StarmusLogger::info(
            'File is offloaded. Downloading local copy.',
            [
                'component'     => self::class,
                'attachment_id' => $attachment_id,
                'remote_url'    => $remote_url,
            ]
        );

        if ( ! \function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $temp_file = download_url(esc_url_raw($remote_url), 120); // 120 second timeout

        if (is_wp_error($temp_file)) {
            StarmusLogger::error(
                'Failed to download offloaded file.',
                [
                    'component'     => self::class,
                    'attachment_id' => $attachment_id,
                    'remote_url'    => $remote_url,
                ]
            );
            return null;
        }

        return $temp_file;
    }

    /**
     * Uploads and replaces attachment file with offloader integration.
     *
     * Handles file uploads for both local and offloaded storage configurations.
     * Automatically delegates to appropriate storage backend based on active plugins.
     *
     * @param int $attachment_id WordPress attachment post ID to replace
     * @param string $local_file_path Local file path to upload
     *
     * @return bool True if upload successful, false on failure
     *
     * @since 1.0.0
     *
     * Upload Strategy:
     * 1. **WP Offload Media**: Delegate to as3cf_upload_attachment() if available
     * 2. **Local Filesystem**: Use WordPress filesystem API as fallback
     * 3. **DAL Integration**: Update metadata through Data Access Layer
     *
     * Supported Offloaders:
     * - WP Offload Media (AS3CF): S3, CloudFlare R2, DigitalOcean Spaces
     * - Local filesystem: Standard WordPress uploads directory
     *
     * Process Flow:
     * 1. Validate local file existence
     * 2. Detect active offloader plugins
     * 3. Delegate upload to appropriate handler
     * 4. Update attachment metadata via DAL
     * 5. Log results for monitoring
     *
     * Error Conditions:
     * - Local file doesn't exist
     * - Offloader upload fails
     * - Filesystem move operation fails
     * - Metadata update fails
     * @see as3cf_upload_attachment() WP Offload Media function
     * @see WP_Filesystem WordPress filesystem abstraction
     * @see StarmusAudioDAL::update_attachment_metadata() Metadata persistence
     */
    public function upload_and_replace_attachment(int $attachment_id, string $local_file_path): bool
    {
        if ( ! file_exists($local_file_path)) {
            StarmusLogger::error(
                'Local file to be uploaded does not exist.',
                [
                    'component'     => self::class,
                    'attachment_id' => $attachment_id,
                    'path'          => $local_file_path,
                ]
            );
            return false;
        }

        // Delegate to WP Offload Media if present
        if (\function_exists('as3cf_upload_attachment')) {
            StarmusLogger::debug(
                'Delegating upload to WP Offload Media.',
                [
                    'component'     => self::class,
                    'attachment_id' => $attachment_id,
                ]
            );
            $result = as3cf_upload_attachment($attachment_id, null, $local_file_path);
            if (is_wp_error($result)) {
                StarmusLogger::error(
                    'WP Offload Media failed to upload.',
                    [
                        'component'     => self::class,
                        'attachment_id' => $attachment_id,
                    ]
                );
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
        }

        StarmusLogger::error(
            'Filesystem failed to move local file.',
            [
                'component'     => self::class,
                'attachment_id' => $attachment_id,
                'path'          => $local_file_path,
            ]
        );

        return false;
    }

    /**
     * Retrieves the correct public URL for an attachment across storage backends.
     *
     * Returns the appropriate public URL whether the file is stored locally or
     * offloaded to external storage. Honors all WordPress URL filtering including
     * offloader plugins and CDN configurations.
     *
     * @param int $attachment_id WordPress attachment post ID
     *
     * @return string|null Public URL if available, null on failure
     *
     * @since 1.0.0
     *
     * Resolution Strategy:
     * 1. **WordPress API**: Use wp_get_attachment_url() (honors all filters)
     * 2. **Metadata Fallback**: Reconstruct from attachment metadata if primary fails
     * 3. **Base URL Construction**: Combine upload dir with relative file path
     *
     * URL Sources (Priority Order):
     * - Offloader plugin URLs (S3, CloudFlare R2, etc.)
     * - CDN-transformed URLs
     * - Local WordPress upload URLs
     * - Reconstructed URLs from metadata
     *
     * Use Cases:
     * - Frontend audio player source URLs
     * - Download links for processed files
     * - API responses requiring public file access
     * - Email notifications with file links
     *
     * WordPress Integration:
     * - Respects wp_get_attachment_url filters
     * - Honors offloader plugin URL transformations
     * - Supports CDN and domain mapping plugins
     * - Automatically escapes URLs for security
     *
     * @return string Escaped public URL ready for HTML output
     * @return null If attachment not found or URL cannot be determined
     *
     * @example
     * ```php
     * $url = $service->star_get_public_url($attachment_id);
     * if ($url) {
     *     echo '<audio src="' . esc_attr($url) . '" controls></audio>';
     * }
     * ```
     */
    public function star_get_public_url(int $attachment_id): ?string
    {
        if ($attachment_id <= 0) {
            return null;
        }

        // Primary method: Let WordPress and its filters (like Offloader) resolve the URL.
        $url = wp_get_attachment_url($attachment_id);
        if ( ! empty($url)) {
            return esc_url_raw($url);
        }

        // Fallback method: Reconstruct URL from metadata if primary fails.
        $meta       = wp_get_attachment_metadata($attachment_id);
        $upload_dir = wp_get_upload_dir();

        if ( ! empty($meta['file'])) {
            $url = trailingslashit($upload_dir['baseurl']) . ltrim((string) $meta['file'], '/');
            return esc_url_raw($url);
        }

        return null;
    }
}
