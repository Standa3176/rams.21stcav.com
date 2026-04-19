<?php

namespace Tests\Unit\Services\DocumentEdits;

use App\Services\DocumentEdits\Adapters\WorksheetEditAdapter;
use App\Services\DocumentEdits\DocumentChangeSetValidator;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;
use App\Services\DocumentEdits\DocumentEditParserService;
use App\Services\DocumentEdits\DocumentEditSafetyValidator;
use App\Services\DocumentEdits\DocumentOperationSchemaValidator;
use App\Services\DocumentEdits\ParserAiCaller;
use App\Services\DocumentEdits\Prompts\DocumentEditParsingPromptFactory;
// Laravel's base TestCase bootstraps the container so facades used inside
// the parser service (Log) work. The parser is still fundamentally a unit
// under test here — no DB, no HTTP, no Eloquent.
use Tests\TestCase;

/**
 * Unit-level tests for the parser service retry loop. The AI caller is a
 * stub returning canned responses in sequence; the real worksheet adapter
 * supplies allowed_ops so the downstream allow-list check is exercised.
 */
class DocumentEditParserServiceTest extends TestCase
{
    /**
     * Build a parser service with a scripted ParserAiCaller that returns
     * the given responses in order. Each response is either an array
     * (decoded JSON) or a Throwable (simulating AI call failure).
     */
    private function serviceWithResponses(array $responses): array
    {
        $caller = new class($responses) extends ParserAiCaller {
            public int $callCount = 0;
            public array $responses;
            public function __construct(array $responses) { $this->responses = $responses; }
            public function call(\App\Core\AI\Prompts\BasePrompt $prompt, ?string $modelName = null): array
            {
                $r = $this->responses[$this->callCount] ?? end($this->responses);
                $this->callCount++;
                if ($r instanceof \Throwable) throw $r;
                return $r;
            }
        };

        $safety = new DocumentEditSafetyValidator();
        $svc = new DocumentEditParserService(
            new DocumentOperationSchemaValidator(),
            $safety,
            new DocumentChangeSetValidator($safety),
            new DocumentEditParsingPromptFactory(),
            $caller,
        );
        return [$svc, $caller];
    }

    private function worksheetAdapter(): DocumentEditAdapterInterface
    {
        // Direct instance so test doesn't need the Laravel container.
        return new WorksheetEditAdapter();
    }

    private function validResponse(): array
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

    public function test_first_attempt_success_does_not_retry(): void
    {
        [$svc, $caller] = $this->serviceWithResponses([$this->validResponse()]);
        $res = $svc->parse($this->worksheetAdapter(), 'Add power blocker', null);

        $this->assertSame(DocumentEditParserService::STATUS_SUCCESS, $res['status']);
        $this->assertSame(1, $res['attempts']);
        $this->assertSame(1, $caller->callCount);
        $this->assertSame('add_blocker', $res['operations'][0]['op']);
    }

    public function test_retry_succeeds_on_second_attempt_when_first_is_invalid(): void
    {
        $invalid = ['operations' => [], 'summary' => 'empty']; // schema_operations_empty
        [$svc, $caller] = $this->serviceWithResponses([$invalid, $this->validResponse()]);

        $res = $svc->parse($this->worksheetAdapter(), 'Add power blocker', null);

        $this->assertSame(DocumentEditParserService::STATUS_SUCCESS, $res['status']);
        $this->assertSame(2, $res['attempts']);
        $this->assertSame(2, $caller->callCount);
    }

    public function test_returns_failed_after_three_invalid_attempts(): void
    {
        $invalid = ['operations' => [['op' => 'not_a_real_op', 'target' => null, 'args' => [], 'rationale' => 'x']], 'summary' => 's'];
        [$svc, $caller] = $this->serviceWithResponses([$invalid, $invalid, $invalid]);

        $res = $svc->parse($this->worksheetAdapter(), 'Bad request', null);

        $this->assertSame(DocumentEditParserService::STATUS_FAILED, $res['status']);
        $this->assertSame(3, $res['attempts']);
        $this->assertSame(3, $caller->callCount);
        $this->assertContains('unknown_operation', array_column($res['errors'], 'code'));
    }

    public function test_ai_exception_is_converted_to_parse_failed_not_500(): void
    {
        [$svc, $caller] = $this->serviceWithResponses([
            new \RuntimeException('synthetic timeout'),
            new \RuntimeException('synthetic timeout'),
            new \RuntimeException('synthetic timeout'),
        ]);

        $res = $svc->parse($this->worksheetAdapter(), 'Something', null);

        $this->assertSame(DocumentEditParserService::STATUS_FAILED, $res['status']);
        $this->assertContains('ai_call_failed', array_column($res['errors'], 'code'));
    }

    public function test_ai_exception_then_success_recovers(): void
    {
        [$svc] = $this->serviceWithResponses([new \RuntimeException('transient'), $this->validResponse()]);

        $res = $svc->parse($this->worksheetAdapter(), 'Add blocker', null);

        $this->assertSame(DocumentEditParserService::STATUS_SUCCESS, $res['status']);
        $this->assertSame(2, $res['attempts']);
    }

    public function test_safety_violation_in_model_output_is_rejected(): void
    {
        // Model tries to sneak a denied key inside args — schema passes (args
        // allows arbitrary keys), but safety validator catches it.
        $evil = [
            'operations' => [[
                'op'        => 'add_blocker',
                'target'    => ['room_name' => null, 'index' => null],
                'args'      => ['type' => 'x', 'message' => 'y', 'action' => 'z', 'controller' => 'Admin'],
                'rationale' => 'x',
            ]],
            'summary' => 's',
        ];
        [$svc] = $this->serviceWithResponses([$evil, $evil, $evil]);

        $res = $svc->parse($this->worksheetAdapter(), 'Mess with routes', null);

        $this->assertSame(DocumentEditParserService::STATUS_FAILED, $res['status']);
        $this->assertContains('denied_key', array_column($res['errors'], 'code'));
    }
}
