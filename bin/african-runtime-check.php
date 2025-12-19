<?php
/**
 * West African Runtime Error Detection Script
 * Scans for patterns that cause issues in African deployment conditions
 */

declare(strict_types=1);

$issues = [];
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator('src/', RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') continue;
    
    $content = file_get_contents($file->getPathname());
    $lines = explode("\n", $content);
    
    foreach ($lines as $num => $line) {
        $lineNum = $num + 1;
        
        // Check for silenced errors (hide runtime issues)
        if (preg_match('/@\s*\w+\s*\(/', $line)) {
            $issues[] = "{$file->getPathname()}:{$lineNum} - Silenced error hides African runtime issues";
        }
        
        // Check for file_get_contents (network issues)
        if (strpos($line, 'file_get_contents') !== false && strpos($line, 'http') !== false) {
            $issues[] = "{$file->getPathname()}:{$lineNum} - Use wp_remote_get for African network conditions";
        }
        
        // Check for missing error logging
        if (preg_match('/catch\s*\(\s*\w+\s+\$\w+\s*\)\s*\{[^}]*\}/', $line) && 
            strpos($line, 'error_log') === false) {
            $issues[] = "{$file->getPathname()}:{$lineNum} - Missing error logging in catch block";
        }
        
        // Check for memory-intensive operations
        if (strpos($line, 'array_merge') !== false && strpos($line, 'foreach') !== false) {
            $issues[] = "{$file->getPathname()}:{$lineNum} - Memory-intensive array operations";
        }
    }
}

if (empty($issues)) {
    echo "✅ No African runtime issues detected\n";
    exit(0);
} else {
    echo "❌ African Runtime Issues Found:\n";
    foreach ($issues as $issue) {
        echo "  $issue\n";
    }
    exit(1);
}