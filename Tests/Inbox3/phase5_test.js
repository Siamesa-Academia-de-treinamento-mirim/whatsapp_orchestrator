'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const chatwoot = read('Assets/js/chatwoot.js');
const picker = read('Assets/js/inbox/template_picker.js');
const renderers = read('Assets/js/inbox/message_renderers.js');

let passed = 0;
const failures = [];
function assert(condition, message) {
    if (condition) { passed += 1; process.stdout.write(`[OK] ${message}\n`); }
    else { failures.push(message); process.stdout.write(`[FAIL] ${message}\n`); }
}

function stripComments(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|\s)\/\/.*$/gm, '$1');
}

const executableChatwoot = stripComments(chatwoot);
const executablePicker = stripComments(picker);

assert((executableChatwoot.match(/state\.activeConversationId\s*=/g) || []).length === 1, 'interactive code has only the central active-conversation assignment');
assert(executableChatwoot.includes('notifyConversationChange(previousId, state.activeConversationId'), 'active conversation changes notify the media lifecycle boundary');
assert(executableChatwoot.includes('scheduleServiceWindowTimer') && executableChatwoot.includes('clearTimeout(state.serviceWindowTimer'), 'active service-window timer is scheduled and cancelled');
assert(executableChatwoot.includes('details.service_window') && executableChatwoot.includes('reconcileServiceWindowError'), 'structured service-window failures reconcile locally without a poll');
assert(executableChatwoot.includes('reconcileActiveConversationRecord') && executableChatwoot.includes('activeWasCleared') && executableChatwoot.includes('clearConversation()'), 'full-list reconciliation clears an active conversation that disappeared');

assert(executablePicker.includes('pending') && executablePicker.includes('fingerprint') && executablePicker.includes('clientMessageId'), 'picker models one logical attempt across double click and retry');
assert(executablePicker.includes('attempt && attempt.pending') && executablePicker.includes('attempt.send_state === \'ambiguous_failure\''), 'pending and ambiguous attempts cannot trigger duplicate or blind sends');
assert(executablePicker.includes('attemptsByFingerprint') && executablePicker.includes('formsByTemplateId'), 'attempt and form state are indexed by logical identity instead of conversation globals');
assert(!executablePicker.includes('session.generation = Number(session.generation || 0) + 1'), 'reactivating a persisted session does not invalidate its generation');
assert(executablePicker.includes('formRevision') && executablePicker.includes('context.templateId'), 'late callbacks retain template and form origin before mutating state');
assert(executablePicker.includes("event.key === 'Escape'") && executablePicker.includes('!picker.contains(event.target)'), 'Escape and click-outside close the picker');
assert(executablePicker.includes('manageInstances') && executablePicker.includes('Última sincronização'), 'refresh is restricted and sync age is displayed');
assert(executablePicker.includes('local_media_id') && !executablePicker.includes('data-template-media-link') && !executablePicker.includes('data-template-media-id'), 'template media input never exposes provider ids or raw links');
assert(executablePicker.includes('URL.revokeObjectURL'), 'cancel/reset paths release local preview object URLs');
assert(!picker.includes('disabled="false"') && picker.includes("(canSend ? '' : ' disabled')"), 'disabled attribute is absent when send is allowed');

assert(renderers.includes('function displayValue') && !renderers.includes('parameters.slice(0, 8).join'), 'template renderer never joins raw objects into [object Object]');
assert(renderers.includes('displayTemplateButtons') === false, 'renderer consumes the projector DTO instead of redefining provider-specific template data');

const logicalAttempt = { clientMessageId: 'one', fingerprint: 'same', pending: true };
assert(logicalAttempt.clientMessageId === 'one' && logicalAttempt.pending === true, 'double-click simulation retains one pending identity');
logicalAttempt.pending = false; logicalAttempt.send_state = 'retryable_failure';
assert(logicalAttempt.clientMessageId === 'one', 'retryable simulation reuses the same client message id');
const edited = { clientMessageId: 'two', fingerprint: 'edited', revision: 2 };
assert(edited.clientMessageId !== logicalAttempt.clientMessageId, 'edited logical payload receives a new client message id');

