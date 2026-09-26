<?php

namespace Tests\Unit\Support;

use App\Models\LabourResource;
use App\Models\Project;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Support\Worksheets\AllocatedEngineers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 46.4 Plan 01 Task 3 — the ONE door an engineer's name comes through.
 *
 * ── WHY THIS FILE IS STRICTER THAN IT LOOKS ─────────────────────────────────
 *
 * D-01: the CLIENT reads the engineer link. One page, one URL — the user chose
 * not to split engineer and client views. D-02: engineer NAMES appear on it.
 *
 * `LabourResource` carries `name`, `email` AND `phone`
 * (`app/Models/LabourResource.php:43-50`). LR-04 — *a client is never given an
 * engineer's phone or email, name only* — was previously enforced only on
 * office surfaces. D-01 makes it bind on a client-facing page.
 *
 * So the leak assertion below is run against a record that HAS a realistic
 * email AND a realistic phone. A test seeded with nulls would pass because
 * there was nothing to leak, which proves nothing at all.
 *
 * The full client-facing proof lands in plan 46.4-05. THE DOOR IS BUILT HERE,
 * AND IT IS BUILT SHUT.
 *
 * @see app/Support/Worksheets/AllocatedEngineers.php
 * @see tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-01, D-02)
 */
class AllocatedEngineersTest extends TestCase
{
    use RefreshDatabase;

