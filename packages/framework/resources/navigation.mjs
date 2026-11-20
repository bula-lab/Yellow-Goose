let activeRouter;
const leaveGuards = new Set();

export function registerLeaveGuard(guard) {
    leaveGuards.add(guard);
    return () => leaveGuards.delete(guard);
}

export function navigate(url, options = {}) {
    if (activeRouter) return activeRouter.visit(url, options);
    window.location.assign(url);
    return Promise.resolve();
}

export function installNavigation(initial, render, win = window) {
    activeRouter?.dispose();
    const doc = win.document;
    const history = win.history;
    const previousRestoration = history.scrollRestoration;
    history.scrollRestoration = 'manual';
    let current = initial;
    let pending;
    let sequence = 0;
    let modalOpener;
    let index = Number.isSafeInteger(history.state?.__gaska?.index) ? history.state.__gaska.index : 0;
    let committedHref = win.location.href;
    let restoring = false;
    const status = doc.createElement('div');
    status.className = 'navigation-status';
    status.setAttribute('role', 'status');
    status.hidden = true;
    doc.body.append(status);
    const relative = url => url.pathname + url.search + url.hash;
    const storeScroll = () => history.replaceState({ ...history.state,
        __gaska: { index, x: win.scrollX, y: win.scrollY } }, '', win.location.href);
    storeScroll();

    function restorePop(targetIndex) {
        if (Number.isSafeInteger(targetIndex) && targetIndex !== index) {
            restoring = true;
            history.go(index - targetIndex);
        } else {
            history.replaceState({ ...history.state, __gaska: { index, x: win.scrollX, y: win.scrollY } }, '', committedHref);
        }
    }

    async function visit(href, { replace = false, pop = false, scroll, targetIndex, redirects = 0 } = {}, guardsPassed = false) {
        const url = new URL(href, win.location.href);
        if (!['http:', 'https:'].includes(url.protocol)) throw new Error('Invalid navigation destination.');
        if (url.origin !== win.location.origin) {
            win.location.assign(url.href);
            return;
        }
        const id = ++sequence;
        pending?.abort();
        const controller = pending = new AbortController();
        if (!pop) storeScroll();
        status.setAttribute('role', 'status');
        status.textContent = 'Ładowanie strony…';
        status.hidden = false;
        doc.documentElement.dataset.navigation = 'loading';
        try {
            if (redirects > 5) throw new Error('Zbyt wiele przekierowań.');
            const response = await win.fetch(url.href, {
                credentials: 'same-origin', signal: controller.signal,
                headers: { Accept: 'application/json', 'X-YG-Navigation': '1', 'X-YG-Version': current.version },
            });
            if (id !== sequence) return;
            if (response.headers.get('X-YG-Navigation') !== '1') {
                win.location.assign(response.url || url.href);
                return;
            }
            const data = await response.json();
            if (id !== sequence) return;
            if (response.status === 409 && typeof data.location === 'string') {
                const target = new URL(data.location, url);
                if (!['http:', 'https:'].includes(target.protocol)) throw new Error('Invalid redirect destination.');
                if (data.reload || target.origin !== win.location.origin) {
                    win.location.assign(target.href);
                    return;
                }
                return visit(target.href, { replace, pop, scroll, targetIndex, redirects: redirects + 1 }, guardsPassed);
            }
            if (data.error) {
                Object.assign(data, { status: response.status, url: relative(url), version: current.version,
                    head: { title: String(response.status) } });
            } else if (!response.ok || typeof data.page !== 'string' || !data.props
                || typeof data.version !== 'string' || typeof data.url !== 'string') {
                throw new Error('Nieprawidłowa odpowiedź nawigacji.');
            }
            if (data.version !== current.version) {
                win.location.assign(url.href);
                return;
            }
            const destination = new URL(data.url, url);
            if (destination.origin !== win.location.origin) throw new Error('Invalid navigation destination.');
            destination.hash ||= url.hash;
            if (!guardsPassed) {
                let refresh = false;
                for (const guard of [...leaveGuards]) {
                    const decision = await guard(data, controller.signal);
                    if (id !== sequence) return;
                    if (!decision) { if (pop) restorePop(targetIndex); return; }
                    if (decision === 'refresh') refresh = true;
                }
                // A successful save can change the destination loader (e.g. its catalogue rows).
                if (refresh) return visit(url.href, { replace, pop, scroll, targetIndex, redirects }, true);
            }
            await render(data, controller.signal);
            if (id !== sequence) return;
            if (pop) index = Number.isSafeInteger(targetIndex) ? targetIndex : index;
            else {
                if (!replace) ++index;
                history[replace ? 'replaceState' : 'pushState']({ __gaska: { index, x: 0, y: 0 } }, '', relative(destination));
            }
            committedHref = destination.href;
            current = data;
            applyHead(doc, data.head, destination);
            const dialogOpen = doc.querySelector('dialog[open]')?.open;
            let restored = false;
            if (!dialogOpen && modalOpener) {
                const target = modalOpener.id ? doc.getElementById(modalOpener.id)
                    : [...doc.querySelectorAll('a[href]')].find(link => link.href === modalOpener.href);
                if (target) { target.focus({ preventScroll: true }); restored = true; }
                modalOpener = null;
            }
            const heading = doc.querySelector('main h1, h1, main');
            if (!dialogOpen && !restored && heading) {
                heading.setAttribute('tabindex', '-1');
                heading.focus({ preventScroll: true });
            }
            if (scroll) win.scrollTo(scroll.x, scroll.y);
            else if (destination.hash) {
                let anchor;
                try { anchor = doc.getElementById(decodeURIComponent(destination.hash.slice(1))); } catch { /* Invalid fragment. */ }
                if (anchor) anchor.scrollIntoView();
                else win.scrollTo(0, 0);
            } else win.scrollTo(0, 0);
            storeScroll();
        } catch (error) {
            if (controller.signal.aborted || id !== sequence) return;
            if (pop) restorePop(targetIndex);
            status.setAttribute('role', 'alert');
            status.textContent = 'Nie udało się wczytać strony. Sprawdź połączenie i spróbuj ponownie.';
            throw error;
        } finally {
            if (id === sequence) {
                delete doc.documentElement.dataset.navigation;
                if (status.getAttribute('role') !== 'alert') status.hidden = true;
            }
        }
    }

    function click(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const anchor = event.target.closest?.('a[href]');
        if (!anchor || anchor.hasAttribute('download') || anchor.hasAttribute('data-yg-reload')
            || (anchor.target && anchor.target !== '_self') || anchor.relList.contains('external')) return;
        const url = new URL(anchor.href, win.location.href);
        if (url.origin !== win.location.origin || !['http:', 'https:'].includes(url.protocol)
            || /^\/api(?:\/|$)/.test(url.pathname)) return;
        if (url.pathname === win.location.pathname && url.search === win.location.search && url.hash) return;
        event.preventDefault();
        if (!doc.querySelector('dialog[open]')?.open && anchor.getAttribute('aria-haspopup') === 'dialog') {
            modalOpener = { id: anchor.id, href: anchor.href };
        }
        void visit(url.href).catch(() => {});
    }

    function popstate(event) {
        const targetIndex = event.state?.__gaska?.index;
        if (restoring) {
            restoring = false;
            if (targetIndex === index) return;
        }
        const url = new URL(win.location.href);
        const rendered = new URL(current.url, url.origin);
        if (url.pathname === rendered.pathname && url.search === rendered.search && url.hash) {
            try { doc.getElementById(decodeURIComponent(url.hash.slice(1)))?.scrollIntoView(); } catch { /* Invalid fragment. */ }
            return;
        }
        void visit(url.href, { pop: true, scroll: event.state?.__gaska, targetIndex }).catch(() => {});
    }

    doc.addEventListener('click', click);
    win.addEventListener('popstate', popstate);
    const router = { visit, dispose() {
        pending?.abort();
        doc.removeEventListener('click', click);
        win.removeEventListener('popstate', popstate);
        history.scrollRestoration = previousRestoration;
        status.remove();
        if (activeRouter === router) activeRouter = undefined;
    } };
    activeRouter = router;
    return router;
}

function applyHead(doc, head, destination) {
    if (typeof head?.title === 'string') doc.title = head.title;
    if (!doc.head) return;
    function setTag(selector, tag, attributes, value, content) {
        let node = doc.head.querySelector(selector);
        if (!value) { node?.remove(); return; }
        if (!node) {
            node = doc.createElement(tag);
            for (const [name, val] of Object.entries(attributes)) node.setAttribute(name, val);
            doc.head.append(node);
        }
        node.setAttribute(content, value);
    }
    setTag('meta[name="description"]', 'meta', { name: 'description' },
        typeof head?.description === 'string' ? head.description : null, 'content');
    let canonical = null;
    if (typeof head?.canonical === 'string' && /^(https?:\/\/|\/(?![\/\\]))/i.test(head.canonical)
        && !/[\s\x00-\x1f\x7f\\]/.test(head.canonical)) {
        try {
            const url = new URL(head.canonical, destination);
            if (['http:', 'https:'].includes(url.protocol) && !url.username && !url.password) canonical = head.canonical;
        } catch { /* An invalid canonical is omitted. */ }
    }
    setTag('link[rel="canonical"]', 'link', { rel: 'canonical' }, canonical, 'href');
}
