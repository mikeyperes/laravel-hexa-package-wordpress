(function (window) {
    "use strict";

    const sleep = (milliseconds) => new Promise(resolve => window.setTimeout(resolve, milliseconds));

    function createId(prefix) {
        const random = window.crypto && typeof window.crypto.randomUUID === "function"
            ? window.crypto.randomUUID()
            : Math.random().toString(16).slice(2) + Date.now().toString(16);
        return String(prefix || "media") + ":" + Date.now() + ":" + random;
    }

    function watch(operationId, onSnapshot, options) {
        const settings = Object.assign({interval: 350, timeout: 180000}, options || {});
        const started = Date.now();
        let stopped = false;
        let lastSequence = 0;

        const done = (async function () {
            while (!stopped && Date.now() - started < settings.timeout) {
                try {
                    const response = await window.fetch("/wordpress/media-operations/" + encodeURIComponent(operationId), {
                        credentials: "same-origin",
                        headers: {"Accept": "application/json", "X-Requested-With": "XMLHttpRequest"},
                    });
                    if (response.ok) {
                        const snapshot = await response.json();
                        const events = Array.isArray(snapshot.events) ? snapshot.events : [];
                        const freshEvents = events.filter(event => Number(event.sequence || 0) > lastSequence);
                        if (freshEvents.length) {
                            lastSequence = Math.max(lastSequence, ...freshEvents.map(event => Number(event.sequence || 0)));
                        }
                        if (typeof onSnapshot === "function") {
                            onSnapshot(snapshot, freshEvents);
                        }
                        if (snapshot.state === "complete" || snapshot.state === "error") {
                            return snapshot;
                        }
                    } else if (response.status !== 404) {
                        return null;
                    }
                } catch (error) {
                    // The upload request remains authoritative; polling retries transient network errors.
                }
                await sleep(settings.interval);
            }
            return null;
        })();

        return {
            operationId,
            done,
            stop: function () { stopped = true; },
        };
    }

    window.HexaWordPressMediaOperations = Object.freeze({createId, watch});
})(window);
