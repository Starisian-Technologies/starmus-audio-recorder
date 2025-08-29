![Starmus Audio Recorder](https://github.com/user-attachments/assets/c51b26bb-f95f-4d8c-9340-dacdacca5d4f)

# Starmus Audio Recorder

**A mobile-first audio recording and annotation plugin for WordPress, designed for low-bandwidth environments.**

The **Starmus Audio Recorder** provides a comprehensive solution for capturing, managing, and annotating user-submitted audio content. Its core feature is a chunked uploader with an offline queue, ensuring recordings are never lost—even with intermittent internet connections.

The plugin is built to be **highly extensible**, offering developers a rich set of WordPress hooks for custom metadata handling, conditional logic, and pre/post-processing.

Named after the iconic **Starmus Festival**, where rock legends and astrophysicists share the stage, this tool is built for creators and communities who believe **voice has gravity**.

[![CodeQL](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/github-code-scanning/codeql/badge.svg)](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/github-code-scanning/codeql)
[![Security Checks](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/security.yml/badge.svg)](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/security.yml)

---

## ✨ Key Features

- **Mobile-Friendly Recorder UI**  
  Clean, accessible, two-step interface: users provide details first, then record their audio.
- **Resilient Chunked Uploads**  
  Audio uploads in small chunks, ensuring reliability on slow or unstable networks.
- **Offline Submission Queue**  
  Failed uploads are saved to the user’s browser (via IndexedDB) and re-attempted once online.
- **Dynamic Form Generation**  
  Dropdowns (e.g. *Language*, *Recording Type*) are populated automatically from your site’s taxonomies.
- **Rich Metadata Capture**  
  Saves extensive session metadata, including a linked `consent-agreement` post capturing User ID, IP, User Agent, and timestamp.
- **Geolocation Capture**  
  Optionally saves GPS coordinates with each recording—ideal for dialect and linguistic mapping.
- **Audio Annotation Editor**  
  Powered by Peaks.js, the `[starmus_audio_editor]` shortcode allows users to segment and label their recordings.  
  Annotations are stored securely via REST API.
- **Developer Extensibility**  
  Object-oriented architecture + numerous WordPress hooks = deep customization without hacking core files.

---

## 📦 Requirements

- **WordPress:** 6.4 or higher  
- **PHP:** 8.2 or higher  
- **Server Dependency:** [`audiowaveform`](https://github.com/bbc/audiowaveform) binary available on server PATH for waveform generation  
- **Browser:** Modern browser supporting MediaRecorder + Geolocation APIs (Chrome, Firefox, Safari, Edge)

---

## 🚀 Installation

1. Download the latest release ZIP file from this repository.  
2. In WordPress Admin, go to **Plugins → Add New → Upload Plugin**.  
3. Upload the ZIP, then click **Install Now**.  
4. Activate the plugin.

---

## 🖥 Usage

The plugin provides three primary shortcodes:

### 1. Audio Recorder

Displays the two-step recording form.  

```php
[starmus_audio_recorder]
```

### 2. User’s Recordings List

Displays a paginated list of logged-in user submissions.  

```php
[starmus_my_recordings]
```

### 3. Audio Editor

Displays the Peaks.js annotation editor. Requires a `post_id` in the URL.  

```php
[starmus_audio_editor]
```

**Example URL:**  

```php
https://yoursite.com/edit-recording/?post_id=123
```

---

## For Developers: Architecture & Extensibility

### Core Architecture

- **StarmusPlugin** – main plugin controller  
- **StarmusAudioRecorderUI** – manages recording form, chunked uploads, metadata, and redirects  
- **StarmusAudioEditorUI** – manages the annotation editor and REST API  
- **JavaScript Modules:**  
  - `starmus-audio-recorder-module.js` (recorder engine)  
  - `starmus-audio-recorder-submissions.js` (form UI, offline queue, AJAX)

### Custom Post Types & Taxonomies

- **CPTs:**  
  - `audio-recording`  
  - `consent-agreement`  
- **Taxonomies:**  
  - `language`  
  - `recording_type`

---

## Hooks

### Audio Recorder Hooks

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

## License

This plugin is released under a **proprietary license**.  
See [LICENSE.md](LICENSE.md) for details.

---

## **🚀 Why "Starmus"?**

Starmus honors the **Starmus Festival** founded by Dr. Garik Israelian and **Dr. Brian May** (guitarist of Queen \+ astrophysicist). Where **science meets sound**, this plugin captures that same cosmic energy — whether it’s a voice memo beneath the stars or an oral history from a rural village.

**Starmus Audio Recorder** is a small tool with a **big mission**: to preserve stories, songs, and spirit in their purest form.

---

## **🤝 Cultural & Creative Projects Welcome**

While this plugin is released under a restricted proprietary license, we actively **support nonprofit, educational, and cultural storytelling** projects.

If you're working in **underserved communities** or preserving oral traditions, reach out. We’re happy to explore **free or discounted licensing**.

**📧 Contact: <support@aiwestafrica.com>**

---

## **🔮 Future Directions**

- Offline saving & encryption (PWA)

- Metadata tagging

- Optional MP3 conversion via WebAssembly

---

## **📄 License**

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

## **🌍 Contact**

**Starisian Technologies**  
 815 E Street, Suite 12083  
 San Diego, CA 92101  
 **Email:** <support@starisian.com>

---

**Made for creators. Built for culture. Inspired by the stars.**
