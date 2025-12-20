# StarmusWaveformService

**Namespace:** `Starisian\Sparxstar\Starmus\services`

**File:** `/workspaces/starmus-audio-recorder/src/services/StarmusWaveformService.php`

## Description

STARISIAN TECHNOLOGIES CONFIDENTIAL
© 2023–2025 Starisian Technologies. All Rights Reserved.
Waveform Generation Service with DAL Integration
Generates JSON waveform data for audio files using the audiowaveform CLI tool.
Provides visualization data for audio editors, players, and analysis tools.
Integrates with WordPress post system and supports offloaded file handling.
Key Features:
- audiowaveform CLI tool integration
- Configurable pixels-per-second and bit depth
- JSON output format for web consumption
- DAL-routed persistence and metadata management
- Offloaded file support via StarmusFileService
- Automatic parent post detection and linking
Technical Requirements:
- audiowaveform binary must be available in system PATH
- Sufficient disk space for temporary file operations
- Audio files must be in supported formats (WAV, MP3, FLAC, etc.)
WordPress Integration:
- Stores waveform data in post meta and ACF fields
- Links waveform data to parent recording posts
- Supports both standard and offloaded attachment workflows
@package Starisian\Sparxstar\Starmus\services
@version 6.6.0-ROBUST-FIX
@since   1.0.0
@see https://github.com/bbc/audiowaveform audiowaveform CLI tool
@see StarmusAudioRecorderDAL Data access layer
@see StarmusFileService File management service

## Methods

### `__construct()`

**Visibility:** `public`

STARISIAN TECHNOLOGIES CONFIDENTIAL
© 2023–2025 Starisian Technologies. All Rights Reserved.
Waveform Generation Service with DAL Integration
Generates JSON waveform data for audio files using the audiowaveform CLI tool.
Provides visualization data for audio editors, players, and analysis tools.
Integrates with WordPress post system and supports offloaded file handling.
Key Features:
- audiowaveform CLI tool integration
- Configurable pixels-per-second and bit depth
- JSON output format for web consumption
- DAL-routed persistence and metadata management
- Offloaded file support via StarmusFileService
- Automatic parent post detection and linking
Technical Requirements:
- audiowaveform binary must be available in system PATH
- Sufficient disk space for temporary file operations
- Audio files must be in supported formats (WAV, MP3, FLAC, etc.)
WordPress Integration:
- Stores waveform data in post meta and ACF fields
- Links waveform data to parent recording posts
- Supports both standard and offloaded attachment workflows
@package Starisian\Sparxstar\Starmus\services
@version 6.6.0-ROBUST-FIX
@since   1.0.0
@see https://github.com/bbc/audiowaveform audiowaveform CLI tool
@see StarmusAudioRecorderDAL Data access layer
@see StarmusFileService File management service
/
namespace Starisian\Sparxstar\Starmus\services;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

// FIX: Removed 'readonly' for PHP < 8.2 compatibility
final class StarmusWaveformService {

	/**
File service for handling offloaded attachments.
@since 1.0.0
/
	private ?StarmusFileService $files = null;

	/**
Initializes waveform service with optional dependencies.
Creates new instances of dependencies if not provided, allowing for
flexible initialization while maintaining testability.
@param StarmusAudioRecorderDAL|null $dal Optional DAL instance
@param StarmusFileService|null $file_service Optional file service instance
@since 1.0.0

### `is_tool_available()`

**Visibility:** `public`

Checks if audiowaveform CLI tool is available on the system.
Verifies that the audiowaveform binary can be found in the system PATH.
Essential for determining if waveform generation is possible.
@return bool True if tool is available, false otherwise
@since 1.0.0
Detection Method:
- Uses shell 'command -v' to locate binary
- Checks for non-empty path response
- Works across different shell environments
@example
```php
if ($service->is_tool_available()) {
    $service->generate_waveform_data($attachment_id, $post_id);
} else {
    error_log('audiowaveform not available');
}
```

### `generate_waveform_data()`

**Visibility:** `public`

Main entry point for waveform generation and storage.
Generates JSON waveform data from audio attachments and stores it on the
parent recording post. Handles parent post detection, file access, and
comprehensive error management.
@param int $attachment_id WordPress attachment post ID for audio file
@param int|null $explicit_parent_id Optional parent post ID (prevents lookup failures)
@return bool True if waveform generated and saved successfully
@since 1.0.0
Process Flow:
1. **Tool Validation**: Check audiowaveform availability
2. **Parent Resolution**: Find recording post ID via multiple strategies
3. **Duplicate Prevention**: Skip if waveform already exists
4. **File Access**: Get local file copy (handles offloaded files)
5. **Generation**: Extract waveform data via CLI tool
6. **Storage**: Save to post meta and ACF fields
Parent Post Detection (Priority Order):
1. Explicit parent ID parameter
2. _parent_recording_id meta field on attachment
3. WordPress post_parent relationship
File Access Handling:
- Supports offloaded files via StarmusFileService
- Falls back to WordPress get_attached_file()
- Validates physical file existence
Storage Locations:
- WordPress post meta: waveform_json field
- ACF field: waveform_json (if ACF active)
- JSON string format for compatibility
Error Conditions:
- audiowaveform tool not available
- Parent recording post not found
- Audio file not accessible
- Waveform extraction fails
- Database storage fails
@see extract_waveform_from_file() CLI extraction implementation
@see StarmusFileService::get_local_copy() File access management

## Properties

---

_Generated by Starisian Documentation Generator_
