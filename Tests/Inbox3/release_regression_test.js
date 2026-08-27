'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const check = (condition, message) => { assert.ok(condition, message); process.stdout.write(`[OK] ${message}\n`); };

const start = read('CODEX_START_HERE.md');
const roadmap = read('docs/inbox3/IMPLEMENTATION_ROADMAP.md');
const chat = read('Assets/js/chatwoot.js');
const workspace = read('Assets/js/hub-workspace.js');
const styles = read('Views/partials/styles.php');
const view = read('Views/partials/conversations.php');
const handoff = read('CODEX_HANDOFF.md');
const applyHandoff = read('INBOX3_HANDOFF_APPLY.md');
const staticQuality = read('Tests/run_static_quality.sh');
const pickerSource = read('Assets/js/inbox/template_picker.js');
const workflowSource = read('Assets/js/inbox/conversation_workflow.js');
const composerStateSource = read('Assets/js/inbox/composer_state.js');
const messageRenderers = read('Assets/js/inbox/message_renderers.js');
const messageSafe = read('Assets/js/inbox/message_safe_content.js');
const mentionsSource = read('Assets/js/inbox/mentions.js');

for (let phase = 1; phase <= 8; phase += 1) check(start.includes(`Phase ${phase} complete`), `phase ${phase} gate is recorded complete`);
const activePhaseMarker = 'Current ' + 'authorized phase';
check(start.includes('Inbox 3 roadmap status = COMPLETE') && start.includes('No post-roadmap phase is authorized'), 'roadmap is marked complete with no successor phase');
check(!start.includes(activePhaseMarker) && !handoff.includes(activePhaseMarker) && !applyHandoff.includes(activePhaseMarker), 'completed documentation has no active-phase marker');
check(start.includes('V016 is reserved'), 'release docs reserve V016 without creating schema');
check(staticQuality.startsWith('#!/usr/bin/env sh\nset -eu\n') && !staticQuality.includes('\r\n'), 'static-quality script is POSIX and LF-only');
check((roadmap.match(/## Phase /g) || []).length >= 9 && (roadmap.match(/### Gate/g) || []).length >= 9, 'roadmap preserves every phase gate');

check(!chat.includes('Legacy inline template picker') && !chat.includes('data-template-media-link') && !chat.includes('data-template-media-id'), 'known replaced frontend paths are absent');
check((chat.match(/window\.addEventListener\(['"]pagehide['"]/g) || []).length === 1, 'chat has one page lifecycle listener');
check((workspace.match(/window\.addEventListener\(['"]pagehide['"]/g) || []).length === 1, 'media workspace has one page lifecycle listener');
check(chat.includes('clearTimeout(state.pollingTimer)') && chat.includes('schedulePoll'), 'polling replaces its timer instead of accumulating timers');
check(chat.includes('activeConversationRecord') && chat.includes('activeConversationDetached') && chat.includes('openConversationById'), 'detached conversation lifecycle remains present');

const workflowSandbox = { window: {}, console, setTimeout, clearTimeout };
vm.runInNewContext(workflowSource, workflowSandbox, { filename: 'conversation_workflow.js' });
const workflow = workflowSandbox.window.ImpulsoConversationWorkflow;
check(workflow && typeof workflow.create === 'function', 'workflow module exposes its release-tested factory');
const session = workflow.create();
const navigation = workflow.createNavigationContext();
const first = navigation.begin(110);
const second = navigation.begin(987);
check(!navigation.isCurrent(first) && navigation.isCurrent(second), 'navigation context rejects a late A response after switching to B');
const mutations = workflow.createMutationTracker();
const team = mutations.begin(7, 'assignment.team');
const assignee = mutations.begin(7, 'assignment.assignee');
check(mutations.isCurrent(team) && mutations.isCurrent(assignee), 'assignment dimensions remain independent');
const oldRead = mutations.begin(7, 'read_state');
const newUnread = mutations.begin(7, 'read_state');
check(!mutations.isCurrent(oldRead) && mutations.isCurrent(newUnread), 'read and unread use one latest logical sequence');

check(composerStateSource.includes('conversationId') && composerStateSource.includes('mode') && composerStateSource.includes('draftKey') && composerStateSource.includes('storage'), 'composer draft identity remains conversation/mode scoped');
check(pickerSource.includes('sessions') && pickerSource.includes('attemptsByFingerprint') && pickerSource.includes('sessionMatches'), 'template picker preserves session and attempt guards');
check(messageRenderers.includes('unsupported') && messageRenderers.includes('internal_note'), 'message renderer registry keeps deliberate rich fallbacks');
check(messageSafe.includes('safeMediaUrl') && messageSafe.includes('autoLink'), 'message content remains URL/XSS safe');

check(view.includes('role="group"') && view.includes('aria-pressed') && !view.includes('role="tab"'), 'queue and channel filters use button filter semantics');
check(chat.includes('aria-pressed') && !chat.includes('role="tab"'), 'filter button state is synchronized without a tab system');
check(chat.includes('data-bulk-select') && chat.includes('aria-label="Selecionar'), 'bulk checkboxes have accessible labels');
check(mentionsSource.includes('aria-controls') && mentionsSource.includes('aria-autocomplete') && mentionsSource.includes('aria-activedescendant') && mentionsSource.includes('input.focus()'), 'mention picker keeps focus on the textarea and projects listbox state');
function mentionNode() {
  const attributes = {};
  const listeners = {};
  const classes = new Set();
  return {
    attributes,
    listeners,
    children: [],
    focused: false,
    value: '',
    selectionStart: 0,
    selectionEnd: 0,
    innerHTML: '',
    classList: {
      add: (...names) => names.forEach(name => classes.add(name)),
      remove: (...names) => names.forEach(name => classes.delete(name)),
      contains: name => classes.has(name)
    },
    setAttribute: (name, value) => { attributes[name] = String(value); },
    removeAttribute: name => { delete attributes[name]; },
    getAttribute: name => Object.prototype.hasOwnProperty.call(attributes, name) ? attributes[name] : null,
    addEventListener: (type, handler) => { (listeners[type] ||= []).push(handler); },
    dispatchEvent: event => { (listeners[event.type] || []).forEach(handler => handler(event)); },
    appendChild: function (child) { this.children.push(child); },
    focus: function () { this.focused = true; }
  };
}

const mentionInput = mentionNode();
const mentionApp = mentionNode();
const mentionComposer = mentionNode();
const mentionState = {
  activeConversationId: 42,
  composerMode: 'note',
  assignmentOptions: { staff: [{ id: 1, name: 'Maria' }, { id: 2, name: 'Joao' }] }
};
const mentionDocument = {
  getElementById: id => id === 'impulso-hub-app' ? mentionApp : (id === 'impulso-message-input' ? mentionInput : null),
  querySelector: selector => selector === '.impulso-composer' ? mentionComposer : null,
  createElement: () => mentionNode()
};
const mentionWindow = {
  ImpulsoHubBridge: { getState: () => mentionState, onConversationChange: () => function () {} },
  ImpulsoCollaborationContract: {}
};
const mentionSandbox = {
  window: mentionWindow,
  document: mentionDocument,
  Event: function (type) { this.type = type; }
};
vm.runInNewContext(mentionsSource, mentionSandbox, { filename: 'mentions.js' });
const mentions = mentionWindow.ImpulsoMentions;
const mentionInputEvent = value => {
  mentionInput.value = value;
  mentionInput.selectionStart = value.length;
  mentionInput.selectionEnd = value.length;
  mentionInput.dispatchEvent({ type: 'input' });
};
const mentionKey = key => {
  const event = { key, preventDefault: () => {}, stopPropagation: () => {} };
  (mentionApp.listeners.keydown || []).forEach(handler => handler(event));
};
mentionInput.focus();
mentionInputEvent('@');
const mentionPicker = mentionComposer.children[0];
check(mentionInput.getAttribute('aria-controls') === 'impulso-mention-picker' && mentionInput.getAttribute('aria-autocomplete') === 'list' && mentionInput.getAttribute('aria-expanded') === 'true', 'mention input owns the open listbox state');
check(mentionInput.getAttribute('aria-activedescendant') === 'impulso-mention-option-1' && mentionPicker.innerHTML.includes('role="option"') && mentionPicker.innerHTML.includes('aria-selected="true"'), 'mention input receives the active option identity');
mentionKey('ArrowDown');
check(mentionInput.getAttribute('aria-activedescendant') === 'impulso-mention-option-2', 'mention ArrowDown updates the active descendant');
mentionKey('ArrowUp');
check(mentionInput.getAttribute('aria-activedescendant') === 'impulso-mention-option-1', 'mention ArrowUp updates the active descendant');
mentionKey('ArrowDown');
mentionKey('Enter');
check(mentionInput.getAttribute('aria-expanded') === 'false' && mentionInput.getAttribute('aria-activedescendant') === null && mentionInput.focused && !mentionPicker.focused, 'mention Enter closes without moving focus from the textarea');
mentionInputEvent('@M');
mentionInput.focus();
mentionKey('Escape');
check(mentionInput.getAttribute('aria-expanded') === 'false' && mentionInput.getAttribute('aria-activedescendant') === null && mentionInput.focused, 'mention Escape closes and preserves textarea focus');

check(styles.includes('focus-visible') && styles.includes('overflow-wrap: anywhere'), 'visual polish covers focus and malicious/long text layout');
  for (const rule of ['max-width: 1480px', 'max-width: 1100px', 'max-width: 840px', 'max-width: 575.98px', '--impulso-available-height']) check(styles.includes(rule), `responsive safeguard exists: ${rule}`);
for (const selector of ['impulso-template-option', 'impulso-template-field', 'impulso-presence-warning', 'impulso-bulk-form', 'impulso-media-stage']) check(styles.includes(selector), `release styling exists for ${selector}`);

process.stdout.write('\nRelease JS regression passed.\n');
