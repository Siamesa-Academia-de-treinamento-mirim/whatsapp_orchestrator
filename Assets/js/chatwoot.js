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
    var pollingScheduler = window.ImpulsoPollingScheduler && typeof window.ImpulsoPollingScheduler.create === 'function'
        ? window.ImpulsoPollingScheduler.create()
        : null;
    var runtime = {
        destroyed: false,
        timers: [],
        requests: [],
        destroy: function () {
            this.destroyed = true;
            if (pollingScheduler) pollingScheduler.destroy();
            if (typeof fallbackPollingTimers !== 'undefined') {
                Object.keys(fallbackPollingTimers).forEach(function (name) { window.clearTimeout(fallbackPollingTimers[name]); delete fallbackPollingTimers[name]; });
            }
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
        reactionAfterCursor: 0,
        activeConversationId: null,
        activeConversationRecord: null,
        activeConversationDetached: false,
        activeConversationRefreshSequence: 0,
        activeConversationRefreshLoading: false,
        composerMode: 'reply',
        channelId: 'all',
        status: 'all',
        search: '',
        filters: { assignee_id: '', team_id: '', priority: '', unread: '', conversation_type: '', bot_status: '', last_activity_from: '', last_activity_to: '', tags: '' },
        filterCounts: { open: 0, pending: 0, resolved: 0, snoozed: 0, unread: 0 },
        assignmentOptions: { staff: [], teams: [] },
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
        pendingSends: {},
        bulkSelectedIds: [],
        templates: { conversationId: 0, rows: [], selectedId: 0, formsByTemplateId: {}, attemptsByFingerprint: {}, search: '' },
        channelSyncAt: {},
        historySyncAt: {},
        historySyncing: {},
        pollingInstanceIndex: 0,
        pollingChannelSyncing: false,
        pollingTimer: null,
        remotePollingTimer: null,
        instancePollingTimer: null,
        searchTimer: null
        ,serviceWindowTimer: null,
        conversationRenderFingerprint: '',
        messageRenderFingerprint: '',
        activeConversationSurfaceFingerprint: ''
    };

    function timingNow() {
        return window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now();
    }

    function traceTiming(label, startedAt, details) {
        var debug = config.debugTimings === true;
        if (!debug) {
            try { debug = new URLSearchParams(window.location.search).get('impulso_debug') === '1'; } catch (error) { debug = false; }
        }
        if (!debug || !window.console || typeof window.console.debug !== 'function') return;
        var payload = Object.assign({ duration_ms: Math.round(Math.max(0, timingNow() - Number(startedAt || timingNow()))) }, details || {});
        window.console.debug('[Impulso timing] ' + label, payload);
    }

    function syncAvailableHeight() {
        if (runtime.destroyed || app.getAttribute('data-active-tab') !== 'conversations') return;
        var content = document.getElementById('page-content');
        if (!content) return;
        var viewportHeight = window.visualViewport && Number(window.visualViewport.height) > 0
            ? Number(window.visualViewport.height)
            : Number(document.documentElement.clientHeight || window.innerHeight || 0);
        var top = content.getBoundingClientRect().top;
        var available = Math.max(320, Math.floor(viewportHeight - top));
        content.style.setProperty('--impulso-available-height', available + 'px');
    }

    var inboxPanelState = {
        channelCollapsed: false,
        conversationCollapsed: false
    };

    function inboxLayout() {
        return app.querySelector('.impulso-chat-layout');
    }

    function inboxWidth() {
        var workspace = app.querySelector('.impulso-workspace');
        var layout = inboxLayout();
        var element = workspace || layout;
        var width = element ? Number(element.getBoundingClientRect().width || 0) : 0;
        return width > 0 ? width : Number(window.innerWidth || 0);
    }

    function isCompactInbox() {
        return inboxWidth() <= 991.98;
    }

    function readInboxPanelPreference(key) {
        try { return window.localStorage.getItem(key) === '1'; } catch (error) { return false; }
    }

    function persistInboxPanelPreferences() {
        try {
            window.localStorage.setItem('impulso_hub_channel_collapsed', inboxPanelState.channelCollapsed ? '1' : '0');
            window.localStorage.setItem('impulso_hub_conversation_collapsed', inboxPanelState.conversationCollapsed ? '1' : '0');
        } catch (error) { /* Private browsing or disabled storage. */ }
    }

    function updateInboxPanelButtons(compact) {
        var labels = {
            channel: { open: 'Recolher canais', closed: 'Expandir canais' },
            conversation: { open: 'Recolher conversas', closed: 'Expandir conversas' }
        };
        app.querySelectorAll('[data-panel-toggle]').forEach(function (button) {
            var panel = button.getAttribute('data-panel-toggle');
            var element = panel === 'channel'
                ? document.getElementById('impulso-channel-sidebar')
                : document.getElementById('impulso-chat-sidebar');
            var open = compact
                ? !!(element && element.classList.contains('open'))
                : (panel === 'channel' ? !inboxPanelState.channelCollapsed : !inboxPanelState.conversationCollapsed);
            var label = labels[panel] ? (open ? labels[panel].open : labels[panel].closed) : '';
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
            var screenReaderText = button.querySelector('.impulso-sr-only');
            if (screenReaderText) screenReaderText.textContent = label;
        });
    }

    function syncInboxPanels() {
        var layout = inboxLayout();
        if (!layout) return;
        var compact = isCompactInbox();
        var channel = document.getElementById('impulso-channel-sidebar');
        var conversation = document.getElementById('impulso-chat-sidebar');
        var backdrop = document.querySelector('.impulso-inbox-drawer-backdrop');

        layout.classList.toggle('impulso-inbox-compact', compact);
        layout.classList.toggle('impulso-channel-sidebar-collapsed', !compact && inboxPanelState.channelCollapsed);
        layout.classList.toggle('impulso-conversation-sidebar-collapsed', !compact && inboxPanelState.conversationCollapsed);

        if (!compact) {
            if (channel) channel.classList.remove('open');
            if (conversation) conversation.classList.remove('open');
        }

        var drawerOpen = compact && !!(
            (channel && channel.classList.contains('open'))
            || (conversation && conversation.classList.contains('open'))
        );
        if (backdrop) {
            backdrop.classList.toggle('impulso-hidden', !drawerOpen);
            backdrop.setAttribute('aria-hidden', drawerOpen ? 'false' : 'true');
        }
        if (channel) channel.setAttribute('aria-hidden', compact ? (channel.classList.contains('open') ? 'false' : 'true') : (inboxPanelState.channelCollapsed ? 'true' : 'false'));
        if (conversation) conversation.setAttribute('aria-hidden', compact ? (conversation.classList.contains('open') ? 'false' : 'true') : (inboxPanelState.conversationCollapsed ? 'true' : 'false'));
        updateInboxPanelButtons(compact);
    }

    function closeInboxDrawers() {
        var channel = document.getElementById('impulso-channel-sidebar');
        var conversation = document.getElementById('impulso-chat-sidebar');
        if (channel) channel.classList.remove('open');
        if (conversation) conversation.classList.remove('open');
        syncInboxPanels();
    }

    function toggleInboxPanel(panel) {
        var layout = inboxLayout();
        if (!layout) return;
        var compact = isCompactInbox();
        var target = panel === 'channel'
            ? document.getElementById('impulso-channel-sidebar')
            : document.getElementById('impulso-chat-sidebar');
        if (!target) return;

        if (compact) {
            var other = panel === 'channel'
                ? document.getElementById('impulso-chat-sidebar')
                : document.getElementById('impulso-channel-sidebar');
            var opening = !target.classList.contains('open');
            if (other) other.classList.remove('open');
            target.classList.toggle('open', opening);
            syncInboxPanels();
            return;
        }

        if (panel === 'channel') inboxPanelState.channelCollapsed = !inboxPanelState.channelCollapsed;
        else inboxPanelState.conversationCollapsed = !inboxPanelState.conversationCollapsed;
        persistInboxPanelPreferences();
        syncInboxPanels();
    }

    var fallbackPollingTimers = {};
    function schedulePolling(name, delay, callback) {
        name = String(name);
        if (pollingScheduler) {
            pollingScheduler.schedule(name, delay, callback);
            return;
        }
        if (fallbackPollingTimers[name]) window.clearTimeout(fallbackPollingTimers[name]);
        fallbackPollingTimers[name] = window.setTimeout(function () {
            delete fallbackPollingTimers[name];
            if (!runtime.destroyed && typeof callback === 'function') callback();
        }, Math.max(0, Number(delay) || 0));
    }

    function runPollingOperation(name, callback) {
        if (pollingScheduler) return pollingScheduler.run(name, callback);
        state.pollingOperations = state.pollingOperations || {};
        if (state.pollingOperations[name]) return Promise.resolve({ status: 'skipped', name: name });
        state.pollingOperations[name] = true;
        var result;
        try { result = callback(); } catch (error) { result = Promise.reject(error); }
        return Promise.resolve(result).then(function (value) {
            return { status: 'fulfilled', value: value, name: name };
        }, function (reason) {
            return { status: 'rejected', reason: reason, name: name };
        }).then(function (settled) {
            delete state.pollingOperations[name];
            return settled;
        });
    }

    function pollingAllSettled(promises) {
        return pollingScheduler ? pollingScheduler.allSettled(promises) : Promise.all(promises);
    }
    var templatePicker = null;
    var workflowSession = window.ImpulsoConversationWorkflow && typeof window.ImpulsoConversationWorkflow.create === 'function'
        ? window.ImpulsoConversationWorkflow.create()
        : null;
    var workflowHelpers = window.ImpulsoConversationWorkflow || {};
    var workflowMutations = workflowHelpers.createMutationTracker ? workflowHelpers.createMutationTracker() : null;
    var conversationNavigation = workflowHelpers.createNavigationContext ? workflowHelpers.createNavigationContext() : (function () {
        var sequence = 0;
        return {
            begin: function (conversationId) { sequence += 1; return { conversationId: Number(conversationId || 0), sequence: sequence }; },
            invalidate: function () { sequence += 1; return sequence; },
            isCurrent: function (context) { return !!context && Number(context.sequence || 0) === sequence; }
        };
    }());
    var customSnoozeLifecycle = workflowHelpers.createDialogLifecycle ? workflowHelpers.createDialogLifecycle() : null;
    var customSnoozeTrigger = null;

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
                    apiException.details = payload && payload.details ? payload.details : {};
                    throw apiException;
                }
                applyCsrf(payload.data);
            });
        }).finally(function () {
            csrfRefreshPromise = null;
        });

        return csrfRefreshPromise;
    }

    function isCsrfFailure(error) {
        var status = Number(error && error.status || 0);
        if (status === 419 || status === 440) return true;
        if (status !== 403) return false;
        var details = error && error.details && typeof error.details === 'object' ? error.details : {};
        var haystack = [error && error.message, details.code, details.error, error && error.payload && error.payload.message].join(' ').toLowerCase();
        return /csrf|security token|token mismatch|invalid token/.test(haystack);
    }

    function api(url, options) {
        options = options || {};
        var method = String(options.method || 'GET').toUpperCase();
        var isWrite = method !== 'GET' && method !== 'HEAD';
        var csrfRetried = options._csrfRetried === true;
        var execute = function () { return apiRequest(url, options); };
        var initial = isWrite && !config.csrfHash ? refreshCsrf() : Promise.resolve();

        return initial.then(execute).catch(function (error) {
            if (!isWrite || csrfRetried || !isCsrfFailure(error)) throw error;
            return refreshCsrf().then(function () {
                return apiRequest(url, Object.assign({}, options, { _csrfRetried: true }));
            });
        });
    }

    function apiRequest(url, options) {
        options = options || {};
        var method = String(options.method || 'GET').toUpperCase();
        var isWrite = method !== 'GET' && method !== 'HEAD';
        var requestStartedAt = timingNow();
        var timingLabel = String(options.timingLabel || (isWrite ? 'api_write' : 'api_read'));
        var responseStatus = 0;
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
        var timeoutMs = Number(options.timeoutMs || (isWrite ? config.writeTimeoutMs : config.readTimeoutMs) || 0);
        timeoutMs = timeoutMs > 0 ? Math.max(1000, Math.min(180000, timeoutMs)) : 0;
        var timeoutId = null;
        var request = window.fetch(url, {
            method: method,
            headers: headers,
            body: options.body == null ? undefined : (isFormData ? options.body : JSON.stringify(options.body)),
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined,
            cache: 'no-store'
        });
        if (timeoutMs > 0) {
            var timeout = new Promise(function (resolve, reject) {
                timeoutId = window.setTimeout(function () {
                    var timeoutError = new Error('A requisicao excedeu o tempo limite e o resultado nao foi confirmado.');
                    timeoutError.status = 408;
                    timeoutError.isTimeout = true;
                    timeoutError.details = { code: 'REQUEST_TIMEOUT', send_state: 'ambiguous_failure' };
                    if (controller) {
                        try { controller.abort(); } catch (error) { /* noop */ }
                    }
                    reject(timeoutError);
                }, timeoutMs);
            });
            request.catch(function () { /* the race owns the request outcome */ });
            request = Promise.race([request, timeout]);
        }

        return request.then(function (response) {
            responseStatus = Number(response.status || 0);
            return response.text().then(function (text) {
                var payload = {};
                try { payload = text ? JSON.parse(text) : {}; } catch (error) { payload = {}; }
                if (!response.ok || payload.success === false) {
                    var apiException = new Error(apiErrorMessage(payload, response.status));
                    apiException.status = response.status;
                    apiException.payload = payload;
                    apiException.details = payload && payload.details ? payload.details : {};
                    applyCsrf(payload && payload.csrf);
                    throw apiException;
                }
                applyCsrf(payload && payload.csrf);
                if (payload && payload.data && typeof payload.data === 'object' && payload.data.csrf_hash) applyCsrf(payload.data);
                return payload;
            });
        }).finally(function () {
            if (timeoutId) window.clearTimeout(timeoutId);
            if (controller) runtime.requests = runtime.requests.filter(function (item) { return item !== controller; });
            traceTiming(timingLabel, requestStartedAt, { status: responseStatus, kind: isWrite ? 'write' : 'read' });
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
        var capabilities = item.capabilities && typeof item.capabilities === 'object' ? item.capabilities : null;
        return {
            contract_version: Number(item.contract_version || (capabilities && capabilities.contract_version) || 1),
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
            has_api_key: !!item.has_api_key,
            provider: String(item.provider || item.provider_type || 'evolution'),
            provider_type: String(item.provider_type || item.provider || 'evolution'),
            provider_status: String(item.provider_status || item.connection_status || 'disconnected'),
            capabilities: capabilities,
            meta_phone_number_id: String(item.meta_phone_number_id || ''),
            meta_waba_id: String(item.meta_waba_id || ''),
            meta_graph_version: String(item.meta_graph_version || 'v25.0'),
            has_meta_access_token: !!item.has_meta_access_token,
            has_meta_verify_token: !!item.has_meta_verify_token,
            has_meta_app_secret: !!item.has_meta_app_secret
        };
    }

    function normalizeConversation(item) {
        item = item || {};
        var contact = item.contact || {};
        var instance = item.instance && typeof item.instance === 'object' ? item.instance : {};
        var capabilities = item.capabilities && typeof item.capabilities === 'object'
            ? item.capabilities
            : (instance.capabilities && typeof instance.capabilities === 'object' ? instance.capabilities : null);
        var name = String(item.name || contact.name || item.contact_name || item.phone_number || 'Contato');
        var phone = String(item.phone || contact.phone || item.phone_number || '');
        var assignment = item.assignment && typeof item.assignment === 'object' ? item.assignment : {};
        var assigneeObject = assignment.user && typeof assignment.user === 'object' ? assignment.user : (item.assignee_details && typeof item.assignee_details === 'object' ? item.assignee_details : {});
        var teamObject = assignment.team && typeof assignment.team === 'object' ? assignment.team : (item.team && typeof item.team === 'object' ? item.team : {});
        var rawPriority = String(item.priority || 'none').toLowerCase();
        var priority = rawPriority === 'normal' ? 'medium' : (['none', 'low', 'medium', 'high', 'urgent'].indexOf(rawPriority) >= 0 ? rawPriority : 'none');
        var unreadCount = Number(item.unread_count != null ? item.unread_count : (typeof item.unread === 'number' ? item.unread : 0));
        return {
            contract_version: Number(item.contract_version || (capabilities && capabilities.contract_version) || 1),
            id: Number(item.id || 0),
            instance_id: Number(item.instance_id || instance.id || 0),
            instance: String(item.instance_name || (typeof item.instance === 'string' ? item.instance : instance.name) || ''),
            instance_status: String(item.instance_status || instance.status || 'disconnected'),
            remote_jid: String(item.remote_jid || ''),
            name: name,
            contact_name: String(item.contact_name || contact.name || name),
            phone: phone,
            avatar: String(item.avatar || contact.initials || initials(name, phone)),
            profile_picture_url: String(item.profile_picture_url || contact.avatar_url || ''),
            status: String(item.status || 'open'),
            contact_id: Number(item.contact_id || contact.id || 0) || null,
            priority: priority,
            priority_legacy: item.priority_legacy || (rawPriority === 'normal' ? 'normal' : null),
            assignee_id: Number(item.assignee_id || 0) || null,
            assignment: assignment,
            conversation_type: String(item.conversation_type || 'individual'),
            group_id: Number(item.group_id || 0) || null,
            provider_type: String(item.provider_type || item.provider || instance.provider_type || instance.provider || 'evolution'),
            provider: String(item.provider || item.provider_name || item.provider_type || instance.provider || instance.provider_type || 'evolution'),
            capabilities: capabilities,
            instance_details: instance,
            service_window_expires_at: item.service_window_expires_at || null,
            service_window: item.service_window && typeof item.service_window === 'object'
                ? item.service_window
                : { required: !!(capabilities && capabilities.conversation && capabilities.conversation.service_window), open: item.service_window_open !== false, expires_at: item.service_window_expires_at || null, freeform_allowed: item.service_window_open !== false, template_required: item.service_window_open === false },
            bot_status: String(item.bot_status || 'active'),
            unread: unreadCount,
            unread_count: unreadCount,
            is_unread: unreadCount > 0,
            last_message: String(item.last_message || item.last_message_preview || ''),
            last_activity_at: item.last_activity_at || item.last_message_at || null,
            assignee: String(item.assignee || assigneeObject.name || 'Sem agente'),
            team_id: Number(item.team_id || (teamObject.id || 0)) || null,
            team_object: teamObject,
            team: String((teamObject && teamObject.name) || item.team_name || (typeof item.team === 'string' ? item.team : '') || 'Sem equipe'),
            email: String(item.email || ''),
            city: String(item.city || ''),
            source: String(item.source || 'WhatsApp'),
            created_at: item.created_at || null,
            tags: Array.isArray(item.tags) ? item.tags : [],
            snoozed_until: item.snoozed_until || null,
            snoozed_by: Number(item.snoozed_by || 0) || null,
            resolved_at: item.resolved_at || null,
            resolved_by: Number(item.resolved_by || 0) || null
        };
    }

    function normalizeMessage(item) {
        item = item || {};
        var v2Content = item.content && typeof item.content === 'object' ? item.content : {};
        var canonicalContent = item.content && typeof item.content === 'object' ? item.content : null;
        var canonicalSender = item.sender && typeof item.sender === 'object' ? item.sender : null;
        var canonicalTimestamps = item.timestamps && typeof item.timestamps === 'object' ? item.timestamps : {};
        var canonicalError = item.error && typeof item.error === 'object' ? item.error : null;
        var canonicalActions = item.actions && typeof item.actions === 'object' ? item.actions : {};
        var canonicalMetadata = item.metadata && typeof item.metadata === 'object' ? item.metadata : {};
        var canonicalReply = item.reply_to && typeof item.reply_to === 'object' ? Object.assign({}, item.reply_to) : null;
        if (canonicalReply && !canonicalReply.local_message_id && Number(canonicalReply.message_id || 0) > 0) canonicalReply.local_message_id = Number(canonicalReply.message_id);
        var v2Attachments = Array.isArray(v2Content.attachments) ? v2Content.attachments : [];
        var v2Attachment = v2Attachments[0] && typeof v2Attachments[0] === 'object' ? v2Attachments[0] : {};
        var v2Type = String(item.type || '');
        var legacyType = String(item.message_type || item.content_type || (v2Type === 'voice' ? 'audio' : v2Type) || 'text');
        var v2Text = v2Content.text == null ? '' : String(v2Content.text);
        var v2Caption = v2Content.caption == null ? '' : String(v2Content.caption);
        var legacyContent = typeof item.content === 'string' ? item.content : '';
        var rawId = item.id == null ? String(item.client_message_id || '') : item.id;
        var numericId = Number(rawId);
        var sentAt = canonicalTimestamps.sent_at || item.sent_at || item.created_at || new Date().toISOString();
        var timestamp = Number(item.message_timestamp || 0);
        if ((!isFinite(timestamp) || timestamp <= 0) && sentAt) {
            var parsedSentAt = new Date(sentAt).getTime();
            timestamp = isFinite(parsedSentAt) ? Math.floor(parsedSentAt / 1000) : 0;
        }
        return {
            contract_version: Number(item.contract_version || 1),
            id: isFinite(numericId) && numericId > 0 ? numericId : String(rawId || ''),
            client_message_id: String(item.client_message_id || ''),
            provider: String(item.provider || item.provider_name || ''),
            provider_message_id: String(item.provider_message_id || item.external_message_id || item.external_id || ''),
            external_message_id: String(item.external_message_id || item.provider_message_id || item.external_id || ''),
            direction: String(item.direction || 'incoming'),
            type: v2Type || legacyType,
            content: canonicalContent,
            sender: canonicalSender,
            message_type: legacyType,
            text_content: String(item.text_content || item.content_text || v2Text || legacyContent || item.text || v2Caption || ''),
            media_url: safeMediaUrl(item.media_url || v2Attachment.url || ''),
            mime_type: String(item.mime_type || v2Attachment.mime_type || ''),
            caption: String(item.caption || v2Caption || ''),
            file_name: String(item.file_name || v2Attachment.file_name || ''),
            file_size: Number(item.file_size || v2Attachment.file_size || 0),
            media_id: Number(item.media_id || v2Attachment.id || 0) || null,
            sender_user_id: Number(item.sender_user_id || (canonicalSender && canonicalSender.user_id) || 0) || null,
            sender_jid: String(item.sender_jid || (canonicalSender && canonicalSender.jid) || ''),
            sender_phone: String(item.sender_phone || (canonicalSender && canonicalSender.phone) || ''),
            sender_name: String(item.sender_name || (canonicalSender && canonicalSender.name) || ''),
            sender_contact_id: Number(item.sender_contact_id || (canonicalSender && canonicalSender.contact_id) || 0) || null,
            is_group_message: !!item.is_group_message,
            provider_name: String(item.provider_name || item.provider || ''),
            is_internal_note: !!item.is_internal_note || legacyType === 'note' || v2Type === 'internal_note',
            delivery_error: String(item.delivery_error || (canonicalError && canonicalError.message) || ''),
            status: String(item.status || 'received').toLowerCase(),
            sent_at: sentAt,
            delivered_at: canonicalTimestamps.delivered_at || item.delivered_at || null,
            read_at: canonicalTimestamps.read_at || item.read_at || null,
            failed_at: canonicalTimestamps.failed_at || item.failed_at || null,
            message_timestamp: isFinite(timestamp) && timestamp > 0 ? timestamp : 0,
            reply_to: canonicalReply,
            reactions: Array.isArray(item.reactions) ? item.reactions : [],
            timestamps: canonicalTimestamps,
            error: canonicalError,
            actions: canonicalActions,
            metadata: canonicalMetadata,
            temporary: !!item.temporary
        };
    }

    function safeMediaUrl(value) {
        if (window.ImpulsoMessageSafeContent && typeof window.ImpulsoMessageSafeContent.safeMediaUrl === 'function') {
            return window.ImpulsoMessageSafeContent.safeMediaUrl(value, window.location);
        }
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
        if (!state.activeConversationId) return null;
        if (state.activeConversationRecord && Number(state.activeConversationRecord.id) === Number(state.activeConversationId)) return state.activeConversationRecord;
        return state.conversations.find(function (item) { return Number(item.id) === Number(state.activeConversationId); }) || null;
    }

    var conversationChangeHandlers = [];
    var composerModeChangeHandlers = [];
    function notifyConversationChange(fromId, toId, reason) {
        conversationChangeHandlers.slice().forEach(function (handler) {
            try { handler({ fromId: Number(fromId || 0), toId: Number(toId || 0), reason: reason || 'selection' }); } catch (error) { /* media cleanup must not block navigation */ }
        });
    }

    function setActiveConversationId(nextId, reason) {
        var previousId = state.activeConversationId;
        var previousKey = Number(previousId || 0);
        var nextKey = Number(nextId || 0);
        if (previousKey === nextKey) return false;
        var preserveDetachedDetail = nextKey < 1 && state.activeConversationDetached && ['filter', 'search', 'channel', 'filter_clear', 'options_reconcile'].indexOf(String(reason || '')) >= 0;
        if (preserveDetachedDetail) return false;
        if (conversationNavigation) conversationNavigation.invalidate();
        closeCustomSnooze(false);
        if (state.serviceWindowTimer) {
            window.clearTimeout(state.serviceWindowTimer);
            state.serviceWindowTimer = null;
        }
        state.activeConversationId = nextKey > 0 ? nextKey : null;
        if (!state.activeConversationId) {
            state.activeConversationRecord = null;
            state.activeConversationDetached = false;
        }
        if (workflowSession) workflowSession.activate(nextKey);
        notifyConversationChange(previousId, state.activeConversationId, reason || 'selection');
        return true;
    }

    function scheduleServiceWindowTimer(conversation) {
        if (state.serviceWindowTimer) window.clearTimeout(state.serviceWindowTimer);
        state.serviceWindowTimer = null;
        if (!conversation || !conversation.service_window || conversation.service_window.open === false) return;
        var expires = conversation.service_window.expires_at;
        if (!expires) return;
        var expiry = Date.parse(String(expires));
        if (!isFinite(expiry)) return;
        if (expiry <= Date.now()) {
            conversation.service_window = Object.assign({}, conversation.service_window, { required: true, open: false, expires_at: expires, seconds_remaining: 0, freeform_allowed: false, template_required: true });
            conversation.service_window_expires_at = expires;
            updateComposerState();
            return;
        }
        var conversationId = Number(conversation.id || 0);
        state.serviceWindowTimer = window.setTimeout(function () {
            state.serviceWindowTimer = null;
            var active = activeConversation();
            if (!active || Number(active.id) !== conversationId) return;
            var service = active.service_window || {};
            active.service_window = Object.assign({}, service, { required: true, open: false, expires_at: expires, seconds_remaining: 0, freeform_allowed: false, template_required: true });
            active.service_window_expires_at = expires;
            updateComposerState();
        }, Math.max(0, expiry - Date.now()));
        runtime.timers.push(state.serviceWindowTimer);
    }

    function reconcileServiceWindowError(error, conversationId) {
        var details = error && error.details && typeof error.details === 'object' ? error.details : {};
        if (details.code !== 'SERVICE_WINDOW_CLOSED' || !details.service_window) return false;
        var active = activeConversation();
        if (!active || Number(active.id) !== Number(conversationId || state.activeConversationId)) return false;
        active.service_window = details.service_window;
        active.service_window_expires_at = details.expires_at || active.service_window.expires_at || null;
        scheduleServiceWindowTimer(active);
        updateComposerState();
        return true;
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
        var html = '<button class="impulso-channel-item' + (allActive ? ' active' : '') + '" type="button" aria-pressed="' + (allActive ? 'true' : 'false') + '" data-channel-filter="all" data-channel-label="Todos os canais">' +
            '<span class="impulso-channel-icon all"><i data-feather="layers"></i></span><span class="impulso-channel-copy"><strong>Todos os canais</strong><small>' + totalConversations + ' conversa' + (totalConversations === 1 ? '' : 's') + '</small></span>' +
            (totalUnread > 0 ? '<span class="impulso-channel-unread">' + totalUnread + '</span>' : '') + '</button>';
        var options = '<option value="all">Todos os canais</option>';
        state.instances.forEach(function (instance) {
            var active = String(state.channelId) === String(instance.id);
            var status = ['connected', 'attention', 'disconnected', 'error'].indexOf(instance.status) >= 0 ? instance.status : 'disconnected';
            html += '<button class="impulso-channel-item' + (active ? ' active' : '') + '" type="button" aria-pressed="' + (active ? 'true' : 'false') + '" data-channel-filter="' + instance.id + '" data-channel-label="' + escapeHtml(instance.name) + '" title="' + escapeHtml(instance.name + (instance.phone ? ' · ' + instance.phone : '')) + '">' +
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
        var selected = state.bulkSelectedIds.indexOf(Number(conversation.id)) >= 0;
        var bulkCheckbox = config.permissions && config.permissions.manageConversations ? '<label class="impulso-bulk-select" title="Selecionar conversa"><input type="checkbox" data-bulk-select="' + conversation.id + '"' + (selected ? ' checked' : '') + ' aria-label="Selecionar ' + escapeHtml(conversation.name) + '"></label>' : '';
        var priority = conversation.priority && conversation.priority !== 'none' ? '<span class="impulso-workflow-pill priority-' + escapeHtml(conversation.priority) + '">' + escapeHtml(conversation.priority) + '</span>' : '';
        var tags = conversation.tags.slice(0, 2).map(function (tag) { return '<span class="impulso-workflow-tag">' + escapeHtml(tag) + '</span>'; }).join('') + (conversation.tags.length > 2 ? '<span class="impulso-workflow-tag">+' + (conversation.tags.length - 2) + '</span>' : '');
        var snooze = conversation.status === 'snoozed' && conversation.snoozed_until ? '<span class="impulso-workflow-pill">até ' + escapeHtml(conversationTime(conversation.snoozed_until)) + '</span>' : '';
        var group = conversation.conversation_type === 'group' ? '<span class="impulso-workflow-pill">Grupo</span>' : '';
        var bot = conversation.bot_status === 'paused' || conversation.bot_status === 'handoff' ? '<span class="impulso-workflow-pill">Bot ' + escapeHtml(conversation.bot_status === 'handoff' ? 'handoff' : 'pausado') + '</span>' : '';
        return '<article class="impulso-conversation-item impulso-conversation-card' + (active ? ' active' : '') + (conversation.unread > 0 ? ' unread' : '') + '" data-conversation-id="' + conversation.id + '" data-status="' + escapeHtml(conversation.status) + '" data-instance-id="' + conversation.instance_id + '">' +
            '<button class="impulso-conversation-select" type="button" data-conversation-select="' + conversation.id + '" aria-label="Abrir conversa de ' + escapeHtml(conversation.name) + '"' + (active ? ' aria-current="page"' : '') + '>' +
            '<div class="impulso-conversation-line"><div class="impulso-avatar">' + escapeHtml(conversation.avatar) + '</div><div class="impulso-conversation-copy">' +
            '<div class="impulso-conversation-title"><strong>' + escapeHtml(conversation.name) + '</strong><span class="impulso-conversation-time">' + escapeHtml(conversationTime(conversation.last_activity_at)) + '</span></div>' +
            '<div class="impulso-conversation-preview">' + escapeHtml(conversation.last_message || 'Sem mensagens') + '</div><div class="impulso-conversation-meta">' +
            '<span class="impulso-instance-mini"><i data-feather="smartphone"></i> ' + escapeHtml(conversation.instance) + '</span><span>' + escapeHtml(conversation.assignee) + '</span><span>' + escapeHtml(conversation.team) + '</span>' +
            (conversation.unread > 0 ? '<span class="impulso-unread">' + conversation.unread + '</span>' : '') + '</div><div class="impulso-workflow-tags">' + group + bot + priority + snooze + tags + '</div></div></div></button>' + bulkCheckbox +
            '<button class="impulso-conversation-menu-trigger" type="button" data-conversation-menu="' + conversation.id + '" aria-label="Ações da conversa" aria-haspopup="menu"><i data-feather="more-vertical"></i></button></article>';
    }

    function conversationSurfaceFingerprint(conversation) {
        conversation = conversation || {};
        return JSON.stringify([
            conversation.id,
            conversation.name,
            conversation.avatar,
            conversation.last_message,
            conversation.last_activity_at,
            conversation.status,
            conversation.instance_status,
            conversation.assignee_id,
            conversation.assignee,
            conversation.team_id,
            conversation.team,
            conversation.unread,
            conversation.priority,
            conversation.snoozed_until,
            conversation.bot_status,
            Array.isArray(conversation.tags) ? conversation.tags : []
        ]);
    }

    function conversationListFingerprint() {
        return JSON.stringify([
            state.activeConversationId,
            state.hasMore,
            state.bulkSelectedIds.slice().sort(function (left, right) { return Number(left) - Number(right); }),
            state.conversations.map(conversationSurfaceFingerprint)
        ]);
    }

    function renderConversationList() {
        var list = document.getElementById('impulso-conversation-list');
        if (!list) return;
        var fingerprint = conversationListFingerprint();
        if (state.conversationRenderFingerprint === fingerprint) return;
        state.conversationRenderFingerprint = fingerprint;
        var html = state.conversations.map(conversationItemHtml).join('');
        if (!state.conversations.length) {
            html = '<div class="impulso-conversation-empty"><div class="impulso-empty-icon"><i data-feather="inbox"></i></div><strong>Nenhuma conversa encontrada</strong><span>As conversas aparecerão após a sincronização ou o primeiro webhook.</span></div>';
        }
        html += '<div class="impulso-conversation-empty' + (state.hasMore ? '' : ' impulso-hidden') + '" id="impulso-conversation-load-more"><button class="btn btn-default btn-sm" type="button">Carregar mais</button></div>';
        list.innerHTML = html;
        setText('impulso-visible-conversation-count', state.conversations.length, '0');
        list.querySelectorAll('[data-conversation-select]').forEach(function (button) {
            button.addEventListener('click', function () { selectConversation(Number(this.getAttribute('data-conversation-select'))); });
        });
        list.querySelectorAll('[data-conversation-menu]').forEach(function (button) {
            button.addEventListener('click', function (event) { event.stopPropagation(); openConversationContextMenu(Number(this.getAttribute('data-conversation-menu')), this); });
        });
        if (window.ImpulsoBulkActions && typeof window.ImpulsoBulkActions.bind === 'function') window.ImpulsoBulkActions.bind(list);
        var more = document.querySelector('#impulso-conversation-load-more button');
        if (more) more.addEventListener('click', function () { loadConversations(false); });
        replaceIcons();
    }

    var conversationMenuTrigger = null;
    var conversationMenuKeyboard = null;
    function closeConversationContextMenu() {
        var menu = document.getElementById('impulso-conversation-context-menu');
        if (menu) menu.classList.add('impulso-hidden');
        var trigger = conversationMenuTrigger;
        conversationMenuTrigger = null;
        conversationMenuKeyboard = null;
        if (trigger && typeof trigger.focus === 'function') trigger.focus();
    }

    function mutationKeyIncludes(keys, key) {
        return Array.isArray(keys) && keys.indexOf(key) >= 0;
    }

    function mergeConversationRecord(target, normalized, mutationKeys) {
        target = target || {};
        if (!Array.isArray(mutationKeys) || !mutationKeys.some(function (key) { return String(key).indexOf('assignment.') === 0; })) {
            return Object.assign(target, normalized);
        }
        target.id = normalized.id;
        if (mutationKeyIncludes(mutationKeys, 'assignment.assignee')) {
            target.assignee_id = normalized.assignee_id;
            target.assignee = normalized.assignee;
        }
        if (mutationKeyIncludes(mutationKeys, 'assignment.team')) {
            target.team_id = normalized.team_id;
            target.team_object = normalized.team_object;
            target.team = normalized.team;
        }
        if (normalized.assignment && typeof normalized.assignment === 'object') {
            var assignment = Object.assign({}, target.assignment || {});
            if (mutationKeyIncludes(mutationKeys, 'assignment.assignee') && Object.prototype.hasOwnProperty.call(normalized.assignment, 'user')) assignment.user = normalized.assignment.user;
            if (mutationKeyIncludes(mutationKeys, 'assignment.team') && Object.prototype.hasOwnProperty.call(normalized.assignment, 'team')) assignment.team = normalized.assignment.team;
            target.assignment = assignment;
        }
        return target;
    }

    function updateConversationRecord(data, mutationKeys, options) {
        if (!data || !data.id) return null;
        var normalized = normalizeConversation(data);
        var existing = state.conversations.find(function (item) { return Number(item.id) === Number(normalized.id); });
        var isActive = Number(state.activeConversationId) === Number(normalized.id);
        if (isActive) {
            state.activeConversationRecord = mergeConversationRecord(state.activeConversationRecord || existing || {}, normalized, mutationKeys);
        }
        var filterCandidate = isActive
            ? state.activeConversationRecord
            : (existing ? mergeConversationRecord(Object.assign({}, existing), normalized, mutationKeys) : normalized);
        var matchesCurrentFilters = conversationMatchesCurrentFilters(filterCandidate);
        var shouldClearActive = isActive && (workflowHelpers.shouldClearActiveConversation
            ? workflowHelpers.shouldClearActiveConversation(state.activeConversationDetached, matchesCurrentFilters)
            : (!state.activeConversationDetached && !matchesCurrentFilters));
        var keepDetachedDetail = isActive && state.activeConversationDetached && !shouldClearActive;
        if (!matchesCurrentFilters) {
            if (existing) state.conversations.splice(state.conversations.indexOf(existing), 1);
            if (isActive && shouldClearActive) clearConversation();
            if (keepDetachedDetail) applyActiveConversation(state.activeConversationRecord || normalized, options || {});
            renderConversationList();
            return keepDetachedDetail ? state.activeConversationRecord : null;
        }
        if (existing) mergeConversationRecord(existing, normalized, mutationKeys);
        if (isActive) applyActiveConversation(state.activeConversationRecord || existing || normalized, options || {});
        renderConversationList();
        return existing || (isActive ? state.activeConversationRecord : null) || normalized;
    }

    function workflowMutationKeys(suffix, body) {
        suffix = String(suffix || '');
        if (suffix === '/priority') return 'priority';
        if (suffix === '/assignment') {
            body = body || {};
            var keys = [];
            if (Object.prototype.hasOwnProperty.call(body, 'assignee_id') || Object.prototype.hasOwnProperty.call(body, 'assign_to_me')) keys.push('assignment.assignee');
            if (Object.prototype.hasOwnProperty.call(body, 'team_id')) keys.push('assignment.team');
            return keys.length ? keys : ['assignment'];
        }
        if (suffix === '/status' || suffix === '/snooze' || suffix === '/unsnooze') return 'status';
        if (suffix === '/read' || suffix === '/unread') return 'read_state';
        return suffix || 'workflow';
    }

    function beginWorkflowMutation(id, suffix, body) {
        if (!workflowMutations || !workflowMutations.begin) return null;
        var keys = workflowMutationKeys(suffix, body);
        if (workflowMutations.beginMany && Array.isArray(keys)) return workflowMutations.beginMany(id, keys);
        if (Array.isArray(keys) && keys.length > 1) return { contexts: keys.map(function (key) { return workflowMutations.begin(id, key); }) };
        return workflowMutations.begin(id, Array.isArray(keys) ? keys[0] : keys);
    }

    function mutateConversation(id, suffix, body) {
        if (!canManageConversations()) {
            showToast('Acesso negado', 'Você não pode alterar o fluxo desta conversa.', 'lock');
            return Promise.reject(new Error('Permissão insuficiente.'));
        }
        var mutationKeys = workflowMutationKeys(suffix, body);
        var operationContext = beginWorkflowMutation(id, suffix, body);
        return api(endpointWithId('conversations', id, suffix), { method: 'POST', body: body || {} }).then(function (payload) {
            if (workflowMutations && operationContext && !workflowMutations.isCurrent(operationContext)) return null;
            var refreshAuxiliary = ['/tags', '/assignment', '/status', '/snooze'].indexOf(suffix) >= 0 || suffix.indexOf('/bot/') === 0;
            var conversation = payload && payload.data ? updateConversationRecord(payload.data, Array.isArray(mutationKeys) ? mutationKeys : [mutationKeys], { loadAuxiliary: refreshAuxiliary }) : null;
            closeConversationContextMenu();
            return conversation;
        }).catch(function (error) {
            if (workflowMutations && operationContext && !workflowMutations.isCurrent(operationContext)) return null;
            showToast('Falha na conversa', error.message, 'alert-triangle');
            throw error;
        });
    }

    function snoozePreset(kind) {
        var date = new Date();
        if (kind === '1h') date.setHours(date.getHours() + 1);
        else if (kind === '4h') date.setHours(date.getHours() + 4);
        else if (kind === 'tomorrow') { date.setDate(date.getDate() + 1); date.setHours(9, 0, 0, 0); }
        else { date.setDate(date.getDate() + ((8 - date.getDay()) % 7 || 7)); date.setHours(9, 0, 0, 0); }
        return date.toISOString();
    }

    function closeCustomSnooze(restoreFocus) {
        var popover = document.getElementById('impulso-custom-snooze');
        if (popover) popover.classList.add('impulso-hidden');
        if (popover) {
            popover.removeAttribute('data-conversation-id');
            var input = document.getElementById('impulso-custom-snooze-input');
            if (input) input.value = '';
        }
        var trigger = customSnoozeLifecycle ? customSnoozeLifecycle.close() : customSnoozeTrigger;
        customSnoozeTrigger = null;
        if (restoreFocus !== false && trigger && typeof trigger.focus === 'function') trigger.focus();
    }

    function openCustomSnooze(id, trigger) {
        var popover = document.getElementById('impulso-custom-snooze');
        var input = document.getElementById('impulso-custom-snooze-input');
        if (!popover || !input) return;
        trigger = trigger || conversationMenuTrigger || document.getElementById('impulso-snooze-button');
        closeCustomSnooze(false);
        customSnoozeTrigger = trigger || null;
        if (customSnoozeLifecycle) customSnoozeLifecycle.open(id, trigger);
        popover.setAttribute('data-conversation-id', String(id));
        input.value = '';
        popover.classList.remove('impulso-hidden');
        closeConversationContextMenu();
        input.focus();
    }

    function applyCustomSnooze() {
        var popover = document.getElementById('impulso-custom-snooze');
        var input = document.getElementById('impulso-custom-snooze-input');
        if (!popover || !input) return;
        var iso = typeof workflowHelpers.snoozeIsoFromLocal === 'function' ? workflowHelpers.snoozeIsoFromLocal(input.value) : '';
        if (!iso) {
            showToast('Data invalida', 'Escolha uma data e hora validas.', 'alert-triangle');
            return;
        }
        var id = Number(popover.getAttribute('data-conversation-id') || 0);
        closeCustomSnooze();
        if (id) mutateConversation(id, '/snooze', { snoozed_until: iso });
    }

    function openConversationContextMenu(id, trigger) {
        if (!canManageConversations()) return;
        var menu = document.getElementById('impulso-conversation-context-menu');
        if (!menu) return;
        var conversation = state.conversations.find(function (item) { return Number(item.id) === Number(id); });
        if (!conversation) return;
        conversationMenuTrigger = trigger || null;
        menu.innerHTML = '<div class="impulso-context-menu-title">' + escapeHtml(conversation.name) + '</div>' +
            '<button type="button" role="menuitem" data-conversation-menu-action="' + (conversation.unread > 0 ? 'read' : 'unread') + '">' + (conversation.unread > 0 ? 'Marcar como lida' : 'Marcar como não lida') + '</button>' +
            '<div class="impulso-context-menu-group"><span>Status</span><button type="button" role="menuitem" data-conversation-menu-action="status:open">Abrir</button><button type="button" role="menuitem" data-conversation-menu-action="status:pending">Pendente</button><button type="button" role="menuitem" data-conversation-menu-action="status:resolved">Resolver</button></div>' +
            '<div class="impulso-context-menu-group"><span>Prioridade</span>' + ['none', 'low', 'medium', 'high', 'urgent'].map(function (value) { var labels = { none: 'Sem prioridade', low: 'Baixa', medium: 'Média', high: 'Alta', urgent: 'Urgente' }; return '<button type="button" role="menuitem" data-conversation-menu-action="priority:' + value + '">' + labels[value] + '</button>'; }).join('') + '</div>' +
            '<div class="impulso-context-menu-group"><span>Adiar</span><button type="button" role="menuitem" data-conversation-menu-action="snooze:1h">1 hora</button><button type="button" role="menuitem" data-conversation-menu-action="snooze:4h">4 horas</button><button type="button" role="menuitem" data-conversation-menu-action="snooze:tomorrow">Amanhã 09:00</button><button type="button" role="menuitem" data-conversation-menu-action="snooze:monday">Próxima segunda 09:00</button></div>' +
            '<button type="button" role="menuitem" data-conversation-menu-action="assign:self">Atribuir a mim</button>';
        menu.innerHTML = menu.innerHTML.replace('<button type="button" role="menuitem" data-conversation-menu-action="assign:self">', '<button type="button" role="menuitem" data-conversation-menu-action="snooze:custom">Data e hora...</button><button type="button" role="menuitem" data-conversation-menu-action="assign:self">');
        var staffItems = state.assignmentOptions.staff.map(function (item) {
            return '<button type="button" role="menuitem" data-conversation-menu-action="assignment:' + item.id + '">' + escapeHtml(item.name || ('Agente ' + item.id)) + '</button>';
        }).join('');
        var teamItems = state.assignmentOptions.teams.map(function (item) {
            return '<button type="button" role="menuitem" data-conversation-menu-action="team:' + item.id + '">' + escapeHtml(item.name || ('Equipe ' + item.id)) + '</button>';
        }).join('');
        menu.insertAdjacentHTML('beforeend', '<div class="impulso-context-menu-group"><span>Responsavel</span><button type="button" role="menuitem" data-conversation-menu-action="assignment:0">Sem agente</button>' + staffItems + '</div><div class="impulso-context-menu-group"><span>Equipe</span><button type="button" role="menuitem" data-conversation-menu-action="team:0">Sem equipe</button>' + teamItems + '</div><button type="button" role="menuitem" data-conversation-menu-action="tags:edit">Editar etiquetas</button><button type="button" role="menuitem" data-conversation-menu-action="copy-link">Copiar link estavel</button><button type="button" role="menuitem" data-conversation-menu-action="open-tab">Abrir em nova aba</button>');
        menu.classList.remove('impulso-hidden');
        menu.setAttribute('aria-activedescendant', '');
        var menuItems = menu.querySelectorAll('[role="menuitem"]');
        conversationMenuKeyboard = workflowHelpers.createMenuKeyboardState ? workflowHelpers.createMenuKeyboardState(menuItems.length) : null;
        menuItems.forEach(function (item, index) {
            item.setAttribute('tabindex', index === 0 ? '0' : '-1');
        });
        if (menuItems.length) menuItems[0].focus();
        var rect = trigger && trigger.getBoundingClientRect ? trigger.getBoundingClientRect() : { left: 20, bottom: 80 };
        menu.style.left = Math.max(8, rect.left - 180) + 'px';
        menu.style.top = Math.min(window.innerHeight - 320, rect.bottom + 4) + 'px';
        menu.querySelectorAll('[data-conversation-menu-action]').forEach(function (button) {
            button.addEventListener('click', function () {
                var action = this.getAttribute('data-conversation-menu-action') || '';
                if (action === 'read' || action === 'unread') mutateConversation(id, action === 'read' ? '/read' : '/unread');
                else if (action.indexOf('status:') === 0) mutateConversation(id, '/status', { status: action.split(':')[1] });
                else if (action.indexOf('priority:') === 0) mutateConversation(id, '/priority', { priority: action.split(':')[1] });
                else if (action === 'snooze:custom') openCustomSnooze(id, conversationMenuTrigger);
                else if (action.indexOf('snooze:') === 0) mutateConversation(id, '/snooze', { snoozed_until: snoozePreset(action.split(':')[1]) });
                else if (action === 'assign:self') mutateConversation(id, '/assignment', workflowHelpers.assignmentMutationPayload ? workflowHelpers.assignmentMutationPayload({ assign_to_me: true }) : { assign_to_me: true });
                else if (action.indexOf('assignment:') === 0) mutateConversation(id, '/assignment', workflowHelpers.assignmentMutationPayload ? workflowHelpers.assignmentMutationPayload({ assignee_id: Number(action.split(':')[1]) || 0 }) : { assignee_id: Number(action.split(':')[1]) || 0 });
                else if (action.indexOf('team:') === 0) mutateConversation(id, '/assignment', workflowHelpers.assignmentMutationPayload ? workflowHelpers.assignmentMutationPayload({ team_id: Number(action.split(':')[1]) || 0 }) : { team_id: Number(action.split(':')[1]) || 0 });
                else if (action === 'tags:edit') editConversationTags(id);
                else if (action === 'copy-link') copyConversationLink(id);
                else if (action === 'open-tab') openConversationTab(id);
            });
        });
    }

    function conversationPermalink(id) {
        try {
            var url = new URL(window.location.href);
            url.searchParams.set('conversation', String(Number(id || 0)));
            url.searchParams.delete('message');
            return url.href;
        } catch (error) { return ''; }
    }

    function copyConversationLink(id) {
        var link = conversationPermalink(id);
        if (!link) return;
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(link).catch(function () {});
        else {
            var helper = document.createElement('textarea'); helper.value = link; helper.style.position = 'fixed'; helper.style.opacity = '0'; document.body.appendChild(helper); helper.select(); try { document.execCommand('copy'); } catch (error) {} helper.remove();
        }
        showToast('Link copiado', 'O link usa somente o identificador local autorizado.', 'link');
    }

    function openConversationTab(id) {
        var link = conversationPermalink(id);
        if (link) window.open(link, '_blank', 'noopener,noreferrer');
    }

    function editConversationTags(id) {
        var conversation = state.conversations.find(function (item) { return Number(item.id) === Number(id); });
        if (!conversation || typeof window.prompt !== 'function') return;
        var value = window.prompt('Etiquetas separadas por virgula', conversation.tags.join(', '));
        if (value === null) return;
        var tags = value.split(',').map(function (tag) { return tag.trim(); }).filter(Boolean);
        mutateConversation(id, '/tags', { tags: tags });
    }

    document.addEventListener('click', function (event) {
        if (!event.target.closest('#impulso-conversation-context-menu') && !event.target.closest('[data-conversation-menu]')) closeConversationContextMenu();
        var popover = document.getElementById('impulso-custom-snooze');
        var protectedSnoozeSurface = event.target.closest('#impulso-custom-snooze')
            || event.target.closest('#impulso-snooze-button')
            || event.target.closest('#impulso-conversation-context-menu');
        var shouldCloseCustomSnooze = customSnoozeLifecycle
            ? customSnoozeLifecycle.shouldCloseOnOutsideClick(!!protectedSnoozeSurface)
            : !protectedSnoozeSurface;
        if (popover && !popover.classList.contains('impulso-hidden') && shouldCloseCustomSnooze) closeCustomSnooze();
    });
    document.addEventListener('keydown', function (event) {
        var popover = document.getElementById('impulso-custom-snooze');
        if (popover && !popover.classList.contains('impulso-hidden')) {
            var closesOnEscape = customSnoozeLifecycle ? customSnoozeLifecycle.shouldCloseOnKey(event.key) : event.key === 'Escape';
            if (closesOnEscape) {
                event.preventDefault();
                closeCustomSnooze();
            }
            return;
        }
        var menu = document.getElementById('impulso-conversation-context-menu');
        if (!menu || menu.classList.contains('impulso-hidden')) return;
        var items = menu.querySelectorAll('[role="menuitem"]');
        if (event.key === 'Escape') {
            event.preventDefault();
            closeConversationContextMenu();
            return;
        }
        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].indexOf(event.key) < 0 || !items.length) return;
        event.preventDefault();
        var index = conversationMenuKeyboard ? conversationMenuKeyboard.key(event.key) : 0;
        items.forEach(function (item, itemIndex) { item.setAttribute('tabindex', itemIndex === index ? '0' : '-1'); });
        items[index].focus();
    });

    function conversationContext() {
        return [String(state.channelId), state.status, state.search, JSON.stringify(state.filters)].join('|');
    }

    function currentConversationFilterState() {
        return {
            channelId: state.channelId,
            status: state.status,
            search: state.search,
            extra: Object.assign({}, state.filters, { current_user_id: Number(config.actorId || 0) })
        };
    }

    function currentSavedViewFilters() {
        var unassignedQueue = state.status === 'unassigned';
        return {
            status: unassignedQueue || state.status === 'all' ? '' : state.status,
            channel: state.channelId === 'all' ? '' : state.channelId,
            search: state.search || '',
            assignee: unassignedQueue ? 'unassigned' : (state.filters.assignee_id || ''),
            team: state.filters.team_id || '',
            priority: state.filters.priority || '',
            unread: state.filters.unread || '',
            conversation_type: state.filters.conversation_type || '',
            bot_status: state.filters.bot_status || '',
            last_activity_from: state.filters.last_activity_from || '',
            last_activity_to: state.filters.last_activity_to || '',
            tags: state.filters.tags || ''
        };
    }

    function applySavedViewFilters(snapshot) {
        snapshot = snapshot && typeof snapshot === 'object' ? snapshot : {};
        var savedStatus = String(snapshot.status || '');
        state.status = savedStatus === 'unassigned' ? 'unassigned' : (['open', 'pending', 'resolved', 'snoozed'].indexOf(savedStatus) >= 0 ? savedStatus : 'all');
        state.channelId = snapshot.channel || snapshot.instance ? String(snapshot.channel || snapshot.instance) : 'all';
        state.search = String(snapshot.search || '').trim();
        state.filters.assignee_id = state.status === 'unassigned' ? 'unassigned' : String(snapshot.assignee || '');
        state.filters.team_id = String(snapshot.team || '');
        ['priority', 'unread', 'conversation_type', 'bot_status', 'last_activity_from', 'last_activity_to', 'tags'].forEach(function (key) { state.filters[key] = String(snapshot[key] || ''); });
        state.bulkSelectedIds = [];
        var search = document.getElementById('impulso-conversation-search'); if (search) search.value = state.search;
        document.querySelectorAll('[data-conversation-filter-control]').forEach(function (control) { var key = control.getAttribute('data-conversation-filter-control'); control.value = state.filters[key] || ''; });
        document.querySelectorAll('[data-conversation-filter]').forEach(function (item) {
            var selected = (item.getAttribute('data-conversation-filter') || 'all') === state.status;
            item.classList.toggle('active', selected);
            item.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        renderChannels();
        renderFilterSummary();
        setActiveConversationId(null, 'filter');
        return loadConversations(true);
    }

    function clearBulkSelection() {
        state.bulkSelectedIds = [];
        var list = document.getElementById('impulso-conversation-list');
        if (list) list.querySelectorAll('[data-bulk-select]').forEach(function (item) { item.checked = false; });
        if (window.ImpulsoBulkActions && typeof window.ImpulsoBulkActions.render === 'function') window.ImpulsoBulkActions.render();
    }

    function conversationMatchesCurrentFilters(conversation) {
        return typeof workflowHelpers.conversationMatchesFilters === 'function'
            ? workflowHelpers.conversationMatchesFilters(conversation, currentConversationFilterState())
            : true;
    }

    function renderFilterSummary() {
        var summary = document.getElementById('impulso-active-filter-summary');
        var clear = document.querySelector('[data-conversation-filter-clear]');
        if (!summary) return;
        var active = [];
        if (state.status !== 'all') active.push('Status: ' + state.status);
        if (state.search) active.push('Busca: ' + state.search);
        Object.keys(state.filters).forEach(function (key) {
            if (!state.filters[key]) return;
            var control = document.querySelector('[data-conversation-filter-control="' + key + '"]');
            var label = control && control.options && control.selectedIndex >= 0 ? control.options[control.selectedIndex].textContent : state.filters[key];
            active.push((control && control.getAttribute('aria-label') || key) + ': ' + label);
        });
        summary.innerHTML = '';
        active.forEach(function (label) {
            var chip = document.createElement('span');
            chip.className = 'impulso-filter-chip';
            chip.textContent = label;
            summary.appendChild(chip);
        });
        if (clear) clear.classList.toggle('impulso-hidden', active.length === 0);
    }

    function clearConversationFilters() {
        state.status = 'all';
        state.search = '';
        Object.keys(state.filters).forEach(function (key) { state.filters[key] = ''; });
        var search = document.getElementById('impulso-conversation-search');
        if (search) search.value = '';
        document.querySelectorAll('[data-conversation-filter-control]').forEach(function (control) { control.value = ''; });
        document.querySelectorAll('[data-conversation-filter]').forEach(function (item) {
            var selected = item.getAttribute('data-conversation-filter') === 'all';
            item.classList.toggle('active', selected);
            item.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        renderFilterSummary();
        setActiveConversationId(null, 'filter_clear');
        loadConversations(true);
    }

    function conversationQuery(page, limit, filters) {
        filters = filters || {};
        var params = new URLSearchParams();
        params.set('page', String(page || 1));
        params.set('limit', String(limit || config.conversationPageSize || 30));
        if (filters.channelId !== 'all') params.set('instance_id', String(filters.channelId));
        if (filters.status !== 'all') params.set('status', filters.status);
        if (filters.search) params.set('search', filters.search);
        Object.keys(filters.extra || {}).forEach(function (key) {
            if (filters.extra[key] !== '' && filters.extra[key] != null) params.set(key, String(filters.extra[key]));
        });
        return params.toString();
    }

    function refreshActiveConversationRecord() {
        var conversationId = Number(state.activeConversationId || 0);
        if (!conversationId || !state.activeConversationRecord || !endpoint('conversations')) return Promise.resolve(null);
        if (state.conversations.some(function (item) { return Number(item.id) === conversationId; })) return Promise.resolve(state.activeConversationRecord);
        if (state.activeConversationRefreshLoading) return Promise.resolve(null);
        var requestId = ++state.activeConversationRefreshSequence;
        var operationContext = workflowSession ? workflowSession.capture(conversationId, { type: 'active_conversation_refresh', requestId: requestId }) : { conversationId: conversationId, requestId: requestId };
        state.activeConversationRefreshLoading = true;
        return api(endpointWithId('conversations', conversationId)).then(function (payload) {
            if (requestId !== state.activeConversationRefreshSequence) return null;
            if (workflowSession ? !workflowSession.isCurrent(operationContext) : Number(state.activeConversationId) !== conversationId) return null;
            var data = payload && payload.data && typeof payload.data === 'object' ? payload.data : null;
            if (!data || Number(data.id || 0) !== conversationId) return null;
            var conversation = updateConversationRecord(data);
            if (conversation && Number(state.activeConversationId) === conversationId) applyActiveConversation(activeConversation(), { loadAuxiliary: false });
            return conversation;
        }).catch(function (error) {
            if (requestId !== state.activeConversationRefreshSequence) return null;
            if (workflowSession ? !workflowSession.isCurrent(operationContext) : Number(state.activeConversationId) !== conversationId) return null;
            var status = Number(error && error.status || 0);
            var disposition = workflowHelpers.activeConversationRefreshDisposition
                ? workflowHelpers.activeConversationRefreshDisposition(status)
                : (status === 404 || status === 403 ? 'clear' : 'preserve');
            if (disposition === 'clear') clearConversation();
            /* The active record remains usable; the next poll retries the canonical read. */
            return null;
        }).finally(function () {
            if (requestId === state.activeConversationRefreshSequence) state.activeConversationRefreshLoading = false;
        });
    }

    function loadConversations(reset, silent) {
        if (!endpoint('conversations') || runtime.destroyed) return Promise.resolve();
        if (reset && !silent) state.bulkSelectedIds = [];
        var requestContext = conversationContext();
        if (state.listLoading && state.listRequestContext === requestContext) return Promise.resolve();
        var requestId = ++state.listRequestSequence;
        state.listLoading = true;
        state.listRequestContext = requestContext;
        var filters = { channelId: state.channelId, status: state.status, search: state.search, extra: state.filters };
        var pageSize = Number(config.conversationPageSize || 30);
        var requestedPage = reset ? 1 : state.page + 1;
        var loadedPageCount = Math.max(1, Math.ceil(state.conversations.length / pageSize));
        var maxSilentPages = Math.max(1, Math.floor(100 / pageSize));
        var requestedLimit = reset && silent
            ? pageSize * Math.min(maxSilentPages, loadedPageCount)
            : pageSize;
        var list = document.getElementById('impulso-conversation-list');
        if (!silent && reset && list) {
            list.innerHTML = '<div class="impulso-conversation-empty"><div class="spinner-border spinner-border-sm" role="status"></div><strong>Carregando conversas</strong><span>Consultando dados do atendimento.</span></div>';
        }
        return api(endpoint('conversations') + '?' + conversationQuery(requestedPage, requestedLimit, filters), { timingLabel: 'local_conversations' }).then(function (payload) {
            if (requestId !== state.listRequestSequence || requestContext !== conversationContext()) return;
            var rows = Array.isArray(payload.data) ? payload.data.map(normalizeConversation) : [];
            if (reset) {
                if (silent) {
                    var reconciliation = typeof workflowHelpers.reconcileConversationRows === 'function'
                        ? workflowHelpers.reconcileConversationRows(state.conversations, rows, requestedLimit, payload.meta && payload.meta.total, pageSize)
                        : { rows: rows, page: Math.max(1, Math.ceil(rows.length / pageSize)), hasMore: !!(payload.meta && payload.meta.has_more) };
                    state.conversations = reconciliation.rows;
                    state.page = reconciliation.page;
                    state.hasMore = reconciliation.hasMore;
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
            if (reset && silent && Array.isArray(state.bulkSelectedIds)) {
                var authoritativeIds = {};
                state.conversations.forEach(function (item) { authoritativeIds[Number(item.id)] = true; });
                state.bulkSelectedIds = state.bulkSelectedIds.filter(function (id) { return !!authoritativeIds[Number(id)]; });
            }
            if (!(reset && silent)) {
                state.page = requestedPage;
                state.hasMore = !!(payload.meta && payload.meta.has_more);
            }
            state.filterCounts = payload.meta && payload.meta.counts ? payload.meta.counts : state.filterCounts;
            Object.keys(state.filterCounts).forEach(function (key) {
                document.querySelectorAll('[data-filter-count="' + key + '"]').forEach(function (element) { element.textContent = String(state.filterCounts[key] || 0); });
            });
            var responseContainsFullList = payload.meta && ((payload.meta.total != null && Number(payload.meta.total) <= rows.length) || (requestedPage === 1 && payload.meta.has_more === false));
            var activeReconciliation = typeof workflowHelpers.reconcileActiveConversationRecord === 'function'
                ? workflowHelpers.reconcileActiveConversationRecord(state.activeConversationId, state.activeConversationRecord, state.conversations, !!responseContainsFullList, state.activeConversationDetached)
                : { activeId: Number(state.activeConversationId || 0), record: state.activeConversationRecord, cleared: !!(state.activeConversationId && responseContainsFullList && !state.activeConversationDetached), listed: false };
            if (activeReconciliation.listed) state.activeConversationRecord = activeReconciliation.record;
            var activeWasCleared = false;
            if (state.activeConversationId && reset && activeReconciliation.cleared) {
                clearConversation();
                activeWasCleared = true;
            }
            renderConversationList();
            if (state.activeConversationId && activeConversation()) {
                applyActiveConversation(activeConversation(), { loadAuxiliary: false });
                if (!activeReconciliation.listed && !activeWasCleared) refreshActiveConversationRecord();
            }
            if (!state.activeConversationId && !activeWasCleared && state.conversations.length) selectConversation(state.conversations[0].id, true);
            if (!state.activeConversationId && !state.conversations.length) clearConversation();
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
        return api(endpoint('instances'), { timingLabel: 'instance_read' }).then(function (payload) {
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
        setActiveConversationId(null, 'channel');
        loadConversations(true).then(function () { return syncPollingChannel(true); });
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
        setActiveConversationId(null, 'clear');
        state.activeConversationRecord = null;
        state.activeConversationDetached = false;
        state.messages = [];
        state.messageAfterId = 0;
        state.hasMoreBefore = false;
        state.messageRenderFingerprint = '';
        state.activeConversationSurfaceFingerprint = '';
        document.querySelectorAll('.impulso-conversation-item').forEach(function (item) { item.classList.remove('active'); });
        setText('impulso-active-avatar', '—');
        setText('impulso-active-name', 'Nenhuma conversa selecionada');
        setText('impulso-active-instance', selectedInstance() ? selectedInstance().name : 'Todos os canais');
        var body = document.getElementById('impulso-chat-body');
        if (body) body.innerHTML = '<div class="impulso-empty"><div class="impulso-empty-icon"><i data-feather="message-circle"></i></div><h4>Sem conversa para exibir</h4><p>Selecione um canal ou aguarde a chegada de uma mensagem.</p></div>';
        updateComposerState();
        replaceIcons();
    }

    function renderAssignmentOptions() {
        var assignee = document.getElementById('impulso-assignee-select');
        var team = document.getElementById('impulso-team-select');
        var filterAssignee = document.querySelector('[data-conversation-filter-control="assignee_id"]');
        var filterTeam = document.querySelector('[data-conversation-filter-control="team_id"]');
        var hydrated = workflowHelpers.hydrateAssignmentOptions ? workflowHelpers.hydrateAssignmentOptions(state.assignmentOptions, state.filters) : {
            staff: Array.isArray(state.assignmentOptions.staff) ? state.assignmentOptions.staff : [],
            teams: Array.isArray(state.assignmentOptions.teams) ? state.assignmentOptions.teams : [],
            assignee_id: String(state.filters.assignee_id || ''),
            team_id: String(state.filters.team_id || ''),
            changed: false
        };
        var staff = hydrated.staff;
        var teams = hydrated.teams;
        var selectedAssignee = hydrated.assignee_id;
        var selectedTeam = hydrated.team_id;
        var changed = hydrated.changed;
        state.filters.assignee_id = selectedAssignee;
        state.filters.team_id = selectedTeam;
        if (assignee) assignee.innerHTML = '<option value="">Sem agente</option>' + staff.map(function (item) { return '<option value="' + item.id + '">' + escapeHtml(item.name || ('Agente ' + item.id)) + '</option>'; }).join('');
        if (team) team.innerHTML = '<option value="">Sem equipe</option>' + teams.map(function (item) { return '<option value="' + item.id + '">' + escapeHtml(item.name || ('Equipe ' + item.id)) + '</option>'; }).join('');
        if (filterAssignee) {
            filterAssignee.innerHTML = '<option value="">Todos os agentes</option><option value="unassigned">Sem agente</option><option value="me">Atribuidas a mim</option>' + staff.map(function (item) { return '<option value="' + item.id + '">' + escapeHtml(item.name || ('Agente ' + item.id)) + '</option>'; }).join('');
            filterAssignee.value = selectedAssignee;
        }
        if (filterTeam) {
            filterTeam.innerHTML = '<option value="">Todas as equipes</option>' + teams.map(function (item) { return '<option value="' + item.id + '">' + escapeHtml(item.name || ('Equipe ' + item.id)) + '</option>'; }).join('');
            filterTeam.value = selectedTeam;
        }
        var active = activeConversation();
        if (active) {
            if (assignee) assignee.value = active.assignee_id ? String(active.assignee_id) : '';
            if (team) team.value = active.team_id ? String(active.team_id) : '';
        }
        return changed;
    }

    function loadAssignmentOptions() {
        if (!endpoint('conversationAssignmentOptions')) return Promise.resolve();
        return api(endpoint('conversationAssignmentOptions')).then(function (payload) {
            state.assignmentOptions = payload && payload.data ? payload.data : { staff: [], teams: [] };
            var changed = renderAssignmentOptions();
            if (changed) {
                renderFilterSummary();
                setActiveConversationId(null, 'options_reconcile');
                loadConversations(true);
            }
        }).catch(function () { /* selectors remain usable with the current DTO */ });
    }

    function applyWorkflowFields(conversation) {
        var status = document.getElementById('impulso-conversation-status');
        var priority = document.getElementById('impulso-conversation-priority');
        var assignee = document.getElementById('impulso-assignee-select');
        var team = document.getElementById('impulso-team-select');
        var snooze = document.getElementById('impulso-conversation-snooze');
        if (status) status.value = conversation.status;
        if (priority) priority.value = conversation.priority;
        if (assignee) assignee.value = conversation.assignee_id ? String(conversation.assignee_id) : '';
        if (team) team.value = conversation.team_id ? String(conversation.team_id) : '';
        if (snooze) snooze.textContent = conversation.snoozed_until ? 'Adiada até ' + conversation.snoozed_until : 'Sem snooze ativo';
        var resolveButton = document.getElementById('impulso-resolve-button');
        if (resolveButton) resolveButton.innerHTML = conversation.status === 'resolved' ? '<i data-feather="rotate-ccw"></i> Reabrir' : '<i data-feather="check"></i> Resolver';
        replaceIcons();
    }

    function loadConversationAuxiliary(conversation) {
        var conversationId = Number(conversation && conversation.id || 0);
        var operationContext = workflowSession ? workflowSession.capture(conversationId, { type: 'conversation_auxiliary' }) : { conversationId: conversationId };
        if (!conversationId) return;
        var previous = document.getElementById('impulso-previous-conversations');
        var activity = document.getElementById('impulso-conversation-activity');
        if (previous && endpoint('conversations')) {
            previous.innerHTML = '<small>Carregando conversas anteriores...</small>';
            api(endpointWithId('conversations', conversationId, '/previous')).then(function (payload) {
                if (workflowSession ? !workflowSession.isCurrent(operationContext) : Number(state.activeConversationId) !== conversationId) return;
                var rows = Array.isArray(payload.data) ? payload.data : [];
                previous.innerHTML = rows.length ? rows.map(function (row) { return '<button type="button" class="impulso-previous-row" data-previous-conversation="' + row.id + '"><strong>' + escapeHtml(row.name) + '</strong><span>' + escapeHtml(row.last_message_preview || 'Sem mensagens') + '</span></button>'; }).join('') : '<small>Nenhuma conversa anterior encontrada.</small>';
                previous.querySelectorAll('[data-previous-conversation]').forEach(function (button) { button.addEventListener('click', function () { openConversationById(Number(this.getAttribute('data-previous-conversation')), { loadAuxiliary: true }); }); });
            }).catch(function () { if (workflowSession ? workflowSession.isCurrent(operationContext) : Number(state.activeConversationId) === conversationId) previous.innerHTML = '<small>Conversas anteriores indisponíveis.</small>'; });
        }
        if (activity && endpoint('conversations')) {
            activity.innerHTML = '<small>Carregando atividade...</small>';
            api(endpointWithId('conversations', conversationId, '/activity')).then(function (payload) {
                if (workflowSession ? !workflowSession.isCurrent(operationContext) : Number(state.activeConversationId) !== conversationId) return;
                var rows = Array.isArray(payload.data) ? payload.data : [];
                activity.innerHTML = rows.length ? rows.map(function (row) { return '<div class="impulso-activity-row"><strong>' + escapeHtml(row.text || row.label || 'Atualizacao da conversa') + '</strong>' + (row.text ? '' : '<span>' + escapeHtml(row.actor || 'Sistema') + '</span>') + '<time>' + escapeHtml(row.created_at || '') + '</time></div>'; }).join('') : '<small>Nenhuma atividade registrada.</small>';
            }).catch(function () { if (workflowSession ? workflowSession.isCurrent(operationContext) : Number(state.activeConversationId) === conversationId) activity.innerHTML = '<small>Atividade indisponível.</small>'; });
        }
    }

    function projectGroupDetails(conversation) {
        var section = document.getElementById('impulso-group-section');
        var list = document.getElementById('impulso-group-participants');
        var count = document.getElementById('impulso-group-participant-count');
        if (!section || !list || !count) return false;
        var isGroup = conversation && conversation.conversation_type === 'group';
        section.classList.toggle('impulso-hidden', !isGroup);
        if (!isGroup) {
            list.innerHTML = '';
            count.textContent = '0';
        }
        return isGroup;
    }

    function applyActiveConversation(conversation, options) {
        options = options || {};
        if (!conversation) return;
        var surfaceFingerprint = conversationSurfaceFingerprint(conversation);
        if (state.activeConversationSurfaceFingerprint === surfaceFingerprint && options.forceSurface !== true) return;
        state.activeConversationSurfaceFingerprint = surfaceFingerprint;
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
        setText('impulso-contact-phone', conversation.conversation_type === 'group' ? 'Grupo WhatsApp' : conversation.phone);
        setText('impulso-bot-conversation-state', conversation.bot_status === 'active' ? 'Ativo até um atendente responder' : (conversation.bot_status === 'handoff' ? 'Encaminhado para humano' : 'Pausado'));
        var botButton = document.querySelector('[data-impulso-action="toggle-conversation-bot"]');
        if (botButton) botButton.textContent = conversation.bot_status === 'active' ? 'Pausar' : 'Retomar';
        var tags = document.getElementById('impulso-contact-tags');
        if (tags) tags.innerHTML = conversation.tags.map(function (tag) { return '<span class="impulso-badge primary">' + escapeHtml(tag) + '</span>'; }).join('');
        applyWorkflowFields(conversation);
        projectGroupDetails(conversation);
        if (options.loadAuxiliary === true) {
            loadConversationAuxiliary(conversation);
            loadGroupDetails(conversation);
        }
        scheduleServiceWindowTimer(conversation);
        updateComposerState();
    }

    function loadGroupDetails(conversation) {
        var section = document.getElementById('impulso-group-section');
        var list = document.getElementById('impulso-group-participants');
        var count = document.getElementById('impulso-group-participant-count');
        if (!section || !list || !count) return;
        var isGroup = projectGroupDetails(conversation);
        if (!isGroup) {
            return;
        }
        var conversationId = Number(conversation.id || 0);
        var operationContext = workflowSession ? workflowSession.capture(conversationId, { type: 'conversation_group' }) : { conversationId: conversationId };
        list.innerHTML = '<small>Carregando participantes...</small>';
        api(endpointWithId('conversations', conversationId, '/group')).then(function (payload) {
            if (workflowSession ? !workflowSession.isCurrent(operationContext) : Number(state.activeConversationId) !== conversationId) return;
            var group = payload && payload.data ? payload.data : {};
            var participants = Array.isArray(group.participants) ? group.participants : [];
            count.textContent = String(Number(group.participant_count || participants.length));
            if (!participants.length) {
                list.innerHTML = '<small>Os participantes serão identificados conforme enviarem mensagens.</small>';
                return;
            }
            list.innerHTML = '<ul class="impulso-list">' + participants.map(function (participant) {
                var name = participant.name || participant.phone || participant.jid || 'Participante';
                var details = participant.is_self ? 'Este número' : (participant.role === 'admin' ? 'Administrador' : (participant.phone || 'Número não resolvido'));
                return '<li class="impulso-list-item"><div class="impulso-avatar sm">' + escapeHtml(initials(name, participant.phone || '')) + '</div><div class="impulso-list-copy"><strong>' + escapeHtml(name) + '</strong><span>' + escapeHtml(details) + '</span></div></li>';
            }).join('') + '</ul>';
            replaceIcons();
        }).catch(function () {
            if (workflowSession ? !workflowSession.isCurrent(operationContext) : Number(state.activeConversationId) !== conversationId) return;
            count.textContent = '0';
            list.innerHTML = '<small>Participantes ainda não sincronizados.</small>';
        });
    }

    function selectConversationRecord(conversation, options) {
        options = options || {};
        var silent = !!options.silent;
        var detached = options.detached === true;
        if (!conversation || !conversation.id) return Promise.resolve(null);
        conversation = normalizeConversation(conversation);
        if (Number(state.activeConversationId) === Number(conversation.id) && (state.messages.length || state.messageLoading)) {
            state.activeConversationRecord = Object.assign({}, conversation);
            state.activeConversationDetached = detached;
            applyActiveConversation(state.activeConversationRecord, { loadAuxiliary: false });
            return Promise.resolve(state.activeConversationRecord);
        }
        state.activeConversationRecord = Object.assign({}, conversation);
        state.activeConversationDetached = detached;
        setActiveConversationId(conversation.id, 'selection');
        if (templatePicker) { templatePicker.reset(conversation.id); templatePicker.close(false); }
        state.messages = [];
        state.messageAfterId = 0;
        state.hasMoreBefore = false;
        state.messageRenderFingerprint = '';
        document.querySelectorAll('.impulso-conversation-item').forEach(function (item) {
            item.classList.toggle('active', Number(item.getAttribute('data-conversation-id')) === Number(conversation.id));
        });
        applyActiveConversation(state.activeConversationRecord, { loadAuxiliary: options.loadAuxiliary !== false });
        var history = loadMessages('reset', !silent).then(function () {
            return options.skipRemoteSync === true ? null : syncConversationHistory(conversation.id, false);
        });
        markConversationRead(conversation);
        var sidebar = document.getElementById('impulso-chat-sidebar');
        if (sidebar && isCompactInbox()) {
            sidebar.classList.remove('open');
            syncInboxPanels();
        }
        return history.then(function () { return state.activeConversationRecord; });
    }

    function openConversationById(id, options) {
        options = options || {};
        id = Number(id || 0);
        if (id < 1 || runtime.destroyed) return Promise.resolve(null);
        var operationContext = conversationNavigation ? conversationNavigation.begin(id) : { conversationId: id, sequence: 0 };
        var listed = state.conversations.find(function (item) { return Number(item.id) === id; });
        if (listed) return selectConversationRecord(listed, { silent: !!options.silent, loadAuxiliary: options.loadAuxiliary !== false, detached: false, skipRemoteSync: options.skipRemoteSync === true });
        if (!endpoint('conversations')) return Promise.resolve(null);
        return api(endpointWithId('conversations', id)).then(function (payload) {
            if (conversationNavigation && !conversationNavigation.isCurrent(operationContext)) return null;
            var data = payload && payload.data && typeof payload.data === 'object' ? payload.data : null;
            var canonical = data ? normalizeConversation(data) : null;
            if (!canonical || Number(canonical.id) !== id) return null;
            return selectConversationRecord(canonical, { silent: !!options.silent, loadAuxiliary: options.loadAuxiliary !== false, detached: true, skipRemoteSync: options.skipRemoteSync === true });
        }).catch(function (error) {
            if (!conversationNavigation || conversationNavigation.isCurrent(operationContext)) showToast('Falha ao abrir conversa', error.message, 'alert-triangle');
            return null;
        });
    }

    function selectConversation(id, silent) {
        return openConversationById(id, { silent: !!silent, loadAuxiliary: true });
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

    function messageHtml(message) {
        var renderers = window.ImpulsoMessageRenderers;
        if (!renderers || typeof renderers.renderMessage !== 'function') return '';
        return renderers.renderMessage(message, { location: window.location, time: messageTime });
    }

    function messageRenderFingerprint() {
        return JSON.stringify([
            state.hasMoreBefore,
            state.messages.map(function (message) {
                var metadata = message.metadata && typeof message.metadata === 'object' ? message.metadata : {};
                var error = message.error && typeof message.error === 'object' ? message.error : {};
                return [
                    message.id,
                    message.external_message_id,
                    message.client_message_id,
                    message.message_type || message.type,
                    message.text_content || (message.content && message.content.text),
                    message.status,
                    message.sent_at,
                    message.media_url,
                    message.delivery_error,
                    metadata.send_state,
                    error.code,
                    error.message,
                    message.reactions
                ];
            })
        ]);
    }

    function mergeReactionUpdates(rows) {
        (Array.isArray(rows) ? rows : []).forEach(function (message) {
            var existing = state.messages.find(function (item) { return sameMessage(item, message); });
            if (existing) mergeMessageRecord(existing, message);
        });
    }

    function renderMessages(options) {
        options = options || {};
        var body = document.getElementById('impulso-chat-body');
        if (!body) return;
        var fingerprint = messageRenderFingerprint();
        if (state.messageRenderFingerprint === fingerprint) return;
        state.messageRenderFingerprint = fingerprint;
        var oldHeight = body.scrollHeight;
        var oldTop = body.scrollTop;
        var nearBottom = oldHeight - oldTop - body.clientHeight < 90;
        var html = '';
        var lastDay = '';
        var renderedMessages = 0;
        state.messages.forEach(function (message) {
            var messageType = window.ImpulsoMessageSafeContent && window.ImpulsoMessageSafeContent.normalizedType
                ? window.ImpulsoMessageSafeContent.normalizedType(message)
                : String(message.type || message.message_type || '');
            if (messageType === 'reaction') return;
            renderedMessages += 1;
            var day = dayLabel(message.sent_at);
            if (day !== lastDay) {
                html += '<div class="impulso-day-divider">' + escapeHtml(day) + '</div>';
                lastDay = day;
            }
            html += messageHtml(message);
        });
        if (!renderedMessages) html = '<div class="impulso-empty"><div class="impulso-empty-icon"><i data-feather="message-square"></i></div><h4>Histórico vazio</h4><p>Envie a primeira mensagem desta conversa.</p></div>';
        if (state.hasMoreBefore) html = '<div class="impulso-load-older"><button class="btn btn-default btn-sm" id="impulso-load-older-messages" type="button">Carregar mensagens anteriores</button></div>' + html;
        body.innerHTML = html;
        var older = document.getElementById('impulso-load-older-messages');
        if (older) older.addEventListener('click', function () { loadMessages('before', true); });
        if (window.ImpulsoMessageActions && typeof window.ImpulsoMessageActions.bind === 'function') {
            window.ImpulsoMessageActions.bind(body, {
                capabilities: (activeConversation() || {}).capabilities || {},
                permissions: config.permissions || {},
                conversationId: function () { return Number(state.activeConversationId || 0); },
                getMessage: function (id) {
                    return state.messages.find(function (item) {
                        return String(item.id || item.client_message_id || item.external_message_id) === String(id);
                    }) || null;
                },
                retry: function (message) { retryMessage(message && message.client_message_id); },
                api: api,
                endpoint: endpoint,
                endpointWithId: endpointWithId,
                toast: showToast,
                replaceIcons: replaceIcons,
                onReaction: function (payload) {
                    var data = payload && payload.data ? normalizeMessage(payload.data) : null;
                    if (!data) return;
                    mergeReactionUpdates([data]);
                    renderMessages({ forceBottom: false });
                }
            });
        }
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
        if (mode !== 'reset' && state.reactionAfterCursor > 0) params.set('reaction_after', String(state.reactionAfterCursor));
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
        var messageUrl = endpointWithId('conversations', conversation.id, '/messages') + '?' + params.toString();
        return api(messageUrl, { timingLabel: 'local_messages' }).then(function (payload) {
            if (requestId !== state.messageRequestSequence || Number(state.activeConversationId) !== Number(requestedConversationId)) return;
            if (mode === 'reset' && payload.meta && payload.meta.sync_error) {
                showToast('Histórico local', payload.meta.sync_error, 'alert-triangle');
            }
            var rows = Array.isArray(payload.data) ? payload.data.map(normalizeMessage) : [];
            if (mode === 'reset') {
                state.messages = [];
                state.messageAfterId = 0;
                state.reactionAfterCursor = 0;
            }
            if (mode === 'reset' || mode === 'before') {
                state.hasMoreBefore = !!(payload.meta && payload.meta.has_more_before);
            }
            mergeMessages(rows, mode === 'before');
            mergeReactionUpdates(payload.meta && payload.meta.reaction_updates);
            if (payload.meta && Number(payload.meta.reaction_cursor || 0) > state.reactionAfterCursor) state.reactionAfterCursor = Number(payload.meta.reaction_cursor);
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
        if (!conversation || !conversation.unread || !canManageConversations() || initialSettings.auto_mark_read === false || initialSettings.auto_mark_read === 0 || initialSettings.auto_mark_read === '0') return;
        var operationContext = workflowMutations && workflowMutations.begin ? workflowMutations.begin(conversation.id, 'read_state') : null;
        api(endpointWithId('conversations', conversation.id, '/read'), { method: 'POST', body: {} }).then(function () {
            if (workflowMutations && operationContext && !workflowMutations.isCurrent(operationContext)) return;
            updateConversationRecord(Object.assign({}, conversation, { unread_count: 0, unread: 0 }));
            loadInstances(true);
        }).catch(function () { if (!workflowMutations || !operationContext || workflowMutations.isCurrent(operationContext)) { /* polling will reconcile */ } });
    }

    function createClientMessageId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
        return 'web-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    function canSendMessages() {
        return !config.permissions || config.permissions.send === true;
    }

    function canManageConversations() {
        return !config.permissions || config.permissions.manageConversations === true;
    }

    function setComposerMode(mode) {
        var previousMode = state.composerMode;
        state.composerMode = mode === 'note' ? 'note' : 'reply';
        if (previousMode !== state.composerMode) {
            composerModeChangeHandlers.slice().forEach(function (handler) {
                try { handler({ fromMode: previousMode, toMode: state.composerMode }); } catch (error) { /* advisory presence must not block mode changes */ }
            });
        }
        updateComposerState();
    }

    function updateComposerState() {
        var conversation = activeConversation();
        var input = document.getElementById('impulso-message-input');
        var button = document.getElementById('impulso-send-message');
        var noteMode = state.composerMode === 'note';
        var canSend = canSendMessages();
        var windowState = conversation && conversation.service_window ? conversation.service_window : {};
        var windowClosed = !noteMode && !!windowState.required && windowState.open === false;
        var allowed = noteMode ? canManageConversations() && !!conversation : canSend && !!conversation && conversation.instance_status === 'connected' && !windowClosed;
        var disabled = !allowed;
        if (input) input.disabled = disabled;
        if (button) button.disabled = disabled;
        var attach = document.querySelector('[data-impulso-action="attach"]');
        var voice = document.querySelector('[data-impulso-action="voice"]');
        if (attach) attach.disabled = disabled;
        if (voice) voice.disabled = disabled;
        var templateButton = document.getElementById('impulso-template-button');
        var templatesAllowed = !!conversation && !!conversation.capabilities && !!conversation.capabilities.actions && conversation.capabilities.actions.send_template === true && !noteMode;
        if (templateButton) {
            templateButton.classList.toggle('impulso-hidden', !templatesAllowed);
            templateButton.disabled = !conversation || conversation.instance_status !== 'connected';
        }
        var serviceWindow = document.getElementById('impulso-service-window');
        if (serviceWindow) {
            serviceWindow.classList.toggle('impulso-hidden', !windowClosed);
            serviceWindow.textContent = windowClosed ? 'A janela de atendimento está fechada. Selecione um template aprovado para enviar uma mensagem.' : '';
        }
    }

    function sendMessage(text, clientId, existing, options) {
        options = options || {};
        var conversation = activeConversation();
        if (!conversation) return Promise.reject(new Error('Selecione uma conversa para responder.'));
        if (!canSendMessages()) {
            showToast('Envio não permitido', 'Seu perfil não possui permissão para enviar mensagens.', 'shield');
            return Promise.reject(new Error('Envio nao permitido.'));
        }
        text = String(text || '').trim();
        if (!text) {
            showToast('Mensagem vazia', 'Digite algum conteúdo antes de enviar.', 'alert-circle');
            return Promise.reject(new Error('Mensagem vazia.'));
        }
        if (conversation.instance_status !== 'connected') {
            showToast('Canal desconectado', 'A mensagem não pode ser enviada por esta instância.', 'wifi-off');
            return Promise.reject(new Error('Canal desconectado.'));
        }
        clientId = clientId || createClientMessageId();
        if (state.pendingSends[clientId]) return Promise.reject(new Error('Este envio ja esta em andamento.'));
        var optimisticStartedAt = Number(options.timingStartedAt || timingNow());
        var optimistic = existing || normalizeMessage({
            id: clientId,
            client_message_id: clientId,
            direction: 'outgoing',
            message_type: 'text',
            text_content: text,
            status: 'sending',
            sent_at: new Date().toISOString(),
            message_timestamp: Math.floor(Date.now() / 1000),
            temporary: true,
            reply_to: options.replyToMessageId ? { local_message_id: Number(options.replyToMessageId), message_id: Number(options.replyToMessageId), resolved: false } : null
        });
        optimistic.status = 'sending';
        if (!existing) state.messages.push(optimistic);
        state.pendingSends[clientId] = true;
        updateComposerState();
        renderMessages({ forceBottom: true });
        traceTiming('composer_to_optimistic', optimisticStartedAt, { kind: 'text' });
        var requestedConversationId = conversation.id;
        var postStartedAt = timingNow();
        var operation = api(endpointWithId('conversations', conversation.id, '/messages'), {
            method: 'POST',
            timingLabel: 'message_post',
            body: {
                text: text,
                client_message_id: clientId,
                reply_to_message_id: options.replyToMessageId ? Number(options.replyToMessageId) : undefined
            }
        }).then(function (payload) {
            traceTiming('message_persist_finalize', postStartedAt, { kind: 'text', outcome: 'success' });
            return payload;
        }, function (error) {
            traceTiming('message_persist_finalize', postStartedAt, { kind: 'text', outcome: 'failure' });
            throw error;
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
            return real;
        }).catch(function (error) {
            reconcileServiceWindowError(error, requestedConversationId);
            if (Number(state.activeConversationId) === Number(requestedConversationId)) {
                optimistic.status = 'failed';
                optimistic.temporary = false;
                var failureState = error && error.details && error.details.send_state
                    ? String(error.details.send_state)
                    : (error && (error.isTimeout || error.status === 408 || error.status === 504 || error.status === 409) ? 'ambiguous_failure' : 'retryable_failure');
                if (!optimistic.metadata || typeof optimistic.metadata !== 'object') optimistic.metadata = {};
                optimistic.metadata.send_state = failureState;
                optimistic.error = { code: failureState.toUpperCase(), message: error.message || 'Falha no envio.', retryable: failureState === 'retryable_failure', suggested_action: failureState === 'ambiguous_failure' ? 'verify_provider_status' : null };
                renderMessages({ forceBottom: true });
                 showToast(failureState === 'ambiguous_failure' ? 'Envio nao confirmado' : 'Falha no envio', error.message || 'O rascunho foi preservado para revisao.', 'alert-triangle');
            }
            throw error;
        }).finally(function () {
            delete state.pendingSends[clientId];
            updateComposerState();
            var input = document.getElementById('impulso-message-input');
            if (input) input.focus();
        });
        return operation;
    }

    function templateEndpoint(conversationId) {
        return endpointWithId('conversations', conversationId, '/templates');
    }

    if (window.ImpulsoTemplatePicker && typeof window.ImpulsoTemplatePicker.create === 'function') {
        templatePicker = window.ImpulsoTemplatePicker.create({
            app: app,
            state: state,
            config: config,
            api: api,
            templateEndpoint: templateEndpoint,
            activeConversation: activeConversation,
            activeConversationId: function () { return state.activeConversationId; },
            createClientMessageId: createClientMessageId,
            normalizeMessage: normalizeMessage,
            mergeMessages: mergeMessages,
            renderMessages: renderMessages,
            updateComposerState: updateComposerState,
            reconcileWindowError: reconcileServiceWindowError,
            escapeHtml: escapeHtml,
            replaceIcons: replaceIcons,
            toast: showToast
        });
    }

    function retryMessage(clientId) {
        var message = state.messages.find(function (item) { return item.client_message_id === clientId; });
        if (!message) return;
        var sendState = String((message.metadata && message.metadata.send_state) || '');
        if (sendState !== 'retryable_failure') {
            showToast('Retry bloqueado', 'O resultado anterior nao permite reenvio automatico seguro.', 'shield');
            return;
        }
        var reply = message.reply_to && typeof message.reply_to === 'object' ? message.reply_to : null;
        var localReplyId = reply ? Number(reply.local_message_id || reply.message_id || 0) : 0;
        if (reply && localReplyId < 1) {
            showToast('Resposta indisponivel', 'A mensagem original nao pode mais ser resolvida; o retry contextual foi bloqueado.', 'alert-circle');
            return;
        }
        sendMessage(message.text_content, clientId, message, { replyToMessageId: localReplyId || 0 }).catch(function () {});
    }

    function remoteSyncInterval() {
        return Math.max(30000, Math.min(300000, Number(config.remoteSyncIntervalMs || 30000)));
    }

    function remoteSyncTimeout() {
        return Math.max(5000, Math.min(15000, Number(config.remoteSyncTimeoutMs || 10000)));
    }

    function syncConversationHistory(conversationId, force) {
        conversationId = Number(conversationId || 0);
        if (conversationId < 1 || runtime.destroyed || !endpoint('conversations')) return Promise.resolve();
        var key = String(conversationId);
        var now = Date.now();
        if (state.historySyncing[key]) return Promise.resolve();
        if (!force && now - Number(state.historySyncAt[key] || 0) < remoteSyncInterval()) return Promise.resolve();

        state.historySyncing[key] = true;
        state.historySyncAt[key] = now;
        return api(endpointWithId('conversations', conversationId, '/messages/sync'), {
            method: 'POST',
            body: { limit: Math.min(100, Math.max(10, Number(config.messagePageSize || 50))) },
            timeoutMs: remoteSyncTimeout(),
            timingLabel: 'remote_history_sync'
        }).then(function (payload) {
            if (Number(state.activeConversationId) !== conversationId) return;
            var rows = Array.isArray(payload.data) ? payload.data.map(normalizeMessage) : [];
            state.hasMoreBefore = !!(payload.meta && payload.meta.has_more_before);
            mergeMessages(rows, false);
            renderMessages({ forceBottom: false });
        }).catch(function () {
            /* Webhooks and the local cursor remain the primary real-time path. */
        }).finally(function () {
            delete state.historySyncing[key];
        });
    }

    function syncPollingChannel(force) {
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
        var syncKey = String(instance.id);
        var now = Date.now();
        if (!force && now - Number(state.channelSyncAt[syncKey] || 0) < remoteSyncInterval()) return Promise.resolve();
        state.pollingInstanceIndex = (state.pollingInstanceIndex + 1) % candidates.length;
        state.pollingChannelSyncing = true;
        state.channelSyncAt[syncKey] = now;
        return api(endpoint('conversations').replace(/\/$/, '') + '/sync', {
            method: 'POST',
            body: {
                instance_id: Number(instance.id),
                limit: Math.min(100, Math.max(10, Number(config.remoteConversationSyncLimit || 100)))
            },
            timeoutMs: remoteSyncTimeout(),
            timingLabel: 'remote_channel_sync'
        }).then(function () {
            return loadConversations(true, true);
        }).catch(function () {
            /* A leitura local continua disponivel; o proximo ciclo tenta novamente. */
        }).finally(function () {
            state.pollingChannelSyncing = false;
        });
    }

    function localPollingInterval() {
        return Math.max(3000, Math.min(5000, Number(config.localPollingIntervalMs || 3000)));
    }

    function poll() {
        if (runtime.destroyed) return;
        schedulePoll();
        if (document.hidden || app.getAttribute('data-active-tab') !== 'conversations') return;
        var startedAt = timingNow();
        var operations = [];
        if (state.activeConversationId) {
            var messageConversationId = String(state.activeConversationId);
            operations.push(runPollingOperation('messages:' + messageConversationId, function () { return loadMessages('after', false); }));
        }
        operations.push(runPollingOperation('conversations', function () { return loadConversations(true, true); }));
        pollingAllSettled(operations).then(function () {
            traceTiming('local_poll_cycle', startedAt, { operations: operations.length });
        });
    }

    function pollRemote() {
        if (runtime.destroyed) return;
        scheduleRemotePoll();
        if (document.hidden || app.getAttribute('data-active-tab') !== 'conversations') return;
        var operations = [];
        if (state.activeConversationId) {
            var historyConversationId = String(state.activeConversationId);
            operations.push(runPollingOperation('remote_history:' + historyConversationId, function () { return syncConversationHistory(Number(historyConversationId), false); }));
        }
        operations.push(runPollingOperation('remote_channel', function () { return syncPollingChannel(false); }));
        pollingAllSettled(operations);
    }

    function pollInstances() {
        if (runtime.destroyed) return;
        scheduleInstancePoll();
        if (document.hidden || app.getAttribute('data-active-tab') !== 'conversations') return;
        runPollingOperation('instances', function () { return loadInstances(true); });
    }

    function schedulePoll() {
        if (runtime.destroyed) return;
        if (!pollingScheduler) {
            if (state.pollingTimer) window.clearTimeout(state.pollingTimer);
            state.pollingTimer = window.setTimeout(poll, localPollingInterval());
            runtime.timers.push(state.pollingTimer);
            return;
        }
        schedulePolling('local', localPollingInterval(), poll);
    }

    function scheduleRemotePoll() {
        if (runtime.destroyed) return;
        schedulePolling('remote', remoteSyncInterval(), pollRemote);
    }

    function scheduleInstancePoll() {
        if (runtime.destroyed) return;
        schedulePolling('instances', Math.max(30000, Math.min(120000, Number(config.instanceRefreshIntervalMs || 60000))), pollInstances);
    }

    function bindConversationControls() {
        var mobile = document.getElementById('impulso-mobile-channel-filter');
        if (mobile) mobile.addEventListener('change', function () {
            var option = this.options[this.selectedIndex];
            activateChannel(this.value, option ? option.textContent : 'Todos os canais');
        });
        document.querySelectorAll('[data-conversation-filter]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.querySelectorAll('[data-conversation-filter]').forEach(function (item) { item.classList.remove('active'); item.setAttribute('aria-pressed', 'false'); });
                this.classList.add('active');
                this.setAttribute('aria-pressed', 'true');
                state.status = this.getAttribute('data-conversation-filter') || 'all';
                renderFilterSummary();
                setActiveConversationId(null, 'filter');
                loadConversations(true);
            });
        });
        document.querySelectorAll('[data-conversation-filter-control]').forEach(function (control) {
            control.addEventListener('change', function () {
                state.filters[this.getAttribute('data-conversation-filter-control')] = this.value || '';
                renderFilterSummary();
                setActiveConversationId(null, 'filter');
                loadConversations(true);
            });
        });
        var search = document.getElementById('impulso-conversation-search');
        if (search) search.addEventListener('input', function () {
            state.search = this.value.trim();
            if (state.searchTimer) window.clearTimeout(state.searchTimer);
            setActiveConversationId(null, 'search');
            renderFilterSummary();
            state.searchTimer = window.setTimeout(function () { loadConversations(true); }, 320);
            runtime.timers.push(state.searchTimer);
        });
        var status = document.getElementById('impulso-conversation-status');
        if (status) status.addEventListener('change', function () { mutateConversation(Number(state.activeConversationId), this.value === 'snoozed' ? '/snooze' : '/status', this.value === 'snoozed' ? { snoozed_until: snoozePreset('tomorrow') } : { status: this.value }); });
        var priority = document.getElementById('impulso-conversation-priority');
        if (priority) priority.addEventListener('change', function () { mutateConversation(Number(state.activeConversationId), '/priority', { priority: this.value }); });
        var assignee = document.getElementById('impulso-assignee-select');
        if (assignee) assignee.addEventListener('change', function () { mutateConversation(Number(state.activeConversationId), '/assignment', workflowHelpers.assignmentMutationPayload ? workflowHelpers.assignmentMutationPayload({ assignee_id: this.value || 0 }) : { assignee_id: this.value || 0 }); });
        var team = document.getElementById('impulso-team-select');
        if (team) team.addEventListener('change', function () { mutateConversation(Number(state.activeConversationId), '/assignment', workflowHelpers.assignmentMutationPayload ? workflowHelpers.assignmentMutationPayload({ team_id: this.value || 0 }) : { team_id: this.value || 0 }); });
        var resolve = document.getElementById('impulso-resolve-button');
        if (resolve) resolve.addEventListener('click', function () {
            var conversation = activeConversation();
            if (conversation) mutateConversation(Number(conversation.id), '/status', { status: conversation.status === 'resolved' ? 'open' : 'resolved' });
        });
        var unread = document.getElementById('impulso-mark-unread');
        if (unread) unread.addEventListener('click', function () { if (state.activeConversationId) mutateConversation(Number(state.activeConversationId), '/unread'); });
        var snooze = document.getElementById('impulso-snooze-button');
        if (snooze) snooze.addEventListener('click', function () { if (state.activeConversationId) openCustomSnooze(Number(state.activeConversationId), this); });
        var customSnoozeCancel = document.getElementById('impulso-custom-snooze-cancel');
        if (customSnoozeCancel) customSnoozeCancel.addEventListener('click', closeCustomSnooze);
        var customSnoozeApply = document.getElementById('impulso-custom-snooze-apply');
        if (customSnoozeApply) customSnoozeApply.addEventListener('click', applyCustomSnooze);
        loadAssignmentOptions();
        var clearFilters = document.querySelector('[data-conversation-filter-clear]');
        if (clearFilters) clearFilters.addEventListener('click', clearConversationFilters);
        renderFilterSummary();
    }

    function fieldValue(id) {
        var element = document.getElementById(id);
        return element ? String(element.value || '').trim() : '';
    }

    function setField(id, value) {
        var element = document.getElementById(id);
        if (element) element.value = value == null ? '' : String(value);
    }

    function updateInstanceProviderSections() {
        var provider = fieldValue('impulso-instance-provider') || 'evolution';
        document.querySelectorAll('[data-instance-provider-section]').forEach(function (section) {
            section.classList.toggle('impulso-hidden', section.getAttribute('data-instance-provider-section') !== provider);
        });
    }

    function openInstanceModal(id) {
        var instance = state.instances.find(function (item) { return Number(item.id) === Number(id); }) || null;
        var provider = instance ? (instance.provider_type || 'evolution') : 'evolution';
        setField('impulso-instance-id', instance ? instance.id : '');
        setField('impulso-instance-provider', provider);
        setField('impulso-instance-name', instance ? instance.name : '');
        setField('impulso-instance-technical-name', instance && provider === 'evolution' ? instance.evolution_instance_name : '');
        setField('impulso-instance-identifier', instance ? instance.internal_identifier : '');
        setField('impulso-instance-base-url', instance ? instance.base_url : '');
        setField('impulso-instance-phone', instance ? (instance.phone || instance.phone_number || '') : '');
        setField('impulso-instance-api-key', '');
        setField('impulso-instance-meta-phone-id', instance ? instance.meta_phone_number_id : '');
        setField('impulso-instance-meta-waba-id', instance ? instance.meta_waba_id : '');
        setField('impulso-instance-meta-version', instance ? (instance.meta_graph_version || 'v25.0') : 'v25.0');
        setField('impulso-instance-meta-access-token', '');
        setField('impulso-instance-meta-verify-token', '');
        setField('impulso-instance-meta-app-secret', '');
        var clearApiKey = document.getElementById('impulso-instance-clear-api-key');
        if (clearApiKey) { clearApiKey.checked = false; clearApiKey.disabled = !instance || !instance.has_api_key; }
        var active = document.getElementById('impulso-instance-active');
        if (active) active.checked = instance ? instance.active : true;
        updateInstanceProviderSections();
        setText('impulso-instance-modal-title', instance ? 'Editar canal WhatsApp' : 'Novo canal WhatsApp');
        var modal = document.getElementById('impulso-instance-modal');
        if (modal && window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    function saveInstance(button) {
        var id = Number(fieldValue('impulso-instance-id') || 0);
        var provider = fieldValue('impulso-instance-provider') || 'evolution';
        var active = document.getElementById('impulso-instance-active');
        var clearApiKey = document.getElementById('impulso-instance-clear-api-key');
        var body = {
            provider_type: provider,
            name: fieldValue('impulso-instance-name'),
            evolution_instance_name: fieldValue('impulso-instance-technical-name'),
            internal_identifier: fieldValue('impulso-instance-identifier'),
            phone_number: fieldValue('impulso-instance-phone'),
            active: active && active.checked ? 1 : 0
        };
        if (provider === 'evolution') {
            body.api_key = fieldValue('impulso-instance-api-key');
            body.clear_api_key = clearApiKey && clearApiKey.checked ? 1 : 0;
            if (config.permissions && config.permissions.manageSettings) body.base_url = fieldValue('impulso-instance-base-url');
        } else {
            body.meta_phone_number_id = fieldValue('impulso-instance-meta-phone-id');
            body.meta_waba_id = fieldValue('impulso-instance-meta-waba-id');
            body.meta_graph_version = fieldValue('impulso-instance-meta-version') || 'v25.0';
            body.meta_access_token = fieldValue('impulso-instance-meta-access-token');
            body.meta_verify_token = fieldValue('impulso-instance-meta-verify-token');
            body.meta_app_secret = fieldValue('impulso-instance-meta-app-secret');
        }
        button.disabled = true;
        api(id ? endpointWithId('instances', id) : endpoint('instances'), { method: 'POST', body: body }).then(function (payload) {
            closeModal(button);
            showToast('Canal salvo', 'A configuração foi armazenada com segurança.', 'check-circle');
            if (payload && Array.isArray(payload.warnings) && payload.warnings.length) {
                showToast('Webhook pendente', payload.warnings[0], 'alert-triangle');
            }
            return refreshInstancesSurface();
        }).catch(function (error) {
            showToast('Falha ao salvar', error.message, 'alert-triangle');
        }).finally(function () { button.disabled = false; });
    }

    function defaultBotDefinition() {
        return {
            start: 'inicio',
            nodes: {
                inicio: {
                    message: 'Olá. Posso ajudar com valores, horários, endereço ou agendamento. Digite uma dessas opções.',
                    transitions: [
                        { id: 'valores', target: 'valores', match: { type: 'any_word', values: ['valor', 'valores', 'preço', 'mensalidade'] } },
                        { id: 'horarios', target: 'horarios', match: { type: 'any_word', values: ['horário', 'horarios', 'turma'] } },
                        { id: 'endereco', target: 'endereco', match: { type: 'any_word', values: ['endereço', 'localização', 'onde'] } },
                        { id: 'agendar', target: '__handoff__', match: { type: 'any_word', values: ['agendar', 'visita', 'matrícula'] } }
                    ],
                    terminal: false,
                    handoff: false,
                    fallback_target: null
                },
                valores: { message: 'A mensalidade e demais valores são confirmados pelo responsável. Vou encaminhar seu atendimento.', transitions: [], terminal: true, handoff: true, fallback_target: null },
                horarios: { message: 'Temos turmas aos sábados. Para confirmar a vaga e o horário correto, vou encaminhar ao responsável.', transitions: [], terminal: true, handoff: true, fallback_target: null },
                endereco: { message: 'Nossa equipe confirma o endereço da unidade correspondente ao seu cadastro. Vou encaminhar seu atendimento.', transitions: [], terminal: true, handoff: true, fallback_target: null }
            }
        };
    }

    function botDefinitionFromField() {
        var raw = fieldValue('impulso-bot-definition');
        try { return JSON.parse(raw); } catch (error) { throw new Error('O fluxo JSON é inválido: ' + error.message); }
    }

    function openBotModal(id) {
        var reset = function (bot) {
            setField('impulso-bot-id', bot ? bot.id : '');
            setField('impulso-bot-name', bot ? bot.name : 'Atendimento inicial');
            setField('impulso-bot-instance', bot && bot.instance_id ? bot.instance_id : '');
            setField('impulso-bot-description', bot ? bot.description : 'Responde somente dúvidas previstas e transfere o restante para um responsável.');
            setField('impulso-bot-trigger', bot ? bot.trigger_type : 'first_message');
            setField('impulso-bot-trigger-values', bot && bot.trigger_config && Array.isArray(bot.trigger_config.values) ? bot.trigger_config.values.join(', ') : '');
            setField('impulso-bot-max-fallbacks', bot ? bot.max_fallbacks : 2);
            setField('impulso-bot-fallback', bot ? bot.fallback_message : 'Não consegui identificar sua dúvida com segurança.');
            setField('impulso-bot-handoff', bot ? bot.handoff_message : 'Vou encaminhar sua mensagem para um responsável continuar o atendimento.');
            setField('impulso-bot-definition', JSON.stringify(bot ? bot.definition : defaultBotDefinition(), null, 2));
            setField('impulso-bot-simulation-inputs', 'oi\nqual o valor?');
            var ignoreGroups = document.getElementById('impulso-bot-ignore-groups');
            if (ignoreGroups) ignoreGroups.checked = bot ? !!bot.ignore_groups : true;
            var result = document.getElementById('impulso-bot-simulation-result');
            if (result) { result.textContent = ''; result.classList.add('impulso-hidden'); }
            setText('impulso-bot-modal-title', bot ? 'Editar bot determinístico' : 'Novo bot determinístico');
            var modal = document.getElementById('impulso-bot-modal');
            if (modal && window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
        };
        if (!id) { reset(null); return; }
        api(endpointWithId('bots', id)).then(function (payload) { reset(payload.data || null); }).catch(function (error) { showToast('Falha ao abrir bot', error.message, 'alert-triangle'); });
    }

    function botPayload() {
        var trigger = fieldValue('impulso-bot-trigger') || 'first_message';
        var values = fieldValue('impulso-bot-trigger-values').split(',').map(function (value) { return value.trim(); }).filter(Boolean);
        return {
            name: fieldValue('impulso-bot-name'),
            instance_id: Number(fieldValue('impulso-bot-instance') || 0) || null,
            description: fieldValue('impulso-bot-description'),
            trigger_type: trigger,
            trigger_config: trigger === 'keyword' ? { values: values } : {},
            definition: botDefinitionFromField(),
            fallback_message: fieldValue('impulso-bot-fallback'),
            handoff_message: fieldValue('impulso-bot-handoff'),
            max_fallbacks: Number(fieldValue('impulso-bot-max-fallbacks') || 2),
            ignore_groups: !!((document.getElementById('impulso-bot-ignore-groups') || {}).checked)
        };
    }

    function saveBot(button) {
        var id = Number(fieldValue('impulso-bot-id') || 0), body;
        try { body = botPayload(); } catch (error) { showToast('Fluxo inválido', error.message, 'alert-triangle'); return; }
        if (!body.name) { showToast('Dados incompletos', 'Informe o nome do bot.', 'alert-circle'); return; }
        button.disabled = true;
        api(id ? endpointWithId('bots', id) : endpoint('bots'), { method: id ? 'PUT' : 'POST', body: body }).then(function () {
            closeModal(button); showToast('Bot salvo', 'O fluxo foi validado e salvo como rascunho.', 'check-circle'); window.location.reload();
        }).catch(function (error) { showToast('Falha ao salvar bot', error.message, 'alert-triangle'); }).finally(function () { button.disabled = false; });
    }

    function publishOrToggleBot(id, action, button) {
        if (!id) return;
        button.disabled = true;
        api(endpointWithId('bots', id, '/' + action), { method: 'POST', body: {} }).then(function () {
            showToast(action === 'publish' ? 'Bot publicado' : 'Estado atualizado', 'A alteração foi aplicada.', 'check-circle'); window.location.reload();
        }).catch(function (error) { showToast('Falha no bot', error.message, 'alert-triangle'); }).finally(function () { button.disabled = false; });
    }

    function simulateBot(button) {
        var definition;
        try { definition = botDefinitionFromField(); } catch (error) { showToast('Fluxo inválido', error.message, 'alert-triangle'); return; }
        var inputs = fieldValue('impulso-bot-simulation-inputs').split(/\r?\n/).map(function (value) { return value.trim(); }).filter(Boolean);
        button.disabled = true;
        api(endpoint('bots') + '/simulate', { method: 'POST', body: { definition: definition, inputs: inputs } }).then(function (payload) {
            var result = document.getElementById('impulso-bot-simulation-result');
            if (result) { result.textContent = JSON.stringify(payload.data || {}, null, 2); result.classList.remove('impulso-hidden'); }
            showToast('Simulação concluída', 'Nenhuma mensagem real foi enviada.', 'play');
        }).catch(function (error) { showToast('Falha na simulação', error.message, 'alert-triangle'); }).finally(function () { button.disabled = false; });
    }

    function toggleConversationBot(button) {
        var conversation = activeConversation();
        if (!conversation) return;
        var pause = conversation.bot_status === 'active';
        button.disabled = true;
        api(endpointWithId('conversations', conversation.id, '/bot/' + (pause ? 'pause' : 'resume')), { method: 'POST', body: pause ? { reason: 'manual_pause' } : {} }).then(function () {
            conversation.bot_status = pause ? 'paused' : 'active';
            applyActiveConversation(conversation);
            showToast(pause ? 'Bot pausado' : 'Bot retomado', pause ? 'Somente o atendente responderá nesta conversa.' : 'O fluxo poderá responder às próximas mensagens.', 'power');
        }).catch(function (error) { showToast('Falha ao alterar bot', error.message, 'alert-triangle'); }).finally(function () { button.disabled = false; });
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

    function evolutionInstance(id) {
        return state.instances.find(function (item) { return Number(item.id) === Number(id); }) || null;
    }

    function showEvolutionConnectModal(instance, data) {
        var modal = document.getElementById('impulso-evolution-connect-modal');
        var empty = document.getElementById('impulso-evolution-qr-empty');
        var image = document.getElementById('impulso-evolution-qr');
        var pairingWrap = document.getElementById('impulso-evolution-pairing-wrap');
        var pairingCode = document.getElementById('impulso-evolution-pairing-code');
        var message = document.getElementById('impulso-evolution-connect-message');
        var qr = data && typeof data.base64 === 'string' ? data.base64.trim() : '';
        var pairing = data && typeof data.pairing_code === 'string' ? data.pairing_code.trim() : '';
        var qrSource = '';
        if (/^data:image\/(?:png|jpe?g|webp);base64,/i.test(qr)) {
            qrSource = qr;
        } else if (qr && qr.length < 1000000 && /^[A-Za-z0-9+/=\s]+$/.test(qr)) {
            qrSource = 'data:image/png;base64,' + qr.replace(/\s/g, '');
        }
        setText('impulso-evolution-connect-title', 'Conectar ' + (instance ? instance.name : 'Evolution'));
        if (image) {
            image.classList.toggle('impulso-hidden', !qrSource);
            if (qrSource) image.src = qrSource;
            else image.removeAttribute('src');
        }
        if (pairingWrap) pairingWrap.classList.toggle('impulso-hidden', !pairing);
        if (pairingCode) pairingCode.textContent = pairing;
        if (empty) empty.classList.toggle('impulso-hidden', !!qrSource || !!pairing);
        if (message) message.textContent = qrSource || pairing
            ? 'Leia o QR Code no WhatsApp ou use o código de pareamento. Esta tela será atualizada quando o canal conectar.'
            : 'A Evolution não retornou um QR Code neste momento. Tente novamente em alguns segundos.';
        if (modal) {
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
            else if (window.jQuery && typeof window.jQuery(modal).modal === 'function') window.jQuery(modal).modal('show');
        }
        replaceIcons();
    }

    function connectEvolutionInstance(id, button) {
        var instance = evolutionInstance(id);
        if (!id) return;
        if (button) button.disabled = true;
        var number = instance && (instance.phone || instance.phone_number) ? String(instance.phone || instance.phone_number) : '';
        var url = endpointWithId('instances', id, '/evolution/connect');
        if (number) url += '?number=' + encodeURIComponent(number);
        api(url, { timingLabel: 'evolution_connect' }).then(function (payload) {
            showEvolutionConnectModal(instance, payload.data || {});
            showToast('Dados de conexão gerados', 'Leia o QR Code para parear o WhatsApp.', 'maximize');
        }).catch(function (error) {
            showToast('Falha ao conectar', error.message, 'alert-triangle');
        }).finally(function () { if (button) button.disabled = false; });
    }

    function evolutionAction(id, action, button) {
        if (!id) return;
        var labels = {
            restart: ['Instância reiniciada', 'A Evolution está iniciando a conexão novamente.'],
            logout: ['Instância desconectada', 'O WhatsApp foi desconectado da Evolution.'],
            delete: ['Instância removida', 'O canal foi removido da Evolution e arquivado no Rise.']
        };
        if (action === 'delete' && !window.confirm('Remover esta instância da Evolution? O pareamento e os dados da sessão serão perdidos.')) return;
        if (button) button.disabled = true;
        var suffix = action === 'delete' ? '/evolution' : '/evolution/' + action;
        api(endpointWithId('instances', id, suffix), { method: action === 'delete' ? 'DELETE' : 'POST', body: {} }).then(function () {
            showToast(labels[action][0], labels[action][1], action === 'delete' ? 'trash-2' : 'check-circle');
            return refreshInstancesSurface(true);
        }).catch(function (error) {
            showToast('Falha na Evolution', error.message, 'alert-triangle');
        }).finally(function () { if (button) button.disabled = false; });
    }

    function syncEvolutionInstances(button) {
        if (!endpoint('instancesSyncEvolution')) return;
        if (button) button.disabled = true;
        api(endpoint('instancesSyncEvolution'), { method: 'POST', body: {}, timingLabel: 'evolution_instance_sync' }).then(function (payload) {
            var data = payload.data || {};
            showToast('Evolution sincronizada', (Number(data.count) || 0) + ' instância(s) importada(s) ou atualizada(s).', 'download-cloud');
            if (payload && Array.isArray(payload.warnings) && payload.warnings.length) {
                showToast('Webhook pendente', payload.warnings[0], 'alert-triangle');
            }
            return refreshInstancesSurface(true);
        }).catch(function (error) {
            showToast('Falha ao sincronizar', error.message, 'alert-triangle');
        }).finally(function () { if (button) button.disabled = false; });
    }

    function applyInitialSettings() {
        var values = {
            'impulso-setting-module-name': initialSettings.module_name || 'Impulso Hub WhatsApp',
            'impulso-setting-timezone': initialSettings.timezone || 'America/Sao_Paulo',
            'impulso-setting-polling': initialSettings.polling_interval_ms || 5000,
            'impulso-setting-page-size': initialSettings.conversation_page_size || 30,
            'impulso-setting-default-status': initialSettings.default_status || 'open',
            'impulso-setting-default-priority': initialSettings.default_priority || 'normal',
            'impulso-setting-auto-resolve-hours': initialSettings.auto_resolve_hours || 0,
            'impulso-setting-base-url': initialSettings.evolution_base_url || '',
            'impulso-setting-timeout': initialSettings.request_timeout_seconds || 30,
            'impulso-setting-evolution-retries': initialSettings.evolution_retries || 0,
            'impulso-setting-status-path': initialSettings.connection_status_path || '/instance/connectionState/{instance}',
            'impulso-setting-chats-path': initialSettings.find_chats_path || '/chat/findChats/{instance}',
            'impulso-setting-messages-path': initialSettings.find_messages_path || '/chat/findMessages/{instance}',
            'impulso-setting-send-path': initialSettings.send_text_path || '/message/sendText/{instance}',
            'impulso-setting-send-media-path': initialSettings.send_media_path || '/message/sendMedia/{instance}',
            'impulso-setting-send-audio-path': initialSettings.send_audio_path || '/message/sendWhatsAppAudio/{instance}',
            'impulso-setting-media-base64-path': initialSettings.get_media_base64_path || '/chat/getBase64FromMediaMessage/{instance}',
            'impulso-setting-campaign-start': initialSettings.campaign_window_start || '08:00',
            'impulso-setting-campaign-end': initialSettings.campaign_window_end || '20:00',
            'impulso-setting-campaign-rate-limit': initialSettings.campaign_default_rate_limit_per_minute || 20,
            'impulso-setting-campaign-max-attempts': initialSettings.campaign_recipient_max_attempts || 5,
            'impulso-setting-campaign-retry-delay': initialSettings.campaign_retry_delay_seconds || 120,
            'impulso-setting-quick-replies': initialSettings.quick_replies_json || '[]',
            'impulso-setting-bot-timeout': initialSettings.bot_session_timeout_minutes || 1440,
            'impulso-setting-bot-fallback': initialSettings.bot_default_fallback || '',
            'impulso-setting-bot-handoff': initialSettings.bot_default_handoff || '',
            'impulso-setting-webhook-retention': initialSettings.webhook_retention_days || 30,
            'impulso-setting-conversation-retention': initialSettings.conversation_retention_days || 0,
            'impulso-setting-media-retention': initialSettings.media_retention_days || 30
        };
        Object.keys(values).forEach(function (id) { setField(id, values[id]); });
        setField('impulso-setting-global-key', '');
        setField('impulso-setting-webhook-secret', '');
        var flags = {
            'impulso-setting-sound': initialSettings.sound_enabled !== false && initialSettings.sound_enabled !== 0 && initialSettings.sound_enabled !== '0',
            'impulso-setting-browser-notifications': !!initialSettings.browser_notifications_enabled,
            'impulso-setting-auto-read': initialSettings.auto_mark_read !== false && initialSettings.auto_mark_read !== 0 && initialSettings.auto_mark_read !== '0',
            'impulso-setting-campaign-pause-errors': Number(initialSettings.campaign_pause_after_errors || 0) > 0,
            'impulso-setting-bot-enabled': initialSettings.bot_enabled !== false && initialSettings.bot_enabled !== 0 && initialSettings.bot_enabled !== '0',
            'impulso-setting-log-webhooks': initialSettings.log_sanitized_webhooks !== false && initialSettings.log_sanitized_webhooks !== 0 && initialSettings.log_sanitized_webhooks !== '0',
            'impulso-setting-secure-media': initialSettings.secure_media !== false && initialSettings.secure_media !== 0 && initialSettings.secure_media !== '0'
        };
        Object.keys(flags).forEach(function (id) {
            var element = document.getElementById(id);
            if (element) element.checked = flags[id];
        });
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
            campaign_window_start: fieldValue('impulso-setting-campaign-start'),
            campaign_window_end: fieldValue('impulso-setting-campaign-end'),
            campaign_default_rate_limit_per_minute: Number(fieldValue('impulso-setting-campaign-rate-limit') || 20),
            campaign_recipient_max_attempts: Number(fieldValue('impulso-setting-campaign-max-attempts') || 5),
            campaign_retry_delay_seconds: Number(fieldValue('impulso-setting-campaign-retry-delay') || 120),
            campaign_pause_after_errors: checkedValue('impulso-setting-campaign-pause-errors') ? 5 : 0,
            quick_replies_json: fieldValue('impulso-setting-quick-replies'),
            bot_enabled: checkedValue('impulso-setting-bot-enabled'),
            bot_session_timeout_minutes: Number(fieldValue('impulso-setting-bot-timeout') || 1440),
            bot_default_fallback: fieldValue('impulso-setting-bot-fallback'),
            bot_default_handoff: fieldValue('impulso-setting-bot-handoff'),
            webhook_secret: fieldValue('impulso-setting-webhook-secret'),
            log_sanitized_webhooks: checkedValue('impulso-setting-log-webhooks'),
            webhook_retention_days: Number(fieldValue('impulso-setting-webhook-retention') || 30),
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
        syncAvailableHeight();
        window.addEventListener('resize', syncInboxPanels, { passive: true });
        window.addEventListener('resize', syncAvailableHeight, { passive: true });
        if (window.visualViewport) window.visualViewport.addEventListener('resize', syncAvailableHeight, { passive: true });
        if (window.requestAnimationFrame) window.requestAnimationFrame(syncAvailableHeight);
        var providerSelect = document.getElementById('impulso-instance-provider');
        if (providerSelect) providerSelect.addEventListener('change', updateInstanceProviderSections);
        updateInstanceProviderSections();
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
        if (submit === 'bot') { saveBot(trigger); return; }
        if (submit) return;
        if (action === 'refresh-dashboard') { window.location.reload(); return; }
        if (action === 'toggle-conversation-bot') { toggleConversationBot(trigger); return; }
        if (action === 'new-bot') { openBotModal(0); return; }
        if (action === 'edit-bot') { openBotModal(trigger.getAttribute('data-bot-id')); return; }
        if (action === 'publish-bot') { publishOrToggleBot(trigger.getAttribute('data-bot-id'), 'publish', trigger); return; }
        if (action === 'toggle-bot') { publishOrToggleBot(trigger.getAttribute('data-bot-id'), 'toggle', trigger); return; }
        if (action === 'simulate-bot') { simulateBot(trigger); return; }
        if (action === 'new-instance') { openInstanceModal(0); return; }
        if (action === 'edit-instance') { openInstanceModal(trigger.getAttribute('data-instance-id')); return; }
        if (action === 'test-instance') { testInstance(trigger.getAttribute('data-instance-id'), trigger); return; }
        if (action === 'refresh-instances') { refreshAllInstances(trigger); return; }
        if (action === 'sync-evolution') { syncEvolutionInstances(trigger); return; }
        if (action === 'connect-evolution') { connectEvolutionInstance(trigger.getAttribute('data-instance-id'), trigger); return; }
        if (action === 'restart-evolution') { evolutionAction(trigger.getAttribute('data-instance-id'), 'restart', trigger); return; }
        if (action === 'logout-evolution') { evolutionAction(trigger.getAttribute('data-instance-id'), 'logout', trigger); return; }
        if (action === 'delete-evolution') { evolutionAction(trigger.getAttribute('data-instance-id'), 'delete', trigger); return; }
        if (action === 'save-settings') { saveSettings(trigger); return; }
        if (action === 'test-evolution') { testEvolutionSettings(trigger); return; }
        if (action === 'test-all-connections') { refreshAllInstances(trigger); return; }
        if (action === 'toggle-channel-sidebar') { toggleInboxPanel('channel'); return; }
        if (action === 'toggle-conversation-sidebar' || action === 'open-conversation-list') { toggleInboxPanel('conversation'); return; }
        if (action === 'close-inbox-drawers') { closeInboxDrawers(); return; }
        if (action === 'open-contact') {
            var contact = document.getElementById('impulso-contact-sidebar');
            if (contact) contact.classList.toggle('open');
            return;
        }
        if (action === 'templates') { if (templatePicker) templatePicker.open(); return; }
        if (action === 'emoji' || action === 'attach' || action === 'voice' || action === 'quick-replies' || action === 'resolve-conversation' || action === 'toggle-priority' || action === 'search-history' || action === 'close-history-search' || action === 'call-contact' || action === 'edit-contact' || action === 'contact-menu' || action === 'edit-assignment' || action === 'edit-tags') return;
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
        if (isCompactInbox()
            && !event.target.closest('#impulso-channel-sidebar')
            && !event.target.closest('#impulso-chat-sidebar')
            && !event.target.closest('[data-panel-toggle]')) {
            closeInboxDrawers();
        }
        var contact = document.getElementById('impulso-contact-sidebar');
        if (contact && contact.classList.contains('open') && !event.target.closest('#impulso-contact-sidebar') && !event.target.closest('[data-impulso-action="open-contact"]')) {
            contact.classList.remove('open');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isCompactInbox()) closeInboxDrawers();
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
        openConversationById: openConversationById,
        conversationPermalink: conversationPermalink,
        copyConversationLink: copyConversationLink,
        openConversationTab: openConversationTab,
        currentSavedViewFilters: currentSavedViewFilters,
        applySavedViewFilters: applySavedViewFilters,
        clearBulkSelection: clearBulkSelection,
        renderConversationList: renderConversationList,
        updateConversationRecord: updateConversationRecord,
        setComposerMode: setComposerMode,
        onConversationChange: function (handler) {
            if (typeof handler !== 'function') return function () {};
            conversationChangeHandlers.push(handler);
            return function () { conversationChangeHandlers = conversationChangeHandlers.filter(function (candidate) { return candidate !== handler; }); };
        },
        onComposerModeChange: function (handler) {
            if (typeof handler !== 'function') return function () {};
            composerModeChangeHandlers.push(handler);
            return function () { composerModeChangeHandlers = composerModeChangeHandlers.filter(function (candidate) { return candidate !== handler; }); };
        },
        renderMessages: renderMessages,
        loadMessages: loadMessages,
        loadConversations: loadConversations,
        loadInstances: loadInstances,
        updateComposerState: updateComposerState,
        reconcileServiceWindowError: reconcileServiceWindowError,
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
    inboxPanelState.channelCollapsed = readInboxPanelPreference('impulso_hub_channel_collapsed');
    inboxPanelState.conversationCollapsed = readInboxPanelPreference('impulso_hub_conversation_collapsed');
    syncInboxPanels();
    replaceIcons();

    if (app.getAttribute('data-active-tab') === 'conversations') {
        var deepLinkId = 0;
        try { deepLinkId = Number(new URLSearchParams(window.location.search).get('conversation') || 0); } catch (error) { deepLinkId = 0; }
        var bootstrapConversationId = deepLinkId > 0 ? deepLinkId : (state.conversations[0] ? Number(state.conversations[0].id || 0) : 0);
        if (bootstrapConversationId > 0) {
            openConversationById(bootstrapConversationId, {
                silent: true,
                loadAuxiliary: true,
                skipRemoteSync: true
            });
        }
        schedulePoll();
        scheduleRemotePoll();
        scheduleInstancePoll();
    }
    updateComposerState();
})(window, document);
