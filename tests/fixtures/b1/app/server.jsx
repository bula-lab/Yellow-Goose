import { renderPage } from '@yellow-goose/react/server';
const pages = import.meta.glob('./pages/*.jsx', { eager: true });
export const render = (page, props) => renderPage(pages, page, props);
