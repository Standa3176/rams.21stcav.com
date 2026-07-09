@props([
    'title',
    'breadcrumb' => null,
])

<div class="dash-page-header">
    <div class="dash-page-header__left">
        @if($breadcrumb)
            <div class="dash-page-header__eyebrow">{{ $breadcrumb }}</div>
        @endif
        <h1 class="dash-page-header__title">{{ $title }}</h1>
    </div>

    @if(isset($actions) && $actions->isNotEmpty())
        <div class="dash-page-header__actions">
            {{ $actions }}
        </div>
    @endif
</div>

<style>
/*
 * Dashboard page header — Jetbuilt-clean (2026-07-09).
 * Uppercase eyebrow above a larger H1; right-aligned actions.
 * Bottom hairline pulled from the layout's global .page-header rule so
 * the whole app's header rhythm stays consistent.
 */
.dash-page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--ink-100);
    flex-wrap: wrap;
    gap: 16px;
}
.dash-page-header__left     { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.dash-page-header__eyebrow {
    font-size: var(--fs-micro);
    font-weight: 600;
    color: var(--accent-700);
    letter-spacing: .08em;
    text-transform: uppercase;
}
.dash-page-header__title {
    font-size: var(--fs-display);
    font-weight: 600;
    color: var(--ink-900);
    letter-spacing: -.025em;
    line-height: 1.2;
}
.dash-page-header__actions  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
</style>
