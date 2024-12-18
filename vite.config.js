import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    define: {
        'process.env.VITE_PUSHER_APP_KEY': JSON.stringify(process.env.VITE_PUSHER_APP_KEY),
        'process.env.VITE_PUSHER_HOST': JSON.stringify(process.env.VITE_PUSHER_HOST),
        'process.env.VITE_PUSHER_PORT': JSON.stringify(process.env.VITE_PUSHER_PORT),
        'process.env.VITE_PUSHER_SCHEME': JSON.stringify(process.env.VITE_PUSHER_SCHEME),
        'process.env.VITE_PUSHER_APP_CLUSTER': JSON.stringify(process.env.VITE_PUSHER_APP_CLUSTER),
    },
});
