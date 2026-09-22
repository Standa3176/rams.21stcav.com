<?php

namespace App\Support\Cockpit;

use App\Models\DeviceLabelPhoto;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * VisitEvidence — the one answer to "what did the engineer send back on this
 * visit" (Phase 46.1, Plan 46.1-01, Task 1).
 *
 * Built because the cockpit could not see any of it: on 2026-09-21
 * `grep -c "photo" app/Support/Cockpit/CockpitPanelPresenter.php` returned 0,
 * while the app was capturing six kinds of returned evidence. A PM could
 * accept a visit without having seen a single thing that came back.
 *
 * ── D-04: READ LIVE, NEVER COPY ─────────────────────────────────────────
 *
 * Everything below is resolved at call time from the engineer's own records —
 * SiteSurveyPhoto / SiteSurveyRoom / SiteSurveyRoomQuestion / WorksheetPhoto /
 * WorksheetSignoff / DeviceLabelPhoto. Nothing is copied onto `visits`,
 * nothing is denormalised and nothing is cached between instances. Same
 * doctrine as `Visit::returnedAt()` and `Visit::isSuperseded()`, and for the
 * same reason: a copy goes stale and then contradicts its source, with no way
 * left to tell which of the two is true.
 *
 * ── WRITES NOTHING ──────────────────────────────────────────────────────
 *
 * No touch(), no firstOrCreate(), no save(), no cache warm — the contract
 * `CockpitPanelPresenter` already states and its own test proves over six
 * tables. `CockpitEvidencePresenterTest` proves it here over nine.
 *
 * ── THE BUCKET MAPPING (D-03), RECORDED AT THE DEFINITION SITE ──────────
 *
 * D-03 names three buckets — before / after / label. This app's schema has NO
 * before/after column, so the buckets are mapped onto the three kinds of
 * evidence it does hold, and that mapping IS the meaning:
 *
 *   before = SiteSurveyPhoto   — the survey is the before-state of the room
 *   after  = WorksheetPhoto    — the install evidence
 *   label  = DeviceLabelPhoto  — the equipment label
 *
 * So a survey-sourced visit yields only `before/`, and a worksheet-sourced one
 * yields `after/` and `label/`. `SiteSurveyPhoto.category` is carried in
 * `caption` only when `caption` is null, and is NEVER used as a folder name.
 *
 * ── RV-03: THREE COLUMNS THAT ARE NEVER RETURNED ────────────────────────
 *
 * `device_label_photos.captured_by` is NOT a person's name. It is an AUDIT
 * field holding a capture IP plus an actor hash, and it has a leak history:
 * `database/migrations/2026_07_08_170000_backfill_device_label_photos_captured_by_leak.php`
 * nulled every legacy value because `PublicWorksheetController::uploadLabelPhoto`
 * had been writing the first 8 hex characters of the worksheet UUID token into
 * it — a credential fragment sitting in a data column (Audit CR-01). That
 * migration's own comment records that the column "is never read by
 * application code", and this class keeps that true.
 *
 * On the same grounds `worksheet_signoffs.ip_address` and
 * `worksheet_signoffs.user_agent` are excluded: the sign-off surface is the
 * client's NAME and SIGNATURE IMAGE only.
 *
 * None of the three appears in any array returned from here, and a test
 * asserts their absence from the SERIALISED presenter output with realistic
 * values actually present in the fixture — so the proof is not vacuous and a
 * later key addition anywhere in the tree trips a red test.
 *
 * ── RELATIVE PATHS ONLY (T-46.1-05) ─────────────────────────────────────
 *
 * `path` is the RELATIVE storage path off the `local` disk. `absolutePath()`
 * is deliberately not called anywhere in this file, so there is exactly one
 * place in this phase where a path meets the filesystem — the ZIP builder and
 * photo route of Plan 46.1-02.
 *
 * @see .planning/phases/46.1-visit-review/46.1-CONTEXT.md (D-03, D-04, D-05)
 */
final class VisitEvidence
{
    public const BUCKET_BEFORE = 'before';

    public const BUCKET_AFTER = 'after';

