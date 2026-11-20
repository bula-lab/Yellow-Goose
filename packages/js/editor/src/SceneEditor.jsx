import { useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import { useWorkspaceLeaveGuard } from '@yellow-goose/react/workspace';
import { SceneViewport } from '@yellow-goose/scene/multi';
import { Dialog } from '@yellow-goose/ui/dialog';
import { projectCommand, validParent } from './document.js';
import { fields, inspectorCommands, inspectorValues } from './inspector.js';
import { createSceneClient, createSceneStore, newId } from './store.js';
export { createSceneClient, createSceneStore } from './store.js';

async function resolveSceneAsset(projectId, assetVersionId, signal) {
    const path = `/api/assets/projects/${encodeURIComponent(projectId)}/versions/${encodeURIComponent(assetVersionId)}`;
    const response = await fetch(path, { credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal });
    if (!response.ok) throw new Error('Model version unavailable.');
    return response.json();
}

const noAssets = [];
export function SceneEditor({ initialDocument, assets = noAssets, csrfToken, client, url, autosaveMs, Viewport = SceneViewport, loadAdapter, resolveAsset = resolveSceneAsset, viewportRef, contains, readOnly = false }) {
    const [store] = useState(() => createSceneStore({ initialDocument, autosaveMs,
        client: client ?? createSceneClient({ ...initialDocument, url, csrfToken }) }));
    useEffect(() => { store.activate(); return () => store.dispose(); }, [store]);
    const state = useSyncExternalStore(store.subscribe, store.getSnapshot, store.getSnapshot);
    const [selection, setSelection] = useState(null), [edits, setEdits] = useState(null);
    const [assetId, setAssetId] = useState(() => assets.find(asset => asset.status === 'ready')?.assetVersionId ?? '');
    const [tool, setTool] = useState('translate'), [snap, setSnap] = useState(false);
    const [message, setMessage] = useState(''), [leave, setLeave] = useState(null);
    const port = useRef(null);
    const setPort = useMemo(() => value => {
        port.current = value;
        if (typeof viewportRef === 'function') viewportRef(value);
        else if (viewportRef) viewportRef.current = value;
    }, [viewportRef]);
    const entity = state.document.entities.find(item => item.entityId === selection);
    const draft = entity && edits?.id === entity.entityId ? edits.fields : null;
    const locked = readOnly || Boolean(state.error || state.conflict || state.resolving);
    const values = entity ? { ...inspectorValues(entity), ...draft } : null;
    const preview = useMemo(() => {
        if (!draft) return state.document;
        try { return inspectorCommands(entity, draft).reduce(projectCommand, state.document); }
        catch { return state.document; }
    }, [entity, draft, state.document]);

    function edit(key, value) { setEdits({ id: selection, fields: { ...draft, [key]: value } }); setMessage(''); }
    function apply() {
        if (!draft) return;
        const commands = inspectorCommands(entity, draft);
        commands.reduce(projectCommand, state.document);
        for (const command of commands) store.enqueue(command);
        setEdits(null);
    }
    async function act(callback) {
        try { setMessage(''); await callback(); }
        catch (error) { setMessage(error.message); }
    }
    function select(id) { if (id !== selection) { apply(); setSelection(id); } }
    function run(type, entityId, payload) { apply(); store.enqueue({ type, entityId, payload }); }
    function add(type) {
        const id = newId(), asset = assets.find(item => item.assetVersionId === assetId);
        run('entity.add', id, { type, name: type === 'garage' ? 'Garage' : (asset?.name ?? 'Model'),
            assetVersionId: type === 'garage' ? null : assetId, properties: type === 'garage' ? { heightMm: 2000 } : {} });
        setSelection(id);
    }
    function save() { apply(); return store.flush(); }
    function choose({ signal }) {
        if (signal.aborted) return Promise.resolve('cancel');
        const release = store.holdAutosave();
        return new Promise(resolve => {
            const abort = () => decide('cancel');
            function decide(choice) {
                signal.removeEventListener('abort', abort);
                setLeave(null);
                if (choice === 'discard') { store.discardPending(); setEdits(null); }
                release();
                resolve(choice);
            }
            signal.addEventListener('abort', abort, { once: true });
            setLeave({ decide });
        });
    }
    useWorkspaceLeaveGuard({ dirty: Boolean(draft) || state.dirty, contains, choose, save });
    const status = state.status === 'saved' && draft ? 'pending' : state.status;
    return <section data-yg-scene-editor="" data-revision={state.confirmed.revision} data-status={status} aria-label="Scene editor">
        <div role="toolbar" aria-label="Scene commands">
            <button type="button" disabled={locked || (!state.canUndo && !draft)} onClick={() => act(() => { apply(); return store.undo(); })}>Undo</button>
            <button type="button" disabled={locked || !state.canRedo || !!draft} onClick={() => act(store.redo)}>Redo</button>
            <button type="button" disabled={locked || (!state.dirty && !draft)} onClick={() => act(save)}>Save</button>
            <output aria-live="polite">Revision {state.confirmed.revision} · {status}</output>
        </div>
        {state.conflict && <div role="alert">Another editor saved revision {state.conflict.revision}. Your changes are pending.
            <button type="button" disabled={state.resolving} onClick={() => act(async () => { await store.reload(); setEdits(null); })}>Reload server version</button>
            <button type="button" disabled={state.resolving} onClick={() => act(store.retryOnLatest)}>Retry on latest revision</button></div>}
        {state.error && !state.conflict && <div role="alert">{state.error}
            {state.rejectedCount > 0 && <button type="button" disabled={state.resolving} onClick={() => act(store.discardRejected)}>
                {state.rejectedCount === 1 ? 'Discard rejected edit' : `Discard rejected edit and ${state.rejectedCount - 1} dependent edits`}</button>}
            <button type="button" disabled={state.resolving} onClick={() => act(store.flush)}>Retry save</button>
            <button type="button" disabled={state.resolving} onClick={() => act(async () => { await store.reload(); setEdits(null); })}>Reload server version</button></div>}
        {message && message !== state.error && <p role="alert">{message}</p>}
        <div className="scene-editor-layout"><section aria-label="Scene objects">
            <h2>Objects</h2>
            <label>Asset version<select disabled={readOnly} value={assetId} onChange={event => setAssetId(event.target.value)}>
                <option value="">Choose an asset</option>{assets.filter(asset => asset.status === 'ready').map(asset =>
                    <option key={asset.assetVersionId} value={asset.assetVersionId}>{asset.name ?? asset.assetVersionId}</option>)}
            </select></label>
            <button type="button" disabled={locked || !assetId} onClick={() => act(() => add('model'))}>Add model</button>
            <button type="button" disabled={locked} onClick={() => act(() => add('garage'))}>Add garage</button>
            <ul aria-label="Entity list">{state.document.entities.map(item => <li key={item.entityId}>
                <button type="button" data-entity-id={item.entityId} aria-pressed={item.entityId === selection}
                    onClick={() => act(() => select(item.entityId))}>{item.name}</button>{!item.visible && ' (hidden)'}</li>)}</ul>
        </section><section aria-label="Scene view">
            <div role="toolbar" aria-label="Transform tools">{['translate', 'rotate', 'scale'].map(mode => <button key={mode} type="button"
                disabled={readOnly} aria-pressed={!readOnly && tool === mode} onClick={() => setTool(mode)}>{mode[0].toUpperCase() + mode.slice(1)}</button>)}
                <label><input type="checkbox" checked={snap} onChange={event => setSnap(event.target.checked)} />Snap to grid</label>
                <button type="button" onClick={() => port.current?.focus(entity?.entityId)}>Frame selection</button></div>
            {Viewport && <Viewport loadAdapter={loadAdapter} resolveAsset={resolveAsset} document={preview} assets={assets} selectedId={entity?.entityId ?? null} tool={locked || draft ? null : tool} snap={snap}
                onSelect={id => act(() => select(id))} onTransform={(id, payload) => act(() => run('entity.transform', id, payload))} viewportRef={setPort} />}
        </section><section aria-label="Inspector"><h2>Inspector</h2>
            {entity ? <><form onSubmit={event => { event.preventDefault(); act(apply); }}><fieldset disabled={locked}>
                <label>Name<input maxLength="160" value={values.name} onChange={event => edit('name', event.target.value)} required /></label>
                <label>Parent<select value={values.parentId} onChange={event => edit('parentId', event.target.value)}><option value="">Scene root</option>
                    {state.document.entities.filter(item => validParent(state.document.entities, selection, item.entityId)).map(item =>
                        <option key={item.entityId} value={item.entityId}>{item.name}</option>)}</select></label>
                {fields.map(field => <label key={field.key}>{field.label}<input type="number" step="any" required value={values[field.key]}
                    onChange={event => edit(field.key, event.target.value)} /></label>)}
                {entity.type === 'garage' && <label>Garage height (cm)<input type="number" step="0.1" min="0.1" required value={values.height}
                    onChange={event => edit('height', event.target.value)} /></label>}
                <button type="submit" disabled={!draft}>Apply changes</button></fieldset></form>
                <button type="button" disabled={locked} onClick={() => act(() => run('entity.update', selection, { visible: !entity.visible }))}>{entity.visible ? 'Hide' : 'Show'}</button>
                <button type="button" disabled={locked} onClick={() => act(() => { const id = newId();
                    run('entity.duplicate', id, { sourceId: selection, name: values.name + ' copy' }); setSelection(id); })}>Duplicate</button>
                <button type="button" disabled={locked || state.document.entities.some(item => item.parentId === selection)}
                    onClick={() => act(() => { run('entity.remove', selection, {}); setSelection(null); })}>Delete</button></>
                : <p>Select an object to edit its properties.</p>}
        </section></div>
        <Dialog open={!!leave} title="Leave this scene?" onClose={() => leave?.decide('cancel')}>
            <p>This scene has unsaved changes.</p>
            {['save', 'discard', 'cancel'].map(choice => <button type="button" key={choice} onClick={() => leave.decide(choice)}>
                {choice[0].toUpperCase() + choice.slice(1)}</button>)}
        </Dialog>
    </section>;
}
