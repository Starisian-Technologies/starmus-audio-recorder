![Starmus Audio Recorder](https://github.com/user-attachments/assets/c51b26bb-f95f-4d8c-9340-dacdacca5d4f)

# 🌌 Starmus Audio Recorder (WordPress Plugin)

The **Starmus Audio Recorder** is a minimalist front-end WordPress plugin for capturing **oral histories, vocals, or field recordings** using the browser’s built-in **MediaRecorder API**. Named after the iconic **Starmus Festival**, where rock legends and astrophysicists share the stage, this tool is built for creators and communities who believe voice has gravity.

## 🔥 Features

- 🎙️ Native, in-browser audio recording using the native MediaRecorder API (no external libraries)
- 🛡️ Consent-first UX — users must opt in consent checkbox, timer, and playback UI before recording
- 📤 MP4/AAC and WebM/Opus output automatically attaches audio to a hidden file input for form upload
- 📱 Lightweight, mobile-friendly, offline-capable works without third-party JavaScript libraries
- 🧠 Clean codebase ready for extension (e.g., transcription, waveform, MP3 export)


## 📂 File Structure

starmus-audio-recorder/
├── starmus-audio-recorder.php # Plugin loader, shortcode, asset enqueuing
├── templates/
|   └── starmus-audio-recorder-ui.html # Consent checkbox, recorder controls, audio preview, file input
└── Assets/
    └── js/
    |   └── starmus-audio-recorder.js # JavaScript using MediaRecorder API
    └── css/ 
        └── starmus-audio-recorder-style.css # Optional styling

   ---

## 🛠️ Installation
1. Download or clone this repository.
2. Place it in your WordPress `/wp-content/plugins/` directory.
3. Activate **Starmus Audio Recorder** via the WordPress admin dashboard.

To embed the recorder UI, include this HTML on your page or form:

```html
<div id="audioRecorder">
    <label for="field_consent">
        <input type="checkbox" id="field_consent" name="item_meta[YOUR_CONSENT_FIELD_NUMBER]" value="1">
        I consent to the recording of this oral history.
    </label><br>

    <div class="controls">
        <button type="button" id="recordButton">Record</button>
        <button type="button" id="pauseButton" disabled>Pause</button>
        <button type="button" id="playButton" disabled>Play</button>
    </div>

    <div id="timer">00:00</div>

    <audio id="audioPlayer" controls></audio>

    <input type="file" id="field_audio_attachment" name="item_meta[YOUR_AUDIO_UPLOAD_FIELD_NUMBER]" style="display:none;" accept="audio/*">
</div>
---<!-- End of embed HTML snippet -->

---

### Made for creators. Built for culture. Inspired by the stars. 

## 🌠 Why "Starmus"?

The name **Starmus** pays tribute to the [Starmus Festival](https://www.starmus.com/), founded by astrophysicist **Dr. Garik Israelian** and **Dr. Brian May** — the legendary guitarist of Queen and a PhD in astrophysics. Starmus is where **stellar science and cosmic sound converge**, bringing together visionaries like **Professor Stephen Hawking**, **Peter Gabriel**, astronauts, Nobel laureates, and iconic musicians.

This plugin, like the festival, is a bridge between **voice and vision**, built to capture **human expression** — whether it's an oral history from a rural village or a voice memo beneath the stars.

**Starmus Audio Recorder** is a small tool with a big mission: preserving stories, song, and spirit in their purest form.


---

### 🤝 Creative and Cultural Projects Welcome

Although this software is released under a restricted proprietary license, we actively support projects that center on **creative expression**, **cultural preservation**, **oral history**, or **community storytelling**.

If you represent a nonprofit, educational institution, or grassroots effort aligned with these values — especially in underrepresented regions — please reach out. We're happy to explore **free or discounted licensing** and **collaborative support**.

📩 Contact us at [support@aiwestafrica.com](mailto:support@aiwestafrica.com).

---

## 🔮 Future Directions
- Offline saving and local encryption (PWA support)
- Metadata tagging and speaker consent logging
- Optional MP3 conversion (via FFmpeg or web worker-based transcoding)

---

## 📄 License

**LicenseRef-Starisian-Technologies-Proprietary**

This repository is governed by the [STARISIAN TECHNOLOGIES CONFIDENTIAL LICENSE](./LICENSE.md) and contains proprietary materials related to the Starisian Technologies.

Access to this repository is restricted. Use, reproduction, or distribution of any materials herein without explicit written consent from Starisian Technologies is strictly prohibited and may result in legal action.

By accessing or interacting with this repository, you acknowledge and agree to the terms outlined in:

- [LICENSE.md](./LICENSE.md) – Legal terms of access and enforcement jurisdiction  
- [TERMS.md](./TERMS.md) – Project-specific ethical and operational conditions
- ❌ **No use in surveillance, coercion, military or exploitative contexts.**
- ✅ **Legitimate governments and NGOs engaged in cultural preservation may contact us for licensing.**

> This repository is public to support creative and cultural collaboration. **If you represent a nonprofit, indigenous initiative, or youth-focused project—please
> [support@aiwestafrica.com](mailto:support@aiwestafrica.com). We’re here to help.**

---

## 🤝 Ethics Statement
Use of this software implies agreement with our ethical standards:

- **No use for surveillance, coercion, or exploitative tracking**
- **No deployment in policing, military, or intelligence contexts**
- **Governments or institutions working on preservation, education, and oral history may request exemptions with review**

For full policy details, see [ETHICS.md](ETHICS.md) or reach out.

---
![Starisian Technologies Logo](https://github.com/user-attachments/assets/9b9fb3b8-bda2-44d3-8fc3-caac7079c8cb)


## 🌐 Contact
**Starisian Technologies**  
815 E Street, Suite 12083  
San Diego, CA 92101  
📩 Email: [support@aiwestafrica.com](mailto:support@aiwestafrica.com).



