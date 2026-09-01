<div class="modal fade" id="impulso-new-conversation-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header"><div class="impulso-modal-title"><div class="impulso-modal-title-icon"><i data-feather="message-circle"></i></div><div><h4>Nova conversa</h4><p>Inicie um atendimento por uma instância conectada</p></div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
                <div class="impulso-field-grid">
                    <div class="impulso-field full"><label>Contato ou telefone</label><div class="impulso-search-field"><i data-feather="search"></i><input class="form-control" id="impulso-new-conversation-contact" placeholder="Nome ou 5511999999999" autocomplete="off"></div><div class="impulso-suggestion-list impulso-hidden" id="impulso-new-conversation-suggestions"></div></div>
                    <div class="impulso-field"><label>Instância</label><select class="form-control" id="impulso-new-conversation-instance"><option value="">Selecione</option><?php foreach (($instances ?? []) as $instance) { ?><option value="<?php echo (int) ($instance['id'] ?? 0); ?>" <?php echo ($instance['status'] ?? '') !== 'connected' ? 'disabled' : ''; ?>><?php echo esc($instance['name'] ?? ''); ?><?php echo ($instance['status'] ?? '') !== 'connected' ? ' — desconectada' : ''; ?></option><?php } ?></select></div>
                    <div class="impulso-field"><label>Nome do contato</label><input class="form-control" id="impulso-new-conversation-name" placeholder="Opcional para novos números"></div>
                    <div class="impulso-field full"><label>Mensagem inicial</label><textarea class="form-control" id="impulso-new-conversation-message" rows="5" placeholder="Escreva a primeira mensagem"></textarea><small><span id="impulso-new-conversation-char-count">0</span> caracteres</small></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" data-impulso-modal-submit="conversation"><i data-feather="send"></i> Iniciar conversa</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="impulso-new-contact-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header"><div class="impulso-modal-title"><div class="impulso-modal-title-icon"><i data-feather="user-plus"></i></div><div><h4 id="impulso-contact-modal-title">Novo contato</h4><p>Cadastre ou atualize os dados do cliente</p></div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><input type="hidden" id="impulso-contact-id"><div class="impulso-field-grid">
                <div class="impulso-field full"><label>Nome</label><input class="form-control" id="impulso-contact-form-name" maxlength="150" placeholder="Nome completo"></div>
                <div class="impulso-field"><label>Telefone</label><input class="form-control" id="impulso-contact-form-phone" maxlength="32" placeholder="5511999999999"></div>
                <div class="impulso-field"><label>E-mail</label><input class="form-control" id="impulso-contact-form-email" type="email" placeholder="email@empresa.com"></div>
                <div class="impulso-field"><label>Empresa</label><input class="form-control" id="impulso-contact-form-company" placeholder="Empresa ou projeto"></div>
                <div class="impulso-field"><label>Cidade</label><input class="form-control" id="impulso-contact-form-city" placeholder="Cidade"></div>
                <div class="impulso-field"><label>Origem</label><select class="form-control" id="impulso-contact-form-source"><option value="whatsapp">WhatsApp</option><option value="campaign">Campanha</option><option value="manual">Cadastro manual</option><option value="meta">Meta Ads</option><option value="other">Outra</option></select></div>
                <div class="impulso-field"><label>Instância preferencial</label><select class="form-control" id="impulso-contact-form-instance"><option value="">Nenhuma</option><?php foreach (($instances ?? []) as $instance) { ?><option value="<?php echo (int) ($instance['id'] ?? 0); ?>"><?php echo esc($instance['name'] ?? ''); ?></option><?php } ?></select></div>
                <div class="impulso-field full"><label>Etiquetas</label><input class="form-control" id="impulso-contact-form-tags" placeholder="lead, matrícula, urgente"><small>Separe as etiquetas por vírgula.</small></div>
                <div class="impulso-field full"><label>Observações</label><textarea class="form-control" id="impulso-contact-form-notes" rows="4" placeholder="Informações úteis para o atendimento"></textarea></div>
                <div class="impulso-field full"><label class="form-check"><input class="form-check-input" id="impulso-contact-form-opt-out" type="checkbox"> <span class="form-check-label">Bloquear este contato para campanhas</span></label></div>
            </div></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" data-impulso-modal-submit="contact"><i data-feather="save"></i> Salvar contato</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="impulso-instance-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header"><div class="impulso-modal-title"><div class="impulso-modal-title-icon"><i data-feather="smartphone"></i></div><div><h4 id="impulso-instance-modal-title">Novo canal WhatsApp</h4><p>Conecte Evolution API ou a API oficial da Meta</p></div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><input id="impulso-instance-id" type="hidden" value=""><div class="impulso-field-grid">
                <div class="impulso-field"><label>Provedor</label><select class="form-control" id="impulso-instance-provider"><option value="evolution">Evolution API</option><option value="meta_cloud">WhatsApp Cloud API oficial</option></select><small>Grupos exigem Evolution. Templates oficiais exigem Meta.</small></div>
                <div class="impulso-field"><label>Nome de exibição</label><input class="form-control" id="impulso-instance-name" maxlength="150" required placeholder="Ex.: SIAMESA SBC"></div>
                <div class="impulso-field"><label>Identificação interna</label><input class="form-control" id="impulso-instance-identifier" maxlength="191" required placeholder="comercial_sbc"></div>
                <div class="impulso-field"><label>Número conectado</label><input class="form-control" id="impulso-instance-phone" maxlength="32" placeholder="5511999999999"></div>

                <div class="impulso-field full" data-instance-provider-section="evolution"><hr><strong>Evolution API</strong></div>
                <div class="impulso-field" data-instance-provider-section="evolution"><label>Nome da instância Evolution</label><input class="form-control" id="impulso-instance-technical-name" maxlength="191" placeholder="siamesa_sbc"></div>
                <div class="impulso-field" data-instance-provider-section="evolution"><label>URL base específica</label><input class="form-control" id="impulso-instance-base-url" type="url" placeholder="Vazio para usar a URL global" <?php echo empty($can_manage_settings) ? 'disabled' : ''; ?>></div>
                <div class="impulso-field" data-instance-provider-section="evolution"><label>API key específica</label><div class="input-group"><input class="form-control" id="impulso-instance-api-key" type="password" autocomplete="new-password" placeholder="Vazio para manter/usar a chave global"><button class="btn btn-default" type="button" data-impulso-action="toggle-password"><i data-feather="eye"></i></button></div></div>
                <div class="impulso-field" data-instance-provider-section="evolution"><label class="form-check"><input class="form-check-input" id="impulso-instance-clear-api-key" type="checkbox"> <span class="form-check-label">Remover chave específica</span></label></div>

                <div class="impulso-field full" data-instance-provider-section="meta_cloud"><hr><strong>WhatsApp Cloud API</strong><small>As credenciais são criptografadas no banco. O App Secret valida a assinatura dos webhooks.</small></div>
                <div class="impulso-field" data-instance-provider-section="meta_cloud"><label>Phone Number ID</label><input class="form-control" id="impulso-instance-meta-phone-id" inputmode="numeric" maxlength="64" placeholder="123456789012345"></div>
                <div class="impulso-field" data-instance-provider-section="meta_cloud"><label>WABA ID</label><input class="form-control" id="impulso-instance-meta-waba-id" inputmode="numeric" maxlength="64" placeholder="123456789012345"></div>
                <div class="impulso-field" data-instance-provider-section="meta_cloud"><label>Versão Graph API</label><input class="form-control" id="impulso-instance-meta-version" maxlength="8" value="v25.0" placeholder="v25.0"></div>
                <div class="impulso-field" data-instance-provider-section="meta_cloud"><label>Access Token</label><input class="form-control" id="impulso-instance-meta-access-token" type="password" autocomplete="new-password" placeholder="Vazio mantém o token atual"></div>
                <div class="impulso-field" data-instance-provider-section="meta_cloud"><label>Verify Token</label><input class="form-control" id="impulso-instance-meta-verify-token" type="password" autocomplete="new-password" placeholder="Definido por você no painel Meta"></div>
                <div class="impulso-field" data-instance-provider-section="meta_cloud"><label>App Secret</label><input class="form-control" id="impulso-instance-meta-app-secret" type="password" autocomplete="new-password" placeholder="Segredo do aplicativo Meta"></div>
                <div class="impulso-field full" data-instance-provider-section="meta_cloud"><small>Webhook: <code><?php echo esc(get_uri('chatwoot_plugin/webhooks/meta/{identificador-interno}')); ?></code></small></div>
                <div class="impulso-field full"><label class="form-check"><input class="form-check-input" id="impulso-instance-active" type="checkbox" checked> <span class="form-check-label">Canal ativo</span></label></div>
            </div></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" data-impulso-modal-submit="instance"><i data-feather="save"></i> Salvar canal</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="impulso-evolution-connect-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="impulso-modal-title"><div class="impulso-modal-title-icon"><i data-feather="maximize"></i></div><div><h4 id="impulso-evolution-connect-title">Conectar Evolution</h4><p>Leia o QR Code no WhatsApp para parear este canal.</p></div></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body text-center">
                <div id="impulso-evolution-qr-empty" class="impulso-empty compact"><div class="impulso-empty-icon"><i data-feather="loader"></i></div><p>Gerando dados de conexão...</p></div>
                <img id="impulso-evolution-qr" class="impulso-evolution-qr impulso-hidden" alt="QR Code para conectar o WhatsApp">
                <div id="impulso-evolution-pairing-wrap" class="impulso-evolution-pairing impulso-hidden"><span>Código de pareamento</span><strong id="impulso-evolution-pairing-code"></strong></div>
                <p id="impulso-evolution-connect-message" class="impulso-text-muted impulso-mt-12">Mantenha esta janela aberta até a conexão ser concluída.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Fechar</button></div>
        </div>
    </div>
