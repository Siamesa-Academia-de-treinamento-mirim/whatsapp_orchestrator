(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.ImpulsoComposerClipboard = factory();
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    function filesFromData(data) {
        if (!data) return [];
        var files = data.files && data.files.length ? Array.prototype.slice.call(data.files) : [];
        if (data.items) Array.prototype.slice.call(data.items).forEach(function (item) {
            if (item.kind !== 'file' || typeof item.getAsFile !== 'function') return;
            var file = item.getAsFile();
            if (file && !files.some(function (candidate) { return candidate === file || (candidate.name === file.name && candidate.size === file.size && candidate.type === file.type); })) files.push(file);
        });
        return files;
    }

    function plainText(data) {
        return data && typeof data.getData === 'function' ? String(data.getData('text/plain') || '') : '';
    }

    function shouldPreventDefault(data) {
        return filesFromData(data).length > 0 && plainText(data) === '';
    }

    return { filesFromData: filesFromData, plainText: plainText, shouldPreventDefault: shouldPreventDefault };
}));
