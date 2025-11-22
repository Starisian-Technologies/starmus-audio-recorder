<?php

/**
 * Starmus Admin Handler - Refactored for Security & Performance
 *
 * @package Starisian\Sparxstar\Starmus\admin
 * @version 0.8.5
 * @since 0.3.1
 */

namespace Starisian\Sparxstar\Starmus\admin;

if (! defined('ABSPATH')) {
	return;
}

use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;
use Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface;

use Starisian\Sparxstar\Starmus\core\StarmusSettings;

/**
 * Secure and optimized admin settings class.
 */
class StarmusAdmin
{


	/**
	 * Menu slug for the plugin settings page.
	 *
	 * @var string
	 */
	const STARMUS_MENU_SLUG = 'starmus-admin';

	/**
	 * Settings group identifier for WordPress options API.
	 *
	 * @var string
	 */
	const STARMUS_SETTINGS_GROUP = 'starmus_settings_group';

	// DELETED: const STARMUS_OPTION_KEY =   <-- This line should be gone.

	/**
	 * Mapping of option keys to field types for rendering.
	 *
	 * @var array<string, string>
	 */
	private array $field_types            = array();

	/**
	 * Settings service instance.
	 *
	 * @var StarmusSettings|null
	 */
	private ?StarmusSettings $settings    = null;

	/**
	 * Data Access Layer instance.
	 *
	 * @var StarmusAudioRecorderDALInterface|null
	 */
	private ?\Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface $dal = null;

	/**
	 * Constructor - initializes admin settings and hooks.
	 *
	 * Sets up DAL and Settings dependencies, defines field type mappings,
	 * and registers admin hooks for menu and settings registration.
	 *
	 * @param StarmusAudioRecorderDALInterface $DAL      Data Access Layer instance.
	 * @param StarmusSettings                  $settings Settings service instance.
	 * @return void
	 * @throws \Throwable If initialization fails.
	 * @since 0.3.1
	 */
	public function __construct(StarmusAudioRecorderDALInterface $DAL, StarmusSettings $settings)
	{
		try {
			$this->dal         = $DAL;
			$this->settings    = $settings;
			$this->field_types = array(
				'cpt_slug'              => 'text',
				'file_size_limit'       => 'number',
				'allowed_file_types'    => 'textarea',
				'allowed_languages'     => 'text',
				'consent_message'       => 'textarea',
				'collect_ip_ua'         => 'checkbox',
				'delete_on_uninstall'   => 'checkbox',
				'edit_page_id'          => 'slug_input',
				'recorder_page_id'      => 'slug_input',
				'my_recordings_page_id' => 'slug_input',
			);
			$this->register_hooks();
		} catch (\Throwable $e) {
			\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::log('StarmusAdmin::__construct', $e);
			throw $e;
		}
	}

	private function register_hooks(): void
	{
		add_action('admin_menu', array($this, 'add_admin_menu'));
		// REVERTED: Back to admin_init for register_settings.
		add_action('admin_init', array($this, 'register_settings'));
	}

	/**
	 * Add admin menu with error handling.
	 *
	 * @since 0.3.1
	 */
	public function add_admin_menu(): void
	{
		try {
			$cpt_slug = $this->settings->get('cpt_slug', 'audio-recording');

			if (empty($cpt_slug) || ! $this->is_valid_cpt_slug($cpt_slug)) {
				$cpt_slug = 'audio-recording';
			}

			$parent_slug = 'edit.php?post_type=' . \sanitize_key($cpt_slug);

			\add_submenu_page(
				$parent_slug,
				\__('Audio Recorder Settings', 'starmus-audio-recorder'),
				\__('Settings', 'starmus-audio-recorder'),
				'manage_options',
				self::STARMUS_MENU_SLUG,
				array($this, 'render_settings_page')
			);
		} catch (\Throwable $e) {
			\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::log('StarmusAdmin::add_admin_menu', $e);
		}
	}

	/**
	 * Validate custom post type slug format.
	 *
	 * Ensures slug contains only lowercase letters, numbers, hyphens, and underscores,
	 * and does not exceed 20 characters.
	 *
	 * @param string $slug The CPT slug to validate.
	 * @return bool True if valid, false otherwise.
	 */
	private function is_valid_cpt_slug(string $slug): bool
	{
		return preg_match('/^[a-z0-9_-]+$/', $slug) && strlen($slug) <= 20;
	}

