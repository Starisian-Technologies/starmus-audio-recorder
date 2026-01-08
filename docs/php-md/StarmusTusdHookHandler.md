# StarmusTusdHookHandler

**Namespace:** `Starisian\Sparxstar\Starmus\includes`

**File:** `/workspaces/starmus-audio-recorder/src/includes/StarmusTusdHookHandler.php`

## Description

Handles TUS daemon webhook callbacks for upload completion events.
Provides REST API endpoint to receive notifications from the TUS daemon
when file uploads are completed. Processes upload metadata and moves
files to their final destinations while maintaining security boundaries.
@package Starisian\Sparxstar\Starmus\includes
@since 1.0.0
@see https://tus.io/protocols/resumable-upload.html TUS Protocol Specification
@see StarmusSubmissionHandler For file processing implementation
Security Features:
- Webhook secret validation via x-starmus-secret header
- Path traversal protection for temporary file cleanup
- Input sanitization and validation
- JSON-only communication with proper content type handling
Supported TUS Events:
- post-finish: Upload completion notification with file processing
- Default: Generic event acknowledgment

## Methods

### `__construct()`

**Visibility:** `public`

Handles TUS daemon webhook callbacks for upload completion events.
Provides REST API endpoint to receive notifications from the TUS daemon
when file uploads are completed. Processes upload metadata and moves
files to their final destinations while maintaining security boundaries.
@package Starisian\Sparxstar\Starmus\includes
@since 1.0.0
@see https://tus.io/protocols/resumable-upload.html TUS Protocol Specification
@see StarmusSubmissionHandler For file processing implementation
Security Features:
- Webhook secret validation via x-starmus-secret header
- Path traversal protection for temporary file cleanup
- Input sanitization and validation
- JSON-only communication with proper content type handling
Supported TUS Events:
- post-finish: Upload completion notification with file processing
- Default: Generic event acknowledgment
/
class StarmusTusdHookHandler
{
    /**
REST API namespace for webhook endpoints.
@since 1.0.0
/
    protected string $namespace = 'starmus/v1';

    /**
REST API base path for webhook routes.
@since 1.0.0
/
    protected string $rest_base = 'hook';

    /**
Initializes the TUS webhook handler with required dependencies.
@param StarmusSubmissionHandler $submission_handler Handler for processing completed uploads
@since 1.0.0

### `register_hooks()`

**Visibility:** `public`

Registers WordPress action hooks for REST API initialization.
Hooks into the WordPress REST API initialization process to register
the webhook endpoint routes when the REST API is ready.
@since 1.0.0
@hook rest_api_init Called when WordPress REST API is initialized

### `register_routes()`

**Visibility:** `public`

Registers REST API routes for TUS webhook handling.
Creates the webhook endpoint that accepts POST requests from the TUS daemon
with proper validation, permission checking, and parameter sanitization.
Route: POST /wp-json/starmus/v1/hook
@since 1.0.0
@see handle_tusd_hook() Main webhook callback handler
@see permissions_check() Authorization validation
Required Parameters:
- Type: Event type string (e.g., 'post-finish')
- Event: Event data object with upload information

### `handle_tusd_hook()`

**Visibility:** `public`

Main webhook callback handler for TUS daemon notifications.
Processes incoming webhook requests from the TUS daemon, validates the payload,
and routes different event types to their appropriate handlers.
@param WP_REST_Request $request Incoming webhook request with JSON payload
@return WP_REST_Response|WP_Error Success response with empty JSON or error object
@since 1.0.0
@see handle_post_finish() Handler for upload completion events
Expected JSON Payload:
```json
{
  "Type": "post-finish",
  "Event": {
    "Upload": {
      "Storage": { "Path": "/tmp/upload", "InfoPath": "/tmp/upload.info" },
      "MetaData": { "postId": "123", "title": "Recording" }
    }
  }
}
```
Supported Event Types:
- post-finish: Upload completion with file processing
- default: Generic event acknowledgment
Error Responses:
- 400: Invalid JSON body or missing required fields
- 403: Authorization failure (handled by permissions_check)
- 500: Internal processing errors

### `permissions_check()`

**Visibility:** `public`

Validates webhook authorization using shared secret header.
Implements secure webhook authentication by comparing a shared secret
sent via the x-starmus-secret header against the configured value.
Uses timing-safe comparison to prevent timing attacks.
@param WP_REST_Request $request Incoming webhook request
@return true|WP_Error True if authorized, WP_Error if unauthorized
@since 1.0.0
Required Configuration:
- STARMUS_TUS_WEBHOOK_SECRET constant must be defined
- TUS daemon must be started with: -hooks-http-forward-headers x-starmus-secret
- Client must send header: x-starmus-secret: {shared_secret}
Security Features:
- Timing-safe string comparison using hash_equals()
- Validates secret is non-empty and not default values
- Logs security violations without exposing secrets
- Returns appropriate HTTP status codes
Error Responses:
- 500: STARMUS_TUS_WEBHOOK_SECRET not configured
- 403: Missing or invalid secret header
@see hash_equals() Timing-safe string comparison
@example
Configuration in wp-config.php:
```php
define('STARMUS_TUS_WEBHOOK_SECRET', 'your-random-secret-key');
```
TUS daemon startup:
```bash
tusd -hooks-http-forward-headers x-starmus-secret
```

## Properties

### `$namespace`

**Visibility:** `protected`

Handles TUS daemon webhook callbacks for upload completion events.
Provides REST API endpoint to receive notifications from the TUS daemon
when file uploads are completed. Processes upload metadata and moves
files to their final destinations while maintaining security boundaries.
@package Starisian\Sparxstar\Starmus\includes
@since 1.0.0
@see https://tus.io/protocols/resumable-upload.html TUS Protocol Specification
@see StarmusSubmissionHandler For file processing implementation
Security Features:
- Webhook secret validation via x-starmus-secret header
- Path traversal protection for temporary file cleanup
- Input sanitization and validation
- JSON-only communication with proper content type handling
Supported TUS Events:
- post-finish: Upload completion notification with file processing
- Default: Generic event acknowledgment
/
class StarmusTusdHookHandler
{
    /**
REST API namespace for webhook endpoints.
@since 1.0.0

### `$rest_base`

**Visibility:** `protected`

REST API base path for webhook routes.
@since 1.0.0

---

_Generated by Starisian Documentation Generator_
