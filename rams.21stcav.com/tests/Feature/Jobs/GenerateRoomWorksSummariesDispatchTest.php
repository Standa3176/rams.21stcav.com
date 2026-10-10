<?php

namespace Tests\Feature\Jobs;

use App\Core\AI\Contracts\AIProviderContract;
use App\Core\AI\Prompts\BasePrompt;
use App\Core\AI\Providers\ClaudeProvider;
use App\Core\Modules\QuoteImport\QuoteImportService;
use App\Jobs\GenerateRoomWorksSummariesJob;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\AICacheService;
use App\Services\QuoteExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 46.9 Plan 05 — the import-time run of GenerateRoomWorksSummariesJob.
 *
 * ⚠ REWORKED, NOT REPLACED. Plan 46.9-02 wrote this file to prove a REAL QUEUE
 * PUSH happened after the import transaction committed. On 2026-10-10 the user
 * overturned D-02 and chose to run the generation INLINE at import
 * (D-12/D-15), so there is no queue push left to assert. Two of Wave 2's three
 * tests therefore asserted the wrong thing. They are reworked here — the
 * PROBE TECHNIQUE they introduced is kept, because that was the genuinely
 * non-vacuous part; only the thing it is hooked to has moved, from a custom
 * queue driver's push() to the AI provider's execute(), which is where the work
 * now actually happens.
 *
 * What this file proves, and why each one is here:
 *
 *  1. An import populates works_summary BEFORE importFromData() returns — no
 *     worker, no Queue::fake() drain, no second step. The positive replacement
 *     for Wave 2's "exactly one job pushed".
 *
 *  2. NOTHING is deferred onto a queue connection. Asserted against a REAL
 *     recording queue driver installed as queue.default, which a reversion to
 *     ::dispatch() would hit. See the framework note on
 *     test_queue_fake_sees_an_explicitly_sync_dispatch_and_nothing_deferred()
 *     for why Queue::fake()'s own assertNothingPushed() cannot express this.
 *
 *  3. The generation runs AFTER the transaction has committed. The provider
 *     double records DB::transactionLevel() and the latest readable
 *     project_packages id at AI-call time. Under RefreshDatabase the level is 1
 *     outside importFromData()'s DB::transaction and 2 inside it, so moving the
 *     call into the closure turns this RED — which was done deliberately, shown
 *     RED, and restored (both outputs are quoted in 46.9-05-SUMMARY.md).
 *     The reason the call must stay outside is now STRONGER than Wave 2's: an
 *     inline AI call inside the transaction would hold a database transaction
 *     open across an outbound HTTP request of up to 180 seconds.
 *
 *  4. A generation failure never fails the import. The throw comes from the
 *     REAL AI CALL (the provider double's execute()), not from dispatch
 *     plumbing — a test made green by failing the plumbing asserts the safe
 *     half of the condition, which is the exact 2026-09-30 failure mode. The
 *     probe's call counter is asserted so the test is worthless-proof.
 *
 *  5. completePendingReimport() generates too (D-15) — the live re-import path
 *     Wave 2 deliberately left uncovered.
 *
 * NOT asserted, stated plainly rather than faked: that the inline call carries
 * the provider's 180s default timeout. AIProviderContract::execute(BasePrompt)
 * takes NO $options argument, and the timeout is applied inside
 * ClaudeProvider::dispatch() (:177) from self::DEFAULT_TIMEOUT (:20). There is
 * no seam at which a bound double can observe it without editing the provider,
 * which this plan forbids. The 180s bound is real and is documented at the call
 * site; it is simply not observable here, and a constant-equality assertion
 * would prove nothing about the invocation.
 *
 * The AICacheService stub in setUp() stays: a stored 30-day response would
 * otherwise be served straight past every provider double in this file and
 * produce a fake green.
 */
class GenerateRoomWorksSummariesDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seam: RoomOverviewSummaryFallbackTest:33-35.
        $cache = $this->createMock(AICacheService::class);
        $cache->method('hash')->willReturn('test-hash-46-9-05');
        $cache->method('get')->willReturn(null);
        $this->app->bind(AICacheService::class, fn () => $cache);

        RoomWorksProbeQueue::reset();
        RoomWorksAiProbeProvider::reset();
    }

    protected function tearDown(): void
    {
        RoomWorksProbeQueue::reset();
        RoomWorksAiProbeProvider::reset();

        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private const BULLETS = "- Mount the Jabra PanaCast 50 above the display\n"
        . "- Terminate the panel control run";

    /** The import payload a real QuoteWerks SQL import produces. */
    private function importPayload(): array
    {
        return [
            'client_name'    => 'Oregano Ltd',
            'site_address'   => '7 Dispatch Way',
            'ref'            => 'OREG-46-9',
            'extracted_data' => [
                'room_overviews' => [
                    [
                        'room'     => 'Boardroom',
                        'overview' => 'Oregano is now using the Crestron small room system with a '
                            . 'Jabra PanaCast 50 video bar and full room control from a single panel.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Bind the recording provider double over ClaudeProvider.
     *
     * GenerateSurveyQuestionsJobTest's Mockery::mock(AIManager::class) trick
     * does not work here — RoomOverviewSummaryService:59 calls AIManager::run()
     * STATICALLY. The only seam is a bound AIProviderContract
     * (RoomOverviewSummaryFallbackTest:44-52). A hand-written double rather
     * than a PHPUnit mock, because it has to RECORD live DB state at call time.
     */
    private function bindAiProbe(bool $throw = false, ?array $payload = null): void
    {
        RoomWorksAiProbeProvider::reset();
        RoomWorksAiProbeProvider::$throw   = $throw;
        RoomWorksAiProbeProvider::$payload = $payload ?? [
            'summaries' => [
                ['room' => 'Boardroom', 'summary' => self::BULLETS],
            ],
        ];

        $this->app->bind(ClaudeProvider::class, fn () => new RoomWorksAiProbeProvider());
    }

    /**
     * Install a REAL queue connection that records every push and never runs
     * the job, as queue.default. dispatchSync() routes to the 'sync'
     * connection explicitly (Bus\Dispatcher::dispatchSync:93-102), so nothing
     * should ever reach this driver. A reversion to ::dispatch() would.
     */
    private function useProbeQueue(): void
    {
        RoomWorksProbeQueue::reset();

        Queue::extend('gsd-room-works-probe', fn () => new RoomWorksProbeQueueConnector());

        config([
            'queue.connections.gsd-room-works-probe' => ['driver' => 'gsd-room-works-probe'],
            'queue.default'                          => 'gsd-room-works-probe',
        ]);
    }

    /** Read the single room row back off a fresh DB read. */
    private function freshRoom(ProjectPackage $package): array
    {
        $rooms = (array) (ProjectPackage::find($package->id)->extracted_data['room_overviews'] ?? []);

        $this->assertCount(1, $rooms, 'the fixture has exactly one room row');

        return (array) $rooms[0];
    }

    // ── 1 (AIB-01): the bullets exist before importFromData() returns ────────

    /**
     * The positive replacement for Wave 2's "exactly one job pushed" test. No
     * Queue::fake(), no drain, no worker: by the time importFromData() hands
     * back, the row on disk already carries the bullets.
     */
    public function test_an_import_generates_room_bullets_inline_before_it_returns(): void
    {
        $this->bindAiProbe();

        $user = User::factory()->create();

        $package = app(QuoteImportService::class)->importFromData($user, $this->importPayload());

        $this->assertInstanceOf(ProjectPackage::class, $package);

        $room = $this->freshRoom($package);

        $this->assertSame(self::BULLETS, $room['works_summary'] ?? null,
            'works_summary must already be persisted when importFromData() returns — this is the '
            . 'whole point of the inline decision (D-12). A null here means the generation is '
            . 'deferred again.');

        $this->assertCount(1, RoomWorksAiProbeProvider::$calls,
            'exactly ONE AI call for the whole package — not one per room (C-02), and not two');

        // D-05: the imported original is kept byte-identical.
        $this->assertSame($this->importPayload()['extracted_data']['room_overviews'][0]['overview'],
            $room['overview'] ?? null,
            'the imported sales prose must survive untouched (D-05)');
    }

    // ── 2: nothing is deferred onto a queue connection ───────────────────────

    /**
     * Asserted against a real recording driver rather than Queue::fake(),
     * because a faked queue cannot distinguish "ran inline" from "was
     * recorded and never ran" — see the next test for that framework fact.
     *
     * Both halves matter: zero pushes AND the bullets present. Zero pushes on
     * its own would pass if the call had been deleted outright.
     */
    public function test_no_job_is_ever_deferred_onto_a_queue_connection(): void
    {
        $this->useProbeQueue();
        $this->bindAiProbe();

        $user = User::factory()->create();

        $package = app(QuoteImportService::class)->importFromData($user, $this->importPayload());

        $this->assertSame([], RoomWorksProbeQueue::$pushes,
            'nothing may reach a deferred queue connection — a push recorded here means the '
            . 'inline dispatchSync() has been reverted to a queued ::dispatch(), which silently '
            . 'changes WHEN the bullets appear (D-12)');

        $this->assertSame(self::BULLETS, $this->freshRoom($package)['works_summary'] ?? null,
            'and the work must have happened anyway, inline — otherwise the zero-pushes '
            . 'assertion above is satisfied by the call simply being gone');
    }

    /**
     * ⚠ FRAMEWORK FACT, measured against the installed Laravel, not assumed.
     *
     * Queue::fake() + assertNothingPushed() CANNOT be used to express "no queue
     * push" for a dispatchSync() call. Illuminate\Bus\Dispatcher::dispatchSync()
     * (:93-102) does not call dispatchNow() for a ShouldQueue job — it runs
     * `return $this->dispatchToQueue($command->onConnection('sync'))`. That goes
     * through the queue manager, and QueueFake::push() (:478-486) RECORDS it
     * instead of letting SyncQueue run it. So under Queue::fake():
     *   - assertNothingPushed() FAILS, on a correct implementation; and
     *   - the job never actually runs, so no bullets appear either.
     * (Written as assertNothingPushed() first and shown failing — the output is
     * quoted in 46.9-05-SUMMARY.md.)
     *
     * What IS expressible, and is a genuine reversion tripwire: the recorded
     * dispatch must be explicitly targeted at the 'sync' connection. A queued
     * ::dispatch() leaves $job->connection as null, so this goes RED.
     */
    public function test_queue_fake_sees_an_explicitly_sync_dispatch_and_nothing_deferred(): void
    {
        Queue::fake();
        $this->bindAiProbe();

        $user = User::factory()->create();

        app(QuoteImportService::class)->importFromData($user, $this->importPayload());

        Queue::assertPushed(GenerateRoomWorksSummariesJob::class, 1);

        Queue::assertPushed(
            GenerateRoomWorksSummariesJob::class,
            fn (GenerateRoomWorksSummariesJob $job) => $job->connection === 'sync',
        );
    }

    // ── 3 (D-14 / T-46.9-07): the AI call happens after the commit ───────────

    /**
     * Wave 2's probe, rehomed. It used to record DB::transactionLevel() inside
     * a custom queue driver's push(); the push is gone, so it now records the
     * same thing inside the provider double's execute() — the moment the AI
     * call is actually made.
     *
     * Baseline: RefreshDatabase holds one outer transaction, so the level is 1
     * here and 2 inside importFromData()'s DB::transaction closure. Moving the
     * call into the closure turns this RED on the level assertion — proven, not
     * claimed.
     */
    public function test_the_generation_runs_after_the_import_transaction_has_committed(): void
    {
        $this->bindAiProbe();

        $baselineLevel = DB::transactionLevel();
        $this->assertSame(1, $baselineLevel,
            'RefreshDatabase is expected to hold exactly one outer transaction — if this '
            . 'changes, the discriminator below needs rewriting, not deleting');

        $user = User::factory()->create();

        $package = app(QuoteImportService::class)->importFromData($user, $this->importPayload());

        $this->assertCount(1, RoomWorksAiProbeProvider::$calls,
            'the probe must have been reached at AI-call time — if it was not, everything below '
            . 'is vacuous');

        $call = RoomWorksAiProbeProvider::$calls[0];

        $this->assertSame($baselineLevel, $call['transaction_level'],
            'the AI call must happen AFTER DB::transaction() has returned. A level above the '
            . 'RefreshDatabase baseline means an outbound HTTP request of up to 180 seconds is '
            . 'being made with a database transaction held open (D-14)');

        $this->assertSame($package->id, $call['latest_package_id'],
            'the project_packages row must already be committed and readable at the moment the '
            . 'AI call is made');
    }

    // ── 4 (AIB-04 / T-46.9-06): a generation failure never fails an import ───

    /**
     * The failure is injected at the REAL AI CALL. Not at a dispatch, not at a
     * registration, not at the service boundary — the provider double throws
     * from inside execute(), which is the only place a live provider outage
     * actually surfaces. The call-counter assertion is what stops this test
     * from being made green by a failure injected somewhere safe.
     */
    public function test_a_generation_failure_does_not_fail_the_import(): void
    {
        $this->bindAiProbe(throw: true);

        $user = User::factory()->create();

        $package = app(QuoteImportService::class)->importFromData($user, $this->importPayload());

        $this->assertNotEmpty(RoomWorksAiProbeProvider::$calls,
            'the throw must have come from the real AI call — if the provider was never reached, '
            . 'the failure was injected somewhere other than the AI call and this test is '
            . 'worthless (the 2026-09-30 failure mode)');

        $this->assertInstanceOf(ProjectPackage::class, $package,
            'the import must still return its package when the AI is down (AIB-04 / D-04)');

        $this->assertDatabaseHas('project_packages', ['id' => $package->id]);

        $room = $this->freshRoom($package);

        $this->assertNotSame('', $room['works_summary'] ?? null,
            'an EMPTY STRING must never be persisted — it would overwrite valid bullets on the '
            . 'next regeneration (the WorksheetGeneratorService:160-176 refusal)');

        $this->assertTrue((bool) ($room['_summary_fallback'] ?? false),
            'the fallback marker is what downstream renderers badge; the job persists the marker '
            . 'and nothing else when the AI is unavailable');

        $this->assertSame($this->importPayload()['extracted_data']['room_overviews'][0]['overview'],
            $room['overview'] ?? null,
            'and the imported prose is still there for the derived-bullet fallback to use (D-04)');
    }

    // ── 5 (D-15): the re-import path generates too ───────────────────────────

    /**
     * completePendingReimport() is reached from ReimportQuoteJob:86 and is the
     * one genuinely live import path Plan 46.9-02 left uncovered. The user said
     * "wire it too" on 2026-10-10.
     *
     * QuoteExtractorService is doubled so this test makes no PDF-vision call;
     * the only AI seam exercised is the room-summary one.
     */
    public function test_a_reimport_generates_room_bullets_inline_too(): void
    {
        $this->bindAiProbe();

        $extractor = $this->createMock(QuoteExtractorService::class);
        $extractor->method('extractFromPath')->willReturn([
            'client_name'    => 'Oregano Ltd',
            'site_address'   => '7 Dispatch Way',
            'room_overviews' => $this->importPayload()['extracted_data']['room_overviews'],
        ]);
        $this->app->bind(QuoteExtractorService::class, fn () => $extractor);

        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $pending = ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'quote_filename' => 'reimport.pdf',
            'quote_path'     => 'quote-imports/reimport.pdf',
            'extracted_data' => [],
            'revision'       => 2,
            'status'         => ProjectPackage::STATUS_EXTRACTING,
        ]);

        $package = app(QuoteImportService::class)->completePendingReimport($pending, $user);

        $this->assertInstanceOf(ProjectPackage::class, $package);

        $this->assertSame(self::BULLETS, $this->freshRoom($package)['works_summary'] ?? null,
            'the re-import path must generate the bullets inline as well (D-15) — this is the '
            . 'three-line gap the user asked to close');

        $this->assertCount(1, RoomWorksAiProbeProvider::$calls,
            'one AI call for the re-imported package, not one per room');
    }

    // ── The ProjectAutoAdvanceTest guard (T-46.9-09) — kept from Wave 2 ──────

    /**
     * tests/Feature/ProjectAutoAdvanceTest.php:56,96 call importFromData()
     * directly and pass NO extracted_data. With the generation now fully
     * INLINE, that suite would make a real network call on every import if the
     * job's step-2 early return did not fire first.
     *
     * This test asserts the REASON it stays green instead of leaving it to
     * luck: with no room_overviews there is nothing to summarise, so a provider
     * bound with expects($this->never()) is never asked.
     */
    public function test_an_import_with_no_extracted_data_never_asks_the_ai(): void
    {
        $this->bindProviderNeverCalled();

        $user = User::factory()->create();

        // The exact payload shape used at ProjectAutoAdvanceTest:51-57.
        $package = app(QuoteImportService::class)->importFromData($user, [
            'client_name'  => 'Acme Ltd',
            'site_address' => '123 Main St',
            'ref'          => 'ABC123',
        ]);

        $this->assertInstanceOf(ProjectPackage::class, $package);
        $this->assertSame([], (array) (ProjectPackage::find($package->id)->extracted_data['room_overviews'] ?? []),
            'no room rows means nothing for the job to do and nothing to ask the AI about');
    }

    /**
     * The control half, and it is what makes the test above non-vacuous: with
     * the SAME inline call, a room that does carry prose really does reach the
     * provider and really does get its bullets persisted. So the never-called
     * assertion above is the early return doing its job, not a call that
     * silently never happened.
     */
    public function test_the_same_inline_path_does_reach_the_ai_when_a_room_carries_prose(): void
    {
        $this->bindAiProbe();

        $user = User::factory()->create();

        $package = app(QuoteImportService::class)->importFromData($user, $this->importPayload());

        $this->assertNotEmpty(RoomWorksAiProbeProvider::$calls,
            'the control must reach the provider — otherwise the never-called guard above proves '
            . 'nothing');

        $this->assertSame(self::BULLETS, $this->freshRoom($package)['works_summary'] ?? null);
    }

    // ── Provider seam ────────────────────────────────────────────────────────

    /** A provider that asserts it is never asked anything at all. */
    private function bindProviderNeverCalled(): void
    {
        $mock = $this->createMock(AIProviderContract::class);
        $mock->expects($this->never())->method('execute');
        $mock->expects($this->never())->method('completeJson');
        $mock->method('getProviderKey')->willReturn('claude');
        $this->app->bind(ClaudeProvider::class, fn () => $mock);
    }
}

/**
 * The AI-call probe — Wave 2's technique, rehomed from the queue driver to the
 * place the work now happens.
 *
 * Records, at the exact moment the AI call is made: DB::transactionLevel() and
 * the highest readable project_packages id. Optionally throws from inside
 * execute(), so an injected failure is a REAL provider failure rather than
 * dispatch plumbing.
 */
final class RoomWorksAiProbeProvider implements AIProviderContract
{
    /** @var array<int, array<string, mixed>> */
    public static array $calls = [];

    public static bool $throw = false;

    /** @var array<string, mixed> */
    public static array $payload = [];

    public static function reset(): void
    {
        self::$calls   = [];
        self::$throw   = false;
        self::$payload = [];
    }

    public function execute(BasePrompt $prompt): array
    {
        return $this->record();
    }

    public function completeJson(string $prompt, array $options = []): array
    {
        return $this->record();
    }

    public function completeWithPdf(string $pdfBase64, string $prompt, array $options = []): array
    {
        return $this->record();
    }

    public function getProviderKey(): string
    {
        return 'claude';
    }

    private function record(): array
    {
        self::$calls[] = [
            'transaction_level' => DB::transactionLevel(),
            'latest_package_id' => ProjectPackage::query()->orderByDesc('id')->value('id'),
        ];

        if (self::$throw) {
            // A RuntimeException is what ClaudeProvider:181 actually throws on
            // an HTTP failure or a rate limit, and it is what AIManager's retry
            // loop catches before converting to AIGenerationException.
            throw new \RuntimeException('ai probe: simulated provider failure inside execute()');
        }

        return self::$payload;
    }
}

/**
 * A queue connection that records every push and never runs the job. Kept from
 * Wave 2: it is now the discriminator proving NOTHING is deferred, where it
 * used to prove exactly one push happened. Extends NullQueue so every other
 * Queue contract method is already a safe no-op.
 */
final class RoomWorksProbeQueue extends NullQueue
{
    /** @var array<int, array<string, mixed>> */
    public static array $pushes = [];

    public static function reset(): void
    {
        self::$pushes = [];
    }

    public function push($job, $data = '', $queue = null)
    {
        self::$pushes[] = [
            'job_class'         => is_object($job) ? $job::class : (string) $job,
            'package_id'        => is_object($job) && property_exists($job, 'packageId')
                ? (int) $job->packageId
                : null,
            'transaction_level' => DB::transactionLevel(),
        ];
    }
}

final class RoomWorksProbeQueueConnector implements ConnectorInterface
{
    public function connect(array $config)
    {
        return new RoomWorksProbeQueue();
    }
}
