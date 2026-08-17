<?php

namespace Tests\Feature\DocumentEdits;

use App\Jobs\BuildRamsDocumentJob;
use App\Models\RamsDocument;
use App\Models\User;
use App\Services\DocumentEdits\Adapters\RamsEditAdapter;
use App\Services\RamsBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 260817-jsg Task 3 — end-to-end proof that an AI-chat edit survives a full
 * RAMS regenerate. Covers both write targets audited in the quick-task plan:
 *
 *   - update_project_field  → reviewed_data.project_field_overrides
 *                              (Cause B — RamsBuilderService::buildFromReview()
 *                              overwrites generated_data wholesale on every
 *                              regen; Task 1 fixed this)
 *   - add_exclusion          → reviewed_data.exclusions directly (this op
 *                              was already durable — buildFromReview() never
 *                              touches reviewed_data.exclusions; it is read
 *                              straight off the model by ExclusionsComposer
 *                              at render time, per Support/Rams/SectionComposers
 *                              /ExclusionsComposer.php)
 *
 * update_project_field is asserted against the REBUILT generated_data (the
 * actual write target buildFromReview() persists project fields to — see
 * RamsDataBuilderService::resolveProjectFields()/normalise()). add_exclusion
 * is asserted against reviewed_data because generated_data never carries an
 * 'exclusions' key at all (confirmed: no such key is set anywhere in
 * RamsBuilderService.php / RamsDataBuilderService.php) — asserting against
 * generated_data for that op would only prove a location the app never reads.
 *
 * Regression guard: revert the RamsEditAdapter::applyUpdateProjectField()
 * durable-mirror write, OR RamsBuilderService::runFromReview()'s re-apply of
 * project_field_overrides, and test_update_project_field_survives_full_regen
 * fails — the chat edit is silently discarded by the next regenerate.
 *
 * AI is mocked via Http::fake() (MethodStatementGeneratorService calls out to
 * Claude) — no live AI calls, per plan constraint.
 */
class RamsEditAdapterRegenRoundTripTest extends TestCase
{
    use RefreshDatabase;

