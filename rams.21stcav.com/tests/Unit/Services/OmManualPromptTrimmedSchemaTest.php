<?php

namespace Tests\Unit\Services;

use App\Core\AI\Prompts\OmManualPrompt;
use Tests\TestCase;

/**
 * Quick task 260726-fx4 Task 7 — the O&M content prompt's per-equipment
 * JSON schema must be trimmed from 12 fields to 4:
 *   installation, operation, maintenance, warnings.
 *
 * The prior 12-field ask forced the AI to hallucinate specifics it didn't
 * have — invented support phone numbers, generic "wipe with microfiber
 * cloth quarterly" filler for daily/weekly/monthly/annual arrays. Trimming
 * these keeps the AI output honest and reduces per-call cost.
 *
 * The removed fields are:
 *   troubleshooting, key_specifications, support_contacts,
 *   daily_ops, weekly_ops, monthly_ops, annual_ops, installation_notes,
 *   operation_guide, maintenance_schedule, manufacturer_contact
 *
 * Non-goal (per plan): DOCX / PDF renderer trim. The renderer never
 * consumed the per-equipment AI fields — everything on the page comes from
 * deterministic sections (operation_sections, maintenance_schedule top-level,
 * fault_finding, manufacturer_support, rooms_summary), so the removed
 * fields were already discarded at render time. Legacy O&M records in the
 * DB are untouched (per plan).
 */
class OmManualPromptTrimmedSchemaTest extends TestCase
{
    // ── The 4 canonical per-equipment fields must appear in the built schema ─

    public function test_built_prompt_lists_the_4_canonical_equipment_fields(): void
    {
        $prompt = OmManualPrompt::forContent();
        $built  = $prompt->build([
            'project_name' => 'X',
            'client_name'  => 'Y',
            'site_address' => 'Z',
            'rooms'        => [],
        ]);

        // Look at the JSON-schema block only — schema uses quoted keys.
        $this->assertStringContainsString('"installation"', $built);
        $this->assertStringContainsString('"operation"', $built);
        $this->assertStringContainsString('"maintenance"', $built);
        $this->assertStringContainsString('"warnings"', $built);
    }

    // ── The 8 removed fields must NOT appear in the built prompt schema ─────

    public function test_built_prompt_omits_the_removed_per_equipment_fields(): void
    {
        $prompt = OmManualPrompt::forContent();
        $built  = $prompt->build([
            'project_name' => 'X',
            'client_name'  => 'Y',
            'site_address' => 'Z',
            'rooms'        => [],
        ]);

        foreach ([
            '"troubleshooting"',
            '"key_specifications"',
            '"support_contacts"',
            '"installation_notes"',
            '"operation_guide"',
            '"maintenance_schedule"',
            '"manufacturer_contact"',
        ] as $removedField) {
            $this->assertStringNotContainsString($removedField, $built,
                "Removed field {$removedField} must not appear in the built prompt schema");
        }
    }

    // ── INSTRUCTIONS step 2 must name the 4 kept fields ─────────────────────

    public function test_instructions_explicitly_name_the_4_kept_fields(): void
    {
        $prompt = OmManualPrompt::forContent();
        $built  = $prompt->build([
            'project_name' => 'X',
            'client_name'  => 'Y',
            'site_address' => 'Z',
            'rooms'        => [],
        ]);

        // Case-insensitive match — instructions use lowercase field names.
        $this->assertMatchesRegularExpression('/installation.*operation.*maintenance.*warnings/is', $built,
            'INSTRUCTIONS must enumerate the 4 kept fields in order');
    }

    // ── INSTRUCTIONS must explicitly forbid the removed fields ──────────────

    public function test_instructions_explicitly_forbid_the_removed_fields(): void
    {
        $prompt = OmManualPrompt::forContent();
        $built  = $prompt->build([
            'project_name' => 'X',
            'client_name'  => 'Y',
            'site_address' => 'Z',
            'rooms'        => [],
        ]);

        $this->assertStringContainsString('Do NOT emit', $built);
        $this->assertStringContainsString('troubleshooting', $built);
        $this->assertStringContainsString('support_contacts', $built);
        $this->assertStringContainsString('key_specifications', $built);
    }
}
