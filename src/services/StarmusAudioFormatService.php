<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\services;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audio Format Converter and Optimizer
 *
 * Handles audio format conversion and optimization for web delivery,
 * working in conjunction with getID3 for format analysis.
 */
final class StarmusAudioFormatService {

	private ?StarmusId3Service $id3_service = null;

	public function __construct( StarmusId3Service $id3_service ) {
		$this->id3_service = $id3_service;
	}

	/**
	 * Analyze and recommend optimal format for web delivery
	 */
	public function analyzeForWebDelivery( string $filepath ): array {
		$analysis = $this->id3_service->analyzeFile( $filepath );

		if ( $analysis === array() ) {
			return array( 'error' => 'Could not analyze file' );
		}

		$audio  = $analysis['audio'] ?? array();
		$format = $analysis['fileformat'] ?? '';

		return array(
			'current_format'        => $format,
			'current_bitrate'       => $audio['bitrate'] ?? 0,
			'current_size'          => $analysis['filesize'] ?? 0,
			'recommendations'       => $this->getWebOptimizationRecommendations( $analysis ),
			'browser_support'       => $this->getBrowserSupport( $format ),
			'mobile_considerations' => $this->getMobileConsiderations( $analysis ),
		);
	}

	/**
	 * Get format recommendations for different use cases
	 */
	private function getWebOptimizationRecommendations( array $analysis ): array {
		$audio    = $analysis['audio'] ?? array();
		$bitrate  = $audio['bitrate'] ?? 0;
		$duration = $audio['playtime_seconds'] ?? 0;

		$recommendations = array();

		// High-quality archive version
		if ( $bitrate < 192000 ) {
			$recommendations['archive'] = array(
				'format'  => 'wav',
				'bitrate' => 'lossless',
				'reason'  => 'Preserve original quality for archival',
			);
		}

		// Web streaming version
		if ( $bitrate > 128000 || $duration > 300 ) {
			$recommendations['web'] = array(
				'format'  => 'mp3',
				'bitrate' => '128kbps',
				'reason'  => 'Optimize for web streaming and bandwidth',
			);
		}

		// Mobile version for low-bandwidth
		if ( $duration > 60 ) {
			$recommendations['mobile'] = array(
				'format'  => 'mp3',
				'bitrate' => '64kbps',
				'reason'  => 'Optimize for mobile and low-bandwidth connections',
			);
		}

		return $recommendations;
	}

	/**
	 * Check browser support for audio formats
	 */
	private function getBrowserSupport( string $format ): array {
		$support_matrix = array(
			'mp3'  => array(
				'chrome'  => true,
				'firefox' => true,
				'safari'  => true,
				'edge'    => true,
				'mobile'  => true,
				'legacy'  => true,
			),
			'wav'  => array(
				'chrome'  => true,
				'firefox' => true,
				'safari'  => true,
				'edge'    => true,
				'mobile'  => true,
				'legacy'  => false,
			),
			'ogg'  => array(
				'chrome'  => true,
				'firefox' => true,
				'safari'  => false,
				'edge'    => true,
				'mobile'  => false,
				'legacy'  => false,
			),
			'webm' => array(
				'chrome'  => true,
				'firefox' => true,
				'safari'  => false,
				'edge'    => true,
				'mobile'  => false,
				'legacy'  => false,
			),
		);

		return $support_matrix[ $format ] ?? array(
			'chrome'  => false,
			'firefox' => false,
			'safari'  => false,
			'edge'    => false,
			'mobile'  => false,
			'legacy'  => false,
		);
	}

	/**
	 * Get mobile-specific considerations
	 */
	private function getMobileConsiderations( array $analysis ): array {
		$audio    = $analysis['audio'] ?? array();
		$filesize = $analysis['filesize'] ?? 0;
		$duration = $audio['playtime_seconds'] ?? 0;
		$bitrate  = $audio['bitrate'] ?? 0;

		return array(
			'data_usage'          => $this->estimateDataUsage( $filesize, $duration ),
			'battery_impact'      => $this->estimateBatteryImpact( $bitrate, $duration ),
			'loading_time'        => $this->estimateLoadingTime( $filesize ),
			'recommended_quality' => $this->getRecommendedMobileQuality( $duration, $filesize ),
		);
	}

	/**
	 * Generate multiple format versions for progressive enhancement
	 */
	public function generateFormatManifest( string $filepath ): array {
		$analysis = $this->id3_service->analyzeFile( $filepath );

		if ( $analysis === array() ) {
			return array();
		}

		$base_name = pathinfo( $filepath, PATHINFO_FILENAME );
		$dir       = \dirname( $filepath );

		return array(
			'original'     => array(
				'path'     => $filepath,
				'format'   => $analysis['fileformat'] ?? 'unknown',
				'quality'  => 'original',
				'use_case' => 'archive',
			),
			'web_high'     => array(
				'path'     => \sprintf( '%s/%s_web_high.mp3', $dir, $base_name ),
				'format'   => 'mp3',
				'quality'  => '192kbps',
				'use_case' => 'desktop_streaming',
			),
			'web_standard' => array(
				'path'     => \sprintf( '%s/%s_web_standard.mp3', $dir, $base_name ),
				'format'   => 'mp3',
				'quality'  => '128kbps',
				'use_case' => 'general_web',
			),
			'mobile'       => array(
				'path'     => \sprintf( '%s/%s_mobile.mp3', $dir, $base_name ),
				'format'   => 'mp3',
				'quality'  => '64kbps',
				'use_case' => 'mobile_low_bandwidth',
			),
		);
	}

	// Helper methods
	private function estimateDataUsage( int $filesize, float $duration ): array {
		$mb_size       = $filesize / ( 1024 * 1024 );
		$mb_per_minute = $duration > 0 ? ( $mb_size / ( $duration / 60 ) ) : 0;

		return array(
			'total_mb'         => round( $mb_size, 2 ),
			'mb_per_minute'    => round( $mb_per_minute, 2 ),
			'data_plan_impact' => $mb_size > 50 ? 'high' : ( $mb_size > 10 ? 'medium' : 'low' ),
		);
	}

	private function estimateBatteryImpact( int $bitrate, float $duration ): string {
		$processing_load = ( $bitrate / 1000 ) * ( $duration / 60 );

		if ( $processing_load > 1000 ) {
			return 'high';
		}

		if ( $processing_load > 500 ) {
			return 'medium';
		}

		return 'low';
	}

	private function estimateLoadingTime( int $filesize ): array {
		$mb_size = $filesize / ( 1024 * 1024 );

		return array(
			'3g'   => round( $mb_size / 0.5, 1 ) . 's', // ~0.5 MB/s
			'4g'   => round( $mb_size / 2, 1 ) . 's',   // ~2 MB/s
			'wifi' => round( $mb_size / 10, 1 ) . 's', // ~10 MB/s
		);
	}

	private function getRecommendedMobileQuality( float $duration, int $filesize ): string {
		$mb_size = $filesize / ( 1024 * 1024 );

		if ( $duration > 600 || $mb_size > 50 ) {
			return '64kbps';
		}

		// Long recordings
		if ( $duration > 300 || $mb_size > 25 ) {
			return '96kbps';
		}

		// Medium recordings
		return '128kbps'; // Short recordings
	}
}
