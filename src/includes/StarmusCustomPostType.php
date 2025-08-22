<?php
namespace Starisian\src\includes;

// Linting stubs for WordPress functions (only used if not running in WP context)
if ( !function_exists( 'add_action' ) ) { function add_action() {} }
if ( !function_exists( 'register_post_type' ) ) { function register_post_type() {} }
if ( !function_exists( 'unregister_post_type' ) ) { function unregister_post_type() {} }
if ( !function_exists( 'post_type_exists' ) ) { function post_type_exists() { return false; } }
if ( !function_exists( '__' ) ) { function __($text) { return $text; } }
if ( !function_exists( '_x' ) ) { function _x($text) { return $text; } }

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StarmusCustomPostType
 *
 * Manages the 'starmus_submission' custom post type.
 */
class StarmusCustomPostType {
	/**
	 * Gets the custom post type slug from admin settings, or falls back to default.
	 */
	public static function get_post_type_slug(): string {
		// Try to get from admin settings (singleton pattern)
		if (class_exists('Starisian\\src\\admin\\StarmusAdminSettings')) {
			$settingsClass = '\\Starisian\\src\\admin\\StarmusAdminSettings';
			$slug = $settingsClass::get_option('cpt_slug', 'audio_submission');
			return $slug ?: 'audio_submission';
		}
		return 'audio_submission';
	}

	public function __construct() {
	\add_action( 'init', [ $this, 'register_post_type' ] );
	}

	public function register_post_type(): void {
		$slug = self::get_post_type_slug();
		$labels = [
			'name'                  => \_x( 'Audio Submissions', 'Post Type General Name', 'starmus' ),
			'singular_name'         => \_x( 'Audio Submission', 'Post Type Singular Name', 'starmus' ),
			'menu_name'             => \__( 'Audio Submissions', 'starmus' ),
			'archives'              => \__( 'Submission Archives', 'starmus' ),
			'attributes'            => \__( 'Submission Attributes', 'starmus' ),
			'parent_item_colon'     => \__( 'Parent Submission:', 'starmus' ),
			'all_items'             => \__( 'All Submissions', 'starmus' ),
			'add_new_item'          => \__( 'Add New Submission', 'starmus' ),
			'add_new'               => \__( 'Add New', 'starmus' ),
			'new_item'              => \__( 'New Submission', 'starmus' ),
			'edit_item'             => \__( 'Edit Submission', 'starmus' ),
			'update_item'           => \__( 'Update Submission', 'starmus' ),
			'view_item'             => \__( 'View Submission', 'starmus' ),
			'view_items'            => \__( 'View Submissions', 'starmus' ),
			'search_items'          => \__( 'Search Submission', 'starmus' ),
			'not_found'             => \__( 'Not found', 'starmus' ),
			'not_found_in_trash'    => \__( 'Not found in Trash', 'starmus' ),
			'insert_into_item'      => \__( 'Insert into submission', 'starmus' ),
			'uploaded_to_this_item' => \__( 'Uploaded to this submission', 'starmus' ),
			'items_list'            => \__( 'Submissions list', 'starmus' ),
			'items_list_navigation' => \__( 'Submissions list navigation', 'starmus' ),
			'filter_items_list'     => \__( 'Filter submissions list', 'starmus' ),
		];
		$args   = [
			'label'               => __( 'Audio Submission', 'starmus' ),
			'description'         => __( 'Stores audio recordings submitted by users.', 'starmus' ),
			'labels'              => $labels,
			'supports'            => [ 'title', 'editor', 'author', 'custom-fields' ],
			'hierarchical'        => false,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_position'       => 25,
			'menu_icon'           => 'dashicons-format-audio',
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => true,
			'can_export'          => true,
			'has_archive'         => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'capability_type'     => 'post',
			'show_in_rest'        => true,
		];
		try{
			\register_post_type( $slug, $args );
		} catch ( \Exception $e ) {
			if ( \defined( 'WP_DEBUG' ) && \constant( 'WP_DEBUG' ) ) {
				error_log( 'Error registering post type: ' . $e->getMessage() );
			}
		}
	}

	public function unregister_custom_post_type(): void {
		$slug = self::get_post_type_slug();
		if ( \post_type_exists( $slug ) ) {
			try {
				\unregister_post_type( $slug );
			} catch ( \Exception $e ) {
				if ( \defined( 'WP_DEBUG' ) && \constant( 'WP_DEBUG' ) ) {
					error_log( 'Error unregistering post type: ' . $e->getMessage() );
				}
			}
		}
	}
}