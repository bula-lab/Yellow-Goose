import { fileURLToPath } from 'node:url';
import { frameworkConfig } from './vendor/yellow-goose/framework/resources/vite.mjs';

export default frameworkConfig(fileURLToPath(new URL('.', import.meta.url)));
