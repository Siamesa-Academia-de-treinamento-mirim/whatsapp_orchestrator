# Inbox 3 — Definition of Done

Use this checklist before marking a phase or release complete.

## Contract

- [ ] New API/DTO shape is documented.
- [ ] Legacy consumers remain compatible or an intentional migration is complete.
- [ ] Provider-specific behavior is expressed in capabilities/adapters.
- [ ] Unknown inputs fail safely.

## Backend

- [ ] Authorization is enforced server-side.
- [ ] Input validation exists server-side.
- [ ] External-write retries/double clicks are idempotent where needed.
- [ ] Provider errors are sanitized for user/API output.
- [ ] Logs do not leak credentials.
- [ ] Existing webhook/contact/group identity invariants remain intact.

## Database

- [ ] New schema uses a new additive migration (V010+) if needed.
- [ ] Existing migrations were not rewritten for the feature.
- [ ] New query patterns have justified indexes.
- [ ] Historical data remains readable.
- [ ] Migration smoke test runs in a disposable DB when environment is available.

## Frontend

- [ ] UI is capability-driven rather than provider-name-driven for common behavior.
- [ ] Loading, empty, disabled and error states are deliberate.
- [ ] User input is not lost on recoverable failure.
- [ ] Message/user content is escaped/sanitized.
- [ ] Keyboard/focus behavior remains usable.
- [ ] New code is placed in cohesive modules when the owning phase touches monolithic files.

## Tests

- [ ] Targeted success cases.
- [ ] Targeted rejection/error cases.
- [ ] Idempotency/race cases where relevant.
- [ ] Existing unit suite green.
- [ ] Existing product static suite green.
- [ ] Handoff integrity test green.
- [ ] PHP syntax lint green.
- [ ] Changed/new JavaScript syntax checks green.
- [ ] Rise/database integration suite green when environment exists, otherwise explicitly recorded as not run.
- [ ] Real provider/manual checks completed when phase/release requires them, otherwise explicitly recorded as pending.

## Scope

- [ ] No Captain/generative AI expansion.
- [ ] No SLA/reporting/help-center/omnichannel expansion.
- [ ] Campaigns/bots were not needlessly refactored.
- [ ] No framework rewrite was introduced for parity.

## Handoff

- [ ] Files changed are listed.
- [ ] Tests and results are listed.
- [ ] Migrations are listed.
- [ ] Known risks/unknowns are listed.
- [ ] Next phase dependencies are clear.
