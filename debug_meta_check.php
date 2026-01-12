<?php
require_once 'starmus-audio-recorder.php';

// Get the last 5 audio recordings
$args = [
    'post_type' => 'audio-recording',
    'posts_per_page' => 5,
    'orderby' => 'date',
    'order' => 'DESC',
];

$query = new WP_Query($args);

echo "Found " . $query->found_posts . " recordings.\n";

if ($query->have_posts()) {
    while ($query->have_posts()) {
        $query->the_post();
        $id = get_the_ID();
        echo "--------------------------------------------------\n";
        echo "ID: " . $id . " | Title: " . get_the_title() . "\n";

        $meta = get_post_meta($id);
        echo "Meta Keys Found:\n";
        foreach ($meta as $key => $value) {
            // Filter relevant keys to keep output readable
            if (strpos($key, 'starmus') !== false || strpos($key, 'wave') !== false || strpos($key, 'trans') !== false || strpos($key, 'finger') !== false) {
                $val_preview = is_array($value) ? $value[0] : $value;
                $len = strlen($val_preview);
                $preview = substr($val_preview, 0, 50) . ($len > 50 ? '...' : '');
                echo "  [$key] ($len bytes): $preview\n";
            }
        }
    }
} else {
    echo "No recordings found.\n";
}
