<?php
$contacts = is_array($contacts ?? null) ? $contacts : [];
$instances = is_array($instances ?? null) ? $instances : [];
$contactSummary = is_array($contact_summary ?? null) ? $contact_summary : [];
$visibleContacts = count($contacts);
?>
<div class="impulso-page" id="impulso-contacts-page">
    <div class="impulso-section-heading">
        <div>
            <h2>Contatos</h2>
            <p>Pesquise, organize e atualize os contatos vinculados às conversas da Evolution.</p>
        </div>
        <div class="impulso-section-actions">
            <button class="btn btn-default" type="button" data-impulso-action="import-contacts"><i data-feather="upload"></i> Importar</button>
            <button class="btn btn-primary" type="button" data-impulso-action="new-contact"><i data-feather="user-plus"></i> Novo contato</button>
        </div>
    </div>

    <div class="impulso-grid impulso-grid-4 impulso-mb-14" id="impulso-contact-summary">
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Contatos</div><div class="impulso-stat-value" data-contact-stat="total"><?php echo (int) ($contactSummary['total'] ?? 0); ?></div><div class="impulso-stat-trend">Base consolidada</div></div><div class="impulso-stat-icon"><i data-feather="users"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Com conversa</div><div class="impulso-stat-value" data-contact-stat="with_conversation"><?php echo (int) ($contactSummary['with_conversation'] ?? 0); ?></div><div class="impulso-stat-trend">Vinculados ao WhatsApp</div></div><div class="impulso-stat-icon success"><i data-feather="message-circle"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Sem identificação</div><div class="impulso-stat-value" data-contact-stat="unidentified"><?php echo (int) ($contactSummary['unidentified'] ?? 0); ?></div><div class="impulso-stat-trend">Usam telefone como referência</div></div><div class="impulso-stat-icon warning"><i data-feather="user-x"></i></div></div></div>
        <div class="impulso-card impulso-stat-card"><div class="impulso-stat-top"><div><div class="impulso-stat-label">Opt-out</div><div class="impulso-stat-value" data-contact-stat="opt_out"><?php echo (int) ($contactSummary['opt_out'] ?? 0); ?></div><div class="impulso-stat-trend">Bloqueados para campanhas</div></div><div class="impulso-stat-icon danger"><i data-feather="shield-off"></i></div></div></div>
    </div>

    <div class="impulso-card">
        <div class="impulso-card-header impulso-card-header-wrap">
            <div><h3>Todos os contatos</h3><p>Dados sincronizados e enriquecidos pelo Rise/n8n</p></div>
            <div class="impulso-filter-row impulso-gap-8">
                <div class="impulso-search" style="width:280px;max-width:45vw;"><i data-feather="search"></i><input id="impulso-contact-search" type="search" placeholder="Nome, telefone, e-mail ou empresa"></div>
                <select class="form-control" id="impulso-contact-instance-filter" style="width:180px;">
                    <option value="all">Todas as instâncias</option>
                    <?php foreach ($instances as $instance) { ?><option value="<?php echo (int) ($instance['id'] ?? 0); ?>"><?php echo esc($instance['name'] ?? ''); ?></option><?php } ?>
                </select>
                <select class="form-control" id="impulso-contact-status-filter" style="width:150px;"><option value="all">Todos</option><option value="identified">Identificados</option><option value="unidentified">Sem nome</option><option value="opt_out">Opt-out</option></select>
                <button class="btn btn-default" type="button" data-impulso-action="refresh-contacts"><i data-feather="refresh-cw"></i></button>
            </div>
        </div>
        <div class="impulso-bulk-bar impulso-hidden" id="impulso-contact-bulk-bar"><strong><span id="impulso-contact-selected-count">0</span> selecionados</strong><button class="btn btn-default btn-sm" type="button" data-impulso-action="bulk-tag-contacts"><i data-feather="tag"></i> Etiquetar</button><button class="btn btn-default btn-sm" type="button" data-impulso-action="bulk-export-contacts"><i data-feather="download"></i> Exportar</button><button class="btn btn-default btn-sm" type="button" data-impulso-action="clear-contact-selection">Limpar</button></div>
        <div class="impulso-table-wrap">
            <table class="impulso-table" id="impulso-contacts-table">
                <thead><tr><th style="width:36px;"><input type="checkbox" id="impulso-contact-select-all" aria-label="Selecionar todos"></th><th>Contato</th><th>Telefone</th><th>Instância</th><th>Etiquetas</th><th>Conversas</th><th>Última atividade</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($contacts as $index => $contact) {
                    $name = trim((string) ($contact['name'] ?? '')) ?: 'Contato';
                    $initials = '';
                    foreach (array_slice(explode(' ', $name), 0, 2) as $part) { $initials .= mb_substr($part, 0, 1); }
                    $contactId = (int) ($contact['id'] ?? ($index + 1));
                    $instanceId = (int) ($contact['instance_id'] ?? 0);
                    $unidentified = $name === 'Contato' || $name === (string) ($contact['phone'] ?? '');
                    $contactStatus = !empty($contact['opt_out']) ? 'opt_out' : ($unidentified ? 'unidentified' : 'identified');
                ?>
                    <tr data-contact-id="<?php echo $contactId; ?>" data-contact-instance="<?php echo $instanceId; ?>" data-contact-status="<?php echo $contactStatus; ?>" data-contact-search="<?php echo esc(mb_strtolower($name . ' ' . ($contact['phone'] ?? '') . ' ' . ($contact['email'] ?? '') . ' ' . ($contact['company'] ?? ''))); ?>">
                        <td><input type="checkbox" class="impulso-contact-select" value="<?php echo $contactId; ?>" aria-label="Selecionar <?php echo esc($name); ?>"></td>
                        <td><button class="impulso-person-button" type="button" data-impulso-action="view-contact" data-contact-id="<?php echo $contactId; ?>"><span class="impulso-avatar sm"><?php echo esc(mb_strtoupper($initials ?: 'C')); ?></span><span class="impulso-person-copy"><strong><?php echo esc($name); ?></strong><span><?php echo esc($contact['email'] ?? 'Sem e-mail'); ?></span></span></button></td>
                        <td><?php echo esc($contact['phone'] ?? ''); ?></td>
                        <td><?php echo esc($contact['instance'] ?? '—'); ?></td>
                        <td><div class="impulso-tag-list"><?php foreach (($contact['tags'] ?? []) as $tag) { ?><span class="impulso-badge primary"><?php echo esc($tag); ?></span><?php } ?><?php if (empty($contact['tags'])) { ?><span class="impulso-text-muted">Sem etiquetas</span><?php } ?></div></td>
                        <td><span class="impulso-count-badge"><?php echo (int) ($contact['conversations'] ?? 1); ?></span></td>
                        <td><?php echo esc($contact['last_seen'] ?? '—'); ?></td>
                        <td><button class="impulso-icon-button btn btn-default" type="button" data-impulso-action="contact-row-menu" data-contact-id="<?php echo $contactId; ?>"><i data-feather="more-horizontal"></i></button></td>
                    </tr>
                <?php } ?>
                <?php if (!$contacts) { ?><tr class="impulso-empty-row"><td colspan="8">Nenhum contato sincronizado até o momento.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
        <div class="impulso-pagination-bar"><span id="impulso-contact-result-count"><?php echo $visibleContacts; ?> contatos</span><button class="btn btn-default btn-sm" type="button" data-impulso-action="load-more-contacts">Carregar mais</button></div>
    </div>
</div>
