(function (window, document) {
    'use strict';

    function stableValue(value) {
        if (Array.isArray(value)) return value.map(stableValue);
        if (value && typeof value === 'object') {
            var result = {};
            Object.keys(value).sort().forEach(function (key) { result[key] = stableValue(value[key]); });
            return result;
        }
        return value == null ? '' : String(value);
    }

    function templateFingerprint(template, values) {
        return JSON.stringify(stableValue({ template_id: Number(template && template.id || 0), values: values }));
    }

    function displayTemplateValue(value) {
        if (value && typeof value === 'object') return value.name || value.local_media_id || '';
        return value == null ? '' : String(value);
    }

    function templateValuesComplete(template, values) {
        return (template && template.fields || []).every(function (field) {
            var value = values ? values[field.key] : '';
            return !field.required || (value && typeof value === 'object'
                ? Number(value.local_media_id || 0) > 0
                : String(value || '').trim() !== '');
        });
    }

    function computeCanSend(template, values, attempt) {
        if (!template || template.sendable !== true || !templateValuesComplete(template, values)) return false;
        if (!attempt) return true;
        if (attempt.pending) return false;
        var sameLogicalAttempt = attempt.fingerprint === templateFingerprint(template, values);
        return !sameLogicalAttempt || !['ambiguous_failure', 'rejected', 'idempotent_success'].includes(String(attempt.send_state || ''));
    }

    function logicalAttemptTransition(attempt, fingerprint, createId) {
        if (attempt && attempt.pending) return { action: 'pending', attempt: attempt };
        if (attempt && attempt.fingerprint === fingerprint && ['ambiguous_failure', 'rejected', 'idempotent_success'].includes(String(attempt.send_state || ''))) {
            return { action: 'blocked_terminal', attempt: attempt };
        }
        if (attempt && attempt.fingerprint === fingerprint) return { action: 'reuse', attempt: attempt };
        return {
            action: 'new',
            attempt: {
                clientMessageId: createId(),
                fingerprint: fingerprint,
                revision: Number(attempt && attempt.revision || 0) + 1,
                pending: true,
                send_state: 'sending',
            },
        };
    }

    function replaceDefinitionValue(text, position, value) {
        return String(text || '').split('{{' + Number(position) + '}}').join(displayTemplateValue(value));
    }

    function resolvePreview(template, values) {
        template = template || {};
        values = values || {};
        var header = template.header && template.header.type === 'text' ? String(template.header.text || '') : String(template.preview && template.preview.header || '');
        var body = template.body ? String(template.body.text || '') : String(template.preview && template.preview.body || '');
        var footer = template.footer ? String(template.footer.text || '') : String(template.preview && template.preview.footer || '');
        var buttonValues = (template.buttons || []).map(function (button) {
            return { text: String(button.text || ''), type: String(button.type || ''), url: String(button.url || '') };
        });
        var media = null;
        (template.fields || []).forEach(function (field) {
            var value = values[field.key];
            if (field.type === 'image' || field.type === 'video' || field.type === 'document') {
                media = { kind: field.type, selected: !!(value && Number(value.local_media_id || 0) > 0), label: value && Number(value.local_media_id || 0) > 0 ? 'Mídia selecionada' : 'Mídia necessária' };
                return;
            }
            if (field.location === 'body') body = replaceDefinitionValue(body, field.position, value);
            if (field.location === 'header') header = replaceDefinitionValue(header, field.position, value);
            if (field.location === 'button') {
                var parts = String(field.key || '').split('.');
                var button = buttonValues[Number(parts[1])];
                if (button) button.url = replaceDefinitionValue(button.url, field.position, value);
            }
        });
        return {
            header: header,
            body: body,
            footer: footer,
            buttons: buttonValues,
            media: media,
        };
    }

    function previewHtml(template, values, esc) {
        var preview = resolvePreview(template, values);
        var media = preview.media ? '<small class="impulso-template-preview-media">' + esc(preview.media.label) + '</small>' : '';
        var buttons = preview.buttons.length ? '<div class="impulso-template-preview-buttons">' + preview.buttons.map(function (button) {
            return '<span>' + esc(button.text || button.type) + '</span>';
        }).join('') + '</div>' : '';
        return '<div class="impulso-template-preview-header">' + esc(preview.header) + '</div><p>' + esc(preview.body) + '</p><small>' + esc(preview.footer) + '</small>' + media + buttons;
    }

    function create(options) {
        options = options || {};
        var app = options.app || document;
        var state = options.state;
        var picker = document.getElementById('impulso-template-picker');
        var trigger = document.getElementById('impulso-template-button');
        var focusBeforeOpen = null;
        var sessions = Object.create(null);
        var activeSession = null;

        function newForm(templateId) {
            return { templateId: Number(templateId || 0), values: {}, media: {}, revision: 0 };
        }
        function ensureSessionShape(session) {
            session.formsByTemplateId = session.formsByTemplateId && typeof session.formsByTemplateId === 'object' ? session.formsByTemplateId : {};
            session.attemptsByFingerprint = session.attemptsByFingerprint && typeof session.attemptsByFingerprint === 'object' ? session.attemptsByFingerprint : {};
            if (!session._sessionStateFinalized) {
                var legacyTemplateId = Number(session.selectedId || 0);
                if (legacyTemplateId && (session.values || session.media)) {
                    var legacyForm = session.formsByTemplateId[String(legacyTemplateId)] || newForm(legacyTemplateId);
                    legacyForm.values = session.values && typeof session.values === 'object' ? session.values : {};
                    legacyForm.media = session.media && typeof session.media === 'object' ? session.media : {};
                    session.formsByTemplateId[String(legacyTemplateId)] = legacyForm;
                }
                if (session.attempt && session.attempt.fingerprint) session.attemptsByFingerprint[session.attempt.fingerprint] = session.attempt;
                delete session.values;
                delete session.media;
                delete session.attempt;
                session._sessionStateFinalized = true;
            }
            return session;
        }
        function newSession(conversationId) {
            return ensureSessionShape({ conversationId: Number(conversationId || 0), rows: [], selectedId: 0, formsByTemplateId: {}, attemptsByFingerprint: {}, search: '', status: 'idle', error: '', last_synced_at: null, generation: 0, operationSequence: 0, latestOperations: {} });
        }
        function sessionFor(conversationId) {
            var id = Number(conversationId || 0);
            var key = String(id);
            if (!sessions[key]) {
                var initial = state && state.templates && Number(state.templates.conversationId || 0) === id ? state.templates : null;
                sessions[key] = ensureSessionShape(initial || newSession(id));
                sessions[key].conversationId = id;
                sessions[key].latestOperations = sessions[key].latestOperations || {};
                sessions[key].operationSequence = Number(sessions[key].operationSequence || 0);
                sessions[key].generation = Number(sessions[key].generation || 0);
            }
            return sessions[key];
        }
        function activateSession(conversationId) {
            var session = sessionFor(conversationId);
            activeSession = session;
            state.templates = session;
            return session;
        }
        function currentSession() {
            if (activeSession && state.templates === activeSession) return activeSession;
            return state && state.templates ? sessionFor(state.templates.conversationId) : sessionFor(0);
        }
        function operationContext(session, kind, templateId, metadata) {
            metadata = metadata || {};
            session.operationSequence = Number(session.operationSequence || 0) + 1;
            var normalizedTemplateId = templateId == null ? 0 : Number(templateId);
            var operationKey = String(kind || 'operation') + (normalizedTemplateId ? ':' + normalizedTemplateId : '');
            if (metadata.fingerprint) operationKey += ':' + metadata.fingerprint;
            session.latestOperations[operationKey] = session.operationSequence;
            session.latestOperations[kind] = session.operationSequence;
            return { conversationId: Number(session.conversationId), session: session, generation: Number(session.generation || 0), operationId: session.operationSequence, operationKey: operationKey, kind: kind, templateId: normalizedTemplateId, fingerprint: metadata.fingerprint || '', formRevision: metadata.formRevision == null ? null : Number(metadata.formRevision) };
        }
        function sessionMatches(context) {
            return !!context && sessionFor(context.conversationId) === context.session && Number(context.generation) === Number(context.session.generation) && Number(context.session.latestOperations[context.operationKey]) === Number(context.operationId) && Number(options.activeConversationId ? options.activeConversationId() : 0) === Number(context.conversationId) && state.templates === context.session;
        }
        function ownsAttempt(context, attempt) {
            return !!context && context.fingerprint && context.session === sessionFor(context.conversationId) && Number(context.generation) === Number(context.session.generation) && context.session.attemptsByFingerprint[context.fingerprint] === attempt && Number(attempt.operationId) === Number(context.operationId);
        }

        function esc(value) { return options.escapeHtml ? options.escapeHtml(value) : String(value == null ? '' : value); }
        function conversation() { return options.activeConversation ? options.activeConversation() : null; }
        function endpoint(id) { return options.templateEndpoint ? options.templateEndpoint(id) : ''; }
        function canManageInstances() { return !!(options.config && options.config.permissions && options.config.permissions.manageInstances === true); }
        function fingerprint(template, values) { return templateFingerprint(template, values); }
        function revokeMediaValue(value) {
            if (value && value.preview_url && value.preview_url.indexOf('blob:') === 0) {
                try { window.URL.revokeObjectURL(value.preview_url); } catch (error) { /* noop */ }
            }
        }
        function resetConversation(conversationId) {
            return activateSession(conversationId);
        }
        function formFor(session, templateId) {
            session = session || currentSession();
            var key = String(Number(templateId || 0));
            if (!session.formsByTemplateId[key]) session.formsByTemplateId[key] = newForm(templateId);
            session.formsByTemplateId[key].templateId = Number(templateId || 0);
            session.formsByTemplateId[key].values = session.formsByTemplateId[key].values && typeof session.formsByTemplateId[key].values === 'object' ? session.formsByTemplateId[key].values : {};
            session.formsByTemplateId[key].media = session.formsByTemplateId[key].media && typeof session.formsByTemplateId[key].media === 'object' ? session.formsByTemplateId[key].media : {};
            session.formsByTemplateId[key].revision = Number(session.formsByTemplateId[key].revision || 0);
            return session.formsByTemplateId[key];
        }
        function clearForm(form) {
            if (!form) return;
            Object.keys(form.media || {}).forEach(function (key) { revokeMediaValue(form.media[key]); });
            form.values = {};
            form.media = {};
            form.revision = Number(form.revision || 0) + 1;
        }
        function rememberFormValues(form, values) {
            var previous = JSON.stringify(stableValue(form.values || {}));
            var next = JSON.stringify(stableValue(values || {}));
            if (previous !== next) form.revision = Number(form.revision || 0) + 1;
            form.values = Object.assign({}, values || {});
        }
        function attemptFor(session, template, values) {
            return session.attemptsByFingerprint[fingerprint(template, values)] || null;
        }
        function archiveSuccessfulAttempts(session, templateId) {
            Object.keys(session.attemptsByFingerprint || {}).forEach(function (key) {
                var attempt = session.attemptsByFingerprint[key];
                if (attempt && Number(attempt.templateId || 0) === Number(templateId || 0) && attempt.send_state === 'idempotent_success') delete session.attemptsByFingerprint[key];
            });
        }
        function selected(session) {
            session = session || currentSession();
            return (session.rows || []).find(function (row) { return Number(row.id) === Number(session.selectedId); }) || null;
        }
        function fieldValue(field, form) {
            form = form || formFor(currentSession(), 0);
            if (Object.prototype.hasOwnProperty.call(form.values || {}, field.key)) return form.values[field.key];
            return '';
        }
        function displayValue(value) { return displayTemplateValue(value); }
        function currentValues(template, session, form) {
            session = session || currentSession();
            form = form || formFor(session, template.id);
            var values = {};
            (template.fields || []).forEach(function (field) {
                if (field.type === 'image' || field.type === 'video' || field.type === 'document') {
                    var media = form.media && form.media[field.key];
                    if (media && media.local_media_id) values[field.key] = { kind: field.type, local_media_id: Number(media.local_media_id) };
                    return;
                }
                var input = picker && Number(session.selectedId) === Number(template.id) && Array.prototype.slice.call(picker.querySelectorAll('[data-template-value]')).find(function (candidate) { return candidate.getAttribute('data-template-value') === field.key; });
                values[field.key] = input ? String(input.value || '').trim() : displayValue(fieldValue(field, form)).trim();
            });
            return values;
        }
        function requiredComplete(template, values) { return templateValuesComplete(template, values); }
        function render(session) {
            session = session || currentSession();
            if (!picker || !session || state.templates !== session) return;
            var query = String(session.search || '').trim().toLowerCase();
            var rows = (session.rows || []).filter(function (item) {
                if (!query) return true;
                return [item.name, item.language, item.category, item.preview && item.preview.body, item.body && item.body.text].join(' ').toLowerCase().indexOf(query) >= 0;
            });
            var item = selected(session);
            var admin = canManageInstances();
            var refresh = admin ? '<button type="button" class="btn btn-default btn-sm" data-template-refresh>Atualizar</button>' : '<small class="impulso-template-sync-note">Listagem local · Atualização exige permissão de gerenciamento de instâncias.</small>';
            if (session.status === 'loading') {
                picker.innerHTML = '<div class="impulso-template-picker-head"><strong>Templates aprovados</strong></div><p class="impulso-empty-copy" role="status">Carregando templates...</p>';
            } else if (session.status === 'error') {
                picker.innerHTML = '<div class="impulso-template-picker-head"><strong>Templates aprovados</strong>' + refresh + '</div><p class="impulso-empty-copy" role="alert">' + esc(session.error || 'Não foi possível carregar os templates.') + '</p>';
            } else if (!item) {
                var last = session.last_synced_at ? '<small class="impulso-template-sync-note">Última sincronização: ' + esc(session.last_synced_at) + '</small>' : '';
                picker.innerHTML = '<div class="impulso-template-picker-head"><strong>Templates aprovados</strong>' + refresh + '</div>' + last + '<input type="search" class="form-control" data-template-search aria-label="Buscar templates" placeholder="Buscar por nome, idioma ou conteúdo" value="' + esc(session.search || '') + '">' +
                    (rows.length ? '<div class="impulso-template-list">' + rows.map(function (row) { return '<button type="button" class="impulso-template-option" data-template-id="' + Number(row.id) + '"><strong>' + esc(row.name) + '</strong><small>' + esc(row.language || '') + ' · ' + esc(row.category || '') + '</small><span>' + esc(row.preview && row.preview.body || (row.body && row.body.text) || '') + '</span></button>'; }).join('') + '</div>' : '<p class="impulso-empty-copy">Nenhum template aprovado sincronizado.</p>');
            } else {
                var form = formFor(session, item.id);
                var fields = (item.fields || []).map(function (field) {
                    if (['image', 'video', 'document'].indexOf(String(field.type || '')) >= 0) {
                        var media = form.media && form.media[field.key];
                        return '<label class="impulso-template-field"><span>' + esc(field.key) + ' · ' + esc(field.type) + (field.required ? ' *' : '') + '</span><input type="file" data-template-media="' + esc(field.key) + '" accept="' + (field.type === 'image' ? 'image/jpeg,image/png' : field.type === 'video' ? 'video/mp4,video/3gpp' : '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt') + '">' + (media ? '<small>' + esc(media.name || 'Mídia selecionada') + ' <button type="button" data-template-media-clear="' + esc(field.key) + '">Remover</button></small>' : '') + '</label>';
                    }
                    return '<label class="impulso-template-field"><span>' + esc(field.key) + (field.required ? ' *' : '') + '</span><input type="text" data-template-value="' + esc(field.key) + '" value="' + esc(displayValue(fieldValue(field, form))) + '" maxlength="4096"></label>';
                }).join('');
                var values = currentValues(item, session, form);
                var canSend = computeCanSend(item, values, attemptFor(session, item, values));
                picker.innerHTML = '<div class="impulso-template-picker-head"><button type="button" class="btn btn-default btn-sm" data-template-back>Voltar</button><strong>' + esc(item.name) + '</strong></div><div class="impulso-template-preview" data-template-preview>' + previewHtml(item, values, esc) + '</div>' + (item.sendable ? fields + '<button type="button" class="btn btn-primary" data-template-send' + (canSend ? '' : ' disabled') + '>Enviar template</button>' : '<p class="impulso-empty-copy">Este template não é enviável.</p>');
            }
            picker.classList.remove('impulso-hidden');
            if (options.replaceIcons) options.replaceIcons();
        }
        function load(force) {
            var conv = conversation();
            if (!conv || !conv.capabilities || !conv.capabilities.actions || conv.capabilities.actions.send_template !== true) return Promise.resolve([]);
            var session = sessionFor(conv.id);
            if (state.templates !== session) { activeSession = session; state.templates = session; }
            if (!force && session.rows.length) { render(session); return Promise.resolve(session.rows); }
            var context = operationContext(session, 'template-list');
            session.status = 'loading';
            session.error = '';
            render(session);
            return Promise.resolve().then(function () {
                return force ? options.api(endpoint(context.conversationId) + '/sync', { method: 'POST', body: {} }) : options.api(endpoint(context.conversationId));
            }).then(function (payload) {
                if (!sessionMatches(context)) return [];
                session.rows = Array.isArray(payload.data) ? payload.data : [];
                session.last_synced_at = payload.meta && payload.meta.last_synced_at || (session.rows[0] && session.rows[0].last_synced_at) || null;
                session.status = 'ready';
                session.error = '';
                render(session);
                return session.rows;
            }).catch(function (error) {
                if (!sessionMatches(context)) return [];
                session.status = 'error';
                session.error = error.message || 'Falha no carregamento.';
                render(session);
                return [];
            });
        }
        function close(restoreFocus) {
            if (!picker) return;
            picker.classList.add('impulso-hidden');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
            if (restoreFocus && focusBeforeOpen && typeof focusBeforeOpen.focus === 'function') focusBeforeOpen.focus();
        }
        function open() {
            var conv = conversation();
            if (!conv) return;
            focusBeforeOpen = document.activeElement;
            if (trigger) { trigger.setAttribute('aria-expanded', 'true'); trigger.setAttribute('aria-controls', 'impulso-template-picker'); }
            if (conv.service_window && conv.service_window.open !== false && options.toast) options.toast('Template oficial', 'Templates são usados quando a janela exigir ou quando você precisar de uma mensagem aprovada.', 'file-text');
            load(false);
        }
        function choose(id) {
            var session = currentSession();
            var selectedId = Number(id || 0);
            archiveSuccessfulAttempts(session, selectedId);
            formFor(session, selectedId);
            session.selectedId = selectedId;
            render(session);
        }
        function messageBelongsToConversation(data, conversationId) {
            var value = data && (data.conversation_id || (data.conversation && data.conversation.id));
            return value != null && Number(value) === Number(conversationId);
        }
        function send() {
            var session = currentSession();
            var conv = conversation(), item = selected(session);
            if (!conv || !item || !item.sendable) return Promise.resolve(false);
            var form = formFor(session, item.id);
            var values = currentValues(item, session, form);
            rememberFormValues(form, values);
            if (!requiredComplete(item, values)) { if (options.toast) options.toast('Campos obrigatórios', 'Preencha todos os valores antes de enviar.', 'alert-circle'); render(session); return Promise.resolve(false); }
            var fp = fingerprint(item, values), attempt = session.attemptsByFingerprint[fp];
            var transition = logicalAttemptTransition(attempt, fp, options.createClientMessageId);
            if (transition.action === 'pending') return transition.attempt.promise || Promise.resolve(false);
            if (transition.action === 'blocked_terminal') {
                if (options.toast) options.toast('Envio encerrado', transition.attempt.send_state === 'ambiguous_failure' ? 'Verifique o provedor antes de tentar novamente.' : 'Edite um campo ou a mídia para criar uma nova tentativa.', 'shield');
                return Promise.resolve(false);
            }
            attempt = session.attemptsByFingerprint[fp] = transition.attempt;
            attempt.templateId = Number(item.id);
            attempt.formRevision = Number(form.revision || 0);
            attempt.pending = true; attempt.send_state = 'sending';
            var context = operationContext(session, 'send', item.id, { fingerprint: fp, formRevision: form.revision });
            attempt.operationId = context.operationId;
            render(session);
            var operation = Promise.resolve().then(function () {
                return options.api(endpoint(context.conversationId), { method: 'POST', body: { template_id: Number(item.id), values: values, client_message_id: attempt.clientMessageId } });
            }).then(function (payload) {
                var belongs = messageBelongsToConversation(payload && payload.data, context.conversationId);
                if (ownsAttempt(context, attempt)) {
                    attempt.pending = false;
                    attempt.send_state = 'idempotent_success';
                    var formStillOwnsOperation = formFor(session, context.templateId) === form && Number(form.revision) === Number(context.formRevision);
                    if (formStillOwnsOperation) {
                        clearForm(form);
                        if (sessionMatches(context) && Number(session.selectedId) === Number(context.templateId)) session.selectedId = 0;
                    }
                    if (sessionMatches(context)) {
                        if (belongs) {
                            var message = options.normalizeMessage(payload.data || {});
                            options.mergeMessages([message], false);
                            options.renderMessages({ forceBottom: true });
                            conv.last_message = message.text_content;
                            conv.last_activity_at = message.sent_at;
                        }
                        render(session);
                        if (options.toast) options.toast('Template enviado', 'A mensagem aprovada foi enviada.', 'check-circle');
                        return belongs ? message : (payload.data || {});
                    }
                }
                return payload && payload.data ? payload.data : payload;
            }).catch(function (error) {
                var errorState = error && error.details && error.details.send_state;
                if (!ownsAttempt(context, attempt)) throw error;
                attempt.pending = false; attempt.send_state = ['pending', 'ambiguous_failure', 'rejected', 'idempotent_success', 'retryable_failure'].includes(String(errorState || ''))
                    ? String(errorState)
                    : (error && Number(error.status) === 422 ? 'rejected' : (error && Number(error.status) === 409 ? 'ambiguous_failure' : 'retryable_failure'));
                if (sessionMatches(context)) {
                    if (options.reconcileWindowError) options.reconcileWindowError(error, context.conversationId);
                    render(session);
                    if (options.toast) options.toast(attempt.send_state === 'ambiguous_failure' ? 'Envio não confirmado' : 'Falha no template', error.message || 'Falha no envio.', 'alert-triangle');
                }
                throw error;
            });
            attempt.promise = operation; return operation;
        }
        if (picker && !picker.dataset.templatePickerBound) {
            picker.dataset.templatePickerBound = '1';
            picker.addEventListener('input', function (event) {
                var session = currentSession();
                if (event.target.matches('[data-template-search]')) { session.search = event.target.value || ''; render(session); var input = picker.querySelector('[data-template-search]'); if (input) { input.focus(); input.setSelectionRange(input.value.length, input.value.length); } return; }
                if (event.target.matches('[data-template-value]')) {
                    var item = selected(session), formState = item && formFor(session, item.id);
                    if (!formState) return;
                    formState.values[event.target.getAttribute('data-template-value')] = String(event.target.value || '').trim();
                    formState.revision = Number(formState.revision || 0) + 1;
                    var values = currentValues(item || { id: 0, fields: [] }, session, formState), sendButton = picker.querySelector('[data-template-send]');
                    if (sendButton) sendButton.disabled = !computeCanSend(item, values, attemptFor(session, item, values));
                    var preview = picker.querySelector('[data-template-preview]');
                    if (preview) preview.innerHTML = previewHtml(item, values, esc);
                }
            });
            picker.addEventListener('change', function (event) {
                var input = event.target.closest('[data-template-media]'); if (!input || !input.files || !input.files[0]) return;
                var session = currentSession(), conv = conversation(), fieldKey = input.getAttribute('data-template-media'), item = selected(session), file = input.files[0], field = item && (item.fields || []).find(function (candidate) { return candidate.key === fieldKey; });
                if (!conv || !item || !field) return;
                var form = new FormData(); form.append('file', file, file.name); form.append('kind', field.type);
                input.disabled = true;
                var formState = formFor(session, item.id);
                formState.revision = Number(formState.revision || 0) + 1;
                var context = operationContext(session, 'media:' + fieldKey, item.id, { formRevision: formState.revision });
                Promise.resolve().then(function () {
                    return options.api(endpoint(context.conversationId) + '/media', { method: 'POST', body: form });
                }).then(function (payload) {
                    if (!sessionMatches(context)) return;
                    if (formFor(session, context.templateId) !== formState || Number(formState.revision) !== Number(context.formRevision)) return;
                    var media = Object.assign({}, payload.data || {});
                    media.preview_url = window.URL && typeof window.URL.createObjectURL === 'function' ? window.URL.createObjectURL(file) : '';
                    formState.media[fieldKey] = media;
                    formState.values[fieldKey] = { kind: field.type, local_media_id: Number(media.local_media_id) };
                    if (Number(session.selectedId) === Number(context.templateId)) render(session);
                }).catch(function (error) {
                    if (!sessionMatches(context)) return;
                    if (options.toast) options.toast('Mídia do template', error.message || 'Falha ao armazenar a mídia.', 'alert-triangle');
                    render(session);
                });
            });
            picker.addEventListener('click', function (event) {
                var button = event.target.closest('[data-template-id],[data-template-back],[data-template-refresh],[data-template-send],[data-template-media-clear]'); if (!button) return;
                if (button.hasAttribute('data-template-id')) choose(button.getAttribute('data-template-id'));
                else if (button.hasAttribute('data-template-back')) { var session = currentSession(); session.selectedId = 0; render(session); }
                else if (button.hasAttribute('data-template-refresh')) load(true);
                else if (button.hasAttribute('data-template-send')) send().catch(function () {});
                else { var session = currentSession(), key = button.getAttribute('data-template-media-clear'), item = selected(session), formState = item && formFor(session, item.id); if (!formState) return; revokeMediaValue(formState.media[key]); delete formState.media[key]; delete formState.values[key]; formState.revision = Number(formState.revision || 0) + 1; render(session); }
            });
        }
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && picker && !picker.classList.contains('impulso-hidden')) close(true); });
        document.addEventListener('click', function (event) { if (picker && !picker.classList.contains('impulso-hidden') && !picker.contains(event.target) && event.target !== trigger && !event.target.closest('[data-impulso-action="templates"]')) close(false); });
        return { open: open, close: close, load: load, render: render, reset: resetConversation, send: send, computeCanSend: computeCanSend, resolvePreview: resolvePreview, getSession: sessionFor, getActiveSession: currentSession };
    }
    window.ImpulsoTemplatePicker = { create: create, computeCanSend: computeCanSend, logicalAttemptTransition: logicalAttemptTransition, resolvePreview: resolvePreview };
}(window, document));
