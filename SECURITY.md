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

- Uploaded bytes (untrusted until integrity is verified; kept either way — the
  material is never discarded, only flagged and quarantined)
- Spawned analysis tools (own processes, own licences, pinned versions; their
  output is parsed, never evaluated)
- The media ingest service (performs transport and issues temporary URLs under
  this service's authorization; trusted by explicit config only)
- Consumers requesting access (authorized per request by the access policy,
  which fails closed when none is configured)

## Security-Critical Flows

- Ingest acceptance and integrity verification
- Access authorization and short-lived URL issuance — including the rule that no
  durable storage URL is stored or emitted anywhere
- The pinned-tool gate: version probe and licence attestation before any tool runs
- Release rendering, whose outputs must never reach the linguistic pipeline
- Preservation guards against mutation or deletion of a registered original

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
