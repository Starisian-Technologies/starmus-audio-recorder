<?php
namespace Starisian\Sparxstar\Starmus\core\interfaces;

if( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface StarmusAudioRecorderDALInterface {
	public function create_audio_post( string $title, string $cpt_slug, int $author_id );
	public function create_attachment_from_file( string $file_path, string $filename );
	public function get_user_recordings( int $user_id, string $cpt_slug, int $posts_per_page = 10, int $paged = 1 );
	public function get_edit_page_url_admin( string $cpt_slug ): string;
	public function update_audio_post_meta( int $post_id, string $meta_key, string $value ): bool;
	public function get_ffmpeg_path(): ?string;
	public function get_registration_key(): string;

	// Persistence / helpers used by processing services
	public function save_post_meta( int $post_id, string $meta_key, mixed $value ): void;
	public function persist_audio_outputs( int $attachment_id, string $mp3, string $wav ): void;
	public function save_audio_outputs( int $post_id, ?string $waveform_json, ?string $mp3_path, ?string $wav_path ): void;
	public function set_audio_state( int $attachment_id, string $state ): void;
}
