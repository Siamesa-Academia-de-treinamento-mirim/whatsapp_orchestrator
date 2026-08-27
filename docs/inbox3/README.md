# Inbox 3 Engineering Index

Inbox 3 is the modernization program for the Impulso Hub / WhatsApp Orchestrator inbox. The goal is Chatwoot-level conversation and messaging ergonomics while retaining a WhatsApp-specialized Rise CRM plugin.

## Canonical documents

| Document | Purpose | Read when |
|---|---|---|
| [SCOPE_AND_GUARDRAILS.md](SCOPE_AND_GUARDRAILS.md) | Product boundary and non-goals | Every phase |
| [CURRENT_STATE_AUDIT.md](CURRENT_STATE_AUDIT.md) | Source-backed snapshot of what exists and what is missing | Before planning a change |
| [TARGET_ARCHITECTURE.md](TARGET_ARCHITECTURE.md) | Module boundaries and dependency direction | Backend/frontend refactors |
| [MESSAGE_CONTRACT_V2.md](MESSAGE_CONTRACT_V2.md) | Canonical frontend message DTO | Message, webhook, renderer work |
| [CONVERSATION_CONTRACT_V2.md](CONVERSATION_CONTRACT_V2.md) | Canonical inbox conversation DTO | Conversation/list/sidebar work |
| [PROVIDER_CAPABILITIES_V2.md](PROVIDER_CAPABILITIES_V2.md) | Capability and media-policy contract | Provider/UI/media work |
| [MEDIA_ENGINE_V2.md](MEDIA_ENGINE_V2.md) | Attachment, recording and provider-safe media pipeline | Phase 2 |
| [COMPOSER_V2.md](COMPOSER_V2.md) | Reply/note composer behavior | Phase 3 |
| [MESSAGE_UI_AND_ACTIONS.md](MESSAGE_UI_AND_ACTIONS.md) | Renderers, context actions, reactions/status | Phase 4 |
| [TEMPLATES_AND_SERVICE_WINDOW.md](TEMPLATES_AND_SERVICE_WINDOW.md) | Meta template picker + 24h behavior | Phase 5 |
| [INBOX_WORKFLOW_V2.md](INBOX_WORKFLOW_V2.md) | Conversation cards, assignment, priority, status, filters/sidebar | Phase 6 |
| [COLLABORATION_AND_PRODUCTIVITY.md](COLLABORATION_AND_PRODUCTIVITY.md) | drafts, mentions, presence, collision, saved views, bulk | Phase 7 |
| [DATABASE_MIGRATION_PLAN.md](DATABASE_MIGRATION_PLAN.md) | Additive schema strategy | Any schema change |
| [API_SURFACE_PLAN.md](API_SURFACE_PLAN.md) | Compatible route/action evolution | Endpoint work |
| [REFERENCE_CHATWOOT.md](REFERENCE_CHATWOOT.md) | Audited Chatwoot behaviors to emulate, not copy | UX parity decisions |
| [IMPLEMENTATION_ROADMAP.md](IMPLEMENTATION_ROADMAP.md) | Sequenced implementation phases and gates | Every Codex task |
| [TEST_STRATEGY.md](TEST_STRATEGY.md) | Automated/manual verification matrix | Every phase |
| [DEFINITION_OF_DONE.md](DEFINITION_OF_DONE.md) | Phase/release completion checklist | Before declaring done |

## Source of truth precedence

When documents disagree:

1. explicit current user instruction;
2. `AGENTS.md` hard invariants;
3. phase contract documents above;
4. current source behavior, when retained for compatibility;
5. legacy root planning documents such as `PROMPT_CODEX_REFINAMENTO_TOTAL.md`.

Legacy documents may describe earlier goals. Do not use them to broaden Inbox 3 scope.

## Work pattern

Each Codex implementation task should:

1. identify the roadmap phase;
2. read only the relevant detailed documents plus global guardrails;
3. inspect current source;
4. write/adjust targeted tests;
5. implement the narrow end-to-end slice;
6. run phase tests and baseline regression;
7. update the contract docs only when the implementation intentionally changes a documented decision;
8. stop at the phase gate.
