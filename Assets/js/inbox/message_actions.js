(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory(require('./message_safe_content'));
    else root.ImpulsoMessageActions = factory(root.ImpulsoMessageSafeContent);
}(typeof self !== 'undefined' ? self : this, function (Safe) {
    'use strict';

    function numberId(value) { var id = Number(value || 0); return isFinite(id) && id > 0 ? id : 0; }
    function typeOf(message) { return Safe.normalizedType ? Safe.normalizedType(message) : String(message && (message.type || message.message_type) || 'unsupported'); }
    function plainText(message) { return Safe.plainText ? Safe.plainText(message) : String(message && (message.text_content || message.caption) || '').trim(); }
    function eligibleMessage(message) { var type = typeOf(message); return !!message && type !== 'internal_note' && type !== 'activity' && type !== 'reaction'; }
    function reactionPolicy(capabilities) { return capabilities && (capabilities.reaction || capabilities.actions && capabilities.actions.reaction) || {}; }
    function hasOwnReaction(message) { return (Array.isArray(message && message.reactions) ? message.reactions : []).some(function (reaction) { return !!(reaction && reaction.reacted_by_me); }); }

    function getMessageActions(message, capabilities, permissions) {
        message = message || {};
        capabilities = capabilities || {};
        permissions = permissions || {};
        var actions = capabilities.actions || {};
        var result = [];
        var text = plainText(message);
        var status = String(message.status || '').toLowerCase();
        var outgoing = String(message.direction || '').toLowerCase() === 'outgoing';
        var id = numberId(message.id);
        var external = String(message.external_message_id || message.provider_message_id || '').trim();
        if (permissions.send !== false && actions.reply === true && eligibleMessage(message) && status !== 'failed' && id && external) result.push('reply');
        if (text) result.push('copy');
        if (permissions.manageSettings === true && text && typeOf(message) === 'text') result.push('create_quick_reply');
        var state = String(message.metadata && message.metadata.send_state || '').toLowerCase();
        if (permissions.send !== false && outgoing && status === 'failed' && message.client_message_id && state === 'retryable_failure' && (typeOf(message) === 'text' || typeOf(message) === 'unsupported')) result.push('retry');
        var reaction = reactionPolicy(capabilities);
        var age = Number(message.message_timestamp || 0);
        var tooOld = Number(reaction.max_target_age_seconds || 0) > 0 && age > 0 && (Date.now() / 1000 - age) > Number(reaction.max_target_age_seconds);
        var groupBlocked = !!message.is_group_message && reaction.groups === false;
        if (permissions.send !== false && actions.react === true && reaction.enabled !== false && eligibleMessage(message) && status !== 'failed' && id && external && !tooOld && !groupBlocked) result.push('react');
        return result;
    }

    function labels() { return { reply: 'Responder', react: 'Reagir', copy: 'Copiar conteúdo', create_quick_reply: 'Criar resposta rápida', retry: 'Tentar novamente' }; }
    function icon(name) { return '<i data-feather="' + name + '" aria-hidden="true"></i>'; }

    function bind(body, options) {
        options = options || {};
        if (!body) return;
        if (body.getAttribute('data-message-actions-bound') === '1') {
            var previousOptions = body.__impulsoMessageActionsOptions || {};
            Object.keys(options).forEach(function (key) { previousOptions[key] = options[key]; });
            return;
        }
        body.__impulsoMessageActionsOptions = options;
        body.setAttribute('data-message-actions-bound', '1');
        var menu = document.getElementById('impulso-message-context-menu');
        if (!menu) {
            menu = document.createElement('div');
            menu.id = 'impulso-message-context-menu';
            menu.className = 'impulso-context-menu impulso-hidden';
            menu.setAttribute('role', 'menu');
            menu.setAttribute('aria-label', 'Ações da mensagem');
            document.body.appendChild(menu);
        }
        var current = null;
        var lastTrigger = null;
        var picker = null;
        function getMessage(row) { return options.getMessage ? options.getMessage(row && row.getAttribute('data-message-id')) : null; }
        function close(restoreFocus) { menu.classList.add('impulso-hidden'); menu.innerHTML = ''; current = null; var trigger = lastTrigger; lastTrigger = null; if (restoreFocus && trigger && document.contains(trigger)) trigger.focus(); }
        function focusFirst() { var first = menu.querySelector('button'); if (first) first.focus(); }
        function open(message, trigger, point) {
            var available = getMessageActions(message, options.capabilities || {}, options.permissions || {});
            if (!available.length) return;
            current = message;
            lastTrigger = trigger || null;
            var names = labels();
            menu.innerHTML = available.map(function (action) { return '<button type="button" role="menuitem" data-message-menu-action="' + action + '">' + icon(action === 'copy' ? 'copy' : action === 'retry' ? 'refresh-cw' : action === 'react' ? 'smile' : action === 'create_quick_reply' ? 'zap' : 'corner-up-left') + '<span>' + names[action] + '</span></button>'; }).join('');
            menu.classList.remove('impulso-hidden');
            var rect = trigger && trigger.getBoundingClientRect ? trigger.getBoundingClientRect() : { right: point ? point.clientX : 20, bottom: point ? point.clientY : 20 };
            var left = Math.min((rect.right || 20), window.innerWidth - 220);
            var top = Math.min((rect.bottom || 20), window.innerHeight - 220);
            menu.style.left = Math.max(8, left) + 'px';
            menu.style.top = Math.max(8, top) + 'px';
            if (trigger) focusFirst();
            if (options.replaceIcons) options.replaceIcons();
        }
        function jump(id) {
            var escaped = String(id).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
            var target = body.querySelector('[data-message-id="' + escaped + '"]');
            if (!target) return;
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var bubble = target.querySelector('.impulso-message');
            if (bubble) { bubble.classList.add('is-highlighted'); window.setTimeout(function () { bubble.classList.remove('is-highlighted'); }, 1800); }
        }
        function copy(message) {
            var value = plainText(message);
            if (!value) return;
            var done = function () { if (options.toast) options.toast('Conteúdo copiado', 'O conteúdo da mensagem foi copiado.', 'copy'); };
            if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(value).then(done).catch(function () { fallbackCopy(value, done); });
            else fallbackCopy(value, done);
        }
        function fallbackCopy(value, done) {
            var field = document.createElement('textarea'); field.value = value; field.setAttribute('readonly', ''); field.style.position = 'fixed'; field.style.opacity = '0'; document.body.appendChild(field); field.select();
            try { document.execCommand('copy'); done(); } catch (error) { if (options.toast) options.toast('Cópia indisponível', 'Selecione o conteúdo manualmente.', 'alert-circle'); }
            field.remove();
        }
        function reply(message) {
            var target = { messageId: numberId(message.id), author: message.sender && message.sender.name || message.sender_name || (message.direction === 'outgoing' ? 'Você' : 'Contato'), preview: plainText(message) || 'Mensagem de mídia' };
            if (window.ImpulsoComposerBridge && window.ImpulsoComposerBridge.setReplyTarget) window.ImpulsoComposerBridge.setReplyTarget(target);
            else { var button = body.querySelector('[data-reply-message-id="' + String(target.messageId) + '"]'); if (button) button.click(); }
        }
        function quickReply(message) {
            var dialog = document.createElement('div');
            dialog.className = 'impulso-message-dialog-backdrop';
            dialog.innerHTML = '<div class="impulso-message-dialog" role="dialog" aria-modal="true" aria-labelledby="impulso-quick-reply-title"><div class="impulso-dialog-header"><h3 id="impulso-quick-reply-title">Criar resposta rápida</h3><button type="button" data-message-dialog-close aria-label="Fechar">×</button></div><form class="impulso-message-dialog-form"><label>Título<input name="title" maxlength="150" required></label><label>Atalho<input name="shortcut" maxlength="70" pattern="[A-Za-z0-9_-]{1,70}" required></label><label>Texto<textarea name="text" maxlength="10000" required></textarea></label><div class="impulso-dialog-error" role="alert"></div><div class="impulso-dialog-actions"><button type="button" data-message-dialog-close>Cancelar</button><button type="submit">Salvar</button></div></form></div>';
            document.body.appendChild(dialog);
            dialog.querySelector('[name="text"]').value = plainText(message);
            dialog.querySelector('[name="title"]').focus();
            var closeDialog = function () { dialog.remove(); };
            dialog.querySelectorAll('[data-message-dialog-close]').forEach(function (button) { button.addEventListener('click', closeDialog); });
            dialog.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeDialog(); });
            dialog.querySelector('form').addEventListener('submit', function (event) {
                event.preventDefault();
                var form = event.currentTarget;
                var data = { title: form.title.value.trim(), shortcut: form.shortcut.value.trim(), text: form.text.value };
                var error = form.querySelector('.impulso-dialog-error');
                var submit = form.querySelector('button[type="submit"]');
                submit.disabled = true;
                if (!options.api || !options.endpoint) return;
                options.api(options.endpoint('quickReplies'), { method: 'POST', body: data }).then(function () {
                    if (options.toast) options.toast('Resposta rápida criada', 'A resposta foi adicionada ao catálogo.', 'check');
                    window.dispatchEvent(new CustomEvent('impulso:quick-replies-invalidated'));
                    closeDialog();
                }).catch(function (failure) { if (error) error.textContent = failure.message || 'Não foi possível criar a resposta rápida.'; submit.disabled = false; });
            });
        }
        function react(message, trigger) {
            if (!options.api || !options.endpointWithId) return;
            if (picker) picker.remove();
            picker = document.createElement('div');
            picker.className = 'impulso-reaction-picker';
            picker.setAttribute('role', 'dialog');
            picker.setAttribute('aria-label', 'Escolher reação');
            var catalog = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
            var own = hasOwnReaction(message);
            picker.innerHTML = '<div class="impulso-reaction-options" role="menu">' + catalog.map(function (emoji) { return '<button type="button" role="menuitem" data-reaction-emoji="' + emoji + '" aria-label="Reagir com ' + emoji + '">' + emoji + '</button>'; }).join('') + (own ? '<button type="button" role="menuitem" class="impulso-reaction-remove" data-reaction-remove="1" aria-label="Remover minha reação">×</button>' : '') + '</div>';
            document.body.appendChild(picker);
            var rect = trigger && trigger.getBoundingClientRect ? trigger.getBoundingClientRect() : { left: window.innerWidth / 2, bottom: window.innerHeight / 2 };
            picker.style.left = Math.max(8, Math.min(rect.left || 8, window.innerWidth - 260)) + 'px';
            picker.style.top = Math.max(8, Math.min((rect.bottom || 8) + 6, window.innerHeight - 70)) + 'px';
            var closed = false;
            var outside = function (event) { if (!picker.contains(event.target)) dismiss(true); };
            var keyboard = function (event) { if (event.key === 'Escape') { event.preventDefault(); dismiss(true); } };
            var dismiss = function (restore) { if (closed) return; closed = true; document.removeEventListener('click', outside); document.removeEventListener('keydown', keyboard); picker.remove(); picker = null; if (restore && trigger && document.contains(trigger)) trigger.focus(); };
            document.addEventListener('click', outside);
            document.addEventListener('keydown', keyboard);
            picker.addEventListener('click', function (event) {
                var button = event.target.closest('[data-reaction-emoji], [data-reaction-remove]');
                if (!button) return;
                event.preventDefault();
                var remove = button.hasAttribute('data-reaction-remove');
                var emoji = remove ? '' : String(button.getAttribute('data-reaction-emoji') || '');
                dismiss(true);
                options.api(options.endpointWithId('conversations', options.conversationId(), '/messages/' + numberId(message.id) + '/reaction'), { method: 'POST', body: { emoji: emoji, remove: remove, client_message_id: 'reaction-' + numberId(message.id) + '-' + Date.now() } }).then(function (payload) { if (options.onReaction) options.onReaction(payload); }).catch(function (error) { if (options.toast) options.toast('Falha na reação', error.message, 'alert-triangle'); });
            });
            var first = picker.querySelector('button');
            if (first) first.focus();
        }
        body.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-message-menu]');
            if (trigger) { event.preventDefault(); event.stopPropagation(); open(getMessage(trigger.closest('[data-message-id]')), trigger); return; }
            var actionButton = event.target.closest('[data-message-menu-action]');
            if (actionButton && current) {
                event.preventDefault(); var action = actionButton.getAttribute('data-message-menu-action'); var message = current; var trigger = lastTrigger; close(false);
                if (action === 'copy') copy(message); else if (action === 'reply') reply(message); else if (action === 'retry' && options.retry) options.retry(message); else if (action === 'create_quick_reply') quickReply(message); else if (action === 'react') react(message, trigger);
                return;
            }
            var jumpButton = event.target.closest('[data-message-jump-id]');
            if (jumpButton) { event.preventDefault(); jump(jumpButton.getAttribute('data-message-jump-id')); }
        });
        body.addEventListener('contextmenu', function (event) {
            var row = event.target.closest('[data-message-id]');
            if (!row) return;
            var message = getMessage(row); if (!getMessageActions(message, options.capabilities || {}, options.permissions || {}).length) return;
            event.preventDefault(); open(message, null, event);
        });
        menu.addEventListener('keydown', function (event) {
            var items = Array.prototype.slice.call(menu.querySelectorAll('button')); if (!items.length) return;
            var currentIndex = items.indexOf(document.activeElement);
            if (event.key === 'Escape') { event.preventDefault(); close(true); return; }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); items[(currentIndex + (event.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus(); }
        });
        document.addEventListener('click', function (event) { if (!event.target.closest('#impulso-message-context-menu') && !event.target.closest('[data-message-menu]')) close(true); });
        window.addEventListener('resize', function () { close(false); if (picker) { picker.remove(); picker = null; } });
    }

    return { bind: bind, getMessageActions: getMessageActions, plainText: plainText };
}));
