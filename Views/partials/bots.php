<?php
$bots = is_array($bots ?? null) ? $bots : [];
$published = count(array_filter($bots, static fn(array $bot): bool => ($bot['status'] ?? '') === 'published'));
$active = count(array_filter($bots, static fn(array $bot): bool => !empty($bot['active'])));
?>
<div class="impulso-page">
    <div class="impulso-section-heading">
        <div><h2>Bots determinísticos</h2><p>Fluxos com respostas definidas, fallback obrigatório e encaminhamento humano. Nenhuma IA é executada.</p></div>
        <div class="impulso-section-actions"><button class="btn btn-primary" type="button" data-impulso-action="new-bot"><i data-feather="plus"></i> Novo bot</button></div>
    </div>
    <div class="impulso-grid impulso-grid-4 impulso-mb-14">
        <div class="impulso-card impulso-stat-card"><div class="impulso-card-body"><div class="impulso-stat-label">Fluxos</div><div class="impulso-stat-value"><?php echo count($bots); ?></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-card-body"><div class="impulso-stat-label">Publicados</div><div class="impulso-stat-value"><?php echo $published; ?></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-card-body"><div class="impulso-stat-label">Ativos</div><div class="impulso-stat-value"><?php echo $active; ?></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-card-body"><div class="impulso-stat-label">Proteção</div><div class="impulso-stat-value" style="font-size:20px">Sem IA</div></div></div>
    </div>
    <div class="impulso-grid impulso-grid-2" id="impulso-bot-list">
        <?php foreach ($bots as $bot) {
            $status = (string) ($bot['status'] ?? 'draft');
            $instanceName = 'Todos os canais';
            foreach (($instances ?? []) as $instance) if ((int)($instance['id'] ?? 0) === (int)($bot['instance_id'] ?? 0)) $instanceName = (string)($instance['name'] ?? $instanceName);
        ?>
        <article class="impulso-card impulso-card-body" data-bot-id="<?php echo (int)$bot['id']; ?>">
            <div class="impulso-instance-heading"><div class="impulso-instance-icon"><i data-feather="git-branch"></i></div><div class="impulso-instance-copy"><h3><?php echo esc($bot['name']); ?></h3><p><?php echo esc($instanceName); ?> · versão <?php echo (int)$bot['version']; ?></p></div><span class="impulso-badge <?php echo !empty($bot['active']) ? 'success' : ($status === 'published' ? 'warning' : 'default'); ?>"><?php echo !empty($bot['active']) ? 'Ativo' : ($status === 'published' ? 'Pausado' : 'Rascunho'); ?></span></div>
            <p><?php echo esc(($bot['description'] ?? '') ?: 'Fluxo de atendimento sem descrição.'); ?></p>
            <div class="impulso-instance-meta"><div class="impulso-meta-box"><span>Gatilho</span><strong><?php echo esc($bot['trigger_type'] ?? 'first_message'); ?></strong></div><div class="impulso-meta-box"><span>Falhas antes do humano</span><strong><?php echo (int)($bot['max_fallbacks'] ?? 2); ?></strong></div></div>
            <div class="impulso-card-actions">
                <button class="btn btn-default btn-sm" type="button" data-impulso-action="edit-bot" data-bot-id="<?php echo (int)$bot['id']; ?>"><i data-feather="edit-2"></i> Editar</button>
                <?php if ($status !== 'published') { ?><button class="btn btn-primary btn-sm" type="button" data-impulso-action="publish-bot" data-bot-id="<?php echo (int)$bot['id']; ?>"><i data-feather="upload"></i> Publicar</button><?php } else { ?><button class="btn btn-default btn-sm" type="button" data-impulso-action="toggle-bot" data-bot-id="<?php echo (int)$bot['id']; ?>"><i data-feather="power"></i> <?php echo !empty($bot['active']) ? 'Pausar' : 'Ativar'; ?></button><?php } ?>
            </div>
        </article>
        <?php } ?>
        <?php if (!$bots) { ?><div class="impulso-card impulso-card-body" style="grid-column:1/-1"><div class="impulso-empty"><div class="impulso-empty-icon"><i data-feather="git-branch"></i></div><h4>Nenhum bot criado</h4><p>Crie um fluxo, teste com mensagens simuladas e publique somente depois da validação.</p></div></div><?php } ?>
    </div>
</div>
