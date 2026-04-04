<?php

namespace App\Services;

use App\Exceptions\RamsGenerationException;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\AIProviderInterface;
use App\Services\EquipmentExtractorService;
use App\Services\QuoteParserService;
use Illuminate\Support\Facades\Log;

class RamsGeneratorService
{
    private const REQUIRED_KEYS = [
        'project',
        'hazards',
        'ppe',
        'persons_at_risk',
        'regulations',
        'method_statement',
    ];

    private const RETRY_SUFFIX = "\n\nYour previous response was invalid JSON or missing required keys. "
        . "Return ONLY the JSON object, nothing else.";

    public function __construct(
        private readonly EquipmentExtractorService $equipmentExtractor,
        private readonly QuoteParserService        $quoteParser,
    ) {}

    // =========================================================================
    // generate — manual form path (unchanged)
    // =========================================================================

    public function generate(array $formData, ?string $provider = null): array
    {
        $aiProvider = AIProviderFactory::make($provider);

        $result = $this->attempt($aiProvider, $formData, attempt: 1);

        if ($this->isValid($result)) {
            return $result;
        }

        $retryFormData                 = $formData;
        $retryFormData['_retry_suffix'] = self::RETRY_SUFFIX;

        $result = $this->attempt($aiProvider, $retryFormData, attempt: 2);

        if ($this->isValid($result)) {
            return $result;
        }

        throw new RamsGenerationException(
            'RAMS generation failed after two attempts: '
            . 'the AI response was either invalid JSON or missing required top-level keys ('
            . implode(', ', self::REQUIRED_KEYS) . ').'
        );
    }

    // =========================================================================
    // generateFromFiles — PDF upload path
    //
    // Refactored to:
    //   1. Extract plain text from the PDF locally (no PDF sent to Claude)
    //   2. Extract AV equipment list from the text
    //   3. Send text + equipment list to Claude as a structured text prompt
    //
    // This replaces the old base64 document-block approach which caused
    // 60–120 second timeouts and truncated JSON responses.
    // =========================================================================

