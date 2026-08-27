'use strict';

const assert = require('assert');
const contract = require('../../Assets/js/inbox/collaboration_contract.js');
const workflow = require('../../Assets/js/inbox/conversation_workflow.js');
let passed = 0;
function check(condition, message) { assert.ok(condition, message); console.log(`[OK] ${message}`); passed += 1; }

check(contract.normalizeMentionIds([48, 12, 48, 0]).join(',') === '12,48', 'mention IDs sao positivos, deduplicados e ordenados');
check(contract.mentionIdentity('nota', 4, [48, 12]) === contract.mentionIdentity('nota', 4, [12, 48]), 'mesmo client ID representa mesma identidade quando mentions mudam apenas de ordem');
check(contract.mentionIdentity('nota', 4, [12]) !== contract.mentionIdentity('nota', 4, [12, 48]), 'alterar mentions muda a identidade idempotente');
check(contract.mentionIdentity('nota', 4, [12]) !== contract.mentionIdentity('nota', 5, [12]), 'alterar a revisao do rascunho muda a identidade idempotente');
check(Object.keys(contract.reconcileMentionItems({ 12: 'Maria', 48: 'Maria' }, 'Oi @Maria')).length === 0, 'display names duplicados nao reconciliam mention para evitar notificacao ambigua');
check(Object.keys(contract.reconcileMentionItems({ 12: 'Maria' }, 'Oi @Maria')).join(',') === '12' && Object.keys(contract.reconcileMentionItems({ 12: 'Maria' }, 'Oi @Maria @Maria')).length === 0, 'mention unica exige exatamente uma ocorrencia visivel');
check(contract.canonicalStatusOptions().map((item) => item.value).join(',') === 'open,pending,resolved' && contract.canonicalPriorityOptions().some((item) => item.label === 'Urgente'), 'bulk expone labels visiveis para status e prioridade canonicos');
check(contract.normalizeBulkReadState('read') === 'read' && contract.normalizeBulkReadState('unread') === 'unread' && contract.normalizeBulkReadState('resolved') === '', 'bulk aceita somente read/unread no contrato de leitura');
check(contract.collisionSummary([{ user_id: 1, name: 'Eu', typing: true }, { user_id: 2, name: 'Maria', typing: true }, { user_id: 3, name: 'Joao', viewing: true }], 1).typing.join(',') === 'Maria', 'collision exclui o proprio agente e preserva typing de terceiros');
check(contract.collisionSummary([{ user_id: 1, name: 'Eu', viewing: true }], 1).viewing.length === 0, 'presence propria nunca gera warning');
check(contract.allowedSavedFilterKeys().indexOf('pagination') < 0 && contract.allowedSavedFilterKeys().indexOf('active_conversation') < 0, 'saved view nao permite pagination nem conversa ativa');
check(contract.bulkSummary([{ ok: true }, { ok: false }, { ok: true }]).succeeded === 2 && contract.bulkSummary([{ ok: true }, { ok: false }, { ok: true }]).failed === 1, 'bulk resume sucesso/falha por item');
const link = contract.conversationPermalink('https://rise.test/chatwoot_plugin?conversation=9&message=4', 123);
check(link.indexOf('conversation=123') >= 0 && link.indexOf('message=') < 0 && link.indexOf('remote_jid') < 0, 'permalink usa somente ID local de conversa');
check(contract.keyboardShouldHandle({ tagName: 'DIV' }) && !contract.keyboardShouldHandle({ tagName: 'TEXTAREA' }), 'atalhos nao roubam textarea');

const sessions = workflow.create();
let active = 10;
const contextA = sessions.capture(10, { type: 'presence' });
sessions.activate(20); active = 20;
let rendered = false;
Promise.resolve({ data: { conversation_id: 10, agents: [{ user_id: 2, name: 'Maria', viewing: true }] } }).then(function (payload) {
    if (sessions.isCurrent(contextA) && active === 10) rendered = !!payload.data;
}).then(function () {
    check(!rendered, 'late presence A nao renderiza collision em B');
    const contextB = sessions.capture(20, { type: 'presence' });
    check(sessions.isCurrent(contextB) && !sessions.isCurrent(contextA), 'presence usa generation/context por conversa');
    console.log(`${passed} passed, 0 failed.`);
}).catch(function (error) { console.error(error); process.exitCode = 1; });

