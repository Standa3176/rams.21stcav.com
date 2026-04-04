@props([
    'title'     => null,
    'emptyText' => 'No records found.',
])

<div class="dash-table-wrapper">

    @if($title || (isset($header) && $header->isNotEmpty()))
    <div class="dash-table-wrapper__header">
        @if($title)
        <div class="dash-table-wrapper__title">{{ $title }}</div>
        @endif
        @if(isset($header) && $header->isNotEmpty())
        <div class="dash-table-wrapper__header-actions">{{ $header }}</div>
        @endif
    </div>
    @endif

    <div class="dash-table-wrapper__body">
        {{ $slot }}
    </div>

    @if(isset($footer) && $footer->isNotEmpty())
    <div class="dash-table-wrapper__footer">
        {{ $footer }}
    </div>
    @endif

</div>

<style>
.dash-table-wrapper {
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,.07);
    overflow: hidden;
    margin-bottom: 1.5rem;
}
.dash-table-wrapper__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #E5E7EB;
    gap: 1rem;
    flex-wrap: wrap;
}
.dash-table-wrapper__title          { font-size: .9375rem; font-weight: 600; color: #1F2937; }
.dash-table-wrapper__header-actions { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.dash-table-wrapper__body           { overflow-x: auto; }
.dash-table-wrapper__footer {
    padding: .85rem 1.25rem;
    border-top: 1px solid #E5E7EB;
    background: #FAFAFA;
}
</style>
