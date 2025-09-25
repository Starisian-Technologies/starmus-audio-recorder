<?php
/**
 * Service class for post-save metadata processing of audio files.
 * Handles ID3 tag writing (including multiple transcription frames) and exposes hooks for extension.
 *
 * @package Starmus\services
 * @version 0.7.4
 */

namespace Starmus\services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AudioProcessingService {

	/**
	 * Main entry point for processing an audio attachment's metadata.
	 *
	 * @param int $attachment_id
	 * @return bool
	 */
	public function process_attachment( int $attachment_id ): bool {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return false;
		}

		// --- 1. Collect transcription data (delegated via filter) ---
		$transcriptions = apply_filters(
			'starmus_audio_transcribe',
			array(), // Default is an empty array.
			$attachment_id,
			$file_path
		);

		if ( ! empty( $transcriptions ) && is_array( $transcriptions ) ) {
			update_post_meta( $attachment_id, 'audio_transcriptions', $transcriptions );
			do_action( 'starmus_audio_transcribed', $attachment_id, $transcriptions );
		}

		// --- 2. Write all available metadata to the file's ID3 tags ---
		if ( $this->write_id3_tags( $attachment_id, $file_path ) ) {
			do_action( 'starmus_audio_id3_written', $attachment_id, $file_path );
		}

		return true;
	}

	/**
	 * Writes ID3 tags (title, artist, transcriptions, etc.) to the audio file.
	 */
	private function write_id3_tags( int $attachment_id, string $file_path ): bool {
		$attachment_post = get_post( $attachment_id );
		if ( ! $attachment_post ) {
			return false;
		}

		// --- Step 1: Baseline tags ---
		$tag_data = array(
			'title'   => array( $attachment_post->post_title ),
			'artist'  => array( get_the_author_meta( 'display_name', $attachment_post->post_author ) ),
			'album'   => array( get_bloginfo( 'name' ) ),
			'year'    => array( get_the_date( 'Y', $attachment_post ) ),
			'comment' => array( "Recorded via Starmus plugin. Attachment ID: {$attachment_id}." ),
		);

		// --- Step 2: Add transcription(s) as USLT frames ---
		$stored_transcriptions = get_post_meta( $attachment_id, 'audio_transcriptions', true );
		if ( ! empty( $stored_transcriptions ) && is_array( $stored_transcriptions ) ) {
			foreach ( $stored_transcriptions as $t ) {
				if ( empty( $t['text'] ) ) {
					continue;
				}
				// Note: 'unsynchronised_lyric' is the correct key for the getID3 library.
				$tag_data['unsynchronised_lyric'][] = array(
					'data'        => $t['text'],
					'description' => $t['desc'] ?? 'Transcription',
					'language'    => $t['lang'] ?? 'eng', // ISO 639-2 (3-letter) code
				);
			}
		}

		// --- Step 3: Allow external filters to add custom frames (e.g., TXXX for dialect) ---
		$tag_data = apply_filters( 'starmus_id3_tag_data', $tag_data, $attachment_id, $file_path );

		// --- Step 4: Write tags to the file ---
		$tagwriter                    = new \getid3_writetags();
		$tagwriter->filename          = $file_path;
		$tagwriter->tagformats        = array( 'id3v2.3' );
		$tagwriter->overwrite_tags    = true;
		$tagwriter->tag_encoding      = 'UTF-8';
		$tagwriter->remove_other_tags = true;
		$tagwriter->tag_data          = $tag_data;

		if ( $tagwriter->WriteTags() ) {
			return true;
		}

		return false;
	}
}
