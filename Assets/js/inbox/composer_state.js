(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.ImpulsoComposerState = factory();
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var VERSION = 2;
    var MODES = { reply: 'reply', note: 'note' };

    function numberId(value) {
        var id = Number(value || 0);
        return isFinite(id) && id > 0 ? id : 0;
    }

    function modeName(value) { return value === MODES.note ? MODES.note : MODES.reply; }

    function clone(value) {
        if (value == null) return value;
        if (Array.isArray(value)) return value.slice();
        if (typeof value === 'object') {
            var result = {};
            Object.keys(value).forEach(function (key) { result[key] = value[key]; });
            return result;
        }
        return value;
    }

    function safeReplyTarget(target) {
        if (!target || numberId(target.messageId || target.id) < 1) return null;
        return {
            messageId: numberId(target.messageId || target.id),
            author: String(target.author || '').slice(0, 160),
            preview: String(target.preview || target.text || '').slice(0, 500)
        };
    }

    function keyFor(conversationId, mode) {
        return String(numberId(conversationId)) + ':' + modeName(mode);
    }

    function record(conversationId, mode) {
        return {
            conversationId: numberId(conversationId),
            mode: modeName(mode),
            text: '',
            replyTarget: null,
            attachments: [],
            revision: 0,
            updatedAt: 0,
            draftLoaded: false,
            dirty: false
        };
    }

    function createStore(options) {
        options = options || {};
        var records = {};
        var storage = options.storage || null;
        var now = options.now || function () { return Date.now(); };
        var scope = String(options.scope || 'whatsapp-orchestrator');
        var actorId = numberId(options.actorId) || 'anonymous';
        var draftPrefix = 'impulso:composer:v2:' + scope + ':' + actorId + ':';
        var autosaveTimers = {};
        var autosaveDelay = Math.max(50, Number(options.autosaveDelay || 500));
        var maxDraftAge = Math.max(86400000, Number(options.maxDraftAge || 30 * 86400000));
        var maxDrafts = Math.max(4, Number(options.maxDrafts || 100));

        function get(conversationId, mode) {
            var key = keyFor(conversationId, mode);
            if (!records[key]) records[key] = record(conversationId, mode);
            return records[key];
        }

        function draftKey(conversationId, mode) {
            return draftPrefix + numberId(conversationId) + ':' + modeName(mode);
        }

        function setText(conversationId, mode, value) {
            var item = get(conversationId, mode);
            value = String(value == null ? '' : value);
            if (item.text !== value) {
                item.text = value;
                item.revision += 1;
                item.updatedAt = now();
                item.dirty = true;
            }
            return item;
        }

        function setReplyTarget(conversationId, mode, target) {
            var item = get(conversationId, mode);
            var safe = safeReplyTarget(target);
            var before = JSON.stringify(item.replyTarget);
            if (before !== JSON.stringify(safe)) {
                item.replyTarget = safe;
                item.revision += 1;
                item.updatedAt = now();
                item.dirty = true;
            }
            return item;
        }

        function setAttachments(conversationId, mode, attachments) {
            var item = get(conversationId, mode);
            item.attachments = Array.isArray(attachments) ? attachments : [];
            return item;
        }

        function snapshot(conversationId, mode) {
            var item = get(conversationId, mode);
            return {
                conversationId: item.conversationId,
                mode: item.mode,
                text: item.text,
                replyTarget: clone(item.replyTarget),
                revision: item.revision,
                attachmentIds: item.attachments.map(function (attachment) { return String(attachment && attachment.id || ''); })
            };
        }

        function sameSnapshot(item, sent) {
            return !!sent && item.conversationId === numberId(sent.conversationId)
                && item.mode === modeName(sent.mode)
                && item.revision === Number(sent.revision)
                && item.text === String(sent.text == null ? '' : sent.text);
        }

        function commitText(sent, clearReply) {
            var item = get(sent && sent.conversationId, sent && sent.mode);
            if (!sameSnapshot(item, sent)) return false;
            item.text = '';
            item.revision += 1;
            item.updatedAt = now();
            if (clearReply !== false) item.replyTarget = null;
            return true;
        }

        function clearForSend(sent) {
            var item = get(sent && sent.conversationId, sent && sent.mode);
            if (!sameSnapshot(item, sent)) return false;
            item.text = '';
            item.replyTarget = null;
            item.revision += 1;
            item.updatedAt = now();
            item.dirty = true;
            return true;
        }

        function commitMedia(sent, result) {
            var item = get(sent && sent.conversationId, sent && sent.mode);
            if (!sent || item.conversationId !== numberId(sent.conversationId) || item.mode !== modeName(sent.mode)) return false;
            var changed = false;
            if (result && result.captionSent && sameSnapshot(item, sent)) {
                item.text = '';
                item.revision += 1;
                item.updatedAt = now();
                changed = true;
            }
            if (result && result.replySent && item.replyTarget && JSON.stringify(item.replyTarget) === JSON.stringify(safeReplyTarget(sent.replyTarget))) {
                item.replyTarget = null;
                item.revision += 1;
                item.updatedAt = now();
                changed = true;
            }
            return changed;
        }

        function serializable(item) {
            return {
                version: VERSION,
                conversation_id: item.conversationId,
                mode: item.mode,
                text: item.text,
                reply_target: safeReplyTarget(item.replyTarget),
                updated_at: item.updatedAt || now()
            };
        }

        function read(key) {
            if (!storage || typeof storage.getItem !== 'function') return null;
            try { return JSON.parse(storage.getItem(key) || 'null'); } catch (error) { return null; }
        }

        function remove(key) {
            if (!storage || typeof storage.removeItem !== 'function') return;
            try { storage.removeItem(key); } catch (error) { /* private mode/quota */ }
        }

        function saveDraft(conversationId, mode) {
            var item = get(conversationId, mode);
            var key = draftKey(conversationId, mode);
            var payload = serializable(item);
            if (!payload.text.trim() && !payload.reply_target) {
                remove(key);
                item.draftLoaded = true;
                item.dirty = false;
                return false;
            }
            if (!storage || typeof storage.setItem !== 'function') return false;
            try {
                storage.setItem(key, JSON.stringify(payload));
                item.draftLoaded = true;
                item.dirty = false;
                return true;
            } catch (error) { return false; }
        }

        function restoreDraft(conversationId, mode) {
            var item = get(conversationId, mode);
            if (item.draftLoaded) return item;
            item.draftLoaded = true;
            if (item.dirty) return item;
            var payload = read(draftKey(conversationId, mode));
            if (!payload || Number(payload.version) !== VERSION || numberId(payload.conversation_id) !== item.conversationId || modeName(payload.mode) !== item.mode) return item;
            if (Number(payload.updated_at || 0) < now() - maxDraftAge) return item;
            item.text = String(payload.text || '');
            item.replyTarget = safeReplyTarget(payload.reply_target);
            item.updatedAt = Number(payload.updated_at || now());
            item.revision += 1;
            item.dirty = false;
            return item;
        }

        function discardDraft(conversationId, mode) {
            remove(draftKey(conversationId, mode));
            var item = get(conversationId, mode);
            item.text = '';
            item.replyTarget = null;
            item.revision += 1;
            item.updatedAt = now();
            item.draftLoaded = true;
            item.dirty = false;
            return item;
        }

        function scheduleAutosave(conversationId, mode) {
            var key = keyFor(conversationId, mode);
            if (autosaveTimers[key]) clearTimeout(autosaveTimers[key]);
            autosaveTimers[key] = setTimeout(function () {
                delete autosaveTimers[key];
                saveDraft(conversationId, mode);
            }, autosaveDelay);
        }

        function flushAutosave(conversationId, mode) {
            var key = keyFor(conversationId, mode);
            if (autosaveTimers[key]) { clearTimeout(autosaveTimers[key]); delete autosaveTimers[key]; }
            return saveDraft(conversationId, mode);
        }

        function flushAll() {
            Object.keys(autosaveTimers).forEach(function (key) {
                clearTimeout(autosaveTimers[key]);
                delete autosaveTimers[key];
            });
            var saved = 0;
            Object.keys(records).forEach(function (key) {
                if (records[key].dirty && saveDraft(records[key].conversationId, records[key].mode)) saved += 1;
            });
            return saved;
        }

        function pruneDrafts() {
            if (!storage || typeof storage.length !== 'number' || typeof storage.key !== 'function') return 0;
            var entries = [];
            for (var index = 0; index < storage.length; index += 1) {
                var key = storage.key(index);
                if (!key || key.indexOf(draftPrefix) !== 0) continue;
                var payload = read(key);
                if (!payload || Number(payload.updated_at || 0) < now() - maxDraftAge || (!String(payload.text || '').trim() && !payload.reply_target)) {
                    remove(key);
                    continue;
                }
                entries.push({ key: key, updatedAt: Number(payload.updated_at || 0) });
            }
            entries.sort(function (left, right) { return right.updatedAt - left.updatedAt; });
            entries.slice(maxDrafts).forEach(function (entry) { remove(entry.key); });
            return entries.length > maxDrafts ? entries.length - maxDrafts : 0;
        }

        return {
            modes: MODES,
            get: get,
            setText: setText,
            setReplyTarget: setReplyTarget,
            setAttachments: setAttachments,
            snapshot: snapshot,
            commitText: commitText,
            clearForSend: clearForSend,
            commitMedia: commitMedia,
            saveDraft: saveDraft,
            restoreDraft: restoreDraft,
            discardDraft: discardDraft,
            scheduleAutosave: scheduleAutosave,
            flushAutosave: flushAutosave,
            flushAll: flushAll,
            pruneDrafts: pruneDrafts,
            draftKey: draftKey,
            safeReplyTarget: safeReplyTarget,
            version: VERSION
        };
    }

    return { VERSION: VERSION, MODES: MODES, createStore: createStore, safeReplyTarget: safeReplyTarget };
}));
