# Collaboration and Productivity

## Drafts

See Composer V2. Draft reliability lands before presence because it directly protects agent work.

## Internal-note mentions

Add `@staff` suggestions in note mode.

Backend should parse/receive explicit selected user IDs rather than trusting free-form `@name` text for notification routing.

On successful note creation:

- persist note;
- persist mention relationships if schema is needed;
- create notifications through the existing notification service;
- do not notify deleted/inaccessible users.

## Agent presence

Presence is ephemeral operational state, not long-term audit data.

Minimum states:

```text
viewing conversation
editing/typing reply
last seen heartbeat
```

Requirements:

- scoped to authenticated staff/account;
- expires automatically after heartbeat timeout;
- closing/navigating eventually clears state;
- failure of presence service must not block message send.

## Collision warning

If another active agent is viewing/typing in the same conversation, surface a non-blocking warning:

```text
Maria está respondendo esta conversa.
```

Do not hard-lock conversations by default. Assignment and human judgment remain primary controls unless separately requested.

## Realtime transport

Use the lightest transport compatible with the Rise deployment. Polling with bounded intervals may be acceptable initially; WebSocket infrastructure should not be introduced solely for visual parity if the host cannot support it reliably.

## Saved views

After base filters are stable, save named filter payloads with schema version. Do not save executable expressions.

## Bulk operations

Use selection state in the conversation list and one server endpoint/service per safe bulk domain action or a validated batch contract. Report partial failures explicitly.

## Keyboard workflow

Add shortcuts only when focus behavior is safe:

- composer send/newline;
- `/` quick replies;
- search/command palette;
- next/previous conversation only if it does not steal normal browser/text-input shortcuts.

## Copy/permalink

Stable links should encode durable conversation/message identifiers and route through authorized application URLs. Copying a link must not expose provider tokens, media signatures with excessive lifetime or raw JIDs when unnecessary.

## Phase 7 decisions

Mentions receive only explicitly selected active staff IDs (maximum 20), persist a relationship to the note/message, and use `note-mention|note_id|user_id` notification dedupe keys. Replaying the same client message ID with different content or sorted mention IDs returns an idempotency payload mismatch. Self-mentions persist but do not notify the author.

Presence uses V015 database rows rather than the generic cache: the cache supports key lookup but not safe multi-agent enumeration. Viewing presence expires after 45 seconds and typing after 8 seconds; expired rows are reused, with no heartbeat history. Presence failures are advisory and never block sending. Collision text is non-blocking and excludes the current actor.

Saved views are private, schema version 1, limited to the documented Phase 6 filter dimensions, and never contain active conversation, pagination, draft or executable query data. Bulk actions accept only status, priority, assignment, read state, tags add and tags remove, with at most 100 unique local conversation IDs and deterministic per-item results.

Stable links use the current application URL plus the positive local `conversation` query parameter. They contain no JID, provider data or credentials. Keyboard support is limited to Ctrl/Cmd+K search focus and Alt+ArrowUp/Down visible-list navigation, suppressed in text/editing controls and inactive for detached detail.
