<div class="impulso-page" id="impulso-settings-page">
    <div class="impulso-section-heading">
        <div>
            <h2>Configurações</h2>
            <p>Defina o comportamento da caixa de entrada, canais, campanhas, bots, webhooks e segurança técnica.</p>
        </div>
        <div class="impulso-section-actions">
            <?php if (!empty($can_manage_instances)) { ?><button class="btn btn-default" type="button" data-impulso-action="test-all-connections"><i data-feather="activity"></i> Testar canais</button><?php } ?>
            <?php if (!empty($can_manage_settings)) { ?><button class="btn btn-primary" type="button" data-impulso-action="save-settings"><i data-feather="save"></i> Salvar alterações</button><?php } ?>
        </div>
    </div>

    <div class="impulso-settings-layout">
        <nav class="impulso-settings-nav">
            <button class="active" type="button" data-settings-tab="general"><i data-feather="sliders"></i> Geral</button>
            <button type="button" data-settings-tab="evolution"><i data-feather="smartphone"></i> Evolution API</button>
            <button type="button" data-settings-tab="campaigns"><i data-feather="send"></i> Campanhas</button>
            <button type="button" data-settings-tab="bots"><i data-feather="git-branch"></i> Bots</button>
            <button type="button" data-settings-tab="webhooks"><i data-feather="radio"></i> Webhooks</button>
            <button type="button" data-settings-tab="security"><i data-feather="shield"></i> Segurança técnica</button>
        </nav>

        <div>
            <section class="impulso-settings-panel active" data-settings-panel="general">
                <div class="impulso-card impulso-mb-14">
                    <div class="impulso-card-header"><div><h3>Experiência de atendimento</h3><p>Preferências da caixa de entrada</p></div></div>
                    <div class="impulso-card-body">
                        <div class="impulso-field-grid">
                            <div class="impulso-field"><label>Nome do módulo</label><input class="form-control" id="impulso-setting-module-name" value="<?php echo esc($settings_public['module_name'] ?? 'Impulso Hub WhatsApp'); ?>"></div>
                            <div class="impulso-field"><label>Fuso horário</label><select class="form-control" id="impulso-setting-timezone"><option value="America/Sao_Paulo">America/Sao_Paulo (UTC-03:00)</option></select></div>
                            <div class="impulso-field"><label>Atualização da caixa</label><input class="form-control" id="impulso-setting-polling" type="number" min="3000" max="60000" step="1000" value="<?php echo (int) ($settings_public['polling_interval_ms'] ?? 5000); ?>"><small>Milissegundos</small></div>
                            <div class="impulso-field"><label>Conversas por página</label><input class="form-control" id="impulso-setting-page-size" type="number" min="10" max="100" value="<?php echo (int) ($settings_public['conversation_page_size'] ?? 30); ?>"></div>
                            <div class="impulso-field"><label>Status inicial</label><select class="form-control" id="impulso-setting-default-status"><option value="open">Aberta</option><option value="pending">Pendente</option></select></div>
                            <div class="impulso-field"><label>Prioridade inicial</label><select class="form-control" id="impulso-setting-default-priority"><option value="none">Sem prioridade</option><option value="low">Baixa</option><option value="medium">Média</option><option value="high">Alta</option><option value="urgent">Urgente</option></select></div>
                            <div class="impulso-field"><label>Resolver inativas após</label><input class="form-control" id="impulso-setting-auto-resolve-hours" type="number" min="0" value="<?php echo (int) ($settings_public['auto_resolve_hours'] ?? 0); ?>"><small>Horas; zero desativa</small></div>
                        </div>
                        <div class="impulso-setting-row impulso-mt-14"><div class="impulso-setting-copy"><strong>Som para novas mensagens</strong><span>Aviso curto para nova mensagem recebida.</span></div><label class="impulso-switch"><input id="impulso-setting-sound" type="checkbox" <?php echo !empty($settings_public['sound_enabled']) ? 'checked' : ''; ?>><span></span></label></div>
                        <div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Notificações do navegador</strong><span>Exibir aviso quando a página estiver em segundo plano.</span></div><label class="impulso-switch"><input id="impulso-setting-browser-notifications" type="checkbox" <?php echo !empty($settings_public['browser_notifications_enabled']) ? 'checked' : ''; ?>><span></span></label></div>
                        <div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Marcar como lida ao abrir</strong><span>Zerar o contador ao selecionar a conversa.</span></div><label class="impulso-switch"><input id="impulso-setting-auto-read" type="checkbox" <?php echo array_key_exists('auto_mark_read', $settings_public) && empty($settings_public['auto_mark_read']) ? '' : 'checked'; ?>><span></span></label></div>
                    </div>
                </div>
                <div class="impulso-card"><div class="impulso-card-header"><div><h3>Arquitetura dos canais</h3><p>As credenciais oficiais são cadastradas individualmente em Instâncias</p></div></div><div class="impulso-card-body"><div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Evolution API</strong><span>Conversas individuais, grupos e campanhas não oficiais.</span></div><span class="impulso-badge primary">Grupos</span></div><div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>WhatsApp Cloud API</strong><span>Templates aprovados, webhooks assinados e janela oficial de 24 horas.</span></div><span class="impulso-badge success">Oficial</span></div></div></div>
            </section>

            <section class="impulso-settings-panel" data-settings-panel="evolution">
                <div class="impulso-card impulso-mb-14">
                    <div class="impulso-card-header"><div><h3>Conexão global Evolution</h3><p>Instâncias podem sobrescrever URL e chave individualmente</p></div></div>
                    <div class="impulso-card-body"><div class="impulso-field-grid">
                        <div class="impulso-field full"><label>URL base</label><input class="form-control" id="impulso-setting-base-url" type="url" value="<?php echo esc($settings_public['evolution_base_url'] ?? ''); ?>" placeholder="https://evolution.exemplo.com"></div>
                        <div class="impulso-field"><label>API key global</label><div class="input-group"><input class="form-control" id="impulso-setting-global-key" type="password" autocomplete="new-password" placeholder="Vazio mantém a chave atual"><button class="btn btn-default" type="button" data-impulso-action="toggle-password"><i data-feather="eye"></i></button></div><small>Atual: <?php echo esc($settings_public['global_api_key_masked'] ?? 'não configurada'); ?></small></div>
                        <div class="impulso-field"><label>Timeout</label><input class="form-control" id="impulso-setting-timeout" type="number" min="5" max="120" value="<?php echo (int) ($settings_public['request_timeout_seconds'] ?? 30); ?>"><small>Segundos</small></div>
                        <div class="impulso-field"><label>Tentativas de conexão</label><input class="form-control" id="impulso-setting-evolution-retries" type="number" min="0" max="5" value="<?php echo (int) ($settings_public['evolution_retries'] ?? 2); ?>"></div>
                    </div></div>
                </div>
                <div class="impulso-card"><div class="impulso-card-header"><div><h3>Endpoints Evolution v2</h3><p>Ajuste apenas se sua instalação usa caminhos personalizados</p></div></div><div class="impulso-card-body"><div class="impulso-field-grid">
                    <div class="impulso-field full"><label>Estado da conexão</label><input class="form-control" id="impulso-setting-status-path" value="<?php echo esc($settings_public['connection_status_path'] ?? '/instance/connectionState/{instance}'); ?>"></div>
                    <div class="impulso-field full"><label>Listar conversas</label><input class="form-control" id="impulso-setting-chats-path" value="<?php echo esc($settings_public['find_chats_path'] ?? '/chat/findChats/{instance}'); ?>"></div>
                    <div class="impulso-field full"><label>Histórico</label><input class="form-control" id="impulso-setting-messages-path" value="<?php echo esc($settings_public['find_messages_path'] ?? '/chat/findMessages/{instance}'); ?>"></div>
                    <div class="impulso-field full"><label>Enviar texto</label><input class="form-control" id="impulso-setting-send-path" value="<?php echo esc($settings_public['send_text_path'] ?? '/message/sendText/{instance}'); ?>"></div>
                    <div class="impulso-field full"><label>Enviar mídia</label><input class="form-control" id="impulso-setting-send-media-path" value="<?php echo esc($settings_public['send_media_path'] ?? '/message/sendMedia/{instance}'); ?>"></div>
                    <div class="impulso-field full"><label>Enviar áudio</label><input class="form-control" id="impulso-setting-send-audio-path" value="<?php echo esc($settings_public['send_audio_path'] ?? '/message/sendWhatsAppAudio/{instance}'); ?>"></div>
                    <div class="impulso-field full"><label>Obter mídia em base64</label><input class="form-control" id="impulso-setting-media-base64-path" value="<?php echo esc($settings_public['get_media_base64_path'] ?? '/chat/getBase64FromMediaMessage/{instance}'); ?>"></div>
                </div></div></div>
            </section>

            <section class="impulso-settings-panel" data-settings-panel="campaigns">
                <div class="impulso-card impulso-mb-14"><div class="impulso-card-header"><div><h3>Fila interna de disparos</h3><p>Controles aplicados no momento real de cada envio</p></div></div><div class="impulso-card-body"><div class="impulso-field-grid">
                    <div class="impulso-field"><label>Janela inicial</label><input class="form-control" id="impulso-setting-campaign-start" type="time" value="<?php echo esc($settings_public['campaign_window_start'] ?? '08:00'); ?>"></div>
                    <div class="impulso-field"><label>Janela final</label><input class="form-control" id="impulso-setting-campaign-end" type="time" value="<?php echo esc($settings_public['campaign_window_end'] ?? '20:00'); ?>"></div>
                    <div class="impulso-field"><label>Limite padrão por minuto</label><input class="form-control" id="impulso-setting-campaign-rate-limit" type="number" min="1" max="1000" value="<?php echo (int) ($settings_public['campaign_default_rate_limit_per_minute'] ?? 20); ?>"></div>
                    <div class="impulso-field"><label>Tentativas por destinatário</label><input class="form-control" id="impulso-setting-campaign-max-attempts" type="number" min="1" max="20" value="<?php echo (int) ($settings_public['campaign_recipient_max_attempts'] ?? 5); ?>"></div>
                    <div class="impulso-field"><label>Espera entre tentativas</label><input class="form-control" id="impulso-setting-campaign-retry-delay" type="number" min="30" max="3600" value="<?php echo (int) ($settings_public['campaign_retry_delay_seconds'] ?? 120); ?>"><small>Segundos</small></div>
                </div><div class="impulso-setting-row impulso-mt-14"><div class="impulso-setting-copy"><strong>Verificar opt-out antes de enviar</strong><span>O contato é removido mesmo que tenha sido bloqueado depois da criação da campanha.</span></div><label class="impulso-switch"><input id="impulso-setting-campaign-optout" type="checkbox" checked disabled><span></span></label></div><div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Pausar após falhas consecutivas</strong><span>Impede que uma indisponibilidade do provedor consuma toda a fila.</span></div><label class="impulso-switch"><input id="impulso-setting-campaign-pause-errors" type="checkbox" <?php echo (int) ($settings_public['campaign_pause_after_errors'] ?? 5) > 0 ? 'checked' : ''; ?>><span></span></label></div></div></div>
                <div class="impulso-card"><div class="impulso-card-header"><div><h3>Respostas rápidas</h3><p>Textos reutilizáveis pelo atendente</p></div></div><div class="impulso-card-body"><textarea class="form-control impulso-code-input" id="impulso-setting-quick-replies" rows="10" placeholder='[{"title":"Saudação","text":"Olá! Tudo bem?"}]'><?php echo esc($settings_public['quick_replies_json'] ?? '[]'); ?></textarea></div></div>
            </section>

            <section class="impulso-settings-panel" data-settings-panel="bots">
                <div class="impulso-card impulso-mb-14"><div class="impulso-card-header"><div><h3>Execução determinística</h3><p>Regras globais para fluxos definidos, sem IA e sem respostas inventadas</p></div></div><div class="impulso-card-body">
                    <div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Permitir execução dos bots publicados</strong><span>Cada fluxo ainda pode ser ativado ou pausado individualmente.</span></div><label class="impulso-switch"><input id="impulso-setting-bot-enabled" type="checkbox" <?php echo empty($settings_public['bot_enabled']) ? '' : 'checked'; ?>><span></span></label></div>
                    <div class="impulso-field-grid impulso-mt-14"><div class="impulso-field"><label>Expirar sessão após</label><input class="form-control" id="impulso-setting-bot-timeout" type="number" min="1" max="10080" value="<?php echo (int) ($settings_public['bot_session_timeout_minutes'] ?? 1440); ?>"><small>Minutos</small></div></div>
                </div></div>
                <div class="impulso-card"><div class="impulso-card-header"><div><h3>Guardrails padrão</h3><p>Mensagens usadas quando o fluxo não define textos próprios</p></div></div><div class="impulso-card-body"><div class="impulso-field-grid">
                    <div class="impulso-field full"><label>Fora do escopo</label><textarea class="form-control" id="impulso-setting-bot-fallback" rows="4" maxlength="4096"><?php echo esc($settings_public['bot_default_fallback'] ?? ''); ?></textarea><small>O bot nunca improvisa: usa este texto e encaminha conforme o fluxo.</small></div>
                    <div class="impulso-field full"><label>Encaminhamento humano</label><textarea class="form-control" id="impulso-setting-bot-handoff" rows="4" maxlength="4096"><?php echo esc($settings_public['bot_default_handoff'] ?? ''); ?></textarea></div>
                </div></div></div>
            </section>

            <section class="impulso-settings-panel" data-settings-panel="webhooks">
                <div class="impulso-card impulso-mb-14"><div class="impulso-card-header"><div><h3>Webhook Evolution</h3><p>Recebe mensagens, grupos, participantes e recibos</p></div><button class="btn btn-default btn-sm" type="button" data-impulso-action="copy-webhook"><i data-feather="copy"></i> Copiar</button></div><div class="impulso-card-body"><div class="impulso-code" id="impulso-webhook-endpoint">POST <?php echo esc($webhook_endpoint ?? get_uri('chatwoot_plugin/webhooks/evolution')); ?>
