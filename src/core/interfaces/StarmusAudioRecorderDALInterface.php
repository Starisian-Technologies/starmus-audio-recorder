<?php

namespace Starisian\Sparxstar\Starmus\core\interfaces;

if (! defined('ABSPATH')) {
	exit;
}

interface StarmusAudioRecorderDALInterface
{
	public function create_audio_post(string $title, string $cpt_slug, int $author_id): int;
	public function create_attachment_from_file(string $file_path, string $filename): int;
	public function create_attachment_from_sideload(array $file_data): int|\WP_Error;
	public function get_user_recordings(int $user_id, string $cpt_slug, int $posts_per_page = 10, int $paged = 1): \WP_Query;
	public function get_edit_page_url_admin(string $cpt_slug): string;
	public function update_audio_post_meta(int $post_id, string $meta_key, string $value): bool;
	public function get_ffmpeg_path(): ?string;
	public function get_registration_key(): string;
	public function set_attachment_parent(int $attachment_id, int $parent_post_id): bool;
	public function delete_attachment(int $attachment_id): void;
	public function get_page_id_by_slug(string $slug): int;
	public function get_page_slug_by_id(int $id): string;
	public function is_rate_limited(int $user_id, int $limit = 10): bool;

	// Persistence / helpers used by processing services
	public function save_post_meta(int $post_id, string $meta_key, mixed $value): void;
	public function persist_audio_outputs(int $attachment_id, string $mp3, string $wav): void;
	public function save_audio_outputs(int $post_id, ?string $waveform_json, ?string $mp3_path, ?string $wav_path): void;
	public function set_audio_state(int $attachment_id, string $state): void;
}