	/**
	 * Render admin settings page with CSRF protection.
	 *
	 * REVERTED: Back to standard settings page rendering using WordPress Settings API.
	 * Checks user capabilities, displays settings form with nonce fields,
	 * and handles error display.
	 *
	 * @return void
	 */
	public function render_settings_page(): void
	{
		try {
			if (! current_user_can('manage_options')) {
				wp_die(__('You do not have sufficient permissions.', 'starmus-audio-recorder'));
			}
?>
			<div class="wrap">
				<h1><?php esc_html_e('SPARXSTAR<sup>&trade;</sup>S tarmus Audio Recorder Settings', 'starmus-audio-recorder'); ?></h1>

				<?php settings_errors(); // Display any admin notices/errors 
				?>

				<form action="<?php echo esc_url('options.php'); ?>" method="post">
					<?php
					// These are now correctly hooked by register_settings() again.
					settings_fields(self::STARMUS_SETTINGS_GROUP);
					do_settings_sections(self::STARMUS_MENU_SLUG);
					submit_button(esc_html__('Save Settings', 'starmus-audio-recorder'));
					?>
				</form>
			</div>
<?php
		} catch (\Throwable $e) {
			\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::log('StarmusAdmin::render_settings_page', $e);
			echo '<div class="error"><p>' . esc_html__('Error loading settings page.', 'starmus-audio-recorder') . '</p></div>';
		}
	}

	/**
	 * Register plugin settings with WordPress Settings API.
	 *
	 * REVERTED: This method is now back to its original purpose.
	 * Registers the main option, adds settings sections and fields.
	 * Called on 'admin_init' hook.
	 *
	 * @return void
	 */
	public function register_settings(): void
	{
		try {
			register_setting(
				self::STARMUS_SETTINGS_GROUP,
				StarmusSettings::STARMUS_OPTION_KEY, // CORRECTED: Access constant from StarmusSettings class
				array(
					'sanitize_callback' => array($this, 'sanitize_settings'),
					'default'           => $this->settings->get_defaults(), // Passed for initial defaults handling
				)
			);

			$this->add_settings_sections();
			$this->add_settings_fields();
		} catch (\Throwable $e) {
			\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::log('StarmusAdmin::register_settings', $e);
		}
	}

	/**
	 * Sanitize and validate settings on save.
	 *
	 * REVERTED: This is the callback for register_setting.
	 * ADDED: Cache clearing after sanitization.
	 * Validates all input fields, applies appropriate sanitization,
	 * converts page slugs to IDs, and returns sanitized array.
	 *
	 * @param array<string, mixed> $input Raw input from settings form.
	 * @return array<string, mixed> Sanitized settings array.
	 */
	public function sanitize_settings(array $input): array
	{
		try {
			$defaults = $this->settings->get_defaults();
			if (! is_array($defaults)) {
				$defaults = array(); // Should be fixed now
			}

			$sanitized = array();

			// CPT Slug
			$cpt_slug              = trim($input['cpt_slug'] ?? '');
			$sanitized['cpt_slug'] = $this->is_valid_cpt_slug($cpt_slug) ? $cpt_slug : ($defaults['cpt_slug'] ?? 'audio-recording');

			// File size limit
			$file_size                    = absint($input['file_size_limit'] ?? 0);
			$sanitized['file_size_limit'] = ($file_size > 0 && $file_size <= 100) ? $file_size : ($defaults['file_size_limit'] ?? 10);

			// Allowed file types
			$file_types = sanitize_text_field($input['allowed_file_types'] ?? '');
			if (! empty($file_types)) {
				$types                           = array_map('trim', explode(',', $file_types));
				$types                           = array_filter($types, array($this, 'is_valid_file_extension'));
				$sanitized['allowed_file_types'] = implode(',', $types);
			} else {
				$sanitized['allowed_file_types'] = $defaults['allowed_file_types'] ?? 'mp3,wav,webm';
			}

			// Allowed languages
			$allowed_langs = sanitize_text_field($input['allowed_languages'] ?? '');
			if (! empty($allowed_langs)) {
				$langs                          = array_map('trim', explode(',', $allowed_langs));
				$langs                          = array_filter(
					$langs,
					function ($l) {
						return preg_match('/^[a-z]{2,4}$/', $l);
					}
				);
				$sanitized['allowed_languages'] = implode(',', $langs);
			} else {
				$sanitized['allowed_languages'] = '';
			}

			// Consent message
			$sanitized['consent_message'] = wp_kses_post($input['consent_message'] ?? $defaults['consent_message'] ?? '');

			// Collect IP/UA
			$sanitized['collect_ip_ua'] = ! empty($input['collect_ip_ua']) ? 1 : 0;

			// Delete on uninstall
			$sanitized['delete_on_uninstall'] = ! empty($input['delete_on_uninstall']) ? 1 : 0;

			// Logic for page IDs - Convert slug to ID
			$page_slug_fields = array(
				'edit_page_id'          => __('Edit Audio Page', 'starmus-audio-recorder'),
				'recorder_page_id'      => __('Audio Recorder Page', 'starmus-audio-recorder'),
				'my_recordings_page_id' => __('My Recordings Page', 'starmus-audio-recorder'),
			);

			foreach ($page_slug_fields as $key => $title) {
				$slug_input = sanitize_text_field($input[$key] ?? '');
				$page_id    = 0;

				if (! empty($slug_input)) {
					$page_id = $this->dal->get_page_id_by_slug($slug_input);
					if ($page_id > 0) {
					} else {
						add_settings_error(
							self::STARMUS_SETTINGS_GROUP,
							"starmus_{$key}_not_found",
							sprintf(
								/* translators: 1: page slug, 2: setting title */
								__('The page with slug "%1$s" for "%2$s" could not be found. Please ensure the page exists.', 'starmus-audio-recorder'),
								esc_html($slug_input),
								esc_html($title)
							),
							'error'
						);
					}
				}
				$sanitized[$key] = $page_id;
			}

			// IMPORTANT FIX: Clear the StarmusSettings internal cache here
			// This ensures StarmusSettings reloads fresh data from the database
			// the next time its get() or all() methods are called (e.g., on page reload).
			if ($this->settings instanceof StarmusSettings) {
				$this->settings->clear_cache();
			}

			return $sanitized; // Return the sanitized array for WordPress to save
		} catch (\Throwable $e) {
			\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::log('StarmusAdmin::sanitize_settings', $e);
			return $this->settings->get_defaults(); // Return safe defaults on error
		}
	}

