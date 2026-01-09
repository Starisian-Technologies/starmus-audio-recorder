<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\services;

use function class_exists;
use function file_exists;

use getID3;
use getID3_lib;
use getID3_writetags;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;

if ( ! \defined('ABSPATH')) {
    exit;
}

/**
 * getID3 Library Wrapper for Audio Metadata Management
 *
 * Provides a clean, secure interface to the getID3 library for reading and writing
 * ID3 metadata tags in audio files. Handles library loading, encoding consistency,
 * and error management for WordPress integration.
 *
 * Features:
 * - Automatic getID3 library detection and loading
 * - UTF-8 encoding enforcement for international content
 * - ID3v2.3 tag writing with overwrite capability
 * - Comprehensive error handling and logging
 * - WordPress integration with proper file path handling
 *
 * Supported Operations:
 * - ID3 tag writing with complete metadata payload
 * - Audio file analysis and metadata extraction
 * - Tag format standardization (ID3v2.3)
 * - Error and warning collection
 *
 * Library Requirements:
 * - getID3 library must be available via Composer autoload
 * - getid3_writetags class for tag writing operations
 * - getid3_lib class for utility functions
 *
 * @package Starisian\Sparxstar\Starmus\services
 *
 * @since   1.0.0
 *
 * @version PHP 8.2 Compatible
 *
 * @see https://www.getid3.org/ getID3 library documentation
 * @see StarmusLogger Logging service integration
 */
class StarmusId3Service
{
    /**
     * Text encoding constant for all ID3 operations.
     * Ensures consistent UTF-8 encoding across all metadata fields.
     *
     * @var string
     *
     * @since 1.0.0
     */
    private const TEXT_ENCODING = 'UTF-8';

    /**
     * Initializes and configures the getID3 core engine.
     *
     * Handles automatic library detection, fallback loading from vendor paths,
     * and proper engine configuration with UTF-8 encoding.
     *
     * @return getID3|null Configured getID3 instance or null if library unavailable
     *
     * @since 1.0.0
     *
     * Loading Strategy:
     * 1. Check if getID3 class already loaded (Composer autoload)
     * 2. Attempt manual loading from common vendor path
     * 3. Configure encoding options for international support
     *
     * Configuration:
     * - Sets text encoding to UTF-8 for international character support
     * - Enables proper metadata handling for multilingual content
     *
     * Error Handling:
     * - Logs library availability issues
     * - Returns null for graceful degradation
     * - Allows calling code to handle missing library scenarios
     * @see \getID3 Core getID3 library class
     */
    private function getID3Engine(): ?getID3
    {
        if ( ! class_exists('getID3')) {
            // Check if we can manually load it from a common vendor path if composer autoload failed context
            $possible_path = WP_CONTENT_DIR . '/plugins/starmus-audio-recorder/vendor/autoload.php';
            if (file_exists($possible_path)) {
                require_once $possible_path;
            }
        }

        if ( ! class_exists('getID3')) {
            StarmusLogger::error(
                'getID3 library class not found.',
                ['component' => self::class]
            );
            return null;
        }

        $getID3 = new getID3();
        $getID3->setOption(['encoding' => self::TEXT_ENCODING]);
        return $getID3;
    }

