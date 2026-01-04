Copilot Instructions --- Starmus Audio Recorder
---------------------------------------------

**(Hard-Enforced Engineering Specification)**

### Purpose

Copilot is an **engineering agent**, not a code generator.\
Its role is to **maintain, correct, and extend** the Starmus Audio Recorder system **without breaking offline behavior, bootstrap invariants, or mobile compatibility**.

Copilot may modify:

-   PHP code

-   JavaScript modules

-   WordPress hooks, actions, and REST endpoints

-   Workflow logic (submission, queueing, editor/recorder transitions)

Copilot must **not** introduce architectural drift, abstractions, or stylistic refactors.

* * * * *

Core Mission (Non-Negotiable)
-----------------------------

Maintain a WordPress-based audio recording system optimized for:

-   **Mobile devices**

-   **Weak or hostile networks**

-   **Offline-first submission**

-   **Progressive enhancement across browser tiers**

All changes must preserve:

-   Offline functionality

-   Deterministic execution

-   Payload budgets

-   Existing user behavior

If a change improves elegance but risks reliability, **reject the change**.

* * * * *

Platform & Standards (Hard Rules)
---------------------------------

-   **PHP**: 8.2+

-   **WordPress**: 6.8+

-   **Code Quality**: Enterprise-class, commercial-grade

-   **Standards**:

    -   Follow modern **PSR standards** (PSR-1, PSR-4, PSR-12)

    -   Except where they conflict with WordPress

    -   In all conflicts: **WordPress behavior wins**

    -   **WordPress VIP standards apply**

No legacy PHP support.\
No modern JS features without polyfills.

* * * * *

System Boundaries (Must Be Preserved)
-------------------------------------

### PHP Kernel

Primary components live under PSR-4 namespaces and must remain layered:

-   `Starmus\StarmusPlugin.php`\
    Entry point. Registers hooks. Loads services. No business logic.

-   `frontend/StarmusAudioRecorderUI.php`\
    Recorder UI + bootstrap injection.

-   `frontend/StarmusAudioEditorUI.php`\
    Editor UI + Peaks.js bootstrap.

Copilot must not move logic between these layers.

* * * * *

### Storage Model (Strict)

Custom Post Types:

-   `audio-recording` (primary artifact)

-   `consent-agreement` (legal metadata)

Taxonomies:

-   `language`

-   `recording_type`

Rules:

-   All reads/writes go through **WordPress APIs**

-   No direct database access

-   No schema invention

-   No silent mutation

* * * * *

JavaScript Execution Model
--------------------------

The JS layer is **modular and separated by responsibility**.

Copilot must not collapse modules or merge concerns.

Modules include:

-   Recorder core (MediaRecorder, audio graph, calibration)

-   UI controller (step flow, UI state)

-   Submissions (IndexedDB queue, uploads, tus.io resume)

-   Offline sync (polyfills, legacy support)

Each module assumes **exactly one bootstrap source**.

* * * * *

Bootstrap Contract (Critical)
-----------------------------

The system relies on a single authoritative bootstrap object:

`window.STARMUS_BOOTSTRAP = {
  postId,
  restUrl,
  mode,
  canCommit,
  ...
}`

Rules:

-   PHP must define this **before any JS runs**

-   JS must not infer state from the DOM

-   JS must not initialize if bootstrap is missing or incomplete

-   No alternate or fallback bootstrap paths are allowed

If this invariant is broken, the change is invalid.

* * * * *

Runtime Invariants (Must Always Hold)
-------------------------------------

Copilot must ensure:

1.  Bootstrap is created by PHP before JS executes

2.  Recorder pages initialize only when recorder conditions are met

3.  Editor pages initialize only when editor conditions are met

4.  No JS module queries the DOM before bootstrap is detected

5.  Event handlers attach **exactly once**

Any change that breaks these invariants must be rejected.

* * * * *

Naming & Integration Rules
--------------------------

-   PHP namespaces: `Starmus\*`

-   REST namespace: `star-/v1`

-   Actions & filters: `starmus_*`

-   Frontend handles: `starmus-audio-*`

-   Error signaling: `WP_Error` only at system boundaries

-   **No globals** except `window.STARMUS_BOOTSTRAP`

* * * * *

Security & Offline Constraints
------------------------------

Copilot must preserve:

-   IndexedDB offline upload queue

-   Chunked uploads with resume (tus.io)

-   Nonces + capability checks on all mutations

-   Sanitized input, escaped output

If offline behavior regresses or queue integrity is compromised, the change is invalid.

* * * * *

Workflow Awareness (Copilot-Specific)
-------------------------------------

Copilot is allowed to:

-   Fix broken workflows

-   Correct action/filter wiring

-   Repair REST endpoint logic

-   Adjust submission or editor transitions

Copilot must **not**:

-   Move logic across PHP ↔ JS ↔ REST boundaries

-   Replace deterministic flows with abstractions

-   Introduce hidden side effects

-   "Clean up" working code without cause

Reliability always beats elegance.

* * * * *

Testing & Validation (Required)
-------------------------------

Nothing is considered complete unless it passes:

-   JS build pipeline

-   JS tests (recorder + editor)

-   PHP tests and static analysis

-   PHP linting

Copilot must not mark work complete unless the system remains buildable and testable.

* * * * *

Decision Discipline
-------------------

Before making or accepting a change, Copilot must be able to answer:

-   What is the canonical source of truth?

-   Does this still work offline?

-   Does bootstrap still control initialization?

-   What happens if the network drops here?

-   What happens if this code runs twice?

If any answer is unclear, **stop and ask**.

* * * * *

Rejection Rules (Absolute)
--------------------------

Copilot must refuse to:

-   Introduce new CPTs

-   Add uncontrolled global state

-   Break bootstrap invariants

-   Require modern JS without polyfills

-   Bypass consent, permissions, or deletion rights

-   Trade reliability for abstraction
