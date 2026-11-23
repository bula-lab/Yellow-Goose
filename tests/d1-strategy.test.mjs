import test from 'node:test';
import assert from 'node:assert/strict';
import { responseData } from '../packages/js/ui/src/http.js';
import { createStrategyClient, createStrategyStore, estimatedServerTime } from '../packages/js/game-strategy/src/store.js';

function deferred() {
    let resolve;
    const promise = new Promise(done => { resolve = done; });
    return { promise, resolve };
}
function harness() {
    const server = { gameId: 'outpost-A', revision: 1, rulesVersion: 'v1', cursor: 0, serverNow: 100, points: 0, operations: [] };
    const feed = [];
    const commands = [];
    const cursors = [];
    const timers = new Map();
    let order = 0, timerId = 0;
    const client = {
        async snapshot() { return { ...server }; },
        async events(cursor) {
            cursors.push(cursor);
            const items = feed.filter(e => e.cursor > cursor);
            return { items, cursor: items.at(-1)?.cursor ?? cursor, reset: false, hasMore: false };
        },
        async command(command) { commands.push(command); return { commandId: command.commandId }; },
    };
    const makeStore = () => createStrategyStore({ ...server }, client, {
        interval: 3000, now: () => 500, makeId: () => 'order-' + ++order,
        schedule(fn, ms) { timers.set(++timerId, { fn, ms }); return timerId; },
        cancel(id) { timers.delete(id); },
    });
    const store = makeStore();
    function next() {
        assert.equal(timers.size, 1);
        const [id, task] = [...timers][0];
        timers.delete(id);
        return task.fn();
    }
    async function start() { store.setMode('online'); await next(); }
    return { store, makeStore, start, server, feed, commands, cursors, client, timers, next,
        state: store.getState, delay: () => [...timers.values()][0]?.ms };
}
function denied(status, code) { return Object.assign(new Error(code), { status, code }); }

test('initial and resumed reads validate the session before enabling commands', async () => {
    const h = harness();
    const transitions = [];
    const unsubscribe = h.store.subscribe(() => transitions.push(h.state().connection));
    assert.equal(h.store.enqueue('build.start', { target: 'mine' }), false);
    await h.start();
    assert.equal(h.state().connection, 'online');
    assert.deepEqual(h.cursors, [0]);
    h.store.setMode('offline');
    assert.equal(h.timers.size, 0);
    h.store.setMode('online');
    assert.equal(h.store.enqueue('build.start', { target: 'mine' }), false);
    h.client.snapshot = async () => { throw denied(401, 'expired'); };
    await h.next();
    assert.equal(h.timers.size, 0);
    assert.equal(h.state().snapshot, null);
    assert.deepEqual(h.state().queue, []);
    assert.deepEqual(h.state().events, []);
    assert.deepEqual(transitions, ['connecting', 'online', 'offline', 'connecting', 'stopped', 'revoked']);
    unsubscribe();
});

test('a lost receipt replays exactly the original envelope, even after reconnect and event delivery', async () => {
    const h = harness();
    await h.start();
    const payload = { target: 'mine' };
    h.store.enqueue('build.start', payload);
    assert.equal(h.store.enqueue('build.start', payload), false);
    payload.target = 'reactor';
    const receipts = new Set();
    h.client.command = async command => {
        h.commands.push(command);
        if (receipts.has(command.commandId)) return { commandId: command.commandId };
        receipts.add(command.commandId);
        h.server.cursor = 1; h.server.revision = 2;
        h.feed.push({ cursor: 1, type: 'build.started' });
        throw new TypeError('Connection interrupted after commit');
    };
    await h.next();
    assert.equal(h.state().queue[0].status, 'uncertain');
    assert.equal(h.state().connection, 'reconnecting');
    h.server.rulesVersion = 'v2';
    h.store.setMode('offline'); h.store.setMode('online');
    await h.next();
    assert.deepEqual(h.state().events, h.feed);
    assert.equal(h.commands.length, 1);
    h.store.retry();
    await h.next(); await h.next();
    assert.equal(receipts.size, 1);
    assert.deepEqual(h.commands, Array(2).fill({ commandId: 'order-1', expectedRevision: 1, expectedRulesVersion: 'v1', type: 'build.start', payload: { target: 'mine' } }));
    assert.equal(h.state().queue.length, 0);
    assert.equal(h.state().events.length, 1);
});

