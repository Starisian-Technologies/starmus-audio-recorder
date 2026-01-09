<?php

/**
 * Handles all reads/writes for the Starmus Script Prosody Engine.
 *
 * Abstraction layer between ACF/WordPress DB and the Frontend App.
 * Implements strict contracts for deterministic behavior.
 *
 * @package Starisian\Sparxstar\Starmus\data
 *
 * @version 1.1.0
 */

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\data;

use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusProsodyDAL;
use Starisian\Sparxstar\Starmus\data\StarmusBaseDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusSanitizer;
use Throwable;

if ( ! \defined('ABSPATH')) {
    exit;
}

final class StarmusProsodyDAL extends StarmusBaseDAL implements IStarmusProsodyDAL
{
    /**
     * Heuristic Constants for "Smart Guessing" pace.
     * Kept identical to legacy for backward compatibility.
     */
    private const BASE_SPEEDS = [
    'announcer'      => 2200,
    'conversational' => 2800,
    'character'      => 3000,
    'narration'      => 3500,
    'default'        => 3000,
    ];

    /**
     * Energy modifiers.
     * Kept identical to legacy for backward compatibility.
     */
    private const ENERGY_MODIFIERS = [
    'high'    => 0.85, // Faster (Lower ms/line)
    'neutral' => 1.0,  // Normal
    'low'     => 1.2,  // Slower (Higher ms/line)
    ];

    // --- PROSODY SPECIFIC OPERATIONS ---

    /**
     * {@inheritdoc}
     */
    public function get_script_payload(int $post_id): array
    {
        try {
            // 1. Validate Post Type (Legacy Requirement)
            // Uses inherited get_post_info from StarmusBaseDAL
            $post_info = $this->get_post_info($post_id);
            if ( ! $post_info || 'starmus-script' !== $post_info['type']) {
                return [];
            }

            // 2. Get Sanitized Settings via Helper
            // This cleans mode, energy, density, and calibrated values
            $data = StarmusSanitizer::get_sanitized_prosody_data($post_id);

            // 3. Calculate Pace
            // If sanitizer returned empty (failure), fallback safely
            $calib  = $data['calibrated_pace_ms'] ?? 0;
            $mode   = $data['performance_mode'] ?? 'conversational';
            $energy = $data['energy_level'] ?? 'neutral';
            $dens   = $data['visual_density'] ?? 28;

            $start_pace = $this->resolve_pace($calib, $mode, $energy);

            // 4. Retrieve and Clean Text Content
            // We access raw post content here as it is the "Source of Truth" for the script
            $post_object = get_post($post_id);
            $source_text = $post_object ? $post_object->post_content : '';

            // We use get_post_meta via our strict inherited method
            $trans_text = (string) $this->get_post_meta($post_id, 'starmus_translation_text');

            return [
            'postID'      => $post_id,
            'source'      => $this->sanitize_stream($source_text),
            'translation' => $this->sanitize_stream($trans_text),
            'startPace'   => $start_pace,
            'density'     => $dens > 0 ? $dens : 28,
            'mode'        => $mode,
            'energy'      => $energy,
            'nonce'       => wp_create_nonce('starmus_prosody_save_' . $post_id),
            ];
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function save_calibrated_pace(int $post_id, int $ms_per_word): bool
    {
        // Legacy Sanity Bounds Check (1s to 6s)
        if ($ms_per_word < 1000 || $ms_per_word > 6000) {
            return false;
        }

        // Use internal strict save method from StarmusBaseDAL
        // This respects ACF/Native logic and Error Logging
        return $this->save_post_meta($post_id, 'calibrated_pace_ms', $ms_per_word);
    }

    // --- INTERNAL LOGIC ---

    /**
     * Logic: If human set a pace, use it. Otherwise, guess based on metadata.
     * Kept exactly as legacy implementation to ensure consistent user experience.
     */
    private function resolve_pace(int $calibrated, string $mode, string $energy): int
    {
        // A: Trust the Human
        if ($calibrated > 0) {
            return $calibrated;
        }

        // B: Calculate Heuristic (Legacy Math)
        $base = self::BASE_SPEEDS[$mode] ?? self::BASE_SPEEDS['default'];
        $mod  = self::ENERGY_MODIFIERS[$energy] ?? 1.0;

        return (int) round($base * $mod);
    }

    /**
     * Clean text for the engine.
     * Removes HTML, ensures single spacing, trims whitespace.
     */
    private function sanitize_stream(string $raw): string
    {
        $text = wp_strip_all_tags($raw);
        $text = str_replace(["\r", "\n"], ' ', $text); // Flatten newlines
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
