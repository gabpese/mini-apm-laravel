/**
 * mini-apm browser client. No dependencies.
 *
 *   const apm = new MiniApm({ endpoint: 'https://host', apiKey: 'apm_...', appVersion: '1.2.0' });
 *   apm.start();
 *   apm.track('exportar_pdf');
 *   apm.captureException(error, { fatal: true });
 */

const MAX_EVENTS_PER_REQUEST = 100; // the API accepts at most 100 events per batch
const MAX_QUEUE = 500; // oldest events are dropped beyond this, so a dead API cannot eat memory
const MAX_MESSAGE = 2000;
const MAX_STACK = 20000;

export class MiniApm {
    /**
     * @param {object} options
     * @param {string} options.endpoint Base URL of the mini-apm server
     * @param {string} options.apiKey Project API key
     * @param {string} options.appVersion Version of the monitored app
     * @param {string} [options.userRef] Anonymous user id (default: generated and kept in localStorage)
     * @param {number} [options.flushInterval] ms between automatic sends; 0 disables the timer
     * @param {number} [options.maxBatchSize] Send as soon as this many events are queued
     * @param {number} [options.retryDelay] ms that sending on batch size pauses after a failure; the timer and flush() still try
     * @param {boolean} [options.autoCapture] Capture window errors and rejected promises
     * @param {object} [options.env] Override the detected machine data ({ os, ram_mb, gpu })
     * @param {typeof fetch} [options.fetch] Custom fetch, mainly for tests
     */
    constructor(options = {}) {
        const { endpoint, apiKey, appVersion } = options;
        if (!endpoint || !apiKey || !appVersion) {
            throw new Error('MiniApm needs endpoint, apiKey and appVersion');
        }

        this.url = `${endpoint.replace(/\/+$/, '')}/api/v1/events`;
        this.apiKey = apiKey;
        this.appVersion = appVersion;
        this.userRef = options.userRef ?? loadUserRef();
        this.flushInterval = options.flushInterval ?? 5000;
        this.maxBatchSize = Math.min(
            options.maxBatchSize ?? 20,
            MAX_EVENTS_PER_REQUEST,
        );
        this.retryDelay = options.retryDelay ?? 10000;
        this.retryAt = 0;
        this.autoCapture = options.autoCapture ?? true;
        this.env = options.env ?? detectEnv();
        this.fetch = options.fetch ?? globalThis.fetch?.bind(globalThis);

        this.queue = [];
        this.timer = null;
        this.sending = null;
        this.pending = 0; // sends queued or running; a new one is only started by batch size when this is 0
        this.started = false;
        this.listeners = [];
    }

    /** Opens a session and starts capturing. Call once when the app starts. */
    start() {
        if (this.started) return;
        this.started = true;

        this.enqueue({ type: 'session_start', env: this.env });

        if (this.autoCapture && typeof window !== 'undefined') {
            this.listen('error', (event) =>
                this.captureException(event.error ?? new Error(event.message)),
            );
            this.listen('unhandledrejection', (event) => {
                const reason = event.reason;
                this.captureException(
                    reason instanceof Error
                        ? reason
                        : new Error(String(reason)),
                );
            });
            this.listen('pagehide', () => this.flush());
        }

        if (this.flushInterval > 0) {
            this.timer = setInterval(() => this.flush(), this.flushInterval);
        }
    }

    /** Stops the timer and the listeners, then sends what is left. */
    stop() {
        clearInterval(this.timer);
        this.timer = null;
        this.listeners.forEach(([name, handler]) =>
            window.removeEventListener(name, handler),
        );
        this.listeners = [];
        this.started = false;

        return this.flush();
    }

    /** Records that a feature was used. */
    track(name, properties) {
        this.enqueue({ type: 'feature_used', name, properties });
    }