</div>


<div class="modal fade" id="impulso-bot-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl"><div class="modal-content">
        <div class="modal-header"><div class="impulso-modal-title"><div class="impulso-modal-title-icon"><i data-feather="git-branch"></i></div><div><h4 id="impulso-bot-modal-title">Novo bot determinístico</h4><p>Defina exatamente o que o bot pode reconhecer e responder</p></div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><input type="hidden" id="impulso-bot-id"><div class="impulso-field-grid">
            <div class="impulso-field"><label>Nome</label><input class="form-control" id="impulso-bot-name" maxlength="191" placeholder="Atendimento inicial"></div>
            <div class="impulso-field"><label>Canal</label><select class="form-control" id="impulso-bot-instance"><option value="">Todos os canais</option><?php foreach (($instances ?? []) as $instance) { ?><option value="<?php echo (int)($instance['id'] ?? 0); ?>"><?php echo esc($instance['name'] ?? ''); ?></option><?php } ?></select></div>
            <div class="impulso-field full"><label>Descrição</label><input class="form-control" id="impulso-bot-description" maxlength="5000" placeholder="Objetivo deste fluxo"></div>
            <div class="impulso-field"><label>Gatilho</label><select class="form-control" id="impulso-bot-trigger"><option value="first_message">Primeira mensagem</option><option value="keyword">Palavra-chave</option><option value="always">Sempre que não houver sessão</option></select></div>
            <div class="impulso-field"><label>Palavras do gatilho</label><input class="form-control" id="impulso-bot-trigger-values" placeholder="oi, olá, informações"></div>
            <div class="impulso-field"><label>Máximo de respostas não reconhecidas</label><input class="form-control" id="impulso-bot-max-fallbacks" type="number" min="1" max="10" value="2"></div>
            <div class="impulso-field"><label class="form-check"><input class="form-check-input" id="impulso-bot-ignore-groups" type="checkbox" checked> <span class="form-check-label">Não responder em grupos</span></label></div>
            <div class="impulso-field full"><label>Mensagem quando não entender</label><textarea class="form-control" id="impulso-bot-fallback" rows="2">Não consegui identificar sua dúvida com segurança.</textarea></div>
            <div class="impulso-field full"><label>Mensagem de encaminhamento</label><textarea class="form-control" id="impulso-bot-handoff" rows="2">Vou encaminhar sua mensagem para um responsável continuar o atendimento.</textarea></div>
            <div class="impulso-field full"><label>Fluxo JSON</label><textarea class="form-control" id="impulso-bot-definition" rows="18" spellcheck="false"></textarea><small>Correspondências permitidas: exact, contains, starts_with e any_word. Use <code>__handoff__</code> para transferir ao humano.</small></div>
            <div class="impulso-field full"><label>Mensagens para simular</label><textarea class="form-control" id="impulso-bot-simulation-inputs" rows="3" placeholder="Uma mensagem por linha"></textarea><pre id="impulso-bot-simulation-result" class="impulso-hidden" style="white-space:pre-wrap;max-height:240px;overflow:auto"></pre></div>
        </div></div>
        <div class="modal-footer"><button class="btn btn-default" type="button" data-impulso-action="simulate-bot"><i data-feather="play"></i> Simular</button><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" data-impulso-modal-submit="bot"><i data-feather="save"></i> Salvar rascunho</button></div>
    </div></div>
