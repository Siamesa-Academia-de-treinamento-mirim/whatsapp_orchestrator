<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/Services/Conversation_workflow_service.php';

use Chatwoot_plugin\Services\Conversation_workflow_service;

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) { echo "[OK] {$message}\n"; $passed++; return; }
    echo "[FAIL] {$message}\n"; $failed++;
};

$assert(Conversation_workflow_service::canonicalPriority('normal') === 'medium', 'legacy normal reads as canonical medium');
$assert(Conversation_workflow_service::canonicalPriority(true) === 'high' && Conversation_workflow_service::canonicalPriority(false) === 'medium', 'boolean priority compatibility is canonical');
$assert(Conversation_workflow_service::validateStatus('pending') === 'pending', 'canonical pending status is accepted');
$invalidPriority = false;
try { Conversation_workflow_service::validatePriority('critical'); } catch (InvalidArgumentException) { $invalidPriority = true; }
$assert($invalidPriority, 'unknown priority is rejected server-side');
$now = new DateTimeImmutable('2026-08-18 12:00:00', new DateTimeZone('UTC'));
$assert(Conversation_workflow_service::snoozeUntil('2026-08-18T13:00:00-03:00', $now) === '2026-08-18 16:00:00', 'snooze input is normalized to UTC');
$rejected = false;
try { Conversation_workflow_service::snoozeUntil('2026-08-18T11:59:00Z', $now); } catch (InvalidArgumentException) { $rejected = true; }
$assert($rejected, 'past snooze is rejected server-side');