    /**
     * Writes ID3 metadata tags to an audio file.
     *
     * Embeds comprehensive metadata into audio files using ID3v2.3 format.
     * Overwrites existing tags and removes other tag formats for consistency.
     *
     * @param string $filepath Absolute path to the audio file
     * @param array $tagData Associative array of ID3 tag data
     *
     * @since 1.0.0
     *
     * Tag Data Structure:
     * ```php
     * [
     *   'title' => ['Song Title'],
     *   'artist' => ['Artist Name'],
     *   'album' => ['Album Name'],
     *   'year' => ['2025'],
     *   'comment' => ['Recording comments'],
     *   'copyright_message' => ['© 2025 Site Name'],
     *   'publisher' => ['Publisher Name'],
     *   'language' => ['en']
     * ]
     * ```
     *
     * Writer Configuration:
     * - Format: ID3v2.3 (maximum compatibility)
     * - Encoding: UTF-8 (international character support)
     * - Overwrite: True (replaces existing tags)
     * - Remove others: True (removes ID3v1, APE, etc.)
     *
     * Error Handling:
     * - Validates file existence before processing
     * - Checks getID3 library availability
     * - Logs detailed error information
     * - Collects and logs warnings separately
     *
     * @throws Throwable Caught and logged, returns false
     *
     * @return bool True if tags written successfully, false on failure
     *
     * @example
     * ```php
     * $tags = [
     *   'title' => ['My Recording'],
     *   'artist' => ['John Doe'],
     *   'album' => ['Site Archives'],
     *   'year' => ['2025']
     * ];
     * $success = $service->writeTags('/path/to/audio.mp3', $tags, 123);
     * ```
     */
    public function writeTags(string $filepath, array $tagData): bool
    {
        if ( ! file_exists($filepath)) {
            StarmusLogger::error(
                'File missing for tagging',
                [
            'component' => self::class,
            'path'      => $filepath,
                ]
            );
            return false;
        }

        try {
            $engine = $this->getID3Engine();
            if ( ! $engine instanceof getID3) {
                return false;
            }

            // Ensure Writer is loaded
            if ( ! class_exists('getid3_writetags')) {
                StarmusLogger::error(
                    'getid3_writetags class missing.',
                    ['component' => self::class]
                );
                return false;
            }

            $tagwriter                    = new getid3_writetags();
            $tagwriter->filename          = $filepath;
            $tagwriter->tagformats        = ['id3v2.3'];
            $tagwriter->overwrite_tags    = true;
            $tagwriter->tag_encoding      = self::TEXT_ENCODING;
            $tagwriter->remove_other_tags = true;
            $tagwriter->tag_data          = $tagData;

            if ( ! $tagwriter->WriteTags()) {
                StarmusLogger::error(
                    'WriteTags Failed',
                    [
                'component' => self::class,
                'errors'    => $tagwriter->errors,
                    ]
                );
                return false;
            }

            if ($tagwriter->warnings !== []) {
                StarmusLogger::warning(
                    'WriteTags Warnings',
                    [
                'component' => self::class,
                'warnings'  => $tagwriter->warnings,
                    ]
                );
            }

            return true;
        } catch (Throwable $throwable) {
            StarmusLogger::log(
                $throwable,
                [
            'component' => self::class,
            'path'      => $filepath,
                ]
            );
            return false;
        }
    }

    /**
     * Check if file needs bandwidth optimization for African networks
     */
    public function needsAfricaOptimization(string $filepath): bool
    {
        $analysis = $this->analyzeFile($filepath);
        $audio    = $analysis['audio'] ?? [];

        $bitrate  = $audio['bitrate'] ?? 0;
        $filesize = $analysis['filesize'] ?? 0;

        // Optimize if > 64kbps or > 2MB
        return $bitrate > 64000 || $filesize > (2 * 1024 * 1024);
    }

    /**
     * Analyzes an audio file and extracts comprehensive metadata.
     *
     * Performs deep analysis of audio files to extract technical information,
     * embedded metadata, and format details using the getID3 library.
     *
     * @param string $filepath Absolute path to the audio file to analyze
     *
     * @return array Complete analysis data or empty array if library unavailable
     *
     * @since 1.0.0
     *
     * Analysis Data Includes:
     * - **Format Information**: File type, codec, bitrate, sample rate
     * - **Technical Details**: Duration, channels, bit depth
     * - **Metadata Tags**: ID3, Vorbis, APE, and other embedded tags
     * - **Audio Properties**: Lossless/lossy, VBR/CBR detection
     * - **File Structure**: Header information, stream details
     *
     * Processing Steps:
     * 1. Initialize getID3 engine with proper configuration
     * 2. Analyze file structure and format
     * 3. Extract technical audio properties
     * 4. Copy embedded tags to standardized comments array
     * 5. Return comprehensive analysis data
     *
     * Supported Formats:
     * - MP3 (all versions and VBR types)
     * - WAV (PCM and compressed)
     * - FLAC (lossless compression)
     * - OGG Vorbis (open source)
     * - WebM (modern web audio)
     * - Many others via getID3 library
     *
     * Return Data Structure:
     * ```php
     * [
     *   'fileformat' => 'mp3',
     *   'audio' => [
     *     'dataformat' => 'mp3',
     *     'bitrate' => 192000,
     *     'sample_rate' => 44100,
     *     'channels' => 2,
     *     'playtime_seconds' => 180.5
     *   ],
     *   'tags' => [
     *     'id3v2' => ['title' => ['Song'], 'artist' => ['Artist']]
     *   ],
     *   'comments' => [
     *     'title' => ['Song'],
     *     'artist' => ['Artist']
     *   ]
     * ]
     * ```
     * @see \getID3::analyze() Core analysis method
     * @see \getid3_lib::CopyTagsToComments() Tag standardization
     */
    public function analyzeFile(string $filepath): array
    {
        $engine = $this->getID3Engine();
        if ( ! $engine instanceof getID3) {
            return [];
        }

        $info = $engine->analyze($filepath);
        getid3_lib::CopyTagsToComments($info);
        return $info;
    }
}
