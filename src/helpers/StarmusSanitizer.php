<?php
namespace Starmus\helpers;

if (!defined('ABSPATH')) {
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
     * @param array $data Raw request parameters.
     * @return array Sanitized data.
     */
    public static function sanitize_submission_data(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $clean[sanitize_key($key)] = array_map('sanitize_text_field', $value);
            } else {
                $clean[sanitize_key($key)] = sanitize_text_field($value);
            }
        }

        return $clean;
    }

    /**
     * Sanitize structured metadata for saving into CPT/attachment.
     *
     * Maps form fields into normalized meta keys.
     *
     * @param array $form_data Sanitized form parameters.
     * @return array Key → Value metadata array.
     */
    public static function sanitize_metadata(array $form_data): array
    {
        $meta = [];

        // Standard fields
        if (!empty($form_data['starmus_title'])) {
            $meta['_starmus_title'] = sanitize_text_field($form_data['starmus_title']);
        }
        if (!empty($form_data['description'])) {
            $meta['_starmus_description'] = sanitize_textarea_field($form_data['description']);
        }
        if (!empty($form_data['language'])) {
            $meta['_starmus_language'] = sanitize_text_field($form_data['language']);
        }
        if (!empty($form_data['dialect'])) {
            $meta['_starmus_dialect'] = sanitize_text_field($form_data['dialect']);
        }
        if (!empty($form_data['project_id'])) {
            $meta['_starmus_project_id'] = sanitize_text_field($form_data['project_id']);
        }
        if (!empty($form_data['interview_type'])) {
            $meta['_starmus_interview_type'] = sanitize_text_field($form_data['interview_type']);
        }

        // Contributor info
        if (!empty($form_data['contributor_name'])) {
            $meta['_contributor_name'] = sanitize_text_field($form_data['contributor_name']);
        }
        if (!empty($form_data['contributor_role'])) {
            $meta['_contributor_role'] = sanitize_text_field($form_data['contributor_role']);
        }
        if (!empty($form_data['translator'])) {
            $meta['_translator'] = sanitize_text_field($form_data['translator']);
        }

        // Content classification
        if (!empty($form_data['story_type'])) {
            $meta['_story_type'] = sanitize_text_field($form_data['story_type']);
        }
        if (!empty($form_data['rating'])) {
            $meta['_content_rating'] = sanitize_text_field($form_data['rating']);
        }
        if (!empty($form_data['verification'])) {
            $meta['_contributor_verification'] = sanitize_text_field($form_data['verification']);
        }

        // Location context
        if (!empty($form_data['geolocation'])) {
            $meta['_geolocation'] = sanitize_text_field($form_data['geolocation']);
        }
        if (!empty($form_data['countries_lived']) && is_array($form_data['countries_lived'])) {
            $meta['_countries_lived'] = array_map('sanitize_text_field', $form_data['countries_lived']);
        }

        // Custom fields passthrough (prefix enforcement)
        foreach ($form_data as $key => $value) {
            if (str_starts_with($key, 'custom_')) {
                $meta['_' . sanitize_key($key)] = sanitize_text_field($value);
            }
        }

        return $meta;
    }
}
