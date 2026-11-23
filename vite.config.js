import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { readFileSync } from 'node:fs';
import { defineConfig } from 'vite';

const { version } = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf-8'));

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/main.jsx', 'resources/js/docs/swagger.js'],
            refresh: true,
        }),
        react(),
    ],
    define: {
        __DEV__: JSON.stringify(process.env.NODE_ENV !== 'production'),
        __APP_VERSION__: JSON.stringify(version),
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: { host: 'localhost' },
        cors: true,
        watch: {
            ignored: [
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
    test: {
        globals: true,
        include: ['resources/js/**/*.test.{js,jsx}'],
    },
});
