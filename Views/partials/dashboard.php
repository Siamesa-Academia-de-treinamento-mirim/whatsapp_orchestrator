<div class="impulso-page">
    <div class="impulso-section-heading">
        <div>
            <h2>Visão geral</h2>
            <p>Acompanhe conversas, canais oficiais e não oficiais e a operação dos bots.</p>
        </div>
        <div class="impulso-section-actions">
            <button class="btn btn-default" type="button" data-impulso-action="refresh-dashboard"><i data-feather="refresh-cw"></i> Atualizar</button>
            <a class="btn btn-primary" href="<?php echo get_uri('chatwoot_plugin?chatwoot_tab=conversations'); ?>"><i data-feather="inbox"></i> Abrir caixa de entrada</a>
        </div>
    </div>

    <div class="impulso-grid impulso-grid-4 impulso-mb-14">
        <div class="impulso-card impulso-stat-card">
            <div class="impulso-stat-top">
                <div>
                    <div class="impulso-stat-label">Conversas abertas</div>
                    <div class="impulso-stat-value"><?php echo (int) $summary['open']; ?></div>
                    <div class="impulso-stat-trend">Dados persistidos no plugin</div>
                </div>
                <div class="impulso-stat-icon"><i data-feather="message-circle"></i></div>
            </div>
        </div>
        <div class="impulso-card impulso-stat-card">
            <div class="impulso-stat-top">
                <div>
                    <div class="impulso-stat-label">Aguardando resposta</div>
                    <div class="impulso-stat-value"><?php echo (int) $summary['pending']; ?></div>
                    <div class="impulso-stat-trend">Status atual das conversas</div>
                </div>
                <div class="impulso-stat-icon warning"><i data-feather="clock"></i></div>
            </div>
        </div>
        <div class="impulso-card impulso-stat-card">
            <div class="impulso-stat-top">
                <div>
                    <div class="impulso-stat-label">Resolvidas hoje</div>
                    <div class="impulso-stat-value"><?php echo (int) $summary['resolved_today']; ?></div>
                    <div class="impulso-stat-trend">Atualizadas no dia</div>
                </div>
                <div class="impulso-stat-icon success"><i data-feather="check-circle"></i></div>
            </div>
        </div>
        <div class="impulso-card impulso-stat-card">
            <div class="impulso-stat-top">
                <div>
                    <div class="impulso-stat-label">Canais conectados</div>
                    <div class="impulso-stat-value"><?php echo (int) $summary['connected_instances']; ?></div>
                    <div class="impulso-stat-trend">Evolution e WhatsApp Cloud API</div>
                </div>
                <div class="impulso-stat-icon info"><i data-feather="smartphone"></i></div>
            </div>
        </div>
    </div>

    <div class="impulso-grid impulso-grid-2 impulso-mb-14">
        <div class="impulso-card">
            <div class="impulso-card-header">
                <div><h3>Fila de atendimento</h3><p>Prioridades, distribuição e tempo de resposta</p></div>
                <span class="impulso-badge success"><span class="impulso-dot"></span> <?php echo (int) $summary['connected_instances']; ?> canais conectados</span>
            </div>
            <div class="impulso-card-body">
                <ul class="impulso-list">
                    <li class="impulso-list-item">
                        <div class="impulso-stat-icon danger"><i data-feather="alert-circle"></i></div>
                        <div class="impulso-list-copy"><strong>Alta prioridade</strong><span>Conversas marcadas como urgentes</span></div>
                        <div class="impulso-list-side"><div class="impulso-mini-value"><?php echo (int) ($summary['high_priority'] ?? 0); ?></div><span>conversas</span></div>
                    </li>
                    <li class="impulso-list-item">
                        <div class="impulso-stat-icon warning"><i data-feather="user-x"></i></div>
                        <div class="impulso-list-copy"><strong>Sem responsável</strong><span>Aguardando distribuição automática ou manual</span></div>
                        <div class="impulso-list-side"><div class="impulso-mini-value"><?php echo (int) ($summary['unassigned'] ?? 0); ?></div><span>conversas</span></div>
                    </li>
                    <li class="impulso-list-item">
                        <div class="impulso-stat-icon"><i data-feather="pause-circle"></i></div>
                        <div class="impulso-list-copy"><strong>Pendentes</strong><span>Aguardando retorno do contato</span></div>
                        <div class="impulso-list-side"><div class="impulso-mini-value"><?php echo (int) $summary['pending']; ?></div><span>conversas</span></div>
                    </li>
                    <li class="impulso-list-item">
                        <div class="impulso-stat-icon success"><i data-feather="zap"></i></div>
                        <div class="impulso-list-copy"><strong>Primeira resposta</strong><span>Tempo médio consolidado</span></div>
                        <div class="impulso-list-side"><div class="impulso-mini-value"><?php echo esc($summary['avg_first_response']); ?></div><span>tempo médio</span></div>
                    </li>
                </ul>
            </div>
        </div>

        <div class="impulso-card">
            <div class="impulso-card-header">
                <div><h3>Saúde dos canais</h3><p>Conectividade por provedor WhatsApp</p></div>
                <?php if (!empty($can_manage_instances)) { ?><a href="<?php echo get_uri('chatwoot_plugin?chatwoot_tab=instances'); ?>" class="btn btn-default btn-sm">Gerenciar</a><?php } ?>
            </div>
            <div class="impulso-card-body">
                <ul class="impulso-list">
                    <?php foreach ($instances as $instance) {
                        $status_class = $instance['status'] === 'connected' ? 'success' : ($instance['status'] === 'attention' ? 'warning' : 'danger');
                        $status_label = $instance['status'] === 'connected' ? 'Conectada' : ($instance['status'] === 'attention' ? 'Atenção' : 'Desconectada');
                    ?>
                    <li class="impulso-list-item">
                        <div class="impulso-avatar sm"><?php echo esc(substr($instance['name'], 0, 2)); ?></div>
                        <div class="impulso-list-copy"><strong><?php echo esc($instance['name']); ?></strong><span><?php echo esc(($instance['provider_type'] ?? 'evolution') === 'meta_cloud' ? 'WhatsApp Cloud API' : 'Evolution API'); ?><?php echo !empty($instance['phone']) ? ' · ' . esc($instance['phone']) : ''; ?></span></div>
                        <div class="impulso-list-side"><span class="impulso-badge <?php echo esc($status_class); ?>"><span class="impulso-dot"></span><?php echo esc($status_label); ?></span></div>
                    </li>
                    <?php } ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="impulso-grid impulso-grid-3">
        <div class="impulso-card">
            <div class="impulso-card-header"><div><h3>Conversas recentes</h3><p>Últimas movimentações</p></div><a href="<?php echo get_uri('chatwoot_plugin?chatwoot_tab=conversations'); ?>" class="btn btn-default btn-sm">Ver todas</a></div>
            <div class="impulso-card-body">
                <ul class="impulso-list">
                    <?php foreach (array_slice($conversations, 0, 4) as $conversation) { ?>
                    <li class="impulso-list-item">
                        <div class="impulso-avatar sm"><?php echo esc($conversation['avatar']); ?></div>
                        <div class="impulso-list-copy"><strong><?php echo esc($conversation['name']); ?></strong><span><?php echo esc($conversation['last_message']); ?></span></div>
                        <div class="impulso-list-side"><?php echo esc($conversation['time']); ?></div>
                    </li>
                    <?php } ?>
                </ul>
            </div>
        </div>

        <?php if (!empty($can_manage_campaigns)) { ?><div class="impulso-card">
            <div class="impulso-card-header"><div><h3>Campanhas ativas</h3><p>Fila interna, templates oficiais e disparos Evolution</p></div><a class="btn btn-default btn-sm" href="<?php echo get_uri('chatwoot_plugin?chatwoot_tab=campaigns'); ?>">Abrir campanhas</a></div>
            <div class="impulso-card-body">
                <?php foreach (array_slice($campaigns, 0, 3) as $campaign) {
                    $percent = $campaign['audience'] > 0 ? round(($campaign['sent'] / $campaign['audience']) * 100) : 0;
                ?>
                <div class="impulso-mb-14">
                    <div class="impulso-inline" style="justify-content:space-between;gap:10px;margin-bottom:7px;">
                        <div class="impulso-list-copy"><strong><?php echo esc($campaign['name']); ?></strong><span><?php echo esc($campaign['instance']); ?></span></div>
                        <div class="impulso-mini-value"><?php echo (int) $percent; ?>%</div>
                    </div>
                    <div class="impulso-progress <?php echo $campaign['status'] === 'paused' ? 'warning' : ''; ?>"><span style="width:<?php echo (int) $percent; ?>%"></span></div>
                </div>
                <?php } ?>
                <?php if (!$campaigns) { ?><div class="impulso-empty compact"><p>Nenhuma campanha encontrada.</p></div><?php } ?>
            </div>
        </div><?php } ?>

        <?php if (!empty($can_manage_settings)) { ?><div class="impulso-card">
            <div class="impulso-card-header"><div><h3>Bots determinísticos</h3><p>Fluxos publicados com respostas previamente definidas</p></div><a class="btn btn-default btn-sm" href="<?php echo get_uri('chatwoot_plugin?chatwoot_tab=bots'); ?>">Gerenciar bots</a></div>
            <div class="impulso-card-body">
                <ul class="impulso-list">
                    <?php foreach (array_slice(($bots ?? []), 0, 4) as $bot) { ?>
                    <li class="impulso-list-item">
                        <div class="impulso-stat-icon <?php echo !empty($bot['active']) ? 'success' : ''; ?>"><i data-feather="git-branch"></i></div>
                        <div class="impulso-list-copy"><strong><?php echo esc($bot['name'] ?? 'Bot'); ?></strong><span>Versão <?php echo (int) ($bot['version'] ?? 1); ?> · <?php echo !empty($bot['published_at']) ? 'publicado' : 'rascunho'; ?></span></div>
                        <div class="impulso-list-side"><span class="impulso-badge <?php echo !empty($bot['active']) ? 'success' : 'neutral'; ?>"><?php echo !empty($bot['active']) ? 'Ativo' : 'Inativo'; ?></span></div>
                    </li>
                    <?php } ?>
                    <?php if (empty($bots)) { ?><li class="impulso-list-item"><div class="impulso-list-copy"><span>Nenhum bot configurado.</span></div></li><?php } ?>
                </ul>
            </div>
        </div><?php } ?>
    </div>
</div>
