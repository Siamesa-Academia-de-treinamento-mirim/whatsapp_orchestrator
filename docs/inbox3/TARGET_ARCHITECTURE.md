# Inbox 3 — Target Architecture

## Architectural objective

Retain the current Rise CRM / CodeIgniter plugin and progressively introduce clear contracts between provider data, domain services and the inbox UI.

```text
Evolution API ─┐
               ├─ Provider adapters/capabilities ─┐
Meta Cloud ────┘                                  │
                                                  v
                                    normalization + domain services
                                                  │
                                                  v
                                          local read model
                                                  │
                              ┌───────────────────┴──────────────────┐
                              v                                      v
                       Message DTO V2                       Conversation DTO V2
                              │                                      │
                              └───────────────────┬──────────────────┘
                                                  v
                                        modular Inbox frontend
```

## Backend ownership

### Provider layer

Owns:

- supported actions;
- provider media policy;
- outbound payload shape;
- provider-specific IDs/context;
- signature/connection behavior.

Does not own:

- UI rendering;
- conversation list filters;
- generic message projection.

### Normalizers

Own conversion of provider webhook payloads into a lossless-enough internal event. Structured WhatsApp concepts should stay structured.

For example, do not collapse a location into only `"Store - Street"` if latitude/longitude/name/address are present.

### Domain services

`Chat_service` and focused services own:

- conversation/message persistence;
- idempotency;
- service-window policy enforcement;
- domain projection to stable DTOs;
- outbound action orchestration.

As Inbox 3 grows, prefer extracting cohesive services over making `Chat_service.php` indefinitely larger. Candidate boundaries:

```text
Services/Inbox/
    Message_projection_service.php
    Message_action_service.php
    Conversation_query_service.php
    Conversation_activity_service.php
    Presence_service.php
```

Exact names may follow current project conventions; behavior separation matters more than the folder name.

## Frontend module direction

Do not perform a big-bang rewrite. Introduce modules while current entrypoints remain responsible for bootstrap.

Target structure:

```text
Assets/js/inbox/
    core/
        api.js
        store.js
        capabilities.js
        events.js

    conversations/
        conversation-list.js
        conversation-card.js
        conversation-actions.js
        filters.js
        bulk-actions.js

    messages/
        message-list.js
        message-renderer.js
        message-actions.js
        reactions.js
        reply.js
        renderers/
            text.js
            image.js
            audio.js
            video.js
            document.js
            sticker.js
            location.js
            contact.js
            template.js
            interactive.js
            note.js
            activity.js
            unsupported.js

    composer/
        composer.js
        attachments.js
        recorder.js
        drafts.js
        quick-replies.js
        templates.js

    sidebar/
        contact.js
        assignment.js
        priority.js
        tags.js
        history.js

    realtime/
        presence.js
        typing.js
        collision.js
```

Existing `chatwoot.js`/`hub-workspace.js` can bootstrap these modules during migration. New business logic should migrate out of the monolith when the owning phase touches it.

## DTO rule

The browser should not need provider-specific knowledge to decide how to display common concepts.

Bad:

```text
if provider === meta and type === ...
else if provider === evolution and payload.foo...
```

Preferred:

```text
message.type
message.content.location
message.reply_to
conversation.capabilities.actions.reply
conversation.capabilities.media.audio.accepted_mime_types
```

Provider-specific details may remain under bounded metadata when necessary, but core UI paths use stable contracts.

## Compatibility strategy

During migration:

```text
existing database row
        ↓
V2 projector
        ↓
new nested V2 fields + legacy aliases
        ↓
old and new frontend can coexist temporarily
```

Do not force a destructive historical message migration solely to get the new UI running.

## Dependency rule

- Controllers validate HTTP/auth and delegate.
- Services own domain decisions.
- Providers own external API differences.
- Models own persistence primitives/queries.
- Views render markup/config only.
- Frontend modules own interaction, not business rules that must also be enforced server-side.

Anything security-, billing-, provider-, idempotency- or service-window-sensitive must be validated on the server even if the UI already prevents it.
