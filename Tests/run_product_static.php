<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
$failures = [];
$passed = 0;
$test = static function (string $name, callable $callback) use (&$failures, &$passed): void {
    try {
        $callback();
        $passed++;
        echo "[OK] {$name}\n";
    } catch (Throwable $exception) {
        $failures[] = $name . ': ' . $exception->getMessage();
        echo "[FAIL] {$name}\n";
    }
};
$assert = static function ($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    $contents = is_file($path) ? file_get_contents($path) : false;
    if ($contents === false) throw new RuntimeException('Arquivo ausente: ' . $relative);
    return $contents;
};

$test('rotas do produto atual estão expostas', static function () use ($read, $assert): void {
    $routes = $read('Config/Routes.php');
    $required = [
        'api/conversations/(:num)/group',
        'api/contact-repairs/preview',
        'api/campaigns/audience-preview',
        'api/campaigns/health',
        'api/campaigns/(:num)/runs',
        'api/campaigns/(:num)/runs/(:num)/recipients',
        'api/bots/simulate',
        'api/bots/(:num)/publish',
        'api/conversations/(:num)/bot/pause',
        'api/instances/(:num)/official-templates/sync',
        'chatwoot_plugin/webhooks/meta/(:segment)',
    ];
    $missing = array_values(array_filter($required, static fn (string $route): bool => !str_contains($routes, "'{$route}'")));
    $assert($missing === [], 'Rotas ausentes: ' . implode(', ', $missing));
});

$test('módulos deliberadamente removidos não possuem rotas públicas', static function () use ($read, $assert): void {
    $routes = $read('Config/Routes.php');
    $forbidden = ['api/ai/', 'api/reports', 'api/audit-logs', 'api/automations', 'api/integrations/n8n'];
    $present = array_values(array_filter($forbidden, static fn (string $route): bool => str_contains($routes, $route)));
    $assert($present === [], 'Rotas proibidas ainda públicas: ' . implode(', ', $present));
});

$test('navegação contém somente os módulos definidos para o produto', static function () use ($read, $assert): void {
    $index = $read('Views/index.php');
    foreach (['dashboard', 'conversations', 'contacts', 'instances', 'campaigns', 'bots', 'settings'] as $tab) {
        $assert(str_contains($index, "data-tab=\"{$tab}\"") || str_contains($index, "'{$tab}'"), 'Aba ausente: ' . $tab);
    }
    foreach (['partials/ai.php', 'partials/reports.php', 'Captain', 'Chat ao vivo', '>SMS<'] as $forbidden) {
        $assert(!str_contains($index, $forbidden), 'Módulo removido ainda aparece na navegação: ' . $forbidden);
    }
});

$test('assets e hooks acompanham o nome real da pasta instalada', static function () use ($read, $assert): void {
    $scripts = $read('Views/partials/scripts.php');
    $index = $read('index.php');
    $assert(!str_contains($scripts, "base_url('plugins/Chatwoot_plugin/"), 'Assets voltaram a apontar para a pasta legada Chatwoot_plugin.');
    $assert(str_contains($scripts, 'CHATWOOT_PLUGIN_FOLDER') && str_contains($scripts, 'basename($plugin_root)'), 'Caminho dinâmico dos assets não foi preservado.');
    $assert(str_contains($index, "define('CHATWOOT_PLUGIN_FOLDER', basename(__DIR__))"), 'Identificador físico dinâmico não foi registrado.');
    $assert(str_contains($index, "register_activation_hook(CHATWOOT_PLUGIN_FOLDER"), 'Hook de ativação voltou a usar um nome fixo.');
});

$test('código executável de IA, relatórios e n8n foi retirado', static function () use ($root, $assert): void {
    $forbidden = [
        'Controllers/Ai_agents.php', 'Controllers/Ai_logs.php', 'Controllers/Ai_state.php',
        'Controllers/Automations.php', 'Controllers/Reports.php', 'Controllers/Integrations.php',
        'Services/Ai_service.php', 'Services/Automation_service.php', 'Services/Report_service.php',
        'Libraries/N8n_client.php', 'Views/partials/ai.php', 'Views/partials/reports.php',
    ];
    $present = array_values(array_filter($forbidden, static fn (string $file): bool => is_file($root . '/' . $file)));
    $assert($present === [], 'Código legado ainda presente: ' . implode(', ', $present));
});

