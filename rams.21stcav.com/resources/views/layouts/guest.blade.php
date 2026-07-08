<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('rams.company_abbr', 'RAMS') }} · {{ config('app.name', 'Sign in') }}</title>

        {{-- Tier-one auth layer (post-audit UI-01). Was Figtree from
             fonts.bunny.net + bg-gray-100 + default Breeze mark. Now uses
             the same Inter Variable + slate canvas + indigo brand mark
             the authenticated shell uses so the first paint is on-brand. --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            body {
                background: var(--canvas, #F6F8FB);
                color: var(--body, #334155);
            }
            .auth-shell {
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 24px;
                padding: 40px 16px;
            }
            .auth-brand {
                display: inline-flex;
                align-items: center;
                gap: 14px;
                text-decoration: none;
            }
            .auth-brand-mark {
                width: 42px; height: 42px;
                border-radius: 10px;
                background: linear-gradient(135deg, var(--brand-500, #6366F1) 0%, var(--brand-700, #4338CA) 55%, var(--brand-800, #3730A3) 100%);
                display: grid; place-items: center;
                color: #fff;
                box-shadow: 0 6px 14px -6px rgba(79,70,229,0.55),
                            inset 0 -2px 0 rgba(0,0,0,0.12),
                            inset 0 1px 0 rgba(255,255,255,0.14);
                position: relative;
            }
            .auth-brand-mark::after {
                content: ""; position: absolute; inset: 3px;
                border-radius: 8px;
                border: 1px solid rgba(255,255,255,0.18);
                pointer-events: none;
            }
            .auth-brand-name {
                color: var(--ink, #0B1220);
                font-size: 20px;
                font-weight: 800;
                letter-spacing: -0.03em;
                line-height: 1;
            }
            .auth-brand-tagline {
                color: var(--muted, #64748B);
                font-size: 12px;
                margin-top: 3px;
            }
            .auth-card {
                width: 100%;
                max-width: 420px;
                background: var(--card, #FFFFFF);
                border: 1px solid var(--border, #E2E8F0);
                border-radius: 12px;
                box-shadow: 0 12px 24px -8px rgb(15 23 42 / 0.14), 0 4px 8px -4px rgb(15 23 42 / 0.08);
                padding: 28px 32px;
            }
            @media (max-width: 480px) {
                .auth-card { padding: 20px 20px; }
                .auth-shell { padding: 24px 12px; }
            }
        </style>
    </head>
    <body class="antialiased">
        <div class="auth-shell">
            <a href="/" class="auth-brand" aria-label="{{ config('rams.company_name', 'RAMS Platform') }}">
                <div class="auth-brand-mark" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 32 32" fill="none">
                        <path d="M6 10l10-6 10 6v12l-10 6-10-6V10z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        <path d="M6 10l10 6 10-6M16 16v12" stroke="currentColor" stroke-width="2" stroke-linejoin="round" opacity="0.7"/>
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
