import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Tailwind theme — tier-one design tokens (2026-07-08, PLAN 260708-b7i).
 *
 * Cool slate neutrals + indigo brand + semantic status. Legacy Figtree is
 * retired from the UI (still referenced by DOCX / PDF templates in
 * resources/views/pdf/ where dompdf/mpdf don't read this config).
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

            // Semantic aliases sit alongside slate-* so screens don't have to
            // rediscover "which grey is the border" every time.
            colors: {
                ink:              '#0B1220',
                'ink-2':          '#1E293B',
                body:             '#334155',
                muted:            '#64748B',
                subtle:           '#94A3B8',
                hairline:         '#E2E8F0',
                'hairline-strong':'#CBD5E1',
                'hairline-soft':  '#EEF2F7',
                canvas:           '#F6F8FB',
                'canvas-soft':    '#F1F5F9',
                card:             '#FFFFFF',
                sidebar:          '#FBFBFD',
                brand: {
                    50:  '#EEF2FF',
                    100: '#E0E7FF',
                    500: '#6366F1',
                    600: '#4F46E5',
                    700: '#4338CA',
                    800: '#3730A3',
                },
            },

            // Tailwind defaults are too heavy for data-dense UI — every card
            // would feel like it's floating an inch off the page.
            boxShadow: {
                card:         '0 1px 2px 0 rgb(15 23 42 / 0.03), 0 1px 3px 0 rgb(15 23 42 / 0.05)',
                'card-hover': '0 4px 6px -1px rgb(15 23 42 / 0.06), 0 2px 4px -2px rgb(15 23 42 / 0.05)',
                lift:         '0 12px 24px -8px rgb(15 23 42 / 0.14), 0 4px 8px -4px rgb(15 23 42 / 0.08)',
                popover:      '0 10px 15px -3px rgb(15 23 42 / 0.10), 0 4px 6px -4px rgb(15 23 42 / 0.08)',
                'inset-focus':'0 0 0 3px rgb(79 70 229 / 0.24)',
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