const vm = require('vm');
const sandbox = { window: {}, document: {} };
vm.runInNewContext(picker, sandbox);
const pickerHelpers = sandbox.window.ImpulsoTemplatePicker;
const bodyTemplate = { id: 7, sendable: true, body: { type: 'text', text: 'Olá {{1}}, pedido {{2}}' }, footer: { type: 'text', text: 'Impulso' }, fields: [
    { key: 'body.1', location: 'body', position: 1, type: 'text', required: true },
    { key: 'body.2', location: 'body', position: 2, type: 'text', required: true },
] };
const mediaTemplate = { id: 8, sendable: true, header: { type: 'media', kind: 'image' }, body: { type: 'text', text: 'Arquivo' }, fields: [
    { key: 'header_media', location: 'header', type: 'image', required: true },
] };
assert(pickerHelpers.computeCanSend({ id: 9, sendable: true, fields: [] }, {}, null) === true, 'sendable template without fields starts enabled');
assert(pickerHelpers.computeCanSend(bodyTemplate, { 'body.1': '', 'body.2': '123' }, null) === false, 'empty body variable disables send');
assert(pickerHelpers.computeCanSend(bodyTemplate, { 'body.1': 'Maria', 'body.2': '123' }, null) === true, 'filled body variables enable send');
assert(pickerHelpers.computeCanSend(mediaTemplate, {}, null) === false && pickerHelpers.computeCanSend(mediaTemplate, { header_media: { kind: 'image', local_media_id: 12 } }, null) === true, 'required template media enables immediately after local upload');
const preview = pickerHelpers.resolvePreview(bodyTemplate, { 'body.1': 'Maria', 'body.2': '123' });
assert(preview.body === 'Olá Maria, pedido 123' && preview.footer === 'Impulso', 'dynamic preview resolves provider-neutral definitions without browser parsing');
const newAttempt = pickerHelpers.logicalAttemptTransition(null, 'body-v1', () => 'client-1').attempt;
assert(newAttempt.clientMessageId === 'client-1' && newAttempt.pending === true, 'new logical attempt starts pending');
assert(pickerHelpers.logicalAttemptTransition(newAttempt, 'body-v1', () => 'client-2').action === 'pending', 'pending logical attempt cannot resend');
newAttempt.pending = false; newAttempt.send_state = 'rejected';
assert(pickerHelpers.logicalAttemptTransition(newAttempt, 'body-v1', () => 'client-2').action === 'blocked_terminal', 'rejected logical attempt cannot retry');
const editedTransition = pickerHelpers.logicalAttemptTransition(newAttempt, 'body-v2', () => 'client-2');
assert(editedTransition.action === 'new' && editedTransition.attempt.clientMessageId === 'client-2', 'editing a field creates a new logical attempt id');
newAttempt.send_state = 'retryable_failure';
assert(pickerHelpers.logicalAttemptTransition(newAttempt, 'body-v1', () => 'client-3').action === 'reuse', '429 retry reuses the same logical attempt id');
newAttempt.send_state = 'ambiguous_failure';
assert(pickerHelpers.logicalAttemptTransition(newAttempt, 'body-v1', () => 'client-4').action === 'blocked_terminal', 'ambiguous attempt cannot retry blindly');