	/**
	 * Add settings sections.
	 */
	private function add_settings_sections(): void
	{
		try {
			add_settings_section(
				'starmus_cpt_section',
				__('Custom Post Type Settings', 'starmus-audio-recorder'),
				null,
				self::STARMUS_MENU_SLUG
			);

			add_settings_section(
				'starmus_rules_section',
				__('File Upload & Recording Rules', 'starmus-audio-recorder'),
				null,
				self::STARMUS_MENU_SLUG
			);
			add_settings_section(
				'starmus_language_section',
				__('Language Validation', 'starmus-audio-recorder'),
				null,
				self::STARMUS_MENU_SLUG
			);

			add_settings_section(
				'starmus_privacy_section',
				__('Privacy & Form Settings', 'starmus-audio-recorder'),
				null,
				self::STARMUS_MENU_SLUG
			);

			add_settings_section(
				'starmus_page_section',
				__('Frontend Page Settings', 'starmus-audio-recorder'),
				null,
				self::STARMUS_MENU_SLUG
			);
		} catch (\Throwable $e) {
			\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::log('StarmusAdmin::add_settings_sections', $e);
		}
	}

	/**
	 * Add settings fields.
	 */
	private function add_settings_fields(): void
	{
		try {
			// Define all fields with their properties
			$fields = array(
				'cpt_slug'              => array(
					'title'       => __('Post Type Slug', 'starmus-audio-recorder'),
					'section'     => 'starmus_cpt_section',
					'description' => __('Use lowercase letters, numbers, and hyphens only.', 'starmus-audio-recorder'),
				),
				'file_size_limit'       => array(
					'title'       => __('Max File Size (MB)', 'starmus-audio-recorder'),
					'section'     => 'starmus_rules_section',
					'description' => __('Maximum allowed file size for uploads.', 'starmus-audio-recorder'),
				),
				'allowed_file_types'    => array(
					'title'       => __('Allowed File Extensions', 'starmus-audio-recorder'),
					'section'     => 'starmus_rules_section',
					'description' => __('Comma-separated list of allowed extensions (e.g., mp3, wav, webm).', 'starmus-audio-recorder'),
				),
				'allowed_languages'     => array(
					'title'       => __('Allowed Languages (ISO codes)', 'starmus-audio-recorder'),
					'section'     => 'starmus_language_section',
					'description' => __('Comma-separated list of allowed language ISO codes (e.g., en, fr, de). Leave blank to allow any language.', 'starmus-audio-recorder'),
				),
				'consent_message'       => array(
					'title'       => __('Consent Checkbox Message', 'starmus-audio-recorder'),
					'section'     => 'starmus_privacy_section',
					'description' => __('Text displayed next to consent checkbox for data collection.', 'starmus-audio-recorder'),
				),
				'collect_ip_ua'         => array(
					'title'       => __('Store IP & User Agent', 'starmus-audio-recorder'),
					'section'     => 'starmus_privacy_section',
					'label'       => __('Save submitter IP and user agent for all submissions.', 'starmus-audio-recorder'),
					'description' => __('Enabling this may have privacy implications. Ensure compliance with data protection laws.', 'starmus-audio-recorder'),
				),
				'delete_on_uninstall'   => array(
					'title'       => __('Delete All Data on Uninstall', 'starmus-audio-recorder'),
					'section'     => 'starmus_privacy_section',
					'label'       => __('Permanently delete all recordings, submissions, and plugin data when the plugin is uninstalled.', 'starmus-audio-recorder'),
					'description' => __('WARNING: This action cannot be undone. If unchecked, data will be preserved even after plugin deletion.', 'starmus-audio-recorder'),
				),
				'edit_page_id'          => array(
					'title'       => __('Edit Audio Page Slug', 'starmus-audio-recorder'),
					'section'     => 'starmus_page_section',
					'description' => __('Enter the slug of the page where users can edit their audio recordings (e.g., "my-audio-editor"). This page must exist and contain the appropriate shortcode.', 'starmus-audio-recorder'),
				),
				'recorder_page_id'      => array(
					'title'       => __('Audio Recorder Page Slug', 'starmus-audio-recorder'),
					'section'     => 'starmus_page_section',
					'description' => __('Enter the slug of the page containing the [starmus-audio-recorder] shortcode (e.g., "record-audio").', 'starmus-audio-recorder'),
				),
				'my_recordings_page_id' => array(
					'title'       => __('My Recordings Page Slug', 'starmus-audio-recorder'),
					'section'     => 'starmus_page_section',
					'description' => __('Enter the slug of the page containing the [starmus-my-recordings] shortcode (e.g., "my-submissions").', 'starmus-audio-recorder'),
				),
			);

			foreach ($fields as $id => $field) {
				add_settings_field(
					$id,
					$field['title'],
					array($this, 'render_field'),
					self::STARMUS_MENU_SLUG,
					$field['section'],
					array_merge(
						array(
							'id'   => $id,
							'type' => $this->field_types[$id] ?? 'text',
						),
						$field
					)
				);
			}
		} catch (\Throwable $e) {
			\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::log('StarmusAdmin::add_settings_fields', $e);
		}
	}

