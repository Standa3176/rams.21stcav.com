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
                // Phase 45 Plan 03 — read-only project cockpit stylesheet.
                // Per-page bundle: pushed to @stack('styles') on the cockpit
                // route ONLY, which is itself flag-gated behind
                // COCKPIT_ENABLED (config/cockpit.php, default false), so no
                // existing page loads it and no existing page is retoned.
                // It @imports resources/css/cav-tokens.css (the --cav-* brand
                // palette) and self-hosted Poppins 400/600. cav-tokens.css is
                // deliberately NOT an input of its own — it is imported, not
                // entry-loaded, so phases 46-51 can pull the palette in
                // without dragging the cockpit layout along.
                'resources/css/cockpit.css',
            ],
            refresh: true,
        }),
        react(),
    ],
});
