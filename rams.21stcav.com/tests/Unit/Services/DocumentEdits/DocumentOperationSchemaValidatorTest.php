<?php

namespace Tests\Unit\Services\DocumentEdits;

use App\Services\DocumentEdits\DocumentOperationSchemaValidator;
use PHPUnit\Framework\TestCase;

class DocumentOperationSchemaValidatorTest extends TestCase
{
    private DocumentOperationSchemaValidator $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new DocumentOperationSchemaValidator();
    }

    private function goodPayload(): array
    {
        return [
            'operations' => [[
                'op'        => 'add_blocker',
                'target'    => ['room_name' => 'Boardroom', 'index' => null],
                'args'      => ['type' => 'power', 'message' => 'Check outlets.', 'action' => 'Call sparky.'],
                'rationale' => 'Need to confirm mains provision.',
            ]],
            'summary' => 'Add one power blocker for Boardroom.',
        ];
    }

    public function test_valid_payload_produces_no_errors(): void
    {
        $this->assertSame([], $this->svc->validate($this->goodPayload()));
    }

    public function test_non_object_root_rejected(): void
    {
        $this->assertSame('schema_not_object', $this->svc->validate('nope')[0]['code']);
    }

    public function test_unknown_top_level_key_rejected(): void
    {
        $p = $this->goodPayload();
        $p['evil_code'] = 'x';
        $codes = array_column($this->svc->validate($p), 'code');
        $this->assertContains('schema_unknown_top_key', $codes);
    }

    public function test_missing_operations_rejected(): void
    {
        $codes = array_column($this->svc->validate(['summary' => 'x']), 'code');
        $this->assertContains('schema_missing_operations', $codes);
    }

    public function test_empty_operations_rejected(): void
    {
        $codes = array_column($this->svc->validate(['operations' => [], 'summary' => 'x']), 'code');
        $this->assertContains('schema_operations_empty', $codes);
    }

    public function test_missing_summary_rejected(): void
    {
        $p = $this->goodPayload();
        unset($p['summary']);
        $codes = array_column($this->svc->validate($p), 'code');
        $this->assertContains('schema_missing_summary', $codes);
    }

    public function test_operation_missing_required_field_rejected(): void
    {
        $p = $this->goodPayload();
        unset($p['operations'][0]['args']);
        $codes = array_column($this->svc->validate($p), 'code');
        $this->assertContains('schema_operation_missing_field', $codes);
    }

    public function test_operation_unknown_key_rejected(): void
    {
        $p = $this->goodPayload();
        $p['operations'][0]['extra'] = 'x';
        $codes = array_column($this->svc->validate($p), 'code');
        $this->assertContains('schema_operation_unknown_key', $codes);
    }

    public function test_operation_empty_op_rejected(): void
    {
        $p = $this->goodPayload();
        $p['operations'][0]['op'] = '   ';
        $codes = array_column($this->svc->validate($p), 'code');
        $this->assertContains('schema_op_invalid', $codes);
    }

    public function test_max_operations_enforced(): void
    {
        $p = $this->goodPayload();
        $template = $p['operations'][0];
        $p['operations'] = array_fill(0, DocumentOperationSchemaValidator::MAX_OPERATIONS + 1, $template);
        $codes = array_column($this->svc->validate($p), 'code');
        $this->assertContains('schema_operations_too_many', $codes);
    }

    public function test_summary_length_cap_enforced(): void
    {
        $p = $this->goodPayload();
        $p['summary'] = str_repeat('x', DocumentOperationSchemaValidator::MAX_SUMMARY_LEN + 1);
        $codes = array_column($this->svc->validate($p), 'code');
        $this->assertContains('schema_summary_too_long', $codes);
    }

    public function test_rationale_length_cap_enforced(): void
    {
        $p = $this->goodPayload();
        $p['operations'][0]['rationale'] = str_repeat('x', DocumentOperationSchemaValidator::MAX_RATIONALE_LEN + 1);
        $codes = array_column($this->svc->validate($p), 'code');
        $this->assertContains('schema_rationale_too_long', $codes);
    }

    public function test_target_unknown_key_rejected(): void
    {
        $p = $this->goodPayload();
        $p['operations'][0]['target']['path'] = '/etc/passwd'; // schema-level rejection even before safety
        $codes = array_column($this->svc->validate($p), 'code');
        $this->assertContains('schema_target_unknown_key', $codes);
    }

    public function test_flatten_merges_args_and_room(): void
    {
        $p   = $this->goodPayload();
        $out = $this->svc->flattenToAdapterOps($p);
        $this->assertCount(1, $out);
        $this->assertSame('add_blocker', $out[0]['op']);
        $this->assertSame('power',       $out[0]['type']);
        $this->assertSame('Boardroom',   $out[0]['room']);
    }
}
