import { renderPage } from '@yellow-goose/react/server';
const pages = import.meta.glob('./pages/**/*.jsx', { eager: true });
export function render(page, props) { return renderPage(pages, page, props); }
