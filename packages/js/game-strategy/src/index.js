import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { createStrategyClient, createStrategyStore } from './store.js';
export { createStrategyClient, createStrategyStore, estimatedServerTime } from './store.js';

// Mount in a layout keyed by gameId to share one connection across its panels.
export function useStrategyGame(initial, csrfToken) {
    const token = useRef(csrfToken);
    token.current = csrfToken;
    const [store] = useState(() => createStrategyStore(initial,
        createStrategyClient(initial.gameId, { csrfToken: () => token.current })));
    const state = useSyncExternalStore(store.subscribe, store.getState, store.getState);
    useEffect(() => {
        try { store.restore(window.sessionStorage); } catch { /* Private browsing can block storage access. */ }
        function connection() {
            store.setMode(navigator.onLine === false ? 'offline' : document.hidden ? 'paused' : 'online');
        }
        connection();
        window.addEventListener('online', connection);
        window.addEventListener('offline', connection);
        document.addEventListener('visibilitychange', connection);
        return () => {
            window.removeEventListener('online', connection);
            window.removeEventListener('offline', connection);
            document.removeEventListener('visibilitychange', connection);
            store.setMode('stopped');
        };
    }, [store]);
    return [state, store];
}
