#!/bin/bash
# SPDX-FileCopyrightText: 2023-2025 Starisian Technologies
# SPDX-License-Identifier: MIT
#
# Unified Documentation Generator
# Generates PHP + JS documentation with unified index

set -e

DOCS_DIR="docs"
PHP_MD_DIR="$DOCS_DIR/php-md"
PHP_HTML_DIR="$DOCS_DIR/php"
JS_DIR="$DOCS_DIR/js"

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📚 STARMUS UNIFIED DOCUMENTATION GENERATOR"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# 1. Generate PHP Markdown Docs
echo "📋 1/4 Generating PHP Markdown documentation..."
php bin/generate-docs.php || echo "⚠️  PHP markdown generation had issues"

# 2. Generate PHP HTML Docs (if phpDocumentor available)
echo ""
echo "📋 2/4 Generating PHP HTML documentation..."
if [ -f "tools/phpDocumentor.phar" ]; then
    mkdir -p "$PHP_HTML_DIR"
    php tools/phpDocumentor.phar -d src -t "$PHP_HTML_DIR" --template=clean || echo "⚠️  phpDocumentor had issues"
else
    echo "ℹ️  phpDocumentor not found (optional - skipping HTML docs)"
fi

# 3. Generate JavaScript Docs
echo ""
echo "📋 3/4 Generating JavaScript documentation..."
node bin/generate-js-docs.js || echo "⚠️  JS doc generation had issues"

# 4. Create Unified Index
echo ""
echo "📋 4/4 Creating unified documentation index..."

