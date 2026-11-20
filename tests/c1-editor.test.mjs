import test from 'node:test';
import assert from 'node:assert/strict';
import { setTimeout as delay } from 'node:timers/promises';
import { createSceneStore, identityTransform, projectCommand } from '../packages/js/editor/src/store.js';
import { degreesFromQuaternion, quaternionFromDegrees } from '../packages/js/editor/src/document.js';
import { inspectorCommands, inspectorValues } from '../packages/js/editor/src/inspector.js';

const fresh = () => ({ schemaVersion: 1, projectId: 'yard', sceneId: 's1', name: 'Yard', revision: 0,
    coordinateSystem: { handedness: 'right', up: 'Y', unit: 'm' }, entities: [] });
const add = (id, payload = {}) => ({ type: 'entity.add', entityId: id, payload: { type: 'model', ...payload } });
const transform = payload => ({ type: 'entity.transform', entityId: 'a', payload });
const update = payload => ({ type: 'entity.update', entityId: 'a', payload });
const remove = id => ({ type: 'entity.remove', entityId: id, payload: {} });

function fixture(auto = Infinity) {
    let document = fresh(), seq = 0, failAfterCommit = false;
    const sent = [], receipts = new Map();
    const client = {
        load: async () => structuredClone(document),
        async send(command) {
            sent.push(structuredClone(command));
            if (receipts.has(command.commandId)) return receipts.get(command.commandId);
            if (command.expectedRevision !== document.revision) throw Object.assign(new Error('Revision conflict'), {
                status: 409, errors: { code: 'revision_conflict', revision: document.revision, document: structuredClone(document) } });
            const old = document.entities.find(entity => entity.entityId === command.entityId);
            const next = projectCommand(document, command);
            const entity = next.entities.find(entity => entity.entityId === command.entityId);
            let inverse = remove(command.entityId);
            if (old && command.type === 'entity.remove') {
                const { entityId, ...payload } = old;
                inverse = { type: 'entity.add', entityId, payload };
            } else if (old) {
                const fields = command.type === 'entity.transform' ? old.transform : old;
                inverse = { type: command.type, entityId: command.entityId, payload: Object.fromEntries(Object.keys(command.payload).map(key => [key, fields[key]])) };
            }
            document = { ...next, revision: document.revision + 1 };
            const receipt = { commandId: command.commandId, revision: document.revision, inverse,
                changes: { upsert: entity ? [entity] : [], remove: entity ? [] : [command.entityId] } };
            receipts.set(command.commandId, receipt);
            if (failAfterCommit) { failAfterCommit = false; throw new Error('Disconnected after commit'); }
            return receipt;
        },
    };
    const open = () => createSceneStore({ client, initialDocument: structuredClone(document), autosaveMs: auto, makeId: () => `command-${++seq}` });
    return { open, client, sent, loseResponse: () => { failAfterCommit = true; } };
}

test('optimistic commands preserve confirmed state, independent entity transforms and acyclic hierarchy', async () => {
    const f = fixture(), s = f.open();
    s.enqueue(add('a', { assetVersionId: 'v1' }));
    s.enqueue({ type: 'entity.duplicate', entityId: 'b', payload: { sourceId: 'a', name: 'Copy' } });
    s.enqueue(update({ parentId: 'b', visible: false }));
    s.enqueue(transform({ scale: [2, 1, 1] }));
    const before = s.getSnapshot();
    assert.equal(before.confirmed.entities.length, 0);
    const [a, b] = before.document.entities;
    assert.equal(a.parentId, 'b'); assert.equal(a.visible, false); assert.equal(b.assetVersionId, 'v1');
    assert.deepEqual(b.transform, identityTransform());
    assert.throws(() => s.enqueue({ type: 'entity.update', entityId: 'b', payload: { parentId: 'a' } }), /parent/);
    assert.throws(() => s.enqueue(remove('b')), /children/);
    assert.throws(() => s.enqueue(transform({ scale: [0, 1, 1] })), /positive/);
    await s.flush();
    assert.deepEqual(f.sent.map(op => op.expectedRevision), [0, 1, 2, 3]);
    assert.deepEqual(f.open().getSnapshot().document.entities, [a, b]);
});

