@extends('layouts.app')

@section('title', 'Confirm Rooms — ' . $survey->project_name)

@section('content')

<div class="page-header">
    <h1 class="page-title">Confirm Rooms</h1>
    <div style="display:flex;gap:.5rem;">
        <a href="{{ route('site-surveys.show', $survey) }}" class="btn btn-outline btn-sm">&#8592; Back to Survey</a>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if ($errors->any())
    <div class="alert alert-error">
        <strong>Please correct the following:</strong>
        <ul style="margin:.5rem 0 0 1.2rem;font-size:.875rem;">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    </div>
@endif

<p style="color:#475569;font-size:.95rem;margin-bottom:1.25rem;line-height:1.5;">
    These rooms were imported from the project's quote data. Untick any that aren&#39;t
    physical spaces (e.g. cabling, services). Bump <em>qty</em> if you have multiple
    of the same room type. You can add or remove rooms again on the next screen.
</p>

<form method="POST" action="{{ route('site-surveys.confirm-rooms.apply', $survey) }}" id="confirm-rooms-form">
    @csrf

    {{-- Scope of Works --}}
    <div class="form-section">
        <div class="form-section__header">
            <h2 class="section-heading">Scope of Works</h2>
        </div>
        <div class="form-section__body">
            <p style="color:#64748B;font-size:.875rem;margin-bottom:.5rem;">
                Pulled from the project&#39;s works description. Edit if needed.
            </p>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label sr-only" for="general_notes">Scope of Works</label>
                <textarea id="general_notes" name="general_notes" class="form-control"
                          rows="5" maxlength="3000" data-optional
                          placeholder="Describe the scope of works for this survey...">{{ old('general_notes', $survey->general_notes) }}</textarea>
            </div>
        </div>
    </div>

    {{-- Rooms --}}
    <div class="form-section">
        <div class="form-section__header">
            <h2 class="section-heading">Rooms ({{ $survey->rooms->count() }} imported)</h2>
        </div>
        <div class="form-section__body">

            @if ($survey->rooms->isEmpty())
                <p style="color:#64748B;font-style:italic;margin:0;">
                    No rooms were extracted from the project data. Continue to the next
                    screen to add them manually.
                </p>
            @else
                <div style="overflow-x:auto;">
                    <table class="rooms-confirm-table" style="width:100%;border-collapse:collapse;font-size:.9rem;">
                        <thead>
                            <tr style="border-bottom:2px solid #178A95;background:#F0FDFA;">
                                <th style="padding:.6rem .5rem;width:80px;text-align:center;color:#0F766E;font-weight:600;text-transform:uppercase;font-size:.75rem;letter-spacing:.05em;">Include</th>
                                <th style="padding:.6rem .5rem;text-align:left;color:#0F766E;font-weight:600;text-transform:uppercase;font-size:.75rem;letter-spacing:.05em;">Room Name</th>
                                <th style="padding:.6rem .5rem;width:90px;text-align:center;color:#0F766E;font-weight:600;text-transform:uppercase;font-size:.75rem;letter-spacing:.05em;">Qty</th>
                                <th style="padding:.6rem .5rem;text-align:left;color:#0F766E;font-weight:600;text-transform:uppercase;font-size:.75rem;letter-spacing:.05em;">Scope / AV Requirements</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($survey->rooms as $i => $room)
                            <tr style="border-bottom:1px solid #E5E7EB;">
                                <td style="padding:.75rem .5rem;text-align:center;vertical-align:top;">
                                    <input type="hidden"   name="rooms[{{ $i }}][id]"      value="{{ $room->id }}">
                                    <input type="hidden"   name="rooms[{{ $i }}][include]" value="0">
                                    <input type="checkbox" name="rooms[{{ $i }}][include]" value="1"
                                           id="rooms-{{ $i }}-include"
                                           {{ old("rooms.$i.include", '1') ? 'checked' : '' }}
                                           style="width:18px;height:18px;cursor:pointer;accent-color:#178A95;">
                                </td>
                                <td style="padding:.5rem;vertical-align:top;">
                                    <input type="text" name="rooms[{{ $i }}][room_name]"
                                           value="{{ old("rooms.$i.room_name", $room->room_name) }}"
                                           class="form-control" maxlength="150" required
                                           aria-label="Room name">
                                </td>
                                <td style="padding:.5rem;vertical-align:top;">
                                    <input type="number" name="rooms[{{ $i }}][qty]"
                                           value="{{ old("rooms.$i.qty", 1) }}"
                                           min="1" max="99" class="form-control"
                                           style="text-align:center;"
                                           aria-label="Quantity">
                                </td>
                                <td style="padding:.5rem;vertical-align:top;">
                                    <textarea name="rooms[{{ $i }}][av_requirements]"
                                              rows="2" maxlength="5000"
                                              class="form-control" data-optional
                                              aria-label="AV requirements"
                                              placeholder="e.g. Single 65&quot; display, ceiling speakers, table mic">{{ old("rooms.$i.av_requirements", $room->av_requirements) }}</textarea>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p style="color:#64748B;font-size:.8rem;margin-top:.75rem;line-height:1.5;">
                    <strong>Tip:</strong> Setting <em>qty &gt; 1</em> will expand the room into numbered copies
                    (e.g. &ldquo;Small Room&rdquo; with qty&nbsp;3 becomes &ldquo;Small Room 1&rdquo;,
                    &ldquo;Small Room 2&rdquo;, &ldquo;Small Room 3&rdquo;).
                </p>
            @endif
        </div>
    </div>

    {{-- Actions --}}
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;margin-top:1.5rem;">
        <button type="submit" class="btn btn-teal" style="min-width:200px;">Confirm &amp; Continue</button>
        <a href="{{ route('site-surveys.edit', $survey) }}" class="btn btn-outline">Skip — go straight to edit</a>
    </div>
</form>

@endsection
