# Codex - Start Here for Inbox 3

This file is the execution entry point for the WhatsApp Orchestrator / Impulso Hub Inbox 3 workstream.

## First instruction

Read, in this order, before changing code:

1. `AGENTS.md`
2. `docs/inbox3/README.md`
3. `docs/inbox3/SCOPE_AND_GUARDRAILS.md`
4. `docs/inbox3/CURRENT_STATE_AUDIT.md`
5. `docs/inbox3/TARGET_ARCHITECTURE.md`
6. `docs/inbox3/MESSAGE_CONTRACT_V2.md`
7. `docs/inbox3/CONVERSATION_CONTRACT_V2.md`
8. `docs/inbox3/PROVIDER_CAPABILITIES_V2.md`
9. `docs/inbox3/MEDIA_ENGINE_V2.md`
10. `docs/inbox3/COMPOSER_V2.md`
11. `docs/inbox3/API_SURFACE_PLAN.md`
12. `docs/inbox3/REFERENCE_CHATWOOT.md`
13. `docs/inbox3/IMPLEMENTATION_ROADMAP.md`
14. `docs/inbox3/TEST_STRATEGY.md`
15. `docs/inbox3/DEFINITION_OF_DONE.md`

Then inspect the actual source files named by those documents and the local read-only `plugins/chatwoot` reference when the phase requires it.

## Phase status

- Phase 1 complete
- Phase 2 complete
- Phase 3 complete
- Phase 4 complete
- Phase 5 complete
- Phase 6 complete
- Phase 7 complete
- Phase 8 complete

## Inbox 3 roadmap status = COMPLETE

All phases in the Inbox 3 roadmap are complete. No post-roadmap phase is authorized.

### Phase 8 constraints

- Preserve Conversation Contract V2, Message Contract V2, provider capabilities, media policy, composer state, template sessions, workflow sequencing, detached detail and collaboration semantics.
- Chatwoot is a visual/behavior reference only; do not copy its framework, store or design system.
- Preserve existing response keys and APIs. No new migration is expected for visual/frontend work.
- Historical migrations V001-V015 remain immutable. V015 is the last migration and V016 is reserved.
- Remove a legacy frontend path only after its callers, compatibility documentation and replacement tests prove equivalence.
- Release-only environment limitations must be recorded explicitly; they are not silently treated as passes.

### Final release completion response

Report the visual audit matrix, studied Chatwoot components, files changed, migrations, polish/accessibility/responsive decisions, dead paths removed, performance/lifecycle checks, permission matrix, exact automated results, provider/browser/manual limitations and confirmation that no post-roadmap feature was added.
