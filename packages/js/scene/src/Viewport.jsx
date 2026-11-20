import { useEffect, useRef, useState } from 'react';

const surface = { position: 'absolute', inset: 0, width: '100%', height: '100%', display: 'block' };
const labels = { loading: 'Wczytywanie modelu…', lost: 'Utracono kontekst graficzny.', error: 'Podgląd 3D jest niedostępny.' };

export function Viewport({ loadAdapter, asset, configuration, selectedId = null, onSelect, viewportRef, label = 'Podgląd modelu 3D' }) {
    const canvas = useRef(null);
    const [port, setPort] = useState(null);
    const [state, setState] = useState('poster');
    const [retry, setRetry] = useState(0);
    const latest = useRef({});
    latest.current = { asset, configuration, selectedId, onSelect };
    const version = asset?.status === 'ready' && asset.modelUrl ? asset.assetVersionId : null;
    const enabled = Boolean(version);

    useEffect(() => {
        if (!enabled) { setState('poster'); return; }
        let alive = true;
        let mounted;
        setState('loading');
        loadAdapter().then(adapter => {
            if (!alive) return;
            mounted = adapter.mount(canvas.current, {
                onStatus: status => { if (alive) setState(status); },
                onSelect: entity => latest.current.onSelect?.(entity),
            });
            setPort(mounted);
        }).catch(() => { if (alive) setState('error'); });
        return () => {
            alive = false;
            mounted?.dispose();
            setPort(null);
        };
    }, [enabled, loadAdapter, retry]);

    useEffect(() => {
        if (!port || !version) return;
        const controller = new AbortController();
        setState('loading');
        port.loadAsset(latest.current.asset, controller.signal).then(() => {
            if (controller.signal.aborted) return;
            port.applyChanges(latest.current.configuration);
            port.select(latest.current.selectedId);
            setState('ready');
        }).catch(() => { if (!controller.signal.aborted) setState('error'); });
        return () => controller.abort();
    }, [port, version]);

    useEffect(() => { port?.applyChanges(configuration); }, [port, configuration]);
    useEffect(() => { port?.select(selectedId); }, [port, selectedId]);
    useEffect(() => {
        if (!viewportRef) return;
        if (typeof viewportRef === 'function') { viewportRef(port); return () => viewportRef(null); }
        viewportRef.current = port;
        return () => { viewportRef.current = null; };
    }, [port, viewportRef]);

    return <figure data-yg-viewport={state} aria-label={label} style={{ margin: 0 }}>
        <div style={{ position: 'relative', width: '100%', aspectRatio: '16 / 9' }}>
            {asset?.posterUrl && state !== 'ready' && <img src={asset.posterUrl} alt={label}
                style={{ ...surface, objectFit: 'contain' }} />}
            <canvas ref={canvas} aria-label={label} aria-hidden={state !== 'ready'} tabIndex={state === 'ready' ? 0 : -1}
                width="640" height="360" style={{ ...surface, touchAction: 'none',
                    ...(state === 'ready' ? {} : { opacity: 0, pointerEvents: 'none' }) }} />
        </div>
        {!asset?.posterUrl && state === 'poster' && <figcaption>Brak modelu do wyświetlenia.</figcaption>}
        {labels[state] && <figcaption role="status">{labels[state]}
            {state === 'error' && <button type="button" onClick={() => setRetry(value => value + 1)}>Ponów</button>}
        </figcaption>}
    </figure>;
}
