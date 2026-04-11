<?php

namespace App\Core\Modules\OMManual;

use App\Core\AI\AIManager;
use App\Core\AI\Prompts\OmManualPrompt;
use App\Core\AI\Prompts\QuoteExtractionPrompt;
use App\Core\Modules\Projects\ProjectDataService;
use App\Core\Modules\Projects\ProjectService;
use App\Exceptions\AIGenerationException;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\EquipmentLineParserService;
use App\Services\EquipmentNormalizerService;
use App\Services\PdfTextExtractorService;
use App\Services\QuoteLineExtractorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * OmManualGeneratorService — enterprise O&M manual generation pipeline.
 *
 * Orchestrates a two-pass AI generation flow using AIManager:
 *
 *   Pass 1 — extractFromPdf():
 *     Upload a QuoteWerks PDF → local pipeline extracts + normalises equipment list
 *     → QuoteExtractionPrompt standardises product names only (no PDF sent to AI).
 *     Result is stored in extracted_data. User reviews before triggering Pass 2.
 *
 *   Pass 2 — generateContent():
 *     Takes the reviewed extracted_data → AI writes full O&M content
 *     (system overview, per-room equipment guides, maintenance schedule, contacts).
 *     DocxService builds the .docx from the generated_data.
 *
 * Activity logging is handled here so the controller stays thin.
 *
 * Local extraction pipeline:
 *   PDF → PdfTextExtractorService → QuoteLineExtractorService
 *       → EquipmentNormalizerService → EquipmentLineParserService
 *       → QuoteExtractionPrompt (name standardisation only)
 *
 * No PDF binary or base64 is ever sent to the AI model.
 */
class OmManualGeneratorService
{
    public function __construct(
        private readonly ProjectService             $projectService,
        private readonly ProjectDataService         $projectDataService,
        private readonly PdfTextExtractorService    $pdfExtractor,
        private readonly QuoteLineExtractorService  $lineExtractor,
        private readonly EquipmentNormalizerService $normalizer,
        private readonly EquipmentLineParserService $lineParser,
    ) {}

    // ── Pass 1: PDF extraction ────────────────────────────────────────────────

    /**
     * Store the uploaded PDF, run Pass 1 local extraction + AI name standardisation,
     * and persist an OmManual record.
     *
     * @param  User           $user
     * @param  string         $pdfPath       Absolute path to the uploaded temp PDF.
     * @param  string         $originalName  Original filename for display.
     * @param  Project|null   $project       Optional project to link.
     * @param  string|null    $provider      AI provider override.
     * @return OmManual                      Record with status='extracted'.
     *
     * @throws AIGenerationException
     * @throws RuntimeException
     */
    public function extractFromPdf(
        User     $user,
        string   $pdfPath,
        string   $originalName,
        ?Project $project  = null,
        ?string  $provider = null,
    ): OmManual {
        if (! file_exists($pdfPath) || filesize($pdfPath) === 0) {
            throw new RuntimeException('Could not read the uploaded PDF file.');
        }

        // Store a persistent copy of the source PDF
        $storedPath = 'om-sources/' . uniqid('om_', true) . '.pdf';
        Storage::disk('local')->put($storedPath, file_get_contents($pdfPath));

        try {
            $extracted = $this->runLocalExtraction($pdfPath, $provider);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($storedPath);
            throw $e;
        }

        return DB::transaction(function () use ($user, $project, $originalName, $storedPath, $extracted) {
            $manual = OmManual::create([
                'user_id'         => $user->id,
                'project_id'      => $project?->id,
                'project_name'    => $project?->name    ?? 'AV Installation',
                'project_ref'     => $project?->ref     ?? null,
                'client_name'     => $project?->client_name ?? null,
                'site_address'    => $project?->site_address ?? null,
                'source_filename' => $originalName,
                'source_path'     => $storedPath,
                'status'          => 'extracted',
                'extracted_data'  => $extracted,
                'generated_data'  => null,
                'filename'        => null,
            ]);

            if ($project !== null) {
                $this->projectService->logDocument(
                    project:      $project,
                    user:         $user,
                    documentType: 'O&M Manual',
                    documentId:   $manual->id,
                    verb:         'added',
                );
            }

            Log::info('OmManual Pass 1 complete', [
                'user_id'    => $user->id,
                'manual_id'  => $manual->id,
                'project_id' => $project?->id,
            ]);

            return $manual;
        });
    }

    // ── Pass 1: Project package extraction (no PDF upload) ───────────────────

