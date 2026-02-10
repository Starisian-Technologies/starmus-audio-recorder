# Starmus WP-CLI Commands

These commands are registered by the plugin and are available when WP-CLI is present and the plugin is active.

## Prerequisites

- WP-CLI installed on the server.
- Run from the WordPress root (or use `wp-env` to run inside the test container).
- For waveform generation, the `audiowaveform` binary must be on the server PATH.

## Command Group

All Starmus commands are under:

```
wp starmus <command>
```

## Waveforms

Generate or delete waveform data for recordings or attachments.

```
# Generate waveforms for all recordings missing them
wp starmus waveform generate

# Regenerate waveforms for specific IDs
wp starmus waveform generate --post_ids=123,456 --regenerate

# Delete waveform data for specific attachments
wp starmus waveform delete --attachment_ids=789
```

Options:

- `--post_ids=<ids>`: Comma-separated audio-recording or attachment IDs.
- `--regenerate`: Force re-generation even if waveform data exists.
- `--chunk_size=<number>`: Batch size when processing all recordings (default 100).
- `--attachment_ids=<ids>`: Comma-separated attachment IDs to delete waveform data.

## Processing Pipeline

Run the full processing pipeline for a specific attachment or recording.

```
wp starmus process run --id=123
# Legacy flag name
wp starmus process run --attachment_id=123
```

## Cache

Flush Starmus taxonomy caches.

```
wp starmus cache flush
```

## Temp File Cleanup

Remove stale temporary upload files.

```
wp starmus cleanup-temp-files --days=3
```

Options:

- `--days=<days>`: Files older than this many days are removed (default 1).

## Export

Export audio recording metadata.

```
# CSV (default)
wp starmus export

# JSON
wp starmus export --format=json
```

Options:

- `--format=<format>`: `csv` or `json`.

## Import (Not Implemented)

```
wp starmus import /path/to/file.csv
wp starmus import /path/to/file.csv --dry-run
```

Note: The import command is currently a stub and will exit with an error.

## Regen

Force waveform + audio processing regeneration for a specific attachment or recording.

```
wp starmus regen 1344
```

## Scan Missing

Find recordings missing `waveform_json` and optionally repair them.

```
wp starmus scan-missing
wp starmus scan-missing --repair
wp starmus scan-missing --repair --limit=50
```

Options:

- `--repair`: Regenerate waveforms for missing entries.
- `--limit=<number>`: Max number of recordings to scan (default 100).

## Batch Regen

Batch regenerate waveforms for all audio attachments.

```
wp starmus batch-regen
wp starmus batch-regen --limit=50 --offset=100
```

Options:

- `--limit=<number>`: Max attachments to process (default 100).
- `--offset=<number>`: Pagination offset (default 0).

## Queue

Queue waveform regeneration for a specific attachment or recording (runs via cron).

```
wp starmus queue 1234
```

## WP-Env Usage (Test Container)

If you are using the repo test environment, you can run commands via `wp-env`:

```
npx wp-env run tests-cli -- wp starmus cache flush
```
