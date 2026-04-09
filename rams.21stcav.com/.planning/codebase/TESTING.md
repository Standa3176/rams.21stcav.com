# Testing Patterns

**Analysis Date:** 2026-04-09

## Test Framework

**Runner:**
- PHPUnit 11.5.3+
- Config: `phpunit.xml`

**Assertion Library:**
- PHPUnit native assertions (`assertSame`, `assertArrayHasKey`, `assertDatabaseHas`, etc.)
- Laravel test helpers (`assertRedirectToRoute`, `assertSessionHas`, `assertViewHas`, etc.)

**Mocking:**
- Mockery 1.6+ (via Laravel's `$this->mock()` helper)
- Laravel `Http::fake()` for external API calls
- Laravel `Bus::fake()`, `Queue::fake()`, `Storage::fake()` for infrastructure

**Run Commands:**
```bash
composer test                    # Clear config + run all tests
php artisan test                 # Run all tests
php artisan test --filter=QuoteParserServiceTest  # Run specific test class
php artisan test --testsuite=Unit    # Unit tests only
php artisan test --testsuite=Feature # Feature tests only
```

## Test Environment

Configured in `phpunit.xml`:
- `APP_ENV=testing`
- `DB_CONNECTION=sqlite` with `DB_DATABASE=:memory:` (in-memory SQLite)
- `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`
- `MAIL_MAILER=array`
- Telescope, Pulse, Nightwatch disabled

## Test File Organization

**Location:** Separate `tests/` directory, mirroring app structure by domain.

**Naming:** `{ClassName}Test.php` — matches the class under test.

**Structure:**
```
tests/
├── TestCase.php                          # Base test case (extends Laravel's)
├── Feature/
│   ├── Auth/
│   │   ├── AuthenticationTest.php        # Breeze scaffold
│   │   ├── EmailVerificationTest.php
│   │   ├── PasswordConfirmationTest.php
│   │   ├── PasswordResetTest.php
│   │   ├── PasswordUpdateTest.php
│   │   └── RegistrationTest.php
│   ├── ExampleTest.php
│   ├── ProfileTest.php
│   ├── OmManual/
│   │   └── OmManualProjectLinkageTest.php
│   ├── Projects/
│   │   └── QuoteProjectResolutionTest.php
│   └── Rams/
│       ├── ManualRamsCreationTest.php
│       ├── QuoteUploadRamsCreationTest.php
│       └── ReviewWorkflowTest.php
└── Unit/
    ├── ExampleTest.php
    ├── Rams/
    │   ├── DocxGenerationSmokeTest.php
    │   ├── MethodStatementFallbackTest.php
    │   └── QuoteParserServiceTest.php
    └── Services/
        ├── AICacheServiceTest.php
        ├── AiSettingsServiceTest.php
        └── WorkerMonitor/
            └── WorkerMonitorServiceTest.php
```

## Test Structure

**Base Test Case:**
`tests/TestCase.php` extends `Illuminate\Foundation\Testing\TestCase` with no customizations.

**Suite Organization — Unit tests (pure PHP, no container):**
```php
namespace Tests\Unit\Rams;

use App\Services\QuoteParserService;
use PHPUnit\Framework\TestCase;  // <-- Note: PHPUnit base, NOT Laravel

class QuoteParserServiceTest extends TestCase
{
    private QuoteParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new QuoteParserService();
    }

    public function test_parse_returns_all_six_required_keys(): void
    {
        $result = $this->parser->parse('');
        $this->assertArrayHasKey('client', $result);
        // ...
    }
}
```

**Suite Organization — Unit tests (with Laravel container):**
```php
namespace Tests\Unit\Services;

use Tests\TestCase;  // <-- Laravel base
use Illuminate\Foundation\Testing\RefreshDatabase;

class AICacheServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new AICacheService();
    }
}
```

**Suite Organization — Feature tests (full HTTP):**
```php
namespace Tests\Feature\Rams;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ManualRamsCreationTest extends TestCase
{
    use RefreshDatabase;

    private array $generatedFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->generatedFiles as $path) {
            if (file_exists($path)) { @unlink($path); }
        }
        parent::tearDown();
    }

    // Helper methods for test data
    private function validFormPayload(array $overrides = []): array { /* ... */ }
    private function fakeClaudeResponse(array $phases): void { /* ... */ }

    public function test_authenticated_user_can_create_rams_from_form(): void { /* ... */ }
}
```

**Naming convention for test methods:** `test_snake_case_description` — descriptive, reads as a sentence.

**Section separators:** Tests are grouped with `// =========` comment blocks by category (e.g., "OUTPUT SHAPE", "CLIENT EXTRACTION", "EQUIPMENT EXTRACTION").

## Mocking Patterns

**External AI calls — Http::fake():**
```php
private function fakeClaudeResponse(array $phases): void
{
    Http::fake(['*' => Http::response([
        'content'     => [['type' => 'text', 'text' => json_encode(['phases' => $phases])]],
        'stop_reason' => 'end_turn',
    ], 200)]);
}
```

**Job dispatch — Bus::fake():**
```php
Bus::fake();
$this->actingAs($user)->post(route('rams.upload.store'), [...]);
Bus::assertDispatched(ExtractRamsDraftJob::class, function ($job) use ($record) {
    return $job->ramsDocumentId === $record->id;
});
```

**Service mocking — Mockery via Laravel helper:**
```php
$this->mock(QuoteTextExtractorService::class, function ($mock) {
    $mock->shouldReceive('extract')
         ->once()
         ->andReturn('Client: Acme Ltd ...');
});
```

**Builder mock with argument verification:**
```php
$builderMock = $this->mock(RamsBuilderService::class);
$builderMock->shouldReceive('buildFromReview')
    ->once()
    ->withArgs(function ($passedReviewData, $passedFormData, $passedRecord) use ($reviewedData, $record) {
        return $passedReviewData['project']['project_name'] === 'REVIEWED Name — should be used'
            && $passedRecord->id === $record->id;
    })
    ->andReturn('/tmp/fake.docx');
```

**Storage::fake() for file upload tests:**
```php
Storage::fake('local');
$this->actingAs($user)->post(route('rams.upload.store'), [
    'quote_pdf' => UploadedFile::fake()->create('quote.pdf', 50, 'application/pdf'),
]);
Storage::disk('local')->assertExists('rams/uploads/...');
```

**Config override in tests:**
```php
config([
    'ai.providers.claude.api_key'  => 'test-key',
    'ai.providers.claude.model'    => 'claude-sonnet-4-6',
]);
```

**What to Mock:**
- External HTTP APIs (Claude, OpenAI) — always use `Http::fake()`
- Job dispatching when testing controllers — use `Bus::fake()`
- File storage when testing uploads — use `Storage::fake()`
- Services when testing job logic in isolation — use `$this->mock()`

**What NOT to Mock:**
- The service under test itself
- Database (use `RefreshDatabase` with in-memory SQLite)
- Model relationships and query scopes
- Pure PHP services like `QuoteParserService` (no container needed)

## Fixtures and Factories

**User Factory:** Standard Laravel `User::factory()->create()` used throughout.

**Model Factories:** Only `User` has a factory. Other models are created inline via `Model::create([...])` with explicit attributes.

**Test Data Helpers:** Each test class defines private helper methods for canonical data:
```php
private function validFormPayload(array $overrides = []): array
{
    return array_merge([
        'project_ref'       => 'TEST-2025-001',
        'project_name'      => 'Feature Test Project',
        'client_name'       => 'Acme Corp',
        'site_address'      => '1 Feature Lane, London, EC1A 1AA',
        'works_description' => 'Supply and installation of AV systems.',
        'hazards'           => ['Electrocution', 'Manual Handling'],
        'ppe'               => ['Safety Boots', 'Hi-Vis Vest'],
        'persons_at_risk'   => ['21CAV Staff', 'Client Staff'],
    ], $overrides);
}

private function validReviewPayload(): array { /* canonical review schema */ }
private function minimalData(): array { /* minimal DOCX generation data */ }
```

**Cleanup pattern:** Feature tests that generate files on disk track paths and clean up in `tearDown()`:
```php
private array $generatedFiles = [];

protected function tearDown(): void
{
    foreach ($this->generatedFiles as $path) {
        if (file_exists($path)) { @unlink($path); }
    }
    parent::tearDown();
}
```

## Coverage

**Requirements:** No coverage thresholds enforced. No coverage configuration in `phpunit.xml`.

**View Coverage:**
```bash
php artisan test --coverage        # If Xdebug/PCOV available
```

## Test Types

**Unit Tests (7 test files):**
- Pure PHP unit tests extend `PHPUnit\Framework\TestCase` (no Laravel boot): `QuoteParserServiceTest`, `WorkerMonitorServiceTest`
- Laravel-dependent unit tests extend `Tests\TestCase` with `RefreshDatabase`: `AICacheServiceTest`, `AiSettingsServiceTest`, `DocxGenerationSmokeTest`
- Scope: single service class in isolation, deterministic inputs/outputs
- Focus areas: text parsing logic, cache TTL/pruning, AI connection testing, DOCX generation smoke tests, AI fallback behaviour

**Feature Tests (10 test files):**
- Full HTTP request cycle via `$this->actingAs($user)->post(route('...'))`
- Database assertions: `assertDatabaseHas()`, `assertDatabaseMissing()`, `assertDatabaseCount()`
- Session assertions: `assertSessionHas('success')`, `assertSessionHasErrors()`
- Redirect assertions: `assertRedirectToRoute()`, `assertRedirect()`
- View assertions: `assertViewIs()`, `assertViewHas()`
- Focus areas: RAMS manual creation, quote upload, review workflow, project resolution, O&M manual linkage, auth (Breeze scaffold)

**Integration Tests:**
- No separate integration test suite. Feature tests serve as integration tests by running the full pipeline with `Http::fake()` for AI and `Bus::fake()` for jobs.

**E2E Tests:**
- Not used. No Dusk or browser testing.

## Common Patterns

**Async Testing — running jobs synchronously:**
```php
// Run job directly without queue
(new ExtractRamsDraftJob($record->id))->handle(
    app(QuoteTextExtractorService::class),
    app(RamsExtractionDraftBuilderService::class),
);
$record->refresh();
$this->assertEquals(RamsDocument::STATUS_AWAITING_REVIEW, $record->status);
```

**Error Testing — AI failure scenarios:**
```php
public function test_returns_five_phases_when_ai_returns_http_500(): void
{
    Http::fake(['*' => Http::response('Internal Server Error', 500)]);
    $result = $this->service->generate($this->minimalParsedQuote(), $this->minimalClassified());
    $this->assertArrayHasKey('phases', $result);
    $this->assertCount(5, $result['phases']);
}
```

**Authorization Testing:**
```php
public function test_unauthenticated_request_is_redirected_to_login(): void
{
    $response = $this->post(route('rams.store'), $this->validFormPayload());
    $response->assertRedirectToRoute('login');
}
```

**Validation Testing:**
```php
public function test_validation_fails_when_required_fields_missing(): void
{
    $user = User::factory()->create();
    $response = $this->actingAs($user)->post(route('rams.store'), [
        'project_ref' => 'INVALID-001',  // Missing required fields
    ]);
    $response->assertSessionHasErrors(['project_name', 'client_name', 'site_address', 'works_description']);
    $this->assertDatabaseMissing('rams_documents', ['project_ref' => 'INVALID-001']);
}
```

**Unique test data pattern:** Each test uses unique identifiers (e.g., `'SMOKE-001'`, `'UPLOAD-STATUS-001'`, `'FALLBACK-001'`) so tests remain independent when run in sequence.

## What Is Tested vs What Is Not

**Well-tested areas:**
- `QuoteParserService` — comprehensive unit tests (60+ assertions covering all extraction heuristics)
- RAMS creation pipeline — manual form, quote upload, review workflow, generation job
- AI fallback behaviour — static fallback when AI unavailable
- AI cache TTL and pruning
- AI settings and provider connection testing
- Worker monitor heartbeat logic
- DOCX generation smoke test
- O&M manual project linkage
- Project resolution from quotes (matching rules)
- Auth flows (Breeze scaffold tests)

**Not tested / gaps:**
- `EquipmentClassifierService` — no dedicated unit tests
- `RiskTemplateResolverService` — no dedicated unit tests
- `RamsDataBuilderService` — no dedicated unit tests
- `RamsDocumentRendererService` — only indirectly via DocxGenerationSmokeTest
- `PdfTextExtractorService` / `PdfOcrExtractorService` — no tests
- `QuoteTextExtractorService` — always mocked in tests, no unit tests
- `SiteSurveyController` and survey workflow — no tests
- `CableScheduleController` / service — no tests
- `HazardTemplateController` — no tests
- `PublicSurveyController` — no tests
- Admin controllers (`SolutionTypeController`) — no tests
- Email sending (`RamsDocumentMail`) — no tests
- PDF generation via DomPDF (`PdfService`) — no tests
- Individual AI prompts (beyond MethodStatementFallbackTest) — no tests
- Model scopes and relationship queries — no dedicated tests
- Authorization policies — tested implicitly through feature tests but no dedicated policy tests

## CI/CD Testing Pipeline

**CI Pipeline:** Not detected. No `.github/workflows/`, `.gitlab-ci.yml`, or other CI configuration files found.

**Local testing only:** Tests are run via `composer test` which clears config cache and runs `php artisan test`.

---

*Testing analysis: 2026-04-09*