</div>

<div class="modal fade" id="impulso-campaign-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <div class="impulso-modal-title">
                    <div class="impulso-modal-title-icon"><i data-feather="send"></i></div>
                    <div><h4 id="impulso-campaign-modal-title">Nova campanha</h4><p>Defina o canal, público, conteúdo e os limites da fila interna</p></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="impulso-campaign-id">
                <input type="hidden" id="impulso-campaign-dispatch-mode" value="internal_queue">
                <div class="impulso-builder-steps" id="impulso-campaign-steps">
                    <button class="impulso-builder-step active" type="button" data-campaign-step="1"><span>1</span> Campanha</button>
                    <button class="impulso-builder-step" type="button" data-campaign-step="2"><span>2</span> Público</button>
                    <button class="impulso-builder-step" type="button" data-campaign-step="3"><span>3</span> Mensagem</button>
                    <button class="impulso-builder-step" type="button" data-campaign-step="4"><span>4</span> Agendamento</button>
                </div>

                <div class="impulso-campaign-form-step" data-campaign-panel="1">
                    <div class="impulso-field-grid">
                        <div class="impulso-field full"><label>Nome da campanha</label><input class="form-control" id="impulso-campaign-name" maxlength="150" placeholder="Ex.: Reativação de leads de julho"></div>
                        <div class="impulso-field"><label>Canal</label><select class="form-control" id="impulso-campaign-instance"><option value="">Selecione</option><?php foreach (($instances ?? []) as $instance) { ?><option value="<?php echo (int) ($instance['id'] ?? 0); ?>" data-provider="<?php echo esc($instance['provider_type'] ?? 'evolution'); ?>"><?php echo esc($instance['name'] ?? ''); ?> — <?php echo esc(($instance['provider_type'] ?? 'evolution') === 'meta_cloud' ? 'Oficial' : 'Evolution'); ?></option><?php } ?></select></div>
                        <div class="impulso-field"><label>Origem do envio</label><select class="form-control" id="impulso-campaign-channel-type" disabled><option value="unofficial">Não oficial · Evolution</option><option value="official">Oficial · Meta Cloud</option></select><small>Definido automaticamente pelo canal selecionado.</small></div>
                        <div class="impulso-field"><label>Agendamento</label><select class="form-control" id="impulso-campaign-type"><option value="one_time">Disparo único</option><option value="recurring">Recorrente</option></select></div>
                        <div class="impulso-field"><label>Limite por minuto</label><input class="form-control" id="impulso-campaign-rate-limit" type="number" min="1" max="1000" value="20"><small>Aplicado por campanha antes de cada envio.</small></div>
                        <div class="impulso-field full"><label>Descrição interna</label><textarea class="form-control" id="impulso-campaign-description" rows="3" placeholder="Objetivo e observações"></textarea></div>
                    </div>
                </div>

                <div class="impulso-campaign-form-step impulso-hidden" data-campaign-panel="2">
                    <div class="impulso-grid impulso-grid-2">
                        <div class="impulso-field-grid">
                            <div class="impulso-field full"><label>Fonte do público</label><select class="form-control" id="impulso-campaign-audience-source"><option value="contacts">Contatos do Impulso Hub</option><option value="manual">Lista manual</option><option value="csv">Arquivo CSV</option></select></div>
                            <div class="impulso-field full"><label>Etiquetas incluídas</label><input class="form-control" id="impulso-campaign-include-tags" placeholder="lead, interessado"></div>
                            <div class="impulso-field full"><label>Etiquetas excluídas</label><input class="form-control" id="impulso-campaign-exclude-tags" placeholder="matriculado, opt-out"></div>
                            <div class="impulso-field full"><label>Números manuais</label><textarea class="form-control" id="impulso-campaign-manual-numbers" rows="7" placeholder="Um número por linha"></textarea></div>
                        </div>
                        <div class="impulso-audience-preview">
                            <div class="impulso-audience-count"><span>Público estimado</span><strong id="impulso-campaign-audience-count">0</strong></div>
                            <div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Opt-outs removidos</strong><span>Verificado novamente no momento do envio</span></div><span class="impulso-badge success"><i data-feather="shield"></i> Ativo</span></div>
                            <div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Idempotência</strong><span>Cada destinatário entra uma única vez na fila</span></div><span class="impulso-badge success">Ativo</span></div>
                            <button class="btn btn-default btn-block" type="button" data-impulso-action="preview-campaign-audience"><i data-feather="users"></i> Calcular público</button>
                        </div>
                    </div>
                </div>

                <div class="impulso-campaign-form-step impulso-hidden" data-campaign-panel="3">
                    <div class="impulso-field-grid impulso-mb-14 impulso-hidden" id="impulso-campaign-official-fields">
                        <div class="impulso-field full">
                            <label>Template oficial aprovado</label>
                            <div class="input-group"><select class="form-control" id="impulso-campaign-template"><option value="">Selecione um canal oficial primeiro</option></select><button class="btn btn-default" type="button" data-impulso-action="sync-official-templates"><i data-feather="refresh-cw"></i> Sincronizar</button></div>
                            <small>Fora da janela de atendimento, a Meta exige um template aprovado.</small>
                        </div>
                        <div class="impulso-field full"><label>Componentes do template (JSON)</label><textarea class="form-control impulso-code-input" id="impulso-campaign-template-parameters" rows="6" placeholder='[{"type":"body","parameters":[{"type":"text","text":"{nome}"}]}]'>[]</textarea><small>O sistema gera a estrutura inicial a partir dos marcadores {{1}}, {{2}} etc. Use variáveis como {nome}, {telefone} ou {1}.</small></div>
                    </div>
                    <div class="impulso-grid impulso-grid-2">
                        <div>
                            <div class="impulso-field full"><label>Mensagem / prévia</label><textarea class="form-control" id="impulso-campaign-message" rows="10" placeholder="Olá, {nome}! Ainda tem interesse..."></textarea><div class="impulso-inline impulso-gap-8 impulso-mt-8" id="impulso-campaign-freeform-tools"><button class="btn btn-default btn-sm" type="button" data-impulso-action="campaign-emoji"><i data-feather="smile"></i> Emoji</button><button class="btn btn-default btn-sm" type="button" data-impulso-action="campaign-variable" data-variable="{nome}">{nome}</button><button class="btn btn-default btn-sm" type="button" data-impulso-action="campaign-variable" data-variable="{empresa}">{empresa}</button><button class="btn btn-default btn-sm" type="button" data-impulso-action="campaign-attachment"><i data-feather="paperclip"></i> Mídia</button></div><input type="file" id="impulso-campaign-file" hidden accept="image/*,video/*,audio/*,.pdf"></div>
                        </div>
                        <div><div class="impulso-phone-preview"><div class="impulso-phone-top"><strong id="impulso-campaign-preview-title">Campanha</strong><span>prévia</span></div><div class="impulso-phone-screen"><div class="impulso-wa-bubble" id="impulso-campaign-preview">Sua mensagem aparecerá aqui.<span class="impulso-wa-time">agora</span></div></div></div></div>
                    </div>
                </div>

                <div class="impulso-campaign-form-step impulso-hidden" data-campaign-panel="4">
                    <div class="impulso-field-grid">
                        <div class="impulso-field"><label>Início</label><input class="form-control" id="impulso-campaign-start-date" type="date"></div>
                        <div class="impulso-field"><label>Horário</label><input class="form-control" id="impulso-campaign-start-time" type="time"></div>
                        <div class="impulso-field"><label>Fuso horário</label><input class="form-control" id="impulso-campaign-timezone" value="America/Sao_Paulo" readonly><small>Usado nas recorrências e datas da fila.</small></div>
                        <div class="impulso-field full"><label>Dias da semana</label><div class="impulso-weekdays" id="impulso-campaign-weekdays"><?php foreach (['seg'=>'Seg','ter'=>'Ter','qua'=>'Qua','qui'=>'Qui','sex'=>'Sex','sab'=>'Sáb','dom'=>'Dom'] as $key=>$label) { ?><label><input type="checkbox" value="<?php echo $key; ?>" <?php echo in_array($key,['seg','ter','qua','qui','sex'],true) ? 'checked' : ''; ?>><span><?php echo $label; ?></span></label><?php } ?></div></div>
                        <div class="impulso-field full"><label class="form-check"><input class="form-check-input" id="impulso-campaign-start-immediately" type="checkbox"> <span class="form-check-label">Iniciar imediatamente após salvar</span></label></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-impulso-action="campaign-previous" id="impulso-campaign-previous" disabled>Voltar</button><div class="impulso-flex-spacer"></div><button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" data-impulso-action="campaign-next" id="impulso-campaign-next">Continuar</button><button type="button" class="btn btn-success impulso-hidden" data-impulso-modal-submit="campaign" id="impulso-campaign-save"><i data-feather="calendar"></i> Salvar campanha</button></div>
        </div>
    </div>
