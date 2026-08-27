# Inbox 3 — Current State Audit

This audit describes the uploaded Orchestrator snapshot used to prepare the handoff. It is not a promise that every finding remains true after later phases; Codex should re-inspect affected files before editing.

## Baseline validation performed

The preparation environment successfully ran:

- `php Tests/run_unit.php` — 17 passed, 0 failed;
- `php Tests/run_product_static.php` — 15 passed, 0 failed;
- PHP syntax lint — 112 PHP files passed;
- `node --check Assets/js/chatwoot.js` — passed;
- `node --check Assets/js/hub-workspace.js` — passed.

Rise/database integration tests were not run because the standalone ZIP does not include a live Rise bootstrap + disposable MySQL/MariaDB environment.

## Current architecture worth preserving

- `Contracts/WhatsAppProviderInterface.php` provides a shared provider abstraction.
- `Providers/Evolution_provider.php` and `Providers/Meta_cloud_provider.php` isolate main outbound provider differences.
- `Services/Provider_manager.php` resolves provider by instance.
- `Services/Chat_service.php` owns local conversation/message read model, sending and webhook persistence.
- `Services/Media_service.php` owns local media storage and outbound media flow.
- `Services/Webhook_normalizer.php` and `Services/Meta_webhook_normalizer.php` normalize provider webhooks.
- `Conversation_action_service.php` already supports substantial local conversation state.
- local messages/conversations are the primary UI read model; remote sync is recovery, not the normal render path.

## Routes already available

`Config/Routes.php` currently exposes conversation endpoints for:

- list/create/sync;
- message list and history sync;
- text send;
- official template send;
- mark read;
- single attachment send;
- internal note;
- priority;
- resolve/reopen;
- tags;
- assignment;
- group details;
- bot pause/resume.

Important missing/underexposed operations include:

- reply-to send semantics;
- reaction send semantics;
- message context operations;
- explicit mark unread;
- pending endpoint/action despite backend status support;
- snooze;
- richer filtering/saved views/bulk operations;
- presence/collision APIs.

## Message persistence and projection

The schema already has useful foundations:

- `chat_messages.reply_to_external_message_id`;
- `caption`, `file_name`, `file_size`, `media_id`;
- `sender_user_id` and provider/group sender fields;
- `delivery_error`, `failed_at`;
- provider and external IDs.

`Chat_service::allowedMessageType()` currently recognizes:

- text, image, audio, video, document, template, sticker, reaction, location, contact.

But `Chat_service::mapMessage()` still projects a mostly flat shape. Structured payloads are not first-class DTO objects and rich types are underrepresented by the frontend.

## Structured incoming messages

`Meta_webhook_normalizer` recognizes Meta message types including sticker, reaction, location, contacts, interactive and button. Current behavior flattens some of them:

- `contacts` becomes local `contact` but mostly text preview;
- `interactive`/`button` become text;
- reaction becomes emoji text;
- location becomes name/address text.

This loses information needed for a Chatwoot-quality renderer/action model.

## Reply-to

A `reply_to_external_message_id` column exists, but the current outbound provider interface has no reply method/context contract and the current UI has no operational reply-to flow. Treat this as an unfinished foundation, not a completed feature.

## Provider capabilities

`Provider_capabilities.php` currently returns a flat boolean map such as:

- groups;
- templates;
- freeform messages;
- freeform outside window;
- media;
- message/read status;
- reactions;
- official.

It also creates short aliases for older consumers. This is insufficient for per-media MIME/size rules, reply/action support, caption rules, voice-note requirements and provider-specific UI decisions.

## Media

`Media_service` currently:

- accepts one uploaded file per request;
- uses one configurable generic upload ceiling (default 16 MB, capped at 64 MB);
- uses a single global MIME allow-list;
- includes `audio/webm`;
- does not transcode recorded audio;
- stores caption on the local outgoing message.

`Meta_cloud_client::sendMedia()` supports image/audio/video/document payload types and intentionally does not attach captions to audio.

Consequences to address:

- media validation needs provider-aware policy;
- browser-recorded WebM must not be blindly sent to Meta;
- local audio caption UX must not imply recipient received a caption when Meta did not send one;
- UI file picker should reflect provider policy before upload.

## Composer/frontend

`Views/partials/conversations.php` already provides reply/note mode, attachment, emoji, quick reply, textarea, voice and send controls. However:

- file input is not multiple;
- `hub-workspace.js` uses a singular `pendingAttachment`;
- it reads only `files[0]`;
- adding another attachment replaces the previous one;
- voice recording uses `MediaRecorder` and can produce `audio/webm`;
- recording is treated as a regular attachment;
- there is no waveform/timer/play-before-send flow;
- no per-conversation reply/note draft system was found;
- quick replies are button-driven rather than slash-command-driven.

## Message renderer

`Assets/js/chatwoot.js` currently has dedicated media rendering primarily for image/audio/document/video. It lacks complete first-class renderers for:

- sticker;
- location;
- shared contact;
- reaction anchored to target;
- rich official template;
- interactive payload;
- explicit unsupported type.

Current status icon logic does not visually preserve the full sent vs delivered distinction.

## Conversation workflow

The database/backend already holds more than the current UI exposes:

- `Conversation_action_service` accepts priority `low`, `normal`, `high`, `urgent`;
- assignment can target arbitrary valid staff IDs;
- status supports `open`, `pending`, `resolved`.

Current UI underexposes these capabilities:

- priority behaves largely as high/normal toggle;
- assignment is mainly self/unassign;
- no complete pending/snooze workflow;
- list filtering is mainly status/instance/search.

This is a reason to extend the UI over existing backend behavior rather than rebuild the workflow from zero.

## Contact sidebar

Current sidebar already contains contact/profile information, assignment/team labels, instance, email/city/source, first contact, tags, group participants and bot state. Inbox 3 should turn these into efficient editable controls and add previous conversation history rather than replace useful existing data.

## Existing campaigns and bots

Campaign and bot subsystems are comparatively mature and outside the Inbox 3 rewrite. Avoid touching them except for backward-compatible changes needed by a shared provider/message contract.
