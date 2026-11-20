import test from 'node:test';
import assert from 'node:assert/strict';
import { createSceneClient } from '../packages/js/editor/src/store.js';
import { modelBytes } from '../packages/js/babylon/src/modelBytes.js';

function page(t) {
    const original = Object.getOwnPropertyDescriptor(globalThis, 'location');
    Object.defineProperty(globalThis, 'location', { configurable: true, value: new URL('http://studio.test/project/yard') });
    t.after(() => original ? Object.defineProperty(globalThis, 'location', original) : delete globalThis.location);
}

test('scene HTTP client keeps CSRF on the host and exposes revision conflict fields', async t => {
    page(t);
    const calls = [];
    const fetcher = async (url, options) => {
        calls.push({ url, options });
        return Response.json({ error: 'New revision', errors: { code: 'revision_conflict', revision: 5 } }, { status: 409 });
    };
    const client = createSceneClient({ projectId: 'yard', sceneId: 'shed', csrfToken: 'fixture', fetcher });
    await assert.rejects(client.send({ commandId: 'one' }), error => error.status === 409 && error.errors.revision === 5);
    assert.equal(calls[0].url.pathname, '/api/projects/yard/scenes/shed/commands');
    assert.equal(calls[0].options.headers['X-CSRF-Token'], 'fixture');
    assert.equal(calls[0].options.credentials, 'same-origin');
    assert.equal(calls[0].options.redirect, 'error');
    const other = createSceneClient({ url: 'https://elsewhere.test/scene', csrfToken: 'fixture', fetcher });
    await assert.rejects(other.send({}), /same-origin/); assert.equal(calls.length, 1);
});

test('an expired or revoked model URL renews the descriptor for the same project and version once', async t => {
    page(t);
    const descriptor = { assetVersionId: 'v', projectId: 'p', status: 'ready', modelUrl: '/old?exp=1' };
    const requests = [], renewals = [];
    const signal = new AbortController().signal;
    t.mock.method(globalThis, 'fetch', async (url, options) => {
        requests.push(String(url)); assert.equal(options.signal, signal);
        return String(url).includes('old') ? new Response(null, { status: 403 }) : new Response(new Uint8Array([7]));
    });
    async function resolve(projectId, version, requestSignal) {
        renewals.push([projectId, version]); assert.equal(requestSignal, signal);
        return { ...descriptor, modelUrl: '/renewed' };
    }
    assert.deepEqual([...await modelBytes('p', 'v', descriptor, resolve, signal)], [7]);
    assert.equal(requests.length, 1); assert.match(requests[0], /renewed$/);
    await modelBytes('p', 'v', { ...descriptor, modelUrl: '/old' }, resolve, signal);
    assert.equal(requests.length, 3); assert.deepEqual(renewals, [['p', 'v'], ['p', 'v']]);
    await assert.rejects(modelBytes('p', 'v', null, async () => ({ ...descriptor, projectId: 'other' }), signal), /unavailable/);
    assert.equal(requests.length, 3);
});
