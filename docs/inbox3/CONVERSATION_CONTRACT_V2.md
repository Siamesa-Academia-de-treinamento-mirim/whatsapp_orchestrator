# Conversation Contract V2

## Goal

Give the inbox one stable conversation projection for cards, header, composer gating and sidebar actions without forcing the browser to reconstruct domain state from unrelated fields.

## Canonical shape

Illustrative V2 projection:

```json
{
  "contract_version": 2,
  "id": 45,
  "type": "individual",
  "status": "open",
  "priority": "high",
  "unread_count": 2,
  "archived": false,
  "instance": {
    "id": 3,
    "name": "WhatsApp SBC",
    "provider": "meta_cloud",
    "connection_status": "connected"
  },
  "contact": {
    "id": 88,
    "name": "Maria Silva",
    "phone": "5511...",
    "avatar_url": null
  },
  "group": null,
  "assignment": {
    "assignee": {"id": 10, "name": "Tiago"},
    "team": null
  },
  "tags": ["matricula", "lead"],
  "service_window": {
    "required": true,
    "open": true,
    "expires_at": "...",
    "last_customer_message_at": "...",
    "seconds_remaining": 86399,
    "freeform_allowed": true,
    "template_required": false
  },
  "bot": {
    "status": "paused",
    "paused_at": "...",
    "handoff_reason": "human_reply"
  },
  "last_message": {
    "preview": "Gostaria de informações...",
    "at": "..."
  },
  "activity": {
    "last_customer_message_at": "...",
    "updated_at": "..."
  },
  "capabilities": {}
}
```

## Principles

- Provider capability document may be included or referenced from instance bootstrap, but the composer must be able to resolve it without hard-coded provider names.
- Service-window state is per conversation; provider capability only says whether the concept applies. The canonical nested state is authoritative for consumers; `service_window_open` and `service_window_expires_at` remain compatibility aliases.
- Contact/group identity stays explicit.
- Assignment returns IDs and useful display names, not a hard-coded label.
- Team is nullable until a real operational team entity is resolved.
- Tags are normalized strings/objects from server data, not parsed from DOM.
- No SLA/capacity/reporting fields are introduced for Chatwoot parity.

## Status and snooze

Phase 6 defines how `snoozed` is represented. The V2 projection should make the effective inbox state obvious, for example:

```json
{
  "status": "snoozed",
  "snoozed_until": "2026-08-17T12:00:00-03:00"
}
```

or a base status plus a separate snooze object. Choose one canonical model and keep it consistent in list filters/actions.

## Priority compatibility

Current stored `normal` cannot simply disappear. Phase 6 must decide a deterministic legacy mapping and keep API/UI display consistent during migration.

## Assignment/team

Current source has `assignee_id` and `team_id`, but the audited UI uses a hard-coded/default team label rather than a complete team workflow.

Phase 6 must:

1. investigate whether the host Rise installation exposes an appropriate staff team/department entity;
2. reuse it if stable and permission-compatible; otherwise introduce the smallest Orchestrator-owned team/membership domain required for manual team assignment;
3. expose team picker only when backed by real persisted data;
4. keep automatic routing/capacity out of scope unless separately requested.

## Legacy compatibility

During migration retain fields currently consumed by the monolithic frontend, such as:

```text
instance_id
instance_name
instance_status
remote_jid
contact_name/name
phone_number/phone
profile_picture_url
last_message_preview
last_message_at
unread_count
status
priority
assignee_id/assignee
team_id
conversation_type/is_group
group_id
provider_name
service_window_expires_at/service_window_open
bot_status
tags
```

New modules should prefer the V2 nested contract.
