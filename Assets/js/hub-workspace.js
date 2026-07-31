(function (window, document) {
    'use strict';

    var app = document.getElementById('impulso-hub-app');
    var bridge = window.ImpulsoHubBridge;
    if (!app || !bridge) return;

    var config = bridge.getConfig ? bridge.getConfig() : {};
    var workspace = {
        pendingAttachment: null,
        pendingAttachmentUrl: '',
        composerMode: 'reply',
        mediaRecorder: null,
        mediaChunks: [],
        campaignStep: 1,
        contactPage: 1,
        contactHasMore: true,
        contactFilterTimer: null,
        emojiTarget: 'composer',
        globalSearchTimer: null,
        historySearchTimer: null,
        pendingCampaignMediaId: null,
        activeContext: null,
        searchTimer: null
    };
    window.ImpulsoHubWorkspace = workspace;

    function byId(id) { return document.getElementById(id); }
    function all(selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }
    function text(value) { return String(value == null ? '' : value); }
    function escapeHtml(value) {
        return text(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function iconRefresh() { if (bridge.replaceIcons) bridge.replaceIcons(); }
    function toast(title, message, icon) { bridge.toast(title, message, icon || 'check-circle'); }
    function endpoint(name) { return bridge.endpoint ? bridge.endpoint(name) : ''; }
    function endpointWithId(name, id, suffix) { return bridge.endpointWithId ? bridge.endpointWithId(name, id, suffix || '') : ''; }
    function api(url, options) {
        if (!url) return Promise.reject(new Error('Recurso indisponível nesta instalação.'));
        return bridge.api(url, options || {});
    }
    function modal(id) { if (bridge.openModal) bridge.openModal(id); }
    function closeModal(element) { if (bridge.closeModal) bridge.closeModal(element); }
    function setBusy(button, busy, label) {
        if (!button) return;
        if (busy) {
            button.dataset.originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> ' + escapeHtml(label || 'Processando');
        } else {
            button.disabled = false;
            if (button.dataset.originalHtml) button.innerHTML = button.dataset.originalHtml;
            delete button.dataset.originalHtml;
            iconRefresh();
        }
    }
    function payloadData(payload, fallback) { return payload && payload.data != null ? payload.data : fallback; }
    function backendError(error, action) {
        var message = error && error.message ? error.message : 'Não foi possível concluir a ação.';
        if (error && error.status === 404) message = 'O recurso solicitado não foi encontrado.';
        if (error && error.status === 405) message = 'Esta ação não é aceita pelo servidor.';
        toast('Ação não concluída', message, 'alert-triangle');
    }
    function formatBytes(bytes) {
        bytes = Number(bytes || 0);
        if (!bytes) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var index = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
        return (bytes / Math.pow(1024, index)).toFixed(index ? 1 : 0) + ' ' + units[index];
    }
    function activeConversation() { return bridge.getActiveConversation ? bridge.getActiveConversation() : null; }
    function activeState() { return bridge.getState ? bridge.getState() : {}; }
    function currentPageUrl(tab) {
        var page = endpoint('page') || window.location.pathname;
        return page + (page.indexOf('?') >= 0 ? '&' : '?') + 'chatwoot_tab=' + encodeURIComponent(tab);
    }
    function goToTab(tab) { window.location.href = currentPageUrl(tab); }

    /* Modal/action helpers */
    function resetNewConversation() {
        ['impulso-new-conversation-contact', 'impulso-new-conversation-name', 'impulso-new-conversation-message'].forEach(function (id) { var el = byId(id); if (el) el.value = ''; });
        var instance = byId('impulso-new-conversation-instance');
        if (instance) instance.value = '';
        var count = byId('impulso-new-conversation-char-count');
        if (count) count.textContent = '0';
    }
    function openNewConversation(contact) {
        resetNewConversation(); contact = contact || {};
        var phone = byId('impulso-new-conversation-contact'); if (phone) phone.value = contact.phone || '';
        var name = byId('impulso-new-conversation-name'); if (name) name.value = contact.name || '';
        var instance = byId('impulso-new-conversation-instance'); if (instance && contact.instance_id) instance.value = contact.instance_id;
        modal('impulso-new-conversation-modal'); window.setTimeout(function () { var el = byId(contact.phone ? 'impulso-new-conversation-message' : 'impulso-new-conversation-contact'); if (el) el.focus(); }, 180);
    }
    function openNewContact(contact) {
        contact = contact || {};
        var map = {
            'impulso-contact-id': contact.id || '',
            'impulso-contact-form-name': contact.name || '',
            'impulso-contact-form-phone': contact.phone || '',
            'impulso-contact-form-email': contact.email || '',
            'impulso-contact-form-company': contact.company || '',
            'impulso-contact-form-city': contact.city || '',
            'impulso-contact-form-source': contact.source || 'whatsapp',
            'impulso-contact-form-instance': contact.instance_id || '',
            'impulso-contact-form-tags': Array.isArray(contact.tags) ? contact.tags.join(', ') : (contact.tags || ''),
            'impulso-contact-form-notes': contact.notes || ''
        };
        Object.keys(map).forEach(function (id) { var el = byId(id); if (el) el.value = map[id]; });
        var optOut = byId('impulso-contact-form-opt-out'); if (optOut) optOut.checked = !!contact.opt_out;
        var title = byId('impulso-contact-modal-title'); if (title) title.textContent = contact.id ? 'Editar contato' : 'Novo contato';
        modal('impulso-new-contact-modal');
    }

    function campaignStep(step) {
        workspace.campaignStep = Math.max(1, Math.min(4, Number(step || 1)));
        all('[data-campaign-panel]').forEach(function (panel) { panel.classList.toggle('impulso-hidden', Number(panel.getAttribute('data-campaign-panel')) !== workspace.campaignStep); });
        all('[data-campaign-step]').forEach(function (button) {
            var itemStep = Number(button.getAttribute('data-campaign-step'));
            button.classList.toggle('active', itemStep === workspace.campaignStep);
            button.classList.toggle('done', itemStep < workspace.campaignStep);
        });
        var previous = byId('impulso-campaign-previous');
        var next = byId('impulso-campaign-next');
        var save = byId('impulso-campaign-save');
        if (previous) previous.disabled = workspace.campaignStep === 1;
        if (next) next.classList.toggle('impulso-hidden', workspace.campaignStep === 4);
        if (save) save.classList.toggle('impulso-hidden', workspace.campaignStep !== 4);
    }
    function resetCampaignForm() {
        ['impulso-campaign-id','impulso-campaign-name','impulso-campaign-description','impulso-campaign-include-tags','impulso-campaign-exclude-tags','impulso-campaign-manual-numbers','impulso-campaign-message'].forEach(function (id) { var el = byId(id); if (el) el.value = ''; });
        ['impulso-campaign-instance'].forEach(function (id) { var el = byId(id); if (el) el.value = ''; });
        var count = byId('impulso-campaign-audience-count'); if (count) count.textContent = '0';
        workspace.pendingCampaignMediaId = null; var campaignFile = byId('impulso-campaign-file'); if (campaignFile) campaignFile.value = '';
        updateCampaignPreview();
        campaignStep(1);
    }
    function openCampaign(campaign) {
        resetCampaignForm();
        campaign = campaign || {};
        workspace.pendingCampaignMediaId = Number(campaign.media_id || 0) || null;
        var values = {
            'impulso-campaign-id': campaign.id || '', 'impulso-campaign-name': campaign.name || '',
            'impulso-campaign-instance': campaign.instance_id || '', 'impulso-campaign-type': campaign.type || 'one_time',
            'impulso-campaign-description': campaign.description || '', 'impulso-campaign-audience-source': campaign.audience_source || 'contacts',
            'impulso-campaign-include-tags': Array.isArray(campaign.include_tags) ? campaign.include_tags.join(', ') : '',
            'impulso-campaign-exclude-tags': Array.isArray(campaign.exclude_tags) ? campaign.exclude_tags.join(', ') : '',
            'impulso-campaign-manual-numbers': Array.isArray(campaign.numbers) ? campaign.numbers.join('\n') : '',
            'impulso-campaign-message': campaign.message || '', 'impulso-campaign-start-date': campaign.start_date || '',
            'impulso-campaign-start-time': campaign.start_time || '', 'impulso-campaign-end-time': campaign.end_time || '20:00',
            'impulso-campaign-interval': campaign.interval_seconds || 10
        };
        Object.keys(values).forEach(function (id) { var el = byId(id); if (el) el.value = values[id]; });
        var title = byId('impulso-campaign-modal-title'); if (title) title.textContent = campaign.id ? 'Editar campanha' : 'Nova campanha';
        updateCampaignPreview();
        modal('impulso-campaign-modal');
    }
    function updateCampaignPreview() {
        var message = byId('impulso-campaign-message');
        var name = byId('impulso-campaign-name');
        var preview = byId('impulso-campaign-preview');
        var title = byId('impulso-campaign-preview-title');
        if (title) title.textContent = name && name.value.trim() ? name.value.trim() : 'Campanha';
        if (preview) preview.innerHTML = escapeHtml(message && message.value.trim() ? message.value.trim() : 'Sua mensagem aparecerá aqui.').replace(/\n/g, '<br>') + '<span class="impulso-wa-time">agora</span>';
    }
    function validateCampaignStep() {
        if (workspace.campaignStep === 1) {
            if (!byId('impulso-campaign-name').value.trim() || !byId('impulso-campaign-instance').value) { toast('Dados incompletos', 'Informe o nome e a instância da campanha.', 'alert-circle'); return false; }
        }
        if (workspace.campaignStep === 3 && !byId('impulso-campaign-message').value.trim()) { toast('Mensagem vazia', 'Escreva o conteúdo da campanha.', 'alert-circle'); return false; }
        return true;
    }

    function openAgent(agent) {
        agent = agent || {};
        var values = {
            'impulso-agent-id': agent.id || '', 'impulso-agent-name': agent.name || '',
            'impulso-agent-instance': agent.instance_id || '', 'impulso-agent-webhook': agent.webhook_url || '',
            'impulso-agent-workflow-id': agent.workflow_id || '', 'impulso-agent-default-mode': agent.default_mode || 'running',
            'impulso-agent-description': agent.description || ''
        };
        Object.keys(values).forEach(function (id) { var el = byId(id); if (el) el.value = values[id]; });
        var active = byId('impulso-agent-active'); if (active) active.checked = agent.active !== false;
        var title = byId('impulso-ai-modal-title'); if (title) title.textContent = agent.id ? 'Editar agente' : 'Novo agente';
        modal('impulso-ai-modal');
    }
    function openAutomation(automation) {
        automation = automation || {};
        var values = {
            'impulso-automation-id': automation.id || '', 'impulso-automation-name': automation.name || '',
            'impulso-automation-trigger': automation.trigger || 'message_received', 'impulso-automation-webhook': automation.webhook_url || '',
            'impulso-automation-conditions': automation.conditions ? JSON.stringify(automation.conditions, null, 2) : ''
        };
        Object.keys(values).forEach(function (id) { var el = byId(id); if (el) el.value = values[id]; });
        var active = byId('impulso-automation-active'); if (active) active.checked = automation.active !== false;
        modal('impulso-automation-modal');
    }

    /* Chat refinement */
    var emojis = ['😀','😃','😄','😁','😅','😂','🤣','😊','🙂','🙃','😉','😍','🥰','😘','😎','🤩','🤔','🤗','🤝','👍','👎','👏','🙌','🙏','💪','✅','❌','⚠️','🔥','🎉','❤️','💜','💙','💚','💛','📌','📅','📞','📲','💬','🚀','⭐','🏆','👋','📍','⏰','💡','📝'];
    var defaultQuickReplies = [];
    function ensureEmojiPicker() {
        var picker = byId('impulso-emoji-picker');
        if (!picker || picker.dataset.ready) return;
        picker.innerHTML = '<div class="impulso-emoji-grid">' + emojis.map(function (emoji) { return '<button type="button" data-emoji="' + escapeHtml(emoji) + '">' + emoji + '</button>'; }).join('') + '</div>';
        picker.dataset.ready = '1';
    }
    function toggleEmojiPicker() {
        ensureEmojiPicker();
        var picker = byId('impulso-emoji-picker'); var quick = byId('impulso-quick-replies');
        if (quick) quick.classList.add('impulso-hidden');
        if (picker) picker.classList.toggle('impulso-hidden');
    }
    function insertAtCursor(input, value) {
        if (!input || input.disabled) return;
        var start = input.selectionStart == null ? input.value.length : input.selectionStart;
        var end = input.selectionEnd == null ? input.value.length : input.selectionEnd;
        input.value = input.value.slice(0, start) + value + input.value.slice(end);
        input.selectionStart = input.selectionEnd = start + value.length;
        input.focus();
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }
    function renderQuickReplies(rows) {
        var panel = byId('impulso-quick-replies'); if (!panel) return;
        rows = Array.isArray(rows) ? rows : [];
        panel.innerHTML = rows.length ? '<div class="impulso-quick-reply-list">' + rows.map(function (item) { return '<button type="button" data-quick-reply="' + escapeHtml(item.text || item.content || '') + '"><strong>' + escapeHtml(item.title || item.name || 'Resposta') + '</strong><span>' + escapeHtml(item.text || item.content || '') + '</span></button>'; }).join('') + '</div>' : '<div class="impulso-empty compact"><p>Nenhuma resposta rápida cadastrada.</p></div>';
        iconRefresh();
    }
    function toggleQuickReplies() {
        var panel = byId('impulso-quick-replies'); var picker = byId('impulso-emoji-picker');
        if (!panel) return;
        if (picker) picker.classList.add('impulso-hidden');
        if (!panel.dataset.loaded) {
            panel.innerHTML = '<div class="impulso-empty compact"><span class="spinner-border spinner-border-sm"></span><p>Carregando respostas...</p></div>';
            panel.dataset.loaded = '1';
            api(endpoint('quickReplies')).then(function (payload) { renderQuickReplies(payloadData(payload, [])); }).catch(function (error) { panel.dataset.loaded = ''; panel.innerHTML = '<div class="impulso-empty compact"><p>' + escapeHtml(error.message || 'Falha ao carregar respostas.') + '</p></div>'; });
        }
        panel.classList.toggle('impulso-hidden');
    }
    function clearAttachment() {
        if (workspace.pendingAttachmentUrl) { try { URL.revokeObjectURL(workspace.pendingAttachmentUrl); } catch (error) {} }
        workspace.pendingAttachment = null; workspace.pendingAttachmentUrl = '';
        var input = byId('impulso-attachment-input'); if (input) input.value = '';
        var preview = byId('impulso-attachment-preview'); if (preview) { preview.innerHTML = ''; preview.classList.add('impulso-hidden'); }
    }
    function setAttachment(file) {
        clearAttachment();
        if (!file) return;
        workspace.pendingAttachment = file;
        workspace.pendingAttachmentUrl = URL.createObjectURL(file);
        var preview = byId('impulso-attachment-preview'); if (!preview) return;
        var visual = file.type.indexOf('image/') === 0 ? '<img src="' + escapeHtml(workspace.pendingAttachmentUrl) + '" alt="Prévia">' : '<span class="impulso-media-icon"><i data-feather="' + (file.type.indexOf('audio/') === 0 ? 'volume-2' : 'file-text') + '"></i></span>';
        preview.innerHTML = visual + '<div class="impulso-attachment-preview-copy"><strong>' + escapeHtml(file.name || 'Áudio gravado') + '</strong><span>' + escapeHtml(file.type || 'arquivo') + ' · ' + formatBytes(file.size) + '</span></div><button class="impulso-icon-button btn btn-default" type="button" data-impulso-action="remove-attachment"><i data-feather="x"></i></button>';
        preview.classList.remove('impulso-hidden'); iconRefresh();
    }
    function sendAttachment() {
        var conversation = activeConversation(); var file = workspace.pendingAttachment; var input = byId('impulso-message-input');
        if (!conversation || !file) return Promise.resolve(false);
        var form = new FormData();
        form.append('file', file, file.name || ('audio-' + Date.now() + '.webm'));
        form.append('caption', input ? input.value.trim() : '');
        form.append('client_message_id', 'media-' + Date.now() + '-' + Math.random().toString(16).slice(2));
        var send = byId('impulso-send-message'); setBusy(send, true, 'Enviando');
        return api(endpointWithId('conversations', conversation.id, '/attachments'), { method: 'POST', body: form }).then(function (payload) {
            var message = bridge.normalizeMessage(payloadData(payload, {}));
            bridge.mergeMessages([message], false); bridge.renderMessages({ forceBottom: true });
            if (input) input.value = ''; clearAttachment();
            toast('Mídia enviada', 'O arquivo foi enviado pelo WhatsApp.', 'check-circle');
            return true;
        }).catch(function (error) { backendError(error, 'envio de mídia'); return false; }).finally(function () { setBusy(send, false); if (bridge.updateComposerState) bridge.updateComposerState(); });
    }
    function sendInternalNote() {
        var conversation = activeConversation(); var input = byId('impulso-message-input'); var note = input ? input.value.trim() : '';
        if (!conversation || !note) { toast('Nota vazia', 'Digite o conteúdo da nota interna.', 'alert-circle'); return; }
        var send = byId('impulso-send-message'); setBusy(send, true, 'Salvando');
        api(endpointWithId('conversations', conversation.id, '/notes'), { method: 'POST', body: { content: note } }).then(function () { input.value = ''; toast('Nota adicionada', 'A nota interna foi registrada.', 'file-text'); bridge.loadMessages('after', false); }).catch(function (error) { backendError(error, 'notas internas'); }).finally(function () { setBusy(send, false); });
    }
    function composerSubmit(event) {
        if (workspace.pendingAttachment || workspace.composerMode === 'note') {
            if (event) { event.preventDefault(); event.stopImmediatePropagation(); }
            if (workspace.pendingAttachment) sendAttachment(); else sendInternalNote();
            return true;
        }
        return false;
    }
    function setComposerMode(mode) {
        workspace.composerMode = mode === 'note' ? 'note' : 'reply';
        var composer = document.querySelector('.impulso-composer'); if (composer) composer.setAttribute('data-mode', workspace.composerMode);
        all('[data-composer-mode]').forEach(function (button) { button.classList.toggle('active', button.getAttribute('data-composer-mode') === workspace.composerMode); });
        var input = byId('impulso-message-input'); if (input) input.placeholder = workspace.composerMode === 'note' ? 'Escreva uma nota interna; ela não será enviada ao WhatsApp' : 'Digite uma mensagem…';
        var badge = byId('impulso-ai-suggestion-badge'); if (badge) badge.innerHTML = workspace.composerMode === 'note' ? '<i data-feather="lock"></i> Nota privada' : '<i data-feather="cpu"></i> Sugestões da IA';
        iconRefresh();
    }
    function startVoiceRecording() {
        var button = byId('impulso-voice-button');
        if (workspace.mediaRecorder && workspace.mediaRecorder.state === 'recording') { workspace.mediaRecorder.stop(); return; }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) { toast('Gravação indisponível', 'Este navegador não oferece suporte à gravação de áudio.', 'mic-off'); return; }
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            workspace.mediaChunks = [];
            workspace.mediaRecorder = new MediaRecorder(stream);
            workspace.mediaRecorder.addEventListener('dataavailable', function (event) { if (event.data && event.data.size) workspace.mediaChunks.push(event.data); });
            workspace.mediaRecorder.addEventListener('stop', function () {
                stream.getTracks().forEach(function (track) { track.stop(); });
                if (button) { button.classList.remove('is-recording'); button.innerHTML = '<i data-feather="mic"></i>'; }
                var blob = new Blob(workspace.mediaChunks, { type: workspace.mediaRecorder.mimeType || 'audio/webm' });
                var file = new File([blob], 'audio-' + new Date().toISOString().replace(/[:.]/g, '-') + '.webm', { type: blob.type });
                setAttachment(file); iconRefresh();
            });
            workspace.mediaRecorder.start();
            if (button) { button.classList.add('is-recording'); button.innerHTML = '<i data-feather="square"></i>'; }
            toast('Gravando áudio', 'Clique novamente no microfone para finalizar.', 'mic'); iconRefresh();
        }).catch(function () { toast('Microfone bloqueado', 'Autorize o uso do microfone no navegador.', 'mic-off'); });
    }
    function openMedia(kind, url, title) {
        if (!url) return;
        var stage = byId('impulso-media-stage'); var download = byId('impulso-media-download'); var mediaTitle = byId('impulso-media-title');
        if (mediaTitle) mediaTitle.textContent = title || (kind === 'image' ? 'Imagem' : kind === 'audio' ? 'Áudio' : 'Documento');
        if (download) download.href = url;
        if (stage) {
            if (kind === 'image') stage.innerHTML = '<img src="' + escapeHtml(url) + '" alt="Imagem da conversa">';
            else if (kind === 'audio') stage.innerHTML = '<audio controls autoplay src="' + escapeHtml(url) + '"></audio>';
            else if (kind === 'video') stage.innerHTML = '<video controls autoplay src="' + escapeHtml(url) + '"></video>';
            else if (/\.pdf($|\?)/i.test(url)) stage.innerHTML = '<iframe src="' + escapeHtml(url) + '" title="Documento"></iframe>';
            else stage.innerHTML = '<div class="impulso-empty"><div class="impulso-empty-icon"><i data-feather="file-text"></i></div><h4>Visualização externa</h4><p>Este formato será aberto em uma nova guia.</p><a class="btn btn-primary" href="' + escapeHtml(url) + '" target="_blank" rel="noopener">Abrir arquivo</a></div>';
        }
        modal('impulso-media-modal'); iconRefresh();
    }
    function searchHistory(query) {
        query = text(query).trim().toLowerCase(); var matches = 0;
        all('#impulso-chat-body .impulso-message-row').forEach(function (row) { var hit = !!query && (row.getAttribute('data-message-search') || '').indexOf(query) >= 0; row.querySelector('.impulso-message').classList.toggle('is-search-match', hit); if (hit) matches += 1; });
        var count = byId('impulso-history-search-count'); if (count) count.textContent = matches + ' resultado' + (matches === 1 ? '' : 's');
        var first = document.querySelector('#impulso-chat-body .impulso-message.is-search-match'); if (first) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
        window.clearTimeout(workspace.historySearchTimer);
        if (query.length < 2) return;
        var conversation = activeConversation(); if (!conversation || !endpoint('search')) return;
        workspace.historySearchTimer = window.setTimeout(function () {
            api(endpoint('search') + '?conversation_id=' + encodeURIComponent(conversation.id) + '&q=' + encodeURIComponent(query) + '&limit=50').then(function (payload) {
                var result = payloadData(payload, {}); var total = Number(result.total || 0);
                if (count) count.textContent = total + ' resultado' + (total === 1 ? '' : 's') + ' no histórico';
            }).catch(function (error) { if (count) count.textContent = error.message || 'Falha na busca'; });
        }, 250);
    }
    function conversationAction(action, body) {
        var conversation = activeConversation(); if (!conversation) return Promise.reject(new Error('Selecione uma conversa.'));
        return api(endpointWithId('conversations', conversation.id, '/' + action), { method: 'POST', body: body || {} });
    }
    function togglePriority(button) {
        var active = button.classList.contains('active'); setBusy(button, true, 'Salvando');
        conversationAction('priority', { priority: !active }).then(function () { button.classList.toggle('active', !active); toast(!active ? 'Prioridade ativada' : 'Prioridade removida', 'A conversa foi atualizada.', 'flag'); }).catch(function (error) { backendError(error, 'prioridade'); }).finally(function () { setBusy(button, false); });
    }
    function resolveConversation(button) {
        var conversation = activeConversation(); if (!conversation) return;
        var willResolve = conversation.status !== 'resolved'; setBusy(button, true, willResolve ? 'Resolvendo' : 'Reabrindo');
        conversationAction(willResolve ? 'resolve' : 'reopen', {}).then(function (payload) { conversation.status = willResolve ? 'resolved' : 'open'; button.classList.toggle('btn-success', !willResolve); button.classList.toggle('btn-default', willResolve); button.innerHTML = willResolve ? '<i data-feather="rotate-ccw"></i> Reabrir' : '<i data-feather="check"></i> Resolver'; toast(willResolve ? 'Conversa resolvida' : 'Conversa reaberta', 'Status atualizado.', 'check-circle'); if (bridge.loadConversations) bridge.loadConversations(true); }).catch(function (error) { backendError(error, 'status da conversa'); }).finally(function () { setBusy(button, false); iconRefresh(); });
    }
    function callContact() { var conversation = activeConversation(); var phone = conversation && text(conversation.phone).replace(/\D/g, ''); if (!phone) { toast('Telefone indisponível', 'O contato não possui um número válido.', 'phone-off'); return; } window.location.href = 'tel:+' + phone; }
    function refreshAiConversationState() {
        var conversation = activeConversation(); var element = byId('impulso-ai-runtime'); if (!conversation || !element) return;
        element.className = 'impulso-ai-runtime'; element.innerHTML = '<span class="impulso-status-dot"></span><strong>Consultando estado...</strong>';
        api(endpointWithId('aiState', conversation.id, '')).then(function (payload) { var data = payloadData(payload, {}); var status = data.status || data.bot_status || 'running'; element.classList.add(status === 'human' || status === 'handoff' ? 'is-human' : status === 'paused' ? 'is-paused' : 'is-running'); element.innerHTML = '<span class="impulso-status-dot"></span><strong>' + escapeHtml(status === 'human' || status === 'handoff' ? 'Atendimento humano' : status === 'paused' ? 'IA pausada' : 'IA ativa') + '</strong>'; var context = byId('impulso-ai-context'); if (context) context.textContent = data.summary || data.context_summary || 'Sem resumo operacional.'; }).catch(function () { element.innerHTML = '<span class="impulso-status-dot"></span><strong>Estado não disponível</strong>'; });
    }

    /* Contacts */
    function contactListUrl(page) {
        var params = new URLSearchParams();
        var query = text((byId('impulso-contact-search') || {}).value).trim();
        var instance = text((byId('impulso-contact-instance-filter') || {}).value || 'all');
        var status = text((byId('impulso-contact-status-filter') || {}).value || 'all');
        params.set('page', String(page || 1)); params.set('limit', '50');
        if (query) params.set('q', query);
        if (instance !== 'all') params.set('instance_id', instance);
        if (status !== 'all') params.set('status', status);
        return endpoint('contacts') + '?' + params.toString();
    }
    function loadContactsPage(page, append, button) {
        setBusy(button, true, append ? 'Carregando' : 'Atualizando');
        return api(contactListUrl(page)).then(function (payload) {
            var rows = payloadData(payload, []); var meta = payload.meta || {}; var body = document.querySelector('#impulso-contacts-table tbody');
            if (!body) return;
            if (!append) body.innerHTML = '';
            var empty = body.querySelector('.impulso-empty-row'); if (empty) empty.remove();
            if (rows.length) body.insertAdjacentHTML('beforeend', rows.map(contactRowHtml).join(''));
            if (!body.querySelector('tr[data-contact-id]')) body.innerHTML = '<tr class="impulso-empty-row"><td colspan="8">Nenhum contato encontrado.</td></tr>';
            workspace.contactPage = page; workspace.contactHasMore = !!meta.has_more;
            all('.impulso-contact-select', body).forEach(function (input) { input.addEventListener('change', updateContactBulk); });
            var count = byId('impulso-contact-result-count'); var total = Number(meta.total || 0); if (count) count.textContent = total + ' contato' + (total === 1 ? '' : 's');
            var more = document.querySelector('[data-impulso-action="load-more-contacts"]'); if (more) more.disabled = !workspace.contactHasMore;
            updateContactBulk(); iconRefresh();
        }).catch(function (error) { backendError(error, append ? 'paginação de contatos' : 'filtros de contatos'); }).finally(function () {
            setBusy(button, false); if (button && button.getAttribute('data-impulso-action') === 'load-more-contacts') button.disabled = !workspace.contactHasMore;
        });
    }
    function applyContactFilters() {
        window.clearTimeout(workspace.contactFilterTimer);
        workspace.contactFilterTimer = window.setTimeout(function () { loadContactsPage(1, false, null); }, 250);
    }
    function updateContactBulk() {
        var selected = all('.impulso-contact-select:checked'); var bar = byId('impulso-contact-bulk-bar'); var count = byId('impulso-contact-selected-count');
        if (bar) bar.classList.toggle('impulso-hidden', !selected.length); if (count) count.textContent = selected.length;
    }
    function contactFromRow(id) {
        var row = document.querySelector('[data-contact-id="' + CSS.escape(text(id)) + '"]');
        if (!row) return { id: id };
        var cells = row.querySelectorAll('td'); var name = row.querySelector('.impulso-person-copy strong'); var email = row.querySelector('.impulso-person-copy span');
        return { id: id, name: name ? name.textContent.trim() : '', email: email ? email.textContent.trim() : '', phone: cells[2] ? cells[2].textContent.trim() : '', instance: cells[3] ? cells[3].textContent.trim() : '', instance_id: row.getAttribute('data-contact-instance') || '', tags: all('.impulso-tag-list .impulso-badge', row).map(function (tag) { return tag.textContent.trim(); }) };
    }
    function refreshContacts() {
        var button = document.querySelector('[data-impulso-action="refresh-contacts"]');
        loadContactsPage(1, false, button);
    }

    /* Campaigns */
    function applyCampaignFilters() {
        var query = text((byId('impulso-campaign-search') || {}).value).trim().toLowerCase(); var status = text((byId('impulso-campaign-status-filter') || {}).value || 'all'); var instance = text((byId('impulso-campaign-instance-filter') || {}).value || 'all'); var visible = 0;
        all('[data-campaign-id]').forEach(function (row) { var show = (!query || (row.getAttribute('data-campaign-search') || '').indexOf(query) >= 0) && (status === 'all' || row.getAttribute('data-campaign-status') === status) && (instance === 'all' || row.getAttribute('data-campaign-instance') === instance); row.classList.toggle('impulso-hidden', !show); if (show) visible += 1; });
        var empty = byId('impulso-campaign-empty'); if (empty) empty.classList.toggle('impulso-hidden', visible > 0);
    }
    function campaignPayload() {
        var weekdays = all('#impulso-campaign-weekdays input:checked').map(function (item) { return item.value; });
        return {
            id: Number((byId('impulso-campaign-id') || {}).value || 0), name: (byId('impulso-campaign-name') || {}).value.trim(), instance_id: Number((byId('impulso-campaign-instance') || {}).value || 0), type: (byId('impulso-campaign-type') || {}).value,
            description: (byId('impulso-campaign-description') || {}).value.trim(), audience_source: (byId('impulso-campaign-audience-source') || {}).value,
            include_tags: (byId('impulso-campaign-include-tags') || {}).value.split(',').map(function (v) { return v.trim(); }).filter(Boolean), exclude_tags: (byId('impulso-campaign-exclude-tags') || {}).value.split(',').map(function (v) { return v.trim(); }).filter(Boolean),
            numbers: (byId('impulso-campaign-manual-numbers') || {}).value.split(/\r?\n/).map(function (v) { return v.replace(/\D/g, ''); }).filter(Boolean), message: (byId('impulso-campaign-message') || {}).value.trim(), media_id: workspace.pendingCampaignMediaId, start_date: (byId('impulso-campaign-start-date') || {}).value, start_time: (byId('impulso-campaign-start-time') || {}).value, end_time: (byId('impulso-campaign-end-time') || {}).value, interval_seconds: Number((byId('impulso-campaign-interval') || {}).value || 10), weekdays: weekdays, start_immediately: !!((byId('impulso-campaign-start-immediately') || {}).checked)
        };
    }
    function uploadCampaignMedia(file) {
        if (!file) return; var form = new FormData(); form.append('file', file); var instance = Number((byId('impulso-campaign-instance') || {}).value || 0); if (instance) form.append('instance_id', instance);
        toast('Enviando mídia', 'Aguarde a validação do arquivo.', 'upload');
        api(endpoint('mediaUpload'), { method: 'POST', body: form }).then(function (payload) { var data = payloadData(payload, {}); workspace.pendingCampaignMediaId = Number(data.media_id || data.id || 0) || null; toast('Mídia anexada', data.name || 'Arquivo pronto para a campanha.', 'paperclip'); }).catch(function (error) { workspace.pendingCampaignMediaId = null; var input = byId('impulso-campaign-file'); if (input) input.value = ''; backendError(error, 'mídia da campanha'); });
    }
    function importCampaignAudienceCsv() {
        var input = document.createElement('input'); input.type = 'file'; input.accept = '.csv,text/csv';
        input.addEventListener('change', function () {
            var file = input.files && input.files[0]; if (!file) return;
            if (file.size > 2 * 1024 * 1024) { toast('CSV muito grande', 'Use um arquivo de até 2 MB.', 'alert-circle'); return; }
            var reader = new FileReader();
            reader.onload = function () {
                var numbers = []; var seen = {};
                text(reader.result).split(/\r?\n/).forEach(function (line) {
                    line.split(/[;,\t]/).some(function (cell) {
                        var digits = text(cell).replace(/\D/g, '');
                        if (digits.length >= 10 && digits.length <= 15) { if (!seen[digits]) { seen[digits] = true; numbers.push(digits); } return true; }
                        return false;
                    });
                });
                var target = byId('impulso-campaign-manual-numbers'); if (target) target.value = numbers.join('\n');
                toast(numbers.length ? 'Público importado' : 'CSV sem telefones', numbers.length ? numbers.length + ' número(s) prontos para validação no servidor.' : 'Nenhum telefone válido foi identificado.', numbers.length ? 'users' : 'alert-circle');
            };
            reader.onerror = function () { toast('Falha ao ler CSV', 'Selecione outro arquivo.', 'alert-circle'); };
            reader.readAsText(file, 'UTF-8');
        });
        input.click();
    }
    function saveCampaign(button) {
        var data = campaignPayload(); if (!data.name || !data.instance_id || !data.message) { toast('Dados incompletos', 'Nome, instância e mensagem são obrigatórios.', 'alert-circle'); return; }
        setBusy(button, true, 'Salvando'); var url = data.id ? endpointWithId('campaigns', data.id) : endpoint('campaigns');
        api(url, { method: data.id ? 'PUT' : 'POST', body: data }).then(function () { closeModal(button); toast('Campanha salva', data.start_immediately ? 'O disparo foi encaminhado ao n8n.' : 'A campanha foi registrada.', 'send'); window.setTimeout(function () { window.location.reload(); }, 500); }).catch(function (error) { backendError(error, 'campanhas'); }).finally(function () { setBusy(button, false); });
    }
    function calculateAudience(button) { setBusy(button, true, 'Calculando'); api(endpoint('campaigns').replace(/\/$/, '') + '/audience-preview', { method: 'POST', body: campaignPayload() }).then(function (payload) { var data = payloadData(payload, {}); var count = byId('impulso-campaign-audience-count'); if (count) count.textContent = Number(data.count || data.total || 0).toLocaleString('pt-BR'); }).catch(function (error) { backendError(error, 'prévia de público'); }).finally(function () { setBusy(button, false); }); }
    function campaignMenu(button) {
        var id = button.getAttribute('data-campaign-id'); showContextMenu(button, [
            { label: 'Editar campanha', icon: 'edit-3', action: function () { api(endpointWithId('campaigns', id)).then(function (payload) { openCampaign(payloadData(payload, {})); }).catch(function (error) { backendError(error, 'campanhas'); }); } },
            { label: 'Duplicar', icon: 'copy', action: function () { api(endpointWithId('campaigns', id, '/duplicate'), { method: 'POST', body: {} }).then(function () { toast('Campanha duplicada', 'Uma nova cópia foi criada.', 'copy'); window.location.reload(); }).catch(function (error) { backendError(error, 'duplicação'); }); } },
            { label: 'Pausar/retomar', icon: 'pause-circle', action: function () { api(endpointWithId('campaigns', id, '/toggle'), { method: 'POST', body: {} }).then(function () { toast('Campanha atualizada', 'O estado do disparo foi alterado.', 'pause-circle'); window.location.reload(); }).catch(function (error) { backendError(error, 'campanha'); }); } },
            { label: 'Excluir', icon: 'trash-2', danger: true, action: function () { if (!window.confirm('Excluir esta campanha?')) return; api(endpointWithId('campaigns', id), { method: 'DELETE', body: {} }).then(function () { window.location.reload(); }).catch(function (error) { backendError(error, 'exclusão'); }); } }
        ]); }

    /* AI and automations */
    function saveAgent(button) {
        var id = Number((byId('impulso-agent-id') || {}).value || 0); var data = { name: (byId('impulso-agent-name') || {}).value.trim(), instance_id: Number((byId('impulso-agent-instance') || {}).value || 0) || null, webhook_url: (byId('impulso-agent-webhook') || {}).value.trim(), workflow_id: (byId('impulso-agent-workflow-id') || {}).value.trim(), default_mode: (byId('impulso-agent-default-mode') || {}).value, description: (byId('impulso-agent-description') || {}).value.trim(), active: !!((byId('impulso-agent-active') || {}).checked) };
        if (!data.name || !data.webhook_url) { toast('Dados incompletos', 'Informe o nome e o endpoint do n8n.', 'alert-circle'); return; }
        setBusy(button, true, 'Salvando'); api(id ? endpointWithId('aiAgents', id) : endpoint('aiAgents'), { method: id ? 'PUT' : 'POST', body: data }).then(function () { closeModal(button); toast('Agente salvo', 'A configuração foi registrada.', 'cpu'); window.location.reload(); }).catch(function (error) { backendError(error, 'agentes de IA'); }).finally(function () { setBusy(button, false); });
    }
    function saveAutomation(button) {
        var id = Number((byId('impulso-automation-id') || {}).value || 0); var conditions = {}; try { conditions = JSON.parse((byId('impulso-automation-conditions') || {}).value || '{}'); } catch (error) { toast('JSON inválido', 'Revise as condições da automação.', 'code'); return; }
        var data = { name: (byId('impulso-automation-name') || {}).value.trim(), trigger: (byId('impulso-automation-trigger') || {}).value, webhook_url: (byId('impulso-automation-webhook') || {}).value.trim(), conditions: conditions, active: !!((byId('impulso-automation-active') || {}).checked) };
        if (!data.name || !data.webhook_url) { toast('Dados incompletos', 'Informe o nome e o webhook.', 'alert-circle'); return; }
        setBusy(button, true, 'Salvando'); api(id ? endpointWithId('automations', id) : endpoint('automations'), { method: id ? 'PUT' : 'POST', body: data }).then(function () { closeModal(button); toast('Automação salva', 'O gatilho foi registrado.', 'git-branch'); window.location.reload(); }).catch(function (error) { backendError(error, 'automações'); }).finally(function () { setBusy(button, false); });
    }
    function toggleResource(input, name, id) { var desired = input.checked; api(endpointWithId(name, id, '/toggle'), { method: 'POST', body: { active: desired } }).then(function () { toast('Estado atualizado', desired ? 'Recurso ativado.' : 'Recurso pausado.', desired ? 'play-circle' : 'pause-circle'); }).catch(function (error) { input.checked = !desired; backendError(error, name === 'aiAgents' ? 'agentes de IA' : 'automações'); }); }
    function testBackend(kind, button) { setBusy(button, true, 'Testando'); var url = kind === 'campaign' ? endpoint('campaigns').replace(/\/$/, '') + '/health' : endpoint('aiState').replace(/\/$/, '') + '/health'; api(url).then(function (payload) { var data = payloadData(payload, {}); toast('Conexão confirmada', data.message || 'O backend respondeu corretamente.', 'activity'); }).catch(function (error) { backendError(error, kind === 'campaign' ? 'saúde das campanhas' : 'saúde do n8n'); }).finally(function () { setBusy(button, false); }); }

    /* Reports */
    function refreshReports(button) {
        var period = (byId('impulso-report-period') || {}).value || '7d'; var instance = (byId('impulso-report-instance') || {}).value || 'all'; setBusy(button, true, 'Atualizando');
        api(endpoint('reports') + '?period=' + encodeURIComponent(period) + '&instance_id=' + encodeURIComponent(instance)).then(function (payload) { applyReportData(payloadData(payload, {})); toast('Relatório atualizado', 'Os indicadores foram recalculados.', 'bar-chart-2'); }).catch(function (error) { backendError(error, 'relatórios'); }).finally(function () { setBusy(button, false); });
    }
    function applyReportData(data) {
        Object.keys(data.summary || {}).forEach(function (key) { var el = document.querySelector('[data-report-stat="' + key + '"]'); if (el) el.textContent = data.summary[key]; });
        Object.keys(data.ai || {}).forEach(function (key) { var el = document.querySelector('[data-report-ai="' + key + '"]'); if (el) el.textContent = data.ai[key]; });
        Object.keys(data.funnel || {}).forEach(function (key) { var el = document.querySelector('[data-funnel="' + key + '"]'); if (el) el.textContent = data.funnel[key]; });
        if (Array.isArray(data.volume)) {
            var max = Math.max.apply(Math, [1].concat(data.volume.map(function (item) { return Number(item.value || item); })));
            var chart = byId('impulso-volume-chart'); if (chart) chart.innerHTML = data.volume.map(function (item) { var value = Number(item.value || item); return '<div class="impulso-chart-column"><div class="impulso-chart-bar" data-value="' + value + '" style="height:' + Math.round((value / max) * 100) + '%"><span>' + value + '</span></div><span class="impulso-chart-label">' + escapeHtml(item.label || '') + '</span></div>'; }).join('');
        }
        iconRefresh();
    }
    function exportReports(button) { var period = (byId('impulso-report-period') || {}).value || '7d'; var instance = (byId('impulso-report-instance') || {}).value || 'all'; var url = endpoint('reports').replace(/\/$/, '') + '/export?period=' + encodeURIComponent(period) + '&instance_id=' + encodeURIComponent(instance); window.location.href = url; }

    /* Global UI */
    function showContextMenu(anchor, items) {
        var menu = byId('impulso-context-menu'); if (!menu) return;
        menu.innerHTML = items.map(function (item, index) { return '<button type="button" data-context-index="' + index + '" class="' + (item.danger ? 'danger' : '') + '"><i data-feather="' + escapeHtml(item.icon || 'circle') + '"></i>' + escapeHtml(item.label) + '</button>'; }).join('');
        var rect = anchor.getBoundingClientRect(); menu.style.left = Math.max(8, Math.min(window.innerWidth - 210, rect.right - 190)) + 'px'; menu.style.top = Math.min(window.innerHeight - (items.length * 38 + 20), rect.bottom + 5) + 'px'; menu.classList.remove('impulso-hidden'); workspace.activeContext = items; iconRefresh();
    }
    function closeContextMenu() { var menu = byId('impulso-context-menu'); if (menu) menu.classList.add('impulso-hidden'); workspace.activeContext = null; }
    function toggleNotifications(show) { var drawer = byId('impulso-notification-drawer'); var backdrop = byId('impulso-drawer-backdrop'); if (!drawer || !backdrop) return; var open = show == null ? !drawer.classList.contains('open') : !!show; drawer.classList.toggle('open', open); backdrop.classList.toggle('open', open); drawer.setAttribute('aria-hidden', open ? 'false' : 'true'); if (open) loadNotifications(); }
    function loadNotifications() { var list = byId('impulso-notification-list'); if (!list) return; list.innerHTML = '<div class="impulso-empty compact"><span class="spinner-border spinner-border-sm"></span><p>Carregando...</p></div>'; api(endpoint('notifications') + '?limit=30').then(function (payload) { var rows = payloadData(payload, []); if (!Array.isArray(rows) || !rows.length) { list.innerHTML = '<div class="impulso-empty compact"><p>Nenhuma notificação.</p></div>'; return; } list.innerHTML = rows.map(function (item) { return '<button class="impulso-notification-item ' + (!item.read ? 'is-unread' : '') + '" type="button" data-notification-id="' + escapeHtml(item.id) + '" data-notification-kind="' + escapeHtml(item.kind || 'system') + '"><span class="impulso-stat-icon ' + escapeHtml(item.level || '') + '"><i data-feather="' + escapeHtml(item.icon || 'bell') + '"></i></span><span><strong>' + escapeHtml(item.title || 'Notificação') + '</strong><span>' + escapeHtml(item.message || '') + '</span><span>' + escapeHtml(item.time || '') + '</span></span></button>'; }).join(''); iconRefresh(); }).catch(function (error) { list.innerHTML = '<div class="impulso-empty compact"><p>' + escapeHtml(error.message) + '</p></div>'; }); }

    function testN8n(button) {
        setBusy(button, true, 'Testando');
        api(endpoint('n8nHealth'), { method: 'POST', body: {} }).then(function (payload) {
            var data = payloadData(payload, {});
            toast('n8n conectado', data.message || ('Resposta em ' + (data.latency_ms || 0) + ' ms.'), 'git-branch');
        }).catch(function (error) { backendError(error, 'teste do n8n'); }).finally(function () { setBusy(button, false); });
    }
    function manageQuickReplies() {
        all('[data-settings-tab]').forEach(function (button) { button.classList.toggle('active', button.getAttribute('data-settings-tab') === 'campaigns'); });
        all('[data-settings-panel]').forEach(function (panel) { panel.classList.toggle('active', panel.getAttribute('data-settings-panel') === 'campaigns'); });
        var editor = byId('impulso-setting-quick-replies');
        if (editor) { editor.scrollIntoView({ behavior: 'smooth', block: 'center' }); window.setTimeout(function () { editor.focus(); }, 280); }
    }
    function markAllNotificationsRead(button) {
        setBusy(button, true, 'Atualizando');
        api(endpoint('notificationsReadAll'), { method: 'POST', body: {} }).then(function () {
            all('.impulso-notification-item').forEach(function (item) { item.classList.remove('is-unread'); });
            var count = document.querySelector('.impulso-notification-count'); if (count) { count.textContent = '0'; count.classList.add('impulso-hidden'); }
            toast('Notificações atualizadas', 'Todas foram marcadas como lidas.', 'check-circle');
        }).catch(function (error) { backendError(error, 'notificações'); }).finally(function () { setBusy(button, false); });
    }

    function requestBrowserNotificationPermission() {
        if (!('Notification' in window) || window.Notification.permission !== 'default') return;
        window.Notification.requestPermission().catch(function () {});
    }
    function playNotificationSound() {
        try {
            var AudioContext = window.AudioContext || window.webkitAudioContext; if (!AudioContext) return;
            var context = new AudioContext(); var oscillator = context.createOscillator(); var gain = context.createGain();
            oscillator.frequency.value = 660; gain.gain.value = 0.035; oscillator.connect(gain); gain.connect(context.destination);
            oscillator.start(); oscillator.stop(context.currentTime + 0.12); oscillator.onended = function () { context.close(); };
        } catch (error) {}
    }
    function initializeNotificationPolling() {
        var preferences = config.preferences || {};
        if (!preferences.soundEnabled && !preferences.browserNotificationsEnabled) return;
        var seen = {}; var initialized = false;
        function pollNotifications() {
            if (document.hidden === false && initialized) return;
            api(endpoint('notifications') + '?limit=10&unread=1').then(function (payload) {
                var rows = payloadData(payload, []); if (!Array.isArray(rows)) return;
                rows.forEach(function (item) {
                    var id = text(item.id); var fresh = initialized && !seen[id]; seen[id] = true;
                    if (!fresh) return;
                    if (preferences.soundEnabled) playNotificationSound();
                    if (preferences.browserNotificationsEnabled && 'Notification' in window && window.Notification.permission === 'granted') {
                        var notice = new window.Notification(item.title || 'Impulso Hub', { body: item.message || '', tag: 'impulso-' + id, icon: '' });
                        notice.onclick = function () { window.focus(); toggleNotifications(true); notice.close(); };
                    }
                });
                initialized = true;
            }).catch(function () {});
        }
        pollNotifications();
        window.setInterval(pollNotifications, Math.max(5000, Number(config.pollingIntervalMs || 5000)));
    }
    function filterNotifications(filter) {
        all('[data-notification-filter]').forEach(function (button) { button.classList.toggle('active', button.getAttribute('data-notification-filter') === filter); });
        all('.impulso-notification-item').forEach(function (item) {
            var visible = filter === 'all' || (filter === 'unread' && item.classList.contains('is-unread')) || (filter === 'system' && item.getAttribute('data-notification-kind') === 'system');
            item.classList.toggle('impulso-hidden', !visible);
        });
    }
    function openGlobalSearch() { var results = byId('impulso-global-search-results'); if (results && !results.dataset.defaults) results.dataset.defaults = results.innerHTML; modal('impulso-global-search-modal'); window.setTimeout(function () { var input = byId('impulso-global-search-input'); if (input) { input.value = ''; input.focus(); filterGlobalCommands(''); } }, 150); }
    function filterGlobalCommands(query) {
        query = text(query).trim(); var results = byId('impulso-global-search-results'); if (!results) return;
        window.clearTimeout(workspace.globalSearchTimer);
        if (query.length < 2) { if (results.dataset.defaults) results.innerHTML = results.dataset.defaults; iconRefresh(); return; }
        results.innerHTML = '<div class="impulso-empty compact"><span class="spinner-border spinner-border-sm"></span><p>Buscando...</p></div>';
        workspace.globalSearchTimer = window.setTimeout(function () {
            api(endpoint('search') + '?q=' + encodeURIComponent(query) + '&limit=30').then(function (payload) {
                var data = payloadData(payload, {}); var rows = data.items || [];
                results.innerHTML = rows.length ? '<div class="impulso-command-section"><span>Resultados</span>' + rows.map(function (item) { return '<button type="button" data-search-tab="' + escapeHtml(item.tab || 'conversations') + '" data-search-id="' + escapeHtml(item.id) + '"><i data-feather="' + (item.type === 'contact' ? 'user' : item.type === 'campaign' ? 'send' : 'message-circle') + '"></i><div><strong>' + escapeHtml(item.title || '') + '</strong><small>' + escapeHtml(item.subtitle || '') + '</small></div></button>'; }).join('') + '</div>' : '<div class="impulso-empty compact"><p>Nenhum resultado encontrado.</p></div>';
                iconRefresh();
            }).catch(function (error) { results.innerHTML = '<div class="impulso-empty compact"><p>' + escapeHtml(error.message || 'Falha na busca.') + '</p></div>'; });
        }, 250);
    }
    function showDataInPalette(title, rows) { var results = byId('impulso-global-search-results'); if (!results) return; var input = byId('impulso-global-search-input'); if (input) input.value = title; results.innerHTML = rows.length ? '<div class="impulso-command-section"><span>' + escapeHtml(title) + '</span>' + rows.join('') + '</div>' : '<div class="impulso-empty compact"><p>Nenhum registro encontrado.</p></div>'; modal('impulso-global-search-modal'); iconRefresh(); }
    function openCampaignTemplates() { api(endpoint('campaignTemplates')).then(function (payload) { var rows = payloadData(payload, []); showDataInPalette('Templates de campanha', rows.map(function (item) { return '<button type="button" data-campaign-template-message="' + escapeHtml(item.message || '') + '"><i data-feather="file-text"></i><div><strong>' + escapeHtml(item.name || 'Template') + '</strong><small>' + escapeHtml(item.message || '') + '</small></div></button>'; })); }).catch(function (error) { backendError(error, 'templates'); }); }
    function openAiLogs() { api(endpoint('aiLogs') + '?limit=30').then(function (payload) { var rows = payloadData(payload, []); showDataInPalette('Logs da IA', rows.map(function (item) { return '<button type="button"><i data-feather="' + (item.status === 'error' ? 'alert-triangle' : 'activity') + '"></i><div><strong>' + escapeHtml(item.event_name || 'Evento') + '</strong><small>' + escapeHtml((item.status || '') + ' · ' + (item.correlation_id || '')) + '</small></div></button>'; })); }).catch(function (error) { backendError(error, 'logs da IA'); }); }
    function showReportDetail(kind) { var period = (byId('impulso-report-period') || {}).value || '7d'; var instance = (byId('impulso-report-instance') || {}).value || 'all'; api(endpoint('reports') + '?period=' + encodeURIComponent(period) + '&instance_id=' + encodeURIComponent(instance)).then(function (payload) { var data = payloadData(payload, {}); var source = kind === 'funnel' ? Object.keys(data.funnel || {}).map(function (key) { return { name: key, value: data.funnel[key] }; }) : (data.channels || []); showDataInPalette(kind === 'funnel' ? 'Funil operacional' : 'Distribuição por instância', source.map(function (item) { return '<button type="button"><i data-feather="bar-chart-2"></i><div><strong>' + escapeHtml(item.name || item.label || '') + '</strong><small>' + escapeHtml(item.value != null ? item.value : item.count || 0) + '</small></div></button>'; })); }).catch(function (error) { backendError(error, 'detalhamento do relatório'); }); }

    function handleAction(action, trigger, event) {
        if (!action) return false;
        if (action === 'global-search') { openGlobalSearch(); return true; }
        if (action === 'notifications') { toggleNotifications(); return true; }
        if (action === 'close-notifications') { toggleNotifications(false); return true; }
        if (action === 'mark-all-notifications-read') { markAllNotificationsRead(trigger); return true; }
        if (action === 'test-n8n') { testN8n(trigger); return true; }
        if (action === 'manage-quick-replies') { manageQuickReplies(); return true; }
        if (action === 'new-conversation') { openNewConversation(); return true; }
        if (action === 'new-contact') { openNewContact(); return true; }
        if (action === 'new-campaign') { openCampaign(); return true; }
        if (action === 'new-agent') { openAgent(); return true; }
        if (action === 'new-automation') { openAutomation(); return true; }
        if (action === 'emoji') { toggleEmojiPicker(); return true; }
        if (action === 'quick-replies') { toggleQuickReplies(); return true; }
        if (action === 'attach') { var file = byId('impulso-attachment-input'); if (file) file.click(); return true; }
        if (action === 'remove-attachment') { clearAttachment(); return true; }
        if (action === 'voice') { startVoiceRecording(); return true; }
        if (action === 'search-history') { var panel = byId('impulso-history-search-panel'); if (panel) { panel.classList.remove('impulso-hidden'); var input = byId('impulso-history-search-input'); if (input) input.focus(); } return true; }
        if (action === 'close-history-search') { var history = byId('impulso-history-search-panel'); if (history) history.classList.add('impulso-hidden'); searchHistory(''); return true; }
        if (action === 'call-contact') { callContact(); return true; }
        if (action === 'toggle-priority') { togglePriority(trigger); return true; }
        if (action === 'resolve-conversation') { resolveConversation(trigger); return true; }
        if (action === 'edit-contact') { var conversation = activeConversation(); if (conversation && conversation.contact_id) api(endpointWithId('contacts', conversation.contact_id)).then(function (payload) { openNewContact(payloadData(payload, {})); }).catch(function (error) { backendError(error, 'contato'); }); else if (conversation) openNewContact(conversation); return true; }
        if (action === 'edit-tags') { editTags(); return true; }
        if (action === 'edit-assignment') { editAssignment(); return true; }
        if (action === 'contact-menu') { contactMenu(trigger); return true; }
        if (action === 'toggle-ai-conversation') { aiConversationMenu(trigger); return true; }
        if (action === 'refresh-contacts') { refreshContacts(); return true; }
        if (action === 'view-contact') { api(endpointWithId('contacts', trigger.getAttribute('data-contact-id'))).then(function (payload) { openNewContact(payloadData(payload, {})); }).catch(function (error) { backendError(error, 'contato'); }); return true; }
        if (action === 'contact-row-menu') { contactRowMenu(trigger); return true; }
        if (action === 'clear-contact-selection') { all('.impulso-contact-select').forEach(function (input) { input.checked = false; }); var selectAll = byId('impulso-contact-select-all'); if (selectAll) selectAll.checked = false; updateContactBulk(); return true; }
        if (action === 'bulk-export-contacts') { exportSelectedContacts(); return true; }
        if (action === 'bulk-tag-contacts') { bulkTagContacts(); return true; }
        if (action === 'load-more-contacts') { loadMoreContacts(trigger); return true; }
        if (action === 'new-campaign' || action === 'campaign-next') { if (action === 'campaign-next' && validateCampaignStep()) campaignStep(workspace.campaignStep + 1); return true; }
        if (action === 'campaign-previous') { campaignStep(workspace.campaignStep - 1); return true; }
        if (action === 'preview-campaign-audience') { calculateAudience(trigger); return true; }
        if (action === 'campaign-variable') { insertAtCursor(byId('impulso-campaign-message'), trigger.getAttribute('data-variable') || ''); updateCampaignPreview(); return true; }
        if (action === 'campaign-emoji') { workspace.emojiTarget = 'campaign'; toggleEmojiPicker(); return true; }
        if (action === 'campaign-attachment') { var campaignFile = byId('impulso-campaign-file'); if (campaignFile) campaignFile.click(); return true; }
        if (action === 'campaign-menu') { campaignMenu(trigger); return true; }
        if (action === 'view-campaign') { api(endpointWithId('campaigns', trigger.getAttribute('data-campaign-id'))).then(function (payload) { openCampaign(payloadData(payload, {})); }).catch(function (error) { backendError(error, 'campanhas'); }); return true; }
        if (action === 'refresh-campaigns') { window.location.reload(); return true; }
        if (action === 'test-campaign-backend') { testBackend('campaign', trigger); return true; }
        if (action === 'campaign-templates') { openCampaignTemplates(); return true; }
        if (action === 'campaign-calendar') { var statusFilter = byId('impulso-campaign-status-filter'); if (statusFilter) { statusFilter.value = 'scheduled'; applyCampaignFilters(); statusFilter.scrollIntoView({ behavior: 'smooth', block: 'center' }); } return true; }
        if (action === 'edit-agent') { api(endpointWithId('aiAgents', trigger.getAttribute('data-agent-id'))).then(function (payload) { openAgent(payloadData(payload, {})); }).catch(function (error) { backendError(error, 'agentes de IA'); }); return true; }
        if (action === 'refresh-ai' || action === 'refresh-ai-state' || action === 'refresh-automations') { window.location.reload(); return true; }
        if (action === 'toggle-ai-instance') { aiInstanceMenu(trigger); return true; }
        if (action === 'automation-menu') { automationMenu(trigger); return true; }
        if (action === 'test-ai-backend') { testBackend('ai', trigger); return true; }
        if (action === 'knowledge-base') { goToTab('settings'); return true; }
        if (action === 'open-ai-logs') { openAiLogs(); return true; }
        if (action === 'refresh-reports') { refreshReports(trigger); return true; }
        if (action === 'export-reports') { exportReports(trigger); return true; }
        if (action === 'report-channel-detail') { showReportDetail('channels'); return true; }
        if (action === 'configure-report-funnel') { showReportDetail('funnel'); return true; }
        if (action === 'import-contacts') { importContacts(); return true; }
        return false;
    }

    function editTags() {
        var conversation = activeConversation(); if (!conversation) return;
        var value = window.prompt('Etiquetas separadas por vírgula:', (conversation.tags || []).join(', ')); if (value == null) return;
        var tags = value.split(',').map(function (item) { return item.trim(); }).filter(Boolean);
        conversationAction('tags', { tags: tags }).then(function () { conversation.tags = tags; toast('Etiquetas atualizadas', 'A conversa foi organizada.', 'tag'); if (bridge.loadConversations) bridge.loadConversations(true); }).catch(function (error) { backendError(error, 'etiquetas'); });
    }
    function editAssignment() {
        var conversation = activeConversation(); if (!conversation) return;
        showContextMenu(byId('impulso-assignee-select') || byId('impulso-contact-sidebar'), [
            { label: 'Não atribuído', icon: 'user-x', action: function () { conversationAction('assignment', { assignee_id: null }).then(function () { toast('Atribuição removida', 'A conversa voltou para a fila.', 'user-x'); }).catch(function (error) { backendError(error, 'atribuição'); }); } },
            { label: 'Atribuir a mim', icon: 'user-check', action: function () { conversationAction('assignment', { assign_to_me: true }).then(function () { toast('Conversa atribuída', 'Você assumiu este atendimento.', 'user-check'); }).catch(function (error) { backendError(error, 'atribuição'); }); } }
        ]);
    }
    function contactMenu(trigger) { showContextMenu(trigger, [
        { label: 'Editar contato', icon: 'edit-3', action: function () { var c = activeConversation(); if (c) openNewContact(c); } },
        { label: 'Copiar telefone', icon: 'copy', action: function () { var c = activeConversation(); if (c && navigator.clipboard) navigator.clipboard.writeText(c.phone || ''); } },
        { label: 'Bloquear para campanhas', icon: 'shield-off', action: function () { var c = activeConversation(); if (c) api(endpointWithId('contacts', c.contact_id || c.id, '/opt-out'), { method: 'POST', body: { opt_out: true } }).then(function () { toast('Opt-out aplicado', 'O contato foi bloqueado para campanhas.', 'shield-off'); }).catch(function (error) { backendError(error, 'opt-out'); }); } }
    ]); }
    function aiConversationMenu(trigger) { var conversation = activeConversation(); if (!conversation) return; showContextMenu(trigger, [
        { label: 'Ativar IA', icon: 'play-circle', action: function () { setAiConversationState(conversation.id, 'running'); } },
        { label: 'Pausar IA', icon: 'pause-circle', action: function () { setAiConversationState(conversation.id, 'paused'); } },
        { label: 'Assumir atendimento humano', icon: 'user-check', action: function () { setAiConversationState(conversation.id, 'human'); } }
    ]); }
    function setAiConversationState(id, status) { api(endpointWithId('aiState', id), { method: 'POST', body: { status: status } }).then(function () { toast('Estado da IA atualizado', 'Novo estado: ' + status + '.', 'cpu'); refreshAiConversationState(); }).catch(function (error) { backendError(error, 'estado da IA'); }); }
    function aiInstanceMenu(trigger) { var id = trigger.getAttribute('data-instance-id'); showContextMenu(trigger, [
        { label: 'Ativar IA na instância', icon: 'play-circle', action: function () { api(endpointWithId('aiState', id, '/instance'), { method: 'POST', body: { status: 'running' } }).then(function () { window.location.reload(); }).catch(function (error) { backendError(error, 'estado da IA'); }); } },
        { label: 'Pausar IA na instância', icon: 'pause-circle', action: function () { api(endpointWithId('aiState', id, '/instance'), { method: 'POST', body: { status: 'paused' } }).then(function () { window.location.reload(); }).catch(function (error) { backendError(error, 'estado da IA'); }); } }
    ]); }
    function contactRowMenu(trigger) { var contact = contactFromRow(trigger.getAttribute('data-contact-id')); showContextMenu(trigger, [
        { label: 'Editar contato', icon: 'edit-3', action: function () { api(endpointWithId('contacts', contact.id)).then(function (payload) { openNewContact(payloadData(payload, {})); }).catch(function (error) { backendError(error, 'contato'); }); } },
        { label: 'Abrir conversa', icon: 'message-circle', action: function () { openNewConversation(contact); } },
        { label: 'Copiar telefone', icon: 'copy', action: function () { if (navigator.clipboard) navigator.clipboard.writeText(contact.phone || ''); } }
    ]); }
    function automationMenu(trigger) { var id = trigger.getAttribute('data-automation-id'); showContextMenu(trigger, [
        { label: 'Editar', icon: 'edit-3', action: function () { api(endpointWithId('automations', id)).then(function (payload) { openAutomation(payloadData(payload, {})); }).catch(function (error) { backendError(error, 'automações'); }); } },
        { label: 'Executar teste', icon: 'play', action: function () { api(endpointWithId('automations', id, '/test'), { method: 'POST', body: {} }).then(function () { toast('Teste disparado', 'A execução foi encaminhada ao n8n.', 'play'); }).catch(function (error) { backendError(error, 'teste da automação'); }); } },
        { label: 'Excluir', icon: 'trash-2', danger: true, action: function () { if (!window.confirm('Excluir esta automação?')) return; api(endpointWithId('automations', id), { method: 'DELETE', body: {} }).then(function () { window.location.reload(); }).catch(function (error) { backendError(error, 'exclusão'); }); } }
    ]); }
    function exportSelectedContacts() { var ids = all('.impulso-contact-select:checked').map(function (input) { return input.value; }); if (!ids.length) return; window.location.href = endpoint('contacts').replace(/\/$/, '') + '/export?ids=' + encodeURIComponent(ids.join(',')); }
    function bulkTagContacts() { var ids = all('.impulso-contact-select:checked').map(function (input) { return Number(input.value); }); var value = window.prompt('Etiquetas separadas por vírgula:'); if (!ids.length || value == null) return; api(endpoint('contacts').replace(/\/$/, '') + '/bulk-tags', { method: 'POST', body: { ids: ids, tags: value.split(',').map(function (v) { return v.trim(); }).filter(Boolean) } }).then(function () { window.location.reload(); }).catch(function (error) { backendError(error, 'etiquetas em massa'); }); }
    function contactRowHtml(contact) {
        var name = text(contact.name || contact.phone || 'Contato'); var initials = name.split(/\s+/).slice(0, 2).map(function (part) { return part.charAt(0); }).join('').toUpperCase();
        var status = contact.opt_out ? 'opt_out' : (name === text(contact.phone) ? 'unidentified' : 'identified'); var tags = Array.isArray(contact.tags) ? contact.tags : [];
        return '<tr data-contact-id="' + escapeHtml(contact.id) + '" data-contact-instance="' + escapeHtml(contact.instance_id || 0) + '" data-contact-status="' + status + '" data-contact-search="' + escapeHtml((name + ' ' + (contact.phone || '') + ' ' + (contact.email || '') + ' ' + (contact.company || '')).toLowerCase()) + '"><td><input type="checkbox" class="impulso-contact-select" value="' + escapeHtml(contact.id) + '"></td><td><button class="impulso-person-button" type="button" data-impulso-action="view-contact" data-contact-id="' + escapeHtml(contact.id) + '"><span class="impulso-avatar sm">' + escapeHtml(initials || 'C') + '</span><span class="impulso-person-copy"><strong>' + escapeHtml(name) + '</strong><span>' + escapeHtml(contact.email || 'Sem e-mail') + '</span></span></button></td><td>' + escapeHtml(contact.phone || '') + '</td><td>' + escapeHtml(contact.instance || '—') + '</td><td><div class="impulso-tag-list">' + (tags.length ? tags.map(function (tag) { return '<span class="impulso-badge primary">' + escapeHtml(tag) + '</span>'; }).join('') : '<span class="impulso-text-muted">Sem etiquetas</span>') + '</div></td><td><span class="impulso-count-badge">' + Number(contact.conversation_count || contact.conversations || 0) + '</span></td><td>' + escapeHtml(contact.last_activity_at || '—') + '</td><td><button class="impulso-icon-button btn btn-default" type="button" data-impulso-action="contact-row-menu" data-contact-id="' + escapeHtml(contact.id) + '"><i data-feather="more-horizontal"></i></button></td></tr>';
    }
    function loadMoreContacts(button) {
        if (!workspace.contactHasMore) return;
        loadContactsPage(workspace.contactPage + 1, true, button);
    }
    function importContacts() { var input = document.createElement('input'); input.type = 'file'; input.accept = '.csv,text/csv'; input.addEventListener('change', function () { var file = input.files && input.files[0]; if (!file) return; var form = new FormData(); form.append('file', file); api(endpoint('contacts').replace(/\/$/, '') + '/import', { method: 'POST', body: form }).then(function (payload) { var data = payloadData(payload, {}); toast('Importação concluída', Number(data.inserted || 0) + ' inseridos, ' + Number(data.updated || 0) + ' atualizados e ' + Number(data.ignored || 0) + ' ignorados.', 'upload'); window.setTimeout(function () { window.location.reload(); }, 650); }).catch(function (error) { backendError(error, 'importação'); }); }); input.click(); }

    function submitForm(kind, button) {
        if (kind === 'conversation') {
            var data = { phone: (byId('impulso-new-conversation-contact') || {}).value.replace(/\D/g, ''), name: (byId('impulso-new-conversation-name') || {}).value.trim(), instance_id: Number((byId('impulso-new-conversation-instance') || {}).value || 0), message: (byId('impulso-new-conversation-message') || {}).value.trim() };
            if (!data.phone || !data.instance_id || !data.message) { toast('Dados incompletos', 'Informe telefone, instância e mensagem.', 'alert-circle'); return; }
            setBusy(button, true, 'Iniciando'); api(endpoint('conversations'), { method: 'POST', body: data }).then(function (payload) { closeModal(button); toast('Conversa iniciada', 'A mensagem inicial foi enviada.', 'message-circle'); goToTab('conversations'); }).catch(function (error) { backendError(error, 'nova conversa'); }).finally(function () { setBusy(button, false); });
            return;
        }
        if (kind === 'contact') {
            var id = Number((byId('impulso-contact-id') || {}).value || 0); var dataContact = { name: (byId('impulso-contact-form-name') || {}).value.trim(), phone: (byId('impulso-contact-form-phone') || {}).value.replace(/\D/g, ''), email: (byId('impulso-contact-form-email') || {}).value.trim(), company: (byId('impulso-contact-form-company') || {}).value.trim(), city: (byId('impulso-contact-form-city') || {}).value.trim(), source: (byId('impulso-contact-form-source') || {}).value, instance_id: Number((byId('impulso-contact-form-instance') || {}).value || 0) || null, tags: (byId('impulso-contact-form-tags') || {}).value.split(',').map(function (v) { return v.trim(); }).filter(Boolean), notes: (byId('impulso-contact-form-notes') || {}).value.trim(), opt_out: !!((byId('impulso-contact-form-opt-out') || {}).checked) };
            if (!dataContact.name || !dataContact.phone) { toast('Dados incompletos', 'Nome e telefone são obrigatórios.', 'alert-circle'); return; }
            setBusy(button, true, 'Salvando'); api(id ? endpointWithId('contacts', id) : endpoint('contacts'), { method: id ? 'PUT' : 'POST', body: dataContact }).then(function () { closeModal(button); toast('Contato salvo', 'Os dados foram atualizados.', 'user-check'); window.setTimeout(function () { window.location.reload(); }, 450); }).catch(function (error) { backendError(error, 'contatos'); }).finally(function () { setBusy(button, false); });
            return;
        }
        if (kind === 'campaign') { saveCampaign(button); return; }
        if (kind === 'agent') { saveAgent(button); return; }
        if (kind === 'automation') { saveAutomation(button); }
    }

    /* Event binding: capture prevents the legacy fallback from swallowing refined actions. */
    app.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-impulso-action], [data-impulso-modal-submit], [data-emoji], [data-quick-reply], [data-media-url], [data-context-index], [data-command-action], [data-notification-filter], [data-notification-id], [data-search-tab], [data-campaign-template-message]');
        if (!trigger) return;
        if (trigger.hasAttribute('data-emoji')) { event.preventDefault(); event.stopImmediatePropagation(); insertAtCursor(byId(workspace.emojiTarget === 'campaign' ? 'impulso-campaign-message' : 'impulso-message-input'), trigger.getAttribute('data-emoji')); workspace.emojiTarget = 'composer'; byId('impulso-emoji-picker').classList.add('impulso-hidden'); updateCampaignPreview(); return; }
        if (trigger.hasAttribute('data-quick-reply')) { event.preventDefault(); event.stopImmediatePropagation(); insertAtCursor(byId('impulso-message-input'), trigger.getAttribute('data-quick-reply')); byId('impulso-quick-replies').classList.add('impulso-hidden'); return; }
        if (trigger.hasAttribute('data-media-url')) { event.preventDefault(); event.stopImmediatePropagation(); openMedia(trigger.getAttribute('data-media-kind'), trigger.getAttribute('data-media-url'), trigger.getAttribute('data-media-title')); return; }
        if (trigger.hasAttribute('data-context-index')) { event.preventDefault(); event.stopImmediatePropagation(); var item = workspace.activeContext && workspace.activeContext[Number(trigger.getAttribute('data-context-index'))]; closeContextMenu(); if (item && item.action) item.action(); return; }
        if (trigger.hasAttribute('data-command-action')) { event.preventDefault(); event.stopImmediatePropagation(); closeModal(trigger); var command = trigger.getAttribute('data-command-action'); if (command === 'new-conversation') openNewConversation(); if (command === 'new-contact') openNewContact(); if (command === 'new-campaign') openCampaign(); return; }
        if (trigger.hasAttribute('data-search-tab')) { event.preventDefault(); goToTab(trigger.getAttribute('data-search-tab') || 'conversations'); return; }
        if (trigger.hasAttribute('data-campaign-template-message')) { event.preventDefault(); var templateMessage = trigger.getAttribute('data-campaign-template-message') || ''; closeModal(trigger); openCampaign(); window.setTimeout(function () { var message = byId('impulso-campaign-message'); if (message) { message.value = templateMessage; updateCampaignPreview(); } }, 100); return; }
        if (trigger.hasAttribute('data-notification-filter')) { event.preventDefault(); event.stopImmediatePropagation(); filterNotifications(trigger.getAttribute('data-notification-filter') || 'all'); return; }
        if (trigger.hasAttribute('data-notification-id')) { event.preventDefault(); event.stopImmediatePropagation(); var notificationId = trigger.getAttribute('data-notification-id'); api(endpointWithId('notifications', notificationId, '/read'), { method: 'POST', body: {} }).catch(function () {}); trigger.classList.remove('is-unread'); return; }
        var submit = trigger.getAttribute('data-impulso-modal-submit'); if (submit && submit !== 'instance') { event.preventDefault(); event.stopImmediatePropagation(); submitForm(submit, trigger); return; }
        var action = trigger.getAttribute('data-impulso-action');
        if (['emoji','quick-replies','attach','voice','search-history','close-history-search','call-contact','toggle-priority','resolve-conversation','edit-contact','edit-tags','edit-assignment','contact-menu','toggle-ai-conversation'].indexOf(action) >= 0 || ['global-search','notifications','close-notifications','new-conversation','new-contact','new-campaign','new-agent','new-automation','refresh-contacts','view-contact','contact-row-menu','clear-contact-selection','bulk-export-contacts','bulk-tag-contacts','load-more-contacts','campaign-next','campaign-previous','preview-campaign-audience','campaign-variable','campaign-emoji','campaign-attachment','campaign-menu','view-campaign','refresh-campaigns','test-campaign-backend','campaign-templates','campaign-calendar','edit-agent','refresh-ai','refresh-ai-state','refresh-automations','toggle-ai-instance','automation-menu','test-ai-backend','knowledge-base','open-ai-logs','refresh-reports','export-reports','report-channel-detail','configure-report-funnel','import-contacts','remove-attachment','mark-all-notifications-read','test-n8n','manage-quick-replies'].indexOf(action) >= 0) {
            event.preventDefault(); event.stopImmediatePropagation(); handleAction(action, trigger, event);
        }
    }, true);

    var sendButton = byId('impulso-send-message'); if (sendButton) sendButton.addEventListener('click', composerSubmit, true);
    var messageInput = byId('impulso-message-input'); if (messageInput) messageInput.addEventListener('keydown', function (event) { if (event.key === 'Enter' && !event.shiftKey) composerSubmit(event); }, true);
    all('[data-composer-mode]').forEach(function (button) { button.addEventListener('click', function (event) { event.preventDefault(); event.stopImmediatePropagation(); setComposerMode(button.getAttribute('data-composer-mode')); }, true); });
    var attachmentInput = byId('impulso-attachment-input'); if (attachmentInput) attachmentInput.addEventListener('change', function () { setAttachment(this.files && this.files[0]); });
    var historyInput = byId('impulso-history-search-input'); if (historyInput) historyInput.addEventListener('input', function () { searchHistory(this.value); });
    var campaignMessage = byId('impulso-campaign-message'); if (campaignMessage) campaignMessage.addEventListener('input', updateCampaignPreview);
    var campaignName = byId('impulso-campaign-name'); if (campaignName) campaignName.addEventListener('input', updateCampaignPreview);
    var campaignFileInput = byId('impulso-campaign-file'); if (campaignFileInput) campaignFileInput.addEventListener('change', function () { uploadCampaignMedia(this.files && this.files[0]); });
    var campaignAudienceSource = byId('impulso-campaign-audience-source'); if (campaignAudienceSource) campaignAudienceSource.addEventListener('change', function () { if (this.value === 'csv') importCampaignAudienceCsv(); });
    all('[data-campaign-step]').forEach(function (button) { button.addEventListener('click', function () { var target = Number(button.getAttribute('data-campaign-step')); if (target <= workspace.campaignStep || validateCampaignStep()) campaignStep(target); }); });
    var contactSearch = byId('impulso-contact-search'); if (contactSearch) contactSearch.addEventListener('input', applyContactFilters);
    var contactInstance = byId('impulso-contact-instance-filter'); if (contactInstance) contactInstance.addEventListener('change', applyContactFilters);
    var contactStatus = byId('impulso-contact-status-filter'); if (contactStatus) contactStatus.addEventListener('change', applyContactFilters);
    var contactSelectAll = byId('impulso-contact-select-all'); if (contactSelectAll) contactSelectAll.addEventListener('change', function () { all('.impulso-contact-select').forEach(function (input) { if (!input.closest('tr').classList.contains('impulso-hidden')) input.checked = contactSelectAll.checked; }); updateContactBulk(); });
    all('.impulso-contact-select').forEach(function (input) { input.addEventListener('change', updateContactBulk); });
    var campaignSearch = byId('impulso-campaign-search'); if (campaignSearch) campaignSearch.addEventListener('input', applyCampaignFilters);
    var campaignStatus = byId('impulso-campaign-status-filter'); if (campaignStatus) campaignStatus.addEventListener('change', applyCampaignFilters);
    var campaignInstance = byId('impulso-campaign-instance-filter'); if (campaignInstance) campaignInstance.addEventListener('change', applyCampaignFilters);
    var automationFilter = byId('impulso-automation-status-filter'); if (automationFilter) automationFilter.addEventListener('change', function () { var value = this.value; all('#impulso-automations-table tbody tr[data-automation-id]').forEach(function (row) { row.classList.toggle('impulso-hidden', value !== 'all' && row.getAttribute('data-automation-status') !== value); }); });
    all('[data-impulso-action="toggle-agent"]').forEach(function (input) { input.addEventListener('change', function (event) { event.stopPropagation(); toggleResource(input, 'aiAgents', input.getAttribute('data-agent-id')); }); });
    all('[data-impulso-action="toggle-automation"]').forEach(function (input) { input.addEventListener('change', function (event) { event.stopPropagation(); toggleResource(input, 'automations', input.getAttribute('data-automation-id')); }); });
    var globalSearch = byId('impulso-global-search-input'); if (globalSearch) globalSearch.addEventListener('input', function () { filterGlobalCommands(this.value); });
    var newMessage = byId('impulso-new-conversation-message'); if (newMessage) newMessage.addEventListener('input', function () { var count = byId('impulso-new-conversation-char-count'); if (count) count.textContent = this.value.length; });
    var reportPeriod = byId('impulso-report-period'); if (reportPeriod) reportPeriod.addEventListener('change', function () { refreshReports(document.querySelector('[data-impulso-action="refresh-reports"]')); });
    var reportInstance = byId('impulso-report-instance'); if (reportInstance) reportInstance.addEventListener('change', function () { refreshReports(document.querySelector('[data-impulso-action="refresh-reports"]')); });
    var browserNotificationSetting = byId('impulso-setting-browser-notifications'); if (browserNotificationSetting) browserNotificationSetting.addEventListener('change', function () { if (this.checked) requestBrowserNotificationPermission(); });

    document.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); openGlobalSearch(); }
        if (event.key === 'Escape') { closeContextMenu(); toggleNotifications(false); var picker = byId('impulso-emoji-picker'); var replies = byId('impulso-quick-replies'); if (picker) picker.classList.add('impulso-hidden'); if (replies) replies.classList.add('impulso-hidden'); }
    });
    document.addEventListener('click', function (event) {
        if (!event.target.closest('#impulso-context-menu') && !event.target.closest('[data-impulso-action$="menu"]')) closeContextMenu();
        if (!event.target.closest('#impulso-emoji-picker') && !event.target.closest('[data-impulso-action="emoji"]')) { var picker = byId('impulso-emoji-picker'); if (picker) picker.classList.add('impulso-hidden'); }
        if (!event.target.closest('#impulso-quick-replies') && !event.target.closest('[data-impulso-action="quick-replies"]')) { var replies = byId('impulso-quick-replies'); if (replies) replies.classList.add('impulso-hidden'); }
    });
    var backdrop = byId('impulso-drawer-backdrop'); if (backdrop) backdrop.addEventListener('click', function () { toggleNotifications(false); });

    if (app.getAttribute('data-active-tab') === 'conversations') {
        var observer = new MutationObserver(function () { refreshAiConversationState(); });
        var nameElement = byId('impulso-active-name'); if (nameElement) observer.observe(nameElement, { childList: true, characterData: true, subtree: true });
        window.setTimeout(refreshAiConversationState, 600);
    }

    setComposerMode('reply');
    applyContactFilters();
    applyCampaignFilters();
    initializeNotificationPolling();
    iconRefresh();
})(window, document);
