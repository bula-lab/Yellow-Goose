import { renderPage } from '../vendor/yellow-goose/framework/resources/server';

const pages = import.meta.glob([
    './pages/**/*.{jsx,js,tsx,ts}',
    '!./pages/api/**',
    '!./pages/**/_*/**',
    '!./pages/**/_*',
], { eager: true });

export function render(page, props) {
    return renderPage(pages, page, props);
}
