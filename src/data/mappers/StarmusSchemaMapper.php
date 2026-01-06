<?php

/**
 * Schema Mapper for Starmus Audio System.
 *
 * Acts as a Key Translator for the NEW Schema (starmus_ prefix).
 * Maintains legacy method signatures for backward compatibility.
 *
 * @package Starisian\Sparxstar\Starmus\data\mappers
 * @version 2.0.0
 */

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\data\mappers;

use function get_current_user_id;
use function get_posts;
use function is_wp_error;
use function wp_insert_post;
use function json_decode;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;
use function sanitize_text_field;
use function date;
use function sprintf;
use function in_array;
use function is_array;
use function is_string;

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;

use function wp_unslash;

if ( ! \defined('ABSPATH')) {
    exit;
}

class StarmusSchemaMapper
{
    /**
     * MAPPING DEFINITION: 'Old_Frontend_Key' => 'New_Starmus_Key'
     * This replaces the old PASSTHROUGH_ALLOWLIST with a translation layer.
     */
    private const FIELD_MAP = [
        // -- Core --
        'stable_uri'                => 'starmus_stable_uri',
        'linked_data_uri'           => 'starmus_linked_data_uri',
        'dc_rights_type'            => 'starmus_rights_type',
        'dc_rights'                 => 'starmus_rights_use',
        'data_sensitivity_level'    => 'starmus_data_sensitivity',
        'anonymization_status'      => 'starmus_anon_status',
        'copyright_licensee'        => 'starmus_copyright_licensee',

        // -- Session --
        'project_collection_id'     => 'starmus_project_collection_id',
        'accession_number'          => 'starmus_accession_number',
        'session_start_time'        => 'starmus_session_start_time',
        'session_end_time'          => 'starmus_session_end_time',
        'location'                  => 'starmus_session_location',
        'recording_equipment'       => 'starmus_recording_equipment',
        'media_condition_notes'     => 'starmus_media_condition',
        'usage_restrictions_rights' => 'starmus_rights_use',
        'access_level'              => 'starmus_access_level',
        'recording_metadata'        => 'starmus_processing_log',

        // -- Rights --
        'copyright_status'          => 'starmus_copyright_status',
        'usage_constraints'         => 'starmus_usage_constraints',

        // -- Processing --
        'explicit'                  => 'starmus_is_explicit',
        'processing_log'            => 'starmus_processing_log',
        'school_reviewed'           => 'starmus_school_reviewed',
        'contributor_verification'  => 'starmus_contributor_verification',
        'qa_review'                 => 'starmus_qa_review',

        // -- Agreement --
        'terms_type'                => 'starmus_terms_type',
        'submission_id'             => 'starmus_submission_id',
        'contributor_user_agent'    => 'starmus_agree_ua',
        'ip_address'                => 'starmus_agree_ip',

        // -- Music --
        'sample_rate'               => 'starmus_sample_rate',
        'bit_depth'                 => 'starmus_bit_depth',
        'tuning_hz'                 => 'starmus_tuning_hz',
        'channel_layout'            => 'starmus_channel_layout',
        'bpm'                       => 'starmus_bpm',
        'musical_key'               => 'starmus_musical_key',
        'isrc_code'                 => 'starmus_isrc_code',
        'integrated_lufs'           => 'starmus_integrated_lufs',

        // -- Transcription & Translation --
        'transcription'             => 'starmus_transcription_text',
        'transcription_json'        => 'starmus_transcription_json',
        'translation'               => 'starmus_translation_text',
        'translation_language'      => 'starmus_translation_language',
        'original_language'         => 'starmus_original_language',
        'back_translation_text'     => 'starmus_back_translation_text',
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
            
            // Resolve Contributor ID (Post Object logic)
            $contributor_post_id = self::get_contributor_id_for_user($user_id);

            // 1. PROCESS DIRECT MAPPINGS
            foreach (self::FIELD_MAP as $old_key => $new_key) {
                if (isset($data[$old_key])) {
                    $mapped[$new_key] = $data[$old_key];
                }
            }

            // 2. PROCESS COMPLEX LOGIC

            // DC Creator
            $mapped['starmus_dc_creator'] = empty($data['dc_creator'])
                ? $data['contributor_name'] ?? 'Unknown Creator'
                : sanitize_text_field($data['dc_creator']);

            // User Linking -> Contributor Post Objects
            if ($contributor_post_id) {
                $mapped['starmus_copyright_licensor']   = $contributor_post_id;
                $mapped['starmus_authorized_signatory'] = $contributor_post_id;
                $mapped['starmus_subject_contributor']  = $contributor_post_id;
            }

            // Dates
            $mapped['starmus_date_created'] = empty($data['date_created'])
                ? date('Ymd')
                : $data['date_created'];

            $mapped['starmus_session_date'] = empty($data['session_date'])
                ? date('Ymd')
                : $data['session_date'];

            // Geolocation
            if (!empty($data['geolocation'])) {
                $mapped['starmus_session_gps'] = $data['geolocation'];
                $mapped['starmus_agree_geo']   = $data['geolocation'];
            }

            // Agreement Logic
            if (!empty($data['agreement'])) {
                $mapped['starmus_agreement_datetime'] = date('Y-m-d H:i:s');
            }

            // JSON Blobs (Safe Handling)
            if (!empty($data['_starmus_env'])) {
                $mapped['starmus_environment_data'] = self::ensure_json_string($data['_starmus_env'], 'env');
                
                // Extract Fingerprint
                $env_arr = self::decode_if_json($data['_starmus_env']);
                if (isset($env_arr['fingerprint'])) {
                    $mapped['starmus_device_fingerprint'] = $env_arr['fingerprint'];
                }
            }

            if (!empty($data['waveform_json'])) {
                $mapped['starmus_waveform_json'] = self::ensure_json_string($data['waveform_json'], 'waveform');
            }

            if (!empty($data['_starmus_calibration'])) {
                // Wrap legacy calibration/transcriber value into JSON object under "transcriber" key
                $transcriber_meta = [
                    'transcriber' => $data['_starmus_calibration'],
                ];
                $mapped['starmus_transcriber_metadata'] = self::ensure_json_string($transcriber_meta, 'transcriber');
            }

            if (!empty($data['parental_permission_slip'])) {
                // Wrap legacy URL in JSON using safe JSON encoder
                $mapped['starmus_parental_permission_slip'] = self::ensure_json_string(
                    [
                        'file_url' => $data['parental_permission_slip'],
                        'type'     => 'legacy_frontend_upload',
                    ],
                    'parental_permission_slip'
                );
            }

            // Taxonomies
            if (!empty($data['language'])) {
                $mapped['starmus_tax_language'] = (int) $data['language'];
            }

            if (!empty($data['dialect'])) {
                $mapped['starmus_tax_dialect'] = (int) $data['dialect'];
            }

        } catch (Throwable $throwable) {
            StarmusLogger::error('Mapper Critical Failure: ' . $throwable->getMessage());
            StarmusLogger::log($throwable);
        }

