<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\core;

use WP_Error;

/**
 * Class StarmusConsentHandler
 *
 * Transport + post builder for legal consent records.
 */
class StarmusConsentHandler
{
    /**
     * Find or create contributor by email.
     *
     * @param string $name Contributor name.
     * @param string $email Contributor email.
     *
     * @return int|WP_Error Post ID on success, WP_Error on failure.
     */
    public function get_or_create_contributor(string $name, string $email): int|WP_Error
    {
        if ($name === '' || $email === '') {
            return new WP_Error('missing_data', 'Name and email required.');
        }

        $existing = get_posts([
            'post_type' => 'sparx_contributor',
            'meta_key' => 'sparxstar_email',
            'meta_value' => $email,
            'numberposts' => 1,
            'fields' => 'ids',
        ]);

        if ( ! empty($existing)) {
            return (int) $existing[0];
        }

        $post_data = [
            'post_type' => 'sparx_contributor',
            'post_title' => sanitize_text_field($name),
            'post_status' => 'publish',
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Update ACF fields.
        update_field('sparxstar_legal_name', $name, $post_id);
        update_field('sparxstar_email', $email, $post_id);

        return $post_id;
    }

    /**
     * Create or attach legal record to any post type.
     *
     * @param string $post_type Target post type.
     * @param array<string, mixed> $consent_data Consent payload.
     * @param int|null $existing_post_id Optional existing post.
     *
     * @return int|WP_Error Recording ID on success, WP_Error on failure.
     */
    public function create_legal_record(string $post_type, array $consent_data, ?int $existing_post_id = null): int|WP_Error
    {
        if (empty($consent_data['sparxstar_authorized_signatory'])) {
            return new WP_Error('missing_signatory', 'Authorized signatory required.');
        }

        $signatory_id = (int) $consent_data['sparxstar_authorized_signatory'];
        $signatory = get_post($signatory_id);
        if ( ! $signatory) {
            return new WP_Error('invalid_signatory', 'Invalid contributor.');
        }

        if ($existing_post_id === null) {
            $title = \sprintf(
                'Legal Agreement - %s - %s',
                $signatory->post_title,
                current_time('Y-m-d H:i:s')
            );

            $post_id = wp_insert_post([
                'post_type' => $post_type,
                'post_title' => $title,
                'post_status' => 'draft',
            ], true);

            if (is_wp_error($post_id)) {
                return $post_id;
            }
        } else {
            $post_id = $existing_post_id;
        }

        $this->apply_consent_data($post_id, $consent_data);

        return $post_id;
    }

    /**
     * Create a consent recording using the canonical consent payload.
     *
     * @param int $contributor_id Contributor post ID.
     * @param array<string, mixed> $data Consent payload.
     *
     * @return int|WP_Error Recording ID on success, WP_Error on failure.
     */
    public function create_consent_recording(int $contributor_id, array $data): int|WP_Error
    {
        $consent_data = [
            'sparxstar_terms_type' => $data['sparxstar_terms_type'] ?? '',
            'sparxstar_terms_purpose' => $data['sparxstar_terms_purpose'] ?? '',
            'sparxstar_terms_url' => $data['sparxstar_terms_url'] ?? '',
            'sparxstar_authorized_signatory' => $contributor_id,
            'signatory_name' => $data['signatory_name'] ?? '',
            'user' => $data['user'] ?? 0,
            'sparxstar_signatory_fingerprint_id' => $data['sparxstar_signatory_fingerprint_id'] ?? '',
            'sparxstar_agreement_signature' => $data['sparxstar_agreement_signature'] ?? '',
            'sparxstar_signatory_geolocation' => $data['sparxstar_signatory_geolocation'] ?? '',
            'sparxstar_client_timestamp' => $data['sparxstar_client_timestamp'] ?? '',
        ];

        return $this->create_legal_record('audio-recording', $consent_data);
    }

    /**
     * Write canonical consent fields only. StarmusTerms handles injection, validation, and sealing.
     *
     * @param int $post_id Target post ID.
     * @param array<string, mixed> $consent_data Consent payload.
     */
    private function apply_consent_data(int $post_id, array $consent_data): void
    {
        $allowed_fields = [
            'sparxstar_terms_type',
            'sparxstar_terms_purpose',
            'sparxstar_terms_url',
            'sparxstar_authorized_signatory',
            'signatory_name',
            'user',
            'sparxstar_signatory_fingerprint_id',
            'sparxstar_agreement_signature',
            'sparxstar_signatory_geolocation',
            'sparxstar_client_timestamp',
        ];

        foreach ($allowed_fields as $field) {
            if (\array_key_exists($field, $consent_data)) {
                update_field($field, $consent_data[$field], $post_id);
            }
        }

        $injector_fields = [
            'sparxstar_signatory_submission_id',
            'sparxstar_signatory_ip',
            'sparxstar_signatory_user_agent',
            'sparxstar_server_timestamp',
        ];

        foreach ($injector_fields as $field) {
            update_field($field, '', $post_id);
        }
    }
}
