(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.ImpulsoCollaborationContract = factory();
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';
    function numberId(value) { var result = Number(value || 0); return isFinite(result) && result > 0 ? result : 0; }
    function normalizeMentionIds(ids) { var values = Array.isArray(ids) ? ids.map(numberId).filter(function (item) { return item > 0; }) : []; values = values.filter(function (item, index) { return values.indexOf(item) === index; }).sort(function (left, right) { return left - right; }); return values.slice(0, 20); }
    function mentionIdentity(content, revisionOrIds, maybeIds) {
        var revision = Array.isArray(revisionOrIds) ? 0 : Number(revisionOrIds || 0);
        var ids = Array.isArray(revisionOrIds) ? revisionOrIds : maybeIds;
        return JSON.stringify({ content: String(content || ''), revision: isFinite(revision) ? revision : 0, mention_user_ids: normalizeMentionIds(ids) });
    }
    function escapeRegExp(value) { return String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    function mentionOccurrences(text, name) {
        var escaped = escapeRegExp(name);
        if (!escaped) return 0;
        var expression;
        try { expression = new RegExp('(^|[\\s([{"\\\'])@' + escaped + '(?=$|[\\s.,!?;:])', 'giu'); }
        catch (error) { expression = new RegExp('(^|\\s)@' + escaped + '(?=$|\\s)', 'gi'); }
        var matches = String(text || '').match(expression);
        return matches ? matches.length : 0;
    }
    function reconcileMentionItems(items, text) {
        var source = items && typeof items === 'object' ? items : {};
        var groups = {};
        Object.keys(source).forEach(function (key) {
            var name = String(source[key] || '').trim();
            var group = name.toLocaleLowerCase();
            if (!groups[group]) groups[group] = [];
            groups[group].push(key);
        });
        var result = {};
        Object.keys(source).forEach(function (key) {
            var name = String(source[key] || '').trim();
            var group = groups[name.toLocaleLowerCase()] || [];
            if (!name || group.length !== 1 || mentionOccurrences(text, name) !== 1) return;
            result[key] = name;
        });
        return result;
    }
    function collisionSummary(agents, selfId) {
        var rows = (Array.isArray(agents) ? agents : []).filter(function (item) { return numberId(item && item.user_id) !== numberId(selfId); });
        return { typing: rows.filter(function (item) { return !!item.typing; }).map(function (item) { return String(item.name || 'Agente'); }), viewing: rows.filter(function (item) { return !!item.viewing && !item.typing; }).map(function (item) { return String(item.name || 'Agente'); }) };
    }
    function allowedSavedFilterKeys() { return ['status', 'instance', 'channel', 'assignee', 'team', 'tags', 'priority', 'unread', 'conversation_type', 'bot_status', 'last_activity_from', 'last_activity_to', 'search']; }
    function canonicalStatusOptions() { return [{ value: 'open', label: 'Aberta' }, { value: 'pending', label: 'Pendente' }, { value: 'resolved', label: 'Resolvida' }]; }
    function canonicalPriorityOptions() { return [{ value: 'none', label: 'Sem prioridade' }, { value: 'low', label: 'Baixa' }, { value: 'medium', label: 'Media' }, { value: 'high', label: 'Alta' }, { value: 'urgent', label: 'Urgente' }]; }
    function normalizeBulkReadState(value) { value = String(value || ''); return value === 'read' || value === 'unread' ? value : ''; }
    function bulkSummary(results) { var rows = Array.isArray(results) ? results : []; var succeeded = rows.filter(function (item) { return item && item.ok; }).length; return { requested: rows.length, succeeded: succeeded, failed: rows.length - succeeded }; }
    function conversationPermalink(base, id) { try { var url = new URL(String(base)); url.searchParams.set('conversation', String(numberId(id))); url.searchParams.delete('message'); return url.href; } catch (error) { return ''; } }
    function keyboardShouldHandle(target) { if (!target) return true; var tag = String(target.tagName || '').toLowerCase(); return ['input', 'textarea', 'select'].indexOf(tag) < 0 && !target.isContentEditable; }
    return { numberId: numberId, normalizeMentionIds: normalizeMentionIds, mentionIdentity: mentionIdentity, reconcileMentionItems: reconcileMentionItems, collisionSummary: collisionSummary, allowedSavedFilterKeys: allowedSavedFilterKeys, canonicalStatusOptions: canonicalStatusOptions, canonicalPriorityOptions: canonicalPriorityOptions, normalizeBulkReadState: normalizeBulkReadState, bulkSummary: bulkSummary, conversationPermalink: conversationPermalink, keyboardShouldHandle: keyboardShouldHandle };
}));
