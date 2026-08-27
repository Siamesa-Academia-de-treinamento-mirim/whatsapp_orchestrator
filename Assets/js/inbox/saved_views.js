(function (window, document) {
    'use strict';
    var bridge = window.ImpulsoHubBridge;
    var app = document.getElementById('impulso-hub-app');
    if (!bridge || !app || !bridge.endpoint('savedViews')) return;
    var views = [];
    var panel = null;
    function esc(value) { return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }
    function data(payload, fallback) { return payload && payload.data != null ? payload.data : fallback; }
    function ensurePanel() {
        if (panel) return panel;
        panel = document.createElement('section'); panel.id = 'impulso-saved-views'; panel.className = 'impulso-saved-views';
        panel.innerHTML = '<div class="impulso-saved-views-heading"><strong>Visualizacoes</strong><button type="button" class="btn btn-link btn-sm" data-saved-view-save>Salvar atual</button></div><div class="impulso-saved-view-list"></div>';
        var tabs = document.querySelector('.impulso-queue-tabs'); if (tabs) tabs.parentNode.insertBefore(panel, tabs);
        panel.addEventListener('click', function (event) {
            var save = event.target.closest('[data-saved-view-save]'); if (save) { event.preventDefault(); saveView(); return; }
            var apply = event.target.closest('[data-saved-view-apply]'); if (apply) { applyView(Number(apply.getAttribute('data-saved-view-apply'))); return; }
            var rename = event.target.closest('[data-saved-view-rename]'); if (rename) { renameView(Number(rename.getAttribute('data-saved-view-rename'))); return; }
            var remove = event.target.closest('[data-saved-view-delete]'); if (remove) { deleteView(Number(remove.getAttribute('data-saved-view-delete'))); }
        });
        return panel;
    }
    function render() {
        var list = ensurePanel().querySelector('.impulso-saved-view-list');
        list.innerHTML = views.length ? views.map(function (view) { return '<div class="impulso-saved-view-row"><button type="button" data-saved-view-apply="' + view.id + '"><i data-feather="filter"></i><span>' + esc(view.name) + '</span></button><button type="button" class="impulso-icon-button" data-saved-view-rename="' + view.id + '" aria-label="Renomear"><i data-feather="edit-2"></i></button><button type="button" class="impulso-icon-button" data-saved-view-delete="' + view.id + '" aria-label="Excluir"><i data-feather="trash-2"></i></button></div>'; }).join('') : '<small>Nenhuma visualizacao salva.</small>';
        if (bridge.replaceIcons) bridge.replaceIcons();
    }
    function load() { return bridge.api(bridge.endpoint('savedViews')).then(function (payload) { views = Array.isArray(data(payload, [])) ? data(payload, []) : []; render(); }).catch(function () { render(); }); }
    function saveView() {
        var name = window.prompt('Nome da visualizacao'); if (name == null || !name.trim()) return;
        bridge.api(bridge.endpoint('savedViews'), { method: 'POST', body: { name: name.trim(), schema_version: 1, filters: bridge.currentSavedViewFilters() } }).then(function (payload) { views.push(data(payload, {})); render(); }).catch(function (error) { if (bridge.toast) bridge.toast('Visualizacao nao salva', error.message, 'alert-triangle'); });
    }
    function applyView(id) { var view = views.find(function (item) { return Number(item.id) === id; }); if (view && bridge.applySavedViewFilters) bridge.applySavedViewFilters(view.filters || {}); }
    function renameView(id) { var view = views.find(function (item) { return Number(item.id) === id; }); if (!view) return; var name = window.prompt('Novo nome', view.name); if (name == null || !name.trim()) return; bridge.api(bridge.endpointWithId('savedViews', id, ''), { method: 'PUT', body: { name: name.trim(), schema_version: 1, filters: view.filters } }).then(function (payload) { var next = data(payload, view); views = views.map(function (item) { return Number(item.id) === id ? next : item; }); render(); }).catch(function (error) { if (bridge.toast) bridge.toast('Visualizacao nao renomeada', error.message, 'alert-triangle'); }); }
    function deleteView(id) { if (!window.confirm('Excluir esta visualizacao?')) return; bridge.api(bridge.endpointWithId('savedViews', id, ''), { method: 'DELETE', body: {} }).then(function () { views = views.filter(function (item) { return Number(item.id) !== id; }); render(); }).catch(function (error) { if (bridge.toast) bridge.toast('Visualizacao nao excluida', error.message, 'alert-triangle'); }); }
    ensurePanel(); load();
    window.ImpulsoSavedViews = { load: load, apply: applyView };
}(window, document));
