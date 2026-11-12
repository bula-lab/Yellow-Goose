import { createRoot, hydrateRoot } from 'react-dom/client';
import PageShell from './PageShell';

export async function startClient(pages) {
    const container = document.getElementById('app');
    try {
        const { page, props } = JSON.parse(document.getElementById('page-data').textContent);
        const loader = pages['./pages/' + page];
        if (!loader) throw new Error('Page is missing from the build');
        const { default: Page } = await loader();
        const tree = <PageShell Page={Page} props={props} />;
        if (container.dataset.renderMode === 'ssr') {
            hydrateRoot(container, tree, {
                identifierPrefix: 'gaska-',
                onRecoverableError: error => console.error('Hydration failed', error),
            });
        } else {
            createRoot(container, { identifierPrefix: 'gaska-' }).render(tree);
        }
    } catch (error) {
        console.error(error);
        createRoot(container).render(<p role="alert">Nie udało się uruchomić strony. Odśwież ją i spróbuj ponownie.</p>);
    }
}
