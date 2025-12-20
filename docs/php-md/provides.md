# provides

**Namespace:** `Starisian\Sparxstar\Starmus\core`

**File:** `/workspaces/starmus-audio-recorder/src/core/StarmusAudioRecorderDAL.php`

## Description

Handles all database and data persistence operations for Starmus.
@package Starisian\Sparxstar\Starmus\core

## Methods

### `create_audio_post()`

**Visibility:** `public`

Handles all database and data persistence operations for Starmus.
@package Starisian\Sparxstar\Starmus\core
/
namespace Starisian\Sparxstar\Starmus\core;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface;
use Throwable;
use WP_Error;
use WP_Query;

/**
Data Access Layer implementation for Starmus Audio Recorder.
This class provides concrete implementations for all database and file system
operations defined in the StarmusAudioRecorderDALInterface. It serves as the
primary abstraction layer between the plugin's business logic and WordPress
core database/media functions.
The DAL handles:
- Audio recording post creation and management
- WordPress attachment operations and metadata
- User recording queries and pagination
- Post meta operations (including ACF integration)
- File path resolution and validation
- Rate limiting and security checks
- Taxonomy assignments for categorization
All database operations include comprehensive error handling with logging
to ensure graceful degradation and debugging capabilities.
@package Starisian\Sparxstar\Starmus\core
@since   0.1.0
@implements StarmusAudioRecorderDALInterface
/
final class StarmusAudioRecorderDAL implements StarmusAudioRecorderDALInterface {

	/*
	------------------------------------*
🧩 CREATION
------------------------------------*/

	/**
{@inheritdoc}

### `create_transcription_post()`

**Visibility:** `public`

Create transcription post linked to audio recording.

### `create_translation_post()`

**Visibility:** `public`

Create translation post linked to audio recording.

### `create_attachment_from_file()`

**Visibility:** `public`

{@inheritdoc}

### `create_attachment_from_sideload()`

**Visibility:** `public`

{@inheritdoc}

### `update_attachment_file_path()`

**Visibility:** `public`

Update the file path for an existing attachment.
Updates the WordPress attachment's file path reference and MIME type.
Used when moving or processing files to update the attachment's
reference to point to the new file location.
@since 0.1.0
@param int $attachment_id The WordPress attachment ID to update.
@param string $file_path The new absolute file system path.
@return bool True on successful update, false on failure.

### `update_attachment_metadata()`

**Visibility:** `public`

Update attachment metadata for an existing file.
Regenerates and updates WordPress attachment metadata including
file size, dimensions (for images), and other file-specific metadata.
Validates file existence before attempting metadata generation.
@since 0.1.0
@param int $attachment_id The WordPress attachment ID to update.
@param string $file_path The absolute file system path to analyze.
@return bool True if metadata was successfully generated and updated, false on failure.

### `set_attachment_parent()`

**Visibility:** `public`

{@inheritdoc}

### `delete_attachment()`

**Visibility:** `public`

{@inheritdoc}

### `get_user_recordings()`

**Visibility:** `public`

{@inheritdoc}

### `get_post_info()`

**Visibility:** `public`

{@inheritdoc}

### `get_ffmpeg_path()`

**Visibility:** `public`

{@inheritdoc}

### `get_edit_page_url_admin()`

**Visibility:** `public`

{@inheritdoc}

### `get_page_id_by_slug()`

**Visibility:** `public`

{@inheritdoc}

### `get_page_slug_by_id()`

**Visibility:** `public`

{@inheritdoc}
/
	/**
Get WordPress page slug by its ID.
Retrieves the URL slug for a WordPress page given its post ID.
Returns empty string if the page doesn't exist.
@since 0.1.0
@param int $id WordPress page/post ID.
@return string Page slug, empty string if not found.

### `is_rate_limited()`

**Visibility:** `public`

Check if a user is rate limited for submissions.
Implements per-user rate limiting using WordPress transients.
Tracks submission attempts and blocks users who exceed the limit
within a one-minute window.
@since 0.1.0
@param int $user_id WordPress user ID to check.
@param int $limit Maximum allowed submissions per minute (default: 10).
@return bool True if user is rate limited, false if within limits.

### `get_registration_key()`

**Visibility:** `public`

Get the DAL registration key for security validation.
Returns the configured override key for DAL replacement validation.
Used in the handshake mechanism when external code attempts to
replace the default DAL implementation.
@since 0.1.0
@return string Registration key if defined, empty string otherwise.

### `update_audio_post_meta()`

**Visibility:** `public`

{@inheritdoc}

### `save_post_meta()`

**Visibility:** `public`

{@inheritdoc}

### `set_audio_state()`

**Visibility:** `public`

{@inheritdoc}

### `save_audio_outputs()`

**Visibility:** `public`

{@inheritdoc}

### `persist_audio_outputs()`

**Visibility:** `public`

{@inheritdoc}

### `record_id3_timestamp()`

**Visibility:** `public`

Record timestamp when ID3 tags were written to an audio file.
Stores a MySQL timestamp indicating when ID3 metadata was last
written to the audio file. Used for audit trails and processing
status tracking.
@since 0.1.0
@param int $attachment_id WordPress attachment ID.
@return void
/
	/**
Record timestamp when ID3 tags were written to an audio file.
@param int $attachment_id WordPress attachment ID.

### `set_copyright_source()`

**Visibility:** `public`

Set copyright source information for an audio attachment.
Records the copyright source or attribution text for an audio file.
Used for legal compliance and metadata management.
@since 0.1.0
@param int $attachment_id WordPress attachment ID.
@param string $copyright_text Copyright notice or source attribution.
@return void
/
	/**
Set copyright source information for an audio attachment.
@param int $attachment_id WordPress attachment ID.
@param string $copyright_text Copyright notice or source attribution.

### `assign_taxonomies()`

**Visibility:** `public`

Assign taxonomy terms to an audio recording post.
Links language and recording type taxonomies to audio recording posts
for categorization and filtering. Only assigns terms if IDs are provided
to avoid overwriting existing assignments with null values.
@since 0.1.0
@param int $post_id WordPress post ID to assign terms to.
@param int|null $language_id Term ID from 'language' taxonomy (optional).
@param int|null $type_id Term ID from 'recording-type' taxonomy (optional).
@return void
/
	/**
Assign taxonomy terms to an audio recording post.
@param int $post_id WordPress post ID to assign terms to.
@param int|null $language_id Term ID from 'language' taxonomy (optional).
@param int|null $type_id Term ID from 'recording-type' taxonomy (optional).

---

_Generated by Starisian Documentation Generator_
