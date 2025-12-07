<?php

/**
 * Handles all database and data persistence operations for Starmus.
 *
 * @package Starisian\Sparxstar\Starmus\core
 */
namespace Starisian\Sparxstar\Starmus\core;

if (! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;
use WP_Error;
use WP_Query;

/**
 * Data Access Layer implementation for Starmus Audio Recorder.
 *
 * This class provides concrete implementations for all database and file system
 * operations defined in the StarmusAudioRecorderDALInterface. It serves as the
 * primary abstraction layer between the plugin's business logic and WordPress
 * core database/media functions.
 *
 * The DAL handles:
 * - Audio recording post creation and management
 * - WordPress attachment operations and metadata
 * - User recording queries and pagination
 * - Post meta operations (including ACF integration)
 * - File path resolution and validation
 * - Rate limiting and security checks
 * - Taxonomy assignments for categorization
 *
 * All database operations include comprehensive error handling with logging
 * to ensure graceful degradation and debugging capabilities.
 *
 * @package Starisian\Sparxstar\Starmus\core
 *
 * @since   0.1.0
 *
 * @implements StarmusAudioRecorderDALInterface
 */
final class StarmusAudioRecorderDAL implements StarmusAudioRecorderDALInterface
{
    /*
    ------------------------------------*
     * 🧩 CREATION
     *------------------------------------*/

    /**
     * {@inheritdoc}
     */
    public function create_audio_post(string $title, string $cpt_slug, int $author_id): int|WP_Error
    {
        try {
            return wp_insert_post(
                [
                    'post_title'  => $title,
                    'post_type'   => $cpt_slug,
                    'post_status' => 'publish',
                    'post_author' => $author_id,
                ]
            );
        } catch (Throwable $throwable) {
            StarmusLogger::error('DAL', $throwable, ['phase' => 'create_audio_post']);
            return new WP_Error('create_post_failed', $throwable->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function create_attachment_from_file(string $file_path, string $filename): int|WP_Error
    {
        try {
            $attachment_id = wp_insert_attachment(
                [
                    'post_mime_type' => wp_check_filetype($filename)['type'] ?? '',
                    'post_title'     => pathinfo($filename, PATHINFO_FILENAME),
                    'post_status'    => 'inherit',
                ],
                $file_path
            );

            if (is_wp_error($attachment_id)) {
                return $attachment_id;
            }

            return $this->update_attachment_metadata($attachment_id, $file_path)
                ? $attachment_id
                : new WP_Error('metadata_failed', 'Metadata update failed.');
        } catch (Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase' => 'create_attachment_from_file',
                    'file'  => $file_path,
                ]
            );
            return new WP_Error('create_attachment_failed', $throwable->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function create_attachment_from_sideload(array $file_data): int|WP_Error
    {
        try {
            if (! \function_exists('media_handle_sideload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }

            $attachment_id = media_handle_sideload($file_data, 0);
            return is_wp_error($attachment_id) ? $attachment_id : $attachment_id;
        } catch (Throwable $throwable) {
            StarmusLogger::error('DAL', $throwable, ['phase' => 'create_attachment_from_sideload']);
            return new WP_Error('sideload_failed', $throwable->getMessage());
        }
    }

    /*
    ------------------------------------*
     * ⚙️ ATTACHMENT OPERATIONS
     *------------------------------------*/

    /**
     * Update the file path for an existing attachment.
     *
     * Updates the WordPress attachment's file path reference and MIME type.
     * Used when moving or processing files to update the attachment's
     * reference to point to the new file location.
     *
     * @since 0.1.0
     *
     * @param int $attachment_id The WordPress attachment ID to update.
     * @param string $file_path The new absolute file system path.
     *
     * @return bool True on successful update, false on failure.
     */
    public function update_attachment_file_path(int $attachment_id, string $file_path): bool
    {
        try {
            update_attached_file($attachment_id, $file_path);
            return (bool) wp_update_post(
                [
                    'ID'             => $attachment_id,
                    'post_mime_type' => 'audio/mpeg',
                ]
            );
        } catch (Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase'         => 'update_attachment_file_path',
                    'attachment_id' => $attachment_id,
                ]
            );
            return false;
        }
    }

    /**
     * Update attachment metadata for an existing file.
     *
     * Regenerates and updates WordPress attachment metadata including
     * file size, dimensions (for images), and other file-specific metadata.
     * Validates file existence before attempting metadata generation.
     *
     * @since 0.1.0
     *
     * @param int $attachment_id The WordPress attachment ID to update.
     * @param string $file_path The absolute file system path to analyze.
     *
     * @return bool True if metadata was successfully generated and updated, false on failure.
     */
    public function update_attachment_metadata(int $attachment_id, string $file_path): bool
    {
        try {
            if (! file_exists($file_path)) {
                return false;
            }

            wp_update_attachment_metadata(
                $attachment_id,
                wp_generate_attachment_metadata($attachment_id, $file_path)
            );
            return true;
        } catch (Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase'         => 'update_attachment_metadata',
                    'attachment_id' => $attachment_id,
                    'file'          => $file_path,
                ]
            );
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function set_attachment_parent(int $attachment_id, int $parent_post_id): bool
    {
        try {
            return (bool) wp_update_post(
                [
                    'ID'          => $attachment_id,
                    'post_parent' => $parent_post_id,
                ]
            );
        } catch (Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase'         => 'set_attachment_parent',
                    'attachment_id' => $attachment_id,
                    'parent'        => $parent_post_id,
                ]
            );
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete_attachment(int $attachment_id): void
    {
        try {
            wp_delete_attachment($attachment_id, true);
        } catch (Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase'         => 'delete_attachment',
                    'attachment_id' => $attachment_id,
                ]
            );
        }
    }

    /*
    ------------------------------------*
     * 📖 RETRIEVAL
     *------------------------------------*/

    /**
     * {@inheritdoc}
     */
    public function get_user_recordings(int $user_id, string $cpt_slug, int $posts_per_page = 10, int $paged = 1): WP_Query
    {
        try {
            return new WP_Query(
                [
                    'post_type'      => $cpt_slug,
                    'author'         => $user_id,
                    'posts_per_page' => $posts_per_page,
                    'paged'          => $paged,
                    'post_status'    => ['publish', 'draft', 'pending', 'private'],
                ]
            );
        } catch (Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase'   => 'get_user_recordings',
                    'user_id' => $user_id,
                ]
            );
            return new WP_Query();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get_post_info(int $post_id): ?array
    {
        try {
            $status = get_post_status($post_id);
            if (! $status) {
                return null;
            }

            return [
                'status' => $status,
                'type'   => get_post_type($post_id),
            ];
        } catch (Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase'   => 'get_post_info',
                    'post_id' => $post_id,
                ]
            );
            return null;
        }
    }

    /*
    ------------------------------------*
     * 🛠️ CONFIG & HELPERS
     *------------------------------------*/

    /**
     * {@inheritdoc}
     */
    public function get_ffmpeg_path(): ?string
    {
        $opt = trim((string) get_option('starmus_ffmpeg_path', ''));
        if ($opt && file_exists($opt)) {
            return $opt;
        }

        $env = getenv('FFMPEG_BIN');
        if ($env && file_exists($env)) {
            return $env;
        }

        $which = trim((string) shell_exec('command -v ffmpeg'));
        return $which ?: null;
    }

    /**
     * {@inheritdoc}
     */
    public function get_edit_page_url_admin(string $cpt_slug): string
    {
        return admin_url('edit.php?post_type=' . $cpt_slug);
    }

    /**
     * {@inheritdoc}
     */
    public function get_page_id_by_slug(string $slug): int
    {
        $page = get_page_by_path(sanitize_title($slug));
        return $page ? (int) $page->ID : 0;
    }

    /**
     * {@inheritdoc}
     */
    /**
     * Get WordPress page slug by its ID.
     *
     * Retrieves the URL slug for a WordPress page given its post ID.
     * Returns empty string if the page doesn't exist.
     *
     * @since 0.1.0
     *
     * @param int $id WordPress page/post ID.
     *
     * @return string Page slug, empty string if not found.
     */
    public function get_page_slug_by_id(int $id): string
    {
        $page = get_post($id);
        return $page ? $page->post_name : '';
    }

    /**
     * Check if a user is rate limited for submissions.
     *
     * Implements per-user rate limiting using WordPress transients.
     * Tracks submission attempts and blocks users who exceed the limit
     * within a one-minute window.
     *
     * @since 0.1.0
     *
     * @param int $user_id WordPress user ID to check.
     * @param int $limit Maximum allowed submissions per minute (default: 10).
     *
     * @return bool True if user is rate limited, false if within limits.
     */
    public function is_rate_limited(int $user_id, int $limit = 10): bool
    {
        $key   = 'starmus_rate_' . $user_id;
        $count = (int) get_transient($key);
        if ($count > $limit) {
            return true;
        }

        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return false;
    }

    /**
     * Get the DAL registration key for security validation.
     *
     * Returns the configured override key for DAL replacement validation.
     * Used in the handshake mechanism when external code attempts to
     * replace the default DAL implementation.
     *
     * @since 0.1.0
     *
     * @return string Registration key if defined, empty string otherwise.
     */
    public function get_registration_key(): string
    {
        return \defined('STARMUS_DAL_OVERRIDE_KEY') ? STARMUS_DAL_OVERRIDE_KEY : '';
    }

    /*
    ------------------------------------*
     * 💾 POST META & STATE
     *------------------------------------*/

    /**
     * {@inheritdoc}
     */
    public function update_audio_post_meta(int $post_id, string $meta_key, string $value): bool
    {
        try {
            return update_post_meta($post_id, $meta_key, $value) !== false;
        } catch (Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase'    => 'update_audio_post_meta',
                    'post_id'  => $post_id,
                    'meta_key' => $meta_key,
                ]
            );
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function save_post_meta(int $post_id, string $meta_key, mixed $value): void
    {
        try {
            if (\function_exists('update_field')) {
                update_field($meta_key, $value, $post_id);
            } else {
                update_post_meta($post_id, $meta_key, $value);
            }
        } catch (Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase'    => 'save_post_meta',
                    'meta_key' => $meta_key,
                    'post_id'  => $post_id,
                ]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function set_audio_state(int $attachment_id, string $state): void
    {
        try {
            $this->save_post_meta($attachment_id, '_audio_processing_state', $state);
        } catch (\Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase'         => 'set_audio_state',
                    'attachment_id' => $attachment_id,
                    'state'         => $state,
                ]
            );
            // Fallback: direct meta update
            try {
                update_post_meta($attachment_id, '_audio_processing_state', $state);
            } catch (\Throwable) {
                // swallow; we don't want processing to fatal because state couldn't be written
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function save_audio_outputs(int $post_id, ?string $waveform_json, ?string $mp3_path, ?string $wav_path): void
    {
        try {
            if ($waveform_json) {
                $this->save_post_meta($post_id, 'waveform_json', $waveform_json);
            }

            if ($mp3_path) {
                $this->save_post_meta($post_id, 'mastered_mp3', $mp3_path);
            }

            if ($wav_path) {
                $this->save_post_meta($post_id, 'archival_wav', $wav_path);
            }
        } catch (Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase'   => 'save_audio_outputs',
                    'post_id' => $post_id,
                ]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function persist_audio_outputs(int $attachment_id, string $mp3, string $wav): void
    {
        try {
            update_post_meta($attachment_id, '_audio_mp3_path', $mp3);
            update_post_meta($attachment_id, '_audio_wav_path', $wav);
            update_post_meta($attachment_id, '_starmus_archival_path', $wav);
        } catch (Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase'         => 'persist_audio_outputs',
                    'attachment_id' => $attachment_id,
                ]
            );
        }
    }

    /**
     * Record timestamp when ID3 tags were written to an audio file.
     *
     * Stores a MySQL timestamp indicating when ID3 metadata was last
     * written to the audio file. Used for audit trails and processing
     * status tracking.
     *
     * @since 0.1.0
     *
     * @param int $attachment_id WordPress attachment ID.
     *
     * @return void
     */
    /**
     * Record timestamp when ID3 tags were written to an audio file.
     *
     * @param int $attachment_id WordPress attachment ID.
     *
     * @return void
     */
    public function record_id3_timestamp(int $attachment_id): void
    {
        try {
            update_post_meta($attachment_id, '_audio_id3_written_at', current_time('mysql'));
        } catch (Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase'         => 'record_id3_timestamp',
                    'attachment_id' => $attachment_id,
                ]
            );
        }
    }

    /**
     * Set copyright source information for an audio attachment.
     *
     * Records the copyright source or attribution text for an audio file.
     * Used for legal compliance and metadata management.
     *
     * @since 0.1.0
     *
     * @param int $attachment_id WordPress attachment ID.
     * @param string $copyright_text Copyright notice or source attribution.
     *
     * @return void
     */
    /**
     * Set copyright source information for an audio attachment.
     *
     * @param int $attachment_id WordPress attachment ID.
     * @param string $copyright_text Copyright notice or source attribution.
     *
     * @return void
     */
    public function set_copyright_source(int $attachment_id, string $copyright_text): void
    {
        try {
            update_post_meta($attachment_id, '_audio_copyright_source', $copyright_text);
        } catch (Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase'         => 'set_copyright_source',
                    'attachment_id' => $attachment_id,
                ]
            );
        }
    }

    /**
     * Assign taxonomy terms to an audio recording post.
     *
     * Links language and recording type taxonomies to audio recording posts
     * for categorization and filtering. Only assigns terms if IDs are provided
     * to avoid overwriting existing assignments with null values.
     *
     * @since 0.1.0
     *
     * @param int $post_id WordPress post ID to assign terms to.
     * @param int|null $language_id Term ID from 'language' taxonomy (optional).
     * @param int|null $type_id Term ID from 'recording-type' taxonomy (optional).
     *
     * @return void
     */
    /**
     * Assign taxonomy terms to an audio recording post.
     *
     * @param int $post_id WordPress post ID to assign terms to.
     * @param int|null $language_id Term ID from 'language' taxonomy (optional).
     * @param int|null $type_id Term ID from 'recording-type' taxonomy (optional).
     *
     * @return void
     */
    public function assign_taxonomies(int $post_id, ?int $language_id = null, ?int $type_id = null): void
    {
        try {
            if ($language_id) {
                wp_set_object_terms($post_id, (int) $language_id, 'language', false);
            }

            if ($type_id) {
                wp_set_object_terms($post_id, (int) $type_id, 'recording-type', false);
            }
        } catch (Throwable $throwable) {
            StarmusLogger::error(
                'DAL',
                $throwable,
                [
                    'phase'   => 'assign_taxonomies',
                    'post_id' => $post_id,
                ]
            );
        }
    }
}
