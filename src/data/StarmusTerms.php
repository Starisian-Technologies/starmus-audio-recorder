<?php

declare(strict_types=1);

/**
 * SparxStar Legal Object Injections — FINAL
 *
 * Pipeline:
 *   inject → validate → lock → seal
 *
 * Properties:
 *   - Write-once forensic audit fields
 *   - Canonical deterministic JSON
 *   - HMAC integrity seal
 *   - Proxy-aware IP capture
 *   - Server UTC timestamp
 *   - Signature + Geolocation included in seal
 *   - Hard validation gate (blocks incomplete agreements)
 *
 * Requirements:
 *   PHP 8.2+
 *   WordPress 6.8+
 *   ACF / SCF active
 */
namespace Starisian\Sparxstar\Starmus\data;
if ( ! defined('ABSPATH')) {
    exit;
}

final class StarmusTerms
{
    /** ---------------- FIELD NAMES (SCF SOURCE OF TRUTH) ---------------- */

    private const FIELD_TERMS_TYPE     = 'starmus_terms_type';
    private const FIELD_TERMS_PURPOSE  = 'sparxstar_terms_purpose';
    private const FIELD_TERMS_URL      = 'sparxstar_terms_url';
    private const FIELD_GEO_REQUIRED   = 'sparxstar_terms_require_geolocation';

    private const FIELD_USER           = 'user';
    private const FIELD_SIGNATORY      = 'sparxstar_authorized_signatory';
    private const FIELD_TYPED_NAME     = 'signatory_name';

    private const FIELD_UUID           = 'sparxstar_signatory_submission_id';
    private const FIELD_SERVER_TS      = 'sparxstar_server_timestamp';
    private const FIELD_IP             = 'sparxstar_signatory_ip';
    private const FIELD_UA             = 'sparxstar_signatory_user_agent';
    private const FIELD_FINGERPRINT    = 'sparxstar_signatory_fingerprint_id';

    private const FIELD_SIGNATURE      = 'sparxstar_agreement_signature';
    private const FIELD_GEO            = 'sparxstar_signatory_geolocation';

    private const FIELD_SEAL           = 'sparxstar_agreement_seal';

    /** ---------------- AUDIT / SEALED FIELDS ---------------- */

    private const AUDIT_FIELDS = [
        self::FIELD_UUID,
        self::FIELD_SERVER_TS,
        self::FIELD_IP,
        self::FIELD_UA,
        self::FIELD_FINGERPRINT,
        self::FIELD_TERMS_TYPE,
        self::FIELD_TERMS_PURPOSE,
        self::FIELD_TERMS_URL,
        self::FIELD_SIGNATORY,
        self::FIELD_TYPED_NAME,
        self::FIELD_GEO,
        self::FIELD_SIGNATURE,
    ];

    private const SUPPORTED_POST_TYPES = [
        'audio-recording',
        'sparx_contributor',
        'contributor_word',
    ];

    /** ---------------- INIT ---------------- */

    public function __construct()
    {
        /** Injection */
        add_filter('acf/update_value/name=' . self::FIELD_UUID,      [$this, 'star_inject_uuid'], 10, 3);
        add_filter('acf/update_value/name=' . self::FIELD_IP,        [$this, 'star_inject_ip'], 10, 3);
        add_filter('acf/update_value/name=' . self::FIELD_UA,        [$this, 'star_inject_ua'], 10, 3);
        add_filter('acf/update_value/name=' . self::FIELD_SERVER_TS, [$this, 'star_inject_server_ts'], 10, 3);

        /** Immutability */
        foreach (array_merge(self::AUDIT_FIELDS, [self::FIELD_SEAL]) as $field) {
            add_filter('acf/update_value/name=' . $field, [$this, 'star_lock_write_once'], 20, 4);
        }

        /** Hard validation gate */
        add_filter('acf/validate_value', [$this, 'star_validate_legal_object'], 20, 4);

        /** Seal AFTER save */
        add_action('acf/save_post', [$this, 'star_generate_seal_after_save'], 50);
    }

    /** ---------------- INJECTION ---------------- */

