<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\LegacyHazardNameFoldMap;
use Tests\TestCase;

/**
 * Phase 26 Plan 08 (HAZ-02 gap closure, round 2) — proves
 * LegacyHazardNameFoldMap::canonicalName()/all() in isolation, no DB.
 *
 * @see app/Services/Rams/LegacyHazardNameFoldMap.php
 */
class LegacyHazardNameFoldMapTest extends TestCase
{
    /** Test 1: case-insensitive, trim-only matching. */
    public function test_confined_spaces_resolves_to_restricted_access_and_ceiling_voids(): void
    {
        $this->assertSame('Restricted access and ceiling voids', LegacyHazardNameFoldMap::canonicalName('Confined Spaces'));
        $this->assertSame('Restricted access and ceiling voids', LegacyHazardNameFoldMap::canonicalName(' Confined Spaces '));
        $this->assertSame('Restricted access and ceiling voids', LegacyHazardNameFoldMap::canonicalName('CONFINED SPACES'));
    }

    /** Test 2: an unmapped name passes through untouched (D-04). */
    public function test_unmapped_name_returns_null(): void
    {
        $this->assertNull(LegacyHazardNameFoldMap::canonicalName('My Custom Site Hazard'));
    }

    /** Test 3: empty string never accidentally matches a map key. */
    public function test_empty_string_returns_null(): void
    {
        $this->assertNull(LegacyHazardNameFoldMap::canonicalName(''));
    }

    /** Test 4: the map's OUTPUT side can never re-introduce "Confined Spaces". */
    public function test_no_value_in_the_map_is_confined_spaces(): void
    {
        foreach (LegacyHazardNameFoldMap::all() as $value) {
            $this->assertNotSame('confined spaces', strtolower(trim($value)), "map value '{$value}' must never be Confined Spaces");
        }
    }
}
