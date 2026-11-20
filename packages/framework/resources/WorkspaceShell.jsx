import { createContext, useContext, useEffect, useMemo, useRef } from 'react';

import { registerLeaveGuard } from './navigation.mjs';

const Workspace = createContext(null);

export function useWorkspace() {
    const scope = useContext(Workspace);
    if (!scope) throw new Error('useWorkspace requires a WorkspaceShell.');
    return scope;
}

export function WorkspaceShell({ viewport, children, ...attributes }) {
    // A workspace key belongs on this component. Each new key owns a separate lifetime.
    const scope = useMemo(() => ({ state: new Map(), controller: null,
        get signal() {
            if (typeof AbortController === 'undefined') return undefined;
            if (!this.controller || this.controller.signal.aborted) this.controller = new AbortController();
            return this.controller.signal;
        } }), []);
    useEffect(() => {
        if (scope.controller?.signal.aborted) scope.controller = null;
        return () => { scope.controller?.abort(); scope.state.clear(); };
    }, [scope]);
    return <Workspace.Provider value={scope}><div {...attributes} data-yg-workspace="">
        {viewport}{children}
    </div></Workspace.Provider>;
}

export function useWorkspaceLeaveGuard({ dirty, contains, choose, save }) {
    const latest = useRef({});
    latest.current = { contains, choose, save };
    useEffect(() => {
        if (!dirty) return;
        const unregister = registerLeaveGuard(async (data, signal) => {
            const guard = latest.current;
            if (guard.contains?.(data)) return true;
            const decision = await guard.choose({ data, signal });
            if (signal.aborted) return false;
            if (decision === 'save') {
                if (!guard.save) throw new Error('Saving this workspace requires a save handler.');
                await guard.save({ signal });
                return signal.aborted ? false : 'refresh';
            }
            return decision === 'discard';
        });
        const beforeUnload = event => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', beforeUnload);
        return () => { unregister(); window.removeEventListener('beforeunload', beforeUnload); };
    }, [dirty]);
}