test('autosave coalesces adjacent unsent transforms and discard cancels a pending save', async () => {
    const f = fixture(10), s = f.open();
    s.enqueue(add('a')); await s.flush();
    const release = s.holdAutosave();
    for (let i = 0; i < 20; i++) s.enqueue(transform({ position: [i, 0, 0] }));
    s.enqueue(transform({ scale: [2, 2, 2] }));
    assert.equal(s.getSnapshot().pendingCount, 1);
    await delay(25); assert.equal(f.sent.length, 1);
    release(); await delay(30);
    assert.equal(f.sent.length, 2);
    assert.deepEqual(f.sent.at(-1).payload, { position: [19, 0, 0], scale: [2, 2, 2] });
    s.enqueue(remove('a')); s.discardPending(); await delay(25);
    assert.equal(f.sent.length, 2); assert.equal(s.getSnapshot().document.entities.length, 1);
});

test('a lost HTTP reply retries the unchanged envelope and undo/redo send new compensating commands', async () => {
    const f = fixture(), s = f.open();
    s.enqueue(add('a')); await s.flush();
    s.enqueue(transform({ position: [1, 2, 3] })); f.loseResponse();
    await assert.rejects(s.flush(), /Disconnected/);
    assert.equal(s.getSnapshot().confirmed.revision, 1);
    assert.equal(s.getSnapshot().status, 'error');
    await s.flush(); assert.deepEqual(f.sent[1], f.sent[2]);
    await s.undo(); assert.deepEqual(s.getSnapshot().document.entities[0].transform.position, [0, 0, 0]);
    await s.redo(); assert.deepEqual(s.getSnapshot().document.entities[0].transform.position, [1, 2, 3]);
    assert.equal(new Set([f.sent[1].commandId, f.sent[3].commandId, f.sent[4].commandId]).size, 3);
    assert.equal(s.getSnapshot().confirmed.revision, 4);
});

test('two tabs stop on 409; explicit rebase applies only pending fields and reload discards them', async () => {
    const f = fixture(), first = f.open(); first.enqueue(add('a')); await first.flush();
    const second = f.open(); first.enqueue(update({ name: 'Renamed by first tab' })); await first.flush();
    second.enqueue(transform({ position: [4, 0, 0] }));
    await assert.rejects(second.flush(), /conflict/);
    assert.equal(second.getSnapshot().status, 'conflict');
    assert.equal(second.getSnapshot().confirmed.revision, 1);
    const stale = f.sent.at(-1); await assert.rejects(second.flush()); assert.equal(f.sent.at(-1), stale);
    await second.retryOnLatest(); assert.notEqual(f.sent.at(-1).commandId, stale.commandId);
    assert.deepEqual(f.open().getSnapshot().document.entities[0].transform.position, [4, 0, 0]);
    assert.equal(second.getSnapshot().document.entities[0].name, 'Renamed by first tab');
    first.enqueue(remove('a')); await assert.rejects(first.flush()); await first.reload();
    assert.equal(first.getSnapshot().pendingCount, 0); assert.equal(first.getSnapshot().document.revision, 3);
    assert.equal(first.getSnapshot().canUndo, false);
});

test('an explicit rebase against a deleted target leaves the conflict and staged changes recoverable', async () => {
    const f = fixture(), first = f.open(); first.enqueue(add('a')); await first.flush();
    const second = f.open(); first.enqueue(remove('a')); await first.flush();
    second.enqueue(transform({ position: [1, 1, 1] })); await assert.rejects(second.flush());
    await assert.rejects(second.retryOnLatest(), /no longer exists/);
    assert.equal(second.getSnapshot().status, 'conflict'); assert.equal(second.getSnapshot().pendingCount, 1);
    await second.reload(); assert.equal(second.getSnapshot().document.entities.length, 0);
});

