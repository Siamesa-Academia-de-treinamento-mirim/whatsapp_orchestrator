# Chatwoot Reference — Behaviors to Emulate, Not Code to Copy

## Reference snapshot

Reference repository: `https://github.com/Siamesa-Academia-de-treinamento-mirim/chatwoot.git`.

The audited repository supplied for comparison identified itself as Chatwoot 4.5.2 at the time of analysis. Treat this as a UX/behavior reference. Re-check upstream/current code when implementation details matter.

Do not transplant Rails/Vue code into the Rise PHP/JavaScript plugin.

## Composer reference behaviors

Relevant Chatwoot component audited:

```text
app/javascript/dashboard/components/widgets/conversation/ReplyBox.vue
```

Behaviors worth adapting:

- separate reply/private-note modes;
- multiple attachments;
- reply-to message;
- drafts scoped by conversation and mode;
- paste files;
- keyboard send configuration;
- quick/canned response workflow including slash command;
- variables;
- WhatsApp template picker;
- audio recorder;
- clear disabled/handoff/self-assignment states.

## Audio reference

Relevant component:

```text
app/javascript/dashboard/components/widgets/WootWriter/AudioRecorder.vue
```

Behaviors worth adapting:

- recording progress;
- waveform;
- stop then preview/playback;
- explicit conversion before send when needed;
- discard/send controls.

Do not copy its dependency stack blindly. Choose a solution compatible with the existing Orchestrator frontend and deployment.

## Conversation card/context menu reference

Relevant components audited:

```text
app/javascript/dashboard/components/widgets/conversation/ConversationCard.vue
app/javascript/dashboard/components/widgets/conversation/contextMenu/Index.vue
```

Behaviors worth adapting:

- assignee and priority visible in list;
- labels/tags;
- unread state;
- context actions for read/unread, status, snooze, priority, label, agent/team, link/new tab.

Exclude SLA-specific UI.

## Message renderer/actions reference

Relevant components audited:

```text
app/javascript/dashboard/components-next/message/Message.vue
dashboard/modules/conversations/components/MessageContextMenu.vue
```

Behaviors worth adapting:

- dedicated renderer by message kind;
- reply-to visualization;
- copy/reply/context actions;
- message status display;
- structured image/file/audio/video/contact/location handling;
- unsupported fallback.

Translation is not a required Inbox 3 feature.

## WhatsApp templates reference

Relevant components audited:

```text
app/javascript/dashboard/components/widgets/conversation/whatsapp/WhatsAppTemplates/TemplatesPicker.vue
app/javascript/dashboard/components/widgets/conversation/whatsapp/WhatsAppTemplates/TemplateParser.vue
```

Behaviors worth adapting:

- search/sync approved templates;
- content preview;
- language/category/header/body/footer/buttons/media awareness;
- generated variable inputs and validation.

## Multi-agent reference behaviors

Adapt:

- assignee workflow;
- agent viewing/typing awareness;
- collision avoidance;
- internal notes and mentions.

Do not import Chatwoot's full enterprise routing/capacity/SLA model.

## Principle

Use Chatwoot to answer:

> What interaction quality should the inbox reach?

Do not use it to answer:

> What framework or product architecture must Orchestrator become?
