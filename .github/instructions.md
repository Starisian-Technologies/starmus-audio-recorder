Coding Context --- Starmus Audio
=========================================

What We Are Building
--------------------

This project delivers **offline-first audio recording, oral history, and language documentation tools** for **West Africa**, beginning in **The Gambia**. It is designed to preserve cultural knowledge, support education, and enable local participation in digital systems **without assuming stable internet, modern devices, or high digital literacy**.

The system must work reliably for people recording stories, language, music, and lived experience in real-world conditions.

* * * * *

Operating Environment
---------------------

Assume the following **at all times**:

-   **Intermittent or unreliable internet**

-   **Low-bandwidth connections** and expensive data plans

-   **Older Android phones**, Android WebView, and legacy browsers

-   Limited device storage and memory

-   Users may lose connection, close the browser, or reload mid-task

The system must **fail safely**, **resume gracefully**, and **never lose user work**.

* * * * *

Core Design Principles
----------------------

-   **Mobile-first**: primary screen widths between **360--480px**

-   **Offline-first**: local save before any upload

-   **Progressive enhancement**: advanced features only when supported

-   **Resilience over elegance**: reliability beats cleverness

-   **Small payloads**: every byte matters

If a feature looks good but risks failure under weak conditions, it is the wrong choice.

* * * * *

JavaScript & Frontend Expectations
----------------------------------

-   Favor **older-browser compatibility**

    -   Android WebView

    -   Older Chrome / Firefox

    -   Opera Mini--style constraints

-   Avoid modern JS features unless safely transpiled or polyfilled

-   Avoid large frameworks and heavy abstractions

-   JavaScript should **degrade gracefully**

    -   Core tasks should not catastrophically fail if JS partially breaks

-   Prefer **local-first workflows**

    -   Save recordings locally

    -   Queue uploads

    -   Resume automatically when online

* * * * *

Offline & Data Safety
---------------------

-   Recording and form submission must work **without internet**

-   Audio and metadata must be saved locally before upload

-   Uploads must support **retry and resume**

-   Users must never be punished for losing connectivity

-   Sync should be silent and automatic when possible

User trust depends on **not losing work**.

* * * * *

UI & Accessibility
------------------

Design for users who may be:

-   New to smartphones

-   New to web apps

-   Working outdoors in bright light

-   Using touch-only interaction

Requirements:

-   High contrast

-   Clear, readable fonts

-   Large tap targets

-   No hover-only interactions

-   Minimal animation

-   Clear feedback at every step

* * * * *

Language & Text Handling
------------------------

-   Default encoding: **UTF-8**

-   Expect multilingual content:

    -   Mandinka

    -   Wolof

    -   French

    -   English

    -   Arabic (potential RTL fallback)

-   Do not assume English-only workflows

-   Text must handle accents, diacritics, and non-Latin scripts safely

* * * * *

Server & Network Assumptions
----------------------------

-   Server responses may be slow

-   Requests may fail unexpectedly

-   All async actions must:

    -   Time out safely

    -   Retry when appropriate

    -   Communicate clearly to the user

Error messages must be:

-   Human

-   Calm

-   Reassuring

-   Offline-aware\
    Example: *"You appear to be offline. Don't worry --- your work has been saved."*

* * * * *

Dependencies & Libraries
------------------------

When external libraries are necessary:

-   Prefer **pure JavaScript**

-   Prefer **small footprint** (<50 KB minified where possible)

-   Prefer **permissive licenses** (MIT, BSD)

-   Avoid dependencies that assume CDNs or constant connectivity

Critical assets must be bundled locally.

* * * * *

Deployment Reality
------------------

-   Output should be **PWA-ready**

-   Do not assume:

    -   Build pipelines

    -   CDNs

    -   Modern hosting features

-   Scripts should be able to run directly from `/assets/` if required

The system must be deployable in constrained environments.

* * * * *

Audience
--------

Primary users include:

-   Students (ages 12--18)

-   Teachers

-   Community elders

-   Local artists, vendors, and creatives

Many users will have **limited digital literacy**.\
The interface must be **obvious, forgiving, and confidence-building**.

* * * * *

Guiding Question
----------------

When making any change, ask:

> *Would this still work for a student on an old Android phone, offline, in a village with weak signal?*

If the answer is unclear, rethink the change.
