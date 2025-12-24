<?php

declare(strict_types=1);

/**
 * getID3 Implementation Comparison
 * Official Documentation vs Starmus Audio Recorder
 *
 * Demonstrates how StarmusId3Service wraps the getID3 library while using
 * StarmusLogger with PSR-3 compliant argument patterns.
 */

// ===== OFFICIAL GETID3 BASIC USAGE =====

// Basic analysis.
$getID3       = new getID3();
$ThisFileInfo = $getID3->analyze( $filename );
getid3_lib::CopyTagsToComments( $ThisFileInfo );

// Basic tag writing.
$tagwriter                 = new getid3_writetags();
$tagwriter->filename       = '/path/to/file.mp3';
$tagwriter->tagformats     = array( 'id3v1', 'id3v2.3' );
$tagwriter->overwrite_tags = true;
$tagwriter->tag_encoding   = 'UTF-8';
$tagwriter->tag_data       = $TagData;
$tagwriter->WriteTags();

// ===== STARMUS ENHANCED IMPLEMENTATION =====

/**
 * Demonstration wrapper showing compliant logger usage.
 */
class StarmusId3Service {

	/**
	 * Standard text encoding for all tag operations.
	 */
	private const TEXT_ENCODING = 'UTF-8';

	/**
	 * Retrieve a configured getID3 engine instance.
	 *
	 * @return \getID3|null Configured engine or null when unavailable.
	 */
	private function getID3Engine(): ?\getID3 {
		if ( ! class_exists( 'getID3' ) ) {
			$possible_path = WP_CONTENT_DIR . '/plugins/starmus-audio-recorder/vendor/autoload.php';
			if ( file_exists( $possible_path ) ) {
				require_once $possible_path;
			}
		}

		if ( ! class_exists( 'getID3' ) ) {
			StarmusLogger::error(
				'getID3 library class not found.',
				array( 'component' => __CLASS__ )
			);
			return null;
		}

		$getID3 = new \getID3();
		$getID3->setOption( array( 'encoding' => self::TEXT_ENCODING ) );
		return $getID3;
	}

	/**
	 * Enhanced tag writing with proper logger context.
	 *
	 * @param string $filepath Path to audio file.
	 * @param array  $tagData  Tag data to write.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function writeTags( string $filepath, array $tagData ): bool {
		if ( ! file_exists( $filepath ) ) {
			StarmusLogger::error(
				'File missing for tagging',
				array(
					'component' => __CLASS__,
					'file'      => $filepath,
				)
			);
			return false;
		}

		try {
			$engine = $this->getID3Engine();
			if ( ! $engine instanceof \getID3 ) {
				return false;
			}

			if ( ! class_exists( 'getid3_writetags' ) ) {
				StarmusLogger::error(
					'getid3_writetags class missing.',
					array( 'component' => __CLASS__ )
				);
				return false;
			}

			$tagwriter                    = new \getid3_writetags();
			$tagwriter->filename          = $filepath;
			$tagwriter->tagformats        = array( 'id3v2.3' ); // Standardized format
			$tagwriter->overwrite_tags    = true;
			$tagwriter->tag_encoding      = self::TEXT_ENCODING;
			$tagwriter->remove_other_tags = true; // Clean slate approach
			$tagwriter->tag_data          = $tagData;

			if ( ! $tagwriter->WriteTags() ) {
				StarmusLogger::error(
					'WriteTags Failed',
					array(
						'component' => __CLASS__,
						'errors'    => $tagwriter->errors,
					)
				);
				return false;
			}

			if ( $tagwriter->warnings !== array() ) {
				StarmusLogger::warning(
					'WriteTags Warnings',
					array(
						'component' => __CLASS__,
						'warnings'  => $tagwriter->warnings,
					)
				);
			}

			return true;
		} catch ( \Throwable $throwable ) {
			StarmusLogger::log(
				$throwable,
				array(
					'component' => __CLASS__,
					'path'      => $filepath,
				)
			);
			return false;
		}
	}

	/**
	 * Analyze an audio file with logger-aware error handling.
	 *
	 * @param string $filepath File to analyze.
	 *
	 * @return array Analysis data.
	 */
	public function analyzeFile( string $filepath ): array {
		$engine = $this->getID3Engine();
		if ( ! $engine instanceof \getID3 ) {
			return array();
		}

		$info = $engine->analyze( $filepath );
		\getid3_lib::CopyTagsToComments( $info );
		return $info;
	}
}

// ===== KEY DIFFERENCES =====
// 1. Library loading with Composer fallback.
// 2. PSR-3 compliant StarmusLogger usage.
// 3. Standardized ID3v2.3 tag format.
// 4. UTF-8 encoding enforcement.
// 5. WordPress-aware file handling and error reporting.
