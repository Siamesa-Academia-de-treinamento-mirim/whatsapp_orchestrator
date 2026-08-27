(function (window, document) {
    'use strict';
    var bridge = window.ImpulsoHubBridge;
    var app = document.getElementById('impulso-hub-app');
    if (!bridge || !app || !bridge.endpoint('bulkAction')) return;
    var state = bridge.getState ? bridge.getState() : {};
    var contract = window.ImpulsoCollaborationContract || {};
    var bar = null;
    function ids() { return Array.isArray(state.bulkSelectedIds) ? state.bulkSelectedIds.map(Number).filter(function (item) { return item > 0; }) : []; }
    function esc(value) { return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }
    function ensureBar() {
        if (bar) return bar;
        bar = document.createElement('div'); bar.id = 'impulso-bulk-bar'; bar.className = 'impulso-bulk-bar impulso-hidden'; bar.setAttribute('role', 'region'); bar.setAttribute('aria-label', 'Acoes em massa');
        bar.innerHTML = '<strong><span data-bulk-count>0</span> selecionadas</strong><button type="button" data-bulk-action="read">Marcar lidas</button><button type="button" data-bulk-action="unread">Marcar nao lidas</button><button type="button" data-bulk-action="status">Status</button><button type="button" data-bulk-action="priority">Prioridade</button><button type="button" data-bulk-action="assignment">Responsavel/equipe</button><button type="button" data-bulk-action="tags_add">Adicionar etiqueta</button><button type="button" data-bulk-action="tags_remove">Remover etiqueta</button><button type="button" data-bulk-clear>Limpar</button><span data-bulk-result aria-live="polite"></span>';
        var list = document.getElementById('impulso-conversation-list'); if (list && list.parentNode) list.parentNode.insertBefore(bar, list);
        bar.addEventListener('click', function (event) { var action = event.target.closest('[data-bulk-action]'); if (action) execute(action.getAttribute('data-bulk-action')); if (event.target.closest('[data-bulk-clear]')) clear(); });
        return bar;
    }
    function render() {
        var selected = ids();
        var selectedMap = {};
        selected.forEach(function (id) { selectedMap[id] = true; });
        var list = document.getElementById('impulso-conversation-list');
        if (list) list.querySelectorAll('[data-bulk-select]').forEach(function (checkbox) {
            checkbox.checked = !!selectedMap[Number(checkbox.getAttribute('data-bulk-select'))];
        });
        var element = ensureBar();
        element.classList.toggle('impulso-hidden', !selected.length);
        var count = element.querySelector('[data-bulk-count]');
        if (count) count.textContent = String(selected.length);
    }
    function bind(list) {
        if (!list || list.getAttribute('data-bulk-bound') === '1') { render(); return; }
        list.setAttribute('data-bulk-bound', '1');
        list.addEventListener('change', function (event) {
            var checkbox = event.target.closest('[data-bulk-select]'); if (!checkbox) return;
            event.stopPropagation(); var id = Number(checkbox.getAttribute('data-bulk-select')); var selected = ids();
            if (checkbox.checked && selected.indexOf(id) < 0) { if (selected.length >= 100) { checkbox.checked = false; return; } selected.push(id); }
            if (!checkbox.checked) selected = selected.filter(function (item) { return item !== id; });
            state.bulkSelectedIds = selected; render();
        });
        list.addEventListener('click', function (event) { if (event.target.closest('[data-bulk-select]')) event.stopPropagation(); });
        render();
    }
    function removeForm() { var form = document.getElementById('impulso-bulk-form'); if (form && form.parentNode) form.parentNode.removeChild(form); }
    function selectOptions(options, blankLabel) {
        var html = blankLabel ? '<option value="">' + esc(blankLabel) + '</option>' : '';
        return html + options.map(function (item) { return '<option value="' + esc(item.value) + '">' + esc(item.label) + '</option>'; }).join('');
    }
    function openForm(kind, done) {
        removeForm();
        var form = document.createElement('form'); form.id = 'impulso-bulk-form'; form.className = 'impulso-bulk-form'; form.setAttribute('role', 'dialog'); form.setAttribute('aria-label', 'Configurar acao em massa');
        var statuses = contract.canonicalStatusOptions ? contract.canonicalStatusOptions() : [{ value: 'open', label: 'Aberta' }, { value: 'pending', label: 'Pendente' }, { value: 'resolved', label: 'Resolvida' }];
        var priorities = contract.canonicalPriorityOptions ? contract.canonicalPriorityOptions() : [{ value: 'none', label: 'Sem prioridade' }, { value: 'low', label: 'Baixa' }, { value: 'medium', label: 'Media' }, { value: 'high', label: 'Alta' }, { value: 'urgent', label: 'Urgente' }];
        if (kind === 'status') form.innerHTML = '<label>Status<select name="status">' + selectOptions(statuses, 'Escolha o status') + '</select></label>';
        if (kind === 'priority') form.innerHTML = '<label>Prioridade<select name="priority">' + selectOptions(priorities, 'Escolha a prioridade') + '</select></label>';
        if (kind === 'assignment') {
            var staff = (state.assignmentOptions && Array.isArray(state.assignmentOptions.staff) ? state.assignmentOptions.staff : []).map(function (item) { return { value: String(Number(item.id) || 0), label: String(item.name || 'Agente') }; });
            var teams = (state.assignmentOptions && Array.isArray(state.assignmentOptions.teams) ? state.assignmentOptions.teams : []).map(function (item) { return { value: String(Number(item.id) || 0), label: String(item.name || 'Equipe') }; });
            form.innerHTML = '<label>Responsavel<select name="assignee_id">' + selectOptions([{ value: '0', label: 'Sem responsavel' }].concat(staff), 'Nao alterar responsavel') + '</select></label><label>Equipe<select name="team_id">' + selectOptions([{ value: '0', label: 'Sem equipe' }].concat(teams), 'Nao alterar equipe') + '</select></label>';
        }
        form.insertAdjacentHTML('beforeend', '<div class="impulso-bulk-form-actions"><button type="button" data-bulk-form-cancel>Cancelar</button><button type="submit">Aplicar</button></div>');
        document.body.appendChild(form);
        form.addEventListener('click', function (event) { if (event.target.closest('[data-bulk-form-cancel]')) { event.preventDefault(); removeForm(); } });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var payload = {};
            Array.prototype.forEach.call(form.querySelectorAll('select'), function (select) { if (select.value !== '') payload[select.name] = select.value; });
            if (!Object.keys(payload).length) { removeForm(); return; }
            removeForm(); done({ action: kind, payload: payload });
        });
        var first = form.querySelector('select'); if (first) first.focus();
    }
    function requestOperation(kind, done) {
        if (kind === 'read' || kind === 'unread') {
            var readState = contract.normalizeBulkReadState ? contract.normalizeBulkReadState(kind) : kind;
            if (!readState) return;
            return done({ action: 'read_state', payload: { state: readState } });
        }
        if (kind === 'tags_add' || kind === 'tags_remove') {
            var tags = window.prompt(kind === 'tags_add' ? 'Etiquetas a adicionar (separadas por virgula)' : 'Etiquetas a remover (separadas por virgula)', '');
            if (tags == null) return;
            return done({ action: kind, payload: { tags: tags.split(',').map(function (item) { return item.trim(); }).filter(Boolean) } });
        }
        openForm(kind, done);
    }
    function execute(kind) {
        var selected = ids(); if (!selected.length) return;
        requestOperation(kind, function (operation) {
            bridge.api(bridge.endpoint('bulkAction'), { method: 'POST', body: { conversation_ids: selected, action: operation.action, payload: operation.payload } }).then(applyResult).catch(function (error) { var resultNode = ensureBar().querySelector('[data-bulk-result]'); if (resultNode) resultNode.textContent = error.message || 'Falha na operacao.'; });
        });
    }
    function applyResult(payload) {
        var result = payload && payload.data ? payload.data : {};
        var rows = Array.isArray(result.results) ? result.results : [];
        var failed = rows.filter(function (item) { return !item.ok; }).map(function (item) { return Number(item.conversation_id); });
        rows.filter(function (item) { return item.ok && item.data && bridge.updateConversationRecord; }).forEach(function (item) { bridge.updateConversationRecord(item.data); });
        state.bulkSelectedIds = failed;
        render();
        var resultNode = ensureBar().querySelector('[data-bulk-result]');
        if (resultNode) resultNode.textContent = Number(result.summary && result.summary.succeeded || 0) + ' concluida(s), ' + Number(result.summary && result.summary.failed || 0) + ' falha(s).';
    }
    function clear() { state.bulkSelectedIds = []; render(); var list = document.getElementById('impulso-conversation-list'); if (list) list.querySelectorAll('[data-bulk-select]').forEach(function (item) { item.checked = false; }); }
    ensureBar(); bind(document.getElementById('impulso-conversation-list')); render();
    window.ImpulsoBulkActions = { bind: bind, render: render, clear: clear, applyResult: applyResult };
}(window, document));