        return $mapped;
    }

    /**
     * Check if a specific field key should be treated as JSON.
     * UPDATED: Now checks against NEW Starmus keys.
     */
    public static function is_json_field(string $field_name): bool
    {
        return in_array($field_name, [
            'starmus_environment_data',
            'starmus_waveform_json',
            'starmus_transcriber_metadata',
            'starmus_school_reviewed',
            'starmus_parental_permission_slip',
            'starmus_contributor_verification',
            'starmus_transcription_json',
            'starmus_processing_log',
            'starmus_asset_audit_log',
            'starmus_revision_history_json'
        ], true);
    }

    /**
     * Helper: Find the SparxStar Contributor Post for a given User ID.
     */
    private static function get_contributor_id_for_user(int $user_id): ?int
    {
        if ($user_id === 0) {
            return null;
        }

        $args = [
            'post_type'       => 'sparx_contributor',
            'meta_query'      => [
                [
                    'key'   => 'sparxstar_wp_user',
                    'value' => $user_id,
                ],
            ],
            'posts_per_page'  => 1,
            'fields'          => 'ids',
            'post_status'     => 'any',
        ];

        $posts = get_posts($args);

        if (is_wp_error($posts)) {
            StarmusLogger::error(
                sprintf(
                    'Failed to load sparx_contributor for user_id %d: %s',
                    $user_id,
                    $posts->get_error_message()
                )
            );
            return null;
        }
        if (!empty($posts)) {
            return $posts[0];
        }

        // No existing contributor found for this user; mapper remains read-only.
        // Creation of contributor posts must be handled by a dedicated service.
        return null;
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
            // Validation-only decode: we intentionally ignore the decoded value and
            // return the original string on success to preserve exact formatting.
            json_decode(wp_unslash($value));
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
            // Strict legacy handling: Wrap invalid string in JSON object
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
