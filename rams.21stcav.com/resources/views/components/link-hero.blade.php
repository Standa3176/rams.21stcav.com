@props([
    'url',
    'label',
    'icon'         => '🔗',
    'urlAriaLabel' => null,
    'regionLabel'  => null,
])

{{--
    Batch 12 — shared "share link" hero panel.
    Extracted from the near-identical .survey-link-hero + .ws-signoff-hero
    stylesheets on site-survey/show and worksheets/show so both surfaces
    render through one place. The panel is a flat navy card (Jetbuilt
    convention) with accent-tinted eyebrow + label, a mono URL chip,
    optional slot for extra body content (sign-off note, hint text, etc.),
    and an actions slot for the copy / open / revoke buttons.

    Usage:
        <x-link-hero
            :url="$publicUrl"
            label="Client sign-off link"
            icon="🔗"
            regionLabel="Client sign-off link"
            urlAriaLabel="Sign-off URL — click to select">
            <x-slot name="hint">Optional inline note under the URL</x-slot>
            <x-slot name="actions">
                <x-copy-link-button :url="$publicUrl" label="Copy" />
                <a href="{{ $publicUrl }}" target="_blank" class="btn btn-sm">Open ↗</a>
            </x-slot>
        </x-link-hero>
--}}
<div class="link-hero" role="region" aria-label="{{ $regionLabel ?? $label }}">
    <div class="link-hero__icon" aria-hidden="true">{{ $icon }}</div>
    <div class="link-hero__body">
        <div class="link-hero__label">{{ $label }}</div>
        <input type="text" value="{{ $url }}" readonly data-optional
               class="link-hero__url"
               onclick="this.select()"
               aria-label="{{ $urlAriaLabel ?? $label }}">
        @isset($hint)
            <div class="link-hero__hint">{{ $hint }}</div>
        @endisset
    </div>
    @isset($actions)
        <div class="link-hero__actions">{{ $actions }}</div>
    @endisset
</div>

@once
<style>
    /*
     * Shared link-hero panel — Jetbuilt-clean. Same navy chrome the
     * top-nav uses, so this hero reads as one product surface not a
     * one-off scoped card. Flat, no gradient, no shadow.
     */
    .link-hero {
        background: var(--nav-800);
        color: #E0E7FF;
        border-radius: var(--radius-lg);
        padding: 18px 22px;
        margin-bottom: 20px;
        display: grid;
        grid-template-columns: 26px 1fr auto;
        gap: 16px;
        align-items: center;
        box-shadow: none;
    }
    .link-hero__icon {
        width: 26px; height: 26px;
        display: flex; align-items: center; justify-content: center;
        color: var(--accent-500);
        font-size: 20px;
    }
    .link-hero__body { min-width: 0; }
    .link-hero__label {
        font-size: 10px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        font-weight: 600;
        color: var(--accent-500);
        margin-bottom: 4px;
    }
    .link-hero__url {
        font-family: var(--font-mono);
        font-size: 12px;
        color: #F1F5F9;
        display: block;
        width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        background: rgba(255, 255, 255, 0.06);
        padding: 6px 10px;
        border-radius: var(--radius-sm);
        border: 1px solid rgba(255, 255, 255, 0.10);
        cursor: text;
    }
    .link-hero__hint {
        font-size: 11px;
        color: rgba(255, 255, 255, 0.65);
        margin-top: 6px;
    }
    .link-hero__actions {
        display: flex;
        gap: 6px;
        align-items: center;
        flex-shrink: 0;
    }
    /* Actions slot: shrink action buttons + retint for the dark ground. */
    .link-hero__actions .btn {
        background: rgba(255, 255, 255, 0.10);
        color: #F1F5F9;
        border-color: rgba(255, 255, 255, 0.16);
        font-size: 12px;
        padding: 5px 12px;
    }
    .link-hero__actions .btn:hover {
        background: rgba(255, 255, 255, 0.18);
        color: #FFF;
    }
    .link-hero__actions .btn.btn-danger {
        background: color-mix(in oklab, var(--danger) 60%, transparent);
        border-color: color-mix(in oklab, var(--danger) 60%, transparent);
        color: #FFF;
    }
    .link-hero__actions .btn.btn-danger:hover {
        background: var(--danger);
        border-color: var(--danger);
    }
</style>
@endonce
