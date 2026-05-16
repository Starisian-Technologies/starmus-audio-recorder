# Security Policy

## Reporting a Vulnerability

Do **not** disclose vulnerabilities in public issues.

Report privately to:

- `security@starisian.com`
- legal escalation: `legal@starisian.com`

Please include:

- affected component(s)
- impact assessment
- reproducible steps
- proof-of-concept details where safe

## Disclosure Expectations

- acknowledgment target: within 5 business days
- remediation target for critical findings: as fast as operationally possible
- coordinated disclosure is expected

## Trust Boundaries

- Browser runtime (untrusted)
- WordPress REST/admin endpoints (trusted only after capability + nonce validation)
- External upload/processing services (trusted by explicit config only)
- Persistent store (WordPress DB/media + browser IndexedDB queue)

## Security-Critical Flows

- submission mutations (audio + metadata)
- consent state transitions
- annotation persistence
- upload resume and retry handling
- editor access and nonce-guarded transitions

## Baseline Security Requirements

- sanitize → validate → escape
- capability checks for privileged actions
- nonce checks for mutation requests
- defensive handling for malformed payloads
- least-privilege defaults for admin/runtime controls

## Out of Scope

- social engineering reports without technical exploit
- dependency advisories not exploitable in this repository context

## Governance

This repository may handle culturally sensitive workflows and associated governance constraints. Security incidents affecting integrity, consent, or controlled data flows are treated as high severity.