test('the garage inspector roundtrips cm/mm, preserves properties and serializes rotated axes as a quaternion', () => {
    const doc = projectCommand(fresh(), add('g', { type: 'garage', properties: { heightMm: 2000, finish: 'zinc' } }));
    const g = doc.entities[0];
    const commands = inspectorCommands(g, { height: '213', position1: '2.13', rotation1: '90', scale2: '0.5' });
    const next = commands.reduce(projectCommand, doc).entities[0];
    assert.deepEqual(next.properties, { heightMm: 2130, finish: 'zinc' });
    const v = inspectorValues(next);
    assert.equal(v.height, 213); assert.equal(v.position1, 2.13); assert.equal(v.rotation1, 90); assert.equal(v.scale2, .5);
    assert.throws(() => inspectorCommands(g, { height: '213.05' }), /millimetre/);
    for (const angles of [[28, 75, -33], [65, 90, -5], [-10, -90, 30]]) {
        const q = quaternionFromDegrees(angles), r = quaternionFromDegrees(degreesFromQuaternion(q));
        assert.ok(Math.abs(Math.abs(q.reduce((sum, value, i) => sum + value*r[i], 0))-1) < 1e-10);
    }
});

test('concurrent history requests consume separate confirmed inverses', async () => {
    const f = fixture(), s = f.open();
    s.enqueue(add('a')); s.enqueue(update({ visible: false })); await s.flush();
    await Promise.all([s.undo(), s.undo()]);
    assert.equal(s.getSnapshot().document.entities.length, 0);
    await Promise.all([s.redo(), s.redo()]);
    assert.equal(s.getSnapshot().document.entities[0].visible, false);
    assert.equal(s.getSnapshot().confirmed.revision, 6);
});

test('invalid UTF-8 names, transform limits, property shapes and millimetres never enter the queue', () => {
    const s = fixture().open();
    for (const name of ['x'.repeat(161), 'ż'.repeat(81), 'line\nbreak', '\ud800']) assert.throws(() => s.enqueue(add('a', { name })), /name/);
    for (const properties of [{ heightMm: 10000001 }, { heightMm: 1.5 }, { fraction: Infinity }, { nested: {} }]) {
        assert.throws(() => s.enqueue(add('a', { properties })));
    }
    assert.equal(s.getSnapshot().pendingCount, 0);
    s.enqueue(add('a', { name: 'ż'.repeat(80) }));
    for (const values of [{ position: [0, 1000001, 0] }, { scale: [1, 1e-8, 1] }, { rotation: [0, 0, 0, 0] }]) {
        assert.throws(() => s.enqueue(transform(values)));
    }
    assert.equal(s.getSnapshot().pendingCount, 1);
    s.enqueue(transform({ rotation: [0, 0, 0, 2] }));
    assert.deepEqual(s.getSnapshot().document.entities[0].transform.rotation, [0, 0, 0, 1]);
});

test('discarding a definitive rejection keeps independent queued edits', async () => {
    const f = fixture(), s = f.open(), send = f.client.send;
    f.client.send = async envelope => {
        if (envelope.entityId === 'missing-version') throw Object.assign(new Error('Asset unavailable'), { status: 422 });
        return send(envelope);
    };
    s.enqueue(add('missing-version', { assetVersionId: 'deleted' }));
    s.enqueue(add('child', { parentId: 'missing-version' }));
    s.enqueue(add('other'));
    await assert.rejects(s.flush(), /unavailable/);
    assert.equal(s.getSnapshot().rejectedCount, 2);
    s.discardRejected(); assert.equal(s.getSnapshot().pendingCount, 1); await s.flush();
    assert.deepEqual(s.getSnapshot().document.entities.map(item => item.entityId), ['other']);
});

const event = receipt => ({ type: 'scene.changed', projectId: 'yard', sceneId: 's1', ...receipt });

test('own event before HTTP receipt and duplicate delivery do not advance history or revision twice', async () => {
    const f = fixture(), s = f.open();
    let respond, notify;
    const reply = new Promise(resolve => { respond = resolve; });
    const committed = new Promise(resolve => { notify = resolve; });
    const send = f.client.send;
    f.client.send = async envelope => { const receipt = await send(envelope); notify(receipt); await reply; return receipt; };
    s.enqueue(add('a')); const saving = s.flush();
    const receipt = await committed;
    await s.receiveEvent(event(receipt)); assert.equal(s.getSnapshot().confirmed.revision, 0);
    assert.equal(s.getSnapshot().document.entities.length, 1);
    respond(); await saving; await s.receiveEvent(event(receipt));
    assert.equal(s.getSnapshot().confirmed.revision, 1); assert.equal(s.getSnapshot().pendingCount, 0);
    await s.undo(); assert.equal(s.getSnapshot().confirmed.revision, 2); assert.equal(s.getSnapshot().canUndo, false);
});