    public function star_inject_uuid($value, $post_id, $field)
    {
        if ( ! $this->star_supported($post_id)) return $value;
        return empty($value) ? wp_generate_uuid4() : $value;
    }

    public function star_inject_ip($value, $post_id, $field)
    {
        if ( ! $this->star_supported($post_id)) return $value;
        if ( ! empty($value)) return $value;

        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';

        if (is_string($ip) && str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public function star_inject_ua($value, $post_id, $field)
    {
        if ( ! $this->star_supported($post_id)) return $value;
        return empty($value) ? ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown') : $value;
    }

    public function star_inject_server_ts($value, $post_id, $field)
    {
        if ( ! $this->star_supported($post_id)) return $value;
        return empty($value) ? current_time('mysql', true) : $value;
    }

    /** ---------------- WRITE-ONCE LOCK ---------------- */

    public function star_lock_write_once($value, $post_id, $field, $original)
    {
        if ( ! $this->star_supported($post_id)) return $value;

        $existing = get_post_meta($post_id, $field['name'], true);

        if (is_string($existing) && $existing !== '') return $existing;
        if (is_array($existing) && ! empty($existing)) return $existing;

        return $value;
    }

    /** ---------------- VALIDATION GATE ---------------- */

    public function star_validate_legal_object($valid, $value, $field, $input)
    {
        if ($valid !== true) return $valid;

        $post_id = $_POST['post_ID'] ?? null;
        if ( ! $this->star_supported($post_id)) return $valid;

        /** Typed name required */
        $name = get_field(self::FIELD_TYPED_NAME, $post_id);
        if (empty(trim((string)$name))) {
            return 'Legal agreement requires typed legal name.';
        }

        /** Signwrap requires signature */
        $terms_type = get_field(self::FIELD_TERMS_TYPE, $post_id);
        if ($terms_type === 'signwrap') {
            $sig = get_field(self::FIELD_SIGNATURE, $post_id);
            if ( ! is_array($sig) || empty($sig['ID'])) {
                return 'Signature is required for signwrap agreement.';
            }
        }

        /** Geo required if flagged */
        $geo_required = get_field(self::FIELD_GEO_REQUIRED, $post_id);
        if (is_array($geo_required) && in_array('Geolocation Required', $geo_required, true)) {
            $geo = get_field(self::FIELD_GEO, $post_id);
            if ( ! is_array($geo) || empty($geo['lat']) || empty($geo['lng'])) {
                return 'Geolocation is required for this agreement.';
            }
        }

        return true;
    }

    /** ---------------- SEAL GENERATION ---------------- */

    public function star_generate_seal_after_save($post_id)
    {
        if ( ! $this->star_supported($post_id)) return;

        $existing = get_post_meta($post_id, self::FIELD_SEAL, true);
        if ( ! empty($existing)) return;

        $payload = [];

        foreach (self::AUDIT_FIELDS as $field) {
            $value = get_field($field, $post_id);

            /** Normalize GEO */
            if ($field === self::FIELD_GEO && is_array($value)) {
                $value = [
                    'lat' => $value['lat'] ?? null,
                    'lng' => $value['lng'] ?? null,
                ];
            }

            /** Normalize signature → hash */
            if ($field === self::FIELD_SIGNATURE && is_array($value) && ! empty($value['ID'])) {
                $file = get_attached_file((int)$value['ID']);
                $value = (is_string($file) && is_readable($file))
                    ? hash_file('sha256', $file)
                    : null;
            }

            $payload[$field] = $value;
        }

        ksort($payload);

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ( ! $json) return;

        $seal = hash_hmac('sha256', $json, AUTH_SALT);

        update_post_meta($post_id, self::FIELD_SEAL, $seal);
        update_field(self::FIELD_SEAL, $seal, $post_id);
    }

    /** ---------------- SUPPORT CHECK ---------------- */

    private function star_supported($post_id): bool
    {
        if ( ! is_string($post_id) || ! ctype_digit($post_id)) return false;
        $type = get_post_type((int)$post_id);
        return in_array($type, self::SUPPORTED_POST_TYPES, true);
    }
}

