import re

content = """<?php

/**
 * Starmus Admin Handler - Refactored for Security & Performance
 *
 * @package Starisian\Sparxstar\Starmus\admin
 *
 * @version 0.9.2
 *
 * @since 0.3.1
 */
namespace Starisian\Sparxstar\Starmus\admin;

if (! \defined('ABSPATH')) {
    return;
}

use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusAudioDAL;
"""

use_statements = [m.start() for m in re.finditer(r'^\s*use\s+[\w\\]+', content, re.MULTILINE)]
abspath_check = re.search(r'defined\(\s*[\'"]ABSPATH[\'"]\s*\)', content)

print(f"Use statements found: {len(use_statements)}")
if use_statements:
    print(f"First use at: {use_statements[0]}")

if abspath_check:
    print(f"ABSPATH check found at: {abspath_check.start()}")
    print(f"Match: {abspath_check.group(0)}")

if use_statements and abspath_check:
    if abspath_check.start() < use_statements[0]:
        print("DETECTED: ABSPATH check comes BEFORE use statements")
    else:
        print("OK: ABSPATH matches comes after or equal")
else:
    print("Not detected both patterns")