    /** Records an error, or a crash when `fatal` is true. */
    captureException(error, { fatal = false } = {}) {
        const err = error instanceof Error ? error : new Error(String(error));

        this.enqueue({
            type: fatal ? 'crash' : 'error',
            message: truncate(`${err.name}: ${err.message}`, MAX_MESSAGE),
            stack: truncate(err.stack ?? '', MAX_STACK) || undefined,
        });
    }

    /**
     * Sends everything queued. Resolves to true only when the API accepted every event.
     * A batch the API rejects for good (bad key, invalid data) is dropped and gives false;
     * one that failed for a passing reason stays queued for the next flush.
     */
    flush() {
        // Wait for the request in flight, so batches never overlap or reorder.
        this.pending++;
        this.sending = (this.sending ?? Promise.resolve())
            .then(() => this.sendQueued())
            .finally(() => this.pending--);

        return this.sending;
    }

    enqueue(event) {
        this.queue.push({
            ...event,
            occurred_at: new Date().toISOString(),
            app_version: this.appVersion,
            user_ref: this.userRef,
        });

        if (this.queue.length > MAX_QUEUE) {
            this.queue.splice(0, this.queue.length - MAX_QUEUE);
        }

        if (
            this.queue.length >= this.maxBatchSize &&
            this.pending === 0 &&
            Date.now() >= this.retryAt
        ) {
            this.flush();
        }
    }

    async sendQueued() {
        let ok = true;

        while (this.queue.length > 0) {
            const batch = this.queue.splice(0, MAX_EVENTS_PER_REQUEST);

            const result = await this.post(batch);

            if (result === 'rejected') {
                ok = false;
            } else if (result === 'retry') {
                this.queue.unshift(...batch); // try again on the next flush
                this.retryAt = Date.now() + this.retryDelay;
                return false;
            }
        }

        return ok;
    }

    async post(events) {
        try {
            const response = await this.fetch(this.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    Authorization: `Bearer ${this.apiKey}`,
                },
                body: JSON.stringify({ events }),
                keepalive: true, // lets the request finish while the page is closing
            });

            if (response.ok) return 'accepted';

            // A client error (bad key, invalid data) will never succeed. Rate limiting will.
            return response.status >= 400 &&
                response.status < 500 &&
                response.status !== 429
                ? 'rejected'
                : 'retry';
        } catch {
            return 'retry';
        }
    }

    listen(name, handler) {
        window.addEventListener(name, handler);
        this.listeners.push([name, handler]);
    }
}

function truncate(text, max) {
    return text.length > max ? text.slice(0, max) : text;
}

function loadUserRef() {
    const key = 'mini_apm_user_ref';

    try {
        let ref = localStorage.getItem(key);
        if (!ref) {
            ref = `u_${Math.random().toString(36).slice(2, 8)}`;
            localStorage.setItem(key, ref);
        }
        return ref;
    } catch {
        return `u_${Math.random().toString(36).slice(2, 8)}`; // storage blocked: id lasts one visit
    }
}

function detectEnv() {
    if (typeof navigator === 'undefined') return {};

    const ua = navigator.userAgent ?? '';
    const os =
        navigator.userAgentData?.platform ||
        (/Windows/.test(ua)
            ? 'Windows'
            : /Mac OS X/.test(ua)
              ? 'macOS'
              : /Android/.test(ua)
                ? 'Android'
                : /Linux/.test(ua)
                  ? 'Linux'
                  : undefined);

    return {
        os,
        ram_mb: navigator.deviceMemory
            ? navigator.deviceMemory * 1024
            : undefined, // Chromium only, rounded by the browser
        gpu: detectGpu(),
    };
}

function detectGpu() {
    try {
        const gl = document.createElement('canvas').getContext('webgl');
        const info = gl?.getExtension('WEBGL_debug_renderer_info');

        return info ? gl.getParameter(info.UNMASKED_RENDERER_WEBGL) : undefined;
    } catch {
        return undefined;
    }
}
