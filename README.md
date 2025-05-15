![Starmus Audio Recorder](https://github.com/user-attachments/assets/c51b26bb-f95f-4d8c-9340-dacdacca5d4f)

# **✨ Starmus Audio Recorder (WordPress Plugin)**

The **Starmus Audio Recorder** is a minimalist front-end WordPress plugin for capturing oral histories, vocals, or field recordings using the browser’s built-in MediaRecorder API.

Named after the iconic **Starmus Festival**, where rock legends and astrophysicists share the stage, this tool is built for creators and communities who believe **voice has gravity**.

---

## **🔥 Features**

* 🎤 **Native, in-browser recording** using the MediaRecorder API (no external libraries)

* 🛡️ **Consent-first UX** — checkbox, timer, and playback before upload

* 📤 **MP4/AAC & WebM/Opus output** automatically attached to form

* 📱 **Mobile-friendly, PWA-capable**, no 3rd-party dependencies

* 🧠 **Clean JS** codebase ready for extensions (transcription, waveform, MP3 export)

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

\<div id="audioRecorder"\>  
  \<label for="field\_consent"\>  
    \<input type="checkbox" id="field\_consent" name="item\_meta\[YOUR\_CONSENT\_FIELD\_NUMBER\]" value="1"\>  
    I consent to the recording of this oral history.  
  \</label\>\<br\>

  \<div class="controls"\>  
    \<button type="button" id="recordButton"\>Record\</button\>  
    \<button type="button" id="pauseButton" disabled\>Pause\</button\>  
    \<button type="button" id="playButton" disabled\>Play\</button\>  
  \</div\>

  \<div id="timer"\>00:00\</div\>  
  \<audio id="audioPlayer" controls\>\</audio\>

  \<input type="file" id="field\_audio\_attachment" name="item\_meta\[YOUR\_AUDIO\_UPLOAD\_FIELD\_NUMBER\]" style="display:none;" accept="audio/\*"\>  
  \<input type="hidden" name="audio\_uuid"\>  
\</div\>

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