    public const BUCKET_LABEL = 'label';

    /** Render order for the buckets; also the photo sort's second key. */
    public const BUCKETS = [
        self::BUCKET_BEFORE,
        self::BUCKET_AFTER,
        self::BUCKET_LABEL,
    ];

    public const KIND_SURVEY = 'survey';

    public const KIND_WORKSHEET = 'worksheet';

    public const KIND_LABEL = 'label';

    /**
     * WHICH DISK EACH KIND'S PATH IS RELATIVE TO — recorded here because the
     * three kinds do NOT agree, and Plan 46.1-06's end-to-end walk is what
     * found it.
     *
     * `worksheet_photos.filename` and `site_survey_photos.filename` are
     * written by `Storage::disk('local')` (see WorksheetPhoto::absolutePath()
     * and SiteSurveyPhoto::absolutePath()). `device_label_photos.photo_path`
     * is written by `DeviceLabelPhotoService::capture()` through
     * `Storage::disk('public')`, because that row's other consumer renders it
     * with `Storage::url()`.
     *
     * In Laravel 11+ `local` is `storage/app/private` and `public` is
     * `storage/app/public` — SIBLINGS, not nested. So resolving a label path
     * against the local disk finds nothing: before this constant existed,
     * every equipment-label photo was silently SKIPPED from the hand-off ZIP
     * and 404ed on the inline photo route, on live, while the tab still drew a
     * thumbnail for it. Unit fixtures hid it by planting the label file on the
     * faked local disk; only a walk that uploaded one through the real public
     * endpoint could see it.
     *
     * A path carried out of here is ALWAYS relative to the disk named beside
     * it. Nothing downstream may assume a default.
     */
    public const DISK_FOR_KIND = [
        self::KIND_SURVEY    => 'local',
        self::KIND_WORKSHEET => 'local',
        self::KIND_LABEL     => 'public',
    ];

    /** @var Collection<int, array<string, mixed>> */
    private Collection $photos;

    /** @var Collection<int, array<string, mixed>> */
    private Collection $rooms;

    /** @var Collection<int, array<string, mixed>> */
    private Collection $serials;

    /** @var array<string, mixed>|null */
    private ?array $signoff;

    private function __construct(
        private readonly Visit $visit,
        private readonly ?Model $source,
    ) {
        $this->photos  = new Collection();
        $this->rooms   = new Collection();
        $this->serials = new Collection();
        $this->signoff = null;
    }

    /**
     * Resolve a visit's evidence, scoped to the ROUTE-BOUND project.
     *
     * T-46.1-03: the visit is never trusted to name its own scope, and no id
     * from a request is ever looked up here — the source is reached through
     * `Visit::source()`, which branches on two hardcoded constants.
     *
     * Returns null when the visit belongs to another project, and null when
     * the visit has no source record at all (nothing was ever issued, so there
     * is nothing that could have come back).
     */
    public static function for(Project $project, Visit $visit): ?self
    {
        if ((int) $visit->project_id !== (int) $project->id) {
            return null;
        }

        if ($visit->source_type === null) {
            return null;
        }

        $source = $visit->source();

        $evidence = new self($visit, $source);

        if ($source instanceof SiteSurvey) {
            $evidence->loadSurvey($source);
        } elseif ($source instanceof Worksheet) {
            $evidence->loadWorksheet($project, $source);
        }

        return $evidence;
    }

    /**
     * True when the visit names a source that no longer resolves — a
     * force-deleted survey or worksheet. The visit still happened, so it is
     * still rendered; it simply has nothing left to show.
     */
    public function sourceMissing(): bool
    {
        return $this->source === null;
    }

