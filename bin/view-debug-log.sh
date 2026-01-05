#!/bin/bash
# View WordPress debug log
# Usage: ./bin/view-debug-log.sh [lines]

LINES=${1:-50}
LOG_FILE="wp-content/debug.log"

if [ ! -f "$LOG_FILE" ]; then
    echo "Debug log not found at: $LOG_FILE"
    echo "Make sure WP_DEBUG_LOG is enabled in wp-config.php"
    exit 1
fi

echo "=== Last $LINES lines of debug.log ==="
echo ""
tail -n "$LINES" "$LOG_FILE"