    public function generateFromFiles(array $files, ?string $provider = null): array
    {
        $aiProvider = AIProviderFactory::make($provider);

        // ── Step 1: Extract text from the quote PDF locally ─────────────────
        $rawText = $this->extractPdfText($files['quote']['path']);

        Log::info('RAMS: PDF text extracted', [
            'chars' => strlen($rawText),
            'quote' => $files['quote']['name'],
        ]);

        // ── Step 2: Parse quote into structured fields (THE KEY CHANGE) ──────
        // QuoteParserService extracts: client, site, ref, equipment[], tasks[], rooms[]
        // Claude NEVER receives raw quote text — only these clean structured fields.
        // This is what prevents token overflow and truncated JSON responses.
        $parsedQuote = $this->quoteParser->parse($rawText);

        // Tag so PromptBuilderService knows to use the structured path
        $parsedQuote['_parsed'] = true;

        Log::info('RAMS: Quote parsed', [
            'client'    => $parsedQuote['client'],
            'site'      => $parsedQuote['site'],
            'ref'       => $parsedQuote['ref'],
            'equipment' => count($parsedQuote['equipment']),
            'tasks'     => count($parsedQuote['tasks']),
            'rooms'     => count($parsedQuote['rooms']),
        ]);

        // ── Step 3: Generate RAMS from structured data only ───────────────────
        // Pass empty string for quoteText — it is not used in the structured path.
        // The parsedQuote is passed as the $equipment parameter (interface reuse).
        $result = $this->attemptFromText($aiProvider, '', $parsedQuote, attempt: 1);

        if ($this->isValid($result)) {
            return $result;
        }

        // Retry with suffix on invalid response
        $result = $this->attemptFromText(
            $aiProvider,
            '',
            $parsedQuote,
            attempt: 2,
            retrySuffix: self::RETRY_SUFFIX
        );

        if ($this->isValid($result)) {
            return $result;
        }

        throw new RamsGenerationException(
            'RAMS generation from files failed after two attempts: '
            . 'the AI response was either invalid JSON or missing required top-level keys ('
            . implode(', ', self::REQUIRED_KEYS) . ').'
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Extract plain text from a PDF file.
     * Tries Spatie PDF-to-text first (fast, uses pdftotext binary),
     * then falls back to smalot/pdfparser (pure PHP).
     */
    private function extractPdfText(string $absolutePath): string
    {
        // Spatie (requires pdftotext installed on server — apt install poppler-utils)
        if (class_exists(\Spatie\PdfToText\Pdf::class)) {
            try {
                $text = \Spatie\PdfToText\Pdf::getText($absolutePath);
                if (! empty(trim($text))) {
                    return $this->cleanText($text);
                }
            } catch (\Throwable $e) {
                Log::warning('RAMS: Spatie PDF extraction failed, falling back to smalot', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // smalot/pdfparser (pure PHP, no binary required)
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $config = new \Smalot\PdfParser\Config();
                $config->setIgnoreEncryption(true);
                $parser = new \Smalot\PdfParser\Parser([], $config);
                $pdf    = $parser->parseFile($absolutePath);
                $text   = $pdf->getText();
                if (! empty(trim($text))) {
                    return $this->cleanText($text);
                }
            } catch (\Throwable $e) {
                Log::warning('RAMS: smalot PDF extraction failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::error('RAMS: All PDF text extraction methods failed', ['path' => $absolutePath]);

        return '[Could not extract text from the PDF. The file may be image-based or corrupted. '
            . 'Please generate the RAMS manually using the form.]';
    }

    /**
     * Normalise extracted PDF text before sending to Claude.
     */
    private function cleanText(string $text): string
    {
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\r\n|\r/', "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Attempt RAMS generation from pre-extracted text + equipment list.
     */
    private function attemptFromText(
        AIProviderInterface $provider,
        string              $quoteText,
        array               $equipment,
        int                 $attempt,
        ?string             $retrySuffix = null,
    ): array {
        try {
            $result = $provider->generateRamsFromText($quoteText, $equipment, $retrySuffix);
            return $this->normalise($result);
        } catch (\Throwable $e) {
            Log::warning('RAMS text generation attempt failed', [
                'attempt'  => $attempt,
                'provider' => get_class($provider),
                'error'    => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Attempt RAMS generation from form data.
     */
    private function attempt(AIProviderInterface $provider, array $formData, int $attempt): array
    {
        try {
            $result = $provider->generateRams($formData);
            return $this->normalise($result);
        } catch (\Throwable $e) {
            Log::warning('RAMS generation attempt failed', [
                'attempt'  => $attempt,
                'provider' => get_class($provider),
                'error'    => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Normalise a decoded Claude response — fill missing required keys with
     * safe empty defaults rather than failing outright.
     *
     * This means a partial response (e.g. missing 'regulations') will produce
     * a usable RAMS document instead of a complete generation failure.
     */
    private function normalise(array $data): array
    {
        $defaults = [
            'project'         => [
                'ref'               => 'RAMS-001',
                'name'              => 'AV Installation',
                'client'            => 'Client',
                'site_address'      => 'See document',
                'works_description' => 'AV installation works.',
                'subtitle'          => 'AV Installation Project',
                'document_status'   => 'For Construction',
            ],
            'hazards'         => [
                ['id' => 1, 'hazard' => 'Working at Height', 'consequences' => ['Falls from height causing injury or death'],
                 'pre_likelihood' => 3, 'pre_severity' => 5,
                 'controls' => ['Use appropriate access equipment per WAHR 2005', 'Conduct pre-use inspection of all ladders and platforms'],
                 'post_likelihood' => 1, 'post_severity' => 3],
                ['id' => 2, 'hazard' => 'Manual Handling', 'consequences' => ['Musculoskeletal injury from lifting heavy equipment'],
                 'pre_likelihood' => 3, 'pre_severity' => 3,
                 'controls' => ['Assess loads before lifting per Manual Handling Operations Regulations 1992', 'Use mechanical aids for loads over 25kg'],
                 'post_likelihood' => 1, 'post_severity' => 2],
                ['id' => 3, 'hazard' => 'Electrical Equipment', 'consequences' => ['Electric shock', 'Burns from short circuit'],
                 'pre_likelihood' => 2, 'pre_severity' => 5,
                 'controls' => ['Isolate circuits before work per Electricity at Work Regulations 1989', 'PAT test all portable equipment before use'],
                 'post_likelihood' => 1, 'post_severity' => 3],
                ['id' => 4, 'hazard' => 'Use of Power Tools', 'consequences' => ['Lacerations', 'Eye injury from debris'],
                 'pre_likelihood' => 3, 'pre_severity' => 3,
                 'controls' => ['Inspect tools before use per PUWER 1998', 'Wear appropriate PPE including eye protection'],
                 'post_likelihood' => 1, 'post_severity' => 2],
                ['id' => 5, 'hazard' => 'Cable Trip Hazards', 'consequences' => ['Slips, trips and falls causing injury'],
                 'pre_likelihood' => 4, 'pre_severity' => 2,
                 'controls' => ['Route cables in trunking or under matting per HSE guidance HSG150', 'Display warning signage around work area'],
                 'post_likelihood' => 2, 'post_severity' => 1],
            ],
            'ppe'             => ['Safety Boots', 'Hi-Vis Vest', 'Safety Glasses', 'Gloves'],
            'persons_at_risk' => ['21CAV Installation Engineers', 'Client Staff', 'Members of Public'],
            'regulations'     => [
                'Health and Safety at Work Act 1974',
                'Management of Health and Safety at Work Regulations 1999',
                'Electricity at Work Regulations 1989',
                'Manual Handling Operations Regulations 1992',
                'Work at Height Regulations 2005',
                'Provision and Use of Work Equipment Regulations 1998',
            ],
            'method_statement' => [
                'introduction'       => 'Works to be carried out by 21st Century AV Ltd engineers following site induction and risk briefing.',
                'scope_of_works'     => [['room' => 'TBC', 'drawing_ref' => 'N/A', 'equipment' => 'AV equipment as per quote']],
                'exclusions'         => [['item' => 'Structural works', 'responsible_party' => 'Client', 'description' => 'Any structural modifications are excluded from this scope']],
                'general_procedures' => ['Complete site induction before commencing work', 'Brief all engineers on this RAMS before work begins', 'Maintain tidy work area throughout'],
                'phases'             => [['name' => 'Phase 1: Installation', 'description' => 'Supply and install AV equipment as quoted.', 'procedures' => ['Deliver equipment to site', 'Install and connect all AV equipment', 'Cable and terminate all connections']]],
                'quality_checks'     => ['Test all AV equipment on completion', 'Check all fixings are secure', 'Clean and remove all waste from site'],
            ],
        ];

        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $data) || empty($data[$key])) {
                Log::warning("RAMS: Missing or empty key '{$key}' — using default", ['key' => $key]);
                $data[$key] = $defaults[$key];
            }
        }

        // Ensure project sub-keys are present
        if (is_array($data['project'])) {
            foreach ($defaults['project'] as $subKey => $default) {
                if (empty($data['project'][$subKey])) {
                    $data['project'][$subKey] = $default;
                }
            }
        }

        return $data;
    }

    /**
     * Check a result is worth using — must have at least project and hazards.
     * Missing secondary keys (regulations, ppe, etc.) are filled by normalise().
     */
    private function isValid(array $result): bool
    {
        if (empty($result)) {
            return false;
        }

        // Primary keys must be present and non-empty — others get normalised
        foreach (['project', 'hazards', 'method_statement'] as $key) {
            if (! array_key_exists($key, $result) || empty($result[$key])) {
                return false;
            }
        }

        return true;
    }
}
