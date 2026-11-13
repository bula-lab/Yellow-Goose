import test from 'node:test';
import assert from 'node:assert/strict';
import { installNavigation } from '../packages/framework/resources/navigation.mjs';

function environment(fetch) {
    const win = new EventTarget();
    const doc = new EventTarget();
    const assigned = [];
    const renders = [];
    const entries = [];
    const status = { hidden: true, attributes: {}, setAttribute(k, v) { this.attributes[k] = v; },
        getAttribute(k) { return this.attributes[k]; }, remove() {} };
    const heading = { setAttribute() {}, focus() {} };
    Object.assign(doc, { body: { append() {} }, documentElement: { dataset: {} },
        createElement: () => status, querySelector: () => heading, getElementById: () => null });
    const location = new URL('https://example.test/');
    location.assign = href => assigned.push(href);
    const history = { state: null, scrollRestoration: 'auto',
        replaceState(state, _, href) { this.state = state; location.href = new URL(href, location).href; },
        pushState(state, _, href) { this.replaceState(state, _, href); entries.push(href); } };
    Object.assign(win, { document: doc, location, history, fetch, scrollX: 0, scrollY: 0, scrollTo() {} });
    const router = installNavigation({ page: 'index.jsx', props: {}, url: '/', version: 'v1' },
        async data => { renders.push(data); }, win);
    return { win, doc, status, router, assigned, renders, entries };
}
const response = (data, status = 200) => new Response(JSON.stringify(data), {
    status, headers: { 'X-YG-Navigation': '1', 'Content-Type': 'application/json' },
});
const page = url => ({ page: 'index.jsx', props: {}, url, version: 'v1', head: { title: 'Updated' } });

test('navigation sends protocol headers and commits history after rendering', async () => {
    let request;
    const env = environment(async (url, options) => { request = { url, options }; return response(page('/next?q=1')); });
    try {
        await env.router.visit('/next?q=1');
        assert.equal(request.options.headers['X-YG-Version'], 'v1');
        assert.equal(request.options.credentials, 'same-origin');
        assert.equal(env.renders[0].url, '/next?q=1');
        assert.deepEqual(env.entries, ['/next?q=1']);
        assert.equal(env.doc.title, 'Updated');
        assert.equal(env.status.hidden, true);
    } finally { env.router.dispose(); }
});

test('a slower superseded request cannot overwrite the latest page', async () => {
    const pending = [];
    const env = environment((url, options) => new Promise(resolve => pending.push({ url, options, resolve })));
    try {
        const slow = env.router.visit('/slow');
        const fast = env.router.visit('/fast');
        assert.equal(pending[0].options.signal.aborted, true);
        pending[1].resolve(response(page('/fast')));
        await fast;
        pending[0].resolve(response(page('/slow')));
        await slow;
        assert.deepEqual(env.renders.map(data => data.url), ['/fast']);
        assert.deepEqual(env.entries, ['/fast']);
    } finally { env.router.dispose(); }
});

test('network failure preserves the current URL and reports a retryable error', async () => {
    const env = environment(async () => { throw new TypeError('offline'); });
    try {
        await assert.rejects(env.router.visit('/unavailable'), /offline/);
        assert.equal(env.win.location.pathname, '/');
        assert.equal(env.renders.length, 0);
        assert.equal(env.status.getAttribute('role'), 'alert');
        assert.equal(env.status.hidden, false);
    } finally { env.router.dispose(); }
});

test('new build triggers document navigation without mounting stale components', async () => {
    const env = environment(async () => response({ location: '/next', reload: true }, 409));
    try {
        await env.router.visit('/next');
        assert.deepEqual(env.assigned, ['https://example.test/next']);
        assert.equal(env.renders.length, 0);
    } finally { env.router.dispose(); }
});

test('loader redirects perform another navigation and preserve one history entry', async () => {
    const env = environment(async url => new URL(url).pathname === '/redirect'
        ? response({ location: '/destination' }, 409) : response(page('/destination')));
    try {
        await env.router.visit('/redirect');
        assert.deepEqual(env.entries, ['/destination']);
        assert.deepEqual(env.renders.map(data => data.url), ['/destination']);
    } finally { env.router.dispose(); }
});

test('HTTP 404 is rendered in the existing document with the requested URL', async () => {
    const env = environment(async () => response({ error: 'Missing' }, 404));
    try {
        await env.router.visit('/missing');
        assert.equal(env.renders[0].status, 404);
        assert.equal(env.renders[0].error, 'Missing');
        assert.equal(env.win.location.pathname, '/missing');
    } finally { env.router.dispose(); }
});

test('popstate refreshes server props without pushing another history entry', async () => {
    const env = environment(async url => response(page(new URL(url).pathname)));
    try {
        await env.router.visit('/next');
        await env.router.visit('/', { pop: true, scroll: { x: 0, y: 80 } });
        assert.deepEqual(env.entries, ['/next']);
        assert.equal(env.renders.at(-1).url, '/');
    } finally { env.router.dispose(); }
});

test('canonical server URL is used for browser history', async () => {
    const env = environment(async () => response(page('/canonical/')));
    try {
        await env.router.visit('/canonical');
        assert.deepEqual(env.entries, ['/canonical/']);
    } finally { env.router.dispose(); }
});

test('redirect loops fail visibly and stop requesting pages', async () => {
    let calls = 0;
    const env = environment(async () => { calls++; return response({ location: '/loop' }, 409); });
    try {
        await assert.rejects(env.router.visit('/loop'), /przekierowań/);
        assert.equal(calls, 6);
        assert.equal(env.status.getAttribute('role'), 'alert');
    } finally { env.router.dispose(); }
});

test('redirect protocol cannot execute javascript URLs', async () => {
    const env = environment(async () => response({ location: 'javascript:alert(1)', reload: true }, 409));
    try {
        await assert.rejects(env.router.visit('/redirect'), /Invalid redirect/);
        assert.deepEqual(env.assigned, []);
        assert.equal(env.status.getAttribute('role'), 'alert');
    } finally { env.router.dispose(); }
});