$test('conversas ocupam o viewport sem criar rolagem externa', static function () use ($read, $assert): void {
    $index = $read('Views/index.php');
    $styles = $read('Views/partials/styles.php');
    $assert(str_contains($index, 'impulso-page-content--conversations'), 'O container de conversas nao recebeu o escopo responsivo.');
    foreach (['#page-content.impulso-page-content--conversations', '--impulso-available-height', 'min-height: 0 !important', 'overflow: hidden'] as $needle) {
        $assert(str_contains($styles, $needle), 'Regra responsiva ausente: ' . $needle);
    }
});

$test('caixa de entrada usa leitura local imediata e sincronizacao remota limitada', static function () use ($read, $assert): void {
    $javascript = $read('Assets/js/chatwoot.js');
    $chat = $read('Services/Chat_service.php');
    $page = $read('Controllers/Chatwoot.php');
    foreach (['pendingSends', 'syncConversationHistory', 'remoteSyncInterval', "loadMessages('after', false)"] as $needle) {
        $assert(str_contains($javascript, $needle), 'Fluxo local-first ausente: ' . $needle);
    }
    $assert(!str_contains($javascript, "messageUrl += '/sync'"), 'A abertura do historico ainda bloqueia na Evolution.');
    foreach (["'offset' => \$limit", 'messageRawPayload', 'provider_event_compact'] as $needle) {
        $assert(str_contains($chat, $needle), 'Limite de sincronizacao/armazenamento ausente: ' . $needle);
    }
    $assert(str_contains($page, "\$activeTab === 'contacts'"), 'Modulos fora da aba ativa ainda sao carregados sem necessidade.');
});

$test('provedores implementam o mesmo contrato', static function () use ($read, $assert): void {
    $contract = $read('Contracts/WhatsAppProviderInterface.php');
    foreach (['sendText', 'sendMedia', 'sendTemplate', 'sendReaction', 'normalizeWebhook', 'getCapabilities', 'testConnection'] as $method) {
        $assert(str_contains($contract, 'function ' . $method . '('), 'Método ausente no contrato: ' . $method);
    }
    foreach (['Providers/Evolution_provider.php', 'Providers/Meta_cloud_provider.php'] as $provider) {
        $source = $read($provider);
        $assert(str_contains($source, 'implements WhatsAppProviderInterface'), 'Provider fora do contrato: ' . $provider);
    }
});

$test('migrações críticas estão registradas até a versão 15', static function () use ($read, $assert): void {
    $runner = $read('Libraries/Migration_runner.php');
    foreach (range(4, 13) as $version) {
        $prefix = $version < 10 ? 'V00' : 'V0';
        $assert(str_contains($runner, $prefix . $version . '_'), 'Migração V' . str_pad((string) $version, 3, '0', STR_PAD_LEFT) . ' não registrada.');
    }
    $assert(str_contains($runner, 'V008_Migrate_legacy_campaign_dispatch::VERSION'), 'Migração de campanhas legadas não registrada.');
    $assert(str_contains($runner, 'V009_Retire_legacy_ai_reports_and_n8n::VERSION'), 'Migração de retirada dos módulos legados não registrada.');
    $assert(str_contains($runner, 'V010_Add_message_delivery_timestamps::VERSION'), 'Migração de timestamps de entrega/leitura não registrada.');
    $assert(str_contains($runner, 'V011_Create_chat_message_reactions::VERSION'), 'Migration V011 de reacoes de mensagens nao registrada.');
    $assert(str_contains($runner, 'V012_Create_chat_message_reaction_attempts::VERSION'), 'Migration V012 de tentativas de reacoes nao registrada.');
    $assert(str_contains($runner, 'V013_Harden_chat_message_reactions::VERSION'), 'Migration V013 de hardening de reacoes nao registrada.');
    $migration = $read('Database/Migrations/V014_Add_conversation_workflow_snooze.php');
    $assert(str_contains($runner, 'V014_Add_conversation_workflow_snooze::VERSION') && str_contains($migration, 'snoozed_until') && str_contains($migration, 'idx_chat_conversation_snooze'), 'Migration V014 de snooze nao registrada corretamente.');
    $phase7 = $read('Database/Migrations/V015_Collaboration_productivity.php');
    $assert(str_contains($runner, 'V015_Collaboration_productivity::VERSION') && str_contains($phase7, 'chat_internal_note_mentions') && str_contains($phase7, 'chat_saved_views') && str_contains($phase7, 'chat_conversation_presence'), 'Migration V015 de collaboration nao registrada corretamente.');
    $legacy = $read('Database/Migrations/V008_Migrate_legacy_campaign_dispatch.php');
    $errorPosition = strpos($legacy, 'SET `last_error`');
    $statusPosition = strpos($legacy, '`status` = CASE');
    $assert($errorPosition !== false && $statusPosition !== false && $errorPosition < $statusPosition, 'V008 deve registrar o motivo antes de alterar o status no MySQL.');
});

