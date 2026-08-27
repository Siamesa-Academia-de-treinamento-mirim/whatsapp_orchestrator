# Inbox Workflow V2

## Conversation states

Canonical inbox workflow:

```text
open
pending
resolved
snoozed
```

`snoozed` may be represented as a status plus `snoozed_until` or as a dedicated state model; choose the simplest additive schema that supports reliable wake-up/query behavior.

Current `open/pending/resolved` backend behavior should be reused.

## Read state

Support explicit:

- mark read;
- mark unread.

Current unread counter remains compatible. Mark unread should have deterministic semantics (for example at least one unread marker) rather than inventing a fake incoming message.

## Priority

Target values:

```text
none
low
medium
high
urgent
```

Current data uses `normal` plus low/high/urgent. Define a compatibility mapping instead of silently corrupting historical values. Recommended transition:

```text
normal -> none or medium
```

The chosen semantic must be documented and tested before migration/UI rollout.

## Assignment

Expose full valid staff assignment:

- unassigned;
- assign to me;
- assign to selected staff member.

Backend already validates staff IDs. Add a safe staff picker endpoint/query if current page bootstrap does not provide enough data.

Team assignment should only become active UX if there is a real operational team model in Rise/Orchestrator. Do not display decorative team controls that cannot be maintained.

## Team / queue assignment

Team assignment is part of Inbox 3 because it directly affects queue ownership. The current snapshot has `team_id` storage but not a complete operational team workflow.

Phase 6 must first inspect the host Rise domain for a suitable staff team/department source. If one exists and is stable, integrate with it. If not, create the smallest Orchestrator-owned team + membership model required for **manual** assignment.

Requirements:

- conversation can be unassigned from a team or assigned to one valid team;
- picker lists only authorized/active teams;
- assignee and team can coexist;
- in the current Rise host, `team` is an operational queue grouping and its
  membership CSV is not a hard assignee constraint; staff and team validity
  are checked independently, with no implicit reassignment;
- no automatic capacity/round-robin routing is introduced;
- the UI must not show a fake hard-coded `Atendimento` team as editable state.

## Conversation card

Card should expose high-signal information without becoming visually noisy:

- avatar;
- contact/group name;
- message preview;
- last activity;
- unread count;
- instance/channel;
- assignee;
- priority;
- selected tags;
- useful state indicator;
- optional bot status when operationally important.

## Conversation context menu

Core operations without opening the conversation:

- mark read/unread;
- open/pending/resolve;
- snooze;
- priority;
- assignee;
- tags;
- open in new tab;
- copy stable link.

Every write action must use server authorization and update list/read model coherently.

## Filters

Base filter dimensions:

```text
status
assignee
instance
tags
priority
unread
conversation_type (individual/group)
bot_status
last_activity range
search
```

Filters should be server-queryable for correctness at scale, not only DOM filtering of the current page.

Advanced AND/OR grouping can follow once each primitive filter is stable. Do not build a generic query language prematurely.

## Saved views

Saved views are named serialized filter configurations, not a separate workflow engine.

Examples:

- Minhas conversas;
- Sem responsável;
- Urgentes;
- Novas matrículas;
- Bot pausado.

Validate ownership/permissions and schema version for saved filters.

## Bulk actions

Only add actions that are safe and local-domain oriented:

- assignment;
- priority;
- tags;
- status/read state.

Do not bulk-send WhatsApp messages as a hidden campaign feature; campaigns already own that domain.

## Sidebar

Retain useful current contact data and make operational fields editable:

- contact identity/phone;
- assignment;
- priority;
- status;
- instance;
- tags;
- email/city/source;
- bot state;
- group participants when applicable;
- previous conversations.

### Active detail outside the filtered list

The loaded conversation list is only the current paginated/filter membership;
it is not the existence boundary for the active detail. Selecting a card binds
the detail to the list, while opening a previous conversation by canonical ID
creates a detached/direct active record. A detached record is never inserted
into the list and remains open across list filter/poll reconciliation. Its
canonical DTO is refreshed by `GET api/conversations/{id}`; only a terminal
404/403 clears it. Selecting another card or explicitly clearing the detail
returns to list-bound mode.

## Activity timeline

Show human-readable operational events inline:

```text
Tiago assigned the conversation to Maria
Priority changed to Urgent
Maria marked the conversation Pending
Conversation resolved
Bot paused for human handling
```

This is conversation context, not a new generic audit product. Reuse existing audit/domain events where safe, but create a purpose-built sanitized activity projection rather than exposing raw audit payloads.
