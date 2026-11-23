export function createStrategyClient(gameId, { csrfToken, timeoutMs = 12000, fetcher = (...args) => fetch(...args) } = {}) {
    const path = '/api/games/' + encodeURIComponent(gameId);
    async function request(url, signal, body) {
        const options = { credentials: 'same-origin', cache: 'no-store', signal, headers: { Accept: 'application/json' } };
        if (body) {
            options.method = 'POST';
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = typeof csrfToken === 'function' ? csrfToken() : csrfToken;
            options.body = JSON.stringify(body);
        }
        const controller = new AbortController();
        const abort = () => controller.abort();
        if (signal?.aborted) abort();
        else signal?.addEventListener('abort', abort, { once: true });
        const timeout = setTimeout(abort, timeoutMs);
        options.signal = controller.signal;
        try {
            const response = await fetcher(url, options);
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                const error = new Error(data.error || 'The server could not complete this request.');
                error.status = response.status;
                error.code = data.errors?.code;
                throw error;
            }
            return await response.json();
        } finally {
            clearTimeout(timeout);
            signal?.removeEventListener('abort', abort);
        }
    }
    return {
        snapshot: signal => request(path, signal),
        events: (cursor, signal) => request(path + '/events?limit=40&cursor=' + cursor, signal),
        command: (envelope, signal) => request(path + '/commands', signal, envelope),
    };
}
