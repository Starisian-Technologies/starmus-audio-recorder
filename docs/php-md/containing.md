# containing

**Namespace:** `Starisian\Sparxstar\Starmus\cli`

**File:** `/workspaces/starmus-audio-recorder/src/cli/StarmusCLI.php`

## Description

WP-CLI commands for managing the Starmus Audio Recorder plugin.
This is the final, consolidated class containing all commands and best practices.
@package Starisian\Sparxstar\Starmus\cli
@version 0.9.2

## Methods

### `waveform()`

**Visibility:** `public`

WP-CLI commands for managing the Starmus Audio Recorder plugin.
This is the final, consolidated class containing all commands and best practices.
@package Starisian\Sparxstar\Starmus\cli
@version 0.9.2
/
namespace Starisian\Sparxstar\Starmus\cli;

use function absint;
use function file_exists;
use function get_post;
use function get_post_meta;
use function get_posts;
use function is_readable;

use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\core\StarmusSubmissionHandler;
use Starisian\Sparxstar\Starmus\cron\StarmusCron;
use Starisian\Sparxstar\Starmus\data\StarmusAudioDAL;
use Starisian\Sparxstar\Starmus\frontend\StarmusAudioRecorderUI;
use Starisian\Sparxstar\Starmus\services\StarmusAudioPipeline;
use Starisian\Sparxstar\Starmus\services\StarmusPostProcessingService;
use Starisian\Sparxstar\Starmus\services\StarmusWaveformService;

use function strtotime;
use function wp_cache_flush;

use WP_CLI;

use function WP_CLI\Utils\make_progress_bar;

use WP_CLI_Command;

use function wp_get_attachment_url;

use WP_Post;
use WP_Query;

use function wp_strip_all_tags;

if ( ! \defined('ABSPATH')) {
    exit;
}

// WP_CLI guard removed: always define the class, only register commands when WP_CLI is present.

/**
Manages the Starmus Audio Recorder plugin.
/
class StarmusCLI extends WP_CLI_Command
{
    /**
Waveform service instance.
/
    private ?StarmusWaveformService $waveform_service = null;

    /**
Pipeline service instance.
/
    private ?StarmusAudioPipeline $pipeline = null;

    public function __construct()
    {
        $this->waveform_service = new StarmusWaveformService();
        $this->pipeline = new StarmusAudioPipeline();
    }

    /**
Manages audio recording waveforms.
## EXAMPLES
    # Generate waveforms for all recordings missing them.
    $ wp starmus waveform generate
    # Force regenerate waveforms for recordings or attachments 123 and 456.
    $ wp starmus waveform generate --post_ids=123,456 --regenerate
    # Delete waveform data for attachment or recording ID 789.
    $ wp starmus waveform delete --attachment_ids=789
@param mixed $args

### `process()`

**Visibility:** `public`

Manages processing pipeline for recordings.
## OPTIONS
<action>
: The action to perform.
---
options:
  - run
---
[--id=<id>]
: Attachment ID OR audio-recording ID.
[--attachment_id=<id>]
: Attachment ID OR audio-recording ID (legacy name).
## EXAMPLES
    # Run the pipeline for a specific recording
    $ wp starmus process run --id=123
@param mixed $args
@param mixed $assoc_args

### `cache()`

**Visibility:** `public`

Manages the Starmus caches.
## EXAMPLES
    # Flush taxonomy caches
    $ wp starmus cache flush
@param mixed $args
@param mixed $assoc_args

### `cleanup_temp_files()`

**Visibility:** `public`

Cleans up stale temporary files.
## OPTIONS
[--days=<days>]
: Cleanup files older than this many days. Defaults to 1.
---
default: 1
---
@subcommand cleanup-temp-files
@alias cleanup_temp_files
@param mixed $args

### `export()`

**Visibility:** `public`

Exports audio recording metadata.
## OPTIONS
[--format=<format>]
: The export format. `csv` or `json`. Defaults to `csv`.
---
default: csv
options:
  - csv
  - json
---
@param mixed $args

### `import()`

**Visibility:** `public`

Imports audio recordings from a CSV file.
## OPTIONS
[<file>]
: The path to the CSV file to import.
[--dry-run]
: Preview the import without creating posts or attachments.
@param mixed $args
@param mixed $assoc_args

### `regen()`

**Visibility:** `public`

Force waveform + mastering regeneration for an attachment OR recording.
## OPTIONS
<id>
: Attachment ID OR audio-recording ID.
## EXAMPLES
    wp starmus regen 1344   # attachment
    wp starmus regen 1343   # audio-recording (auto-resolves)
@subcommand regen

### `scan_missing()`

**Visibility:** `public`

Scan for audio recordings with missing waveform_json and optionally repair them.
## OPTIONS
[--repair]
: Automatically regenerate waveforms for recordings with missing data.
[--limit=<number>]
: Maximum number of recordings to process (default: 100).
## EXAMPLES
    wp starmus scan-missing
    wp starmus scan-missing --repair
    wp starmus scan-missing --repair --limit=50
@subcommand scan-missing

### `batch_regen()`

**Visibility:** `public`

Batch regenerate waveforms for all audio attachments.
## OPTIONS
[--limit=<number>]
: Maximum number of attachments to process (default: 100).
[--offset=<number>]
: Offset for pagination (default: 0).
## EXAMPLES
    wp starmus batch-regen
    wp starmus batch-regen --limit=50 --offset=100
@subcommand batch-regen

### `queue()`

**Visibility:** `public`

Queue waveform regeneration for a specific attachment or recording (runs via cron).
## OPTIONS
<id>
: Attachment ID OR audio-recording ID.
## EXAMPLES
    wp starmus queue 1234   # attachment
    wp starmus queue 1233   # audio-recording
@subcommand queue

## Properties

---

_Generated by Starisian Documentation Generator_
