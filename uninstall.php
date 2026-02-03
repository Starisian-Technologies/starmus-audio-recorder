<?php

namespace Starisian\Sparxstar\Starmus;

/**
 * Fired when the plugin is uninstalled.
 *
 * @version 0.9.2
 * @since 0.3.1
 */

// If uninstall not called from WordPress, then exit.
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check if the user has permissions to uninstall.
if (! current_user_can('activate_plugins')) {
    return;
}

// --- Safety Check: Only delete data if explicitly allowed ---
// Check both the constant and the admin setting
$sparxstar_starmus_settings            = get_option('starmus_settings', []);
$sparxstar_starmus_delete_on_uninstall = defined('STARMUS_DELETE_ON_UNINSTALL') ? STARMUS_DELETE_ON_UNINSTALL : false;
$sparxstar_starmus_admin_setting       = isset($sparxstar_starmus_settings['delete_on_uninstall']) ? (bool) $sparxstar_starmus_settings['delete_on_uninstall'] : false;

// Only proceed with data deletion if either the constant OR admin setting allows it
if ($sparxstar_starmus_delete_on_uninstall === false && $sparxstar_starmus_admin_setting === false) {
    // User wants to keep data - just remove the settings option
    delete_option('starmus_settings');
    return;
}

// --- Cleanup Logic ---
// It's safer to hardcode option names and post types here than to load plugin files.

// 1. Delete plugin options.
delete_option('starmus_settings');

// 2. Delete all custom posts from ACF-registered post types.
$sparxstar_starmus_post_types = ['starmus-audio-recording'];
foreach ($sparxstar_starmus_post_types as $cpt_slug) {
    $posts = get_posts(
        [
            'post_type'   => $cpt_slug,
            'numberposts' => -1,
            'post_status' => 'any',
            'fields'      => 'ids',
        ]
    );

    if (! empty($posts)) {
        foreach ($posts as $post_id) {
            wp_delete_post($post_id, true); // Force delete.
        }
    }
}

// 3. Remove custom capabilities.
$sparxstar_starmus_roles_to_clean = ['editor', 'administrator', 'community_contributor'];
foreach ($sparxstar_starmus_roles_to_clean as $role_name) {
    $role = get_role($role_name);
    if ($role) {
        $role->remove_cap('starmus_edit_audio');
        $role->remove_cap('starmus_record_audio');
    }
}

// 4. Delete custom taxonomies' terms (ACF-registered taxonomies).
$sparxstar_starmus_taxonomies = ['language', 'recording-type', 'dialect', 'ambassador'];
foreach ($sparxstar_starmus_taxonomies as $taxonomy) {
    $terms = get_terms(
        [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]
    );
    if (! empty($terms) && ! is_wp_error($terms)) {
        foreach ($terms as $term) {
            wp_delete_term($term->term_id, $taxonomy);
        }
    }
}

// 5. Remove ACF field groups and post type definitions from database
// (they're stored in wp_posts with post_type 'acf-field-group' and 'acf-post-type')
$sparxstar_starmus_acf_items = get_posts(
    [
        'post_type'   => ['acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy'],
        'numberposts' => -1,
        'post_status' => 'any',
        'meta_query'  => [
            [
                'key'     => '_acf_key',
                'compare' => 'LIKE',
                'value'   => 'group_68',  // Our ACF groups start with this
            ],
        ],
    ]
);
if (! empty($sparxstar_starmus_acf_items)) {
    foreach ($sparxstar_starmus_acf_items as $item) {
        wp_delete_post($item->ID, true);
    }
}

// Note: Do not flush rewrite rules here. WordPress handles that after uninstallation.
