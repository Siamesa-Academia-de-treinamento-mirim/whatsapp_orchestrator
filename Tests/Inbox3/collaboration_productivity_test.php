<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) { echo "[OK] {$message}\n"; $passed++; return; }
    echo "[FAIL] {$message}\n"; $failed++;
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$migration = $read('Database/Migrations/V015_Collaboration_productivity.php');
$runner = $read('Libraries/Migration_runner.php');
$routes = $read('Config/Routes.php');
$actions = $read('Services/Conversation_action_service.php');
$presence = $read('Services/Conversation_presence_service.php');
$views = $read('Services/Saved_view_service.php');
$filterService = $read('Services/Conversation_filter_service.php');
$bulk = $read('Services/Conversation_bulk_action_service.php');
$controller = $read('Controllers/Conversations.php');
$savedController = $read('Controllers/Saved_views.php');
$chat = $read('Services/Chat_service.php');
$notifications = $read('Services/Notification_service.php');
$composer = $read('Assets/js/inbox/composer.js');
$mentions = $read('Assets/js/inbox/mentions.js');
$collaborationContract = $read('Assets/js/inbox/collaboration_contract.js');
$bulkUi = $read('Assets/js/inbox/bulk_actions.js');
$scripts = $read('Views/partials/scripts.php');

