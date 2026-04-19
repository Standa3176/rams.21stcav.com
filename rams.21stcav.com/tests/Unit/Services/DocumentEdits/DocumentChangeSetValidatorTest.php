<?php

namespace Tests\Unit\Services\DocumentEdits;

use App\Services\DocumentEdits\DocumentChangeSetValidator;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;
use App\Services\DocumentEdits\DocumentEditSafetyValidator;
use PHPUnit\Framework\TestCase;

class DocumentChangeSetValidatorTest extends TestCase
{
    private DocumentChangeSetValidator $svc;
    private DocumentEditAdapterInterface $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new DocumentChangeSetValidator(new DocumentEditSafetyValidator());

        $this->adapter = new class implements DocumentEditAdapterInterface {
            public function documentType(): string { return 'worksheet'; }
            public function loadPayload(int $id): ?array { return []; }
            public function allowedOperations(): array { return ['update_room_field', 'add_blocker']; }
            public function applyOperation(array $payload, array $op): array {
                return ['ok' => false, 'code' => 'not_implemented', 'error' => 'stub'];
            }
            public function summariseDiff(array $before, array $after): array { return []; }
            public function commitChanges(int $documentId, array $payload): ?string { return null; }
        };
    }

    public function test_allowed_operation_passes(): void
    {
        $errors = $this->svc->validate([
            ['op' => 'update_room_field', 'field' => 'has_power', 'value' => true],
        ], $this->adapter);
        $this->assertSame([], $errors);
    }

    public function test_unknown_operation_is_rejected(): void
    {
        $errors = $this->svc->validate([
            ['op' => 'not_a_real_op', 'x' => 1],
        ], $this->adapter);
        $codes = array_column($errors, 'code');
        $this->assertContains('unknown_operation', $codes);
    }

    public function test_operation_without_op_key_is_rejected(): void
    {
        $errors = $this->svc->validate([['field' => 'x']], $this->adapter);
        $codes = array_column($errors, 'code');
        $this->assertContains('operation_missing_name', $codes);
    }

    public function test_non_array_op_is_rejected(): void
    {
        $errors = $this->svc->validate(['not an object'], $this->adapter);
        $codes = array_column($errors, 'code');
        $this->assertContains('operation_not_array', $codes);
    }

    public function test_safety_violation_short_circuits_adapter_check(): void
    {
        // A denied key AND an unknown op: safety fires first.
        $errors = $this->svc->validate([
            ['op' => 'not_a_real_op', 'controller' => 'x'],
        ], $this->adapter);
        $codes = array_column($errors, 'code');
        $this->assertContains('denied_key', $codes);
        $this->assertNotContains('unknown_operation', $codes);
    }
}
