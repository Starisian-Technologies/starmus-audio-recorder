#!/bin/bash
# SPDX-FileCopyrightText: 2023-2025 Starisian Technologies
# SPDX-License-Identifier: MIT

set -e

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📚 STARMUS DOCUMENTATION GENERATOR"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

echo ""
echo "📋 1. Generating PHP documentation..."
php bin/generate-docs.php

echo ""
echo "📋 2. Generating JavaScript documentation..."
node bin/generate-js-docs.js

echo ""
echo "📋 3. Generating API index..."

# Create unified index
cat > docs/API-INDEX.md << 'EOF'
# Starmus Audio Recorder - API Documentation

## PHP Classes

See detailed documentation in [`/docs/php-md/`](./php-md/)

## JavaScript Modules

See detailed documentation in [`/docs/js/`](./js/)

### Core Modules

- **[starmus-integrator.js](./js/starmus-integrator.md)** - Main orchestrator and entry point
- **[starmus-core.js](./js/starmus-core.md)** - Submission engine
- **[starmus-recorder.js](./js/starmus-recorder.md)** - Audio recording logic
- **[starmus-ui.js](./js/starmus-ui.md)** - UI controller
- **[starmus-hooks.js](./js/starmus-hooks.md)** - Event system
- **[starmus-state-store.js](./js/starmus-state-store.md)** - State management
- **[starmus-offline.js](./js/starmus-offline.md)** - Offline queue
- **[starmus-tus.js](./js/starmus-tus.md)** - TUS resumable uploads
- **[starmus-audio-editor.js](./js/starmus-audio-editor.md)** - Waveform editor

---

_Generated automatically. Run `npm run docs` to update._
EOF

echo "✓ Created docs/API-INDEX.md"

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ ALL DOCUMENTATION GENERATED"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "View documentation:"
echo "  - PHP:  docs/php-md/"
echo "  - JS:   docs/js/"
echo "  - Index: docs/API-INDEX.md"
