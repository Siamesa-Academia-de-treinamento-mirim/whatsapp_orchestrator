<?php
$agents = is_array($agents ?? null) ? $agents : [];
$automations = is_array($automations ?? null) ? $automations : [];
?>
<div class="impulso-page" id="impulso-ai-page">
    <div class="impulso-section-heading">
        <div>
            <h2>IA e Automações</h2>
            <p>Controle os fluxos do n8n, o estado da IA e as regras de passagem para atendimento humano.</p>
        </div>
        <div class="impulso-section-actions">
            <button class="btn btn-default" type="button" data-impulso-action="new-automation"><i data-feather="git-branch"></i> Nova automação</button>
            <button class="btn btn-primary" type="button" data-impulso-action="new-agent"><i data-feather="plus"></i> Novo agente</button>
        </div>
    </div>

    <div class="impulso-grid impulso-grid-4 impulso-mb-14" id="impulso-ai-summary">
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Conversas com IA ativa</div><div class="impulso-stat-value" data-ai-stat="running">0</div><div class="impulso-stat-trend">Estado operacional atual</div></div><div class="impulso-stat-icon"><i data-feather="cpu"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Em atendimento humano</div><div class="impulso-stat-value" data-ai-stat="human">0</div><div class="impulso-stat-trend">IA pausada por handoff</div></div><div class="impulso-stat-icon warning"><i data-feather="user-check"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Automações ativas</div><div class="impulso-stat-value" data-ai-stat="automations"><?php echo count(array_filter($automations, static fn($item) => !empty($item['active']))); ?></div><div class="impulso-stat-trend">Fluxos habilitados</div></div><div class="impulso-stat-icon success"><i data-feather="zap"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Falhas nas últimas 24h</div><div class="impulso-stat-value" data-ai-stat="errors">0</div><div class="impulso-stat-trend">Execuções com erro</div></div><div class="impulso-stat-icon danger"><i data-feather="alert-triangle"></i></div></div></div>
    </div>

    <div class="impulso-grid impulso-grid-2 impulso-mb-14">
        <div class="impulso-card">
            <div class="impulso-card-header impulso-card-header-wrap">
                <div><h3>Agentes</h3><p>Identidade, instâncias e fluxo responsável</p></div>
                <div class="impulso-filter-row">
                    <div class="impulso-search"><i data-feather="search"></i><input id="impulso-agent-search" type="search" placeholder="Buscar agente"></div>
                    <button class="btn btn-default btn-sm" type="button" data-impulso-action="refresh-ai"><i data-feather="refresh-cw"></i></button>
                </div>
            </div>
            <div class="impulso-card-body" id="impulso-agent-list">
                <?php foreach ($agents as $agent) { ?>
                    <article class="impulso-agent-card" data-agent-id="<?php echo (int) ($agent['id'] ?? 0); ?>" data-agent-search="<?php echo esc(mb_strtolower(($agent['name'] ?? '') . ' ' . ($agent['instance'] ?? '') . ' ' . ($agent['workflow'] ?? ''))); ?>">
                        <div class="impulso-agent-icon <?php echo !empty($agent['active']) ? 'active' : ''; ?>"><i data-feather="cpu"></i></div>
                        <div class="impulso-agent-copy"><strong><?php echo esc($agent['name'] ?? 'Agente'); ?></strong><span><?php echo esc($agent['instance'] ?? 'Todas as instâncias'); ?> · <?php echo esc($agent['workflow'] ?? 'Fluxo não informado'); ?></span></div>
                        <label class="impulso-switch"><input type="checkbox" data-impulso-action="toggle-agent" data-agent-id="<?php echo (int) ($agent['id'] ?? 0); ?>" <?php echo !empty($agent['active']) ? 'checked' : ''; ?>><span></span></label>
                        <button class="impulso-icon-button btn btn-default" type="button" data-impulso-action="edit-agent" data-agent-id="<?php echo (int) ($agent['id'] ?? 0); ?>"><i data-feather="settings"></i></button>
                    </article>
                <?php } ?>
                <div class="impulso-empty <?php echo $agents ? 'impulso-hidden' : ''; ?>" id="impulso-agent-empty"><div class="impulso-empty-icon"><i data-feather="cpu"></i></div><h4>Nenhum agente configurado</h4><p>Cadastre a referência do fluxo n8n que atende cada instância.</p><button class="btn btn-primary" type="button" data-impulso-action="new-agent">Criar agente</button></div>
            </div>
        </div>

        <div class="impulso-card">
            <div class="impulso-card-header"><div><h3>Estado operacional</h3><p>Controle global por instância</p></div><button class="btn btn-default btn-sm" type="button" data-impulso-action="refresh-ai-state"><i data-feather="refresh-cw"></i> Atualizar</button></div>
            <div class="impulso-card-body" id="impulso-ai-instance-state">
                <?php foreach (($instances ?? []) as $instance) { ?>
                    <div class="impulso-setting-row" data-ai-instance-id="<?php echo (int) ($instance['id'] ?? 0); ?>">
                        <div class="impulso-setting-copy"><strong><?php echo esc($instance['name'] ?? 'Instância'); ?></strong><span><?php echo esc($instance['phone'] ?? ''); ?></span></div>
                        <div class="impulso-inline impulso-gap-8">
                            <span class="impulso-badge neutral" data-ai-instance-status>Não consultado</span>
                            <button class="btn btn-default btn-sm" type="button" data-impulso-action="toggle-ai-instance" data-instance-id="<?php echo (int) ($instance['id'] ?? 0); ?>">Gerenciar</button>
                        </div>
                    </div>
                <?php } ?>
                <?php if (empty($instances)) { ?><div class="impulso-empty compact"><p>Nenhuma instância cadastrada.</p></div><?php } ?>
            </div>
        </div>
    </div>

    <div class="impulso-card impulso-mb-14">
        <div class="impulso-card-header impulso-card-header-wrap">
            <div><h3>Automações</h3><p>Gatilhos e webhooks executados pelo n8n</p></div>
            <div class="impulso-filter-row"><select class="form-control" id="impulso-automation-status-filter" style="width:150px;"><option value="all">Todos os status</option><option value="active">Ativas</option><option value="inactive">Inativas</option><option value="error">Com erro</option></select><button class="btn btn-default" type="button" data-impulso-action="refresh-automations"><i data-feather="refresh-cw"></i></button></div>
        </div>
        <div class="impulso-table-wrap">
            <table class="impulso-table" id="impulso-automations-table">
                <thead><tr><th>Automação</th><th>Gatilho</th><th>Destino</th><th>Última execução</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($automations as $automation) { ?>
                    <tr data-automation-id="<?php echo (int) ($automation['id'] ?? 0); ?>" data-automation-status="<?php echo !empty($automation['active']) ? 'active' : 'inactive'; ?>">
                        <td><strong><?php echo esc($automation['name'] ?? 'Automação'); ?></strong></td>
                        <td><?php echo esc($automation['trigger'] ?? 'Webhook'); ?></td>
                        <td><?php echo esc($automation['workflow'] ?? 'n8n'); ?></td>
                        <td><?php echo esc($automation['last_run'] ?? 'Nunca'); ?></td>
                        <td><label class="impulso-switch"><input type="checkbox" data-impulso-action="toggle-automation" data-automation-id="<?php echo (int) ($automation['id'] ?? 0); ?>" <?php echo !empty($automation['active']) ? 'checked' : ''; ?>><span></span></label></td>
                        <td><button class="impulso-icon-button btn btn-default" type="button" data-impulso-action="automation-menu" data-automation-id="<?php echo (int) ($automation['id'] ?? 0); ?>"><i data-feather="more-horizontal"></i></button></td>
                    </tr>
                <?php } ?>
                <?php if (!$automations) { ?><tr class="impulso-empty-row"><td colspan="6">Nenhuma automação cadastrada.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="impulso-grid impulso-grid-2">
        <div class="impulso-card">
            <div class="impulso-card-header"><div><h3>Execuções recentes</h3><p>Últimos eventos recebidos do n8n</p></div><button class="btn btn-default btn-sm" type="button" data-impulso-action="open-ai-logs">Ver logs</button></div>
            <div class="impulso-card-body" id="impulso-ai-execution-list"><div class="impulso-empty compact"><p>Nenhuma execução carregada.</p></div></div>
        </div>
        <div class="impulso-card">
            <div class="impulso-card-header"><div><h3>Conectividade n8n</h3><p>Endpoints usados pelo plugin</p></div><button class="btn btn-default btn-sm" type="button" data-impulso-action="test-ai-backend"><i data-feather="activity"></i> Testar</button></div>
            <div class="impulso-card-body" id="impulso-ai-health">
                <div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>API de controle</strong><span>Estado ainda não verificado</span></div><span class="impulso-badge neutral">Não testada</span></div>
                <div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Webhook de eventos</strong><span>Estado ainda não verificado</span></div><span class="impulso-badge neutral">Não testado</span></div>
            </div>
        </div>
    </div>
</div>
