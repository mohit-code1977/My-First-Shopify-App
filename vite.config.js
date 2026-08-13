import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/zoho-sync.css',
                'resources/js/app.jsx',
                'resources/js/zoho-sync.js',
            ],
            refresh: true,
        }),
        react(),
    ],
});