# Provider Capability Contract V2

## Goal

The frontend must know what an action/media flow supports **without hard-coding Evolution vs Meta logic**.

Current boolean capabilities remain as compatibility aliases during migration, but the new canonical capability document is structured and versioned.

## Suggested capability shape

```json
{
  "contract_version": 2,
  "provider": "meta_cloud",
  "official": true,
  "conversation": {
    "groups": false,
    "service_window": true,
    "templates": true
  },
  "actions": {
    "send_text": true,
    "send_media": true,
    "reply": true,
    "react": true,
    "mark_read": false,
    "delete_message": false
  },
  "reaction": {
    "enabled": true,
    "groups": false,
    "max_target_age_seconds": 2592000,
    "supports_remove": true
  },
  "events": {
    "receive": {
      "message_status": true,
      "read_status": true,
      "reactions": true
    }
  },
  "media": {
    "image": {
      "enabled": true,
      "accepted_mime_types": ["image/jpeg", "image/png"],
      "recording_input_mime_types": [],
      "max_bytes": 5242880,
      "caption": true,
      "caption_max_chars": 1024,
      "multiple_selection": true,
      "voice_note": false,
      "requires_conversion": false,
      "requires_recording_conversion": false,
      "recording_target": null,
      "transport": "https",
      "requires_https_link": true,
      "requires_opus_codec": false,
      "voice_note_requires_mono": false
    },
    "audio": {
      "enabled": true,
      "accepted_mime_types": ["audio/aac", "audio/mp4", "audio/mpeg", "audio/amr", "audio/ogg"],
      "recording_input_mime_types": ["audio/webm", "video/webm"],
      "max_bytes": 16777216,
      "caption": false,
      "caption_max_chars": 0,
      "multiple_selection": true,
      "voice_note": true,
      "requires_conversion": true,
      "requires_recording_conversion": true,
      "recording_target": "audio/ogg; codecs=opus",
      "requires_opus_codec": true,
      "voice_note_requires_mono": true,
      "transport": "https",
      "requires_https_link": true
    }
  }
}
```

The Phase 2 implementation verifies the Meta matrix against the official Meta
WhatsApp Business Platform media collection: image JPEG/PNG up to 5 MB, audio
AAC/MP4/MPEG/AMR/OGG (Opus only) up to 16 MB, video MP4/3GPP up to 16 MB, and
the listed document formats up to 100 MB. The official collection also states
that base `audio/ogg` without Opus is not supported. These values remain
versioned backend policy and must be revisited if Meta changes the contract.

For Meta video, the backend additionally validates H.264 video and AAC audio
streams with FFprobe; a video without an audio stream is allowed by the
provider contract.

## Principles

### Same schema, different values

Evolution and Meta documents must have the same structural keys. The UI should branch on capability values, not provider names.

### Separate action support from message recognition

A provider may let us **receive** a message/action that we cannot **send**. Incoming reaction normalization alone does not prove outbound reaction support; this implementation enables the outbound flag only after the adapter and provider endpoint are both covered.

Model direction where necessary:

```text
receive
send
```

or separate action flags.

Phase 1 uses `events.receive` for provider events that the normalizers
recognize. `actions` is reserved for operational outbound/local actions. The
current providers therefore expose received reactions and read receipts while
`actions.reply` is true because the backend validates a local target and both
adapters send the provider-specific quoted-message context. `actions.react` is
true only because both adapters now call their documented provider reaction
operation; received reactions remain separately represented under
`events.receive`.
Local conversation read state is not a provider mark-read receipt.

The provider-neutral reaction policy is exposed as `reaction`: `enabled`,
`groups`, `max_target_age_seconds`, and `supports_remove`. Meta advertises
`groups=false` and a 30-day target window. Evolution advertises group support
when the group target participant can be preserved in the adapter payload.
These values are backend authority, not frontend-only hints.

### Media is policy, not a boolean

`supports_media = true` is insufficient. Media policy needs at least:

- enabled;
- accepted MIME types/extensions if relevant;
- maximum bytes;
- caption support;
- voice-note support;
- whether browser recording requires conversion;
- provider upload/link constraints where the backend needs them.

### Capabilities are authoritative server data

The frontend can use capabilities to disable/hide actions, but the backend must revalidate the same policy before any external send.

### No secrets

Capability payloads never contain:

- API key;
- access token;
- app secret;
- webhook secret;
- credential ciphertext;
- private provider endpoint containing embedded credentials.

## Service-window behavior

The capability document says whether a provider has a service-window concept. The conversation DTO says whether the window is currently open and when it expires.

Do not encode a transient per-conversation state as a global provider capability.

## Backward compatibility

Retain current boolean keys/aliases until the current monolithic UI no longer reads them. New frontend modules should consume the V2 nested contract.

Phase 1 publishes `contract_version: 2` on instance and conversation payloads,
with the structured `media` policy taking precedence over the historical
boolean `media` short alias. That conflicting alias is retained as
`legacy_aliases.media`; the explicit `supports_media` alias remains at the
top level with the other legacy booleans. Phase 2 made these policies
authoritative for server-side MIME, size, caption, voice, codec and conversion
validation. Recording conversion is advertised only under the Meta audio
policy; image/video/document/sticker policies never carry an audio conversion
target. Media limits remain category-specific and are revalidated before any
provider call.

## Contract tests

Tests must prove:

- both providers conform to the same schema;
- values differ where expected;
- legacy aliases remain during migration;
- no secret-like fields are serialized;
- media policies are internally consistent (`enabled=false` cannot advertise an active send path);
- unknown provider/action values fail closed in UI/backend behavior.
