<?php

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * StarmusFileService (DAL-integrated)
 * ----------------------------
 * - Guarantees local access for offloaded files.
 * - Routes all persistence and attachment updates through DAL.
 * - Supports external offloaders like WP Offload Media (AS3CF).
 *
 * @package Starisian\Sparxstar\Starmus\services
 *
 * @version 0.9.1-dal
 */
namespace Starisian\Sparxstar\Starmus\services;

if (! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;

final readonly class StarmusFileService
{
    /**
     * Data Access Layer instance.
     *
     * @var StarmusAudioRecorderDAL
     */
    private StarmusAudioRecorderDAL $dal;

    public function __construct(?StarmusAudioRecorderDAL $dal = null)
    {
        $this->dal = $dal ?: new StarmusAudioRecorderDAL();
    }

    /**
     * Guarantees a local copy of an attachment's file is available for processing.
     *
     * If offloaded, downloads it to a temp path and returns the local copy.
     * The caller is responsible for cleanup.
     */
    public function get_local_copy(int $attachment_id): ?string
    {
        $local_path = @get_attached_file($attachment_id);
        if ($local_path && file_exists($local_path)) {
            return $local_path;
        }

        $remote_url = wp_get_attachment_url($attachment_id);
        if (! $remote_url) {
            return null;
        }

        $remote_url = esc_url_raw($remote_url);

        if (! \function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $temp = download_url($remote_url);
        if (is_wp_error($temp)) {
            return null;
        }

        return $temp;
    }

    /**
     * Uploads or re-attaches a local file via offloader or DAL fallback.
     */
    public function upload_and_replace_attachment(int $attachment_id, string $local_file_path): bool
    {
        if (! file_exists($local_file_path)) {
            return false;
        }

        // Delegate to offloader if present
        if (\function_exists('as3cf_upload_attachment')) {
            $result = as3cf_upload_attachment($attachment_id, null, $local_file_path);
            return !is_wp_error($result);
        }

        // DAL-managed fallback
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

        return false;
    }

    /**
     * Returns the correct public URL for an attachment.
     * - Honors external offloaders (AS3CF, Cloudflare, etc.)
     * - Falls back to wp_get_attachment_url()
     * - Normalizes HTTPS and Base URL mismatches
     *
     * @param int $attachment_id Attachment ID to resolve URL for.
     *
     * @return string|null Public URL or null if attachment not found.
     */
    public function star_get_public_url(int $attachment_id): ?string
    {
        if ($attachment_id <= 0) {
            return null;
        }

        // First: Use WordPress's own resolver (may be offloaded/re-routed)
        $url = wp_get_attachment_url($attachment_id);
        if (! empty($url)) {
            return esc_url_raw($url);
        }

        // Second: If bypassed or metadata missing, reconstruct from metadata
        $meta       = wp_get_attachment_metadata($attachment_id);
        $upload_dir = wp_get_upload_dir();

        if (! empty($meta['file'])) {
            $url = trailingslashit($upload_dir['baseurl']) . ltrim((string) $meta['file'], '/');
            return esc_url_raw($url);
        }

        // Third: Last-chance fallback: GUID (rare, but safer than null)
        $guid = get_post_field('guid', $attachment_id);
        return empty($guid) ? null : esc_url_raw($guid);
    }
}
