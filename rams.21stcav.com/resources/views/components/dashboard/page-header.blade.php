@props([
    'title',
    'breadcrumb' => null,
])

<div class="dash-page-header">
    <div class="dash-page-header__left">
        @if($breadcrumb)
        <div class="dash-page-header__breadcrumb">{{ $breadcrumb }}</div>
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
.dash-page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    margin-bottom: 1.75rem;
    flex-wrap: wrap;
    gap: 1rem;
}
.dash-page-header__left         { display: flex; flex-direction: column; gap: .2rem; }
.dash-page-header__breadcrumb   { font-size: .75rem; color: #9CA3AF; font-weight: 500; letter-spacing: .02em; }
.dash-page-header__title        { font-size: 1.375rem; font-weight: 700; color: #1F2937; letter-spacing: -.02em; line-height: 1.2; }
.dash-page-header__actions      { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
</style>