</div>


<div class="modal fade" id="impulso-campaign-history-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <div class="impulso-modal-title">
                    <div class="impulso-modal-title-icon"><i data-feather="activity"></i></div>
                    <div><h4 id="impulso-campaign-history-title">Histórico da campanha</h4><p>Ocorrências, destinatários, tentativas e recibos do provedor</p></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="impulso-grid impulso-grid-4 impulso-mb-14" id="impulso-campaign-history-summary">
                    <div class="impulso-card impulso-stat-card compact"><div class="impulso-stat-label">Público</div><div class="impulso-stat-value" data-history-stat="audience">0</div></div>
                    <div class="impulso-card impulso-stat-card compact"><div class="impulso-stat-label">Enviadas</div><div class="impulso-stat-value" data-history-stat="sent">0</div></div>
                    <div class="impulso-card impulso-stat-card compact"><div class="impulso-stat-label">Entregues</div><div class="impulso-stat-value" data-history-stat="delivered">0</div></div>
                    <div class="impulso-card impulso-stat-card compact"><div class="impulso-stat-label">Respostas</div><div class="impulso-stat-value" data-history-stat="replied">0</div></div>
                </div>
                <div class="impulso-grid" style="grid-template-columns:minmax(260px, 0.8fr) minmax(0, 2.2fr);gap:14px;">
                    <div class="impulso-card">
                        <div class="impulso-card-header"><div><h3>Ocorrências</h3><p>Uma linha por execução</p></div></div>
                        <div class="impulso-card-body" id="impulso-campaign-run-list"><div class="impulso-empty compact"><p>Carregando ocorrências...</p></div></div>
                    </div>
                    <div class="impulso-card">
                        <div class="impulso-card-header impulso-card-header-wrap">
                            <div><h3>Destinatários</h3><p id="impulso-campaign-run-caption">Selecione uma ocorrência</p></div>
                            <div class="impulso-filter-row impulso-gap-8">
                                <input class="form-control" id="impulso-campaign-recipient-search" placeholder="Buscar telefone" style="width:170px;">
                                <select class="form-control" id="impulso-campaign-recipient-status" style="width:150px;"><option value="all">Todos</option><option value="pending">Pendente</option><option value="retry">Repetição</option><option value="sent">Enviada</option><option value="delivered">Entregue</option><option value="read">Lida</option><option value="replied">Respondida</option><option value="failed">Falhou</option><option value="opt_out">Opt-out</option></select>
                            </div>
                        </div>
                        <div class="table-responsive"><table class="table table-hover impulso-table"><thead><tr><th>Contato</th><th>Status</th><th>Tentativas</th><th>Último evento</th><th>Erro</th></tr></thead><tbody id="impulso-campaign-recipient-list"><tr><td colspan="5" class="text-center">Selecione uma ocorrência.</td></tr></tbody></table></div>
                        <div class="impulso-card-footer impulso-hidden" id="impulso-campaign-recipient-more"><button class="btn btn-default btn-sm" type="button" data-impulso-action="load-more-campaign-recipients">Carregar mais</button></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Fechar</button><button type="button" class="btn btn-primary" data-impulso-action="edit-viewed-campaign"><i data-feather="edit-3"></i> Editar campanha</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="impulso-media-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-xl"><div class="modal-content impulso-media-modal-content"><div class="modal-header"><div><h4 id="impulso-media-title">Mídia</h4><p id="impulso-media-description"></p></div><div class="impulso-inline impulso-gap-8"><a class="btn btn-default btn-sm" id="impulso-media-download" href="#" download target="_blank"><i data-feather="download"></i> Baixar</a><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div></div><div class="modal-body" id="impulso-media-stage"></div></div></div></div>

