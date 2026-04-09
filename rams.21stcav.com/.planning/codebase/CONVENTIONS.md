# Coding Conventions

**Analysis Date:** 2026-04-09

## Naming Patterns

**Files:**
- Controllers: PascalCase with `Controller` suffix — `RamsController.php`, `SiteSurveyController.php`
- Services: PascalCase with `Service` suffix — `QuoteParserService.php`, `RamsBuilderService.php`
- Models: PascalCase singular — `RamsDocument.php`, `ProjectPackage.php`, `SiteSurvey.php`
- Jobs: PascalCase with `Job` suffix, verb-first — `BuildRamsDocumentJob.php`, `ExtractRamsDraftJob.php`
- Form Requests: PascalCase with `Request` suffix — `RamsFormRequest.php`, `RamsUploadRequest.php`
- Policies: PascalCase with `Policy` suffix matching model — `RamsDocumentPolicy.php`, `OmManualPolicy.php`
- Prompts: PascalCase with `Prompt` suffix — `MethodStatementPrompt.php`, `RamsPrompt.php`
- Blade views: kebab-case — `quote-review.blade.php`, `_room-form.blade.php` (partials prefixed with underscore)
- Legacy/backup files: numeric date suffix — `PdfService2403-1807.php`, `RamsReviewDataService2703.php` (NOT recommended for new code)

**Functions:**
- camelCase throughout: `buildFromForm()`, `buildFromReview()`, `resolveRamsDocxPath()`
- Boolean methods prefixed with `is`/`has`: `isAdmin()`, `isSuperseded()`, `isPipelineStatus()`
- Private helpers follow same camelCase: `stripFences()`, `resolveScope()`

**Variables:**
- camelCase: `$ramsDocument`, `$reviewPayload`, `$generatedData`
- Short descriptive names in tight scope: `$e` for exceptions, `$ctx` for context arrays

**Types/Constants:**
- Model status constants: `STATUS_UPPERCASE_SNAKE` — `STATUS_AWAITING_REVIEW`, `STATUS_GENERATING`
- Class constants: `UPPERCASE_SNAKE` — `ANTHROPIC_VERSION`, `DEFAULT_TIMEOUT`, `CONFIDENCE_THRESHOLD`

## Code Style

**Formatting:**
- Laravel Pint (dev dependency in `composer.json`)
- PSR-12 style: 4-space indentation, opening braces on same line for classes/methods
- Aligned column formatting for class properties and array keys (see `RamsDocument::$fillable`, `ClaudeProvider` constructor)

**Linting:**
- Laravel Pint (`laravel/pint` ^1.24) — PSR-12 base
- No `.php-cs-fixer` or custom Pint config detected; uses Pint defaults

**Section Separators:**
- Use ASCII art comment dividers to separate controller methods:
```php
// ─────────────────────────────────────────────────────────────────────────
// methodName
// ─────────────────────────────────────────────────────────────────────────
```
- Use `// ══════` or `// ────────` for major sections in service classes
- Use `// ── Label ──` for subsections within methods

## Import Organization

**Order:**
1. Framework imports (`Illuminate\*`, `Symfony\*`)
2. App namespace imports (`App\*`) — models, services, jobs, requests
3. No blank lines between groups (single block)