test('revision gaps fetch a scoped snapshot; overlapping foreign revisions preserve pending edits as a conflict', async () => {
    const f = fixture(), clean = f.open(), dirty = f.open(); let loads = 0;
    const load = f.client.load;
    f.client.load = async signal => { loads++; return load(signal); };
    dirty.enqueue(add('local'));
    const first = await f.client.send({ ...add('a'), expectedRevision: 0, commandId: 'remote-1' });
    const second = await f.client.send({ ...update({ name: 'Second tab' }), expectedRevision: 1, commandId: 'remote-2' });
    await clean.receiveEvent({ ...event(second), sceneId: 'foreign' }); assert.equal(loads, 0);
    await clean.receiveEvent(event(second)); assert.equal(clean.getSnapshot().document.revision, 2);
    await clean.receiveEvent(event(first)); assert.equal(loads, 1);
    await dirty.receiveEvent(event(second)); assert.equal(loads, 2);
    assert.equal(dirty.getSnapshot().status, 'conflict'); assert.equal(dirty.getSnapshot().confirmed.revision, 0);
    assert.deepEqual(dirty.getSnapshot().document.entities.map(item => item.entityId), ['local']);
    await dirty.retryOnLatest(); assert.deepEqual(dirty.getSnapshot().document.entities.map(item => item.entityId), ['a', 'local']);
});

test('a foreign event after a lost reply preserves retry of the original command ID', async () => {
    const f = fixture(), s = f.open();
    s.enqueue(add('a')); f.loseResponse(); await assert.rejects(s.flush());
    const firstSend = f.sent[0];
    const foreign = await f.client.send({ ...update({ visible: false }), expectedRevision: 1, commandId: 'outside' });
    await s.receiveEvent(event(foreign));
    assert.equal(s.getSnapshot().status, 'error');
    await s.flush(); assert.deepEqual(f.sent.at(-1), firstSend);
    assert.equal(s.getSnapshot().document.revision, 2); assert.equal(s.getSnapshot().document.entities[0].visible, false);
});

test('interleaved undo and redo entered together respect invocation order', async () => {
    const f = fixture(), s = f.open(); s.enqueue(add('a')); await s.flush();
    await Promise.all([s.undo(), s.redo(), s.undo()]);
    assert.equal(s.getSnapshot().document.revision, 4);
    assert.equal(s.getSnapshot().document.entities.length, 0); assert.equal(s.getSnapshot().canRedo, true);
});

test('leave dialog holds an autosave after its in-flight head; discard never sends the queued tail', async () => {
    const f = fixture(2), s = f.open();
    const send = f.client.send;
    let started, permit;
    const sending = new Promise(resolve => { started = resolve; });
    const response = new Promise(resolve => { permit = resolve; });
    f.client.send = async envelope => { started(); await response; return send(envelope); };
    s.enqueue(add('a')); s.enqueue(add('b')); await sending;
    const release = s.holdAutosave(); permit(); await delay(10);
    assert.equal(s.getSnapshot().confirmed.revision, 1); assert.equal(s.getSnapshot().pendingCount, 1);
    assert.deepEqual(f.sent.map(item => item.entityId), ['a']);
    s.discardPending(); release(); await delay(10);
    assert.deepEqual(f.sent.map(item => item.entityId), ['a']);
});

test('an explicit save upgrades a held in-flight autosave and resolves only after every command is acknowledged', async () => {
    const f = fixture(2), s = f.open();
    const send = f.client.send;
    let started, permit;
    const sending = new Promise(resolve => { started = resolve; });
    const response = new Promise(resolve => { permit = resolve; });
    f.client.send = async envelope => { started(); await response; return send(envelope); };
    s.enqueue(add('a')); s.enqueue(add('b')); await sending;
    const release = s.holdAutosave(), saved = s.flush(); permit(); await saved;
    assert.equal(s.getSnapshot().confirmed.revision, 2); assert.equal(s.getSnapshot().pendingCount, 0);
    release();
});
