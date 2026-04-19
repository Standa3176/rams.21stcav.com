<?php

namespace App\Services\DocumentEdits;

use App\Services\DocumentEdits\Prompts\DocumentEditParsingPromptFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates the conversational parse → strict-ops JSON flow.
 *
 * Never applies operations, never touches Eloquent. All it does is:
 *   1. Build the prompt via DocumentEditParsingPromptFactory.
 *   2. Call the LLM via ParserAiCaller.
 *   3. Run the response through three validators in order:
 *        a) DocumentOperationSchemaValidator  (shape)
 *        b) DocumentEditSafetyValidator       (denied keys / tokens)
 *        c) DocumentChangeSetValidator        (adapter allow-list)
 *   4. If any fail, retry up to 3 total attempts feeding the errors back to
 *      the model. Stop early on success.
 *   5. Return {status, operations, summary, attempts, errors, raw_last}.
 *
 * Logs:
 *   parse_started          — per call
 *   parse_attempt_failed   — per failed attempt with error codes
 *   parse_validated        — on success with attempt count + op count
 *   parse_rejected         — when all attempts fail
 */
class DocumentEditParserService
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly DocumentOperationSchemaValidator $schemaValidator,
        private readonly DocumentEditSafetyValidator      $safetyValidator,
        private readonly DocumentChangeSetValidator       $changeSetValidator,
        private readonly DocumentEditParsingPromptFactory $promptFactory,
        private readonly ParserAiCaller                   $aiCaller,
    ) {}

    /**
     * Parse a user message into validated operations for the given adapter.
     *
     * Return shape (always present, never throws):
     *   [
     *     'status'        => 'success' | 'failed',
     *     'operations'    => array<int, array<string, mixed>>,   // flattened, adapter-ready
     *     'summary'       => string,
     *     'attempts'      => int,
     *     'model_name'    => string|null,
     *     'errors'        => list<array{code, message}>,         // populated when failed
     *     'raw_last'      => string|null,                        // last raw model text
     *   ]
     *
     * @param array<string, mixed>|null $documentPayload  Safe subset fed to prompt
     * @return array<string, mixed>
     */
    public function parse(
        DocumentEditAdapterInterface $adapter,
        string  $userMessage,
        ?array  $documentPayload,
        ?string $modelName = null,
        array   $logContext = [],
    ): array {
        $this->audit('parse_started', $logContext + [
            'document_type' => $adapter->documentType(),
            'model'         => $modelName,
            'message_len'   => strlen($userMessage),
        ]);

        $errors      = [];
        $rawLast     = null;
        $decodedLast = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $prompt = $this->promptFactory->make(
                adapter:        $adapter,
                userMessage:    $userMessage,
                documentPayload: $documentPayload,
                priorErrors:    $errors,
                priorRawOutput: $rawLast,
            );

            try {
                $decoded     = $this->aiCaller->call($prompt, $modelName);
                $decodedLast = $decoded;
                $rawLast     = is_array($decoded)
                    ? (json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')
                    : (string) $decoded;
            } catch (Throwable $e) {
                $errors = [[
                    'code'    => 'ai_call_failed',
                    'message' => 'AI call failed: ' . $this->safeExceptionMessage($e),
                ]];
                $this->audit('parse_attempt_failed', $logContext + ['attempt' => $attempt, 'codes' => ['ai_call_failed']]);
                continue; // retry
            }

            // 1. schema
            $schemaErrors = $this->schemaValidator->validate($decoded);
            if (! empty($schemaErrors)) {
                $errors = $schemaErrors;
                $this->audit('parse_attempt_failed', $logContext + [
                    'attempt' => $attempt,
                    'codes'   => array_unique(array_column($schemaErrors, 'code')),
                ]);
                continue;
            }

            // Flatten operations into the shape adapters + safety validator expect.
            $flatOps = $this->schemaValidator->flattenToAdapterOps($decoded);

            // 2. safety (denied keys / tokens)
            $safetyErrors = $this->safetyValidator->validate($flatOps);
            if (! empty($safetyErrors)) {
                $errors = $safetyErrors;
                $this->audit('parse_attempt_failed', $logContext + [
                    'attempt' => $attempt,
                    'codes'   => array_unique(array_column($safetyErrors, 'code')),
                ]);
                continue;
            }

            // 3. adapter allow-list
            $csErrors = $this->changeSetValidator->validate($flatOps, $adapter);
            if (! empty($csErrors)) {
                $errors = $csErrors;
                $this->audit('parse_attempt_failed', $logContext + [
                    'attempt' => $attempt,
                    'codes'   => array_unique(array_column($csErrors, 'code')),
                ]);
                continue;
            }

            // All three validators passed.
            $this->audit('parse_validated', $logContext + [
                'attempts' => $attempt,
                'op_count' => count($flatOps),
            ]);
            return [
                'status'     => self::STATUS_SUCCESS,
                'operations' => $flatOps,
                'summary'    => (string) ($decoded['summary'] ?? ''),
                'attempts'   => $attempt,
                'model_name' => $modelName,
                'errors'     => [],
                'raw_last'   => $rawLast,
            ];
        }

        // All attempts exhausted.
        $this->audit('parse_rejected', $logContext + [
            'attempts' => self::MAX_ATTEMPTS,
            'codes'    => array_unique(array_column($errors, 'code')),
        ]);
        return [
            'status'     => self::STATUS_FAILED,
            'operations' => [],
            'summary'    => '',
            'attempts'   => self::MAX_ATTEMPTS,
            'model_name' => $modelName,
            'errors'     => $errors,
            'raw_last'   => $rawLast,
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function audit(string $event, array $context): void
    {
        Log::info('DocumentEditParserService: ' . $event, $context);
    }

    private function safeExceptionMessage(Throwable $e): string
    {
        // Avoid leaking stack frames / secrets. Keep the class + short message.
        return class_basename($e) . ': ' . preg_replace('/\s+/u', ' ', substr($e->getMessage(), 0, 200));
    }
}
