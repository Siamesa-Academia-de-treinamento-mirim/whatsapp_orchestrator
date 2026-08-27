# Message Contract V2

## Goal

Create one versioned, provider-neutral message projection that can represent all message forms the inbox needs without forcing the frontend to inspect raw provider payloads.

The V2 contract is an API/read-model contract, not necessarily a one-table schema.

## Canonical shape

Illustrative shape; field names should remain stable after Phase 1:

```json
{
  "contract_version": 2,
  "id": 123,
  "conversation_id": 45,
  "instance_id": 2,
  "provider": "evolution",
  "provider_message_id": "...",
  "client_message_id": "...",
  "direction": "incoming",
  "type": "text",
  "status": "delivered",
  "sender": {
    "kind": "contact",
    "user_id": null,
    "contact_id": 88,
    "jid": "...",
    "phone": "...",
    "name": "Maria"
  },
  "content": {
    "text": "Olá",
    "caption": "",
    "attachments": [],
    "location": null,
    "contact": null,
    "template": null,
    "interactive": null
  },
  "reply_to": null,
  "reactions": [],
  "timestamps": {
    "created_at": "...",
    "sent_at": "...",
    "delivered_at": null,
    "read_at": null,
    "failed_at": null
  },
  "error": null,
  "actions": {},
  "metadata": {}
}
```

## Message types

V2 types:

```text
text
image
gallery
audio
voice
video
document
sticker
location
contact
template
interactive
reaction
internal_note
activity
unsupported
```

`gallery` may be a frontend/domain aggregation of multiple attachments rather than a provider-native message type.

`voice` is semantically distinct from generic audio when the provider exposes/accepts a voice-note/PTT concept. If the current provider cannot distinguish it, normalize to `audio` and expose a safe flag rather than inventing data.

## Structured content

### Attachments

Each attachment should project enough information for UI rendering without raw provider payload access:

```json
{
  "id": 10,
  "kind": "image",
  "url": "/...",
  "mime_type": "image/jpeg",
  "file_name": "photo.jpg",
  "file_size": 12345,
  "width": null,
  "height": null,
  "duration_ms": null,
  "is_voice_note": false
}
```

Do not force every optional field into the database in Phase 1.

### Location

Preserve when available:

```text
latitude
longitude
name
address
url (derived by frontend only if safe)
```

### Shared contact

Preserve a sanitized useful subset:

```text
display_name
phones[]
emails[]
organization (when present)
```

Do not expose arbitrary unbounded vCard/provider payloads directly to the browser.

### Template

Represent:

```text
name
language
category when known
header/body/footer rendered or source structure
resolved parameters
buttons
media reference when applicable
```

History must show useful sent content, not only `[Template] name`.

### Interactive

Represent provider-neutral interaction result/content where possible:

```text
kind
id
label/title
description
context
```

Do not flatten button/list replies to plain text until after the structured fields are captured.

## Reply-to

The row already has `reply_to_external_message_id`. V2 should project:

```json
{
  "provider_message_id": "wamid...",
  "message_id": 99,
  "type": "text",
  "author": "Maria",
  "preview": "Vocês abrem sábado?"
}
```

If the target message cannot be resolved locally, preserve at least the provider message ID and mark it unresolved. Do not drop the relationship.

Outbound reply semantics are a message action and require provider support; a projected `reply_to` does not imply every provider can send every reply form.

## Reactions

A reaction belongs to a target message. The main timeline should not model it only as a standalone emoji bubble.

Preferred projection on the target:

```json
[
  {"emoji":"❤️","count":2,"reacted_by_me":false},
  {"emoji":"👍","count":1,"reacted_by_me":true}
]
```

Provider events may still be stored individually for idempotency/history.

## Status model

Canonical outbound progression:

```text
sending -> sent -> delivered -> read
                 \-> failed (where appropriate)
```

Incoming messages use a stable received state or omit meaningless outbound receipts. Do not regress a message from a higher receipt state to a lower one when late events arrive.

V2 should keep explicit timestamps when the provider/event supplies them. Do not fabricate delivered/read times from local render time.

Phase 1 persists provider delivery/read timestamps in nullable `chat_messages`
columns through additive migration V010. A status event without a reliable
provider timestamp may advance status, but must not invent a delivered/read
timestamp from local processing time. Late lower-ranked receipts never regress
the canonical status.

## Error model

For failed outbound messages expose a sanitized error object, for example:

```json
{
  "code": "SERVICE_WINDOW_CLOSED",
  "message": "A janela de atendimento terminou.",
  "retryable": false,
  "suggested_action": "send_template"
}
```

Never send raw secrets/provider stack traces to the browser.

## Unsupported fallback

Unknown types become:

```text
type = unsupported
content.text = safe preview if one exists
metadata.safe_type_hint = sanitized provider type
```

The renderer should visibly say the message type is not yet supported and retain a safe preview/download path when possible. Unknown must not silently become `text`.

## Legacy compatibility window

During migration retain current keys required by the existing UI, including where applicable:

```text
message_type
text_content
media_url
mime_type
caption
file_name
file_size
external_message_id
sender_name
sender_phone
is_internal_note
```

New modules should consume V2. Remove aliases only in a later explicit cleanup after all consumers migrate.

## Phase 1 projection notes

The Phase 1 projector emits the canonical fields above together with the
legacy aliases at the same message level. Structured reaction data is exposed
under `content.reaction`; provider events may remain individual records while
`reactions` is reserved for an aggregate on the target message. A reply
reference always preserves its provider message ID and includes
`resolved: false` when no local target exists.