**Path Aliases:**
- Standard PSR-4 autoloading: `App\` maps to `app/`
- No path aliases (`@` or `~`) for PHP
- No barrel files or index re-exports

## Error Handling

**Controllers — try/catch with user-friendly flash messages:**
```php
try {
    $this->ramsBuilder->buildFromForm($validated, $ramsDocument);
} catch (\Throwable $e) {
    Log::error('RamsController: RAMS build failed', [
        'record_id' => $ramsDocument->id,
        'error'     => $e->getMessage(),
    ]);
    $ramsDocument->update(['status' => RamsDocument::STATUS_DRAFT]);
    return back()->with('error', 'The document could not be generated. Please try again.');
}
```

**Jobs — catch, update status to FAILED, re-throw:**
```php
try {
    // ... work
} catch (\Throwable $e) {
    Log::error('BuildRamsDocumentJob: Phase B generation failed', [
        'record_id' => $this->ramsDocumentId,
        'error'     => $e->getMessage(),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
        'attempt'   => $this->attempts(),
    ]);
    $record->update([
        'status'        => RamsDocument::STATUS_FAILED,
        'error_message' => $e->getMessage(),
    ]);
    throw $e;
}
```

**Services — defensive design, return empty/defaults rather than throw:**
- `QuoteParserService`: returns empty strings/arrays for missing data (never throws)
- `MethodStatementService`: returns static 5-phase fallback when AI fails
- AI providers: throw `RuntimeException` on HTTP failure or invalid JSON

**Custom Exceptions:**
- `App\Exceptions\AIGenerationException` — thrown by AI manager after retry exhaustion
- `App\Exceptions\RamsGenerationException` — thrown during RAMS pipeline failures

**Authorization:**
- Use `$this->authorize('view', $rams)` in controllers (policy-based)
- Use `abort_if()` / `abort_unless()` for inline admin checks:
```php
abort_unless(auth()->user()?->isAdmin(), 403);
```

## Logging

**Framework:** Laravel `Log` facade (`Illuminate\Support\Facades\Log`)

**Patterns:**
- Always prefix log messages with class name: `'RamsController: document soft-deleted'`
- Include structured context array with relevant IDs:
```php
Log::error('BuildRamsDocumentJob: Phase B generation failed', [
    'record_id' => $this->ramsDocumentId,
    'error'     => $e->getMessage(),
    'file'      => $e->getFile(),
    'line'      => $e->getLine(),
]);
```
- Use `Log::info()` for successful operations and state transitions
- Use `Log::error()` for failures and exceptions
- Use `Log::warning()` for unexpected-but-recoverable states (e.g., missing `project_id` on creating)
- Model boot events log warnings for data integrity issues:
```php
static::creating(function (self $model): void {
    if (empty($model->project_id)) {
        Log::warning('RamsDocument: creating record without project_id — ...');
    }
});
```

## Comments

**When to Comment:**
- PHPDoc blocks on all classes explaining purpose, usage patterns, and pipeline position
- PHPDoc on all public methods with `@param`, `@return`, `@throws`
- Inline comments for non-obvious business logic or defensive guard clauses
- Large constant arrays have inline `// ...` explaining why specific values are included/excluded

**DocBlock Style:**
```php
/**
 * Orchestrates RAMS document generation.
 *
 * Entry points:
 *   buildFromForm($formData, $record)
 *   buildFromReview($reviewedData, $formData, $record)
 *
 * Pipeline stages:
 *   1. Parse / classify / resolve risks  (local, no AI)
 *   2. Generate method statement         (AI — single AI call)
 */
```

## Data Flow Pattern

**Controller to Service to Model:**

1. **Controller** receives request, validates via FormRequest, calls service
2. **Service** orchestrates business logic, calls other services/AI
3. **Model** persists data, defines relationships and status constants

**Standard RAMS pipeline flow:**
```
Controller::store()
  → FormRequest validates
  → Model::create() (placeholder record)
  → Service::buildFromForm() or Job dispatch
    → QuoteParserService (local text parsing)
    → EquipmentClassifierService (local classification)
    → RiskTemplateResolverService (local risk lookup)
    → MethodStatementGeneratorService (AI call via prompt DTO)
    → RamsDataBuilderService (assemble data)
    → RamsDocumentRendererService (write DOCX)
  → Model::update() (final status)
```

**AI Prompt Pattern:**
```
BasePrompt (abstract)
  → MethodStatementPrompt extends BasePrompt
    → build(array $context): string    // constructs prompt text
    → systemMessage(): string          // AI persona/rules
    → maxTokens(): int                 // token budget
    → temperature(): float             // sampling temperature

AIProviderContract (interface)
  → ClaudeProvider implements AIProviderContract
  → OpenAIProvider implements AIProviderContract
    → execute(BasePrompt $prompt): array  // returns decoded JSON
```

## Function Design

**Size:** Methods are moderately sized (20-60 lines typical). Large controllers have many methods but each is focused.

**Constructor injection:** Services use PHP 8 constructor promotion with `private readonly`:
```php
public function __construct(
    private readonly QuoteParserService         $quoteParser,
    private readonly EquipmentClassifierService $classifier,
    private readonly RamsDocumentRendererService $renderer,
) {}
```

**Return values:** 
- Controllers return typed responses: `View`, `RedirectResponse`, `JsonResponse`, `BinaryFileResponse`
- Services return data arrays or file paths (strings)
- Parsers return structured arrays with documented shape (see `QuoteParserService` class docblock)

## Module Design

**Exports:** No barrel files. Each class is imported directly by full namespace.

**Dual location pattern for services:**
- `app/Services/` — flat namespace for all service classes (primary location)
- `app/Core/Modules/{Module}/` — module-specific service classes (e.g., `HazardLibraryService`)
- `app/Core/AI/` — AI abstraction layer (Contracts, Prompts, Providers)

**Model constants over enums:** Status values use `const STATUS_*` strings on the Model class, not PHP enums. Test assertions reference these constants directly: `RamsDocument::STATUS_FAILED`.

**Queue jobs:** Jobs implement `ShouldQueue`, use `Dispatchable`, `InteractsWithQueue`, `Queueable`, `SerializesModels` traits. Accept record ID in constructor (not the model). Set `$tries` and `$timeout`. Implement `failed()` hook.

---

*Convention analysis: 2026-04-09*
