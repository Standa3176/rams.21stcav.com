<?php

namespace App\Support\Cockpit;

use App\Core\Modules\OMManual\OmManualGeneratorService;
use App\Exceptions\OmManualValidationException;
use App\Models\LabourResource;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Services\OmManualValidationService;
use Throwable;

/**
 * CockpitDocumentFormPresenter — the fields each of the four cockpit documents
 * needs, AS DATA (Phase 46.2, Plan 46.2-04; 46.2-CONTEXT.md D-03).
 *
 * PURE READING. Nothing here writes. `documentFieldMap()` and `fieldsFor()`
 * touch no model at all; `readiness()` reads the project and DELEGATES to
 * `OmManualValidationService`, which is itself read-only. There is no touch(),
 * no firstOrCreate() and no cache warm on any path in this class.
 *
 * WHY THIS IS A MAP AND NOT FOUR HAND-WRITTEN PANELS. This page's *contents*
 * have changed four times (sketch 002 → sketch 004 → PMV-style review → doc
 * creation) while its visual design held. The next change must be a ROW EDIT,
 * not a rewrite. A fifth document type is therefore one entry in
 * `DOCUMENT_FIELD_MAP` plus one entry in `CockpitModulePresenter::MODULE_MAP` —
 * no new branch anywhere, because Plan 46.2-05's Blade iterates `groups` and
 * switches on `type`, of which there are seven and the set is closed.
 *
 * D-03 — FIELDS ARE DISCOVERED, NOT INVENTED. Every field below carries
 * `consumer => ['file', 'symbol']`, and
 * `CockpitDocumentFormPresenterTest::test_every_mapped_field_symbol_is_found_in_its_named_generator()`
 * greps the named file for the named symbol. A field whose generator does not
 * contain its symbol is a RED TEST, not a silent form input that writes into
 * nothing. "A field the generator ignores is a field that teaches a PM to fill
 * in noise" (46.2-CONTEXT.md D-03) — that sentence is executable here.
 *
 * Every symbol in this file was grep-confirmed against the working tree on
 * 2026-09-24 before the field was admitted (44 symbols, 44 hits). The
 * candidates REJECTED for having no generator consumer are listed in
 * `.planning/phases/46.2-doc-creation-cockpit/46.2-04-SUMMARY.md` and
 * summarised under "REJECTED" below, so a later author reads the finding
 * rather than re-adding the field.
 *
 * ── REJECTED, WITH EVIDENCE (each re-checked by grep over the generator set:
 *    the four DOCX builders, RamsBuilderService / RamsDataBuilderService /
 *    RamsDisplayPatchService, app/Support/Rams, and the PDF Blades) ──
 *
 *   SITE SURVEY — `pm_name`, `pm_phone`, `pm_email`, `visit_time`,
 *   `survey_type`. All five are validated at `SiteSurveyController.php:672-677`
 *   and fillable on the model, and NO generator reads any of them (0 hits
 *   each). `survey_type` is additionally documented as a DEAD COLUMN at
 *   `BackfillVisitsCommand.php:48`.
 *
 *   RAMS — `programme.ongoing`. Emitted by
 *   `RamsReviewDataService::normaliseProgramme()` and read by nothing (0 hits
 *   outside the normaliser, in the whole application).
 *
 *   RAMS — `site_logistics.access_type`, `.access_notes`, `.parking`,
 *   `.parking_notes`, `.install_floor`, `.delivery_area`, `.restrictions`,
 *   `.commissioning_notes`. Emitted by `normaliseSiteLogistics()` and consumed
 *   ONLY by the project-package review form that writes them
 *   (`ProjectPackageReviewController.php:1158-1170`). The RAMS document's own
 *   "Site Logistics & Access" block renders a DIFFERENT, SURVEY-SHAPED key set
 *   — `comms_room_access_status`, `parking_restraints`, `site_access_notes`,
 *   `delivery_routes`, `distance_from_base_*` (`DocxBuilderService.php:678-684`
 *   and `pdf/rams-v2.blade.php:856-864`) — and
 *   `RamsDataBuilderService.php:538` rewrites `site_logistics` to exactly those
 *   seven survey keys, dropping the reviewed ones. Only
 *   `contact_name/_phone/_email` survive, via
 *   `RamsDisplayPatchService.php:132-142`, and those three ARE mapped.
 *   ⚠ Beware substring greps here: `parking` matches `parking_restraints` and
 *   `access_notes` matches `site_access_notes`. Both false positives were hit
 *   and eliminated at derivation time; that is why the symbols in this map are
 *   written in their `$sl['...']` / `$survey?->...` access form.
 *
 * ── THE ONE DELIBERATE OMISSION: `programme.planned_end_time` ──
 * `RamsDisplayPatchService.php:153` READS `planned_end_time`, but
 * `RamsReviewDataService::normaliseProgramme()` (`:279-296`) returns a fresh
 * array WITHOUT that key, so the value is ALWAYS ''. A panel field for it would
 * let a PM type a value that `normalise()` silently discards on the next pass —
 * the worst form of the D-03 failure. It is EXCLUDED, and
 * `test_planned_end_time_is_not_a_field()` asserts its absence BY NAME so the
 * omission is asserted rather than remembered. The underlying defect is logged
 * (D-46.2-04-01) and deliberately LEFT UNFIXED — fixing it is not this plan's
 * call.
 *
 * ── TARGETS ARE THE KEYS THAT SURVIVE NORMALISATION ──
 * RAMS values are resolved by `RamsDisplayPatchService` from `reviewed_data`
 * and normalised by `RamsReviewDataService`, so a `target` MUST be a key one of
 * the `normalise*()` methods literally returns (`programme.*`, `site_logistics.*`,
 * `project.*`). Writing anywhere else is discarded on the next `normalise()`.
 * The single exception is `form_data.working_hours`, and it is an exception for
 * a measured reason — see that field's note.
 *
 * ── LABOUR RESOURCES SUPPLY NAMES, NOTHING ELSE ──
 * RAMS stores engineers as free text (`lead_engineer_name` string,
 * `additional_engineers` string[]), so `LabourResource::active()` filtered by
 * role is the OPTION SOURCE and the selected NAMES are what get written. LR-04
 * binds: NAME ONLY — never a labour resource's email or phone. No schema
 * change, and the resource list is a convenience source, not a foreign key
 * (T-46.2-11, accepted).
 *
 * ── NO `select` ──
 * `CockpitReadOnlyFenceTest::FORBIDDEN_MARKUP` still bans `<select`, and Plan
 * 46.2-03 re-took that ruling. There is therefore no `select` field type; a
 * closed value set is `radio`. If a field ever genuinely needs a dropdown, the
 * procedure is to LIFT THE FENCE ENTRY BY NAME in the commit that ships it (as
 * 46-04 and 46.1-04 did) — never to delete the entry to make a change fit, and
 * never to decide it here.
 */
