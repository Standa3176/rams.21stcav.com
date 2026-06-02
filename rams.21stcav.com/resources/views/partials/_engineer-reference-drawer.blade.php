{{--
    Engineer reference files drawer (quick task 260601-r4c).

    Shared partial consumed by:
      - resources/views/worksheets/public-show.blade.php (engineer worksheet
        public link), inserted above the SITE LOGISTICS project-level drawer.
      - resources/views/surveys/show.blade.php (engineer survey public link),
        inserted above Site Logistics on the rooms-list screen.

    Required params:
      - $files          \Illuminate\Support\Collection<ProjectReferenceFile>
      - $serveRouteName string (route name: public-worksheet.files.serve OR
                                                public-survey.files.serve)
      - $token          string (UUID survey/worksheet access token)

    When $files is empty the drawer renders nothing — keeps the page clean
    on projects with no reference uploads.

    NO new global CSS — minimal inline styling using the brand teal #178A95
    so the drawer renders identically on both pages without depending on
    .room-drawer rules that only exist in the worksheet view.
--}}

@php
    /** @var \Illuminate\Support\Collection $files */
    /** @var string $serveRouteName */
    /** @var string $token */

    $files = $files ?? collect();

    $kindOf = function ($file) {
        $ext = strtolower(pathinfo($file->original_filename, PATHINFO_EXTENSION));
        if ($ext === 'pdf') return 'pdf';
        if (in_array($ext, ['png','jpg','jpeg','webp'], true)) return 'image';
        if (in_array($ext, ['dwg','dxf'], true)) return 'cad';
        if (in_array($ext, ['xlsx','xls','csv'], true)) return 'sheet';
        if (in_array($ext, ['docx','doc'], true)) return 'doc';
        return 'other';
    };

    $chipFor = [
        'pdf'   => '📄 PDF',
        'image' => '🖼 IMG',
        'cad'   => '📐 CAD',
        'sheet' => '📊 SHEET',
        'doc'   => '📝 DOC',
        'other' => '📎 FILE',
    ];

    $humanSize = function (int $bytes) {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024) . ' KB';
        return round($bytes / (1024 * 1024), 1) . ' MB';
    };

    $byKind = $files->groupBy($kindOf);
    // Display order (PDFs first so the engineer's primary reference renders inline immediately).
    $displayOrder = ['pdf', 'image', 'cad', 'sheet', 'doc', 'other'];
@endphp

@if($files->isNotEmpty())
<details class="erf-drawer"
         style="background:#fff;border:1.5px solid rgba(23,138,149,.35);border-left:4px solid #178A95;border-radius:10px;margin-bottom:1rem;overflow:hidden;">
    <summary
        style="list-style:none;cursor:pointer;padding:.7rem 1rem;display:flex;align-items:center;justify-content:space-between;min-height:44px;font-size:.9rem;font-weight:700;background:rgba(23,138,149,.06);color:#0B3C45;user-select:none;">
        <span>📎 Engineer Reference Files ({{ $files->count() }})</span>
        <span style="font-size:1.1rem;color:#178A95;">▾</span>
    </summary>
    <div style="padding:.85rem 1rem;">

        @foreach($displayOrder as $kind)
            @php $group = $byKind->get($kind, collect()); @endphp
            @if($group->isNotEmpty())
                <div style="margin-bottom:.85rem;">
                    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#178A95;margin-bottom:.4rem;">
                        {{ $chipFor[$kind] }}
                    </div>

                    @if($kind === 'pdf')
                        @foreach($group as $f)
                            @php $url = route($serveRouteName, ['token' => $token, 'file' => $f->id]); @endphp
                            <details style="border:1px solid #E5E7EB;border-radius:8px;margin-bottom:.45rem;background:#FAFAFA;">
                                <summary style="cursor:pointer;padding:.55rem .7rem;min-height:44px;font-size:.88rem;color:#0B3C45;display:flex;align-items:center;justify-content:space-between;">
                                    <span>📄 {{ $f->original_filename }} ({{ $humanSize((int) $f->size_bytes) }})</span>
                                    <span style="font-size:.95rem;color:#178A95;">▾</span>
                                </summary>
                                <div style="padding:.6rem .7rem;background:#fff;">
                                    <iframe src="{{ $url }}#view=FitH"
                                            style="width:100%;height:80vh;border:0;background:#fff;border-radius:8px;"
                                            loading="lazy"></iframe>
                                    <div style="margin-top:.5rem;text-align:right;">
                                        <a href="{{ $url }}" download
                                           style="display:inline-block;padding:.45rem .85rem;background:#178A95;color:#fff;border-radius:6px;text-decoration:none;font-size:.82rem;font-weight:600;min-height:44px;line-height:1;">
                                            ↓ Download
                                        </a>
                                    </div>
                                </div>
                            </details>
                        @endforeach

                    @elseif($kind === 'image')
                        <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                            @foreach($group as $f)
                                @php $url = route($serveRouteName, ['token' => $token, 'file' => $f->id]); @endphp
                                <a href="{{ $url }}" target="_blank" rel="noopener"
                                   title="{{ $f->original_filename }} ({{ $humanSize((int) $f->size_bytes) }})"
                                   style="display:inline-block;">
                                    <img src="{{ $url }}" alt="{{ $f->original_filename }}"
                                         style="max-width:220px;max-height:160px;border-radius:6px;border:1px solid #E5E7EB;display:block;" />
                                </a>
                            @endforeach
                        </div>

                    @else
                        @foreach($group as $f)
                            @php $url = route($serveRouteName, ['token' => $token, 'file' => $f->id]); @endphp
                            <a href="{{ $url }}"
                               style="display:flex;align-items:center;gap:.6rem;padding:.6rem .75rem;margin-bottom:.4rem;background:#FAFAFA;border:1px solid #E5E7EB;border-radius:8px;text-decoration:none;color:#0B3C45;font-size:.88rem;min-height:44px;">
                                <span style="font-weight:700;color:#178A95;white-space:nowrap;">{{ $chipFor[$kind] }}</span>
                                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $f->original_filename }}</span>
                                <span style="color:#6B7280;font-size:.78rem;white-space:nowrap;">{{ $humanSize((int) $f->size_bytes) }}</span>
                                <span style="color:#178A95;font-size:.78rem;white-space:nowrap;">Tap to download</span>
                            </a>
                        @endforeach
                    @endif
                </div>
            @endif
        @endforeach

    </div>
</details>
@endif
