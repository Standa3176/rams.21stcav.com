<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\AIUsageController;
use App\Http\Controllers\Admin\SolutionTypeController;
use App\Http\Controllers\CableScheduleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HazardTemplateController;
use App\Http\Controllers\OmManualController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\QuoteImportController;
use App\Http\Controllers\QuoteUploadController;
use App\Http\Controllers\QuoteWerksImportController;
use App\Http\Controllers\RamsController;
use App\Http\Controllers\RamsReviewController;
use App\Http\Controllers\ProjectPackageReviewController;
use App\Http\Controllers\PublicSurveyController;
use App\Http\Controllers\SiteSurveyController;
use App\Http\Controllers\SurveyController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
});

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect('/login');
})->name('logout');

/*
|--------------------------------------------------------------------------
| Public Survey Routes — no authentication required
|
| Engineers access these via a unique UUID token embedded in the URL.
| The token is generated automatically when a SiteSurvey is created.
|--------------------------------------------------------------------------
*/

Route::get('survey/{token}/confirmation',               [PublicSurveyController::class, 'confirmation']) ->name('survey.confirmation');
Route::get('survey/{token}/download-form',              [SurveyController::class, 'downloadForm'])     ->name('survey.download.form');
Route::get('survey/{token}',                           [SurveyController::class, 'show'])             ->name('survey.show');
Route::post('survey/{token}/step-save',                [SurveyController::class, 'stepSave'])         ->name('survey.step.save')    ->middleware('throttle:60,1');
Route::post('survey/{token}/save',                     [PublicSurveyController::class, 'save'])        ->name('survey.save')         ->middleware('throttle:30,1');
Route::post('survey/{token}/submit',                   [PublicSurveyController::class, 'submit'])      ->name('survey.submit')       ->middleware('throttle:10,1');
Route::post('survey/{token}/rooms/{room}/photos',      [PublicSurveyController::class, 'uploadPhoto'])    ->name('survey.photos.upload')   ->middleware('throttle:30,1');
Route::post('survey/{token}/rooms/{room}/complete',    [PublicSurveyController::class, 'completeRoom'])   ->name('survey.room.complete')   ->middleware('throttle:60,1');
Route::post('survey/{token}/rooms/{room}/uncomplete',  [PublicSurveyController::class, 'uncompleteRoom']) ->name('survey.room.uncomplete') ->middleware('throttle:60,1');
Route::post('survey/{token}/rooms/{room}/questions/{question}', [PublicSurveyController::class, 'answerQuestion'])
    ->name('survey.question.answer')
    ->middleware('throttle:120,1');
Route::get('survey/{token}/photos/{photo}',            [PublicSurveyController::class, 'servePhoto'])     ->name('survey.photos.serve');

