<?php
/**
 * Starmus Admin Handler - Refactored for Security & Performance
 *
 * @package Starmus\admin
 * @version 0.6.7
 * @since 0.3.1
 */

namespace Starmus\admin;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

use Starmus\includes\StarmusSettings;

/**
 * Secure and optimized admin settings class.
 */
class StarmusAdmin {

		/** Menu slug for the plugin settings page. */
		const STAR_MENU_SLUG = 'starmus-admin';

		/** Settings group identifier for WordPress options API. */
		const STAR_SETTINGS_GROUP = 'starmus_settings_group';

		/** Mapping of option keys to field types. */
	private array $field_types = array();

	/**
	 * Constructor - initializes admin settings.
	 *
	 * @since 0.3.1
	 */
	public function __construct() {
				$this->field_types = array(
					'cpt_slug'              => 'text',
					'file_size_limit'       => 'number',
					'allowed_file_types'    => 'textarea',
					'consent_message'       => 'textarea',
					'collect_ip_ua'         => 'checkbox',
					'edit_page_id'          => 'pages_dropdown',
					'recorder_page_id'      => 'pages_dropdown',
					'my_recordings_page_id' => 'pages_dropdown',
				);
	}

	/**
	 * Add admin menu with error handling.
	 *
	 * @since 0.3.1
	 */
	public function add_admin_menu(): void {
		$cpt_slug = StarmusSettings::get( 'cpt_slug', 'audio-recording' );
		if ( empty( $cpt_slug ) || ! $this->is_valid_cpt_slug( $cpt_slug ) ) {
			$cpt_slug = 'audio-recording';
		}

		$parent_slug = 'edit.php?post_type=' . sanitize_key( $cpt_slug );

		add_submenu_page(
			$parent_slug,
			__( 'Audio Recorder Settings', STARMUS_TEXT_DOMAIN ),
			__( 'Settings', STARMUS_TEXT_DOMAIN ),
			'manage_options',
			self::STAR_MENU_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Validate CPT slug format.
	 */
	private function is_valid_cpt_slug( string $slug ): bool {
		return preg_match( '/^[a-z0-9_-]+$/', $slug ) && strlen( $slug ) <= 20;
	}

	/**
	 * Render settings page with CSRF protection.
	 *
	 * @since 0.3.1
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions.', STARMUS_TEXT_DOMAIN ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Starmus Audio Recorder Settings', STARMUS_TEXT_DOMAIN ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::STAR_SETTINGS_GROUP );
				do_settings_sections( self::STAR_MENU_SLUG );
				submit_button( __( 'Save Settings', STARMUS_TEXT_DOMAIN ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Register settings with validation.
	 *
	 * @since 0.3.1
	 */
	public function register_settings(): void {
		register_setting(
			self::STAR_SETTINGS_GROUP,
			StarmusSettings::STAR_OPTION_KEY,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => StarmusSettings::get_defaults(),
			)
		);

		$this->add_settings_sections();
		$this->add_settings_fields();
	}

	/**
	 * Add settings sections.
	 */
	private function add_settings_sections(): void {
		add_settings_section(
			'starmus_cpt_section',
			__( 'Custom Post Type Settings', STARMUS_TEXT_DOMAIN ),
			null,
			self::STAR_MENU_SLUG
		);

		add_settings_section(
			'starmus_rules_section',
			__( 'File Upload & Recording Rules', STARMUS_TEXT_DOMAIN ),
			null,
			self::STAR_MENU_SLUG
		);

				add_settings_section(
					'starmus_privacy_section',
					__( 'Privacy & Form Settings', STARMUS_TEXT_DOMAIN ),
					null,
					self::STAR_MENU_SLUG
				);

				add_settings_section(
					'starmus_page_section',
					__( 'Frontend Page Settings', STARMUS_TEXT_DOMAIN ),
					null,
					self::STAR_MENU_SLUG
				);
	}

	/**
	 * Add settings fields.
	 */
	private function add_settings_fields(): void {
		$fields = array(
			'cpt_slug'              => array(
				'title'       => __( 'Post Type Slug', STARMUS_TEXT_DOMAIN ),
				'section'     => 'starmus_cpt_section',
				'description' => __( 'Use lowercase letters, numbers, and underscores only.', STARMUS_TEXT_DOMAIN ),
			),
			'file_size_limit'       => array(
				'title'       => __( 'Max File Size (MB)', STARMUS_TEXT_DOMAIN ),
				'section'     => 'starmus_rules_section',
				'description' => __( 'Maximum allowed file size for uploads.', STARMUS_TEXT_DOMAIN ),
			),
			'allowed_file_types'    => array(
				'title'       => __( 'Allowed File Extensions', STARMUS_TEXT_DOMAIN ),
				'section'     => 'starmus_rules_section',
				'description' => __( 'Comma-separated list of allowed extensions.', STARMUS_TEXT_DOMAIN ),
			),
			'consent_message'       => array(
				'title'       => __( 'Consent Checkbox Message', STARMUS_TEXT_DOMAIN ),
				'section'     => 'starmus_privacy_section',
				'description' => __( 'Text displayed next to consent checkbox.', STARMUS_TEXT_DOMAIN ),
			),
			'collect_ip_ua'         => array(
				'title'       => __( 'Store IP & User Agent', STARMUS_TEXT_DOMAIN ),
				'section'     => 'starmus_privacy_section',
				'label'       => __( 'Save submitter IP and user agent.', STARMUS_TEXT_DOMAIN ),
				'description' => __( 'Requires user consent.', STARMUS_TEXT_DOMAIN ),
			),
			'edit_page_id'          => array(
				'title'       => __( 'Edit Page', STARMUS_TEXT_DOMAIN ),
				'section'     => 'starmus_page_section',
				'description' => __( 'Page containing the audio editor shortcode.', STARMUS_TEXT_DOMAIN ),
			),
			'recorder_page_id'      => array(
				'title'       => __( 'Recorder Page', STARMUS_TEXT_DOMAIN ),
				'section'     => 'starmus_page_section',
				'description' => __( 'Page containing the [starmus-audio-recorder] shortcode.', STARMUS_TEXT_DOMAIN ),
			),
			'my_recordings_page_id' => array(
				'title'       => __( 'My Recordings Page', STARMUS_TEXT_DOMAIN ),
				'section'     => 'starmus_page_section',
				'description' => __( 'Page containing the [starmus-my-recordings] shortcode.', STARMUS_TEXT_DOMAIN ),
			),
		);

		foreach ( $fields as $id => $field ) {
			add_settings_field(
				$id,
				$field['title'],
				array( $this, 'render_field' ),
				self::STAR_MENU_SLUG,
				$field['section'],
				array_merge(
					array(
						'id'   => $id,
						'type' => $this->field_types[ $id ],
					),
					$field
				)
			);
		}
	}

	/**
	 * Sanitize settings with comprehensive validation.
	 */
	public function sanitize_settings( array $input ): array {
		$defaults = StarmusSettings::get_defaults();
		if ( ! is_array( $defaults ) ) {
			$defaults = array();
		}

		$sanitized = array();

		// CPT Slug
		$cpt_slug              = trim( $input['cpt_slug'] ?? '' );
		$sanitized['cpt_slug'] = $this->is_valid_cpt_slug( $cpt_slug ) ? $cpt_slug : ( $defaults['cpt_slug'] ?? 'audio-recording' );

		// File size limit
		$file_size                    = absint( $input['file_size_limit'] ?? 0 );
		$sanitized['file_size_limit'] = ( $file_size > 0 && $file_size <= 100 ) ? $file_size : ( $defaults['file_size_limit'] ?? 10 );

		// Allowed file types
		$file_types = sanitize_text_field( $input['allowed_file_types'] ?? '' );
		if ( ! empty( $file_types ) ) {
			$types                           = array_map( 'trim', explode( ',', $file_types ) );
			$types                           = array_filter( $types, array( $this, 'is_valid_file_extension' ) );
			$sanitized['allowed_file_types'] = implode( ',', $types );
		} else {
			$sanitized['allowed_file_types'] = $defaults['allowed_file_types'] ?? 'mp3,wav,webm';
		}

		// Consent message
		$sanitized['consent_message'] = wp_kses_post( $input['consent_message'] ?? $defaults['consent_message'] ?? '' );

		// Collect IP/UA
		$sanitized['collect_ip_ua'] = ! empty( $input['collect_ip_ua'] ) ? 1 : 0;

				// Edit page ID
				$page_id                   = absint( $input['edit_page_id'] ?? 0 );
				$sanitized['edit_page_id'] = ( $page_id > 0 && get_post( $page_id ) ) ? $page_id : 0;

				// Recorder page ID
				$page_id                       = absint( $input['recorder_page_id'] ?? 0 );
				$sanitized['recorder_page_id'] = ( $page_id > 0 && get_post( $page_id ) ) ? $page_id : 0;

				// My Recordings page ID
				$page_id                            = absint( $input['my_recordings_page_id'] ?? 0 );
				$sanitized['my_recordings_page_id'] = ( $page_id > 0 && get_post( $page_id ) ) ? $page_id : 0;

				return $sanitized;
	}

	/**
	 * Validate file extension.
	 */
	private function is_valid_file_extension( string $ext ): bool {
		$allowed = array( 'mp3', 'wav', 'webm', 'm4a', 'ogg', 'opus', 'flac' );
		return in_array( strtolower( trim( $ext ) ), $allowed, true );
	}

	/**
	 * Render form field with validation.
	 */
	public function render_field( array $args ): void {
		if ( empty( $args['id'] ) ) {
			return;
		}

		$id    = esc_attr( $args['id'] );
		$type  = $args['type'] ?? 'text';
		$value = StarmusSettings::get( $id );
		$name  = StarmusSettings::STAR_OPTION_KEY . "[$id]";

		switch ( $type ) {
			case 'textarea':
				printf(
					'<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>',
					$id,
					esc_attr( $name ),
					esc_textarea( $value )
				);
				break;

			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%s" name="%s" value="1" %s /> %s</label>',
					$id,
					esc_attr( $name ),
					checked( 1, $value, false ),
					esc_html( $args['label'] ?? '' )
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%s" name="%s" value="%s" class="small-text" min="1" max="100" />',
					$id,
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			case 'pages_dropdown':
				wp_dropdown_pages(
					array(
						'name'              => $name,
						'id'                => $id,
						'selected'          => $value,
						'show_option_none'  => __( '— Select a Page —', STARMUS_TEXT_DOMAIN ),
						'option_none_value' => '0',
					)
				);
				break;

			case 'text':
			default:
				printf(
					'<input type="text" id="%s" name="%s" value="%s" class="regular-text" />',
					$id,
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;
		}

		if ( ! empty( $args['description'] ) ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses( $args['description'], array( 'strong' => array() ) )
			);
		}
	}
}
