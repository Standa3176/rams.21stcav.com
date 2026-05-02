import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Phase 18 Plan 03 — rack editor entry. Loads Sortable.js
                // ONLY on /projects/{p}/drawings/{r}/edit, keeping it out of
                // the global Alpine bundle.
                'resources/js/rack-editor.js',
            ],
            refresh: true,
        }),
    ],
});