    /**
     * Every photo this visit's source holds, one array per photo, with exactly
     * the keys: kind, id, room, bucket, caption, original_name, mime_type,
     * path. `path` is RELATIVE — see the class docblock.
     *
     * Ordered by room, then bucket, then id, so repeat reads are stable.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function photos(): Collection
    {
        return $this->photos;
    }

    /**
     * Survey-sourced only: one entry per room that returned SOMETHING — a
     * photo, a non-empty note, or at least one answered question.
     *
     * UNANSWERED QUESTIONS ARE OMITTED. An unanswered question is noise on a
     * review page; `answered` of `total` carries that fact in two integers
     * instead of twenty empty rows.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rooms(): Collection
    {
        return $this->rooms;
    }

    /**
     * Worksheet-sourced only: the serials the engineer captured (D-05 — the
     * thing most likely wanted in Bitrix, and invisible to the office today).
     *
     * `captured_by` IS NOT A KEY OF THESE ARRAYS — see the RV-03 block in the
     * class docblock and
     * `2026_07_08_170000_backfill_device_label_photos_captured_by_leak.php`.
     * Do not restore it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function serials(): Collection
    {
        return $this->serials;
    }

    /**
     * Worksheet-sourced only: the client's most recent acceptance.
     *
     * `ip_address` and `user_agent` are NOT keys — same reasoning as the
     * audit column above. The sign-off surface is the NAME and the SIGNATURE.
     *
     * @return array<string, mixed>|null
     */
    public function signoff(): ?array
    {
        return $this->signoff;
    }

    /**
     * When the engineer's work came back — derived by `Visit::returnedAt()`,
     * never stored.
     */
    public function returnedAt(): ?Carbon
    {
        return $this->visit->returnedAt();
    }

    /**
     * Every [kind, id] pair this evidence contains.
     *
     * Plan 46.1-02's photo route uses this as a MEMBERSHIP test, so a photo id
     * arriving in a query string is checked against this visit's own evidence
     * rather than looked up globally.
     *
     * @return array<int, array{kind: string, id: int}>
     */
    public function photoIds(): array
    {
        return $this->photos
            ->map(fn (array $photo): array => ['kind' => $photo['kind'], 'id' => $photo['id']])
            ->values()
            ->all();
    }

    // ── Loading ──────────────────────────────────────────────────────────

    private function loadSurvey(SiteSurvey $survey): void
    {
        $survey->loadMissing(['rooms.photos', 'rooms.questions']);

        $photos = [];
        $rooms  = [];

        foreach ($survey->rooms as $room) {
            /** @var SiteSurveyRoom $room */
            $roomName = (string) ($room->room_name ?? '');

            foreach ($room->photos as $photo) {
                /** @var SiteSurveyPhoto $photo */
                $photos[] = [
                    'kind'          => self::KIND_SURVEY,
                    'id'            => (int) $photo->id,
                    'room'          => $roomName,
                    'bucket'        => self::BUCKET_BEFORE,
                    // category is a fallback caption only — never a folder name.
                    'caption'       => $photo->caption ?? $photo->category,
                    'original_name' => $photo->original_name,
                    'mime_type'     => $photo->mime_type,
                    'path'          => $photo->storagePath(),
                    'disk'          => self::DISK_FOR_KIND[self::KIND_SURVEY],
                ];
            }

            $answers  = [];
            $total    = 0;
            $answered = 0;

            foreach ($room->questions as $question) {
                $total++;

                $answer = $question->answer;

                if ($answer === null || trim((string) $answer) === '') {
                    continue;
                }

                $answered++;

                $entry = [
                    'question' => (string) $question->question,
                    'answer'   => (string) $answer,
                ];

                if ($answer === 'other') {
                    $entry['other_text'] = $question->other_text;
                }

                $answers[] = $entry;
            }

            $notes       = $this->cleanText($room->notes);
            $accessNotes = $this->cleanText($room->access_notes);

            $returnedSomething = $room->photos->isNotEmpty()
                || $notes !== null
                || $accessNotes !== null
                || $answered > 0;

            if (! $returnedSomething) {
                continue;
            }

            $rooms[] = [
                'name'         => $roomName,
                'notes'        => $notes,
                'access_notes' => $accessNotes,
                'completed'    => (bool) $room->is_completed,
                'answered'     => $answered,
                'total'        => $total,
                'answers'      => $answers,
            ];
        }

        $this->photos = $this->sortPhotos($photos);
        $this->rooms  = new Collection($rooms);
    }

