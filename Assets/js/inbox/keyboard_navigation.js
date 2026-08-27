(function (window, document) {
    'use strict';
    var bridge = window.ImpulsoHubBridge;
    var app = document.getElementById('impulso-hub-app');
    if (!bridge || !app) return;
    function inputLike(element) {
        if (!element) return true;
        var tag = String(element.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select' || element.isContentEditable || !!element.closest('[contenteditable="true"], .impulso-composer, #impulso-mention-picker');
    }
    function visibleCards() { return Array.prototype.slice.call(document.querySelectorAll('[data-conversation-select]')).filter(function (item) { return !item.closest('.impulso-hidden'); }); }
    function move(direction) {
        var state = bridge.getState ? bridge.getState() : {};
        if (state.activeConversationDetached) return;
        var cards = visibleCards(); if (!cards.length) return;
        var current = cards.findIndex(function (item) { return Number(item.getAttribute('data-conversation-select')) === Number(state.activeConversationId); });
        var next = current < 0 ? (direction > 0 ? 0 : cards.length - 1) : (current + direction + cards.length) % cards.length;
        cards[next].focus(); cards[next].click();
    }
    document.addEventListener('keydown', function (event) {
        if (event.isComposing || event.keyCode === 229) return;
        var search = document.getElementById('impulso-conversation-search');
        if (event.key === 'Escape' && search && document.activeElement === search) {
            if (search.value) {
                event.preventDefault();
                search.value = '';
                search.dispatchEvent(new Event('input', { bubbles: true }));
            }
            return;
        }
        if (inputLike(document.activeElement)) return;
        if ((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() === 'k') {
            if (!search) return;
            event.preventDefault(); search.focus(); search.select(); return;
        }
        if (event.altKey && event.key === 'ArrowDown') { event.preventDefault(); move(1); }
        else if (event.altKey && event.key === 'ArrowUp') { event.preventDefault(); move(-1); }
    }, true);
    window.ImpulsoKeyboardNavigation = { inputLike: inputLike, move: move };
}(window, document));
