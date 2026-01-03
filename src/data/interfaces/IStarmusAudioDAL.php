<?php

/**
 * Contract for the Audio Data Access Layer.
 *
 * @package Starisian\Sparxstar\Starmus\data\interfaces
 * @version 1.3.0
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\data\interfaces;

use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusBaseDAL;
use WP_Error;
use WP_Query;
use function defined;

if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * Defines operations specific to Audio Files and Recordings.
 * Extends the Base contract for Meta/Audit/Provenance.
 */
interface IStarmusAudioDAL extends IStarmusBaseDAL {


	// --- WRITE OPERATIONS ---
	public function create_audio_post(string $title, string $cpt_slug, int $author_id): int|WP_Error;

	public function create_transcription_post(int $audio_post_id, string $transcription_text, int $author_id): int|WP_Error;

	public function create_translation_post(int $audio_post_id, string $translation_text, int $author_id): int|WP_Error;

	public function create_attachment_from_file(string $file_path, string $filename): int|WP_Error;

	public function create_attachment_from_sideload(array $file_data): int|WP_Error;

	// --- SPECIFIC META OPERATIONS ---
	public function persist_audio_outputs(int $attachment_id, string $mp3, string $wav): void;

	public function save_audio_outputs(int $post_id, ?string $waveform_json, ?string $mp3_path, ?string $wav_path): void;

	public function set_audio_state(int $attachment_id, string $state): void;

	// --- LEGACY SUPPORT ---
	public function record_id3_timestamp(int $attachment_id): void;

	public function set_copyright_source(int $attachment_id, string $copyright_text): void;

	public function assign_taxonomies(int $post_id, ?int $language_id = null): void;

	// --- ATTACHMENT MANAGEMENT ---
	public function set_attachment_parent(int $attachment_id, int $parent_post_id): bool;

	public function update_attachment_file_path(int $attachment_id, string $file_path): bool;

	public function update_attachment_metadata(int $attachment_id, string $file_path): bool;

	public function delete_attachment(int $attachment_id): void;

	// --- QUERIES & UTILS ---
	public function get_user_recordings(int $user_id, string $cpt_slug, int $posts_per_page = 10, int $paged = 1): WP_Query;

	public function get_page_id_by_slug(string $slug): int;

	public function get_page_slug_by_id(int $id): string;

	public function is_rate_limited(int $user_id, int $limit = 10): bool;

	public function get_ffmpeg_path(): ?string;

	public function get_registration_key(): string;

	public function get_edit_page_url_admin(string $cpt_slug): string;
}