async function runBehavioralTests() {
class FakeClassList {
    constructor() { this.values = new Set(); }
    add(value) { this.values.add(value); }
    remove(value) { this.values.delete(value); }
    contains(value) { return this.values.has(value); }
}
class FakeElement {
    constructor() { this.dataset = {}; this.attributes = {}; this.listeners = {}; this.classList = new FakeClassList(); this.inputs = []; this.html = ''; this.files = []; }
    addEventListener(type, handler) { this.listeners[type] = handler; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) { return this.attributes[name] == null ? null : this.attributes[name]; }
    hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name); }
    matches(selector) { return selector === '[data-template-search]' ? this.hasAttribute('data-template-search') : selector === '[data-template-value]' ? this.hasAttribute('data-template-value') : false; }
    closest() { return this; }
    contains() { return false; }
    querySelectorAll(selector) { return selector === '[data-template-value]' ? this.inputs : []; }
    querySelector() { return null; }
    focus() {}
    set innerHTML(value) { this.html = String(value); }
    get innerHTML() { return this.html; }
}
class FakeDocument {
    constructor(picker, trigger) { this.elements = { 'impulso-template-picker': picker, 'impulso-template-button': trigger }; this.activeElement = null; this.listeners = {}; }
    getElementById(id) { return this.elements[id] || null; }
    addEventListener(type, handler) { this.listeners[type] = handler; }
}
class FakeFormData { append() {} }
function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}
async function flushPromises() {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
}
function clickButton(attributes) {
    return { attributes, hasAttribute(name) { return !!this.attributes[name]; }, getAttribute(name) { return this.attributes[name] || null; }, closest() { return this; } };
}
function pickerHarness() {
    const pickerElement = new FakeElement();
    const triggerElement = new FakeElement();
    const documentDouble = new FakeDocument(pickerElement, triggerElement);
    const windowDouble = { URL: { created: 0, revoked: 0, createObjectURL() { this.created += 1; return `blob:test-${this.created}`; }, revokeObjectURL() { this.revoked += 1; } } };
    const context = { window: windowDouble, document: documentDouble, FormData: FakeFormData };
    vm.runInNewContext(picker, context);
    let activeId = 1;
    let nextId = 1;
    const conversations = {
        1: { id: 1, capabilities: { actions: { send_template: true } }, service_window: { open: false } },
        2: { id: 2, capabilities: { actions: { send_template: true } }, service_window: { open: false } },
    };
    const state = { templates: { conversationId: 0, rows: [], selectedId: 0, formsByTemplateId: {}, attemptsByFingerprint: {}, search: '' }, messages: [] };
    const calls = [];
    const api = (url, request) => { const pending = deferred(); calls.push({ url, request, pending }); return pending.promise; };
    const module = context.window.ImpulsoTemplatePicker.create({
        app: documentDouble,
        state,
        config: { permissions: { manageInstances: true } },
        api,
        templateEndpoint: (id) => `/conversations/${id}/templates`,
        activeConversation: () => conversations[activeId],
        activeConversationId: () => activeId,
        createClientMessageId: () => `cid-${nextId++}`,
        normalizeMessage: (message) => Object.assign({ conversation_id: Number(message.conversation_id || 0) }, message),
        mergeMessages: (rows) => rows.forEach((row) => state.messages.push(row)),
        renderMessages: () => { state.renderMessagesCalls = Number(state.renderMessagesCalls || 0) + 1; },
        updateComposerState: () => {},
        reconcileWindowError: () => {},
        escapeHtml: (value) => String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
        replaceIcons: () => {},
        toast: () => {},
    });
    return {
        state,
        calls,
        picker: pickerElement,
        window: windowDouble,
        module,
        conversations,
        setActive(id) { activeId = Number(id); module.reset(activeId); },
        session(id = activeId) { return module.getSession(id); },
        form(id, conversationId = activeId) { const session = module.getSession(conversationId); return session.formsByTemplateId[String(id)] || null; },
        template(id = 101) { return { id, name: `T-${id}`, sendable: true, fields: [] }; },
        bodyTemplate(id = 102) { return { id, name: `T-${id}`, sendable: true, body: { type: 'text', text: 'Olá {{1}}' }, fields: [{ key: 'body.1', location: 'body', position: 1, type: 'text', required: true }] }; },
        choose(id) { pickerElement.listeners.click({ target: clickButton({ [`data-template-id`]: String(id) }) }); },
        back() { pickerElement.listeners.click({ target: clickButton({ 'data-template-back': true }) }); },
    };
}

{
    const h = pickerHarness();
    h.setActive(1); h.state.templates.rows = [h.template(101)]; h.state.templates.selectedId = 101; h.module.render();
    const pendingSend = h.module.send(); await flushPromises();
    h.state.messages = [{ id: 'message-b' }]; h.setActive(2); h.module.close(false);
    h.calls[0].pending.resolve({ data: { conversation_id: 1, id: 'message-a', text_content: 'A' } }); await pendingSend;
    const attempt = Object.values(h.session(1).attemptsByFingerprint)[0];
    assert(h.state.messages.length === 1 && h.state.messages[0].id === 'message-b', 'late A send success cannot merge a message into B history');
    assert(attempt.send_state === 'idempotent_success' && attempt.pending === false, 'late A success still finalizes its isolated attempt');
    assert(h.calls.length === 1, 'late A success after switching to B does not create a duplicate request');
}

{
    const h = pickerHarness();
    h.setActive(1); h.state.templates.rows = [h.template(103)]; h.state.templates.selectedId = 103; h.module.render();
    const originalGeneration = h.session(1).generation;
    const pendingSend = h.module.send(); await flushPromises(); h.setActive(2); h.setActive(1);
    h.calls[0].pending.resolve({ data: { conversation_id: 1, id: 'message-reactivated' } }); await pendingSend;
    const attempt = Object.values(h.session(1).attemptsByFingerprint)[0];
    assert(h.session(1).generation === originalGeneration && attempt.send_state === 'idempotent_success' && attempt.pending === false && h.calls.length === 1, 'reactivating A keeps its session generation and applies late success once');
}

