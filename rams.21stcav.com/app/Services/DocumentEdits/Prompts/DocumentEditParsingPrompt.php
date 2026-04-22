<?php

namespace App\Services\DocumentEdits\Prompts;

use App\Core\AI\Prompts\BasePrompt;

/**
 * Prompt DTO for parsing a user's conversational request into a strictly-
 * schemaed operations array. Built by DocumentEditParsingPromptFactory so
 * the parser service stays free of prompt-string concerns.
 *
 * The prompt deliberately:
 *   - lists the full adapter allow-list and refuses anything outside it
 *   - includes a small redacted snapshot of the document payload (room names
 *     + counts only, no raw content / IDs of other docs)
 *   - instructs the model to return JSON matching the schema verbatim
 *   - on retry attempts, surfaces the machine-readable error codes from the
 *     previous attempt so the model can self-correct
 */
class DocumentEditParsingPrompt extends BasePrompt
{
    /**
     * @param array<int, string>             $allowedOperations
     * @param array<string, array{args:array<string,string>, notes?:string}> $operationSchemas  Per-op arg docs
     * @param array<string, mixed>           $payloadSnapshot  Safe subset only
     * @param list<array{code: string, message: string}> $priorErrors
     */
    public function __construct(
        private readonly string $documentType,
        private readonly string $userMessage,
        private readonly array  $allowedOperations,
        private readonly array  $payloadSnapshot,
        private readonly array  $priorErrors       = [],
        private readonly ?string $priorRawOutput   = null,
        private readonly array  $operationSchemas  = [],
    ) {}

    public function build(array $context = []): string
    {
        $ops     = implode(', ', array_map(fn ($op) => "\"{$op}\"", $this->allowedOperations));
        $snapshot = json_encode($this->payloadSnapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        $sections = [];
        $sections[] = "# Task\nConvert the user's request into a strict JSON operations plan for a '{$this->documentType}' document.";
        $sections[] = "# Allowed operations\nAvailable op names: {$ops}\nEvery operation you emit MUST use one of these exact strings.";

        if (! empty($this->operationSchemas)) {
            $lines = ['# Operation argument schemas', 'Each op accepts ONLY the args listed below — do not invent keys.', ''];
            foreach ($this->operationSchemas as $opName => $spec) {
                $lines[] = "## {$opName}";
                foreach ((array) ($spec['args'] ?? []) as $arg => $help) {
                    $lines[] = "- `{$arg}`: {$help}";
                }
                if (! empty($spec['notes'])) {
                    $lines[] = "  note: {$spec['notes']}";
                }
                $lines[] = '';
            }
            $sections[] = trim(implode("\n", $lines));
        }

        $sections[] = "# Document snapshot (safe subset)\n```json\n{$snapshot}\n```";
        $sections[] = "# Schema (STRICT — must match exactly)\n```\n{\n  \"operations\": [\n    {\"op\": \"<name>\", \"target\": {\"room_name\": \"<string|null>\", \"index\": <int|null>}, \"args\": { ... }, \"rationale\": \"<short explanation>\"}\n  ],\n  \"summary\": \"<short human summary ≤ 1000 chars>\"\n}\n```\nRules:\n- Return JSON only. No markdown fences, no prose.\n- Top-level keys allowed: operations, summary. Nothing else.\n- Each operation has exactly four keys: op, target, args, rationale. No extras.\n- Max 25 operations per parse.\n- rationale and summary each ≤ 1000 chars.\n- Never include file paths, route names, class names, PHP code, or shell commands anywhere.";
        $sections[] = "# User request\n{$this->userMessage}";

        if (! empty($this->priorErrors)) {
            $codes = implode(', ', array_unique(array_column($this->priorErrors, 'code')));
            $msgs  = implode("\n- ", array_slice(array_column($this->priorErrors, 'message'), 0, 10));
            $sections[] = "# Previous attempt failed validation\nError codes: {$codes}\nMessages:\n- {$msgs}\n\nRe-emit the corrected JSON only. Fix EVERY error above.";
            if ($this->priorRawOutput !== null && $this->priorRawOutput !== '') {
                $trimmed = substr($this->priorRawOutput, 0, 800);
                $sections[] = "# Your previous output (truncated to 800 chars)\n```\n{$trimmed}\n```";
            }
        }

        return implode("\n\n", $sections);
    }

    public function systemMessage(): string
    {
        return 'You are an operations-planning assistant for an AV installation document system. '
             . 'Respond ONLY with valid JSON matching the specified schema — no markdown fences, no commentary. '
             . 'Never include file paths, route names, class names, migrations, shell commands, or any runtime code tokens.';
    }

    public function maxTokens(): int
    {
        return 2048;
    }

    public function temperature(): float
    {
        return 0.1;
    }
}
