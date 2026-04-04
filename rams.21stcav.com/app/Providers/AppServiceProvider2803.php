<?php

namespace App\Providers;

use App\Models\OmManual;
use App\Models\RamsDocument;
use App\Policies\OmManualPolicy;
use App\Policies\RamsDocumentPolicy;
use App\Services\PdfOcrExtractorService;
use App\Services\PdfTextExtractorService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Smalot\PdfParser\Parser;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PdfTextExtractorService::class, function () {
            return new PdfTextExtractorService(new Parser(), new PdfOcrExtractorService());
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ── Authorization policies ────────────────────────────────────────────
        // Although Laravel 11 auto-discovers policies that follow the naming
        // convention (ModelPolicy → Model), we register explicitly for clarity
        // and to make the relationship visible at a glance.
        Gate::policy(RamsDocument::class, RamsDocumentPolicy::class);
        Gate::policy(OmManual::class,     OmManualPolicy::class);
    }
}
