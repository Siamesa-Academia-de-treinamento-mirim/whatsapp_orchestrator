# Media Engine V2

## Goal

Make attachment and voice-message handling reliable, provider-aware and pleasant before rebuilding the full composer.

## Current problems to eliminate

- one `pendingAttachment` only;
- `files[0]` only;
- global MIME policy independent of provider;
- one generic size ceiling;
- browser `MediaRecorder` can produce WebM that is not universally safe for provider send;
- no pre-send voice playback/waveform/timer;
- audio caption can appear locally even when the provider does not send it.

## Attachment collection

Frontend state becomes a collection:

```text
pendingAttachments[]
```

Each pending item tracks:

```text
local id
File/Blob
kind
mime type
size
preview URL
validation state
upload/send state
error
caption policy
```

Requirements:

- adding a file never silently replaces an existing pending file;
- each item can be removed independently;
- the provider capability policy validates before upload;
- server validates again;
- object URLs are revoked when removed/composer destroyed;
- UI handles partial failure explicitly.

## Multiple send semantics

Do not assume “multiple selection” means one provider API request. If the provider sends files as separate messages, orchestrate separate idempotent sends in deterministic order.

Each external message needs its own client idempotency key. A composer-level batch ID may group them for UI purposes.

Decide caption semantics explicitly:

- caption may apply only to the first eligible media message;
- or user can assign per-item captions;
- or a separate text message is sent after attachments.

Do not duplicate the same caption accidentally across N provider messages.

## Voice recorder

Target experience:

```text
record -> timer/waveform -> stop -> playback preview -> discard or send
```

Required behaviors:

- permission denied has a clear error;
- recording can be cancelled without upload;
- timer is monotonic enough for UX;
- user can play/pause before send;
- recorder cleanup releases tracks;
- losing/closing conversation does not leak microphone capture;
- supported output is selected based on provider policy;
- if browser output is incompatible, backend or controlled client conversion produces a provider-safe target;
- conversion failure is visible and does not create a fake sent message.

## Transcoding rule

Do not implement a fragile MIME rename (e.g. rename WebM bytes to `.ogg`/`.mp3`). Conversion must produce a real valid target container/codec.

Prefer a server-side conversion boundary if the deployment can provide a reliable media tool. If the Rise environment cannot guarantee that dependency, document and implement a tested fallback strategy rather than silently accepting incompatible recordings.

The implementation phase must decide the supported deployment model and add a startup/diagnostic check when an external binary is required.

## Provider-aware media policy

Media validation belongs in one shared backend policy derived from Provider Capability V2. The UI should receive the same policy.

Validate at least:

- kind;
- MIME detected from bytes where possible;
- size;
- extension/name safety;
- caption support/length;
- provider restrictions;
- URL/link requirements for Meta public media flow.

## Inbound media

Keep current secure media retrieval behavior. New renderers may enrich previews, but must not expose provider credentials or follow arbitrary unsafe URLs.

## Security

- never trust browser-provided MIME alone;
- continue using server-side file inspection;
- sanitize file names;
- maintain path traversal protections;
- do not inline executable HTML/SVG as trusted content;
- keep signed/public media URLs appropriately bounded for Meta delivery;
- enforce maximum download/upload sizes server-side.

## Phase acceptance

- multiple files can be queued and removed independently;
- both provider policies reject unsupported MIME/size before external send;
- recorded audio is valid for the target provider or explicitly blocked with actionable error;
- no fake audio caption is shown as delivered when it was not sent;
- send order/idempotency is deterministic;
- unit/static tests cover provider media differences and recording/upload validation boundaries.

## Corrective idempotency states

Media sends use three provider-neutral states. `idempotent_success` means the
existing row is `sent`, `delivered` or `read` and is returned without another
external call. `retryable_failure` means the failure was observed before
provider acceptance and may be retried under the same client id. Any timeout,
transport exception or unknown historical failure is `ambiguous_failure`; it
remains visible as failed and is never blindly resent.

The same conversation/client id is guarded by a MySQL named lock shared with
text sends. Locks are released in `finally`, including provider exceptions.
