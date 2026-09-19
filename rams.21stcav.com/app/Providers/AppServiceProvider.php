<?php

namespace App\Providers;

use App\Core\Modules\Projects\ProjectDataService;
use App\Models\CableSchedule;
use App\Models\InstallTask;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\Worksheet;
use App\Observers\InstallTaskObserver;
use App\Policies\CableSchedulePolicy;
use App\Policies\OmManualPolicy;
use App\Policies\ProjectDrawingPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\RamsDocumentPolicy;
use App\Policies\SiteSurveyPolicy;
use App\Policies\WorksheetPolicy;
use App\Repositories\ProductTaxonomyRepository;
use App\Services\PdfOcrExtractorService;
use App\Services\PdfTextExtractorService;
use App\Services\WorkerMonitorService;
use App\Support\Filesystem\WindowsSafeFilesystem;
use App\Support\Rams\RamsTheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

        // ── Worksheet product taxonomy (phase 260727-wt1 Plan 01) ─────────────
        // Single instance per request so WorksheetClassifier (Plan 02) and
        // LearnedTaxonomyWriter (Plan 04) share the same object. No state
        // beyond Eloquent's own query cache, so the singleton is purely a
        // consistency + micro-perf choice — swap for bind() if a future
        // per-request stateful decorator ever gets layered on.
        $this->app->singleton(ProductTaxonomyRepository::class);

        // ── RAMS shared design tokens (phase 260726-rf3) ──────────────────────
        // Single-source-of-truth theme for both RAMS renderers (DOCX +
        // PDF). Both renderers resolve the same singleton so a design
        // change edits one config file and reflows both outputs. See
        // config/rams_theme.php + App\Support\Rams\RamsTheme.
        $this->app->singleton(RamsTheme::class, function ($app) {
            return RamsTheme::fromConfig(
                (array) $app['config']->get('rams_theme', [])
            );
        });

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

        // ── ODBC driver resolver (260723-qw1) ─────────────────────────────────
        // Laravel 12 has no built-in `odbc` driver. Register a minimal resolver
        // that wraps PDO in Illuminate\Database\Connection. LAZY — only fires
        // when DB::connection('quotewerks') is first used, so blank env vars
        // never break boot/migrate/tinker. Ported from service.21stcav.com.
        //
        // No try/catch — QuoteWerksDbFetcher::fetch() catches PDOException
        // at the call site and re-throws as QuoteWerksUnreachableException.
        // Wrapping in the resolver would swallow the diagnostic.
        DB::extend('odbc', function (array $config, string $name): \Illuminate\Database\Connection {
            $dsn      = (string) ($config['dsn'] ?? '');
            $username = $config['username'] ?? null;
            $password = $config['password'] ?? null;
            $options  = $config['options'] ?? [];

            $pdo = new \PDO($dsn, $username, $password, $options);

            // Connection ctor: ($pdo, $database, $tablePrefix, $config). ODBC
            // has no per-connection database (DSN abstracts it); prefix unused.
            return new \Illuminate\Database\Connection($pdo, '', '', $config);
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
        Gate::policy(OmManual::class, OmManualPolicy::class);
        Gate::policy(ProjectDrawing::class, ProjectDrawingPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        // Re-audit S-04 — one enforcement point per model so retry-generation
        // handlers share the same shape as RamsController's authorize() call.
        Gate::policy(Worksheet::class, WorksheetPolicy::class);
        Gate::policy(CableSchedule::class, CableSchedulePolicy::class);
        // Quick task 260817-w4k — SiteSurvey was the only document type
        // reachable through DocumentEditController with no policy behind
        // it, so can('update', $survey) denied while the other four types
        // allowed. Registered here before the AI-edit endpoints were
        // routed through the policy.
        Gate::policy(SiteSurvey::class, SiteSurveyPolicy::class);

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
        //
        // Cache-gated so the RAMS queue:work cron (fires every minute) doesn't
        // spam laravel.log with ~1440 identical warnings a day. Cache::add()
        // returns true only when the key wasn't set — so we log once per
        // 24h window until the delegate is installed. Same gating on the
        // catch block so a broken imagick extension doesn't spam either.
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            try {
                $formats = (new \Imagick)->queryFormats('HEI*');
                if (empty($formats) && Cache::add('heic-delegate-missing-warned', 1, now()->addDay())) {
                    Log::warning(
                        'AppServiceProvider: imagick loaded but HEIC delegate missing. '
                        .'HEIC uploads will fail. Install libheif-dev and recompile ImageMagick.'
                    );
                }
            } catch (\Throwable $e) {
                if (Cache::add('imagick-check-failed-warned', 1, now()->addDay())) {
                    Log::warning(
                        'AppServiceProvider: imagick extension check failed: '.$e->getMessage()
                    );
                }
            }
        }

        // ── Quick task 260919-fq8: per-token throttle keys for public worksheet
        //    routes ─────────────────────────────────────────────────────────────
        // Every public worksheet route used to key its `throttle:N,1` bucket on
        // IP alone. Two engineers behind the same office NAT/site guest wifi
        // shared a bucket, so one engineer's photo-upload burst could 429 a
        // different engineer's worksheet. Keying on the {token} route param
        // (proven pattern: service-contractor-creator's `pmv-engineer-*`
        // limiters) gives every worksheet its own independent bucket. The
        // `?: $request->ip()` fallback is load-bearing — without it a request
        // that somehow reaches these routes with no token resolves to an empty
        // string key, i.e. one shared unlimited bucket for every such request.
        RateLimiter::for(
            'worksheet-sign',
            // Reconsidered during this task (was going to stay 10/min,
            // unchanged from the old IP-based default, purely re-scoped to
            // per-token). Raised to 30/min instead: per-token keying already
            // removes the cross-engineer collision that caused the reported
            // "signature didn't work" confusion on 2026-09-18, but an
            // engineer alone can still exhaust a 10/min budget by tapping a
            // stuck submit button mid-retry — which presents identically to
            // that same incident, just self-inflicted instead of shared. The
            // sibling SCC app's equivalent submit route (`pmv-engineer-
            // submit`) runs at 30/min for the same reason. 30/min still
            // leaves this the tightest write limiter in the block (well
            // below the 60/min status-write budget) since a legitimate
            // sign-off is a rare, deliberate action, not a polling loop.
            fn (Request $request) => Limit::perMinute(30)
                ->by((string) $request->route('token') ?: $request->ip())
        );
        // Ordinary photo mutation routes (upload/delete). Unchanged numeric
        // budget from the old IP-based default, now scoped per-token.
        RateLimiter::for(
            'worksheet-photo-write',
            fn (Request $request) => Limit::perMinute(30)
                ->by((string) $request->route('token') ?: $request->ip())
        );
        // Halved from the blanket 30/min other photo-write routes get: this
        // route triggers paid AI extraction (DeviceLabelPhotoService, called
        // from uploadLabelPhoto() below) on every accepted upload, so a
        // compromised/leaked token now has a bounded worst-case AI spend per
        // minute. Deliberately keyed on token ONLY (no IP component) — a
        // composite token+IP key would let an attacker rotating IPs get a
        // fresh AI-cost budget on every new IP for the same token, which is
        // worse for cost control than a token-only key that caps total spend
        // no matter how many IPs are used.
        RateLimiter::for(
            'worksheet-label-photo-upload',
            fn (Request $request) => Limit::perMinute(15)
                ->by((string) $request->route('token') ?: $request->ip())
        );
        // Covers room-complete, survey-reviewed, label-photo confirm, and
        // reference-file serve. Unchanged numeric budget, now per-token.
        RateLimiter::for(
            'worksheet-status-write',
            fn (Request $request) => Limit::perMinute(60)
                ->by((string) $request->route('token') ?: $request->ip())
        );
        // Covers survey-photo serve. Unchanged numeric budget, now per-token.
        RateLimiter::for(
            'worksheet-survey-photo-read',
            fn (Request $request) => Limit::perMinute(120)
                ->by((string) $request->route('token') ?: $request->ip())
        );
    }
}
