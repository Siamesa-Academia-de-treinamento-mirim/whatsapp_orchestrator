<?php
$selected = $conversations[0] ?? null;
$channel_stats = [];
$total_unread = 0;

foreach (($instances ?? []) as $instance) {
    $id = (int) ($instance['id'] ?? 0);
    $name = (string) ($instance['name'] ?? 'Canal sem nome');
    $channel_stats[(string) $id] = [
        'id' => $id,
        'name' => $name,
        'phone' => (string) ($instance['phone'] ?? ''),
        'status' => (string) ($instance['status'] ?? 'disconnected'),
        'count' => 0,
        'unread' => 0
    ];
}

foreach (($conversations ?? []) as $conversation) {
    $instance_id = (int) ($conversation['instance_id'] ?? 0);
    $instance_name = (string) ($conversation['instance'] ?? 'Canal sem nome');
    $channel_key = (string) $instance_id;
    if (!isset($channel_stats[$channel_key])) {
        $channel_stats[$channel_key] = [
            'id' => $instance_id,
            'name' => $instance_name,
            'phone' => '',
            'status' => 'connected',
            'count' => 0,
            'unread' => 0
        ];
    }

    $unread = (int) ($conversation['unread'] ?? 0);
    $channel_stats[$channel_key]['count']++;
    $channel_stats[$channel_key]['unread'] += $unread;
    $total_unread += $unread;
}
?>
<div class="impulso-conversations-page">
    <div class="impulso-chat-layout">
        <div class="impulso-inbox-drawer-backdrop impulso-hidden" data-impulso-action="close-inbox-drawers" aria-hidden="true"></div>
        <aside class="impulso-channel-sidebar" id="impulso-channel-sidebar" aria-label="Canais de atendimento">
            <div class="impulso-channel-header">
                <div>
                    <span class="impulso-eyebrow">WhatsApps</span>
                    <h3>Canais</h3>
                </div>
                <span class="impulso-count-badge"><?php echo count($channel_stats); ?></span>
            </div>

            <div class="impulso-channel-list" role="group" aria-label="Filtrar conversas por canal">
                <button class="impulso-channel-item active"
                        type="button"
                        aria-pressed="true"
                        data-channel-filter="all"
                        data-channel-label="Todos os canais">
                    <span class="impulso-channel-icon all"><i data-feather="layers"></i></span>
                    <span class="impulso-channel-copy">
                        <strong>Todos os canais</strong>
                        <small><?php echo count($conversations); ?> conversas</small>
                    </span>
                    <?php if ($total_unread > 0) { ?><span class="impulso-channel-unread"><?php echo $total_unread; ?></span><?php } ?>
                </button>

                <?php foreach ($channel_stats as $channel) { ?>
                    <button class="impulso-channel-item"
                            type="button"
                            aria-pressed="false"
                            title="<?php echo esc($channel['name'] . ($channel['phone'] ? ' · ' . $channel['phone'] : '')); ?>"
                            data-channel-filter="<?php echo (int) $channel['id']; ?>"
                            data-channel-label="<?php echo esc($channel['name']); ?>">
                        <span class="impulso-channel-icon status-<?php echo esc($channel['status']); ?>">
                            <i data-feather="message-circle"></i>
                            <span class="impulso-channel-status-dot" aria-hidden="true"></span>
                        </span>
                        <span class="impulso-channel-copy">
                            <strong><?php echo esc($channel['name']); ?></strong>
                            <small><?php echo $channel['count']; ?> conversa<?php echo $channel['count'] === 1 ? '' : 's'; ?></small>
                        </span>
                        <?php if ($channel['unread'] > 0) { ?><span class="impulso-channel-unread"><?php echo $channel['unread']; ?></span><?php } ?>
                    </button>
                <?php } ?>
            </div>

            <?php if (!empty($can_manage_instances)) { ?>
                <a class="impulso-channel-manage" href="<?php echo get_uri('chatwoot_plugin?chatwoot_tab=instances'); ?>">
                    <i data-feather="settings"></i>
                    <span>Gerenciar instâncias</span>
                </a>
            <?php } ?>
        </aside>

        <aside class="impulso-chat-column impulso-chat-sidebar" id="impulso-chat-sidebar">
            <div class="impulso-chat-sidebar-header">
                <div class="impulso-chat-heading">
                    <div>
                        <h2>Conversas</h2>
                        <span class="impulso-current-channel" id="impulso-current-channel">Todos os canais</span>
                    </div>
                    <span class="impulso-count-badge" id="impulso-visible-conversation-count"><?php echo count($conversations); ?></span>
                </div>

                <div class="impulso-mobile-channel-picker">
                    <label for="impulso-mobile-channel-filter">Canal</label>
                    <select id="impulso-mobile-channel-filter" class="form-control">
                        <option value="all">Todos os canais</option>
                        <?php foreach ($channel_stats as $channel) { ?>
                            <option value="<?php echo (int) $channel['id']; ?>"><?php echo esc($channel['name']); ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="impulso-search">
                    <i data-feather="search"></i>
                    <input type="search" id="impulso-conversation-search" placeholder="Buscar por nome, telefone ou mensagem">
                </div>
                <div class="impulso-workflow-filters" aria-label="Filtros operacionais">
                    <select class="form-control" data-conversation-filter-control="assignee_id" aria-label="Filtrar por agente"><option value="">Todos os agentes</option><option value="unassigned">Sem agente</option><option value="me">Atribuídas a mim</option></select>
                    <select class="form-control" data-conversation-filter-control="team_id" aria-label="Filtrar por equipe"><option value="">Todas as equipes</option></select>
                    <select class="form-control" data-conversation-filter-control="priority" aria-label="Filtrar por prioridade"><option value="">Todas as prioridades</option><option value="none">Sem prioridade</option><option value="low">Baixa</option><option value="medium">Média</option><option value="high">Alta</option><option value="urgent">Urgente</option></select>
                    <select class="form-control" data-conversation-filter-control="unread" aria-label="Filtrar leitura"><option value="">Lidas e não lidas</option><option value="1">Não lidas</option><option value="0">Lidas</option></select>
                    <select class="form-control" data-conversation-filter-control="conversation_type" aria-label="Filtrar tipo"><option value="">Individuais e grupos</option><option value="individual">Individuais</option><option value="group">Grupos</option></select>
                    <select class="form-control" data-conversation-filter-control="bot_status" aria-label="Filtrar bot"><option value="">Todos os bots</option><option value="running">Bot ativo</option><option value="paused">Bot pausado</option><option value="handoff">Handoff humano</option></select>
                    <input class="form-control" data-conversation-filter-control="last_activity_from" type="date" aria-label="Atividade desde">
                    <input class="form-control" data-conversation-filter-control="last_activity_to" type="date" aria-label="Atividade até">
                    <input class="form-control" data-conversation-filter-control="tags" type="search" placeholder="Tags (separadas por vírgula)" aria-label="Filtrar tags">
                </div>
                <div class="impulso-active-filter-row"><div id="impulso-active-filter-summary" aria-live="polite"></div><button type="button" class="btn btn-link btn-sm impulso-hidden" data-conversation-filter-clear>Limpar filtros</button></div>
            </div>
            <div class="impulso-queue-tabs" role="group" aria-label="Filtrar conversas por status">
                <button class="impulso-queue-tab active" type="button" aria-pressed="true" data-conversation-filter="all">Todas</button>
                <button class="impulso-queue-tab" type="button" aria-pressed="false" data-conversation-filter="open">Abertas <span data-filter-count="open"></span></button>
                <button class="impulso-queue-tab" type="button" aria-pressed="false" data-conversation-filter="pending">Pendentes <span data-filter-count="pending"></span></button>
                <button class="impulso-queue-tab" type="button" aria-pressed="false" data-conversation-filter="snoozed">Adiadas <span data-filter-count="snoozed"></span></button>
                <button class="impulso-queue-tab" type="button" aria-pressed="false" data-conversation-filter="resolved">Resolvidas <span data-filter-count="resolved"></span></button>
                <button class="impulso-queue-tab" type="button" aria-pressed="false" data-conversation-filter="unassigned">Sem agente</button>
            </div>
            <div class="impulso-conversation-list" id="impulso-conversation-list">
                <?php foreach ($conversations as $index => $conversation) { ?>
                <button class="impulso-conversation-item <?php echo $index === 0 ? 'active ' : ''; ?><?php echo ((int) ($conversation['unread'] ?? 0) > 0) ? 'unread' : ''; ?>"
                        type="button"
                        data-conversation-id="<?php echo (int) $conversation['id']; ?>"
                        data-status="<?php echo esc($conversation['status']); ?>"
                        data-assignee="<?php echo esc($conversation['assignee']); ?>"
                        data-instance-id="<?php echo (int) ($conversation['instance_id'] ?? 0); ?>"
                        data-search="<?php echo esc(strtolower($conversation['name'] . ' ' . $conversation['phone'] . ' ' . $conversation['last_message'] . ' ' . $conversation['instance'])); ?>">
                    <div class="impulso-conversation-line">
                        <div class="impulso-avatar"><?php echo esc($conversation['avatar']); ?></div>
                        <div class="impulso-conversation-copy">
                            <div class="impulso-conversation-title">
                                <strong><?php echo esc($conversation['name']); ?></strong>
                                <span class="impulso-conversation-time"><?php echo esc($conversation['time']); ?></span>
                            </div>
                            <div class="impulso-conversation-preview"><?php echo esc($conversation['last_message']); ?></div>
                            <div class="impulso-conversation-meta">
                                <span class="impulso-instance-mini"><i data-feather="smartphone"></i> <?php echo esc($conversation['instance']); ?></span>
                                <?php if ((int) $conversation['unread'] > 0) { ?><span class="impulso-unread"><?php echo (int) $conversation['unread']; ?></span><?php } ?>
                            </div>
                        </div>
                    </div>
                </button>
                <?php } ?>

                <div class="impulso-conversation-empty impulso-hidden" id="impulso-conversation-empty">
                    <div class="impulso-empty-icon"><i data-feather="inbox"></i></div>
                    <strong>Nenhuma conversa encontrada</strong>
                    <span>Altere o canal, o status ou a busca.</span>
                </div>
            </div>
        </aside>

        <section class="impulso-chat-column impulso-chat-main">
            <header class="impulso-chat-header">
                <div class="impulso-chat-header-main">
                    <button class="impulso-icon-button btn btn-default impulso-panel-toggle impulso-channel-panel-toggle" type="button" data-impulso-action="toggle-channel-sidebar" data-panel-toggle="channel" aria-controls="impulso-channel-sidebar" aria-expanded="true" aria-label="Recolher canais" title="Recolher canais"><i data-feather="sidebar"></i><span class="impulso-sr-only">Recolher canais</span></button>
                    <button class="impulso-icon-button btn btn-default impulso-open-conversation-list impulso-panel-toggle" type="button" data-impulso-action="toggle-conversation-sidebar" data-panel-toggle="conversation" aria-controls="impulso-chat-sidebar" aria-expanded="true" aria-label="Recolher conversas" title="Recolher conversas"><i data-feather="menu"></i><span class="impulso-sr-only">Recolher conversas</span></button>
                    <div class="impulso-avatar" id="impulso-active-avatar"><?php echo esc($selected['avatar'] ?? '—'); ?></div>
                    <div class="impulso-chat-header-copy">
                        <h3 id="impulso-active-name"><?php echo esc($selected['name'] ?? 'Selecione uma conversa'); ?></h3>
                        <p><span class="impulso-active-channel-chip"><i data-feather="message-circle"></i><span id="impulso-active-instance"><?php echo esc($selected['instance'] ?? ''); ?></span></span> <span class="impulso-text-success" id="impulso-active-connection"><?php echo (($selected['instance_status'] ?? '') === 'connected') ? 'WhatsApp conectado' : 'Canal indisponível'; ?></span></p>
                    </div>
                </div>
                <div class="impulso-chat-header-actions">
                    <button class="impulso-icon-button btn btn-default impulso-mobile-hide" type="button" data-impulso-action="call-contact" title="Abrir chamada para o contato"><i data-feather="phone"></i></button>
                    <button class="impulso-icon-button btn btn-default impulso-mobile-hide" type="button" data-impulso-action="search-history" title="Buscar no histórico"><i data-feather="search"></i></button>
                    <button class="btn btn-default btn-sm impulso-mobile-hide" type="button" data-impulso-action="toggle-priority" id="impulso-priority-button"><i data-feather="flag"></i> Prioridade</button>
                    <button class="btn btn-success btn-sm" type="button" data-impulso-action="resolve-conversation" id="impulso-resolve-button"><i data-feather="check"></i> Resolver</button>
                    <button class="impulso-icon-button btn btn-default" type="button" data-impulso-action="open-contact"><i data-feather="sidebar"></i></button>
                </div>
            </header>

            <div class="impulso-history-search impulso-hidden" id="impulso-history-search-panel">
                <i data-feather="search"></i>
                <input type="search" id="impulso-history-search-input" placeholder="Buscar nesta conversa">
                <span id="impulso-history-search-count">0 resultados</span>
                <button class="impulso-icon-button btn btn-default" type="button" data-impulso-action="close-history-search"><i data-feather="x"></i></button>
            </div>
            <div class="impulso-chat-body" id="impulso-chat-body">
                <?php if (!empty($selected['messages'])) { ?>
                <div class="impulso-day-divider">Hoje</div>
                <?php foreach ($selected['messages'] as $message) { ?>
                    <div class="impulso-message-row <?php echo esc($message['type']); ?>">
                        <div class="impulso-message">
                            <p><?php echo esc($message['text']); ?></p>
                            <div class="impulso-message-footer">
                                <span><?php echo esc($message['time']); ?></span>
                                <?php if ($message['type'] === 'outgoing') { ?><i data-feather="check"></i><?php } ?>
                            </div>
                        </div>
                    </div>
                <?php } ?>
                <?php } else { ?>
                    <div class="impulso-empty"><div class="impulso-empty-icon"><i data-feather="message-square"></i></div><h4>Carregando histórico</h4><p>As mensagens serão consultadas pela integração.</p></div>
                <?php } ?>
            </div>
            <div class="impulso-context-menu impulso-hidden" id="impulso-message-context-menu" role="menu" aria-label="Ações da mensagem"></div>
            <div class="impulso-context-menu impulso-hidden" id="impulso-conversation-context-menu" role="menu" aria-label="Ações da conversa"></div>

            <div class="impulso-composer">
                <div class="impulso-composer-mode">
                    <button class="impulso-mode-button active" type="button" data-composer-mode="reply">Responder</button>
                    <button class="impulso-mode-button" type="button" data-composer-mode="note">Nota interna</button>
                    <span class="impulso-badge"><i data-feather="shield"></i> Bot com respostas definidas</span>
                </div>
                <div class="impulso-composer-box">
                    <div class="impulso-composer-tools">
                        <button class="impulso-tool-button" type="button" data-impulso-action="attach" title="Enviar imagem, áudio ou documento"><i data-feather="paperclip"></i></button>
                        <button class="impulso-tool-button impulso-hidden" type="button" data-impulso-action="templates" id="impulso-template-button" title="Enviar template aprovado" aria-label="Enviar template aprovado" aria-expanded="false" aria-controls="impulso-template-picker"><i data-feather="file-text"></i></button>
                        <button class="impulso-tool-button" type="button" data-impulso-action="emoji" title="Emoji" aria-label="Abrir seletor de emoji" aria-expanded="false" aria-controls="impulso-emoji-picker"><i data-feather="smile"></i></button>
                        <button class="impulso-tool-button" type="button" data-impulso-action="quick-replies" title="Respostas rápidas" aria-label="Abrir respostas rápidas" aria-expanded="false" aria-controls="impulso-quick-replies"><i data-feather="zap"></i></button>
                    </div>
                    <textarea id="impulso-message-input" rows="1" placeholder="Digite uma mensagem…"></textarea>
                    <button class="impulso-tool-button" type="button" data-impulso-action="voice" id="impulso-voice-button" title="Gravar áudio"><i data-feather="mic"></i></button>
                    <button class="impulso-send-button" type="button" id="impulso-send-message" title="Enviar"><i data-feather="send"></i></button>
                </div>
                <input id="impulso-attachment-input" type="file" multiple hidden accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt">
                <div id="impulso-drop-affordance" class="impulso-drop-affordance impulso-hidden" role="status" aria-live="polite"><i data-feather="upload-cloud"></i><span>Solte os arquivos aqui</span></div>
                <div id="impulso-service-window" class="impulso-composer-hint impulso-hidden" role="status" aria-live="polite"></div>
                <div class="impulso-composer-popover impulso-hidden" id="impulso-emoji-picker" aria-label="Seletor de emojis"></div>
                <div class="impulso-composer-popover impulso-hidden" id="impulso-quick-replies" aria-label="Respostas rápidas"></div>
                <div class="impulso-composer-popover impulso-hidden" id="impulso-template-picker" aria-label="Templates oficiais"></div>
                <div class="impulso-attachment-preview impulso-hidden" id="impulso-attachment-preview"></div>
                <div class="impulso-composer-hint" id="impulso-composer-hint">Enter para enviar · Shift + Enter para quebrar linha</div>
            </div>
        </section>

        <aside class="impulso-chat-column impulso-contact-sidebar" id="impulso-contact-sidebar">
            <div class="impulso-contact-profile">
                <div class="impulso-avatar lg" id="impulso-contact-avatar"><?php echo esc($selected['avatar'] ?? '—'); ?></div>
                <h3 id="impulso-contact-name"><?php echo esc($selected['name'] ?? ''); ?></h3>
                <p id="impulso-contact-phone"><?php echo esc($selected['phone'] ?? ''); ?></p>
                <div class="impulso-profile-actions">
                    <button class="impulso-profile-action" type="button" data-impulso-action="call-contact"><i data-feather="phone"></i><span>Ligar</span></button>
                    <button class="impulso-profile-action" type="button" data-impulso-action="edit-contact"><i data-feather="edit-3"></i><span>Editar</span></button>
                    <button class="impulso-profile-action" type="button" data-impulso-action="contact-menu"><i data-feather="more-horizontal"></i><span>Mais</span></button>
                </div>
            </div>

            <div class="impulso-contact-section">
                <div class="impulso-contact-section-title"><span>Atendimento</span><button class="btn btn-default btn-sm" type="button" data-impulso-action="edit-assignment">Editar</button></div>
                <div class="impulso-contact-item"><i data-feather="user"></i><div class="impulso-contact-item-copy"><span>Responsável</span><strong id="impulso-contact-assignee"><?php echo esc($selected['assignee'] ?? ''); ?></strong></div></div>
                <div class="impulso-contact-item"><i data-feather="users"></i><div class="impulso-contact-item-copy"><span>Equipe</span><strong id="impulso-contact-team"><?php echo esc($selected['team'] ?? ''); ?></strong></div></div>
                <div class="impulso-contact-item"><i data-feather="inbox"></i><div class="impulso-contact-item-copy"><span>Caixa de entrada</span><strong id="impulso-contact-instance"><?php echo esc($selected['instance'] ?? ''); ?></strong></div></div>
                <select class="impulso-select-small" id="impulso-assignee-select"><option value="">Não atribuído</option></select>
            </div>

            <div class="impulso-contact-section">
                <div class="impulso-contact-section-title"><span>Dados do contato</span><i data-feather="edit-2" class="impulso-muted-icon"></i></div>
                <div class="impulso-contact-item"><i data-feather="mail"></i><div class="impulso-contact-item-copy"><span>E-mail</span><strong id="impulso-contact-email"><?php echo esc(($selected['email'] ?? '') ?: 'Não informado'); ?></strong></div></div>
                <div class="impulso-contact-item"><i data-feather="map-pin"></i><div class="impulso-contact-item-copy"><span>Cidade</span><strong id="impulso-contact-city"><?php echo esc($selected['city'] ?? ''); ?></strong></div></div>
                <div class="impulso-contact-item"><i data-feather="target"></i><div class="impulso-contact-item-copy"><span>Origem</span><strong id="impulso-contact-source"><?php echo esc($selected['source'] ?? ''); ?></strong></div></div>
                <div class="impulso-contact-item"><i data-feather="calendar"></i><div class="impulso-contact-item-copy"><span>Primeiro contato</span><strong id="impulso-contact-created"><?php echo esc($selected['created_at'] ?? ''); ?></strong></div></div>
            </div>

            <div class="impulso-contact-section">
                <div class="impulso-contact-section-title"><span>Etiquetas</span><button class="btn btn-default btn-sm" type="button" data-impulso-action="edit-tags">+</button></div>
                <div class="impulso-tag-list" id="impulso-contact-tags">
                    <?php foreach (($selected['tags'] ?? []) as $tag) { ?><span class="impulso-badge primary"><?php echo esc($tag); ?></span><?php } ?>
                </div>
            </div>

            <div class="impulso-contact-section">
                <div class="impulso-contact-section-title"><span>Fluxo da conversa</span><button class="btn btn-default btn-sm" type="button" id="impulso-snooze-button">Adiar</button></div>
                <div class="impulso-workflow-controls">
                    <label>Status<select class="form-control impulso-select-small" id="impulso-conversation-status"><option value="open">Aberta</option><option value="pending">Pendente</option><option value="resolved">Resolvida</option><option value="snoozed">Adiada</option></select></label>
                    <label>Prioridade<select class="form-control impulso-select-small" id="impulso-conversation-priority"><option value="none">Sem prioridade</option><option value="low">Baixa</option><option value="medium">Média</option><option value="high">Alta</option><option value="urgent">Urgente</option></select></label>
                </div>
                <select class="impulso-select-small" id="impulso-team-select"><option value="">Sem equipe</option></select>
                <small id="impulso-conversation-snooze">Sem snooze ativo</small>
                <div id="impulso-custom-snooze" class="impulso-custom-snooze impulso-hidden" role="dialog" aria-label="Escolher data e hora do snooze">
                    <label for="impulso-custom-snooze-input">Data e hora</label>
                    <input id="impulso-custom-snooze-input" type="datetime-local" class="form-control">
                    <div class="impulso-custom-snooze-actions"><button type="button" class="btn btn-link btn-sm" id="impulso-custom-snooze-cancel">Cancelar</button><button type="button" class="btn btn-primary btn-sm" id="impulso-custom-snooze-apply">Aplicar</button></div>
                </div>
            </div>

            <div class="impulso-contact-section impulso-hidden" id="impulso-group-section">
                <div class="impulso-contact-section-title"><span>Participantes do grupo</span><span class="impulso-badge" id="impulso-group-participant-count">0</span></div>
                <div id="impulso-group-participants"><small>Os participantes serão identificados conforme enviarem mensagens.</small></div>
            </div>

            <div class="impulso-contact-section">
                <div class="impulso-contact-section-title"><span>Conversas anteriores</span></div>
                <div id="impulso-previous-conversations"><small>Selecione uma conversa.</small></div>
            </div>

            <div class="impulso-contact-section">
                <div class="impulso-contact-section-title"><span>Atividade</span><button class="btn btn-default btn-sm" type="button" id="impulso-mark-unread">Não lida</button></div>
                <div id="impulso-conversation-activity"><small>Selecione uma conversa.</small></div>
            </div>

            <div class="impulso-contact-section">
                <div class="impulso-contact-section-title"><span>Bot de atendimento</span><button class="btn btn-default btn-sm" type="button" data-impulso-action="toggle-conversation-bot">Pausar</button></div>
                <div class="impulso-contact-item"><i data-feather="shield"></i><div class="impulso-contact-item-copy"><span>Estado</span><strong id="impulso-bot-conversation-state">Ativo até um atendente responder</strong></div></div>
                <small>Quando um atendente envia uma mensagem, o bot é pausado automaticamente para evitar respostas conflitantes.</small>
            </div>
        </aside>
    </div>
</div>

<script type="application/json" id="impulso-conversation-data"><?php echo json_encode($conversations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