    /**
     * Create an O&M manual draft from a reviewed ProjectPackage.
     * Uses the already-reviewed quote data linked to the project.
     */
    public function extractFromProjectPackage(User $user, ProjectPackage $package): OmManual
    {
        $project = $package->project;
        if (! $project) {
            throw new RuntimeException('This package is not linked to a project.');
        }

        // Prefer hardware_list (pre-classified by ExtractQuoteJob); fall back to
        // equipment_list filtered through the keyword heuristic for older packages.
        $extracted = $package->extracted_data ?? [];
        $equipment = ! empty($extracted['hardware_list'])
            ? $extracted['hardware_list']
            : $this->filterHardwareItems($package->equipment_list ?? ($extracted['equipment_list'] ?? []));

        $rooms = $this->buildRoomsFromEquipment($equipment);

        $extracted = [
            'project' => [
                'name'   => $project->name          ?? ($package->extracted_data['project_name'] ?? 'AV Installation'),
                'ref'    => $project->ref           ?? ($package->extracted_data['qw_number'] ?? ''),
                'client' => $project->client_name   ?? ($package->extracted_data['client_name'] ?? ''),
                'site'   => $project->site_address  ?? ($package->extracted_data['site_address'] ?? ''),
            ],
            'rooms' => $rooms,
        ];

        return DB::transaction(function () use ($user, $project, $package, $extracted) {
            $manual = OmManual::create([
                'user_id'         => $user->id,
                'project_id'      => $project->id,
                'project_name'    => $project->name,
                'project_ref'     => $project->ref,
                'client_name'     => $project->client_name,
                'site_address'    => $project->site_address,
                'source_filename' => $package->quote_filename,
                'source_path'     => $package->quote_path,
                'status'          => 'extracted',
                'extracted_data'  => $extracted,
                'generated_data'  => null,
                'filename'        => null,
            ]);

            $this->projectService->logDocument(
                project:      $project,
                user:         $user,
                documentType: 'O&M Manual',
                documentId:   $manual->id,
                verb:         'added',
            );

            return $manual;
        });
    }

    // ── Pass 1: ProjectDataService feed (replaces PDF extraction for project-linked O&Ms) ──

    /**
     * Build the extracted_data context array from ProjectDataService output.
     * Produces the exact shape that buildContentContext() and OmManualPrompt::forContent() expect.
     *
     * Called by OmManualController::generateFromProject() as the new Pass 1 replacement (D-07, D-08).
     * No PDF extraction, no review step — ProjectDataService data is already reviewed.
     *
     * @param  Project $project  The project to read canonical data from.
     * @return array             extracted_data array with keys: project_name, project_ref, client_name, site_address, notes, rooms[].
     */
    public function buildContextFromProjectData(Project $project): array
    {
        $data = $this->projectDataService->resolve($project);

        $rooms = array_map(function (array $room) {
            $filteredEquipment = $this->filterHardwareItems($room['equipment'] ?? []);

            return [
                'name'        => $room['name'] ?? $room['room_name'] ?? 'Unknown Room',
                'floor'       => $room['floor'] ?? null,
                'drawing_ref' => $room['drawing_ref'] ?? $room['room_ref'] ?? '',
                'equipment'   => array_map(fn ($eq) => [
                    'qty'          => (int) ($eq['quantity'] ?? $eq['qty'] ?? 1),
                    'name'         => $eq['name'] ?? $eq['description'] ?? '',
                    'description'  => $eq['description'] ?? $eq['name'] ?? '',
                    'model'        => $eq['model'] ?? '',
                    'manufacturer' => $eq['manufacturer'] ?? '',
                    'part_no'      => $eq['part_no'] ?? '',
                    'category'     => $eq['category'] ?? 'Other',
                ], $filteredEquipment),
            ];
        }, $data['rooms'] ?? []);

        return [
            'project_name' => $data['project']['name'] ?? '',
            'project_ref'  => $data['project']['quote_reference'] ?? '',
            'client_name'  => $data['project']['client_name'] ?? '',
            'site_address' => $data['project']['site_address'] ?? '',
            'notes'        => $data['survey']['h_and_s_notes'] ?? '',
            'rooms'        => $rooms,
        ];
    }

    // ── Pass 2: Full content generation ──────────────────────────────────────

