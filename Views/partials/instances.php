<?php
$instances = is_array($instances ?? null) ? $instances : [];
$webhook_logs = is_array($webhook_logs ?? null) ? $webhook_logs : [];
$connected_count = 0;
$messages_today = 0;
$recent_failures = 0;
foreach ($instances as $instance) {
    if (($instance['status'] ?? '') === 'connected') {
        $connected_count++;
    }
    $messages_today += (int) ($instance['messages_today'] ?? 0);
}
foreach ($webhook_logs as $log) {
    if (($log['status'] ?? '') === 'error') {
        $recent_failures++;
    }
}
?>
<div class="impulso-page">
    <div class="impulso-section-heading">
        <div>
            <h2>Canais WhatsApp</h2>
            <p>Gerencie Evolution API e WhatsApp Cloud API oficial no mesmo atendimento.</p>
        </div>
        <div class="impulso-section-actions">
            <?php if (!empty($can_manage_instances)) { ?>
                <button class="btn btn-default" type="button" data-impulso-action="sync-evolution"><i data-feather="download-cloud"></i> Sincronizar Evolution</button>
                <button class="btn btn-default" type="button" data-impulso-action="refresh-instances"><i data-feather="refresh-cw"></i> Verificar todas</button>
                <button class="btn btn-primary" type="button" data-impulso-action="new-instance"><i data-feather="plus"></i> Nova instância</button>
            <?php } ?>
        </div>
    </div>

    <div class="impulso-grid impulso-grid-4 impulso-mb-14">
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Instâncias</div><div class="impulso-stat-value"><?php echo count($instances); ?></div><div class="impulso-stat-trend"><strong><?php echo count(array_filter($instances, static fn($item) => !empty($item['active']))); ?></strong> ativas</div></div><div class="impulso-stat-icon"><i data-feather="smartphone"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Conectadas</div><div class="impulso-stat-value"><?php echo $connected_count; ?></div><div class="impulso-stat-trend"><strong><?php echo count($instances) ? (int) round(($connected_count / count($instances)) * 100) : 0; ?>%</strong> das cadastradas</div></div><div class="impulso-stat-icon success"><i data-feather="wifi"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Mensagens hoje</div><div class="impulso-stat-value"><?php echo $messages_today; ?></div><div class="impulso-stat-trend">Dados persistidos no plugin</div></div><div class="impulso-stat-icon info"><i data-feather="send"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Falhas recentes</div><div class="impulso-stat-value"><?php echo $recent_failures; ?></div><div class="impulso-stat-trend">Últimos eventos registrados</div></div><div class="impulso-stat-icon warning"><i data-feather="alert-triangle"></i></div></div></div>
    </div>

    <div class="impulso-grid impulso-grid-2 impulso-mb-14">
        <?php foreach ($instances as $instance) {
            $status = (string) ($instance['status'] ?? 'disconnected');
            $label = $status === 'connected' ? 'Conectada' : ($status === 'attention' ? 'Atenção' : ($status === 'error' ? 'Erro' : 'Desconectada'));
            $badge = $status === 'connected' ? 'success' : ($status === 'attention' ? 'warning' : 'danger');
            $health = $status === 'connected' ? 100 : ($status === 'attention' ? 50 : 0);
        ?>
        <div class="impulso-card impulso-instance-card <?php echo esc($status); ?>">
            <div class="impulso-card-body">
                <div class="impulso-instance-heading">
                    <div class="impulso-instance-icon"><i data-feather="smartphone"></i></div>
                    <div class="impulso-instance-copy"><h3><?php echo esc($instance['name'] ?? ''); ?></h3><p><?php echo esc(($instance['phone'] ?? '') ?: 'Número não informado'); ?></p></div>
                    <span class="impulso-badge <?php echo esc($badge); ?>"><span class="impulso-dot"></span><?php echo esc($label); ?></span>
                </div>
                <div class="impulso-instance-meta">
                    <?php $providerType = (string) ($instance['provider_type'] ?? 'evolution'); ?>
                    <div class="impulso-meta-box"><span>Provedor</span><strong><?php echo $providerType === 'meta_cloud' ? 'Meta Cloud API' : 'Evolution API'; ?></strong></div>
                    <div class="impulso-meta-box"><span>Identificador</span><strong><?php echo esc($providerType === 'meta_cloud' ? ($instance['meta_phone_number_id'] ?? '') : ($instance['evolution_instance_name'] ?? '')); ?></strong></div>
                    <div class="impulso-meta-box"><span>Conversas</span><strong><?php echo (int) ($instance['conversation_count'] ?? 0); ?></strong></div>
                    <div class="impulso-meta-box"><span>Não lidas</span><strong><?php echo (int) ($instance['unread_count'] ?? 0); ?></strong></div>
                    <?php if ($providerType === 'evolution') { ?><div class="impulso-meta-box"><span>Estado Evolution</span><strong><?php echo esc($instance['provider_status'] ?? $status); ?></strong></div><?php } ?>
                    <div class="impulso-meta-box"><span>Status operacional</span><strong><?php echo !empty($instance['active']) ? 'Ativa' : 'Inativa'; ?></strong></div>
                </div>
                <div class="impulso-health-row">
                    <span class="impulso-text-muted" style="font-size:9px;">Conexão</span>
                    <div class="impulso-progress <?php echo $health === 100 ? 'success' : ($health ? 'warning' : 'danger'); ?>"><span style="width:<?php echo $health; ?>%"></span></div>
                    <strong><?php echo esc($label); ?></strong>
                </div>
                <div class="impulso-card-actions">
                    <?php if (!empty($can_manage_instances)) { ?>
                        <button class="btn btn-default btn-sm" type="button" data-impulso-action="test-instance" data-instance-id="<?php echo (int) ($instance['id'] ?? 0); ?>"><i data-feather="activity"></i> Atualizar status</button>
                        <?php if ($providerType === 'evolution') { ?>
                            <button class="btn btn-primary btn-sm" type="button" data-impulso-action="connect-evolution" data-instance-id="<?php echo (int) ($instance['id'] ?? 0); ?>"><i data-feather="maximize"></i> QR / parear</button>
                            <button class="btn btn-default btn-sm" type="button" data-impulso-action="restart-evolution" data-instance-id="<?php echo (int) ($instance['id'] ?? 0); ?>"><i data-feather="rotate-cw"></i> Reiniciar</button>
                            <button class="btn btn-default btn-sm" type="button" data-impulso-action="logout-evolution" data-instance-id="<?php echo (int) ($instance['id'] ?? 0); ?>"><i data-feather="log-out"></i> Desconectar</button>
                            <button class="btn btn-danger btn-sm" type="button" data-impulso-action="delete-evolution" data-instance-id="<?php echo (int) ($instance['id'] ?? 0); ?>"><i data-feather="trash-2"></i> Remover da Evolution</button>
                        <?php } ?>
                        <button class="btn btn-default btn-sm" type="button" data-impulso-action="edit-instance" data-instance-id="<?php echo (int) ($instance['id'] ?? 0); ?>"><i data-feather="settings"></i> Editar</button>
                    <?php } ?>
                </div>
            </div>
        </div>
        <?php } ?>
        <?php if (!$instances) { ?>
            <div class="impulso-card impulso-card-body" style="grid-column:1/-1;">
                <div class="impulso-empty"><div class="impulso-empty-icon"><i data-feather="smartphone"></i></div><h4>Nenhuma instância cadastrada</h4><p>Cadastre o primeiro canal WhatsApp para iniciar o atendimento.</p></div>
            </div>
        <?php } ?>
    </div>

    <div class="impulso-card">
        <div class="impulso-card-header"><div><h3>Eventos recentes</h3><p>Últimos webhooks recebidos e processados pelo plugin</p></div></div>
        <div class="impulso-table-wrap">
            <table class="impulso-table">
                <thead><tr><th>Horário</th><th>Instância</th><th>Evento</th><th>Detalhe</th><th>Resultado</th></tr></thead>
                <tbody>
                    <?php foreach ($webhook_logs as $log) {
                        $log_status = ($log['status'] ?? '') === 'processed' || ($log['status'] ?? '') === 'duplicate' ? 'success' : (($log['status'] ?? '') === 'error' ? 'danger' : 'warning');
                    ?>
                        <tr><td><?php echo esc($log['created_at_display'] ?? ($log['created_at'] ?? '')); ?></td><td><?php echo esc($log['instance_name'] ?? '—'); ?></td><td><?php echo esc($log['event_name'] ?? 'evento'); ?></td><td><?php echo esc($log['detail'] ?? ($log['error_message'] ?? 'Evento recebido')); ?></td><td><span class="impulso-badge <?php echo esc($log_status); ?>"><?php echo esc(ucfirst($log['status'] ?? 'recebido')); ?></span></td></tr>
                    <?php } ?>
                    <?php if (!$webhook_logs) { ?><tr><td colspan="5">Nenhum webhook recebido até o momento.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
