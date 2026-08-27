# Composer V2

## Goal

Build a conversation composer with Chatwoot-level ergonomics on top of Message/Capability/Media V2 contracts.

## Modes

Two explicit modes:

- `reply` — external WhatsApp message;
- `note` — internal note.

State is independent per mode. Switching modes must not destroy unsent content.

## Composer state

Conceptually:

```text
conversation_id
mode
text
reply_to
attachments[]
recording
selected_template
template_parameters
draft_state
send_state
```

## Required controls

- attachment picker;
- emoji picker;
- quick replies;
- template picker when provider supports it;
- voice recorder when provider/media policy supports it;
- send;
- visible reply-to quote with cancel;
- mode switch.

Controls are capability-driven.

## Multiple attachment UX

- file input supports multiple selection;
- paste from clipboard accepts supported images/files;
- drag/drop surface gives visible affordance;
- pending items render preview/name/size/error;
- item removal is independent;
- composer remains usable while previews load;
- unsupported files are rejected before send with specific reasons.

## Drafts

Minimum implementation: browser storage keyed by:

```text
conversation_id + composer_mode
```

Draft includes text and reply target. Do not persist raw `File` objects to localStorage. Attachment draft behavior must be explicit: either keep only while page remains alive or use a supported browser persistence layer in a later enhancement.

Rules:

- autosave with debounce;
- restore when returning to conversation;
- clear only after confirmed successful send or explicit discard;
- note and reply drafts are separate;
- no cross-account data leakage: storage key must include a safe account/user/plugin scope where needed.

## Quick replies

Existing quick reply CRUD/shortcuts are a strong foundation.

Add composer lookup:

```text
/bol -> /boleto, /boleto_atrasado, ...
```

Support variables only after defining a safe substitution catalog, for example:

```text
{{contact.name}}
{{contact.phone}}
{{agent.name}}
```

Unknown variables remain visible or fail validation; never evaluate code/templates dynamically.

## Reply-to

User can invoke reply from a message action. Composer shows a compact quoted preview. Sending carries the reply target through the server provider action contract.

Cancelling reply removes only the reply target, not the typed text.

## Keyboard behavior

Support configurable/explicit send semantics. Minimum:

- Enter sends under the current product setting;
- Shift+Enter newline;
- Escape closes ephemeral menus/previews where safe;
- `/` quick reply lookup;
- Cmd/Ctrl+K can become inbox search/command entry when that phase is implemented.

Do not hijack accessibility/browser shortcuts unnecessarily.

## Internal notes

Notes do not use provider media send. Phase 7 adds `@mentions` with staff suggestions and notification creation; this contract is complete.

## Error behavior

A send failure must preserve enough composer state to retry safely. Never clear a user's text/attachments merely because the network request started.

## Accessibility

- controls have labels/tooltips;
- keyboard focus remains predictable after menus/modals;
- recording status is not color-only;
- errors are announced/visible near the relevant item;
- disabled state explains why when service window/provider capability blocks sending.
