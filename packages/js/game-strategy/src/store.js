export { createStrategyClient } from './client.js';

function commandId() {
    if (globalThis.crypto.randomUUID) return globalThis.crypto.randomUUID();
    return Array.from(globalThis.crypto.getRandomValues(new Uint8Array(16)), n => n.toString(16).padStart(2, '0')).join('');
}

const monotonicNow = () => typeof performance === 'undefined' ? 0 : performance.now();
const authenticationFailed = error => [401, 403, 404].includes(error.status);

// Elapsed time is display-only. Only a snapshot can remove an operation or release its reward.
export function estimatedServerTime(state, now = monotonicNow()) {
    if (!state.snapshot) return null;
    const elapsed = state.sampledAt == null ? 0 : Math.max(0, now - state.sampledAt);
    return state.snapshot.serverNow + Math.floor(elapsed / 1000);
}

export function createStrategyStore(initial, client, {
    interval = 3000, now = monotonicNow, makeId = commandId,
    schedule = (fn, ms) => setTimeout(fn, ms), cancel = timer => clearTimeout(timer),
} = {}) {
    if (!Number.isFinite(interval) || interval <= 0) throw new RangeError('Expected a positive polling interval.');
    const gameId = initial.gameId;
    const listeners = new Set();
    let state = { snapshot: initial, sampledAt: null, cursor: initial.cursor, events: [], queue: [],
        connection: 'connecting', error: null };
    let mode = 'stopped', generation = 0, flight, timer, failures = 0, needsSync = true;
    let storage;
    const storageKey = 'yg.strategy.pending.' + gameId;
    function update(patch) {
        state = { ...state, ...patch };
        if (patch.queue && storage) {
            try {
                if (patch.queue.length) storage.setItem(storageKey, JSON.stringify(patch.queue));
                else storage.removeItem(storageKey);
            } catch { /* A browser can disable session storage. Keep the in-memory queue. */ }
        }
        for (const listener of listeners) listener();
    }
    function replaceHead(patch) {
        return [{ ...state.queue[0], ...patch }, ...state.queue.slice(1)];
    }
    function wake(delay = 0) {
        cancel(timer);
        if (mode === 'online' && !flight) timer = schedule(run, delay);
    }
    function setMode(next) {
        if (mode === next || state.connection === 'revoked') return;
        mode = next;
        ++generation;
        cancel(timer);
        flight?.abort();
        flight = null;
        needsSync = true;
        const interrupted = state.queue[0]?.status === 'sending';
        update({ connection: next === 'online' ? 'connecting' : next,
            ...(interrupted ? { queue: replaceHead({ status: 'uncertain' }) } : {}) });
        wake();
    }
    async function synchronize(controller) {
        const signal = controller.signal;
        let snapshot = await client.snapshot(signal);
        signal.throwIfAborted();
        if (snapshot.gameId !== gameId) throw new Error('Unexpected game in snapshot.');
        let cursor = state.cursor;
        let history = state.events;
        const feed = await client.events(cursor, signal);
        signal.throwIfAborted();
        let reset = feed.reset || !Array.isArray(feed.items);
        if (!reset) {
            for (const event of feed.items) {
                if (event.cursor <= cursor) continue;
                if (event.cursor !== cursor + 1) { reset = true; break; }
                cursor = event.cursor;
                history = [...history.slice(-19), event];
            }
            if (feed.cursor !== cursor || (!feed.hasMore && cursor < snapshot.cursor)) reset = true;
        }
        if (reset || cursor > snapshot.cursor) {
            snapshot = await client.snapshot(signal);
            signal.throwIfAborted();
            if (snapshot.gameId !== gameId) throw new Error('Unexpected game in snapshot.');
        }
        if (snapshot.revision < state.snapshot.revision && !feed.reset) throw new Error('Received an older game revision.');
        update({ snapshot, sampledAt: now(), cursor: reset ? snapshot.cursor : cursor,
            events: reset ? [] : history, connection: 'online' });
        return !reset && feed.hasMore;
    }
    function revoke() {
        setMode('stopped');
        update({ connection: 'revoked', snapshot: null, events: [], cursor: 0, sampledAt: null, queue: [], error: null });
    }
    async function run() {
        if (flight || mode !== 'online') return;
        const epoch = generation;
        const controller = flight = new AbortController();
        let delay = interval;
        try {
            if (needsSync || state.queue[0]?.status !== 'queued') {
                const more = await synchronize(controller);
                controller.signal.throwIfAborted();
                needsSync = false;
                failures = 0;
                if (more) delay = Math.min(interval, 250);
            }
            const item = state.queue[0];
            if (item?.status === 'queued') {
                update({ queue: replaceHead({ status: 'sending' }), error: null });
                try {
                    const receipt = await client.command(item.envelope, controller.signal);
                    controller.signal.throwIfAborted();
                    if (receipt.commandId !== item.envelope.commandId) throw new Error('Unrecognized command receipt.');
                    update({ queue: state.queue.slice(1), connection: 'connecting' });
                } catch (error) {
                    controller.signal.throwIfAborted();
                    if (authenticationFailed(error)) throw error;
                    if (error.status === 409 && ['revision_conflict', 'rules_conflict'].includes(error.code)) {
                        update({ queue: replaceHead({ status: 'conflict' }), connection: 'connecting' });
                    } else if (error.status >= 400 && error.status < 500 && error.status !== 408 && error.status !== 429) {
                        update({ queue: state.queue.slice(1), error: error.message });
                    } else {
                        update({ queue: replaceHead({ status: 'uncertain' }) });
                        throw error;
                    }
                }
                needsSync = true;
                delay = 0;
            }
        } catch (error) {
            if (controller.signal.aborted) return;
            if (authenticationFailed(error)) {
                revoke();
            } else {
                needsSync = true;
                update({ connection: 'reconnecting' });
                delay = Math.min(Math.max(interval, 30000), interval * 2 ** Math.min(++failures, 5));
            }
        } finally {
            if (epoch === generation) {
                flight = null;
                wake(delay);
            }
        }
    }
    function envelope(type, payload, expectedRevision, expectedRulesVersion) {
        return { commandId: makeId(), type, expectedRevision, expectedRulesVersion, payload: structuredClone(payload) };
    }
    function enqueue(type, payload, expectedRevision = state.snapshot?.revision, expectedRulesVersion = state.snapshot?.rulesVersion) {
        if (state.connection !== 'online' || typeof expectedRulesVersion !== 'string') return false;
        const duplicate = state.queue.some(({ envelope: previous }) =>
            type === previous.type && JSON.stringify(payload) === JSON.stringify(previous.payload));
        if (duplicate) return false;
        update({ queue: [...state.queue, { envelope: envelope(type, payload, expectedRevision, expectedRulesVersion), status: 'queued' }], error: null });
        wake();
        return true;
    }
    function retry() {
        const item = state.queue[0];
        if (state.connection !== 'online' || item?.status !== 'uncertain') return;
        update({ queue: replaceHead({ status: 'queued' }), error: null });
        wake();
    }
    function retryReviewed(expectedRevision = state.snapshot?.revision, expectedRulesVersion = state.snapshot?.rulesVersion) {
        const item = state.queue[0];
        if (state.connection !== 'online' || item?.status !== 'conflict' || needsSync || typeof expectedRulesVersion !== 'string') return;
        const command = envelope(item.envelope.type, item.envelope.payload, expectedRevision, expectedRulesVersion);
        update({ queue: replaceHead({ status: 'queued', envelope: command }) });
        wake();
    }
    function restore(saved) {
        if (storage || !saved) return;
        storage = saved;
        try {
            const items = JSON.parse(storage.getItem(storageKey) || '[]');
            if (Array.isArray(items) && items.length) {
                const valid = items.filter(i => i?.envelope && typeof i.envelope.commandId === 'string'
                    && typeof i.envelope.type === 'string' && Number.isSafeInteger(i.envelope.expectedRevision)
                    && i.envelope.payload && typeof i.envelope.payload === 'object');
                update({ queue: valid.map(item => ({
                    envelope: item.envelope,
                    status: item.status === 'conflict' || typeof item.envelope.expectedRulesVersion !== 'string' ? 'conflict' : 'uncertain',
                })) });
            }
        } catch { /* Storage may be unavailable or left over from a previous version. */ }
    }
    return { getState: () => state, subscribe(listener) { listeners.add(listener); return () => listeners.delete(listener); },
        setMode, restore, revoke, enqueue, retry, retryReviewed,
        refresh() { needsSync = true; wake(); },
        discardConflict() {
            if (state.queue[0]?.status === 'conflict') { update({ queue: state.queue.slice(1) }); wake(); }
        } };
}
