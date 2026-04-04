@extends('layouts.app')

@section('title', 'Survey — ' . $survey->project_name)

@section('content')

<div class="page-header">
    <h1 class="page-title">{{ $survey->project_name }}</h1>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        @if($survey->isDraft())
            <a href="{{ route('site-surveys.edit', $survey) }}" class="btn btn-outline btn-sm">&#9998; Edit</a>
            <form method="POST" action="{{ route('site-surveys.complete', $survey) }}" style="margin:0;">
                @csrf
                <button type="submit" class="btn btn-teal btn-sm"
                        onclick="return confirm('Mark this survey as completed?')">&#10003; Mark Complete</button>
            </form>
        @else
            <span style="background:#d4edda;color:#155724;padding:.25rem .75rem;border-radius:4px;font-size:.8rem;font-weight:600;">&#10003; Completed</span>
            <a href="{{ route('site-surveys.edit', $survey) }}" class="btn btn-outline btn-sm">&#9998; Edit</a>
        @endif
        <a href="{{ route('site-surveys.pdf', $survey) }}" class="btn btn-outline btn-sm" target="_blank">&#128438; Download PDF</a>
        <a href="{{ route('site-surveys.index') }}" class="btn btn-outline btn-sm">&#8592; All Surveys</a>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

{{-- Header info card --}}
<div class="section-block" style="margin-bottom:1.25rem;">
    <div class="form-grid-2">
        <div>
            <div style="font-size:.75rem;color:#666;text-transform:uppercase;letter-spacing:.05em;">Client</div>
            <div style="font-size:1rem;font-weight:500;">{{ $survey->client_name ?? '—' }}</div>
        </div>
        <div>
            <div style="font-size:.75rem;color:#666;text-transform:uppercase;letter-spacing:.05em;">Reference</div>
            <div style="font-size:1rem;">{{ $survey->project_ref ?? '—' }}</div>
        </div>
        <div>
            <div style="font-size:.75rem;color:#666;text-transform:uppercase;letter-spacing:.05em;">Site Address</div>
            <div style="font-size:1rem;">{{ $survey->site_address ?? '—' }}</div>
        </div>
        <div>
            <div style="font-size:.75rem;color:#666;text-transform:uppercase;letter-spacing:.05em;">Survey Date</div>
            <div style="font-size:1rem;">{{ $survey->survey_date?->format('d M Y') ?? '—' }}</div>
        </div>
        <div>
            <div style="font-size:.75rem;color:#666;text-transform:uppercase;letter-spacing:.05em;">Surveyor</div>
            <div style="font-size:1rem;">{{ $survey->surveyor_name ?? '—' }}</div>
        </div>
        @if($survey->project)
        <div>
            <div style="font-size:.75rem;color:#666;text-transform:uppercase;letter-spacing:.05em;">Project</div>
            <div><a href="{{ route('projects.show', $survey->project) }}" style="color:#007B8A;">{{ $survey->project->name }}</a></div>
        </div>
        @endif
    </div>
    @if($survey->general_notes)
    <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #eee;">
        <div style="font-size:.75rem;color:#666;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.4rem;">General Notes</div>
        <p style="margin:0;white-space:pre-wrap;">{{ $survey->general_notes }}</p>
    </div>
    @endif
</div>

