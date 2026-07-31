(function (window, document) {
    'use strict';

    var previousRuntime = window.ImpulsoHubRuntime;
    if (previousRuntime && typeof previousRuntime.destroy === 'function') {
        previousRuntime.destroy();
    }

    var app = document.getElementById('impulso-hub-app');
    if (!app) {
        return;
    }

    function readJson(id, fallback) {
        var element = document.getElementById(id);
        if (!element) return fallback;
        try {
            return JSON.parse(element.textContent || '');
        } catch (error) {
            return fallback;
        }
    }

    var config = readJson('impulso-app-config', {});
    var initialConversations = readJson('impulso-conversation-data', []);
    var initialInstances = readJson('impulso-instance-data', []);
    var initialSettings = readJson('impulso-settings-data', {});
    var runtime = {
        destroyed: false,
        timers: [],
        requests: [],
        destroy: function () {
            this.destroyed = true;
            this.timers.forEach(function (timer) { window.clearTimeout(timer); });
            this.timers = [];
            this.requests.forEach(function (controller) {
                try { controller.abort(); } catch (error) { /* noop */ }
            });
            this.requests = [];
        }
    };
    window.ImpulsoHubRuntime = runtime;

    var state = {
        conversations: Array.isArray(initialConversations) ? initialConversations.map(normalizeConversation) : [],
        instances: Array.isArray(initialInstances) ? initialInstances.map(normalizeInstance) : [],
        messages: [],
        messageAfterId: 0,
        activeConversationId: null,
        channelId: 'all',
        status: 'all',
        search: '',
        page: 1,
        hasMore: false,
        hasMoreBefore: false,
        listLoading: false,
        listRequestSequence: 0,
        listRequestContext: '',
        instanceLoading: false,
        messageLoading: false,
        messageRequestSequence: 0,
        messageRequestContext: '',
        sending: false,
        syncedChannels: {},
        pollingInstanceIndex: 0,
        pollingChannelSyncing: false,
        pollingTimer: null,
        searchTimer: null
    };

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function replaceIcons() {
        if (window.feather && typeof window.feather.replace === 'function') {
            window.feather.replace();
        }
    }

    function showToast(title, message, icon) {
        var stack = document.getElementById('impulso-toast-stack');
        if (!stack) return;
        var toast = document.createElement('div');
        toast.className = 'impulso-toast';
        toast.innerHTML = '<i data-feather="' + escapeHtml(icon || 'check-circle') + '"></i><div><strong>' + escapeHtml(title) + '</strong><span>' + escapeHtml(message) + '</span></div>';
        stack.appendChild(toast);
        replaceIcons();
        var timer = window.setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(8px)';
            toast.style.transition = '.18s ease';
            window.setTimeout(function () { toast.remove(); }, 190);
        }, 3600);
        runtime.timers.push(timer);
    }

    function closeModal(element) {
        var modalElement = element && element.closest ? element.closest('.modal') : element;
        if (!modalElement) return;
        if (window.bootstrap && window.bootstrap.Modal) {
            (window.bootstrap.Modal.getInstance(modalElement) || window.bootstrap.Modal.getOrCreateInstance(modalElement)).hide();
        } else if (window.jQuery && typeof window.jQuery(modalElement).modal === 'function') {
            window.jQuery(modalElement).modal('hide');
        }
    }

    function endpoint(name) {
        return config.endpoints && config.endpoints[name] ? String(config.endpoints[name]) : '';
    }

    function endpointWithId(name, id, suffix) {
        var base = endpoint(name).replace(/\/$/, '');
        return base + '/' + encodeURIComponent(String(id)) + (suffix || '');
    }

    function apiErrorMessage(payload, status) {
        if (payload && typeof payload.message === 'string' && payload.message) return payload.message;
        if (payload && payload.error && typeof payload.error.message === 'string') return payload.error.message;
        return status === 403 ? 'Você não tem permissão para esta ação.' : 'Não foi possível concluir a solicitação.';
    }

    var csrfRefreshPromise = null;

    function applyCsrf(data) {
        data = data || {};
        if (data.csrf_header) config.csrfHeader = String(data.csrf_header);
        if (data.csrf_token_name) config.csrfTokenName = String(data.csrf_token_name);
        if (data.csrf_hash) config.csrfHash = String(data.csrf_hash);
        if (window.AppHelper && data.csrf_hash) {
            window.AppHelper.csrfHash = String(data.csrf_hash);
            if (data.csrf_token_name) window.AppHelper.csrfTokenName = String(data.csrf_token_name);
        }
    }

    function refreshCsrf() {
        var url = endpoint('csrf');
        if (!url || csrfRefreshPromise) return csrfRefreshPromise || Promise.resolve();

        csrfRefreshPromise = window.fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            return response.text().then(function (text) {
                var payload = {};
                try { payload = text ? JSON.parse(text) : {}; } catch (error) { payload = {}; }
                if (!response.ok || payload.success === false) {
                    var apiException = new Error(apiErrorMessage(payload, response.status));
                    apiException.status = response.status;
                    apiException.payload = payload;
                    throw apiException;
                }
                applyCsrf(payload.data);
            });
        }).finally(function () {
            csrfRefreshPromise = null;
        });

        return csrfRefreshPromise;
    }

    function api(url, options) {
        options = options || {};
        var method = String(options.method || 'GET').toUpperCase();
        var isWrite = method !== 'GET' && method !== 'HEAD';

        return isWrite
            ? refreshCsrf().then(function () { return apiRequest(url, options); })
            : apiRequest(url, options);
    }

    function apiRequest(url, options) {
        options = options || {};
        var method = String(options.method || 'GET').toUpperCase();
        var headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }, options.headers || {});
        var isFormData = options.body && window.FormData && options.body instanceof window.FormData;
        if (method !== 'GET' && method !== 'HEAD') {
            if (!isFormData) headers['Content-Type'] = 'application/json';
            if (config.csrfHash) headers[config.csrfHeader || 'X-CSRF-TOKEN'] = config.csrfHash;
        }
        var controller = window.AbortController ? new AbortController() : null;
        if (controller) runtime.requests.push(controller);

        return window.fetch(url, {
            method: method,
            headers: headers,
            body: options.body == null ? undefined : (isFormData ? options.body : JSON.stringify(options.body)),
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined,
            cache: 'no-store'
        }).then(function (response) {
            return response.text().then(function (text) {
                var payload = {};
                try { payload = text ? JSON.parse(text) : {}; } catch (error) { payload = {}; }
                if (!response.ok || payload.success === false) {
                    var apiException = new Error(apiErrorMessage(payload, response.status));
                    apiException.status = response.status;
                    apiException.payload = payload;
                    throw apiException;
                }
                return payload;
            });
        }).finally(function () {
            if (!controller) return;
            runtime.requests = runtime.requests.filter(function (item) { return item !== controller; });
        });
    }

    function initials(name, phone) {
        var source = String(name || '').trim();
        if (!source) source = String(phone || '').slice(-4);
        var parts = source.split(/\s+/).filter(Boolean);
        return ((parts[0] || '?').charAt(0) + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase();
    }

    function normalizeInstance(item) {
        item = item || {};
        return {
            id: Number(item.id || 0),
            name: String(item.name || item.evolution_instance_name || 'Canal sem nome'),
            evolution_instance_name: String(item.evolution_instance_name || ''),
            internal_identifier: String(item.internal_identifier || ''),
            base_url: String(item.base_url || ''),
            phone: String(item.phone || item.phone_number || ''),
            status: String(item.status || item.connection_status || 'disconnected').toLowerCase(),
            active: item.active === true || Number(item.active) === 1,
            conversation_count: Number(item.conversation_count || item.open_conversations || 0),
            unread_count: Number(item.unread_count || 0),
            messages_today: Number(item.messages_today || 0),
            last_sync_at: item.last_sync_at || null,
            has_api_key: !!item.has_api_key
        };
    }

    function normalizeConversation(item) {
        item = item || {};
        var contact = item.contact || {};
        var instance = item.instance || {};
        var name = String(item.name || contact.name || item.contact_name || item.phone_number || 'Contato');
        var phone = String(item.phone || contact.phone || item.phone_number || '');
        return {
            id: Number(item.id || 0),
            instance_id: Number(item.instance_id || instance.id || 0),
            instance: String(item.instance_name || (typeof item.instance === 'string' ? item.instance : instance.name) || ''),
            instance_status: String(item.instance_status || instance.status || 'disconnected'),
            remote_jid: String(item.remote_jid || ''),
            name: name,
            phone: phone,
            avatar: String(item.avatar || contact.initials || initials(name, phone)),
            profile_picture_url: String(item.profile_picture_url || contact.avatar_url || ''),
            status: String(item.status || 'open'),
            contact_id: Number(item.contact_id || contact.id || 0) || null,
            priority: String(item.priority || 'normal'),
            assignee_id: Number(item.assignee_id || 0) || null,
            ai_status: String(item.ai_status || 'running'),
            ai_summary: String(item.ai_summary || ''),
            unread: Number(item.unread || item.unread_count || 0),
            last_message: String(item.last_message || item.last_message_preview || ''),
            last_activity_at: item.last_activity_at || item.last_message_at || null,
            assignee: String(item.assignee || 'Não atribuído'),
            team: String(item.team || 'Atendimento'),
            email: String(item.email || ''),
            city: String(item.city || ''),
            source: String(item.source || 'WhatsApp'),
            created_at: item.created_at || null,
            tags: Array.isArray(item.tags) ? item.tags : []
        };
    }

    function normalizeMessage(item) {
        item = item || {};
        var rawId = item.id == null ? String(item.client_message_id || '') : item.id;
        var numericId = Number(rawId);
        var sentAt = item.sent_at || item.created_at || new Date().toISOString();
        var timestamp = Number(item.message_timestamp || 0);
        if ((!isFinite(timestamp) || timestamp <= 0) && sentAt) {
            var parsedSentAt = new Date(sentAt).getTime();
            timestamp = isFinite(parsedSentAt) ? Math.floor(parsedSentAt / 1000) : 0;
        }
        return {
            id: isFinite(numericId) && numericId > 0 ? numericId : String(rawId || ''),
            client_message_id: String(item.client_message_id || ''),
            external_message_id: String(item.external_message_id || item.external_id || ''),
            direction: String(item.direction || item.type || 'incoming'),
            message_type: String(item.message_type || item.content_type || 'text'),
            text_content: String(item.text_content || item.content || item.text || ''),
            media_url: safeMediaUrl(item.media_url || ''),
            mime_type: String(item.mime_type || ''),
            caption: String(item.caption || ''),
            file_name: String(item.file_name || ''),
            file_size: Number(item.file_size || 0),
            media_id: Number(item.media_id || 0) || null,
            sender_user_id: Number(item.sender_user_id || 0) || null,
            is_internal_note: !!item.is_internal_note || String(item.message_type || '') === 'note',
            delivery_error: String(item.delivery_error || ''),
            status: String(item.status || 'received').toLowerCase(),
            sent_at: sentAt,
            message_timestamp: isFinite(timestamp) && timestamp > 0 ? timestamp : 0,
            temporary: !!item.temporary
        };
    }

    function safeMediaUrl(value) {
        if (!value) return '';
        try {
            var parsed = new URL(String(value), window.location.origin);
            return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : '';
        } catch (error) {
            return '';
        }
    }

    function dateValue(value) {
        var parsed = value ? new Date(value) : new Date();
        return isNaN(parsed.getTime()) ? new Date() : parsed;
    }

    function conversationTime(value) {
        if (!value) return '';
        var date = dateValue(value);
        var now = new Date();
        if (date.toDateString() === now.toDateString()) {
            return new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(date);
        }
        var yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
        if (date.toDateString() === yesterday.toDateString()) return 'Ontem';
        return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit' }).format(date);
    }

    function messageTime(value) {
        return new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(dateValue(value));
    }

    function dayLabel(value) {
        var date = dateValue(value);
        var now = new Date();
        if (date.toDateString() === now.toDateString()) return 'Hoje';
        var yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
        if (date.toDateString() === yesterday.toDateString()) return 'Ontem';
        return new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' }).format(date);
    }

    function selectedInstance() {
        var id = Number(state.channelId || 0);
        return state.instances.find(function (item) { return item.id === id; }) || null;
    }

    function activeConversation() {
        return state.conversations.find(function (item) { return Number(item.id) === Number(state.activeConversationId); }) || null;
    }

    function setText(id, value, fallback) {
        var element = document.getElementById(id);
        if (element) element.textContent = value == null || value === '' ? (fallback || 'Não informado') : String(value);
    }

    function renderChannels() {
        var list = document.querySelector('.impulso-channel-list');
        var mobile = document.getElementById('impulso-mobile-channel-filter');
        if (!list || !mobile) return;
        var totalConversations = 0;
        var totalUnread = 0;
        state.instances.forEach(function (instance) {
            totalConversations += instance.conversation_count;
            totalUnread += instance.unread_count;
        });
        var allActive = state.channelId === 'all';
        var html = '<button class="impulso-channel-item' + (allActive ? ' active' : '') + '" type="button" role="tab" aria-selected="' + (allActive ? 'true' : 'false') + '" data-channel-filter="all" data-channel-label="Todos os canais">' +
            '<span class="impulso-channel-icon all"><i data-feather="layers"></i></span><span class="impulso-channel-copy"><strong>Todos os canais</strong><small>' + totalConversations + ' conversa' + (totalConversations === 1 ? '' : 's') + '</small></span>' +
            (totalUnread > 0 ? '<span class="impulso-channel-unread">' + totalUnread + '</span>' : '') + '</button>';
        var options = '<option value="all">Todos os canais</option>';
        state.instances.forEach(function (instance) {
            var active = String(state.channelId) === String(instance.id);
            var status = ['connected', 'attention', 'disconnected', 'error'].indexOf(instance.status) >= 0 ? instance.status : 'disconnected';
            html += '<button class="impulso-channel-item' + (active ? ' active' : '') + '" type="button" role="tab" aria-selected="' + (active ? 'true' : 'false') + '" data-channel-filter="' + instance.id + '" data-channel-label="' + escapeHtml(instance.name) + '" title="' + escapeHtml(instance.name + (instance.phone ? ' · ' + instance.phone : '')) + '">' +
                '<span class="impulso-channel-icon status-' + status + '"><i data-feather="message-circle"></i><span class="impulso-channel-status-dot" aria-hidden="true"></span></span>' +
                '<span class="impulso-channel-copy"><strong>' + escapeHtml(instance.name) + '</strong><small>' + instance.conversation_count + ' conversa' + (instance.conversation_count === 1 ? '' : 's') + '</small></span>' +
                (instance.unread_count > 0 ? '<span class="impulso-channel-unread">' + instance.unread_count + '</span>' : '') + '</button>';
            options += '<option value="' + instance.id + '"' + (active ? ' selected' : '') + '>' + escapeHtml(instance.name) + '</option>';
        });
        list.innerHTML = html;
        mobile.innerHTML = options;
        var count = document.querySelector('.impulso-channel-header .impulso-count-badge');
        if (count) count.textContent = String(state.instances.length);
        bindChannelButtons();
        replaceIcons();
    }

    function conversationItemHtml(conversation) {
        var active = Number(conversation.id) === Number(state.activeConversationId);
        return '<button class="impulso-conversation-item' + (active ? ' active' : '') + '" type="button" data-conversation-id="' + conversation.id + '" data-status="' + escapeHtml(conversation.status) + '" data-instance-id="' + conversation.instance_id + '">' +
            '<div class="impulso-conversation-line"><div class="impulso-avatar">' + escapeHtml(conversation.avatar) + '</div><div class="impulso-conversation-copy">' +
            '<div class="impulso-conversation-title"><strong>' + escapeHtml(conversation.name) + '</strong><span class="impulso-conversation-time">' + escapeHtml(conversationTime(conversation.last_activity_at)) + '</span></div>' +
            '<div class="impulso-conversation-preview">' + escapeHtml(conversation.last_message || 'Sem mensagens') + '</div><div class="impulso-conversation-meta">' +
            '<span class="impulso-instance-mini"><i data-feather="smartphone"></i> ' + escapeHtml(conversation.instance) + '</span>' +
            (conversation.unread > 0 ? '<span class="impulso-unread">' + conversation.unread + '</span>' : '') + '</div></div></div></button>';
    }

    function renderConversationList() {
        var list = document.getElementById('impulso-conversation-list');
        if (!list) return;
        var html = state.conversations.map(conversationItemHtml).join('');
        if (!state.conversations.length) {
            html = '<div class="impulso-conversation-empty"><div class="impulso-empty-icon"><i data-feather="inbox"></i></div><strong>Nenhuma conversa encontrada</strong><span>As conversas aparecerão após a sincronização ou o primeiro webhook.</span></div>';
        }
        html += '<div class="impulso-conversation-empty' + (state.hasMore ? '' : ' impulso-hidden') + '" id="impulso-conversation-load-more"><button class="btn btn-default btn-sm" type="button">Carregar mais</button></div>';
        list.innerHTML = html;
        setText('impulso-visible-conversation-count', state.conversations.length, '0');
        list.querySelectorAll('[data-conversation-id]').forEach(function (button) {
            button.addEventListener('click', function () { selectConversation(Number(this.getAttribute('data-conversation-id'))); });
        });
        var more = document.querySelector('#impulso-conversation-load-more button');
        if (more) more.addEventListener('click', function () { loadConversations(false); });
        replaceIcons();
    }

    function conversationContext() {
        return [String(state.channelId), state.status, state.search].join('|');
    }

    function conversationQuery(page, limit, filters) {
        filters = filters || {};
        var params = new URLSearchParams();
        params.set('page', String(page || 1));
        params.set('limit', String(limit || config.conversationPageSize || 30));
        if (filters.channelId !== 'all') params.set('instance_id', String(filters.channelId));
        if (filters.status !== 'all') params.set('status', filters.status);
        if (filters.search) params.set('search', filters.search);
        return params.toString();
    }

    function loadConversations(reset, silent) {
        if (!endpoint('conversations') || runtime.destroyed) return Promise.resolve();
        var requestContext = conversationContext();
        if (state.listLoading && state.listRequestContext === requestContext) return Promise.resolve();
        var requestId = ++state.listRequestSequence;
        state.listLoading = true;
        state.listRequestContext = requestContext;
        var filters = { channelId: state.channelId, status: state.status, search: state.search };
        var pageSize = Number(config.conversationPageSize || 30);
        var requestedPage = reset ? 1 : state.page + 1;
        var loadedPageCount = Math.max(1, Math.ceil(state.conversations.length / pageSize));
        var maxSilentPages = Math.max(1, Math.floor(100 / pageSize));
        var requestedLimit = reset && silent
            ? pageSize * Math.min(maxSilentPages, loadedPageCount)
            : pageSize;
        var syncKey = String(filters.channelId);
        var syncRemote = !!(reset && !silent && !state.syncedChannels[syncKey]);
        var list = document.getElementById('impulso-conversation-list');
        if (!silent && reset && list) {
            list.innerHTML = '<div class="impulso-conversation-empty"><div class="spinner-border spinner-border-sm" role="status"></div><strong>Carregando conversas</strong><span>Consultando dados do atendimento.</span></div>';
        }
        var remoteSync = Promise.resolve();
        if (syncRemote) {
            var syncBody = {};
            if (filters.channelId !== 'all') syncBody.instance_id = Number(filters.channelId);
            remoteSync = api(endpoint('conversations').replace(/\/$/, '') + '/sync', {
                method: 'POST',
                body: syncBody
            }).then(function (payload) {
                var syncResult = payload && payload.data ? payload.data : {};
                if (Number(syncResult.errors || 0) > 0) {
                    if (!silent) showToast('Sincronização parcial', 'Um ou mais canais não puderam ser atualizados; os dados locais foram mantidos.', 'alert-triangle');
                } else {
                    state.syncedChannels[syncKey] = true;
                }
            }).catch(function (error) {
                if (!silent) showToast('Sincronização indisponível', error.message + ' Exibindo os dados locais.', 'alert-triangle');
            });
        }
        return remoteSync.then(function () {
            return api(endpoint('conversations') + '?' + conversationQuery(requestedPage, requestedLimit, filters));
        }).then(function (payload) {
            if (requestId !== state.listRequestSequence || requestContext !== conversationContext()) return;
            var rows = Array.isArray(payload.data) ? payload.data.map(normalizeConversation) : [];
            if (reset) {
                if (silent) {
                    var refreshed = {};
                    rows.forEach(function (item) { refreshed[item.id] = true; });
                    state.conversations = rows.concat(state.conversations.filter(function (item) { return !refreshed[item.id]; }));
                } else {
                    state.conversations = rows;
                }
            } else {
                var known = {};
                state.conversations.forEach(function (item) { known[item.id] = true; });
                rows.forEach(function (item) { if (!known[item.id]) state.conversations.push(item); });
            }
            state.conversations.sort(function (left, right) {
                return dateValue(right.last_activity_at).getTime() - dateValue(left.last_activity_at).getTime() || Number(right.id) - Number(left.id);
            });
            state.page = reset && silent ? Math.max(1, state.page) : requestedPage;
            state.hasMore = !!(payload.meta && (silent && payload.meta.total != null
                ? Number(payload.meta.total) > state.conversations.length
                : payload.meta.has_more));
            if (state.activeConversationId && !activeConversation() && reset) state.activeConversationId = null;
            renderConversationList();
            if (state.activeConversationId && activeConversation()) applyActiveConversation(activeConversation());
            if (!state.activeConversationId && state.conversations.length) selectConversation(state.conversations[0].id, true);
            if (!state.conversations.length) clearConversation();
        }).catch(function (error) {
            if (!silent && requestId === state.listRequestSequence && requestContext === conversationContext()) {
                if (list) list.innerHTML = '<div class="impulso-conversation-empty"><div class="impulso-empty-icon"><i data-feather="alert-triangle"></i></div><strong>Falha ao carregar conversas</strong><span>' + escapeHtml(error.message) + '</span><button class="btn btn-default btn-sm" id="impulso-retry-conversations" type="button">Tentar novamente</button></div>';
                var retry = document.getElementById('impulso-retry-conversations');
                if (retry) retry.addEventListener('click', function () { loadConversations(true); });
                replaceIcons();
            }
        }).finally(function () {
            if (requestId === state.listRequestSequence) state.listLoading = false;
        });
    }

    function loadInstances(silent) {
        if (!endpoint('instances') || state.instanceLoading || runtime.destroyed) return Promise.resolve();
        state.instanceLoading = true;
        return api(endpoint('instances')).then(function (payload) {
            state.instances = Array.isArray(payload.data) ? payload.data.map(normalizeInstance) : [];
            if (state.channelId !== 'all' && !selectedInstance()) state.channelId = 'all';
            renderChannels();
        }).catch(function (error) {
            if (!silent) showToast('Falha nos canais', error.message, 'alert-triangle');
        }).finally(function () { state.instanceLoading = false; });
    }

    function refreshInstancesSurface(silent) {
        if (app.getAttribute('data-active-tab') === 'instances') {
            window.location.reload();
            return Promise.resolve();
        }
        return loadInstances(silent);
    }

    function persistChannel() {
        try { window.localStorage.setItem('impulso_hub_channel_id', String(state.channelId)); } catch (error) { /* noop */ }
    }

    function activateChannel(value, label) {
        state.channelId = value === 'all' ? 'all' : String(Number(value));
        persistChannel();
        setText('impulso-current-channel', label || (selectedInstance() ? selectedInstance().name : 'Todos os canais'));
        renderChannels();
        state.activeConversationId = null;
        loadConversations(true);
    }

    function bindChannelButtons() {
        document.querySelectorAll('[data-channel-filter]').forEach(function (button) {
            button.addEventListener('click', function () { activateChannel(this.getAttribute('data-channel-filter'), this.getAttribute('data-channel-label')); });
        });
    }

    function restoreChannel() {
        var saved = 'all';
        try { saved = window.localStorage.getItem('impulso_hub_channel_id') || 'all'; } catch (error) { /* noop */ }
        state.channelId = saved === 'all' || state.instances.some(function (item) { return String(item.id) === saved; }) ? saved : 'all';
    }

    function clearConversation() {
        state.activeConversationId = null;
        state.messages = [];
        state.messageAfterId = 0;
        state.hasMoreBefore = false;
        document.querySelectorAll('.impulso-conversation-item').forEach(function (item) { item.classList.remove('active'); });
        setText('impulso-active-avatar', '—');
        setText('impulso-active-name', 'Nenhuma conversa selecionada');
        setText('impulso-active-instance', selectedInstance() ? selectedInstance().name : 'Todos os canais');
        var body = document.getElementById('impulso-chat-body');
        if (body) body.innerHTML = '<div class="impulso-empty"><div class="impulso-empty-icon"><i data-feather="message-circle"></i></div><h4>Sem conversa para exibir</h4><p>Selecione um canal ou aguarde a chegada de uma mensagem.</p></div>';
        updateComposerState();
        replaceIcons();
    }

    function applyActiveConversation(conversation) {
        if (!conversation) return;
        setText('impulso-active-avatar', conversation.avatar);
        setText('impulso-active-name', conversation.name);
        setText('impulso-active-instance', conversation.instance);
        setText('impulso-active-connection', conversation.instance_status === 'connected' ? 'WhatsApp conectado' : 'Canal indisponível');
        setText('impulso-contact-avatar', conversation.avatar);
        setText('impulso-contact-name', conversation.name);
        setText('impulso-contact-phone', conversation.phone);
        setText('impulso-contact-assignee', conversation.assignee);
        setText('impulso-contact-team', conversation.team);
        setText('impulso-contact-instance', conversation.instance);
        setText('impulso-contact-email', conversation.email);
        setText('impulso-contact-city', conversation.city);
        setText('impulso-contact-source', conversation.source);
        setText('impulso-contact-created', conversation.created_at ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(dateValue(conversation.created_at)) : 'Não informado');
        var tags = document.getElementById('impulso-contact-tags');
        if (tags) tags.innerHTML = conversation.tags.map(function (tag) { return '<span class="impulso-badge primary">' + escapeHtml(tag) + '</span>'; }).join('');
        updateComposerState();
    }

    function selectConversation(id, silent) {
        var conversation = state.conversations.find(function (item) { return Number(item.id) === Number(id); });
        if (!conversation) return;
        if (Number(state.activeConversationId) === Number(conversation.id) && (state.messages.length || state.messageLoading)) {
            applyActiveConversation(conversation);
            return;
        }
        state.activeConversationId = conversation.id;
        state.messages = [];
        state.messageAfterId = 0;
        state.hasMoreBefore = false;
        document.querySelectorAll('.impulso-conversation-item').forEach(function (item) {
            item.classList.toggle('active', Number(item.getAttribute('data-conversation-id')) === Number(id));
        });
        applyActiveConversation(conversation);
        loadMessages('reset', !silent);
        markConversationRead(conversation);
        var sidebar = document.getElementById('impulso-chat-sidebar');
        if (sidebar && window.innerWidth <= 840) sidebar.classList.remove('open');
    }

    function sameMessage(left, right) {
        if (!left || !right) return false;
        if (left.external_message_id && right.external_message_id && left.external_message_id === right.external_message_id) return true;
        if (left.client_message_id && right.client_message_id && left.client_message_id === right.client_message_id) return true;
        var leftId = Number(left.id || 0);
        var rightId = Number(right.id || 0);
        return isFinite(leftId) && isFinite(rightId) && leftId > 0 && leftId === rightId;
    }

    function statusRank(status) {
        return ({ failed: -1, pending: 0, sending: 0, received: 1, sent: 1, delivered: 2, read: 3 })[String(status || '').toLowerCase()] || 0;
    }

    function mergeMessageRecord(target, source) {
        var previous = {
            external_message_id: target.external_message_id,
            client_message_id: target.client_message_id,
            status: target.status,
            temporary: target.temporary
        };
        Object.assign(target, source);
        if (!target.external_message_id) target.external_message_id = previous.external_message_id;
        if (!target.client_message_id) target.client_message_id = previous.client_message_id;
        if (statusRank(previous.status) > statusRank(target.status)) target.status = previous.status;
        target.temporary = !!(previous.temporary && source.temporary);
        return target;
    }

    function compactMessages() {
        var compact = [];
        state.messages.forEach(function (message) {
            var existingIndex = compact.findIndex(function (item) { return sameMessage(item, message); });
            if (existingIndex < 0) compact.push(message);
            else mergeMessageRecord(compact[existingIndex], message);
        });
        var merged;
        do {
            merged = false;
            for (var left = 0; left < compact.length && !merged; left += 1) {
                for (var right = left + 1; right < compact.length; right += 1) {
                    if (sameMessage(compact[left], compact[right])) {
                        mergeMessageRecord(compact[left], compact[right]);
                        compact.splice(right, 1);
                        merged = true;
                        break;
                    }
                }
            }
        } while (merged);
        state.messages = compact;
    }

    function mergeMessages(rows, prepend) {
        rows.forEach(function (message) {
            var localId = Number(message.id || 0);
            if (isFinite(localId) && localId > state.messageAfterId) state.messageAfterId = localId;
            var existing = state.messages.find(function (item) { return sameMessage(item, message); });
            if (existing) mergeMessageRecord(existing, message);
            else if (prepend) state.messages.unshift(message);
            else state.messages.push(message);
        });
        compactMessages();
        state.messages.sort(function (a, b) {
            var providerTime = Number(a.message_timestamp || 0) - Number(b.message_timestamp || 0);
            if (providerTime) return providerTime;
            var preciseTime = dateValue(a.sent_at).getTime() - dateValue(b.sent_at).getTime();
            if (preciseTime) return preciseTime;
            var leftId = Number(a.id || 0);
            var rightId = Number(b.id || 0);
            if (isFinite(leftId) && isFinite(rightId) && leftId > 0 && rightId > 0) return leftId - rightId;
            return String(a.client_message_id || a.external_message_id || '').localeCompare(String(b.client_message_id || b.external_message_id || ''));
        });
    }

    function mediaHtml(message) {
        var url = message.media_url;
        if (message.message_type === 'image') {
            return url ? '<button class="impulso-media-button" type="button" data-media-kind="image" data-media-url="' + escapeHtml(url) + '"><img class="impulso-message-image" src="' + escapeHtml(url) + '" alt="Imagem da conversa" loading="lazy" referrerpolicy="no-referrer"></button>' : '<div class="impulso-media-card"><span class="impulso-media-icon"><i data-feather="image"></i></span><div><strong>Imagem recebida</strong><span>Mídia indisponível para visualização.</span></div></div>';
        }
        if (message.message_type === 'audio') {
            return url ? '<div class="impulso-audio-message"><audio class="impulso-message-audio" controls preload="metadata" src="' + escapeHtml(url) + '"></audio><button class="impulso-media-open" type="button" data-media-kind="audio" data-media-url="' + escapeHtml(url) + '" title="Abrir áudio"><i data-feather="maximize-2"></i></button></div>' : '<div class="impulso-media-card"><span class="impulso-media-icon"><i data-feather="volume-2"></i></span><div><strong>Áudio recebido</strong><span>Mídia indisponível para reprodução.</span></div></div>';
        }
        if (message.message_type === 'document') {
            return '<button class="impulso-media-card impulso-media-document" type="button"' + (url ? ' data-media-kind="document" data-media-url="' + escapeHtml(url) + '"' : ' disabled') + '><span class="impulso-media-icon"><i data-feather="file-text"></i></span><span><strong>' + escapeHtml(message.file_name || 'Documento') + '</strong><small>' + (url ? 'Visualizar ou baixar arquivo' : 'Arquivo indisponível') + '</small></span><i data-feather="external-link"></i></button>';
        }
        if (message.message_type === 'video') {
            return url ? '<button class="impulso-media-button" type="button" data-media-kind="video" data-media-url="' + escapeHtml(url) + '"><video class="impulso-message-video" controls preload="metadata" src="' + escapeHtml(url) + '"></video></button>' : '';
        }
        return '';
    }

    function statusIcon(message) {
        if (message.direction !== 'outgoing') return '';
        if (message.status === 'failed') return '<i data-feather="alert-circle"></i>';
        if (message.status === 'sending') return '<i data-feather="clock"></i>';
        return '<i data-feather="check' + (message.status === 'read' ? '-circle' : '') + '"></i>';
    }

    function messageHtml(message) {
        var direction = message.direction === 'outgoing' ? 'outgoing' : message.direction === 'internal' ? 'internal' : 'incoming';
        var retry = canSendMessages() && message.status === 'failed' && message.client_message_id ? '<button class="impulso-message-retry" type="button" data-retry-message="' + escapeHtml(message.client_message_id) + '">Tentar novamente</button>' : '';
        return '<div class="impulso-message-row ' + direction + (message.is_internal_note ? ' is-note' : '') + (message.status === 'failed' ? ' is-failed' : '') + '" data-message-id="' + escapeHtml(message.id || message.external_message_id || '') + '" data-message-search="' + escapeHtml(String(message.text_content || '').toLowerCase()) + '"><div class="impulso-message">' + mediaHtml(message) +
            (message.text_content ? '<p>' + escapeHtml(message.text_content).replace(/\n/g, '<br>') + '</p>' : '') + retry +
            '<div class="impulso-message-footer"><span>' + escapeHtml(messageTime(message.sent_at)) + '</span>' + statusIcon(message) + '</div></div></div>';
    }

    function renderMessages(options) {
        options = options || {};
        var body = document.getElementById('impulso-chat-body');
        if (!body) return;
        var oldHeight = body.scrollHeight;
        var oldTop = body.scrollTop;
        var nearBottom = oldHeight - oldTop - body.clientHeight < 90;
        var html = '';
        var lastDay = '';
        state.messages.forEach(function (message) {
            var day = dayLabel(message.sent_at);
            if (day !== lastDay) {
                html += '<div class="impulso-day-divider">' + escapeHtml(day) + '</div>';
                lastDay = day;
            }
            html += messageHtml(message);
        });
        if (!state.messages.length) html = '<div class="impulso-empty"><div class="impulso-empty-icon"><i data-feather="message-square"></i></div><h4>Histórico vazio</h4><p>Envie a primeira mensagem desta conversa.</p></div>';
        if (state.hasMoreBefore) html = '<div class="impulso-load-older"><button class="btn btn-default btn-sm" id="impulso-load-older-messages" type="button">Carregar mensagens anteriores</button></div>' + html;
        body.innerHTML = html;
        var older = document.getElementById('impulso-load-older-messages');
        if (older) older.addEventListener('click', function () { loadMessages('before', true); });
        body.querySelectorAll('[data-retry-message]').forEach(function (button) {
            button.addEventListener('click', function () { retryMessage(this.getAttribute('data-retry-message')); });
        });
        replaceIcons();
        if (options.prepend) body.scrollTop = body.scrollHeight - oldHeight + oldTop;
        else if (options.forceBottom || nearBottom) body.scrollTop = body.scrollHeight;
    }

    function loadMessages(mode, showLoading) {
        var conversation = activeConversation();
        if (!conversation || !endpoint('conversations') || runtime.destroyed) return Promise.resolve();
        var requestContext = String(conversation.id);
        if (state.messageLoading && state.messageRequestContext === requestContext) return Promise.resolve();
        var requestId = ++state.messageRequestSequence;
        state.messageLoading = true;
        state.messageRequestContext = requestContext;
        var params = new URLSearchParams();
        params.set('limit', String(config.messagePageSize || 50));
        if (mode === 'before' && state.messages.length) {
            var firstPersisted = state.messages.find(function (message) {
                var localId = Number(message.id || 0);
                return isFinite(localId) && localId > 0;
            });
            if (firstPersisted) {
                params.set('before_id', String(firstPersisted.id));
                if (firstPersisted.message_timestamp) params.set('before_timestamp', String(firstPersisted.message_timestamp));
            }
        }
        if (mode === 'after' && state.messageAfterId > 0) params.set('after_id', String(state.messageAfterId));
        var body = document.getElementById('impulso-chat-body');
        if (showLoading && mode === 'reset' && body) body.innerHTML = '<div class="impulso-empty"><div class="spinner-border spinner-border-sm" role="status"></div><p>Carregando histórico...</p></div>';
        var requestedConversationId = conversation.id;
        var messageUrl = endpointWithId('conversations', conversation.id, '/messages');
        var requestOptions = {};
        if (mode === 'reset' || mode === 'refresh') {
            messageUrl += '/sync';
            requestOptions = { method: 'POST', body: { limit: Number(config.messagePageSize || 50) } };
        } else {
            messageUrl += '?' + params.toString();
        }
        return api(messageUrl, requestOptions).then(function (payload) {
            if (requestId !== state.messageRequestSequence || Number(state.activeConversationId) !== Number(requestedConversationId)) return;
            if (mode === 'reset' && payload.meta && payload.meta.sync_error) {
                showToast('Histórico local', payload.meta.sync_error, 'alert-triangle');
            }
            var rows = Array.isArray(payload.data) ? payload.data.map(normalizeMessage) : [];
            if (mode === 'reset') {
                state.messages = [];
                state.messageAfterId = 0;
            }
            if (mode === 'reset' || mode === 'before') {
                state.hasMoreBefore = !!(payload.meta && payload.meta.has_more_before);
            }
            mergeMessages(rows, mode === 'before');
            renderMessages({
                prepend: mode === 'before',
                forceBottom: mode === 'reset'
            });
        }).catch(function (error) {
            if (requestId !== state.messageRequestSequence || Number(state.activeConversationId) !== Number(requestedConversationId)) return;
            if (showLoading && mode === 'reset' && body) {
                body.innerHTML = '<div class="impulso-empty"><div class="impulso-empty-icon"><i data-feather="alert-triangle"></i></div><h4>Falha ao carregar histórico</h4><p>' + escapeHtml(error.message) + '</p><button class="btn btn-default btn-sm" id="impulso-retry-messages" type="button">Tentar novamente</button></div>';
                var retry = document.getElementById('impulso-retry-messages');
                if (retry) retry.addEventListener('click', function () { loadMessages('reset', true); });
                replaceIcons();
            } else if (mode !== 'refresh') {
                showToast('Falha no histórico', error.message + ' As mensagens já carregadas foram mantidas.', 'alert-triangle');
            }
        }).finally(function () {
            if (requestId === state.messageRequestSequence) state.messageLoading = false;
        });
    }

    function markConversationRead(conversation) {
        if (!conversation || !conversation.unread) return;
        api(endpointWithId('conversations', conversation.id, '/read'), { method: 'POST', body: {} }).then(function () {
            conversation.unread = 0;
            renderConversationList();
            loadInstances(true);
        }).catch(function () { /* polling will reconcile */ });
    }

    function createClientMessageId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
        return 'web-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    function canSendMessages() {
        return !config.permissions || config.permissions.send === true;
    }

    function updateComposerState() {
        var conversation = activeConversation();
        var input = document.getElementById('impulso-message-input');
        var button = document.getElementById('impulso-send-message');
        var canSend = canSendMessages();
        var disabled = !canSend || !conversation || conversation.instance_status !== 'connected' || state.sending;
        if (input) input.disabled = disabled;
        if (button) button.disabled = disabled;
        var hint = document.getElementById('impulso-composer-hint');
        if (hint) hint.textContent = !canSend ? 'Seu perfil não possui permissão para enviar mensagens.' : (!conversation ? 'Selecione uma conversa para responder.' : (conversation.instance_status !== 'connected' ? 'A instância está desconectada; o envio foi bloqueado.' : 'Enter para enviar · Shift + Enter para quebrar linha'));
    }

    function sendMessage(text, clientId, existing) {
        var conversation = activeConversation();
        if (!conversation || state.sending) return;
        if (!canSendMessages()) {
            showToast('Envio não permitido', 'Seu perfil não possui permissão para enviar mensagens.', 'shield');
            return;
        }
        text = String(text || '').trim();
        if (!text) {
            showToast('Mensagem vazia', 'Digite algum conteúdo antes de enviar.', 'alert-circle');
            return;
        }
        if (conversation.instance_status !== 'connected') {
            showToast('Canal desconectado', 'A mensagem não pode ser enviada por esta instância.', 'wifi-off');
            return;
        }
        var input = document.getElementById('impulso-message-input');
        clientId = clientId || createClientMessageId();
        var optimistic = existing || normalizeMessage({
            id: clientId,
            client_message_id: clientId,
            direction: 'outgoing',
            message_type: 'text',
            text_content: text,
            status: 'sending',
            sent_at: new Date().toISOString(),
            message_timestamp: Math.floor(Date.now() / 1000),
            temporary: true
        });
        optimistic.status = 'sending';
        if (!existing) state.messages.push(optimistic);
        if (input) input.value = '';
        state.sending = true;
        updateComposerState();
        renderMessages({ forceBottom: true });
        var requestedConversationId = conversation.id;
        api(endpointWithId('conversations', conversation.id, '/messages'), {
            method: 'POST',
            body: { text: text, client_message_id: clientId }
        }).then(function (payload) {
            var real = normalizeMessage(payload.data || {});
            conversation.last_message = real.text_content;
            conversation.last_activity_at = real.sent_at;
            renderConversationList();
            if (Number(state.activeConversationId) !== Number(requestedConversationId)) return;
            var index = state.messages.indexOf(optimistic);
            if (index >= 0) state.messages.splice(index, 1);
            mergeMessages([real], false);
            renderMessages({ forceBottom: true });
        }).catch(function (error) {
            if (Number(state.activeConversationId) !== Number(requestedConversationId)) return;
            optimistic.status = 'failed';
            optimistic.temporary = false;
            renderMessages({ forceBottom: true });
            showToast('Falha no envio', error.message, 'alert-triangle');
        }).finally(function () {
            state.sending = false;
            updateComposerState();
            if (input) input.focus();
        });
    }

    function retryMessage(clientId) {
        var message = state.messages.find(function (item) { return item.client_message_id === clientId; });
        if (message) sendMessage(message.text_content, clientId, message);
    }

    function syncPollingChannel() {
        if (state.pollingChannelSyncing || !endpoint('conversations')) return Promise.resolve();
        var candidates = state.instances.filter(function (instance) {
            return instance.active && instance.status === 'connected';
        });
        if (state.channelId !== 'all') {
            candidates = candidates.filter(function (instance) {
                return String(instance.id) === String(state.channelId);
            });
        }
        if (!candidates.length) return Promise.resolve();
        var instance = candidates[state.pollingInstanceIndex % candidates.length];
        state.pollingInstanceIndex = (state.pollingInstanceIndex + 1) % candidates.length;
        state.pollingChannelSyncing = true;
        return api(endpoint('conversations').replace(/\/$/, '') + '/sync', {
            method: 'POST',
            body: { instance_id: Number(instance.id) }
        }).catch(function () {
            /* A leitura local continua disponivel; o proximo ciclo tenta novamente. */
        }).finally(function () {
            state.pollingChannelSyncing = false;
        });
    }

    function poll() {
        if (runtime.destroyed) return;
        if (!document.hidden && app.getAttribute('data-active-tab') === 'conversations') {
            loadInstances(true);
            var refresh = state.activeConversationId
                ? loadMessages('refresh', false)
                : Promise.resolve();
            refresh
                .then(syncPollingChannel)
                .then(function () { return loadConversations(true, true); });
        }
        schedulePoll();
    }

    function schedulePoll() {
        if (runtime.destroyed) return;
        if (state.pollingTimer) window.clearTimeout(state.pollingTimer);
        var interval = Math.max(3000, Math.min(60000, Number(config.pollingIntervalMs || 5000)));
        state.pollingTimer = window.setTimeout(poll, interval);
        runtime.timers.push(state.pollingTimer);
    }

    function bindConversationControls() {
        var mobile = document.getElementById('impulso-mobile-channel-filter');
        if (mobile) mobile.addEventListener('change', function () {
            var option = this.options[this.selectedIndex];
            activateChannel(this.value, option ? option.textContent : 'Todos os canais');
        });
        document.querySelectorAll('[data-conversation-filter]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.querySelectorAll('[data-conversation-filter]').forEach(function (item) { item.classList.remove('active'); });
                this.classList.add('active');
                state.status = this.getAttribute('data-conversation-filter') || 'all';
                state.activeConversationId = null;
                loadConversations(true);
            });
        });
        var search = document.getElementById('impulso-conversation-search');
        if (search) search.addEventListener('input', function () {
            state.search = this.value.trim();
            if (state.searchTimer) window.clearTimeout(state.searchTimer);
            state.searchTimer = window.setTimeout(function () { state.activeConversationId = null; loadConversations(true); }, 320);
            runtime.timers.push(state.searchTimer);
        });
        var send = document.getElementById('impulso-send-message');
        var input = document.getElementById('impulso-message-input');
        if (send) send.addEventListener('click', function () { sendMessage(input ? input.value : ''); });
        if (input) input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMessage(this.value);
            }
        });
        document.querySelectorAll('[data-composer-mode]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.querySelectorAll('[data-composer-mode]').forEach(function (item) { item.classList.toggle('active', item === button); });
            });
        });
    }

    function fieldValue(id) {
        var element = document.getElementById(id);
        return element ? String(element.value || '').trim() : '';
    }

    function setField(id, value) {
        var element = document.getElementById(id);
        if (element) element.value = value == null ? '' : String(value);
    }

    function openInstanceModal(id) {
        var instance = state.instances.find(function (item) { return Number(item.id) === Number(id); }) || null;
        setField('impulso-instance-id', instance ? instance.id : '');
        setField('impulso-instance-name', instance ? instance.name : '');
        setField('impulso-instance-technical-name', instance ? instance.evolution_instance_name : '');
        setField('impulso-instance-identifier', instance ? instance.internal_identifier : '');
        setField('impulso-instance-base-url', instance ? instance.base_url : '');
        setField('impulso-instance-phone', instance ? instance.phone : '');
        setField('impulso-instance-api-key', '');
        var clearApiKey = document.getElementById('impulso-instance-clear-api-key');
        if (clearApiKey) {
            clearApiKey.checked = false;
            clearApiKey.disabled = !instance || !instance.has_api_key;
        }
        var active = document.getElementById('impulso-instance-active');
        if (active) active.checked = instance ? instance.active : true;
        setText('impulso-instance-modal-title', instance ? 'Editar instância Evolution' : 'Nova instância Evolution');
        var modal = document.getElementById('impulso-instance-modal');
        if (modal && window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    function saveInstance(button) {
        var id = Number(fieldValue('impulso-instance-id') || 0);
        var active = document.getElementById('impulso-instance-active');
        var clearApiKey = document.getElementById('impulso-instance-clear-api-key');
        var body = {
            name: fieldValue('impulso-instance-name'),
            evolution_instance_name: fieldValue('impulso-instance-technical-name'),
            internal_identifier: fieldValue('impulso-instance-identifier'),
            phone_number: fieldValue('impulso-instance-phone'),
            api_key: fieldValue('impulso-instance-api-key'),
            clear_api_key: clearApiKey && clearApiKey.checked ? 1 : 0,
            active: active && active.checked ? 1 : 0
        };
        if (config.permissions && config.permissions.manageSettings) {
            body.base_url = fieldValue('impulso-instance-base-url');
        }
        button.disabled = true;
        api(id ? endpointWithId('instances', id) : endpoint('instances'), { method: 'POST', body: body }).then(function () {
            closeModal(button);
            showToast('Instância salva', 'A configuração foi armazenada com segurança.', 'check-circle');
            return refreshInstancesSurface();
        }).catch(function (error) {
            showToast('Falha ao salvar', error.message, 'alert-triangle');
        }).finally(function () { button.disabled = false; });
    }

    function testInstance(id, button) {
        if (!id) return;
        if (button) button.disabled = true;
        api(endpointWithId('instances', id, '/status'), { method: 'POST', body: {} }).then(function (payload) {
            var data = payload.data || {};
            showToast(data.status === 'connected' ? 'Instância conectada' : 'Estado atualizado', data.message || ('Status: ' + (data.status || 'desconhecido')), data.status === 'connected' ? 'wifi' : 'alert-circle');
            return refreshInstancesSurface(true);
        }).catch(function (error) {
            showToast('Falha na conexão', error.message, 'alert-triangle');
        }).finally(function () { if (button) button.disabled = false; });
    }

    function refreshAllInstances(button) {
        if (button) button.disabled = true;
        api(endpoint('instancesRefresh'), { method: 'POST', body: {} }).then(function (payload) {
            showToast('Verificação concluída', (payload.data || []).length + ' instância(s) consultada(s).', 'refresh-cw');
            return refreshInstancesSurface(true);
        }).catch(function (error) {
            showToast('Falha na verificação', error.message, 'alert-triangle');
        }).finally(function () { if (button) button.disabled = false; });
    }

    function applyInitialSettings() {
        var values = {
            'impulso-setting-module-name': initialSettings.module_name || 'Impulso Hub',
            'impulso-setting-timezone': initialSettings.timezone || 'America/Sao_Paulo',
            'impulso-setting-polling': initialSettings.polling_interval_ms || 5000,
            'impulso-setting-page-size': initialSettings.conversation_page_size || 30,
            'impulso-setting-default-status': initialSettings.default_status || 'open',
            'impulso-setting-default-priority': initialSettings.default_priority || 'normal',
            'impulso-setting-sla-minutes': initialSettings.sla_minutes || 30,
            'impulso-setting-auto-resolve-hours': initialSettings.auto_resolve_hours || 0,
            'impulso-setting-base-url': initialSettings.evolution_base_url || '',
            'impulso-setting-timeout': initialSettings.request_timeout_seconds || 30,
            'impulso-setting-evolution-retries': initialSettings.evolution_retries == null ? 2 : initialSettings.evolution_retries,
            'impulso-setting-status-path': initialSettings.connection_status_path || '/instance/connectionState/{instance}',
            'impulso-setting-chats-path': initialSettings.find_chats_path || '/chat/findChats/{instance}',
            'impulso-setting-messages-path': initialSettings.find_messages_path || '/chat/findMessages/{instance}',
            'impulso-setting-send-path': initialSettings.send_text_path || '/message/sendText/{instance}',
            'impulso-setting-send-media-path': initialSettings.send_media_path || '/message/sendMedia/{instance}',
            'impulso-setting-send-audio-path': initialSettings.send_audio_path || '/message/sendWhatsAppAudio/{instance}',
            'impulso-setting-media-base64-path': initialSettings.get_media_base64_path || '/chat/getBase64FromMediaMessage/{instance}',
            'impulso-setting-n8n-base-url': initialSettings.n8n_base_url || '',
            'impulso-setting-n8n-auth-mode': initialSettings.n8n_auth_mode || 'bearer',
            'impulso-setting-n8n-header-name': initialSettings.n8n_header_name || 'X-API-Key',
            'impulso-setting-n8n-timeout': initialSettings.n8n_timeout_seconds || 30,
            'impulso-setting-n8n-health-path': initialSettings.n8n_health_path || '/healthz',
            'impulso-setting-n8n-campaigns-path': initialSettings.n8n_campaigns_path || '/webhook/campanha',
            'impulso-setting-n8n-ai-path': initialSettings.n8n_ai_path || '/webhook/iara/control',
            'impulso-setting-n8n-events-path': initialSettings.n8n_events_path || '/webhook/impulso/events',
            'impulso-setting-campaign-start': initialSettings.campaign_window_start || '08:00',
            'impulso-setting-campaign-end': initialSettings.campaign_window_end || '20:00',
            'impulso-setting-campaign-batch-size': initialSettings.campaign_batch_size || 20,
            'impulso-setting-campaign-min-interval': initialSettings.campaign_min_interval_seconds || 8,
            'impulso-setting-campaign-pause-errors': initialSettings.campaign_pause_after_errors || 5,
            'impulso-setting-campaign-optout': initialSettings.campaign_optout_text || '',
            'impulso-setting-quick-replies': initialSettings.quick_replies_json || '',
            'impulso-setting-ai-default-state': initialSettings.ai_default_state || 'running',
            'impulso-setting-ai-stop-command': initialSettings.ai_stop_command || '@stop',
            'impulso-setting-ai-start-command': initialSettings.ai_start_command || '@start',
            'impulso-setting-ai-return-minutes': initialSettings.ai_auto_return_minutes || 0,
            'impulso-setting-webhook-retention': initialSettings.webhook_retention_days || 30,
            'impulso-setting-audit-retention': initialSettings.audit_retention_days || 180,
            'impulso-setting-conversation-retention': initialSettings.conversation_retention_days || 0,
            'impulso-setting-media-retention': initialSettings.media_retention_days || 30
        };
        Object.keys(values).forEach(function (id) { setField(id, values[id]); });
        setField('impulso-setting-global-key', '');
        setField('impulso-setting-n8n-token', '');
        setField('impulso-setting-webhook-secret', '');
        var flags = {
            'impulso-setting-sound': initialSettings.sound_enabled !== false && initialSettings.sound_enabled !== 0 && initialSettings.sound_enabled !== '0',
            'impulso-setting-browser-notifications': !!initialSettings.browser_notifications_enabled,
            'impulso-setting-auto-read': initialSettings.auto_mark_read !== false && initialSettings.auto_mark_read !== 0 && initialSettings.auto_mark_read !== '0',
            'impulso-setting-ai-human-priority': initialSettings.ai_human_priority !== false && initialSettings.ai_human_priority !== 0 && initialSettings.ai_human_priority !== '0',
            'impulso-setting-ai-show-context': initialSettings.ai_show_context !== false && initialSettings.ai_show_context !== 0 && initialSettings.ai_show_context !== '0',
            'impulso-setting-log-webhooks': initialSettings.log_sanitized_webhooks !== false && initialSettings.log_sanitized_webhooks !== 0 && initialSettings.log_sanitized_webhooks !== '0',
            'impulso-setting-audit-enabled': initialSettings.audit_enabled !== false && initialSettings.audit_enabled !== 0 && initialSettings.audit_enabled !== '0',
            'impulso-setting-secure-media': initialSettings.secure_media !== false && initialSettings.secure_media !== 0 && initialSettings.secure_media !== '0'
            ,'impulso-setting-n8n-private-networks': initialSettings.n8n_allow_private_networks !== false && initialSettings.n8n_allow_private_networks !== 0 && initialSettings.n8n_allow_private_networks !== '0'
            ,'impulso-setting-campaign-optout': initialSettings.campaign_optout_text !== '' && initialSettings.campaign_optout_text !== 0 && initialSettings.campaign_optout_text !== '0'
            ,'impulso-setting-campaign-pause-errors': Number(initialSettings.campaign_pause_after_errors || 0) > 0
        };
        Object.keys(flags).forEach(function (id) { var element = document.getElementById(id); if (element) element.checked = flags[id]; });
        setText('impulso-global-key-mask', initialSettings.global_api_key_masked || 'Não configurada');
        setText('impulso-webhook-secret-mask', initialSettings.webhook_secret_masked || 'Não configurado');
    }

    function checkedValue(id) {
        var element = document.getElementById(id);
        return element && element.checked ? 1 : 0;
    }

    function saveSettings(button) {
        var body = {
            module_name: fieldValue('impulso-setting-module-name'),
            timezone: fieldValue('impulso-setting-timezone'),
            polling_interval_ms: Number(fieldValue('impulso-setting-polling') || 5000),
            conversation_page_size: Number(fieldValue('impulso-setting-page-size') || 30),
            sound_enabled: checkedValue('impulso-setting-sound'),
            browser_notifications_enabled: checkedValue('impulso-setting-browser-notifications'),
            auto_mark_read: checkedValue('impulso-setting-auto-read'),
            default_status: fieldValue('impulso-setting-default-status'),
            default_priority: fieldValue('impulso-setting-default-priority'),
            sla_minutes: Number(fieldValue('impulso-setting-sla-minutes') || 30),
            auto_resolve_hours: Number(fieldValue('impulso-setting-auto-resolve-hours') || 0),
            evolution_base_url: fieldValue('impulso-setting-base-url'),
            global_api_key: fieldValue('impulso-setting-global-key'),
            request_timeout_seconds: Number(fieldValue('impulso-setting-timeout') || 30),
            evolution_retries: Number(fieldValue('impulso-setting-evolution-retries') || 0),
            connection_status_path: fieldValue('impulso-setting-status-path'),
            find_chats_path: fieldValue('impulso-setting-chats-path'),
            find_messages_path: fieldValue('impulso-setting-messages-path'),
            send_text_path: fieldValue('impulso-setting-send-path'),
            send_media_path: fieldValue('impulso-setting-send-media-path'),
            send_audio_path: fieldValue('impulso-setting-send-audio-path'),
            get_media_base64_path: fieldValue('impulso-setting-media-base64-path'),
            n8n_base_url: fieldValue('impulso-setting-n8n-base-url'),
            n8n_token: fieldValue('impulso-setting-n8n-token'),
            n8n_auth_mode: fieldValue('impulso-setting-n8n-auth-mode'),
            n8n_header_name: fieldValue('impulso-setting-n8n-header-name'),
            n8n_allow_private_networks: checkedValue('impulso-setting-n8n-private-networks'),
            n8n_timeout_seconds: Number(fieldValue('impulso-setting-n8n-timeout') || 30),
            n8n_health_path: fieldValue('impulso-setting-n8n-health-path'),
            n8n_campaigns_path: fieldValue('impulso-setting-n8n-campaigns-path'),
            n8n_ai_path: fieldValue('impulso-setting-n8n-ai-path'),
            n8n_events_path: fieldValue('impulso-setting-n8n-events-path'),
            campaign_window_start: fieldValue('impulso-setting-campaign-start'),
            campaign_window_end: fieldValue('impulso-setting-campaign-end'),
            campaign_batch_size: Number(fieldValue('impulso-setting-campaign-batch-size') || 20),
            campaign_min_interval_seconds: Number(fieldValue('impulso-setting-campaign-min-interval') || 8),
            campaign_pause_after_errors: checkedValue('impulso-setting-campaign-pause-errors') ? 5 : 0,
            campaign_optout_text: checkedValue('impulso-setting-campaign-optout') ? 'automatic' : '',
            quick_replies_json: fieldValue('impulso-setting-quick-replies'),
            ai_default_state: fieldValue('impulso-setting-ai-default-state'),
            ai_human_priority: checkedValue('impulso-setting-ai-human-priority'),
            ai_show_context: checkedValue('impulso-setting-ai-show-context'),
            ai_stop_command: fieldValue('impulso-setting-ai-stop-command'),
            ai_start_command: fieldValue('impulso-setting-ai-start-command'),
            ai_auto_return_minutes: Number(fieldValue('impulso-setting-ai-return-minutes') || 0),
            webhook_secret: fieldValue('impulso-setting-webhook-secret'),
            log_sanitized_webhooks: checkedValue('impulso-setting-log-webhooks'),
            webhook_retention_days: Number(fieldValue('impulso-setting-webhook-retention') || 30),
            audit_enabled: checkedValue('impulso-setting-audit-enabled'),
            audit_retention_days: Number(fieldValue('impulso-setting-audit-retention') || 180),
            conversation_retention_days: Number(fieldValue('impulso-setting-conversation-retention') || 0),
            media_retention_days: Number(fieldValue('impulso-setting-media-retention') || 30),
            secure_media: checkedValue('impulso-setting-secure-media')
        };
        if (button) button.disabled = true;
        api(endpoint('settings'), { method: 'POST', body: body }).then(function (payload) {
            initialSettings = Object.assign({}, initialSettings, body, payload.data || {});
            applyInitialSettings();
            showToast('Configurações salvas', 'Parâmetros do módulo foram enviados ao backend.', 'save');
        }).catch(function (error) {
            showToast('Falha ao salvar', error.message, 'alert-triangle');
        }).finally(function () { if (button) button.disabled = false; });
    }

    function testEvolutionSettings(button) {
        if (!endpoint('settingsTest')) return;
        if (button) button.disabled = true;
        api(endpoint('settingsTest'), { method: 'POST', body: {} }).then(function (payload) {
            var rows = Array.isArray(payload.data) ? payload.data : [];
            var connected = rows.filter(function (item) { return item && item.status === 'connected'; }).length;
            showToast('Teste concluído', connected + ' de ' + rows.length + ' instância(s) conectada(s).', connected === rows.length && rows.length ? 'wifi' : 'alert-circle');
            return loadInstances(true);
        }).catch(function (error) {
            showToast('Falha no teste', error.message, 'alert-triangle');
        }).finally(function () { if (button) button.disabled = false; });
    }

    function bindStaticControls() {
        var mobileSection = document.getElementById('impulso-mobile-section');
        if (mobileSection) mobileSection.addEventListener('change', function () {
            window.location.href = endpoint('page') + '?chatwoot_tab=' + encodeURIComponent(this.value);
        });
        document.querySelectorAll('[data-settings-tab]').forEach(function (button) {
            button.addEventListener('click', function () {
                var tab = this.getAttribute('data-settings-tab');
                document.querySelectorAll('[data-settings-tab]').forEach(function (item) { item.classList.remove('active'); });
                document.querySelectorAll('[data-settings-panel]').forEach(function (panel) { panel.classList.toggle('active', panel.getAttribute('data-settings-panel') === tab); });
                this.classList.add('active');
            });
        });
        var contactSearch = document.getElementById('impulso-contact-search');
        if (contactSearch) contactSearch.addEventListener('input', function () {
            var query = this.value.trim().toLowerCase();
            document.querySelectorAll('#impulso-contacts-table tbody tr').forEach(function (row) {
                row.classList.toggle('impulso-hidden', !!query && (row.getAttribute('data-contact-search') || '').indexOf(query) === -1);
            });
        });
        document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
        window.addEventListener('pagehide', function () { runtime.destroy(); }, { once: true });
    }

    app.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-impulso-action], [data-impulso-modal-submit]');
        if (!trigger) return;
        var action = trigger.getAttribute('data-impulso-action');
        var submit = trigger.getAttribute('data-impulso-modal-submit');
        if (submit === 'instance') { saveInstance(trigger); return; }
        if (submit) return;
        if (action === 'refresh-dashboard') { window.location.reload(); return; }
        if (action === 'new-instance') { openInstanceModal(0); return; }
        if (action === 'edit-instance') { openInstanceModal(trigger.getAttribute('data-instance-id')); return; }
        if (action === 'test-instance') { testInstance(trigger.getAttribute('data-instance-id'), trigger); return; }
        if (action === 'refresh-instances') { refreshAllInstances(trigger); return; }
        if (action === 'save-settings') { saveSettings(trigger); return; }
        if (action === 'test-evolution') { testEvolutionSettings(trigger); return; }
        if (action === 'test-all-connections') { refreshAllInstances(trigger); return; }
        if (action === 'open-conversation-list') {
            var sidebar = document.getElementById('impulso-chat-sidebar');
            if (sidebar) sidebar.classList.add('open');
            return;
        }
        if (action === 'open-contact') {
            var contact = document.getElementById('impulso-contact-sidebar');
            if (contact) contact.classList.toggle('open');
            return;
        }
        if (action === 'emoji' || action === 'attach' || action === 'voice' || action === 'quick-replies' || action === 'resolve-conversation' || action === 'toggle-priority' || action === 'search-history' || action === 'close-history-search' || action === 'call-contact' || action === 'edit-contact' || action === 'contact-menu' || action === 'edit-assignment' || action === 'edit-tags' || action === 'toggle-ai-conversation') return;
        if (action === 'toggle-password') {
            var password = trigger.parentElement ? trigger.parentElement.querySelector('input') : null;
            if (password) password.type = password.type === 'password' ? 'text' : 'password';
            return;
        }
        if (action === 'copy-webhook') {
            var code = document.getElementById('impulso-webhook-endpoint');
            if (code && navigator.clipboard) navigator.clipboard.writeText(code.textContent.trim());
            showToast('Webhook copiado', 'O endpoint foi copiado para a área de transferência.', 'copy');
            return;
        }
    });

    document.addEventListener('click', function (event) {
        var contact = document.getElementById('impulso-contact-sidebar');
        if (contact && contact.classList.contains('open') && !event.target.closest('#impulso-contact-sidebar') && !event.target.closest('[data-impulso-action="open-contact"]')) {
            contact.classList.remove('open');
        }
    });

    window.ImpulsoHubBridge = {
        api: api,
        endpoint: endpoint,
        endpointWithId: endpointWithId,
        toast: showToast,
        openModal: function (id) {
            var element = document.getElementById(id);
            if (!element) return;
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(element).show();
            else if (window.jQuery && typeof window.jQuery(element).modal === 'function') window.jQuery(element).modal('show');
        },
        closeModal: closeModal,
        getConfig: function () { return config; },
        getState: function () { return state; },
        getActiveConversation: activeConversation,
        renderMessages: renderMessages,
        loadMessages: loadMessages,
        loadConversations: loadConversations,
        loadInstances: loadInstances,
        updateComposerState: updateComposerState,
        replaceIcons: replaceIcons,
        normalizeMessage: normalizeMessage,
        mergeMessages: mergeMessages,
        sendText: sendMessage,
        refreshCsrf: refreshCsrf
    };

    restoreChannel();
    renderChannels();
    renderConversationList();
    applyInitialSettings();
    bindConversationControls();
    bindStaticControls();
    replaceIcons();

    if (app.getAttribute('data-active-tab') === 'conversations') {
        loadInstances(true).then(function () {
            restoreChannel();
            renderChannels();
            return loadConversations(true);
        });
        schedulePoll();
    }
    updateComposerState();
})(window, document);
