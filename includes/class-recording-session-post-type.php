<?php
namespace Starmus\includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the "Recording Session" custom post type.
 */
class RecordingSessionPostType {
    public function __construct() {
        add_action( 'init', [ $this, 'register' ] );
    }

    /**
     * Register the custom post type.
     */
    public function register(): void {
        $labels = [
            'name'                     => esc_html__( 'Recording Sessions', 'starmus-audio-recorder' ),
            'singular_name'            => esc_html__( 'Recording Session', 'starmus-audio-recorder' ),
            'menu_name'                => esc_html__( 'Recording Sessions', 'starmus-audio-recorder' ),
            'all_items'                => esc_html__( 'All Recording Sessions', 'starmus-audio-recorder' ),
            'edit_item'                => esc_html__( 'Edit Recording Session', 'starmus-audio-recorder' ),
            'view_item'                => esc_html__( 'View Recording Session', 'starmus-audio-recorder' ),
            'view_items'               => esc_html__( 'View Recording Sessions', 'starmus-audio-recorder' ),
            'add_new_item'             => esc_html__( 'Add New Recording Session', 'starmus-audio-recorder' ),
            'add_new'                  => esc_html__( 'Add New Recording Session', 'starmus-audio-recorder' ),
            'new_item'                 => esc_html__( 'New Recording Session', 'starmus-audio-recorder' ),
            'parent_item_colon'        => esc_html__( 'Parent Recording Session:', 'starmus-audio-recorder' ),
            'search_items'             => esc_html__( 'Search Recording Sessions', 'starmus-audio-recorder' ),
            'not_found'                => esc_html__( 'No recording sessions found', 'starmus-audio-recorder' ),
            'not_found_in_trash'       => esc_html__( 'No recording sessions found in Trash', 'starmus-audio-recorder' ),
            'archives'                 => esc_html__( 'Recording Session Archives', 'starmus-audio-recorder' ),
            'attributes'               => esc_html__( 'Recording Session Attributes', 'starmus-audio-recorder' ),
            'insert_into_item'         => esc_html__( 'Insert into recording session', 'starmus-audio-recorder' ),
            'uploaded_to_this_item'    => esc_html__( 'Uploaded to this recording session', 'starmus-audio-recorder' ),
            'filter_items_list'        => esc_html__( 'Filter recording sessions list', 'starmus-audio-recorder' ),
            'items_list_navigation'    => esc_html__( 'Recording Sessions list navigation', 'starmus-audio-recorder' ),
            'items_list'               => esc_html__( 'Recording Sessions list', 'starmus-audio-recorder' ),
            'item_published'           => esc_html__( 'Recording Session published.', 'starmus-audio-recorder' ),
            'item_published_privately' => esc_html__( 'Recording Session published privately.', 'starmus-audio-recorder' ),
            'item_reverted_to_draft'   => esc_html__( 'Recording Session reverted to draft.', 'starmus-audio-recorder' ),
            'item_scheduled'           => esc_html__( 'Recording Session scheduled.', 'starmus-audio-recorder' ),
            'item_updated'             => esc_html__( 'Recording Session updated.', 'starmus-audio-recorder' ),
            'item_link'                => esc_html__( 'Recording Session Link', 'starmus-audio-recorder' ),
            'item_link_description'    => esc_html__( 'A link to a recording session.', 'starmus-audio-recorder' ),
        ];

        $args = [
            'labels'             => $labels,
            'description'        => esc_html__( 'Represents a discrete field recording event with metadata like date, location, participants, and equipment used, linking multiple oral fragments and histories.', 'starmus-audio-recorder' ),
            'public'             => true,
            'hierarchical'       => false,
            'exclude_from_search'=> false,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_admin_bar'  => true,
            'show_in_nav_menus'  => true,
            'show_in_rest'       => true,
            'menu_position'      => 4,
            'menu_icon'          => 'dashicons-microphone',
            'supports'           => [ 'title', 'author', 'comments', 'editor', 'excerpt', 'revisions', 'thumbnail', 'custom-fields' ],
            'taxonomies'         => [ 'category', 'post_tag', 'project', 'language', 'cultural-heritage', 'ambassador', 'dialect', 'recording-type' ],
            'has_archive'        => true,
            'rewrite'            => [ 'slug' => 'sessions', 'with_front' => true, 'feeds' => true, 'pages' => true ],
            'capability_type'    => [ 'session', 'sessions' ],
            'map_meta_cap'       => true,
        ];

        register_post_type( 'recording-session', $args );
    }
}