$test('identidade de grupos e contatos possui invariantes explícitas', static function () use ($read, $assert): void {
    $normalizer = $read('Services/Webhook_normalizer.php');
    $migration = $read('Database/Migrations/V004_Add_channels_groups_and_bots.php');
    $assert(str_contains($normalizer, "(\$fromMe ? '' :"), 'Evento outgoing ainda pode carregar nome do proprietário.');
    foreach (['sender_jid', 'sender_phone', 'sender_name', 'is_group_message', 'chat_group_participants'] as $needle) {
        $assert(str_contains($migration, $needle), 'Estrutura de grupo ausente: ' . $needle);
    }
});

$test('campanhas usam fila interna e histórico por ocorrência', static function () use ($read, $assert): void {
    $service = $read('Services/Campaign_service.php');
    $dispatcher = $read('Services/Campaign_dispatch_service.php');
    $assert(!str_contains($service, 'N8n_client'), 'Campaign_service ainda depende do n8n.');
    $assert(str_contains($service, "'dispatch_mode' => 'internal_queue'"), 'Fila interna não é obrigatória.');
    $assert(str_contains($dispatcher, 'chat_campaign_run_recipients'), 'Dispatcher não usa snapshots por ocorrência.');
    $assert(str_contains($dispatcher, 'GET_LOCK'), 'Dispatcher não possui serialização concorrente.');
});


$test('worker interno e histórico operacional estão disponíveis', static function () use ($read, $assert): void {
    $command = $read('Commands/Chatwoot_jobs.php');
    $cron = $read('cron.php');
    $workspace = $read('Assets/js/hub-workspace.js');
    $modal = $read('Views/modals/common.php');
    foreach (['impulso:chat-jobs', 'Integration_job_service'] as $needle) {
        $assert(str_contains($command, $needle), 'Worker interno incompleto: ' . $needle);
    }
    foreach (['PHP_SAPI', 'Migration_runner', 'Integration_job_service'] as $needle) {
        $assert(str_contains($cron, $needle), 'Cron seguro incompleto: ' . $needle);
    }
    foreach (['openCampaignHistory', 'loadCampaignRunRecipients', 'data-campaign-run-id'] as $needle) {
        $assert(str_contains($workspace, $needle), 'Histórico de campanha ausente no workspace: ' . $needle);
    }
    $assert(str_contains($modal, 'impulso-campaign-history-modal'), 'Modal de histórico não registrado.');
});

$test('bot determinístico não executa IA, regex ou código arbitrário', static function () use ($read, $assert): void {
    $validator = $read('Services/Bot_flow_validator.php');
    $service = $read('Services/Bot_service.php');
    foreach (['eval(', 'preg_replace_callback', 'shell_exec', 'OpenAI', 'ChatGPT'] as $needle) {
        $assert(!str_contains($validator . $service, $needle), 'Execução não determinística encontrada: ' . $needle);
    }
    foreach (['fallback_message', 'handoff_message', 'max_fallbacks', 'pauseConversation'] as $needle) {
        $assert(str_contains($validator . $service, $needle), 'Guardrail ausente: ' . $needle);
    }
});

