<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\frontend;

use Starisian\Sparxstar\Starmus\core\StarmusConsentHandler;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper;
use WP_Error;

if ( ! defined('ABSPATH')) {
    exit;
}

class StarmusConsentUI
{
    private const MAX_UPLOAD_SIZE = 5 * 1024 * 1024; // 5MB

    // Strict MIME allowlist for validation
    private const ALLOWED_MIMES = [
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    private ?StarmusConsentHandler $handler = null;
    private ?StarmusSettings $settings = null;

    public function __construct(StarmusConsentHandler $handler, StarmusSettings $settings)
    {
        $this->handler = $handler;
        $this->settings = $settings;
    }

    public function register_hooks(): void
    {
        // template_redirect ensures environment is loaded but headers aren't sent
        add_action('template_redirect', [$this, 'handle_submission'], 20);
    }

    public function render_shortcode(): string
    {
        return StarmusTemplateLoaderHelper::secure_render_template('starmus-consent-form.php');
    }

    public function handle_submission(): void
    {
        // 1. Gatekeeping
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset($_POST['starmus_consent_action'])) {
            return;
        }

        if ( ! wp_verify_nonce($_POST['starmus_consent_nonce'] ?? '', 'starmus_consent_action')) {
            wp_die('Security check failed (Nonce).', 'Starmus Security', ['response' => 403]);
        }

        if ( ! is_user_logged_in()) {
            wp_die('You must be logged in to sign this agreement.', 'Starmus Security', ['response' => 403]);
        }

        // 2. Load File Handlers (Frontend Context)
        if ( ! function_exists('media_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        // 3. Capture & Sanitize Data
        // Trim names to prevent whitespace variations from breaking the seal hash later
        $legal_name = trim(sanitize_text_field(wp_unslash($_POST['sparxstar_legal_name'] ?? '')));
        $email      = sanitize_email(wp_unslash($_POST['sparxstar_email'] ?? ''));
        $terms_type = sanitize_text_field(wp_unslash($_POST['starmus_terms_type'] ?? 'clickwrap'));
        $purpose    = sanitize_text_field(wp_unslash($_POST['sparxstar_terms_purpose'] ?? 'contribute'));

        // Fingerprint: Normalize (trim) then truncate
        $fingerprint = trim(sanitize_text_field(wp_unslash($_POST['sparxstar_signatory_fingerprint_id'] ?? '')));
        $fingerprint = substr($fingerprint, 0, 128);

        // Geo: Strict Typing (Float) + Finite Check
        $raw_lat = $_POST['sparxstar_lat'] ?? '';
        $raw_lng = $_POST['sparxstar_lng'] ?? '';

        $lat = ($raw_lat !== '') ? (float) wp_unslash($raw_lat) : null;
        $lng = ($raw_lng !== '') ? (float) wp_unslash($raw_lng) : null;

        // Basic Validation
        if (empty($legal_name) || empty($email) || ! is_email($email)) {
            $this->redirect_with_error('missing_required_fields');
            return;
        }

        // 4. Handle Signature Upload (Hardened)
        $attachment_id = 0;

        if ( ! empty($_FILES['starmus_contributor_signature']['name'])) {
            $file = $_FILES['starmus_contributor_signature'];

            // A. Size Check
            if ($file['size'] > self::MAX_UPLOAD_SIZE) {
                $this->redirect_with_error('file_too_large');
                return;
            }

            // B. Hardened MIME Check using WordPress Core
            // We use WP to check the file, then validate strictly against our allowlist.
            $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);

            if (empty($check['type']) || ! in_array($check['type'], self::ALLOWED_MIMES, true)) {
                $this->redirect_with_error('invalid_file_type');
                return;
            }

            // C. Process Upload
            $upload_id = media_handle_upload('starmus_contributor_signature', 0);
            if (is_wp_error($upload_id)) {
                $this->redirect_with_error('upload_failed');
                return;
            }
            $attachment_id = $upload_id;
        }

        // 5. Signwrap Logic Enforcement (Early Exit)
        if ($terms_type === 'signwrap' && empty($attachment_id)) {
            $this->redirect_with_error('signature_required');
            return;
        }

        // 6. Create/Update Contributor
        $contributor_id = $this->handler->create_contributor([
            'name'  => $legal_name,
            'email' => $email,
        ]);

        if (is_wp_error($contributor_id)) {
            $this->redirect_with_error('contributor_error');
            return;
        }

        // 7. Prepare Consent Payload
        // Fields map to SparxStar_Legal_Object_Injections_Final constants
        $consent_data = [
            'starmus_terms_type'                 => $terms_type,
            'sparxstar_terms_purpose'            => $purpose,
            'signatory_name'                     => $legal_name,
            'sparxstar_authorized_signatory'     => $contributor_id,
            'user'                               => get_current_user_id(),
            'sparxstar_signatory_fingerprint_id' => $fingerprint,
            'sparxstar_agreement_signature'      => $attachment_id ?: '',
        ];

        // Inject Terms URL
        $terms_url = $this->settings->get('terms_url');
        if ( ! empty($terms_url)) {
            $consent_data['sparxstar_terms_url'] = esc_url_raw($terms_url);
        }

        // Geo Data: Ensure strictly finite numbers to prevent JSON breakage during sealing
        if (
            $lat !== null
            && $lng !== null
            && ( ! function_exists('is_finite') || (is_finite($lat) && is_finite($lng)))
        ) {
            $consent_data['sparxstar_signatory_geolocation'] = [
                'lat' => $lat,
                'lng' => $lng,
            ];
        }

        // 8. Create Recording (Destination Class handles UUID, IP, Seal)
        $recording_id = $this->handler->create_consent_recording($contributor_id, $consent_data);

        if (is_wp_error($recording_id)) {
            $message = $recording_id->get_error_message();
            $this->redirect_with_error('recording_failed', $message);
            return;
        }

        // 9. Success Redirect
        nocache_headers();

        $recorder_page_id = (int) $this->settings->get('recorder_page_id');
        $redirect_url = $recorder_page_id ? get_permalink($recorder_page_id) : home_url();

        $redirect_url = add_query_arg([
            'starmus_recording_id' => $recording_id,
            'starmus_status'       => 'signed'
        ], $redirect_url);

        wp_safe_redirect($redirect_url);
        exit;
    }

    private function redirect_with_error(string $error_code, string $detail = ''): void
    {
        nocache_headers();

        $referer = wp_get_referer();
        $target_url = ($referer && wp_validate_redirect($referer, false)) ? $referer : home_url();

        $url = add_query_arg([
            'starmus_error'  => $error_code,
            'starmus_detail' => sanitize_text_field($detail)
        ], $target_url);

        wp_safe_redirect($url);
        exit;
    }
}
