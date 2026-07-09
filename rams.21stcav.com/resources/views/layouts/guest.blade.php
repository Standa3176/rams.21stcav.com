<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Re-audit UI-03 — was `{abbr} · {app.name}`, which resolved
             to "RAMS · Laravel" in the tab because .env still has the
             Breeze default APP_NAME. Authenticated pages end in "— 21st
             Century AV" via config('rams.company_name'), so the auth
             pages inherit the same suffix. --}}
        <title>Sign in — {{ config('rams.company_name', '21st Century AV') }}</title>

        {{-- Auth layer — Jetbuilt-clean (2026-07-09). Was an indigo
             gradient hexagon + glossy-inset chrome from the tier-one
             pass. Retunes to the flat navy mark that matches the
             top-nav lockup in the authenticated shell, so the first
             paint reads as one product. --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            /* Token stub — guest layout doesn't inherit the :root block
               from layouts/app.blade.php, so the handful of vars used on
               the auth pages get inlined here. Keep in lockstep with the
               main layout — nav-800, accent-*, ink-*, paper, surface,
               radius-*, fs-*. */
            :root {
                --paper: #F7F9FC;
                --surface: #FFFFFF;
                --ink-900: #0F172A;
                --ink-700: #334155;
                --ink-500: #64748B;
                --ink-300: #CBD5E1;
                --ink-200: #E2E8F0;
                --ink-100: #F1F5F9;
                --body: var(--ink-900);
                --nav-800: #0B2440;
                --accent-600: #2E7BFF;
                --accent-700: #1E5FE0;
                --radius-sm: 6px;
                --radius-lg: 8px;
                --fs-body: 0.9375rem;
                --fs-small: 0.8125rem;
                --fs-h3: 1.125rem;
            }
            body {
                background: var(--paper);
                color: var(--body);
                font-size: var(--fs-body);
            }
            .auth-shell {
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 28px;
                padding: 48px 16px;
            }
            .auth-brand {
                display: inline-flex;
                align-items: center;
                gap: 12px;
                text-decoration: none;
            }
            .auth-brand-mark {
                width: 36px; height: 36px;
                border-radius: var(--radius-sm);
                background: var(--nav-800);
                display: grid; place-items: center;
                color: #fff;
                box-shadow: none;
            }
            .auth-brand-mark svg { color: #fff; }
            .auth-brand-name {
                color: var(--ink-900);
                font-size: 18px;
                font-weight: 600;
                letter-spacing: -0.02em;
                line-height: 1.15;
            }
            .auth-brand-tagline {
                color: var(--ink-500);
                font-size: var(--fs-small);
                margin-top: 2px;
            }
            .auth-card {
                width: 100%;
                max-width: 400px;
                background: var(--surface);
                border: 1px solid var(--ink-200);
                border-radius: var(--radius-lg);
                box-shadow: none;
                padding: 32px 32px 28px;
            }
            .auth-card__title {
                font-size: var(--fs-h3);
                font-weight: 600;
                color: var(--ink-900);
                letter-spacing: -0.015em;
                margin-bottom: 6px;
            }
            .auth-card__sub {
                font-size: var(--fs-small);
                color: var(--ink-500);
                margin-bottom: 22px;
            }
            .auth-footer {
                font-size: var(--fs-small);
                color: var(--ink-500);
                text-align: center;
                margin-top: 4px;
            }
            .auth-footer a { color: var(--accent-700); font-weight: 500; }
            .auth-footer a:hover { color: var(--accent-600); }
            @media (max-width: 480px) {
                .auth-card { padding: 24px 20px; }
                .auth-shell { padding: 32px 12px; }
            }
        </style>
    </head>
    <body class="antialiased">
        <div class="auth-shell">
            <a href="/" class="auth-brand" aria-label="{{ config('rams.company_name', 'RAMS Platform') }}">
                <div class="auth-brand-mark" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2 L22 7 L22 17 L12 22 L2 17 L2 7 Z"/>
                    </svg>
                </div>
                <div>
                    <div class="auth-brand-name">RAMS</div>
                    <div class="auth-brand-tagline">Project delivery documents. Simplified.</div>
                </div>
            </a>

            <div class="auth-card">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
