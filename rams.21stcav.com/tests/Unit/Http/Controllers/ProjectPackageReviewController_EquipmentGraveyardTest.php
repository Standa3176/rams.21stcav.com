<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\ProjectPackageReviewController;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Quick task 260723-eq1 — soft-delete graveyard round-trip.
 *
 * Locks the array-shaping contract of
 * ProjectPackageReviewController::parseReviewPayload() when equipment rows
 * carry a `deleted` flag:
 *
 *   - Active rows (deleted="0" or absent)   → $payload['equipment'][]
 *   - Deleted rows (deleted="1")            → $payload['equipment_deleted'][]
 *                                             with `deleted:true` + stamps
 *   - Round-trip preservation: a re-submitted graveyard row keeps its
 *     original deleted_at + deleted_by stamps (does NOT re-stamp with now()).
 *
 * Downstream services read only $raw['equipment'] so this split keeps them
 * clean without any migration or schema change.
 */
class ProjectPackageReviewController_EquipmentGraveyardTest extends TestCase
{
    private function invokeParseReviewPayload(Request $request): array
    {
        $controller = app(ProjectPackageReviewController::class);
        $method     = new ReflectionMethod($controller, 'parseReviewPayload');
        $method->setAccessible(true);

        return $method->invoke($controller, $request);
    }

    public function test_it_splits_active_and_deleted_rows(): void
    {
        $request = Request::create('/fake', 'POST', [
            'equipment' => [
                [
                    'quantity'    => 3,
                    'part_number' => 'AB-1',
                    'name'        => 'Display',
                    'area'        => 'Room 1',
                    'category'    => 'hardware',
                    // no `deleted` key → active
                ],
                [
                    'quantity'    => 1,
                    'part_number' => 'AB-2',
                    'name'        => 'Mount',
                    'area'        => 'Room 1',
                    'category'    => 'hardware',
                    'deleted'     => '1',
                ],
            ],
        ]);

        $payload = $this->invokeParseReviewPayload($request);

        $this->assertArrayHasKey('equipment', $payload);
        $this->assertArrayHasKey('equipment_deleted', $payload);

        $this->assertCount(1, $payload['equipment'], 'active bucket');
        $this->assertCount(1, $payload['equipment_deleted'], 'graveyard bucket');

        // Active row — clean, no deleted flag.
        $active = $payload['equipment'][0];
        $this->assertSame('AB-1', $active['part_number']);
        $this->assertSame('Display', $active['name']);
        $this->assertSame(3, $active['quantity']);
        $this->assertArrayNotHasKey('deleted', $active);
        $this->assertArrayNotHasKey('deleted_at', $active);
        $this->assertArrayNotHasKey('deleted_by', $active);

        // Deleted row — stamped with deleted:true + iso timestamp + user id.
        $dead = $payload['equipment_deleted'][0];
        $this->assertSame('AB-2', $dead['part_number']);
        $this->assertSame('Mount', $dead['name']);
        $this->assertTrue($dead['deleted']);
        $this->assertNotEmpty($dead['deleted_at']);
        // ISO8601 sanity — parseable back into a DateTime.
        $this->assertNotFalse(strtotime($dead['deleted_at']));
        $this->assertIsInt($dead['deleted_by']);
    }

    public function test_it_preserves_original_deleted_at_on_round_trip(): void
    {
        $originalStamp   = '2026-01-15T09:30:00+00:00';
        $originalUserId  = 42;

        $request = Request::create('/fake', 'POST', [
            'equipment' => [
                [
                    'quantity'    => 1,
                    'part_number' => 'ROUND-1',
                    'name'        => 'Old ghost',
                    'area'        => 'Room 7',
                    'category'    => 'hardware',
                    'deleted'     => '1',
                    'deleted_at'  => $originalStamp,
                    'deleted_by'  => (string) $originalUserId,
                ],
            ],
        ]);

        $payload = $this->invokeParseReviewPayload($request);

        $this->assertCount(0, $payload['equipment']);
        $this->assertCount(1, $payload['equipment_deleted']);

        $dead = $payload['equipment_deleted'][0];
        $this->assertTrue($dead['deleted']);
        $this->assertSame(
            $originalStamp,
            $dead['deleted_at'],
            'Re-submitted graveyard rows keep their original deleted_at, not now()'
        );
        $this->assertSame(
            $originalUserId,
            $dead['deleted_by'],
            'Re-submitted graveyard rows keep their original deleted_by'
        );
    }

    public function test_it_treats_deleted_zero_as_active(): void
    {
        $request = Request::create('/fake', 'POST', [
            'equipment' => [
                [
                    'quantity'    => 1,
                    'part_number' => 'ZERO-1',
                    'name'        => 'Restored',
                    'area'        => 'Room 2',
                    'category'    => 'hardware',
                    'deleted'     => '0',
                ],
            ],
        ]);

        $payload = $this->invokeParseReviewPayload($request);

        $this->assertCount(1, $payload['equipment']);
        $this->assertCount(0, $payload['equipment_deleted']);
        $this->assertArrayNotHasKey('deleted', $payload['equipment'][0]);
    }
}
