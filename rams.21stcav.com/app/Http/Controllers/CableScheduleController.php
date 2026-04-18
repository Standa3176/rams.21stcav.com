<?php

namespace App\Http\Controllers;

use App\Jobs\BuildCableScheduleJob;
use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Project;
use App\Services\CableScheduleGeneratorService;
use App\Services\PdfTextExtractorService;
use App\Services\QuoteLineExtractorService;
use App\Services\WorkerMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CableScheduleController extends Controller
{
    public function __construct(
        private readonly PdfTextExtractorService   $pdfExtractor,
        private readonly QuoteLineExtractorService $lineExtractor,
        private readonly CableScheduleGeneratorService $deterministicGenerator,
        private readonly WorkerMonitorService      $workerMonitor,
    ) {}

    public function index(Request $request): View
    {
        $isAdmin     = auth()->user()->isAdmin();
        $showDeleted = $isAdmin && $request->boolean('show_deleted');

        if ($showDeleted) {
            $schedules = CableSchedule::onlyTrashed()->with('user')->withCount('items')->latest('deleted_at')->paginate(15);
            return view('cable-schedule.index', compact('schedules', 'isAdmin', 'showDeleted'));
        }

        $schedules = CableSchedule::where('user_id', auth()->id())
            ->withCount('items')
            ->latest()
            ->paginate(15);

        return view('cable-schedule.index', compact('schedules', 'isAdmin', 'showDeleted'));
    }

    public function create(): View
    {
        return view('cable-schedule.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'project_name' => ['required', 'string', 'max:200'],
            'project_ref'  => ['nullable', 'string', 'max:50'],
            'client_name'  => ['nullable', 'string', 'max:150'],
            'quote_pdf'    => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $pdf         = $request->file('quote_pdf');
        $filename    = $pdf->getClientOriginalName();

        // Deterministic extraction pipeline — no AI generation in this flow.
        $text  = $this->pdfExtractor->extract($pdf->getRealPath());
        $lines = $this->lineExtractor->extractEquipmentLines($text);
        $cables = $this->deterministicGenerator->buildRowsFromEquipmentLines($lines, 'Quote Line');

        if (empty($cables)) {
            return back()
                ->withInput()
                ->with('error', 'No cable-relevant hardware lines were found in the uploaded quote.');
        }

        $schedule = DB::transaction(function () use ($request, $filename, $cables) {
            $s = CableSchedule::create([
                'user_id'         => auth()->id(),
                'project_name'    => $request->input('project_name'),
                'project_ref'     => $request->input('project_ref'),
                'client_name'     => $request->input('client_name'),
                'source_filename' => $filename,
                'status'          => 'draft',
            ]);

            foreach ($cables as $i => $cable) {
                CableScheduleItem::create([
                    'cable_schedule_id' => $s->id,
                    'cable_id'          => $cable['cable_id']        ?? null,
                    'from_location'     => $cable['from_location']   ?? null,
                    'to_location'       => $cable['to_location']     ?? null,
                    'cable_type'        => $cable['cable_type']      ?? null,
                    'cores'             => $cable['cores']           ?? null,
                    'approx_length_m'   => $cable['approx_length_m'] ?? null,
                    'notes'             => $cable['notes']           ?? null,
                    'sort_order'        => $cable['sort_order']      ?? ($i + 1),
                ]);
            }

            return $s;
        });

        return redirect()->route('cable-schedules.edit', $schedule)
            ->with('success', 'Cable schedule generated — review and adjust below.');
    }

    public function edit(CableSchedule $cableSchedule): View
    {
        abort_unless($cableSchedule->user_id === auth()->id(), 403);

        $cableSchedule->load('items');

        return view('cable-schedule.edit', ['schedule' => $cableSchedule]);
    }

    public function update(Request $request, CableSchedule $cableSchedule): RedirectResponse
    {
        abort_unless($cableSchedule->user_id === auth()->id(), 403);

        $request->validate([
            'status'            => ['nullable', 'in:draft,final'],
            'items'             => ['nullable', 'array'],
            'items.*.cable_id'        => ['nullable', 'string', 'max:50'],
            'items.*.from_location'   => ['nullable', 'string', 'max:200'],
            'items.*.to_location'     => ['nullable', 'string', 'max:200'],
            'items.*.cable_type'      => ['nullable', 'string', 'max:100'],
            'items.*.cores'           => ['nullable', 'string', 'max:50'],
            'items.*.approx_length_m' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes'           => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $cableSchedule) {
            $cableSchedule->update(['status' => $request->input('status', $cableSchedule->status)]);

            // Replace all items with the submitted set
            $cableSchedule->items()->delete();

            foreach ($request->input('items', []) as $i => $item) {
                CableScheduleItem::create(array_merge($item, [
                    'cable_schedule_id' => $cableSchedule->id,
                    'sort_order'        => $i,
                ]));
            }
        });

        return redirect()->route('cable-schedules.edit', $cableSchedule)
            ->with('success', 'Cable schedule saved.');
    }

    public function destroy($cableSchedule): RedirectResponse
    {
        $record = CableSchedule::findOrFail($cableSchedule);
        abort_unless($record->user_id === auth()->id() || auth()->user()->isAdmin(), 403);

        $record->delete();

        return redirect()->route('cable-schedules.index')->with('success', 'Cable schedule deleted.');
    }

    public function restore(int $id): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $record = CableSchedule::withTrashed()->findOrFail($id);
        $record->restore();

        return back()->with('success', 'Cable schedule restored.');
    }

    public function forceDestroy(int $id): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $record = CableSchedule::onlyTrashed()->findOrFail($id);
        $record->forceDelete();

        Log::info('CableScheduleController: permanently deleted', ['id' => $id, 'admin_id' => auth()->id()]);

        return back()->with('success', 'Cable schedule permanently deleted.');
    }

    // ── generateFromProject ───────────────────────────────────────────────────

    /**
     * Create a CableSchedule for the given project and queue generation.
     *
     * @param  Project $project
     * @return RedirectResponse
     */
    public function generateFromProject(Project $project): RedirectResponse
    {
        abort_if($project->user_id !== auth()->id() && ! auth()->user()?->isAdmin(), 403);

        $schedule = CableSchedule::create([
            'user_id'      => auth()->id(),
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'project_ref'  => $project->quote_reference ?? $project->ref ?? null,
            'client_name'  => $project->client_name,
            'status'       => CableSchedule::STATUS_GENERATING,
        ]);

        Log::info('CableScheduleController: generateFromProject queued', [
            'project_id'        => $project->id,
            'cable_schedule_id' => $schedule->id,
        ]);

        $this->workerMonitor->ensureRunning();
        BuildCableScheduleJob::dispatch($schedule->id);

        return back()->with('success', 'Cable schedule generation queued — edit items when ready.');
    }

    // ── status (JSON polling endpoint) ────────────────────────────────────────

    /**
     * Return the current status of a cable schedule as JSON for polling.
     *
     * @param  CableSchedule $cableSchedule
     * @return JsonResponse
     */
    public function status(CableSchedule $cableSchedule): JsonResponse
    {
        abort_if($cableSchedule->user_id !== auth()->id() && ! auth()->user()?->isAdmin(), 403);

        $downloadUrl = in_array($cableSchedule->status, [CableSchedule::STATUS_DRAFT, CableSchedule::STATUS_FINAL])
            ? route('cable-schedules.download', $cableSchedule)
            : null;

        return response()->json([
            'status'       => $cableSchedule->status,
            'download_url' => $downloadUrl,
            'error'        => $cableSchedule->error_message ?? null,
        ]);
    }

    // ── download ──────────────────────────────────────────────────────────────

    /**
     * Stream the generated XLSX file to the browser.
     *
     * @param  CableSchedule $cableSchedule
     * @return BinaryFileResponse|RedirectResponse
     */
    public function download(CableSchedule $cableSchedule): BinaryFileResponse|RedirectResponse
    {
        abort_if($cableSchedule->user_id !== auth()->id() && ! auth()->user()?->isAdmin(), 403);

        // Resolve filename: source_filename is the reliable column (always exists on table).
        // Fall back to filename for forward compatibility if that column is added later.
        $outputFilename = $cableSchedule->source_filename
            ?? $cableSchedule->filename
            ?? null;

        if (! $outputFilename) {
            return back()->with('error', 'No file available yet. Please generate the schedule first.');
        }

        // Generated files are stored under storage/app/private/cable-schedules/
        $absolutePath = storage_path('app/private/cable-schedules/' . $outputFilename);

        if (! file_exists($absolutePath)) {
            return back()->with('error', 'Document file not found on disk.');
        }

        // Dynamic content type by extension
        $ext = strtolower(pathinfo($outputFilename, PATHINFO_EXTENSION));
        $contentType = match ($ext) {
            'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv'   => 'text/csv',
            default => 'application/octet-stream',
        };

        return response()->download($absolutePath, $outputFilename, [
            'Content-Type' => $contentType,
        ]);
    }

    // ── retryGeneration ──────────────────────────────────────────────────────

    public function retryGeneration(CableSchedule $cableSchedule): RedirectResponse
    {
        abort_if($cableSchedule->user_id !== auth()->id() && ! auth()->user()?->isAdmin(), 403);

        if ($cableSchedule->status === CableSchedule::STATUS_GENERATING) {
            return back()->with('error', 'This cable schedule is already being generated. Please wait.');
        }

        // Clear old items for clean re-generation
        DB::table('cable_schedule_items')->where('cable_schedule_id', $cableSchedule->id)->delete();

        $cableSchedule->update([
            'status' => CableSchedule::STATUS_GENERATING,
        ]);

        app(WorkerMonitorService::class)->ensureRunning();
        BuildCableScheduleJob::dispatch($cableSchedule->id);

        Log::info('CableScheduleController: regeneration queued', [
            'cable_schedule_id' => $cableSchedule->id,
            'user_id'           => auth()->id(),
        ]);

        return back()->with('success', 'Cable schedule regeneration queued.');
    }
}