    /** Absolute paths of DOCX files written during the test run (cleaned up in tearDown). */
    private array $generatedFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->generatedFiles as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fakeClaudeResponse(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['phases' => [
                ['title' => 'Phase 1: Pre-works', 'steps' => ['Site induction', 'PPE check']],
            ]])]],
            'stop_reason' => 'end_turn',
        ], 200)]);
    }

    private function makeApprovedRams(User $user): RamsDocument
    {
        $reviewedData = [
            'project' => [
                'project_name' => 'Round Trip Test',
                'quote_ref'    => 'RT-001',
                'client_name'  => 'Acme Ltd',
                'site_name'    => 'Acme HQ',
                'site_address' => '1 Test Street',
                'site_contact' => 'Jane Doe',
            ],
            'equipment'              => [],
            'activities'             => [],
            'hazards'                => [],
            'ppe'                    => ['Safety Boots', 'Hi-Vis Vest'],
            'access'                 => [],
            'exclusions'             => [],
            'room_overviews'         => [],
            'method_statement_notes' => 'Ground-level install only.',
            'scope_of_works'         => 'Supply and install AV systems.',
            'works_overview'         => 'Two-sentence project overview.',
            'site_logistics'         => [],
        ];

        return RamsDocument::create([
            'user_id'        => $user->id,
            'project_ref'    => 'RT-001',
            'project_name'   => 'Round Trip Test',
            'client_name'    => 'Acme Ltd',
            'site_address'   => '1 Test Street',
            // Chat edits only ever happen against a completed RAMS (see the
            // drawer's :visible guard in resources/views/rams/review.blade.php),
            // and isStale() only evaluates completed/for_review/draft documents —
            // approved_at set below satisfies BuildRamsDocumentJob's approval
            // guard regardless of status, so STATUS_COMPLETED is safe here.
            'status'         => RamsDocument::STATUS_COMPLETED,
            'ai_provider'    => 'claude',
            'ai_model'       => 'claude-sonnet',
            'filename'       => 'pending-round-trip.docx',
            'approved_at'    => now(),
            'form_data'      => ['source' => 'quote_upload'],
            'generated_data' => [],
            'reviewed_data'  => $reviewedData,
        ]);
    }

    /** Runs the SAME job the "↻ Regenerate" button / rams.regenerate route dispatches. */
    private function regenerate(RamsDocument $rams): RamsDocument
    {
        $this->fakeClaudeResponse();
        (new BuildRamsDocumentJob($rams->id))->handle(app(RamsBuilderService::class));

        $fresh = $rams->fresh();

        $candidate = storage_path('app/rams/' . $fresh->filename);
        if (file_exists($candidate)) {
            $this->generatedFiles[] = $candidate;
        }

        return $fresh;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_update_project_field_survives_full_regen(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeApprovedRams($user);

        // 1) Apply the AI-chat edit exactly as DocumentEditController::applyChangeSet() does.
        $adapter = new RamsEditAdapter();
        $payload = $adapter->loadPayload($rams->id);
        $res = $adapter->applyOperation($payload, [
            'op'    => 'update_project_field',
            'field' => 'site_contact',
            'value' => 'John Smith (chat edit)',
        ]);
        $this->assertTrue($res['ok'], 'update_project_field op should succeed');
        $adapter->commitChanges($rams->id, $res['payload']);

        // Sanity — the edit is visible immediately (existing behaviour, unchanged).
        $afterApply = $rams->fresh();
        $this->assertSame(
            'John Smith (chat edit)',
            $afterApply->generated_data['project']['site_contact'] ?? null,
            'chat edit did not land on generated_data immediately after apply',
        );
        // The durable mirror this test guards against regressing.
        $this->assertSame(
            'John Smith (chat edit)',
            $afterApply->reviewed_data['project_field_overrides']['site_contact'] ?? null,
            'chat edit was not mirrored into reviewed_data.project_field_overrides (Task 1 fix)',
        );
        $this->assertNotEmpty(
            $afterApply->reviewed_data['_pending_regen_since'] ?? null,
            'commitChanges() should mark the document stale until the next regen (Task 2)',
        );
        $this->assertTrue($afterApply->isStale(), 'RamsDocument::isStale() should be true after an unregenerated chat edit');

        // 2) Regenerate — the exact operation the user reported as broken.
        $rebuilt = $this->regenerate($rams);

        // 3) The chat edit must still be present in the REBUILT generated_data.
        $this->assertSame(
            'John Smith (chat edit)',
            $rebuilt->generated_data['project']['site_contact'] ?? null,
            'update_project_field chat edit was discarded by regen (Cause B, 260817-jsg) — '
            . 'RamsBuilderService::buildFromReview() overwrites generated_data from reviewed_data '
            . 'and the edit never reached reviewed_data.',
        );

        // The stale flag must be cleared by a successful regen (Task 2).
        $this->assertArrayNotHasKey(
            '_pending_regen_since',
            $rebuilt->reviewed_data ?? [],
            'stale flag was not cleared after a successful regen',
        );
        $this->assertFalse($rebuilt->isStale(), 'RamsDocument::isStale() should be false immediately after a successful regen');
    }

    public function test_add_exclusion_survives_full_regen(): void
    {
        $user = User::factory()->create();
        $rams = $this->makeApprovedRams($user);

        $adapter = new RamsEditAdapter();
        $payload = $adapter->loadPayload($rams->id);
        $res = $adapter->applyOperation($payload, [
            'op'   => 'add_exclusion',
            'text' => 'No structural works',
        ]);
        $this->assertTrue($res['ok'], 'add_exclusion op should succeed');
        $adapter->commitChanges($rams->id, $res['payload']);

        $rebuilt = $this->regenerate($rams);

        $this->assertContains(
            'No structural works',
            $rebuilt->reviewed_data['exclusions'] ?? [],
            'add_exclusion chat edit was discarded by regen — reviewed_data.exclusions '
            . 'must be untouched by RamsBuilderService::buildFromReview().',
        );
    }
}
