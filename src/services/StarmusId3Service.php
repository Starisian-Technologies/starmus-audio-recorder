<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\services;

if (! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

/**
 * Wraps the procedural james-heinrich/getid3 library for both reading and writing.
 * This isolates the global-namespace dependency on getID3 classes.
 *
 * NOTE: getID3, getid3_writetags (and other needed files) must be autoloaded
 * via Composer's "files" autoloading for this to work.
 */
final class StarmusId3Service
{
    /**
     * Text encoding for ID3 tags.
     *
     * @var string
     */
    private const TEXT_ENCODING = 'UTF-8';

    /**
     * Initializes the getID3 core engine (for reading and general setup).
     */
    private function getID3Engine(): ?\getID3
    {
        if (!class_exists('getID3')) {
            StarmusLogger::error('ID3 Service', 'Cannot load getID3 library. Check Composer autoload config.', ['class' => 'getID3']);
            return null;
        }

        $getID3 = new \getID3();
        $getID3->setOption(['encoding' => self::TEXT_ENCODING]);
        // Set options for robust reading, e.g., low memory use or specific data extraction
        // $getID3->option_md5_data = true; // Only enable if you absolutely need this hash.

        return $getID3;
    }

    /**
     * Writes ID3v2.3 tags to an audio file.
     * This is the functionality you needed for the post-processing pipeline.
     *
     * @param string $filepath Absolute path to the MP3 file.
     * @param array $tagData The tag payload in getID3 format (e.g., ['title' => ['Value']]).
     * @param int $post_id The parent post ID for logging context.
     *
     * @return bool True on successful write (or successful run with warnings).
     */
    public function writeTags(string $filepath, array $tagData, int $post_id): bool
    {
        if (!file_exists($filepath) || !class_exists('getid3_writetags')) {
            StarmusLogger::error('ID3 Writer', 'Required ID3 writer class not available or file is missing.', ['file' => $filepath]);
            return false;
        }

        try {
            // Need to initialize the reader engine first for options to be set correctly
            if (!$this->getID3Engine() instanceof \getID3) {
                return false;
            }

            // Initialize getID3 tag-writing module
            $tagwriter = new \getid3_writetags();

            $tagwriter->filename          = $filepath;
            $tagwriter->tagformats        = ['id3v2.3'];
            $tagwriter->overwrite_tags    = true;
            $tagwriter->tag_encoding      = self::TEXT_ENCODING;
            $tagwriter->remove_other_tags = true;
            $tagwriter->tag_data          = $tagData;

            if (! $tagwriter->WriteTags()) {
                StarmusLogger::error(
                    'ID3 Writer Error',
                    'Failed to write ID3 tags: ' . implode('; ', $tagwriter->errors),
                    ['file' => $filepath, 'post_id' => $post_id]
                );
                return false;
            }

            if ($tagwriter->warnings !== []) {
                StarmusLogger::warning('ID3 Writer Warning', 'ID3 tags written with warnings: ' . implode('; ', $tagwriter->warnings), ['file' => $filepath, 'post_id' => $post_id]);
            }

            return true;
        } catch (\Throwable $throwable) {
            StarmusLogger::error('ID3 Writer Exception', $throwable->getMessage(), ['file' => $filepath, 'post_id' => $post_id]);
            return false;
        }
    }

    /**
     * Reads all available metadata from a file.
     * This is needed for deep analysis (like ReplayGain) or display.
     *
     * @param string $filepath Absolute path to the file.
     *
     * @return array The full analysis array from getID3.
     */
    public function analyzeFile(string $filepath): array
    {
        $engine = $this->getID3Engine();
        if (!$engine || !file_exists($filepath)) {
            return ['error' => 'File not found or engine failed to initialize.'];
        }

        $analysis = $engine->analyze($filepath);

        // This is a common and useful step: copy tags to comments for a unified, flat view
        if (method_exists($engine, 'CopyTagsToComments')) {
            $engine->CopyTagsToComments($analysis);
        }

        return $analysis;
    }

    // NOTE: The database integration should typically remain outside this service,
    // in a DAL (Data Access Layer) class, as integrating MySQL queries here
    // would violate SRP. The service only provides the *data*.
}
