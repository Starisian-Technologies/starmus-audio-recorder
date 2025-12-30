<?php
namespace Starisian\Sparxstar\Starmus\data;

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

if ( ! \defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class StarmusProsodyDAL
 *
 * Handles all reads/writes for the Starmus Script Prosody Engine.
 * Abstraction layer between ACF/WordPress DB and the Frontend App.
 *
 * @package Starisian\Sparxstar\Starmus\data
 */
class StarmusProsodyDAL {

	// Heuristic Constants for "Smart Guessing" pace
	private const BASE_SPEEDS = array(
		'announcer'      => 2200,
		'conversational' => 2800,
		'character'      => 3000,
		'narration'      => 3500,
		'default'        => 3000,
	);

	private const ENERGY_MODIFIERS = array(
		'high'    => 0.85, // Faster
		'neutral' => 1.0,  // Normal
		'low'     => 1.2,   // Slower
	);

	public function __construct() {
		// Intentionally left blank
	}

	/**
	 * Fetch the full data payload required by the JS Client.
	 *
	 * @param int $post_id
	 *
	 * @return array
	 */
	public function get_script_payload( int $post_id ): array {
		try {
			$post_id = (int) $post_id;

			$post = get_post( $post_id );

			if ( ! $post || $post->post_type !== 'starmus-script' ) {
				return array();
			}

			// 1. Get raw configuration fields
			$mode       = get_field( 'performance_mode', $post_id ) ?: 'conversational';
			$energy     = get_field( 'energy_level', $post_id ) ?: 'neutral';
			$density    = (int) get_field( 'visual_density', $post_id );
			$calibrated = (int) get_field( 'calibrated_pace_ms', $post_id );

			// 2. Calculate Pace (Heuristic vs Saved)
			$start_pace = $this->resolve_pace( $calibrated, $mode, $energy );

			// 3. Clean Text Streams
			$source_clean = $this->sanitize_stream( $post->post_content );
			$trans_clean  = $this->sanitize_stream( get_field( 'starmus_translation_text', $post_id ) ?: '' );
		} catch ( \Throwable $e ) {
			StarmusLogger::log( $e );
			return array();
		}
		// 4. Return Data Object
		return array(
			'postID'      => $post_id,
			'source'      => $source_clean,
			'translation' => $trans_clean,
			'startPace'   => $start_pace,
			'density'     => $density > 0 ? $density : 28, // Default if unset
			'mode'        => $mode,
			'energy'      => $energy,
			'nonce'       => wp_create_nonce( 'starmus_prosody_save_' . $post_id ),
		);
	}

	/**
	 * Updates the 'calibrated_pace_ms' field via AJAX.
	 * This is the ONLY write operation permitted by the frontend.
	 *
	 * @param int $post_id
	 * @param int $ms
	 *
	 * @return bool
	 */
	public function save_calibrated_pace( int $post_id, int $ms ): bool {
		// Sanity Bounds Check (1s to 6s)
		if ( $ms < 1000 || $ms > 6000 ) {
			return false;
		}

		// Update ACF Field
		return (bool) update_field( 'calibrated_pace_ms', $ms, $post_id );
	}

	/**
	 * Logic: If human set a pace, use it. Otherwise, guess based on metadata.
	 *
	 * @param int    $calibrated
	 * @param string $mode
	 * @param string $energy
	 *
	 * @return int
	 */
	private function resolve_pace( int $calibrated, string $mode, string $energy ): int {
		// A: Trust the Human
		if ( $calibrated > 0 ) {
			return $calibrated;
		}

		// B: Calculate Heuristic
		$base = self::BASE_SPEEDS[ $mode ] ?? self::BASE_SPEEDS['default'];
		$mod  = self::ENERGY_MODIFIERS[ $energy ] ?? 1.0;

		return (int) round( $base * $mod );
	}

	/**
	 * Clean text for the engine.
	 * Removes HTML, ensures single spacing, trims whitespace.
	 *
	 * @param string $raw
	 *
	 * @return string
	 */
	private function sanitize_stream( string $raw ): string {
		$text = wp_strip_all_tags( $raw );
		$text = str_replace( array( "\r", "\n" ), ' ', $text ); // Flatten newlines
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}
}
