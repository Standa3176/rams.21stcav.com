@extends('layouts.app')

@section('title', 'Edit Cable Schedule')

@section('content')

<div class="page-header">
    <h1 class="page-title">{{ $schedule->project_name }}</h1>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <a href="{{ route('documents.revisions.view', ['type' => 'cable', 'id' => $schedule->id]) }}" class="btn btn-outline btn-sm">↻ History</a>
        <x-document-edit-drawer
            type="cable"
            :id="$schedule->id"
            label="Cable Schedule"
            :visible="in_array($schedule->status, [\App\Models\CableSchedule::STATUS_DRAFT, \App\Models\CableSchedule::STATUS_FINAL])" />
        <a href="{{ route('cable-schedules.index') }}" class="btn btn-outline btn-sm">← Back to list</a>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<form method="POST" action="{{ route('cable-schedules.update', $schedule) }}" id="edit-form">
    @csrf
    @method('PUT')

    {{-- Status + meta --}}
    <div class="section-block" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
        <div>
            <label class="form-label" style="margin-bottom:.25rem;">Status</label>
            <select name="status" class="form-control" style="width:auto;">
                <option value="draft" {{ $schedule->status==='draft'  ? 'selected':'' }}>Draft</option>
                <option value="final" {{ $schedule->status==='final'  ? 'selected':'' }}>Final</option>
            </select>
        </div>
        <div style="flex:1;font-size:.875rem;color:#666;">
            @if ($schedule->source_filename)
                Source: <strong>{{ $schedule->source_filename }}</strong> ·
            @endif
            {{ $schedule->items->count() }} cable(s) ·
            Created {{ $schedule->created_at->format('d M Y') }}
        </div>
        <button type="submit" class="btn btn-teal">Save Changes</button>
    </div>

    {{-- Cable table --}}
    <div class="card" style="padding:0;overflow:hidden;">
        <table class="data-table" id="cables-table">
            <thead>
                <tr>
                    <th style="width:90px;">Cable ID</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Type</th>
                    <th style="width:80px;">Cores</th>
                    <th style="width:80px;">Length (m)</th>
                    <th>Notes</th>
                    <th style="width:44px;"></th>
                </tr>
            </thead>
            <tbody id="cables-body">
                @foreach ($schedule->items as $i => $item)
                <tr>
                    <td><input type="text" name="items[{{ $i }}][cable_id]"  value="{{ $item->cable_id }}"        class="form-control" style="min-width:0;" maxlength="50"></td>
                    <td><input type="text" name="items[{{ $i }}][from_location]" value="{{ $item->from_location }}" class="form-control" style="min-width:0;" maxlength="200"></td>
                    <td><input type="text" name="items[{{ $i }}][to_location]"   value="{{ $item->to_location }}"   class="form-control" style="min-width:0;" maxlength="200"></td>
                    <td><input type="text" name="items[{{ $i }}][cable_type]"    value="{{ $item->cable_type }}"    class="form-control" style="min-width:0;" maxlength="100"></td>
                    <td><input type="text" name="items[{{ $i }}][cores]"         value="{{ $item->cores }}"         class="form-control" style="min-width:0;" maxlength="50"></td>
                    <td><input type="number" name="items[{{ $i }}][approx_length_m]" value="{{ $item->approx_length_m }}" class="form-control" style="min-width:0;" step="0.1" min="0"></td>
                    <td><input type="text" name="items[{{ $i }}][notes]"         value="{{ $item->notes }}"         class="form-control" style="min-width:0;" maxlength="500"></td>
                    <td><button type="button" onclick="this.closest('tr').remove()"
                                style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:1.1rem;">✕</button></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div style="margin-top:.75rem;display:flex;gap:.75rem;">
        <button type="button" class="btn btn-outline btn-sm" onclick="addRow()">+ Add Row</button>
        <button type="submit" class="btn btn-teal">Save Changes</button>
    </div>

</form>

@endsection

@push('scripts')
<script>
let rowIndex = {{ $schedule->items->count() }};

function addRow() {
    const i = rowIndex++;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" name="items[${i}][cable_id]"          class="form-control" style="min-width:0;" maxlength="50" placeholder="CAB-${String(i+1).padStart(3,'0')}"></td>
        <td><input type="text" name="items[${i}][from_location]"     class="form-control" style="min-width:0;" maxlength="200"></td>
        <td><input type="text" name="items[${i}][to_location]"       class="form-control" style="min-width:0;" maxlength="200"></td>
        <td><input type="text" name="items[${i}][cable_type]"        class="form-control" style="min-width:0;" maxlength="100"></td>
        <td><input type="text" name="items[${i}][cores]"             class="form-control" style="min-width:0;" maxlength="50"></td>
        <td><input type="number" name="items[${i}][approx_length_m]" class="form-control" style="min-width:0;" step="0.1" min="0"></td>
        <td><input type="text" name="items[${i}][notes]"             class="form-control" style="min-width:0;" maxlength="500"></td>
        <td><button type="button" onclick="this.closest('tr').remove()"
                    style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:1.1rem;">✕</button></td>
    `;
    document.getElementById('cables-body').appendChild(tr);
    tr.querySelector('input').focus();
}
</script>
@endpush
