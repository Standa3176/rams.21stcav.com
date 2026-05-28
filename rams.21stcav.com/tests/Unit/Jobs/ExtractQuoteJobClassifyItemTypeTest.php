<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ExtractQuoteJob;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Regression tests for ExtractQuoteJob::classifyItemType().
 *
 * Bug B (quick task 260528-h8e — 21CQ30485-03-OPS): the consumable
 * description-keyword fallback was guarded by `if (\$upper === '')`, so
 * real-part-numbered cables, patch leads and mains extensions were silently
 * forced into 'hardware'. The service-keyword loop downstream runs
 * unconditionally — the consumable loop must mirror that to match the
 * canonical "physically installed product vs bulk material" semantic.
 *
 * Tests invoke the private method via ReflectionMethod (precedent:
 * ReviewedDataStructuralDiffTest's private-controller pattern). The job is
 * instantiated without invoking __construct() so we don't need a real
 * ProjectPackage / User to exercise the pure classifier.
 *
 * No Laravel bootstrapping required — the method is pure PHP with no
 * dependencies on the container, database, or HTTP.
 */
class ExtractQuoteJobClassifyItemTypeTest extends TestCase
{
    private ReflectionMethod $classify;
    private ExtractQuoteJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        $reflectionClass = new ReflectionClass(ExtractQuoteJob::class);
        $this->job       = $reflectionClass->newInstanceWithoutConstructor();
        $this->classify  = $reflectionClass->getMethod('classifyItemType');
        $this->classify->setAccessible(true);
    }

    private function classify(string $partNo, string $description): string
    {
        return (string) $this->classify->invoke($this->job, $partNo, $description);
    }

    // =========================================================================
    // BUG B — REAL PART-NUMBERED CONSUMABLES (the failing cases)
    // =========================================================================

    public function test_real_part_numbered_cat5e_patch_lead_classifies_as_consumable(): void
    {
        // 21CQ30485-03-OPS line item that was wrongly classed as 'hardware'.
        $this->assertSame('consumable', $this->classify('CS17461', '7m Black Cat5e Ethernet Patch Lead'));
    }

    public function test_real_part_numbered_cat5e_10m_classifies_as_consumable(): void
    {
        // 21CQ30485-03-OPS line item that was wrongly classed as 'hardware'.
        $this->assertSame('consumable', $this->classify('AV16131', 'Cat5e RJ45 Ethernet Patch Lead, 10m'));
    }

    public function test_real_part_numbered_4_gang_mains_extension_classifies_as_consumable(): void
    {
        // 21CQ30485-03-OPS line item — requires the new 'mains extension' /
        // 'extension lead' keyword (existing $consumableDescKeywords didn't
        // cover power extensions).
        $this->assertSame('consumable', $this->classify('PL12980', '4 Gang Mains Extension Lead'));
    }

    public function test_real_part_numbered_power_extension_classifies_as_consumable(): void
    {
        // 21CQ30485-03-OPS line item — variant with length suffix.
        $this->assertSame('consumable', $this->classify('PL15290', '4 Gang Mains Extension Lead (1m)'));
    }

    // =========================================================================
    // BUG B — REGRESSION GUARDS (must STILL pass after the change)
    // =========================================================================

    /**
     * Truly-unknown items default to 'hardware' — user decision documented in
     * 260528-h8e PLAN.md ("classifier default remains 'hardware' for
     * truly-unknown items").
     */
    public function test_unknown_part_with_no_keywords_still_defaults_to_hardware(): void
    {
        $this->assertSame('hardware', $this->classify('XYZ999', 'Generic Mounting Plate Assembly'));
    }

    /**
     * The original blank-part-number consumable path must keep working —
     * removing the `if (\$upper === '')` guard widens the rule, never
     * narrows it.
     */
    public function test_blank_part_with_cable_description_still_classifies_as_consumable(): void
    {
        $this->assertSame('consumable', $this->classify('', 'HDMI cable 3m'));
    }

    /**
     * Service description keywords still beat the unknown→hardware default.
     */
    public function test_service_keyword_in_description_still_wins_over_unknown_hardware(): void
    {
        $this->assertSame('professional_service', $this->classify('ENG', 'Site survey'));
    }

    /**
     * Ordering invariant: CS prefix (customer_supplied) check sits ABOVE the
     * consumable description fallback. Removing the `$upper === ''` guard
     * must NOT change this — CS-FOO with a 'patch lead' description still
     * returns 'customer_supplied'.
     */
    public function test_cs_prefix_customer_supplied_still_short_circuits_before_consumable_branch(): void
    {
        $this->assertSame('customer_supplied', $this->classify('CS-FOO', 'Cat5e patch lead'));
    }
}
