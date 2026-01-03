<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\services;

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;


if ( ! \defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enhanced getID3 Service with Advanced Audio Processing
 *
 * Extends the base ID3 service with modern audio processing capabilities
 * inspired by the broader getID3 ecosystem on GitHub.
 */
final class StarmusEnhancedId3Service extends StarmusId3Service {

    /**
     * Extract comprehensive audio metadata with enhanced processing
     */
    public function extractEnhancedMetadata( string $filepath ): array {
        $analysis = $this->analyzeFile( $filepath );

        if ( $analysis === [] ) {
            return [];
        }

        return [
        'technical'     => $this->extractTechnicalData( $analysis ),
        'metadata'      => $this->extractStandardizedMetadata( $analysis ),
        'quality'       => $this->assessAudioQuality( $analysis ),
        'compatibility' => $this->checkFormatCompatibility( $analysis ),
        ];
    }

    /**
     * Extract technical audio properties
     */
    private function extractTechnicalData( array $analysis ): array {
        $audio = $analysis['audio'] ?? [];

        return [
        'format'       => $analysis['fileformat'] ?? 'unknown',
        'codec'        => $audio['dataformat'] ?? 'unknown',
        'bitrate'      => $audio['bitrate'] ?? 0,
        'sample_rate'  => $audio['sample_rate'] ?? 0,
        'channels'     => $audio['channels'] ?? 0,
        'duration'     => $audio['playtime_seconds'] ?? 0,
        'bitrate_mode' => $audio['bitrate_mode'] ?? 'unknown',
        'lossless'     => $audio['lossless'] ?? false,
        'file_size'    => $analysis['filesize'] ?? 0,
        ];
    }

    /**
     * Standardize metadata from various tag formats
     */
    private function extractStandardizedMetadata( array $analysis ): array {
        $comments = $analysis['comments'] ?? [];

        return [
        'title'        => $this->getFirstValue( $comments['title'] ?? [] ),
        'artist'       => $this->getFirstValue( $comments['artist'] ?? [] ),
        'album'        => $this->getFirstValue( $comments['album'] ?? [] ),
        'year'         => $this->getFirstValue( $comments['year'] ?? [] ),
        'genre'        => $this->getFirstValue( $comments['genre'] ?? [] ),
        'comment'      => $this->getFirstValue( $comments['comment'] ?? [] ),
        'track_number' => $this->getFirstValue( $comments['track_number'] ?? [] ),
        'language'     => $this->detectLanguage( $comments ),
        ];
    }

    /**
     * Assess audio quality for Starmus use cases
     */
    private function assessAudioQuality( array $analysis ): array {
        $audio       = $analysis['audio'] ?? [];
        $bitrate     = $audio['bitrate'] ?? 0;
        $sample_rate = $audio['sample_rate'] ?? 0;

        return [
        'quality_tier'         => $this->determineQualityTier( $bitrate, $sample_rate ),
        'suitable_for_web'     => $bitrate >= 64000 && $bitrate <= 320000,
        'suitable_for_archive' => $bitrate >= 128000 || ( $audio['lossless'] ?? false ),
        'mobile_friendly'      => $bitrate <= 128000,
        'bandwidth_estimate'   => $this->estimateBandwidth( $bitrate ),
        ];
    }

    /**
     * Check format compatibility for different browsers/devices
     */
    private function checkFormatCompatibility( array $analysis ): array {
        $format = $analysis['fileformat'] ?? '';

        return [
        'web_compatible'         => \in_array( $format, [ 'mp3', 'wav', 'ogg', 'webm' ] ),
        'mobile_compatible'      => \in_array( $format, [ 'mp3', 'wav' ] ),
        'legacy_compatible'      => $format === 'mp3',
        'modern_web'             => \in_array( $format, [ 'webm', 'ogg' ] ),
        'recommended_conversion' => $this->getRecommendedFormat( $format ),
        ];
    }

    /**
     * Generate Starmus-specific metadata for recordings
     */
    public function generateStarmusMetadata( int $post_id, array $form_data ): array {
        $site_name    = get_bloginfo( 'name' );
        $current_year = date( 'Y' );

        return [
        'title'             => [ $form_data['title'] ?? 'Recording #' . $post_id ],
        'artist'            => [ $form_data['speaker_name'] ?? get_bloginfo( 'name' ) ],
        'album'             => [ $site_name . ' Audio Archive' ],
        'year'              => [ $current_year ],
        'comment'           => [ $this->buildComment( $form_data ) ],
        'copyright_message' => [ \sprintf( '© %s %s', $current_year, $site_name ) ],
        'publisher'         => [ $site_name ],
        'language'          => [ $form_data['language'] ?? 'en' ],
        'genre'             => [ $this->mapRecordingTypeToGenre( $form_data['recording_type'] ?? '' ) ],
        ];
    }

    /**
     * Batch process multiple audio files
     */
    public function batchProcessAudio( array $file_paths ): array {
        $results = [];

        foreach ( $file_paths as $path ) {
            if ( ! file_exists( $path ) ) {
                continue;
            }

            $results[ basename( (string) $path ) ] = [
            'path'         => $path,
            'metadata'     => $this->extractEnhancedMetadata( $path ),
            'processed_at' => time(),
            ];
        }

        return $results;
    }

    // Helper methods
    private function getFirstValue( array $values ): string {
        return $values[0] ?? '';
    }

    private function determineQualityTier( int $bitrate, int $sample_rate ): string {
        if ( $bitrate >= 256000 && $sample_rate >= 44100 ) {
            return 'high';
        }

        if ( $bitrate >= 128000 && $sample_rate >= 22050 ) {
            return 'medium';
        }

        return 'low';
    }

    private function estimateBandwidth( int $bitrate ): string {
        $kbps = $bitrate / 1000;
        return $kbps . ' kbps';
    }

    private function getRecommendedFormat( string $current_format ): string {
        $web_formats = [ 'mp3', 'wav', 'ogg', 'webm' ];
        return \in_array( $current_format, $web_formats ) ? $current_format : 'mp3';
    }

    private function buildComment( array $form_data ): string {
        $parts = [];

        if ( ! empty( $form_data['description'] ) ) {
            $parts[] = $form_data['description'];
        }

        if ( ! empty( $form_data['location'] ) ) {
            $parts[] = 'Location: ' . $form_data['location'];
        }

        return implode( ' | ', $parts );
    }

    private function mapRecordingTypeToGenre( string $recording_type ): string {
        $mapping = [
        'interview'    => 'Speech',
        'oral-history' => 'Documentary',
        'music'        => 'Music',
        'podcast'      => 'Podcast',
        'lecture'      => 'Educational',
        ];

        return $mapping[ $recording_type ] ?? 'Other';
    }

    private function detectLanguage( array $comments ): string {
        // Simple language detection based on metadata
        if ( isset( $comments['language'] ) ) {
            return $this->getFirstValue( $comments['language'] );
        }

        // Could integrate with more sophisticated language detection
        return 'en';
    }
}