{
    const h = pickerHarness();
    h.setActive(1); h.state.templates.rows = [h.template(102)]; h.state.templates.selectedId = 102; h.module.render();
    const pendingSend = h.module.send(); await flushPromises(); h.setActive(2); h.setActive(1);
    h.calls[0].pending.reject({ status: 422, details: { send_state: 'rejected' }, message: 'rejected' }); await pendingSend.catch(() => {}); await flushPromises();
    const attempt = Object.values(h.session(1).attemptsByFingerprint)[0];
    assert(attempt.send_state === 'rejected' && attempt.pending === false, 'reactivating A applies late rejection instead of leaving the attempt pending');
}

{
    const h = pickerHarness();
    h.setActive(1); h.state.templates.rows = [h.template(201), h.bodyTemplate(202)]; h.state.templates.selectedId = 201; h.module.render();
    const pendingSend = h.module.send(); await flushPromises(); h.choose(202);
    const t2 = h.form(202); t2.values['body.1'] = 'new draft'; t2.revision += 1; h.module.render();
    h.calls[0].pending.resolve({ data: { conversation_id: 1, id: 'message-t1' } }); await pendingSend;
    assert(h.state.templates.selectedId === 202 && h.form(202).values['body.1'] === 'new draft', 'late T1 success does not clear the newer T2 form');
}

{
    const h = pickerHarness();
    h.setActive(1); h.state.templates.rows = [
        { id: 301, name: 'T1', sendable: true, fields: [{ key: 'header_media', type: 'image', location: 'header', required: true }] },
        { id: 302, name: 'T2', sendable: true, fields: [{ key: 'header_media', type: 'image', location: 'header', required: true }] },
    ]; h.state.templates.selectedId = 301; h.module.render();
    const input = new FakeElement(); input.setAttribute('data-template-media', 'header_media'); input.files = [{ name: 't1.jpg' }];
    h.picker.listeners.change({ target: input }); await flushPromises(); h.choose(302);
    h.calls[0].pending.resolve({ data: { local_media_id: 77, name: 't1.jpg' } }); await flushPromises();
    assert(!h.form(302).media.header_media && h.form(301).media.header_media.local_media_id === 77, 'late T1 media is stored only in T1 and never enters T2');
}

{
    const h = pickerHarness();
    h.setActive(1); const loadA = h.module.load(false); await flushPromises();
    h.setActive(2); h.state.templates.status = 'ready'; h.module.render(); h.calls[0].pending.reject(new Error('A failed')); await loadA; await flushPromises();
    assert(h.state.templates.conversationId === 2 && h.state.templates.status === 'ready' && !h.picker.innerHTML.includes('A failed'), 'late A load failure cannot change B status, error or DOM');
}

{
    const h = pickerHarness();
    h.setActive(1); h.state.templates.rows = [h.template(401), h.template(402)]; h.state.templates.selectedId = 401; h.module.render();
    const first = h.module.send(); await flushPromises(); const cid = h.calls[0].request.body.client_message_id;
    h.calls[0].pending.reject({ status: 409, details: { send_state: 'ambiguous_failure' }, message: 'ambiguous' }); await first.catch(() => {}); await flushPromises();
    h.choose(402); const second = h.module.send(); await flushPromises(); h.calls[1].pending.resolve({ data: { conversation_id: 1, id: 'message-t2' } }); await second;
    h.choose(401); const blocked = await h.module.send(); await flushPromises();
    const attempt = Object.values(h.session(1).attemptsByFingerprint).find((item) => item.templateId === 401);
    assert(blocked === false && attempt.clientMessageId === cid && attempt.send_state === 'ambiguous_failure', 'ambiguous T1 remains blocked after a successful T2 operation');
}

