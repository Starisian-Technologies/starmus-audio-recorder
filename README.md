![Starmus Audio Recorder](https://github.com/user-attachments/assets/c51b26bb-f95f-4d8c-9340-dacdacca5d4f)

# **✨ Starmus Audio Recorder (WordPress Plugin)**

The **Starmus Audio Recorder** is a minimalist front-end WordPress plugin for capturing oral histories, vocals, or field recordings using the browser’s built-in MediaRecorder API.

Named after the iconic **Starmus Festival**, where rock legends and astrophysicists share the stage, this tool is built for creators and communities who believe **voice has gravity**.

[![CodeQL](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/github-code-scanning/codeql/badge.svg)](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/github-code-scanning/codeql) [![Proof HTML, Lint JS & CSS](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/proof-html-js-css.yml/badge.svg)](https://github.com/Starisian-Technologies/starmus-audio-recorder/actions/workflows/proof-html-js-css.yml)

---

## **🔥 Features**

* 🎤 **Native, in-browser recording** using the MediaRecorder API (no external libraries)
* 🛡️ **Consent-first UX** — checkbox, timer, and playback before upload
* 📤 **MP4/AAC & WebM/Opus output** automatically attached to form
* 📱 **Mobile-friendly, PWA-capable**, no 3rd-party dependencies
* 🧠 **Clean JS** codebase ready for extensions (transcription, waveform, MP3 export)
* 👉 Developed for **low-bandwidth, mobile-first usage in The Gambia**, prioritizing offline, accessibility, and minimal dependencies.

---

## **📂 File Structure**

starmus-audio-recorder/  
├── starmus-audio-recorder.php         \# Plugin loader, shortcode, enqueuing  
├── templates/  
│   └── starmus-audio-recorder-ui.html \# Recorder UI HTML  
├── assets/  
│   ├── js/  
│   │   └── starmus-audio-recorder.js  \# MediaRecorder API logic  
│   └── css/  
│       └── starmus-audio-recorder-style.css \# Styling (optional)

---

## **🛠️ Installation**

1. Download or clone this repository.

2. Place it in your `/wp-content/plugins/` directory.

3. Activate **Starmus Audio Recorder** in WordPress admin.

4. Embed the UI using shortcode or insert this HTML:

```html
<!--
  Starmus Audio Recorder UI
  This HTML is designed to work with starmus-audio-recorder.js and uses the native MediaRecorder API.
  It enables a user to record audio in-browser.
  The JavaScript is responsible for handling the recorded audio data
  for playback and submission via the form fields below.
-->

<div id="starmus_audioWrapper" class="sparxstar-audioWrapper" data-enabled-recorder>
    <h2 id="sparxstar_audioRecorderHeading" class="sparxstar-h2">Audio Recorder</h2>
    <div id="sparxstar_audioRecorder" class="sparxstar_audioRecorder" role="region" aria-labelledby="sparxstar_audioRecorderHeading">

        <!-- Consent Checkbox -->
        <label for="field_consent">
            <input type="checkbox" id="field_consent" name="item_meta[YOUR_CONSENT_FIELD_NUMBER]" value="1" required>
            I consent to the recording and submission of this oral history.
        </label>

        <!-- Recorder Controls -->
        <div class="sparxstar_recorderControls" role="group" aria-label="Recording controls">
            <button type="button" id="recordButton" class="sparxstar_button">Record</button>
            <button type="button" id="pauseButton" class="sparxstar_button" disabled>Pause</button>
            <button type="button" id="playButton" class="sparxstar_button" disabled>Play</button>
        </div>

        <!-- Volume Meter -->
        <div id="sparxstar_audioLevelWrap" class="sparxstar_audioLevelWrap" aria-hidden="true">
            <div id="sparxstar_audioLevelBar"
                 class="sparxstar_audioLevelBar"
                 role="meter"
                 aria-valuenow="0"
                 aria-valuemin="0"
                 aria-valuemax="100"
                 aria-label="Microphone input level"></div>
        </div>

        <!-- Optional Status Message -->
        <div id="sparxstar_status" role="status" aria-live="polite" class="visually-hidden"></div>

        <!-- Timer Display -->
        <div id="sparxstar_timer" class="sparxstar_timer" role="timer" aria-live="polite">00:00</div>

        <!-- Audio Playback -->
        <audio id="sparxstar_audioPlayer" class="sparxstar_audioPlayer" controls aria-label="Recorded audio preview"></audio>

        <!-- Hidden Form Fields -->
        <input type="file" name="item_meta[YOUR_AUDIO_UPLOAD_FIELD_NUMBER]" accept="audio/*" style="display:none;">
        <input type="hidden" name="audio_uuid">
    </div>
</div>
```
---

## **🚀 Why "Starmus"?**

Starmus honors the **Starmus Festival** founded by Dr. Garik Israelian and **Dr. Brian May** (guitarist of Queen \+ astrophysicist).

Where **science meets sound**, this plugin captures that same cosmic energy — whether it’s a voice memo beneath the stars or an oral history from a rural village.

**Starmus Audio Recorder** is a small tool with a **big mission**: to preserve stories, songs, and spirit in their purest form.

---

## **🤝 Cultural & Creative Projects Welcome**

While this plugin is released under a restricted proprietary license, we actively **support nonprofit, educational, and cultural storytelling** projects.

If you're working in **underserved communities** or preserving oral traditions, reach out. We’re happy to explore **free or discounted licensing**.

**📧 Contact: support@aiwestafrica.com**

---

## **🔮 Future Directions**

* Offline saving & encryption (PWA)

* Metadata tagging & speaker consent logging

* Optional MP3 conversion via WebAssembly

---

## **📄 License**

**LicenseRef-Starisian-Technologies-Proprietary**

This software is governed by the **Starisian Technologies Confidential License**. Unauthorized use or distribution is strictly prohibited.

By accessing this repo, you accept:

* LICENSE.md — legal terms, jurisdiction

* TERMS.md — ethics, allowed use

**Not allowed:** surveillance, coercion, military use.  
 **Encouraged:** oral history, education, culture, community voice.

---

## **📰 Ethics & Governance**

You must adhere to these standards:

* No use in surveillance or coercion

* No use by military, police, or intelligence agencies

* Exemptions may be granted for verified educational/cultural programs

Full details in `ETHICS.md` or by request.

---

## **🌍 Contact**

**Starisian Technologies**  
 815 E Street, Suite 12083  
 San Diego, CA 92101  
 **Email:** support@starisian.com

---

**Made for creators. Built for culture. Inspired by the stars.**

