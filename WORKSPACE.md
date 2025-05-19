# Starmus Audio Recorder: Workspace Setup

## Project Overview
This project is a mobile-first, offline-friendly audio recording and submission tool designed for low-bandwidth, low-power devices in The Gambia, West Africa. It prioritizes compatibility, accessibility, and resilience to poor connectivity.

## Key Workspace Guidelines
- **No build step required:** All scripts and styles should work directly from `/assets/`.
- **No CDN dependencies:** Bundle all critical assets locally.
- **Browser compatibility:** Support Android WebView, Opera Mini, and older Chrome/Firefox.
- **Bundle size:** Keep all JS/CSS as small as possible; avoid large libraries.
- **Degrade gracefully:** Core features must work even if JavaScript is disabled.
- **Offline-first:** Use service workers and local storage for offline operation and queued submissions.
- **PWA-ready:** Ensure manifest and service worker are present for installability.

## Audience
- Students (ages 12–18)
- Teachers
- Community elders
- Local vendors and creatives

**Note:** Users may have limited digital literacy. UI must be extremely intuitive, with clear, high-contrast visuals and tap-only controls.

## Development Checklist
- [ ] All JS/CSS assets are local and <50kb where possible
- [ ] No modern JS features without transpilation/polyfill
- [ ] All async logic has timeouts and retry handling
- [ ] Error messages are friendly and offline-aware
- [ ] Audio recording works without immediate upload
- [ ] Form submissions are queued and retried on reconnect
- [ ] UI is mobile-first, accessible, and RTL-friendly
- [ ] Project is PWA-ready (manifest, service worker, offline fallback)

---

For more, see `.github/instructions.md`.
