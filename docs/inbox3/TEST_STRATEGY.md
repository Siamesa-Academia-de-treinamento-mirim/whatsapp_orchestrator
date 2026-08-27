# Inbox 3 — Test Strategy

## Testing layers

Inbox 3 needs four layers:

1. pure/unit contract tests;
2. static/product guardrail tests;
3. Rise + database service/migration integration tests;
4. browser/provider homologation.

A phase is not “tested” merely because PHP/JS syntax passes.

## Baseline standalone suite

From plugin root:

```bash
php Tests/run_unit.php
php Tests/run_product_static.php
php Tests/run_inbox3_handoff.php
find . -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
node --check Assets/js/chatwoot.js
node --check Assets/js/hub-workspace.js
```

Add `node --check` for every new/changed module.

## Rise/database suite

When available:

```bash
php Tests/run_migration_smoke.php
php Tests/run_service_integration.php
php Tests/run_refinement_integration.php
```

Use a disposable database. Do not point migration/integration tests at production.

## Test naming/direction

Prefer focused test runners/fixtures that can execute without a browser where practical, for example:

```text
Tests/Inbox3/
    message_contract_test.php
    provider_capabilities_test.php
    media_policy_test.php
    conversation_workflow_test.php
    fixtures/
```

The existing project uses custom PHP test runners rather than assuming PHPUnit. Follow the repository's lightweight conventions unless introducing a test dependency is explicitly justified.

## Phase 1 matrix

Test DTO projection for:

| Type | Incoming | Outgoing | Structured fields | Legacy compatibility |
|---|---:|---:|---:|---:|
| text | yes | yes | n/a | yes |
| image | yes | yes | attachment | yes |
| audio/voice | yes | yes | attachment/voice flag | yes |
| video | yes | yes | attachment | yes |
| document | yes | yes | attachment | yes |
| sticker | yes | where supported | attachment | safe |
| location | yes | where supported | coordinates/name/address | safe |
| contact | yes | where supported | contact fields | safe |
| template | yes/history | Meta | template object | safe |
| interactive | yes | where supported | interaction object | safe |
| reaction | event/aggregate | where supported | target+emoji | safe |
| unknown | yes | n/a | unsupported | safe |

Also test capability schema equivalence and secret redaction.

## Phase 2 matrix

For each provider/media kind test:

- accepted MIME;
- rejected MIME;
- just-under/over max size;
- empty file;
- spoofed client MIME vs detected MIME;
- caption allowed/blocked;
- idempotent duplicate client ID;
- multiple file deterministic ordering;
- partial failure;
- recording conversion target validation.

## Phase 3 composer behavior

Where browser automation is unavailable, isolate state transformations into testable JS functions and at minimum syntax-check modules. In Rise/browser homologation test:

- conversation switch with drafts;
- note/reply switch;
- paste screenshot;
- drag 2+ files;
- remove one file;
- reply then cancel reply;
- failed send preserves draft;
- microphone denied/cancelled/recorded;
- keyboard send/newline.

## Phase 4 renderer/security matrix

For every renderer use fixtures containing:

- empty optional fields;
- long text/file names;
- quotes/HTML tags/script-like text;
- invalid/unsafe URL schemes;
- missing media;
- unresolved reply target;
- status transitions out of order.

No renderer may inject unsanitized message HTML.

## Phase 5 Meta template/window matrix

- approved vs rejected/disabled template;
- language variants;
- body/header/button variables;
- missing parameter;
- service window open;
- service window closed;
- window expires between compose and send;
- template provider error;
- rich history after successful send.

## Phase 6 workflow/filter matrix

- permissions per action;
- assign valid/deleted/non-staff;
- unassign/self-assign;
- each priority value + old `normal` mapping;
- open/pending/resolved/snoozed transitions;
- mark unread/read;
- filter pagination consistency;
- combined filters;
- contact previous-conversation query isolation.

## Phase 7 collaboration matrix

- mention existing/unauthorized/deleted staff;
- notification dedupe;
- presence heartbeat/expiry;
- two-agent collision state;
- presence transport failure does not block send;
- saved view ownership;
- invalid saved filter schema;
- bulk action partial failures.

## Manual real-provider release matrix

Before a production Inbox 3 release, use authorized test numbers/accounts and verify at least:

### Evolution

- inbound/outbound text;
- image/audio/video/document;
- group participants;
- reply if implemented/supported;
- reactions if implemented/supported;
- status receipts supported by installed Evolution version;
- media retrieval.

### Meta Cloud

- inbound/outbound text inside window;
- window closed behavior;
- approved template;
- image/audio/video/document under current official limits;
- reply/action paths actually supported by current Graph API version;
- delivered/read receipts;
- webhook signature/duplicate delivery.

## Reporting rule

Every Codex completion message lists exact commands run and results. “Tests passed” without command/result detail is insufficient.
