<li class="item-row" data-item-id="{{ $item->id }}" data-status="{{ $item->status }}">
    <div class="item-row__body">
        <p class="item-row__equipment">{{ $item->equipment_name }}</p>
        <p class="item-row__category">{{ $categoryLabels[$item->category] ?? ucfirst($item->category) }}</p>

        {{-- Notes preview — updated in-place by the Alpine factory --}}
        <p class="item-row__notes" data-role="note-preview">{{ $item->notes }}</p>

        {{-- Photo attached flag — toggled in-place by the Alpine factory --}}
        <p class="item-row__photo-flag" data-role="photo-flag" @if(! $item->evidence_photo_path) hidden @endif>
            Photo attached
        </p>
    </div>

    <div class="item-row__actions">
        @php
            $buttons = [
                ['status' => 'pass', 'label' => 'Pass'],
                ['status' => 'fail', 'label' => 'Fail'],
                ['status' => 'na',   'label' => 'N/A'],
            ];
        @endphp
        @foreach ($buttons as $btn)
            <button type="button"
                    data-role="status-btn"
                    data-status="{{ $btn['status'] }}"
                    @click="patchStatus({{ $item->id }}, '{{ $btn['status'] }}')"
                    @disabled($locked)
                    class="item-row__btn item-row__btn--{{ $btn['status'] }} {{ $item->status === $btn['status'] ? 'is-active' : '' }}">
                {{ $btn['label'] }}
            </button>
        @endforeach
    </div>
</li>
