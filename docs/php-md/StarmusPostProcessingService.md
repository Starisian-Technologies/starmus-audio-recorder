# StarmusPostProcessingService

**Namespace:** `Starisian\Sparxstar\Starmus\services`

**File:** `/workspaces/starmus-audio-recorder/src/services/StarmusPostProcessingService.php`

## Description

STARISIAN TECHNOLOGIES CONFIDENTIAL
© 2023–2025 Starisian Technologies. All Rights Reserved.
Unified Audio Post-Processing Service with EBU R128 Normalization
Provides comprehensive audio processing pipeline for recorded submissions.
Integrates FFmpeg transcoding, loudness normalization, ID3 tagging, and
waveform generation for optimal mobile/web delivery.
Key Features:
- **EBU R128 Loudness Normalization**: Professional broadcast standards
- **Network-Adaptive Processing**: Quality profiles for 2G/3G/4G networks
- **Dual-Format Output**: MP3 for delivery, WAV for archival
- **Automatic ID3 Tagging**: WordPress metadata integration
- **Waveform Generation**: Visual audio analysis data
- **Offload-Aware File Handling**: CloudFlare R2/S3 compatibility
Processing Pipeline:
1. **File Acquisition**: Local copy retrieval (handles offloaded files)
2. **Loudness Analysis**: Two-pass EBU R128 measurement and normalization
3. **Network Profiling**: Adaptive frequency filtering based on connection
4. **Dual Transcoding**: MP3 delivery + WAV archival formats
5. **Metadata Injection**: WordPress post data → ID3 tags
6. **Media Library Import**: WordPress attachment management
7. **Waveform Generation**: JSON visualization data
8. **Post Metadata Update**: Processing results and file references
Technical Requirements:
- FFmpeg binary available in system PATH or configured location
- Write permissions to WordPress uploads directory
- Sufficient disk space for temporary processing files
- Audio format support (WAV, MP3, FLAC via FFmpeg)
Network Profiles:
- **2G/Slow-2G**: Aggressive filtering (100Hz-4kHz), lower quality
- **3G**: Moderate filtering (80Hz-7kHz), balanced quality
- **4G/WiFi**: Minimal filtering (60Hz highpass), full quality
WordPress Integration:
- Links processed files to recording posts as attachments
- Stores processing logs in post metadata
- Preserves original file relationships
- Updates mastered_mp3 and archival_wav meta fields
@package Starisian\Sparxstar\Starmus\services
@version 2.0.0-OFFLOAD-AWARE
@since   1.0.0
@see StarmusWaveformService Waveform generation integration
@see StarmusId3Service ID3 metadata management
@see StarmusFileService Offloaded file handling
@see StarmusAudioDAL WordPress data operations

## Methods

### `__construct()`

**Visibility:** `public`

