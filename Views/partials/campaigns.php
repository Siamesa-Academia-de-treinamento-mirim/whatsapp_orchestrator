<?php
$campaigns = is_array($campaigns ?? null) ? $campaigns : [];
$campaignSummary = is_array($campaign_summary ?? null) ? $campaign_summary : [];
?>
<div class="impulso-page" id="impulso-campaigns-page">
    <div class="impulso-section-heading">
        <div>
            <h2>Campanhas</h2>
            <p>Crie, agende e acompanhe disparos executados pelos fluxos do n8n.</p>
        </div>
        <div class="impulso-section-actions">
            <button class="btn btn-default" type="button" data-impulso-action="campaign-templates"><i data-feather="file-text"></i> Templates</button>
            <button class="btn btn-primary" type="button" data-impulso-action="new-campaign"><i data-feather="plus"></i> Nova campanha</button>
        </div>
    </div>

    <div class="impulso-grid impulso-grid-4 impulso-mb-14" id="impulso-campaign-summary">
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Campanhas no mês</div><div class="impulso-stat-value" data-campaign-stat="month"><?php echo (int) ($campaignSummary['month'] ?? 0); ?></div><div class="impulso-stat-trend">Criadas no período atual</div></div><div class="impulso-stat-icon"><i data-feather="send"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Mensagens enviadas</div><div class="impulso-stat-value" data-campaign-stat="sent"><?php echo (int) ($campaignSummary['sent'] ?? 0); ?></div><div class="impulso-stat-trend">Total consolidado</div></div><div class="impulso-stat-icon success"><i data-feather="check"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Taxa de entrega</div><div class="impulso-stat-value" data-campaign-stat="delivery_rate"><?php echo esc($campaignSummary['delivery_rate'] ?? '0%'); ?></div><div class="impulso-stat-trend">Entregues sobre enviadas</div></div><div class="impulso-stat-icon info"><i data-feather="check-circle"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Taxa de resposta</div><div class="impulso-stat-value" data-campaign-stat="reply_rate"><?php echo esc($campaignSummary['reply_rate'] ?? '0%'); ?></div><div class="impulso-stat-trend">Respostas identificadas</div></div><div class="impulso-stat-icon warning"><i data-feather="message-square"></i></div></div></div>
    </div>

    <div class="impulso-card impulso-mb-14">
        <div class="impulso-card-header impulso-card-header-wrap">
            <div><h3>Campanhas</h3><p>Operação, progresso e resultados por disparo</p></div>
            <div class="impulso-filter-row impulso-gap-8">
                <div class="impulso-search"><i data-feather="search"></i><input id="impulso-campaign-search" type="search" placeholder="Buscar campanha"></div>
                <select class="form-control" id="impulso-campaign-status-filter" style="width:160px;">
                    <option value="all">Todos os status</option>
                    <option value="draft">Rascunho</option>
                    <option value="scheduled">Agendada</option>
                    <option value="running">Enviando</option>
                    <option value="paused">Pausada</option>
                    <option value="completed">Concluída</option>
                    <option value="failed">Com falhas</option>
                </select>
                <select class="form-control" id="impulso-campaign-instance-filter" style="width:180px;">
                    <option value="all">Todas as instâncias</option>
                    <?php foreach (($instances ?? []) as $instance) { ?><option value="<?php echo (int) ($instance['id'] ?? 0); ?>"><?php echo esc($instance['name'] ?? ''); ?></option><?php } ?>
                </select>
                <button class="btn btn-default" type="button" data-impulso-action="refresh-campaigns"><i data-feather="refresh-cw"></i></button>
            </div>
        </div>
        <div id="impulso-campaign-list" class="impulso-campaign-list">
            <?php foreach ($campaigns as $campaign) {
                $statusKey = (string) ($campaign['status'] ?? 'draft');
                $statusMap = [
                    'running' => ['label' => 'Enviando', 'class' => 'success'],
                    'scheduled' => ['label' => 'Agendada', 'class' => 'info'],
                    'draft' => ['label' => 'Rascunho', 'class' => 'neutral'],
                    'paused' => ['label' => 'Pausada', 'class' => 'warning'],
                    'completed' => ['label' => 'Concluída', 'class' => 'success'],
                    'failed' => ['label' => 'Com falhas', 'class' => 'danger'],
                ];
                $status = $statusMap[$statusKey] ?? ['label' => ucfirst($statusKey), 'class' => 'neutral'];
                $audience = max(0, (int) ($campaign['audience_count'] ?? $campaign['audience'] ?? 0));
                $sent = max(0, (int) ($campaign['sent'] ?? 0));
                $percent = $audience > 0 ? min(100, (int) round(($sent / $audience) * 100)) : 0;
            ?>
                <article class="impulso-campaign-row" data-campaign-id="<?php echo (int) ($campaign['id'] ?? 0); ?>" data-campaign-status="<?php echo esc($statusKey); ?>" data-campaign-instance="<?php echo (int) ($campaign['instance_id'] ?? 0); ?>" data-campaign-search="<?php echo esc(mb_strtolower(($campaign['name'] ?? '') . ' ' . ($campaign['instance'] ?? ''))); ?>">
                    <div class="impulso-campaign-overview">
                        <div class="impulso-campaign-name">
                            <strong><?php echo esc($campaign['name'] ?? 'Campanha'); ?></strong>
                            <span><?php echo esc($campaign['instance'] ?? ''); ?> · <?php echo esc($campaign['scheduled'] ?? 'Sem agendamento'); ?></span>
                        </div>
                        <div class="impulso-campaign-progress-block"><div class="impulso-progress"><span style="width:<?php echo $percent; ?>%"></span></div><small><?php echo $sent; ?> de <?php echo $audience; ?> processados</small></div>
                        <div class="impulso-campaign-metric"><span>Entregues</span><strong><?php echo (int) ($campaign['delivered'] ?? 0); ?></strong></div>
                        <div class="impulso-campaign-metric"><span>Lidas</span><strong><?php echo (int) ($campaign['read'] ?? 0); ?></strong></div>
                        <div class="impulso-campaign-metric"><span>Respostas</span><strong><?php echo (int) ($campaign['replied'] ?? 0); ?></strong></div>
                        <div><span class="impulso-badge <?php echo esc($status['class']); ?>"><span class="impulso-dot"></span><?php echo esc($status['label']); ?></span></div>
                        <div class="impulso-row-menu">
                            <button class="impulso-icon-button btn btn-default" type="button" data-impulso-action="view-campaign" data-campaign-id="<?php echo (int) ($campaign['id'] ?? 0); ?>" title="Visualizar"><i data-feather="eye"></i></button>
                            <button class="impulso-icon-button btn btn-default" type="button" data-impulso-action="campaign-menu" data-campaign-id="<?php echo (int) ($campaign['id'] ?? 0); ?>" title="Mais ações"><i data-feather="more-horizontal"></i></button>
                        </div>
                    </div>
                </article>
            <?php } ?>
            <div class="impulso-empty <?php echo $campaigns ? 'impulso-hidden' : ''; ?>" id="impulso-campaign-empty">
                <div class="impulso-empty-icon"><i data-feather="send"></i></div>
                <h4>Nenhuma campanha encontrada</h4>
                <p>Crie uma campanha para disparar mensagens por uma instância da Evolution.</p>
                <button class="btn btn-primary" type="button" data-impulso-action="new-campaign"><i data-feather="plus"></i> Criar campanha</button>
            </div>
        </div>
    </div>

    <div class="impulso-grid impulso-grid-2">
        <div class="impulso-card">
            <div class="impulso-card-header"><div><h3>Próximas execuções</h3><p>Agendamentos devolvidos pelo n8n</p></div><button class="btn btn-default btn-sm" type="button" data-impulso-action="campaign-calendar"><i data-feather="calendar"></i> Calendário</button></div>
            <div class="impulso-card-body" id="impulso-campaign-schedule-list"><div class="impulso-empty compact"><p>Nenhuma execução próxima.</p></div></div>
        </div>
        <div class="impulso-card">
            <div class="impulso-card-header"><div><h3>Saúde do disparador</h3><p>Estado dos webhooks e da fila do n8n</p></div><button class="btn btn-default btn-sm" type="button" data-impulso-action="test-campaign-backend"><i data-feather="activity"></i> Testar</button></div>
            <div class="impulso-card-body" id="impulso-campaign-health">
                <div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>API do n8n</strong><span>Verificação ainda não executada</span></div><span class="impulso-badge neutral">Não testada</span></div>
                <div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Fila de disparo</strong><span>Monitoramento fornecido pelo backend</span></div><span class="impulso-badge neutral">—</span></div>
                <div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Última execução</strong><span>Sem dados carregados</span></div><span class="impulso-badge neutral">—</span></div>
            </div>
        </div>
    </div>
</div>
