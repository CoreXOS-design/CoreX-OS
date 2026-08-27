import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/corex.css', 'resources/js/app.js', 'resources/js/esign-signature-pad.js'],
            refresh: false,
        }),
    ],
});
