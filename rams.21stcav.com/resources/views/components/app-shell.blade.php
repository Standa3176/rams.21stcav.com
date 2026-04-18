{{--
    <x-app-shell>
    Thin content wrapper that auto-renders session flash messages before the slot.
    Use inside @section('content') — the layout already provides page-wrap.
--}}

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif
@if (session('warning'))
    <div class="alert alert-warning">{{ session('warning') }}</div>
@endif
@if (session('info'))
    <div class="alert alert-info">{{ session('info') }}</div>
@endif

{{ $slot }}
