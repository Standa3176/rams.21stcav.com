{{--
    Engineer reference files admin card (quick task 260601-r4c).

    Project-wide artifact section — distinct from per-stage cards. Lists
    every ProjectReferenceFile on this project with upload form + delete
    buttons. Authed-user actions only (server-side gate in controller).

    Required scope: $project.
--}}
@php
    /** @var \App\Models\Project $project */
    $referenceFiles = $project->referenceFiles()->with('uploadedByUser')->orderByDesc('uploaded_at')->get();

    $kindChip = function (string $filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match (true) {
            $ext === 'pdf'                                    => ['📄', 'PDF'],
            in_array($ext, ['png','jpg','jpeg','webp'], true) => ['🖼', 'IMG'],
            in_array($ext, ['dwg','dxf'], true)               => ['📐', 'CAD'],
            in_array($ext, ['xlsx','xls','csv'], true)        => ['📊', 'SHEET'],
            in_array($ext, ['docx','doc'], true)              => ['📝', 'DOC'],
            default                                           => ['📎', 'FILE'],
        };
    };

    $humanSize = function (int $bytes) {
        if ($bytes < 1024)        return $bytes . ' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024) . ' KB';
        return round($bytes / (1024 * 1024), 1) . ' MB';
    };
@endphp

<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mt-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-base font-semibold text-gray-900">
            📎 Engineer Reference Files
            <span class="text-sm font-normal text-gray-500">({{ $referenceFiles->count() }})</span>
        </h2>
    </div>

    <p class="text-sm text-gray-600 mb-4">
        Site plans, CAD drawings, cable schedules, method statements — anything the on-site engineer
        needs in their hand on install day. Appears in both the worksheet and survey public links.
        Allowed: PDF, PNG/JPEG/WEBP, DWG/DXF, XLSX/XLS, DOCX/DOC, CSV. Max 20 MB per file.
    </p>

    {{-- Upload form --}}
    <form method="POST"
          action="{{ route('projects.reference-files.store', $project) }}"
          enctype="multipart/form-data"
          class="mb-5 space-y-2">
        @csrf
        <div class="flex flex-col sm:flex-row gap-2">
            <input type="file" name="file" required
                   class="flex-1 text-sm border border-gray-300 rounded-md p-2 bg-white">
            <input type="text" name="label" maxlength="200"
                   placeholder="Optional label (e.g. Site plan)"
                   class="flex-1 text-sm border border-gray-300 rounded-md p-2">
            <button type="submit" class="btn btn-teal">
                Upload
            </button>
        </div>
        @error('file')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror
    </form>

    {{-- Files list --}}
    @if($referenceFiles->isEmpty())
        <p class="text-sm text-gray-500 italic">No reference files uploaded yet.</p>
    @else
        <ul class="divide-y divide-gray-100 border border-gray-100 rounded-md">
            @foreach($referenceFiles as $f)
                @php [$chipEmoji, $chipText] = $kindChip($f->original_filename); @endphp
                <li class="flex items-center gap-3 p-3 text-sm">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 text-gray-700 rounded text-xs font-medium">
                        <span>{{ $chipEmoji }}</span><span>{{ $chipText }}</span>
                    </span>
                    <a href="{{ route('projects.reference-files.show', [$project, $f]) }}"
                       class="flex-1 text-gray-900 hover:text-teal-700 hover:underline truncate"
                       target="_blank">
                        {{ $f->original_filename }}
                        @if($f->label)
                            <span class="text-gray-500 text-xs">— {{ $f->label }}</span>
                        @endif
                    </a>
                    <span class="text-xs text-gray-500 hidden sm:inline">
                        {{ $humanSize((int) $f->size_bytes) }}
                    </span>
                    <span class="text-xs text-gray-500 hidden md:inline">
                        {{ $f->uploadedByUser?->name ?? '—' }}
                    </span>
                    <span class="text-xs text-gray-500 hidden lg:inline">
                        {{ $f->uploaded_at?->diffForHumans() }}
                    </span>
                    <form method="POST"
                          action="{{ route('projects.reference-files.destroy', [$project, $f]) }}"
                          class="m-0"
                          data-confirm="Delete file &quot;{{ $f->original_filename }}&quot;? This cannot be undone."
                          data-confirm-label="Delete"
                          data-confirm-danger="1">
                        @csrf @method('DELETE')
                        <button type="submit"
                                class="text-xs text-red-600 hover:text-red-800 hover:underline">
                            Delete
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</div>
