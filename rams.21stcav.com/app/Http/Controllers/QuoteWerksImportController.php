<?php

namespace App\Http\Controllers;

use App\Core\Modules\QuoteImport\QuoteWerksImportService;
use App\Http\Requests\QuoteWerksLookupRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * QuoteWerksImportController — handles HTTP requests for SQL-based quote import.
 *
 * Two entry points:
 *   POST /quote-import/quotewerks/lookup — import by reference number (direct → review)
 *   POST /quote-import/quotewerks/search — search by client name (→ results displayed in UI)
 *
 * Both paths land at the same review page as the PDF import path.
 * Connection failures are shown as inline flash errors; the user is not sent to an error page.
 *
 * SECURITY: QuoteWerks credentials never appear in responses, logs visible to users, or views.
 * Raw SQL Server error messages are logged server-side and replaced with safe user messages.
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
     * @throws \Throwable  On unexpected failure (logged; user sees generic error).
     */
    public function lookup(QuoteWerksLookupRequest $request): RedirectResponse
    {
        $reference = strtoupper(trim($request->validated('qw_reference', '')));

        if (empty($reference)) {
            return back()->withErrors(['qw_reference' => 'Please enter a quote reference number.'])->withInput();
        }

        try {
            $package = $this->qwImportService->importByReference(
                user:      $request->user(),
                reference: $reference,
            );

            return redirect()->route('quote-import.review', $package)
                ->with('success', "Quote {$reference} imported from QuoteWerks. Please review and confirm.");

        } catch (ModelNotFoundException) {
            return back()
                ->withErrors(['qw_reference' => "Quote reference '{$reference}' was not found in QuoteWerks."])
                ->withInput();

        } catch (\Throwable $e) {
            Log::error('QuoteWerksImportController: lookup failed', [
                'reference' => $reference,
                'user_id'   => $request->user()->id,
                'error'     => $e->getMessage(),
            ]);

            $userMessage = $this->connectionErrorMessage($e);

            return back()
                ->with('error', $userMessage)
                ->withInput();
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Search by client name
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Search QuoteWerks by client name; return results for display in the UI.
     * Results are flashed to session and the create page is re-rendered with them.
     *
     * @param  QuoteWerksLookupRequest  $request
     * @return RedirectResponse
     */
    public function search(QuoteWerksLookupRequest $request): RedirectResponse
    {
        $clientName = trim($request->validated('client_name', ''));

        if (strlen($clientName) < 2) {
            return back()->withErrors(['client_name' => 'Enter at least 2 characters to search.'])->withInput();
        }

        try {
            $results = $this->qwImportService->searchByClient(
                clientName: $clientName,
                dateFrom:   $request->validated('date_from'),
            );

            return back()
                ->withInput()
                ->with('qw_search_results', $results)
                ->with('qw_search_query', $clientName);

        } catch (\Throwable $e) {
            Log::error('QuoteWerksImportController: search failed', [
                'client_name' => $clientName,
                'user_id'     => $request->user()->id,
                'error'       => $e->getMessage(),
            ]);

            return back()
                ->with('error', $this->connectionErrorMessage($e))
                ->withInput();
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Map an exception to a safe user-visible message.
     * Raw SQL Server error details are never shown to the user.
     *
     * @param  \Throwable  $e
     * @return string
     */
    private function connectionErrorMessage(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'could not find driver') || str_contains($msg, 'pdo_sqlsrv')) {
            return 'QuoteWerks is not configured on this server. Contact your administrator.';
        }

        if (str_contains($msg, 'timeout') || str_contains($msg, 'timed out') || str_contains($msg, 'unreachable')) {
            return 'Could not connect to QuoteWerks. Check your VPN connection. You can upload a PDF instead.';
        }

        if (str_contains($msg, 'login') || str_contains($msg, 'password') || str_contains($msg, 'authentication')) {
            return 'QuoteWerks authentication failed. Contact your administrator.';
        }

        return 'Could not connect to QuoteWerks. Check VPN connection. You can upload a PDF instead.';
    }
}
