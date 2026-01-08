# StarmusSubmissionHandler

**Namespace:** `Starisian\Sparxstar\Starmus\core`

**File:** `/workspaces/starmus-audio-recorder/src/core/StarmusSubmissionHandler.php`

## Description

@package \Starisian\Sparxstar\Starmus\core
@file STarusSubmissionHandler.php
@author
@version 1.0.0
@since 1.0.0

## Methods

### `__construct()`

**Visibility:** `public`

@package \Starisian\Sparxstar\Starmus\core
@file STarusSubmissionHandler.php
@author
@version 1.0.0
@since 1.0.0
/

declare(strict_types=1);

/**
Core submission service responsible for processing audio uploads using the DAL.
Handles the complete workflow for audio file submissions including file validation,
upload processing, metadata extraction, post creation, and post-processing tasks.
Supports both chunked TUS uploads and traditional fallback uploads.
@package   Starisian\Sparxstar\Starmus\core
@version   6.9.3-GOLDEN-MASTER
@since     1.0.0
Features:
- TUS resumable upload processing via temporary file handling
- Traditional fallback upload support for Tier C browsers
- MIME type validation with security-conscious detection
- Rate limiting and file size validation
- Automatic metadata extraction and ACF field population
- Post-processing service integration with cron fallback
- Temporary file cleanup and path traversal protection
Upload Flow:
1. File validation (MIME type, size, extension)
2. Secure file movement to WordPress uploads directory
3. WordPress attachment creation via DAL
4. Audio recording post creation or update
5. Metadata extraction and ACF field population
6. Taxonomy assignment (language, recording type)
7. Post-processing trigger (audio optimization)
8. Temporary file cleanup
@see IStarmusAudioDAL Data Access Layer interface
@see StarmusSettings Plugin configuration management
@see StarmusPostProcessingService Audio processing service
/
namespace Starisian\Sparxstar\Starmus\core;

use function array_map;
use function base64_decode;
use function explode;
use function file_exists;
use function file_put_contents;
use function filemtime;
use function filesize;
use function get_current_user_id;
use function get_post_meta;
use function get_post_type;
use function glob;
use function home_url;
use function is_dir;
use function is_wp_error;
use function json_decode;
use function mime_content_type;
use function mkdir;
use function pathinfo;
use function rmdir;
use function sanitize_file_name;
use function sanitize_key;
use function sanitize_text_field;

use Starisian\Sparxstar\Starmus\core\interfaces\IStarmusSubmissionHandler;
use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusAudioDAL;
use Starisian\Sparxstar\Starmus\data\mappers\StarmusSchemaMapper;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusSanitizer;
use Starisian\Sparxstar\Starmus\services\StarmusPostProcessingService;

use function str_contains;
use function str_starts_with;
use function strtolower;
use function sys_get_temp_dir;

use Throwable;

use function time;
use function trailingslashit;
use function unlink;
use function wp_check_filetype;

use WP_Error;

use function wp_get_attachment_url;
use function wp_get_mime_types;
use function wp_next_scheduled;
use function wp_normalize_path;

use WP_REST_Request;

use function wp_schedule_single_event;
use function wp_set_post_terms;
use function wp_unique_filename;
use function wp_unslash;
use function wp_upload_dir;

if ( ! \defined('ABSPATH')) {
    exit;
}

/**
StarmusSubmissionHandler Class
/

final class StarmusSubmissionHandler implements IStarmusSubmissionHandler
{
    /**
Class constructor dependencies.
@var array<string>
@phpstan-var list<string>
@psalm-var list<string>
Fallback file field names for multi-format support.
@since 1.0.0
/
    private array $fallback_file_keys = ['audio_file', 'file', 'upload'];

    /**
Default allowed MIME types for audio uploads.
Explicit MIME types for stricter validation when settings don't specify types.
Includes common audio formats supported across browsers and platforms.
@var array<string>
@phpstan-var list<string>
@psalm-var list<string>
MIME type allowlist for uploads.
@since 1.0.0
/
    private array $default_allowed_mimes = [
    'audio/webm',
    'audio/ogg',
    'audio/mpeg',
    'audio/wav',
    'audio/x-wav',
    'audio/mp4',
    ];

    /**
Initializes the submission handler with required dependencies.
Sets up WordPress action hooks for temporary file cleanup and logs
successful construction. Throws exceptions on setup failures.
@param IStarmusAudioDAL $dal Data Access Layer implementation
@param StarmusSettings $settings Plugin configuration service
@throws Throwable If construction fails or hooks cannot be registered
@since 1.0.0

### `handle_upload_chunk_rest_multipart()`

**Visibility:** `public`

Handles multipart chunk uploads via REST API.

### `handle_upload_chunk_rest_base64()`

**Visibility:** `public`

Handles base64 encoded uploads via REST API (Legacy).

### `process_completed_file()`

**Visibility:** `public`

Processes a completed file upload from disk (TUS or chunked upload completion).
Main entry point for processing uploaded files that are already on disk,
typically from TUS daemon webhook callbacks. Delegates to internal
finalization method with proper error handling.
@param string $file_path Absolute path to the uploaded file on disk
@param array $form_data Sanitized form submission data with metadata
@since 1.0.0
@see _finalize_from_local_disk() Internal finalization implementation
Success Response:
```php
[
  'success' => true,
  'attachment_id' => 123,
  'post_id' => 456,
  'url' => 'https://site.com/uploads/recording.wav'
]
```
@throws WP_Error 400 If file is missing or invalid
@throws WP_Error 413 If file exceeds size limits
@throws WP_Error 415 If MIME type not allowed
@throws WP_Error 500 If file operations or processing fail
@return array|WP_Error Success data with attachment/post IDs or error object

### `handle_fallback_upload_rest()`

**Visibility:** `public`

@deprecated 6.9.3 Use the new 'starmus_recording_processed' hook for better data.
This hook is fired for backward compatibility.
/
            do_action('starmus_after_audio_saved', (int) $cpt_post_id, $form_data);
            return [
            'success'       => true,
            'attachment_id' => (int) $attachment_id,
            'post_id'       => (int) $cpt_post_id,
            'url'           => wp_get_attachment_url((int) $attachment_id),
            ];
        } catch (Throwable $throwable) {
            StarmusLogger::log(
                $throwable,
                [
            'component'     => self::class,
            'method'        => __METHOD__,
            'attachment_id' => (int) $attachment_id,
            'post_id'       => (int) $cpt_post_id,
            'file_path'     => $file_path,
            'filename'      => $filename,
                ]
            );
            return $this->err('server_error', 'File finalization failed.', 500);
        }
    }

    /**
Handles REST API fallback uploads for Tier C browsers.
Processes traditional file uploads via WordPress REST API when modern
upload methods (TUS, chunked) are not supported. Includes rate limiting,
enhanced MIME detection for iOS/Safari, and comprehensive validation.
@param WP_REST_Request $request REST API request with file and form data
@since 1.0.0
Rate Limiting:
- Checks user-based rate limits before processing
- Returns 429 status for excessive requests
MIME Detection (iOS/Safari Fix):
1. Check uploaded file type from browser
2. Use mime_content_type() if browser type is empty
3. Fallback to wp_check_filetype() for extension-based detection
Supported File Keys (Priority Order):
- audio_file (preferred)
- file (generic)
- upload (fallback)
- Any array field with tmp_name
@see process_fallback_upload() File processing implementation
@see detect_file_key() File field detection logic
@see validate_file_against_settings() MIME and size validation
@throws WP_Error rate_limited If user exceeds rate limits
@throws WP_Error missing_file If no valid file provided
@throws WP_Error upload_error If browser upload failed
@throws WP_Error server_error If processing fails
@return array|WP_Error Success response or error object

### `process_fallback_upload()`

**Visibility:** `public`

Processes traditional file uploads using WordPress media functions.
Handles sideloaded file uploads for fallback scenarios when TUS or
chunked uploads are not available. Uses WordPress core media handling
functions with DAL abstraction for consistency.
@param array $files_data $_FILES array data from request
@param array $form_data Sanitized form submission data
@param string $file_key Detected file field key from files array
@since 1.0.0
Required WordPress Functions:
- media_handle_sideload() for attachment creation
- Includes image.php, file.php, media.php if not loaded
Post Creation Logic:
- Uses existing post if post_id provided and valid
- Creates new audio recording post otherwise
- Associates attachment as post parent
Response Includes:
- attachment_id: WordPress attachment post ID
- post_id: Audio recording custom post ID
- url: Direct attachment file URL
- redirect_url: User-facing success page URL
@throws WP_Error missing_file If file field is empty
@throws WP_Error server_error If DAL operations fail
@return array|WP_Error Success data with redirect URL or error

### `save_all_metadata()`

**Visibility:** `public`

Saves comprehensive metadata for audio recording posts.
Extracts and processes various types of metadata from form submissions,
including environment data, calibration settings, transcripts, and device
information. Updates ACF fields and taxonomies with proper sanitization.
@param int $audio_post_id Audio recording custom post ID
@param int $attachment_id WordPress attachment post ID
@param array $form_data Complete sanitized form submission data
@since 1.0.0
Metadata Types Processed:
1. **Environment Data**: Browser, device, network information
2. **Calibration Data**: Microphone settings and audio levels
3. **Runtime Metadata**: Processing configuration and environment data
4. **Waveform Data**: Audio visualization information
5. **Submission Info**: IP address, timestamps, user agent
6. **Taxonomies**: Language and recording type classifications
7. **Linked Objects**: Connections to other custom post types
JSON Field Processing:
- _starmus_env: Environment/UEC data with device fingerprinting
- _starmus_calibration: Microphone calibration and gain settings
- waveform_json: Audio visualization data for editors
ACF Field Mapping:
- environment_data: Complete environment JSON
- device_fingerprint: Extracted device identifiers
- user_agent: Browser user agent string
- runtime_metadata: Calibration settings JSON
- mic_profile: Human-readable microphone settings
- runtime_metadata: Processing environment and configuration
- submission_ip: User IP address (GDPR/privacy considerations)
WordPress Actions:
- starmus_after_save_submission_metadata (deprecated 7.0.0)
- starmus_recording_processed: New definitive integration hook
@see update_acf_field() ACF field update wrapper
@see trigger_post_processing() Post-processing service trigger
@see StarmusSanitizer::get_user_ip() IP address extraction

### `get_cpt_slug()`

**Visibility:** `public`

Gets the custom post type slug for audio recordings.
Retrieves the configured CPT slug from settings with sanitization
and fallback to default value.
@return string Custom post type slug
@since 1.0.0

### `sanitize_submission_data()`

**Visibility:** `public`

Sanitizes form submission data using central sanitizer.
@param array $data Raw form submission data
@return array Sanitized submission data
@since 1.0.0
@see StarmusSanitizer::sanitize_submission_data()

### `cleanup_stale_temp_files()`

**Visibility:** `public`

Cleans up stale temporary files via cron job.
Removes temporary .part files older than 24 hours from the
temporary directory. Registered as WordPress cron callback.
@since 1.0.0
@hook starmus_cleanup_temp_files

## Properties

---

_Generated by Starisian Documentation Generator_
