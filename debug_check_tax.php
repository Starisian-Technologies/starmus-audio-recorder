<?php
// Load WordPress
require_once __DIR__ . '/wp-load.php'; // Assuming generic wp-load structure if available, but usually in a container I should use wp-cli or just run this via `wp eval` if possible.

// Wait, I can't easily run a standalone PHP script that bootstraps WP unless I know where wp-load.php is.
// Since this is a plugin workspace, I might not have a full WP install.
// But the workspace info shows `wp-admin`, `wp-content`, etc. So it IS a WP install.

// Let's try to find wp-load.php position.
if (file_exists(__DIR__ . '/wp-load.php')) {
	require_once __DIR__ . '/wp-load.php';
} elseif (file_exists(__DIR__ . '/../wp-load.php')) {
	require_once __DIR__ . '/../wp-load.php';
} elseif (file_exists('/var/www/html/wp-load.php')) {
	require_once '/var/www/html/wp-load.php';
} else {
	// Attempt relative path assuming workspace root is plugin root
	// Usually plugins are in wp-content/plugins/plugin-name
	// So wp-load.php would be ../../../wp-load.php
	if (file_exists(__DIR__ . '/../../../wp-load.php')) {
		require_once __DIR__ . '/../../../wp-load.php';
	}
}

global $wp_taxonomies;
$output = [];
foreach ($wp_taxonomies as $tax_name => $tax_obj) {
	if (strpos($tax_name, 'starmus') !== false || strpos($tax_name, 'recording') !== false) {
		$output[$tax_name] = $tax_obj->object_type;
	}
}

echo json_encode($output, JSON_PRETTY_PRINT);