/*
|--------------------------------------------------------------------------
| RAMS Generator — authenticated routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    // ── Dashboard ─────────────────────────────────────────────────────────
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ── Profile ───────────────────────────────────────────────────────────
    Route::get('/profile',    [ProfileController::class, 'edit'])   ->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update']) ->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // ── Quote Import (enterprise PDF → ProjectPackage pipeline) ───────────
    Route::get('/quote-import',                                [QuoteImportController::class, 'create'])   ->name('quote-import.create');
    Route::post('/quote-import',                               [QuoteImportController::class, 'store'])    ->name('quote-import.store')->middleware('throttle:10,1');

    // ── QuoteWerks SQL Import — must be before {package} wildcard routes ───
    Route::post('/quote-import/quotewerks/lookup', [QuoteWerksImportController::class, 'lookup'])->name('quotewerks.lookup')->middleware('throttle:20,1');
    Route::post('/quote-import/quotewerks/search', [QuoteWerksImportController::class, 'search'])->name('quotewerks.search')->middleware('throttle:30,1');

    Route::get('/quote-import/{package}/extracting',            [QuoteImportController::class, 'extracting'])   ->name('quote-import.extracting');
    Route::get('/quote-import/{package}/extract-status',       [QuoteImportController::class, 'extractStatus'])->name('quote-import.extract-status');
    Route::get('/quote-import/{package}/review',               [QuoteImportController::class, 'review'])   ->name('quote-import.review');
    Route::post('/quote-import/{package}/confirm',             [QuoteImportController::class, 'confirm'])  ->name('quote-import.confirm');

    // Project-level data review (shared by all docs)
    Route::get('/project-packages/{package}/review',           [ProjectPackageReviewController::class, 'show'])   ->name('project-packages.review.show');
    Route::post('/project-packages/{package}/review',          [ProjectPackageReviewController::class, 'update']) ->name('project-packages.review.update');
    Route::post('/project-packages/{package}/approve',              [ProjectPackageReviewController::class, 'approve'])->name('project-packages.review.approve');
    Route::post('/project-packages/{package}/room-summary',        [ProjectPackageReviewController::class, 'generateRoomSummary'])->name('project-packages.room-summary');
    Route::post('/project-packages/{package}/generate-survey-rooms', [ProjectPackageReviewController::class, 'generateSurveyRooms'])->name('project-packages.generate-survey-rooms');
    Route::post('/project-packages/{package}/scope-of-works',    [ProjectPackageReviewController::class, 'generateScopeOfWorks'])->name('project-packages.scope-of-works');
    Route::post('/quote-import/{package}/re-extract',          [QuoteImportController::class, 'reextract'])->name('quote-import.reextract');

    // ── Projects ──────────────────────────────────────────────────────────
    Route::resource('projects', ProjectController::class)
        ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    Route::post('projects/{project}/transition',  [ProjectController::class, 'transition']) ->name('projects.transition');
    Route::post('projects/{project}/archive',     [ProjectController::class, 'archive'])    ->name('projects.archive');
    Route::post('projects/{project}/reopen',      [ProjectController::class, 'reopen'])     ->name('projects.reopen');
    Route::post('projects/{id}/restore',          [ProjectController::class, 'restore'])    ->name('projects.restore');
    Route::delete('projects/{id}/force-destroy',  [ProjectController::class, 'forceDestroy'])->name('projects.force-destroy');

    // ── Admin-only routes ─────────────────────────────────────────────────
    Route::middleware('admin')->group(function () {

        // RAMS settings
        Route::get('/rams/settings',  [RamsController::class, 'settings'])
            ->name('rams.settings');
        Route::post('/rams/settings', [RamsController::class, 'saveSettings'])
            ->name('rams.settings.save');
        Route::post('/rams/settings/test-connection', [RamsController::class, 'testConnection'])
            ->name('rams.settings.test');

        // User management
        Route::get('/admin/users',                          [UserController::class, 'index'])        ->name('admin.users.index');
        Route::get('/admin/users/create',                   [UserController::class, 'create'])       ->name('admin.users.create');
        Route::post('/admin/users',                         [UserController::class, 'store'])        ->name('admin.users.store');
        Route::get('/admin/users/{user}/edit',              [UserController::class, 'edit'])         ->name('admin.users.edit');
        Route::put('/admin/users/{user}',                   [UserController::class, 'update'])       ->name('admin.users.update');
        Route::post('/admin/users/{user}/toggle-active',    [UserController::class, 'toggleActive']) ->name('admin.users.toggle-active');
        Route::delete('/admin/users/{user}',                [UserController::class, 'destroy'])      ->name('admin.users.destroy');

        // AI usage dashboard
        Route::get('/admin/ai-usage', [AIUsageController::class, 'index'])->name('admin.ai-usage.index');

        // ── Solution Types ────────────────────────────────────────────────────
        Route::resource('admin/solution-types', SolutionTypeController::class)
             ->names('admin.solution-types')
             ->parameters(['solution-types' => 'solutionType']);

        // ── Worker Monitor (admin only) ───────────────────────────────────────
        Route::prefix('admin')->group(function () {
            Route::get ('worker',          [\App\Http\Controllers\WorkerMonitorController::class, 'index'])  ->name('admin.worker.index');
            Route::post('worker/start',    [\App\Http\Controllers\WorkerMonitorController::class, 'start'])  ->name('admin.worker.start');
            Route::post('worker/stop',     [\App\Http\Controllers\WorkerMonitorController::class, 'stop'])   ->name('admin.worker.stop');
            Route::post('worker/restart',  [\App\Http\Controllers\WorkerMonitorController::class, 'restart'])->name('admin.worker.restart');
        });
    });

    // ── Quote upload (before resource to prevent {rams} capturing "upload") ─
    Route::get('/rams/upload',  [QuoteUploadController::class, 'create'])->name('rams.upload.create');
    Route::post('/rams/upload', [QuoteUploadController::class, 'store'])->name('rams.upload.store')->middleware('throttle:10,1');

    // ── Post-upload processing page + JSON status poll ────────────────────
    // These must be registered before the RAMS resource so {rams} in the path
    // does not conflict with the "processing" and "check-ready" literal segments.
    Route::get('rams/{rams}/processing',  [QuoteUploadController::class, 'processing']) ->name('rams.processing');
    Route::get('rams/{rams}/check-ready', [QuoteUploadController::class, 'checkReady'])->name('rams.check-ready');

    // ── Core RAMS resource ────────────────────────────────────────────────
    Route::resource('rams', RamsController::class)
        ->only(['index', 'create', 'store', 'destroy']);

    // ── Existing RAMS actions ─────────────────────────────────────────────
    Route::get('rams/{rams}/review',               [RamsController::class, 'review'])            ->name('rams.review');
    Route::post('rams/{rams}/update-and-download', [RamsController::class, 'updateAndDownload'])->name('rams.update-and-download');
    Route::get('rams/{rams}/download',             [RamsController::class, 'download'])         ->name('rams.download');
    Route::get('rams/{rams}/download-pdf',         [RamsController::class, 'downloadPdf'])      ->name('rams.download-pdf');
    Route::post('rams/{rams}/email',               [RamsController::class, 'email'])            ->name('rams.email');
    Route::post('rams/{rams}/status',              [RamsController::class, 'updateStatus'])     ->name('rams.status');
    Route::post('rams/{rams}/regenerate',          [RamsController::class, 'regenerate'])       ->name('rams.regenerate');
    Route::delete('rams/{rams}/destroy',           [RamsController::class, 'destroy'])          ->name('rams.destroy');

    // ── Retry / recovery actions ──────────────────────────────────────────
    Route::post('rams/{rams}/retry-extraction', [RamsController::class, 'retryExtraction'])->name('rams.retry-extraction');
    Route::post('rams/{rams}/retry-generation', [RamsController::class, 'retryGeneration'])->name('rams.retry-generation');
    Route::post('rams/from-project/{project}',  [RamsController::class, 'generateFromProject'])->name('rams.from-project');

    // ── Restore / permanent delete (admin only) ───────────────────────────
    Route::post('rams/{id}/restore',         [RamsController::class, 'restore'])        ->name('rams.restore');
    Route::delete('rams/{id}/force-destroy', [RamsController::class, 'forceDestroy'])   ->name('rams.force-destroy');

    // ── Pre-generation review workflow ────────────────────────────────────
    // GET  — display the review/edit form (extracted or reviewed data)
    // POST — save edits to reviewed_data (without generating)
    // POST — validate and approve (sets status=approved; generation triggered separately)
    Route::get('rams/{rams}/quote-review',         [RamsReviewController::class, 'show'])       ->name('rams.quote-review.show');
    Route::post('rams/{rams}/quote-review',        [RamsReviewController::class, 'update'])     ->name('rams.quote-review.update');
    Route::post('rams/{rams}/room-overviews/summarize', [RamsReviewController::class, 'summarize'])
        ->name('rams.room-overviews.summarize');
    Route::post('rams/{rams}/approve',             [RamsReviewController::class, 'approve'])    ->name('rams.approve');

    // ── Hazard Template Library ───────────────────────────────────────────
    Route::get('/hazard-templates/api',                     [HazardTemplateController::class, 'apiIndex']) ->name('hazard-templates.api');
    Route::get('/hazard-templates',                         [HazardTemplateController::class, 'index'])    ->name('hazard-templates.index');
    Route::post('/hazard-templates',                        [HazardTemplateController::class, 'store'])    ->name('hazard-templates.store');
    Route::get('/hazard-templates/{hazardTemplate}/edit',   [HazardTemplateController::class, 'edit'])    ->name('hazard-templates.edit');
    Route::put('/hazard-templates/{hazardTemplate}',        [HazardTemplateController::class, 'update'])  ->name('hazard-templates.update');
    Route::delete('/hazard-templates/{hazardTemplate}',     [HazardTemplateController::class, 'destroy']) ->name('hazard-templates.destroy');

    // ── Cable Schedules ───────────────────────────────────────────────────
    // Literal-segment routes MUST be before Route::resource('cable-schedules', ...)
    // so that the {cableSchedule} wildcard does not swallow literal segments.
    Route::post('cable-schedules/generate-from-project/{project}', [CableScheduleController::class, 'generateFromProject'])->name('cable-schedules.generate-from-project');
    Route::get('cable-schedules/{cableSchedule}/status',           [CableScheduleController::class, 'status'])             ->name('cable-schedules.status');
    Route::get('cable-schedules/{cableSchedule}/download',             [CableScheduleController::class, 'download'])           ->name('cable-schedules.download');
    Route::post('cable-schedules/{cableSchedule}/retry-generation',   [CableScheduleController::class, 'retryGeneration'])   ->name('cable-schedules.retry-generation');

    Route::resource('cable-schedules', CableScheduleController::class)
        ->only(['index', 'create', 'store', 'destroy']);
    Route::get('cable-schedules/{cableSchedule}/edit',      [CableScheduleController::class, 'edit'])        ->name('cable-schedules.edit');
    Route::put('cable-schedules/{cableSchedule}',           [CableScheduleController::class, 'update'])      ->name('cable-schedules.update');
    Route::post('cable-schedules/{id}/restore',             [CableScheduleController::class, 'restore'])     ->name('cable-schedules.restore');
    Route::delete('cable-schedules/{id}/force-destroy',     [CableScheduleController::class, 'forceDestroy'])->name('cable-schedules.force-destroy');

    // ── Site Surveys ──────────────────────────────────────────────────────
    // Literal-segment routes must be before the resource so they are not
    // captured by {siteSurvey} route model binding.
    Route::get('site-surveys/from-project/{project}',          [SiteSurveyController::class, 'createFromProject'])    ->name('site-surveys.from-project');
    Route::post('site-surveys/supersede-from-project/{project}', [SiteSurveyController::class, 'supersedeFromProject']) ->name('site-surveys.supersede-from-project');
    Route::get('site-surveys/project-data/{project}', [SiteSurveyController::class, 'projectData'])      ->name('site-surveys.project-data');
    Route::resource('site-surveys', SiteSurveyController::class)
        ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    Route::post('site-surveys/{siteSurvey}/complete',                           [SiteSurveyController::class, 'complete'])          ->name('site-surveys.complete');
    Route::post('site-surveys/{siteSurvey}/rooms/{room}/questions/{question}',  [SiteSurveyController::class, 'answerQuestion'])     ->name('site-survey.question.answer');
    Route::post('site-surveys/{id}/restore',                                    [SiteSurveyController::class, 'restore'])            ->name('site-surveys.restore');
    Route::delete('site-surveys/{id}/force-destroy',                            [SiteSurveyController::class, 'forceDestroy'])       ->name('site-surveys.force-destroy');
    Route::post('site-surveys/{siteSurvey}/rooms/{room}/photos',                [SiteSurveyController::class, 'uploadPhoto'])    ->name('site-surveys.photos.upload')->middleware('throttle:30,1');
    Route::delete('site-surveys/photos/{photo}',                                [SiteSurveyController::class, 'deletePhoto'])    ->name('site-surveys.photos.delete');
    Route::get('site-surveys/photos/{photo}',                                   [SiteSurveyController::class, 'servePhoto'])     ->name('site-surveys.photos.serve');
    Route::get('site-surveys/{siteSurvey}/pdf',                                 [SiteSurveyController::class, 'downloadPdf'])    ->name('site-surveys.pdf');
    Route::get('site-surveys/blank-form',                                       [SiteSurveyController::class, 'downloadBlankForm'])->name('site-surveys.blank-form');

    // ── O&M Manuals ───────────────────────────────────────────────────────
    // Status polling route MUST be before the resource wildcard to prevent {omManual} swallowing 'status'
    Route::get('om-manuals/{omManual}/status', [\App\Http\Controllers\OmManualController::class, 'status'])->name('om-manuals.status');
    Route::resource('om-manuals', OmManualController::class)
        ->only(['index', 'create', 'store', 'destroy']);
    Route::post('om-manuals/from-project/{project}', [OmManualController::class, 'storeFromProject'])
        ->name('om-manuals.from-project');
    Route::post('om-manuals/generate-from-project/{project}', [OmManualController::class, 'generateFromProject'])
        ->name('om-manuals.generate-from-project');
    Route::get('om-manuals/{omManual}/edit',         [OmManualController::class, 'edit'])        ->name('om-manuals.edit');
    Route::put('om-manuals/{omManual}',              [OmManualController::class, 'update'])      ->name('om-manuals.update');
    Route::post('om-manuals/{omManual}/generate',        [OmManualController::class, 'generate'])        ->name('om-manuals.generate');
    Route::post('om-manuals/{omManual}/retry-generation',[OmManualController::class, 'retryGeneration']) ->name('om-manuals.retry-generation');
    Route::get('om-manuals/{omManual}/download',         [OmManualController::class, 'download'])        ->name('om-manuals.download');
    Route::get('om-manuals/{omManual}/download-pdf',     [OmManualController::class, 'downloadPdf'])     ->name('om-manuals.download-pdf');
    Route::post('om-manuals/{id}/restore',           [OmManualController::class, 'restore'])     ->name('om-manuals.restore');
    Route::delete('om-manuals/{id}/force-destroy',   [OmManualController::class, 'forceDestroy'])->name('om-manuals.force-destroy');

    // ── Worksheets ────────────────────────────────────────────────────────
    // The generate-from-project literal-segment route MUST be registered BEFORE
    // any {worksheet} wildcard routes to prevent route model binding conflicts.
    Route::post('worksheets/generate-from-project/{project}', [\App\Http\Controllers\WorksheetController::class, 'generateFromProject'])->name('worksheets.generate-from-project');
    Route::get('worksheets/{worksheet}/status',       [\App\Http\Controllers\WorksheetController::class, 'status'])          ->name('worksheets.status');
    Route::get('worksheets/{worksheet}/download',     [\App\Http\Controllers\WorksheetController::class, 'download'])        ->name('worksheets.download');
    Route::post('worksheets/{worksheet}/retry-generation', [\App\Http\Controllers\WorksheetController::class, 'retryGeneration'])->name('worksheets.retry-generation');
    Route::delete('worksheets/{worksheet}',           [\App\Http\Controllers\WorksheetController::class, 'destroy'])         ->name('worksheets.destroy');
    Route::get('worksheets/{worksheet}',          [\App\Http\Controllers\WorksheetController::class, 'show'])    ->name('worksheets.show');
    Route::get('worksheets',                      [\App\Http\Controllers\WorksheetController::class, 'index'])   ->name('worksheets.index');

    // ── Install Programmes ────────────────────────────────────────────────────
    Route::post('projects/{project}/install-programme/generate',
        [\App\Http\Controllers\InstallProgrammeController::class, 'generate'])
        ->name('install-programmes.generate');

    Route::get('install-programmes/{programme}/review',
        [\App\Http\Controllers\InstallProgrammeController::class, 'review'])
        ->name('install-programmes.review');

    Route::post('install-programmes/{programme}/activate',
        [\App\Http\Controllers\InstallProgrammeController::class, 'activate'])
        ->name('install-programmes.activate');

    Route::delete('install-tasks/{task}',
        [\App\Http\Controllers\InstallProgrammeController::class, 'destroyTask'])
        ->name('install-tasks.destroy');

    Route::post('install-tasks/{task}/assign',
        [\App\Http\Controllers\TaskAssignmentController::class, 'assign'])
        ->name('install-tasks.assign');

    Route::post('install-programmes/{programme}/assign-room',
        [\App\Http\Controllers\TaskAssignmentController::class, 'assignRoom'])
        ->name('install-programmes.assign-room');

    Route::post('install-programmes/{programme}/assign-all',
        [\App\Http\Controllers\TaskAssignmentController::class, 'assignAll'])
        ->name('install-programmes.assign-all');

    Route::get('install-programmes/{programme}/schedule',
        [\App\Http\Controllers\InstallProgrammeController::class, 'schedule'])
        ->name('install-programmes.schedule');

    // ── Phase 14 — Mobile Field View ──────────────────────────────────────
    Route::get('projects/{project}/programme',
        [\App\Http\Controllers\InstallProgrammeController::class, 'field'])
        ->name('install-programmes.field');

    Route::patch('install-tasks/{task}/status',
        [\App\Http\Controllers\TaskStatusController::class, 'update'])
        ->name('install-tasks.status');

    Route::patch('install-tasks/{task}/notes',
        [\App\Http\Controllers\TaskStatusController::class, 'updateNotes'])
        ->name('install-tasks.notes');

    Route::post('install-tasks/{task}/photos',
        [\App\Http\Controllers\TaskPhotoController::class, 'store'])
        ->name('install-task-photos.store')
        ->middleware('throttle:60,1');

    Route::patch('install-task-photos/{photo}',
        [\App\Http\Controllers\TaskPhotoController::class, 'update'])
        ->name('install-task-photos.update');

    Route::delete('install-task-photos/{photo}',
        [\App\Http\Controllers\TaskPhotoController::class, 'destroy'])
        ->name('install-task-photos.destroy');

    Route::get('install-task-photos/{photo}',
        [\App\Http\Controllers\TaskPhotoController::class, 'show'])
        ->name('install-task-photos.show');

    Route::post('projects/{project}/time-entries/start',
        [\App\Http\Controllers\TimeEntryController::class, 'start'])
        ->name('time-entries.start')
        ->middleware('throttle:30,1');

    Route::post('projects/{project}/time-entries/stop',
        [\App\Http\Controllers\TimeEntryController::class, 'stop'])
        ->name('time-entries.stop')
        ->middleware('throttle:30,1');

    Route::post('time-entries/{entry}/heartbeat',
        [\App\Http\Controllers\TimeEntryController::class, 'heartbeat'])
        ->name('time-entries.heartbeat')
        ->middleware('throttle:10,1');

    Route::patch('time-entries/{entry}',
        [\App\Http\Controllers\TimeEntryController::class, 'update'])
        ->name('time-entries.update')
        ->middleware('throttle:20,1');

    // ─── Document Edit Core (chat-driven data-only edits) ───────────────────
    //
    // All endpoints sit inside the existing `auth` middleware group above.
    // Chat-driven operations are safety-validated at DocumentChangeSetValidator
    // so they can never modify app code / routes / config / migrations.
    Route::post(  'documents/{type}/{id}/threads',
        [\App\Http\Controllers\DocumentEditController::class, 'createThread'])
        ->name('documents.threads.create');
    Route::post(  'documents/{type}/{id}/threads/{thread}/messages',
        [\App\Http\Controllers\DocumentEditController::class, 'postMessage'])
        ->name('documents.threads.messages.create');
    Route::post(  'documents/{type}/{id}/threads/{thread}/parse',
        [\App\Http\Controllers\DocumentEditController::class, 'parseMessage'])
        ->name('documents.threads.parse');
    Route::get(   'documents/{type}/{id}/changes/{changeSet}',
        [\App\Http\Controllers\DocumentEditController::class, 'showChangeSet'])
        ->name('documents.changes.show');
    Route::post(  'documents/{type}/{id}/changes/{changeSet}/apply',
        [\App\Http\Controllers\DocumentEditController::class, 'applyChangeSet'])
        ->name('documents.changes.apply');
    Route::get(   'documents/{type}/{id}/revisions',
        [\App\Http\Controllers\DocumentEditController::class, 'listRevisions'])
        ->name('documents.revisions.index');
    Route::get(   'documents/{type}/{id}/revisions-view',
        [\App\Http\Controllers\DocumentEditController::class, 'revisionsView'])
        ->name('documents.revisions.view');
});

require __DIR__.'/auth.php';