    private function loadWorksheet(Project $project, Worksheet $worksheet): void
    {
        $worksheet->loadMissing(['photos', 'signoffs']);

        $photos = [];

        foreach ($worksheet->photos as $photo) {
            /** @var WorksheetPhoto $photo */
            $photos[] = [
                'kind'          => self::KIND_WORKSHEET,
                'id'            => (int) $photo->id,
                'room'          => (string) ($photo->room_name ?? ''),
                'bucket'        => self::BUCKET_AFTER,
                'caption'       => $photo->caption,
                'original_name' => $photo->original_name,
                'mime_type'     => $photo->mime_type,
                'path'          => $photo->storagePath(),
                'disk'          => self::DISK_FOR_KIND[self::KIND_WORKSHEET],
            ];
        }

        // T-46.1-02: scoped by BOTH worksheet_id and the route-bound project.
        // A label row carrying this worksheet_id but another project's
        // project_id must not surface here, and a test creates exactly such a
        // row to prove the second clause is load-bearing.
        $labels = DeviceLabelPhoto::query()
            ->where('worksheet_id', $worksheet->id)
            ->where('project_id', $project->id)
            ->with('device')
            ->orderBy('room_name')
            ->orderBy('id')
            ->get();

        $serials = [];

        foreach ($labels as $label) {
            /** @var DeviceLabelPhoto $label */
            $roomName = (string) ($label->room_name ?? '');

            $photos[] = [
                'kind'          => self::KIND_LABEL,
                'id'            => (int) $label->id,
                'room'          => $roomName,
                'bucket'        => self::BUCKET_LABEL,
                'caption'       => null,
                'original_name' => null,
                'mime_type'     => null,
                'path'          => (string) $label->photo_path,
                'disk'          => self::DISK_FOR_KIND[self::KIND_LABEL],
            ];

            // NOTE: there is deliberately no audit-column key on this array.
            // See the RV-03 block in the class docblock and
            // 2026_07_08_170000_backfill_device_label_photos_captured_by_leak.php.
            $serials[] = [
                'id'          => (int) $label->id,
                'room'        => $roomName,
                'device'      => (string) ($label->device?->description ?? ''),
                'serial'      => $this->resolveSerial($label),
                'confirmed'   => (bool) $label->confirmed,
                'captured_at' => $label->captured_at,
            ];
        }

        $this->photos  = $this->sortPhotos($photos);
        $this->serials = new Collection($serials);

        $signoff = $worksheet->latestSignoff();

        if ($signoff !== null) {
            // NOTE: no address-of-origin and no client-agent keys here. RV-03.
            $this->signoff = [
                'client_name'          => $signoff->client_name,
                'signed_at'            => $signoff->signed_at,
                'signed_with_comments' => (bool) $signoff->signed_with_comments,
                'comments'             => $signoff->comments,
                'signature_data_uri'   => $signoff->signature_data_uri,
            ];
        }
    }

    /**
     * The device register is the serial's home of record; the AI extraction is
     * the fallback for a label photo not yet reconciled to a device row.
     */
    private function resolveSerial(DeviceLabelPhoto $label): ?string
    {
        $fromDevice = $label->device?->serial_number;

        if ($fromDevice !== null && trim((string) $fromDevice) !== '') {
            return (string) $fromDevice;
        }

        $extracted = $label->ai_extracted;

        if (is_array($extracted)) {
            $candidate = $extracted['serial'] ?? null;

            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * Room, then bucket, then id — stable across repeat reads.
     *
     * @param  array<int, array<string, mixed>>  $photos
     * @return Collection<int, array<string, mixed>>
     */
    private function sortPhotos(array $photos): Collection
    {
        usort($photos, function (array $a, array $b): int {
            return [(string) $a['room'], (int) array_search($a['bucket'], self::BUCKETS, true), (int) $a['id']]
               <=> [(string) $b['room'], (int) array_search($b['bucket'], self::BUCKETS, true), (int) $b['id']];
        });

        return new Collection($photos);
    }

    private function cleanText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
