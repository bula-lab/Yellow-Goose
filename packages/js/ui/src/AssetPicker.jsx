import { useEffect, useId, useRef, useState } from 'react';
import { responseData } from './http.js';

async function checksum(file) {
    const hash = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
    return Array.from(new Uint8Array(hash), byte => byte.toString(16).padStart(2, '0')).join('');
}

export async function uploadAsset(projectId, model, poster, csrfToken, onProgress, signal, resume) {
    if (!model || !poster || model.size < 1 || model.size > 16777216 || poster.size < 1 || poster.size > 4194304) {
        throw new Error('Model musi mieć do 16 MiB, a poster do 4 MiB.');
    }
    const base = '/api/assets/projects/' + encodeURIComponent(projectId) + '/uploads';
    const headers = { 'X-CSRF-Token': csrfToken };
    const send = async (url, options) => responseData(await fetch(url, { credentials: 'same-origin', signal, ...options }));
    let session = resume;
    if (!session) {
        const [modelChecksum, posterChecksum] = await Promise.all([checksum(model), checksum(poster)]);
        session = await send(base, { method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' },
            body: JSON.stringify({ modelBytes: model.size, posterBytes: poster.size, modelChecksum, posterChecksum }) });
    }
    const url = base + '/' + encodeURIComponent(session.uploadId);
    headers['X-Upload-Token'] = session.token;
    try {
        const { offsets, chunkBytes } = await send(url, { headers });
        if (!Number.isSafeInteger(chunkBytes) || chunkBytes < 1) throw new Error('Invalid upload chunk size.');
        for (const [kind, file] of [['model', model], ['poster', poster]]) {
            let offset = offsets[kind];
            if (!Number.isSafeInteger(offset) || offset < 0 || offset > file.size) throw new Error('Invalid upload offset.');
            while (offset < file.size) {
                const end = Math.min(offset + chunkBytes, file.size);
                const result = await send(url + '/' + kind + '?offset=' + offset, { method: 'PUT',
                    headers: { ...headers, 'Content-Type': 'application/octet-stream' }, body: file.slice(offset, end) });
                if (result.offset !== end) throw new Error('Invalid upload acknowledgement.');
                offsets[kind] = offset = end;
                onProgress?.((offsets.model + offsets.poster) / (model.size + poster.size));
            }
        }
        return await send(url + '/complete', { method: 'POST', headers });
    } catch (error) {
        if (error && typeof error === 'object') error.uploadSession = { uploadId: session.uploadId, token: session.token };
        throw error;
    }
}

export function AssetPicker({ assets = [], value, onChange, upload, label = 'Wersja modelu' }) {
    const id = useId();
    const [local, setLocal] = useState(null);
    const [model, setModel] = useState(null);
    const [poster, setPoster] = useState(null);
    const [progress, setProgress] = useState(null);
    const [error, setError] = useState('');
    const resume = useRef(null);
    const controller = useRef(null);
    useEffect(() => () => controller.current?.abort(), []);
    const items = local && !assets.some(asset => asset.assetVersionId === local.assetVersionId) ? [local, ...assets] : assets;
    const selected = items.find(asset => asset.assetVersionId === value);
    async function start() {
        if (controller.current || !model || !poster) return;
        const request = controller.current = new AbortController();
        setError(''); setProgress(0);
        try {
            const created = await upload(model, poster, n => { if (!request.signal.aborted) setProgress(n); }, request.signal, resume.current);
            if (!request.signal.aborted) { setLocal(created); resume.current = null; }
        } catch (e) {
            if (!request.signal.aborted) { setError(e.message); resume.current = e.uploadSession; }
        } finally { controller.current = null; if (!request.signal.aborted) setProgress(null); }
    }
    return <fieldset><legend>{label}</legend><label htmlFor={id}>Model</label>
        <select id={id} value={value || ''} onChange={e => onChange?.(e.target.value || null)}>
            <option value="">Bez modelu</option>
            {items.map(asset => <option key={asset.assetVersionId} value={asset.assetVersionId} disabled={asset.status !== 'ready'}>
                {asset.assetVersionId} ({asset.status})</option>)}
        </select>{selected?.posterUrl && <img src={selected.posterUrl} alt="Podgląd wybranego modelu" width="160" />}
        {upload && <div><label>Plik GLB<input type="file" accept=".glb" disabled={progress !== null} onChange={e => {
            resume.current = null; setModel(e.target.files[0]); }} /></label>
            <label>Poster PNG lub JPEG<input type="file" accept="image/png,image/jpeg" disabled={progress !== null}
                onChange={e => { resume.current = null; setPoster(e.target.files[0]); }} /></label>
            <button type="button" disabled={progress !== null || !model || !poster} onClick={start}>Wyślij pliki</button>
            {progress !== null && <progress aria-label="Postęp przesyłania" value={progress} max="1" />}
            {local && <p role="status">{local.assetVersionId} ({items.find(asset => asset.assetVersionId === local.assetVersionId)?.status})</p>}
            {error && <p role="alert">{error}</p>}
        </div>}
    </fieldset>;
}
