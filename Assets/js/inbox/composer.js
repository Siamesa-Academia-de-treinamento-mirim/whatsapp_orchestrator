(function (window, document) {
    'use strict';

    var app = document.getElementById('impulso-hub-app');
    var bridge = window.ImpulsoHubBridge;
    var State = window.ImpulsoComposerState;
    var Quick = window.ImpulsoComposerQuickReplies;
    var Clipboard = window.ImpulsoComposerClipboard;
    var media = window.ImpulsoHubMedia;
    if (!app || !bridge || !State || !Quick) return;

    var config = bridge.getConfig ? bridge.getConfig() : {};
    var store = State.createStore({
        scope: 'whatsapp-orchestrator',
        actorId: config.actorId || 0,
        storage: (function () { try { return window.localStorage; } catch (error) { return null; } }())
    });
    var state = bridge.getState ? bridge.getState() : {};
    var currentConversationId = Number(state.activeConversationId || 0);
    var mode = 'reply';
    var pendingSendsByClientId = {};
    var quickSourceRows = [];
    var quickVisibleRows = [];
    var quickQuery = '';
    var quickIndex = -1;
    var sendIdentities = {};

    function byId(id) { return document.getElementById(id); }
    function text(value) { return String(value == null ? '' : value); }
    function timingNow() { return window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now(); }
    function activeConversation() { return bridge.getActiveConversation ? bridge.getActiveConversation() : null; }
    function input() { return byId('impulso-message-input'); }
    function composer() { return document.querySelector('.impulso-composer'); }
    function panel(id) { return byId(id); }
    function setPopoverState(id, open) {
        var popover = panel(id);
        var action = id === 'impulso-emoji-picker' ? 'emoji' : 'quick-replies';
        if (popover) {
            popover.classList.toggle('impulso-hidden', !open);
            popover.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        document.querySelectorAll('[data-impulso-action="' + action + '"]').forEach(function (trigger) {
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }
    function closePopovers() {
        setPopoverState('impulso-emoji-picker', false);
        setPopoverState('impulso-quick-replies', false);
        quickIndex = -1;
    }
    function escapeHtml(value) { return text(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }
    function endpoint(name) { return bridge.endpoint ? bridge.endpoint(name) : ''; }
    function endpointWithId(name, id, suffix) { return bridge.endpointWithId ? bridge.endpointWithId(name, id, suffix || '') : ''; }
    function toast(title, message, icon) { if (bridge.toast) bridge.toast(title, message, icon || 'alert-circle'); }
    function capabilities() { var conversation = activeConversation() || {}; return conversation.capabilities || {}; }
    function hasReplyCapability() { return !!(capabilities().actions && capabilities().actions.reply === true); }
    function canNote() { return !config.permissions || config.permissions.manageConversations === true; }
    function canSend() { return !config.permissions || config.permissions.send === true; }
    function hasAction(name) { return !!(capabilities().actions && capabilities().actions[name] === true); }
    function conversationConnected() { var conversation = activeConversation(); return !!conversation && conversation.instance_status === 'connected'; }
    function currentRecord() { return store.get(currentConversationId, mode); }
    function getMediaAttachments(conversationId) {
        conversationId = Number(conversationId || currentConversationId || 0);
        if (!media) return [];
        if (media.getAttachmentsForConversation) return media.getAttachmentsForConversation(conversationId);
        return media.getAttachments ? media.getAttachments() : [];
    }
    function canReplyText() { return !!activeConversation() && canSend() && conversationConnected() && hasAction('send_text'); }
    function canSendMedia() { return !!activeConversation() && canSend() && conversationConnected() && hasAction('send_media'); }
    function mediaPolicy(kind) { var policies = capabilities().media || {}; return policies[kind] || null; }
    function canUseVoice() { var policy = mediaPolicy('audio'); return canSendMedia() && !!policy && policy.enabled === true && policy.voice_note === true; }
    function flushConversationDrafts(conversationId) {
        conversationId = Number(conversationId || 0);
        if (!conversationId) return;
        store.flushAutosave(conversationId, 'reply');
        store.flushAutosave(conversationId, 'note');
    }
    function sendIdentity(snapshot, prefix, identitySuffix) {
        var key = [snapshot.conversationId, snapshot.mode, snapshot.revision, prefix, identitySuffix || ''].join(':');
        if (!sendIdentities[key]) sendIdentities[key] = prefix + '-' + Date.now() + '-' + Math.random().toString(16).slice(2);
        return sendIdentities[key];
    }

    function mentionIds(conversationId) {
        return window.ImpulsoMentions && typeof window.ImpulsoMentions.getMentionIds === 'function'
            ? window.ImpulsoMentions.getMentionIds(conversationId)
            : [];
    }

    function noteIdentity(snapshot, mentions) {
        var identity = window.ImpulsoCollaborationContract && typeof window.ImpulsoCollaborationContract.mentionIdentity === 'function'
            ? window.ImpulsoCollaborationContract.mentionIdentity(snapshot.text, snapshot.revision, mentions)
            : JSON.stringify({ content: snapshot.text, revision: snapshot.revision, mention_user_ids: mentions });
        return sendIdentity(snapshot, 'note', identity);
    }

    function updateDraft() {
        var field = input();
        if (!field || !currentConversationId) return;
        store.setText(currentConversationId, mode, field.value);
        store.scheduleAutosave(currentConversationId, mode);
    }

    function renderReplyStrip() {
        var box = composer();
        if (!box) return;
        var strip = byId('impulso-reply-strip');
        var target = store.get(currentConversationId, 'reply').replyTarget;
        if (!strip) {
            strip = document.createElement('div');
            strip.id = 'impulso-reply-strip';
        strip.className = 'impulso-reply-strip impulso-hidden';
            strip.setAttribute('role', 'status');
            strip.setAttribute('aria-live', 'polite');
            var modeNode = box.querySelector('.impulso-composer-mode');
            box.insertBefore(strip, modeNode ? modeNode.nextSibling : box.firstChild);
        }
        if (!target || mode !== 'reply') {
            strip.classList.add('impulso-hidden');
            strip.innerHTML = '';
            return;
        }
        strip.classList.remove('impulso-hidden');
        strip.innerHTML = '<span class="impulso-reply-strip-icon"><i data-feather="corner-up-left"></i></span><span class="impulso-reply-strip-copy"><strong>Respondendo a ' + escapeHtml(target.author || 'mensagem') + '</strong><span>' + escapeHtml(target.preview || 'Mensagem') + '</span></span><button type="button" class="impulso-icon-button btn btn-default" data-composer-action="cancel-reply" aria-label="Cancelar resposta"><i data-feather="x"></i></button>';
        if (bridge.replaceIcons) bridge.replaceIcons();
    }

    function renderMode() {
        var root = composer();
        var field = input();
        if (root) root.setAttribute('data-mode', mode);
        document.querySelectorAll('[data-composer-mode]').forEach(function (button) {
            var active = button.getAttribute('data-composer-mode') === mode;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        if (field) {
            field.placeholder = mode === 'note' ? 'Escreva uma nota interna; ela nao sera enviada ao WhatsApp' : 'Digite uma mensagem…';
            field.setAttribute('aria-label', mode === 'note' ? 'Nota interna' : 'Mensagem');
        }
        document.querySelectorAll('[data-impulso-action="attach"], [data-impulso-action="voice"]').forEach(function (button) {
            var action = button.getAttribute('data-impulso-action');
            var available = action === 'voice' ? canUseVoice() : canSendMedia();
            var reason = mode === 'note'
                ? 'Anexos e voz nao estao disponiveis em notas internas'
                : !currentConversationId
                    ? 'Selecione uma conversa'
                    : !canSend()
                        ? 'Sem permissao para enviar mensagens'
                        : !conversationConnected()
                            ? 'A instancia esta desconectada'
                            : !hasAction('send_media')
                                ? 'Midia nao suportada neste canal'
                                : action === 'voice' && !canUseVoice()
                                    ? 'Notas de voz nao suportadas neste canal'
                                    : '';
            button.disabled = mode === 'note' || !available;
            if (reason) button.title = reason;
            else if (action === 'voice') button.title = 'Gravar audio';
            else button.title = 'Anexar arquivo';
            button.setAttribute('aria-label', button.title);
        });
        var preview = byId('impulso-attachment-preview');
        if (preview) preview.classList.toggle('impulso-hidden', mode === 'note' || !getMediaAttachments().length);
        var dropAffordance = byId('impulso-drop-affordance');
        if (dropAffordance && mode === 'note') dropAffordance.classList.add('impulso-hidden');
        var sendButton = byId('impulso-send-message');
        if (sendButton) {
            var noteAvailable = !!activeConversation() && canNote();
            sendButton.disabled = mode === 'note' ? !noteAvailable : !canReplyText() && !(canSendMedia() && getMediaAttachments().length);
            sendButton.title = mode === 'note' ? (noteAvailable ? 'Salvar nota interna' : 'Notas internas indisponiveis') : (!canReplyText() && !canSendMedia() ? 'Envio indisponivel neste canal' : 'Enviar');
            sendButton.setAttribute('aria-label', sendButton.title);
        }
        renderReplyStrip();
    }

    function renderState() {
        var field = input();
        var item = currentRecord();
        if (field && field.value !== item.text) field.value = item.text;
        renderMode();
        updateDraft();
    }

    function restoreConversation(conversationId) {
        closePopovers();
        currentConversationId = Number(conversationId || 0);
        store.restoreDraft(currentConversationId, mode);
        store.restoreDraft(currentConversationId, 'reply');
        store.restoreDraft(currentConversationId, 'note');
        var field = input();
        if (field) field.value = store.get(currentConversationId, mode).text;
        renderMode();
        renderReplyStrip();
    }

    function switchMode(nextMode) {
        nextMode = nextMode === 'note' ? 'note' : 'reply';
        if (nextMode === mode) { renderMode(); return; }
        closePopovers();
        updateDraft();
        store.flushAutosave(currentConversationId, mode);
        if (nextMode === 'note' && !canNote()) {
            toast('Nota indisponivel', 'Seu perfil nao possui permissao para notas internas.', 'shield');
            return;
        }
        mode = nextMode;
        if (media && media.setComposerMode) media.setComposerMode(mode);
        if (bridge.setComposerMode) bridge.setComposerMode(mode);
        store.restoreDraft(currentConversationId, mode);
        var field = input();
        if (field) field.value = store.get(currentConversationId, mode).text;
        renderMode();
    }

    function insertAtCursor(value) {
        var field = input();
        if (!field || field.disabled || mode === 'note' && value === '') return;
        var start = field.selectionStart == null ? field.value.length : field.selectionStart;
        var end = field.selectionEnd == null ? field.value.length : field.selectionEnd;
        field.value = field.value.slice(0, start) + text(value) + field.value.slice(end);
        field.selectionStart = field.selectionEnd = start + text(value).length;
        field.focus();
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function ensureEmojiPicker() {
        var picker = panel('impulso-emoji-picker');
        if (!picker || picker.dataset.ready) return;
        var emojis = ['😀', '😃', '😄', '😁', '😅', '😂', '🤣', '😊', '🙂', '😉', '😍', '🥰', '😘', '😎', '🤔', '🤗', '👍', '👏', '🙏', '💪', '✅', '❌', '⚠️', '🔥', '🎉', '❤️', '💙', '💚', '💛', '💬', '🚀', '⭐'];
        picker.innerHTML = '<div class="impulso-emoji-grid">' + emojis.map(function (emoji) { return '<button type="button" data-composer-emoji="' + escapeHtml(emoji) + '" aria-label="Emoji ' + escapeHtml(emoji) + '">' + emoji + '</button>'; }).join('') + '</div>';
        picker.dataset.ready = '1';
    }

    function toggleEmoji() {
        ensureEmojiPicker();
        var picker = panel('impulso-emoji-picker');
        var open = !!picker && picker.classList.contains('impulso-hidden');
        setPopoverState('impulso-quick-replies', false);
        setPopoverState('impulso-emoji-picker', open);
    }

    function renderQuickReplies(rows) {
        var replies = panel('impulso-quick-replies');
        if (!replies) return;
        var query = arguments.length > 1 ? text(arguments[1]) : ((Quick.slashToken(input() ? input().value : '', input() ? input().selectionStart : 0) || {}).query || '');
        quickQuery = query;
        var filtered = Quick.filter(quickSourceRows, query);
        quickVisibleRows = filtered;
        quickIndex = filtered.length ? Math.min(Math.max(quickIndex, 0), filtered.length - 1) : -1;
        replies.innerHTML = filtered.length ? '<div class="impulso-quick-reply-list" role="listbox" aria-label="Respostas rapidas">' + filtered.map(function (row, index) {
            return '<button type="button" role="option" aria-selected="' + (index === quickIndex ? 'true' : 'false') + '" class="' + (index === quickIndex ? 'is-highlighted' : '') + '" data-composer-quick-index="' + index + '"><strong>' + escapeHtml(row.shortcut || row.title || 'Resposta') + ' ' + escapeHtml(row.title || '') + '</strong><span>' + escapeHtml(row.text || row.content || '') + '</span></button>';
        }).join('') + '</div>' : '<div class="impulso-empty compact"><p>Nenhuma resposta rapida encontrada.</p></div>';
        setPopoverState('impulso-quick-replies', true);
        if (bridge.replaceIcons) bridge.replaceIcons();
    }

    function loadQuickReplies(open) {
        var replies = panel('impulso-quick-replies');
        if (!replies) return;
        if (replies.dataset.loaded === 'loading') return;
        setPopoverState('impulso-emoji-picker', false);
        if (open && replies.dataset.loaded === '1') {
            if (!replies.classList.contains('impulso-hidden') && quickQuery === '') {
                setPopoverState('impulso-quick-replies', false);
            } else {
                quickIndex = -1;
                renderQuickReplies(quickSourceRows, '');
            }
            return;
        }
        replies.dataset.loaded = 'loading';
        replies.innerHTML = '<div class="impulso-empty compact"><span class="spinner-border spinner-border-sm"></span><p>Carregando respostas...</p></div>';
        bridge.api(endpoint('quickReplies')).then(function (payload) {
            quickSourceRows = Array.isArray(payload && payload.data) ? payload.data : [];
            replies.dataset.loaded = '1';
            quickQuery = '';
            if (open) renderQuickReplies(quickSourceRows, '');
            else renderQuickReplies(quickSourceRows);
        }).catch(function (error) {
            replies.dataset.loaded = '';
            replies.innerHTML = '<div class="impulso-empty compact"><p>' + escapeHtml(error.message || 'Falha ao carregar respostas.') + '</p></div>';
            setPopoverState('impulso-quick-replies', true);
        });
    }

    function selectQuickReply(index) {
        var row = quickVisibleRows[Number(index)];
        if (!row) return;
        var field = input();
        var token = Quick.slashToken(field ? field.value : '', field ? field.selectionStart : 0);
        var catalog = {
            'contact.name': (activeConversation() || {}).name || (activeConversation() || {}).contact_name || '',
            'contact.phone': (activeConversation() || {}).phone || (activeConversation() || {}).phone_number || '',
            'agent.name': config.actorName || ''
        };
        var inserted = Quick.substitute(row.text || row.content || '', catalog);
        if (token && field) {
            var replaced = Quick.replaceSlashToken(field.value, field.selectionStart, inserted);
            field.value = replaced.value;
            field.selectionStart = field.selectionEnd = replaced.cursor;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        } else insertAtCursor(inserted);
        setPopoverState('impulso-quick-replies', false);
        quickIndex = -1;
    }

    function currentReplyTarget() {
        var target = store.get(currentConversationId, 'reply').replyTarget;
        if (!target) return null;
        var messages = (bridge.getState ? bridge.getState().messages : []) || [];
        var found = messages.find(function (message) { return Number(message.id || 0) === Number(target.messageId); });
        if (!found || !found.external_message_id || found.status === 'failed' || found.is_internal_note || found.direction === 'internal') {
            toast('Resposta indisponivel', 'A mensagem original nao esta mais disponivel. Cancele a resposta ou escolha outra mensagem.', 'alert-circle');
            return null;
        }
        return target;
    }

    function syncMediaVisibility() {
        var preview = byId('impulso-attachment-preview');
        if (preview && mode === 'reply' && getMediaAttachments().length) preview.classList.remove('impulso-hidden');
        if (preview && mode === 'note') preview.classList.add('impulso-hidden');
    }

    function sendNote(snapshot, clientMessageId) {
        var id = snapshot.conversationId;
        var mentions = mentionIds(snapshot.conversationId);
        var mentionSnapshot = window.ImpulsoMentions && typeof window.ImpulsoMentions.snapshot === 'function'
            ? window.ImpulsoMentions.snapshot(snapshot.conversationId)
            : { conversationId: snapshot.conversationId, revision: 0, ids: mentions };
        return bridge.api(endpointWithId('conversations', id, '/notes'), {
            method: 'POST',
            body: { content: snapshot.text, client_message_id: clientMessageId, mention_user_ids: mentions }
        }).then(function () {
            var mentionStillMatches = !window.ImpulsoMentions || typeof window.ImpulsoMentions.matchesSnapshot !== 'function' || window.ImpulsoMentions.matchesSnapshot(snapshot.conversationId, mentionSnapshot);
            if (mentionStillMatches) {
                var committed = store.commitText(snapshot, true);
                if (committed !== false && window.ImpulsoMentions && typeof window.ImpulsoMentions.clearIfMatches === 'function') window.ImpulsoMentions.clearIfMatches(snapshot.conversationId, mentionSnapshot);
                store.flushAutosave(snapshot.conversationId, snapshot.mode);
            }
            toast('Nota adicionada', 'A nota interna foi registrada.', 'file-text');
            return true;
        });
    }

    function sendCurrent() {
        if (!currentConversationId) return Promise.resolve(false);
        var sendStartedAt = timingNow();
        updateDraft();
        if (mode === 'note' && !canNote()) return Promise.reject(new Error('Notas internas indisponiveis.'));
        var storedTarget = mode === 'reply' ? store.get(currentConversationId, 'reply').replyTarget : null;
        var target = mode === 'reply' ? currentReplyTarget() : null;
        if (mode === 'reply' && storedTarget && !target) {
            return Promise.reject(new Error('A mensagem escolhida nao esta mais disponivel. Cancele a resposta ou escolha outra mensagem.'));
        }
        var snapshot = store.snapshot(currentConversationId, mode);
        if (!snapshot.text.trim() && !(mode === 'reply' && getMediaAttachments().length)) {
            toast('Mensagem vazia', 'Digite algum conteudo antes de enviar.', 'alert-circle');
            return Promise.resolve(false);
        }
        if (mode === 'reply' && target) snapshot.replyTarget = target;
        if (mode === 'reply' && !getMediaAttachments().length && !canReplyText()) return Promise.reject(new Error('Envio de texto indisponivel neste canal.'));
        if (mode === 'reply' && getMediaAttachments().length && !canSendMedia()) return Promise.reject(new Error('Envio de midia indisponivel neste canal.'));
        var attachments = getMediaAttachments(snapshot.conversationId);
        var attachmentIds = attachments.map(function (attachment) { return String(attachment && attachment.id || ''); }).filter(Boolean);
        var clientMessageId = mode === 'note'
            ? noteIdentity(snapshot, mentionIds(snapshot.conversationId))
            : (attachmentIds.length ? null : sendIdentity(snapshot, 'composer'));
        var pendingIds = clientMessageId ? [clientMessageId] : attachmentIds;
        pendingIds.forEach(function (id) {
            pendingSendsByClientId[String(id)] = { conversationId: snapshot.conversationId, mode: snapshot.mode, text: snapshot.text, replyTarget: snapshot.replyTarget };
        });
        if (store.clearForSend) store.clearForSend(snapshot);
        else { store.setText(snapshot.conversationId, snapshot.mode, ''); store.setReplyTarget(snapshot.conversationId, snapshot.mode, null); }
        store.flushAutosave(snapshot.conversationId, snapshot.mode);
        var currentField = input();
        if (currentField && Number(currentConversationId) === Number(snapshot.conversationId) && mode === snapshot.mode) currentField.value = '';
        renderReplyStrip();
        renderMode();
        var operation;
        if (mode === 'note') operation = sendNote(snapshot, clientMessageId);
        else if (attachments.length && media && media.sendAttachment) {
            operation = media.sendAttachment(null, { caption: snapshot.text, replyToMessageId: target ? target.messageId : 0 }).then(function (result) {
                result = result || {};
                store.commitMedia(snapshot, { captionSent: !!result.captionSent, replySent: !!result.replySent });
                store.flushAutosave(snapshot.conversationId, snapshot.mode);
                syncMediaVisibility();
                return Number(result.sent || 0) > 0;
            });
        } else {
            operation = bridge.sendText(snapshot.text, clientMessageId, null, { replyToMessageId: target ? target.messageId : 0, timingStartedAt: sendStartedAt }).then(function () {
                store.commitText(snapshot, true);
                store.flushAutosave(snapshot.conversationId, snapshot.mode);
                renderReplyStrip();
                return true;
            });
        }
        return operation.catch(function (error) {
            toast('Falha no envio', error.message || 'O envio falhou; o rascunho foi preservado.', 'alert-triangle');
            throw error;
        }).finally(function () {
            pendingIds.forEach(function (id) { delete pendingSendsByClientId[String(id)]; });
            if (bridge.updateComposerState) bridge.updateComposerState();
            renderMode();
        });
    }

    function handleFileList(files) {
        if (mode === 'note') { toast('Anexos indisponiveis', 'Notas internas nao enviam midia.', 'file-minus'); return; }
        if (!currentConversationId || !media || !media.setAttachments) return;
        if (!canSendMedia()) { toast('Midia indisponivel', 'O canal atual nao permite envio de midia.', 'file-minus'); return; }
        media.setAttachments(files, { conversationId: currentConversationId });
        syncMediaVisibility();
    }

    function handleKeydown(event) {
        if (event.isComposing || event.keyCode === 229) return;
        var replies = panel('impulso-quick-replies');
        var isQuickOpen = replies && !replies.classList.contains('impulso-hidden') && quickVisibleRows.length;
        if (event.key === 'Escape') {
            if (isQuickOpen || (panel('impulso-emoji-picker') && !panel('impulso-emoji-picker').classList.contains('impulso-hidden'))) {
                event.preventDefault();
                closePopovers();
                return;
            }
            if (store.get(currentConversationId, 'reply').replyTarget) {
                if (mode !== 'reply') return;
                event.preventDefault();
                store.setReplyTarget(currentConversationId, 'reply', null);
                store.scheduleAutosave(currentConversationId, 'reply');
                renderReplyStrip();
                return;
            }
        }
        if (isQuickOpen && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            event.preventDefault();
            quickIndex = Quick.keyboardIndex(quickVisibleRows.length, quickIndex, event.key);
            renderQuickReplies(quickSourceRows);
            return;
        }
        if (isQuickOpen && (event.key === 'Enter' || event.key === 'Tab')) {
            event.preventDefault();
            selectQuickReply(quickIndex < 0 ? 0 : quickIndex);
            return;
        }
        if (event.key === 'Enter' && !event.shiftKey && !event.ctrlKey && !event.metaKey) {
            event.preventDefault();
            sendCurrent().catch(function () {});
        }
    }

    function bind() {
        var field = input();
        if (!field) return;
        field.addEventListener('input', function () {
            updateDraft();
            var token = Quick.slashToken(field.value, field.selectionStart);
            if (token && mode === 'reply' && panel('impulso-quick-replies') && panel('impulso-quick-replies').dataset.loaded === '1') { quickIndex = -1; renderQuickReplies(quickSourceRows); }
            else if (token && mode === 'reply') loadQuickReplies(false);
            else if (!token && panel('impulso-quick-replies')) setPopoverState('impulso-quick-replies', false);
        });
        field.addEventListener('keydown', handleKeydown, true);
        field.addEventListener('paste', function (event) {
            var data = event.clipboardData;
            var files = Clipboard ? Clipboard.filesFromData(data) : [];
            if (!files.length) return;
            handleFileList(files);
            if (Clipboard && Clipboard.shouldPreventDefault(data)) event.preventDefault();
        });
        var fileInput = byId('impulso-attachment-input');
        if (fileInput) fileInput.addEventListener('change', function () { handleFileList(this.files); this.value = ''; });
        var box = document.querySelector('.impulso-composer-box');
        if (box) {
            ['dragenter', 'dragover'].forEach(function (eventName) { box.addEventListener(eventName, function (event) { if (mode === 'reply' && canSendMedia() && event.dataTransfer && event.dataTransfer.types && Array.prototype.indexOf.call(event.dataTransfer.types, 'Files') >= 0) { event.preventDefault(); box.classList.add('is-dragging'); var affordance = byId('impulso-drop-affordance'); if (affordance) affordance.classList.remove('impulso-hidden'); } }); });
            ['dragleave', 'drop'].forEach(function (eventName) { box.addEventListener(eventName, function (event) { event.preventDefault(); box.classList.remove('is-dragging'); var affordance = byId('impulso-drop-affordance'); if (affordance) affordance.classList.add('impulso-hidden'); }); });
            box.addEventListener('drop', function (event) { if (mode === 'reply' && canSendMedia()) handleFileList(event.dataTransfer && event.dataTransfer.files); else if (mode === 'reply') toast('Midia indisponivel', 'O canal atual nao permite envio de midia.', 'file-minus'); });
        }
        app.addEventListener('click', function (event) {
            var modeButton = event.target.closest('[data-composer-mode]');
            if (modeButton) { event.preventDefault(); event.stopImmediatePropagation(); switchMode(modeButton.getAttribute('data-composer-mode')); return; }
            var replyButton = event.target.closest('[data-reply-message-id]');
            if (replyButton) { event.preventDefault(); event.stopImmediatePropagation(); switchMode('reply'); store.setReplyTarget(currentConversationId, 'reply', { messageId: replyButton.getAttribute('data-reply-message-id'), author: replyButton.getAttribute('data-reply-author'), preview: replyButton.getAttribute('data-reply-preview') }); store.scheduleAutosave(currentConversationId, 'reply'); renderReplyStrip(); field.focus(); return; }
            var action = event.target.closest('[data-composer-action]');
            if (action) { event.preventDefault(); event.stopImmediatePropagation(); if (action.getAttribute('data-composer-action') === 'cancel-reply') { store.setReplyTarget(currentConversationId, 'reply', null); store.scheduleAutosave(currentConversationId, 'reply'); renderReplyStrip(); } return; }
            var emoji = event.target.closest('[data-composer-emoji]');
            if (emoji) { event.preventDefault(); event.stopImmediatePropagation(); insertAtCursor(emoji.getAttribute('data-composer-emoji')); setPopoverState('impulso-emoji-picker', false); return; }
            var quick = event.target.closest('[data-composer-quick-index]');
            if (quick) { event.preventDefault(); event.stopImmediatePropagation(); selectQuickReply(quick.getAttribute('data-composer-quick-index')); return; }
            var tool = event.target.closest('[data-impulso-action]');
            if (!tool || !tool.closest('.impulso-composer')) return;
            var toolAction = tool.getAttribute('data-impulso-action');
            if (toolAction === 'attach') { event.preventDefault(); event.stopImmediatePropagation(); if (mode === 'reply' && canSendMedia()) byId('impulso-attachment-input').click(); else if (mode === 'reply') toast('Midia indisponivel', 'O canal atual nao permite envio de midia.', 'file-minus'); }
            if (toolAction === 'emoji') { event.preventDefault(); event.stopImmediatePropagation(); toggleEmoji(); }
            if (toolAction === 'quick-replies') { event.preventDefault(); event.stopImmediatePropagation(); loadQuickReplies(true); }
            if (toolAction === 'voice') { event.preventDefault(); event.stopImmediatePropagation(); if (mode === 'reply' && canUseVoice() && media && media.startVoiceRecording) media.startVoiceRecording(); else if (mode === 'reply') toast('Voz indisponivel', 'Notas de voz nao estao disponiveis neste canal.', 'mic-off'); }
        }, true);
        document.addEventListener('click', function (event) {
            if (!event.target.closest('#impulso-emoji-picker') && !event.target.closest('[data-impulso-action="emoji"]')) setPopoverState('impulso-emoji-picker', false);
            if (!event.target.closest('#impulso-quick-replies') && !event.target.closest('[data-impulso-action="quick-replies"]')) setPopoverState('impulso-quick-replies', false);
        });
        var sendButton = byId('impulso-send-message');
        if (sendButton) sendButton.addEventListener('click', function (event) { event.preventDefault(); sendCurrent().catch(function () {}); }, true);
        if (bridge.setComposerMode) bridge.setComposerMode(mode);
        store.pruneDrafts();
        restoreConversation(currentConversationId);
    }

    window.ImpulsoComposerBridge = {
        setReplyTarget: function (target) {
            if (!currentConversationId || !target) return false;
            switchMode('reply');
            store.setReplyTarget(currentConversationId, 'reply', target);
            store.scheduleAutosave(currentConversationId, 'reply');
            renderReplyStrip();
            var field = input();
            if (field) field.focus();
            return true;
        },
        invalidateQuickReplies: function () {
            quickSourceRows = [];
            quickVisibleRows = [];
            var panelElement = panel('impulso-quick-replies');
            if (panelElement) delete panelElement.dataset.loaded;
        }
    };
    window.addEventListener('impulso:quick-replies-invalidated', function () {
        if (window.ImpulsoComposerBridge && window.ImpulsoComposerBridge.invalidateQuickReplies) window.ImpulsoComposerBridge.invalidateQuickReplies();
    });

    if (bridge.onConversationChange) bridge.onConversationChange(function (change) {
        change = change || {};
        var fromId = Number(change.fromId || 0);
        if (fromId && fromId === currentConversationId) {
            updateDraft();
            flushConversationDrafts(fromId);
        }
        closePopovers();
        restoreConversation(change && change.toId);
    });
    window.addEventListener('pagehide', function () {
        updateDraft();
        store.flushAll();
    });
    bind();
}(window, document));