test('a conflict keeps the intent and requires explicit review against a fresh snapshot', async () => {
    const h = harness();
    await h.start();
    h.client.command = async command => {
        h.commands.push(command);
        if (command.expectedRevision !== h.server.revision) throw denied(409, 'revision_conflict');
        return { commandId: command.commandId };
    };
    h.server.revision = 5;
    h.store.enqueue('build.start', { target: 'lab' });
    await h.next();
    assert.equal(h.state().queue[0].status, 'conflict');
    h.store.retryReviewed();
    await h.next();
    assert.equal(h.state().queue[0].status, 'conflict');
    assert.equal(h.state().snapshot.revision, 5);
    await h.next();
    assert.equal(h.commands.length, 1);
    h.store.retryReviewed();
    await h.next();
    assert.equal(h.commands.length, 2);
    assert.notEqual(h.commands[0].commandId, h.commands[1].commandId);
    assert.deepEqual(h.commands[1].payload, { target: 'lab' });
    assert.equal(h.commands[1].expectedRevision, 5);
});

test('definitive rule rejection removes the failed order without automatic re-submission', async () => {
    const h = harness();
    await h.start();
    h.store.enqueue('reward.claim', { rewardId: 'one' });
    h.client.command = async () => { throw denied(409, 'reward_unavailable'); };
    await h.next();
    assert.equal(h.state().error, 'reward_unavailable');
    assert.deepEqual(h.state().queue, []);
    await h.next();
    assert.equal(h.state().connection, 'online');
});

test('duplicates are ignored; a cursor gap reloads the snapshot before acknowledging the feed', async () => {
    const h = harness();
    await h.start();
    h.server.cursor = 2; h.server.revision = 3;
    h.client.events = async () => ({ cursor: 2, items: [{ cursor: 1 }, { cursor: 1 }, { cursor: 2 }] });
    await h.next();
    assert.deepEqual(h.state().events, [{ cursor: 1 }, { cursor: 2 }]);
    h.client.events = async () => ({ cursor: 4, items: [{ cursor: 4 }] });
    let reads = 0;
    h.client.snapshot = async () => { ++reads; return { ...h.server, cursor: 4, revision: 5 }; };
    await h.next();
    assert.equal(reads, 2);
    assert.equal(h.state().cursor, 4);
    assert.deepEqual(h.state().events, []);
});

test('a new generation ignores a late response from an interrupted poll', async () => {
    const h = harness();
    const stale = deferred();
    let oldSignal;
    h.client.snapshot = signal => { oldSignal = signal; return stale.promise; };
    h.store.setMode('online');
    const first = h.next();
    h.store.setMode('paused');
    assert.ok(oldSignal.aborted);
    h.store.setMode('online');
    h.client.snapshot = async () => ({ ...h.server, revision: 12 });
    await h.next();
    stale.resolve({ ...h.server, revision: 2 });
    await first;
    assert.equal(h.state().snapshot.revision, 12);
    assert.equal(h.timers.size, 1);
    h.store.setMode('stopped');
    assert.equal(h.timers.size, 0);
});

test('a restored in-flight order waits for the user and reuses its receipt identifier', async () => {
    const h = harness();
    await h.start();
    const records = new Map();
    const storage = { getItem: key => records.get(key), setItem: (key, v) => records.set(key, v), removeItem: key => records.delete(key) };
    h.store.restore(storage);
    const sent = deferred();
    h.client.command = () => sent.promise;
    h.store.enqueue('reward.claim', { rewardId: 'bonus-1' });
    const sending = h.next();
    h.store.setMode('stopped');
    const saved = h.state().queue[0].envelope;
    sent.resolve({ commandId: saved.commandId });
    await sending;
    const restored = h.makeStore();
    restored.restore(storage);
    restored.setMode('online');
    await h.next();
    assert.equal(restored.getState().queue[0].status, 'uncertain');
    h.client.command = async e => { assert.deepEqual(e, saved); return { commandId: e.commandId }; };
    restored.retry(); await h.next();
    assert.equal(restored.getState().queue.length, 0);
    assert.equal(records.size, 0);
});

