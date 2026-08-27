(function (window, document) {
    'use strict';
    var bridge = window.ImpulsoHubBridge;
    var app = document.getElementById('impulso-hub-app');
    if (!bridge || !app) return;
    var config = bridge.getConfig ? bridge.getConfig() : {};
    var state = bridge.getState ? bridge.getState() : {};
    var sequence = 0;
    var timer = null;
    var typingTimer = null;
    var typingRenew = null;
    var current = 0;
    var warning = null;
    var pagehideBound = false;

    function id(value) { var result = Number(value || 0); return isFinite(result) && result > 0 ? result : 0; }
    function esc(value) { return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }
    function endpoint(idValue) { return bridge.endpointWithId('conversations', idValue, '/presence'); }
    function ensureWarning() {
        if (warning) return warning;
        warning = document.createElement('div'); warning.id = 'impulso-presence-warning'; warning.className = 'impulso-presence-warning impulso-hidden'; warning.setAttribute('role', 'status'); warning.setAttribute('aria-live', 'polite');
        var header = document.querySelector('.impulso-chat-header'); if (header) header.insertAdjacentElement('afterend', warning);
        return warning;
    }
    function render(payload, conversationId) {
        if (id(state.activeConversationId) !== id(conversationId)) return;
        var rows = payload && Array.isArray(payload.agents) ? payload.agents.filter(function (item) { return id(item.user_id) !== id(config.actorId); }) : [];
        var typing = rows.filter(function (item) { return item.typing; }).map(function (item) { return item.name; });
        var viewing = rows.filter(function (item) { return item.viewing && !item.typing; }).map(function (item) { return item.name; });
        var messages = [];
        if (typing.length) messages.push(typing.slice(0, 3).join(', ') + (typing.length > 3 ? ' e outros' : '') + (typing.length === 1 ? ' esta respondendo esta conversa.' : ' estao respondendo esta conversa.'));
        if (viewing.length) messages.push(viewing.slice(0, 3).join(', ') + (viewing.length > 3 ? ' e outros' : '') + (viewing.length === 1 ? ' esta visualizando esta conversa.' : ' estao visualizando esta conversa.'));
        var element = ensureWarning(); element.innerHTML = messages.length ? '<i data-feather="users"></i><span>' + esc(messages.join(' ')) + '</span>' : ''; element.classList.toggle('impulso-hidden', !messages.length);
        if (bridge.replaceIcons) bridge.replaceIcons();
    }
    function send(conversationId, presenceState) {
        conversationId = id(conversationId); if (!conversationId || !bridge.api) return Promise.resolve();
        return bridge.api(endpoint(conversationId), { method: 'POST', body: { state: presenceState } }).catch(function () { return null; });
    }
    function poll(conversationId, token) {
        if (!conversationId || !bridge.api) return Promise.resolve();
        return bridge.api(endpoint(conversationId)).then(function (payload) { if (token === sequence && id(state.activeConversationId) === conversationId) render(payload && payload.data, conversationId); }).catch(function () { if (token === sequence && id(state.activeConversationId) === conversationId) render({}, conversationId); });
    }
    function stopTimers() { if (timer) window.clearInterval(timer); timer = null; if (typingTimer) window.clearTimeout(typingTimer); typingTimer = null; if (typingRenew) window.clearInterval(typingRenew); typingRenew = null; }
    function start(conversationId) {
        stopTimers(); current = id(conversationId); sequence += 1; var token = sequence; if (!current) { render({}, 0); return; }
        send(current, 'viewing'); poll(current, token);
        timer = window.setInterval(function () { if (!document.hidden && id(state.activeConversationId) === current) { send(current, 'viewing'); poll(current, token); } }, 15000);
        if (!pagehideBound) { window.addEventListener('pagehide', leave, { once: true }); pagehideBound = true; }
    }
    function leave() { if (current) send(current, 'leave'); stopTimers(); }
    function onInput() {
        if (state.composerMode !== 'reply' || !current) return;
        if (typingTimer) window.clearTimeout(typingTimer);
        if (!typingRenew) { send(current, 'typing'); typingRenew = window.setInterval(function () { if (current && state.composerMode === 'reply') send(current, 'typing'); }, 5000); }
        typingTimer = window.setTimeout(function () { if (typingRenew) window.clearInterval(typingRenew); typingRenew = null; send(current, 'viewing'); }, 2200);
    }
    if (bridge.onConversationChange) bridge.onConversationChange(function (event) { if (event.fromId) send(event.fromId, 'leave'); start(event.toId); });
    if (bridge.onComposerModeChange) bridge.onComposerModeChange(function (event) {
        if (event && event.toMode !== 'reply') {
            if (typingTimer) window.clearTimeout(typingTimer);
            typingTimer = null;
            if (typingRenew) window.clearInterval(typingRenew);
            typingRenew = null;
            if (current) send(current, 'viewing');
        }
    });
    var input = document.getElementById('impulso-message-input'); if (input) input.addEventListener('input', onInput);
    document.addEventListener('visibilitychange', function () { if (!document.hidden && current) { send(current, 'viewing'); poll(current, sequence); } });
    start(state.activeConversationId);
    window.ImpulsoPresence = { refresh: function () { return poll(current, sequence); }, leave: leave };
}(window, document));