    /**
     * Run Pass 2 AI generation from the reviewed extracted_data.
     * Returns the fully populated generated_data array (does NOT build docx).
     *
     * The controller / DocxService is responsible for building the .docx.
     *
     * @throws AIGenerationException
     */
    public function generateContent(OmManual $manual, User $user, ?string $provider = null): array
    {
        if (empty($manual->extracted_data)) {
            throw new RuntimeException("OmManual #{$manual->id} has no extracted data to generate from.");
        }

        $prompt  = OmManualPrompt::forContent();
        $context = $this->buildContentContext($manual);

        $generated = AIManager::run($prompt, $context, $provider);

        // Merge the original project fields into the generated result so the
        // DocxService has everything in one place.
        $generated['project'] ??= [
            'name'   => $manual->project_name,
            'ref'    => $manual->project_ref,
            'client' => $manual->client_name,
            'site'   => $manual->site_address,
        ];
        $generated['rooms_summary'] ??= $context['rooms'] ?? [];

        // Ensure safe defaults for all expected keys
        $generated['maintenance_schedule']   ??= [];
        $generated['fault_finding']          ??= [];
        $generated['network_devices']        ??= [];
        $generated['network_security_notes'] ??= [];
        $generated['manufacturer_support']   ??= [];
        $generated['warranty_summary']       ??= [];

        if ($manual->project !== null) {
            $this->projectService->logDocument(
                project:      $manual->project,
                user:         $user,
                documentType: 'O&M Manual',
                documentId:   $manual->id,
                verb:         'updated',
            );
        }

        Log::info('OmManual Pass 2 complete', [
            'user_id'   => $user->id,
            'manual_id' => $manual->id,
        ]);

        return $generated;
    }

    // ── Room sanitisation ─────────────────────────────────────────────────────

