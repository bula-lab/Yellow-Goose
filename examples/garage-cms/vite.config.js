import { fileURLToPath } from 'node:url';
import { frameworkConfig } from '@yellow-goose/vite';

export default frameworkConfig(fileURLToPath(new URL('.', import.meta.url)));