{{-- Rooms --}}
@forelse ($survey->rooms as $room)
<div class="section-block" style="margin-bottom:1.25rem;" id="room-{{ $room->id }}">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
        <h2 class="section-heading" style="margin:0;">
            {{ $room->room_name }}
            @if($room->room_ref)
                <span style="font-weight:400;color:#666;font-size:.875rem;">({{ $room->room_ref }})</span>
            @endif
            @if($room->floor)
                <span style="font-weight:400;color:#888;font-size:.8rem;margin-left:.5rem;">Floor: {{ $room->floor }}</span>
            @endif
        </h2>
    </div>

    <div class="form-grid-2" style="margin-bottom:1rem;">
        <div>
            <div style="font-size:.75rem;color:#666;text-transform:uppercase;letter-spacing:.05em;">Dimensions (W &times; D &times; H)</div>
            <div>
                {{ $room->room_width_m ? $room->room_width_m . 'm' : '—' }}
                &times;
                {{ $room->room_depth_m ? $room->room_depth_m . 'm' : '—' }}
                &times;
                {{ $room->room_height_m ? $room->room_height_m . 'm' : '—' }}
            </div>
        </div>
        <div>
            <div style="font-size:.75rem;color:#666;text-transform:uppercase;letter-spacing:.05em;">Ceiling</div>
            <div>{{ $room->ceiling_type ? ucfirst($room->ceiling_type) : '—' }}
                @if($room->ceiling_height_m) &nbsp;({{ $room->ceiling_height_m }}m) @endif
            </div>
        </div>
        <div>
            <div style="font-size:.75rem;color:#666;text-transform:uppercase;letter-spacing:.05em;">Wall Material</div>
            <div>{{ $room->wall_material ? ucfirst($room->wall_material) : '—' }}</div>
        </div>
        <div>
            <div style="font-size:.75rem;color:#666;text-transform:uppercase;letter-spacing:.05em;">Floor Type</div>
            <div>{{ $room->floor_type ? ucfirst($room->floor_type) : '—' }}</div>
        </div>
        <div>
            <div style="font-size:.75rem;color:#666;text-transform:uppercase;letter-spacing:.05em;">Power</div>
            <div>
                @if($room->has_power)
                    <span style="color:#155724;font-weight:600;">&#10003; Present</span>
                    &mdash; {{ $room->power_outlet_count }} outlets
                @else
                    <span style="color:#721c24;">&#10007; Not present</span>
                @endif
                @if($room->requires_additional_power)
                    &nbsp;<span style="background:#fff3cd;color:#856404;padding:.1rem .4rem;border-radius:3px;font-size:.75rem;">Additional required</span>
                @endif
            </div>
        </div>
        <div>
            <div style="font-size:.75rem;color:#666;text-transform:uppercase;letter-spacing:.05em;">Network</div>
            <div>
                @if($room->has_network)
                    <span style="color:#155724;font-weight:600;">&#10003; Present</span>
                    &mdash; {{ $room->network_port_count }} ports
                @else
                    <span style="color:#721c24;">&#10007; Not present</span>
                @endif
            </div>
        </div>
    </div>

    @if($room->existing_cabling || $room->av_requirements || $room->av_equipment_list || $room->access_notes || $room->notes)
    <table style="width:100%;border-collapse:collapse;font-size:.875rem;margin-bottom:1rem;">
        @if($room->av_requirements)
        <tr>
            <td style="width:28%;padding:.4rem .6rem;font-weight:600;color:#444;background:#f5f5f5;border:1px solid #e0e0e0;vertical-align:top;">AV Requirements</td>
            <td style="padding:.4rem .6rem;border:1px solid #e0e0e0;white-space:pre-wrap;">{{ $room->av_requirements }}</td>
        </tr>
        @endif
        @if($room->av_equipment_list)
        <tr>
            <td style="width:28%;padding:.4rem .6rem;font-weight:600;color:#444;background:#f5f5f5;border:1px solid #e0e0e0;vertical-align:top;">Existing AV Equipment</td>
            <td style="padding:.4rem .6rem;border:1px solid #e0e0e0;white-space:pre-wrap;">{{ $room->av_equipment_list }}</td>
        </tr>
        @endif
        @if($room->existing_cabling)
        <tr>
            <td style="width:28%;padding:.4rem .6rem;font-weight:600;color:#444;background:#f5f5f5;border:1px solid #e0e0e0;vertical-align:top;">Existing Cabling</td>
            <td style="padding:.4rem .6rem;border:1px solid #e0e0e0;white-space:pre-wrap;">{{ $room->existing_cabling }}</td>
        </tr>
        @endif
        @if($room->access_notes)
        <tr>
            <td style="width:28%;padding:.4rem .6rem;font-weight:600;color:#444;background:#f5f5f5;border:1px solid #e0e0e0;vertical-align:top;">Access / Hazard Notes</td>
            <td style="padding:.4rem .6rem;border:1px solid #e0e0e0;white-space:pre-wrap;">{{ $room->access_notes }}</td>
        </tr>
        @endif
        @if($room->notes)
        <tr>
            <td style="width:28%;padding:.4rem .6rem;font-weight:600;color:#444;background:#f5f5f5;border:1px solid #e0e0e0;vertical-align:top;">Notes</td>
            <td style="padding:.4rem .6rem;border:1px solid #e0e0e0;white-space:pre-wrap;">{{ $room->notes }}</td>
        </tr>
        @endif
    </table>
    @endif

    {{-- Photo gallery --}}
    <div class="photo-section">
        <div style="font-size:.8rem;font-weight:600;color:#555;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.6rem;">
            Photos ({{ $room->photos->count() }})
        </div>

        <div class="photo-grid" id="photos-{{ $room->id }}"
             style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem;">
            @foreach($room->photos as $photo)
            <div class="photo-thumb" id="photo-{{ $photo->id }}"
                 style="position:relative;width:140px;flex-shrink:0;">
                <a href="{{ route('site-surveys.photos.serve', $photo) }}" target="_blank">
                    <img src="{{ route('site-surveys.photos.serve', $photo) }}"
                         alt="{{ $photo->caption ?? $photo->original_name }}"
                         style="width:140px;height:100px;object-fit:cover;border-radius:4px;border:1px solid #ddd;display:block;">
                </a>
                @if($photo->caption)
                    <div style="font-size:.72rem;color:#555;margin-top:.25rem;text-align:center;
                                white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $photo->caption }}</div>
                @endif
                <button type="button"
                        onclick="deletePhoto({{ $photo->id }}, this)"
                        style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,.55);border:none;
                               color:#fff;border-radius:50%;width:20px;height:20px;font-size:.7rem;
                               cursor:pointer;line-height:1;display:flex;align-items:center;justify-content:center;">
                    &#10005;
                </button>
            </div>
            @endforeach

            @if($room->photos->isEmpty())
            <div class="no-photos" id="no-photos-{{ $room->id }}"
                 style="color:#aaa;font-size:.875rem;padding:.5rem 0;">No photos yet.</div>
            @endif
        </div>

        {{-- Upload area --}}
        <div class="photo-upload-area"
             style="border:2px dashed #ccc;border-radius:6px;padding:.75rem 1rem;
                    display:flex;align-items:center;gap:1rem;flex-wrap:wrap;cursor:pointer;"
             data-room-id="{{ $room->id }}"
             onclick="document.getElementById('photo-input-{{ $room->id }}').click()">
            <span style="color:#007B8A;font-size:.875rem;">&#43; Add Photo</span>
            <span style="color:#999;font-size:.8rem;">Click or drop image (JPEG/PNG, max 10 MB)</span>
            <input type="file" id="photo-input-{{ $room->id }}"
                   accept="image/*" style="display:none;"
                   data-room-id="{{ $room->id }}"
                   onchange="uploadPhoto({{ $room->id }}, this)">
        </div>
        <input type="text" id="caption-{{ $room->id }}" placeholder="Optional caption for next photo..."
               class="form-control" style="margin-top:.5rem;font-size:.875rem;" maxlength="200">
    </div>

