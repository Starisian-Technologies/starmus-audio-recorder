<?php

namespace Starisian\Sparxstar\Starmus\helpers;

if (! \defined('ABSPATH')) {
    exit;
}

/**
 * Sanitizer for Starmus audio submissions.
 *
 * Handles general request params and structured metadata.
 */
class StarmusSanitizer
{
    /**
     * Sanitize general submission data from forms or REST params.
     *
     * @param array<string, mixed> $data Raw request parameters.
     *
     * @return array<string, mixed> Sanitized data.
     */
    public static function sanitize_submission_data(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            $clean[sanitize_key($key)] = \is_array($value) ? array_map(sanitize_text_field(...), $value) : sanitize_text_field($value);
        }

        return $clean;
    }

    /**
     * Sanitize structured metadata for saving into CPT/attachment.
     *
     * Maps form fields into normalized meta keys.
     *
     * @param array<string, mixed> $form_data Sanitized form parameters.
     *
     * @return array<string, mixed> Key → Value metadata array.
     */
    public static function sanitize_metadata(array $form_data): array
    {
        $meta = [];

        // Standard fields
        if (! empty($form_data['starmus_title'])) {
            $meta['_starmus_title'] = sanitize_text_field($form_data['starmus_title']);
        }

        if (! empty($form_data['description'])) {
            $meta['_starmus_description'] = sanitize_textarea_field($form_data['description']);
        }

        if (! empty($form_data['language'])) {
            $meta['_starmus_language'] = sanitize_text_field($form_data['language']);
        }

        if (! empty($form_data['dialect'])) {
            $meta['_starmus_dialect'] = sanitize_text_field($form_data['dialect']);
        }

        if (! empty($form_data['project_id'])) {
            $meta['_starmus_project_id'] = sanitize_text_field($form_data['project_id']);
        }

        if (! empty($form_data['interview_type'])) {
            $meta['_starmus_interview_type'] = sanitize_text_field($form_data['interview_type']);
        }

        // Content classification
        if (! empty($form_data['story_type'])) {
            $meta['_story_type'] = sanitize_text_field($form_data['story_type']);
        }

        if (! empty($form_data['rating'])) {
            $meta['_content_rating'] = sanitize_text_field($form_data['rating']);
        }

        // Location context
        if (! empty($form_data['geolocation'])) {
            $meta['_geolocation'] = sanitize_text_field($form_data['geolocation']);
        }

        if (! empty($form_data['countries_lived']) && \is_array($form_data['countries_lived'])) {
            $meta['_countries_lived'] = array_map(sanitize_text_field(...), $form_data['countries_lived']);
        }

        // Custom fields passthrough (prefix enforcement)
        foreach ($form_data as $key => $value) {
            if (str_starts_with((string) $key, 'custom_')) {
                $meta['_' . sanitize_key($key)] = sanitize_text_field($value);
            }
        }

        return $meta;
    }

    public static function get_user_ip(): string
    {
        $ipaddress = '';
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ipaddress = sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipaddress = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
        } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
            $ipaddress = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED']));
        } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ipaddress = sanitize_text_field(wp_unslash($_SERVER['HTTP_FORWARDED_FOR']));
        } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
            $ipaddress = sanitize_text_field(wp_unslash($_SERVER['HTTP_FORWARDED']));
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ipaddress = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        } else {
            $ipaddress = '0.0.0.0';
        }

        return trim($ipaddress);
    }
}
