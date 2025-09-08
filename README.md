![Starmus Audio Recorder](https://github.com/user-attachments/assets/c51b26bb-f95f-4d8c-9340-dacdacca5d4f)

# Starmus Audio Recorder

A mobile-first audio recording and annotation plugin for WordPress, designed for low-bandwidth environments and legacy device compatibility.

---

**=== Starmus Audio Recorder ===**
**Contributors:** Starisian Technologies, Max Barrett
**Tags:** WordPress, Audio, Web Audio API, recorder, offline, tus, resumable
**Requires at least:** 6.4
**Tested up to:** 6.5
**Requires PHP:** 8.0
**Stable tag:** v0.5.0
**License:** See LICENSE.md

[![CodeQL](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/github-code-scanning/codeql/badge.svg)](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/github-code-scanning/codeql) [![Dependabot Updates](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/dependabot/dependabot-updates/badge.svg)](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/dependabot/dependabot-updates) 
[![Security Checks](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/security.yml/badge.svg)](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/security.yml) 

[![Release Code Quality Final Review](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/release.yml/badge.svg)](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/release.yml)

The Starmus Audio Recorder is a comprehensive WordPress solution for capturing, managing, and annotating user-submitted audio. Its standout feature is a resilient, offline-first submission system, ensuring recordings are never lost, even on unstable networks.

The plugin is built with a modern, modular architecture that intelligently provides advanced features like microphone calibration and speech-to-text to capable browsers, while offering a robust fallback experience for older devices.

Named after the iconic Starmus Festival, where rock legends and astrophysicists share the stage, this tool is built for creators and communities who believe voice has gravity.

---

## ✨ Key Features

- **Mobile-First, Two-Step UI:** A clean, accessible interface that separates metadata entry from the recording process for a focused user experience.
- **Resumable Uploads:** Powered by the `tus.io` protocol, audio uploads in small chunks and automatically resumes after network interruptions.
- **Offline Submission Queue:** Failed or offline submissions are saved securely in the user’s browser (via IndexedDB) and are automatically uploaded when connectivity is restored.
- **Progressive Enhancement:**
    - **Tier A (Modern Browsers):** Offers microphone calibration (gain control), noise suppression, and real-time speech-to-text transcription via the Speech Recognition API.
    - **Tier B/C (Legacy Browsers):** Gracefully degrades, providing a simpler recording experience or a file upload fallback to ensure 100% user compatibility.
- **Rich Metadata & Consent Capture:** Saves extensive session data, including a linked consent-agreement post, User ID, IP, and User Agent.
- **Geolocation Capture:** The legacy fallback script captures GPS coordinates, ideal for dialect and linguistic mapping projects.
- **Audio Annotation Editor:** A separate `[starmus_audio_editor]` shortcode powered by Peaks.js allows for detailed audio segmentation and labeling.
- **Developer Extensibility:** A rich set of WordPress hooks allows for deep customization without altering core plugin files.

## 📦 Requirements

- **WordPress:** 6.4 or higher
- **PHP:** 8.0 or higher
- **Server:**
    - A `tus.io` compatible server endpoint is **highly recommended** for resumable uploads. The plugin includes a fallback to the standard WordPress REST API.
    - The `audiowaveform` binary is required on the server's PATH for waveform generation in the editor.
- **Browser:** A modern browser supporting the MediaRecorder API is recommended for the best experience. A fallback is provided for older browsers.

## 🚀 Installation

1.  Download the latest release `.zip` file from this repository.
2.  In your WordPress Admin, go to **Plugins → Add New → Upload Plugin**.
3.  Upload the `.zip` file and click **Install Now**.
4.  Activate the plugin.
5.  (Optional but Recommended) Set up a `tusd` server endpoint and configure the URL in `StarmusAudioRecorderUI.php`.

## 🖥 Usage

The plugin provides three primary shortcodes:

#### 1. Audio Recorder
Displays the two-step recording form.
```php
[starmus_audio_recorder_form]
```
#### 2. User’s Recordings List
Displays a paginated, accessible list of the logged-in user's submissions.
```php
[starmus_my_recordings]
```
#### 3. Audio Editor
Displays the Peaks.js annotation editor. This page must be accessed via a secure link containing a `post_id` and a nonce, typically generated from the "My Recordings" list.
```php
[starmus_audio_editor]
```

Example URL: `https://yoursite.com/edit-recording/?post_id=123&nonce=...`

## For Developers: Architecture & Extensibility

- **StarmusPlugin** – main plugin controller  
- **StarmusAudioRecorderUI** – manages recording form, chunked uploads, metadata, and redirects  
- **StarmusAudioEditorUI** – manages the annotation editor and REST API

### Custom Post Types & Taxonomies

- **CPTs:**  
  - `audio-recording`  
  - `consent-agreement`  
- **Taxonomies:**  
  - `language`  
  - `recording_type` 

### Core JavaScript Architecture
The plugin uses a four-part modular architecture for its front-end application:

1.  **`starmus-audio-recorder-module.js` (The Engine):** A secure, modern recording engine using `MediaRecorder`. It handles mic access, calibration, and speech recognition. It has no knowledge of the UI or uploads.
2.  **`starmus-audio-recorder-submissions-handler.js` (The Uploader):** The submission specialist. It manages the `tus.io` resumable uploads, the offline IndexedDB queue, and the fallback to the WordPress REST API.
3.  **`starmus-audio-recorder-ui-controller.js` (The UI Controller):** The "glue" for modern browsers. It manages the two-step UI, validates form fields, and delegates tasks to the Engine and Uploader modules.
4.  **`starmus-audio-recorder-submissions.js` (The Legacy Fallback):** A self-contained script loaded by older browsers (`nomodule`). It provides polyfills and a simpler submission process, including geolocation capture.

### Third-Party Libraries
This plugin relies on the following excellent open-source libraries, which should be installed via `npm` and included in the `/assets/js/vendor/` directory:
-   **tus-js-client:** For resumable file uploads.
-   **Peaks.js:** For the audio annotation editor.
-   **Recorder.js:** Used as a fallback for browsers that lack `MediaRecorder`.

### Core Hooks
The Starmus Audio Recorder is a lightweight, front-end WordPress plugin that allows users to record audio directly in the browser using the MediaRecorder API.

- **`starmus_before_recorder_render` (Action)**  
  Fires before recorder form displays.
  *Example: Redirect if profile is incomplete.*

```php
  add_action('starmus_before_recorder_render', function() {
    if (!is_user_logged_in()) return;
    $first_name = get_user_meta(get_current_user_id(), 'first_name', true);
    if (empty($first_name)) {
        wp_safe_redirect(home_url('/edit-profile/?notice=incomplete'));
        exit;
    }
});
```

- **`starmus_after_audio_upload` (Action)**  
  Fires after recording + metadata saved.  
  *Example: Send an email to the admin.*

```php  
add_action('starmus_after_audio_upload', function($audio_post_id, $attachment_id, $form_data) {
    $title = get_the_title($audio_post_id);
    wp_mail(
        get_option('admin_email'),
        "New Audio Submission: {$title}",
        "A new recording has been submitted. View it here: " . get_edit_post_link($audio_post_id)
    );
}, 10, 3);
```

- **`starmus_audio_upload_success_response` (Filter)**  
  Modify JSON response.  
  *Example: add conditional redirect.*

```php
  add_filter('starmus_audio_upload_success_response', function($response, $post_id, $form_data) {
    if (isset($form_data['recording_type']) && 'oral-history' === $form_data['recording_type']) {
        $response['redirect_url'] = home_url("/add-oral-history-details/?recording_id={$post_id}");
    }
    return $response;
}, 10, 3);
```

### Audio Editor Hooks

- **`starmus_before_editor_render` (Action)** – Before the editor loads  
- **`starmus_editor_template` (Filter)** – Override the editor template  
- **`starmus_before_annotations_save` (Action)** – Fires via REST before annotations are saved  
- **`starmus_after_annotations_save` (Action)** – Fires after annotations saved

---
## Development Setup

Composer-based tools require a GitHub token for certain dependencies. Copy `auth.json.example` to `auth.json` and replace `YOUR_GITHUB_TOKEN_HERE` with your personal access token. Alternatively, set the `COMPOSER_AUTH` environment variable.

```json
{
  "github-oauth": {
    "github.com": "YOUR_GITHUB_TOKEN_HERE"
  }
}
```

---

## ** License**

**LicenseRef-Starisian-Technologies-Proprietary**

This software is governed by the **Starisian Technologies Confidential License**. Unauthorized use or distribution is strictly prohibited.

By accessing this repo, you accept:

- LICENSE.md — legal terms, jurisdiction
- TERMS.md — ethics, allowed use

**Not allowed:** surveillance, coercion, military use.  
 **Encouraged:** oral history, education, culture, community voice.

---

## **📰 Ethics & Governance**

You must adhere to these standards:

- No use in surveillance or coercion
- No use by military, police, or intelligence agencies
- Exemptions may be granted for verified educational/cultural programs

Full details in `ETHICS.md` or by request.

---

## **🚀 Why "Starmus"?**

Starmus honors the **Starmus Festival** founded by Dr. Garik Israelian and **Dr. Brian May** (guitarist of Queen \+ astrophysicist). Where **science meets sound**, this plugin captures that same cosmic energy — whether it’s a voice memo beneath the stars or an oral history from a rural village.

**Starmus Audio Recorder** is a small tool with a **big mission**: to preserve stories, songs, and spirit in their purest form.

---

## **🤝 Cultural & Creative Projects Welcome**

While this plugin is released under a restricted proprietary license, we actively **support nonprofit, educational, and cultural storytelling** projects.

If you're working in **underserved communities** or preserving oral traditions, reach out. We’re happy to explore **free or discounted licensing**.

---

## **🌍 Contact**

**Starisian Technologies**  
 815 E Street, Suite 12083  
 San Diego, CA 92101  
 **Email:** <support@starisian.com>

---

**Made for creators. Built for culture. Inspired by the stars.**
