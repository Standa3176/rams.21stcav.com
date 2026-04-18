<?php

namespace Tests\Unit\Services\DocumentEdits;

use App\Services\DocumentEdits\DocumentEditSafetyValidator;
use PHPUnit\Framework\TestCase;

class DocumentEditSafetyValidatorTest extends TestCase
{
    private DocumentEditSafetyValidator $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new DocumentEditSafetyValidator();
    }

    public function test_empty_operations_are_rejected(): void
    {
        $errors = $this->svc->validate(null);
        $this->assertNotEmpty($errors);
        $this->assertSame('empty_operations', $errors[0]['code']);
    }

    public function test_non_array_operations_are_rejected(): void
    {
        $errors = $this->svc->validate('hello');
        $this->assertSame('operations_not_array', $errors[0]['code']);
    }

    public function test_benign_operations_pass(): void
    {
        $ops = [
            ['op' => 'update_room_field', 'room_id' => 5, 'field' => 'has_power', 'value' => true],
        ];
        $this->assertSame([], $this->svc->validate($ops));
    }

    public function test_denied_key_path_is_rejected(): void
    {
        $ops = [['op' => 'update_room_field', 'path' => '/etc/passwd', 'value' => 'x']];
        $errors = $this->svc->validate($ops);
        $this->assertNotEmpty($errors);
        $codes = array_column($errors, 'code');
        $this->assertContains('denied_key', $codes);
    }

    public function test_all_denied_keys_are_rejected(): void
    {
        $denied = ['path', 'file', 'filepath', 'class', 'method', 'route', 'controller',
                   'migration', 'blade', 'php', 'sql', 'shell', 'command'];
        foreach ($denied as $key) {
            $errors = $this->svc->validate([['op' => 'noop', $key => 'x']]);
            $codes = array_column($errors, 'code');
            $this->assertContains('denied_key', $codes, "Expected key '{$key}' to be rejected");
        }
    }

    public function test_denied_keys_match_case_insensitively(): void
    {
        $errors = $this->svc->validate([['op' => 'noop', 'ROUTE' => '/x']]);
        $this->assertContains('denied_key', array_column($errors, 'code'));
    }

    public function test_denied_keys_are_rejected_when_nested(): void
    {
        $ops = [['op' => 'x', 'data' => ['nested' => ['controller' => 'Admin']]]];
        $errors = $this->svc->validate($ops);
        $this->assertContains('denied_key', array_column($errors, 'code'));
    }

    public function test_php_tag_in_value_is_rejected(): void
    {
        $ops = [['op' => 'x', 'value' => '<?php system("rm -rf /"); ?>']];
        $errors = $this->svc->validate($ops);
        $codes = array_column($errors, 'code');
        $this->assertContains('denied_value_substring', $codes);
    }

    public function test_oversized_payload_is_rejected(): void
    {
        $big = str_repeat('x', DocumentEditSafetyValidator::MAX_OPERATIONS_BYTES + 10);
        $ops = [['op' => 'x', 'value' => $big]];
        $errors = $this->svc->validate($ops);
        $codes = array_column($errors, 'code');
        $this->assertContains('operations_too_large', $codes);
    }
}
