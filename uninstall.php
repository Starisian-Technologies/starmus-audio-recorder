<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Starmus
 * @version 0.8.4
 * @since 0.3.1
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Check if the user has permissions to uninstall.
if ( ! current_user_can( 'activate_plugins' ) ) {
	return;
}

// --- Cleanup Logic ---
// It's safer to hardcode option names and post types here than to load plugin files.

// 1. Delete plugin options.
delete_option( 'starmus_settings' );

// 2. Delete all custom posts.
$cpt_slug = 'audio-recording'; // Use the default or known CPT slug.
$posts    = get_posts(
	array(
		'post_type'   => $cpt_slug,
		'numberposts' => -1,
		'post_status' => 'any',
		'fields'      => 'ids',
	)
);

if ( ! empty( $posts ) ) {
	foreach ( $posts as $post_id ) {
		wp_delete_post( $post_id, true ); // Force delete.
	}
}

// 3. Remove custom capabilities.
$roles_to_clean = array( 'editor', 'administrator', 'contributor', 'community_contributor' );
foreach ( $roles_to_clean as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		$role->remove_cap( 'starmus_edit_audio' );
		$role->remove_cap( 'starmus_record_audio' );
	}
}

// Note: Do not flush rewrite rules here. WordPress handles that after uninstallation.
