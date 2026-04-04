<?php

namespace App\Core\AI\Contracts;

use App\Core\AI\Prompts\BasePrompt;

/**
 * Contract for all AI provider implementations.
 *
 * All modules in the enterprise platform interact with AI exclusively through
 * this contract — providers (Claude, OpenAI) are interchangeable.
 *
 * Three interaction modes are supported:
 *   1. Text-only   — prompt string, returns decoded JSON array
 *   2. PDF + text  — base64 PDF + prompt, returns decoded JSON array
 *   3. Prompt DTO  — a typed BasePrompt object carrying its own system message
 *                    and max_tokens, returns decoded JSON array
 */
interface AIProviderContract
{
    /**
     * Send a plain text prompt and return decoded JSON.
     *
     * @param  string  $prompt      The full user-facing prompt.
     * @param  array   $options     Optional overrides: max_tokens, system, temperature.
     * @return array                Decoded JSON response.
     *
     * @throws \RuntimeException    On HTTP failure or invalid JSON.
     */
    public function completeJson(string $prompt, array $options = []): array;

    /**
     * Send a base64-encoded PDF document together with a text prompt.
     * Claude uses native document vision; OpenAI uses data URI.
     *
     * @param  string       $pdfBase64   Base64-encoded PDF bytes.
     * @param  string       $prompt      Instruction prompt to accompany the document.
     * @param  array        $options     Optional overrides: max_tokens, system.
     * @return array                     Decoded JSON response.
     *
     * @throws \RuntimeException         On HTTP failure or invalid JSON.
     */
    public function completeWithPdf(string $pdfBase64, string $prompt, array $options = []): array;

    /**
     * Execute a typed Prompt DTO.
     *
     * The prompt object carries the full instruction set, system message, and
     * token budget — the provider just handles the transport layer.
     *
     * @param  BasePrompt  $prompt   A resolved prompt instance (already built).
     * @return array                 Decoded JSON response.
     *
     * @throws \RuntimeException     On HTTP failure or invalid JSON.
     */
    public function execute(BasePrompt $prompt): array;

    /**
     * Return the canonical provider key ('claude' | 'openai').
     */
    public function getProviderKey(): string;
}
