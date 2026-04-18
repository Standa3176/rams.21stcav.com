{{--
    <x-page-header title="..." :breadcrumb="[['label'=>'Home','url'=>'/']]" subtitle="..." :status="$record->status">
        <x-slot name="actions"> ... </x-slot>
    </x-page-header>

    Props:
      title      — required string
      breadcrumb — optional array of ['label', 'url'] pairs, or a plain string
      subtitle   — optional string shown below title
      status     — optional status key; renders a <x-status-badge> inline with subtitle
--}}

@props([
    'title',
    'breadcrumb' => null,
    'subtitle'   => null,
    'status'     => null,
])

<div class="page-header">
    <div class="page-header-left">

        {{-- Breadcrumb --}}
        @if ($breadcrumb)
            <nav class="breadcrumb" aria-label="Breadcrumb">
                @if (is_array($breadcrumb))
                    @foreach ($breadcrumb as $crumb)
                        @if (! $loop->last)
                            <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                            <span class="breadcrumb__sep" aria-hidden="true">›</span>
                        @else
                            <span class="breadcrumb__current" aria-current="page">{{ $crumb['label'] }}</span>
                        @endif
                    @endforeach
                @else
                    <span>{{ $breadcrumb }}</span>
                    <span class="breadcrumb__sep" aria-hidden="true">›</span>
                    <span class="breadcrumb__current" aria-current="page">{{ $title }}</span>
                @endif
            </nav>
        @endif

        <h1 class="page-title">{{ $title }}</h1>

        {{-- Status badge + subtitle on same meta row --}}
        @if ($status || $subtitle)
            <div class="page-header-meta">
                @if ($status)
                    <x-status-badge :status="$status" />
                @endif
                @if ($subtitle)
                    <span class="page-subtitle" style="margin:0;">{{ $subtitle }}</span>
                @endif
            </div>
        @endif

    </div>

    @if (isset($actions) && $actions->isNotEmpty())
        <div class="page-header-actions">
            {{ $actions }}
        </div>
    @endif
</div>
