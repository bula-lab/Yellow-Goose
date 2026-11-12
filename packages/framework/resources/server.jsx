import { renderToString } from 'react-dom/server';
import PageShell from './PageShell';

export function renderPage(pages, page, props) {
    const Page = pages['./pages/' + page]?.default;
    if (!Page) throw new Error('Page is missing from the SSR build: ' + page);
    return renderToString(<PageShell Page={Page} props={props} />, { identifierPrefix: 'gaska-' });
}
