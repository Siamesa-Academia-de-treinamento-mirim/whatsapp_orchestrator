(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.ImpulsoComposerQuickReplies = factory();
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var VARIABLE_NAMES = ['contact.name', 'contact.phone', 'agent.name'];

    function text(value) { return String(value == null ? '' : value); }

    function filter(rows, query) {
        var needle = text(query).trim().toLowerCase();
        return (Array.isArray(rows) ? rows : []).filter(function (row) {
            if (!needle) return true;
            return [row.shortcut, row.title, row.text, row.content].some(function (value) {
                return text(value).toLowerCase().indexOf(needle) >= 0;
            });
        });
    }

    function slashToken(value, cursor) {
        value = text(value);
        cursor = cursor == null ? value.length : Math.max(0, Math.min(value.length, Number(cursor)));
        var prefix = value.slice(0, cursor);
        var match = prefix.match(/(?:^|\s)(\/[A-Za-z0-9_-]*)$/);
        if (!match) return null;
        var token = match[1];
        return { start: cursor - token.length, end: cursor, query: token.slice(1), token: token };
    }

    function replaceSlashToken(value, cursor, replacement) {
        var token = slashToken(value, cursor);
        if (!token) return { value: text(value), cursor: cursor == null ? text(value).length : cursor, token: null };
        var next = text(value).slice(0, token.start) + text(replacement) + text(value).slice(token.end);
        var nextCursor = token.start + text(replacement).length;
        return { value: next, cursor: nextCursor, token: token };
    }

    function substitute(value, catalog) {
        var source = catalog || {};
        return text(value).replace(/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/g, function (whole, name) {
            if (VARIABLE_NAMES.indexOf(name) < 0 || source[name] == null) return whole;
            return text(source[name]);
        });
    }

    function keyboardIndex(length, current, key) {
        length = Math.max(0, Number(length || 0));
        if (!length) return -1;
        current = Number(current);
        if (!isFinite(current) || current < 0 || current >= length) current = key === 'ArrowUp' ? 0 : -1;
        if (key === 'ArrowDown') return (current + 1 + length) % length;
        if (key === 'ArrowUp') return (current - 1 + length) % length;
        return current;
    }

    return {
        variableNames: VARIABLE_NAMES.slice(),
        filter: filter,
        slashToken: slashToken,
        replaceSlashToken: replaceSlashToken,
        substitute: substitute,
        keyboardIndex: keyboardIndex
    };
}));
