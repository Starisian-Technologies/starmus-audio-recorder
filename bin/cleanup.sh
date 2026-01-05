#!/bin/bash
# SPDX-FileCopyrightText: 2023-2025 Starisian Technologies
# SPDX-License-Identifier: MIT

set -e

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "🧹 STARMUS CLEANUP SUITE"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Check if running in dry-run mode
DRY_RUN=${1:-""}

if [ "$DRY_RUN" == "--dry-run" ] || [ "$DRY_RUN" == "-n" ]; then
    echo "🔍 DRY RUN MODE - No changes will be made"
    echo ""
    
    echo "📋 1. Rector (Dry Run)"
    composer rector:dry || true
    
    echo ""
    echo "📋 2. PHP CS Fixer (Dry Run)"
    composer phpfix:dry || true
    
    echo ""
    echo "📋 3. PHPCS Check"
    composer phpcs || true
    
    echo ""
    echo "📋 4. ESLint Check"
    npm run lint:js || true
    
    echo ""
    echo "📋 5. Stylelint Check"
    npm run lint:css || true
    
else
    echo "🔧 FULL CLEANUP MODE - Making changes"
    echo ""
    
    echo "📋 1. Rector (Structural PHP fixes)"
    composer rector || true
    
    echo ""
    echo "📋 2. PHP CS Fixer (Code style)"
    composer phpfix || true
    
    echo ""
    echo "📋 3. PHPCBF (WordPress standards)"
    composer phpcbf || true
    
    echo ""
    echo "📋 4. ESLint + Prettier (JavaScript)"
    npm run lint:js:fix || true
    
    echo ""
    echo "📋 5. Stylelint (CSS)"
    npm run lint:css:fix || true
    
    echo ""
    echo "📋 6. Prettier (All files)"
    npm run format || true
    
    echo ""
    echo "📋 7. Markdownlint (Documentation)"
    npm run lint:md:fix || true
    
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ CLEANUP COMPLETE"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "Run validation:"
echo "  npm run lint"
echo "  composer test"
