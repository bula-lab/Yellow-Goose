import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

export function frameworkConfig(root) {
    const vitePort = Number(process.env.GASKA_VITE_PORT || 5173);
    return defineConfig(({ isSsrBuild }) => ({
        root,
        plugins: [react()],
        base: '/build/',
        publicDir: false,
        resolve: { dedupe: ['react', 'react-dom'] },
        build: {
            outDir: isSsrBuild ? 'build/ssr' : 'public/build',
            manifest: !isSsrBuild,
            rolldownOptions: {
                input: resolve(root, isSsrBuild ? 'app/server.jsx' : 'app/client.jsx'),
                ...(isSsrBuild ? { output: { entryFileNames: 'server.js' } } : {}),
            },
        },
        server: {
            host: '127.0.0.1',
            strictPort: true,
            port: vitePort,
            origin: 'http://127.0.0.1:' + vitePort,
            cors: { origin: /^http:\/\/(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/ },
        },
    }));
}
