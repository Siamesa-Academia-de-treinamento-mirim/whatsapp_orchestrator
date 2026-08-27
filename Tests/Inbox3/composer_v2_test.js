'use strict';

const assert = require('assert');
const State = require('../../Assets/js/inbox/composer_state.js');
const Quick = require('../../Assets/js/inbox/composer_quick_replies.js');
const Clipboard = require('../../Assets/js/inbox/composer_clipboard.js');
const fs = require('fs');
const path = require('path');

function storage() {
  const values = new Map();
  return {
    get length() { return values.size; },
    key(index) { return Array.from(values.keys())[index] || null; },
    getItem(key) { return values.has(key) ? values.get(key) : null; },
    setItem(key, value) { values.set(key, String(value)); },
    removeItem(key) { values.delete(key); },
    values,
  };
}

let passed = 0;
function test(name, callback) {
  callback();
  passed += 1;
  console.log(`[OK] ${name}`);
}

test('reply and note state are isolated by conversation and mode', () => {
  const store = State.createStore({ actorId: 7, storage: storage() });
  store.setText(12, 'reply', 'resposta');
  store.setText(12, 'note', 'nota');
  store.setText(13, 'reply', 'outra conversa');
  assert.strictEqual(store.get(12, 'reply').text, 'resposta');
  assert.strictEqual(store.get(12, 'note').text, 'nota');
  assert.strictEqual(store.get(13, 'reply').text, 'outra conversa');
});

test('reply target is local-only and cancellation does not clear text', () => {
  const store = State.createStore({ actorId: 7, storage: storage() });
  store.setText(12, 'reply', 'texto');
  store.setReplyTarget(12, 'reply', { id: 44, author: 'Contato', preview: 'mensagem', providerMessageId: 'secret-external-id' });
  assert.deepStrictEqual(store.get(12, 'reply').replyTarget, { messageId: 44, author: 'Contato', preview: 'mensagem' });
  store.setReplyTarget(12, 'reply', null);
  assert.strictEqual(store.get(12, 'reply').text, 'texto');
  assert.strictEqual(store.get(12, 'reply').replyTarget, null);
});

test('drafts are scoped, restore safely, and never serialize attachments', () => {
  const firstStorage = storage();
  const first = State.createStore({ actorId: 7, storage: firstStorage, now: () => 1000 });
  first.setText(12, 'reply', 'draft');
  first.setAttachments(12, 'reply', [{ id: 'file-1', file: { name: 'secret' } }]);
  assert.strictEqual(first.saveDraft(12, 'reply'), true);
  const raw = firstStorage.getItem(first.draftKey(12, 'reply'));
  assert.ok(raw.includes('draft'));
  assert.ok(!raw.includes('file-1'));
  const restored = State.createStore({ actorId: 7, storage: firstStorage, now: () => 1000 });
  restored.restoreDraft(12, 'reply');
  assert.strictEqual(restored.get(12, 'reply').text, 'draft');
  const otherActor = State.createStore({ actorId: 8, storage: firstStorage });
  assert.notStrictEqual(otherActor.draftKey(12, 'reply'), first.draftKey(12, 'reply'));
});

test('draft hydration happens once and never overwrites dirty in-memory text', () => {
  const values = storage();
  const first = State.createStore({ actorId: 7, storage: values, now: () => 1000 });
  first.setText(12, 'reply', 'old-from-storage');
  first.saveDraft(12, 'reply');

  const restored = State.createStore({ actorId: 7, storage: values, now: () => 1000 });
  restored.restoreDraft(12, 'reply');
  restored.setText(12, 'reply', 'new-in-memory');
  values.setItem(restored.draftKey(12, 'reply'), JSON.stringify({
    version: 2, conversation_id: 12, mode: 'reply', text: 'stale-overwrite', reply_target: null, updated_at: 1000,
  }));
  restored.restoreDraft(12, 'reply');
  assert.strictEqual(restored.get(12, 'reply').text, 'new-in-memory');

  const missing = State.createStore({ actorId: 8, storage: values, now: () => 1000 });
  missing.restoreDraft(99, 'reply');
  values.setItem(missing.draftKey(99, 'reply'), JSON.stringify({
    version: 2, conversation_id: 99, mode: 'reply', text: 'late-storage', reply_target: null, updated_at: 1000,
  }));
  missing.restoreDraft(99, 'reply');
  assert.strictEqual(missing.get(99, 'reply').text, '');
});

test('mode and conversation switches can flush every dirty draft before debounce', () => {
  const values = storage();
  const store = State.createStore({ actorId: 7, storage: values, autosaveDelay: 60000 });
  store.setText(12, 'reply', 'reply A');
  store.setText(12, 'note', 'note A');
  store.setText(13, 'reply', 'reply B');
  assert.strictEqual(store.flushAll(), 3);
  assert.ok(values.getItem(store.draftKey(12, 'reply')).includes('reply A'));
  assert.ok(values.getItem(store.draftKey(12, 'note')).includes('note A'));
  assert.ok(values.getItem(store.draftKey(13, 'reply')).includes('reply B'));
});

