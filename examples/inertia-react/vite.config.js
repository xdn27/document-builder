import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [laravel({ input: 'resources/js/app.jsx', refresh: true }), react()],
    resolve: {
        alias: {
            // Paginator dipakai langsung dari paket Composer: tanpa npm, tanpa
            // langkah build tambahan, selalu versi yang sama dengan PHP-nya.
            '@document-builder': fileURLToPath(new URL('./vendor/maqiis/document-builder/resources/js', import.meta.url)),
        },
    },
});
