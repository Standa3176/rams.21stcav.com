<?php

namespace App\Core\Modules\OMManual;

use App\Core\AI\AIManager;
use App\Core\AI\Prompts\OmManualPrompt;
use App\Core\AI\Prompts\OmRoomOverviewPrompt;
use App\Core\AI\Prompts\QuoteExtractionPrompt;
use App\Core\Modules\Projects\ProjectDataService;
use App\Core\Modules\Projects\ProjectService;
use App\Exceptions\AIGenerationException;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\EquipmentLineParserService;
use App\Services\EquipmentNormaliserService;
use App\Services\EquipmentNormalizerService;
use App\Services\ManufacturerSupportResolverService;
use App\Services\OmManualValidationService;
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
    private const NON_PHYSICAL_ROOMS = [
        'licencing', 'licensing', 'cabling', 'cables', 'professional services',
        'support services', 'consumables', 'services', 'options', 'delivery', 'carriage',
        'additional', 'ancillaries', 'ancillary', 'accessories', 'miscellaneous', 'misc',
        'sundries', 'general items', 'fixings', 'unistrut', 'unallocated',
    ];

    private const LABOUR_OR_DOCUMENT_KEYWORDS = [
        'install', 'installation', 'commission', 'commissioning', 'programming', 'configuration',
        'project management', 'survey', 'travel', 'labour', 'training', 'handover', 'design',
        'engineering', 'support', 'drawing', 'document', 'manual', 'additional',
        // Phase 8 fix — logistics / shipping lines should never appear as
        // "installed equipment" in the OM. QuoteWerks sometimes assigns a
        // delivery line to a meeting room, so room-name filtering alone
        // doesn't catch it.
        'delivery', 'shipment', 'shipping', 'carriage', 'freight',
    ];

    private const CABLE_OR_CONSUMABLE_KEYWORDS = [
        'cable', 'cat5', 'cat6', 'cat6a', 'hdmi lead', 'patch lead', 'ethernet',
        'fibre', 'fiber', 'usb cable', 'displayport cable', '305m', '100m', '50m',
        'reel', 'drum', 'consumable', 'fixing', 'screw', 'bolt', 'anchor', 'tie',
        'velcro', 'tape', 'label',
        // Structural fixings & ancillaries (not maintainable AV items)
        'unistrut', 'lindapter', 'square washer', 'spring nut', 'threaded rod',
        'end cap', 'm8 nut', 'm8 lindapter', 'm8 square', 'm8 spring', 'm8 threaded',
        'extension lead', 'surge protected', '4 gang', 'gang surge',
    ];

    /**
     * Passive parts that share keywords with active devices (e.g. "Camera Shelf",
     * "Logitech Camera Splitter", "Logitech Rally Camera power Adapter") but are
     * not network-capable and don't need active maintenance. Used to gate both
     * isNetworkCapable() and buildMaintenanceSchedule() so accessories don't
     * inherit camera/audio/network maintenance plans.
     */
    private const NON_NETWORK_PARTS = [
        'shelf', 'splitter', 'adapter', 'mount', 'joiner', 'coupler',
        'bracket', 'rail', 'plate', 'tilt', 'extension lead',
        'unistrut', 'lindapter', 'washer', 'spring nut', 'threaded rod',
        'mic pod', 'tv mount', 'cat coupler',
    ];

    private const EXISTING_REUSE_KEYWORDS = [
        'existing', 'exisiting', 'utilise existing', 'utilise client', 'client existing',
        'retained', 'reuse',
    ];

    public function __construct(
        private readonly ProjectService                    $projectService,
        private readonly ProjectDataService                $projectDataService,
        private readonly PdfTextExtractorService           $pdfExtractor,
        private readonly QuoteLineExtractorService         $lineExtractor,
        private readonly EquipmentNormalizerService        $normalizer,
        private readonly EquipmentLineParserService        $lineParser,
        private readonly OmManualValidationService         $validator,
        private readonly ManufacturerSupportResolverService $manufacturerResolver,
        private readonly EquipmentNormaliserService        $equipmentNormaliser,
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
        $rooms = $this->buildRoomsFromProjectAndPackage($project, $data);

        // ── Enrich rooms with content pack descriptions ───────────────────────
        // Load per-room prose descriptions from the most recent ProjectPackage
        // linked to this project (extracted/reviewed by the PM via review form).
        $descriptionsByRoom = [];
        $linkedPackage = $project->packages()
            ->latest()
            ->first();

        if ($linkedPackage !== null) {
            foreach ((array) ($linkedPackage->extracted_data['room_overviews'] ?? []) as $ro) {
                $roomName = trim((string) ($ro['room'] ?? ''));
                // Phase 22.1 D-01: room_overviews carries 4 canonical keys
                // (room / overview / works_summary / solution_type_id).
                // The legacy 'description' key is actively stripped by
                // RamsReviewDataService::normaliseRoomOverviews(), so any
                // package extracted post-Phase-22.1 (every project since late
                // April 2026) returns empty for 'description'. The validator
                // then rejects O&M generation with "narrative for {room}
                // missing". Read 'overview' as canonical with 'description'
                // as legacy fallback for pre-22.1 records.
                $desc = trim((string) ($ro['overview'] ?? $ro['description'] ?? ''));
                if ($roomName !== '' && $desc !== '') {
                    $descriptionsByRoom[$roomName] = $desc;
                }
            }
        }

        // Merge descriptions into each room entry. The validator checks
        // 'narrative' (Tier 1 spec); we populate both 'description' (legacy
        // template back-compat) and 'narrative' (canonical) from the same
        // source so existing templates render and the validator passes.
        $rooms = array_map(function (array $room) use ($descriptionsByRoom): array {
            $name        = $room['name'] ?? '';
            $description = $descriptionsByRoom[$name] ?? '';
            $room['description'] = $description;
            $room['narrative']   = $description;
            return $room;
        }, $rooms);

        // Load scope_of_works from the package if available.
        $scopeOfWorks = '';
        if ($linkedPackage !== null) {
            $scopeOfWorks = trim((string) ($linkedPackage->extracted_data['scope_of_works'] ?? ''));
        }

        // Phase 6 — appendices flow through the generator data pipeline.
        // Drawings populate Section 3 (register) and Appendix A; user guides
        // populate Appendix B. The Phase 1 validator enforces ≥ 1 drawing.
        $appendices  = $this->buildAppendixSections($project);

        return [
            'project_name'   => $data['project']['name'] ?? '',
            'project_ref'    => $data['project']['quote_reference'] ?? $data['project']['ref'] ?? '',
            'client_name'    => $data['project']['client_name'] ?? '',
            'site_address'   => $data['project']['site_address'] ?? '',
            'notes'          => $data['survey']['h_and_s_notes'] ?? '',
            'scope_of_works' => $scopeOfWorks,
            // Phase 1+4 — auto-populated date fields the validator requires.
            // document_date is always today; handover_date is pulled from
            // projects.handover_date (Phase 4 column) and is the gate that
            // forces the user to set it before generation can proceed.
            'document_date'  => now()->format('d M Y'),
            'handover_date'  => $project->handover_date?->format('d M Y') ?? '',
            'rooms'          => $rooms,
            'drawings'       => $appendices['drawings'],
            'user_guides'    => $appendices['user_guides'],
        ];
    }

    /**
     * Phase 6 — pull appendix records (drawings + user guides) from the
     * project relationship and shape them for the generator data pipeline.
     * Each row is a plain array (date pre-formatted) so it survives JSON
     * encoding into OmManual::generated_data.
     *
     * @return array{drawings: array<int, array<string, string>>, user_guides: array<int, array<string, string>>}
     */
    private function buildAppendixSections(Project $project): array
    {
        $drawings = $project->appendices()
            ->where('type', \App\Models\Appendix::TYPE_DRAWING)
            ->orderBy('reference_number')
            ->get()
            ->map(fn ($d) => [
                'reference_number' => (string) ($d->reference_number ?? ''),
                'title'            => (string) $d->title,
                'revision'         => (string) ($d->revision ?? ''),
                'date'             => $d->date?->format('d M Y') ?? '',
                'file_path'        => (string) ($d->file_path ?? ''),
            ])
            ->values()
            ->all();

        $userGuides = $project->appendices()
            ->where('type', \App\Models\Appendix::TYPE_USER_GUIDE)
            ->orderBy('title')
            ->get()
            ->map(fn ($g) => [
                'title'            => (string) $g->title,
                'reference_number' => (string) ($g->reference_number ?? ''),
                'file_path'        => (string) ($g->file_path ?? ''),
            ])
            ->values()
            ->all();

        return [
            'drawings'    => $drawings,
            'user_guides' => $userGuides,
        ];
    }

    // ── Pass 2: Full content generation ──────────────────────────────────────

    /**
     * Run Pass 2 AI generation from the reviewed extracted_data.
     * Returns the fully populated generated_data array (does NOT build docx).
     *
     * The controller / DocxService is responsible for building the .docx.
     *
     * Tier 1 — Phase 1: validates the canonical context BEFORE the AI call
     * and BEFORE any rendering. If required fields are missing the call
     * aborts with OmManualValidationException and no AI tokens are spent.
     *
     * @throws AIGenerationException
     * @throws \App\Exceptions\OmManualValidationException
     */
    public function generateContent(OmManual $manual, User $user, ?string $provider = null): array
    {
        if (empty($manual->extracted_data)) {
            throw new RuntimeException("OmManual #{$manual->id} has no extracted data to generate from.");
        }

        $prompt  = OmManualPrompt::forContent();
        $context = $this->buildContentContext($manual);

        // Tier 1 NO-TBC POLICY — fail fast on missing required fields so weak
        // documents never reach the AI / DOCX / PDF render stages.
        $this->validator->validateOmData($context);

        $generated = AIManager::run($prompt, $context, $provider);

        // Merge project fields with per-field fallback (empty/generic AI
        // values must not suppress real model/project values).
        $genProject    = is_array($generated['project'] ?? null) ? $generated['project'] : [];
        $manualProject = $manual->project;

        $pick = static function ($aiValue, array $fallbacks, array $invalid = []): string {
            $invalidSet = array_map(static fn($v) => strtolower(trim((string) $v)), $invalid);

            $candidate = trim((string) ($aiValue ?? ''));
            if ($candidate !== '' && ! in_array(strtolower($candidate), $invalidSet, true)) {
                return $candidate;
            }

            foreach ($fallbacks as $fallback) {
                $value = trim((string) ($fallback ?? ''));
                if ($value !== '' && ! in_array(strtolower($value), $invalidSet, true)) {
                    return $value;
                }
            }

            return '';
        };

        $generated['project'] = [
            'name'   => $pick($genProject['name'] ?? null, [
                $manual->project_name,
                $manualProject?->name,
                $context['project_name'] ?? null,
            ], ['av installation project', 'project']),
            'ref'    => $pick($genProject['ref'] ?? null, [
                $manual->project_ref,
                $manualProject?->ref,
                $context['project_ref'] ?? null,
            ]),
            'client' => $pick($genProject['client'] ?? null, [
                $manual->client_name,
                $manualProject?->client_name,
                $context['client_name'] ?? null,
            ], ['client', 'tbc', 'tbd']),
            'site'   => $pick($genProject['site'] ?? null, [
                $manual->site_address,
                $manualProject?->site_address,
                $context['site_address'] ?? null,
            ], ['site', 'tbc', 'tbd']),
        ];

        // Deterministic quality layer: always rebuild critical O&M sections from
        // canonical room/equipment context to avoid sparse AI output.
        $deterministic = $this->buildDeterministicSections($context);

        // Wire-up phase — devices table is the single source of truth for
        // serial / IP / VLAN / port / firmware / asset tag. Seed on first
        // generation so the asset register + Network table have rows to
        // edit, then enrich both data structures with whatever has been
        // populated so the rendered OM reflects the latest device data.
        if ($manual->project !== null) {
            $this->seedDevicesIfMissing($manual->project, $deterministic['rooms_summary']);
            $deterministic['rooms_summary']   = $this->enrichRoomsWithDevices($manual->project, $deterministic['rooms_summary']);
            $deterministic['network_devices'] = $this->enrichNetworkDevicesWithDevices($manual->project, $deterministic['network_devices']);
        }

        $generated['rooms_summary']         = $deterministic['rooms_summary'];
        $generated['operation_sections']    = $deterministic['operation_sections'];
        $generated['maintenance_schedule']  = $deterministic['maintenance_schedule'];
        $generated['fault_finding']         = $deterministic['fault_finding'];
        $generated['network_devices']       = $deterministic['network_devices'];
        $generated['ip_register']           = $deterministic['ip_register'];
        $generated['network_security_notes']= $deterministic['network_security_notes'];
        $generated['manufacturer_support']  = $deterministic['manufacturer_support'];
        $generated['warranty_summary']      = $deterministic['warranty_summary'];
        $generated['existing_reuse']        = $deterministic['existing_reuse'];
        $generated['equipment_installed']   = $deterministic['equipment_installed'];
        $generated['asset_register']        = $deterministic['asset_register'];

        // Phase 6 — appendices: copied straight from the validated context
        // so the template reads from $data instead of querying relationships
        // at render time (snapshot is reproducible from generated_data alone).
        $generated['drawings']              = is_array($context['drawings']    ?? null) ? $context['drawings']    : [];
        $generated['user_guides']           = is_array($context['user_guides'] ?? null) ? $context['user_guides'] : [];

        // Tier 1 (option C) — three new client-facing additions:
        //   - Quick Start Guide (front-of-doc, deterministic)
        //   - Common Tasks per room (within Section 4, platform-detected)
        //   - Per-room "How the System Works" narratives (AI, ≤120 words)
        $generated['quick_start']      = $this->buildQuickStartContent($deterministic['rooms_summary']);
        $generated['common_tasks']     = $this->buildCommonTasksPerRoom($deterministic['rooms_summary']);
        $generated['system_overviews'] = $this->buildSystemOverviewNarratives($deterministic['rooms_summary'], $provider);

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
        // For project-linked manuals, always prefer fresh canonical project context
        // so generation reflects latest reviewed package data (including room tags).
        if ($manual->project !== null) {
            try {
                return $this->buildContextFromProjectData($manual->project);
            } catch (\Throwable $e) {
                Log::warning('OmManualGeneratorService: project context fallback to extracted_data', [
                    'manual_id' => $manual->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $extractedData = $manual->extracted_data ?? [];

        // ── New shape: rooms key present (project-linked O&Ms via ProjectDataService) ──
        if (isset($extractedData['rooms']) && is_array($extractedData['rooms'])) {
            return [
                'project_name'   => $extractedData['project_name'] ?? $manual->project_name ?? 'AV Installation',
                'project_ref'    => $extractedData['project_ref']  ?? $manual->project_ref  ?? '',
                'client_name'    => $extractedData['client_name']  ?? $manual->client_name  ?? '',
                'site_address'   => $extractedData['site_address'] ?? $manual->site_address ?? '',
                'notes'          => $extractedData['notes']        ?? '',
                'scope_of_works' => trim((string) ($extractedData['scope_of_works'] ?? '')),
                // Phase 1+4 — date fields the validator checks. document_date
                // is always today; handover_date carries through if the cached
                // extracted_data captured one, else empty (forces validator
                // gate so the user populates it).
                'document_date'  => $extractedData['document_date'] ?? now()->format('d M Y'),
                'handover_date'  => $extractedData['handover_date'] ?? '',
                'rooms'          => $extractedData['rooms'],
                'drawings'       => $extractedData['drawings']      ?? [],
                'user_guides'    => $extractedData['user_guides']   ?? [],
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
            'project_name'  => $manual->project_name ?? 'AV Installation',
            'project_ref'   => $manual->project_ref  ?? '',
            'client_name'   => $manual->client_name  ?? '',
            'site_address'  => $manual->site_address ?? '',
            'notes'         => '',
            // Phase 1+6 — date / appendix fields the validator checks.
            // Legacy PDF-uploaded OMs have no project link, so handover_date
            // and drawings stay empty; the validator gate stops generation
            // unless those fields are supplied via extracted_data.
            'document_date' => now()->format('d M Y'),
            'handover_date' => '',
            'rooms'         => $rooms,
            'drawings'      => [],
            'user_guides'   => [],
        ];
    }

    /**
     * Build room groups from package area tags first; fall back to resolved rooms.
     */
    private function buildRoomsFromProjectAndPackage(Project $project, array $resolvedData): array
    {
        $roomsByName = [];
        $package = $project->latestPackage;
        $source = [];

        if ($package !== null) {
            $reviewed = (array) ($package->reviewed_data ?? []);
            $extracted = (array) ($package->extracted_data ?? []);
            $source = ! empty($reviewed) ? $reviewed : $extracted;
        }

        foreach ((array) ($source['rooms'] ?? []) as $room) {
            $roomName = is_array($room)
                ? trim((string) ($room['name'] ?? $room['room_name'] ?? ''))
                : trim((string) $room);
            if ($roomName === '' || in_array(strtolower($roomName), self::NON_PHYSICAL_ROOMS, true)) {
                continue;
            }
            $roomsByName[$roomName] ??= [
                'name'        => $roomName,
                'floor'       => null,
                'drawing_ref' => '',
                'equipment'   => [],
            ];
        }

        $equipmentSource = (array) ($source['equipment_list']
            ?? $source['equipment']
            ?? $source['hardware_list']
            ?? []);

        foreach ($equipmentSource as $eq) {
            if (! is_array($eq)) {
                continue;
            }
            $roomName = trim((string) ($eq['area'] ?? $eq['location'] ?? $eq['room'] ?? ''));
            if ($roomName === '') {
                $roomName = 'General';
            }
            if (in_array(strtolower($roomName), self::NON_PHYSICAL_ROOMS, true)) {
                continue;
            }
            $roomsByName[$roomName] ??= [
                'name'        => $roomName,
                'floor'       => null,
                'drawing_ref' => '',
                'equipment'   => [],
            ];
            $roomsByName[$roomName]['equipment'][] = $this->mapContextEquipmentItem($eq);
        }

        // Fallback to resolved rooms if package mapping unavailable.
        if (empty($roomsByName)) {
            foreach ((array) ($resolvedData['rooms'] ?? []) as $room) {
                if (! is_array($room)) {
                    continue;
                }
                $name = trim((string) ($room['name'] ?? $room['room_name'] ?? 'General'));
                if ($name === '' || in_array(strtolower($name), self::NON_PHYSICAL_ROOMS, true)) {
                    continue;
                }
                $mappedItems = array_map(fn ($eq) => $this->mapContextEquipmentItem((array) $eq), (array) ($room['equipment'] ?? []));
                $roomsByName[$name] = [
                    'name'        => $name,
                    'floor'       => $room['floor'] ?? null,
                    'drawing_ref' => $room['drawing_ref'] ?? $room['room_ref'] ?? '',
                    'equipment'   => $mappedItems,
                ];
            }
        }

        if (empty($roomsByName)) {
            $roomsByName['General'] = [
                'name'        => 'General',
                'floor'       => null,
                'drawing_ref' => '',
                'equipment'   => [],
            ];
        }

        ksort($roomsByName, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($roomsByName);
    }

    private function mapContextEquipmentItem(array $eq): array
    {
        $qty = (int) ($eq['quantity'] ?? $eq['qty'] ?? 1);
        $description = trim((string) ($eq['description'] ?? $eq['name'] ?? $eq['model'] ?? ''));
        $partNo = trim((string) ($eq['part_no'] ?? $eq['part_number'] ?? ''));
        $model = trim((string) ($eq['model'] ?? ''));

        if ($model === '' && $partNo !== '') {
            $model = $partNo;
        }

        return [
            'qty'          => $qty > 0 ? $qty : 1,
            'name'         => trim((string) ($eq['name'] ?? $description)),
            'description'  => $description,
            'model'        => $model,
            'manufacturer' => trim((string) ($eq['manufacturer'] ?? '')),
            'part_no'      => $partNo,
            'category'     => trim((string) ($eq['category'] ?? 'Other')),
        ];
    }

    /**
     * Deterministic O&M dataset builder.
     */
    private function buildDeterministicSections(array $context): array
    {
        $roomsSummary = [];
        $existingReuse = [];
        $installedFlat = [];
        $manufacturers = [];
        $networkDevices = [];

        foreach ((array) ($context['rooms'] ?? []) as $room) {
            if (! is_array($room)) {
                continue;
            }

            $roomName = trim((string) ($room['name'] ?? 'General'));
            if ($roomName === '') {
                $roomName = 'General';
            }

            $drawingRef = trim((string) ($room['drawing_ref'] ?? ''));
            $installedForRoom = [];

            foreach ((array) ($room['equipment'] ?? []) as $eqRaw) {
                if (! is_array($eqRaw)) {
                    continue;
                }

                $eq = $this->mapContextEquipmentItem($eqRaw);

                // Phase 3 — clean description + attach taxonomy flags
                // (category / is_networked / is_wireless) before any
                // downstream classification or rendering.
                $eq = $this->equipmentNormaliser->normalise($eq);

                $classification = $this->classifyOmItem($eq);

                if ($classification === 'install_hardware') {
                    $installedForRoom[] = $eq;
                    $installedFlat[] = $eq + ['room' => $roomName, 'drawing_ref' => $drawingRef];

                    $brand = $this->inferManufacturer($eq['manufacturer'], $eq['description'] . ' ' . $eq['name']);
                    if ($brand !== '') {
                        $manufacturers[$brand][] = $eq['description'];
                    }

                    // Phase 3 — prefer the normaliser's authoritative is_networked
                    // flag over keyword matching. Falls back to legacy detection
                    // if the normaliser hasn't classified the item.
                    $isNetworkDevice = array_key_exists('is_networked', $eq)
                        ? (bool) $eq['is_networked']
                        : $this->isNetworkCapable($eq['description'] . ' ' . $eq['name']);

                    if ($isNetworkDevice) {
                        $networkDevices[] = [
                            'room'         => $roomName,
                            'drawing_ref'  => $drawingRef,
                            'device'       => $eq['description'],
                            'category'     => $eq['category']     ?? null,
                            'is_wireless'  => $eq['is_wireless']  ?? false,
                            'hostname'     => '',
                            'ip_address'   => '',
                            'vlan'         => '',
                            'mac_address'  => '',
                            'admin_url'    => '',
                            'network_notes'=> $this->networkNoteForDevice($eq['description']),
                        ];
                    }
                    continue;
                }

                if ($classification === 'existing_reuse') {
                    $existingReuse[] = [
                        'room'        => $roomName,
                        'qty'         => $eq['qty'],
                        'description' => $eq['description'],
                        'model'       => $eq['model'],
                        'part_no'     => $eq['part_no'],
                    ];
                }
            }

            $installedForRoom = $this->mergeEquipmentRows($installedForRoom);
            if (! empty($installedForRoom)) {
                $roomsSummary[] = [
                    'name'        => $roomName,
                    'floor'       => $room['floor'] ?? null,
                    'drawing_ref' => $drawingRef,
                    'equipment'   => $installedForRoom,
                    // Phase 8 fix — pass through the per-room narrative
                    // (sourced from package room_overviews + Phase 1 mirror)
                    // so the Section 1 Executive Summary template can render
                    // each room's overview block.
                    'narrative'   => trim((string) ($room['narrative'] ?? $room['description'] ?? '')),
                ];
            }
        }

        $installedFlat = $this->uniqueRows($installedFlat, fn ($r) => strtolower(($r['room'] ?? '') . '|' . ($r['description'] ?? '') . '|' . ($r['part_no'] ?? '')));
        $existingReuse = $this->uniqueRows($existingReuse, fn ($r) => strtolower(($r['room'] ?? '') . '|' . ($r['description'] ?? '') . '|' . ($r['part_no'] ?? '')));
        $networkDevices = $this->uniqueRows($networkDevices, fn ($r) => strtolower(($r['room'] ?? '') . '|' . ($r['device'] ?? '')));

        return [
            'rooms_summary'         => $roomsSummary,
            'operation_sections'    => $this->buildOperationSections($roomsSummary, $existingReuse),
            'maintenance_schedule'  => $this->buildMaintenanceSchedule($installedFlat),
            'fault_finding'         => $this->buildFaultFindingTable(),
            'network_devices'       => $networkDevices,
            'ip_register'           => $networkDevices,
            'network_security_notes'=> [
                'Use unique admin passwords for each network-enabled AV endpoint.',
                'Place AV devices on a dedicated client-approved VLAN where possible.',
                'Disable unused services and ports after commissioning.',
                'Keep firmware reviewed at least every 6 months in line with client policy.',
            ],
            'manufacturer_support'  => $this->buildManufacturerSupport($manufacturers),
            'warranty_summary'      => $this->buildWarrantySummary($manufacturers),
            'existing_reuse'        => $existingReuse,
            'equipment_installed'   => $installedFlat,
            'asset_register'        => $installedFlat,
        ];
    }

    private function mergeEquipmentRows(array $items): array
    {
        $merged = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = strtolower(trim(($item['description'] ?? '') . '|' . ($item['model'] ?? '') . '|' . ($item['part_no'] ?? '')));
            if ($key === '||') {
                continue;
            }
            if (! isset($merged[$key])) {
                $merged[$key] = $item;
                continue;
            }
            $merged[$key]['qty'] = (int) ($merged[$key]['qty'] ?? 1) + (int) ($item['qty'] ?? 1);
        }
        return array_values($merged);
    }

    private function classifyOmItem(array $item): string
    {
        $text = strtolower(trim(($item['description'] ?? '') . ' ' . ($item['name'] ?? '') . ' ' . ($item['model'] ?? '') . ' ' . ($item['part_no'] ?? '')));
        $category = strtolower(trim((string) ($item['category'] ?? '')));

        if ($text === '' || $text === 'additional') {
            return 'labour_or_document';
        }

        if ($this->containsAny($text, self::EXISTING_REUSE_KEYWORDS)) {
            return 'existing_reuse';
        }

        if (in_array($category, ['services', 'option'], true) || $this->containsAny($text, self::LABOUR_OR_DOCUMENT_KEYWORDS)) {
            return 'labour_or_document';
        }

        if (in_array($category, ['cables', 'consumables'], true) || $this->containsAny($text, self::CABLE_OR_CONSUMABLE_KEYWORDS)) {
            return 'cable_consumable';
        }

        return 'install_hardware';
    }

    private function buildOperationSections(array $rooms, array $existingReuse): array
    {
        $existingByRoom = [];
        foreach ($existingReuse as $row) {
            $existingByRoom[$row['room']][] = $row['description'] ?? '';
        }

        $sections = [];
        foreach ($rooms as $room) {
            $roomName = $room['name'] ?? 'Room';
            $equipment = (array) ($room['equipment'] ?? []);
            $subsystems = $this->detectSubsystemsFromEquipment($equipment);

            $startUp = [
                'Confirm room power and network services are available.',
                'Power on the AV system from the room interface or agreed startup control point.',
            ];
            $normalUse = [
                'Select the required source and verify image/audio routing.',
                'Adjust room audio to a comfortable level before commencing meetings.',
            ];
            $shutdown = [
                'End active calls/sessions and return source selection to default.',
                'Power down the room system using the normal shutdown control.',
            ];

            if (in_array('VC', $subsystems, true)) {
                $startUp[] = 'Sign in to the conferencing platform and verify camera/microphone status.';
                $normalUse[] = 'Run a brief test call before first client use each day.';
            }
            if (in_array('Display', $subsystems, true)) {
                $normalUse[] = 'Check primary display(s) are on the correct input profile.';
            }
            if (in_array('Audio', $subsystems, true)) {
                $normalUse[] = 'Confirm speech reinforcement and far-end audio are clear and feedback-free.';
            }

            $notes = [];
            if (! empty($existingByRoom[$roomName])) {
                $notes[] = [
                    'type' => 'warning',
                    'text' => 'This room interfaces with existing client-owned equipment: '
                        . implode(', ', array_slice(array_unique($existingByRoom[$roomName]), 0, 4)) . '.',
                ];
            }

            $sections[] = [
                'room_name'  => $roomName,
                'drawing_ref'=> $room['drawing_ref'] ?? '',
                'subsections'=> [
                    ['title' => 'Start-Up Procedure', 'steps' => $startUp, 'notes' => []],
                    ['title' => 'Normal Operation',   'steps' => $normalUse, 'notes' => []],
                    ['title' => 'Shutdown Procedure', 'steps' => $shutdown, 'notes' => $notes],
                ],
            ];
        }

        return $sections;
    }

    private function detectSubsystemsFromEquipment(array $equipment): array
    {
        $subsystems = [];
        foreach ($equipment as $item) {
            $text = strtolower(($item['description'] ?? '') . ' ' . ($item['name'] ?? ''));
            if ($this->containsAny($text, ['display', 'screen', 'monitor', 'samsung', 'projector', 'qm85', 'qe'])) {
                $subsystems['Display'] = 'Display';
            }
            if ($this->containsAny($text, ['cisco', 'codec', 'room kit', 'camera', 'navigator', 'ptz', 'teams', 'zoom'])) {
                $subsystems['VC'] = 'VC';
            }
            if ($this->containsAny($text, ['speaker', 'microphone', 'mic', 'dsp', 'amplifier', 'shure', 'q-sys', 'lea'])) {
                $subsystems['Audio'] = 'Audio';
            }
        }
        return array_values($subsystems);
    }

    private function buildMaintenanceSchedule(array $installedFlat): array
    {
        $schedule = [];
        foreach ($installedFlat as $item) {
            $name = trim((string) ($item['description'] ?? ''));
            if ($name === '') {
                continue;
            }
            $lower = strtolower($name);

            // Passive accessories (mounts, splitters, adapters, shelves, plates,
            // cable couplers) get visual-inspection-only maintenance — they
            // share keywords with active devices but aren't operationally
            // serviced. This gate runs before brand/category branches so
            // "Camera Shelf" doesn't inherit camera maintenance, "Logitech
            // Camera Splitter" doesn't get test-call checks, etc.
            if ($this->containsAny($lower, self::NON_NETWORK_PARTS)) {
                $schedule[] = ['frequency' => 'Quarterly', 'item' => $name, 'task' => 'Visual inspection and operational function check.', 'responsible_party' => 'FM / AV Support'];
                continue;
            }

            if ($this->containsAny($lower, ['display', 'screen', 'monitor', 'samsung', 'sony', 'iiyama', 'commercial display', 'qm', 'qe'])) {
                $schedule[] = ['frequency' => 'Monthly', 'item' => $name, 'task' => 'Clean display surface and check image quality/uniformity.', 'responsible_party' => 'FM / AV Support'];
                $schedule[] = ['frequency' => '6-Monthly', 'item' => $name, 'task' => 'Review firmware level and apply approved updates.', 'responsible_party' => 'AV Support'];
            } elseif ($this->containsAnyWord($lower, ['codec', 'room kit', 'camera', 'navigator', 'ptz', 'rally bar', 'rally camera', 'cam550'])) {
                $schedule[] = ['frequency' => 'Monthly', 'item' => $name, 'task' => 'Run test call and verify camera framing/mic pickup.', 'responsible_party' => 'AV Support'];
                $schedule[] = ['frequency' => '6-Monthly', 'item' => $name, 'task' => 'Review conferencing software/firmware and backup configuration.', 'responsible_party' => 'AV Support'];
            } elseif ($this->containsAnyWord($lower, ['dsp', 'q-sys', 'biamp', 'amplifier', 'lea', 'speaker', 'microphone', 'shure', 'tesira', 'parle', 'tcm-x'])) {
                $schedule[] = ['frequency' => 'Quarterly', 'item' => $name, 'task' => 'Verify audio path, levels, and device health status.', 'responsible_party' => 'AV Support'];
                $schedule[] = ['frequency' => '6-Monthly', 'item' => $name, 'task' => 'Confirm firmware/configuration backup and update records.', 'responsible_party' => 'AV Support'];
            } elseif ($this->containsAny($lower, ['switch', 'netgear', 'network interface', 'audio network'])) {
                $schedule[] = ['frequency' => 'Monthly', 'item' => $name, 'task' => 'Check link status, error counters, and port utilisation.', 'responsible_party' => 'Client IT'];
                $schedule[] = ['frequency' => '6-Monthly', 'item' => $name, 'task' => 'Apply approved firmware updates during maintenance window.', 'responsible_party' => 'Client IT'];
            } else {
                $schedule[] = ['frequency' => 'Quarterly', 'item' => $name, 'task' => 'Visual inspection and operational function check.', 'responsible_party' => 'FM / AV Support'];
            }
        }

        $schedule = $this->uniqueRows($schedule, fn ($r) => strtolower(($r['frequency'] ?? '') . '|' . ($r['item'] ?? '') . '|' . ($r['task'] ?? '')));
        return array_slice($schedule, 0, 120);
    }

    private function buildFaultFindingTable(): array
    {
        return [
            [
                'symptom' => 'No image on display',
                'cause'   => 'Display/input route not active',
                'steps'   => [
                    'Confirm display power is on and not in standby.',
                    'Check selected source on room control interface.',
                    'Verify source device is connected and active.',
                    'Check matrix/switch route for correct output mapping.',
                ],
            ],
            [
                'symptom' => 'No far-end audio in VC call',
                'cause'   => 'Codec audio output path muted or disconnected',
                'steps'   => [
                    'Confirm room volume is above minimum and unmuted.',
                    'Check codec output device selection.',
                    'Verify DSP/amplifier status and signal presence.',
                    'Run a local audio test tone where available.',
                ],
            ],
            [
                'symptom' => 'Microphone not heard by far end',
                'cause'   => 'Mic mute/state or DSP input issue',
                'steps'   => [
                    'Confirm microphone is unmuted and battery/charge is healthy.',
                    'Check RF/pairing status for wireless microphones.',
                    'Verify DSP input meter activity and gating.',
                    'Confirm codec/application is using the correct audio input.',
                ],
            ],
            [
                'symptom' => 'Touch panel / room interface unresponsive',
                'cause'   => 'Network/PoE/control processor issue',
                'steps'   => [
                    'Check panel power or PoE link status.',
                    'Verify network switch port is active.',
                    'Restart panel and confirm it rejoins control system.',
                    'Escalate to AV support if control pages fail to load.',
                ],
            ],
            [
                'symptom' => 'VC call drops unexpectedly',
                'cause'   => 'Network instability or platform session issue',
                'steps'   => [
                    'Check network link status on codec and switch.',
                    'Run a short repeat test call.',
                    'Verify platform account/session state.',
                    'If repeated, log time/date and escalate to IT + AV support.',
                ],
            ],
        ];
    }

    private function isNetworkCapable(string $text): bool
    {
        $lower = strtolower($text);

        // Passive parts (mounts, splitters, adapters, shelves, plates, cable
        // couplers) often share keywords with networked devices but are not
        // themselves networkable. Exclude them up-front.
        if ($this->containsAny($lower, self::NON_NETWORK_PARTS)) {
            return false;
        }

        return $this->containsAnyWord($lower, [
            'cisco', 'codec', 'room kit', 'navigator', 'touch panel', 'tap',
            'dsp', 'q-sys', 'biamp', 'shure', 'mxw', 'netgear', 'switch',
            'lea', 'extron', 'crestron', 'logitech', 'rally bar', 'rally camera',
            'cam550', 'ptz', 'iiyama', 'commercial display', 'audio network',
            'tesira', 'parle', 'tcm-x', 'nuc',
        ]);
    }

    private function networkNoteForDevice(string $device): string
    {
        $lower = strtolower($device);
        if ($this->containsAnyWord($lower, ['codec', 'room kit', 'cisco', 'rally bar', 'tap', 'nuc'])) {
            return 'Requires reliable LAN connectivity and platform registration.';
        }
        // Phase 8 fix — Biamp Parle / TCM-X are WIRED Tesira-network ceiling
        // microphones, not wireless RF mics. Match these BEFORE the generic
        // microphone bucket so the previous "wireless mic management" string
        // no longer leaks into the OM for them.
        if ($this->containsAnyWord($lower, ['parle', 'tcm-x'])) {
            return 'Wired Tesira-network ceiling microphone — record AVB reservation and retain DSP configuration backup.';
        }
        if ($this->containsAnyWord($lower, ['shure', 'mxw'])) {
            return 'Wireless microphone receiver — IT to register on management network and update firmware policy.';
        }
        if ($this->containsAnyWord($lower, ['microphone'])) {
            return 'Microphone endpoint — Client IT to assign IP/VLAN if applicable.';
        }
        if ($this->containsAnyWord($lower, ['dsp', 'q-sys', 'biamp', 'lea', 'tesira'])) {
            return 'Record static reservation/hostname and retain configuration backup.';
        }
        if ($this->containsAnyWord($lower, ['switch', 'netgear'])) {
            return 'Client IT to manage firmware, VLAN policy, and port security.';
        }
        return 'Client IT to assign IP/VLAN and record credential ownership.';
    }

    /**
     * Build the manufacturer-support section by resolving each detected brand
     * through ManufacturerSupportResolverService. The resolver is the single
     * source of truth (Phase 2 — NO TBC POLICY): if it can't find a usable
     * record for a brand, it throws and OM generation aborts.
     *
     * @throws \RuntimeException If any inferred brand has no usable support details.
     */
    private function buildManufacturerSupport(array $manufacturers): array
    {
        $rows = [];
        foreach ($manufacturers as $brand => $items) {
            // Resolver throws on no-match — bubble up so generation aborts.
            $details = $this->manufacturerResolver->resolve($brand);

            $rows[] = [
                'brand'               => $brand,
                'equipment_installed' => implode(', ', array_slice(array_values(array_unique($items)), 0, 5)),
                'uk_phone'            => $details['support_phone'] ?? '',
                'support_portal'      => $details['support_url']   ?? '',
                'support_email'       => $details['support_email'] ?? '',
                'warranty'            => $this->formatWarrantyMessage($details['warranty_years'] ?? null),
                'notes'               => ['Escalate via 21st Century AV for installation-period support coordination.'],
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a['brand'], $b['brand']));
        return $rows;
    }

    /**
     * Pull warranty period from the resolver's result. Falls back to the
     * generic "per manufacturer terms" line when years are unknown — never
     * outputs "TBC".
     */
    private function buildWarrantySummary(array $manufacturers): array
    {
        $rows = [];
        foreach (array_keys($manufacturers) as $brand) {
            try {
                $details = $this->manufacturerResolver->resolve($brand);
                $period  = $details['warranty_years'] !== null
                    ? $details['warranty_years'] . ' years'
                    : 'Per manufacturer terms';
            } catch (\Throwable $e) {
                // buildManufacturerSupport will have already thrown for this brand
                // if its record is missing — but defensively use a non-TBC default
                // here so a transient resolver failure during summary doesn't
                // emit "TBC" into the output.
                $period = 'Per manufacturer terms';
            }

            $rows[] = [
                'equipment' => $brand . ' installed equipment',
                'period'    => $period,
                'notes'     => 'Start date typically from commissioning/handover.',
            ];
        }
        return $rows;
    }

    private function formatWarrantyMessage(?int $years): string
    {
        if ($years !== null && $years > 0) {
            return "Manufacturer warranty: {$years} years from commissioning/handover.";
        }
        return 'Manufacturer warranty per product family — confirm cover before service.';
    }

    private function inferManufacturer(string $manufacturer, string $text): string
    {
        $manufacturer = trim($manufacturer);
        if ($manufacturer !== '') {
            return $manufacturer;
        }
        $lower = strtolower($text);
        // Word-boundary matching prevents short brand keywords (e.g. 'lea') from
        // matching as substrings of unrelated words (e.g. 'extension lead'). Order
        // is significant — earlier entries win on tie.
        $map = [
            'logitech' => 'Logitech',
            'samsung'  => 'Samsung',
            'sony'     => 'Sony',
            'iiyama'   => 'iiyama',
            'biamp'    => 'Biamp',
            'cisco'    => 'Cisco',
            'q-sys'    => 'Q-SYS',
            'qsys'     => 'Q-SYS',
            'shure'    => 'Shure',
            'lea'      => 'LEA',
            'chief'    => 'Chief',
            'netgear'  => 'Netgear',
            'extron'   => 'Extron',
            'crestron' => 'Crestron',
            'unicol'   => 'Unicol',
            'bose'     => 'Bose',
            'bosch'    => 'Bosch',
        ];
        foreach ($map as $needle => $brand) {
            if (preg_match('/\b' . preg_quote($needle, '/') . '\b/i', $lower) === 1) {
                return $brand;
            }
        }
        return '';
    }

    private function uniqueRows(array $rows, callable $keyFn): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = $keyFn($row);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }
        return $out;
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

    /**
     * Word-boundary variant of containsAny — matches each needle only when it
     * appears as a whole word in the haystack. Use this for short brand or
     * product keywords (e.g. 'lea', 'tap', 'biamp', 'shure') where a substring
     * match would create false positives — e.g. 'lea' inside 'extension lead'.
     */
    private function containsAnyWord(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n === '') {
                continue;
            }
            if (preg_match('/\b' . preg_quote($n, '/') . '\b/i', $haystack) === 1) {
                return true;
            }
        }
        return false;
    }

    // ════════════════════════════════════════════════════════════════════════
    // Tier 1 (option C) data builders — Quick Start, Common Tasks,
    // and AI per-room "How the System Works" narratives.
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Quick Start Guide — single-page front-matter block. Deterministic;
     * adapts the "join a call" line to the detected conferencing platform.
     *
     * @param array $roomsSummary  Output of buildDeterministicSections.
     * @return array{
     *   platform_label:string,
     *   start_steps:array<int,string>,
     *   join_steps:array<int,string>,
     *   present_steps:array<int,string>,
     *   support_label:string
     * }
     */
    private function buildQuickStartContent(array $roomsSummary): array
    {
        $platform = $this->detectPrimaryPlatform($roomsSummary);
        $isTeams  = $platform === 'teams_rooms_logitech';

        $platformLabel = $isTeams
            ? 'Microsoft Teams Rooms (Logitech)'
            : 'Configured conferencing platform';

        return [
            'platform_label' => $platformLabel,
            'start_steps' => [
                'Tap the touch controller on the table to wake the system.',
                'Wait for the home screen — the display(s) will switch on automatically.',
                'The room is ready when the controller shows the calendar / "Meet now" view.',
            ],
            'join_steps' => $isTeams
                ? [
                    'On the touch controller, tap the meeting name in the calendar list.',
                    'Tap "Join" — camera and microphone activate automatically.',
                    'Adjust volume on the controller if needed; speak normally.',
                ]
                : [
                    'On the touch controller, select the meeting from the calendar list.',
                    'Tap "Join" — camera and microphone activate automatically.',
                    'Adjust volume on the controller if needed; speak normally.',
                ],
            'present_steps' => [
                'Connect your laptop to the table cable (HDMI or USB-C).',
                'On the touch controller, tap "Share content".',
                'Your screen appears on the room display(s).',
                'When finished, unplug the cable or tap "Stop sharing".',
            ],
            'support_label' => '21st Century AV Service Desk — 01189 977770 (Mon–Fri 09:00–17:30) — info@21stcenturyav.com',
        ];
    }

    /**
     * Common Tasks per room — task-based workflows for the most frequent
     * end-user actions. Platform-aware: when a room is detected as a
     * Logitech Teams Rooms install, steps reference the Tap controller's
     * Teams UI; otherwise generic instructions are emitted.
     *
     * Each task carries a `[IMAGE: …]` placeholder the template renders as
     * a hint for screenshot insertion (out of scope to render real images).
     *
     * @param array $roomsSummary
     * @return array<int, array{room_name:string, platform:string, tasks:array<int, array<string,mixed>>}>
     */
    private function buildCommonTasksPerRoom(array $roomsSummary): array
    {
        $blocks = [];

        foreach ($roomsSummary as $room) {
            $roomName = (string) ($room['name'] ?? 'Room');
            $platform = $this->detectRoomPlatform((array) ($room['equipment'] ?? []));
            $isTeams  = $platform === 'teams_rooms_logitech';

            $platformLabel = $isTeams
                ? 'Microsoft Teams Rooms (Logitech)'
                : 'Conferencing platform configured for this room';

            $tasks = [
                [
                    'name'  => 'Start a meeting',
                    'image' => '[IMAGE: tap_home_screen]',
                    'steps' => $isTeams ? [
                        'On the Tap controller, tap "Meet now".',
                        'Tap "Start meeting" — camera and mic activate automatically.',
                        'Once the meeting opens, share the join link from the controller if external attendees are needed.',
                    ] : [
                        'On the touch controller, open the conferencing app.',
                        'Tap "Start meeting" — camera and mic activate.',
                        'Share the join link with external attendees if required.',
                    ],
                ],
                [
                    'name'  => 'Present from your laptop',
                    'image' => '[IMAGE: tap_share_content]',
                    'steps' => [
                        'Plug your laptop into the table cable (HDMI or USB-C).',
                        'On the touch controller, tap "Share content".',
                        'Select the source — your laptop screen appears on the room display(s).',
                        'When finished, tap "Stop sharing" or unplug the cable.',
                    ],
                ],
                [
                    'name'  => 'Adjust volume',
                    'image' => '[IMAGE: tap_volume]',
                    'steps' => [
                        'On the touch controller, locate the speaker / volume icon.',
                        'Press "+" or "−" until the level is comfortable.',
                    ],
                ],
                [
                    'name'  => 'End a meeting',
                    'image' => '[IMAGE: tap_leave_meeting]',
                    'steps' => $isTeams ? [
                        'On the Tap controller, tap the red "Leave" button.',
                        'Confirm "Leave meeting".',
                        'The system returns to the home screen automatically.',
                    ] : [
                        'On the touch controller, tap "Leave" or "End".',
                        'Confirm if prompted.',
                        'The system returns to the home screen.',
                    ],
                ],
            ];

            $blocks[] = [
                'room_name' => $roomName,
                'platform'  => $platformLabel,
                'tasks'     => $tasks,
            ];
        }

        return $blocks;
    }

    /**
     * Per-room "How the System Works" narratives via AI (≤120 words each).
     *
     * Falls back to a deterministic template when the AI call fails — the
     * template never renders empty narratives. Caching is handled by
     * AIManager (SHA-256 of the built prompt), so re-running the generator
     * with unchanged equipment doesn't burn tokens.
     *
     * @param array       $roomsSummary
     * @param string|null $provider     AI provider override ('claude' / 'openai').
     * @return array<int, array{room_name:string, narrative:string}>
     */
    private function buildSystemOverviewNarratives(array $roomsSummary, ?string $provider = null): array
    {
        $out = [];

        foreach ($roomsSummary as $room) {
            $roomName = (string) ($room['name'] ?? 'Room');

            $equipmentNames = array_values(array_filter(array_map(
                static fn (array $eq) => trim((string) ($eq['description'] ?? $eq['name'] ?? '')),
                (array) ($room['equipment'] ?? [])
            ), static fn ($n) => $n !== ''));

            $context = [
                'room'      => $roomName,
                'equipment' => $equipmentNames,
            ];

            $narrative = $this->fallbackRoomOverview($roomName, $equipmentNames);
            try {
                $result = AIManager::run(new OmRoomOverviewPrompt(), $context, $provider);
                $candidate = trim((string) ($result['narrative'] ?? ''));
                if ($candidate !== '') {
                    $narrative = $candidate;
                }
            } catch (\Throwable $e) {
                Log::warning('OmRoomOverview AI call failed, using deterministic fallback', [
                    'room'  => $roomName,
                    'error' => $e->getMessage(),
                ]);
            }

            $out[] = [
                'room_name' => $roomName,
                'narrative' => $narrative,
            ];
        }

        return $out;
    }

    private function fallbackRoomOverview(string $roomName, array $equipmentNames): string
    {
        if (empty($equipmentNames)) {
            return "AV system installed in {$roomName}. Operated via the room controller; "
                 . 'detailed component list is in Section 2.';
        }

        $headline = array_slice($equipmentNames, 0, 3);
        $more     = max(0, count($equipmentNames) - 3);
        $list     = implode(', ', $headline);
        $tail     = $more > 0 ? " plus {$more} further item(s)" : '';

        return "AV system installed in {$roomName} comprising {$list}{$tail}. "
             . 'Operated via the room controller for video conferencing and laptop presentation; '
             . 'speech reinforcement and far-end audio are mixed by the in-room DSP. '
             . 'Full equipment list is in Section 2.';
    }

    /**
     * Look across all rooms and pick the most likely conferencing platform
     * for the project. Used by the Quick Start (front-of-doc) so the
     * "join a call" steps match the most common platform on site.
     */
    private function detectPrimaryPlatform(array $roomsSummary): string
    {
        foreach ($roomsSummary as $room) {
            if ($this->detectRoomPlatform((array) ($room['equipment'] ?? [])) === 'teams_rooms_logitech') {
                return 'teams_rooms_logitech';
            }
        }
        return 'generic';
    }

    /**
     * Per-room platform detection. Returns 'teams_rooms_logitech' when the
     * room contains a Logitech Tap controller alongside a Logitech Rally
     * Bar / Camera or NUC — the canonical Teams Rooms (MTR) signature.
     */
    private function detectRoomPlatform(array $equipment): string
    {
        $haystack = strtolower(implode(' | ', array_map(
            static fn (array $eq) => (string) ($eq['description'] ?? $eq['name'] ?? ''),
            $equipment
        )));

        $hasTap   = $this->containsAnyWord($haystack, ['logitech tap', 'tap controller', 'tap cat5e']);
        $hasRally = $this->containsAnyWord($haystack, ['rally bar', 'rally camera', 'rally conference camera']);
        $hasNuc   = $this->containsAnyWord($haystack, ['nuc', 'teams base system', 'teams rooms']);

        if ($hasTap && ($hasRally || $hasNuc)) {
            return 'teams_rooms_logitech';
        }
        return 'generic';
    }

    // ════════════════════════════════════════════════════════════════════════
    // Wire-up phase — Asset Register + Network table backed by devices table.
    // Seeding is idempotent (firstOrCreate per equipment line); enrichment
    // matches by (project_id, room_name, lower-case description) so changes
    // to model strings don't break the join.
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Ensure each equipment line on the project has a corresponding row in
     * the devices table. Idempotent — only creates rows that don't already
     * exist for the (project, room, description) tuple.
     */
    private function seedDevicesIfMissing(Project $project, array $roomsSummary): void
    {
        foreach ($roomsSummary as $room) {
            $roomName = trim((string) ($room['name'] ?? 'General'));
            foreach ((array) ($room['equipment'] ?? []) as $eq) {
                $description = trim((string) ($eq['description'] ?? ''));
                if ($description === '') {
                    continue;
                }
                \App\Models\Device::firstOrCreate(
                    [
                        'project_id'  => $project->id,
                        'room_name'   => $roomName,
                        'description' => $description,
                    ],
                    [
                        'model'        => trim((string) ($eq['model']        ?? '')) ?: null,
                        'manufacturer' => trim((string) ($eq['manufacturer'] ?? '')) ?: null,
                        'part_no'      => trim((string) ($eq['part_no']      ?? '')) ?: null,
                        'qty'          => (int) ($eq['qty'] ?? 1),
                    ]
                );
            }
        }
    }

    /**
     * Enrich rooms_summary equipment items with the device row's serial /
     * IP / VLAN / port / firmware / asset tag / MAC, where populated. Matches
     * by (project_id, lower-case room_name, lower-case description). Items
     * with no matching device are returned unchanged.
     */
    private function enrichRoomsWithDevices(Project $project, array $roomsSummary): array
    {
        $devices = $this->loadDevicesByMatchKey($project);
        if ($devices->isEmpty()) {
            return $roomsSummary;
        }

        foreach ($roomsSummary as &$room) {
            $roomName = trim((string) ($room['name'] ?? ''));
            foreach ((array) ($room['equipment'] ?? []) as &$eq) {
                $key = $this->deviceMatchKey($roomName, (string) ($eq['description'] ?? ''));
                $hit = $devices->get($key);
                if ($hit === null) {
                    continue;
                }
                $eq['serial_number']    = (string) ($hit->serial_number    ?? '');
                $eq['mac_address']      = (string) ($hit->mac_address      ?? '');
                $eq['ip_address']       = (string) ($hit->ip_address       ?? '');
                $eq['vlan']             = (string) ($hit->vlan             ?? '');
                $eq['port']             = (string) ($hit->port             ?? '');
                $eq['firmware_version'] = (string) ($hit->firmware_version ?? '');
                $eq['asset_tag']        = (string) ($hit->asset_tag        ?? '');
            }
            unset($eq);
        }
        unset($room);

        return $roomsSummary;
    }

    /**
     * Enrich network_devices entries with IP / VLAN / Port / MAC from the
     * devices table. network_devices entries are keyed by (room, device).
     */
    private function enrichNetworkDevicesWithDevices(Project $project, array $networkDevices): array
    {
        $devices = $this->loadDevicesByMatchKey($project);
        if ($devices->isEmpty()) {
            return $networkDevices;
        }

        foreach ($networkDevices as &$nd) {
            $key = $this->deviceMatchKey((string) ($nd['room'] ?? ''), (string) ($nd['device'] ?? ''));
            $hit = $devices->get($key);
            if ($hit === null) {
                continue;
            }
            $nd['ip_address']  = (string) ($hit->ip_address  ?? '');
            $nd['vlan']        = (string) ($hit->vlan        ?? '');
            $nd['port']        = (string) ($hit->port        ?? '');
            $nd['mac_address'] = (string) ($hit->mac_address ?? '');
        }
        unset($nd);

        return $networkDevices;
    }

    /**
     * Single load of all the project's devices, indexed by match key for
     * O(1) lookup during enrichment.
     */
    private function loadDevicesByMatchKey(Project $project): \Illuminate\Support\Collection
    {
        return $project->devices()->get()->keyBy(
            fn ($d) => $this->deviceMatchKey((string) ($d->room_name ?? ''), (string) ($d->description ?? ''))
        );
    }

    private function deviceMatchKey(string $roomName, string $description): string
    {
        return strtolower(trim($roomName)) . '|' . strtolower(trim($description));
    }
}
