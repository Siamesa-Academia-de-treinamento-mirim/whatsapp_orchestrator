(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory(require('./message_safe_content'));
    else root.ImpulsoMessageRenderers = factory(root.ImpulsoMessageSafeContent);
}(typeof self !== 'undefined' ? self : this, function (Safe) {
    'use strict';

    Safe = Safe || {};
    var esc = Safe.escapeHtml || function (value) { return String(value == null ? '' : value); };
    var typeOf = Safe.normalizedType || function (message) { return String(message && (message.type || message.message_type) || 'unsupported'); };

    function content(message) { return message && message.content && typeof message.content === 'object' ? message.content : {}; }
    function attachments(message) {
        var list = content(message).attachments;
        if (Array.isArray(list) && list.length) return list;
        if (message && (message.media_url || message.media_id || message.file_name)) return [{
            url: message.media_url || '', id: message.media_id || null, mime_type: message.mime_type || '',
            file_name: message.file_name || '', file_size: message.file_size || 0
        }];
        return [];
    }
    function mediaUrl(attachment, context) {
        var locationLike = context && context.location;
        return Safe.safeMediaUrl ? Safe.safeMediaUrl(attachment && attachment.url, locationLike) : '';
    }
    function textValue(message) {
        var c = content(message);
        return String(c.text || message.text_content || c.caption || message.caption || '');
    }
    function caption(message) { return String(content(message).caption || message.caption || ''); }
    function icon(name) { return '<i data-feather="' + esc(name) + '" aria-hidden="true"></i>'; }
    function unavailable(label) { return '<div class="impulso-media-card impulso-media-unavailable" role="status">' + icon('file-minus') + '<span><strong>' + esc(label) + '</strong><small>Mídia indisponível para visualização.</small></span></div>'; }

    function replyQuote(message) {
        var reply = message && message.reply_to;
        if (!reply || typeof reply !== 'object') return '';
        var id = Number(reply.local_message_id || reply.message_id || 0);
        var preview = String(reply.preview || '').trim() || 'Mensagem respondida não disponível';
        var author = String(reply.author || 'Mensagem respondida');
        var attrs = id > 0 ? ' data-message-jump-id="' + esc(id) + '"' : '';
        return '<button class="impulso-message-reply-quote" type="button"' + attrs + ' aria-label="Ir para mensagem respondida">' + icon('corner-up-left') + '<span><strong>' + esc(author) + '</strong><small>' + esc(preview) + '</small></span></button>';
    }

    function reactionAggregate(message) {
        var rows = Array.isArray(message && message.reactions) ? message.reactions : [];
        return rows.map(function (reaction) {
            var emoji = String(reaction && reaction.emoji || '').slice(0, 16);
            var count = Math.max(0, Number(reaction && reaction.count || 0));
            if (!emoji || !count) return '';
            var mine = !!(reaction && reaction.reacted_by_me);
            var label = emoji + ', ' + count + ' reação(ões)' + (mine ? ', você reagiu' : '');
            return '<span class="impulso-message-reaction' + (mine ? ' is-reacted' : '') + '" title="' + esc(label) + '" aria-label="' + esc(label) + '" data-reacted-by-me="' + (mine ? 'true' : 'false') + '">' + esc(emoji) + '<small>' + esc(count) + '</small></span>';
        }).join('');
    }

    function footer(message, context) {
        var timestamps = message && message.timestamps || {};
        var sent = timestamps.sent_at || message && message.sent_at || '';
        var detail = [];
        [['sent_at', 'Enviada'], ['delivered_at', 'Entregue'], ['read_at', 'Lida'], ['failed_at', 'Falhou']].forEach(function (entry) {
            var value = timestamps[entry[0]] || message && message[entry[0]] || '';
            if (value) detail.push(entry[1] + ': ' + (context && context.time ? context.time(value) : value));
        });
        var displayTime = sent ? (context && context.time ? context.time(sent) : sent) : '';
        var html = '<div class="impulso-message-footer"><span' + (detail.length ? ' title="' + esc(detail.join(' · ')) + '"' : '') + '>' + esc(displayTime) + '</span>';
        if (message && message.direction === 'outgoing' && typeOf(message) !== 'internal_note') {
            var status = String(message.status || 'sent').toLowerCase();
            var labels = { sending: 'Enviando', sent: 'Enviada', delivered: 'Entregue', read: 'Lida', failed: 'Falhou' };
            var statusIcon = status === 'sending' ? icon('clock') : status === 'failed' ? icon('alert-circle') : status === 'delivered' || status === 'read' ? icon('check') + icon('check') : icon('check');
            var statusTitle = detail.length ? detail.join(' · ') : (labels[status] || 'Status desconhecido');
            html += '<span class="impulso-message-status status-' + esc(status) + (status === 'read' ? ' is-read' : '') + '" title="' + esc(statusTitle) + '" aria-label="' + esc(statusTitle) + '">' + statusIcon + '</span>';
        }
        html += '</div>';
        return html;
    }

    function textRenderer(message) {
        var value = textValue(message);
        return value ? '<p class="impulso-message-text">' + (Safe.autoLink ? Safe.autoLink(value) : esc(value).replace(/\r?\n/g, '<br>')) + '</p>' : '<span class="impulso-message-empty-content">Mensagem sem texto</span>';
    }

    function imageRenderer(message, context) {
        var item = attachments(message)[0] || {};
        var url = mediaUrl(item, context);
        var html = url ? '<button class="impulso-media-button" type="button" data-media-kind="image" data-media-url="' + esc(url) + '" aria-label="Abrir imagem"><img class="impulso-message-image" src="' + esc(url) + '" alt="Imagem da conversa" loading="lazy" referrerpolicy="no-referrer"></button>' : unavailable('Imagem recebida');
        return html + (caption(message) ? '<p class="impulso-message-caption">' + (Safe.autoLink ? Safe.autoLink(caption(message)) : esc(caption(message))) + '</p>' : '');
    }

    function galleryRenderer(message, context) {
        var list = attachments(message);
        var html = '<div class="impulso-message-gallery" aria-label="Galeria de mídia">';
        list.forEach(function (item) {
            var url = mediaUrl(item, context);
            html += url ? '<button class="impulso-media-button" type="button" data-media-kind="image" data-media-url="' + esc(url) + '" aria-label="Abrir item da galeria"><img class="impulso-message-image" src="' + esc(url) + '" alt="Item da galeria" loading="lazy" referrerpolicy="no-referrer"></button>' : unavailable('Item de mídia');
        });
        return html + '</div>' + (caption(message) ? '<p class="impulso-message-caption">' + esc(caption(message)) + '</p>' : '');
    }

    function audioRenderer(message, context) {
        var item = attachments(message)[0] || {};
        var url = mediaUrl(item, context);
        if (!url) return unavailable(typeOf(message) === 'voice' ? 'Nota de voz recebida' : 'Áudio recebido');
        var label = typeOf(message) === 'voice' || item.is_voice_note || (message.metadata && message.metadata.is_voice_note) ? 'Nota de voz' : 'Áudio';
        return '<div class="impulso-audio-message ' + (label === 'Nota de voz' ? 'is-voice' : '') + '"><span class="impulso-media-kind">' + icon('volume-2') + '<small>' + esc(label) + '</small></span><audio class="impulso-message-audio" controls preload="metadata" src="' + esc(url) + '"></audio></div>' + (caption(message) ? '<p class="impulso-message-caption">' + esc(caption(message)) + '</p>' : '');
    }

    function videoRenderer(message, context) {
        var item = attachments(message)[0] || {};
        var url = mediaUrl(item, context);
        if (!url) return unavailable('Vídeo recebido');
        var html = '<div class="impulso-video-message"><video class="impulso-message-video skip-context-menu" controls preload="metadata" src="' + esc(url) + '"></video><button class="impulso-media-open-button" type="button" data-media-kind="video" data-media-url="' + esc(url) + '" aria-label="Abrir vídeo em tela cheia">' + icon('maximize-2') + '<span>Abrir vídeo</span></button></div>';
        return html + (caption(message) ? '<p class="impulso-message-caption">' + (Safe.autoLink ? Safe.autoLink(caption(message)) : esc(caption(message))) + '</p>' : '');
    }

    function documentRenderer(message, context) {
        var item = attachments(message)[0] || {};
        var url = mediaUrl(item, context);
        var name = item.file_name || message.file_name || 'Documento';
        var details = [Safe.formatBytes && Safe.formatBytes(item.file_size || message.file_size), item.mime_type || message.mime_type].filter(Boolean).join(' · ');
        return '<button class="impulso-media-card impulso-media-document" type="button"' + (url ? ' data-media-kind="document" data-media-url="' + esc(url) + '"' : ' disabled') + '>' + icon('file-text') + '<span><strong>' + esc(name) + '</strong><small>' + esc(details || (url ? 'Visualizar documento' : 'Arquivo indisponível')) + '</small></span>' + icon('external-link') + '</button>' + (caption(message) ? '<p class="impulso-message-caption">' + esc(caption(message)) + '</p>' : '');
    }

    function stickerRenderer(message, context) {
        var item = attachments(message)[0] || {};
        var url = mediaUrl(item, context);
        return url ? '<img class="impulso-message-sticker" src="' + esc(url) + '" alt="Sticker" loading="lazy" referrerpolicy="no-referrer">' : unavailable('Sticker recebido');
    }

    function locationRenderer(message) {
        var location = content(message).location || {};
        var point = Safe.coordinates ? Safe.coordinates(location) : null;
        var title = location.name || 'Localização compartilhada';
        var address = location.address || '';
        var map = point ? 'https://maps.google.com/?q=' + encodeURIComponent(point.latitude + ',' + point.longitude) : '';
        return '<div class="impulso-structured-card">' + icon('map-pin') + '<span><strong>' + esc(title) + '</strong>' + (address ? '<small>' + esc(address) + '</small>' : '') + (point ? '<small>' + esc(point.latitude + ', ' + point.longitude) + '</small>' : '<small>Coordenadas inválidas ou indisponíveis</small>') + '</span>' + (map ? '<a href="' + esc(map) + '" target="_blank" rel="noopener noreferrer" class="impulso-structured-link">Abrir mapa</a>' : '') + '</div>';
    }

    function contactRenderer(message) {
        var contact = content(message).contact || {};
        var phones = Array.isArray(contact.phones) ? contact.phones : [];
        var emails = Array.isArray(contact.emails) ? contact.emails : [];
        return '<div class="impulso-structured-card impulso-contact-card">' + icon('user') + '<span><strong>' + esc(contact.display_name || contact.name || 'Contato compartilhado') + '</strong>' + phones.slice(0, 3).map(function (phone) { return '<small>' + esc(phone) + '</small>'; }).join('') + emails.slice(0, 2).map(function (email) { return '<small>' + esc(email) + '</small>'; }).join('') + (contact.organization ? '<small>' + esc(contact.organization) + '</small>' : '') + '</span></div>';
    }

    function templateRenderer(message) {
        var template = content(message).template || {};
        var body = template.body || textValue(message);
        var header = template.header || template.title || '';
        var mediaReference = template.media_reference && template.media_reference.url ? template.media_reference : null;
        var components = Array.isArray(template.components) ? template.components : [];
        var buttons = Array.isArray(template.buttons) ? template.buttons : [];
        var parameters = Array.isArray(template.resolved_parameters) ? template.resolved_parameters : [];
        function displayValue(value) {
            if (value === null || value === undefined) return '';
            if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') return String(value);
            if (Array.isArray(value)) return value.map(displayValue).filter(Boolean).join(' · ');
            if (typeof value === 'object') return displayValue(value.text || value.value || value.title || value.label || value.name || '');
            return '';
        }
        var componentText = components.map(displayValue).filter(Boolean);
        var buttonText = buttons.map(displayValue).filter(Boolean);
        parameters = parameters.map(displayValue).filter(Boolean);
        var mediaHtml = mediaReference && mediaReference.kind === 'image' ? '<img class="impulso-template-media" src="' + esc(mediaReference.url) + '" alt="Cabeçalho de mídia do template">' : mediaReference ? '<a class="impulso-structured-link" href="' + esc(mediaReference.url) + '" target="_blank" rel="noopener noreferrer">Abrir cabeçalho de mídia</a>' : '';
        return '<div class="impulso-structured-card impulso-template-card">' + icon('file-text') + '<span><strong>' + esc(template.name || 'Template') + '</strong>' + mediaHtml + (header ? '<h4>' + esc(header) + '</h4>' : '') + (body ? '<p>' + (Safe.autoLink ? Safe.autoLink(body) : esc(body)) + '</p>' : '') + (template.footer ? '<small>' + esc(template.footer) + '</small>' : '') + (componentText.length ? '<small>' + esc(componentText.slice(0, 8).map(displayValue).join(' · ')) + '</small>' : '') + (parameters.length ? '<small>Parâmetros: ' + esc(parameters.slice(0, 8).map(displayValue).join(' · ')) + '</small>' : '') + (buttonText.length ? '<div class="impulso-template-buttons">' + buttonText.slice(0, 6).map(function (button) { return '<span>' + esc(button) + '</span>'; }).join('') + '</div>' : '') + '</span></div>';
    }

    function interactiveRenderer(message) {
        var interactive = content(message).interactive || {};
        var buttons = Array.isArray(interactive.buttons) ? interactive.buttons : [];
        return '<div class="impulso-structured-card impulso-interactive-card">' + icon('list') + '<span><strong>' + esc(interactive.title || interactive.label || 'Interação') + '</strong>' + (interactive.body || interactive.description ? '<p>' + esc(interactive.body || interactive.description) + '</p>' : '') + (buttons.length ? '<small>' + esc(buttons.map(function (button) { return button.title || button.text || ''; }).filter(Boolean).slice(0, 5).join(' · ')) + '</small>' : '') + '</span></div>';
    }

    function internalNoteRenderer(message) { var sender = message && message.sender && message.sender.name || message && (message.sender_name || message.author_name) || 'Nota interna'; if (/^\d+$/.test(String(sender).trim())) sender = 'Nota interna'; var mentions = Array.isArray(message && message.mentions) ? message.mentions.slice(0, 20).map(function (item) { return '<span class="impulso-note-mention">@' + esc(item && item.name || 'agente') + '</span>'; }).join(' ') : ''; return '<div class="impulso-note-label">' + esc(sender) + '</div>' + textRenderer(message) + (mentions ? '<div class="impulso-note-mentions" aria-label="Agentes mencionados">' + mentions + '</div>' : ''); }
    function activityRenderer(message) { return '<div class="impulso-activity-content">' + icon('activity') + '<span>' + (Safe.autoLink ? Safe.autoLink(textValue(message) || 'Atividade da conversa') : esc(textValue(message) || 'Atividade da conversa')) + '</span></div>'; }
    function unsupportedRenderer(message, context) {
        var item = attachments(message)[0] || {};
        var url = mediaUrl(item, context);
        var name = item.file_name || message && message.file_name || 'Anexo recebido';
        var details = item.mime_type || message && message.mime_type || '';
        var card = '<div class="impulso-media-card impulso-media-unavailable">' + icon('paperclip') + '<span><strong>' + esc(name) + '</strong><small>' + esc(details || 'Formato não suportado para visualização') + '</small></span>' + (url ? '<a class="impulso-media-open-button" href="' + esc(url) + '" target="_blank" rel="noopener noreferrer">Abrir anexo</a>' : '') + '</div>';
        return '<div class="impulso-unsupported-content">' + icon('help-circle') + '<span><strong>Mensagem não suportada</strong><small>' + esc(textValue(message) || 'O conteúdo não pode ser exibido com segurança.') + '</small></span></div>' + (url || item.file_name ? card : '');
    }
    function reactionRenderer() { return ''; }

    var registry = {
        text: textRenderer, image: imageRenderer, gallery: galleryRenderer, audio: audioRenderer, voice: audioRenderer,
        video: videoRenderer, document: documentRenderer, sticker: stickerRenderer, location: locationRenderer,
        contact: contactRenderer, template: templateRenderer, interactive: interactiveRenderer,
        internal_note: internalNoteRenderer, activity: activityRenderer, unsupported: unsupportedRenderer, reaction: reactionRenderer
    };

    function renderMessage(message, context) {
        message = message || {};
        context = context || {};
        var type = registry[typeOf(message)] ? typeOf(message) : 'unsupported';
        if (type === 'reaction') return '';
        var direction = message.is_internal_note || type === 'internal_note' ? 'internal' : message.direction === 'outgoing' ? 'outgoing' : 'incoming';
        var id = message.id || message.client_message_id || message.external_message_id || '';
        var author = direction === 'incoming' && message.is_group_message ? (message.sender_name || message.sender_phone || 'Participante') : '';
        var body = registry[type](message, context);
        var reactions = reactionAggregate(message);
        var search = [author, Safe.plainText ? Safe.plainText(message) : textValue(message)].join(' ').toLowerCase();
        var menu = type !== 'activity' ? '<button class="impulso-message-menu-trigger" type="button" data-message-menu="' + esc(id) + '" aria-haspopup="menu" aria-label="Mais ações da mensagem">' + icon('more-vertical') + '</button>' : '';
        return '<div class="impulso-message-row ' + esc(direction) + ' message-type-' + esc(type) + (message.status === 'failed' ? ' is-failed' : '') + '" data-message-id="' + esc(id) + '" data-message-type="' + esc(type) + '" data-message-search="' + esc(search) + '"><div class="impulso-message">' + menu + (author ? '<strong class="impulso-message-author">' + esc(author) + '</strong>' : '') + replyQuote(message) + body + reactions + footer(message, context) + '</div></div>';
    }

    return { registry: registry, renderMessage: renderMessage, renderReplyQuote: replyQuote, reactionAggregate: reactionAggregate };
}));
