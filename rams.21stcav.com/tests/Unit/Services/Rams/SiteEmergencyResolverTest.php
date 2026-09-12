<?php

namespace Tests\Unit\Services\Rams;

use App\Services\Rams\SiteEmergencyResolver;
use Tests\TestCase;

/**
 * Phase 29 Plan 02 (RULE-08, GATE-12) — direct-call proof for
 * SiteEmergencyResolver::resolve()/classify(). No reflection needed — both
 * methods are public static (mirrors Ffp2ConfinedSpaceGateTest's shape,
 * simplified: this resolver has no private-method internals to reach into).
 *
 * @see app/Services/Rams/SiteEmergencyResolver.php
 * @see .planning/phases/29-cdm-duty-holder-emergency-arrangements/29-02-PLAN.md
 */
class SiteEmergencyResolverTest extends TestCase
{
    private const HOLD_POINT = 'Nearest A&E — to be confirmed at induction (must be a 24/7 Emergency Department)';

    public function test_resolve_returns_hold_point_when_hospital_blank(): void
    {
        $result = SiteEmergencyResolver::resolve([
            'nearest_hospital' => '',
            'hospital_address' => '123 Example Road, Testtown, TE5 7ST',
        ]);

        $this->assertFalse($result['verified']);
        $this->assertSame(self::HOLD_POINT, $result['text']);
    }

    public function test_resolve_returns_hold_point_when_address_blank(): void
    {
        $result = SiteEmergencyResolver::resolve([
            'nearest_hospital' => 'St Mary\'s Hospital',
            'hospital_address' => '   ',
        ]);

        $this->assertFalse($result['verified']);
        $this->assertSame(self::HOLD_POINT, $result['text']);
    }

    public function test_resolve_returns_verified_when_both_present(): void
    {
        $result = SiteEmergencyResolver::resolve([
            'nearest_hospital' => 'St Mary\'s Hospital',
            'hospital_address' => '123 Example Road, Testtown, TE5 7ST',
        ]);

        $this->assertTrue($result['verified']);
        $this->assertSame(
            'St Mary\'s Hospital, 123 Example Road, Testtown, TE5 7ST. Route and travel time confirmed at induction.',
            $result['text'],
        );
    }

    public function test_classify_passes_hold_point_clean(): void
    {
        $this->assertNull(SiteEmergencyResolver::classify([
            'nearest_hospital' => '',
            'hospital_address' => '',
        ]));
    }

    public function test_classify_passes_hold_point_literal_with_blank_address(): void
    {
        // CR-01 repro (29-VERIFICATION.md gap 1): a PM copy-pastes the
        // HOLD_POINT sentence itself back into nearest_hospital. classify()
        // must recognise this as the sanctioned hold-point output, not fall
        // through to the missing_address_or_postcode check.
        $this->assertNull(SiteEmergencyResolver::classify([
            'nearest_hospital' => self::HOLD_POINT,
            'hospital_address' => '',
        ]));
    }

    public function test_classify_passes_hold_point_literal_regardless_of_address(): void
    {
        // The HOLD_POINT literal is clean regardless of what (if anything)
        // is in hospital_address — a PM could paste it into either field
        // independently, and the class must never narrow the clean path
        // based on unrelated fields.
        $this->assertNull(SiteEmergencyResolver::classify([
            'nearest_hospital' => self::HOLD_POINT,
            'hospital_address' => 'some address',
        ]));
    }

    public function test_classify_flags_banned_string(): void
    {
        $this->assertSame('banned_string', SiteEmergencyResolver::classify([
            'nearest_hospital' => 'to be identified at site induction',
            'hospital_address' => '123 Example Road, Testtown, TE5 7ST',
        ]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function urgentCareKeywordProvider(): array
    {
        return [
            'urgent care' => ['Testtown Urgent Care Centre'],
            'urgent treatment centre' => ['Testtown Urgent Treatment Centre'],
            'minor injury unit' => ['Testtown Minor Injury Unit'],
            'minor injuries unit' => ['Testtown Minor Injuries Unit'],
            'walk-in centre (hyphen)' => ['Testtown Walk-in Centre'],
            'walk in centre (space)' => ['Testtown Walk In Centre'],
        ];
    }

    /**
     * @dataProvider urgentCareKeywordProvider
     */
    public function test_classify_flags_urgent_care_keyword(string $hospitalName): void
    {
        $this->assertSame('urgent_care_keyword', SiteEmergencyResolver::classify([
            'nearest_hospital' => $hospitalName,
            'hospital_address' => '123 Example Road, Testtown, TE5 7ST',
        ]));
    }

    public function test_classify_flags_utc_keyword_with_word_boundary(): void
    {
        $this->assertSame('urgent_care_keyword', SiteEmergencyResolver::classify([
            'nearest_hospital' => 'Willow UTC',
            'hospital_address' => '123 Example Road, Testtown, TE5 7ST',
        ]));
    }

    public function test_classify_does_not_false_positive_utc_substring(): void
    {
        // "Baseline Health Centre" contains no whole-word "utc" token — the
        // word-boundary regex must not match on any substring occurrence,
        // proving the utc check does not misfire on an unrelated real
        // hospital name.
        $this->assertNull(SiteEmergencyResolver::classify([
            'nearest_hospital' => 'Baseline Health Centre',
            'hospital_address' => '123 Example Road, Testtown, TE5 7ST',
        ]));
    }

    public function test_classify_flags_missing_address(): void
    {
        $this->assertSame('missing_address_or_postcode', SiteEmergencyResolver::classify([
            'nearest_hospital' => 'St Mary\'s Hospital',
            'hospital_address' => '',
        ]));
    }

    public function test_classify_passes_verified_clean(): void
    {
        $this->assertNull(SiteEmergencyResolver::classify([
            'nearest_hospital' => 'St Mary\'s Hospital',
            'hospital_address' => '123 Example Road, Testtown, TE5 7ST',
        ]));
    }
}