$assert(str_contains($migration, 'VERSION = 15') && str_contains($migration, 'chat_internal_note_mentions') && str_contains($migration, 'chat_saved_views') && str_contains($migration, 'chat_conversation_presence'), 'V015 cria somente storage aditivo de Phase 7');
$assert(str_contains($runner, 'V015_Collaboration_productivity::VERSION') && str_contains($runner, "V015_Collaboration_productivity.php"), 'V015 esta registrada no migration runner');
$assert(str_contains($routes, "api/conversations/bulk-action") && str_contains($routes, "api/conversations/(:num)/presence") && str_contains($routes, "api/saved-views/(:num)"), 'rotas de presence, bulk e saved views estao expostas');
$assert(str_contains($controller, 'mention_user_ids') && str_contains($controller, 'Conversation_presence_service') && str_contains($controller, 'Conversation_bulk_action_service'), 'nota, presence e bulk delegam para contratos backend');
$assert(str_contains($savedController, 'listForUser') && str_contains($savedController, 'update') && str_contains($savedController, 'delete'), 'saved views sao CRUD e sempre usam o actor autenticado');
$assert(str_contains($actions, 'validateMentionUserIds') && str_contains($actions, "where('user_type', 'staff')") && str_contains($actions, "where('status', 'active')") && str_contains($actions, 'count($ids) > 20'), 'mentions aceitam apenas staff ativo e limite de 20');
$assert(str_contains($actions, 'IDEMPOTENCY_PAYLOAD_MISMATCH') && str_contains($actions, 'mention_user_ids') && str_contains($actions, 'note-mention|'), 'idempotencia da nota inclui conteudo e IDs de mention ordenados');
$assert(str_contains($actions, 'transStart') && str_contains($actions, 'transComplete') && str_contains($actions, 'notification') && str_contains($actions, 'if ($mentionedUserId === $actorId) continue'), 'nota e relacoes sao transacionais e notificacao nao bloqueia commit nem auto-mention');
$assert(str_contains($presence, '45') && str_contains($presence, '8') && str_contains($presence, 'expires_at') && !str_contains($presence, 'Audit_service'), 'presence e efemera, possui TTL de viewing/typing e nao gera auditoria');
$assert(str_contains($presence, 'strtotime((string) $row[\'typing_until\'] . \' UTC\')') && str_contains($presence, 'typing'), 'presence calcula expiracao de typing por tempo, nao por ordenacao textual');
$assert(str_contains($views, 'MAX_VIEWS = 50') && str_contains($views, 'schema_version') && str_contains($filterService, 'array_diff') && str_contains($views, 'owned'), 'saved views validam schema, filtros permitidos, limite e ownership por validador compartilhado');
$assert(!str_contains($savedController, 'actionFailure') && str_contains($savedController, 'InvalidArgumentException') && str_contains($savedController, 'RuntimeException') && str_contains($savedController, '500'), 'saved view errors map validation, ownership and unexpected failures sem helper inexistente');
$assert(str_contains($filterService, "\$rawStatus === 'unassigned'") && str_contains($filterService, "\$query['unassigned'] = true") && str_contains($filterService, 'teamExists') && str_contains($filterService, 'staffExists') && str_contains($filterService, 'last_activity_from'), 'filtros de listagem e saved views compartilham validacao de instancia, equipe, agente, datas e status canonico');
$assert(str_contains($actions, 'sendLocks->acquireFor') && strpos($actions, 'sendLocks->acquireFor') < strpos($actions, 'find_by_client_message_id') && str_contains($actions, 'finally'), 'add_note adquire lock por conversa/client ID antes do lookup e sempre libera');
$assert(str_contains($actions, 'isInternalNoteMessage') && str_contains($actions, 'IDEMPOTENCY_PAYLOAD_MISMATCH') && str_contains($actions, 'Message_send_exception'), 'note replay exige flag interna, direction interna e tipo note antes de aceitar idempotencia');
$assert(str_contains($controller, 'public function note') && str_contains($controller, 'exception->details()') && str_contains($controller, 'Message_send_exception'), 'note controller preserva o erro estruturado de colisao em 409');
$assert(str_contains($bulk, "'status', 'priority', 'assignment', 'read_state', 'tags_add', 'tags_remove'") && str_contains($bulk, 'count($ids) > 100') && str_contains($bulk, "'summary'") && str_contains($bulk, "'ok' => false"), 'bulk possui allowlist, limite 100 e resultado parcial deterministico');
$assert(str_contains($bulk, 'validatePayload') && str_contains($bulk, "['read', 'unread']") && !str_contains($bulk, "=== 'unread') ?") && str_contains($bulk, 'Operacao nao aplicada para esta conversa.'), 'bulk pre-valida read_state e nao transforma estado invalido em read nem expone erro interno por item');
$assert(str_contains($actions, 'public function add_tags') && str_contains($actions, 'public function remove_tags'), 'bulk de tags usa add/remove sem substituir silenciosamente o conjunto');
$assert(str_contains($chat, 'noteMentionsForMessage') && str_contains($chat, "'mentions' =>"), 'Message DTO projeta somente id, nome e avatar de mentions');
$assert(str_contains($chat, 'noteMentionsForMessages') && str_contains($chat, 'whereIn(\'m.message_id\', $ids)') && str_contains($chat, "? []"), 'Message DTO carrega mentions de notas em lote e evita relation query para mensagens comuns');
$assert(str_contains($notifications, "'mention'=>'at-sign'"), 'notification mapping inclui kind mention');
$assert(str_contains($composer, 'mention_user_ids') && str_contains($composer, 'mentionIdentity') && str_contains($composer, 'matchesSnapshot') && str_contains($composer, 'clearIfMatches'), 'composer captura identidade textual/revisao/mentions e nao limpa metadados de sucesso tardio obsoleto');
$assert(str_contains($mentions, 'reconcileMentionItems') && str_contains($mentions, 'clearIfMatches') && str_contains($mentions, 'snapshot'), 'mention state possui snapshot e reconciliacao fail-safe por conversa');
$assert(str_contains($presence, 'ON DUPLICATE KEY UPDATE') && str_contains($presence, 'typing_until = VALUES(typing_until)') && str_contains($presence, "\$state === 'typing' || \$state === 'leave'") && !str_contains($presence, 'select(\'id, typing_until\')'), 'presence usa upsert atomico e viewing nao regrava typing_until');
$assert(str_contains($collaborationContract, 'canonicalStatusOptions') && str_contains($collaborationContract, 'canonicalPriorityOptions') && str_contains($collaborationContract, 'normalizeBulkReadState'), 'contrato frontend expoe somente opcoes visiveis canonicas para bulk');
$assert(!str_contains($bulkUi, 'ID do agente') && !str_contains($bulkUi, 'Status: open') && str_contains($bulkUi, 'assignmentOptions') && str_contains($bulkUi, 'updateConversationRecord') && !str_contains($bulkUi, 'loadConversations(true, true)'), 'bulk UI usa opcoes de agente/equipe e DTO retornado sem prompts de IDs/enums ou reload forcado');
$assert(str_contains($bulkUi, 'function applyResult') && str_contains($bulkUi, 'checkbox.checked = !!selectedMap') && str_contains($bulkUi, 'state.bulkSelectedIds = failed'), 'bulk render reconcilia checkboxes imediatamente depois de resultados parciais ou sucesso total');
$assert(str_contains($scripts, 'mentions.js') && str_contains($scripts, 'presence.js') && str_contains($scripts, 'saved_views.js') && str_contains($scripts, 'bulk_actions.js') && str_contains($scripts, 'keyboard_navigation.js'), 'frontend Phase 7 usa modulos dedicados');

echo "{$passed} passed, {$failed} failed.\n";
exit($failed ? 1 : 0);