</div>
@empty
<div class="card" style="padding:2rem;text-align:center;color:#888;">
    No rooms recorded for this survey.
    <a href="{{ route('site-surveys.edit', $survey) }}" style="color:#007B8A;margin-left:.5rem;">Add rooms</a>
</div>
@endforelse

{{-- Delete survey --}}
<div style="margin-top:2rem;padding-top:1rem;border-top:1px solid #eee;display:flex;align-items:center;gap:1rem;">
    <form method="POST" action="{{ route('site-surveys.destroy', $survey) }}"
          onsubmit="return confirm('Permanently delete this survey and all photos?');" style="margin:0;">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-danger-outline btn-sm">Delete Survey</button>
    </form>
</div>

@endsection

@push('scripts')
<script>
const CSRF = '{{ csrf_token() }}';
const SURVEY_ID = {{ $survey->id }};

function uploadPhoto(roomId, input) {
    if (!input.files.length) return;
    const file = input.files[0];
    const caption = document.getElementById('caption-' + roomId).value;
    const url = '/site-surveys/' + SURVEY_ID + '/rooms/' + roomId + '/photos';

    const fd = new FormData();
    fd.append('photo', file);
    if (caption) fd.append('caption', caption);
    fd.append('_token', CSRF);

    const area = input.closest('.photo-upload-area');
    area.style.opacity = '.5';
    area.style.pointerEvents = 'none';

    fetch(url, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.id) {
                appendPhoto(roomId, data);
                document.getElementById('caption-' + roomId).value = '';
            } else {
                alert('Upload failed: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(function(e) { alert('Upload failed: ' + e.message); })
        .finally(function() {
            area.style.opacity = '1';
            area.style.pointerEvents = '';
            input.value = '';
        });
}

function appendPhoto(roomId, data) {
    const grid = document.getElementById('photos-' + roomId);
    const noPhotos = document.getElementById('no-photos-' + roomId);
    if (noPhotos) noPhotos.remove();

    const div = document.createElement('div');
    div.className = 'photo-thumb';
    div.id = 'photo-' + data.id;
    div.style.cssText = 'position:relative;width:140px;flex-shrink:0;';

    const link = document.createElement('a');
    link.href = data.url;
    link.target = '_blank';
    const img = document.createElement('img');
    img.src = data.url;
    img.alt = data.caption || data.original_name;
    img.style.cssText = 'width:140px;height:100px;object-fit:cover;border-radius:4px;border:1px solid #ddd;display:block;';
    link.appendChild(img);
    div.appendChild(link);

    if (data.caption) {
        const cap = document.createElement('div');
        cap.style.cssText = 'font-size:.72rem;color:#555;margin-top:.25rem;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
        cap.textContent = data.caption;
        div.appendChild(cap);
    }

    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.style.cssText = 'position:absolute;top:2px;right:2px;background:rgba(0,0,0,.55);border:none;color:#fff;border-radius:50%;width:20px;height:20px;font-size:.7rem;cursor:pointer;line-height:1;display:flex;align-items:center;justify-content:center;';
    delBtn.innerHTML = '&#10005;';
    delBtn.onclick = function() { deletePhoto(data.id, this); };
    div.appendChild(delBtn);

    grid.appendChild(div);
}

function deletePhoto(photoId, btn) {
    if (!confirm('Delete this photo?')) return;
    btn.disabled = true;

    fetch('/site-surveys/photos/' + photoId, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.deleted) {
            const el = document.getElementById('photo-' + photoId);
            if (el) el.remove();
        }
    })
    .catch(function(e) { alert('Delete failed: ' + e.message); btn.disabled = false; });
}

// Drag-and-drop
document.querySelectorAll('.photo-upload-area').forEach(function(area) {
    area.addEventListener('dragover', function(e) {
        e.preventDefault();
        area.style.borderColor = '#007B8A';
    });
    area.addEventListener('dragleave', function() {
        area.style.borderColor = '#ccc';
    });
    area.addEventListener('drop', function(e) {
        e.preventDefault();
        area.style.borderColor = '#ccc';
        const file = e.dataTransfer.files[0];
        if (!file || !file.type.startsWith('image/')) return;
        const roomId = area.dataset.roomId;
        const input = document.getElementById('photo-input-' + roomId);
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        input.dispatchEvent(new Event('change'));
    });
});
</script>
@endpush
