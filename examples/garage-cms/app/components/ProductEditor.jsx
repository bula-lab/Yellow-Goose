import { useEffect, useRef, useState } from 'react';
import { navigate } from '@yellow-goose/react/navigation';
import { useWorkspaceLeaveGuard } from '@yellow-goose/react/workspace';
import { AssetPicker, uploadAsset } from '@yellow-goose/ui/assets';
import { Dialog, Form } from '@yellow-goose/ui';
import Frame from './Frame';
import { post, request } from './http';

function signature(text, assetId, materials) {
    return JSON.stringify([text.id, text.title, text.description, assetId, materials]);
}

export default function ProductEditor({ projectId, draft: initialDraft, fields, materials, assets, csrfToken }) {
    const [draft, setDraft] = useState(initialDraft);
    const [versions, setVersions] = useState(assets);
    const [uploadedId, setUploadedId] = useState(null);
    const [assetId, setAssetId] = useState(draft?.assetVersionId ?? null);
    const [palette, setPalette] = useState(materials);
    const [materialIds, setMaterialIds] = useState(draft ? draft.materials.map(material => material.id) : materials.items.map(material => material.id).slice(0, 32));
    const [pending, setPending] = useState(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [openingSavedDraft, setOpeningSavedDraft] = useState(false);
    const [text, setText] = useState(() => ({ id: draft?.id ?? '', title: draft?.title ?? '', description: draft?.description ?? '' }));
    const [saved, setSaved] = useState(() => signature(text, assetId, materialIds));
    const [leave, setLeave] = useState(null);
    const dirty = saved !== signature(text, assetId, materialIds);
    const dirtyRef = useRef(dirty);
    const signingOut = useRef(false);
    dirtyRef.current = dirty;
    const versionUrl = '/api/assets/projects/' + encodeURIComponent(projectId) + '/versions';
    const waiting = versions.some(version => ['queued','processing'].includes(version.status));
    useEffect(() => {
        if (!waiting) return;
        const controller = new AbortController();
        const timer = setInterval(async () => {
            try {
                const next = await request(versionUrl, { signal: controller.signal });
                for (const id of new Set([assetId, uploadedId])) {
                    if (id && !next.items.some(version => version.assetVersionId === id)) {
                        try {
                            next.items.unshift(await request(versionUrl + '/' + encodeURIComponent(id), { signal: controller.signal }));
                        } catch (failure) { if (failure.status !== 404) throw failure; }
                    }
                }
                if (!controller.signal.aborted) setVersions(next.items);
            } catch (failure) { if (!controller.signal.aborted) setError(failure.message); }
        }, 1200);
        return () => { clearInterval(timer); controller.abort(); };
    }, [waiting, versionUrl, uploadedId, assetId]);
    async function upload(model, poster, progress, signal, resume) {
        const version = await uploadAsset(projectId, model, poster, csrfToken, progress, signal, resume);
        setUploadedId(version.assetVersionId);
        setVersions(previous => [version, ...previous.filter(candidate => candidate.assetVersionId !== version.assetVersionId)]);
        return version;
    }
    function choose({ signal } = {}) {
        return new Promise(resolve => {
            if (signal?.aborted) { resolve('cancel'); return; }
            function decide(choice) {
                signal?.removeEventListener('abort', abort);
                setLeave(null);
                resolve(choice);
            }
            const abort = () => decide('cancel');
            signal?.addEventListener('abort', abort, { once: true });
            setLeave({ decide });
        });
    }
    async function persist(values, signal) {
        const data = { ...values, assetVersionId: assetId, materialIds };
        if (draft) Object.assign(data, { expectedRevision: draft.revision, id: draft.id });
        let result;
        try {
            result = await post('/api/resources/products/actions/' + (draft ? 'save' : 'create'), data, csrfToken, signal);
        } catch (failure) {
            if (!signal?.aborted) setError([failure.message, ...Object.values(failure.errors || {}).flat()].join(' '));
            throw failure;
        }
        dirtyRef.current = false;
        setSaved(signature(data, assetId, materialIds));
        setDraft({ ...draft, ...data, ...result });
        return result;
    }
    async function save(values, signal) {
        setOpeningSavedDraft(true);
        try {
            const result = await persist(values, signal);
            await navigate('/editor/products/' + encodeURIComponent(result.id), { replace: Boolean(draft) });
        } finally { if (!signal?.aborted) setOpeningSavedDraft(false); }
    }
    useWorkspaceLeaveGuard({ dirty, contains: () => signingOut.current || !dirtyRef.current, choose, save: ({ signal }) => persist(text, signal) });
    async function beforeSignOut(finish) {
        if (dirtyRef.current) {
            const choice = await choose();
            if (choice === 'cancel') return;
            if (choice === 'save') await persist(text);
        }
        signingOut.current = true;
        try { await finish(); } finally { signingOut.current = false; }
    }
    async function publication() {
        setBusy(true);
        try {
            await post('/api/resources/products/actions/' + pending, { id: draft.id, expectedRevision: draft.revision }, csrfToken);
            setPending(null);
            await navigate('/editor/products/' + encodeURIComponent(draft.id), { replace: true });
        } catch (failure) {
            setPending(null);
            setError([failure.message, ...Object.values(failure.errors || {}).flat()].join(' '));
        } finally { setBusy(false); }
    }
    async function moreMaterials() {
        try {
            const page = await request('/api/resources/materials?limit=100&cursor=' + encodeURIComponent(palette.nextCursor));
            setPalette(previous => ({ items: [...previous.items, ...page.items], nextCursor: page.nextCursor }));
        } catch (failure) { setError(failure.message); }
    }
    const textFields = draft ? { title: fields.title, description: fields.description } : fields;
    return <Frame editor csrfToken={csrfToken} beforeSignOut={beforeSignOut}><main inert={openingSavedDraft} aria-busy={openingSavedDraft}>
        <h1>{draft ? 'Edit product' : 'New product'}</h1>
        {draft && <p>Draft revision {draft.draftRevision} · {draft.publishedRevision ? 'Published revision ' + draft.publishedRevision : 'Unpublished'}
            {' · '}<a href={'/preview/' + encodeURIComponent(draft.id)}>Preview draft</a>
            {draft.publishedRevision && <> · <a href={'/garages/' + encodeURIComponent(draft.id)}>View published offer</a></>}</p>}
        <div className="editor-grid"><section><h2>Product details</h2>
            <Form fields={textFields} initialValues={text} onChange={values => setText({ ...text, ...values })} onSubmit={save} submitLabel="Save draft" />
            <fieldset><legend>Offered finishes</legend>{palette.items.map(material =>
                <label key={material.id} className="choice"><input type="checkbox" checked={materialIds.includes(material.id)}
                    onChange={event => setMaterialIds(previous => event.target.checked ? [...previous, material.id] : previous.filter(id => id !== material.id))} />
                    {material.title}</label>)}
                {palette.nextCursor && <button type="button" onClick={moreMaterials}>More finishes</button>}</fieldset>
        </section><section><h2>Model and poster</h2>
            <AssetPicker assets={versions} value={assetId} onChange={setAssetId} upload={upload} />
        </section></div>
        {error && <p role="alert">{error}</p>}
        {draft && <section><h2>Publication</h2><div className="line">
            <button disabled={dirty} onClick={() => setPending('publish')}>Publish saved draft</button>
            {draft.publishedRevision && <button disabled={dirty} onClick={() => setPending('unpublish')}>Unpublish offer</button>}</div>
            {dirty && <p>Save your edits before changing publication.</p>}</section>}
        <Dialog open={pending !== null} title={pending === 'unpublish' ? 'Unpublish offer?' : 'Publish saved draft?'} onClose={() => setPending(null)}>
            <p>{pending === 'unpublish' ? 'Guests will lose access to this offer.' : 'Guests will see the saved text, finishes, model and poster.'}</p>
            <button onClick={publication} disabled={busy}>Confirm {pending}</button>
        </Dialog>
        <Dialog open={leave !== null} title="Leave unsaved edits?" onClose={() => leave?.decide('cancel')}>
            <button onClick={() => leave.decide('save')}>Save and leave</button>
            <button onClick={() => leave.decide('discard')}>Discard edits</button>
            <button onClick={() => leave.decide('cancel')}>Keep editing</button>
        </Dialog>
    </main></Frame>;
}
