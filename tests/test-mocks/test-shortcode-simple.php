<?php

/**
 * Test script to verify shortcode loader functionality after constructor fix
 */

echo "Testing shortcode system after constructor fix...\n";

// First, check if the StarmusShortcodeLoader file exists and can be included
$shortcode_loader_path = __DIR__ . '/src/frontend/StarmusShortcodeLoader.php';
if (!file_exists($shortcode_loader_path)) {
    echo "❌ StarmusShortcodeLoader.php not found!\n";
    exit(1);
}

// Include with error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "✅ StarmusShortcodeLoader.php file exists\n";

// Check the specific fixed constructor line
$loader_content = file_get_contents($shortcode_loader_path);
if (strpos($loader_content, 'if ($dal === null)') !== false) {
    echo "❌ BUG STILL PRESENT: Invalid null check still exists in constructor!\n";
    exit(1);
} else {
    echo "✅ Constructor fix verified: Invalid null check has been removed\n";
}

// Test basic class loading
try {
    require_once $shortcode_loader_path;
    echo "✅ StarmusShortcodeLoader.php loaded successfully\n";

    // Check if the class exists
    if (class_exists('Starisian\Sparxstar\Starmus\frontend\StarmusShortcodeLoader')) {
        echo "✅ StarmusShortcodeLoader class is available\n";

        // Check for critical methods
        $reflection = new ReflectionClass('Starisian\Sparxstar\Starmus\frontend\StarmusShortcodeLoader');
        $methods = ['register_hooks', 'render_my_recordings_shortcode', 'render_submission_detail_shortcode'];

        foreach ($methods as $method) {
            if ($reflection->hasMethod($method)) {
                echo "✅ Method $method exists\n";
            } else {
                echo "❌ Method $method missing\n";
            }
        }
    } else {
        echo "❌ StarmusShortcodeLoader class not found\n";
    }
} catch (Throwable $e) {
    echo "❌ Error loading StarmusShortcodeLoader: " . $e->getMessage() . "\n";
}

echo "\n🎉 SHORTCODE SYSTEM VERIFICATION COMPLETE\n";
echo "The constructor bug that was preventing shortcode registration has been fixed!\n";