final class CockpitDocumentFormPresenter
{
    /** The closed set of input types Plan 46.2-05's Blade knows how to render. */
    public const TYPE_TEXT = 'text';

    public const TYPE_DATE = 'date';

    public const TYPE_TIME = 'time';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_CHECKBOX = 'checkbox';

    public const TYPE_RADIO = 'radio';

    /** A list of `LabourResource` NAMES rendered as checkboxes (LR-04). */
    public const TYPE_RESOURCE_LIST = 'resource-list';

    /**
     * Readiness kinds. A document either delegates its readiness list to a
     * named existing validator, or has none. This is a KEY, so a fifth
     * document reusing a validator is still one row.
     */
    public const READINESS_OM_VALIDATOR = 'om-manual-validator';

    /** LabourResource option sources, named rather than queried in the map. */
    public const PREFILL_ENGINEERS = 'LabourResource::active(engineer)';

    public const PREFILL_PROGRAMMERS = 'LabourResource::active(programmer)';

    /**
     * Rules for a field the PROJECT already answers. It is rendered read-only
     * and `prohibited` makes submitting it a validation failure rather than a
     * silent overwrite — the PM is never asked a question the app knows, and
     * cannot answer it differently behind the app's back.
     */
    private const RULES_DISPLAY_ONLY = ['prohibited'];

