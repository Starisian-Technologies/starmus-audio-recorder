<?php
/**
 * Handles all database and data persistence operations for Starmus.
 *
 * @package Starisian\Sparxstar\Starmus\core
 */

namespace Starisian\Sparxstar\Starmus\core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_Query;
use Throwable;
use Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

final class StarmusAudioRecorderDAL implements StarmusAudioRecorderDALInterface {

	/*
	------------------------------------*
	 * 🧩 CREATION
	 *------------------------------------*/
	public function create_audio_post( string $title, string $cpt_slug, int $author_id ): int|WP_Error {
		try {
			return wp_insert_post([
				'post_title'  => $title,
				'post_type'   => $cpt_slug,
				'post_status' => 'publish',
				'post_author' => $author_id,
			]);
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'create_audio_post'));
			return new WP_Error('create_post_failed', $e->getMessage());
		}
	}

	public function create_attachment_from_file( string $file_path, string $filename ): int|WP_Error {
		try {
			$attachment_id = wp_insert_attachment([
				'post_mime_type' => wp_check_filetype( $filename )['type'] ?? '',
				'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
				'post_status'    => 'inherit',
			], $file_path);

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			return $this->update_attachment_metadata( $attachment_id, $file_path )
				? $attachment_id
				: new WP_Error('metadata_failed', 'Metadata update failed.');
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'create_attachment_from_file', 'file' => $file_path));
			return new WP_Error('create_attachment_failed', $e->getMessage());
		}
	}

	public function create_attachment_from_sideload( array $file_data ): int|WP_Error {
		try {
			if ( ! function_exists('media_handle_sideload') ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}
			$attachment_id = media_handle_sideload( $file_data, 0 );
			return is_wp_error($attachment_id) ? $attachment_id : $attachment_id;
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'create_attachment_from_sideload'));
			return new WP_Error('sideload_failed', $e->getMessage());
		}
	}

	/*
	------------------------------------*
	 * ⚙️ ATTACHMENT OPERATIONS
	 *------------------------------------*/
	public function update_attachment_file_path( int $attachment_id, string $file_path ): bool {
		try {
			update_attached_file($attachment_id, $file_path);
			return (bool) wp_update_post([
				'ID'             => $attachment_id,
				'post_mime_type' => 'audio/mpeg',
			]);
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'update_attachment_file_path', 'attachment_id' => $attachment_id));
			return false;
		}
	}

	public function update_attachment_metadata( int $attachment_id, string $file_path ): bool {
		try {
			if ( ! file_exists($file_path) ) {
				return false;
			}
			wp_update_attachment_metadata(
				$attachment_id,
				wp_generate_attachment_metadata($attachment_id, $file_path)
			);
			return true;
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'update_attachment_metadata', 'attachment_id' => $attachment_id, 'file' => $file_path));
			return false;
		}
	}

	public function set_attachment_parent( int $attachment_id, int $parent_post_id ): bool {
		try {
			return (bool) wp_update_post([
				'ID'          => $attachment_id,
				'post_parent' => $parent_post_id,
			]);
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'set_attachment_parent', 'attachment_id' => $attachment_id, 'parent' => $parent_post_id));
			return false;
		}
	}

	public function delete_attachment( int $attachment_id ): void {
		try {
			wp_delete_attachment($attachment_id, true);
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'delete_attachment', 'attachment_id' => $attachment_id));
		}
	}

	/*
	------------------------------------*
	 * 🧠 POST & META OPERATIONS
	 *------------------------------------*/
	public function save_post_meta( int $post_id, string $meta_key, mixed $value ): void {
		try {
			if ( function_exists('update_field') ) {
				update_field($meta_key, $value, $post_id);
			} else {
				update_post_meta($post_id, $meta_key, $value);
			}
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'save_post_meta', 'meta_key' => $meta_key, 'post_id' => $post_id));
		}
	}

	public function update_audio_post_fields( int $audio_post_id, array $fields ): bool {
		try {
			if ( function_exists('update_field') ) {
				foreach ($fields as $key => $val) {
					update_field($key, $val, $audio_post_id);
				}
			} else {
				foreach ($fields as $key => $val) {
					update_post_meta($audio_post_id, $key, $val);
				}
			}
			return true;
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'update_audio_post_fields', 'post_id' => $audio_post_id));
			return false;
		}
	}

	public function update_audio_post_meta( int $post_id, string $meta_key, string $value ): bool {
		try {
			return update_post_meta($post_id, $meta_key, $value) !== false;
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'update_audio_post_meta', 'post_id' => $post_id, 'meta_key' => $meta_key));
			return false;
		}
	}

	public function persist_audio_outputs( int $attachment_id, string $mp3, string $wav ): void {
		try {
			update_post_meta($attachment_id, '_audio_mp3_path', $mp3);
			update_post_meta($attachment_id, '_audio_wav_path', $wav);
			update_post_meta($attachment_id, '_starmus_archival_path', $wav);
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'persist_audio_outputs', 'attachment_id' => $attachment_id));
		}
	}

	public function record_id3_timestamp( int $attachment_id ): void {
		try {
			update_post_meta($attachment_id, '_audio_id3_written_at', current_time('mysql'));
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'record_id3_timestamp', 'attachment_id' => $attachment_id));
		}
	}

	public function set_copyright_source( int $attachment_id, string $copyright_text ): void {
		try {
			update_post_meta($attachment_id, '_audio_copyright_source', $copyright_text);
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'set_copyright_source', 'attachment_id' => $attachment_id));
		}
	}

	/*
	------------------------------------*
	 * 🧭 TAXONOMIES + MEDIA LINKING
	 *------------------------------------*/
	public function assign_taxonomies( int $post_id, ?int $language_id = null, ?int $type_id = null ): void {
		try {
			if ( $language_id ) {
				wp_set_object_terms($post_id, (int)$language_id, 'language', false);
			}
			if ( $type_id ) {
				wp_set_object_terms($post_id, (int)$type_id, 'recording-type', false);
			}
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'assign_taxonomies', 'post_id' => $post_id));
		}
	}

	public function save_audio_outputs( int $post_id, ?string $waveform_json, ?string $mp3_path, ?string $wav_path ): void {
		try {
			if ( $waveform_json ) {
				$this->save_post_meta($post_id, 'waveform_json', $waveform_json);
			}
			if ( $mp3_path ) {
				$this->save_post_meta($post_id, 'mastered_mp3', $mp3_path);
			}
			if ( $wav_path ) {
				$this->save_post_meta($post_id, 'archival_wav', $wav_path);
			}
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'save_audio_outputs', 'post_id' => $post_id));
		}
	}

	/*
	------------------------------------*
	 * 🔍 LOOKUPS & HELPERS
	 *------------------------------------*/
	public function get_post_info( int $post_id ): ?array {
		try {
			$status = get_post_status($post_id);
			if ( ! $status ) {
				return null;
			}
			return [
				'status' => $status,
				'type'   => get_post_type($post_id),
			];
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'get_post_info', 'post_id' => $post_id));
			return null;
		}
	}

	public function get_user_recordings( int $user_id, string $cpt_slug, int $per_page = 10, int $paged = 1 ): WP_Query {
		try {
			return new WP_Query([
				'post_type'      => $cpt_slug,
				'author'         => $user_id,
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'post_status'    => ['publish', 'draft', 'pending', 'private'],
			]);
		} catch ( Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'get_user_recordings', 'user_id' => $user_id));
			return new WP_Query();
		}
	}

	public function get_edit_page_url_admin( string $cpt_slug ): string {
		return admin_url('edit.php?post_type=' . $cpt_slug);
	}

	public function get_page_id_by_slug( string $slug ): int {
		$page = get_page_by_path( sanitize_title($slug) );
		return $page ? (int) $page->ID : 0;
	}

	public function get_page_slug_by_id( int $id ): string {
		$page = get_post($id);
		return $page ? $page->post_name : '';
	}

	/*
	------------------------------------*
	 * 🧩 UTILITIES
	 *------------------------------------*/
	public function is_rate_limited( int $user_id, int $limit = 10 ): bool {
		$key   = 'starmus_rate_' . $user_id;
		$count = (int) get_transient($key);
		if ( $count > $limit ) {
			return true;
		}
		set_transient($key, $count + 1, MINUTE_IN_SECONDS);
		return false;
	}

	public function get_ffmpeg_path(): ?string {
		$opt = trim((string) get_option('starmus_ffmpeg_path', ''));
		if ( $opt && file_exists($opt) ) {
			return $opt;
		}
		$env = getenv('FFMPEG_BIN');
		if ( $env && file_exists($env) ) {
		 return $env;
		}
		$which = trim((string) shell_exec('command -v ffmpeg'));
		return $which ?: null;
	}

	public function get_registration_key(): string {
		return defined('STARMUS_DAL_OVERRIDE_KEY') ? STARMUS_DAL_OVERRIDE_KEY : '';
	}

	/**
	 * Set a short processing state on an attachment (centralized).
	 */
	public function set_audio_state( int $attachment_id, string $state ): void {
		try {
			$this->save_post_meta( $attachment_id, '_audio_processing_state', $state );
		} catch ( \Throwable $e ) {
			StarmusLogger::error('DAL', $e, array('phase' => 'set_audio_state', 'attachment_id' => $attachment_id, 'state' => $state));
			// Fallback: direct meta update
			try {
				update_post_meta( $attachment_id, '_audio_processing_state', $state );
			} catch ( \Throwable $_e ) {
				// swallow; we don't want processing to fatal because state couldn't be written
			}
		}
	}
}
