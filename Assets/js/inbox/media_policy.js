(function (root) {
    'use strict';

    function text(value) {
        return String(value == null ? '' : value);
    }

    function normalizeMime(value) {
        return text(value).toLowerCase().trim().split(';', 1)[0].trim();
    }

    function allows(mime, allowed) {
        var normalized = normalizeMime(mime);
        if (!normalized || !Array.isArray(allowed)) return false;
        return allowed.some(function (candidate) { return normalizeMime(candidate) === normalized; });
    }

    function allowsFile(file, policy, options) {
        policy = policy || {};
        options = options || {};
        if (policy.enabled !== true) return false;
        var allowed = options.recording ? (policy.recording_input_mime_types || []) : (policy.accepted_mime_types || []);
        return allows(file && file.type, allowed);
    }

    var api = {
        normalizeMime: normalizeMime,
        allows: allows,
        allowsFile: allowsFile
    };

    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.ImpulsoMediaPolicy = api;
}(typeof window !== 'undefined' ? window : globalThis));