<div class="modal fade" id="impulso-global-search-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-body impulso-command-palette"><div class="impulso-command-search"><i data-feather="search"></i><input id="impulso-global-search-input" type="search" placeholder="Busque conversas, contatos, campanhas ou configurações" autocomplete="off"><kbd>ESC</kbd></div><div class="impulso-command-results" id="impulso-global-search-results"><div class="impulso-command-section"><span>Ações rápidas</span><button type="button" data-command-action="new-conversation"><i data-feather="message-circle"></i><div><strong>Nova conversa</strong><small>Enviar mensagem por uma instância conectada</small></div></button><button type="button" data-command-action="new-contact"><i data-feather="user-plus"></i><div><strong>Novo contato</strong><small>Cadastrar contato manualmente</small></div></button><button type="button" data-command-action="new-campaign"><i data-feather="send"></i><div><strong>Nova campanha</strong><small>Criar um disparo pela fila interna</small></div></button></div></div></div></div></div></div>

<div class="impulso-notification-drawer" id="impulso-notification-drawer" aria-hidden="true"><div class="impulso-drawer-header"><div><span class="impulso-eyebrow">Central</span><h3>Notificações</h3></div><button class="impulso-icon-button btn btn-default" type="button" data-impulso-action="close-notifications"><i data-feather="x"></i></button></div><div class="impulso-drawer-tabs"><button class="active" type="button" data-notification-filter="all">Todas</button><button type="button" data-notification-filter="unread">Não lidas</button><button type="button" data-notification-filter="system">Sistema</button></div><div class="impulso-drawer-body" id="impulso-notification-list"><div class="impulso-empty compact"><p>Nenhuma notificação carregada.</p></div></div><div class="impulso-drawer-footer"><button class="btn btn-default btn-block" type="button" data-impulso-action="mark-all-notifications-read">Marcar todas como lidas</button></div></div><div class="impulso-drawer-backdrop" id="impulso-drawer-backdrop"></div>

<div class="impulso-context-menu impulso-hidden" id="impulso-context-menu"></div>
<div class="impulso-toast-stack" id="impulso-toast-stack"></div>
