import 'vite/modulepreload-polyfill';
import { startClient } from '../vendor/yellow-goose/framework/resources/client';
import './styles.css';

const pages = import.meta.glob([
    './pages/**/*.{jsx,js,tsx,ts}',
    '!./pages/api/**',
    '!./pages/**/_*/**',
    '!./pages/**/_*',
]);
startClient(pages);
