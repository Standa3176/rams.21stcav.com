<?php

namespace App\Http\Controllers;

use App\Core\AI\AIManager;
use App\Core\AI\Prompts\CableSchedulePrompt;
use App\Exceptions\AIGenerationException;
use App\Jobs\BuildCableScheduleJob;
use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Models\Project;
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
        $providerKey = config('ai.default', 'claude');

        // Local extraction pipeline — no PDF binary is sent to AI.
        $text  = $this->pdfExtractor->extract($pdf->getRealPath());
        $lines = $this->lineExtractor->extractEquipmentLines($text);

        try {
            $result = AIManager::run(
                new CableSchedulePrompt($lines),
                [],
                $providerKey,
            );
        } catch (AIGenerationException $e) {
            return back()->withInput()->with('error',
                'Cable schedule generation failed: ' . $e->getMessage()
            );
        }

        $cables = $result['cables'] ?? [];

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
                    'sort_order'        => $i,
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
            'error'        => $cableSchedule->error_message,
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

        if (! $cableSchedule->filename) {
            return back()->with('error', 'No file available yet. Please generate the schedule first.');
        }

        $diskPath = 'cable-schedules/' . $cableSchedule->filename;

        if (! Storage::disk('local')->exists($diskPath)) {
            return back()->with('error', 'Document file not found on disk.');
        }

        return Storage::disk('local')->download(
            $diskPath,
            $cableSchedule->filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
