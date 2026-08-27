'use strict';

const assert = require('assert');
const workflow = require('../../Assets/js/inbox/conversation_workflow.js');
let passed = 0;
function check(condition, message) { assert.ok(condition, message); console.log(`[OK] ${message}`); passed += 1; }

check(workflow.canonicalPriority('normal') === 'medium', 'legacy normal is canonical medium');
check(workflow.canonicalPriority(true) === 'high' && workflow.canonicalPriority(false) === 'medium', 'boolean priorities remain compatible');
check(workflow.statuses.join(',') === 'open,pending,resolved,snoozed', 'workflow statuses are canonical');
const sessions = workflow.create();
const a = sessions.activate(10);
const aLoad = sessions.capture(10, { type: 'load' });
sessions.activate(20);
check(!sessions.isCurrent(aLoad), 'late A callback is rejected after switching to B');
const bSend = sessions.capture(20, { type: 'send', clientMessageId: 'cid-b' });
sessions.activate(10);
check(!sessions.isCurrent(bSend), 'late B callback cannot mutate reactivated A');
const aNew = sessions.capture(10, { type: 'refresh' });
check(!sessions.isCurrent(a) && sessions.isCurrent(aNew), 'generation rejects stale same-conversation response');
check(sessions.getActiveId() === 10, 'active conversation remains provider-neutral and deterministic');
const navigation = workflow.createNavigationContext();
const previousOpen = navigation.begin(1234);
const otherOpen = navigation.begin(987);
check(!navigation.isCurrent(previousOpen) && navigation.isCurrent(otherOpen), 'late out-of-list conversation fetch cannot replace a newer navigation target');
const currentOpen = navigation.begin(1234);
check(navigation.isCurrent(currentOpen), 'previous conversation navigation gets a fresh operation context');
check(workflow.snoozeIsoFromLocal('2026-08-19T09:00:00-03:00') === '2026-08-19T12:00:00.000Z', 'custom snooze converts local timezone to explicit ISO UTC');
const reconciled = workflow.reconcileConversationRows([{ id: 1 }, { id: 2 }, { id: 3 }], [{ id: 2 }, { id: 3 }, { id: 4 }], 3, 3, 30);
check(reconciled.rows.map(row => row.id).join(',') === '2,3,4' && reconciled.page === 1, 'silent refresh removes rows that left the filtered server range');
for (const count of [30, 60, 90, 120]) {
    const existing = Array.from({ length: count }, (_, index) => ({ id: index + 1 }));
    const incoming = existing.slice(0, Math.min(count, 90)).map(row => ({ id: row.id + 1000 }));
    const result = workflow.reconcileConversationRows(existing, incoming, 90, 150, 30);
    check(result.rows.length === Math.min(count, 90) && result.page === Math.ceil(Math.min(count, 90) / 30) && result.hasMore, `silent refresh keeps a contiguous prefix for ${count} loaded rows`);
}
const refreshed120 = workflow.reconcileConversationRows(
    Array.from({ length: 120 }, (_, index) => ({ id: index + 1 })),
    Array.from({ length: 90 }, (_, index) => ({ id: 200 - index })),
    90,
    150,
    30
);
check(refreshed120.rows.length === 90 && refreshed120.rows.every(row => row.id > 110) && refreshed120.page === 3, 'removed rows and new top rows reconcile without preserving an unchecked stale tail');
check(refreshed120.page + 1 === 4 && refreshed120.hasMore, 'next page starts exactly after the refreshed contiguous range');
const activeRecord = { id: 110, name: 'Conversa 110', instance_status: 'connected' };
const activeOutsidePrefix = workflow.reconcileActiveConversationRecord(110, activeRecord, refreshed120.rows, false);
check(activeOutsidePrefix.record.id === 110 && !activeOutsidePrefix.cleared && !activeOutsidePrefix.listed, 'active record remains operational when silent refresh drops it outside the authoritative prefix');
const activeInsidePrefix = workflow.reconcileActiveConversationRecord(110, activeRecord, [{ id: 110, name: 'Conversa 110 atualizada' }], false);
check(activeInsidePrefix.listed && activeInsidePrefix.record.name === 'Conversa 110 atualizada', 'active record reconciles with a newer row when it reappears in the loaded prefix');
const activeCleared = workflow.reconcileActiveConversationRecord(110, activeRecord, [{ id: 1 }], true);
check(activeCleared.cleared && activeCleared.record === null, 'full authoritative response clears an active conversation that left the filter');
const detachedRecord = { id: 1234, status: 'resolved', priority: 'medium', unread_count: 1 };
const detachedFull = workflow.reconcileActiveConversationRecord(1234, detachedRecord, [{ id: 1 }], true, true);
check(!detachedFull.cleared && detachedFull.record.id === 1234, 'full authoritative open-list response keeps a detached detail outside the filter');
check(!workflow.shouldClearActiveConversation(true, false) && workflow.shouldClearActiveConversation(false, false), 'detached details ignore filter membership while list-bound details retain filter lifecycle');
check(workflow.activeConversationRefreshDisposition(404) === 'clear' && workflow.activeConversationRefreshDisposition(403) === 'clear' && workflow.activeConversationRefreshDisposition(500) === 'preserve', 'terminal active refresh responses clear while transient failures preserve the local detail');
const detachedRefresh = workflow.reconcileActiveConversationRecord(1234, Object.assign({}, detachedRecord, { status: 'open', priority: 'urgent', unread_count: 0 }), [], false, true);
check(detachedRefresh.record.status === 'open' && detachedRefresh.record.priority === 'urgent' && detachedRefresh.record.unread_count === 0 && detachedRefresh.record.id === 1234, 'detached canonical refresh updates status, priority and unread without a list row');
check(workflow.conversationMatchesFilters({ id: 2, name: 'Contato 2', status: 'open', assignee_id: 7, team_id: 3, priority: 'urgent', unread_count: 1, conversation_type: 'group', bot_status: 'active', tags: ['matricula'] }, {
    channelId: 'all', status: 'open', search: '2', extra: { assignee_id: '7', team_id: '3', priority: 'urgent', unread: '1', conversation_type: 'group', bot_status: 'running', tags: 'matricula'
}}), 'current filter matcher covers status, search, assignment, team, priority, unread, group, bot and tag');
check(workflow.conversationMatchesFilters({ id: 3, contact_name: 'Maria', phone: '5511999999999', remote_jid: '5511999999999@s.whatsapp.net', last_message: 'Urgente', instance_id: 2, status: 'open', assignee_id: null, priority: 'urgent' }, {
    channelId: '2', status: 'unassigned', search: '@s.whatsapp.net', extra: { priority: 'urgent' }
}), 'unassigned queue shortcut combines with channel, remote JID search and priority without becoming a status');
check(!workflow.conversationMatchesFilters({ id: 4, name: 'Contato', instance: 'Canal Comercial', status: 'open' }, {
    channelId: 'all', status: 'all', search: 'Canal Comercial', extra: {}
}), 'local search does not treat instance name as a backend search field');
check(workflow.conversationMatchesFilters({ id: 5, contact_name: 'Maria', phone: '5511', remote_jid: '5511@s.whatsapp.net', last_message: 'Documento' }, {
    channelId: 'all', status: 'all', search: 'Documento', extra: {}
}) && workflow.conversationMatchesFilters({ id: 6, contact_name: 'Maria', phone: '5511', remote_jid: '5511@s.whatsapp.net', last_message: '' }, {
    channelId: 'all', status: 'all', search: '5511', extra: {}
}), 'local search preserves name, phone and last-message matches from the server contract');
const hydrated = workflow.hydrateAssignmentOptions({ staff: [{ id: 7, name: 'Maria' }], teams: [{ id: 3, name: 'Comercial' }] }, { assignee_id: '7', team_id: '99' });
check(hydrated.assignee_id === '7' && hydrated.team_id === '' && hydrated.changed, 'staff/team option hydration preserves valid selection and clears removed team');
const tracker = workflow.createMutationTracker();
const high = tracker.begin(10, 'priority');
const urgent = tracker.begin(10, 'priority');
check(tracker.isCurrent(urgent) && !tracker.isCurrent(high), 'stale priority mutation response cannot overwrite urgent state');
const assignment = tracker.begin(10, 'assignment');
const status = tracker.begin(10, 'status');
check(tracker.isCurrent(assignment) && tracker.isCurrent(status), 'different workflow mutation fields retain independent operation contexts');
const read = tracker.begin(10, 'read_state');
const unread = tracker.begin(10, 'read_state');
check(!tracker.isCurrent(read) && tracker.isCurrent(unread), 'read and unread share one logical sequencing key');
const secondRead = tracker.begin(10, 'read_state');
check(!tracker.isCurrent(unread) && tracker.isCurrent(secondRead), 'late unread response cannot replace a newer read state');
const teamOnly = workflow.assignmentMutationPayload({ team_id: 3 });
const assigneeOnly = workflow.assignmentMutationPayload({ assignee_id: 7 });
check(teamOnly.team_id === 3 && !Object.prototype.hasOwnProperty.call(teamOnly, 'assignee_id'), 'team-only assignment payload preserves assignee by omission');
check(assigneeOnly.assignee_id === 7 && !Object.prototype.hasOwnProperty.call(assigneeOnly, 'team_id'), 'assignee-only assignment payload preserves team by omission');
const menu = workflow.createMenuKeyboardState(4);
check(menu.key('ArrowDown') === 1 && menu.key('End') === 3 && menu.key('ArrowUp') === 2 && menu.key('Home') === 0, 'context menu keyboard state supports arrows, Home and End');
const snoozeDialog = workflow.createDialogLifecycle();
const trigger = { focused: 0, focus() { this.focused += 1; } };
snoozeDialog.open(42, trigger);
check(snoozeDialog.isOpen() && snoozeDialog.conversationId() === 42, 'custom snooze dialog records its conversation and trigger');
check(snoozeDialog.close() === trigger && !snoozeDialog.isOpen() && snoozeDialog.conversationId() === 0, 'Escape/cancel close clears dialog context and returns its focus target');
snoozeDialog.open(42, trigger);
check(snoozeDialog.shouldCloseOnKey('Escape') && !snoozeDialog.shouldCloseOnKey('Enter'), 'custom snooze closes on Escape but not ordinary input keys');
check(snoozeDialog.shouldCloseOnOutsideClick(false) && !snoozeDialog.shouldCloseOnOutsideClick(true), 'custom snooze outside click closes only outside protected surfaces');
snoozeDialog.close();
const assignmentTracker = workflow.createMutationTracker();
const teamDeferred = {};
const assigneeDeferred = {};
teamDeferred.promise = new Promise(resolve => { teamDeferred.resolve = resolve; });
assigneeDeferred.promise = new Promise(resolve => { assigneeDeferred.resolve = resolve; });
const teamContext = assignmentTracker.beginMany(10, ['assignment.team']);
const assigneeContext = assignmentTracker.beginMany(10, ['assignment.assignee']);
const appliedDimensions = [];
const teamCompletion = teamDeferred.promise.then(() => { if (assignmentTracker.isCurrent(teamContext)) appliedDimensions.push('team'); });
const assigneeCompletion = assigneeDeferred.promise.then(() => { if (assignmentTracker.isCurrent(assigneeContext)) appliedDimensions.push('assignee'); });
assigneeDeferred.resolve({ assignee_id: 7 });
teamDeferred.resolve({ team_id: 3 });
Promise.all([teamCompletion, assigneeCompletion]).then(() => {
    check(appliedDimensions.sort().join(',') === 'assignee,team', 'controlled team and assignee completions apply independent DTO dimensions');
    const oldAssignee = assignmentTracker.beginMany(10, ['assignment.assignee']);
    const newAssignee = assignmentTracker.beginMany(10, ['assignment.assignee']);
    check(!assignmentTracker.isCurrent(oldAssignee) && assignmentTracker.isCurrent(newAssignee) && assignmentTracker.isCurrent(teamContext), 'older assignee response is stale while the independent team operation remains current');
    console.log(`${passed} passed, 0 failed.`);
}).catch(error => { console.error(error); process.exitCode = 1; });
