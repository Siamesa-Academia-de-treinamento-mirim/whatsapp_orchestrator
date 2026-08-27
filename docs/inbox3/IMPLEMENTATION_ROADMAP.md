# Inbox 3 — Implementation Roadmap

## Why the order matters

The current UI is not the main architectural constraint. Rich UI features depend on stable message and provider contracts. Therefore the sequence goes from contracts -> media/composer -> message UI -> templates -> workflow -> collaboration -> polish.

Do not skip directly to CSS redesign.

---

## Phase 0 — Baseline and safety net

### Objective

Confirm the current repository state before each implementation stream.

### Work

- run baseline unit/static/lint/JS checks;
- inspect current git status;
- identify Rise/database tests available in the environment;
- preserve a concise test result in the task response.

### Gate

No unexplained pre-existing failing targeted test. Environment-dependent failures are documented, not ignored.

---

## Phase 1 — Message Contract V2 + Provider Capability V2

### Objective

Create the stable contracts every later frontend/backend feature uses.

### Backend deliverables

- Message DTO V2 projector;
- explicit structured message types and unsupported fallback;
- normalized reply reference projection;
- canonical status/error projection;
- capability document V2 with action + media-policy structure;
- backward-compatible legacy capability aliases;
- capability data available to conversation/frontend without secrets.

### Frontend deliverables

- compatibility parsing only; no redesign;
- new code can read V2 while current UI continues to work.

### Tests

- message projection matrix;
- legacy aliases;
- capability schema parity across providers;
- secret absence;
- unknown type fail-safe.

### Gate

Current inbox renders existing messages unchanged, V2 data is available, baseline suite stays green, and no provider-specific branching is required in the V2 consumer for common concepts.

---

## Phase 2 — Media Engine V2 + Voice

### Objective

Make media provider-safe before building Composer 2.

### Deliverables

- provider-aware MIME/size/caption policy enforced server-side;
- multiple pending attachments model;
- deterministic per-file idempotency/send ordering;
- audio recording lifecycle;
- real compatible conversion/transcoding strategy where necessary;
- waveform/timer/playback preview;
- provider-aware accept/validation hints;
- safe partial failure UX.

### Tests

- policy per provider/media kind;
- unsupported MIME/size;
- multiple file order/idempotency;
- audio conversion success/failure boundary;
- Meta audio caption behavior;
- cleanup/no duplicate send.

### Gate

A recorded voice message and representative image/video/document can be safely prepared/sent for each applicable provider path, or the UI blocks unsupported combinations before external send.

---

## Phase 3 — Composer V2

### Objective

Rebuild the agent input experience on the new contracts.

### Deliverables

- modular composer;
- multiple attachments UI;
- drag/drop + clipboard paste;
- reply-to selection/quote/cancel;
- drafts per conversation + reply/note mode;
- slash quick replies;
- emoji;
- keyboard behavior;
- error-preserving retry state.

Template-specific UI may show a placeholder hook but belongs to Phase 5.

### Tests

- state transitions;
- switching conversations/modes preserves drafts;
- send clears only after success;
- attachment state removal/cleanup;
- reply target lifecycle;
- slash search.

### Gate

Normal reply/note/media composition is no longer dependent on the old singular attachment flow and survives navigation/errors without losing user text.

---

## Phase 4 — Message Renderers + Actions

### Objective

Reach high message fidelity and action ergonomics.

### Deliverables

- renderer modules for every V2 type;
- reply quote rendering + jump-to-target;
- message context menu;
- copy/retry/quick-reply creation;
- reaction receive aggregation and outbound action when capability supports it;
- full sent/delivered/read/failed distinction;
- autolink with safe URL parsing;
- structured sticker/location/contact/interactive/unsupported UI.

Provider delete action is only implemented if a verified provider contract is added; otherwise omit it rather than faking deletion.

### Tests

- renderer matrix/fixtures;
- XSS/URL escaping cases;
- reply unresolved/resolved;
- status ordering;
- reaction dedupe/targeting;
- context action capability gating.

### Gate

Every recognized V2 type has a deliberate renderer or deliberate unsupported renderer; no recognized structured message silently becomes an empty/plain text bubble.

---

## Phase 5 — Meta Templates + Service Window UX

### Objective

Bring official template sending into the normal conversation composer.

### Deliverables

- template picker/search/sync;
- structured variable/component form;
- template preview;
- send from conversation;
- rich history projection;
- service-window-aware composer state;
- race-safe backend rejection UX.

### Tests

- approved template filtering;
- parameter validation;
- rich persisted projection;
- window open/closed/race;
- Evolution capability hides template flow.

### Gate

An agent outside the Meta 24h window can move directly to a validated approved template without attempting an invalid freeform send, and history shows the useful template content.

---

## Phase 6 — Conversation Workflow + Inbox UI

### Objective

Expose the backend workflow and reach mature queue organization.

### Deliverables

- open/pending/resolved/snooze;
- mark read/unread;
- full priority selector with documented compatibility mapping;
- full staff assignment picker;
- real manual team/queue assignment after resolving the host/domain source;
- richer conversation cards;
- conversation context menu;
- filters;
- sidebar editable controls + previous conversations;
- activity timeline;
- server-side filter support and indexes as needed.

### Tests

- each workflow transition + authorization;
- snooze wake behavior;
- priority compatibility;
- filter combinations and pagination;
- assignment validation;
- team assignment/membership validation;
- activity sanitization.

### Gate

Agents can manage queue state from list/conversation without hidden backend capabilities or client-only filters that break across pagination.

---

## Phase 7 — Collaboration + Productivity

### Objective

Improve multi-agent safety and high-volume work.

### Deliverables

- note mentions + notifications;
- viewing/typing presence;
- collision warning;
- saved views;
- bulk safe actions;
- stable permalinks/new-tab workflow;
- keyboard navigation/search improvements.

### Tests

- presence expiry;
- mention authorization/notification;
- collision does not block normal sending when presence subsystem fails;
- saved view ownership/schema validation;
- bulk partial failure handling.

### Gate

Two agents can work concurrently without silent duplicate-response risk, and high-volume queue operations remain authorized and predictable.

---

## Phase 8 — Visual polish + full regression

### Objective

Make the product visually coherent after behavior is stable.

### Deliverables

- spacing/type/hierarchy cleanup;
- menus/popovers/tooltips/empty/loading/error states;
- focus/keyboard/accessibility polish;
- responsive behavior;
- media viewer polish;
- removal of dead legacy frontend paths after confirmed migration;
- documentation cleanup.

### Tests

- full automated suite;
- browser/manual matrix in Rise;
- Evolution real-path smoke;
- Meta real-path smoke;
- upgrade migration test on production-like DB copy;
- permission matrix;
- representative long histories/media/groups.

### Gate

No known critical regression, all available automated tests green, environment-only validations recorded, and the old code paths removed only after feature equivalence is demonstrated.

---

## Phase sequencing rule for Codex

Each task should implement one phase or one explicitly bounded vertical slice inside a phase. A phase can use several commits/tasks. Do not combine Phase 1 through Phase 8 into one request.
