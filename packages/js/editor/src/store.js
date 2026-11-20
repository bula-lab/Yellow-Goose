import { applyChanges, checkDocument, projectCommand, validId } from './document.js';
export { identityTransform, projectCommand } from './document.js';

export function newId() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    return Array.from(globalThis.crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
}

export function createSceneClient({ projectId, sceneId, csrfToken, url, fetcher = (...args) => fetch(...args) }) {
    const base = url ?? `/api/projects/${encodeURIComponent(projectId)}/scenes/${encodeURIComponent(sceneId)}`;
    async function request(path, options) {
        const target = new URL(path, location.href);
        if (target.origin !== location.origin) throw new Error('Expected a same-origin scene URL.');
        const response = await fetcher(target, { credentials: 'same-origin', redirect: 'error', cache: 'no-store', ...options });
        const data = await response.json();
        if (!response.ok) {
            const error = new Error(typeof data.error === 'string' ? data.error : 'Scene request failed.');
            throw Object.assign(error, { status: response.status, errors: data.errors ?? {} });
        }
        return data;
    }
    return {
        load: signal => request(base, { signal }),
        send: (command, signal) => request(base + '/commands', { signal, method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }, body: JSON.stringify(command) }),
    };
}

export function createSceneStore({ initialDocument, client, autosaveMs = 500, makeId = newId }) {
    let confirmed = checkDocument(initialDocument);
    let document = confirmed;
    let pending = [];
    const undo = [], redo = [], listeners = new Set();
    let state, timer, flight, request, error, conflict, saving = false, active = true, holds = 0;
    let resolving = false, rejected = null, remote = null, explicitFlush = false;
    const eventRequests = new Set();
    let historyActions = Promise.resolve();
    function publish() {
        state = { confirmed, document, pendingCount: pending.length, dirty: pending.length > 0, error, conflict, rejectedCount: rejected?.count ?? 0,
            canUndo: pending.length > 0 || undo.length > 0, canRedo: !pending.length && redo.length > 0, resolving,
            status: conflict ? 'conflict' : error ? 'error' : saving ? 'saving' : pending.length ? 'pending' : 'saved' };
        for (const listener of listeners) listener();
    }
    function schedule() {
        clearTimeout(timer);
        if (active && !holds && !error && !conflict && !resolving && pending.length && Number.isFinite(autosaveMs)) {
            timer = setTimeout(() => startFlush(false).catch(() => {}), autosaveMs);
        }
    }
    function enqueue(command, history = 'edit') {
        if (resolving || conflict || error) throw new Error('Resolve the pending save before editing.');
        // The caller may continue editing their input after dispatch.
        const next = projectCommand(document, command);
        const operation = JSON.parse(JSON.stringify(command));
        const tail = pending.at(-1);
        if (history === 'edit' && operation.type === 'entity.transform' && tail?.history === 'edit' && !tail.envelope
            && tail.operation.type === operation.type && tail.operation.entityId === operation.entityId) {
            Object.assign(tail.operation.payload, operation.payload);
        } else {
            pending.push({ operation, history, commandId: makeId(), envelope: null });
        }
        document = next;
        publish(); schedule();
    }
    function acknowledge(item, receipt) {
        if (receipt.commandId !== item.commandId || receipt.revision !== confirmed.revision + 1 || !receipt.inverse
            || !Array.isArray(receipt.changes?.upsert) || !Array.isArray(receipt.changes?.remove)) throw new Error('Invalid scene acknowledgement. Reload the server version.');
        const next = checkDocument(applyChanges(confirmed, receipt.changes, receipt.revision), confirmed);
        // Validate the remaining optimistic operations against the canonical server values.
        confirmed = next;
        pending.shift();
        if (item.history === 'undo') { undo.pop(); redo.push(receipt.inverse); }
        else if (item.history === 'redo') { redo.pop(); undo.push(receipt.inverse); }
        else { undo.push(receipt.inverse); redo.length = 0; }
        document = pending.reduce((doc, entry) => projectCommand(doc, entry.operation), confirmed);
    }
    function observeRemote() {
        if (!remote || resolving) return;
        if (remote.revision <= confirmed.revision) { remote = null; return; }
        // A lost HTTP response is ambiguous. The unchanged envelope must retrieve
        // its original receipt before a foreign revision can replace its base.
        if (pending[0]?.envelope && !conflict) return;
        if (pending.length) {
            conflict = { code: 'revision_conflict', revision: remote.revision, document: remote };
            error = 'Another editor saved a newer revision.';
        } else {
            document = confirmed = remote; remote = null; error = null; conflict = null;
            undo.length = 0; redo.length = 0;
        }
    }
    async function receiveEvent(event) {
        if (!active || event?.projectId !== confirmed.projectId || event.sceneId !== confirmed.sceneId) return;
        if (event.type !== 'scene.changed' || !validId(event.commandId) || !Number.isSafeInteger(event.revision) || event.revision < 1) {
            throw new Error('Invalid scene event.');
        }
        if (event.revision <= confirmed.revision || event.revision <= (remote?.revision ?? 0)) return;
        const envelope = pending[0]?.envelope;
        if (envelope?.commandId === event.commandId && envelope.expectedRevision + 1 === event.revision) return;
        let latest;
        if (event.revision === confirmed.revision + 1 && Array.isArray(event.changes?.upsert) && Array.isArray(event.changes?.remove)) {
            latest = checkDocument(applyChanges(confirmed, event.changes, event.revision), confirmed);
        } else {
            const controller = new AbortController();
            eventRequests.add(controller);
            try {
                latest = checkDocument(await client.load(controller.signal), confirmed);
                if (controller.signal.aborted) return;
                if (latest.revision < event.revision) throw new Error('Snapshot is behind the scene event.');
            } finally { eventRequests.delete(controller); }
        }
        if (latest.revision <= confirmed.revision || latest.revision <= (remote?.revision ?? 0)) return;
        remote = latest; observeRemote(); publish(); schedule();
    }
    function rejectedRemainder() {
        let next = confirmed;
        const keep = [];
        for (const item of pending.slice(1)) {
            try { next = projectCommand(next, item.operation); keep.push(item); }
            catch { /* This operation depends on the rejected edit. */ }
        }
        return { keep, next, count: pending.length - keep.length };
    }
    async function drain() {
        saving = true; error = null; rejected = null; publish();
        while (pending.length && active && (explicitFlush || !holds)) {
            const item = pending[0];
            // Preserve the exact envelope following a lost response, including expectedRevision.
            item.envelope ??= { ...item.operation, commandId: item.commandId, expectedRevision: confirmed.revision };
            request = new AbortController();
            const receipt = await client.send(item.envelope, request.signal);
            if (request.signal.aborted) throw new Error('Save interrupted.');
            acknowledge(item, receipt); observeRemote(); publish();
            if (conflict) throw Object.assign(new Error(error), { status: 409, errors: conflict });
        }
    }
    function startFlush(explicit) {
        clearTimeout(timer);
        if (flight) {
            if (!explicit) return flight;
            explicitFlush = true;
            return flight.then(() => pending.length ? flush() : undefined);
        }
        if (conflict || resolving || !active) return Promise.reject(new Error('Resolve the scene state before saving.'));
        if (!pending.length) return Promise.resolve();
        explicitFlush = explicit;
        flight = drain().catch(failure => {
            if (active) {
                error = failure.message;
                if (failure.status === 409 && failure.errors?.code === 'revision_conflict') conflict = failure.errors;
                if (failure.status === 422 && pending.length) rejected = rejectedRemainder();
            }
            throw failure;
        }).finally(() => { flight = null; request = null; saving = false; publish(); });
        return flight;
    }
    const flush = () => startFlush(true);
    function historyAction(mode) {
        const next = historyActions.then(async () => {
            await flush();
            const command = (mode === 'undo' ? undo : redo).at(-1);
            if (!command) return;
            enqueue(command, mode);
            await flush();
        });
        historyActions = next.catch(() => {});
        return next;
    }
    async function reconcile(keepPending) {
        if (resolving || saving) throw new Error('Wait for the current save.');
        if (keepPending && !conflict) throw new Error('There is no revision conflict.');
        clearTimeout(timer);
        resolving = true; publish();
        request = new AbortController();
        try {
            const snapshot = checkDocument(await client.load(request.signal), confirmed);
            const latest = remote && remote.revision > snapshot.revision ? remote : snapshot;
            if (request.signal.aborted) throw new Error('Reload interrupted.');
            if (latest.revision < confirmed.revision) throw new Error('Snapshot is behind the confirmed scene.');
            const queue = keepPending ? pending.map(item => ({ ...item, envelope: null, commandId: makeId(), history: 'edit' })) : [];
            const projected = queue.reduce((doc, item) => projectCommand(doc, item.operation), latest);
            confirmed = latest; document = projected; pending = queue; rejected = null; remote = null;
            undo.length = 0; redo.length = 0; error = null; conflict = null;
        } catch (failure) { if (active) error = failure.message; throw failure; }
        finally { resolving = false; request = null; publish(); }
        if (keepPending) await flush();
    }
    publish();
    return {
        getSnapshot: () => state,
        subscribe(listener) { listeners.add(listener); return () => listeners.delete(listener); },
        enqueue, flush, receiveEvent, undo: () => historyAction('undo'), redo: () => historyAction('redo'),
        reload: () => reconcile(false), retryOnLatest: () => reconcile(true),
        holdAutosave() { holds++; clearTimeout(timer); let held = true; return () => { if (held) { held = false; holds--; schedule(); } }; },
        discardRejected() {
            if (!rejected || saving) throw new Error('There is no rejected edit.');
            document = rejected.next; pending = rejected.keep;
            rejected = null; error = null; observeRemote(); publish(); schedule();
        },
        discardPending() {
            clearTimeout(timer);
            // A command already sent may have committed. Its receipt still owns that revision.
            pending = saving ? pending.slice(0, 1) : [];
            document = pending.reduce((doc, item) => projectCommand(doc, item.operation), confirmed);
            conflict = null; error = null; rejected = null; observeRemote(); publish();
        },
        activate() { active = true; schedule(); },
        dispose() { active = false; clearTimeout(timer); request?.abort(); for (const controller of eventRequests) controller.abort(); },
    };
}
