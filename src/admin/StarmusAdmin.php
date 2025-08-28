<?php
/**
 * Starmus Admin Handler
 *
 * Creates and manages the plugin's settings page.
 *
 * @package Starmus\admin
 */

namespace Starmus\admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Starmus\includes\StarmusSettings;

/**
 * Class StarmusAdmin
 */
class StarmusAdmin {

	const MENU_SLUG = 'starmus-admin';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Adds the submenu page under the Custom Post Type menu.
	 */
	public function add_admin_menu(): void {
		// REFACTOR: Use the get() method for consistency.
		$cpt_slug    = StarmusSettings::get( 'cpt_slug' );
		$parent_slug = 'edit.php?post_type=' . $cpt_slug;

		add_submenu_page(
			$parent_slug,
			__( 'Audio Recorder Settings', 'starmus_audio_recorder' ),
			__( 'Settings', 'starmus_audio_recorder' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Renders the main settings page wrapper and form.
	 */
	public function render_settings_page(): void {
		// No changes needed here. This is perfect.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Starmus Audio Recorder Settings', 'starmus_audio_recorder' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'starmus_settings_group' );
				do_settings_sections( self::MENU_SLUG );
				submit_button( __( 'Save Settings', 'starmus_audio_recorder' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Registers settings, sections, and fields using the Settings API.
	 */
	public function register_settings(): void {
		// REFACTOR: Use the constant from StarmusSettings for a single source of truth.
		register_setting( 'starmus_settings_group', StarmusSettings::OPTION_KEY, array( $this, 'sanitize_settings' ) );

		// Section 1: Custom Post Type Settings
		add_settings_section( 'starmus_cpt_section', __( 'Custom Post Type Settings', 'starmus_audio_recorder' ), null, self::MENU_SLUG );
		add_settings_field(
			'cpt_slug',
			__( 'Post Type Slug', 'starmus_audio_recorder' ),
			array( $this, 'render_field' ), // REFACTOR: Use the single render callback
			self::MENU_SLUG,
			'starmus_cpt_section',
			array(
				'id'          => 'cpt_slug',
				'type'        => 'text',
				'description' => __( 'The internal name for the post type. Use lowercase letters, numbers, and underscores only. <strong>Warning: Changing this will hide existing submissions until it\'s changed back.</strong>', 'starmus_audio_recorder' ),
			)
		);

		// Section 2: File Upload and Recording Rules
		add_settings_section( 'starmus_rules_section', __( 'File Upload & Recording Rules', 'starmus_audio_recorder' ), null, self::MENU_SLUG );
		add_settings_field(
			'file_size_limit',
			__( 'Max File Size (MB)', 'starmus_audio_recorder' ),
			array( $this, 'render_field' ), // REFACTOR: Use the single render callback
			self::MENU_SLUG,
			'starmus_rules_section',
			array(
				'id'          => 'file_size_limit',
				'type'        => 'number',
				'description' => __( 'Maximum allowed file size for uploads in Megabytes.', 'starmus_audio_recorder' ),
			)
		);
		add_settings_field(
			'allowed_file_types',
			__( 'Allowed File Extensions', 'starmus_audio_recorder' ),
			array( $this, 'render_field' ), // REFACTOR: Use the single render callback
			self::MENU_SLUG,
			'starmus_rules_section',
			array(
				'id'          => 'allowed_file_types',
				'type'        => 'textarea',
				'description' => __( 'Comma-separated list of allowed audio file extensions (e.g., mp3, wav, webm, m4a).', 'starmus_audio_recorder' ),
			)
		);

		// Section 3: Privacy Settings
		add_settings_section( 'starmus_privacy_section', __( 'Privacy & Form Settings', 'starmus_audio_recorder' ), null, self::MENU_SLUG );
		add_settings_field(
			'consent_message',
			__( 'Consent Checkbox Message', 'starmus_audio_recorder' ),
			array( $this, 'render_field' ), // REFACTOR: Use the single render callback
			self::MENU_SLUG,
			'starmus_privacy_section',
			array(
				'id'          => 'consent_message',
				'type'        => 'textarea',
				'description' => __( 'The text displayed next to the consent checkbox. Basic HTML is allowed.', 'starmus_audio_recorder' ),
			)
		);
		add_settings_field(
			'collect_ip_ua',
			__( 'Store IP & User Agent', 'starmus_audio_recorder' ),
			array( $this, 'render_field' ), // REFACTOR: Use the single render callback
			self::MENU_SLUG,
			'starmus_privacy_section',
			array(
				'id'          => 'collect_ip_ua',
				'type'        => 'checkbox',
				'label'       => __( 'Save submitter IP address and browser user agent.', 'starmus_audio_recorder' ),
				'description' => __( 'Requires user consent. Leave unchecked to anonymize submissions.', 'starmus_audio_recorder' ),
			)
		);
		// NEW: Add a setting for the edit page URL from the previous review
		add_settings_field(
			'edit_page_id',
			__( 'Edit Page', 'starmus_audio_recorder' ),
			array( $this, 'render_field' ),
			self::MENU_SLUG,
			'starmus_privacy_section',
			array(
				'id'          => 'edit_page_id',
				'type'        => 'pages_dropdown',
				'description' => __( 'Select the page that contains the `[starmus_audio_editor]` shortcode.', 'starmus_audio_recorder' ),
			)
		);
	}

	/**
	 * Sanitizes all settings before saving to the database.
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = array();
		// REFACTOR: Get defaults from the canonical source to stay in sync.
		$defaults = StarmusSettings::get_defaults();

		$sanitized['cpt_slug']        = ! empty( $input['cpt_slug'] ) ? sanitize_key( $input['cpt_slug'] ) : $defaults['cpt_slug'];
		$sanitized['file_size_limit'] = isset( $input['file_size_limit'] ) ? absint( $input['file_size_limit'] ) : $defaults['file_size_limit'];

		if ( ! empty( $input['allowed_file_types'] ) ) {
			$types                           = array_map( 'trim', explode( ',', $input['allowed_file_types'] ) );
			$types                           = array_map( 'sanitize_text_field', $types );
			$sanitized['allowed_file_types'] = implode( ',', array_filter( $types ) );
		} else {
			$sanitized['allowed_file_types'] = $defaults['allowed_file_types'];
		}

		$sanitized['consent_message'] = ! empty( $input['consent_message'] ) ? wp_kses_post( $input['consent_message'] ) : $defaults['consent_message'];
		$sanitized['collect_ip_ua']   = ! empty( $input['collect_ip_ua'] ) ? 1 : 0;
		$sanitized['edit_page_id']    = isset( $input['edit_page_id'] ) ? absint( $input['edit_page_id'] ) : 0;

		return $sanitized;
	}

	/**
	 * Universal render callback for all field types.
	 *
	 * @param array $args The arguments for the field.
	 */
	public function render_field( array $args ): void {
		$id    = esc_attr( $args['id'] );
		$type  = $args['type'] ?? 'text'; // Default to text input
		$value = StarmusSettings::get( $id );
		$name  = StarmusSettings::OPTION_KEY . "[$id]";

		switch ( $type ) {
			case 'textarea':
				printf( '<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>', $id, $name, esc_textarea( $value ) );
				break;

			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%s" name="%s" value="1" %s /> %s</label>',
					$id,
					$name,
					checked( 1, $value, false ),
					esc_html( $args['label'] ?? '' )
				);
				break;

			case 'number':
				printf( '<input type="number" id="%s" name="%s" value="%s" class="small-text" min="0" />', $id, $name, esc_attr( $value ) );
				break;

			case 'pages_dropdown':
				wp_dropdown_pages(
					array(
						'name'              => $name,
						'selected'          => $value,
						'show_option_none'  => __( '— Select a Page —', 'starmus_audio_recorder' ),
						'option_none_value' => '0',
					)
				);
				break;

			case 'text':
			default:
				printf( '<input type="text" id="%s" name="%s" value="%s" class="regular-text" />', $id, $name, esc_attr( $value ) );
				break;
		}

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', wp_kses( $args['description'], array( 'strong' => array() ) ) );
		}
	}
}
