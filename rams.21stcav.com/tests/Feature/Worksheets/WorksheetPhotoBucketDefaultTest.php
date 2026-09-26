<?php

namespace Tests\Feature\Worksheets;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 46.4 Plan 01 Task 1 — the photo bucket (D-03), and the written ruling
 * on what happens to history.
 *
 * THE RULING THIS FILE HOLDS: every photo row that existed before the bucket
 * migration reads `completion` afterwards, and it reads `completion` because a
 * statement in the migration's `up()` PUT IT THERE — not because a column
 * default quietly filled it in. The distinction matters: a default is
 * invisible in a schema diff and nobody ever decided it; an explicit UPDATE is
 * a decision somebody has to read.
 *
 * The relabel is correct because the tray these photos were captured through
 * is titled, verbatim, `📷 Photos of completed work`
 * (`resources/views/worksheets/public-show.blade.php:938`). Calling them
 * `completion` restates the label the engineer was already reading.
 *
 * ⚠️ A LATER PLAN MUST NOT CHANGE THAT TRAY TITLE. This ruling rests on it.
 *
 * @see database/migrations/2026_09_26_100000_add_bucket_to_worksheet_photos_table.php
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-03)
 */
class WorksheetPhotoBucketDefaultTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH =
        'database/migrations/2026_09_26_100000_add_bucket_to_worksheet_photos_table.php';

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function worksheet(): Worksheet
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::factory()->create([
            'user_id'    => $user->id,
            'project_id' => $project->id,
        ]);
    }

    /** @return array<string,mixed> */
    private function rawPhotoRow(Worksheet $worksheet): array
    {
        return [
            'worksheet_id'  => $worksheet->id,
            'room_name'     => 'Boardroom',
            'filename'      => 'worksheets/' . fake()->uuid() . '.jpg',
            'original_name' => 'IMG_0042.jpg',
            'mime_type'     => 'image/jpeg',
            'caption'       => null,
            'sort_order'    => 0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ];
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION_PATH);
    }

    // ── The two buckets, and only two ────────────────────────────────────────

    public function test_a_photo_created_with_no_bucket_reads_completion(): void
    {
        $photo = WorksheetPhoto::create([
            'worksheet_id'  => $this->worksheet()->id,
            'room_name'     => 'Boardroom',
            'filename'      => 'worksheets/a.jpg',
            'original_name' => 'a.jpg',
            'mime_type'     => 'image/jpeg',
        ]);

        $this->assertSame(WorksheetPhoto::BUCKET_COMPLETION, $photo->fresh()->bucket);
    }

    public function test_a_photo_created_as_start_reads_start(): void
    {
        $photo = WorksheetPhoto::create([
            'worksheet_id'  => $this->worksheet()->id,
            'room_name'     => 'Boardroom',
            'filename'      => 'worksheets/b.jpg',
            'original_name' => 'b.jpg',
            'mime_type'     => 'image/jpeg',
            'bucket'        => WorksheetPhoto::BUCKET_START,
        ]);

        $this->assertSame('start', $photo->fresh()->bucket);
    }

    public function test_there_are_exactly_two_buckets(): void
    {
        $this->assertSame(
            ['start', 'completion'],
            WorksheetPhoto::BUCKETS,
            'A third bucket would have to be rendered somewhere forever, and the office '
            . 'would have to learn what it means. D-03 rejected that explicitly.'
        );
        $this->assertCount(2, WorksheetPhoto::BUCKETS);
    }

    // ── The history ruling, proven by migration ordering ─────────────────────

    /**
     * The migration-ordering fixture: wind the schema BACK to pre-migration,
     * insert a row through the query builder exactly as the old code did (no
     * bucket, because no bucket existed), then wind FORWARD again and read it.
     *
     * This also proves the migration runs down AND back up without stranding
     * the row it wound over.
     */
    public function test_a_row_inserted_before_the_migration_reads_completion_after_it(): void
    {
        $worksheet = $this->worksheet();
        $migration = $this->migration();

        $migration->down();

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('worksheet_photos', 'bucket'),
            'down() must actually remove the column, or this fixture proves nothing.'
        );

        $id = DB::table('worksheet_photos')->insertGetId($this->rawPhotoRow($worksheet));

        $migration->up();

        $this->assertSame(
            'completion',
            DB::table('worksheet_photos')->where('id', $id)->value('bucket'),
            'A photo captured before this phase was captured through a tray titled '
            . '"Photos of completed work". It is completion work, and the migration says so.'
        );

        // The row survived the round trip whole, not just its new column.
        $this->assertSame('Boardroom', DB::table('worksheet_photos')->where('id', $id)->value('room_name'));
        $this->assertSame(1, DB::table('worksheet_photos')->where('id', $id)->count());
    }

    /**
     * The column arrives NULLABLE and is filled by a statement. If someone
     * later "simplifies" this to a single `->default('completion')` add, the
     * backfill stops being load-bearing — it becomes a no-op that reads like a
     * decision — and this test is the thing that notices.
     */
    public function test_the_backfill_is_an_explicit_statement_and_not_the_column_default(): void
    {
        $source = file_get_contents(base_path(self::MIGRATION_PATH));

        $this->assertMatchesRegularExpression(
            '/->string\(\s*[\'"]bucket[\'"].*?\)\s*->nullable\(\)/s',
            $source,
            'The column must arrive nullable so the backfill below it is the thing that '
            . 'stamps history, rather than a default doing it silently.'
        );

        $this->assertMatchesRegularExpression(
            '/whereNull\(\s*[\'"]bucket[\'"]\s*\)/',
            $source,
            'up() must carry an explicit backfill of the pre-existing rows.'
        );

        $this->assertMatchesRegularExpression(
            '/->update\(\s*\[\s*[\'"]bucket[\'"]\s*=>/',
            $source,
            'The backfill must be a real UPDATE, not a comment describing one.'
        );
    }

    public function test_the_ruling_is_written_into_the_migration_itself(): void
    {
        $source = file_get_contents(base_path(self::MIGRATION_PATH));

        $this->assertStringContainsString(
            'Photos of completed work',
            $source,
            'The justification for relabelling history is the tray title the engineer read. '
            . 'It belongs in the migration, quoted, not only in a plan file.'
        );
    }

    // ── The index plans 02 and 03 read through ───────────────────────────────

    public function test_the_room_and_bucket_read_path_is_indexed(): void
    {
        $indexes = collect(
            \Illuminate\Support\Facades\Schema::getIndexes('worksheet_photos')
        )->map(fn (array $i): array => $i['columns']);

        $this->assertTrue(
            $indexes->contains(fn (array $columns): bool => $columns === ['worksheet_id', 'room_name', 'bucket']),
            'Every read in plans 02 and 03 is "this room\'s photos in this bucket".'
        );
    }
}
