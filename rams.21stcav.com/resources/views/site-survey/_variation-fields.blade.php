{{--
  Partial: _variation-fields.blade.php
  Quick task 260508-v7g — Variations & Additions form fields.

  Shared by both the Add modal AND the inline-edit row on
  resources/views/site-survey/edit.blade.php so the field set stays in lockstep.

  Variables:
    $variation — nullable SurveyVariation (null when adding, model when editing)
    $survey    — SiteSurvey (used to populate room dropdown + photo dropdown)
--}}

<label class="form-label" style="display:block;margin-bottom:.4rem;">
    <span style="font-weight:600;font-size:.82rem;">Room</span>
    <select name="room_name" class="form-control" data-optional>
        <option value="">— Survey-wide —</option>
        @foreach ($survey->rooms as $room)
            <option value="{{ $room->room_name }}"
                    @selected(($variation->room_name ?? '') === $room->room_name)>{{ $room->room_name }}</option>
        @endforeach
    </select>
</label>

<label class="form-label" style="display:block;margin-bottom:.4rem;">
    <span style="font-weight:600;font-size:.82rem;">Type <span class="req">*</span></span>
    <select name="type" class="form-control" required>
        @foreach ([
            'extra_hardware'         => 'Extra Hardware',
            'extra_labour'           => 'Extra Labour',
            'cable_change'           => 'Cable Change',
            'client_provided_change' => 'Client-Provided Change',
            'access_issue'           => 'Access Issue',
            'other'                  => 'Other',
        ] as $value => $label)
            <option value="{{ $value }}"
                    @selected(($variation->type ?? '') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</label>

<label class="form-label" style="display:block;margin-bottom:.4rem;">
    <span style="font-weight:600;font-size:.82rem;">Description <span class="req">*</span></span>
    <textarea name="description" rows="3" class="form-control" maxlength="3000" required
              placeholder="e.g. Extra HDMI cable for podium PC; client confirmed in walk-through 06/05.">{{ $variation->description ?? '' }}</textarea>
</label>

<label class="form-label" style="display:block;margin-bottom:.4rem;">
    <span style="font-weight:600;font-size:.82rem;">Quantity</span>
    <input type="number" name="qty" min="1" max="9999"
           value="{{ $variation->qty ?? 1 }}" class="form-control" data-optional>
</label>

<label class="form-label" style="display:block;margin-bottom:.4rem;">
    <span style="font-weight:600;font-size:.82rem;">Photo (optional)</span>
    <select name="photo_id" class="form-control" data-optional>
        <option value="">— None —</option>
        @foreach ($survey->rooms as $room)
            @foreach ($room->photos as $photo)
                <option value="{{ $photo->id }}"
                        @selected((int) ($variation->photo_id ?? 0) === (int) $photo->id)>
                    {{ $room->room_name }} — {{ $photo->caption ?: ($photo->original_name ?? 'photo #'.$photo->id) }}
                </option>
            @endforeach
        @endforeach
    </select>
</label>

<label class="form-label" style="display:block;margin-bottom:.4rem;">
    <span style="font-weight:600;font-size:.82rem;">Status</span>
    <select name="status" class="form-control" data-optional>
        @foreach (['proposed', 'quoted', 'approved', 'rejected'] as $option)
            <option value="{{ $option }}"
                    @selected(($variation->status ?? 'proposed') === $option)>{{ ucfirst($option) }}</option>
        @endforeach
    </select>
</label>

<label class="form-label" style="display:block;margin-bottom:0;">
    <span style="font-weight:600;font-size:.82rem;">Notes (optional)</span>
    <textarea name="notes" rows="2" class="form-control" maxlength="2000" data-optional>{{ $variation->notes ?? '' }}</textarea>
</label>
