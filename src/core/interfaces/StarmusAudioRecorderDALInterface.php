<?php

/**
 * Interface for the Data Access Layer of the Starmus Audio Recorder.
 *
 * @package Starisian\Sparxstar\Starmus\core\interfaces
 */
namespace Starisian\Sparxstar\Starmus\core\interfaces;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the contract for data access operations related to audio recordings.
 *
 * This interface abstracts the underlying data storage mechanism (e.g., WordPress database, custom tables)
 * and provides a consistent API for creating, retrieving, updating, and deleting audio recording data.
 */
interface StarmusAudioRecorderDALInterface {

	/**
	 * Creates a new audio post.
	 *
	 * @param string $title The title of the audio post.
	 * @param string $cpt_slug The custom post type slug.
	 * @param int $author_id The ID of the post author.
	 *
	 * @return int|\WP_Error The new post ID on success, or a WP_Error object on failure.
	 */
	public function create_audio_post( string $title, string $cpt_slug, int $author_id ): int|\WP_Error;

	/**
	 * Creates an attachment from a local file.
	 *
	 * @param string $file_path The path to the local file.
	 * @param string $filename The desired filename for the attachment.
	 *
	 * @return int|\WP_Error The new attachment ID on success, or a WP_Error object on failure.
	 */
	public function create_attachment_from_file( string $file_path, string $filename ): int|\WP_Error;

	/**
	 * Creates an attachment from a sideloaded file.
	 *
	 * @param array<string, mixed> $file_data The file data array (from $_FILES).
	 *
	 * @return int|\WP_Error The new attachment ID on success, or a WP_Error object on failure.
	 */
	public function create_attachment_from_sideload( array $file_data ): int|\WP_Error;

	/**
	 * Retrieves a user's recordings.
	 *
	 * @param int $user_id The ID of the user.
	 * @param string $cpt_slug The custom post type slug.
	 * @param int $posts_per_page The number of posts to retrieve per page.
	 * @param int $paged The current page number.
	 *
	 * @return \WP_Query A WP_Query object containing the user's recordings.
	 */
	public function get_user_recordings( int $user_id, string $cpt_slug, int $posts_per_page = 10, int $paged = 1 ): \WP_Query;

	/**
	 * Gets the URL for the admin edit page for a given CPT.
	 *
	 * @param string $cpt_slug The custom post type slug.
	 *
	 * @return string The URL of the edit page.
	 */
	public function get_edit_page_url_admin( string $cpt_slug ): string;

	/**
	 * Updates audio post meta.
	 *
	 * @param int $post_id The ID of the post.
	 * @param string $meta_key The meta key to update.
	 * @param string $value The new meta value.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function update_audio_post_meta( int $post_id, string $meta_key, string $value ): bool;

	/**
	 * Gets the path to the FFmpeg binary.
	 *
	 * @return string|null The path to the FFmpeg binary, or null if not found.
	 */
	public function get_ffmpeg_path(): ?string;

	/**
	 * Gets the registration key for DAL replacement.
	 *
	 * @return string The registration key.
	 */
	public function get_registration_key(): string;

	/**
	 * Sets the parent post for an attachment.
	 *
	 * @param int $attachment_id The ID of the attachment.
	 * @param int $parent_post_id The ID of the parent post.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function set_attachment_parent( int $attachment_id, int $parent_post_id ): bool;

	/**
	 * Deletes an attachment.
	 *
	 * @param int $attachment_id The ID of the attachment to delete.
	 */
	public function delete_attachment( int $attachment_id ): void;

	/**
	 * Gets a page ID by its slug.
	 *
	 * @param string $slug The slug of the page.
	 *
	 * @return int The ID of the page.
	 */
	public function get_page_id_by_slug( string $slug ): int;

	/**
	 * Gets a page slug by its ID.
	 *
	 * @param int $id The ID of the page.
	 *
	 * @return string The slug of the page.
	 */
	public function get_page_slug_by_id( int $id ): string;

	/**
	 * Checks if a user is rate-limited.
	 *
	 * @param int $user_id The ID of the user.
	 * @param int $limit The rate limit.
	 *
	 * @return bool True if the user is rate-limited, false otherwise.
	 */
	public function is_rate_limited( int $user_id, int $limit = 10 ): bool;

	/**
	 * Saves post meta data.
	 *
	 * @param int $post_id The ID of the post.
	 * @param string $meta_key The meta key.
	 * @param mixed $value The meta value.
	 */
	public function save_post_meta( int $post_id, string $meta_key, mixed $value ): void;

	/**
	 * Persists audio output file paths.
	 *
	 * @param int $attachment_id The ID of the attachment.
	 * @param string $mp3 The path to the MP3 file.
	 * @param string $wav The path to the WAV file.
	 */
	public function persist_audio_outputs( int $attachment_id, string $mp3, string $wav ): void;

	/**
	 * Saves audio output data.
	 *
	 * @param int $post_id The ID of the post.
	 * @param string|null $waveform_json The waveform data in JSON format.
	 * @param string|null $mp3_path The path to the MP3 file.
	 * @param string|null $wav_path The path to the WAV file.
	 */
	public function save_audio_outputs( int $post_id, ?string $waveform_json, ?string $mp3_path, ?string $wav_path ): void;

	/**
	 * Sets the processing state of an audio attachment.
	 *
	 * @param int $attachment_id The ID of the attachment.
	 * @param string $state The processing state.
	 */
	public function set_audio_state( int $attachment_id, string $state ): void;

	/**
	 * Gets information about a post.
	 *
	 * @param int $post_id The ID of the post.
	 *
	 * @return array{id: int, title: string, type: string, status: string}|null An array of post information, or null if not found.
	 */
	public function get_post_info( int $post_id ): ?array;

	/**
	 * Creates a transcription post linked to an audio recording.
	 *
	 * @param int $audio_post_id The ID of the parent audio recording.
	 * @param string $transcription_text The transcription text.
	 * @param int $author_id The ID of the author.
	 *
	 * @return int|\WP_Error The new transcription post ID on success, or WP_Error on failure.
	 */
	public function create_transcription_post( int $audio_post_id, string $transcription_text, int $author_id ): int|\WP_Error;

	/**
	 * Creates a translation post linked to an audio recording.
	 *
	 * @param int $audio_post_id The ID of the parent audio recording.
	 * @param string $translation_text The translation text.
	 * @param int $author_id The ID of the author.
	 *
	 * @return int|\WP_Error The new translation post ID on success, or WP_Error on failure.
	 */
	public function create_translation_post( int $audio_post_id, string $translation_text, int $author_id ): int|\WP_Error;
}
