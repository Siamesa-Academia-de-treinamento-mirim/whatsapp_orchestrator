# Inbox 3 — Database Migration Plan

## Baseline

The uploaded snapshot registers migrations through V009. Inbox 3 schema changes begin at **V010** (delivery/read timestamps), **V011** (confirmed current reaction state), **V012** (outbound reaction attempts), and **V013** (reaction ordering/status/rollback and the monotonic change cursor), **V014** (durable snooze), and **V015** (collaboration/productivity). The next additive migration is **V016**.

## Rules

- V001–V013 are historical. Never modify them to introduce a new Inbox 3 feature.
- Migrations are additive and idempotent under the project's migration runner conventions.
- Prefer nullable/new columns or new tables over changing meaning of existing columns in place.
- Preserve historical rows.
- Add indexes for actual query patterns introduced by filters/workflows.
- Large backfills must be bounded/restartable or avoided through projection compatibility.
- Every migration change requires migration smoke coverage in a disposable DB environment.

## Likely schema needs by phase

These are design candidates, not commands to create all tables upfront.

### Phase 1 — Message Contract / Capabilities

Prefer **no migration** if nested V2 fields can be projected from existing columns/raw sanitized payloads.

Only add schema when a first-class field is required for correctness and cannot safely be derived.

### Phase 2 — Media

Existing `chat_media` may support most file records. Potential extensions only when required:

- duration;
- width/height;
- voice-note flag;
- conversion metadata/status.

Avoid adding columns until implementation needs them.

### Phase 4 — Reply/reactions/actions

Existing `reply_to_external_message_id` should be reused.

A reaction table may be useful when aggregating multiple actor reactions reliably, e.g. conceptually:

```text
chat_message_reactions
  message_id
  provider_reaction_id / dedupe identity
  actor identity
  emoji
  from_me
  active/deleted
  timestamps
```

Do not force reactions into message text.

Implemented additively in **V011**: `chat_message_reactions` stores only the
confirmed current state for each target/actor. Its active rows are aggregated
for the requested local message ids. V011 is never used as outbound idempotency
or attempt history.

Implemented additively in **V012**: `chat_message_reaction_attempts` stores the
immutable client request identity and provider outcome, with a unique
`(instance_id, client_message_id)` and a `(message_id, created_at)` index.
V013 adds nullable provider-ordering metadata to V011 without editing the
historical migration file.

Implemented additively in **V013**: reaction attempts gain previous confirmed
state and provider status/error fields; V011 gains the attempt source and a
canonical state-order key; and `chat_message_reaction_changes` provides an
auto-increment change cursor for live target reconciliation. V013 is
forward-only and does not rewrite V001–V013. Any future schema addition starts
at V014.

Current-state ordering uses provider time when supplied and a high-precision
local order time for outbound confirmation/rollback. An outbound or rollback
source wins an equal-time provider echo; equal-kind ties use the provider or
attempt key lexicographically, making the result deterministic without
pretending local time is a provider timestamp.

### Phase 6 — Snooze/activity/views

Potential additive fields/tables:

```text
chat_conversations.snoozed_until
chat_conversation_activities
chat_saved_views
chat_teams / chat_team_members   # only if no suitable Rise team domain exists
```

Before creating `chat_conversation_activities`, evaluate whether a sanitized projection over existing audit/domain records can meet UX and retention requirements. Do not expose raw audit logs directly.

Implemented additively in **V014**: `chat_conversations.snoozed_until` and
`snoozed_by`, plus the due-snooze wake index. Conversation activity uses the
existing sanitized audit projection and teams reuse the operational Rise
`team` domain; no parallel activity or team table is created.

V014 is forward-only. V015 is the additive Phase 7 migration described below; V016 remains reserved for a later phase.

### Phase 7 — Mentions/presence

Potential:

```text
chat_internal_note_mentions
```

Presence should prefer ephemeral/cache storage when available. If database-backed, include expiry and indexes and avoid unbounded heartbeat history.

## Phase 7 migration note

V015 is forward-only and owns `chat_internal_note_mentions`, private schema-versioned `chat_saved_views`, and bounded `chat_conversation_presence` rows. Presence uses the database because the available generic cache can save/read keys but cannot safely enumerate all agents for a conversation; expired rows are reused and no heartbeat history is retained. V016 remains reserved.

## Priority migration

Current stored priority values include `normal`. Before changing allowed values, define and test a mapping. Do not silently reinterpret old `normal` rows differently across backend and frontend.

## Rollback philosophy

Production-safe forward migrations are primary. `down()` should be conservative; never make a rollback routine that destroys user data simply to satisfy symmetry.
