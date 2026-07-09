import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Tailwind theme — Jetbuilt-clean (2026-07-09).
 *
 * Navy primary + electric blue accent + Slate neutrals, mirroring the
 * CSS custom properties in layouts/app.blade.php :root. Semantic aliases
 * sit alongside so screens don't have to rediscover "which grey is the
 * border" each time.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"Inter Variable"', 'Inter', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },

            colors: {
                ink:              '#0F172A',
                'ink-2':          '#1E293B',
                body:             '#0F172A',
                muted:            '#64748B',
                subtle:           '#94A3B8',
                hairline:         '#E2E8F0',
                'hairline-strong':'#CBD5E1',
                'hairline-soft':  '#F1F5F9',
                canvas:           '#F7F9FC',
                'canvas-soft':    '#FAFBFD',
                card:             '#FFFFFF',
                sidebar:          '#FFFFFF',

                // Legacy `brand.*` aliases retained for the Breeze auth
                // components (`bg-brand-600`, `focus:ring-brand-600`, etc.).
                // Remapped to the Jetbuilt electric-blue accent so no
                // component code has to change.
                brand: {
                    50:  '#F0F5FF',
                    100: '#DCE9FF',
                    500: '#4C8FFF',
                    600: '#2E7BFF',
                    700: '#1E5FE0',
                    800: '#0B2440',
                    // Re-audit D-08 — surveys/show.blade.php uses
                    // `text-brand-teal` / `bg-brand-teal` / `focus:ring-brand-teal`
                    // in 60+ places. Was silently no-op'ing because the
                    // key wasn't declared; add here so every existing
                    // survey-form class resolves to the accent hue instead
                    // of the invisible fallback.
                    teal: '#2E7BFF',
                },

                // Explicit accent + nav sets for new code that wants the
                // semantic name rather than the historical `brand.*` slot.
                accent: {
                    50:  '#F0F5FF',
                    100: '#DCE9FF',
                    500: '#4C8FFF',
                    600: '#2E7BFF',
                    700: '#1E5FE0',
                },
                nav: {
                    700: '#143263',
                    800: '#0B2440',
                    900: '#0A1F3D',
                },
            },

            // Flatter than Tailwind defaults — Jetbuilt cards use border,
            // not shadow, and modals stop at `popover`.
            boxShadow: {
                card:         '0 1px 2px 0 rgb(15 23 42 / 0.05)',
                'card-hover': '0 4px 6px -1px rgb(15 23 42 / 0.08), 0 2px 4px -2px rgb(15 23 42 / 0.05)',
                lift:         '0 20px 25px -5px rgb(15 23 42 / 0.12), 0 8px 10px -6px rgb(15 23 42 / 0.05)',
                popover:      '0 10px 15px -3px rgb(15 23 42 / 0.10), 0 4px 6px -4px rgb(15 23 42 / 0.06)',
                'inset-focus':'0 0 0 3px rgb(46 123 255 / 0.24)',
            },

            borderRadius: {
                sm:    '4px',
                md:    '6px',
                lg:    '8px',
                xl:    '12px',
                '2xl': '16px',
            },

            letterSpacing: {
                tight:          '-0.015em',
                tighter:        '-0.02em',
                'tighter-plus': '-0.03em',
            },
        },
    },

    plugins: [forms],
};
