<?php

/**
 * Schema Mapper for Starmus Audio System.
 *
 * Acts as a Key Translator for the NEW Schema.
 * Works in tandem with StarmusSanitizer to ensure LEGACY keys are also generated.
 *
 * @package Starisian\Sparxstar\Starmus\data\mappers
 * @version 1.1.1
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\data\mappers;

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;
use function get_current_user_id;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;
use function sanitize_text_field;
use function wp_unslash;

if ( ! defined('ABSPATH')) {
    exit;
}

class StarmusSchemaMapper
{

    /**
     * List of fields that map 1:1 from Frontend to ACF (New Schema).
     */
    private const PASSTHROUGH_ALLOWLIST = [
    // -- Group: aiwa_core_metadata --
    'stable_uri',
    'linked_data_uri',
    'dc_rights_type',
    'dc_rights',
    'dc_rights_geo',
    'dc_rights_royalty',
    'data_sensitivity_level',
    'anonymization_status',
    'copyright_licensee',

    // -- Group: session_meta --
    'project_collection_id',
    'accession_number',
    'session_start_time',
    'session_end_time',
    'location',
    'recording_equipment',
    'media_condition_notes',
    'usage_restrictions_rights',
    'access_level',
    'audio_quality_score_tax',
    'recording_metadata',

    // -- Group: shared_rights_credits --
    'copyright_status',
    'usage_constraints',

    // -- Group: processing --
    'explicit',
    'processing_log',

    // -- Group: agreement --
    'terms_type',
    'contributor_name',
    'contributor_user_agent',
    'submission_id',

    // -- Group: music_engineering --
    'sample_rate',
    'bit_depth',
    'tuning_hz',
    'channel_layout',

    // -- Group: music_composition --
    'bpm',
    'musical_key',
    'isrc_code',
    'integrated_lufs'
    ];

    /**
     * Maps form data to the ACF Schema structure.
     *
     * @param array<string, mixed> $data Raw or semi-sanitized form data.
     * @return array<string, mixed> Data ready for ACF saving.
     */
    public static function map_form_data(array $data): array
    {
        $mapped = [];

        try {
            $user_id = get_current_user_id();

            // 1. PROCESS PASSTHROUGH FIELDS
            foreach (self::PASSTHROUGH_ALLOWLIST as $key) {
                if (isset($data[$key])) {
                    $mapped[$key] = $data[$key];
                }
            }

            // 2. PROCESS COMPLEX MAPPINGS

            // DC Creator (Used by Sanitizer to generate _starmus_title)
            $mapped['dc_creator'] = empty($data['dc_creator'])
            ? $mapped['contributor_name'] ?? 'Unknown Creator'
            : (sanitize_text_field($data['dc_creator']));

            // User Links
            $mapped['copyright_licensor'] = $user_id;
            $mapped['authorized_user_id'] = $user_id;

            // Dates
            $mapped['dc_date_created'] = empty($data['date_created'])
            ? date('Ymd')
            : $data['date_created'];

            $mapped['session_date'] = empty($data['session_date'])
            ? date('Ymd')
            : $data['session_date'];

            // Geolocation (Used by Sanitizer to generate _starmus_geolocation)
            if ( ! empty($data['geolocation'])) {
                $mapped['gps_coordinates'] = $data['geolocation'];
            }

            // JSON Blobs (Safely handled)
            if ( ! empty($data['_starmus_env'])) {
                $mapped['environment_data'] = self::ensure_json_string($data['_starmus_env'], 'environment_data');

                // Extract Fingerprint
                $env_arr = self::decode_if_json($data['_starmus_env']);
                if (isset($env_arr['fingerprint'])) {
                    $mapped['device_fingerprint'] = $env_arr['fingerprint'];
                }
            }

            if ( ! empty($data['waveform_json'])) {
                $mapped['waveform_json'] = self::ensure_json_string($data['waveform_json'], 'waveform_json');
            }

            if ( ! empty($data['_starmus_calibration'])) {
                $mapped['transcriber'] = self::ensure_json_string($data['_starmus_calibration'], 'transcriber');
            }

            // Agreement Logic
            if ( ! empty($data['agreement'])) {
                $mapped['agreement_to_terms_toggle'] = 1;
                $mapped['agreement_datetime']        = date('Y-m-d H:i:s');
            }

            // IP Address
            if ( ! empty($data['ip_address'])) {
                $mapped['submission_ip']  = $data['ip_address'];
                $mapped['contributor_ip'] = $data['ip_address'];
            }

            // Taxonomies (Used by Sanitizer to generate _starmus_language/dialect)
            if ( ! empty($data['language'])) {
                $mapped['language'] = (int) $data['language'];
            }

            if ( ! empty($data['dialect'])) {
                $mapped['dialect'] = (int) $data['dialect'];
            }
        } catch (Throwable $throwable) {
            // Log critical failure but return what we have to prevent total data loss
            StarmusLogger::error('Mapper Critical Failure: ' . $throwable->getMessage());
            StarmusLogger::log($throwable);
        }

        return $mapped;
    }

    /**
     * Check if a specific field key should be treated as JSON.
     */
    public static function is_json_field(string $field_name): bool
    {
        return in_array($field_name, [
        'environment_data',
        'waveform_json',
        'transcriber',
        'school_reviewed',
        'parental_permission_slip',
        'contributor_verification',
        'transcription_json',
        'recording_metadata',
        ], true);
    }

    /**
     * Helper: Enforce JSON String with Error Logging.
     */
    private static function ensure_json_string(mixed $value, string $context = 'unknown'): string
    {
        if (is_array($value)) {
            $json = json_encode($value);
            if (false === $json) {
                StarmusLogger::error(sprintf('Mapper JSON Encode Failed (%s): ', $context) . json_last_error_msg());
                return '{}';
            }

            return $json;
        }

        if (is_string($value)) {
            json_decode(wp_unslash($value));
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }

            StarmusLogger::warning(sprintf('Mapper received invalid JSON string for (%s). Wrapping.', $context));
            return (string) json_encode(['raw_preserved' => $value]);
        }

        return '{}';
    }

    /**
     * Helper: Decode to Array safely.
     */
    private static function decode_if_json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode(wp_unslash($value), true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}

