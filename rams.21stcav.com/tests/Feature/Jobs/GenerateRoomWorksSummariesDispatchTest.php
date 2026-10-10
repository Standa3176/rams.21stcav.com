<?php

namespace Tests\Feature\Jobs;

use App\Core\Modules\QuoteImport\QuoteImportService;
use App\Jobs\GenerateRoomWorksSummariesJob;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\AICacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 46.9 Plan 02 — dispatch wiring for GenerateRoomWorksSummariesJob.
 *
 * Written RED, before QuoteImportService dispatches anything.
 *
 * Three tests, and the second and third exist specifically because a bare
 * Queue::assertPushed() cannot tell the two candidate implementations apart:
 *
 *  - Test 1 proves exactly ONE job is pushed, carrying the right package id.
 *    A second dispatch would be a second AI call per import (T-46.9-08).
 *
 *  - Test 2 proves the ORDERING: at the moment the real push happens, the
 *    project_packages row is readable AND DB::transactionLevel() is back to the
 *    RefreshDatabase baseline (1, the suite's own outer transaction) rather than
 *    2 (inside importFromData()'s DB::transaction). Queue::fake() is useless for
 *    this — QueueFake::push() merely records, so it passes identically whether
 *    the dispatch sits inside or outside the transaction closure. This test uses
 *    a real queue connection whose push() observes the live state instead
 *    (T-46.9-07).
 *
 *  - Test 3 proves AIB-04 at the boundary that matters: the REAL PUSH throws,
 *    and the import still returns its package and the row is still readable.
 *    It MUST fail at the push, not at a callback registration. This is why the
 *    production code must NOT use ->afterCommit(): with afterCommit() the push
 *    is deferred into DatabaseTransactionRecord::executeCallbacks() (:83-88), a
 *    bare untried foreach, reached from ManagesTransactions'
 *    transactionsManager?->commit() (:66-70) which sits OUTSIDE that method's
 *    own try/catch (:51-64) — so a driver failure escapes importFromData()
 *    AFTER the package row has committed: a half-reported import (T-46.9-17).
 *
 * The probe queue (bottom of this file) never executes the job, so these three
 * tests make no AI call at all. The AICacheService stub in setUp() is belt and
 * braces against the 30-day cache faking a green for any test here that does
 * run the job inline.
 */
class GenerateRoomWorksSummariesDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seam: RoomOverviewSummaryFallbackTest:33-35. A stored 30-day response
        // would otherwise be served straight past any provider fake.
        $cache = $this->createMock(AICacheService::class);
        $cache->method('hash')->willReturn('test-hash-46-9-02');
        $cache->method('get')->willReturn(null);
        $this->app->bind(AICacheService::class, fn () => $cache);

        RoomWorksProbeQueue::reset();
    }

    protected function tearDown(): void
    {
        RoomWorksProbeQueue::reset();

        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Swap the default queue connection for a probe that records the live state
     * at push() time and never runs the job. This is the only seam that can
     * observe the inside-vs-outside-the-transaction distinction.
     */
    private function useProbeQueue(bool $throwOnPush = false): void
    {
        RoomWorksProbeQueue::reset();
        RoomWorksProbeQueue::$throwOnPush = $throwOnPush;

        Queue::extend('gsd-room-works-probe', fn () => new RoomWorksProbeQueueConnector());

        config([
            'queue.connections.gsd-room-works-probe' => ['driver' => 'gsd-room-works-probe'],
            'queue.default'                          => 'gsd-room-works-probe',
        ]);
    }

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

    // ── Test 1 (AIB-01): exactly one job, carrying the right package id ──────

    public function test_an_import_dispatches_exactly_one_job_carrying_the_new_package_id(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $package = app(QuoteImportService::class)->importFromData($user, $this->importPayload());

        $this->assertInstanceOf(ProjectPackage::class, $package);

        Queue::assertPushed(
            GenerateRoomWorksSummariesJob::class,
            fn (GenerateRoomWorksSummariesJob $job) => $job->packageId === $package->id,
        );

        // Exactly one — NOT "at least one". A second dispatch is a second AI
        // call per import (T-46.9-08).
        Queue::assertPushed(GenerateRoomWorksSummariesJob::class, 1);
    }

    // ── Test 2 (D-10 / T-46.9-07): the push happens after the commit ─────────

    /**
     * The non-vacuity half. Asserted via a real queue connection, because
     * Queue::fake() cannot distinguish the two placements.
     *
     * Baseline: under RefreshDatabase the whole test runs inside an outer
     * transaction, so DB::transactionLevel() is 1 here and 2 inside
     * importFromData()'s DB::transaction closure. Moving the dispatch back
     * inside the closure therefore turns this test RED on the level assertion.
     */
    public function test_the_push_happens_only_once_the_package_row_is_committed(): void
    {
        $this->useProbeQueue();

        $baselineLevel = DB::transactionLevel();
        $this->assertSame(1, $baselineLevel,
            'RefreshDatabase is expected to hold exactly one outer transaction — if this '
            . 'changes, the discriminator below needs rewriting, not deleting');

        $user = User::factory()->create();

        $package = app(QuoteImportService::class)->importFromData($user, $this->importPayload());

        $this->assertCount(1, RoomWorksProbeQueue::$pushes,
            'exactly one real push is expected to reach the queue driver');

        $push = RoomWorksProbeQueue::$pushes[0];

        $this->assertSame(GenerateRoomWorksSummariesJob::class, $push['job_class']);
        $this->assertSame($package->id, $push['package_id']);

        // The ordering property, both halves:
        $this->assertSame($baselineLevel, $push['transaction_level'],
            'the real push must happen AFTER DB::transaction() has returned — a transaction '
            . 'level above the RefreshDatabase baseline means the dispatch is still inside '
            . 'the import transaction and a worker could read an uncommitted row');

        $this->assertTrue($push['package_found'],
            'the project_packages row must already be readable at the moment the job is pushed');
    }

    // ── Test 3 (AIB-04 / T-46.9-06): a push failure never fails an import ────

    /**
     * The driver fails at the REAL PUSH. Not at a callback registration — a
     * registration cannot fail for this reason, and a test made green that way
     * asserts the safe half of the condition, which is the exact 2026-09-30
     * failure mode.
     */
    public function test_a_real_push_failure_does_not_fail_the_import(): void
    {
        $this->useProbeQueue(throwOnPush: true);

        $user = User::factory()->create();

        $package = app(QuoteImportService::class)->importFromData($user, $this->importPayload());

        $this->assertCount(1, RoomWorksProbeQueue::$pushes,
            'the probe must have been reached at push() time — if it was not, the failure was '
            . 'injected somewhere other than the real push and this test is worthless');

        $this->assertInstanceOf(ProjectPackage::class, $package,
            'the import must still return its package when the queue driver is down (AIB-04)');

        $this->assertDatabaseHas('project_packages', ['id' => $package->id]);

        $this->assertNotNull(ProjectPackage::find($package->id),
            'the committed package row must still be readable after a dispatch failure — a '
            . 'rolled-back import here would be the half-reported import of T-46.9-17');
    }
}

/**
 * A queue connection that records the live state at push() time and never runs
 * the job. Extends NullQueue so every other Queue contract method is already a
 * safe no-op.
 */
final class RoomWorksProbeQueue extends NullQueue
{
    /** @var array<int, array<string, mixed>> */
    public static array $pushes = [];

    public static bool $throwOnPush = false;

    public static function reset(): void
    {
        self::$pushes      = [];
        self::$throwOnPush = false;
    }

    public function push($job, $data = '', $queue = null)
    {
        $packageId = is_object($job) && property_exists($job, 'packageId')
            ? (int) $job->packageId
            : null;

        self::$pushes[] = [
            'job_class'         => is_object($job) ? $job::class : (string) $job,
            'package_id'        => $packageId,
            'transaction_level' => DB::transactionLevel(),
            'package_found'     => $packageId !== null && ProjectPackage::find($packageId) !== null,
        ];

        if (self::$throwOnPush) {
            throw new \RuntimeException('probe queue: simulated driver failure inside push()');
        }
    }
}

final class RoomWorksProbeQueueConnector implements ConnectorInterface
{
    public function connect(array $config)
    {
        return new RoomWorksProbeQueue();
    }
}
