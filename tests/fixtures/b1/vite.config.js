import { frameworkConfig } from '@yellow-goose/vite';
import { fileURLToPath } from 'node:url';
export default frameworkConfig(fileURLToPath(new URL('.', import.meta.url)));
