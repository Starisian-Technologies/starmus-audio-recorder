# StarmusFileService

**Namespace:** `Starisian\Sparxstar\Starmus\services`

**File:** `/workspaces/starmus-audio-recorder/src/services/StarmusFileService.php`

## Description

STARISIAN TECHNOLOGIES CONFIDENTIAL
© 2023–2025 Starisian Technologies. All Rights Reserved.
StarmusFileService (DAL-integrated)

- Guarantees local access for offloaded files.
- Routes all persistence and attachment updates through DAL.
- Supports external offloaders like WP Offload Media (AS3CF).
@package Starisian\Sparxstar\Starmus\services
@version 1.0.0-HARDENED

## Methods

### `__construct()`

**Visibility:** `public`

STARISIAN TECHNOLOGIES CONFIDENTIAL
© 2023–2025 Starisian Technologies. All Rights Reserved.
StarmusFileService (DAL-integrated)

- Guarantees local access for offloaded files.
- Routes all persistence and attachment updates through DAL.
- Supports external offloaders like WP Offload Media (AS3CF).
@package Starisian\Sparxstar\Starmus\services
@version 1.0.0-HARDENED
/
namespace Starisian\Sparxstar\Starmus\services;

if (! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\data\StarmusAudioDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

/**
STARISIAN TECHNOLOGIES CONFIDENTIAL
© 2023–2025 Starisian Technologies. All Rights Reserved.
StarmusFileService (DAL-integrated)
Provides comprehensive file management for audio recordings with support for
external offload services like Cloudflare R2 via WP Offload Media. Guarantees
local file access for processing while maintaining compatibility with various
WordPress hosting configurations.
Key Features:

- Local file access guarantee for offloaded attachments
- Automatic metadata generation for third-party plugin compatibility
- DAL-routed persistence for consistent data access patterns
- Support for WP Offload Media (AS3CF) and Advanced Media Offloader
- Temporary file management for processing workflows
Compatibility Layers:
- Advanced Media Offloader: Automatic metadata generation
- WP Offload Media: Native upload delegation
- Local filesystem: Direct file operations
@package Starisian\Sparxstar\Starmus\services
@version 1.0.0-HARDENED
@since   1.0.0
@see StarmusAudioDAL Data access abstraction
@see Advanced_Media_Offloader\Plugin Third-party offloader integration
/
final readonly class StarmusFileService
{
    /**
Data Access Layer for consistent WordPress operations.
@since 1.0.0
/
    private ?StarmusAudioDAL $dal;

    /**
Initializes the file service with DAL dependency.
@param StarmusAudioDAL|null $dal Optional DAL instance (creates new if null)
@since 1.0.0

### `register_compatibility_hooks()`

**Visibility:** `public`

Registers compatibility hooks for third-party plugin integrations.
Conditionally loads compatibility layers to maintain clean, modular
integrations with external plugins. Only activates hooks when the
target plugins are detected as present.
Current Integrations:

- Advanced Media Offloader: Metadata generation bridge
@since 1.0.0
@hook add_attachment Priority 20 for metadata generation

### `ensure_attachment_metadata()`

**Visibility:** `public`

Ensures essential metadata exists for newly created attachments.
Compatibility bridge that detects when attachments are created by Starmus
without proper metadata and generates it. Critical for third-party plugins
like Advanced Media Offloader that rely on metadata to function correctly.
@param int $attachment_id WordPress attachment post ID
@since 1.0.0
@hook add_attachment Called when new attachments are created
Guard Conditions:

1. Skip if metadata already exists (performance optimization)
2. Only target Starmus API endpoints (prevents interference)
3. Validate file existence before processing
Process Flow:
4. Check for existing metadata
5. Validate request originates from Starmus endpoint
6. Verify physical file existence
7. Generate WordPress attachment metadata
8. Save via DAL for consistency
Error Handling:

- Logs detailed information for debugging
- Gracefully handles missing files or generation failures
- Continues execution on non-critical errors
@see wp_generate_attachment_metadata() WordPress metadata generation
@see StarmusAudioDAL::update_attachment_metadata() DAL persistence

### `get_local_copy()`

**Visibility:** `public`

Guarantees local file access for attachment processing.
Returns a local file path for an attachment, downloading from remote storage
if necessary. Essential for audio processing workflows that require direct
file system access.
@param int $attachment_id WordPress attachment post ID
@since 1.0.0
Resolution Strategy:

1. **Local Check**: Verify existing local file via get_attached_file()
2. **Remote Download**: Download from public URL if offloaded
3. **Temporary Storage**: Return temp file path (caller must clean up)
Use Cases:

- Audio processing (FFmpeg operations)
- Metadata extraction (getID3 analysis)
- Waveform generation (audiowaveform CLI)
- File validation and security scanning
Offloader Support:
- WP Offload Media (AS3CF): Downloads from S3/CloudFlare R2
- Advanced Media Offloader: Downloads from configured storage
- Custom offloaders: Works with any plugin using wp_get_attachment_url
Important Notes:
- Returns temporary files for offloaded content
- Caller responsible for cleanup of temp files
- 120-second download timeout for large files
- Automatically handles WordPress file URL filtering
@throws \Exception Implicitly via download_url() on network failures
@return string|null Local file path if available, null on failure
@example

```php
$local_path = $service->get_local_copy($attachment_id);
if ($local_path && file_exists($local_path)) {
    // Process file
    process_audio_file($local_path);
    // Clean up if temporary
    if ($local_path !== get_attached_file($attachment_id)) {
        unlink($local_path);
    }
}
```

### `upload_and_replace_attachment()`

**Visibility:** `public`

Uploads and replaces attachment file with offloader integration.
Handles file uploads for both local and offloaded storage configurations.
Automatically delegates to appropriate storage backend based on active plugins.
@param int $attachment_id WordPress attachment post ID to replace
@param string $local_file_path Local file path to upload
@return bool True if upload successful, false on failure
@since 1.0.0
Upload Strategy:

1. **WP Offload Media**: Delegate to as3cf_upload_attachment() if available
2. **Local Filesystem**: Use WordPress filesystem API as fallback
3. **DAL Integration**: Update metadata through Data Access Layer
Supported Offloaders:

- WP Offload Media (AS3CF): S3, CloudFlare R2, DigitalOcean Spaces
- Local filesystem: Standard WordPress uploads directory
Process Flow:

1. Validate local file existence
2. Detect active offloader plugins
3. Delegate upload to appropriate handler
4. Update attachment metadata via DAL
5. Log results for monitoring
Error Conditions:

- Local file doesn't exist
- Offloader upload fails
- Filesystem move operation fails
- Metadata update fails
@see as3cf_upload_attachment() WP Offload Media function
@see WP_Filesystem WordPress filesystem abstraction
@see StarmusAudioDAL::update_attachment_metadata() Metadata persistence

### `star_get_public_url()`

**Visibility:** `public`

Retrieves the correct public URL for an attachment across storage backends.
Returns the appropriate public URL whether the file is stored locally or
offloaded to external storage. Honors all WordPress URL filtering including
offloader plugins and CDN configurations.
@param int $attachment_id WordPress attachment post ID
@return string|null Public URL if available, null on failure
@since 1.0.0
Resolution Strategy:

1. **WordPress API**: Use wp_get_attachment_url() (honors all filters)
2. **Metadata Fallback**: Reconstruct from attachment metadata if primary fails
3. **Base URL Construction**: Combine upload dir with relative file path
URL Sources (Priority Order):

- Offloader plugin URLs (S3, CloudFlare R2, etc.)
- CDN-transformed URLs
- Local WordPress upload URLs
- Reconstructed URLs from metadata
Use Cases:
- Frontend audio player source URLs
- Download links for processed files
- API responses requiring public file access
- Email notifications with file links
WordPress Integration:
- Respects wp_get_attachment_url filters
- Honors offloader plugin URL transformations
- Supports CDN and domain mapping plugins
- Automatically escapes URLs for security
@return string Escaped public URL ready for HTML output
@return null If attachment not found or URL cannot be determined
@example

```php
$url = $service->star_get_public_url($attachment_id);
if ($url) {
    echo '<audio src="' . esc_attr($url) . '" controls></audio>';
}
```

## Properties

---

_Generated by Starisian Documentation Generator_