cat > "$DOCS_DIR/index.html" << 'EOF'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Starmus Audio Recorder - Documentation Hub</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
            background: #f5f5f5;
        }
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        h1 { font-size: 2.5rem; margin-bottom: 0.5rem; }
        .subtitle { opacity: 0.9; font-size: 1.1rem; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }
        .card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .card h2 {
            color: #667eea;
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }
        .card p { color: #666; margin-bottom: 1rem; }
        .card a {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 0.5rem 1.5rem;
            text-decoration: none;
            border-radius: 4px;
            transition: background 0.2s;
        }
        .card a:hover { background: #764ba2; }
        .resources {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-top: 2rem;
        }
        .resources h2 { color: #667eea; margin-bottom: 1rem; }
        .resources ul { list-style: none; }
        .resources li {
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
        }
        .resources li:last-child { border-bottom: none; }
        .resources a {
            color: #667eea;
            text-decoration: none;
        }
        .resources a:hover { text-decoration: underline; }
        footer {
            text-align: center;
            margin-top: 3rem;
            color: #666;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <header>
        <h1>📚 Starmus Audio Recorder</h1>
        <p class="subtitle">Complete API Documentation & Developer Resources</p>
    </header>

    <div class="grid">
        <div class="card">
            <h2>🔷 PHP API (Markdown)</h2>
            <p>Auto-generated documentation from PHP class docblocks. Clean, readable Markdown format.</p>
            <a href="./php-md/StarmusAudioRecorder.html">Browse PHP Docs →</a>
        </div>

        <div class="card">
            <h2>🟦 PHP API (HTML)</h2>
            <p>Full phpDocumentor HTML documentation with class diagrams and inheritance trees.</p>
            <a href="./php/index.html">Browse HTML Docs →</a>
        </div>

        <div class="card">
            <h2>🟨 JavaScript API</h2>
            <p>JSDoc-generated documentation for all ES modules, functions, and event handlers.</p>
            <a href="./js/starmus-integrator.html">Browse JS Docs →</a>
        </div>

        <div class="card">
            <h2>📖 API Index</h2>
            <p>Comprehensive listing of all classes, modules, and functions across the codebase.</p>
            <a href="./API-INDEX.html">View Index →</a>
        </div>
    </div>

    <div class="resources">
        <h2>Developer Resources</h2>
        <ul>
            <li><a href="../README.html">README - Project Overview</a></li>
            <li><a href="../ARCHITECTURE.html">ARCHITECTURE - System Design</a></li>
            <li><a href="../TESTING.html">TESTING - Test Suite Guide</a></li>
            <li><a href="../DOCUMENTATION.html">DOCUMENTATION - How to Write Docs</a></li>
            <li><a href="../CLEANUP-TOOLS.html">CLEANUP-TOOLS - Code Quality Tools</a></li>
            <li><a href="../DEV-TOOLS.html">DEV-TOOLS - Developer Toolchain</a></li>
        </ul>
    </div>

    <footer>
        <p><strong>Starisian Technologies</strong> | Generated: <span id="timestamp"></span></p>
        <p><a href="https://github.com/Starisian-Technologies/starmus-audio-recorder" target="_blank">View on GitHub</a></p>
    </footer>

    <script>
        document.getElementById('timestamp').textContent = new Date().toLocaleString();
    </script>
</body>
</html>
EOF

cat > "$DOCS_DIR/API-INDEX.md" << 'EOF'
# Starmus Audio Recorder - API Documentation Index

**Generated:** $(date)

## 📂 Documentation Structure

- **[PHP Markdown Docs](./php-md/)** - Auto-generated from PHP docblocks
- **[PHP HTML Docs](./php/)** - phpDocumentor output with full class diagrams
- **[JavaScript Docs](./js/)** - JSDoc-generated module documentation

---

## 🔷 PHP Classes

### Core System
- [StarmusAudioRecorder](./php-md/StarmusAudioRecorder.md) - Main plugin controller
- [StarmusAudioRecorderDAL](./php-md/StarmusAudioRecorderDAL.md) - Data access layer

### Frontend
- [StarmusAudioRecorderUI](./php-md/StarmusAudioRecorderUI.md) - Recording interface
- [StarmusAudioEditorUI](./php-md/StarmusAudioEditorUI.md) - Waveform editor interface

### Services
- [StarmusAudioProcessingService](./php-md/StarmusAudioProcessingService.md) - Audio conversion (WEBM→MP3/WAV)
- [StarmusFileService](./php-md/StarmusFileService.md) - File handling
- [StarmusWaveformService](./php-md/StarmusWaveformService.md) - Waveform data generation

### API
- [StarmusRESTHandler](./php-md/StarmusRESTHandler.md) - REST API endpoints
- [StarmusSubmissionHandler](./php-md/StarmusSubmissionHandler.md) - Form submission processing

### Assets
- [StarmusAssetLoader](./php-md/StarmusAssetLoader.md) - Frontend asset management

---

## 🟨 JavaScript Modules

### Core Modules
- **[starmus-integrator.js](./js/starmus-integrator.md)** - Main orchestrator & entry point
- **[starmus-core.js](./js/starmus-core.md)** - Submission engine
- **[starmus-recorder.js](./js/starmus-recorder.md)** - Audio recording logic
- **[starmus-ui.js](./js/starmus-ui.md)** - UI state controller
- **[starmus-state-store.js](./js/starmus-state-store.md)** - Redux-style state management

### Upload & Sync
- **[starmus-tus.js](./js/starmus-tus.md)** - TUS resumable upload protocol
- **[starmus-offline.js](./js/starmus-offline.md)** - IndexedDB offline queue

### Editor
- **[starmus-audio-editor.js](./js/starmus-audio-editor.md)** - Peaks.js waveform editor integration

### Infrastructure
- **[starmus-hooks.js](./js/starmus-hooks.md)** - Event bus & command dispatcher

---

## 📖 Additional Resources

- [README](../README.md) - Project overview
- [ARCHITECTURE](../ARCHITECTURE.md) - System design
- [TESTING](../TESTING.md) - Test suite guide
- [DOCUMENTATION](../DOCUMENTATION.md) - Documentation guide

---

_Generated automatically by Starmus Documentation System_
EOF

echo "✓ Created docs/index.html"
echo "✓ Created docs/API-INDEX.md"

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ UNIFIED DOCUMENTATION COMPLETE"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "📂 Documentation available at:"
echo "  • Main Hub:  docs/index.html"
echo "  • PHP (MD):  docs/php-md/"
echo "  • PHP (HTML): docs/php/"
echo "  • JavaScript: docs/js/"
echo "  • API Index: docs/API-INDEX.md"
echo ""
echo "🌐 To view locally:"
echo "  Open docs/index.html in a browser"
