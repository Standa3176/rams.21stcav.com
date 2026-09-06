<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\PpeVocabularyFoldMap;
use Tests\TestCase;

/**
 * Phase 28 Plan 03 (research Q4 gap closure) — proves
 * PpeVocabularyFoldMap::canonical()/canonicalAll()/all() in isolation,
 * no DB.
 *
 * @see app/Services/Rams/PpeVocabularyFoldMap.php
 */
class PpeVocabularyFoldMapTest extends TestCase
{
    /** Test 1: exact match resolves to the FFP3 replacement. */
    public function test_dust_mask_ffp2_resolves_to_dust_mask_ffp3(): void
    {
        $this->assertSame('Dust Mask (FFP3)', PpeVocabularyFoldMap::canonical('Dust Mask (FFP2)'));
    }

    /** Test 2: case-insensitive lookup. */
    public function test_lowercase_ffp2_resolves_to_dust_mask_ffp3(): void
    {
        $this->assertSame('Dust Mask (FFP3)', PpeVocabularyFoldMap::canonical('dust mask (ffp2)'));
    }

    /** Test 3: trims before lookup. */
    public function test_padded_ffp2_resolves_to_dust_mask_ffp3(): void
    {
        $this->assertSame('Dust Mask (FFP3)', PpeVocabularyFoldMap::canonical(' Dust Mask (FFP2) '));
    }

    /**
     * Test 4: an unmapped PPE item passes through UNCHANGED (not null) —
     * unlike LegacyHazardNameFoldMap::canonicalName(), a miss here must
     * never drop the item from the caller's array.
     */
    public function test_unmapped_item_passes_through_unchanged(): void
    {
        $this->assertSame('Hi-Visibility Vest', PpeVocabularyFoldMap::canonical('Hi-Visibility Vest'));
    }

    /** Test 5: canonicalAll() resolves each element independently, order preserved. */
    public function test_canonical_all_resolves_each_element_independently_order_preserved(): void
    {
        $this->assertSame(
            ['Hi-Visibility Vest', 'Dust Mask (FFP3)'],
            PpeVocabularyFoldMap::canonicalAll(['Hi-Visibility Vest', 'Dust Mask (FFP2)']),
        );
    }

    /**
     * Test 6 (drift-guard): no value in the map ever contains the
     * substring 'FFP2' in any casing — mirrors
     * LegacyHazardNameFoldMapTest::test_no_value_in_the_map_is_confined_spaces().
     */
    public function test_no_value_in_the_map_contains_ffp2(): void
    {
        foreach (PpeVocabularyFoldMap::all() as $value) {
            $this->assertStringNotContainsStringIgnoringCase('FFP2', $value, "map value '{$value}' must never contain FFP2");
        }
    }
}
