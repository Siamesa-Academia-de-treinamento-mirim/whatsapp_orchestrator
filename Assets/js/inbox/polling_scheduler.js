(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.ImpulsoPollingScheduler = factory();
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    function allSettled(promises) {
        if (typeof Promise.allSettled === 'function') return Promise.allSettled(promises);
        return Promise.all((promises || []).map(function (promise) {
            return Promise.resolve(promise).then(function (value) { return { status: 'fulfilled', value: value }; }, function (reason) { return { status: 'rejected', reason: reason }; });
        }));
    }

    function create(options) {
        options = options || {};
        var setTimeoutFn = options.setTimeout || setTimeout;
        var clearTimeoutFn = options.clearTimeout || clearTimeout;
        var timers = {};
        var busy = {};
        var destroyed = false;

        function schedule(name, delay, callback) {
            if (destroyed) return;
            name = String(name);
            if (timers[name]) clearTimeoutFn(timers[name]);
            timers[name] = setTimeoutFn(function () {
                delete timers[name];
                if (destroyed || typeof callback !== 'function') return;
                callback();
            }, Math.max(0, Number(delay) || 0));
        }

        function run(name, callback) {
            name = String(name);
            if (destroyed || busy[name]) return Promise.resolve({ status: 'skipped', name: name });
            busy[name] = true;
            var result;
            try { result = callback(); } catch (error) { result = Promise.reject(error); }
            return Promise.resolve(result).then(function (value) {
                return { status: 'fulfilled', value: value, name: name };
            }, function (reason) {
                return { status: 'rejected', reason: reason, name: name };
            }).then(function (settled) {
                delete busy[name];
                return settled;
            });
        }

        function destroy() {
            destroyed = true;
            Object.keys(timers).forEach(function (name) { clearTimeoutFn(timers[name]); delete timers[name]; });
            busy = {};
        }

        return {
            allSettled: allSettled,
            schedule: schedule,
            run: run,
            destroy: destroy,
            isBusy: function (name) { return !!busy[String(name)]; }
        };
    }

    return { allSettled: allSettled, create: create };
}));
