import { useEffect, useRef, useState } from 'react';

const surface = { position: 'absolute', inset: 0, width: '100%', height: '100%', display: 'block' };

export function SceneViewport({ loadAdapter, document, assets, selectedId, tool, snap, onSelect, onTransform, resolveAsset, viewportRef }) {
    const canvas = useRef(null), callbacks = useRef({});
    callbacks.current = { onSelect, onTransform };
    const [port, setPort] = useState(null), [status, setStatus] = useState('poster'), [attempt, setAttempt] = useState(0);
    const poster = assets.find(asset => asset.posterUrl && document.entities.some(entity => entity.assetVersionId === asset.assetVersionId))?.posterUrl
        ?? assets.find(asset => asset.posterUrl)?.posterUrl;
    useEffect(() => {
        if (!loadAdapter) { setStatus('poster'); return; }
        let active = true, mounted;
        setStatus('loading');
        loadAdapter().then(module => {
            if (!active) return;
            mounted = module.mount(canvas.current, { onStatus: value => { if (active) setStatus(value); },
                resolveAsset, onSelect: id => callbacks.current.onSelect?.(id), onTransform: (id, values) => callbacks.current.onTransform?.(id, values) });
            setPort(mounted);
        }).catch(() => { if (active) setStatus('error'); });
        return () => { active = false; mounted?.dispose(); setPort(null); };
    }, [loadAdapter, resolveAsset, attempt]);
    useEffect(() => {
        if (!port) return;
        try { port.setDocument(document, assets); } catch { setStatus('error'); }
    }, [port, document, assets]);
    useEffect(() => { port?.select(selectedId); }, [port, selectedId]);
    useEffect(() => { port?.setTool(tool, snap); }, [port, tool, snap]);
    useEffect(() => {
        if (!viewportRef) return;
        const assign = value => typeof viewportRef === 'function' ? viewportRef(value) : (viewportRef.current = value);
        assign(port); return () => assign(null);
    }, [viewportRef, port]);
    return <figure data-yg-scene-viewport={status} aria-label="Scene viewport" style={{ margin: 0 }}>
        <div style={{ position: 'relative', width: '100%', aspectRatio: '16 / 9', background: '#e7ecee' }}>
            {poster && status !== 'ready' && <img src={poster} alt="Scene poster" style={{ ...surface, objectFit: 'contain' }} />}
            <canvas ref={canvas} aria-label="Interactive scene" tabIndex={status === 'ready' ? 0 : -1} width="640" height="360"
                style={{ ...surface, touchAction: 'none', ...(status === 'ready' ? {} : { opacity: 0, pointerEvents: 'none' }) }} />
        </div>
        {status === 'loading' && <figcaption role="status">Loading scene…</figcaption>}
        {(status === 'error' || status === 'lost') && <figcaption role="status">
            {status === 'lost' ? 'Graphics context lost.' : 'Scene preview unavailable.'}
            <button type="button" onClick={() => setAttempt(value => value + 1)}>Retry preview</button></figcaption>}
    </figure>;
}