test('backoff is bounded and deadline estimates cannot finish an operation or award points', async () => {
    const h = harness();
    h.server.operations = [{ target: 'mine', completesAt: 120 }];
    await h.start();
    const snapshot = h.state().snapshot;
    assert.equal(estimatedServerTime(h.state(), 2499), 101);
    assert.equal(estimatedServerTime(h.state(), 88888500), 88988);
    assert.deepEqual(snapshot.operations, [{ target: 'mine', completesAt: 120 }]);
    assert.equal(snapshot.points, 0);
    h.client.events = async () => { throw new Error('Offline'); };
    for (const expected of [6000, 12000, 24000, 30000, 30000]) {
        await h.next();
        assert.equal(h.delay(), expected);
    }
    assert.equal(h.state().snapshot, snapshot);
});

test('HTTP client sends scoped URLs, the current CSRF token, and server error codes', async () => {
    const calls = [];
    let csrfToken = 'first';
    const client = createStrategyClient('a/b', { csrfToken: () => csrfToken, fetcher: async (url, opts) => {
        calls.push({ url, opts });
        return url.endsWith('/commands')
            ? Response.json({ error: 'Stale revision', errors: { code: 'revision_conflict' } }, { status: 409 })
            : Response.json({});
    } });
    const signal = new AbortController().signal;
    await client.snapshot(signal);
    await client.events(9, signal);
    csrfToken = 'second';
    const order = { commandId: 'a', type: 'build.start', expectedRevision: 2, expectedRulesVersion: 'v1', payload: { target: 'lab' } };
    await assert.rejects(client.command(order, signal), e => e.status === 409 && e.code === 'revision_conflict');
    assert.deepEqual(calls.map(c => c.url), ['/api/games/a%2Fb', '/api/games/a%2Fb/events?limit=40&cursor=9', '/api/games/a%2Fb/commands']);
    assert.equal(calls[2].opts.headers['X-CSRF-Token'], 'second');
    assert.deepEqual(JSON.parse(calls[2].opts.body), order);
    assert.equal(calls[2].opts.credentials, 'same-origin');
});

test('an unresponsive HTTP request times out and releases the caller', async () => {
    const client = createStrategyClient('scope', { timeoutMs: 10, fetcher: (url, { signal }) =>
        new Promise((resolve, reject) => signal.addEventListener('abort', () => reject(new Error('Aborted')), { once: true })) });
    await assert.rejects(client.snapshot(new AbortController().signal), /Aborted/);
});

test('a server-confirmed cursor reset accepts the recovered snapshot without sending a pending intent', async () => {
    const h = harness();
    h.server.revision = 20; h.server.cursor = 20;
    h.client.events = async () => ({ items: [], reset: true, cursor: h.server.cursor });
    await h.start();
    h.store.enqueue('build.start', { target: 'mine' });
    h.client.command = async () => { throw new Error('Receipt lost'); };
    await h.next();
    h.server.revision = 3; h.server.cursor = 3;
    await h.next();
    assert.equal(h.state().connection, 'online');
    assert.equal(h.state().snapshot.revision, 3);
    assert.equal(h.state().cursor, 3);
    assert.equal(h.state().queue[0].status, 'uncertain');
    assert.equal(h.state().queue[0].envelope.expectedRevision, 20);
});

test('changed prices with an unchanged game revision require a newly confirmed order', async () => {
    const h = harness();
    await h.start();
    const displayed = h.state().snapshot;
    h.server.rulesVersion = 'v2';
    await h.next();
    assert.equal(h.state().snapshot.rulesVersion, 'v2');
    h.client.command = async order => {
        h.commands.push(order);
        if (order.expectedRulesVersion !== h.server.rulesVersion) throw denied(409, 'rules_conflict');
        return { commandId: order.commandId };
    };
    h.store.enqueue('build.start', { target: 'lab' }, displayed.revision, displayed.rulesVersion);
    await h.next(); await h.next(); await h.next();
    assert.equal(h.commands.length, 1);
    assert.equal(h.commands[0].expectedRulesVersion, 'v1');
    assert.equal(h.state().queue[0].status, 'conflict');
    const reviewed = h.state().snapshot;
    h.store.retryReviewed(reviewed.revision, reviewed.rulesVersion);
    await h.next();
    assert.equal(h.commands.length, 2);
    assert.equal(h.commands[1].expectedRulesVersion, 'v2');
    assert.equal(h.commands[1].expectedRevision, h.commands[0].expectedRevision);
    assert.notEqual(h.commands[0].commandId, h.commands[1].commandId);
    assert.deepEqual(h.state().queue, []);
});

test('a leaderboard response retains its authorization status even without a JSON body', async () => {
    for (const status of [401, 403]) {
        await assert.rejects(responseData(new Response(null, { status })), error => error.status === status);
    }
});
