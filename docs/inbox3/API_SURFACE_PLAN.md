# Inbox 3 — API Surface Plan

This is a compatibility-oriented plan, not a requirement to create every endpoint exactly as named. Prefer extending existing routes when that keeps semantics clear and old clients working.

## General rules

- Controllers authenticate/authorize/validate HTTP input and delegate to services.
- All writes return the updated normalized domain projection needed by the UI.
- External send operations use client idempotency identifiers.
- Existing routes stay functional during migration.
- Do not create provider-named UI routes for common actions.

## Phase 1

Prefer no new public route if capabilities can be added safely to existing instance/conversation bootstrap/list payloads.

Message list responses gain Message DTO V2 fields while retaining legacy keys.

## Phase 2 — media

Keep the current single-file endpoint compatible:

```text
POST api/conversations/{id}/attachments
```

Phase 2 also adds, without removing the route above:

```text
POST api/conversations/{id}/attachments/batch
```

The multipart `files[]` order is preserved. Each item carries its own
`client_message_id`, kind, recording/voice-note flags and caption. The
response returns deterministic item states (`sent`, `idempotent`, `rejected`,
`failed`, or `not_attempted`) and a batch id. All items are preflighted before
the first provider call, so a rejected item cannot cause an earlier sibling to
be sent.

Capability V2 supplies media policy; avoid a duplicate independently-maintained MIME-policy endpoint.

Existing media identities are not all successes: sent/delivered/read rows are
`idempotent_success`; explicit pre-acceptance failures are
`retryable_failure`; provider timeouts and unknown failures are
`ambiguous_failure` and are not resent automatically.

## Phase 3 — reply

Preferred compatible direction:

- extend the existing text/message send body with an optional validated reply target; or add a narrowly defined reply action if the provider contract requires materially different handling;
The Composer V2 implementation extends the existing text and attachment
requests with an optional validated local reply target. Phase 4 adds the
provider-neutral reaction action below, with server-side target, capability and
idempotency validation.

The reaction action is:

```text
POST api/conversations/{conversationId}/messages/{messageId}/reaction
```

Body carries emoji/remove semantics, never arbitrary provider payload JSON.

If outbound delete is unsupported/unverified, do not add a fake delete endpoint.

## Phase 5 — templates

Reuse current routes:

```text
GET/POST api/instances/{id}/official-templates[/sync]
GET api/conversations/{id}/templates
POST api/conversations/{id}/templates/sync
POST api/conversations/{id}/templates
```

The conversation GET is available with SEND permission and returns only the
local provider-neutral DTO. Explicit sync is an instance-management action.
The conversation POST accepts only `template_id`, validated `values` and
`client_message_id`; template definitions and provider components are loaded
from the same instance on the server. Enhance validated request/response
contracts for parsed components and rich history rather than creating a
parallel template subsystem.

## Phase 6 — conversation workflow

Existing routes for priority/resolve/reopen/tags/assignment remain compatible.

The inbox also exposes `GET api/conversations/{id}` as the canonical individual
conversation read used when a previous/active conversation is outside the
currently loaded list prefix. It returns the same Conversation DTO V2 as the
list projector and does not insert the row into the paginated list.

Add or consolidate missing semantics cleanly, conceptually:

```text
POST api/conversations/{id}/status       # open/pending/resolved
POST api/conversations/{id}/unread       # mark unread
POST api/conversations/{id}/snooze       # until timestamp/preset
DELETE api/conversations/{id}/snooze     # wake now, if REST conventions fit router
```

It is acceptable to retain `resolve`/`reopen` as compatibility aliases over the new status service.

Conversation listing expands validated query parameters for assignee/tag/priority/unread/type/bot/activity filters.

Staff/team picker data must come from authorized server data, not arbitrary IDs typed in the browser.

## Phase 7 — collaboration/productivity

Potential surfaces:

```text
POST api/conversations/{id}/presence
GET  api/conversations/{id}/presence

GET/POST api/saved-views
PUT/DELETE api/saved-views/{id}

POST api/conversations/bulk-action
```

Presence payloads are ephemeral and bounded. Bulk action body uses an allow-listed action enum and validated conversation IDs.

Internal-note mention IDs can extend the existing note endpoint rather than create a separate message system.

Phase 7 uses the existing note route with additive `mention_user_ids`, the presence and bulk routes above, and private saved-view CRUD. Mention notification clicks reuse `openConversationById()` and never put a detached conversation into the paginated list.

```text
GET/POST    api/saved-views
PUT/DELETE  api/saved-views/{id}
```

## Stable links

Conversation/message permalinks should use normal application routes/IDs and authorization. Never generate a stable link from a temporary signed media URL or raw provider credential-bearing URL.