X-Chatwoot-Webhook-Secret: <?php echo esc($settings_public['webhook_secret_masked'] ?? 'não configurado'); ?>
Content-Type: application/json</div><div class="impulso-field impulso-mt-14"><label>Novo segredo Evolution</label><div class="input-group"><input class="form-control" id="impulso-setting-webhook-secret" type="password" autocomplete="new-password" placeholder="Vazio mantém o segredo atual"><button class="btn btn-default" type="button" data-impulso-action="toggle-password"><i data-feather="eye"></i></button></div></div><div class="impulso-setting-row impulso-mt-14"><div class="impulso-setting-copy"><strong>Registrar payload sanitizado</strong><span>Remove credenciais e mantém somente dados técnicos para deduplicação e diagnóstico.</span></div><label class="impulso-switch"><input id="impulso-setting-log-webhooks" type="checkbox" <?php echo empty($settings_public['log_sanitized_webhooks']) ? '' : 'checked'; ?>><span></span></label></div></div></div>
                <div class="impulso-card impulso-mb-14"><div class="impulso-card-header"><div><h3>Webhook oficial Meta</h3><p>O Verify Token e o App Secret são configurados em cada canal oficial</p></div></div><div class="impulso-card-body"><div class="impulso-code">GET/POST <?php echo esc(rtrim(get_uri('chatwoot_plugin/webhooks/meta'), '/') . '/{identificador-da-instancia}'); ?>
