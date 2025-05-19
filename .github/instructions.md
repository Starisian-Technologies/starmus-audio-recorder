# Copilot Coding Context Instructions

## Project Context

This project is being developed for **mobile-first deployment in The Gambia, West Africa**, where internet access is often **intermittent**, **bandwidth is limited**, and devices may be **low-powered Android phones** or **older browsers**.

## Core Requirements

- **Prioritize compatibility** with older browsers (Android WebView, Opera Mini, older versions of Chrome/Firefox).
- **Avoid dependencies** on modern JS frameworks unless polyfilled.
- **Keep bundle sizes small**. Every byte counts on low-data plans.
- All **JavaScript must degrade gracefully**. Core functionality must **still work without JavaScript** where possible.
- **Use service workers and local storage/cache APIs** to allow the app to run offline.
- Where recording or upload is involved, **save locally first**, allow retry/resume when internet returns.
- **Design UI for small screens** (mobile-first, 360px–480px wide).

## Accessibility and UX Notes

- Use **clear, legible fonts** and **high contrast** for visibility outdoors.
- UI elements must work with **tap-only interaction** (no hover states).
- Avoid unnecessary animations or heavy resource usage.

## Language & Encoding

- Default charset should be **UTF-8**.
- Expect multilingual text, including **Mandinka**, **Wolof**, **French**, and **Arabic**.
- Support **RTL fallback** if needed for Arabic script.

## Server Constraints

- Assume **slow server responses**; all async logic must have timeouts and retry handling.
- Error messages must be **friendly** and **offline-aware** (“You appear to be offline. Don’t worry, we saved your work.”).

## Development Guidelines

- Minimize reliance on modern JS features (e.g., avoid top-level `await`, ES2020+ features unless transpiled).
- When using external libraries, prefer ones that are:
  - Pure JS (no server dependency)
  - <50kb minified
  - MIT or permissive license

## Specific Fallback Features

- Offline-friendly form submissions (queue until back online)
- Audio recording must work without requiring immediate upload
- On reconnect, app should retry or sync silently

## Deployment Considerations

- Output should be **PWA-ready**
- No build step assumptions; scripts should work raw from `/assets/` if needed
- Don’t assume CDN availability—**bundle critical assets locally**

## Audience

The target users include:

- Students (ages 12–18)
- Teachers
- Community elders
- Local vendors and creatives

They may have **limited digital literacy**, so UI must be extremely intuitive.