    /** The complete key set. Anything else on a returned element is a leak. */
    private const EXPECTED_KEYS = ['id', 'name'];

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function worksheet(): Worksheet
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::factory()->create([
            'user_id'    => $user->id,
            'project_id' => $project->id,
        ]);
    }

    /** @param  array<int,int>  $ids */
    private function visitFor(Worksheet $worksheet, array $ids): Visit
    {
        return Visit::factory()->create([
            'project_id'          => $worksheet->project_id,
            'type'                => Visit::TYPE_INSTALL,
            'source_type'         => Visit::SOURCE_WORKSHEET,
            'source_id'           => $worksheet->id,
            'labour_resource_ids' => $ids,
        ]);
    }

    /**
     * An engineer with CONTACT DETAILS ON THE RECORD. The privacy assertions
     * are worthless without them.
     */
    private function engineerWithContactDetails(string $name): LabourResource
    {
        return LabourResource::factory()->create([
            'name'  => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@21stcav.com',
            'phone' => '07700 900' . fake()->numberBetween(100, 999),
            'roles' => [LabourResource::ROLE_ENGINEER],
        ]);
    }

    // ── The projection ───────────────────────────────────────────────────────

    public function test_it_returns_id_and_name_in_the_order_the_visit_lists_them(): void
    {
        $worksheet = $this->worksheet();

        $sean = $this->engineerWithContactDetails('Sean Pearce');
        $robert = $this->engineerWithContactDetails('Robert Allan');
        $shallom = $this->engineerWithContactDetails('Shallom Stafford');

        // Deliberately NOT ascending id — the visit's own order is the order.
        $this->visitFor($worksheet, [$shallom->id, $sean->id, $robert->id]);

        $this->assertSame(
            [
                ['id' => $shallom->id, 'name' => 'Shallom Stafford'],
                ['id' => $sean->id,    'name' => 'Sean Pearce'],
                ['id' => $robert->id,  'name' => 'Robert Allan'],
            ],
            AllocatedEngineers::forWorksheet($worksheet)
        );
    }

    public function test_it_returns_a_plain_array_not_a_model_collection(): void
    {
        $worksheet = $this->worksheet();
        $engineer = $this->engineerWithContactDetails('Sean Pearce');
        $this->visitFor($worksheet, [$engineer->id]);

        $result = AllocatedEngineers::forWorksheet($worksheet);

        $this->assertIsArray($result);
        $this->assertIsArray($result[0]);
        $this->assertSame([0], array_keys($result), 'The array must be a LIST, ready for json_encode.');
    }

    // ── LR-04: the leak assertion, run against real contact details ──────────

    /**
     * T-46.4-01-01. Walks EVERY returned element's key set against an exact
     * expected set — not `assertArrayNotHasKey('email')`, which would miss a
     * `mobile`, a `contact`, or a whole nested model.
     */
    public function test_no_returned_element_carries_anything_but_id_and_name(): void
    {
        $worksheet = $this->worksheet();

        $sean = $this->engineerWithContactDetails('Sean Pearce');
        $robert = $this->engineerWithContactDetails('Robert Allan');
        $this->visitFor($worksheet, [$sean->id, $robert->id]);

        // The fixture is only meaningful if there is something to leak.
        $this->assertNotNull($sean->fresh()->email);
        $this->assertNotNull($sean->fresh()->phone);

        $result = AllocatedEngineers::forWorksheet($worksheet);
        $this->assertCount(2, $result);

        foreach ($result as $element) {
            $this->assertSame(
                self::EXPECTED_KEYS,
                array_keys($element),
                'LR-04: name only. A client reads this page (D-01), and an email or a phone one '
                . 'attribute away from a name is how it leaks.'
            );
        }

        // Belt and braces: the serialised form carries no trace of either.
        $encoded = json_encode($result);
        $this->assertStringNotContainsString('@21stcav.com', $encoded);
        $this->assertStringNotContainsString('07700 900', $encoded);
        $this->assertStringNotContainsString('email', $encoded);
        $this->assertStringNotContainsString('phone', $encoded);
    }

    /**
     * The `->select(['id','name'])` is what makes the leak STRUCTURALLY
     * impossible rather than merely absent — the hydrated models never hold an
     * email or a phone to begin with, so no future `->toArray()`, no Blade
     * `@json`, and no debug dump can reach one.
     */
    public function test_the_query_selects_only_the_two_columns(): void
    {
        $source = file_get_contents(base_path('app/Support/Worksheets/AllocatedEngineers.php'));

        $this->assertMatchesRegularExpression(
            '/->select\(\s*\[\s*[\'"]id[\'"]\s*,\s*[\'"]name[\'"]\s*\]\s*\)/',
            $source,
            'The select is LOAD-BEARING. Returning whole models and trusting callers is how '
            . 'an email leaks onto a page a client reads.'
        );

        $this->assertStringNotContainsString("'email'", $source);
        $this->assertStringNotContainsString("'phone'", $source);
    }

    // ── The three no-engineer fallbacks. None of them may 500. ───────────────

    public function test_a_worksheet_with_no_visit_returns_an_empty_array(): void
    {
        $this->assertSame([], AllocatedEngineers::forWorksheet($this->worksheet()));
    }

    public function test_a_visit_with_an_empty_allocation_returns_an_empty_array(): void
    {
        $worksheet = $this->worksheet();
        $this->visitFor($worksheet, []);

        $this->assertSame([], AllocatedEngineers::forWorksheet($worksheet));
    }

    public function test_a_visit_with_a_null_allocation_returns_an_empty_array(): void
    {
        $worksheet = $this->worksheet();
        $visit = $this->visitFor($worksheet, []);
        // A pre-Phase-44 row, or a hand-edited one. The column is an array
        // cast, so NULL is reachable and must not be dereferenced blindly.
        $visit->forceFill(['labour_resource_ids' => null])->save();

        $this->assertSame([], AllocatedEngineers::forWorksheet($worksheet));
    }

    public function test_a_visit_naming_a_deleted_resource_returns_the_survivors(): void
    {
        $worksheet = $this->worksheet();

        $sean = $this->engineerWithContactDetails('Sean Pearce');
        $gone = $this->engineerWithContactDetails('Departed Contractor');
        $goneId = $gone->id;

        $this->visitFor($worksheet, [$sean->id, $goneId]);

        $gone->delete();

        $this->assertSame(
            [['id' => $sean->id, 'name' => 'Sean Pearce']],
            AllocatedEngineers::forWorksheet($worksheet),
            'A visit outlives the people on it. A missing resource is a gap in the list, '
            . 'never an exception on an engineer standing in a plant room.'
        );
    }

    public function test_every_engineer_being_gone_still_returns_an_empty_array(): void
    {
        $worksheet = $this->worksheet();
        $this->visitFor($worksheet, [98765, 98766]);

        $this->assertSame([], AllocatedEngineers::forWorksheet($worksheet));
    }

    // ── LR-02: deactivation preserves history ────────────────────────────────

    public function test_an_inactive_but_allocated_engineer_is_still_returned(): void
    {
        $worksheet = $this->worksheet();

        $retired = $this->engineerWithContactDetails('Retired Engineer');
        $retired->forceFill(['is_active' => false])->save();

        $this->visitFor($worksheet, [$retired->id]);

        $this->assertSame(
            [['id' => $retired->id, 'name' => 'Retired Engineer']],
            AllocatedEngineers::forWorksheet($worksheet),
            'LR-02: deactivating a resource must not erase them from a past visit. '
            . 'scopeActive() belongs on the ALLOCATION form, never on this read.'
        );
    }

    // ── It reads the worksheet's OWN visit and no other ──────────────────────

    public function test_it_ignores_a_visit_wrapping_a_different_source(): void
    {
        $worksheet = $this->worksheet();
        $other = $this->worksheet();

        $engineer = $this->engineerWithContactDetails('Sean Pearce');
        $this->visitFor($other, [$engineer->id]);

        // Same id, but a SITE SURVEY — the source_type half of the match.
        Visit::factory()->create([
            'project_id'          => $worksheet->project_id,
            'type'                => Visit::TYPE_SITE_SURVEY,
            'source_type'         => Visit::SOURCE_SITE_SURVEY,
            'source_id'           => $worksheet->id,
            'labour_resource_ids' => [$engineer->id],
        ]);

        $this->assertSame([], AllocatedEngineers::forWorksheet($worksheet));
    }
}
