(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.ImpulsoConversationWorkflow = factory();
})(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';
    var statuses = ['open', 'pending', 'resolved', 'snoozed'];
    var priorities = ['none', 'low', 'medium', 'high', 'urgent'];
    function canonicalPriority(value) {
        if (value === true) return 'high';
        if (value === false) return 'medium';
        var priority = String(value == null ? 'none' : value).toLowerCase();
        if (priority === 'normal') return 'medium';
        return priorities.indexOf(priority) >= 0 ? priority : 'none';
    }
    function snoozeIsoFromLocal(value) {
        if (!value) return '';
        var date = new Date(String(value));
        return isNaN(date.getTime()) ? '' : date.toISOString();
    }
    function conversationMatchesFilters(conversation, filters) {
        conversation = conversation || {};
        filters = filters || {};
        var extra = filters.extra || {};
        var id = Number(conversation.id || 0);
        if (filters.channelId && filters.channelId !== 'all' && String(conversation.instance_id || '') !== String(filters.channelId)) return false;
        var status = String(filters.status || 'all');
        if (status === 'unassigned') {
            if (Number(conversation.assignee_id || 0) > 0) return false;
        } else if (status !== 'all' && String(conversation.status || 'open') !== status) {
            return false;
        }
        var query = String(filters.search || '').trim().toLowerCase();
        if (query) {
            var haystack = [conversation.name, conversation.contact_name, conversation.phone, conversation.phone_number, conversation.remote_jid, conversation.last_message, conversation.last_message_preview].join(' ').toLowerCase();
            if (haystack.indexOf(query) < 0) return false;
        }
        if (extra.assignee_id) {
            if (extra.assignee_id === 'unassigned' && Number(conversation.assignee_id || 0) > 0) return false;
            if (extra.assignee_id === 'me' && Number(conversation.assignee_id || 0) !== Number(extra.current_user_id || 0)) return false;
            if (extra.assignee_id !== 'unassigned' && extra.assignee_id !== 'me' && Number(conversation.assignee_id || 0) !== Number(extra.assignee_id)) return false;
        }
        if (extra.team_id && Number(conversation.team_id || 0) !== Number(extra.team_id)) return false;
        if (extra.priority && canonicalPriority(conversation.priority) !== canonicalPriority(extra.priority)) return false;
        if (extra.unread !== '' && extra.unread != null && (Number(conversation.unread_count || conversation.unread || 0) > 0 ? '1' : '0') !== String(extra.unread)) return false;
        if (extra.conversation_type && String(conversation.conversation_type || 'individual') !== String(extra.conversation_type)) return false;
        if (extra.bot_status) {
            var botStatus = String(conversation.bot_status || 'active');
            if (extra.bot_status === 'running') botStatus = botStatus === 'active' ? 'running' : botStatus;
            if (botStatus !== String(extra.bot_status)) return false;
        }
        var activity = conversation.last_activity_at || conversation.last_message_at || '';
        var activityTime = Date.parse(String(activity));
        var fromTime = extra.last_activity_from ? Date.parse(String(extra.last_activity_from) + (String(extra.last_activity_from).length === 10 ? 'T00:00:00' : '')) : NaN;
        var toTime = extra.last_activity_to ? Date.parse(String(extra.last_activity_to) + (String(extra.last_activity_to).length === 10 ? 'T23:59:59.999' : '')) : NaN;
        if (!isNaN(activityTime) && !isNaN(fromTime) && activityTime < fromTime) return false;
        if (!isNaN(activityTime) && !isNaN(toTime) && activityTime > toTime) return false;
        if (extra.tags) {
            var requestedTags = Array.isArray(extra.tags) ? extra.tags : String(extra.tags).split(/\s*,\s*/);
            var conversationTags = Array.isArray(conversation.tags) ? conversation.tags.map(function (tag) { return String(tag).toLowerCase(); }) : [];
            if (requestedTags.filter(Boolean).length && !requestedTags.some(function (tag) { return conversationTags.indexOf(String(tag).toLowerCase()) >= 0; })) return false;
        }
        return id > 0;
    }
    function reconcileConversationRows(existing, incoming, refreshLimit, total, pageSize) {
        existing = Array.isArray(existing) ? existing : [];
        incoming = Array.isArray(incoming) ? incoming : [];
        refreshLimit = Math.max(0, Number(refreshLimit || incoming.length));
        pageSize = Math.max(1, Number(pageSize || 30));
        // A silent refresh validates only the prefix returned by the server.
        // Keeping an older tail would make the list look contiguous while its
        // pagination cursor no longer described the rows actually known.
        var rows = incoming.slice();
        var knownTotal = total != null && isFinite(Number(total));
        var page = Math.max(1, Math.ceil(rows.length / pageSize));
        var hasMore = knownTotal ? Number(total) > rows.length : rows.length >= refreshLimit;
        return { rows: rows, page: page, hasMore: hasMore, refreshedCount: rows.length, discardedCount: Math.max(0, existing.length - rows.length) };
    }
    function reconcileActiveConversationRecord(activeId, activeRecord, rows, fullAuthoritative, detached) {
        activeId = Number(activeId || 0);
        rows = Array.isArray(rows) ? rows : [];
        if (activeId < 1) return { activeId: 0, record: null, cleared: true, listed: false };
        var listed = rows.find(function (row) { return Number(row && row.id) === activeId; });
        if (listed) return { activeId: activeId, record: Object.assign({}, listed), cleared: false, listed: true };
        if (fullAuthoritative && !detached) return { activeId: 0, record: null, cleared: true, listed: false };
        return { activeId: activeId, record: activeRecord || null, cleared: false, listed: false };
    }
    function shouldClearActiveConversation(detached, matchesCurrentFilters) {
        return !detached && !matchesCurrentFilters;
    }
    function activeConversationRefreshDisposition(status) {
        status = Number(status || 0);
        return status === 404 || status === 403 ? 'clear' : 'preserve';
    }
    function createMutationTracker() {
        var sequences = {};
        function uniqueKeys(keys) {
            return (Array.isArray(keys) ? keys : [keys]).map(function (key) { return String(key || 'workflow'); }).filter(function (key, index, all) { return all.indexOf(key) === index; });
        }
        return {
            begin: function (conversationId, operationKey) {
                var key = String(Number(conversationId || 0)) + ':' + String(operationKey || 'workflow');
                sequences[key] = Number(sequences[key] || 0) + 1;
                return { conversationId: Number(conversationId || 0), operationKey: String(operationKey || 'workflow'), sequence: sequences[key] };
            },
            beginMany: function (conversationId, operationKeys) {
                var contexts = uniqueKeys(operationKeys).map(function (operationKey) { return this.begin(conversationId, operationKey); }, this);
                return { conversationId: Number(conversationId || 0), operationKeys: contexts.map(function (context) { return context.operationKey; }), contexts: contexts };
            },
            isCurrent: function (context) {
                if (!context) return false;
                if (Array.isArray(context.contexts)) return context.contexts.every(this.isCurrent.bind(this));
                var key = String(Number(context.conversationId || 0)) + ':' + String(context.operationKey || 'workflow');
                return Number(sequences[key] || 0) === Number(context.sequence || 0);
            }
        };
    }
    function hydrateAssignmentOptions(options, current) {
        options = options || {};
        current = current || {};
        var staff = Array.isArray(options.staff) ? options.staff : [];
        var teams = Array.isArray(options.teams) ? options.teams : [];
        var assigneeId = String(current.assignee_id || '');
        var teamId = String(current.team_id || '');
        var validAssignee = assigneeId === '' || assigneeId === 'unassigned' || assigneeId === 'me' || staff.some(function (item) { return String(item.id) === assigneeId; });
        var validTeam = teamId === '' || teams.some(function (item) { return String(item.id) === teamId; });
        return {
            staff: staff,
            teams: teams,
            assignee_id: validAssignee ? assigneeId : '',
            team_id: validTeam ? teamId : '',
            changed: !validAssignee || !validTeam
        };
    }
    function assignmentMutationPayload(input) {
        input = input || {};
        var payload = {};
        if (Object.prototype.hasOwnProperty.call(input, 'assign_to_me')) payload.assign_to_me = input.assign_to_me;
        if (Object.prototype.hasOwnProperty.call(input, 'assignee_id')) payload.assignee_id = input.assignee_id;
        if (Object.prototype.hasOwnProperty.call(input, 'team_id')) payload.team_id = input.team_id;
        return payload;
    }
    function createMenuKeyboardState(count) {
        var index = 0;
        count = Math.max(0, Number(count || 0));
        return {
            current: function () { return index; },
            focus: function (next) { index = Math.max(0, Math.min(Math.max(0, count - 1), Number(next || 0))); return index; },
            key: function (key) {
                if (!count) return 0;
                if (key === 'ArrowDown') index = (index + 1) % count;
                else if (key === 'ArrowUp') index = (index - 1 + count) % count;
                else if (key === 'Home') index = 0;
                else if (key === 'End') index = count - 1;
                return index;
            }
        };
    }
    function createDialogLifecycle() {
        var active = false;
        var conversationId = 0;
        var trigger = null;
        return {
            open: function (id, element) {
                active = true;
                conversationId = Number(id || 0);
                trigger = element || null;
                return { conversationId: conversationId, trigger: trigger };
            },
            close: function () {
                var previousTrigger = trigger;
                active = false;
                conversationId = 0;
                trigger = null;
                return previousTrigger;
            },
            shouldCloseOnKey: function (key) { return active && String(key || '') === 'Escape'; },
            shouldCloseOnOutsideClick: function (insideProtectedSurface) { return active && !insideProtectedSurface; },
            isOpen: function () { return active; },
            conversationId: function () { return conversationId; },
            trigger: function () { return trigger; }
        };
    }
    function create() {
        var sessions = {}, activeId = 0, generation = 0;
        function session(id) { id = Number(id || 0); if (!sessions[id]) sessions[id] = { id: id, generation: 0 }; return sessions[id]; }
        return {
            activate: function (id) { id = Number(id || 0); activeId = id; generation += 1; session(id).generation = generation; return { conversationId: id, generation: generation }; },
            capture: function (id, extra) { var current = session(id); return Object.assign({ conversationId: Number(id || 0), generation: current.generation }, extra || {}); },
            isCurrent: function (context) { if (!context) return false; var current = session(context.conversationId); return Number(context.conversationId || 0) === activeId && Number(context.generation || 0) === Number(current.generation || 0); },
            getActiveId: function () { return activeId; },
            getSessionGeneration: function (id) { return session(id).generation; }
        };
    }
    function createNavigationContext() {
        var sequence = 0;
        return {
            begin: function (conversationId) {
                sequence += 1;
                return { conversationId: Number(conversationId || 0), sequence: sequence };
            },
            invalidate: function () {
                sequence += 1;
                return sequence;
            },
            isCurrent: function (context) {
                return !!context && Number(context.sequence || 0) === sequence;
            }
        };
    }
    return {
        statuses: statuses,
        priorities: priorities,
        canonicalPriority: canonicalPriority,
        snoozeIsoFromLocal: snoozeIsoFromLocal,
        conversationMatchesFilters: conversationMatchesFilters,
        reconcileConversationRows: reconcileConversationRows,
        reconcileActiveConversationRecord: reconcileActiveConversationRecord,
        shouldClearActiveConversation: shouldClearActiveConversation,
        activeConversationRefreshDisposition: activeConversationRefreshDisposition,
        createMutationTracker: createMutationTracker,
        hydrateAssignmentOptions: hydrateAssignmentOptions,
        assignmentMutationPayload: assignmentMutationPayload,
        createMenuKeyboardState: createMenuKeyboardState,
        createDialogLifecycle: createDialogLifecycle,
        createNavigationContext: createNavigationContext,
        create: create
    };
});
