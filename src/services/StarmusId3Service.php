<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\services;

if (! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

/**
 * Wraps getID3 library.
 * PHP 8.2 Compatible.
 */
final class StarmusId3Service
{
    private const TEXT_ENCODING = 'UTF-8';

    /**
     * Initializes the getID3 core engine.
     */
    private function getID3Engine(): ?\getID3
    {
        if (!class_exists('getID3')) {
            // Check if we can manually load it from a common vendor path if composer autoload failed context
            $possible_path = WP_CONTENT_DIR . '/plugins/starmus-audio-recorder/vendor/autoload.php';
            if (file_exists($possible_path)) {
                require_once $possible_path;
            }
        }

        if (!class_exists('getID3')) {
            StarmusLogger::error('StarmusId3Service', 'getID3 library class not found.');
            return null;
        }

        $getID3 = new \getID3();
        $getID3->setOption(['encoding' => self::TEXT_ENCODING]);
        return $getID3;
    }

    public function writeTags(string $filepath, array $tagData, int $post_id): bool
    {
        if (!file_exists($filepath)) {
            StarmusLogger::error('StarmusId3Service', 'File missing for tagging', ['file' => $filepath]);
            return false;
        }

        try {
            $engine = $this->getID3Engine();
            if (!$engine instanceof \getID3) return false;

            // Ensure Writer is loaded
            if (!class_exists('getid3_writetags')) {
                StarmusLogger::error('StarmusId3Service', 'getid3_writetags class missing.');
                return false;
            }

            $tagwriter = new \getid3_writetags();
            $tagwriter->filename          = $filepath;
            $tagwriter->tagformats        = ['id3v2.3'];
            $tagwriter->overwrite_tags    = true;
            $tagwriter->tag_encoding      = self::TEXT_ENCODING;
            $tagwriter->remove_other_tags = true;
            $tagwriter->tag_data          = $tagData;

            if (! $tagwriter->WriteTags()) {
                StarmusLogger::error('StarmusId3Service', 'WriteTags Failed', ['errors' => $tagwriter->errors]);
                return false;
            }

            if (!empty($tagwriter->warnings)) {
                StarmusLogger::warning('StarmusId3Service', 'WriteTags Warnings', ['warnings' => $tagwriter->warnings]);
            }

            return true;
        } catch (\Throwable $e) {
            StarmusLogger::error('StarmusId3Service', 'Exception', ['msg' => $e->getMessage()]);
            return false;
        }
    }

    public function analyzeFile(string $filepath): array
    {
        $engine = $this->getID3Engine();
        if (!$engine instanceof \getID3) return [];

        $info = $engine->analyze($filepath);
        \getid3_lib::CopyTagsToComments($info);
        return $info;
    }
}