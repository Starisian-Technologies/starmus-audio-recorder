<?php
/**
 * Summary of namespace Starmus\helpers
 */
namespace Starmus\helpers;

if (!defined('ABSPATH')) {
    exit;
}

class StarmusMimeHelper
{
    public static function get_allowed_mimes(): array
    {
        return [
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'oga' => 'audio/ogg',
            'opus' => 'audio/ogg; codecs=opus',
            'weba' => 'audio/webm',
            'aac' => 'audio/aac',
            'm4a' => 'audio/mp4',
            'flac' => 'audio/flac',
            'mp4' => 'video/mp4',
            'm4v' => 'video/x-m4v',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'ogv' => 'video/ogg',
            'avi' => 'video/x-msvideo',
            'wmv' => 'video/x-ms-wmv',
            '3gp' => 'video/3gpp',
            '3g2' => 'video/3gpp2',
        ];
    }
}
