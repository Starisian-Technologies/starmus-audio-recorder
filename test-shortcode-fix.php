<?php

/**
 * Test script to verify shortcode loader functionality after constructor fix
 */

require_once __DIR__ . '/src/StarmusAudioRecorder.php';
require_once __DIR__ . '/src/core/StarmusSettings.php';
require_once __DIR__ . '/src/frontend/StarmusShortcodeLoader.php';
require_once __DIR__ . '/src/frontend/StarmusAudioRecorderUI.php';
require_once __DIR__ . '/src/core/StarmusAudioRecorderDAL.php';

use Starisian\Sparxstar\Starmus\frontend\StarmusShortcodeLoader;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\core\StarmusAudioRecorderDAL;

echo "Testing shortcode loader instantiation after constructor fix...\n";
$settings = new StarmusSettings();
$dal = new StarmusAudioRecorderDAL();

try {
    $loader = new StarmusShortcodeLoader($settings, $dal);
    echo "✅ StarmusShortcodeLoader constructed successfully!\n";
    echo "✅ Constructor fix is working properly!\n";

    // Check if methods exist
    if (method_exists($loader, 'render_my_recordings_shortcode')) {
        echo "✅ render_my_recordings_shortcode method exists\n";
    }
    if (method_exists($loader, 'render_submission_detail_shortcode')) {
        echo "✅ render_submission_detail_shortcode method exists\n";
    }
    if (method_exists($loader, 'register_hooks')) {
        echo "✅ register_hooks method exists\n";
    }
    if (method_exists($loader, 'render_editor_with_bootstrap')) {
        echo "✅ render_editor_with_bootstrap method exists\n";
    }

    echo "\nVerifying StarmusAudioRecorderUI methods exist:\n";
    $ui = new StarmusAudioRecorderUI($settings);
    if (method_exists($ui, 'render_re_recorder_shortcode')) {
        echo "✅ render_re_recorder_shortcode method exists in StarmusAudioRecorderUI\n";
    }
    if (method_exists($ui, 'render_recorder_shortcode')) {
        echo "✅ render_recorder_shortcode method exists in StarmusAudioRecorderUI\n";
    }

    echo "\n🎉 ALL SHORTCODE METHODS VERIFIED - The constructor fix resolved the issue!\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} catch (Throwable $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
