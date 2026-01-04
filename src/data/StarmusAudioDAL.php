<?php

/**
 * Handles all database and data persistence operations for Starmus.
 *
 * @package Starisian\Sparxstar\Starmus\data
 *
 * @version 1.2.0
 */

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\data;

use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusAudioDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;
use WP_Error;
use WP_Query;

if ( ! \defined('ABSPATH')) {
    exit;
}

/**
 * Data Access Layer implementation for Starmus Audio Recorder.
 *
 * Concrete implementation of IStarmusAudioDAL.
 * Handles rigid database interactions with full legacy support.
 */
final class StarmusAudioDAL extends StarmusBaseDAL implements IStarmusAudioDAL
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
            $post_id = wp_insert_post(
                [
            'post_title'  => $title,
            'post_type'   => $cpt_slug,
            'post_status' => 'publish',
            'post_author' => $author_id,
                ],
                true
            );

            return is_wp_error($post_id) ? $post_id : (int) $post_id;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return new WP_Error('create_post_failed', $throwable->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function create_transcription_post(int $audio_post_id, string $transcription_text, int $author_id): int|WP_Error
    {
        try {
            $post_id = wp_insert_post(
                [
            'post_title'  => 'Transcription for Recording #' . $audio_post_id,
            'post_type'   => 'audio-transcription',
            'post_status' => 'publish',
            'post_author' => $author_id,
            'post_parent' => $audio_post_id,
                ],
                true
            );

            if (is_wp_error($post_id)) {
                return $post_id;
            }

            $this->save_post_meta((int) $post_id, 'audio', $audio_post_id);
            $this->save_post_meta((int) $post_id, 'transcription', $transcription_text);

            return (int) $post_id;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return new WP_Error('create_transcription_failed', $throwable->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function create_translation_post(int $audio_post_id, string $translation_text, int $author_id): int|WP_Error
    {
        try {
            $post_id = wp_insert_post(
                [
            'post_title'  => 'Translation for Recording #' . $audio_post_id,
            'post_type'   => 'audio-translation',
            'post_status' => 'publish',
            'post_author' => $author_id,
            'post_parent' => $audio_post_id,
                ],
                true
            );

            if (is_wp_error($post_id)) {
                return $post_id;
            }

            $this->save_post_meta((int) $post_id, 'audio', $audio_post_id);
            $this->save_post_meta((int) $post_id, 'translation', $translation_text);

            return (int) $post_id;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return new WP_Error('create_translation_failed', $throwable->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function create_attachment_from_file(string $file_path, string $filename): int|WP_Error
    {
        try {
            // Security: Check filetype
            $filetype = wp_check_filetype($filename, null);

            $attachment_id = wp_insert_attachment(
                [
            'post_mime_type' => $filetype['type'] ?: 'application/octet-stream',
            'post_title'     => pathinfo($filename, PATHINFO_FILENAME),
            'post_status'    => 'inherit',
                ],
                $file_path
            );

            if (is_wp_error($attachment_id)) {
                return $attachment_id;
            }

            return $this->update_attachment_metadata((int) $attachment_id, $file_path)
            ? (int) $attachment_id
            : new WP_Error('metadata_failed', 'Metadata update failed.');
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return new WP_Error('create_attachment_failed', $throwable->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function create_attachment_from_sideload(array $file_data): int|WP_Error
    {
        try {
            if ( ! \function_exists('media_handle_sideload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }

            $attachment_id = media_handle_sideload($file_data, 0);
            return is_wp_error($attachment_id) ? $attachment_id : (int) $attachment_id;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return new WP_Error('sideload_failed', $throwable->getMessage());
        }
    }

    /*
    ------------------------------------*
    * ⚙️ ATTACHMENT OPERATIONS
    *------------------------------------*/

    /**
     * {@inheritdoc}
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
            StarmusLogger::log($throwable);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function update_attachment_metadata(int $attachment_id, string $file_path): bool
    {
        try {
            if ( ! file_exists($file_path)) {
                return false;
            }

            // Required for wp_generate_attachment_metadata
            if ( ! \function_exists('wp_generate_attachment_metadata')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            wp_update_attachment_metadata(
                $attachment_id,
                wp_generate_attachment_metadata($attachment_id, $file_path)
            );
            return true;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
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
            StarmusLogger::log($throwable);
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
            StarmusLogger::log($throwable);
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
            'orderby'        => 'date',
            'order'          => 'DESC',
                ]
            );
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return new WP_Query();
        }
    }

    /**
     * {@inheritdoc}
     */

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
    public function get_page_slug_by_id(int $id): string
    {
        $page = get_post($id);
        return $page ? $page->post_name : '';
    }

    /**
     * {@inheritdoc}
     */
    public function is_rate_limited(int $user_id, int $limit = 10): bool
    {
        // Admins bypass rate limit
        if (user_can($user_id, 'manage_options')) {
            return false;
        }

        $key   = 'starmus_rate_' . $user_id;
        $count = (int) get_transient($key);

        if ($count >= $limit) {
            return true;
        }

        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return false;
    }

    /**
     * {@inheritdoc}
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
     * Updates audio post meta (Legacy Support).
     *
     * Preserved for backward compatibility, though new code should use save_post_meta.
     *
     * @param int $post_id Target Post ID.
     * @param string $meta_key Key.
     * @param string $value Value.
     */
    public function update_audio_post_meta(int $post_id, string $meta_key, string $value): bool
    {
        try {
            return update_post_meta($post_id, $meta_key, $value) !== false;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function set_audio_state(int $attachment_id, string $state): void
    {
        try {
            $this->save_post_meta($attachment_id, '_audio_processing_state', $state);
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
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
            StarmusLogger::log($throwable);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function persist_audio_outputs(int $attachment_id, string $mp3, string $wav): void
    {
        try {
            // Keep legacy meta for compatibility
            update_post_meta($attachment_id, '_audio_mp3_path', $mp3);
            update_post_meta($attachment_id, '_audio_wav_path', $wav);
            update_post_meta($attachment_id, '_starmus_archival_path', $wav);
        } catch (Throwable $throwable) {
            StarmusLogger::log(
                $throwable,
                [
            'phase'         => 'DAL persist_audio_outputs',
            'attachment_id' => $attachment_id,
                ]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function record_id3_timestamp(int $attachment_id): void
    {
        try {
            update_post_meta($attachment_id, '_audio_id3_written_at', gmdate('Y-m-d H:i:s'));
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function set_copyright_source(int $attachment_id, string $copyright_text): void
    {
        try {
            update_post_meta($attachment_id, '_audio_copyright_source', $copyright_text);
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function assign_taxonomies(int $post_id, ?int $language_id = null): void
    {
        try {
            if ($language_id) {
                wp_set_object_terms($post_id, (int) $language_id, 'language', false);
            }
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }
}