Assinatura: X-Hub-Signature-256
Objeto: whatsapp_business_account</div></div></div>
                <div class="impulso-card"><div class="impulso-card-header"><div><h3>Eventos Evolution recomendados</h3><p>Ative estes eventos no gerenciador da instância</p></div></div><div class="impulso-card-body"><div class="impulso-tag-list"><span class="impulso-badge primary">MESSAGES_UPSERT</span><span class="impulso-badge primary">MESSAGES_UPDATE</span><span class="impulso-badge primary">CONNECTION_UPDATE</span><span class="impulso-badge primary">CHATS_UPSERT</span><span class="impulso-badge primary">CONTACTS_UPSERT</span><span class="impulso-badge primary">GROUPS_UPSERT</span></div></div></div>
            </section>

            <section class="impulso-settings-panel" data-settings-panel="security">
                <div class="impulso-card impulso-mb-14"><div class="impulso-card-header"><div><h3>Proteções obrigatórias</h3><p>Controles técnicos que não dependem do operador</p></div></div><div class="impulso-card-body"><div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Credenciais criptografadas</strong><span>Tokens Meta, App Secret, Verify Token e chaves Evolution não são retornados pela API.</span></div><span class="impulso-badge success">Ativo</span></div><div class="impulso-setting-row"><div class="impulso-setting-copy"><strong>Bloquear mídia insegura</strong><span>Aceitar somente HTTPS ou URL assinada gerada pelo plugin.</span></div><label class="impulso-switch"><input id="impulso-setting-secure-media" type="checkbox" <?php echo empty($settings_public['secure_media']) ? '' : 'checked'; ?>><span></span></label></div></div></div>
                <div class="impulso-card"><div class="impulso-card-header"><div><h3>Retenção técnica</h3><p>Prazo para limpeza de dados auxiliares</p></div></div><div class="impulso-card-body"><div class="impulso-field-grid"><div class="impulso-field"><label>Webhooks sanitizados</label><input class="form-control" id="impulso-setting-webhook-retention" type="number" min="1" value="<?php echo (int) ($settings_public['webhook_retention_days'] ?? 30); ?>"><small>Dias</small></div><div class="impulso-field"><label>Cache de mídia</label><input class="form-control" id="impulso-setting-media-retention" type="number" min="0" value="<?php echo (int) ($settings_public['media_retention_days'] ?? 30); ?>"><small>Dias</small></div><div class="impulso-field"><label>Conversas resolvidas</label><input class="form-control" id="impulso-setting-conversation-retention" type="number" min="0" value="<?php echo (int) ($settings_public['conversation_retention_days'] ?? 0); ?>"><small>Zero mantém indefinidamente</small></div></div></div></div>
            </section>
        </div>
    </div>
</div>