test('send revision prevents late success from clearing newer text', () => {
  const store = State.createStore({ actorId: 7, storage: storage() });
  store.setText(12, 'reply', 'old');
  const snapshot = store.snapshot(12, 'reply');
  store.setText(12, 'reply', 'new');
  assert.strictEqual(store.commitText(snapshot), false);
  assert.strictEqual(store.get(12, 'reply').text, 'new');
  const current = store.snapshot(12, 'reply');
  assert.strictEqual(store.commitText(current), true);
  assert.strictEqual(store.get(12, 'reply').text, '');
});

test('send snapshot clears the visible draft immediately without losing recoverable content', () => {
  const store = State.createStore({ actorId: 7, storage: storage() });
  store.setText(12, 'reply', 'mensagem pendente');
  store.setReplyTarget(12, 'reply', { id: 44, author: 'Contato', preview: 'mensagem original' });
  const snapshot = store.snapshot(12, 'reply');
  assert.strictEqual(store.clearForSend(snapshot), true);
  assert.strictEqual(store.get(12, 'reply').text, '');
  assert.strictEqual(store.get(12, 'reply').replyTarget, null);
  assert.strictEqual(snapshot.text, 'mensagem pendente');
  assert.deepStrictEqual(snapshot.replyTarget, { messageId: 44, author: 'Contato', preview: 'mensagem original' });
  store.setText(12, 'reply', 'novo texto');
  assert.strictEqual(store.commitText(snapshot), false);
  assert.strictEqual(store.get(12, 'reply').text, 'novo texto');
});

test('media caption and reply are consumed only after confirmed success', () => {
  const store = State.createStore({ actorId: 7, storage: storage() });
  store.setText(12, 'reply', 'caption');
  store.setReplyTarget(12, 'reply', { id: 44, author: 'Contato', preview: 'mensagem' });
  const snapshot = store.snapshot(12, 'reply');
  assert.strictEqual(store.commitMedia(snapshot, { captionSent: false, replySent: false }), false);
  assert.strictEqual(store.get(12, 'reply').text, 'caption');
  assert.strictEqual(store.commitMedia(snapshot, { captionSent: true, replySent: true }), true);
  assert.strictEqual(store.get(12, 'reply').text, '');
  assert.strictEqual(store.get(12, 'reply').replyTarget, null);
});

test('quick replies filter shortcut/title/text and replace only the slash token', () => {
  const rows = [{ shortcut: '/ola', title: 'Saudacao', text: 'Ola {{contact.name}}' }, { shortcut: '/prazo', title: 'Prazo', text: 'Ate amanha' }];
  assert.strictEqual(Quick.filter(rows, 'saud').length, 1);
  const replacement = Quick.replaceSlashToken('antes /ola', 10, 'Ola Ana');
  assert.strictEqual(replacement.value, 'antes Ola Ana');
  assert.strictEqual(Quick.substitute('Oi {{contact.name}} {{unknown}}', { 'contact.name': 'Ana' }), 'Oi Ana {{unknown}}');
});

test('quick reply filtering always uses the complete source and supports zero-result recovery', () => {
  const source = [
    { shortcut: '/bol', title: 'Boleto', text: 'Boleto vencido' },
    { shortcut: '/prazo', title: 'Prazo', text: 'Ate amanha' },
  ];
  assert.strictEqual(Quick.filter(source, 'bol').length, 1);
  assert.strictEqual(Quick.filter(source, 'inexistente').length, 0);
  assert.strictEqual(Quick.filter(source, '').length, 2);
  assert.strictEqual(Quick.filter(source, 'pra').length, 1);
});

test('clipboard keeps native text insertion while extracting files from files and items', () => {
  const image = { name: 'image.png', size: 10, type: 'image/png' };
  const duplicate = { name: 'image.png', size: 10, type: 'image/png' };
  const fileItem = { kind: 'file', getAsFile: () => duplicate };
  const mixed = { files: [image], items: [fileItem], getData: () => 'texto colado' };
  assert.strictEqual(Clipboard.filesFromData(mixed).length, 1);
  assert.strictEqual(Clipboard.plainText(mixed), 'texto colado');
  assert.strictEqual(Clipboard.shouldPreventDefault(mixed), false);
  assert.strictEqual(Clipboard.shouldPreventDefault({ files: [image], items: [], getData: () => '' }), true);
  assert.strictEqual(Clipboard.filesFromData({ files: [], items: [{ kind: 'string' }] }).length, 0);
});

test('keyboard navigation is bounded and IME-safe contract remains explicit', () => {
  assert.strictEqual(Quick.keyboardIndex(3, -1, 'ArrowDown'), 0);
  assert.strictEqual(Quick.keyboardIndex(3, 0, 'ArrowUp'), 2);
  assert.ok(fs.readFileSync(path.join(__dirname, '../../Assets/js/inbox/composer.js'), 'utf8').includes('event.isComposing'));
});

console.log(`\n${passed} passed, 0 failed.`);
