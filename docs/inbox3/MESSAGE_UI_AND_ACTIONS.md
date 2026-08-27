# Message UI and Actions

## Renderer architecture

Replace the current handful of `if message_type` branches with dedicated renderers consuming Message DTO V2.

Required renderers:

- text;
- image/gallery;
- audio/voice;
- video;
- document;
- sticker;
- location;
- shared contact;
- template;
- interactive;
- internal note;
- activity;
- unsupported.

## Message context actions

Context menu is derived from message direction/state and provider capabilities.

Core actions:

- Reply;
- React when supported;
- Copy text/content where meaningful;
- Create quick reply from text where permitted;
- Copy stable conversation/message link when stable linking exists;
- Retry failed outbound message when retry is safe/idempotent;
- Delete only when an explicit server/provider contract exists; do not fake provider deletion by hiding the local row.

Translation is not required for Inbox 3 parity unless separately requested.

## Reply rendering

A message with `reply_to` shows a compact quote of the referenced message. Clicking it should scroll/highlight the local target when available.

Unresolved target: show safe preview/provider reference state, do not break the bubble.

## Reactions

Show reactions attached to target message. Sending/removing a reaction must use a provider action contract and remain disabled if provider cannot perform it.

Do not infer outbound Meta reaction support merely because incoming Meta webhooks normalize reaction events; verify provider/API support at implementation time.

## Status UI

For outbound messages preserve distinct states:

```text
sending
sent
delivered
read
failed
```

Use icon + tooltip/accessible text. Avoid relying only on color.

Never regress status due to late lower-rank webhook events.

## Rich types

### Sticker

Render as sticker/image-like media without a normal document chrome when safe media is available.

### Location

Show name/address plus map/open-location action generated from sanitized coordinates. Do not render arbitrary provider HTML.

### Shared contact

Show name and useful phone/email entries with safe actions.

### Template

Show meaningful header/body/footer/buttons/variables that were sent/received. Do not reduce history to template name alone.

### Interactive

Show the interaction label/result and useful contextual content.

### Unsupported

Show a visible fallback such as “Tipo de mensagem ainda não suportado” plus safe preview/download if available.

## Autolink

Plain-text URLs may become clickable only after safe URL parsing. Allow `http`/`https` and escape text first; never inject raw HTML from message content.

## Media viewer

Image/video/document viewer can be improved incrementally. Keep secure local media endpoints as the source rather than exposing authenticated provider URLs directly.
