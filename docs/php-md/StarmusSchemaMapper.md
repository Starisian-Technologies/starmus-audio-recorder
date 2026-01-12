# StarmusSchemaMapper

**Namespace:** `Starisian\Sparxstar\Starmus\data\mappers`

**File:** `/workspaces/starmus-audio-recorder/src/data/mappers/StarmusSchemaMapper.php`

## Description

Schema Mapper for Starmus Audio System.
Acts as a Key Translator for the NEW Schema (starmus_ prefix).
Maintains legacy method signatures for backward compatibility.
Ensures 1:1 data mapping for all unique fields.
@package Starisian\Sparxstar\Starmus\data\mappers
@version 2.0.4

## Methods

### `map_form_data()`

**Visibility:** `public`

Schema Mapper for Starmus Audio System.
Acts as a Key Translator for the NEW Schema (starmus_ prefix).
Maintains legacy method signatures for backward compatibility.
Ensures 1:1 data mapping for all unique fields.
@package Starisian\Sparxstar\Starmus\data\mappers
@version 2.0.4
/

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\data\mappers;

use function date;
use function get_current_user_id;
use function json_decode;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;
use function sanitize_text_field;

use Starisian\Sparxstar\Starmus\data\StarmusAudioDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;

use function wp_unslash;

if ( ! \defined('ABSPATH')) {
    exit;
}

class StarmusSchemaMapper
{
    /**
JSON Error Constant for Comparison
/
    private const JSON_ERROR_NONE = 0;
    /**
FIELDS TO PASSTHROUGH WITHOUT MODIFICATION
These fields are directly copied from input to output.
Complex logic fields (dates, users, JSON blobs) are handled separately.
@var array<int, string>
/
    private const PASSTHROUGH_ALLOWLIST = [
        // Core Fields
        'contributor_name',
        'dc_description',
        'dc_title',
        'dc_subject',
        'dc_language',
        'dc_format',
        'dc_identifier',
        'date_created',
        'session_date',
        'geolocation',
        'parental_permission_slip',

        // Legacy Fields (for backward compatibility)
        'starmus_global_uuid',
        'starmus_stable_uri',
        'starmus_linked_data_uri',
        'starmus_rights_type',
        'starmus_rights_use',
        'starmus_rights_geo',
        'starmus_rights_royalty',
        'starmus_data_sensitivity',
        'starmus_anon_status',
        'starmus_consent_scope',
        'starmus_copyright_licensee',
    ];

    /**
MAPPING DEFINITION: 'Old_Frontend_Key' => 'New_Starmus_Key'
Direct 1:1 mappings. Replaces the old PASSTHROUGH_ALLOWLIST.
Note: Dates, Users, and JSON blobs are handled specifically in map_form_data(),
but are listed here for completeness.
/
    private const FIELD_MAP = [
        // -- Core Archival --
        'global_uuid'            => 'starmus_global_uuid',
        'stable_uri'             => 'starmus_stable_uri',
        'linked_data_uri'        => 'starmus_linked_data_uri',
        'dc_rights_type'         => 'starmus_rights_type',
        'dc_rights'              => 'starmus_rights_use',
        'dc_rights_geo'          => 'starmus_rights_geo',
        'dc_rights_royalty'      => 'starmus_rights_royalty',
        'data_sensitivity_level' => 'starmus_data_sensitivity',
        'anonymization_status'   => 'starmus_anon_status',
        'consent_scope'          => 'starmus_consent_scope',
        'copyright_licensee'     => 'starmus_copyright_licensee',

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
        'recording_metadata' => 'starmus_recording_metadata', // Technical Session JSON
        'processing_log'     => 'starmus_processing_log',     // Audit Trail JSON

        // -- Rights & Credits --
        'copyright_status'  => 'starmus_copyright_status',
        'usage_constraints' => 'starmus_usage_constraints',

        // -- Processing Status --
        'explicit'                 => 'starmus_is_explicit',
        'is_music'                 => 'starmus_is_music',
        'school_reviewed'          => 'starmus_school_reviewed',
        'contributor_verification' => 'starmus_contributor_verification',
        'qa_review'                => 'starmus_qa_review',
        'waveform_json'            => 'starmus_waveform_json',
        'original_source'          => 'starmus_original_source',
        'archival_wav'             => 'starmus_archival_wav',
        'mastered_mp3'             => 'starmus_mastered_mp3',
        'cloud_object_uri'         => 'starmus_cloud_object_uri',
        'device_fingerprint'       => 'starmus_device_fingerprint',
        'environment_data'         => 'starmus_environment_data',

        // -- Agreement --
        'terms_type'              => 'starmus_terms_type',
        'submission_id'           => 'starmus_submission_id',
        'contributor_signature'   => 'starmus_contributor_signature',
        'contributor_user_agent'  => 'starmus_agree_ua',
        'ip_address'              => 'starmus_agree_ip',
        'submission_ip'           => 'starmus_agree_ip', // Legacy alias
        'contributor_ip'          => 'starmus_agree_ip', // Legacy alias
        'contributor_geolocation' => 'starmus_agree_geo',

        // -- Music Engineering --
        'sample_rate'    => 'starmus_sample_rate',
        'bit_depth'      => 'starmus_bit_depth',
        'tuning_hz'      => 'starmus_tuning_hz',
        'channel_layout' => 'starmus_channel_layout',

        // -- Music Composition --
        'bpm'             => 'starmus_bpm',
        'musical_key'     => 'starmus_musical_key',
        'isrc_code'       => 'starmus_isrc_code',
        'integrated_lufs' => 'starmus_integrated_lufs',
        'stems_cloud_uri' => 'starmus_stems_cloud_uri',
        'daw_project_uri' => 'starmus_daw_project_uri',

        // -- Release --
        'upc_code'       => 'starmus_upc_code',
        'catalog_number' => 'starmus_catalog_number',
        'label_name'     => 'starmus_label_name',

        // -- Transcription & Translation --
        'transcription'          => 'starmus_transcription_text', // The visual text
        'transcription_json'     => 'starmus_transcription_json', // The timestamps
        'translation'            => 'starmus_translation_text',
        'translation_language'   => 'starmus_translation_language',
        'original_language'      => 'starmus_original_language',
        'back_translation_text'  => 'starmus_back_translation_text',
        'transcription_hash'     => 'starmus_transcription_hash',
        'translation_hash'       => 'starmus_translation_hash',
        'audio_recording_parent' => 'starmus_linked_audio', // The link to parent
        'transcription_parent'   => 'starmus_transcription_parent', // Link for translation
    ];

    /**
Maps form data to the ACF Schema structure.
Translates legacy frontend field keys (e.g. 'location') into the new
Starmus database schema keys (e.g. 'starmus_session_location').
@param array<string, mixed> $data Raw or semi-sanitized form data.
@return array<string, mixed> Data ready for ACF saving.

### `extract_user_ids()`

**Visibility:** `public`

Extracts user IDs for submission processing.
@param array $data Raw form data
@return array Key-value pair of field names and user IDs

### `is_json_field()`

**Visibility:** `public`

Check if a specific field key should be treated as JSON.
UPDATED: Checks against NEW Starmus keys AND Legacy keys for backward compatibility.
@param string $field_name
@return bool

---

_Generated by Starisian Documentation Generator_
