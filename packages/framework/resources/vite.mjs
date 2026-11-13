import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { createRequire } from 'node:module';

export function frameworkConfig(root) {
    const vitePort = Number(process.env.GASKA_VITE_PORT || 5173);
    const require = createRequire(resolve(root, 'package.json'));
    // React's public browser entry also loads streaming code requiring Web APIs.
    // The pinned synchronous implementation can run in a bare V8 context.
    const syncRenderer = resolve(require.resolve('react-dom/package.json'), '../cjs/react-dom-server-legacy.browser.production.js');
    return defineConfig(({ isSsrBuild }) => ({
        root,
        plugins: [react()],
        base: '/build/',
        publicDir: false,
        resolve: {
            dedupe: ['react', 'react-dom'],
            ...(isSsrBuild ? { alias: [{ find: /^react-dom\/server$/, replacement: syncRenderer }] } : {}),
        },
        define: { 'process.env.NODE_ENV': JSON.stringify(isSsrBuild ? 'production' : process.env.NODE_ENV || 'development') },
        ssr: { target: 'webworker', noExternal: true },
        build: {
            outDir: isSsrBuild ? 'build/ssr' : 'public/build',
            manifest: !isSsrBuild,
            ...(isSsrBuild ? { lib: {
                entry: resolve(root, 'app/server.jsx'), name: 'GaskaSSR', formats: ['iife'], fileName: () => 'server.js',
            } } : {}),
            rolldownOptions: {
                input: resolve(root, isSsrBuild ? 'app/server.jsx' : 'app/client.jsx'),
                ...(isSsrBuild ? { output: { entryFileNames: 'server.js', format: 'iife', name: 'GaskaSSR' } } : {}),
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