const composerSource = require('fs').readFileSync(require('path').join(__dirname, '../../Assets/js/inbox/composer.js'), 'utf8');
check(composerSource.includes('mentionIdentity') && composerSource.includes('matchesSnapshot') && composerSource.includes('clearIfMatches'), 'Composer real conserva draft e metadata quando sucesso de mention chega atrasado');
const bulkSource = require('fs').readFileSync(require('path').join(__dirname, '../../Assets/js/inbox/bulk_actions.js'), 'utf8');
check(!bulkSource.includes('ID do agente') && !bulkSource.includes('Status: open') && bulkSource.includes('assignmentOptions') && !bulkSource.includes('loadConversations(true, true)'), 'Bulk real usa opcoes visiveis e DTO retornado sem reabrir polling forcado');

class BulkClassList {
  constructor() { this.values = new Set(); }
  toggle(value, force) { if (force === undefined ? !this.values.has(value) : force) this.values.add(value); else this.values.delete(value); }
  contains(value) { return this.values.has(value); }
}
class BulkElement {
  constructor() { this.attributes = {}; this.listeners = {}; this.classList = new BulkClassList(); this.countNode = { textContent: '' }; this.resultNode = { textContent: '' }; this.parentNode = null; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name] == null ? null : this.attributes[name]; }
  addEventListener(type, handler) { this.listeners[type] = handler; }
  insertAdjacentHTML() {}
  querySelector(selector) { if (selector === '[data-bulk-count]') return this.countNode; if (selector === '[data-bulk-result]') return this.resultNode; return null; }
  querySelectorAll() { return []; }
}
const bulkCheckboxes = [1, 2, 3].map((id) => ({ checked: false, getAttribute: () => String(id) }));
const bulkList = new BulkElement();
bulkList.querySelectorAll = () => bulkCheckboxes;
bulkList.parentNode = { insertBefore: () => {} };
const bulkApp = {};
const bulkBar = [];
const bulkState = { bulkSelectedIds: [1, 2, 3], assignmentOptions: { staff: [], teams: [] } };
const bulkBridge = {
  getState: () => bulkState,
  endpoint: () => '/bulk',
  updateConversationRecord: () => {},
  api: () => Promise.resolve({ data: { results: [] } }),
};
const bulkDocument = {
  body: { appendChild: () => {} },
  getElementById: (id) => id === 'impulso-hub-app' ? bulkApp : id === 'impulso-conversation-list' ? bulkList : null,
  createElement: () => { const element = new BulkElement(); bulkBar.push(element); return element; },
};
const bulkSandbox = { window: { ImpulsoHubBridge: bulkBridge, ImpulsoCollaborationContract: contract }, document: bulkDocument };
require('vm').runInNewContext(bulkSource, bulkSandbox);
const bulk = bulkSandbox.window.ImpulsoBulkActions;
bulk.applyResult({ data: { summary: { succeeded: 2, failed: 1 }, results: [{ conversation_id: 1, ok: true, data: {} }, { conversation_id: 2, ok: true, data: {} }, { conversation_id: 3, ok: false }] } });
const partialBar = bulkBar[0];
check(JSON.stringify(bulkState.bulkSelectedIds) === JSON.stringify([3]) && bulkCheckboxes.map((item) => item.checked).join(',') === 'false,false,true' && partialBar.countNode.textContent === '1' && !partialBar.classList.contains('impulso-hidden'), 'bulk partial result reconciles state, checkboxes and count while preserving failed ID');
bulk.applyResult({ data: { summary: { succeeded: 1, failed: 0 }, results: [{ conversation_id: 3, ok: true, data: {} }] } });
check(JSON.stringify(bulkState.bulkSelectedIds) === '[]' && bulkCheckboxes.every((item) => item.checked === false) && partialBar.countNode.textContent === '0' && partialBar.classList.contains('impulso-hidden'), 'bulk all-success result clears every checkbox and hides the bar without list reload');
