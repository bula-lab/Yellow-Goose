import 'vite/modulepreload-polyfill';
import { startClient } from '@yellow-goose/react/client';
import './styles.css';

startClient(import.meta.glob('./pages/**/*.jsx'));
