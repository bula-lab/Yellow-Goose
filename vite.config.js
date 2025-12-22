import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

export default defineConfig({
    plugins: [react()],
    build: {
        // Output compiled assets to public/build so PHP can serve them
        outDir: 'public/build',
        manifest: true,
        rollupOptions: {
            // Treat the React page as an entry point (for simple compilation)
            // In a real integration, we might need a specific entry file.
            input: resolve(__dirname, 'app/pages/index.jsx'),
        },
    },
    server: {
        // Dev server settings
        strictPort: true,
        port: 5173,
    }
});