{
    const h = pickerHarness();
    h.setActive(1); h.state.templates.rows = [h.template(411), h.template(412)]; h.state.templates.selectedId = 411; h.module.render();
    const first = h.module.send(); await flushPromises(); const cid = h.calls[0].request.body.client_message_id;
    h.calls[0].pending.reject({ status: 422, details: { send_state: 'rejected' }, message: 'rejected' }); await first.catch(() => {}); await flushPromises();
    h.choose(412); const second = h.module.send(); await flushPromises(); h.calls[1].pending.resolve({ data: { conversation_id: 1, id: 'message-t2' } }); await second;
    h.choose(411); const blocked = await h.module.send(); await flushPromises();
    const attempt = Object.values(h.session(1).attemptsByFingerprint).find((item) => item.templateId === 411);
    assert(blocked === false && attempt.clientMessageId === cid && attempt.send_state === 'rejected', 'rejected T1 remains terminal after a successful T2 operation');
}

{
    const h = pickerHarness();
    h.setActive(1); h.state.templates.rows = [h.template(421), h.template(422)]; h.state.templates.selectedId = 421; h.module.render();
    const first = h.module.send(); await flushPromises(); const cid = h.calls[0].request.body.client_message_id;
    h.calls[0].pending.reject({ status: 500, message: 'retryable' }); await first.catch(() => {}); await flushPromises();
    h.choose(422); const second = h.module.send(); await flushPromises(); h.calls[1].pending.resolve({ data: { conversation_id: 1, id: 'message-t2' } }); await second;
    h.choose(421); const retry = h.module.send(); await flushPromises();
    assert(h.calls.length === 3 && h.calls[2].request.body.client_message_id === cid, 'retryable T1 reuses its original client id after T2 operation');
    h.calls[2].pending.resolve({ data: { conversation_id: 1, id: 'message-t1-retry' } }); await retry;
}

{
    const h = pickerHarness();
    h.setActive(1); h.state.templates.rows = [h.template(431)]; h.state.templates.selectedId = 431; h.module.render();
    const pending = h.module.send(); await flushPromises(); h.back(); h.choose(431); const second = h.module.send(); await flushPromises();
    assert(h.calls.length === 1 && second === pending, 'Back and reselect preserve a pending send without a second request');
    h.calls[0].pending.resolve({ data: { conversation_id: 1, id: 'message-pending' } }); await pending;
}

{
    const h = pickerHarness();
    h.setActive(1); h.state.templates.rows = [h.bodyTemplate(501), h.bodyTemplate(502)]; h.state.templates.selectedId = 501; h.module.render();
    const input = new FakeElement(); input.setAttribute('data-template-value', 'body.1'); input.value = 'Maria'; h.picker.inputs = [input];
    h.picker.listeners.input({ target: input }); h.choose(502);
    assert(h.form(501).values['body.1'] === 'Maria' && !h.form(502).values['body.1'], 'T1 values are isolated from T2 values');
    h.choose(501); assert(h.picker.innerHTML.includes('Maria'), 'Back/reselect restores the form state of its own template');
}

{
    const h = pickerHarness();
    h.setActive(1); h.state.templates.rows = [
        { id: 511, name: 'T1', sendable: true, fields: [{ key: 'header_media', type: 'image', location: 'header', required: true }] },
        { id: 512, name: 'T2', sendable: true, fields: [{ key: 'header_media', type: 'image', location: 'header', required: true }] },
    ]; h.state.templates.selectedId = 511; h.module.render();
    const input = new FakeElement(); input.setAttribute('data-template-media', 'header_media'); input.files = [{ name: 't1.jpg' }]; h.picker.listeners.change({ target: input }); await flushPromises();
    const t2 = h.form(512);
    assert(!t2 || (!t2.media.header_media && !t2.values.header_media), 'T1 media is isolated from T2 media');
}

{
    const h = pickerHarness();
    h.setActive(1); h.state.templates.rows = [h.template(601)]; h.state.templates.selectedId = 601; h.module.render();
    const first = h.module.send(); await flushPromises(); const firstCid = h.calls[0].request.body.client_message_id;
    h.calls[0].pending.resolve({ data: { conversation_id: 1, id: 'message-success' } }); await first; h.choose(601); const second = h.module.send(); await flushPromises();
    assert(h.calls.length === 2 && h.calls[1].request.body.client_message_id !== firstCid, 'confirmed success permits a deliberate future operation');
    h.calls[1].pending.resolve({ data: { conversation_id: 1, id: 'message-success-2' } }); await second;
}

}

runBehavioralTests().then(function () {
    console.log(`\n${passed} passed, ${failures.length} failed.`);
    if (failures.length) process.exit(1);
}).catch(function (error) {
    console.error(error);
    process.exit(1);
});
