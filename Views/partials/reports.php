<?php
$reports = is_array($reports ?? null) ? $reports : [];
$volume = is_array($reports['volume'] ?? null) ? $reports['volume'] : [0,0,0,0,0,0,0];
$labels = is_array($reports['labels'] ?? null) ? $reports['labels'] : ['Qui','Sex','Sáb','Dom','Seg','Ter','Qua'];
$channels = is_array($reports['channels'] ?? null) ? $reports['channels'] : [];
$reportAgents = is_array($reports['agents'] ?? null) ? $reports['agents'] : [];
$maxVolume = max(array_merge([0], $volume));
?>
<div class="impulso-page" id="impulso-reports-page">
    <div class="impulso-section-heading">
        <div>
            <h2>Relatórios</h2>
            <p>Acompanhe volume, tempo de resposta, canais, campanhas e automações.</p>
        </div>
        <div class="impulso-section-actions">
            <select class="form-control" id="impulso-report-period" style="width:170px;"><option value="7d">Últimos 7 dias</option><option value="30d">Últimos 30 dias</option><option value="month">Este mês</option><option value="custom">Período personalizado</option></select>
            <select class="form-control" id="impulso-report-instance" style="width:180px;"><option value="all">Todas as instâncias</option><?php foreach (($instances ?? []) as $instance) { ?><option value="<?php echo (int) ($instance['id'] ?? 0); ?>"><?php echo esc($instance['name'] ?? ''); ?></option><?php } ?></select>
            <button class="btn btn-default" type="button" data-impulso-action="refresh-reports"><i data-feather="refresh-cw"></i></button>
            <?php if (!empty($can_export_reports)) { ?><button class="btn btn-primary" type="button" data-impulso-action="export-reports"><i data-feather="download"></i> Exportar</button><?php } ?>
        </div>
    </div>

    <div class="impulso-grid impulso-grid-4 impulso-mb-14" id="impulso-report-summary">
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Conversas recebidas</div><div class="impulso-stat-value" data-report-stat="received"><?php echo (int) ($reports['received'] ?? 0); ?></div><div class="impulso-stat-trend" data-report-trend="received">No período selecionado</div></div><div class="impulso-stat-icon"><i data-feather="inbox"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Tempo de 1ª resposta</div><div class="impulso-stat-value" data-report-stat="first_response"><?php echo esc($reports['first_response'] ?? '—'); ?></div><div class="impulso-stat-trend">Média do período</div></div><div class="impulso-stat-icon success"><i data-feather="zap"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Tempo de resolução</div><div class="impulso-stat-value" data-report-stat="resolution_time"><?php echo esc($reports['resolution_time'] ?? '—'); ?></div><div class="impulso-stat-trend">Média das resolvidas</div></div><div class="impulso-stat-icon info"><i data-feather="check-circle"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Taxa de resposta</div><div class="impulso-stat-value" data-report-stat="reply_rate"><?php echo esc($reports['reply_rate'] ?? '0%'); ?></div><div class="impulso-stat-trend">Conversas respondidas</div></div><div class="impulso-stat-icon warning"><i data-feather="message-square"></i></div></div></div>
    </div>

    <div class="impulso-grid impulso-grid-2 impulso-mb-14">
        <div class="impulso-card">
            <div class="impulso-card-header"><div><h3>Volume de conversas</h3><p>Entradas e saídas por dia</p></div><div class="impulso-segmented"><button class="active" type="button" data-report-series="conversations">Conversas</button><button type="button" data-report-series="messages">Mensagens</button></div></div>
            <div class="impulso-card-body"><div class="impulso-chart" id="impulso-volume-chart">
                <?php foreach ($volume as $index => $value) { $height = $maxVolume > 0 ? round(($value / $maxVolume) * 100) : 0; ?>
                    <div class="impulso-chart-column"><div class="impulso-chart-bar" data-value="<?php echo (int) $value; ?>" style="height:<?php echo (int) $height; ?>%"><span><?php echo (int) $value; ?></span></div><span class="impulso-chart-label"><?php echo esc($labels[$index] ?? ''); ?></span></div>
                <?php } ?>
            </div></div>
        </div>
        <div class="impulso-card">
            <div class="impulso-card-header"><div><h3>Distribuição por instância</h3><p>Participação no volume total</p></div><button class="btn btn-default btn-sm" type="button" data-impulso-action="report-channel-detail">Detalhar</button></div>
            <div class="impulso-card-body"><div class="impulso-donut-wrap"><div class="impulso-donut" id="impulso-channel-donut"><div class="impulso-donut-center"><strong data-report-stat="channel_total"><?php echo array_sum(array_map(static fn($channel) => (int) ($channel['count'] ?? 0), $channels)); ?></strong><span>conversas</span></div></div><div class="impulso-legend" id="impulso-channel-legend">
                <?php $colors = ['var(--ih-primary)', 'var(--ih-success)', 'var(--ih-warning)', 'var(--ih-info)', 'var(--ih-danger)']; foreach ($channels as $index => $channel) { ?>
                    <div class="impulso-legend-item"><span class="impulso-legend-label"><span class="impulso-legend-color" style="background:<?php echo $colors[$index] ?? 'var(--ih-muted)'; ?>"></span><?php echo esc($channel['name'] ?? 'Canal'); ?></span><strong><?php echo esc($channel['value'] ?? '0%'); ?></strong></div>
                <?php } ?>
                <?php if (!$channels) { ?><div class="impulso-empty compact"><p>Sem dados por instância.</p></div><?php } ?>
            </div></div></div>
        </div>
    </div>

    <div class="impulso-grid impulso-grid-2 impulso-mb-14">
        <div class="impulso-card">
            <div class="impulso-card-header"><div><h3>Desempenho por atendente</h3><p>Volume, primeira resposta e resolução</p></div><select class="form-control" id="impulso-report-agent-filter" style="width:150px;"><option value="all">Toda a equipe</option></select></div>
            <div class="impulso-table-wrap"><table class="impulso-table" id="impulso-agent-report-table"><thead><tr><th>Atendente</th><th>Conversas</th><th>Resolvidas</th><th>1ª resposta</th><th>Taxa de resposta</th></tr></thead><tbody>
                <?php foreach ($reportAgents as $agent) { ?><tr><td><div class="impulso-person-line"><div class="impulso-avatar sm"><?php echo esc(mb_substr($agent['name'] ?? 'A', 0, 2)); ?></div><div class="impulso-person-copy"><strong><?php echo esc($agent['name'] ?? 'Atendente'); ?></strong><span><?php echo esc($agent['team'] ?? 'Atendimento'); ?></span></div></div></td><td><?php echo (int) ($agent['conversations'] ?? 0); ?></td><td><?php echo (int) ($agent['resolved'] ?? 0); ?></td><td><?php echo esc($agent['first_response'] ?? '—'); ?></td><td><span class="impulso-badge success"><?php echo esc($agent['reply_rate'] ?? '0%'); ?></span></td></tr><?php } ?>
                <?php if (!$reportAgents) { ?><tr class="impulso-empty-row"><td colspan="5">Nenhum indicador de atendente disponível.</td></tr><?php } ?>
            </tbody></table></div>
        </div>
        <div class="impulso-card">
            <div class="impulso-card-header"><div><h3>IA e automações</h3><p>Execuções, handoffs e falhas</p></div><?php if (!empty($can_manage_ai)) { ?><a class="btn btn-default btn-sm" href="<?php echo get_uri('chatwoot_plugin?chatwoot_tab=ai'); ?>">Gerenciar</a><?php } ?></div>
            <div class="impulso-card-body" id="impulso-ai-report-metrics">
                <div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Respostas automáticas</strong><span>No período selecionado</span></div><span class="impulso-badge neutral" data-report-ai="responses">0</span></div>
                <div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Transferências para humano</strong><span>Handoffs realizados</span></div><span class="impulso-badge neutral" data-report-ai="handoffs">0</span></div>
                <div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Falhas de execução</strong><span>Fluxos com erro</span></div><span class="impulso-badge neutral" data-report-ai="errors">0</span></div>
                <div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Tempo médio automático</strong><span>Da entrada à resposta</span></div><span class="impulso-badge neutral" data-report-ai="response_time">—</span></div>
            </div>
        </div>
    </div>

    <div class="impulso-card">
        <div class="impulso-card-header"><div><h3>Funil operacional</h3><p>Da primeira mensagem à conclusão do atendimento</p></div><button class="btn btn-default btn-sm" type="button" data-impulso-action="configure-report-funnel">Detalhar etapas</button></div>
        <div class="impulso-card-body"><div class="impulso-grid impulso-grid-4" id="impulso-report-funnel">
            <div class="impulso-meta-box impulso-funnel-step"><span>Recebidas</span><strong data-funnel="received">0</strong><div class="impulso-progress"><span style="width:100%"></span></div></div>
            <div class="impulso-meta-box impulso-funnel-step"><span>Respondidas</span><strong data-funnel="replied">0</strong><div class="impulso-progress success"><span style="width:0"></span></div></div>
            <div class="impulso-meta-box impulso-funnel-step"><span>Qualificadas</span><strong data-funnel="qualified">0</strong><div class="impulso-progress warning"><span style="width:0"></span></div></div>
            <div class="impulso-meta-box impulso-funnel-step"><span>Resolvidas</span><strong data-funnel="resolved">0</strong><div class="impulso-progress success"><span style="width:0"></span></div></div>
        </div></div>
    </div>
</div>
