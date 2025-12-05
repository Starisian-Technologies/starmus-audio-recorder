#!/bin/bash

# Starmus Audio Recorder - Translation Build Script
# Based on AiWA Orchestrator i18n workflow
# Copyright (C) 2025 Starisian Technologies

set -e

PROJECT_NAME="Starmus Audio Recorder"
TEXT_DOMAIN="starmus-audio-recorder"
LANGUAGES_DIR="languages"
POT_FILE="${LANGUAGES_DIR}/${TEXT_DOMAIN}.pot"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}🌐 Building translation files for ${PROJECT_NAME}${NC}"

# Check if WP-CLI is available
if ! command -v wp &> /dev/null; then
    echo -e "${YELLOW}⚠️  WP-CLI not found. Installing WP-CLI...${NC}"
    
    # Download WP-CLI
    curl -O https://raw.githubusercontent.com/wp-cli/wp-cli/v2.9.0/utils/wp-cli-phar-generator/wp-cli.phar
    chmod +x wp-cli.phar
    
    # Use local WP-CLI
    WP_CLI="./wp-cli.phar"
    echo -e "${GREEN}✅ WP-CLI downloaded successfully${NC}"
else
    WP_CLI="wp"
fi

# Ensure languages directory exists
mkdir -p "${LANGUAGES_DIR}"

# Function to check if POT file was created successfully
check_pot_file() {
    local pot_file="$1"
    local file_type="$2"
    
    if [ ! -f "$pot_file" ]; then
        echo -e "${RED}❌ Error: ${file_type} POT file was not created${NC}"
        return 1
    fi
    
    # Check if file has content (more than just headers)
    local line_count=$(wc -l < "$pot_file")
    if [ "$line_count" -lt 20 ]; then
        echo -e "${YELLOW}⚠️  Warning: ${file_type} POT file seems to have very few translations${NC}"
    fi
    
    echo -e "${GREEN}✅ ${file_type} POT file created successfully: $pot_file${NC}"
    echo -e "${BLUE}   Lines: $line_count${NC}"
}

# Generate main PHP POT file
echo -e "${YELLOW}📝 Generating PHP translations...${NC}"

$WP_CLI i18n make-pot . "$POT_FILE" \
    --domain="$TEXT_DOMAIN" \
    --package-name="$PROJECT_NAME" \
    --file-comment="Translation file for the $PROJECT_NAME plugin.
Copyright (C) $(date +%Y) Starisian Technologies
This file is distributed under the same license as the $PROJECT_NAME plugin." \
    --exclude="node_modules,vendor,tests,build,dist,.git,.github,wp-admin,wp-content,wordpress-installer"

check_pot_file "$POT_FILE" "PHP"

# Summary
echo ""
echo -e "${GREEN}🎉 Translation build completed successfully!${NC}"
echo -e "${BLUE}📁 Files generated:${NC}"
echo -e "   📄 $POT_FILE"
echo ""
echo -e "${YELLOW}💡 Next steps:${NC}"
echo "   1. Review the generated POT file"
echo "   2. Create language-specific PO files (e.g., ${TEXT_DOMAIN}-fr_FR.po)"
echo "   3. Translate the strings in your PO files"
echo "   4. Convert PO files to MO files for production use"
echo ""
echo -e "${BLUE}🔗 Useful commands:${NC}"
echo "   composer run makepot       # Generate translations"
echo "   bash bin/makepot.sh       # Run this comprehensive script"

# Clean up downloaded WP-CLI if we downloaded it
if [ -f "./wp-cli.phar" ]; then
    rm ./wp-cli.phar
    echo -e "${BLUE}🧹 Cleaned up temporary WP-CLI download${NC}"
fi