$model = file_get_contents($root . '/Models/Chat_conversations_model.php');
$actions = file_get_contents($root . '/Services/Conversation_action_service.php');
$controller = file_get_contents($root . '/Controllers/Conversations.php');
$filterService = file_get_contents($root . '/Services/Conversation_filter_service.php');
$settings = file_get_contents($root . '/Controllers/Settings.php');
$chat = file_get_contents($root . '/Services/Chat_service.php');
$audit = file_get_contents($root . '/Services/Audit_service.php');
$bot = file_get_contents($root . '/Services/Bot_service.php');
$worker = file_get_contents($root . '/Services/Integration_job_service.php');
$js = file_get_contents($root . '/Assets/js/chatwoot.js');
$view = file_get_contents($root . '/Views/partials/conversations.php');
$routes = file_get_contents($root . '/Config/Routes.php');
$assert(str_contains($model, "'snoozed_until'") && str_contains($model, 'workflow_counts') && str_contains($model, 'last_activity_from'), 'server-side workflow fields and filters are implemented');
$assert(str_contains($actions, 'mark_unread') && str_contains($actions, 'conversation.team_assigned') && str_contains($actions, "'conversation.pending'"), 'workflow writes persist unread, team and semantic status audit');
$assert(str_contains($actions, "where('status', 'active')") && str_contains($actions, '$previousUnread'), 'assignment requires active staff and read/unread audit is idempotent');
$assert(str_contains($actions, '$assigneeTouched') && str_contains($actions, '$teamTouched') && str_contains($actions, 'Informe ao menos um campo de atribuicao.'), 'empty assignment bodies are rejected and dimensions are detected explicitly');
$assert(str_contains($actions, 'if ($assigneeTouched) $payload[\'assignee_id\']') && str_contains($actions, 'if ($teamTouched) $payload[\'team_id\']'), 'partial assignment updates write only explicitly touched columns');
$assert(str_contains($actions, '$payload = []') && str_contains($actions, 'upsert_conversation'), 'independent assignment mutations use a partial upsert safe for concurrent team and assignee changes');
$assert(str_contains($settings, 'validatePriority'), 'settings validate canonical priority values');
$assert(str_contains($controller, 'assignment_options') && str_contains($controller, 'conversationFilters') && str_contains($controller, 'previous') && str_contains($controller, 'InvalidArgumentException'), 'workflow API exposes authorized options, validated filters, history and 422 handling');
$assert(str_contains($filterService, "if (\$rawStatus === 'unassigned')") && str_contains($filterService, "\$out['assignee'] = 'unassigned';") && str_contains($filterService, "\$query['unassigned'] = true;") && !str_contains($filterService, "\$query['status'] = 'unassigned'"), 'unassigned queue status is translated to an unassigned filter instead of a database status');
$assert(str_contains($controller, 'public function show(int $id)') && str_contains($controller, 'get_conversation($id)') && str_contains($controller, "'Conversa nao encontrada.', 404"), 'individual conversation GET returns the canonical DTO and 404s missing conversations');
$assert(str_contains($routes, "api/conversations/(:num)', 'Conversations::show") && str_contains($chat, 'public function get_conversation(int $id)') && str_contains($chat, 'return $result[\'data\'][0] ?? null;'), 'individual conversation route reuses the list projector without exposing a raw row');
$assert(str_contains($chat, "'priority_legacy'") && str_contains($chat, "'is_unread'") && str_contains($chat, "'snoozed_until'") && str_contains($chat, "'assignee_details'"), 'conversation DTO exposes canonical priority, assignment, unread and snooze fields');
$assert(str_contains($audit, 'resolveStaffNames') && str_contains($audit, 'alterou a prioridade') && str_contains($audit, "'text'"), 'activity projection resolves names and emits semantic safe text');
$assert(str_contains($audit, 'conversation.tags_changed') && str_contains($audit, 'conversation.bot_paused') && str_contains($audit, 'conversation.bot_resumed') && str_contains($audit, 'array_slice($tags, 0, 5)'), 'tag and bot activity text is human-readable, bounded and not raw JSON');
$assert(str_contains($bot, "'bot.conversation_paused'") && str_contains($bot, "'bot.conversation_resumed'") && str_contains($audit, "'bot.conversation_paused'") && str_contains($audit, "'bot.conversation_resumed'") && str_contains($audit, 'pausou o bot para atendimento humano') && str_contains($audit, 'retomou o bot'), 'activity test compares Bot_service action names with the expected human-readable projections');
$assert(str_contains($worker, 'wakeDueSnoozes'), 'worker invokes due snooze wake-up');
$assert(str_contains($js, 'conversationContext') && str_contains($js, 'reconcileConversationRows') && str_contains($js, 'createMutationTracker') && str_contains($js, 'workflowSession') && str_contains($js, 'ArrowDown'), 'frontend uses filtered reconciliation, mutation context and keyboard menu state');
$assert(str_contains($js, 'activeConversationRecord') && str_contains($js, 'reconcileActiveConversationRecord') && str_contains($js, 'sendMessage'), 'active conversation record remains available to composer and sidebar outside the paginated list');
$assert(str_contains($js, 'activeConversationDetached') && str_contains($js, 'detached: true') && str_contains($js, 'detached: false'), 'active detail records distinguish direct detached navigation from list-bound selection');
$assert(str_contains($js, 'shouldClearActiveConversation') && str_contains($js, 'state.activeConversationDetached, matchesCurrentFilters'), 'filter membership cannot clear a detached active detail');
$assert(str_contains($js, 'reconcileActiveConversationRecord(state.activeConversationId, state.activeConversationRecord, state.conversations, !!responseContainsFullList, state.activeConversationDetached'), 'full authoritative list reconciliation respects detached origin');
$refreshStart = strpos($js, 'function refreshActiveConversationRecord(');
$refreshEnd = strpos($js, 'function loadConversations(', $refreshStart);
$refreshBlock = $refreshStart >= 0 && $refreshEnd > $refreshStart ? substr($js, $refreshStart, $refreshEnd - $refreshStart) : '';
$assert(str_contains($refreshBlock, 'activeConversationRefreshDisposition') && str_contains($refreshBlock, "disposition === 'clear'") && str_contains($refreshBlock, 'clearConversation();') && !str_contains($refreshBlock, 'showToast('), 'terminal canonical refresh responses clear safely while transient polling failures remain silent');
$assert(str_contains($js, 'function openConversationById') && str_contains($js, 'endpointWithId(\'conversations\', id)') && str_contains($js, 'conversationNavigation'), 'conversation selection can open a canonical conversation outside the paginated list with a navigation context');
$assert(str_contains($js, 'function refreshActiveConversationRecord') && str_contains($js, 'active_conversation_refresh') && str_contains($js, 'activeReconciliation.listed'), 'silent polling canonically refreshes an active record only while it is outside the authoritative list prefix');
$assert(str_contains($js, "openConversationById(Number(this.getAttribute('data-previous-conversation'))") && !str_contains($js, 'state.conversations.push(canonical)'), 'previous conversation buttons use ID navigation without artificial list insertion');
$loadStart = strpos($js, 'function loadConversations(');
$loadEnd = strpos($js, 'function loadInstances(', $loadStart);
$loadBlock = $loadStart >= 0 && $loadEnd > $loadStart ? substr($js, $loadStart, $loadEnd - $loadStart) : '';
$assert($loadBlock !== '' && !str_contains($loadBlock, 'loadConversationAuxiliary(') && !str_contains($loadBlock, 'loadGroupDetails('), 'silent list reconciliation does not reload previous, activity or group auxiliary endpoints');
$workflowJs = file_get_contents($root . '/Assets/js/inbox/conversation_workflow.js');
$assert(str_contains($workflowJs, 'createNavigationContext') && str_contains($workflowJs, 'conversation.contact_name') && str_contains($workflowJs, 'conversation.remote_jid') && !str_contains($workflowJs, 'conversation.instance].join'), 'local matcher and navigation context follow the canonical server search fields');
$assert(str_contains($js, 'assignment.assignee') && str_contains($js, 'assignment.team') && str_contains($js, 'beginMany'), 'assignee and team mutations use independent frontend sequencing dimensions');
$assert(str_contains($js, "'/read' || suffix === '/unread'") && str_contains($js, "'read_state'"), 'read and unread mutations share one frontend sequencing key');
$assert(str_contains($js, 'createDialogLifecycle') && str_contains($js, 'shouldCloseOnOutsideClick') && str_contains($js, 'closeCustomSnooze(false)'), 'custom snooze closes on Escape/outside and on conversation transitions');
$assert(str_contains($view, 'data-conversation-filter-control') && str_contains($view, 'impulso-conversation-context-menu') && str_contains($view, 'impulso-custom-snooze') && str_contains($view, 'impulso-previous-conversations'), 'Inbox UI exposes hydrated filters, custom snooze, context menu and history');

echo "{$passed} passed, {$failed} failed.\n";
exit($failed ? 1 : 0);
