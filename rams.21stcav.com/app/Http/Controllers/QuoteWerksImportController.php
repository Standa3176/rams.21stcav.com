<?php

namespace App\Http\Controllers;

use App\Core\Modules\QuoteImport\QuoteWerksImportService;
use App\Exceptions\QuoteWerksUnreachableException;
use App\Http\Requests\QuoteWerksLookupRequest;
use App\Models\ProjectPackage;
use App\Services\Imports\Quote\QuoteWerksDbFetcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * QuoteWerksImportController — direct-import from QuoteWerks SQL Server
 * over the office WireGuard tunnel (260723-qw1).
 *
 * Two entry points:
 *   POST /quote-import/quotewerks/lookup — import by reference number →
 *     dup-check → ODBC fetch → RAMS review UI.
 *   POST /quote-import/quotewerks/search — search by client name →
 *     re-renders create page with results table.
 *
 * Both paths use standard RAMS server-flash + Blade redirect conventions
 * (NOT SCC's Alpine SPA + JSON responses). Dup-check surfaces as a warning
 * flash with a "Continue anyway" link (?force=1), not a modal.
 *
 * Connection failures (WireGuard down, ODBC DSN misconfigured, MSSQL
 * unreachable) render as user-safe flash errors — the raw PDOException is
 * logged server-side and never surfaces in the response.
 */
class QuoteWerksImportController extends Controller
{
    public function __construct(
        private readonly QuoteWerksImportService $qwImportService,
    ) {}

    // ══════════════════════════════════════════════════════════════════════════
    // Import by reference number
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Import a quote by reference number and redirect to review.
     *
     * Flow:
     *   1. Validate + normalize reference.
     *   2. Dup-check ProjectPackage.extracted_data.quote_ref (skipped on ?force=1).
     *   3. ODBC fetch header + items via QuoteWerksDbFetcher.
     *   4. Guard: header null → "not found"; DocType != QUOTE → wrong-type error.
     *   5. Delegate to importFromParsedShape → redirect to review page.
     */
    public function lookup(QuoteWerksLookupRequest $request, QuoteWerksDbFetcher $fetcher): RedirectResponse
    {
        $reference = strtoupper(trim((string) $request->input('reference', '')));

        if ($reference === '') {
            return back()
                ->withErrors(['reference' => 'Please enter a quote reference number.'])
                ->withInput();
        }

        // ── Dup check ──────────────────────────────────────────────────────
        if (! $request->boolean('force')) {
            $existing = $this->findPriorImport($reference);
            if ($existing !== null) {
                $projectName = $existing->project?->name ?? 'unlinked project';
                $importedAt  = $existing->created_at?->format('j M Y H:i') ?? 'unknown date';

                return back()
                    ->withInput()
                    ->with(
                        'warning',
                        sprintf(
                            'Quote %s was imported on %s as "%s". Import anyway?',
                            $reference,
                            $importedAt,
                            $projectName
                        )
                    )
                    ->with('qw_force_url', route('quotewerks.lookup') . '?force=1&reference=' . urlencode($reference))
                    ->with('qw_last_reference', $reference);
            }
        }

        // ── Fetch ──────────────────────────────────────────────────────────
        try {
            $result = $fetcher->fetch($reference);
        } catch (QuoteWerksUnreachableException $e) {
            Log::error('QuoteWerksImportController: fetcher unreachable', [
                'reference' => $reference,
                'user_id'   => $request->user()->id,
                'error'     => $e->getMessage(),
                'previous'  => $e->getPrevious()?->getMessage(),
            ]);

            return back()
                ->with('error', 'Cannot reach QuoteWerks right now — please upload the quote PDF instead.')
                ->withInput();
        }

        if ($result['header'] === null) {
            return back()
                ->withErrors(['reference' => "Quote {$reference} was not found in QuoteWerks."])
                ->withInput();
        }

        // ── DocType guard ─────────────────────────────────────────────────
        $docType = (string) ($result['header']['DocType'] ?? '');
        if (strcasecmp($docType, 'QUOTE') !== 0) {
            return back()
                ->withErrors([
                    'reference' => sprintf(
                        'Document %s is not a Quote (it is a %s).',
                        $reference,
                        $docType !== '' ? $docType : 'unknown document type'
                    ),
                ])
                ->withInput();
        }

        // ── Map + persist ─────────────────────────────────────────────────
        $parsed = $fetcher->mapToParsedShape($result['header'], $result['items']);

        try {
            $package = $this->qwImportService->importFromParsedShape($request->user(), $parsed);
        } catch (\Throwable $e) {
            Log::error('QuoteWerksImportController: importFromParsedShape failed', [
                'reference' => $reference,
                'user_id'   => $request->user()->id,
                'error'     => $e->getMessage(),
            ]);

            return back()
                ->with('error', 'Failed to save the imported quote. Please try again or upload the PDF instead.')
                ->withInput();
        }

        return redirect()
            ->route('quote-import.review', $package)
            ->with('success', "Quote {$reference} imported from QuoteWerks. Please review and confirm.");
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Search by client name
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Search QuoteWerks by client name; return results for display in the UI.
     * Results are flashed to session and the create page is re-rendered.
     */
    public function search(Request $request, QuoteWerksDbFetcher $fetcher): RedirectResponse
    {
        $clientName = trim((string) $request->input('client', ''));
        $dateFrom   = $request->input('date_from');

        if (strlen($clientName) < 2) {
            return back()
                ->withErrors(['client' => 'Enter at least 2 characters to search.'])
                ->withInput();
        }

        try {
            $rows = $fetcher->searchByClient($clientName, $dateFrom ?: null);
        } catch (QuoteWerksUnreachableException $e) {
            Log::error('QuoteWerksImportController: search unreachable', [
                'client_name' => $clientName,
                'user_id'     => $request->user()->id,
                'error'       => $e->getMessage(),
                'previous'    => $e->getPrevious()?->getMessage(),
            ]);

            return back()
                ->with('error', 'Cannot reach QuoteWerks right now — please upload the quote PDF instead.')
                ->withInput();
        }

        // Map fetcher rows to the view-friendly shape the Blade template already
        // renders (doc_no, client_name, doc_date, subject).
        $results = array_map(static fn (array $row): array => [
            'doc_no'      => (string) ($row['DocNo'] ?? ''),
            'client_name' => (string) ($row['SoldToCompany'] ?? ''),
            'doc_date'    => $row['DocDate'] ?? null,
            'subject'     => (string) ($row['CustomMemo01'] ?? ''),
        ], $rows);

        return back()
            ->withInput()
            ->with('qw_search_results', $results)
            ->with('qw_search_query', $clientName);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Find the most recent successful ProjectPackage for a given QuoteWerks
     * reference. Uses JSON_EXTRACT so we don't have to add a dedicated column.
     */
    private function findPriorImport(string $reference): ?ProjectPackage
    {
        return ProjectPackage::whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(extracted_data, '$.quote_ref')) = ?",
                [$reference]
            )
            ->latest('created_at')
            ->first();
    }
}
