<?php
/**
 * Starmus Admin Settings Handler
 *
 * Creates and manages the plugin's settings page.
 *
 * @package Starmus\src\admin
 */

namespace Starisian\src\admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StarmusAdminSettings
 */
class StarmusAdminSettings {

	/**
	 * The key for our settings array in the wp_options table.
	 */
	const OPTION_NAME = 'starmus_settings';

	/**
	 * The slug for the admin menu page.
	 */
	const MENU_SLUG = 'starmus-settings';

	/**
	 * Class constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Adds the submenu page under the Custom Post Type menu.
	 */
	public function add_admin_menu(): void {
		$parent_slug = 'edit.php?post_type=' . self::get_option( 'cpt_slug', 'starmus_submission' );
		add_submenu_page(
			$parent_slug,
			__( 'Audio Recorder Settings', 'starmus' ),
			__( 'Settings', 'starmus' ),
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
			<h1><?php esc_html_e( 'Starmus Audio Recorder Settings', 'starmus' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'starmus_settings_group' );
				do_settings_sections( self::MENU_SLUG );
				submit_button( __( 'Save Settings', 'starmus' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Registers settings, sections, and fields using the Settings API.
	 */
	public function register_settings(): void {
        // sanitize the submitted data
		register_setting( 'starmus_settings_group', self::OPTION_NAME, [ $this, 'sanitize_settings' ] );
        // refresh the class file of associated class
        register_setting( 'starmus_settings_group', self::OPTION_NAME, [ $this, 'sanitize_and_refresh' ] );

		// Section 1: Custom Post Type Settings
		add_settings_section(
			'starmus_cpt_section',
			__( 'Custom Post Type Settings', 'starmus' ),
			null,
			self::MENU_SLUG
		);

		add_settings_field(
			'cpt_slug',
			__( 'Post Type Slug', 'starmus' ),
			[ $this, 'render_text_field' ],
			self::MENU_SLUG,
			'starmus_cpt_section',
			[ 'id' => 'cpt_slug', 'description' => __( 'The internal name for the post type. Use lowercase letters, numbers, and underscores only. <strong>Warning: Changing this will hide existing submissions until it\'s changed back.</strong>', 'starmus' ) ]
		);

		// Section 2: File Upload and Recording Rules
		add_settings_section(
			'starmus_rules_section',
			__( 'File Upload & Recording Rules', 'starmus' ),
			null,
			self::MENU_SLUG
		);

		add_settings_field(
			'file_size_limit',
			__( 'Max File Size (MB)', 'starmus' ),
			[ $this, 'render_number_field' ],
			self::MENU_SLUG,
			'starmus_rules_section',
			[ 'id' => 'file_size_limit', 'description' => __( 'Maximum allowed file size for uploads in Megabytes.', 'starmus' ) ]
		);

		add_settings_field(
			'recording_time_limit',
			__( 'Max Recording Time (seconds)', 'starmus' ),
			[ $this, 'render_number_field' ],
			self::MENU_SLUG,
			'starmus_rules_section',
			[ 'id' => 'recording_time_limit', 'description' => __( 'Maximum duration for a browser recording in seconds. Set to 0 for no limit.', 'starmus' ) ]
		);

		add_settings_field(
			'allowed_file_types',
			__( 'Allowed File Extensions', 'starmus' ),
			[ $this, 'render_textarea_field' ],
			self::MENU_SLUG,
			'starmus_rules_section',
			[ 'id' => 'allowed_file_types', 'description' => __( 'Comma-separated list of allowed audio file extensions (e.g., mp3, wav, webm, m4a).', 'starmus' ) ]
		);

		// Section 3: Form Settings
		add_settings_section(
			'starmus_form_section',
			__( 'Submission Form Settings', 'starmus' ),
			null,
			self::MENU_SLUG
		);

		add_settings_field(
			'consent_message',
			__( 'Consent Checkbox Message', 'starmus' ),
			[ $this, 'render_textarea_field' ],
			self::MENU_SLUG,
			'starmus_form_section',
			[ 'id' => 'consent_message', 'description' => __( 'The text displayed next to the consent checkbox. Basic HTML is allowed.', 'starmus' ) ]
		);
	}

	/**
	 * Sanitizes all settings before saving to the database.
	 *
	 * @param array $input The submitted settings.
	 * @return array The sanitized settings.
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = [];

		$sanitized['cpt_slug'] = ! empty( $input['cpt_slug'] ) ? sanitize_key( $input['cpt_slug'] ) : 'starmus_submission';
		$sanitized['file_size_limit'] = isset( $input['file_size_limit'] ) ? absint( $input['file_size_limit'] ) : 10;
		$sanitized['recording_time_limit'] = isset( $input['recording_time_limit'] ) ? absint( $input['recording_time_limit'] ) : 300;

		if ( ! empty( $input['allowed_file_types'] ) ) {
			$types = explode( ',', $input['allowed_file_types'] );
			$types = array_map( 'trim', $types );
			$types = array_map( 'sanitize_text_field', $types );
			$types = array_filter( $types );
			$sanitized['allowed_file_types'] = implode( ',', $types );
		} else {
			$sanitized['allowed_file_types'] = 'mp3,wav,webm,m4a,ogg';
		}

		$sanitized['consent_message'] = ! empty( $input['consent_message'] ) ? wp_kses_post( $input['consent_message'] ) : '';

		return $sanitized;
	}

	// --- RENDER CALLBACKS ---
	public function render_text_field( array $args ): void {
		$value = self::get_option( $args['id'] );
		printf(
			'<input type="text" id="%s" name="%s[%s]" value="%s" class="regular-text" />',
			esc_attr( $args['id'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['id'] ),
			esc_attr( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	public function render_number_field( array $args ): void {
		$value = self::get_option( $args['id'] );
		printf(
			'<input type="number" id="%s" name="%s[%s]" value="%s" class="small-text" min="0" />',
			esc_attr( $args['id'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['id'] ),
			esc_attr( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	public function render_textarea_field( array $args ): void {
		$value = self::get_option( $args['id'] );
		printf(
			'<textarea id="%s" name="%s[%s]" rows="4" class="large-text">%s</textarea>',
			esc_attr( $args['id'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['id'] ),
			esc_textarea( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', wp_kses_post( $args['description'] ) );
		}
	}

	/**
	 * Helper function to safely get a setting value.
	 *
	 * @param string $key     The setting key to retrieve.
	 * @param mixed  $default Optional default value to return if the key is not set.
	 * @return mixed The value of the setting.
	 */
	public static function get_option( string $key, $default = '' ) {
		$options = get_option( self::OPTION_NAME );
		$defaults = [
			'cpt_slug'             => 'starmus_submission',
			'file_size_limit'      => 10,
			'recording_time_limit' => 300, // 5 minutes
			'allowed_file_types'   => 'mp3,wav,webm,m4a,ogg,opus',
			'consent_message'      => __( 'I consent to having this audio recording stored and used.', 'starmus' ),
		];
		
		return $options[ $key ] ?? $defaults[ $key ] ?? $default;
	}

     /**
     * Sanitize callback that also refreshes the other class.
     */
    public function sanitize_and_refresh( array $input ): array {
        $sanitized_input = $this->sanitize_settings( $input ); // Assuming you move sanitation to a separate method

        // AFTER WordPress saves the new options, we can tell the Singleton to reload.
        // We register an action that runs right after the option is updated.
        add_action( 'updated_option', function( $option_name ) {
            if ( self::OPTION_NAME === $option_name ) {
                 StarmusSubmissionManager::get_instance()->load_settings();
            }
        }, 10, 1 );

        return $sanitized_input;
    }
}