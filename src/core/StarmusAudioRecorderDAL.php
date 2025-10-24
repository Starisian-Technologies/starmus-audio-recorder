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

final class StarmusAudioRecorderDAL {

	/*
	------------------------------------*
	 * 🧩 CREATION
	 *------------------------------------*/

	public function create_audio_post( string $title, string $cpt_slug, int $author_id ): int|WP_Error {
		return wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => $cpt_slug,
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);
	}

	public function create_attachment_from_file( string $file_path, string $filename ): int|WP_Error {
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => wp_check_filetype( $filename )['type'] ?? '',
				'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
				'post_status'    => 'inherit',
			),
			$file_path
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return $this->update_attachment_metadata( $attachment_id, $file_path )
			? $attachment_id
			: new WP_Error( 'metadata_failed', 'Metadata update failed.' );
	}

	public function create_attachment_from_sideload( array $file_data ): int|WP_Error {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		$attachment_id = media_handle_sideload( $file_data, 0 );
		return is_wp_error( $attachment_id ) ? $attachment_id : $attachment_id;
	}

	/*
	------------------------------------*
	 * ⚙️ ATTACHMENT OPERATIONS
	 *------------------------------------*/

	public function update_attachment_file_path( int $attachment_id, string $file_path ): bool {
		update_attached_file( $attachment_id, $file_path );
		return (bool) wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => 'audio/mpeg',
			)
		);
	}

	public function update_attachment_metadata( int $attachment_id, string $file_path ): bool {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}
		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $file_path )
		);
		return true;
	}

	public function set_attachment_parent( int $attachment_id, int $parent_post_id ): bool {
		return (bool) wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_parent' => $parent_post_id,
			)
		);
	}

	public function delete_attachment( int $attachment_id ): void {
		wp_delete_attachment( $attachment_id, true );
	}

	/*
	------------------------------------*
	 * 🧠 POST & META OPERATIONS
	 *------------------------------------*/

	public function save_post_meta( int $post_id, string $meta_key, mixed $value ): void {
		if ( function_exists( 'update_field' ) ) {
			update_field( $meta_key, $value, $post_id );
		} else {
			update_post_meta( $post_id, $meta_key, $value );
		}
	}

	public function update_audio_post_fields( int $audio_post_id, array $fields ): bool {
		if ( function_exists( 'update_field' ) ) {
			foreach ( $fields as $key => $val ) {
				update_field( $key, $val, $audio_post_id );
			}
			return true;
		}
		foreach ( $fields as $key => $val ) {
			update_post_meta( $audio_post_id, $key, $val );
		}
		return true;
	}

	public function update_audio_post_meta( int $post_id, string $meta_key, string $value ): bool {
		return update_post_meta( $post_id, $meta_key, $value ) !== false;
	}

	public function persist_audio_outputs( int $attachment_id, string $mp3, string $wav ): void {
		update_post_meta( $attachment_id, '_audio_mp3_path', $mp3 );
		update_post_meta( $attachment_id, '_audio_wav_path', $wav );
		update_post_meta( $attachment_id, '_starmus_archival_path', $wav );
	}

	public function record_id3_timestamp( int $attachment_id ): void {
		update_post_meta( $attachment_id, '_audio_id3_written_at', current_time( 'mysql' ) );
	}

	public function set_copyright_source( int $attachment_id, string $copyright_text ): void {
		update_post_meta( $attachment_id, '_audio_copyright_source', $copyright_text );
	}

	/*
	------------------------------------*
	 * 🧭 LOOKUPS & HELPERS
	 *------------------------------------*/

	public function get_post_info( int $post_id ): ?array {
		$status = get_post_status( $post_id );
		if ( ! $status ) {
			return null;
		}
		return array(
			'status' => $status,
			'type'   => get_post_type( $post_id ),
		);
	}

	public function get_user_recordings( int $user_id, string $cpt_slug, int $per_page = 10, int $paged = 1 ): WP_Query {
		return new WP_Query(
			array(
				'post_type'      => $cpt_slug,
				'author'         => $user_id,
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			)
		);
	}

	public function get_edit_page_url_admin( string $cpt_slug ): string {
		return admin_url( 'edit.php?post_type=' . $cpt_slug );
	}

	public function get_page_id_by_slug( string $slug ): int {
		$page = get_page_by_path( sanitize_title( $slug ) );
		return $page ? (int) $page->ID : 0;
	}

	public function get_page_slug_by_id( int $id ): string {
		$page = get_post( $id );
		return $page ? $page->post_name : '';
	}

	/*
	------------------------------------*
	 * 🧩 UTILITIES
	 *------------------------------------*/

	public function is_rate_limited( int $user_id, int $limit = 10 ): bool {
		$key   = 'starmus_rate_' . $user_id;
		$count = (int) get_transient( $key );
		if ( $count > $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return false;
	}

	public function get_ffmpeg_path(): ?string {
		$opt = trim( (string) get_option( 'starmus_ffmpeg_path', '' ) );
		if ( $opt && file_exists( $opt ) ) {
			return $opt;
		}
		$env = getenv( 'FFMPEG_BIN' );
		if ( $env && file_exists( $env ) ) {
			return $env;
		}
		$which = trim( (string) shell_exec( 'command -v ffmpeg' ) );
		return $which ?: null;
	}
}
