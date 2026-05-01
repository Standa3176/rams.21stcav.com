<?php

namespace App\Providers;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\InstallTask;
use App\Models\OmManual;
use App\Models\ProjectDrawing;
use App\Models\RamsDocument;
use App\Observers\InstallTaskObserver;
use App\Policies\OmManualPolicy;
use App\Policies\ProjectDrawingPolicy;
use App\Policies\RamsDocumentPolicy;
use App\Services\PdfOcrExtractorService;
use App\Services\PdfTextExtractorService;
use App\Services\WorkerMonitorService;
use App\Support\Filesystem\WindowsSafeFilesystem;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Parser::class, function () {
            $config = new Config;
            $config->setIgnoreEncryption(true);

            return new Parser([], $config);
        });

        $this->app->bind(PdfTextExtractorService::class, function () {
            return new PdfTextExtractorService(app(Parser::class), new PdfOcrExtractorService);
        });

        $this->app->singleton(ProjectDataService::class);

        // SchematicD2SourceBuilder takes an `array $config` that Laravel's
        // auto-resolver can't fill. Inject the drawings config explicitly
        // so SchematicGeneratorService can typehint the builder normally.
        $this->app->singleton(\App\Services\Drawings\SchematicD2SourceBuilder::class, function ($app) {
            return new \App\Services\Drawings\SchematicD2SourceBuilder(
                (array) $app['config']->get('drawings', [])
            );
        });

        // Harden file replacement on Windows to avoid intermittent
        // Blade compile failures: rename(...): Access is denied (code 5).
        $this->app->singleton('files', fn () => new WindowsSafeFilesystem);
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
        Gate::policy(OmManual::class, OmManualPolicy::class);
        Gate::policy(ProjectDrawing::class, ProjectDrawingPolicy::class);

        // ── Phase 16: commissioning generation trigger (D-03) ────────────────
        // Observer fires CommissioningItemGenerator::generate() when the
        // LAST install_task in a programme flips to STATUS_COMPLETE. Guard
        // logic lives in the observer (wasChanged + remaining count) so
        // mid-flight completions never trigger generation.
        InstallTask::observe(InstallTaskObserver::class);

        // ── Worker heartbeat — write on every queue loop + job completion ────
        // Fixes the observability gap behind the "clicked regenerate, nothing
        // completed for 5+ min" incident: previously the heartbeat file only
        // existed when spawnWorker() was used (not the normal case), so
        // WorkerMonitorService::isRunning() fell back to worker.log mtime and
        // went stale the moment the worker idled. These hooks keep the file
        // continuously fresh while any queue:work loop is running.
        Event::listen(Looping::class, fn () => app(WorkerMonitorService::class)->writeHeartbeat());
        Event::listen(JobProcessed::class, fn () => app(WorkerMonitorService::class)->writeHeartbeat());
        Event::listen(WorkerStopping::class, fn () => app(WorkerMonitorService::class)->clearHeartbeat());

        // ── Phase 14: libheif delegate health check ───────────────────────────
        // Warn at boot if imagick is loaded but libheif delegate is missing.
        // Non-blocking — app still starts; HEIC uploads will surface the error
        // at upload time via HeicImageConverter. See CONTEXT.md D-11 (fail-loud)
        // and 14-RESEARCH.md Pitfall 1 (libheif delegate trap).
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            try {
                $formats = (new \Imagick)->queryFormats('HEI*');
                if (empty($formats)) {
                    Log::warning(
                        'AppServiceProvider: imagick loaded but HEIC delegate missing. '
                        .'HEIC uploads will fail. Install libheif-dev and recompile ImageMagick.'
                    );
                }
            } catch (\Throwable $e) {
                Log::warning(
                    'AppServiceProvider: imagick extension check failed: '.$e->getMessage()
                );
            }
        }
    }
}
