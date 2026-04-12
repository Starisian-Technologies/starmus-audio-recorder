# StarmusTerms

**Namespace:** `Starisian\Sparxstar\Starmus\data`

**File:** `/workspaces/starmus-audio-recorder/src/data/StarmusTerms.php`

## Description

SparxStar Legal Object Injections — FINAL
Pipeline:
  inject → validate → lock → seal
Properties:
  - Write-once forensic audit fields
  - Canonical deterministic JSON
  - HMAC integrity seal
  - Proxy-aware IP capture
  - Server UTC timestamp
  - Signature + Geolocation included in seal
  - Hard validation gate (blocks incomplete agreements)
Requirements:
  PHP 8.2+
  WordPress 6.8+
  ACF / SCF active

## Methods

### `__construct()`

**Visibility:** `public`

SparxStar Legal Object Injections — FINAL
Pipeline:
  inject → validate → lock → seal
Properties:
  - Write-once forensic audit fields
  - Canonical deterministic JSON
  - HMAC integrity seal
  - Proxy-aware IP capture
  - Server UTC timestamp
  - Signature + Geolocation included in seal
  - Hard validation gate (blocks incomplete agreements)
Requirements:
  PHP 8.2+
  WordPress 6.8+
  ACF / SCF active
/
namespace Starisian\Sparxstar\Starmus\data;

if ( ! \defined('ABSPATH')) {
    exit;
}

final class StarmusTerms
{
    /** ---------------- FIELD NAMES (SCF SOURCE OF TRUTH) ---------------- */

    private const FIELD_TERMS_TYPE = 'sparxstar_terms_type';

    private const FIELD_TERMS_PURPOSE = 'sparxstar_terms_purpose';

    private const FIELD_TERMS_URL = 'sparxstar_terms_url';

    private const FIELD_GEO_REQUIRED = 'sparxstar_terms_require_geolocation';

    private const FIELD_SIGNATORY = 'sparxstar_authorized_signatory';

    private const FIELD_TYPED_NAME = 'signatory_name';

    private const FIELD_UUID = 'sparxstar_signatory_submission_id';

    private const FIELD_SERVER_TS = 'sparxstar_server_timestamp';

    private const FIELD_CLIENT_TS = 'sparxstar_client_timestamp';

    private const FIELD_IP = 'sparxstar_signatory_ip';

    private const FIELD_UA = 'sparxstar_signatory_user_agent';

    private const FIELD_FINGERPRINT = 'sparxstar_signatory_fingerprint_id';

    private const FIELD_SIGNATURE = 'sparxstar_agreement_signature';

    private const FIELD_GEO = 'sparxstar_signatory_geolocation';

    private const FIELD_SEAL = 'sparxstar_agreement_seal';

    /** ---------------- AUDIT / SEALED FIELDS ---------------- */

    private const AUDIT_FIELDS = [
        self::FIELD_UUID,
        self::FIELD_SERVER_TS,
        self::FIELD_CLIENT_TS,
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

    /** ---------------- INIT ----------------

### `star_inject_uuid()`

**Visibility:** `public`

Injection */
        add_filter('acf/update_value/name=' . self::FIELD_UUID, $this->star_inject_uuid(...), 10, 3);
        add_filter('acf/update_value/name=' . self::FIELD_IP, $this->star_inject_ip(...), 10, 3);
        add_filter('acf/update_value/name=' . self::FIELD_UA, $this->star_inject_ua(...), 10, 3);
        add_filter('acf/update_value/name=' . self::FIELD_SERVER_TS, $this->star_inject_server_ts(...), 10, 3);

        /** Immutability */
        foreach (array_merge(self::AUDIT_FIELDS, [self::FIELD_SEAL]) as $field) {
            add_filter('acf/update_value/name=' . $field, $this->star_lock_write_once(...), 20, 4);
        }

        /** Hard validation gate */
        add_filter('acf/validate_value', $this->star_validate_legal_object(...), 20, 4);

        /** Seal AFTER save */
        add_action('acf/save_post', $this->star_generate_seal_after_save(...), 50);
    }

    /** ---------------- INJECTION ----------------

### `star_lock_write_once()`

**Visibility:** `public`

---------------- WRITE-ONCE LOCK ----------------

### `star_validate_legal_object()`

**Visibility:** `public`

---------------- VALIDATION GATE ----------------

### `star_generate_seal_after_save()`

**Visibility:** `public`

Typed name required */
        $name = get_field(self::FIELD_TYPED_NAME, $post_id);
        if (\in_array(trim((string)$name), ['', '0'], true)) {
            return 'Legal agreement requires typed legal name.';
        }

        /** Signwrap requires signature */
        $terms_type = get_field(self::FIELD_TERMS_TYPE, $post_id);
        if ($terms_type === 'signwrap') {
            $sig = get_field(self::FIELD_SIGNATURE, $post_id);
            if ( ! \is_array($sig) || empty($sig['ID'])) {
                return 'Signature is required for signwrap agreement.';
            }
        }

        /** Geo required if flagged */
        $geo_required = get_field(self::FIELD_GEO_REQUIRED, $post_id);
        if (\is_array($geo_required) && \in_array('Geolocation Required', $geo_required, true)) {
            $geo = get_field(self::FIELD_GEO, $post_id);
            if ( ! \is_array($geo) || empty($geo['lat']) || empty($geo['lng'])) {
                return 'Geolocation is required for this agreement.';
            }
        }

        return true;
    }

    /** ---------------- SEAL GENERATION ----------------

---

_Generated by Starisian Documentation Generator_
