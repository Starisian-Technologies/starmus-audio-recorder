# Spoken Audio Node

The platform audio asset lifecycle for SPARXSTAR / AIWA, as a Node/TypeScript
service.

It accepts recordings, keeps them exactly as they arrived, prepares them for
analysis without changing them, measures them with a pinned Praat worker, finds
their speech and silence boundaries, and hands out short-lived access to
whoever is authorized to hear them.

Role and boundary: [`.github/instructions/starmus-boundary.md`](.github/instructions/starmus-boundary.md).
How to work in it: [`AGENTS.md`](AGENTS.md).

## What it is not

- **Not a recorder.** The microphone, the offline queue and the chunked-upload
  client belong to the capture UI package.
- **Not a transcription service.** Transcript, translation and linguistic
  interpretation are ESU's records. This service never declares linguistic
  truth.
- **Not an editor.** There is no trim, splice, cut or "effective audio" anywhere
  in the preservation path. Dead air is handled by marking it, not by removing
  it.
- **Not a file server.** It authorizes access; the media ingest service performs
  transport and issues the temporary URL.
- **No longer a WordPress plugin.** That was the first generation and it is
  superseded.

## The shape of an asset

Every recording becomes one asset with a ladder of renditions:

| Rung | What it is | Evidence? | Feeds analysis? |
| --- | --- | --- | --- |
| `original` | Exactly what arrived. Immutable, and the timeline authority. | yes | yes |
| `analysis-standard` | Deterministic, timeline-preserving preparation, with a manifest | yes | yes |
| `analysis-enhanced` | Denoised. For comparison only. | no | no |
| `playback` | Low-bandwidth listening, including waveform data | no | no |
| `publication` | Release rendering — a separate, walled-off job class | no | no |

There is one timeline: the original's. Every measurement, segment and annotation
maps to it, and no mapping contract to a second timeline exists or is needed.

## The analysis worker

Praat runs as a separate process, never linked, at a version pinned per job
class, with its licence read from its own distribution and recorded in
[`tools.pinned.json`](tools.pinned.json) before deployment.

Every result it produces carries the Praat version, the script, the script's
hash, the parameters the script echoed back, the hash of the bytes measured, and
original-timeline coordinates. A value Praat could not compute comes back as
`null` — never as a zero.

Measurement classes whose published reliability floors this platform's material
has not been ruled against — jitter, shimmer, harmonicity — are reported and
marked unreliable rather than quietly presented as sound.

## Getting started

Requires Node 20, pnpm, and the pinned analysis tools:

```sh
sudo apt-get install -y praat ffmpeg   # Ubuntu 24.04, as CI does
pnpm install
pnpm run verify:tools                  # versions and licences must match the pins
pnpm test
```

`verify:tools` failing is the expected outcome on a machine whose tools differ
from the pins. Re-record the pins for that deployment — do not loosen the check.

## What is deliberately missing

- **Numeric floors for capture profiles.** OQ-021, owned by AIWA and the
  acoustic-analysis owner. `src/domain/captureProfile.ts` carries the machinery
  and no values; an unruled profile reports "not gated", which is not a pass.
- **A wire schema for the intake seam.** OQ-022 has not settled which repository
  is its single home, so `IntakeEvent` is a domain shape and nothing maps it to a
  wire format.
- **Default implementations of any port.** The capture→ingestion contract records
  four terms as still owed, and states that no repository implements a guess at
  them. A default would be that guess, shipped.

## Licence

BUSL 1.1 — see [`LICENSE.md`](LICENSE.md). The analysis tools are separate
processes under their own licences, recorded in `tools.pinned.json`.
