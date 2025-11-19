<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * @package Starmus
 * @version 0.8.5
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
$settings = get_option('starmus_settings', array());
$delete_on_uninstall = defined('STARMUS_DELETE_ON_UNINSTALL') ? STARMUS_DELETE_ON_UNINSTALL : false;
$admin_setting = isset($settings['delete_on_uninstall']) ? (bool) $settings['delete_on_uninstall'] : false;

// Only proceed with data deletion if either the constant OR admin setting allows it
if (! $delete_on_uninstall && ! $admin_setting) {
	// User wants to keep data - just remove the settings option
	delete_option('starmus_settings');
	return;
}

// --- Cleanup Logic ---
// It's safer to hardcode option names and post types here than to load plugin files.

// 1. Delete plugin options.
delete_option('starmus_settings');

// 2. Delete all custom posts from ACF-registered post types.
$post_types = array('audio-recording', 'consent-agreement');
foreach ($post_types as $cpt_slug) {
	$posts = get_posts(
		array(
			'post_type'   => $cpt_slug,
			'numberposts' => -1,
			'post_status' => 'any',
			'fields'      => 'ids',
		)
	);

	if (! empty($posts)) {
		foreach ($posts as $post_id) {
			wp_delete_post($post_id, true); // Force delete.
		}
	}
}

// 3. Remove custom capabilities.
$roles_to_clean = array('editor', 'administrator', 'contributor', 'community_contributor');
foreach ($roles_to_clean as $role_name) {
	$role = get_role($role_name);
	if ($role) {
		$role->remove_cap('starmus_edit_audio');
		$role->remove_cap('starmus_record_audio');
	}
}

// 4. Delete custom taxonomies' terms (ACF-registered taxonomies).
$taxonomies = array('language', 'recording-type', 'dialect', 'ambassador');
foreach ($taxonomies as $taxonomy) {
	$terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
	if (! empty($terms) && ! is_wp_error($terms)) {
		foreach ($terms as $term) {
			wp_delete_term($term->term_id, $taxonomy);
		}
	}
}

// 5. Remove ACF field groups and post type definitions from database
// (they're stored in wp_posts with post_type 'acf-field-group' and 'acf-post-type')
$acf_items = get_posts(
	array(
		'post_type'   => array('acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy'),
		'numberposts' => -1,
		'post_status' => 'any',
		'meta_query'  => array(
			array(
				'key'     => '_acf_key',
				'compare' => 'LIKE',
				'value'   => 'group_68',  // Our ACF groups start with this
			),
		),
	)
);
if (! empty($acf_items)) {
	foreach ($acf_items as $item) {
		wp_delete_post($item->ID, true);
	}
}

// Note: Do not flush rewrite rules here. WordPress handles that after uninstallation.