    /**
     * THE MAP. Four rows, keyed identically to
     * `CockpitModulePresenter::moduleMap()` — asserted as set equality by
     * `test_the_document_keys_match_the_module_keys_exactly()`, so a fifth
     * module can never render a panel with no fields and a fifth field row can
     * never exist with no module.
     *
     * `formats` mirrors `46.2-FORMAT-INVENTORY.md` EXACTLY. `worksheet.pdf` is
     * null on purpose (DC-07, NOT DELIVERED): there is no worksheet PDF Blade,
     * and `worksheets.engineer-report-pdf` is a different document that
     * `abort_if`s 404 for exactly the PM this panel serves. Keep the null
     * EXACT — closing the gap later must be a deliberate edit here.
     *
     * @var array<string, array<string, mixed>>
     */
    private const DOCUMENT_FIELD_MAP = [
        ProjectDeliverable::KEY_SITE_SURVEY => [
            'generate_route' => 'site-surveys.from-project',
            'formats'        => ['word' => 'site-surveys.docx', 'pdf' => 'site-surveys.pdf'],
            'intro'          => 'These answers appear on the survey report and on the printable field form the surveyor takes to site.',
            'readiness'      => null,
            'groups'         => [
                [
                    'legend' => 'The survey',
                    'fields' => [
                        [
                            'key'      => 'survey_date',
                            'label'    => 'Survey date',
                            'type'     => self::TYPE_DATE,
                            'options'  => null,
                            'target'   => 'survey.survey_date',
                            'consumer' => ['file' => 'app/Services/SiteSurveyDocxService.php', 'symbol' => '$survey->survey_date'],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'date'],
                        ],
                        [
                            'key'      => 'surveyor_name',
                            'label'    => 'Surveyor',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'survey.surveyor_name',
                            'consumer' => ['file' => 'app/Services/SiteSurveyDocxService.php', 'symbol' => '$survey->surveyor_name'],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:150'],
                        ],
                        [
                            'key'      => 'site_contact_name',
                            'label'    => 'Site contact',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'survey.site_contact_name',
                            'consumer' => ['file' => 'resources/views/pdf/site-survey/_header-meta.blade.php', 'symbol' => '$survey?->site_contact_name'],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:150'],
                        ],
                        [
                            'key'      => 'site_contact_phone',
                            'label'    => 'Site contact phone',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'survey.site_contact_phone',
                            'consumer' => ['file' => 'resources/views/pdf/site-survey/_header-meta.blade.php', 'symbol' => '$survey?->site_contact_phone'],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:50'],
                        ],
                        [
                            'key'      => 'general_notes',
                            'label'    => 'General notes',
                            'type'     => self::TYPE_TEXTAREA,
                            'options'  => null,
                            'target'   => 'survey.general_notes',
                            'consumer' => ['file' => 'app/Services/SiteSurveyDocxService.php', 'symbol' => '$survey->general_notes'],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:5000'],
                        ],
                    ],
                ],
                [
                    // All five are consumed by the field-form and blank-form
                    // PDFs via `_header-meta.blade.php`, which SurveyPdfService
                    // renders at `:78` and `:100`.
                    'legend' => 'Access and logistics',
                    'fields' => [
                        [
                            'key'      => 'site_access_notes',
                            'label'    => 'Site access notes',
                            'type'     => self::TYPE_TEXTAREA,
                            'options'  => null,
                            'target'   => 'survey.site_access_notes',
                            'consumer' => ['file' => 'resources/views/pdf/site-survey/_header-meta.blade.php', 'symbol' => '$survey?->site_access_notes'],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:2000'],
                        ],
                        [
                            'key'      => 'parking_restraints',
                            'label'    => 'Parking arrangements',
                            'type'     => self::TYPE_TEXTAREA,
                            'options'  => null,
                            'target'   => 'survey.parking_restraints',
                            'consumer' => ['file' => 'resources/views/pdf/site-survey/_header-meta.blade.php', 'symbol' => '$survey?->parking_restraints'],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:2000'],
                        ],
                        [
                            'key'      => 'delivery_routes',
                            'label'    => 'Delivery routes',
                            'type'     => self::TYPE_TEXTAREA,
                            'options'  => null,
                            'target'   => 'survey.delivery_routes',
                            'consumer' => ['file' => 'resources/views/pdf/site-survey/_header-meta.blade.php', 'symbol' => '$survey?->delivery_routes'],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:2000'],
                        ],
                        [
                            'key'      => 'distance_from_base_miles',
                            'label'    => 'Distance from base (miles)',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'survey.distance_from_base_miles',
                            'consumer' => ['file' => 'resources/views/pdf/site-survey/_header-meta.blade.php', 'symbol' => '$survey?->distance_from_base_miles'],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'numeric', 'min:0', 'max:9999'],
                        ],
                        [
                            'key'      => 'distance_from_base_notes',
                            'label'    => 'Travel notes',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'survey.distance_from_base_notes',
                            'consumer' => ['file' => 'resources/views/pdf/site-survey/_header-meta.blade.php', 'symbol' => '$survey?->distance_from_base_notes'],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:500'],
                        ],
                    ],
                ],
                [
                    'legend' => 'Comms room',
                    'fields' => [
                        [
                            // OPTIONS ARE THE STORED VOCABULARY, not the
                            // labels the field-form Blade compares against.
                            // `SiteSurveyController.php:681` validates
                            // `in:yes,no,outsourced,unknown`, and
                            // `DocxBuilderService.php:695` /
                            // `pdf/rams-v2.blade.php:866` map those four to
                            // labels. `_header-meta.blade.php:66-69` compares
                            // against 'permission' / 'outsourced' / 'free'
                            // instead, so its "Permission required" and "Free"
                            // boxes can never tick — logged as D-46.2-04-02 and
                            // NOT fixed here. Offering the Blade's vocabulary
                            // in this map would store a value the validator
                            // rejects, so the stored set wins.
                            'key'      => 'comms_room_access_status',
                            'label'    => 'Comms room access',
                            'type'     => self::TYPE_RADIO,
                            'options'  => [
                                'yes'        => 'Permission required',
                                'no'         => 'Free access',
                                'outsourced' => 'Outsourced facilities team',
                                'unknown'    => 'Status unknown',
                            ],
                            'target'   => 'survey.comms_room_access_status',
                            'consumer' => ['file' => 'resources/views/pdf/site-survey/_header-meta.blade.php', 'symbol' => '$survey?->comms_room_access_status'],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'in:yes,no,outsourced,unknown'],
                        ],
                        [
                            'key'      => 'comms_room_access_notes',
                            'label'    => 'Comms room notes',
                            'type'     => self::TYPE_TEXTAREA,
                            'options'  => null,
                            'target'   => 'survey.comms_room_access_notes',
                            'consumer' => ['file' => 'resources/views/pdf/site-survey/_header-meta.blade.php', 'symbol' => '$survey?->comms_room_access_notes'],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:2000'],
                        ],
                    ],
                ],
                [
                    // Its own group because of its audience, not its size:
                    // `summary.blade.php:67` guards this block with
                    // `! $internal`, so it appears on the CLIENT report ONLY.
                    // The internal PDF never shows it.
                    'legend' => 'Client report only',
                    'fields' => [
                        [
                            'key'      => 'office_review_notes',
                            'label'    => 'Office review notes (client report only)',
                            'type'     => self::TYPE_TEXTAREA,
                            'options'  => null,
                            'target'   => 'survey.office_review_notes',
                            'consumer' => ['file' => 'resources/views/pdf/site-survey/summary.blade.php', 'symbol' => '$survey->office_review_notes'],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:5000'],
                        ],
                    ],
                ],
                [
                    'legend' => 'From the project',
                    'fields' => [
                        [
                            'key'      => 'project_name',
                            'label'    => 'Project',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'survey.project_name',
                            'consumer' => ['file' => 'app/Services/SiteSurveyDocxService.php', 'symbol' => '$survey->project_name'],
                            'prefill'  => 'project.name',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                        [
                            'key'      => 'project_ref',
                            'label'    => 'Project ref',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'survey.project_ref',
                            'consumer' => ['file' => 'resources/views/pdf/site-survey/summary.blade.php', 'symbol' => '$survey->project_ref'],
                            'prefill'  => 'project.ref',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                        [
                            'key'      => 'client_name',
                            'label'    => 'Client',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'survey.client_name',
                            'consumer' => ['file' => 'app/Services/SiteSurveyDocxService.php', 'symbol' => '$survey->client_name'],
                            'prefill'  => 'project.client_name',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                        [
                            'key'      => 'site_address',
                            'label'    => 'Site address',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'survey.site_address',
                            'consumer' => ['file' => 'app/Services/SiteSurveyDocxService.php', 'symbol' => '$survey->site_address'],
                            'prefill'  => 'project.site_address',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                    ],
                ],
            ],
        ],

        // THE WORKSHEET HAS ZERO PM-ENTERABLE FIELDS, AND THAT IS A FINDING,
        // NOT AN OMISSION. `WorksheetController::generateFromProject(Project
        // $project)` takes a Project and nothing else (`:151`); every value in
        // the document comes from the AI pipeline over the project package via
        // `BuildWorksheetJob`. The only PM-visible values are the four header
        // fields copied off the project at creation
        // (`WorksheetController.php:154-161`), and those are DISPLAY ONLY. The
        // user's examples — install dates, engineers, site contact — are RAMS
        // fields; they are NOT added here. `intro` says so in one line, per the
        // plan, so the PM understands why this form is short instead of
        // assuming it is broken.
        ProjectDeliverable::KEY_WORKSHEET => [
            'generate_route' => 'worksheets.generate-from-project',
            'formats'        => ['word' => 'worksheets.download', 'pdf' => null],
            'intro'          => 'The worksheet is built from the project package, so there is nothing to fill in here. Install dates, engineers and the site contact belong to the RAMS. Word only — there is no worksheet PDF (DC-07).',
            'readiness'      => null,
            'groups'         => [
                [
                    'legend' => 'From the project',
                    'fields' => [
                        [
                            'key'      => 'project_name',
                            'label'    => 'Project',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'worksheet.project_name',
                            'consumer' => ['file' => 'app/Services/WorksheetDocxService.php', 'symbol' => '$worksheet->project_name'],
                            'prefill'  => 'project.name',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                        [
                            'key'      => 'project_ref',
                            'label'    => 'Reference',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'worksheet.project_ref',
                            'consumer' => ['file' => 'app/Services/WorksheetDocxService.php', 'symbol' => '$worksheet->project_ref'],
                            'prefill'  => 'project.ref',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                        [
                            'key'      => 'client_name',
                            'label'    => 'Client',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'worksheet.client_name',
                            'consumer' => ['file' => 'app/Services/WorksheetDocxService.php', 'symbol' => '$worksheet->client_name'],
                            'prefill'  => 'project.client_name',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                        [
                            'key'      => 'site_address',
                            'label'    => 'Site',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'worksheet.site_address',
                            'consumer' => ['file' => 'app/Services/WorksheetDocxService.php', 'symbol' => '$worksheet->site_address'],
                            'prefill'  => 'project.site_address',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                    ],
                ],
            ],
        ],

        ProjectDeliverable::KEY_RAMS => [
            'generate_route' => 'rams.from-project',
            'formats'        => ['word' => 'rams.download', 'pdf' => 'rams.download-pdf'],
            'intro'          => 'Install dates, the team and the site contact. These appear on the RAMS cover, the document control table and Section 4.',
            'readiness'      => null,
            'groups'         => [
                [
                    'legend' => 'When',
                    'fields' => [
                        [
                            'key'      => 'planned_start_date',
                            'label'    => 'Planned start date',
                            'type'     => self::TYPE_DATE,
                            'options'  => null,
                            'target'   => 'programme.planned_start_date',
                            'consumer' => ['file' => 'app/Services/RamsBuilderService.php', 'symbol' => "\$programme['planned_start_date']"],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'date'],
                        ],
                        [
                            'key'      => 'planned_end_date',
                            'label'    => 'Planned end date',
                            'type'     => self::TYPE_DATE,
                            'options'  => null,
                            'target'   => 'programme.planned_end_date',
                            'consumer' => ['file' => 'app/Services/Rams/RamsDisplayPatchService.php', 'symbol' => "'planned_end_date'"],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'date', 'after_or_equal:planned_start_date'],
                        ],
                        [
                            // There is deliberately NO `planned_end_time`
                            // sibling — see the class docblock and
                            // D-46.2-04-01.
                            'key'      => 'planned_start_time',
                            'label'    => 'Start time on site',
                            'type'     => self::TYPE_TIME,
                            'options'  => null,
                            'target'   => 'programme.planned_start_time',
                            'consumer' => ['file' => 'app/Services/Rams/RamsDisplayPatchService.php', 'symbol' => "'planned_start_time'"],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:20'],
                        ],
                        [
                            // GREP OVERRULED THE PLAN HERE. The plan's
                            // <interfaces> proposed two radios writing the
                            // `in_hours|out_of_hours` enum at
                            // `programme.working_hours`. NO GENERATOR READS
                            // THAT ENUM (0 hits for `$prog['working_hours']` /
                            // `$programme['working_hours']` across the whole
                            // generator set); the only consumer is the review
                            // form that writes it
                            // (`ProjectPackageReviewController.php:1132`). What
                            // the cover and Section 4 actually render is a
                            // human-readable STRING from
                            // `form_data.working_hours`
                            // (`CoverComposer.php:56`, `rams-v2.blade.php:420`,
                            // `DocxBuilderService.php:663`), validated at
                            // `RamsFormRequest.php:64`. So: a text field, at
                            // the target that reaches the document. Note this
                            // target is `form_data`, NOT `reviewed_data` — it
                            // is the ONE exception to the normalised-key rule,
                            // and it is an exception because
                            // `normaliseProject()` is exactly 11 keys
                            // (`RamsReviewDataService.php:98`) and
                            // `working_hours` is not among them, so there is no
                            // reviewed_data home for it that survives
                            // `normalise()`.
                            'key'      => 'working_hours',
                            'label'    => 'Working hours',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'form_data.working_hours',
                            'consumer' => ['file' => 'app/Support/Rams/SectionComposers/CoverComposer.php', 'symbol' => "\$formData['working_hours']"],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:200'],
                        ],
                    ],
                ],
                [
                    // Six fields — exactly at the DC-08 budget. A seventh
                    // belongs in a new group, which is why programmers has one.
                    'legend' => 'Who',
                    'fields' => [
                        [
                            'key'      => 'project_manager_name',
                            'label'    => 'Project manager',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'programme.project_manager_name',
                            'consumer' => ['file' => 'app/Services/RamsBuilderService.php', 'symbol' => "\$programme['project_manager_name']"],
                            'prefill'  => 'project.owner_name',
                            'rules'    => ['nullable', 'string', 'max:150'],
                        ],
                        [
                            'key'      => 'project_manager_phone',
                            'label'    => 'PM phone',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'programme.project_manager_phone',
                            'consumer' => ['file' => 'app/Services/RamsBuilderService.php', 'symbol' => "\$programme['project_manager_phone']"],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:50'],
                        ],
                        [
                            'key'      => 'project_manager_email',
                            'label'    => 'PM email',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'programme.project_manager_email',
                            'consumer' => ['file' => 'app/Services/RamsBuilderService.php', 'symbol' => "\$programme['project_manager_email']"],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'email', 'max:150'],
                        ],
                        [
                            // NAME ONLY (LR-04). The option source supplies the
                            // active engineers' names; their email and phone
                            // never leave `LabourResource`.
                            'key'      => 'lead_engineer_name',
                            'label'    => 'Lead engineer',
                            'type'     => self::TYPE_RESOURCE_LIST,
                            'options'  => null,
                            'target'   => 'programme.lead_engineer_name',
                            'consumer' => ['file' => 'app/Services/RamsBuilderService.php', 'symbol' => "\$programme['lead_engineer_name']"],
                            'prefill'  => self::PREFILL_ENGINEERS,
                            'rules'    => ['nullable', 'string', 'max:150'],
                        ],
                        [
                            'key'      => 'lead_engineer_phone',
                            'label'    => 'Lead engineer phone',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'programme.lead_engineer_phone',
                            'consumer' => ['file' => 'app/Services/RamsBuilderService.php', 'symbol' => "\$programme['lead_engineer_phone']"],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:50'],
                        ],
                        [
                            'key'      => 'additional_engineers',
                            'label'    => 'Other engineers on site',
                            'type'     => self::TYPE_RESOURCE_LIST,
                            'options'  => null,
                            'target'   => 'programme.additional_engineers',
                            'consumer' => ['file' => 'app/Services/RamsBuilderService.php', 'symbol' => "\$programme['additional_engineers']"],
                            'prefill'  => self::PREFILL_ENGINEERS,
                            'rules'    => ['nullable', 'array'],
                        ],
                    ],
                ],
                [
                    'legend' => 'Programmers',
                    'fields' => [
                        [
                            'key'      => 'programmers',
                            'label'    => 'Programmers',
                            'type'     => self::TYPE_RESOURCE_LIST,
                            'options'  => null,
                            'target'   => 'programme.programmers',
                            'consumer' => ['file' => 'app/Services/RamsBuilderService.php', 'symbol' => "\$programme['programmers']"],
                            'prefill'  => self::PREFILL_PROGRAMMERS,
                            'rules'    => ['nullable', 'array'],
                        ],
                    ],
                ],
                [
                    // The ONLY three `site_logistics` keys a generator reads —
                    // `RamsDisplayPatchService.php:132-142` resolves them into
                    // `client_contact_name/_email/_phone`. The other eight keys
                    // `normaliseSiteLogistics()` emits are REJECTED; see the
                    // class docblock.
                    'legend' => 'Site contact',
                    'fields' => [
                        [
                            'key'      => 'contact_name',
                            'label'    => 'Site contact',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'site_logistics.contact_name',
                            'consumer' => ['file' => 'app/Services/Rams/RamsDisplayPatchService.php', 'symbol' => "\$sl['contact_name']"],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:150'],
                        ],
                        [
                            'key'      => 'contact_phone',
                            'label'    => 'Site contact phone',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'site_logistics.contact_phone',
                            'consumer' => ['file' => 'app/Services/Rams/RamsDisplayPatchService.php', 'symbol' => "\$sl['contact_phone']"],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'string', 'max:50'],
                        ],
                        [
                            'key'      => 'contact_email',
                            'label'    => 'Site contact email',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'site_logistics.contact_email',
                            'consumer' => ['file' => 'app/Services/Rams/RamsDisplayPatchService.php', 'symbol' => "\$sl['contact_email']"],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'email', 'max:150'],
                        ],
                    ],
                ],
                [
                    'legend' => 'From the project',
                    'fields' => [
                        [
                            'key'      => 'project_name',
                            'label'    => 'Project',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'project.project_name',
                            'consumer' => ['file' => 'app/Services/RamsBuilderService.php', 'symbol' => "\$project['project_name']"],
                            'prefill'  => 'project.name',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                        [
                            'key'      => 'quote_ref',
                            'label'    => 'Quote ref',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'project.quote_ref',
                            'consumer' => ['file' => 'app/Services/RamsBuilderService.php', 'symbol' => "\$project['quote_ref']"],
                            'prefill'  => 'project.ref',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                        [
                            'key'      => 'client_name',
                            'label'    => 'Client',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'project.client_name',
                            'consumer' => ['file' => 'app/Services/RamsBuilderService.php', 'symbol' => "\$project['client_name']"],
                            'prefill'  => 'project.client_name',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                        [
                            'key'      => 'site_address',
                            'label'    => 'Site address',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'project.site_address',
                            'consumer' => ['file' => 'app/Services/RamsBuilderService.php', 'symbol' => "\$project['site_address']"],
                            'prefill'  => 'project.site_address',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                    ],
                ],
            ],
        ],

        // THE O&M HAS EXACTLY TWO REAL INPUTS. Everything else its validator
        // requires is project, room and drawing data — rooms, per-room
        // equipment and narrative, at least one drawing, plus the document date
        // which is ALWAYS today (`OmManualGeneratorService.php:393`). Those are
        // a READINESS LIST, never inputs a PM could type a lie into, and
        // `readiness()` renders the validator's own missing-field list rather
        // than re-deriving a second opinion about what is missing.
        ProjectDeliverable::KEY_OM => [
            'generate_route' => 'om-manuals.generate-from-project',
            'formats'        => ['word' => 'om-manuals.download', 'pdf' => 'om-manuals.download-pdf'],
            'intro'          => 'The O&M is built from the project rooms, equipment and drawings. Only the handover date and the issue mode are entered here.',
            'readiness'      => self::READINESS_OM_VALIDATOR,
            'groups'         => [
                [
                    'legend' => 'Handover',
                    'fields' => [
                        [
                            'key'      => 'handover_date',
                            'label'    => 'Handover date',
                            'type'     => self::TYPE_DATE,
                            'options'  => null,
                            'target'   => 'project.handover_date',
                            'consumer' => ['file' => 'app/Core/Modules/OMManual/OmManualGeneratorService.php', 'symbol' => '$project->handover_date'],
                            'prefill'  => 'project.handover_date',
                            'rules'    => ['nullable', 'date'],
                        ],
                        [
                            // Default is Final issue: the strict Tier-1 NO-TBC
                            // policy is the norm and draft is the exception,
                            // so the safe option is the one already selected.
                            'key'      => 'draft',
                            'label'    => 'Issue',
                            'type'     => self::TYPE_RADIO,
                            'options'  => [
                                '0' => 'Final issue (no TBC)',
                                '1' => 'Draft (placeholders allowed)',
                            ],
                            'target'   => 'query.draft',
                            'consumer' => ['file' => 'app/Http/Controllers/OmManualController.php', 'symbol' => "boolean('draft')"],
                            'prefill'  => null,
                            'rules'    => ['nullable', 'boolean'],
                        ],
                    ],
                ],
                [
                    'legend' => 'From the project',
                    'fields' => [
                        [
                            'key'      => 'project_name',
                            'label'    => 'Project',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'om_context.project_name',
                            'consumer' => ['file' => 'app/Services/OmManualValidationService.php', 'symbol' => "\$data['project_name']"],
                            'prefill'  => 'project.name',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                        [
                            'key'      => 'client_name',
                            'label'    => 'Client',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'om_context.client_name',
                            'consumer' => ['file' => 'app/Services/OmManualValidationService.php', 'symbol' => "\$data['client_name']"],
                            'prefill'  => 'project.client_name',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                        [
                            'key'      => 'site_address',
                            'label'    => 'Site address',
                            'type'     => self::TYPE_TEXT,
                            'options'  => null,
                            'target'   => 'om_context.site_address',
                            'consumer' => ['file' => 'app/Services/OmManualValidationService.php', 'symbol' => "\$data['site_address']"],
                            'prefill'  => 'project.site_address',
                            'readonly' => true,
                            'rules'    => self::RULES_DISPLAY_ONLY,
                        ],
                    ],
                ],
            ],
        ],
    ];

    /**
     * The field map as data, so tests ITERATE it rather than sample it — the
     * same mechanism that made the visit-type invariant provable in
     * `CockpitModulePresenter`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function documentFieldMap(): array
    {
        return self::DOCUMENT_FIELD_MAP;
    }

    /**
     * One document's groups.
     *
     * An unknown key returns `[]` and NEVER throws — the same "this presenter
     * never invents a module" contract `CockpitModulePresenter::progress()`
     * already keeps.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fieldsFor(string $documentKey): array
    {
        return self::DOCUMENT_FIELD_MAP[$documentKey]['groups'] ?? [];
    }

    /**
     * The readiness list for a document — the things that must be TRUE about
     * the project before the document can be generated, as opposed to the
     * things a PM types.
     *
     * DELEGATED, NOT RE-DERIVED. The O&M's list is
     * `OmManualValidationService`'s own missing-field output, read off the
     * exception it already throws. This class forms no second opinion about
     * what is missing, so the panel and the generator can never disagree.
     *
     * The other three documents have no readiness source and return `[]`, as
     * does an unknown key. A FIFTH document is still one row: it either names
     * an existing readiness kind or `null`.
     *
     * @return array<int, string>
     */
    public function readiness(Project $project, string $documentKey): array
    {
        $kind = self::DOCUMENT_FIELD_MAP[$documentKey]['readiness'] ?? null;

        if ($kind === null) {
            return [];
        }

        return match ($kind) {
            // No default arm on purpose: a row naming a readiness kind with no
            // delegate must fail LOUDLY here rather than render an empty list
            // that reads as "everything is ready".
            self::READINESS_OM_VALIDATOR => $this->omManualReadiness($project),
        };
    }

    /**
     * READ ONLY. `buildContextFromProjectData()` resolves project data and
     * builds rooms; the device-seeding write in this service lives in
     * `generateContent()` (`:669`) and is NOT on this path.
     *
     * @return array<int, string>
     */
    private function omManualReadiness(Project $project): array
    {
        try {
            $context = app(OmManualGeneratorService::class)->buildContextFromProjectData($project);
        } catch (Throwable) {
            // Not a missing-FIELD claim — the project data could not be read at
            // all. Said plainly rather than reported as a satisfied readiness
            // list, which would invite a generation attempt that then fails.
            return ['project data could not be read'];
        }

        try {
            app(OmManualValidationService::class)->validateOmData($context);
        } catch (OmManualValidationException $e) {
            return $e->getMissingFields();
        }

        return [];
    }

    /**
     * The `LabourResource` roles a `resource-list` field may draw its NAMES
     * from. Named here so Plan 46.2-05 resolves a role constant rather than
     * inventing a query, and so LR-04 (NAME ONLY) has one place to hold.
     *
     * @return array<string, string>
     */
    public static function resourceListRoles(): array
    {
        return [
            self::PREFILL_ENGINEERS   => LabourResource::ROLE_ENGINEER,
            self::PREFILL_PROGRAMMERS => LabourResource::ROLE_PROGRAMMER,
        ];
    }
}
