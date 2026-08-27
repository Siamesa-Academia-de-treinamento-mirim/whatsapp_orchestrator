(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.ImpulsoMessageSafeContent = factory();
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var MAX_TEXT = 10000;
    var MEDIA_PATH = /\/chatwoot_plugin\/api\/media\//i;

    function text(value) { return String(value == null ? '' : value); }

    function escapeHtml(value) {
        return text(value).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function safeHttpUrl(value, base) {
        value = text(value).trim();
        if (!value || value.length > 4096) return '';
        try {
            var parsed = new URL(value, base || undefined);
            if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return '';
            if (parsed.username || parsed.password) return '';
            return parsed.href;
        } catch (error) {
            return '';
        }
    }

    function safeMediaUrl(value, locationLike) {
        value = text(value).trim();
        if (!value || value.length > 4096 || value.indexOf('//') === 0) return '';
        var base = locationLike && locationLike.origin ? locationLike.origin : '';
        var url = safeHttpUrl(value, base || undefined);
        if (!url) return '';
        try {
            var parsed = new URL(url, base || undefined);
            var origin = locationLike && locationLike.origin ? String(locationLike.origin) : '';
            if (origin && parsed.origin !== origin) return '';
            if (!MEDIA_PATH.test(parsed.pathname)) return '';
            return parsed.href;
        } catch (error) {
            return '';
        }
    }

    function normalizedType(message) {
        message = message || {};
        var value = text(message.type || message.message_type || 'unsupported').toLowerCase();
        if (value === 'note') value = 'internal_note';
        if (value === 'audio' && message.metadata && message.metadata.is_voice_note) value = 'voice';
        return value || 'unsupported';
    }

    function bounded(value, limit) {
        var result = text(value).replace(/\0/g, '');
        limit = Number(limit || MAX_TEXT);
        return result.length > limit ? result.slice(0, limit) : result;
    }

    function trimTrailingPunctuation(value) {
        var trailing = '';
        while (/[.,!?;:)]$/.test(value)) trailing = value.slice(-1) + trailing, value = value.slice(0, -1);
        return { value: value, trailing: trailing };
    }

    function autoLink(value) {
        value = bounded(value, MAX_TEXT);
        var result = '';
        var last = 0;
        var pattern = /https?:\/\/[^\s<>]+/gi;
        var match;
        while ((match = pattern.exec(value))) {
            result += escapeHtml(value.slice(last, match.index));
            var cleaned = trimTrailingPunctuation(match[0]);
            var href = safeHttpUrl(cleaned.value);
            if (!href) result += escapeHtml(match[0]);
            else result += '<a href="' + escapeHtml(href) + '" target="_blank" rel="noopener noreferrer nofollow">' + escapeHtml(cleaned.value) + '</a>' + escapeHtml(cleaned.trailing);
            last = match.index + match[0].length;
        }
        result += escapeHtml(value.slice(last));
        return result.replace(/\r?\n/g, '<br>');
    }

    function plainText(message) {
        message = message || {};
        var content = message.content && typeof message.content === 'object' ? message.content : {};
        var type = normalizedType(message);
        var value = text(content.text || message.text_content || message.caption || content.caption || '');
        if (!value && type === 'location' && content.location) {
            value = [content.location.name, content.location.address].filter(Boolean).join(' — ');
        }
        if (!value && type === 'contact' && content.contact) value = content.contact.name || '';
        if (!value && type === 'template' && content.template) value = content.template.body || content.template.name || '';
        if (!value && type === 'interactive' && content.interactive) value = content.interactive.body || content.interactive.title || '';
        if (!value && type === 'document') value = message.file_name || (content.attachments && content.attachments[0] && content.attachments[0].file_name) || '';
        return bounded(value, 10000).trim();
    }

    function coordinates(value) {
        var latitude = Number(value && (value.latitude != null ? value.latitude : value.lat));
        var longitude = Number(value && (value.longitude != null ? value.longitude : value.lng));
        if (!isFinite(latitude) || !isFinite(longitude) || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) return null;
        return { latitude: latitude, longitude: longitude };
    }

    function formatBytes(value) {
        var bytes = Number(value || 0);
        if (!isFinite(bytes) || bytes <= 0) return '';
        var units = ['B', 'KB', 'MB', 'GB'];
        var index = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
        return (bytes / Math.pow(1024, index)).toFixed(index ? 1 : 0) + ' ' + units[index];
    }

    function formatDuration(value) {
        var seconds = Math.max(0, Math.floor(Number(value || 0)));
        if (!seconds) return '';
        var minutes = Math.floor(seconds / 60);
        return minutes + ':' + String(seconds % 60).padStart(2, '0');
    }

    return {
        bounded: bounded,
        coordinates: coordinates,
        escapeHtml: escapeHtml,
        autoLink: autoLink,
        formatBytes: formatBytes,
        formatDuration: formatDuration,
        normalizedType: normalizedType,
        plainText: plainText,
        safeHttpUrl: safeHttpUrl,
        safeMediaUrl: safeMediaUrl
    };
}));