STARISIAN TECHNOLOGIES CONFIDENTIAL
© 2023–2025 Starisian Technologies. All Rights Reserved.
Unified Audio Post-Processing Service with EBU R128 Normalization
Provides comprehensive audio processing pipeline for recorded submissions.
Integrates FFmpeg transcoding, loudness normalization, ID3 tagging, and
waveform generation for optimal mobile/web delivery.
Key Features:
- **EBU R128 Loudness Normalization**: Professional broadcast standards
- **Network-Adaptive Processing**: Quality profiles for 2G/3G/4G networks
- **Dual-Format Output**: MP3 for delivery, WAV for archival
- **Automatic ID3 Tagging**: WordPress metadata integration
- **Waveform Generation**: Visual audio analysis data
- **Offload-Aware File Handling**: CloudFlare R2/S3 compatibility
Processing Pipeline:
1. **File Acquisition**: Local copy retrieval (handles offloaded files)
2. **Loudness Analysis**: Two-pass EBU R128 measurement and normalization
3. **Network Profiling**: Adaptive frequency filtering based on connection
4. **Dual Transcoding**: MP3 delivery + WAV archival formats
5. **Metadata Injection**: WordPress post data → ID3 tags
6. **Media Library Import**: WordPress attachment management
7. **Waveform Generation**: JSON visualization data
8. **Post Metadata Update**: Processing results and file references
Technical Requirements:
- FFmpeg binary available in system PATH or configured location
- Write permissions to WordPress uploads directory
- Sufficient disk space for temporary processing files
- Audio format support (WAV, MP3, FLAC via FFmpeg)
Network Profiles:
- **2G/Slow-2G**: Aggressive filtering (100Hz-4kHz), lower quality
- **3G**: Moderate filtering (80Hz-7kHz), balanced quality
- **4G/WiFi**: Minimal filtering (60Hz highpass), full quality
WordPress Integration:
- Links processed files to recording posts as attachments
- Stores processing logs in post metadata
- Preserves original file relationships
- Updates mastered_mp3 and archival_wav meta fields
@package Starisian\Sparxstar\Starmus\services
@version 2.0.0-OFFLOAD-AWARE
@since   1.0.0
@see StarmusWaveformService Waveform generation integration
@see StarmusId3Service ID3 metadata management
@see StarmusFileService Offloaded file handling
@see StarmusAudioDAL WordPress data operations
/
final readonly class StarmusPostProcessingService
{
    public const STATE_PROCESSING  = 'processing';

    public const STATE_WAVEFORM    = 'waveform';

    public const STATE_COMPLETED   = 'completed';

    public const STATE_ERR_UNKNOWN = 'error_unknown';

    /**
Data Access Layer for WordPress operations.
@since 1.0.0
/
    private IStarmusAudioDAL $dal;

    /**
Waveform generation service for visualization data.
@since 1.0.0
/
    private StarmusWaveformService $waveform_service;

    /**
ID3 metadata service for audio tagging.
@since 1.0.0
/
    private StarmusEnhancedId3Service $id3_service;

    /**
File service for offloaded attachment handling.
@since 2.0.0
/
    private StarmusFileService $file_service;

    /**
Initializes post-processing service with integrated dependencies.
Creates all required service instances and establishes dependency
injection for file service to ensure offload-aware operations.
@since 1.0.0

### `process()`

**Visibility:** `public`

Main entry point for comprehensive audo processing pipeline.
Executes complete post-processing workflow from raw recording to
delivery-ready formats with metadata, normalization, and visualization.
@param int $post_id WordPress post ID for the recording
@param int $attachment_id WordPress attachment ID for source audio file
@param array $params Processing configuration options
@since 1.0.0
Parameter Options ($params):
- **network_type**: string ['2g', '3g', '4g'] - Quality profile selection
- **samplerate**: int [22050, 44100, 48000] - Target sample rate
- **bitrate**: string ['128k', '192k', '256k'] - MP3 encoding bitrate
- **session_uuid**: string - Session tracking identifier
Processing Workflow:
1. **File Retrieval**: Download local copy (CloudFlare R2/S3 support)
2. **Environment Setup**: Output directory creation, FFmpeg validation
3. **Loudness Analysis**: EBU R128 two-pass measurement
4. **Adaptive Filtering**: Network-specific frequency optimization
5. **Dual Transcoding**: MP3 delivery + WAV archival generation
6. **ID3 Integration**: WordPress metadata → audio file tags
7. **Media Library Import**: WordPress attachment management
8. **Waveform Generation**: JSON visualization data creation
9. **Metadata Updates**: Post meta field population
10. **Cleanup**: Temporary file removal
EBU R128 Normalization:
- Target loudness: -23 LUFS (broadcast standard)
- Peak limiting: -2 dBFS (headroom preservation)
- Loudness range: 7 LU (dynamic range control)
- Two-pass processing for optimal results
Output Files:
- **{post_id}_master.mp3**: Delivery-optimized, ID3-tagged
- **{post_id}_archival.wav**: Lossless backup, full quality
- **waveform.json**: Visualization data (via WaveformService)
Error Handling:
- FFmpeg availability validation
- File access and permission checks
- Processing command failure recovery
- Automatic temporary file cleanup
- Comprehensive error logging
WordPress Integration:
- Updates `mastered_mp3` and `archival_wav` post meta
- Stores complete `processing_log` for debugging
- Maintains parent-child attachment relationships
- Preserves original file if processing fails
@throws RuntimeException If FFmpeg not found or file access fails
@return bool True if processing completed successfully
@see get_local_copy() File retrieval handling
@see build_tag_payload() ID3 metadata construction
@see import_to_media_library() WordPress attachment creation

## Properties

---

_Generated by Starisian Documentation Generator_