	/**
	 * Validate file extension.
	 */
	private function is_valid_file_extension(string $ext): bool
	{
		$allowed = StarmusSettings::get_allowed_mimes();
		// Correctly get the keys (extensions) from the allowed MIME types map.
		$allowed_extensions = array_keys($allowed);
		return in_array(strtolower(trim($ext)), $allowed_extensions, true);
	}


	/**
	 * Render form field with validation.
	 * REVERTED: Field name is back to using STARMUS_OPTION_KEY for Settings API.
	 */
	public function render_field(array $args): void
	{
		try {
			if (empty($args['id'])) {
				return;
			}

			$id    = esc_attr($args['id']);
			$type  = $args['type'] ?? 'text';
			$value = $this->settings->get($id); // This value is the stored ID (from wp_options)
			// CORRECTED: Field name uses the option key as parent for Settings API
			$name = StarmusSettings::STARMUS_OPTION_KEY . "[$id]";

			switch ($type) {
				case 'textarea':
					printf(
						'<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>',
						esc_attr($id),
						esc_attr($name),
						esc_textarea($value)
					);
					break;

				case 'checkbox':
					printf(
						'<label><input type="checkbox" id="%s" name="%s" value="1" %s /> %s</label>',
						esc_attr($id),
						esc_attr($name),
						checked(1, $value, false),
						esc_html($args['label'] ?? '')
					);
					break;

				case 'number':
					printf(
						'<input type="number" id="%s" name="%s" value="%s" class="small-text" min="1" max="100" />',
						esc_attr($id),
						esc_attr($name),
						esc_attr($value)
					);
					break;

				case 'pages_dropdown':
					wp_dropdown_pages(
						array(
							'name'              => esc_attr($name),
							'id'                => esc_attr($id),
							'selected'          => esc_attr($value),
							'show_option_none'  => esc_html__('— Select a Page —', 'starmus-audio-recorder'),
							'option_none_value' => '0',
						)
					);
					break;

				case 'slug_input':
					$current_slug = '';
					if ((int) $value > 0) {
						$current_slug = $this->dal->get_page_slug_by_id((int) $value);
					}
					printf(
						'<input type="text" id="%s" name="%s" value="%s" class="regular-text" placeholder="e.g., starmus-audio-editor" />',
						esc_attr($id),
						esc_attr($name),
						esc_attr($current_slug)
					);
					break;

				case 'text':
				default:
					printf(
						'<input type="text" id="%s" name="%s" value="%s" class="regular-text" />',
						esc_attr($id),
						esc_attr($name),
						esc_attr($value)
					);
					break;
			}

			if (! empty($args['description'])) {
				printf(
					'<p class="description">%s</p>',
					wp_kses($args['description'], array('strong' => array()))
				);
			}
		} catch (\Throwable $e) {
			\Starisian\Sparxstar\Starmus\helpers\StarmusLogger::log('StarmusAdmin::render_field', $e);
		}
	}
}
