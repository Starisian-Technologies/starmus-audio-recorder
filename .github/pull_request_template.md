## Summary

<!-- Describe what changed and why. -->

## Scope

- [ ] Docs-only
- [ ] Domain rules (`src/domain/`, `src/preservation/`)
- [ ] Analysis worker or pinned toolchain
- [ ] Ingest, access, or release pipeline
- [ ] Ports or seam contracts
- [ ] CI/Workflows

## Validation

<!-- Include commands and outcomes. -->

- [ ] `pnpm run validate` (boundary checks)
- [ ] `pnpm run typecheck`
- [ ] `pnpm run lint`
- [ ] `pnpm run verify:tools`
- [ ] `pnpm test`

## Security and Governance Check

- [ ] No registered original is mutated or deleted (ADR-039)
- [ ] No processing route discards material (ADR-011)
- [ ] Every stored measurement keeps its parameters and tool version (ADR-038)
- [ ] No durable storage URL in a record, event or evidence field (ADR-038)
- [ ] No numeric capture-profile floor added (OQ-021 is not ours)
- [ ] Any new pinned tool carries a licence read from its own distribution
- [ ] No port given a default implementation

## Risks and Rollback

<!-- Note user-facing risk and rollback approach. -->

## Documentation Updates

- [ ] README
- [ ] ARCHITECTURE
- [ ] DEVELOPMENT
- [ ] SECURITY
- [ ] CHANGELOG
