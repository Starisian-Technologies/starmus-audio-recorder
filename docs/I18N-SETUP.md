# Starmus Audio Recorder - Internationalization (i18n) Setup

Based on the AiWA Orchestrator translation workflow, this document describes the complete internationalization setup for the Starmus Audio Recorder plugin.

## Overview

The Starmus Audio Recorder plugin is fully prepared for internationalization with:

- ✅ **Complete makepot script setup** (similar to AiWA Orchestrator)
- ✅ **Proper text domain configuration**: `starmus-audio-recorder`
- ✅ **Centralized translation management** via `Starmusi18NLanguage` class
- ✅ **WordPress translation best practices** implemented
- ✅ **Automated POT file generation** with 627+ translatable strings detected

## File Structure

```
languages/
├── .gitkeep                           # Ensures directory tracking
├── starmus-audio-recorder.pot         # Generated translation template (627 strings)
├── README-translation-template.po     # Example translation file structure
└── [future .po/.mo files]            # Language-specific translations

bin/
└── makepot.sh                        # Comprehensive build script (AiWA-style)

src/i18n/
└── Starmusi18NLanguage.php           # Translation management class
```

## Usage Commands

### Primary Commands (Composer Scripts)

```bash
# Generate translation template (POT file)
composer run makepot

# Alternative: Run the comprehensive build script directly
bash bin/makepot.sh
```

### Manual Translation Workflow

```bash
# 1. Generate POT file
composer run makepot

# 2. Create language-specific files (example for French)
cp languages/README-translation-template.po languages/starmus-audio-recorder-fr_FR.po

# 3. Translate strings in your .po file
# Edit languages/starmus-audio-recorder-fr_FR.po

# 4. Generate binary .mo file for production
msgfmt -o languages/starmus-audio-recorder-fr_FR.mo languages/starmus-audio-recorder-fr_FR.po
```

## Technical Details

### Text Domain Configuration

- **Domain**: `starmus-audio-recorder`
- **Loaded in**: `Starmusi18NLanguage::load_textdomain()`
- **Usage**: `Starmusi18NLanguage::t('Your string here')`

### Translation Class Features

```php
// Basic translation
echo Starmusi18NLanguage::t('Save Settings');

// JavaScript localization (escaped and ready)
$js_strings = (new Starmusi18NLanguage())->get_js_strings();
wp_localize_script('your-script', 'starmusL10n', $js_strings);
```

### POT Generation Features

- **Auto-detection**: Plugin file structure automatically recognized
- **String extraction**: 627+ strings found across PHP templates and classes
- **Exclusions**: Vendor, tests, build directories properly excluded
- **Validation warnings**: Placeholder strings flagged for translator comments
- **Headers**: Proper copyright and contact information included

### Integration with WordPress

The i18n system integrates with WordPress through:

- `load_plugin_textdomain()` for translation loading
- Standard `__()` function wrapper via `Starmusi18NLanguage::t()`
- `wp_localize_script()` compatibility for JavaScript strings
- Proper escaping via `esc_html()` and `esc_attr()`

## Comparison with AiWA Orchestrator

This setup mirrors the AiWA Orchestrator i18n workflow with:

- ✅ **Same script structure** in `bin/makepot.sh`
- ✅ **Identical composer script naming** (`makepot`)
- ✅ **Similar translation class pattern** (centralized management)
- ✅ **Consistent file organization** (languages directory)
- ✅ **Same build process** (POT → PO → MO workflow)

## Next Steps for Translators

1. **Review the generated POT file**: `languages/starmus-audio-recorder.pot`
2. **Add translator comments** for the flagged placeholder strings:
   - `"ID: %d"` in `starmus-audio-editor-ui.php:107`
   - `"Audio file missing for recording #%d."` in `starmus-audio-editor-ui.php:127`
   - `"Duration: %s"` in `starmus-my-recordings-list.php:131`
   - `"View details for %s"` in `starmus-my-recordings-list.php:138`
   - `"Edit audio for %s"` in `starmus-my-recordings-list.php:148`

3. **Create language files** using the template in `README-translation-template.po`
4. **Test translations** by generating .mo files and activating them in WordPress

## Maintenance

The POT file should be regenerated whenever:

- New translatable strings are added to the codebase
- Existing strings are modified
- Before major releases to ensure translation completeness

Run `composer run makepot` as part of the release preparation workflow.
