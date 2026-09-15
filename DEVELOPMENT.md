# Development Guide

## Local toolchain

| Tool | Version | Why |
| --- | --- | --- |
| Node.js | 20 LTS | Platform standard |
| pnpm | 10.29.2 | `pnpm-lock.yaml` is the lockfile |
| TypeScript | 5.x, `strict` | Platform standard |
| Praat | as pinned in `tools.pinned.json` | The analysis worker |
| ffmpeg / ffprobe | as pinned in `tools.pinned.json` | Decode, probe, derivatives |

There is no PHP, no Composer, and no Docker/WordPress environment. Those belonged
to the first-generation plugin and were removed in the ADR-038 restructure.

On Ubuntu 24.04, which is what CI runs:

```sh
sudo apt-get update
sudo apt-get install -y --no-install-recommends praat ffmpeg
pnpm install
```

## Commands

```sh
pnpm run validate      # boundary checks (scripts/validate-node-boundary.cjs)
pnpm run typecheck     # tsc --noEmit
pnpm run lint          # eslint + markdownlint
pnpm run build         # validate, then tsc to dist/
pnpm run verify:tools  # every pinned tool: version probe + licence attestation
pnpm test              # build, then node --test over dist/
```

`pnpm run validate` runs automatically before `build`.

## About the pinned toolchain

`tools.pinned.json` records, per tool: the version this deployment is pinned to,
how to ask the tool its version, and the licence as read from that tool's own
distribution with the path it was read from.

`pnpm run verify:tools` runs every tool and refuses any whose reported version is
not the pinned one. **That failing on your machine is expected and correct** if
your distribution ships different builds. The fix is to re-read the licences from
your own distribution and re-record the pins for that deployment — not to loosen
the check. A measurement carries the version that produced it, so a pin that does
not match reality makes every result unverifiable.

## About the tests

`src/domain/*.test.ts` are pure: no filesystem, no processes, a tenth of a second
for the whole file. They cover the invariants — what may be evidence, what may
feed analysis, when a manifest is deterministic, when a measurement class is
reliable.

`src/analysis/analysis.test.ts` is an integration test against real Praat and
real ffmpeg. Fixtures are synthesised with ffmpeg so expected values are known
rather than golden: a 220 Hz tone measures 220 Hz, silence has no pitch, and a
tone/silence/tone file segments into three intervals. The TextGrid test writes an
export and has Praat read it back, because a TextGrid only this repository can
parse is not TextGrid-compatible.

Mocking Praat would prove the parser matches the mock. It would not tell you the
scripts work.

## Adding a measurement class

1. Add it to `MEASUREMENT_CLASSES` in `src/domain/measurement.ts`.
2. Add its reliability precondition in the same file. The type forces this: a
   class without one would report through conditions in which it is meaningless.
3. If it needs a new Praat computation, add it to `praat/measure.praat` and echo
   its parameters back with the others.
4. Map the reported key to the class in `MEASUREMENT_SPECS` in
   `src/analysis/praatWorker.ts`.
5. Add a test against a fixture whose expected value you know.

Where the class has a published reliability floor that this platform's material
has not been ruled against, leave `requiresSnrDb` null and set
`unruledFloorNote`. It will be reported and marked unreliable, which is what
ADR-038 asks for — not withheld, and not silently presented as sound.

## Adding a pinned tool

1. Install it, run it, and read the version it reports.
2. Read its licence from its own distribution — the copyright file in the package,
   not a project page or memory.
3. Record both in `tools.pinned.json`, with the path you read the licence from.
4. Add it to the `jobClassPins` of the classes allowed to use it.
5. Spawn it. Never link it.

`pnpm run validate` fails if a tool arrives without a version or an attestation.
