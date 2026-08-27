# Templates and Meta Service Window

## Goal

Make official WhatsApp templates part of normal conversation handling rather than a separate campaign-only mental model.

## Template picker

For a conversation whose provider supports official templates:

- open from composer;
- list locally synced approved templates;
- search by name/body/category/language where data exists;
- allow explicit refresh/sync;
- display name, language, category and meaningful content preview;
- support header/body/footer/buttons/media components that the backend actually supports.

Do not send arbitrary unvalidated template component JSON from a raw textarea in the conversation flow.

## Parameter form

Parse template component definitions into typed fields. Validate:

- required parameters;
- expected text/media/button parameter positions;
- maximum lengths where provider rules require them;
- media requirement/capability;
- selected language/template state.

Build provider component JSON server-side or through a tightly validated client contract.

## History projection

When sending a template, persist enough normalized information to render what the agent intended/sent:

```text
template name
language
resolved parameter values
renderable body/header/footer
buttons
media reference
provider message id/status
```

Do not rely on `[Template] template_name` as final history UX.

## 24-hour service window

Current backend already blocks freeform Meta text outside the window. Inbox 3 moves this state earlier into UX while retaining server enforcement.

Conversation DTO exposes:

```text
service_window.required
service_window.open
service_window.expires_at
service_window.last_customer_message_at
service_window.seconds_remaining
service_window.freeform_allowed
service_window.template_required
service_window_open
service_window_expires_at
```

The Meta value is always the official fixed 24-hour window. The historical
`meta_service_window_hours` setting is not an ordering or enforcement
authority.

When open:

- freeform composer behaves normally.

When closed:

- freeform send controls are disabled or converted to a clear blocked state;
- the user receives an explanation;
- template picker is the primary action;
- server still rejects a forged/late freeform request.

## Race conditions

Window state can expire while the user is typing. The backend remains authoritative. On server rejection:

- preserve draft;
- show window-closed error;
- offer template path;
- do not repeatedly retry the forbidden freeform send.

## Evolution

Evolution does not gain official Meta template behavior just for UI parity. Capability contract hides/disables this path.
