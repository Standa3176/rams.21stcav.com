{{--
    survey/step-container

    Wraps a step's content with consistent spacing.
    Show/hide is driven by the parent Alpine.js `currentStep` value.

    Props:
      $step  (int)    — step number this container belongs to (1–8)
      $label (string) — optional section label rendered above the slot
--}}
@props(['step', 'label' => null])

<div x-show="currentStep === {{ (int) $step }}" class="space-y-4">
    @if ($label)
        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 px-1">{{ $label }}</p>
    @endif
    {{ $slot }}
</div>
