<?php

namespace App\Core\AI\Prompts;

/**
 * Base class for all AI prompt DTOs.
 *
 * Each module defines its own prompt class by extending BasePrompt and
 * implementing `build()`. The prompt object is then passed directly to
 * `AIProviderContract::execute()`.
 *
 * Usage:
 *   $prompt = new MethodStatementPrompt($scope);
 *   $data   = $manager->provider()->execute($prompt);
 */
abstract class BasePrompt
{
    /**
     * Whether this prompt produces a PDF-based request.
     * Override to true and provide $pdfBase64 in subclasses that send documents.
     */
    protected bool $usesPdf = false;

    /** Base64-encoded PDF bytes — only populated when $usesPdf is true. */
    protected ?string $pdfBase64 = null;

    /** Whether this prompt sends an image (vision call). */
    protected bool $usesImage = false;

    /** Base64-encoded image bytes — only populated when $usesImage is true. */
    protected ?string $imageBase64 = null;

    /** Image MIME type, e.g. 'image/jpeg'. */
    protected ?string $imageMediaType = null;

    // ── Subclass responsibilities ─────────────────────────────────────────────

    /**
     * Build and return the full user-facing prompt string.
     *
     * @param  array  $context  Module-specific data for interpolation.
     */
    abstract public function build(array $context = []): string;

    // ── Overridable defaults ──────────────────────────────────────────────────

    /**
     * System message sent as the AI's "role" context.
     * Override in subclasses to specialise the AI persona.
     */
    public function systemMessage(): string
    {
        return 'You are a senior UK AV (Audio-Visual) installation expert. '
             . 'Respond ONLY with valid JSON — no markdown fences, no commentary.';
    }

    /**
     * Maximum tokens for the completion.
     * Override in subclasses that need longer responses (e.g. O&M content).
     */
    public function maxTokens(): int
    {
        return 4096;
    }

    /**
     * Temperature for sampling (0.0–1.0).
     * Lower = more deterministic (preferable for structured JSON output).
     */
    public function temperature(): float
    {
        return 0.2;
    }

    // ── PDF support ───────────────────────────────────────────────────────────

    public function usesPdf(): bool
    {
        return $this->usesPdf;
    }

    public function getPdfBase64(): ?string
    {
        return $this->pdfBase64;
    }

    /**
     * Fluent setter — attach a base64-encoded PDF to this prompt instance.
     * Used by prompts that need a PDF supplied at runtime (e.g. OmManualPrompt Pass 1).
     */
    public function setPdf(string $pdfBase64): static
    {
        $this->usesPdf   = true;
        $this->pdfBase64 = $pdfBase64;

        return $this;
    }

    // ── Image support ─────────────────────────────────────────────────────────

    public function usesImage(): bool
    {
        return $this->usesImage;
    }

    public function getImageBase64(): ?string
    {
        return $this->imageBase64;
    }

    public function getImageMediaType(): ?string
    {
        return $this->imageMediaType;
    }

    /**
     * Fluent setter — attach a base64-encoded image and its MIME type.
     * Used by vision prompts (e.g. LabelExtractionPrompt).
     */
    public function setImage(string $imageBase64, string $mediaType = 'image/jpeg'): static
    {
        $this->usesImage      = true;
        $this->imageBase64    = $imageBase64;
        $this->imageMediaType = $mediaType;

        return $this;
    }

    // ── Context store (survives AIManager's clone) ────────────────────────────

    /** Keyed data stored by withContext() so build() has access when called with no args. */
    protected array $storedContext = [];

    /**
     * Fluent setter — attach context data that will be available inside build()
     * even when it is called with no arguments (e.g. after AIManager clones the prompt).
     *
     * Subsequent calls MERGE rather than replace, so partial updates are safe.
     */
    public function withContext(array $context): static
    {
        $this->storedContext = array_merge($this->storedContext, $context);

        return $this;
    }

    // ── Retry support ─────────────────────────────────────────────────────────

    /**
     * Suffix appended to the prompt on a second attempt.
     * Instructs the model to return only JSON after a failed first attempt.
     */
    public function retrySuffix(): string
    {
        return "\n\nYour previous response was not valid JSON or was missing required keys. "
             . "Return ONLY the JSON object — no markdown, no explanation, no fences.";
    }
}