    /**
     * Normalise Pass 1 extraction rooms to the canonical shape.
     */
    public function sanitiseRooms(array $rooms): array
    {
        return array_values(array_map(function (array $room) {
            return [
                'name'        => $room['name']        ?? 'Unknown Room',
                'floor'       => $room['floor']       ?? null,
                'drawing_ref' => $room['drawing_ref'] ?? '',
                'equipment'   => array_values(array_map(
                    fn (array $eq) => [
                        'qty'          => (int) ($eq['qty']         ?? $eq['quantity'] ?? 1),
                        'name'         => $eq['name']               ?? $eq['description'] ?? '',
                        'description'  => $eq['description']        ?? $eq['name']        ?? '',
                        'model'        => $eq['model']               ?? '',
                        'manufacturer' => $eq['manufacturer']        ?? '',
                        'part_no'      => $eq['part_no']             ?? '',
                        'category'     => $eq['category']            ?? 'Other',
                    ],
                    is_array($room['equipment'] ?? null) ? $room['equipment'] : []
                )),
            ];
        }, $rooms));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Local extraction pipeline — no PDF binary is ever sent to the AI.
     *
     * Pipeline:
     *   PDF → PdfTextExtractorService (plain text)
     *       → QuoteLineExtractorService (filter quantity-prefixed lines)
     *       → EquipmentNormalizerService (manufacturer prefix + title-case)
     *       → EquipmentLineParserService (split into {quantity, name} structs)
     *       → QuoteExtractionPrompt via AIManager (product name standardisation)
     *
     * Returns the standardised equipment list as:
     *   ['equipment' => [['quantity' => int, 'name' => string], ...]]
     *
     * @throws AIGenerationException
     */
    private function runLocalExtraction(string $absolutePdfPath, ?string $provider): array
    {
        $text  = $this->pdfExtractor->extract($absolutePdfPath);
        $lines = $this->lineExtractor->extractEquipmentLines($text);
        $lines = $this->normalizer->normalize($lines);
        $items = $this->lineParser->parse($lines);

        $result = AIManager::run(new QuoteExtractionPrompt($items), [], $provider);

        if (empty($result)) {
            throw new AIGenerationException('AI returned an empty extraction response.');
        }

        return $result;
    }

    /**
     * Build the context array for OmManualPrompt::forContent().
     *
     * Supports two extracted_data shapes for backward compatibility:
     *
     *   New shape (project-linked O&Ms via buildContextFromProjectData):
     *     { project_name, project_ref, client_name, site_address, notes, rooms: [{name, floor, drawing_ref, equipment: [...]}] }
     *     Detected by the presence of a 'rooms' key → used directly.
     *
     *   Legacy shape (PDF-uploaded O&Ms via extractFromPdf / extractFromProjectPackage):
     *     { equipment: [{quantity, name}] }
     *     Detected by the presence of an 'equipment' key → wrapped in a single 'General' room.
     */
    private function buildContentContext(OmManual $manual): array
    {
        $extractedData = $manual->extracted_data ?? [];

        // ── New shape: rooms key present (project-linked O&Ms via ProjectDataService) ──
        if (isset($extractedData['rooms']) && is_array($extractedData['rooms'])) {
            return [
                'project_name' => $extractedData['project_name'] ?? $manual->project_name ?? 'AV Installation',
                'project_ref'  => $extractedData['project_ref']  ?? $manual->project_ref  ?? '',
                'client_name'  => $extractedData['client_name']  ?? $manual->client_name  ?? '',
                'site_address' => $extractedData['site_address'] ?? $manual->site_address ?? '',
                'notes'        => $extractedData['notes']        ?? '',
                'rooms'        => $extractedData['rooms'],
            ];
        }

        // ── Legacy shape: flat equipment list (PDF-uploaded O&Ms — backward compatibility) ──
        $equipment = $extractedData['equipment'] ?? [];
        $equipment = array_values(array_filter($equipment, function ($item) {
            if (! is_array($item)) {
                return true;
            }
            $category = strtolower((string) ($item['category'] ?? ''));
            // Only hardware should feed O&M lists. If no category is set, keep the item.
            return $category === '' || $category === 'hardware';
        }));

        // Map standardised {quantity, name} items to the room-equipment shape
        // expected by the Pass 2 prompt. All items are placed in one "General"
        // room since room assignment is not available from local-only extraction.
        $roomEquipment = array_map(
            static fn (array $item): array => [
                'qty'          => $item['quantity'] ?? 1,
                'name'         => $item['name']     ?? '',
                'description'  => $item['name']     ?? '',
                'model'        => '',
                'manufacturer' => '',
                'part_no'      => '',
                'category'     => 'Other',
            ],
            $equipment,
        );

        $rooms = empty($roomEquipment) ? [] : [[
            'name'        => 'General',
            'floor'       => null,
            'drawing_ref' => '',
            'equipment'   => $roomEquipment,
        ]];

        return [
            'project_name' => $manual->project_name ?? 'AV Installation',
            'project_ref'  => $manual->project_ref  ?? '',
            'client_name'  => $manual->client_name  ?? '',
            'site_address' => $manual->site_address ?? '',
            'notes'        => '',
            'rooms'        => $rooms,
        ];
    }

    /**
     * Filter to hardware-only items using category/keyword heuristics.
     */
    private function filterHardwareItems(array $items): array
    {
        $filtered = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            // item_type is set by ExtractQuoteJob::classifyItemType() on new imports.
            $itemType = $item['item_type'] ?? '';
            if ($itemType === 'consumable' || $itemType === 'professional_service') {
                continue;
            }
            if ($itemType === 'hardware') {
                $filtered[] = $item;
                continue;
            }

            // Legacy fallback: category/keyword heuristic for packages without item_type.
            $category = strtolower((string) ($item['category'] ?? ''));
            $desc = strtolower((string) ($item['model'] ?? '') . ' ' . (string) ($item['description'] ?? '') . ' ' . (string) ($item['name'] ?? ''));

            if (in_array($category, ['cables', 'consumables', 'services', 'option'], true)) {
                continue;
            }

            if ($category === '') {
                if ($this->containsAny($desc, ['optional', 'option'])) {
                    continue;
                }
                if ($this->containsAny($desc, ['cable', 'cat5', 'cat6', 'cat6a', 'hdmi', 'usb', 'patch lead', 'ethernet'])) {
                    continue;
                }
                if ($this->containsAny($desc, ['consumable', 'fixing', 'screw', 'bolt', 'tie', 'velcro', 'label', 'tape'])) {
                    continue;
                }
                if ($this->containsAny($desc, ['install', 'installation', 'commission', 'programming', 'configuration', 'survey', 'project management', 'travel'])) {
                    continue;
                }
            }

            $filtered[] = $item;
        }

        return $filtered;
    }

    /**
     * Build room groups from equipment list.
     */
    private function buildRoomsFromEquipment(array $equipment): array
    {
        $rooms = [];
        foreach ($equipment as $item) {
            $room = trim((string) ($item['location'] ?? $item['room'] ?? $item['area'] ?? 'General'));
            if ($room === '') {
                $room = 'General';
            }
            $rooms[$room][] = [
                'qty'          => (int) ($item['qty'] ?? $item['quantity'] ?? 1),
                'description'  => (string) ($item['description'] ?? $item['name'] ?? $item['model'] ?? ''),
                'model'        => (string) ($item['model'] ?? ''),
                'manufacturer' => (string) ($item['manufacturer'] ?? ''),
                'part_no'      => (string) ($item['part_no'] ?? $item['part_number'] ?? ''),
                'category'     => (string) ($item['category'] ?? ''),
            ];
        }

        ksort($rooms, SORT_NATURAL | SORT_FLAG_CASE);

        $result = [];
        foreach ($rooms as $name => $items) {
            $result[] = [
                'name'        => $name,
                'drawing_ref' => '',
                'equipment'   => $items,
            ];
        }

        return $result;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($haystack, $n)) {
                return true;
            }
        }
        return false;
    }
}
