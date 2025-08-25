<?php
/**
 * Starmus Admin Handler
 *
 * Creates and manages the plugin's settings page.
 *
 * @package Starmys\admin
 */

namespace Starmus\admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StarmusAdmin
 */
class StarmusAdmin {

	const OPTION_NAME = 'starmus_admin';
	const MENU_SLUG = 'starmus-admin';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Adds the submenu page under the Custom Post Type menu.
	 */
	public function add_admin_menu(): void {
		// FIX #2: Ensure the fallback here matches the main default.
		$parent_slug = 'edit.php?post_type=' . self::get_option( 'cpt_slug', 'audio-recording' );
		add_submenu_page(
			$parent_slug,
			__( 'Audio Recorder Settings', STARMUS_TEXT_DOMAIN ),
			__( 'Settings', STARMUS_TEXT_DOMAIN ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Renders the main settings page wrapper and form.
	 */
	public function render_settings_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Starmus Audio Recorder Settings', STARMUS_TEXT_DOMAIN ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'starmus_settings_group' );
				do_settings_sections( self::MENU_SLUG );
				submit_button( __( 'Save Settings', STARMUS_TEXT_DOMAIN ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Registers settings, sections, and fields using the Settings API.
	 */
	public function register_settings(): void {
        register_setting( 'starmus_settings_group', self::OPTION_NAME, [ $this, 'sanitize_settings' ] );

		// Section 1: Custom Post Type Settings
		add_settings_section( 'starmus_cpt_section', __( 'Custom Post Type Settings', STARMUS_TEXT_DOMAIN ), null, self::MENU_SLUG );
		add_settings_field(
			'cpt_slug',
			__( 'Post Type Slug', STARMUS_TEXT_DOMAIN ),
			[ $this, 'render_text_field' ],
			self::MENU_SLUG,
			'starmus_cpt_section',
			[ 'id' => 'cpt_slug', 'description' => __( 'The internal name for the post type. Use lowercase letters, numbers, and underscores only. <strong>Warning: Changing this will hide existing submissions until it\'s changed back.</strong>', STARMUS_TEXT_DOMAIN ) ]
		);

		// Section 2: File Upload and Recording Rules
		add_settings_section( 'starmus_rules_section', __( 'File Upload & Recording Rules', STARMUS_TEXT_DOMAIN ), null, self::MENU_SLUG );
		add_settings_field(
			'file_size_limit',
			__( 'Max File Size (MB)', STARMUS_TEXT_DOMAIN ),
			[ $this, 'render_number_field' ],
			self::MENU_SLUG,
			'starmus_rules_section',
			[ 'id' => 'file_size_limit', 'description' => __( 'Maximum allowed file size for uploads in Megabytes.', STARMUS_TEXT_DOMAIN ) ]
		);
		add_settings_field(
			'recording_time_limit',
			__( 'Max Recording Time (seconds)', STARMUS_TEXT_DOMAIN ),
			[ $this, 'render_number_field' ],
			self::MENU_SLUG,
			'starmus_rules_section',
			[ 'id' => 'recording_time_limit', 'description' => __( 'Maximum duration for a browser recording in seconds. Set to 0 for no limit.', STARMUS_TEXT_DOMAIN ) ]
		);
		add_settings_field(
			'allowed_file_types',
			__( 'Allowed File Extensions', STARMUS_TEXT_DOMAIN ),
			[ $this, 'render_textarea_field' ],
			self::MENU_SLUG,
			'starmus_rules_section',
			[ 'id' => 'allowed_file_types', 'description' => __( 'Comma-separated list of allowed audio file extensions (e.g., mp3, wav, webm, m4a).', STARMUS_TEXT_DOMAIN ) ]
		);

		// Section 3: Form Settings
		add_settings_section( 'starmus_form_section', __( 'Submission Form Settings', STARMUS_TEXT_DOMAIN ), null, self::MENU_SLUG );
                add_settings_field(
                        'consent_message',
                        __( 'Consent Checkbox Message', STARMUS_TEXT_DOMAIN ),
                        [ $this, 'render_textarea_field' ],
                        self::MENU_SLUG,
                        'starmus_form_section',
                        [ 'id' => 'consent_message', 'description' => __( 'The text displayed next to the consent checkbox. Basic HTML is allowed.', STARMUS_TEXT_DOMAIN ) ]
                );

                // Section 4: Privacy Settings
                add_settings_section( 'starmus_privacy_section', __( 'Privacy Settings', STARMUS_TEXT_DOMAIN ), null, self::MENU_SLUG );
                add_settings_field(
                        'collect_ip_ua',
                        __( 'Store IP & User Agent', STARMUS_TEXT_DOMAIN ),
                        [ $this, 'render_checkbox_field' ],
                        self::MENU_SLUG,
                        'starmus_privacy_section',
                        [
                                'id'          => 'collect_ip_ua',
                                'label'       => __( 'Save submitter IP address and browser user agent.', STARMUS_TEXT_DOMAIN ),
                                'description' => __( 'Requires user consent. Leave unchecked to anonymize submissions.', STARMUS_TEXT_DOMAIN ),
                        ]
                );
                add_settings_field(
                        'data_policy_url',
                        __( 'Data Policy URL', STARMUS_TEXT_DOMAIN ),
                        [ $this, 'render_text_field' ],
                        self::MENU_SLUG,
                        'starmus_privacy_section',
                        [ 'id' => 'data_policy_url', 'description' => __( 'Optional link shown above the form for your privacy or data policy.', STARMUS_TEXT_DOMAIN ) ]
                );
        }

	/**
	 * Sanitizes all settings before saving to the database.
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = [];
		$sanitized['cpt_slug'] = ! empty( $input['cpt_slug'] ) ? sanitize_key( $input['cpt_slug'] ) : 'audio-recording';
		$sanitized['file_size_limit'] = isset( $input['file_size_limit'] ) ? absint( $input['file_size_limit'] ) : 10;
		$sanitized['recording_time_limit'] = isset( $input['recording_time_limit'] ) ? absint( $input['recording_time_limit'] ) : 300;

		if ( ! empty( $input['allowed_file_types'] ) ) {
			$types = array_map('trim', explode(',', $input['allowed_file_types']));
			$types = array_map('sanitize_text_field', $types);
			$sanitized['allowed_file_types'] = implode(',', array_filter($types));
		} else {
			$sanitized['allowed_file_types'] = 'mp3,wav,webm,m4a,ogg';
		}

                $sanitized['consent_message'] = ! empty( $input['consent_message'] ) ? wp_kses_post( $input['consent_message'] ) : '';

                $sanitized['collect_ip_ua'] = ! empty( $input['collect_ip_ua'] ) ? 1 : 0;
                $sanitized['data_policy_url'] = ! empty( $input['data_policy_url'] ) ? esc_url_raw( $input['data_policy_url'] ) : '';

                return $sanitized;
        }

	// --- RENDER CALLBACKS ---
	public function render_text_field( array $args ): void {
		$value = self::get_option( $args['id'] );
		printf(
			'<input type="text" id="%s" name="%s[%s]" value="%s" class="regular-text" />',
			esc_attr( $args['id'] ), esc_attr( self::OPTION_NAME ), esc_attr( $args['id'] ), esc_attr( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			// FIX #3: Use wp_kses for consistency, allowing bold tags.
			printf( '<p class="description">%s</p>', wp_kses( $args['description'], ['strong' => []] ) );
		}
	}

	public function render_number_field( array $args ): void {
		$value = self::get_option( $args['id'] );
		printf(
			'<input type="number" id="%s" name="%s[%s]" value="%s" class="small-text" min="0" />',
			esc_attr( $args['id'] ), esc_attr( self::OPTION_NAME ), esc_attr( $args['id'] ), esc_attr( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			// FIX #3: Use wp_kses for consistency.
			printf( '<p class="description">%s</p>', wp_kses( $args['description'], ['strong' => []] ) );
		}
	}

        public function render_textarea_field( array $args ): void {
                $value = self::get_option( $args['id'] );
                printf(
                        '<textarea id="%s" name="%s[%s]" rows="4" class="large-text">%s</textarea>',
                        esc_attr( $args['id'] ), esc_attr( self::OPTION_NAME ), esc_attr( $args['id'] ), esc_textarea( $value )
                );
                if ( ! empty( $args['description'] ) ) {
                        printf( '<p class="description">%s</p>', wp_kses( $args['description'], ['strong' => []] ) );
                }
        }

        public function render_checkbox_field( array $args ): void {
                $value = self::get_option( $args['id'] );
                printf(
                        '<label><input type="checkbox" id="%s" name="%s[%s]" value="1" %s /> %s</label>',
                        esc_attr( $args['id'] ),
                        esc_attr( self::OPTION_NAME ),
                        esc_attr( $args['id'] ),
                        checked( 1, $value, false ),
                        isset( $args['label'] ) ? esc_html( $args['label'] ) : ''
                );
                if ( ! empty( $args['description'] ) ) {
                        printf( '<p class="description">%s</p>', wp_kses( $args['description'], [ 'strong' => [] ] ) );
                }
        }

	/**
	 * Helper function to safely get a setting value.
	 */
	public static function get_option( string $key, $default = '' ) {
		$options = get_option( self::OPTION_NAME );
		$defaults = [
			'cpt_slug'             => 'audio-recording',
			'file_size_limit'      => 10,
			'recording_time_limit' => 300,
                        'allowed_file_types'   => 'mp3,wav,webm,m4a,ogg,opus',
                        'consent_message'      => __( 'I consent to having this audio recording stored and used.', STARMUS_TEXT_DOMAIN ),
                        'collect_ip_ua'        => 0,
                        'data_policy_url'      => '',
                ];
		
		return $options[ $key ] ?? $defaults[ $key ] ?? $default;
	}

}
