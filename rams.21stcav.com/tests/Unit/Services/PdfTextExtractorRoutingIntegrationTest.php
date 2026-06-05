<?php

namespace Tests\Unit\Services;

use App\Services\PdfOcrExtractorService;
use App\Services\PdfTextExtractorService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

/**
 * Integration-level tests for {@see PdfTextExtractorService::extract()}
 * exercising the short-tag routing branches end-to-end WITHOUT requiring
 * a real pdftotext binary or PDF file.
 *
 * Strategy: anonymous subclass of PdfTextExtractorService overrides the
 * shell_exec-touching methods (extractWithPdfToText, extractWithPdfToTextLayout,
 * parseText) so we can drive extract() through every branch deterministically.
 *
 * Extends Tests\TestCase (Laravel bootstrap) to get Log facade support —
 * the pure detector tests live in the sibling
 * {@see PdfTextExtractorShortTagRoutingTest} which uses PHPUnit\TestCase
 * directly for speed.
 *
 * Quick task 260604-p9u.
 */
class PdfTextExtractorRoutingIntegrationTest extends TestCase
{
    private string $rawBaseline;

    protected function setUp(): void
    {
        parent::setUp();

        // The pre-Task-2 -raw form of the Cicor fixture — preserved as a
        // permanent test asset so the integration tests have a known
        // short-tag-shaped input independent of the main fixture.
        $this->rawBaseline = (string) file_get_contents(
            __DIR__ . '/../../Fixtures/quotewerks/priced-cicor-21CQ30167-raw-baseline.txt'
        );

        $this->assertGreaterThan(
            500,
            strlen($this->rawBaseline),
            'priced-cicor-21CQ30167-raw-baseline.txt must exist and be the full -raw form.'
        );
    }

    /**
     * Build an anonymous subclass with the named override behaviours.
     *
     * @param  array{rawText?: string|null, layoutText?: string|\Throwable|null, smalotText?: string|null}  $overrides
     */
    private function service(array $overrides): PdfTextExtractorService
    {
        $rawText    = $overrides['rawText']    ?? '';
        $layoutText = $overrides['layoutText'] ?? '';
        $smalotText = $overrides['smalotText'] ?? '';

        $ocr = $this->createMock(PdfOcrExtractorService::class);

        return new class(new Parser(), $ocr, $rawText, $layoutText, $smalotText) extends PdfTextExtractorService {
            public function __construct(
                Parser $parser,
                PdfOcrExtractorService $ocr,
                private readonly string $rawTextStub,
                private readonly string|\Throwable $layoutTextStub,
                private readonly string $smalotTextStub,
            ) {
                parent::__construct($parser, $ocr);
            }

            protected function extractWithPdfToText(string $path): string
            {
                return $this->rawTextStub;
            }

            protected function extractWithPdfToTextLayout(string $path): string
            {
                if ($this->layoutTextStub instanceof \Throwable) {
                    throw $this->layoutTextStub;
                }
                return $this->layoutTextStub;
            }

            protected function parseText(string $path): string
            {
                return $this->smalotTextStub;
            }
        };
    }

    // =========================================================================
    // TASK 3 — extract() routing branches
    // =========================================================================

    public function test_3_1_short_tag_text_uses_layout_output(): void
    {
        $sentinel = "LAYOUT_OUTPUT_SENTINEL\nP1S FW-85BZ30L P1E\nP1S CM20 P1E\nH1 X H1E";

        $svc = $this->service([
            'rawText'    => $this->rawBaseline,   // short-tag shape → triggers detector
            'layoutText' => $sentinel,
        ]);

        $out = $svc->extract('/dev/null/fake.pdf');

        $this->assertStringContainsString('LAYOUT_OUTPUT_SENTINEL', $out);
    }

    public function test_3_2_short_tag_with_empty_layout_falls_back_to_raw(): void
    {
        // Capture the warning log entry so we can assert it fired.
        Log::spy();

        $svc = $this->service([
            'rawText'    => $this->rawBaseline,   // short-tag shape → triggers detector
            'layoutText' => '',                   // simulates binary missing / process failure
        ]);

        $out = $svc->extract('/dev/null/fake.pdf');

        $this->assertSame($this->rawBaseline, $out, 'extract() should return the -raw text verbatim when -layout returns empty.');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context = []): bool {
            return str_contains($message, '-layout re-extract returned empty');
        });
    }

    public function test_3_3_short_tag_with_layout_throwing_falls_back_to_raw(): void
    {
        Log::spy();

        $svc = $this->service([
            'rawText'    => $this->rawBaseline,
            'layoutText' => new \RuntimeException('simulated process failure'),
        ]);

        $out = $svc->extract('/dev/null/fake.pdf');

        $this->assertSame($this->rawBaseline, $out, 'extract() should catch Throwable and return -raw text verbatim.');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context = []): bool {
            return str_contains($message, '-layout re-extract threw');
        });
    }

    public function test_3_4_long_tag_text_never_invokes_layout_re_extract(): void
    {
        $longTag = "SITENAMESTART Acme SITENAMEEND PARTSTART foo PARTEND OVERVIEWTITLESTART hi OVERVIEWTITLEEND";

        $svc = $this->service([
            'rawText' => $longTag,
            // If invoked, this exception propagates — proves the layout
            // method was never called when long-tag markers are present.
            'layoutText' => new \LogicException('extractWithPdfToTextLayout MUST NOT be called on long-tag input.'),
        ]);

        $out = $svc->extract('/dev/null/fake.pdf');

        // No exception thrown → layout method was never called.
        $this->assertSame($longTag, $out, 'Long-tag input should pass through Stage 0 -raw text unchanged.');
    }

    public function test_3_5_empty_stage_zero_output_drops_to_smalot_path(): void
    {
        $smalotText = 'SITENAMESTART AcmeFromSmalot SITENAMEEND ' . str_repeat('legitimate prose with multiple words and structure ', 20);

        $svc = $this->service([
            'rawText'    => '',           // binary missing / shell_exec disabled / pdftotext output empty
            'layoutText' => new \LogicException('extractWithPdfToTextLayout MUST NOT be called when Stage 0 returns empty.'),
            'smalotText' => $smalotText,
        ]);

        $out = $svc->extract('/dev/null/fake.pdf');

        // Smalot path produced usable text → returned. Layout never called.
        $this->assertStringContainsString('AcmeFromSmalot', $out);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
