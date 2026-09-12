<?php

namespace Tests\Feature\Rams;

use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\DocxBuilderService;
use App\Services\Rams\RamsComplianceUpgradeService;
use App\Services\Rams\RamsDisplayPatchService;
use App\Services\Rams\SiteEmergencyResolver;
use App\Support\Rams\RamsDocumentComposer;
use App\Support\Rams\RamsTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 29 Plan 04 (RULE-08/D-01/D-05/D-07) — proves a real end-to-end
 * render (not a hand-parsed blade snippet) can never surface the banned
 * passive string or a literal `'TBC'` A&E value, on any of the five render
 * sites RESEARCH.md Finding 3 maps, under either `RAMS_UNIFIED_COMPOSER`
 * state.
 *
 * Mirrors PdfSnapshotTest::renderBothBlades()'s real RamsDocument
 * fixture-build approach (create User + Project + RamsDocument, run
 * RamsDisplayPatchService::patch() then RamsComplianceUpgradeService::
 * upgrade() then RamsDocumentComposer::compose(), then render both blades
 * directly) rather than hand-building a fixture that bypasses the real
 * pipeline.
 *
 * `site_emergency` is set directly on `generated_data`, mirroring
 * `RamsController::updateAndDownload()`'s own mirror of
 * `reviewed_data['site_emergency']` into `generated_data['site_emergency']`
 * (`:584`) — this is the shape `RamsComplianceUpgradeService::
 * resolveSiteEmergency()` actually reads (`$data['site_emergency']`, not
 * `reviewed_data`), so a fixture that only sets `reviewed_data` would never
 * exercise the resolver through `upgrade()`.
 *
 * All other Section 7.0 fields (fire assembly point, fire warden, first
 * aider, defibrillator, isolation switch, extinguisher class) are always
 * populated in both fixtures so their OWN independent `'TBC'` fallbacks
 * (out of this plan's scope — see 29-04-PLAN.md Task 1 acceptance
 * criteria) never fire and pollute the "no TBC anywhere" assertions below.
 */
class SiteEmergencyRenderSitesRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const BANNED_STRING = 'to be identified at site induction';

    /** Every Section 7.0 field EXCEPT nearest_hospital/hospital_address, always populated. */
    private const OTHER_SITE_EMERGENCY_FIELDS = [
        'fire_assembly_point'         => 'Main gate assembly point',
        'fire_warden_name'            => 'Jordan Fire Warden',
        'fire_warden_contact'         => '07700 900111',
        'first_aider_name'            => 'Casey First Aider',
        'first_aider_contact'         => '07700 900222',
        'defibrillator_location'      => 'Reception, ground floor',
        'electrical_isolation_switch' => 'Sub-panel A, plant room',
        'fire_extinguisher_class'     => 'Class A + Class C (CO2)',
    ];

    /** @return array{verified: bool, text: string} the resolved hold-point branch, from the shared resolver — not re-derived here. */
    private function holdPointBranch(): array
    {
        return SiteEmergencyResolver::resolve(self::OTHER_SITE_EMERGENCY_FIELDS);
    }

    /** @return array{verified: bool, text: string} the resolved verified branch, from the shared resolver — not re-derived here. */
    private function verifiedBranch(): array
    {
        return SiteEmergencyResolver::resolve(array_merge(self::OTHER_SITE_EMERGENCY_FIELDS, [
            'nearest_hospital' => 'Queen Elizabeth Hospital',
            'hospital_address' => '123 Mindelsohn Way, Birmingham, B15 2GW',
        ]));
    }

    /**
     * Build a real, persisted RamsDocument with `generated_data['site_emergency']`
     * set to the given data state, run it through the real patch + upgrade
     * pipeline, and return both rendered blades plus the raw generated_data
     * (post-upgrade) for direct assertions.
     *
     * @return array{v1: string, v2: string, data: array}
     */
    private function renderBothBladesWith(array $siteEmergency): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        $rams = RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_ref'    => '21CQ00000-01-OPS',
            'project_name'   => 'Site Emergency Regression Test',
            'client_name'    => 'Regression Test Ltd',
            'site_address'   => '1 Regression Street, London',
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet-4-6',
            'filename'       => 'rams-site-emergency-regression.docx',
            'status'         => RamsDocument::STATUS_COMPLETED,
            'form_data'      => [],
            'reviewed_data'  => [
                'site_emergency' => $siteEmergency,
            ],
            'generated_data' => [
                'project' => [
                    'name'            => 'Site Emergency Regression Test',
                    'ref'             => '21CQ00000-01-OPS',
                    'client'          => 'Regression Test Ltd',
                    'site_address'    => '1 Regression Street, London',
                    'doc_author'      => 'Sonny',
                    'revision'        => 'Rev 1.0',
                    'document_status' => 'For Issue',
                    'working_hours'   => 'Monday–Friday, 09:00–17:30',
                ],
                'team'             => [
                    ['role' => 'Project Manager', 'name' => 'Sonny'],
                ],
                'hazards'          => [],
                'method_statement' => ['phases' => []],
                'site_emergency'   => $siteEmergency,
            ],
        ]);
        $rams->refresh();

        app(RamsDisplayPatchService::class)->patch($rams);

        // Real pipeline entry point (mirrors RamsController::upgrade() call
        // sites) — resolveSiteEmergency() populates
        // generated_data['site_emergency_resolved'] here, which the legacy
        // blade's Section 7.0 A&E row reads.
        $rams->generated_data = RamsComplianceUpgradeService::upgrade($rams->generated_data ?? []);
        $rams->save();
        $rams->refresh();

        $dto   = app(RamsDocumentComposer::class)->compose($rams);
        $theme = app(RamsTheme::class);

        $htmlV1 = view('pdf.rams', [
            'rams' => $rams,
            'data' => $rams->generated_data ?? [],
        ])->render();

        $htmlV2 = view('pdf.rams-v2', [
            'rams'  => $rams,
            'data'  => $rams->generated_data ?? [],
            'dto'   => $dto,
            'theme' => $theme,
        ])->render();

        return [
            'v1'   => $htmlV1,
            'v2'   => $htmlV2,
            'data' => $rams->generated_data ?? [],
        ];
    }

    /**
     * Build a real, persisted RamsDocument with `generated_data['site_emergency']`
     * set to the given data state, run it through `RamsDisplayPatchService::patch()`
     * ONLY (mirroring `RamsRegenerateSnapshotsCommand`'s render path, which never
     * calls `RamsComplianceUpgradeService::upgrade()`), and render `pdf.rams`
     * directly against the resulting `generated_data` — which will have NO
     * `site_emergency_resolved` key at all, reproducing the real bypass this
     * gap-closure plan exists to fix (29-VERIFICATION.md gap 3 / 29-REVIEW.md
     * WR-01).
     *
     * @return array{v1: string, data: array}
     */
    private function renderV1WithoutUpgrade(array $siteEmergency): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        $rams = RamsDocument::create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'project_ref'    => '21CQ00000-01-OPS',
            'project_name'   => 'Site Emergency No-Upgrade Regression Test',
            'client_name'    => 'Regression Test Ltd',
            'site_address'   => '1 Regression Street, London',
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet-4-6',
            'filename'       => 'rams-site-emergency-no-upgrade-regression.docx',
            'status'         => RamsDocument::STATUS_COMPLETED,
            'form_data'      => [],
            'reviewed_data'  => [
                'site_emergency' => $siteEmergency,
            ],
            'generated_data' => [
                'project' => [
                    'name'            => 'Site Emergency No-Upgrade Regression Test',
                    'ref'             => '21CQ00000-01-OPS',
                    'client'          => 'Regression Test Ltd',
                    'site_address'    => '1 Regression Street, London',
                    'doc_author'      => 'Sonny',
                    'revision'        => 'Rev 1.0',
                    'document_status' => 'For Issue',
                    'working_hours'   => 'Monday–Friday, 09:00–17:30',
                ],
                'team'             => [
                    ['role' => 'Project Manager', 'name' => 'Sonny'],
                ],
                'hazards'          => [],
                'method_statement' => ['phases' => []],
                'site_emergency'   => $siteEmergency,
            ],
        ]);
        $rams->refresh();

        // Mirrors RamsRegenerateSnapshotsCommand's actual render path: patch()
        // only, deliberately WITHOUT RamsComplianceUpgradeService::upgrade() —
        // so generated_data never gets a site_emergency_resolved key.
        app(RamsDisplayPatchService::class)->patch($rams);
        $rams->refresh();

        $this->assertArrayNotHasKey(
            'site_emergency_resolved',
            $rams->generated_data ?? [],
            'Test setup invariant broken: patch() must not populate site_emergency_resolved (that is upgrade()\'s job) — otherwise this test would not reproduce the real bypass.',
        );

        $htmlV1 = view('pdf.rams', [
            'rams' => $rams,
            'data' => $rams->generated_data ?? [],
        ])->render();

        return [
            'v1'   => $htmlV1,
            'data' => $rams->generated_data ?? [],
        ];
    }

    /** Extract the "Nearest A&E Hospital" table cell's raw inner text from a rendered blade. */
    private function extractNearestAeCell(string $html): string
    {
        $matched = preg_match(
            '/Nearest A&amp;E Hospital<\/td>\s*<td[^>]*>(.*?)<\/td>/s',
            $html,
            $m,
        );
        $this->assertSame(1, $matched, 'Could not locate the Nearest A&E Hospital table cell in rendered output.');

        return trim($m[1]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Hold-point data state (no nearest_hospital / hospital_address)
    // ══════════════════════════════════════════════════════════════════════

    public function test_hold_point_state_v1_blade_shows_holdpoint_no_banned_string_no_tbc(): void
    {
        config(['rams.unified_composer' => false]);

        ['v1' => $html, 'data' => $data] = $this->renderBothBladesWith(self::OTHER_SITE_EMERGENCY_FIELDS);

        $this->assertStringNotContainsString(self::BANNED_STRING, $html);
        $this->assertSame(
            $this->holdPointBranch()['text'],
            $data['site_emergency_resolved']['text'] ?? null,
            'upgrade() must resolve the hold-point branch when nearest_hospital/hospital_address are blank.',
        );
        $this->assertFalse($data['site_emergency_resolved']['verified'] ?? true);

        $cell = $this->extractNearestAeCell($html);
        $this->assertStringNotContainsString('TBC', $cell, 'A&E cell must never fall back to the literal TBC value.');
        $this->assertStringContainsString(
            'to be confirmed at induction (must be a 24/7 Emergency Department)',
            $cell,
        );
    }

    public function test_hold_point_state_v2_blade_shows_holdpoint_no_banned_string_no_tbc(): void
    {
        config(['rams.unified_composer' => true]);

        ['v2' => $html] = $this->renderBothBladesWith(self::OTHER_SITE_EMERGENCY_FIELDS);

        $this->assertStringNotContainsString(self::BANNED_STRING, $html);

        $cell = $this->extractNearestAeCell($html);
        $this->assertStringNotContainsString('TBC', $cell, 'A&E cell must never fall back to the literal TBC value.');
        $this->assertStringContainsString(
            'to be confirmed at induction (must be a 24/7 Emergency Department)',
            $cell,
        );
    }

    public function test_hold_point_state_welfare_bullet_points_to_section_7_on_both_blades(): void
    {
        ['v1' => $htmlV1, 'v2' => $htmlV2] = $this->renderBothBladesWith(self::OTHER_SITE_EMERGENCY_FIELDS);

        foreach (['v1' => $htmlV1, 'v2' => $htmlV2] as $variant => $html) {
            $this->assertStringNotContainsString(self::BANNED_STRING, $html, "banned string leaked into {$variant}");
            $this->assertStringContainsString(
                'Nearest A&amp;E — see Section 7.0.',
                $html,
                "Welfare bullet must point to Section 7.0 on {$variant}",
            );
            // First-aid certificate/kit content is kept, not dropped.
            $this->assertStringContainsString('First Aid at Work', $html);
            $this->assertStringContainsString('First aid kit carried at all times.', $html);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Verified data state (nearest_hospital + hospital_address both set)
    // ══════════════════════════════════════════════════════════════════════

    public function test_verified_state_v1_blade_shows_named_hospital_no_banned_string_no_tbc(): void
    {
        config(['rams.unified_composer' => false]);

        $siteEmergency = array_merge(self::OTHER_SITE_EMERGENCY_FIELDS, [
            'nearest_hospital' => 'Queen Elizabeth Hospital',
            'hospital_address' => '123 Mindelsohn Way, Birmingham, B15 2GW',
        ]);

        ['v1' => $html, 'data' => $data] = $this->renderBothBladesWith($siteEmergency);

        $this->assertStringNotContainsString(self::BANNED_STRING, $html);
        $this->assertTrue($data['site_emergency_resolved']['verified'] ?? false);

        $cell = $this->extractNearestAeCell($html);
        $this->assertStringNotContainsString('TBC', $cell);
        $this->assertStringNotContainsString('to be confirmed at induction', $cell, 'Verified branch must not show the hold-point line.');
        $this->assertStringContainsString('Queen Elizabeth Hospital', $cell);
        $this->assertStringContainsString('Route and travel time confirmed at induction.', $cell);
    }

    public function test_verified_state_v2_blade_shows_named_hospital_no_banned_string_no_tbc(): void
    {
        config(['rams.unified_composer' => true]);

        $siteEmergency = array_merge(self::OTHER_SITE_EMERGENCY_FIELDS, [
            'nearest_hospital' => 'Queen Elizabeth Hospital',
            'hospital_address' => '123 Mindelsohn Way, Birmingham, B15 2GW',
        ]);

        ['v2' => $html] = $this->renderBothBladesWith($siteEmergency);

        $this->assertStringNotContainsString(self::BANNED_STRING, $html);

        $cell = $this->extractNearestAeCell($html);
        $this->assertStringNotContainsString('TBC', $cell);
        $this->assertStringNotContainsString('to be confirmed at induction', $cell, 'Verified branch must not show the hold-point line.');
        $this->assertStringContainsString('Queen Elizabeth Hospital', $cell);
        $this->assertStringContainsString('Route and travel time confirmed at induction.', $cell);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 29-11 gap closure (29-UAT.md Gap 2): a WHOLLY EMPTY site_emergency (no
    // fields at all — not even the OTHER_SITE_EMERGENCY_FIELDS 8) must still
    // show the D-05 hold-point line, never a blank table and never "TBC".
    // Uses a brand-new empty array, deliberately NOT OTHER_SITE_EMERGENCY_FIELDS,
    // because that fixture is exactly what let the wholly-empty case escape
    // detection in the first place (29-UAT.md Gap 2 root cause).
    // ══════════════════════════════════════════════════════════════════════

    public function test_wholly_empty_site_emergency_v1_blade_shows_holdpoint_not_blank_no_tbc(): void
    {
        config(['rams.unified_composer' => false]);

        ['v1' => $html, 'data' => $data] = $this->renderBothBladesWith([]);

        $this->assertStringNotContainsString(self::BANNED_STRING, $html);
        $this->assertFalse($data['site_emergency_resolved']['verified'] ?? true);

        $cell = $this->extractNearestAeCell($html);
        $this->assertNotSame('', $cell, 'A&E cell must never be blank for a wholly empty site_emergency.');
        $this->assertStringNotContainsString('TBC', $cell, 'A&E cell must never fall back to the literal TBC value.');
        $this->assertStringContainsString(
            'to be confirmed at induction (must be a 24/7 Emergency Department)',
            $cell,
        );

        // The whole rendered Section 7.0 output, not just the A&E cell, must
        // never contain "TBC" — the reworded empty-state banner must not
        // reintroduce it.
        $sectionStart = strpos($html, '7.0 Site-Specific Emergency Details');
        $sectionEnd = strpos($html, '7.1 Emergency Contact Numbers');
        $this->assertNotFalse($sectionStart);
        $this->assertNotFalse($sectionEnd);
        $section = substr($html, $sectionStart, $sectionEnd - $sectionStart);
        $this->assertStringNotContainsString('TBC', $section, 'No render of Section 7.0 may contain the literal substring "TBC".');
    }

    public function test_wholly_empty_site_emergency_v2_blade_shows_holdpoint_not_blank_no_tbc(): void
    {
        config(['rams.unified_composer' => true]);

        ['v2' => $html] = $this->renderBothBladesWith([]);

        $this->assertStringNotContainsString(self::BANNED_STRING, $html);

        $cell = $this->extractNearestAeCell($html);
        $this->assertNotSame('', $cell, 'A&E cell must never be blank for a wholly empty site_emergency.');
        $this->assertStringNotContainsString('TBC', $cell, 'A&E cell must never fall back to the literal TBC value.');
        $this->assertStringContainsString(
            'to be confirmed at induction (must be a 24/7 Emergency Department)',
            $cell,
        );

        $sectionStart = strpos($html, '7.0 Site-Specific Emergency Details');
        $sectionEnd = strpos($html, '7.1 Emergency Contact Numbers');
        $this->assertNotFalse($sectionStart);
        $this->assertNotFalse($sectionEnd);
        $section = substr($html, $sectionStart, $sectionEnd - $sectionStart);
        $this->assertStringNotContainsString('TBC', $section, 'No render of Section 7.0 may contain the literal substring "TBC".');
    }

    // ══════════════════════════════════════════════════════════════════════
    // DocxBuilderService — site #5. Composer-state-independent
    // (buildWelfareArrangements() is shared by both build() branches), so
    // one composer state is sufficient per 29-04-PLAN.md Task 3's action.
    // ══════════════════════════════════════════════════════════════════════

    public function test_docx_welfare_bullet_never_contains_banned_string(): void
    {
        Storage::fake('documents');
        config(['rams.unified_composer' => false]);

        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        $rams = RamsDocument::factory()->create([
            'user_id'        => $owner->id,
            'project_id'     => $project->id,
            'generated_data' => [
                'project' => [
                    'name'            => 'Site Emergency Regression Test (DOCX)',
                    'ref'             => '21CQ00000-02-OPS',
                    'client'          => 'Regression Test Ltd',
                    'site_address'    => '1 Regression Street, London',
                    'doc_author'      => 'Sonny',
                    'revision'        => 'Rev 1.0',
                    'document_status' => 'For Issue',
                ],
                'team'             => [
                    ['role' => 'Project Manager', 'name' => 'Sonny'],
                ],
                'hazards'          => [],
                'method_statement' => ['phases' => []],
                'site_emergency'   => self::OTHER_SITE_EMERGENCY_FIELDS,
            ],
            'reviewed_data' => null,
            'status'        => RamsDocument::STATUS_COMPLETED,
        ]);

        $path = app(DocxBuilderService::class)->build($rams->generated_data ?? [], $rams->fresh());
        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Failed to open generated DOCX as zip.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($xml);

        $this->assertStringNotContainsString(self::BANNED_STRING, $xml);
        $this->assertStringContainsString('Nearest A&amp;E', $xml, 'DOCX Welfare bullet must carry the Section-pointer text.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 29-09 gap closure (29-VERIFICATION.md gap 3 / 29-REVIEW.md WR-01):
    // pdf.rams rendered WITHOUT upgrade() — the real RamsRegenerateSnapshotsCommand
    // bypass path — must never render a blank A&E cell.
    // ══════════════════════════════════════════════════════════════════════

    public function test_no_upgrade_hold_point_state_shows_holdpoint_not_blank(): void
    {
        ['v1' => $html] = $this->renderV1WithoutUpgrade(self::OTHER_SITE_EMERGENCY_FIELDS);

        $this->assertStringNotContainsString(self::BANNED_STRING, $html);

        $cell = $this->extractNearestAeCell($html);
        $this->assertNotSame('', $cell, 'A&E cell must never be blank when site_emergency_resolved is absent.');
        $this->assertStringNotContainsString('TBC', $cell, 'A&E cell must never fall back to the literal TBC value.');
        $this->assertStringContainsString(
            'to be confirmed at induction (must be a 24/7 Emergency Department)',
            $cell,
        );
    }

    public function test_no_upgrade_verified_state_shows_named_hospital_not_blank(): void
    {
        $siteEmergency = array_merge(self::OTHER_SITE_EMERGENCY_FIELDS, [
            'nearest_hospital' => 'Queen Elizabeth Hospital',
            'hospital_address' => '123 Mindelsohn Way, Birmingham, B15 2GW',
        ]);

        ['v1' => $html] = $this->renderV1WithoutUpgrade($siteEmergency);

        $this->assertStringNotContainsString(self::BANNED_STRING, $html);

        $cell = $this->extractNearestAeCell($html);
        $this->assertNotSame('', $cell, 'A&E cell must never be blank when site_emergency_resolved is absent.');
        $this->assertStringNotContainsString('TBC', $cell);
        $this->assertStringNotContainsString('to be confirmed at induction', $cell, 'Verified branch must not show the hold-point line.');
        $this->assertStringContainsString('Queen Elizabeth Hospital', $cell);
        $this->assertStringContainsString('Route and travel time confirmed at induction.', $cell);
    }
}