$test('webhook Meta exige verificação e assinatura', static function () use ($read, $assert): void {
    $source = $read('Controllers/Meta_webhooks.php') . $read('Libraries/Meta_cloud_client.php');
    foreach (['hub_verify_token', 'X-Hub-Signature-256', 'hash_hmac', 'hash_equals'] as $needle) {
        $assert(str_contains($source, $needle), 'Proteção Meta ausente: ' . $needle);
    }
});

$test('resposta humana pausa o bot somente após envio bem-sucedido', static function () use ($read, $assert): void {
    $chat = $read('Services/Chat_service.php');
    $media = $read('Services/Media_service.php');
    $sendPos = strpos($chat, 'send_text(');
    $pausePos = strpos($chat, 'pauseConversation', $sendPos === false ? 0 : $sendPos);
    $assert($sendPos !== false && $pausePos !== false && $pausePos > $sendPos, 'Pausa do bot no texto está antes do envio.');
    $providerPos = strpos($media, 'sendMedia(');
    $mediaPausePos = strpos($media, 'pauseConversation', $providerPos === false ? 0 : $providerPos);
    $assert($providerPos !== false && $mediaPausePos !== false && $mediaPausePos > $providerPos, 'Pausa do bot na mídia está antes do envio.');
});

$test('Reaction Engine mantém fronteiras de estado e retry terminal', static function () use ($read, $assert): void {
    $locks = $read('Services/Send_lock_service.php');
    $reactionService = $read('Services/Message_reaction_service.php');
    $reactionModel = $read('Models/Chat_message_reactions_model.php');
    $attemptModel = $read('Models/Chat_message_reaction_attempts_model.php');
    $webhookModel = $read('Models/Chat_webhook_logs_model.php');
    $chat = $read('Services/Chat_service.php');
    $jobs = $read('Services/Integration_job_service.php');
    foreach (['nameForReaction', 'acquireReaction', 'releaseReaction'] as $needle) {
        $assert(str_contains($locks, $needle), 'lock por instance/message/reactor ausente: ' . $needle);
    }
    foreach (['confirmAttemptState', 'attemptCanRecoverState', 'previous_source_attempt_id'] as $needle) {
        $assert(str_contains($reactionService, $needle), 'confirmação serializada/previous state ausente: ' . $needle);
    }
    foreach (['transBegin', 'transRollback', 'appendChange'] as $needle) {
        $assert(str_contains($reactionModel, $needle), 'atomicidade V011/change cursor ausente: ' . $needle);
    }
    $assert(substr_count($chat, 'confirmAttemptState') >= 2, 'Chat_service não usa confirmação serializada no envio e no receipt');
    $identityLockPos = strpos($chat, 'acquireReaction((int) $instance');
    $providerReactionPos = strpos($chat, '$provider->sendReaction');
    $outboundConfirmPos = strpos($chat, 'confirmAttemptState($attemptId, $providerEventId, true)');
    $assert($identityLockPos !== false && $providerReactionPos !== false && $outboundConfirmPos !== false
        && $identityLockPos < $providerReactionPos && $providerReactionPos < $outboundConfirmPos, 'envio outbound não permanece serializado antes do provider e até a confirmação');
    $assert(str_contains($reactionService, 'operationOrderAt($freshAttempt)'), 'rollback não usa a ordem original da tentativa');
    $assert(str_contains($webhookModel, 'find_terminal') && str_contains($chat, 'terminalWebhookResult'), 'redelivery terminal não possui consulta/guarda explícita por dedupe key');
    foreach (['retry_exhausted', 'markWebhookRetryExhausted', 'processed_at\' => $now'] as $needle) {
        $assert(str_contains($jobs, $needle), 'retry terminal de webhook ausente: ' . $needle);
    }
    $assert(str_contains($attemptModel, 'if ($currentState === \'rejected\'') && str_contains($attemptModel, 'providerAt === null'), 'state machine V012 protege rejeição terminal e timestamp existente');
});

echo "\n{$passed} passed, " . count($failures) . " failed.\n";
if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    exit(1);
}
