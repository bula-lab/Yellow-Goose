import { createRoot, hydrateRoot } from 'react-dom/client';
import PageShell from './PageShell';
import { installNavigation } from './navigation.mjs';

export async function startClient(pages) {
    const container = document.getElementById('app');
    let root;
    try {
        const initial = JSON.parse(document.getElementById('page-data').textContent);
        let visit = 0;
        async function component(data) {
            if (data.error) return () => <main><h1>{data.status}</h1><p role="alert">{data.error}</p><a href="/">Strona główna</a></main>;
            const loader = pages['./pages/' + data.page];
            if (!loader) throw new Error('Page is missing from the build');
            return (await loader()).default;
        }
        const Page = await component(initial);
        let installed = false;
        const ready = () => {
            if (installed) return;
            installed = true;
            installNavigation(initial, async (data, signal) => {
                const NextPage = await component(data);
                if (signal.aborted) return;
                container.dataset.clientReady = 'false';
                await new Promise(resolve => {
                    signal.addEventListener('abort', resolve, { once: true });
                    const committed = () => { signal.removeEventListener('abort', resolve); resolve(); };
                    root.render(<PageShell visit={++visit} Page={NextPage} props={data.props || {}} onReady={committed} />);
                });
            });
        };
        const tree = <PageShell visit={visit} Page={Page} props={initial.props} onReady={ready} />;
        if (container.dataset.renderMode === 'ssr') {
            root = hydrateRoot(container, tree, {
                identifierPrefix: 'gaska-',
                onRecoverableError: error => console.error('Hydration failed', error),
            });
        } else {
            root = createRoot(container, { identifierPrefix: 'gaska-' });
            root.render(tree);
        }
    } catch (error) {
        console.error(error);
        (root || createRoot(container)).render(<p role="alert">Nie udało się uruchomić strony. Odśwież ją i spróbuj ponownie.</p>);
    }
}
