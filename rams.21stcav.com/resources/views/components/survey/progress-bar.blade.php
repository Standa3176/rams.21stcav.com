{{--
    survey/progress-bar

    Renders a labelled progress bar. Intended for use inside a dark header block
    where text/track colours are white-tinted.

    Props:
      $current  (int)    — current step number
      $total    (int)    — total step count (default 8)
      $roomName (string) — room name shown as left label
--}}
@props(['current' => 1, 'total' => 8, 'roomName' => ''])

<div class="mt-3">
    <div class="flex justify-between items-center mb-1">
        <span class="text-xs font-medium text-white/70">{{ $roomName }}</span>
        <span class="text-xs text-white/70">Step {{ $current }} of {{ $total }}</span>
    </div>
    <div class="bg-white/20 rounded-full h-1.5 overflow-hidden">
        <div class="bg-emerald-400 h-full rounded-full transition-all duration-300"
             style="width: {{ round(($current / max($total, 1)) * 100) }}%"></div>
    </div>
</div>
