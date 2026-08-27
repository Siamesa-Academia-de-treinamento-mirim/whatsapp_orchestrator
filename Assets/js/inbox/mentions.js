(function (window, document) {
    'use strict';
    var bridge = window.ImpulsoHubBridge;
    var app = document.getElementById('impulso-hub-app');
    if (!bridge || !app) return;
    var state = bridge.getState ? bridge.getState() : {};
    var contract = window.ImpulsoCollaborationContract || {};
    var records = {};
    var picker = null;
    var visible = [];
    var index = 0;

    function id(value) { var result = Number(value || 0); return isFinite(result) && result > 0 ? result : 0; }
    function esc(value) { return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }
    function currentId(value) { return id(value || state.activeConversationId); }
    function record(conversationId) { var key = String(currentId(conversationId)); if (!records[key]) records[key] = { items: {}, revision: 0 }; records[key].items = records[key].items || {}; records[key].revision = Number(records[key].revision || 0); return records[key]; }
    function field() { return document.getElementById('impulso-message-input'); }
    function isNote() { return state.composerMode === 'note'; }
    function staff() {
        var rows = state.assignmentOptions && Array.isArray(state.assignmentOptions.staff) ? state.assignmentOptions.staff : [];
        return rows.filter(function (item) { return item && item.active !== false && item.status !== 'inactive'; });
    }
    function ensurePicker() {
        if (picker) return picker;
        picker = document.createElement('div');
        picker.id = 'impulso-mention-picker';
        picker.className = 'impulso-composer-popover impulso-hidden';
        picker.setAttribute('role', 'listbox');
        picker.setAttribute('aria-label', 'Mencionar agente');
        var composer = document.querySelector('.impulso-composer');
        if (composer) composer.appendChild(picker);
        return picker;
    }
    function syncInputAria(open, activeId) {
        var input = field();
        if (!input) return;
        input.setAttribute('aria-controls', 'impulso-mention-picker');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && activeId) input.setAttribute('aria-activedescendant', activeId);
        else input.removeAttribute('aria-activedescendant');
    }
    function close() { var element = ensurePicker(); element.classList.add('impulso-hidden'); visible = []; index = 0; syncInputAria(false, ''); }
    function queryAtCursor() {
        var input = field();
        if (!input || !isNote()) return null;
        var before = input.value.slice(0, input.selectionStart);
        var match = before.match(/@([^\s@]*)$/u);
        return match ? { query: match[1], start: input.selectionStart - match[0].length, end: input.selectionStart } : null;
    }
    function render(rows) {
        var element = ensurePicker();
        visible = rows;
        index = rows.length ? Math.min(index, rows.length - 1) : 0;
        if (!rows.length) { close(); return; }
        var activeOptionId = 'impulso-mention-option-' + String(rows[index].id);
        element.innerHTML = rows.map(function (item, itemIndex) {
            var active = itemIndex === index;
            return '<button type="button" role="option" id="impulso-mention-option-' + esc(item.id) + '" aria-selected="' + (active ? 'true' : 'false') + '" class="' + (active ? 'is-highlighted' : '') + '" data-mention-id="' + esc(item.id) + '"><strong>@' + esc(item.name || ('Agente ' + item.id)) + '</strong></button>';
        }).join('');
        element.classList.remove('impulso-hidden');
        syncInputAria(true, activeOptionId);
    }
    function refresh() {
        var token = queryAtCursor();
        if (!token) { close(); return; }
        var query = token.query.toLowerCase();
        render(staff().filter(function (item) { return String(item.name || '').toLowerCase().indexOf(query) >= 0; }).slice(0, 8));
    }
    function select(item) {
        var input = field(); var token = queryAtCursor();
        if (!input || !token || !item) return;
        var name = String(item.name || 'Agente ' + item.id);
        input.value = input.value.slice(0, token.start) + '@' + name + ' ' + input.value.slice(token.end);
        input.selectionStart = input.selectionEnd = token.start + name.length + 2;
        record().items[id(item.id)] = name;
        record().revision++;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        close();
        input.focus();
    }
    function reconcile(conversationId, text) {
        var item = record(conversationId);
        var next = contract.reconcileMentionItems ? contract.reconcileMentionItems(item.items, text) : item.items;
        if (JSON.stringify(next) !== JSON.stringify(item.items)) item.revision++;
        item.items = next;
    }
    function getMentionIds(conversationId) {
        var item = record(conversationId);
        var input = field();
        if (!Object.keys(item.items).length || !input || !isNote() || currentId(conversationId) !== currentId()) return [];
        reconcile(conversationId, input.value);
        var ids = Object.keys(item.items).map(function (value) { return id(value); });
        return contract.normalizeMentionIds ? contract.normalizeMentionIds(ids) : ids.filter(Boolean);
    }
    function snapshot(conversationId) {
        var item = record(conversationId);
        return { conversationId: currentId(conversationId), revision: item.revision, ids: contract.normalizeMentionIds ? contract.normalizeMentionIds(Object.keys(item.items).map(id)) : Object.keys(item.items).map(id).filter(Boolean) };
    }
    function matchesSnapshot(conversationId, expected) {
        if (!expected) return false;
        var actual = snapshot(conversationId);
        return actual.conversationId === currentId(expected.conversationId) && actual.revision === Number(expected.revision || 0) && JSON.stringify(actual.ids) === JSON.stringify(expected.ids || []);
    }
    function clearIfMatches(conversationId, expected) { if (!matchesSnapshot(conversationId, expected)) return false; delete records[String(currentId(conversationId))]; if (currentId(conversationId) === currentId()) close(); return true; }
    function clear(conversationId) { delete records[String(currentId(conversationId))]; close(); }
    function onKeydown(event) {
        if (!isNote() || !visible.length || event.isComposing || event.keyCode === 229) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); event.stopPropagation(); index = event.key === 'ArrowDown' ? (index + 1) % visible.length : (index - 1 + visible.length) % visible.length; render(visible); }
        else if (event.key === 'Enter' || event.key === 'Tab') { event.preventDefault(); event.stopPropagation(); select(visible[index]); }
        else if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
    }
    var input = field();
    if (input) {
        syncInputAria(false, '');
        input.addEventListener('input', function () { if (isNote()) { reconcile(currentId(), input.value); refresh(); } else close(); });
    }
    app.addEventListener('keydown', onKeydown, true);
    app.addEventListener('click', function (event) { var button = event.target.closest('[data-mention-id]'); if (!button || !button.closest('#impulso-mention-picker')) return; event.preventDefault(); event.stopPropagation(); select(staff().find(function (item) { return id(item.id) === id(button.getAttribute('data-mention-id')); })); }, true);
    if (bridge.onConversationChange) bridge.onConversationChange(function () { close(); });
    window.ImpulsoMentions = { getMentionIds: getMentionIds, snapshot: snapshot, matchesSnapshot: matchesSnapshot, clearIfMatches: clearIfMatches, clear: clear, close: close };
}(window, document));
