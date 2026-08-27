# Impulso Hub / WhatsApp Orchestrator — Agent Instructions

## Mission

Evolve the existing Rise CRM plugin into a first-class WhatsApp inbox with Chatwoot-level messaging and agent ergonomics **without turning it into Chatwoot or an omnichannel helpdesk**.

The current PHP/CodeIgniter backend, Evolution integration, Meta Cloud integration, campaigns, bots, contacts, groups, queues, webhook idempotency and permissions are valuable production foundations. Preserve them unless a phase specification explicitly requires a compatible extension.

## Read before changing code

1. Read `docs/inbox3/README.md`.
2. Read the documents listed there for the phase you are implementing.
3. Read `CODEX_START_HERE.md` when beginning the Inbox 3 workstream.
4. Inspect the actual implementation before editing; documentation describes intent, not a substitute for source inspection.

## Hard scope boundaries

Implement features that directly improve WhatsApp conversations, messages, media, templates, inbox organization, agent workflow and multi-agent collaboration.

Do **not** add or expand these Chatwoot product areas as part of Inbox 3:
- Captain / generative AI;
- SLA or SLA reports;
- managerial/advanced reporting;
- Help Center / portal;
- live chat;
- email, SMS, Instagram, Facebook or other omnichannel channels;
- CSAT;
- business-hours engine;
- generic Chatwoot automation framework;
- generic macros framework;
- agent-capacity/round-robin system unless a later explicit requirement asks for it.

Existing Orchestrator campaigns, deterministic bots and technical audit/logging remain supported. Do not remove them and do not redesign them unless a phase explicitly depends on a small compatibility change.

## Engineering invariants

- Keep Rise CRM + PHP + CodeIgniter architecture. Do not rewrite the plugin in Rails, Vue, React or another framework.
- Do not copy Chatwoot code wholesale. Chatwoot is a behavior/UX reference only.
- Keep Evolution and Meta Cloud behavior provider-neutral above the provider layer.
- Provider differences belong in provider capabilities/adapters, not scattered UI conditionals.
- New database migrations are additive and forward-only. V010 owns delivery/read
  timestamps, V011 owns confirmed current reaction state, V012 owns outbound
  reaction attempts, V013 owns reaction ordering/status/rollback and the
  monotonic change cursor, V014 owns durable conversation snooze state, and V015
  owns Phase 7 collaboration storage.
- V001-V015 are historical and must not be edited to introduce new production
  logic. V016 is reserved for future work.
- Preserve existing message/contact/group identity and webhook idempotency invariants.
- Preserve permission checks and secret sanitization.
- Do not expose provider credentials or raw sensitive webhook payloads to the browser.
- Maintain compatibility with existing stored conversations/messages. Prefer projection/normalization over destructive backfills.
- Every new write endpoint must be idempotent where retries or double clicks can cause duplicate external effects.
- Every provider-dependent UI action must be backed by a capability returned by the backend.
- Do not mark a feature complete when only one provider path was tested unless the phase explicitly applies to one provider only.

## Frontend direction

The existing frontend is plain JavaScript and Rise views. Keep that stack for Inbox 3. Refactor incrementally into focused modules instead of expanding `Assets/js/chatwoot.js` and `Assets/js/hub-workspace.js` indefinitely.

For future inbox, composer and message UI phases, consult the local `plugins/chatwoot` copy as a read-only behavior/UX reference before implementing. Do not import Chatwoot features or architecture outside the Orchestrator scope.

Target module boundaries are described in `docs/inbox3/TARGET_ARCHITECTURE.md`. Avoid a big-bang frontend rewrite.

## Testing rule

For every implementation phase:
1. add or update targeted automated tests for the new contract;
2. run the targeted tests while iterating;
3. before declaring the phase done, run the baseline suite that can execute without a Rise runtime;
4. run Rise/database integration tests when that environment is available;
5. document any validation that could not be performed and why.

Baseline commands from the plugin root:

```bash
php Tests/run_unit.php
php Tests/run_product_static.php
php Tests/run_inbox3_handoff.php
find . -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
node --check Assets/js/chatwoot.js
node --check Assets/js/hub-workspace.js
```

As frontend modules are added, run `node --check` on every changed/new `.js` file. Also run `git diff --check` on the final patch.

When a Rise + disposable MySQL/MariaDB environment is available, also run:

```bash
php Tests/run_migration_smoke.php
php Tests/run_service_integration.php
php Tests/run_refinement_integration.php
```

## Phase discipline

Implement one roadmap phase at a time. Do not opportunistically implement later-phase UI while changing a lower-level contract. Each phase has a gate in `docs/inbox3/IMPLEMENTATION_ROADMAP.md`; satisfy that gate before advancing.

If a requested change conflicts with a documented invariant, stop the conflicting implementation, explain the conflict in the task result, and prefer the invariant unless the user explicitly overrides it.

## Definition of done

A phase is complete only when:
- the specified behavior exists end-to-end;
- old behavior remains compatible;
- provider differences are explicit;
- errors have useful user-facing and technical behavior;
- targeted tests cover success, rejection and idempotency/error cases;
- baseline tests still pass;
- docs/contracts affected by the implementation are updated;
- no out-of-scope Chatwoot subsystem was introduced.

See `docs/inbox3/DEFINITION_OF_DONE.md` for the full release checklist.
