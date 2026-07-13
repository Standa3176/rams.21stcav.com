import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

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
                // Quick task 260713-sk1 — throwaway React Flow schematic
                // editor spike. Isolated JSX bundle, only loaded on
                // /spike/schematic-editor. Feature-flagged behind
                // SPIKE_SCHEMATIC_ENABLED so it doesn't ship to prod
                // users until an admin flips it on. Delete with the rest
                // of the spike after 2026-07-27 review.
                'resources/js/spike/main.jsx',
            ],
            refresh: true,
        }),
        react(),
    ],
});
