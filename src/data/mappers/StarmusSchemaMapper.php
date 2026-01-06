<?php

/**
 * Schema Mapper for Starmus Audio System.
 *
 * Acts as a Key Translator for the NEW Schema (starmus_ prefix).
 * Maintains legacy method signatures for backward compatibility.
 * Ensures 1:1 data mapping for all unique fields.
 *
 * @package Starisian\Sparxstar\Starmus\data\mappers
 * @version 2.0.4
 */

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\data\mappers;

use function get_current_user_id;
use function get_posts;
use function get_userdata;
use function is_wp_error;
use function wp_insert_post;
use function update_field;
use function json_decode;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;
use function sanitize_text_field;
use function date;
use function gmdate;
use function strtotime;
use function sprintf;
use function in_array;
use function is_array;
use function is_string;
use function is_numeric;
use function explode;
use function trim;
use function array_unique;
use function array_merge;

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
     * 
     * Direct 1:1 mappings. Replaces the old PASSTHROUGH_ALLOWLIST.
     * Note: Dates, Users, and JSON blobs are handled specifically in map_form_data(), 
     * but are listed here for completeness.
     */
    private const FIELD_MAP = [
        // -- Core Archival --
        'global_uuid'               => 'starmus_global_uuid',
        'stable_uri'                => 'starmus_stable_uri',
        'linked_data_uri'           => 'starmus_linked_data_uri',
        'dc_rights_type'            => 'starmus_rights_type',
        'dc_rights'                 => 'starmus_rights_use',
        'dc_rights_geo'             => 'starmus_rights_geo',
        'dc_rights_royalty'         => 'starmus_rights_royalty',
        'data_sensitivity_level'    => 'starmus_data_sensitivity',
        'anonymization_status'      => 'starmus_anon_status',
        'consent_scope'             => 'starmus_consent_scope',
        'copyright_licensee'        => 'starmus_copyright_licensee',

        // -- Session Metadata --
        'project_collection_id'     => 'starmus_project_collection_id',
        'accession_number'          => 'starmus_accession_number',
        'session_start_time'        => 'starmus_session_start_time',
        'session_end_time'          => 'starmus_session_end_time',
        'location'                  => 'starmus_session_location',
        'gps_coordinates'           => 'starmus_session_gps',
        'recording_equipment'       => 'starmus_recording_equipment',
        'audio_files_originals'     => 'starmus_audio_files_originals',
        'media_condition_notes'     => 'starmus_media_condition',
        'usage_restrictions_rights' => 'starmus_rights_use',
        'access_level'              => 'starmus_access_level',
        'audio_quality_score_tax'   => 'starmus_audio_quality_score',
        
        // -- Technical Data (Distinct Fields) --
        'recording_metadata'        => 'starmus_recording_metadata', // Technical Session JSON
        'processing_log'            => 'starmus_processing_log',     // Audit Trail JSON

        // -- Rights & Credits --
        'copyright_status'          => 'starmus_copyright_status',
        'usage_constraints'         => 'starmus_usage_constraints',

        // -- Processing Status --
        'explicit'                  => 'starmus_is_explicit',
        'is_music'                  => 'starmus_is_music',
        'school_reviewed'           => 'starmus_school_reviewed',
        'contributor_verification'  => 'starmus_contributor_verification',
        'qa_review'                 => 'starmus_qa_review',
        'waveform_json'             => 'starmus_waveform_json',
        'original_source'           => 'starmus_original_source',
        'archival_wav'              => 'starmus_archival_wav',
        'mastered_mp3'              => 'starmus_mastered_mp3',
        'cloud_object_uri'          => 'starmus_cloud_object_uri',
        'device_fingerprint'        => 'starmus_device_fingerprint',
        'environment_data'          => 'starmus_environment_data',

        // -- Agreement --
        'terms_type'                => 'starmus_terms_type',
        'submission_id'             => 'starmus_submission_id',
        'contributor_signature'     => 'starmus_contributor_signature',
        'contributor_user_agent'    => 'starmus_agree_ua',
        'ip_address'                => 'starmus_agree_ip',
        'submission_ip'             => 'starmus_agree_ip', // Legacy alias
        'contributor_ip'            => 'starmus_agree_ip', // Legacy alias
        'contributor_geolocation'   => 'starmus_agree_geo',

        // -- Music Engineering --
        'sample_rate'               => 'starmus_sample_rate',
        'bit_depth'                 => 'starmus_bit_depth',
        'tuning_hz'                 => 'starmus_tuning_hz',
        'channel_layout'            => 'starmus_channel_layout',
        
        // -- Music Composition --
        'bpm'                       => 'starmus_bpm',
        'musical_key'               => 'starmus_musical_key',
        'isrc_code'                 => 'starmus_isrc_code',
        'integrated_lufs'           => 'starmus_integrated_lufs',
        'stems_cloud_uri'           => 'starmus_stems_cloud_uri',
        'daw_project_uri'           => 'starmus_daw_project_uri',

        // -- Release --
        'upc_code'                  => 'starmus_upc_code',
        'catalog_number'            => 'starmus_catalog_number',
        'label_name'                => 'starmus_label_name',

        // -- Transcription & Translation --
        'transcription'             => 'starmus_transcription_text', // The visual text
        'transcription_json'        => 'starmus_transcription_json', // The timestamps
        'translation'               => 'starmus_translation_text',
        'translation_language'      => 'starmus_translation_language',
        'original_language'         => 'starmus_original_language',
        'back_translation_text'     => 'starmus_back_translation_text',
        'transcription_hash'        => 'starmus_transcription_hash',
        'translation_hash'          => 'starmus_translation_hash',
        'audio_recording_parent'    => 'starmus_linked_audio', // The link to parent
        'transcription_parent'      => 'starmus_transcription_parent', // Link for translation
    ];

    /**
     * Maps form data to the ACF Schema structure.
     *
     * Translates legacy frontend field keys (e.g. 'location') into the new
     * Starmus database schema keys (e.g. 'starmus_session_location').
     * 
     * @param array<string, mixed> $data Raw or semi-sanitized form data.
     * @return array<string, mixed> Data ready for ACF saving.
     */
    public static function map_form_data(array $data): array
    {
        $mapped = [];

        try {
            $user_id = get_current_user_id();
            
            // 1. PROCESS DIRECT MAPPINGS (Simple Renames)
            foreach (self::FIELD_MAP as $old_key => $new_key) {
                // If the frontend sent the OLD name, map it
                if (isset($data[$old_key])) {
                    $mapped[$new_key] = $data[$old_key];
                }
                // If the frontend sent the NEW name (already refactored), keep it
                elseif (isset($data[$new_key])) {
                    $mapped[$new_key] = $data[$new_key];
                }
            }

            // 2. PROCESS COMPLEX LOGIC & RELATIONSHIPS

            // DC Creator (Fallback Logic)
            $mapped['starmus_dc_creator'] = empty($data['dc_creator'])
                ? $data['contributor_name'] ?? 'Unknown Creator'
                : sanitize_text_field($data['dc_creator']);

            // --- User ID to Contributor Post ID Conversions ---
            // Takes specific User ID from form, finds Post Object, assigns to new Key.

            // A. Copyright Licensor
            if (!empty($data['copyright_licensor'])) {
                $licensor_id = self::get_contributor_id_for_user((int)$data['copyright_licensor']);
                if ($licensor_id) {
                    $mapped['starmus_copyright_licensor'] = $licensor_id;
                }
            }

            // B. Authorized Signatory
            if (!empty($data['authorized_user_id'])) {
                $signatory_id = self::get_contributor_id_for_user((int)$data['authorized_user_id']);
                if ($signatory_id) {
                    $mapped['starmus_authorized_signatory'] = $signatory_id;
                }
            }

            // C. Subject / Main Contributor
            $subject_uid = $data['subject_user_id'] ?? $data['contributor_id'] ?? null;
            if (!empty($subject_uid)) {
                $subject_id = self::get_contributor_id_for_user((int)$subject_uid);
                if ($subject_id) {
                    $mapped['starmus_subject_contributor'] = $subject_id;
                }
            }

            // D. Interviewers / Recorders (Array of IDs)
            if (!empty($data['interviewers_recorders'])) {
                $raw_ids = is_array($data['interviewers_recorders']) 
                    ? $data['interviewers_recorders'] 
                    : explode(',', (string)$data['interviewers_recorders']);
                
                $mapped_ids = [];
                foreach ($raw_ids as $uid) {
                    $cid = self::get_contributor_id_for_user((int)trim((string)$uid));
                    if ($cid) {
                        $mapped_ids[] = $cid;
                    }
                }
                if (!empty($mapped_ids)) {
                    $mapped['starmus_interviewers_recorders'] = $mapped_ids;
                }
            }

            // --- DATES (Store as UTC, Sanitize to Ymd for ACF) ---
            
            $created_ts = !empty($data['date_created']) ? strtotime($data['date_created']) : false;
            $mapped['starmus_date_created'] = ($created_ts !== false) 
                ? gmdate('Ymd', $created_ts) 
                : gmdate('Ymd');

            $session_ts = !empty($data['session_date']) ? strtotime($data['session_date']) : false;
            $mapped['starmus_session_date'] = ($session_ts !== false) 
                ? gmdate('Ymd', $session_ts) 
                : gmdate('Ymd');

            // Geolocation
            if (!empty($data['geolocation'])) {
                $mapped['starmus_session_gps'] = $data['geolocation'];
                $mapped['starmus_agree_geo']   = $data['geolocation'];
            }

            // Agreement Logic (Store as UTC DateTime)
            if (!empty($data['agreement'])) {
                $mapped['starmus_agreement_datetime'] = gmdate('Y-m-d H:i:s');
            }

            // --- JSON BLOBS (Complex Transformations) ---

            // Environment Data (Array -> JSON)
            if (!empty($data['_starmus_env'])) {
                $mapped['starmus_environment_data'] = self::ensure_json_string($data['_starmus_env'], 'env');
                
                // Extract Fingerprint
                $env_arr = self::decode_if_json($data['_starmus_env']);
                if (isset($env_arr['fingerprint'])) {
                    $mapped['starmus_device_fingerprint'] = $env_arr['fingerprint'];
                }
            }

            // Waveform (String -> JSON)
            if (!empty($data['waveform_json'])) {
                $mapped['starmus_waveform_json'] = self::ensure_json_string($data['waveform_json'], 'waveform');
            }

            // Recording Metadata (Array -> JSON)
            if (!empty($data['recording_metadata'])) {
                $mapped['starmus_recording_metadata'] = self::ensure_json_string(
                    $data['recording_metadata'], 
                    'recording_metadata'
                );
            }

            // Processing Log (Array/String -> JSON)
            if (!empty($data['processing_log'])) {
                $mapped['starmus_processing_log'] = self::ensure_json_string(
                    $data['processing_log'], 
                    'processing_log'
                );
            }

            // Transcriber Metadata (String -> JSON Object)
            if (!empty($data['_starmus_calibration'])) {
                $transcriber_meta = [
                    'transcriber' => $data['_starmus_calibration']
                ];
                $mapped['starmus_transcriber_metadata'] = self::ensure_json_string($transcriber_meta, 'transcriber');
            } elseif (!empty($data['transcriber'])) {
                $mapped['starmus_transcriber_metadata'] = self::ensure_json_string(
                    ['transcriber' => $data['transcriber']], 
                    'transcriber'
                );
            }

            // Transcribed Date (Date String -> JSON Object)
            if (!empty($data['transcribed'])) {
                $mapped['starmus_transcribed_metadata'] = self::ensure_json_string(
                    ['date' => $data['transcribed']], 
                    'transcribed_meta'
                );
            }

            // Translator Metadata (String -> JSON Object)
            if (!empty($data['translator'])) {
                $mapped['starmus_translator_metadata'] = self::ensure_json_string(
                    ['name' => $data['translator']], 
                    'translator_meta'
                );
            }

            // Translated Date (Date String -> JSON Object)
            if (!empty($data['translated'])) {
                $mapped['starmus_translated_metadata'] = self::ensure_json_string(
                    ['date' => $data['translated']], 
                    'translated_meta'
                );
            }

            // AI Training (Boolean/Int -> JSON Object)
            if (isset($data['ai_training'])) {
                $mapped['starmus_ai_training'] = self::ensure_json_string(
                    ['status' => (bool)$data['ai_training']], 
                    'ai_training'
                );
            }

            // AI Trained Date (Date String -> JSON Object)
            if (!empty($data['ai_trained'])) {
                $mapped['starmus_ai_trained'] = self::ensure_json_string(
                    ['date' => $data['ai_trained']], 
                    'ai_trained'
                );
            }

            // Parental Permission (String URL -> JSON Object)
            if (!empty($data['parental_permission_slip'])) {
                $mapped['starmus_parental_permission_slip'] = json_encode([
                    'file_url' => $data['parental_permission_slip'],
                    'type'     => 'legacy_frontend_upload'
                ]);
            }

            // --- REPEATERS (Array -> JSON String) ---

            if (!empty($data['revision_history'])) {
                $mapped['starmus_revision_history_json'] = self::ensure_json_string(
                    $data['revision_history'], 
                    'revision_history'
                );
            }

            if (!empty($data['asset_audit_log'])) {
                $mapped['starmus_asset_audit_log'] = self::ensure_json_string(
                    $data['asset_audit_log'], 
                    'asset_audit_log'
                );
            }

            // --- TAXONOMIES ---
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
     * Legacy Helper: Extracts User IDs from submission data.
     * Restored to prevent fatal errors in StarmusSubmissionHandler.
     *
     * @param array $data Raw form data.
     * @return int[] Unique list of User IDs found in the form.
     */
    public static function extract_user_ids(array $data): array
    {
        $user_ids = [];
        $keys = ['copyright_licensor', 'authorized_user_id', 'subject_user_id', 'contributor_id'];

        foreach ($keys as $key) {
            if (!empty($data[$key])) {
                if (is_array($data[$key])) {
                    foreach ($data[$key] as $id) {
                        if (is_numeric($id)) $user_ids[] = (int)$id;
                    }
                } elseif (is_numeric($data[$key])) {
                    $user_ids[] = (int)$data[$key];
                }
            }
        }
        
        // Handle 'interviewers_recorders' which might be comma-separated or array
        if (!empty($data['interviewers_recorders'])) {
             $interviewers = $data['interviewers_recorders'];
             if (is_string($interviewers)) {
                 $ids = explode(',', $interviewers);
                 foreach ($ids as $id) {
                     $id = trim($id);
                     if (is_numeric($id)) $user_ids[] = (int)$id;
                 }
             } elseif (is_array($interviewers)) {
                 foreach ($interviewers as $id) {
                     if (is_numeric($id)) $user_ids[] = (int)$id;
                 }
             }
        }

        return array_unique($user_ids);
    }

    /**
     * Check if a specific field key should be treated as JSON.
     * UPDATED: Checks against NEW Starmus keys AND Legacy keys for backward compatibility.
     *
     * @param string $field_name
     * @return bool
     */
    public static function is_json_field(string $field_name): bool
    {
        $new_keys = [
            'starmus_environment_data',
            'starmus_waveform_json',
            'starmus_transcriber_metadata',
            'starmus_transcribed_metadata',
            'starmus_translator_metadata',
            'starmus_translated_metadata',
            'starmus_ai_training',
            'starmus_ai_trained',
            'starmus_school_reviewed',
            'starmus_parental_permission_slip',
            'starmus_contributor_verification',
            'starmus_transcription_json',
            'starmus_processing_log',
            'starmus_recording_metadata',
            'starmus_asset_audit_log',
            'starmus_revision_history_json'
        ];

        // Ensure legacy keys work too if called by old code
        $legacy_keys = [
            'environment_data',
            'waveform_json',
            'transcriber',
            'school_reviewed',
            'parental_permission_slip',
            'contributor_verification',
            'transcription_json',
            'recording_metadata',
            'processing_log'
        ];

        return in_array($field_name, array_merge($new_keys, $legacy_keys), true);
    }

    /**
     * Helper: Find the SparxStar Contributor Post for a given User ID.
     * 
     * @note This is a Read-Only lookup. It does NOT create new posts to adhere to SRP.
     * If a contributor is missing, it must be created by a dedicated service before mapping.
     */
    private static function get_contributor_id_for_user(int $user_id): ?int
