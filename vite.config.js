import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // Order matters: Bootstrap first, then the Herozi theme, then app overrides.
            input: [
                'resources/scss/bootstrap.scss',
                'resources/scss/icons.scss',
                'resources/scss/app.scss',
                'resources/scss/custom.scss',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
    build: {
        // ApexCharts (~1 MB) is its own chunk, loaded only by pages with charts.
        chunkSizeWarningLimit: 1100,
    },
    css: {
        preprocessorOptions: {
            scss: {
                // Herozi imports "bootstrap/scss/..." as bare paths.
                loadPaths: ['node_modules'],
                // The theme uses Sass @import and global functions; keep builds readable.
                quietDeps: true,
                silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function'],
            },
        },
    },
});
