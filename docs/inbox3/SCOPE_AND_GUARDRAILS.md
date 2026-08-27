# Inbox 3 — Scope and Guardrails

## Product statement

Inbox 3 is a WhatsApp-specialized agent workspace. It should feel as mature as Chatwoot for day-to-day inbox work while remaining centered on the Orchestrator's existing domain:

- Evolution API;
- Meta WhatsApp Cloud API;
- individual conversations;
- Evolution groups;
- text and WhatsApp media;
- official Meta templates;
- contacts/tags;
- existing campaigns;
- existing deterministic bots;
- Rise staff permissions and assignment.

## In scope

### Messaging fidelity

- rich message DTO;
- text, image, audio/voice, video, document, sticker;
- shared contact and location;
- template and interactive message representation;
- reply-to/quoted message;
- reactions when supported by the provider;
- explicit unsupported-message fallback;
- accurate sending/sent/delivered/read/failed states;
- provider-safe error details and retry where safe.

### Composer

- reply and internal-note modes;
- multiple attachments;
- paste and drag/drop;
- previews and per-file removal;
- audio recording with timer/waveform/playback before send;
- provider-safe audio conversion/format policy;
- quick replies including `/shortcut` lookup;
- emoji;
- drafts per conversation and composer mode;
- official template picker and variable form;
- 24-hour Meta service-window awareness;
- keyboard shortcuts.

### Conversation operations

- mark read/unread;
- open, pending, resolved;
- snooze/defer with explicit wake time;
- priority: none/low/medium/high/urgent (with a compatibility mapping from current `normal`);
- full staff assignee picker and unassign;
- manual team/queue assignment backed by a real team domain;
- tags;
- conversation context menu;
- previous conversations for a contact;
- activity events shown inside the conversation timeline.

### Inbox organization

- richer conversation cards;
- filters by status, assignee, instance, tags, priority, unread, individual/group, bot state, activity date;
- saved views/folders after base filters are stable;
- bulk operations for safe local conversation actions.

### Multi-agent collaboration

- internal-note mentions;
- agent presence/viewing state;
- typing state between agents;
- collision warning;
- notifications for mentions/assignments using the existing notification foundation.

## Explicitly out of scope

Do not use Chatwoot parity as justification for adding:

- Captain or generative AI;
- SLA policy, SLA timers or SLA reports;
- advanced managerial reporting suite;
- Help Center, knowledge base or customer portal;
- web live chat;
- email/SMS/social channels;
- generic omnichannel inbox abstraction beyond what is needed to keep Evolution/Meta provider-neutral;
- CSAT surveys;
- business-hours automation engine;
- generic no-code automation builder;
- generic macro/action builder;
- marketplace/integration ecosystem;
- agent-capacity routing system.

## Existing features that must survive

Inbox 3 may touch shared contracts, but must not regress:

- campaign queue, retries, opt-out, occurrence history and idempotency;
- deterministic bot versions, sessions, fallback/handoff and pause/resume;
- Evolution group identity and participant attribution;
- Meta webhook verification and service-window enforcement;
- credential encryption/sanitization;
- webhook deduplication;
- Rise role permissions.

## Design rule

When deciding whether a Chatwoot behavior belongs in Inbox 3, ask:

> Does this directly improve a human agent's WhatsApp conversation, message/media handling, queue organization or collaboration?

If no, it is not part of this workstream unless separately requested.
