<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\core;

use WP_Error;

/**
 * Class StarmusConsentHandler
 *
 * Handles creation of contributors and consent recordings.
 */
class StarmusConsentHandler
{

    /**
     * Creates a sparx_contributor post.
     *
     * @param array<string, mixed> $data Contributor data. Must contain 'name' and 'email'.
     * @return int|WP_Error Post ID on success, WP_Error on failure.
     */
    public function create_contributor(array $data): int|WP_Error
    {
        if (empty($data['name']) || empty($data['email'])) {
            return new WP_Error('missing_data', 'Name and Email are required.');
        }

        $post_data = [
        'post_type'   => 'sparx_contributor',
        'post_title'  => sanitize_text_field($data['name']),
        'post_status' => 'publish',
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Update ACF fields.
        update_field('sparxstar_legal_name', $data['name'], $post_id);
        update_field('sparxstar_email', $data['email'], $post_id);

        return $post_id;
    }

    /**
     * Creates a draft audio-recording post for consent.
     *
     * @param int                  $contributor_id The ID of the sparx_contributor.
     * @param array<string, mixed> $consent_data   Consent data. keys: terms_type, signature, ip, user_agent.
     * @return int|WP_Error Recording ID on success, WP_Error on failure.
     */
    public function create_consent_recording(int $contributor_id, array $consent_data): int|WP_Error
    {
        // Validate contributor.
        $contributor = get_post($contributor_id);
        if ( ! $contributor || 'sparx_contributor' !== $contributor->post_type) {
            return new WP_Error('invalid_contributor', 'Invalid contributor ID.');
        }

        $contributor_name = get_the_title($contributor_id);

        // Prepare post data.
        $post_title = sprintf('Consent Agreement - %s - %s', $contributor_name, current_time('Y-m-d H:i:s'));
        $post_data  = [
        'post_type'   => 'audio-recording',
        'post_title'  => $post_title,
        'post_status' => 'draft',
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Update ACF fields.
        update_field('starmus_authorized_signatory', $contributor_id, $post_id);
        update_field('starmus_terms_type', $consent_data['terms_type'] ?? '', $post_id);

        if ( ! empty($consent_data['signature'])) {
            update_field('starmus_contributor_signature', $consent_data['signature'], $post_id);
        }

        update_field('starmus_agreement_datetime', current_time('Y-m-d H:i:s'), $post_id);
        update_field('starmus_contributor_ip', $consent_data['ip'] ?? '', $post_id);
        update_field('starmus_contributor_user_agent', $consent_data['user_agent'] ?? '', $post_id);

        // Archival fields.
        update_field('starmus_dc_creator', $contributor_name, $post_id);

        // Data Classification Default.
        $classification_default = [
        'starmus_data_sensitivity' => 'restricted',
        'starmus_consent_scope'    => [],
        'starmus_anon_status'      => 0,
        ];
        update_field('starmus_data_classification', $classification_default, $post_id);

        return $post_id;
    }
}
